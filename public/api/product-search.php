<?php
declare(strict_types=1);

/**
 * Product live-search endpoint.
 *
 * GET /api/product-search.php?q=<search_term>[&mode=attach&bundle_id=<int>]
 *
 * Returns a JSON array of up to 20 matching products.  Matches are
 * case-insensitive and accent-insensitive via the pre-computed normalized_title
 * column (idx_products_normalized_title).
 *
 * Modes:
 *   (default)  All active, non-deleted products.
 *   attach     Attach-candidate search for a bundle edit modal.
 *              Requires bundle_id.  Excludes bundles (is_bundle = 1),
 *              the bundle itself, and products already attached to it.
 *
 * Response shape:
 *   [{ "id": <int>, "shopify_product_id": "...", "title": "...", "vendor": "..." }, ...]
 *
 * Requires HTTP Basic Auth.
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../../app/db.php';
require __DIR__ . '/../../app/normalize.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$q    = trim((string) ($_GET['q'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$needle = '%' . normalizeTitle($q) . '%';
$db     = getDb($config);

switch ($mode) {
    case 'attach':
        $bundleId = (int) ($_GET['bundle_id'] ?? 0);
        if ($bundleId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'bundle_id is required for mode=attach']);
            exit;
        }
        $stmt = $db->prepare(
            "SELECT id, shopify_product_id, title, vendor
             FROM   products
             WHERE  deleted_at       IS NULL
               AND  is_bundle        = 0
               AND  id               != :bundle_id
               AND  id NOT IN (
                   SELECT component_product_id FROM bundle_components WHERE bundle_product_id = :bundle_id
               )
               AND  normalized_title LIKE :needle
             ORDER  BY title
             LIMIT  20"
        );
        $stmt->execute([':bundle_id' => $bundleId, ':needle' => $needle]);
        break;

    default:
        $stmt = $db->prepare(
            "SELECT id, shopify_product_id, title, vendor
             FROM   products
             WHERE  deleted_at       IS NULL
               AND  normalized_title LIKE :needle
             ORDER  BY title
             LIMIT  20"
        );
        $stmt->execute([':needle' => $needle]);
}

echo json_encode($stmt->fetchAll());
