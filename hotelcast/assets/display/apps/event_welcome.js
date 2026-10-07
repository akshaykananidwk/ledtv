/* Wedding / event welcome (#10) — core/Apps/EventWelcomeApp.php. Programme highlight + photos refresh
 * every 60 s, welcome text rotates (gu / hi / en). ES5 only. */
(function (w, d) {
  'use strict';
  var show = null, rotating = false;

  /** Same markup as EventWelcomeApp::item(). */
  function item(i, labels) {
    var badge = labels && labels[i.state] ? '<span class="ew-badge">' + HC.esc(labels[i.state]) + '</span>' : '';
    return '<li class="ew-item ew-' + HC.esc(i.state) + '"><span class="ew-time">' + HC.esc(i.time) + (i.day ? '<small>' + HC.esc(i.day) + '</small>' : '') + '</span>' +
      '<span class="ew-label">' + HC.esc(i.label) + (i.place ? '<small>' + HC.esc(i.place) + '</small>' : '') + '</span>' + badge + '</li>';
  }

  w.HCApp = {
    update: function (data, initial) {
      if (!data) { return; }
      var list = d.getElementById('ewSchedule'), k, html = '';
      if (list && !initial) {
        var items = data.schedule || [];
        for (k = 0; k < items.length; k++) { html += item(items[k], data.labels); }
        list.innerHTML = html;
        list.style.display = html ? '' : 'none';
      }
      var el = d.getElementById('ewShow');
      if (el && w.HCSlides) {
        if (!show) {
          var o = {};
          try { o = JSON.parse(el.getAttribute('data-opts') || '{}'); } catch (e) { o = {}; }
          show = w.HCSlides.create(el, o);
        }
        show.set(data.photos || []);
      }
      var wel = d.getElementById('ewWelcome');
      if (wel && !rotating) { rotating = true; HC.rotate(wel, 6); }
      var text = d.querySelector('.ew-text');
      if (text && HC.fitText) { HC.fitText(text, 0.6); }
    }
  };
})(window, document);
