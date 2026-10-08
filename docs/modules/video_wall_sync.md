# Module: Synchronized playback (#37) and video walls (#36) — 2.4

**Synchronized playback**: every TV that plays the same synced playlist shows the same item at the same
moment (lobby, restaurant, a row of TVs side by side). **Video wall**: N × M TVs (up to 4 × 4) act as one
big screen; each TV shows its own part of the picture, always synced, with optional bezel compensation.

## Files

| file | purpose |
|---|---|
| `migrations/024_video_walls.sql` | `content_playlists.sync_playback` / `sync_epoch_ms`; tenant tables `video_walls`, `video_wall_tiles` |
| `core/SyncPlayback.php` | schedule (`plan()`), duration rules (`itemMs()`, `missingDurations()`), `server_time_ms` clock (`nowMs()`) |
| `core/VideoWalls.php` | wall CRUD, validation, Access, TV output (`toTv()`, `applyToContent()`), Identify |
| `core/Extensions/SyncPlaybackExtension.php` | adds `sync` to content playing a synced playlist (PRIORITY 150, after sponsor ads) |
| `core/Extensions/VideoWallExtension.php` | turns a wall tile's content into the wall content (PRIORITY 250, after ads and ticker) |
| `core/boot.d/video_walls.php` | tenant tables, permission `video_walls.manage` (manager+) |
| `admin/video_walls.php`, `admin/partials/nav.d/18_video_walls.php` | admin page (sidebar: after Groups) |
| `lang/gu_videowall.php`, `lang/hi_videowall.php` | Gujarati / Hindi strings |
| `tests/Integration/Apps/VideoWallTest.php` | server tests |
| Android `SyncClock.kt`, `SyncPlan.kt`, `WallGeometry.kt` | pure Kotlin (unit tested): clock offset, schedule, drift rules, tile geometry |
| Android `WallSync.kt`, `res/layout/hc_wall_player_view.xml` | `ServerClock` (app instance), `WallLayout` (applies the geometry to views, TextureView player) |
| Android tests `SyncClockTest`, `SyncPlanTest`, `WallGeometryTest` | JVM unit tests |

### Changes to shared files

1. `api/index.php`: the poll / command response has `server_time_ms` (ms).
2. `admin/playlists.php`: the **Sync playback** switch, its validation, a "Synced" badge in the list.
3. `admin/partials/common.php`: `mode_label('wall')`.
4. Android `Models.kt`: `PollResponse.serverTimeMs`, `Content.sync`, `Content.wall`.
5. Android `SyncManager.kt`: each poll's round trip is fed to `ServerClock`.
6. Android `ContentPlayer.kt`: wall layout of every item view, TextureView player on walls, synced start /
   boundaries / video drift correction, muting of non-audio tiles. Without `wall` / `sync` nothing changes.
7. `docs/API.md`: `server_time_ms`, mode `wall`, `sync`, `wall`.

## Where the "Sync playback" switch lives — and why

On the **playlist** (Playlists → edit → *Sync playback*), not on the group assignment. Sync is a property of
*what* is played: every TV playing that playlist — through its room, a group, a time-window broadcast or the
hotel default ("all") — runs on the same schedule, so no target type needs its own flag. The duration rule
(below) is checked where the durations are entered (the playlist editor). To sync a single video, put it in
a playlist (or use a video wall).

## Durations

A synced schedule needs a fixed length for every item (`SyncPlayback::itemMs`):

- the playlist's per-item seconds, otherwise the item's own duration;
- items with duration 0 that are not videos get the TV's defaults (10 s; layouts 60 s), so the server and
  the TV agree;
- a **video with duration 0** uses its media length when the server knows it (`settings.media_duration_ms`
  or `settings.media_duration` in seconds); otherwise it **needs seconds**.

Admin: with *Sync playback* switched on, the editor marks video rows without seconds as required. If such a
video is saved anyway, the playlist is saved but the switch stays **off** with a warning naming the videos.
If an item loses its length later (content edited), the server simply omits `sync` (logged) and the TVs play
normally. The server rewrites duration-0 items of a synced list to the schedule's seconds so the TV's own
timing agrees.

## TV contract

Poll / command response: `server_time_ms` (int, ms). Content of a synced playlist or a wall:

```json
"sync": { "epoch_ms": 1791206990000, "cycle_ms": 46000, "item_offsets_ms": [0, 30000, 36000] }
```

- `epoch_ms`: start of the schedule — set when the playlist / wall is saved (so a change restarts everything at
  item 1 on all TVs at the same moment); stable across polls, so the content hash stays stable.
- `item_offsets_ms`: start of each item in `items` within the cycle (one per item).
- Position: `t = (server_now − epoch_ms) mod cycle_ms` → the last item whose offset ≤ t, `t − offset` into it.

Wall tiles additionally get `mode: "wall"` and

```json
"wall": { "id": 4, "rows": 2, "cols": 2, "row": 1, "col": 0, "bezel_x_pct": 1, "bezel_y_pct": 1.786, "audio": false }
```

`row` / `col` are 0-based (row 0 = top, col 0 = left as seen from the front). `bezel_*_pct` = gap between two
pictures / one picture's width (or height) × 100. `audio`: this tile plays sound (configurable, default the
first tile; "No sound" possible). On a wall the server also switches off the overlay clock / logo / weather and
the ticker, never inserts sponsor ads, and sends `transition: fade` instead of `slide`.

What wins over a wall: emergency, hotel suspended, room switched off / TV-off schedule, check-in mode
"vacant → off", and time-window broadcasts (`scheduled`). The wall replaces `assigned`, `group`, `default` and
`empty`. A wall that is switched off (or has nothing playable) leaves the TVs on their own content.

## TV behaviour (app 2.4.0, code 11)

**Clock** (`SyncClock`, `ServerClock`): every poll records `t0` (before the request), `t1` (response) on the
monotonic clock (`elapsedRealtime`) and `server_time_ms`. Offset = `server_time_ms − (t0 + t1) / 2`; of the last
16 samples (≤ 30 min old, RTT ≤ 10 s) the one with the **smallest round trip** is used (error ≤ RTT/2; jitter,
retries and long polls only make the RTT bigger). Before the first poll the TV's own wall clock is used.

**Schedule** (`SyncPlan`, `ContentPlayer`):

- On new content the TV starts with the item the clock asks for; a video is sought to the offset.
- The advance timer is set to the next **boundary** of the schedule (not to "duration" after start). When it
  fires, the TV shows what the clock says — this re-aligns images, pages and videos at every boundary. A timer
  that fires a little early waits for the boundary instead of rebuilding the item.
- Videos: a check every second compares the player position with the schedule (looping videos modulo their
  media length; non-looping ones hold the last frame). Drift **> 300 ms** is corrected right after start / a
  seek and in the first 1.5 s of an item (the boundary); in the **middle of an item only drift > 1 s** is
  corrected. Seeks land late (key frames), so the TV learns a seek lead (half-step, 0–1.5 s).
- Streams and YouTube cannot be sought: they only start at the right time.
- If `sync` does not match the playable items (e.g. an item the app cannot render), the app plays normally.

**Wall** (`WallGeometry`, `WallLayout`): in units of one screen the picture is `W = cols + (cols−1)·gapX` by
`H = rows + (rows−1)·gapY`. Every item view is laid out at the wall's aspect ratio (`W·w/s × H·h/s`,
`s = max(W, H)`, never larger than the screen), scaled by `s` around its top-left corner and moved by
`−col·(1+gapX)·w`, `−row·(1+gapY)·h`, so the tile shows exactly its crop rect
`[col·(1+gapX)/W … (col·(1+gapX)+1)/W]` (likewise vertically). The gap part of the picture is "behind the
frames".

- **Video**: a `StyledPlayerView` with a **TextureView** surface (`hc_wall_player_view.xml`, resize mode
  zoom). The default SurfaceView ignores view transforms, so it is only used off walls (normal path untouched).
- **Images**: `ImageView` (center-crop) in the transformed view.
- **Web pages / HTML / YouTube / announcements / clocks**: the same view transform (best effort): the page is
  rendered at the smaller wall-shaped size and scaled up, so text is softer than on a single TV and pages
  that adapt to their viewport may look different. A page-level CSS crop was not attempted.
- **Layouts** cannot be wall content (admin rejects them; layouts inside a wall playlist are skipped).
- Sound: only the `audio` tile; the others mute video, YouTube (URL) and web media.

## Old apps (< 2.4.0)

They ignore `wall`, `sync` and `server_time_ms` (unknown JSON fields) and play the items normally: a wall TV
shows the **whole** picture (not its tile) and a synced playlist plays unsynced. This is harmless; the wall
editor marks such TVs "Update app".

## Admin

**Playlists → edit → Sync playback** (see above). The list shows a "Synced" badge.

**Video walls** (sidebar, after Groups; permission `video_walls.manage`, manager+):

1. *New video wall*: name, rows and columns (1–4 each, at least 2 TVs).
2. *The wall plays*: one content item or a playlist (videos need seconds; layouts are not allowed).
3. Grid "Which TV is where? (as seen from the front)": choose the room for each tile. A room can be on one
   tile of one wall only (rooms of other walls are shown disabled; the database enforces it too). Empty
   tiles are allowed. After saving, each tile shows whether its TV is online and whether its app is too old.
4. *Sound from*: a tile or "No sound". *Wall is on*: off = the TVs show their own content.
5. *Bezel compensation (optional)*: gap between the pictures of two neighbouring TVs in mm (both frames
   together) and the picture width / height of one TV in mm → `bezel_x_pct = gap / width × 100`.
6. **Identify** (list and edit page): every TV of the wall shows its tile number (large title, "Row r,
   column c · wall · room") for 15 s, via `SHOW_MESSAGE` (also works on older apps).

Saving restarts the wall's schedule and tells the wall's TVs to refresh. Users limited to some TVs (Access)
see and change only walls whose TVs are all theirs and can only place their own TVs (403 otherwise).
Rooms / walls / content of another hotel are refused like everywhere else (404, logged).

## Not verifiable without real TVs

Measured sync accuracy between TVs (expected: clock error ≤ half the best poll RTT, typically 5–30 ms on a
LAN, plus decoder start latency), the TextureView performance on low-end TV boxes (TextureView costs more GPU
than SurfaceView; 4K video scaled 4× may drop frames on weak SoCs), how web pages look when scaled, and the
physical bezel alignment. The unit tests cover the math; the server tests cover the contract.

---

## ગુજરાતી સારાંશ

**સિંક પ્લેબેક (#37)**: પ્લેલિસ્ટ એડિટમાં *સિંક પ્લેબેક* ચાલુ કરો. પછી આ પ્લેલિસ્ટ ચલાવતા બધા ટીવી (રૂમ, ગ્રુપ,
બ્રોડકાસ્ટ કે હોટેલ ડિફૉલ્ટ દ્વારા) એક જ ક્ષણે એક જ આઇટમ બતાવે છે. દરેક વિડિયોની લંબાઈ સેકન્ડમાં જોઈએ; ન હોય તો
પ્લેલિસ્ટ સચવાય છે પણ સિંક બંધ રહે છે અને ચેતવણી દેખાય છે. ટીવી દરેક પોલમાં સર્વરનો સમય (`server_time_ms`) લઈને
પોતાની ઘડિયાળનો તફાવત ગણે છે (સૌથી ઓછા રાઉન્ડ-ટ્રિપવાળો નમૂનો) અને `sync` શેડ્યૂલ પ્રમાણે સાચી આઇટમ અને સાચી
જગ્યાએથી વિડિયો ચલાવે છે. આઇટમ બદલાતી વખતે 300 ms થી વધુ ફરક હોય તો સુધારે છે, આઇટમની વચ્ચે ફક્ત 1 સેકન્ડથી વધુ ફરક હોય તો.

**વિડિયો વૉલ (#36)**: *વિડિયો વૉલ* પેજમાં વૉલ બનાવો (4 × 4 સુધી), ગ્રીડમાં દરેક ટાઇલ પર કયો ટીવી (રૂમ) છે તે પસંદ કરો,
શું ચાલશે તે પસંદ કરો (કન્ટેન્ટ અથવા પ્લેલિસ્ટ; લેઆઉટ નહીં), અવાજ કયા ટીવીમાંથી આવશે અને જરૂર હોય તો બેઝલ (ફ્રેમ)
સમાયોજન mm માં. *ઓળખો* બટનથી દરેક ટીવી 15 સેકન્ડ માટે પોતાનો ટાઇલ નંબર બતાવે છે. દરેક ટીવી આખા ચિત્રનો
ફક્ત પોતાનો ભાગ બતાવે છે અને વૉલ હંમેશા સિંકમાં ચાલે છે. એક રૂમ ફક્ત એક જ વૉલમાં હોઈ શકે. ઇમરજન્સી, બંધ ટીવી
અને સમયવાળા બ્રોડકાસ્ટ વૉલ કરતાં આગળ છે. 2.4 કરતાં જૂની ટીવી એપ આખું ચિત્ર સામાન્ય રીતે બતાવે છે (નુકસાન નહીં)
— એપ અપડેટ કરો. વેબ પેજ વૉલ પર મોટા કરીને બતાવાય છે (અક્ષરો થોડા ઝાંખા લાગી શકે).
