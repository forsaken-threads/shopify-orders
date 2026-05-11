<?php
declare(strict_types=1);

/**
 * Employee time-clock page.
 *
 * Shows the current shift state ("not clocked in", "clocked in since
 * 9:14 AM", or "stale — see manager"), a Clock In / Clock Out button,
 * the running total for the current pay-period week, and a list of
 * shifts in that week.
 *
 * Posts to itself with action=clock_in / action=clock_out.  No JSON API
 * — a normal form POST + redirect-after-post is plenty for two clicks
 * a day, and avoids needing CSRF + JS plumbing for what is the single
 * most-used page for Basic Employees.
 *
 * Stale-shift rule: an open punch whose clock_in is on a calendar day
 * before today (in the display timezone) can only be closed out by an
 * admin via /timecards.php — the employee can't be expected to know
 * what time they actually left, and we don't want a 24-hour shift
 * sneaking into payroll.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/timeclock.php';

$me = requirePermission($config, 'clock_in_out');
$db = getDb($config);
$tz = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));

$nowUtc        = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$payWeekStart  = (string) $config['pay_week_start'];
$weekStartUtc  = weekStartUtc($nowUtc, $tz, $payWeekStart);
$weekStartDate = weekStartLocalDate($nowUtc, $tz, $payWeekStart);
$weekApproved  = isWeekApproved($db, (int) $me['id'], $weekStartDate);

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } elseif ($weekApproved) {
        $error = "This week's timecard has been approved and is locked.  Ask your manager to re-open it if you need to clock in or out.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $open   = findOpenPunch($db, (int) $me['id']);

        if ($action === 'clock_in') {
            if ($open !== null) {
                $error = "You're already clocked in.  Use Clock Out first.";
            } else {
                clockIn($db, (int) $me['id']);
                header('Location: /clock.php');
                exit;
            }
        } elseif ($action === 'clock_out') {
            if ($open === null) {
                $error = "You're not currently clocked in.";
            } elseif (isOpenPunchStale($open, $nowUtc, $tz)) {
                $error = "You're still clocked in from a previous day.  Ask your manager to close out that shift first.";
            } else {
                clockOut($db, (int) $me['id']);
                header('Location: /clock.php');
                exit;
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

// ── Page state ───────────────────────────────────────────────────────────────

$openPunch     = findOpenPunch($db, (int) $me['id']);
$openIsStale   = $openPunch !== null && isOpenPunchStale($openPunch, $nowUtc, $tz);

// Punches whose clock_in falls inside the current pay-period week.
$weekEndUtc = $weekStartUtc->modify('+7 days');
$stmt = $db->prepare(
    "SELECT id, clock_in, clock_out, notes
     FROM time_punches
     WHERE user_id = ? AND clock_in >= ? AND clock_in < ?
     ORDER BY clock_in DESC"
);
$stmt->execute([
    (int) $me['id'],
    $weekStartUtc->format('Y-m-d H:i:s'),
    $weekEndUtc->format('Y-m-d H:i:s'),
]);
$weekPunches  = $stmt->fetchAll();
$weekMinutes  = totalMinutes($weekPunches, $nowUtc);

// Format helpers used in the template.
$fmtTime = function (string $utcDt) use ($tz): string {
    return (new DateTimeImmutable($utcDt))->setTimezone($tz)->format('g:i A');
};
$fmtDay = function (string $utcDt) use ($tz, $nowUtc): string {
    $local = (new DateTimeImmutable($utcDt))->setTimezone($tz);
    $today = $nowUtc->setTimezone($tz)->format('Y-m-d');
    $ystr  = $nowUtc->setTimezone($tz)->modify('-1 day')->format('Y-m-d');
    return match ($local->format('Y-m-d')) {
        $today => 'Today',
        $ystr  => 'Yesterday',
        default => $local->format('D, M j'),
    };
};

$payWeekRange = match ($payWeekStart) {
    'mon'   => 'Monday → Sunday',
    'sat'   => 'Saturday → Friday',
    default => 'Sunday → Saturday',
};

$pageTitle  = 'Time clock - Cent Notes';
$activePage = 'clock';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .clock-main {
        flex: 1;
        padding: 2rem;
        max-width: 640px;
        margin: 0 auto;
        width: 100%;
    }

    .clock-main h1 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
    }

    .notice {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        padding: .65rem .9rem;
        border-radius: 6px;
        font-size: .85rem;
        margin-bottom: 1.25rem;
    }

    .error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: .65rem .9rem;
        border-radius: 6px;
        font-size: .85rem;
        margin-bottom: 1.25rem;
    }

    .state-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 2rem;
        text-align: center;
        margin-bottom: 1.5rem;
    }

    .state-card.is-in        { border-top: 4px solid #15803d; }
    .state-card.is-out       { border-top: 4px solid #94a3b8; }
    .state-card.is-stale     { border-top: 4px solid #b45309; background: #fffbeb; }
    .state-card.is-locked    { border-top: 4px solid #b91c1c; background: #fef2f2; }

    .state-status {
        font-size: .8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: #888;
        margin-bottom: .35rem;
    }

    .state-card.is-in     .state-status { color: #15803d; }
    .state-card.is-stale  .state-status { color: #b45309; }
    .state-card.is-locked .state-status { color: #b91c1c; }

    .state-headline {
        font-size: 1.45rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: .35rem;
    }

    .state-detail {
        font-size: .9rem;
        color: #555;
        margin-bottom: 1.5rem;
    }

    .btn-clock {
        width: 100%;
        padding: 1.1rem 1.5rem;
        border: none;
        border-radius: 10px;
        font-size: 1.05rem;
        font-weight: 700;
        font-family: inherit;
        letter-spacing: .03em;
        cursor: pointer;
        transition: background .15s;
    }

    .btn-clock-in  { background: #1a1a2e; color: #fff; }
    .btn-clock-in:hover  { background: #2d2d5e; }
    .btn-clock-out { background: #b45309; color: #fff; }
    .btn-clock-out:hover { background: #92400e; }

    .btn-clock:disabled {
        opacity: .5;
        cursor: not-allowed;
    }

    .summary-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .summary-card .label {
        font-size: .8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #666;
    }

    .summary-card .label span { font-weight: 400; text-transform: none; letter-spacing: 0; color: #999; }

    .summary-card .total {
        font-size: 1.65rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: #1a1a2e;
    }

    .approved-pill {
        display: inline-block;
        margin-left: .5rem;
        padding: .12em .55em;
        border-radius: 99px;
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fca5a5;
        vertical-align: middle;
    }

    .shifts-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
    }

    .shifts-card h2 {
        font-size: .82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #555;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f0f0f0;
    }

    .shift-row {
        display: grid;
        grid-template-columns: 6.5rem 1fr auto;
        gap: 1rem;
        padding: .75rem 1.25rem;
        border-bottom: 1px solid #f5f5f7;
        font-size: .9rem;
        align-items: center;
    }

    .shift-row:last-child { border-bottom: none; }
    .shift-row .day      { font-weight: 600; color: #1a1a2e; }
    .shift-row .times    { color: #444; font-variant-numeric: tabular-nums; }
    .shift-row .duration { color: #666; font-variant-numeric: tabular-nums; }
    .shift-row.open      { background: #f0fdf4; }
    .shift-row.open .duration { color: #15803d; font-weight: 600; }

    .shifts-empty { padding: 1.5rem 1.25rem; text-align: center; color: #999; font-size: .85rem; }

    @media (max-width: 540px) {
        .clock-main { padding: 1rem; }
        .state-card { padding: 1.5rem 1rem; }
        .state-headline { font-size: 1.25rem; }
        .shift-row { grid-template-columns: 5rem 1fr auto; gap: .65rem; padding: .65rem 1rem; font-size: .85rem; }
    }
</style>

<main class="clock-main">
    <h1>Time clock</h1>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php
    // ── State card variants ──────────────────────────────────────────────
    if ($weekApproved):
        // Current pay-period week locked by an admin.  Rare; usually only
        // historical weeks are approved.
    ?>
        <div class="state-card is-locked">
            <div class="state-status">Week locked</div>
            <div class="state-headline">Time clock is locked</div>
            <div class="state-detail">
                This week's timecard has been approved.  Ask your manager to
                re-open it if you need to clock in or out.
            </div>
            <button type="button" class="btn-clock btn-clock-in" disabled>Clock In</button>
        </div>
    <?php elseif ($openIsStale): ?>
        <div class="state-card is-stale">
            <div class="state-status">Needs a manager</div>
            <div class="state-headline">You're still clocked in from <?= h($fmtDay($openPunch['clock_in'])) ?></div>
            <div class="state-detail">
                It looks like you forgot to clock out at the end of that shift.
                Ask your manager to set the correct time on the time-cards page
                before you clock in again.
            </div>
            <button type="button" class="btn-clock btn-clock-out" disabled>Clock Out</button>
        </div>
    <?php elseif ($openPunch !== null): ?>
        <?php
            $inLocal = (new DateTimeImmutable($openPunch['clock_in']))->setTimezone($tz);
            $elapsed = max(0, (int) round(($nowUtc->getTimestamp() - $inLocal->getTimestamp()) / 60));
        ?>
        <div class="state-card is-in">
            <div class="state-status">Clocked in</div>
            <div class="state-headline">Since <?= h($inLocal->format('g:i A')) ?></div>
            <div class="state-detail"><?= h(formatHours($elapsed)) ?> on this shift</div>
            <form method="post" action="clock.php">
                <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clock_out">
                <button type="submit" class="btn-clock btn-clock-out">Clock Out</button>
            </form>
        </div>
    <?php else: ?>
        <div class="state-card is-out">
            <div class="state-status">Not clocked in</div>
            <div class="state-headline">Ready when you are</div>
            <div class="state-detail">Tap Clock In to start your shift.</div>
            <form method="post" action="clock.php">
                <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clock_in">
                <button type="submit" class="btn-clock btn-clock-in">Clock In</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="summary-card">
        <div class="label">
            This week
            <span>(<?= h($payWeekRange) ?>)</span>
            <?php if ($weekApproved): ?><span class="approved-pill">Approved</span><?php endif; ?>
        </div>
        <div class="total"><?= h(formatHours($weekMinutes)) ?></div>
    </div>

    <div class="shifts-card">
        <h2>Shifts this week</h2>
        <?php if (empty($weekPunches)): ?>
            <div class="shifts-empty">No shifts yet this week.</div>
        <?php else: ?>
            <?php foreach ($weekPunches as $p): ?>
                <?php
                    $isOpen   = empty($p['clock_out']);
                    $duration = totalMinutes([$p], $nowUtc);
                ?>
                <div class="shift-row<?= $isOpen ? ' open' : '' ?>">
                    <div class="day"><?= h($fmtDay($p['clock_in'])) ?></div>
                    <div class="times">
                        <?= h($fmtTime($p['clock_in'])) ?> –
                        <?= $isOpen ? '<em>in progress</em>' : h($fmtTime($p['clock_out'])) ?>
                    </div>
                    <div class="duration"><?= h(formatHours($duration)) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
