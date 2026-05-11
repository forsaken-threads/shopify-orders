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
