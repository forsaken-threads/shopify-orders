<?php
declare(strict_types=1);

/**
 * Returns the application changelog as JSON.
 *
 * GET /api/changelog.php?since=<version>
 *
 * Response: { current_version: "x.y.z",
 *             entries: [ {version,date,title,notes[],is_new}, ... ] }
 *
 * `since` is the caller's last_version_seen as the page was rendered, not as
 * it stands now: opening the modal fires mark-version-seen in parallel with
 * this request, so reading the stored value here would race it and could clear
 * the very marks this response exists to set.  An absent or empty `since` is a
 * user with no baseline to be new against, and nothing is marked.
 *
 * Served separately so the changelog payload doesn't bloat every page render.
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../auth.php';

requireApiLogin($config);

header('Content-Type: application/json');

$entries = require __DIR__ . '/../../app/changelog.php';
$since   = trim((string) ($_GET['since'] ?? ''));

foreach ($entries as &$entry) {
    $entry['is_new'] = $since !== ''
        && version_compare((string) $entry['version'], $since, '>');
}
unset($entry);

echo json_encode([
    'current_version' => (string) ($config['app_version'] ?? ''),
    'entries'         => $entries,
]);
