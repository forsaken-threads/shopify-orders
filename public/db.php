<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection to the SQLite database.
 *
 * Schema creation is handled separately by scripts/migrate.php.
 * Run that script once before starting the application.
 */
function getDb(array $config): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new PDO(
        dsn: 'sqlite:' . $config['db_path'],
        options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Enable WAL mode for better concurrent read/write performance.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}
