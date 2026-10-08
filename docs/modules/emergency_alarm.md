# Module: Emergency alarm and message sounds (2.4.1)

> **ગ્રાહકની માંગ:** "ઇમરજન્સી એલાર્મ મોકલીએ ત્યારે ઇમરજન્સી ચાલુ રહે ત્યાં સુધી ટીવી પર એલાર્મનો અવાજ (બીપ) વાગતો રહેવો
> જોઈએ. અને સૂચના / સંદેશ માટે ગીત / અવાજ વાગવો જોઈએ."

## ગુજરાતી સારાંશ

- **ઇમરજન્સી એલાર્મ:** બ્રોડકાસ્ટ → *ઇમરજન્સી સંદેશ* ફોર્મમાં હવે **એલાર્મ અવાજ** પસંદ કરી શકાય છે: કોઈ અવાજ નહીં /
  **ઇમરજન્સી બીપ** (નવી ઇમરજન્સી માટે ડિફોલ્ટ) / ઇમરજન્સી સાયરન / ફાયર એલાર્મ / હોટેલની સાઉન્ડ લાઇબ્રેરીનો કોઈપણ અવાજ.
- **"ઇમરજન્સી બંધ ન થાય ત્યાં સુધી વગાડતા રહો"** (ડિફોલ્ટ ચાલુ). બંધ કરો તો અવાજ N વાર (1–10) વાગશે.
- **એલાર્મ અવાજનું પ્રમાણ** 0–100 (ડિફોલ્ટ 80): એલાર્મ વાગે ત્યારે ટીવી પોતાનો અવાજ ઓછામાં ઓછો આટલો કરે છે
  (મ્યૂટ હોય તો ચાલુ કરે છે) અને એલાર્મ પૂરો થાય પછી પહેલાં જેટલો કરી દે છે. રાત્રિની મહત્તમ મર્યાદા એલાર્મ દરમિયાન લાગુ પડતી નથી.
- **"બધા ટીવી પર એલાર્મ બંધ કરો"** બટન: સંદેશ સ્ક્રીન પર રહે છે, ફક્ત અવાજ બંધ થાય છે. **"બંધ કરો"** ઇમરજન્સી જ બંધ કરે છે.
- ટીવી એપ: ઇમરજન્સી દરમિયાન વિડિયો / કન્ટેન્ટ બંધ રહે છે, ફક્ત એલાર્મ સંભળાય છે. અવાજની ફાઇલ ટીવીમાં કેશ થાય છે,
  એટલે ઇન્ટરનેટ જાય તો પણ એલાર્મ વાગતો રહે છે. ટીવીની સ્ક્રીન બંધ હોય તો પણ એલાર્મ વાગે છે (ઇમરજન્સી ટીવી ચાલુ પણ કરે છે).
  મહેમાન HDMI / સેટિંગ્સમાં જાય તો એલાર્મ બંધ થાય છે અને પ્લેયર પર પાછા આવતાં ફરી શરૂ થાય છે.
- વેબ પ્લેયર: બ્રાઉઝર ઓટોપ્લે રોકે તો સ્ક્રીન પર "🔊 એલાર્મનો અવાજ ચાલુ કરવા ટેપ કરો અથવા OK દબાવો" દેખાય છે.
- **સંદેશ સાથે અવાજ:** TV કંટ્રોલ્સ → *ટીવી પર સંદેશ બતાવો* માં "સંદેશ દેખાય ત્યારે અવાજ": કોઈ નહીં (ડિફોલ્ટ) / સૂચના ઘંટડી /
  લાઇબ્રેરીનો કોઈ અવાજ, 1–5 વાર. વિડિયોનો અવાજ એટલી વાર ધીમો થાય છે.
- **સૂચના બોર્ડ (Notice board) ડિસ્પ્લે એપ:** "નવી સૂચના આવે ત્યારે ઘંટડી વગાડો" (ડિફોલ્ટ બંધ).
- સુરક્ષા: ફક્ત બિલ્ટ-ઇન અવાજ અથવા પોતાની હોટેલની લાઇબ્રેરીનો અવાજ જ ચાલે (PLAY_SOUND જેવો જ નિયમ). બીજી હોટેલનો અવાજ → 404.
- જૂની ઇમરજન્સી (2.4.1 પહેલાંની) અને જૂની ટીવી એપ પહેલાંની જેમ જ ચાલે છે (અવાજ વગર).

## What it does

| feature | where | TV contract |
|---|---|---|
| Alarm sound while an emergency is shown (loop or N times, minimum volume) | Broadcast → Emergency message; AJAX `emergency_start`; chain emergencies (beep) | `content.emergency.alarm` |
| Silence alarm on all TVs (message stays) | Broadcast → Emergency message; AJAX `emergency_silence` | `alarm` becomes `null`, `alarm_muted: true` (hash changes) |
| Sound when a message appears | TV controls → Show a message on the TV | `SHOW_MESSAGE` `{…, sound: {url, repeat, volume}}` |
| Chime when a new notice appears | Notice board display app setting | display page `<audio>` (`data.chime_url`) |

### `content.emergency` (2.4.1)

```json
"emergency": {
  "id": 9, "title": "Fire", "message": "Use the stairs", "bg_color": "#B00020", "text_color": "#FFFFFF",
  "alarm": { "url": "https://hotel.example/assets/sounds/emergency_beep.wav", "loop": true, "repeat": 3, "volume": 80, "name": "Emergency beep" },
  "alarm_muted": false
}
```

`alarm` is `null` when the emergency has no sound, was silenced (`alarm_muted: true`) or was created before
2.4.1. `url` is always absolute. `repeat` (1–10) is used when `loop` is false. `volume` (0–100) is the minimum
STREAM_MUSIC level as a share of its maximum. A deleted uploaded alarm sound falls back to the built-in beep;
an uploaded sound used by an active emergency cannot be deleted from the library.

## Built-in sounds (assets/sounds, `b:` references)

Synthesised by `tools/sounds/make_alarm_sounds.php` (pure PHP, no samples, royalty-free; mono 22.05 kHz 16-bit;
RMS of the audible part −6 dBFS, peak ≤ −1 dBFS; loops start and end at a zero crossing):

| ref | file | sound |
|---|---|---|
| `b:emergency_beep` | `emergency_beep.wav` (2.0 s, 88 KB) | 1 kHz beep-beep, loopable — default alarm |
| `b:emergency_siren` | `emergency_siren.wav` (3.0 s, 132 KB) | wail 600 → 1200 → 600 Hz, phase-continuous, seamless loop |
| `b:fire_alarm` | `fire_alarm.wav` (2.0 s, 88 KB) | four fast "whoop" sweeps 500 → 1300 Hz |
| `b:notice_chime` | `notice_chime.wav` (2.4 s, 106 KB) | 3-tone ding-dong-ding (E6, C6, G5) |

## Files

| file | purpose |
|---|---|
| `migrations/027_emergency_alarm.sql` | `broadcast_commands.alarm_sound`, `alarm_loop`, `alarm_repeat`, `alarm_volume`, `alarm_muted` (idempotent) |
| `core/Broadcaster.php` | `alarmOptions()` (validation), `alarmFor()` (content object), `emergencyStart(…, $alarm)`, `emergencySilence()`, `alarmSounding()` |
| `core/ContentResolver.php` | `emergency.alarm` + `emergency.alarm_muted` |
| `core/Sounds.php` | new built-ins, `DEFAULT_ALARM`, `ALARMS`, `NOTICE_CHIME`, `libraryRef()` (PLAY_SOUND rule), `choices()`, `usage()` counts active emergencies |
| `core/TvControls.php` | `messageSound()` → `SHOW_MESSAGE.sound` |
| `core/Apps/NoticeBoardApp.php`, `assets/display/apps/notice_board.js` | `chime` setting, `data.chime_url`, `<audio>` on new notices (never in the admin preview) |
| `admin/broadcast.php`, `admin/ajax.php`, `core/Chains.php` | form options, silence button / action, chain emergencies use the beep |
| `admin/tv_controls.php` | message sound picker |
| `admin/partials/tv_simulator.php` | "Alarm sound" indicator during an emergency; plays it after "Sound on" |
| `assets/player/player.js`, `player.css`, `core/WebPlayer.php` | web player alarm (`<audio>`, loop / repeat / volume, autoplay hint) and message sound; web player 2.4.1 |
| `.htaccess` | `audio/wav`, `audio/mpeg`, `audio/ogg` content types |
| `lang/gu_alarm.php`, `lang/hi_alarm.php` | Gujarati / Hindi strings |
| `tests/Integration/Apps/EmergencyAlarmTest.php` | server tests |
| Android `AlarmPlan.kt` (pure, `AlarmPlanTest`), `EmergencyAlarm.kt`, `Models.kt`, `ContentCache.kt`, `MainActivity.kt`, `SyncManager.kt`, `Volume.kt`, `CommandHandler.kt`, `Announcer.kt`, `Prefs.kt` | TV app 2.4.1 (code 12) |

## Android behaviour (2.4.1)

- `EmergencyAlarm` (app-level, main thread) is updated by every content update and every render. `AlarmPlan.decide`:
  same sound + loop mode → no restart (a sync re-sending the emergency never stutters); new sound / loop mode →
  restart; only the volume changed → volume adjusted; alarm gone (stop / silence) → stopped at once.
- Dedicated ExoPlayer, `REPEAT_MODE_ONE` for loops, wake mode so it keeps playing with the screen off, no audio focus.
  Plays the cached file when available (`ContentCache.mediaUrls` includes the alarm), otherwise streams;
  load errors are retried every 5 s (from the cache once downloaded).
- Volume: STREAM_MUSIC raised to ≥ `volume` % (rounded up), unmuted; the previous level / mute is saved in
  `Prefs.alarmVolumeRestore` and restored when the alarm stops — unless someone changed the volume during the alarm.
  `VolumeController.enforce` and the per-stay default volume wait while the alarm holds the volume.
- The player activity pausing while the screen is on (guest went to HDMI / settings) stops the alarm until it resumes;
  a different alarm (new emergency) still starts. Screen off does not stop it.
- `SHOW_MESSAGE` with `sound`: the message is shown first, then the sound goes through the `PLAY_SOUND` queue
  (`Announcer`, content ducked). A missing / bad sound never fails the message (noted in the ack).

## Needs a real TV

Actual loudness of the alarm through the TV speakers, the volume being raised and restored (TVs whose firmware
fixes the volume ignore it), and the alarm sounding when the screen was off / in standby (depends on the TV
keeping Android running in standby) can only be checked on real hardware.
