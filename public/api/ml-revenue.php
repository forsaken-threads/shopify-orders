<?php
declare(strict_types=1);

/**
 * Per-ML revenue endpoint for the scatter plot chart.
 *
 * GET /api/ml-revenue.php?period=<period>
 *
 * Computes revenue-per-ml for every non-bundle product variant that has a
 * known ml size (variant_ml IS NOT NULL).  Results are aggregated across all
 * paid orders in the requested time period.
 *
 * period values:
 *   ytd          Year-to-date (Jan 1 of the current year through now)
 *   ttm          Trailing twelve months
 *   2024, 2025   Full calendar year
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 * {
 *   "period": "ytd",
 *   "points": [
 *     {
 *       "product":         "...",
 *       "variant":         "100 ml",
 *       "ml":              100,
 *       "total_units":     <int>,
 *       "total_ml":        <int>,
 *       "total_revenue":   <float>,
 *       "revenue_per_ml":  <float>
 *     }, ...
 *   ]
 * }
 *
 * revenue_per_ml = total_revenue / (total_units * ml)
 *   i.e., the average selling price per ml across all sales of that variant.
 * total_ml = total_units * ml
 *   i.e., total millilitres sold of that variant across the period.
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$period = trim((string) ($_GET['period'] ?? ''));

// ── Build date range from period ───────────────────────────────────────────────

$currentYear = (int) date('Y');

$dateMin = null;
$dateMax = null;

if ($period === 'ytd') {
    $dateMin = $currentYear . '-01-01T00:00:00';
} elseif ($period === 'ttm') {
    $dateMin = date('Y-m-d\TH:i:s', strtotime('-12 months'));
} elseif (ctype_digit($period)) {
    $year = (int) $period;
    if ($year >= 2020 && $year <= $currentYear) {
        $dateMin = $year . '-01-01T00:00:00';
        $dateMax = ($year + 1) . '-01-01T00:00:00';
    }
}

if ($dateMin === null && !ctype_digit($period)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid period. Use ytd, ttm, or a year (e.g. 2024).']);
    exit;
}

$db = getDb($config);

// ── Build query ────────────────────────────────────────────────────────────────
// Join order_line_items → orders → products.
// Exclude bundles (products.is_bundle = 1).
// Only rows where variant_ml is known.
// Average revenue per ml = total revenue / (total_units * ml).

$whereClauses = [
    'oli.variant_ml IS NOT NULL',
    'oli.variant_ml > 0',
    'p.is_bundle = 0',
];
$params = [];

if ($dateMin !== null) {
    $whereClauses[] = 'o.shopify_created_at >= :date_min';
    $params[':date_min'] = $dateMin;
}
if ($dateMax !== null) {
    $whereClauses[] = 'o.shopify_created_at < :date_max';
    $params[':date_max'] = $dateMax;
}

$where = implode(' AND ', $whereClauses);

$sql = "
    SELECT
        p.title                                                           AS product,
        COALESCE(NULLIF(oli.variant_title, ''), 'Default')               AS variant,
        oli.variant_ml                                                    AS ml,
        SUM(oli.quantity)                                                 AS total_units,
        SUM(oli.quantity) * oli.variant_ml                               AS total_ml,
        ROUND(SUM(oli.quantity * oli.price), 2)                          AS total_revenue,
        ROUND(
            SUM(oli.quantity * oli.price) /
            (SUM(oli.quantity) * oli.variant_ml),
            4
        )                                                                 AS revenue_per_ml
    FROM  order_line_items oli
    JOIN  orders            o ON o.id = oli.order_id
    JOIN  products          p ON p.shopify_product_id = oli.shopify_product_id
    WHERE {$where}
    GROUP BY oli.shopify_product_id, oli.variant_title, oli.variant_ml
    HAVING total_units > 0
    ORDER BY revenue_per_ml DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$points = array_map(fn($r) => [
    'product'        => $r['product'],
    'variant'        => $r['variant'],
    'ml'             => (int)   $r['ml'],
    'total_units'    => (int)   $r['total_units'],
    'total_ml'       => (int)   $r['total_ml'],
    'total_revenue'  => (float) $r['total_revenue'],
    'revenue_per_ml' => (float) $r['revenue_per_ml'],
], $rows);

echo json_encode([
    'period' => $period,
    'points' => $points,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
