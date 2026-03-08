<?php
declare(strict_types=1);

/**
 * Shared Shopify webhook helpers.
 *
 * Provides HMAC-SHA256 signature verification and structured log appending.
 * Required by both public/webhooks/orders.php and public/webhooks/products.php.
 */

/**
 * Verify the X-Shopify-Hmac-Sha256 header against the raw request body.
 *
 * Uses hash_equals() for constant-time comparison to prevent timing attacks.
 * Returns false if the secret is empty or the header is absent.
 */
function verifyShopifyHmac(string $secret, string $rawBody): bool
{
    if ($secret === '') {
        return false;
    }
    $provided = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    if ($provided === '') {
        return false;
    }
    $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, binary: true));
    return hash_equals($computed, $provided);
}

/**
 * Append a timestamped entry to a webhook log file.
 *
 * Uses FILE_APPEND | LOCK_EX to reduce interleaving; errors are silenced
 * so a logging failure never causes the webhook to return a non-200 response.
 */
function webhookLog(string $file, string $identifier, string $topic): void
{
    $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $identifier, $topic);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
