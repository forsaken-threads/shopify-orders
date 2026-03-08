<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$db = getDb($config);

// Pagination parameters.
$perPage     = 25;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// Total pending count for badge and pagination.
$totalPending = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$totalPages   = max(1, (int) ceil($totalPending / $perPage));
$currentPage  = min($currentPage, $totalPages);
$offset       = ($currentPage - 1) * $perPage;

// Fetch the current page of pending orders (oldest first).
$stmt = $db->prepare(<<<'SQL'
    SELECT id, order_number, customer_name, customer_email,
           total_price, currency, shopify_created_at, received_at
    FROM   orders
    WHERE  status = 'pending'
    ORDER  BY shopify_created_at ASC
    LIMIT  :limit OFFSET :offset
SQL);
$stmt->execute([':limit' => $perPage, ':offset' => $offset]);
$orders = $stmt->fetchAll();

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

function pageUrl(int $page): string
{
    return '?page=' . $page;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Orders - Utility App</title>
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

        /* ── Navbar ── */
        .navbar {
            background: #1a1a2e;
            padding: .875rem 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .navbar-brand {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            letter-spacing: .03em;
        }

        /* ── Main ── */
        .main {
            flex: 1;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
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

        /* ── Card / Table ── */
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

        /* ── Pagination ── */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-top: 1px solid #f0f0f0;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .pagination-info {
            font-size: .82rem;
            color: #666;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: .35rem;
        }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 .6rem;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 500;
            text-decoration: none;
            color: #1a1a2e;
            border: 1px solid #e2e8f0;
            transition: background .15s, border-color .15s;
            white-space: nowrap;
        }

        .page-link:hover { background: #f0f0f5; border-color: #c8d0e0; }
        .page-link.active { background: #1a1a2e; color: #fff; border-color: #1a1a2e; cursor: default; }
        .page-link.disabled { opacity: .38; pointer-events: none; }

        .page-ellipsis {
            font-size: .82rem;
            color: #999;
            padding: 0 .25rem;
        }

        @media (max-width: 700px) {
            .main { padding: 1rem; }
            .navbar { padding: .75rem 1rem; }
            .hide-mobile { display: none; }
            .pagination { justify-content: center; }
            .pagination-info { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a class="navbar-brand" href="index.php">Utility App</a>
</nav>

<div class="main">

    <div class="page-header">
        <h1>Pending Orders
            <?php if ($totalPending > 0): ?>
                <span class="badge-count"><?= $totalPending ?></span>
            <?php endif; ?>
        </h1>
        <?php if ($totalPending > 0): ?>
            <span class="subtitle">Page <?= $currentPage ?> of <?= $totalPages ?></span>
        <?php endif; ?>
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

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                <?php
                $firstItem = $offset + 1;
                $lastItem  = min($offset + $perPage, $totalPending);
                echo "Showing {$firstItem}–{$lastItem} of {$totalPending} pending orders";
                ?>
            </div>
            <div class="pagination-controls">
                <!-- Previous -->
                <a class="page-link<?= $currentPage <= 1 ? ' disabled' : '' ?>"
                   href="<?= pageUrl($currentPage - 1) ?>">&#8592; Prev</a>

                <?php
                // Build the page number window: always show first, last, and up to 3 around current.
                $window = [];
                for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++) {
                    $window[] = $p;
                }

                $showFirst = !in_array(1, $window, true);
                $showLast  = !in_array($totalPages, $window, true);

                if ($showFirst) {
                    echo '<a class="page-link" href="' . pageUrl(1) . '">1</a>';
                    if (!in_array(2, $window, true)) {
                        echo '<span class="page-ellipsis">&hellip;</span>';
                    }
                }

                foreach ($window as $p) {
                    $active = $p === $currentPage ? ' active' : '';
                    echo '<a class="page-link' . $active . '" href="' . pageUrl($p) . '">' . $p . '</a>';
                }

                if ($showLast) {
                    if (!in_array($totalPages - 1, $window, true)) {
                        echo '<span class="page-ellipsis">&hellip;</span>';
                    }
                    echo '<a class="page-link" href="' . pageUrl($totalPages) . '">' . $totalPages . '</a>';
                }
                ?>

                <!-- Next -->
                <a class="page-link<?= $currentPage >= $totalPages ? ' disabled' : '' ?>"
                   href="<?= pageUrl($currentPage + 1) ?>">Next &#8594;</a>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div>

</body>
</html>
