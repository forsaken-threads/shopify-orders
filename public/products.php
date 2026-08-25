<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';

requirePermission($config, 'orders');

$pageTitle  = 'Products - Cent Notes';
$activePage = 'products';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .products-wrap {
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

    .dropdown-item-brand {
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

    /* ── Print card ── */
    .print-card {
        margin-top: 1.75rem;
        max-width: 480px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,.05);
        overflow: hidden;
    }

    .print-card[hidden] { display: none; }

    .print-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .8rem 1.1rem;
        background: #f7f8fb;
        border-bottom: 1px solid #e2e8f0;
    }

    .print-card-product {
        font-size: .85rem;
        font-weight: 600;
        color: #1a1a2e;
    }

    .print-card-close {
        background: none;
        border: none;
        font-size: 1.3rem;
        line-height: 1;
        color: #aaa;
        cursor: pointer;
        padding: 0 .2rem;
    }

    .print-card-close:hover { color: #555; }

    .print-card-body { padding: 1.1rem; }

    .pc-field-group { margin-bottom: .9rem; }

    .pc-field-group label {
        display: block;
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #666;
        margin-bottom: .3rem;
    }

    .pc-field-group input[type="text"] {
        width: 100%;
        padding: .5rem .65rem;
        font-size: .88rem;
        font-family: inherit;
        color: #1a1a2e;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        outline: none;
        transition: border-color .15s, box-shadow .15s;
    }

    .pc-field-group input[type="text"]:focus {
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    /* Radios rather than a select: one press picks a size, no dropdown to open. */
    .pc-ml-choices { display: flex; gap: .4rem; }

    .pc-ml-choices input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .pc-ml-choices label {
        margin: 0;
        padding: .45rem .9rem;
        font-size: .82rem;
        font-weight: 600;
        text-transform: none;
        letter-spacing: 0;
        color: #555;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        cursor: pointer;
        transition: background .15s, border-color .15s, color .15s;
    }

    .pc-ml-choices label:hover { border-color: #1a1a2e; }

    .pc-ml-choices input[type="radio"]:checked + label {
        background: #1a1a2e;
        border-color: #1a1a2e;
        color: #fff;
    }

    .pc-ml-choices input[type="radio"]:focus-visible + label {
        box-shadow: 0 0 0 3px rgba(26,26,46,.18);
    }

    .print-card-footer {
        display: flex;
        align-items: center;
        gap: .7rem;
        margin-top: 1.2rem;
    }

    .pc-skip-label {
        display: flex;
        align-items: center;
        gap: .35rem;
        font-size: .75rem;
        color: #666;
        cursor: pointer;
    }

    .pc-error {
        flex: 1;
        font-size: .75rem;
        color: #b91c1c;
        text-align: right;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .btn-pc-print {
        padding: .5rem 1.3rem;
        font-size: .85rem;
        font-weight: 600;
        font-family: inherit;
        color: #fff;
        background: #1a1a2e;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: background .15s, opacity .15s;
    }

    .btn-pc-print:hover { background: #2d2d5e; }
    .btn-pc-print:disabled { opacity: .45; cursor: default; }
    .btn-pc-print.pc-ok { background: #166534; }
    .btn-pc-print.pc-fail { background: #991b1b; }

    .products-hint {
        margin-top: 1.75rem;
        font-size: .85rem;
        color: #999;
    }

    @media (max-width: 768px) {
        .products-wrap { padding: 1.25rem; max-width: 100%; }
        .search-wrap, .print-card { max-width: 100%; }
    }
</style>

<div class="products-wrap">
    <div class="page-header">
        <h1>Products</h1>
        <span class="subtitle">Look up any product and print a label</span>
    </div>

    <div class="search-wrap" id="pc-search-wrap">
        <div class="search-input-row">
            <span class="search-icon">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input
                type="text"
                class="search-input"
                id="pc-search-input"
                placeholder="Search products by name…"
                autocomplete="off"
                spellcheck="false"
            >
            <button class="search-clear" id="pc-search-clear" tabindex="-1" aria-label="Clear search">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="search-dropdown" id="pc-dropdown" role="listbox"></div>
    </div>

    <div class="print-card" id="pc-card" hidden>
        <div class="print-card-head">
            <span class="print-card-product" id="pc-card-product"></span>
            <button type="button" class="print-card-close" id="pc-card-close" aria-label="Clear selection">&times;</button>
        </div>
        <div class="print-card-body">
            <form id="pc-form">
                <div class="pc-field-group">
                    <label for="pc-title">Product Title</label>
                    <input type="text" id="pc-title" autocomplete="off">
                </div>
                <div class="pc-field-group">
                    <label for="pc-brand">Brand</label>
                    <input type="text" id="pc-brand" autocomplete="off">
                </div>
                <div class="pc-field-group">
                    <label>Size</label>
                    <div class="pc-ml-choices">
                        <input type="radio" name="pc-ml" id="pc-ml-1" value="1" checked>
                        <label for="pc-ml-1">1ml</label>
                        <input type="radio" name="pc-ml" id="pc-ml-5" value="5">
                        <label for="pc-ml-5">5ml</label>
                        <input type="radio" name="pc-ml" id="pc-ml-10" value="10">
                        <label for="pc-ml-10">10ml</label>
                    </div>
                </div>
                <div class="print-card-footer">
                    <label class="pc-skip-label">
                        <input type="checkbox" id="pc-skip-persist">
                        Don't save edits
                    </label>
                    <span class="pc-error" id="pc-error"></span>
                    <button type="submit" class="btn-pc-print" id="pc-print-btn">Print</button>
                </div>
            </form>
        </div>
    </div>

    <p class="products-hint" id="pc-hint">Type at least two characters to search.</p>
</div>

<script>
(function () {
    'use strict';

    var searchWrap  = document.getElementById('pc-search-wrap');
    var searchInput = document.getElementById('pc-search-input');
    var searchClear = document.getElementById('pc-search-clear');
    var dropdown    = document.getElementById('pc-dropdown');
    var card        = document.getElementById('pc-card');
    var cardProduct = document.getElementById('pc-card-product');
    var cardClose   = document.getElementById('pc-card-close');
    var form        = document.getElementById('pc-form');
    var titleInput  = document.getElementById('pc-title');
    var brandInput  = document.getElementById('pc-brand');
    var skipPersist = document.getElementById('pc-skip-persist');
    var errorEl     = document.getElementById('pc-error');
    var printBtn    = document.getElementById('pc-print-btn');
    var hint        = document.getElementById('pc-hint');

    var debounceTimer = null;
    var searchAbort   = null;
    var items         = [];
    var activeIndex   = -1;
    var selected      = null;   // the product row the card is currently showing

    // Same rule the order and bundle print forms use: a saved preference wins,
    // and an unsaved one falls back to the title with its brand prefix removed.
    function stripBrandPrefix(title, brand) {
        if (!brand) return title;
        var re = new RegExp('^' + brand.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*', 'i');
        return title.replace(re, '').trim();
    }

    // ── Live search ───────────────────────────────────────────────────────

    function hideDropdown() {
        dropdown.classList.remove('visible');
        dropdown.innerHTML = '';
        items       = [];
        activeIndex = -1;
    }

    function setFocused(idx) {
        var rows = dropdown.querySelectorAll('.dropdown-item');
        rows.forEach(function (r, i) { r.classList.toggle('focused', i === idx); });
        activeIndex = idx;
    }

    function runSearch(q) {
        if (searchAbort) searchAbort.abort();
        searchAbort = new AbortController();

        fetch(apiUrl('product-search.php?mode=print&q=') + encodeURIComponent(q), { signal: searchAbort.signal })
            .then(function (r) { return r.json(); })
            .then(renderDropdown)
            .catch(function (err) { if (err.name !== 'AbortError') hideDropdown(); });
    }

    function renderDropdown(products) {
        items       = products;
        activeIndex = -1;
        dropdown.innerHTML = '';

        if (products.length === 0) {
            dropdown.innerHTML = '<div class="dropdown-empty">No products found.</div>';
        } else {
            products.forEach(function (p, i) {
                var brand = p.preferred_brand != null ? p.preferred_brand : (p.custom_brand || '');
                var el = document.createElement('div');
                el.className = 'dropdown-item';
                el.setAttribute('role', 'option');
                el.innerHTML =
                    '<div class="dropdown-item-title">' + escHtml(p.title) + '</div>' +
                    (brand ? '<div class="dropdown-item-brand">' + escHtml(brand) + '</div>' : '');
                el.addEventListener('mousedown', function (e) { e.preventDefault(); selectProduct(p); });
                el.addEventListener('mousemove', function () { setFocused(i); });
                dropdown.appendChild(el);
            });
        }
        dropdown.classList.add('visible');
    }

    searchInput.addEventListener('input', function () {
        var q = searchInput.value.trim();
        searchClear.style.display = q ? 'block' : 'none';
        clearTimeout(debounceTimer);
        if (q.length < 2) { hideDropdown(); return; }
        debounceTimer = setTimeout(function () { runSearch(q); }, 300);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (!dropdown.classList.contains('visible')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setFocused(Math.min(activeIndex + 1, items.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setFocused(Math.max(activeIndex - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0) selectProduct(items[activeIndex]);
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    searchClear.addEventListener('click', function () {
        searchInput.value = '';
        searchClear.style.display = 'none';
        hideDropdown();
        searchInput.focus();
    });

    document.addEventListener('click', function (e) {
        if (!searchWrap.contains(e.target)) hideDropdown();
    });

    // ── Print card ────────────────────────────────────────────────────────

    function selectProduct(p) {
        selected = p;
        hideDropdown();

        cardProduct.textContent = p.title;
        titleInput.value = p.preferred_title != null
            ? p.preferred_title
            : stripBrandPrefix(p.title, p.custom_brand || '');
        brandInput.value = p.preferred_brand != null ? p.preferred_brand : (p.custom_brand || '');

        document.getElementById('pc-ml-1').checked = true;
        skipPersist.checked = false;
        errorEl.textContent = '';
        resetPrintButton();

        card.hidden = false;
        hint.hidden = true;
        titleInput.focus();
    }

    function clearSelection() {
        selected    = null;
        card.hidden = true;
        hint.hidden = false;
        searchInput.focus();
    }

    function resetPrintButton() {
        printBtn.disabled = false;
        printBtn.textContent = 'Print';
        printBtn.classList.remove('pc-ok', 'pc-fail');
    }

    cardClose.addEventListener('click', clearSelection);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!selected) return;

        printBtn.disabled = true;
        printBtn.textContent = 'Printing…';
        printBtn.classList.remove('pc-ok', 'pc-fail');
        errorEl.textContent = '';

        var ml = form.querySelector('input[name="pc-ml"]:checked').value;

        var formData = new FormData();
        formData.append('action', 'product');
        formData.append('product_id', selected.id);
        formData.append('items[0][title]', titleInput.value);
        formData.append('items[0][full_title]', selected.title);
        formData.append('items[0][custom_brand]', brandInput.value);
        formData.append('items[0][original_brand]', selected.custom_brand || '');
        formData.append('items[0][ml]', ml);
        formData.append('items[0][shopify_product_id]', selected.shopify_product_id);
        formData.append('items[0][preferred_title]', selected.preferred_title != null ? selected.preferred_title : '');
        formData.append('items[0][preferred_brand]', selected.preferred_brand != null ? selected.preferred_brand : '');
        formData.append('items[0][quantity]', '1');
        if (skipPersist.checked) {
            formData.append('skip_persist', '1');
        }

        fetch(apiUrl('print-order.php'), {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok && data.results && data.results[0] && data.results[0].status === 'ok') {
                printBtn.textContent = 'Printed';
                printBtn.classList.add('pc-ok');
                // The label just printed is now this product's preference, unless
                // the operator asked us not to save it.
                if (!skipPersist.checked) {
                    selected.preferred_title = titleInput.value;
                    selected.preferred_brand = brandInput.value;
                }
            } else {
                printBtn.textContent = 'Failed';
                printBtn.classList.add('pc-fail');
                errorEl.textContent = (data.results && data.results[0] && data.results[0].error) || data.error || 'Print failed';
            }
            setTimeout(resetPrintButton, 3000);
        })
        .catch(function () {
            printBtn.textContent = 'Error';
            printBtn.classList.add('pc-fail');
            errorEl.textContent = 'Network error — please try again.';
            setTimeout(resetPrintButton, 3000);
        });
    });

    searchInput.focus();
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
