<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

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
// Base: expand, order, customer, total, items, status, order-date = 7
// +2 if pending (print + printed/archive group), +1 if archived (unarchive), +1 if printed (pending btn)
$colCount = $filterStatus === 'pending' ? 9 : ($filterStatus === 'archived' || $filterStatus === 'printed' ? 8 : 7);

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
        <div class="table-scroll" id="table-scroll">
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
                    <?php if ($filterStatus === 'pending'): ?><th></th><th></th><?php endif; ?>
                    <?php if ($filterStatus === 'printed'): ?><th></th><?php endif; ?>
                    <?php if ($filterStatus === 'archived'): ?><th></th><?php endif; ?>
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
                    <td><?= h((function($d) use ($config) {
                        try {
                            return (new DateTimeImmutable($d))
                                ->setTimezone(new DateTimeZone($config['display_timezone']))
                                ->format('d M Y, H:i');
                        } catch (Exception) { return $d; }
                    })($order['shopify_created_at'])) ?></td>
                    <?php if ($filterStatus === 'pending'): ?>
                    <td>
                        <button class="btn-print"
                                data-id="<?= $oid ?>"
                                data-order-number="<?= h($order['order_number']) ?>"
                                title="Print labels for order <?= h($order['order_number']) ?>">
                            Print
                        </button>
                    </td>
                    <td>
                        <div class="btn-group">
                            <button class="btn-set-printed"
                                    data-id="<?= $oid ?>"
                                    title="Mark order <?= h($order['order_number']) ?> as printed">
                                Printed
                            </button>
                            <button class="btn-group-toggle"
                                    data-id="<?= $oid ?>"
                                    aria-expanded="false"
                                    aria-label="More actions"
                                    title="More actions">&#9662;</button>
                            <div class="btn-group-menu" hidden>
                                <button class="btn-group-item btn-archive"
                                        data-id="<?= $oid ?>"
                                        title="Archive order <?= h($order['order_number']) ?>">
                                    Archive
                                </button>
                            </div>
                        </div>
                    </td>
                    <?php endif; ?>
                    <?php if ($filterStatus === 'printed'): ?>
                    <td>
                        <button class="btn-revert-pending"
                                data-id="<?= $oid ?>"
                                title="Move order <?= h($order['order_number']) ?> back to pending">
                            Pending
                        </button>
                    </td>
                    <?php endif; ?>
                    <?php if ($filterStatus === 'archived'): ?>
                    <td>
                        <button class="btn-unarchive"
                                data-id="<?= $oid ?>"
                                title="Move order <?= h($order['order_number']) ?> back to pending">
                            Unarchive
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
        </div>

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

<?php require __DIR__ . '/../app/partials/print-modals.php'; ?>

<style>
/* ── Viewport-locked layout (no page scroll) ───────────────────────────────── */
html, body { height: 100vh; overflow: hidden; }
body { min-height: 0; }

.main {
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

.page-header { flex-shrink: 0; }
.filter-bar  { flex-shrink: 0; }

.card {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.table-scroll {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}

.card > table   { flex: 1; min-height: 0; }
.card > .pagination { flex-shrink: 0; }

/* ── Sticky table header ───────────────────────────────────────────────────── */
.table-scroll thead { position: sticky; top: 0; z-index: 2; }
.table-scroll thead th { background: #1a1a2e; }

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

/* ── Raw data modal (page-specific additions) ──────────────────────────────── */

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

/* ── Unarchive button ─────────────────────────────────────────────────── */
.btn-unarchive {
    display: inline-block;
    padding: .4rem 1rem;
    background: transparent;
    color: var(--accent, #4f46e5);
    border: 1px solid var(--accent, #4f46e5);
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-unarchive:hover { background: var(--accent, #4f46e5); color: #fff; }
.btn-unarchive:disabled { opacity: .45; cursor: default; }

/* ── Button group (Printed + dropdown) ──────────────────────────────── */
.btn-group {
    position: relative;
    display: inline-flex;
    white-space: nowrap;
}

.btn-set-printed {
    display: inline-block;
    padding: .4rem 1rem;
    background: transparent;
    color: #166534;
    border: 1px solid #166534;
    border-radius: 6px 0 0 6px;
    font-size: .8rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-set-printed:hover { background: #166534; color: #fff; }
.btn-set-printed:disabled { opacity: .45; cursor: default; }

.btn-group-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: .4rem .5rem;
    background: transparent;
    color: #166534;
    border: 1px solid #166534;
    border-left: none;
    border-radius: 0 6px 6px 0;
    font-size: .7rem;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-group-toggle:hover { background: #166534; color: #fff; }

.btn-group-menu {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 10;
    margin-top: 2px;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #d1d5db);
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
    min-width: 100%;
}

.btn-group-menu[hidden] { display: none; }

.btn-group-item {
    display: block;
    width: 100%;
    padding: .45rem .75rem;
    background: transparent;
    border: none;
    font-size: .8rem;
    font-weight: 500;
    color: var(--text, #374151);
    cursor: pointer;
    text-align: left;
    white-space: nowrap;
    transition: background .15s;
}

.btn-group-item:hover { background: var(--bg-subtle, #f3f4f6); }

/* ── Revert to Pending button (printed tab) ─────────────────────────── */
.btn-revert-pending {
    display: inline-block;
    padding: .4rem 1rem;
    background: transparent;
    color: var(--accent, #4f46e5);
    border: 1px solid var(--accent, #4f46e5);
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-revert-pending:hover { background: var(--accent, #4f46e5); color: #fff; }
.btn-revert-pending:disabled { opacity: .45; cursor: default; }

/* Row printed state (mirrors archived-row) */
tr.printed-row td { opacity: .35; text-decoration: line-through; pointer-events: none; }
</style>

<script>
(function () {
    'use strict';

    // fmtDate, escHtml, and toggleAccordion are provided by app/partials/header.php.
    // Use escHtml() throughout this file (esc is aliased below for backward compat).
    var esc = escHtml;

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
            var rows = li.map(function (item, i) {
                var unitPrice = Number(item.price);
                var qty       = Number(item.quantity);
                var ml        = item.variant_ml != null ? String(item.variant_ml) : '';
                var brand     = item.custom_brand || '';
                var preferredTitle = item.preferred_title != null ? item.preferred_title : null;
                var preferredBrand = item.preferred_brand != null ? item.preferred_brand : null;
                var strippedTitle = preferredTitle != null ? preferredTitle : stripBrandPrefix(item.title, brand);
                var displayBrand  = preferredBrand != null ? preferredBrand : brand;
                return '<tr>' +
                    '<td>' + esc(item.title) + '</td>' +
                    '<td>' + esc(item.variant_title) + '</td>' +
                    '<td>' + (ml ? esc(ml) : '') + '</td>' +
                    '<td>' + esc(item.sku) + '</td>' +
                    '<td>' + esc(item.vendor) + '</td>' +
                    '<td>' + esc(brand) + '</td>' +
                    '<td>' + qty + '</td>' +
                    '<td>' + unitPrice.toFixed(2) + '</td>' +
                    '<td>' + (unitPrice * qty).toFixed(2) + '</td>' +
                    '<td class="oneoff-print-cell">' +
                        (ml ? '<button class="btn-oneoff-print"' +
                            ' data-order-id="' + esc(String(o.id)) + '"' +
                            ' data-title="' + esc(strippedTitle) + '"' +
                            ' data-full-title="' + esc(item.title) + '"' +
                            ' data-brand="' + esc(displayBrand) + '"' +
                            ' data-ml="' + esc(ml) + '"' +
                            ' data-product-id="' + esc(item.shopify_product_id || '') + '"' +
                            ' data-preferred-title="' + esc(preferredTitle || '') + '"' +
                            ' data-preferred-brand="' + esc(preferredBrand || '') + '"' +
                            ' title="Print one label">Print</button>' : '') +
                    '</td>' +
                    '</tr>';
            }).join('');

            items =
                '<div class="order-detail-items">' +
                '<h4>Line Items</h4>' +
                '<table class="line-items-table"><thead><tr>' +
                '<th>Product</th><th>Variant</th><th>ML</th><th>SKU</th>' +
                '<th>Vendor</th><th>Brand</th><th>Qty</th><th>Unit Price</th><th>Line Total</th>' +
                '<th></th>' +
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
                    wireOneoffPrintButtons(contentEl);
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

    // ── Use shared stripBrandPrefix from PrintModals ──────────────────────────
    var stripBrandPrefix = PrintModals.stripBrandPrefix;

    // ── Print modals (delegated to shared PrintModals partial) ─────────────

    var wireOneoffPrintButtons = PrintModals.wireOneoffPrintButtons;

    function markOrderPrinted(orderId) {
        var row = document.querySelector('tr.order-row[data-order-id="' + orderId + '"]');
        if (row) {
            row.classList.add('printed-row');
            var printBtn = row.querySelector('.btn-print');
            if (printBtn) {
                printBtn.textContent = 'Printed';
                printBtn.disabled = true;
            }
            var setPrintedBtn = row.querySelector('.btn-set-printed');
            if (setPrintedBtn) {
                setPrintedBtn.textContent = 'Printed';
                setPrintedBtn.disabled = true;
            }
            var groupToggle = row.querySelector('.btn-group-toggle');
            if (groupToggle) groupToggle.disabled = true;
        }
    }

    PrintModals.onPrintConfirm = markOrderPrinted;

    // Wire up all print buttons
    document.querySelectorAll('.btn-print').forEach(function (btn) {
        btn.addEventListener('click', function () {
            PrintModals.openPrintModal(btn.dataset.id, btn.dataset.orderNumber, detailCache);
        });
    });

    // ── Pagination: autoscroll table to top ─────────────────────────────────────
    var tableScroll = document.getElementById('table-scroll');
    document.querySelectorAll('.pagination .page-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (tableScroll) tableScroll.scrollTop = 0;
        });
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

            fetch('api/archive-order.php', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/x-www-form-urlencoded',
                    'X-CSRF-Token':  CSRF_TOKEN,
                },
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

    // ── Button group toggle (dropdown) ──────────────────────────────────────
    document.querySelectorAll('.btn-group-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu = btn.parentElement.querySelector('.btn-group-menu');
            var isOpen = !menu.hidden;
            // Close all open menus first
            document.querySelectorAll('.btn-group-menu').forEach(function (m) { m.hidden = true; });
            document.querySelectorAll('.btn-group-toggle').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
            if (!isOpen) {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // Close dropdown on outside click
    document.addEventListener('click', function () {
        document.querySelectorAll('.btn-group-menu').forEach(function (m) { m.hidden = true; });
        document.querySelectorAll('.btn-group-toggle').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    });

    // ── Set Printed (from button group) ─────────────────────────────────────
    document.querySelectorAll('.btn-set-printed').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.dataset.id;
            var row = btn.closest('tr');

            btn.disabled = true;
            btn.textContent = 'Updating…';

            var body = new URLSearchParams();
            body.append('id', id);

            fetch('api/set-printed.php', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/x-www-form-urlencoded',
                    'X-CSRF-Token':  CSRF_TOKEN,
                },
                body: body.toString(),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    row.classList.add('printed-row');
                    btn.textContent = 'Printed';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Printed';
                    alert('Could not update order: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Printed';
                alert('Network error — please try again.');
            });
        });
    });

    // ── Revert to Pending (from printed tab) ─────────────────────────────────
    document.querySelectorAll('.btn-revert-pending').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.dataset.id;
            var row = btn.closest('tr');

            btn.disabled = true;
            btn.textContent = 'Reverting…';

            var body = new URLSearchParams();
            body.append('id', id);

            fetch('api/revert-to-pending.php', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/x-www-form-urlencoded',
                    'X-CSRF-Token':  CSRF_TOKEN,
                },
                body: body.toString(),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    row.classList.add('archived-row');
                    btn.textContent = 'Reverted';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Pending';
                    alert('Could not revert order: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Pending';
                alert('Network error — please try again.');
            });
        });
    });

    // ── Unarchive ─────────────────────────────────────────────────────────────
    document.querySelectorAll('.btn-unarchive').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.dataset.id;
            var row = btn.closest('tr');

            btn.disabled = true;
            btn.textContent = 'Restoring…';

            var body = new URLSearchParams();
            body.append('id', id);

            fetch('api/unarchive-order.php', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/x-www-form-urlencoded',
                    'X-CSRF-Token':  CSRF_TOKEN,
                },
                body: body.toString(),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    row.classList.add('archived-row');
                    btn.textContent = 'Restored';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Unarchive';
                    alert('Could not restore order: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Unarchive';
                alert('Network error — please try again.');
            });
        });
    });
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
