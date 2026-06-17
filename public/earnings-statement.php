<?php
declare(strict_types=1);

/**
 * Printable earnings statement for a single employee.
 *
 * An official-looking, print-to-PDF sheet listing every week the employee
 * was actually paid for in a given year, with the hours behind it, the
 * rate in effect, the disbursed amount, and a total.  The amount column is
 * the locked timecard_approvals.amount_paid recorded at mark-paid time, not
 * a re-estimate — so the statement always reconciles with money that left
 * the books.  Hours are recomputed from the (approval-locked) punches.
 *
 * Gated on manage_timecards, with the same hierarchy guard as /timecards.php
 * and /users.php: you can't pull a statement for someone who outranks you.
 *
 * URL shape:
 *   /earnings-statement.php?user_id=42
 *   /earnings-statement.php?user_id=42&year=2026
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/timeclock.php';

$me  = requirePermission($config, 'manage_timecards');
$db  = getDb($config);
$tz  = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));
$pws = (string) $config['pay_week_start'];

$nowUtc      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$todayLocal  = $nowUtc->setTimezone($tz);
$currentYear = (int) $todayLocal->format('Y');

// ── Resolve and authorize the target employee ────────────────────────────────
$userId = (int) ($_GET['user_id'] ?? 0);
$stmt = $db->prepare("SELECT id, username, name, role, preferences FROM users WHERE id = ?");
$stmt->execute([$userId]);
$target = $stmt->fetch() ?: null;

if ($target === null || roleRank((string) $target['role']) > userRoleRank($me)) {
    // Same fail-closed posture as the timecards picker — bounce rather than
    // confirm the user exists / is out of reach.
    header('Location: /timecards.php');
    exit;
}

$employeeName = trim((string) $target['name']) !== ''
    ? (string) $target['name']
    : (string) $target['username'];

// ── Year selection ───────────────────────────────────────────────────────────
$year = (int) ($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) {
    $year = $currentYear;
}

// Years that actually have paid weeks for this employee, for the picker.
$stmt = $db->prepare(
    "SELECT DISTINCT substr(week_start_date, 1, 4) AS y
     FROM timecard_approvals
     WHERE user_id = ? AND paid_at IS NOT NULL
     ORDER BY y DESC"
);
$stmt->execute([(int) $target['id']]);
$paidYears = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
// Always offer the current year so a fresh employee with no paid weeks yet
// still lands on a sensible (empty) statement.
if (!in_array($currentYear, $paidYears, true)) {
    array_unshift($paidYears, $currentYear);
    rsort($paidYears);
}

// ── Pull the paid weeks for the chosen year ──────────────────────────────────
$yearStart = sprintf('%04d-01-01', $year);
$yearEnd   = sprintf('%04d-12-31', $year);

$stmt = $db->prepare(
    "SELECT week_start_date, amount_paid, paid_at
     FROM timecard_approvals
     WHERE user_id = ?
       AND paid_at IS NOT NULL
       AND week_start_date >= ?
       AND week_start_date <= ?
     ORDER BY week_start_date ASC"
);
$stmt->execute([(int) $target['id'], $yearStart, $yearEnd]);
$paidWeeks = $stmt->fetchAll();

// Re-derive hours per week from the punches and tally the totals.  Punches in
// a paid (therefore approved) week are locked, so this matches what was paid.
$punchStmt = $db->prepare(
    "SELECT clock_in, clock_out
     FROM time_punches
     WHERE user_id = ? AND clock_in >= ? AND clock_in < ?"
);

$rows         = [];
$totalMinutes = 0;
$totalAmount  = 0.0;

foreach ($paidWeeks as $w) {
    $weekDate    = (string) $w['week_start_date'];
    $wStartLocal = new DateTimeImmutable($weekDate . ' 00:00:00', $tz);
    $wStartUtc   = $wStartLocal->setTimezone(new DateTimeZone('UTC'));
    $wEndUtc     = $wStartUtc->modify('+7 days');

    $punchStmt->execute([
        (int) $target['id'],
        $wStartUtc->format('Y-m-d H:i:s'),
        $wEndUtc->format('Y-m-d H:i:s'),
    ]);
    $minutes = totalMinutes($punchStmt->fetchAll(), $nowUtc);

    $rate   = effectiveRateFor($db, (int) $target['id'], $weekDate);
    $amount = (float) ($w['amount_paid'] ?? 0);

    $rows[] = [
        'week_ending' => $wStartLocal->modify('+6 days')->format('M j, Y'),
        'minutes'     => $minutes,
        'rate'        => $rate !== null ? (float) $rate['hourly_rate'] : null,
        'amount'      => $amount,
        'paid_on'     => (new DateTimeImmutable((string) $w['paid_at']))->setTimezone($tz)->format('M j, Y'),
    ];

    $totalMinutes += $minutes;
    $totalAmount  += $amount;
}

// ── Period label ─────────────────────────────────────────────────────────────
$periodEndLocal = $year === $currentYear
    ? $todayLocal
    : new DateTimeImmutable(sprintf('%04d-12-31', $year) . ' 00:00:00', $tz);
$periodLabel = (new DateTimeImmutable($yearStart . ' 00:00:00', $tz))->format('M j')
    . ' – ' . $periodEndLocal->format('M j, Y');

$generatedLabel = $todayLocal->format('M j, Y') . ' at ' . $todayLocal->format('g:i A');

$pageTitle  = 'Earnings statement - ' . $employeeName;
$activePage = 'timecards';
$hideNav    = true;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .es-toolbar {
        display: flex;
        align-items: center;
        gap: 1rem;
        max-width: 760px;
        margin: 1.5rem auto 0;
        padding: 0 1rem;
        flex-wrap: wrap;
    }

    .es-toolbar .es-spacer { flex: 1; }

    .es-back { font-size: .85rem; color: #555; text-decoration: none; }
    .es-back:hover { color: #1a1a2e; }

    .es-year {
        padding: .42rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .9rem;
        font-family: inherit;
        background: #fff;
    }

    .es-btn {
        padding: .5rem 1.1rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .es-btn:hover { background: #2d2d5e; }

    .es-sheet {
        background: #fff;
        max-width: 760px;
        margin: 1rem auto 3rem;
        padding: 2.5rem 2.75rem;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
    }

    .es-letterhead {
        text-align: center;
        border-bottom: 2px solid #1a1a2e;
        padding-bottom: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .es-company { font-size: 1.6rem; font-weight: 800; letter-spacing: .02em; }

    .es-doc-title {
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .18em;
        color: #666;
        margin-top: .4rem;
    }

    .es-contact {
        margin-top: .7rem;
        font-size: .78rem;
        color: #555;
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: .35rem .9rem;
    }

    .es-contact a { color: inherit; text-decoration: none; }
    .es-contact a:hover { text-decoration: underline; }
    .es-contact .sep { color: #ccc; }

    .es-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1.1rem 2.5rem;
        margin-bottom: 1.75rem;
    }

    .es-meta .lbl {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #999;
        font-weight: 700;
        margin-bottom: .15rem;
    }

    .es-meta .val { font-size: .9rem; font-weight: 600; }

    .es-table { width: 100%; border-collapse: collapse; }

    .es-table thead { background: transparent; color: #1a1a2e; }

    .es-table th {
        text-align: left;
        padding: .55rem .5rem;
        font-size: .7rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #555;
        border-bottom: 2px solid #1a1a2e;
        white-space: nowrap;
    }

    .es-table th.num, .es-table td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .es-table td {
        padding: .55rem .5rem;
        border-bottom: 1px solid #e5e7eb;
        font-size: .9rem;
        vertical-align: middle;
    }

    .es-table tbody tr:hover td { background: transparent; }

    .es-table tfoot td {
        padding: .75rem .5rem;
        font-weight: 700;
        font-size: .95rem;
        border-top: 2px solid #1a1a2e;
        border-bottom: none;
        background: transparent;
    }

    .es-empty { text-align: center; color: #999; padding: 2.5rem 0; font-size: .9rem; }

    .es-foot-note {
        margin-top: 1.75rem;
        font-size: .72rem;
        color: #aaa;
        text-align: center;
    }

    @media print {
        .navbar, .navbar-env, .es-toolbar { display: none !important; }
        body { background: #fff; }
        .es-sheet {
            box-shadow: none;
            margin: 0 auto;
            max-width: 100%;
            padding: 0;
            border-radius: 0;
        }
        .es-foot-note { color: #888; }
    }

    @media (max-width: 700px) {
        .es-sheet { padding: 1.5rem 1.25rem; margin: 1rem; }
    }
</style>

<div class="es-toolbar">
    <a class="es-back" href="timecards.php?<?= h(http_build_query(['user_id' => (int) $target['id']])) ?>">&lsaquo; Back to time cards</a>
    <div class="es-spacer"></div>
    <?php if (count($paidYears) > 1): ?>
        <select class="es-year" onchange="location.href='earnings-statement.php?user_id=<?= (int) $target['id'] ?>&year=' + this.value">
            <?php foreach ($paidYears as $y): ?>
                <option value="<?= (int) $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <button type="button" class="es-btn" onclick="window.print()">Print / Save PDF</button>
</div>

<div class="es-sheet">
    <div class="es-letterhead">
        <div class="es-company">Decantalize</div>
        <div class="es-doc-title">Earnings Statement</div>
        <div class="es-contact">
            <span><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', (string) $config['company_phone'])) ?>"><?= h((string) $config['company_phone']) ?></a></span>
            <span class="sep">&bull;</span>
            <span><a href="https://<?= h((string) $config['company_website']) ?>"><?= h((string) $config['company_website']) ?></a></span>
            <span class="sep">&bull;</span>
            <span><a href="mailto:<?= h((string) $config['company_email']) ?>"><?= h((string) $config['company_email']) ?></a></span>
        </div>
    </div>

    <div class="es-meta">
        <div>
            <div class="lbl">Employee</div>
            <div class="val"><?= h($employeeName) ?></div>
        </div>
        <div>
            <div class="lbl">Period</div>
            <div class="val"><?= h($periodLabel) ?></div>
        </div>
        <div>
            <div class="lbl">Pay weeks</div>
            <div class="val"><?= count($rows) ?></div>
        </div>
        <div>
            <div class="lbl">Generated</div>
            <div class="val"><?= h($generatedLabel) ?></div>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="es-empty">No paid weeks recorded for <?= (int) $year ?>.</div>
    <?php else: ?>
        <table class="es-table">
            <thead>
                <tr>
                    <th>Week ending</th>
                    <th class="num">Hours</th>
                    <th class="num">Rate</th>
                    <th class="num">Amount</th>
                    <th>Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['week_ending']) ?></td>
                        <td class="num"><?= h(formatHours($r['minutes'])) ?></td>
                        <td class="num"><?= $r['rate'] !== null ? '$' . h(number_format($r['rate'], 2)) : '—' ?></td>
                        <td class="num">$<?= h(number_format($r['amount'], 2)) ?></td>
                        <td><?= h($r['paid_on']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="num"><?= h(formatHours($totalMinutes)) ?></td>
                    <td class="num"></td>
                    <td class="num">$<?= h(number_format($totalAmount, 2)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <div class="es-foot-note">
        Generated by Cent Notes on <?= h($generatedLabel) ?>.
    </div>
</div>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
