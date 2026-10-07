/* Token queue calling page (admin/queue.php): AJAX buttons (CSRF header via the form token),
   live waiting count every 5 s, remembers "my counter" on this phone / PC. */
(function () {
  'use strict';
  const KEY = 'hc_queue_counter';
  const store = {
    get() { try { return localStorage.getItem(KEY); } catch (e) { return null; } },
    set(v) { try { v ? localStorage.setItem(KEY, v) : localStorage.removeItem(KEY); } catch (e) { /* private mode */ } },
  };

  document.addEventListener('DOMContentLoaded', () => {
    const pick = document.getElementById('qCounterPick');
    if (pick) {
      const saved = store.get();
      const link = saved && pick.querySelector('[data-counter-link="' + CSS.escape(saved) + '"]');
      if (link && !/[?&]pick=1/.test(location.search)) { location.href = link.href; return; }
      pick.addEventListener('click', (ev) => {
        const a = ev.target.closest('[data-counter-link]');
        if (a) store.set(a.getAttribute('data-counter-link'));
      });
    }
    document.querySelectorAll('[data-forget-counter]').forEach((a) => a.addEventListener('click', () => store.set(null)));

    const app = document.getElementById('qApp');
    if (!app) return;
    store.set(app.dataset.counter);
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function show(d) {
      $('qCurrent').textContent = d.current ? d.current.label : '—';
      $('qStatus').textContent = d.current ? d.current.status_label : (app.dataset.noToken || '—');
      $('qCustomer').textContent = d.current ? (d.current.name + ' ' + d.current.phone).trim() : '';
      document.querySelectorAll('[data-q-waiting]').forEach((el) => { el.textContent = d.waiting; });
      $('qNext').innerHTML = d.next.map((l) => '<span class="badge text-bg-light border fs-6">' + esc(l) + '</span>').join(' ');
      if (d.message) {
        const m = $('qMsg');
        m.className = 'alert py-2 alert-' + (d.level === 'warning' ? 'warning' : 'success');
        m.textContent = d.message;
      }
    }

    async function send(form) {
      const btn = form.querySelector('button');
      btn.disabled = true;
      try {
        const res = await fetch(app.dataset.url, {
          method: 'POST', body: new FormData(form), credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const json = await res.json().catch(() => null);
        if (res.status === 401) { location.reload(); return; }
        if (!json || !json.ok) throw new Error((json && json.error && json.error.message) || ('HTTP ' + res.status));
        show(json.data);
        if (form.querySelector('[name="number"]')) form.reset();
      } catch (e) {
        if (window.HC && HC.toast) HC.toast(e.message, 'danger'); else alert(e.message);
      } finally {
        btn.disabled = false;
      }
    }

    app.addEventListener('submit', (ev) => {
      const form = ev.target.closest('form[data-q-op]');
      if (!form) return;
      ev.preventDefault();
      send(form);
    });

    async function poll() {
      if (document.hidden) return;
      try {
        const res = await fetch(app.dataset.url + '?counter=' + encodeURIComponent(app.dataset.counter), {
          credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const json = await res.json();
        if (json && json.ok) { json.data.message = ''; show(json.data); }
      } catch (e) { /* offline: try again later */ }
    }
    setInterval(poll, 5000);
  });
})();
