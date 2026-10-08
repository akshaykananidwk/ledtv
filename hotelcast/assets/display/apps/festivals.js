/* Festival calendar (#28): server-rendered #dfBody, swapped on live refresh. ES5 only. */
(function (w, d) {
  'use strict';
  // Hide the upcoming festivals that do not fit completely (no half-cut row at the bottom of the screen).
  function fit() {
    var list = d.querySelector('.fe-list');
    if (!list) { return; }
    var items = list.querySelectorAll('.fe-item'), i, bottom = list.getBoundingClientRect().bottom + 1;
    for (i = 0; i < items.length; i++) { items[i].style.display = ''; }
    for (i = 1; i < items.length; i++) {
      if (items[i].getBoundingClientRect().bottom > bottom) { items[i].style.display = 'none'; }
    }
  }
  w.HCApp = {
    init: function () {
      fit();
      w.addEventListener('resize', fit);
      w.addEventListener('load', fit);
      if (d.fonts && d.fonts.ready) { d.fonts.ready.then(fit); }
    },
    update: function (data, initial) {
      if (initial || !data || typeof data.html !== 'string') { return; }
      var el = d.getElementById('dfBody');
      if (el) { el.innerHTML = data.html; fit(); }
    }
  };
})(window, document);
