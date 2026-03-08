<?php
declare(strict_types=1);

/**
 * Product live-search endpoint.
 *
 * GET /api/product-search.php?q=<search_term>
 *
 * Returns a JSON array of up to 20 active products whose title contains
 * the search term (case-insensitive, accent-insensitive full-string matching).
 *
 * Matching is done in PHP after fetching all active product titles so that
 * accent normalization (é→e, ô→o, ā→a, etc.) works correctly — SQLite's
 * built-in LOWER() and LIKE only handle ASCII characters.
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

/**
 * Strip diacritics and fold to lowercase ASCII for accent-insensitive matching.
 * e.g. "Le Falconé Jawhara" → "le falcone jawhara"
 */
function normalizeForSearch(string $s): string
{
    // Decompose characters into base + combining marks, then drop the marks.
    $normalized = normalizer_normalize($s, Normalizer::FORM_D);
    if ($normalized === false) {
        return mb_strtolower($s);
    }
    $s = $normalized;
    // Remove combining diacritical marks (U+0300–U+036F).
    $s = preg_replace('/[\x{0300}-\x{036F}]/u', '', $s) ?? $s;
    return mb_strtolower($s);
}

$needle = normalizeForSearch($q);

$db   = getDb($config);
$stmt = $db->prepare(
    "SELECT shopify_product_id, title, vendor
     FROM   products
     WHERE  status = 'active'
     ORDER BY title"
);
$stmt->execute();

$results = [];
foreach ($stmt->fetchAll() as $row) {
    if (mb_strpos(normalizeForSearch($row['title']), $needle) !== false) {
        $results[] = $row;
        if (count($results) === 20) {
            break;
        }
    }
}

echo json_encode($results);
