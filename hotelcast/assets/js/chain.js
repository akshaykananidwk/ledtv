/*
 * HotelCast — hotel chain pages (admin/chain*.php).
 *  - table[data-sortable]: click a th[data-sort="num|text"] to sort by the cells' data-v value
 *  - [data-chain-view] buttons switch [data-chain-panel] table / cards (remembered per browser)
 *  - input[data-chain-filter] filters [data-hotel-row] by data-name
 *  - [data-check-all="name"] (un)ticks every checkbox input[name="name"] in its form
 *  - #chainCfg {chain, refresh}: refreshes the TVs online / offline numbers every N seconds
 */
(function () {
  'use strict';

  function store(key, val) {
    try {
      if (val === undefined) return window.localStorage.getItem(key);
      window.localStorage.setItem(key, val);
    } catch (e) { /* private mode */ }
    return null;
  }

  function sortable(table) {
    var heads = table.querySelectorAll('thead th[data-sort]');
    Array.prototype.forEach.call(heads, function (th) {
      th.style.cursor = 'pointer';
      th.setAttribute('role', 'button');
      th.setAttribute('tabindex', '0');
      th.setAttribute('aria-sort', 'none');
      var go = function () {
        var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        Array.prototype.forEach.call(heads, function (h) { h.setAttribute('aria-sort', 'none'); h.classList.remove('sorted-asc', 'sorted-desc'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
        var num = th.getAttribute('data-sort') === 'num';
        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        rows.sort(function (a, b) {
          var ca = a.cells[idx], cb = b.cells[idx];
          var va = ca ? (ca.getAttribute('data-v') || ca.textContent) : '';
          var vb = cb ? (cb.getAttribute('data-v') || cb.textContent) : '';
          var r = num ? (parseFloat(va) || 0) - (parseFloat(vb) || 0) : String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
          return asc ? r : -r;
        });
        rows.forEach(function (r) { body.appendChild(r); });
      };
      th.addEventListener('click', go);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
  }

  function views() {
    var btns = document.querySelectorAll('[data-chain-view]');
    if (!btns.length) return;
    var show = function (v) {
      Array.prototype.forEach.call(document.querySelectorAll('[data-chain-panel]'), function (p) { p.hidden = p.getAttribute('data-chain-panel') !== v; });
      Array.prototype.forEach.call(btns, function (b) { var on = b.getAttribute('data-chain-view') === v; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      store('hc_chain_view', v);
    };
    Array.prototype.forEach.call(btns, function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-chain-view')); }); });
    var saved = store('hc_chain_view');
    show(saved === 'cards' || saved === 'table' ? saved : (window.innerWidth < 768 ? 'cards' : 'table'));
  }

  function filter() {
    var input = document.querySelector('[data-chain-filter]');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      Array.prototype.forEach.call(document.querySelectorAll('[data-hotel-row]'), function (r) {
        r.hidden = q !== '' && (r.getAttribute('data-name') || '').indexOf(q) === -1;
      });
    });
  }

  function checkAll() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-check-all]'), function (b) {
      b.addEventListener('click', function () {
        var form = b.closest('form') || document;
        var boxes = form.querySelectorAll('input[type="checkbox"][name="' + b.getAttribute('data-check-all') + '"]');
        var anyOff = Array.prototype.some.call(boxes, function (x) { return !x.checked && !x.disabled; });
        Array.prototype.forEach.call(boxes, function (x) { if (!x.disabled) x.checked = anyOff; });
      });
    });
  }

  function kindSwitch() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-kind-switch]'), function (group) {
      var form = group.closest('form');
      var update = function () {
        var sel = form.querySelector('input[name="kind"]:checked');
        var kind = sel ? sel.value : '';
        Array.prototype.forEach.call(form.querySelectorAll('[data-kinds]'), function (el) {
          var on = (' ' + el.getAttribute('data-kinds') + ' ').indexOf(' ' + kind + ' ') !== -1;
          el.hidden = !on;
          Array.prototype.forEach.call(el.querySelectorAll('input,select,textarea'), function (i) { i.disabled = !on; });
        });
      };
      Array.prototype.forEach.call(form.querySelectorAll('input[name="kind"]'), function (r) { r.addEventListener('change', update); });
      update();
    });
  }

  function refresh() {
    var cfgEl = document.getElementById('chainCfg');
    var ajax = document.querySelector('meta[name="hc-ajax"]');
    if (!cfgEl || !ajax || !window.fetch) return;
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
    var every = Math.max(30, parseInt(cfg.refresh, 10) || 60) * 1000;
    var tick = function () {
      if (document.hidden) return;
      fetch(ajax.content + '?action=chain_overview&chain=' + encodeURIComponent(cfg.chain), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j || !j.ok) return;
        (j.data.hotels || []).forEach(function (h) {
          ['online', 'offline'].forEach(function (k) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-h="' + h.id + '"][data-k="' + k + '"]'), function (el) { el.textContent = h[k]; });
          });
        });
      }).catch(function () { /* offline: keep the last numbers */ });
    };
    window.setInterval(tick, every);
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('table[data-sortable]'), sortable);
    views();
    filter();
    checkAll();
    kindSwitch();
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
