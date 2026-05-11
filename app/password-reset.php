<?php
declare(strict_types=1);

/**
 * Shared password-reset helpers.
 *
 * Both /forgot-password.php (self-service) and /users.php (admin "Reset
 * password" button) mint tokens and send the same email — this file holds
 * the common code: link construction, email templates, and the
 * generate-and-send entry point.
 *
 * Tokens are stored as SHA-256 of a random 32-byte hex string with a
 * one-hour expiry.  Issuing a new token invalidates any prior unused
 * tokens for the same user, so only the most recent link can be redeemed.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Mint a one-hour single-use reset token for $userId and email it to the
 * supplied address.  Returns true on success, false when the email is
 * empty or SMTP delivery failed.  Callers should already have confirmed
 * the user exists and is active.
 */
function generateAndEmailReset(array $config, int $userId, string $userName, string $userEmail): bool
{
    $userEmail = trim($userEmail);
    if ($userEmail === '') {
        return false;
    }

    $db = getDb($config);

    // Invalidate any prior unused tokens for this user.
    $db->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL")
       ->execute([$userId]);

    // Persist only the SHA-256 hash; the raw token only leaves in email.
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $db->prepare(
        "INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (?, ?, datetime('now', '+1 hour'))"
    )->execute([$userId, $tokenHash]);

    $resetUrl = buildResetUrl($config, $rawToken);
    return sendMail(
        $config,
        $userEmail,
        $userName,
        'Reset your Cent Notes password',
        renderResetEmailHtml($userName, $resetUrl),
        renderResetEmailText($userName, $resetUrl),
    );
}

/**
 * Build the absolute reset URL.  Uses APP_BASE_URL from config when set;
 * otherwise infers scheme + host from the current request (works for normal
 * deployments but can be wrong behind reverse proxies that don't forward
 * X-Forwarded-Proto, which is why APP_BASE_URL exists).
 */
function buildResetUrl(array $config, string $rawToken): string
{
    $base = (string) ($config['app_base_url'] ?? '');
    if ($base === '') {
        $fwd    = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $scheme = $fwd !== ''
            ? strtolower(explode(',', $fwd)[0])
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/reset-password.php?token=' . urlencode($rawToken);
}

function renderResetEmailHtml(string $name, string $url): string
{
    $greeting = $name !== '' ? 'Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',' : 'Hi,';
    $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html>
<html><body style="font-family: system-ui, sans-serif; color: #1a1a2e; line-height: 1.55;">
<p>{$greeting}</p>
<p>Someone requested a password reset for your Cent Notes account.  If that
was you, click the link below to choose a new password.  The link is valid
for one hour and can only be used once.</p>
<p><a href="{$safeUrl}" style="display:inline-block; padding:.6rem 1.25rem; background:#1a1a2e; color:#fff; text-decoration:none; border-radius:6px;">Reset password</a></p>
<p style="font-size: .85rem; color: #666;">Or copy this URL into your browser:<br><a href="{$safeUrl}">{$safeUrl}</a></p>
<p style="font-size: .85rem; color: #666;">If you didn't request this, you can ignore this email — your password won't change.</p>
</body></html>
HTML;
}

function renderResetEmailText(string $name, string $url): string
{
    $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';
    return <<<TEXT
{$greeting}

Someone requested a password reset for your Cent Notes account.  If that was
you, open this link to choose a new password.  It is valid for one hour and
can only be used once.

{$url}

If you didn't request this, you can ignore this email — your password
won't change.
TEXT;
}
