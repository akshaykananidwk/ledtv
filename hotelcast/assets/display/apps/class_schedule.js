/* Class schedule (#6) — core/Apps/ClassScheduleApp.php. The server sends the escaped body HTML
 * (NOW / NEXT already worked out in the hotel time zone); pages of the today view rotate. ES5 only. */
(function (w, d) {
  'use strict';
  var last = null;
  w.HCApp = {
    update: function (data, initial) {
      var body = d.getElementById('csBody');
      if (!body || !data) { return; }
      if (!initial) {
        // Unchanged schedule: keep the running page rotation.
        if (typeof data.html !== 'string' || data.html === last) { return; }
        body.innerHTML = data.html;
      }
      last = data.html;
      var pages = d.getElementById('csPages');
      if (pages) { HC.rotate(pages, data.page_sec || 10); }
    }
  };
})(window, document);
