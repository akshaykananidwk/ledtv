# Krishna Cloud TV Management — digital signage & TV management SaaS

> Product name: **Krishna Cloud TV Management** (until 2.4: "Krishna Cloud LED TV"). "HotelCast" remains the
> internal code name (folder `hotelcast/`, Android package `com.hotelcast.tv`, database tables `hotels` / `rooms`,
> API fields `hotel_id` / `room_number`). Changing those would break updates of TVs that are already installed —
> see [docs/modules/terminology.md](docs/modules/terminology.md) for the UI term ↔ database / API name mapping.

Control every Android TV, Smart-TV browser and display of a business from one browser tab — temples, shops,
showrooms, restaurants, hospitals and clinics, schools, offices, factories and hotels. When a TV is switched on,
the app opens full-screen and shows whatever the admin has pushed — **live darshan**, **timetables**, menus,
**announcements**, **offers**, **videos**, slideshows, token displays, web pages — screen by screen, per area /
floor, or on every screen at once. One server hosts many **customers** (multi-tenant SaaS) with plans, invoices,
resellers and white-label branding; each customer manages its own **screens**.

*Tamara business na darek TV / screen ne admin panel thi remotely control karo — content push karo,
screen-wise schedule karo, emergency message moklo, TV reboot/update karo.*

```
 Admin Panel (PC / tablet / phone)
        │  HTTPS
        ▼
 PHP 8 REST API  ──►  MySQL / MariaDB
        ▲
        │  poll every 5–10 s (hash-based, content only sent when it changes)
        │
 Android TV app / web player (each screen)  ── offline cache: keeps showing the last content without internet
```

| Part | Folder | Tech |
|------|--------|------|
| Server: admin panel + REST API + installer + auto-updater | [`hotelcast/`](hotelcast) | PHP 8.1+, MySQL 8 / MariaDB 10.6, Apache, Bootstrap 5, vanilla JS — no Composer, no Node |
| Android TV app | [`android/`](android) | Kotlin, ExoPlayer 2.19, Retrofit, OkHttp, Glide, WorkManager — min SDK 21, target 34 |
| Documentation | [`docs/`](docs) | Install guide, TV setup, admin guide, API, developer guide (tenancy + extension points), security, test report |

## What's new in 2.6 — three separate panels, Super Admin console, customer archive / delete

Server only (the TV app stays 2.5.0). Design notes and research: [docs/modules/panels.md](docs/modules/panels.md); owner's specification: [docs/SPEC_SAAS.md](docs/SPEC_SAAS.md).

| Feature | Where |
|---|---|
| **Three panels with their own look**: the **Super Admin console** (dark indigo, "Super Admin" badge, platform menu only — no customer modules), the **Reseller panel** (teal, own overview / customers / screens / plans / invoices / support) and the **Customer workspace** (the customer's branding; never a platform or reseller item). `core/Panel.php` decides the panel from the role; permissions stay server-side | every admin page |
| **Super Admin dashboard**: KPI tiles (customers, active / suspended / expiring, TVs online / offline / outdated / health warnings, users, open sign-ups, unpaid invoices, revenue, resellers, pool), "Needs attention" alerts, **device errors with severity**, trends (customer growth, TVs by status, customers by plan), recent activity across customers, platform switches (online sign-up, platform registration) | Super Admin → Dashboard |
| **Global search** in the header: customers, screens / TVs (name, device ID, IP), users (e-mail, username), resellers — limited to what the user may see; customer users get 403 | header search box · `platform_search.php` |
| **Customer 360**: Summary · Plan & features (**switch every feature on / off**, limits and expiry inline) · Screens (commands, transfer, update, revoke, screen on / off) · Users (create, role, reset password, enable / disable) · Content snapshot · Billing (invoices, licenses, extend validity) · Activity · Settings (status switch, registration key, chain, branding, archive, delete). Everything routine without opening the customer | Super Admin → Customers → Manage |
| **One-click switches** with CSRF and audit log: customer active / suspended, feature per customer, user active, screen on / off, plan active, reseller active, sign-up open, platform registration | console pages · `ajax.php?action=platform_toggle` |
| **"Open customer workspace"** (impersonation) is explicit: a striped violet banner *"You are managing customer X as Super Admin — Exit workspace"*, the customer's own theme and sidebar, every action logged in the customer's log | Customer 360 / lists |
| **Archive and delete customers** (spec §50): *Archive* (suspended, hidden from the list, restorable) is the default list action; *Delete permanently* needs the typed customer name and removes every row of every table with `hotel_id`, uploaded files, APKs, live-view frames and caches, logs out its users and rejects its TVs (401) — invoices are kept for accounting (`customer_name` snapshot, migration 032) | Customers list · Customer 360 → Settings |
| **Console pages from the spec**: Content overview (what every customer has uploaded / assigned), Reports (growth, revenue, plan / status / reseller distribution, TVs per customer, CSV), Audit logs across all customers (filters, CSV); customer menu reordered (Dashboard, Screens, Locations & groups, Content, Playlists, Schedules, …, Users, Settings, **Subscription** with TRIAL / ACTIVE / EXPIRING / EXPIRED / SUSPENDED state) | Super Admin → Insight · Customer → Subscription |
| A module that is off in the plan answers **"This feature is not available in your current plan."** on pages, ajax and API | 403 responses |
| Every new string in ગુજરાતી and हिन्दी (`lang/gu_panels.php`, `lang/hi_panels.php`) | language menu |

## What's new in 2.5.1 — all TVs on Screens & TVs, transfer a TV with everything

Server only (the TV app stays 2.5.0).

| Feature | Where |
|---|---|
| **Screens & TVs → All customers**: the Super Admin sees every TV of every customer — including the customers of resellers — on Screens & TVs, with a view switch "This customer (…)" \| "All customers (N TVs)" (default All customers, remembered), customer column with a link to the customer details, search, customer / status filters, "screens without TV" and pagination. Resellers see their own customers; customer users never see the switch | Screens & TVs · [platform_screens.md § 7](docs/modules/platform_screens.md) |
| **Transfer to another customer — everything**: ⇄ button on every TV (both views, TV detail page, All screens; bulk via checkboxes). Besides the TV (token kept, no setup on the TV) it **copies** the screen details (name / ID, area / floor, PIN, USB / HDMI-CEC mode, power-off times, device schedules) and the content (playlist, media files copied into the new customer's storage, layouts, tickers, scheduled content) to the target screen. The old customer keeps its originals; the target's TV and storage limits are checked; on any error nothing is left behind. Result: "Copied: 1 playlist, 12 media files, 1 ticker; Not copied: …" | Screens & TVs, Platform → All screens · [platform_screens.md § 2b](docs/modules/platform_screens.md) |

## What's new in 2.5 — plans, roles, all screens, new name

| Feature | Where |
|---|---|
| **New name: Krishna Cloud TV Management** — a general digital-signage SaaS. In the UI "Hotel" is now **Customer** (platform) / **Business** (customer side) and "Room" is **Screen** (screen name / ID + optional area / floor). Hotel wording remains only in the optional **Hospitality** module (guests, room service, PMS, feedback, local guide). Database, API and JSON names are unchanged | everywhere · [terminology.md](docs/modules/terminology.md) |
| **Plans & features**: every module belongs to a feature; plans (Basic / Business / Hospitality …) switch feature groups on or off per customer, plus per-customer add / remove overrides. Pages, menus, API routes, TV extensions and permissions follow the plan | Platform → Plans, Customer details · [plans_features.md](docs/modules/plans_features.md) |
| **Custom roles (RBAC)**: copy a built-in role, pick permissions from a matrix (hidden when the plan lacks the feature), assign it to users; escalation-safe | Admin → Roles · [roles.md](docs/modules/roles.md) |
| **All screens**: every TV of every customer in one table with filters, bulk commands, **move a TV** to another customer / screen, an **unassigned pool** for TVs registered with the platform key, and a **customer details** page (TVs, users, plan, features) | Platform → All screens · [platform_screens.md](docs/modules/platform_screens.md) |
| **Hindi admin panel**: the admin language menu now offers English / ગુજરાતી / हिन्दी (main pages translated, the rest falls back to English) | language menu |
| Demo data is a general business ("Krishna Showroom": Entrance, Counter 1 …); hospitality demo data only when the demo customer's plan has the Hospitality features | Platform → Demo |
| TV app 2.5.0 (versionCode 13): new name ("Krishna Cloud TV" on the launcher), "Screen name / ID" instead of room number, QR setup wording | [android/README.md](android/README.md) |

## What's new in 2.4 — features 26–50

| # | Feature | Docs |
|---|---|---|
| 26–30 | Air quality + coastal/ferry warning, Panchang + Choghadiya (offline), festival countdown, birthday wall, Google reviews | [widgets_26_30.md](docs/modules/widgets_26_30.md) |
| 31–35 | Calendar (drag & drop), content approval, start/expiry dates, playlist time windows, holiday calendar | [scheduling.md](docs/modules/scheduling.md) |
| 36–37 | Video wall (up to 4×4) and synchronized playback | [video_wall_sync.md](docs/modules/video_wall_sync.md) |
| 38–40, 43, 47, 49 | Volume / input / restart / bell schedules, spoken announcements, presence sensors, proof-of-play report | [device_schedules.md](docs/modules/device_schedules.md) |
| 41, 42, 44, 48, 50 | Live screen view, USB/offline mode, TV health, text-to-speech, HDMI-CEC (boxes) | [device_features.md](docs/modules/device_features.md) |
| 45–46 | Web player for Smart-TV browsers / PCs, Fire TV & Raspberry Pi setup | [web_player.md](docs/modules/web_player.md) |

## What's new in 2.3 — 25 new features

| # | Feature | Where |
|---|---|---|
| 1 | Restaurant menu board (sold-out switch, today's special, veg/non-veg, dayparting) | Apps → Menu board · [menu_board.md](docs/modules/menu_board.md) |
| 2, 5 | Token / queue system for hospitals, clinics, banks, offices (NEXT/RECALL, chime + voice, tickets, phone self-service) | Apps → Token display · [queue.md](docs/modules/queue.md) |
| 3 | School / office notice board | Apps → Notice board |
| 4, 6, 7, 8 | Shop offers with countdown, gym/class schedule, bus/rail/airport departures, factory KPI dashboard (+ machine push API) | [business_apps.md](docs/modules/business_apps.md) |
| 9, 10 | Real-estate showcase, wedding/event welcome | [content_apps.md](docs/modules/content_apps.md) |
| 11, 12 | Drag & drop slide designer (14 templates), PDF → slides import | Content → Design a slide / Import PDF · [designer_pdf.md](docs/modules/designer_pdf.md) |
| 13 | Split-screen layouts (up to 6 zones) | Content → Split screen · [layouts.md](docs/modules/layouts.md) |
| 14, 16, 19 | Google Sheet live table, Instagram/Facebook wall, phone photo album (+ guest upload link) | [content_apps.md](docs/modules/content_apps.md) |
| 15 | YouTube playlists and channels | Content → YouTube |
| 17, 18 | Countdown, QR (URL / UPI / WhatsApp / Wi-Fi) | Apps |
| 20 | 12 themes (Diwali, Navratri, Janmashtami, wedding …) + bundled Gujarati/Hindi fonts | every app · [display_apps.md](docs/modules/display_apps.md) |
| 21–25 | Gold/silver rates, stock market, cricket, currency, train/flight status (manual or API key) + ticker placeholders `{gold_24k}` `{usd_inr}` … | [data_feeds.md](docs/modules/data_feeds.md) |
| 26–30 | Air quality + rain / heat / sea-ferry warnings, offline panchang + choghadiya, festival calendar with countdown, birthday / anniversary wall (consent, CSV), Google reviews | Apps · Festivals · Birthdays · [widgets_26_30.md](docs/modules/widgets_26_30.md) |

## What's new in 2.2

| Feature | Where |
|---|---|
| **Ticker bar per TV / group / all**: own text, colours, speed, font size, height, top or bottom, date/time windows. The video shrinks so the bar never covers it | Admin → **Ticker** · [docs/modules/ticker_bar.md](docs/modules/ticker_bar.md) |
| **Per-user TV access**: the Admin gives each Manager / Staff / Reception user "All TVs" or only chosen groups / screens | Admin → **Users** · [docs/modules/user_access.md](docs/modules/user_access.md) |
| **SaaS role names**: Super Admin (Platform) → Admin (per customer) → Manager / Staff / Reception | everywhere |
| **Hotel chains hidden by default** (Platform settings → Features to turn them on) | Platform settings |
| TV app 2.1.1: QR setup works on old TVs (bundled root certificates, wrong-clock handling, connection diagnostics) | TV app |

## Features

**TV app** — auto-start on boot · full-screen kiosk (HOME launcher, lock-task when device-owner) · image
slideshow with fade/slide transitions · video (loop/mute) · live HLS/RTSP/DASH streams with auto-reconnect ·
HTML timetable with the current darshan highlighted · marquee or full-screen announcements · any URL / YouTube ·
clock, weather, logo and ticker overlays · emergency override · offline cache of content and media ·
remote reboot, clear-cache, screen on/off, silent APK update · PIN-protected settings · English + ગુજરાતી.

**Admin panel** — live dashboard · screens with online/offline, IP, app version, Wi-Fi · area/floor/zone groups ·
content library (uploads with resize/compression, streams, URLs, announcements, timetable builder, HTML) ·
drag-and-drop playlists · push now / schedule / daily time windows / repeat days · emergency broadcast ·
calendar · users with Super Admin / Manager / Staff roles · APK manager · logs & reports with CSV export ·
settings · GitHub auto-update with backup + auto-rollback · TV-simulator preview of any content or screen.

**Multi-customer platform (2.0)** — one server hosts many customers, each with its own login and fully
isolated data · platform admin: customers (create / suspend / enter), plans with TV limits, monthly
invoices with tax (manual payments, overdue reminders, auto-suspend & reactivation), resellers with
commission reports, license keys for self-hosted installs · white-label branding (platform → reseller →
customer) on login, admin panel, invoices and TVs · roles Platform Admin / Reseller / Super Admin /
Manager / Staff / Reception · extension points for modules ([docs/DEVELOPER.md](docs/DEVELOPER.md)).

## What's new in 2.1

| # | Feature | Where |
|---|---------|-------|
| QR | Add a TV with a QR code — the TV shows a QR, staff scan it with a phone, pick the room, done (no typing on the TV) | Admin → Add TV (QR), `docs/modules/qr_setup.md` |
| 17 | Online sign-up with 14-day free trial, reminders, upgrade → invoice | `signup.php`, Platform → Sign-ups, `docs/modules/signup_demo.md` |
| 21 | Public demo hotel + TV simulator, private client demos (7 days) | `demo.php`, Platform → Demo |
| 19 | Ad marketplace: local businesses book TV ads, UPI/bank payment, hotel revenue share, proof-of-play | `/advertise/`, Admin → Marketplace, `docs/modules/ad_marketplace.md` |
| 20 | Hotel chain dashboard: all hotels in one view, publish content & broadcast to many hotels | Admin → Chain, `docs/modules/hotel_chains.md` |

## What's new in 2.0

| # | Feature | Where |
|---|---------|-------|
| 18–22 | Multi-hotel SaaS, platform & reseller panels, plans/TV limits, license keys for self-hosted installs, manual invoices with auto-suspend, white-label branding | Admin → Platform, `docs/ADMIN_GUIDE.md`, `docs/INSTALL.md` |
| 1, 6, 9, 10 | Guest front desk, check-in mode (vacant TVs off), personal welcome card, checkout reminder, PMS API + webhook | Admin → Front desk, `docs/modules/guests_services.md` |
| 2, 3, 8 | Guest phone web app via TV QR: room service, requests, feedback (Google review) + live staff alerts | `/g/<token>`, Admin → Orders |
| 11, 17 | Sponsor ads with impression reports, analytics (plays, uptime, hours ON, electricity, services) | Admin → Ads, Analytics, `docs/modules/ads_analytics_templates.md` |
| 7, 12 | 28 ready-made templates (festivals, notices, temple, local guide) — replaces #13 AI for now | Admin → Templates |
| 4, 5, 15, 16 | TV guest menu (QR, Live TV, HDMI, cast), volume + night limit, screenshots, messages | Admin → TV controls, `docs/modules/pwa_support_devices.md` |
| 14 | Admin as installable phone app (PWA) with web-push notifications | Admin → Notifications |
| 23 | Windows bulk TV setup tool | `tools/windows/`, Admin → Screens & TVs → Download setup file |
| 24 | Support: TV logs, crash reports, platform support dashboard | Admin → Support |

Developer extension points: `docs/DEVELOPER.md`. Full 2.0 contract: `docs/V2_SPEC.md`.

## Quick start

1. **Server** — upload `dist/hotelcast-2.5.0.zip` (built by `tools/build-release.sh`) to your hosting, extract, open
   `https://your-domain/hotelcast/install/` and follow the 7-step wizard → [docs/INSTALL.md](docs/INSTALL.md)
2. **TVs** — install `android/release/KrishnaCloud-TV-2.5.0.apk` on each TV (USB pen-drive or `adb`), open it,
   scan the QR code with your phone (or enter server address + screen name / ID + registration key) → [android/README.md](android/README.md)
3. **Use it** — Admin → Content → add content → Broadcast → choose screens → *Push now* → [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md)

## Auto-update (GitHub)

Admin → **Auto-Update**: save repository URL, branch, app folder (`hotelcast`) and a fine-grained
Personal Access Token (Contents: read) once. Then:

* **Check for Update** — latest version, commit message, date, changed files and changelog.
* **Update Now** — full backup (files + MySQL dump → `backups/backup_YYYY-MM-DD_HH-MM.zip`) → download →
  PHP syntax check of every file → install (never touching `.env`, `config.php`, `uploads/`, `storage/`,
  `backups/`, `logs/`) → numbered SQL migrations (tracked in `schema_migrations`) → cache/opcache clear →
  health check → **automatic rollback** of files and database on any error → log saved.
* **History** with per-row rollback, manual restore of any backup, backup download/upload.

To publish a new server release: bump `hotelcast/version.json`, add `hotelcast/migrations/00N_name.sql`
if the schema changes, push to the branch. TV app releases: upload the APK in Admin → APK Manager →
*Push App Update*.

## Repository layout

```
hotelcast/            Server application (this is what you upload)
  admin/              Admin panel pages (+ ajax.php, ajax_update.php)
  api/                REST API front controller (/api/...)
  install/            One-click installer (auto-deleted after use)
  core/               PHP classes: DB, Auth, Csrf, ContentManager, ContentResolver, Broadcaster,
                      DeviceManager, Scheduler, Updater, Backup, Migrator, HealthCheck, …
  migrations/         001_init.sql, 002_… (run automatically by installer/updater)
  lang/               gu*.php / hi*.php — Gujarati and Hindi translations
  assets/             CSS/JS + vendored Bootstrap, icons, SortableJS, FullCalendar, hls.js
  uploads/ backups/ logs/ storage/   runtime data (protected, never overwritten)
  tests/              PHPUnit unit + integration tests, load test
  version.json        {"version":"1.0.0","commit":"…","date":"…"}
android/              Android Studio project (Kotlin) + release/KrishnaCloud-TV-2.5.0.apk
docs/                 INSTALL, ADMIN_GUIDE, API, SECURITY, TEST_REPORT
tools/build-release.sh  builds dist/hotelcast-<version>.zip
```

## Development & tests

```bash
# PHP tests (needs a MySQL/MariaDB test database; it is wiped on every run)
cd hotelcast
HC_TEST_DB_HOST=127.0.0.1 HC_TEST_DB_NAME=hotelcast_test HC_TEST_DB_USER=hctest HC_TEST_DB_PASS=… phpunit -c phpunit.xml

# Load test against a running installation (simulates 80 TVs)
php tests/load/load_test.php --url=https://your-domain/hotelcast/ --key=REGISTRATIONKEY --tvs=80 --duration=120

# Local dev server (emulates the .htaccess routing)
php -S 127.0.0.1:8080 -t hotelcast hotelcast/tests/router.php

# Android
cd android && ./gradlew testReleaseUnitTest lintRelease assembleRelease
```

Results: [docs/TEST_REPORT.md](docs/TEST_REPORT.md). Security design: [docs/SECURITY.md](docs/SECURITY.md).
API reference: [docs/API.md](docs/API.md).

## ગુજરાતી સારાંશ

Krishna Cloud TV Management થી મંદિર, દુકાન, શોરૂમ, રેસ્ટોરન્ટ, હૉસ્પિટલ, શાળા, ઑફિસ, ફેક્ટરી કે હોટેલની
દરેક સ્ક્રીન (Android TV) ને એક જ બ્રાઉઝર પરથી કંટ્રોલ કરો: લાઇવ દર્શન, દર્શન સમય, મેનુ, જાહેરાતો, ઓફર
અને વિડિયો — તરત જ બધી સ્ક્રીન પર. એક સર્વર પર ઘણા ગ્રાહકો (customers) — દરેકની પોતાની સ્ક્રીન. સર્વર પર
ફાઇલો અપલોડ કરો, `/install` ખોલો અને સ્ટેપ ફોલો કરો. એડમિન પેનલ અંગ્રેજી, ગુજરાતી અને હિન્દીમાં ઉપલબ્ધ છે.
