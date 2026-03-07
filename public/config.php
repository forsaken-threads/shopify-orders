<?php
declare(strict_types=1);

/**
 * Configuration — loaded from env.ini if present, otherwise falls back to
 * real environment variables or built-in defaults.
 *
 * Copy env.ini.example → env.ini at the project root and fill in values.
 * Never commit env.ini.
 *
 * SHOPIFY_WEBHOOK_SECRET  Shopify-provided secret for verifying X-Shopify-Hmac-Sha256.
 * SHOPIFY_API_KEY         API key for your Shopify app (used for OAuth token acquisition).
 * SHOPIFY_API_SECRET      API secret for your Shopify app (used for OAuth token acquisition).
 * SHOPIFY_SHOP_DOMAIN     Your store domain, e.g. your-store.myshopify.com.
 * SHOPIFY_API_VERSION     Pinned Admin API version, e.g. 2025-01.
 * AUTH_USER               Username for the orders and download pages.
 * AUTH_PASSWORD           Password for the orders and download pages.
 *
 * The Admin API access token is NOT stored in env.ini. It is obtained once via
 * install.php (OAuth) and persisted in shopify.ini at the project root.
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

// Load the Admin API access token from shopify.ini (written by install.php).
// This file is gitignored and must not be committed.
$shopifyIniPath = __DIR__ . '/../shopify.ini';
$shopifyAccessToken = '';
if (is_file($shopifyIniPath)) {
    $shopifyIni = parse_ini_file($shopifyIniPath);
    if (is_array($shopifyIni)) {
        $shopifyAccessToken = (string) ($shopifyIni['SHOPIFY_ACCESS_TOKEN'] ?? '');
    }
}

return [
    'db_path'                => __DIR__ . '/../orders.sqlite',
    'shopify_webhook_secret' => (string) (getenv('SHOPIFY_WEBHOOK_SECRET') ?: ''),
    'shopify_api_key'        => (string) (getenv('SHOPIFY_API_KEY')        ?: ''),
    'shopify_api_secret'     => (string) (getenv('SHOPIFY_API_SECRET')     ?: ''),
    'shopify_shop_domain'    => (string) (getenv('SHOPIFY_SHOP_DOMAIN')    ?: ''),
    'shopify_api_version'    => (string) (getenv('SHOPIFY_API_VERSION')    ?: '2025-01'),
    'shopify_access_token'   => $shopifyAccessToken,
    'shopify_ini_path'       => $shopifyIniPath,
    'auth_user'              => (string) (getenv('AUTH_USER')              ?: 'admin'),
    'auth_password'          => (string) (getenv('AUTH_PASSWORD')          ?: 'changeme'),
];
