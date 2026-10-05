/* HotelCast front desk (admin/guests.php): check-in / edit / move modals, board search & filter. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const stayModalEl = document.getElementById('gdStayModal');
    const moveModalEl = document.getElementById('gdMoveModal');

    const setField = (root, name, value) => {
      root.querySelectorAll('[data-f="' + name + '"]').forEach((el) => { el.value = value == null ? '' : value; });
      root.querySelectorAll('[data-f-text="' + name + '"]').forEach((el) => { el.textContent = value == null ? '' : value; });
    };
    const parse = (s) => { try { return JSON.parse(s); } catch (e) { return null; } };

    const openStay = (mode, data) => {
      if (!stayModalEl || !window.bootstrap) return;
      const form = stayModalEl.querySelector('form');
      form.reset();
      const edit = mode === 'edit';
      setField(form, 'op', edit ? 'update' : 'checkin');
      setField(form, 'room_id', edit ? '' : data.id);
      setField(form, 'stay_id', edit ? data.id : '');
      setField(form, 'room', data.room);
      ['salutation', 'guest_name', 'notes', 'balance_text', 'wifi_password'].forEach((k) => setField(form, k, edit ? (data[k] || '') : ''));
      setField(form, 'phone', '');
      setField(form, 'phone_masked', edit ? (data.phone_masked || '') : '');
      const lang = form.querySelector('[data-f="language"]');
      if (edit && lang) lang.value = data.language || 'en';
      const out = form.querySelector('[data-f="checkout_at"]');
      if (out) out.value = edit ? (data.checkout_at || '') : (out.dataset.default || '');
      stayModalEl.querySelector('[data-title-checkin]').hidden = edit;
      stayModalEl.querySelector('[data-title-edit]').hidden = !edit;
      stayModalEl.querySelectorAll('[data-edit-only]').forEach((el) => { el.hidden = !edit; });
      const hint = stayModalEl.querySelector('[data-phone-hint]');
      if (hint) hint.hidden = !(edit && data.phone_masked);
      const modal = bootstrap.Modal.getOrCreateInstance(stayModalEl);
      stayModalEl.addEventListener('shown.bs.modal', () => { const n = form.querySelector('[data-f="guest_name"]'); if (n) n.focus(); }, { once: true });
      modal.show();
    };

    document.querySelectorAll('[data-gd-checkin]').forEach((b) => b.addEventListener('click', () => {
      const d = parse(b.dataset.gdCheckin); if (d) openStay('checkin', d);
    }));
    document.querySelectorAll('[data-gd-edit]').forEach((b) => b.addEventListener('click', () => {
      const d = parse(b.dataset.gdEdit); if (d) openStay('edit', d);
    }));
    document.querySelectorAll('[data-gd-move]').forEach((b) => b.addEventListener('click', () => {
      const d = parse(b.dataset.gdMove);
      if (!d || !moveModalEl || !window.bootstrap) return;
      setField(moveModalEl, 'stay_id', d.id);
      setField(moveModalEl, 'guest_name', (d.salutation ? d.salutation + ' ' : '') + d.guest_name + ' (' + d.room + ')');
      bootstrap.Modal.getOrCreateInstance(moveModalEl).show();
    }));

    // Search + filter on the room board.
    const search = document.getElementById('gdSearch');
    const cards = Array.from(document.querySelectorAll('[data-gd-room]'));
    const apply = () => {
      const q = (search ? search.value : '').trim().toLowerCase();
      const f = (document.querySelector('input[name="gdFilter"]:checked') || {}).value || 'all';
      cards.forEach((c) => {
        const okState = f === 'all' || (f === 'due' ? c.dataset.due === '1' : c.dataset.state === f);
        const okText = !q || (c.dataset.search || '').indexOf(q) !== -1;
        c.hidden = !(okState && okText);
      });
    };
    if (search) search.addEventListener('input', apply);
    document.querySelectorAll('input[name="gdFilter"]').forEach((r) => r.addEventListener('change', apply));
  });
})();
