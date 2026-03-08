#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync paid Shopify orders into the local database.
 *
 * Queries the Shopify Admin API for all orders with financial_status=paid,
 * then inserts any that are not already present in the local SQLite database.
 * Orders already stored (matched by shopify_order_id) are skipped so existing
 * status values are never overwritten.
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

// ── Fetch paid orders from Shopify (cursor-based pagination) ──────────────────

/**
 * Perform a GET request to the Shopify Admin API.
 *
 * Enforces a minimum 500 ms gap between all calls (matching Shopify's leaky-bucket
 * leak rate of 2 req/s for a 40-request bucket) using a static timestamp.
 *
 * @return array{body: string, status: int, link: string, callLimit: string, retryAfter: int}
 */
function shopifyGet(string $url, string $accessToken): array
{
    // Throttle all outbound requests to ≤ 2/s so we never exceed the bucket leak rate.
    static $lastRequestAt = 0.0;
    $minGap = 0.5; // seconds — matches the 2 req/s leak rate
    $now    = microtime(true);
    $gap    = $now - $lastRequestAt;
    if ($lastRequestAt > 0.0 && $gap < $minGap) {
        usleep((int)(($minGap - $gap) * 1_000_000));
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
 * Call shopifyGet with automatic retry on HTTP 429 responses.
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
 * Parse the "next" page cursor from a Shopify Link header.
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
 * Fetch the custom.brand metafield for a Shopify product.
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
        error_log(sprintf('[sync] fetchProductBrand: request failed for product %s', $productId));
        $cache[$productId] = null;
        return null;
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log(sprintf('[sync] fetchProductBrand: HTTP %d for product %s', $result['status'], $productId));
        $cache[$productId] = null;
        return null;
    }

    try {
        $data = json_decode($result['body'], associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log(sprintf('[sync] fetchProductBrand: invalid JSON for product %s: %s', $productId, $e->getMessage()));
        $cache[$productId] = null;
        return null;
    }

    $value = $data['metafields'][0]['value'] ?? null;
    $cache[$productId] = ($value !== null && $value !== '') ? (string) $value : null;
    return $cache[$productId];
}

// ── Prepare insert statements ─────────────────────────────────────────────────

$orderStmt = $db->prepare(<<<'SQL'
    INSERT INTO orders
        (shopify_order_id, order_number, customer_name, customer_email,
         total_price, currency, raw_data, shopify_created_at)
    VALUES
        (:shopify_id, :order_number, :customer_name, :customer_email,
         :total_price, :currency, :raw_data, :created_at)
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
$queryParams = [
    'financial_status' => 'paid',
    'status'           => 'any',
    'limit'            => '250',
    'order'            => 'created_at asc',
];

if (!$allTime) {
    // 25 hours ago in ISO 8601 UTC so cron runs with a comfortable overlap window.
    $queryParams['created_at_min'] = date('c', time() - (25 * 3600));
}

$nextUrl = sprintf(
    'https://%s/admin/api/%s/orders.json?%s',
    $shopDomain,
    rawurlencode($apiVersion),
    http_build_query($queryParams)
);

$modeLabel = $allTime ? 'all-time history' : 'prior 25 hours';
echo "Starting sync of paid orders ({$modeLabel}) from {$shopDomain}…\n";
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
