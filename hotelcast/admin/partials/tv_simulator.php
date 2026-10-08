<?php
/**
 * TV simulator page (renders a Content object the way the Android app does). Included by
 * admin/preview.php (logged-in preview) and demo_tv.php (public demo, demo hotel only).
 * Expects: $obj (Content object), $label (string), $embed (bool, hides the toolbar),
 * optional $simRefreshUrl (JSON endpoint {ok, data: Content} polled every 10 s, null = static),
 * $simCloseUrl (toolbar "Close" link), $simBadge (extra label in the toolbar).
 */
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(__('TV preview')) ?> · <?= e($label) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<script src="<?= e(asset('vendor/hlsjs/hls.min.js')) ?>" defer></script>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:#111;color:#fff;font-family:"Noto Sans Gujarati","Noto Sans",system-ui,Arial,sans-serif;overflow:hidden}
.bar{height:44px;display:flex;align-items:center;gap:.5rem;padding:0 .75rem;background:#1f2937;font-size:14px;white-space:nowrap;overflow-x:auto}
.bar .lbl{font-weight:700;overflow:hidden;text-overflow:ellipsis}
.bar .mode{background:#2563eb;border-radius:999px;padding:2px 10px;font-size:12px}
.bar .mode.m-emergency{background:#b00020}.bar .mode.m-off{background:#475569}
.bar button,.bar a{background:#374151;color:#fff;border:0;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:13px;text-decoration:none}
.bar button:hover,.bar a:hover{background:#4b5563}
.bar .sp{flex:1}
.wrap{position:absolute;top:44px;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center}
body.embed .bar{display:none}body.embed .wrap{top:0}
#stage{position:relative;background:#000;overflow:hidden;box-shadow:0 0 0 1px #333}
/* --tk-top / --tk-bottom: space reserved for the ticker bar (reserve_space) — the content shrinks, the bar never covers it */
.layer{position:absolute;inset:var(--tk-top,0px) 0 var(--tk-bottom,0px) 0;transition:opacity .8s ease,transform .8s ease}
.layer.fade-enter{opacity:0}.layer.slide-enter{transform:translateX(100%)}.layer.slide-leave{transform:translateX(-100%)}.layer.fade-leave{opacity:0}
.layer img,.layer video{width:100%;height:100%;object-fit:var(--tk-fit,contain);background:#000;display:block}
.layer iframe{width:100%;height:100%;border:0;background:#fff;display:block}
.ann{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:4%}
.ann .t{font-weight:700;line-height:1.2;white-space:pre-wrap;word-break:break-word}
.ann .s{margin-top:.6em;opacity:.85}
.marq{width:100%;height:100%;display:flex;align-items:center;overflow:hidden}
.marq span{display:inline-block;white-space:nowrap;padding-left:100%;font-weight:700;animation:mq linear infinite}
@keyframes mq{from{transform:translateX(0)}to{transform:translateX(-100%)}}
.emergency .ann{animation:pulse 1.6s infinite}
.emergency .ann::before{content:"⚠";font-size:3em;line-height:1}
@keyframes pulse{50%{filter:brightness(1.25)}}
.clk{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center}
.clk .time{font-size:14em;font-weight:700;font-variant-numeric:tabular-nums;line-height:1}
.clk .date{font-size:3em;opacity:.8;margin-top:.3em}
.clk svg{height:80%}
.ph{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6em;background:#0f172a;color:#cbd5e1;text-align:center;padding:5%}
.ph i{font-size:6em}.ph .u{font-family:monospace;font-size:1.6em;word-break:break-all;color:#93c5fd}.ph .h{font-size:2.4em;font-weight:700}
.welcome{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:radial-gradient(circle,#1e3a8a,#020617);text-align:center}
.welcome img{max-height:28%;max-width:50%;margin-bottom:3%}
.welcome .w1{font-size:5em;font-weight:700}.welcome .w2{font-size:2.6em;opacity:.85}
.ov{position:absolute;z-index:5;pointer-events:none}
.ov-logo{top:3%;left:2.5%;height:11%}.ov-logo img{height:100%;max-width:20em;object-fit:contain;filter:drop-shadow(0 0 .3em rgba(0,0,0,.6))}
.ov-tr{top:3%;right:2.5%;text-align:right;text-shadow:0 0 .3em #000,0 0 .1em #000}
.ov-tr .c{font-size:3.4em;font-weight:700;font-variant-numeric:tabular-nums}.ov-tr .w{font-size:2em}
.ov-tick{left:0;right:0;display:flex;align-items:center;overflow:hidden;font-weight:600;z-index:6}
.ov-tick.pos-bottom{bottom:0}.ov-tick.pos-top{top:0}
.ov-tick span{position:absolute;left:0;white-space:nowrap;will-change:transform}
.empty-note{color:#64748b;font-size:2em}
.badge-off{position:absolute;bottom:3%;right:3%;color:#334155;font-size:1.6em}
.lay .zone{position:absolute;overflow:hidden}.lay .zone .layer{inset:0}
.mutebtn{position:absolute;z-index:6;bottom:9%;right:2%;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:3.4em;height:3.4em;font-size:1.4em;cursor:pointer}
</style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">
<div class="bar">
  <i class="bi bi-tv"></i>
  <span class="lbl"><?= e($label) ?></span>
  <?php if (!empty($simBadge)): ?><span class="mode" style="background:#b45309"><?= e($simBadge) ?></span><?php endif; ?>
  <span class="mode" id="modeBadge"></span>
  <span id="itemInfo" style="opacity:.75"></span>
  <span class="sp"></span>
  <button type="button" id="btnPrev" title="<?= e(__('Previous')) ?>"><i class="bi bi-skip-start-fill"></i></button>
  <button type="button" id="btnNext" title="<?= e(__('Next')) ?>"><i class="bi bi-skip-end-fill"></i></button>
  <button type="button" id="btnMute" title="<?= e(__('Sound on/off')) ?>"><i class="bi bi-volume-mute-fill"></i></button>
  <button type="button" id="btnFs" title="<?= e(__('Full screen')) ?>"><i class="bi bi-arrows-fullscreen"></i></button>
  <?php if (!empty($simCloseUrl)): ?><a href="<?= e($simCloseUrl) ?>"><i class="bi bi-x-lg"></i> <?= e(__('Close')) ?></a><?php endif; ?>
</div>
<div class="wrap" id="wrap"><div id="stage"></div></div>
<script type="application/json" id="contentData"><?= json_embed($obj) ?></script>
<script>
(function () {
  'use strict';
  const T = <?= json_embed([
      'modes' => ['emergency' => __('Emergency'), 'scheduled' => __('Scheduled'), 'assigned' => __('Assigned'), 'group' => __('Group'), 'default' => __('Default'), 'off' => __('Screen off'), 'empty' => __('Welcome screen'), 'preview' => __('Preview')],
      'welcome' => __('Welcome'), 'room' => __('Room'), 'rtsp' => __('RTSP camera streams play on the TV but cannot be shown in a web browser.'),
      'off' => __('Screen off'), 'nothing' => __('Nothing to show'), 'of' => __('of'),
  ]) ?>;
  const refreshUrl = <?= json_embed($simRefreshUrl ?? null) ?>;
  const stage = document.getElementById('stage');
  const wrap = document.getElementById('wrap');
  let content = JSON.parse(document.getElementById('contentData').textContent);
  let idx = 0, timer = null, clockTimers = [], muted = true, hlsList = [];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function fit() {
    const W = wrap.clientWidth, H = wrap.clientHeight;
    let w = W, h = W * 9 / 16;
    if (h > H) { h = H; w = H * 16 / 9; }
    stage.style.width = w + 'px'; stage.style.height = h + 'px';
    stage.style.fontSize = (w / 192) + 'px'; // 1em = 1/192 of width (10px on a 1920 TV)
  }
  window.addEventListener('resize', fit); fit();

  function fmtTime(d, f) {
    const p = (n) => String(n).padStart(2, '0');
    const h = d.getHours(), h12 = h % 12 || 12;
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return (f || 'hh:mm a').replace(/yyyy|MM|dd|EEE|HH|H|hh|h|mm|ss|a/g, (t) => ({
      yyyy: d.getFullYear(), MM: p(d.getMonth() + 1), dd: p(d.getDate()), EEE: days[d.getDay()],
      HH: p(h), H: h, hh: p(h12), h: h12, mm: p(d.getMinutes()), ss: p(d.getSeconds()), a: h < 12 ? 'AM' : 'PM',
    }[t]));
  }
  function every(ms, fn) { fn(); clockTimers.push(setInterval(fn, ms)); }

  function videoEl(url, item, loopSingle) {
    const v = document.createElement('video');
    v.autoplay = true; v.playsInline = true; v.muted = muted || !!item.mute;
    v.loop = loopSingle && item.loop !== false;
    v.dataset.mute = item.mute ? '1' : '0';
    if (/\.m3u8(\?|$)/i.test(url) && !v.canPlayType('application/vnd.apple.mpegurl') && window.Hls && Hls.isSupported()) {
      const hls = new Hls(); hls.loadSource(url); hls.attachMedia(v); hlsList.push(hls);
    } else { v.src = url; }
    v.addEventListener('canplay', () => { v.play().catch(() => {}); }, { once: true });
    return v;
  }

  function renderItem(item, single) {
    const el = document.createElement('div');
    el.className = 'layer';
    const fs = (sp) => (sp * 1920 / 960 / 10) + 'em'; // sp on a 960dp-wide TV → em units
    switch (item.type) {
      case 'image': el.innerHTML = '<img alt="" src="' + esc(item.url) + '">'; break;
      case 'video': el.appendChild(videoEl(item.url, item, single)); break;
      case 'stream':
        if (/^(rtsp|rtmp):/i.test(item.url)) {
          el.innerHTML = '<div class="ph"><i class="bi bi-camera-video"></i><div class="h">' + esc(item.title) + '</div><div>' + esc(T.rtsp) + '</div><div class="u">' + esc(item.url) + '</div></div>';
        } else { el.appendChild(videoEl(item.url, Object.assign({ loop: true }, item), true)); }
        break;
      case 'url':
        el.innerHTML = '<iframe referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms" src="' + esc(item.url) + '"></iframe>'; break;
      case 'youtube':
        el.innerHTML = '<iframe allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" src="' + esc(item.embed_url || item.url) + '"></iframe>'; break;
      case 'html':
      case 'timetable': {
        // Untrusted HTML: sandboxed, no same-origin access to the admin panel.
        const f = document.createElement('iframe');
        f.setAttribute('sandbox', 'allow-scripts');
        f.srcdoc = item.html || '';
        el.appendChild(f);
        if (item.type === 'timetable' && item.refresh_sec) clockTimers.push(setInterval(() => { f.srcdoc = item.html; }, item.refresh_sec * 1000));
        break;
      }
      case 'announcement':
        if (item.style === 'marquee') {
          const len = (item.text || '').length + 20;
          el.innerHTML = '<div class="marq" style="background:' + esc(item.bg_color) + ';color:' + esc(item.text_color) + ';font-size:' + fs(item.font_size) + '"><span style="animation-duration:' + Math.max(8, len * 0.25) + 's">' + esc(item.text) + (item.subtitle ? ' — ' + esc(item.subtitle) : '') + '</span></div>';
        } else {
          el.innerHTML = '<div class="ann" style="background:' + esc(item.bg_color) + ';color:' + esc(item.text_color) + '"><div class="t" style="font-size:' + fs(item.font_size) + '">' + esc(item.text) + '</div>'
            + (item.subtitle ? '<div class="s" style="font-size:' + fs(item.font_size * 0.5) + '">' + esc(item.subtitle) + '</div>' : '') + '</div>';
        }
        break;
      case 'clock': {
        el.innerHTML = '<div class="clk" style="background:' + esc(item.bg_color) + ';color:' + esc(item.text_color) + '"></div>';
        const box = el.firstChild;
        if (item.style === 'analog') {
          box.innerHTML = '<svg viewBox="-100 -100 200 200"><circle r="95" fill="none" stroke="currentColor" stroke-width="4"/>'
            + Array.from({ length: 12 }, (_, i) => '<line x1="0" y1="-82" x2="0" y2="-92" stroke="currentColor" stroke-width="' + (i % 3 ? 2 : 5) + '" transform="rotate(' + i * 30 + ')"/>').join('')
            + '<line id="hh" y2="-50" stroke="currentColor" stroke-width="7" stroke-linecap="round"/><line id="mh" y2="-75" stroke="currentColor" stroke-width="4" stroke-linecap="round"/><line id="sh" y2="-82" stroke="#e11d48" stroke-width="2"/><circle r="5" fill="currentColor"/></svg>'
            + '<div class="date"></div>';
          every(1000, () => {
            const d = new Date();
            box.querySelector('#hh').setAttribute('transform', 'rotate(' + ((d.getHours() % 12) * 30 + d.getMinutes() / 2) + ')');
            box.querySelector('#mh').setAttribute('transform', 'rotate(' + (d.getMinutes() * 6) + ')');
            box.querySelector('#sh').setAttribute('transform', 'rotate(' + (d.getSeconds() * 6) + ')');
            box.querySelector('.date').textContent = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
          });
        } else {
          box.innerHTML = '<div class="time"></div><div class="date"></div>';
          every(1000, () => {
            const d = new Date();
            box.querySelector('.time').textContent = fmtTime(d, 'hh:mm');
            box.querySelector('.date').textContent = fmtTime(d, 'a') + ' · ' + d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
          });
        }
        break;
      }
      case 'layout': {
        // Split screen (2.3): each zone cycles its own items; only the audio zone (mute=false) plays sound.
        const L = item.layout || {};
        const pct = (v) => Math.max(0, Math.min(100, Number(v) || 0));
        el.classList.add('lay');
        el.style.background = /^#[0-9a-f]{6}$/i.test(String(L.bg_color || '')) ? L.bg_color : '#000';
        const stops = [];
        (Array.isArray(L.zones) ? L.zones : []).slice(0, 6).forEach((z) => {
          const box = document.createElement('div');
          box.className = 'zone';
          box.style.cssText = 'left:' + pct(z.x) + '%;top:' + pct(z.y) + '%;width:' + pct(z.w) + '%;height:' + pct(z.h) + '%;font-size:' + (pct(z.w) / 100) + 'em';
          box.style.setProperty('--tk-fit', { fit: 'contain', fill: 'fill', zoom: 'cover' }[z.scale] || 'contain');
          el.appendChild(box);
          stops.push(zonePlayer(box, z));
        });
        el._stop = () => stops.forEach((f) => f());
        break;
      }
      default:
        el.innerHTML = '<div class="ph"><i class="bi bi-question-circle"></i><div>' + esc(item.type) + '</div></div>';
    }
    return el;
  }

  /** Plays one layout zone: cycles its items (durations, fade / none). Returns a stop function. */
  function zonePlayer(box, z) {
    const items = (Array.isArray(z.items) ? z.items : []).filter((it) => it && it.type !== 'layout');
    let i = -1, t = null, dead = false;
    const next = () => {
      if (dead || !items.length) return;
      i = (i + 1) % items.length;
      const it = z.mute ? Object.assign({}, items[i], { mute: true }) : items[i];
      const single = items.length === 1;
      const layer = renderItem(it, single && z.loop !== false);
      const old = Array.from(box.children);
      if (z.transition === 'fade' && old.length) {
        layer.classList.add('fade-enter');
        box.appendChild(layer);
        requestAnimationFrame(() => requestAnimationFrame(() => { layer.classList.remove('fade-enter'); old.forEach((o) => o.classList.add('fade-leave')); }));
        setTimeout(() => old.forEach(cleanupLayer), 900);
      } else {
        old.forEach(cleanupLayer);
        box.appendChild(layer);
      }
      if (single || (z.loop === false && i === items.length - 1)) return;
      if (it.duration > 0) t = setTimeout(next, it.duration * 1000);
      else if (it.type === 'video') { const v = layer.querySelector('video'); if (v) v.addEventListener('ended', next, { once: true }); }
    };
    next();
    return () => { dead = true; clearTimeout(t); Array.from(box.children).forEach(cleanupLayer); };
  }

  function clearTimers() {
    clearTimeout(timer); timer = null;
    stage.querySelectorAll('.layer').forEach((l) => { if (l._stop) l._stop(); });
    clockTimers.forEach(clearInterval); clockTimers = [];
  }

  function show(i, animate) {
    const items = content.items || [];
    if (!items.length) return;
    idx = (i + items.length) % items.length;
    const item = items[idx];
    clearTimeout(timer);
    const single = items.length === 1;
    const tr = (content.playlist && content.playlist.transition) || 'none';
    const el = renderItem(item, single);
    const old = Array.from(stage.querySelectorAll('.layer'));
    if (animate && tr !== 'none' && old.length) {
      el.classList.add(tr + '-enter');
      stage.insertBefore(el, stage.querySelector('.ov'));
      requestAnimationFrame(() => requestAnimationFrame(() => {
        el.classList.remove(tr + '-enter');
        old.forEach((o) => o.classList.add(tr + '-leave'));
      }));
      setTimeout(() => old.forEach(cleanupLayer), 900);
    } else {
      old.forEach(cleanupLayer);
      stage.insertBefore(el, stage.querySelector('.ov'));
    }
    document.getElementById('itemInfo').textContent = (items.length > 1 ? (idx + 1) + ' ' + T.of + ' ' + items.length + ' · ' : '') + (item.title || '') + (item.duration ? ' · ' + item.duration + 's' : '');
    if (!single) {
      if (item.duration > 0) timer = setTimeout(() => show(idx + 1, true), item.duration * 1000);
      else if (item.type === 'video') { const v = el.querySelector('video'); if (v) v.addEventListener('ended', () => show(idx + 1, true), { once: true }); }
    }
  }
  function cleanupLayer(o) {
    if (o._stop) o._stop();
    o.querySelectorAll('video').forEach((v) => { v.pause(); v.removeAttribute('src'); v.load(); });
    o.remove();
  }

  // 1 dp on the TV (960 dp wide) = 0.2em of the stage (1em = 1/192 of its width).
  const DP = 0.2;
  function ticker(tk) {
    const font = Math.max(14, Math.min(72, Number(tk.font_size) || 26));
    const height = Math.max(32, Math.min(200, Number(tk.height) || 56), font * 1.4); // the bar grows with the font
    const top = tk.position === 'top';
    const reserve = tk.reserve_space !== false && tk.reserve_space !== 0;
    stage.style.setProperty(top ? '--tk-top' : '--tk-bottom', reserve ? (height * DP) + 'em' : '0px');
    stage.style.setProperty('--tk-fit', reserve ? ({ fit: 'contain', zoom: 'cover' }[tk.video_scale] || 'fill') : 'contain');
    const bar = document.createElement('div');
    bar.className = 'ov ov-tick ' + (top ? 'pos-top' : 'pos-bottom');
    const col = (c, d) => (/^#[0-9a-f]{6}$/i.test(String(c)) ? c : d);
    bar.style.cssText = 'background:' + col(tk.bg_color, '#000000') + ';color:' + col(tk.text_color, '#FFD700') + ';height:' + (height / font) + 'em;font-size:' + (font * DP) + 'em'; // the bar's own em = its font size
    if (!reserve) bar.style.opacity = '.92';
    const span = document.createElement('span');
    span.textContent = Array.isArray(tk.messages) && tk.messages.length ? tk.messages.join('   ✦   ') : tk.text;
    bar.appendChild(span);
    stage.appendChild(bar);
    // Same speed as the TV app: 30 + 25 × speed dp per second, from the right edge until the text has left.
    const emPx = parseFloat(getComputedStyle(span).fontSize) || 1;
    const widthEm = span.scrollWidth / emPx, stageEm = 192 / (font * DP);
    const dpPerSec = 30 + Math.max(1, Math.min(10, Number(tk.speed) || 5)) * 25;
    const dur = ((stageEm + widthEm) * font) / dpPerSec * 1000;
    if (span.animate) span.animate([{ transform: 'translateX(' + stageEm + 'em)' }, { transform: 'translateX(' + (-widthEm) + 'em)' }], { duration: Math.max(1000, dur), iterations: Infinity });
  }

  function overlays() {
    const ov = content.overlay || {};
    const frag = document.createElement('div');
    frag.className = 'ov';
    frag.style.cssText = 'left:0;right:0;top:var(--tk-top,0px);bottom:var(--tk-bottom,0px)';
    let html = '';
    if (ov.logo && content.hotel && content.hotel.logo_url) html += '<div class="ov ov-logo"><img alt="" src="' + esc(content.hotel.logo_url) + '"></div>';
    if (ov.clock || (ov.weather && ov.weather.enabled)) {
      html += '<div class="ov ov-tr">' + (ov.clock ? '<div class="c" data-clock></div>' : '')
        + (ov.weather && ov.weather.enabled ? '<div class="w">' + esc(ov.weather.icon || '') + ' ' + esc(ov.weather.temp_c) + '°C ' + esc(ov.weather.city || '') + '</div>' : '') + '</div>';
    }
    frag.innerHTML = html;
    stage.appendChild(frag);
    if (ov.ticker && (ov.ticker.text || (ov.ticker.messages || []).length)) ticker(ov.ticker);
    const c = frag.querySelector('[data-clock]');
    if (c) every(1000, () => { c.textContent = fmtTime(new Date(), ov.clock_format); });
  }

  function render() {
    clearTimers();
    hlsList.forEach((h) => { try { h.destroy(); } catch (e) { /* ignore */ } }); hlsList = [];
    stage.innerHTML = '';
    stage.style.removeProperty('--tk-top'); stage.style.removeProperty('--tk-bottom'); stage.style.removeProperty('--tk-fit');
    stage.classList.toggle('emergency', content.mode === 'emergency');
    const badge = document.getElementById('modeBadge');
    badge.textContent = T.modes[content.mode] || content.mode;
    badge.className = 'mode m-' + content.mode;
    document.getElementById('itemInfo').textContent = '';
    if (content.mode === 'off' || content.screen_on === false) {
      stage.innerHTML = '<div class="badge-off"><i class="bi bi-power"></i> ' + esc(T.off) + '</div>';
      return;
    }
    if (!content.items || !content.items.length) {
      const h = content.hotel || {};
      stage.innerHTML = '<div class="layer"><div class="welcome">' + (h.logo_url ? '<img alt="" src="' + esc(h.logo_url) + '">' : '')
        + '<div class="w1">' + esc(T.welcome) + '</div><div class="w2">' + esc(h.name || '') + '</div>'
        + (content.room && content.room.number ? '<div class="w2">' + esc(T.room) + ' ' + esc(content.room.number) + '</div>' : '') + '</div></div>';
      overlays();
      return;
    }
    if (content.mode !== 'emergency') overlays();
    show(0, false);
  }

  document.getElementById('btnNext').addEventListener('click', () => show(idx + 1, true));
  document.getElementById('btnPrev').addEventListener('click', () => show(idx - 1, true));
  document.getElementById('btnMute').addEventListener('click', (ev) => {
    muted = !muted;
    stage.querySelectorAll('video').forEach((v) => { v.muted = muted || v.dataset.mute === '1'; if (!muted) v.play().catch(() => {}); });
    ev.currentTarget.innerHTML = '<i class="bi ' + (muted ? 'bi-volume-mute-fill' : 'bi-volume-up-fill') + '"></i>';
  });
  document.getElementById('btnFs').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else (stage.requestFullscreen || stage.webkitRequestFullscreen || (() => {})).call(stage);
  });
  document.addEventListener('fullscreenchange', () => setTimeout(() => {
    if (document.fullscreenElement) { const w = screen.width, h = screen.height; stage.style.fontSize = (Math.min(w, h * 16 / 9) / 192) + 'px'; } else fit();
  }, 50));

  window.addEventListener('DOMContentLoaded', render);

  // Room previews follow the live content (like the TV's polling).
  if (refreshUrl) {
    setInterval(async () => {
      if (document.hidden) return;
      try {
        const res = await fetch(refreshUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
        const j = await res.json();
        if (j.ok && j.data.hash !== content.hash) { content = j.data; render(); }
      } catch (e) { /* offline: keep showing */ }
    }, 10000);
  }
})();
</script>
</body>
</html>
