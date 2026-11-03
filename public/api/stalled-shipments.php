<?php
declare(strict_types=1);

/**
 * Packages whose tracking has not shown them delivered N or more days after
 * the label was made, oldest first.
 *
 * GET /api/stalled-shipments.php?days=N
 *
 * Reads each order's own fulfillments out of raw_data, which the orders webhook
 * rewrites on every orders/updated delivery.  Shopify keeps a fulfillment's
 * shipment_status current from the carrier, so nothing here asks Shopify or
 * USPS anything.
 *
 * A fulfillment counts when Shopify still holds it (status 'success': a label
 * cancelled and redone leaves a 'cancelled' one behind), it has a tracking
 * number, and its shipment_status is anything but 'delivered' — including
 * none at all.  Orders cancelled or refunded in full are left out, since she
 * has already dealt with those.
 *
 * A fulfillment's updated_at has moved only when its shipment_status did, so it
 * is reported as when the status was last set: the time Shopify recorded it,
 * which can trail the scan by hours.  A scan that leaves the status alone does
 * not appear to move it, so "in transit since" is not the last scan.
 *
 * Only labels made in the last STALLED_LOOKBACK_DAYS are read.  Shopify stops
 * updating some fulfillments long before delivery, so the history holds dozens
 * still in transit from 2025, and with no way to mark a row handled they would
 * head the list forever.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

header('Content-Type: application/json');

const STALLED_LOOKBACK_DAYS = 30;

$days = filter_var($_GET['days'] ?? '7', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => STALLED_LOOKBACK_DAYS - 1],
]);
if ($days === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Days must be a whole number from 1 to ' . (STALLED_LOOKBACK_DAYS - 1) . '.']);
    exit;
}

$utc = new DateTimeZone('UTC');
$tz  = new DateTimeZone($config['display_timezone']);
$now = new DateTimeImmutable('now', $utc);

// Fulfillment timestamps carry Shopify's offset like shopify_created_at, so
// they are compared through strftime(), which yields the UTC instant.
$stmt = getDb($config)->prepare("
    SELECT o.id,
           o.order_number,
           o.customer_name,
           o.customer_email,
           strftime('%Y-%m-%d %H:%M:%S', json_extract(f.value, '$.created_at')) AS shipped_utc,
           strftime('%Y-%m-%d %H:%M:%S', json_extract(f.value, '$.updated_at')) AS status_utc,
           json_extract(f.value, '$.shipment_status') AS shipment_status,
           json_extract(f.value, '$.tracking_number') AS tracking_number,
           json_extract(f.value, '$.tracking_url')    AS tracking_url
    FROM   orders o, json_each(o.raw_data, '$.fulfillments') f
    WHERE  json_extract(f.value, '$.status') = 'success'
      AND  COALESCE(json_extract(f.value, '$.tracking_number'), '') <> ''
      AND  COALESCE(json_extract(f.value, '$.shipment_status'), '') <> 'delivered'
      AND  json_extract(o.raw_data, '$.cancelled_at') IS NULL
      AND  COALESCE(json_extract(o.raw_data, '$.financial_status'), '') <> 'refunded'
      AND  strftime('%Y-%m-%d %H:%M:%S', json_extract(f.value, '$.created_at')) >= :since
      AND  strftime('%Y-%m-%d %H:%M:%S', json_extract(f.value, '$.created_at')) <= :stalled_before
    ORDER  BY shipped_utc, o.id
");
$stmt->execute([
    ':since'          => $now->modify('-' . STALLED_LOOKBACK_DAYS . ' days')->format('Y-m-d H:i:s'),
    ':stalled_before' => $now->modify("-{$days} days")->format('Y-m-d H:i:s'),
]);

$shipments = [];
foreach ($stmt as $r) {
    $shipped = new DateTimeImmutable($r['shipped_utc'], $utc);
    $set     = new DateTimeImmutable($r['status_utc'], $utc);
    $url     = (string) $r['tracking_url'];

    $shipments[] = [
        'order_id'        => (int) $r['id'],
        'order_number'    => $r['order_number'],
        'customer_name'   => $r['customer_name'],
        'customer_email'  => $r['customer_email'],
        'shipped'         => $shipped->setTimezone($tz)->format('Y-m-d'),
        'days'            => intdiv($now->getTimestamp() - $shipped->getTimestamp(), 86400),
        'status'          => $r['shipment_status'],
        'status_since'    => $set->setTimezone($tz)->format('Y-m-d'),
        'status_days'     => intdiv($now->getTimestamp() - $set->getTimestamp(), 86400),
        'tracking_number' => $r['tracking_number'],
        // The URL is the carrier's, by way of whatever wrote the fulfillment,
        // and it lands in an href, so only a web address passes.
        'tracking_url'    => preg_match('#^https?://#i', $url) ? $url : null,
    ];
}

echo json_encode([
    'days'          => $days,
    'lookback_days' => STALLED_LOOKBACK_DAYS,
    'shipments'     => $shipments,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
