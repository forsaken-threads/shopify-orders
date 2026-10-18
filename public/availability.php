<?php
declare(strict_types=1);

/**
 * Availability: the days and times each employee cannot work.
 *
 * Everyone manages their own entries here.  manage_availability (admin and
 * root) also sees and edits every active user at or below their own rank, on
 * one month calendar, under the same hierarchy rule as /users.php and
 * /timecards.php.
 *
 * URL shape:
 *   /availability.php
 *   /availability.php?month=2026-10
 *   /availability.php?month=2026-10&user_id=3   (managers: one person only)
 *
 * Entries are local wall-clock dates and times; see the unavailability table
 * in scripts/migrate.php.  The timezone is used only to find today.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';

$me        = requirePermission($config, 'availability');
$db        = getDb($config);
$myRank    = userRoleRank($me);
$canManage = userCan($me, 'manage_availability');
$tz        = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));
$today     = new DateTimeImmutable('today', $tz);
$todayDate = $today->format('Y-m-d');

// Weeks start on the pay-week start day, so a row here is a row on Time cards.
$firstDow = match ((string) $config['pay_week_start']) {
    'mon'   => 1,
    'sat'   => 6,
    default => 0,
};
$dowShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dowOrder = [];
for ($i = 0; $i < 7; $i++) {
    $dowOrder[] = ($firstDow + $i) % 7;
}

// ── Whose entries this user may see and change ───────────────────────────────

$labelOf = fn (array $u): string => trim((string) $u['name']) !== '' ? trim((string) $u['name']) : (string) $u['username'];

$people = [];
foreach ($db->query("SELECT id, username, name, role FROM users WHERE is_active = 1")->fetchAll() as $u) {
    $isMe = (int) $u['id'] === (int) $me['id'];
    if ($isMe || ($canManage && roleRank((string) $u['role']) <= $myRank)) {
        $people[(int) $u['id']] = $u;
    }
}
uasort($people, fn ($a, $b) => strcasecmp($labelOf($a), $labelOf($b)));

// Calendar chips carry a first name; two people sharing one get full names.
$firstWords = [];
foreach ($people as $id => $u) {
    $firstWords[$id] = preg_split('/\s+/', $labelOf($u))[0];
}
$firstWordCounts = array_count_values($firstWords);
$shortOf  = [];
$colourOf = [];
$i = 0;
foreach ($people as $id => $u) {
    $shortOf[$id]  = $firstWordCounts[$firstWords[$id]] > 1 ? $labelOf($u) : $firstWords[$id];
    $colourOf[$id] = $i++ % 8;
}

// ── Formatting ───────────────────────────────────────────────────────────────

$fmtDate = function (string $ymd) use ($today): string {
    $d = new DateTimeImmutable($ymd);
    return $d->format($d->format('Y') === $today->format('Y') ? 'D, M j' : 'D, M j, Y');
};

$fmtClock = function (string $hm): string {
    $t = DateTimeImmutable::createFromFormat('!H:i', $hm);
    return $t->format($t->format('i') === '00' ? 'g A' : 'g:i A');
};

$fmtTimes = function (?string $from, ?string $to) use ($fmtClock): string {
    if ($from === null || $to === null) {
        return 'all day';
    }
    $a = $fmtClock($from);
    $b = $fmtClock($to);
    if (substr($a, -2) === substr($b, -2)) {
        return substr($a, 0, -3) . '–' . $b;
    }
    return $a . ' – ' . $b;
};

$joinDays = function (array $days) use ($dowOrder, $dowShort): string {
    if (count($days) === 7) {
        return 'day';
    }
    $names = [];
    foreach ($dowOrder as $dow) {
        if (in_array($dow, $days, true)) {
            $names[] = $dowShort[$dow];
        }
    }
    $last = array_pop($names);
    return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
};

$describe = function (array $e) use ($fmtDate, $fmtTimes, $joinDays): string {
    $times = $fmtTimes($e['start_time'], $e['end_time']);
    if ($e['repeat_days'] !== null) {
        $s = 'Every ' . $joinDays($e['days']) . ' from ' . $fmtDate($e['start_date']);
        if ($e['end_date'] !== null) {
            $s .= ' until ' . $fmtDate($e['end_date']);
        }
        return $s . ', ' . $times;
    }
    $span = $e['start_date'] === $e['end_date']
        ? $fmtDate($e['start_date'])
        : $fmtDate($e['start_date']) . ' – ' . $fmtDate($e['end_date']);
    return $span . ', ' . $times;
};

$daysOf = fn (?string $repeatDays): array => $repeatDays === null ? [] : array_map('intval', explode(',', $repeatDays));

$loadEntry = function (int $id) use ($db, $daysOf): ?array {
    $stmt = $db->prepare(
        "SELECT a.*, ub.name AS updated_by_name, ub.username AS updated_by_username
         FROM unavailability a
         LEFT JOIN users ub ON ub.id = a.updated_by
         WHERE a.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['days'] = $daysOf($row['repeat_days']);
    return $row;
};

// Who last changed an entry, when that was somebody other than its owner.
$changedNote = function (array $e) use ($tz): string {
    if ($e['updated_by'] === null || (int) $e['updated_by'] === (int) $e['user_id']) {
        return '';
    }
    $who  = trim((string) $e['updated_by_name']) !== '' ? (string) $e['updated_by_name'] : (string) $e['updated_by_username'];
    $when = (new DateTimeImmutable((string) $e['updated_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('M j');
    return "Last changed by {$who} on {$when}.";
};

$parseDate = function (string $s): ?string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};

// <input type="time"> sends HH:MM, or HH:MM:SS once a step below a minute is set.
$parseTime = fn (string $s): ?string => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s) ? substr($s, 0, 5) : null;

// ── View: month and person filter ────────────────────────────────────────────

$source     = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$monthParam = (string) ($source['month'] ?? '');
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthParam, $tz);
if ($monthStart === false || $monthStart->format('Y-m') !== $monthParam) {
    $monthStart = $today->modify('first day of this month');
}
$monthKey = $monthStart->format('Y-m');

$filterId = $canManage ? (int) ($source['user_id'] ?? 0) : 0;
if (!isset($people[$filterId])) {
    $filterId = 0;
}

$viewQs = function (string $month, int $filter): string {
    $q = ['month' => $month];
    if ($filter > 0) {
        $q['user_id'] = $filter;
    }
    return http_build_query($q);
};

// ── POST handling ────────────────────────────────────────────────────────────

$notice     = '';
$error      = '';
$reopenMode = '';   // 'create' | 'edit' | ''
$form       = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = (string) ($_POST['_csrf'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } elseif ($action === 'delete') {
        $existing = $loadEntry((int) ($_POST['id'] ?? 0));
        if ($existing === null || !isset($people[(int) $existing['user_id']])) {
            $error = 'That entry no longer exists.';
        } else {
            $db->prepare("DELETE FROM unavailability WHERE id = ?")->execute([(int) $existing['id']]);
            $notice = 'Deleted: ' . ($canManage ? $labelOf($people[(int) $existing['user_id']]) . ', ' : '') . $describe($existing) . '.';
        }
    } elseif ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $existing = $loadEntry($id);
            $ownerId  = $existing === null ? 0 : (int) $existing['user_id'];
        } else {
            $ownerId = $canManage ? (int) ($_POST['owner_id'] ?? 0) : (int) $me['id'];
        }

        if (!isset($people[$ownerId])) {
            $error = $id > 0 ? 'That entry no longer exists.' : 'Choose who this is for.';
        } else {
            $repeat    = (string) ($_POST['repeat'] ?? '') === 'weekly' ? 'weekly' : 'none';
            $startRaw  = trim((string) ($_POST['start_date'] ?? ''));
            $endRaw    = trim((string) ($_POST['end_date'] ?? ''));
            $allDay    = (string) ($_POST['all_day'] ?? '1') !== '0';
            $fromRaw   = trim((string) ($_POST['start_time'] ?? ''));
            $toRaw     = trim((string) ($_POST['end_time'] ?? ''));
            $note      = trim((string) ($_POST['note'] ?? ''));
            $days      = [];
            foreach ((array) ($_POST['days'] ?? []) as $d) {
                if (is_string($d) && preg_match('/^[0-6]$/', $d)) {
                    $days[] = (int) $d;
                }
            }
            $days = array_values(array_unique($days));
            sort($days);

            $startDate = $parseDate($startRaw);
            $endDate   = $endRaw === '' ? null : $parseDate($endRaw);
            $fromTime  = $allDay ? null : $parseTime($fromRaw);
            $toTime    = $allDay ? null : $parseTime($toRaw);

            if ($startDate === null) {
                $error = $repeat === 'weekly' ? 'Choose the date the repeat starts.' : 'Choose a date.';
            } elseif ($endRaw !== '' && $endDate === null) {
                $error = 'Check the end date.';
            } elseif ($endDate !== null && $endDate < $startDate) {
                $error = 'The end date is before the start date.';
            } elseif ($repeat === 'weekly' && $days === []) {
                $error = 'Tick at least one day of the week.';
            } elseif (!$allDay && ($fromTime === null || $toTime === null)) {
                $error = 'Enter both times, or choose All day.';
            } elseif (!$allDay && $toTime <= $fromTime) {
                $error = 'The end time must be after the start time.  For time past midnight, add a second entry for the next day.';
            } elseif (mb_strlen($note) > 200) {
                $error = 'Keep the note to 200 characters.';
            }

            // A short repeat can miss every ticked day, which would save an
            // entry that never appears on the calendar.
            if ($error === '' && $repeat === 'weekly' && $endDate !== null) {
                $lands = false;
                $d     = new DateTimeImmutable($startDate);
                $stop  = min($endDate, $d->modify('+6 days')->format('Y-m-d'));
                for (; $d->format('Y-m-d') <= $stop; $d = $d->modify('+1 day')) {
                    if (in_array((int) $d->format('w'), $days, true)) {
                        $lands = true;
                        break;
                    }
                }
                if (!$lands) {
                    $error = 'Between those dates the repeat never falls on a day you ticked.';
                }
            }

            if ($error === '') {
                $row = [
                    'user_id'     => $ownerId,
                    'start_date'  => $startDate,
                    'end_date'    => $repeat === 'weekly' ? $endDate : ($endDate ?? $startDate),
                    'repeat_days' => $repeat === 'weekly' ? implode(',', $days) : null,
                    'start_time'  => $fromTime,
                    'end_time'    => $toTime,
                    'note'        => $note === '' ? null : $note,
                ];
                if ($existing === null) {
                    $db->prepare(
                        "INSERT INTO unavailability
                           (user_id, start_date, end_date, repeat_days, start_time, end_time, note, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $row['user_id'], $row['start_date'], $row['end_date'], $row['repeat_days'],
                        $row['start_time'], $row['end_time'], $row['note'], (int) $me['id'],
                    ]);
                } else {
                    $db->prepare(
                        "UPDATE unavailability
                            SET start_date = ?, end_date = ?, repeat_days = ?, start_time = ?, end_time = ?,
                                note = ?, updated_by = ?, updated_at = datetime('now')
                          WHERE id = ?"
                    )->execute([
                        $row['start_date'], $row['end_date'], $row['repeat_days'], $row['start_time'],
                        $row['end_time'], $row['note'], (int) $me['id'], $id,
                    ]);
                }
                $row['days'] = $days;
                $notice = 'Saved: ' . ($canManage ? $labelOf($people[$ownerId]) . ', ' : '') . $describe($row) . '.';
            } else {
                $reopenMode = $existing === null ? 'create' : 'edit';
                $form = [
                    'id'        => $id,
                    'userId'    => $ownerId,
                    'person'    => $labelOf($people[$ownerId]),
                    'repeat'    => $repeat,
                    'days'      => $days,
                    'startDate' => $startRaw,
                    'endDate'   => $endRaw,
                    'allDay'    => $allDay,
                    'startTime' => $fromRaw,
                    'endTime'   => $toRaw,
                    'note'      => $note,
                    'changed'   => $existing === null ? '' : $changedNote($existing),
                ];
            }
        }
    } else {
        $error = 'Unknown action.';
    }

    if ($error === '' && $notice !== '') {
        $_SESSION['flash_notice'] = $notice;
        header('Location: /availability.php?' . $viewQs($monthKey, $filterId));
        exit;
    }
}

if ($notice === '' && !empty($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

// ── Page state ───────────────────────────────────────────────────────────────

$monthEnd  = $monthStart->modify('last day of this month');
$gridStart = $monthStart->modify('-' . (((int) $monthStart->format('w') - $firstDow + 7) % 7) . ' days');
$gridEnd   = $monthEnd->modify('+' . (($firstDow + 6 - (int) $monthEnd->format('w')) % 7) . ' days');

$visibleIds   = $filterId > 0 ? [$filterId] : array_keys($people);
$placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
$stmt = $db->prepare(
    "SELECT a.*, ub.name AS updated_by_name, ub.username AS updated_by_username
     FROM unavailability a
     LEFT JOIN users ub ON ub.id = a.updated_by
     WHERE a.user_id IN ({$placeholders})
       AND a.start_date <= ?
       AND (a.end_date IS NULL OR a.end_date >= ?)"
);
$stmt->execute([...$visibleIds, $gridEnd->format('Y-m-d'), $gridStart->format('Y-m-d')]);
$entries = $stmt->fetchAll();
foreach ($entries as &$e) {
    $e['days'] = $daysOf($e['repeat_days']);
}
unset($e);

// All-day entries first, then by start time, then in the people order.
$personPos = array_flip(array_keys($people));
usort($entries, fn ($a, $b) =>
    [$a['start_time'] !== null, (string) $a['start_time'], $personPos[(int) $a['user_id']]]
    <=> [$b['start_time'] !== null, (string) $b['start_time'], $personPos[(int) $b['user_id']]]
);

$byDay = [];
for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')) {
    $ds  = $d->format('Y-m-d');
    $dow = (int) $d->format('w');
    foreach ($entries as $e) {
        if ($ds < $e['start_date'] || ($e['end_date'] !== null && $ds > $e['end_date'])) {
            continue;
        }
        if ($e['repeat_days'] !== null && !in_array($dow, $e['days'], true)) {
            continue;
        }
        $byDay[$ds][] = $e;
    }
}

$showNames = $canManage && $filterId === 0 && count($people) > 1;

// One chip button, used by both the grid and the phone list.
$chip = function (array $e, bool $long) use ($people, $labelOf, $shortOf, $colourOf, $showNames, $fmtTimes, $describe, $changedNote): string {
    $uid   = (int) $e['user_id'];
    $times = $fmtTimes($e['start_time'], $e['end_time']);
    $text  = $showNames ? $shortOf[$uid] . ' · ' . $times : ucfirst($times);
    if ($long && $e['note'] !== null) {
        $text .= ' — ' . $e['note'];
    }
    $title = $labelOf($people[$uid]) . ': ' . $describe($e) . ($e['note'] !== null ? ' — ' . $e['note'] : '');
    return '<button type="button" class="chip c' . $colourOf[$uid] . ($e['repeat_days'] !== null ? ' is-weekly' : '') . ' js-edit"'
        . ' title="' . h($title) . '"'
        . ' data-id="' . (int) $e['id'] . '"'
        . ' data-user-id="' . $uid . '"'
        . ' data-person="' . h($labelOf($people[$uid])) . '"'
        . ' data-repeat="' . ($e['repeat_days'] !== null ? 'weekly' : 'none') . '"'
        . ' data-days="' . h((string) $e['repeat_days']) . '"'
        . ' data-start-date="' . h((string) $e['start_date']) . '"'
        . ' data-end-date="' . h($e['repeat_days'] !== null || $e['end_date'] !== $e['start_date'] ? (string) $e['end_date'] : '') . '"'
        . ' data-start-time="' . h((string) $e['start_time']) . '"'
        . ' data-end-time="' . h((string) $e['end_time']) . '"'
        . ' data-note="' . h((string) $e['note']) . '"'
        . ' data-changed="' . h($changedNote($e)) . '"'
        . '>' . h($text) . '</button>';
};

$prevMonth    = $monthStart->modify('-1 month');
$nextMonth    = $monthStart->modify('+1 month');
$currentMonth = $today->format('Y-m');

$pageTitle  = 'Availability - Cent Notes';
$activePage = 'availability';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .av-main {
        flex: 1;
        padding: 2rem;
        max-width: 1100px;
        margin: 0 auto;
        width: 100%;
    }

    .av-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: .35rem;
    }

    .av-head h1 { font-size: 1.4rem; font-weight: 700; }

    .av-intro { font-size: .88rem; color: #666; margin-bottom: 1.25rem; }

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

    .btn-primary:hover { background: #2d2d5e; }

    .btn-cancel {
        padding: .5rem 1.1rem;
        background: transparent;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 7px;
        font-size: .82rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-cancel:hover { background: #f0f0f5; border-color: #c8d0e0; }

    .btn-delete {
        margin-right: auto;
        padding: .5rem 1.1rem;
        background: transparent;
        color: #b91c1c;
        border: 1px solid #fca5a5;
        border-radius: 7px;
        font-size: .82rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
    }

    .btn-delete:hover { background: #fff1f2; }
    .btn-delete[hidden] { display: none; }

    /* ── Toolbar ──────────────────────────────────────────────────────────── */
    .av-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .av-toolbar h2 {
        font-size: 1.1rem;
        font-weight: 700;
        min-width: 10.5rem;
        text-align: center;
    }

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

    .av-filter { margin-left: auto; display: flex; align-items: center; gap: .5rem; font-size: .85rem; }

    .av-filter select {
        padding: .4rem .6rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .85rem;
        font-family: inherit;
        background: #fff;
    }

    .legend { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
    .legend .chip { cursor: default; }

    /* ── Chips: one per entry on a day ────────────────────────────────────── */
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
        cursor: pointer;
        overflow-wrap: anywhere;
    }

    .legend .chip { display: inline-block; width: auto; }
    .chip.is-weekly { border-style: dashed; }
    .chip:hover { filter: brightness(.96); }

    .chip.c0 { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
    .chip.c1 { background: #dcfce7; color: #166534; border-color: #86efac; }
    .chip.c2 { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
    .chip.c3 { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
    .chip.c4 { background: #fce7f3; color: #9d174d; border-color: #f9a8d4; }
    .chip.c5 { background: #ccfbf1; color: #115e59; border-color: #5eead4; }
    .chip.c6 { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
    .chip.c7 { background: #e2e8f0; color: #334155; border-color: #94a3b8; }

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

    .cal-day-head { display: flex; align-items: center; justify-content: space-between; }

    .cal-date {
        font-size: .78rem;
        font-weight: 600;
        color: #444;
        min-width: 1.5rem;
        height: 1.5rem;
        line-height: 1.5rem;
        text-align: center;
        border-radius: 99px;
    }

    .cal-add {
        border: none;
        background: transparent;
        color: #9ca3af;
        font-size: 1rem;
        line-height: 1;
        padding: .1rem .35rem;
        border-radius: 4px;
        cursor: pointer;
        opacity: 0;
    }

    .cal-day:hover .cal-add,
    .cal-add:focus { opacity: 1; }

    @media (hover: none) {
        .cal-add { opacity: .6; }
    }
    .cal-add:hover { background: #f0f0f5; color: #1a1a2e; }

    /* ── Phone list: the same month as days with entries ──────────────────── */
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

    .list-empty { padding: 1.5rem 1rem; text-align: center; color: #999; font-size: .88rem; }

    @media (max-width: 720px) {
        .av-main { padding: 1rem; }
        .cal-grid { display: none; }
        .cal-list { display: block; }
        .av-filter { margin-left: 0; width: 100%; }
        .av-toolbar h2 { min-width: 0; flex: 1; }
    }

    /* ── Modal ────────────────────────────────────────────────────────────── */
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

    .modal-body { padding: 1.25rem 1.25rem .25rem; overflow-y: auto; }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
        padding: 1rem 1.25rem;
        border-top: 1px solid #f0f0f0;
        background: #fafbfc;
    }

    .modal-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .9rem 1.1rem;
    }

    .field { display: flex; flex-direction: column; }
    .field.full { grid-column: 1 / -1; }
    .field[hidden] { display: none; }

    .field > label,
    .field-label {
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .field input[type=text],
    .field input[type=date],
    .field input[type=time],
    .field select {
        padding: .5rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .88rem;
        font-family: inherit;
        color: #1a1a2e;
        background: #fff;
    }

    .field input:focus,
    .field select:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .field input:disabled { background: #f5f5f8; color: #888; }

    .field-hint { font-size: .72rem; color: #888; margin-top: .25rem; }

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

    .choice-row.days { gap: .3rem; }
    .choice-row.days .choice { padding: .3rem .45rem; gap: .25rem; font-size: .8rem; }
</style>

<main class="av-main">
    <div class="av-head">
        <h1>Availability</h1>
        <button type="button" class="btn-primary" id="open-create">Mark unavailable</button>
    </div>
    <p class="av-intro">
        <?php if ($canManage): ?>
            The days and times people have said they can't work.  Click an entry to change it.
        <?php else: ?>
            Mark the days and times you can't work.  Click an entry to change it.
        <?php endif; ?>
    </p>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== '' && $reopenMode === ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="av-toolbar">
        <a class="month-link" href="availability.php?<?= h($viewQs($prevMonth->format('Y-m'), $filterId)) ?>" aria-label="Previous month">&lsaquo; <?= h($prevMonth->format('M')) ?></a>
        <h2><?= h($monthStart->format('F Y')) ?></h2>
        <a class="month-link" href="availability.php?<?= h($viewQs($nextMonth->format('Y-m'), $filterId)) ?>" aria-label="Next month"><?= h($nextMonth->format('M')) ?> &rsaquo;</a>
        <?php if ($monthKey !== $currentMonth): ?>
            <a class="month-link" href="availability.php?<?= h($viewQs($currentMonth, $filterId)) ?>">This month</a>
        <?php endif; ?>

        <?php if ($canManage && count($people) > 1): ?>
            <form class="av-filter" method="get" action="availability.php" id="filter-form">
                <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                <label for="filter-user">Show</label>
                <select name="user_id" id="filter-user" onchange="document.getElementById('filter-form').submit()">
                    <option value="0">Everyone</option>
                    <?php foreach ($people as $id => $u): ?>
                        <option value="<?= (int) $id ?>"<?= $id === $filterId ? ' selected' : '' ?>><?= h($labelOf($u)) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-cancel">Show</button></noscript>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($showNames): ?>
        <div class="legend">
            <?php foreach ($people as $id => $u): ?>
                <span class="chip c<?= $colourOf[$id] ?>"><?= h($labelOf($u)) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="cal-grid">
        <?php foreach ($dowOrder as $dow): ?>
            <div class="cal-dow"><?= h($dowShort[$dow]) ?></div>
        <?php endforeach; ?>
        <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
            $ds  = $d->format('Y-m-d');
            $cls = 'cal-day';
            if ($d->format('Y-m') !== $monthKey) { $cls .= ' is-other-month'; }
            if ($ds === $todayDate)               { $cls .= ' is-today'; }
        ?>
            <div class="<?= $cls ?>">
                <div class="cal-day-head">
                    <span class="cal-date"><?= h($d->format('j')) ?></span>
                    <button type="button" class="cal-add js-add" data-date="<?= h($ds) ?>"
                            aria-label="Mark unavailable on <?= h($fmtDate($ds)) ?>" title="Mark unavailable on <?= h($fmtDate($ds)) ?>">+</button>
                </div>
                <?php foreach ($byDay[$ds] ?? [] as $e): ?>
                    <?= $chip($e, false) ?>
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
                <h3><?= h($fmtDate($ds)) ?></h3>
                <?php foreach ($byDay[$ds] as $e): ?>
                    <?= $chip($e, true) ?>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
        <?php if ($listed === 0): ?>
            <div class="list-empty">Nothing marked for <?= h($monthStart->format('F')) ?>.</div>
        <?php endif; ?>
    </div>
</main>

<!-- ── Create / Edit modal ──────────────────────────────────────────────── -->
<div id="av-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="av-modal-title">Mark unavailable</h2>
            <button type="button" class="modal-close" id="av-modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="av-form" method="post" action="availability.php" autocomplete="off">
            <div class="modal-body">
                <div class="error" id="av-modal-error" <?= ($error !== '' && $reopenMode !== '') ? '' : 'hidden' ?>><?= h($error) ?></div>

                <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"     id="av-id" value="">
                <input type="hidden" name="month"  value="<?= h($monthKey) ?>">
                <?php if ($filterId > 0): ?>
                    <input type="hidden" name="user_id" value="<?= (int) $filterId ?>">
                <?php endif; ?>

                <div class="modal-grid">
                    <?php if ($canManage): ?>
                        <div class="field full create-only">
                            <label for="av-user">Who</label>
                            <select id="av-user" name="owner_id">
                                <?php foreach ($people as $id => $u): ?>
                                    <option value="<?= (int) $id ?>"><?= h($labelOf($u)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field full edit-only" hidden>
                            <label for="av-person">Who</label>
                            <input type="text" id="av-person" value="" disabled>
                        </div>
                    <?php endif; ?>

                    <div class="field full">
                        <span class="field-label">Repeats</span>
                        <div class="choice-row">
                            <label class="choice"><input type="radio" name="repeat" value="none" id="av-repeat-none" checked> Specific dates</label>
                            <label class="choice"><input type="radio" name="repeat" value="weekly" id="av-repeat-weekly"> Every week</label>
                        </div>
                    </div>

                    <div class="field full weekly-only" hidden>
                        <span class="field-label">On</span>
                        <div class="choice-row days">
                            <?php foreach ($dowOrder as $dow): ?>
                                <label class="choice"><input type="checkbox" name="days[]" value="<?= $dow ?>" class="js-day"> <?= h($dowShort[$dow]) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="av-start-date" id="av-start-label">From</label>
                        <input type="date" id="av-start-date" name="start_date" required>
                    </div>

                    <div class="field">
                        <label for="av-end-date" id="av-end-label">To</label>
                        <input type="date" id="av-end-date" name="end_date">
                        <div class="field-hint" id="av-end-hint">Leave blank for a single day.</div>
                    </div>

                    <div class="field full">
                        <span class="field-label">Time</span>
                        <div class="choice-row">
                            <label class="choice"><input type="radio" name="all_day" value="1" id="av-all-day" checked> All day</label>
                            <label class="choice"><input type="radio" name="all_day" value="0" id="av-part-day"> Part of the day</label>
                        </div>
                    </div>

                    <div class="field part-day-only" hidden>
                        <label for="av-start-time">From</label>
                        <input type="time" id="av-start-time" name="start_time" required>
                    </div>

                    <div class="field part-day-only" hidden>
                        <label for="av-end-time">To</label>
                        <input type="time" id="av-end-time" name="end_time" required>
                    </div>

                    <div class="field full">
                        <label for="av-note">Note</label>
                        <input type="text" id="av-note" name="note" maxlength="200" placeholder="Optional">
                        <div class="field-hint edit-only" id="av-changed" hidden></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-delete edit-only" form="av-delete-form" hidden
                        data-confirm="Delete this entry?  This can't be undone.">Delete</button>
                <button type="button" class="btn-cancel" id="av-modal-cancel">Cancel</button>
                <button type="submit" class="btn-primary" id="av-submit">Save</button>
            </div>
        </form>
    </div>
</div>

<form id="av-delete-form" method="post" action="availability.php" hidden>
    <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id"     id="av-delete-id" value="">
    <input type="hidden" name="month"  value="<?= h($monthKey) ?>">
    <?php if ($filterId > 0): ?>
        <input type="hidden" name="user_id" value="<?= (int) $filterId ?>">
    <?php endif; ?>
</form>

<script>
(function () {
    'use strict';

    var modal      = document.getElementById('av-modal');
    var titleEl    = document.getElementById('av-modal-title');
    var errEl      = document.getElementById('av-modal-error');
    var idEl       = document.getElementById('av-id');
    var deleteIdEl = document.getElementById('av-delete-id');
    var userEl     = document.getElementById('av-user');
    var personEl   = document.getElementById('av-person');
    var repeatNone = document.getElementById('av-repeat-none');
    var repeatWeek = document.getElementById('av-repeat-weekly');
    var startDate  = document.getElementById('av-start-date');
    var endDate    = document.getElementById('av-end-date');
    var startLabel = document.getElementById('av-start-label');
    var endLabel   = document.getElementById('av-end-label');
    var endHint    = document.getElementById('av-end-hint');
    var allDay     = document.getElementById('av-all-day');
    var partDay    = document.getElementById('av-part-day');
    var startTime  = document.getElementById('av-start-time');
    var endTime    = document.getElementById('av-end-time');
    var noteEl     = document.getElementById('av-note');
    var changedEl  = document.getElementById('av-changed');
    var dayBoxes   = document.querySelectorAll('.js-day');

    var ME_ID     = <?= json_encode((int) $me['id']) ?>;
    var FILTER_ID = <?= json_encode($filterId) ?>;
    var TODAY     = <?= json_encode($todayDate) ?>;

    // Hidden sections also disable their controls, so nothing in them submits.
    function setSection(selector, on) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.hidden = !on;
            el.querySelectorAll('input, select').forEach(function (c) {
                // The read-only person box stays disabled; it only displays.
                if (c !== personEl) { c.disabled = !on; }
            });
        });
    }

    function showCreateOnlyFields(show) {
        setSection('.create-only', show);
        setSection('.edit-only', !show);
    }

    function weekdayOf(ymd) {
        var p = ymd.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2]).getDay();
    }

    function syncRepeat() {
        var weekly = repeatWeek.checked;
        setSection('.weekly-only', weekly);
        startLabel.textContent = weekly ? 'Starting' : 'From';
        endLabel.textContent   = weekly ? 'Until' : 'To';
        endHint.textContent    = weekly ? 'Leave blank to keep repeating.' : 'Leave blank for a single day.';

        // Switching to a repeat with nothing ticked: start from the start date's day.
        var anyTicked = Array.prototype.some.call(dayBoxes, function (b) { return b.checked; });
        if (weekly && !anyTicked && startDate.value) {
            var dow = String(weekdayOf(startDate.value));
            dayBoxes.forEach(function (b) { b.checked = b.value === dow; });
        }
    }

    function syncTime() {
        setSection('.part-day-only', partDay.checked);
    }

    function fill(data) {
        (data.repeat === 'weekly' ? repeatWeek : repeatNone).checked = true;
        var days = (data.days || '').toString().split(',');
        dayBoxes.forEach(function (b) { b.checked = days.indexOf(b.value) !== -1; });
        startDate.value = data.startDate || '';
        endDate.value   = data.endDate || '';
        var timed = !!(data.startTime || data.endTime) || data.allDay === false;
        (timed ? partDay : allDay).checked = true;
        startTime.value = data.startTime || '';
        endTime.value   = data.endTime || '';
        noteEl.value    = data.note || '';
        syncRepeat();
        syncTime();
    }

    function openCreate(prefill) {
        prefill = prefill || {};
        titleEl.textContent = 'Mark unavailable';
        idEl.value = '';
        showCreateOnlyFields(true);
        if (userEl) {
            userEl.value = String(prefill.userId || FILTER_ID || ME_ID);
        }
        fill({
            repeat:    prefill.repeat || 'none',
            days:      prefill.days || '',
            startDate: prefill.startDate || TODAY,
            endDate:   prefill.endDate || '',
            allDay:    prefill.allDay,
            startTime: prefill.startTime || '',
            endTime:   prefill.endTime || '',
            note:      prefill.note || '',
        });
        modal.hidden = false;
        setTimeout(function () { startDate.focus(); }, 30);
    }

    function openEdit(data) {
        titleEl.textContent = 'Change unavailable time';
        idEl.value       = data.id;
        deleteIdEl.value = data.id;
        showCreateOnlyFields(false);
        if (personEl) { personEl.value = data.person || ''; }
        changedEl.textContent = data.changed || '';
        changedEl.hidden      = !data.changed;
        fill(data);
        modal.hidden = false;
        setTimeout(function () { startDate.focus(); }, 30);
    }

    function closeModal() {
        modal.hidden = true;
    }

    function clearModalError() {
        errEl.hidden = true;
        errEl.textContent = '';
    }

    repeatNone.addEventListener('change', syncRepeat);
    repeatWeek.addEventListener('change', syncRepeat);
    allDay.addEventListener('change', syncTime);
    partDay.addEventListener('change', syncTime);

    document.getElementById('open-create').addEventListener('click', function () {
        clearModalError();
        openCreate();
    });

    document.querySelectorAll('.js-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            clearModalError();
            openCreate({ startDate: btn.dataset.date });
        });
    });

    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            clearModalError();
            openEdit({
                id:        btn.dataset.id,
                person:    btn.dataset.person,
                repeat:    btn.dataset.repeat,
                days:      btn.dataset.days,
                startDate: btn.dataset.startDate,
                endDate:   btn.dataset.endDate,
                startTime: btn.dataset.startTime,
                endTime:   btn.dataset.endTime,
                note:      btn.dataset.note,
                changed:   btn.dataset.changed,
            });
        });
    });

    document.getElementById('av-modal-close').addEventListener('click', closeModal);
    document.getElementById('av-modal-cancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
    });

    // The Delete button sits in the modal but submits the separate delete form.
    document.getElementById('av-delete-form').addEventListener('submit', function (e) {
        var msg = e.submitter && e.submitter.dataset.confirm;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    <?php if ($reopenMode === 'create'): ?>
        openCreate(<?= json_encode($form) ?>);
        errEl.hidden = false;
    <?php elseif ($reopenMode === 'edit'): ?>
        openEdit(<?= json_encode($form) ?>);
        errEl.hidden = false;
    <?php endif; ?>
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
