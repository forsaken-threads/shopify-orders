#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Enable or disable storefront password protection via the Shopify Admin
 * GraphQL API (onlineStorePasswordProtectionUpdate mutation).
 *
 * Usage:
 *   php scripts/toggle-password-protection.php enable
 *   php scripts/toggle-password-protection.php disable
 *
 * When enabling, the SHOPIFY_STOREFRONT_PASSWORD environment variable (or
 * env.ini entry) is used as the storefront password.  When disabling, no
 * password value is required.
 *
 * Requirements:
 *   - env.ini with SHOPIFY_SHOP_DOMAIN, SHOPIFY_API_VERSION, and
 *     SHOPIFY_STOREFRONT_PASSWORD (for enable) filled in.
 *   - shopify.ini written by install.php (contains SHOPIFY_ACCESS_TOKEN).
 *   - The access token must have the write_online_store scope.
 *
 * Exit codes: 0 = success, 1 = usage / configuration error, 2 = API error.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);
$config      = require $projectRoot . '/app/config.php';

// ── Parse arguments ───────────────────────────────────────────────────────────

$action = strtolower(trim($argv[1] ?? ''));

if (!in_array($action, ['enable', 'disable'], true)) {
    fwrite(STDERR, "Usage: php scripts/toggle-password-protection.php <enable|disable>\n");
    exit(1);
}

$passwordEnabled = $action === 'enable';

// ── Validate configuration ───────────────────────────────────────────────────

$shopDomain  = $config['shopify_shop_domain'];
$accessToken = $config['shopify_access_token'];
$apiVersion  = $config['shopify_api_version'];

if ($shopDomain === '' || $accessToken === '' || $apiVersion === '') {
    fwrite(STDERR, "Error: SHOPIFY_SHOP_DOMAIN, SHOPIFY_ACCESS_TOKEN, and SHOPIFY_API_VERSION must all be set.\n");
    fwrite(STDERR, "  - Set SHOPIFY_SHOP_DOMAIN and SHOPIFY_API_VERSION in env.ini.\n");
    fwrite(STDERR, "  - Run public/install.php once via a browser to obtain SHOPIFY_ACCESS_TOKEN.\n");
    exit(1);
}

$storefrontPassword = (string) (getenv('SHOPIFY_STOREFRONT_PASSWORD') ?: '');

if ($passwordEnabled && $storefrontPassword === '') {
    fwrite(STDERR, "Error: SHOPIFY_STOREFRONT_PASSWORD must be set in env.ini when enabling password protection.\n");
    exit(1);
}

// ── Build GraphQL mutation ───────────────────────────────────────────────────

$mutation = <<<'GRAPHQL'
mutation togglePasswordProtection($input: OnlineStorePasswordProtectionInput!) {
    onlineStorePasswordProtectionUpdate(input: $input) {
        onlineStorePasswordProtection {
            id
            passwordEnabled
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

$variables = ['input' => ['passwordEnabled' => $passwordEnabled]];

if ($passwordEnabled) {
    $variables['input']['password'] = $storefrontPassword;
}

$payload = json_encode([
    'query'     => $mutation,
    'variables' => $variables,
], JSON_THROW_ON_ERROR);

// ── Send request ─────────────────────────────────────────────────────────────

$url = sprintf(
    'https://%s/admin/api/%s/graphql.json',
    $shopDomain,
    rawurlencode($apiVersion)
);

echo sprintf("Sending request to %s password protection on %s…\n", $action, $shopDomain);

$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $accessToken,
        ]),
        'content'       => $payload,
        'timeout'       => 30,
        'ignore_errors' => true,
    ],
]);

$body = @file_get_contents($url, false, $context);

if ($body === false) {
    fwrite(STDERR, "Error: Could not reach Shopify API. Check outbound connectivity.\n");
    exit(2);
}

$statusLine = $http_response_header[0] ?? '';
preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m);
$status = (int) ($m[1] ?? 0);

if ($status < 200 || $status >= 300) {
    fwrite(STDERR, sprintf("Error: Shopify API returned HTTP %d.\n%s\n", $status, $body));
    exit(2);
}

try {
    $data = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    fwrite(STDERR, "Error: Shopify returned invalid JSON: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Handle response ──────────────────────────────────────────────────────────

$result     = $data['data']['onlineStorePasswordProtectionUpdate'] ?? null;
$userErrors = $result['userErrors'] ?? [];

if (!empty($userErrors)) {
    fwrite(STDERR, "Shopify returned errors:\n");
    foreach ($userErrors as $err) {
        fwrite(STDERR, sprintf("  - [%s] %s\n", implode('.', (array) ($err['field'] ?? [])), $err['message'] ?? ''));
    }
    exit(2);
}

$protection = $result['onlineStorePasswordProtection'] ?? null;

if ($protection === null) {
    fwrite(STDERR, "Error: Unexpected API response.\n" . $body . "\n");
    exit(2);
}

$state = $protection['passwordEnabled'] ? 'enabled' : 'disabled';
echo "Password protection is now {$state}.\n";

exit(0);
