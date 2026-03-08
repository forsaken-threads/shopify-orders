<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$pageTitle  = 'Reports - Utility App';
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
    .summary-pills {
        display: flex;
        gap: .75rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

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

                    <div class="variant-table-wrap">
                        <table class="variant-table">
                            <thead>
                                <tr>
                                    <th>Variant</th>
                                    <th>Units Sold</th>
                                    <th>Revenue</th>
                                    <th>Share</th>
                                </tr>
                            </thead>
                            <tbody id="pp-variant-rows"></tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td id="pp-foot-units">—</td>
                                    <td id="pp-foot-revenue">—</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p class="source-note" id="pp-source-note"></p>
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

        fetch('api/product-search.php?q=' + encodeURIComponent(q), { signal: searchAbort.signal })
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

        fetch('api/product-profitability.php?product_id=' + encodeURIComponent(product.shopify_product_id))
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

        document.getElementById('pp-product-name').textContent    = product.title;
        document.getElementById('pp-product-vendor').textContent  = product.vendor ? product.vendor : '';
        document.getElementById('pp-total-units').textContent     = fmtNum(summary.total_units);
        document.getElementById('pp-total-revenue').textContent   = fmtCurrency(totalRevenue);
        document.getElementById('pp-foot-units').textContent      = fmtNum(summary.total_units);
        document.getElementById('pp-foot-revenue').textContent    = fmtCurrency(totalRevenue);

        const tbody = document.getElementById('pp-variant-rows');
        tbody.innerHTML = '';

        if (variants.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4" style="text-align:center;color:#aaa;padding:1.25rem 1rem;">No sales found for this product.</td>';
            tbody.appendChild(tr);
        } else {
            variants.forEach(function (v) {
                const pct    = totalRevenue > 0 ? (v.total_revenue / totalRevenue * 100) : 0;
                const barPct = Math.max(pct, 2);
                const tr     = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + escHtml(v.variant_title) + '</td>' +
                    '<td>' + fmtNum(v.total_units) + '</td>' +
                    '<td>' + fmtCurrency(v.total_revenue) + '</td>' +
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

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
