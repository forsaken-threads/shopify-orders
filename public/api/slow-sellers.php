<?php
declare(strict_types=1);

/**
 * Active products that have sold 5ml or less over all time, never-sold first.
 *
 * GET /api/slow-sellers.php[?bundles=1]
 *
 * A product's total is quantity × variant_ml over its own line items, on orders
 * of every status.  Lines with no size are not bottles and count nothing.
 * Bundles themselves are never listed: they are a way of selling other products.
 *
 * bundles=1 also counts each bundle line toward every component of the bundle,
 * at the line's own quantity and size — the rule bottles-sold.php expands by,
 * applied per component rather than summed, and likewise only for bundles marked
 * complete.  An unexpandable bundle can only leave a total low, so it can put a
 * product on this list but never keep one off it, and goes unreported.
 *
 * Requires the 'reports' permission (admin+).
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/permissions.php';
require_once __DIR__ . '/../../app/db.php';

requireApiPermission($config, 'reports');

header('Content-Type: application/json');

const SLOW_SELLER_MAX_ML = 5;

$bundles = ($_GET['bundles'] ?? '0') === '1';

// shopify_created_at is Shopify's own string with its offset, so it is read
// through strftime(), which yields the UTC instant and orders correctly.
$soldLines = "
    SELECT p.id                                           AS product_id,
           oli.quantity * oli.variant_ml                  AS ml,
           strftime('%Y-%m-%d %H:%M:%S', o.shopify_created_at) AS sold_at
    FROM   order_line_items oli
    JOIN   orders   o ON o.id = oli.order_id
    JOIN   products p ON p.shopify_product_id = oli.shopify_product_id
    WHERE  oli.variant_ml IS NOT NULL
";
if ($bundles) {
    $soldLines .= "
    UNION ALL
    SELECT bc.component_product_id,
           oli.quantity * oli.variant_ml,
           strftime('%Y-%m-%d %H:%M:%S', o.shopify_created_at)
    FROM   order_line_items oli
    JOIN   orders            o  ON o.id = oli.order_id
    JOIN   products          b  ON b.shopify_product_id = oli.shopify_product_id AND b.is_bundle = 1
    JOIN   bundle_states     bs ON bs.product_id = b.id AND bs.is_complete = 1
    JOIN   bundle_components bc ON bc.bundle_product_id = b.id
    WHERE  oli.variant_ml IS NOT NULL
    ";
}

$db   = getDb($config);
$stmt = $db->prepare("
    SELECT p.title,
           p.shopify_created_at,
           COALESCE(SUM(s.ml), 0) AS total_ml,
           MAX(s.sold_at)         AS last_sold_utc
    FROM   products p
    LEFT   JOIN ($soldLines) s ON s.product_id = p.id
    WHERE  p.status = 'active' AND p.deleted_at IS NULL AND p.is_bundle = 0
    GROUP  BY p.id
    HAVING total_ml <= :max_ml
    ORDER  BY total_ml, p.title COLLATE NOCASE
");
// execute()'s array binds as text, and SQLite orders every integer below every
// string, so the threshold has to go in as an integer or it filters nothing.
$stmt->bindValue(':max_ml', SLOW_SELLER_MAX_ML, PDO::PARAM_INT);
$stmt->execute();

$tz  = new DateTimeZone($config['display_timezone']);
$utc = new DateTimeZone('UTC');

$products = [];
foreach ($stmt as $r) {
    $products[] = [
        'title'     => $r['title'],
        'ml'        => (int) $r['total_ml'],
        'last_sold' => $r['last_sold_utc'] === null ? null
            : (new DateTimeImmutable($r['last_sold_utc'], $utc))->setTimezone($tz)->format('Y-m-d'),
        'added'     => $r['shopify_created_at'] === null ? null
            : (new DateTimeImmutable($r['shopify_created_at']))->setTimezone($tz)->format('Y-m-d'),
    ];
}

echo json_encode([
    'bundles'  => $bundles,
    'max_ml'   => SLOW_SELLER_MAX_ML,
    'products' => $products,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
