# Module: Ticker bar (2.2) — scrolling text per TV, group or all TVs

A ticker is a scrolling text bar at the bottom (or top) of the TV. Its text can be the same for all TVs,
different per group, or different per room. Colours, speed, font size, bar height and position are set
per ticker. By default the TV **does not cover the video**: the content area shrinks and the bar sits
below (or above) it (`reserve_space`).

## Files

| file | purpose |
|---|---|
| `migrations/010_tickers_access.sql` | table `tickers` (and `user_access`, see core/Access.php) |
| `migrations/010_tickers_access_upgrade.php` | converts each hotel's legacy `ticker_text` setting into a `tickers` row, then clears the setting (idempotent, one transaction per hotel, PDO only) |
| `core/Tickers.php` | validation, CRUD helpers, state badges, resolution (`forRoom`, `forTarget`, `overview`, `resolve`, `legacy`) |
| `core/Extensions/TickerExtension.php` | ContentExtension (`PRIORITY = 200`, runs last): sets `content.overlay.ticker` |
| `core/boot.d/tickers_access.php` | tenant table `tickers`, permission `tickers.manage` (staff and above) |
| `admin/tickers.php` | list with state badges, create / edit / delete / switch on-off, live preview, "what each TV shows now" overview |
| `admin/partials/nav.d/12_tickers.php` | "Ticker bar" menu entry right after Broadcast (`['after' => 'broadcast']`) |
| `lang/gu_tickers.php`, `lang/hi_tickers.php` | Gujarati / Hindi strings |
| `tests/Integration/TickersTest.php` | tests (in `phpunit.xml`) |

### Changes to shared files

1. `core/ContentResolver.php`: `overlay()` always returns `ticker: null`; the extension fills it, so the
   ticker is never emitted twice.
2. `admin/settings.php`: the "Scrolling ticker" card is now a short note with a link to the Ticker page;
   saving the Display tab no longer writes the `ticker_*` keys (so a value pushed by a chain template is kept).
3. `admin/partials/common.php`: `hc_preview_object()` adds the hotel-wide ticker (`Tickers::forTarget('all')`)
   to content / playlist previews; `hc_nav_sections()` supports an optional `['after' => key]` flag.
4. `admin/partials/tv_simulator.php`: renders `position`, `height`, `font_size`, `messages` and
   `reserve_space` (the stage's content layers shrink via `--tk-top` / `--tk-bottom`).
5. `core/Demo.php`: demo data creates a hotel-wide row in `tickers` instead of the `ticker_text` setting.

## TV contract — `content.overlay.ticker`

`null` when no ticker is active for the room. Otherwise:

```json
{
  "text": "Room 101: ચેક-આઉટ 10:00 AM   ✦   મંગળા આરતી સવારે 6:30",
  "messages": ["Room 101: ચેક-આઉટ 10:00 AM", "મંગળા આરતી સવારે 6:30"],
  "speed": 6,
  "bg_color": "#7B1FA2",
  "text_color": "#FFFFFF",
  "font_size": 30,
  "height": 64,
  "position": "bottom",
  "reserve_space": true
}
```

| field | type | meaning |
|---|---|---|
| `text` | string | all messages joined with `"   ✦   "` (2.1 apps only read `text`, `speed` and the colours) |
| `messages` | string[] | the active messages in display order (whitespace / new lines collapsed to one space) |
| `speed` | int 1–10 | scroll speed; the TV app and the admin previews use **30 + 25 × speed dp per second** |
| `bg_color`, `text_color` | `#RRGGBB` | upper-case hex |
| `font_size` | int 14–72 | sp, default 26 |
| `height` | int 32–200 | dp, default 56 (the TV may grow the bar when the font needs more room) |
| `position` | `"bottom"` \| `"top"` | |
| `reserve_space` | bool | `true`: the content area shrinks by the bar height, the bar never covers the video; `false`: the bar is drawn over the content |

The ticker is `null` in modes `suspended`, `off` (incl. vacant rooms switched off by the guests module and
power schedules) and `emergency` (the emergency screen is full screen). It is kept in all other modes,
including `empty` (welcome screen), `assigned`, `group`, `default` and `scheduled`.

The ticker is part of the content `hash` (computed after all extensions), so any change makes the TV
reload. Saving / deleting / switching a ticker bumps `content_version` (content cache cleared). The content
object is cached for at most 15 seconds and the cache key changes every minute, so date ranges and daily
windows switch on time without any edit (within ~15 s).

## Resolution for a room

1. **Active tickers**: `is_active = 1`, inside `starts_at` / `ends_at` (end exclusive), inside the daily
   window `time_from`–`time_to` (end exclusive; `22:00`–`06:00` runs overnight) and, if `days` is set,
   on one of those days. The window / day check is `ContentResolver::windowActive()` — the same rule as
   broadcast time windows (on a listed day the early part of an overnight window counts too, and after
   midnight the window still runs when the previous day is listed).
2. **Matching**: target `all`, a `group` the room is a member of, or the `room` itself.
3. **Legacy setting**: a non-empty hotel setting `ticker_text` (Settings page ≤ 2.1, chain templates) is an
   extra `all` ticker with priority −1000 and the `ticker_bg_color` / `ticker_text_color` / `ticker_speed`
   settings (font 26, height 56, bottom, reserve_space true).
4. **Specificity**: room 3 > group 2 > all 1. If any matching ticker has `override_lower = 1`, the matching
   tickers that are less specific than the *most specific* override ticker are dropped (a group override
   hides the `all` tickers on that group's TVs but keeps room tickers; an `all` override hides nothing).
5. **Order**: specificity desc, priority desc, id asc. The style (colours, speed, font size, height,
   position, reserve_space) comes from the first ticker.

`days` is stored like `broadcast_commands.repeat_days`: comma list of ISO weekdays **1 = Monday … 7 = Sunday**.
`0` is accepted as Sunday on input (normalised to 7) and when reading.

Other helpers: `Tickers::forTarget('all'|'group'|'room', $id)` (previews: "all" = only hotel-wide tickers,
"group" = a TV that is only in that group), `Tickers::overview($rooms)` (many rooms with two queries),
`Tickers::state($row)` → `live` / `scheduled` (not started yet, or outside its window / days) / `expired` /
`paused` (switched off).

## Admin usage (Admin → Ticker bar, staff and above)

* **New ticker**: message (Gujarati / Hindi / English, up to 1000 characters), optional name, target
  (All TVs / a group / a room), colour presets or colour pickers, speed slider, font size (sp), bar height
  (dp), position (bottom / top), *Do not cover the video (video shrinks)* (on by default), *Hide the less
  specific tickers on these TVs* (override), priority (−999…999), date range, daily time window and days of
  the week, switched on/off. The live preview strip scrolls the text with the chosen style.
* **List**: state badge (Live / Scheduled / Expired / Off), target name, schedule summary, look, priority;
  edit, switch on/off, delete.
* **What each TV shows now**: the resolved ticker text per room (first 300 rooms).
* A legacy `ticker_text` value (e.g. pushed by a chain template) is shown in a note above the list.

### Access / tenancy

* Another hotel's ticker, room or group id → 404 (`Tenant::find` / `Tenant::deny`).
* A user with TV restrictions (`user_access`, core/Access.php) sees and edits only tickers whose target they
  may control (`Access::canTarget`): never `all`, only assigned groups and their rooms. Choosing "All TVs" is a
  validation error; another room / group of the same hotel → 403 (`Access::deny`). The overview lists only
  their rooms. Users without restrictions see everything.

## Upgrade

`010_tickers_access_upgrade.php` runs once after `010_tickers_access.sql`: for every hotel with a non-empty
`ticker_text` it inserts a `tickers` row (target all, the same colours and speed, font 26, height 56, bottom,
reserve_space on), sets `ticker_text` to `''` and bumps `content_version` — in one transaction per hotel, so
an interrupted run can simply be re-run. The `ticker_*` keys keep working as the legacy fallback (chain
templates still write them).

## Video scale next to the bar (2.2.2)

`video_scale` in the ticker object (admin: *Video in the smaller area*). It only applies while `reserve_space` is true:

| Value | TV | Look |
|---|---|---|
| `fill` (default) | ExoPlayer `RESIZE_MODE_FILL`, images `FIT_XY` | whole area, no black side bars, nothing cut, slightly squeezed vertically |
| `fit` | `RESIZE_MODE_FIT`, `FIT_CENTER` | shape kept, black bars at the sides |
| `zoom` | `RESIZE_MODE_ZOOM`, `CENTER_CROP` | shape kept, fills, edges cut |

Older apps ignore the field (they behave like `fit`).
