/*
 * Shared photo slideshow for the content apps (photo_album, showcase, event_welcome) — loaded by
 * ContentApps::slidesAssets() inside the page body. Cross-fade between two layers, optional Ken Burns
 * zoom, captions + date, shuffle, preloading; new photos (ids not seen before) are shown next.
 * ES5 only (old Android TV WebViews).
 *
 *   var s = HCSlides.create(el, {sec: 8, kenburns: true, fit: 'cover'|'contain', shuffle: false,
 *                                captions: true, dates: false});
 *   s.set([{id: 1, url: '…', caption: '…', date: '…'}, …]);
 */
(function (w, d) {
  'use strict';
  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function shuffle(a) {
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1)), t = a[i];
      a[i] = a[j];
      a[j] = t;
    }
    return a;
  }

  function Slides(el, opts) {
    this.el = el;
    this.o = opts || {};
    this.list = [];
    this.seen = {};
    this.pos = -1;
    this.cur = null;
    this.timer = null;
    this.front = 0;
    this.started = false;
    this.kb = 0;
    el.className += ' hcs' + (this.o.fit === 'contain' ? ' hcs-contain' : '');
    this.layers = [];
    for (var i = 0; i < 2; i++) {
      var l = d.createElement('div');
      l.className = 'hcs-layer';
      l.innerHTML = '<div class="hcs-bg"></div><div class="hcs-img"></div><div class="hcs-cap" style="display:none"></div>';
      el.appendChild(l);
      this.layers.push(l);
    }
  }

  Slides.prototype.set = function (items) {
    items = items || [];
    var first = !this.started, fresh = [], keep = [], i, curId = this.cur ? this.cur.id : null;
    for (i = 0; i < items.length; i++) {
      if (!first && !this.seen[items[i].id]) { fresh.push(items[i]); } else { keep.push(items[i]); }
    }
    if (this.o.shuffle) { shuffle(keep); }
    // Current photo stays current; brand-new photos come right after it.
    var pos = -1;
    for (i = 0; i < keep.length; i++) { if (keep[i].id === curId) { pos = i; break; } }
    if (pos >= 0) {
      keep = keep.slice(0, pos + 1).concat(fresh, keep.slice(pos + 1));
    } else {
      keep = fresh.concat(keep);
      pos = -1;
    }
    this.list = keep;
    this.pos = pos;
    for (i = 0; i < items.length; i++) { this.seen[items[i].id] = true; }
    if (!this.list.length) {
      this.stop();
      this.cur = null;
      for (i = 0; i < 2; i++) { this.layers[i].className = 'hcs-layer'; }
      return;
    }
    if (first || !this.cur || pos < 0) { this.started = true; this.next(); } else if (!this.timer && this.list.length > 1) { this.schedule(); }
  };

  Slides.prototype.stop = function () {
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
  };

  Slides.prototype.schedule = function () {
    var self = this;
    this.stop();
    if (this.list.length < 2) { return; }
    this.timer = setTimeout(function () { self.timer = null; self.next(); }, Math.max(3, this.o.sec || 8) * 1000);
  };

  Slides.prototype.next = function () {
    if (!this.list.length) { return; }
    this.pos = (this.pos + 1) % this.list.length;
    if (this.pos === 0 && this.o.shuffle && this.list.length > 2 && this.cur) {
      shuffle(this.list);
      if (this.list[0].id === this.cur.id) { this.list.push(this.list.shift()); }
    }
    var item = this.list[this.pos], self = this, img = new w.Image(), done = false;
    function go(ok) {
      if (done) { return; }
      done = true;
      if (ok) { self.show(item); }
      self.schedule();
    }
    img.onload = function () { go(true); };
    img.onerror = function () { go(false); };
    setTimeout(function () { go(true); }, 8000);
    img.src = item.url;
  };

  Slides.prototype.show = function (item) {
    var back = this.layers[1 - this.front], front = this.layers[this.front];
    var url = 'url("' + String(item.url).replace(/["\\\n\r]/g, '') + '")';
    back.querySelector('.hcs-bg').style.backgroundImage = url;
    var im = back.querySelector('.hcs-img');
    im.style.backgroundImage = url;
    var cap = back.querySelector('.hcs-cap'), h = '';
    if (this.o.captions && item.caption) { h += '<div class="hcs-cap-t">' + esc(item.caption) + '</div>'; }
    if (this.o.dates && item.date) { h += '<div class="hcs-cap-d">' + esc(item.date) + '</div>'; }
    cap.innerHTML = h;
    cap.style.display = h ? '' : 'none';
    this.kb = (this.kb % 4) + 1;
    var dur = (Math.max(3, this.o.sec || 8) + 2) + 's';
    im.style.webkitAnimationDuration = dur;
    im.style.animationDuration = dur;
    back.className = 'hcs-layer is-active' + (this.o.kenburns ? ' hcs-kb hcs-kb' + this.kb : '');
    front.className = front.className.replace(/\s*is-active/g, '');
    this.front = 1 - this.front;
    this.cur = item;
    if (this.o.onShow) { this.o.onShow(item); }
  };

  w.HCSlides = { create: function (el, opts) { return new Slides(el, opts); }, esc: esc };
})(window, document);
