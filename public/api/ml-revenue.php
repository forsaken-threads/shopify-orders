<?php
declare(strict_types=1);

/**
 * Per-ML revenue endpoint for the scatter plot chart.
 *
 * GET /api/ml-revenue.php?period=<period>[&vol_min=<int>][&vol_max=<int>]
 *
 * Computes revenue-per-ml for every non-bundle product that has at least one
 * variant with a known ml size.  All variants of a product are summed together
 * so each product yields a single data point.
 *
 * period values:
 *   ytd          Year-to-date (Jan 1 of the current year through now)
 *   ttm          Trailing twelve months
 *   2024, 2025   Full calendar year
 *   all          No date restriction (full order history)
 *
 * vol_min / vol_max  Optional integer filters on total ml sold per product.
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 * {
 *   "period": "ytd",
 *   "points": [
 *     {
 *       "product":         "...",
 *       "total_units":     <int>,
 *       "total_ml":        <int>,
 *       "total_revenue":   <float>,
 *       "revenue_per_ml":  <float>
 *     }, ...
 *   ]
 * }
 *
 * total_ml       = SUM(quantity * variant_ml) across all variants
 * revenue_per_ml = total_revenue / total_ml
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

if ($dateMin === null && $period !== 'all') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid period. Use ytd, ttm, all, or a year (e.g. 2024).']);
    exit;
}

// ── Volume filter params ───────────────────────────────────────────────────────

$volMin = isset($_GET['vol_min']) && is_numeric($_GET['vol_min']) ? max(0, (int) $_GET['vol_min']) : null;
$volMax = isset($_GET['vol_max']) && is_numeric($_GET['vol_max']) ? max(0, (int) $_GET['vol_max']) : null;

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

// Named parameters in HAVING are not reliably bound by PDO/SQLite; apply the
// vol min/max filter in PHP after fetching (see below).
$having = 'total_ml > 0';

$sql = "
    SELECT
        p.title                                                           AS product,
        SUM(oli.quantity * oli.variant_ml)                               AS total_ml,
        SUM(oli.quantity)                                                 AS total_units,
        ROUND(SUM(oli.quantity * oli.price), 2)                          AS total_revenue,
        ROUND(
            SUM(oli.quantity * oli.price) /
            SUM(oli.quantity * oli.variant_ml),
            4
        )                                                                 AS revenue_per_ml
    FROM  order_line_items oli
    JOIN  orders            o ON o.id = oli.order_id
    JOIN  products          p ON p.shopify_product_id = oli.shopify_product_id
    WHERE {$where}
    GROUP BY oli.shopify_product_id
    HAVING {$having}
    ORDER BY revenue_per_ml DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Apply vol min/max filter in PHP — named params in HAVING are unreliable in
// PDO/SQLite and silently evaluate to NULL, filtering every row.
if ($volMin !== null) {
    $rows = array_values(array_filter($rows, fn($r) => (int) $r['total_ml'] >= $volMin));
}
if ($volMax !== null) {
    $rows = array_values(array_filter($rows, fn($r) => (int) $r['total_ml'] <= $volMax));
}

$points = array_map(fn($r) => [
    'product'        => $r['product'],
    'total_units'    => (int)   $r['total_units'],
    'total_ml'       => (int)   $r['total_ml'],
    'total_revenue'  => (float) $r['total_revenue'],
    'revenue_per_ml' => (float) $r['revenue_per_ml'],
], $rows);

echo json_encode([
    'period' => $period,
    'points' => $points,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
