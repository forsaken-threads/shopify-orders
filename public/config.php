<?php
declare(strict_types=1);

/**
 * Configuration — loaded from env.ini if present, otherwise falls back to
 * real environment variables or built-in defaults.
 *
 * Copy env.ini.example → env.ini at the project root and fill in values.
 * Never commit env.ini.
 *
 * WEBHOOK_BEARER_TOKEN  Pre-shared Bearer token for authenticating webhook requests.
 * AUTH_USER             Username for the orders and download pages.
 * AUTH_PASSWORD         Password for the orders and download pages.
 */

// Load env.ini from the project root if present.
// Real environment variables take precedence over values in the file.
$iniPath = __DIR__ . '/../env.ini';
if (is_file($iniPath)) {
    $ini = parse_ini_file($iniPath);
    if (is_array($ini)) {
        foreach ($ini as $key => $value) {
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }
}

return [
    'db_path'              => __DIR__ . '/orders.sqlite',
    'webhook_bearer_token' => (string) (getenv('WEBHOOK_BEARER_TOKEN') ?: ''),
    'auth_user'            => (string) (getenv('AUTH_USER')            ?: 'admin'),
    'auth_password'        => (string) (getenv('AUTH_PASSWORD')        ?: 'changeme'),
];
