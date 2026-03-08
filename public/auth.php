<?php
declare(strict_types=1);

/**
 * HTTP Basic Auth helper.
 *
 * Also starts a PHP session and ensures a CSRF token exists in it.
 * The token is available to callers via $_SESSION['csrf_token'] and is
 * output as a JS variable by app/partials/header.php.
 *
 * Webhook handlers must NOT require this file — they authenticate via HMAC.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Sends a 401 and exits if the request does not carry valid HTTP Basic credentials.
 */
function requireBasicAuth(string $expectedUser, string $expectedPassword, string $realm = 'Orders'): void
{
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPass = $_SERVER['PHP_AUTH_PW']   ?? null;

    $valid = $providedUser !== null
        && hash_equals($expectedUser,     $providedUser)
        && hash_equals($expectedPassword, $providedPass);

    if (!$valid) {
        header(sprintf('WWW-Authenticate: Basic realm="%s"', addslashes($realm)));
        require __DIR__ . '/401.php';
        exit;
    }
}
