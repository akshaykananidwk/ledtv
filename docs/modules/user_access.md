# Module: Per-user TV access (2.2) and SaaS roles

The platform is a SaaS. Each customer is a hotel (tenant). Roles from top to bottom:

| Role key (DB, unchanged) | Shown as | Scope |
|---|---|---|
| `platform_admin` | **Super Admin (Platform)** | Platform owner: hotels (customers), plans, invoices, resellers, licenses, platform settings. Can enter any hotel and act as its Admin there. |
| `reseller` | **Reseller** | Hotels of one reseller. |
| `super_admin` | **Admin** | One customer (hotel): everything in it, including users, settings and billing. Always all TVs. |
| `manager` / `staff` / `reception` | **Manager** / **Staff** / **Reception** | One hotel. Optionally limited to some TVs (this module). |
| `chain_admin` | Chain Admin | Hotel chains feature. Off by default, see below. |

Only the labels changed (`role_label()` in `core/helpers.php`, `lang/gu_access.php`, `lang/hi_access.php`).
The role values in `users.role`, `Auth::HOTEL_ROLES`, the permissions and the tests that use role keys are unchanged.

## Per-user TV access

The Admin opens **Users → Add / Edit** and picks, for a Manager, Staff or Reception user:

* **All TVs** (default): no rows in `user_access`, the same behaviour as before 2.2.
* **Only these TVs**: tick groups and/or rooms. The list can be searched and shows each group's room count.
  At least one group or room is required. Group ids and room ids are checked with `Tenant::find()`, so an id
  of another hotel is refused with 404 (logged). Unknown ids are dropped.

The users list has a **TVs** column ("All TVs" or e.g. "3 rooms, 1 group"). A change is logged in the activity
log (`user_access`, "all TVs → groups 3; rooms 12"). Deleting a user deletes their `user_access` rows.
The Admin role is never limited: saving a user as Admin clears the rows.

### Rules (core/Access.php)

* Only `manager`, `staff` and `reception` users with rows are limited. Every other role is unlimited, and so are
  all requests without a logged-in user (cron, scheduler, TV device API, PMS API, guest portal).
* Allowed rooms = assigned rooms + the current members of assigned groups (a room added to the group later is
  included automatically). Rows of rooms that were deleted keep the user limited. They never make the user
  unlimited.
* A limited user may target a **group** only when that group is assigned. A **floor** is allowed only when every
  room on the floor is allowed. **All rooms** (`target_type = all`) is never allowed.
* An existing broadcast row (schedule, power schedule, emergency, ad campaign) may be changed only when its whole
  target is allowed (`Access::canBroadcast`).
* A refusal answers **403** (JSON `{"error":{"code":"FORBIDDEN"}}` for AJAX, the "Access denied" page otherwise)
  and is logged in `logs/security` ("TV access denied (user_access)"). Ids of another hotel still answer 404.

### Where it is enforced

| Place | Limited users |
|---|---|
| `Broadcaster::parseTarget()` (all target forms and AJAX) | 403 for `all`, other rooms, unassigned groups, floors that are not fully theirs |
| `Broadcaster::pushNow / sendCommand / emergencyStart` | same check again (rooms bulk actions, TV controls, APK push, chain code) |
| `Broadcaster::emergencyStop` | one id: 403 unless the whole target is theirs. "Stop all": stops only those emergencies |
| `Broadcaster::setPowerScheduleEnabled` | 403 for power schedules of other TVs |
| `admin/partials/common.php` | `hc_rooms()`, `hc_groups()`, `hc_floors()`, `hc_devices_by_room()`, `hc_dashboard_stats()`, `hc_room_status_list()` return only their rooms / TVs. `target_picker()` hides "All rooms" (and floors / groups when none are allowed). `hc_preview_object()` returns 403 for another room. |
| Dashboard (`index.php`, `ajax.php` `dashboard_stats` / `room_status`, header, `dashboard.d/40_guests.php`) | counts, room grid, TV counts and occupancy of their rooms. Recent activity shows only their own actions. "Refresh all TVs" is hidden. An emergency that reaches one of their TVs is shown; the Stop button appears only when they may stop it. |
| `rooms.php` | list, filters, revoked TVs, TV details, edit only for their rooms. Cannot add (single, bulk, QR) or delete rooms. The registration key is hidden. Bulk actions only on their rooms. Room edit changes only the membership of their assigned groups; other memberships are kept. |
| `groups.php` (manager) | only assigned groups. Cannot create or delete groups or run "auto floors". Members can be chosen only from their rooms. |
| `broadcast.php` | push / schedule / command / emergency only to their TVs. History shows only broadcasts to their TVs. |
| `schedule.php`, `ajax.php` `schedule_events` | list / calendar / edit / cancel / delete only for schedules whose target is theirs |
| `power.php` | power now, add / pause / delete schedules only for their TVs. The hotel-wide "How should OFF work" setting is hidden (403). |
| `tv_controls.php` | commands only to their TVs. Volume rules and the guest menu are hotel-wide, so they are hidden (403). |
| `support.php`, `ajax.d/support.php`, `DeviceSupport::request/requestStatus` | TV list, TV page, screenshots / logs only for TVs in their rooms |
| `setup_file.php`, `claim.php` (QR setup) | only their rooms. No "Create new room" in the QR setup. |
| `guests.php` | board and history of their rooms. Check-in, check-out, edit, move (both rooms), welcome, new guest link only for their rooms. |
| `orders.php`, `ajax.d/guests.php`, `GuestServices::board/alertCounts/newSince/setOrderStatus/setRequestStatus` | orders and requests of their rooms only (KOT print included) |
| `logs.php` | TV status and "played" logs of their rooms, broadcasts to their TVs, their own activity |
| `analytics.php` | "Plays per room" and TV uptime tables show only their TVs (per-day and per-content totals stay hotel-wide) |
| `apk.php` | TV version list of their TVs; pushing an APK only to their TVs |
| `ads.php` | campaign targets only their TVs. Editing, pausing or deleting a campaign of other TVs is refused. |

Unchanged on purpose: the content library and playlists stay shared (a limited manager may create and edit
content and playlists). Assigning content goes through rooms / groups / broadcasts and is therefore limited.
Guest feedback, settings, users and billing keep their normal role permissions (Admin / Manager). The TV-side
device API is not affected.

### Emergency decision

Emergencies already support targets (rooms / groups / floors / all), so a limited user may **start** an
emergency on their own TVs (never "all rooms"). They may **stop** only emergencies whose whole target is
theirs. A hotel-wide emergency started by an Admin still shows in their dashboard banner (it is on their TVs),
but without a Stop button. "Stop all" stops only the emergencies they own; the Admin's keep running.

## Hotel chains switch

Hotel chains (`docs/modules/hotel_chains.md`) are off by default. They are switched on in **Platform settings →
Features** (platform setting `feature_chains`, `Settings::setPlatform('feature_chains', '1')`, read with
`Chains::enabled()`).

While it is off:

* the chain menus (`nav.d/55_hotel_chains.php`) and the chain card on the platform hotel page are hidden;
* `chain.php`, `chain_content.php`, `chain_broadcast.php` (via `Chains::page()`), `platform_chains.php`, the
  `chain_overview` AJAX action and `platform_hotels.php op=set_chain` answer 404;
* chain admins cannot be created (only `platform_chains.php` creates them), and chain admins / super admins with
  chain access cannot enter other hotels (`Chains::userCanEnter()` is false). A chain admin who logs in lands on
  the profile page;
* all chain data stays in the database and is back when the switch is turned on.

## Files

| file | purpose |
|---|---|
| `migrations/010_tickers_access.sql` | table `user_access` (hotel_id, user_id, target_type group/room, target_id) |
| `core/Access.php` | rules, checks (`can*` / `require*` / `canTargetList` / `canBroadcast` / `touchesBroadcast` / `requireUnrestricted`), `roomSql()` for list queries, `setForUser()` / `forUser()`, `deny()` (403) |
| `core/boot.d/tickers_access.php` | registers `user_access` as a tenant table |
| `admin/users.php` | "Which TVs can this user control?", TVs column, "Who can do what" box |
| `lang/gu_access.php`, `lang/hi_access.php` | Gujarati / Hindi strings |
| `tests/Integration/UserAccessTest.php` | lists, 403 matrix (data unchanged), allowed actions, users form (CSRF, validation, foreign ids), chains switch, crawl of every admin page as limited staff / manager without PHP warnings |
