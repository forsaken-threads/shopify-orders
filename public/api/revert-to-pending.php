<?php
declare(strict_types=1);

/**
 * Revert an order back to pending.
 *
 * POST /api/revert-to-pending.php
 * Body (application/x-www-form-urlencoded): id=<int>
 * Header: X-CSRF-Token: <token>
 *
 * Transitions an order from 'printed' to 'pending'.
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

// ── Revert order to pending ──────────────────────────────────────────────────

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
    exit;
}

$db = getDb($config);
$stmt = $db->prepare("UPDATE orders SET status = 'pending' WHERE id = ? AND status = 'printed'");
$stmt->execute([$id]);

if ($stmt->rowCount() === 0) {
    echo json_encode(['ok' => false, 'error' => 'Order not found or not in printed status.']);
    exit;
}

echo json_encode(['ok' => true]);
