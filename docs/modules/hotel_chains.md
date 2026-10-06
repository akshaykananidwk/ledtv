# Module: Hotel chains (#20) — one owner, many hotels

A chain groups hotels of one owner. The **chain admin** sees every hotel of the chain on one dashboard,
enters any of them (acting as super admin there), publishes chain-wide content, broadcasts to all rooms
of selected hotels and pushes chain-wide settings templates.

**Off by default (2.2).** The platform switches the feature on in **Platform settings → Features**
(platform setting `feature_chains`, `Chains::enabled()`). While off, the chain menus and pages answer 404,
chain admins cannot enter hotels and no chains / chain admins can be created; the data stays untouched.
See `docs/modules/user_access.md`.

## Files

| file | purpose |
|---|---|
| `migrations/009_hotel_chains.sql` | tables `hotel_chains`, `chain_content_items`, `chain_playlists`, `chain_playlist_items`, `chain_publications`, `chain_setting_templates`, `chain_actions` |
| `migrations/009_hotel_chains_upgrade.php` | `hotels.chain_id`, `users.chain_id` (+ index, FK `ON DELETE SET NULL`); appends `chain_admin` to the `users.role` ENUM, keeping every existing value (idempotent, PDO only) |
| `core/Chains.php` | access rules, chain CRUD, dashboard / report aggregation, library + publish, broadcast, templates, action history |
| `core/boot.d/hotel_chains.php` | permissions `chains.manage` (platform_admin, reseller), `chain.view` (role gate only) |
| `admin/chain.php` | dashboard (totals, sortable table + cards, enter hotel) and comparison report (charts, CSV, previous period) |
| `admin/chain_content.php` | chain library (content + playlists), publish to hotels |
| `admin/chain_broadcast.php` | push now / schedule / emergency / stop; settings templates; history |
| `admin/platform_chains.php` | platform admin / reseller: chains, hotels, chain admins, chain access for super admins |
| `admin/ajax.d/chain.php` | `chain_overview?chain=ID` (dashboard auto-refresh) |
| `admin/partials/nav.d/55_hotel_chains.php` | "Hotel chain" menu section; "Hotel chains" in the platform / reseller menu |
| `assets/js/chain.js` | sortable tables, table/cards toggle, filter, select all, broadcast form switch, refresh |
| `lang/gu_chains.php` | Gujarati strings |
| `tests/Integration/ChainsTest.php` | tests (in `phpunit.xml`, before UpdaterTest) |

### Changes to shared files

1. `core/Auth.php`: `CHAIN_ROLES = ['chain_admin']`, `chain_admin` in `ALL_ROLES`; login refuses a chain admin
   without a valid chain; `resolveTenant()` lets a chain admin (and a super admin with `users.chain_id`) use an
   entered hotel only when `Chains::userCanEnter()`; `canAccessHotel()`, `hotelRole()` / `roleCan()` (chain admin
   = super_admin inside a hotel), `require()` redirects a chain admin without hotel to `chain.php`, `homePage()`
   → `chain.php`, `leaveHotel()` returns hotel users to their own hotel, `inEnteredHotel()` covers chain users,
   new `backPage()`; `enterHotel()` clears `$_SESSION['hc_back']`.
2. `admin/partials/header.php`: banner "Back to chain" (uses `Auth::backPage()`), section title `chain`.
3. `core/helpers.php`: `role_label('chain_admin')`.
4. `admin/platform_hotels.php`: hotel detail → "Hotel chain" card (`op=set_chain`).
5. `phpunit.xml`: `ChainsTest.php` added.

## Access model (security)

* `Chains::userChainIds()`: platform_admin → all chains; reseller → chains with its `reseller_id`;
  chain_admin → `users.chain_id`; super_admin → `users.chain_id` **only while its own hotel is in that chain**.
  Everyone else → none (pages answer 403/404, the menu is hidden).
* Every cross-hotel action takes explicit hotel ids and calls `Chains::assertHotels()`: each id must have
  `hotels.chain_id` = the chain (reseller: and its own `reseller_id`). One foreign id refuses the **whole**
  request (logged in `security`, 404; CLI: `TenantException`) before anything is written.
* Per-hotel work runs in `Tenant::run($hotelId)`, so the normal tenant safety net applies.
* Chain library rows / templates are looked up with `chain_id` (`Chains::contentItem()` etc. → 404).
* A reseller's chain can only contain that reseller's hotels; a hotel can be in one chain.
* Write actions skip suspended / expired hotels (status `skipped`) — the read-only rule.
* Chain admins entering a hotel follow the hotel's read-only rule (they are owner-side, not platform).

## Dashboard / report

Overview per hotel (in each hotel context): TVs online/offline, rooms, occupancy (in-house stays, if
`guest_stays` exists), today's plays / uptime / ad impressions (`Analytics::today()`), open orders
(`new/accepted/preparing`) and requests (`open`), feedback average (30 days), plan, expiry, unpaid / overdue
invoices. Report: `Analytics::byDay/byTv/tvSummary/guestServices/occupancy` per hotel for a range; totals
(uptime weighted by TV-hours, feedback weighted by count), optional previous period with change %, charts
(Chart.js via `assets/js/analytics.js`) and CSV.

## Publishing

`Chains::publish($chain, 'content'|'playlist', $id, $hotelIds)`: content is copied into `content_items` of each
hotel (`settings.chain_content_id` set); a media file (`uploads/chains/c{id}/media/…`) is copied to
`uploads/h{hotel}/media/YYYY/MM/chain{id}_….ext` (+ thumbnail). `chain_publications (hotel, type, source) →
local_id` makes re-publishing update the same row; a changed media file is copied again and the old copy
deleted. Playlists publish their items first and rebuild `playlist_items`. Deleting a chain item keeps the
hotel copies (links removed). Removing a hotel from the chain deletes its links.

## Broadcast / templates

`Chains::validateBroadcast()` once, then `Chains::broadcast()` per hotel: `push` (publish + `Broadcaster::pushNow`
to all rooms), `schedule` (once / window, hotel local time), `emergency`, `emergency_stop`. Templates store groups
`ticker`, `overlay` (hotel settings) and `branding` (hotel `brand_name/brand_color/brand_logo`, optionally the chain
logo). Every action is recorded in `chain_actions` with per-hotel results and in each hotel's activity log.

## Limitations

* Chain library editor supports image, video, announcement, YouTube, URL, stream, HTML and clock (no timetable /
  template-library items); hotels may edit their copies, a re-publish overwrites them.
* Chain broadcasts always target all rooms of a hotel (no per-room / group targeting across hotels).
* Chain branding is applied by pushing a template (not a live inheritance level in `Branding`).
* The dashboard computes live numbers per hotel on each load (fine for tens of hotels; no caching).
