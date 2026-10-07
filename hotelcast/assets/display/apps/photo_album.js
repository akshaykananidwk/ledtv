/* Photo album (#19) — core/Apps/PhotoAlbumApp.php. Slideshow (lib-slides.js); data every 30 s. ES5 only. */
(function (w, d) {
  'use strict';
  var show = null;
  w.HCApp = {
    update: function (data) {
      var el = d.getElementById('paShow'), empty = d.getElementById('paEmpty');
      if (!el || !data || !w.HCSlides) { return; }
      if (!show) {
        var o = {};
        try { o = JSON.parse(el.getAttribute('data-opts') || '{}'); } catch (e) { o = {}; }
        show = w.HCSlides.create(el, o);
      }
      var photos = data.photos || [];
      if (empty) {
        empty.style.display = photos.length ? 'none' : '';
        if (data.empty) { empty.textContent = data.empty; }
      }
      show.set(photos);
    }
  };
})(window, document);
