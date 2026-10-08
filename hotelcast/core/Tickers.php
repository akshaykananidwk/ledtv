<?php
declare(strict_types=1);

/**
 * Ticker bar (2.2): scrolling text bars per TV (room), per group or for all TVs of a hotel.
 * Table `tickers` (migrations/010_tickers_access.sql), admin page admin/tickers.php, TV output via
 * core/Extensions/TickerExtension.php → content.overlay.ticker (docs/modules/ticker_bar.md).
 *
 * Resolution for a room (forRoom):
 *   1. active tickers: is_active = 1, inside starts_at / ends_at, inside time_from–time_to (overnight
 *      windows allowed) and today in `days` (ISO 1 = Mon … 7 = Sun like broadcast repeat_days;
 *      0 is accepted as Sunday) — evaluated with ContentResolver::windowActive();
 *   2. matching the room: target all, a group the room belongs to, or the room itself;
 *   3. the legacy hotel setting `ticker_text` (chain templates) counts as an extra 'all' ticker with
 *      priority -1000 and the ticker_* setting colours / speed;
 *   4. specificity room 3 > group 2 > all 1. An override_lower ticker hides the less specific
 *      ones (only those below the most specific override ticker);
 *   5. messages ordered by specificity desc, priority desc, id asc and joined with SEPARATOR;
 *      the style comes from the first one.
 */
final class Tickers
{
    public const SEPARATOR = '   ✦   ';
    public const TARGETS = ['all', 'group', 'room'];
    public const POSITIONS = ['bottom', 'top'];
    /** fill = no black side bars (default), fit = aspect kept with bars, zoom = cropped. */
    public const VIDEO_SCALES = ['fill', 'fit', 'zoom'];
    public const SPECIFICITY = ['room' => 3, 'group' => 2, 'all' => 1];
    public const LEGACY_PRIORITY = -1000;
    public const MAX_MESSAGE = 1000;

    public const DEFAULTS = [
        'id' => 0, 'name' => '', 'message' => '', 'target_type' => 'all', 'target_id' => null,
        'text_color' => '#FFD700', 'bg_color' => '#000000', 'speed' => 5, 'font_size' => 26, 'height' => 56,
        'position' => 'bottom', 'reserve_space' => 1, 'video_scale' => 'fill', 'override_lower' => 0, 'priority' => 0,
        'starts_at' => null, 'ends_at' => null, 'time_from' => null, 'time_to' => null, 'days' => null, 'is_active' => 1,
    ];

    /** Colour presets for the admin form: [label, background, text]. */
    public const PRESETS = [
        ['Classic', '#000000', '#FFD700'],
        ['Temple', '#7B1FA2', '#FFFFFF'],
        ['Saffron', '#FF9933', '#000000'],
        ['Night', '#0F172A', '#E2E8F0'],
        ['Alert', '#B00020', '#FFFFFF'],
        ['Fresh', '#065F46', '#FFFFFF'],
        ['Royal', '#1E3A8A', '#FDE68A'],
        ['Paper', '#FFFFFF', '#111827'],
    ];

    // ------------------------------------------------------------------ validation / CRUD

    /**
     * Validate admin input. Returns [data, errors]. Ids of groups / rooms are checked with
     * Tenant::find (another hotel's id → Tenant::deny, 404) and Access (TVs the user may not
     * control → Access::deny, 403). A restricted user cannot target all TVs (validation error).
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $str = static fn (string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $int = static function (string $k, int $min, int $max, int $def) use ($in): int {
            $v = $in[$k] ?? null;
            if (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,6}$/', trim($v)))) {
                return max($min, min($max, (int) $v));
            }
            return $def;
        };
        $flag = static fn (string $k): int => isset($in[$k]) && is_scalar($in[$k]) && in_array((string) $in[$k], ['1', 'on', 'true'], true) ? 1 : 0;

        $message = is_string($in['message'] ?? null) ? trim(str_replace("\r\n", "\n", $in['message'])) : '';
        if ($message === '') {
            $errors[] = __('The ticker message is required.');
        } elseif (mb_strlen($message) > self::MAX_MESSAGE) {
            $errors[] = __('The ticker message may have at most :n characters.', ['n' => self::MAX_MESSAGE]);
        }
        $name = $str('name', 120);
        if ($name === '') {
            $name = mb_substr(preg_replace('/\s+/u', ' ', $message) ?? '', 0, 60);
        }

        $type = in_array($in['target_type'] ?? '', self::TARGETS, true) ? (string) $in['target_type'] : 'all';
        $targetId = null;
        if ($type === 'all') {
            if (Access::restricted()) {
                $errors[] = __('You may only choose the TVs assigned to you, not all TVs.');
            }
        } else {
            $raw = $type === 'group' ? ($in['group_id'] ?? $in['target_id'] ?? 0) : ($in['room_id'] ?? $in['target_id'] ?? 0);
            $id = is_int($raw) || (is_string($raw) && ctype_digit($raw) && strlen($raw) < 10) ? (int) $raw : 0;
            $row = $id > 0 ? Tenant::find($type === 'group' ? 'room_groups' : 'rooms', $id) : null;
            if (!$row) {
                $errors[] = $type === 'group' ? __('Choose a group.') : __('Choose a screen.');
            } else {
                Access::requireTarget($type, $id);
                $targetId = $id;
            }
        }

        $parseDt = static function (string $k) use ($str, &$errors): ?string {
            $v = $str($k, 25);
            if ($v === '') {
                return null;
            }
            $d = DateTime::createFromFormat('!Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('!Y-m-d H:i:s', $v) ?: DateTime::createFromFormat('!Y-m-d H:i', $v) ?: DateTime::createFromFormat('!Y-m-d', $v);
            if (!$d) {
                $errors[] = __('Invalid date: :v', ['v' => $v]);
                return null;
            }
            return $d->format('Y-m-d H:i:s');
        };
        $startsAt = $parseDt('starts_at');
        $endsAt = $parseDt('ends_at');
        if ($startsAt && $endsAt && $endsAt <= $startsAt) {
            $errors[] = __('The end date must be after the start date.');
        }

        $parseTime = static function (string $k) use ($str, &$errors): ?string {
            $v = $str($k, 8);
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v)) {
                $errors[] = __('Invalid time: :v', ['v' => $v]);
                return null;
            }
            return substr($v, 0, 5) . ':00';
        };
        $timeFrom = $parseTime('time_from');
        $timeTo = $parseTime('time_to');
        if (($timeFrom === null) !== ($timeTo === null)) {
            $errors[] = __('Set both times of the daily window, or neither.');
        } elseif ($timeFrom !== null && $timeFrom === $timeTo) {
            $errors[] = __('The daily window needs different start and end times.');
        }

        $days = self::normalizeDays($in['days'] ?? []);

        $data = [
            'name' => $name,
            'message' => $message,
            'target_type' => $type,
            'target_id' => $targetId,
            'text_color' => clean_color(is_string($in['text_color'] ?? null) ? $in['text_color'] : null, '#FFD700'),
            'bg_color' => clean_color(is_string($in['bg_color'] ?? null) ? $in['bg_color'] : null, '#000000'),
            'speed' => $int('speed', 1, 10, 5),
            'font_size' => $int('font_size', 14, 72, 26),
            'height' => $int('height', 32, 200, 56),
            'position' => in_array($in['position'] ?? '', self::POSITIONS, true) ? (string) $in['position'] : 'bottom',
            'reserve_space' => $flag('reserve_space'),
            'video_scale' => in_array($in['video_scale'] ?? '', self::VIDEO_SCALES, true) ? (string) $in['video_scale'] : 'fill',
            'override_lower' => $flag('override_lower'),
            'priority' => $int('priority', -999, 999, 0),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'days' => $days === [] || count($days) === 7 ? null : implode(',', $days),
            'is_active' => $flag('is_active'),
        ];
        return [$data, $errors];
    }

    /** Days input (array or "1,3,5") → sorted unique ISO days 1..7 (0 = Sunday is accepted). */
    public static function normalizeDays(mixed $v): array
    {
        $list = is_string($v) ? explode(',', $v) : (array) $v;
        $out = [];
        foreach ($list as $d) {
            if (is_int($d) || (is_string($d) && preg_match('/^\s*\d\s*$/', $d))) {
                $d = (int) $d;
                if ($d === 0) {
                    $d = 7;
                }
                if ($d >= 1 && $d <= 7) {
                    $out[$d] = $d;
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Insert (id null) or update a ticker of the current hotel. Returns the id. */
    public static function save(?int $id, array $data): int
    {
        $data = array_intersect_key($data, self::DEFAULTS);
        unset($data['id']);
        if ($id) {
            DB::update('tickers', $data + ['updated_at' => now()], 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('tickers', $data + ['created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        }
        Settings::bumpContentVersion();
        return (int) $id;
    }

    public static function delete(int $id): void
    {
        DB::delete('tickers', 'id = :id', ['id' => $id]);
        Settings::bumpContentVersion();
    }

    public static function setActive(int $id, bool $on): void
    {
        DB::update('tickers', ['is_active' => $on ? 1 : 0, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        Settings::bumpContentVersion();
    }

    /** A ticker of the current hotel that the current user may see / edit (null otherwise). */
    public static function findVisible(int $id): ?array
    {
        $t = Tenant::find('tickers', $id);
        return $t && self::visible($t) ? $t : null;
    }

    /** May the current user see / edit this ticker? (Access: restricted users never see 'all'.) */
    public static function visible(array $t): bool
    {
        return Access::canTarget((string) $t['target_type'], $t['target_id'] !== null ? (int) $t['target_id'] : null);
    }

    /** Tickers of the current hotel the current user may see, newest first. */
    public static function listVisible(): array
    {
        $rows = DB::all('SELECT * FROM tickers WHERE hotel_id = :h ORDER BY is_active DESC, priority DESC, id DESC', ['h' => Tenant::id()]);
        return array_values(array_filter($rows, [self::class, 'visible']));
    }

    // ------------------------------------------------------------------ state

    /** live | scheduled (not started yet, or outside its daily window / days) | expired | paused (switched off). */
    public static function state(array $t, ?int $ts = null): string
    {
        $ts ??= time();
        if (!(int) $t['is_active']) {
            return 'paused';
        }
        if (!empty($t['ends_at']) && strtotime((string) $t['ends_at']) <= $ts) {
            return 'expired';
        }
        return self::activeAt($t, $ts) ? 'live' : 'scheduled';
    }

    /** Is the ticker on air at $ts (is_active, date range, days, daily window incl. overnight)? */
    public static function activeAt(array $t, ?int $ts = null): bool
    {
        if (!(int) ($t['is_active'] ?? 0)) {
            return false;
        }
        $days = self::normalizeDays((string) ($t['days'] ?? ''));
        return ContentResolver::windowActive([
            'start_at' => $t['starts_at'] ?? null,
            'end_at' => $t['ends_at'] ?? null,
            'repeat_days' => $days ? implode(',', $days) : null,
            'daily_start' => $t['time_from'] ?? null,
            'daily_end' => $t['time_to'] ?? null,
        ], $ts);
    }

    // ------------------------------------------------------------------ resolution

    /** content.overlay.ticker for a room (null = no ticker). */
    public static function forRoom(array $room, ?int $ts = null): ?array
    {
        if (isset($room['hotel_id']) && (int) $room['hotel_id'] !== Tenant::id()) {
            throw new TenantException('Room belongs to another hotel');
        }
        $rid = (int) $room['id'];
        return self::resolve(self::activeRows($ts), $rid, self::groupIdsOf([$rid])[$rid] ?? []);
    }

    /**
     * Preview for a target: 'all' = what every TV shows from hotel-wide tickers, 'group' = a TV that is
     * only in that group, 'room' = exactly that room (same as forRoom).
     */
    public static function forTarget(string $type, int|string|null $id = null, ?int $ts = null): ?array
    {
        if ($type === 'room') {
            $room = Tenant::find('rooms', (int) $id);
            return $room ? self::forRoom($room, $ts) : null;
        }
        $groups = $type === 'group' && Tenant::find('room_groups', (int) $id) ? [(int) $id] : [];
        return self::resolve(self::activeRows($ts), 0, $groups);
    }

    /** Resolved ticker of many rooms at once: [room_id => ticker|null] (admin overview). */
    public static function overview(array $rooms, ?int $ts = null): array
    {
        $rows = self::activeRows($ts);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rooms);
        $groups = self::groupIdsOf($ids);
        $out = [];
        foreach ($ids as $rid) {
            $out[$rid] = self::resolve($rows, $rid, $groups[$rid] ?? []);
        }
        return $out;
    }

    /** Ticker rows of the current hotel active at $ts, plus the legacy setting ticker. */
    public static function activeRows(?int $ts = null): array
    {
        $ts ??= time();
        $rows = [];
        try {
            $all = DB::all('SELECT * FROM tickers WHERE hotel_id = :h AND is_active = 1', ['h' => Tenant::id()]);
        } catch (PDOException $e) {
            // Table not migrated yet (update in progress): legacy setting only.
            Logger::error('Tickers: ' . $e->getMessage());
            $all = [];
        }
        foreach ($all as $t) {
            if (self::activeAt($t, $ts)) {
                $rows[] = $t;
            }
        }
        $legacy = self::legacy();
        if ($legacy !== null) {
            $rows[] = $legacy;
        }
        return $rows;
    }

    /** The old hotel setting ticker_text (Settings page ≤ 2.1, chain templates) as an 'all' ticker. */
    public static function legacy(): ?array
    {
        $text = trim((string) Settings::get('ticker_text', ''));
        if ($text === '') {
            return null;
        }
        return [
            'id' => 0, 'legacy' => true, 'name' => '', 'message' => $text, 'target_type' => 'all', 'target_id' => null,
            'text_color' => clean_color((string) Settings::get('ticker_text_color'), '#FFD700'),
            'bg_color' => clean_color((string) Settings::get('ticker_bg_color'), '#000000'),
            'speed' => max(1, min(10, Settings::int('ticker_speed', 5))),
            'font_size' => 26, 'height' => 56, 'position' => 'bottom', 'reserve_space' => 1, 'video_scale' => 'fill',
            'override_lower' => 0, 'priority' => self::LEGACY_PRIORITY, 'is_active' => 1,
        ];
    }

    /** Pick, order and merge the rows that apply to a room (room id 0 = none) in the given groups. */
    public static function resolve(array $rows, int $roomId, array $groupIds): ?array
    {
        $match = [];
        foreach ($rows as $t) {
            $tid = $t['target_id'] !== null ? (int) $t['target_id'] : 0;
            $ok = match ((string) $t['target_type']) {
                'all' => true,
                'group' => $tid > 0 && in_array($tid, $groupIds, true),
                'room' => $roomId > 0 && $tid === $roomId,
                default => false,
            };
            if ($ok) {
                $match[] = $t;
            }
        }
        if (!$match) {
            return null;
        }
        $spec = static fn (array $t): int => self::SPECIFICITY[(string) $t['target_type']] ?? 0;
        $overrideLevel = 0;
        foreach ($match as $t) {
            if ((int) ($t['override_lower'] ?? 0)) {
                $overrideLevel = max($overrideLevel, $spec($t));
            }
        }
        if ($overrideLevel > 0) {
            $match = array_values(array_filter($match, static fn ($t) => $spec($t) >= $overrideLevel));
        }
        usort($match, static fn ($a, $b) => [$spec($b), (int) $b['priority'], (int) $a['id']] <=> [$spec($a), (int) $a['priority'], (int) $b['id']]);
        $messages = [];
        foreach ($match as $t) {
            $m = trim((string) (preg_replace('/\s+/u', ' ', (string) $t['message']) ?? ''));
            if ($m !== '') {
                $messages[] = $m;
            }
        }
        if (!$messages) {
            return null;
        }
        return self::payload($match[0], $messages);
    }

    /** TV contract object (docs/modules/ticker_bar.md). */
    public static function payload(array $style, array $messages): array
    {
        return [
            'text' => implode(self::SEPARATOR, $messages),
            'messages' => array_values($messages),
            'speed' => max(1, min(10, (int) ($style['speed'] ?? 5))),
            'bg_color' => clean_color((string) ($style['bg_color'] ?? ''), '#000000'),
            'text_color' => clean_color((string) ($style['text_color'] ?? ''), '#FFD700'),
            'font_size' => max(14, min(72, (int) ($style['font_size'] ?? 26) ?: 26)),
            'height' => max(32, min(200, (int) ($style['height'] ?? 56) ?: 56)),
            'position' => ($style['position'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
            'reserve_space' => (bool) (int) ($style['reserve_space'] ?? 1),
            'video_scale' => in_array($style['video_scale'] ?? 'fill', self::VIDEO_SCALES, true) ? (string) ($style['video_scale'] ?? 'fill') : 'fill',
        ];
    }

    /** [room_id => [group ids]] for rooms of the current hotel. */
    private static function groupIdsOf(array $roomIds): array
    {
        $roomIds = array_values(array_filter(array_map('intval', $roomIds)));
        if (!$roomIds) {
            return [];
        }
        [$in, $p] = DB::in($roomIds, 'tr');
        $out = [];
        foreach (DB::all("SELECT m.room_id, m.group_id FROM room_group_members m JOIN room_groups g ON g.id = m.group_id WHERE g.hotel_id = :h AND m.room_id IN $in", $p + ['h' => Tenant::id()]) as $r) {
            $out[(int) $r['room_id']][] = (int) $r['group_id'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ admin helpers

    /** Human name of a ticker's target ("All TVs", "Group: VIP", "Room 101"). */
    public static function targetName(array $t, ?array $groups = null, ?array $rooms = null): string
    {
        $id = $t['target_id'] !== null ? (int) $t['target_id'] : 0;
        if ($t['target_type'] === 'group') {
            $name = $groups !== null ? ($groups[$id] ?? null) : DB::value('SELECT name FROM room_groups WHERE id = :id AND hotel_id = :h', ['id' => $id, 'h' => Tenant::id()]);
            return __('Group') . ': ' . ($name !== null && $name !== false ? (string) $name : __('(deleted)'));
        }
        if ($t['target_type'] === 'room') {
            $num = $rooms !== null ? ($rooms[$id] ?? null) : DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $id, 'h' => Tenant::id()]);
            return __('Screen') . ' ' . ($num !== null && $num !== false ? (string) $num : __('(deleted)'));
        }
        return __('All TVs');
    }
}
