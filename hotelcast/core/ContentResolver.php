<?php
declare(strict_types=1);

/**
 * Decides what a room's TV should show right now and builds the Content object.
 *
 * Priority: emergency → hotel suspended → room off / scheduled power-off window → holiday "TVs off" → active time-window
 *           broadcast → holiday "show content" → room assignment → group assignment → hotel default → empty (welcome screen).
 * Items that are not approved, outside their validity window or (in playlists) outside their daypart never
 * play (2.4, core/ContentRules.php, core/Holidays.php).
 */
final class ContentResolver
{
    private const CACHE_TTL = 15;

    /** Content object for a room (file-cached per hotel, invalidated by content_version). */
    public static function forRoom(array $room): array
    {
        self::assertRoom($room);
        $key = 'h' . Tenant::id() . ':room:' . $room['id'] . ':v' . Settings::get('content_version', '1') . ':' . floor(time() / 60);
        $ns = Cache::hotelNs('content');
        $cached = Cache::get($ns, $key, self::CACHE_TTL);
        if (is_array($cached)) {
            return $cached;
        }
        $content = self::build($room);
        Cache::set($ns, $key, $content);
        return $content;
    }

    /**
     * Content object for the TV polling with $device (row of `devices`). Same as forRoom(), except that
     * apps older than 2.3.0 (app_version_code < Layouts::MIN_APP_CODE) get every split screen layout
     * replaced by the items of its largest zone (they do not know type 'layout').
     */
    public static function forDevice(array $room, array $device): array
    {
        $content = self::forRoom($room);
        return Layouts::legacyDevice($device) ? Layouts::downgradeContent($content) : $content;
    }

    /** A room may only be resolved inside its own hotel's context. */
    private static function assertRoom(array $room): void
    {
        if (isset($room['hotel_id']) && (int) $room['hotel_id'] !== Tenant::id()) {
            throw new TenantException('Room belongs to another hotel');
        }
    }

    public static function build(array $room): array
    {
        self::assertRoom($room);
        $hid = Tenant::id();
        $groupIds = array_map('intval', DB::column(
            'SELECT m.group_id FROM room_group_members m JOIN room_groups g ON g.id = m.group_id WHERE m.room_id = :r AND g.hotel_id = :h',
            ['r' => $room['id'], 'h' => $hid]
        ));
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
                'id' => $hid,
                'name' => (string) Settings::get('hotel_name', ''),
                'logo_url' => media_url((string) Settings::get('hotel_logo', '')),
            ],
            'playlist' => null,
            'items' => [],
            'overlay' => self::overlay(),
            'emergency' => null,
            'power_off_mode' => Settings::get('power_off_mode', 'standby') === 'black' ? 'black' : 'standby',
        ];

        // 1. Emergency (always shown, even when the hotel is suspended — safety first)
        $emergencies = DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :h AND is_emergency = 1 AND status = 'active' ORDER BY id DESC", ['h' => $hid]);
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
                return self::finish($content, $room);
            }
        }

        // 1b. Hotel suspended / expired / license invalid → polite "service paused" screen.
        if (!Tenant::isActive()) {
            $content['mode'] = 'suspended';
            $content['suspended'] = Tenant::suspendedMessage();
            return self::finish($content, $room);
        }

        // 2. Room switched off (admin) or inside a scheduled "TV off" window. Emergencies above still wake it.
        if (!(int) $room['is_enabled']) {
            $content['mode'] = 'off';
            $content['screen_on'] = false;
            return self::finish($content, $room);
        }
        foreach (self::powerWindows() as $b) {
            if (self::windowActive($b) && self::targets($b, $room, $groupIds)) {
                $content['mode'] = 'off';
                $content['screen_on'] = false;
                $content['power_schedule_id'] = (int) $b['id'];
                return self::finish($content, $room);
            }
        }
        // 2b. Holiday calendar (2.4): "TVs off" works like a power-off window for the whole day.
        $holiday = Holidays::forRoom($room, $groupIds);
        if ($holiday && $holiday['action'] === 'tv_off') {
            $content['mode'] = 'off';
            $content['screen_on'] = false;
            $content['off_reason'] = 'holiday';
            $content['holiday_id'] = (int) $holiday['id'];
            return self::finish($content, $room);
        }

        // 3. Time-window broadcasts
        $windows = DB::all(
            "SELECT * FROM broadcast_commands
             WHERE hotel_id = :h AND mode = 'window' AND is_emergency = 0 AND command = 'SHOW_CONTENT' AND status IN ('scheduled','active')
               AND (start_at IS NULL OR start_at <= :now) AND (end_at IS NULL OR end_at > :now2)
             ORDER BY id DESC",
            ['h' => $hid, 'now' => now(), 'now2' => now()]
        );
        foreach ($windows as $b) {
            if (self::windowActive($b) && self::targets($b, $room, $groupIds)
                && self::fill($content, $b['content_id'] ? (int) $b['content_id'] : null, $b['playlist_id'] ? (int) $b['playlist_id'] : null)) {
                $content['mode'] = 'scheduled';
                $content['broadcast_id'] = (int) $b['id'];
                return self::finish($content, $room);
            }
        }
        // 3b. Holiday "show content" (2.4)
        if ($holiday && $holiday['action'] === 'show_content'
            && self::fill($content, $holiday['content_id'] ? (int) $holiday['content_id'] : null, $holiday['playlist_id'] ? (int) $holiday['playlist_id'] : null)) {
            $content['mode'] = 'scheduled';
            $content['holiday_id'] = (int) $holiday['id'];
            return self::finish($content, $room);
        }

        // 4. Room assignment
        if (self::fill($content, $room['content_id'] ? (int) $room['content_id'] : null, $room['playlist_id'] ? (int) $room['playlist_id'] : null)) {
            $content['mode'] = 'assigned';
            return self::finish($content, $room);
        }

        // 5. Group assignment (lowest group id first for determinism)
        if ($groupIds) {
            [$in, $params] = DB::in($groupIds, 'g');
            $groups = DB::all("SELECT content_id, playlist_id FROM room_groups WHERE hotel_id = :h AND id IN $in AND (content_id IS NOT NULL OR playlist_id IS NOT NULL) ORDER BY id", $params + ['h' => $hid]);
            foreach ($groups as $g) {
                if (self::fill($content, $g['content_id'] ? (int) $g['content_id'] : null, $g['playlist_id'] ? (int) $g['playlist_id'] : null)) {
                    $content['mode'] = 'group';
                    return self::finish($content, $room);
                }
            }
        }

        // 6. Default
        $defC = Settings::int('default_content_id', 0) ?: null;
        $defP = Settings::int('default_playlist_id', 0) ?: null;
        if (self::fill($content, $defC, $defP)) {
            $content['mode'] = 'default';
        }
        return self::finish($content, $room);
    }

    /** Enabled "TV off" schedules (SCREEN_OFF windows). */
    public static function powerWindows(): array
    {
        return DB::all(
            "SELECT * FROM broadcast_commands
             WHERE hotel_id = :h AND mode = 'window' AND command = 'SCREEN_OFF' AND status IN ('scheduled','active')
               AND (start_at IS NULL OR start_at <= :now) AND (end_at IS NULL OR end_at > :now2)
             ORDER BY id",
            ['h' => Tenant::id(), 'now' => now(), 'now2' => now()]
        );
    }

    /** Fill items from a playlist (preferred) or single content item. */
    private static function fill(array &$content, ?int $contentId, ?int $playlistId): bool
    {
        if ($playlistId) {
            $pl = ContentManager::findOwnPlaylist($playlistId);
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
            $item = ContentManager::findOwn($contentId);
            if ($item && ContentRules::playable($item)) { // active + approved + validity window (2.4)
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

    /**
     * Overlay settings (clock, logo, weather) — public so admin previews can reuse it. `ticker` is
     * always null here: TickerExtension fills it per room (Tickers::forRoom, incl. the legacy
     * ticker_text setting), so it is never emitted twice.
     */
    public static function overlay(): array
    {
        $weather = ['enabled' => false];
        if (Settings::bool('overlay_weather')) {
            $w = Weather::current();
            $weather = $w ? ['enabled' => true, 'city' => (string) Settings::get('weather_city', '')] + $w : ['enabled' => false];
        }
        return [
            'clock' => Settings::bool('overlay_clock'),
            'clock_format' => (string) Settings::get('overlay_clock_format', 'hh:mm a'),
            'weather' => $weather,
            'ticker' => null,
            'logo' => Settings::bool('overlay_logo'),
        ];
    }

    /** Run content extensions, then hash. */
    private static function finish(array $content, array $room = []): array
    {
        $content['branding'] = Branding::forTv();
        // Hotel UTC offset (minutes) so players on devices with another clock zone (web player on a UTC
        // Raspberry Pi, a PC abroad) show the hotel's local time. Android TVs use their own zone.
        $content['tz_offset_min'] = intdiv((new DateTime())->getOffset(), 60);
        foreach (self::extensions() as $ext) {
            try {
                $ext->apply($content, $room);
            } catch (Throwable $e) {
                Logger::error('Content extension ' . get_class($ext) . ' failed: ' . $e->getMessage());
            }
        }
        self::sortGuestMenu($content);
        $content['hash'] = sha1(json_out($content));
        $content['generated_at'] = date('c');
        return $content;
    }

    /** @var ContentExtension[]|null */
    private static ?array $extensions = null;

    /** Forget discovered extensions (tests / long-running processes). */
    public static function resetExtensions(): void
    {
        self::$extensions = null;
    }

    /** Instances of every core/Extensions/*.php class implementing ContentExtension (sorted by file name). */
    public static function extensions(): array
    {
        if (self::$extensions === null) {
            self::$extensions = [];
            $files = glob(HC_CORE . '/Extensions/*.php') ?: [];
            sort($files);
            foreach ($files as $f) {
                $class = basename($f, '.php');
                if (!class_exists($class, false)) {
                    require_once $f;
                }
                if (class_exists($class, false) && is_subclass_of($class, 'ContentExtension')) {
                    self::$extensions[] = new $class();
                }
            }
            // Optional `public const PRIORITY` (lower runs first, default 100): the guests module sets
            // the guest language that later extensions use for their labels.
            usort(self::$extensions, static fn ($a, $b) => self::priority($a) <=> self::priority($b));
        }
        return self::$extensions;
    }

    private static function priority(object $ext): int
    {
        $c = get_class($ext) . '::PRIORITY';
        return defined($c) ? (int) constant($c) : 100;
    }

    /** Fixed guest-menu order on the TV: room services first, then guide, TV inputs, cast. */
    public static function sortGuestMenu(array &$content): void
    {
        if (empty($content['guest_menu']) || !is_array($content['guest_menu'])) {
            return;
        }
        $rank = static function (array $m): int {
            $id = (string) ($m['id'] ?? '');
            return match (true) {
                $id === 'services' => 10, $id === 'requests' => 20, $id === 'feedback' => 30,
                $id === 'guide' => 40, $id === 'live_tv' => 50, str_starts_with($id, 'hdmi') => 60,
                $id === 'cast' => 70, default => 80,
            };
        };
        $menu = array_values(array_filter($content['guest_menu'], 'is_array'));
        usort($menu, static fn ($a, $b) => $rank($a) <=> $rank($b)); // stable in PHP 8
        $content['guest_menu'] = $menu;
    }

    /** Short human description of what a room is showing, for the admin panel. */
    public static function describe(array $content): string
    {
        return match ($content['mode']) {
            'off' => __('Screen off'),
            'suspended' => __('Service paused'),
            'empty' => __('Welcome screen'),
            'emergency' => '⚠ ' . ($content['emergency']['title'] ?? __('Emergency')),
            default => ($content['playlist']['name'] ?? ($content['items'][0]['title'] ?? '-')),
        };
    }
}
