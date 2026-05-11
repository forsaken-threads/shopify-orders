<?php
declare(strict_types=1);

/**
 * Returns the application changelog as JSON.
 *
 * GET /api/changelog.php
 *
 * Response: { current_version: "x.y.z", entries: [ {version,date,title,notes[]}, ... ] }
 *
 * Served separately so the changelog payload doesn't bloat every page render.
 */

$config = require __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../auth.php';

requireBasicAuth($config);

header('Content-Type: application/json');

$entries = require __DIR__ . '/../../app/changelog.php';

echo json_encode([
    'current_version' => (string) ($config['app_version'] ?? ''),
    'entries'         => $entries,
]);
