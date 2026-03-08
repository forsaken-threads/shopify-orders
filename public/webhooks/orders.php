<?php
declare(strict_types=1);

/**
 * Shopify orders webhook endpoint.
 *
 * Register in Shopify admin under Settings → Notifications → Webhooks.
 * Supported topics: orders/create, orders/updated, orders/paid, orders/fulfilled
 *
 * Authentication: X-Shopify-Hmac-Sha256 header verified against SHOPIFY_WEBHOOK_SECRET.
 *
 * Product brand (custom.brand) is resolved from the local products table rather
 * than calling the Shopify Admin API at webhook time.  Run scripts/sync-products.php
 * once to populate the table, then keep it current via the products webhook.
 */

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

// ── Validate HTTP method ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ── Read the payload ──────────────────────────────────────────────────────────
// Must be read before any other input consumption; also required for HMAC verification.

$rawBody = (string) file_get_contents('php://input');

// ── Authenticate via Shopify HMAC-SHA256 ─────────────────────────────────────

if (!verifyShopifyHmac($config['shopify_webhook_secret'], $rawBody)) {
    http_response_code(401);
    exit('Unauthorized');
}

$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

// Acknowledge topics we don't handle with 200 so Shopify stops retrying.
$handled = ['orders/create', 'orders/updated', 'orders/paid', 'orders/fulfilled'];
if (!in_array($topic, $handled, strict: true)) {
    http_response_code(200);
    exit('OK');
}

// ── Parse payload ─────────────────────────────────────────────────────────────

$order = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);

if (!is_array($order) || empty($order['id'])) {
    http_response_code(422);
    exit('Unprocessable Entity');
}

// ── For non-fulfilled topics: only process revenue-bearing orders ─────────────
//
// Accept paid, partially_refunded, and refunded orders.
// Partially/fully refunded orders are stored with their original line item amounts;
// refund attribution (order.refunds[].refund_line_items[]) is not applied to
// revenue figures — refunds are rare and the data is still useful for reporting.

const ACCEPTED_FINANCIAL_STATUSES = ['paid', 'partially_refunded', 'refunded'];

if ($topic !== 'orders/fulfilled') {
    $financialStatus = $order['financial_status'] ?? '';
    if (!in_array($financialStatus, ACCEPTED_FINANCIAL_STATUSES, strict: true)) {
        webhookLog(dirname(__DIR__, 2) . '/logs/orders.log', $order['name'] ?? (string) $order['id'], $topic . ' (dropped: status=' . $financialStatus . ')');
        http_response_code(200);
        echo 'OK';
        exit;
    }
}

// ── Handle orders/fulfilled — update status on locally stored orders ──────────
//
// When Shopify fires orders/fulfilled we only need to flip the local status.
// We do NOT re-upsert the full order or replace line items.

if ($topic === 'orders/fulfilled') {
    $db = getDb($config);

    $shopifyId = (string) $order['id'];

    $stmt = $db->prepare(
        "UPDATE orders SET status = 'fulfilled' WHERE shopify_order_id = ? AND status != 'fulfilled'"
    );
    $stmt->execute([$shopifyId]);

    webhookLog(dirname(__DIR__, 2) . '/logs/orders.log', $order['name'] ?? (string) $order['id'], $topic);

    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Look up custom.brand from local products table ────────────────────────────
//
// Collect all unique product IDs in this order, then fetch their brands in a
// single query against the local products table (populated by sync-products.php
// and kept current by the products webhook).

$db = getDb($config);

$productIds = [];
foreach ($order['line_items'] ?? [] as $item) {
    $pid = (string) ($item['product_id'] ?? '');
    if ($pid !== '') {
        $productIds[$pid] = true;
    }
}

/** @var array<string,string|null> $brandByProductId */
$brandByProductId = [];

if (!empty($productIds)) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $brandStmt    = $db->prepare(
        "SELECT shopify_product_id, custom_brand FROM products WHERE shopify_product_id IN ({$placeholders})"
    );
    $brandStmt->execute(array_keys($productIds));
    foreach ($brandStmt->fetchAll() as $row) {
        $brandByProductId[$row['shopify_product_id']] = $row['custom_brand'];
    }
}

// ── Persist to SQLite ─────────────────────────────────────────────────────────

$shopifyId    = (string) $order['id'];
$orderNumber  = (string) ($order['order_number'] ?? $order['name'] ?? $order['id']);
$customerName = trim(
    ($order['customer']['first_name'] ?? '') . ' ' .
    ($order['customer']['last_name']  ?? '')
);
$customerEmail = $order['customer']['email'] ?? $order['email'] ?? '';
$totalPrice    = (float) ($order['total_price'] ?? 0.0);
$currency      = $order['currency']    ?? 'USD';
$createdAt     = $order['created_at']  ?? date('c');

try {
    $db->beginTransaction();

    // Upsert the order; preserve existing status so a re-delivery doesn't
    // reset a manually-updated status back to 'pending'.
    $db->prepare(<<<'SQL'
        INSERT INTO orders
            (shopify_order_id, order_number, customer_name, customer_email,
             total_price, currency, raw_data, shopify_created_at)
        VALUES
            (:shopify_id, :order_number, :customer_name, :customer_email,
             :total_price, :currency, :raw_data, :created_at)
        ON CONFLICT(shopify_order_id) DO UPDATE SET
            order_number   = excluded.order_number,
            customer_name  = excluded.customer_name,
            customer_email = excluded.customer_email,
            total_price    = excluded.total_price,
            currency       = excluded.currency,
            raw_data       = excluded.raw_data
    SQL)->execute([
        ':shopify_id'     => $shopifyId,
        ':order_number'   => $orderNumber,
        ':customer_name'  => $customerName,
        ':customer_email' => $customerEmail,
        ':total_price'    => $totalPrice,
        ':currency'       => $currency,
        ':raw_data'       => $rawBody,
        ':created_at'     => $createdAt,
    ]);

    // Fetch the internal ID reliably.
    $idStmt = $db->prepare('SELECT id FROM orders WHERE shopify_order_id = ?');
    $idStmt->execute([$shopifyId]);
    $orderId = (int) $idStmt->fetchColumn();

    // Replace line items on every delivery so we stay in sync with Shopify.
    $db->prepare('DELETE FROM order_line_items WHERE order_id = ?')->execute([$orderId]);

    $lineStmt = $db->prepare(<<<'SQL'
        INSERT INTO order_line_items
            (order_id, shopify_line_item_id, shopify_product_id, title, variant_title, variant_ml,
             sku, vendor, quantity, price, custom_brand)
        VALUES
            (:order_id, :line_item_id, :shopify_product_id, :title, :variant_title, :variant_ml,
             :sku, :vendor, :quantity, :price, :custom_brand)
    SQL);

    foreach ($order['line_items'] ?? [] as $item) {
        $productId   = (string) ($item['product_id'] ?? '');
        $customBrand = $brandByProductId[$productId] ?? null;

        // Fall back to line item properties for backwards compatibility when the
        // product isn't in the local table yet.
        if ($customBrand === null) {
            foreach ($item['properties'] ?? [] as $prop) {
                if (($prop['name'] ?? '') === 'custom.brand') {
                    $customBrand = ($prop['value'] !== '' && $prop['value'] !== null)
                        ? (string) $prop['value']
                        : null;
                    break;
                }
            }
        }

        $variantTitle = $item['variant_title'] ?? null;
        $variantMl    = null;
        if ($variantTitle !== null && preg_match('/^(\d+)\s*ml$/i', $variantTitle, $m)) {
            $variantMl = (int) $m[1];
        }

        $lineStmt->execute([
            ':order_id'           => $orderId,
            ':line_item_id'       => (string) ($item['id'] ?? ''),
            ':shopify_product_id' => $productId !== '' ? $productId : null,
            ':title'              => (string) ($item['title'] ?? ''),
            ':variant_title'      => $variantTitle,
            ':variant_ml'         => $variantMl,
            ':sku'                => $item['sku']    ?? null,
            ':vendor'             => $item['vendor'] ?? null,
            ':quantity'           => (int)   ($item['quantity'] ?? 1),
            ':price'              => (float) ($item['price']    ?? 0.0),
            ':custom_brand'       => $customBrand,
        ]);
    }

    $db->commit();

    webhookLog(dirname(__DIR__, 2) . '/logs/orders.log', $orderNumber, $topic);

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log(sprintf('[orders-webhook] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    exit('Internal Server Error');
}

http_response_code(200);
echo 'OK';

// ── Helpers ───────────────────────────────────────────────────────────────────

function webhookLog(string $file, string $identifier, string $topic): void
{
    $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $identifier, $topic);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function verifyShopifyHmac(string $secret, string $rawBody): bool
{
    if ($secret === '') {
        return false;
    }
    $provided = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    if ($provided === '') {
        return false;
    }
    $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, binary: true));
    return hash_equals($computed, $provided);
}
