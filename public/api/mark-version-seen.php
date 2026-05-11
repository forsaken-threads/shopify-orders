<?php
declare(strict_types=1);

/**
 * Updates the authenticated user's preferences.last_version_seen.
 *
 * POST /api/mark-version-seen.php
 * Body (application/x-www-form-urlencoded): version=<string>
 * Header: X-CSRF-Token: <token>
 *
 * Returns JSON {ok:true} on success.  The caller passes the version it
 * actually displayed (typically the current app version), so a stale tab
 * marking an older version doesn't clear the badge for a newer release.
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../auth.php';

$user = requireBasicAuth($config);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken  = $_SESSION['csrf_token']        ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

$version = trim((string) ($_POST['version'] ?? ''));
if ($version === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing version.']);
    exit;
}

$db   = getDb($config);
$prefs = $user['preferences'];
$prefs['last_version_seen'] = $version;
$encoded = json_encode($prefs, JSON_UNESCAPED_SLASHES);

$stmt = $db->prepare("UPDATE users SET preferences = ?, updated_at = datetime('now') WHERE id = ?");
$stmt->execute([$encoded, $user['id']]);

$_SESSION['user']['preferences'] = $prefs;

echo json_encode(['ok' => true]);
