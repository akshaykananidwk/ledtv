# Module: Platform screens — all TVs of all customers, moving TVs, unassigned pool, customer detail

Release 2.5 (2.5.1: All customers view on Screens & TVs, "Transfer everything" — § 2b and § 7). Wording: `hotels` = **customers**, `rooms` = **screens** (screen ID = `room_number`,
location = floor / groups), `devices` = the physical **TVs / players** registered to a screen.

The owner (Super Admin = `platform_admin`) sees and manages every TV of every customer in one place,
assigns any TV to any customer, and opens one customer with its TVs and users. Resellers use the same
pages for **their own customers only**.

| | |
|---|---|
| Migration | `migrations/030_platform_screens.sql`: table `device_pool` (not a tenant table), index `devices.idx_devices_platform_list` |
| Boot hook | `core/boot.d/platform_screens.php` (permissions) |
| Server code | `core/PlatformScreens.php` (listing, filters, counters, commands, revoke, move / `transfer()`), `core/ScreenTransfer.php` (2.5.1 "Transfer everything" copy), `core/DevicePool.php` (unassigned pool) |
| Admin pages | `admin/platform_screens.php` (All screens + pool), `admin/platform_customer.php` (customer detail tabs), `admin/rooms.php` + `admin/partials/rooms_platform.php` (2.5.1 Screens & TVs → All customers, transfer dialog), `admin/partials/platform_screens.php` (shared table / bulk bar / transfer dialog / POST handler), `admin/partials/platform_screens_dashboard.php` (dashboard block), `admin/partials/nav.d/52_platform_screens.php` |
| Shared files touched | `core/DeviceManager.php` (2 hooks: platform key in `register()`, pool TVs in `authenticate()`), `admin/platform_hotels.php` (dashboard block include, "Screens (online/total)" + "Users" columns, "Customer details" links) |
| Translations | `lang/gu_platform_screens.php`, `lang/hi_platform_screens.php`, 2.5.1: `lang/gu_screen_transfer.php`, `lang/hi_screen_transfer.php` |
| Tests | `tests/Integration/Apps/PlatformScreensTest.php`, 2.5.1: `tests/Integration/Apps/ScreenTransferTest.php` |

| permission | roles | used for |
|---|---|---|
| `platform.screens` | platform_admin, reseller | All screens, customer detail, commands, revoke (reseller: own customers only) |
| `platform.move` | platform_admin | move TVs to another customer / screen (2.5 security review: not for resellers) |
| `platform.pool` | platform_admin | unassigned pool, platform registration key, "Move to unassigned pool" |

Customer users (super_admin … reception, chain admins) get **403** on every page of this module.

---------------------------------------------------------------------------------------------------
## 1. Platform → All screens (`admin/platform_screens.php`)

One table of every TV (`devices` row) across customers, 25 / 50 / 100 / 200 per page:

customer · screen (ID, name, device ID) · location / groups · device model · platform (Android app /
web player) · app version + "Needs update" · online / offline + last seen · now showing + mode
(emergency, off, holiday, scheduled, suspended …, from `ContentResolver` in the customer's context) ·
health warnings (`DeviceHealth::warnings`, same rule as TV health) · LAN / public IP · registered.

* **Filters**: search (screen, device ID, model, IP, customer, version), customer, status (active
  default / online / offline / revoked / all), platform, app version, needs update, health warning,
  without screen (registered TV with no screen).
* **Counters** (clickable filters): screens, online, offline, outdated app, health warnings.
* **CSV export** of the filtered list (all pages). With more than 1000 rows the "now showing" column
  uses the cheap mode (suspended / screen off) instead of resolving every screen's content.
* **Online** = `devices.status = 'online'` (maintained per customer by the offline detector) — the
  same rule as the customers list and Platform → Support. `last_ping` is stored in the customer's time
  zone, so "last seen" is computed inside that customer's context.
* **Needs update** = Android TV whose `app_version_code` is lower than the newest APK of **its
  customer** (APK Manager), or of the whole platform when the customer has no APK. Web players never
  need an update.

### Row actions

* **Open in customer** (`→`): enters the customer (`Auth::enterHotel`, logged in the customer's log)
  and jumps to the TV detail page (`rooms.php?action=device&id=…`). **Live view** and **TV health**
  open the same way when available (TV assigned to a screen / health reported).
* **Commands**: reload (restart app), reboot, screen on / off, ping, clear cache, update app; move to
  another customer, move to unassigned pool (platform admin), revoke.

### Bulk actions (selected rows)

* **Command** — whitelist `PlatformScreens::COMMANDS` (subset of `Broadcaster::DEVICE_COMMANDS`;
  `SHOW_MESSAGE`, `LIVE_VIEW`, `EMERGENCY` … are refused). The selection is grouped by customer and
  `Broadcaster::sendCommand($cmd, 'rooms', <screens of the selected TVs>)` runs **inside each
  customer's context** (`Tenant::run`), so each customer gets its own `broadcast_commands` row, logs
  and the SCREEN_ON/OFF state persisted on its screens. Note: a command targets the selected TV's
  screen, so a second TV on the same screen receives it too (same as the customer's own pages).
* **Update app** — the newest APK of each customer (`Broadcaster::pushApk`); customers without an APK
  are skipped and named in a warning. Web players are skipped.
* **Revoke** — like Rooms & TVs → Revoke (random token, TV back to its setup screen) + pending commands
  expired, live view stopped, health history removed.
* **Move** — below. **Move to unassigned pool** — § 3.

Every action is logged in the customer's activity log **and** the platform log (`hotel_id NULL`).
All device ids are re-checked against the user's customers (`PlatformScreens::devices`); one id outside
the scope rejects the whole request (logged in `logs/security`).

---------------------------------------------------------------------------------------------------
## 2. Move / assign a TV to any customer

From the row ("Move to another customer…") or in bulk: choose the **target customer** and the
**target screen**:

| mode | meaning |
|---|---|
| Same screen ID | the screen with the TV's current screen ID in the target (created when missing, copying name / floor) |
| An existing screen | pick one of the target's screens (loaded from `platform_screens.php?action=rooms&customer=ID`) |
| Create a new screen | new screen with that name (screen ID derived from the name, made unique); several TVs → "Name 1", "Name 2" … |

`PlatformScreens::move()` runs in one transaction:

1. locks the target `hotels` row (`SELECT … FOR UPDATE`) and checks the **screen limit**
   (`Tenant::maxTvs` = customer `max_tvs` / plan / license; `Tenant::tvCount`): only TVs that do not
   already count for the target need a slot. Over the limit → `LICENSE_LIMIT: …` and **nothing** moves;
2. refuses revoked TVs (they must register again) and web players for a plan without the web player;
3. `(hotel_id, device_uid)` is unique: an old revoked / screen-less record of the same TV in the target
   is deleted; an active one blocks the move;
4. clears what belongs to the old customer: **all** `device_commands` of the TV are deleted (the table
   has no `hotel_id`; message texts / announcements of the old customer would otherwise show on the new
   customer's TV page — the old customer no longer sees the TV anyway), the
   `device_live_views` session + frame file, `device_health_history` rows, `devices.health_alerts`,
   `current_hash`, `offline_notified`;
5. `UPDATE devices SET hotel_id = target, room_id = screen` (same row / id), a `device_status_logs` row
   in the target, activity logs (old customer `device_moved_out`, new customer `device_moved_in`,
   platform `device_move`), then `content_version` is bumped in both customers.

Kept: history of the old customer (status log, play log, support files / crash reports, usage
samples) stays with the old customer. `user_access` needs no change (it points at screens, not TVs).

### Token decision: the token is KEPT

The TV keeps polling with its bearer token. `DeviceManager::authenticate()` looks the token up in
`devices` **across customers** (`WHERE token_hash = …`, no `hotel_id`), and `Tenant::forDevice()` takes
the customer from that freshly read row. Every device endpoint (`device/command`, `heartbeat`, `ack`,
`played`, `apk/{id}`, `content/{room}`, `api/routes/device_*.php`) authenticates this way — none caches
the customer. So the **next poll** after a move is already served in the new customer's context: new
content (the content hash differs → `content_changed: true`), new settings PIN in the next heartbeat, no
commands of the old customer. No visit to the TV and no re-registration (which would need the target's
registration key and a screen number typed on the TV).

Rotating the token would force exactly that visit. The token is a secret only the TV holds; whoever
could use it before the move could use it before as well. When a TV must really lose access
(stolen, sold), use **Revoke** — that rotates the token.

---------------------------------------------------------------------------------------------------
## 2b. Transfer everything (2.5.1)

The move dialog — **"Transfer to another customer"** (row button <i>⇄</i> on All screens, on Screens & TVs in
both views and on the TV detail page; bulk through the checkboxes) — has two boxes, both **ticked by
default**. They only act when the TV changes customer (`PlatformScreens::transfer($ids, $target,
['mode' => …, 'copy' => ['details' => bool, 'content' => bool]])`, `move()` = transfer without copy).

| box | copied onto the target screen |
|---|---|
| **Screen details** | `rooms.name` (not with "Create a new screen": the typed name wins), `floor`, `is_enabled`, `settings_pin`, `notes`, `usb_mode` (only when the target plan has USB mode), `cec_mode`; the screen's own **power-off windows** (`broadcast_commands` `SCREEN_OFF`, mode window, target = this screen) and **device schedules** (volume, mute, input, restart, built-in bell, spoken announcement) aimed at this screen. The screen ID (`room_number`) is kept by the target screen mode "Same screen ID". |
| **Content** | what the screen plays: its own assignment (playlist or single item), else the first group assignment it follows, else the old customer's default content — copied and **assigned directly** to the target screen (the flash says so). Playlists with all items (order, durations, dayparts), split screen layouts with their zone sources (each item copied once, zone ids rewritten), images / videos with their **files copied** to `uploads/h{target}/media/YYYY/MM/<random>` (+ thumbnail); tickers aimed at this screen (`tickers.target_type = 'room'`, incl. video scale); the screen's scheduled content windows (`SHOW_CONTENT`, mode window, target = this screen). |

**Not copied** (listed in the result flash "Copied: 1 playlist, 2 media files, 1 ticker; Not copied: …"):
display apps (their data — menu boards, albums, feeds … — lives in the old customer's module tables),
items whose media file is missing, layouts with such a zone, modules not in the target's plan (layouts,
tickers, schedules, power schedules, device schedules), bells with an uploaded sound of the old customer,
schedules / tickers aimed at all screens, groups or floors of the old customer (counted), group membership,
emergency broadcasts, play statistics. The TV's command history stays deleted (2.5 security review).

Safety:

* **Copy only** — no row or file of the old customer changes. Every source row is read with
  `hotel_id = <old customer>`; a media path of a third customer (`h{other}/…`) or outside `uploads/` is
  refused (`ScreenTransfer::sourceFile`). Only the selected TV's screen is read, so nothing else of the old
  customer reaches the new one.
* New rows belong to the target (`hotel_id = target`, `created_by` NULL); approval state, validity window
  and active flag are kept.
* **One transaction** with the move: target row locked, screen limit (`assertCapacity`), then the
  **storage limit** of the target (`storage_mb`, `ScreenTransfer::assertStorage`: `STORAGE_LIMIT: …` — nothing
  is written) before anything is copied. Any error later (copy failure, a TV that clashes, …) rolls back the
  rows and `ScreenTransfer::cleanup()` deletes the files already copied — no partial garbage.
* Several TVs of one screen copy that screen once; an item used twice (playlist + layout zone) is copied once.
* Logs: old customer `screen_copied_out` (which screen, not where to), new customer `screen_copied_in`
  (summary), platform `screen_transfer`, plus the move logs of § 2. `content_version` of both customers is
  bumped: the TV's next poll returns the copied content with the target's URLs.
* Permissions as § 2: `platform.move` (platform admins), CSRF, all ids re-checked.

---------------------------------------------------------------------------------------------------
## 3. Unassigned pool (pre-configure TVs before selling)

Platform admins only (`platform.pool`), card "Unassigned pool" on All screens. At most
`DevicePool::MAX_WAITING` (500) TVs wait in the pool: a new TV beyond that gets `409 POOL_FULL` (a leaked
key cannot fill the table; registration is also limited per IP). "New key" rotates the key.

1. **Turn on platform registration**: a platform registration key is created
   (`system_settings` hotel 0: `platform_pool_key` = `P` + 16 hex, `platform_pool_enabled`). "New key"
   rotates it (TVs already in the pool keep working).
2. Install the app on a TV and register it with the server URL, the **platform key** and any screen
   number (e.g. the future screen ID or a serial). `DeviceManager::register()` sees that the key is not
   a customer's key, `DevicePool::isPoolKey()` matches → `DevicePool::register()` creates / updates the
   `device_pool` row and returns a normal token (`hotel.id = 0`, `unassigned: true`).
3. The TV polls as usual. `authenticate()` finds no `devices` row for the token and hands the request to
   `DevicePool::handleRequest()`: `device/command` answers a **waiting screen** — content mode
   `suspended` (shown by every app version) with "Waiting for setup — This screen is not assigned to a
   customer yet. Device ID: …"; heartbeat / played / ack are accepted; other device routes answer
   `409 NOT_ASSIGNED`. Pool TVs are rate limited like TVs (120 / min).
4. **Assign to customer** (one or many, same screen modes as § 2; "Same screen ID" uses the number typed
   on the TV): atomically, with the target's screen limit, a `devices` row is created in the customer —
   or the customer's old revoked record of the same TV is reused — with the **pool row's token hash**,
   and the pool row is deleted. The next poll shows the customer's content.
5. **Move to unassigned pool** (row / bulk): the customer keeps a **revoked** record of the TV (its
   history, visible under Rooms & TVs → Revoked TVs); the TV's live token moves into a pool row
   (`source = removed`, `from_hotel_id`, label = its screen ID) and the TV shows the waiting screen.
6. **Remove** deletes pool rows: those TVs get `INVALID_TOKEN` and return to their setup screen.
   Turning platform registration off stops new pool registrations (TVs already in the pool keep their
   waiting screen until assigned or removed).

Resellers never see the pool. A pool TV that registers again with a customer key simply becomes that
customer's TV; its stale pool row can be removed.

---------------------------------------------------------------------------------------------------
## 4. Customer detail (`admin/platform_customer.php?id=…&tab=…`)

Platform admin: any customer; reseller: own customers (others → 404). Header: status, plan, reseller,
**Login as this customer** (`Auth::enterHotel`), Edit (platform admins; the plan / feature override
form stays on `platform_hotels.php`).

* **Overview** — limits usage: screens (TVs) used / max + online, users used / max (`max_users` when the
  plan / customer defines it), storage used (content library) / max (`max_storage_mb` when defined);
  details (plan, status, valid until, reseller, created, contact); billing summary for platform admins
  (price per screen, unpaid invoices, last invoice).
* **Screens** — the customer's TVs with the same columns, counters and actions as All screens.
* **Users** — the customer's users (customer roles only; custom roles show their name via
  `Auth::roleName()`): role, last login, active / locked, screen access ("All screens" or ":r screens, :g groups"
  from `user_access`). Actions: **Reset password** (new temporary password shown once, all sessions
  revoked, optionally emailed with the login link), **Deactivate / Activate** (deactivate logs the user
  out). Adding users / changing roles stays in the customer's own Users page (Login as this customer).
* **Activity** — the customer's activity log (50 per page).

## 5. Customers list and platform dashboard (`admin/platform_hotels.php`)

* New columns **Screens (online/total)** (link to the Screens tab) and **Users** (link to the Users
  tab) and a **Customer details** button per row / on the customer view.
* Dashboard block under the existing tiles: the All screens counters (total, online, offline, outdated,
  health warnings), **top customers by screens** and **offline TVs by customer** (links filter All
  screens).

## 6. Security

* CSRF on every POST (`Csrf::check()` before any action; tested for every op).
* Scope checks in the core class, not only in the pages: device ids, target customer and the rooms
  JSON are limited to the user's customers; cross-scope attempts are logged.
* Commands are whitelisted; revoke / move / pool operations are transactions.
* Demo users: refused centrally by `Demo::guard()` like every admin POST.

## 7. Screens & TVs → All customers (2.5.1, `admin/rooms.php`)

Users with `platform.screens` get a view switch at the top of Screens & TVs:
**"This customer (<name>)" | "All customers (N TVs)"** (resellers: "All my customers").

* Platform admins default to **All customers** (the page title reads "Screens — all customers"); the
  choice (`?view=all|customer`) is remembered in the session (`hc_rooms_view`). Without an open customer
  only All customers is possible. Resellers default to This customer (the title reads "Screens — your
  customers" in their All view) and see only their own customers (`PlatformScreens::scopeHotelIds`);
  without an open customer they keep the old redirect to their panel until they pick
  `rooms.php?view=all`.
* All customers = the All screens table (`ps_table`, `PlatformScreens::list` / `decorate`): customer (link to
  the customer details), screen, location / group, device, status + last seen, now showing, health; search,
  customer and status filters (active, online, offline, revoked, all, **screens without TV** —
  `PlatformScreens::screensWithoutTv`), counters, pagination; bulk bar with commands and transfer. "More
  filters & CSV" opens Platform → All screens.
* The customer view is the old page plus, for platform admins, a **Transfer** button per screen with a TV,
  the bulk action "Transfer TVs to another customer" and the button on the TV detail page.
* Customer users never get the switch: `rooms_platform_scope()` = `Auth::can('platform.screens')`, which checks
  the real role; `?view=all` and a posted `ps_form` change nothing for them (old page / normal POST handling),
  resellers posting a transfer get 403 (`platform.move`).

---------------------------------------------------------------------------------------------------
## ગુજરાતી સારાંશ

**બધી સ્ક્રીન (Platform → All screens)**: સુપર એડમિન એક જ પાના પર બધા ગ્રાહકોના બધા TV જુએ છે —
ગ્રાહક, સ્ક્રીન, સ્થાન/ગ્રુપ, મોડલ, Android/વેબ, એપ વર્ઝન, ઓનલાઇન/ઑફલાઇન, હાલ શું ચાલે છે, આરોગ્ય
ચેતવણી, IP અને નોંધણી તારીખ. શોધ, ફિલ્ટર (ગ્રાહક, સ્થિતિ, પ્લેટફોર્મ, વર્ઝન, અપડેટ જરૂરી, ચેતવણી,
સ્ક્રીન વગર), પાના અને CSV એક્સપોર્ટ છે. પસંદ કરેલા TV ને કમાન્ડ (રીલોડ, રીબૂટ, સ્ક્રીન ચાલુ/બંધ,
પિંગ), એપ અપડેટ, રદ કરવું અને બીજા ગ્રાહકને ખસેડવું — દરેક ગ્રાહકના પોતાના સંદર્ભમાં. રીસેલર ફક્ત
પોતાના ગ્રાહકો જુએ છે.

**TV બીજા ગ્રાહકને ખસેડવું**: નવો ગ્રાહક અને સ્ક્રીન (એ જ સ્ક્રીન ID, હાલની સ્ક્રીન અથવા નવી સ્ક્રીન)
પસંદ કરો. ગ્રાહકની સ્ક્રીન મર્યાદા તપાસાય છે. જૂના ગ્રાહકના બાકી કમાન્ડ, લાઇવ વ્યૂ અને આરોગ્ય
ઇતિહાસ સાફ થાય છે; બંને ગ્રાહક અને પ્લેટફોર્મના લોગમાં નોંધ થાય છે. **ટોકન એ જ રહે છે**: TV આગલા
પોલ પર જ નવા ગ્રાહકનું કન્ટેન્ટ બતાવે છે, ફરી નોંધણીની જરૂર નથી. TV ની ઍક્સેસ ખરેખર બંધ કરવી હોય
તો "રદ કરો" વાપરો.

**અસોંપાયેલ પૂલ**: પ્લેટફોર્મ રજિસ્ટ્રેશન ચાલુ કરતાં પ્લેટફોર્મ કી મળે છે. આ કીથી નોંધાયેલા TV કોઈ
ગ્રાહકના નથી અને "સેટઅપની રાહ" સ્ક્રીન બતાવે છે. સુપર એડમિન પછી તેમને કોઈ પણ ગ્રાહકને સોંપે છે —
વેચતા પહેલાં TV તૈયાર કરી શકાય. ગ્રાહક પાસેથી TV પૂલમાં પાછું પણ લઈ શકાય.

**ગ્રાહકની વિગતો**: ટેબ — સારાંશ (પ્લાન, સ્થિતિ, સ્ક્રીન/યુઝર/સ્ટોરેજ મર્યાદા, બિલિંગ), સ્ક્રીન,
યુઝર્સ (રોલ, છેલ્લું લૉગિન, સ્ક્રીન ઍક્સેસ; પાસવર્ડ રીસેટ + ઇમેઇલ, નિષ્ક્રિય/સક્રિય, "આ ગ્રાહક તરીકે
ખોલો") અને પ્રવૃત્તિ. ગ્રાહકોની યાદીમાં "સ્ક્રીન (ઓનલાઇન/કુલ)" અને "યુઝર્સ" કૉલમ, અને ડેશબોર્ડ પર
ટોચના ગ્રાહકો તથા ગ્રાહક મુજબ ઑફલાઇન TV દેખાય છે.

**2.5.1 — સ્ક્રીન અને TV → બધા ગ્રાહકો**: સુપર એડમિન "સ્ક્રીન અને TV" પાના પર "આ ગ્રાહક" | "બધા ગ્રાહકો"
વચ્ચે બદલી શકે છે (ડિફોલ્ટ: બધા ગ્રાહકો, રીસેલરના ગ્રાહકો સહિત). શોધ, ગ્રાહક, સ્થિતિ અને "TV વગરની સ્ક્રીન"
ફિલ્ટર તથા પાના છે. ગ્રાહક યુઝરને આ સ્વિચ ક્યારેય દેખાતી નથી.

**બીજા ગ્રાહકને ટ્રાન્સફર — બધું સાથે**: દરેક TV પર ⇄ બટન (અને ચેકબોક્સથી ઘણા TV). "સ્ક્રીનની વિગતો" (નામ,
માળ, PIN, USB/CEC, બંધ થવાના સમય, ડિવાઇસ શેડ્યૂલ) અને "કન્ટેન્ટ" (પ્લેલિસ્ટ, મીડિયા ફાઇલો, લેઆઉટ, ટિકર,
શેડ્યૂલ કરેલું કન્ટેન્ટ) નવા ગ્રાહકમાં **કૉપી** થાય છે — જૂના ગ્રાહક પાસે મૂળ રહે છે. નવા ગ્રાહકની
સ્ટોરેજ અને TV મર્યાદા તપાસાય છે; ભૂલ થાય તો કંઈ બાકી રહેતું નથી. ડિસ્પ્લે એપ કૉપી થતી નથી (પરિણામમાં
"કૉપી નથી થયું" યાદી).
