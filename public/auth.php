<?php
declare(strict_types=1);

/**
 * Session-cookie auth, backed by the users table.
 *
 * Starts a PHP session and ensures a CSRF token exists on it.  The token is
 * available to callers via $_SESSION['csrf_token'] and is output as a JS
 * variable by app/partials/header.php.  The signed-in user row (id, username,
 * name, email, preferences) is stored in $_SESSION['user'] after a successful
 * login and is re-validated against the database on every request by
 * currentUser() — a deactivated user loses access immediately rather than
 * waiting for their session to expire.
 *
 * Webhook handlers must NOT require this file — they authenticate via HMAC.
 */

require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                                // session cookie only
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Look up a user by username and verify the password.  Returns the user row
 * (preferences decoded) on success, or null on no-match / wrong-password /
 * deactivated account.  The three failure cases return null indistinguishably
 * so callers can't enumerate usernames or activation state.
 */
function findUserByCredentials(PDO $db, string $username, string $password): ?array
{
    $stmt = $db->prepare(
        "SELECT id, username, password_hash, name, email, preferences, is_active
         FROM users WHERE username = ?"
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ((int) $row['is_active'] === 0) {
        return null;
    }
    if (!password_verify($password, (string) $row['password_hash'])) {
        return null;
    }
    return hydrateUserRow($row);
}

/**
 * Look up an active user by id.  Used by currentUser() to refresh the session
 * payload against the database on each request.
 */
function findActiveUserById(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, username, name, email, preferences, is_active
         FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['is_active'] === 0) {
        return null;
    }
    return hydrateUserRow($row);
}

/**
 * Shape a users-table row into the array stored in $_SESSION['user'].
 * Decodes preferences and drops sensitive fields like password_hash.
 */
function hydrateUserRow(array $row): array
{
    $prefs = json_decode((string) ($row['preferences'] ?? '{}'), true);
    if (!is_array($prefs)) {
        $prefs = [];
    }
    return [
        'id'          => (int)    $row['id'],
        'username'    => (string) $row['username'],
        'name'        => (string) ($row['name']  ?? ''),
        'email'       => (string) ($row['email'] ?? ''),
        'preferences' => $prefs,
    ];
}

/**
 * Mark the current session as logged in as the given user.  Regenerates the
 * session id to prevent fixation attacks where an attacker pre-seeds a victim
 * with a known session id before login.
 */
function logIn(array $userRow): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $userRow;
}

/**
 * Tear down the current session entirely.  Clears the session array, expires
 * the cookie on the client, and destroys the server-side session file.
 */
function logOut(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Returns the currently signed-in user (refreshed from the database each call
 * so that profile edits and deactivations take effect immediately), or null
 * if there is no valid session.  Safe to call from logged-out pages.
 */
function currentUser(array $config): ?array
{
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser)) {
        return null;
    }
    $userId = (int) ($sessionUser['id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    $user = findActiveUserById(getDb($config), $userId);
    if ($user === null) {
        // Account was deleted or deactivated — tear the session down.
        logOut();
        return null;
    }
    $_SESSION['user'] = $user;
    return $user;
}

/**
 * Browser-facing gate.  Redirects to /login.php (with a ?next= pointer back
 * to the current URL) if the request isn't authenticated.  Returns the
 * session user otherwise.
 */
function requireLogin(array $config): array
{
    $user = currentUser($config);
    if ($user !== null) {
        return $user;
    }
    $next  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $query = $next !== '' && $next !== '/' ? '?next=' . urlencode($next) : '';
    header('Location: /login.php' . $query);
    exit;
}

/**
 * API-facing gate.  Emits a 401 JSON response and exits if the request isn't
 * authenticated.  Returns the session user otherwise.
 */
function requireApiLogin(array $config): array
{
    $user = currentUser($config);
    if ($user !== null) {
        return $user;
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'authentication required']);
    exit;
}

/**
 * Validate a ?next= redirect target.  Accepts same-origin paths only — any
 * scheme/host or protocol-relative URL is rejected and replaced with the
 * default landing page.  Prevents the login form being used as an open
 * redirect to a phishing site.
 */
function sanitizeNext(string $next): string
{
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')) {
        return '/index.php';
    }
    return $next;
}
