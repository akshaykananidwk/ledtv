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

  w.HCApp = {
    update: function (data, initial) {
      var list = d.getElementById('nbList');
      if (!list || !data) { return; }
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
