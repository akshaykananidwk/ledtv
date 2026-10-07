/* Menu board admin (admin/menu_board.php): one-tap "available / sold out" and "today's special"
   switches without reloading the page (falls back to a normal form post without JavaScript). */
(function () {
  'use strict';
  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('form[data-mb-toggle]');
    if (!form) return;
    ev.preventDefault();
    const btn = form.querySelector('button');
    if (btn.hasAttribute('data-reload')) { form.submit(); return; }
    btn.disabled = true;
    try {
      const res = await fetch(form.action || window.location.href, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      });
      const json = await res.json().catch(() => null);
      if (!json || !json.ok) throw new Error((json && json.error && json.error.message) || ('HTTP ' + res.status));
      const on = json.data.value === 1;
      const row = form.closest('[data-item]');
      if (json.data.field === 'sold_out') {
        btn.classList.toggle('btn-danger', on);
        btn.classList.toggle('btn-success', !on);
        btn.textContent = on ? btn.dataset.onText : btn.dataset.offText;
        if (row) row.classList.toggle('is-sold', on);
      } else if (json.data.field === 'special') {
        btn.classList.toggle('btn-warning', on);
        btn.classList.toggle('btn-outline-secondary', !on);
        const i = btn.querySelector('i');
        if (i) i.className = 'bi ' + (on ? 'bi-star-fill' : 'bi-star');
      }
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    } catch (e) {
      if (window.HC && HC.toast) HC.toast(e.message, 'danger'); else alert(e.message);
    } finally {
      btn.disabled = false;
    }
  });
})();
