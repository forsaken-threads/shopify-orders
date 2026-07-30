<?php
declare(strict_types=1);

/**
 * The current VIP list, as computed by the nightly scripts/score-vips.php.
 *
 * GET /api/vips.php[?format=csv]
 *
 * Returns the most recent run's fifty VIPs in rank order, each with their star
 * count, which of the four stars they hold, and the spend / item figures behind
 * them for both windows.  Nothing is ranked here — vip_scores is the answer, and
 * re-deriving it would be a second implementation free to drift from app/vip.php.
 *
 * Stars, in the priority order the ranking compares them:
 *   star_6m_spend  spend over the trailing six months
 *   star_6m_items  items over the trailing six months
 *   star_at_spend  spend over all time
 *   star_at_items  items over all time
 *
 * Before the job has ever run there is no list; that is a normal state on a
 * fresh deploy, and it returns computed_on: null with an empty array rather
 * than an error.
 *
 * format  'csv' streams a spreadsheet download; anything else returns JSON.
 *
 * Names are not stored with the scores — only the lowercased email key — so
 * each is pulled from orders.customer_name on that customer's most recent
 * order.
 *
 * That differs from the Top Customers report on purpose.  It resolves the same
 * customer through mailingAddressFromRaw(), which prefers the shipping-address
 * recipient, so the two show a different name for a minority of customers.
 * This page carries no addresses, so the account name is the apter field here,
 * and unifying them would mean moving mailingAddressFromRaw() into the shared
 * library to settle a display label.  Identity is unaffected either way: both
 * reports key on the lowercased email.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

$db = getDb($config);

$computedOn = $db->query("SELECT MAX(computed_on) FROM vip_scores")->fetchColumn();
$computedOn = $computedOn !== false && $computedOn !== null ? (string) $computedOn : null;

$scored = [];
if ($computedOn !== null) {
    $stmt = $db->prepare(
        "SELECT email_key, vip_rank, score,
                star_6m_spend, star_6m_items, star_at_spend, star_at_items,
                spend_6m, items_6m, spend_at, items_at
         FROM   vip_scores
         WHERE  computed_on = :computed_on
         ORDER  BY vip_rank"
    );
    $stmt->execute([':computed_on' => $computedOn]);
    $scored = $stmt->fetchAll();
}

// The latest order overall, not the latest in any window — the freshest name on
// file is the one worth showing, matching the Top Customers report.
$latestStmt = $db->prepare(
    "SELECT customer_name, customer_email
     FROM   orders
     WHERE  lower(customer_email) = :email_key
     ORDER  BY shopify_created_at DESC, id DESC
     LIMIT  1"
);

$rows = [];
foreach ($scored as $s) {
    $latestStmt->execute([':email_key' => $s['email_key']]);
    $latest = $latestStmt->fetch() ?: [];

    $rows[] = [
        'rank'          => (int) $s['vip_rank'],
        'name'          => trim((string) ($latest['customer_name'] ?? '')),
        'email'         => (string) ($latest['customer_email'] ?? $s['email_key']),
        'score'         => (int) $s['score'],
        'star_6m_spend' => (int) $s['star_6m_spend'],
        'star_6m_items' => (int) $s['star_6m_items'],
        'star_at_spend' => (int) $s['star_at_spend'],
        'star_at_items' => (int) $s['star_at_items'],
        'spend_6m'      => (float) $s['spend_6m'],
        'items_6m'      => (int) $s['items_6m'],
        'spend_at'      => (float) $s['spend_at'],
        'items_at'      => (int) $s['items_at'],
    ];
}

// ── CSV download ─────────────────────────────────────────────────────────────

if ($format === 'csv') {
    $filename = 'vips-' . ($computedOn ?? 'none') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    // Explicit separator/enclosure/escape: PHP 8.4 deprecates omitting $escape,
    // and '' disables the legacy backslash escaping for RFC-4180-clean output.
    fputcsv($out, [
        'Rank', 'Name', 'Email', 'Score',
        '6mo Spend Star', '6mo Items Star', 'All-Time Spend Star', 'All-Time Items Star',
        '6mo Spend', '6mo Items', 'All-Time Spend', 'All-Time Items',
    ], ',', '"', '');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['rank'], $r['name'], $r['email'], $r['score'],
            $r['star_6m_spend'], $r['star_6m_items'], $r['star_at_spend'], $r['star_at_items'],
            number_format($r['spend_6m'], 2, '.', ''), $r['items_6m'],
            number_format($r['spend_at'], 2, '.', ''), $r['items_at'],
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── JSON ─────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
echo json_encode([
    'computed_on' => $computedOn,
    'count'       => count($rows),
    'vips'        => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
