/* Festival calendar (#28): server-rendered #dfBody, swapped on live refresh. ES5 only. */
(function (w, d) {
  'use strict';
  w.HCApp = {
    update: function (data, initial) {
      if (initial || !data || typeof data.html !== 'string') { return; }
      var el = d.getElementById('dfBody');
      if (el) { el.innerHTML = data.html; }
    }
  };
})(window, document);
