<?php
declare(strict_types=1);

/**
 * Streams a CSV file for a single pending order's line items.
 *
 * Usage: download.php?id=<internal_order_id>
 *
 * CSV columns:
 *   Order Number | Order Date | Customer Name | Customer Email |
 *   SKU | Product | Variant | Vendor | Quantity | Unit Price | Line Total | Currency | Custom Brand
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

// ── Validate input ────────────────────────────────────────────────────────────

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($orderId === false || $orderId === null) {
    http_response_code(400);
    exit('Bad Request: missing or invalid id parameter.');
}

// ── Fetch order and its line items ────────────────────────────────────────────

$db = getDb($config);

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if ($order === false) {
    http_response_code(404);
    exit('Not Found: order does not exist.');
}

$lineStmt = $db->prepare(
    'SELECT * FROM order_line_items WHERE order_id = ? ORDER BY id ASC'
);
$lineStmt->execute([$orderId]);
$lineItems = $lineStmt->fetchAll();

// ── Stream CSV ────────────────────────────────────────────────────────────────

$safeOrderNum = preg_replace('/[^A-Za-z0-9_\-]/', '', $order['order_number']);
$filename     = sprintf('order-%s-%s.csv', $safeOrderNum, date('Ymd-His'));

header('Content-Type: text/csv; charset=UTF-8');
header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));
header('Cache-Control: no-store');
header('Pragma: no-cache');

$out = fopen('php://output', 'wb');

// UTF-8 BOM — makes Excel open the file correctly without import wizard.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Order Number',
    'Order Date',
    'Customer Name',
    'Customer Email',
    'SKU',
    'Product',
    'Variant',
    'Vendor',
    'Quantity',
    'Unit Price',
    'Line Total',
    'Currency',
    'Custom Brand',
], separator: ',', enclosure: '"', escape: '\\');

foreach ($lineItems as $item) {
    $unitPrice = (float) $item['price'];
    $quantity  = (int)   $item['quantity'];
    $lineTotal = number_format($unitPrice * $quantity, 2, '.', '');

    fputcsv($out, [
        $order['order_number'],
        $order['shopify_created_at'],
        $order['customer_name'],
        $order['customer_email'],
        $item['sku']           ?? '',
        $item['title'],
        $item['variant_title'] ?? '',
        $item['vendor']        ?? '',
        $quantity,
        number_format($unitPrice, 2, '.', ''),
        $lineTotal,
        $order['currency'],
        $item['custom_brand']  ?? '',
    ], separator: ',', enclosure: '"', escape: '\\');
}

fclose($out);
