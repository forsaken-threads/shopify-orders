<?php
declare(strict_types=1);

/**
 * VIP ranking.
 *
 * A customer earns a star for placing in the top VIP_STAR_TOP of each of four
 * measures — spend and item count, over a trailing six months and over all
 * time — and the VIP_SLOTS highest star totals become that run's VIPs.
 *
 * app/customers.php computes the aggregates the ranking reads, and
 * scripts/score-vips.php gathers them and writes the result.  Reading that
 * result back lives here too: currentVipScores() and vipBadgeHtml() are how the
 * order pages mark a VIP without re-deriving one.
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

/**
 * The most recent run's VIPs, keyed by lowercased email.
 *
 * A run holds at most VIP_SLOTS rows, so the whole list comes back in one query
 * and callers look up by key — a page of orders costs the same as a single one.
 * Before the nightly job has ever run there is no list, and an empty array is
 * the answer rather than an error.
 */
function currentVipScores(PDO $db): array
{
    $rows = $db->query(
        "SELECT email_key, vip_rank, score,
                star_6m_spend, star_6m_items, star_at_spend, star_at_items
         FROM   vip_scores
         WHERE  computed_on = (SELECT MAX(computed_on) FROM vip_scores)"
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['email_key']] = $row;
    }

    return $out;
}

// The VIP report card draws the same star from its own copy of this, sized for
// a table column rather than for a badge.
const VIP_STAR_SVG = '<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 '
    . '17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

/**
 * The badge for one customer, or '' for anyone not on the list — so a caller
 * can pass a currentVipScores() lookup straight through.
 *
 * Nothing customer-derived reaches the markup: every label is a literal and the
 * two figures are cast to int, so there is nothing here to escape.
 */
function vipBadgeHtml(?array $score): string
{
    if ($score === null) {
        return '';
    }

    $measures = [
        'star_6m_spend' => 'spend, last six months',
        'star_6m_items' => 'items, last six months',
        'star_at_spend' => 'spend, all time',
        'star_at_items' => 'items, all time',
    ];

    $stars = '';
    foreach (VIP_STAR_COLUMNS as $column) {
        $held  = (int) $score[$column] === 1;
        $title = 'Top ' . VIP_STAR_TOP . ' by ' . $measures[$column] . ($held ? '' : ' — not held');

        $stars .= '<span class="vip-badge-star ' . ($held ? 'on' : 'off') . '" title="' . $title . '">'
            . VIP_STAR_SVG . '</span>';
    }

    return '<span class="vip-badge" title="VIP #' . (int) $score['vip_rank'] . ' — '
        . (int) $score['score'] . ' of ' . count(VIP_STAR_COLUMNS) . ' stars">'
        . '<span class="vip-badge-label">VIP</span>'
        . '<span class="vip-badge-stars">' . $stars . '</span></span>';
}

// The code she hands VIPs on a postcard.  Matched space-insensitively, so the
// same code reaches here whether it was entered as VIP10 or VIP 10 at checkout.
const VIP_DISCOUNT_CODE = 'VIP10';

/**
 * How many orders each customer has placed with VIP_DISCOUNT_CODE, keyed by
 * lowercased email.  Customers who have never used it are absent, so a caller
 * can pass a lookup straight through to vipCodeUsedHtml().
 *
 * One query for the whole page, like currentVipScores(): only a handful of
 * customers ever redeem one code, so the whole set comes back at once.
 */
function vipCodeRedemptions(PDO $db): array
{
    // The commas discountCodesFor() wraps around the stored codes are what keep
    // this from also matching VIP100.
    $stmt = $db->prepare(
        "SELECT   LOWER(customer_email) AS email_key, COUNT(*) AS orders
         FROM     orders
         WHERE    customer_email != ''
           AND    REPLACE(discount_codes, ' ', '') LIKE :pattern
         GROUP BY email_key"
    );
    $stmt->execute([':pattern' => '%,' . VIP_DISCOUNT_CODE . ',%']);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['email_key']] = (int) $row['orders'];
    }

    return $out;
}

/**
 * The spent-code marker for one customer, or '' for anyone who has not redeemed
 * it — so a caller can pass a vipCodeRedemptions() lookup straight through.
 *
 * As with vipBadgeHtml(), nothing customer-derived reaches the markup: the code
 * is a constant and the count is cast to int.
 */
function vipCodeUsedHtml(?int $orders): string
{
    if ($orders === null || $orders < 1) {
        return '';
    }

    $title = $orders === 1
        ? 'Already ordered with ' . VIP_DISCOUNT_CODE . ' — do not send the code again'
        : 'Already ordered with ' . VIP_DISCOUNT_CODE . ' on ' . (int) $orders
          . ' orders — do not send the code again';

    return '<span class="vip-code-used" title="' . $title . '">'
        . '<span class="vip-code-used-code">' . VIP_DISCOUNT_CODE . '</span> used</span>';
}
