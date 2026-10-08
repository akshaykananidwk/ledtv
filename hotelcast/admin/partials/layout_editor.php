<?php
/**
 * Split screen layout editor (2.3) — included by admin/content.php inside the form card for type 'layout'.
 * Expects $item (content row) and $s (its settings). Posts one hidden field `layout_json`
 * (validated by Layouts::validate). Vanilla JS, no build step.
 */
declare(strict_types=1);

$lyHid = Tenant::id();
$lySources = ['c' => [], 'p' => []];
foreach (DB::all("SELECT id, title, type, is_active FROM content_items WHERE hotel_id = :h AND type <> 'layout' ORDER BY title", ['h' => $lyHid]) as $c) {
    $lySources['c'][] = ['v' => 'c:' . $c['id'], 'title' => (string) $c['title'], 'type' => __(ContentManager::TYPES[$c['type']] ?? $c['type']),
        'icon' => ContentManager::TYPE_ICONS[$c['type']] ?? 'bi-file', 'active' => (bool) (int) $c['is_active'], 'snd' => in_array($c['type'], Layouts::SOUND_TYPES, true)];
}
foreach (DB::all(
    "SELECT p.id, p.name, COUNT(i.id) AS n, SUM(c.type = 'layout') AS nested
     FROM content_playlists p LEFT JOIN playlist_items i ON i.playlist_id = p.id LEFT JOIN content_items c ON c.id = i.content_id
     WHERE p.hotel_id = :h GROUP BY p.id ORDER BY p.name",
    ['h' => $lyHid]
) as $p) {
    $lySources['p'][] = ['v' => 'p:' . $p['id'], 'title' => (string) $p['name'], 'n' => (int) $p['n'], 'nested' => (int) $p['nested'] > 0];
}
$lyZones = [];
$lyAudio = 'auto';
foreach (array_values(array_filter((array) ($s['zones'] ?? []), 'is_array')) as $i => $z) {
    [$cid, $pid] = Layouts::parseSource($z);
    $lyZones[] = ['x' => (float) ($z['x'] ?? 0), 'y' => (float) ($z['y'] ?? 0), 'w' => (float) ($z['w'] ?? 0), 'h' => (float) ($z['h'] ?? 0),
        'source' => $cid ? 'c:' . $cid : ($pid ? 'p:' . $pid : ''),
        'scale' => (string) ($z['scale'] ?? 'fit'), 'transition' => (string) ($z['transition'] ?? 'fade')];
    if (($s['audio'] ?? '') === 'z' . ($i + 1)) {
        $lyAudio = $i;
    }
}
if (($s['audio'] ?? '') === 'none') {
    $lyAudio = 'none';
}
$lyPresets = Layouts::presets();
if (!$lyZones) {
    foreach ($lyPresets['left70'][1] as [$x, $y, $w, $h]) {
        $lyZones[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'source' => '', 'scale' => 'fit', 'transition' => 'fade'];
    }
}
$lyBg = clean_color(isset($s['bg_color']) ? (string) $s['bg_color'] : null, '#000000');
?>
<style>
.ly-presets{display:flex;flex-wrap:wrap;gap:.5rem}
.ly-preset{border:1px solid var(--bs-border-color);background:var(--bs-body-bg);border-radius:.5rem;padding:.35rem;width:118px;text-align:center;font-size:.75rem;line-height:1.2;cursor:pointer}
.ly-preset:hover,.ly-preset:focus{border-color:var(--bs-primary);outline:0;box-shadow:0 0 0 .2rem rgba(13,110,253,.2)}
.ly-preset svg{width:100%;height:auto;aspect-ratio:16/9;display:block;margin-bottom:.25rem;border-radius:.25rem}
.ly-canvas{position:relative;width:100%;aspect-ratio:16/9;border-radius:.5rem;overflow:hidden;user-select:none;touch-action:none;box-shadow:inset 0 0 0 1px rgba(0,0,0,.2)}
.ly-zone{position:absolute;border:2px solid #fff;color:#fff;font-size:.8rem;cursor:move;display:flex;align-items:center;justify-content:center;text-align:center;padding:.25rem;overflow:hidden;text-shadow:0 1px 2px #000}
.ly-zone.sel{outline:3px solid #facc15;z-index:2}
.ly-zone.bad{border-color:#ef4444;border-style:dashed;z-index:3}
.ly-zone .ly-num{position:absolute;top:2px;left:4px;font-weight:700}
.ly-zone .ly-snd{position:absolute;top:2px;right:4px}
.ly-zone .ly-handle{position:absolute;right:0;bottom:0;width:16px;height:16px;background:#fff;cursor:nwse-resize;clip-path:polygon(100% 0,100% 100%,0 100%)}
.ly-row{border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.5rem;margin-bottom:.5rem}
.ly-row.sel{border-color:var(--bs-primary)}
.ly-row .ly-swatch{display:inline-block;width:1rem;height:1rem;border-radius:.2rem;vertical-align:-.15rem}
.ly-row input[type=number]{max-width:6rem}
</style>
<div class="col-12">
  <label class="form-label"><?= e(__('Start from a template')) ?></label>
  <div class="ly-presets" id="lyPresets">
    <?php foreach ($lyPresets as $key => [$label, $rects]): ?>
      <button type="button" class="ly-preset" data-preset="<?= e($key) ?>" title="<?= e($label) ?>">
        <?= Layouts::miniSvg(['bg_color' => '#1F2937', 'zones' => array_map(fn ($r) => ['x' => $r[0], 'y' => $r[1], 'w' => $r[2], 'h' => $r[3]], $rects)]) ?>
        <?= e($label) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="form-text"><?= e(__('Choosing a template keeps the content already picked for the first zones.')) ?></div>
</div>
<div class="col-12">
  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
    <label class="form-label mb-0" for="lyBg"><?= e(__('Background colour')) ?></label>
    <input type="color" class="form-control form-control-color" id="lyBg" value="<?= e($lyBg) ?>">
    <span class="flex-grow-1"></span>
    <button type="button" class="btn btn-sm btn-outline-primary" id="lyAdd"><i class="bi bi-plus-lg"></i> <?= e(__('Add zone')) ?></button>
  </div>
  <div class="ly-canvas" id="lyCanvas" style="background:<?= e($lyBg) ?>" aria-label="<?= e(__('Screen layout (16:9)')) ?>"></div>
  <div class="form-text"><i class="bi bi-arrows-move"></i> <?= e(__('Drag a zone to move it, drag its corner to resize. Positions snap to 1% of the screen.')) ?></div>
  <div class="alert alert-danger py-2 mt-2 mb-0" id="lyWarn" role="alert" hidden></div>
</div>
<div class="col-12">
  <label class="form-label"><?= e(__('Zones')) ?> (<span id="lyCount">0</span> / <?= Layouts::MAX_ZONES ?>)</label>
  <div class="form-check mb-1"><input class="form-check-input" type="radio" name="ly_audio" id="lyAudioAuto" value="auto"><label class="form-check-label" for="lyAudioAuto"><?= e(__('Sound: automatic (the first zone with a video)')) ?></label></div>
  <div class="form-check mb-2"><input class="form-check-input" type="radio" name="ly_audio" id="lyAudioNone" value="none"><label class="form-check-label" for="lyAudioNone"><?= e(__('No sound')) ?></label></div>
  <div id="lyRows"></div>
  <input type="hidden" name="layout_json" id="layoutJson" value="">
  <div class="form-text"><?= e(__('Each zone plays its own content item or playlist. Only one zone plays sound; all others are muted. A layout cannot contain another layout.')) ?></div>
</div>
<script type="application/json" id="lyData"><?= json_embed(['zones' => $lyZones, 'audio' => $lyAudio, 'bg' => $lyBg, 'sources' => $lySources, 'max' => Layouts::MAX_ZONES,
    'presets' => array_map(fn ($p) => $p[1], $lyPresets)]) ?></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const D = JSON.parse(document.getElementById('lyData').textContent);
  const T = <?= json_embed([
      'zone' => __('Zone'), 'source' => __('What it plays'), 'choose' => __('— choose content or playlist —'), 'content' => __('Content'), 'playlists' => __('Playlists'),
      'items' => __(':n items'), 'inactive' => __('inactive'), 'missing' => __('(deleted — choose again)'), 'nested' => __('contains a layout'),
      'scale' => __('Scale'), 'fit' => __('Fit'), 'fill' => __('Stretch'), 'zoom' => __('Zoom (crop)'), 'transition' => __('Transition'), 'fade' => __('Fade'), 'none' => __('None'),
      'sound' => __('Sound from this zone'), 'remove' => __('Remove zone'), 'empty' => __('empty'), 'overlap' => __('Zones :a and :b overlap.'),
      'outside' => __('Zone :n is outside the screen (x, y, width and height are percent, 0–100).'), 'nosrc' => __('Zone :n: choose a content item or playlist.'),
      'max' => __('A layout can have at most :n zones.'), 'x' => __('Left %'), 'y' => __('Top %'), 'w' => __('Width %'), 'h' => __('Height %'),
  ]) ?>;
  const COLORS = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2'];
  const canvas = document.getElementById('lyCanvas'), rows = document.getElementById('lyRows'), out = document.getElementById('layoutJson');
  const warn = document.getElementById('lyWarn'), bg = document.getElementById('lyBg');
  const srcTitle = {};
  D.sources.c.forEach((c) => { srcTitle[c.v] = c.title; });
  D.sources.p.forEach((p) => { srcTitle[p.v] = '▶ ' + p.title; });
  let zones = D.zones.map((z) => Object.assign({}, z));
  let audio = D.audio, sel = 0;
  const r2 = (v) => Math.round(v * 100) / 100;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const fmt = (s, o) => s.replace(/:(\w+)/g, (m, k) => (k in o ? o[k] : m));

  function sourceSelect(z) {
    const s = document.createElement('select');
    s.className = 'form-select form-select-sm';
    s.dataset.k = 'source';
    s.add(new Option(T.choose, ''));
    const g1 = document.createElement('optgroup'); g1.label = T.content;
    D.sources.c.forEach((c) => g1.appendChild(new Option(c.title + ' — ' + c.type + (c.active ? '' : ' (' + T.inactive + ')'), c.v)));
    const g2 = document.createElement('optgroup'); g2.label = T.playlists;
    D.sources.p.forEach((p) => { const o = new Option('▶ ' + p.title + ' (' + fmt(T.items, { n: p.n }) + ')' + (p.nested ? ' — ' + T.nested : ''), p.v); o.disabled = p.nested; g2.appendChild(o); });
    if (g2.children.length) s.appendChild(g2);
    if (g1.children.length) s.appendChild(g1);
    if (z.source && !(z.source in srcTitle)) s.add(new Option(T.missing, z.source));
    s.value = z.source || '';
    return s;
  }
  function sel2(k, val, opts) {
    const s = document.createElement('select');
    s.className = 'form-select form-select-sm'; s.dataset.k = k;
    opts.forEach((o) => s.add(new Option(T[o], o)));
    s.value = val;
    return s;
  }
  function field(label, el) {
    const d = document.createElement('div');
    d.className = 'col-auto';
    const l = document.createElement('label');
    l.className = 'form-label small mb-0 d-block'; l.textContent = label;
    d.append(l, el);
    return d;
  }

  function buildRows() {
    rows.innerHTML = '';
    zones.forEach((z, i) => {
      const row = document.createElement('div');
      row.className = 'ly-row' + (i === sel ? ' sel' : '');
      row.dataset.i = i;
      const head = document.createElement('div');
      head.className = 'd-flex align-items-center gap-2 mb-1';
      head.innerHTML = '<span class="ly-swatch" style="background:' + COLORS[i % 6] + '"></span>';
      const b = document.createElement('strong'); b.textContent = T.zone + ' ' + (i + 1); head.appendChild(b);
      const sp = document.createElement('span'); sp.className = 'flex-grow-1'; head.appendChild(sp);
      const rd = document.createElement('div'); rd.className = 'form-check mb-0';
      rd.innerHTML = '<input class="form-check-input" type="radio" name="ly_audio" value="' + i + '" id="lyA' + i + '"><label class="form-check-label small" for="lyA' + i + '"></label>';
      rd.querySelector('label').textContent = T.sound;
      rd.querySelector('input').checked = audio === i;
      head.appendChild(rd);
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'btn btn-sm btn-outline-danger'; del.dataset.del = i; del.title = T.remove; del.setAttribute('aria-label', T.remove);
      del.innerHTML = '<i class="bi bi-x-lg"></i>';
      del.disabled = zones.length <= 1;
      head.appendChild(del);
      row.appendChild(head);
      const grid = document.createElement('div');
      grid.className = 'row g-2 align-items-end';
      const src = field(T.source, sourceSelect(z)); src.className = 'col-12 col-md';
      grid.appendChild(src);
      ['x', 'y', 'w', 'h'].forEach((k) => {
        const n = document.createElement('input');
        n.type = 'number'; n.className = 'form-control form-control-sm'; n.min = k === 'w' || k === 'h' ? 1 : 0; n.max = 100; n.step = '0.01';
        n.value = z[k]; n.dataset.k = k; n.setAttribute('aria-label', T.zone + ' ' + (i + 1) + ' ' + T[k]);
        grid.appendChild(field(T[k], n));
      });
      grid.appendChild(field(T.scale, sel2('scale', z.scale, ['fit', 'fill', 'zoom'])));
      grid.appendChild(field(T.transition, sel2('transition', z.transition, ['fade', 'none'])));
      row.appendChild(grid);
      rows.appendChild(row);
    });
    document.getElementById('lyAudioAuto').checked = audio === 'auto';
    document.getElementById('lyAudioNone').checked = audio === 'none';
    document.getElementById('lyCount').textContent = zones.length;
    document.getElementById('lyAdd').disabled = zones.length >= D.max;
  }

  function overlaps() {
    const bad = [];
    for (let a = 0; a < zones.length; a++) {
      for (let b = a + 1; b < zones.length; b++) {
        const p = zones[a], q = zones[b];
        if (p.x < q.x + q.w - 0.001 && q.x < p.x + p.w - 0.001 && p.y < q.y + q.h - 0.001 && q.y < p.y + p.h - 0.001) bad.push([a, b]);
      }
    }
    return bad;
  }

  function drawCanvas() {
    canvas.style.background = bg.value;
    canvas.innerHTML = '';
    const bad = overlaps(), badSet = new Set(), msgs = [];
    bad.forEach(([a, b]) => { badSet.add(a); badSet.add(b); msgs.push(fmt(T.overlap, { a: a + 1, b: b + 1 })); });
    zones.forEach((z, i) => {
      if (z.x < 0 || z.y < 0 || z.w < 1 || z.h < 1 || z.x + z.w > 100.001 || z.y + z.h > 100.001) { badSet.add(i); msgs.push(fmt(T.outside, { n: i + 1 })); }
      const el = document.createElement('div');
      el.className = 'ly-zone' + (i === sel ? ' sel' : '') + (badSet.has(i) ? ' bad' : '');
      el.dataset.i = i;
      el.style.cssText = 'left:' + z.x + '%;top:' + z.y + '%;width:' + z.w + '%;height:' + z.h + '%;background:' + COLORS[i % 6] + 'cc';
      const num = document.createElement('span'); num.className = 'ly-num'; num.textContent = i + 1; el.appendChild(num);
      const label = document.createElement('span'); label.textContent = z.source ? (srcTitle[z.source] || T.missing) : T.empty; el.appendChild(label);
      if (audioZone() === i) { const s = document.createElement('i'); s.className = 'bi bi-volume-up-fill ly-snd'; el.appendChild(s); }
      const h = document.createElement('span'); h.className = 'ly-handle'; h.dataset.resize = '1'; el.appendChild(h);
      canvas.appendChild(el);
    });
    warn.hidden = !msgs.length;
    warn.textContent = msgs.join(' ');
    serialize();
  }

  function audioZone() {
    if (typeof audio === 'number') return audio;
    if (audio === 'none') return -1;
    const vid = zones.findIndex((z) => z.source && /^c:/.test(z.source) && D.sources.c.some((c) => c.v === z.source && c.snd));
    return vid;
  }

  function serialize() {
    out.value = JSON.stringify({
      bg_color: bg.value,
      audio: typeof audio === 'number' ? 'z' + (audio + 1) : audio,
      zones: zones.map((z, i) => ({ id: 'z' + (i + 1), x: r2(z.x), y: r2(z.y), w: r2(z.w), h: r2(z.h), source: z.source, scale: z.scale, transition: z.transition })),
    });
  }

  function refresh() { buildRows(); drawCanvas(); }

  rows.addEventListener('input', (ev) => {
    const row = ev.target.closest('.ly-row'), k = ev.target.dataset.k;
    if (!row || !k) return;
    const z = zones[+row.dataset.i];
    z[k] = ['x', 'y', 'w', 'h'].includes(k) ? (parseFloat(ev.target.value) || 0) : ev.target.value;
    sel = +row.dataset.i;
    drawCanvas();
  });
  rows.addEventListener('change', (ev) => {
    if (ev.target.name === 'ly_audio') { audio = +ev.target.value; drawCanvas(); }
  });
  document.getElementById('lyAudioAuto').addEventListener('change', () => { audio = 'auto'; drawCanvas(); });
  document.getElementById('lyAudioNone').addEventListener('change', () => { audio = 'none'; drawCanvas(); });
  rows.addEventListener('click', (ev) => {
    const d = ev.target.closest('[data-del]');
    if (d) {
      const i = +d.dataset.del;
      zones.splice(i, 1);
      if (typeof audio === 'number') audio = audio === i ? 'auto' : (audio > i ? audio - 1 : audio);
      sel = Math.min(sel, zones.length - 1);
      refresh();
    }
  });
  bg.addEventListener('input', drawCanvas);
  document.getElementById('lyAdd').addEventListener('click', () => {
    if (zones.length >= D.max) return;
    // First free 25 % square on a 5 % grid, else the top-left corner.
    let spot = { x: 0, y: 0 };
    search: for (let y = 0; y <= 75; y += 5) {
      for (let x = 0; x <= 75; x += 5) {
        const c = { x, y, w: 25, h: 25 };
        if (!zones.some((q) => c.x < q.x + q.w && q.x < c.x + c.w && c.y < q.y + q.h && q.y < c.y + c.h)) { spot = c; break search; }
      }
    }
    zones.push({ x: spot.x, y: spot.y, w: 25, h: 25, source: '', scale: 'fit', transition: 'fade' });
    sel = zones.length - 1;
    refresh();
  });
  document.getElementById('lyPresets').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-preset]');
    if (!b) return;
    const rects = D.presets[b.dataset.preset];
    zones = rects.map((r, i) => Object.assign({ source: '', scale: 'fit', transition: 'fade' }, zones[i] ? { source: zones[i].source, scale: zones[i].scale, transition: zones[i].transition } : {}, { x: r[0], y: r[1], w: r[2], h: r[3] }));
    if (typeof audio === 'number' && audio >= zones.length) audio = 'auto';
    sel = 0;
    refresh();
  });

  // Drag to move / resize (pointer events, snap to 1 %).
  let drag = null;
  canvas.addEventListener('pointerdown', (ev) => {
    const el = ev.target.closest('.ly-zone');
    if (!el) return;
    const i = +el.dataset.i;
    sel = i;
    drag = { i, resize: !!ev.target.dataset.resize, sx: ev.clientX, sy: ev.clientY, z: Object.assign({}, zones[i]) };
    canvas.setPointerCapture(ev.pointerId);
    rows.querySelectorAll('.ly-row').forEach((r) => r.classList.toggle('sel', +r.dataset.i === i));
    ev.preventDefault();
  });
  canvas.addEventListener('pointermove', (ev) => {
    if (!drag) return;
    const dx = (ev.clientX - drag.sx) / canvas.clientWidth * 100, dy = (ev.clientY - drag.sy) / canvas.clientHeight * 100;
    const z = zones[drag.i], o = drag.z;
    if (drag.resize) {
      z.w = clamp(Math.round(o.w + dx), 1, 100 - o.x);
      z.h = clamp(Math.round(o.h + dy), 1, 100 - o.y);
    } else {
      z.x = clamp(Math.round(o.x + dx), 0, 100 - o.w);
      z.y = clamp(Math.round(o.y + dy), 0, 100 - o.h);
    }
    drawCanvas();
  });
  const end = () => { if (drag) { drag = null; buildRows(); drawCanvas(); } };
  canvas.addEventListener('pointerup', end);
  canvas.addEventListener('pointercancel', end);
  canvas.closest('form').addEventListener('submit', serialize);
  refresh();
});
</script>
