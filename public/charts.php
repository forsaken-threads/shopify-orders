<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
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
require __DIR__ . '/../app/partials/header.php';
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

    /* ── Filter bar ── */
    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 1rem;
        margin-top: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .filter-bar label {
        display: flex;
        flex-direction: column;
        gap: .3rem;
        font-size: .74rem;
        font-weight: 600;
        color: #888;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .filter-bar select,
    .filter-bar input[type="number"] {
        height: 2.1rem;
        padding: 0 .75rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: .85rem;
        font-family: inherit;
        color: #333;
        background: #fff;
        transition: border-color .15s;
    }

    .filter-bar select:focus,
    .filter-bar input[type="number"]:focus {
        outline: none;
        border-color: #1a1a2e;
    }

    .filter-bar select {
        appearance: none;
        -webkit-appearance: none;
        padding-right: 2rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right .6rem center;
        cursor: pointer;
        min-width: 9rem;
    }

    .filter-bar input[type="number"] {
        width: 6rem;
        -moz-appearance: textfield;
    }

    .filter-bar input[type="number"]::-webkit-outer-spin-button,
    .filter-bar input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .vol-range {
        display: flex;
        flex-direction: column;
        gap: .3rem;
    }

    .vol-range-label {
        font-size: .74rem;
        font-weight: 600;
        color: #888;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .vol-range-inputs {
        display: flex;
        align-items: center;
        gap: .4rem;
    }

    .vol-range-sep {
        color: #bbb;
        font-size: .85rem;
        padding-bottom: 1px;
    }

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

                <!-- Filter bar -->
                <div class="filter-bar" id="ml-filter-bar">
                    <label>
                        Period
                        <select id="ml-period-select">
                            <option value="ytd">Year to Date</option>
                            <option value="ttm">Trailing 12 Months</option>
                            <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endforeach; ?>
                            <option value="all">All Time</option>
                        </select>
                    </label>
                    <div class="vol-range">
                        <div class="vol-range-label">Volume sold (ml)</div>
                        <div class="vol-range-inputs">
                            <input type="number" id="ml-vol-min" min="0" step="1" placeholder="min">
                            <span class="vol-range-sep">–</span>
                            <input type="number" id="ml-vol-max" min="0" step="1" placeholder="max">
                        </div>
                    </div>
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

    // ── Per-ML Revenue chart ───────────────────────────────────────────────────
    // toggleAccordion is provided by app/partials/header.php.
    // Wrap it to auto-load the chart when this accordion first opens.

    const loadingEl    = document.getElementById('ml-loading');
    const errorEl      = document.getElementById('ml-error');
    const chartArea    = document.getElementById('ml-chart-area');
    const metaEl       = document.getElementById('ml-chart-meta');
    const canvas       = document.getElementById('ml-chart-canvas');
    const periodSelect = document.getElementById('ml-period-select');
    const volMinInput  = document.getElementById('ml-vol-min');
    const volMaxInput  = document.getElementById('ml-vol-max');

    let chartInstance = null;
    let chartLoaded   = false;
    let volDebounce   = null;

    // Auto-load chart when accordion first opens
    const _origToggle = window.toggleAccordion;
    window.toggleAccordion = function (cardId) {
        _origToggle(cardId);
        if (cardId === 'card-ml-revenue') {
            const card = document.getElementById('card-ml-revenue');
            if (card.classList.contains('open') && !chartLoaded) {
                chartLoaded = true;
                loadChart();
            }
        }
    };

    // Reload on period change
    periodSelect.addEventListener('change', function () {
        chartLoaded = true;
        loadChart();
    });

    // Debounced reload on volume filter change
    function onVolChange() {
        clearTimeout(volDebounce);
        volDebounce = setTimeout(function () {
            if (chartLoaded) loadChart();
        }, 450);
    }
    volMinInput.addEventListener('input', onVolChange);
    volMaxInput.addEventListener('input', onVolChange);

    function showLoading(on) {
        loadingEl.classList.toggle('visible', on);
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.toggle('visible', msg !== '');
    }

    function loadChart() {
        const period = periodSelect.value;
        const volMin = volMinInput.value !== '' ? parseInt(volMinInput.value, 10) : null;
        const volMax = volMaxInput.value !== '' ? parseInt(volMaxInput.value, 10) : null;

        let url = 'api/ml-revenue.php?period=' + encodeURIComponent(period);
        if (volMin !== null && !isNaN(volMin)) url += '&vol_min=' + volMin;
        if (volMax !== null && !isNaN(volMax)) url += '&vol_max=' + volMax;

        showLoading(true);
        showError('');
        chartArea.classList.remove('visible');

        fetch(url)
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

        // Build dataset: x = total ml sold across all variants, y = revenue_per_ml
        const chartData = points.map(function (p) {
            return {
                x:             p.total_ml,
                y:             p.revenue_per_ml,
                product:       p.product,
                total_units:   p.total_units,
                total_revenue: p.total_revenue,
            };
        });

        // One color per product, spread evenly across the palette.
        const palette     = generatePalette(points.length);
        const pointColors = palette;

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
                                return items[0].raw.product;
                            },
                            label: function (item) {
                                const d = item.raw;
                                return [
                                    'Total ml sold: ' + Number(d.x).toLocaleString() + ' ml',
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
                            text:    'Total ML Sold',
                            font:    { size: 12, weight: '600' },
                            color:   '#444',
                        },
                        ticks: {
                            callback: function (v) { return Number(v).toLocaleString() + ' ml'; },
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

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
