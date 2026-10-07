/* KPI dashboard (#8) — core/Apps/KpiDashboardApp.php. The server sends the escaped tile HTML and the
 * current shift; tiles are only replaced when something changed (values pushed by machines show
 * up within one refresh), so page rotation and the ticker keep running. ES5 only. */
(function (w, d) {
  'use strict';
  var last = null;
  w.HCApp = {
    update: function (data, initial) {
      var body = d.getElementById('kpBody');
      if (!body || !data || typeof data.html !== 'string') { return; }
      var shift = d.getElementById('kpShift');
      if (shift) {
        shift.textContent = data.shift || '';
        shift.style.display = data.shift ? '' : 'none';
      }
      if (!initial) {
        if (data.html === last) { return; }
        body.innerHTML = data.html;
      }
      last = data.html;
      var pages = d.getElementById('kpPages');
      if (pages) {
        HC.rotate(pages, data.page_sec || 12, function (slide) {
          var vals = slide.querySelectorAll('.kp-value');
          for (var i = 0; i < vals.length; i++) { HC.fitText(vals[i], 1.5); }
        });
      }
    }
  };
})(window, document);
