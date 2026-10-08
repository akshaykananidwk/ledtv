/* Notice board (#3) — rotating or scrolling notices; re-rendered on each live refresh. ES5 only. */
(function (w, d) {
  'use strict';
  var scrollTimer = null;

  function card(n, slide) {
    var h = '<div class="nb-card hc-card' + (slide ? ' hc-slide' : '') + (n.image ? ' has-img' : '') + '">' +
      '<div class="nb-meta"><span class="hc-badge" style="background:' + HC.esc(n.color) + ';color:#fff">' + HC.esc(n.label) + '</span>' +
      (n.date ? '<span class="nb-date">' + HC.esc(n.date) + '</span>' : '') + '</div><div class="nb-main">';
    if (n.image) { h += '<img class="nb-img" alt="" src="' + HC.esc(n.image) + '">'; }
    h += '<div class="nb-text"><div class="nb-title">' + HC.esc(n.title) + '</div>';
    if (n.body) { h += '<div class="nb-body">' + HC.esc(n.body).replace(/\n/g, '<br>') + '</div>'; }
    return h + '</div></div></div>';
  }

  /** List layout: when the notices are taller than the screen, page through them. */
  function startScroll(list, sec) {
    if (scrollTimer) { clearInterval(scrollTimer); scrollTimer = null; }
    var box = list.parentNode, offset = 0;
    list.style.webkitTransform = list.style.transform = 'translateY(0)';
    if (list.scrollHeight <= box.clientHeight) { return; }
    scrollTimer = setInterval(function () {
      var max = list.scrollHeight - box.clientHeight;
      offset = offset >= max ? 0 : Math.min(max, offset + box.clientHeight * 0.8);
      list.style.webkitTransform = list.style.transform = 'translateY(' + (-offset) + 'px)';
    }, Math.max(3, sec) * 1000);
  }

  /* 2.4.1: optional chime when a notice appears that was not shown before (data.chime_url). */
  var seen = null, audio = null;
  function noticeKey(n) { return n.id ? 'i' + n.id : 't' + n.title; }
  function chime(data) {
    var notices = data.notices || [], next = {}, fresh = false, i, k;
    for (i = 0; i < notices.length; i++) {
      k = noticeKey(notices[i]);
      next[k] = true;
      if (seen && !seen[k]) { fresh = true; }
    }
    seen = next;
    if (!fresh || !data.chime_url) { return; }
    try {
      if (!audio) { audio = d.createElement('audio'); audio.preload = 'auto'; }
      if (audio.getAttribute('src') !== data.chime_url) { audio.src = data.chime_url; }
      audio.currentTime = 0;
      var p = audio.play();
      if (p && typeof p.then === 'function') { p.then(null, function () { /* autoplay blocked: ignore */ }); }
    } catch (e) { /* no audio on this screen */ }
  }

  w.HCApp = {
    update: function (data, initial) {
      var list = d.getElementById('nbList');
      if (!list || !data) { return; }
      chime(data);
      var rotate = data.layout === 'rotate', sec = data.rotate_sec || 10, html = '', i;
      if (!initial) {
        var notices = data.notices || [];
        for (i = 0; i < notices.length; i++) { html += card(notices[i], rotate); }
        list.innerHTML = html || '<div class="hc-empty">' + HC.esc(data.empty) + '</div>';
      }
      if (rotate) {
        HC.rotate(list, sec, function (slide) { HC.fitText(slide.querySelector('.nb-text') || slide, 0.9); });
      } else {
        startScroll(list, sec);
      }
    }
  };
})(window, document);
