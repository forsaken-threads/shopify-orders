<?php
declare(strict_types=1);

/**
 * Set one variant's available stock in Shopify, from an Out of Stock row.
 *
 * POST /api/inventory-set.php
 * Body (multipart/form-data):
 *   product_id  — internal products.id of the row
 *   variant_id  — Shopify variant id, one of the row's `out` entries
 *   expected    — the count the row showed for it, zero or below
 *   quantity    — the count to set, 1–999
 * Header: X-CSRF-Token: <token>
 *
 * The inventory item comes from the product's own raw_data rather than from the
 * request, so a caller can only name a variant of the product it names.  The
 * location is SHOPIFY_LOCATION_ID: the shop has one, and this writes to it.
 *
 * Shopify applies the set only while the location still holds `expected`.  A
 * restock made in Shopify admin after the report loaded is refused rather than
 * overwritten, and comes back 409 with stale:true.
 *
 * Returns {ok:true, quantity} or {ok:false, error, stale?}.  Every attempt that
 * reaches Shopify is logged to logs/inventory-set.log with who asked.
 *
 * Requires the 'adjust_inventory' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/shopify.php';

$user = requireApiPermission($config, 'adjust_inventory');

header('Content-Type: application/json');

// The version this mutation's shape was read from — changeFromQuantity, and the
// @idempotent key Shopify requires from 2026-04.  SHOPIFY_API_VERSION is the
// sync scripts' pin, still 2025-01, and moving it is a change of its own.
const INVENTORY_API_VERSION = '2026-07';

function fail(int $status, string $error, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}

$providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken  = $_SESSION['csrf_token']        ?? '';
if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    fail(403, 'Invalid or missing CSRF token.');
}

// The Shopify call below can take its whole timeout, and PHP holds the session
// lock for the request; without this the operator's other tabs wait on it.
session_write_close();

if ($config['shopify_location_id'] === '' || $config['shopify_access_token'] === '') {
    fail(503, 'Setting stock is not configured on this server.');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$variantId = trim((string) ($_POST['variant_id'] ?? ''));
$expected  = filter_var($_POST['expected'] ?? '', FILTER_VALIDATE_INT);
$quantity  = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);

if ($productId <= 0 || !ctype_digit($variantId) || $expected === false || $expected > 0) {
    fail(400, 'Invalid request.');
}
if ($quantity === false) {
    fail(400, 'Enter a whole number from 1 to 999.');
}

$db   = getDb($config);
$stmt = $db->prepare("
    SELECT p.shopify_product_id,
           json_extract(v.value, '$.inventory_item_id') AS inventory_item_id,
           json_extract(v.value, '$.title')             AS variant_title
    FROM   products p, json_each(p.raw_data, '$.variants') v
    WHERE  p.id = ? AND p.status = 'active' AND p.deleted_at IS NULL AND p.is_bundle = 0
      AND  CAST(json_extract(v.value, '$.id') AS TEXT) = ?
");
$stmt->execute([$productId, $variantId]);
$variant = $stmt->fetch();
if (!$variant || $variant['inventory_item_id'] === null) {
    fail(404, 'That product or size was not found.');
}

$mutation = <<<'GRAPHQL'
mutation SetAvailable($input: InventorySetQuantitiesInput!, $key: String!) {
  inventorySetQuantities(input: $input) @idempotent(key: $key) {
    inventoryAdjustmentGroup { id }
    userErrors { code field message }
  }
}
GRAPHQL;

$result = shopifyGraphql(
    $config['shopify_shop_domain'],
    $config['shopify_access_token'],
    INVENTORY_API_VERSION,
    $mutation,
    [
        'input' => [
            'name'       => 'available',
            'reason'     => 'correction',
            'quantities' => [[
                'inventoryItemId'    => 'gid://shopify/InventoryItem/' . $variant['inventory_item_id'],
                'locationId'         => 'gid://shopify/Location/' . $config['shopify_location_id'],
                'quantity'           => $quantity,
                'changeFromQuantity' => $expected,
            ]],
        ],
        // One key per request: the button is disabled while one is in flight,
        // and a repeat after a lost answer is refused by the compare instead.
        'key' => bin2hex(random_bytes(16)),
    ]
);

$body       = $result['body'];
$payload    = $body['data']['inventorySetQuantities'] ?? null;
$userErrors = $payload['userErrors'] ?? [];
$stale      = in_array('CHANGE_FROM_QUANTITY_STALE', array_column($userErrors, 'code'), true);

// What went wrong, in Shopify's words where it gave any; null when the set landed.
// The status is checked before `errors` because a 401 carries it as a string.
if ($result['status'] === 0) {
    $problem = 'no answer';
} elseif ($body === null || $result['status'] !== 200) {
    $problem = 'HTTP ' . $result['status'];
} elseif (!empty($body['errors'])) {
    $problem = implode('; ', array_column($body['errors'], 'message'));
} elseif ($userErrors !== []) {
    $problem = implode('; ', array_column($userErrors, 'message'));
} elseif (!isset($payload['inventoryAdjustmentGroup'])) {
    $problem = 'no adjustment returned';
} else {
    $problem = null;
}

$actor = $user['username'];
if (originalUsername() !== '') {
    $actor .= ' (masquerade by ' . originalUsername() . ')';
}
file_put_contents(
    dirname(__DIR__, 2) . '/logs/inventory-set.log',
    '[' . date('Y-m-d H:i:s') . "] {$actor} | product:{$variant['shopify_product_id']} variant:{$variantId}"
        . " ({$variant['variant_title']}) | {$expected} -> {$quantity} | "
        . ($problem === null ? 'ok' : ($stale ? 'stale: ' : 'failed: ') . $problem) . "\n",
    FILE_APPEND | LOCK_EX
);

if ($problem === null) {
    echo json_encode(['ok' => true, 'quantity' => $quantity]);
    exit;
}
if ($result['status'] === 0) {
    // A timeout can land after Shopify applied the set, which is why this does
    // not say nothing changed: a repeat is what finds out, and cannot set twice.
    fail(504, 'Shopify did not answer.  Try again — if the first try did land, Shopify will refuse the repeat.');
}
if ($stale) {
    fail(409, 'Shopify no longer has ' . $expected . ' of this — it changed after the report loaded.  Nothing was changed; load the report again.', ['stale' => true]);
}
fail(502, 'Shopify did not set the stock: ' . $problem);
