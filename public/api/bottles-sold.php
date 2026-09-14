<?php
declare(strict_types=1);

/**
 * Bottles sold per size over a timeframe, with bundles counted as the bottles
 * inside them.
 *
 * GET /api/bottles-sold.php?period=<period>[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 *
 * period  30d | 90d | ytd | ttm | all | custom (default 30d).  custom takes
 *         from and to as calendar days in the display timezone, both inclusive.
 *
 * A bundle line counts quantity × the bundle's component count, at the line's
 * own size, and only once the bundle is marked complete with components in it.
 * Any other bundle counts no bottles and is tallied under unexpanded instead,
 * so the page can say its figures are short rather than let them read as whole.
 * unexpanded splits on whether the Bundles page can fix it: not_set_up is an
 * active bundle that page lists for setting up, and not_on_bundles_page is
 * everything it does not list — drafts, deleted products, and bundles whose
 * product is gone from the products table.
 *
 * Orders of every status count, and so do orders with no email: a bottle on
 * either was still sold.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

header('Content-Type: application/json');

const BOTTLE_SIZES = [1, 5, 10];

$tz     = new DateTimeZone($config['display_timezone']);
$now    = new DateTimeImmutable('now', $tz);
$period = strtolower(trim((string) ($_GET['period'] ?? '30d')));

// Bounds are wall-clock times in the display timezone, start inclusive and end
// exclusive: a day picked on a calendar has to mean that day where the shop is.
$start = null;
$end   = null;
$from  = null;
$to    = null;

switch ($period) {
    case 'all':
        break;
    case '30d':
        $start = $now->modify('-30 days');
        break;
    case '90d':
        $start = $now->modify('-90 days');
        break;
    case 'ytd':
        $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);
        break;
    case 'ttm':
        $start = $now->modify('-12 months');
        break;
    case 'custom':
        $from    = trim((string) ($_GET['from'] ?? ''));
        $to      = trim((string) ($_GET['to'] ?? ''));
        $fromDay = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $toDay   = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        // createFromFormat() rolls 2026-02-30 over into March rather than
        // failing, so a date is only accepted if it formats back unchanged.
        if ($fromDay === false || $toDay === false
            || $fromDay->format('Y-m-d') !== $from || $toDay->format('Y-m-d') !== $to) {
            http_response_code(400);
            echo json_encode(['error' => 'Choose both a From and a To date.']);
            exit;
        }
        if ($fromDay > $toDay) {
            http_response_code(400);
            echo json_encode(['error' => 'The From date must not be after the To date.']);
            exit;
        }
        $start = $fromDay;
        $end   = $toDay->modify('+1 day');
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid period. Use all, 30d, 90d, ytd, ttm, or custom.']);
        exit;
}

// shopify_created_at is Shopify's own string with its offset, not UTC text, so
// it is compared through strftime(), which yields the UTC instant it names.
$utc    = new DateTimeZone('UTC');
$where  = ['oli.variant_ml IN (' . implode(', ', BOTTLE_SIZES) . ')'];
$params = [];
if ($start !== null) {
    $where[]          = "strftime('%Y-%m-%d %H:%M:%S', o.shopify_created_at) >= :start";
    $params[':start'] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
}
if ($end !== null) {
    $where[]        = "strftime('%Y-%m-%d %H:%M:%S', o.shopify_created_at) < :end";
    $params[':end'] = $end->setTimezone($utc)->format('Y-m-d H:i:s');
}

$db = getDb($config);

// LEFT JOIN products: a line item whose product has since left the catalog was
// still sold, and an inner join would drop it.
$stmt = $db->prepare("
    SELECT oli.variant_ml              AS ml,
           oli.title                   AS title,
           p.id                        AS product_id,
           p.is_bundle                 AS is_bundle,
           p.deleted_at                AS deleted_at,
           p.status                    AS status,
           COALESCE(bs.is_complete, 0) AS is_complete,
           (SELECT COUNT(*) FROM bundle_components bc
            WHERE  bc.bundle_product_id = p.id) AS components,
           SUM(oli.quantity)           AS units
    FROM   order_line_items oli
    JOIN   orders           o  ON o.id = oli.order_id
    LEFT   JOIN products      p  ON p.shopify_product_id = oli.shopify_product_id
    LEFT   JOIN bundle_states bs ON bs.product_id = p.id
    WHERE  " . implode(' AND ', $where) . "
    GROUP  BY oli.variant_ml, p.id, oli.title
");
$stmt->execute($params);

$bottles          = array_fill_keys(BOTTLE_SIZES, 0);
$notSetUp         = 0;
$notOnBundlesPage = 0;

foreach ($stmt as $r) {
    $units = (int) $r['units'];

    // With no products row there is no is_bundle to read, so the line's own
    // title goes through the rule sync-products.php derives is_bundle by.
    $isBundle = $r['product_id'] !== null
        ? (int) $r['is_bundle'] === 1
        : (bool) preg_match('/\bbundle\s*$/i', (string) $r['title']);

    if (!$isBundle) {
        $bottles[(int) $r['ml']] += $units;
    } elseif ((int) $r['is_complete'] === 1 && (int) $r['components'] > 0) {
        $bottles[(int) $r['ml']] += $units * (int) $r['components'];
    } elseif ($r['product_id'] !== null && $r['deleted_at'] === null && $r['status'] === 'active') {
        // The Bundles page lists only active, undeleted bundles for setting up,
        // so these are the only ones pointing the operator there would help.
        $notSetUp += $units;
    } else {
        $notOnBundlesPage += $units;
    }
}

echo json_encode([
    'period'     => $period,
    'from'       => $from,
    'to'         => $to,
    'sizes'      => array_map(fn($ml) => ['ml' => $ml, 'bottles' => $bottles[$ml]], BOTTLE_SIZES),
    'unexpanded' => ['not_set_up' => $notSetUp, 'not_on_bundles_page' => $notOnBundlesPage],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
