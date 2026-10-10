# 2.8.0 — email that really works: SMTP, forgot password, invites, welcome mail (2026-10-10)

Server `version.json` 2.8.0 (migration 035: `password_resets`, `mail_log`); the TV app stays 2.6.0. Guide: `docs/modules/email.md`.

- Full PHP suite: **623 tests, 25,824 assertions, 0 failures** (607 → +16 in the new `tests/Integration/Apps/EmailFlowsTest.php`). No existing test had to change. `php -l` on every changed PHP file (29): 0 errors.
- No real e-mail was sent at any point: tests capture mail with `Mailer::$testHook` / `Notifier::$mailer`, the sandbox writes to `storage/mail_outbox.jsonl`, SMTP only to a fake server on 127.0.0.1.

| `EmailFlowsTest` | covers |
|---|---|
| MIME | multipart/alternative (text + HTML, quoted-printable, CRLF), RFC 2047 Gujarati subject (round trip, encoded words ≤ 75 chars, folding), Message-ID / Date / MIME-Version / Reply-To, quoted / encoded display names; CR/LF injection in subject, From and From name stripped (no extra header line), injected recipient refused; dot-stuffing |
| hooks / template | `Mailer::$testHook` gets the whole message, old `Notifier::$mailer` still gets every mail; `Notifier::email` keeps the plain text and adds the branded HTML (customer white-label name / colour, linked URLs, support contact); template escapes, button URL also in the text part |
| SMTP client (fake server `tests/fixtures/fake_smtp.php`) | no-auth delivery: EHLO → MAIL FROM → RCPT TO → DATA → QUIT, message received byte-exact after un-stuffing; AUTH LOGIN ok / wrong password (exact `535 …` + App-Password hint, password never in the transcript); AUTH PLAIN; RCPT 550; STARTTLS required but not offered → refused before any credentials; closed port → "SMTP connect … failed"; TLS peer verification on by default, off only with "allow self-signed" |
| Mailer + log | SMTP settings from the platform (encrypted password) → sent as the mailbox address, caller address as Reply-To; `mail_log` row (recipient, subject with the code masked, transport, status), never a body; failure row with the server error; only the newest 200 rows kept; non-loopback SMTP host in a sandbox → outbox |
| settings page | Email (SMTP) card + presets + log render; save → password stored `enc:…`, never echoed, empty = keep, "remove saved password"; **Send test email** ok through the fake server, wrong password → `535 5.7.8 …` + SMTP conversation shown (test does not save), unreachable server → connect error; CSRF 419; customer admin 403 |
| forgot / reset | login shows "Forgot password?" + "Email or username"; unknown address and known address (mixed case + spaces) → identical page, ≥ 1 s, timing difference small; one mail with an absolute 64-hex link, 60 min; only SHA-256 stored; the token in no log file / table; reset page sends `Referrer-Policy: no-referrer` and moves the token out of the URL; weak / mismatching password refused; success → new hash, failed attempts + lock cleared, every session revoked (old session redirected to login), token used, platform-level activity row, "password changed" mail; second use refused; login with the new password by email |
| tokens | new request invalidates the older token; expired token refused (page + `complete()`); malformed tokens; disabled account → token dead; password = email refused |
| rate limits | per account: 3 mails / hour, 4th request silent; per IP: 5 / 15 min, then "Too many requests" (429) |
| CSRF | forgot and reset forms without / with a forged token → 419, nothing sent / changed |
| roles | reseller, chain admin, customer super admin, staff: link mailed and works; Hindi customer owner → Hindi mail with the customer's brand and `&b=<slug>` link; Gujarati reseller → Gujarati mail; inactive user, archived customer, suspended reseller, unknown → no mail, same neutral page |
| login by email | `  BoSs@Alpha.TEST ` and `EMBOSS` log in; duplicate email (other case) refused in Users and `validateAdmin`; `bin/make_super_admin.php` still creates a working admin |
| sign-up | OTP mail (code in text + big code in HTML, Gujarati), verify → **welcome mail** (subject "your free trial is ready", login URL, username, email, trial days, registration key / TV steps) + platform notice; failed send → `Signup::$mailFailed`, `mail_log` failed, send not counted, immediate retry works; over HTTP with an unreachable SMTP server the verify page shows "could not send … contact support" with the support address |
| invites | Customer 360 → Users with "Email an invite link" and no password → Gujarati invite mail, `invite` token 72 h, no usable password before, branded reset page (customer brand), password set → login by email, `password_set_invite` row in the customer's log; direct password still works (no token); customer admin Users → invite; Super Admin → Platform admins invite; empty password without the checkbox still refused |

- Browser (headless Chromium, `tests/browser/email_qa.js` + `email_server.php` sandbox): **31 checks passed** — login EN / GU with "Forgot password?" (390 px), forgot form EN / GU, neutral answer, reset form EN / GU (token removed from the address bar), Super Admin logs in with the email address, Email (SMTP) card EN / GU (password not echoed, log), test email to an unreachable server shows the exact error, rendered reset e-mail EN / GU; no JavaScript / HTTP errors. Screenshots: `docs/screenshots/2.8/` (login-forgot-en, login-forgot-gu, forgot-form-en, forgot-form-gu, forgot-sent-en, reset-form-en, reset-form-gu, smtp-settings-en, smtp-settings-gu, smtp-test-error-en, email-reset-en, email-reset-gu).
- Not tested here (needs the live mailbox): a real STARTTLS / SSL handshake with Gmail / Hostinger / Zoho and delivery into a real inbox (use **Send test email** on the live site), SPF / DKIM of the live domain.

---

# 2.7.0 — Super Admin APK Manager, forced TV app update on start; TV app 2.6.0 (2026-10-10)

Server `version.json` 2.7.0 (migration 034); TV app **2.6.0 (versionCode 14)**. Design: `docs/modules/apk_manager.md`.

- Full PHP suite: **607 tests, 25,326 assertions, 0 failures** (602 → +5 in the new `tests/Integration/Apps/PlatformApkTest.php`). `php -l` on every changed file: 0 errors.
- One existing expectation kept by design: `TenancyTest` (another customer's APK id in `apk.php` push → 404) — the customer push still uses `Tenant::find` first and only then accepts a platform release rolled out to that customer.

| `PlatformApkTest` | covers |
|---|---|
| APK parsing | fixture APKs built with aapt2 + signed with a throwaway test key (`tests/fixtures/apk/`): package / versionCode / versionName / signer SHA-256 equal `apksigner`'s value; unsigned → no signer; the real `android/release` build (UTF-16 manifest, v1+v2) → `com.hotelcast.tv`, release signer `b0f2c899…1a156c`; a non-ZIP → "not a valid Android APK" |
| access | Super Admin 200 without PHP errors; customer admin and reseller 403 on the page, the upload and "Update all TVs now"; wrong CSRF token refused; nothing stored / queued |
| nav | "APK Manager" link only in the Super Admin console (not in the reseller panel nor a customer workspace), group "Devices & content" |
| upload validation | broken ZIP, wrong package (`com.example.other`), unsigned, wrong signer, same version again → 422 with the reason, no row, no file |
| device API | `GET api/device/app-version`: no release → `update: null`; after the upload latest 90 + `required` + `required_version_code` + sha256 + URL (`source: platform`); 401 without / with a wrong token; poll carries `app_update`; web player: `update: null`, no `app_update`, APK 404; download: full (sha256 + `X-Content-SHA256`, counted) and resumed (`Range` → 206, same bytes, not counted), 401 unauthenticated |
| precedence | customer's own higher release (95, optional) offered to that customer only (required stays 90), other customers can't download it (404); a lower customer release never beats the platform release; roll-out "selected" (91 for one customer: others stay on 90 and get 404 for 91); selected without customers refused; options change (optional + all customers) → latest 91, required 90 |
| counters | Devices & screens counters / APK Manager tiles use each customer's effective latest (4 outdated, 1 on latest, web player excluded); page and Overview tile show "Latest: v9.1.0" |
| Update all TVs now | `UPDATE_APP` only for online Android TVs with an older app (offline TV, current TV and web player skipped), payload = each customer's effective release (95 for the customer with its own higher build), delivered on the next poll, a second press replaces (no duplicate); Devices & screens "Update app" uses the same release |
| delete | latest platform release refused (stays), older one deleted with its file; a customer admin can't delete a platform release through `apk.php`; audit rows at platform level |
| translations | every string of the page / core classes in gu and hi; Gujarati page renders |

- **Android** (`./gradlew testReleaseUnitTest lintRelease assembleRelease`): ✅ **233 unit tests, 0 failures** (+7 in `UpdateGateTest`: block only for a required newer version (never equal / lower / optional / without URL), server answer wins and an empty answer clears the cache, offline → cache, no info → start normally, no loop after updating, retry policy (download always every 30 s, install max 3, signature / wrong APK manual), PackageInstaller status → reason (signature mismatch, cancelled, blocked, storage, invalid), free space + progress, the JSON contract of `app-version` / poll). Lint: **0 errors** (36 warnings, none new from the 2.6.0 files).
- APK: `android/release/KrishnaCloud-TV-2.6.0.apk` (7.4 MB, SHA-256 `35eae00e21d65524d920ff7a141e222e72c147785ee4a739be0770b972e21ef5`), `apksigner verify`: v1 + v2 OK, signer certificate SHA-256 **`b0f2c89990dcc8376790e2d6add7f9830f3990f1e8e44abb29ef7eb1dc1a156c`** (same key → OTA from 2.5.0 works); the server's own parser reads it as `com.hotelcast.tv` 14 / 2.6.0 with the same signer. The 2.5.0 APK was removed from `android/release/`.
- Browser (headless Chromium, `tests/browser/panels_server.php` sandbox + 3 seeded platform releases): APK Manager EN / GU and options row / "Update all TVs now" confirmation / 390 px (no horizontal overflow), no JavaScript errors. Screenshots: `docs/screenshots/2.7/` (apk_manager_en, apk_manager_gu, apk_manager_options_en, apk_manager_update_all_confirm_en, apk_manager_mobile_en).
- Not tested here (needs a real TV): the "Update required" screen on a device (silent install as device owner, system installer confirmation, "Install unknown apps" flow, signature-mismatch message from Android), resume after a cut connection on a TV.

---

# 2.6.1 — public landing page, TV transfer only from the console, logs (2026-10-09)

Server only (`version.json` 2.6.1; the TV app stays 2.5.0).

- Full PHP suite: **602 tests, 25,154 assertions, 0 failures** (591 → +9 LandingPageTest, +2 PanelsTest: Super Admin hidden from customer logs + clear logs; console pages never render inside a customer workspace; +1 ScreenTransferTest: only the Super Admin can transfer, every other user type refused).

- **Landing page** (`index.php`, `core/Landing.php`, `assets/landing/`, `lang/{gu,hi}_landing.php`): new `tests/Integration/Apps/LandingPageTest.php` (9 tests) — 200 without login and no PHP warnings, every section, SEO tags + valid SoftwareApplication JSON-LD, CSP with nonce and no inline script, no CDN asset; EN / GU / HI via `?lang=` (cookie set), cookie and Accept-Language (q-values); plans from the database (active plan with price / limits / modules shown, inactive plan hidden, "all modules, except …", no active plan → "Contact us for pricing"); trial / demo / WhatsApp / call / e-mail buttons only when the platform settings enable them, white-label name; logged-in user gets "Go to dashboard" (200, no redirect); not installed → 302 to `install/`; no customer name in the page, live numbers only from 10 upwards.
- Browser QA (`tests/browser/landing_qa.js` + `landing_server.php`, headless Chromium): **all checks passed** — EN / GU / HI at 360, 390, 768 and 1280 px: no horizontal overflow, every link / button ≥ 44 px high, nav on one line at 1280 px, mobile menu opens / closes (aria-expanded), reveal-on-scroll leaves nothing hidden, back-to-top, no console errors / CSP violations / failed requests. Screenshots: `docs/screenshots/2.6.1/` (landing-desktop-en, landing-mobile-gu, landing-mobile-hi, landing-mobile-menu-open, landing-plans).
- **TV transfer** only from the Super Admin console (removed from the customer's Screens page).
- **Logs**: the Super Admin's work inside a customer workspace no longer appears in the customer's logs (still in Super Admin → Audit logs); logs can be cleared (customer Admin: own logs per tab, all or older than 30 / 90 / 365 days; Super Admin: audit log, error / update logs). New `PanelsTest` test.

# 2.6.0 — three panels, Super Admin console, customer archive / delete (2026-10-09)

Server only (`version.json` 2.6.0; the TV app stays 2.5.0). Separate Super Admin console / Reseller panel / Customer workspace (`core/Panel.php`), Customer 360 with one-click switches, global search, impersonation banner, archive + permanent delete, audit logs / reports / content overview, subscription state; migration 032. Design notes: `docs/modules/panels.md`; owner's specification: `docs/SPEC_SAAS.md`.
PHPUnit ✅ **590 tests, 24,870 assertions, 0 failures** (full suite; 577 before + 13 in the new `tests/Integration/Apps/PanelsTest.php`). Five existing tests were adjusted to the new design only where the design changed (home pages `platform_overview.php` / `reseller_overview.php`, customer view → Customer 360, commission report under Reseller → Invoices); their checks were kept.

| `PanelsTest` | covers |
|---|---|
| panels & sidebars | platform admin lands on the console (no customer module in the sidebar, badge, global search); reseller panel limited (no platform-only item, 403 on platform pages); customer workspace shows the business name only, 403 on every console / reseller page |
| impersonation | "Open customer workspace" → customer theme + banner (role word, Exit), sidebar is the customer's, user menu links back; exit → console; reseller: own customers only (404 for foreign), same banner |
| global search | customers / TVs by device id / users by e-mail / resellers for the platform admin; reseller never sees another reseller's customers, users or the resellers group; customer users 403 (page + switch ajax) |
| Customer 360 | every tab renders inside the console; feature switch on / off (override stored / removed, dependency warning), suspend / activate, user active, screen on / off (scope-checked), plan active, sign-up open, platform registration; audit rows in both logs; reseller scope + 403 on platform-only switches; CSRF 419 on ajax and forms; create user (role, language, log, login works), refused roles, role change logs the user out, password reset, limits form, reseller limits |
| delete (spec §50) | populated customer (demo content, tickers, invoice, 2 registered TVs, files): resellers / customers 403, wrong name does nothing, CSRF 419; delete → hotels row gone, no orphan row in any `hotel_id` table, cascades clean child tables, files gone (other customer's kept), TVs 401 on the next poll, re-registration refused, users logged out, invoice kept with `customer_name` and shown on Platform → Invoices, result message with counts |
| spec alignment | §34 menu order / labels per panel, console pages (content overview, reports, audit logs) render, dashboard KPIs + charts + device errors; subscription page state ACTIVE → EXPIRING, scoped, 403 for staff; §10 "This feature is not available in your current plan." on page + ajax + `Features::denial`, translated; audit logs filters / CSV / 403 for reseller & customer, customer log stays own; archive → suspended + hidden + users logged out + log row, restore → active again, delete button only after archiving (list) |
| translations | gu / hi tables contain the panel strings; console pages render in gu / hi without PHP errors |

Browser QA (`tests/browser/panels_qa.js`, headless Chromium, sandbox with a platform admin, a reseller with 2 customers, 2 direct customers, 17 fake TVs, a pending sign-up, 2 invoices): **60 checks passed** — console home / badge / sidebar, sign-up switch via ajax (confirm modal), customers list (archive + status switches), header search → results, Customer 360 plan switch, screens switches, user created from the console, delete modal needs the typed name, impersonation banner + exit, mobile (390 px: collapsed sidebar, no horizontal overflow), Gujarati console, reseller overview (own customers only, search finds nothing foreign), customer dashboard EN / GU without platform items, 403 on console URLs; no JavaScript / HTTP errors. Screenshots: `docs/screenshots/2.6/` (super-admin-overview, -customers, -global-search, customer-360-plan / -screens / -users / -delete-modal, impersonation-banner, super-admin-overview-mobile, super-admin-sidebar-mobile, super-admin-overview-gu, customer-360-plan-gu, reseller-overview, reseller-customers, customer-dashboard, customer-dashboard-gu).

---

# 2.5.1 — all customers' TVs on Screens & TVs, transfer a TV with everything (2026-10-08)

Server only (`version.json` 2.5.1; the TV app stays 2.5.0). Screens & TVs (`admin/rooms.php`) gets the view switch "This customer" | "All customers" for platform users and a "Transfer to another customer" dialog with "Transfer everything" (screen details + content copied into the target, `core/ScreenTransfer.php`). See `docs/modules/platform_screens.md` § 2b, § 7.
PHPUnit ✅ **577 tests, 23,699 assertions, 0 failures, 2 skipped** (full suite; 570 before + 7 in the new `tests/Integration/Apps/ScreenTransferTest.php`). One earlier full run had a failure in `ContentAppsTest::testEventWelcomeProgrammeHighlightAndFrames` (programme item at 11:59 PM while the run crossed local midnight — time-of-day dependent, passes alone and in the second full run).

| `ScreenTransferTest` | covers |
|---|---|
| platform admin on rooms.php | All customers default (every customer's TVs incl. resellers' customers, customer column links), search / customer / status / "screens without TV" filters, pagination; without an open customer only All; own account: switch, `?view=customer` remembered, Transfer buttons in the customer view, bulk option and on the TV detail page |
| reseller / customer user | reseller: own customers only, no Transfer (no `platform.move`), old redirect to the panel until `?view=all`; customer admin: old page, no switch, `?view=all` and a posted platform form change nothing; reseller POST → 403; core API refuses |
| transfer everything | screen details (ID, name, floor, PIN, notes, CEC), playlist + image / video / announcement / layout copied as new rows of the target with files in `uploads/h{target}`, layout zones re-pointed, display app and bell with an uploaded sound listed as not copied, room ticker / power-off window / volume schedule copied, nothing else of the old customer; old customer's rows and files unchanged; token kept, old commands deleted; the TV's next poll (ContentResolver) lists the copies with the target's URLs and serves the file; logs on both customers + platform |
| no options / inherited content | without boxes nothing is copied; a screen following default content gets it copied and assigned directly; "new screen" keeps the typed name |
| limits & failures | storage limit (`STORAGE_LIMIT`), screen limit (`LICENSE_LIMIT`) and a clash after files were copied: no rows, no screen, no files left, TVs unchanged, originals intact |
| CSRF / translations | 419 without / with a wrong token on rooms.php (list + TV page) and platform_screens.php; every new string in gu / hi, no hotel / room wording |

Browser check (headless Chromium, sandbox with 4 customers, 7 TVs): All customers view EN / GU, customer view with Transfer buttons, transfer dialog, transfer of a screen with 2 images, a video, an announcement, a display app and a ticker → "Copied: 1 screen setting, 1 playlist, 4 content items, 3 media files, 1 ticker; Not copied: Display app …", target customer lists the screen with its playlist. No JavaScript errors from the app. Screenshots: `docs/screenshots/2.5.1/`.

---

# 2.5.0 — Krishna Cloud TV Management: plans & features, custom roles, all screens, rename (2026-10-08)

Product renamed to **Krishna Cloud TV Management**; UI wording Hotel → Customer / Business, Room → Screen outside the Hospitality module (database / API / JSON names unchanged, see `docs/modules/terminology.md`); Hindi admin language; migration 031.
PHPUnit ✅ **570 tests, 23,449 assertions, 0 failures, 3 skipped** (full suite run twice after the QA / security review; before it: 560 tests, 9 intermittent failures in `RolesTest`, see below) (new: `TerminologyTest` crawls every admin page as platform admin — platform pages and inside a customer — and as a customer admin without Hospitality, in EN / GU / HI: no PHP warnings, no "hotel" / "room" in the visible English text; `MigratorTest::testProductNameMigration031`) · Android ✅ 226 tests, lint 0 errors, APK `KrishnaCloud-TV-2.5.0.apk` (code 13, launcher label "Krishna Cloud TV"), same signing key (SHA-256 `b0f2c899…1a156c`).

### 2.5 QA and security review

| Area | Result |
|------|--------|
| Security review of plans & features, custom roles, platform screens / move TV / unassigned pool (code reading + `tests/Integration/Apps/SecurityReview25Test.php`, 10 tests, each one fails on the code before the fix) | ✅ 8 issues fixed (table below) |
| Checked and found OK (tests kept / added) | hidden modules by direct URL, `ajax.php` / `ajax.d` actions, REST module routes, display pages and TV content (extensions, app families, layouts) → 403 / skipped; custom roles: no platform permissions (`platform.*`, `update.manage`, `chain.view`, `push.self` dropped on save), no rights the editor lacks, no own-role edit or self-assignment, foreign role ids → 404, the last active Admin stays (custom roles are never Admin), CSRF on every POST; pool key secret (platform admin only, not on customer pages), rotatable, per-IP registration limit; customers get 403 on All screens / pool actions; screen limit on registration, QR claim, pool assign and move; user limit on user create |
| Browser QA (headless Chromium, sandbox with two customers): plan with 3 features → customer → hidden menus + 11 direct URLs + an ajax action refused; plan changed → menus and pages back; custom role "Content desk" + user → only content / playlists; TV moved between customers on All screens; dashboard and 8 pages in EN / GU / HI | ✅ 4 UI bugs fixed (table below). Screenshots: `docs/screenshots/2.5/` (plans editor, plans list, customer detail + screens tab, your plan, not in plan, roles editor / list, custom-role user, All screens before / during / after a move, customer dashboard EN / GU / HI, TV preview) |
| Test infrastructure | `RolesTest` failed intermittently (9 tests, HTTP 500 "Class FeaturesReal not found"): its stub swap waited 2.6 s, but OPcache trusts a file for `revalidate_freq` whole seconds → wait 3.3 s |

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | Medium | After a plan downgrade, time-based rules of removed modules kept running on the TVs: TV power schedules, holidays, scheduled content windows and one-time pushes, device schedules (volume / input / restart / announcements), presence idle-off | Ignored / not fired while the feature is off (`ContentResolver`, `Broadcaster::processSchedules`, `DeviceSchedules::tick`, `Presence::idleTick`); kept, so they work again after an upgrade |
| 2 | Medium | A moved TV showed the old customer's command history (incl. message / announcement texts) and status log on the new customer's TV page (`device_commands` has no `hotel_id`) | Commands of the TV are deleted on a move to another customer; the status log on the TV page is scoped to the customer |
| 3 | Medium | Saving a customer whose plan had been deactivated silently switched it to "No plan" (= every feature, unlimited, not billed); resellers could give their customers "No plan" or an inactive plan by POST | Current plan stays selectable ("(inactive)"); resellers must choose an active plan or keep the current one |
| 4 | Low | Scheduling content on the Broadcast page only checked `schedule.manage`, which TV power schedules also grant → scheduling without "Schedules & calendar" | `Features::require('schedule')` + option hidden |
| 5 | Low | `ajax.php?action=send_command` accepted every device command (SPEAK, PLAY_SOUND, SCREENSHOT, UPLOAD_LOGS, SET_VOLUME, SHOW_MESSAGE …) without the permission / plan checks of their own pages | Same whitelist as the Broadcast page |
| 6 | Low | Moving TVs between customers was allowed to resellers (requirement: platform admins only) | New permission `platform.move` (platform admins); `PlatformScreens::move` refuses non-platform users too; reseller UI hides "Move" |
| 7 | Low | The unassigned pool had no size limit (a leaked platform key could fill `device_pool`) | `DevicePool::MAX_WAITING` = 500 → `409 POOL_FULL` for new TVs |
| 8 | Low | With "Content approval" removed from the plan, the old "require approval" setting still held staff content back (no approvals page to release it) | `Approvals::enabled()` also needs the feature |
| 9 | UI | Menu board: "Active (room service and TV)" and the room-service hint shown without Hospitality | "Active (shown on TV)" / hint hidden unless `room_service` is in the plan (gu / hi translated) |
| 10 | UI | Relative times mixed English units into GU / HI ("5h પહેલાં"); Hindi lacked ~345 strings Gujarati had (login errors, access denied, TV power, APK manager, backups / updates, dashboard widgets, push notifications, TV support / controls) | `time_ago()` uses whole translated phrases; `lang/hi_admin_more.php` |
| 11 | UI | JS error "Cannot read properties of null (reading 'style')" when typing the admin password on the new-customer form | Strength meter markup fixed + guard in `admin.js` |
| 12 | UI | Customer detail header "Customer #3 · · Starter QA" (empty city) | Empty parts skipped |

Not changed (by design / open): screen records without a TV are free — limits count TVs (`max_screens` = registered TVs), so adding / bulk-adding screens is not limited; a non-Admin role manager may add or remove permissions they hold themselves on other roles (documented, tested in `RolesTest`); the "Basic" / "Standard" / "Premium" plans of 2.0 keep `features = NULL` (= everything) also on fresh installs (only the new presets Business / Pro / Hospitality are restricted); Hindi still falls back to English in Ads, Hospitality (guests), Ad marketplace, Chains, Platform and Sign-up pages; All screens table needs horizontal scrolling below ~1600 px width.

---

# 2.4.1 — emergency alarm sound + notice chime (2026-10-08)

Emergency: beep / siren / fire alarm / library sound, repeating until stopped (or N times), alarm volume (TV raised, restored after), "Silence alarm" button. Messages and notice board: optional chime. Built-in sounds synthesised in-house (`tools/sounds/make_alarm_sounds.php`).
PHPUnit ✅ 520 tests, 0 failures (EmergencyAlarmTest 12 new) · Android ✅ 226 tests, lint 0 errors, APK `KrishnaCloud-TV-2.4.1.apk` (code 12), same signing key. Not verified here: loudness / volume restore / standby behaviour on real TVs.

---

# 2.4.0 — features 26–50 (2026-10-08)

| Area | Result |
|------|--------|
| PHPUnit full suite | ✅ **508 tests, 19,267 assertions, 0 failures** (run twice in a row by QA, no flakiness; 3 flaky / time-of-day tests found earlier and fixed) |
| Android unit tests (sync clock/plan, wall geometry, USB playlist, announcer queue, health, CEC, RELOAD-to-front) | ✅ **216 tests, 0 failures**; lint 0 errors; APK `KrishnaCloud-TV-2.4.0.apk` (code 11), same signing key |
| Web player end-to-end (headless Chromium) | ✅ 40/40 checks (QR + manual setup, playlist/app/layout/ticker, emergency, commands, reload, offline) |
| Browser QA: 5 new display apps × en/gu × 1080p/720p × 3 themes; every new admin page × 4 roles; phone width; calendar drag/drop, approvals, holidays, video wall 2×2, sync, device schedules, presence, play report, live view, TV health | ✅ 9 bugs fixed (web players offered APK updates, AQI/panchang/festivals overflow, broken Gujarati/Hindi characters from byte-wise trim in 10 files, wrong "Plays" translation, initials, spacing) + web player clock now uses the hotel time zone |
| Security review (presence webhook, live view uploads, web player, sounds, CSV, data feeds, approval bypass, tenancy, Android payloads) | ✅ 4 issues fixed (PLAY_SOUND restricted to the sound library — no LAN fetches, approval checks for ads/guide/app images, live frames re-encoded, CSV paste size limit) + regression tests |

Not verified here (needs hardware): sync accuracy and video walls on real TVs, HDMI-CEC, USB drives, TTS voices, Raspberry Pi, real Open-Meteo / Google APIs.

---

# 2.3.0 — 25 new features (2026-10-08)

| Area | Result |
|------|--------|
| PHPUnit full suite | ✅ **442 tests, 16,072 assertions, 0 failures** |
| Android unit tests (layouts, decoder planner, YouTube URLs, ticker) | ✅ **173 tests, 0 failures**; lint 0 errors; APK `KrishnaCloud-TV-2.3.0.apk` (code 10), same signing key |
| Browser QA (headless Chromium, live sandbox): all 19 display apps × en/gu × 1080p/720p, 3 themes, live updates, queue end-to-end with two counters, every admin page × 5 roles, phone-width pages, designer, PDF import, album, layout editor, TV poll API (new + old app) | ✅ 12 bugs found and fixed (heading sizes, text fitting, offers overflow, YouTube playlist embeds, simulator ticker height, queue/gold/currency/KPI overflow, kiosk number wrap, ticker preview JS error) |
| Security review of new public endpoints (display/, queue self-service, guest album upload, KPI push API, SSRF in sheet/data feeds, uploads, tenancy, CSRF) | ✅ 3 issues fixed (shared API quota from previews, refresh TTL, guest GIF re-encode) + regression tests |

Screenshots: `docs/screenshots/2.3/`. Not verified here: real TV hardware (multi-video decoders, speech, autoplay), real provider APIs, YouTube/Instagram/Facebook embeds (no internet in the test environment), thermal printers.

---

# 2.2.2 — no black side bars next to the ticker (2026-10-06)

With "Do not cover the video" on, the video used to keep its shape in the smaller area, which left black bars at the left and right. New ticker option **video_scale** (`fill` default / `fit` / `zoom`): `fill` uses the whole width and height (nothing cut, slightly squeezed). Migration `012_ticker_video_scale.sql`.
PHPUnit ✅ 367 tests, 0 failures · Android ✅ 143 tests, lint 0 errors, APK `KrishnaCloud-TV-2.2.2.apk` (code 9).

---

# 2.2.1 — product renamed to "Krishna Cloud LED TV" (2026-10-06)

Visible name changed in the admin panel, login, installer, PWA, notifications, setup tool (`KrishnaCloud-Setup.bat`) and TV app label. Migration `011_product_name.php` replaces the old default name on existing servers and leaves custom white-label names unchanged. Package name and update key are unchanged, so installed TVs update in place.
PHPUnit ✅ 367 tests, 0 failures · Android ✅ 142 tests, lint 0 errors, APK `KrishnaCloud-TV-2.2.1.apk` (code 8), same signing key.

---

# HotelCast 2.2.0 — Test summary (2026-10-06)

| Area | Result |
|------|--------|
| PHPUnit full suite | ✅ **367 tests, 11,071 assertions, 0 failures** |
| Ticker bar (TickersTest): all / group / room, override, priority, date range, daily + overnight windows, days, legacy setting + upgrade migration, exact TV contract, hash change, page CRUD / XSS / CSRF, tenancy, restricted users | ✅ |
| Per-user TV access (UserAccessTest): limited staff / manager see only their TVs; 50+ refused actions return 403 with data unchanged; allowed actions work; users form; role names; chains switch; crawl of every page without PHP warnings | ✅ |
| Android unit tests (ticker layout: reserve space top/bottom, clamping, old-server ticker, server fixture) | ✅ **142 tests, 0 failures**; lint 0 errors; APK 2.2.0 (code 7) signed with the same key |

Not verified here: the ticker on a physical TV (no emulator): re-layout without re-buffering, scroll smoothness with Gujarati text.

---

# HotelCast TV app 2.1.1 — QR setup fix for old TVs (2026-10-06)

Problem: on some TVs the setup screen stayed on "Offline – check Wi-Fi" with an empty white box although
Wi-Fi was connected. The live server answered `provision/start` correctly, so the TV could not complete
the HTTPS connection. The usual causes are old root certificates on Android 7.0 and earlier, or a wrong
TV date.

| Change | Test |
|--------|------|
| Bundled current public roots (ISRG X1/X2, USERTrust, GTS, DigiCert G2, …) in addition to the system list | ✅ TlsCompatTest (15 roots parse, valid) |
| Wrong TV clock: network time from plain-HTTP `Date` headers; chain re-validated at that time; device owner (Android 9+) sets the clock | ✅ CompatTrustManagerTest (accepted at network time, rejected without it / unknown root / outside validity) |
| Setup screen shows the real reason (DNS, HTTPS error, wrong date) and buttons "Fix date & time" / "Connect without HTTPS" | ✅ build + lint 0 errors |
| QR claim keeps `http://` when the TV had to use it | ✅ TlsCompatTest |
| No empty white box / "— — —" while there is no code | ✅ |
| Android unit tests | ✅ **126 tests, 0 failures**; APK 2.1.1 (code 6) signed with the same key |

Not verified here: the fix on the physical TV (no emulator in this environment).

---

# HotelCast 2.1.0 — Test summary (2026-10-06)

| Area | Result |
|------|--------|
| PHPUnit full suite | ✅ **348 tests, 10,168 assertions, 0 failures** |
| QR TV setup (ProvisioningTest): start → claim on phone → TV registers → used; expiry, rate limits, cross-hotel | ✅ |
| Sign-up / trial / demo (SignupDemoTest): OTP, abuse limits, trial expiry → paused TVs, upgrade → paid, demo write-block on every admin POST/AJAX | ✅ |
| Ad marketplace (MarketplaceTest): pricing math, booking lifecycle, ad only on booked hotels, reports, payouts, advertiser/admin session separation | ✅ |
| Hotel chains (ChainsTest): aggregates, publish with media copy, chain broadcast only inside the chain, isolation | ✅ |
| Android unit tests (incl. QR setup state machine, server contract) | ✅ **117 tests, 0 failures**; APK 2.1.0 signed with the 1.x key |

Not verified here: real TVs/phones (QR scan → TV registration on a live network), OTP email delivery, UPI payment apps.

---

# HotelCast 2.0.0 — Test summary (2026-10-06)

| Area | Result |
|------|--------|
| PHP lint, every file | ✅ 0 errors |
| PHPUnit full suite (unit + HTTP integration) | ✅ **289 tests, 7,163 assertions, 0 failures** |
| Multi-hotel isolation (TenancyTest): every page / AJAX / API with another hotel's ids | ✅ 403/404, data unchanged |
| Upgrade of a live 1.2.0 database to 2.0 (MigrationUpgradeTest) incl. resume after interruption | ✅ data kept, old TV token + login still work |
| Platform: plans, TV limits, suspension, invoices, payments, auto-suspend, resellers, license server/client | ✅ (PlatformTest) |
| Guests / PMS / guest web app / orders / feedback | ✅ (GuestsTest, GuestServicesTest) |
| Ads, sponsor report, analytics, 28 templates (XSS-escaped fields) | ✅ (AdsAnalyticsTest, TemplatesTest) |
| PWA, web push (RFC 8291 Appendix A vector matches exactly), support uploads, TV controls, setup file | ✅ (PwaSupportDevicesTest, WebPushTest) |
| Server → TV contract: real server Content (all modules on) parsed by the app's own models | ✅ (ContractFixtureTest → ServerContractTest) |
| Android unit tests | ✅ **91 tests, 0 failures**; lint 0 errors; signed with the 1.x key (OTA update works) |
| Windows bulk setup tool | ✅ parses (PowerShell 7) and full flow verified with a simulated adb |
| Release zip | ✅ identical to source (minus tests), installer opens, /core blocked |

Not verified here (needs real hardware / browsers): Live TV / HDMI switching, PixelCopy screenshots and
volume on specific TV models, wake from standby per model, real web-push delivery to a phone, email/WhatsApp
delivery, Apache .htaccess on the target host.

---

# HotelCast 1.0.0 — Test Report

Date: 2026-10-05 · Environment: PHP 8.3.6 (CLI + built-in server), MariaDB 10.11, Ubuntu 24.04,
JDK 21, Android Gradle Plugin 8.5.2. Apache itself was not available in the test container; its
`.htaccess` routing was emulated with `hotelcast/tests/router.php`.

## 2.0.0 — multi-hotel foundation (server)

PHPUnit: ✅ **220 tests, 3,978 assertions, 0 failures** (PHP 8.3.6, MariaDB 10.11). New suites:

* **TenancyTest** — two hotels; every admin page / AJAX action / API endpoint used by hotel A's super
  admin with hotel B's room, group, content, playlist, schedule, power schedule, emergency, APK,
  device and user ids answers 403/404, a snapshot of all of hotel B's data is unchanged afterwards,
  list pages never show B's data, B's TV never receives A's emergency / content / ticker, device
  tokens, command ids and APKs are hotel-bound, the registration key decides the hotel.
* **PlatformTest** — hotel create / edit / enter / leave through the UI, TV limit (`LICENSE_LIMIT`),
  suspension (TV `mode: suspended`, `HOTEL_SUSPENDED` on registration, read-only admin, billing still
  open), expiry, invoice numbers / amounts / 18 % tax, idempotent monthly generation, overdue reminder
  + auto-suspend, payment → automatic reactivation, reseller isolation / allowance / commission, license
  server (binding, reset, expired, revoked, rate limit), license client (valid, 24 h cache, 14-day
  offline grace, invalid, unlicensed 2-TV demo, real loopback check), branding in TV content and login.
* **MigrationUpgradeTest** — a 1.2.0 database (001 only + data) upgraded in place: all rows kept and
  in hotel 1, settings split hotel / platform, first super admin → platform admin, per-hotel unique
  keys, old media paths, interrupted migration resumed, upgraded app works over HTTP (old TV token,
  old registration key, owner login incl. updater).
* **ExtensionPointsTest** — nav.d, dashboard.d, ajax.d, api/routes, content extensions, tasks,
  lang module files (gu / hi), boot.d tenant tables and permissions.
* Every admin page was crawled as platform admin, reseller (also inside its hotel), super admin,
  manager, staff and reception: expected 200 / 302 / 403 everywhere, no PHP warnings
  (`logs/php_error.log` is asserted empty by the suites).

## Summary (1.x)

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
