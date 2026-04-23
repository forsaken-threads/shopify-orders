<?php
declare(strict_types=1);

/**
 * List components of a bundle.
 *
 * GET /api/bundle-components.php?id=<bundle_product_id>[&include_variants=1]
 *
 * Returns the bundle's metadata plus the list of currently attached component
 * products, joined through bundle_components.  When include_variants=1 is
 * passed, each component also includes a `ml_variants` array extracted from
 * its raw Shopify product JSON — used by the bundle print modal to populate
 * the per-row ml selector.
 *
 * Response shape:
 *   {
 *     "bundle": { "id", "shopify_product_id", "title", "is_complete",
 *                 "preferred_title", "preferred_brand" },
 *     "components": [
 *       {
 *         "id", "shopify_product_id", "title", "vendor",
 *         "custom_brand", "preferred_title", "preferred_brand",
 *         "ml_variants": [1, 5, 10]     // only when include_variants=1
 *       }
 *     ]
 *   }
 *
 * bundle.preferred_title / preferred_brand carry the two lines the user saved
 * for the bundle-name print label (printed at Bundle size by print-label.py).
 *
 * Requires HTTP Basic Auth.
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../../app/db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$bundleId = (int) ($_GET['id'] ?? 0);
if ($bundleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid bundle id.']);
    exit;
}

$includeVariants = !empty($_GET['include_variants']);

$db = getDb($config);

$bundleStmt = $db->prepare(
    "SELECT p.id, p.shopify_product_id, p.title,
            p.preferred_title, p.preferred_brand,
            COALESCE(s.is_complete, 0) AS is_complete
     FROM   products p
     LEFT   JOIN bundle_states s ON s.product_id = p.id
     WHERE  p.id = :id AND p.is_bundle = 1 AND p.deleted_at IS NULL"
);
$bundleStmt->execute([':id' => $bundleId]);
$bundle = $bundleStmt->fetch();

if (!$bundle) {
    http_response_code(404);
    echo json_encode(['error' => 'Bundle not found.']);
    exit;
}

$componentStmt = $db->prepare(
    "SELECT p.id, p.shopify_product_id, p.title, p.vendor,
            p.custom_brand, p.preferred_title, p.preferred_brand" .
            ($includeVariants ? ", p.raw_data" : "") . "
     FROM   bundle_components bc
     JOIN   products          p ON p.id = bc.component_product_id
     WHERE  bc.bundle_product_id = :id
       AND  p.deleted_at IS NULL
     ORDER  BY p.title"
);
$componentStmt->execute([':id' => $bundleId]);
$components = $componentStmt->fetchAll();

if ($includeVariants) {
    foreach ($components as &$c) {
        $c['ml_variants'] = extractMlVariants((string) ($c['raw_data'] ?? ''));
        unset($c['raw_data']);
    }
    unset($c);
}

echo json_encode([
    'bundle'     => [
        'id'                 => (int) $bundle['id'],
        'shopify_product_id' => $bundle['shopify_product_id'],
        'title'              => $bundle['title'],
        'is_complete'        => (int) $bundle['is_complete'] === 1,
        'preferred_title'    => $bundle['preferred_title'],
        'preferred_brand'    => $bundle['preferred_brand'],
    ],
    'components' => $components,
]);

/**
 * Pull the distinct ml sizes out of a product's Shopify raw JSON.
 * Matches variant titles or option values of the form "5 ml", "10ml", etc.
 */
function extractMlVariants(string $rawJson): array
{
    if ($rawJson === '') {
        return [];
    }
    try {
        $data = json_decode($rawJson, associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return [];
    }
    $found = [];
    foreach ($data['variants'] ?? [] as $v) {
        foreach (['title', 'option1', 'option2', 'option3'] as $field) {
            $val = $v[$field] ?? null;
            if (is_string($val) && preg_match('/^\s*(\d+)\s*ml\b/i', $val, $m)) {
                $found[(int) $m[1]] = true;
                break;
            }
        }
    }
    $sizes = array_keys($found);
    sort($sizes, SORT_NUMERIC);
    return $sizes;
}
