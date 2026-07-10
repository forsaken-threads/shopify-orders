<?php
declare(strict_types=1);

/**
 * Top customers with mailing addresses, rankable three ways.
 *
 * GET /api/top-customers.php?mode=<mode>&limit=<int>[&format=csv]
 *
 * Customers are keyed by lowercased email.  Three ranking modes:
 *   spend     (default) — total spend, SUM of order total_price.
 *   items                — total item count, SUM of line-item quantity.
 *   per_item             — average spend per item (total spend / items),
 *                          restricted to customers whose item count is at or
 *                          above the 4th-quintile floor (60th percentile) of
 *                          the item-count distribution.  This filters out
 *                          low-volume buyers whose per-item average is noise.
 *
 * Spend is order-level (total_price) and item count is line-item level
 * (quantity); the two are aggregated separately and joined per customer so
 * the order total is never multiplied across line items.
 *
 * Each customer's mailing address is pulled from their most-recent order's
 * raw_data — there is no customer/address table.
 *
 * limit   number of customers to return (default 100, clamped 1..1000).
 * format  'csv' streams a spreadsheet download; anything else returns JSON.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

// The item-count floor for per_item mode: the 4th-quintile floor of the
// item-count distribution (60th percentile — "at least in the 4th quintile").
const PER_ITEM_FLOOR_PERCENTILE = 0.60;

$limit  = (int) ($_GET['limit'] ?? 100);
$limit  = max(1, min(1000, $limit));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
$mode   = strtolower(trim((string) ($_GET['mode'] ?? 'spend')));

if (!in_array($mode, ['spend', 'items', 'per_item'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid mode. Use spend, items, or per_item.']);
    exit;
}

$period = strtolower(trim((string) ($_GET['period'] ?? 'all')));

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

// Per-customer aggregate over every customer (needed whole so per_item mode
// can compute the item-count percentile).  Spend and order count come from the
// order level; item count from the line-item level — joined per customer so
// total_price is never counted once per line item.  A trailing-window filter,
// when set, is applied inside both CTEs; two distinct placeholders because the
// same named param can't be reused across a query under PDO_SQLite.
$ordDateFilter = $dateMin !== null ? ' AND shopify_created_at   >= :dmin_ord' : '';
$itmDateFilter = $dateMin !== null ? ' AND o.shopify_created_at >= :dmin_itm' : '';

$aggSql = "
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

$aggStmt = $db->prepare($aggSql);
if ($dateMin !== null) {
    $aggStmt->bindValue(':dmin_ord', $dateMin);
    $aggStmt->bindValue(':dmin_itm', $dateMin);
}
$aggStmt->execute();

$all = [];
foreach ($aggStmt as $r) {
    $items   = (int) $r['items'];
    $spent   = (float) $r['spent'];
    $all[] = [
        'email_key'   => (string) $r['email_key'],
        'spent'       => $spent,
        'order_count' => (int) $r['order_count'],
        'items'       => $items,
        'per_item'    => $items > 0 ? round($spent / $items, 2) : 0.0,
    ];
}

// ── Select + sort per mode ───────────────────────────────────────────────────
$itemFloor = 0;
$poolSize  = count($all);

if ($mode === 'items') {
    usort($all, fn($a, $b) => [$b['items'], $b['spent']] <=> [$a['items'], $a['spent']]);
    $ranked = $all;
} elseif ($mode === 'per_item') {
    // 4th-quintile floor of the item-count distribution.
    $counts = array_map(fn($c) => $c['items'], $all);
    sort($counts);
    $nAll = count($counts);
    if ($nAll > 0) {
        $idx = (int) floor(PER_ITEM_FLOOR_PERCENTILE * $nAll);
        if ($idx >= $nAll) {
            $idx = $nAll - 1;
        }
        $itemFloor = $counts[$idx];
    }
    $ranked = array_values(array_filter($all, fn($c) => $c['items'] >= $itemFloor));
    $poolSize = count($ranked);
    usort($ranked, fn($a, $b) => [$b['per_item'], $b['items']] <=> [$a['per_item'], $a['items']]);
} else {
    usort($all, fn($a, $b) => [$b['spent'], $b['items']] <=> [$a['spent'], $a['items']]);
    $ranked = $all;
}

$ranked = array_slice($ranked, 0, $limit);

// ── Attach the mailing address for each ranked customer ──────────────────────
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

    $rows[] = [
        'rank'        => $rank,
        'name'        => $addr['name'],
        'email'       => (string) ($latest['customer_email'] ?? $c['email_key']),
        'order_count' => $c['order_count'],
        'items'       => $c['items'],
        'spent'       => $c['spent'],
        'per_item'    => $c['per_item'],
        'company'     => $addr['company'],
        'address1'    => $addr['address1'],
        'address2'    => $addr['address2'],
        'city'        => $addr['city'],
        'state'       => $addr['state'],
        'zip'         => $addr['zip'],
        'country'     => $addr['country'],
    ];
}

// ── CSV download ─────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $modeSlug = ['spend' => 'spend', 'items' => 'items', 'per_item' => 'spend-per-item'][$mode];
    $filename = 'top-' . $limit . '-customers-by-' . $modeSlug . '-' . $period . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    // Explicit separator/enclosure/escape: PHP 8.4 deprecates omitting $escape,
    // and '' disables the legacy backslash escaping for RFC-4180-clean output.
    fputcsv($out, ['Rank', 'Name', 'Email', 'Orders', 'Items', 'Total Spent', 'Spend Per Item',
                   'Company', 'Address 1', 'Address 2', 'City', 'State', 'ZIP', 'Country'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['rank'], $r['name'], $r['email'], $r['order_count'], $r['items'],
            number_format($r['spent'], 2, '.', ''),
            number_format($r['per_item'], 2, '.', ''),
            $r['company'], $r['address1'], $r['address2'],
            $r['city'], $r['state'], $r['zip'], $r['country'],
        ], ',', '"', '');
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
if ($mode === 'per_item') {
    $payload['item_floor']       = $itemFloor;
    $payload['pool_size']        = $poolSize;
    $payload['floor_percentile'] = (int) round(PER_ITEM_FLOOR_PERCENTILE * 100);
}

header('Content-Type: application/json');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
