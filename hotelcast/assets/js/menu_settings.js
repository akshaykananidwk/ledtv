/*
 * 2.9 Menu settings (admin/menu_settings.php, docs/modules/menu_settings.md).
 * Reorder the items of each [data-menu-list] by HTML5 drag & drop (mouse), by dragging the handle with a finger
 * (touch / pen, pointer events) or with the Up / Down buttons (keyboard, screen readers). The form posts the
 * hidden order[] inputs in DOM order, so the new order needs no extra serialisation. Items never leave their list
 * (one list per heading).
 */
(function () {
  'use strict';
  const form = document.querySelector('[data-menu-form]');
  if (!form) return;
  let i18n = {};
  try { i18n = JSON.parse((document.getElementById('menuI18n') || {}).textContent || '{}'); } catch (e) { i18n = {}; }
  const live = form.querySelector('[data-menu-live]');
  const fmt = (s, x, n) => String(s || '').replace(':x', x).replace(':n', n);
  const label = (li) => (li.querySelector('[data-menu-label]') || {}).textContent || '';
  const announce = (text) => { if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 30); } };
  const posOf = (li) => Array.prototype.indexOf.call(li.parentNode.children, li) + 1;
  const refresh = (list) => {
    const items = list.querySelectorAll('.hc-menu-item');
    items.forEach((li, i) => {
      const up = li.querySelector('[data-move="up"]');
      const down = li.querySelector('[data-move="down"]');
      if (up) up.disabled = i === 0;
      if (down) down.disabled = i === items.length - 1;
    });
  };
  const lists = form.querySelectorAll('[data-menu-list]');
  lists.forEach(refresh);

  // Up / Down buttons
  form.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-move]');
    if (!btn) return;
    const li = btn.closest('.hc-menu-item');
    const list = li.parentNode;
    if (btn.dataset.move === 'up' && li.previousElementSibling) {
      list.insertBefore(li, li.previousElementSibling);
    } else if (btn.dataset.move === 'down' && li.nextElementSibling) {
      list.insertBefore(li.nextElementSibling, li);
    } else {
      return;
    }
    refresh(list);
    const again = li.querySelector('[data-move="' + btn.dataset.move + '"]');
    (again && !again.disabled ? again : btn.parentNode.querySelector('[data-move]:not([disabled])') || btn).focus();
    announce(fmt(i18n.moved, label(li), posOf(li)));
  });

  // Show / hide switches
  form.addEventListener('change', (ev) => {
    const sw = ev.target.closest('[data-menu-show]');
    if (!sw) return;
    const li = sw.closest('.hc-menu-item');
    li.classList.toggle('is-hidden', !sw.checked);
    announce(fmt(sw.checked ? i18n.shown : i18n.hidden, label(li)));
  });

  // HTML5 drag & drop (mouse)
  let dragged = null;
  const clearTargets = () => form.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
  const placeAt = (list, y) => {
    const others = Array.from(list.querySelectorAll('.hc-menu-item')).filter((el) => el !== dragged);
    const next = others.find((el) => { const r = el.getBoundingClientRect(); return y < r.top + r.height / 2; });
    list.insertBefore(dragged, next || null);
  };
  lists.forEach((list) => {
    list.addEventListener('dragstart', (ev) => {
      const li = ev.target.closest('.hc-menu-item');
      if (!li) return;
      dragged = li;
      li.classList.add('is-dragging');
      ev.dataTransfer.effectAllowed = 'move';
      try { ev.dataTransfer.setData('text/plain', li.dataset.key); } catch (e) { /* old browsers */ }
    });
    list.addEventListener('dragover', (ev) => {
      if (!dragged || dragged.parentNode !== list) return; // items stay inside their heading
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      clearTargets();
      const over = ev.target.closest('.hc-menu-item');
      if (over && over !== dragged) over.classList.add('is-drop-target');
      placeAt(list, ev.clientY);
    });
    list.addEventListener('drop', (ev) => { if (dragged && dragged.parentNode === list) ev.preventDefault(); });
    list.addEventListener('dragend', () => {
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      clearTargets();
      refresh(list);
      announce(fmt(i18n.moved, label(dragged), posOf(dragged)));
      dragged = null;
    });
  });

  // Touch / pen: drag by the handle with pointer events (HTML5 drag & drop does not fire on touch screens).
  form.addEventListener('pointerdown', (ev) => {
    const handle = ev.target.closest('.hc-menu-handle');
    if (!handle || ev.pointerType === 'mouse') return;
    const li = handle.closest('.hc-menu-item');
    const list = li.parentNode;
    ev.preventDefault();
    dragged = li;
    li.classList.add('is-dragging');
    const move = (e) => { if (dragged) { e.preventDefault(); placeAt(list, e.clientY); } };
    const end = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', end);
      document.removeEventListener('pointercancel', end);
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      refresh(list);
      announce(fmt(i18n.moved, label(dragged), posOf(dragged)));
      dragged = null;
    };
    document.addEventListener('pointermove', move, { passive: false });
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
  });
})();
