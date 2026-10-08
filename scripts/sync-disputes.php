#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Re-check Shopify Payments disputes and send any alert that is owed.
 *
 * The webhook at public/webhooks/disputes.php emails as soon as Shopify
 * reports a dispute.  This script, run every 15 minutes from cron, covers what
 * the webhook cannot: a delivery Shopify never made, an email SMTP refused,
 * and the morning reminder while a dispute still needs a response.
 *
 * It also re-creates either webhook subscription when it is missing.  Shopify
 * deletes one after eight failed deliveries in a row, and tells only the app's
 * developer contact.
 *
 * Usage:
 *   php scripts/sync-disputes.php [--test-alert]
 *
 * Options:
 *   --test-alert   Send a sample alert to DISPUTE_ALERT_TO and exit.  Nothing
 *                  is asked of Shopify and nothing is written to the database.
 *
 * Requirements:
 *   - env.ini with the SHOPIFY_* and SMTP_* values, APP_BASE_URL and
 *     DISPUTE_ALERT_TO filled in.
 *   - shopify.ini written by install.php, from a visit made after
 *     read_shopify_payments_disputes joined its scope list.
 *
 * Exits 0 on success, 1 on configuration error, 2 when Shopify or SMTP refused
 * something.  Every run prints a summary line, so a log that has stopped
 * growing means cron has stopped running this.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);

$config = require $projectRoot . '/app/config.php';
require_once $projectRoot . '/app/db.php';
require $projectRoot . '/app/shopify.php';
require $projectRoot . '/app/disputes.php';

$say = static function (string $line): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
};

// ── --test-alert ──────────────────────────────────────────────────────────────

if (in_array('--test-alert', $argv ?? [], true)) {
    if ($config['dispute_alert_to'] === []) {
        fwrite(STDERR, "Error: DISPUTE_ALERT_TO is empty.  Set it in env.ini to the addresses that should be alerted.\n");
        exit(1);
    }

    $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $email = renderDisputeEmail(
        $config,
        [
            'shopify_order_id' => '0',
            'type'             => 'chargeback',
            'status'           => 'needs_response',
            'reason'           => 'product_not_received',
            'amount'           => 84.5,
            'currency'         => 'USD',
            'initiated_at'     => $now->format('Y-m-d H:i:s'),
            'evidence_due_by'  => $now->modify('+7 days')->format('Y-m-d H:i:s'),
            'alerted_state'    => null,
        ],
        ['id' => 0, 'order_number' => '0000', 'customer_name' => 'Sample Customer', 'customer_email' => 'sample@example.com'],
        'new',
        $now
    );
    $email['subject'] = '[TEST] ' . $email['subject'];
    $notice           = 'This is a test.  No dispute exists, and the links below lead nowhere.';
    $email['text']    = $notice . "\n\n" . $email['text'];
    $email['html']    = preg_replace(
        '/(<body[^>]*>)/',
        '$1<div style="background:#fef3c7; color:#92400e; padding:10px 24px; text-align:center; font-weight:700;">' . $notice . '</div>',
        $email['html'],
        1
    );

    if (!mailDisputeEmail($config, $email)) {
        fwrite(STDERR, "Error: no address accepted the sample alert.  The PHP error log has the SMTP detail.\n");
        exit(2);
    }
    echo 'Sent a sample alert to ' . implode(', ', $config['dispute_alert_to']) . ".\n";
    exit(0);
}

// ── Validate configuration ────────────────────────────────────────────────────

$shopDomain  = $config['shopify_shop_domain'];
$accessToken = $config['shopify_access_token'];
$apiVersion  = $config['shopify_api_version'];

if ($shopDomain === '' || $accessToken === '' || $apiVersion === '') {
    fwrite(STDERR, "Error: SHOPIFY_SHOP_DOMAIN, SHOPIFY_ACCESS_TOKEN, and SHOPIFY_API_VERSION must all be set.\n");
    fwrite(STDERR, "  - Set SHOPIFY_SHOP_DOMAIN and SHOPIFY_API_VERSION in env.ini.\n");
    fwrite(STDERR, "  - Run public/install.php once via a browser to obtain SHOPIFY_ACCESS_TOKEN.\n");
    exit(1);
}

$db       = getDb($config);
$apiBase  = sprintf('https://%s/admin/api/%s', $shopDomain, rawurlencode($apiVersion));
$exitCode = 0;

// ── Webhook subscriptions ─────────────────────────────────────────────────────
//
// Shopify delivers only to https, so a deployment without an https
// APP_BASE_URL (local development) subscribes nothing and relies on the list
// below alone.

$address = $config['app_base_url'] . '/webhooks/disputes.php';

if (str_starts_with($address, 'https://')) {
    $listed = shopifyGet($apiBase . '/webhooks.json?limit=250', $accessToken);
    $hooks  = $listed['status'] === 200 ? json_decode($listed['body'], associative: true) : null;

    if (!is_array($hooks) || !isset($hooks['webhooks'])) {
        $say(sprintf('Could not list webhook subscriptions (HTTP %d).', $listed['status']));
        $exitCode = 2;
    } else {
        foreach (['disputes/create', 'disputes/update'] as $topic) {
            foreach ($hooks['webhooks'] as $hook) {
                if (($hook['topic'] ?? '') === $topic && ($hook['address'] ?? '') === $address) {
                    continue 2;
                }
            }

            $payload = json_encode(
                ['webhook' => ['topic' => $topic, 'address' => $address, 'format' => 'json']],
                JSON_THROW_ON_ERROR
            );
            $response = @file_get_contents($apiBase . '/webhooks.json', false, stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", [
                        'X-Shopify-Access-Token: ' . $accessToken,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]),
                    'content'       => $payload,
                    'timeout'       => 15,
                    'ignore_errors' => true,
                ],
            ]));
            // Read only on an answer: a request that never connected leaves the
            // previous topic's headers in place.
            $status = 0;
            if ($response !== false && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
                $status = (int) $m[1];
            }

            if ($status === 201) {
                $say(sprintf('Subscribed %s to %s.', $topic, $address));
            } else {
                $say(sprintf('Could not subscribe %s (HTTP %d): %s', $topic, $status, trim((string) $response)));
                $exitCode = 2;
            }
        }
    }
}

// ── Disputes, as Shopify has them now ─────────────────────────────────────────
//
// One page, most recently opened first.  A dispute still open is always
// among the newest, so nothing further back can be owed an alert.

$checked = 'no';
$listed  = shopifyGet($apiBase . '/shopify_payments/disputes.json', $accessToken);
$data    = $listed['status'] === 200 ? json_decode($listed['body'], associative: true) : null;

if (!is_array($data) || !isset($data['disputes'])) {
    $say(sprintf(
        'Could not read the dispute list from Shopify (HTTP %d).  A 403 or 404 means the token lacks read_shopify_payments_disputes: visit /install.php as root to approve it.',
        $listed['status']
    ));
    $exitCode = 2;
} else {
    foreach ($data['disputes'] as $dispute) {
        recordDispute($db, $dispute);
    }
    $checked = (string) count($data['disputes']);
}

// ── Alerts ────────────────────────────────────────────────────────────────────

$alerts = sendDueDisputeAlerts($config, $db);

if ($alerts['failed'] > 0) {
    $exitCode = 2;
    if ($config['dispute_alert_to'] === []) {
        $say('DISPUTE_ALERT_TO is empty, so nobody can be alerted.  Set it in env.ini.');
    }
}

$say(sprintf(
    '%s disputes checked; alerts sent: %d, not sent: %d.',
    $checked,
    $alerts['sent'],
    $alerts['failed']
));

exit($exitCode);
