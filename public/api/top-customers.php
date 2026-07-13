<?php
declare(strict_types=1);

/**
 * Top customers with mailing addresses, rankable five ways over any timeframe.
 *
 * GET /api/top-customers.php?mode=<mode>&period=<period>&limit=<int>[&format=csv]
 *
 * Customers are keyed by lowercased email.  Ranking modes:
 *   spend      — total spend, SUM of order total_price.
 *   orders     — total number of orders placed.
 *   items      — total item count, SUM of line-item quantity.
 *   per_order  — average order value (total spend / orders).
 *   per_item   — average spend per item (total spend / items).
 *
 * The two ratio modes are floored on their denominator so a single lucky
 * purchase can't top the chart: a customer must sit at or above the
 * 4th-quintile (60th percentile) of the orders / items distribution, and never
 * below RATIO_FLOOR_MINIMUM.  The percentile alone isn't enough for per_order —
 * most customers order exactly once, so p60 lands at 1 and filters nobody.
 *
 * Spend and order count are order-level and item count is line-item level; the
 * two are aggregated separately and joined per customer so the order total is
 * never multiplied across line items.
 *
 * period  all | 30d | 90d | ytd | ttm (default 30d).  Anything but 'all' also
 *         returns each customer's lifetime value of the ranking metric, for
 *         comparison against their showing in the window.
 * limit   number of customers to return (default 100, clamped 1..1000).
 * format  'csv' streams a spreadsheet download; anything else returns JSON.
 *
 * Each customer's mailing address is pulled from their most-recent order's
 * raw_data — there is no customer/address table.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

// Denominator floor for the ratio modes: the 4th-quintile floor (60th
// percentile) of the live distribution, but never below two — one order or one
// item is too thin a denominator to average anything meaningful over.
const RATIO_FLOOR_PERCENTILE = 0.60;
const RATIO_FLOOR_MINIMUM    = 2;

// The aggregate field each mode ranks by, and the field that breaks ties.
const MODE_METRIC   = ['spend' => 'spent',  'orders' => 'order_count', 'items' => 'items',
                       'per_order' => 'per_order', 'per_item' => 'per_item'];
const MODE_TIEBREAK = ['spend' => 'items',  'orders' => 'spent',       'items' => 'spent',
                       'per_order' => 'order_count', 'per_item' => 'items'];
// Ratio modes, mapped to the aggregate field their floor applies to.
const MODE_DENOMINATOR = ['per_order' => 'order_count', 'per_item' => 'items'];
// Column heading for each mode's metric, and which ones read as money.
const MODE_LABEL    = ['spend' => 'Total Spent', 'orders' => 'Orders', 'items' => 'Items',
                       'per_order' => 'Spend Per Order', 'per_item' => 'Spend Per Item'];
const MODE_SLUG     = ['spend' => 'spend', 'orders' => 'orders', 'items' => 'items',
                       'per_order' => 'spend-per-order', 'per_item' => 'spend-per-item'];
const MODE_CURRENCY = ['spend' => true, 'orders' => false, 'items' => false,
                       'per_order' => true, 'per_item' => true];

$limit  = (int) ($_GET['limit'] ?? 100);
$limit  = max(1, min(1000, $limit));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
$mode   = strtolower(trim((string) ($_GET['mode'] ?? 'spend')));

if (!isset(MODE_METRIC[$mode])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid mode. Use spend, orders, items, per_order, or per_item.']);
    exit;
}

$period = strtolower(trim((string) ($_GET['period'] ?? '30d')));

// Optional trailing-window filter.  Matches the period vocabulary used by the
// charts endpoints.  $dateMin is an ISO string compared against
// shopify_created_at (also ISO, year-first) — the same coarse string
// comparison the other reports rely on.
$dateMin = null;
switch ($period) {
    case 'all':
        break;
    case '30d':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-30 days'));
        break;
    case '90d':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-90 days'));
        break;
    case 'ytd':
        $dateMin = ((int) date('Y')) . '-01-01T00:00:00';
        break;
    case 'ttm':
        $dateMin = date('Y-m-d\TH:i:s', strtotime('-12 months'));
        break;
    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid period. Use all, 30d, 90d, ytd, or ttm.']);
        exit;
}

/**
 * Per-customer aggregate across every customer, optionally limited to orders on
 * or after $dateMin.  Returned keyed by lowercased email.
 */
function customerAggregates(PDO $db, ?string $dateMin): array
{
    // Two distinct placeholders: PDO_SQLite can't reuse a named param in one query.
    $ordDateFilter = $dateMin !== null ? ' AND shopify_created_at   >= :dmin_ord' : '';
    $itmDateFilter = $dateMin !== null ? ' AND o.shopify_created_at >= :dmin_itm' : '';

    $sql = "
        WITH ord AS (
            SELECT lower(customer_email) AS ek,
                   ROUND(SUM(total_price), 2) AS spent,
                   COUNT(*)                   AS order_count
            FROM   orders
            WHERE  TRIM(customer_email) != ''{$ordDateFilter}
            GROUP  BY lower(customer_email)
        ),
        itm AS (
            SELECT lower(o.customer_email) AS ek,
                   SUM(oli.quantity)       AS items
            FROM   orders            o
            JOIN   order_line_items  oli ON oli.order_id = o.id
            WHERE  TRIM(o.customer_email) != ''{$itmDateFilter}
            GROUP  BY lower(o.customer_email)
        )
        SELECT ord.ek                    AS email_key,
               ord.spent                 AS spent,
               ord.order_count           AS order_count,
               COALESCE(itm.items, 0)    AS items
        FROM   ord
        LEFT   JOIN itm ON itm.ek = ord.ek
    ";

    $stmt = $db->prepare($sql);
    if ($dateMin !== null) {
        $stmt->bindValue(':dmin_ord', $dateMin);
        $stmt->bindValue(':dmin_itm', $dateMin);
    }
    $stmt->execute();

    $out = [];
    foreach ($stmt as $r) {
        $ek     = (string) $r['email_key'];
        $spent  = (float) $r['spent'];
        $orders = (int) $r['order_count'];
        $items  = (int) $r['items'];
        $out[$ek] = [
            'email_key'   => $ek,
            'spent'       => $spent,
            'order_count' => $orders,
            'items'       => $items,
            'per_item'    => $items > 0 ? round($spent / $items, 2) : 0.0,
            'per_order'   => round($spent / $orders, 2),
        ];
    }

    return $out;
}

/**
 * Extract a mailing address from a decoded order's raw_data.  Prefers the
 * shipping address, then the customer's default address, then billing.  Falls
 * back to the orders.customer_name column for the recipient name when the
 * address block carries no name of its own.
 */
function mailingAddressFromRaw(array $raw, string $fallbackName): array
{
    $sa = $raw['shipping_address'] ?? null;
    if (!is_array($sa)) {
        $sa = $raw['customer']['default_address'] ?? ($raw['billing_address'] ?? null);
    }
    if (!is_array($sa)) {
        $sa = [];
    }

    $name = trim((string) ($sa['name'] ?? ''));
    if ($name === '') {
        $name = trim(((string) ($sa['first_name'] ?? '')) . ' ' . ((string) ($sa['last_name'] ?? '')));
    }
    if ($name === '') {
        $name = $fallbackName;
    }

    return [
        'name'     => $name,
        'company'  => trim((string) ($sa['company']  ?? '')),
        'address1' => trim((string) ($sa['address1'] ?? '')),
        'address2' => trim((string) ($sa['address2'] ?? '')),
        'city'     => trim((string) ($sa['city']     ?? '')),
        'state'    => trim((string) ($sa['province_code'] ?? $sa['province'] ?? '')),
        'zip'      => trim((string) ($sa['zip']      ?? '')),
        'country'  => trim((string) ($sa['country_code'] ?? $sa['country'] ?? '')),
    ];
}

$db = getDb($config);

// The whole population is needed, not just the top N, so the ratio modes can
// compute their floor against the live distribution for this timeframe.
$all = array_values(customerAggregates($db, $dateMin));

// Lifetime figures for the All-Time comparison column.  A customer ranked in a
// window always has orders overall, so every ranked email is present here.
$allTime = $dateMin !== null ? customerAggregates($db, null) : null;

// ── Select + sort per mode ───────────────────────────────────────────────────
$metric     = MODE_METRIC[$mode];
$tiebreak   = MODE_TIEBREAK[$mode];
$ratioFloor = 0;
$poolSize   = count($all);

if (isset(MODE_DENOMINATOR[$mode])) {
    $denominator = MODE_DENOMINATOR[$mode];

    $counts = array_column($all, $denominator);
    sort($counts);
    $nAll = count($counts);
    if ($nAll > 0) {
        $idx = (int) floor(RATIO_FLOOR_PERCENTILE * $nAll);
        if ($idx >= $nAll) {
            $idx = $nAll - 1;
        }
        $ratioFloor = max($counts[$idx], RATIO_FLOOR_MINIMUM);
    }

    $ranked   = array_values(array_filter($all, fn($c) => $c[$denominator] >= $ratioFloor));
    $poolSize = count($ranked);
} else {
    $ranked = $all;
}

usort($ranked, fn($a, $b) => [$b[$metric], $b[$tiebreak]] <=> [$a[$metric], $a[$tiebreak]]);
$ranked = array_slice($ranked, 0, $limit);

// ── Attach the mailing address for each ranked customer ──────────────────────
// Deliberately the latest order overall, not the latest within the window — the
// freshest address on file is the one worth mailing to.
$latestStmt = $db->prepare(
    "SELECT customer_name, customer_email, raw_data
     FROM   orders
     WHERE  lower(customer_email) = :email_key
     ORDER  BY shopify_created_at DESC, id DESC
     LIMIT  1"
);

$rows = [];
$rank = 0;
foreach ($ranked as $c) {
    $rank++;
    $latestStmt->execute([':email_key' => $c['email_key']]);
    $latest = $latestStmt->fetch() ?: [];

    $raw = [];
    if (!empty($latest['raw_data'])) {
        $decoded = json_decode((string) $latest['raw_data'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    $addr = mailingAddressFromRaw($raw, trim((string) ($latest['customer_name'] ?? '')));

    $row = [
        'rank'        => $rank,
        'name'        => $addr['name'],
        'email'       => (string) ($latest['customer_email'] ?? $c['email_key']),
        'order_count' => $c['order_count'],
        'items'       => $c['items'],
        'spent'       => $c['spent'],
        'per_item'    => $c['per_item'],
        'per_order'   => $c['per_order'],
        'company'     => $addr['company'],
        'address1'    => $addr['address1'],
        'address2'    => $addr['address2'],
        'city'        => $addr['city'],
        'state'       => $addr['state'],
        'zip'         => $addr['zip'],
        'country'     => $addr['country'],
    ];
    if ($allTime !== null) {
        $row['all_time'] = $allTime[$c['email_key']][$metric];
    }

    $rows[] = $row;
}

// ── CSV download ─────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'top-' . $limit . '-customers-by-' . MODE_SLUG[$mode] . '-' . $period . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $header = ['Rank', 'Name', 'Email', 'Orders', 'Items', 'Total Spent', 'Spend Per Item', 'Spend Per Order'];
    if ($allTime !== null) {
        $header[] = 'All-Time ' . MODE_LABEL[$mode];
    }
    array_push($header, 'Company', 'Address 1', 'Address 2', 'City', 'State', 'ZIP', 'Country');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    // Explicit separator/enclosure/escape: PHP 8.4 deprecates omitting $escape,
    // and '' disables the legacy backslash escaping for RFC-4180-clean output.
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $r) {
        $line = [
            $r['rank'], $r['name'], $r['email'], $r['order_count'], $r['items'],
            number_format($r['spent'], 2, '.', ''),
            number_format($r['per_item'], 2, '.', ''),
            number_format($r['per_order'], 2, '.', ''),
        ];
        if ($allTime !== null) {
            $line[] = MODE_CURRENCY[$mode]
                ? number_format((float) $r['all_time'], 2, '.', '')
                : (string) $r['all_time'];
        }
        array_push($line, $r['company'], $r['address1'], $r['address2'],
                          $r['city'], $r['state'], $r['zip'], $r['country']);
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── JSON ─────────────────────────────────────────────────────────────────────
$payload = [
    'mode'            => $mode,
    'period'          => $period,
    'limit'           => $limit,
    'count'           => count($rows),
    'total_customers' => count($all),
    'customers'       => $rows,
];
if (isset(MODE_DENOMINATOR[$mode])) {
    $payload['ratio_floor']      = $ratioFloor;
    $payload['pool_size']        = $poolSize;
    $payload['floor_percentile'] = (int) round(RATIO_FLOOR_PERCENTILE * 100);
}

header('Content-Type: application/json');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
