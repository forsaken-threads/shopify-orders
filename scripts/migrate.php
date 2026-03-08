#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Database migration script — creates the schema from scratch.
 *
 * Run once before starting the application (or after wiping the database):
 *   php scripts/migrate.php
 *
 * This script is intentionally separate from the web application so that
 * schema creation never happens implicitly on every request.
 */

$projectRoot = dirname(__DIR__);
$config      = require $projectRoot . '/public/config.php';

$pdo = new PDO(
    dsn: 'sqlite:' . $config['db_path'],
    options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS orders (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        shopify_order_id   TEXT    NOT NULL UNIQUE,
        order_number       TEXT    NOT NULL,
        customer_name      TEXT    NOT NULL DEFAULT '',
        customer_email     TEXT    NOT NULL DEFAULT '',
        total_price        REAL    NOT NULL DEFAULT 0.0,
        currency           TEXT    NOT NULL DEFAULT 'USD',
        status             TEXT    NOT NULL DEFAULT 'pending',
        raw_data           TEXT    NOT NULL,
        shopify_created_at TEXT    NOT NULL,
        received_at        TEXT    NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS order_line_items (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id             INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        shopify_line_item_id TEXT    NOT NULL,
        shopify_product_id   TEXT,
        title                TEXT    NOT NULL DEFAULT '',
        variant_title        TEXT,
        variant_ml           INTEGER,
        sku                  TEXT,
        vendor               TEXT,
        quantity             INTEGER NOT NULL DEFAULT 1,
        price                REAL    NOT NULL DEFAULT 0.0,
        custom_brand         TEXT
    );

    CREATE INDEX IF NOT EXISTS idx_orders_status    ON orders(status);
    CREATE INDEX IF NOT EXISTS idx_orders_created   ON orders(shopify_created_at);
    CREATE INDEX IF NOT EXISTS idx_line_items_order ON order_line_items(order_id);
SQL);

echo "Migration complete.\n";
