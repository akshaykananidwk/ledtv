/* Google Sheet table (#14) — core/Apps/SheetTableApp.php. Pages through the rows; new data every minute
 * (the server reloads the sheet every refresh_min minutes). ES5 only. */
(function (w, d) {
  'use strict';
  var model = null, page = 0, timer = null;

  function byId(id) { return d.getElementById(id); }

  /** Same markup as SheetTableApp::table(). */
  function table(m, from, to) {
    var num = m.numeric || [], h = '<table class="st-table">', j, k, r, today = {};
    for (k = 0; k < (m.today || []).length; k++) { today[m.today[k]] = true; }
    if (m.header) {
      h += '<thead><tr>';
      for (j = 0; j < m.header.length; j++) { h += '<th' + (num[j] ? ' class="st-num"' : '') + '>' + HC.esc(m.header[j]) + '</th>'; }
      h += '</tr></thead>';
    }
    h += '<tbody>';
    for (k = from; k < to && k < m.rows.length; k++) {
      r = m.rows[k];
      h += '<tr' + (today[k] ? ' class="st-today"' : '') + '>';
      for (j = 0; j < r.length; j++) { h += '<td' + (num[j] ? ' class="st-num"' : '') + '>' + HC.esc(r[j]).replace(/\n/g, '<br>') + '</td>'; }
      h += '</tr>';
    }
    return h + '</tbody></table>';
  }

  function pages() {
    return model && model.rows.length ? Math.ceil(model.rows.length / Math.max(1, model.per_page || 10)) : 0;
  }

  function show() {
    var box = byId('stTable'), n = pages(), per = Math.max(1, model.per_page || 10), pg = byId('stPage');
    if (!box) { return; }
    if (page >= n) { page = 0; }
    box.innerHTML = n ? table(model, page * per, page * per + per) : '';
    if (pg) { pg.textContent = n > 1 ? (model.page_label || 'Page') + ' ' + (page + 1) + ' / ' + n : ''; }
  }

  function schedule() {
    if (timer) { clearInterval(timer); timer = null; }
    if (pages() > 1) {
      timer = setInterval(function () { page = (page + 1) % Math.max(1, pages()); show(); }, Math.max(3, model.page_sec || 10) * 1000);
    }
  }

  w.HCApp = {
    update: function (data) {
      if (!data) { return; }
      var first = model === null;
      model = data;
      model.rows = model.rows || [];
      if (first && model.today && model.today.length) {
        page = Math.floor(model.today[0] / Math.max(1, model.per_page || 10));
      }
      show();
      if (first || !timer) { schedule(); }
      var msg = byId('stMessage'), up = byId('stUpdated'), er = byId('stError');
      if (msg) { msg.textContent = data.message || ''; msg.style.display = data.message ? '' : 'none'; }
      if (up) { up.textContent = data.updated || ''; }
      if (er) { er.textContent = data.error || ''; er.style.display = data.error && model.rows.length ? '' : 'none'; }
    }
  };
})(window, document);
