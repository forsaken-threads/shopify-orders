<?php
declare(strict_types=1);

/**
 * SMTP mailer wrapper around PHPMailer.
 *
 * Configuration is read from the $config array returned by app/config.php
 * (which itself loads env.ini).  Required keys:
 *   smtp_host, smtp_port, smtp_username, smtp_password,
 *   smtp_encryption ('tls', 'ssl', or 'none'),
 *   smtp_verify_peer (bool — false skips cert verification, allowing
 *                     self-signed certs on a local dev MTA),
 *   smtp_from_email, smtp_from_name.
 *
 * Auth is enabled only when smtp_username or smtp_password is non-empty, so
 * an unauthenticated local relay works without bogus credentials.
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
        $mail->Host    = (string) ($config['smtp_host'] ?? '');
        $mail->Port    = (int)    ($config['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';

        $smtpUser = (string) ($config['smtp_username'] ?? '');
        $smtpPass = (string) ($config['smtp_password'] ?? '');
        $mail->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        }

        switch (strtolower((string) ($config['smtp_encryption'] ?? 'tls'))) {
            case 'ssl':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'none':
            case '':
                // Cleartext — also disable PHPMailer's opportunistic STARTTLS
                // upgrade, otherwise it'll try to negotiate against a server
                // that advertises STARTTLS even though we asked for plain.
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
                break;
            default:
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        if (($config['smtp_verify_peer'] ?? true) === false) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

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
