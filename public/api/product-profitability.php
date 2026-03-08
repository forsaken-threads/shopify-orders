<?php
declare(strict_types=1);

/**
 * Product profitability endpoint.
 *
 * GET /api/product-profitability.php?product_id=<shopify_product_id>
 *
 * Uses the local SQLite database (synced via sync-paid-orders.php) to compute
 * total sales for the requested product, broken down by variant.  Only orders
 * already present in the local database are counted.
 *
 * Run `php scripts/sync-paid-orders.php --all-time` once to populate full
 * order history, then run daily without the flag to stay current.
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
 *       "variant_id":    null,
 *       "variant_title": "...",
 *       "total_units":   <int>,
 *       "total_revenue": <float>
 *     }, ...
 *   ],
 *   "source": "local_db"
 * }
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

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
     WHERE  shopify_product_id = :id
       AND  deleted_at IS NULL"
);
$stmt->execute([':id' => $productId]);
$localProduct = $stmt->fetch();

if ($localProduct === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

// ── Query local database ───────────────────────────────────────────────────────
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

$variants = [];
foreach ($salesStmt->fetchAll() as $row) {
    $variants[] = [
        'variant_id'    => null,
        'variant_title' => $row['variant_title'],
        'total_units'   => (int)   $row['total_units'],
        'total_revenue' => (float) $row['total_revenue'],
    ];
}

// ── Summarise ──────────────────────────────────────────────────────────────────
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
    'source'   => 'local_db',
]);
