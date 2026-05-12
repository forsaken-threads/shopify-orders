<?php
declare(strict_types=1);

/**
 * Time-clock helpers.
 *
 * All punch timestamps are stored in UTC ('YYYY-MM-DD HH:MM:SS'); the
 * pay-period week boundaries are computed in the display timezone so a
 * Sunday in California means the same thing here as it does on the wall
 * clock there.
 *
 * Functions in this file are pure helpers — none of them write to the
 * database except clockIn() / clockOut(), and those compose at the very
 * top of the request layer (POST handlers in /clock.php and /timecards.php).
 */

require_once __DIR__ . '/db.php';

/**
 * Returns the open (clock_out IS NULL) punch row for the given user, or
 * null when not clocked in.  At most one open punch should exist per user
 * — clockIn() refuses to create a second one — but defensively we order
 * by clock_in DESC and take the most recent.
 */
function findOpenPunch(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, user_id, clock_in, clock_out, notes
         FROM time_punches
         WHERE user_id = ? AND clock_out IS NULL
         ORDER BY clock_in DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * True when the open shift was clocked in on a calendar day before today
 * (display TZ).  Used as the cutoff between "you might still be on shift"
 * (today) and "you definitely forgot to clock out" (any earlier day).
 * Stale shifts can't be closed by the employee — they need an admin to
 * set the correct clock_out time via /timecards.php.
 */
function isOpenPunchStale(array $punch, DateTimeImmutable $nowUtc, DateTimeZone $tz): bool
{
    $clockInLocal = (new DateTimeImmutable($punch['clock_in']))->setTimezone($tz);
    $todayLocal   = $nowUtc->setTimezone($tz);
    return $clockInLocal->format('Y-m-d') !== $todayLocal->format('Y-m-d');
}

/**
 * Start of the current pay-period week, expressed as a UTC instant.
 * Computed in the display timezone (so Sunday-or-Monday at 00:00 means
 * the local midnight, not UTC midnight), then converted back to UTC for
 * queries against time_punches.clock_in.
 */
function weekStartUtc(DateTimeImmutable $nowUtc, DateTimeZone $tz, string $payWeekStart): DateTimeImmutable
{
    $local    = $nowUtc->setTimezone($tz);
    $dow      = (int) $local->format('w');                     // 0=Sun ... 6=Sat
    $startDow = match (strtolower($payWeekStart)) {
        'mon'   => 1,
        'sat'   => 6,
        default => 0,                                          // 'sun' + fallback
    };
    $offset = ($dow - $startDow + 7) % 7;
    return $local
        ->setTime(0, 0, 0)
        ->modify("-{$offset} days")
        ->setTimezone(new DateTimeZone('UTC'));
}

/**
 * The local-calendar date string ('YYYY-MM-DD') of the current pay-period
 * week's first day.  Used as the lookup key in timecard_approvals so an
 * approval that "covers the week of May 5" is identified consistently
 * regardless of UTC drift.
 */
function weekStartLocalDate(DateTimeImmutable $nowUtc, DateTimeZone $tz, string $payWeekStart): string
{
    return weekStartUtc($nowUtc, $tz, $payWeekStart)->setTimezone($tz)->format('Y-m-d');
}

/**
 * True when the (user, week) pair has an approval row.  Approved weeks
 * are read-only — no new punches, no edits, no clock-in/out actions.
 */
function isWeekApproved(PDO $db, int $userId, string $weekStartLocalDate): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM timecard_approvals WHERE user_id = ? AND week_start_date = ?"
    );
    $stmt->execute([$userId, $weekStartLocalDate]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Sum minutes worked across the given punches.  Open shifts (clock_out
 * NULL) accumulate up to $nowUtc, so the "current week" total ticks
 * forward live while someone is on the clock.
 */
function totalMinutes(array $punches, DateTimeImmutable $nowUtc): int
{
    $minutes = 0;
    foreach ($punches as $p) {
        $in  = new DateTimeImmutable((string) $p['clock_in']);
        $out = !empty($p['clock_out'])
            ? new DateTimeImmutable((string) $p['clock_out'])
            : $nowUtc;
        $minutes += (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
    }
    return $minutes;
}

/**
 * Format a minute count as "Xh YYm".  An open shift with 47 minutes
 * elapsed renders as "0h 47m"; a long week renders as "27h 12m".
 */
function formatHours(int $minutes): string
{
    $minutes = max(0, $minutes);
    return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
}

/**
 * Convert a UTC timestamp string ('YYYY-MM-DD HH:MM:SS') into the
 * 'YYYY-MM-DDTHH:MM' string an <input type="datetime-local"> wants.
 * Useful for pre-populating the punch-edit modal.
 */
function utcToLocalInput(string $utcDt, DateTimeZone $tz): string
{
    return (new DateTimeImmutable($utcDt))
        ->setTimezone($tz)
        ->format('Y-m-d\TH:i');
}

/**
 * Inverse of utcToLocalInput.  Returns the UTC timestamp string suitable
 * for inserting into time_punches, or null if the input is empty or
 * unparsable.  Browser sends a value like "2026-05-11T09:14" with no
 * timezone — we treat it as wall-clock time in the display timezone.
 */
function localInputToUtc(string $input, DateTimeZone $tz): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($input, $tz))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (\Exception) {
        return null;
    }
}

/**
 * Return the local-date string ('YYYY-MM-DD') of the pay-period week
 * that the given UTC instant falls into.  Used to check whether an
 * edited punch is moving into / out of an approved week.
 */
function weekStartDateFor(string $utcDt, DateTimeZone $tz, string $payWeekStart): string
{
    return weekStartLocalDate(new DateTimeImmutable($utcDt), $tz, $payWeekStart);
}

/**
 * Snap an arbitrary local-calendar date string ('YYYY-MM-DD') to the
 * pay-week-start date that contains it.  Used by the rate-history forms
 * on /users.php so admins can type any date and the row stores the
 * pay-week boundary that the rest of the timeclock code expects.
 */
function snapToPayWeekStart(string $dateStr, DateTimeZone $tz, string $payWeekStart): string
{
    $local = new DateTimeImmutable($dateStr . ' 00:00:00', $tz);
    return weekStartLocalDate($local->setTimezone(new DateTimeZone('UTC')), $tz, $payWeekStart);
}

/**
 * Decode a user row's preferences JSON and return whether the user is
 * paid hourly.  Defaults to false on a missing flag or unparseable JSON.
 */
function isUserPaidHourly(array $user): bool
{
    $prefs = $user['preferences'] ?? null;
    if (is_string($prefs)) {
        $prefs = json_decode($prefs, true) ?: [];
    } elseif (!is_array($prefs)) {
        $prefs = [];
    }
    return !empty($prefs['paid_hourly']);
}

/**
 * Look up the hourly rate that was in effect for the user during the
 * pay-period week starting on $weekStartDate.  Returns the matching
 * hourly_rates row or null when no row covers that week.
 *
 * Overlaps are forbidden at insert time, so at most one row matches.
 */
function effectiveRateFor(PDO $db, int $userId, string $weekStartDate): ?array
{
    $stmt = $db->prepare(
        "SELECT id, hourly_rate, effective_from, effective_to
         FROM hourly_rates
         WHERE user_id = ?
           AND (effective_from IS NULL OR effective_from <= ?)
           AND (effective_to   IS NULL OR effective_to   >= ?)
         LIMIT 1"
    );
    $stmt->execute([$userId, $weekStartDate, $weekStartDate]);
    return $stmt->fetch() ?: null;
}

/**
 * Check whether a [from, to] range (either bound nullable) overlaps any
 * existing hourly_rates row for the user.  Ignores the row with id
 * $ignoreId, used by the edit flow so a row's own range doesn't
 * conflict with itself.
 */
function hourlyRateRangeOverlaps(
    PDO $db,
    int $userId,
    ?string $from,
    ?string $to,
    ?int $ignoreId = null
): bool {
    // Two inclusive ranges [a,b] and [c,d] overlap iff (a <= d) AND (c <= b),
    // where NULL on a low bound = -inf and NULL on a high bound = +inf.
    $sql = "SELECT 1 FROM hourly_rates
            WHERE user_id = ?
              AND (effective_from IS NULL OR ? IS NULL OR effective_from <= ?)
              AND (effective_to   IS NULL OR ? IS NULL OR effective_to   >= ?)";
    $args = [$userId, $to, $to, $from, $from];
    if ($ignoreId !== null) {
        $sql  .= " AND id != ?";
        $args[] = $ignoreId;
    }
    $sql .= " LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return (bool) $stmt->fetchColumn();
}

/**
 * Open a new shift for $userId.  Caller must verify there's no existing
 * open punch and that the user's current pay week isn't approved.
 */
function clockIn(PDO $db, int $userId): void
{
    $db->prepare(
        "INSERT INTO time_punches (user_id, clock_in) VALUES (?, datetime('now'))"
    )->execute([$userId]);
}

/**
 * Close the open shift for $userId at "now".  Caller must verify there
 * IS an open punch and it isn't stale (different local day).
 */
function clockOut(PDO $db, int $userId): void
{
    $db->prepare(
        "UPDATE time_punches
         SET clock_out = datetime('now')
         WHERE user_id = ? AND clock_out IS NULL"
    )->execute([$userId]);
}
