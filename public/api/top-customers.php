<?php
declare(strict_types=1);

/**
 * Top-spending customers with mailing addresses.
 *
 * GET /api/top-customers.php?limit=<int>[&format=csv]
 *
 * Ranks customers by lifetime spend (SUM of order total_price across every
 * order, no status filter) keyed by lowercased email, and attaches each
 * customer's most-recent shipping address pulled from that order's raw_data.
 * There is no customer/address table — addresses live only inside the Shopify
 * order JSON, so we decode the latest order per customer to get one.
 *
 * limit   number of customers to return (default 100, clamped 1..1000).
 * format  'csv' streams a spreadsheet download; anything else returns JSON.
 *
 * JSON response shape:
 * {
 *   "limit": <int>,
 *   "count": <int>,
 *   "customers": [
 *     {
 *       "rank": <int>, "name": "...", "email": "...",
 *       "order_count": <int>, "spent": <float>,
 *       "company": "...", "address1": "...", "address2": "...",
 *       "city": "...", "state": "...", "zip": "...", "country": "..."
 *     }, ...
 *   ]
 * }
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

$limit  = (int) ($_GET['limit'] ?? 100);
$limit  = max(1, min(1000, $limit));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

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

// Rank customers by summed spend, keyed by lowercased email.  Orders with a
// blank email can't be keyed to a person, so they're excluded.
$aggStmt = $db->prepare(
    "SELECT lower(customer_email) AS email_key,
            ROUND(SUM(total_price), 2) AS spent,
            COUNT(*)                   AS order_count
     FROM   orders
     WHERE  TRIM(customer_email) != ''
     GROUP  BY lower(customer_email)
     ORDER  BY spent DESC, order_count DESC
     LIMIT  :limit"
);
$aggStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$aggStmt->execute();
$top = $aggStmt->fetchAll();

// For each top customer, fetch their most-recent order for the address.
$latestStmt = $db->prepare(
    "SELECT customer_name, customer_email, raw_data
     FROM   orders
     WHERE  lower(customer_email) = :email_key
     ORDER  BY shopify_created_at DESC, id DESC
     LIMIT  1"
);

$rows = [];
$rank = 0;
foreach ($top as $t) {
    $rank++;
    $latestStmt->execute([':email_key' => $t['email_key']]);
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
        'email'       => (string) ($latest['customer_email'] ?? $t['email_key']),
        'order_count' => (int) $t['order_count'],
        'spent'       => (float) $t['spent'],
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
    $filename = 'top-' . $limit . '-customers-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    // Explicit separator/enclosure/escape: PHP 8.4 deprecates omitting $escape,
    // and '' disables the legacy backslash escaping for RFC-4180-clean output.
    fputcsv($out, ['Rank', 'Name', 'Email', 'Orders', 'Total Spent', 'Company',
                   'Address 1', 'Address 2', 'City', 'State', 'ZIP', 'Country'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['rank'], $r['name'], $r['email'], $r['order_count'],
            number_format($r['spent'], 2, '.', ''),
            $r['company'], $r['address1'], $r['address2'],
            $r['city'], $r['state'], $r['zip'], $r['country'],
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── JSON ─────────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'limit'     => $limit,
    'count'     => count($rows),
    'customers' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
