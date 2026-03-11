<?php
declare(strict_types=1);

/**
 * Print labels for an order (two-stage flow).
 *
 * POST /api/print-order.php
 * Body (multipart/form-data):
 *   action              — "print" (default) or "confirm"
 *
 * action=print:
 *   order_id            — internal order PK
 *   items[i][title]     — (possibly edited) stripped product title
 *   items[i][full_title]— original full product title
 *   items[i][custom_brand]    — (possibly edited) brand
 *   items[i][original_brand]  — original brand value
 *   items[i][shopify_product_id] — Shopify product ID
 *   items[i][ml]              — variant ML size (1, 5, or 10)
 *   items[i][quantity]        — label quantity
 * Returns: {ok:true, results:[{index, title, status:"ok"|"error", error?}]}
 * Does NOT update order status — the user must confirm after reviewing.
 *
 * action=confirm:
 *   order_id            — internal order PK
 * Updates order status to 'printed'.
 * Returns: {ok:true}
 *
 * Header: X-CSRF-Token: <token>
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

// ── Validate order_id ────────────────────────────────────────────────────────

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
    exit;
}

$db = getDb($config);

$action = trim((string) ($_POST['action'] ?? 'print'));

if ($action === 'oneoff') {
    // One-off prints work on any order status and never change it.
    $orderStmt = $db->prepare("SELECT id, shopify_order_id, status FROM orders WHERE id = ?");
} else {
    // Regular print/confirm requires pending or fulfilled status.
    $orderStmt = $db->prepare("SELECT id, shopify_order_id, status FROM orders WHERE id = ? AND status IN ('pending', 'fulfilled')");
}
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found or not in a printable status.']);
    exit;
}

// ── action=confirm: finalize the order ───────────────────────────────────────

if ($action === 'confirm') {
    // Only transition pending → printed; fulfilled orders keep their status.
    if ($order['status'] === 'pending') {
        $db->prepare("UPDATE orders SET status = 'printed' WHERE id = ? AND status = 'pending'")
           ->execute([$orderId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── action=print: execute print commands and return per-item results ─────────

$items = $_POST['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No line items provided.']);
    exit;
}

$logDir     = dirname(__DIR__, 2) . '/logs';
$scriptsDir = dirname(__DIR__, 2) . '/scripts';
$labelLog   = $logDir . '/print-labels.log';

$labelEntries  = '';
$validMlSizes  = ['1', '5', '10'];
$timestamp     = date('Y-m-d H:i:s');
$results       = [];   // per-item status to return to the frontend

$prefUpdateStmt = $db->prepare(
    "UPDATE products SET preferred_title = ?, preferred_brand = ? WHERE shopify_product_id = ?"
);

foreach ($items as $idx => $item) {
    $title          = trim((string) ($item['title'] ?? ''));
    $brand          = trim((string) ($item['custom_brand'] ?? ''));
    $fullTitle      = trim((string) ($item['full_title'] ?? ''));
    $productId      = trim((string) ($item['shopify_product_id'] ?? ''));
    $ml             = trim((string) ($item['ml'] ?? ''));
    $preferredTitle = (string) ($item['preferred_title'] ?? '');
    $preferredBrand = (string) ($item['preferred_brand'] ?? '');

    if (!in_array($ml, $validMlSizes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing ML size for item: ' . $title]);
        exit;
    }

    $qty = max(1, (int) ($item['quantity'] ?? 1));

    // Build the SSH print command
    $mlArg = $ml . 'ml';
    $remoteCmd = '~/print-service/venv/bin/python3 ~/print-service/print-label.py '
               . escapeshellarg($mlArg) . ' ' . escapeshellarg($title) . ' ' . escapeshellarg($brand);
    $cmd = 'ssh keith@percival.spartang.com ' . escapeshellarg($remoteCmd);

    // Execute for each copy (quantity) — track per-item success
    $itemFailed = false;
    $itemError  = '';
    for ($q = 0; $q < $qty; $q++) {
        $cmdOutput = [];
        $cmdResult = 0;
        exec($cmd . ' 2>&1', $cmdOutput, $cmdResult);
        $outputStr = implode("\n", $cmdOutput);
        if ($cmdResult !== 0) {
            $logLine = "[{$timestamp}] exit:{$cmdResult} | {$mlArg} | {$title} | {$brand} | order:{$order['shopify_order_id']}\ncmd: {$cmd}\n{$outputStr}\n---\n";
            file_put_contents($logDir . '/print-errors.log', $logLine, FILE_APPEND | LOCK_EX);
            $itemFailed = true;
            $itemError  = $outputStr;
        } else {
            $logLine = "[{$timestamp}] exit:0 | {$mlArg} | {$title} | {$brand} | order:{$order['shopify_order_id']}\n{$outputStr}\n---\n";
            file_put_contents($logDir . '/print-results.log', $logLine, FILE_APPEND | LOCK_EX);
        }
    }

    $result = ['index' => (int) $idx, 'title' => $title, 'status' => $itemFailed ? 'error' : 'ok'];
    if ($itemFailed) {
        $result['error'] = $itemError;
    }
    $results[] = $result;

    // Log the label entry
    $labelEntries .= "[{$timestamp}] {$mlArg} | {$title} | {$brand} | order:{$order['shopify_order_id']} | " . ($itemFailed ? 'FAIL' : 'ok') . "\n";

    // Update preferred title/brand in products table if the submitted values
    // differ from the current preferences.
    if ($productId !== '' && ($title !== $preferredTitle || $brand !== $preferredBrand)) {
        $prefUpdateStmt->execute([$title, $brand, $productId]);
    }
}

file_put_contents($labelLog, $labelEntries, FILE_APPEND | LOCK_EX);

// Return per-item results — never update order status here
echo json_encode(['ok' => true, 'results' => $results]);
