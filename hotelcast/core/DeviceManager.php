<?php
declare(strict_types=1);

/** TV device registration, token auth, heartbeat & online/offline tracking. */
final class DeviceManager
{
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function pinHash(?array $room = null): string
    {
        $pin = $room && !empty($room['settings_pin']) ? (string) $room['settings_pin'] : (string) Settings::get('tv_settings_pin', '1234');
        return hash('sha256', $pin);
    }

    public static function validUid(string $uid): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid);
    }

    /** Register (or re-register) a TV. Returns response data array. */
    public static function register(array $in): array
    {
        $uid = trim((string) ($in['device_id'] ?? ''));
        $roomNumber = trim((string) ($in['room_number'] ?? ''));
        $key = (string) ($in['registration_key'] ?? '');

        if (!self::validUid($uid) || $roomNumber === '' || mb_strlen($roomNumber) > 20) {
            Api::error('VALIDATION_ERROR', 'device_id (8-64 chars) and room_number are required', 400);
        }
        // The registration key identifies the hotel (unique per hotel).
        $hotel = $key !== '' && strlen($key) <= 64 ? DB::one('SELECT id, registration_key FROM hotels WHERE registration_key = :k', ['k' => $key]) : null;
        if (!$hotel || !hash_equals((string) $hotel['registration_key'], $key)) {
            // 2.5 platform registration key → unassigned pool (core/DevicePool.php, docs/modules/platform_screens.md).
            if (class_exists('DevicePool') && DevicePool::isPoolKey($key)) {
                return DevicePool::register($in);
            }
            Logger::write('device', 'warning', 'Registration rejected (bad key)', ['uid' => $uid, 'room' => $roomNumber, 'ip' => client_ip()]);
            Api::error('INVALID_REGISTRATION_KEY', 'Registration key is wrong. Check Admin → Settings → Devices.', 401);
        }
        Tenant::set((int) $hotel['id']);
        if (!Tenant::isActive()) {
            Logger::write('device', 'warning', 'Registration rejected (hotel suspended)', ['uid' => $uid, 'hotel' => $hotel['id']]);
            Api::error('HOTEL_SUSPENDED', Tenant::suspendedMessage()['title'] . ': ' . Tenant::suspendedMessage()['message'], 403);
        }
        $hid = Tenant::id();
        // 2.5 plans: browsers (web player) register only when the customer's plan includes it.
        $wpType = $in['platform'] ?? $in['device_type'] ?? '';
        if (is_string($wpType) && strtolower(trim($wpType)) === 'web' && !Features::enabled('web_player')) {
            Api::error('FEATURE_DISABLED', 'The web player is not included in this plan. Contact your provider.', 403);
        }
        $existing = DB::one('SELECT id, is_revoked, room_id FROM devices WHERE hotel_id = :h AND device_uid = :u', ['h' => $hid, 'u' => $uid]);

        // Plan / license limit: a new TV (or a revoked / unassigned one coming back) needs a free slot.
        $max = Tenant::maxTvs();
        $countsAlready = $existing && !(int) $existing['is_revoked'] && $existing['room_id'] !== null;
        if ($max !== null && !$countsAlready && Tenant::tvCount() >= $max) {
            Logger::write('device', 'warning', 'Registration rejected (TV limit)', ['uid' => $uid, 'hotel' => $hid, 'max' => $max]);
            Api::error('LICENSE_LIMIT', 'TV limit reached (' . $max . ' TVs). Remove an old TV in the admin panel or upgrade your plan.', 403);
        }

        $room = DB::one('SELECT * FROM rooms WHERE hotel_id = :h AND room_number = :n', ['h' => $hid, 'n' => $roomNumber]);
        if (!$room) {
            if (!Settings::bool('auto_create_rooms')) {
                Api::error('ROOM_NOT_FOUND', 'Room ' . $roomNumber . ' does not exist. Add it in the admin panel first.', 404);
            }
            $floor = preg_match('/^(\d+)\d{2}$/', $roomNumber, $m) ? $m[1] : null;
            $rid = DB::insert('rooms', ['room_number' => $roomNumber, 'name' => 'Room ' . $roomNumber, 'floor' => $floor, 'created_at' => now()]);
            $room = DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $rid, 'h' => $hid]);
            ActivityLog::add('room_auto_created', 'room', $rid, 'Created by TV registration ' . $uid);
        }

        $token = random_token(32);
        $fields = [
            'room_id' => $room['id'],
            'token_hash' => self::hashToken($token),
            'app_version' => self::str($in['app_version'] ?? null, 20),
            'app_version_code' => isset($in['app_version_code']) ? (int) $in['app_version_code'] : null,
            'android_version' => self::str($in['android_version'] ?? null, 20),
            'model' => self::str($in['model'] ?? null, 100),
            'ip_address' => self::ip($in['ip_address'] ?? null),
            'public_ip' => client_ip(),
            'status' => 'online',
            'is_revoked' => 0,
            'last_ping' => now(),
        ];
        $fields += self::platformFields($in); // 2.4: android | web (web player), migration 026
        if ($existing) {
            DB::update('devices', $fields + ['registered_at' => now()], 'id = :id', ['id' => $existing['id']]);
            $deviceId = (int) $existing['id'];
        } else {
            $deviceId = DB::insert('devices', $fields + ['device_uid' => $uid, 'registered_at' => now()]);
        }
        // One active TV per room: other devices in the same room keep working (e.g. 2 TVs in a suite).
        DB::insert('device_status_logs', ['device_id' => $deviceId, 'room_id' => $room['id'], 'status' => 'online', 'created_at' => now()]);
        Logger::write('device', 'info', 'Device registered', ['uid' => $uid, 'room' => $roomNumber, 'hotel' => $hid]);

        return [
            'token' => $token,
            'device_id' => $uid,
            'hotel' => ['id' => $hid, 'name' => (string) Settings::get('hotel_name', '')],
            'room' => ['id' => (int) $room['id'], 'number' => $room['room_number'], 'name' => (string) $room['name'], 'floor' => (string) $room['floor']],
            'poll_interval' => self::pollInterval(),
            'heartbeat_interval' => max(15, Settings::int('heartbeat_interval', 60)),
            'settings_pin_hash' => self::pinHash($room),
        ];
    }

    public static function pollInterval(): int
    {
        return max(3, min(60, Settings::int('poll_interval', 8)));
    }

    /** Authenticate the calling device from headers; aborts with 401 on failure. */
    public static function authenticate(): array
    {
        $token = Api::bearer();
        $uid = (string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
        if (!$token) {
            Api::error('UNAUTHENTICATED', 'Missing device token', 401);
        }
        $device = DB::one('SELECT * FROM devices WHERE token_hash = :h LIMIT 1', ['h' => self::hashToken($token)]);
        if (!$device && class_exists('DevicePool')) {
            DevicePool::handleRequest($token, $uid); // 2.5: a TV of the unassigned pool is answered there (exits)
        }
        if (!$device || (int) $device['is_revoked'] || ($uid !== '' && !hash_equals($device['device_uid'], $uid))) {
            Api::error('INVALID_TOKEN', 'Device token invalid or revoked. Please register again.', 401);
        }
        // From here on every query runs in the device's hotel.
        Tenant::forDevice($device);

        $retry = RateLimiter::hit('dev:' . $device['id'], 120, 60);
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
        }

        // Mark online / update last ping (cheap: at most once every 3 s per device).
        if ($device['status'] !== 'online' || !$device['last_ping'] || strtotime($device['last_ping']) < time() - 3) {
            DB::update('devices', ['last_ping' => now(), 'status' => 'online', 'public_ip' => client_ip()], 'id = :id', ['id' => $device['id']]);
            if ($device['status'] !== 'online') {
                DB::insert('device_status_logs', ['device_id' => $device['id'], 'room_id' => $device['room_id'], 'status' => 'online', 'created_at' => now()]);
                Notifier::deviceBackOnline($device);
            }
        }
        return $device;
    }

    public static function room(array $device): array
    {
        $room = $device['room_id'] ? DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $device['room_id'], 'h' => (int) $device['hotel_id']]) : null;
        if (!$room) {
            Api::error('ROOM_NOT_FOUND', 'This TV is not assigned to a room. Register it again.', 404);
        }
        return $room;
    }

    /** Pending commands for a device; marks them delivered. */
    public static function pendingCommands(int $deviceId): array
    {
        $rows = DB::all(
            "SELECT id, command, payload FROM device_commands
             WHERE device_id = :d AND status IN ('pending','delivered') AND created_at > :since
             ORDER BY id LIMIT 20",
            ['d' => $deviceId, 'since' => date('Y-m-d H:i:s', time() - 86400)]
        );
        $out = [];
        $newIds = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'command' => $r['command'], 'payload' => json_decode((string) $r['payload'], true) ?: (object) []];
            $newIds[] = (int) $r['id'];
        }
        if ($newIds) {
            [$in, $p] = DB::in($newIds, 'c');
            DB::query("UPDATE device_commands SET status = 'delivered', delivered_at = COALESCE(delivered_at, :n) WHERE status = 'pending' AND id IN $in", $p + ['n' => now()]);
        }
        return $out;
    }

    public static function ack(array $device, array $in): array
    {
        $cid = (int) ($in['command_id'] ?? 0);
        $status = in_array($in['status'] ?? 'acked', ['delivered', 'acked', 'failed'], true) ? $in['status'] : 'acked';
        $cmd = DB::one('SELECT * FROM device_commands WHERE id = :id AND device_id = :d', ['id' => $cid, 'd' => $device['id']]);
        if (!$cmd) {
            Api::error('NOT_FOUND', 'Command not found', 404);
        }
        DB::update('device_commands', [
            'status' => $status,
            'message' => self::str($in['message'] ?? null, 255),
            'acked_at' => $status === 'delivered' ? null : now(),
        ], 'id = :id', ['id' => $cid]);
        if ($cmd['broadcast_id']) {
            DB::insert('broadcast_logs', [
                'broadcast_id' => $cmd['broadcast_id'], 'device_id' => $device['id'], 'room_id' => $device['room_id'],
                'event' => $status, 'message' => self::str($cmd['command'] . ($in['message'] ?? '' ? ': ' . $in['message'] : ''), 500),
                'created_at' => now(),
            ]);
        }
        return ['command_id' => $cid, 'status' => $status];
    }

    public static function heartbeat(array $device, array $in): array
    {
        DB::update('devices', [
            'app_version' => self::str($in['app_version'] ?? $device['app_version'], 20),
            'app_version_code' => isset($in['app_version_code']) ? (int) $in['app_version_code'] : $device['app_version_code'],
            'android_version' => self::str($in['android_version'] ?? $device['android_version'], 20),
            'model' => self::str($in['model'] ?? $device['model'], 100),
            'ip_address' => self::ip($in['ip_address'] ?? null) ?? $device['ip_address'],
            'battery' => isset($in['battery']) ? max(-1, min(100, (int) $in['battery'])) : null,
            'network_type' => self::str($in['network_type'] ?? null, 20),
            'wifi_signal' => isset($in['wifi_signal']) ? (int) $in['wifi_signal'] : null,
            'free_storage_mb' => isset($in['free_storage_mb']) ? (int) $in['free_storage_mb'] : null,
            'current_hash' => isset($in['current_content_hash']) && preg_match('/^[a-f0-9]{40}$/', (string) $in['current_content_hash']) ? $in['current_content_hash'] : $device['current_hash'],
            'current_item_id' => isset($in['current_item_id']) ? (int) $in['current_item_id'] : null,
            'screen_on' => isset($in['screen_on']) ? (int) (bool) $in['screen_on'] : 1,
            'uptime_sec' => isset($in['uptime_sec']) ? (int) $in['uptime_sec'] : null,
            'last_heartbeat' => now(),
            'last_ping' => now(),
            'status' => 'online',
        ], 'id = :id', ['id' => $device['id']]);
        DeviceHealth::record($device, $in['health'] ?? null); // 2.4 TV health (#44)
        $room = $device['room_id'] ? DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $device['room_id'], 'h' => (int) $device['hotel_id']]) : null;
        return [
            'server_time' => date('c'),
            'poll_interval' => self::pollInterval(),
            'heartbeat_interval' => max(15, Settings::int('heartbeat_interval', 60)),
            'settings_pin_hash' => self::pinHash($room),
        ];
    }

    public static function played(array $device, array $in): array
    {
        $count = 0;
        foreach (array_slice((array) ($in['items'] ?? []), 0, 200) as $p) {
            if (!is_array($p) || empty($p['content_id'])) {
                continue;
            }
            $ts = isset($p['started_at']) ? strtotime((string) $p['started_at']) : time();
            $row = [
                'device_id' => $device['id'],
                'room_id' => $device['room_id'],
                'content_id' => (int) $p['content_id'],
                'event' => 'played',
                'duration_sec' => isset($p['duration_sec']) ? max(0, (int) $p['duration_sec']) : null,
                'created_at' => date('Y-m-d H:i:s', $ts ?: time()),
            ];
            // Sponsor ad impression (ads module, migration 004): only ids of this hotel's campaigns are kept.
            if (isset($p['ad_campaign_id']) && ($adId = Ads::impressionCampaign($p['ad_campaign_id'])) !== null) {
                $row['ad_campaign_id'] = $adId;
            }
            DB::insert('broadcast_logs', $row);
            $count++;
        }
        return ['saved' => $count];
    }

    /** Mark the current hotel's devices offline when they stopped polling. Returns newly offline devices. */
    public static function detectOffline(): array
    {
        $limit = max(30, Settings::int('offline_after', 90));
        $stale = DB::all(
            "SELECT d.*, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id
             WHERE d.hotel_id = :h AND d.status = 'online' AND (d.last_ping IS NULL OR d.last_ping < :t)",
            ['t' => date('Y-m-d H:i:s', time() - $limit), 'h' => Tenant::id()]
        );
        $changed = [];
        foreach ($stale as $d) {
            if (DB::update('devices', ['status' => 'offline'], "id = :id AND status = 'online'", ['id' => $d['id']]) === 1) {
                DB::insert('device_status_logs', ['device_id' => $d['id'], 'room_id' => $d['room_id'], 'status' => 'offline', 'created_at' => now()]);
                $changed[] = $d;
            }
        }
        return $changed;
    }

    public static function isOnline(?array $device): bool
    {
        return $device && $device['status'] === 'online' && $device['last_ping']
            && strtotime($device['last_ping']) >= time() - max(30, Settings::int('offline_after', 90));
    }

    /**
     * 2.4 web player: `platform` ('android' | 'web', from `platform` or `device_type` in the register body)
     * and the browser's `user_agent`. Empty when migration 026 has not run yet.
     */
    public static function platformFields(array $in): array
    {
        if (!Migrator::hasColumn(DB::pdo(), 'devices', 'platform')) {
            return [];
        }
        $p = strtolower(trim((string) ($in['platform'] ?? $in['device_type'] ?? '')));
        $web = $p === 'web';
        return ['platform' => $web ? 'web' : 'android', 'user_agent' => $web ? self::str($in['user_agent'] ?? null, 255) : null];
    }

    /** 2.4: a web player (browser) — it cannot install APKs, so it is left out of app updates. */
    public static function isWeb(?array $device): bool
    {
        return ($device['platform'] ?? 'android') === 'web';
    }

    /** SQL condition "not a web player" for $col (devices.platform), '' before migration 026. */
    public static function notWebSql(string $col = 'platform'): string
    {
        return Migrator::hasColumn(DB::pdo(), 'devices', 'platform') ? " AND $col <> 'web'" : '';
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return mb_substr(strip_tags((string) $v), 0, $max);
    }

    private static function ip(mixed $v): ?string
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP) ? $v : null;
    }
}
