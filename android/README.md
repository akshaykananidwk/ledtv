# HotelCast TV – Android TV client

Kiosk player for the HotelCast hotel-TV management system. It registers with the HotelCast
server, polls for content and commands every few seconds, and plays the room's playlist full screen
(images, videos, live streams, timetables / HTML / web pages / YouTube, announcements, clocks) with a
logo / clock / weather / ticker overlay and full-screen emergency broadcasts. The server contract is
in [`../docs/API.md`](../docs/API.md).

| | |
|---|---|
| Package / applicationId | `com.hotelcast.tv` |
| Version | 1.0.0 (versionCode 1) |
| Android | 5.0 (API 21) and newer, targetSdk 34 |
| Signed release APK | `release/HotelCast-TV-1.0.0.apk` |

---

## 1. Building

Requirements: JDK 17 or 21, Android SDK with `platforms;android-34` and `build-tools;34.0.0`.

```bash
cd android
echo "sdk.dir=/path/to/android-sdk" > local.properties    # or export ANDROID_HOME
./gradlew assembleRelease             # signed APK -> app/build/outputs/apk/release/app-release.apk
./gradlew testReleaseUnitTest         # JVM unit tests
./gradlew assembleAndroidTest         # build instrumentation tests
./gradlew connectedAndroidTest        # run them on a connected TV or emulator
```

Toolchain: Gradle 8.7 (wrapper), Android Gradle Plugin 8.5.2, Kotlin 1.9.24.

### Signing

`app/build.gradle.kts` reads `keystore.properties` from the project root:

```properties
storeFile=keystore/hotelcast-release.jks
storePassword=...
keyAlias=hotelcast
keyPassword=...
```

If that file does not exist, it falls back to environment variables (useful on CI):
`HOTELCAST_KEYSTORE`, `HOTELCAST_KEYSTORE_PASSWORD`, `HOTELCAST_KEY_ALIAS`, `HOTELCAST_KEY_PASSWORD`.

> **Keep `keystore/hotelcast-release.jks` and its password safe.** Android only installs an update
> over an existing app if both are signed with the **same key**. If you lose the key, every TV has
> to uninstall and reinstall the app by hand. Because this repository is **public**, the keystore
> and `keystore.properties` are git-ignored and were delivered to the owner privately — store them
> offline (password manager / USB drive). To build a release, put them back into `android/` (or set
> the environment variables). For GitHub Actions, add the base64 keystore and passwords as
> repository secrets (`HOTELCAST_KEYSTORE_BASE64`, `HOTELCAST_KEYSTORE_PASSWORD`,
> `HOTELCAST_KEY_ALIAS`, `HOTELCAST_KEY_PASSWORD`); without them CI builds an unsigned APK.

Check a signature with:

```bash
$ANDROID_HOME/build-tools/34.0.0/apksigner verify --print-certs release/HotelCast-TV-1.0.0.apk
```

---

## 2. Installing on a TV (sideloading)

### a) With adb (network or USB)

1. On the TV: *Settings → Device Preferences → About*. Select **Build** 7 times to enable
   developer options.
2. *Settings → Device Preferences → Developer options*. Turn on **USB debugging** and/or
   **Network debugging**.
3. From a PC on the same network:
   ```bash
   adb connect 192.168.1.45:5555          # the TV's IP address
   adb install -r HotelCast-TV-1.0.0.apk
   ```

### b) With a USB pen drive and a file manager

1. Copy `HotelCast-TV-1.0.0.apk` to a FAT32/exFAT pen drive and plug it into the TV.
2. Install a file manager on the TV, such as *File Commander*, *X-plore* or *FX File Explorer*.
3. Allow unknown sources:
   * Android 8 and newer: *Settings → Apps → Security & restrictions → Unknown sources* (or *Install
     unknown apps*), then enable it for the file manager.
   * Android 5–7: *Settings → Security & restrictions → Unknown sources → On*.
4. Open the APK in the file manager and select **Install**.

### First run

With no registration yet, the app opens the **setup screen** directly, without a PIN. Enter these
three values, then select **Save & Register**:

* **Server URL**: the HotelCast install address, for example `https://hotel.com/hotelcast` or
  `http://192.168.1.10/hotelcast`. The app appends `/api/` itself. Scheme-less input is accepted:
  LAN IPs get `http://` and domain names get `https://`. If you paste a URL that already ends in
  `/api`, `/admin` or `index.php`, the app strips it.
* **Room number**, for example `101`.
* **Registration key**, from *Admin → Settings → Devices*.

**Test Connection** calls `GET /api/health` and shows the server version and database status.

---

## 3. Make it the TV's launcher (HOME)

The app declares `HOME`, `LAUNCHER` and `LEANBACK_LAUNCHER` intent filters.

* **Simple:** press the remote's HOME button once after installing. If the TV asks which launcher
  to use, choose **HotelCast TV → Always**.
* **Over adb (Android 7 and newer):**
  ```bash
  adb shell cmd package set-home-activity com.hotelcast.tv/.MainActivity
  ```
* Some Google TV / Android TV 12+ builds hide the launcher chooser. On those, use device-owner mode
  (next section), which forces HotelCast as the permanent HOME app.

The app also starts after a reboot (`BOOT_COMPLETED`, `LOCKED_BOOT_COMPLETED`, `QUICKBOOT_POWERON`)
and after an update (`MY_PACKAGE_REPLACED`). On Android 10 and newer, the system only allows a
background app to open a screen if one of these is true:

* the app is the HOME app,
* the app is the device owner, or
* the app may draw over other apps. Grant that once with:

  ```bash
  adb shell appops set com.hotelcast.tv SYSTEM_ALERT_WINDOW allow
  ```

---

## 4. Full kiosk: device-owner mode (recommended)

Device-owner mode enables these features:

* **Lock-task kiosk.** HOME, recents and the status bar are blocked, and guests cannot leave the
  app.
* **Permanent HOME app**, set with `addPersistentPreferredActivity`.
* **Silent OTA updates.** `UPDATE_APP` installs without any prompt and the app restarts by itself.
* **Remote reboot.** `REBOOT` uses `DevicePolicyManager.reboot()` on Android 7 and newer.

Setup: the TV must have **no Google or other accounts** (factory reset it if needed). Then run:

```bash
adb install -r HotelCast-TV-1.0.0.apk
adb shell dpm set-device-owner com.hotelcast.tv/.AdminReceiver
adb shell am start -n com.hotelcast.tv/.MainActivity
```

The settings screen shows *Device owner (full kiosk): yes*.

To remove device owner later (for example before returning a TV):

```bash
adb shell dpm remove-active-admin com.hotelcast.tv/.AdminReceiver   # only works on debuggable builds / test-only admins
# otherwise: factory reset the TV
```

### Reboot fallback chain

`REBOOT` tries each method in turn until one works:

1. `DevicePolicyManager.reboot()` (device owner, Android 7 and newer).
2. `PowerManager.reboot()` (system-signed builds only).
3. `su -c reboot` (rooted boxes).
4. Restart the app process through `AlarmManager` (last resort).

### Update fallback

On a TV that is not device owner, `UPDATE_APP` downloads the APK and checks it before installing:

* the download carries the auth headers,
* the SHA-256 must match the server's value,
* the package name must be `com.hotelcast.tv`.

It then opens the system installer (`ACTION_INSTALL_PACKAGE` through a `FileProvider`). Someone
with the remote has to confirm the install. On Android 8 and newer, allow **Install unknown apps**
for HotelCast TV once. The app opens that setting itself if it is missing.

---

## 5. Opening settings on a running TV (hidden gesture)

Guests cannot see a settings button. Staff open settings with any of these gestures:

| Remote / input | Gesture |
|---|---|
| Remote with a MENU (☰) key | Press **MENU** |
| Any remote | Press **BACK 5 times** within 3 seconds |
| Any remote | Press **UP, UP, DOWN, DOWN** on the D-pad |
| Any remote | **Hold OK / centre** for 3 seconds |
| Touch screen | Tap the **top-left corner 5 times** within 3 seconds |

A **PIN** prompt appears next:

* **Default PIN is `1234`.** It applies only before the first registration.
* After registration, the PIN is the one set on the server. The app checks it against the
  `settings_pin_hash` (SHA-256) from `register` and from every heartbeat, so a PIN change in the
  admin panel reaches the TV within one heartbeat.
* After 5 wrong attempts the prompt locks for 60 seconds.

### The settings screen

The screen works with the D-pad: large, focusable fields and buttons with a yellow focus ring.

* Server URL, room number and registration key.
* **Save & Register**: registers or re-registers the TV and rotates its token.
* **Test Connection**.
* **Clear Cache**: deletes cached media and the content JSON, then downloads again.
* **Exit kiosk / Android settings**: stops lock-task for **10 minutes** and opens Android settings.
  The player then returns to the front by itself.
* **Back to player** (or MENU / BACK).
* Device information: device ID, IP, network, app and Android version, model, registered room,
  connection status, last poll time, and device-owner state.

---

## 6. How it works

### Runtime components

| Component | Role |
|---|---|
| `MainActivity` | Kiosk UI: immersive full screen, keep-screen-on, lock-task, BACK blocked, hidden gestures, overlay, emergency / black / welcome layers |
| `ContentPlayer` | Renders the playlist (described below) |
| `SyncManager` + `PollService` | Poll loop, heartbeat loop, played-history batching and media prefetch, inside a foreground service (described below) |
| `PollWorker` | WorkManager watchdog every 15 minutes (the WorkManager minimum). Restarts the loop if Android killed it and sends one heartbeat and poll |
| `CommandHandler` | Runs the server commands (described below) |
| `ContentCache` | Stores the last Content JSON in `filesDir/content/` and media files in `filesDir/media/` (named by the SHA-256 of the URL). With no network on a cold start, the cached content and media play straight away. A small red dot (top right) means offline |
| `AppUpdater` | OTA install (see §4) |

#### What `ContentPlayer` renders

* **Images**: fade, slide or no transition, each for its `duration`.
* **Videos**: looped or played once, muted if requested. In a playlist, a `duration: 0` video
  moves on when it ends.
* **Streams**: HLS, RTSP, DASH or progressive, through ExoPlayer. A broken stream reconnects with
  backoff from 2 s up to 30 s; RTSP alternates between UDP and TCP.
* **WebView items**: `timetable` and `html` (with `refresh_sec`), `url`, and `youtube` (autoplay
  embed).
* **Announcements**: full screen or marquee.
* **Clocks**: digital or analog.

A failing item is skipped. If every item fails, the welcome screen shows and the player retries
after 60 s.

#### What the sync loop does

* Polls `GET device/command/{id}?hash=` every `poll_interval` (8 s by default; the server can set
  3–60 s). The loop backs off when the network fails and honours `Retry-After`.
* Sends a heartbeat every `heartbeat_interval` with these fields: IP, battery (`-1` when the device
  has none, as on most TVs), network type, Wi-Fi RSSI, free storage, content hash, current item,
  `screen_on` and uptime.
* Sends played history to `POST device/played` in batches.
* On `401 INVALID_TOKEN` it clears the token and returns to the setup screen.

#### Commands handled by `CommandHandler`

* The commands are `SHOW_CONTENT`, `REBOOT`, `CLEAR_CACHE`, `UPDATE_APP`, `SCREEN_OFF`,
  `SCREEN_ON`, `RELOAD` and `PING`.
* Every delivery is acked.
* Commands are de-duplicated by `id`. The ids persist, so a command never runs twice, even across
  restarts. A duplicate delivery is only re-acked.
* `REBOOT`, `RELOAD` and `UPDATE_APP` are acked **before** they act.

### Screen modes

| Content | Display |
|---|---|
| `emergency` / `emergency != null` | Full-screen message above everything, in the server's colours |
| `off`, `screen_on:false`, or `SCREEN_OFF` command | Black screen; the screen may sleep |
| `empty` (or no playable items) | Hotel logo, "Welcome to …" and the room number |
| anything else | Playlist plus overlay (logo top-left, clock and weather top-right, ticker bottom) |

### Languages

All text is UTF-8: content, the ticker (drawn with `Canvas.drawText`, which handles Gujarati
conjuncts), and WebView HTML (`loadDataWithBaseURL(..., "UTF-8")`). The UI strings come in English
(`res/values`) and Gujarati (`res/values-gu`), and Android picks the TV's system language.

### Network

Cleartext HTTP is allowed (`usesCleartextTraffic` plus `network_security_config`) for LAN servers,
and HTTPS also works. User-installed CAs are trusted, so a hotel can use a self-signed certificate
installed on the TV. Credentials (`Authorization`, `X-Device-Id`) go only to the configured server
host, never to a media CDN.

---

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| "Registration failed: INVALID_REGISTRATION_KEY" | Copy the key again from *Admin → Settings → Devices* |
| "Registration failed: ROOM_NOT_FOUND" | Create the room in the admin panel, or enable `auto_create_rooms` |
| "Cannot connect" / "Unknown host" | Check the Server URL with **Test Connection**. Make sure the TV is on the same network or VLAN as a LAN server. Open `http://SERVER/hotelcast/api/health` in a PC browser to compare |
| HTML 404 page on Test Connection | Apache `mod_rewrite` is off on the server. Enable it, since the app uses route-style URLs (`/api/device/...`) |
| SSL error | The certificate is expired, or self-signed and not installed on the TV. Install the CA on the TV or use `http://` on the LAN |
| Red dot in the top-right corner | The TV cannot reach the server. It keeps playing cached content and catches up when the network returns |
| TV went back to the setup screen | An admin revoked the device or it was re-registered elsewhere (401 INVALID_TOKEN). Register it again |
| Guests can leave the app with HOME | Make HotelCast the default launcher, or better, set it as device owner (§4) |
| App does not start after a power cut | Make it the HOME app, or grant `SYSTEM_ALERT_WINDOW` (§3), or set it as device owner |
| `UPDATE_APP` fails with "SHA-256 mismatch" | The upload was corrupted. Upload the APK again in the admin panel |
| `UPDATE_APP` fails with "Install unknown apps not granted" | Allow it once on the TV (the app opens the screen), or use device-owner mode for silent updates |
| Update installs but is rejected as "App not installed" | The new APK was signed with a different key. Sign it with `keystore/hotelcast-release.jks` |
| Stream stays black | Check the URL in VLC from a PC on the hotel network. RTSP cameras often need `rtsp://user:pass@ip:554/...`. The player retries every 2–30 s |
| YouTube item is black | The TV's Android System WebView is too old. Update it from the Play Store, or use a `video` or `stream` item |
| Forgot PIN | Change it in the admin panel; it reaches the TV with the next heartbeat (60 s by default). Or clear the app data with `adb shell pm clear com.hotelcast.tv` (this also clears the registration) |
| Logs | `adb logcat -s SyncManager ContentPlayer CommandHandler AppUpdater Kiosk BootReceiver` |
