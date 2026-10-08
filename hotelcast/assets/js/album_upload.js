/*
 * Photo album uploader (#19): admin/album_upload.php and the public guest page album/index.php.
 * Picks many photos (gallery / camera), makes big photos smaller on the phone (canvas, max 1920 px,
 * JPEG; the server re-encodes them again with Uploader), uploads them ONE BY ONE with a progress bar,
 * refuses HEIC with a clear message. ES5 (old phones). Markup:
 *   <div data-album-uploader data-url="…" [data-csrf="…"] [data-album="id"] [data-reload="1"] data-i18n='{…}'>
 *     <input type="file" data-album-files multiple> … <input data-album-caption> [<input data-album-name>]
 *     <div data-album-list></div>
 *   </div>
 */
(function (w, d) {
  'use strict';
  var MAX_PX = 1920;

  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function isHeic(f) {
    return /\.(heic|heif)$/i.test(f.name || '') || /heic|heif/i.test(f.type || '');
  }

  /** Smaller JPEG of a big photo (calls back with the original when not possible). */
  function shrink(file, cb) {
    var type = file.type || '';
    if (!/^image\/(jpeg|png|webp)$/.test(type) || file.size < 600 * 1024 || !w.URL || !w.HTMLCanvasElement) { cb(file); return; }
    var url = w.URL.createObjectURL(file);
    var img = new w.Image();
    var done = false;
    function finish(out) {
      if (done) { return; }
      done = true;
      try { w.URL.revokeObjectURL(url); } catch (e) { /* ignore */ }
      cb(out || file);
    }
    img.onload = function () {
      try {
        var iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        var scale = Math.min(1, MAX_PX / Math.max(iw, ih));
        if (scale >= 1 && type === 'image/jpeg' && file.size < 3 * 1024 * 1024) { finish(file); return; }
        var c = d.createElement('canvas');
        c.width = Math.round(iw * scale);
        c.height = Math.round(ih * scale);
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        if (!c.toBlob) { finish(file); return; }
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { finish(file); return; }
          blob.name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
          finish(blob);
        }, 'image/jpeg', 0.86);
      } catch (e) { finish(file); }
    };
    img.onerror = function () { finish(file); };
    img.src = url;
  }

  function Uploader(root) {
    this.root = root;
    this.url = root.getAttribute('data-url');
    this.csrf = root.getAttribute('data-csrf') || '';
    this.album = root.getAttribute('data-album') || '';
    this.reload = root.getAttribute('data-reload') === '1';
    try { this.t = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { this.t = {}; }
    this.list = root.querySelector('[data-album-list]');
    this.queue = [];
    this.busy = false;
    this.ok = 0;
    this.total = 0;
    var self = this, inputs = root.querySelectorAll('[data-album-files]'), i;
    for (i = 0; i < inputs.length; i++) {
      inputs[i].addEventListener('change', function (ev) {
        var files = ev.target.files, k;
        for (k = 0; k < files.length; k++) { self.add(files[k]); }
        ev.target.value = '';
        self.next();
      });
    }
  }

  Uploader.prototype.add = function (file) {
    var row = d.createElement('div');
    row.className = 'album-up-row border rounded p-2 mb-2';
    row.innerHTML = '<div class="d-flex justify-content-between small"><span class="text-truncate me-2">' + esc(file.name || 'photo') + '</span>' +
      '<span data-st class="text-muted text-nowrap">' + esc(this.t.waiting || 'Waiting…') + '</span></div>' +
      '<div class="progress mt-1" style="height:6px"><div class="progress-bar" data-bar style="width:0%"></div></div>';
    if (this.list) { this.list.appendChild(row); }
    this.total++;
    this.queue.push({ file: file, row: row });
  };

  Uploader.prototype.state = function (job, text, cls, pct) {
    var st = job.row.querySelector('[data-st]'), bar = job.row.querySelector('[data-bar]');
    st.textContent = text;
    st.className = 'text-end ' + (cls || 'text-muted'); // long messages wrap instead of leaving the card on a phone
    if (pct !== undefined && bar) { bar.style.width = pct + '%'; }
    if (bar && cls === 'text-danger') { bar.className = 'progress-bar bg-danger'; bar.style.width = '100%'; }
    if (bar && cls === 'text-success') { bar.className = 'progress-bar bg-success'; }
  };

  Uploader.prototype.next = function () {
    if (this.busy) { return; }
    var job = this.queue.shift(), self = this;
    if (!job) { this.finished(); return; }
    this.busy = true;
    if (isHeic(job.file)) { this.state(job, this.t.heic || 'HEIC is not supported', 'text-danger'); this.busy = false; this.next(); return; }
    if (job.file.type && !/^image\//.test(job.file.type)) { this.state(job, this.t.not_image || 'Not an image', 'text-danger'); this.busy = false; this.next(); return; }
    this.state(job, this.t.uploading || 'Uploading…', 'text-primary', 2);
    shrink(job.file, function (blob) { self.send(job, blob); });
  };

  Uploader.prototype.field = function (sel) {
    var el = this.root.querySelector(sel);
    return el ? el.value : '';
  };

  Uploader.prototype.send = function (job, blob) {
    var self = this, fd = new w.FormData(), x = new w.XMLHttpRequest();
    fd.append('op', 'upload');
    if (this.csrf) { fd.append('_csrf', this.csrf); }
    if (this.album) { fd.append('album_id', this.album); }
    fd.append('caption', this.field('[data-album-caption]'));
    fd.append('guest_name', this.field('[data-album-name]'));
    fd.append('photo', blob, blob.name || job.file.name || 'photo.jpg');
    x.open('POST', this.url, true);
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.setRequestHeader('Accept', 'application/json');
    if (this.csrf) { x.setRequestHeader('X-CSRF-Token', this.csrf); }
    if (x.upload) {
      x.upload.onprogress = function (ev) {
        if (ev.lengthComputable) { self.state(job, (self.t.uploading || 'Uploading…') + ' ' + Math.round(ev.loaded * 100 / ev.total) + '%', 'text-primary', Math.max(2, Math.round(ev.loaded * 95 / ev.total))); }
      };
    }
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
      if (x.status === 200 && j && j.ok) {
        self.ok++;
        self.state(job, (j.data && j.data.message) || self.t.done || 'Uploaded', 'text-success', 100);
      } else {
        var msg = j && j.error && j.error.message ? j.error.message : (x.status ? (self.t.failed || 'Failed') + ' (' + x.status + ')' : (self.t.network || 'Network error'));
        self.state(job, msg, 'text-danger');
      }
      self.busy = false;
      self.next();
    };
    x.send(fd);
  };

  Uploader.prototype.finished = function () {
    if (!this.total) { return; }
    var s = d.createElement('div');
    s.className = 'alert ' + (this.ok === this.total ? 'alert-success' : 'alert-warning') + ' py-2 mb-2';
    s.textContent = (this.t.summary || ':ok of :n photos uploaded.').replace(':ok', this.ok).replace(':n', this.total);
    if (this.list) { this.list.appendChild(s); }
    if (this.reload && this.ok > 0) { setTimeout(function () { w.location.reload(); }, 1200); }
    this.ok = 0;
    this.total = 0;
  };

  function init() {
    var els = d.querySelectorAll('[data-album-uploader]'), i;
    for (i = 0; i < els.length; i++) { if (!els[i]._up) { els[i]._up = new Uploader(els[i]); } }
  }
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', init); } else { init(); }
})(window, document);
