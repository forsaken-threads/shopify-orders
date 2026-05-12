<?php
declare(strict_types=1);

/**
 * User management page.
 *
 * Shows every user in the system (active or not) and lets the operator
 * create new accounts, edit display name and email on existing ones,
 * trigger a password-reset email, and toggle active/inactive.
 *
 * No delete — keep the row around so historical references to the user_id
 * (e.g. password_resets) stay coherent.
 *
 * Gated on the 'manage_users' permission (admin and root only).  Lower-tier
 * roles can't even see the page exists — the menu link is hidden and a
 * direct visit redirects to /index.php via requirePermission().
 *
 * Form actions (POST, CSRF-protected):
 *   action=create          — add a new user (username + password required;
 *                             name + email optional).
 *   action=update          — change the display name and/or email of an
 *                             existing user.  Username and password are not
 *                             touched here — password changes go through the
 *                             password-reset flow.
 *   action=reset_password  — email a one-hour single-use reset link to the
 *                             user; rejected if they have no email on file.
 *   action=toggle          — flip is_active for the given user_id.  Users
 *                             cannot deactivate themselves.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';
require_once __DIR__ . '/../app/password-reset.php';
require_once __DIR__ . '/../app/timeclock.php';

$me     = requirePermission($config, 'manage_users');
$db     = getDb($config);
$myRank = userRoleRank($me);
$tz     = new DateTimeZone((string) ($config['display_timezone'] ?? 'UTC'));
$pws    = (string) $config['pay_week_start'];

$notice = '';
$error  = '';

// Sticky form state — populated when a create or update attempt fails so
// the modal can be re-opened with the non-secret fields still filled in.
$form = ['id' => '', 'username' => '', 'name' => '', 'email' => '', 'role' => 'basic_employee', 'paid_hourly' => false];

// Set when an error in a modal action should re-open the modal on reload.
$reopenMode = '';   // 'create' | 'edit' | ''

// When set, the page renders with the hourly-rates modal auto-opened for
// this user.  Populated either by ?manage_rates=<id> in the URL or by a
// rate-management POST handler that wants the modal re-opened.
$manageRatesUserId = 0;
// When set inside the rates modal, render this rate row as an inline edit
// form instead of static cells.  Cleared after a successful update_rate.
$editRateId        = 0;
// Sticky form state for the rates modal's add / edit row when validation
// fails server-side and we want to re-render with the entered values.
$rateForm          = ['id' => 0, 'mode' => '', 'from' => '', 'to' => '', 'rate' => '', 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $form['username'] = trim((string) ($_POST['username'] ?? ''));
            $form['name']     = trim((string) ($_POST['name']     ?? ''));
            $form['email']    = trim((string) ($_POST['email']    ?? ''));
            $form['role']     = (string) ($_POST['role'] ?? 'basic_employee');
            $password         = (string) ($_POST['password']         ?? '');
            $confirm          = (string) ($_POST['password_confirm'] ?? '');

            if ($form['username'] === '') {
                $error = 'Username is required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (!hash_equals($password, $confirm)) {
                $error = 'Passwords did not match.';
            } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (!in_array($form['role'], ROLES, true)) {
                $error = 'Invalid role.';
            } elseif (roleRank($form['role']) > $myRank) {
                $error = "You can't grant a role higher than your own.";
            } else {
                $exists = $db->prepare("SELECT 1 FROM users WHERE username = ?");
                $exists->execute([$form['username']]);
                if ($exists->fetchColumn()) {
                    $error = 'A user with that username already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare(
                        "INSERT INTO users (username, password_hash, name, email, role, preferences, is_active)
                         VALUES (?, ?, ?, ?, ?, '{}', 1)"
                    )->execute([
                        $form['username'],
                        $hash,
                        $form['name'],
                        $form['email'] !== '' ? $form['email'] : null,
                        $form['role'],
                    ]);
                    $notice = 'Created user "' . $form['username'] . '".';
                    $form   = ['id' => '', 'username' => '', 'name' => '', 'email' => '', 'role' => 'basic_employee'];
                }
            }
            if ($error !== '') {
                $reopenMode = 'create';
            }
        } elseif ($action === 'update') {
            $form['id']    = (string) (int) ($_POST['user_id'] ?? 0);
            $form['name']  = trim((string) ($_POST['name']  ?? ''));
            $form['email'] = trim((string) ($_POST['email'] ?? ''));
            $form['paid_hourly'] = isset($_POST['paid_hourly']);
            $postedRole    = isset($_POST['role']) ? (string) $_POST['role'] : null;

            $targetId = (int) $form['id'];

            // Look up the target so we can enforce hierarchy rules.  Also
            // grab preferences so we can merge the paid_hourly flag rather
            // than blow away other keys (e.g. last_version_seen).
            $stmt = $db->prepare("SELECT id, username, role, preferences FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch() ?: null;

            if ($targetId <= 0 || $target === null) {
                $error = 'Invalid user.';
            } elseif (roleRank((string) $target['role']) > $myRank) {
                $error = "You can't edit a user whose role is higher than yours.";
            } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $form['username'] = (string) $target['username'];

                // Role only changes for non-self edits.  The modal hides
                // the selector when editing self, so a posted 'role' field
                // in that case is either bogus or stale — ignore it.
                $isSelfEdit = (int) $target['id'] === (int) $me['id'];
                $finalRole  = (string) $target['role'];

                if (!$isSelfEdit && $postedRole !== null) {
                    if (!in_array($postedRole, ROLES, true)) {
                        $error = 'Invalid role.';
                    } elseif (roleRank($postedRole) > $myRank) {
                        $error = "You can't grant a role higher than your own.";
                    } else {
                        $finalRole = $postedRole;
                    }
                }
                $form['role'] = $finalRole;

                if ($error === '') {
                    $prefs = json_decode((string) ($target['preferences'] ?? '{}'), true);
                    if (!is_array($prefs)) {
                        $prefs = [];
                    }
                    $prefs['paid_hourly'] = (bool) $form['paid_hourly'];

                    $db->prepare(
                        "UPDATE users
                         SET name = ?, email = ?, role = ?, preferences = ?, updated_at = datetime('now')
                         WHERE id = ?"
                    )->execute([
                        $form['name'],
                        $form['email'] !== '' ? $form['email'] : null,
                        $finalRole,
                        json_encode($prefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $targetId,
                    ]);
                    $notice = 'Updated user.';
                    $form   = ['id' => '', 'username' => '', 'name' => '', 'email' => '', 'role' => 'basic_employee', 'paid_hourly' => false];
                }
            }
            if ($error !== '') {
                $reopenMode = 'edit';
            }
        } elseif (in_array($action, ['add_rate', 'update_rate', 'delete_rate'], true)) {
            // Rate-management actions all share the same target-user guard and
            // PRG back to ?manage_rates=<id> so the modal stays open.
            $targetId = (int) ($_POST['user_id'] ?? 0);

            $stmt = $db->prepare("SELECT id, name, username, role, preferences FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch() ?: null;

            if ($targetId <= 0 || $target === null) {
                $error = 'Invalid user.';
            } elseif (roleRank((string) $target['role']) > $myRank) {
                $error = "You can't manage a user whose role is higher than yours.";
            } elseif (!isUserPaidHourly($target)) {
                $error = "That user isn't flagged as paid hourly.";
            } else {
                $rateId = (int) ($_POST['rate_id'] ?? 0);

                if ($action === 'delete_rate') {
                    $db->prepare("DELETE FROM hourly_rates WHERE id = ? AND user_id = ?")
                       ->execute([$rateId, $targetId]);
                    $notice = 'Rate removed.';
                } else {
                    // add_rate or update_rate.  Validate and snap dates.
                    $rateRaw = trim((string) ($_POST['hourly_rate']    ?? ''));
                    $fromRaw = trim((string) ($_POST['effective_from'] ?? ''));
                    $toRaw   = trim((string) ($_POST['effective_to']   ?? ''));

                    $rateForm = [
                        'id'    => $rateId,
                        'mode'  => $action === 'update_rate' ? 'edit' : 'add',
                        'from'  => $fromRaw,
                        'to'    => $toRaw,
                        'rate'  => $rateRaw,
                        'error' => '',
                    ];

                    $rateVal = is_numeric($rateRaw) ? (float) $rateRaw : -1.0;

                    $fromSnap = $fromRaw !== '' ? snapToPayWeekStart($fromRaw, $tz, $pws) : null;
                    $toSnap   = $toRaw   !== '' ? snapToPayWeekStart($toRaw,   $tz, $pws) : null;

                    if ($rateVal <= 0) {
                        $rateForm['error'] = 'Hourly rate must be greater than zero.';
                    } elseif ($fromSnap !== null && $toSnap !== null && $fromSnap > $toSnap) {
                        $rateForm['error'] = 'Effective-from week must be on or before effective-to week.';
                    } elseif (hourlyRateRangeOverlaps($db, $targetId, $fromSnap, $toSnap, $action === 'update_rate' ? $rateId : null)) {
                        // Surface the snapped values so it's obvious why
                        // "Apr 26" (a Sunday) conflicts with a rate that
                        // ends "Apr 25" — both fall in the same pay-week.
                        $fromLabel = $fromSnap !== null ? (new DateTimeImmutable($fromSnap))->format('M j, Y') : 'the beginning of time';
                        $toLabel   = $toSnap   !== null ? (new DateTimeImmutable($toSnap))->format('M j, Y')   : 'open-ended';
                        $rateForm['error'] = "That range (pay weeks {$fromLabel} → {$toLabel}) overlaps an existing rate.  Adjust the other row's dates first.";
                    } else {
                        if ($action === 'add_rate') {
                            $db->prepare(
                                "INSERT INTO hourly_rates (user_id, hourly_rate, effective_from, effective_to)
                                 VALUES (?, ?, ?, ?)"
                            )->execute([$targetId, $rateVal, $fromSnap, $toSnap]);
                            $notice = 'Rate added.';
                        } else {
                            $upd = $db->prepare(
                                "UPDATE hourly_rates
                                    SET hourly_rate = ?, effective_from = ?, effective_to = ?
                                  WHERE id = ? AND user_id = ?"
                            );
                            $upd->execute([$rateVal, $fromSnap, $toSnap, $rateId, $targetId]);
                            if ($upd->rowCount() === 0) {
                                $rateForm['error'] = 'Rate not found.';
                            } else {
                                $notice = 'Rate updated.';
                            }
                        }
                    }

                    if ($rateForm['error'] !== '') {
                        // Stay on the rates modal with the form re-rendered.
                        $error              = $rateForm['error'];
                        $manageRatesUserId  = $targetId;
                        if ($action === 'update_rate') {
                            $editRateId = $rateId;
                        }
                    }
                }
            }

            // PRG back to the rates modal on success so the admin keeps editing.
            if ($error === '' && $notice !== '') {
                $_SESSION['flash_notice'] = $notice;
                header('Location: /users.php?' . http_build_query(['manage_rates' => $targetId]));
                exit;
            }
        } elseif ($action === 'reset_password') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId <= 0) {
                $error = 'Invalid user.';
            } else {
                $stmt = $db->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
                $stmt->execute([$targetId]);
                $u = $stmt->fetch();
                $email = trim((string) ($u['email'] ?? ''));
                if (!$u) {
                    $error = 'User not found.';
                } elseif (roleRank((string) $u['role']) > $myRank) {
                    $error = "You can't reset a user whose role is higher than yours.";
                } elseif ((int) $u['is_active'] !== 1) {
                    $error = "Can't reset — that account is inactive.";
                } elseif ($email === '') {
                    $error = "Can't reset — that user has no email on file.";
                } elseif (generateAndEmailReset($config, (int) $u['id'], (string) $u['name'], $email)) {
                    $notice = 'Sent a reset link to ' . $email . '.';
                } else {
                    $error = 'Failed to send the reset email; check the server logs.';
                }
            }
        } elseif ($action === 'toggle') {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            if ($targetId <= 0) {
                $error = 'Invalid user.';
            } elseif ($targetId === (int) $me['id']) {
                $error = "You can't deactivate your own account.";
            } else {
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$targetId]);
                $row = $stmt->fetch() ?: null;
                if ($row === null) {
                    $error = 'User not found.';
                } elseif (roleRank((string) $row['role']) > $myRank) {
                    $error = "You can't change a user whose role is higher than yours.";
                } else {
                    $db->prepare(
                        "UPDATE users
                         SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END,
                             updated_at = datetime('now')
                         WHERE id = ?"
                    )->execute([$targetId]);
                    $notice = 'Updated user status.';
                }
            }
        }
    }
}

// Pick up a flash notice from a prior rate-modal PRG redirect.
if ($notice === '' && !empty($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

// GET-side: ?manage_rates=<id> opens the rates modal for that user on load
// (also used by every rate-management PRG so the modal stays open).  Only
// honored after the POST handler runs so a successful update can clear it.
if ($manageRatesUserId === 0 && isset($_GET['manage_rates'])) {
    $manageRatesUserId = (int) $_GET['manage_rates'];
}
if ($editRateId === 0 && isset($_GET['edit_rate'])) {
    $editRateId = (int) $_GET['edit_rate'];
}

$users = $db->query(
    "SELECT id, username, name, email, role, is_active, created_at, preferences
     FROM users
     ORDER BY is_active DESC, username COLLATE NOCASE ASC"
)->fetchAll();

// Decode preferences once per row so the table and modal both have it.
foreach ($users as &$u) {
    $u['paid_hourly'] = isUserPaidHourly($u);
}
unset($u);

// Load rate history for the user whose rates modal is auto-opening.  Kept
// scoped to that one user; the modal isn't a directory of every employee.
$manageRatesUser = null;
$manageRates     = [];
if ($manageRatesUserId > 0) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $manageRatesUserId) {
            $manageRatesUser = $u;
            break;
        }
    }
    // Hide the modal if the target isn't visible to this admin (rank guard)
    // or isn't paid-hourly.  We render no button for those, but a stale URL
    // shouldn't open an empty modal.
    if ($manageRatesUser === null
        || roleRank((string) $manageRatesUser['role']) > $myRank
        || !$manageRatesUser['paid_hourly']
    ) {
        $manageRatesUserId = 0;
        $manageRatesUser   = null;
    } else {
        $stmt = $db->prepare(
            "SELECT id, hourly_rate, effective_from, effective_to
             FROM hourly_rates
             WHERE user_id = ?
             ORDER BY (effective_from IS NULL) DESC, effective_from ASC"
        );
        $stmt->execute([$manageRatesUserId]);
        $manageRates = $stmt->fetchAll();
    }
}

// Roles the current user is allowed to grant — used to populate the
// modal's role <select>.  Anything above $myRank is filtered out.
$assignableRoles = array_filter(ROLES, fn ($r) => roleRank($r) <= $myRank);

$pageTitle  = 'Users - Cent Notes';
$activePage = null;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .users-main {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    .users-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .users-header h1 {
        font-size: 1.4rem;
        font-weight: 700;
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

    .users-table-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        overflow: hidden;
    }

    .users-table { width: 100%; border-collapse: collapse; }

    .users-table thead { background: #1a1a2e; color: #fff; }

    .users-table th {
        padding: .75rem 1rem;
        text-align: left;
        font-size: .78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        white-space: nowrap;
    }

    .users-table td {
        padding: .8rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .88rem;
        vertical-align: middle;
        white-space: nowrap;
    }

    .users-table tbody tr:last-child td { border-bottom: none; }

    .users-table .col-action {
        width: 1%;
        text-align: right;
    }

    .users-table .row-actions {
        display: inline-flex;
        gap: .35rem;
        justify-content: flex-end;
    }

    .users-table .muted { color: #888; font-style: italic; }
    .users-table .you   { font-size: .72rem; color: #666; margin-left: .35rem; }

    .status-badge {
        display: inline-block;
        padding: .2em .6em;
        border-radius: 5px;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .status-active   { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .status-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

    .btn-primary {
        padding: .5rem 1.1rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .82rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s;
    }

    .btn-primary:hover { background: #2d2d5e; }

    .btn-row {
        padding: .32rem .75rem;
        background: transparent;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .78rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }

    .btn-row:hover:not(:disabled) { background: #f0f0f5; border-color: #c8d0e0; }
    .btn-row:disabled { opacity: .4; cursor: not-allowed; }

    .btn-row.deactivate:hover:not(:disabled) { background: #fff1f2; color: #b91c1c; border-color: #fca5a5; }

    /* ── Modal ────────────────────────────────────────────────────────────── */
    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        background: rgba(0,0,0,.55);
        padding: 8vh 1rem 1rem;
    }

    .modal-overlay[hidden] { display: none; }

    .modal-box {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 60px rgba(0,0,0,.3);
        width: min(520px, 100%);
        max-height: 84vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

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

    .modal-body {
        padding: 1.25rem 1.25rem .25rem;
        overflow-y: auto;
    }

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
    .field[hidden] { display: none; }   /* override .field's display:flex */

    .field label {
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .field input,
    .field select {
        padding: .5rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .88rem;
        font-family: inherit;
        color: #1a1a2e;
        background: #fff;
        transition: border-color .15s, box-shadow .15s;
    }

    .field input:focus,
    .field select:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .field input:disabled,
    .field select:disabled { background: #f5f5f8; color: #888; cursor: not-allowed; }

    .field-readonly-hint {
        font-size: .72rem;
        color: #888;
        margin-top: .25rem;
    }

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

    /* "Paid hourly" checkbox row inside the Edit user modal. */
    .checkbox-row {
        display: inline-flex;
        align-items: center;
        gap: .55rem;
        font-size: .9rem;
        font-weight: 500;
        color: #1a1a2e;
        cursor: pointer;
        user-select: none;
    }

    .checkbox-row input[type="checkbox"] {
        width: 1rem;
        height: 1rem;
        accent-color: #1a1a2e;
    }

    /* ── Rates modal ──────────────────────────────────────────────────────── */
    #rates-modal .modal-box { width: min(680px, 100%); }

    .rates-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 1.25rem;
    }

    .rates-table th {
        text-align: left;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #555;
        padding: .35rem .5rem;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }

    .rates-table td {
        padding: .55rem .5rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .85rem;
        vertical-align: middle;
        white-space: nowrap;
    }

    .rates-table tbody tr:last-child td { border-bottom: none; }
    .rates-table .col-rate    { font-variant-numeric: tabular-nums; }
    .rates-table .col-actions { width: 1%; text-align: right; }
    .rates-table .empty-row td { text-align: center; color: #999; padding: 1rem; }
    .rates-table .editing-row td { background: #fafbff; }

    .rates-table input[type="date"],
    .rates-table input[type="number"] {
        padding: .3rem .45rem;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        font-size: .82rem;
        font-family: inherit;
        width: 100%;
        min-width: 0;
    }

    .rates-table input[type="number"] { max-width: 6.5rem; }

    .rates-add {
        background: #fafbff;
        border: 1px solid #e2e6f0;
        border-radius: 8px;
        padding: .85rem 1rem;
    }

    .rates-add h3 {
        font-size: .8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #555;
        margin-bottom: .65rem;
    }

    .rates-add-grid {
        display: grid;
        grid-template-columns: 6.5rem 1fr 1fr;
        gap: .55rem;
        align-items: end;
    }

    .rates-add-grid .submit-cell {
        grid-column: 1 / -1;
        text-align: right;
        margin-top: .5rem;
    }

    .rates-add-grid label {
        font-size: .72rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .25rem;
        display: block;
        letter-spacing: .03em;
    }

    .rates-add-grid input {
        padding: .42rem .55rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .85rem;
        font-family: inherit;
        width: 100%;
    }

    .rates-hint {
        font-size: .72rem;
        color: #888;
        margin-top: .55rem;
    }

    @media (max-width: 700px) {
        .users-main { max-width: 100%; padding: 1rem; }
        .modal-grid { grid-template-columns: minmax(0, 1fr); }
        .users-table .col-email   { display: none; }
        .users-table .col-created { display: none; }
        .rates-add-grid { grid-template-columns: 1fr 1fr; }
        .rates-add-grid .rate-cell  { grid-column: 1 / -1; }
    }
</style>

<main class="users-main">
    <div class="users-header">
        <h1>Users</h1>
        <button type="button" class="btn-primary" id="open-create-modal">Add user</button>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php // Modal-action errors render inside the modal so they're visible
          // when it re-opens.  Errors from row-button actions (toggle,
          // reset_password) still surface here on the page. ?>
    <?php if ($error !== '' && $reopenMode === ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="users-table-card">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Name</th>
                    <th class="col-email">Email</th>
                    <th>Role</th>
                    <th class="col-created">Created</th>
                    <th>Status</th>
                    <th class="col-action"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                        $isSelf       = (int) $u['id'] === (int) $me['id'];
                        $isActive     = (int) $u['is_active'] === 1;
                        $hasEmail     = trim((string) ($u['email'] ?? '')) !== '';
                        $targetRole   = (string) ($u['role'] ?? 'basic_employee');
                        $targetRank   = roleRank($targetRole);
                        $outranksMe   = $targetRank > $myRank;
                        // The shared "you can't touch this row" guard for Reset
                        // and Deactivate, beyond the per-action conditions.
                        $rankBlocked  = $outranksMe;
                        $rankTooltip  = "This user's role is higher than yours.";
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($u['username']) ?></strong>
                            <?php if ($isSelf): ?><span class="you">(you)</span><?php endif; ?>
                        </td>
                        <td><?= $u['name'] !== '' ? h($u['name']) : '<span class="muted">—</span>' ?></td>
                        <td class="col-email"><?= $hasEmail ? h($u['email']) : '<span class="muted">—</span>' ?></td>
                        <td><?= h(ROLE_LABELS[$targetRole] ?? $targetRole) ?></td>
                        <td class="col-created"><?= h((string) $u['created_at']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $isActive ? 'active' : 'inactive' ?>">
                                <?= $isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="col-action">
                            <div class="row-actions">
                                <button type="button" class="btn-row js-edit"
                                        data-id="<?= (int) $u['id'] ?>"
                                        data-name="<?= h($u['name']) ?>"
                                        data-email="<?= h((string) $u['email']) ?>"
                                        data-username="<?= h($u['username']) ?>"
                                        data-role="<?= h($targetRole) ?>"
                                        data-paid-hourly="<?= $u['paid_hourly'] ? '1' : '0' ?>"
                                        data-self="<?= $isSelf ? '1' : '0' ?>"
                                        <?php if ($rankBlocked): ?>disabled title="<?= h($rankTooltip) ?>"<?php endif; ?>>
                                    Edit
                                </button>

                                <?php if ($u['paid_hourly'] && !$rankBlocked): ?>
                                    <a class="btn-row"
                                       href="users.php?<?= h(http_build_query(['manage_rates' => (int) $u['id']])) ?>">Rates</a>
                                <?php endif; ?>

                                <form method="post" action="users.php" style="display:inline" class="js-reset-form">
                                    <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action"  value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn-row"
                                            <?php if ($rankBlocked): ?>disabled title="<?= h($rankTooltip) ?>"
                                            <?php elseif (!$isActive): ?>disabled title="Account is inactive"
                                            <?php elseif (!$hasEmail): ?>disabled title="No email on file"
                                            <?php endif; ?>
                                            data-confirm="Send a password-reset link to <?= h((string) $u['email']) ?>?">
                                        Reset password
                                    </button>
                                </form>

                                <form method="post" action="users.php" style="display:inline">
                                    <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action"  value="toggle">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit"
                                            class="btn-row<?= $isActive ? ' deactivate' : '' ?>"
                                            <?php if ($isSelf): ?>disabled title="You can't deactivate your own account."
                                            <?php elseif ($rankBlocked): ?>disabled title="<?= h($rankTooltip) ?>"
                                            <?php endif; ?>>
                                        <?= $isActive ? 'Deactivate' : 'Reactivate' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ── Create / Edit modal ──────────────────────────────────────────────── -->
<div id="user-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="user-modal-title">Add a user</h2>
            <button type="button" class="modal-close" id="user-modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="user-modal-form" method="post" action="users.php" autocomplete="off">
            <div class="modal-body">
                <div id="user-modal-error" class="error" style="margin-bottom: 1rem;"
                     <?= ($error !== '' && $reopenMode !== '') ? '' : 'hidden' ?>>
                    <?= h($error) ?>
                </div>

                <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action"  id="user-modal-action"   value="create">
                <input type="hidden" name="user_id" id="user-modal-user-id" value="">

                <div class="modal-grid">
                    <div class="field full create-only" id="field-username">
                        <label for="modal-username">Username</label>
                        <input id="modal-username" name="username" type="text" value="<?= h($form['username']) ?>">
                    </div>

                    <div class="field full" id="field-username-readonly" hidden>
                        <label>Username</label>
                        <input type="text" id="modal-username-readonly" value="" disabled>
                        <div class="field-readonly-hint">Usernames can't be changed.</div>
                    </div>

                    <div class="field">
                        <label for="modal-name">Display name</label>
                        <input id="modal-name" name="name" type="text" value="<?= h($form['name']) ?>" maxlength="100">
                    </div>

                    <div class="field">
                        <label for="modal-email">Email</label>
                        <input id="modal-email" name="email" type="email" value="<?= h($form['email']) ?>" maxlength="200">
                    </div>

                    <div class="field create-only" id="field-password">
                        <label for="modal-password">Password</label>
                        <input id="modal-password" name="password" type="password" autocomplete="new-password">
                    </div>

                    <div class="field create-only" id="field-password-confirm">
                        <label for="modal-password-confirm">Confirm password</label>
                        <input id="modal-password-confirm" name="password_confirm" type="password" autocomplete="new-password">
                    </div>

                    <div class="field full" id="field-role">
                        <label for="modal-role">Role</label>
                        <select id="modal-role" name="role">
                            <?php foreach ($assignableRoles as $r): ?>
                                <option value="<?= h($r) ?>"<?= $form['role'] === $r ? ' selected' : '' ?>>
                                    <?= h(ROLE_LABELS[$r]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full" id="field-role-self" hidden>
                        <label>Role</label>
                        <input type="text" id="modal-role-self" value="" disabled>
                        <div class="field-readonly-hint">You can't change your own role.</div>
                    </div>

                    <div class="field full edit-only" id="field-paid-hourly" hidden>
                        <label class="checkbox-row">
                            <input type="checkbox" name="paid_hourly" id="modal-paid-hourly" value="1">
                            <span>Paid hourly</span>
                        </label>
                        <div class="field-readonly-hint">When on, this user gets a Rates button on the table for managing their hourly-rate history.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" id="user-modal-cancel">Cancel</button>
                <button type="submit" class="btn-primary" id="user-modal-submit">Create user</button>
            </div>
        </form>
    </div>
</div>

<?php if ($manageRatesUser !== null):
    // Display label for the rates-modal heading: name preferred, falls back to username.
    $rateUserLabel = trim((string) $manageRatesUser['name']) !== '' ? $manageRatesUser['name'] : $manageRatesUser['username'];

    $rateRowsHtml = '';
    $fmtDate = function (?string $d): string {
        if ($d === null || $d === '') return '<span class="muted">—</span>';
        return h((new DateTimeImmutable($d))->format('M j, Y'));
    };

    // Reference anchor for the date inputs: a known pay-week-start far in the
    // past, used with step=7 to restrict the picker to pay-week-start days.
    $payWeekAnchor = snapToPayWeekStart('2020-01-01', $tz, $pws);
    $payWeekDayLabel = match (strtolower($pws)) {
        'mon'   => 'Monday',
        'sat'   => 'Saturday',
        default => 'Sunday',
    };
?>
<!-- ── Hourly-rates modal ─────────────────────────────────────────────── -->
<div id="rates-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2>Hourly rates — <?= h($rateUserLabel) ?></h2>
            <a class="modal-close" href="users.php" aria-label="Close">&times;</a>
        </div>
        <div class="modal-body">
            <?php if ($error !== '' && $manageRatesUserId !== 0): ?>
                <div class="error" style="margin-bottom: 1rem;"><?= h($error) ?></div>
            <?php endif; ?>

            <table class="rates-table">
                <thead>
                    <tr>
                        <th>From (pay week)</th>
                        <th>To (pay week)</th>
                        <th>Rate</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($manageRates)): ?>
                        <tr class="empty-row"><td colspan="4">No rates recorded yet.  Add the first one below.</td></tr>
                    <?php else: ?>
                        <?php foreach ($manageRates as $r): ?>
                            <?php $isEditing = $editRateId === (int) $r['id']; ?>
                            <?php if ($isEditing): ?>
                                <tr class="editing-row">
                                    <form method="post" action="users.php" id="rate-edit-form-<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action"  value="update_rate">
                                        <input type="hidden" name="user_id" value="<?= (int) $manageRatesUserId ?>">
                                        <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                                    </form>
                                    <td>
                                        <input form="rate-edit-form-<?= (int) $r['id'] ?>" type="date" name="effective_from"
                                               min="<?= h($payWeekAnchor) ?>" step="7"
                                               value="<?= h((string) ($rateForm['from'] !== '' ? $rateForm['from'] : (string) $r['effective_from'])) ?>">
                                    </td>
                                    <td>
                                        <input form="rate-edit-form-<?= (int) $r['id'] ?>" type="date" name="effective_to"
                                               min="<?= h($payWeekAnchor) ?>" step="7"
                                               value="<?= h((string) ($rateForm['to'] !== '' ? $rateForm['to'] : (string) $r['effective_to'])) ?>">
                                    </td>
                                    <td>
                                        <input form="rate-edit-form-<?= (int) $r['id'] ?>" type="number" step="0.01" min="0.01" name="hourly_rate" required
                                               value="<?= h((string) ($rateForm['rate'] !== '' ? $rateForm['rate'] : number_format((float) $r['hourly_rate'], 2, '.', ''))) ?>">
                                    </td>
                                    <td class="col-actions">
                                        <button form="rate-edit-form-<?= (int) $r['id'] ?>" type="submit" class="btn-row" style="border-color:#1a1a2e;color:#1a1a2e">Save</button>
                                        <a class="btn-row" href="users.php?<?= h(http_build_query(['manage_rates' => (int) $manageRatesUserId])) ?>">Cancel</a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td><?= $fmtDate($r['effective_from']) ?></td>
                                    <td><?= $fmtDate($r['effective_to']) ?></td>
                                    <td class="col-rate">$<?= h(number_format((float) $r['hourly_rate'], 2)) ?> / hr</td>
                                    <td class="col-actions">
                                        <a class="btn-row" href="users.php?<?= h(http_build_query(['manage_rates' => (int) $manageRatesUserId, 'edit_rate' => (int) $r['id']])) ?>">Edit</a>
                                        <form method="post" action="users.php" style="display:inline" class="js-confirm-form">
                                            <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action"  value="delete_rate">
                                            <input type="hidden" name="user_id" value="<?= (int) $manageRatesUserId ?>">
                                            <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn-row deactivate"
                                                    data-confirm="Delete this rate row?">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($editRateId === 0): ?>
            <form method="post" action="users.php" class="rates-add">
                <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action"  value="add_rate">
                <input type="hidden" name="user_id" value="<?= (int) $manageRatesUserId ?>">

                <h3>Add a new rate</h3>
                <div class="rates-add-grid">
                    <div class="rate-cell">
                        <label for="rates-add-rate">$ / hr</label>
                        <input id="rates-add-rate" type="number" step="0.01" min="0.01" name="hourly_rate" required
                               value="<?= h($rateForm['mode'] === 'add' ? $rateForm['rate'] : '') ?>">
                    </div>
                    <div>
                        <label for="rates-add-from">From</label>
                        <input id="rates-add-from" type="date" name="effective_from"
                               min="<?= h($payWeekAnchor) ?>" step="7"
                               value="<?= h($rateForm['mode'] === 'add' ? $rateForm['from'] : '') ?>">
                    </div>
                    <div>
                        <label for="rates-add-to">To</label>
                        <input id="rates-add-to" type="date" name="effective_to"
                               min="<?= h($payWeekAnchor) ?>" step="7"
                               value="<?= h($rateForm['mode'] === 'add' ? $rateForm['to'] : '') ?>">
                    </div>
                    <div class="submit-cell">
                        <button type="submit" class="btn-primary">Add rate</button>
                    </div>
                </div>
                <div class="rates-hint">Dates must be a <?= h($payWeekDayLabel) ?> (the pay-week-start).  Any other date is snapped back to its containing pay week.  Leave either side blank for an open-ended range.</div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    var modal       = document.getElementById('user-modal');
    var form        = document.getElementById('user-modal-form');
    var titleEl     = document.getElementById('user-modal-title');
    var submitEl    = document.getElementById('user-modal-submit');
    var actionEl    = document.getElementById('user-modal-action');
    var userIdEl    = document.getElementById('user-modal-user-id');
    var nameEl      = document.getElementById('modal-name');
    var emailEl     = document.getElementById('modal-email');
    var usernameEl  = document.getElementById('modal-username');
    var passEl      = document.getElementById('modal-password');
    var passConfEl  = document.getElementById('modal-password-confirm');
    var usernameRO  = document.getElementById('modal-username-readonly');
    var roleEl      = document.getElementById('modal-role');
    var roleSelfEl  = document.getElementById('modal-role-self');
    var paidHourlyEl= document.getElementById('modal-paid-hourly');
    var fieldUser   = document.getElementById('field-username');
    var fieldUserRO = document.getElementById('field-username-readonly');
    var fieldRole   = document.getElementById('field-role');
    var fieldRoleS  = document.getElementById('field-role-self');
    var createOnly  = document.querySelectorAll('.create-only');
    var editOnly    = document.querySelectorAll('.edit-only');

    // Human-readable role labels, mirroring ROLE_LABELS in app/permissions.php.
    // Used by the self-edit readonly display where we have only the role key
    // from the data-role attribute.
    var ROLE_LABELS = <?= json_encode(ROLE_LABELS) ?>;

    function showCreateOnlyFields(show) {
        createOnly.forEach(function (el) {
            el.hidden = !show;
            // Disable inputs inside hidden sections so they don't get
            // submitted with the form.
            el.querySelectorAll('input').forEach(function (inp) {
                inp.disabled = !show;
            });
        });
        // Edit-only fields are the mirror image — visible only when not
        // creating.  Disable when hidden for the same submit-suppression reason.
        editOnly.forEach(function (el) {
            el.hidden = show;
            el.querySelectorAll('input').forEach(function (inp) {
                inp.disabled = show;
            });
        });
    }

    function setRoleValue(value) {
        if (!value) { return; }
        for (var i = 0; i < roleEl.options.length; i++) {
            if (roleEl.options[i].value === value) {
                roleEl.selectedIndex = i;
                return;
            }
        }
        // Target's role isn't in our assignable list (shouldn't happen
        // because the Edit button is disabled for higher-ranked users,
        // but be defensive).  Leave the selector at its default.
    }

    function openCreate(prefill) {
        titleEl.textContent  = 'Add a user';
        submitEl.textContent = 'Create user';
        actionEl.value       = 'create';
        userIdEl.value       = '';

        fieldUser.hidden   = false;
        fieldUserRO.hidden = true;
        showCreateOnlyFields(true);

        // Real role selector for create.
        fieldRole.hidden  = false;
        fieldRoleS.hidden = true;
        roleEl.disabled   = false;

        usernameEl.value = (prefill && prefill.username) || '';
        nameEl.value     = (prefill && prefill.name)     || '';
        emailEl.value    = (prefill && prefill.email)    || '';
        passEl.value     = '';
        passConfEl.value = '';
        setRoleValue((prefill && prefill.role) || 'basic_employee');

        modal.hidden = false;
        setTimeout(function () { usernameEl.focus(); }, 30);
    }

    function openEdit(data) {
        titleEl.textContent  = 'Edit user';
        submitEl.textContent = 'Save';
        actionEl.value       = 'update';
        userIdEl.value       = data.id || '';

        fieldUser.hidden   = true;
        fieldUserRO.hidden = false;
        usernameRO.value   = data.username || '';
        showCreateOnlyFields(false);

        var isSelf = data.self === '1' || data.self === true;
        if (isSelf) {
            // Self-edit: hide the real selector (so it isn't submitted) and
            // show the read-only display labelled with the role they have.
            fieldRole.hidden  = true;
            fieldRoleS.hidden = false;
            roleEl.disabled   = true;
            roleSelfEl.value  = ROLE_LABELS[data.role] || data.role || '';
        } else {
            fieldRole.hidden  = false;
            fieldRoleS.hidden = true;
            roleEl.disabled   = false;
            setRoleValue(data.role);
        }

        nameEl.value  = data.name  || '';
        emailEl.value = data.email || '';
        if (paidHourlyEl) {
            paidHourlyEl.checked = data.paidHourly === '1' || data.paidHourly === 1 || data.paidHourly === true;
        }

        modal.hidden = false;
        setTimeout(function () { nameEl.focus(); }, 30);
    }

    function closeModal() {
        modal.hidden = true;
    }

    var modalErrEl = document.getElementById('user-modal-error');
    function clearModalError() {
        if (modalErrEl) {
            modalErrEl.hidden = true;
            modalErrEl.textContent = '';
        }
    }

    document.getElementById('open-create-modal').addEventListener('click', function () {
        clearModalError();
        openCreate();
    });
    document.getElementById('user-modal-close').addEventListener('click', closeModal);
    document.getElementById('user-modal-cancel').addEventListener('click', closeModal);

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    // Edit buttons populate the modal from data-* attributes.
    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) { return; }
            clearModalError();
            openEdit({
                id:          btn.dataset.id,
                username:    btn.dataset.username,
                name:        btn.dataset.name,
                email:       btn.dataset.email,
                role:        btn.dataset.role,
                paidHourly:  btn.dataset.paidHourly,
                self:        btn.dataset.self,
            });
        });
    });

    // Reset-password forms: confirm before submitting.
    document.querySelectorAll('.js-reset-form').forEach(function (frm) {
        frm.addEventListener('submit', function (e) {
            var btn = frm.querySelector('button[type=submit]');
            var msg = btn && btn.dataset.confirm;
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // If a server-side error left form state behind, re-open the modal in
    // the right mode so the user doesn't have to retype anything.
    <?php if ($reopenMode === 'create'): ?>
        openCreate({
            username: <?= json_encode($form['username']) ?>,
            name:     <?= json_encode($form['name']) ?>,
            email:    <?= json_encode($form['email']) ?>,
            role:     <?= json_encode($form['role']) ?>,
        });
    <?php elseif ($reopenMode === 'edit'): ?>
        openEdit({
            id:         <?= json_encode($form['id']) ?>,
            username:   <?= json_encode($form['username']) ?>,
            name:       <?= json_encode($form['name']) ?>,
            email:      <?= json_encode($form['email']) ?>,
            role:       <?= json_encode($form['role']) ?>,
            paidHourly: <?= json_encode($form['paid_hourly'] ? '1' : '0') ?>,
            self:       <?= json_encode((int) $form['id'] === (int) $me['id'] ? '1' : '0') ?>,
        });
    <?php endif; ?>
}());

<?php if ($manageRatesUserId > 0): ?>
// Auto-open the rates modal when ?manage_rates=<id> is on the URL (or a
// rate-management PRG put us back there).
(function () {
    'use strict';
    var modal = document.getElementById('rates-modal');
    if (!modal) return;
    modal.hidden = false;

    // Esc and clicking outside the box should drop the manage_rates param
    // by navigating back to /users.php; the close link in the header does
    // the same thing.  Both fully reset the modal-open state.
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            window.location.href = '/users.php';
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) {
            window.location.href = '/users.php';
        }
    });

    // Per-row delete-confirm prompts.
    modal.querySelectorAll('form.js-confirm-form').forEach(function (frm) {
        frm.addEventListener('submit', function (e) {
            var btn = frm.querySelector('button[type=submit]');
            var msg = btn && btn.dataset.confirm;
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
}());
<?php endif; ?>
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
