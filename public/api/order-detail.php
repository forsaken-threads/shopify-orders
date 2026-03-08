<?php
declare(strict_types=1);

/**
 * Order detail endpoint — async accordion & raw-data modal.
 *
 * GET /api/order-detail.php?id=<internal_order_id>
 *
 * Returns order metadata, line items, and the full raw_data JSON for a single
 * order.  Used by the orders table to populate accordion rows and the raw-data
 * modal on demand rather than embedding all data at page-load time.
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 * {
 *   "order": {
 *     "id": <int>,
 *     "shopify_order_id": "...",
 *     "order_number": "...",
 *     "customer_name": "...",
 *     "customer_email": "...",
 *     "total_price": <float>,
 *     "currency": "...",
 *     "status": "...",
 *     "shopify_created_at": "...",
 *     "received_at": "...",
 *     "raw_data": { ... }
 *   },
 *   "line_items": [
 *     {
 *       "title": "...",
 *       "variant_title": "...",
 *       "variant_ml": <int|null>,
 *       "sku": "...",
 *       "vendor": "...",
 *       "custom_brand": "...",
 *       "quantity": <int>,
 *       "price": <float>
 *     }, ...
 *   ]
 * }
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../../app/db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

$db = getDb($config);

$stmt = $db->prepare(
    "SELECT id, shopify_order_id, order_number, customer_name, customer_email,
            total_price, currency, status, shopify_created_at, received_at, raw_data
     FROM   orders
     WHERE  id = ?"
);
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

$liStmt = $db->prepare(
    "SELECT title, variant_title, variant_ml, sku, vendor, quantity, price, custom_brand, shopify_product_id
     FROM   order_line_items
     WHERE  order_id = ?
     ORDER  BY id ASC"
);
$liStmt->execute([$id]);
$lineItems = $liStmt->fetchAll();

echo json_encode([
    'order' => [
        'id'                 => (int) $order['id'],
        'shopify_order_id'   => $order['shopify_order_id'],
        'order_number'       => $order['order_number'],
        'customer_name'      => $order['customer_name'],
        'customer_email'     => $order['customer_email'],
        'total_price'        => (float) $order['total_price'],
        'currency'           => $order['currency'],
        'status'             => $order['status'],
        'shopify_created_at' => $order['shopify_created_at'],
        'received_at'        => $order['received_at'],
        'raw_data'           => json_decode($order['raw_data'] ?? 'null'),
    ],
    'line_items' => array_map(fn($li) => [
        'title'        => $li['title'],
        'variant_title'=> $li['variant_title'] ?? '',
        'variant_ml'   => $li['variant_ml'] !== null ? (int) $li['variant_ml'] : null,
        'sku'          => $li['sku'] ?? '',
        'vendor'       => $li['vendor'] ?? '',
        'custom_brand' => $li['custom_brand'] ?? '',
        'shopify_product_id' => $li['shopify_product_id'] ?? '',
        'quantity'     => (int) $li['quantity'],
        'price'        => (float) $li['price'],
    ], $lineItems),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
