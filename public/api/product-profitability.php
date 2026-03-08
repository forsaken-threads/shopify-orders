<?php
declare(strict_types=1);

/**
 * Product profitability endpoint.
 *
 * GET /api/product-profitability.php?product_id=<shopify_product_id>
 *
 * Queries the Shopify Admin REST API (paginating through all orders) to
 * compute total sales for the requested product, broken down by variant.
 * Only orders with financial_status=paid are counted.
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 * {
 *   "product": {
 *     "shopify_product_id": "...",
 *     "title": "...",
 *     "vendor": "..."
 *   },
 *   "summary": {
 *     "total_units":   <int>,
 *     "total_revenue": <float>
 *   },
 *   "variants": [
 *     {
 *       "variant_id":    "...",
 *       "variant_title": "...",
 *       "total_units":   <int>,
 *       "total_revenue": <float>
 *     }, ...
 *   ],
 *   "source": "shopify_api" | "local_db"
 * }
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

// Allow this endpoint more time since it may page through many Shopify orders.
set_time_limit(120);

$productId = trim((string) ($_GET['product_id'] ?? ''));

if ($productId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'product_id is required']);
    exit;
}

$db = getDb($config);

// ── Look up product in local cache ─────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT shopify_product_id, title, vendor
     FROM   products
     WHERE  shopify_product_id = :id"
);
$stmt->execute([':id' => $productId]);
$localProduct = $stmt->fetch();

if ($localProduct === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$shopDomain  = $config['shopify_shop_domain'];
$accessToken = $config['shopify_access_token'];
$apiVersion  = $config['shopify_api_version'];

// ── Helper: single Shopify GET ─────────────────────────────────────────────────

function shopifyGet(string $url, string $accessToken): array
{
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
        return ['body' => '', 'status' => 0, 'link' => '', 'retryAfter' => 0];
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m);
    $status = (int) ($m[1] ?? 0);

    $link       = '';
    $retryAfter = 0;
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Link:') === 0) {
            $link = $header;
        } elseif (stripos($header, 'Retry-After:') === 0) {
            $retryAfter = (int) trim(substr($header, strlen('Retry-After:')));
        }
    }

    return ['body' => $body, 'status' => $status, 'link' => $link, 'retryAfter' => $retryAfter];
}

function shopifyGetWithRetry(string $url, string $accessToken, int $maxRetries = 4): array
{
    $attempt = 0;
    while (true) {
        $result = shopifyGet($url, $accessToken);

        if ($result['status'] !== 429) {
            return $result;
        }

        $attempt++;
        if ($attempt > $maxRetries) {
            return $result;
        }

        $wait = $result['retryAfter'] > 0 ? $result['retryAfter'] : (2 ** $attempt);
        sleep($wait);
    }
}

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

// ── Query Shopify Admin API for orders ─────────────────────────────────────────
// Falls back to local DB if credentials are not configured.

$source   = 'shopify_api';
$variants = [];  // variant_id → ['variant_title'=>'', 'total_units'=>0, 'total_revenue'=>0.0]

if ($shopDomain !== '' && $accessToken !== '') {

    // Only request the fields we need to reduce payload size.
    $nextUrl = sprintf(
        'https://%s/admin/api/%s/orders.json?status=any&financial_status=paid&limit=250&fields=id,line_items',
        $shopDomain,
        rawurlencode($apiVersion)
    );

    while ($nextUrl !== null) {
        $result = shopifyGetWithRetry($nextUrl, $accessToken);

        if ($result['status'] === 0 || $result['status'] < 200 || $result['status'] >= 300) {
            // Fall through to local DB fallback below.
            $source   = 'local_db';
            $variants = [];
            break;
        }

        try {
            $payload = json_decode($result['body'], associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $source   = 'local_db';
            $variants = [];
            break;
        }

        foreach ($payload['orders'] ?? [] as $order) {
            foreach ($order['line_items'] ?? [] as $item) {
                if ((string) ($item['product_id'] ?? '') !== $productId) {
                    continue;
                }

                $variantId    = (string) ($item['variant_id'] ?? '');
                $variantTitle = (string) ($item['variant_title'] ?? '');
                $qty          = (int)   ($item['quantity']      ?? 1);
                $price        = (float) ($item['price']         ?? 0.0);

                $key = $variantId !== '' ? $variantId : ('notitle:' . $variantTitle);

                if (!isset($variants[$key])) {
                    $variants[$key] = [
                        'variant_id'    => $variantId,
                        'variant_title' => $variantTitle !== '' ? $variantTitle : 'Default',
                        'total_units'   => 0,
                        'total_revenue' => 0.0,
                    ];
                }

                $variants[$key]['total_units']   += $qty;
                $variants[$key]['total_revenue'] += $qty * $price;
            }
        }

        $nextUrl = parseNextUrl($result['link']);
    }

} else {
    $source = 'local_db';
}

// ── Local DB fallback (or primary source if no Shopify credentials) ────────────

if ($source === 'local_db') {
    $salesStmt = $db->prepare(
        "SELECT
             COALESCE(NULLIF(oli.variant_title, ''), 'Default') AS variant_title,
             SUM(oli.quantity)                                  AS total_units,
             SUM(oli.quantity * oli.price)                      AS total_revenue
         FROM   order_line_items oli
         JOIN   orders o ON o.id = oli.order_id
         WHERE  oli.shopify_product_id = :product_id
         GROUP  BY oli.variant_title
         ORDER  BY total_revenue DESC"
    );
    $salesStmt->execute([':product_id' => $productId]);

    foreach ($salesStmt->fetchAll() as $row) {
        $variants[] = [
            'variant_id'    => null,
            'variant_title' => $row['variant_title'],
            'total_units'   => (int)   $row['total_units'],
            'total_revenue' => (float) $row['total_revenue'],
        ];
    }
}

// ── Sort and summarise ─────────────────────────────────────────────────────────

usort($variants, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

$variants = array_values($variants);

$totalUnits   = array_sum(array_column($variants, 'total_units'));
$totalRevenue = array_sum(array_column($variants, 'total_revenue'));

echo json_encode([
    'product' => [
        'shopify_product_id' => $localProduct['shopify_product_id'],
        'title'              => $localProduct['title'],
        'vendor'             => $localProduct['vendor'],
    ],
    'summary' => [
        'total_units'   => (int)   $totalUnits,
        'total_revenue' => round((float) $totalRevenue, 2),
    ],
    'variants' => $variants,
    'source'   => $source,
]);
