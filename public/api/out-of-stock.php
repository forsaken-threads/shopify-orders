<?php
declare(strict_types=1);

/**
 * Active products with at least one variant at zero inventory, sold-out first.
 *
 * GET /api/out-of-stock.php
 *
 * Stock is each variant's inventory_quantity in products.raw_data, summed by
 * Shopify across the shop's locations.  It is as current as the last
 * products/update webhook, and Shopify sends one on every sale, so nothing here
 * calls Shopify.
 *
 * Bundles are never listed: they are a way of selling other products.
 *
 * Each product carries its sizes at zero, as ml, and sold_out when every variant
 * is at zero.  A variant whose title is not a size ("Default Title") adds no
 * size but still counts toward sold_out.  `out` lists those same variants one
 * by one — id, size or null, and count — for setting their stock through
 * inventory-set.php, which takes the count back as the one it expects.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

header('Content-Type: application/json');

$db   = getDb($config);
$stmt = $db->query("
    SELECT p.id,
           p.title,
           json_extract(v.value, '$.id')                 AS variant_id,
           COALESCE(p.preferred_brand, TRIM(p.custom_brand), '') AS brand,
           json_extract(v.value, '$.title')              AS variant_title,
           json_extract(v.value, '$.inventory_quantity') AS quantity
    FROM   products p, json_each(p.raw_data, '$.variants') v
    WHERE  p.status = 'active' AND p.deleted_at IS NULL AND p.is_bundle = 0
");

$byProduct = [];
foreach ($stmt as $r) {
    $id = $r['id'];
    $byProduct[$id] ??= ['title' => $r['title'], 'brand' => $r['brand'], 'sizes' => [], 'out' => [], 'in_stock' => 0];

    if ((int) $r['quantity'] > 0) {
        $byProduct[$id]['in_stock']++;
        continue;
    }

    $size = null;
    if (preg_match('/^\s*(\d+)\s*ml\b/i', (string) $r['variant_title'], $m)) {
        // "10 ml" and "10ml" are one size, so the key is the number.
        $size = (int) $m[1];
        $byProduct[$id]['sizes'][$size] = true;
    }
    $byProduct[$id]['out'][] = [
        'variant_id' => (string) $r['variant_id'],
        'size'       => $size,
        'quantity'   => (int) $r['quantity'],
    ];
}

$products = [];
foreach ($byProduct as $id => $p) {
    $soldOut = $p['in_stock'] === 0;
    if (!$soldOut && $p['sizes'] === []) {
        continue;
    }
    $sizes = array_keys($p['sizes']);
    sort($sizes, SORT_NUMERIC);
    $products[] = [
        'id'       => $id,
        'title'    => $p['title'],
        'brand'    => $p['brand'],
        'sizes'    => $sizes,
        'out'      => $p['out'],
        'sold_out' => $soldOut,
    ];
}

// Sold out first, then by brand with the unbranded last, then by title.
usort($products, fn(array $a, array $b): int =>
    ($b['sold_out'] <=> $a['sold_out'])
    ?: (($a['brand'] === '') <=> ($b['brand'] === ''))
    ?: strcasecmp($a['brand'], $b['brand'])
    ?: strcasecmp($a['title'], $b['title'])
);

echo json_encode(['products' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
