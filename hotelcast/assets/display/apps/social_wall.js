/* Social wall (#16) — core/Apps/SocialWallApp.php. Official Facebook / Instagram embed iframes, scaled to
 * fit; posts rotate in groups. The page reloads every 30 minutes so the Facebook timeline stays fresh.
 * ES5 only. */
(function (w, d) {
  'use strict';
  var data = null, group = 0, timer = null;

  /** Same markup as SocialWallApp::frame(). */
  function frame(src, cls) {
    return '<div class="sw-card ' + HC.esc(cls) + '"><div class="sw-scale"><iframe src="' + HC.esc(src) + '" width="500" height="880" scrolling="no" frameborder="0" allowfullscreen' +
      ' allow="autoplay; encrypted-media; picture-in-picture" sandbox="allow-scripts allow-same-origin allow-popups allow-presentation" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div>';
  }

  /** Scale each 500×880 iframe box to the height of its card (embeds have a fixed CSS pixel width). */
  function fit() {
    var cards = d.querySelectorAll('.sw-card'), k;
    for (k = 0; k < cards.length; k++) {
      var c = cards[k], s = c.querySelector('.sw-scale');
      if (!s) { continue; }
      var sc = Math.min(c.clientHeight / 880, c.clientWidth / 500);
      if (sc > 0) {
        s.style.webkitTransform = s.style.transform = 'scale(' + sc + ')';
        s.style.marginLeft = Math.max(0, (c.clientWidth - 500 * sc) / 2) + 'px';
      }
    }
  }

  function perScreen() {
    var n = Math.max(1, Math.min(3, data.per_screen || 1));
    return data.page ? Math.min(2, n) : n;
  }

  function showGroup() {
    var box = d.getElementById('swPosts'), posts = data.posts || [], n = perScreen(), html = '', k;
    if (!box || !posts.length) { return; }
    for (k = 0; k < n && k < posts.length; k++) {
      var p = posts[(group * n + k) % posts.length];
      html += frame(p.embed, 'sw-' + p.network);
    }
    box.innerHTML = html;
    fit();
  }

  w.HCApp = {
    update: function (dd) {
      if (!dd || data) { return; }
      data = dd;
      fit();
      w.addEventListener('resize', fit);
      var n = perScreen();
      if ((data.posts || []).length > n) {
        timer = setInterval(function () { group = (group + 1) % Math.ceil(data.posts.length / n); showGroup(); }, Math.max(5, data.rotate_sec || 20) * 1000);
      }
      if (!(w.HC_DISPLAY && w.HC_DISPLAY.preview)) {
        setTimeout(function () { w.location.reload(); }, 30 * 60 * 1000);
      }
    }
  };
})(window, document);
