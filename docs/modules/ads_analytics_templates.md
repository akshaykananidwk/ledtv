# Module: Ads & sponsors (#11), Analytics (#17), Templates & local guide (#12, #7)

HotelCast 2.0, V2_SPEC §3 and §4. #13 (AI generation) is **not** implemented; the template library replaces it.
Permissions: everything is **manager+** (`ads.manage`, `analytics.view`, `templates.manage`, registered in
`core/boot.d/ads_analytics_templates.php`). Plan modules: `ads`, `analytics`, `templates` (`Tenant::feature()`),
so menu items, pages, the dashboard widget and the TV extensions switch off when the plan does not include them.

## Files

| file | purpose |
|---|---|
| `migrations/004_ads_analytics_templates.sql` | tables `sponsors`, `ad_campaigns`, `ad_stats_daily`, `tv_usage_daily`; column `broadcast_logs.ad_campaign_id` + indexes |
| `core/boot.d/ads_analytics_templates.php` | registers the 4 tenant tables and the 3 permissions |
| `core/Ads.php` | sponsors / campaigns CRUD + validation, insertion rules, impression check, roll-up, sponsor report |
| `core/Analytics.php` | plays, uptime, hours ON, kWh, occupancy, guest services, usage sampling, dashboard numbers |
| `core/Templates.php` | template registry, field normalisation, renderer (festival + card layouts), save as content |
| `core/QrCode.php` | pure-PHP QR encoder (byte mode, ECC L/M, versions 1–20) → inline SVG |
| `core/Extensions/AdsExtension.php` | inserts ads into the Content object (`Ads::apply`) |
| `core/Extensions/GuideExtension.php` | adds the local guide to `guest_menu` |
| `core/Tasks/AnalyticsTask.php` | every 5 min: samples TVs into `tv_usage_daily` |
| `core/Tasks/AdsRollupTask.php` | hourly: rolls up the last 3 complete days of impressions into `ad_stats_daily` |
| `templates/*.php` (+ `.htaccess` deny) | 28 template definitions (12 festivals, 8 notices, 3 temple, 5 local guide) |
| `admin/ads.php`, `admin/sponsor_report.php`, `admin/analytics.php`, `admin/templates.php` | admin pages |
| `admin/ajax.d/tpl.php` | `tpl_render` (live preview) |
| `admin/partials/nav.d/30_ads_analytics_templates.php`, `admin/partials/dashboard.d/60_analytics_today.php` | menu, widget |
| `assets/vendor/chartjs/` (Chart.js 4.4.6 UMD + LICENSE, MIT), `assets/js/analytics.js` | charts |
| `lang/gu_ads.php`, `lang/hi_ads.php` | Gujarati UI strings, Hindi guest string ("Local guide") |
| `tests/Integration/AdsAnalyticsTest.php`, `tests/Integration/TemplatesTest.php` | tests |

### Changes to shared files (minimal, isolated)

1. **`core/DeviceManager.php` → `played()`**: when an item carries `ad_campaign_id`, it is stored in
   `broadcast_logs.ad_campaign_id` if `Ads::impressionCampaign()` confirms the campaign belongs to the device's
   hotel (other ids → stored as a normal play). `impressionCampaign()` never throws (safe before migration 004).
2. **`admin/content.php`**: (a) editing an `html` item whose settings contain a `template_id` redirects to
   `templates.php?action=edit&id=…` (`&raw=1` keeps the plain HTML editor; saving there drops the template link);
   (b) "From a template…" entry in the *Add content* dropdown.
3. **`phpunit.xml` is NOT changed** — please add `tests/Integration/AdsAnalyticsTest.php` and
   `tests/Integration/TemplatesTest.php` to the `integration` suite when merging.

## Ads (#11)

**Data**: `sponsors` (name, contact, phone, email, contract start/end, notes). `ad_campaigns` (sponsor, content item,
start/end date, optional daily window — overnight allowed, target all/rooms/groups/floors via
`Broadcaster::parseTarget`, `freq_items` N or NULL, `freq_minutes` M, `max_per_day` per TV, `priority`, `active|paused`).

**Insertion rules (`Ads::apply`, run by AdsExtension after ContentResolver decided the content):**
* Only in modes `assigned`, `group`, `default`, `scheduled` with `screen_on`; never in `emergency`, `off`,
  `suspended`, `empty` (welcome screen) or admin preview. The personal welcome card needs no rule: the TV app
  pauses the playlist while the card is shown, and GuestExtension (runs later) empties items when it switches a
  vacant room off. Extensions that switch a room to a non-ad mode later can call `Ads::strip($content)`.
* A campaign is live when active, today within its dates, inside its daily window, its content item is active,
  it targets the room, and the **busiest TV of the room** played it fewer than `max_per_day` times today (counted
  from `broadcast_logs`).
* **Playlist**: walk the items; per campaign count items (N) or seconds (M minutes, duration 0 counts as 10 s); when
  due insert the ad. At most 3 ads per break (by priority, then id); the rest wait for the next break. Every
  campaign appears at least once per playlist loop (playlist shorter than N → ad at the end).
* **Single item** (normally duration 0 = forever) → rotation `[main item for M min, ad1, ad2, …]` where M is the
  smallest `freq_minutes` of the eligible campaigns; the TV loops the list (playlist `null` = loop).
* Ad items = `ContentManager::toTvItem()` + `"ad_campaign_id"`; duration 0 becomes 15 s except videos (play to end).
  Content also gets `"ads": [campaign ids]`. All of it is part of the content hash (TV restarts the list when a
  campaign starts/ends/hits its cap — at most once per change).

**Impressions**: the TV reports `POST /api/device/played` items with `ad_campaign_id` (Android 2.0 `PlayedItem`).

**Sponsor report** (`sponsor_report.php?sponsor_id=` or `?campaign_id=`, `&from=&to=`): impressions, total screen
time, distinct rooms, days on air, per day (chart + table), per campaign, per room; CSV (`&csv=day|room|campaign`)
and a printable page (`&print=1`, "Print / Save as PDF"). Days before today come from `ad_stats_daily`, which is
(re)built from raw logs while they exist (report view + hourly task) and **kept after the log retention purges
`broadcast_logs`**, so billing data survives. Deleting a campaign deletes its roll-up.

## Analytics (#17)

`analytics.php?from=&to=` (max 1 year; quick ranges). KPIs, plays per day chart (plays, ad impressions, screen time),
top content, plays per content / room, TVs table + uptime chart, occupancy and guest services when the guests
module tables exist (`information_schema` check — absent → section hidden). CSV: `&csv=days|content|rooms|tvs|occupancy|requests`.

Methods (also explained on the page):
* **Plays / screen time**: `broadcast_logs` `event='played'` (TV reports each finished item). Limited to the log retention.
* **Uptime %** per TV = online seconds ÷ period seconds. Period starts at max(range start, registration) and ends at
  min(range end, now). Online time from `device_status_logs` transitions; the state at the start = last transition
  before the range (none → opposite of the first transition inside, offline if none). A TV still marked online but
  silent longer than `offline_after` counts only until its `last_ping`. Hotel uptime = Σ online ÷ Σ period.
* **Hours ON**: `AnalyticsTask` samples every 5 min — minutes since the previous sample (max 15) go to `online_min`
  if the TV is online and to `screen_on_min` if additionally `devices.screen_on = 1` from the last heartbeat (a TV
  without recent heartbeats counts as on). TVs without samples in the range fall back to online hours (`method=status`).
* **kWh** = hours ON × setting `tv_watts` (default 100, editable on the page) ÷ 1000.
* **Occupancy %** per day = rooms with a `guest_stays` row covering 18:00 of that day ÷ rooms.
* **Guest services**: `guest_orders` (count, delivered avg minutes `created_at→delivered_at`, value excl. cancelled),
  `guest_requests` by `type_name` (count, done, avg minutes), `guest_feedback` averages.

Dashboard widget: today's plays, uptime since midnight, ad impressions (manager+).

## Templates (#12) and local guide (#7)

28 templates, CSS/SVG/emoji only (no external images, fonts or scripts — works offline on the TV and avoids
copyright issues). Definition: `id, category (festival|notice|temple|guide), layout (festival|card), variant
(notice|big|board|tiles|timetable|sign|offer|wifi|qr|list), name{en,gu,hi}, icon, motif, emoji, fields[], defaults{en,gu,hi}, colors`.
Field types: `text, textarea, color, list (one row per line, columns "a | b | c"), time, url`. Values are normalised
(lengths, control chars, `clean_color`, http(s) URLs only) and every value is escaped with `e()` on output.

`render()` → full HTML document for 1920×1080 (vh units, Gujarati/Devanagari font stack). QR codes (Wi-Fi
`WIFI:T:WPA;S:…;P:…;;`, map / guide links) are generated server-side by `QrCode` as inline SVG; output is
bit-identical to qrcode-generator 1.4.4 (verified in tests).

Admin: gallery (scaled sandboxed `srcdoc` iframes, EN/GU/HI default text) → form + live preview (`tpl_render`) →
saved as content type `html` with settings `{template_id, fields, lang}`; editing from the content library opens the
template form again. **Local guide**: on the Templates page choose a content item (setting
`local_guide_content_id`, optional title `local_guide_title`); GuideExtension appends
`{id:"guide", type:"content", title, icon:"map", content:{ContentItem}}` to `guest_menu` (replacing an older
"guide" entry, keeping other modules' items); not in suspended / emergency / off modes or for inactive items.

## Tests

```
cd hotelcast
HC_TEST_DB_NAME=hotelcast_test_a php phpunit.phar -c phpunit.xml tests/Integration/AdsAnalyticsTest.php
HC_TEST_DB_NAME=hotelcast_test_a php phpunit.phar -c phpunit.xml tests/Integration/TemplatesTest.php
```
Cover: insertion by items / minutes, rotation, date range / window / paused / inactive content, targeting, never in
emergency/off/suspended/empty, daily cap per TV, priority + max per break, plan feature flag, impressions through the
device API (foreign / bogus ids ignored), report numbers + roll-up surviving retention, pages/CSV/print, CRUD and
validation, permissions (staff / reception 403), cross-hotel isolation (404, data unchanged, no foreign ads),
analytics math (uptime, hours ON, kWh, plays, occupancy, guest services), usage sampling; every template × language
renders valid self-contained HTML, XSS escaping, QR reference vectors, save → re-edit → TV item, local guide in
`guest_menu` (in-process and via `/api/device/command`).

## Limitations

* Content is resolved per **room**, so "max impressions per TV per day" uses the busiest TV of the room.
* "Every N items" restarts counting each playlist loop (a short playlist shows the ad once per loop).
* Hours ON depend on the 5-minute sampler (cron or lazy ticks from TV polls); gaps > 15 min are not back-filled.
  Older history only has online/offline data.
* Plays/screen-time analytics follow the log retention (`log_retention_days`); only ad stats are rolled up.
* Emoji rendering depends on the TV's emoji font; template text is static (no live clock).
