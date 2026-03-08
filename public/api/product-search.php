<?php
declare(strict_types=1);

/**
 * Product live-search endpoint.
 *
 * GET /api/product-search.php?q=<search_term>
 *
 * Returns a JSON array of up to 20 active products whose normalized_title
 * contains the search term (case-insensitive, accent-insensitive matching).
 *
 * The search needle is normalized in PHP via normalizeTitle() before being
 * passed to a SQL LIKE query against the pre-computed normalized_title column.
 * This avoids loading all product titles into memory and lets SQLite do the
 * filtering efficiently via the idx_products_normalized_title index.
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 *   [{ "shopify_product_id": "...", "title": "...", "vendor": "..." }, ...]
 */

$config = require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../../app/db.php';
require __DIR__ . '/../../app/normalize.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$needle = '%' . normalizeTitle($q) . '%';

$db   = getDb($config);
$stmt = $db->prepare(
    "SELECT shopify_product_id, title, vendor
     FROM   products
     WHERE  status           = 'active'
       AND  deleted_at       IS NULL
       AND  normalized_title LIKE :needle
     ORDER  BY title
     LIMIT  20"
);
$stmt->execute([':needle' => $needle]);

echo json_encode($stmt->fetchAll());
