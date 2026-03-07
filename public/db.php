<?php
declare(strict_types=1);

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

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS orders (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            shopify_order_id   TEXT    NOT NULL UNIQUE,
            order_number       TEXT    NOT NULL,
            customer_name      TEXT    NOT NULL DEFAULT '',
            customer_email     TEXT    NOT NULL DEFAULT '',
            total_price        TEXT    NOT NULL DEFAULT '0.00',
            currency           TEXT    NOT NULL DEFAULT 'USD',
            status             TEXT    NOT NULL DEFAULT 'pending',
            raw_data           TEXT    NOT NULL,
            shopify_created_at TEXT    NOT NULL,
            received_at        TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS order_line_items (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id            INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            shopify_line_item_id TEXT   NOT NULL,
            title               TEXT    NOT NULL DEFAULT '',
            variant_title       TEXT,
            sku                 TEXT,
            vendor              TEXT,
            quantity            INTEGER NOT NULL DEFAULT 1,
            price               TEXT    NOT NULL DEFAULT '0.00',
            custom_order        TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
        CREATE INDEX IF NOT EXISTS idx_orders_created  ON orders(shopify_created_at);
        CREATE INDEX IF NOT EXISTS idx_line_items_order ON order_line_items(order_id);
    SQL);

    // Migration: add custom_order column if it was not present in earlier schema versions.
    $cols = array_column($pdo->query('PRAGMA table_info(order_line_items)')->fetchAll(), 'name');
    if (!in_array('custom_order', $cols, strict: true)) {
        $pdo->exec('ALTER TABLE order_line_items ADD COLUMN custom_order TEXT');
    }

    return $pdo;
}
