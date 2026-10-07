/* Token issue page (admin/queue_issue.php): issue with AJAX, show the number big, print a small
   ticket (58 / 80 mm thermal paper, remembered on this device), optional automatic printing. */
(function () {
  'use strict';
  const get = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
  const set = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } };

  document.addEventListener('DOMContentLoaded', () => {
    const paper = document.getElementById('qiPaper');
    const auto = document.getElementById('qiAuto');
    const applyPaper = () => { document.body.classList.toggle('paper-58', paper.value === '58'); };
    if (paper) {
      paper.value = get('hc_queue_paper') === '58' ? '58' : '80';
      applyPaper();
      paper.addEventListener('change', () => { set('hc_queue_paper', paper.value); applyPaper(); });
    }
    if (auto) {
      auto.checked = get('hc_queue_autoprint') === '1';
      auto.addEventListener('change', () => set('hc_queue_autoprint', auto.checked ? '1' : '0'));
    }
    const printBtn = document.getElementById('qiPrint');
    if (printBtn) printBtn.addEventListener('click', () => window.print());
    const app = document.getElementById('qiApp');
    if (!app) return;
    const $ = (id) => document.getElementById(id);
    const ticket = $('qTicket');

    app.addEventListener('submit', async (ev) => {
      const form = ev.target.closest('form[data-qi-issue]');
      if (!form) return;
      ev.preventDefault();
      form.querySelector('[name="name"]').value = ($('qiName') || {}).value || '';
      form.querySelector('[name="phone"]').value = ($('qiPhone') || {}).value || '';
      const btn = form.querySelector('button');
      btn.disabled = true;
      try {
        const res = await fetch(app.dataset.url, {
          method: 'POST', body: new FormData(form), credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const json = await res.json().catch(() => null);
        if (!json || !json.ok) throw new Error((json && json.error && json.error.message) || ('HTTP ' + res.status));
        const t = json.data;
        const ahead = ticket.dataset.aheadText.replace(':n', t.ahead);
        $('qiLabel').textContent = t.label;
        $('qiService').textContent = t.service;
        $('qiAhead').textContent = ahead;
        $('tHotel').textContent = t.hotel;
        $('tSvc').textContent = t.service;
        $('tNum').textContent = t.label;
        $('tAhead').textContent = ahead;
        $('tTime').textContent = t.time;
        printBtn.disabled = false;
        const w = document.querySelector('[data-qi-wait="' + form.querySelector('[name="service_id"]').value + '"]');
        if (w) w.textContent = String((parseInt(w.textContent, 10) || 0) + 1);
        if ($('qiName')) $('qiName').value = '';
        if ($('qiPhone')) $('qiPhone').value = '';
        if (auto && auto.checked) setTimeout(() => window.print(), 100);
      } catch (e) {
        if (window.HC && HC.toast) HC.toast(e.message, 'danger'); else alert(e.message);
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
