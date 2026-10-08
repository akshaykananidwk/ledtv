# Module: Web player (2.4, #45) and Fire TV / Android boxes / Raspberry Pi (#46)

The **web player** is the TV app as a web page: `https://<server>/player/`. Open it in any browser — the
Samsung (Tizen) or LG (webOS) TV browser, Fire TV Silk, a PC or mini-PC in kiosk mode, Chromium on a Raspberry
Pi — and the screen behaves like a TV running the Android app: QR or manual setup, then it registers with the
normal device API as a device of type **web**, long-polls for content and commands, plays the room's content
(playlists, layouts, ticker, overlay, emergency …), sends heartbeats and acks every command.

No new API: the player uses `POST /api/device/register`, `GET /api/device/command/{id}`,
`POST /api/device/heartbeat`, `POST /api/device/ack`, `/played`, `/screenshot`, `/logs` and the QR provisioning
endpoints exactly like the TV app (docs/API.md, docs/modules/qr_setup.md).

## Files

| File | Purpose |
|---|---|
| `player/index.php` | The player page (public, no login): headers, JSON config, loads the JS / CSS |
| `player/qr.php` | SVG QR code of the claim URL for a setup code (`?code=K7P2QX` only — not a general QR generator) |
| `assets/player/player.js` | The whole player, **ES5** (no build step, no dependency), ~1500 lines |
| `assets/player/player.css` | Styles (bundled Noto fonts for Gujarati / Devanagari) |
| `core/WebPlayer.php` | Version (`web-2.4.0`, version code 11), CSP / headers, language, JS config, list of UI strings |
| `migrations/026_web_player.sql` | `devices.platform` ENUM('android','web') DEFAULT 'android', `devices.user_agent` |
| `admin/partials/web_player_link.php` | "Web player link" button + modal (URL, copy, QR, short setup notes) |
| `lang/gu_webplayer.php`, `lang/hi_webplayer.php` | Gujarati / Hindi strings (player screens and admin) |
| `tests/Integration/Apps/WebPlayerTest.php` | PHPUnit (page, headers, QR, platform column, API as a web device, rooms page, translations) |
| `tests/browser/webplayer_e2e.js` (+ `webplayer_server.php`, `webplayer_ctl.php`) | Headless Chromium end-to-end test |
| `tools/raspberry-pi/setup.sh`, `hotelcast-cec-bridge.py`, `README.md` | Raspberry Pi kiosk + HDMI-CEC (#46) |
| `docs/screenshots/2.4/web-player-*.jpg` | Setup screen, layout + ticker, emergency (1920×1080) |

### Changes to shared files

1. `core/DeviceManager.php`: `register()` stores `platform` (`web` when the body has `platform` or `device_type`
   = `"web"`, else `android`) and, for web players, `user_agent` (255 chars) — new `platformFields()`; nothing
   happens before migration 026 has run. Heartbeat and auth are unchanged.
2. `admin/rooms.php`: "Web player link" button in the page head; the TV column shows a **Web player** badge with
   the browser and OS (Android TVs unchanged); TV details show *Platform* (Android TV app / Web player),
   *Operating system* instead of *Android version* for web players, and *User agent*; the room form's TV list
   shows a browser icon.
3. `admin/claim.php` (Add TV with QR): the same "Web player link" button.
4. `api/index.php`, `phpunit.xml`, nav.d: **not changed** (the test lives in `tests/Integration/Apps/`).

## How the player works

### First run: setup screen

* **QR (left)**: `POST /api/provision/start` with the player's device id → big setup code + QR image
  (`player/qr.php?code=…` = `admin/claim.php?code=…`). Staff scan it with a phone, pick the room, *Assign*; the
  player polls `GET /api/provision/status` every `poll_interval` s and, on `claimed`, registers with the room
  number + registration key it receives. Expired / used codes are replaced automatically; when QR setup is not
  available (rate limit, old server) the screen says so and retries every minute.
* **Manual (right)**: server address (prefilled with the server the page came from), room number, registration
  key → *Connect*. Wrong key / unknown room / TV limit / suspended hotel / network errors are shown in words.
  If another server address is typed, the browser opens **that server's** `/player/` (same origin as its API, so
  no CORS is needed) and hands over room and key in the URL fragment (`#room=…&key=…`, never sent over the
  network, removed from the address bar right away).
* `?room=101` prefills the room number; `?lang=gu|hi|en` sets the language (else the browser's language).
* Remote control: ▲ / ▼ move between the fields, OK goes to the next field / connects.

### Identity and storage (per browser profile)

| key | where | content |
|---|---|---|
| `hc_device_id` | localStorage **and** cookie (10 years) | `web-<uuid v4>` (crypto.getRandomValues) |
| `hc_token` | localStorage (a cookie only when localStorage is not available) | device token |
| `hc_content`, `hc_hash` | localStorage | the last Content object and its hash (offline playback) |
| `hc_room`, `hc_pin` | localStorage | room number, SHA-256 of the settings PIN (from register / heartbeat) |
| `hc_done` | localStorage | ids of the last 200 executed commands (de-duplication, survives reloads) |
| `hc_volume`, `hc_muted`, `hc_lang`, `hc_cec` | localStorage | player volume, mute, UI language, CEC bridge port |

### Registration body

`device_type: "web"`, `platform: "web"`, `app_version: "web-2.4.0"`, `app_version_code: 11` (the feature level
of the 2.4 Android app, so the server sends split screen layouts, which need ≥ 10), `android_version`: the OS
(`Tizen 6.0`, `webOS`, `Fire OS`, `Linux ARM`, `Windows` …), `model`: `Web player · <browser> · <OS>`,
`user_agent`. The API is called as `api/index.php?r=<route>`, so it works without mod_rewrite, relative to the
page (any host name / IP that reaches the server works).

### Polling, heartbeat, offline

* `GET device/command/{id}?hash=<current>&wait=20`. If the admin enabled long polling the server holds the
  request up to 20 s and the player asks again right away; otherwise it polls every `poll_interval` (3–60 s).
  After commands or new content it polls again within 1.5 s. Errors back off (×2 up to 60 s, `Retry-After` on
  429); `401` → the token is deleted and the setup screen says the screen was removed.
* Heartbeat every `heartbeat_interval` (≥ 15 s) with version, OS, network type, content hash, current item,
  `screen_on` and uptime; queued play reports are sent with it (`/device/played`, incl. `ad_campaign_id`).
* **Offline**: the last content is kept in localStorage and shown after a reload / power cut; images and
  videos come from the browser's HTTP cache (no Service Worker — not reliable on TV browsers). A small red dot
  bottom right means "server not reachable".

### What is rendered

The screen is scaled like the TV app: 1 dp = 1/960 of the width (16:9 boxed), so font sizes / ticker heights
match the Android app and the admin TV simulator.

| Content | Web player |
|---|---|
| `image` | `<img>`, `object-fit` contain (or the ticker's `video_scale` / the zone's `scale`) |
| `video` | HTML5 `<video>`, `loop` / `mute`; `.m3u8` via the browser's native HLS or the vendored hls.js (`assets/vendor/hlsjs`, loaded only when needed) |
| `stream` | HLS / progressive as video; RTSP / RTMP / UDP / DASH show "cannot play in a web browser" |
| `url` (incl. display apps) | `<iframe sandbox="allow-scripts allow-same-origin allow-forms allow-presentation">`, reloaded every `refresh_sec` |
| `html`, `timetable` | sandboxed `srcdoc` iframe (`allow-scripts` only: no access to the token), timetable refresh |
| `youtube` | `embed_url` iframe, autoplay (muted until the first key press, see below) |
| `announcement` | full screen or marquee, colours, font size, subtitle |
| `clock` | digital / analog |
| `layout` | zones in percent of the content area, each zone its own playlist (durations, fade / none, loop, scale, mute) |
| playlists | item `duration`, `0` = until the video ends (other types 10 s), transitions `fade` / `slide` / `none` |
| `overlay` | hotel logo (top left), clock (`clock_format`) and weather (top right) |
| `overlay.ticker` | all fields: `messages` / `text`, `speed` (30 + 25 × speed dp/s), colours, `font_size`, `height`, `position` top / bottom, `reserve_space` (content area shrinks), `video_scale` |
| `emergency` | full screen, above everything, pulsing; ticker and overlay hidden |
| `off` / `screen_on: false` | black screen (+ CEC standby on a Raspberry Pi, unless `power_off_mode` is `black`); a key press shows content again (like the TV app) |
| `suspended` | "Service paused" with branding and support contact |
| `empty` / no content yet | welcome screen: logo, "Welcome to <hotel>", room number, product name |

Not in the web player: the guest menu, welcome card / checkout reminder, Live TV / HDMI inputs, volume policy
of the TV (the player has its own volume).

### Commands (every command is acked)

| Command | Web player | Ack |
|---|---|---|
| `PING` | — | `acked` "pong (web player)" |
| `SHOW_CONTENT` | polls again with an empty hash | `acked` |
| `CLEAR_CACHE` | deletes the cached content JSON (+ Cache Storage), re-fetches | `acked` |
| `RELOAD` | reloads the page (after the ack) | `acked` |
| `REBOOT` | reloads the page — a browser cannot reboot the device | `acked` |
| `SCREEN_OFF` / `SCREEN_ON` | black screen / content again; Raspberry Pi: CEC standby / on | `acked` |
| `SET_VOLUME {level}` | volume of the player's media (not the TV's own volume) | `acked` / `failed` (no level) |
| `MUTE` / `UNMUTE` | player mute | `acked` |
| `SHOW_MESSAGE {title, message, duration_sec}` | message card (OK / Back closes it) | `acked`; `failed` during an emergency |
| `SCREENSHOT` | best effort: the player redraws its screen on a canvas (colours, texts, images / video frames when the media server allows it); web pages are grey boxes → uploaded to `/device/screenshot` | `acked` "(approximate …)"; `failed` "unsupported: …" when the browser cannot draw |
| `UPLOAD_LOGS` | the player's in-memory log + state → `/device/logs` | `acked` |
| `SPEAK {text, lang: gu\|hi\|en\|auto, rate, repeat, volume?, chime_before}` (payload as in docs/modules/device_schedules.md) | `speechSynthesis`; language `gu-IN` / `hi-IN` / `en-IN` from `lang` (`auto`: the script of the text); picks an installed voice of that language; optional chime first; video sound lowered while speaking | `acked` "Speaking (gu-IN, voice …)"; `failed` with the reason (no voice engine, blocked by autoplay policy) |
| `PLAY_SOUND {url, volume?, repeat}` | HTML audio (max 2 min, video sound lowered meanwhile; a new sound stops the previous one); without `url` a built-in Web Audio tone `sound`: `chime` / `bell` / `beep` / `alarm` / `doorbell` | `acked` / `failed` (blocked, bad file) |
| `UPDATE_APP`, `OPEN_INPUT`, `SHOW_WELCOME`, unknown | — | `failed` "unsupported: …" |

Commands are de-duplicated by id (also across reloads) and re-acked when the server delivers them again.

### Sound, full screen, wake lock

* Browsers only autoplay **muted** media without a user gesture. The player first tries with sound; if the
  browser refuses, it plays muted and shows "Press OK to enable sound" once. The first key press / click / touch
  unmutes everything (YouTube is reloaded with sound) and unlocks SPEAK / PLAY_SOUND.
  Kiosk browsers started with `--autoplay-policy=no-user-gesture-required` (Raspberry Pi script, Chrome command
  line below) have sound from the start.
* The first OK / click also requests **full screen** (`?fs=0` disables it) and a screen **wake lock**
  (`navigator.wakeLock`, HTTPS only; re-acquired when the page becomes visible again).

### Remote control and hidden settings

Arrows, OK and Back never leave the page (Back is trapped with a history entry; the browser's own Back button
mapping differs per TV). Open the **settings** with `1 2 3 4` on the number keys, by holding OK for 3 s,
▲ ▲ ▼ ▼, Back five times within 3 s, the context-menu key, or 5 clicks in the top left corner. The room's
settings PIN (4 digits, from the server, default 1234) is asked first — number keys or the on-screen keypad.
The menu shows room, server, device id, version and online state and has: Reload, Sound on, Full screen,
Language (English / ગુજરાતી / हिन्दी), **Re-pair this screen** (forget token and room → setup screen) and
**Reset the player** (also a new device id); both ask "Press OK again to confirm".

### Page headers (`WebPlayer::sendHeaders`)

* **No `X-Frame-Options`**, `frame-ancestors *`: kiosk shells and signage CMSs may embed the player.
* `Content-Security-Policy`: `default-src 'self'`, `object-src 'none'`, `base-uri 'none'`, `form-action 'self'`;
  media / images / iframes from anywhere (CDN media, YouTube, web pages); inline scripts / styles are allowed
  because sandboxed `srcdoc` iframes (HTML / timetable items) inherit the page's policy. The player itself has no
  inline script (its config is a JSON block).
* `Cache-Control: no-cache` (a reload always gets the current version), `Permissions-Policy` allows autoplay,
  fullscreen and screen-wake-lock (camera / microphone / geolocation stay off).
* Display apps are framed from the same server (`X-Frame-Options: SAMEORIGIN` on `/display/` is fine). Open
  the player under the same host name as the server's `base_url` — or display apps under another host will be
  blocked by their SAMEORIGIN header. Third-party `url` items that forbid framing stay blank (the Android app
  opens them directly).

## Admin

* **Rooms & TVs → Web player link** (also on **Add TV with QR**): the player URL (copy / open), a QR code of it
  and one-line setup notes per device.
* Web players appear like TVs: online state, current content, commands, emergency, broadcasts, schedules,
  screenshots and logs (Support) work the same. The TV column shows a **Web player** badge (browser · OS); the TV
  details page shows the platform and the user agent.
* Version: web players report `web-2.4.0` with version code 11. They cannot install an APK: the APK page shows
  them with a **Web player** badge (never "update available"), **Push update** skips them (`UPDATE_APP` is not
  queued, `DeviceManager::notWebSql()`), and TV support / the platform dashboard do not count them as outdated.
  They update themselves on the next page reload.

## Setting up screens

### Samsung smart TV (Tizen browser)

1. Open **Internet** (Samsung's browser), type the player address, *Add to bookmarks* / *Add to Home*.
2. Scan the QR code shown on the TV with a phone and choose the room (or type room + key with the remote).
3. Press OK once (sound + full screen).
4. TV menu: switch off *Auto Power Off* / *Eco Solution → Auto Power Off* and the screen saver.

Limitations: no real kiosk — after a power cycle the TV usually opens its last *source*, not the browser
(start Internet again, or use a Samsung signage model / the Android app on a box); autoplay with sound needs one
OK press after each start; HLS and H.264 / HEVC MP4 play natively; `speechSynthesis` is usually missing (SPEAK
answers "unsupported"); full screen depends on the browser version (Tizen 2017+ hides its toolbar in full screen).

### LG smart TV (webOS browser)

1. Open **Web Browser**, type the address, add it as a bookmark (webOS 6+: *Add to Home*).
2. QR / manual setup as above; press OK once.
3. Settings → General → *Eco mode*: *Auto Power Off* off; *Screen Saver* off where available.

Limitations: like Samsung — the browser is not restarted after power on, one OK press for sound; native HLS and
H.264 / HEVC; the browser may show a "low memory" warning with very large images (keep images ≤ 4K); no speech
synthesis on most models.

### Amazon Fire TV (Stick / Cube)

**Recommended: the Android app.** It installs on Fire OS (the manifest declares touchscreen and leanback as not
required, has both LAUNCHER and LEANBACK_LAUNCHER entries and a banner): install **Downloader** from the Amazon
app store, enable *Settings → My Fire TV → Developer options → Install unknown apps → Downloader* (on new Fire
OS: *About → Fire TV Stick*, press OK 7 times to show Developer options), enter the APK link of your server
(Admin → App updates) in Downloader and install. The app then works like on any Android TV (QR setup, autostart
on boot may need the app to be opened once).

**Web alternative: Silk browser** — open the address, use the menu → *Full screen*. Limitations: Silk is closed
when the Fire TV sleeps; set *Settings → Display & Sounds → Screensaver → Start time: Never* and *Sleep: Never*;
one OK press for sound; HLS via hls.js / native.

### Raspberry Pi (recommended web option)

`sudo tools/raspberry-pi/setup.sh https://<server>/player/` — installs Chromium in kiosk mode for Raspberry Pi
OS Bookworm (labwc, wayfire or X11, detected), auto-login, no screen blanking, autoplay with sound and an
HDMI-CEC bridge so SCREEN_OFF / SCREEN_ON (and "TV off" schedules) put the TV in standby / switch it on. See
`tools/raspberry-pi/README.md`.

Limitations: Pi 4 / 5 play 1080p H.264 fine; HEVC and 4K video are not decoded by Chromium; a Pi 3 is only good
for images / text; speech synthesis needs `speech-dispatcher` with espeak-ng voices (Gujarati / Hindi voices are
basic); the script was not tested on real hardware by the release team.

### Windows mini PC / NUC (Chrome kiosk)

1. Create a shortcut in `shell:startup` with
   `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --autoplay-policy=no-user-gesture-required --noerrdialogs --disable-session-crashed-bubble https://<server>/player/`
   (Edge works the same with `msedge.exe --kiosk … --edge-kiosk-type=fullscreen`).
2. Windows: automatic sign-in (`netplwiz`), power plan *never sleep / never turn off the display*, active hours
   for Windows Update. Alt+F4 leaves the kiosk.

Limitations: Windows Update restarts; HDMI-CEC is not available on most PCs (SCREEN_OFF gives a black screen);
HEVC needs the Windows HEVC extension; `speechSynthesis` uses the installed Windows voices (install the Gujarati /
Hindi speech packs for SPEAK in those languages).

### Any other browser (PC, tablet)

Works for testing and small screens. Without kiosk mode the browser shows its UI until OK / click enables full
screen; the screen may sleep unless the wake lock is granted (HTTPS) or the OS power settings prevent it.

## Android app on Fire TV and non-Android-TV boxes (#46)

`android/app/src/main/AndroidManifest.xml` was checked: `android.hardware.touchscreen` and
`android.software.leanback` are `required="false"` (also `android.hardware.wifi`), `MainActivity` has both
`LAUNCHER` and `LEANBACK_LAUNCHER` categories, and `android:banner="@drawable/banner"` is set on the application.
No manifest change was needed: the APK installs on Fire TV, Android TV, Google TV and generic Android boxes.

## Tests

* `tests/Integration/Apps/WebPlayerTest.php` (PHPUnit): page renders without PHP warnings, headers (no
  X-Frame-Options, CSP parts, no-cache, Permissions-Policy), JSON config (version code ≥ 10, relative API, en / gu
  / hi strings), language detection, ES5 smoke check of the script, QR endpoint (valid codes only), migration 026
  (columns, idempotent), `platformFields()`, a web device registering / polling (layouts delivered) / heartbeat /
  SPEAK, PLAY_SOUND, SCREENSHOT acks / provisioning start, an Android TV staying `android`, rooms page (badge,
  link + QR, TV details, room form) and claim page, translations.
* Browser: `NODE_PATH=<playwright-core>/node_modules HC_TEST_DB_NAME=… node tests/browser/webplayer_e2e.js` —
  real headless Chromium against a sandbox server: setup + QR, wrong key, manual registration, playlist with image
  + display app + announcement + layout, ticker (reserve space, scrolling), overlay clock, emergency on / off,
  SPEAK / PLAY_SOUND / SHOW_MESSAGE / SET_VOLUME / SCREENSHOT / PING / unknown command acks, SCREEN_OFF / ON,
  hidden menu with PIN, reload keeps the token; saves the three screenshots. ES5: `npx acorn --ecma5
  assets/player/player.js`.

---------------------------------------------------------------------------------------------------

## ગુજરાતી સારાંશ — વેબ પ્લેયર

**વેબ પ્લેયર** એટલે TV એપ, પણ વેબ પેજ તરીકે: `https://<તમારું સર્વર>/player/`. આ સરનામું Samsung / LG
સ્માર્ટ TV ના બ્રાઉઝર, Fire TV ના Silk, મિની PC કે Raspberry Pi માં ખોલો — સ્ક્રીન એપવાળા TV જેવું જ કામ કરશે.

1. **પહેલી વાર**: સ્ક્રીન પર QR કોડ અને 6 અક્ષરનો કોડ દેખાશે. ફોનથી સ્કેન કરો, રૂમ પસંદ કરો અને **સોંપો**
   દબાવો — અથવા સ્ક્રીન પર જ રૂમ નંબર અને રજીસ્ટ્રેશન કી લખીને **કનેક્ટ કરો** દબાવો.
2. પછી સ્ક્રીન રૂમનું કન્ટેન્ટ બતાવે છે: ફોટા, વિડિયો, લાઇવ સ્ટ્રીમ (HLS), વેબ પેજ / ડિસ્પ્લે એપ, જાહેરાત,
   ઘડિયાળ, સ્પ્લિટ સ્ક્રીન લેઆઉટ, ટિકર પટ્ટી, લોગો / ઘડિયાળ / હવામાન, ઇમરજન્સી, સ્ક્રીન બંધ, વેલકમ સ્ક્રીન.
3. એડમિન પેનલના આદેશો (રીલોડ, સ્ક્રીન બંધ / ચાલુ, અવાજ, સંદેશ, સ્ક્રીનશૉટ, **SPEAK** — ગુજરાતી / હિન્દી / અંગ્રેજી
   બોલવું, **PLAY_SOUND** — ઘંટડી / અવાજ) ચાલે છે; દરેક આદેશનો જવાબ (ack) મોકલાય છે. જે શક્ય નથી તેનો જવાબ
   "unsupported" આવે છે.
4. ઇન્ટરનેટ જાય તો છેલ્લું કન્ટેન્ટ ચાલુ રહે છે; પેજ ફરી લોડ કરવાથી જોડાણ (ટોકન) જતું નથી.
5. **અવાજ**: બ્રાઉઝર પહેલાં અવાજ વગર વિડિયો ચલાવે છે — રિમોટનું OK એક વાર દબાવો, અવાજ અને પૂર્ણ સ્ક્રીન ચાલુ થશે.
6. **છુપું સેટિંગ્સ મેનુ**: `1 2 3 4` દબાવો (અથવા OK 3 સેકન્ડ દબાવી રાખો), રૂમનો PIN લખો. ત્યાંથી રીલોડ,
   ભાષા, **આ સ્ક્રીન ફરી જોડો** અને **પ્લેયર રીસેટ કરો**.
7. એડમિન → **રૂમ અને TV → વેબ પ્લેયર લિંક** માં સરનામું અને QR મળે છે. વેબ પ્લેયરવાળા TV ની યાદીમાં
   "વેબ પ્લેયર" બેજ દેખાય છે.
8. **Raspberry Pi**: `sudo tools/raspberry-pi/setup.sh https://<સર્વર>/player/` — Pi ચાલુ થતાં જ પૂર્ણ સ્ક્રીનમાં
   પ્લેયર ખૂલે છે અને HDMI-CEC થી TV બંધ / ચાલુ થાય છે.
9. **Fire TV**: Android એપ (Downloader એપથી APK) સૌથી સારી; નહિતર Silk બ્રાઉઝર.
10. **મર્યાદાઓ**: Samsung / LG બ્રાઉઝર TV ચાલુ કરતાં આપમેળે ખૂલતું નથી; RTSP કેમેરા સ્ટ્રીમ બ્રાઉઝરમાં ચાલતી
    નથી; ગેસ્ટ મેનુ, Live TV / HDMI ઇનપુટ ફક્ત Android એપમાં છે; કેટલીક વેબસાઇટ બીજા પેજની અંદર દેખાવાની મંજૂરી
    આપતી નથી.
