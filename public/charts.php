<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

// Build the list of selectable years: 2024 through the prior calendar year.
$currentYear = (int) date('Y');
$years = [];
for ($y = 2024; $y < $currentYear; $y++) {
    $years[] = $y;
}

$pageTitle  = 'Charts - Utility App';
$activePage = 'charts';
require __DIR__ . '/partials/header.php';
?>
<style>
    /* ── Charts page layout ── */
    .charts-wrap {
        flex: 1;
        padding: 2rem;
        max-width: 85vw;
        margin: 0 auto;
        width: 100%;
    }

    /* ── Accordion cards (same as reports.php) ── */
    .accordion {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

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

    .accordion-header-text h2 {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: .15rem;
    }

    .accordion-header-text p {
        font-size: .8rem;
        color: #888;
        line-height: 1.4;
    }

    .accordion-chevron {
        flex-shrink: 0;
        color: #aaa;
        transition: transform .2s ease;
    }

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

    /* ── Period selector ── */
    .period-selector {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .period-btn {
        display: inline-flex;
        align-items: center;
        padding: .4rem 1rem;
        border-radius: 6px;
        font-size: .82rem;
        font-weight: 500;
        cursor: pointer;
        color: #555;
        background: #fff;
        border: 1px solid #e2e8f0;
        font-family: inherit;
        transition: background .15s, border-color .15s, color .15s;
    }

    .period-btn:hover { background: #f0f0f5; border-color: #c8d0e0; }

    .period-btn.active { background: #1a1a2e; color: #fff; border-color: #1a1a2e; }

    /* ── Chart loading / error states ── */
    .chart-loading {
        display: none;
        align-items: center;
        gap: .6rem;
        padding: 1.25rem 0;
        font-size: .875rem;
        color: #888;
    }

    .chart-loading.visible { display: flex; }

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

    .chart-error {
        display: none;
        padding: .8rem 1rem;
        background: #fff5f5;
        border: 1px solid #fca5a5;
        border-radius: 7px;
        color: #b91c1c;
        font-size: .85rem;
        margin-top: 1rem;
    }

    .chart-error.visible { display: block; }

    /* ── Chart area ── */
    .chart-area {
        display: none;
        margin-top: .5rem;
    }

    .chart-area.visible { display: block; }

    .chart-canvas-wrap {
        position: relative;
        width: 100%;
        height: 480px;
    }

    .chart-empty {
        padding: 3rem 1rem;
        text-align: center;
        color: #aaa;
        font-size: .875rem;
    }

    /* ── Chart meta ── */
    .chart-meta {
        margin-top: .75rem;
        font-size: .72rem;
        color: #aaa;
    }

    @media (max-width: 700px) {
        .charts-wrap { padding: 1rem; }
        .chart-canvas-wrap { height: 320px; }
    }

    @media (max-width: 480px) {
        .accordion-header { padding: 1rem; }
        .accordion-body { padding: 0 1rem 1rem; }
    }
</style>

<div class="charts-wrap">
    <div class="page-header">
        <h1>Charts</h1>
        <span class="subtitle">Visual analytics from local order data</span>
    </div>

    <div class="accordion" id="accordion">

        <!-- ── Card 1: Per-ML Revenue ── -->
        <div class="accordion-card" id="card-ml-revenue">
            <div class="accordion-header" role="button" aria-expanded="false"
                 aria-controls="body-ml-revenue"
                 onclick="toggleAccordion('card-ml-revenue')">
                <div class="accordion-header-icon">
                    <!-- scatter/dot chart icon -->
                    <svg viewBox="0 0 24 24">
                        <circle cx="5"  cy="19" r="1.5"/>
                        <circle cx="9"  cy="13" r="1.5"/>
                        <circle cx="14" cy="8"  r="1.5"/>
                        <circle cx="19" cy="5"  r="1.5"/>
                        <circle cx="12" cy="16" r="1.5"/>
                        <circle cx="7"  cy="7"  r="1.5"/>
                        <line x1="3" y1="21" x2="21" y2="21"/>
                        <line x1="3" y1="3"  x2="3"  y2="21"/>
                    </svg>
                </div>
                <div class="accordion-header-text">
                    <h2>Product Per-ML Revenue</h2>
                    <p>Scatter plot of average revenue per ml for each product variant, excluding bundles.</p>
                </div>
                <div class="accordion-chevron">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>

            <div class="accordion-body" id="body-ml-revenue">

                <!-- Period selector -->
                <div class="period-selector" id="ml-period-selector">
                    <button class="period-btn" data-period="ytd">Year to Date</button>
                    <button class="period-btn" data-period="ttm">Trailing 12 Mo.</button>
                    <?php foreach ($years as $y): ?>
                    <button class="period-btn" data-period="<?= $y ?>"><?= $y ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- Loading -->
                <div class="chart-loading" id="ml-loading">
                    <div class="spinner"></div>
                    Loading chart data…
                </div>

                <!-- Error -->
                <div class="chart-error" id="ml-error"></div>

                <!-- Chart -->
                <div class="chart-area" id="ml-chart-area">
                    <div class="chart-canvas-wrap">
                        <canvas id="ml-chart-canvas"></canvas>
                    </div>
                    <p class="chart-meta" id="ml-chart-meta"></p>
                </div>

            </div><!-- /accordion-body -->
        </div><!-- /card -->

    </div><!-- /accordion -->
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<script>
(function () {
    'use strict';

    // ── Accordion ──────────────────────────────────────────────────────────────

    window.toggleAccordion = function (cardId) {
        const card   = document.getElementById(cardId);
        const isOpen = card.classList.contains('open');
        card.classList.toggle('open', !isOpen);
        card.querySelector('.accordion-header').setAttribute('aria-expanded', String(!isOpen));
    };

    // ── Per-ML Revenue chart ───────────────────────────────────────────────────

    const loadingEl  = document.getElementById('ml-loading');
    const errorEl    = document.getElementById('ml-error');
    const chartArea  = document.getElementById('ml-chart-area');
    const metaEl     = document.getElementById('ml-chart-meta');
    const canvas     = document.getElementById('ml-chart-canvas');
    const selector   = document.getElementById('ml-period-selector');

    let chartInstance = null;
    let activePeriod  = null;

    // Period button clicks
    selector.addEventListener('click', function (e) {
        const btn = e.target.closest('.period-btn');
        if (!btn) return;

        const period = btn.dataset.period;
        if (period === activePeriod) return;

        // Update active state
        selector.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activePeriod = period;

        loadChart(period);
    });

    function showLoading(on) {
        loadingEl.classList.toggle('visible', on);
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.toggle('visible', msg !== '');
    }

    function loadChart(period) {
        showLoading(true);
        showError('');
        chartArea.classList.remove('visible');

        fetch('api/ml-revenue.php?period=' + encodeURIComponent(period))
            .then(function (res) {
                if (!res.ok) return res.json().then(function (d) { throw new Error(d.error || 'Server error'); });
                return res.json();
            })
            .then(function (data) {
                showLoading(false);
                renderChart(data);
            })
            .catch(function (err) {
                showLoading(false);
                showError('Failed to load chart data: ' + (err.message || 'Unknown error'));
            });
    }

    function renderChart(data) {
        const points = data.points;

        if (!points || points.length === 0) {
            chartArea.classList.add('visible');
            // Destroy existing chart if any
            if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
            canvas.style.display = 'none';
            metaEl.textContent = '';

            // Show empty message
            let emptyEl = document.getElementById('ml-chart-empty');
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.id = 'ml-chart-empty';
                emptyEl.className = 'chart-empty';
                canvas.parentNode.appendChild(emptyEl);
            }
            emptyEl.textContent = 'No data with known ml sizes found for this period.';
            emptyEl.style.display = 'block';
            return;
        }

        // Hide empty state if previously shown
        const emptyEl = document.getElementById('ml-chart-empty');
        if (emptyEl) emptyEl.style.display = 'none';
        canvas.style.display = '';

        // Build dataset: x = ml, y = revenue_per_ml
        // Color by ml size for visual grouping (hue based on ml bucket)
        const chartData = points.map(function (p) {
            return {
                x:             p.ml,
                y:             p.revenue_per_ml,
                // Extra data for tooltip
                product:       p.product,
                variant:       p.variant,
                total_units:   p.total_units,
                total_revenue: p.total_revenue,
            };
        });

        // Assign colors by ml bucket so same-size variants cluster visually.
        const mlValues  = [...new Set(points.map(p => p.ml))].sort((a, b) => a - b);
        const palette   = generatePalette(mlValues.length);
        const mlColorMap = {};
        mlValues.forEach(function (ml, i) { mlColorMap[ml] = palette[i]; });

        const pointColors = chartData.map(p => mlColorMap[p.x] || 'rgba(26,26,46,0.7)');

        if (chartInstance) {
            chartInstance.destroy();
        }

        chartInstance = new Chart(canvas, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Product Variants',
                    data: chartData,
                    backgroundColor: pointColors,
                    borderColor:     pointColors.map(c => c.replace(/[\d.]+\)$/, '1)')),
                    borderWidth:     1,
                    pointRadius:     6,
                    pointHoverRadius:9,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                const d = items[0].raw;
                                return d.product + (d.variant && d.variant !== 'Default' ? ' — ' + d.variant : '');
                            },
                            label: function (item) {
                                const d = item.raw;
                                return [
                                    'ML size: ' + d.x + ' ml',
                                    '$/ml: $' + Number(d.y).toFixed(4),
                                    'Units sold: ' + Number(d.total_units).toLocaleString(),
                                    'Revenue: $' + Number(d.total_revenue).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}),
                                ];
                            },
                        },
                        padding:     10,
                        boxPadding:  4,
                    },
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text:    'Variant Size (ml)',
                            font:    { size: 12, weight: '600' },
                            color:   '#444',
                        },
                        ticks: {
                            callback: function (v) { return v + ' ml'; },
                            color: '#666',
                        },
                        grid: { color: '#f0f0f0' },
                    },
                    y: {
                        title: {
                            display: true,
                            text:    'Revenue per ml ($/ml)',
                            font:    { size: 12, weight: '600' },
                            color:   '#444',
                        },
                        ticks: {
                            callback: function (v) { return '$' + Number(v).toFixed(3); },
                            color: '#666',
                        },
                        grid: { color: '#f0f0f0' },
                    },
                },
            },
        });

        metaEl.textContent =
            points.length + ' variant' + (points.length !== 1 ? 's' : '') +
            ' plotted. Based on paid orders synced to local database.';

        chartArea.classList.add('visible');
    }

    // Generate a palette of distinct semi-transparent colors.
    function generatePalette(n) {
        const colors = [];
        for (let i = 0; i < n; i++) {
            const hue = Math.round((i / Math.max(n, 1)) * 360);
            colors.push('hsla(' + hue + ', 60%, 45%, 0.75)');
        }
        return colors;
    }

}());
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
