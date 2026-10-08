/*
 * Panchang + Choghadiya (#27): highlights the current choghadiya with the server-corrected clock
 * (HC.now) and refreshes the server-rendered body at every segment boundary and after the next sunrise.
 * ES5 only.
 */
(function (w, d) {
  'use strict';
  var timer = null, lastNow = -1, lastRefresh = 0;
  function mark() {
    var now = HC.now(), segs = d.querySelectorAll('.pc-seg'), cur = -1, next = 0, i, s, e;
    for (i = 0; i < segs.length; i++) {
      s = parseFloat(segs[i].getAttribute('data-s')) || 0;
      e = parseFloat(segs[i].getAttribute('data-e')) || 0;
      if (now >= s && now < e) { cur = i; }
      segs[i].className = segs[i].className.replace(/\s*is-now/g, '') + (now >= s && now < e ? ' is-now' : '');
      if (e > now && (!next || e < next)) { next = e; }
    }
    var wrap = d.querySelector('.pc-wrap');
    var dayEnd = wrap ? parseFloat(wrap.getAttribute('data-next')) || 0 : 0;
    // A new segment started (or the Vedic day ended): fetch the server's fresh body.
    if (((lastNow >= 0 && cur !== lastNow) || (dayEnd && now >= dayEnd)) && now - lastRefresh > 60000) {
      lastNow = cur;
      lastRefresh = now;
      HC.refresh();
      return;
    }
    lastNow = cur;
  }
  w.HCApp = {
    init: function () {
      mark();
      if (timer) { clearInterval(timer); }
      timer = setInterval(mark, 15000);
    },
    update: function (data, initial) {
      if (initial || !data || typeof data.html !== 'string') { return; }
      var el = d.getElementById('dfBody');
      if (el) { el.innerHTML = data.html; }
      lastNow = -1;
      mark();
    }
  };
})(window, document);
