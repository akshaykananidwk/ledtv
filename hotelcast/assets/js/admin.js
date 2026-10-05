/* HotelCast admin helpers: fetch wrapper (CSRF), toasts, confirm dialogs, auto-refresh. */
(function () {
  'use strict';
  const T = Object.assign({ ok: 'OK', cancel: 'Cancel', confirm: 'Please confirm', error: 'Something went wrong' }, window.HC_I18N || {});
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';

  const HC = {
    csrf: meta('csrf-token'),
    ajaxUrl: meta('hc-ajax'),
    t: T,

    /** Call admin/ajax.php. Returns the `data` field or throws Error(message). */
    async api(action, opts = {}) {
      const method = (opts.method || (opts.data ? 'POST' : 'GET')).toUpperCase();
      const params = new URLSearchParams(Object.assign({ action }, opts.params || {}));
      const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
      let body;
      if (method !== 'GET') {
        headers['X-CSRF-Token'] = HC.csrf;
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.data || {});
      }
      let res;
      try {
        res = await fetch(HC.ajaxUrl + '?' + params.toString(), { method, headers, body, credentials: 'same-origin' });
      } catch (e) {
        throw new Error(T.error + ' (network)');
      }
      let json = null;
      try { json = await res.json(); } catch (e) { /* not json */ }
      if (res.status === 401) {
        window.location.reload();
        throw new Error('Unauthenticated');
      }
      if (!json || !json.ok) {
        throw new Error((json && json.error && json.error.message) || (T.error + ' (' + res.status + ')'));
      }
      return json.data;
    },

    toast(message, type = 'success', delay = 4000) {
      const box = document.getElementById('hcToasts');
      if (!box || !window.bootstrap) { alert(message); return; }
      const el = document.createElement('div');
      const bg = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-primary' }[type] || 'text-bg-dark';
      el.className = 'toast align-items-center border-0 ' + bg;
      el.setAttribute('role', 'status');
      el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
      el.querySelector('.toast-body').textContent = message;
      box.appendChild(el);
      const t = new bootstrap.Toast(el, { delay });
      el.addEventListener('hidden.bs.toast', () => el.remove());
      t.show();
    },

    /** Promise<boolean> confirmation dialog. */
    confirm(message, opts = {}) {
      const modalEl = document.getElementById('hcConfirmModal');
      if (!modalEl || !window.bootstrap) return Promise.resolve(window.confirm(message));
      modalEl.querySelector('[data-title]').textContent = opts.title || T.confirm;
      modalEl.querySelector('[data-body]').textContent = message;
      const ok = modalEl.querySelector('[data-ok]');
      ok.textContent = opts.okText || T.ok;
      ok.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      return new Promise((resolve) => {
        let answered = false;
        const onOk = () => { answered = true; modal.hide(); };
        ok.addEventListener('click', onOk, { once: true });
        modalEl.addEventListener('hidden.bs.modal', () => { ok.removeEventListener('click', onOk); resolve(answered); }, { once: true });
        modal.show();
      });
    },

    /** Run fn now and every ms milliseconds while the tab is visible. */
    every(ms, fn, runNow = true) {
      let timer = null;
      const tick = async () => {
        if (!document.hidden) { try { await fn(); } catch (e) { console.warn(e); } }
        timer = setTimeout(tick, ms);
      };
      if (runNow) tick(); else timer = setTimeout(tick, ms);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { clearTimeout(timer); tick(); }
      });
      return () => clearTimeout(timer);
    },

    esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    /** Read target picker values from a container (for ajax calls). */
    targetFrom(container) {
      const type = (container.querySelector('input[name="target_type"]:checked') || {}).value || 'all';
      const pick = (n) => Array.from(container.querySelectorAll('input[name="' + n + '"]:checked')).map((i) => i.value);
      const ids = type === 'rooms' ? pick('room_ids[]') : type === 'groups' ? pick('group_ids[]') : type === 'floors' ? pick('floors[]') : [];
      return { target_type: type, target_ids: ids };
    },

    passwordScore(pw) {
      let s = 0;
      if (!pw) return 0;
      if (pw.length >= 8) s++;
      if (pw.length >= 12) s++;
      if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
      if (/\d/.test(pw)) s++;
      if (/[^A-Za-z0-9]/.test(pw)) s++;
      if (!/[A-Za-z]/.test(pw) || !/\d/.test(pw) || pw.length < 8) s = Math.min(s, 1);
      return Math.min(4, s);
    },
  };
  window.HC = HC;

  /* ---------- Global behaviours ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    // Confirm before submitting forms / following links with data-confirm.
    document.addEventListener('submit', async (ev) => {
      const form = ev.target;
      const submitter = ev.submitter;
      const msg = (submitter && submitter.dataset.confirm) || form.dataset.confirm;
      if (!msg || form.dataset.confirmed === '1') return;
      ev.preventDefault();
      if (await HC.confirm(msg, { danger: (submitter && submitter.dataset.confirmSafe) ? false : !form.dataset.confirmSafe })) {
        form.dataset.confirmed = '1';
        if (submitter && submitter.name) {
          const h = document.createElement('input');
          h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
          form.appendChild(h);
        }
        form.submit();
      }
    });
    document.addEventListener('click', async (ev) => {
      const a = ev.target.closest('a[data-confirm]');
      if (!a) return;
      ev.preventDefault();
      if (await HC.confirm(a.dataset.confirm)) window.location.href = a.href;
    });

    // Language switcher.
    document.querySelectorAll('.js-lang').forEach((a) => a.addEventListener('click', async (ev) => {
      ev.preventDefault();
      try { await HC.api('set_language', { data: { lang: a.dataset.lang } }); window.location.reload(); }
      catch (e) { HC.toast(e.message, 'danger'); }
    }));

    // Emergency stop buttons (banner + lists).
    document.addEventListener('click', async (ev) => {
      const b = ev.target.closest('.js-emergency-stop');
      if (!b) return;
      ev.preventDefault();
      if (!(await HC.confirm(T.stop_emergency || 'Stop?', { danger: false }))) return;
      b.disabled = true;
      try {
        await HC.api('emergency_stop', { data: { id: parseInt(b.dataset.id || '0', 10) } });
        window.location.reload();
      } catch (e) { HC.toast(e.message, 'danger'); b.disabled = false; }
    });

    // "Select all" checkboxes: data-check-all="<css selector of targets>".
    document.querySelectorAll('[data-check-all]').forEach((master) => {
      master.addEventListener('change', () => {
        document.querySelectorAll(master.dataset.checkAll).forEach((c) => { if (!c.disabled && c.closest('tr,label,div') && c.offsetParent !== null) c.checked = master.checked; });
        document.dispatchEvent(new Event('hc:selection'));
      });
    });

    // Target pickers.
    document.querySelectorAll('[data-target-picker]').forEach((tp) => {
      const sync = () => {
        const type = (tp.querySelector('input[name="target_type"]:checked') || {}).value || 'all';
        tp.querySelectorAll('.target-panel').forEach((p) => { p.hidden = p.dataset.panel !== type; });
      };
      tp.querySelectorAll('input[name="target_type"]').forEach((r) => r.addEventListener('change', sync));
      tp.querySelectorAll('[data-select]').forEach((b) => b.addEventListener('click', () => {
        tp.querySelectorAll('input[name="room_ids[]"]').forEach((c) => { c.checked = b.dataset.select === 'all'; });
      }));
      tp.querySelectorAll('[data-select-floor]').forEach((b) => b.addEventListener('click', () => {
        const boxes = Array.from(tp.querySelectorAll('input[name="room_ids[]"][data-floor="' + CSS.escape(b.dataset.selectFloor) + '"]'));
        const allOn = boxes.every((c) => c.checked);
        boxes.forEach((c) => { c.checked = !allOn; });
      }));
      sync();
    });

    // Password strength meters: <input data-strength="#barId">.
    document.querySelectorAll('input[data-strength]').forEach((inp) => {
      const wrap = document.querySelector(inp.dataset.strength);
      if (!wrap) return;
      const bar = wrap.querySelector('span');
      const label = wrap.parentElement.querySelector('[data-strength-label]');
      const colors = ['#ef4444', '#ef4444', '#f59e0b', '#22c55e', '#15803d'];
      const names = [T.weak, T.weak, T.fair, T.good, T.strong];
      inp.addEventListener('input', () => {
        const s = HC.passwordScore(inp.value);
        bar.style.width = inp.value ? ((s + 1) * 20) + '%' : '0';
        bar.style.background = colors[s];
        if (label) label.textContent = inp.value ? names[s] : '';
      });
    });

    // XHR upload forms with progress bar: <form class="js-upload" data-progress="#id">.
    document.querySelectorAll('form.js-upload').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        const fileInput = form.querySelector('input[type=file]');
        if (!fileInput || !fileInput.files.length || !window.FormData) return; // normal submit
        ev.preventDefault();
        const wrap = document.querySelector(form.dataset.progress);
        const bar = wrap ? wrap.querySelector('.progress-bar') : null;
        const label = wrap ? wrap.querySelector('[data-progress-label]') : null;
        const btns = form.querySelectorAll('button[type=submit]');
        btns.forEach((b) => { b.disabled = true; });
        if (wrap) wrap.hidden = false;
        const fd = new FormData(form);
        if (ev.submitter && ev.submitter.name) fd.append(ev.submitter.name, ev.submitter.value);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', HC.csrf);
        xhr.upload.addEventListener('progress', (e) => {
          if (!e.lengthComputable || !bar) return;
          const pct = Math.round((e.loaded / e.total) * 100);
          bar.style.width = pct + '%';
          bar.textContent = pct + '%';
          if (label) label.textContent = pct >= 100 ? T.processing : T.uploading;
        });
        xhr.onload = () => {
          let json = null;
          try { json = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
          if (json && json.ok) {
            window.location.href = (json.data && json.data.redirect) || window.location.href;
            return;
          }
          btns.forEach((b) => { b.disabled = false; });
          if (wrap) wrap.hidden = true;
          HC.toast((json && json.error && json.error.message) || (T.error + ' (' + xhr.status + ')'), 'danger', 9000);
        };
        xhr.onerror = () => {
          btns.forEach((b) => { b.disabled = false; });
          if (wrap) wrap.hidden = true;
          HC.toast(T.error + ' (network)', 'danger');
        };
        xhr.send(fd);
      });
    });

    // Image file preview: <input type=file data-preview="#img">.
    document.querySelectorAll('input[type=file][data-preview]').forEach((inp) => {
      inp.addEventListener('change', () => {
        const img = document.querySelector(inp.dataset.preview);
        if (!img || !inp.files[0] || !inp.files[0].type.startsWith('image/')) return;
        img.src = URL.createObjectURL(inp.files[0]);
        img.hidden = false;
      });
    });

    // Copy-to-clipboard buttons.
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(b.dataset.copy); HC.toast('✓ ' + b.dataset.copy, 'info', 2000); }
      catch (e) { window.prompt('', b.dataset.copy); }
    }));

    // Keep the header online badge fresh.
    const badge = document.getElementById('hcOnlineBadge');
    if (badge && HC.ajaxUrl) {
      HC.every(30000, async () => {
        const s = await HC.api('dashboard_stats');
        badge.querySelector('[data-online]').textContent = s.online;
        badge.querySelector('[data-total]').textContent = s.devices;
        badge.classList.toggle('text-bg-success', s.online >= s.devices);
        badge.classList.toggle('text-bg-warning', s.online < s.devices);
        document.dispatchEvent(new CustomEvent('hc:stats', { detail: s }));
      }, false);
    }

    // Enable Bootstrap tooltips.
    if (window.bootstrap) document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
  });
})();
