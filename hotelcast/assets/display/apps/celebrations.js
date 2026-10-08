/* Birthday / anniversary wall (#29): rotates today's people (HC.rotate), swaps #dfBody on refresh. ES5 only. */
(function (w, d) {
  'use strict';
  function setup() {
    var hero = d.querySelector('.ce-hero');
    if (hero) { HC.rotate(hero, parseInt(hero.getAttribute('data-rotate'), 10) || 8); }
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
