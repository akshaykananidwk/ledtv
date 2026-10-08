# Module: 2.4 device features — live view, USB / offline mode, TV health, announcements, HDMI-CEC

Features #41 (live screen view), #42 (USB / offline mode), #44 (TV health), #48 (TTS announcements)
and #50 (HDMI-CEC) of release 2.4. Server and Android TV app (versionName 2.4.0, versionCode 11).

| | |
|---|---|
| Migration | `migrations/025_device_features.sql`: `devices.health` / `health_at` / `health_alerts`, `rooms.usb_mode` / `cec_mode`, tables `device_health_history`, `device_live_views` |
| Boot hook | `core/boot.d/device_features.php` (tenant tables, permissions, staff alert type `tv_health`) |
| Server code | `core/LiveView.php`, `core/DeviceHealth.php`, `core/DeviceFeatures.php`, `core/Extensions/DeviceFeaturesExtension.php`, `core/Tasks/DeviceHealthTask.php`, `api/routes/device_features.php`, `admin/live_view.php`, `admin/tv_health.php`, `admin/ajax.d/live.php`, `admin/partials/device_features_card.php` |
| Shared files touched | `DeviceManager::heartbeat` (one call), `TvControls` (SPEAK / PLAY_SOUND in the whitelist), `command_label()` (LIVE_VIEW), `admin/broadcast.php` (Announce box), `admin/rooms.php` (card on the TV detail page), `admin/support.php` (Live view buttons) |
| App code | `LiveView.kt`, `UsbMedia.kt`, `UsbPlayerActivity.kt`, `DeviceHealth.kt`, `Announcer.kt`, `CecControl.kt`; hooks in `CommandHandler`, `SyncManager`, `PowerController`, `ContentPlayer` (one line), `MainActivity.onStart` (one line), `Models`, `ApiService` |
| Translations | `lang/gu_devices24.php`, `lang/hi_devices24.php` |
| Tests | `tests/Integration/Apps/DeviceFeaturesTest.php`, Android `DeviceFeatures24Test.kt` |

| permission | rule | used for |
|---|---|---|
| `support.view` | manager+ | Live view (page, AJAX, frames) |
| `tv_health.view` | reception+ (every hotel role) | Admin → TV health |
| `rooms.manage` | manager+ | USB mode / CEC mode of a room (TV detail page) |
| `settings.manage` | super admin | health alerts by email / WhatsApp on / off |
| `announce.send` | staff+ | "Announce" box on Broadcast (same permission as the device-schedules "Announce now") |

Every page / action respects `Access` (users limited to some TVs see and control only theirs) and the
hotel tenancy (another hotel's ids → 404).

---------------------------------------------------------------------------------------------------
## 1. Live screen view (#41)

Admin → TV support → a TV → **Live view** (also on the TV detail page and the TV health table).

1. Opening `admin/live_view.php?device=ID` calls `live_start`: `LiveView::start()` creates (or renews)
   the TV's single session row in `device_live_views` with a random 32-hex token, `expires_at = now +
   120 s`, and queues `LIVE_VIEW {session, interval: 4, max_sec: 120, max_width: 960, quality: 60}`
   (an older pending `LIVE_VIEW` of the same TV is expired).
2. The TV captures its own window every 4 s with the existing SCREENSHOT path (PixelCopy on Android
   8+, `View.draw` before), scales it to ≤ 960 px wide, JPEG quality 60, and posts it to the **existing**
   `POST /api/device/screenshot` with one extra multipart field `live=<token>`.
   `api/routes/device_features.php` (runs before `device_support.php`) takes those requests;
   requests without `live` are normal screenshots as before.
3. Answer to the TV: `{stored, live: {continue, interval, stop_in}}`. `stop_in` = seconds until the
   session expires (≤ 120). The TV stops when `continue` is false, when `stop_in` has passed without a
   newer answer, after 5 failed uploads in a row, or on 401 / 404.
4. The page polls `live_poll` every 3 s: it returns the newest frame (`n`, age, size) **and** extends the
   session by 120 s (keepalive). The image is reloaded from `live_view.php?device=ID&action=frame`
   (`Cache-Control: no-store`) only when `n` changed. Closing / leaving the page sends `live_stop`
   (`fetch(…, {keepalive: true})` on `pagehide`); without it, the TV stops 2 minutes after the last
   poll anyway. After 10 minutes the page pauses itself ("Continue live view").

Limits (server): frame ≤ 400 KB (413), JPEG only, ≤ 960 px wide (400), at most one frame per 2 s per TV
(429 + `Retry-After`), at most 1200 frames per TV per hour. A frame with an unknown / old token, for a
stopped or expired session, or from a TV of another hotel is not stored and gets `continue: false`.

Storage: only the newest frame — `storage/support/h{hotel}/d{device}/live.jpg`, replaced atomically
(write + rename). Live frames are **not** support files: the "last screenshot" history is untouched.
`DeviceHealthTask` deletes the frame and the session row one hour after the session ended.

**Limits of the capture (same as SCREENSHOT):** the app captures its own window. On many TVs video
is drawn on a hardware overlay (SurfaceView / tunnelled playback) that is not part of the window, so
**video areas appear black** while images, web pages, tickers and text are captured. Live TV / HDMI
inputs, Android settings and other apps are never captured; in standby the capture fails and the page
shows "No new picture for … s". DRM-protected video is always black.

---------------------------------------------------------------------------------------------------
## 2. USB / offline mode (#42)

The TV plays images and videos from a folder named **`KrishnaCloud`** (or `HotelCast`, any case) at the
root of any mounted storage: USB drive, SD card, or the internal shared storage.

When:

| situation | what plays |
|---|---|
| registered, server content (or its offline cache) available | server content — the cache keeps priority |
| registered, no content at all yet (offline since the first start, cache empty) | USB folder |
| room has **USB mode** on (TV detail page → *USB mode*; content field `usb_mode: true`) | USB folder (if one is plugged in, else the server content) |
| TV not registered | USB folder in `UsbPlayerActivity` (MENU, BACK ×5 or holding OK 3 s opens QR setup; removing the drive returns to setup; registering opens the normal player) |

Emergency, "off" and "suspended" always keep their meaning; in USB mode the room's overlay, ticker and
volume rules stay, only the playlist is replaced.

Order and durations: files in natural name order (`2.jpg` before `10.jpg`, case-insensitive; hidden
`._` files skipped). Images 10 s, videos play to their end. Optional `playlist.txt` (UTF-8):

```
# lobby loop
01 welcome.jpg, 8
offer.png 15
hotel-film.mp4
menu.jpg;20s
```

One file per line, optional seconds after `,` `;` `|` `=` a tab or spaces; `#` comments. When the
playlist names at least one existing file, only the listed files play, in that order. Formats: jpg,
jpeg, png, webp, gif, bmp; mp4, m4v, mkv, webm, mov, 3gp, ts, avi, mpg (whatever the TV can decode).

Mount detection: `StorageManager.getStorageVolumes()` (API 24+, `StorageVolume.getDirectory()` on 30+,
hidden `getPath()` on 24–29), plus `/storage/*` scanning (the only source on API 21–23), plus
`/mnt/usb_storage`-style folders of old boxes, plus the internal shared storage last. Rescans on
`ACTION_MEDIA_MOUNTED / UNMOUNTED / REMOVED / EJECT` and every 30 s.

Permissions: `READ_EXTERNAL_STORAGE` (maxSdkVersion 32), `READ_MEDIA_IMAGES` + `READ_MEDIA_VIDEO` (33+),
`requestLegacyExternalStorage` for Android 10. A **device-owner** TV grants them to itself silently
(`DevicePolicyManager.setPermissionGrantState`). Otherwise the app asks once per app start, at first use:
on the player when it may need USB (no content yet / USB mode), or on the setup screens when a
removable drive is mounted. Android 11+ hides non-media files of shared storage from apps, so
`playlist.txt` may be unreadable there — the folder then plays in name order (name your files `01 …`,
`02 …`).

The heartbeat's `health.usb` reports `source` (server / usb), `folder`, `files`, `permission`.

---------------------------------------------------------------------------------------------------
## 3. TV health (#44)

Every heartbeat of a 2.4 app carries `health`:

| key | meaning |
|---|---|
| `storage_free_mb` / `storage_total_mb` | internal storage (app files) |
| `cache_free_mb` / `cache_total_mb` | cache dir partition |
| `ram_avail_mb` / `ram_total_mb` / `ram_low` | `ActivityManager.MemoryInfo` |
| `cpu_temp_c` | best-effort `/sys/class/thermal/thermal_zone*/temp` (CPU-like zones preferred, °C; null when unreadable) |
| `wifi_rssi` / `wifi_link_mbps` | Wi-Fi only |
| `network` | ethernet / wifi / mobile / none |
| `uptime_sec`, `app_mem_mb` (PSS) | |
| `resolution` / `ui_resolution` / `refresh_hz` | HDMI / panel mode (API 23+) and UI size |
| `android_version`, `sdk`, `device_owner`, `last_crash_at` | |
| `usb {…}`, `cec {mode, detected_box, box_mode}`, `live_view` | state of the other 2.4 features |

`DeviceHealth::record()` (called from `DeviceManager::heartbeat`) sanitises it (unknown keys dropped,
numbers clamped, strings validated), stores it in `devices.health` (+ `health_at`) and writes at most one
row per TV per 10 minutes to `device_health_history` (deleted after 7 days by `DeviceHealthTask`).

**Admin → TV health** (`admin/tv_health.php`): all TVs with storage, memory, temperature, network,
uptime, model / Android / app / resolution / device owner / last crash, 24 h sparklines (temperature and
free memory) and red cells for warnings. Filter "Only TVs with warnings".

| warning | rule |
|---|---|
| Storage low | `storage_free_mb` < 500 |
| Memory low | `ram_low`, or available < 150 MB, or < 10 % of total |
| Too hot | `cpu_temp_c` > 75 °C |
| Weak Wi-Fi | on Wi-Fi and RSSI < −75 dBm |
| Not restarted for a long time | uptime > 30 days |

Alerts: `DeviceHealthTask` (every 10 minutes, active hotels) checks online TVs with health younger than
30 minutes. New warnings go out through `Notifier::send()` — the same email / WhatsApp contacts as the
offline alerts — and as staff push (`tv_health` alert type, users with `tv_health.view`). Debounce per TV
and warning (`devices.health_alerts`): sent once, again after 24 h if it stays; a warning that clears and
comes back within 6 h is not re-sent. Switch: `notify_health` (TV health page, super admin); when never
set it follows `notify_offline`.

---------------------------------------------------------------------------------------------------
## 4. TTS announcements and sounds (#48)

Commands (agreed contract, also sent by the device-schedules module):

* `SPEAK {text, lang: gu|hi|en|auto, rate: 0.5–2, repeat: 1–3, volume?: 0–100, chime_before}` —
  Android `TextToSpeech` with the gu-IN / hi-IN / en-IN voice (`auto` = Gujarati script → gu,
  Devanagari → hi, else en). A missing voice falls back to the default voice and the ack says so
  ("voice gu-IN not installed – default voice (en-US) used"). Optional two-tone chime before.
* `PLAY_SOUND {url, volume, repeat}` — a short clip in its own ExoPlayer instance, max 2 minutes.

Both are queued on the TV (max 10 waiting, never overlapping) and acked when queued with the voice /
fallback, or `failed` with the reason (no TTS engine, bad payload, queue full). While the queue plays,
every content ExoPlayer (videos, streams, layout zones) is ducked to 15 % and restored afterwards
(`AudioDuck`); sound inside web pages / YouTube is not ducked.

Server: `TvControls::COMMANDS` accepts `SPEAK` / `PLAY_SOUND` (payloads: `DeviceSchedules::speakPayload`,
`DeviceFeatures::playSoundPayload`); `Broadcaster::DEVICE_COMMANDS` lists both. **Broadcast → Announce**
(text ≤ 500, language, repeat, chime, target picker) calls `DeviceSchedules::announce()` → the same
`Broadcaster::sendCommand('SPEAK', …)` path as the device-schedules "Announce now"; per-TV results appear
in the broadcast history.

---------------------------------------------------------------------------------------------------
## 5. HDMI-CEC (#50) — what works and what does not

Normal apps cannot use `HdmiControlManager`: it is a system API guarded by the signature permission
`HDMI_CEC`. What the app does instead:

* **Android box / stick driving a TV over HDMI, CEC enabled** (box setting "HDMI-CEC" / "Anynet+",
  "Bravia Sync", "SimpLink"… on the TV): when the box goes to sleep, Android itself sends `<Standby>` and
  the TV switches off; when the box wakes up, Android sends One Touch Play and the TV switches on and
  selects the box's input. So in **CEC box mode** `SCREEN_OFF` / the "off" schedule always use real
  device sleep (`lockNow` as device owner, root `KEYCODE_SLEEP`) — even when the hotel chose the
  black-screen mode — and `SCREEN_ON` wakes the box (wake lock + bringing the player to front).
* Per room on the TV detail page: **CEC mode** `auto` (detect), `box`, `tv`. Detection (best-effort):
  hardware HDMI inputs or a tuner (`TvInputManager`) → TV panel; known TV names (Bravia, TCL, Hisense…)
  → TV; known box names (Mi Box, Shield, Chromecast, Fire TV, X96, H96, MXQ, Tata Play / Airtel / Jio
  boxes…) → box; anything else → TV (existing behaviour unchanged). Choose `box` when a box is not
  recognised.
* The app also tries `HdmiControlManager` via reflection (One Touch Play / standby). This only works on
  system-signed builds and fails silently everywhere else; the ack says which path was used.
* **Built-in Android TVs** (panel inside) keep the existing power controller (`lockNow` / wake lock /
  black screen); CEC is not involved.
* Not possible: switching another HDMI device, changing the TV's input over CEC, or waking a TV whose
  box is unplugged / in deep standby. Without device owner (or root) a box cannot put itself to sleep:
  the TV then only gets a black screen.

---------------------------------------------------------------------------------------------------
## 6. Verify on a real TV

* Live view on 2–3 models (video black or not; latency; page closed → uploads stop within 2 minutes).
* USB: FAT32 / exFAT / NTFS drives on Android 7, 9, 11, 12+; device owner vs. permission prompt;
  `playlist.txt` readability on Android 11+; hot-plug while playing; unregistered TV with a drive.
* Health: CPU temperature availability per SoC (Amlogic, MediaTek, Realtek), Ethernet vs Wi-Fi,
  resolution on 4K TVs.
* SPEAK: Gujarati / Hindi voices installed (Google TTS → "Install voice data"), ducking during video,
  queue of 3 announcements, chime volume.
* CEC: box with CEC on a Samsung / Sony / LG TV — SCREEN_OFF turns the TV off, SCREEN_ON turns it on and
  selects the input; a built-in TV still behaves as before.

---------------------------------------------------------------------------------------------------
## ગુજરાતી સારાંશ

* **લાઇવ વ્યૂ:** TV સપોર્ટ / TV વિગતો પરથી "લાઇવ વ્યૂ" ખોલો. પાનું ખુલ્લું હોય ત્યાં સુધી ટીવી દર 3–5
  સેકન્ડે નાનો સ્ક્રીનશોટ (≤ 960 px) મોકલે છે; પાનું બંધ કરો એટલે વધુમાં વધુ 2 મિનિટમાં ટીવી જાતે બંધ કરે છે.
  ફક્ત છેલ્લું ચિત્ર સચવાય છે. ઘણાં ટીવી પર વિડિઓ કાળો દેખાય છે (વિડિઓ સ્તર સ્ક્રીનશોટમાં આવતું નથી).
* **USB / ઓફલાઇન મોડ:** USB ડ્રાઇવ / SD કાર્ડ પર `KrishnaCloud` નામનું ફોલ્ડર બનાવો અને તેમાં ફોટા / વિડિઓ
  મૂકો (નામના ક્રમમાં ચાલે છે; વૈકલ્પિક `playlist.txt` માં ક્રમ અને સેકન્ડ). સર્વર ન મળે અને કેશ ખાલી હોય,
  ટીવી નોંધાયેલું ન હોય, અથવા રૂમમાં "USB મોડ" ચાલુ હોય ત્યારે ટીવી આ ફોલ્ડર ચલાવે છે. કેશવાળું સર્વર
  કન્ટેન્ટ હંમેશાં પહેલું.
* **ટીવી હેલ્થ:** નવું પાનું "ટીવી હેલ્થ" દરેક ટીવીનું સ્ટોરેજ, મેમરી, તાપમાન, Wi-Fi, ચાલુ સમય બતાવે છે અને
  સમસ્યા લાલ રંગમાં (સ્ટોરેજ < 500 MB, તાપમાન > 75 °C, Wi-Fi < −75 dBm, 30 દિવસથી રીસ્ટાર્ટ નથી…).
  ચેતવણી ઓફલાઇન ચેતવણી જેવા જ ઈમેલ / WhatsApp પર એક વાર જાય છે (24 કલાક પછી ફરી).
* **જાહેરાત (બોલતું ટીવી):** Broadcast પાના પર "Announce" બોક્સમાં લખાણ, ભાષા (ગુજરાતી / હિન્દી / English /
  આપોઆપ) અને ટીવી પસંદ કરો — ટીવી લખાણ બોલે છે અને વિડિઓનો અવાજ ધીમો કરે છે. ગુજરાતી અવાજ ન હોય તો
  મૂળ અવાજ વપરાય છે અને પરિણામમાં લખાય છે.
* **HDMI-CEC:** સામાન્ય એપ HDMI-CEC સીધું વાપરી શકતી નથી. એન્ડ્રોઇડ બોક્સ (CEC ચાલુ) માટે રૂમમાં "CEC મોડ:
  બોક્સ" પસંદ કરો: "બંધ" બોક્સને સ્લીપમાં મૂકે છે અને ટીવી CEC થી બંધ થાય છે, "ચાલુ" બોક્સ જગાડે છે અને ટીવી
  ચાલુ થાય છે. અંદરની સ્ક્રીનવાળાં એન્ડ્રોઇડ ટીવી પહેલાં જેવા જ ચાલે છે.
