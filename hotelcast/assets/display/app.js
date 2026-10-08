/*
 * Display apps (2.3) runtime — loaded by every page from display/index.php.
 * ES5 only (old Android TV WebViews, Chrome 50 era): no arrow functions, let/const, fetch, ?. or ``.
 *
 * window.HC_DISPLAY (from the server): app, item, rev, lang, preview, refresh_sec, data_url,
 *   server_now (ms), tz_offset_min (hotel time zone), data (initial data()), i18n.
 *
 * Apps hook in by defining window.HCApp in assets/display/apps/<key>.js:
 *   window.HCApp = {
 *     init: function (cfg) {},              // optional, once, before the first update
 *     update: function (data, initial) {}   // initial data (initial = true), then every refresh
 *   };
 * Helpers: HC.now(), HC.local(ms), HC.formatTime(d, h24, sec), HC.formatDate(d), HC.clock(el, opts),
 *   HC.esc(s), HC.pad(n), HC.rotate(container, sec), HC.fitText(el, minRem), HC.refresh().
 */
(function (w, d) {
  'use strict';
  var C = w.HC_DISPLAY || {};
  var HC = w.HC = w.HC || {};
  var I = C.i18n || {};
  var fails = 0;
  var timer = null;
  var started = false;

  HC.config = C;
  HC.data = C.data === undefined ? null : C.data;
  HC.offset = C.server_now ? C.server_now - new Date().getTime() : 0; // TV clock may be wrong
  HC.tzOffsetMin = C.tz_offset_min || 0;

  /** Server-corrected epoch milliseconds. */
  HC.now = function () { return new Date().getTime() + HC.offset; };
  /** Date whose getUTC* fields are the hotel's local time (independent of the TV's time zone). */
  HC.local = function (ms) { return new Date((ms === undefined || ms === null ? HC.now() : ms) + HC.tzOffsetMin * 60000); };
  HC.pad = function (n) { return (n < 10 ? '0' : '') + n; };
  HC.esc = function (s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  /** "6:05 PM" / "18:05" (+ ":09" seconds) from an HC.local() date. */
  HC.formatTime = function (dt, h24, seconds) {
    var h = dt.getUTCHours(), m = dt.getUTCMinutes(), s = dt.getUTCSeconds(), out;
    if (h24) {
      out = HC.pad(h) + ':' + HC.pad(m);
    } else {
      out = (h % 12 || 12) + ':' + HC.pad(m);
    }
    if (seconds) { out += ':' + HC.pad(s); }
    return h24 ? out : out + ' ' + (h >= 12 ? (I.pm || 'PM') : (I.am || 'AM'));
  };
  /** "Wednesday, 7 October 2026" in the page language. */
  HC.formatDate = function (dt) {
    var days = I.days || [], months = I.months || [];
    return (days[dt.getUTCDay()] || '') + ', ' + dt.getUTCDate() + ' ' + (months[dt.getUTCMonth()] || '') + ' ' + dt.getUTCFullYear();
  };
  /** Live clock in el (opts: h24, seconds, date = show the date instead). Elements with
   *  data-hc-clock="12|24" (data-hc-seconds) and data-hc-date are bound automatically. */
  HC.clock = function (el, opts) {
    opts = opts || {};
    function tick() {
      var dt = HC.local();
      el.textContent = opts.date ? HC.formatDate(dt) : HC.formatTime(dt, !!opts.h24, !!opts.seconds);
    }
    tick();
    return setInterval(tick, 1000);
  };
  /** Show the .hc-slide children of container one after the other (adds .is-active). */
  HC.rotate = function (container, sec, onShow) {
    if (container._hcRotate) { clearInterval(container._hcRotate); }
    var slides = container.querySelectorAll('.hc-slide');
    var i = 0;
    function show(n) {
      for (var k = 0; k < slides.length; k++) { slides[k].className = slides[k].className.replace(/\s*is-active/g, '') + (k === n ? ' is-active' : ''); }
      if (onShow && slides[n]) { onShow(slides[n], n); }
    }
    if (!slides.length) { return; }
    show(0);
    if (slides.length > 1) {
      container._hcRotate = setInterval(function () { i = (i + 1) % slides.length; show(i); }, Math.max(2, sec || 8) * 1000);
    }
  };
  /** Shrink el's font size (rem) until its content fits its box (minimum minRem). */
  HC.fitText = function (el, minRem) {
    var root = parseFloat(w.getComputedStyle(d.documentElement).fontSize) || 16;
    var size = parseFloat(w.getComputedStyle(el).fontSize) / root;
    var min = minRem || 0.8, guard = 40;
    // Glyphs of Noto Sans (more so Gujarati / Devanagari) reach a little outside a tight line-height (1.2):
    // that overflow (up to ~0.35 em) is not "too much text", or every heading would shrink to the minimum.
    while (guard-- > 0 && size > min && (el.scrollHeight > el.clientHeight + 1 + size * root * 0.35 || el.scrollWidth > el.clientWidth + 1)) {
      size = Math.max(min, size * 0.92);
      el.style.fontSize = size + 'rem';
    }
  };

  // 1rem = 1/64 of the largest 16:9 box that fits the window.
  function scale() {
    var vw = w.innerWidth || d.documentElement.clientWidth, vh = w.innerHeight || d.documentElement.clientHeight;
    if (vw && vh) { d.documentElement.style.fontSize = (Math.min(vw, vh * 16 / 9) / 64) + 'px'; }
  }

  function status(show) {
    var el = d.getElementById('hc-status');
    if (el) { el.style.display = show ? '' : 'none'; }
  }

  function app() { return w.HCApp || null; }

  function schedule(sec) {
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(poll, sec * 1000);
  }

  function interval() {
    // Apps without live data still check every 10 minutes whether the item was edited (rev).
    return C.refresh_sec > 0 ? Math.max(3, C.refresh_sec) : 600;
  }

  function failed() {
    fails++;
    if (fails >= 2) { status(true); }
    var base = C.refresh_sec > 0 ? Math.max(5, C.refresh_sec) : 30;
    schedule(Math.min(300, base * Math.pow(2, Math.min(fails - 1, 6))));
  }

  function poll() {
    if (!C.data_url) { return; }
    var x;
    try { x = new XMLHttpRequest(); } catch (e) { return; }
    var done = false;
    x.open('GET', C.data_url + (C.data_url.indexOf('?') < 0 ? '?' : '&') + '_=' + new Date().getTime(), true);
    x.timeout = 15000;
    x.onreadystatechange = function () {
      if (x.readyState !== 4 || done) { return; }
      done = true;
      var j = null;
      if (x.status === 200) {
        try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
      }
      if (!j || !j.ok) { failed(); return; }
      fails = 0;
      status(false);
      if (j.server_now) { HC.offset = j.server_now - new Date().getTime(); }
      if (j.rev && C.rev && j.rev !== C.rev && !C.preview) { w.location.reload(); return; }
      if (j.refresh_sec !== undefined) { C.refresh_sec = j.refresh_sec; }
      HC.data = j.data;
      var a = app();
      if (a && a.update && j.data !== null && j.data !== undefined) {
        try { a.update(j.data, false); } catch (e) { if (w.console) { w.console.error(e); } }
      }
      schedule(interval());
    };
    x.ontimeout = function () { if (!done) { done = true; failed(); } };
    x.onerror = function () { if (!done) { done = true; failed(); } };
    try { x.send(); } catch (e) { if (!done) { done = true; failed(); } }
  }

  /** Fetch data now (e.g. after a button press in a preview). */
  HC.refresh = function () { poll(); };

  HC.start = function () {
    if (started) { return; }
    started = true;
    scale();
    w.addEventListener('resize', scale);
    var els = d.querySelectorAll('[data-hc-clock]'), k;
    for (k = 0; k < els.length; k++) {
      HC.clock(els[k], { h24: els[k].getAttribute('data-hc-clock') === '24', seconds: els[k].hasAttribute('data-hc-seconds') });
    }
    els = d.querySelectorAll('[data-hc-date]');
    for (k = 0; k < els.length; k++) { HC.clock(els[k], { date: true }); }
    var a = app();
    try {
      if (a && a.init) { a.init(C); }
      if (a && a.update && HC.data !== null) { a.update(HC.data, true); }
    } catch (e) { if (w.console) { w.console.error(e); } }
    if (C.data_url) { schedule(interval()); }
  };
  // Pages call HC.start() after the app script; this is a safety net.
  w.addEventListener('load', function () { HC.start(); });
})(window, document);
