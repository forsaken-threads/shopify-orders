<?php
declare(strict_types=1);

/**
 * VIP ranking.
 *
 * A customer earns a star for placing in the top VIP_STAR_TOP of each of four
 * measures — spend and item count, over a trailing six months and over all
 * time — and the VIP_SLOTS highest star totals become that run's VIPs.
 *
 * This file only ranks.  app/customers.php computes the aggregates it reads,
 * and scripts/score-vips.php gathers them and writes the result.
 */

// Customers awarded a star per measure, and VIP slots filled from the starred
// population.  Well over fifty customers hold at least one star, so the slots
// always fall inside that population and a zero-star customer is never a VIP.
const VIP_STAR_TOP = 50;
const VIP_SLOTS    = 50;

// The four measures in fixed priority order.  The tiebreak scans this list
// left to right, which is what makes a trailing-six-month star outrank an
// all-time one and a spend star outrank an items one.
const VIP_STAR_COLUMNS = ['star_6m_spend', 'star_6m_items', 'star_at_spend', 'star_at_items'];

/**
 * The email keys holding the $topN highest values of $field, as a lookup set.
 *
 * A tie at the cut is broken by email key, so the same orders always award the
 * same stars.  Only the VIP boundary is deliberately random, and that happens
 * in rankVips().
 */
function topByMeasure(array $aggregates, string $field, int $topN): array
{
    $rows = array_values($aggregates);
    usort($rows, fn($a, $b) => [$b[$field], $a['email_key']] <=> [$a[$field], $b['email_key']]);

    $set = [];
    foreach (array_slice($rows, 0, $topN) as $row) {
        $set[$row['email_key']] = true;
    }

    return $set;
}

/**
 * This run's VIP list, highest first, at most VIP_SLOTS long.  $sixMonth and
 * $allTime are customerAggregates() results for the trailing six-month window
 * and for all time; each returned row carries a customer's stars and the four
 * figures behind them, and its position in the array is its rank.
 *
 * Ordering, applied in this order: score descending; then the star vector
 * compared position by position in VIP_STAR_COLUMNS order, the customer holding
 * the first differing star winning; then spend per item, descending; then a
 * coin flip.
 *
 * Two customers reaching the spend-per-item comparison have survived a
 * position-by-position comparison of their vectors, so their vectors are
 * identical and the window that figure covers is never ambiguous.
 */
function rankVips(array $sixMonth, array $allTime): array
{
    $stars = [
        'star_6m_spend' => topByMeasure($sixMonth, 'spent', VIP_STAR_TOP),
        'star_6m_items' => topByMeasure($sixMonth, 'items', VIP_STAR_TOP),
        'star_at_spend' => topByMeasure($allTime,  'spent', VIP_STAR_TOP),
        'star_at_items' => topByMeasure($allTime,  'items', VIP_STAR_TOP),
    ];

    // All-time is the population: a customer with orders inside the six-month
    // window necessarily has orders overall, so nobody starred is missing here.
    $ranked = [];
    foreach ($allTime as $emailKey => $at) {
        $sm  = $sixMonth[$emailKey] ?? [];
        $row = ['email_key' => $emailKey, 'score' => 0];

        foreach (VIP_STAR_COLUMNS as $column) {
            $row[$column] = isset($stars[$column][$emailKey]) ? 1 : 0;
            $row['score'] += $row[$column];
        }

        if ($row['score'] === 0) {
            continue;
        }

        $row['spend_6m'] = $sm['spent'] ?? 0.0;
        $row['items_6m'] = $sm['items'] ?? 0;
        $row['spend_at'] = $at['spent'];
        $row['items_at'] = $at['items'];

        // A six-month star can only have come from $sixMonth, so $sm is present
        // on exactly the branch that reads it.
        $row['per_item'] = ($row['star_6m_spend'] === 1 || $row['star_6m_items'] === 1)
            ? $sm['per_item']
            : $at['per_item'];

        // Drawn once per customer per run rather than inside the comparator: a
        // comparator that answered differently for the same pair would leave
        // usort's result undefined.  Re-rolled every run, by decision.
        $row['coin'] = random_int(0, PHP_INT_MAX);

        $ranked[] = $row;
    }

    usort($ranked, function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        foreach (VIP_STAR_COLUMNS as $column) {
            if ($a[$column] !== $b[$column]) {
                return $b[$column] <=> $a[$column];
            }
        }
        if ($a['per_item'] !== $b['per_item']) {
            return $b['per_item'] <=> $a['per_item'];
        }
        return $a['coin'] <=> $b['coin'];
    });

    return array_slice($ranked, 0, VIP_SLOTS);
}
