#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-off backfill: re-apply line items from each order's stored raw_data using
 * current_quantity as the canonical figure.
 *
 * Orders ingested before the current_quantity fix stored line items at their
 * original `quantity`, so units removed by an order edit or refund were still
 * picked, printed, and counted. This script re-reads raw_data (no Shopify calls),
 * recomputes the line items exactly as the webhook now does, and rewrites only
 * the orders whose line items actually differ.
 *
 * Scope: only unshipped orders (status not 'fulfilled' or 'archived'). Already
 * shipped orders are left intact so their picked/packed history isn't rewritten;
 * the fix targets the pick/print hazard, which only affects unshipped orders.
 *
 * Usage:
 *   php scripts/backfill-current-quantity.php            # dry run, prints what would change
 *   php scripts/backfill-current-quantity.php --apply    # commit the changes
 *
 * total_price is left untouched — it mirrors Shopify's reported order total.
 */

$projectRoot = dirname(__DIR__);

$config = require $projectRoot . '/app/config.php';
require_once $projectRoot . '/app/db.php';

$apply = in_array('--apply', $argv ?? [], true);

$db = getDb($config);

// Resolve brands once from the local products table, mirroring the webhook.
$brandByProductId = [];
foreach ($db->query("SELECT shopify_product_id, custom_brand FROM products")->fetchAll() as $row) {
    $brandByProductId[$row['shopify_product_id']] = $row['custom_brand'];
}

$selectLines = $db->prepare(
    "SELECT shopify_line_item_id, quantity FROM order_line_items WHERE order_id = ? ORDER BY shopify_line_item_id"
);
$deleteLines = $db->prepare("DELETE FROM order_line_items WHERE order_id = ?");
$insertLine  = $db->prepare(<<<'SQL'
    INSERT INTO order_line_items
        (order_id, shopify_line_item_id, shopify_product_id, title, variant_title, variant_ml,
         sku, vendor, quantity, price, custom_brand)
    VALUES
        (:order_id, :line_item_id, :shopify_product_id, :title, :variant_title, :variant_ml,
         :sku, :vendor, :quantity, :price, :custom_brand)
SQL);

$scanned = 0;
$changed = 0;
$dropped = 0;   // line-item rows removed (current_quantity 0)
$reduced = 0;   // line-item rows whose quantity dropped
$skippedNoRaw = 0;

$orders = $db->query(
    "SELECT id, order_number, raw_data FROM orders
     WHERE status NOT IN ('fulfilled', 'archived') ORDER BY id"
);

echo $apply ? "Applying current_quantity backfill…\n\n" : "Dry run (no writes). Pass --apply to commit.\n\n";

foreach ($orders as $order) {
    $scanned++;
    $orderId = (int) $order['id'];

    $raw = $order['raw_data'] ?? '';
    if ($raw === '') {
        $skippedNoRaw++;
        continue;
    }

    try {
        $parsed = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $skippedNoRaw++;
        continue;
    }

    $lineItems = $parsed['line_items'] ?? [];
    if (!is_array($lineItems)) {
        continue;
    }

    // Build the desired rows from raw_data using current_quantity, mirroring the
    // webhook: drop any line edited down to zero.
    $desired = [];
    foreach ($lineItems as $item) {
        $quantity = (int) ($item['current_quantity'] ?? $item['quantity'] ?? 1);
        if ($quantity === 0) {
            continue;
        }

        $productId   = (string) ($item['product_id'] ?? '');
        $customBrand = $brandByProductId[$productId] ?? null;
        if ($customBrand === null) {
            foreach ($item['properties'] ?? [] as $prop) {
                if (($prop['name'] ?? '') === 'custom.brand') {
                    $customBrand = ($prop['value'] !== '' && $prop['value'] !== null)
                        ? (string) $prop['value']
                        : null;
                    break;
                }
            }
        }

        $variantTitle = $item['variant_title'] ?? null;
        $variantMl    = null;
        if ($variantTitle !== null && preg_match('/^(\d+)\s*ml$/i', $variantTitle, $m)) {
            $variantMl = (int) $m[1];
        }

        $desired[] = [
            'line_item_id'       => (string) ($item['id'] ?? ''),
            'shopify_product_id' => $productId !== '' ? $productId : null,
            'title'              => (string) ($item['title'] ?? ''),
            'variant_title'      => $variantTitle,
            'variant_ml'         => $variantMl,
            'sku'                => $item['sku']    ?? null,
            'vendor'             => $item['vendor'] ?? null,
            'quantity'           => $quantity,
            'price'              => (float) ($item['price'] ?? 0.0),
            'custom_brand'       => $customBrand,
        ];
    }

    // Compare the desired quantities (keyed by line item id) against what's stored.
    $selectLines->execute([$orderId]);
    $stored = [];
    foreach ($selectLines->fetchAll() as $row) {
        $stored[(string) $row['shopify_line_item_id']] = (int) $row['quantity'];
    }

    $desiredMap = [];
    foreach ($desired as $d) {
        $desiredMap[$d['line_item_id']] = $d['quantity'];
    }

    ksort($stored);
    ksort($desiredMap);
    if ($stored === $desiredMap) {
        continue;
    }

    // Tally what's moving for the report.
    foreach ($stored as $lid => $qty) {
        if (!isset($desiredMap[$lid])) {
            $dropped++;
        } elseif ($desiredMap[$lid] < $qty) {
            $reduced++;
        }
    }

    $changed++;
    echo sprintf("  #%s (order_id %d): %d line(s) -> %d line(s)\n",
        $order['order_number'], $orderId, count($stored), count($desiredMap));

    if (!$apply) {
        continue;
    }

    try {
        $db->beginTransaction();
        $deleteLines->execute([$orderId]);
        foreach ($desired as $d) {
            $insertLine->execute([
                ':order_id'           => $orderId,
                ':line_item_id'       => $d['line_item_id'],
                ':shopify_product_id' => $d['shopify_product_id'],
                ':title'              => $d['title'],
                ':variant_title'      => $d['variant_title'],
                ':variant_ml'         => $d['variant_ml'],
                ':sku'                => $d['sku'],
                ':vendor'             => $d['vendor'],
                ':quantity'           => $d['quantity'],
                ':price'              => $d['price'],
                ':custom_brand'       => $d['custom_brand'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, sprintf("  Error rewriting order %d: %s\n", $orderId, $e->getMessage()));
    }
}

echo "\n";
echo "Scanned orders     : {$scanned}\n";
echo "Orders affected    : {$changed}\n";
echo "  lines dropped    : {$dropped}\n";
echo "  lines reduced    : {$reduced}\n";
echo "Skipped (no raw)   : {$skippedNoRaw}\n";
echo $apply ? "\nDone. Changes committed.\n" : "\nDry run only — re-run with --apply to commit.\n";
