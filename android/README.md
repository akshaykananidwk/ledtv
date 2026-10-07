# HotelCast TV – Android TV client

Kiosk player for the HotelCast hotel-TV management system. It registers with the HotelCast
server, polls for content and commands every few seconds, and plays the room's playlist full screen
(images, videos, live streams, timetables / HTML / web pages / YouTube, announcements, clocks) with a
logo / clock / weather / ticker overlay and full-screen emergency broadcasts. The server contract is
in [`../docs/API.md`](../docs/API.md).

| | |
|---|---|
| Package / applicationId | `com.hotelcast.tv` |
| Version | 2.3.0 (versionCode 10) |
| Android | 5.0 (API 21) and newer, targetSdk 34 |
| Signed release APK | `release/KrishnaCloud-TV-2.3.0.apk` (same signing key as 1.x / 2.x, signer SHA-256 `b0f2c899…1a156c`, so OTA `UPDATE_APP` from older versions works) |

What is new in 2.3: **split-screen layouts** (up to 6 zones, each looping its own items, see
[Split-screen layouts (2.3)](#split-screen-layouts-23)), **YouTube playlists and channels**
(autoplay, the whole list loops), and WebView settings for server-rendered **display apps** (menu
boards, token displays with a chime, countdowns): no reload on sync, retry with backoff instead of
the Android error page, text zoom fixed at 100 %.

What is new in 2.2: a separate **ticker bar** (bottom or top) whose text, colours, speed, font size
and height come from the server per TV. By default the content area shrinks so the bar never cuts
or covers the video (see [Ticker bar (2.2)](#ticker-bar-22)).

What is new in 2.1: **QR setup** — a new TV shows a QR code and a 6-character code; staff scan it
with a phone, pick the room in the admin panel and the TV registers itself. Nothing is typed on the
TV (see [QR setup (no typing)](#qr-setup-no-typing)). Settings has **Re-setup with QR** for moving a
TV to another room.

What is new in 2.0 (client side of `docs/V2_SPEC.md`): guest menu on the OK key (room-service /
feedback QR codes, Live TV, HDMI inputs, cast instructions, local guide), personal welcome card,
checkout reminder, suspended screen, white-label branding, volume policy with night limit, remote
screenshot / log upload, crash reports, sponsor-ad impressions, bulk provisioning by adb, and Hindi.
All new Content fields are optional — the app keeps working against a 1.x server.

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
$ANDROID_HOME/build-tools/34.0.0/apksigner verify --print-certs release/KrishnaCloud-TV-2.3.0.apk
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
   adb install -r KrishnaCloud-TV-2.3.0.apk
   ```

### b) With a USB pen drive and a file manager

1. Copy `KrishnaCloud-TV-2.3.0.apk` to a FAT32/exFAT pen drive and plug it into the TV.
2. Install a file manager on the TV, such as *File Commander*, *X-plore* or *FX File Explorer*.
3. Allow unknown sources:
   * Android 8 and newer: *Settings → Apps → Security & restrictions → Unknown sources* (or *Install
     unknown apps*), then enable it for the file manager.
   * Android 5–7: *Settings → Security & restrictions → Unknown sources → On*.
4. Open the APK in the file manager and select **Install**.

### First run

With no registration yet, the app opens the **QR setup screen** directly, without a PIN — see
[QR setup (no typing)](#qr-setup-no-typing). From there, **Enter details manually** opens the classic
setup form (still without a PIN before the first registration). Enter these three values, then
select **Save & Register**:

* **Server URL**: the HotelCast install address, for example `https://hotel.com/hotelcast` or
  `http://192.168.1.10/hotelcast`. Pre-filled with the built-in server (`https://ledtv.akdwk.in/`)
  when nothing is configured. The app appends `/api/` itself. Scheme-less input is accepted:
  LAN IPs get `http://` and domain names get `https://`. If you paste a URL that already ends in
  `/api`, `/admin` or `index.php`, the app strips it.
* **Room number**, for example `101`.
* **Registration key**, from *Admin → Settings → Devices*.

**Test Connection** calls `GET /api/health` and shows the server version and database status.
**Back to QR setup** returns to the QR screen.

### QR setup (no typing)

**English**

1. Install the APK (pen drive or adb, §2) and open **HotelCast TV**. A new TV shows a big QR code and
   a 6-character code (e.g. `AB3 K9Z`).
2. If the TV is not on the network yet, select **Open Wi-Fi settings**, connect, and press BACK —
   the screen shows the network state and retries by itself.
3. On your phone, **scan the QR code** with the camera.
4. **Log in to the admin panel** (if you are not already).
5. **Choose the room** and confirm. Within a few seconds the TV shows *Assigned to room 101 –
   connecting…*, then *Registered ✔* and opens the player.

No camera? Enter the code shown on the TV in the admin panel instead. The code is valid for the
time shown on screen (countdown); an expired code is replaced automatically.

Buttons on the QR screen (D-pad): **Enter details manually** (classic form), **Change server** (only
the server URL — for hotels running their own HotelCast server; the QR flow then continues against
that server; **Use default** goes back to the built-in one), **Open Wi-Fi settings**, and **Try again**
after a failed registration (e.g. `LICENSE_LIMIT`, `HOTEL_SUSPENDED`, `INVALID_REGISTRATION_KEY` are
explained on screen).

**ગુજરાતી**

1. APK ઇન્સ્ટોલ કરો (પેન ડ્રાઇવ અથવા adb, §2) અને **HotelCast TV** ખોલો. નવું ટીવી મોટો QR કોડ અને
   6 અક્ષરનો કોડ (દા.ત. `AB3 K9Z`) બતાવશે.
2. ટીવી હજી નેટવર્ક પર ન હોય તો **Wi-Fi સેટિંગ્સ ખોલો** પસંદ કરો, Wi-Fi જોડો અને BACK દબાવો — સ્ક્રીન
   નેટવર્કની સ્થિતિ બતાવે છે અને જાતે ફરી પ્રયાસ કરે છે.
3. તમારા ફોનના કૅમેરાથી **QR કોડ સ્કેન કરો**.
4. **એડમિન પેનલમાં લૉગ ઇન કરો** (જો પહેલેથી ન હોય તો).
5. **રૂમ પસંદ કરો** અને પુષ્ટિ કરો. થોડી સેકન્ડમાં ટીવી *રૂમ 101 સોંપાયો – જોડાઈ રહ્યા છીએ…*,
   પછી *નોંધણી થઈ ગઈ ✔* બતાવે છે અને પ્લેયર ખોલે છે.

કૅમેરા નથી? ટીવી પર દેખાતો કોડ એડમિન પેનલમાં દાખલ કરો. કોડ સ્ક્રીન પરના સમય (કાઉન્ટડાઉન) સુધી માન્ય
છે; સમય પૂરો થાય તો નવો કોડ આપમેળે આવે છે. ટીવી પર રિમોટથી કંઈ ટાઇપ કરવાની જરૂર નથી.

**Still needed once per TV** (QR setup does not replace these):

* **Installing the APK** itself still needs a pen drive + file manager or adb (§2).
* **Device-owner mode** (full kiosk, real standby, silent OTA updates, remote reboot — §4) still
  needs the one-time `adb shell dpm set-device-owner …` command or the Windows bulk tool (§10).
  QR setup only registers the TV with the server.

**How it works.** The TV calls `POST {api}/provision/start` with `device_id`, `model` and
`app_version` (no auth headers) and gets `code`, `secret`, `claim_url`, `expires_in` and
`poll_interval`. It draws `claim_url` as the QR code and polls `GET {api}/provision/status?code=…&secret=…`
every `poll_interval` seconds: `pending` → keep waiting; `claimed` → the answer carries `server_url`,
`room_number`, `registration_key` and `hotel_name`, which are validated like the bulk-tool extras,
saved, and registered through the normal `device/register` path (logged to `HotelCastSetup` as
`REGISTERED room=…` / `FAILED …`); `expired` / `used` / HTTP 404 → a new code is requested. Network
errors back off 2 → 4 → … → 60 s while the code stays on screen ("Offline – check Wi-Fi");
`429 RATE_LIMITED` waits for `Retry-After`. A server without the `provision/` endpoints shows
"This server does not offer QR setup" and the manual form remains available.

The built-in server comes from the Gradle property `hotelcastDefaultServer` (`gradle.properties`,
default `https://ledtv.akdwk.in/`) → `BuildConfig.DEFAULT_SERVER_URL`; it is used whenever the TV
has no server URL configured. Build for another default with
`./gradlew assembleRelease -PhotelcastDefaultServer=https://hotel.example.com/hotelcast/`.

Bulk provisioning still wins: if the setup tool's extras / `PROVISION` broadcast register the TV
while the QR screen is open, the QR screen closes and the player opens.

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
adb install -r KrishnaCloud-TV-2.3.0.apk
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
| Any remote | **Hold OK / centre** for 3 seconds (a *short* OK press opens the guest menu instead, §8) |
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
* **Re-setup with QR (move to another room)**: after a confirmation, unregisters the TV and opens the
  QR setup screen, so the TV can be assigned to a different room from a phone.
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
| `GuestUi` / `GuestMenuPanel` | Welcome card, checkout reminder, message card, guest menu and its QR / cast / content views (§8) |
| `CenterKeyTracker` | OK-key state machine: short press = guest menu, 3 s hold = settings |
| `InputSwitcher` | Live TV / HDMI switching chain (§8) |
| `VolumePolicy` / `VolumeController` | Default / max / night volume (§8) |
| `QrCodes` / `WifiQr` | On-device QR codes (ZXing core, Apache 2.0) |
| `LogCollector`, `CrashReporter`, `ScreenshotEncoder` | Support tools (§9) |
| `Provisioning`, `ProvisionReceiver`, `Registrar` | adb provisioning and registration (§10) |

#### What `ContentPlayer` renders

* **Images**: fade, slide or no transition, each for its `duration`.
* **Videos**: looped or played once, muted if requested. In a playlist, a `duration: 0` video
  moves on when it ends.
* **Streams**: HLS, RTSP, DASH or progressive, through ExoPlayer. A broken stream reconnects with
  backoff from 2 s up to 30 s; RTSP alternates between UDP and TCP.
* **WebView items**: `timetable` and `html` (with `refresh_sec`), `url`, and `youtube` (autoplay
  embed, single video, playlist or channel; see below).
* **Announcements**: full screen or marquee.
* **Clocks**: digital or analog.
* **Layouts** (2.3): split screen, see [Split-screen layouts (2.3)](#split-screen-layouts-23).

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
  `SCREEN_ON`, `RELOAD` and `PING`, and since 2.0 `SET_VOLUME {level}`, `MUTE`, `UNMUTE`,
  `SCREENSHOT`, `UPLOAD_LOGS`, `OPEN_INPUT {input}`, `SHOW_WELCOME` and
  `SHOW_MESSAGE {title, message, duration_sec}` (see §8–§10).
* Every ack carries a readable result (e.g. `Volume 25% (requested 60%, limited by night max)`,
  `Opened HDMI 2 via com.android.tv (passthrough)`); failures are acked as `failed` with the reason
  (e.g. `Player screen is not open (TV in standby, settings or another app)`).
* Every delivery is acked.
* Commands are de-duplicated by `id`. The ids persist, so a command never runs twice, even across
  restarts. A duplicate delivery is only re-acked.
* `REBOOT`, `RELOAD` and `UPDATE_APP` are acked **before** they act.

### Screen modes

| Content | Display |
|---|---|
| `emergency` / `emergency != null` | Full-screen message above everything, in the server's colours |
| `off`, `screen_on:false`, or `SCREEN_OFF` command | TV goes to standby (see *TV power* below); black screen as fallback |
| `suspended` (hotel suspended / licence expired) | Polite full-screen message (`suspended.title/message`, branding logo and support number); nothing plays |
| `empty` (or no playable items) | Hotel logo (or branding logo), "Welcome to …", the room number and the product name |
| anything else | Playlist plus overlay (logo top-left, clock and weather top-right) and the ticker bar (see below) |

### Ticker bar (2.2)

A scrolling text bar whose text, colours and size can differ per TV. The server sends it as
`content.overlay.ticker` (`null` = no bar):

```json
{ "text": "msg1   ✦   msg2", "messages": ["msg1", "msg2"],
  "speed": 5, "bg_color": "#000000", "text_color": "#FFD700",
  "font_size": 26, "height": 56, "position": "bottom", "reserve_space": true }
```

| Field | Meaning | Default / range |
|---|---|---|
| `text` | Text that scrolls (one line; newlines become spaces). If blank, `messages` joined with `   ✦   ` is used | blank and no messages = no bar |
| `speed` | 1 (slow) … 10 (fast); 30 + 25 × speed dp per second | 5, clamped 1–10 |
| `bg_color` / `text_color` | `#RRGGBB` | black / accent |
| `font_size` | sp | 26, clamped 14–72 |
| `height` | dp; grows automatically when the font needs more room (font × 1.5 + 8 dp) | 56, clamped 32–200 |
| `position` | `bottom` or `top` | `bottom` |
| `reserve_space` | `true`: the content area shrinks so the bar never covers the video. `false`: the bar is drawn over the content | `true` (also for older servers that send only text, speed and colours) |

Behaviour:

- **reserve_space true** (default): the stage (video, images, web pages), the welcome screen and all
  guest layers (welcome card, checkout reminder, messages, guest menu, full-screen details) get a
  margin of the bar height on the bar's side. Video stays aspect-fit (`RESIZE_MODE_FIT`), images
  `FIT_CENTER`, inside the smaller area, so nothing is cut or covered. Only the margins change, so a
  playing video is just re-laid out, never restarted or re-buffered.
- **reserve_space false**: the bar overlays the bottom (or top) of the content, as in 2.1.
- The logo / clock / weather overlay is always kept clear of the bar (with `position: top` it moves
  down below the bar).
- The bar is never taller than about a third of the screen; if that cap makes it too small for
  `font_size`, the text is drawn smaller instead of being clipped.
- Shown in normal and welcome (empty playlist) modes and while guest layers are open. Hidden (and
  the full screen given back to the content) during emergency, screen-off / black power-off,
  suspended and "connecting" (no content yet). The emergency, screen-off and suspended layers sit
  above the bar in `activity_main.xml` in any case.
- Every sync re-applies the ticker; an identical configuration is a no-op, so the text does not
  jump. A changed text restarts from the right edge; changed colours, speed or size keep the position.
- Long texts are split at spaces into chunks measured once; each frame draws only the chunks on
  screen, so a very long Gujarati / Hindi text scrolls as smoothly as a short one.
- Logic and geometry: `TickerSpec` / `TickerLayout` (`TickerLayout.kt`, unit tested in
  `TickerLayoutTest`); drawing: `MarqueeView`; wiring: `MainActivity.applyTicker`.

### Split-screen layouts (2.3)

A `type: "layout"` item carries `layout: { bg_color, zones: [...] }`. Each zone has `x`, `y`, `w`,
`h` in percent of the stage (floats 0..100), its own `items` (any normal item type, never another
layout), `loop`, `transition` (`fade` | `none`), `scale` (`fit` | `fill` | `zoom`, default `fit`)
and `mute`. Contract: `docs/API.md`.

- **Parsing**: `LayoutData` / `LayoutZoneData` (Gson, nullable, lenient booleans) are normalised by
  `LayoutSpec.from` (`LayoutSpec.kt`): positions and sizes clamped to 0..100 and to the stage edge,
  zero-size zones and zones without a playable item dropped, nested layouts and unknown item types
  dropped, at most 6 zones, unique zone ids. A layout with no usable zone is not playable.
- **Playback**: `LayoutPlayer` puts one sub-`FrameLayout` per zone into a `ZoneLayout` that places
  them by percent of the stage and re-lays them out whenever the stage changes size (ticker bar
  reserving space). Each zone runs its own `ContentPlayer` (zone mode) with a synthetic playlist, so
  images, video, streams, web pages, announcements and clocks behave as they do full screen. Zone
  `scale` maps to `ScaleMode` for video and images. Clocks in a zone size from the zone (the analog
  dial fills it; digital text follows the zone size).
- **Duration**: the layout item's own `duration` (60 s when 0) is how long it stays before the
  outer playlist moves on; the zones keep looping inside it. Analytics / play history report only
  the layout item (its id); zones never report.
- **Sound**: only one zone plays sound — the first zone with `mute: false` that has video, stream
  or YouTube (otherwise the first unmuted zone with a web page). Every other zone is muted (video
  volume 0, YouTube `mute=1`, web pages may not autoplay media).
- **Releasing**: when the layout ends, the content changes or playback stops, every zone player is
  released (ExoPlayers, WebViews, handlers). Zone decoders are freed before the next item starts.
- **Unchanged content**: if a sync changes the hash but the layout item on screen is identical, the
  layout keeps running (no restart).

#### Video decoder limits

Many inexpensive TVs and boxes run only one or two hardware video decoders at once. `DecoderPlanner`
(pure Kotlin, unit tested in `LayoutSpecTest`) gives decoders to at most **2** zones by default
(video, stream and YouTube all count): the sound zone first, then the other video zones in order.
A zone without a decoder plays its other items only; if it has none, it shows a black placeholder
with the item title. If an ExoPlayer in a zone still fails with a decoder error (ExoPlayer codes
4001–4004) while another zone is decoding, the TV lowers its limit (saved as `max_video_decoders`,
visible in uploaded logs) and that zone drops its video, without restarting the others. On a TV
with a limit of 1, put the important video in the unmuted zone. A failing video in the only video
zone is handled like a normal item error (skip / retry).

### YouTube playlists and channels

`youtube` items play `embed_url` (or `url`) in a WebView with the same settings as single videos.
`YouTubeUrls` (unit tested in `YouTubeUrlsTest`) handles:

- single videos `…/embed/ID` (adds `playlist=ID`, which YouTube needs for `loop=1` to repeat one
  video), playlists `…/embed/videoseries?list=PL…` and channel uploads `list=UU…` (the list loops
  as a whole);
- watch, `youtu.be`, `/shorts/`, `/live/`, `/playlist?list=` and `/channel/UC…` links (a channel
  becomes its uploads list `UU…`), converted to embeds.

The URL always gets `autoplay=1` and `loop=1`, `rel=0`, and (when the server did not set them)
`controls=0`, `playsinline=1`, `iv_load_policy=3`. `mute` follows the item / zone. Looping avoids
the end screen; YouTube still decides what `rel=0` shows if a list does end. The embed is loaded
with a `Referer` of the server's origin, since YouTube refuses embeds without one. A YouTube item
is black when the TV's Android System WebView is too old (see Troubleshooting).

### Display apps (web pages)

Server-rendered display apps (menu boards, token displays with a chime, countdowns, live widgets)
arrive as `type: "url"` items whose page polls JSON itself. The WebView:

- has JavaScript and DOM storage on, and `mediaPlaybackRequiresUserGesture = false` so a chime can
  autoplay (except in a muted layout zone);
- allows mixed content in compatibility mode (`http` images / media inside an `https` page on a
  hotel LAN; the page itself should be on the same scheme as the JSON it polls);
- has a black background while loading and a fixed text zoom of 100 %, so the TV's accessibility
  font scale does not break the layout;
- is not recreated when a sync changes the content hash but the item (same URL) is still in the
  playlist: the page keeps running and the playlist timing continues;
- is reloaded only when the item has `refresh_sec > 0` (as before; `0` or missing = never);
- on a failed main-page load (network error, or HTTP ≥ 400 for `url` items) shows black instead of
  the Android error page and retries after 5 s, 10 s, 20 s … then every 2 min (`WebRetryPolicy`).

### Languages

All text is UTF-8: content, the ticker (drawn with `Canvas.drawText`, which handles Gujarati
conjuncts), and WebView HTML (`loadDataWithBaseURL(..., "UTF-8")`). The UI strings come in English
(`res/values`), Gujarati (`res/values-gu`) and Hindi (`res/values-hi`); staff screens follow the TV's
system language. Guest-facing screens (welcome card, checkout reminder, guest menu, messages,
suspended screen) follow `guest.language` (`en` / `gu` / `hi`) from the server when present, via a
localized `Context` (`createConfigurationContext`), whatever language the TV itself is set to.

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
| QR setup: "Cannot reach the server" although Wi-Fi works (2.1.1+) | The screen now shows the reason. **TV date wrong**: select *Fix date & time* and turn on automatic time (the app also learns the network time and, as device owner on Android 9+, sets the clock itself). **HTTPS error on an old TV**: 2.1.1 bundles current root certificates (Let's Encrypt etc.); if HTTPS still fails and the server answers over HTTP, select *Connect without HTTPS*. Traffic is then not encrypted, so use it only as a fallback. **DNS error**: the Wi-Fi has no internet or blocks the server name |
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
| Logs | `adb logcat -s SyncManager ContentPlayer CommandHandler AppUpdater Kiosk BootReceiver GuestUi InputSwitcher Volume HotelCastSetup` — or send `UPLOAD_LOGS` from the admin panel |
| OK on the remote does nothing | The server sends no `guest_menu` and room services are off — nothing to show. Settings still open with a 3 s hold |
| "Live TV is not available on this TV" | No TV input / Live TV app could be found (common on Android boxes without a tuner). See §8 for the per-brand notes |
| Volume jumps back down | That is the night limit / max volume from the server (`volume` in the content). Change it in the admin panel |


## TV power (automatic off / on)

Admin → **TV Power** switches TVs off/on now or on a daily schedule (e.g. OFF 23:00 → ON 06:00).
The app follows the server's desired state (`mode: off` / `screen_on: false`):

| What | How the app does it |
|------|---------------------|
| Switch off | `DevicePolicyManager.lockNow()` when device owner → standby. Rooted boxes: `input keyevent 223` (SLEEP). Otherwise only a black screen. |
| Switch on | Wake lock with `ACQUIRE_CAUSES_WAKEUP` + player brought to front (`setTurnScreenOn`). Rooted boxes: `input keyevent 224` (WAKEUP). |
| Keep listening while off | The foreground poll service holds a partial wake lock + Wi-Fi lock, so the TV keeps polling in standby. |
| Guest uses the remote while "off" | The TV shows content again (local override) until the next server change. |
| Emergency | Always wakes the TV. |

Requirements for real remote **power-on**:

1. App is **device owner** (`adb shell dpm set-device-owner com.hotelcast.tv/.AdminReceiver`).
2. The TV must keep Android running in standby. Enable the TV setting called **Quick start**,
   **Instant on**, **Fast start** or **Network standby** (name depends on the brand). With a real "deep"
   standby, or when the TV is switched off at the wall, nothing can switch it on remotely — then use
   the TV's own **On timer / Power-on timer** (Settings → Device preferences / System → Timer) and keep
   the app as HOME app so it starts after boot.
3. Some TVs ignore the wake lock; on those, remote power-on works only with root or HDMI-CEC from a box.

Test each TV model once: Admin → TV Power → *Turn OFF* → wait 15 s → *Turn ON*. The table
*Last ON/OFF results from TVs* shows each TV's reply, e.g. `Screen on (wake lock)` or
`Wake FAILED: TV did not switch on` — then that model cannot be woken from standby.

**Black-screen mode** (Admin → TV Power → *How should "OFF" work?*): the TV only shows a black screen and
stays awake and connected, so *Turn ON* always works. Use it for TV models that cannot be woken.

Google TV example (Nextview): Settings → System → Power and energy → **Energy modes: Increased (Always
connected)**, **Shut-off timer → When inactive: Never**, **Power-on behaviour: Google TV home screen**, and as a
backup **Scheduled power on/off → Power On Time Type: Daily, Auto Power On Time 06:00**.


---

## 8. Guest features (2.0)

### Guest menu (OK key)

A **short press of OK / D-pad centre** (released before 3 s) opens a side panel with the room's
`guest_menu` items (large icons, labels from the server in the guest's language). D-pad up/down
moves, OK opens an item, **BACK** goes back / closes, and the panel closes by itself after **60 s**
without a key press. When the server sends no `guest_menu` but `services.enabled` is true, the menu
has a single "Room Service" QR entry. The staff gestures are unchanged: holding OK for 3 s, MENU,
BACK ×5 and UP UP DOWN DOWN still open the PIN prompt (OK is only treated as "short" on key-up if
the 3-second long press did not fire; remotes that send no key repeats are handled on release).

| item `type` | What happens |
|---|---|
| `qr` | Full-screen QR code (generated on the TV with ZXing) + title + URL text, e.g. room service, feedback |
| `live_tv` | Switches to Live TV (chain below) |
| `input` | Switches to `input` = `hdmi1`…`hdmi4` (HDMI passthrough, below) |
| `cast` | Cast instructions (`text`) + a Wi-Fi QR code (`WIFI:T:WPA;S:<ssid>;P:<password>;;`, special characters `\ ; , : "` escaped) from `welcome.wifi` |
| `content` | The embedded ContentItem (e.g. local guide HTML) full screen until BACK (auto-close after 10 min) |

Analytics: the app reports `POST /api/device/event` with `guest_menu_open`, `guest_menu_item`,
`qr_shown`, `input_switch` (with the result) and `welcome_shown`. A 1.x server's 404 is ignored.

### Live TV / HDMI

Live TV tries, in order, the first thing that resolves to an app on the TV:

1. the TV's tuner input: `ACTION_VIEW` on `TvContract.buildChannelsUriForInput(tunerId)`;
2. `ACTION_VIEW` on `TvContract.Channels.CONTENT_URI` (the system *Live Channels* / TV app);
3. launch intents of known TV apps: `com.google.android.tv` (Live Channels), `com.android.tv` (AOSP),
   `com.google.android.apps.tv.launcherx` (Google TV home), `com.mediatek.wwtv.tvcenter` (MediaTek
   "TV center" used by many Sony / Philips / TCL / Xiaomi / Nextview boards), `com.sony.dtv.tvx`,
   `com.tcl.tv`, `com.mitv.tvhome` (Xiaomi PatchWall);
4. the TV's input list (`android.settings.TV_INPUT_SETTINGS`, where the TV has it); for the admin's
   `OPEN_INPUT` command only, Android settings as the very last resort (never from the guest menu, so
   a guest cannot land in Android settings).

HDMI (`hdmi1`…`hdmi4`): `TvInputManager.getTvInputList()` → hardware inputs of `TYPE_HDMI` (HDMI-CEC
child devices skipped), matched by label ("HDMI 2"), by id (`…hdmi2…`) or by position, then
`ACTION_VIEW` on `TvContract.buildChannelUriForPassthroughInput(inputId)`. Listing inputs needs no
permission on Android TV; a `SecurityException` is caught and the input list is opened instead.

| TV / platform | Notes |
|---|---|
| Google TV / Android TV with a tuner (Sony, Philips, TCL, Xiaomi, Nextview…) | Works: the system TV app handles the passthrough / channel URIs. Test each model once (`OPEN_INPUT` from the admin panel; the ack names the app used) |
| MediaTek-based TVs | Usually `com.mediatek.wwtv.tvcenter`; HDMI labels are "HDMI 1…4" |
| Android boxes / sticks (no tuner, no HDMI-in) | No TV inputs exist: Live TV / HDMI show "not available" — remove those items from the menu for such rooms |
| TVs whose HDMI switching is outside Android | The input list opens; the guest picks the source by hand |

Kiosk: as device owner on **Android 9+** the target package is added to the lock-task allow list and
the HOME key is enabled inside lock task; on **Android 5–8** lock task is paused while the guest is in
the TV app. In both cases the guest returns with **HOME** (HotelCast is the HOME app), and HotelCast
restores the allow list / re-enters lock task on return. An emergency brings HotelCast back to the
front automatically.

### Personal welcome card, checkout reminder, messages

* **Welcome** (`welcome`, `guest`): full-screen card with the guest name, hotel/branding logo, the
  message, Wi-Fi name/password with a Wi-Fi QR code and the room-service QR (`services.url`). Shown
  once per `welcome.id` (remembered on the TV) for `duration_sec` (default 20 s), and again after
  each power-on within 24 h of `guest.checkin_at`. Any key closes it. Content (and ads) do not play
  behind it. `SHOW_WELCOME` shows it again now. It never appears over an emergency.
* **Checkout reminder** (`checkout_reminder`): banner at the bottom with the text and the services
  QR. OK or BACK dismisses it for good (per `id`); otherwise it hides after 2 minutes and comes back
  after the next power-on.
* **`SHOW_MESSAGE`** `{title, message, duration_sec}`: a card in the middle of the screen
  (default 15 s), closed with OK / BACK — e.g. "Your food is on the way".
* **Branding** (`branding`): product name, logo and colour are used on the welcome / empty /
  suspended screens, the guest UI accents and the settings screen title (`<product> – Settings`).

### Volume (`volume`)

* `volume.default` is applied once per stay (per `welcome.id`, else per `guest.checkin_at`; without
  guest data once on the first content).
* Every 30 s while the screen is on (also while the guest watches Live TV / HDMI) and right after a
  VOLUME key, `STREAM_MUSIC` is lowered to `volume.max`, or to `night_max` between `night_from` and
  `night_to` (TV local time, window may cross midnight).
* `SET_VOLUME {level}` (0–100, clamped to the current limit), `MUTE`, `UNMUTE`.
* TVs whose speaker volume is handled by the TV firmware outside Android (`isVolumeFixed`, some
  HDMI-CEC/ARC setups) cannot be controlled: the command is acked `failed` with that reason.

---

## 9. Support tools (screenshots, logs, crash reports)

| Command / event | What the TV does |
|---|---|
| `SCREENSHOT` | Captures its own window (PixelCopy on Android 8+, `View.draw` before), scales to ≤ 1280 px wide, JPEG quality 80, uploads `POST /api/device/screenshot` (multipart field `image`). Video surfaces may appear black on some TVs (hardware overlays are not part of the window); WebView, images and text are captured. Fails with a reason when the player is not on screen (standby, settings, Live TV) |
| `UPLOAD_LOGS` | Last 2000 logcat lines of its own process (`logcat -d -t 2000 --pid=<pid>`, without `--pid` on Android < 7) + a JSON state (settings **without the token or registration key**, versions, device owner / lock task, power state, network, current content, volume, last errors), capped at 512 KB → `POST /api/device/logs` |
| crash | The uncaught-exception handler writes the stack trace to `files/crash/`; the app restarts as before (2 s, or 30 s in a crash loop), and on the next start sends `POST /api/device/crash {stack, app_version, happened_at}` and deletes the file (max. 5 kept) |
| ads | Items with `ad_campaign_id` are reported in `POST /api/device/played` with `ad_campaign_id` |

---

## 10. Bulk provisioning (setup tool / adb)

The PC setup tool (`tools/windows/KrishnaCloud-Setup.ps1`) configures TVs over adb. Either open the
setup screen with extras:

```bash
adb shell am start -n com.hotelcast.tv/.SettingsActivity \
    --es hc_server https://hotel.com/hotelcast --es hc_room 101 --es hc_key KEY \
    --ez hc_autoregister true            # optional: --ez hc_force true
```

or send a broadcast (no UI needed, also works while the player is locked):

```bash
adb shell am broadcast -a com.hotelcast.tv.PROVISION -n com.hotelcast.tv/.ProvisionReceiver \
    -f 0x20 --es hc_server https://hotel.com/hotelcast --es hc_room 101 --es hc_key KEY \
    --ez hc_autoregister true
```

(`-f 0x20` = `FLAG_INCLUDE_STOPPED_PACKAGES`, needed when the app was installed but never opened.)

| extra | |
|---|---|
| `hc_server` | Server URL (normalised like the setup screen) |
| `hc_room` | Room number (letters/digits/space `. _ / -`, max 32) |
| `hc_key` | Registration key |
| `hc_autoregister` | `true` = register immediately; otherwise the fields are only filled in / saved |
| `hc_force` | `true` = accept even if the TV is already registered (re-provisioning) |

Values are accepted only when the TV is **not registered yet**, unless `hc_force=true`. The result is
written to logcat with tag `HotelCastSetup`; read it with `adb logcat -d -s HotelCastSetup`:

```
I HotelCastSetup: REGISTERED room=101
E HotelCastSetup: FAILED INVALID_REGISTRATION_KEY: Invalid registration key
E HotelCastSetup: FAILED already registered (room 101); add --ez hc_force true to re-provision
I HotelCastSetup: SAVED room=101 (hc_autoregister=false)
```

**Security.** `SettingsActivity` and `ProvisionReceiver` are exported but protected with
`android:permission="android.permission.DUMP"`. DUMP is a `signature|privileged|development`
permission held by the adb shell and the system, never grantable to a normal app — so only `adb
shell`, the system and HotelCast itself (same uid) can start them; another app on the TV gets a
`SecurityException`, and guests cannot reach the setup screen without the PIN. (Checking the caller
uid of `am start` is not reliable before Android 14, hence the permission approach.)

Registration errors are shown clearly on the setup screen (English / Gujarati / Hindi):
`HOTEL_SUSPENDED` (the hotel's service is paused), `LICENSE_LIMIT` (licence TV limit reached),
`INVALID_REGISTRATION_KEY` and `ROOM_NOT_FOUND`.
