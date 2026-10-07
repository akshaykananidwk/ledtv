# Module: Split screen layouts (2.3)

A **layout** is one content item (type `layout`) that divides the TV screen into up to six zones.
Each zone plays its own content item or playlist, for example: left 70 % a video, right 30 % the menu
images, a strip at the bottom with an announcement. A layout can be assigned to rooms, groups, broadcasts
or the hotel default like any other content item, and it can be put into a playlist (it then stays for
its own `duration`).

## Files

| file | purpose |
|---|---|
| `migrations/013_apps_layouts.sql` | adds `layout` (and `app`) to `content_items.type` / `chain_content_items.type` |
| `core/Layouts.php` | presets, validation, TV output (`toTv`, `zoneItems`), old-app fallback (`legacyDevice`, `downgradeContent`, `fallbackItems`), admin helpers (`usageMap`, `usageNote`, `miniSvg`) |
| `admin/partials/layout_editor.php` | the editor inside the content form (presets, 16:9 canvas, zone list), vanilla JS |
| `lang/gu_layouts.php`, `lang/hi_layouts.php` | Gujarati / Hindi strings |
| `tests/Integration/Apps/LayoutsTest.php` | tests |

### Changes to shared files

1. `core/ContentManager.php`: type `layout` in `TYPES` ("Split screen layout") and `TYPE_ICONS` (`bi-grid-1x2`);
   `toTvItem()` adds `layout` via `Layouts::toTv()`; `validate()` calls `Layouts::validate()`.
2. `core/ContentResolver.php`: new `forDevice($room, $device)` = `forRoom()` + the old-app fallback.
3. `api/index.php`: `GET /api/device/command` and `GET /api/content/{room}` use `forDevice()`.
4. `admin/content.php`: the layout form branch (includes the editor partial), 60 s default duration for new
   layouts, a zone diagram as the grid thumbnail, "Used in layout X" notes in the list and on the delete page.
5. `admin/playlists.php`: "Used in layout X" note in the playlist list.
6. `admin/partials/tv_simulator.php`: renders `layout` items (each zone cycles its own items, only the audio zone has sound).

## Storage — `content_items.settings`

```json
{ "bg_color": "#000000", "audio": "auto",
  "zones": [ { "id": "z1", "x": 0, "y": 0, "w": 70, "h": 100, "content_id": 5, "playlist_id": null,
               "scale": "zoom", "transition": "none", "loop": true } ] }
```

`audio` is `auto` (default: the first zone whose items contain a `video`, `stream` or `youtube`), `none`
(all zones muted) or a zone id (`z2`). Zone ids are always `z1…zN` in list order.

## Rules (server-side validation, `Layouts::validate`)

- 1 to 6 zones; `x`, `y`, `w`, `h` are percent of the screen, rounded to 2 decimals; `w`, `h` ≥ 1;
  `x + w` ≤ 100 and `y + h` ≤ 100. Zones may touch but not overlap.
- Every zone needs a source: one content item (`c:<id>`) or one playlist (`p:<id>`) of the **own hotel**.
  Ids are checked with `Tenant::find`: another hotel's id is a cross-hotel attempt → logged, HTTP 404.
- No nesting: a layout cannot be a zone source, and neither can a playlist that contains a layout.
- `scale` ∈ `fit` | `fill` | `zoom` (default `fit`), `transition` ∈ `fade` | `none` (default `fade`),
  `bg_color` `#RRGGBB` (default `#000000`). Anything else is normalised to the default.

## TV contract

The content item (also documented in docs/API.md → ContentItem):

```json
{ "id": 12, "type": "layout", "title": "Lobby", "duration": 60,
  "layout": { "bg_color": "#000000",
    "zones": [ { "id": "z1", "x": 0, "y": 0, "w": 70, "h": 100,
                 "items": [ … ContentItems exactly as toTvItem() produces, never type layout … ],
                 "loop": true, "transition": "fade", "scale": "fit", "mute": false } ] } }
```

Resolution (`Layouts::toTv`, run on every content build — the content cache is invalidated by `content_version`
as for playlists, so editing a zone's playlist updates the layout on the TVs):

- content source → one item (`duration` 0, like a single assigned item) if it exists in the hotel, is active
  and is not a layout; otherwise the zone's `items` is empty.
- playlist source → the playlist's active items in order, with the per-item duration override; layouts inside
  it are skipped (no nesting even if a layout was added to the playlist later); a deleted playlist → empty.
- `mute`: `false` for the audio zone only, `true` for every other zone. The TV must mute a zone with
  `mute: true` regardless of its items' own `mute`.
- Coordinates are JSON numbers; whole numbers are written without decimals (`70`, not `70.0`).
- The zone keeps its place when its source is gone: the TV shows `bg_color` in an empty zone.

## Old TV apps (< 2.3.0)

Apps before 2.3.0 do not know `layout`. `ContentResolver::forDevice()` checks the polling device's
`devices.app_version_code` (sent by the app on register and on every heartbeat). When it is **< 10** or
unknown (`NULL`), every layout item in `content.items` is replaced by the items of its **largest zone**
(by area, the first on a tie; empty zones are passed over; a layout with only empty zones is dropped).
If that zone has a single item, it gets the layout's `duration`, so a playlist keeps rotating as before.
The content `hash` is recomputed for the downgraded object, so old and new apps each see a stable hash.
After the app is updated, its next heartbeat stores the new version code and the next poll returns the layout.

## Admin usage

Content Library → **Add content → Split screen layout**:

1. Pick a **template**: full screen + bottom strip, 70/30 (video left), 30/70, 50/50, 3 columns, 2 × 2 grid,
   L-shape (main + side + bottom), header + main + footer. Content already chosen for the first zones is kept.
2. Adjust zones on the 16:9 **canvas**: drag to move, drag the corner to resize (snaps to 1 %), or type exact
   left / top / width / height percentages. Overlapping or out-of-screen zones turn red and a warning is shown;
   the server rejects them too. **Add zone** (up to 6) / remove zone.
3. Per zone: **Plays** (a content item or playlist of this hotel; layouts and playlists containing layouts are
   not offered), **Scale** (fit / stretch / zoom), **Transition** (fade / none) and **Sound from this zone**.
   Above the list: *Sound: automatic* (first zone with a video) or *No sound*.
4. **Background colour** (visible in empty space and empty zones), **Show for (seconds)** (used when the layout
   is inside a playlist; new layouts default to 60 s).

**Preview** opens the TV simulator, which plays every zone. In the content library a layout shows a small
diagram of its zones; items and playlists used by a layout show "Used in layout X" (also on the delete page:
deleting them leaves that zone empty, the layout keeps working). Permissions are those of the content library
(`content.manage`); users limited to some TVs (Access) are not affected because content is shared.
