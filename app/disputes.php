<?php
declare(strict_types=1);

/**
 * Shopify Payments disputes: recording them and emailing about them.
 *
 * Two callers hand this file the same Dispute resource: the webhook at
 * public/webhooks/disputes.php, and scripts/sync-disputes.php reading
 * Shopify's own list.  Both record what they were given and then call
 * sendDueDisputeAlerts(), so one rule decides whether an email goes out.
 *
 * An email is owed when a dispute's type and status differ from what the last
 * email reported, or when Shopify is still waiting for evidence and the last
 * email went on an earlier day.  A send that fails leaves the row owed, which
 * is how the next run retries it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const DISPUTE_CLOSED_STATUSES = ['won', 'lost', 'accepted', 'charge_refunded'];

// Local hour before which the daily reminder does not go out.
const DISPUTE_REMINDER_HOUR = 8;

const DISPUTE_REASONS = [
    'bank_not_process'          => "The customer's bank could not process the charge.",
    'credit_not_processed'      => 'The customer says a refund or credit they were owed never arrived.',
    'customer_initiated'        => 'The customer started the dispute.  Contact them for the details.',
    'debit_not_authorized'      => "The customer's bank says the debit was not authorized.",
    'duplicate'                 => 'The customer says they were charged more than once.',
    'fraudulent'                => 'The cardholder says they did not authorize the payment.',
    'general'                   => 'No specific reason was given.  Contact the customer for the details.',
    'incorrect_account_details' => 'The account details on the purchase were wrong.',
    'insufficient_funds'        => "The customer's bank account did not have the funds.",
    'product_not_received'      => 'The customer says the order never arrived.',
    'product_unacceptable'      => 'The customer says the product was defective, damaged or not as described.',
    'subscription_canceled'     => 'The customer says they were charged after canceling a subscription.',
    'unrecognized'              => 'The customer does not recognize the charge.',
];

/**
 * Insert or refresh one dispute from Shopify's Dispute resource.
 *
 * A dispute first seen already closed is recorded as reported.  The first
 * sync after install reads the shop's history, and nobody needs an email
 * about a chargeback settled last year.
 */
function recordDispute(PDO $db, array $dispute): void
{
    $type   = (string) ($dispute['type']   ?? '');
    $status = (string) ($dispute['status'] ?? '');

    $toUtc = static function (mixed $iso): ?string {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    };

    $db->prepare(<<<'SQL'
        INSERT INTO disputes
            (shopify_dispute_id, shopify_order_id, type, status, reason, amount,
             currency, initiated_at, evidence_due_by, raw_data, alerted_state)
        VALUES
            (:dispute_id, :order_id, :type, :status, :reason, :amount,
             :currency, :initiated_at, :evidence_due_by, :raw_data, :alerted_state)
        ON CONFLICT(shopify_dispute_id) DO UPDATE SET
            shopify_order_id = excluded.shopify_order_id,
            type             = excluded.type,
            status           = excluded.status,
            reason           = excluded.reason,
            amount           = excluded.amount,
            currency         = excluded.currency,
            initiated_at     = excluded.initiated_at,
            evidence_due_by  = excluded.evidence_due_by,
            raw_data         = excluded.raw_data
    SQL)->execute([
        ':dispute_id'      => (string) $dispute['id'],
        ':order_id'        => isset($dispute['order_id']) ? (string) $dispute['order_id'] : null,
        ':type'            => $type,
        ':status'          => $status,
        ':reason'          => isset($dispute['reason']) ? (string) $dispute['reason'] : null,
        ':amount'          => (float) ($dispute['amount'] ?? 0.0),
        ':currency'        => (string) ($dispute['currency'] ?? 'USD'),
        ':initiated_at'    => $toUtc($dispute['initiated_at'] ?? null),
        ':evidence_due_by' => $toUtc($dispute['evidence_due_by'] ?? null),
        ':raw_data'        => json_encode($dispute, JSON_THROW_ON_ERROR),
        ':alerted_state'   => in_array($status, DISPUTE_CLOSED_STATUSES, true) ? $type . ':' . $status : null,
    ]);
}

/**
 * Which email this row is owed, if any: 'new', 'changed', 'reminder' or null.
 */
function disputeAlertKind(array $row, DateTimeImmutable $now, DateTimeZone $tz): ?string
{
    if ($row['alerted_state'] !== $row['type'] . ':' . $row['status']) {
        return $row['alerted_at'] === null ? 'new' : 'changed';
    }
    if ($row['status'] !== 'needs_response') {
        return null;
    }

    $nowLocal  = $now->setTimezone($tz);
    $lastLocal = (new DateTimeImmutable($row['alerted_at'], new DateTimeZone('UTC')))->setTimezone($tz);

    return $lastLocal->format('Y-m-d') < $nowLocal->format('Y-m-d')
        && (int) $nowLocal->format('G') >= DISPUTE_REMINDER_HOUR
        ? 'reminder'
        : null;
}

/**
 * Email every dispute that is owed one.
 *
 * Each row is marked as reported before its email is sent and put back if the
 * send fails, so the webhook and the cron job arriving together send one email
 * rather than two.  With no recipients configured nothing is marked, and
 * everything owed is counted as not sent.
 *
 * @return array{sent: int, failed: int}
 */
function sendDueDisputeAlerts(array $config, PDO $db): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $tz  = new DateTimeZone($config['display_timezone']);

    $claim = $db->prepare(
        'UPDATE disputes SET alerted_state = :state, alerted_at = :now
          WHERE id = :id AND alerted_state IS :old_state AND alerted_at IS :old_at'
    );
    $restore = $db->prepare('UPDATE disputes SET alerted_state = :old_state, alerted_at = :old_at WHERE id = :id');
    $findOrder = $db->prepare(
        'SELECT id, order_number, customer_name, customer_email FROM orders WHERE shopify_order_id = ?'
    );

    $sent   = 0;
    $failed = 0;

    foreach ($db->query('SELECT * FROM disputes ORDER BY id')->fetchAll() as $row) {
        $kind = disputeAlertKind($row, $now, $tz);
        if ($kind === null) {
            continue;
        }
        if ($config['dispute_alert_to'] === []) {
            $failed++;
            continue;
        }

        $claim->execute([
            ':state'     => $row['type'] . ':' . $row['status'],
            ':now'       => $now->format('Y-m-d H:i:s'),
            ':id'        => $row['id'],
            ':old_state' => $row['alerted_state'],
            ':old_at'    => $row['alerted_at'],
        ]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $findOrder->execute([$row['shopify_order_id']]);
        $order = $findOrder->fetch() ?: null;

        if (mailDisputeEmail($config, renderDisputeEmail($config, $row, $order, $kind, $now))) {
            $sent++;
            continue;
        }

        $restore->execute([
            ':old_state' => $row['alerted_state'],
            ':old_at'    => $row['alerted_at'],
            ':id'        => $row['id'],
        ]);
        $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Send one rendered alert to every address in DISPUTE_ALERT_TO.
 *
 * True when at least one address accepted it.  Requiring all of them would
 * let a single mistyped address keep the alert owed, and every run would
 * send it to the good addresses again.
 *
 * @param array{subject: string, html: string, text: string, urgent: bool} $email
 */
function mailDisputeEmail(array $config, array $email): bool
{
    $sentAny = false;
    foreach ($config['dispute_alert_to'] as $address) {
        $sentAny = sendMail($config, $address, '', $email['subject'], $email['html'], $email['text'], $email['urgent'])
            || $sentAny;
    }
    return $sentAny;
}

/**
 * Build the alert for one dispute row.  $order is its row from the orders
 * table, or null when Cent Notes does not hold that order.
 *
 * Only a dispute still waiting for evidence is loud: capitals, a red banner
 * and high priority.  Every other status reads as plain news, so the loud ones
 * keep meaning that something has to be done.
 *
 * @return array{subject: string, html: string, text: string, urgent: bool}
 */
function renderDisputeEmail(array $config, array $dispute, ?array $order, string $kind, DateTimeImmutable $now): array
{
    $tz     = new DateTimeZone($config['display_timezone']);
    $utc    = new DateTimeZone('UTC');
    $type   = (string) $dispute['type'];
    $status = (string) $dispute['status'];
    $urgent = $status === 'needs_response';

    $amount = number_format((float) $dispute['amount'], 2);
    $amount = $dispute['currency'] === 'USD' ? '$' . $amount : $amount . ' ' . $dispute['currency'];

    if ($order !== null) {
        $orderRow   = '#' . $order['order_number'];
        $orderLabel = 'order ' . $orderRow;
    } elseif ($dispute['shopify_order_id'] !== null) {
        $orderRow   = 'Shopify order ' . $dispute['shopify_order_id'] . ' (not in Cent Notes)';
        $orderLabel = 'Shopify order ' . $dispute['shopify_order_id'];
    } else {
        $orderRow   = 'None';
        $orderLabel = 'a charge with no order';
    }

    $deadline = '';
    $respondBy = '';
    if ($dispute['evidence_due_by'] !== null) {
        $due      = (new DateTimeImmutable($dispute['evidence_due_by'], $utc))->setTimezone($tz);
        $daysLeft = (int) $now->setTimezone($tz)->setTime(0, 0)->diff($due->setTime(0, 0))->format('%r%a');
        $deadline = match (true) {
            $daysLeft < 0   => 'OVERDUE',
            $daysLeft === 0 => 'DUE TODAY',
            $daysLeft === 1 => '1 day left',
            default         => $daysLeft . ' days left',
        };
        $respondBy = $due->format('D M j, g:i A');
    }

    $words = static fn (string $s): string => match ($s) {
        'needs_response'  => 'needs a response',
        'under_review'    => 'under review',
        'charge_refunded' => 'refunded',
        'won'             => 'WON',
        'lost'            => 'LOST',
        default           => str_replace('_', ' ', $s),
    };
    $statusWords = $words($status);

    if ($urgent) {
        $headline = strtoupper($type) . ' for ' . $amount . ' on ' . $orderLabel;
        $subject  = ($kind === 'reminder' ? 'ACTION REQUIRED (reminder): ' : 'ACTION REQUIRED: ') . $headline;
        if ($deadline !== '') {
            $subject .= ' - ' . $deadline . ', respond by ' . $due->format('D M j');
        }
    } else {
        $headline = ucfirst($type) . ' ' . $statusWords . ': ' . $amount . ' on ' . $orderLabel;
        $subject  = $headline;
    }

    $whatItIs = match (true) {
        in_array($status, DISPUTE_CLOSED_STATUSES, true) => '',
        $type === 'inquiry'    => "An inquiry is the customer's bank asking about the charge.  No money has been taken back yet.",
        $type === 'chargeback' => "A chargeback means the customer's bank has taken the money back while it decides.",
        default                => '',
    };
    $whatNext = match ($status) {
        'needs_response'  => 'Shopify is waiting for your evidence.  Respond in Shopify before the deadline, or the bank decides without your side of it.',
        'under_review'    => "Your evidence is with the customer's bank.  There is nothing to do until the bank decides.",
        'won'             => 'The bank decided in your favor.',
        'lost'            => 'The bank decided for the customer.',
        'accepted'        => "The dispute was accepted, so it is closed in the customer's favor.",
        'charge_refunded' => 'The charge was refunded, which closes the dispute.',
        default           => '',
    };

    $rows = ['Amount' => $amount, 'Order' => $orderRow];
    if ($order !== null && trim($order['customer_name'] . $order['customer_email']) !== '') {
        $rows['Customer'] = trim($order['customer_name'] . ' ' . $order['customer_email']);
    }
    if ($dispute['reason'] !== null) {
        $rows['Reason'] = DISPUTE_REASONS[$dispute['reason']] ?? $dispute['reason'];
    }
    $rows['Status'] = ucfirst($type) . ', ' . $statusWords;
    if ($kind === 'changed' && $dispute['alerted_state'] !== null) {
        [$oldType, $oldStatus] = explode(':', $dispute['alerted_state'], 2);
        $rows['Was'] = ucfirst($oldType) . ', ' . $words($oldStatus);
    }
    if ($dispute['initiated_at'] !== null) {
        $rows['Opened'] = (new DateTimeImmutable($dispute['initiated_at'], $utc))->setTimezone($tz)->format('D M j, g:i A');
    }
    if ($urgent && $respondBy !== '') {
        $rows['Respond by'] = $respondBy . ' (' . $deadline . ')';
    }

    $links = [];
    if ($dispute['shopify_order_id'] !== null) {
        $links['Open the order in Shopify'] = sprintf(
            'https://%s/admin/orders/%s',
            $config['shopify_shop_domain'],
            rawurlencode((string) $dispute['shopify_order_id'])
        );
    }
    if ($order !== null && $config['app_base_url'] !== '') {
        $links['Open the order in Cent Notes'] = $config['app_base_url'] . '/order.php?id=' . $order['id'];
    }

    // ── Plain text ──
    $text = ($urgent ? "ACTION REQUIRED\n" : '') . $headline . "\n";
    if ($urgent && $respondBy !== '') {
        $text .= 'Respond by ' . $respondBy . ' (' . $deadline . ")\n";
    }
    $text .= "\n" . trim($whatItIs . "\n" . $whatNext) . "\n\n";
    foreach ($rows as $label => $value) {
        $text .= $label . ': ' . $value . "\n";
    }
    foreach ($links as $label => $url) {
        $text .= "\n" . $label . ":\n" . $url . "\n";
    }

    // ── HTML ──
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $bannerColor = match (true) {
        $urgent           => '#b91c1c',
        $status === 'won' => '#166534',
        default           => '#1a1a2e',
    };
    $banner = $urgent
        ? '<div style="font-size:15px; font-weight:700; letter-spacing:.12em;">ACTION REQUIRED</div>'
          . '<div style="font-size:30px; font-weight:800; line-height:1.2; margin-top:6px;">' . $e(strtoupper($type) . ' - ' . $amount) . '</div>'
          . ($respondBy !== ''
              ? '<div style="font-size:18px; font-weight:700; margin-top:10px;">Respond by ' . $e($respondBy) . ' - ' . $e($deadline) . '</div>'
              : '')
        : '<div style="font-size:22px; font-weight:700; line-height:1.3;">' . $e($headline) . '</div>';

    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:8px 16px 8px 0; color:#666; vertical-align:top; white-space:nowrap;">' . $e($label) . '</td>'
            . '<td style="padding:8px 0; font-weight:600;">' . $e($value) . '</td>'
            . '</tr>';
    }
    $linksHtml = '';
    foreach ($links as $label => $url) {
        $linksHtml .= '<a href="' . $e($url) . '" style="display:inline-block; margin:0 10px 10px 0; padding:11px 20px;'
            . ' background:' . $bannerColor . '; color:#fff; text-decoration:none; border-radius:6px; font-weight:700;">'
            . $e($label) . '</a>';
    }
    $explainHtml = '';
    foreach ([$whatItIs, $whatNext] as $sentence) {
        if ($sentence !== '') {
            $explainHtml .= '<p style="margin:0 0 10px;">' . $e($sentence) . '</p>';
        }
    }

    $html = <<<HTML
<!doctype html>
<html><body style="margin:0; padding:0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color:#1a1a2e; line-height:1.5;">
<div style="background:{$bannerColor}; color:#fff; padding:26px 24px; text-align:center;">{$banner}</div>
<div style="padding:22px 24px; max-width:640px; margin:0 auto;">
{$explainHtml}
<table style="border-collapse:collapse; margin:14px 0 20px; font-size:15px;">{$rowsHtml}</table>
<div>{$linksHtml}</div>
<p style="margin-top:18px; font-size:12px; color:#888;">Sent by Cent Notes from what Shopify reports about this dispute.</p>
</div>
</body></html>
HTML;

    return ['subject' => $subject, 'html' => $html, 'text' => $text, 'urgent' => $urgent];
}
