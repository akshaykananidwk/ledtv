# Module: Guests, front desk, PMS & guest services (V2_SPEC §1 + §2)

Features #1 (personal welcome), #6 (checkout reminder), #9 (check-in mode), #10 (PMS), #2 (room
service), #3 (service requests), #8 (feedback). Plan modules: `guests` (front desk, PMS, welcome,
check-in mode) and `services` (guest web app: menu / orders, requests, feedback). A plan without a
feature list enables both (`Tenant::feature()`).

This file has three parts for the lead to merge: **API** (→ docs/API.md), **Admin guide**
(→ docs/ADMIN_GUIDE.md) and **Developer notes** (→ docs/DEVELOPER.md).

---------------------------------------------------------------------------------------------------
## 1. API

### 1.1 TV Content object additions (device API, `GET /api/device/command`, `GET /api/content/{room}`)

Added by `core/Extensions/GuestExtension.php` (never for `mode` `emergency` / `suspended`):

```json
{
  "mode": "off", "screen_on": false, "off_reason": "vacant",
  "guest": { "name": "Mr. Shah", "first_name": "Rajesh", "language": "gu",
             "checkin_at": "2026-10-05T14:02:00+05:30", "checkout_at": "2026-10-06T10:00:00+05:30" },
  "welcome": { "show": true, "id": "stay-123", "title": "સ્વાગત છે શ્રી Rajesh Shah 🙏", "message": "…",
               "wifi": { "ssid": "Hotel-Guest", "password": "…" }, "duration_sec": 20 },
  "checkout_reminder": { "show": true, "id": "co-123-2026-10-06", "text": "Checkout today at 10:00 AM. …" },
  "services": { "enabled": true, "url": "https://…/g/AbC123…", "label": "Scan for Room Service" },
  "guest_menu": [ …entries of other modules…,
    { "id": "services", "type": "qr", "title": "Room Service", "icon": "food", "url": "https://…/g/AbC123…" },
    { "id": "requests", "type": "qr", "title": "Requests", "icon": "bell", "url": "https://…/g/AbC123…#requests" },
    { "id": "feedback", "type": "qr", "title": "Feedback", "icon": "star", "url": "https://…/g/AbC123…#feedback" } ]
}
```

* `guest`, `welcome` — only while the room has an active stay. `welcome.show` is always `true` during
  the stay; the TV shows the card once per `welcome.id` and again on power-on during the first 24 h
  after `guest.checkin_at`. `wifi` is null when no SSID is configured; a per-stay Wi-Fi password
  overrides the hotel password. Texts are in the guest's language (en / gu / hi).
* `checkout_reminder` — null except on the expected checkout day from the configured time (default
  07:00) until check-out. The `id` changes per day; the bill summary (front desk or PMS `balance`)
  is appended when enabled.
* `services` + `guest_menu` QR items — only while the room's guest link is valid (see 1.3). The
  module **appends** its three items to `guest_menu`; other modules add theirs.
* **Check-in mode** (`off_reason` `vacant`): when on and the room has no guest, `guest_vacant_mode`
  `off` → `mode: off`, `screen_on: false`, `off_reason: "vacant"`, no items; `welcome` →
  `mode: empty` (idle "Welcome to {hotel}" screen); `normal` → unchanged. Emergencies, a suspended
  hotel and rooms already off are never overridden. For rooms off for other reasons the module also
  fills `off_reason` (`admin` = room disabled, `schedule` = TV power schedule) if no one else did.
* Check-in, check-out, room move, edit → `Settings::bumpContentVersion()` + a `SHOW_CONTENT`
  command to the room's TVs (refresh at once).

**Commands** queued by this module: `SHOW_CONTENT` (above), `SHOW_WELCOME` (front desk "Show welcome
on TV again"), `SHOW_MESSAGE` `{title, message, duration_sec: 15}` when staff change an order
(accepted / preparing / delivered = "on the way" / cancelled) or complete a request — in the guest's
language; can be switched off per click or by setting.

### 1.2 PMS API (#10)

Per-hotel key: *Guest services setup → PMS integration → Generate key* (format `hcpms` + 40 hex,
shown once, stored as SHA-256; rotate / revoke there). Header `Authorization: Bearer <key>`.
Envelope and error format as everywhere (`{ok, data}` / `{ok:false, error:{code, message}}`).
Rate limits: 300 requests / minute / key, 600 / minute / IP, 20 failed authentications / 10 min / IP
(`429 RATE_LIMITED`, `Retry-After`). Hotel suspended → `403 HOTEL_SUSPENDED`; plan without the
`guests` module → `403 FEATURE_DISABLED`.

| endpoint | body | result |
|---|---|---|
| `POST /api/pms/checkin` | `room_number`, `guest_name` (required), `salutation?` (Mr/Mrs/Ms/Dr/Shri/Smt/Kum), `language?` (`en`/`gu`/`hi` or "Gujarati"/"hi-IN"…), `checkout_at?` (ISO 8601 or `YYYY-MM-DD` → standard checkout time), `external_ref?` (booking id), `phone?`, `balance?` (free text, e.g. "₹ 4,500 due") | `201 {stay_id, room_number, status:"checked_in", idempotent:false, replaced_stay_id}` |
| `POST /api/pms/checkout` | `external_ref` or `room_number` | `200 {stay_id, status:"checked_out", idempotent}` |
| `POST /api/pms/room-move` | `to_room` + `from_room` or `external_ref` | `200 {stay_id, room_number, status:"moved", idempotent}` |
| `POST /api/pms/update` | `external_ref` or `room_number` + any of `checkout_at`, `balance`, `guest_name`, `salutation`, `language`, `phone` | `200 {stay_id, status:"updated"}` |
| `GET /api/pms/rooms` | – | `200 {rooms:[{room_number, name, floor, occupied, stay:{stay_id, guest_name, language, checkin_at, checkout_at, external_ref, source}|null}], occupied, total}` |
| `POST /api/pms/webhook` | any JSON payload (key may also be passed as `?key=` for systems that cannot set headers) | result of the mapped action |

Idempotency (safe retries):
* check-in with an `external_ref` that is already checked in → `200`, `idempotent:true` (details
  updated; a different `room_number` moves the stay). Without a ref, the same guest name in the same
  room is the same stay.
* check-in into a room occupied by **another** booking → the previous stay is checked out (the PMS
  is the source of truth; `replaced_stay_id`).
* check-out of an already checked-out ref, or of a vacant room → `200`, `idempotent:true`.
* move to the room the stay is already in → `200`, `idempotent:true`.

Errors: `INVALID_API_KEY` 401, `VALIDATION_ERROR` 400, `ROOM_NOT_FOUND` 404, `STAY_NOT_FOUND` 404,
`ROOM_OCCUPIED` 409 (move target occupied), `UNKNOWN_EVENT` 422 (webhook), `METHOD_NOT_ALLOWED` 405.

**Webhook field mapping** (setting `guest_pms_mapping`, edited on the PMS tab): JSON object
`field → dot path` in the payload; a list of paths is joined with a space (first + last name);
`events` maps actions to event names (case-insensitive). Presets: `generic` (default, the field
names of the REST API), `ezee`, `hotelogix`, `stayflexi` ("style" presets — check the field names of
the hotel's PMS account and adjust). Example (eZee style):

```json
{ "event": "EventType",
  "events": { "checkin": ["CheckIn"], "checkout": ["CheckOut"], "room_move": ["RoomMove", "RoomChange"], "update": ["Modify"] },
  "room_number": "RoomNo", "guest_name": ["Guest.FirstName", "Guest.LastName"], "salutation": "Guest.Salutation",
  "language": "Guest.Language", "phone": "Guest.Mobile", "checkout_at": "DepartureDate",
  "external_ref": "ReservationNo", "from_room": "OldRoomNo", "to_room": "NewRoomNo", "balance": "Balance" }
```

For `room_move` events without `to_room`, `room_number` is used as the target.

### 1.3 Guest web app API (public, token)

Every room has a secret **guest token** (20 chars `[A-Za-z0-9]`, table `guest_tokens`), rotated on
every check-in, check-out, room move and on "New guest link". The token is valid while the room has
an active stay it was issued for; when the hotel does **not** use check-in mode, the room's current
token is always valid. Hotel suspended / plan without `services` → invalid.

Guest page: `https://…/g/{token}` (Apache: `g/.htaccess`; also `/g/index.php/{token}` and
`/g/?t={token}`). Fragment `#requests` / `#feedback` / `#info` opens that tab.

JSON API (no cookies, no CSRF token needed — the token in the URL is the credential; POST bodies must
be `Content-Type: application/json`, max 20 KB):

| endpoint | notes |
|---|---|
| `GET /api/guest/{token}` | `{hotel{name, logo_url, color}, room{number, name}, guest{name{en,gu,hi}, language}|null, language, wifi, checkout_at, checkout_time, checkout_date, reception_phone, currency, features{services, requests, feedback}, menu[categories{id, name{en,gu,hi}, items[{id, name{…}, description{…}, price, food_type veg/nonveg/egg/none, photo_url, hours, available}]}], request_types[{id, name{…}, icon, needs_time}], status}` |
| `GET /api/guest/{token}/status` | `{orders[{id, status, created_at, created_label, total, notes, items[{item_id, name, qty, price}]}], requests[{id, type_id, type, status, time, time_label, notes, created_at, created_label}], feedback|null}` — only what was sent with **this** token (a new guest in the same room never sees the previous guest's data) |
| `POST /api/guest/{token}/order` | `{items:[{id:int, qty:int 1–20}] (1–30 lines), notes?: string ≤ 300}` → `201` order. Prices always from the database; items must be active, in an active category and inside their available hours. Errors `VALIDATION_ERROR` 400, `ITEM_UNAVAILABLE` 409, `SERVICE_DISABLED` 403 |
| `POST /api/guest/{token}/request` | `{type_id:int, time?: "HH:MM" (required for wake-up-type buttons; next occurrence), notes?: ≤ 300}` → `201`; the same request again within 2 min returns the existing one (`duplicate:true`) |
| `POST /api/guest/{token}/feedback` | `{rating:1–5, cleanliness?, staff?, food?: 1–5, comment?: ≤ 1000}` → `201 {id, rating, google_review_url}` (`google_review_url` only for rating ≥ 4 and an https link configured). One feedback per token; sending again updates it |

Errors: `INVALID_TOKEN` 404 (unknown, rotated or expired — same answer for all), `RATE_LIMITED` 429,
`UNSUPPORTED_MEDIA_TYPE` 415. Rate limits: 240 requests / min / IP, 120 / min / token, writes
30 / 10 min / token and 60 / 10 min / IP, invalid tokens 30 / 10 min / IP.

### 1.4 Admin AJAX (`admin/ajax.d/guests.php`, permission `services.manage`)

| action | method | description |
|---|---|---|
| `guests_alerts` | GET | `?order=<last seen id>&request=<id>` → `{new_orders, open_orders, open_requests, last_order_id, last_request_id, orders[], requests[]}` (newer than the given ids; used by the live alerts on every admin page) |
| `guests_board` | GET | orders (open + today's finished) and requests for the board |
| `guests_order_status` | POST | `{id, status: accepted|preparing|delivered|cancelled, notify_tv?: bool}` (422 for a transition that is not allowed) |
| `guests_request_status` | POST | `{id, status: done|cancelled|open, notify_tv?: bool}` |

Ids of another hotel → `404 NOT_FOUND` (logged).

---------------------------------------------------------------------------------------------------
## 2. Admin guide

**Who can do what** — reception and up: *Front desk*, *Orders & requests*. Manager and up:
*Guest services setup* (settings, PMS key & mapping, menu, request buttons) and *Guest feedback*.
Reception users land on the Front desk after login.

### Front desk (menu: Front desk)
* **Room board**: every room as a card — vacant / occupied, guest name, TV language, masked phone,
  expected checkout (red on the checkout day), open room-service orders. Search box and filters
  (occupied / vacant / checkout today).
* **Check in** (vacant room): title (Mr/Mrs/Ms/Dr/Shri/Smt/Kum), name, TV language (English /
  ગુજરાતી / हिन्दी), phone (optional), expected checkout (default tomorrow at the standard checkout
  time), internal notes; managers can set a personal Wi-Fi password. The TV wakes up and shows
  "Welcome Mr. Shah 🙏" with the hotel logo, Wi-Fi and the room-service QR code.
* **Check out** — the TV returns to the vacant behaviour and the guest's phone link stops working.
* **Edit** (pencil) — name, language, checkout, bill summary for the checkout reminder, notes. Leave
  the phone empty to keep the saved number; tick "Remove phone" to delete it.
* **Move** (arrows) — to a vacant room; open orders and requests move with the guest; both TVs update.
* **⋯ menu** — show the welcome on the TV again, copy the guest link, create a new guest link (if a
  QR photo was shared, the old link stops working).
* **History** — all stays with search (name, room, booking ref) and room-service charges. Names and
  phone numbers are deleted automatically N days after checkout (setting, default 30 — DPDP).

### Orders & requests (menu: Orders & requests)
Live board (refreshes every 10 s). Orders: **Accept → Preparing → Delivered**, or **Cancel**; printer
icon = kitchen order ticket (KOT, 80 mm receipt layout, prints automatically). Requests: **Done**
(Reopen if needed). With "Tell the guest on TV" on, the room TV shows a short message ("Your order is
on the way"). On **every admin page** users with access get a toast, a short sound and a red bell
badge in the top bar for new orders / requests (click anywhere once so the browser allows sound;
"Test sound" on the board). With the Mobile app module installed, staff also get push / email /
WhatsApp alerts; otherwise tick "Also send new orders by email / WhatsApp" in the setup.

### Guest services setup (menu: Guest services setup, managers)
* **Settings** — Check-in mode (TVs know whether a room is occupied) and what a vacant room's TV does:
  normal content / idle "Welcome to the hotel" screen / switched off. Welcome duration, standard
  checkout time, guest Wi-Fi name + password, checkout reminder (on / off, from what time, include bill
  summary), retention days, guest app sections (room service, requests, feedback), TV messages on
  status changes, reception phone shown to guests, Google review link (shown after 4–5 stars).
  **TV texts per language**: welcome title, welcome message, checkout reminder, bill line for English,
  Gujarati and Hindi; empty = built-in text. Placeholders `{salutation} {name} {first_name} {hotel}
  {room} {time} {date} {balance}`.
* **PMS integration** — generate / rotate / revoke the API key (shown once — copy it into the PMS),
  endpoint URLs, webhook URL, field mapping with presets (eZee / Hotelogix / StayFlexi style).
* **Room-service menu** — categories (name EN / GU / HI, order, active) and items (names and
  descriptions EN / GU / HI, price ₹, veg / non-veg / egg, photo, available from–to — overnight
  allowed, order, available). The eye button hides an item quickly (e.g. sold out).
* **Request buttons** — the quick buttons guests see (defaults: drinking water, fresh towels, room
  cleaning, extra bed, please call me, maintenance, laundry pickup, wake-up call). "Guest picks a time"
  asks for a time (wake-up call).

### Guest feedback (menu: Guest feedback, managers)
Average rating, distribution, cleanliness / staff / food averages, comments, filter by date and
"3 stars or less", **Export CSV**. Ratings of 1–2 stars also alert managers (push / email when
available).

### What the guest sees (phone, after scanning the TV QR code)
Mobile page in the hotel colours, language switch EN / ગુ / हि (starts in the language chosen at
check-in). Tabs: **Room Service** (categories, items with photo, veg mark, price, cart, kitchen notes,
live status of orders), **Requests** (big buttons, wake-up time), **Feedback** (stars, categories,
comment, Google review button), **Info** (Wi-Fi + copy, checkout time, call reception). An old or
wrong link shows a friendly "please scan the QR code again" page in all three languages.

---------------------------------------------------------------------------------------------------
## 3. Developer notes

Files (all new, module-owned):

| file | purpose |
|---|---|
| `migrations/003_guests_services.sql` | tables `guest_stays`, `guest_tokens`, `guest_menu_categories`, `guest_menu_items`, `guest_orders`, `guest_order_items`, `guest_request_types`, `guest_requests`, `guest_feedback` (all `hotel_id` + FK `hotels`) |
| `core/boot.d/guests_services.php` | registers the 9 tenant tables, permissions `guests.setup` and `guests.feedback` (manager) |
| `core/Guests.php` | stays, check-in / out / move, tokens, templates, welcome / reminder objects, PMS key, PII purge |
| `core/GuestServices.php` | menu, orders, requests, feedback, board, alerts, TV messages, staff alerts, guest app data |
| `core/GuestPms.php` | PMS actions, webhook mapping + presets (`PmsError`) |
| `core/Extensions/GuestExtension.php` | TV Content fields (see 1.1) |
| `core/Tasks/GuestRetentionTask.php` | every 6 h: `Guests::purgePii(guest_retention_days)` per hotel |
| `api/routes/pms.php`, `api/routes/guest.php` | APIs 1.2 / 1.3 |
| `admin/guests.php`, `admin/orders.php`, `admin/services_setup.php`, `admin/feedback.php` | admin pages |
| `admin/ajax.d/guests.php` | AJAX 1.4 |
| `admin/partials/nav.d/20_guests_services.php` | menu items (hidden when the plan lacks the module) |
| `admin/partials/dashboard.d/40_guests.php` | "Guests today" widget |
| `admin/partials/footer.d/40_guests_alerts.php` + `assets/js/guests-alerts.js` | live alerts on every admin page (uses the `footer.d` hook) |
| `assets/js/guests-frontdesk.js`, `assets/js/guests-orders.js`, `assets/css/guests-admin.css` | admin page assets |
| `g/index.php`, `g/theme.php`, `g/.htaccess`, `assets/guest/guest.{css,js}` | guest web app (no CDN; strict CSP, `Referrer-Policy: no-referrer` so the token never leaks) |
| `lang/gu_guests.php`, `lang/hi_guests.php` | Gujarati (admin + guest), Hindi (guest-facing) |
| `tests/Integration/GuestsTest.php`, `tests/Integration/GuestServicesTest.php` | tests (add to `phpunit.xml`) |

Settings (hotel, prefix `guest_`, defaults in `Guests::DEFAULTS`): `guest_checkin_mode`,
`guest_vacant_mode`, `guest_welcome_duration`, `guest_wifi_ssid`, `guest_wifi_password`,
`guest_checkout_time`, `guest_reminder_enabled`, `guest_reminder_time`, `guest_reminder_balance`,
`guest_retention_days`, `guest_google_review_url`, `guest_reception_phone`, `guest_pms_key_hash`,
`guest_pms_key_hint`, `guest_pms_mapping`, `guest_services_enabled`, `guest_requests_enabled`,
`guest_feedback_enabled`, `guest_tv_notify`, `guest_notify_external`, templates
`guest_{welcome_title|welcome_message|reminder_text|balance_text}_{en|gu|hi}`,
`guest_request_types_seeded`.

Staff alerts: `GuestServices::alertStaff()` calls `StaffAlerts::send('services.manage', …)` when the
PWA module's class exists (low ratings: `guests.feedback`), else `Notifier::send()` if
`guest_notify_external` = 1.

Other modules: analytics can read `guest_orders` (`created_at`, `accepted_at`, `delivered_at`,
`total`, `status`), `guest_requests` (`type_name`, `created_at`, `done_at`), `guest_feedback`
(`rating`, `rating_*`) and `guest_stays` (occupancy) — always with `hotel_id = Tenant::id()`.
Feedback averages: `GuestServices::feedbackStats($from, $to)`.

Known limitations:
* Guest app strings are rendered server-side into the page for the three languages; the menu/request
  names fall back to English when a translation is empty.
* Request status changes by the guest (cancel) are not offered — guests call reception.
* PMS presets are "style" examples; real field names depend on each PMS account configuration.
* Room-service charges are summed per stay (shown in history / KOT) but not pushed back to the PMS.
