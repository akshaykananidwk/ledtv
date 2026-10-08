<?php
declare(strict_types=1);

/**
 * Presence / motion sensors (#47, docs/modules/device_schedules.md): a PIR sensor (ESP8266 / ESP32,
 * Shelly, Home Assistant, a phone automation …) posts "motion" to POST /api/presence with its own token
 * (api/routes/presence.php, only the SHA-256 is stored). The rooms / groups of an active sensor are in
 * "presence mode":
 *   - motion          → SCREEN_ON to the target TVs (once; again only after an idle switch-off),
 *   - no motion for idle_minutes → SCREEN_OFF (DeviceSchedulesTask → Presence::idleTick()).
 * Presence never fights the other power rules: a room switched off in the admin panel stays off, a room
 * inside a power-off schedule (or anything else that makes ContentResolver return mode "off") is only
 * switched on when "presence overrides schedule" is ticked, and rooms with an active emergency are never
 * switched off (emergency always wins). The commands are not persisted on the room (unlike TV Power →
 * Turn OFF), so the next content change / power schedule still decides.
 */
final class Presence
{
    public const EVENTS = ['motion', 'clear', 'ping'];
    public const TARGET_TYPES = ['rooms', 'groups'];
    public const TOKEN_RE = '/^prs[0-9a-f]{48}$/';

    public static function find(int $id): ?array
    {
        return Tenant::find('presence_sensors', $id);
    }

    /** Sensors of the current hotel the current user may see (limited users: only their TVs). */
    public static function list(): array
    {
        $rows = DB::all('SELECT * FROM presence_sensors WHERE hotel_id = :h ORDER BY name, id', ['h' => Tenant::id()]);
        return array_values(array_filter($rows, [Access::class, 'canBroadcast']));
    }

    /** Validate form input. Returns [data, errors]. */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 120);
        if ($name === '') {
            $errors[] = __('Enter a name for the sensor.');
        }
        if (!in_array((string) ($in['target_type'] ?? ''), self::TARGET_TYPES, true)) {
            $in['target_type'] = 'rooms';
        }
        [$type, $ids] = Broadcaster::parseTarget($in);
        if (!$ids) {
            $errors[] = __('Choose the screens or groups of this sensor.');
        }
        $idle = $in['idle_minutes'] ?? '';
        if (!is_numeric($idle) || (int) $idle < 1 || (int) $idle > 1440 || (float) $idle != (int) $idle) {
            $errors[] = __('Switch off after: enter 1 to 1440 minutes.');
        }
        return [[
            'name' => $name,
            'target_type' => $type,
            'target_ids' => $ids,
            'idle_minutes' => max(1, min(1440, (int) $idle)),
            'override_schedule' => !empty($in['override_schedule']) ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data, ?int $userId = null): int
    {
        $row = ['target_ids' => json_out(array_values($data['target_ids']))] + $data;
        if ($id) {
            DB::update('presence_sensors', $row, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('presence_sensors', $row + ['created_by' => $userId, 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        DB::delete('presence_sensors', 'id = :id', ['id' => $id]);
    }

    /** New token for a sensor (returned once; only its hash is stored). */
    public static function newToken(int $id): string
    {
        $token = 'prs' . random_token(24);
        DB::update('presence_sensors', ['token_hash' => hash('sha256', $token), 'token_hint' => substr($token, -4)], 'id = :id', ['id' => $id]);
        return $token;
    }

    public static function revokeToken(int $id): void
    {
        DB::update('presence_sensors', ['token_hash' => null, 'token_hint' => ''], 'id = :id', ['id' => $id]);
    }

    /** Sensor of any hotel by its token (null when unknown / malformed). */
    public static function byToken(string $token): ?array
    {
        if (!preg_match(self::TOKEN_RE, $token)) {
            return null;
        }
        return DB::one('SELECT * FROM presence_sensors WHERE token_hash = :h LIMIT 1', ['h' => hash('sha256', $token)]);
    }

    // ------------------------------------------------------------------ events (current hotel = sensor's)

    /** Handle an event from the sensor. Returns the API answer. */
    public static function handle(array $sensor, string $event, ?int $now = null): array
    {
        $now ??= time();
        $at = date('Y-m-d H:i:s', $now);
        $set = ['last_event' => $event, 'events' => (int) $sensor['events'] + 1];
        if ($event === 'motion') {
            $set['last_seen'] = $at;
        }
        DB::update('presence_sensors', $set, 'id = :id', ['id' => $sensor['id']]);
        $sensor = array_merge($sensor, $set);
        $out = ['id' => (int) $sensor['id'], 'event' => $event, 'active' => (bool) (int) $sensor['is_active'], 'screens_on' => 0, 'skipped' => []];
        if ($event === 'motion' && (int) $sensor['is_active']) {
            $out = array_merge($out, self::screenOn($sensor, $now));
        }
        $out['state'] = (string) DB::value('SELECT state FROM presence_sensors WHERE id = :id AND hotel_id = :h', ['id' => $sensor['id'], 'h' => Tenant::id()]);
        return $out;
    }

    /**
     * Why a room may not be switched on by presence right now: 'emergency' (already shown, TV awake),
     * 'switched_off' (admin turned the room off), 'power_schedule' (power-off window / holiday / any rule that
     * makes the content "off") unless the sensor overrides schedules. Null = may be switched on.
     */
    public static function blockOn(array $room, array $sensor, array $emergency): ?string
    {
        if (isset($emergency[(int) $room['id']])) {
            return 'emergency';
        }
        if (!(int) $room['is_enabled']) {
            return 'switched_off';
        }
        if (!(int) $sensor['override_schedule'] && (ContentResolver::forRoom($room)['mode'] ?? '') === 'off') {
            return 'power_schedule';
        }
        return null;
    }

    /** Motion: switch on the TVs of the sensor's rooms that may be on (not again while already on). */
    private static function screenOn(array $sensor, int $now): array
    {
        $emergency = DeviceSchedules::emergencyRoomIds();
        $on = [];
        $skipped = [];
        foreach (self::rooms($sensor) as $room) {
            $why = self::blockOn($room, $sensor, $emergency);
            if ($why === null) {
                $on[] = $room;
            } else {
                $skipped[(string) $room['room_number']] = $why;
            }
        }
        if (!$on) {
            return ['screens_on' => 0, 'skipped' => $skipped];
        }
        // Claim the idle → on transition so a burst of motion events switches on only once.
        if ($sensor['state'] === 'on' || DB::update('presence_sensors', ['state' => 'on', 'on_sent_at' => date('Y-m-d H:i:s', $now)], "id = :id AND state <> 'on'", ['id' => $sensor['id']]) !== 1) {
            return ['screens_on' => 0, 'skipped' => $skipped, 'already_on' => true];
        }
        $bid = self::broadcastRow($sensor, 'SCREEN_ON', $now);
        $n = Broadcaster::queueForRooms($on, 'SCREEN_ON', [], $bid);
        return ['screens_on' => $n, 'skipped' => $skipped];
    }

    /** Rooms of a sensor (current hotel). */
    public static function rooms(array $sensor): array
    {
        return Broadcaster::targetRooms((string) $sensor['target_type'], json_decode((string) $sensor['target_ids'], true) ?: []);
    }

    private static function broadcastRow(array $sensor, string $command, int $now): int
    {
        return DB::insert('broadcast_commands', [
            'title' => mb_substr(__('Presence') . ': ' . $sensor['name'], 0, 190),
            'command' => $command,
            'target_type' => $sensor['target_type'],
            'target_ids' => (string) $sensor['target_ids'],
            'payload' => json_out(['source' => 'presence', 'sensor_id' => (int) $sensor['id']]),
            'mode' => 'now',
            'status' => 'completed',
            'start_at' => date('Y-m-d H:i:s', $now),
            'created_by' => $sensor['created_by'] ?: null,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * Switch off the TVs of sensors without motion for idle_minutes (current hotel). Rooms with an emergency,
     * rooms already "off" by a power rule and rooms another sensor still sees people in are left alone.
     * Returns the number of TVs switched off.
     */
    public static function idleTick(?int $now = null): int
    {
        $now ??= time();
        $hid = Tenant::id();
        $sensors = DB::all("SELECT * FROM presence_sensors WHERE hotel_id = :h AND is_active = 1", ['h' => $hid]);
        if (!$sensors) {
            return 0;
        }
        // Rooms where some active sensor saw motion within its idle time: keep them on.
        $busy = [];
        foreach ($sensors as $s) {
            if ($s['last_seen'] && (int) strtotime((string) $s['last_seen']) > $now - (int) $s['idle_minutes'] * 60) {
                foreach (self::rooms($s) as $r) {
                    $busy[(int) $r['id']] = true;
                }
            }
        }
        $emergency = null;
        $total = 0;
        foreach ($sensors as $s) {
            if ($s['state'] !== 'on') {
                continue;
            }
            $lastMotion = (int) strtotime((string) ($s['last_seen'] ?: $s['on_sent_at'] ?: $s['created_at']));
            if ($lastMotion > $now - (int) $s['idle_minutes'] * 60) {
                continue;
            }
            if (DB::update('presence_sensors', ['state' => 'off', 'off_sent_at' => date('Y-m-d H:i:s', $now)], "id = :id AND state = 'on'", ['id' => $s['id']]) !== 1) {
                continue;
            }
            $emergency ??= DeviceSchedules::emergencyRoomIds();
            $rooms = array_values(array_filter(self::rooms($s), static function (array $r) use ($busy, $emergency): bool {
                return !isset($busy[(int) $r['id']]) && !isset($emergency[(int) $r['id']])
                    && (int) $r['is_enabled'] && (ContentResolver::forRoom($r)['mode'] ?? '') !== 'off';
            }));
            if ($rooms) {
                $total += Broadcaster::queueForRooms($rooms, 'SCREEN_OFF', [], self::broadcastRow($s, 'SCREEN_OFF', $now));
            }
        }
        return $total;
    }

    /** Human label of a skip reason / state. */
    public static function reasonLabel(string $why): string
    {
        return match ($why) {
            'emergency' => __('emergency active'),
            'switched_off' => __('switched off in TV Power'),
            'power_schedule' => __('inside a power-off schedule'),
            default => $why,
        };
    }
}
