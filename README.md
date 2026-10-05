# HotelCast — Hotel TV Remote Management System

Control every Android TV in your hotel from one browser tab. When a TV is switched on, the
HotelCast app opens full-screen and shows whatever the admin has pushed — **Dwarkadhish live
darshan**, the **temple timetable**, hotel **announcements**, **offers**, **videos**, slideshows,
web pages — room by room, floor by floor, or the whole hotel at once. No room visits needed.

*Hotel ni darek Android TV ne admin panel thi remotely control karo — content push karo, room-wise
schedule karo, emergency message moklo, TV reboot/update karo.*

```
 Admin Panel (PC / tablet / phone)
        │  HTTPS
        ▼
 PHP 8 REST API  ──►  MySQL / MariaDB
        ▲
        │  poll every 5–10 s (hash-based, content only sent when it changes)
        │
 Android TV app (each room)  ── offline cache: keeps showing the last content without internet
```

| Part | Folder | Tech |
|------|--------|------|
| Server: admin panel + REST API + installer + auto-updater | [`hotelcast/`](hotelcast) | PHP 8.1+, MySQL 8 / MariaDB 10.6, Apache, Bootstrap 5, vanilla JS — no Composer, no Node |
| Android TV app | [`android/`](android) | Kotlin, ExoPlayer 2.19, Retrofit, OkHttp, Glide, WorkManager — min SDK 21, target 34 |
| Documentation | [`docs/`](docs) | Install guide, TV setup, admin guide, API, security, test report |

## Features

**TV app** — auto-start on boot · full-screen kiosk (HOME launcher, lock-task when device-owner) · image
slideshow with fade/slide transitions · video (loop/mute) · live HLS/RTSP/DASH streams with auto-reconnect ·
HTML timetable with the current darshan highlighted · marquee or full-screen announcements · any URL / YouTube ·
clock, weather, logo and ticker overlays · emergency override · offline cache of content and media ·
remote reboot, clear-cache, screen on/off, silent APK update · PIN-protected settings · English + ગુજરાતી.

**Admin panel** — live dashboard · rooms with online/offline, IP, app version, Wi-Fi · floor/zone groups ·
content library (uploads with resize/compression, streams, URLs, announcements, timetable builder, HTML) ·
drag-and-drop playlists · push now / schedule / daily time windows / repeat days · emergency broadcast ·
calendar · users with Super Admin / Manager / Staff roles · APK manager · logs & reports with CSV export ·
settings · GitHub auto-update with backup + auto-rollback · TV-simulator preview of any content or room.

## Quick start

1. **Server** — upload `dist/hotelcast-1.1.0.zip` to your hosting, extract, open
   `https://your-domain/hotelcast/install/` and follow the 7-step wizard → [docs/INSTALL.md](docs/INSTALL.md)
2. **TVs** — install `android/release/HotelCast-TV-1.1.0.apk` on each TV (USB pen-drive or `adb`), open it,
   enter server address + room number + registration key → [android/README.md](android/README.md)
3. **Use it** — Admin → Content → add content → Broadcast → choose rooms → *Push now* → [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md)

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
  lang/               gu.php — Gujarati translations
  assets/             CSS/JS + vendored Bootstrap, icons, SortableJS, FullCalendar, hls.js
  uploads/ backups/ logs/ storage/   runtime data (protected, never overwritten)
  tests/              PHPUnit unit + integration tests, load test
  version.json        {"version":"1.0.0","commit":"…","date":"…"}
android/              Android Studio project (Kotlin) + release/HotelCast-TV-1.1.0.apk
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
cd android && ./gradlew testReleaseUnitTest assembleRelease
```

Results: [docs/TEST_REPORT.md](docs/TEST_REPORT.md). Security design: [docs/SECURITY.md](docs/SECURITY.md).
API reference: [docs/API.md](docs/API.md).

## ગુજરાતી સારાંશ

HotelCast થી હોટેલના દરેક રૂમના Android TV ને એક જ બ્રાઉઝર પરથી કંટ્રોલ કરો: દ્વારકાધીશ લાઇવ દર્શન,
મંદિરના દર્શન સમય, હોટેલની જાહેરાતો, ઓફર અને વિડિયો — તરત જ બધા TV પર. સર્વર પર ફાઇલો અપલોડ કરો,
`/install` ખોલો અને સ્ટેપ ફોલો કરો. એડમિન પેનલ અંગ્રેજી અને ગુજરાતી બંનેમાં ઉપલબ્ધ છે.
