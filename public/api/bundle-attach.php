<?php
declare(strict_types=1);

/**
 * Attach a component product to a bundle.
 *
 * POST /api/bundle-attach.php
 * Body (application/x-www-form-urlencoded):
 *   bundle_id=<products.id of is_bundle=1 product>
 *   component_id=<products.id of is_bundle=0 product>
 * Header: X-CSRF-Token: <token>
 *
 * Rejects attempts to attach a bundle as a component (bundles-within-bundles
 * are not supported).  Duplicate attachments are silently accepted via
 * ON CONFLICT DO NOTHING.
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

if ($bundleId === $componentId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A bundle cannot be a component of itself.']);
    exit;
}

$db = getDb($config);

$check = $db->prepare(
    "SELECT id, is_bundle FROM products WHERE id = ? AND deleted_at IS NULL"
);

$check->execute([$bundleId]);
$bundle = $check->fetch();
if (!$bundle || (int) $bundle['is_bundle'] !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bundle_id does not refer to a bundle product.']);
    exit;
}

$check->execute([$componentId]);
$component = $check->fetch();
if (!$component) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Component product not found.']);
    exit;
}
if ((int) $component['is_bundle'] === 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bundles cannot be attached as components.']);
    exit;
}

$db->prepare(
    "INSERT INTO bundle_components (bundle_product_id, component_product_id)
     VALUES (?, ?)
     ON CONFLICT(bundle_product_id, component_product_id) DO NOTHING"
)->execute([$bundleId, $componentId]);

echo json_encode(['ok' => true]);
