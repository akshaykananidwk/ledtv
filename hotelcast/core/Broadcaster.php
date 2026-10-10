<?php
declare(strict_types=1);

/**
 * Pushing content and commands to TVs: instant push, scheduled push, time-window
 * broadcasts, emergency override and device commands.
 */
final class Broadcaster
{
    public const DEVICE_COMMANDS = ['REBOOT', 'CLEAR_CACHE', 'UPDATE_APP', 'SCREEN_OFF', 'SCREEN_ON', 'RELOAD', 'PING', 'SHOW_CONTENT',
        // 2.0 (V2_SPEC § TV contract): volume, support tools, inputs, guest messages.
        'SET_VOLUME', 'MUTE', 'UNMUTE', 'SCREENSHOT', 'UPLOAD_LOGS', 'OPEN_INPUT', 'SHOW_WELCOME', 'SHOW_MESSAGE',
        // 2.4 (device schedules): text-to-speech announcement and bell / chime sound.
        'SPEAK', 'PLAY_SOUND'];
    public const TARGET_TYPES = ['all', 'rooms', 'groups', 'floors'];

    /** Resolve target → list of room rows (current hotel only). */
    public static function targetRooms(string $type, array $ids): array
    {
        $h = ['hid' => Tenant::id()];
        switch ($type) {
            case 'all':
                return DB::all('SELECT * FROM rooms WHERE hotel_id = :hid ORDER BY room_number', $h);
            case 'rooms':
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!$ids) {
                    return [];
                }
                [$in, $p] = DB::in($ids, 'r');
                return DB::all("SELECT * FROM rooms WHERE hotel_id = :hid AND id IN $in ORDER BY room_number", $p + $h);
            case 'groups':
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!$ids) {
                    return [];
                }
                [$in, $p] = DB::in($ids, 'g');
                return DB::all("SELECT DISTINCT r.* FROM rooms r JOIN room_group_members m ON m.room_id = r.id JOIN room_groups g ON g.id = m.group_id AND g.hotel_id = r.hotel_id WHERE r.hotel_id = :hid AND m.group_id IN $in ORDER BY r.room_number", $p + $h);
            case 'floors':
                $ids = array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== ''));
                if (!$ids) {
                    return [];
                }
                [$in, $p] = DB::in($ids, 'f');
                return DB::all("SELECT * FROM rooms WHERE hotel_id = :hid AND floor IN $in ORDER BY room_number", $p + $h);
        }
        return [];
    }

    /**
     * Normalise target input from forms: returns [type, ids]. Room / group ids of another hotel are
     * refused (Tenant::deny → 404); ids that do not exist are dropped. A user limited to some TVs
     * (core/Access.php) may only target their rooms / assigned groups, never 'all' (403).
     */
    public static function parseTarget(array $in): array
    {
        $t = (string) ($in['target_type'] ?? 'all');
        $type = in_array($t, self::TARGET_TYPES, true) ? $t : 'all';
        $ids = match ($type) {
            'rooms' => array_map('intval', (array) ($in['room_ids'] ?? $in['target_ids'] ?? [])),
            'groups' => array_map('intval', (array) ($in['group_ids'] ?? $in['target_ids'] ?? [])),
            'floors' => array_map('strval', (array) ($in['floors'] ?? $in['target_ids'] ?? [])),
            default => [],
        };
        $ids = array_values(array_unique($ids));
        if ($type === 'rooms' && $ids) {
            $owned = Tenant::assertOwnsAll('rooms', $ids);
            $ids = array_values(array_filter($ids, fn ($i) => in_array($i, $owned, true)));
        } elseif ($type === 'groups' && $ids) {
            $owned = Tenant::assertOwnsAll('room_groups', $ids);
            $ids = array_values(array_filter($ids, fn ($i) => in_array($i, $owned, true)));
        }
        Access::requireTargetList($type, $ids);
        return [$type, $ids];
    }

    public static function describeTarget(string $type, array|string|null $ids): string
    {
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        $ids = (array) $ids;
        switch ($type) {
            case 'all':
                return __('All screens');
            case 'rooms':
                if (!$ids) {
                    return __('No screens');
                }
                [$in, $p] = DB::in(array_map('intval', $ids), 'r');
                $nums = DB::column("SELECT room_number FROM rooms WHERE hotel_id = :hid AND id IN $in ORDER BY room_number", $p + ['hid' => Tenant::id()]);
                return __('Screens') . ': ' . (count($nums) > 8 ? implode(', ', array_slice($nums, 0, 8)) . ' +' . (count($nums) - 8) : implode(', ', $nums));
            case 'groups':
                if (!$ids) {
                    return __('No groups');
                }
                [$in, $p] = DB::in(array_map('intval', $ids), 'g');
                return __('Groups') . ': ' . implode(', ', DB::column("SELECT name FROM room_groups WHERE hotel_id = :hid AND id IN $in ORDER BY name", $p + ['hid' => Tenant::id()]));
            case 'floors':
                return __('Floors') . ': ' . implode(', ', $ids);
        }
        return '-';
    }

    /** Queue a command for every device in the given rooms. Returns number of devices. */
    public static function queueForRooms(array $rooms, string $command, array $payload = [], ?int $broadcastId = null): int
    {
        $roomIds = array_map(fn ($r) => (int) $r['id'], $rooms);
        if (!$roomIds) {
            return 0;
        }
        [$in, $p] = DB::in($roomIds, 'r');
        // 2.4: web players cannot install an APK — UPDATE_APP is not queued for them.
        $notWeb = $command === 'UPDATE_APP' ? DeviceManager::notWebSql() : '';
        $devices = DB::all("SELECT id, room_id FROM devices WHERE hotel_id = :hid AND is_revoked = 0 AND room_id IN $in" . $notWeb, $p + ['hid' => Tenant::id()]);
        $json = json_out((object) $payload);
        foreach ($devices as $d) {
            if ($command === 'SHOW_CONTENT') {
                // Collapse duplicate pending refreshes.
                DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND command = 'SHOW_CONTENT' AND status IN ('pending','delivered')", ['d' => $d['id']]);
            }
            DB::insert('device_commands', [
                'device_id' => $d['id'],
                'broadcast_id' => $broadcastId,
                'command' => $command,
                'payload' => $json,
                'status' => 'pending',
                'created_at' => now(),
            ]);
            if ($broadcastId) {
                DB::insert('broadcast_logs', [
                    'broadcast_id' => $broadcastId, 'device_id' => $d['id'], 'room_id' => $d['room_id'],
                    'event' => 'queued', 'message' => $command, 'created_at' => now(),
                ]);
            }
        }
        return count($devices);
    }

    /**
     * Push content now: assigns the content/playlist to every targeted room and tells TVs to refresh.
     * Returns broadcast id.
     */
    public static function pushNow(string $targetType, array $ids, ?int $contentId, ?int $playlistId, string $title = '', ?int $userId = null): int
    {
        if (($contentId && !ContentManager::find($contentId)) || ($playlistId && !ContentManager::findPlaylist($playlistId))) {
            throw new InvalidArgumentException(__('Select content or a playlist.'));
        }
        if (!$contentId && !$playlistId) {
            throw new InvalidArgumentException(__('Select content or a playlist.'));
        }
        if (!$playlistId && !ContentRules::approved((array) ContentManager::find($contentId))) { // 2.4 approvals (#32)
            throw new InvalidArgumentException(__('This content is waiting for approval and cannot be used yet.'));
        }
        Access::requireTargetList($targetType, $ids);
        $rooms = self::targetRooms($targetType, $ids);
        if (!$rooms) {
            throw new InvalidArgumentException(__('No screens match the selected target.'));
        }
        return DB::transaction(function () use ($rooms, $targetType, $ids, $contentId, $playlistId, $title, $userId) {
            $bid = DB::insert('broadcast_commands', [
                'title' => mb_substr($title ?: self::contentTitle($contentId, $playlistId), 0, 190),
                'command' => 'SHOW_CONTENT',
                'target_type' => $targetType,
                'target_ids' => json_out($ids),
                'content_id' => $playlistId ? null : $contentId,
                'playlist_id' => $playlistId,
                'mode' => 'now',
                'status' => 'completed',
                'start_at' => now(),
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            self::applyAssignment($rooms, $playlistId ? null : $contentId, $playlistId);
            self::queueForRooms($rooms, 'SHOW_CONTENT', [], $bid);
            return $bid;
        });
    }

    private static function applyAssignment(array $rooms, ?int $contentId, ?int $playlistId): void
    {
        $roomIds = array_map(fn ($r) => (int) $r['id'], $rooms);
        [$in, $p] = DB::in($roomIds, 'r');
        DB::query("UPDATE rooms SET content_id = :c, playlist_id = :pl WHERE hotel_id = :hid AND id IN $in", $p + ['c' => $contentId, 'pl' => $playlistId, 'hid' => Tenant::id()]);
        Settings::bumpContentVersion();
    }

    public static function contentTitle(?int $contentId, ?int $playlistId): string
    {
        if ($playlistId) {
            return (string) (DB::value('SELECT name FROM content_playlists WHERE id = :id AND hotel_id = :hid', ['id' => $playlistId, 'hid' => Tenant::id()]) ?? 'Playlist');
        }
        if ($contentId) {
            return (string) (DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :hid', ['id' => $contentId, 'hid' => Tenant::id()]) ?? 'Content');
        }
        return '';
    }

    /**
     * Schedule a broadcast.
     *  mode 'once'   — at start_at the content is pushed (becomes the room's assignment).
     *  mode 'window' — content overrides between start_at/end_at, optionally only on repeat days
     *                  and within daily_start..daily_end; afterwards rooms return to normal content.
     */
    public static function schedule(array $data, ?int $userId = null): int
    {
        $id = DB::insert('broadcast_commands', [
            'title' => mb_substr((string) $data['title'], 0, 190),
            'command' => 'SHOW_CONTENT',
            'target_type' => $data['target_type'],
            'target_ids' => json_out($data['target_ids']),
            'content_id' => $data['content_id'] ?: null,
            'playlist_id' => $data['playlist_id'] ?: null,
            'mode' => $data['mode'],
            'status' => 'scheduled',
            'start_at' => $data['start_at'] ?: null,
            'end_at' => $data['end_at'] ?: null,
            'daily_start' => $data['daily_start'] ?: null,
            'daily_end' => $data['daily_end'] ?: null,
            'repeat_days' => $data['repeat_days'] ?: null,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
        Settings::bumpContentVersion();
        return $id;
    }

    /** Validate schedule form input. Returns [data, errors]. */
    public static function validateSchedule(array $in): array
    {
        $errors = [];
        [$type, $ids] = self::parseTarget($in);
        $source = (string) ($in['source'] ?? '');
        $contentId = null;
        $playlistId = null;
        if (str_starts_with($source, 'c:')) {
            $contentId = (int) substr($source, 2);
        } elseif (str_starts_with($source, 'p:')) {
            $playlistId = (int) substr($source, 2);
        } else {
            $contentId = (int) ($in['content_id'] ?? 0) ?: null;
            $playlistId = (int) ($in['playlist_id'] ?? 0) ?: null;
        }
        if ($contentId && !ContentManager::find($contentId)) {
            $contentId = null;
        }
        if ($playlistId && !ContentManager::findPlaylist($playlistId)) {
            $playlistId = null;
        }
        if (!$contentId && !$playlistId) {
            $errors[] = __('Select content or a playlist.');
        } elseif (!$playlistId && !ContentRules::approved((array) ContentManager::find($contentId))) { // 2.4 approvals (#32)
            $errors[] = __('This content is waiting for approval and cannot be used yet.');
        }
        if ($type !== 'all' && !$ids) {
            $errors[] = __('Select at least one target.');
        }
        $mode = ($in['mode'] ?? 'once') === 'window' ? 'window' : 'once';
        $start = self::parseDateTime($in['start_at'] ?? '');
        $end = self::parseDateTime($in['end_at'] ?? '');
        if ($mode === 'once' && !$start) {
            $errors[] = __('Start date/time is required.');
        }
        if ($start && $end && strtotime($end) <= strtotime($start)) {
            $errors[] = __('End time must be after start time.');
        }
        $ds = self::parseTime($in['daily_start'] ?? '');
        $de = self::parseTime($in['daily_end'] ?? '');
        if (($ds xor $de) || ($ds && $ds === $de)) {
            $errors[] = __('Give both daily start and end time (different values).');
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['repeat_days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if ($mode === 'window' && !$start && !$end && !$ds && !$days) {
            $errors[] = __('A time window needs a date range, daily times or repeat days.');
        }
        $title = trim((string) ($in['title'] ?? '')) ?: self::contentTitle($contentId, $playlistId);
        return [[
            'title' => $title,
            'target_type' => $type,
            'target_ids' => $ids,
            'content_id' => $playlistId ? null : $contentId,
            'playlist_id' => $playlistId,
            'mode' => $mode,
            'start_at' => $start,
            'end_at' => $mode === 'window' ? $end : null,
            'daily_start' => $mode === 'window' ? $ds : null,
            'daily_end' => $mode === 'window' ? $de : null,
            'repeat_days' => $mode === 'window' && $days ? implode(',', $days) : null,
        ], $errors];
    }

    /**
     * Save validated schedule data (validateSchedule) over an existing schedule row: it becomes
     * 'scheduled' again, TVs that were showing it re-evaluate, then schedules are processed.
     * Used by the calendar (2.4, core/Calendar.php).
     */
    public static function updateSchedule(array $b, array $data): void
    {
        DB::update('broadcast_commands', [
            'title' => mb_substr((string) $data['title'], 0, 190),
            'target_type' => $data['target_type'],
            'target_ids' => json_out($data['target_ids']),
            'content_id' => $data['content_id'] ?: null,
            'playlist_id' => $data['playlist_id'] ?: null,
            'mode' => $data['mode'],
            'status' => 'scheduled',
            'start_at' => $data['start_at'] ?: null,
            'end_at' => $data['end_at'] ?: null,
            'daily_start' => $data['daily_start'] ?: null,
            'daily_end' => $data['daily_end'] ?: null,
            'repeat_days' => $data['repeat_days'] ?: null,
        ], 'id = :id', ['id' => $b['id']]);
        Settings::bumpContentVersion();
        if ($b['status'] === 'active') {
            self::queueForRooms(self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], (int) $b['id']);
        }
        self::processSchedules();
    }

    public static function parseDateTime(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $v));
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    public static function parseTime(?string $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v) ? substr($v . ':00', 0, 8) : null;
    }

    /**
     * Daily "TV off" schedule: TVs go to standby at $offTime and wake at $onTime (overnight allowed,
     * e.g. 23:00 → 06:00) on the given weekdays (1 = Mon … 7 = Sun). Stored as a SCREEN_OFF window.
     * Returns [id|null, errors].
     */
    public static function schedulePower(array $in, ?int $userId = null): array
    {
        [$type, $ids] = self::parseTarget($in);
        $off = self::parseTime($in['off_time'] ?? '');
        $on = self::parseTime($in['on_time'] ?? '');
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $errors = [];
        if (!$off || !$on || $off === $on) {
            $errors[] = __('Give a TV-off time and a TV-on time (different values).');
        }
        if ($type !== 'all' && !$ids) {
            $errors[] = __('Select at least one target.');
        }
        if (!$days) {
            $errors[] = __('Choose at least one day.');
        }
        if ($errors) {
            return [null, $errors];
        }
        $title = trim((string) ($in['title'] ?? '')) ?: __('TV off :a – on :b', ['a' => substr($off, 0, 5), 'b' => substr($on, 0, 5)]);
        $id = DB::insert('broadcast_commands', [
            'title' => mb_substr($title, 0, 190),
            'command' => 'SCREEN_OFF',
            'target_type' => $type,
            'target_ids' => json_out($ids),
            'mode' => 'window',
            'status' => 'scheduled',
            'daily_start' => $off,
            'daily_end' => $on,
            'repeat_days' => implode(',', $days),
            'created_by' => $userId,
            'created_at' => now(),
        ]);
        Settings::bumpContentVersion();
        self::processSchedules();
        return [$id, []];
    }

    /** Pause / resume a power schedule. */
    public static function setPowerScheduleEnabled(int $id, bool $enabled): void
    {
        $b = Tenant::find('broadcast_commands', $id, "command = 'SCREEN_OFF' AND mode = 'window'");
        if (!$b) {
            return;
        }
        Access::requireBroadcast($b);
        DB::update('broadcast_commands', ['status' => $enabled ? 'scheduled' : 'cancelled'], 'id = :id', ['id' => $id]);
        Settings::bumpContentVersion();
        self::queueForRooms(self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], $id);
        if ($enabled) {
            self::processSchedules();
        }
    }

    /**
     * Validated alarm options of an emergency (2.4.1, docs/modules/emergency_alarm.md) from form / JSON input:
     *   alarm_sound   "" / "none" = no alarm, "b:emergency_beep" (default when the key is missing),
     *                 "b:emergency_siren", "b:fire_alarm" or any sound of the hotel's library ("u:12");
     *   alarm_loop    repeat until the emergency is stopped (default on);
     *   alarm_repeat  plays when not looping, 1–10 (default 3);
     *   alarm_volume  0–100, the TV raises its volume to at least this (default 80).
     * Returns ['sound', 'loop', 'repeat', 'volume'] or null (no alarm). Only built-in sounds and the current
     * hotel's uploads are accepted (Sounds::libraryRef, same rule as PLAY_SOUND); another hotel's sound id → 404.
     * Throws InvalidArgumentException.
     */
    public static function alarmOptions(array $in): ?array
    {
        $ref = array_key_exists('alarm_sound', $in) ? trim((string) $in['alarm_sound']) : Sounds::DEFAULT_ALARM;
        if ($ref === '' || $ref === 'none') {
            return null;
        }
        Sounds::libraryRef($ref);
        $loop = !array_key_exists('alarm_loop', $in) || filter_var($in['alarm_loop'], FILTER_VALIDATE_BOOLEAN);
        $rep = $in['alarm_repeat'] ?? 3;
        $vol = $in['alarm_volume'] ?? 80;
        if ($rep === '' || $rep === null) {
            $rep = 3;
        }
        if ($vol === '' || $vol === null) {
            $vol = 80;
        }
        if (!is_numeric($rep) || (int) $rep < 1 || (int) $rep > 10) {
            throw new InvalidArgumentException(__('Repeat must be between 1 and 10 times.'));
        }
        if (!is_numeric($vol) || (int) $vol < 0 || (int) $vol > 100) {
            throw new InvalidArgumentException(__('Volume must be between 0 and 100.'));
        }
        return ['sound' => $ref, 'loop' => $loop, 'repeat' => (int) $rep, 'volume' => (int) $vol];
    }

    /**
     * `content.emergency.alarm` of an emergency row: {url, loop, repeat, volume, name} or null (no alarm,
     * silenced, or a row from before 2.4.1 without the alarm columns). A deleted uploaded sound falls back to
     * the built-in beep: an emergency that had an alarm keeps sounding.
     */
    public static function alarmFor(array $b): ?array
    {
        $ref = (string) ($b['alarm_sound'] ?? '');
        if ($ref === '' || !empty($b['alarm_muted'])) {
            return null;
        }
        $s = null;
        try {
            $s = Sounds::resolve($ref);
        } catch (TenantException) {
            $s = null;
        }
        $s ??= Sounds::resolve(Sounds::DEFAULT_ALARM);
        if (!$s) {
            return null;
        }
        $loop = (int) ($b['alarm_loop'] ?? 1) === 1;
        return [
            'url' => (string) $s['url'],
            'loop' => $loop,
            'repeat' => max(1, min(10, (int) ($b['alarm_repeat'] ?? 3))), // used when loop is false
            'volume' => max(0, min(100, (int) ($b['alarm_volume'] ?? 80))),
            'name' => (string) $s['name'],
        ];
    }

    /**
     * Start an emergency broadcast that overrides all content on targeted TVs. $alarm: validated
     * alarmOptions() or null (no alarm sound).
     */
    public static function emergencyStart(string $title, string $message, string $targetType, array $ids, ?int $userId = null, string $bg = '#B00020', string $fg = '#FFFFFF', ?array $alarm = null): int
    {
        Access::requireTargetList($targetType, $ids);
        $title = trim($title) ?: __('Emergency');
        if ($alarm !== null) {
            Sounds::libraryRef((string) ($alarm['sound'] ?? '')); // never trust a caller-built array
        }
        $bid = DB::insert('broadcast_commands', [
            'title' => mb_substr($title, 0, 190),
            'command' => 'EMERGENCY',
            'target_type' => $targetType,
            'target_ids' => json_out($ids),
            'payload' => json_out(['title' => $title, 'message' => $message, 'bg_color' => clean_color($bg, '#B00020'), 'text_color' => clean_color($fg, '#FFFFFF')]),
            'is_emergency' => 1,
            'mode' => 'now',
            'status' => 'active',
            'start_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'alarm_sound' => $alarm ? (string) $alarm['sound'] : null,
            'alarm_loop' => $alarm && empty($alarm['loop']) ? 0 : 1,
            'alarm_repeat' => $alarm ? max(1, min(10, (int) ($alarm['repeat'] ?? 3))) : 3,
            'alarm_volume' => $alarm ? max(0, min(100, (int) ($alarm['volume'] ?? 80))) : 80,
            'alarm_muted' => 0,
        ]);
        Settings::bumpContentVersion();
        self::queueForRooms(self::targetRooms($targetType, $ids), 'SHOW_CONTENT', [], $bid);
        return $bid;
    }

    /**
     * "Silence alarm on all TVs" (2.4.1): the emergency message stays on screen but the alarm sound stops
     * (alarm_muted → content.emergency.alarm becomes null, so the content hash changes and TVs refresh).
     * One emergency ($id) or every active one; a user limited to some TVs only silences emergencies that
     * target nothing but their TVs (like emergencyStop). Returns the number of emergencies silenced.
     */
    public static function emergencySilence(?int $id = null): int
    {
        if ($id) {
            $row = Tenant::find('broadcast_commands', $id, "is_emergency = 1 AND status = 'active'");
            if ($row) {
                Access::requireBroadcast($row);
            }
            $rows = $row ? [$row] : [];
        } else {
            $rows = DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :hid AND is_emergency = 1 AND status = 'active'", ['hid' => Tenant::id()]);
            $rows = array_values(array_filter($rows, [Access::class, 'canBroadcast']));
        }
        $n = 0;
        foreach ($rows as $b) {
            if ((string) ($b['alarm_sound'] ?? '') === '' || !empty($b['alarm_muted'])) {
                continue;
            }
            DB::update('broadcast_commands', ['alarm_muted' => 1], 'id = :id', ['id' => $b['id']]);
            Settings::bumpContentVersion();
            self::queueForRooms(self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], (int) $b['id']);
            $n++;
        }
        return $n;
    }

    /** True when an active emergency (visible to this user) still sounds its alarm. */
    public static function alarmSounding(array $emergencies): bool
    {
        foreach ($emergencies as $b) {
            if ((string) ($b['alarm_sound'] ?? '') !== '' && empty($b['alarm_muted']) && Access::canBroadcast($b)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stop one (or all) emergency broadcasts. A user limited to some TVs may stop only emergencies
     * that target nothing but their TVs (403 for one id; "stop all" stops only those).
     */
    public static function emergencyStop(?int $id = null): int
    {
        if ($id) {
            $row = Tenant::find('broadcast_commands', $id, "is_emergency = 1 AND status = 'active'");
            if ($row) {
                Access::requireBroadcast($row);
            }
            $rows = $row ? [$row] : [];
        } else {
            $rows = DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :hid AND is_emergency = 1 AND status = 'active'", ['hid' => Tenant::id()]);
            $rows = array_values(array_filter($rows, [Access::class, 'canBroadcast']));
        }
        foreach ($rows as $b) {
            DB::update('broadcast_commands', ['status' => 'completed', 'end_at' => now()], 'id = :id', ['id' => $b['id']]);
            Settings::bumpContentVersion();
            self::queueForRooms(self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], (int) $b['id']);
        }
        return count($rows);
    }

    public static function activeEmergencies(): array
    {
        return DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :hid AND is_emergency = 1 AND status = 'active' ORDER BY id DESC", ['hid' => Tenant::id()]);
    }

    /** Send a device command (REBOOT, CLEAR_CACHE, UPDATE_APP...). Returns [broadcastId, deviceCount]. */
    public static function sendCommand(string $command, string $targetType, array $ids, array $payload = [], ?int $userId = null): array
    {
        if (!in_array($command, self::DEVICE_COMMANDS, true)) {
            throw new InvalidArgumentException('Unknown command');
        }
        Access::requireTargetList($targetType, $ids);
        $rooms = self::targetRooms($targetType, $ids);
        $bid = DB::insert('broadcast_commands', [
            'title' => $command,
            'command' => $command,
            'target_type' => $targetType,
            'target_ids' => json_out($ids),
            'payload' => json_out((object) $payload),
            'mode' => 'now',
            'status' => 'completed',
            'start_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
        ]);
        if ($command === 'SCREEN_OFF' || $command === 'SCREEN_ON') {
            // Persist on/off state so it survives TV reboot.
            $roomIds = array_map(fn ($r) => (int) $r['id'], $rooms);
            if ($roomIds) {
                [$in, $p] = DB::in($roomIds, 'r');
                DB::query("UPDATE rooms SET is_enabled = :e WHERE hotel_id = :hid AND id IN $in", $p + ['e' => $command === 'SCREEN_ON' ? 1 : 0, 'hid' => Tenant::id()]);
                Settings::bumpContentVersion();
            }
        }
        $count = self::queueForRooms($rooms, $command, $payload, $bid);
        return [$bid, $count];
    }

    /** Push an APK update to TVs (the customer's own release or a platform release offered to it, 2.7). */
    public static function pushApk(int $apkId, string $targetType, array $ids, ?int $userId = null): array
    {
        // Tenant::find denies another customer's release (404, logged); a platform release (hotel_id NULL) is
        // accepted when it is rolled out to this customer.
        $apk = Tenant::find('apk_releases', $apkId) ?? AppReleases::findForHotel($apkId, Tenant::id());
        if (!$apk) {
            throw new InvalidArgumentException('APK not found');
        }
        return self::sendCommand('UPDATE_APP', $targetType, $ids, [
            'url' => base_url('api/device/apk/' . $apk['id']),
            'version_code' => (int) $apk['version_code'],
            'version_name' => $apk['version_name'],
            'sha256' => $apk['sha256'],
        ], $userId);
    }

    public static function cancel(int $id): void
    {
        DB::update('broadcast_commands', ['status' => 'cancelled'], "id = :id AND status IN ('scheduled','active')", ['id' => $id]);
        Settings::bumpContentVersion();
    }

    /**
     * Process schedules of the current hotel (Scheduler::tick runs it for every hotel).
     * - 'once' broadcasts whose start time arrived are pushed.
     * - 'window' broadcasts flip scheduled→active→completed; TVs are told to refresh on each change.
     */
    public static function processSchedules(): array
    {
        $done = ['pushed' => 0, 'activated' => 0, 'ended' => 0];
        $hid = Tenant::id();
        // 2.5 plans: one-time scheduled pushes of a customer without "Schedules & calendar" wait (they run
        // after an upgrade); window schedules are ignored by ContentResolver while the feature is off.
        $due = !Features::enabled('schedule') ? [] : DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :hid AND mode = 'once' AND status = 'scheduled' AND start_at <= :n", ['n' => now(), 'hid' => $hid]);
        foreach ($due as $b) {
            // Claim atomically so concurrent ticks don't double-process.
            if (DB::update('broadcast_commands', ['status' => 'completed'], "id = :id AND status = 'scheduled'", ['id' => $b['id']]) !== 1) {
                continue;
            }
            $rooms = self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []);
            if ($rooms) {
                self::applyAssignment($rooms, $b['content_id'] ? (int) $b['content_id'] : null, $b['playlist_id'] ? (int) $b['playlist_id'] : null);
                self::queueForRooms($rooms, 'SHOW_CONTENT', [], (int) $b['id']);
            }
            $done['pushed']++;
        }

        $windows = DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = :hid AND mode = 'window' AND status IN ('scheduled','active')", ['hid' => $hid]);
        foreach ($windows as $b) {
            $ended = !empty($b['end_at']) && strtotime($b['end_at']) <= time();
            $active = !$ended && ContentResolver::windowActive($b);
            $newStatus = $ended ? 'completed' : ($active ? 'active' : 'scheduled');
            if ($newStatus !== $b['status']) {
                if (DB::update('broadcast_commands', ['status' => $newStatus], 'id = :id AND status = :s', ['id' => $b['id'], 's' => $b['status']]) === 1) {
                    Settings::bumpContentVersion();
                    self::queueForRooms(self::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], (int) $b['id']);
                    $newStatus === 'active' ? $done['activated']++ : $done['ended']++;
                }
            }
        }
        return $done;
    }
}
