/**
 * ANINAW — charts for reports (Chart.js, vendored in assets/vendor/chartjs).
 *   <canvas data-chart='{"type":"bar","labels":[...],"datasets":[{"label":"...","data":[...]}]}'>
 * Values are plain numbers in the institution currency, already computed on the server.
 */
(function () {
    'use strict';
    if (!window.Chart) { return; }
    var palette = ['#198754', '#12372A', '#D99A16', '#0D6EFD', '#DC3545', '#6F42C1', '#20C997', '#6B7770'];
    var currency = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#6B7770';

    document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
        var config;
        try { config = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
        var round = config.type === 'doughnut' || config.type === 'pie';
        config.datasets.forEach(function (dataset, index) {
            var colors = round ? palette.slice(0, dataset.data.length) : palette[index % palette.length];
            dataset.backgroundColor = dataset.backgroundColor || colors;
            dataset.borderColor = dataset.borderColor || (round ? '#fff' : colors);
            dataset.borderRadius = round ? 0 : 4;
        });
        new Chart(canvas, {
            type: config.type,
            data: { labels: config.labels, datasets: config.datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (ctx) { return (ctx.dataset.label || ctx.label) + ': ' + (config.prefix || '') + currency.format(ctx.parsed.y !== undefined && !round ? ctx.parsed.y : ctx.parsed); } } }
                },
                scales: round ? {} : { y: { beginAtZero: true, ticks: { callback: function (v) { return (config.prefix || '') + currency.format(v); } } } }
            }
        });
    });
})();
