<?php
declare(strict_types=1);

/**
 * Shared page header partial.
 *
 * Set these variables before require-ing this file:
 *   string      $pageTitle  — rendered in <title>
 *   string|null $activePage — 'orders' | 'reports' | null (highlights the active nav link)
 */

$activePage ??= null;

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Utility App') ?></title>
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
    <a class="navbar-brand" href="index.php">Utility App</a>
    <ul class="navbar-nav">
        <li><a class="nav-link<?= $activePage === 'orders'  ? ' active' : '' ?>" href="orders.php">Orders</a></li>
        <li><a class="nav-link<?= $activePage === 'reports' ? ' active' : '' ?>" href="reports.php">Reports</a></li>
        <li><a class="nav-link<?= $activePage === 'charts'  ? ' active' : '' ?>" href="charts.php">Charts</a></li>
    </ul>
</nav>
