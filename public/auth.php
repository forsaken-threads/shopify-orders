<?php
declare(strict_types=1);

/**
 * HTTP Basic Auth helper, backed by the users table.
 *
 * Also starts a PHP session and ensures a CSRF token exists in it.
 * The token is available to callers via $_SESSION['csrf_token'] and is
 * output as a JS variable by app/partials/header.php.
 *
 * After authentication the matched user row (id, username, name, preferences
 * decoded to an array) is stored in $_SESSION['user'] for the rest of the
 * request and the header partial.
 *
 * Webhook handlers must NOT require this file — they authenticate via HMAC.
 */

require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Look up a user by username and verify the password.  Returns the user row
 * with preferences decoded into an associative array, or null on no match.
 */
function findUserByCredentials(PDO $db, string $username, string $password): ?array
{
    $stmt = $db->prepare("SELECT id, username, password_hash, name, preferences FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!password_verify($password, (string) $row['password_hash'])) {
        return null;
    }
    $prefs = json_decode((string) $row['preferences'], true);
    if (!is_array($prefs)) {
        $prefs = [];
    }
    return [
        'id'          => (int) $row['id'],
        'username'    => (string) $row['username'],
        'name'        => (string) $row['name'],
        'preferences' => $prefs,
    ];
}

/**
 * Sends a 401 and exits if the request does not carry valid HTTP Basic
 * credentials matching a row in the users table.  On success stores the user
 * in $_SESSION['user'] and returns it.
 */
function requireBasicAuth(array $config, string $realm = 'Orders'): array
{
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPass = $_SERVER['PHP_AUTH_PW']   ?? null;

    $user = null;
    if ($providedUser !== null && $providedPass !== null) {
        $db   = getDb($config);
        $user = findUserByCredentials($db, $providedUser, $providedPass);
    }

    if ($user === null) {
        header(sprintf('WWW-Authenticate: Basic realm="%s"', addslashes($realm)));
        require __DIR__ . '/401.php';
        exit;
    }

    $_SESSION['user'] = $user;
    return $user;
}

/**
 * Returns the currently authenticated user, or null if not authenticated.
 * Used by pages (e.g. index.php) that render differently when logged out
 * rather than forcing a 401.
 */
function currentUser(array $config): ?array
{
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPass = $_SERVER['PHP_AUTH_PW']   ?? null;
    if ($providedUser === null || $providedPass === null) {
        return null;
    }
    $db   = getDb($config);
    $user = findUserByCredentials($db, $providedUser, $providedPass);
    if ($user !== null) {
        $_SESSION['user'] = $user;
    }
    return $user;
}
