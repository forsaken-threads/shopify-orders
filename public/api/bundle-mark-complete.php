<?php
declare(strict_types=1);

/**
 * Mark a bundle's component associations as complete.
 *
 * POST /api/bundle-mark-complete.php
 * Body (application/x-www-form-urlencoded):
 *   id=<products.id of is_bundle=1 product>
 * Header: X-CSRF-Token: <token>
 *
 * Upserts bundle_states.is_complete = 1 for the bundle.  Once marked complete
 * the bundle leaves the management list and becomes eligible for the bundle
 * print lookup.  Use /api/bundle-reopen.php to reverse.
 *
 * Returns JSON {ok:true} on success.
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

$providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken  = $_SESSION['csrf_token']        ?? '';
if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

$bundleId = (int) ($_POST['id'] ?? 0);
if ($bundleId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid bundle id.']);
    exit;
}

$db = getDb($config);

$check = $db->prepare("SELECT 1 FROM products WHERE id = ? AND is_bundle = 1 AND deleted_at IS NULL");
$check->execute([$bundleId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Bundle not found.']);
    exit;
}

$db->prepare(
    "INSERT INTO bundle_states (product_id, is_complete, updated_at)
     VALUES (?, 1, datetime('now'))
     ON CONFLICT(product_id) DO UPDATE SET is_complete = 1, updated_at = datetime('now')"
)->execute([$bundleId]);

echo json_encode(['ok' => true]);
