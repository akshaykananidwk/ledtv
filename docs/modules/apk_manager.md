# Super Admin → APK Manager and the forced update on app start (2.7 server / 2.6.0 TV app)

Owner's request (Gujarati, translated): *"In the Super Admin console there must be an 'APK Manager'. When we
build a new app version we upload it there once and press update for everyone. On every TV, whenever the app is
started and a newer app exists, it MUST update first — it can never be used without updating. A TV that is
already running is fine, but once it is closed and opened again it has to update."*

## Files

| Part | Files |
|---|---|
| Console page | `admin/platform_apk.php` (permission `platform.manage` = platform admin only), sidebar item `platform_apk` in `admin/partials/nav.d/50_platform.php`, group "Devices & content" after "Devices & screens" (`core/Panel.php`) |
| Logic | `core/AppReleases.php` (precedence, required version, upload validation, delete rules, "Update all TVs now", fleet numbers), `core/ApkInfo.php` (reads the APK without the Android SDK) |
| Database | migration `034_platform_apk.sql`: `apk_releases.hotel_id` nullable (NULL = platform release), new columns `is_required`, `rollout` (`all` / `selected`), `package_name`, `signer_sha256`, `downloads`; table `apk_release_hotels` (release → selected customers) |
| Device API | `GET api/device/app-version`, `app_update` in the poll response, `GET api/device/apk/{id}` accepts platform releases + `Range` (resume) — `api/index.php`, docs/API.md |
| Other pages | Overview tile "Outdated app" (sub-text "Latest: v…", links here); Devices & screens "Needs update" filter / counters and "Update app" bulk action, customer APK Manager, customer TV support, Platform → Support use the same precedence |
| Translations | `lang/gu_apk_manager.php`, `lang/hi_apk_manager.php` |
| TV app | `UpdateGate.kt` (pure decision logic, unit tested), `UpdateCheck.kt` (server check + cache + background prefetch, `UpdateStatus`), `UpdateActivity.kt` + `res/layout/activity_update.xml` ("Update required" screen), `AppUpdater.kt` (resumable verified download, gate install), `MainActivity.kt` (gate), `Prefs.kt` (cache), strings in `res/values{,-gu,-hi}/strings.xml` |
| Tests | `tests/Integration/Apps/PlatformApkTest.php` + fixtures `tests/fixtures/apk/*.apk`; `android/app/src/test/.../UpdateGateTest.kt` |

## Releases and precedence

* A **platform release** is uploaded once in Super Admin → APK Manager (`hotel_id` NULL). Roll-out: **all
  customers** (default) or **selected customers** (`apk_release_hotels`).
* A **customer release** is uploaded in the customer's own APK Manager (plan feature "TV app updates"); it keeps
  working as before and is only visible to that customer.
* For a TV the server offers the **highest `versionCode` among {platform releases rolled out to its customer, its
  customer's own releases}** (tie → the platform release). So the platform release wins unless the customer
  uploaded a higher version itself.
* **Required update (force)**, default ON for a platform upload: `required_version_code` is the highest
  `versionCode` of a required release in the same set. Customer releases are optional (as before 2.7).
* Web players (`devices.platform = 'web'`) never get an APK: `update` is `null`, no `app_update` in the poll, the
  APK download answers 404 and "Update all TVs now" skips them (the 2.4 bug stays fixed).

## Upload validation (no aapt needed)

`ApkInfo::read()` opens the file as a ZIP, parses the **binary AndroidManifest.xml** (string pool UTF-8 or UTF-16,
`<manifest>` attributes `package`, `versionCode`, `versionName`) and reads the **APK Signing Block** (v3, else v2)
to get the SHA-256 of the first signer's certificate — the same value `apksigner verify --print-certs` prints.
Refused, with a message: not a ZIP / no manifest, package ≠ `com.hotelcast.tv`, no v2/v3 signature, a signer other
than the expected one (platform setting `platform_apk_signer`, default
`b0f2c89990dcc8376790e2d6add7f9830f3990f1e8e44abb29ef7eb1dc1a156c`, editable on the page, `-` switches the check off),
`versionCode` not higher than the latest platform release, more than 300 MB. The version name / code are never typed
by hand. Stored: `sha256` of the file (sent to the TV), package, signer, size, uploader.

The signer check compares the certificate the APK *declares*; the cryptographic signature is verified by Android on
install (a differently signed update fails with `INSTALL_FAILED_UPDATE_INCOMPATIBLE`, shown on the TV as
"signature mismatch"). The check here just stops a wrong build before it reaches TVs.

Server note: the PHP upload limit (`upload_max_filesize` / `post_max_size`) must be larger than the APK (~7.5 MB);
the page shows the effective limit.

## Page

KPI tiles (latest app, TVs on the latest version / outdated against each customer's effective latest, online
Android TVs), upload card (file, Required switch, roll-out, notes, progress bar), signing certificate card, release
list (Latest / Required / Optional badges, size, roll-out, **downloads** (full downloads counted by the APK
endpoint), **installed** (active Android TVs reporting that `versionCode`), uploader, options row to change
Required / roll-out / notes, delete), version distribution bars. **"Update all TVs now"** (with a confirmation)
queues `UPDATE_APP` with each customer's effective latest release for every online, active Android TV that has a
screen and reports an older version; an older pending `UPDATE_APP` of the TV is replaced; the result says how many
TVs / customers got it and how many were already up to date. The latest platform release cannot be deleted (TVs
may be downloading it); older ones can (file removed). Every action is in the audit log at platform level.

## TV app 2.6.0: forced update on start

1. **Cold start** (`MainActivity.onCreate`: app launch, after boot, after a crash or update): nothing is played;
   the welcome layer shows "Checking for app updates…" while `GET device/app-version` runs (≤ 6 s).
2. **Decision** (`UpdateGate.decide`):
   * server answered → its answer counts (an empty answer clears the cache);
   * server not reachable → the **cached** answer (Prefs, refreshed by every poll) counts;
   * no answer and no cache → start normally (an offline TV keeps showing its cached content);
   * **block only** when the offered `version_code` > installed **and** `required_version_code` > installed
     (never for an equal / lower version, never without a URL).
3. **Blocked** → "Update required" screen (`UpdateActivity`): product name and brand colour, installed → new
   version, progress bar, status text. It downloads the APK (**resumes** a partial file with `Range`, checks free
   space, verifies **sha256**, package and versionCode) and installs it: **silently** with a PackageInstaller
   session when the app is device owner, otherwise through the **system installer** confirmation (same session;
   "Install unknown apps" must be allowed — the screen says how and has an "Allow installs" button). After the
   install Android restarts the app (BootReceiver `MY_PACKAGE_REPLACED`) and the gate lets the new version play.
4. **Cannot be dismissed**: BACK does nothing. On a TV that is not device owner HOME keeps its normal Android
   behaviour, but the player never plays content while outdated — opening it again shows the screen again (where
   the player is the TV's home app, HOME itself returns to the update screen).
5. **Running TV** when a release is uploaded: keeps playing (owner: fine). The poll caches the new
   `app_update` and downloads a required APK in the background, so the **next start** (also: coming back from
   standby / Live TV / HDMI — `onStart` decides from the cache without waiting) installs it, even without
   internet at that moment. "Update all TVs now" / the existing `UPDATE_APP` push updates running TVs immediately.
6. **Failures** — clear text per case (download failed / no free space / damaged file / wrong app file /
   installing blocked → how to allow "Install unknown apps" / **signature mismatch** / cancelled / install failed)
   and a "Try again" button. Download problems retry automatically every 30 s. Install failures retry
   automatically up to **3 times per version**; after that the screen stays with the error and "Try again"
   (no endless install loop). **Choice:** the app never falls back to playing the old version — the owner wants
   no use without the update; the error tells the operator what to fix (e.g. upload a correctly signed APK).
   Failures are written to the TV's error log (Support → logs).

### How existing 2.5.0 TVs get 2.6.0

TVs on 2.5.0 have no start gate yet. Upload `KrishnaCloud-TV-2.6.0.apk` (versionCode 14, Required ON) and press
**"Update all TVs now"** once: 2.5.0 TVs install it through the existing `UPDATE_APP` command (silent on
device-owner TVs, system installer otherwise). From then on every app start is gated. TVs that were offline get the
command when they come back (it stays pending).

## Safety notes / open points

* Emergency alarms are not shown while the update screen is up (nothing of the player runs); the update normally
  takes about a minute. An emergency broadcast to an outdated TV that cannot install (e.g. signature mismatch) is
  therefore not shown on that TV — fix the release.
* An offline TV that already knows a required version (cache) blocks until it can download — unless the APK was
  already prefetched in the background, in which case it installs offline.
* Platform → Support "Outdated app" per customer compares with the latest platform release (a release rolled out to
  selected customers only makes other customers look outdated there); Devices & screens / Overview / APK Manager use
  the exact per-customer precedence.
* Rolling back (making an older version "latest") is not supported: TVs only install a higher `versionCode`.
