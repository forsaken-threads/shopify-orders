<?php
declare(strict_types=1);

/**
 * Archive an order.
 *
 * POST /api/archive-order.php
 * Body (application/x-www-form-urlencoded): id=<int>
 * Header: X-CSRF-Token: <token>
 *
 * Transitions an order from 'pending' to 'archived'.
 * Returns JSON {ok:true} on success or {ok:false,error:"..."} on failure.
 *
 * Authentication: HTTP Basic Auth (same credentials as the web UI).
 * CSRF protection: token generated in auth.php and stored in the PHP session;
 *                  must be sent back by the client as the X-CSRF-Token header.
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

// ── Archive the order ─────────────────────────────────────────────────────────

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
    exit;
}

$db = getDb($config);
$db->prepare("UPDATE orders SET status = 'archived' WHERE id = ? AND status = 'pending'")
   ->execute([$id]);

echo json_encode(['ok' => true]);
