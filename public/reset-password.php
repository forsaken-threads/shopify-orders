<?php
declare(strict_types=1);

/**
 * Password reset handler.
 *
 * Reached via the link emailed by /forgot-password.php.  Verifies the token
 * against password_resets (SHA-256 hashed), then lets the holder set a new
 * password and marks the token used.  An expired, missing, or already-used
 * token shows a generic "link invalid" page without revealing which failure
 * mode it hit.
 *
 * GET  /reset-password.php?token=… — show the new-password form.
 * POST /reset-password.php         — apply the new password.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

$rawToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error    = '';
$success  = false;

$reset = $rawToken !== '' ? lookupActiveReset(getDb($config), $rawToken) : null;
$tokenValid = $reset !== null;

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'Passwords did not match.';
        } else {
            $db   = getDb($config);
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $db->beginTransaction();
            try {
                $db->prepare(
                    "UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([$hash, (int) $reset['user_id']]);

                $db->prepare(
                    "UPDATE password_resets SET used_at = datetime('now') WHERE id = ?"
                )->execute([(int) $reset['id']]);

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $success = true;
        }
    }
}

/**
 * Look up an unused, unexpired reset row by raw token.  Returns the row on
 * match (containing id and user_id), or null otherwise.
 */
function lookupActiveReset(PDO $db, string $rawToken): ?array
{
    $stmt = $db->prepare(
        "SELECT id, user_id FROM password_resets
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at > datetime('now')"
    );
    $stmt->execute([hash('sha256', $rawToken)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$pageTitle  = 'Reset password - Cent Notes';
$activePage = null;
$hideNav    = true;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .auth-main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 1.5rem;
    }

    .auth-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.09);
        padding: 2.25rem 2.25rem 2rem;
        width: 100%;
        max-width: 380px;
    }

    .auth-card h1 {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        text-align: center;
    }

    .auth-field { margin-bottom: 1rem; }

    .auth-field label {
        display: block;
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .auth-field input {
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

    .auth-field input:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .auth-field-hint {
        font-size: .75rem;
        color: #888;
        margin-top: .3rem;
    }

    .auth-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: .6rem .85rem;
        border-radius: 6px;
        font-size: .82rem;
        margin-bottom: 1rem;
    }

    .auth-notice {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        color: #075985;
        padding: .85rem 1rem;
        border-radius: 6px;
        font-size: .85rem;
        line-height: 1.5;
        margin-bottom: 1rem;
    }

    .auth-notice.bad {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .auth-submit {
        display: block;
        width: 100%;
        padding: .6rem 1rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .9rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s;
        margin-top: .5rem;
    }

    .auth-submit:hover { background: #2d2d5e; }

    .auth-links {
        text-align: center;
        margin-top: 1.25rem;
        font-size: .82rem;
    }

    .auth-links a {
        color: #555;
        text-decoration: none;
    }

    .auth-links a:hover { color: #1a1a2e; text-decoration: underline; }
</style>

<main class="auth-main">
    <?php if ($success): ?>
        <div class="auth-card">
            <h1>Password updated</h1>
            <div class="auth-notice">
                Your password has been changed.  You can now sign in with
                your new password.
            </div>
            <div class="auth-links">
                <a href="login.php">→ Sign in</a>
            </div>
        </div>
    <?php elseif (!$tokenValid): ?>
        <div class="auth-card">
            <h1>Link invalid</h1>
            <div class="auth-notice bad">
                This password-reset link is invalid, has expired, or has
                already been used.  Reset links are valid for one hour.
            </div>
            <div class="auth-links">
                <a href="forgot-password.php">Request a new link</a>
            </div>
        </div>
    <?php else: ?>
        <form class="auth-card" method="post" action="reset-password.php" autocomplete="off">
            <h1>Choose a new password</h1>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= h($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="_csrf" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="token" value="<?= h($rawToken) ?>">

            <div class="auth-field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                <div class="auth-field-hint">At least 8 characters.</div>
            </div>

            <div class="auth-field">
                <label for="password_confirm">Confirm new password</label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            </div>

            <button class="auth-submit" type="submit">Update password</button>
        </form>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
