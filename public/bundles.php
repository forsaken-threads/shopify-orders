<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$db = getDb($config);

// ── Pagination for the incomplete-bundles management list ─────────────────────
//
// A bundle is "incomplete" when either no row exists in bundle_states for it
// or the row has is_complete = 0.  Only active, non-deleted bundle products
// are eligible.

$perPage     = 25;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

$whereIncomplete = "
    p.is_bundle  = 1
    AND p.status = 'active'
    AND p.deleted_at IS NULL
    AND COALESCE((SELECT is_complete FROM bundle_states WHERE product_id = p.id), 0) = 0
";

$totalCount = (int) $db->query(
    "SELECT COUNT(*) FROM products p WHERE {$whereIncomplete}"
)->fetchColumn();

$totalPages  = max(1, (int) ceil($totalCount / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$stmt = $db->prepare("
    SELECT p.id, p.shopify_product_id, p.title, p.vendor,
           (SELECT COUNT(*) FROM bundle_components bc WHERE bc.bundle_product_id = p.id) AS component_count
    FROM   products p
    WHERE  {$whereIncomplete}
    ORDER  BY p.title
    LIMIT  :limit OFFSET :offset
");
$stmt->execute([':limit' => $perPage, ':offset' => $offset]);
$bundles = $stmt->fetchAll();

// ── Complete bundles (for the lookup list) ───────────────────────────────────
//
// Kept as a simple flat list — completed bundle count stays low in practice,
// so pagination and search aren't worth the complexity.

$completeBundles = $db->query("
    SELECT p.id, p.shopify_product_id, p.title, p.vendor,
           (SELECT COUNT(*) FROM bundle_components bc WHERE bc.bundle_product_id = p.id) AS component_count
    FROM   products      p
    JOIN   bundle_states s ON s.product_id = p.id AND s.is_complete = 1
    WHERE  p.is_bundle  = 1
      AND  p.status     = 'active'
      AND  p.deleted_at IS NULL
    ORDER  BY p.title
")->fetchAll();

function bundlesPageUrl(int $page): string
{
    return '?page=' . $page;
}

$pageTitle  = 'Bundles - Cent Notes';
$activePage = 'bundles';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .bundles-wrap {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    /* ── Management table ── */
    .bundle-table-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }

    .bundle-table { width: 100%; border-collapse: collapse; }

    .bundle-table thead { background: #1a1a2e; color: #fff; }

    .bundle-table th {
        padding: .65rem 1rem;
        text-align: left;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        white-space: nowrap;
    }

    .bundle-table td {
        padding: .7rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        font-size: .875rem;
    }

    .bundle-table tbody tr:last-child td { border-bottom: none; }
    .bundle-table tbody tr:hover td { background: #fafafa; }

    .bundle-table .col-count  { width: 6rem; text-align: right; font-variant-numeric: tabular-nums; }
    /* width:1% + nowrap lets the column auto-size to fit one or two buttons. */
    .bundle-table .col-action { width: 1%; white-space: nowrap; text-align: right; }

    .btn-edit-bundle {
        display: inline-block;
        padding: .35rem .9rem;
        background: transparent;
        color: var(--accent, #4f46e5);
        border: 1px solid var(--accent, #4f46e5);
        border-radius: 6px;
        font-size: .78rem;
        font-weight: 500;
        cursor: pointer;
        transition: background .15s, color .15s;
    }

    .btn-edit-bundle:hover { background: var(--accent, #4f46e5); color: #fff; }

    .btn-print-bundle {
        display: inline-block;
        padding: .35rem .9rem;
        margin-left: .35rem;
        background: #1a1a2e;
        color: #fff;
        border: 1px solid #1a1a2e;
        border-radius: 6px;
        font-size: .78rem;
        font-weight: 500;
        cursor: pointer;
        transition: background .15s;
    }

    .btn-print-bundle:hover { background: #2d2d5e; border-color: #2d2d5e; }

    .empty-state-bundles {
        padding: 2.5rem 1rem;
        text-align: center;
        color: #777;
        font-size: .9rem;
    }

    /* ── Bundle Lookup (mirrors reports.php search) ── */
    .bundle-search-wrap { position: relative; max-width: 480px; margin-top: 1rem; }
    .bundle-search-input-row { position: relative; display: flex; align-items: center; }

    .bundle-search-input {
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

    .bundle-search-input:focus {
        border-color: #1a1a2e;
        box-shadow: 0 0 0 3px rgba(26,26,46,.08);
    }

    .bundle-search-icon {
        position: absolute;
        left: .8rem;
        color: #aaa;
        pointer-events: none;
    }

    .bundle-search-icon svg {
        width: 1rem; height: 1rem;
        fill: none; stroke: currentColor;
        stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        display: block;
    }

    .bundle-search-dropdown {
        position: absolute;
        top: calc(100% + 4px);
        left: 0; right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,.10);
        z-index: 100;
        max-height: 40vh;
        overflow-y: auto;
        display: none;
    }

    .bundle-search-dropdown.visible { display: block; }

    .bundle-dropdown-item {
        padding: .65rem 1rem;
        cursor: pointer;
        transition: background .1s;
        border-bottom: 1px solid #f5f5f5;
        font-size: .875rem;
        font-weight: 500;
        color: #1a1a2e;
    }

    .bundle-dropdown-item:last-child { border-bottom: none; }
    .bundle-dropdown-item:hover,
    .bundle-dropdown-item.focused { background: #f0f2f5; }

    .bundle-dropdown-empty {
        padding: .85rem 1rem;
        font-size: .85rem;
        color: #aaa;
        text-align: center;
    }

    /* ── Shared modal body styles for the edit modal ── */
    /* overflow:visible lets the attach-search dropdown extend past the modal's
       bottom edge; the shared .modal-box rule in print-modals.php clips by default. */
    #bundle-edit-modal .modal-box   { max-width: 640px; width: 92vw; overflow: visible; }
    #bundle-print-modal .modal-box  { width: min(1100px, 100%); }

    /* Bundle print modal uses its own tighter qty cell style. */
    #bundle-print-modal td.qty { white-space: nowrap; font-variant-numeric: tabular-nums; }
    #bundle-print-modal select {
        padding: .35rem .5rem;
        border: 1px solid var(--border, #d1d5db);
        border-radius: 4px;
        font-size: .85rem;
        font-family: inherit;
        color: var(--text, #111827);
        background: #fff;
    }

    /* ── Bundle name label row (always the top row of the print form) ── */
    #bundle-print-modal .bp-bundle-name-row td { background: #f5f7ff; }
    #bundle-print-modal .bp-line-label {
        display: block;
        font-size: .65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #6b7280;
        margin-bottom: .2rem;
    }
    #bundle-print-modal .bp-ml-empty {
        text-align: center;
        color: #aaa;
        font-size: .85rem;
    }

    .be-section { padding: 1rem 1.25rem; border-top: 1px solid #f0f0f0; }
    .be-section:first-of-type { border-top: none; }

    .be-section h3 {
        font-size: .78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #888;
        margin-bottom: .65rem;
    }

    .be-component-list { list-style: none; padding: 0; margin: 0; }

    .be-component-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .5rem .6rem;
        border-radius: 6px;
        font-size: .875rem;
        border: 1px solid transparent;
    }

    .be-component-list li:hover { background: #fafafa; border-color: #f0f0f0; }

    .be-component-title { flex: 1; color: #1a1a2e; }
    .be-component-vendor { font-size: .75rem; color: #888; margin-left: .5rem; }

    .be-btn-detach {
        padding: .3rem .7rem;
        font-size: .75rem;
        background: transparent;
        color: #b91c1c;
        border: 1px solid #fca5a5;
        border-radius: 5px;
        cursor: pointer;
        transition: background .15s, color .15s;
    }

    .be-btn-detach:hover { background: #b91c1c; color: #fff; }
    .be-btn-detach:disabled { opacity: .45; cursor: default; }

    .be-empty { font-size: .85rem; color: #aaa; padding: .25rem; }

    /* Modal footer buttons */
    .be-footer {
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
        padding: 1rem 1.25rem;
        border-top: 1px solid #f0f0f0;
        background: #fafafa;
    }

    .btn-secondary {
        padding: .45rem 1rem;
        background: #fff;
        color: #374151;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .85rem;
        font-weight: 500;
        cursor: pointer;
        transition: background .15s;
    }

    .btn-secondary:hover { background: #f3f4f6; }

    .btn-primary {
        padding: .45rem 1rem;
        background: #166534;
        color: #fff;
        border: 1px solid #166534;
        border-radius: 6px;
        font-size: .85rem;
        font-weight: 500;
        cursor: pointer;
        transition: opacity .15s;
    }

    .btn-primary:hover { opacity: .9; }
    .btn-primary:disabled { opacity: .5; cursor: default; }
</style>

<div class="bundles-wrap">
    <div class="page-header">
        <h1>Bundles</h1>
        <span class="subtitle">Curate components and print bundle labels</span>
    </div>

    <div class="accordion" id="accordion">

        <!-- ── Card 1: Bundle Management ──────────────────────────────────── -->
        <div class="accordion-card" id="card-manage">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-manage"
                 onclick="toggleAccordion('card-manage')">
                <div class="accordion-header-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Bundle Management</h2>
                    <p>Review incomplete bundles and curate their component associations.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-manage">
                <?php if (empty($bundles)): ?>
                    <div class="empty-state-bundles">
                        <strong>No incomplete bundles.</strong>
                        <p>All active bundles have been marked complete.</p>
                    </div>
                <?php else: ?>
                    <div class="bundle-table-wrap">
                        <table class="bundle-table">
                            <thead>
                                <tr>
                                    <th>Bundle</th>
                                    <th class="hide-mobile">Vendor</th>
                                    <th class="col-count">Components</th>
                                    <th class="col-action"></th>
                                </tr>
                            </thead>
                            <tbody id="bundles-rows">
                            <?php foreach ($bundles as $b): ?>
                                <tr data-bundle-id="<?= (int) $b['id'] ?>">
                                    <td><?= h($b['title']) ?></td>
                                    <td class="hide-mobile"><?= h($b['vendor'] ?? '') ?></td>
                                    <td class="col-count"><?= (int) $b['component_count'] ?></td>
                                    <td class="col-action">
                                        <button class="btn-edit-bundle"
                                                data-bundle-id="<?= (int) $b['id'] ?>"
                                                data-bundle-title="<?= h($b['title']) ?>">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            <?php
                            $firstItem = $offset + 1;
                            $lastItem  = min($offset + $perPage, $totalCount);
                            echo "Showing {$firstItem}–{$lastItem} of {$totalCount} incomplete bundles";
                            ?>
                        </div>
                        <div class="pagination-controls">
                            <a class="page-link<?= $currentPage <= 1 ? ' disabled' : '' ?>"
                               href="<?= bundlesPageUrl($currentPage - 1) ?>">&#8592; Prev</a>
                            <?php
                            $window = [];
                            for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++) {
                                $window[] = $p;
                            }
                            if (!in_array(1, $window, true)) {
                                echo '<a class="page-link" href="' . bundlesPageUrl(1) . '">1</a>';
                                if (!in_array(2, $window, true)) echo '<span class="page-ellipsis">&hellip;</span>';
                            }
                            foreach ($window as $p) {
                                $active = $p === $currentPage ? ' active' : '';
                                echo '<a class="page-link' . $active . '" href="' . bundlesPageUrl($p) . '">' . $p . '</a>';
                            }
                            if (!in_array($totalPages, $window, true)) {
                                if (!in_array($totalPages - 1, $window, true)) echo '<span class="page-ellipsis">&hellip;</span>';
                                echo '<a class="page-link" href="' . bundlesPageUrl($totalPages) . '">' . $totalPages . '</a>';
                            }
                            ?>
                            <a class="page-link<?= $currentPage >= $totalPages ? ' disabled' : '' ?>"
                               href="<?= bundlesPageUrl($currentPage + 1) ?>">Next &#8594;</a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Card 2: Bundle Lookup ─────────────────────────────────────── -->
        <div class="accordion-card" id="card-lookup">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-lookup"
                 onclick="toggleAccordion('card-lookup')">
                <div class="accordion-header-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Bundle Lookup</h2>
                    <p>Find a completed bundle and print labels for its components.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-lookup">
                <?php if (empty($completeBundles)): ?>
                    <div class="empty-state-bundles">
                        <strong>No completed bundles yet.</strong>
                        <p>Curate a bundle's components in Bundle Management and mark it complete to see it here.</p>
                    </div>
                <?php else: ?>
                    <div class="bundle-table-wrap">
                        <table class="bundle-table">
                            <thead>
                                <tr>
                                    <th>Bundle</th>
                                    <th class="hide-mobile">Vendor</th>
                                    <th class="col-count">Components</th>
                                    <th class="col-action"></th>
                                </tr>
                            </thead>
                            <tbody id="complete-bundles-rows">
                            <?php foreach ($completeBundles as $b): ?>
                                <tr data-bundle-id="<?= (int) $b['id'] ?>">
                                    <td><?= h($b['title']) ?></td>
                                    <td class="hide-mobile"><?= h($b['vendor'] ?? '') ?></td>
                                    <td class="col-count"><?= (int) $b['component_count'] ?></td>
                                    <td class="col-action">
                                        <button class="btn-edit-bundle btn-reopen-bundle"
                                                data-bundle-id="<?= (int) $b['id'] ?>"
                                                data-bundle-title="<?= h($b['title']) ?>">
                                            Edit
                                        </button>
                                        <button class="btn-print-bundle"
                                                data-bundle-id="<?= (int) $b['id'] ?>"
                                                data-bundle-title="<?= h($b['title']) ?>">
                                            Print
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ── Edit Bundle Modal ───────────────────────────────────────────────────── -->
<div id="bundle-edit-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-label="Edit bundle components">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="be-title">Edit Bundle</span>
            <button class="modal-close" id="be-close" aria-label="Close">&times;</button>
        </div>

        <div class="be-section">
            <h3>Currently Attached</h3>
            <ul class="be-component-list" id="be-components"></ul>
        </div>

        <div class="be-section">
            <h3>Attach a Product</h3>
            <div class="bundle-search-wrap" id="be-search-wrap">
                <div class="bundle-search-input-row">
                    <span class="bundle-search-icon">
                        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="text"
                           class="bundle-search-input"
                           id="be-search-input"
                           placeholder="Search products to attach…"
                           autocomplete="off"
                           spellcheck="false">
                </div>
                <div class="bundle-search-dropdown" id="be-dropdown" role="listbox"></div>
            </div>
        </div>

        <div class="be-footer">
            <button class="btn-secondary" id="be-btn-close">Close</button>
            <button class="btn-primary"   id="be-btn-complete">Mark Complete</button>
        </div>
    </div>
</div>

<!-- ── Bundle Print Modal ──────────────────────────────────────────────────── -->
<div id="bundle-print-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-label="Print bundle labels">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="bp-title">Print Bundle Labels</span>
            <button class="modal-close" id="bp-close" aria-label="Close">&times;</button>
        </div>
        <div id="bp-loading" class="modal-fetch-loading" hidden>
            <div class="detail-spinner"></div>
            Loading components…
        </div>
        <div id="bp-body" class="print-modal-body"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // Shared utilities (escHtml and toggleAccordion come from header.php).
    // ─────────────────────────────────────────────────────────────────────────

    function postForm(url, params) {
        const body = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => body.append(k, String(v)));
        return fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: body.toString(),
        }).then(r => r.json());
    }

    function openModal(el)  { el.hidden = false; document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.hidden = true;  document.body.style.overflow = ''; }

    // Reload the page while preserving which accordion card is currently open.
    // The open card's id is pushed into location.hash before reload; on page
    // load (below) we read the hash and re-open that card.
    function reloadPreservingAccordion() {
        const open = document.querySelector('.accordion-card.open');
        if (open && open.id) window.location.hash = open.id;
        window.location.reload();
    }

    // On initial page load, restore accordion state from hash if present.
    (function restoreAccordion() {
        const hash = window.location.hash.slice(1);
        if (!hash) return;
        const card = document.getElementById(hash);
        if (card && !card.classList.contains('open')) toggleAccordion(hash);
    }());

    // ─────────────────────────────────────────────────────────────────────────
    // Edit Bundle Modal
    // ─────────────────────────────────────────────────────────────────────────

    const editModal      = document.getElementById('bundle-edit-modal');
    const editTitle      = document.getElementById('be-title');
    const editList       = document.getElementById('be-components');
    const editSearch     = document.getElementById('be-search-input');
    const editDropdown   = document.getElementById('be-dropdown');
    const editSearchWrap = document.getElementById('be-search-wrap');

    let editBundleId = null;
    let editDirty    = false;   // tracks whether components changed while modal open

    function renderEditComponentList(components) {
        editList.innerHTML = '';
        if (!components.length) {
            editList.innerHTML = '<li class="be-empty">No components attached.</li>';
            return;
        }
        components.forEach(c => {
            const li = document.createElement('li');
            li.innerHTML =
                '<span class="be-component-title">' + escHtml(c.title) +
                (c.vendor ? '<span class="be-component-vendor">' + escHtml(c.vendor) + '</span>' : '') +
                '</span>';
            const btn = document.createElement('button');
            btn.className = 'be-btn-detach';
            btn.textContent = 'Detach';
            btn.addEventListener('click', () => handleDetach(c.id, btn));
            li.appendChild(btn);
            editList.appendChild(li);
        });
    }

    function refreshEditComponents() {
        return fetch('api/bundle-components.php?id=' + encodeURIComponent(editBundleId))
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                renderEditComponentList(data.components);
            });
    }

    function openEditModal(bundleId, title, initialDirty = false) {
        editBundleId = bundleId;
        editDirty    = initialDirty;
        editTitle.textContent = 'Edit: ' + title;
        editList.innerHTML    = '<li class="be-empty">Loading…</li>';
        editSearch.value      = '';
        editDropdown.classList.remove('visible');
        editDropdown.innerHTML = '';
        openModal(editModal);
        refreshEditComponents().catch(err => {
            editList.innerHTML = '<li class="be-empty" style="color:#b91c1c">Failed: ' + escHtml(err.message) + '</li>';
        });
    }

    function closeEditModal() {
        closeModal(editModal);
        // If components changed during the session, reload the page so counts and
        // "incomplete" filter reflect the new state.  Mark-complete handles its
        // own full-page reload below.
        if (editDirty) reloadPreservingAccordion();
    }

    function handleDetach(componentId, btn) {
        btn.disabled = true;
        btn.textContent = 'Detaching…';
        postForm('api/bundle-detach.php', { bundle_id: editBundleId, component_id: componentId })
            .then(data => {
                if (!data.ok) throw new Error(data.error || 'Detach failed');
                editDirty = true;
                return refreshEditComponents();
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Detach';
                alert('Could not detach: ' + err.message);
            });
    }

    function handleAttach(componentId) {
        postForm('api/bundle-attach.php', { bundle_id: editBundleId, component_id: componentId })
            .then(data => {
                if (!data.ok) throw new Error(data.error || 'Attach failed');
                editDirty = true;
                editSearch.value = '';
                editDropdown.classList.remove('visible');
                editDropdown.innerHTML = '';
                return refreshEditComponents();
            })
            .catch(err => {
                alert('Could not attach: ' + err.message);
            });
    }

    function handleMarkComplete() {
        const btn = document.getElementById('be-btn-complete');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        postForm('api/bundle-mark-complete.php', { id: editBundleId })
            .then(data => {
                if (!data.ok) throw new Error(data.error || 'Mark complete failed');
                reloadPreservingAccordion();
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Mark Complete';
                alert('Could not mark complete: ' + err.message);
            });
    }

    document.getElementById('be-close').addEventListener('click',    closeEditModal);
    document.getElementById('be-btn-close').addEventListener('click', closeEditModal);
    document.getElementById('be-btn-complete').addEventListener('click', handleMarkComplete);

    editModal.addEventListener('click', e => { if (e.target === editModal) closeEditModal(); });

    // Wire up each row's Edit button on page load.
    document.querySelectorAll('.btn-edit-bundle').forEach(btn => {
        btn.addEventListener('click', () => {
            openEditModal(Number(btn.dataset.bundleId), btn.dataset.bundleTitle);
        });
    });

    // ─────────────────────────────────────────────────────────────────────────
    // Attach-search (inside edit modal) — live search with debounce
    // ─────────────────────────────────────────────────────────────────────────

    makeLiveSearch({
        input:      editSearch,
        dropdown:   editDropdown,
        wrap:       editSearchWrap,
        buildUrl:   q => 'api/product-search.php?mode=attach&bundle_id=' + encodeURIComponent(editBundleId) +
                         '&q=' + encodeURIComponent(q),
        onSelect:   product => handleAttach(product.id),
        renderItem: p => {
            const el = document.createElement('div');
            el.className = 'bundle-dropdown-item';
            el.textContent = p.title;
            return el;
        },
    });

    // ─────────────────────────────────────────────────────────────────────────
    // Bundle Lookup — server-rendered list of completed bundles with per-row
    // Edit (reopens) and Print buttons.
    // ─────────────────────────────────────────────────────────────────────────

    // Edit button in the completed list — reopens the bundle (is_complete → 0)
    // and opens the edit modal with initialDirty=true so the page reloads on
    // close and the reopened bundle reappears in the Management list.
    document.querySelectorAll('.btn-reopen-bundle').forEach(btn => {
        btn.addEventListener('click', () => {
            const id    = Number(btn.dataset.bundleId);
            const title = btn.dataset.bundleTitle;
            btn.disabled = true;
            btn.textContent = 'Reopening…';
            postForm('api/bundle-reopen.php', { id: id })
                .then(data => {
                    if (!data.ok) throw new Error(data.error || 'Reopen failed');
                    openEditModal(id, title, true);
                })
                .catch(err => { alert('Could not reopen: ' + err.message); })
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = 'Edit';
                });
        });
    });

    // Print button in the completed list — opens the bundle print modal directly.
    document.querySelectorAll('.btn-print-bundle').forEach(btn => {
        btn.addEventListener('click', () => {
            openBundlePrintModal({
                id:    Number(btn.dataset.bundleId),
                title: btn.dataset.bundleTitle,
            });
        });
    });

    // ─────────────────────────────────────────────────────────────────────────
    // Bundle Print Modal
    //
    // Mirrors the order-print flow's print/retry/review machinery but without
    // the pending→printed confirm stage (bundles track completion separately
    // via bundle_states).  If all labels print OK on first submit, the modal
    // closes; otherwise the failed rows enter the shared retry UI.
    // ─────────────────────────────────────────────────────────────────────────

    const bundlePrintModal = document.getElementById('bundle-print-modal');
    const bpTitle          = document.getElementById('bp-title');
    const bpBody           = document.getElementById('bp-body');
    const bpLoading        = document.getElementById('bp-loading');

    let bpActiveBundleId   = null;

    function openBundlePrintModal(bundle) {
        bpActiveBundleId = bundle.id;
        bpTitle.textContent = 'Print Bundle Labels — ' + bundle.title;
        bpBody.innerHTML = '';
        bpLoading.hidden = false;
        openModal(bundlePrintModal);

        fetch('api/bundle-components.php?id=' + encodeURIComponent(bundle.id) + '&include_variants=1')
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                bpLoading.hidden = true;
                bpBody.innerHTML = renderBundlePrintForm(data);
                const cancel = document.getElementById('bp-cancel-btn');
                if (cancel) cancel.addEventListener('click', closeBundlePrintModal);
                const form = document.getElementById('bp-form');
                if (form) form.addEventListener('submit', handleBundlePrintSubmit);
            })
            .catch(err => {
                bpLoading.hidden = true;
                bpBody.innerHTML =
                    '<p style="color:#b91c1c;font-size:.85rem;padding:.5rem 0;">Failed to load components: ' +
                    escHtml(err.message) + '</p>';
            });
    }

    function closeBundlePrintModal() {
        closeModal(bundlePrintModal);
        bpActiveBundleId = null;
    }

    document.getElementById('bp-close').addEventListener('click', closeBundlePrintModal);
    // Intentionally no backdrop click close on the print modal — matches PrintModals' behavior.

    function renderBundlePrintForm(data) {
        const esc    = escHtml;
        const bundle = data.bundle;
        const comps  = data.components;

        if (!comps || comps.length === 0) {
            return '<p style="color:#888;font-size:.85rem;">This bundle has no attached components.</p>';
        }

        // ── Bundle name label row (always index 0) ──────────────────────────
        //
        // The user supplies two lines for the bundle name label; the remote
        // print-label.py Bundle path treats title=Line 1, brand=Line 2.
        // Defaults: Line 1 = the bundle's saved preferred_title (full bundle
        // title on first use, no stripBrandPrefix), Line 2 = saved
        // preferred_brand (empty on first use — the user is expected to
        // manually split the name once and save).
        const bundleLine1 = bundle.preferred_title != null && bundle.preferred_title !== ''
            ? bundle.preferred_title
            : bundle.title;
        const bundleLine2 = bundle.preferred_brand != null ? bundle.preferred_brand : '';
        const bundlePreferredTitle = bundle.preferred_title != null ? bundle.preferred_title : '';
        const bundlePreferredBrand = bundle.preferred_brand != null ? bundle.preferred_brand : '';

        const bundleNameRow =
            '<tr class="bp-bundle-name-row" data-item-index="0">' +
                '<td class="print-retry-col" hidden>' +
                    '<input type="checkbox" class="print-retry-cb" data-index="0">' +
                '</td>' +
                '<td class="print-status-col" hidden></td>' +
                '<td>' +
                    '<span class="bp-line-label">Line 1</span>' +
                    '<input type="text" name="items[0][title]" value="' + esc(bundleLine1) + '">' +
                    '<input type="hidden" name="items[0][full_title]" value="' + esc(bundle.title) + '">' +
                    '<input type="hidden" name="items[0][shopify_product_id]" value="' + esc(bundle.shopify_product_id || '') + '">' +
                    '<input type="hidden" name="items[0][preferred_title]" value="' + esc(bundlePreferredTitle) + '">' +
                    '<input type="hidden" name="items[0][ml]" value="bundle">' +
                '</td>' +
                '<td>' +
                    '<span class="bp-line-label">Line 2</span>' +
                    '<input type="text" name="items[0][custom_brand]" value="' + esc(bundleLine2) + '">' +
                    '<input type="hidden" name="items[0][original_brand]" value="' + esc(bundleLine2) + '">' +
                    '<input type="hidden" name="items[0][preferred_brand]" value="' + esc(bundlePreferredBrand) + '">' +
                '</td>' +
                '<td class="bp-ml-empty">—</td>' +
                '<td class="qty">1' +
                    '<input type="hidden" name="items[0][quantity]" value="1">' +
                '</td>' +
                '<td class="skip-persist-col">' +
                    '<input type="checkbox" class="skip-persist-item-cb" name="items[0][save_edits]" value="1" checked>' +
                '</td>' +
            '</tr>';

        // ── Component rows (indices 1..N) ───────────────────────────────────
        const componentRows = comps.map((c, idx) => {
            const i             = idx + 1;  // shift past the bundle name row
            const brand         = c.preferred_brand != null ? c.preferred_brand : (c.custom_brand || '');
            const displayTitle  = c.preferred_title != null
                ? c.preferred_title
                : PrintModals.stripBrandPrefix(c.title, c.custom_brand || '');
            const preferredTitle = c.preferred_title != null ? c.preferred_title : '';
            const preferredBrand = c.preferred_brand != null ? c.preferred_brand : '';

            let mlOptions;
            if (c.ml_variants && c.ml_variants.length > 0) {
                const defaultMl = c.ml_variants[0];
                mlOptions = c.ml_variants.map(m =>
                    '<option value="' + m + '"' + (m === defaultMl ? ' selected' : '') + '>' + m + 'ml</option>'
                ).join('');
            } else {
                mlOptions =
                    '<option value="" disabled selected>Select…</option>' +
                    '<option value="1">1ml</option>' +
                    '<option value="5">5ml</option>' +
                    '<option value="10">10ml</option>';
            }

            return '<tr data-item-index="' + i + '">' +
                '<td class="print-retry-col" hidden>' +
                    '<input type="checkbox" class="print-retry-cb" data-index="' + i + '">' +
                '</td>' +
                '<td class="print-status-col" hidden></td>' +
                '<td>' +
                    '<input type="text" name="items[' + i + '][title]" value="' + esc(displayTitle) + '">' +
                    '<input type="hidden" name="items[' + i + '][full_title]" value="' + esc(c.title) + '">' +
                    '<input type="hidden" name="items[' + i + '][shopify_product_id]" value="' + esc(c.shopify_product_id || '') + '">' +
                    '<input type="hidden" name="items[' + i + '][preferred_title]" value="' + esc(preferredTitle) + '">' +
                '</td>' +
                '<td>' +
                    '<input type="text" name="items[' + i + '][custom_brand]" value="' + esc(brand) + '">' +
                    '<input type="hidden" name="items[' + i + '][original_brand]" value="' + esc(brand) + '">' +
                    '<input type="hidden" name="items[' + i + '][preferred_brand]" value="' + esc(preferredBrand) + '">' +
                '</td>' +
                '<td>' +
                    '<select name="items[' + i + '][ml]" required>' + mlOptions + '</select>' +
                '</td>' +
                '<td class="qty">1' +
                    '<input type="hidden" name="items[' + i + '][quantity]" value="1">' +
                '</td>' +
                '<td class="skip-persist-col">' +
                    '<input type="checkbox" class="skip-persist-item-cb" name="items[' + i + '][save_edits]" value="1" checked>' +
                '</td>' +
                '</tr>';
        }).join('');

        const totalLabels = comps.length + 1;  // +1 for the bundle name label

        return '<form id="bp-form">' +
            '<input type="hidden" name="bundle_id" value="' + esc(String(bundle.id)) + '">' +
            '<input type="hidden" name="action" value="bundle">' +
            '<table><thead><tr>' +
                '<th class="print-retry-col" hidden>' +
                    '<input type="checkbox" class="print-retry-cb print-check-all" title="Check all">' +
                '</th>' +
                '<th class="print-status-col" hidden></th>' +
                '<th>Product Title</th><th>Brand</th><th>ML</th><th>Qty</th>' +
                '<th class="skip-persist-col">Save</th>' +
            '</tr></thead><tbody>' + bundleNameRow + componentRows + '</tbody></table>' +
            '<div class="print-modal-footer">' +
                '<span class="print-total-qty">Total labels: <strong>' + totalLabels + '</strong></span>' +
                '<span class="print-error" id="bp-error"></span>' +
                '<button type="button" class="btn-print-cancel" id="bp-cancel-btn">Cancel</button>' +
                '<button type="submit" class="btn-print-submit" id="bp-submit-btn">Print Labels</button>' +
            '</div>' +
            '</form>';
    }

    function bpUpdateButton() {
        const form = document.getElementById('bp-form');
        const btn  = document.getElementById('bp-submit-btn');
        if (!form || !btn) return;
        const anyChecked = form.querySelector('.print-retry-cb:checked:not(.print-check-all)');
        btn.textContent = anyChecked ? 'Retry' : 'Close';
        btn.disabled = false;
        const checkAll = form.querySelector('.print-check-all');
        if (checkAll) {
            const all = form.querySelectorAll('.print-retry-cb:not(.print-check-all)');
            checkAll.checked = all.length > 0 && Array.prototype.every.call(all, cb => cb.checked);
        }
    }

    function bpWireCheckAll(form) {
        const checkAll = form.querySelector('.print-check-all');
        if (!checkAll) return;
        checkAll.addEventListener('change', () => {
            form.querySelectorAll('.print-retry-cb:not(.print-check-all)').forEach(cb => {
                cb.checked = checkAll.checked;
            });
            bpUpdateButton();
        });
    }

    function bpEnterReviewStage(results) {
        const form = document.getElementById('bp-form');
        if (!form) return;
        const hasFailures = results.some(r => r.status === 'error');

        if (!hasFailures) {
            // All labels printed successfully on first try — done.
            closeBundlePrintModal();
            return;
        }

        form.querySelectorAll('.print-retry-col, .print-status-col').forEach(el => { el.hidden = false; });

        results.forEach(r => {
            const row = form.querySelector('tr[data-item-index="' + r.index + '"]');
            if (!row) return;
            const statusCell = row.querySelector('.print-status-col');
            const cb = row.querySelector('.print-retry-cb');
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

        form.querySelectorAll('.print-retry-cb:not(.print-check-all)').forEach(cb => {
            cb.addEventListener('change', bpUpdateButton);
        });
        bpWireCheckAll(form);
        bpUpdateButton();
    }

    function bpEnterNetworkRetryMode() {
        const form = document.getElementById('bp-form');
        if (!form) return;
        form.querySelectorAll('.print-retry-col, .print-status-col').forEach(el => { el.hidden = false; });
        form.querySelectorAll('tr[data-item-index]').forEach(row => {
            const statusCell = row.querySelector('.print-status-col');
            const cb = row.querySelector('.print-retry-cb');
            row.classList.remove('print-row-error', 'print-row-ok');
            row.classList.add('print-row-error');
            if (statusCell) statusCell.innerHTML = '<span class="label-fail">?</span>';
            if (cb) cb.checked = false;
        });
        form.querySelectorAll('.print-retry-cb:not(.print-check-all)').forEach(cb => {
            cb.addEventListener('change', bpUpdateButton);
        });
        bpWireCheckAll(form);
        bpUpdateButton();
    }

    function handleBundlePrintSubmit(e) {
        e.preventDefault();
        const form    = e.target;
        const submit  = document.getElementById('bp-submit-btn');
        const errorEl = document.getElementById('bp-error');
        const inReview = !form.querySelector('.print-retry-col').hidden;

        // In review mode with nothing checked → nothing to retry; close the modal.
        if (inReview) {
            const anyChecked = form.querySelector('.print-retry-cb:checked:not(.print-check-all)');
            if (!anyChecked) { closeBundlePrintModal(); return; }

            // Reset visual state on retried rows before resubmitting.
            form.querySelectorAll('.print-retry-cb:checked').forEach(cb => {
                const row = cb.closest('tr');
                row.classList.remove('print-row-error', 'print-row-ok');
                row.querySelector('.print-status-col').innerHTML = '';
            });
        }

        submit.disabled = true;
        submit.textContent = 'Printing…';
        errorEl.textContent = '';

        const formData = new FormData();
        formData.append('action',    'bundle');
        formData.append('bundle_id', form.querySelector('[name="bundle_id"]').value);

        const rows = form.querySelectorAll('tr[data-item-index]');
        let sendIndex = 0;
        let labelCount = 0;

        rows.forEach(row => {
            const idx = row.getAttribute('data-item-index');
            if (inReview) {
                const cb = row.querySelector('.print-retry-cb');
                if (!cb || !cb.checked) return;
            }
            row.querySelectorAll('[name^="items[' + idx + ']"]').forEach(input => {
                if (input.classList.contains('skip-persist-item-cb')) return;
                const newName = input.name.replace('items[' + idx + ']', 'items[' + sendIndex + ']');
                formData.append(newName, input.value);
            });
            const saveCb = row.querySelector('.skip-persist-item-cb');
            if (saveCb && saveCb.checked) {
                formData.append('items[' + sendIndex + '][save_edits]', '1');
            }
            formData.append('_row_map[' + sendIndex + ']', idx);

            const qtyInput = row.querySelector('input[name$="[quantity]"]');
            labelCount += qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
            sendIndex++;
        });

        // Scale timeout to label count — same heuristic as the order flow.
        const timeoutMs = Math.max(60000, 30000 + labelCount * 20000);
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), timeoutMs);

        fetch('api/print-order.php', {
            method:  'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body:    formData,
            signal:  controller.signal,
        })
            .then(r => r.json())
            .then(data => {
                clearTimeout(timeoutId);
                if (!data.ok) {
                    errorEl.textContent = data.error || 'Unknown error';
                    submit.disabled = false;
                    submit.textContent = inReview ? 'Retry' : 'Print Labels';
                    return;
                }
                const mapped = (data.results || []).map(r => {
                    const origIdx = formData.get('_row_map[' + r.index + ']');
                    return { index: origIdx != null ? Number(origIdx) : r.index, title: r.title, status: r.status, error: r.error };
                });

                if (inReview) {
                    rows.forEach(row => {
                        const idx = Number(row.getAttribute('data-item-index'));
                        const retried = mapped.find(m => m.index === idx);
                        if (!retried) return;
                        const statusCell = row.querySelector('.print-status-col');
                        const cb = row.querySelector('.print-retry-cb');
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
                    });
                    bpUpdateButton();
                    // After retry: if nothing still failing, dismiss automatically.
                    // (Orders need a confirm click; bundles don't.)
                    if (!form.querySelector('tr.print-row-error')) closeBundlePrintModal();
                } else {
                    bpEnterReviewStage(mapped);
                }
            })
            .catch(err => {
                clearTimeout(timeoutId);
                errorEl.textContent = err && err.name === 'AbortError'
                    ? 'Request timed out — some labels may have printed. Select any that need reprinting.'
                    : 'Network error — select the labels you need to reprint.';
                if (!inReview) {
                    bpEnterNetworkRetryMode();
                } else {
                    submit.disabled = false;
                    submit.textContent = 'Retry';
                }
            });
    }

    // Global Escape closes the edit modal if open.
    // Intentionally omits bundlePrintModal — matches PrintModals' no-Escape policy
    // for the full print modal (prevents accidental close while editing a label).
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !editModal.hidden) closeEditModal();
    });

    // ─────────────────────────────────────────────────────────────────────────
    // Reusable live-search wiring: debounce, abort in-flight, keyboard nav.
    // ─────────────────────────────────────────────────────────────────────────

    function makeLiveSearch(opts) {
        const { input, dropdown, wrap, buildUrl, onSelect, renderItem } = opts;
        let debounce  = null;
        let abortCtrl = null;
        let items     = [];
        let focused   = -1;

        function hide()  { dropdown.classList.remove('visible'); dropdown.innerHTML = ''; items = []; focused = -1; }
        function setFocus(i) {
            const rows = dropdown.querySelectorAll('.bundle-dropdown-item');
            rows.forEach((r, idx) => r.classList.toggle('focused', idx === i));
            focused = i;
        }

        function run(q) {
            if (abortCtrl) abortCtrl.abort();
            abortCtrl = new AbortController();
            fetch(buildUrl(q), { signal: abortCtrl.signal })
                .then(r => r.json())
                .then(render)
                .catch(err => { if (err.name !== 'AbortError') hide(); });
        }

        function render(results) {
            items   = results;
            focused = -1;
            dropdown.innerHTML = '';
            if (!results.length) {
                dropdown.innerHTML = '<div class="bundle-dropdown-empty">No matches.</div>';
            } else {
                results.forEach((r, i) => {
                    const el = renderItem(r);
                    el.addEventListener('mousedown', e => { e.preventDefault(); onSelect(r); });
                    el.addEventListener('mousemove', () => setFocus(i));
                    dropdown.appendChild(el);
                });
            }
            dropdown.classList.add('visible');
        }

        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearTimeout(debounce);
            if (q.length < 2) { hide(); return; }
            debounce = setTimeout(() => run(q), 300);
        });

        input.addEventListener('keydown', e => {
            if (!dropdown.classList.contains('visible')) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); setFocus(Math.min(focused + 1, items.length - 1)); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); setFocus(Math.max(focused - 1, 0)); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (focused >= 0) onSelect(items[focused]); }
            else if (e.key === 'Escape')    { hide(); }
        });

        document.addEventListener('click', e => {
            if (!wrap.contains(e.target)) hide();
        });
    }
}());
</script>

<?php
// Pulls in .modal-overlay / .modal-box / .modal-header / .modal-close CSS that
// this page's edit and lookup modals rely on; also defines the PrintModals JS
// namespace which block 3 will consume for stripBrandPrefix and print wiring.
require __DIR__ . '/../app/partials/print-modals.php';
require __DIR__ . '/../app/partials/footer.php';
?>
