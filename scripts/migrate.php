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

// ── Orders ────────────────────────────────────────────────────────────────────

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

// ── Products ──────────────────────────────────────────────────────────────────
//
// Local cache of Shopify products.  Populated by scripts/sync-products.php and
// kept up-to-date by the products webhook (public/webhooks/products.php).
//
// is_bundle = 1 when the product title ends with the word "bundle"
//             (case-insensitive).  Used to identify bundle products that have
//             component relationships tracked in bundle_components.
//
// Draft products are ignored and never stored here.

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS products (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        shopify_product_id TEXT    NOT NULL UNIQUE,
        title              TEXT    NOT NULL DEFAULT '',
        vendor             TEXT,
        status             TEXT    NOT NULL DEFAULT 'active',
        custom_brand       TEXT,
        is_bundle          INTEGER NOT NULL DEFAULT 0,
        raw_data           TEXT    NOT NULL DEFAULT '',
        shopify_created_at TEXT,
        synced_at          TEXT    NOT NULL DEFAULT (datetime('now'))
    );

    -- Join table that records which products are components of a bundle product.
    -- Populated separately; the migration creates the structure for future use.
    CREATE TABLE IF NOT EXISTS bundle_components (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        bundle_product_id    INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        component_product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        UNIQUE(bundle_product_id, component_product_id)
    );

    CREATE INDEX IF NOT EXISTS idx_products_shopify_id     ON products(shopify_product_id);
    CREATE INDEX IF NOT EXISTS idx_products_is_bundle      ON products(is_bundle);
    CREATE INDEX IF NOT EXISTS idx_bundle_components_bundle ON bundle_components(bundle_product_id);
SQL);

echo "Migration complete.\n";
