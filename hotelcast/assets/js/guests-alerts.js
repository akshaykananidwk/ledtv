/*
 * HotelCast live guest-service alerts (every admin page, users with services.manage):
 * polls guests_alerts every 10 s, shows a toast + plays a short sound for new orders / requests and
 * keeps a bell badge (new orders + open requests) in the top bar. Exposes window.HCGuestAlerts.beep().
 */
(function () {
  'use strict';
  const cfgEl = document.getElementById('hcGuestAlertsConfig');
  if (!cfgEl) return;
  let cfg = {};
  try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
  const T = cfg.i18n || {};
  const storeKey = 'hc_guest_alerts_h' + cfg.hotel;
  const read = () => { try { return JSON.parse(localStorage.getItem(storeKey) || 'null'); } catch (e) { return null; } };
  const write = (v) => { try { localStorage.setItem(storeKey, JSON.stringify(v)); } catch (e) { /* private mode */ } };

  // ---- sound (Web Audio, no file; unlocked by the first user gesture)
  let ctx = null;
  const unlock = () => {
    try {
      if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
      if (ctx.state === 'suspended') ctx.resume();
    } catch (e) { ctx = null; }
  };
  ['click', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, unlock, { once: true, passive: true }));
  const beep = () => {
    unlock();
    if (!ctx) return;
    const t0 = ctx.currentTime;
    [[880, 0], [1320, 0.18], [880, 0.36]].forEach(([f, d]) => {
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.type = 'sine';
      o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, t0 + d);
      g.gain.exponentialRampToValueAtTime(0.35, t0 + d + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + d + 0.16);
      o.connect(g); g.connect(ctx.destination);
      o.start(t0 + d); o.stop(t0 + d + 0.17);
    });
  };
  window.HCGuestAlerts = { beep };

  const fmt = (s, vars) => String(s || '').replace(/:(\w+)/g, (m, k) => (vars[k] !== undefined ? vars[k] : m));

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.HC) return;
    const HC = window.HC;

    // Bell badge in the top bar.
    const bar = document.querySelector('.hc-topbar .ms-auto');
    let badge = null;
    if (bar) {
      badge = document.createElement('a');
      badge.href = cfg.orders_url;
      badge.className = 'badge rounded-pill text-bg-danger text-decoration-none';
      badge.id = 'hcGuestBadge';
      badge.title = T.badge || '';
      badge.setAttribute('aria-label', T.badge || '');
      badge.hidden = true;
      badge.innerHTML = '<i class="bi bi-bell-fill"></i> <span></span>';
      bar.insertBefore(badge, bar.firstChild);
    }
    const baseTitle = document.title;

    const tick = async () => {
      const seen = read();
      const d = await HC.api('guests_alerts', { params: { order: seen ? seen.o : 0, request: seen ? seen.r : 0 } });
      const n = (d.new_orders || 0) + (d.open_requests || 0);
      if (badge) { badge.hidden = n === 0; badge.querySelector('span').textContent = n; }
      document.title = (n ? '(' + n + ') ' : '') + baseTitle;
      if (!seen) {
        // First run in this browser: remember the current state, do not replay old alerts.
        write({ o: d.last_order_id, r: d.last_request_id });
        return;
      }
      const fresh = (d.orders || []).length + (d.requests || []).length;
      if (fresh > 0) {
        (d.orders || []).slice().reverse().forEach((o) => HC.toast('🛎️ ' + fmt(T.new_order, { room: o.room }) + ' · ' + o.items + ' × · ' + o.total, 'warning', 12000));
        (d.requests || []).slice().reverse().forEach((r) => HC.toast('🔔 ' + fmt(T.new_request, { room: r.room, type: r.type }) + (r.time ? ' ⏰ ' + r.time : ''), 'info', 12000));
        beep();
        document.dispatchEvent(new CustomEvent('hc:guest-alert', { detail: d }));
      }
      write({ o: Math.max(seen.o || 0, d.last_order_id || 0), r: Math.max(seen.r || 0, d.last_request_id || 0) });
    };
    HC.every(cfg.interval || 10000, tick);
  });
})();
