# Module: Live video sync — 2.6.1

**Problem**: a TV playing a live stream (temple darshan, news, a camera) slowly falls behind the real
live moment — after some hours several minutes — because every network hiccup / rebuffer adds delay
that a normal player never catches up.

**Solution**: every TV keeps live videos close to the live edge on its own:

- a **watchdog** checks the stream every 15 s and jumps back to live as soon as it is more than the
  *maximum delay* behind (default 60 s, never more than 120 s);
- a **periodic re-sync** every N minutes (default 5) as a safety net — also for streams whose delay
  the TV cannot measure (progressive HTTP "live" MP3/MP4/TS, RTSP cameras);
- frozen / endlessly buffering streams are reloaded automatically.

## Settings (per customer)

Settings → **TV & Display** → card **Live video** (`admin/settings.php`, op `save_live_sync`,
permission `settings.manage`, CSRF protected):

| setting key | form field | allowed values | default |
|---|---|---|---|
| `live_sync_refresh_min` | Keep live videos in sync — refresh every | Off (0), 2, 5, 10, 15, 30 minutes | 5 |
| `live_sync_max_lag_s` | Maximum delay behind live | 30, 60, 90, 120 seconds | 60 |

Only the listed values are accepted (`LiveSync::validate()`); an unset or invalid stored value means the
default. Saving bumps the content version, so the TVs get the change on their next poll.

## TV contract

Every content object has (`ContentResolver::finish()` → `LiveSync::forTv()`):

```json
"live_sync": {"refresh_min": 5, "max_lag_s": 60}
```

`refresh_min` 0 = no periodic re-sync (the max-delay watchdog still runs). Apps older than 2.6.1 ignore
the field; app 2.6.1 uses 5 min / 60 s when the field is missing (older server). The heartbeat's
`health.live_resyncs` reports how many re-syncs a TV made since the app started (`core/DeviceHealth.php`).

## Android TV app (2.6.1, versionCode 15)

All live playback goes through `ContentPlayer` — full screen, every split-screen zone (`LayoutPlayer`
passes `live_sync` to its zone players), guest-menu content, and broadcasts / scheduled content
(`broadcast.php` → content resolved by the server). Emergencies are announcements, not streams.

`LiveSync.kt` holds the pure decisions (unit tested in `LiveSyncTest.kt`):

`LiveSync.decide(offsetMs, maxLagMs, bufferingMs, stalledMs, sinceLastRefreshMs, refreshEveryMs, seekable, live, targetOffsetMs)` →
`NONE | SEEK_TO_LIVE | RELOAD`, in this order:

1. buffering ≥ 20 s, or the stream position has not moved for 30 s → **RELOAD** (re-prepare the URL);
2. not live (a VOD file in a "stream" item) → nothing more;
3. `currentLiveOffset` > max delay → **SEEK_TO_LIVE** (`seekToDefaultPosition()`, no black screen) for
   seekable live HLS/DASH, otherwise **RELOAD**. The limit is never below the stream's own target offset
   + 10 s, so a provider that holds back e.g. 60 s does not cause a seek loop;
4. periodic re-sync due → SEEK_TO_LIVE (skipped when already within target + 10 s), or RELOAD for streams
   that cannot seek (progressive HTTP, RTSP).

ExoPlayer configuration of stream items: `MediaItem.LiveConfiguration` with the stream's own target offset,
`maxOffsetMs` = max delay, playback speed 0.98–1.04 (gentle catch-up). `BehindLiveWindowException`
(ERROR_CODE_BEHIND_LIVE_WINDOW) → `seekToDefaultPosition()` + `prepare()` instead of the error/retry loop.
A reload keeps the last frame on screen (`setKeepContentOnPlayerReset`), no waiting screen.
Each re-sync is logged (`Log.i ContentPlayer "live: …"`).

**YouTube** items (and `url` items with a YouTube link) in the WebView: every 30 s a small script asks the
embed player whether the video is live; when it is more than the max delay behind, it jumps to the live
edge (`player.seekTo(player.getDuration(), true)`, or the end of the `<video>` seekable range). Live
YouTube pages — detected by the link (`/live/…`, `/@channel/live`, `embed/live_stream?channel=`) or
reported live by the page — are also reloaded every N minutes.

## Web player (`assets/player/player.js`, ES5)

- hls.js streams: `liveSyncDuration` = min(30 s, max delay / 2), `liveMaxLatencyDuration` = max delay,
  `maxLiveSyncPlaybackRate` 1.04; watchdog every 15 s: more than the max delay behind
  `hls.liveSyncPosition` → seek there; periodic re-sync → seek to live (when > 10 s behind).
- native HLS (Safari, smart-TV browsers) and progressive streams: more than the max delay behind the end
  of the seekable range → seek; periodic re-sync → reload the stream; frozen for 30 s → reload.
- YouTube live links: the iframe is reloaded every N minutes (cross-origin, no player API).

## Limits

- The TV can only re-sync to what the stream provider offers. If the source itself is delayed (encoder,
  CDN, a provider whose HLS playlist is already minutes behind), the TV cannot be earlier than the stream —
  it can only stay close to the stream's own live edge.
- Streams with long segments keep a delay of about 3 segments (e.g. 3 × 10 s = 30 s) by HLS design.
- Every periodic reload of a stream that cannot seek (MP3/HTTP, RTSP) interrupts it for a moment
  (re-connect); choose a longer interval or Off if that is noticeable.
- The YouTube live edge check relies on YouTube's embed page; if YouTube changes it, the periodic reload
  still keeps the TV in sync.

## Files

| file | purpose |
|---|---|
| `core/LiveSync.php` | options, defaults, validation, `live_sync` for the TV |
| `core/ContentResolver.php` | adds `live_sync` to every content object (`finish()`) |
| `core/DeviceHealth.php` | accepts `health.live_resyncs` |
| `admin/settings.php` | "Live video" card on the TV & Display tab (own form, op `save_live_sync`) |
| `lang/gu_live_sync.php`, `lang/hi_live_sync.php` | Gujarati / Hindi strings |
| `assets/player/player.js` | web player live sync |
| `tests/Integration/Apps/LiveSyncTest.php` | server tests |
| Android `LiveSync.kt`, `ContentPlayer.kt`, `LayoutPlayer.kt`, `Models.kt` (`Content.liveSync`), `GuestUi.kt`, `SyncManager.kt` (health) | TV app |
| Android test `LiveSyncTest.kt` | JVM unit tests |
