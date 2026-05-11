<?php
declare(strict_types=1);

/**
 * SMTP mailer wrapper around PHPMailer.
 *
 * Configuration is read from the $config array returned by app/config.php
 * (which itself loads env.ini).  Required keys:
 *   smtp_host, smtp_port, smtp_username, smtp_password,
 *   smtp_encryption ('tls' or 'ssl'),
 *   smtp_from_email, smtp_from_name.
 *
 * Returns true on successful queue, false on transport failure.  The error
 * detail is written to error_log() so callers don't need to surface SMTP
 * internals to end users.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendMail(
    array  $config,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody = ''
): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = (string) ($config['smtp_host'] ?? '');
        $mail->Port       = (int)    ($config['smtp_port'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) ($config['smtp_username'] ?? '');
        $mail->Password   = (string) ($config['smtp_password'] ?? '');
        $mail->SMTPSecure = strtolower((string) ($config['smtp_encryption'] ?? 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(
            (string) ($config['smtp_from_email'] ?? ''),
            (string) ($config['smtp_from_name']  ?? '')
        );
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        return $mail->send();
    } catch (Exception) {
        error_log('[mailer] SMTP send failed: ' . $mail->ErrorInfo);
        return false;
    }
}
