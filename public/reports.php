<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';

requirePermission($config, 'reports');

$pageTitle  = 'Reports - Cent Notes';
$activePage = 'reports';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    /* ── Reports page layout ── */
    .reports-wrap {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    /* ── Search box ── */
    .search-wrap {
        position: relative;
        max-width: 480px;
        margin-top: 1.25rem;
    }

    .search-input-row {
        position: relative;
        display: flex;
        align-items: center;
    }

    .search-icon {
        position: absolute;
        left: .8rem;
        color: #aaa;
        pointer-events: none;
    }

    .search-icon svg {
        width: 1rem;
        height: 1rem;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
        display: block;
    }

    .search-input {
        width: 100%;
        padding: .65rem .9rem .65rem 2.4rem;
        font-size: .88rem;
        border: 1px solid #d1d5db;
        border-radius: 7px;
        outline: none;
        font-family: inherit;
        color: #1a1a2e;
        background: #fff;
        transition: border-color .15s, box-shadow .15s;
    }

    .search-input:focus {
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .search-input::placeholder { color: #bbb; }

    .search-clear {
        position: absolute;
        right: .7rem;
        background: none;
        border: none;
        cursor: pointer;
        color: #aaa;
        padding: .2rem;
        line-height: 0;
        display: none;
        transition: color .15s;
    }

    .search-clear:hover { color: #555; }

    .search-clear svg {
        width: .9rem;
        height: .9rem;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.5;
        stroke-linecap: round;
        stroke-linejoin: round;
        display: block;
    }

    /* ── Autocomplete dropdown ── */
    .search-dropdown {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,.10);
        z-index: 100;
        overflow: hidden;
        display: none;
    }

    .search-dropdown.visible { display: block; }

    .dropdown-item {
        padding: .65rem 1rem;
        cursor: pointer;
        transition: background .1s;
        border-bottom: 1px solid #f5f5f5;
    }

    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-item:hover { background: #f7f8fb; }
    .dropdown-item.focused { background: #f0f2f5; }

    .dropdown-item-title {
        font-size: .875rem;
        font-weight: 600;
        color: #1a1a2e;
    }

    .dropdown-item-vendor {
        font-size: .75rem;
        color: #888;
        margin-top: .1rem;
    }

    .dropdown-empty {
        padding: .85rem 1rem;
        font-size: .85rem;
        color: #aaa;
        text-align: center;
    }

    /* ── Results area ── */
    .results-area {
        margin-top: 1.5rem;
        display: none;
    }

    .results-area.visible { display: block; }

    .results-product-header {
        display: flex;
        align-items: baseline;
        gap: .75rem;
        margin-bottom: 1.25rem;
    }

    .results-product-name {
        font-size: 1.05rem;
        font-weight: 700;
    }

    .results-product-vendor {
        font-size: .8rem;
        color: #888;
    }

    .results-clear-btn {
        margin-left: auto;
        background: none;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        font-size: .75rem;
        color: #888;
        cursor: pointer;
        padding: .25rem .65rem;
        font-family: inherit;
        transition: border-color .15s, color .15s;
    }

    .results-clear-btn:hover { border-color: #aab; color: #444; }

    /* ── Summary row ── */
    .summary-group-row {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .summary-group { flex: 1 1 auto; }

    .summary-group-label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #1a1a2e;
        font-weight: 700;
        margin-bottom: .45rem;
    }

    .summary-pills {
        display: flex;
        gap: .75rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

    .summary-group .summary-pills { margin-bottom: 0; }

    .summary-group-fulfilled .summary-pill { background: #eef0fb; }

    .summary-pill {
        background: #f0f2f5;
        border-radius: 8px;
        padding: .65rem 1.1rem;
        min-width: 120px;
    }

    .summary-pill-label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #888;
        font-weight: 600;
        margin-bottom: .25rem;
    }

    .summary-pill-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1a1a2e;
        font-variant-numeric: tabular-nums;
    }

    /* ── Variant table ── */
    .variant-table-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }

    .variant-table {
        width: 100%;
        border-collapse: collapse;
    }

    .variant-table thead { background: #1a1a2e; color: #fff; }

    .variant-table th {
        padding: .65rem 1rem;
        text-align: left;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        white-space: nowrap;
    }

    .variant-table th:not(:first-child) { text-align: right; }
    .variant-table th.col-group { text-align: center; }

    /* ── Fulfilled column group: subtle tint + a divider on the group edge ── */
    .variant-table .col-group-fulfilled { background: rgba(99, 102, 241, .06); }
    .variant-table thead .col-group-fulfilled { background: rgba(255, 255, 255, .08); }
    .variant-table .col-divide { border-left: 1px solid #e2e8f0; }
    .variant-table thead .col-divide { border-left: 1px solid rgba(255, 255, 255, .25); }

    .variant-table td {
        padding: .7rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .875rem;
        font-variant-numeric: tabular-nums;
    }

    .variant-table td:not(:first-child) { text-align: right; }

    .variant-table tbody tr:last-child td { border-bottom: none; }
    .variant-table tbody tr:hover td { background: #fafafa; }

    .variant-table tfoot td {
        padding: .7rem 1rem;
        font-size: .875rem;
        font-weight: 700;
        background: #f7f8fb;
        border-top: 2px solid #e2e8f0;
        font-variant-numeric: tabular-nums;
    }

    .variant-table tfoot td:not(:first-child) { text-align: right; }

    .pct-bar-wrap {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: .5rem;
    }

    .pct-bar {
        display: inline-block;
        height: 6px;
        border-radius: 3px;
        background: #1a1a2e;
        opacity: .18;
        min-width: 4px;
    }

    .pct-text { font-size: .8rem; color: #666; min-width: 3.5ch; text-align: right; }

    /* ── Loading / error states ── */
    .lookup-loading {
        display: none;
        align-items: center;
        gap: .6rem;
        padding: 1.25rem 0;
        font-size: .875rem;
        color: #888;
    }

    .lookup-loading.visible { display: flex; }

    .lookup-error {
        display: none;
        padding: .8rem 1rem;
        background: #fff5f5;
        border: 1px solid #fca5a5;
        border-radius: 7px;
        color: #b91c1c;
        font-size: .85rem;
        margin-top: 1rem;
    }

    .lookup-error.visible { display: block; }

    .source-note {
        font-size: .72rem;
        color: #aaa;
        margin-top: .75rem;
    }

    @media (max-width: 700px) {
        .reports-wrap { padding: 1rem; }
        .search-wrap { max-width: 100%; }
        .summary-pill { min-width: 100px; }
    }

    /* ── Top Customers card ── */
    .tc-controls {
        display: flex;
        align-items: center;
        gap: .6rem;
        flex-wrap: wrap;
        margin-top: 1.25rem;
    }

    .tc-control-label { font-size: .85rem; color: #555; }

    .tc-limit-input {
        width: 5rem;
        padding: .5rem .6rem;
        font-size: .88rem;
        font-family: inherit;
        border: 1px solid #d1d5db;
        border-radius: 7px;
        color: #1a1a2e;
        background: #fff;
    }

    .tc-limit-input:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .tc-period-select {
        padding: .5rem .6rem;
        font-size: .88rem;
        font-family: inherit;
        border: 1px solid #d1d5db;
        border-radius: 7px;
        color: #1a1a2e;
        background: #fff;
        cursor: pointer;
    }

    .tc-period-select:focus {
        outline: none;
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .tc-load-btn {
        padding: .5rem 1.25rem;
        background: #1a1a2e;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s;
    }

    .tc-load-btn:hover { background: #2d2d5e; }
    .tc-load-btn:disabled { opacity: .5; cursor: default; }

    .tc-results-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .tc-results-count { font-size: .85rem; color: #555; font-weight: 600; }

    .tc-table-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: auto;
    }

    .tc-table { width: 100%; border-collapse: collapse; }

    .tc-table thead { background: #1a1a2e; color: #fff; }

    .tc-table th {
        padding: .6rem 1rem;
        text-align: left;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
        white-space: nowrap;
    }

    .tc-table th.tc-col-num { text-align: right; }

    .tc-table td {
        padding: .6rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .85rem;
        vertical-align: top;
    }

    .tc-table tbody tr:last-child td { border-bottom: none; }
    .tc-table tbody tr:hover td { background: #fafafa; }

    .tc-col-rank { width: 2.5rem; text-align: right; color: #888; font-variant-numeric: tabular-nums; }
    .tc-col-num  { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

    .tc-cust-name  { font-weight: 600; color: #1a1a2e; }
    .tc-cust-email { font-size: .75rem; color: #888; margin-top: .15rem; }
    .tc-addr       { color: #444; line-height: 1.45; }
    .tc-addr-missing { color: #c0392b; font-style: italic; }

    .tc-modes {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        margin-top: 1.25rem;
    }

    .tc-modes .filter-link { font-family: inherit; cursor: pointer; }

    .tc-mode-note {
        font-size: .8rem;
        color: #666;
        margin-top: .85rem;
        line-height: 1.45;
    }

    .tc-mode-note:empty { display: none; }

    /* Highlight the column the active mode ranks by. */
    .tc-table.mode-spend   th.col-spent,
    .tc-table.mode-items   th.col-items,
    .tc-table.mode-peritem th.col-peritem  { background: #2d2d5e; }

    .tc-table.mode-spend   td.col-spent,
    .tc-table.mode-items   td.col-items,
    .tc-table.mode-peritem td.col-peritem  { background: #f5f7ff; font-weight: 700; color: #1a1a2e; }

</style>

<div class="reports-wrap">
    <div class="page-header">
        <h1>Reports</h1>
        <span class="subtitle">Quick lookups and analytics</span>
    </div>

    <div class="accordion" id="accordion">

        <!-- ── Card 1: Product Profitability ── -->
        <div class="accordion-card" id="card-product-profitability">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-product-profitability"
                 onclick="toggleAccordion('card-product-profitability')">
                <div class="accordion-header-icon">
                    <!-- bar-chart icon -->
                    <svg viewBox="0 0 24 24">
                        <rect x="3"  y="12" width="4" height="9"/>
                        <rect x="10" y="7"  width="4" height="14"/>
                        <rect x="17" y="3"  width="4" height="18"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Product Profitability</h2>
                    <p>Total sales and per-variant revenue for any product.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-product-profitability">

                <!-- Search input -->
                <div class="search-wrap" id="pp-search-wrap">
                    <div class="search-input-row">
                        <span class="search-icon">
                            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input
                            type="text"
                            class="search-input"
                            id="pp-search-input"
                            placeholder="Search products by name…"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button class="search-clear" id="pp-search-clear" tabindex="-1" aria-label="Clear search">
                            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="search-dropdown" id="pp-dropdown" role="listbox"></div>
                </div>

                <!-- Loading -->
                <div class="lookup-loading" id="pp-loading">
                    <div class="spinner"></div>
                    Fetching sales data…
                </div>

                <!-- Error -->
                <div class="lookup-error" id="pp-error"></div>

                <!-- Results -->
                <div class="results-area" id="pp-results">
                    <div class="results-product-header">
                        <span class="results-product-name" id="pp-product-name"></span>
                        <span class="results-product-vendor" id="pp-product-vendor"></span>
                        <button class="results-clear-btn" onclick="clearProfitability()">Clear</button>
                    </div>

                    <div class="summary-group-row">
                        <div class="summary-group">
                            <div class="summary-group-label">All Orders</div>
                            <div class="summary-pills">
                                <div class="summary-pill">
                                    <div class="summary-pill-label">Total Units Sold</div>
                                    <div class="summary-pill-value" id="pp-total-units">—</div>
                                </div>
                                <div class="summary-pill">
                                    <div class="summary-pill-label">Total Revenue</div>
                                    <div class="summary-pill-value" id="pp-total-revenue">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="summary-group summary-group-fulfilled">
                            <div class="summary-group-label">Fulfilled</div>
                            <div class="summary-pills">
                                <div class="summary-pill">
                                    <div class="summary-pill-label">Units Fulfilled</div>
                                    <div class="summary-pill-value" id="pp-fulfilled-units">—</div>
                                </div>
                                <div class="summary-pill">
                                    <div class="summary-pill-label">Fulfilled Revenue</div>
                                    <div class="summary-pill-value" id="pp-fulfilled-revenue">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="variant-table-wrap">
                        <table class="variant-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">Variant</th>
                                    <th colspan="3" class="col-group">All Orders</th>
                                    <th colspan="3" class="col-group col-group-fulfilled col-divide">Fulfilled</th>
                                    <th rowspan="2">Share</th>
                                </tr>
                                <tr>
                                    <th>Units</th>
                                    <th>ML</th>
                                    <th>Revenue</th>
                                    <th class="col-group-fulfilled col-divide">Units</th>
                                    <th class="col-group-fulfilled">ML</th>
                                    <th class="col-group-fulfilled">Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="pp-variant-rows"></tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td id="pp-foot-units">—</td>
                                    <td id="pp-foot-ml">—</td>
                                    <td id="pp-foot-revenue">—</td>
                                    <td id="pp-foot-fulfilled-units" class="col-group-fulfilled col-divide">—</td>
                                    <td id="pp-foot-fulfilled-ml" class="col-group-fulfilled">—</td>
                                    <td id="pp-foot-fulfilled-revenue" class="col-group-fulfilled">—</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p class="source-note" id="pp-source-note"></p>
                </div>

            </div><!-- /accordion-body -->
        </div><!-- /card -->

        <!-- ── Card 2: Top Customers ── -->
        <div class="accordion-card" id="card-top-customers">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-top-customers"
                 onclick="toggleAccordion('card-top-customers')">
                <div class="accordion-header-icon">
                    <!-- users icon -->
                    <svg viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Top Customers</h2>
                    <p>Highest-spending customers with mailing addresses — view or export a spreadsheet.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-top-customers">

                <div class="tc-modes" id="tc-modes" role="tablist" aria-label="Ranking basis">
                    <button type="button" class="filter-link active" data-mode="spend"    role="tab" aria-selected="true">Total spend</button>
                    <button type="button" class="filter-link"        data-mode="items"    role="tab" aria-selected="false">Total items</button>
                    <button type="button" class="filter-link"        data-mode="per_item" role="tab" aria-selected="false">Spend per item</button>
                </div>

                <div class="tc-controls">
                    <label class="tc-control-label" for="tc-limit">Show top</label>
                    <input type="number" id="tc-limit" class="tc-limit-input"
                           value="100" min="1" max="1000" step="1">
                    <span class="tc-control-label">customers over</span>
                    <select id="tc-period" class="tc-period-select" aria-label="Timeframe">
                        <option value="all" selected>All time</option>
                        <option value="30d">Last 30 days</option>
                        <option value="90d">Last 90 days</option>
                        <option value="ytd">Year to date</option>
                        <option value="ttm">Trailing 12 months</option>
                    </select>
                    <button type="button" class="tc-load-btn" id="tc-load-btn">Load</button>
                    <a class="btn-download" id="tc-csv-btn" href="#">Download CSV</a>
                </div>

                <p class="tc-mode-note" id="tc-mode-note"></p>

                <!-- Loading -->
                <div class="lookup-loading" id="tc-loading">
                    <div class="spinner"></div>
                    Crunching customer spend…
                </div>

                <!-- Error -->
                <div class="lookup-error" id="tc-error"></div>

                <!-- Results -->
                <div class="results-area" id="tc-results">
                    <div class="tc-results-header">
                        <span class="tc-results-count" id="tc-count"></span>
                    </div>
                    <div class="tc-table-wrap">
                        <table class="tc-table mode-spend" id="tc-table">
                            <thead>
                                <tr>
                                    <th class="tc-col-rank">#</th>
                                    <th>Customer</th>
                                    <th>Mailing Address</th>
                                    <th class="tc-col-num col-orders">Orders</th>
                                    <th class="tc-col-num col-items">Items</th>
                                    <th class="tc-col-num col-spent">Total Spent</th>
                                    <th class="tc-col-num col-peritem">$/Item</th>
                                </tr>
                            </thead>
                            <tbody id="tc-rows"></tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /accordion-body -->
        </div><!-- /card -->

    </div><!-- /accordion -->
</div>

<script>
(function () {
    'use strict';

    // toggleAccordion and escHtml are provided by app/partials/header.php.

    // ── Product Profitability lookup ───────────────────────────────────────────

    const input      = document.getElementById('pp-search-input');
    const clearBtn   = document.getElementById('pp-search-clear');
    const dropdown   = document.getElementById('pp-dropdown');
    const loadingEl  = document.getElementById('pp-loading');
    const errorEl    = document.getElementById('pp-error');
    const resultsEl  = document.getElementById('pp-results');

    let debounceTimer   = null;
    let activeIndex     = -1;
    let dropdownItems   = [];
    let searchAbort     = null;

    // Show/hide the clear ×
    input.addEventListener('input', function () {
        clearBtn.style.display = this.value.length > 0 ? 'block' : 'none';
        scheduleSearch(this.value.trim());
    });

    clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.style.display = 'none';
        hideDropdown();
        input.focus();
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        if (!document.getElementById('pp-search-wrap').contains(e.target)) {
            hideDropdown();
        }
    });

    // Keyboard nav in dropdown
    input.addEventListener('keydown', function (e) {
        if (!dropdown.classList.contains('visible')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setFocused(Math.min(activeIndex + 1, dropdownItems.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setFocused(Math.max(activeIndex - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && activeIndex < dropdownItems.length) {
                selectProduct(dropdownItems[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    function setFocused(idx) {
        const rows = dropdown.querySelectorAll('.dropdown-item');
        rows.forEach((r, i) => r.classList.toggle('focused', i === idx));
        activeIndex = idx;
    }

    function scheduleSearch(q) {
        clearTimeout(debounceTimer);
        if (q.length < 2) { hideDropdown(); return; }
        debounceTimer = setTimeout(() => runSearch(q), 300);
    }

    function runSearch(q) {
        if (searchAbort) searchAbort.abort();
        searchAbort = new AbortController();

        fetch(apiUrl('product-search.php?q=') + encodeURIComponent(q), { signal: searchAbort.signal })
            .then(r => r.json())
            .then(products => renderDropdown(products))
            .catch(err => {
                if (err.name !== 'AbortError') hideDropdown();
            });
    }

    function renderDropdown(products) {
        activeIndex   = -1;
        dropdownItems = products;
        dropdown.innerHTML = '';

        if (products.length === 0) {
            dropdown.innerHTML = '<div class="dropdown-empty">No products found.</div>';
        } else {
            products.forEach(function (p, i) {
                const el = document.createElement('div');
                el.className    = 'dropdown-item';
                el.setAttribute('role', 'option');
                el.innerHTML =
                    '<div class="dropdown-item-title">' + escHtml(p.title) + '</div>' +
                    (p.vendor ? '<div class="dropdown-item-vendor">' + escHtml(p.vendor) + '</div>' : '');
                el.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent blur before click
                    selectProduct(p);
                });
                el.addEventListener('mousemove', function () { setFocused(i); });
                dropdown.appendChild(el);
            });
        }

        dropdown.classList.add('visible');
    }

    function hideDropdown() {
        dropdown.classList.remove('visible');
        dropdown.innerHTML = '';
        dropdownItems = [];
        activeIndex   = -1;
    }

    function selectProduct(product) {
        hideDropdown();
        input.value                = product.title;
        clearBtn.style.display     = 'block';

        clearResults();
        showLoading(true);
        showError('');

        fetch(apiUrl('product-profitability.php?product_id=') + encodeURIComponent(product.shopify_product_id))
            .then(function (r) {
                if (!r.ok) return r.json().then(d => Promise.reject(d.error || 'Server error'));
                return r.json();
            })
            .then(function (data) {
                showLoading(false);
                renderResults(data);
            })
            .catch(function (msg) {
                showLoading(false);
                showError(typeof msg === 'string' ? msg : 'Failed to load sales data.');
            });
    }

    window.clearProfitability = function () {
        input.value            = '';
        clearBtn.style.display = 'none';
        clearResults();
        showError('');
    };

    function clearResults() {
        resultsEl.classList.remove('visible');
    }

    function showLoading(on) {
        loadingEl.classList.toggle('visible', on);
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.toggle('visible', msg !== '');
    }

    function renderResults(data) {
        const product      = data.product;
        const summary      = data.summary;
        const variants     = data.variants;
        const totalRevenue = summary.total_revenue;

        document.getElementById('pp-product-name').textContent        = product.title;
        document.getElementById('pp-product-vendor').textContent      = product.vendor ? product.vendor : '';
        document.getElementById('pp-total-units').textContent         = fmtNum(summary.total_units);
        document.getElementById('pp-total-revenue').textContent       = fmtCurrency(totalRevenue);
        document.getElementById('pp-fulfilled-units').textContent     = fmtNum(summary.fulfilled_units);
        document.getElementById('pp-fulfilled-revenue').textContent   = fmtCurrency(summary.fulfilled_revenue);
        document.getElementById('pp-foot-units').textContent          = fmtNum(summary.total_units);
        document.getElementById('pp-foot-ml').textContent             = fmtNum(summary.total_ml);
        document.getElementById('pp-foot-revenue').textContent        = fmtCurrency(totalRevenue);
        document.getElementById('pp-foot-fulfilled-units').textContent   = fmtNum(summary.fulfilled_units);
        document.getElementById('pp-foot-fulfilled-ml').textContent      = fmtNum(summary.fulfilled_ml);
        document.getElementById('pp-foot-fulfilled-revenue').textContent = fmtCurrency(summary.fulfilled_revenue);

        const tbody = document.getElementById('pp-variant-rows');
        tbody.innerHTML = '';

        if (variants.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="8" style="text-align:center;color:#aaa;padding:1.25rem 1rem;">No sales found for this product.</td>';
            tbody.appendChild(tr);
        } else {
            variants.forEach(function (v) {
                const pct    = totalRevenue > 0 ? (v.total_revenue / totalRevenue * 100) : 0;
                const barPct = Math.max(pct, 2);
                const tr     = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + escHtml(v.variant_title) + '</td>' +
                    '<td>' + fmtNum(v.total_units) + '</td>' +
                    '<td>' + fmtNum(v.total_ml) + '</td>' +
                    '<td>' + fmtCurrency(v.total_revenue) + '</td>' +
                    '<td class="col-group-fulfilled col-divide">' + fmtNum(v.fulfilled_units) + '</td>' +
                    '<td class="col-group-fulfilled">' + fmtNum(v.fulfilled_ml) + '</td>' +
                    '<td class="col-group-fulfilled">' + fmtCurrency(v.fulfilled_revenue) + '</td>' +
                    '<td><div class="pct-bar-wrap">' +
                        '<span class="pct-text">' + pct.toFixed(1) + '%</span>' +
                        '<span class="pct-bar" style="width:' + Math.round(barPct * 0.6) + 'px"></span>' +
                    '</div></td>';
                tbody.appendChild(tr);
            });
        }

        const srcMap = { shopify_api: 'Live data from Shopify Admin API.', local_db: 'Based on orders synced to local database.' };
        document.getElementById('pp-source-note').textContent = srcMap[data.source] || '';

        resultsEl.classList.add('visible');
    }

    // ── Formatters ─────────────────────────────────────────────────────────────

    function fmtNum(n) {
        return Number(n).toLocaleString();
    }

    function fmtCurrency(n) {
        return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

})();
</script>

<script>
(function () {
    'use strict';

    // escHtml, apiUrl and toggleAccordion are provided by app/partials/header.php.

    // ── Top Customers lookup ────────────────────────────────────────────────────

    const limitInput = document.getElementById('tc-limit');
    const loadBtn    = document.getElementById('tc-load-btn');
    const csvBtn     = document.getElementById('tc-csv-btn');
    const modesEl    = document.getElementById('tc-modes');
    const periodEl   = document.getElementById('tc-period');
    const noteEl     = document.getElementById('tc-mode-note');
    const loadingEl  = document.getElementById('tc-loading');
    const errorEl    = document.getElementById('tc-error');
    const resultsEl  = document.getElementById('tc-results');
    const countEl    = document.getElementById('tc-count');
    const tableEl    = document.getElementById('tc-table');
    const rowsEl     = document.getElementById('tc-rows');

    let activeMode = 'spend';

    const MODE_CLASS = { spend: 'mode-spend', items: 'mode-items', per_item: 'mode-peritem' };
    const PERIOD_LABEL = {
        all: 'All time', '30d': 'Last 30 days', '90d': 'Last 90 days',
        ytd: 'Year to date', ttm: 'Trailing 12 months',
    };

    function clampLimit() {
        let n = parseInt(limitInput.value, 10);
        if (!Number.isFinite(n) || n < 1) n = 1;
        if (n > 1000) n = 1000;
        return n;
    }

    function fmtCurrency(n) {
        return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtInt(n) { return Number(n).toLocaleString(); }

    function addressHtml(c) {
        const lines = [];
        if (c.company)  lines.push(escHtml(c.company));
        if (c.address1) lines.push(escHtml(c.address1));
        if (c.address2) lines.push(escHtml(c.address2));
        let cityLine = [c.city, c.state].filter(Boolean).join(', ');
        if (c.zip) cityLine = (cityLine ? cityLine + ' ' : '') + c.zip;
        if (cityLine) lines.push(escHtml(cityLine));
        if (c.country && c.country !== 'US') lines.push(escHtml(c.country));
        return lines.length ? lines.join('<br>') : '<span class="tc-addr-missing">No address on file</span>';
    }

    function noteFor(data) {
        if (data.mode === 'items') {
            return 'Ranked by total number of items purchased across all orders.';
        }
        if (data.mode === 'per_item') {
            const pct = data.total_customers ? Math.round(100 * data.pool_size / data.total_customers) : 0;
            return 'Ranked by average spend per item (total spent ÷ items), among the ' +
                fmtInt(data.pool_size) + ' customers with at least ' + fmtInt(data.item_floor) +
                ' items purchased — the top ' + pct + '% by volume.';
        }
        return 'Ranked by total amount spent across all orders.';
    }

    function load() {
        const n = clampLimit();
        limitInput.value = n;
        loadBtn.disabled = true;
        errorEl.classList.remove('visible');
        resultsEl.classList.remove('visible');
        loadingEl.classList.add('visible');

        fetch(apiUrl('top-customers.php?mode=' + encodeURIComponent(activeMode) + '&period=' + encodeURIComponent(periodEl.value) + '&limit=' + n))
            .then(function (r) {
                if (!r.ok) return r.json().then(function (d) { return Promise.reject(d.error || 'Server error'); });
                return r.json();
            })
            .then(function (data) {
                loadingEl.classList.remove('visible');
                loadBtn.disabled = false;
                render(data);
            })
            .catch(function (msg) {
                loadingEl.classList.remove('visible');
                loadBtn.disabled = false;
                errorEl.textContent = typeof msg === 'string' ? msg : 'Failed to load customers.';
                errorEl.classList.add('visible');
            });
    }

    function render(data) {
        const list = data.customers || [];
        tableEl.className = 'tc-table ' + (MODE_CLASS[data.mode] || 'mode-spend');
        countEl.textContent = 'Top ' + list.length + ' customer' + (list.length === 1 ? '' : 's') +
            ' · ' + (PERIOD_LABEL[data.period] || 'All time');
        noteEl.textContent = noteFor(data);

        rowsEl.innerHTML = '';
        if (list.length === 0) {
            rowsEl.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:1.5rem;">No customers found.</td></tr>';
        } else {
            let html = '';
            list.forEach(function (c) {
                html += '<tr>' +
                    '<td class="tc-col-rank">' + c.rank + '</td>' +
                    '<td><div class="tc-cust-name">' + escHtml(c.name || '—') + '</div>' +
                        '<div class="tc-cust-email">' + escHtml(c.email) + '</div></td>' +
                    '<td class="tc-addr">' + addressHtml(c) + '</td>' +
                    '<td class="tc-col-num col-orders">' + fmtInt(c.order_count) + '</td>' +
                    '<td class="tc-col-num col-items">' + fmtInt(c.items) + '</td>' +
                    '<td class="tc-col-num col-spent">' + fmtCurrency(c.spent) + '</td>' +
                    '<td class="tc-col-num col-peritem">' + fmtCurrency(c.per_item) + '</td>' +
                    '</tr>';
            });
            rowsEl.innerHTML = html;
        }
        resultsEl.classList.add('visible');
    }

    function setMode(mode) {
        if (mode === activeMode) return;
        activeMode = mode;
        modesEl.querySelectorAll('.filter-link').forEach(function (b) {
            const on = b.dataset.mode === mode;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    modesEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.filter-link');
        if (!btn) return;
        setMode(btn.dataset.mode);
        load();
    });

    loadBtn.addEventListener('click', load);

    limitInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); load(); }
    });

    // Re-run when the timeframe changes (only if results are already showing).
    periodEl.addEventListener('change', function () {
        if (resultsEl.classList.contains('visible')) load();
    });

    // CSV downloads directly from the current mode + period + limit — no need to load the table first.
    csvBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const n = clampLimit();
        limitInput.value = n;
        window.location.href = apiUrl('top-customers.php?format=csv&mode=' + encodeURIComponent(activeMode) +
            '&period=' + encodeURIComponent(periodEl.value) + '&limit=' + n);
    });
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
