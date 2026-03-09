<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$db = getDb($config);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    $pageTitle  = 'Order Not Found - Utility App';
    $activePage = 'orders';
    require __DIR__ . '/../app/partials/header.php';
    echo '<div class="main"><div class="empty-state"><strong>Order not found.</strong></div></div>';
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

$stmt = $db->prepare(
    "SELECT id, shopify_order_id, order_number, customer_name, customer_email,
            total_price, currency, status, shopify_created_at, received_at
     FROM   orders
     WHERE  id = ?"
);
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle  = 'Order Not Found - Utility App';
    $activePage = 'orders';
    require __DIR__ . '/../app/partials/header.php';
    echo '<div class="main"><div class="empty-state"><strong>Order not found.</strong></div></div>';
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

$liStmt = $db->prepare(
    "SELECT title, variant_title, variant_ml, sku, vendor, quantity, price, custom_brand, shopify_product_id
     FROM   order_line_items
     WHERE  order_id = ?
     ORDER  BY id ASC"
);
$liStmt->execute([$id]);
$lineItems = $liStmt->fetchAll();

function statusBadgeOrder(string $status): string
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

function stripBrandPrefixPhp(string $title, string $brand): string
{
    if ($brand === '') return $title;
    return ltrim(preg_replace('/^' . preg_quote($brand, '/') . '\s*/i', '', $title));
}

$pageTitle  = 'Order ' . htmlspecialchars($order['order_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' - Utility App';
$activePage = 'orders';
require __DIR__ . '/../app/partials/header.php';
?>

<?php require __DIR__ . '/../app/partials/print-modals.php'; ?>

<style>
.order-page-main {
    flex: 1;
    padding: 2rem;
    max-width: 85vw;
    margin: 0 auto;
    width: 100%;
    overflow-y: auto;
}

.order-back-link {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .82rem;
    color: #666;
    text-decoration: none;
    margin-bottom: 1rem;
    transition: color .15s;
}

.order-back-link:hover { color: #1a1a2e; }

.order-page-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.order-page-header h1 { font-size: 1.4rem; font-weight: 700; }

.order-page-actions {
    margin-left: auto;
    display: flex;
    gap: .5rem;
}

/* ── Order meta ─────────────────────────────────────────────────────────── */
.order-meta-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.order-meta-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem 2.5rem;
}

.order-meta-grid > div {
    display: flex;
    flex-direction: column;
    min-width: 10rem;
}

.order-meta-grid dt {
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #6b7280;
    margin-bottom: .15rem;
}

.order-meta-grid dd {
    margin: 0;
    font-size: .875rem;
    color: #111827;
}

/* ── Line items ─────────────────────────────────────────────────────────── */
.line-items-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    overflow: hidden;
}

.line-items-card h2 {
    font-size: .9rem;
    font-weight: 700;
    padding: 1rem 1.25rem;
    margin: 0;
    border-bottom: 1px solid #f0f0f0;
}

.line-items-card table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}

.line-items-card thead { background: #1a1a2e; color: #fff; }

.line-items-card th {
    padding: .6rem .75rem;
    text-align: left;
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
}

.line-items-card td {
    padding: .6rem .75rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.line-items-card tbody tr:last-child td { border-bottom: none; }
.line-items-card tbody tr:hover td { background: #fafafa; }
</style>

<div class="order-page-main">
    <a href="orders.php" class="order-back-link">&larr; Back to Orders</a>

    <div class="order-page-header">
        <h1>Order <?= h($order['order_number']) ?></h1>
        <?= statusBadgeOrder($order['status']) ?>
        <div class="order-page-actions">
            <?php if ($order['status'] === 'pending'): ?>
            <button class="btn-print" id="order-print-btn"
                    data-id="<?= $id ?>"
                    data-order-number="<?= h($order['order_number']) ?>">
                Print
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="order-meta-card">
        <dl class="order-meta-grid">
            <div><dt>Shopify Order ID</dt><dd><?= h($order['shopify_order_id']) ?></dd></div>
            <div><dt>Customer</dt><dd><?= h($order['customer_name']) ?><?php if ($order['customer_email']): ?> <span style="color:#888;font-size:.82rem;">&lt;<?= h($order['customer_email']) ?>&gt;</span><?php endif; ?></dd></div>
            <div><dt>Total</dt><dd><?= h($order['currency']) ?> <?= h(number_format((float) $order['total_price'], 2)) ?></dd></div>
            <div><dt>Status</dt><dd><?= statusBadgeOrder($order['status']) ?></dd></div>
            <div>
                <dt>Order Date</dt>
                <dd><?= h((function($d) use ($config) {
                    try {
                        return (new DateTimeImmutable($d))
                            ->setTimezone(new DateTimeZone($config['display_timezone']))
                            ->format('d M Y, H:i');
                    } catch (Exception) { return $d; }
                })($order['shopify_created_at'])) ?></dd>
            </div>
            <div>
                <dt>Received</dt>
                <dd><?= h((function($d) use ($config) {
                    try {
                        return (new DateTimeImmutable($d))
                            ->setTimezone(new DateTimeZone($config['display_timezone']))
                            ->format('d M Y, H:i');
                    } catch (Exception) { return $d; }
                })($order['received_at'])) ?></dd>
            </div>
        </dl>
    </div>

    <?php if (!empty($lineItems)): ?>
    <div class="line-items-card">
        <h2>Line Items</h2>
        <table>
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
                    <?php if ($order['status'] !== 'pending'): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lineItems as $item):
                $unitPrice = (float) $item['price'];
                $qty       = (int) $item['quantity'];
                $ml        = $item['variant_ml'] !== null ? (int) $item['variant_ml'] : null;
                $brand     = $item['custom_brand'] ?? '';
                $strippedTitle = stripBrandPrefixPhp($item['title'], $brand);
            ?>
                <tr>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h($item['variant_title'] ?? '') ?></td>
                    <td><?= $ml !== null ? h((string) $ml) : '' ?></td>
                    <td><?= h($item['sku'] ?? '') ?></td>
                    <td><?= h($item['vendor'] ?? '') ?></td>
                    <td><?= h($brand) ?></td>
                    <td class="qty"><?= $qty ?></td>
                    <td class="price"><?= number_format($unitPrice, 2) ?></td>
                    <td class="price"><?= number_format($unitPrice * $qty, 2) ?></td>
                    <?php if ($order['status'] !== 'pending'): ?>
                    <td class="oneoff-print-cell">
                        <?php if ($ml !== null): ?>
                        <button class="btn-oneoff-print"
                                data-order-id="<?= $id ?>"
                                data-title="<?= h($strippedTitle) ?>"
                                data-full-title="<?= h($item['title']) ?>"
                                data-brand="<?= h($brand) ?>"
                                data-ml="<?= h((string) $ml) ?>"
                                data-product-id="<?= h($item['shopify_product_id'] ?? '') ?>"
                                title="Print one label">Print</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    // Wire one-off print buttons
    PrintModals.wireOneoffPrintButtons(document.querySelector('.line-items-card') || document);

    // Wire the full-order print button (only shown when status is "printed")
    var printBtn = document.getElementById('order-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            PrintModals.openPrintModal(printBtn.dataset.id, printBtn.dataset.orderNumber, {});
        });
    }
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
