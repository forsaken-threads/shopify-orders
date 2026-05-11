<?php
declare(strict_types=1);

/**
 * Forgot-password request handler.
 *
 * GET  /forgot-password.php          — username entry form.
 * POST /forgot-password.php          — record a single-use reset token (only
 *                                       if the username matches an active
 *                                       user with an email on file) and
 *                                       email out a link.  The response page
 *                                       is the same whether or not anything
 *                                       was sent, so the form can't be used
 *                                       to probe for valid usernames.
 *
 * Tokens live in password_resets, hashed via SHA-256.  The raw token is only
 * ever held in memory long enough to email it.  Prior outstanding tokens for
 * the same user are invalidated when a new one is issued.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../app/password-reset.php';

$submitted        = false;
$error            = '';
$username         = '';
$deferredUsername = null;       // populated when post-flush work is queued

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));

        if ($username === '') {
            $error = 'Please enter your username.';
        } else {
            // Don't run the DB lookup + email send here — both are timing
            // oracles (a real account with email triggers SMTP, which is
            // slow; a missing account or empty email returns instantly).
            // Defer everything until after the response is flushed; see
            // the fastcgi_finish_request() block at the bottom of the file.
            $submitted        = true;
            $deferredUsername = $username;
        }
    }
}

/**
 * Look up the user and dispatch a reset email.  No-op when the username
 * doesn't match an active account or that account has no email on file.
 * Intended to run AFTER the response has been flushed so its cost
 * (especially the SMTP send) doesn't reveal whether anything matched.
 * Any failure is logged, never surfaced to the caller.
 */
function processForgotPassword(array $config, string $username): void
{
    try {
        $db   = getDb($config);
        $stmt = $db->prepare(
            "SELECT id, name, email FROM users WHERE username = ? AND is_active = 1"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        if (!generateAndEmailReset(
            $config,
            (int)    $row['id'],
            (string) ($row['name']  ?? ''),
            (string) ($row['email'] ?? ''),
        )) {
            error_log('[forgot-password] no email on file or SMTP failed for user_id=' . $row['id']);
        }
    } catch (\Throwable $e) {
        error_log('[forgot-password] deferred work failed: ' . $e->getMessage());
    }
}

$pageTitle  = 'Forgot password - Cent Notes';
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
        margin-bottom: .35rem;
        text-align: center;
    }

    .auth-intro {
        font-size: .85rem;
        color: #666;
        text-align: center;
        margin-bottom: 1.25rem;
        line-height: 1.5;
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
    <?php if ($submitted): ?>
        <div class="auth-card">
            <h1>Check your email</h1>
            <div class="auth-notice">
                If an account exists for that username and has an email
                address on file, we've sent password reset instructions.
                The link is valid for one hour.
            </div>
            <div class="auth-links">
                <a href="login.php">← Back to sign in</a>
            </div>
        </div>
    <?php else: ?>
        <form class="auth-card" method="post" action="forgot-password.php" autocomplete="off">
            <h1>Forgot password</h1>
            <p class="auth-intro">
                Enter your username and we'll email you a link to reset
                your password.
            </p>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= h($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="_csrf" value="<?= h($_SESSION['csrf_token']) ?>">

            <div class="auth-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username"
                       value="<?= h($username) ?>" autofocus required>
            </div>

            <button class="auth-submit" type="submit">Send reset link</button>

            <div class="auth-links">
                <a href="login.php">← Back to sign in</a>
            </div>
        </form>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>

<?php
// ── Deferred work: DB lookup + token + email ────────────────────────────────
// Run AFTER the response is flushed to the client so the request takes the
// same time whether or not the username matched a real account with an
// email on file.  fastcgi_finish_request() is PHP-FPM-only; if it isn't
// available the work happens synchronously like before, which still works
// but reveals the timing oracle.
if ($deferredUsername !== null) {
    if (function_exists('fastcgi_finish_request')) {
        // Persist anything we touched in the session (CSRF, etc.) before
        // releasing the FastCGI request — otherwise the session lock is
        // held longer than needed.
        session_write_close();
        fastcgi_finish_request();
    }
    processForgotPassword($config, $deferredUsername);
}
