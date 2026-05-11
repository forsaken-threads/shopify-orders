<?php
declare(strict_types=1);

/**
 * User management page.
 *
 * Shows every user in the system (active or not) and lets the operator
 * create new accounts and toggle existing ones between active and inactive.
 * No delete — keep the row around so historical references to the user_id
 * (e.g. password_resets) stay coherent.
 *
 * No admin gating in this phase; that arrives with the role system.  For now
 * every signed-in user can manage the user list, matching the small, trusted
 * audience this internal tool serves today.
 *
 * Form actions (POST, CSRF-protected):
 *   action=create   — add a new user (username + password required; name +
 *                      email optional).
 *   action=toggle   — flip is_active for the given user_id.  Users cannot
 *                      deactivate themselves.
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/auth.php';

$me = requireLogin($config);
$db = getDb($config);

$notice = '';
$error  = '';

// Sticky form state — populated when a create attempt fails so the user
// doesn't have to retype the non-secret fields.
$form = ['username' => '', 'name' => '', 'email' => ''];

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
            } else {
                $exists = $db->prepare("SELECT 1 FROM users WHERE username = ?");
                $exists->execute([$form['username']]);
                if ($exists->fetchColumn()) {
                    $error = 'A user with that username already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $insert = $db->prepare(
                        "INSERT INTO users (username, password_hash, name, email, preferences, is_active)
                         VALUES (?, ?, ?, ?, '{}', 1)"
                    );
                    $insert->execute([
                        $form['username'],
                        $hash,
                        $form['name'],
                        $form['email'] !== '' ? $form['email'] : null,
                    ]);
                    $notice = 'Created user "' . $form['username'] . '".';
                    $form = ['username' => '', 'name' => '', 'email' => ''];
                }
            }
        } elseif ($action === 'toggle') {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            if ($targetId <= 0) {
                $error = 'Invalid user.';
            } elseif ($targetId === (int) $me['id']) {
                $error = "You can't deactivate your own account.";
            } else {
                $stmt = $db->prepare(
                    "UPDATE users
                     SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END,
                         updated_at = datetime('now')
                     WHERE id = ?"
                );
                $stmt->execute([$targetId]);
                $notice = 'Updated user status.';
            }
        }
    }
}

$users = $db->query(
    "SELECT id, username, name, email, is_active, created_at
     FROM users
     ORDER BY is_active DESC, username COLLATE NOCASE ASC"
)->fetchAll();

$pageTitle  = 'Users - Cent Notes';
$activePage = null;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .users-main {
        flex: 1;
        padding: 2rem;
        max-width: 960px;
        margin: 0 auto;
        width: 100%;
    }

    .users-main h1 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
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

    .users-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1.5rem;
    }

    .users-card h2 {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .create-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .9rem 1.1rem;
    }

    .field { display: flex; flex-direction: column; }

    .field label {
        font-size: .78rem;
        font-weight: 600;
        color: #555;
        margin-bottom: .35rem;
        letter-spacing: .03em;
    }

    .field input {
        padding: .5rem .7rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .88rem;
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

    .create-actions {
        margin-top: 1rem;
        display: flex;
        justify-content: flex-end;
    }

    .btn-primary {
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

    .btn-primary:hover { background: #2d2d5e; }

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
    }

    .users-table td {
        padding: .8rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .88rem;
        vertical-align: middle;
    }

    .users-table tbody tr:last-child td { border-bottom: none; }

    .users-table .col-action { width: 1%; white-space: nowrap; text-align: right; }

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

    .btn-toggle {
        padding: .35rem .85rem;
        background: transparent;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .8rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }

    .btn-toggle:hover { background: #f0f0f5; border-color: #c8d0e0; }
    .btn-toggle:disabled { opacity: .4; cursor: not-allowed; }

    .btn-toggle.deactivate:hover { background: #fff1f2; color: #b91c1c; border-color: #fca5a5; }

    @media (max-width: 700px) {
        .create-grid { grid-template-columns: minmax(0, 1fr); }
        .users-table .col-email { display: none; }
        .users-table .col-created { display: none; }
    }
</style>

<main class="users-main">
    <h1>Users</h1>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="users-card" method="post" action="users.php" autocomplete="off">
        <h2>Add a user</h2>

        <input type="hidden" name="_csrf"  value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">

        <div class="create-grid">
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" value="<?= h($form['username']) ?>" required>
            </div>

            <div class="field">
                <label for="name">Display name</label>
                <input id="name" name="name" type="text" value="<?= h($form['name']) ?>" maxlength="100">
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?= h($form['email']) ?>" maxlength="200">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="password_confirm">Confirm password</label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            </div>
        </div>

        <div class="create-actions">
            <button type="submit" class="btn-primary">Create user</button>
        </div>
    </form>

    <div class="users-table-card">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Name</th>
                    <th class="col-email">Email</th>
                    <th class="col-created">Created</th>
                    <th>Status</th>
                    <th class="col-action"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                        $isSelf   = (int) $u['id'] === (int) $me['id'];
                        $isActive = (int) $u['is_active'] === 1;
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($u['username']) ?></strong>
                            <?php if ($isSelf): ?><span class="you">(you)</span><?php endif; ?>
                        </td>
                        <td><?= $u['name'] !== '' ? h($u['name']) : '<span class="muted">—</span>' ?></td>
                        <td class="col-email"><?= $u['email'] !== null && $u['email'] !== '' ? h($u['email']) : '<span class="muted">—</span>' ?></td>
                        <td class="col-created"><?= h((string) $u['created_at']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $isActive ? 'active' : 'inactive' ?>">
                                <?= $isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="col-action">
                            <form method="post" action="users.php" style="display:inline">
                                <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action"  value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <button type="submit"
                                        class="btn-toggle<?= $isActive ? ' deactivate' : '' ?>"
                                        <?= $isSelf ? 'disabled title="You can\'t deactivate your own account."' : '' ?>>
                                    <?= $isActive ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
