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
 * AUTH_USER               Seed username for the first user, read once by
 *                         scripts/migrate.php into the users table.  After the
 *                         seed runs, auth is database-backed and this value is
 *                         ignored.
 * AUTH_PASSWORD           Seed password for the first user; see AUTH_USER.
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

// Resolve the label printer's ssh target from the WAN route.
//
// carmarthen answers which route is up in root-owned /etc/backup-route;
// scripts/sync-print-route.sh copies that file here as route.ini, because this
// app runs in a container whose /etc is not the host's.  Only the route name is
// imported — what the target is on each route is this repo's business, and
// lives in env.ini as PRINT_SSH_TARGET_DIRECT / PRINT_SSH_TARGET_TUNNEL.
//
// An explicit PRINT_SSH_TARGET still wins, so it pins the target and this
// changes nothing until that key is removed from a deployment's env.ini.
$routeIniPath = __DIR__ . '/../route.ini';
$printRoute   = '';
if (is_file($routeIniPath)) {
    $routeIni = parse_ini_file($routeIniPath);
    if (is_array($routeIni)) {
        $printRoute = strtoupper(trim((string) ($routeIni['route'] ?? '')));
    }
}

$printSshTarget = (string) (getenv('PRINT_SSH_TARGET') ?: '');
if ($printSshTarget === '' && $printRoute !== '') {
    $printSshTarget = (string) (getenv('PRINT_SSH_TARGET_' . $printRoute) ?: '');
}
if ($printSshTarget === '') {
    $printSshTarget = 'keith@percival.spartang.com';
}

return [
    'db_path'                => __DIR__ . '/../orders.sqlite',
    // Current application version.  Bumped per commit when there are
    // user-visible changes worth recording in app/changelog.php.  The header
    // bell icon shows an unseen-changes badge when this differs from the
    // signed-in user's preferences.last_version_seen.
    'app_version'            => '1.22.0',
    'shopify_webhook_secret' => (string) (getenv('SHOPIFY_WEBHOOK_SECRET') ?: ''),
    'shopify_api_key'        => (string) (getenv('SHOPIFY_API_KEY')        ?: ''),
    'shopify_api_secret'     => (string) (getenv('SHOPIFY_API_SECRET')     ?: ''),
    'shopify_shop_domain'    => (string) (getenv('SHOPIFY_SHOP_DOMAIN')    ?: ''),
    'shopify_api_version'    => (string) (getenv('SHOPIFY_API_VERSION')    ?: '2025-01'),
    'shopify_access_token'   => $shopifyAccessToken,
    'shopify_ini_path'       => $shopifyIniPath,
    // Environment name; when not "production" it is displayed in the header
    // bar so non-prod deployments are visually distinct.
    'app_env'                => (string) (getenv('APP_ENV')                ?: 'production'),
    // Timezone used when displaying order dates in the web UI.
    // Set DISPLAY_TIMEZONE in env.ini to any valid PHP timezone identifier,
    // e.g. America/New_York, Europe/London, Australia/Sydney.
    'display_timezone'       => (string) (getenv('DISPLAY_TIMEZONE')       ?: 'America/Detroit'),
    // user@host passed to ssh by print-order.php.  Resolved above from
    // PRINT_SSH_TARGET, else the route in route.ini, else the prod default.
    'print_ssh_target'       => $printSshTarget,
    // ── SMTP (used by app/mailer.php for password-reset emails) ─────────────
    'smtp_host'              => (string) (getenv('SMTP_HOST')              ?: ''),
    'smtp_port'              => (int)    (getenv('SMTP_PORT')              ?: 587),
    'smtp_username'          => (string) (getenv('SMTP_USERNAME')          ?: ''),
    'smtp_password'          => (string) (getenv('SMTP_PASSWORD')          ?: ''),
    // 'tls' (STARTTLS), 'ssl' (SMTPS), or 'none' (cleartext — only sensible
    // on a trusted LAN or against a local MTA).
    'smtp_encryption'        => (string) (getenv('SMTP_ENCRYPTION')        ?: 'tls'),
    // When false, PHPMailer skips peer verification and accepts self-signed
    // certs — useful for local dev MTAs.  Defaults to true (secure).
    // parse_ini_file's "false" magic-word turns into an empty string in
    // getenv(), which we map to false here along with "0"/"no"/"off".
    'smtp_verify_peer'       => (static function () {
        $v = getenv('SMTP_VERIFY_PEER');
        if ($v === false) {
            return true;
        }
        return !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off', ''], true);
    })(),
    'smtp_from_email'        => (string) (getenv('SMTP_FROM_EMAIL')        ?: ''),
    'smtp_from_name'         => (string) (getenv('SMTP_FROM_NAME')         ?: 'Cent Notes'),
    // Absolute base URL of this deployment (no trailing slash).  Used to build
    // password-reset links in outgoing email, where relative URLs aren't valid.
    'app_base_url'           => rtrim((string) (getenv('APP_BASE_URL')     ?: ''), '/'),
    // Day of week the pay-period week starts.  'sun' (Sunday→Saturday),
    // 'mon' (Monday→Sunday), or 'sat' (Saturday→Friday).  Used by the
    // time-clock pages.  Falls back to 'sun' on any unrecognised value.
    'pay_week_start'         => (static function () {
        $raw = strtolower((string) (getenv('PAY_WEEK_START') ?: 'sun'));
        return in_array($raw, ['sun', 'mon', 'sat'], true) ? $raw : 'sun';
    })(),
    // Company contact details shown on official documents (e.g. the printable
    // earnings statement letterhead).  Defaults match Decantalize's published
    // contact info; override per-deployment in env.ini.
    'company_phone'          => (string) (getenv('COMPANY_PHONE')   ?: '248-313-8711'),
    'company_website'        => (string) (getenv('COMPANY_WEBSITE') ?: 'decantalize.com'),
    'company_email'          => (string) (getenv('COMPANY_EMAIL')   ?: 'sales@decantalize.com'),
];
