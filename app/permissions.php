<?php
declare(strict_types=1);

/**
 * Role-based permissions.
 *
 * Four additive roles, lowest to highest rank: basic_employee, data_entry,
 * admin, root.  Each role inherits everything the role below it can do,
 * plus its own additions.
 *
 *   basic_employee  — clock_in_out (placeholder for a future feature)
 *   data_entry      — + orders, bundles
 *   admin           — + reports, charts, manage_users
 *   root            — + shopify_install
 *
 * The PERMISSIONS_BY_ROLE map is the single source of truth: page and API
 * gates call userCan() / requirePermission() / requireApiPermission()
 * against a permission string and don't hard-code role names.  Adding a
 * new permission means editing one map.
 *
 * Role rank (ROLE_RANK) is a separate constant used for "can role A
 * assign / edit role B?" comparisons in the user-management UI, which
 * are hierarchy-based rather than permission-based.
 *
 * This file requires auth.php — the gate helpers compose on top of
 * requireLogin() / requireApiLogin().
 */

require_once __DIR__ . '/../public/auth.php';

const ROLES = ['basic_employee', 'data_entry', 'admin', 'root'];

const ROLE_LABELS = [
    'basic_employee' => 'Basic Employee',
    'data_entry'     => 'Data Entry',
    'admin'          => 'Admin',
    'root'           => 'Root',
];

const ROLE_RANK = [
    'basic_employee' => 1,
    'data_entry'     => 2,
    'admin'          => 3,
    'root'           => 4,
];

const PERMISSIONS_BY_ROLE = [
    'basic_employee' => ['clock_in_out'],
    'data_entry'     => ['clock_in_out', 'orders', 'bundles'],
    'admin'          => ['clock_in_out', 'orders', 'bundles', 'reports', 'charts', 'manage_users', 'manage_timecards'],
    'root'           => ['clock_in_out', 'orders', 'bundles', 'reports', 'charts', 'manage_users', 'manage_timecards', 'shopify_install'],
];

/**
 * Returns true if the user's role grants the requested permission.  Falls
 * back to basic_employee for users with a missing or unrecognised role,
 * which is the least-privileged option — fail closed.
 */
function userCan(array $user, string $permission): bool
{
    $role  = (string) ($user['role'] ?? 'basic_employee');
    $perms = PERMISSIONS_BY_ROLE[$role] ?? PERMISSIONS_BY_ROLE['basic_employee'];
    return in_array($permission, $perms, true);
}

/**
 * Numeric rank of the user's role.  Used for hierarchy checks like
 * "can this user assign this role?" and "can this user edit that user?".
 * Returns 0 for an unrecognised role so any rank comparison fails closed.
 */
function userRoleRank(array $user): int
{
    return ROLE_RANK[(string) ($user['role'] ?? '')] ?? 0;
}

function roleRank(string $role): int
{
    return ROLE_RANK[$role] ?? 0;
}

/**
 * Browser-facing gate.  Bounces to /index.php with no flash message when
 * the user is signed in but lacking the permission — avoids confirming
 * that a feature they can't access even exists.  Unauthenticated requests
 * are handled by the inner requireLogin() (redirect to /login.php).
 */
function requirePermission(array $config, string $permission): array
{
    $user = requireLogin($config);
    if (userCan($user, $permission)) {
        return $user;
    }
    header('Location: /index.php');
    exit;
}

/**
 * API-facing gate.  Returns the user array on success; emits 403 JSON
 * when authenticated but lacking the permission, or 401 JSON via the
 * inner requireApiLogin() when not authenticated.
 */
function requireApiPermission(array $config, string $permission): array
{
    $user = requireApiLogin($config);
    if (userCan($user, $permission)) {
        return $user;
    }
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'forbidden']);
    exit;
}
