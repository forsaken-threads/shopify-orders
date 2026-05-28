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
 * Every figure is reported twice: once across all stored (revenue-bearing)
 * orders, and once restricted to fulfilled orders (status = 'fulfilled').  The
 * gap between the two approximates units committed but not yet decanted.
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
 *     "total_units":       <int>,
 *     "total_ml":          <int>,
 *     "total_revenue":     <float>,
 *     "fulfilled_units":   <int>,
 *     "fulfilled_ml":      <int>,
 *     "fulfilled_revenue": <float>
 *   },
 *   "variants": [
 *     {
 *       "variant_id":        null,
 *       "variant_title":     "...",
 *       "total_units":       <int>,
 *       "total_ml":          <int>,
 *       "total_revenue":     <float>,
 *       "fulfilled_units":   <int>,
 *       "fulfilled_ml":      <int>,
 *       "fulfilled_revenue": <float>
 *     }, ...
 *   ],
 *   "source": "local_db"
 * }
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

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
// Fulfilled-only figures are computed in the same pass via conditional sums on
// status = 'fulfilled' (the same definition used by fulfillments-per-day.php).
$salesStmt = $db->prepare(
    "SELECT
         COALESCE(NULLIF(oli.variant_title, ''), 'Default') AS variant_title,
         SUM(oli.quantity)                                  AS total_units,
         SUM(oli.quantity * COALESCE(oli.variant_ml, 0))    AS total_ml,
         SUM(oli.quantity * oli.price)                      AS total_revenue,
         SUM(CASE WHEN o.status = 'fulfilled' THEN oli.quantity ELSE 0 END)                               AS fulfilled_units,
         SUM(CASE WHEN o.status = 'fulfilled' THEN oli.quantity * COALESCE(oli.variant_ml, 0) ELSE 0 END) AS fulfilled_ml,
         SUM(CASE WHEN o.status = 'fulfilled' THEN oli.quantity * oli.price ELSE 0 END)                    AS fulfilled_revenue
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
        'variant_id'        => null,
        'variant_title'     => $row['variant_title'],
        'total_units'       => (int)   $row['total_units'],
        'total_ml'          => (int)   $row['total_ml'],
        'total_revenue'     => (float) $row['total_revenue'],
        'fulfilled_units'   => (int)   $row['fulfilled_units'],
        'fulfilled_ml'      => (int)   $row['fulfilled_ml'],
        'fulfilled_revenue' => (float) $row['fulfilled_revenue'],
    ];
}

// ── Summarise ──────────────────────────────────────────────────────────────────
$totalUnits       = array_sum(array_column($variants, 'total_units'));
$totalMl          = array_sum(array_column($variants, 'total_ml'));
$totalRevenue     = array_sum(array_column($variants, 'total_revenue'));
$fulfilledUnits   = array_sum(array_column($variants, 'fulfilled_units'));
$fulfilledMl      = array_sum(array_column($variants, 'fulfilled_ml'));
$fulfilledRevenue = array_sum(array_column($variants, 'fulfilled_revenue'));

echo json_encode([
    'product' => [
        'shopify_product_id' => $localProduct['shopify_product_id'],
        'title'              => $localProduct['title'],
        'vendor'             => $localProduct['vendor'],
    ],
    'summary' => [
        'total_units'       => (int)   $totalUnits,
        'total_ml'          => (int)   $totalMl,
        'total_revenue'     => round((float) $totalRevenue, 2),
        'fulfilled_units'   => (int)   $fulfilledUnits,
        'fulfilled_ml'      => (int)   $fulfilledMl,
        'fulfilled_revenue' => round((float) $fulfilledRevenue, 2),
    ],
    'variants' => $variants,
    'source'   => 'local_db',
]);
