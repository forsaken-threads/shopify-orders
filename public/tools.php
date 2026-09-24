<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/permissions.php';

requirePermission($config, 'tools');

$pageTitle  = 'Tools - Cent Notes';
$activePage = 'tools';
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    /* ── Tools page layout ── */
    .tools-wrap {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    .tool-controls {
        display: flex;
        align-items: center;
        gap: .6rem;
        flex-wrap: wrap;
        margin-top: 1.25rem;
    }

    .tool-btn {
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

    .tool-btn:hover { background: #2d2d5e; }
    .tool-btn:disabled { opacity: .5; cursor: default; }

    .tool-btn.secondary { background: #fff; color: #1a1a2e; border: 1px solid #d1d5db; }
    .tool-btn.secondary:hover { background: #f5f5f5; }

    .tool-note { font-size: .8rem; color: #888; margin-top: .75rem; line-height: 1.45; }

    .tool-loading {
        display: none;
        align-items: center;
        gap: .6rem;
        padding: 1.25rem 0;
        font-size: .875rem;
        color: #888;
    }

    .tool-loading.visible { display: flex; }

    .tool-error {
        display: none;
        padding: .8rem 1rem;
        background: #fff5f5;
        border: 1px solid #fca5a5;
        border-radius: 7px;
        color: #b91c1c;
        font-size: .85rem;
        margin-top: 1rem;
    }

    .tool-error.visible { display: block; }

    /* ── Printer check ── */
    .pc-steps { list-style: none; margin-top: 1.25rem; display: flex; flex-direction: column; gap: .6rem; }
    .pc-steps:empty { display: none; }

    .pc-step {
        display: grid;
        grid-template-columns: 5.5rem 1fr auto;
        gap: .25rem 1rem;
        align-items: baseline;
        padding: .7rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: .85rem;
    }

    .pc-badge {
        justify-self: start;
        padding: .15rem .55rem;
        border-radius: 999px;
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .pc-badge.pass    { background: #dcfce7; color: #166534; }
    .pc-badge.fail    { background: #fee2e2; color: #991b1b; }
    .pc-badge.warn    { background: #fef3c7; color: #92400e; }
    .pc-badge.info    { background: #e0e7ff; color: #3730a3; }
    .pc-badge.skipped { background: #f1f5f9; color: #64748b; }

    .pc-label   { font-weight: 600; color: #1a1a2e; }
    .pc-summary { color: #444; line-height: 1.45; margin-top: .15rem; word-break: break-word; }
    .pc-time    { color: #888; font-variant-numeric: tabular-nums; white-space: nowrap; }

    .pc-output {
        grid-column: 2 / -1;
        margin-top: .4rem;
        padding: .6rem .8rem;
        background: #1a1a2e;
        color: #e2e8f0;
        border-radius: 6px;
        font-size: .75rem;
        white-space: pre-wrap;
        word-break: break-word;
        max-height: 16rem;
        overflow: auto;
    }

    @media (max-width: 700px) {
        .tools-wrap { padding: 1rem; }
        .pc-step { grid-template-columns: 1fr auto; }
        .pc-badge { grid-column: 1 / -1; }
        .pc-output { grid-column: 1 / -1; }
    }
</style>

<div class="tools-wrap">
    <div class="page-header">
        <h1>Tools</h1>
        <span class="subtitle">Diagnostics</span>
    </div>

    <div class="accordion" id="accordion">

        <!-- ── Card 1: Printer connectivity ── -->
        <div class="accordion-card" id="card-printer-check">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-printer-check"
                 onclick="toggleAccordion('card-printer-check')">
                <div class="accordion-header-icon">
                    <!-- printer icon -->
                    <svg viewBox="0 0 24 24">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Printer connectivity</h2>
                    <p>Check every hop from Cent Notes to the Brother QL-820NWB bottle-label printer, or print a test label.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-printer-check">

                <div class="tool-controls">
                    <button type="button" class="tool-btn" id="pc-run-btn">Run checks</button>
                    <button type="button" class="tool-btn secondary" id="pc-print-btn"
                            data-confirm="Print one test label?  This uses tape.">Print test label</button>
                </div>
                <p class="tool-note">
                    The checks print nothing.  A printer can accept a connection and still fail to
                    print, so only a test label proves the whole chain.
                </p>

                <!-- Loading -->
                <div class="tool-loading" id="pc-loading">
                    <div class="spinner"></div>
                    <span id="pc-loading-text"></span>
                </div>

                <!-- Error -->
                <div class="tool-error" id="pc-error"></div>

                <!-- Results -->
                <ul class="pc-steps" id="pc-steps"></ul>

            </div><!-- /accordion-body -->
        </div><!-- /card -->

    </div><!-- /accordion -->
</div>

<script>
(function () {
    'use strict';

    // escHtml, apiUrl, toggleAccordion and CSRF_TOKEN are provided by app/partials/header.php.

    // ── Printer connectivity ────────────────────────────────────────────────────

    const runBtn    = document.getElementById('pc-run-btn');
    const printBtn  = document.getElementById('pc-print-btn');
    const loadingEl = document.getElementById('pc-loading');
    const loadingTx = document.getElementById('pc-loading-text');
    const errorEl   = document.getElementById('pc-error');
    const stepsEl   = document.getElementById('pc-steps');

    const STATUS_LABEL = { pass: 'Pass', fail: 'Fail', warn: 'Check', info: 'Info', skipped: 'Skipped' };

    function begin(text) {
        runBtn.disabled = true;
        printBtn.disabled = true;
        errorEl.classList.remove('visible');
        stepsEl.innerHTML = '';
        loadingTx.textContent = text;
        loadingEl.classList.add('visible');
    }

    function end() {
        loadingEl.classList.remove('visible');
        runBtn.disabled = false;
        printBtn.disabled = false;
    }

    function fail(msg, fallback) {
        end();
        errorEl.textContent = typeof msg === 'string' ? msg : fallback;
        errorEl.classList.add('visible');
    }

    function stepHtml(s) {
        return '<li class="pc-step">' +
            '<span class="pc-badge ' + escHtml(s.status) + '">' + escHtml(STATUS_LABEL[s.status] || s.status) + '</span>' +
            '<div><div class="pc-label">' + escHtml(s.label) + '</div>' +
                '<div class="pc-summary">' + escHtml(s.summary) + '</div></div>' +
            '<span class="pc-time">' + (s.seconds === null ? '' : s.seconds.toFixed(2) + 's') + '</span>' +
            (s.output ? '<pre class="pc-output">' + escHtml(s.output) + '</pre>' : '') +
            '</li>';
    }

    function readJson(r) {
        if (!r.ok) return r.json().then(function (d) { return Promise.reject(d.error || 'Server error'); });
        return r.json();
    }

    runBtn.addEventListener('click', function () {
        begin('Running checks…');
        fetch(apiUrl('printer-check.php'))
            .then(readJson)
            .then(function (data) {
                end();
                stepsEl.innerHTML = data.steps.map(stepHtml).join('');
            })
            .catch(function (msg) { fail(msg, 'Failed to run the checks.'); });
    });

    printBtn.addEventListener('click', function () {
        if (!window.confirm(printBtn.dataset.confirm)) return;
        begin('Printing a test label…');
        fetch(apiUrl('printer-check.php'), { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(readJson)
            .then(function (data) {
                end();
                stepsEl.innerHTML = stepHtml({
                    status:  data.ok ? 'pass' : 'fail',
                    label:   'Test label',
                    seconds: data.seconds,
                    summary: data.ok
                        ? 'Sent a 1ml label stamped ' + data.printed_at + '.  Check that it came out of the printer.'
                        : 'The print command exited ' + data.exit + '.  Nothing may have printed.',
                    output:  data.output,
                });
            })
            .catch(function (msg) { fail(msg, 'Failed to print the test label.'); });
    });
}());
</script>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
