/*
 * HotelCast admin service worker (PWA, #14). Scope: the admin/ folder.
 *  - Static assets (../assets/…) are cached (cache-first; their URLs carry a ?v= version).
 *  - HTML pages, AJAX and API responses are NEVER cached: they always come from the network.
 *    Only when a page cannot be loaded at all (offline) the static offline page is shown.
 *  - Web push: shows the notification; a click focuses / opens the admin page of the alert.
 */
'use strict';

const VERSION = 'hc-pwa-v1';
const STATIC_CACHE = VERSION + '-static';
const SCOPE = self.registration.scope;                   // …/admin/
const OFFLINE_URL = new URL('offline.html', SCOPE).href;
const ASSETS = new URL('../assets/', SCOPE).href;          // …/assets/
const ICON = new URL('pwa_icon.php?s=192', SCOPE).href;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('hc-pwa-') && k !== STATIC_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function isStaticAsset(url) {
  return url.href.startsWith(ASSETS) && /\.(css|js|woff2?|ttf|png|jpe?g|gif|svg|webp|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }
  const url = new URL(req.url);
  if (url.origin === self.location.origin && isStaticAsset(url)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) => cache.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok && res.type === 'basic') {
          cache.put(req, res.clone());
        }
        return res;
      })))
    );
    return;
  }
  if (req.mode === 'navigate') {
    // Network only (pages contain live hotel data); offline fallback page when the network fails.
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL).then((r) => r || Response.error())));
  }
  // Everything else (AJAX, API, PHP images): not intercepted.
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Krishna Cloud LED TV', body: event.data ? event.data.text() : '' };
  }
  const title = data.title || 'Krishna Cloud LED TV';
  const options = {
    body: data.body || '',
    icon: data.icon || ICON,
    badge: data.icon || ICON,
    tag: data.tag || 'hotelcast',
    renotify: true,
    requireInteraction: data.type === 'emergency',
    timestamp: data.ts ? data.ts * 1000 : Date.now(),
    data: { url: data.url || SCOPE },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  let target = (event.notification.data && event.notification.data.url) || SCOPE;
  try {
    const u = new URL(target, SCOPE);
    target = u.origin === self.location.origin ? u.href : SCOPE; // only open our own pages
  } catch (e) {
    target = SCOPE;
  }
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if (c.url.startsWith(SCOPE) && 'focus' in c) {
          return c.focus().then((w) => (w && 'navigate' in w ? w.navigate(target) : w));
        }
      }
      return self.clients.openWindow(target);
    })
  );
});
