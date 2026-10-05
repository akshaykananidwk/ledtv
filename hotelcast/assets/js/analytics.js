/*
 * HotelCast analytics charts (Chart.js 4, vendored in assets/vendor/chartjs).
 * Every <canvas data-chart="ID"> is drawn from <script type="application/json" id="ID">:
 *   { "type": "bar|line|doughnut", "labels": [...], "horizontal": false,
 *     "datasets": [{ "label": "...", "data": [...], "type": "line"?, "axis": "y2"? }], "unit": "%"? }
 */
(function () {
  'use strict';
  var PALETTE = ['#4f46e5', '#0ea5e9', '#16a34a', '#f59e0b', '#dc2626', '#9333ea', '#0d9488', '#db2777'];

  function draw(canvas) {
    var src = document.getElementById(canvas.getAttribute('data-chart'));
    if (!src || typeof window.Chart === 'undefined') return;
    var cfg;
    try { cfg = JSON.parse(src.textContent || '{}'); } catch (e) { return; }
    var hasY2 = false;
    var datasets = (cfg.datasets || []).map(function (d, i) {
      var color = d.color || PALETTE[i % PALETTE.length];
      var ds = { label: d.label, data: d.data || [], borderColor: color, backgroundColor: cfg.type === 'doughnut' ? PALETTE : color + (d.type === 'line' || cfg.type === 'line' ? '33' : 'cc'),
        borderWidth: 2, borderRadius: 4, tension: 0.3, pointRadius: (d.data || []).length > 40 ? 0 : 3, fill: (d.type || cfg.type) === 'line' && i === 0 && !d.axis };
      if (d.type) ds.type = d.type;
      if (d.axis === 'y2') { ds.yAxisID = 'y2'; hasY2 = true; }
      return ds;
    });
    var horizontal = !!cfg.horizontal;
    var scales = cfg.type === 'doughnut' ? {} : {
      x: { grid: { display: horizontal }, ticks: { autoSkip: true, maxRotation: 0 } },
      y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { precision: 0, callback: function (v) { return v + (cfg.unit || ''); } } }
    };
    if (horizontal) { scales.x.beginAtZero = true; scales.y = { grid: { display: false } }; }
    if (cfg.max) { scales[horizontal ? 'x' : 'y'].max = cfg.max; }
    if (hasY2) { scales.y2 = { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }; }
    new window.Chart(canvas, {
      type: cfg.type || 'bar',
      data: { labels: cfg.labels || [], datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x', animation: { duration: 400 },
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: datasets.length > 1 || cfg.type === 'doughnut', position: 'bottom' } },
        scales: scales
      }
    });
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('canvas[data-chart]'), draw);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
