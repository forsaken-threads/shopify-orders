#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync revenue-bearing Shopify orders into the local database.
 *
 * Queries the Shopify Admin API for all orders, then inserts any with a
 * financial_status of paid, partially_refunded, or refunded that are not
 * already present in the local SQLite database.  Orders already stored
 * (matched by shopify_order_id) are skipped so existing status values are
 * never overwritten.  Refund amounts are not applied to line item figures —
 * refund attribution (order.refunds[].refund_line_items[]) is available in
 * raw_data if needed in future.
 *
 * Usage:
 *   php scripts/sync-paid-orders.php [--all-time]
 *
 * Options:
 *   --all-time   Fetch all paid orders ever (full history).
 *                Default behaviour fetches only orders from the prior 25 hours,
 *                suitable for a daily cron job that catches anything missed by
 *                webhooks without re-scanning the entire order history.
 *
 * Requirements:
 *   - env.ini (copied from env.ini.example) with SHOPIFY_* values filled in.
 *   - shopify.ini written by install.php (contains SHOPIFY_ACCESS_TOKEN).
 *
 * The script prints a summary line for each order processed and exits with
 * code 0 on success, 1 on configuration error, 2 on API error.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);

$config = require $projectRoot . '/public/config.php';
require $projectRoot . '/public/db.php';
require $projectRoot . '/app/shopify.php';

// ── Parse arguments ───────────────────────────────────────────────────────────

$allTime = in_array('--all-time', $argv ?? [], true);

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

// ── Load existing shopify_order_ids from the database ─────────────────────────

$db = getDb($config);

$existingIds = $db
    ->query("SELECT shopify_order_id FROM orders")
    ->fetchAll(PDO::FETCH_COLUMN);

$existingIdSet = array_flip($existingIds); // Use as a hash-set for O(1) lookups.

// ── Prepare insert statements ─────────────────────────────────────────────────

$orderStmt = $db->prepare(<<<'SQL'
    INSERT INTO orders
        (shopify_order_id, order_number, customer_name, customer_email,
         total_price, currency, status, raw_data, shopify_created_at)
    VALUES
        (:shopify_id, :order_number, :customer_name, :customer_email,
         :total_price, :currency, :status, :raw_data, :created_at)
SQL);

$lineStmt = $db->prepare(<<<'SQL'
    INSERT INTO order_line_items
        (order_id, shopify_line_item_id, shopify_product_id, title, variant_title, variant_ml,
         sku, vendor, quantity, price, custom_brand)
    VALUES
        (:order_id, :line_item_id, :shopify_product_id, :title, :variant_title, :variant_ml,
         :sku, :vendor, :quantity, :price, :custom_brand)
SQL);

// ── Main sync loop ────────────────────────────────────────────────────────────

$inserted   = 0;
$skipped    = 0;
$errors     = 0;
$pageCount  = 0;
$brandCache = [];

// Build the initial URL.
// Default: prior 25 hours so a daily cron catches anything missed by webhooks.
// --all-time: no date filter, fetches full order history.
// Fetch all orders and filter client-side; Shopify's financial_status param
// only accepts a single value, so 'any' + local filtering is the simplest way
// to capture paid, partially_refunded, and refunded in one pass.
$queryParams = [
    'financial_status' => 'any',
    'status'           => 'any',
    'limit'            => '250',
    'order'            => 'created_at asc',
];

if (!$allTime) {
    // 25 hours ago in ISO 8601 UTC so cron runs with a comfortable overlap window.
    $queryParams['created_at_min'] = date('c', time() - (25 * 3600));
} else {
    // Shopify's REST API defaults to the last 60 days when no created_at_min is
    // supplied, even with status=any.  Setting a far-past date forces it to return
    // the full order history from store birth.
    $queryParams['created_at_min'] = '2000-01-01T00:00:00Z';
}

$nextUrl = sprintf(
    'https://%s/admin/api/%s/orders.json?%s',
    $shopDomain,
    rawurlencode($apiVersion),
    http_build_query($queryParams)
);

$modeLabel = $allTime ? 'all-time history' : 'prior 25 hours';
echo "Starting sync of paid/refunded orders ({$modeLabel}) from {$shopDomain}…\n";
if (!$allTime) {
    echo "  Created after: {$queryParams['created_at_min']}\n";
}
echo "\n";

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

    $orders = $payload['orders'] ?? [];

    if (empty($orders)) {
        echo "  No orders on this page, done.\n";
        break;
    }

    foreach ($orders as $order) {
        $shopifyId = (string) ($order['id'] ?? '');

        if ($shopifyId === '') {
            echo "  Skipping order with no ID.\n";
            $errors++;
            continue;
        }

        // Only store revenue-bearing orders; discard pending, authorized, voided, etc.
        $financialStatus = $order['financial_status'] ?? '';
        if (!in_array($financialStatus, ['paid', 'partially_refunded', 'refunded'], strict: true)) {
            $skipped++;
            continue;
        }

        // Skip orders already in the database.
        if (isset($existingIdSet[$shopifyId])) {
            $orderNumber = $order['order_number'] ?? $order['name'] ?? $shopifyId;
            echo "  Skip  #{$orderNumber} (already in DB)\n";
            $skipped++;
            continue;
        }

        // Fetch custom.brand metafields for each product in this order.
        $brandByProductId = [];
        $productIds = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $pid = (string) ($item['product_id'] ?? '');
            if ($pid !== '' && !isset($productIds[$pid])) {
                $productIds[$pid] = true;
            }
        }

        foreach (array_keys($productIds) as $productId) {
            $brandByProductId[$productId] = fetchProductBrand(
                $shopDomain,
                $accessToken,
                $apiVersion,
                (string) $productId,
                $brandCache
            );
        }

        // Derive local status from Shopify's financial/fulfillment status so
        // orders that are already fulfilled or fully refunded when first synced
        // are not stored as pending.
        if ($financialStatus === 'refunded') {
            $status = 'archived';
        } elseif (($order['fulfillment_status'] ?? null) === 'fulfilled') {
            $status = 'fulfilled';
        } else {
            $status = 'pending';
        }

        // Build field values.
        $orderNumber  = (string) ($order['order_number'] ?? $order['name'] ?? $order['id']);
        $customerName = trim(
            ($order['customer']['first_name'] ?? '') . ' ' .
            ($order['customer']['last_name']  ?? '')
        );
        $customerEmail = (string) ($order['customer']['email'] ?? $order['email'] ?? '');
        $totalPrice    = (float) ($order['total_price'] ?? 0.0);
        $currency      = (string) ($order['currency']    ?? 'USD');
        $createdAt     = (string) ($order['created_at']  ?? date('c'));
        $rawData       = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $db->beginTransaction();

            $orderStmt->execute([
                ':shopify_id'     => $shopifyId,
                ':order_number'   => $orderNumber,
                ':customer_name'  => $customerName,
                ':customer_email' => $customerEmail,
                ':total_price'    => $totalPrice,
                ':currency'       => $currency,
                ':status'         => $status,
                ':raw_data'       => $rawData,
                ':created_at'     => $createdAt,
            ]);

            $orderId = (int) $db->lastInsertId();

            foreach ($order['line_items'] ?? [] as $item) {
                $productId   = (string) ($item['product_id'] ?? '');
                $customBrand = $brandByProductId[$productId] ?? null;

                if ($customBrand === null) {
                    foreach ($item['properties'] ?? [] as $prop) {
                        if (($prop['name'] ?? '') === 'custom.brand') {
                            $customBrand = ($prop['value'] !== '' && $prop['value'] !== null)
                                ? (string) $prop['value']
                                : null;
                            break;
                        }
                    }
                }

                $variantTitle = $item['variant_title'] ?? null;
                $variantMl    = null;
                if ($variantTitle !== null && preg_match('/^(\d+)\s*ml$/i', $variantTitle, $m)) {
                    $variantMl = (int) $m[1];
                }

                $lineStmt->execute([
                    ':order_id'           => $orderId,
                    ':line_item_id'       => (string) ($item['id'] ?? ''),
                    ':shopify_product_id' => $productId !== '' ? $productId : null,
                    ':title'              => (string) ($item['title'] ?? ''),
                    ':variant_title'      => $variantTitle,
                    ':variant_ml'         => $variantMl,
                    ':sku'                => $item['sku']    ?? null,
                    ':vendor'             => $item['vendor'] ?? null,
                    ':quantity'           => (int)   ($item['quantity'] ?? 1),
                    ':price'              => (float) ($item['price']    ?? 0.0),
                    ':custom_brand'       => $customBrand,
                ]);
            }

            $db->commit();

            echo "  Insert #{$orderNumber} — {$customerName} ({$shopifyId})\n";
            $inserted++;

            $existingIdSet[$shopifyId] = true;

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fwrite(STDERR, sprintf("  Error inserting order %s: %s\n", $shopifyId, $e->getMessage()));
            $errors++;
        }
    }

    $nextUrl = parseNextUrl($result['link']);
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\nSync complete.\n";
echo "  Inserted : {$inserted}\n";
echo "  Skipped  : {$skipped}\n";
echo "  Errors   : {$errors}\n";

exit($errors > 0 ? 2 : 0);
