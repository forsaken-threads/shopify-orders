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

// ── Fetch orders with total item quantity and raw_data ─────────────────────────

$stmt = $db->prepare(<<<'SQL'
    SELECT o.id, o.shopify_order_id, o.order_number, o.customer_name, o.customer_email,
           o.total_price, o.currency, o.status, o.shopify_created_at, o.received_at,
           o.raw_data,
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

// ── Fetch line items for all orders on this page ──────────────────────────────

$lineItemsByOrder = [];
if (!empty($orders)) {
    $orderIds    = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $liStmt = $db->prepare(
        "SELECT * FROM order_line_items WHERE order_id IN ({$placeholders}) ORDER BY order_id, id ASC"
    );
    $liStmt->execute($orderIds);
    foreach ($liStmt->fetchAll() as $li) {
        $lineItemsByOrder[(int) $li['order_id']][] = $li;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

$detroitTz = new DateTimeZone('America/Detroit');

function fmt(string $date): string
{
    global $detroitTz;
    try {
        return (new DateTimeImmutable($date))->setTimezone($detroitTz)->format('d M Y, H:i');
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

// Number of visible columns (used for accordion colspan).
// Base: expand, order, customer, total, items, status, order-date, download = 8
// +1 if pending (archive button)
$colCount = $filterStatus === 'pending' ? 9 : 8;

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
                    <th class="col-expand"></th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th class="hide-mobile">Total</th>
                    <th class="hide-mobile">Items</th>
                    <th class="hide-mobile">Status</th>
                    <th>Order Date</th>
                    <th></th>
                    <?php if ($filterStatus === 'pending'): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order):
                $oid      = (int) $order['id'];
                $lineItems = $lineItemsByOrder[$oid] ?? [];
            ?>
                <tr class="order-row" data-order-id="<?= $oid ?>">
                    <td class="col-expand">
                        <button class="btn-expand"
                                aria-expanded="false"
                                aria-controls="detail-<?= $oid ?>"
                                title="Show order details">+</button>
                    </td>
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
                    <td>
                        <a class="btn-download"
                           href="download.php?id=<?= $oid ?>"
                           title="Download CSV for order <?= h($order['order_number']) ?>">
                            ↓ CSV
                        </a>
                    </td>
                    <?php if ($filterStatus === 'pending'): ?>
                    <td>
                        <button class="btn-archive"
                                data-id="<?= $oid ?>"
                                title="Archive order <?= h($order['order_number']) ?>">
                            Archive
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <tr class="order-detail-row" id="detail-<?= $oid ?>" hidden>
                    <td colspan="<?= $colCount ?>">
                        <div class="order-detail">

                            <div class="order-detail-meta">
                                <dl class="order-meta-list">
                                    <div>
                                        <dt>Shopify Order ID</dt>
                                        <dd><?= h($order['shopify_order_id']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Order Number</dt>
                                        <dd><?= h($order['order_number']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Customer</dt>
                                        <dd><?= h($order['customer_name']) ?>
                                            <?php if ($order['customer_email']): ?>
                                                &lt;<?= h($order['customer_email']) ?>&gt;
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Status</dt>
                                        <dd><?= statusBadge($order['status']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Total</dt>
                                        <dd><?= h($order['currency']) ?> <?= h(number_format((float) $order['total_price'], 2)) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Order Date</dt>
                                        <dd><?= h(fmt($order['shopify_created_at'])) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Received</dt>
                                        <dd><?= h(fmt($order['received_at'])) ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <?php if (!empty($lineItems)): ?>
                            <div class="order-detail-items">
                                <h4>Line Items</h4>
                                <table class="line-items-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Variant</th>
                                            <th>ML</th>
                                            <th>SKU</th>
                                            <th>Vendor</th>
                                            <th>Brand</th>
                                            <th>Qty</th>
                                            <th>Unit Price</th>
                                            <th>Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($lineItems as $li):
                                        $unitPrice = (float) $li['price'];
                                        $qty       = (int)   $li['quantity'];
                                    ?>
                                        <tr>
                                            <td><?= h($li['title']) ?></td>
                                            <td><?= h($li['variant_title'] ?? '') ?></td>
                                            <td><?= $li['variant_ml'] !== null ? h((string) $li['variant_ml']) : '' ?></td>
                                            <td><?= h($li['sku'] ?? '') ?></td>
                                            <td><?= h($li['vendor'] ?? '') ?></td>
                                            <td><?= h($li['custom_brand'] ?? '') ?></td>
                                            <td><?= $qty ?></td>
                                            <td><?= h(number_format($unitPrice, 2)) ?></td>
                                            <td><?= h(number_format($unitPrice * $qty, 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <div class="order-detail-actions">
                                <button class="btn-raw-data"
                                        data-order-id="<?= $oid ?>"
                                        title="View raw Shopify order JSON">
                                    { } View Raw Data
                                </button>
                            </div>

                        </div>
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

<!-- ── Raw Data Modal ─────────────────────────────────────────────────────── -->
<div id="raw-data-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-label="Raw order data">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">Raw Order Data</span>
            <button id="modal-close" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <pre id="modal-json" class="modal-json"></pre>
    </div>
</div>

<!-- Embed raw_data keyed by order ID so JS can look it up without extra requests -->
<script id="order-raw-data" type="application/json">
<?= json_encode(
    array_combine(
        array_map(fn($o) => (string) $o['id'], $orders),
        array_map(fn($o) => json_decode($o['raw_data'] ?? 'null'), $orders)
    ),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>
</script>

<style>
/* ── Expand button ──────────────────────────────────────────────────────────── */
.col-expand { width: 2rem; padding-right: 0; }

.btn-expand {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 4px;
    background: var(--bg-card, #fff);
    color: var(--text, #374151);
    font-size: 1rem;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    padding: 0;
}

.btn-expand:hover { background: var(--accent, #4f46e5); color: #fff; border-color: var(--accent, #4f46e5); }
.btn-expand[aria-expanded="true"] { background: var(--accent, #4f46e5); color: #fff; border-color: var(--accent, #4f46e5); }

/* ── Detail row ─────────────────────────────────────────────────────────────── */
.order-detail-row td {
    padding: 0;
    border-top: none;
}

.order-detail {
    padding: 1rem 1.25rem 1.25rem;
    background: var(--bg-subtle, #f9fafb);
    border-top: 1px solid var(--border, #e5e7eb);
}

/* ── Meta list (definition list grid) ──────────────────────────────────────── */
.order-meta-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 2rem;
    margin: 0 0 1rem;
    padding: 0;
}

.order-meta-list > div {
    display: flex;
    flex-direction: column;
    min-width: 10rem;
}

.order-meta-list dt {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted, #6b7280);
    margin: 0 0 0.15rem;
}

.order-meta-list dd {
    margin: 0;
    font-size: 0.875rem;
    color: var(--text, #111827);
}

/* ── Line items sub-table ───────────────────────────────────────────────────── */
.order-detail-items h4 {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted, #6b7280);
    margin: 0 0 0.5rem;
}

.line-items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.line-items-table th {
    text-align: left;
    padding: 0.3rem 0.5rem;
    border-bottom: 2px solid var(--border, #e5e7eb);
    font-weight: 600;
    color: var(--text-muted, #6b7280);
    white-space: nowrap;
}

.line-items-table td {
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid var(--border, #f3f4f6);
    color: var(--text, #374151);
    vertical-align: top;
}

.line-items-table tbody tr:last-child td { border-bottom: none; }

/* ── Detail actions ─────────────────────────────────────────────────────────── */
.order-detail-actions {
    margin-top: 0.875rem;
}

.btn-raw-data {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 5px;
    background: var(--bg-card, #fff);
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--text-muted, #6b7280);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.btn-raw-data:hover {
    background: var(--text, #111827);
    color: #fff;
    border-color: var(--text, #111827);
}

/* ── Modal ──────────────────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.55);
    padding: 1rem;
}

.modal-overlay[hidden] { display: none; }

.modal-box {
    background: var(--bg-card, #fff);
    border-radius: 8px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    width: min(860px, 100%);
    max-height: 85vh;
    overflow: hidden;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--border, #e5e7eb);
    flex-shrink: 0;
}

.modal-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text, #111827);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: var(--text-muted, #6b7280);
    cursor: pointer;
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
}

.modal-close:hover { background: var(--bg-subtle, #f3f4f6); color: var(--text, #111827); }

.modal-json {
    overflow: auto;
    padding: 1rem 1.25rem;
    margin: 0;
    font-size: 0.78rem;
    line-height: 1.5;
    color: var(--text, #111827);
    background: var(--bg-subtle, #f9fafb);
    white-space: pre;
    tab-size: 2;
    flex: 1;
}
</style>

<script>
(function () {
    'use strict';

    // ── Raw data map (order id → parsed JSON) ──────────────────────────────────
    var rawDataMap = {};
    try {
        rawDataMap = JSON.parse(document.getElementById('order-raw-data').textContent);
    } catch (e) {}

    // ── Accordion expand/collapse ──────────────────────────────────────────────
    document.querySelectorAll('.btn-expand').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            var detailId = btn.getAttribute('aria-controls');
            var detailRow = document.getElementById(detailId);
            if (!detailRow) return;

            if (expanded) {
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = '+';
                detailRow.hidden = true;
            } else {
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = '−';
                detailRow.hidden = false;
            }
        });
    });

    // ── Raw data modal ─────────────────────────────────────────────────────────
    var modal   = document.getElementById('raw-data-modal');
    var jsonPre = document.getElementById('modal-json');
    var closeBtn = document.getElementById('modal-close');

    function openModal(orderId) {
        var data = rawDataMap[String(orderId)];
        jsonPre.textContent = data !== undefined
            ? JSON.stringify(data, null, 2)
            : '(no data)';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.btn-raw-data').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.dataset.orderId);
        });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    // ── Archive ────────────────────────────────────────────────────────────────
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
}());
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
