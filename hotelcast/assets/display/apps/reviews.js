/* Google reviews (#30): rotates the review cards (HC.rotate), swaps #dfBody on refresh. ES5 only. */
(function (w, d) {
  'use strict';
  function setup() {
    var box = d.querySelector('.rv-slides');
    if (box) { HC.rotate(box, parseInt(box.getAttribute('data-rotate'), 10) || 10); }
  }
  w.HCApp = {
    init: function () { setup(); },
    update: function (data, initial) {
      if (initial || !data || typeof data.html !== 'string') { return; }
      var el = d.getElementById('dfBody');
      if (el) { el.innerHTML = data.html; setup(); }
    }
  };
})(window, document);
