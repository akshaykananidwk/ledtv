<?php
declare(strict_types=1);

/**
 * Video walls (2.4, #36): N×M TVs (up to 4×4) act as one big screen. Tables video_walls and
 * video_wall_tiles (migration 024). See docs/modules/video_wall_sync.md.
 *
 * TV contract — the content of a room that is a tile of an active wall (VideoWallExtension):
 *   mode "wall", the wall's items (one content item or a playlist), overlay clock / logo / weather /
 *   ticker off, no sponsor ads, and
 *   "wall": {"id", "rows", "cols", "row", "col", "bezel_x_pct", "bezel_y_pct", "audio"}
 *   "sync": {"epoch_ms", "cycle_ms", "item_offsets_ms"}   (always: a wall plays synced, see SyncPlayback)
 * The TV scales every visual item to the whole wall and shows only its own tile (row, col; 0-based).
 * bezel_*_pct = the gap between two pictures in percent of one picture's width / height.
 */
final class VideoWalls
{
    public const MAX_ROWS = 4;
    public const MAX_COLS = 4;
    /** audio_row value for "no sound on any tile". */
    public const AUDIO_NONE = 255;
    /** Modes the wall replaces (emergency, off, suspended and scheduled broadcasts win over a wall). */
    public const WALL_MODES = ['assigned', 'group', 'default', 'empty'];
    /** Content types a wall cannot show (layouts: their zone videos cannot be cropped). */
    public const UNSUPPORTED_TYPES = ['layout'];

    // ------------------------------------------------------------------ reading

    public static function find(int $id): ?array
    {
        return Tenant::find('video_walls', $id);
    }

    /** Walls of the current hotel visible to the current user (restricted users: only walls of their TVs). */
    public static function all(): array
    {
        $walls = DB::all('SELECT * FROM video_walls WHERE hotel_id = :h ORDER BY name, id', ['h' => Tenant::id()]);
        return array_values(array_filter($walls, fn ($w) => self::canUse($w)));
    }

    /** @return array<int, array> tiles of a wall (with room number / name), ordered by row, col. */
    public static function tiles(int $wallId): array
    {
        return DB::all(
            'SELECT t.*, r.room_number, r.name AS room_name FROM video_wall_tiles t JOIN rooms r ON r.id = t.room_id AND r.hotel_id = t.hotel_id
             WHERE t.wall_id = :w AND t.hotel_id = :h ORDER BY t.row_index, t.col_index',
            ['w' => $wallId, 'h' => Tenant::id()]
        );
    }

    /** Can the current user see / change this wall? Restricted users (Access) only when all its TVs are theirs. */
    public static function canUse(array $wall): bool
    {
        if (!Access::restricted()) {
            return true;
        }
        foreach (self::tiles((int) $wall['id']) as $t) {
            if (!Access::canRoom((int) $t['room_id'])) {
                return false;
            }
        }
        return true;
    }

    public static function requireUse(array $wall): void
    {
        if (!self::canUse($wall)) {
            Access::deny('video wall ' . (int) $wall['id']);
        }
    }

    /** Active wall + tile of a room, or null. */
    public static function forRoom(int $roomId): ?array
    {
        try {
            $row = DB::one(
                'SELECT w.*, t.row_index, t.col_index FROM video_wall_tiles t JOIN video_walls w ON w.id = t.wall_id AND w.hotel_id = t.hotel_id
                 WHERE t.room_id = :r AND t.hotel_id = :h AND w.is_active = 1 LIMIT 1',
                ['r' => $roomId, 'h' => Tenant::id()]
            );
        } catch (Throwable) {
            return null; // migration 024 not applied yet
        }
        return $row ?: null;
    }

    /** Bezel compensation [x %, y %]: the gap between two pictures relative to one picture. */
    public static function bezelPct(array $wall): array
    {
        $gap = (float) $wall['bezel_mm'];
        $w = (float) $wall['screen_w_mm'];
        $h = (float) $wall['screen_h_mm'];
        if ($gap <= 0) {
            return [0, 0];
        }
        $num = static function (float $v) {
            $v = round($v, 3);
            return floor($v) === $v ? (int) $v : $v;
        };
        return [$w > 0 ? $num($gap / $w * 100) : 0, $h > 0 ? $num($gap / $h * 100) : 0];
    }

    /** The `wall` object for one tile. */
    public static function toTv(array $wall, int $row, int $col): array
    {
        [$bx, $by] = self::bezelPct($wall);
        return [
            'id' => (int) $wall['id'],
            'rows' => (int) $wall['rows_count'],
            'cols' => (int) $wall['cols_count'],
            'row' => $row,
            'col' => $col,
            'bezel_x_pct' => $bx,
            'bezel_y_pct' => $by,
            'audio' => (int) $wall['audio_row'] === $row && (int) $wall['audio_col'] === $col,
        ];
    }

    /** TV items the wall plays: [playlist|null, items]. Inactive items and layouts are skipped. */
    public static function items(array $wall): array
    {
        if (!empty($wall['playlist_id'])) {
            $pl = ContentManager::findOwnPlaylist((int) $wall['playlist_id']);
            if ($pl) {
                $items = [];
                foreach (ContentManager::playlistItems((int) $pl['id']) as $row) {
                    if (in_array($row['type'], self::UNSUPPORTED_TYPES, true)) {
                        continue;
                    }
                    $items[] = ContentManager::toTvItem($row, $row['pli_duration'] !== null ? (int) $row['pli_duration'] : null);
                }
                if ($items) {
                    return [['id' => (int) $pl['id'], 'name' => (string) $pl['name'], 'transition' => $pl['transition'] === 'slide' ? 'fade' : (string) $pl['transition'], 'loop' => true], $items];
                }
            }
            return [null, []];
        }
        if (!empty($wall['content_id'])) {
            $row = ContentManager::findOwn((int) $wall['content_id']);
            if ($row && (int) $row['is_active'] && !in_array($row['type'], self::UNSUPPORTED_TYPES, true)) {
                $tv = ContentManager::toTvItem($row);
                if ($tv['type'] !== 'video') {
                    $tv['duration'] = 0; // a single item stays on screen
                }
                return [null, [$tv]];
            }
        }
        return [null, []];
    }

    /** ContentExtension body: turn the content of a wall tile into the wall's content. */
    public static function applyToContent(array &$content, array $room): void
    {
        if (empty($room['id']) || !in_array($content['mode'] ?? '', self::WALL_MODES, true) || ($content['screen_on'] ?? true) === false) {
            return;
        }
        $wall = self::forRoom((int) $room['id']);
        if (!$wall) {
            return;
        }
        [$playlist, $items] = self::items($wall);
        if (!$items) {
            return; // nothing to show: the TV keeps its normal content
        }
        $sync = SyncPlayback::plan($items, (int) $wall['sync_epoch_ms']);
        $content['mode'] = 'wall';
        $content['playlist'] = $playlist;
        $content['items'] = $items;
        unset($content['ads']);
        $content['wall'] = self::toTv($wall, (int) $wall['row_index'], (int) $wall['col_index']);
        if ($sync !== null) {
            $content['sync'] = $sync;
        } else {
            unset($content['sync']);
        }
        // One picture across all TVs: no per-TV clock, logo, weather or ticker on top of it.
        $overlay = is_array($content['overlay'] ?? null) ? $content['overlay'] : [];
        $overlay['clock'] = false;
        $overlay['logo'] = false;
        $overlay['weather'] = ['enabled' => false];
        $overlay['ticker'] = null;
        $content['overlay'] = $overlay;
    }

    // ------------------------------------------------------------------ validation / saving

    /**
     * Validate the admin form. Input: name, rows, cols, bezel_mm, screen_w_mm, screen_h_mm,
     * source ("c:<id>" | "p:<id>"), tiles["<row>_<col>"] = room id, audio ("<row>_<col>" | "none"), is_active.
     * Foreign ids → Tenant deny (404); rooms outside a restricted user's TVs → Access deny (403).
     * Returns [data, tiles [[row, col, room_id]], errors].
     */
    public static function validate(array $in, ?int $wallId = null): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = __('Wall name is required (max 120 characters).');
        }
        $rows = (int) ($in['rows'] ?? 0);
        $cols = (int) ($in['cols'] ?? 0);
        if ($rows < 1 || $rows > self::MAX_ROWS || $cols < 1 || $cols > self::MAX_COLS || $rows * $cols < 2) {
            $errors[] = __('A wall has 1 to 4 rows and 1 to 4 columns, at least 2 TVs.');
            $rows = max(1, min(self::MAX_ROWS, $rows));
            $cols = max(1, min(self::MAX_COLS, $cols));
        }
        $mm = static function ($v, float $max): float {
            $f = is_numeric($v) ? (float) $v : 0.0;
            return $f > 0 ? round(min($f, $max), 2) : 0.0;
        };
        $bezel = $mm($in['bezel_mm'] ?? 0, 500);
        $sw = $mm($in['screen_w_mm'] ?? 0, 5000);
        $sh = $mm($in['screen_h_mm'] ?? 0, 5000);
        if ($bezel > 0 && ($sw <= 0 || $sh <= 0)) {
            $errors[] = __('For bezel compensation enter the picture width and height of one TV in mm.');
        }

        // Content: one item or one playlist of this hotel.
        $contentId = null;
        $playlistId = null;
        $src = (string) ($in['source'] ?? '');
        if (preg_match('/^c:(\d+)$/', $src, $m)) {
            $row = ContentManager::find((int) $m[1]);
            if (!$row) {
                $errors[] = __('Choose what the wall plays.');
            } elseif (in_array($row['type'], self::UNSUPPORTED_TYPES, true)) {
                $errors[] = __('A split screen layout cannot be shown on a video wall.');
            } else {
                $contentId = (int) $row['id'];
                if ($row['type'] === 'video' && SyncPlayback::itemMs('video', (int) $row['duration'], $row) === null) {
                    $errors[] = __('The video ":t" needs a length in seconds (Content Library → "Show for") so that all TVs of the wall stay in step.', ['t' => $row['title']]);
                }
            }
        } elseif (preg_match('/^p:(\d+)$/', $src, $m)) {
            $pl = ContentManager::findPlaylist((int) $m[1]);
            if (!$pl) {
                $errors[] = __('Choose what the wall plays.');
            } else {
                $playlistId = (int) $pl['id'];
                $plItems = array_map(fn ($r) => [(int) $r['id'], $r['pli_duration'] !== null ? (int) $r['pli_duration'] : null], ContentManager::playlistItems($playlistId));
                $missing = SyncPlayback::missingDurations($plItems);
                if ($missing) {
                    $errors[] = __('These videos need a length in seconds in the playlist so that all TVs stay in step: :t', ['t' => implode(', ', $missing)]);
                }
            }
        } else {
            $errors[] = __('Choose what the wall plays.');
        }

        // Tiles: room per cell, inside the grid, each room once, not in another wall.
        $tiles = [];
        $seen = [];
        $raw = is_array($in['tiles'] ?? null) ? $in['tiles'] : [];
        $roomIds = [];
        foreach ($raw as $key => $rid) {
            if ((int) $rid > 0) {
                $roomIds[] = (int) $rid;
            }
        }
        $own = $roomIds ? Tenant::assertOwnsAll('rooms', $roomIds) : [];
        foreach ($raw as $key => $rid) {
            $rid = (int) $rid;
            if ($rid <= 0) {
                continue;
            }
            if (!preg_match('/^(\d+)_(\d+)$/', (string) $key, $m) || (int) $m[1] >= $rows || (int) $m[2] >= $cols) {
                $errors[] = __('A TV was placed outside the grid.');
                continue;
            }
            if (!in_array($rid, $own, true)) {
                $errors[] = __('Room not found.');
                continue;
            }
            if (!Access::canRoom($rid)) {
                Access::deny('video wall room ' . $rid);
            }
            if (isset($seen[$rid])) {
                $errors[] = __('Room :r is placed on more than one tile.', ['r' => self::roomNumber($rid)]);
                continue;
            }
            $seen[$rid] = true;
            $other = DB::one(
                'SELECT w.name FROM video_wall_tiles t JOIN video_walls w ON w.id = t.wall_id WHERE t.room_id = :r AND t.hotel_id = :h' . ($wallId ? ' AND t.wall_id <> :w' : ''),
                ['r' => $rid, 'h' => Tenant::id()] + ($wallId ? ['w' => $wallId] : [])
            );
            if ($other) {
                $errors[] = __('Room :r is already part of the wall ":w".', ['r' => self::roomNumber($rid), 'w' => $other['name']]);
                continue;
            }
            $tiles[] = [(int) $m[1], (int) $m[2], $rid];
        }
        usort($tiles, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $audio = (string) ($in['audio'] ?? '0_0');
        if ($audio === 'none') {
            [$ar, $ac] = [self::AUDIO_NONE, self::AUDIO_NONE];
        } elseif (preg_match('/^(\d+)_(\d+)$/', $audio, $m) && (int) $m[1] < $rows && (int) $m[2] < $cols) {
            [$ar, $ac] = [(int) $m[1], (int) $m[2]];
        } else {
            [$ar, $ac] = [0, 0];
        }

        $data = [
            'name' => $name, 'rows_count' => $rows, 'cols_count' => $cols,
            'bezel_mm' => $bezel, 'screen_w_mm' => $sw, 'screen_h_mm' => $sh,
            'content_id' => $contentId, 'playlist_id' => $playlistId,
            'audio_row' => $ar, 'audio_col' => $ac,
            'is_active' => empty($in['is_active']) ? 0 : 1,
        ];
        return [$data, $tiles, $errors];
    }

    private static function roomNumber(int $roomId): string
    {
        return (string) DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $roomId, 'h' => Tenant::id()]);
    }

    /** Insert or update a wall and replace its tiles. Restarts the synced schedule (new epoch). Returns the id. */
    public static function save(?int $id, array $data, array $tiles, ?int $userId = null): int
    {
        if ($id) {
            $wall = self::find($id);
            if (!$wall) {
                throw new InvalidArgumentException('Wall not found');
            }
            self::requireUse($wall);
        }
        $data['sync_epoch_ms'] = SyncPlayback::nowMs();
        $id = (int) DB::transaction(function () use ($id, $data, $tiles, $userId) {
            if ($id) {
                DB::update('video_walls', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('video_walls', $data + ['created_by' => $userId, 'created_at' => now()]);
            }
            DB::delete('video_wall_tiles', 'wall_id = :w', ['w' => $id]);
            foreach ($tiles as [$r, $c, $rid]) {
                DB::insert('video_wall_tiles', ['wall_id' => $id, 'room_id' => $rid, 'row_index' => $r, 'col_index' => $c]);
            }
            return $id;
        });
        Settings::bumpContentVersion();
        return $id;
    }

    public static function delete(int $id): ?array
    {
        $wall = self::find($id);
        if (!$wall) {
            return null;
        }
        self::requireUse($wall);
        DB::delete('video_walls', 'id = :id', ['id' => $id]);
        Settings::bumpContentVersion();
        return $wall;
    }

    /**
     * "Identify": every TV of the wall shows its tile number big for a few seconds (SHOW_MESSAGE, also
     * understood by older apps). Returns the number of TVs the command was queued for.
     */
    public static function identify(array $wall, int $seconds = 15): int
    {
        self::requireUse($wall);
        $cols = max(1, (int) $wall['cols_count']);
        $n = 0;
        foreach (self::tiles((int) $wall['id']) as $t) {
            $r = (int) $t['row_index'];
            $c = (int) $t['col_index'];
            $n += Broadcaster::queueForRooms([['id' => (int) $t['room_id']]], 'SHOW_MESSAGE', [
                'title' => (string) ($r * $cols + $c + 1),
                'message' => __('Row :r, column :c', ['r' => $r + 1, 'c' => $c + 1]) . ' · ' . $wall['name'] . ' · ' . __('Room :n', ['n' => $t['room_number']]),
                'duration_sec' => $seconds,
            ]);
        }
        return $n;
    }

    /** Online / registered TVs per room id of a wall (admin grid). */
    public static function deviceStatus(int $wallId): array
    {
        $out = [];
        $rows = DB::all(
            'SELECT d.room_id, d.status, d.last_ping, d.app_version_code FROM devices d JOIN video_wall_tiles t ON t.room_id = d.room_id AND t.hotel_id = d.hotel_id
             WHERE t.wall_id = :w AND d.hotel_id = :h AND d.is_revoked = 0',
            ['w' => $wallId, 'h' => Tenant::id()]
        );
        foreach ($rows as $d) {
            $rid = (int) $d['room_id'];
            $out[$rid] ??= ['online' => false, 'old_app' => false];
            $out[$rid]['online'] = $out[$rid]['online'] || DeviceManager::isOnline($d);
            $out[$rid]['old_app'] = $out[$rid]['old_app'] || (int) $d['app_version_code'] < SyncPlayback::MIN_APP_CODE;
        }
        return $out;
    }
}
