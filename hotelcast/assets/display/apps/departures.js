/* Departures board (#7) — core/Apps/DeparturesApp.php. The server sends the escaped board HTML
 * (status, delays and auto-hide already applied). The board is only replaced when it changed;
 * rows whose status changed get a split-flap "flip" animation. Pages rotate. ES5 only. */
(function (w, d) {
  'use strict';
  var last = null;

  function states(root) {
    var out = {}, rows = root.querySelectorAll('[data-k]');
    for (var i = 0; i < rows.length; i++) { out[rows[i].getAttribute('data-k')] = rows[i].getAttribute('data-st'); }
    return out;
  }

  w.HCApp = {
    update: function (data, initial) {
      var body = d.getElementById('dfBody');
      if (!body || !data || typeof data.html !== 'string') { return; }
      if (!initial) {
        if (data.html === last) { return; }
        var before = states(body);
        body.innerHTML = data.html;
        var rows = body.querySelectorAll('[data-k]');
        for (var i = 0; i < rows.length; i++) {
          var k = rows[i].getAttribute('data-k');
          if (before[k] !== undefined && before[k] !== rows[i].getAttribute('data-st')) { rows[i].className += ' df-changed'; }
        }
      }
      last = data.html;
      var pages = d.getElementById('dfPages');
      if (pages) { HC.rotate(pages, data.page_sec || 10); }
    }
  };
})(window, document);
