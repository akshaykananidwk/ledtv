/*
 * HotelCast admin PWA (#14): service worker registration, "Install app" prompt and the web push
 * client (window.HCPush, used by admin/push.php). Configuration comes from the JSON block
 * #hcPwaConfig printed by admin/partials/footer.d/50_pwa.php.
 */
(function () {
  'use strict';
  const cfgEl = document.getElementById('hcPwaConfig');
  if (!cfgEl) {
    return;
  }
  let cfg = {};
  try {
    cfg = JSON.parse(cfgEl.textContent || '{}');
  } catch (e) {
    return;
  }

  const store = {
    get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  // ------------------------------------------------------------------ service worker
  const swCapable = 'serviceWorker' in navigator && window.isSecureContext;
  let swReady = null;
  if (swCapable && cfg.sw) {
    swReady = navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope }).then(() => navigator.serviceWorker.ready).catch((e) => {
      console.warn('Service worker registration failed:', e && e.message ? e.message : e);
      return null;
    });
  }

  // ------------------------------------------------------------------ install prompt
  const bar = document.getElementById('hcInstallBar');
  let deferred = null;
  const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const dismissed = () => Number(store.get('hcInstallDismissed') || 0) > Date.now();
  const showInstall = (on) => {
    document.querySelectorAll('[data-pwa-install]').forEach((b) => { b.hidden = !on; });
    if (bar) {
      bar.hidden = !on || dismissed();
    }
  };
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    if (!standalone()) {
      showInstall(true);
    }
  });
  window.addEventListener('appinstalled', () => {
    deferred = null;
    showInstall(false);
  });
  async function install() {
    if (!deferred) {
      return false;
    }
    deferred.prompt();
    let accepted = false;
    try {
      accepted = (await deferred.userChoice).outcome === 'accepted';
    } catch (e) { /* ignore */ }
    deferred = null;
    showInstall(false);
    return accepted;
  }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-pwa-install], [data-pwa-dismiss]');
    if (!t) {
      return;
    }
    e.preventDefault();
    if (t.hasAttribute('data-pwa-dismiss')) {
      store.set('hcInstallDismissed', String(Date.now() + 14 * 86400 * 1000));
      if (bar) {
        bar.hidden = true;
      }
    } else {
      install();
    }
  });

  // ------------------------------------------------------------------ web push client
  function b64uToBytes(s) {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
      out[i] = raw.charCodeAt(i);
    }
    return out;
  }
  function bytesToB64u(buf) {
    const bytes = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < bytes.length; i++) {
      s += String.fromCharCode(bytes[i]);
    }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  async function ajax(action, body, query) {
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const qs = new URLSearchParams(Object.assign({ action }, query || {})).toString();
    const res = await fetch(cfg.ajax + '?' + qs, {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let j = null;
    try {
      j = await res.json();
    } catch (e) { /* not JSON */ }
    if (!res.ok || !j || !j.ok) {
      throw new Error((j && j.error && j.error.message) || ('HTTP ' + res.status));
    }
    return j.data;
  }
  async function registration() {
    if (!swReady) {
      throw new Error(cfg.i18n && cfg.i18n.no_sw ? cfg.i18n.no_sw : 'Service worker not available');
    }
    const reg = await swReady;
    if (!reg) {
      throw new Error(cfg.i18n && cfg.i18n.no_sw ? cfg.i18n.no_sw : 'Service worker not available');
    }
    return reg;
  }

  window.HCPush = {
    /** Browser + server support. */
    supported() {
      return swCapable && 'PushManager' in window && 'Notification' in window && !!cfg.vapid;
    },
    permission() {
      return 'Notification' in window ? Notification.permission : 'unsupported';
    },
    async current() {
      if (!this.supported()) {
        return null;
      }
      const reg = await registration();
      return reg.pushManager.getSubscription();
    },
    /** Ask permission, subscribe and register the subscription on the server. */
    async subscribe(types) {
      if (!this.supported()) {
        throw new Error(cfg.i18n.unsupported);
      }
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        throw new Error(cfg.i18n.denied);
      }
      const reg = await registration();
      let sub = await reg.pushManager.getSubscription();
      const key = b64uToBytes(cfg.vapid);
      if (sub && sub.options && sub.options.applicationServerKey) {
        // Server key changed → the old subscription cannot be used any more.
        if (bytesToB64u(sub.options.applicationServerKey) !== cfg.vapid) {
          await sub.unsubscribe();
          sub = null;
        }
      }
      if (!sub) {
        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      }
      const json = sub.toJSON();
      return ajax('push_subscribe', { endpoint: json.endpoint, keys: json.keys, types: types || null });
    },
    async unsubscribe() {
      const sub = await this.current();
      if (!sub) {
        return { removed: 0 };
      }
      const endpoint = sub.endpoint;
      await sub.unsubscribe();
      return ajax('push_unsubscribe', { endpoint });
    },
    async savePrefs(types) {
      const sub = await this.current();
      if (!sub) {
        throw new Error(cfg.i18n.not_subscribed);
      }
      return ajax('push_prefs', { endpoint: sub.endpoint, types });
    },
    async status() {
      const sub = await this.current().catch(() => null);
      return ajax('push_status', undefined, sub ? { endpoint: sub.endpoint } : {});
    },
    test() {
      return ajax('push_test', {});
    },
    install,
    canInstall() {
      return !!deferred;
    },
  };
  document.dispatchEvent(new CustomEvent('hc:pwa-ready'));
})();
