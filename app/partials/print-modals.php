<!-- ── Print Labels Modal ──────────────────────────────────────────────── -->
<div id="print-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-label="Print labels">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="print-modal-title">Print Labels</span>
            <button id="print-modal-close" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div id="print-modal-loading" class="modal-fetch-loading" hidden>
            <div class="detail-spinner"></div>
            Loading line items…
        </div>
        <div id="print-modal-body" class="print-modal-body"></div>
    </div>
</div>

<!-- ── One-off Print Modal ───────────────────────────────────────────── -->
<div id="oneoff-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-label="Print single label">
    <div class="modal-box oneoff-modal-box">
        <div class="modal-header">
            <span class="modal-title" id="oneoff-modal-title">Print Label</span>
            <button id="oneoff-modal-close" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div id="oneoff-modal-body" class="oneoff-modal-body"></div>
    </div>
</div>

<style>
/* ── Modal ──────────────────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.55);
    padding: 1rem;
}

.modal-overlay[hidden] { display: none; }

.modal-box {
    background: var(--bg-card, #fff);
    border-radius: 8px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    width: min(860px, 100%);
    max-height: 85vh;
    overflow: hidden;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--border, #e5e7eb);
    flex-shrink: 0;
}

.modal-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text, #111827);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: var(--text-muted, #6b7280);
    cursor: pointer;
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
}

.modal-close:hover { background: var(--bg-subtle, #f3f4f6); color: var(--text, #111827); }

.modal-fetch-loading {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: 1.5rem 1.25rem;
    font-size: .875rem;
    color: #888;
}

.modal-fetch-loading[hidden] { display: none; }

/* ── Print button ──────────────────────────────────────────────────────────── */
.btn-print {
    display: inline-block;
    padding: .4rem 1rem;
    background: #1a1a2e;
    color: #fff;
    border: 1px solid #1a1a2e;
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background .15s;
}

.btn-print:hover { background: #2d2d5e; border-color: #2d2d5e; }
.btn-print:disabled { opacity: .45; cursor: default; }

/* ── Print modal body ──────────────────────────────────────────────────────── */
#print-modal .modal-box {
    width: min(1100px, 100%);
}

.print-modal-body {
    overflow: auto;
    padding: 1rem 1.25rem 1.25rem;
    flex: 1;
}

.print-modal-body table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
    margin-bottom: 1rem;
}

.print-modal-body th {
    text-align: left;
    padding: .4rem .5rem;
    border-bottom: 2px solid var(--border, #e5e7eb);
    font-weight: 600;
    font-size: .78rem;
    color: var(--text-muted, #6b7280);
    white-space: nowrap;
    background: transparent;
    color: var(--text-muted, #6b7280);
}

.print-modal-body td {
    padding: .35rem .5rem;
    border-bottom: 1px solid var(--border, #f3f4f6);
    vertical-align: middle;
}

.print-modal-body input[type="text"] {
    width: 100%;
    padding: .35rem .5rem;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 4px;
    font-size: .85rem;
    font-family: inherit;
    color: var(--text, #111827);
}

.print-modal-body input[type="text"]:focus {
    outline: none;
    border-color: var(--accent, #4f46e5);
    box-shadow: 0 0 0 2px rgba(79, 70, 229, .15);
}

.print-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .75rem;
    padding-top: .75rem;
    border-top: 1px solid var(--border, #e5e7eb);
}

.btn-print-submit {
    padding: .55rem 1.5rem;
    background: #1a1a2e;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}

.btn-print-submit:hover { background: #2d2d5e; }
.btn-print-submit:disabled { opacity: .45; cursor: default; }

.btn-print-cancel {
    padding: .55rem 1.5rem;
    background: transparent;
    color: #666;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-print-cancel:hover { background: #f3f4f6; color: #333; }

.print-total-qty {
    font-size: .85rem;
    color: var(--text, #374151);
    margin-right: auto;
}

.print-error {
    color: #b91c1c;
    font-size: .85rem;
}

/* ── Print review stage: retry checkboxes & status indicators ──────────── */
.print-retry-col { width: 2rem; text-align: center; }
.print-status-col { width: 4.5rem; text-align: center; white-space: nowrap; }

.print-retry-cb {
    width: 1rem;
    height: 1rem;
    cursor: pointer;
    accent-color: #4f46e5;
}

.label-ok {
    display: inline-block;
    padding: .15rem .5rem;
    background: #dcfce7;
    color: #166534;
    border-radius: 4px;
    font-size: .75rem;
    font-weight: 600;
}

.label-fail {
    display: inline-block;
    padding: .15rem .5rem;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 4px;
    font-size: .75rem;
    font-weight: 600;
}

tr.print-row-error { background: #fef2f2; }
tr.print-row-ok td input[type="text"] { opacity: .55; }

/* ── One-off print button in detail line items ─────────────────────────── */
.oneoff-print-cell { white-space: nowrap; text-align: center; }

.btn-oneoff-print {
    padding: .2rem .6rem;
    background: transparent;
    color: #1a1a2e;
    border: 1px solid #1a1a2e;
    border-radius: 4px;
    font-size: .72rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
}

.btn-oneoff-print:hover { background: #1a1a2e; color: #fff; }

/* ── One-off print modal ──────────────────────────────────────────────── */
.oneoff-modal-box { width: min(520px, 100%); }

.oneoff-modal-body {
    padding: 1rem 1.25rem 1.25rem;
}

.oneoff-modal-body label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted, #6b7280);
    margin-bottom: .2rem;
}

.oneoff-modal-body input[type="text"] {
    width: 100%;
    padding: .4rem .5rem;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 4px;
    font-size: .85rem;
    font-family: inherit;
    color: var(--text, #111827);
    box-sizing: border-box;
}

.oneoff-modal-body input[type="text"]:focus {
    outline: none;
    border-color: var(--accent, #4f46e5);
    box-shadow: 0 0 0 2px rgba(79, 70, 229, .15);
}

.oneoff-field-group {
    margin-bottom: .75rem;
}

.oneoff-field-row {
    display: flex;
    gap: .75rem;
    margin-bottom: .75rem;
}

.oneoff-field-row > .oneoff-field-group {
    flex: 1;
    margin-bottom: 0;
}

.oneoff-ml-display {
    font-size: .85rem;
    color: var(--text, #374151);
    padding: .4rem 0;
}

.oneoff-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .75rem;
    padding-top: .75rem;
    border-top: 1px solid var(--border, #e5e7eb);
}

.oneoff-modal-footer .print-error {
    margin-right: auto;
}

.btn-oneoff-submit {
    padding: .5rem 1.25rem;
    background: #1a1a2e;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}

.btn-oneoff-submit:hover { background: #2d2d5e; }
.btn-oneoff-submit:disabled { opacity: .45; cursor: default; }

.btn-oneoff-submit.oneoff-ok { background: #166534; }
.btn-oneoff-submit.oneoff-fail { background: #991b1b; }

.btn-oneoff-cancel {
    padding: .5rem 1.25rem;
    background: transparent;
    color: #666;
    border: 1px solid var(--border, #d1d5db);
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, color .15s;
}

.btn-oneoff-cancel:hover { background: #f3f4f6; color: #333; }

/* ── Spinner (shared) ─────────────────────────────────────────────────── */
.detail-spinner {
    width: 1rem;
    height: 1rem;
    border: 2px solid #e2e8f0;
    border-top-color: #1a1a2e;
    border-radius: 50%;
    animation: pm-spin .7s linear infinite;
    flex-shrink: 0;
}

@keyframes pm-spin { to { transform: rotate(360deg); } }
</style>

<script>
/**
 * Shared print-modal logic.
 *
 * Exposes a global `PrintModals` object with helpers that pages can use.
 * Both the full-order print modal and one-off print modal are managed here.
 */
var PrintModals = (function () {
    'use strict';

    var esc = escHtml;   // from header.php

    // ── DOM refs ──────────────────────────────────────────────────────────
    var printModal     = document.getElementById('print-modal');
    var printTitle     = document.getElementById('print-modal-title');
    var printLoading   = document.getElementById('print-modal-loading');
    var printBody      = document.getElementById('print-modal-body');
    var printCloseBtn  = document.getElementById('print-modal-close');

    var oneoffModal    = document.getElementById('oneoff-modal');
    var oneoffTitle    = document.getElementById('oneoff-modal-title');
    var oneoffBody     = document.getElementById('oneoff-modal-body');
    var oneoffCloseBtn = document.getElementById('oneoff-modal-close');

    var activePrintId  = null;

    // ── Helpers ───────────────────────────────────────────────────────────

    function stripBrandPrefix(title, brand) {
        if (!brand) return title;
        var re = new RegExp('^' + brand.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*', 'i');
        return title.replace(re, '').trim();
    }

    // ── Full order print form rendering ───────────────────────────────────

    function renderPrintForm(data) {
        var o  = data.order;
        var li = data.line_items;
        if (!li || li.length === 0) {
            return '<p style="color:#888;font-size:.85rem;">No line items to print.</p>';
        }

        var totalPrintQty = 0;
        var rows = li.map(function (item, i) {
            var brand = item.preferred_brand != null ? item.preferred_brand : (item.custom_brand || '');
            var displayTitle = item.preferred_title != null
                ? item.preferred_title
                : stripBrandPrefix(item.title, item.custom_brand);
            var preferredTitle = item.preferred_title != null ? item.preferred_title : '';
            var preferredBrand = item.preferred_brand != null ? item.preferred_brand : '';
            var ml = item.variant_ml != null ? String(item.variant_ml) : '';
            var qty = Number(item.quantity);
            totalPrintQty += qty;
            return '<tr data-item-index="' + i + '">' +
                '<td class="print-retry-col" hidden>' +
                    '<input type="checkbox" class="print-retry-cb" data-index="' + i + '">' +
                '</td>' +
                '<td class="print-status-col" hidden></td>' +
                '<td>' +
                    '<input type="text" name="items[' + i + '][title]" value="' + esc(displayTitle) + '">' +
                    '<input type="hidden" name="items[' + i + '][full_title]" value="' + esc(item.title) + '">' +
                    '<input type="hidden" name="items[' + i + '][shopify_product_id]" value="' + esc(item.shopify_product_id || '') + '">' +
                    '<input type="hidden" name="items[' + i + '][ml]" value="' + esc(ml) + '">' +
                    '<input type="hidden" name="items[' + i + '][preferred_title]" value="' + esc(preferredTitle) + '">' +
                '</td>' +
                '<td>' +
                    '<input type="text" name="items[' + i + '][custom_brand]" value="' + esc(brand) + '">' +
                    '<input type="hidden" name="items[' + i + '][original_brand]" value="' + esc(brand) + '">' +
                    '<input type="hidden" name="items[' + i + '][preferred_brand]" value="' + esc(preferredBrand) + '">' +
                '</td>' +
                '<td>' + esc(ml ? ml + 'ml' : '') + '</td>' +
                '<td class="qty">' + qty +
                    '<input type="hidden" name="items[' + i + '][quantity]" value="' + qty + '">' +
                '</td>' +
                '</tr>';
        }).join('');

        return '<form id="print-form">' +
            '<input type="hidden" name="order_id" value="' + esc(String(o.id)) + '">' +
            '<table><thead><tr>' +
            '<th class="print-retry-col" hidden></th>' +
            '<th class="print-status-col" hidden></th>' +
            '<th>Product Title</th><th>Brand</th><th>ML</th><th>Qty</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="print-modal-footer">' +
            '<span class="print-total-qty">Total labels: <strong>' + totalPrintQty + '</strong></span>' +
            '<span class="print-error" id="print-error"></span>' +
            '<button type="button" class="btn-print-cancel" id="print-cancel-btn">Cancel</button>' +
            '<button type="submit" class="btn-print-submit" id="print-submit-btn">Print Labels</button>' +
            '</div>' +
            '</form>';
    }

    // ── Review stage ──────────────────────────────────────────────────────

    function enterReviewStage(results) {
        var form = document.getElementById('print-form');
        if (!form) return;

        form.querySelectorAll('.print-retry-col, .print-status-col').forEach(function (el) {
            el.hidden = false;
        });

        results.forEach(function (r) {
            var row = form.querySelector('tr[data-item-index="' + r.index + '"]');
            if (!row) return;
            var statusCell = row.querySelector('.print-status-col');
            var cb = row.querySelector('.print-retry-cb');
            if (r.status === 'error') {
                row.classList.add('print-row-error');
                statusCell.innerHTML = '<span class="label-fail">FAILED</span>';
                cb.checked = true;
            } else {
                row.classList.add('print-row-ok');
                statusCell.innerHTML = '<span class="label-ok">OK</span>';
                cb.checked = false;
            }
        });

        form.querySelectorAll('.print-retry-cb').forEach(function (cb) {
            cb.addEventListener('change', updatePrintButton);
        });

        updatePrintButton();
    }

    function updatePrintButton() {
        var form = document.getElementById('print-form');
        var submitBtn = document.getElementById('print-submit-btn');
        if (!form || !submitBtn) return;

        var anyChecked = form.querySelector('.print-retry-cb:checked');
        submitBtn.textContent = anyChecked ? 'Retry' : 'Confirm';
        submitBtn.disabled = false;
    }

    // ── Print form submit handler ─────────────────────────────────────────

    function handlePrintSubmit(e) {
        e.preventDefault();
        var form      = e.target;
        var submitBtn = document.getElementById('print-submit-btn');
        var errorEl   = document.getElementById('print-error');
        var orderId   = form.querySelector('[name="order_id"]').value;

        var inReview = !form.querySelector('.print-retry-col').hidden;

        if (inReview) {
            var anyChecked = form.querySelector('.print-retry-cb:checked');

            if (!anyChecked) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Confirming…';
                errorEl.textContent = '';

                var confirmData = new FormData();
                confirmData.append('order_id', orderId);
                confirmData.append('action', 'confirm');

                fetch('api/print-order.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: confirmData,
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.ok) {
                        closePrintModal();
                        if (_onPrintConfirm) _onPrintConfirm(orderId);
                    } else {
                        errorEl.textContent = data.error || 'Unknown error';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Confirm';
                    }
                })
                .catch(function () {
                    errorEl.textContent = 'Network error — please try again.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Confirm';
                });
                return;
            }

            // Retry: reset visual state on retried rows
            form.querySelectorAll('.print-retry-cb:checked').forEach(function (cb) {
                var row = cb.closest('tr');
                row.classList.remove('print-row-error', 'print-row-ok');
                row.querySelector('.print-status-col').innerHTML = '';
            });
        }

        // Print (initial or retry)
        submitBtn.disabled = true;
        submitBtn.textContent = 'Printing…';
        errorEl.textContent = '';

        var formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('action', 'print');

        var rows = form.querySelectorAll('tr[data-item-index]');
        var sendIndex = 0;
        rows.forEach(function (row) {
            var idx = row.getAttribute('data-item-index');
            if (inReview) {
                var cb = row.querySelector('.print-retry-cb');
                if (!cb || !cb.checked) return;
            }
            row.querySelectorAll('input[name^="items[' + idx + ']"]').forEach(function (input) {
                var name = input.name.replace('items[' + idx + ']', 'items[' + sendIndex + ']');
                formData.append(name, input.value);
            });
            formData.append('_row_map[' + sendIndex + ']', idx);
            sendIndex++;
        });

        fetch('api/print-order.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) {
                errorEl.textContent = data.error || 'Unknown error';
                submitBtn.disabled = false;
                submitBtn.textContent = inReview ? 'Retry' : 'Print Labels';
                return;
            }

            var mapped = (data.results || []).map(function (r) {
                var origIdx = formData.get('_row_map[' + r.index + ']');
                return { index: origIdx != null ? Number(origIdx) : r.index, title: r.title, status: r.status, error: r.error };
            });

            if (inReview) {
                rows.forEach(function (row) {
                    var idx = Number(row.getAttribute('data-item-index'));
                    var retried = mapped.find(function (m) { return m.index === idx; });
                    if (retried) {
                        var statusCell = row.querySelector('.print-status-col');
                        var cb = row.querySelector('.print-retry-cb');
                        row.classList.remove('print-row-error', 'print-row-ok');
                        if (retried.status === 'error') {
                            row.classList.add('print-row-error');
                            statusCell.innerHTML = '<span class="label-fail">FAILED</span>';
                            cb.checked = true;
                        } else {
                            row.classList.add('print-row-ok');
                            statusCell.innerHTML = '<span class="label-ok">OK</span>';
                            cb.checked = false;
                        }
                    }
                });
                updatePrintButton();
            } else {
                enterReviewStage(mapped);
            }
        })
        .catch(function () {
            errorEl.textContent = 'Network error — please try again.';
            submitBtn.disabled = false;
            submitBtn.textContent = inReview ? 'Retry' : 'Print Labels';
        });
    }

    // ── Open / close full print modal ─────────────────────────────────────

    var _onPrintConfirm = null;
    var _detailCache = null;

    function openPrintModal(orderId, orderNumber, detailCache) {
        activePrintId = orderId;
        _detailCache = detailCache || {};
        printTitle.textContent = 'Print Labels — Order ' + orderNumber;
        printBody.innerHTML = '';
        printModal.hidden = false;
        printCloseBtn.focus();

        function show(data) {
            printLoading.hidden = true;
            printBody.innerHTML = renderPrintForm(data);
            var cancelBtn = document.getElementById('print-cancel-btn');
            if (cancelBtn) cancelBtn.addEventListener('click', closePrintModal);
            var form = document.getElementById('print-form');
            if (form) form.addEventListener('submit', handlePrintSubmit);
        }

        if (_detailCache[orderId]) {
            printLoading.hidden = true;
            show(_detailCache[orderId]);
            return;
        }

        printLoading.hidden = false;
        fetch('api/order-detail.php?id=' + encodeURIComponent(orderId))
            .then(function (res) {
                if (!res.ok) return res.json().then(function (d) { throw new Error(d.error || 'Server error'); });
                return res.json();
            })
            .then(function (data) {
                _detailCache[orderId] = data;
                show(data);
            })
            .catch(function (err) {
                printLoading.hidden = true;
                printBody.innerHTML =
                    '<p style="color:#b91c1c;font-size:.85rem;padding:.5rem 0;">' +
                    'Failed to load order details: ' + esc(err.message) + '</p>';
            });
    }

    function closePrintModal() {
        printModal.hidden = true;
        activePrintId = null;
    }

    // ── One-off print modal ───────────────────────────────────────────────

    function openOneoffModal(btn) {
        var orderId        = btn.dataset.orderId;
        var title          = btn.dataset.title;
        var fullTitle      = btn.dataset.fullTitle;
        var brand          = btn.dataset.brand;
        var ml             = btn.dataset.ml;
        var productId      = btn.dataset.productId;
        var preferredTitle = btn.dataset.preferredTitle || '';
        var preferredBrand = btn.dataset.preferredBrand || '';

        // Use preferred values when set, otherwise fall back to defaults
        var displayTitle = preferredTitle || title;
        var displayBrand = preferredBrand || brand;

        oneoffTitle.textContent = 'Print Label — ' + ml + 'ml';

        oneoffBody.innerHTML =
            '<form id="oneoff-form">' +
            '<input type="hidden" name="order_id" value="' + esc(orderId) + '">' +
            '<input type="hidden" name="full_title" value="' + esc(fullTitle) + '">' +
            '<input type="hidden" name="original_brand" value="' + esc(displayBrand) + '">' +
            '<input type="hidden" name="ml" value="' + esc(ml) + '">' +
            '<input type="hidden" name="product_id" value="' + esc(productId) + '">' +
            '<input type="hidden" name="preferred_title" value="' + esc(preferredTitle) + '">' +
            '<input type="hidden" name="preferred_brand" value="' + esc(preferredBrand) + '">' +
            '<div class="oneoff-field-group">' +
                '<label for="oneoff-title">Product Title</label>' +
                '<input type="text" id="oneoff-title" name="title" value="' + esc(displayTitle) + '">' +
            '</div>' +
            '<div class="oneoff-field-row">' +
                '<div class="oneoff-field-group">' +
                    '<label for="oneoff-brand">Brand</label>' +
                    '<input type="text" id="oneoff-brand" name="brand" value="' + esc(displayBrand) + '">' +
                '</div>' +
                '<div class="oneoff-field-group">' +
                    '<label>ML Size</label>' +
                    '<div class="oneoff-ml-display">' + esc(ml) + 'ml</div>' +
                '</div>' +
            '</div>' +
            '<div class="oneoff-modal-footer">' +
                '<span class="print-error" id="oneoff-error"></span>' +
                '<button type="button" class="btn-oneoff-cancel" id="oneoff-cancel-btn">Cancel</button>' +
                '<button type="submit" class="btn-oneoff-submit" id="oneoff-submit-btn">Print</button>' +
            '</div>' +
            '</form>';

        oneoffModal.hidden = false;

        var cancelBtn = document.getElementById('oneoff-cancel-btn');
        if (cancelBtn) cancelBtn.addEventListener('click', closeOneoffModal);

        var form = document.getElementById('oneoff-form');
        if (form) form.addEventListener('submit', handleOneoffSubmit);

        document.getElementById('oneoff-title').focus();
    }

    function closeOneoffModal() {
        oneoffModal.hidden = true;
    }

    function handleOneoffSubmit(e) {
        e.preventDefault();
        var form      = e.target;
        var submitBtn = document.getElementById('oneoff-submit-btn');
        var errorEl   = document.getElementById('oneoff-error');

        submitBtn.disabled = true;
        submitBtn.textContent = 'Printing…';
        submitBtn.classList.remove('oneoff-ok', 'oneoff-fail');
        errorEl.textContent = '';

        var formData = new FormData();
        formData.append('order_id', form.querySelector('[name="order_id"]').value);
        formData.append('action', 'oneoff');
        formData.append('items[0][title]', form.querySelector('[name="title"]').value);
        formData.append('items[0][full_title]', form.querySelector('[name="full_title"]').value);
        formData.append('items[0][custom_brand]', form.querySelector('[name="brand"]').value);
        formData.append('items[0][original_brand]', form.querySelector('[name="original_brand"]').value);
        formData.append('items[0][ml]', form.querySelector('[name="ml"]').value);
        formData.append('items[0][shopify_product_id]', form.querySelector('[name="product_id"]').value);
        formData.append('items[0][preferred_title]', form.querySelector('[name="preferred_title"]').value);
        formData.append('items[0][preferred_brand]', form.querySelector('[name="preferred_brand"]').value);
        formData.append('items[0][quantity]', '1');

        fetch('api/print-order.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok && data.results && data.results[0] && data.results[0].status === 'ok') {
                submitBtn.textContent = 'Printed';
                submitBtn.classList.add('oneoff-ok');
            } else {
                submitBtn.textContent = 'Failed';
                submitBtn.classList.add('oneoff-fail');
                errorEl.textContent = (data.results && data.results[0] && data.results[0].error) || data.error || 'Print failed';
            }
            setTimeout(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Print';
                submitBtn.classList.remove('oneoff-ok', 'oneoff-fail');
            }, 3000);
        })
        .catch(function () {
            submitBtn.textContent = 'Error';
            submitBtn.classList.add('oneoff-fail');
            errorEl.textContent = 'Network error — please try again.';
            setTimeout(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Print';
                submitBtn.classList.remove('oneoff-ok', 'oneoff-fail');
            }, 3000);
        });
    }

    // ── Wire close buttons and backdrop clicks ────────────────────────────

    if (printCloseBtn) printCloseBtn.addEventListener('click', closePrintModal);
    printModal.addEventListener('click', function (e) { if (e.target === printModal) closePrintModal(); });

    if (oneoffCloseBtn) oneoffCloseBtn.addEventListener('click', closeOneoffModal);
    oneoffModal.addEventListener('click', function (e) { if (e.target === oneoffModal) closeOneoffModal(); });

    // Escape key handling
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !oneoffModal.hidden) { closeOneoffModal(); return; }
        if (e.key === 'Escape' && !printModal.hidden) { closePrintModal(); return; }
    });

    // ── Wire up one-off print buttons in a container ──────────────────────

    function wireOneoffPrintButtons(container) {
        container.querySelectorAll('.btn-oneoff-print').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openOneoffModal(btn);
            });
        });
    }

    // ── Public API ────────────────────────────────────────────────────────

    return {
        openPrintModal: openPrintModal,
        closePrintModal: closePrintModal,
        openOneoffModal: openOneoffModal,
        closeOneoffModal: closeOneoffModal,
        wireOneoffPrintButtons: wireOneoffPrintButtons,
        stripBrandPrefix: stripBrandPrefix,
        set onPrintConfirm(fn) { _onPrintConfirm = fn; },
    };
}());
</script>
