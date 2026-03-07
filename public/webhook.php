<?php
declare(strict_types=1);

/**
 * Shopify webhook endpoint.
 *
 * Register in Shopify admin under Settings → Notifications → Webhooks.
 * Supported topics: orders/create, orders/updated, orders/paid
 *
 * Every request is authenticated by verifying the X-Shopify-Hmac-Sha256 header:
 *   X-Shopify-Hmac-Sha256: <base64(HMAC-SHA256(SHOPIFY_WEBHOOK_SECRET, raw_body))>
 *
 * Set SHOPIFY_WEBHOOK_SECRET in env.ini (copied from env.ini.example) or as a
 * real environment variable. The value must be kept secret.
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

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
$topic   = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

// Acknowledge topics we don't handle with 200 so Shopify stops retrying.
$handled = ['orders/create', 'orders/updated', 'orders/paid'];
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

// ── Persist to SQLite ─────────────────────────────────────────────────────────

$db = getDb($config);

$shopifyId    = (string) $order['id'];
$orderNumber  = (string) ($order['order_number'] ?? $order['name'] ?? $order['id']);
$customerName = trim(
    ($order['customer']['first_name'] ?? '') . ' ' .
    ($order['customer']['last_name']  ?? '')
);
$customerEmail = $order['customer']['email'] ?? $order['email'] ?? '';
$totalPrice    = $order['total_price'] ?? '0.00';
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
        ':shopify_id'    => $shopifyId,
        ':order_number'  => $orderNumber,
        ':customer_name' => $customerName,
        ':customer_email'=> $customerEmail,
        ':total_price'   => $totalPrice,
        ':currency'      => $currency,
        ':raw_data'      => $rawBody,
        ':created_at'    => $createdAt,
    ]);

    // Fetch the internal ID reliably (PDOStatement::execute returns bool, not the statement).
    $idStmt = $db->prepare('SELECT id FROM orders WHERE shopify_order_id = ?');
    $idStmt->execute([$shopifyId]);
    $orderId = (int) $idStmt->fetchColumn();

    // Replace line items on every delivery so we stay in sync with Shopify.
    $db->prepare('DELETE FROM order_line_items WHERE order_id = ?')->execute([$orderId]);

    $lineStmt = $db->prepare(<<<'SQL'
        INSERT INTO order_line_items
            (order_id, shopify_line_item_id, title, variant_title, sku, vendor, quantity, price, custom_order)
        VALUES
            (:order_id, :line_item_id, :title, :variant_title, :sku, :vendor, :quantity, :price, :custom_order)
    SQL);

    foreach ($order['line_items'] ?? [] as $item) {
        // Extract custom.order from the line item's properties array.
        // Shopify sends properties as [{name: "...", value: "..."}, ...].
        $customOrder = null;
        foreach ($item['properties'] ?? [] as $prop) {
            if (($prop['name'] ?? '') === 'custom.order') {
                $customOrder = ($prop['value'] !== '' && $prop['value'] !== null)
                    ? (string) $prop['value']
                    : null;
                break;
            }
        }

        $lineStmt->execute([
            ':order_id'      => $orderId,
            ':line_item_id'  => (string) ($item['id'] ?? ''),
            ':title'         => (string) ($item['title'] ?? ''),
            ':variant_title' => $item['variant_title'] ?? null,
            ':sku'           => $item['sku']            ?? null,
            ':vendor'        => $item['vendor']         ?? null,
            ':quantity'      => (int)    ($item['quantity'] ?? 1),
            ':price'         => (string) ($item['price']    ?? '0.00'),
            ':custom_order'  => $customOrder,
        ]);
    }

    $db->commit();

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log(sprintf('[shopify-webhook] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    exit('Internal Server Error');
}

http_response_code(200);
echo 'OK';

// ── Helpers ───────────────────────────────────────────────────────────────────

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
