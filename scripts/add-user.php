#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Interactive script for adding a new user to the users table.
 *
 *   php scripts/add-user.php
 *
 * Prompts for username, password (hidden, with confirmation), and display
 * name.  Refuses to overwrite an existing username — delete the row by hand
 * if you really need to replace one.
 *
 * Must be run from a TTY.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$config      = require $projectRoot . '/app/config.php';
require $projectRoot . '/app/db.php';

$db = getDb($config);

// Verify the users table exists; if not, tell the operator to run the
// migration first.  Running add-user.php before migrate.php would otherwise
// fail with a confusing SQL error.
$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
if (!$check) {
    fwrite(STDERR, "users table does not exist. Run scripts/migrate.php first.\n");
    exit(1);
}

/**
 * Prompt on stdout, read a line from stdin, return the trimmed result.
 */
function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    if ($line === false) {
        fwrite(STDERR, "\nInput closed.\n");
        exit(1);
    }
    return trim($line);
}

/**
 * Read a password without echoing it.  Falls back to a visible read if
 * stty isn't available (e.g. on Windows).
 */
function askPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    $sttyAvailable = false;
    $oldStty       = '';
    if (DIRECTORY_SEPARATOR !== '\\') {
        $oldStty = (string) shell_exec('stty -g 2>/dev/null');
        if ($oldStty !== '') {
            shell_exec('stty -echo');
            $sttyAvailable = true;
        }
    }

    $line = fgets(STDIN);

    if ($sttyAvailable) {
        shell_exec('stty ' . escapeshellarg(trim($oldStty)));
        fwrite(STDOUT, "\n");
    }

    if ($line === false) {
        fwrite(STDERR, "\nInput closed.\n");
        exit(1);
    }
    return rtrim($line, "\r\n");
}

// ── Username ─────────────────────────────────────────────────────────────────
$username = '';
while ($username === '') {
    $username = ask('Username: ');
    if ($username === '') {
        fwrite(STDOUT, "Username cannot be empty.\n");
        continue;
    }
    $stmt = $db->prepare("SELECT 1 FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        fwrite(STDOUT, "A user named '{$username}' already exists.\n");
        $username = '';
    }
}

// ── Display name ─────────────────────────────────────────────────────────────
$name = ask('Full name: ');

// ── Password ─────────────────────────────────────────────────────────────────
$password = '';
while ($password === '') {
    $password = askPassword('Password: ');
    if ($password === '') {
        fwrite(STDOUT, "Password cannot be empty.\n");
        continue;
    }
    if (strlen($password) < 8) {
        fwrite(STDOUT, "Password must be at least 8 characters.\n");
        $password = '';
        continue;
    }
    $confirm = askPassword('Confirm password: ');
    if (!hash_equals($password, $confirm)) {
        fwrite(STDOUT, "Passwords did not match. Try again.\n");
        $password = '';
    }
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$insert = $db->prepare(
    "INSERT INTO users (username, password_hash, name, preferences)
     VALUES (?, ?, ?, '{}')"
);
$insert->execute([$username, $hash, $name]);

echo "Created user '{$username}'" . ($name !== '' ? " ({$name})" : '') . ".\n";
