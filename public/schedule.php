<?php
declare(strict_types=1);

/**
 * Schedule: everyone's regular week, and the days moved or taken off.
 *
 * Everyone sees everyone's shifts.  An employee changes only their own, from
 * today on: move a day to another day in the same pay week, keeping its times;
 * take it off; or put it back.  Every admin is emailed when they do.
 *
 * manage_schedule (admin and root) changes anyone's day and times and sets
 * regular weeks, for people at or below their own rank — the same hierarchy
 * rule as /users.php and /timecards.php.  Those changes send no email.
 *
 * URL shape:
 *   /schedule.php
 *   /schedule.php?month=2026-10
 *   /schedule.php?month=2026-10&show=3   (one person only)
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/timeclock.php';
require_once __DIR__ . '/../app/schedule.php';

$me        = requirePermission($config, 'schedule');
$meId      = (int) $me['id'];
$db        = getDb($config);
$myRank    = userRoleRank($me);
$canManage = userCan($me, 'manage_schedule');
$tz        = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));
$pws       = (string) $config['pay_week_start'];
$today     = new DateTimeImmutable('today', $tz);
$todayDate = $today->format('Y-m-d');

// Weeks start on the pay-week start day, so a row of the calendar is a pay
// week, and a move an employee makes stays inside its row.
$firstDow = match ($pws) {
    'mon'   => 1,
    'sat'   => 6,
    default => 0,
};
$dowShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dowOrder = [];
for ($i = 0; $i < 7; $i++) {
    $dowOrder[] = ($firstDow + $i) % 7;
}

$payWeekOf = function (string $ymd) use ($tz, $pws): array {
    $start = snapToPayWeekStart($ymd, $tz, $pws);
    return [$start, (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d')];
};

$parseDate = function (string $s): ?string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};

// <input type="time"> sends HH:MM, or HH:MM:SS once a step below a minute is set.
$parseTime = fn (string $s): ?string => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s) ? substr($s, 0, 5) : null;

// ── People ───────────────────────────────────────────────────────────────────

$labelOf = fn (array $u): string => trim((string) $u['name']) !== '' ? trim((string) $u['name']) : (string) $u['username'];

$people = [];
foreach ($db->query("SELECT id, username, name, role FROM users WHERE is_active = 1")->fetchAll() as $u) {
    $people[(int) $u['id']] = $u;
}
uasort($people, fn ($a, $b) => strcasecmp($labelOf($a), $labelOf($b)));

// Whose shifts and regular week this user may change as a manager.
$canEdit = fn (int $uid): bool => $canManage && isset($people[$uid]) && roleRank((string) $people[$uid]['role']) <= $myRank;

// The times to keep when a shift moves: none when they are its regular ones,
// so a later change to the regular week reaches it.
$keptTimes = fn (array $s): array => ($s['start_time'] === $s['regular_start'] && $s['end_time'] === $s['regular_end'])
    ? [null, null]
    : [$s['start_time'], $s['end_time']];

// Why an employee may not change one of their shifts, or null when they may.
$selfBlock = function (array $s) use ($todayDate): ?string {
    if ($s['shift_date'] < $todayDate || ($s['state'] !== 'off' && $s['date'] < $todayDate)) {
        return 'Days before today can no longer be changed.  Ask an admin.';
    }
    return null;
};

// ── View: month and person filter ────────────────────────────────────────────

$regularWeeks = scheduleRegularWeeks($db, array_keys($people));
$scheduled    = array_filter($people, fn ($u) => !empty($regularWeeks[(int) $u['id']]));

$source     = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$monthParam = (string) ($source['month'] ?? '');
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthParam, $tz);
if ($monthStart === false || $monthStart->format('Y-m') !== $monthParam) {
    $monthStart = $today->modify('first day of this month');
}
$monthKey = $monthStart->format('Y-m');

$showId = (int) ($source['show'] ?? 0);
if (!isset($scheduled[$showId])) {
    $showId = 0;
}

$viewQs = function (string $month, int $show): string {
    $q = ['month' => $month];
    if ($show > 0) {
        $q['show'] = $show;
    }
    return http_build_query($q);
};

// ── POST handling ────────────────────────────────────────────────────────────

$notice       = '';
$error        = '';
$mail         = null;   // [recipients, person, before, after] for an employee's change
$reopen       = '';     // 'shift' | 'regular' | ''
$form         = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = (string) ($_POST['_csrf'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $uid    = (int) ($_POST['user_id'] ?? 0);

    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } elseif ($action === 'save_regular') {
        if (!$canEdit($uid)) {
            $error = "You can't change that person's schedule.";
        } else {
            $week  = [];
            $typed = [];
            foreach ($dowOrder as $w) {
                if (empty($_POST['on'][$w])) {
                    continue;
                }
                $fromRaw = trim((string) ($_POST['start'][$w] ?? ''));
                $toRaw   = trim((string) ($_POST['end'][$w] ?? ''));
                $typed[$w] = [$fromRaw, $toRaw];
                $from = $parseTime($fromRaw);
                $to   = $parseTime($toRaw);
                if ($error !== '') {
                    continue;
                }
                if ($from === null || $to === null) {
                    $error = "Enter a start and an end time for {$dowShort[$w]}.";
                } elseif ($to <= $from) {
                    $error = "On {$dowShort[$w]} the end time must be after the start time.";
                } else {
                    $week[$w] = [$from, $to];
                }
            }

            if ($error === '') {
                $db->beginTransaction();
                $db->prepare("DELETE FROM schedule_shifts WHERE user_id = ?")->execute([$uid]);
                $insert = $db->prepare(
                    "INSERT INTO schedule_shifts (user_id, weekday, start_time, end_time, updated_by) VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($week as $w => [$from, $to]) {
                    $insert->execute([$uid, $w, $from, $to, $meId]);
                }
                $db->commit();
                $notice = 'Saved ' . $labelOf($people[$uid]) . "'s regular week.";
            } else {
                $reopen = 'regular';
                $form   = ['userId' => $uid, 'week' => $typed];
            }
        }
    } elseif (in_array($action, ['move', 'putback', 'off', 'save', 'restore'], true)) {
        $shiftDate = $parseDate((string) ($_POST['shift_date'] ?? ''));
        $shift     = ($shiftDate !== null && isset($people[$uid])) ? scheduleShiftFor($db, $uid, $shiftDate) : null;
        $asManager = $canEdit($uid);
        // Managers use the manager actions, their own shifts included.
        $asSelf    = !$canManage && $uid === $meId;

        if ($shift === null || (!$asManager && !$asSelf)) {
            $error = 'That shift is no longer on the schedule.';
        } elseif ($asSelf && !in_array($action, ['move', 'putback', 'off'], true)) {
            $error = 'Unknown action.';
        } elseif ($asManager && !in_array($action, ['save', 'off', 'restore'], true)) {
            $error = 'Unknown action.';
        } elseif ($asSelf && ($block = $selfBlock($shift)) !== null) {
            $error = $block;
        } else {
            $name = $labelOf($people[$uid]);
            $day  = scheduleFormatDate($shiftDate);
            [$keepFrom, $keepTo] = $keptTimes($shift);

            if ($action === 'move') {
                $to = $parseDate((string) ($_POST['to_date'] ?? ''));
                [$weekStart, $weekEnd] = $payWeekOf($shiftDate);
                if ($to === null) {
                    $error = 'Choose a day to move it to.';
                } elseif ($to < $weekStart || $to > $weekEnd) {
                    $error = 'A shift can move only to another day in the same pay week.';
                } elseif ($to < $todayDate) {
                    $error = 'Choose today or a later day.';
                } elseif ($shift['state'] !== 'off' && $to === $shift['date']) {
                    $error = 'It is already on that day.';
                } elseif (scheduleWorksOn($db, $uid, $to, $shiftDate)) {
                    $error = 'You already work on ' . scheduleFormatDate($to) . '.';
                } else {
                    scheduleSetChange($db, $uid, $shiftDate, $to, $keepFrom, $keepTo, $meId);
                    $notice = "Moved your {$day} shift to " . scheduleFormatDate($to) . '.';
                }
            } elseif ($action === 'putback') {
                if ($shift['date'] === $shiftDate && $shift['state'] !== 'off') {
                    $error = 'It is already on its own day.';
                } elseif (scheduleWorksOn($db, $uid, $shiftDate, $shiftDate)) {
                    $error = "You already work on {$day}, so this shift can't go back there.";
                } else {
                    scheduleSetChange($db, $uid, $shiftDate, $shiftDate, $keepFrom, $keepTo, $meId);
                    $notice = "Put your {$day} shift back on its own day.";
                }
            } elseif ($action === 'off') {
                if ($shift['state'] === 'off') {
                    $error = 'That day is already off.';
                } else {
                    scheduleSetChange($db, $uid, $shiftDate, null, $keepFrom, $keepTo, $meId);
                    $notice = $asSelf ? "Took your {$day} shift off." : "Took {$name}'s {$day} shift off.";
                }
            } elseif ($action === 'restore') {
                if ($shift['state'] === 'regular') {
                    $error = 'That shift is already regular.';
                } elseif (scheduleWorksOn($db, $uid, $shiftDate, $shiftDate)) {
                    $error = "{$name} already works on {$day}, so this shift can't go back there.  Move the other one first.";
                } else {
                    scheduleSetChange($db, $uid, $shiftDate, $shiftDate, null, null, $meId);
                    $notice = "Put {$name}'s {$day} shift back to regular.";
                }
            } else {
                $dateRaw = trim((string) ($_POST['date'] ?? ''));
                $fromRaw = trim((string) ($_POST['start_time'] ?? ''));
                $toRaw   = trim((string) ($_POST['end_time'] ?? ''));
                $date    = $parseDate($dateRaw);
                $from    = $parseTime($fromRaw);
                $to      = $parseTime($toRaw);
                if ($date === null) {
                    $error = 'Choose the day it is worked.';
                } elseif ($from === null || $to === null) {
                    $error = 'Enter a start and an end time.';
                } elseif ($to <= $from) {
                    $error = 'The end time must be after the start time.';
                } elseif (scheduleWorksOn($db, $uid, $date, $shiftDate)) {
                    $error = "{$name} already works on " . scheduleFormatDate($date) . '.';
                } else {
                    $regular = $from === $shift['regular_start'] && $to === $shift['regular_end'];
                    scheduleSetChange($db, $uid, $shiftDate, $date, $regular ? null : $from, $regular ? null : $to, $meId);
                    $notice = "Saved {$name}'s {$day} shift: " . scheduleDescribe(scheduleShiftFor($db, $uid, $shiftDate)) . '.';
                }
                if ($error !== '') {
                    $reopen = 'shift';
                    $form   = ['userId' => $uid, 'shiftDate' => $shiftDate, 'date' => $dateRaw, 'startTime' => $fromRaw, 'endTime' => $toRaw];
                }
            }

            if ($error === '' && $asSelf) {
                $recipients = scheduleAdminRecipients($db);
                if ($recipients !== []) {
                    $mail    = [$recipients, $name, $shift, scheduleShiftFor($db, $uid, $shiftDate)];
                    $notice .= '  The admins will get an email.';
                }
            }
        }
    } else {
        $error = 'Unknown action.';
    }

    if ($error === '' && $notice !== '') {
        $_SESSION['flash_notice'] = $notice;
        header('Location: /schedule.php?' . $viewQs($monthKey, $showId));
        if ($mail !== null) {
            // Absent outside php-fpm, where the redirect waits for the email.
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            mailScheduleChange($config, ...$mail);
        }
        exit;
    }
}

if ($notice === '' && !empty($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

// ── Page state ───────────────────────────────────────────────────────────────

// Calendar chips carry a first name; two people sharing one get full names.
$firstWords = [];
foreach ($people as $id => $u) {
    $firstWords[$id] = preg_split('/\s+/', $labelOf($u))[0];
}
$firstWordCounts = array_count_values($firstWords);
$shortOf  = [];
$colourOf = [];
foreach ($people as $id => $u) {
    $shortOf[$id] = $firstWordCounts[$firstWords[$id]] > 1 ? $labelOf($u) : $firstWords[$id];
}
$i = 0;
foreach ($scheduled as $id => $u) {
    $colourOf[$id] = $i++ % 8;
}

$monthEnd  = $monthStart->modify('last day of this month');
$gridStart = $payWeekOf($monthStart->format('Y-m-d'))[0];
$gridEnd   = $payWeekOf($monthEnd->format('Y-m-d'))[1];

$visibleIds = $showId > 0 ? [$showId] : array_keys($scheduled);
$shifts     = scheduleShifts($db, $visibleIds, $gridStart, $gridEnd);

// Worked shifts first, by start time, then off days and moved-away markers.
$personPos = array_flip(array_keys($people));
usort($shifts, fn ($a, $b) =>
    [$a['date'], !in_array($a['state'], SCHEDULE_WORKED, true), $a['start_time'], $personPos[$a['user_id']]]
    <=> [$b['date'], !in_array($b['state'], SCHEDULE_WORKED, true), $b['start_time'], $personPos[$b['user_id']]]
);
$byDay = [];
foreach ($shifts as $s) {
    $byDay[$s['date']][] = $s;
}

$showNames = $showId === 0 && count($scheduled) > 1;

// Days an employee could move one of their shifts to: the rest of its pay
// week from today on, where they do not already work.  Memoised per shift,
// since a moved shift shows twice.
$targetsMemo = [];
$moveTargets = function (array $s) use (&$targetsMemo, $db, $payWeekOf, $todayDate): array {
    $key = $s['user_id'] . '/' . $s['shift_date'];
    if (isset($targetsMemo[$key])) {
        return $targetsMemo[$key];
    }
    [$weekStart, $weekEnd] = $payWeekOf($s['shift_date']);
    $worked = [];
    foreach (scheduleShifts($db, [$s['user_id']], $weekStart, $weekEnd) as $other) {
        if ($other['shift_date'] !== $s['shift_date'] && in_array($other['state'], SCHEDULE_WORKED, true)) {
            $worked[$other['date']] = true;
        }
    }
    $targets = [];
    for ($d = new DateTimeImmutable($weekStart); $d->format('Y-m-d') <= $weekEnd; $d = $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d');
        if ($ds < $todayDate || $ds === $s['shift_date'] || isset($worked[$ds])
            || ($s['state'] !== 'off' && $ds === $s['date'])) {
            continue;
        }
        $targets[] = ['date' => $ds, 'label' => scheduleFormatDate($ds)];
    }
    return $targetsMemo[$key] = $targets;
};

// One chip, used by both the grid and the phone list.  A button when this
// user may change the shift, plain text otherwise.
$chip = function (array $s, bool $long) use ($people, $labelOf, $shortOf, $colourOf, $showNames, $canManage, $canEdit, $meId, $selfBlock, $moveTargets): string {
    $uid  = $s['user_id'];
    $name = $showNames ? $shortOf[$uid] . ' · ' : '';
    $when = $long ? 'D, M j' : 'D';
    $text = match ($s['state']) {
        'off'        => $name . 'off',
        'moved_away' => $name . 'moved to ' . (new DateTimeImmutable($s['moved_to']))->format($when),
        'moved'      => $name . scheduleFormatTimes($s['start_time'], $s['end_time']) . ' · from ' . (new DateTimeImmutable($s['shift_date']))->format($when),
        default      => $name . scheduleFormatTimes($s['start_time'], $s['end_time']),
    };
    // A moved-away marker stands for the moved shift itself.
    $real  = $s['state'] === 'moved_away' ? ['state' => 'moved', 'date' => $s['moved_to']] + $s : $s;
    $now   = scheduleDescribe($real);
    $reg   = scheduleFormatDate($s['shift_date']) . ', ' . scheduleFormatTimes($s['regular_start'], $s['regular_end']);
    $title = $labelOf($people[$uid]) . ' — regular: ' . $reg . ($s['state'] === 'regular' ? '' : '; now: ' . $now);
    $cls   = 'chip c' . ($colourOf[$uid] ?? 7) . ' state-' . $s['state'];

    $asManager = $canEdit($uid);
    $asSelf    = !$canManage && $uid === $meId && $selfBlock($real) === null;
    if (!$asManager && !$asSelf) {
        return '<span class="' . $cls . '" title="' . h($title) . '">' . h($text) . '</span>';
    }
    $data = [
        'user-id'    => (string) $uid,
        'person'     => $labelOf($people[$uid]),
        'shift-date' => $real['shift_date'],
        'shift-day'  => scheduleFormatDate($real['shift_date']),
        'state'      => $real['state'],
        'date'       => $real['date'],
        'start-time' => $real['start_time'],
        'end-time'   => $real['end_time'],
        'now'        => $now,
        'regular'    => $reg,
    ];
    if ($asSelf) {
        $data['targets'] = json_encode($moveTargets($real));
    }
    $attrs = '';
    foreach ($data as $k => $v) {
        $attrs .= ' data-' . $k . '="' . h($v) . '"';
    }
    return '<button type="button" class="' . $cls . ' js-shift" title="' . h($title) . '"' . $attrs . '>' . h($text) . '</button>';
};

$editablePeople = array_filter($people, fn ($u) => $canEdit((int) $u['id']));

// A regular-week cell may break after its dash and nowhere else, so a long
// week wraps onto two lines in a narrow window instead of scrolling.
$weekTimes = fn (array $t): string => strtr(scheduleFormatTimes($t['start_time'], $t['end_time']), [' – ' => "\u{a0}– ", ' ' => "\u{a0}"]);

$prevMonth    = $monthStart->modify('-1 month');
$nextMonth    = $monthStart->modify('+1 month');
$currentMonth = $today->format('Y-m');

$pageTitle  = 'Schedule - Cent Notes';
$activePage = 'schedule';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .sc-main {
        flex: 1;
        padding: 2rem;
        max-width: 1100px;
        margin: 0 auto;
        width: 100%;
    }

    .sc-main h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: .35rem; }

    .sc-intro { font-size: .88rem; color: #666; margin-bottom: 1.25rem; max-width: 52rem; }

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

    .error[hidden] { display: none; }

    .btn-primary {
        padding: .55rem 1.1rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-primary:hover:not(:disabled) { background: #2d2d5e; }
    .btn-primary:disabled { opacity: .4; cursor: not-allowed; }

    .btn-secondary {
        padding: .5rem 1rem;
        background: transparent;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 7px;
        font-size: .82rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-secondary:hover { background: #f0f0f5; border-color: #c8d0e0; }
    .btn-secondary[hidden] { display: none; }

    .btn-off { color: #b91c1c; border-color: #fca5a5; }
    .btn-off:hover { background: #fff1f2; border-color: #fca5a5; }

    /* ── Toolbar ──────────────────────────────────────────────────────────── */
    .sc-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .sc-toolbar h2 { font-size: 1.1rem; font-weight: 700; min-width: 10.5rem; text-align: center; }

    .month-link {
        padding: .35rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        color: #1a1a2e;
        font-size: .82rem;
        text-decoration: none;
    }

    .month-link:hover { background: #f0f0f5; }

    .sc-filter { margin-left: auto; display: flex; align-items: center; gap: .5rem; font-size: .85rem; }

    .sc-filter select {
        padding: .4rem .6rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .85rem;
        font-family: inherit;
        background: #fff;
    }

    .legend { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }

    /* ── Chips: one per shift on a day ────────────────────────────────────── */
    .chip {
        display: block;
        width: 100%;
        text-align: left;
        border: 1px solid;
        border-radius: 5px;
        padding: .15rem .4rem;
        font-size: .74rem;
        font-family: inherit;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    button.chip { cursor: pointer; }
    button.chip:hover { filter: brightness(.95); }
    .legend .chip { display: inline-block; width: auto; }

    .chip.c0 { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
    .chip.c1 { background: #dcfce7; color: #166534; border-color: #86efac; }
    .chip.c2 { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
    .chip.c3 { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
    .chip.c4 { background: #fce7f3; color: #9d174d; border-color: #f9a8d4; }
    .chip.c5 { background: #ccfbf1; color: #115e59; border-color: #5eead4; }
    .chip.c6 { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
    .chip.c7 { background: #e2e8f0; color: #334155; border-color: #94a3b8; }

    .chip.state-moved,
    .chip.state-changed { border-style: dashed; border-width: 1.5px; }
    .chip.state-off,
    .chip.state-moved_away { background: #fff; color: #888; border-style: dotted; }
    .chip.state-off { text-decoration: line-through; }

    /* ── Month grid ───────────────────────────────────────────────────────── */
    .cal-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
    }

    .cal-dow {
        padding: .5rem;
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #666;
        background: #fafbfc;
        border-bottom: 1px solid #eee;
        text-align: center;
    }

    .cal-day {
        min-height: 6.5rem;
        padding: .35rem;
        border-right: 1px solid #f0f0f0;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        flex-direction: column;
        gap: .2rem;
    }

    .cal-day:nth-child(7n) { border-right: none; }
    .cal-day.is-other-month { background: #fafafa; }
    .cal-day.is-other-month .cal-date { color: #bbb; }
    .cal-day.is-today { background: #f5f7ff; }
    .cal-day.is-today .cal-date { background: #1a1a2e; color: #fff; }

    .cal-date {
        align-self: flex-start;
        font-size: .78rem;
        font-weight: 600;
        color: #444;
        min-width: 1.5rem;
        height: 1.5rem;
        line-height: 1.5rem;
        text-align: center;
        border-radius: 99px;
    }

    /* ── Phone list: the same month as days with shifts ───────────────────── */
    .cal-list { display: none; }

    .list-day {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: .75rem 1rem;
        margin-bottom: .75rem;
    }

    .list-day h3 { font-size: .85rem; font-weight: 700; margin-bottom: .45rem; }
    .list-day.is-today h3::after { content: ' · Today'; color: #1a1a2e; font-weight: 400; }
    .list-day .chip { font-size: .85rem; padding: .35rem .55rem; margin-bottom: .3rem; }

    .list-empty, .sc-empty { padding: 1.5rem 1rem; text-align: center; color: #888; font-size: .88rem; }

    .sc-empty {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 1rem;
    }

    /* ── Regular weeks (managers) ─────────────────────────────────────────── */
    .regular-card {
        margin-top: 2rem;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow-x: auto;
    }

    .regular-card h2 {
        font-size: .82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #555;
        padding: 1rem 1.25rem .35rem;
    }

    .regular-card p { font-size: .82rem; color: #777; padding: 0 1.25rem .75rem; }

    .regular-table { width: 100%; border-collapse: collapse; font-size: .78rem; }

    .regular-table th,
    .regular-table td { padding: .5rem .45rem; border-top: 1px solid #f0f0f0; text-align: left; }

    .regular-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; color: #777; font-weight: 700; }
    .regular-table td.none { color: #bbb; }
    .regular-table td:first-child, .regular-table th:first-child { padding-left: 1.25rem; font-weight: 600; white-space: nowrap; }
    .regular-table td:last-child { padding-right: 1.25rem; text-align: right; }
    .regular-table .btn-secondary { padding: .35rem .8rem; font-size: .78rem; }

    @media (max-width: 720px) {
        .sc-main { padding: 1rem; }
        .cal-grid { display: none; }
        .cal-list { display: block; }
        .sc-filter { margin-left: 0; width: 100%; }
        .sc-toolbar h2 { min-width: 0; flex: 1; }
    }

    /* ── Modals ───────────────────────────────────────────────────────────── */
    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        background: rgba(0,0,0,.55);
        padding: 6vh 1rem 1rem;
    }

    .modal-overlay[hidden] { display: none; }

    .modal-box {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 60px rgba(0,0,0,.3);
        width: min(500px, 100%);
        max-height: 88vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .modal-box form { display: flex; flex-direction: column; min-height: 0; }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .modal-header h2 { font-size: 1rem; font-weight: 700; }

    .modal-close {
        border: none;
        background: transparent;
        font-size: 1.4rem;
        color: #9ca3af;
        cursor: pointer;
        padding: 0 .25rem;
        line-height: 1;
    }

    .modal-close:hover { color: #1a1a2e; }

    .modal-body { padding: 1.25rem 1.25rem .5rem; overflow-y: auto; }

    .modal-footer {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .5rem;
        padding: 1rem 1.25rem;
        border-top: 1px solid #f0f0f0;
        background: #fafbfc;
    }

    .modal-footer .spacer { margin-right: auto; }

    .shift-status { font-size: .85rem; color: #444; line-height: 1.6; margin-bottom: 1rem; }
    .shift-status strong { color: #1a1a2e; }

    .modal-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .9rem 1.1rem;
    }

    .field { display: flex; flex-direction: column; }
    .field.full { grid-column: 1 / -1; }

    .field > label,
    .field-label {
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .modal-body input[type=date],
    .modal-body input[type=time] {
        padding: .5rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .88rem;
        font-family: inherit;
        color: #1a1a2e;
        background: #fff;
    }

    .modal-body input:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .modal-body input:disabled { background: #f5f5f8; color: #aaa; }

    .field-hint { font-size: .75rem; color: #888; margin-top: .35rem; }
    .field-hint[hidden] { display: none; }

    .choice-row { display: flex; flex-wrap: wrap; gap: .4rem; }

    .choice {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .35rem .65rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .85rem;
        cursor: pointer;
        user-select: none;
    }

    .choice:has(input:checked) { background: #1a1a2e; border-color: #1a1a2e; color: #fff; }
    .choice input { margin: 0; }

    .week-row {
        display: grid;
        grid-template-columns: 5.5rem 1fr 1fr;
        gap: .5rem;
        align-items: center;
        margin-bottom: .45rem;
    }

    .week-row .choice { justify-content: flex-start; }
</style>

<main class="sc-main">
    <h1>Schedule</h1>
    <p class="sc-intro">
        <?php if ($canManage): ?>
            Everyone's shifts.  Click a shift to change its day or times, or to give the day off.
            Set each person's regular week at the bottom of the page.
        <?php else: ?>
            Everyone's shifts.  Click one of your own, from today on, to move it to another day in the
            same week (it keeps its hours), to take the day off, or to put it back.  The admins get an
            email when you do.
        <?php endif; ?>
    </p>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error" id="page-error"<?= $reopen !== '' ? ' hidden' : '' ?>><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$canManage && empty($regularWeeks[$meId])): ?>
        <div class="sc-empty">You don't have a regular schedule yet.  An admin sets it up.</div>
    <?php endif; ?>

    <div class="sc-toolbar">
        <a class="month-link" href="schedule.php?<?= h($viewQs($prevMonth->format('Y-m'), $showId)) ?>" aria-label="Previous month">&lsaquo; <?= h($prevMonth->format('M')) ?></a>
        <h2><?= h($monthStart->format('F Y')) ?></h2>
        <a class="month-link" href="schedule.php?<?= h($viewQs($nextMonth->format('Y-m'), $showId)) ?>" aria-label="Next month"><?= h($nextMonth->format('M')) ?> &rsaquo;</a>
        <?php if ($monthKey !== $currentMonth): ?>
            <a class="month-link" href="schedule.php?<?= h($viewQs($currentMonth, $showId)) ?>">This month</a>
        <?php endif; ?>

        <?php if (count($scheduled) > 1): ?>
            <form class="sc-filter" method="get" action="schedule.php" id="filter-form">
                <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                <label for="filter-show">Show</label>
                <select name="show" id="filter-show" onchange="document.getElementById('filter-form').submit()">
                    <option value="0">Everyone</option>
                    <?php foreach ($scheduled as $id => $u): ?>
                        <option value="<?= (int) $id ?>"<?= $id === $showId ? ' selected' : '' ?>><?= h($labelOf($u)) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($showNames): ?>
        <div class="legend">
            <?php foreach ($scheduled as $id => $u): ?>
                <span class="chip c<?= $colourOf[$id] ?>"><?= h($labelOf($u)) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="cal-grid">
        <?php foreach ($dowOrder as $dow): ?>
            <div class="cal-dow"><?= h($dowShort[$dow]) ?></div>
        <?php endforeach; ?>
        <?php for ($d = new DateTimeImmutable($gridStart); $d->format('Y-m-d') <= $gridEnd; $d = $d->modify('+1 day')):
            $ds  = $d->format('Y-m-d');
            $cls = 'cal-day';
            if ($d->format('Y-m') !== $monthKey) { $cls .= ' is-other-month'; }
            if ($ds === $todayDate)               { $cls .= ' is-today'; }
        ?>
            <div class="<?= $cls ?>" data-date="<?= h($ds) ?>">
                <span class="cal-date"><?= h($d->format('j')) ?></span>
                <?php foreach ($byDay[$ds] ?? [] as $s): ?>
                    <?= $chip($s, false) ?>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>

    <div class="cal-list">
        <?php $listed = 0; ?>
        <?php for ($d = $monthStart; $d <= $monthEnd; $d = $d->modify('+1 day')):
            $ds = $d->format('Y-m-d');
            if (empty($byDay[$ds])) { continue; }
            $listed++;
        ?>
            <div class="list-day<?= $ds === $todayDate ? ' is-today' : '' ?>">
                <h3><?= h(scheduleFormatDate($ds)) ?></h3>
                <?php foreach ($byDay[$ds] as $s): ?>
                    <?= $chip($s, true) ?>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
        <?php if ($listed === 0): ?>
            <div class="list-empty">No shifts in <?= h($monthStart->format('F')) ?>.</div>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
        <section class="regular-card">
            <h2>Regular weeks</h2>
            <p>The shifts each person works every week.  A change here applies to every week, past ones included.</p>
            <table class="regular-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <?php foreach ($dowOrder as $dow): ?>
                            <th><?= h($dowShort[$dow]) ?></th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($editablePeople as $id => $u):
                        $week = $regularWeeks[$id] ?? [];
                    ?>
                        <tr>
                            <td><?= h($labelOf($u)) ?></td>
                            <?php foreach ($dowOrder as $dow): ?>
                                <?php if (isset($week[$dow])): ?>
                                    <td><?= h($weekTimes($week[$dow])) ?></td>
                                <?php else: ?>
                                    <td class="none">—</td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <td>
                                <button type="button" class="btn-secondary js-regular"
                                        data-user-id="<?= (int) $id ?>"
                                        data-person="<?= h($labelOf($u)) ?>"
                                        data-week="<?= h(json_encode(array_map(fn ($t) => [$t['start_time'], $t['end_time']], $week), JSON_FORCE_OBJECT)) ?>">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>

<?php if ($canManage): ?>
<!-- ── Manager: one shift ────────────────────────────────────────────────── -->
<div id="shift-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="shift-title">Shift</h2>
            <button type="button" class="modal-close js-close" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="schedule.php" class="js-confirm-form" autocomplete="off">
            <div class="modal-body">
                <div class="error" id="shift-error" <?= ($error !== '' && $reopen === 'shift') ? '' : 'hidden' ?>><?= h($error) ?></div>
                <input type="hidden" name="_csrf"      value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="month"      value="<?= h($monthKey) ?>">
                <input type="hidden" name="show"       value="<?= (int) $showId ?>">
                <input type="hidden" name="user_id"    id="shift-user-id">
                <input type="hidden" name="shift_date" id="shift-shift-date">

                <div class="shift-status">
                    <div>Now: <strong id="shift-now"></strong></div>
                    <div>Regular: <span id="shift-regular"></span></div>
                </div>

                <div class="modal-grid">
                    <div class="field full">
                        <label for="shift-date">Worked on</label>
                        <input type="date" id="shift-date" name="date" required>
                    </div>
                    <div class="field">
                        <label for="shift-start">From</label>
                        <input type="time" id="shift-start" name="start_time" required>
                    </div>
                    <div class="field">
                        <label for="shift-end">To</label>
                        <input type="time" id="shift-end" name="end_time" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-secondary btn-off" name="action" value="off" id="shift-off" formnovalidate
                        data-confirm="Give this day off?">Day off</button>
                <button type="submit" class="btn-secondary spacer" name="action" value="restore" id="shift-restore" formnovalidate>Back to regular</button>
                <button type="button" class="btn-secondary js-close">Cancel</button>
                <button type="submit" class="btn-primary" name="action" value="save">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Manager: a regular week ───────────────────────────────────────────── -->
<div id="regular-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="regular-title">Regular week</h2>
            <button type="button" class="modal-close js-close" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="schedule.php" autocomplete="off">
            <div class="modal-body">
                <div class="error" id="regular-error" <?= ($error !== '' && $reopen === 'regular') ? '' : 'hidden' ?>><?= h($error) ?></div>
                <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action"  value="save_regular">
                <input type="hidden" name="month"   value="<?= h($monthKey) ?>">
                <input type="hidden" name="show"    value="<?= (int) $showId ?>">
                <input type="hidden" name="user_id" id="regular-user-id">
                <?php foreach ($dowOrder as $dow): ?>
                    <div class="week-row" data-dow="<?= $dow ?>">
                        <label class="choice"><input type="checkbox" name="on[<?= $dow ?>]" value="1" class="js-week-on"> <?= h($dowShort[$dow]) ?></label>
                        <input type="time" name="start[<?= $dow ?>]" aria-label="<?= h($dowShort[$dow]) ?> start" required>
                        <input type="time" name="end[<?= $dow ?>]" aria-label="<?= h($dowShort[$dow]) ?> end" required>
                    </div>
                <?php endforeach; ?>
                <div class="field-hint">Tick the days they work.  Leave every day unticked for no regular schedule.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary js-close">Cancel</button>
                <button type="submit" class="btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<!-- ── Employee: one of their own shifts ─────────────────────────────────── -->
<div id="self-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="self-title">Your shift</h2>
            <button type="button" class="modal-close js-close" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="schedule.php" class="js-confirm-form" autocomplete="off">
            <div class="modal-body">
                <input type="hidden" name="_csrf"      value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="month"      value="<?= h($monthKey) ?>">
                <input type="hidden" name="show"       value="<?= (int) $showId ?>">
                <input type="hidden" name="user_id"    value="<?= $meId ?>">
                <input type="hidden" name="shift_date" id="self-shift-date">

                <div class="shift-status">
                    <div>Now: <strong id="self-now"></strong></div>
                    <div>Regular: <span id="self-regular"></span></div>
                </div>

                <div class="field">
                    <span class="field-label">Move it to</span>
                    <div class="choice-row" id="self-targets"></div>
                    <div class="field-hint" id="self-no-targets" hidden>No other free day left in this pay week.</div>
                    <div class="field-hint">It keeps its hours.  The admins get an email about any change.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-secondary btn-off" name="action" value="off" id="self-off" formnovalidate
                        data-confirm="Take this day off?  The admins will get an email.">Take the day off</button>
                <button type="submit" class="btn-secondary spacer" name="action" value="putback" id="self-putback" formnovalidate>Put back</button>
                <button type="button" class="btn-secondary js-close">Cancel</button>
                <button type="submit" class="btn-primary" name="action" value="move" id="self-move">Move</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    var CAN_MANAGE = <?= json_encode($canManage) ?>;

    function openModal(modal) { modal.hidden = false; }
    function closeModals() {
        document.querySelectorAll('.modal-overlay').forEach(function (m) { m.hidden = true; });
    }

    document.querySelectorAll('.js-close').forEach(function (b) { b.addEventListener('click', closeModals); });
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) { closeModals(); } });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModals(); } });

    // The buttons that end a day carry data-confirm.
    document.querySelectorAll('form.js-confirm-form').forEach(function (frm) {
        frm.addEventListener('submit', function (e) {
            var msg = e.submitter && e.submitter.dataset.confirm;
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    function clearError(id) {
        var el = document.getElementById(id);
        if (el) { el.hidden = true; el.textContent = ''; }
    }

    // ── Manager: one shift ──
    function openShift(d) {
        document.getElementById('shift-title').textContent    = d.person + ' · ' + d.shiftDay + ' shift';
        document.getElementById('shift-user-id').value        = d.userId;
        document.getElementById('shift-shift-date').value     = d.shiftDate;
        document.getElementById('shift-now').textContent      = d.now;
        document.getElementById('shift-regular').textContent  = d.regular;
        document.getElementById('shift-date').value           = d.state === 'off' ? d.shiftDate : d.date;
        document.getElementById('shift-start').value          = d.startTime;
        document.getElementById('shift-end').value            = d.endTime;
        document.getElementById('shift-off').hidden           = d.state === 'off';
        document.getElementById('shift-restore').hidden       = d.state === 'regular';
        openModal(document.getElementById('shift-modal'));
    }

    // ── Manager: a regular week ──
    function openRegular(userId, person, week) {
        document.getElementById('regular-title').textContent = person + "'s regular week";
        document.getElementById('regular-user-id').value     = userId;
        document.querySelectorAll('#regular-modal .week-row').forEach(function (row) {
            var times = week[row.dataset.dow];
            var on    = row.querySelector('.js-week-on');
            var t     = row.querySelectorAll('input[type=time]');
            on.checked = !!times;
            t[0].value = times ? times[0] : '';
            t[1].value = times ? times[1] : '';
            t[0].disabled = t[1].disabled = !on.checked;
        });
        openModal(document.getElementById('regular-modal'));
    }

    document.querySelectorAll('.js-week-on').forEach(function (on) {
        on.addEventListener('change', function () {
            on.closest('.week-row').querySelectorAll('input[type=time]').forEach(function (t) { t.disabled = !on.checked; });
        });
    });

    document.querySelectorAll('.js-regular').forEach(function (btn) {
        btn.addEventListener('click', function () {
            clearError('regular-error');
            openRegular(btn.dataset.userId, btn.dataset.person, JSON.parse(btn.dataset.week));
        });
    });

    // ── Employee: one of their own shifts ──
    function openSelf(d) {
        document.getElementById('self-title').textContent   = 'Your ' + d.shiftDay + ' shift';
        document.getElementById('self-shift-date').value    = d.shiftDate;
        document.getElementById('self-now').textContent     = d.now;
        document.getElementById('self-regular').textContent = d.regular;

        var targets = JSON.parse(d.targets || '[]');
        var box     = document.getElementById('self-targets');
        box.innerHTML = '';
        targets.forEach(function (t) {
            var label = document.createElement('label');
            label.className = 'choice';
            label.innerHTML = '<input type="radio" name="to_date" required value="' + escHtml(t.date) + '"> ' + escHtml(t.label);
            box.appendChild(label);
        });
        document.getElementById('self-no-targets').hidden = targets.length > 0;
        document.getElementById('self-move').disabled     = targets.length === 0;
        document.getElementById('self-off').hidden        = d.state === 'off';
        var putback = document.getElementById('self-putback');
        putback.hidden      = d.state === 'regular' || d.state === 'changed';
        putback.textContent = 'Put back on ' + d.shiftDay;
        openModal(document.getElementById('self-modal'));
    }

    document.querySelectorAll('.js-shift').forEach(function (btn) {
        btn.addEventListener('click', function () {
            clearError('shift-error');
            (CAN_MANAGE ? openShift : openSelf)(btn.dataset);
        });
    });

    // A server-side error reopens what was being edited, with what was typed.
    var pageError = document.getElementById('page-error');
    <?php if ($reopen === 'shift'): ?>
        (function (f) {
            var btn = document.querySelector('.js-shift[data-user-id="' + f.userId + '"][data-shift-date="' + f.shiftDate + '"]');
            if (!btn) { pageError.hidden = false; return; }
            openShift(btn.dataset);
            document.getElementById('shift-error').hidden = false;
            document.getElementById('shift-date').value  = f.date;
            document.getElementById('shift-start').value = f.startTime;
            document.getElementById('shift-end').value   = f.endTime;
        }(<?= json_encode($form) ?>));
    <?php elseif ($reopen === 'regular'): ?>
        (function (f) {
            var btn = document.querySelector('.js-regular[data-user-id="' + f.userId + '"]');
            if (!btn) { pageError.hidden = false; return; }
            openRegular(f.userId, btn.dataset.person, f.week);
            document.getElementById('regular-error').hidden = false;
        }(<?= json_encode($form, JSON_FORCE_OBJECT) ?>));
    <?php endif; ?>
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
