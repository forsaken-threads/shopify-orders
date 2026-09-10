<?php
declare(strict_types=1);

/**
 * Print labels for an order, a single one-off label, a bundle, or a product.
 *
 * POST /api/print-order.php
 * Body (multipart/form-data):
 *   action              — "print" (default), "confirm", "oneoff", "bundle", or "product"
 *
 * action=print:
 *   order_id            — internal order PK
 *   items[i][title]     — (possibly edited) stripped product title
 *   items[i][full_title]— original full product title
 *   items[i][custom_brand]    — (possibly edited) brand
 *   items[i][original_brand]  — original brand value
 *   items[i][shopify_product_id] — Shopify product ID
 *   items[i][ml]              — variant ML size (1, 5, or 10)
 *   items[i][quantity]        — label quantity
 * Returns: {ok:true, results:[{index, title, status:"ok"|"error", error?, skipped?}]}
 * Does NOT update order status — the user must confirm after reviewing.
 *
 * action=confirm:
 *   order_id            — internal order PK
 * Updates order status to 'printed'.
 * Returns: {ok:true}
 *
 * action=bundle:
 *   bundle_id           — internal products.id of an is_bundle=1 product
 *   items[i][*]         — same shape as action=print
 * No order-summary label is appended, no status is transitioned, per-row
 * save_edits still persists preferred_title/preferred_brand on the component.
 * Log lines use bundle:<shopify_product_id> instead of order:<shopify_order_id>.
 *
 * action=product:
 *   product_id          — internal products.id of a non-bundle product
 *   items[0][*]         — same shape as action=print
 * On-demand reprint of a single label for a product nobody ordered — no order
 * is involved, so no status is transitioned.  Persistence follows the one-off
 * rule (the global skip_persist flag) rather than a per-row checkbox.
 * Log lines use product:<shopify_product_id>.
 *
 * The print host is probed once before the first label, and a connect-level ssh
 * failure part-way through ends the job rather than re-paying ConnectTimeout on
 * every label that is left.  Those labels come back status:"error" with
 * skipped:true, and the response carries print_host_unreachable plus an error
 * string.  A failed probe answers 503 with ok:false and no results at all,
 * because nothing was attempted.
 *
 * For every action, items[i][shopify_product_id] is checked against the subject
 * the request resolved before a preference is persisted — the order's line
 * items, the bundle and its components, or the product itself.  A label still
 * prints when the check fails; only the write is skipped, and the skip is
 * recorded in print-labels.log.
 *
 * Header: X-CSRF-Token: <token>
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/permissions.php';

requireApiPermission($config, 'orders');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF validation ───────────────────────────────────────────────────────────

$providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken  = $_SESSION['csrf_token']        ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

// PHP holds an exclusive flock on the session file for the whole request, and
// the print loop below runs one blocking ssh per label.  Without this, a slow
// print blocks every other request from the same operator and exhausts the pool.
session_write_close();

$db = getDb($config);

$action = trim((string) ($_POST['action'] ?? 'print'));

$force       = (bool) ($_POST['force'] ?? false);
$skipPersist = (bool) ($_POST['skip_persist'] ?? false);  // global flag for one-off prints

// ── Resolve the subject (order, bundle or product) and the log identifier ────
//
// $logIdentifier is the token written into the print/error log lines so each
// label can be traced back to the thing it was printed for.  For orders this
// is the Shopify order id; for bundles and products it's the Shopify product
// id, prefixed so the two cannot be confused in the log.

if ($action === 'bundle') {
    $bundleId = (int) ($_POST['bundle_id'] ?? 0);
    if ($bundleId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid bundle ID.']);
        exit;
    }
    $bundleStmt = $db->prepare(
        "SELECT id, shopify_product_id FROM products
         WHERE id = ? AND is_bundle = 1 AND deleted_at IS NULL"
    );
    $bundleStmt->execute([$bundleId]);
    $bundle = $bundleStmt->fetch();
    if (!$bundle) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Bundle not found.']);
        exit;
    }
    $logIdentifier = 'bundle:' . $bundle['shopify_product_id'];
} elseif ($action === 'product') {
    $printProductId = (int) ($_POST['product_id'] ?? 0);
    if ($printProductId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid product ID.']);
        exit;
    }
    $productStmt = $db->prepare(
        "SELECT id, shopify_product_id FROM products
         WHERE id = ? AND is_bundle = 0 AND deleted_at IS NULL"
    );
    $productStmt->execute([$printProductId]);
    $product = $productStmt->fetch();
    if (!$product) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Product not found.']);
        exit;
    }
    $logIdentifier = 'product:' . $product['shopify_product_id'];
} else {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid order ID.']);
        exit;
    }

    if ($action === 'oneoff') {
        // One-off prints work on any order status and never change it.
        $orderStmt = $db->prepare("SELECT id, shopify_order_id, status FROM orders WHERE id = ?");
    } elseif ($force) {
        // Force flag allows reprinting orders that are already printed.
        $orderStmt = $db->prepare("SELECT id, shopify_order_id, status FROM orders WHERE id = ?");
    } else {
        // Regular print/confirm requires pending or fulfilled status.
        $orderStmt = $db->prepare("SELECT id, shopify_order_id, status FROM orders WHERE id = ? AND status IN ('pending', 'fulfilled')");
    }
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Order not found or not in a printable status.']);
        exit;
    }
    $logIdentifier = 'order:' . $order['shopify_order_id'];

    // ── action=confirm: finalize the order ───────────────────────────────
    if ($action === 'confirm') {
        // Only transition pending → printed; fulfilled orders keep their status.
        if ($order['status'] === 'pending') {
            $db->prepare("UPDATE orders SET status = 'printed' WHERE id = ? AND status = 'pending'")
               ->execute([$orderId]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── action=print: execute print commands and return per-item results ─────────

$items = $_POST['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No line items provided.']);
    exit;
}

$logDir     = dirname(__DIR__, 2) . '/logs';
$scriptsDir = dirname(__DIR__, 2) . '/scripts';
$labelLog   = $logDir . '/print-labels.log';

$labelEntries  = '';
$validMlSizes  = ['1', '5', '10'];
$timestamp     = date('Y-m-d H:i:s');
$results       = [];   // per-item status to return to the frontend
$maxRetries    = 2;     // retry transient SSH failures up to 2 times

$prefUpdateStmt = $db->prepare(
    "UPDATE products SET preferred_title = ?, preferred_brand = ? WHERE shopify_product_id = ?"
);

// The product id on each item comes from the browser, so it is checked against
// the subject the request actually resolved before any preference is persisted.
// Without it a caller could rewrite an unrelated product's label wording while
// print-labels.log records the subject it named.
if ($action === 'bundle') {
    $allowedStmt = $db->prepare(
        "SELECT p.shopify_product_id
         FROM   bundle_components bc
         JOIN   products          p ON p.id = bc.component_product_id
         WHERE  bc.bundle_product_id = ? AND p.deleted_at IS NULL"
    );
    $allowedStmt->execute([$bundle['id']]);
    // Item 0 of a bundle print is the bundle's own label, carrying its own id.
    $allowed = array_merge([$bundle['shopify_product_id']], $allowedStmt->fetchAll(PDO::FETCH_COLUMN));
} elseif ($action === 'product') {
    $allowed = [$product['shopify_product_id']];
} else {
    $allowedStmt = $db->prepare("SELECT shopify_product_id FROM order_line_items WHERE order_id = ?");
    $allowedStmt->execute([$order['id']]);
    $allowed = $allowedStmt->fetchAll(PDO::FETCH_COLUMN);
}
$allowedProductIds = array_filter(array_map('strval', $allowed), static fn(string $id): bool => $id !== '');

/**
 * Whether ssh gave up before it ever reached the print host.
 *
 * Keyed on ssh's own wording rather than on the errno text after the colon:
 * musl writes "Operation timed out" where glibc writes "Connection timed
 * out", so the container and a dev box word one failure two ways.  The two
 * prefixes cover connect timeout, refused, no route and DNS.  A connection
 * that dropped mid-transfer words itself differently and stays retryable,
 * which is the distinction the retry rule needs.
 */
function printHostUnreachable(string $sshOutput): bool
{
    return str_contains($sshOutput, 'ssh: connect to host ')
        || str_contains($sshOutput, 'ssh: Could not resolve hostname ');
}

// SSH options for every label and for the probe below.
// ConnectTimeout: fail fast if the printer host is unreachable.
// ServerAliveInterval/CountMax: detect a stalled connection within 15s.
// -4: the print host answers on IPv4 only — its AAAA record resolves but
// drops inbound SSH, so without this a container with a v6 route would
// burn ConnectTimeout on v6 before falling back on every single label.
$sshOpts   = '-4 -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=3';
$sshPrefix = "ssh {$sshOpts} " . escapeshellarg($config['print_ssh_target']) . ' ';

$hostUnreachable  = false;
$unreachableError = 'Printer host unreachable — this label was not sent.';

// One probe before the first label.  A host that is already down otherwise
// costs a full ConnectTimeout on the first label before the loop can tell,
// and the operator waits with nothing printed either way.  It does not
// replace the check inside the loop: the host can also drop part-way
// through, which is what happened on 2026-09-04.
$probeOutput = [];
$probeResult = 0;
exec($sshPrefix . escapeshellarg('true') . ' 2>&1', $probeOutput, $probeResult);
if ($probeResult !== 0 && printHostUnreachable(implode("\n", $probeOutput))) {
    file_put_contents(
        $logDir . '/print-errors.log',
        "[{$timestamp}] preflight exit:{$probeResult} | {$logIdentifier} | unreachable, nothing sent\n"
            . implode("\n", $probeOutput) . "\n---\n",
        FILE_APPEND | LOCK_EX
    );

    // 503 rather than 200: the request was fine and the dependency is not.
    // Every caller reads the body, so this only changes what the logs say.
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'The label printer host is not reachable.  Nothing was sent — check the printer, then try again.',
    ]);
    exit;
}

foreach ($items as $idx => $item) {
    $title          = trim((string) ($item['title'] ?? ''));
    $brand          = trim((string) ($item['custom_brand'] ?? ''));
    $fullTitle      = trim((string) ($item['full_title'] ?? ''));
    $productId      = trim((string) ($item['shopify_product_id'] ?? ''));
    $ml             = trim((string) ($item['ml'] ?? ''));
    $preferredTitle = (string) ($item['preferred_title'] ?? '');
    $preferredBrand = (string) ($item['preferred_brand'] ?? '');

    $isOrderLabel  = ($ml === 'order');
    $isBundleLabel = ($ml === 'bundle');

    // Items with no associated ML variant default to a 1ml label.
    if (!$isOrderLabel && !$isBundleLabel && $ml === '') {
        $ml = '1';
    }

    if (!$isOrderLabel && !$isBundleLabel && !in_array($ml, $validMlSizes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing ML size for item: ' . $title]);
        exit;
    }

    $qty = max(1, (int) ($item['quantity'] ?? 1));

    $mlArg = $isOrderLabel
        ? 'Order'
        : ($isBundleLabel ? 'Bundle' : $ml . 'ml');
    $remoteCmd = '~/print-service/venv/bin/python3 ~/print-service/print-label.py '
               . escapeshellarg($mlArg) . ' ' . escapeshellarg($title) . ' ' . escapeshellarg($brand);
    $cmd = $sshPrefix . escapeshellarg($remoteCmd);

    // Execute for each copy (quantity) — track per-item success.
    // Transient SSH failures (exit codes 255, 1) are retried up to $maxRetries
    // times.  A 255 that never reached the host is the exception: it ends the
    // job instead, because no retry of it can succeed.
    $itemFailed = false;
    $itemError  = '';
    // Set when an earlier label established that the host is not answering:
    // this one is reported un-attempted rather than costing another
    // ConnectTimeout that cannot succeed.
    $itemSkipped = $hostUnreachable;

    if ($itemSkipped) {
        $itemFailed = true;
        $itemError  = $unreachableError;
    }

    for ($q = 0; $q < $qty && !$itemSkipped; $q++) {
        $attempt    = 0;
        $printed    = false;
        $outputStr  = '';
        $cmdResult  = 0;
        while ($attempt <= $maxRetries) {
            $cmdOutput = [];
            $cmdResult = 0;
            $t0 = microtime(true);
            exec($cmd . ' 2>&1', $cmdOutput, $cmdResult);
            $elapsed = round(microtime(true) - $t0, 2);
            $outputStr = implode("\n", $cmdOutput);

            if ($cmdResult === 0) {
                $logLine = "[{$timestamp}] exit:0 | {$elapsed}s | {$mlArg} | {$title} | {$brand} | {$logIdentifier}\n{$outputStr}\n---\n";
                file_put_contents($logDir . '/print-results.log', $logLine, FILE_APPEND | LOCK_EX);
                $printed = true;
                break;
            }

            // Log every failed attempt, saying what happens next rather than
            // what the retry budget alone would suggest.
            $attemptUnreachable = printHostUnreachable($outputStr);
            $retryLabel = $attemptUnreachable
                ? ' (host unreachable, stopping)'
                : ($attempt < $maxRetries ? " (attempt " . ($attempt + 1) . "/{$maxRetries}, will retry)" : " (final attempt)");
            $logLine = "[{$timestamp}] exit:{$cmdResult} | {$elapsed}s | {$mlArg} | {$title} | {$brand} | {$logIdentifier}{$retryLabel}\ncmd: {$cmd}\n{$outputStr}\n---\n";
            file_put_contents($logDir . '/print-errors.log', $logLine, FILE_APPEND | LOCK_EX);

            // A failure to connect is not transient, and every remaining label
            // would pay the same ConnectTimeout for the same nothing.  Stop the
            // job here; the rest come back un-attempted for the operator to
            // retry once the host is back.
            if ($attemptUnreachable) {
                $hostUnreachable = true;
                break;
            }

            // Only retry on SSH transport errors (255) or general errors (1) that
            // suggest a transient connection issue rather than a print-service bug.
            if ($cmdResult !== 255 && $cmdResult !== 1) {
                break;
            }

            $attempt++;
            if ($attempt <= $maxRetries) {
                sleep($attempt); // 1s then 2s backoff
            }
        }

        if (!$printed) {
            $itemFailed = true;
            $itemError  = $outputStr;
            if ($hostUnreachable) {
                break;
            }
        }
    }

    $result = ['index' => (int) $idx, 'title' => $title, 'status' => $itemFailed ? 'error' : 'ok'];
    if ($itemFailed) {
        $result['error'] = $itemError;
    }
    // Additive, alongside status 'error' rather than replacing it: every caller
    // branches on status, and one that does not know this flag must still treat
    // an un-attempted label as not printed.
    if ($itemSkipped) {
        $result['skipped'] = true;
    }
    $results[] = $result;

    // Log the label entry
    $outcome = $itemSkipped ? 'NOT SENT' : ($itemFailed ? 'FAIL' : 'ok');
    $labelEntries .= "[{$timestamp}] {$mlArg} | {$title} | {$brand} | {$logIdentifier} | {$outcome}\n";

    // Update preferred title/brand in products table if the submitted values
    // differ from the current preferences.
    // For full-order prints, each item has its own save_edits flag (checked = persist).
    // For one-off and product prints, the global skip_persist flag is used.
    $itemSaveEdits = ($action === 'oneoff' || $action === 'product')
        ? !$skipPersist
        : !empty($item['save_edits']);
    if ($itemSaveEdits && !$isOrderLabel && $productId !== '' && ($title !== $preferredTitle || $brand !== $preferredBrand)) {
        if (in_array($productId, $allowedProductIds, true)) {
            $prefUpdateStmt->execute([$title, $brand, $productId]);
        } else {
            // Print, but do not persist: the label content came from the POST
            // either way, and refusing the whole request would turn this into a
            // printing outage the first time the frontend sends something off.
            $labelEntries .= "[{$timestamp}] {$mlArg} | {$title} | {$brand} | {$logIdentifier} | "
                           . "preference not saved: product:{$productId} is not part of this subject\n";
        }
    }
}

file_put_contents($labelLog, $labelEntries, FILE_APPEND | LOCK_EX);

// Return per-item results — never update order status here.
// ok stays true when the job was cut short: some labels really printed, and
// the caller needs the per-item results to know which.
$response = ['ok' => true, 'results' => $results];
if ($hostUnreachable) {
    $response['print_host_unreachable'] = true;
    $response['error'] = 'The label printer host stopped answering partway through.  '
                       . 'Nothing was sent after that — retry the labels below once it is back.';
}
echo json_encode($response);
