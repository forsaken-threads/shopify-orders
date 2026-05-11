<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

// Already signed in?  Bounce straight to the destination.
$existingUser = currentUser($config);
if ($existingUser !== null) {
    $dest = sanitizeNext((string) ($_GET['next'] ?? ''));
    header('Location: ' . $dest);
    exit;
}

$error    = '';
$username = '';
$next     = (string) ($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $error = 'Your session expired.  Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            $user = findUserByCredentials(getDb($config), $username, $password);
            if ($user === null) {
                $error = 'Invalid username or password.';
            } else {
                logIn($user);
                header('Location: ' . sanitizeNext($next));
                exit;
            }
        }
    }
}

$pageTitle  = 'Sign in - Cent Notes';
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

    .auth-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: .6rem .85rem;
        border-radius: 6px;
        font-size: .82rem;
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
    <form class="auth-card" method="post" action="login.php" autocomplete="on">
        <h1>Sign in</h1>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= h($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="_csrf" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="next"  value="<?= h($next) ?>">

        <div class="auth-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username"
                   value="<?= h($username) ?>" autofocus required>
        </div>

        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="auth-submit" type="submit">Sign in</button>

        <div class="auth-links">
            <a href="forgot-password.php">Forgot your password?</a>
        </div>
    </form>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
