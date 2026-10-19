<?php
declare(strict_types=1);

/**
 * Schedule: each person's regular week, and the changes made to single days.
 *
 * The two tables are described in scripts/migrate.php.  A shift here is one
 * regular day as it currently stands, wherever it has been moved to:
 *
 *   shift_date  the regular day it belongs to, which is what identifies it
 *   date        the day it is worked: new_date for a moved shift
 *   state       'regular'     worked on its own day at its regular times
 *               'changed'     worked on its own day at times an admin set
 *               'moved'       worked on another day
 *               'off'         not worked
 *               'moved_away'  the marker scheduleShifts() leaves on the
 *                             regular day of a moved shift
 *
 * Dates are local 'Y-m-d' and times local 'H:i'; nothing here touches a
 * timezone.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const SCHEDULE_WORKED = ['regular', 'changed', 'moved'];

/**
 * Regular weeks for the given users: [user_id][weekday] => start_time, end_time.
 */
function scheduleRegularWeeks(PDO $db, array $userIds): array
{
    if ($userIds === []) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare("SELECT user_id, weekday, start_time, end_time FROM schedule_shifts WHERE user_id IN ({$in})");
    $stmt->execute(array_values($userIds));
    $weeks = [];
    foreach ($stmt->fetchAll() as $r) {
        $weeks[(int) $r['user_id']][(int) $r['weekday']] = [
            'start_time' => (string) $r['start_time'],
            'end_time'   => (string) $r['end_time'],
        ];
    }
    return $weeks;
}

/**
 * One shift from its regular times and its change row, if it has one.
 */
function scheduleMakeShift(int $userId, string $shiftDate, array $regular, ?array $change): array
{
    $state = match (true) {
        $change === null                    => 'regular',
        $change['new_date'] === null        => 'off',
        $change['new_date'] === $shiftDate  => 'changed',
        default                             => 'moved',
    };
    return [
        'user_id'       => $userId,
        'shift_date'    => $shiftDate,
        'date'          => $change['new_date'] ?? $shiftDate,
        'start_time'    => $change['start_time'] ?? $regular['start_time'],
        'end_time'      => $change['end_time'] ?? $regular['end_time'],
        'regular_start' => $regular['start_time'],
        'regular_end'   => $regular['end_time'],
        'state'         => $state,
        'changed_by'    => $change === null ? null : $change['changed_by'],
    ];
}

/**
 * Every shift, off day and moved-away marker showing on a day from $from to
 * $to inclusive, for the given users.  Unsorted.
 */
function scheduleShifts(PDO $db, array $userIds, string $from, string $to): array
{
    if ($userIds === []) {
        return [];
    }
    $regular = scheduleRegularWeeks($db, $userIds);

    $in   = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare(
        "SELECT user_id, shift_date, new_date, start_time, end_time, changed_by
         FROM schedule_changes
         WHERE user_id IN ({$in})
           AND (shift_date BETWEEN ? AND ? OR new_date BETWEEN ? AND ?)"
    );
    $stmt->execute([...array_values($userIds), $from, $to, $from, $to]);
    $changes = [];
    foreach ($stmt->fetchAll() as $c) {
        $changes[(int) $c['user_id']][(string) $c['shift_date']] = $c;
    }

    $shifts = [];
    $end    = new DateTimeImmutable($to);
    for ($d = new DateTimeImmutable($from); $d <= $end; $d = $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d');
        $w  = (int) $d->format('w');
        foreach ($userIds as $uid) {
            $reg = $regular[(int) $uid][$w] ?? null;
            if ($reg === null) {
                continue;
            }
            $shift = scheduleMakeShift((int) $uid, $ds, $reg, $changes[(int) $uid][$ds] ?? null);
            if ($shift['state'] === 'moved') {
                $shifts[] = ['state' => 'moved_away', 'moved_to' => $shift['date'], 'date' => $ds] + $shift;
            } elseif ($shift['state'] !== 'changed') {
                $shifts[] = $shift;
            }
        }
    }

    // Moved and changed shifts show on their new day, which may be in range
    // while their regular day is not.
    foreach ($changes as $uid => $byDate) {
        foreach ($byDate as $sd => $c) {
            $nd = $c['new_date'];
            if ($nd === null || $nd < $from || $nd > $to) {
                continue;
            }
            $reg = $regular[$uid][(int) (new DateTimeImmutable((string) $sd))->format('w')] ?? null;
            if ($reg !== null) {
                $shifts[] = scheduleMakeShift($uid, (string) $sd, $reg, $c);
            }
        }
    }
    return $shifts;
}

/**
 * The shift belonging to one regular day, or null when the person's regular
 * week has no shift on that weekday.
 */
function scheduleShiftFor(PDO $db, int $userId, string $shiftDate): ?array
{
    $w   = (int) (new DateTimeImmutable($shiftDate))->format('w');
    $reg = scheduleRegularWeeks($db, [$userId])[$userId][$w] ?? null;
    if ($reg === null) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT new_date, start_time, end_time, changed_by FROM schedule_changes WHERE user_id = ? AND shift_date = ?"
    );
    $stmt->execute([$userId, $shiftDate]);
    return scheduleMakeShift($userId, $shiftDate, $reg, $stmt->fetch() ?: null);
}

/**
 * True when the person works on $date, not counting the shift whose regular
 * day is $exceptShiftDate.  One shift a day is the rule a move has to keep.
 */
function scheduleWorksOn(PDO $db, int $userId, string $date, string $exceptShiftDate): bool
{
    foreach (scheduleShifts($db, [$userId], $date, $date) as $s) {
        if ($s['date'] === $date && $s['shift_date'] !== $exceptShiftDate && in_array($s['state'], SCHEDULE_WORKED, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Record where a regular day's shift is worked, and at what times.  A shift
 * back on its own day at its regular times needs no row, so it has none.
 */
function scheduleSetChange(PDO $db, int $userId, string $shiftDate, ?string $newDate, ?string $startTime, ?string $endTime, int $byUserId): void
{
    if ($newDate === $shiftDate && $startTime === null && $endTime === null) {
        $db->prepare("DELETE FROM schedule_changes WHERE user_id = ? AND shift_date = ?")->execute([$userId, $shiftDate]);
        return;
    }
    $db->prepare(
        "INSERT INTO schedule_changes (user_id, shift_date, new_date, start_time, end_time, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
         ON CONFLICT (user_id, shift_date) DO UPDATE SET
             new_date = excluded.new_date, start_time = excluded.start_time, end_time = excluded.end_time,
             changed_by = excluded.changed_by, changed_at = excluded.changed_at"
    )->execute([$userId, $shiftDate, $newDate, $startTime, $endTime, $byUserId]);
}

function scheduleFormatDate(string $ymd): string
{
    return (new DateTimeImmutable($ymd))->format('D, M j');
}

/**
 * '9 AM – 1 PM', or '9–11 AM' when both ends share a half of the day.
 */
function scheduleFormatTimes(string $from, string $to): string
{
    $fmt = static function (string $hm): string {
        $t = DateTimeImmutable::createFromFormat('!H:i', $hm);
        return $t->format($t->format('i') === '00' ? 'g A' : 'g:i A');
    };
    $a = $fmt($from);
    $b = $fmt($to);
    if (substr($a, -2) === substr($b, -2)) {
        return substr($a, 0, -3) . '–' . $b;
    }
    return $a . ' – ' . $b;
}

/**
 * Where a shift stands, as a phrase: 'Mon, Oct 20, 9 AM – 1 PM' or 'Off'.
 */
function scheduleDescribe(array $shift): string
{
    if ($shift['state'] === 'off') {
        return 'Off';
    }
    return scheduleFormatDate($shift['date']) . ', ' . scheduleFormatTimes($shift['start_time'], $shift['end_time']);
}

/**
 * Active users with the admin role and an email on file: who hears about an
 * employee's change.
 */
function scheduleAdminRecipients(PDO $db): array
{
    return $db->query(
        "SELECT name, username, email FROM users
         WHERE role = 'admin' AND is_active = 1 AND TRIM(COALESCE(email, '')) != ''"
    )->fetchAll();
}

/**
 * Email each recipient about a change an employee made to their own shift.
 * $before and $after are that shift either side of the change.  A failed
 * send is logged by sendMail() and not retried: the change is already on the
 * schedule, and this is only the note that it happened.
 */
function mailScheduleChange(array $config, array $recipients, string $person, array $before, array $after): void
{
    $day     = scheduleFormatDate($before['shift_date']);
    $summary = match (true) {
        $after['state'] === 'off'                                         => "{$person} took {$day} off",
        $after['date'] === $after['shift_date']                           => "{$person} put {$day} back",
        default => "{$person} moved {$day} to " . scheduleFormatDate($after['date']),
    };
    $rows = [
        'Regular day' => $day . ', ' . scheduleFormatTimes($before['regular_start'], $before['regular_end']),
        'Was'         => scheduleDescribe($before),
        'Now'         => scheduleDescribe($after),
    ];
    $link = $config['app_base_url'] !== ''
        ? $config['app_base_url'] . '/schedule.php?month=' . substr($after['date'], 0, 7)
        : '';

    $text = $summary . ".\n\n";
    foreach ($rows as $label => $value) {
        $text .= $label . ': ' . $value . "\n";
    }
    if ($link !== '') {
        $text .= "\nSee the schedule:\n" . $link . "\n";
    }

    $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p style="font-family:sans-serif;font-size:15px">' . $e($summary) . '.</p>'
          . '<table style="font-family:sans-serif;font-size:14px;border-collapse:collapse">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="padding:2px 12px 2px 0;color:#666">' . $e($label) . '</td><td style="padding:2px 0">' . $e($value) . '</td></tr>';
    }
    $html .= '</table>';
    if ($link !== '') {
        $html .= '<p style="font-family:sans-serif;font-size:14px"><a href="' . $e($link) . '">See the schedule</a></p>';
    }

    foreach ($recipients as $r) {
        $name = trim((string) $r['name']) !== '' ? (string) $r['name'] : (string) $r['username'];
        sendMail($config, (string) $r['email'], $name, 'Schedule change: ' . $summary, $html, $text);
    }
}
