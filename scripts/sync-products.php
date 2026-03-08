#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync Shopify products (active, draft, and archived) into the local database.
 *
 * Fetches products via the Shopify Admin REST API using updated_at_min to
 * limit the query to recently changed products, resolves the custom.brand
 * metafield for each product, and upserts the results into the local products
 * table.
 *
 * Usage:
 *   php scripts/sync-products.php [--all-products]
 *
 * Options:
 *   --all-products   Fetch every product regardless of when it was last
 *                    updated (full catalogue sync).
 *                    Default behaviour fetches only products updated in the
 *                    prior 25 hours, suitable for a daily cron job that
 *                    catches anything missed by webhooks without re-scanning
 *                    the entire catalogue.
 *
 * Note on deletions: the REST API never returns deleted products — they simply
 * disappear from the listing.  Handle product deletions via the
 * products/delete webhook instead.
 *
 * Requirements:
 *   - env.ini (copied from env.ini.example) with SHOPIFY_* values filled in.
 *   - shopify.ini written by install.php (contains SHOPIFY_ACCESS_TOKEN).
 *
 * Exit codes: 0 = success, 1 = configuration error, 2 = API error.
 *
 * Rate limiting: throttles to ≤ 2 requests/s and retries on HTTP 429 with
 * exponential backoff (2 s, 4 s, 8 s, 16 s), matching the pattern used in
 * sync-unfulfilled-orders.php.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);
$config      = require $projectRoot . '/app/config.php';
require $projectRoot . '/app/db.php';
require $projectRoot . '/app/shopify.php';
require $projectRoot . '/app/normalize.php';

// ── Parse arguments ───────────────────────────────────────────────────────────

$allProducts = in_array('--all-products', $argv ?? [], true);

// ── Validate configuration ────────────────────────────────────────────────────

$shopDomain  = $config['shopify_shop_domain'];
$accessToken = $config['shopify_access_token'];
$apiVersion  = $config['shopify_api_version'];

if ($shopDomain === '' || $accessToken === '' || $apiVersion === '') {
    fwrite(STDERR, "Error: SHOPIFY_SHOP_DOMAIN, SHOPIFY_ACCESS_TOKEN, and SHOPIFY_API_VERSION must all be set.\n");
    fwrite(STDERR, "  - Set SHOPIFY_SHOP_DOMAIN and SHOPIFY_API_VERSION in env.ini.\n");
    fwrite(STDERR, "  - Run public/install.php once via a browser to obtain SHOPIFY_ACCESS_TOKEN.\n");
    exit(1);
}

// ── Database setup ────────────────────────────────────────────────────────────

$db = getDb($config);

// ── Prepare upsert statement ──────────────────────────────────────────────────

$productStmt = $db->prepare(<<<'SQL'
    INSERT INTO products
        (shopify_product_id, title, normalized_title, vendor, status, custom_brand, is_bundle, raw_data, shopify_created_at)
    VALUES
        (:shopify_product_id, :title, :normalized_title, :vendor, :status, :custom_brand, :is_bundle, :raw_data, :shopify_created_at)
    ON CONFLICT(shopify_product_id) DO UPDATE SET
        title              = excluded.title,
        normalized_title   = excluded.normalized_title,
        vendor             = excluded.vendor,
        status             = excluded.status,
        custom_brand       = excluded.custom_brand,
        is_bundle          = excluded.is_bundle,
        raw_data           = excluded.raw_data,
        shopify_created_at = excluded.shopify_created_at,
        deleted_at         = NULL,
        synced_at          = datetime('now')
SQL);

// ── Main sync loop ────────────────────────────────────────────────────────────

$synced     = 0;
$skipped    = 0;
$errors     = 0;
$pageCount  = 0;
$brandCache = []; // product ID → ?string; shared across all pages

// Collect every Shopify product ID seen during an --all-products run so we can
// soft-delete local rows that Shopify no longer returns (i.e. deleted products).
// Only populated when $allProducts is true; left empty for the 25 h default run.
$syncedShopifyIds = [];

// Build the initial URL.
// Default: updated in the prior 25 hours so a daily cron catches anything
// missed by webhooks without re-scanning the entire catalogue.
// --all-products: no date filter, fetches the full catalogue.
$queryParams = [
    'status' => 'active,draft,archived',
    'limit'  => '250',
];

if (!$allProducts) {
    // 25 hours ago in ISO 8601 UTC — comfortable overlap window for daily cron.
    $queryParams['updated_at_min'] = date('c', time() - (25 * 3600));
}

$nextUrl = sprintf(
    'https://%s/admin/api/%s/products.json?%s',
    $shopDomain,
    rawurlencode($apiVersion),
    http_build_query($queryParams)
);

$modeLabel = $allProducts ? 'full catalogue' : 'prior 25 hours';
echo "Starting product sync ({$modeLabel}) from {$shopDomain}…\n";
if (!$allProducts) {
    echo "  Updated after: {$queryParams['updated_at_min']}\n";
}

while ($nextUrl !== null) {
    $pageCount++;
    echo "  Fetching page {$pageCount}…\n";

    $result = shopifyGetWithRetry($nextUrl, $accessToken);

    if ($result['status'] === 0) {
        fwrite(STDERR, "Error: Could not reach Shopify API. Check outbound connectivity.\n");
        exit(2);
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        fwrite(STDERR, sprintf("Error: Shopify API returned HTTP %d.\n%s\n", $result['status'], $result['body']));
        exit(2);
    }

    try {
        $payload = json_decode($result['body'], associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        fwrite(STDERR, "Error: Shopify returned invalid JSON: " . $e->getMessage() . "\n");
        exit(2);
    }

    $products = $payload['products'] ?? [];

    if (empty($products)) {
        echo "  No products on this page, done.\n";
        break;
    }

    foreach ($products as $product) {
        $shopifyProductId = (string) ($product['id'] ?? '');

        if ($shopifyProductId === '') {
            echo "  Skipping product with no ID.\n";
            $errors++;
            continue;
        }

        $title     = (string) ($product['title'] ?? '');
        $vendor    = isset($product['vendor']) && $product['vendor'] !== '' ? (string) $product['vendor'] : null;
        $status    = (string) ($product['status'] ?? 'active');
        $createdAt = isset($product['created_at']) && $product['created_at'] !== '' ? (string) $product['created_at'] : null;

        // Title ending in "bundle" (word boundary, case-insensitive) → bundle flag.
        $isBundle = (int) (bool) preg_match('/\bbundle\s*$/i', $title);

        // Fetch custom.brand metafield via API.
        echo "  Fetching brand for product #{$shopifyProductId} \"{$title}\"…\n";
        $customBrand = fetchProductBrand($shopDomain, $accessToken, $apiVersion, $shopifyProductId, $brandCache);

        $rawData = json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $productStmt->execute([
                ':shopify_product_id'  => $shopifyProductId,
                ':title'               => $title,
                ':normalized_title'    => normalizeTitle($title),
                ':vendor'              => $vendor,
                ':status'              => $status,
                ':custom_brand'        => $customBrand,
                ':is_bundle'           => $isBundle,
                ':raw_data'            => $rawData,
                ':shopify_created_at'  => $createdAt,
            ]);

            if ($allProducts) {
                $syncedShopifyIds[$shopifyProductId] = true;
            }

            $bundleTag = $isBundle ? ' [BUNDLE]' : '';
            $brandTag  = $customBrand !== null ? " (brand: {$customBrand})" : '';
            echo "  Synced  \"{$title}\"{$bundleTag}{$brandTag}\n";
            $synced++;

        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("  Error syncing product %s (%s): %s\n", $shopifyProductId, $title, $e->getMessage()));
            $errors++;
        }
    }

    $nextUrl = parseNextUrl($result['link']);
}

// ── Reconciliation (--all-products only) ──────────────────────────────────────
//
// Soft-delete any local product that Shopify did not return in the full listing.
// These are products that were deleted in Shopify since the last full sync.
// Skipped for the default 25 h run — rely on the products/delete webhook instead.

$softDeleted = 0;

if ($allProducts) {
    echo "\nReconciling local products against full Shopify catalogue…\n";

    $localRows = $db
        ->query("SELECT shopify_product_id FROM products WHERE deleted_at IS NULL")
        ->fetchAll(PDO::FETCH_COLUMN);

    $toDelete = array_diff($localRows, array_keys($syncedShopifyIds));

    if (!empty($toDelete)) {
        $deleteStmt = $db->prepare(
            "UPDATE products SET deleted_at = datetime('now'), synced_at = datetime('now') WHERE shopify_product_id = ?"
        );
        foreach ($toDelete as $orphanId) {
            $deleteStmt->execute([$orphanId]);
            echo "  Soft-deleted product #{$orphanId} (not returned by Shopify)\n";
            $softDeleted++;
        }
    } else {
        echo "  No orphaned products found.\n";
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\nSync complete.\n";
echo "  Synced       : {$synced}\n";
if ($allProducts) {
    echo "  Soft-deleted : {$softDeleted}\n";
}
echo "  Errors       : {$errors}\n";

exit($errors > 0 ? 2 : 0);
