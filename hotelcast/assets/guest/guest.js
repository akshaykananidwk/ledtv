/* HotelCast guest web app: room-service menu + cart + live order status, requests, feedback, info.
 * Vanilla JS, no dependencies. Config + initial data are embedded by g/index.php (#gConfig). */
(function () {
  'use strict';
  const cfgEl = document.getElementById('gConfig');
  if (!cfgEl) return;
  const CFG = JSON.parse(cfgEl.textContent || '{}');
  let D = CFG.data || {};
  const LS = (() => { try { return window.localStorage; } catch (e) { return null; } })();
  const store = {
    get(k, d) { try { const v = LS && LS.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
    set(k, v) { try { if (LS) LS.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } },
  };
  const tokenKey = 'g_' + (CFG.api || '').slice(-12);
  let lang = store.get('g_lang', null) || CFG.lang || 'en';
  if (!CFG.strings || !CFG.strings[lang]) lang = CFG.lang || 'en';
  let cart = store.get(tokenKey + '_cart', {}) || {};
  let tab = 'menu';
  let activeCat = null;
  let pendingRequest = null;
  let fb = null;

  const $ = (s, r) => (r || document).querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const t = (k, vars) => {
    let s = (CFG.strings[lang] && CFG.strings[lang][k]) || k;
    if (vars) Object.keys(vars).forEach((v) => { s = s.split(':' + v).join(vars[v]); });
    return s;
  };
  const L = (obj) => (obj && (obj[lang] || obj.en)) || '';
  const money = (v) => (D.currency || '₹') + ' ' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const main = $('#gMain');

  // ------------------------------------------------------------------ API
  async function api(path, body) {
    const url = CFG.api + (path ? encodeURIComponent('/' + path) : '');
    let res;
    try {
      res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: body ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'omit', cache: 'no-store',
      });
    } catch (e) { throw new Error(t('Offline — check your Wi-Fi connection.')); }
    let j = null;
    try { j = await res.json(); } catch (e) { /* not json */ }
    if (res.status === 404 && j && j.error && j.error.code === 'INVALID_TOKEN') { expired(); throw new Error(''); }
    if (res.status === 429) throw new Error(t('Too many requests. Please wait a moment.'));
    if (!j || !j.ok) throw new Error((j && j.error && j.error.code === 'ITEM_UNAVAILABLE' ? j.error.message : '') || t('Something went wrong. Please try again.'));
    return j.data;
  }

  function expired() {
    document.body.innerHTML = '<main class="g-expired-box"><div class="g-expired-icon">🔑</div><h1>' + esc(t('This link is not valid any more')) + '</h1><p>' + esc(t('Please scan the QR code on your TV again, or call reception.')) + '</p></main>';
    document.body.className = 'g-expired';
  }

  let toastTimer = null;
  function toast(msg, bad) {
    if (!msg) return;
    const el = $('#gToast');
    el.textContent = msg;
    el.className = 'g-toast' + (bad ? ' g-bad' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, 3500);
  }

  // ------------------------------------------------------------------ header / tabs
  function tabsList() {
    const f = D.features || {};
    const list = [];
    if (f.services) list.push(['menu', '🍽️', t('Room Service')]);
    if (f.requests) list.push(['requests', '🛎️', t('Requests')]);
    if (f.feedback) list.push(['feedback', '⭐', t('Feedback')]);
    list.push(['info', 'ℹ️', t('Info')]);
    return list;
  }

  function renderHeader() {
    document.documentElement.lang = lang;
    document.querySelectorAll('.g-langs button').forEach((b) => b.setAttribute('aria-pressed', b.dataset.lang === lang ? 'true' : 'false'));
    const room = $('[data-t-room]');
    if (room) room.textContent = t('Room :room', { room: D.room.number });
    const greet = $('[data-greet]');
    greet.textContent = D.guest ? t('Welcome :name', { name: L(D.guest.name) }) : '';
    const openCount = ((D.status && D.status.orders) || []).filter((o) => ['new', 'accepted', 'preparing'].indexOf(o.status) !== -1).length;
    $('#gTabs').innerHTML = tabsList().map(([id, icon, label]) => '<button type="button" role="tab" data-tab="' + id + '" aria-selected="' + (tab === id) + '">'
      + '<span class="g-ti" aria-hidden="true">' + icon + '</span>' + esc(label)
      + (id === 'menu' && openCount ? '<span class="g-dot">' + openCount + '</span>' : '') + '</button>').join('');
  }

  function render() {
    renderHeader();
    if (tab === 'menu') renderMenu();
    else if (tab === 'requests') renderRequests();
    else if (tab === 'feedback') renderFeedback();
    else renderInfo();
    renderCartBar();
  }

  function setTab(id, push) {
    if (!tabsList().some((x) => x[0] === id)) id = tabsList()[0][0];
    tab = id;
    if (push !== false && history.replaceState) history.replaceState(null, '', '#' + id);
    render();
    window.scrollTo(0, 0);
  }

  // ------------------------------------------------------------------ menu + orders
  const steps = ['new', 'accepted', 'preparing', 'delivered'];
  const stLabel = { new: 'Received', accepted: 'Accepted', preparing: 'Preparing', delivered: 'Delivered', cancelled: 'Cancelled', open: 'Sent', done: 'Done' };

  function ordersHtml() {
    const orders = (D.status && D.status.orders) || [];
    if (!orders.length) return '';
    return '<h2 class="g-h2">' + esc(t('Your orders')) + '</h2>' + orders.slice(0, 5).map((o) => {
      const idx = steps.indexOf(o.status);
      return '<div class="g-card"><div class="g-order-head"><strong>' + esc(t('Order #:n', { n: o.id })) + '</strong>'
        + '<span class="g-status g-st-' + esc(o.status) + '">' + esc(t(stLabel[o.status] || o.status)) + '</span></div>'
        + (o.status !== 'cancelled' ? '<div class="g-steps">' + steps.map((s, i) => '<span class="' + (i <= idx ? 'on' : '') + '"></span>').join('') + '</div>' : '')
        + '<div class="g-small">' + o.items.map((i) => esc(i.qty + '× ' + itemName(i))).join(', ') + ' · ' + esc(money(o.total)) + '</div></div>';
    }).join('');
  }

  function itemName(line) {
    for (const c of D.menu || []) for (const it of c.items) if (it.id === line.item_id) return L(it.name);
    return line.name;
  }

  function findItem(id) {
    for (const c of D.menu || []) for (const it of c.items) if (it.id === id) return it;
    return null;
  }

  function renderMenu() {
    const cats = D.menu || [];
    if (!cats.length) { main.innerHTML = ordersHtml() + '<div class="g-card g-empty">' + esc(t('The menu is not available right now.')) + '</div>'; return; }
    if (!activeCat || !cats.some((c) => c.id === activeCat)) activeCat = cats[0].id;
    let h = ordersHtml();
    h += '<div class="g-cats" role="group">' + cats.map((c) => '<button type="button" data-cat="' + c.id + '" aria-pressed="' + (c.id === activeCat) + '">' + esc(L(c.name)) + '</button>').join('') + '</div>';
    const cat = cats.find((c) => c.id === activeCat);
    h += '<div class="g-card">' + cat.items.map((it) => {
      const q = cart[it.id] || 0;
      const ctl = !it.available ? '<span class="g-hours">' + esc(it.hours ? t('Available :h', { h: it.hours }) : t('Not available now')) + '</span>'
        : (q ? '<span class="g-stepper"><button type="button" data-dec="' + it.id + '" aria-label="−">−</button><span>' + q + '</span><button type="button" data-inc="' + it.id + '" aria-label="+">+</button></span>'
          : '<button type="button" class="g-add" data-inc="' + it.id + '">' + esc(t('Add')) + '</button>');
      return '<div class="g-item' + (it.available ? '' : ' g-off') + '">'
        + (it.photo_url ? '<img class="g-item-photo" loading="lazy" alt="" src="' + esc(it.photo_url) + '">' : '')
        + '<div class="g-item-body"><div class="g-item-name"><span class="g-food g-food-' + esc(it.food_type) + '" title="' + esc(t(it.food_type === 'nonveg' ? 'Non-veg' : it.food_type === 'egg' ? 'Egg' : 'Veg')) + '"></span>' + esc(L(it.name)) + '</div>'
        + (L(it.description) ? '<div class="g-item-desc">' + esc(L(it.description)) + '</div>' : '')
        + '<div class="g-item-foot"><span class="g-price">' + esc(money(it.price)) + '</span>' + ctl + '</div></div></div>';
    }).join('') + '</div>';
    main.innerHTML = h;
  }

  function cartLines() {
    return Object.keys(cart).map((id) => ({ item: findItem(parseInt(id, 10)), qty: cart[id] })).filter((l) => l.item && l.qty > 0);
  }
  function cartTotal() { return cartLines().reduce((s, l) => s + l.item.price * l.qty, 0); }
  function saveCart() {
    Object.keys(cart).forEach((k) => { if (!cart[k] || !findItem(parseInt(k, 10))) delete cart[k]; });
    store.set(tokenKey + '_cart', cart);
  }

  function renderCartBar() {
    const bar = $('#gCartBar');
    const lines = cartLines();
    const n = lines.reduce((s, l) => s + l.qty, 0);
    const show = tab === 'menu' && n > 0;
    bar.hidden = !show;
    document.body.classList.toggle('g-has-cart', show);
    if (show) bar.innerHTML = '<button type="button" data-cart><span>🛒 ' + n + ' ' + esc(t('items')) + ' · ' + esc(money(cartTotal())) + '</span><span>' + esc(t('View cart')) + ' ›</span></button>';
  }

  function openSheet(html) {
    $('#gSheetPanel').innerHTML = html;
    $('#gSheet').hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeSheet() {
    $('#gSheet').hidden = true;
    document.body.style.overflow = '';
    pendingRequest = null;
  }

  function renderCartSheet() {
    const lines = cartLines();
    if (!lines.length) { closeSheet(); return; }
    openSheet('<div class="g-sheet-title">' + esc(t('Your cart')) + '<button type="button" class="g-close" data-close aria-label="' + esc(t('Close')) + '">✕</button></div>'
      + lines.map((l) => '<div class="g-line"><div class="g-line-name"><span class="g-food g-food-' + esc(l.item.food_type) + '"></span>' + esc(L(l.item.name)) + '<div class="g-small">' + esc(money(l.item.price)) + '</div></div>'
        + '<span class="g-stepper"><button type="button" data-dec="' + l.item.id + '" data-in-sheet aria-label="−">−</button><span>' + l.qty + '</span><button type="button" data-inc="' + l.item.id + '" data-in-sheet aria-label="+">+</button></span></div>').join('')
      + '<div class="g-total"><span>' + esc(t('Total')) + '</span><span>' + esc(money(cartTotal())) + '</span></div>'
      + '<label class="g-label" for="gNotes">' + esc(t('Notes for the kitchen (optional)')) + '</label><textarea id="gNotes" maxlength="300">' + esc(store.get(tokenKey + '_notes', '')) + '</textarea>'
      + '<div class="g-actions"><button type="button" class="g-btn g-btn-light" data-clear>' + esc(t('Clear cart')) + '</button><button type="button" class="g-btn g-btn-ok" data-order>' + esc(t('Place order')) + '</button></div>');
  }

  async function placeOrder(btn) {
    const items = cartLines().map((l) => ({ id: l.item.id, qty: l.qty }));
    if (!items.length) return;
    btn.disabled = true;
    try {
      const notes = ($('#gNotes') || {}).value || '';
      const o = await api('order', { items, notes });
      cart = {}; saveCart(); store.set(tokenKey + '_notes', '');
      D.status = D.status || { orders: [], requests: [] };
      D.status.orders.unshift(o);
      closeSheet();
      toast(t('Order placed! We will keep you updated here.'));
      render();
    } catch (e) { toast(e.message, true); btn.disabled = false; }
  }

  // ------------------------------------------------------------------ requests
  function renderRequests() {
    const types = D.request_types || [];
    let h = '<h2 class="g-h2">' + esc(t('What do you need?')) + '</h2><div class="g-grid">'
      + types.map((r) => '<button type="button" class="g-req" data-req="' + r.id + '"><span class="g-req-icon" aria-hidden="true">' + esc(r.icon || '🛎️') + '</span>' + esc(L(r.name)) + '</button>').join('') + '</div>';
    const reqs = (D.status && D.status.requests) || [];
    h += '<h2 class="g-h2 g-mt">' + esc(t('Your requests')) + '</h2>';
    h += reqs.length ? '<div class="g-card">' + reqs.slice(0, 10).map((r) => {
      const type = types.find((x) => x.id === r.type_id);
      const when = r.time ? ' · ⏰ ' + new Date(r.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
      return '<div class="g-line"><div class="g-line-name">' + esc((type ? (type.icon + ' ' + L(type.name)) : r.type)) + '<div class="g-small">' + esc(new Date(r.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + when) + '</div></div>'
        + '<span class="g-status g-st-' + esc(r.status) + '">' + esc(t(stLabel[r.status] || r.status)) + '</span></div>';
    }).join('') + '</div>' : '<div class="g-card g-empty">' + esc(t('No requests yet.')) + '</div>';
    main.innerHTML = h;
  }

  function openRequest(id) {
    const r = (D.request_types || []).find((x) => x.id === id);
    if (!r) return;
    pendingRequest = r;
    openSheet('<div class="g-sheet-title">' + esc(r.icon + ' ' + L(r.name)) + '<button type="button" class="g-close" data-close aria-label="' + esc(t('Close')) + '">✕</button></div>'
      + (r.needs_time ? '<label class="g-label" for="gReqTime">' + esc(t('Time')) + '</label><input type="time" id="gReqTime" required>' : '')
      + '<label class="g-label" for="gReqNotes">' + esc(t('Note (optional)')) + '</label><textarea id="gReqNotes" maxlength="300"></textarea>'
      + '<div class="g-actions"><button type="button" class="g-btn g-btn-light" data-close>' + esc(t('Cancel')) + '</button><button type="button" class="g-btn" data-send-req>' + esc(t('Send request')) + '</button></div>');
    const tm = $('#gReqTime');
    if (tm) { const d = new Date(Date.now() + 8 * 3600e3); tm.value = String(d.getHours()).padStart(2, '0') + ':00'; }
  }

  async function sendRequest(btn) {
    const r = pendingRequest;
    if (!r) return;
    const body = { type_id: r.id, notes: ($('#gReqNotes') || {}).value || '' };
    if (r.needs_time) {
      body.time = ($('#gReqTime') || {}).value || '';
      if (!/^\d{2}:\d{2}$/.test(body.time)) { toast(t('Please choose a time.'), true); return; }
    }
    btn.disabled = true;
    try {
      const res = await api('request', body);
      D.status = D.status || { orders: [], requests: [] };
      if (!D.status.requests.some((x) => x.id === res.id)) D.status.requests.unshift(res);
      closeSheet();
      toast(t('Request sent. Our team is on it.'));
      render();
    } catch (e) { toast(e.message, true); btn.disabled = false; }
  }

  // ------------------------------------------------------------------ feedback
  function starsHtml(name, value, small) {
    let h = '<div class="g-stars' + (small ? ' g-small-stars' : '') + '" role="radiogroup" data-stars="' + name + '">';
    for (let i = 1; i <= 5; i++) h += '<button type="button" role="radio" aria-checked="' + (value === i) + '" aria-label="' + i + '" data-star="' + i + '" class="' + (i <= value ? 'on' : '') + '">★</button>';
    return h + '</div>';
  }

  function renderFeedback() {
    const prev = (D.status && D.status.feedback) || null;
    if (!fb) fb = prev ? { rating: prev.rating, cleanliness: prev.cleanliness || 0, staff: prev.staff || 0, food: prev.food || 0, comment: prev.comment || '' } : { rating: 0, cleanliness: 0, staff: 0, food: 0, comment: '' };
    main.innerHTML = '<div class="g-card"><h2 class="g-h2 g-center">' + esc(t('How was your stay?')) + '</h2>'
      + starsHtml('rating', fb.rating) + '<div class="g-small g-center">' + esc(t('Tap a star to rate')) + '</div>'
      + ['cleanliness', 'staff', 'food'].map((c) => '<div class="g-rate-row"><span>' + esc(t(c.charAt(0).toUpperCase() + c.slice(1))) + '</span>' + starsHtml(c, fb[c], true) + '</div>').join('')
      + '<label class="g-label" for="gFbComment">' + esc(t('Comment (optional)')) + '</label><textarea id="gFbComment" maxlength="1000">' + esc(fb.comment) + '</textarea>'
      + '<div class="g-actions"><button type="button" class="g-btn" data-send-fb>' + esc(t(prev ? 'Update feedback' : 'Send feedback')) + '</button></div>'
      + '<div class="g-review" id="gReview"></div></div>';
  }

  async function sendFeedback(btn) {
    fb.comment = ($('#gFbComment') || {}).value || '';
    if (!fb.rating) { toast(t('Please choose a rating.'), true); return; }
    btn.disabled = true;
    try {
      const body = { rating: fb.rating, comment: fb.comment };
      ['cleanliness', 'staff', 'food'].forEach((c) => { if (fb[c]) body[c] = fb[c]; });
      const res = await api('feedback', body);
      D.status = D.status || {};
      D.status.feedback = { rating: fb.rating, cleanliness: fb.cleanliness || null, staff: fb.staff || null, food: fb.food || null, comment: fb.comment };
      toast(t('Thank you for your feedback!'));
      btn.disabled = false;
      btn.textContent = t('Update feedback');
      const rv = $('#gReview');
      if (rv) {
        rv.innerHTML = res.google_review_url ? '<p>' + esc(t('Would you share your experience on Google?')) + '</p><a class="g-btn" target="_blank" rel="noopener noreferrer" href="' + esc(res.google_review_url) + '">⭐ ' + esc(t('Write a Google review')) + '</a>' : '';
      }
    } catch (e) { toast(e.message, true); btn.disabled = false; }
  }

  // ------------------------------------------------------------------ info
  function renderInfo() {
    let h = '<div class="g-card">';
    if (D.wifi && D.wifi.ssid) {
      h += '<div class="g-info-row"><span class="g-info-icon">📶</span><div class="g-info-main"><div class="g-small">' + esc(t('Wi-Fi')) + ' · ' + esc(t('Network')) + '</div><div class="g-info-val">' + esc(D.wifi.ssid) + '</div></div></div>';
      if (D.wifi.password) h += '<div class="g-info-row"><span class="g-info-icon">🔑</span><div class="g-info-main"><div class="g-small">' + esc(t('Password')) + '</div><div class="g-info-val">' + esc(D.wifi.password) + '</div></div><button type="button" class="g-link-btn" data-copy="' + esc(D.wifi.password) + '">' + esc(t('Copy')) + '</button></div>';
    }
    h += '<div class="g-info-row"><span class="g-info-icon">🧳</span><div class="g-info-main"><div class="g-small">' + esc(t(D.checkout_date ? 'Checkout' : 'Checkout time')) + '</div><div class="g-info-val">' + esc((D.checkout_date ? D.checkout_date + ', ' : '') + D.checkout_time) + '</div></div></div>';
    if (D.reception_phone) h += '<div class="g-info-row"><span class="g-info-icon">📞</span><div class="g-info-main"><div class="g-small">' + esc(t('Reception')) + '</div><div class="g-info-val">' + esc(D.reception_phone) + '</div></div><a class="g-btn" href="tel:' + esc(D.reception_phone.replace(/[^0-9+]/g, '')) + '">' + esc(t('Call reception')) + '</a></div>';
    h += '</div>';
    main.innerHTML = h;
  }

  // ------------------------------------------------------------------ events
  document.addEventListener('click', async (ev) => {
    const el = ev.target.closest('button, a');
    if (!el) {
      if (ev.target === $('#gSheet')) closeSheet();
      return;
    }
    const ds = el.dataset;
    if (ds.lang) { lang = ds.lang; store.set('g_lang', lang); render(); return; }
    if (ds.tab) { setTab(ds.tab); return; }
    if (ds.cat) { activeCat = parseInt(ds.cat, 10); renderMenu(); return; }
    if (ds.inc || ds.dec) {
      const id = parseInt(ds.inc || ds.dec, 10);
      cart[id] = Math.max(0, Math.min(20, (cart[id] || 0) + (ds.inc ? 1 : -1)));
      saveCart();
      if (ds.inSheet !== undefined) { const n = $('#gNotes'); if (n) store.set(tokenKey + '_notes', n.value); renderCartSheet(); }
      renderMenu(); renderCartBar();
      return;
    }
    if (ds.cart !== undefined) { renderCartSheet(); return; }
    if (ds.clear !== undefined) { cart = {}; saveCart(); closeSheet(); render(); return; }
    if (ds.order !== undefined) { placeOrder(el); return; }
    if (ds.close !== undefined) { closeSheet(); return; }
    if (ds.req) { openRequest(parseInt(ds.req, 10)); return; }
    if (ds.sendReq !== undefined) { sendRequest(el); return; }
    if (ds.star) {
      const group = el.closest('[data-stars]').dataset.stars;
      fb[group] = parseInt(ds.star, 10);
      const c = ($('#gFbComment') || {}).value;
      if (c !== undefined) fb.comment = c;
      renderFeedback();
      return;
    }
    if (ds.sendFb !== undefined) { sendFeedback(el); return; }
    if (ds.copy) {
      try { await navigator.clipboard.writeText(ds.copy); toast(t('Copied')); } catch (e) { window.prompt('', ds.copy); }
    }
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !$('#gSheet').hidden) closeSheet(); });

  // Live status (orders / requests) every 15 s while visible.
  async function poll() {
    if (document.hidden) return;
    try {
      const s = await api('status');
      const before = JSON.stringify(D.status && [D.status.orders, D.status.requests]);
      D.status = s;
      if (JSON.stringify([s.orders, s.requests]) !== before && $('#gSheet').hidden && (tab === 'menu' || tab === 'requests')) render();
      else renderHeader();
    } catch (e) { /* offline: keep showing the last state */ }
  }
  setInterval(poll, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  const initial = (location.hash || '').replace('#', '');
  saveCart();
  setTab(['menu', 'requests', 'feedback', 'info'].indexOf(initial) !== -1 ? initial : 'menu', false);
})();
