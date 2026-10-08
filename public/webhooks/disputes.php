<?php
declare(strict_types=1);

/**
 * Shopify disputes webhook endpoint.
 *
 * Supported topics: disputes/create, disputes/update
 *
 * Shopify's admin cannot subscribe to these, so scripts/sync-disputes.php
 * registers them through the Admin API.  A subscription made that way is
 * signed with the app's own secret, so the X-Shopify-Hmac-Sha256 header is
 * verified against SHOPIFY_API_SECRET here, not SHOPIFY_WEBHOOK_SECRET.
 *
 * The dispute is recorded, Shopify is answered, and only then is the alert
 * email sent: Shopify allows five seconds for the whole request, and an SMTP
 * conversation can take longer than that.
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require __DIR__ . '/../../app/webhook.php';
require __DIR__ . '/../../app/disputes.php';

// ── Validate HTTP method ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ── Read the payload ──────────────────────────────────────────────────────────

$rawBody = (string) file_get_contents('php://input');

// ── Authenticate via Shopify HMAC-SHA256 ─────────────────────────────────────

if (!verifyShopifyHmac($config['shopify_api_secret'], $rawBody)) {
    http_response_code(401);
    exit('Unauthorized');
}

$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

$handled = ['disputes/create', 'disputes/update'];
if (!in_array($topic, $handled, strict: true)) {
    http_response_code(200);
    exit('OK');
}

// ── Parse payload ─────────────────────────────────────────────────────────────

$logFile = dirname(__DIR__, 2) . '/logs/disputes.log';
$dispute = json_decode($rawBody, associative: true);

// Answered with 200 where the other endpoints answer 422.  Shopify deletes a
// subscription after eight failed deliveries in a row, and a payload that
// cannot be read now will not read on the retry either.
if (!is_array($dispute) || empty($dispute['id'])) {
    webhookLog($logFile, 'unreadable payload', $topic . ' (dropped)');
    http_response_code(200);
    exit('OK');
}

// ── Persist to SQLite ─────────────────────────────────────────────────────────

try {
    $db = getDb($config);
    recordDispute($db, $dispute);
} catch (Throwable $e) {
    error_log(sprintf('[disputes-webhook] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    exit('Internal Server Error');
}

webhookLog(
    $logFile,
    (string) $dispute['id'],
    sprintf('%s (%s, %s)', $topic, $dispute['type'] ?? '?', $dispute['status'] ?? '?')
);

http_response_code(200);
echo 'OK';

// ── Send the alert, after Shopify has its answer ──────────────────────────────

// Absent outside php-fpm, where the response simply waits for the email.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// A failure here leaves the dispute owed an alert, which scripts/sync-disputes.php
// sends on its next run.
try {
    sendDueDisputeAlerts($config, $db);
} catch (Throwable $e) {
    error_log(sprintf('[disputes-webhook] alert failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
}
