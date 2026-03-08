<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
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

// ── Fetch orders (no raw_data or line items — loaded async on expand) ──────────

$stmt = $db->prepare(<<<'SQL'
    SELECT o.id, o.shopify_order_id, o.order_number, o.customer_name, o.customer_email,
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
require __DIR__ . '/../app/partials/header.php';
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
                $oid = (int) $order['id'];
            ?>
                <tr class="order-row" data-order-id="<?= $oid ?>">
                    <td class="col-expand">
                        <button class="btn-expand"
                                aria-expanded="false"
                                aria-controls="detail-<?= $oid ?>"
                                data-order-id="<?= $oid ?>"
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
                    <td><?= h((function($d) {
                        try {
                            return (new DateTimeImmutable($d))
                                ->setTimezone(new DateTimeZone('America/Detroit'))
                                ->format('d M Y, H:i');
                        } catch (Exception) { return $d; }
                    })($order['shopify_created_at'])) ?></td>
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
                <!-- Detail row — content loaded asynchronously on first expand -->
                <tr class="order-detail-row" id="detail-<?= $oid ?>" hidden>
                    <td colspan="<?= $colCount ?>">
                        <div class="order-detail" id="detail-content-<?= $oid ?>">
                            <div class="detail-loading">
                                <div class="detail-spinner"></div>
                                Loading order details…
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
        <div id="modal-loading" class="modal-fetch-loading" hidden>
            <div class="detail-spinner"></div>
            Fetching raw data…
        </div>
        <pre id="modal-json" class="modal-json"></pre>
    </div>
</div>

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

/* ── Detail loading state ───────────────────────────────────────────────────── */
.detail-loading {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: 1rem 0;
    font-size: .875rem;
    color: #888;
}

.detail-spinner {
    width: 1rem;
    height: 1rem;
    border: 2px solid #e2e8f0;
    border-top-color: #1a1a2e;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    flex-shrink: 0;
}

@keyframes spin { to { transform: rotate(360deg); } }

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

.modal-fetch-loading {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: 1.5rem 1.25rem;
    font-size: .875rem;
    color: #888;
}

.modal-fetch-loading[hidden] { display: none; }

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

    // ── Date formatter (Detroit/Eastern time) ──────────────────────────────────
    var dtFmt = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Detroit',
        day:   '2-digit',
        month: 'short',
        year:  'numeric',
        hour:  '2-digit',
        minute:'2-digit',
        hour12: false,
    });

    function fmtDate(dateStr) {
        if (!dateStr) return '';
        try {
            var parts = {};
            dtFmt.formatToParts(new Date(dateStr)).forEach(function (p) { parts[p.type] = p.value; });
            return parts.day + ' ' + parts.month + ' ' + parts.year + ', ' + parts.hour + ':' + parts.minute;
        } catch (e) {
            return dateStr;
        }
    }

    // ── HTML escaping ──────────────────────────────────────────────────────────
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Status badge ───────────────────────────────────────────────────────────
    function statusBadge(status) {
        var cls = {
            pending:   'status-pending',
            printed:   'status-printed',
            fulfilled: 'status-fulfilled',
            archived:  'status-archived',
        }[status] || 'status-pending';
        var label = status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="status-badge ' + cls + '">' + esc(label) + '</span>';
    }

    // ── Render accordion detail HTML from API response ─────────────────────────
    function renderDetail(data) {
        var o  = data.order;
        var li = data.line_items;

        var customer = esc(o.customer_name);
        if (o.customer_email) {
            customer += ' &lt;' + esc(o.customer_email) + '&gt;';
        }

        var meta =
            '<div class="order-detail-meta">' +
            '<dl class="order-meta-list">' +
            '<div><dt>Shopify Order ID</dt><dd>' + esc(o.shopify_order_id) + '</dd></div>' +
            '<div><dt>Order Number</dt><dd>' + esc(o.order_number) + '</dd></div>' +
            '<div><dt>Customer</dt><dd>' + customer + '</dd></div>' +
            '<div><dt>Status</dt><dd>' + statusBadge(o.status) + '</dd></div>' +
            '<div><dt>Total</dt><dd>' + esc(o.currency) + ' ' + Number(o.total_price).toFixed(2) + '</dd></div>' +
            '<div><dt>Order Date</dt><dd>' + esc(fmtDate(o.shopify_created_at)) + '</dd></div>' +
            '<div><dt>Received</dt><dd>' + esc(fmtDate(o.received_at)) + '</dd></div>' +
            '</dl>' +
            '</div>';

        var items = '';
        if (li && li.length > 0) {
            var rows = li.map(function (item) {
                var unitPrice = Number(item.price);
                var qty       = Number(item.quantity);
                return '<tr>' +
                    '<td>' + esc(item.title) + '</td>' +
                    '<td>' + esc(item.variant_title) + '</td>' +
                    '<td>' + (item.variant_ml != null ? esc(String(item.variant_ml)) : '') + '</td>' +
                    '<td>' + esc(item.sku) + '</td>' +
                    '<td>' + esc(item.vendor) + '</td>' +
                    '<td>' + esc(item.custom_brand) + '</td>' +
                    '<td>' + qty + '</td>' +
                    '<td>' + unitPrice.toFixed(2) + '</td>' +
                    '<td>' + (unitPrice * qty).toFixed(2) + '</td>' +
                    '</tr>';
            }).join('');

            items =
                '<div class="order-detail-items">' +
                '<h4>Line Items</h4>' +
                '<table class="line-items-table"><thead><tr>' +
                '<th>Product</th><th>Variant</th><th>ML</th><th>SKU</th>' +
                '<th>Vendor</th><th>Brand</th><th>Qty</th><th>Unit Price</th><th>Line Total</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>' +
                '</div>';
        }

        var actions =
            '<div class="order-detail-actions">' +
            '<button class="btn-raw-data" data-order-id="' + esc(String(o.id)) + '" title="View raw Shopify order JSON">' +
            '{ } View Raw Data' +
            '</button>' +
            '</div>';

        return meta + items + actions;
    }

    // ── Detail data cache (orderId → fetched API response) ────────────────────
    var detailCache = {};

    // ── Accordion expand/collapse ──────────────────────────────────────────────
    document.querySelectorAll('.btn-expand').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var expanded  = btn.getAttribute('aria-expanded') === 'true';
            var detailId  = btn.getAttribute('aria-controls');
            var detailRow = document.getElementById(detailId);
            var orderId   = btn.dataset.orderId;
            if (!detailRow) return;

            if (expanded) {
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = '+';
                detailRow.hidden = true;
                return;
            }

            // Open
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = '−';
            detailRow.hidden = false;

            // If already fetched, nothing more to do.
            if (detailCache[orderId]) return;

            // Fetch order detail from API.
            var contentEl = document.getElementById('detail-content-' + orderId);

            fetch('api/order-detail.php?id=' + encodeURIComponent(orderId))
                .then(function (res) {
                    if (!res.ok) return res.json().then(function (d) { throw new Error(d.error || 'Server error'); });
                    return res.json();
                })
                .then(function (data) {
                    detailCache[orderId] = data;
                    contentEl.innerHTML = renderDetail(data);
                    // Wire up the raw-data button that was just rendered.
                    var rawBtn = contentEl.querySelector('.btn-raw-data');
                    if (rawBtn) rawBtn.addEventListener('click', handleRawDataClick);
                })
                .catch(function (err) {
                    contentEl.innerHTML =
                        '<div style="color:#b91c1c;padding:.5rem 0;font-size:.85rem;">' +
                        'Failed to load order details: ' + esc(err.message) + '</div>';
                });
        });
    });

    // ── Raw data modal ─────────────────────────────────────────────────────────
    var modal      = document.getElementById('raw-data-modal');
    var jsonPre    = document.getElementById('modal-json');
    var modalLoad  = document.getElementById('modal-loading');
    var closeBtn   = document.getElementById('modal-close');

    function openModal(orderId) {
        jsonPre.textContent = '';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        closeBtn.focus();

        if (detailCache[orderId]) {
            // Already fetched via accordion — use cached raw_data.
            modalLoad.hidden = true;
            jsonPre.textContent = JSON.stringify(detailCache[orderId].order.raw_data, null, 2);
            return;
        }

        // Not yet fetched — load on demand.
        modalLoad.hidden = false;
        fetch('api/order-detail.php?id=' + encodeURIComponent(orderId))
            .then(function (res) {
                if (!res.ok) return res.json().then(function (d) { throw new Error(d.error || 'Server error'); });
                return res.json();
            })
            .then(function (data) {
                detailCache[orderId] = data;
                modalLoad.hidden = true;
                jsonPre.textContent = JSON.stringify(data.order.raw_data, null, 2);
            })
            .catch(function (err) {
                modalLoad.hidden = true;
                jsonPre.textContent = 'Error loading data: ' + err.message;
            });
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    function handleRawDataClick(e) {
        openModal(e.currentTarget.dataset.orderId);
    }

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

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
