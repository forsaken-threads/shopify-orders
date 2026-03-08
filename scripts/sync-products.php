#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time sync of all active (non-draft) Shopify products into the local database.
 *
 * Fetches every active product via the Shopify Admin REST API, resolves the
 * custom.brand metafield for each product, and upserts the results into the
 * local products table.  Run this once after initial setup (or any time you
 * need to reconcile the local cache with Shopify).
 *
 * Usage:
 *   php scripts/sync-products.php
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
$config      = require $projectRoot . '/public/config.php';
require $projectRoot . '/public/db.php';

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

// ── HTTP helpers ──────────────────────────────────────────────────────────────

/**
 * Perform a GET request to the Shopify Admin API.
 *
 * Enforces a minimum 500 ms gap between calls (≤ 2 req/s, matching Shopify's
 * leaky-bucket leak rate) using a static timestamp.
 *
 * @return array{body: string, status: int, link: string, callLimit: string, retryAfter: int}
 */
function shopifyGet(string $url, string $accessToken): array
{
    static $lastRequestAt = 0.0;
    $minGap = 0.5; // seconds — 2 req/s
    $now    = microtime(true);
    $gap    = $now - $lastRequestAt;
    if ($lastRequestAt > 0.0 && $gap < $minGap) {
        usleep((int) (($minGap - $gap) * 1_000_000));
    }
    $lastRequestAt = microtime(true);

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", [
                'X-Shopify-Access-Token: ' . $accessToken,
                'Accept: application/json',
            ]),
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        return ['body' => '', 'status' => 0, 'link' => '', 'callLimit' => '', 'retryAfter' => 0];
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m);
    $status = (int) ($m[1] ?? 0);

    $link       = '';
    $callLimit  = '';
    $retryAfter = 0;
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Link:') === 0) {
            $link = $header;
        } elseif (stripos($header, 'X-Shopify-Shop-Api-Call-Limit:') === 0) {
            $callLimit = trim(substr($header, strlen('X-Shopify-Shop-Api-Call-Limit:')));
        } elseif (stripos($header, 'Retry-After:') === 0) {
            $retryAfter = (int) trim(substr($header, strlen('Retry-After:')));
        }
    }

    return ['body' => $body, 'status' => $status, 'link' => $link, 'callLimit' => $callLimit, 'retryAfter' => $retryAfter];
}

/**
 * Call shopifyGet with automatic retry on HTTP 429.
 *
 * Respects the Retry-After header when present; falls back to exponential
 * backoff (2 s, 4 s, 8 s, 16 s) when the header is absent.
 *
 * @return array{body: string, status: int, link: string, callLimit: string, retryAfter: int}
 */
function shopifyGetWithRetry(string $url, string $accessToken, int $maxRetries = 4): array
{
    $attempt = 0;
    while (true) {
        $result = shopifyGet($url, $accessToken);

        if ($result['callLimit'] !== '') {
            echo "    [bucket: {$result['callLimit']}]\n";
        }

        if ($result['status'] !== 429) {
            return $result;
        }

        $attempt++;
        if ($attempt > $maxRetries) {
            return $result;
        }

        $wait = $result['retryAfter'] > 0 ? $result['retryAfter'] : (2 ** $attempt);
        echo "  Rate limited (429) — Retry-After: {$wait}s. Waiting before retry {$attempt}/{$maxRetries}…\n";
        sleep($wait);
    }
}

/**
 * Parse the "next" page cursor URL from a Shopify Link header.
 *
 * Shopify returns: Link: <URL>; rel="next", <URL>; rel="previous"
 * Returns the full URL for the next page, or null if there is none.
 */
function parseNextUrl(string $linkHeader): ?string
{
    if ($linkHeader === '') {
        return null;
    }
    $parts = preg_split('/,\s*(?=<)/', $linkHeader) ?: [];
    foreach ($parts as $part) {
        if (strpos($part, 'rel="next"') !== false) {
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                return $m[1];
            }
        }
    }
    return null;
}

/**
 * Fetch the custom.brand metafield for a single Shopify product.
 *
 * Results are cached in $cache (keyed by product ID) so repeated calls within
 * a sync run never hit the API more than once per product.
 *
 * @param array<string, string|null> $cache  Passed by reference; shared across all calls.
 */
function fetchProductBrand(
    string $shopDomain,
    string $accessToken,
    string $apiVersion,
    string $productId,
    array &$cache
): ?string {
    if (array_key_exists($productId, $cache)) {
        return $cache[$productId];
    }

    $url = sprintf(
        'https://%s/admin/api/%s/products/%s/metafields.json?namespace=custom&key=brand',
        $shopDomain,
        rawurlencode($apiVersion),
        rawurlencode($productId)
    );

    $result = shopifyGetWithRetry($url, $accessToken);

    if ($result['status'] === 0 || $result['body'] === '') {
        error_log(sprintf('[sync-products] fetchProductBrand: request failed for product %s', $productId));
        $cache[$productId] = null;
        return null;
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log(sprintf('[sync-products] fetchProductBrand: HTTP %d for product %s', $result['status'], $productId));
        $cache[$productId] = null;
        return null;
    }

    try {
        $data = json_decode($result['body'], associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log(sprintf('[sync-products] fetchProductBrand: invalid JSON for product %s: %s', $productId, $e->getMessage()));
        $cache[$productId] = null;
        return null;
    }

    $value             = $data['metafields'][0]['value'] ?? null;
    $cache[$productId] = ($value !== null && $value !== '') ? (string) $value : null;
    return $cache[$productId];
}

// ── Prepare upsert statement ──────────────────────────────────────────────────

$productStmt = $db->prepare(<<<'SQL'
    INSERT INTO products
        (shopify_product_id, title, vendor, status, custom_brand, is_bundle, raw_data, shopify_created_at)
    VALUES
        (:shopify_product_id, :title, :vendor, :status, :custom_brand, :is_bundle, :raw_data, :shopify_created_at)
    ON CONFLICT(shopify_product_id) DO UPDATE SET
        title              = excluded.title,
        vendor             = excluded.vendor,
        status             = excluded.status,
        custom_brand       = excluded.custom_brand,
        is_bundle          = excluded.is_bundle,
        raw_data           = excluded.raw_data,
        shopify_created_at = excluded.shopify_created_at,
        synced_at          = datetime('now')
SQL);

// ── Main sync loop ────────────────────────────────────────────────────────────

$synced    = 0;
$skipped   = 0;
$errors    = 0;
$pageCount = 0;
$brandCache = []; // product ID → ?string; shared across all pages

// Fetch active products only (draft products are excluded).
// status=active excludes drafts; archived products are included (status=active,archived
// would be the alternative, but the user only asked to ignore drafts).
$nextUrl = sprintf(
    'https://%s/admin/api/%s/products.json?status=active&limit=250',
    $shopDomain,
    rawurlencode($apiVersion)
);

echo "Starting product sync from {$shopDomain}…\n";

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
                ':vendor'              => $vendor,
                ':status'              => $status,
                ':custom_brand'        => $customBrand,
                ':is_bundle'           => $isBundle,
                ':raw_data'            => $rawData,
                ':shopify_created_at'  => $createdAt,
            ]);

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

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\nSync complete.\n";
echo "  Synced : {$synced}\n";
echo "  Errors : {$errors}\n";

exit($errors > 0 ? 2 : 0);
