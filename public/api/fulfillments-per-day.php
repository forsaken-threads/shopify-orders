<?php
declare(strict_types=1);

/**
 * Fulfillments-per-day endpoint for the line chart.
 *
 * GET /api/fulfillments-per-day.php?period=<period>
 *
 * Counts the number of orders fulfilled per day by inspecting the
 * fulfillment date from the Shopify raw_data JSON payload.
 *
 * period values:
 *   30d          Last 30 days (default)
 *   7d           Last 7 days
 *   90d          Last 90 days
 *   ytd          Year-to-date (Jan 1 of the current year through now)
 *   ttm          Trailing twelve months
 *   all          No date restriction (full order history)
 *
 * Response shape:
 * {
 *   "period": "30d",
 *   "days": [
 *     { "date": "2026-02-10", "count": 5 },
 *     ...
 *   ]
 * }
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../../app/db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$period = trim((string) ($_GET['period'] ?? '30d'));

// ── Build date range from period ─────────────────────────────────────────────

$currentYear = (int) date('Y');
$dateMin = null;

switch ($period) {
    case '7d':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-7 days'));
        break;
    case '30d':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-30 days'));
        break;
    case '90d':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-90 days'));
        break;
    case 'ytd':
        $dateMin = $currentYear . '-01-01T00:00:00';
        break;
    case 'ttm':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-12 months'));
        break;
    case 'all':
        // No date restriction
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid period. Use 7d, 30d, 90d, ytd, ttm, or all.']);
        exit;
}

$db = getDb($config);

// ── Query fulfilled orders and extract fulfillment date from raw_data ────────
// Shopify stores fulfillments in raw_data -> fulfillments[0].created_at.
// We use SQLite's json_extract() to pull the first fulfillment's created_at,
// then group by date.

$whereClauses = ["o.status = 'fulfilled'"];
$params = [];

if ($dateMin !== null) {
    $whereClauses[] = 'o.shopify_created_at >= :date_min';
    $params[':date_min'] = $dateMin;
}

$where = implode(' AND ', $whereClauses);

// Extract the fulfillment date from raw_data JSON. Fall back to shopify_created_at
// if the fulfillments array is missing or empty.
$sql = "
    SELECT
        DATE(
            COALESCE(
                json_extract(o.raw_data, '$.fulfillments[0].created_at'),
                o.shopify_created_at
            )
        ) AS fulfillment_date,
        COUNT(*) AS order_count
    FROM orders o
    WHERE {$where}
    GROUP BY fulfillment_date
    ORDER BY fulfillment_date ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// If a date range was specified, fill in zero-count days for a continuous line.
$days = [];

if ($dateMin !== null && count($rows) > 0) {
    // Build a lookup map of date => count
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[$row['fulfillment_date']] = (int) $row['order_count'];
    }

    // Generate every date from dateMin to today
    $start = new DateTime(substr($dateMin, 0, 10));
    $end   = new DateTime('today');
    $end->modify('+1 day'); // include today

    $interval = new DateInterval('P1D');
    $range    = new DatePeriod($start, $interval, $end);

    foreach ($range as $dt) {
        $d = $dt->format('Y-m-d');
        $days[] = [
            'date'  => $d,
            'count' => $lookup[$d] ?? 0,
        ];
    }
} else {
    // "all" period — only return days that have data
    foreach ($rows as $row) {
        $days[] = [
            'date'  => $row['fulfillment_date'],
            'count' => (int) $row['order_count'],
        ];
    }
}

echo json_encode([
    'period' => $period,
    'days'   => $days,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
