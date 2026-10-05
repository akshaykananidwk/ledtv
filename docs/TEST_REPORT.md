# HotelCast 1.0.0 — Test Report

Date: 2026-10-05 · Environment: PHP 8.3.6 (CLI + built-in server), MariaDB 10.11, Ubuntu 24.04,
JDK 21, Android Gradle Plugin 8.5.2. Apache itself was not available in the test container; its
`.htaccess` routing was emulated with `hotelcast/tests/router.php`.

## Summary

| Area | Result |
|------|--------|
| PHP lint (`php -l`, every file) | ✅ 0 errors |
| PHPUnit (unit + integration) | ✅ **109 tests, 2,962 assertions, 0 failures** (time-window tests skip themselves only when the ±1 h window crosses midnight) |
| Integration: admin push → TV receives | ✅ received on the next poll (0.01 s after the push in the test; on real TVs at most one poll interval, 8 s by default) |
| Load test, 80 TVs polling every 8 s for 60 s | ✅ 599 requests, 100 % success, p50 6 ms · p95 10 ms · p99 17 ms |
| Stress test, 200 TVs polling every 3 s for 45 s | ✅ 2,999 requests (66.6 req/s), 100 % success, p50 5 ms · p95 11 ms · max 316 ms |
| Update system: backup → update → health check → rollback | ✅ (mock GitHub API, see below) |
| Installer: fresh copy → 7 steps → working system | ✅ |
| Android unit tests (`testReleaseUnitTest`) | ✅ 29 tests, 0 failures (re-run for 1.1.0) |
| Android instrumentation tests | ⚠ compile (`assembleAndroidTest`) but need a TV or emulator to run — not run |
| Android lint | ✅ 0 errors |
| Signed release APK | ✅ `apksigner verify`: v1 + v2 signatures valid, RSA 2048 |

## PHP test suites (`hotelcast/tests`)

### Unit tests (`tests/Unit`)
* **CoreHelpersTest** — HTML escaping (incl. Gujarati), `.env` round trip with special characters,
  libsodium encryption, colour sanitising, the GitHub token is encrypted at rest, file cache, rate limiter,
  the Gujarati translation file is valid UTF-8 and switches the language.
* **MigratorTest** — SQL splitter (quotes, comments), all 20 tables created, migrations are idempotent.
* **ContentTest** — validation for every content type, rejects `javascript:` URLs, timetable rows and escaping,
  YouTube embed URLs, TV item format, CDN base URL, image upload resized to 1920 px and renamed to a random name,
  a PHP file disguised as a `.jpg` is rejected, `.php` extension is rejected.
* **ResolverBroadcastTest** — priority order: default → group → room → time window → emergency → off;
  the content hash is stable; push-now assigns content and queues commands (duplicates collapsed);
  emergency start/stop; one-off schedule processed exactly once; daily TV power-off schedules (inside/outside
  window, overnight, pause/resume, emergency wakes TVs that are scheduled off, validation); time windows incl. overnight windows and repeat
  days; screen on/off persists; offline detection.
* **AuthBackupTest** — bcrypt cost 12, password policy, role map, backup→restore round trip (Gujarati text,
  newlines, quotes, files added after the backup are removed, `.env` untouched), protected paths, unsafe backup
  names rejected.

### Integration tests (`tests/Integration`, real HTTP)
* **DeviceApiTest** — health, registration (wrong key, validation, success, only the token hash is stored,
  floor taken from the room number), polling with the content hash, push reaching the TV within 10 s, ack (no
  re-delivery), heartbeat, play history, a TV can't read another room or impersonate another device, missing or
  invalid token, wrong methods, unknown routes, `UPDATE_APP` + `REBOOT` commands, APK download (auth required,
  SHA-256 verified), revoked device rejected, rate limit returns 429 with `Retry-After`.
* **AdminPanelTest** — redirect to login, 5-failure lockout (the correct password is refused while locked),
  login for all three roles, **13 pages × 3 roles permission matrix** (200 or 403 as expected, with no PHP
  warnings, notices or fatal errors), XSS payload in a room name is escaped, CSRF required on AJAX POSTs (419),
  dashboard/room-status AJAX, staff can send an emergency but not a device reboot (403), TV-simulator preview for
  content/playlist/room, updater AJAX limited to super admin, logout.
* **InstallerTest** — an uninstalled app redirects to `/install` and the API answers 503 `NOT_INSTALLED`; wizard
  steps: requirements → wrong DB password message → test-connection AJAX → database auto-created → `.env` and
  `config.php` written → tables + demo data → weak admin password rejected → admin created → hotel details
  (Gujarati name) → GitHub settings (token encrypted) → `installed.lock` written, `/install` deleted → the
  installed API and admin pages work; a re-uploaded installer is blocked (403).
* **UpdaterTest** (against a mock GitHub API server) —
  1. *Check for Update*: version, short SHA, changed files counted only inside the app folder, commit list.
  2. *Successful update* 1.0.0 → 1.1.0: backup created, new files installed, migration `002` applied, `.env`,
     `config.php` and `uploads/` are **not** overwritten even though the package contains them, data preserved,
     history = success, lock released.
  3. *Failed migration* in 1.2.0 → **automatic rollback**: version, files and DB schema restored, the broken
     migration is not recorded, history row = `rolled_back` (survives the DB restore).
  4. *Syntax error in the package* → rejected before any file is touched.
  5. *Bad token* → clean failure, nothing changed.
  6. *Manual rollback* to the first backup → back to 1.0.0, schema restored, history entry `rollback`.

## Load test

`php hotelcast/tests/load/load_test.php --url=… --key=… --tvs=N --interval=S --duration=D`
registers N simulated TVs, then polls with jitter, sends heartbeats and acks commands.
Server for the run: PHP built-in server with 16 workers on the same container as MariaDB.

| Scenario | Requests | Success | p50 | p90 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|
| 80 TVs / 8 s / 60 s | 599 | 100 % | 6 ms | 9 ms | 10 ms | 17 ms | 28 ms |
| 200 TVs / 3 s / 45 s | 2,999 | 100 % | 5 ms | 9 ms | 11 ms | 17 ms | 316 ms |

The per-IP registration limit (100 per 5 min) works as designed: in the first stress attempt only 20 of 200
TVs registered. It can be raised with `register_limit_per_5min` in `config.php` for very large hotels.
Run the same script against your real host after installation; shared hosting will have higher latency.

## Security scan (OWASP checklist)

Covered by the tests above and documented in [SECURITY.md](SECURITY.md): access control matrix, CSRF, XSS
escaping, prepared statements only, upload validation, brute-force lockout, rate limiting, token hashing,
encrypted secrets, protected files on update, installer lock. No automated DAST tool was run in this
environment — recommended before go-live: run OWASP ZAP baseline against the installed site over HTTPS and
confirm `/.env`, `/config.php`, `/core/`, `/backups/`, `/logs/` return 403 on the real Apache server.

## Not tested here (needs real hardware or hosting)

* The TV app on physical Android TVs (boot auto-start, kiosk/lock-task, silent install as device owner, reboot,
  RTSP/HLS playback of the real darshan stream). Recommended acceptance test: 2–3 TV models in the hotel,
  following `android/README.md`.
* Apache `.htaccess` behaviour on the target host (verify step 1 of the installer shows a green .htaccess check).
* Video compression via ffmpeg (not installed in the test container; uploads work without it).
* Email/WhatsApp delivery (depends on the host's `mail()` and the gateway account).
