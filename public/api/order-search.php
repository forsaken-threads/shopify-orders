<?php
declare(strict_types=1);

/**
 * Order search endpoint.
 *
 * GET /api/order-search.php?q=<search_term>
 *
 * Searches orders by order_number, customer_name, and customer_email.
 * Returns up to 20 matching orders.
 *
 * Requires HTTP Basic Auth.
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../../app/db.php';
require __DIR__ . '/../auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$query = trim((string) ($_GET['q'] ?? ''));

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$db = getDb($config);

$like = '%' . $query . '%';

$stmt = $db->prepare(<<<'SQL'
    SELECT id, order_number, customer_name, customer_email, total_price, currency, status,
           shopify_created_at
    FROM   orders
    WHERE  order_number  LIKE :q1
       OR  customer_name LIKE :q2
       OR  customer_email LIKE :q3
    ORDER BY shopify_created_at DESC
    LIMIT 20
SQL);

$stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
$results = $stmt->fetchAll();

echo json_encode(array_map(fn($r) => [
    'id'             => (int) $r['id'],
    'order_number'   => $r['order_number'],
    'customer_name'  => $r['customer_name'],
    'customer_email' => $r['customer_email'],
    'total_price'    => (float) $r['total_price'],
    'currency'       => $r['currency'],
    'status'         => $r['status'],
    'shopify_created_at' => $r['shopify_created_at'],
], $results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
