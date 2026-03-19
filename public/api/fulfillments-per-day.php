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

// ── Timezone for date grouping ───────────────────────────────────────────────
// Shopify timestamps are UTC. Convert to Eastern (America/New_York handles
// EST/EDT automatically) before grouping by date so that orders are counted
// on the correct local day.
$tz = new DateTimeZone('America/New_York');

// ── Query fulfilled orders and extract fulfillment timestamp from raw_data ───
// Shopify stores fulfillments in raw_data -> fulfillments[0].created_at.
// We fetch individual timestamps and group by date in PHP after timezone
// conversion, since SQLite has no native timezone support.

$dateParam = $dateMin !== null ? [':date_min' => $dateMin] : [];

// Fulfillments per day
$fulfilledWhere = "o.status = 'fulfilled'";
if ($dateMin !== null) {
    $fulfilledWhere .= ' AND o.shopify_created_at >= :date_min';
}

$sql = "
    SELECT
        COALESCE(
            json_extract(o.raw_data, '$.fulfillments[0].created_at'),
            o.shopify_created_at
        ) AS ts
    FROM orders o
    WHERE {$fulfilledWhere}
    ORDER BY ts ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($dateParam);
$fulfilledRows = $stmt->fetchAll();

// Orders received per day (all statuses)
$receivedWhere = '1=1';
if ($dateMin !== null) {
    $receivedWhere = 'o.shopify_created_at >= :date_min';
}

$sql = "
    SELECT o.shopify_created_at AS ts
    FROM orders o
    WHERE {$receivedWhere}
    ORDER BY ts ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($dateParam);
$receivedRows = $stmt->fetchAll();

// Group by date after converting each timestamp to Eastern time
function groupByLocalDate(array $rows, DateTimeZone $tz): array {
    $counts = [];
    foreach ($rows as $row) {
        $dt = new DateTime($row['ts']);
        $dt->setTimezone($tz);
        $date = $dt->format('Y-m-d');
        $counts[$date] = ($counts[$date] ?? 0) + 1;
    }
    return $counts;
}

$fulfilledLookup = groupByLocalDate($fulfilledRows, $tz);
$receivedLookup  = groupByLocalDate($receivedRows, $tz);

// Merge all dates from both series
$allDates = array_unique(array_merge(
    array_keys($fulfilledLookup),
    array_keys($receivedLookup)
));

// If a date range was specified, fill in zero-count days for a continuous line.
$fulfilledDays = [];
$receivedDays  = [];

if ($dateMin !== null && count($allDates) > 0) {
    $start = new DateTime(substr($dateMin, 0, 10));
    $end   = new DateTime('today');
    $end->modify('+1 day'); // include today

    $interval = new DateInterval('P1D');
    $range    = new DatePeriod($start, $interval, $end);

    foreach ($range as $dt) {
        $d = $dt->format('Y-m-d');
        $fulfilledDays[] = ['date' => $d, 'count' => $fulfilledLookup[$d] ?? 0];
        $receivedDays[]  = ['date' => $d, 'count' => $receivedLookup[$d] ?? 0];
    }
} else {
    // "all" period — only return days that have data in either series
    sort($allDates);
    foreach ($allDates as $d) {
        $fulfilledDays[] = ['date' => $d, 'count' => $fulfilledLookup[$d] ?? 0];
        $receivedDays[]  = ['date' => $d, 'count' => $receivedLookup[$d] ?? 0];
    }
}

echo json_encode([
    'period'    => $period,
    'fulfilled' => $fulfilledDays,
    'received'  => $receivedDays,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
