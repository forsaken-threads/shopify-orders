<?php
declare(strict_types=1);

/**
 * Shopify products webhook endpoint.
 *
 * Register in Shopify admin under Settings → Notifications → Webhooks.
 * Supported topics: products/create, products/update, products/delete
 *
 * Authentication: X-Shopify-Hmac-Sha256 header verified against SHOPIFY_WEBHOOK_SECRET.
 *
 * Products in all statuses (active, draft, archived) are upserted into the
 * local products table.
 *
 * is_bundle is set to 1 when a product title ends with the word "bundle"
 * (case-insensitive match), allowing bundle products to be identified without
 * re-scanning titles at query time.
 *
 * Note: the Shopify products webhook payload does not include metafields by
 * default.  custom_brand will be null unless Shopify is configured to send
 * metafields in the webhook payload (via metafield subscriptions), or until
 * scripts/sync-products.php is run to populate that column.
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

$rawBody = (string) file_get_contents('php://input');

// ── Authenticate via Shopify HMAC-SHA256 ─────────────────────────────────────

if (!verifyShopifyHmac($config['shopify_webhook_secret'], $rawBody)) {
    http_response_code(401);
    exit('Unauthorized');
}

$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

$handled = ['products/create', 'products/update', 'products/delete'];
if (!in_array($topic, $handled, strict: true)) {
    http_response_code(200);
    exit('OK');
}

// ── Parse payload ─────────────────────────────────────────────────────────────

$product = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);

if (!is_array($product) || empty($product['id'])) {
    http_response_code(422);
    exit('Unprocessable Entity');
}

$db               = getDb($config);
$shopifyProductId = (string) $product['id'];

// ── Handle products/delete ────────────────────────────────────────────────────

if ($topic === 'products/delete') {
    $db->prepare('DELETE FROM products WHERE shopify_product_id = ?')->execute([$shopifyProductId]);
    webhookLog(dirname(__DIR__, 2) . '/logs/products.log', (string) ($product['title'] ?? $shopifyProductId), $topic);
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Build field values ────────────────────────────────────────────────────────

$status    = (string) ($product['status'] ?? 'active');
$title     = (string) ($product['title'] ?? '');
$vendor    = isset($product['vendor']) && $product['vendor'] !== '' ? (string) $product['vendor'] : null;
$createdAt = isset($product['created_at']) && $product['created_at'] !== '' ? (string) $product['created_at'] : null;

// A product is a bundle when its title ends with the word "bundle" (case-insensitive).
$isBundle = (int) (bool) preg_match('/\bbundle\s*$/i', $title);

// Extract custom.brand from metafields if Shopify included them in the payload.
// (Requires a metafield subscription configured in Shopify — absent by default.)
$customBrand = null;
foreach ($product['metafields'] ?? [] as $mf) {
    if (($mf['namespace'] ?? '') === 'custom' && ($mf['key'] ?? '') === 'brand') {
        $val = $mf['value'] ?? null;
        $customBrand = ($val !== null && $val !== '') ? (string) $val : null;
        break;
    }
}

// ── Upsert into local products table ─────────────────────────────────────────

try {
    $db->prepare(<<<'SQL'
        INSERT INTO products
            (shopify_product_id, title, vendor, status, custom_brand, is_bundle, raw_data, shopify_created_at)
        VALUES
            (:shopify_product_id, :title, :vendor, :status, :custom_brand, :is_bundle, :raw_data, :shopify_created_at)
        ON CONFLICT(shopify_product_id) DO UPDATE SET
            title              = excluded.title,
            vendor             = excluded.vendor,
            status             = excluded.status,
            custom_brand       = CASE
                                     WHEN excluded.custom_brand IS NOT NULL THEN excluded.custom_brand
                                     ELSE products.custom_brand
                                 END,
            is_bundle          = excluded.is_bundle,
            raw_data           = excluded.raw_data,
            shopify_created_at = excluded.shopify_created_at,
            synced_at          = datetime('now')
    SQL)->execute([
        ':shopify_product_id'  => $shopifyProductId,
        ':title'               => $title,
        ':vendor'              => $vendor,
        ':status'              => $status,
        ':custom_brand'        => $customBrand,
        ':is_bundle'           => $isBundle,
        ':raw_data'            => $rawBody,
        ':shopify_created_at'  => $createdAt,
    ]);
} catch (Throwable $e) {
    error_log(sprintf('[products-webhook] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    exit('Internal Server Error');
}

webhookLog(dirname(__DIR__, 2) . '/logs/products.log', $title, $topic);

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
