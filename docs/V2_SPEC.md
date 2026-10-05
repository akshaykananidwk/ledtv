# HotelCast 2.0 — Feature Specification (contract for all work packages)

Business model: **both** — (a) SaaS: many hotels on one platform server, each hotel with its own
login/data; (b) self-hosted install for one hotel, controlled by a **license key** checked against the
platform server. AI generation: **not now** (replaced by the template library, #12). Payments: **manual**
invoices (mark paid by hand), no gateway yet.

Numbers (#n) refer to the feature list agreed with the owner.

---------------------------------------------------------------------------------------------------
## 0. Multi-hotel foundation (SaaS) — #18 #19 #20 #21 #22

### Tenancy
* New table `hotels` (id, name, slug, status `active|suspended|expired`, plan_id, reseller_id, max_tvs,
  expires_at, brand fields, contact, created_at…). A single-install site simply has hotel id 1.
* Every tenant-owned table gets `hotel_id INT UNSIGNED NOT NULL DEFAULT 1` + index + FK:
  rooms, room_groups, content_items, content_playlists, broadcast_commands, devices, apk_releases,
  activity_logs, broadcast_logs, device_status_logs, users (NULL for platform users), and all new
  feature tables. Unique keys become per hotel (e.g. `UNIQUE(hotel_id, room_number)`).
* `system_settings` becomes per hotel: PK `(hotel_id, setting_key)`. `hotel_id = 0` = platform-wide
  settings (GitHub updater, platform name/branding, license server secrets). `Settings::get()` reads the
  current hotel's value, falling back to platform (0) then DEFAULTS. `Settings::platform()` for hotel 0.
* `core/Tenant.php`: `Tenant::id()` (current hotel), `Tenant::set(int)`, `Tenant::hotel()`.
  - Admin request: from the logged-in user (`users.hotel_id`); platform users may "switch into" a
    hotel (stored in session) to manage it.
  - Device API request: from the device row (`devices.hotel_id`).
  - Registration: the **registration key identifies the hotel** (key is unique per hotel).
* EVERY query on tenant tables must be scoped by `hotel_id = Tenant::id()` (reads AND writes). This is
  the #1 security requirement — a hotel must never see or change another hotel's data. Tests must
  prove isolation (two hotels, cross-access attempts return 404/403).
* File storage per hotel: `uploads/h{hotel_id}/media/...`, `storage/apk/h{hotel_id}/...`, cache keys
  include hotel id.

### Roles
| role | scope | can |
|------|-------|-----|
| `platform_admin` | whole platform | hotels, plans, invoices, resellers, licenses, platform settings, auto-update, can enter any hotel |
| `reseller` | own hotels (`hotels.reseller_id`) | create/manage their hotels, see their commissions, enter their hotels; no platform settings |
| `super_admin` | one hotel | everything inside the hotel (as today) |
| `manager`, `staff` | one hotel | as today |
| `reception` (new) | one hotel | guests check-in/out, service orders/requests, view rooms; nothing else |

Existing single-install super admin becomes `platform_admin` **and** hotel 1 super admin is kept
working (migration converts the first super_admin to platform_admin with hotel_id 1 so nothing breaks).

### Plans, limits, licensing (#19)
* `plans` (id, name, price_per_tv_month, max_tvs, features JSON — which modules are enabled).
* Limits enforced server-side: TV registration refused (`LICENSE_LIMIT`) when the hotel has `max_tvs`
  active devices; hotel `suspended/expired` → TVs show a polite "service paused, contact reception"
  screen (Content `mode: suspended`), admin shows a banner and is read-only except billing page.
* Self-hosted license: config `license_key` + `license_server`. `core/License.php` calls
  `POST {license_server}/api/license/check` {key, domain, version, tv_count} daily (cached, 14-day
  offline grace). Platform answers {valid, hotel name, max_tvs, expires_at, features, message}.
  Invalid/expired → same behaviour as suspended. No license configured on a self-hosted install →
  "unlicensed" banner + max 2 TVs (demo).
* Platform admin: create licenses (key = random, bound to domain on first check, can reset).

### Billing (#20, manual)
* `invoices` (hotel_id, number, period_from/to, tv_count, amount, tax, status `unpaid|paid|cancelled`,
  due_date, paid_at, payment_ref, notes). Platform admin: generate monthly invoices for all active
  hotels (TV count × plan price), mark paid, print/PDF-friendly HTML invoice, reminders (email/WhatsApp
  through Notifier) for overdue, auto-suspend after N days overdue (setting).
* Hotel super admin sees own invoices (Billing page) read-only.

### White-label (#21)
* Platform branding (hotel 0 settings): product name (default "HotelCast"), logo, primary colour,
  support phone/email, footer text — used on login page, admin header, installer, TV welcome screen.
* Reseller can override branding for their hotels.
* Android app reads `branding` from the Content object (name, logo_url, color) — no rebuild needed.

### Resellers (#22)
* `resellers` (name, contact, commission_percent, status, user accounts with role `reseller`).
* Reseller panel: their hotels, add hotel (within allowance), their invoices, commission report
  (sum of paid invoices × %), status of TVs.

---------------------------------------------------------------------------------------------------
## 1. Guests, check-in/out, PMS — #1 #6 #9 #10

* `guests` / `stays` table: hotel_id, room_id, guest_name, salutation (Mr/Mrs/Ms/Shri/Smt/…), language
  (en/gu/hi), phone (optional), checkin_at, expected_checkout_at, checked_out_at, source
  (`manual|pms|api`), external_ref (PMS booking id), notes.
* Admin **Guests / Front desk** page (role reception+): room board (vacant/occupied), check-in form,
  check-out button, edit, history. Privacy: phone shown masked, data auto-deleted N days after checkout
  (setting, default 30 — DPDP).
* **Check-in mode (#9)** setting per hotel: when on, a **vacant** room's TV is switched off
  (power-off window logic, mode `off` with reason `vacant`) or shows a "Welcome to {hotel}" idle screen
  (setting: `vacant_mode = off|welcome|normal`). Check-in → TV wakes and shows the personalised welcome.
* **Personal welcome (#1)**: Content object gets `guest` + `welcome`; TV shows a full-screen welcome
  card ("Welcome Mr. Shah 🙏" + hotel logo + Wi-Fi name/password + a QR to room services) for N seconds
  the first time content arrives after check-in (and on each power-on for the first 24 h), then the
  normal content. Text templates editable per language.
* **Checkout reminder (#6)**: on checkout day, from a configurable time (default 07:00), TV shows a
  dismissible banner/overlay "Checkout today at 10:00 AM — need late checkout? Call reception / scan QR".
  Optional bill summary text (manual entry or PMS field `balance`).
* **PMS integration (#10)**: REST API with per-hotel API key (Settings → Integrations):
  `POST /api/pms/checkin` {room_number, guest_name, salutation?, language?, checkout_at?, external_ref?}
  `POST /api/pms/checkout` {room_number | external_ref}
  `POST /api/pms/room-move` {from_room, to_room}
  `GET  /api/pms/rooms` (occupancy)
  Auth: `Authorization: Bearer <pms_api_key>`. Also a generic **incoming webhook** URL format and a
  documented mapping for eZee / Hotelogix / StayFlexi style payloads (field mapping configurable).
  Documented in docs/API.md.

---------------------------------------------------------------------------------------------------
## 2. Guest services from the phone — #2 #3 #8

* Each room has a secret **guest token** (rotated on every check-in/checkout). Public mobile web app
  `/{base}/g/{token}` (no login; token valid only while the stay is active, or always for vacant=off).
  The TV shows a QR code for this URL.
* **Room service menu (#2)**: menu categories + items (name EN/GU/HI, price, photo, veg/non-veg,
  available hours, active). Guest builds a cart → order (room, items, notes) → status
  `new → accepted → preparing → delivered | cancelled`. Charges added to the stay.
* **Service requests (#3)**: quick buttons configurable (Water, Towels, Room cleaning, Extra bed,
  Call me, Maintenance, Laundry pickup, Wake-up call with time) → request with status.
* **Reception notifications**: admin panel shows a live counter + toast + sound for new orders/requests
  (AJAX poll every 10 s on every admin page for roles reception+); optional WhatsApp/email via Notifier.
  Orders page with status buttons; guest page shows live status.
* **Feedback (#8)**: 1–5 stars + comment (+ categories cleanliness/staff/food); if rating ≥ 4 show a
  button to the hotel's Google review link (setting). Feedback report in admin.
* Rate limiting on public endpoints (per token + per IP), CSRF-free JSON API with token, input
  validation, no PII leakage across rooms.

---------------------------------------------------------------------------------------------------
## 3. Advertising & analytics — #11 #17

* `sponsors` (name, contact, contract start/end, notes) and `ad_campaigns` (sponsor_id, content_id,
  start/end date, daily time window, target rooms/groups/floors, frequency: "after every N items" or
  "every M minutes", max impressions/day, priority, status).
* ContentResolver inserts active ad items into the room's playlist (also for single items: rotate
  every M minutes). Items carry `"ad_campaign_id"` so the TV reports impressions via
  `POST /api/device/played` (`ad_campaign_id`, `content_id`, `duration_sec`).
* Ads never show during emergency, power-off, or the personal welcome.
* **Sponsor report**: impressions, screen time, rooms reached, per day; printable/CSV for billing the
  sponsor.
* **Analytics dashboard (#17)**: content plays & screen time per content/room/day, TV uptime %
  (from device_status_logs + heartbeats), hours ON per TV, estimated electricity (TV watts setting ×
  hours ON), guest services stats (orders, avg delivery time, requests by type), feedback average,
  occupancy. Charts with a vendored chart library (Chart.js, MIT) in assets/vendor. Date-range filter,
  CSV export.

---------------------------------------------------------------------------------------------------
## 4. Content templates & local guide — #7 #12 (#13 replaced)

* Template library (no external images — CSS/SVG/emoji designs, so no copyright issue): festivals
  (Diwali, Janmashtami, Navratri, Holi, Uttarayan, Rath Yatra, Ganesh Chaturthi, New Year, Independence
  Day, Republic Day), hotel notices (Wi-Fi details, breakfast timing, checkout time, no smoking,
  swimming pool timing, restaurant menu board, offer/discount), temple (darshan timetable variants,
  aarti, bhog), local guide (#7): attractions list with timings & distance, Bet Dwarka ferry timings,
  taxi / auto contacts, emergency numbers (police 100, ambulance 108, fire 101, hospital), map QR.
* Admin: Templates gallery → pick → fill fields (EN/GU/HI) → preview → saves as normal content
  (`html` type with stored template id + field values so it can be edited again).

---------------------------------------------------------------------------------------------------
## 5. TV app features — #4 #5 #15 #16 #24 (+ client side of everything above)

See **§ TV contract** below. Summary: guest menu on the TV (OK/MENU-free key: the remote's
**DPAD_CENTER short press** opens a guest menu when content is playing), QR to room services, Live TV /
HDMI input switching, Cast instructions, volume control + night volume limit, screenshot, remote log
upload, crash reports, personalised welcome & checkout reminder, suspended screen, branding,
provisioning by adb extras (bulk setup).

---------------------------------------------------------------------------------------------------
## 6. Admin mobile app — #14

* The admin panel becomes an installable **PWA**: manifest (name/icons from branding), service worker
  (offline shell, cache static assets, never cache API/HTML with data), "Install app" prompt.
* **Notifications**: Web Push (VAPID) for new orders/requests/TV offline/emergency, implemented in
  pure PHP (openssl ECDH P-256 + HKDF + aes128gcm, no Composer). Users opt in per device. Fallback:
  in-page toasts + sound while the app is open.

---------------------------------------------------------------------------------------------------
## 7. Bulk TV setup tool — #23

* `tools/windows/HotelCast-Setup.bat` + `HotelCast-Setup.ps1`: downloads adb (platform-tools) if
  missing, reads `tvs.csv` (ip_or_ip:port, room_number) exported from Admin → Rooms → "Download setup
  file" (includes server URL + registration key), and for each TV: pair (asks for the 6-digit code shown
  on the TV, handles Android 11+ wireless debugging) or connect (older TVs, port 5555), install APK,
  remove extra users, check accounts (warn), set device owner, grant appops, provision the app
  (server/room/key via intent extras), verify registration via the API, write a colour report
  (OK/FAILED per TV) and a log file. Gujarati + English prompts.
* App accepts provisioning: `am start -n com.hotelcast.tv/.SettingsActivity --es hc_server URL
  --es hc_room 101 --es hc_key KEY --ez hc_autoregister true` (only accepted when not yet registered,
  or when started via adb shell — check calling uid/`Intent` flag; document).

---------------------------------------------------------------------------------------------------
## 8. Support tools — #24

* TV: `UPLOAD_LOGS` command → app uploads last ~2000 logcat lines of its own process + app state to
  `POST /api/device/logs`; uncaught exceptions → `POST /api/device/crash` on next start.
* Admin (hotel) → TV details: view uploaded logs, crashes, last screenshot, "Request logs" /
  "Take screenshot" buttons.
* Platform: Support dashboard across hotels: offline TVs, crash counts, outdated app versions,
  hotels with errors; alerts to platform admin (email/WhatsApp) for crash spikes.

---------------------------------------------------------------------------------------------------
## § TV contract (server ⇄ Android app)

All existing fields stay. Additions to the **Content object**:

```json
{
  "mode": "assigned | ... | off | suspended",
  "off_reason": "admin | schedule | vacant | null",
  "branding": { "product": "HotelCast", "logo_url": "…|null", "color": "#7B1FA2", "support": "+91…" },
  "guest": { "name": "Mr. Shah", "first_name": "Rajesh", "language": "gu", "checkin_at": "ISO", "checkout_at": "ISO|null" },
  "welcome": { "show": true, "id": "stay-123", "title": "Welcome Mr. Shah 🙏", "message": "…",
               "wifi": { "ssid": "Hotel-Guest", "password": "…" }, "duration_sec": 20 },
  "checkout_reminder": { "show": true, "id": "co-123-2026-10-06", "text": "Checkout today at 10:00 AM…" },
  "suspended": { "title": "Service paused", "message": "Please contact reception." },
  "services": { "enabled": true, "url": "https://…/g/AbC123", "label": "Scan for Room Service" },
  "guest_menu": [
    { "id": "services", "type": "qr", "title": "Room Service", "icon": "food", "url": "https://…/g/AbC123" },
    { "id": "feedback", "type": "qr", "title": "Feedback", "icon": "star", "url": "https://…/g/AbC123#feedback" },
    { "id": "live_tv", "type": "live_tv", "title": "Live TV", "icon": "tv" },
    { "id": "hdmi1", "type": "input", "title": "HDMI 1", "icon": "hdmi", "input": "hdmi1" },
    { "id": "cast", "type": "cast", "title": "Cast from phone", "icon": "cast", "text": "Connect to Wi-Fi 'Hotel-Guest' and tap Cast in YouTube…" },
    { "id": "guide", "type": "content", "title": "Local guide", "icon": "map", "content": { ...ContentItem... } }
  ],
  "volume": { "default": 30, "max": 100, "night_max": 25, "night_from": "22:00", "night_to": "06:00" },
  "ticker": "...as before (overlay.ticker)..."
}
```

Playlist items may carry `"ad_campaign_id": 12` (sponsor ad) — report it in `played`.

**New commands** (`GET /api/device/command` → `commands[]`):

| command | payload | TV behaviour |
|---------|---------|--------------|
| `SET_VOLUME` | `{ "level": 0-100 }` | set STREAM_MUSIC volume (respect `volume.max`/night max) |
| `MUTE` / `UNMUTE` | `{}` | |
| `SCREENSHOT` | `{}` | capture own window (PixelCopy / View.draw), JPEG ≤ 1280 px, `POST /api/device/screenshot` multipart `image` |
| `UPLOAD_LOGS` | `{}` | `POST /api/device/logs` JSON `{ "logs": "…", "state": {…} }` |
| `OPEN_INPUT` | `{ "input": "live_tv"\|"hdmi1".."hdmi4" }` | switch to live TV / HDMI input (TvContract passthrough URIs / Live Channels intent; fallback: open TV input picker) |
| `SHOW_WELCOME` | `{}` | show the welcome card again now |
| `SHOW_MESSAGE` | `{ "title", "message", "duration_sec" }` | overlay message (e.g. "Your food is on the way") |

**New endpoints (device auth):**
* `POST /api/device/screenshot` multipart `image` (jpeg) → `{ok}`
* `POST /api/device/logs` `{ logs: string ≤ 512 KB, state: object }`
* `POST /api/device/crash` `{ stack: string, app_version, happened_at }`
* `POST /api/device/played` items may include `ad_campaign_id`.
* `POST /api/device/event` `{ type: "guest_menu_open"|"input_switch"|"qr_shown"|…, data }` (analytics).

**Register response** additionally: `hotel` {id, name}. `INVALID_REGISTRATION_KEY` now also when the
key's hotel is suspended → `HOTEL_SUSPENDED` (403) with message; `LICENSE_LIMIT` (403) when max TVs.

**Guest menu on the TV**: short press OK/DPAD_CENTER (when no PIN dialog and not in settings) opens a
side panel listing `guest_menu` items (DPAD navigable, big icons, EN/GU/HI labels from server).
QR items render the QR full-size with title + url text (QR generated on device with ZXing core, Apache
2.0). Live TV/HDMI items switch input. Cast shows instructions + Wi-Fi QR (`WIFI:T:WPA;S:ssid;P:pw;;`).
BACK closes the menu. Auto-close after 60 s. Long-press OK (3 s) still opens settings (existing).

**Provisioning extras** (bulk setup, see §7): `hc_server`, `hc_room`, `hc_key`, `hc_autoregister`.

---------------------------------------------------------------------------------------------------
## Quality bar (all packages)

* Every tenant query scoped; tests for isolation.
* All UI strings via `__()`; Gujarati translations in `lang/gu*.php` (module files allowed:
  `lang/gu_<module>.php` are merged automatically by I18n).
* CSRF on every POST, permissions server-side, `e()` for output, prepared statements only, uploads via
  Uploader.
* Migrations are new numbered files (never edit 001 after release); idempotent.
* PHPUnit tests for each module (unit + HTTP integration). Android unit tests for new parsing/logic.
* Docs updated: API.md, ADMIN_GUIDE.md, android/README.md, INSTALL.md.
