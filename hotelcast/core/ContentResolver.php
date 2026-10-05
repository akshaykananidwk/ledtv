<?php
declare(strict_types=1);

/**
 * Decides what a room's TV should show right now and builds the Content object.
 *
 * Priority: emergency → room off / scheduled power-off window → active time-window broadcast → room assignment
 *           → group assignment → hotel default → empty (welcome screen).
 */
final class ContentResolver
{
    private const CACHE_TTL = 15;

    /** Content object for a room (file-cached, invalidated by content_version). */
    public static function forRoom(array $room): array
    {
        $key = 'room:' . $room['id'] . ':v' . Settings::get('content_version', '1') . ':' . floor(time() / 60);
        $cached = Cache::get('content', $key, self::CACHE_TTL);
        if (is_array($cached)) {
            return $cached;
        }
        $content = self::build($room);
        Cache::set('content', $key, $content);
        return $content;
    }

    public static function build(array $room): array
    {
        $groupIds = array_map('intval', DB::column('SELECT group_id FROM room_group_members WHERE room_id = :r', ['r' => $room['id']]));
        $content = [
            'mode' => 'empty',
            'screen_on' => true,
            'room' => [
                'id' => (int) $room['id'],
                'number' => (string) $room['room_number'],
                'name' => (string) ($room['name'] ?? ''),
                'floor' => (string) ($room['floor'] ?? ''),
            ],
            'hotel' => [
                'name' => (string) Settings::get('hotel_name', ''),
                'logo_url' => media_url((string) Settings::get('hotel_logo', '')),
            ],
            'playlist' => null,
            'items' => [],
            'overlay' => self::overlay(),
            'emergency' => null,
            'power_off_mode' => Settings::get('power_off_mode', 'standby') === 'black' ? 'black' : 'standby',
        ];

        // 1. Emergency
        $emergencies = DB::all("SELECT * FROM broadcast_commands WHERE is_emergency = 1 AND status = 'active' ORDER BY id DESC");
        foreach ($emergencies as $b) {
            if (self::targets($b, $room, $groupIds)) {
                $p = json_decode((string) $b['payload'], true) ?: [];
                $em = [
                    'id' => (int) $b['id'],
                    'title' => (string) ($p['title'] ?? $b['title']),
                    'message' => (string) ($p['message'] ?? ''),
                    'bg_color' => clean_color($p['bg_color'] ?? null, '#B00020'),
                    'text_color' => clean_color($p['text_color'] ?? null, '#FFFFFF'),
                ];
                $content['mode'] = 'emergency';
                $content['emergency'] = $em;
                $content['items'] = [[
                    'id' => 0, 'type' => 'announcement', 'title' => $em['title'], 'duration' => 0,
                    'text' => $em['message'] !== '' ? $em['message'] : $em['title'],
                    'subtitle' => $em['message'] !== '' ? $em['title'] : '',
                    'style' => 'fullscreen', 'bg_color' => $em['bg_color'], 'text_color' => $em['text_color'], 'font_size' => 56,
                ]];
                return self::finish($content);
            }
        }

        // 2. Room switched off (admin) or inside a scheduled "TV off" window. Emergencies above still wake it.
        if (!(int) $room['is_enabled']) {
            $content['mode'] = 'off';
            $content['screen_on'] = false;
            return self::finish($content);
        }
        foreach (self::powerWindows() as $b) {
            if (self::windowActive($b) && self::targets($b, $room, $groupIds)) {
                $content['mode'] = 'off';
                $content['screen_on'] = false;
                $content['power_schedule_id'] = (int) $b['id'];
                return self::finish($content);
            }
        }

        // 3. Time-window broadcasts
        $windows = DB::all(
            "SELECT * FROM broadcast_commands
             WHERE mode = 'window' AND is_emergency = 0 AND command = 'SHOW_CONTENT' AND status IN ('scheduled','active')
               AND (start_at IS NULL OR start_at <= :now) AND (end_at IS NULL OR end_at > :now2)
             ORDER BY id DESC",
            ['now' => now(), 'now2' => now()]
        );
        foreach ($windows as $b) {
            if (self::windowActive($b) && self::targets($b, $room, $groupIds)
                && self::fill($content, $b['content_id'] ? (int) $b['content_id'] : null, $b['playlist_id'] ? (int) $b['playlist_id'] : null)) {
                $content['mode'] = 'scheduled';
                $content['broadcast_id'] = (int) $b['id'];
                return self::finish($content);
            }
        }

        // 4. Room assignment
        if (self::fill($content, $room['content_id'] ? (int) $room['content_id'] : null, $room['playlist_id'] ? (int) $room['playlist_id'] : null)) {
            $content['mode'] = 'assigned';
            return self::finish($content);
        }

        // 5. Group assignment (lowest group id first for determinism)
        if ($groupIds) {
            [$in, $params] = DB::in($groupIds, 'g');
            $groups = DB::all("SELECT content_id, playlist_id FROM room_groups WHERE id IN $in AND (content_id IS NOT NULL OR playlist_id IS NOT NULL) ORDER BY id", $params);
            foreach ($groups as $g) {
                if (self::fill($content, $g['content_id'] ? (int) $g['content_id'] : null, $g['playlist_id'] ? (int) $g['playlist_id'] : null)) {
                    $content['mode'] = 'group';
                    return self::finish($content);
                }
            }
        }

        // 6. Default
        $defC = Settings::int('default_content_id', 0) ?: null;
        $defP = Settings::int('default_playlist_id', 0) ?: null;
        if (self::fill($content, $defC, $defP)) {
            $content['mode'] = 'default';
        }
        return self::finish($content);
    }

    /** Enabled "TV off" schedules (SCREEN_OFF windows). */
    public static function powerWindows(): array
    {
        return DB::all(
            "SELECT * FROM broadcast_commands
             WHERE mode = 'window' AND command = 'SCREEN_OFF' AND status IN ('scheduled','active')
               AND (start_at IS NULL OR start_at <= :now) AND (end_at IS NULL OR end_at > :now2)
             ORDER BY id",
            ['now' => now(), 'now2' => now()]
        );
    }

    /** Fill items from a playlist (preferred) or single content item. */
    private static function fill(array &$content, ?int $contentId, ?int $playlistId): bool
    {
        if ($playlistId) {
            $pl = ContentManager::findPlaylist($playlistId);
            if ($pl) {
                $items = [];
                foreach (ContentManager::playlistItems($playlistId) as $row) {
                    $items[] = ContentManager::toTvItem($row, $row['pli_duration'] !== null ? (int) $row['pli_duration'] : null);
                }
                if ($items) {
                    $content['playlist'] = ['id' => (int) $pl['id'], 'name' => $pl['name'], 'transition' => $pl['transition'], 'loop' => true];
                    $content['items'] = $items;
                    return true;
                }
            }
        }
        if ($contentId) {
            $item = ContentManager::find($contentId);
            if ($item && (int) $item['is_active']) {
                $tv = ContentManager::toTvItem($item);
                // A single item stays on screen; duration only matters inside playlists.
                $tv['duration'] = 0;
                $content['playlist'] = null;
                $content['items'] = [$tv];
                return true;
            }
        }
        return false;
    }

    /** Does a broadcast target this room? */
    public static function targets(array $b, array $room, array $groupIds): bool
    {
        $ids = json_decode((string) ($b['target_ids'] ?? '[]'), true) ?: [];
        return match ($b['target_type']) {
            'all' => true,
            'rooms' => in_array((int) $room['id'], array_map('intval', $ids), true),
            'groups' => (bool) array_intersect($groupIds, array_map('intval', $ids)),
            'floors' => in_array((string) $room['floor'], array_map('strval', $ids), true),
            default => false,
        };
    }

    /** Is a window broadcast active at this moment (repeat days + daily time range)? */
    public static function windowActive(array $b, ?int $ts = null): bool
    {
        $ts ??= time();
        if (!empty($b['start_at']) && strtotime($b['start_at']) > $ts) {
            return false;
        }
        if (!empty($b['end_at']) && strtotime($b['end_at']) <= $ts) {
            return false;
        }
        if (!empty($b['repeat_days'])) {
            $days = array_map('intval', explode(',', (string) $b['repeat_days']));
            if (!in_array((int) date('N', $ts), $days, true)) {
                // Overnight windows (e.g. 22:00–02:00) belong to the previous day after midnight.
                $overnight = !empty($b['daily_start']) && !empty($b['daily_end']) && $b['daily_end'] < $b['daily_start'];
                if (!($overnight && date('H:i:s', $ts) < $b['daily_end'] && in_array((int) date('N', $ts - 86400), $days, true))) {
                    return false;
                }
            }
        }
        if (!empty($b['daily_start']) && !empty($b['daily_end'])) {
            $t = date('H:i:s', $ts);
            $s = $b['daily_start'];
            $e = $b['daily_end'];
            return $s <= $e ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
        }
        return true;
    }

    /** Overlay settings (clock, logo, weather, ticker) — public so admin previews can reuse it. */
    public static function overlay(): array
    {
        $ticker = trim((string) Settings::get('ticker_text', ''));
        $weather = ['enabled' => false];
        if (Settings::bool('overlay_weather')) {
            $w = Weather::current();
            $weather = $w ? ['enabled' => true, 'city' => (string) Settings::get('weather_city', '')] + $w : ['enabled' => false];
        }
        return [
            'clock' => Settings::bool('overlay_clock'),
            'clock_format' => (string) Settings::get('overlay_clock_format', 'hh:mm a'),
            'weather' => $weather,
            'ticker' => $ticker === '' ? null : [
                'text' => $ticker,
                'speed' => max(1, min(10, Settings::int('ticker_speed', 5))),
                'bg_color' => clean_color((string) Settings::get('ticker_bg_color'), '#000000'),
                'text_color' => clean_color((string) Settings::get('ticker_text_color'), '#FFD700'),
            ],
            'logo' => Settings::bool('overlay_logo'),
        ];
    }

    private static function finish(array $content): array
    {
        $content['hash'] = sha1(json_out($content));
        $content['generated_at'] = date('c');
        return $content;
    }

    /** Short human description of what a room is showing, for the admin panel. */
    public static function describe(array $content): string
    {
        return match ($content['mode']) {
            'off' => __('Screen off'),
            'empty' => __('Welcome screen'),
            'emergency' => '⚠ ' . ($content['emergency']['title'] ?? __('Emergency')),
            default => ($content['playlist']['name'] ?? ($content['items'][0]['title'] ?? '-')),
        };
    }
}
