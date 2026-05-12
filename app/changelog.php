<?php
declare(strict_types=1);

/**
 * Application changelog.
 *
 * Each entry is an associative array with:
 *   version  — semver string; must match app_version in app/config.php for the
 *              "current" entry (the top of the list).
 *   date     — release date (YYYY-MM-DD) shown in the modal.
 *   title    — short headline describing the release.
 *   notes    — list of user-facing bullet points.
 *
 * Newest entries first.  When you bump app_version in app/config.php, add a
 * new entry here in the same commit so deployed users see the changes in the
 * header's release modal.
 */

return [
    [
        'version' => '1.5.1',
        'date'    => '2026-05-12',
        'title'   => 'Responsive header for narrower screens',
        'notes'   => [
            'Below 1200px wide, the top-nav links (Orders, Reports, Charts, Bundles, Clock, Time cards) collapse into a hamburger menu next to the brand.  Search, the release bell, and the user menu stay where they were.',
            'Below 700px wide, the header wraps into three rows — brand + hamburger, then the search bar full-width, then the bell + user menu — instead of hiding the user menu like before.  All text and spacing also shrink slightly at this size to reduce awkward wrapping on tight layouts.',
            'The non-production environment badge (e.g. "STAGING") now floats in the lower-left corner of the page instead of sitting in the middle of the header, so it no longer competes with the nav links for space.',
        ],
    ],
    [
        'version' => '1.5.0',
        'date'    => '2026-05-11',
        'title'   => 'Time clock and weekly time cards',
        'notes'   => [
            'New Clock page (in the nav for everyone) — Clock In / Clock Out, current shift state, this-week total, and a list of shifts in the current pay-period week.  Designed to be usable from a phone.',
            'New Time cards page (admin and root) — pick an employee and a pay-period week, view their shifts, edit or delete any punch, add a missing one, and approve the week.  Approving locks the week from further edits until an admin re-opens it.',
            'Pay-period week start is configurable via PAY_WEEK_START in env.ini.  Supports "sun" (default), "mon", and "sat".',
            'Stale-shift safety: if you forgot to clock out from a previous day, the Clock page locks you out of clock-out and tells you to ask your manager — they fix the actual time on the Time cards page instead of you guessing.',
            'Header search ("Search orders…") is now only visible to Data Entry and up, since Basic Employees don\'t have any access to orders to begin with.',
            'Deploy step: production needs `php scripts/migrate.php` to add the time_punches and timecard_approvals tables, and PAY_WEEK_START set to "sat" in env.ini for the Saturday → Friday pay cadence.',
        ],
    ],
    [
        'version' => '1.4.0',
        'date'    => '2026-05-11',
        'title'   => 'Role-based access',
        'notes'   => [
            'Every account now has a role.  Four levels, lowest to highest: Basic Employee (no app access yet — placeholder for the upcoming clock-in/out feature), Data Entry (Orders + Bundles), Admin (+ Reports, Charts, and user management), Root (+ Shopify install / re-auth).',
            'Bootstrap: the existing "root" account is now Root, "admin" is Admin, and any other existing user starts as Basic Employee.  Adjust roles on the Users page as needed.',
            'Nav links hide automatically when the signed-in user can\'t use them — Basic Employees see only Profile and Sign out in the menu.',
            'On the Users page, the Edit / Reset password / Deactivate buttons are disabled for any user whose role is higher than yours, and the role selector only offers roles at or below your own.  You can edit your own name and email but not your own role.',
            'Tightened: Shopify install / re-auth is now Root-only.  If you previously visited /install.php as the admin account, sign in as root for that flow.',
        ],
    ],
    [
        'version' => '1.3.0',
        'date'    => '2026-05-11',
        'title'   => 'Real login, profile editing, and user management',
        'notes'   => [
            'Signing in now uses a proper login form at /login.php instead of the browser\'s HTTP Basic prompt.  Sessions are cookie-based and end when you close the browser; click your name in the header to sign out.',
            'Forgot your password?  Use the link on the sign-in page — if your account has an email address on file, you\'ll receive a one-hour, single-use reset link.',
            'New Profile page (header → your name → Profile) lets you update your display name and email, and change your password without going through the reset flow.',
            'New Users page (header → your name → Users) lets you create new accounts and deactivate ones that no longer need access.  Deactivated accounts are blocked from signing in immediately, not just at session expiry.',
            'Bootstrap step: existing accounts have no email yet — sign in with your current password and visit Profile to add one before the forgot-password flow can reach you.',
            'Deploy step: production needs `composer install --no-dev` once after pulling, plus SMTP credentials in env.ini for outgoing reset emails.',
        ],
    ],
    [
        'version' => '1.2.1',
        'date'    => '2026-05-11',
        'title'   => 'Fix: API endpoints failing with duplicate getDb() declaration',
        'notes'   => [
            'Fixed a fatal error on every /api/* endpoint introduced in 1.2.0, where db.php was loaded twice and PHP refused to redeclare getDb().',
            'All includes of db.php and auth.php now use require_once so the files cannot be loaded more than once per request.',
            'Routed the print and order-detail modal fetches through apiUrl() so they work for users whose Basic Auth password contains "@".',
        ],
    ],
    [
        'version' => '1.2.0',
        'date'    => '2026-05-11',
        'title'   => 'Release notifications and per-user accounts',
        'notes'   => [
            'New bell icon in the header shows a red dot when a release you haven\'t seen yet is available.',
            'Clicking the bell opens a modal with the full changelog and marks the latest version as seen for your account.',
            'Logins are now backed by a users table in the database instead of a single shared credential in env.ini.',
            'Each user has a name and a JSON preferences blob (currently tracking the last release version seen).',
            'Run scripts/add-user.php to interactively create additional accounts.',
        ],
    ],
    [
        'version' => '1.1.0',
        'date'    => '2026-05-11',
        'title'   => 'Product profitability: ml sold',
        'notes'   => [
            'Added an "ML Sold" column to the Product Profitability report so volume can be compared alongside revenue and margin.',
        ],
    ],
    [
        'version' => '1.0.0',
        'date'    => '2026-04-23',
        'title'   => 'Initial release',
        'notes'   => [
            'Shopify orders ingested in real time via signed webhooks and stored locally in SQLite.',
            'Orders page with status filters (pending, printed, fulfilled, archived), pagination, and expandable line items.',
            'Header search modal (Ctrl/Cmd+K) for fast lookup by order number, customer name, or email.',
            'Per-order detail page with print-label workflow, including one-off labels, reprints, and ML-size variants.',
            'Print jobs delivered over SSH to a configurable label-printer host, with retry and network-error handling.',
            'Bundles management: curate which products make up a bundle, mark complete/reopen, and print a simplified two-line bundle label.',
            'Local product catalog kept in sync with Shopify, including preferred title/brand overrides for label printing.',
            'Reports section with Product Profitability and revenue-by-ml breakdowns.',
            'Charts section including Orders Per Day with fulfillment overlay and toggleable burn-rate line.',
            'Storefront password-protection toggle script for putting the shop behind a password.',
            'Non-production APP_ENV banner in the header so staging/dev deployments are visually distinct.',
        ],
    ],
];
