#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-off backfill: populate orders.discount_codes from each order's stored
 * raw_data.
 *
 * Orders ingested before the column existed have it empty, so nothing can tell
 * a coded order from an uncoded one.  This script re-reads raw_data (no Shopify
 * calls), builds the value exactly as both ingest paths now do, and rewrites
 * only the rows whose stored value actually differs.
 *
 * Scope: every order, deliberately.  The sibling backfill-current-quantity.php
 * excludes 'fulfilled' and 'archived' orders because rewriting a shipped
 * order's line items would rewrite its picked/packed history.  Nothing here is
 * a pick/print hazard — a discount code is fixed at checkout and a shipped
 * order's code is as true as a pending one's — and every coded order in the
 * database today is fulfilled or archived, so inheriting that filter would
 * leave the column empty on all of them.
 *
 * Usage:
 *   php scripts/backfill-discount-codes.php            # dry run, prints what would change
 *   php scripts/backfill-discount-codes.php --apply    # commit the changes
 *
 * Expect no VIP10 rows: the code is new and the codes this finds are past
 * promos.  A run that reports zero VIP10 orders has not failed.
 */

$projectRoot = dirname(__DIR__);

$config = require $projectRoot . '/app/config.php';
require_once $projectRoot . '/app/db.php';
require $projectRoot . '/app/discounts.php';

$apply = in_array('--apply', $argv ?? [], true);

$db = getDb($config);

$update = $db->prepare("UPDATE orders SET discount_codes = ? WHERE id = ?");

$scanned      = 0;
$changed      = 0;
$withCodes    = 0;
$multiCode    = 0;
$skippedNoRaw = 0;

$orders = $db->query("SELECT id, order_number, raw_data, discount_codes FROM orders ORDER BY id");

echo $apply ? "Applying discount_codes backfill…\n\n" : "Dry run (no writes). Pass --apply to commit.\n\n";

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

    $desired = discountCodesFor($parsed);

    if ($desired !== '') {
        $withCodes++;
        if (substr_count($desired, ',') > 2) {
            $multiCode++;
        }
    }

    if ($desired === (string) $order['discount_codes']) {
        continue;
    }

    $changed++;
    echo sprintf("  #%s (order_id %d): '%s' -> '%s'\n",
        $order['order_number'], $orderId, $order['discount_codes'], $desired);

    if (!$apply) {
        continue;
    }

    try {
        $update->execute([$desired, $orderId]);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("  Error updating order %d: %s\n", $orderId, $e->getMessage()));
    }
}

echo "\n";
echo "Scanned orders     : {$scanned}\n";
echo "Orders rewritten   : {$changed}\n";
echo "Orders with a code : {$withCodes}\n";
echo "  carrying 2+      : {$multiCode}\n";
echo "Skipped (no raw)   : {$skippedNoRaw}\n";
echo $apply ? "\nDone. Changes committed.\n" : "\nDry run only — re-run with --apply to commit.\n";
