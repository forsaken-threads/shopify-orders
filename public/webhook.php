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

// ── Fetch custom.brand metafield for each product via Shopify Admin API ───────
// The custom.brand value lives on the product as a metafield (namespace=custom,
// key=brand). It is NOT part of the order payload, so we pull it here before
// persisting.  Results are keyed by product_id (string).
//
// If Admin API credentials are not configured the map is left empty and we
// fall back to reading the value from line item properties (legacy path).

/** @var array<string,string|null> $brandByProductId */
$brandByProductId = [];

if ($config['shopify_access_token'] !== '' && $config['shopify_shop_domain'] !== '') {
    $productIds = [];
    foreach ($order['line_items'] ?? [] as $item) {
        $pid = (string) ($item['product_id'] ?? '');
        if ($pid !== '' && !isset($productIds[$pid])) {
            $productIds[$pid] = true;
        }
    }

    foreach (array_keys($productIds) as $productId) {
        $brand = fetchProductBrand(
            $config['shopify_shop_domain'],
            $config['shopify_access_token'],
            $config['shopify_api_version'],
            $productId
        );
        $brandByProductId[$productId] = $brand;
    }
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
            (order_id, shopify_line_item_id, title, variant_title, sku, vendor, quantity, price, custom_brand)
        VALUES
            (:order_id, :line_item_id, :title, :variant_title, :sku, :vendor, :quantity, :price, :custom_brand)
    SQL);

    foreach ($order['line_items'] ?? [] as $item) {
        // Prefer the value fetched from the product metafield (custom.brand).
        // Fall back to line item properties for backwards compatibility.
        $productId   = (string) ($item['product_id'] ?? '');
        $customBrand = isset($brandByProductId[$productId])
            ? $brandByProductId[$productId]
            : null;

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

        $lineStmt->execute([
            ':order_id'      => $orderId,
            ':line_item_id'  => (string) ($item['id'] ?? ''),
            ':title'         => (string) ($item['title'] ?? ''),
            ':variant_title' => $item['variant_title'] ?? null,
            ':sku'           => $item['sku']            ?? null,
            ':vendor'        => $item['vendor']         ?? null,
            ':quantity'      => (int)    ($item['quantity'] ?? 1),
            ':price'         => (string) ($item['price']    ?? '0.00'),
            ':custom_brand'  => $customBrand,
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

/**
 * Fetch the custom.brand metafield value for a Shopify product.
 *
 * Calls:
 *   GET https://{shop}/admin/api/{version}/products/{product_id}/metafields.json
 *       ?namespace=custom&key=brand
 *
 * Returns the metafield value string, or null if not found / on error.
 */
function fetchProductBrand(
    string $shopDomain,
    string $accessToken,
    string $apiVersion,
    string $productId
): ?string {
    $url = sprintf(
        'https://%s/admin/api/%s/products/%s/metafields.json?namespace=custom&key=brand',
        $shopDomain,
        rawurlencode($apiVersion),
        rawurlencode($productId)
    );

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", [
                'X-Shopify-Access-Token: ' . $accessToken,
                'Accept: application/json',
            ]),
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log(sprintf('[shopify-webhook] fetchProductBrand: HTTP request failed for product %s', $productId));
        return null;
    }

    // Check for a non-2xx response code from $http_response_header.
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('#HTTP/\S+\s+(2\d{2})#', $statusLine)) {
        error_log(sprintf('[shopify-webhook] fetchProductBrand: unexpected status "%s" for product %s', $statusLine, $productId));
        return null;
    }

    try {
        $data = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log(sprintf('[shopify-webhook] fetchProductBrand: invalid JSON for product %s: %s', $productId, $e->getMessage()));
        return null;
    }

    $value = $data['metafields'][0]['value'] ?? null;
    return ($value !== null && $value !== '') ? (string) $value : null;
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
