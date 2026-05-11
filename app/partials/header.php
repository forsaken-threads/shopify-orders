<?php
declare(strict_types=1);

/**
 * Shared page header partial.
 *
 * Set these variables before require-ing this file:
 *   string      $pageTitle  — rendered in <title>
 *   string|null $activePage — 'orders' | 'reports' | 'charts' | 'bundles' | null (highlights the active nav link)
 */

$activePage ??= null;
$hideNav    ??= false;

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Cent Notes') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Navbar ── */
        .navbar {
            background: #1a1a2e;
            padding: .875rem 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            position: relative;
        }

        /* Non-production environment indicator, centered across the navbar. */
        .navbar-env {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            color: #fbbf24;
            font-size: .95rem;
            font-weight: 700;
            letter-spacing: .12em;
            pointer-events: none;
            white-space: nowrap;
        }

        .navbar-brand {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            letter-spacing: .03em;
            flex-shrink: 0;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            list-style: none;
        }

        .nav-link {
            font-size: .85rem;
            font-weight: 500;
            color: rgba(255,255,255,.65);
            text-decoration: none;
            letter-spacing: .02em;
            transition: color .15s;
        }

        .nav-link:hover,
        .nav-link.active { color: #fff; }

        /* ── Navbar search trigger ── */
        .navbar-search {
            margin-left: auto;
            flex-shrink: 0;
        }

        .navbar-search-input {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 6px;
            padding: .4rem .75rem .4rem 2rem;
            color: rgba(255,255,255,.7);
            font-size: .82rem;
            font-family: inherit;
            width: 180px;
            cursor: pointer;
            transition: background .15s, border-color .15s;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.5)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: .6rem center;
        }

        .navbar-search-input::placeholder { color: rgba(255,255,255,.45); }
        .navbar-search-input:hover { background-color: rgba(255,255,255,.15); border-color: rgba(255,255,255,.25); }
        .navbar-search-input:focus { outline: none; }

        /* ── Search modal ── */
        .search-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: rgba(0, 0, 0, 0.55);
            padding: 10vh 1rem 1rem;
        }

        .search-overlay[hidden] { display: none; }

        .search-box {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            width: min(600px, 100%);
            display: flex;
            flex-direction: column;
            max-height: 70vh;
            overflow: hidden;
        }

        .search-input-wrap {
            display: flex;
            align-items: center;
            padding: .75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            gap: .5rem;
        }

        .search-input-wrap svg {
            flex-shrink: 0;
            width: 1.1rem;
            height: 1.1rem;
            stroke: #9ca3af;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .search-modal-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: .95rem;
            font-family: inherit;
            color: #111827;
            background: transparent;
        }

        .search-modal-input::placeholder { color: #9ca3af; }

        .search-results {
            overflow-y: auto;
            flex: 1;
        }

        .search-empty,
        .search-hint {
            padding: 2rem 1rem;
            text-align: center;
            color: #9ca3af;
            font-size: .85rem;
        }

        .search-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: 1.5rem 1rem;
            color: #9ca3af;
            font-size: .85rem;
        }

        .search-result-item {
            display: flex;
            align-items: center;
            padding: .6rem 1rem;
            text-decoration: none;
            color: #111827;
            border-bottom: 1px solid #f3f4f6;
            transition: background .1s;
            gap: .75rem;
        }

        .search-result-item:last-child { border-bottom: none; }
        .search-result-item:hover,
        .search-result-item.active { background: #f0f2f5; }

        .search-result-order {
            font-weight: 700;
            font-size: .88rem;
            min-width: 4.5rem;
        }

        .search-result-customer {
            flex: 1;
            min-width: 0;
        }

        .search-result-name {
            font-size: .85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .search-result-email {
            font-size: .75rem;
            color: #888;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .search-result-meta {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
        }

        .search-result-price {
            font-size: .82rem;
            font-variant-numeric: tabular-nums;
            color: #555;
        }

        @media (max-width: 700px) {
            .navbar-search-input { width: 120px; }
        }

        /* ── Release bell ── */
        .navbar-bell {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            margin-left: .5rem;
            border: none;
            background: transparent;
            color: rgba(255,255,255,.7);
            cursor: pointer;
            border-radius: 6px;
            flex-shrink: 0;
            transition: background .15s, color .15s;
        }

        .navbar-bell:hover { background: rgba(255,255,255,.1); color: #fff; }

        .navbar-bell svg {
            width: 1.05rem;
            height: 1.05rem;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .navbar-bell-dot {
            position: absolute;
            top: .25rem;
            right: .25rem;
            width: .55rem;
            height: .55rem;
            background: #e53e3e;
            border-radius: 50%;
            border: 2px solid #1a1a2e;
            display: none;
        }

        .navbar-bell.has-unseen .navbar-bell-dot { display: block; }

        .navbar-user {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            margin-left: -.15rem;
            padding: .3rem .55rem;
            font-size: .82rem;
            font-weight: 500;
            font-family: inherit;
            color: rgba(255,255,255,.75);
            background: transparent;
            border: none;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            cursor: pointer;
            transition: background .15s, color .15s;
        }

        .navbar-user:hover { background: rgba(255,255,255,.1); color: #fff; }

        .navbar-user-caret {
            width: .65rem;
            height: .65rem;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            opacity: .7;
        }

        .user-menu {
            position: absolute;
            top: calc(100% + .35rem);
            right: 0;
            min-width: 10rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,.18);
            padding: .35rem 0;
            z-index: 1500;
        }

        .user-menu[hidden] { display: none; }

        .user-menu a {
            display: block;
            padding: .5rem 1rem;
            font-size: .85rem;
            color: #1a1a2e;
            text-decoration: none;
            transition: background .1s;
        }

        .user-menu a:hover { background: #f0f2f5; }

        .user-menu-sep {
            height: 1px;
            background: #f0f0f0;
            margin: .3rem 0;
        }

        @media (max-width: 700px) {
            .navbar-user { display: none; }
        }

        /* ── Release modal ── */
        .release-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: rgba(0,0,0,.55);
            padding: 8vh 1rem 1rem;
        }

        .release-overlay[hidden] { display: none; }

        .release-box {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            width: min(640px, 100%);
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .release-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .release-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #1a1a2e;
        }

        .release-close {
            border: none;
            background: transparent;
            font-size: 1.25rem;
            color: #9ca3af;
            cursor: pointer;
            padding: 0 .25rem;
            line-height: 1;
        }

        .release-close:hover { color: #1a1a2e; }

        .release-body {
            overflow-y: auto;
            padding: 1rem 1.25rem 1.25rem;
        }

        .release-entry { padding: .85rem 0; border-bottom: 1px solid #f0f0f0; }
        .release-entry:last-child { border-bottom: none; }
        .release-entry.is-current { background: #fafbff; margin: 0 -1.25rem; padding: .85rem 1.25rem; }

        .release-entry-head {
            display: flex;
            align-items: baseline;
            gap: .6rem;
            margin-bottom: .35rem;
        }

        .release-entry-version {
            font-size: .9rem;
            font-weight: 700;
            color: #1a1a2e;
        }

        .release-entry-current-tag {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #1a1a2e;
            background: #fde68a;
            padding: .1em .45em;
            border-radius: 4px;
        }

        .release-entry-date { font-size: .75rem; color: #888; margin-left: auto; }
        .release-entry-title { font-size: .85rem; color: #444; margin-bottom: .35rem; }

        .release-entry-notes {
            list-style: disc;
            padding-left: 1.25rem;
            font-size: .82rem;
            color: #333;
            line-height: 1.55;
        }

        .release-entry-notes li { margin: .15rem 0; }

        .release-empty,
        .release-loading {
            padding: 2rem 1rem;
            text-align: center;
            color: #9ca3af;
            font-size: .85rem;
        }

        /* ── Main content area ── */
        .main {
            flex: 1;
            padding: 2rem;
            max-width: 85vw;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        h1 { font-size: 1.4rem; font-weight: 700; }

        .subtitle { font-size: .875rem; color: #666; }

        .badge-count {
            background: #e53e3e;
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            padding: .2em .55em;
            border-radius: 99px;
            vertical-align: middle;
        }

        /* ── Card ── */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden;
        }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; }

        thead { background: #1a1a2e; color: #fff; }

        th {
            padding: .75rem 1rem;
            text-align: left;
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
        }

        td {
            padding: .8rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            font-size: .88rem;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #fafafa; }

        .order-num { font-weight: 700; font-size: .95rem; }

        .customer-email { font-size: .78rem; color: #888; margin-top: .18rem; }

        .price { font-variant-numeric: tabular-nums; white-space: nowrap; }

        .qty { font-variant-numeric: tabular-nums; text-align: center; }

        /* ── Status badges ── */
        .status-badge {
            display: inline-block;
            padding: .25em .7em;
            border-radius: 5px;
            font-size: .75rem;
            font-weight: 600;
        }

        .status-pending   { background: #fff8e1; color: #b45309; border: 1px solid #fde68a; }
        .status-printed   { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .status-fulfilled { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .status-archived  { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* ── Buttons ── */
        .btn-download {
            display: inline-block;
            padding: .4rem 1rem;
            background: #1a1a2e;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 500;
            white-space: nowrap;
            transition: background .15s;
        }

        .btn-download:hover { background: #2d2d5e; }

        .btn-archive {
            display: inline-block;
            padding: .4rem 1rem;
            background: transparent;
            color: #888;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 500;
            white-space: nowrap;
            cursor: pointer;
            transition: background .15s, color .15s, border-color .15s;
        }

        .btn-archive:hover { background: #fff1f2; color: #b91c1c; border-color: #fca5a5; }
        .btn-archive:disabled { opacity: .45; cursor: default; }

        /* Row fades out after archiving (stays in DOM until navigation). */
        tr.archived-row td { opacity: .35; text-decoration: line-through; pointer-events: none; }

        /* ── Filter bar ── */
        .filter-bar {
            display: flex;
            gap: .5rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .filter-link {
            display: inline-flex;
            align-items: center;
            padding: .4rem 1rem;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 500;
            text-decoration: none;
            color: #555;
            background: #fff;
            border: 1px solid #e2e8f0;
            transition: background .15s, border-color .15s, color .15s;
        }

        .filter-link:hover { background: #f0f0f5; border-color: #c8d0e0; }

        .filter-link.active { background: #1a1a2e; color: #fff; border-color: #1a1a2e; }

        .filter-count {
            margin-left: .4em;
            font-size: .72rem;
            font-weight: 700;
            background: rgba(0,0,0,.12);
            padding: .1em .45em;
            border-radius: 99px;
        }

        .filter-link.active .filter-count { background: rgba(255,255,255,.2); }

        /* ── Empty state ── */
        .empty-state { padding: 4rem 2rem; text-align: center; color: #aaa; }
        .empty-state p { margin-top: .5rem; font-size: .875rem; }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-top: 1px solid #f0f0f0;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .pagination-info { font-size: .82rem; color: #666; }

        .pagination-controls { display: flex; align-items: center; gap: .35rem; }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 .6rem;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 500;
            text-decoration: none;
            color: #1a1a2e;
            border: 1px solid #e2e8f0;
            transition: background .15s, border-color .15s;
            white-space: nowrap;
        }

        .page-link:hover { background: #f0f0f5; border-color: #c8d0e0; }
        .page-link.active { background: #1a1a2e; color: #fff; border-color: #1a1a2e; cursor: default; }
        .page-link.disabled { opacity: .38; pointer-events: none; }

        .page-ellipsis { font-size: .82rem; color: #999; padding: 0 .25rem; }

        /* ── Footer ── */
        .footer { text-align: center; padding: 1.5rem; font-size: .75rem; color: #aaa; }

        @media (max-width: 700px) {
            .main { padding: 1rem; }
            .navbar { padding: .75rem 1rem; }
            .hide-mobile { display: none; }
            .pagination { justify-content: center; }
            .pagination-info { width: 100%; text-align: center; }
        }

        /* ── Accordion (shared by charts.php and reports.php) ── */
        .accordion { display: flex; flex-direction: column; gap: 1rem; }

        .accordion-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden;
        }

        .accordion-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            cursor: pointer;
            user-select: none;
            transition: background .15s;
        }

        .accordion-header:hover { background: #fafafa; }

        .accordion-header-icon {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.4rem;
            height: 2.4rem;
            background: #f0f2f5;
            border-radius: 8px;
        }

        .accordion-header-icon svg {
            width: 1.2rem;
            height: 1.2rem;
            fill: none;
            stroke: #1a1a2e;
            stroke-width: 1.75;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .accordion-header-text { flex: 1; }
        .accordion-header-text h2 { font-size: 1rem; font-weight: 700; margin-bottom: .15rem; }
        .accordion-header-text p  { font-size: .8rem; color: #888; line-height: 1.4; }

        .accordion-chevron { flex-shrink: 0; color: #aaa; transition: transform .2s ease; }

        .accordion-chevron svg {
            width: 1.1rem;
            height: 1.1rem;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            display: block;
        }

        .accordion-card.open .accordion-chevron { transform: rotate(180deg); }
        .accordion-card.open { overflow: visible; }

        .accordion-body {
            display: none;
            padding: 0 1.5rem 1.5rem;
            border-top: 1px solid #f0f0f0;
        }

        .accordion-card.open .accordion-body { display: block; }

        /* ── Shared spinner ── */
        .spinner {
            width: 1.1rem;
            height: 1.1rem;
            border: 2px solid #e2e8f0;
            border-top-color: #1a1a2e;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 480px) {
            .accordion-header { padding: 1rem; }
            .accordion-body   { padding: 0 1rem 1rem; }
        }
    </style>
</head>
<body>

<script>
/* ── Shared JS utilities (available to all pages) ──────────────────────────── */

// Timezone for displaying order dates; set via DISPLAY_TIMEZONE in env.ini.
var APP_TIMEZONE = <?= json_encode($config['display_timezone'] ?? 'America/Detroit') ?>;

// CSRF token for state-changing API requests (archive, etc.).
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

/**
 * Build an absolute URL for an API endpoint, scoped to the current origin.
 *
 * Relative fetch URLs are resolved against document.URL, which inherits any
 * userinfo (user:password@) present there.  fetch() rejects URLs with
 * embedded credentials with a synchronous TypeError, which doesn't even
 * show up as a network request.  Building against location.origin strips
 * the userinfo while keeping same-origin (and the browser still attaches
 * cached Basic Auth from the credential store).
 */
function apiUrl(path) {
    return window.location.origin + '/api/' + path;
}

/**
 * Escape a value for safe insertion into HTML.
 * Handles null/undefined gracefully.
 */
function escHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Format an ISO date string in APP_TIMEZONE.
 * Returns the original string on parse failure.
 */
(function () {
    var _fmt = new Intl.DateTimeFormat('en-US', {
        timeZone: APP_TIMEZONE,
        day:    '2-digit',
        month:  'short',
        year:   'numeric',
        hour:   '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    window.fmtDate = function (dateStr) {
        if (!dateStr) return '';
        try {
            var parts = {};
            _fmt.formatToParts(new Date(dateStr)).forEach(function (p) { parts[p.type] = p.value; });
            return parts.day + ' ' + parts.month + ' ' + parts.year + ', ' + parts.hour + ':' + parts.minute;
        } catch (e) {
            return dateStr;
        }
    };
}());

/**
 * Toggle an accordion card open/closed.
 * Called via onclick="toggleAccordion('card-id')" in charts.php and reports.php.
 */
function toggleAccordion(cardId) {
    var card   = document.getElementById(cardId);
    var isOpen = card.classList.contains('open');
    card.classList.toggle('open', !isOpen);
    card.querySelector('.accordion-header').setAttribute('aria-expanded', String(!isOpen));
}
</script>

<nav class="navbar">
    <a class="navbar-brand" href="index.php">Cent Notes</a>
    <?php $appEnv = (string) ($config['app_env'] ?? 'production'); if (strtolower($appEnv) !== 'production'): ?>
    <span class="navbar-env"><?= h(strtoupper($appEnv)) ?></span>
    <?php endif; ?>
    <?php if (!$hideNav): ?>
    <ul class="navbar-nav">
        <li><a class="nav-link<?= $activePage === 'orders'  ? ' active' : '' ?>" href="orders.php">Orders</a></li>
        <li><a class="nav-link<?= $activePage === 'reports' ? ' active' : '' ?>" href="reports.php">Reports</a></li>
        <li><a class="nav-link<?= $activePage === 'charts'  ? ' active' : '' ?>" href="charts.php">Charts</a></li>
        <li><a class="nav-link<?= $activePage === 'bundles' ? ' active' : '' ?>" href="bundles.php">Bundles</a></li>
    </ul>
    <div class="navbar-search">
        <input type="text" class="navbar-search-input" id="navbar-search-trigger" placeholder="Search orders…" readonly>
    </div>
    <?php
    $appVersion       = (string) ($config['app_version'] ?? '');
    $sessionUser      = $_SESSION['user'] ?? null;
    $lastVersionSeen  = (string) ($sessionUser['preferences']['last_version_seen'] ?? '');
    $hasUnseenRelease = $appVersion !== '' && $appVersion !== $lastVersionSeen;
    ?>
    <button type="button"
            class="navbar-bell<?= $hasUnseenRelease ? ' has-unseen' : '' ?>"
            id="navbar-bell"
            aria-label="What's new"
            title="What's new">
        <svg viewBox="0 0 24 24">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        </svg>
        <span class="navbar-bell-dot"></span>
    </button>
    <?php $userName = (string) ($sessionUser['name'] ?? ''); if ($userName !== ''): ?>
    <button type="button" class="navbar-user" id="navbar-user-trigger"
            aria-haspopup="menu" aria-expanded="false" title="<?= h($userName) ?>">
        <span><?= h($userName) ?></span>
        <svg class="navbar-user-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div class="user-menu" id="navbar-user-menu" role="menu" hidden>
        <a href="profile.php" role="menuitem">Profile</a>
        <a href="users.php"   role="menuitem">Users</a>
        <div class="user-menu-sep"></div>
        <a href="logout.php"  role="menuitem">Sign out</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</nav>

<?php if (!$hideNav): ?>
<script>
(function () {
    'use strict';
    var trigger = document.getElementById('navbar-user-trigger');
    var menu    = document.getElementById('navbar-user-menu');
    if (!trigger || !menu) return;

    function close() {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }
    function open() {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
    }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        if (menu.hidden) open(); else close();
    });
    document.addEventListener('click', function (e) {
        if (!menu.hidden && !menu.contains(e.target) && e.target !== trigger) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) close();
    });
}());
</script>
<?php endif; ?>

<?php if (!$hideNav): ?>
<!-- ── Search Modal ────────────────────────────────────────────────────── -->
<div id="search-modal" class="search-overlay" hidden>
    <div class="search-box">
        <div class="search-input-wrap">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-modal-input" id="search-modal-input" placeholder="Search by order #, customer name, or email…" autocomplete="off">
        </div>
        <div class="search-results" id="search-results">
            <div class="search-hint">Start typing to search orders…</div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var trigger     = document.getElementById('navbar-search-trigger');
    var overlay     = document.getElementById('search-modal');
    var input       = document.getElementById('search-modal-input');
    var resultsEl   = document.getElementById('search-results');
    var debounceId  = null;
    var activeIndex = -1;
    var resultItems = [];

    if (!trigger || !overlay) return;

    function openSearch() {
        overlay.hidden = false;
        input.value = '';
        resultsEl.innerHTML = '<div class="search-hint">Start typing to search orders…</div>';
        activeIndex = -1;
        resultItems = [];
        setTimeout(function () { input.focus(); }, 50);
    }

    function closeSearch() {
        overlay.hidden = true;
        input.value = '';
        activeIndex = -1;
        resultItems = [];
    }

    trigger.addEventListener('click', openSearch);
    trigger.addEventListener('focus', function (e) { e.preventDefault(); openSearch(); });

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeSearch();
    });

    document.addEventListener('keydown', function (e) {
        // Ctrl/Cmd+K to open search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (overlay.hidden) openSearch(); else closeSearch();
            return;
        }
        if (e.key === 'Escape' && !overlay.hidden) {
            closeSearch();
            return;
        }
        if (overlay.hidden) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (resultItems.length > 0) {
                activeIndex = (activeIndex + 1) % resultItems.length;
                highlightResult();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (resultItems.length > 0) {
                activeIndex = activeIndex <= 0 ? resultItems.length - 1 : activeIndex - 1;
                highlightResult();
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && resultItems[activeIndex]) {
                window.location.href = resultItems[activeIndex].href;
            }
        }
    });

    function highlightResult() {
        var links = resultsEl.querySelectorAll('.search-result-item');
        links.forEach(function (el, i) {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) el.scrollIntoView({ block: 'nearest' });
        });
    }

    function statusBadgeHtml(status) {
        var cls = {
            pending:   'status-pending',
            printed:   'status-printed',
            fulfilled: 'status-fulfilled',
            archived:  'status-archived',
        }[status] || 'status-pending';
        var label = status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="status-badge ' + cls + '">' + escHtml(label) + '</span>';
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(debounceId);
        activeIndex = -1;
        resultItems = [];

        if (q.length < 2) {
            resultsEl.innerHTML = '<div class="search-hint">Start typing to search orders…</div>';
            return;
        }

        resultsEl.innerHTML = '<div class="search-loading"><div class="spinner"></div> Searching…</div>';

        debounceId = setTimeout(function () {
            fetch(apiUrl('order-search.php?q=' + encodeURIComponent(q)))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.length === 0) {
                        resultsEl.innerHTML = '<div class="search-empty">No orders found.</div>';
                        resultItems = [];
                        return;
                    }

                    var html = '';
                    resultItems = [];
                    data.forEach(function (order) {
                        var href = 'order.php?id=' + order.id;
                        resultItems.push({ href: href });
                        html += '<a class="search-result-item" href="' + escHtml(href) + '">' +
                            '<span class="search-result-order">' + escHtml(order.order_number) + '</span>' +
                            '<span class="search-result-customer">' +
                                '<div class="search-result-name">' + escHtml(order.customer_name) + '</div>' +
                                '<div class="search-result-email">' + escHtml(order.customer_email) + '</div>' +
                            '</span>' +
                            '<span class="search-result-meta">' +
                                '<span class="search-result-price">' + escHtml(order.currency) + ' ' + Number(order.total_price).toFixed(2) + '</span>' +
                                statusBadgeHtml(order.status) +
                            '</span>' +
                            '</a>';
                    });
                    resultsEl.innerHTML = html;
                })
                .catch(function () {
                    resultsEl.innerHTML = '<div class="search-empty">Search failed. Please try again.</div>';
                    resultItems = [];
                });
        }, 250);
    });
}());
</script>

<!-- ── Release / Changelog Modal ───────────────────────────────────────── -->
<div id="release-modal" class="release-overlay" hidden>
    <div class="release-box">
        <div class="release-header">
            <h2>What's new</h2>
            <button type="button" class="release-close" id="release-close" aria-label="Close">&times;</button>
        </div>
        <div class="release-body" id="release-body">
            <div class="release-loading"><div class="spinner"></div></div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var bell      = document.getElementById('navbar-bell');
    var overlay   = document.getElementById('release-modal');
    var closeBtn  = document.getElementById('release-close');
    var bodyEl    = document.getElementById('release-body');
    var APP_VERSION = <?= json_encode($appVersion) ?>;
    var loaded    = false;

    if (!bell || !overlay) return;

    function openModal() {
        overlay.hidden = false;
        if (!loaded) {
            loadChangelog();
        }
        markSeen();
    }

    function closeModal() {
        overlay.hidden = true;
    }

    function loadChangelog() {
        fetch(apiUrl('changelog.php'), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                loaded = true;
                renderChangelog(data);
            })
            .catch(function () {
                bodyEl.innerHTML = '<div class="release-empty">Failed to load changelog.</div>';
            });
    }

    function renderChangelog(data) {
        var entries = (data && data.entries) || [];
        if (entries.length === 0) {
            bodyEl.innerHTML = '<div class="release-empty">No release notes yet.</div>';
            return;
        }
        var current = data.current_version || '';
        var html = '';
        entries.forEach(function (entry) {
            var isCurrent = entry.version === current;
            html += '<div class="release-entry' + (isCurrent ? ' is-current' : '') + '">';
            html += '<div class="release-entry-head">';
            html += '<span class="release-entry-version">v' + escHtml(entry.version) + '</span>';
            if (isCurrent) {
                html += '<span class="release-entry-current-tag">Current</span>';
            }
            html += '<span class="release-entry-date">' + escHtml(entry.date || '') + '</span>';
            html += '</div>';
            if (entry.title) {
                html += '<div class="release-entry-title">' + escHtml(entry.title) + '</div>';
            }
            if (Array.isArray(entry.notes) && entry.notes.length) {
                html += '<ul class="release-entry-notes">';
                entry.notes.forEach(function (note) {
                    html += '<li>' + escHtml(note) + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
        });
        bodyEl.innerHTML = html;
    }

    function markSeen() {
        if (!bell.classList.contains('has-unseen') || !APP_VERSION) return;
        var body = 'version=' + encodeURIComponent(APP_VERSION);
        fetch(apiUrl('mark-version-seen.php'), {
            method:  'POST',
            headers: {
                'Content-Type':  'application/x-www-form-urlencoded',
                'X-CSRF-Token':  CSRF_TOKEN,
            },
            body: body,
        }).then(function () {
            bell.classList.remove('has-unseen');
        }).catch(function () {
            // Leave badge alone; user will see it again next page load.
        });
    }

    bell.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeModal();
    });
}());
</script>
<?php endif; ?>
