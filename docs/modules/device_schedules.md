# Module: Device schedules (2.4) — timed TV actions, bells, announcements, presence sensors, proof of play

Things a hotel, school, factory or temple wants the TVs to do **at a set time** without anybody pressing a
button, plus two related tools:

| # | feature | TV command |
|---|---|---|
| 38 | Volume schedule: volume N (0–100), mute, unmute at a time (e.g. 22:00 volume 10, 07:00 volume 40) | `SET_VOLUME` `{level}` / `MUTE` / `UNMUTE` |
| 39 | Input schedule: Live TV / HDMI 1–4 at a time, and "back to the player app" | `OPEN_INPUT` `{input}` / `RELOAD` |
| 43 | Nightly auto restart, spread 0–10 minutes per TV, only when the TV has been up > N hours, offline TVs skipped | `REBOOT` (per TV) |
| 49 | Bell / chime schedule with a small sound library + "bell timetable" quick setup | `PLAY_SOUND` `{url, volume?, repeat}` |
| – | Spoken announcement schedule + "Announce now" | `SPEAK` `{text, lang, rate, repeat, volume?, chime_before}` |
| 47 | Presence / motion sensor webhook: motion → TVs on, no motion for N minutes → TVs off | `SCREEN_ON` / `SCREEN_OFF` |
| 40 | Proof of play report (plays, airtime per TV / per day / per content, CSV, printable PDF view) | – |

## Files

| file | purpose |
|---|---|
| `migrations/023_device_schedules.sql` | tenant tables `device_schedules`, `device_schedule_runs`, `sounds`, `presence_sensors`; index `(hotel_id, content_id, created_at)` on `broadcast_logs` for the report |
| `core/boot.d/device_schedules.php` | registers the tenant tables; permissions `device_schedules.manage` (manager+), `announce.send` (staff+), `play_report.view` (manager+) |
| `core/DeviceSchedules.php` | actions, payload validation, CRUD, bell timetable bulk entry, occurrence logic, firing, staggered restarts, "Announce now" |
| `core/Sounds.php` | built-in chimes + uploaded sounds, absolute URLs for the TV |
| `core/Presence.php` | sensors, tokens, motion → SCREEN_ON, idle → SCREEN_OFF, precedence rules |
| `core/PlayReport.php` | filters + indexed queries of the proof of play report |
| `core/Tasks/DeviceSchedulesTask.php` | every minute: fire due schedules, send due restarts, idle switch-off (per active hotel, in its time zone) |
| `api/routes/presence.php` | `POST /api/presence` (token auth, rate limits) |
| `admin/device_schedules.php` | tabs Schedules (+ Announce now, bell timetable), Sounds, Presence sensors |
| `admin/play_report.php` | proof of play report, CSV, `?print=1` printable page |
| `admin/partials/nav.d/23_device_schedules.php` | "Device schedules" after TV Power, "Proof of play" after Logs & History |
| `assets/sounds/school_bell.wav`, `temple_bell.wav`, `soft_chime.wav` | built-in sounds (see below) |
| `lang/gu_device_schedules.php`, `lang/hi_device_schedules.php` | Gujarati / Hindi strings |
| `tests/Integration/Apps/DeviceSchedulesTest.php` | tests (picked up by the `tests/Integration/Apps` directory in `phpunit.xml`) |

### Changes to shared files (minimal)

1. `core/Broadcaster.php`: `DEVICE_COMMANDS` also accepts `SPEAK` and `PLAY_SOUND`.
2. `admin/partials/common.php`: `command_label()` knows `SPEAK` ("Spoken announcement") and `PLAY_SOUND` ("Play sound").
3. `core/Uploader.php`: new kind `audio` (mp3 / wav / ogg, max 5 MB, real MIME + file signature check,
   files containing `<?php` / `<script` are refused) stored under `uploads/h{id}/sounds/YYYY/MM/<random>.<ext>`.

## TV contract (the Android app implements the two new commands)

Commands travel like every other device command: one row per TV in `device_commands` (`payload` JSON),
delivered by `GET /api/device/command` and acknowledged with `POST /api/device/ack`.

```json
{"id": 812, "command": "SPEAK", "payload": {"text": "Aarti will start in 10 minutes", "lang": "gu", "rate": 1.0, "repeat": 2, "volume": 60, "chime_before": true}}
{"id": 813, "command": "PLAY_SOUND", "payload": {"url": "https://tv.example.com/assets/sounds/school_bell.wav", "volume": 70, "repeat": 2}}
```

* `SPEAK`: `text` 1–500 characters (tags stripped, whitespace collapsed), `lang` `gu` | `hi` | `en` | `auto`,
  `rate` 0.5–2.0, `repeat` 1–3, `volume` 0–100 **only present when set**, `chime_before` boolean.
* `PLAY_SOUND`: absolute `url` of an mp3 / wav / ogg, `volume` 0–100 only when set, `repeat` 1–10.
* "Back to the player app" is `RELOAD` (the existing "Restart app" command: the player activity is recreated
  and shows the room's content). TV app note: when another app (Live TV / HDMI) is in front, `RELOAD` should
  also bring the player to the front.
* `OPEN_INPUT` uses the existing `{"input": "live_tv" | "hdmi1".."hdmi4"}` (`TvControls::OPEN_INPUTS`).

## Schedules

A row of `device_schedules` = action + options (JSON) + `run_time` (hotel time zone) + repeat
(`once` on `run_date`, `daily`, `weekly` on `days` 1 = Mon … 7 = Sun) + target (all / rooms / groups / floors,
the normal target picker, `Broadcaster::parseTarget` → another hotel's ids 404, a limited user's foreign TVs 403).

**Exactly once per occurrence.** `DeviceSchedulesTask` (interval 60 s, run by `Scheduler::tick` from cron or
lazily from TV polls / admin pages) calls `DeviceSchedules::tick()` for every active hotel inside
`Tenant::run()` (so `date()` is the hotel's time zone). An occurrence is due when its time is in the last
10 minutes (`CATCHUP_SEC`: a late tick still fires, an occurrence missed for longer is skipped), not before
`active_from` (set on create / edit / resume, so no surprise bell for a time that already passed) and after
`last_fired_for`. It is claimed with `INSERT IGNORE` into `device_schedule_runs`
(unique `schedule_id, occurrence_at, device_id`), so two overlapping ticks can never both fire it.
`last_fired_for`, `last_fired_at` and `last_result` ("Sent to 12 TV(s).") are shown on the page.
Every fired occurrence also creates a `broadcast_commands` row ("Schedule: …") so TV acks show up in
Logs & History.

**Emergency always wins:** rooms reached by an active emergency are skipped (no bell, no mute, no restart).

**Nightly restart (#43):** at the occurrence one `planned` run row per TV is written with
`due_at = time + random 0…stagger minutes` (default 10). Each tick sends `REBOOT` to the TVs whose
`due_at` arrived (claim `planned → sent`), and marks a TV `skipped` with a note when it is offline
(`DeviceManager::isOnline`), has an active emergency, has been up for less than `min_uptime_h`
(`devices.uptime_sec` from the last heartbeat + the time since; unknown uptime = restart) or the
restart is more than 30 minutes late (server was down).

**Bell timetable (#49):** "08:00, 08:45, 09.30 13:15" (comma / space / semicolon / new line, `H:MM` or
`H.MM`) creates one weekly bell schedule per time (default Monday–Friday), all or nothing.

### Sounds

* Built-in (no row): `b:school_bell`, `b:temple_bell`, `b:soft_chime` → `assets/sounds/*.wav`. They were
  synthesized for this product (no third-party audio, 16-bit mono 22 kHz PCM): an electric bell (880 Hz
  gong partials struck 22×/s), a temple bell (inharmonic partials 0.5…5.1 × 293 Hz with slow beating and a
  long decay) and a two-note "ding-dong" chime (E5 → C5).
* Uploaded (`sounds` table, `u:<id>`): mp3 / wav / ogg ≤ 5 MB through `Uploader` kind `audio`. A sound used by
  a bell schedule cannot be deleted. If an uploaded sound disappears anyway, the schedule is not sent and its
  result says "Not sent: the sound was deleted."

### Permissions

| who | can |
|---|---|
| reception | nothing here |
| staff (`announce.send`) | "Announce now" (limited staff: only their rooms / groups) |
| manager+ (`device_schedules.manage`) | schedules, bell timetable, sounds, presence sensors (limited managers: targets only inside their TVs, and they only see / change schedules and sensors that target nothing but their TVs) |
| manager+ (`play_report.view`) | proof of play report (limited users: only plays of their rooms) |

## Presence / motion sensors (#47)

Each sensor (`presence_sensors`) has a name, rooms or groups, `idle_minutes`, "presence overrides schedule",
an on/off switch (= presence mode for its rooms) and a token (`prs` + 48 hex, shown **once**, only the
SHA-256 stored, "New token" replaces it, "Revoke" disconnects).

```
POST /api/presence
Authorization: Bearer prs0123…               (or "token" in the JSON / form body, or ?token=…)
Content-Type: application/json

{"event": "motion"}       → TVs on          {"event": "clear"} → recorded only        {"event": "ping"} → test
{"state": "on" | "off"}   (Home Assistant style: on = motion, off = clear); an empty body = motion

200 {"ok":true,"data":{"id":3,"event":"motion","active":true,"screens_on":2,"skipped":{"104":"power_schedule"},"state":"on"}}
401 INVALID_TOKEN · 400 VALIDATION_ERROR · 405 (not POST) · 429 RATE_LIMITED (Retry-After) · 403 HOTEL_SUSPENDED
```

Rate limits: 60 events / minute per sensor, 600 requests / minute and 20 bad tokens / 10 minutes per IP.

Rules (`Presence::blockOn`, `Presence::idleTick`):

* **motion** → `SCREEN_ON` to the sensor's TVs, once (state `on`); more motion only refreshes `last_seen`.
* **no motion for `idle_minutes`** → `SCREEN_OFF` (state `off`), skipping rooms another sensor still sees
  people in, rooms with an emergency and rooms that are already off by a power rule.
* A room **switched off in TV Power** (`rooms.is_enabled = 0`) is never switched on by a sensor.
* A room inside a **power-off schedule / holiday** (anything that makes `ContentResolver` return mode `off`)
  is only switched on when "presence overrides schedule" is ticked.
* **Emergency always wins**: never switched off, and nothing needs switching on (the emergency wakes the TV).
* Presence commands are not stored on the room (unlike TV Power → Turn OFF), so the normal power schedule /
  content still decides afterwards.

### Connecting a sensor

**ESP8266 / ESP32 + HC-SR501 PIR** (PIR OUT → GPIO 5 / D1, VCC → 5 V / VIN, GND → GND):

```cpp
// Arduino IDE: board "NodeMCU 1.0" (ESP8266) or "ESP32 Dev Module".
#if defined(ESP8266)
  #include <ESP8266WiFi.h>
  #include <ESP8266HTTPClient.h>
#else
  #include <WiFi.h>
  #include <HTTPClient.h>
#endif
#include <WiFiClientSecure.h>

const char* WIFI_SSID = "HotelStaff";
const char* WIFI_PASS = "********";
const char* URL       = "https://tv.example.com/api/presence";
const char* TOKEN     = "prs0123456789abcdef…";   // Admin → Device schedules → Presence sensors
const int   PIR_PIN   = 5;                       // D1
unsigned long lastSent = 0;

void setup() {
  pinMode(PIR_PIN, INPUT);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  while (WiFi.status() != WL_CONNECTED) delay(500);
}

void loop() {
  // Motion: send at most every 30 s (the server switches on once and keeps the TVs on while motion continues).
  if (digitalRead(PIR_PIN) == HIGH && millis() - lastSent > 30000) {
    WiFiClientSecure client;
    client.setInsecure();                        // or client.setCACert(...) with your server's CA
    HTTPClient http;
    http.begin(client, URL);
    http.addHeader("Authorization", String("Bearer ") + TOKEN);
    http.addHeader("Content-Type", "application/json");
    int code = http.POST("{\"event\":\"motion\"}");
    http.end();
    lastSent = millis();
  }
  delay(200);
}
```

**Home Assistant** (`configuration.yaml` + an automation on the motion sensor):

```yaml
rest_command:
  hotelcast_motion:
    url: "https://tv.example.com/api/presence"
    method: POST
    headers:
      Authorization: "Bearer prs0123…"
    content_type: "application/json"
    payload: '{"event":"motion"}'

automation:
  - alias: "Lobby motion → TVs on"
    trigger: { platform: state, entity_id: binary_sensor.lobby_motion, to: "on" }
    action: { service: rest_command.hotelcast_motion }
```

**Shelly Motion / Shelly with a PIR input:** Settings → Actions (Gen 1) or Webhooks (Gen 2+) → "motion
detected" → URL `https://tv.example.com/api/presence?token=prs0123…` with method POST. (The token in the
URL can show up in proxy / web server logs; use the Bearer header where the device allows it.)

**Smartphone automation** (e.g. iPhone Shortcuts "Get contents of URL", Android Tasker / MacroDroid
"HTTP request"): method POST, header `Authorization: Bearer prs…`, JSON body `{"event":"motion"}`,
triggered by arriving at the shop / opening the door / an NFC tag at the entrance.

Test from a computer:

```bash
curl -X POST https://tv.example.com/api/presence -H "Authorization: Bearer prs0123…" \
     -H "Content-Type: application/json" -d '{"event":"motion"}'
```

## Proof of play report (#40)

`admin/play_report.php?from=&to=&content_id=&playlist_id=&room_id=&group_id=&campaign_id=&page=`

* Source: the plays the TVs report (`POST /api/device/played` → `broadcast_logs`, `event = 'played'`): device,
  room, content item, start time (`created_at`), seconds on screen (`duration_sec`), sponsor `ad_campaign_id`.
  Playlist = plays of the playlist's items; group = plays in the group's rooms. Kept for the hotel's log
  retention period (Settings → `log_retention_days`).
* Summary: plays, total airtime, TVs, days on air; tables per TV, per day, per content; all plays paginated
  (100 per page, newest first).
* CSV: `csv=plays` (every play, streamed in keyset chunks of 2000), `days`, `tvs`, `content`; values starting with
  `= + - @` are prefixed (`csv_download`).
* `print=1`: standalone A4 page with the hotel logo / name, period and filters, summary, per-day bars, per-TV
  and per-content tables, a signature / stamp line and "Generated by Krishna Cloud LED TV on …"; the
  "Print / Save as PDF" button uses the browser's print dialog (print CSS hides the buttons).
* Speed: at most 366 days (`Analytics::range`), every query starts on `(hotel_id, event, created_at)`,
  `(room_id, created_at)` or `(hotel_id, content_id, created_at)` (added by migration 023); the list is
  paginated and ordered on the index (`created_at DESC, id DESC`).
* Users limited to some TVs only see plays of their rooms (`Access::roomSql`), a room / group outside
  their TVs → 403, another hotel's content / playlist / room / group / campaign id → 404.

## Gujarati summary (ગુજરાતી સારાંશ)

**ડિવાઇસ સમયપત્રક** પાનેથી ટીવી નક્કી સમયે જાતે કામ કરે છે:

* **અવાજ સમયપત્રક** — દા.ત. રાત્રે 22:00 વાગ્યે અવાજ 10, સવારે 07:00 વાગ્યે અવાજ 40, અથવા મ્યૂટ / અનમ્યૂટ.
* **ઇનપુટ સમયપત્રક** — નક્કી સમયે લાઇવ ટીવી કે HDMI પર જાઓ અને પછી "પ્લેયર એપ પર પાછા".
* **રાત્રે ઓટો રીસ્ટાર્ટ** — દરેક ટીવી 0–10 મિનિટના અંતરે ફરી શરૂ થાય છે (બધા એક સાથે નહીં); બંધ (ઓફલાઇન) ટીવી
  અને ઓછા કલાક ચાલુ રહેલા ટીવી છોડાય છે.
* **ઘંટ / ચાઇમ** — શાળા, ફેક્ટરી, મંદિર માટે. ત્રણ પોતાના અવાજ (શાળાનો ઘંટ, મંદિરનો ઘંટ, હળવો ચાઇમ) અથવા
  તમારી MP3 / WAV / OGG (5 MB સુધી). "ઘંટ સમયપત્રક" માં બધા સમય એક સાથે લખો: 08:00, 08:45, 09:30 …
* **બોલાતી જાહેરાત** — દા.ત. "આરતી 10 મિનિટમાં શરૂ થશે" ગુજરાતી / હિન્દી / અંગ્રેજીમાં; "હમણાં જાહેરાત કરો" થી
  તરત પસંદ કરેલા ટીવી પર.
* દરેક સમયપત્રક દરેક સમયે **ફક્ત એક જ વાર** ચાલે છે, હોટેલના ટાઇમ ઝોન મુજબ; ઇમરજન્સી સંદેશ હંમેશાં પ્રથમ.
* **હાજરી (મોશન) સેન્સર** — લોકો દેખાય તો ટીવી ચાલુ, નક્કી મિનિટ સુધી કોઈ ન દેખાય તો બંધ. પાવર-બંધ
  સમયપત્રક દરમિયાન ટીવી ચાલુ નહીં થાય, સિવાય કે "હાજરી સમયપત્રક કરતાં આગળ" પસંદ કરેલું હોય. સેન્સર
  (ESP8266 / ESP32 + PIR, Shelly, Home Assistant કે ફોન) `POST /api/presence` પર ટોકન સાથે સંદેશ મોકલે છે.
* **પ્રૂફ ઓફ પ્લે રિપોર્ટ** — કયા ટીવી પર શું, કેટલી વાર અને કેટલો સમય ચાલ્યું; તારીખ, કન્ટેન્ટ, પ્લેલિસ્ટ,
  રૂમ / ગ્રુપ અને જાહેરાત ઝુંબેશ પ્રમાણે ફિલ્ટર, CSV અને સહી-લાઇન સાથેનો છાપવા યોગ્ય રિપોર્ટ ("Save as PDF").
