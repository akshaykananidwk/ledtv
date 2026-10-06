# Module: PWA + push, support tools, TV device controls, setup file

V2_SPEC §6 (#14), §8 (#24, server side), §5 TV device controls (#4 #5 #15 #16, server side),
§7 "Download setup file" (#23).

Migration: `migrations/005_pwa_support_devices.sql` (tables `push_subscriptions`, `device_support_files`,
`device_events`; `broadcast_commands.command` ENUM → `VARCHAR(30)`). Boot hook:
`core/boot.d/pwa_support_devices.php`.

| permission | rule | used for |
|---|---|---|
| `devices.controls` | manager+ | Admin → TV controls (volume, guest menu, TV commands) |
| `support.view` | manager+ | Admin → TV support (screenshots, logs, crashes) |
| `devices.setup` | manager+ | Rooms → Download setup file |
| `support.platform` | platform_admin | Platform → Support |
| `push.self` | every role | Notifications page: own push subscriptions |

---------------------------------------------------------------------------------------------------
## 1. Device API (device auth: `Authorization: Bearer <token>` + `X-Device-Id`)

All endpoints are `POST`, rate limited per device per hour (screenshot / logs / crash: 30, event: 1200)
on top of the normal 120 requests / minute. Errors: `400 VALIDATION_ERROR`, `413 PAYLOAD_TOO_LARGE`,
`429 RATE_LIMITED` (+ `Retry-After`), `401 UNAUTHENTICATED / INVALID_TOKEN`, `405`.

| endpoint | body | limits | response |
|---|---|---|---|
| `/api/device/screenshot` | multipart, field `image` (JPEG) | ≤ 2 MB, must be a real JPEG | `{id}` |
| `/api/device/logs` | `{ "logs": "…", "state": { … } }` | logs ≤ 512 KB (bytes), state ≤ 64 KB | `{id}` |
| `/api/device/crash` | `{ "stack": "…", "app_version": "2.0.0 (20)", "happened_at": "ISO" }` | stack ≤ 64 KB | `{id}` |
| `/api/device/event` | `{ "type": "input_switch", "data": { … } }` | type `[a-z0-9_.-]{1,40}`, data ≤ 4 KB | `{id}` |

Files are stored in `storage/support/h{hotel}/d{device}/` (never web reachable) and served only by
`admin/support.php?action=file&id=…` after the permission + hotel check. The newest
`support_keep_per_device` files (hotel setting, default 10) per device and kind are kept; files older
than `support_retention_days` (default 30) and events older than `log_retention_days` are removed by
`SupportCleanupTask` (daily). PHP hosts need `upload_max_filesize` ≥ 2M.

**Events** (`device_events`: hotel_id, device_id, room_id, type, data JSON, created_at) are for the
analytics module. The TV app sends `guest_menu_open`, `guest_menu_item`, `qr_shown`, `input_switch`
(with `ok` / `message`) and `welcome_shown`.

### Commands (`GET /api/device/command` → `commands[]`)

Queued with `Broadcaster::sendCommand()` (targets: all / rooms / groups / floors) through
`TvControls::send()`, payloads exactly as `android/…/CommandHandler.kt` parses them:

| command | payload |
|---|---|
| `SET_VOLUME` | `{"level": 0..100}` |
| `MUTE`, `UNMUTE`, `SHOW_WELCOME`, `SCREENSHOT`, `UPLOAD_LOGS` | `{}` |
| `OPEN_INPUT` | `{"input": "live_tv" \| "hdmi1" … "hdmi4"}` |
| `SHOW_MESSAGE` | `{"title": "…", "message": "…", "duration_sec": 3..3600}` |

`SCREENSHOT` / `UPLOAD_LOGS` from the support page target exactly one device
(`DeviceSupport::request()`, a single `device_commands` row; duplicates pending for the same device
are collapsed). Other modules can show a message on a room's TV:
`TvControls::send('SHOW_MESSAGE', 'rooms', [$roomId], ['title' => 'Your food is on the way', 'message' => '', 'duration_sec' => 20]);`

### Content object (`core/Extensions/DeviceControlsExtension.php`)

* `volume` — only when Admin → TV controls → "Control the TV volume" is on:
  `{ "default": 30|null, "max": 80, "night_max": 20|null, "night_from": "22:00"|null, "night_to": "06:00"|null }`.
  Present in every mode (also `suspended`).
* `guest_menu` — items are **appended** (other modules add the room-service QR / local guide):
  `{"id":"live_tv","type":"live_tv","title":"Live TV","icon":"tv"}`,
  `{"id":"hdmi1","type":"input","title":"<label or HDMI 1>","icon":"hdmi","input":"hdmi1"}` (enabled inputs, port order),
  `{"id":"cast","type":"cast","title":"Cast from phone","icon":"cast","text":"…"}`. Not added in `suspended` mode.
  Titles / default cast text in the guest's language (`content.guest.language` when set before this
  extension runs, else the hotel's `default_language`; EN/GU/HI). The cast text uses the shared
  settings `wifi_ssid` / `wifi_password` (also written by the guests module); custom texts per
  language support `{ssid}` and `{password}`. The TV's Wi-Fi QR on the cast screen comes from
  `welcome.wifi` (guests module).

Hotel settings: `tv_volume_enabled`, `volume_default`, `volume_max`, `volume_night_enabled`,
`volume_night_max`, `volume_night_from`, `volume_night_to`, `guest_menu_live_tv`,
`guest_menu_inputs` (JSON `{"hdmi1":"label"}`), `guest_menu_cast`, `guest_cast_text_en|gu|hi`,
`wifi_ssid`, `wifi_password`.

---------------------------------------------------------------------------------------------------
## 2. PWA (installable admin panel)

* `admin/manifest.php` — `application/manifest+json`, name / colour / icons from the white-label
  branding of the logged-in user's hotel (linked with `crossorigin="use-credentials"`), platform branding
  before login. `start_url` `./index.php?source=pwa`, scope `./` (admin/), `display: standalone`.
* `admin/pwa_icon.php?s=192[&m=1]` — PNG icons drawn with GD (brand colour + logo, or a TV glyph),
  cached in `storage/cache/pwa/`; fallback without GD: `assets/pwa/*.png`.
* `admin/sw.js` — service worker, scope `admin/`: caches `assets/…` (versioned URLs) and
  `admin/offline.html` only; HTML pages, AJAX and API are never cached (network only; the static
  offline page is shown when the network fails). Handles `push` and `notificationclick` (focuses an
  open admin window or opens the alert's URL; only same-origin URLs).
* Layout hooks (new, shared): `admin/partials/head.d/*.php` (included in `<head>` of header.php) and
  `admin/partials/footer.d/*.php` (included before `</body>` of footer.php). This module adds
  `head.d/50_pwa.php` (manifest, theme colour, icons, `assets/css/pwa.css`) and `footer.d/50_pwa.php`
  ("Install app" bar + `assets/js/pwa.js` config). `pwa.js` registers the service worker, shows the
  install bar on `beforeinstallprompt` (dismiss = 14 days) and provides `window.HCPush`.
* Service workers and push need HTTPS (or `localhost` for development).

## 3. Web push + staff alerts

`core/WebPush.php` — pure PHP: RFC 8291 (ECDH P-256 + HKDF + `aes128gcm`) and RFC 8292 VAPID (ES256
JWT, DER → raw r||s). Verified against the RFC 8291 Appendix A vector (`tests/Unit/WebPushTest.php`).
VAPID keys are generated on first use and stored in platform settings `platform_vapid_public` /
`platform_vapid_private` (encrypted with `Crypto`). `WebPush::supported()` is false when openssl lacks
EC support: the Notifications page says so and email / WhatsApp keep working. Sends with TTL, Urgency,
optional Topic; `404/410` removes the subscription, other errors increment `failures` (removed after 20
or 180 days unused by `SupportCleanupTask`).

**Public API for other modules** (`core/StaffAlerts.php`):

```php
StaffAlerts::send('services.manage', 'New order · Room 101', '2 × Masala tea', 'orders.php', 'orders'); // → int delivered
StaffAlerts::sendPlatform('Title', 'Body', 'platform_support.php');        // platform admins (push + platform email / WhatsApp)
StaffAlerts::registerType('my_type', 'My alert label', 'my.permission');  // in a boot.d file, shown on the Notifications page
```

`send()` pushes to every opted-in user of the **current hotel** (`users.hotel_id`) whose role has the
permission (`Auth::roleCan()`; platform roles count as super admin), skipping devices where the user
switched that alert type off; unregistered types are always delivered. Email / WhatsApp go out
additionally when the hotel's super admin ticked the type (`alert_email_types` /
`alert_whatsapp_types`, using Settings → Notifications addresses). Built-in types: `orders`,
`requests` (services.manage), `tv_offline` (rooms.view), `emergency` (dashboard.view), `support`
(support.view), `platform` (support.platform). Hotels whose plan lacks the `pwa` module get no push.

Admin AJAX (`admin/ajax.d/push.php`, session + CSRF): `push_status` (GET), `push_subscribe`
`{endpoint, keys:{p256dh, auth}, types?}`, `push_unsubscribe {endpoint}`, `push_prefs {endpoint, types[]}`,
`push_test`. Endpoints must be `https` on a public host. Max 20 devices per user.

Tasks: `StaffAlertsTask` (every minute: TVs offline longer than `notify_offline_minutes`, emergency
broadcasts started — each pushed once), `SupportAlertTask` (every 15 min: hotels with ≥
`platform_crash_alert_threshold` crash reports in the last hour → platform admins + the hotel's
managers, at most once per 6 h per hotel), `SupportCleanupTask` (daily).

---------------------------------------------------------------------------------------------------
## 4. Admin guide

* **Notifications** (menu, every user): *Enable notifications* on each phone / PC, choose alert types,
  *Send test notification*, remove old devices. iPhone: add to home screen from Safari first. Super
  admins also choose which alert types are emailed / sent by WhatsApp.
* **TV controls** (manager+): volume rules (volume at check-in, max, night max + times), guest menu
  (Live TV, HDMI 1–4 with labels, Cast + instructions EN/GU/HI, guest Wi-Fi), *Volume now*
  (set / mute / unmute), *Show a message on the TV*, *Show the welcome screen again*, *Switch input*.
  Results appear on the TV details page (command replies).
* **TV support** (manager+): list of TVs with crashes (7 days), logs, last screenshot, outdated app;
  per TV: *Take screenshot* / *Request logs* (the page waits for the upload), logs viewer / download,
  crash stacks, recent TV events.
* **Rooms → Download setup file** (manager+): `tvs.csv` for `tools/windows/HotelCast-Setup.bat` —
  lines `server,<url>`, `key,<registration key>`, `tv_address,room`, then one `<last known TV IP or
  blank>,<room>` per room (all rooms or a selection). Fill in blank addresses (the tool skips rows
  without one). The file contains the registration key.
* **Platform → Support** (platform admin): offline TVs, crashes 24 h / 7 days, outdated app versions
  against the newest uploaded APK, hotels with errors (crashes, offline TVs, failed commands), recent
  crashes, crash-spike alert threshold.
