<?php
declare(strict_types=1);

/**
 * Shopify OAuth installation handler.
 *
 * Visit this page once to authorize the app and obtain a permanent offline
 * Admin API access token.  Re-visit whenever you rotate the app credentials
 * or install the app on a new store — the new token overwrites shopify.ini.
 *
 * Flow:
 *   1. Visit /install.php
 *        → if a working token already exists and holds every scope in
 *          $scopes, shows a status page.
 *        → if it works but lacks a scope, names the missing ones and offers
 *          consent behind a button.
 *        → otherwise redirects to the Shopify OAuth consent page.
 *   2. Merchant approves the app in Shopify admin.
 *        → Shopify redirects back to /install.php?code=…&hmac=…&shop=…
 *   3. The authorization code is exchanged for a permanent access token.
 *        → Token is written to shopify.ini in the project root, and the
 *          browser is sent back to step 1 to report on it.
 *
 * Requires SHOPIFY_API_KEY, SHOPIFY_API_SECRET, and SHOPIFY_SHOP_DOMAIN
 * to be set in env.ini (or as real environment variables).
 */

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';

requirePermission($config, 'shopify_install');

$apiKey     = $config['shopify_api_key'];
$apiSecret  = $config['shopify_api_secret'];
$shopDomain = $config['shopify_shop_domain'];
$apiVersion = $config['shopify_api_version'];
$iniPath    = $config['shopify_ini_path'];

// write_inventory is for setting stock from the Out of Stock report, and
// read_shopify_payments_disputes for the dispute alerts in app/disputes.php.
// Shopify grants a scope only once the app's own configuration in Shopify
// declares it, so adding one here also needs a new app version released and
// installed there.  Consent alone issues a token without it.
$scopes = ['read_products', 'read_orders', 'write_inventory', 'read_shopify_payments_disputes'];

if ($apiKey === '' || $apiSecret === '' || $shopDomain === '') {
    http_response_code(500);
    exit('SHOPIFY_API_KEY, SHOPIFY_API_SECRET, and SHOPIFY_SHOP_DOMAIN must all be set in env.ini before running the installer.');
}

// Build the absolute callback URL for this script so Shopify can redirect back.
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$scheme = $forwardedProto !== '' ? strtolower(explode(',', $forwardedProto)[0]) : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$callbackUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

$authUrl = sprintf(
    'https://%s/admin/oauth/authorize?client_id=%s&scope=%s&redirect_uri=%s',
    $shopDomain,
    rawurlencode($apiKey),
    rawurlencode(implode(',', $scopes)),
    rawurlencode($callbackUrl)
);

// ── Shared layout helpers ─────────────────────────────────────────────────────

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $bodyContent): void
{
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title} - Cent Notes</title>
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                font-family: system-ui, -apple-system, sans-serif;
                background: #f0f2f5;
                color: #1a1a2e;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }

            .navbar {
                background: #1a1a2e;
                padding: .875rem 2rem;
                display: flex;
                align-items: center;
            }

            .navbar-brand {
                font-size: .95rem;
                font-weight: 700;
                color: #fff;
                text-decoration: none;
                letter-spacing: .03em;
            }

            .main {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 3rem 2rem;
            }

            .card {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,.09);
                padding: 2.5rem 3rem;
                max-width: 500px;
                width: 100%;
            }

            .card-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2.75rem;
                height: 2.75rem;
                border-radius: 8px;
                margin-bottom: 1.25rem;
            }

            .card-icon svg {
                width: 1.35rem;
                height: 1.35rem;
                stroke-width: 2;
                stroke-linecap: round;
                stroke-linejoin: round;
                fill: none;
            }

            .icon-success { background: #f0fff4; }
            .icon-success svg { stroke: #38a169; }
            .icon-warning { background: #fffbeb; }
            .icon-warning svg { stroke: #d97706; }

            h1 {
                font-size: 1.2rem;
                font-weight: 700;
                margin-bottom: .6rem;
            }

            p {
                font-size: .9rem;
                color: #555;
                line-height: 1.6;
                margin-bottom: 1rem;
            }

            p:last-of-type { margin-bottom: 1.5rem; }

            .btn {
                display: inline-block;
                padding: .55rem 1.4rem;
                background: #1a1a2e;
                color: #fff;
                text-decoration: none;
                border-radius: 7px;
                font-size: .875rem;
                font-weight: 600;
                transition: background .15s;
            }

            .btn:hover { background: #2d2d5e; }

            .meta {
                margin-top: 1.25rem;
                font-size: .78rem;
                color: #aaa;
                border-top: 1px solid #f0f0f0;
                padding-top: 1rem;
            }

            @media (max-width: 560px) {
                .card { padding: 2rem 1.5rem; }
                .navbar { padding: .75rem 1rem; }
            }
        </style>
    </head>
    <body>
    <nav class="navbar">
        <a class="navbar-brand" href="index.php">Cent Notes</a>
    </nav>
    <main class="main">
        <div class="card">
            {$bodyContent}
        </div>
    </main>
    </body>
    </html>
    HTML;
    exit;
}

// ── Step 2: OAuth callback — exchange code for access token ──────────────────

if (isset($_GET['code'])) {
    // Verify the HMAC Shopify attaches to the callback to prevent tampering.
    $hmacProvided = (string) ($_GET['hmac'] ?? '');
    $params = $_GET;
    unset($params['hmac']);
    ksort($params);
    $computed = hash_hmac('sha256', http_build_query($params), $apiSecret);

    if (!hash_equals($computed, $hmacProvided)) {
        http_response_code(400);
        exit('Invalid HMAC — possible request tampering detected.');
    }

    // Exchange the authorization code for a permanent offline access token.
    $postBody = http_build_query([
        'client_id'     => $apiKey,
        'client_secret' => $apiSecret,
        'code'          => (string) $_GET['code'],
    ]);

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($postBody),
                'Accept: application/json',
            ]),
            'content'       => $postBody,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $tokenUrl = sprintf('https://%s/admin/oauth/access_token', $shopDomain);
    $response = @file_get_contents($tokenUrl, false, $context);

    if ($response === false) {
        http_response_code(502);
        exit('Could not reach Shopify token endpoint. Check your server\'s outbound connectivity.');
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('#HTTP/\S+\s+(2\d{2})#', $statusLine)) {
        http_response_code(502);
        exit('Shopify returned an error: ' . h($statusLine) . ' — ' . h($response));
    }

    try {
        $data = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        http_response_code(502);
        exit('Shopify returned invalid JSON from the token endpoint.');
    }

    $token = (string) ($data['access_token'] ?? '');
    if ($token === '') {
        http_response_code(502);
        exit('Shopify did not return an access_token. Response: ' . h($response));
    }

    // Persist the token to shopify.ini (gitignored).
    $ini  = '; Shopify Admin API access token.' . PHP_EOL;
    $ini .= '; Auto-generated by install.php — do not edit by hand and never commit this file.' . PHP_EOL;
    $ini .= 'SHOPIFY_ACCESS_TOKEN = ' . $token . PHP_EOL;

    if (file_put_contents($iniPath, $ini) === false) {
        http_response_code(500);
        exit('Token was obtained but could not be written to ' . h($iniPath) . '. Check file-system permissions.');
    }

    // Shopify issues a token even when it withheld a scope, so this page does
    // not report success itself: the pre-flight below judges the new token.
    // The flag stops a token that fails its test call from going straight
    // back to consent, which would loop until the browser gave up.
    $_SESSION['install_token_minted'] = true;
    header('Location: install.php');
    exit;
}

// ── Token pre-flight check ────────────────────────────────────────────────────

$existingToken = $config['shopify_access_token'];
$justMinted    = !empty($_SESSION['install_token_minted']);
unset($_SESSION['install_token_minted']);

if ($existingToken !== '') {
    // Test the token with a lightweight Admin API call.
    $testUrl = sprintf('https://%s/admin/api/%s/shop.json', $shopDomain, $apiVersion);
    $testContext = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => 'X-Shopify-Access-Token: ' . $existingToken . "\r\nAccept: application/json",
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
    ]);

    $testResponse   = @file_get_contents($testUrl, false, $testContext);
    $testStatusLine = $http_response_header[0] ?? '';
    $tokenWorking   = $testResponse !== false && preg_match('#HTTP/\S+\s+200#', $testStatusLine);

    // A token minted before a scope was added to $scopes still answers
    // shop.json, so it would read as working here while every call needing
    // the new scope is refused.
    $granted = [];
    if ($tokenWorking) {
        $scopesResponse = @file_get_contents(sprintf('https://%s/admin/oauth/access_scopes.json', $shopDomain), false, $testContext);
        $scopesData     = $scopesResponse !== false ? json_decode($scopesResponse, associative: true) : null;
        foreach ((is_array($scopesData) ? $scopesData['access_scopes'] ?? [] : []) as $scope) {
            $granted[] = (string) ($scope['handle'] ?? '');
        }
    }

    $missing = array_values(array_diff($scopes, $granted));

    if ($tokenWorking && $missing === []) {
        renderPage('Installation', <<<HTML
            <div class="card-icon icon-success">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h1>Token is working</h1>
            <p>An existing Shopify access token is stored and verified against the API &mdash; everything is configured correctly.</p>
            <p>To re-authorize or rotate credentials, remove <code>shopify.ini</code> and revisit this page.</p>
            <a class="btn" href="orders.php">View pending orders</a>
        HTML);
    }

    $warningIcon = '<div class="card-icon icon-warning"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>';

    // Consent grants a missing scope only once the app's configuration
    // declares it, so this asks rather than redirecting: otherwise every visit
    // would mint another token without it.
    if ($tokenWorking) {
        $missingList = implode(', ', array_map(static fn(string $s): string => '<code>' . h($s) . '</code>', $missing));
        $authHref    = h($authUrl);
        renderPage('Missing Shopify Permissions', <<<HTML
            {$warningIcon}
            <h1>Shopify has not granted every permission</h1>
            <p>The stored access token works, but it does not hold {$missingList}.</p>
            <p>Shopify grants a permission only once the app&rsquo;s own configuration declares it.  In Shopify, add the missing scopes to the app&rsquo;s configuration, release that as a new version, and install the version on the store.  Then approve here.</p>
            <a class="btn" href="{$authHref}">Approve in Shopify</a>
            <p class="meta">Approving before that version is installed issues another token without them, and this page comes back.</p>
        HTML);
    }

    if ($justMinted) {
        $failure = h($testResponse === false ? 'no response' : $testStatusLine);
        renderPage('Token Not Working', <<<HTML
            {$warningIcon}
            <h1>Shopify issued a token that does not work</h1>
            <p>A new access token was saved to <code>shopify.ini</code>, but a test call to the Admin API failed: {$failure}.</p>
            <p>Asking Shopify for another token will not help until that is fixed, so this page has stopped here.  Check <code>SHOPIFY_SHOP_DOMAIN</code> and <code>SHOPIFY_API_VERSION</code> in <code>env.ini</code>, then test again.</p>
            <a class="btn" href="install.php">Test again</a>
        HTML);
    }

    // The token failed its test call — fall through to OAuth flow below.
}

// ── Step 1: Redirect to Shopify OAuth consent page ───────────────────────────

header('Location: ' . $authUrl);
exit;
