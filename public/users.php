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

$me = requirePermission($config, 'manage_users');
$db = getDb($config);

$notice = '';
$error  = '';

// Sticky form state — populated when a create or update attempt fails so
// the modal can be re-opened with the non-secret fields still filled in.
$form = ['id' => '', 'username' => '', 'name' => '', 'email' => ''];

// Set when an error in a modal action should re-open the modal on reload.
$reopenMode = '';   // 'create' | 'edit' | ''

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
                    $db->prepare(
                        "INSERT INTO users (username, password_hash, name, email, preferences, is_active)
                         VALUES (?, ?, ?, ?, '{}', 1)"
                    )->execute([
                        $form['username'],
                        $hash,
                        $form['name'],
                        $form['email'] !== '' ? $form['email'] : null,
                    ]);
                    $notice = 'Created user "' . $form['username'] . '".';
                    $form   = ['id' => '', 'username' => '', 'name' => '', 'email' => ''];
                }
            }
            if ($error !== '') {
                $reopenMode = 'create';
            }
        } elseif ($action === 'update') {
            $form['id']    = (string) (int) ($_POST['user_id'] ?? 0);
            $form['name']  = trim((string) ($_POST['name']  ?? ''));
            $form['email'] = trim((string) ($_POST['email'] ?? ''));

            $targetId = (int) $form['id'];
            if ($targetId <= 0) {
                $error = 'Invalid user.';
            } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                // Carry the existing username back so the modal title can
                // still show who's being edited if validation re-fails.
                $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                $stmt->execute([$targetId]);
                $form['username'] = (string) ($stmt->fetchColumn() ?: '');

                $db->prepare(
                    "UPDATE users
                     SET name = ?, email = ?, updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([
                    $form['name'],
                    $form['email'] !== '' ? $form['email'] : null,
                    $targetId,
                ]);
                $notice = 'Updated user.';
                $form   = ['id' => '', 'username' => '', 'name' => '', 'email' => ''];
            }
            if ($error !== '') {
                $reopenMode = 'edit';
            }
        } elseif ($action === 'reset_password') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId <= 0) {
                $error = 'Invalid user.';
            } else {
                $stmt = $db->prepare("SELECT id, name, email, is_active FROM users WHERE id = ?");
                $stmt->execute([$targetId]);
                $u = $stmt->fetch();
                $email = trim((string) ($u['email'] ?? ''));
                if (!$u) {
                    $error = 'User not found.';
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

    .field input:disabled { background: #f5f5f8; color: #888; cursor: not-allowed; }

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

    @media (max-width: 700px) {
        .users-main { max-width: 100%; padding: 1rem; }
        .modal-grid { grid-template-columns: minmax(0, 1fr); }
        .users-table .col-email   { display: none; }
        .users-table .col-created { display: none; }
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
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

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
                        $isSelf    = (int) $u['id'] === (int) $me['id'];
                        $isActive  = (int) $u['is_active'] === 1;
                        $hasEmail  = trim((string) ($u['email'] ?? '')) !== '';
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($u['username']) ?></strong>
                            <?php if ($isSelf): ?><span class="you">(you)</span><?php endif; ?>
                        </td>
                        <td><?= $u['name'] !== '' ? h($u['name']) : '<span class="muted">—</span>' ?></td>
                        <td class="col-email"><?= $hasEmail ? h($u['email']) : '<span class="muted">—</span>' ?></td>
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
                                        data-username="<?= h($u['username']) ?>">
                                    Edit
                                </button>

                                <form method="post" action="users.php" style="display:inline" class="js-reset-form">
                                    <input type="hidden" name="_csrf"   value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action"  value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn-row"
                                            <?php if (!$hasEmail): ?>disabled title="No email on file"<?php endif; ?>
                                            <?php if (!$isActive): ?>disabled title="Account is inactive"<?php endif; ?>
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
                                            <?= $isSelf ? 'disabled title="You can\'t deactivate your own account."' : '' ?>>
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
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" id="user-modal-cancel">Cancel</button>
                <button type="submit" class="btn-primary" id="user-modal-submit">Create user</button>
            </div>
        </form>
    </div>
</div>

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
    var fieldUser   = document.getElementById('field-username');
    var fieldUserRO = document.getElementById('field-username-readonly');
    var createOnly  = document.querySelectorAll('.create-only');

    function showCreateOnlyFields(show) {
        createOnly.forEach(function (el) {
            el.hidden = !show;
            // Disable inputs inside hidden sections so they don't get
            // submitted with the form.
            el.querySelectorAll('input').forEach(function (inp) {
                inp.disabled = !show;
            });
        });
    }

    function openCreate(prefill) {
        titleEl.textContent  = 'Add a user';
        submitEl.textContent = 'Create user';
        actionEl.value       = 'create';
        userIdEl.value       = '';

        fieldUser.hidden   = false;
        fieldUserRO.hidden = true;
        showCreateOnlyFields(true);

        usernameEl.value = (prefill && prefill.username) || '';
        nameEl.value     = (prefill && prefill.name)     || '';
        emailEl.value    = (prefill && prefill.email)    || '';
        passEl.value     = '';
        passConfEl.value = '';

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

        nameEl.value  = data.name  || '';
        emailEl.value = data.email || '';

        modal.hidden = false;
        setTimeout(function () { nameEl.focus(); }, 30);
    }

    function closeModal() {
        modal.hidden = true;
    }

    document.getElementById('open-create-modal').addEventListener('click', function () { openCreate(); });
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
            openEdit({
                id:       btn.dataset.id,
                username: btn.dataset.username,
                name:     btn.dataset.name,
                email:    btn.dataset.email,
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
        });
    <?php elseif ($reopenMode === 'edit'): ?>
        openEdit({
            id:       <?= json_encode($form['id']) ?>,
            username: <?= json_encode($form['username']) ?>,
            name:     <?= json_encode($form['name']) ?>,
            email:    <?= json_encode($form['email']) ?>,
        });
    <?php endif; ?>
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
