<?php
declare(strict_types=1);

/**
 * Configuration — override via environment variables in production.
 *
 * SHOPIFY_WEBHOOK_SECRET  The secret set when registering the webhook in Shopify admin.
 * AUTH_USER               Username for the orders page.
 * AUTH_PASSWORD           Password for the orders page.
 */
return [
    'db_path'        => __DIR__ . '/orders.sqlite',
    'webhook_secret' => (string) (getenv('SHOPIFY_WEBHOOK_SECRET') ?: 'your-webhook-secret-here'),
    'auth_user'      => (string) (getenv('AUTH_USER') ?: 'admin'),
    'auth_password'  => (string) (getenv('AUTH_PASSWORD') ?: 'changeme'),
];
