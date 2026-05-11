<?php
declare(strict_types=1);

/**
 * Self-service profile page.
 *
 * Two independent forms in one page:
 *   1. Display name + email      — saved freely.
 *   2. Change password           — requires the current password and a
 *                                  confirmed new one (min 8 chars).
 *
 * Both forms POST back to profile.php with a hidden "action" field so a
 * single handler can route them.  After a successful save the $_SESSION
 * user is refreshed by currentUser() on the next request (which re-reads
 * the row from the database), so reloads always show the current values.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

$user = requireLogin($config);

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $db     = getDb($config);

        if ($action === 'profile') {
            $name  = trim((string) ($_POST['name']  ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $stmt = $db->prepare(
                    "UPDATE users SET name = ?, email = ?, updated_at = datetime('now') WHERE id = ?"
                );
                $stmt->execute([$name, $email !== '' ? $email : null, (int) $user['id']]);
                $notice = 'Profile updated.';
                $user   = currentUser($config) ?? $user;
            }
        } elseif ($action === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password']     ?? '');
            $confirm = (string) ($_POST['new_password_confirm'] ?? '');

            // Re-read the hash from the DB rather than trusting anything in
            // the session — keeps the verification authoritative.
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([(int) $user['id']]);
            $currentHash = (string) $stmt->fetchColumn();

            if (!password_verify($current, $currentHash)) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif (!hash_equals($new, $confirm)) {
                $error = 'New passwords did not match.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare(
                    "UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([$hash, (int) $user['id']]);
                $notice = 'Password changed.';
            }
        }
    }
}

$pageTitle  = 'Profile - Cent Notes';
$activePage = null;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .profile-main {
        flex: 1;
        padding: 2rem;
        max-width: 640px;
        margin: 0 auto;
        width: 100%;
    }

    .profile-main h1 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
    }

    .profile-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1.5rem;
    }

    .profile-card h2 {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .profile-username {
        font-size: .85rem;
        color: #666;
        margin-bottom: 1rem;
        padding: .55rem .75rem;
        background: #f5f5f8;
        border-radius: 6px;
    }

    .profile-username strong { color: #1a1a2e; }

    .field { margin-bottom: 1rem; }

    .field label {
        display: block;
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .field input {
        width: 100%;
        padding: .55rem .75rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .9rem;
        font-family: inherit;
        color: #1a1a2e;
        background: #fff;
        transition: border-color .15s, box-shadow .15s;
    }

    .field input:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .field-hint {
        font-size: .75rem;
        color: #888;
        margin-top: .3rem;
    }

    .notice {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        padding: .65rem .9rem;
        border-radius: 6px;
        font-size: .85rem;
        margin-bottom: 1.5rem;
    }

    .error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: .65rem .9rem;
        border-radius: 6px;
        font-size: .85rem;
        margin-bottom: 1.5rem;
    }

    .btn-save {
        padding: .55rem 1.25rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s;
    }

    .btn-save:hover { background: #2d2d5e; }
</style>

<main class="profile-main">
    <h1>Profile</h1>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="profile-card" method="post" action="profile.php" autocomplete="off">
        <h2>Account details</h2>

        <div class="profile-username">
            Username: <strong><?= h($user['username']) ?></strong>
            <div class="field-hint">Usernames can't be changed.  Ask another user to create a new account if you need a different one.</div>
        </div>

        <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="profile">

        <div class="field">
            <label for="name">Display name</label>
            <input id="name" name="name" type="text" value="<?= h($user['name']) ?>" maxlength="100">
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= h($user['email']) ?>" maxlength="200" autocomplete="email">
            <div class="field-hint">Used to receive password-reset links.</div>
        </div>

        <button class="btn-save" type="submit">Save details</button>
    </form>

    <form class="profile-card" method="post" action="profile.php" autocomplete="off">
        <h2>Change password</h2>

        <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="password">

        <div class="field">
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        </div>

        <div class="field">
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
            <div class="field-hint">At least 8 characters.</div>
        </div>

        <div class="field">
            <label for="new_password_confirm">Confirm new password</label>
            <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required>
        </div>

        <button class="btn-save" type="submit">Change password</button>
    </form>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
