<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$db = getDb($config);

// Fetch the 10 oldest pending orders so the longest-waiting are always visible.
$stmt = $db->query(<<<'SQL'
    SELECT id, order_number, customer_name, customer_email,
           total_price, currency, shopify_created_at, received_at
    FROM   orders
    WHERE  status = 'pending'
    ORDER  BY shopify_created_at ASC
    LIMIT  10
SQL);

$orders = $stmt->fetchAll();

// Counts for the header badge.
$totalPending = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('d M Y, H:i');
    } catch (Exception) {
        return $date;
    }
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Orders</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
            padding: 2rem;
        }

        .page-header {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        h1 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .subtitle {
            font-size: .875rem;
            color: #666;
        }

        .badge-count {
            background: #e53e3e;
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            padding: .2em .55em;
            border-radius: 99px;
            vertical-align: middle;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #1a1a2e;
            color: #fff;
        }

        th {
            padding: .75rem 1rem;
            text-align: left;
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
        }

        td {
            padding: .8rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            font-size: .88rem;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }

        tbody tr:hover td { background: #fafafa; }

        .order-num {
            font-weight: 700;
            font-size: .95rem;
        }

        .customer-email {
            font-size: .78rem;
            color: #888;
            margin-top: .18rem;
        }

        .price {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-block;
            padding: .25em .7em;
            border-radius: 5px;
            font-size: .75rem;
            font-weight: 600;
            background: #fff8e1;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .btn-download {
            display: inline-block;
            padding: .4rem 1rem;
            background: #1a1a2e;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 500;
            white-space: nowrap;
            transition: background .15s;
        }

        .btn-download:hover { background: #2d2d5e; }

        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            color: #aaa;
        }

        .empty-state p { margin-top: .5rem; font-size: .875rem; }

        .footer-note {
            margin-top: 1rem;
            font-size: .78rem;
            color: #999;
            text-align: right;
        }

        @media (max-width: 700px) {
            body { padding: 1rem; }
            .hide-mobile { display: none; }
        }
    </style>
</head>
<body>

<div class="page-header">
    <h1>Pending Orders
        <?php if ($totalPending > 0): ?>
            <span class="badge-count"><?= $totalPending ?></span>
        <?php endif; ?>
    </h1>
    <span class="subtitle">Showing 10 oldest</span>
</div>

<div class="card">
    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <strong>All clear!</strong>
            <p>There are no pending orders right now.</p>
        </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th class="hide-mobile">Total</th>
                <th class="hide-mobile">Status</th>
                <th>Order Date</th>
                <th class="hide-mobile">Received</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><span class="order-num"><?= h($order['order_number']) ?></span></td>
                <td>
                    <?= h($order['customer_name']) ?>
                    <div class="customer-email"><?= h($order['customer_email']) ?></div>
                </td>
                <td class="price hide-mobile">
                    <?= h($order['currency']) ?> <?= h(number_format((float) $order['total_price'], 2)) ?>
                </td>
                <td class="hide-mobile"><span class="status-badge">Pending</span></td>
                <td><?= h(fmt($order['shopify_created_at'])) ?></td>
                <td class="hide-mobile"><?= h(fmt($order['received_at'])) ?></td>
                <td>
                    <a class="btn-download"
                       href="download.php?id=<?= (int) $order['id'] ?>"
                       title="Download CSV for order <?= h($order['order_number']) ?>">
                        ↓ CSV
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($totalPending > 10): ?>
<p class="footer-note">
    <?= $totalPending - 10 ?> more pending order<?= ($totalPending - 10) !== 1 ? 's' : '' ?> not shown.
</p>
<?php endif; ?>

</body>
</html>
