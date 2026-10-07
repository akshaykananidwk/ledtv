/*
 * Data feed widgets (gold_rates, market, cricket, currency, travel_status) — the server renders the
 * body (#dfBody); every live refresh brings the same HTML (data.html, escaped server-side), swapped in
 * here. ES5 only (old TV WebViews). An app may still define its own HCApp in apps/<key>.js.
 */
(function (w, d) {
  'use strict';
  if (w.HCApp) { return; }
  w.HCApp = {
    update: function (data, initial) {
      if (initial || !data || typeof data.html !== 'string') { return; }
      var el = d.getElementById('dfBody');
      if (el) { el.innerHTML = data.html; }
    }
  };
})(window, document);
