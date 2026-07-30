#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Daily VIP scoring.
 *
 * Ranks customers on spend and item count over a trailing six months and over
 * all time, awards a star for each measure a customer places in the top 50 of,
 * and records the fifty highest star totals as the day's VIPs.  Ranking rules
 * live in app/vip.php; the aggregates come from app/customers.php.
 *
 * Usage:
 *   php scripts/score-vips.php
 *
 * Runs from cron at 3:00 AM — after the 2:00 paid-orders sync, so it ranks the
 * night's orders rather than yesterday's.
 *
 * A day is scored once.  When rows already exist for today the script reports
 * and stops instead of replacing them: the final tiebreak is a fresh coin flip,
 * so a re-run can seat a different customer in the last slot with the database
 * unchanged.  To re-score a day deliberately, delete its rows first.
 *
 * Exits 0 on success and on a refused re-run, 2 if the write fails.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);

$config = require $projectRoot . '/app/config.php';
require_once $projectRoot . '/app/db.php';
require_once $projectRoot . '/app/customers.php';
require_once $projectRoot . '/app/vip.php';

$db = getDb($config);

// The run's local day, not the UTC instant it started: a job firing at 3:00 AM
// in the display timezone belongs to that date wherever the server clock is.
$today = (new DateTimeImmutable('now', new DateTimeZone($config['display_timezone'])))
    ->format('Y-m-d');

echo "Scoring VIPs for {$today}.\n";

// ── Refuse a second run for the same day ──────────────────────────────────────

$existing = $db->prepare("SELECT COUNT(*) FROM vip_scores WHERE computed_on = :computed_on");
$existing->execute([':computed_on' => $today]);
$already = (int) $existing->fetchColumn();

if ($already > 0) {
    echo "  {$already} rows already recorded for {$today} — nothing to do.\n";
    echo "  To re-score this day, delete its rows first.\n";
    exit(0);
}

// ── Aggregate ─────────────────────────────────────────────────────────────────

// Calendar months, and a naive local timestamp string-compared against
// shopify_created_at, exactly as the Top Customers report's windows work.  The
// comparison is offset-blind and therefore approximate within a few hours of
// the window edge; a VIP list windowed differently from the report it derives
// from would be worse than one that is approximate in the same way.
$sixMonthStart = date('Y-m-d\TH:i:s', strtotime('-6 months'));

$sixMonth = customerAggregates($db, $sixMonthStart);
$allTime  = customerAggregates($db, null);

echo "  Six-month window from {$sixMonthStart}.\n";
echo '  ' . count($allTime) . ' customers all time, ' . count($sixMonth) . " in the window.\n";

// ── Rank ──────────────────────────────────────────────────────────────────────

$vips = rankVips($sixMonth, $allTime);

if ($vips === []) {
    echo "  No customer holds a star — nothing to record.\n";
    exit(0);
}

echo '  Ranked ' . count($vips) . ' VIPs, scores '
    . $vips[0]['score'] . ' down to ' . $vips[count($vips) - 1]['score'] . ".\n";

// ── Write ─────────────────────────────────────────────────────────────────────

$insert = $db->prepare(<<<'SQL'
    INSERT INTO vip_scores
        (computed_on, email_key, vip_rank, score,
         star_6m_spend, star_6m_items, star_at_spend, star_at_items,
         spend_6m, items_6m, spend_at, items_at)
    VALUES
        (:computed_on, :email_key, :vip_rank, :score,
         :star_6m_spend, :star_6m_items, :star_at_spend, :star_at_items,
         :spend_6m, :items_6m, :spend_at, :items_at)
SQL);

try {
    $db->beginTransaction();

    foreach ($vips as $index => $vip) {
        $insert->execute([
            ':computed_on'   => $today,
            ':email_key'     => $vip['email_key'],
            ':vip_rank'      => $index + 1,
            ':score'         => $vip['score'],
            ':star_6m_spend' => $vip['star_6m_spend'],
            ':star_6m_items' => $vip['star_6m_items'],
            ':star_at_spend' => $vip['star_at_spend'],
            ':star_at_items' => $vip['star_at_items'],
            ':spend_6m'      => $vip['spend_6m'],
            ':items_6m'      => $vip['items_6m'],
            ':spend_at'      => $vip['spend_at'],
            ':items_at'      => $vip['items_at'],
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "  Error writing VIP rows: {$e->getMessage()}\n");
    exit(2);
}

echo '  Wrote ' . count($vips) . " rows.\n";
echo "Done.\n";
