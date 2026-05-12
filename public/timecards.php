<?php
declare(strict_types=1);

/**
 * Admin time-card management.
 *
 * Gated on the manage_timecards permission (admin + root).  Lets the
 * operator pick an employee and a pay-period week, view all the shifts
 * in that week, edit / add / delete punches, and approve the week.
 * An approved week is locked: no edits, no new punches, no deletes
 * until the admin re-opens it.
 *
 * Hierarchy: the same rule as /users.php — an admin can't touch a
 * user whose role outranks their own.
 *
 * URL shape (so back/forward and bookmarks behave):
 *   /timecards.php
 *   /timecards.php?user_id=42
 *   /timecards.php?user_id=42&week=2026-05-09
 *
 * Times are stored UTC in time_punches; this page converts to and from
 * the display timezone for all input and display.  See app/timeclock.php
 * for the helpers.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/timeclock.php';

$me     = requirePermission($config, 'manage_timecards');
$db     = getDb($config);
$myRank = userRoleRank($me);
$tz     = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));
$pws    = (string) $config['pay_week_start'];
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$notice = '';
$error  = '';

// ── Resolve the "current view" (user + week) from POST or GET ────────────────
// On POST the selection comes via hidden inputs so PRG can redirect back.
$source       = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$selectedUser = (int) ($source['user_id'] ?? 0);
$weekParam    = trim((string) ($source['week'] ?? ''));

// Validate the week parameter; default to the current pay week.
try {
    $weekStartLocal = $weekParam !== ''
        ? new DateTimeImmutable($weekParam . ' 00:00:00', $tz)
        : weekStartUtc($nowUtc, $tz, $pws)->setTimezone($tz);
} catch (\Exception) {
    $weekStartLocal = weekStartUtc($nowUtc, $tz, $pws)->setTimezone($tz);
}
// Snap to the actual week boundary in case the param was mid-week.
$weekStartUtc  = weekStartUtc($weekStartLocal->setTimezone(new DateTimeZone('UTC')), $tz, $pws);
$weekStartDate = $weekStartUtc->setTimezone($tz)->format('Y-m-d');
$weekEndUtc    = $weekStartUtc->modify('+7 days');

// ── POST handling ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // Every action needs a target user.  Resolve and hierarchy-check it.
        // preferences is included so mark_paid can read the paid_hourly flag.
        $targetId = (int) ($_POST['target_user_id'] ?? $_POST['user_id'] ?? 0);
        $target   = null;
        if ($targetId > 0) {
            $stmt = $db->prepare("SELECT id, username, name, role, preferences FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch() ?: null;
        }
        if ($target === null) {
            $error = 'Invalid user.';
        } elseif (roleRank((string) $target['role']) > $myRank) {
            $error = "You can't manage a user whose role is higher than yours.";
        } elseif ($action === 'approve') {
            $weekDate = (string) ($_POST['week_start_date'] ?? '');
            if ($weekDate === '') {
                $error = 'Missing week.';
            } else {
                // Idempotent via UNIQUE(user_id, week_start_date).  IGNORE
                // is safer than checking-then-inserting under a race.
                $db->prepare(
                    "INSERT OR IGNORE INTO timecard_approvals
                       (user_id, week_start_date, approved_by, approved_at)
                       VALUES (?, ?, ?, datetime('now'))"
                )->execute([(int) $target['id'], $weekDate, (int) $me['id']]);
                $notice = 'Week approved.';
            }
        } elseif ($action === 'unapprove') {
            $weekDate = (string) ($_POST['week_start_date'] ?? '');
            if ($weekDate === '') {
                $error = 'Missing week.';
            } else {
                // Paid weeks are terminal — can't be unapproved.  This guard
                // is the only barrier; the UI hides the button when paid.
                $stmt = $db->prepare(
                    "SELECT paid_at FROM timecard_approvals WHERE user_id = ? AND week_start_date = ?"
                );
                $stmt->execute([(int) $target['id'], $weekDate]);
                $row = $stmt->fetch();
                if ($row && !empty($row['paid_at'])) {
                    $error = "Can't re-open — this week is already marked paid.";
                } else {
                    $db->prepare(
                        "DELETE FROM timecard_approvals WHERE user_id = ? AND week_start_date = ?"
                    )->execute([(int) $target['id'], $weekDate]);
                    $notice = 'Week re-opened.';
                }
            }
        } elseif ($action === 'mark_paid') {
            $weekDate = (string) ($_POST['week_start_date'] ?? '');
            if ($weekDate === '') {
                $error = 'Missing week.';
            } elseif (!isUserPaidHourly($target)) {
                $error = "That user isn't flagged as paid hourly.";
            } else {
                $stmt = $db->prepare(
                    "SELECT paid_at FROM timecard_approvals
                     WHERE user_id = ? AND week_start_date = ?"
                );
                $stmt->execute([(int) $target['id'], $weekDate]);
                $appr = $stmt->fetch();

                if (!$appr) {
                    $error = "Week isn't approved yet.";
                } elseif (!empty($appr['paid_at'])) {
                    $error = 'Week was already marked paid.';
                } else {
                    $rate = effectiveRateFor($db, (int) $target['id'], $weekDate);
                    if ($rate === null) {
                        $error = "No hourly rate is on file for the pay week of {$weekDate}.  Set one on the Users page first.";
                    } else {
                        // Re-derive the week bounds from the posted week_start_date so
                        // we're not relying on any earlier $weekStartUtc/$weekEndUtc
                        // computation drifting from the action target.
                        $wStartUtc = (new DateTimeImmutable($weekDate . ' 00:00:00', $tz))
                            ->setTimezone(new DateTimeZone('UTC'));
                        $wEndUtc   = $wStartUtc->modify('+7 days');

                        $stmtP = $db->prepare(
                            "SELECT clock_in, clock_out
                             FROM time_punches
                             WHERE user_id = ? AND clock_in >= ? AND clock_in < ?"
                        );
                        $stmtP->execute([
                            (int) $target['id'],
                            $wStartUtc->format('Y-m-d H:i:s'),
                            $wEndUtc->format('Y-m-d H:i:s'),
                        ]);
                        $weekPunches = $stmtP->fetchAll();
                        $minutes = totalMinutes($weekPunches, $nowUtc);
                        $amount  = round($minutes / 60.0 * (float) $rate['hourly_rate'], 2);

                        $db->prepare(
                            "UPDATE timecard_approvals
                                SET paid_at = datetime('now'), paid_by = ?, amount_paid = ?
                              WHERE user_id = ? AND week_start_date = ?"
                        )->execute([
                            (int) $me['id'],
                            $amount,
                            (int) $target['id'],
                            $weekDate,
                        ]);
                        $notice = 'Marked paid.';
                    }
                }
            }
        } elseif (in_array($action, ['add_punch', 'update_punch', 'delete_punch'], true)) {
            // Punch-editing actions all share the same approved-week guard.
            $editError = null;

            $punchId   = (int) ($_POST['punch_id'] ?? 0);
            $newInUtc  = localInputToUtc((string) ($_POST['clock_in']  ?? ''), $tz);
            $newOutUtc = localInputToUtc((string) ($_POST['clock_out'] ?? ''), $tz);
            $notes     = trim((string) ($_POST['notes'] ?? ''));
            if ($notes === '') {
                $notes = null;
            }

            // For update / delete, we need the existing row to know its
            // current week and verify ownership.
            $existing = null;
            if ($action !== 'add_punch') {
                $stmt = $db->prepare("SELECT id, user_id, clock_in, clock_out FROM time_punches WHERE id = ?");
                $stmt->execute([$punchId]);
                $existing = $stmt->fetch() ?: null;
                if ($existing === null) {
                    $editError = 'Punch not found.';
                } elseif ((int) $existing['user_id'] !== (int) $target['id']) {
                    $editError = 'Punch does not belong to that user.';
                }
            }

            // Reject if any of the touched weeks is approved — old week
            // (where the punch is now) for update / delete, new week
            // (where the punch would land) for add / update.
            if ($editError === null) {
                $weeksToCheck = [];
                if ($existing !== null) {
                    $weeksToCheck[] = weekStartDateFor((string) $existing['clock_in'], $tz, $pws);
                }
                if ($newInUtc !== null) {
                    $weeksToCheck[] = weekStartDateFor($newInUtc, $tz, $pws);
                }
                foreach (array_unique($weeksToCheck) as $wk) {
                    if (isWeekApproved($db, (int) $target['id'], $wk)) {
                        $editError = "Can't edit — the week of {$wk} is approved.  Re-open it first.";
                        break;
                    }
                }
            }

            if ($editError === null) {
                if ($action === 'delete_punch') {
                    $db->prepare("DELETE FROM time_punches WHERE id = ?")
                       ->execute([$punchId]);
                    $notice = 'Shift deleted.';
                } else {
                    // Validate clock_in / clock_out.
                    if ($newInUtc === null) {
                        $editError = 'Clock in time is required.';
                    } elseif ($newOutUtc !== null && $newOutUtc < $newInUtc) {
                        $editError = 'Clock out must be after clock in.';
                    }

                    // One-open-shift rule: refuse to add or save an open
                    // punch when the user already has a different open
                    // punch.
                    if ($editError === null && $newOutUtc === null) {
                        $sql  = "SELECT id FROM time_punches
                                 WHERE user_id = ? AND clock_out IS NULL";
                        $args = [(int) $target['id']];
                        if ($action === 'update_punch') {
                            $sql  .= " AND id != ?";
                            $args[] = $punchId;
                        }
                        $stmt = $db->prepare($sql);
                        $stmt->execute($args);
                        if ($stmt->fetchColumn()) {
                            $editError = 'That user already has an open shift.  Close it before adding another.';
                        }
                    }
                }

                if ($editError === null && $action === 'add_punch') {
                    $db->prepare(
                        "INSERT INTO time_punches
                           (user_id, clock_in, clock_out, notes, edited_by, edited_at)
                         VALUES (?, ?, ?, ?, ?, datetime('now'))"
                    )->execute([(int) $target['id'], $newInUtc, $newOutUtc, $notes, (int) $me['id']]);
                    $notice = 'Shift added.';
                } elseif ($editError === null && $action === 'update_punch') {
                    $db->prepare(
                        "UPDATE time_punches
                            SET clock_in = ?, clock_out = ?, notes = ?,
                                edited_by = ?, edited_at = datetime('now')
                          WHERE id = ?"
                    )->execute([$newInUtc, $newOutUtc, $notes, (int) $me['id'], $punchId]);
                    $notice = 'Shift updated.';
                }
            }

            if ($editError !== null) {
                $error = $editError;
            }
        } else {
            $error = 'Unknown action.';
        }

        // PRG: on success, redirect back to the view the admin was on.
        if ($error === '' && $notice !== '') {
            $_SESSION['flash_notice'] = $notice;
            $qs = http_build_query([
                'user_id' => (int) $target['id'],
                'week'    => $weekStartDate,
            ]);
            header('Location: /timecards.php?' . $qs);
            exit;
        }
    }
}

// Pick up a flash notice from a prior PRG redirect.
if ($notice === '' && !empty($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

// ── Page state ───────────────────────────────────────────────────────────────

// Every user with the clock_in_out permission is a candidate, which today
// is every active user; show inactive users too so historical cards
// remain reachable.  preferences pulled here too so isUserPaidHourly()
// can decide whether to surface the pay / mark-paid UI.
$allUsers = $db->query(
    "SELECT id, username, name, role, is_active, preferences
     FROM users
     ORDER BY is_active DESC, username COLLATE NOCASE ASC"
)->fetchAll();

// Filter the picker to users at-or-below my rank — same hierarchy as the
// POST guard so the UI never offers an action that would be rejected.
$pickableUsers = array_values(array_filter($allUsers, fn ($u) => roleRank((string) $u['role']) <= $myRank));

$target           = null;
$punches          = [];
$approval         = null;
$weekTotal        = 0;
$targetPaidHourly = false;
$weekRate         = null;       // hourly_rates row covering this week (or null)
$weekAmount       = null;       // computed week pay (float) when rate available

if ($selectedUser > 0) {
    foreach ($pickableUsers as $u) {
        if ((int) $u['id'] === $selectedUser) {
            $target = $u;
            break;
        }
    }
}

if ($target !== null) {
    $stmt = $db->prepare(
        "SELECT id, clock_in, clock_out, notes, edited_by, edited_at
         FROM time_punches
         WHERE user_id = ? AND clock_in >= ? AND clock_in < ?
         ORDER BY clock_in ASC"
    );
    $stmt->execute([
        (int) $target['id'],
        $weekStartUtc->format('Y-m-d H:i:s'),
        $weekEndUtc->format('Y-m-d H:i:s'),
    ]);
    $punches   = $stmt->fetchAll();
    $weekTotal = totalMinutes($punches, $nowUtc);

    $stmt = $db->prepare(
        "SELECT a.approved_at, a.approved_by, a.paid_at, a.paid_by, a.amount_paid,
                au.username AS approver_username, au.name AS approver_name,
                pu.username AS payer_username,    pu.name AS payer_name
         FROM timecard_approvals a
         LEFT JOIN users au ON au.id = a.approved_by
         LEFT JOIN users pu ON pu.id = a.paid_by
         WHERE a.user_id = ? AND a.week_start_date = ?"
    );
    $stmt->execute([(int) $target['id'], $weekStartDate]);
    $approval = $stmt->fetch() ?: null;

    $targetPaidHourly = isUserPaidHourly($target);
    if ($targetPaidHourly) {
        $weekRate = effectiveRateFor($db, (int) $target['id'], $weekStartDate);
        if ($weekRate !== null) {
            $weekAmount = round($weekTotal / 60.0 * (float) $weekRate['hourly_rate'], 2);
        }
    }
}

// Prev / next week (in local time, then re-snapped through weekStartUtc).
$prevWeekDate = $weekStartUtc->modify('-7 days')->setTimezone($tz)->format('Y-m-d');
$nextWeekDate = $weekStartUtc->modify('+7 days')->setTimezone($tz)->format('Y-m-d');
$currentWeekDate = weekStartUtc($nowUtc, $tz, $pws)->setTimezone($tz)->format('Y-m-d');

// Display helpers.
$fmtDay = function (string $utcDt) use ($tz): string {
    return (new DateTimeImmutable($utcDt))->setTimezone($tz)->format('D, M j');
};
$fmtTime = function (string $utcDt) use ($tz): string {
    return (new DateTimeImmutable($utcDt))->setTimezone($tz)->format('g:i A');
};
$fmtDateRange = function (DateTimeImmutable $startUtc) use ($tz): string {
    $start = $startUtc->setTimezone($tz);
    $end   = $startUtc->modify('+6 days')->setTimezone($tz);
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('M j') . ' – ' . $end->format('j, Y');
    }
    return $start->format('M j') . ' – ' . $end->format('M j, Y');
};

$pageTitle  = 'Time cards - Cent Notes';
$activePage = 'timecards';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .tc-main {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    .tc-main h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 1.25rem; }

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

    .picker-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .picker-card .group { display: flex; align-items: center; gap: .5rem; }
    .picker-card .group label {
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    .picker-card select {
        padding: .42rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .9rem;
        font-family: inherit;
        background: #fff;
    }

    .picker-card .week-nav {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
    }

    .week-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 .55rem;
        border-radius: 6px;
        font-size: .85rem;
        font-weight: 600;
        text-decoration: none;
        color: #1a1a2e;
        background: #f0f2f5;
        border: 1px solid transparent;
    }

    .week-link:hover { background: #e2e6ec; }

    .week-range {
        font-weight: 700;
        font-size: .92rem;
        color: #1a1a2e;
        padding: 0 .35rem;
        font-variant-numeric: tabular-nums;
    }

    .today-link {
        font-size: .8rem;
        color: #555;
        text-decoration: none;
        padding: .15rem .55rem;
        border-radius: 5px;
    }

    .today-link:hover { background: #f0f2f5; color: #1a1a2e; }

    .picker-card .picker-spacer { flex: 1; }

    .empty-pick {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 3rem 1.5rem;
        text-align: center;
        color: #888;
    }

    .approval-bar {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }

    .approval-bar.is-approved { background: #fff7ed; border: 1px solid #fdba74; }
    .approval-bar.is-paid     { background: #ecfdf5; border: 1px solid #6ee7b7; }

    .approval-bar .total {
        font-size: 1.4rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .approval-bar .total.warn { color: #b45309; }

    .approval-bar .total-label {
        font-size: .8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #666;
    }

    .approval-bar .rate-hint {
        font-size: .72rem;
        color: #777;
        margin-top: .15rem;
    }

    .approval-bar .rate-hint.warn { color: #b45309; }

    .approval-state {
        font-size: .85rem;
        color: #444;
        flex: 1;
        min-width: 0;
    }

    .approval-state.locked { color: #9a3412; }
    .approval-state.paid   { color: #047857; }

    .approval-actions { display: inline-flex; gap: .5rem; flex-wrap: wrap; }

    .btn-primary {
        padding: .5rem 1.1rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-primary:hover { background: #2d2d5e; }

    .btn-danger {
        padding: .5rem 1.1rem;
        background: #b45309;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-danger:hover { background: #92400e; }

    .punches-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
    }

    .punches-table { width: 100%; border-collapse: collapse; }

    .punches-table thead { background: #1a1a2e; color: #fff; }
    .punches-table th {
        padding: .75rem 1rem;
        text-align: left;
        font-size: .78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .punches-table .col-action { width: 1%; text-align: right; white-space: nowrap; }

    .punches-table td {
        padding: .7rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .9rem;
        vertical-align: middle;
    }

    .punches-table tbody tr:last-child td { border-bottom: none; }
    .punches-table .open td { background: #f0fdf4; }
    .punches-table .open .duration { color: #15803d; font-weight: 600; }

    .punches-empty { padding: 2.5rem 1.5rem; text-align: center; color: #999; }

    .row-action-buttons {
        display: inline-flex;
        gap: .35rem;
        justify-content: flex-end;
    }

    .btn-row {
        padding: .3rem .75rem;
        background: transparent;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .78rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-row:hover:not(:disabled) { background: #f0f0f5; border-color: #c8d0e0; }
    .btn-row:disabled { opacity: .4; cursor: not-allowed; }
    .btn-row.danger:hover:not(:disabled) { background: #fff1f2; color: #b91c1c; border-color: #fca5a5; }

    .add-shift-row { padding: 1rem 1.25rem; border-top: 1px solid #f0f0f0; }

    .edited-pill {
        display: inline-block;
        margin-left: .5rem;
        padding: .08em .5em;
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
        border-radius: 99px;
        font-size: .68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        vertical-align: middle;
        cursor: help;
    }

    /* ── Modal (reused styling from users.php) ───────────────────────────── */
    .modal-overlay {
        position: fixed; inset: 0; z-index: 2000;
        display: flex; align-items: flex-start; justify-content: center;
        background: rgba(0,0,0,.55);
        padding: 8vh 1rem 1rem;
    }
    .modal-overlay[hidden] { display: none; }
    .modal-box {
        background: #fff; border-radius: 10px;
        box-shadow: 0 20px 60px rgba(0,0,0,.3);
        width: min(480px, 100%); max-height: 84vh;
        display: flex; flex-direction: column; overflow: hidden;
    }
    .modal-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb;
    }
    .modal-header h2 { font-size: 1rem; font-weight: 700; }
    .modal-close {
        border: none; background: transparent; font-size: 1.4rem;
        color: #9ca3af; cursor: pointer; padding: 0 .25rem; line-height: 1;
    }
    .modal-close:hover { color: #1a1a2e; }
    .modal-body { padding: 1.25rem 1.25rem .25rem; overflow-y: auto; }
    .modal-footer {
        display: flex; justify-content: flex-end; gap: .5rem;
        padding: 1rem 1.25rem; border-top: 1px solid #f0f0f0; background: #fafbfc;
    }
    .field { display: flex; flex-direction: column; margin-bottom: 1rem; }
    .field label {
        font-size: .78rem; font-weight: 600; color: #555;
        margin-bottom: .35rem; letter-spacing: .03em;
    }
    .field input {
        padding: .5rem .7rem; border: 1px solid #d1d5db; border-radius: 6px;
        font-size: .9rem; font-family: inherit;
    }
    .field input:focus {
        outline: none; border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }
    .field-hint { font-size: .72rem; color: #888; margin-top: .25rem; }
    .btn-cancel {
        padding: .5rem 1.1rem; background: transparent; color: #555;
        border: 1px solid #d1d5db; border-radius: 7px;
        font-size: .85rem; font-weight: 500; font-family: inherit; cursor: pointer;
    }
    .btn-cancel:hover { background: #f0f0f5; }

    @media (max-width: 700px) {
        .tc-main { padding: 1rem; }
        .picker-card { flex-direction: column; align-items: stretch; }
        .picker-card .group { justify-content: space-between; }
        .picker-card .picker-spacer { display: none; }
        .punches-table .col-notes { display: none; }
    }
</style>

<main class="tc-main">
    <h1>Time cards</h1>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="picker-card" method="get" action="timecards.php" id="picker-form">
        <div class="group">
            <label for="user_id">Employee</label>
            <select name="user_id" id="user_id" onchange="document.getElementById('picker-form').submit()">
                <option value="">— Pick an employee —</option>
                <?php foreach ($pickableUsers as $u): ?>
                    <?php $optLabel = trim((string) $u['name']) !== '' ? (string) $u['name'] : (string) $u['username']; ?>
                    <option value="<?= (int) $u['id'] ?>"<?= $selectedUser === (int) $u['id'] ? ' selected' : '' ?>>
                        <?= h($optLabel) ?><?= (int) $u['is_active'] !== 1 ? ' [inactive]' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="group week-nav">
            <label>Week</label>
            <a class="week-link"
               href="timecards.php?<?= h(http_build_query(['user_id' => $selectedUser, 'week' => $prevWeekDate])) ?>"
               title="Previous week">&lsaquo;</a>
            <span class="week-range"><?= h($fmtDateRange($weekStartUtc)) ?></span>
            <a class="week-link"
               href="timecards.php?<?= h(http_build_query(['user_id' => $selectedUser, 'week' => $nextWeekDate])) ?>"
               title="Next week">&rsaquo;</a>
            <?php if ($weekStartDate !== $currentWeekDate): ?>
                <a class="today-link"
                   href="timecards.php?<?= h(http_build_query(['user_id' => $selectedUser, 'week' => $currentWeekDate])) ?>">Current</a>
            <?php endif; ?>
        </div>
        <div class="picker-spacer"></div>
    </form>

    <?php if ($target === null): ?>
        <div class="empty-pick">Pick an employee above to view their time card.</div>
    <?php else: ?>
        <?php
            $isApproved = $approval !== null;
            $isPaid     = $isApproved && !empty($approval['paid_at']);
            // Hidden inputs shared by every action form in the approval bar.
            $hiddens = static function () use ($target, $weekStartDate): string {
                ob_start();
                ?>
                <input type="hidden" name="_csrf"           value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="user_id"         value="<?= (int) $target['id'] ?>">
                <input type="hidden" name="target_user_id"  value="<?= (int) $target['id'] ?>">
                <input type="hidden" name="week"            value="<?= h($weekStartDate) ?>">
                <input type="hidden" name="week_start_date" value="<?= h($weekStartDate) ?>">
                <?php
                return (string) ob_get_clean();
            };
        ?>

        <div class="approval-bar<?= $isPaid ? ' is-paid' : ($isApproved ? ' is-approved' : '') ?>">
            <div>
                <div class="total-label">Total this week</div>
                <div class="total"><?= h(formatHours($weekTotal)) ?></div>
            </div>

            <?php if ($targetPaidHourly): ?>
                <div>
                    <?php if ($isPaid): ?>
                        <div class="total-label">Paid</div>
                        <div class="total">$<?= h(number_format((float) $approval['amount_paid'], 2)) ?></div>
                    <?php elseif ($weekRate !== null): ?>
                        <div class="total-label">Pay (this week)</div>
                        <div class="total">$<?= h(number_format((float) $weekAmount, 2)) ?></div>
                        <div class="rate-hint">at $<?= h(number_format((float) $weekRate['hourly_rate'], 2)) ?>/hr</div>
                    <?php else: ?>
                        <div class="total-label">Pay (this week)</div>
                        <div class="total warn">—</div>
                        <div class="rate-hint warn">No rate on file for this pay week</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="approval-state<?= $isPaid ? ' paid' : ($isApproved ? ' locked' : '') ?>">
                <?php if ($isPaid):
                    $payerLabel = trim((string) ($approval['payer_name'] ?? '')) !== ''
                        ? (string) $approval['payer_name']
                        : (string) ($approval['payer_username'] ?? 'someone'); ?>
                    Paid by
                    <strong><?= h($payerLabel) ?></strong>
                    on <?= h((new DateTimeImmutable($approval['paid_at']))->setTimezone($tz)->format('M j, g:i A')) ?>.
                    Locked from further changes.
                <?php elseif ($isApproved): ?>
                    Approved by
                    <strong><?= h($approval['approver_name'] !== '' ? $approval['approver_name'] : $approval['approver_username']) ?></strong>
                    on <?= h((new DateTimeImmutable($approval['approved_at']))->setTimezone($tz)->format('M j, g:i A')) ?>.
                    No further edits allowed until re-opened.
                <?php else: ?>
                    Week is open for edits.  Approve to lock it from any further changes.
                <?php endif; ?>
            </div>

            <?php if (!$isPaid): ?>
                <div class="approval-actions">
                    <?php if ($isApproved && $targetPaidHourly): ?>
                        <form method="post" action="timecards.php">
                            <?= $hiddens() ?>
                            <input type="hidden" name="action" value="mark_paid">
                            <button type="submit" class="btn-primary"
                                    <?php if ($weekRate === null): ?>disabled title="No rate on file for this pay week"<?php endif; ?>
                                    data-confirm="Mark this week paid for $<?= $weekAmount !== null ? h(number_format((float) $weekAmount, 2)) : '0.00' ?>?  This locks the week permanently.">
                                Mark paid
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="timecards.php">
                        <?= $hiddens() ?>
                        <input type="hidden" name="action" value="<?= $isApproved ? 'unapprove' : 'approve' ?>">
                        <button type="submit" class="<?= $isApproved ? 'btn-cancel' : 'btn-primary' ?>"
                                data-confirm="<?= $isApproved
                                    ? 'Re-open this week for editing?'
                                    : 'Approve this week and lock it from further edits?' ?>">
                            <?= $isApproved ? 'Re-open week' : 'Approve week' ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="punches-card">
            <table class="punches-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Clock in</th>
                        <th>Clock out</th>
                        <th>Duration</th>
                        <th class="col-notes">Notes</th>
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($punches)): ?>
                        <tr><td colspan="6" class="punches-empty">No shifts in this week.</td></tr>
                    <?php else: ?>
                        <?php foreach ($punches as $p): ?>
                            <?php
                                $isOpen   = empty($p['clock_out']);
                                $duration = totalMinutes([$p], $nowUtc);
                                $wasEdited = !empty($p['edited_at']);
                            ?>
                            <tr class="<?= $isOpen ? 'open' : '' ?>">
                                <td><?= h($fmtDay($p['clock_in'])) ?></td>
                                <td><?= h($fmtTime($p['clock_in'])) ?></td>
                                <td><?= $isOpen ? '<em style="color:#15803d">— in progress —</em>' : h($fmtTime($p['clock_out'])) ?></td>
                                <td class="duration"><?= h(formatHours($duration)) ?></td>
                                <td class="col-notes">
                                    <?= $p['notes'] !== null && $p['notes'] !== '' ? h($p['notes']) : '<span style="color:#bbb">—</span>' ?>
                                    <?php if ($wasEdited): ?>
                                        <span class="edited-pill" title="Edited <?= h((new DateTimeImmutable($p['edited_at']))->setTimezone($tz)->format('M j, g:i A')) ?>">edited</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-action">
                                    <div class="row-action-buttons">
                                        <button type="button" class="btn-row js-edit-punch"
                                                data-id="<?= (int) $p['id'] ?>"
                                                data-clock-in="<?= h(utcToLocalInput($p['clock_in'], $tz)) ?>"
                                                data-clock-out="<?= $isOpen ? '' : h(utcToLocalInput($p['clock_out'], $tz)) ?>"
                                                data-notes="<?= h((string) ($p['notes'] ?? '')) ?>"
                                                <?= $isApproved ? 'disabled title="Week is approved"' : '' ?>>
                                            Edit
                                        </button>
                                        <form method="post" action="timecards.php" style="display:inline" class="js-delete-form">
                                            <input type="hidden" name="_csrf"          value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action"         value="delete_punch">
                                            <input type="hidden" name="punch_id"       value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="user_id"        value="<?= (int) $target['id'] ?>">
                                            <input type="hidden" name="target_user_id" value="<?= (int) $target['id'] ?>">
                                            <input type="hidden" name="week"           value="<?= h($weekStartDate) ?>">
                                            <button type="submit" class="btn-row danger"
                                                    data-confirm="Delete this shift?"
                                                    <?= $isApproved ? 'disabled title="Week is approved"' : '' ?>>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!$isApproved): ?>
                <div class="add-shift-row">
                    <button type="button" class="btn-primary" id="open-add-modal">+ Add shift</button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php if ($target !== null && !$isApproved): ?>
<!-- ── Add / Edit punch modal ─────────────────────────────────────────── -->
<div id="punch-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="punch-modal-title">Add shift</h2>
            <button type="button" class="modal-close" id="punch-modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="punch-modal-form" method="post" action="timecards.php" autocomplete="off">
            <div class="modal-body">
                <input type="hidden" name="_csrf"          value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action"         id="punch-modal-action"   value="add_punch">
                <input type="hidden" name="punch_id"       id="punch-modal-punch-id" value="">
                <input type="hidden" name="user_id"        value="<?= (int) $target['id'] ?>">
                <input type="hidden" name="target_user_id" value="<?= (int) $target['id'] ?>">
                <input type="hidden" name="week"           value="<?= h($weekStartDate) ?>">

                <div class="field">
                    <label for="punch-clock-in">Clock in</label>
                    <input id="punch-clock-in" name="clock_in" type="datetime-local" required>
                </div>
                <div class="field">
                    <label for="punch-clock-out">Clock out</label>
                    <input id="punch-clock-out" name="clock_out" type="datetime-local">
                    <div class="field-hint">Leave blank to record an open shift.</div>
                </div>
                <div class="field">
                    <label for="punch-notes">Notes</label>
                    <input id="punch-notes" name="notes" type="text" maxlength="200">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel"  id="punch-modal-cancel">Cancel</button>
                <button type="submit" class="btn-primary" id="punch-modal-submit">Add shift</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    var modal      = document.getElementById('punch-modal');
    var titleEl    = document.getElementById('punch-modal-title');
    var submitEl   = document.getElementById('punch-modal-submit');
    var actionEl   = document.getElementById('punch-modal-action');
    var punchIdEl  = document.getElementById('punch-modal-punch-id');
    var inEl       = document.getElementById('punch-clock-in');
    var outEl      = document.getElementById('punch-clock-out');
    var notesEl    = document.getElementById('punch-notes');

    function openAdd() {
        titleEl.textContent  = 'Add shift';
        submitEl.textContent = 'Add shift';
        actionEl.value       = 'add_punch';
        punchIdEl.value      = '';
        inEl.value           = '';
        outEl.value          = '';
        notesEl.value        = '';
        modal.hidden = false;
        setTimeout(function () { inEl.focus(); }, 30);
    }

    function openEdit(data) {
        titleEl.textContent  = 'Edit shift';
        submitEl.textContent = 'Save';
        actionEl.value       = 'update_punch';
        punchIdEl.value      = data.id || '';
        inEl.value           = data.clockIn  || '';
        outEl.value          = data.clockOut || '';
        notesEl.value        = data.notes    || '';
        modal.hidden = false;
        setTimeout(function () { inEl.focus(); }, 30);
    }

    function closeModal() { modal.hidden = true; }

    document.getElementById('open-add-modal')   && document.getElementById('open-add-modal').addEventListener('click', openAdd);
    document.getElementById('punch-modal-close').addEventListener('click', closeModal);
    document.getElementById('punch-modal-cancel').addEventListener('click', closeModal);

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    document.querySelectorAll('.js-edit-punch').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) { return; }
            openEdit({
                id:       btn.dataset.id,
                clockIn:  btn.dataset.clockIn,
                clockOut: btn.dataset.clockOut,
                notes:    btn.dataset.notes,
            });
        });
    });
}());
</script>
<?php endif; ?>

<script>
// Confirm prompts for destructive / locking actions on this page.
document.querySelectorAll('form button[data-confirm]').forEach(function (btn) {
    btn.closest('form').addEventListener('submit', function (e) {
        if (btn.disabled) { return; }
        var msg = btn.dataset.confirm;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
