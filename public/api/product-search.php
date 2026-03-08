<?php
declare(strict_types=1);

/**
 * Product live-search endpoint.
 *
 * GET /api/product-search.php?q=<search_term>
 *
 * Returns a JSON array of up to 20 active products whose title contains
 * the search term (case-insensitive full-string matching, not prefix-only).
 *
 * Requires HTTP Basic Auth (same credentials as the web UI).
 *
 * Response shape:
 *   [{ "shopify_product_id": "...", "title": "...", "vendor": "..." }, ...]
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../db.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$db   = getDb($config);
$stmt = $db->prepare(
    "SELECT shopify_product_id, title, vendor
     FROM   products
     WHERE  LOWER(title) LIKE LOWER(:pattern)
     AND    status = 'active'
     ORDER BY title
     LIMIT 20"
);
$stmt->execute([':pattern' => '%' . $q . '%']);

echo json_encode($stmt->fetchAll());
