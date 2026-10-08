# Module: Scheduling (2.4) — calendar, content approval, expiry dates, playlist dayparting, holidays

Five features that decide **when** content is on air:

| # | feature | where |
|---|---|---|
| 31 | Calendar of everything scheduled, drag & drop, add by selecting a time range | Admin → Calendar (`admin/calendar.php`) |
| 32 | Content approval workflow (hotel setting "Require approval") | Admin → Approvals (`admin/approvals.php`) |
| 33 | Content start / expiry dates (`valid_from` / `valid_to`) | content form, content list badges + filter |
| 34 | Dayparting inside playlists (time window + weekdays per item) | playlist editor (clock button per item) |
| 35 | Holiday calendar (TVs off / show content / marker) + Indian public holidays 2026–2027 | Admin → Holidays (`admin/holidays.php`) |

## Files

| file | purpose |
|---|---|
| `migrations/022_scheduling.sql` | `content_items`: `approval_status` (default `approved`), `approval_note`, `submitted_by`, `reviewed_by`, `reviewed_at`, `valid_from`, `valid_to`, `expiry_warned_for`; `playlist_items`: `daypart_from`, `daypart_to`, `daypart_days`; tables `content_revisions`, `holidays` |
| `core/boot.d/scheduling.php` | tenant tables, permissions `content.approve` (manager), `content.submit` (reception), `holidays.manage` (manager), web-push alert types `approvals`, `content_expiry` |
| `core/ContentRules.php` | what may play now: `playable()` (active + approved + validity window), `validity()`, `daypartActive()`, `filterPlaylistRows()`, form helpers, badges. `ContentRules::$now` is a test clock |
| `core/Approvals.php` | approval workflow: `save()` (used by the content form), `submit()`, `approve()`, `reject()`, revisions, queue (`entries()`), counts, badges |
| `core/Holidays.php` | validation / CRUD, `forRoom()` (used by the resolver), starter list `indianHolidays()`, `importIndian()` |
| `core/Calendar.php` | calendar events (schedules, power, content dates, holidays, device schedules), `move()`, `add()` |
| `core/Tasks/ContentExpiryTask.php` | hourly: "content expires soon" web push / email (setting `content_expiry_warn_days`, default 3, 0 = off) |
| `admin/calendar.php`, `admin/ajax.d/calendar.php` | calendar page (FullCalendar, vendored) and its AJAX actions `calendar_events`, `calendar_move`, `calendar_add` |
| `admin/approvals.php` | queue with preview / approve / reject-with-reason, "My submissions", the "Require approval" switch (hotel admins), TV preview of the waiting version (`?action=preview&id=`) |
| `admin/holidays.php` | list per year + month calendar, add / edit / pause / delete, import of the starter list |
| `admin/partials/nav.d/22_scheduling.php` | menu: Calendar (after Schedule), Holidays (after TV Power), Approvals (after Content Library, with a count) |
| `lang/gu_scheduling.php`, `lang/hi_scheduling.php` | Gujarati / Hindi |
| `tests/Integration/Apps/SchedulingTest.php` | tests |

### Changes to shared files (small, marked "2.4")

1. `core/ContentManager.php`: `playlistItems()` also selects the daypart columns and, for `$activeOnly`
   (TVs, layouts, previews), filters with `ContentRules::filterPlaylistRows()`; `deleteItem()` discards a
   waiting revision (and its files).
2. `core/ContentResolver.php`: single items use `ContentRules::playable()`; holiday steps 2b ("TVs off")
   and 3b ("show content"); doc comment with the new priority.
3. `core/Layouts.php`: a zone's single item uses `ContentRules::playable()` (playlist zones go through
   `playlistItems()`).
4. `core/Broadcaster.php`: `pushNow()` and `validateSchedule()` refuse content that is not approved;
   new `updateSchedule()` (used by the calendar; same columns as the edit form of `admin/schedule.php`).
5. `admin/partials/common.php`: `parse_source()` refuses non-approved content (room, group, default,
   broadcast, schedule forms); `source_select()` shows it disabled "(waiting for approval)".
6. `admin/content.php`: staff / reception may add & edit while approval is on (save goes through
   `Approvals::save()`), "Send for approval" / "Save as draft", start / expiry fields, approval box,
   status badges and the status filter (Live / Scheduled / Expired / Waiting / Draft / Rejected).
7. `admin/playlists.php`: daypart controls per item (clock button) and saving them.

## What plays — priority (ContentResolver::build)

emergency → hotel suspended → room switched off → scheduled power-off window → **holiday "TVs off"** →
time-window broadcast → **holiday "show content"** → room assignment → group → hotel default → welcome screen.

Everywhere (room, group, default, broadcasts, holiday content, playlists, layout zones) an item is skipped
unless it is active, **approved** and inside its **validity window**; inside playlists also inside its
**daypart**. A playlist with no playable item counts as empty, so the next level (group, default …) is used
— the same fallback as an empty playlist. Emergency messages are never affected.

All dates and times are **hotel time** (the hotel's time zone setting; PHP's default time zone is set per
hotel by `Tenant::set()`). `valid_to` and the end of a daypart are exclusive; holiday dates are inclusive.

### Caching / when TVs switch

`ContentResolver::forRoom()` caches per hotel, content version **and minute** (key ends with
`floor(time()/60)`, TTL 15 s). Every content object carries `hash = sha1(content)`; when the active set
changes (a daypart starts, an item expires, a holiday begins) the items change, so the hash changes and
the TV reloads within about one minute (its next poll after the minute key rolls over). Admin changes bump
`content_version` (immediate).

## #31 Calendar

* Sources: content schedules (`broadcast_commands` SHOW_CONTENT once / window — repeating windows expand
  into occurrences), TV power schedules (SCREEN_OFF windows), content start / expiry dates, holidays and,
  when the device-schedules module is installed (`device_schedules` table), its timed actions (read-only).
* Colours by type (legend on the page, `Calendar::COLORS`).
* FullCalendar runs with `timeZone: 'UTC'` and the server sends wall-clock strings without offset, so the
  calendar always shows **hotel time**, whatever the browser's time zone. Dates coming back (drag, select)
  are read the same way (`Calendar::parseLocal`, a trailing `Z` / offset is ignored).
* Drag & drop / resize: one-off schedules (`once`: new start, must be in the future; plain window: new
  start / end) and holidays (all-day). Validation goes through `Broadcaster::validateSchedule()` (targets,
  content, approval, end after start), saving through `Broadcaster::updateSchedule()`.
* **Repeating schedules move as a series** (FullCalendar `groupId`): the page asks for confirmation; the
  daily times follow the dragged occurrence and the weekdays shift by the number of days moved
  (Sunday → Monday wraps). **"This occurrence only" is not supported** — the resolver has no exception
  dates; the page says to add a separate schedule for a single day instead (conscious simplification).
* Power schedules, content dates and device schedules are read-only in the calendar (click opens their page).
* Add: select a time range (or "Add schedule") → choose content / playlist, TVs, and either "show between
  start and end" (`window`) or "switch at start time (stays)" (`once`) → `Broadcaster::validateSchedule()`
  + `Broadcaster::schedule()`.
* Permissions: `schedule.manage`. Users limited to some TVs see hotel-wide entries and entries that
  target only their TVs, and may move only the latter (403 otherwise). Another hotel's ids → 404.

## #32 Approval workflow

* Hotel setting `content_require_approval` (default off), switched on Admin → Approvals by hotel admins
  (`settings.manage`, unrestricted users).
* Statuses: `draft` → `pending` → `approved` / `rejected`. Existing content is `approved` (column default,
  migration 022). Code paths that insert content without knowing about approvals (designer, templates,
  apps, chains, marketplace — all manager-only) therefore produce approved content.
* While on: staff and reception (`content.submit`) can add / edit content in the Content Library. A new
  item is `pending` ("Send for approval") or `draft`; managers (`content.approve`) get a web push
  (alert type "Content waiting for approval") and see it in the queue. **Managers' own saves are approved
  directly.** Staff still cannot delete, duplicate or (de)activate items, use the designer, templates or apps.
* **Editing approved content keeps the approved version on air**: the staff edit is stored as a revision
  (`content_revisions`, one open revision per item, also new uploaded files) and the item stays
  `approved`. Approve → the revision replaces the item (old files are deleted). Reject (reason required) →
  the item is unchanged; the author sees the reason. A manager saving the item discards a waiting revision.
* Not approved content is skipped by the resolver everywhere (see above), cannot be pushed, scheduled,
  used for a holiday or chosen in room / group / default pickers (`parse_source`, `pushNow`,
  `validateSchedule`). It can be added to a playlist, where it is skipped until approved.
* Notification of the author: activity log entries `content_approved` / `content_rejected` (with
  `@username`) and the Approvals menu entry shows the number of their rejected items; the queue shows a
  count for managers. (Web push goes to managers; a per-user push to the author is not available in
  `StaffAlerts`.)
* Setting off again: new saves are approved; items still waiting stay in the queue until a manager decides.

## #33 Start / expiry dates

`valid_from` / `valid_to` (DATETIME, hotel time, optional) on the content form. Badges in the content list:
**Scheduled** (starts later), **Live**, **Expired**; filter "Any status / Live / Scheduled / Expired /
Waiting for approval / Draft / Rejected". The calendar shows start and expiry dates.
`ContentExpiryTask` warns managers N days before `valid_to` (once per item and date; re-armed when
`valid_to` changes).

## #34 Dayparting in playlists

Per playlist item: optional daily window `daypart_from`–`daypart_to` (overnight allowed, e.g. 22:00–02:00;
after midnight it belongs to the night it started) and weekdays `daypart_days` (1 = Mon … 7 = Sun; all
seven = no limit). Both times or none. The editor shows a clock button per item and a summary badge.

## #35 Holidays

Table `holidays`: name, `start_date` … `end_date` (inclusive), action `tv_off` | `show_content`
(content or playlist) | `none` (marker), target all / rooms / groups / floors (same format as broadcasts),
active switch. When several holidays match a room, the most specific target wins (rooms, then groups /
floors, then all). "TVs off" gives the same content object as a scheduled power-off (`mode: off`,
`screen_on: false`) with `off_reason: "holiday"` and `holiday_id`; "show content" gives `mode: scheduled`
with `holiday_id`. Saving / deleting a holiday that covers today refreshes the targeted TVs.

"Import Indian public holidays 2026–2027" adds a starter list (national holidays and major festivals,
hotel-wide, action "marker only" or "TVs off"). **Lunar festival dates are approximate — verify them
against the official list of your state.** Entries already present (same date and name) are skipped.

## Tests (`tests/Integration/Apps/SchedulingTest.php`)

migration of existing content to approved (simulated 2.3 schema, idempotent re-run); validity window incl.
time zone edges (same instant expired in Asia/Kolkata, live in America/New_York) and the expiry warning;
dayparting incl. overnight, weekdays, hash change and fallback; approval rules over HTTP (staff item not on
TV until approved, edit → revision while the old version plays, reject with reason, manager auto-approve,
setting off = old behaviour, tenancy); holiday off / show content / specificity / window / power-off /
emergency precedence and the management page (import, tenancy, restricted users); calendar AJAX (events of
every type, move with CSRF and validation, series move, holiday move, add, tenancy, restricted users);
"no PHP warnings" on the new pages for all roles in English and Gujarati; translation coverage.

## Open points

* "This occurrence only" for repeating schedules (needs exception dates in `broadcast_commands`).
* Display apps that reference content items themselves (guide, notice board timetables, albums) do not
  check approval / validity.
* A per-user web push to the author of approved / rejected content (StaffAlerts sends by permission only).

## ગુજરાતી સારાંશ

* **કૅલેન્ડર** (Admin → કૅલેન્ડર): કન્ટેન્ટ શેડ્યૂલ, ટીવી પાવર શેડ્યૂલ, કન્ટેન્ટ શરૂ / પૂરું થવાની તારીખ,
  રજાઓ અને ડિવાઇસ શેડ્યૂલ — બધું મહિના / અઠવાડિયા / દિવસના દૃશ્યમાં, પ્રકાર મુજબ રંગ સાથે. એન્ટ્રી ખેંચીને
  સમય બદલો, સમય પસંદ કરીને નવું શેડ્યૂલ ઉમેરો. પુનરાવર્તિત શેડ્યૂલ આખી શ્રેણી તરીકે ખસે છે ("ફક્ત આ
  દિવસ" નથી — તે દિવસ માટે અલગ શેડ્યૂલ ઉમેરો). સમય હંમેશાં હોટેલનો સમય છે.
* **મંજૂરી** (Admin → મંજૂરીઓ): "મંજૂરી જરૂરી" ચાલુ હોય ત્યારે સ્ટાફ / રિસેપ્શનનું કન્ટેન્ટ મેનેજર મંજૂર કરે
  પછી જ ટીવી પર આવે છે. મંજૂર કન્ટેન્ટમાં સ્ટાફનો ફેરફાર મંજૂર થાય ત્યાં સુધી જૂની મંજૂર આવૃત્તિ ચાલુ રહે છે.
  નામંજૂર કરતી વખતે કારણ લખવું પડે છે, જે લેખકને દેખાય છે. મેનેજરનું પોતાનું કન્ટેન્ટ સીધું મંજૂર થાય છે.
* **શરૂ / પૂરું થવાની તારીખ**: કન્ટેન્ટ ફોર્મમાં "ક્યારથી" / "ક્યાં સુધી" — તે બહાર આઇટમ રૂમ, ગ્રુપ, પ્લેલિસ્ટ
  અને લેઆઉટ બધે છોડી દેવાય છે. યાદીમાં "શેડ્યૂલ / લાઇવ / પૂરું" બેજ અને ફિલ્ટર. પૂરું થવાના થોડા દિવસ પહેલાં
  મેનેજરને સૂચના.
* **પ્લેલિસ્ટમાં સમયગાળો**: દરેક આઇટમ માટે ઘડિયાળ બટન — કયા સમયથી કયા સમય સુધી (રાતભર પણ) અને કયા
  દિવસોએ. કંઈ ચાલુ ન હોય તો પ્લેલિસ્ટ ખાલી ગણાય અને ગ્રુપ / ડિફોલ્ટ કન્ટેન્ટ દેખાય. ફેરફાર લગભગ એક
  મિનિટમાં ટીવી પર દેખાય છે.
* **રજાઓ** (Admin → રજાઓ): તારીખ, નામ, અને તે દિવસે "ટીવી બંધ" (શેડ્યૂલ કરેલા પાવર-ઓફની જેમ),
  "કન્ટેન્ટ બતાવો" અથવા "ફક્ત નિશાની" — બધા ટીવી, ગ્રુપ કે રૂમ માટે. ઇમરજન્સી સંદેશ હંમેશાં દેખાય છે.
  "ભારતીય જાહેર રજાઓ 2026–2027 આયાત કરો" થી શરૂઆતની યાદી — તહેવારોની તારીખો જાતે ચકાસો.
