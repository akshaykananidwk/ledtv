/* Google Drive slideshow (2.9.7) — core/Apps/GoogleDriveApp.php. Photos cross-fade (lib-slides.css layers),
   videos play full screen to the end (or video_max seconds), then the show continues. Data every 60 s:
   new files come next, removed files drop out. ES5 only (old TV WebViews). */
(function (w, d) {
  'use strict';
  var st = null;

  function esc(s) { return w.HCSlides ? w.HCSlides.esc(s) : String(s || ''); }
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; } return a; }

  function Show(el, video, o) {
    this.el = el; this.video = video; this.o = o || {};
    this.list = []; this.seen = {}; this.pos = -1; this.cur = null; this.timer = null; this.front = 0; this.kb = 0;
    el.className += ' hcs' + (this.o.fit === 'contain' ? ' hcs-contain' : '');
    this.layers = [];
    for (var i = 0; i < 2; i++) {
      var l = d.createElement('div');
      l.className = 'hcs-layer';
      l.innerHTML = '<div class="hcs-bg"></div><div class="hcs-img"></div><div class="hcs-cap" style="display:none"></div>';
      el.appendChild(l);
      this.layers.push(l);
    }
    var self = this;
    video.addEventListener('ended', function () { self.videoDone(); });
    video.addEventListener('error', function () { self.videoDone(); });
  }

  Show.prototype.set = function (items) {
    items = items || [];
    var first = this.pos < 0 && !this.cur, keep = [], fresh = [], i, ids = {};
    for (i = 0; i < items.length; i++) {
      ids[items[i].id] = true;
      if (!first && !this.seen[items[i].id]) { fresh.push(items[i]); } else { keep.push(items[i]); }
      this.seen[items[i].id] = true;
    }
    if (this.o.shuffle) { shuffle(keep); }
    var curId = this.cur ? this.cur.id : null, pos = -1;
    for (i = 0; i < keep.length; i++) { if (keep[i].id === curId) { pos = i; break; } }
    this.list = pos >= 0 ? keep.slice(0, pos + 1).concat(fresh, keep.slice(pos + 1)) : fresh.concat(keep);
    this.pos = pos;
    if (!this.list.length) { this.stopAll(); return; }
    if (!this.cur || !ids[this.cur.id]) { this.next(); } else if (!this.timer && this.cur.kind !== 'video' && this.list.length > 1) { this.schedule(this.o.sec); }
  };

  Show.prototype.stopAll = function () {
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
    this.cur = null;
    this.hideVideo();
    for (var i = 0; i < 2; i++) { this.layers[i].className = 'hcs-layer'; }
  };

  Show.prototype.schedule = function (sec) {
    var self = this;
    if (this.timer) { clearTimeout(this.timer); }
    this.timer = setTimeout(function () { self.timer = null; self.next(); }, Math.max(3, sec || 8) * 1000);
  };

  Show.prototype.next = function () {
    if (!this.list.length) { return; }
    if (this.list.length === 1 && this.cur && this.cur.id === this.list[0].id && this.cur.kind !== 'video') { return; }
    this.pos = (this.pos + 1) % this.list.length;
    if (this.pos === 0 && this.o.shuffle && this.list.length > 2) { shuffle(this.list); }
    var item = this.list[this.pos];
    if (item.kind === 'video') { this.playVideo(item); } else { this.showImage(item); }
  };

  Show.prototype.showImage = function (item) {
    var self = this, img = new w.Image(), done = false;
    function go(ok) {
      if (done) { return; }
      done = true;
      if (ok) { self.hideVideo(); self.paint(item); }
      self.schedule(ok ? self.o.sec : 3);
    }
    img.onload = function () { go(true); };
    img.onerror = function () { go(false); };
    setTimeout(function () { go(true); }, 10000);
    img.src = item.url;
  };

  Show.prototype.paint = function (item) {
    var back = this.layers[1 - this.front], front = this.layers[this.front];
    var url = 'url("' + String(item.url).replace(/["\\\n\r]/g, '') + '")';
    back.querySelector('.hcs-bg').style.backgroundImage = url;
    var im = back.querySelector('.hcs-img');
    im.style.backgroundImage = url;
    var cap = back.querySelector('.hcs-cap');
    cap.innerHTML = this.o.captions && item.caption ? '<div class="hcs-cap-t">' + esc(item.caption) + '</div>' : '';
    cap.style.display = cap.innerHTML ? '' : 'none';
    this.kb = (this.kb % 4) + 1;
    var dur = (Math.max(3, this.o.sec || 8) + 2) + 's';
    im.style.webkitAnimationDuration = dur;
    im.style.animationDuration = dur;
    back.className = 'hcs-layer is-active' + (this.o.kenburns ? ' hcs-kb hcs-kb' + this.kb : '');
    front.className = front.className.replace(/\s*is-active/g, '');
    this.front = 1 - this.front;
    this.cur = item;
  };

  Show.prototype.playVideo = function (item) {
    var v = this.video, self = this;
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
    this.cur = item;
    v.muted = !!this.o.mute;
    v.src = item.url;
    v.className = 'gd-video is-on';
    var p = v.play();
    if (p && p.catch) { p.catch(function () { v.muted = true; v.play().catch(function () { self.videoDone(); }); }); }
    // Safety: a video that never starts / never ends must not stop the show.
    var max = this.o.video_max > 0 ? this.o.video_max : 3600;
    this.timer = setTimeout(function () { self.timer = null; self.videoDone(); }, (max + 30) * 1000);
    if (this.o.video_max > 0) {
      this.capTimer && clearTimeout(this.capTimer);
      this.capTimer = setTimeout(function () { if (self.cur === item) { self.videoDone(); } }, this.o.video_max * 1000);
    }
  };

  Show.prototype.hideVideo = function () {
    var v = this.video;
    if (v.className.indexOf('is-on') >= 0) {
      v.className = 'gd-video';
      try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) { /* ignore */ }
    }
  };

  Show.prototype.videoDone = function () {
    if (!this.cur || this.cur.kind !== 'video') { return; }
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
    if (this.list.length === 1) { var v = this.video; try { v.currentTime = 0; v.play(); } catch (e) { /* ignore */ } this.schedule(this.o.video_max > 0 ? this.o.video_max : 3600); return; }
    this.next();
  };

  w.HCApp = {
    update: function (data) {
      var el = d.getElementById('gdShow'), video = d.getElementById('gdVideo'), empty = d.getElementById('gdEmpty');
      if (!el || !video || !data) { return; }
      if (!st) {
        var o = {};
        try { o = JSON.parse(el.getAttribute('data-opts') || '{}'); } catch (e) { o = {}; }
        st = new Show(el, video, o);
      }
      var items = data.items || [];
      if (empty) {
        empty.style.display = items.length ? 'none' : '';
        empty.textContent = data.empty || '';
      }
      st.set(items);
    }
  };
})(window, document);
