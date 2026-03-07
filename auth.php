<?php
declare(strict_types=1);

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
        http_response_code(401);
        exit('401 Unauthorized');
    }
}
