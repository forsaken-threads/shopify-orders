<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

// ── AJAX: Archive action ───────────────────────────────────────────────────────
// POST ?action=archive  body: id=<int>
// Transitions a pending order to archived status.
// Returns JSON {ok:true} or {ok:false,error:"..."}.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'archive') {
    header('Content-Type: application/json');

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
        exit;
    }

    $db = getDb($config);
    $stmt = $db->prepare(
        "UPDATE orders SET status = 'archived' WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$id]);

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

$db = getDb($config);

// ── Status filter ─────────────────────────────────────────────────────────────

$validStatuses = ['pending', 'printed', 'fulfilled', 'archived'];
$filterStatus  = $_GET['status'] ?? 'pending';
if (!in_array($filterStatus, $validStatuses, strict: true)) {
    $filterStatus = 'pending';
}

// Count per-status for the filter tab badges.
$statusCounts = array_fill_keys($validStatuses, 0);
$countsStmt   = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM orders
     WHERE status IN ('pending','printed','fulfilled','archived')
     GROUP BY status"
);
foreach ($countsStmt->fetchAll() as $row) {
    if (array_key_exists($row['status'], $statusCounts)) {
        $statusCounts[$row['status']] = (int) $row['cnt'];
    }
}

// ── Pagination ────────────────────────────────────────────────────────────────

$perPage     = 25;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = ?");
$countStmt->execute([$filterStatus]);
$totalCount = (int) $countStmt->fetchColumn();

$totalPages  = max(1, (int) ceil($totalCount / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

// ── Fetch orders with total item quantity ─────────────────────────────────────

$stmt = $db->prepare(<<<'SQL'
    SELECT o.id, o.order_number, o.customer_name, o.customer_email,
           o.total_price, o.currency, o.status, o.shopify_created_at, o.received_at,
           COALESCE(
               (SELECT SUM(li.quantity) FROM order_line_items li WHERE li.order_id = o.id),
               0
           ) AS total_quantity
    FROM   orders o
    WHERE  o.status = :status
    ORDER  BY o.shopify_created_at ASC
    LIMIT  :limit OFFSET :offset
SQL);
$stmt->execute([':status' => $filterStatus, ':limit' => $perPage, ':offset' => $offset]);
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

function pageUrl(int $page, string $status): string
{
    return '?status=' . urlencode($status) . '&page=' . $page;
}

function statusBadge(string $status): string
{
    $class = match ($status) {
        'pending'   => 'status-pending',
        'printed'   => 'status-printed',
        'fulfilled' => 'status-fulfilled',
        'archived'  => 'status-archived',
        default     => 'status-pending',
    };
    return '<span class="status-badge ' . $class . '">' . ucfirst(htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</span>';
}

$pageTitle  = 'Orders - Utility App';
$activePage = 'orders';
require __DIR__ . '/partials/header.php';
?>

<div class="main">

    <div class="page-header">
        <h1>Orders</h1>
        <?php if ($totalCount > 0): ?>
            <span class="subtitle">Page <?= $currentPage ?> of <?= $totalPages ?></span>
        <?php endif; ?>
    </div>

    <!-- Status filter tabs -->
    <div class="filter-bar">
        <?php foreach ($validStatuses as $s): ?>
            <a href="?status=<?= urlencode($s) ?>"
               class="filter-link<?= $filterStatus === $s ? ' active' : '' ?>">
                <?= ucfirst($s) ?>
                <span class="filter-count"><?= $statusCounts[$s] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <strong>No <?= h($filterStatus) ?> orders.</strong>
                <p>There are no orders with this status right now.</p>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th class="hide-mobile">Total</th>
                    <th class="hide-mobile">Items</th>
                    <th class="hide-mobile">Status</th>
                    <th>Order Date</th>
                    <th class="hide-mobile">Received</th>
                    <th></th>
                    <?php if ($filterStatus === 'pending'): ?><th></th><?php endif; ?>
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
                    <td class="qty hide-mobile"><?= (int) $order['total_quantity'] ?></td>
                    <td class="hide-mobile"><?= statusBadge($order['status']) ?></td>
                    <td><?= h(fmt($order['shopify_created_at'])) ?></td>
                    <td class="hide-mobile"><?= h(fmt($order['received_at'])) ?></td>
                    <td>
                        <a class="btn-download"
                           href="download.php?id=<?= (int) $order['id'] ?>"
                           title="Download CSV for order <?= h($order['order_number']) ?>">
                            ↓ CSV
                        </a>
                    </td>
                    <?php if ($filterStatus === 'pending'): ?>
                    <td>
                        <button class="btn-archive"
                                data-id="<?= (int) $order['id'] ?>"
                                title="Archive order <?= h($order['order_number']) ?>">
                            Archive
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                <?php
                $firstItem = $offset + 1;
                $lastItem  = min($offset + $perPage, $totalCount);
                echo "Showing {$firstItem}–{$lastItem} of {$totalCount} " . h($filterStatus) . " orders";
                ?>
            </div>
            <div class="pagination-controls">
                <a class="page-link<?= $currentPage <= 1 ? ' disabled' : '' ?>"
                   href="<?= pageUrl($currentPage - 1, $filterStatus) ?>">&#8592; Prev</a>

                <?php
                $window = [];
                for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++) {
                    $window[] = $p;
                }

                $showFirst = !in_array(1, $window, true);
                $showLast  = !in_array($totalPages, $window, true);

                if ($showFirst) {
                    echo '<a class="page-link" href="' . pageUrl(1, $filterStatus) . '">1</a>';
                    if (!in_array(2, $window, true)) {
                        echo '<span class="page-ellipsis">&hellip;</span>';
                    }
                }

                foreach ($window as $p) {
                    $active = $p === $currentPage ? ' active' : '';
                    echo '<a class="page-link' . $active . '" href="' . pageUrl($p, $filterStatus) . '">' . $p . '</a>';
                }

                if ($showLast) {
                    if (!in_array($totalPages - 1, $window, true)) {
                        echo '<span class="page-ellipsis">&hellip;</span>';
                    }
                    echo '<a class="page-link" href="' . pageUrl($totalPages, $filterStatus) . '">' . $totalPages . '</a>';
                }
                ?>

                <a class="page-link<?= $currentPage >= $totalPages ? ' disabled' : '' ?>"
                   href="<?= pageUrl($currentPage + 1, $filterStatus) ?>">Next &#8594;</a>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-archive').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.dataset.id;
            var row = btn.closest('tr');

            btn.disabled = true;
            btn.textContent = 'Archiving…';

            var body = new URLSearchParams();
            body.append('id', id);

            fetch('orders.php?action=archive', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    row.classList.add('archived-row');
                    btn.textContent = 'Archived';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Archive';
                    alert('Could not archive order: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Archive';
                alert('Network error — please try again.');
            });
        });
    });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
