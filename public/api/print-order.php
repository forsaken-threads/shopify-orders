<?php
declare(strict_types=1);

/**
 * Print labels for an order.
 *
 * POST /api/print-order.php
 * Body (multipart/form-data):
 *   order_id            — internal order PK
 *   items[i][title]     — (possibly edited) stripped product title
 *   items[i][full_title]— original full product title
 *   items[i][custom_brand]    — (possibly edited) brand
 *   items[i][original_brand]  — original brand value
 *   items[i][shopify_product_id] — Shopify product ID
 *   items[i][ml]              — variant ML size (1, 5, or 10)
 * Header: X-CSRF-Token: <token>
 *
 * 1. Logs each label to ./logs/print-labels.log
 * 2. Updates order status from 'pending' to 'printed'
 * 3. For any brand changes, logs to ./logs/brand-updates.log
 *    (stubbed — would call Shopify Admin API to update product metafield)
 *
 * Returns JSON {ok:true} on success or {ok:false,error:"..."} on failure.
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../../app/db.php';
require __DIR__ . '/../auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF validation ───────────────────────────────────────────────────────────

$providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken  = $_SESSION['csrf_token']        ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

// ── Validate input ────────────────────────────────────────────────────────────

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
    exit;
}

$items = $_POST['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No line items provided.']);
    exit;
}

$db = getDb($config);

// Verify the order exists and is pending.
$orderStmt = $db->prepare("SELECT id, shopify_order_id FROM orders WHERE id = ? AND status = 'pending'");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found or not in pending status.']);
    exit;
}

// ── Log labels ────────────────────────────────────────────────────────────────

$logDir  = dirname(__DIR__, 2) . '/logs';
$labelLog = $logDir . '/print-labels.log';

$brandChanges = [];
$labelEntries = '';
$validMlSizes = ['1', '5', '10'];
$timestamp    = date('Y-m-d H:i:s');

foreach ($items as $item) {
    $title         = trim((string) ($item['title'] ?? ''));
    $brand         = trim((string) ($item['custom_brand'] ?? ''));
    $originalBrand = (string) ($item['original_brand'] ?? '');
    $fullTitle     = trim((string) ($item['full_title'] ?? ''));
    $productId     = trim((string) ($item['shopify_product_id'] ?? ''));
    $ml            = trim((string) ($item['ml'] ?? ''));

    if (!in_array($ml, $validMlSizes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing ML size for item: ' . $title]);
        exit;
    }

    $qty = max(1, (int) ($item['quantity'] ?? 1));

    // Build the SSH print command — the remote command is passed via escapeshellarg
    // to avoid nested quoting issues with single quotes inside the ssh '...' wrapper.
    $remoteCmd = '~/print-service/venv/bin/python3 ~/print-service/print-label-' . $ml . 'ml.py '
               . escapeshellarg($title) . ' ' . escapeshellarg($brand);
    $cmd = 'ssh keith@percival.spartang.com ' . escapeshellarg($remoteCmd);

    // Execute for each copy (quantity)
    for ($q = 0; $q < $qty; $q++) {
        exec($cmd . ' 2>&1', $cmdOutput, $cmdResult);
    }

    // Log the label entry
    $labelEntries .= "[{$timestamp}] {$ml}ml | {$title} | {$brand} | order:{$order['shopify_order_id']} | exit:{$cmdResult}\n";

    if ($originalBrand !== $brand && $productId !== '') {
        $brandChanges[] = [
            'shopify_product_id' => $productId,
            'full_title'         => $fullTitle,
            'old_brand'          => $originalBrand,
            'new_brand'          => $brand,
        ];
    }
}

file_put_contents($labelLog, $labelEntries, FILE_APPEND | LOCK_EX);

// ── Update order status to printed ────────────────────────────────────────────

$db->prepare("UPDATE orders SET status = 'printed' WHERE id = ? AND status = 'pending'")
   ->execute([$orderId]);

// ── Log brand changes (stub for Shopify Admin API metafield update) ───────────
//
// In production this would call:
//   PUT https://{shop}/admin/api/{version}/products/{product_id}/metafields.json
// with the updated custom.brand value. That update triggers a products/update
// webhook which would sync the change back to our local products table.

if (!empty($brandChanges)) {
    $brandLog = $logDir . '/brand-updates.log';

    foreach ($brandChanges as $change) {
        $entry = [
            'timestamp'          => $timestamp,
            'shopify_product_id' => $change['shopify_product_id'],
            'full_title'         => $change['full_title'],
            'old_brand'          => $change['old_brand'],
            'new_brand'          => $change['new_brand'],
        ];
        file_put_contents(
            $brandLog,
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}

echo json_encode(['ok' => true]);
