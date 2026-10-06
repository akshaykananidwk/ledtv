/* Advertiser portal (#19): confirmations, print, upload / text previews on the TV frame, live quote. */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  document.addEventListener('click', function (ev) {
    var p = ev.target.closest('[data-print]');
    if (p) { ev.preventDefault(); window.print(); }
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f.matches('form[data-confirm]') && !window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
  });

  // File preview in a TV frame.
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var box = document.querySelector(inp.getAttribute('data-preview'));
      if (!box) { return; }
      var scr = box.querySelector('.tv-screen');
      scr.textContent = '';
      var f = inp.files && inp.files[0];
      if (!f) { box.classList.add('d-none'); return; }
      var url = URL.createObjectURL(f);
      var el = document.createElement(f.type.indexOf('video/') === 0 ? 'video' : 'img');
      el.src = url;
      if (el.tagName === 'VIDEO') { el.muted = true; el.autoplay = true; el.loop = true; el.setAttribute('playsinline', ''); }
      scr.appendChild(el);
      box.classList.remove('d-none');
    });
  });

  // Text announcement live preview.
  document.querySelectorAll('form[data-text-preview]').forEach(function (f) {
    var box = f.querySelector('[data-tv-text]');
    function upd() {
      box.querySelector('strong').textContent = f.elements.text.value || '…';
      box.querySelector('span').textContent = f.elements.subtitle.value;
      box.style.background = f.elements.bg_color.value;
      box.style.color = f.elements.text_color.value;
    }
    f.addEventListener('input', upd);
    upd();
  });

  // Booking form: model switch + live quote (computed by the server).
  var form = document.getElementById('mktBook');
  if (!form) { return; }
  var out = form.querySelector('[data-quote]');
  var timer = null;
  function model() { var r = form.querySelector('input[name=pricing_model]:checked'); return r ? r.value : 'per_day'; }
  function applyModel() {
    var m = model();
    form.querySelectorAll('[data-cpm-only]').forEach(function (el) { el.hidden = m !== 'cpm'; });
    form.querySelectorAll('.mkt-pick').forEach(function (el) {
      var ok = el.getAttribute('data-models') === 'both' || el.getAttribute('data-models') === m;
      el.classList.toggle('mkt-off', !ok);
      el.querySelector('input').disabled = !ok;
    });
  }
  function cell(tr, text, right) { var td = document.createElement('td'); td.textContent = text; if (right) { td.className = 'text-end'; } tr.appendChild(td); return td; }
  function render(q) {
    out.textContent = '';
    if (!q) { var s = document.createElement('span'); s.className = 'text-muted'; s.textContent = '—'; out.appendChild(s); return; }
    if (q.errors && q.errors.length) {
      var al = document.createElement('div'); al.className = 'alert alert-warning py-2 small';
      q.errors.forEach(function (e) { var d = document.createElement('div'); d.textContent = e; al.appendChild(d); });
      out.appendChild(al);
    }
    var t = document.createElement('table'); t.className = 'table table-sm mb-0';
    var tb = document.createElement('tbody');
    q.lines.forEach(function (l) {
      var tr = document.createElement('tr');
      cell(tr, l.name);
      var det = q.lines.length && l.impressions !== null
        ? q.labels.cpm.replace(':i', Number(l.impressions).toLocaleString()).replace(':p', l.unit_label)
        : q.labels.per_day.replace(':d', l.days).replace(':t', l.tv_count).replace(':p', l.unit_label);
      cell(tr, det, true).classList.add('small');
      cell(tr, l.amount_label, true);
      tb.appendChild(tr);
    });
    [[q.labels.subtotal_text, q.labels.subtotal], [q.labels.tax_name, q.labels.tax], [q.labels.total_text, q.labels.total]].forEach(function (r, i) {
      var tr = document.createElement('tr'); if (i === 2) { tr.className = 'fw-bold'; }
      var a = cell(tr, r[0], true); a.colSpan = 2; cell(tr, r[1], true); tb.appendChild(tr);
    });
    t.appendChild(tb); out.appendChild(t);
  }
  function quote() {
    var ids = Array.prototype.map.call(form.querySelectorAll('input[name="hotel_ids[]"]:checked:not(:disabled)'), function (i) { return i.value; });
    var body = {
      id: form.elements.id.value, hotel_ids: ids, pricing_model: model(), start_date: form.elements.start_date.value,
      end_date: form.elements.end_date.value, impressions: form.elements.impressions.value, category: form.elements.category.value
    };
    fetch(form.getAttribute('data-quote-url'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { render(j.data); } }).catch(function () {});
  }
  form.addEventListener('change', function () { applyModel(); clearTimeout(timer); timer = setTimeout(quote, 250); });
  form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(quote, 400); });
  applyModel();
  quote();
})();
