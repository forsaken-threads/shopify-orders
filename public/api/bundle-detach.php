<?php
declare(strict_types=1);

/**
 * Detach a component product from a bundle.
 *
 * POST /api/bundle-detach.php
 * Body (application/x-www-form-urlencoded):
 *   bundle_id=<int>
 *   component_id=<int>
 * Header: X-CSRF-Token: <token>
 *
 * Returns JSON {ok:true} on success.  Detaching a component that isn't
 * currently attached is a no-op and also returns ok.
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

$bundleId    = (int) ($_POST['bundle_id']    ?? 0);
$componentId = (int) ($_POST['component_id'] ?? 0);

if ($bundleId <= 0 || $componentId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bundle_id and component_id are required.']);
    exit;
}

$db = getDb($config);
$db->prepare(
    "DELETE FROM bundle_components WHERE bundle_product_id = ? AND component_product_id = ?"
)->execute([$bundleId, $componentId]);

echo json_encode(['ok' => true]);
