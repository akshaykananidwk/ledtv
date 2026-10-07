/* Showcase (#9) — core/Apps/ShowcaseApp.php. Ken Burns slideshow (lib-slides.js) beside the info panel. ES5 only. */
(function (w, d) {
  'use strict';
  var show = null;
  w.HCApp = {
    update: function (data) {
      var el = d.getElementById('scShow');
      if (!el || !data || !w.HCSlides) { return; }
      if (!show) {
        var o = {};
        try { o = JSON.parse(el.getAttribute('data-opts') || '{}'); } catch (e) { o = {}; }
        show = w.HCSlides.create(el, o);
      }
      show.set(data.photos || []);
      var panel = d.querySelector('.sc-panel-in');
      if (panel && w.HC && HC.fitText) { HC.fitText(panel, 0.7); }
    }
  };
})(window, document);
