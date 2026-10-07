/* Slide designer (#11): fabric.js 6 editor for 1920×1080 TV slides. Config: <script id="dzConfig">.
 * Saves a PNG + the design JSON to ajax.php?action=designer_save (core/Designer.php). */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const cfgEl = document.getElementById('dzConfig');
    if (!cfgEl || !window.fabric) return;
    const cfg = JSON.parse(cfgEl.textContent);
    const HC = window.HC;
    const L = cfg.i18n;
    const W = cfg.width;
    const H = cfg.height;
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
    const FONT = Object.values(cfg.fonts)[0] || 'Arial, sans-serif';
    const TEXT_TYPES = ['textbox', 'i-text', 'itext', 'text'];
    const isText = (o) => o && TEXT_TYPES.includes(o.type);
    const isImage = (o) => o && o.type === 'image';
    const toast = (m, t) => (HC ? HC.toast(m, t || 'success') : window.alert(m));
    const uploadsBase = new URL('../uploads/', window.location.href).href;

    fabric.FabricObject.customProperties = ['hcRole'];
    Object.assign(fabric.FabricObject.ownDefaults || {}, { transparentCorners: false, cornerColor: '#2563eb', cornerStyle: 'circle', borderColor: '#2563eb' });

    const canvas = new fabric.Canvas('dzCanvas', { preserveObjectStacking: true, backgroundColor: '#1a237e', stopContextMenu: true });

    // ------------------------------------------------------------------ sizing
    const stage = $('#dzStage');
    function fit() {
      const avail = Math.max(240, stage.clientWidth - 34);
      const maxH = Math.max(200, window.innerHeight - 260);
      const w = Math.floor(Math.min(avail, maxH * W / H, W));
      canvas.setDimensions({ width: w, height: Math.round(w * H / W) });
      canvas.setZoom(w / W);
      canvas.requestRenderAll();
    }
    fit();
    if (window.ResizeObserver) new ResizeObserver(() => fit()).observe(stage);
    window.addEventListener('resize', fit);

    // ------------------------------------------------------------------ fonts
    const sample = 'Aa ગુજરાતી हिन्दी ₹';
    const fontsReady = (document.fonts && document.fonts.load ? Promise.all(
      cfg.families.flatMap((f) => ['400', '700'].map((w) => document.fonts.load(w + ' 40px "' + f + '"', sample).catch(() => null)))
    ) : Promise.resolve()).then(() => {
      if (fabric.cache && fabric.cache.clearFontCache) fabric.cache.clearFontCache();
      canvas.getObjects().forEach((o) => { if (isText(o)) { o.initDimensions(); o.setCoords(); } });
      canvas.requestRenderAll();
    });

    // ------------------------------------------------------------------ (de)serialisation
    const bgMode = $('#dzBgMode');
    const bg1 = $('#dzBg1');
    const bg2 = $('#dzBg2');

    /** Same-origin absolute upload URLs back to "../uploads/…" so designs survive a changed host name. */
    function relSrc(src) {
      return typeof src === 'string' && src.startsWith(uploadsBase) ? '../uploads/' + src.slice(uploadsBase.length) : src;
    }
    function fixSrcs(o) {
      if (!o || typeof o !== 'object') return o;
      if (o.type && String(o.type).toLowerCase() === 'image' && o.src) o.src = relSrc(o.src);
      if (Array.isArray(o.objects)) o.objects.forEach(fixSrcs);
      return o;
    }
    function serialize() {
      const o = canvas.toObject(['hcRole']);
      const out = { version: o.version, objects: (o.objects || []).map(fixSrcs), background: o.background || '#ffffff', hc: { bgMode: bgMode.value, bg1: bg1.value, bg2: bg2.value } };
      if (o.backgroundImage) out.backgroundImage = fixSrcs(o.backgroundImage);
      return out;
    }
    function hex(c, dflt) {
      try {
        if (c && typeof c === 'object' && c.colorStops) c = (c.colorStops[0] || {}).color;
        return c ? '#' + new fabric.Color(c).toHex().slice(0, 6).toLowerCase() : dflt;
      } catch (e) { return dflt; }
    }
    function syncBgControls(data) {
      const hc = data.hc || {};
      const bg = data.background;
      if (hc.bgMode) {
        bgMode.value = hc.bgMode; bg1.value = hex(hc.bg1, '#1a237e'); bg2.value = hex(hc.bg2, '#880e4f');
      } else if (bg && typeof bg === 'object' && bg.colorStops) {
        const c = bg.coords || {};
        bgMode.value = c.y2 && c.x2 ? 'd' : (c.y2 ? 'v' : 'h');
        bg1.value = hex(bg.colorStops[0] && bg.colorStops[0].color, '#1a237e');
        bg2.value = hex(bg.colorStops[bg.colorStops.length - 1] && bg.colorStops[bg.colorStops.length - 1].color, '#880e4f');
      } else {
        bgMode.value = 'solid'; bg1.value = hex(bg, '#ffffff');
      }
    }
    const probe = (src) => new Promise((resolve) => {
      const im = new Image();
      im.onload = () => resolve(true);
      im.onerror = () => resolve(false);
      im.src = src;
    });
    /** Drop pictures whose file is gone (deleted from the library) instead of failing the whole load. */
    async function dropBrokenImages(data) {
      const objs = data.objects;
      const checks = await Promise.all(objs.map((o) => (String(o.type).toLowerCase() === 'image' ? probe(o.src) : true)));
      let lost = checks.includes(false);
      data.objects = objs.filter((o, i) => checks[i]);
      if (data.backgroundImage && !(await probe(data.backgroundImage.src))) { delete data.backgroundImage; lost = true; }
      if (lost) toast(L.image_failed, 'warning');
      return data;
    }
    let restoring = false;
    async function load(src, fromTemplate) {
      const data = JSON.parse(JSON.stringify(src || {}));
      let objects = Array.isArray(data.objects) ? data.objects : [];
      if (fromTemplate) {
        objects = objects.map((o) => (o && typeof o.text === 'string' ? Object.assign(o, { text: o.text.split('{hotel}').join(cfg.hotel || '') }) : o));
      }
      const clean = await dropBrokenImages({ version: data.version || fabric.version, objects, background: data.background || '#ffffff', backgroundImage: data.backgroundImage });
      if (!clean.backgroundImage) delete clean.backgroundImage;
      restoring = true;
      try {
        await canvas.loadFromJSON(clean);
      } finally {
        restoring = false;
      }
      syncBgControls(data);
      canvas.getObjects().forEach((o) => o.setCoords());
      canvas.requestRenderAll();
      refreshLayers();
      refreshPanels();
    }

    // ------------------------------------------------------------------ history (undo / redo)
    let stack = [];
    let pos = -1;
    let dirty = false;
    let histTimer = null;
    const undoBtn = $('[data-act="undo"]');
    const redoBtn = $('[data-act="redo"]');
    function updateUndo() {
      undoBtn.disabled = pos <= 0;
      redoBtn.disabled = pos >= stack.length - 1;
    }
    function record(markDirty) {
      if (restoring) return;
      const s = JSON.stringify(serialize());
      if (stack[pos] === s) return;
      stack = stack.slice(0, pos + 1);
      stack.push(s);
      if (stack.length > 60) stack.shift();
      pos = stack.length - 1;
      if (markDirty !== false) dirty = true;
      updateUndo();
      refreshLayers();
    }
    function later() {
      if (restoring) return;
      clearTimeout(histTimer);
      histTimer = setTimeout(record, 250);
    }
    async function goto(i) {
      if (i < 0 || i >= stack.length) return;
      pos = i;
      await load(JSON.parse(stack[i]));
      dirty = true;
      updateUndo();
    }
    canvas.on('object:added', later);
    canvas.on('object:removed', later);
    canvas.on('object:modified', later);

    // ------------------------------------------------------------------ adding things
    function place(obj, at) {
      if (at) obj.set(at);
      else {
        const r = obj.getBoundingRect();
        obj.set({ left: obj.left + (W - r.width) / 2 - r.left, top: obj.top + (H - r.height) / 2 - r.top });
      }
      canvas.add(obj);
      obj.setCoords();
      canvas.setActiveObject(obj);
      canvas.requestRenderAll();
    }
    function textColor() {
      if (bgMode.value !== 'solid' || canvas.backgroundImage) return '#ffffff';
      const c = new fabric.Color(bg1.value).getSource();
      return (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) > 150 ? '#212121' : '#ffffff';
    }
    function addText(kind) {
      const spec = { heading: [L.heading, 120, 'bold', 1500], subheading: [L.subheading, 72, 'bold', 1300], body: [L.body, 48, 'normal', 1000] }[kind];
      place(new fabric.Textbox(spec[0], { width: spec[3], fontSize: spec[1], fontWeight: spec[2], fill: textColor(), fontFamily: FONT, textAlign: 'center', lineHeight: 1.16 }));
    }
    function star(r) {
      const pts = [];
      for (let i = 0; i < 10; i++) {
        const rad = i % 2 ? r * 0.45 : r;
        const a = Math.PI / 5 * i - Math.PI / 2;
        pts.push({ x: rad * Math.cos(a), y: rad * Math.sin(a) });
      }
      return new fabric.Polygon(pts, { fill: '#FFC107', strokeWidth: 0 });
    }
    const shapes = {
      'add-rect': () => new fabric.Rect({ width: 600, height: 340, fill: '#FFC107', strokeWidth: 0 }),
      'add-rounded': () => new fabric.Rect({ width: 600, height: 340, rx: 40, ry: 40, fill: '#ffffff', opacity: 0.9, strokeWidth: 0 }),
      'add-circle': () => new fabric.Circle({ radius: 200, fill: '#E91E63', strokeWidth: 0 }),
      'add-triangle': () => new fabric.Triangle({ width: 420, height: 360, fill: '#4CAF50', strokeWidth: 0 }),
      'add-line': () => new fabric.Line([0, 0, 800, 0], { stroke: '#ffffff', strokeWidth: 10, strokeLineCap: 'round' }),
      'add-star': () => star(200),
    };
    async function imageFrom(url) {
      return fabric.FabricImage.fromURL(url, { crossOrigin: 'anonymous' });
    }
    async function addImageUrl(url, role) {
      try {
        const img = await imageFrom(url);
        const max = role === 'logo' ? [360, 240] : [W * 0.6, H * 0.6];
        img.scale(Math.min(1, max[0] / img.width, max[1] / img.height));
        if (role) img.set('hcRole', role);
        place(img, role === 'logo' ? { left: 60, top: 60 } : null);
      } catch (e) {
        toast(HC.t.error, 'danger');
      }
    }
    async function setBgImage(url) {
      try {
        const img = await imageFrom(url);
        const s = Math.max(W / img.width, H / img.height);
        img.set({ scaleX: s, scaleY: s, originX: 'left', originY: 'top', left: (W - img.width * s) / 2, top: (H - img.height * s) / 2 });
        canvas.backgroundImage = img;
        canvas.requestRenderAll();
        record();
      } catch (e) {
        toast(HC.t.error, 'danger');
      }
    }
    function applyBg() {
      const m = bgMode.value;
      if (m === 'solid') {
        canvas.backgroundColor = bg1.value;
      } else {
        const coords = { h: { x1: 0, y1: 0, x2: W, y2: 0 }, v: { x1: 0, y1: 0, x2: 0, y2: H }, d: { x1: 0, y1: 0, x2: W, y2: H } }[m];
        canvas.backgroundColor = new fabric.Gradient({ type: 'linear', gradientUnits: 'pixels', coords, colorStops: [{ offset: 0, color: bg1.value }, { offset: 1, color: bg2.value }] });
      }
      canvas.requestRenderAll();
      later();
    }
    [bg1, bg2].forEach((el) => el.addEventListener('input', applyBg));
    bgMode.addEventListener('change', applyBg);

    // ------------------------------------------------------------------ uploads (multipart XHR with progress)
    function postForm(action, fd, onProgress) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', HC.ajaxUrl + '?action=' + encodeURIComponent(action));
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', HC.csrf);
        if (onProgress) xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        xhr.onload = () => {
          let j = null;
          try { j = JSON.parse(xhr.responseText); } catch (e) { /* not json */ }
          if (xhr.status === 401) { window.location.reload(); return; }
          if (j && j.ok) resolve(j.data);
          else reject(new Error((j && j.error && j.error.message) || (HC.t.error + ' (' + xhr.status + ')')));
        };
        xhr.onerror = () => reject(new Error(HC.t.error + ' (network)'));
        xhr.send(fd);
      });
    }
    const progress = $('#dzProgress');
    function showProgress(label, frac) {
      progress.hidden = frac === null;
      if (frac === null) return;
      progress.querySelector('[data-label]').textContent = label;
      const bar = progress.querySelector('.progress-bar');
      bar.style.width = Math.round(frac * 100) + '%';
    }
    const fileInput = $('#dzFile');
    let uploadFor = 'object';
    fileInput.addEventListener('change', async () => {
      const f = fileInput.files[0];
      fileInput.value = '';
      if (!f) return;
      const fd = new FormData();
      fd.append('file', f, f.name);
      try {
        showProgress(L.uploading, 0);
        const r = await postForm('designer_image', fd, (p) => showProgress(L.uploading, p));
        cfg.library.unshift({ id: r.id, title: r.title, url: r.url, thumb: r.thumb });
        if (uploadFor === 'background') await setBgImage(r.url);
        else await addImageUrl(r.url);
      } catch (e) {
        toast(e.message, 'danger');
      } finally {
        showProgress('', null);
      }
    });

    // ------------------------------------------------------------------ library picker
    const libModalEl = $('#dzLibModal');
    let libFor = 'object';
    function openLibrary(target) {
      libFor = target;
      const grid = $('#dzLib');
      grid.innerHTML = '';
      if (!cfg.library.length) {
        grid.innerHTML = '<p class="text-muted"></p>';
        grid.firstChild.textContent = L.no_images;
      }
      cfg.library.forEach((im) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.innerHTML = '<img alt="" loading="lazy"><span></span>';
        b.querySelector('img').src = im.thumb || im.url;
        b.querySelector('span').textContent = im.title;
        b.title = im.title;
        b.addEventListener('click', () => {
          bootstrap.Modal.getOrCreateInstance(libModalEl).hide();
          if (libFor === 'background') setBgImage(im.url);
          else addImageUrl(im.url);
        });
        grid.appendChild(b);
      });
      bootstrap.Modal.getOrCreateInstance(libModalEl).show();
    }

    // ------------------------------------------------------------------ selection actions
    const active = () => canvas.getActiveObject();
    const actives = () => canvas.getActiveObjects();
    function removeActive() {
      const objs = actives();
      if (!objs.length) return;
      canvas.discardActiveObject();
      objs.forEach((o) => canvas.remove(o));
      canvas.requestRenderAll();
    }
    async function duplicate() {
      const objs = actives();
      if (!objs.length) return;
      canvas.discardActiveObject();
      const clones = [];
      for (const o of objs) {
        const c = await o.clone(['hcRole']);
        c.set({ left: c.left + 30, top: c.top + 30 });
        canvas.add(c);
        clones.push(c);
      }
      canvas.setActiveObject(clones.length === 1 ? clones[0] : new fabric.ActiveSelection(clones, { canvas }));
      canvas.requestRenderAll();
    }
    function arrange(how) {
      const objs = actives();
      if (!objs.length) return;
      const fn = { front: 'bringObjectToFront', forward: 'bringObjectForward', backward: 'sendObjectBackwards', back: 'sendObjectToBack' }[how];
      const list = how === 'front' || how === 'forward' ? objs : objs.slice().reverse();
      list.forEach((o) => canvas[fn](o));
      canvas.requestRenderAll();
      record();
    }
    function alignTo(how) {
      const o = active();
      if (!o) return;
      const r = o.getBoundingRect();
      const d = {
        'align-left': [-r.left, 0], 'align-hcenter': [(W - r.width) / 2 - r.left, 0], 'align-right': [W - r.width - r.left, 0],
        'align-top': [0, -r.top], 'align-vcenter': [0, (H - r.height) / 2 - r.top], 'align-bottom': [0, H - r.height - r.top],
      }[how];
      o.set({ left: o.left + d[0], top: o.top + d[1] });
      o.setCoords();
      canvas.requestRenderAll();
      record();
    }

    // ------------------------------------------------------------------ snap / align guides
    const snapEl = $('#dzSnap');
    let guides = [];
    canvas.on('object:moving', (e) => {
      guides = [];
      if (!snapEl.checked) return;
      const obj = e.target;
      const thr = 8 / canvas.getZoom();
      const r = obj.getBoundingRect();
      const xs = [0, W / 2, W];
      const ys = [0, H / 2, H];
      const inSel = obj.type === 'activeselection' ? obj.getObjects() : [];
      canvas.getObjects().forEach((o) => {
        if (o === obj || inSel.includes(o) || !o.visible) return;
        const b = o.getBoundingRect();
        xs.push(b.left, b.left + b.width / 2, b.left + b.width);
        ys.push(b.top, b.top + b.height / 2, b.top + b.height);
      });
      const best = (edges, lines) => {
        let res = null;
        edges.forEach((v) => lines.forEach((t) => {
          const dlt = t - v;
          if (Math.abs(dlt) <= thr && (!res || Math.abs(dlt) < Math.abs(res.d))) res = { d: dlt, t };
        }));
        return res;
      };
      const bx = best([r.left, r.left + r.width / 2, r.left + r.width], xs);
      const by = best([r.top, r.top + r.height / 2, r.top + r.height], ys);
      if (bx) { obj.set('left', obj.left + bx.d); guides.push({ x: bx.t }); }
      if (by) { obj.set('top', obj.top + by.d); guides.push({ y: by.t }); }
      obj.setCoords();
    });
    canvas.on('after:render', (opt) => {
      if (!guides.length || !opt.ctx) return;
      const ctx = opt.ctx;
      const v = canvas.viewportTransform;
      ctx.save();
      ctx.transform(v[0], v[1], v[2], v[3], v[4], v[5]);
      ctx.strokeStyle = '#ff2d95';
      ctx.lineWidth = 1.5 / canvas.getZoom();
      ctx.setLineDash([8 / canvas.getZoom(), 6 / canvas.getZoom()]);
      guides.forEach((g) => {
        ctx.beginPath();
        if (g.x !== undefined) { ctx.moveTo(g.x, 0); ctx.lineTo(g.x, H); } else { ctx.moveTo(0, g.y); ctx.lineTo(W, g.y); }
        ctx.stroke();
      });
      ctx.restore();
    });
    canvas.on('mouse:up', () => { if (guides.length) { guides = []; canvas.requestRenderAll(); } });

    // ------------------------------------------------------------------ properties panel
    const panels = { none: $('[data-panel="none"]'), text: $('[data-panel="text"]'), shape: $('[data-panel="shape"]'), common: $('[data-panel="common"]') };
    const pFont = $('#pFont');
    const pSize = $('#pSize');
    const pColor = $('#pColor');
    const pLine = $('#pLine');
    const pShadow = $('#pShadow');
    const pShadowColor = $('#pShadowColor');
    const pShadowBlur = $('#pShadowBlur');
    const pOutline = $('#pOutline');
    const pOutlineColor = $('#pOutlineColor');
    const pOutlineWidth = $('#pOutlineWidth');
    const sFill = $('#sFill');
    const sStroke = $('#sStroke');
    const sStrokeW = $('#sStrokeW');
    const sRadius = $('#sRadius');
    const cOpacity = $('#cOpacity');

    function refreshPanels() {
      const objs = actives();
      const o = objs[0];
      const texts = objs.filter(isText);
      const shapesSel = objs.filter((x) => !isText(x) && !isImage(x));
      panels.none.hidden = !!o;
      panels.common.hidden = !o;
      panels.text.hidden = !texts.length;
      panels.shape.hidden = !shapesSel.length;
      if (!o) { refreshLayers(); return; }
      cOpacity.value = o.opacity == null ? 1 : o.opacity;
      const t = texts[0];
      if (t) {
        if (![...pFont.options].some((op) => op.value === t.fontFamily)) {
          const op = document.createElement('option');
          op.value = t.fontFamily;
          op.textContent = String(t.fontFamily).split(',')[0].replace(/["']/g, '');
          pFont.appendChild(op);
        }
        pFont.value = t.fontFamily;
        pSize.value = Math.round(t.fontSize * (t.scaleY || 1));
        pColor.value = hex(t.fill, '#000000');
        pLine.value = t.lineHeight;
        $('[data-toggle="bold"]').classList.toggle('active', String(t.fontWeight) === 'bold' || Number(t.fontWeight) >= 600);
        $('[data-toggle="italic"]').classList.toggle('active', t.fontStyle === 'italic');
        $('[data-toggle="underline"]').classList.toggle('active', !!t.underline);
        $$('[data-align]').forEach((b) => b.classList.toggle('active', b.dataset.align === t.textAlign));
        pShadow.checked = !!t.shadow;
        if (t.shadow) { pShadowColor.value = hex(t.shadow.color, '#000000'); pShadowBlur.value = t.shadow.blur; }
        pOutline.checked = !!t.stroke && t.strokeWidth > 0;
        if (pOutline.checked) { pOutlineColor.value = hex(t.stroke, '#000000'); pOutlineWidth.value = t.strokeWidth; }
        $$('[data-when]').forEach((el) => { el.hidden = !$('#' + el.dataset.when).checked; });
      }
      const s = shapesSel[0];
      if (s) {
        sFill.value = hex(s.fill, '#ffffff');
        sStroke.value = hex(s.stroke, '#000000');
        sStrokeW.value = s.strokeWidth || 0;
        $('[data-only="Rect"]').hidden = s.type !== 'rect';
        sRadius.value = s.rx || 0;
      }
      refreshLayers();
    }
    canvas.on('selection:created', refreshPanels);
    canvas.on('selection:updated', refreshPanels);
    canvas.on('selection:cleared', refreshPanels);
    canvas.on('object:modified', refreshPanels);

    function setOn(list, props) {
      list.forEach((o) => { o.set(props); if (isText(o)) o.initDimensions(); o.setCoords(); });
      canvas.requestRenderAll();
      later();
    }
    const textSel = () => actives().filter(isText);
    const shapeSel = () => actives().filter((x) => !isText(x) && !isImage(x));
    pFont.addEventListener('change', () => setOn(textSel(), { fontFamily: pFont.value }));
    pSize.addEventListener('input', () => {
      const v = Math.max(8, Math.min(600, Number(pSize.value) || 0));
      if (v) textSel().forEach((o) => setOn([o], { fontSize: v / (o.scaleY || 1) }));
    });
    pColor.addEventListener('input', () => setOn(textSel(), { fill: pColor.value }));
    pLine.addEventListener('input', () => setOn(textSel(), { lineHeight: Number(pLine.value) }));
    $$('[data-toggle]').forEach((b) => b.addEventListener('click', () => {
      const t = textSel()[0];
      if (!t) return;
      const k = b.dataset.toggle;
      const on = !b.classList.contains('active');
      b.classList.toggle('active', on);
      setOn(textSel(), k === 'bold' ? { fontWeight: on ? 'bold' : 'normal' } : k === 'italic' ? { fontStyle: on ? 'italic' : 'normal' } : { underline: on });
    }));
    $$('[data-align]').forEach((b) => b.addEventListener('click', () => {
      $$('[data-align]').forEach((x) => x.classList.toggle('active', x === b));
      setOn(textSel(), { textAlign: b.dataset.align });
    }));
    function applyShadow() {
      $$('[data-when="pShadow"]').forEach((el) => { el.hidden = !pShadow.checked; });
      setOn(textSel(), { shadow: pShadow.checked ? new fabric.Shadow({ color: pShadowColor.value, blur: Number(pShadowBlur.value), offsetX: 4, offsetY: 4 }) : null });
    }
    [pShadow, pShadowColor, pShadowBlur].forEach((el) => el.addEventListener('input', applyShadow));
    function applyOutline() {
      $$('[data-when="pOutline"]').forEach((el) => { el.hidden = !pOutline.checked; });
      setOn(textSel(), pOutline.checked ? { stroke: pOutlineColor.value, strokeWidth: Number(pOutlineWidth.value), paintFirst: 'stroke', strokeLineJoin: 'round' } : { stroke: null, strokeWidth: 0 });
    }
    [pOutline, pOutlineColor, pOutlineWidth].forEach((el) => el.addEventListener('input', applyOutline));
    sFill.addEventListener('input', () => setOn(shapeSel().filter((o) => o.type !== 'line'), { fill: sFill.value }));
    sStroke.addEventListener('input', () => setOn(shapeSel(), { stroke: sStroke.value }));
    sStrokeW.addEventListener('input', () => setOn(shapeSel(), { strokeWidth: Math.max(0, Math.min(80, Number(sStrokeW.value) || 0)) }));
    sRadius.addEventListener('input', () => setOn(shapeSel().filter((o) => o.type === 'rect'), { rx: Number(sRadius.value), ry: Number(sRadius.value) }));
    cOpacity.addEventListener('input', () => setOn(actives(), { opacity: Number(cOpacity.value) }));

    // ------------------------------------------------------------------ layers list
    const layersEl = $('#dzLayers');
    function layerLabel(o) {
      if (isText(o)) return [(o.text || '').replace(/\s+/g, ' ').slice(0, 40) || L.layer_text, 'bi-fonts'];
      if (isImage(o)) return o.hcRole === 'logo' ? [L.layer_logo, 'bi-award'] : [L.layer_image, 'bi-image'];
      return [L.layer_shape, 'bi-square'];
    }
    function refreshLayers() {
      const sel = actives();
      const objs = canvas.getObjects().slice().reverse();
      layersEl.innerHTML = '';
      if (!objs.length) {
        const li = document.createElement('li');
        li.className = 'text-muted';
        li.textContent = L.empty_layers;
        layersEl.appendChild(li);
        return;
      }
      objs.forEach((o) => {
        const [label, icon] = layerLabel(o);
        const li = document.createElement('li');
        li.className = sel.includes(o) ? 'active' : '';
        li.tabIndex = 0;
        li.innerHTML = '<i class="bi"></i><span></span>';
        li.querySelector('i').classList.add(icon);
        li.querySelector('span').textContent = label;
        const pick = () => { canvas.setActiveObject(o); canvas.requestRenderAll(); refreshPanels(); };
        li.addEventListener('click', pick);
        li.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
        layersEl.appendChild(li);
      });
    }

    // ------------------------------------------------------------------ keyboard
    document.addEventListener('keydown', (e) => {
      const tag = (e.target && e.target.tagName || '').toLowerCase();
      const o = active();
      if (o && o.isEditing) return;
      if (['input', 'textarea', 'select'].includes(tag) || (e.target && e.target.isContentEditable)) return;
      if (document.querySelector('.modal.show')) return;
      const mod = e.ctrlKey || e.metaKey;
      const k = e.key.toLowerCase();
      if (mod && k === 'z') { e.preventDefault(); goto(e.shiftKey ? pos + 1 : pos - 1); return; }
      if (mod && k === 'y') { e.preventDefault(); goto(pos + 1); return; }
      if (mod && k === 'd') { e.preventDefault(); duplicate(); return; }
      if (mod && k === 's') { e.preventDefault(); save(); return; }
      if (!o) return;
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeActive(); return; }
      const step = e.shiftKey ? 10 : 1;
      const mv = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
      if (mv) {
        e.preventDefault();
        o.set({ left: o.left + mv[0], top: o.top + mv[1] });
        o.setCoords();
        canvas.requestRenderAll();
        later();
      }
    });

    // ------------------------------------------------------------------ templates
    const tplModalEl = $('#dzTplModal');
    let thumbsDone = false;
    async function thumb(design) {
      const el = document.createElement('canvas');
      const sc = new fabric.StaticCanvas(el, { width: 384, height: 216, renderOnAddRemove: false, enableRetinaScaling: false });
      try {
        const d = JSON.parse(JSON.stringify(design));
        (d.objects || []).forEach((o) => { if (o && typeof o.text === 'string') o.text = o.text.split('{hotel}').join(cfg.hotel || ''); });
        await sc.loadFromJSON({ version: d.version, objects: (d.objects || []).filter((o) => String(o.type).toLowerCase() !== 'image'), background: d.background || '#ffffff' });
        sc.setZoom(384 / W);
        sc.renderAll();
        return sc.toDataURL({ format: 'jpeg', quality: 0.8 });
      } catch (e) {
        return null;
      } finally {
        sc.dispose();
      }
    }
    function tplCard(t, mine) {
      const card = document.createElement('div');
      card.className = 'position-relative';
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'dz-tpl w-100';
      b.innerHTML = '<span class="dz-tpl-ph"></span><span class="dz-tpl-name"></span>';
      b.querySelector('.dz-tpl-name').textContent = t.name;
      b.addEventListener('click', () => startFrom(t.design));
      card.appendChild(b);
      if (mine) {
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-danger dz-tpl-del';
        del.innerHTML = '<i class="bi bi-trash"></i>';
        del.title = del.ariaLabel = HC.t.confirm;
        del.addEventListener('click', () => deleteTemplate(t.id));
        card.appendChild(del);
      }
      card._design = t.design;
      return card;
    }
    function renderTemplates() {
      const mine = $('#dzMyTpl');
      mine.innerHTML = '';
      cfg.templates.forEach((t) => mine.appendChild(tplCard(t, true)));
      $('#dzMyTplWrap').hidden = !cfg.templates.length;
      const st = $('#dzStarterTpl');
      if (!st.children.length) cfg.starters.forEach((t) => st.appendChild(tplCard(t, false)));
      thumbsDone = false;
    }
    async function renderThumbs() {
      if (thumbsDone) return;
      thumbsDone = true;
      await fontsReady;
      for (const card of $$('#dzMyTpl > div, #dzStarterTpl > div')) {
        const ph = card.querySelector('.dz-tpl-ph');
        if (!ph) continue;
        const url = await thumb(card._design);
        if (url) {
          const img = document.createElement('img');
          img.alt = '';
          img.src = url;
          ph.replaceWith(img);
        }
      }
    }
    tplModalEl.addEventListener('shown.bs.modal', renderThumbs);
    async function startFrom(design) {
      if (canvas.getObjects().length && dirty && !(await HC.confirm(L.replace, { danger: false }))) return;
      bootstrap.Modal.getOrCreateInstance(tplModalEl).hide();
      await load(design || { objects: [], background: '#1a237e' }, true);
      record();
    }
    async function deleteTemplate(id) {
      if (!(await HC.confirm(L.delete_template))) return;
      const fd = new FormData();
      fd.append('id', String(id));
      try {
        await postForm('designer_tpldelete', fd);
        cfg.templates = cfg.templates.filter((t) => t.id !== id);
        renderTemplates();
        renderThumbs();
      } catch (e) {
        toast(e.message, 'danger');
      }
    }
    const saveTplModalEl = $('#dzSaveTplModal');
    $('#dzSaveTplForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = $('#dzTplName').value.trim();
      if (!name) return;
      const design = serialize();
      const fd = new FormData();
      fd.append('name', name);
      fd.append('design', new Blob([JSON.stringify(design)], { type: 'application/json' }), 'design.json');
      try {
        const r = await postForm('designer_template', fd);
        cfg.templates = [{ id: r.id, name, design }].concat(cfg.templates.filter((t) => t.id !== r.id));
        renderTemplates();
        bootstrap.Modal.getOrCreateInstance(saveTplModalEl).hide();
        toast(L.template_saved);
      } catch (err) {
        toast(err.message, 'danger');
      }
    });

    // ------------------------------------------------------------------ save
    const titleEl = $('#dzTitle');
    const durEl = $('#dzDuration');
    const saveBtn = $('#dzSave');
    let saving = false;
    async function save() {
      if (saving) return;
      const title = titleEl.value.trim();
      if (!title) { toast(L.title_required, 'warning'); titleEl.focus(); return; }
      saving = true;
      saveBtn.disabled = true;
      try {
        canvas.discardActiveObject();
        guides = [];
        canvas.renderAll();
        const json = JSON.stringify(serialize());
        const jsonBlob = new Blob([json], { type: 'application/json' });
        if (jsonBlob.size > cfg.maxJson) throw new Error(L.json_too_big);
        await fontsReady;
        const out = canvas.toCanvasElement(W / canvas.width);
        const png = await new Promise((resolve) => out.toBlob(resolve, 'image/png'));
        if (!png) throw new Error(HC.t.error);
        if (png.size > cfg.maxImage) throw new Error(L.too_big);
        const fd = new FormData();
        fd.append('id', String(cfg.id || 0));
        fd.append('title', title);
        fd.append('duration', String(Math.max(0, parseInt(durEl.value, 10) || 0)));
        fd.append('design', jsonBlob, 'design.json');
        fd.append('png', png, 'slide.png');
        showProgress(L.saving, 0);
        const r = await postForm('designer_save', fd, (p) => showProgress(L.saving, p));
        cfg.id = r.id;
        dirty = false;
        if (window.history && window.history.replaceState) window.history.replaceState(null, '', r.edit_url);
        toast(L.saved);
      } catch (e) {
        toast(e.message, 'danger');
      } finally {
        saving = false;
        saveBtn.disabled = false;
        showProgress('', null);
      }
    }

    // ------------------------------------------------------------------ buttons
    document.getElementById('dzApp').addEventListener('click', (e) => {
      const b = e.target.closest('[data-act]');
      if (!b) return;
      const act = b.dataset.act;
      if (shapes[act]) { place(shapes[act]()); return; }
      switch (act) {
        case 'add-heading': addText('heading'); break;
        case 'add-subheading': addText('subheading'); break;
        case 'add-body': addText('body'); break;
        case 'upload-image': uploadFor = 'object'; fileInput.click(); break;
        case 'library-image': openLibrary('object'); break;
        case 'add-logo': if (cfg.logo) addImageUrl(cfg.logo, 'logo'); else toast(L.no_logo, 'warning'); break;
        case 'bg-image': openLibrary('background'); break;
        case 'bg-upload': uploadFor = 'background'; fileInput.click(); break;
        case 'bg-clear': canvas.backgroundImage = null; canvas.requestRenderAll(); record(); break;
        case 'undo': goto(pos - 1); break;
        case 'redo': goto(pos + 1); break;
        case 'front': case 'forward': case 'backward': case 'back': arrange(act); break;
        case 'duplicate': duplicate(); break;
        case 'delete': removeActive(); break;
        case 'save': save(); break;
        case 'save-template':
          $('#dzTplName').value = titleEl.value.trim();
          bootstrap.Modal.getOrCreateInstance(saveTplModalEl).show();
          break;
        default:
          if (act.startsWith('align-')) alignTo(act);
      }
    });
    tplModalEl.addEventListener('click', (e) => {
      if (e.target.closest('[data-act="blank"]')) startFrom(null);
    });
    window.addEventListener('beforeunload', (e) => {
      if (dirty) { e.preventDefault(); e.returnValue = L.leave; }
    });

    // ------------------------------------------------------------------ start
    renderTemplates();
    (async () => {
      let initial = cfg.design;
      let fromTpl = false;
      if (!initial && cfg.start) {
        const [kind, id] = cfg.start.split(':');
        const t = kind === 'starter' ? cfg.starters.find((x) => x.id === id) : cfg.templates.find((x) => String(x.id) === id);
        if (t) { initial = t.design; fromTpl = true; }
      }
      await fontsReady;
      if (initial) await load(initial, fromTpl);
      else { syncBgControls({ background: '#1a237e' }); bootstrap.Modal.getOrCreateInstance(tplModalEl).show(); }
      record(false);
      refreshPanels();
    })();
  });
})();
