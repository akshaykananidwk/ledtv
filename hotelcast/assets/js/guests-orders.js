/* HotelCast Orders & requests board (admin/orders.php): live refresh every 10 s, status buttons, KOT. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const board = document.getElementById('goBoard');
    if (!board || !window.HC) return;
    const HC = window.HC;
    const esc = HC.esc;
    let L = {};
    try { L = JSON.parse(board.dataset.labels || '{}'); } catch (e) { L = {}; }
    const kotUrl = board.dataset.kot;
    const notifyBox = document.getElementById('goNotifyTv');
    const ordersEl = board.querySelector('[data-orders]');
    const requestsEl = board.querySelector('[data-requests]');
    const statusCls = { new: 'text-bg-danger', accepted: 'text-bg-primary', preparing: 'text-bg-warning', delivered: 'text-bg-success', cancelled: 'text-bg-secondary', open: 'text-bg-danger', done: 'text-bg-success' };
    const food = { veg: '<span class="go-food go-veg" title="' + esc(L.veg) + '"></span>', nonveg: '<span class="go-food go-nonveg" title="' + esc(L.nonveg) + '"></span>', egg: '<span class="go-food go-egg" title="' + esc(L.egg) + '"></span>', none: '' };
    const btnFor = {
      accepted: ['btn-primary', 'bi-check2', L.accept],
      preparing: ['btn-warning', 'bi-fire', L.prepare],
      delivered: ['btn-success', 'bi-truck', L.deliver],
      cancelled: ['btn-outline-danger', 'bi-x-lg', L.cancel],
    };

    const orderCard = (o) => {
      const open = ['new', 'accepted', 'preparing'].indexOf(o.status) !== -1;
      const items = o.items.map((i) => '<li>' + (food[i.food_type] || '') + ' <strong>' + i.qty + '×</strong> ' + esc(i.name) + '</li>').join('');
      const buttons = (o.next || []).filter((s) => !(o.status === 'new' && (s === 'preparing' || s === 'delivered')) && !(o.status === 'accepted' && s === 'delivered'))
        .map((s) => { const b = btnFor[s]; return b ? '<button type="button" class="btn btn-sm ' + b[0] + '" data-order="' + o.id + '" data-status="' + s + '"><i class="bi ' + b[1] + '"></i> ' + esc(b[2]) + '</button>' : ''; }).join(' ');
      return '<div class="col-md-6 col-xxl-4"><div class="card h-100 go-order go-' + o.status + (open ? '' : ' opacity-75') + '"><div class="card-body p-2 d-flex flex-column">'
        + '<div class="d-flex align-items-center gap-2"><span class="fs-5 fw-bold">' + esc(L.room) + ' ' + esc(o.room) + '</span>'
        + '<span class="badge ' + (statusCls[o.status] || 'text-bg-light') + ' ms-auto">' + esc(L[o.status] || o.status) + '</span></div>'
        + '<div class="small text-muted">#' + o.id + ' · ' + esc(o.created) + ' (' + esc(o.ago) + ')' + (o.guest ? ' · ' + esc(o.guest) : '') + '</div>'
        + '<ul class="list-unstyled my-2 small">' + items + '</ul>'
        + (o.notes ? '<div class="small alert alert-warning py-1 px-2 mb-2"><i class="bi bi-chat-left-text"></i> ' + esc(o.notes) + '</div>' : '')
        + '<div class="d-flex align-items-center mt-auto gap-1 flex-wrap"><strong class="me-auto">' + esc(o.total) + '</strong>'
        + '<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="' + esc(kotUrl + '?kot=' + o.id) + '" title="' + esc(L.kot) + '"><i class="bi bi-printer"></i></a> ' + buttons + '</div>'
        + '</div></div></div>';
    };

    const requestRow = (r) => {
      const open = r.status === 'open';
      return '<div class="list-group-item' + (open ? '' : ' opacity-75') + '"><div class="d-flex gap-2 align-items-start">'
        + '<span class="fs-4">' + esc(r.icon || '🛎️') + '</span><div class="flex-grow-1 min-w-0">'
        + '<div><strong>' + esc(L.room) + ' ' + esc(r.room) + '</strong> · ' + esc(r.type) + (r.time ? ' <span class="badge text-bg-info">⏰ ' + esc(r.time) + '</span>' : '') + '</div>'
        + '<div class="small text-muted">' + esc(r.created) + ' (' + esc(r.ago) + ')' + (r.guest ? ' · ' + esc(r.guest) : '') + '</div>'
        + (r.notes ? '<div class="small">' + esc(r.notes) + '</div>' : '') + '</div>'
        + (open ? '<button type="button" class="btn btn-sm btn-success" data-request="' + r.id + '" data-status="done"><i class="bi bi-check2"></i> ' + esc(L.done_btn) + '</button>'
                : '<span class="badge ' + (statusCls[r.status] || '') + '">' + esc(L[r.status] || r.status) + '</span> <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-request="' + r.id + '" data-status="open">' + esc(L.reopen) + '</button>')
        + '</div></div>';
    };

    const setCounts = (d) => {
      board.querySelectorAll('[data-count]').forEach((el) => { const v = d[el.dataset.count]; if (v !== undefined) { el.textContent = v; el.hidden = !v; } });
    };

    const render = (d) => {
      ordersEl.innerHTML = d.orders.length ? d.orders.map(orderCard).join('') : '<div class="col-12 text-muted small p-3">' + esc(L.none_orders) + '</div>';
      requestsEl.innerHTML = d.requests.length ? d.requests.map(requestRow).join('') : '<div class="list-group-item text-muted small">' + esc(L.none_requests) + '</div>';
      setCounts(d);
    };

    const load = async () => render(await HC.api('guests_board'));

    board.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button[data-status]');
      if (!b) return;
      const isOrder = b.dataset.order !== undefined;
      const status = b.dataset.status;
      if (isOrder && status === 'cancelled' && !(await HC.confirm(L.confirm_cancel))) return;
      b.disabled = true;
      try {
        await HC.api(isOrder ? 'guests_order_status' : 'guests_request_status', {
          data: { id: parseInt(isOrder ? b.dataset.order : b.dataset.request, 10), status, notify_tv: !!(notifyBox && notifyBox.checked) },
        });
        HC.toast(L.updated, 'success', 1500);
        await load();
      } catch (e) { HC.toast(e.message, 'danger'); b.disabled = false; }
    });

    const test = document.getElementById('goSoundTest');
    if (test) test.addEventListener('click', () => { if (window.HCGuestAlerts) window.HCGuestAlerts.beep(); });
    document.addEventListener('hc:guest-alert', () => load().catch(() => {}));
    HC.every(10000, load);
  });
})();
