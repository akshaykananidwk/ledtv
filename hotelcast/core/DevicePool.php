<?php
declare(strict_types=1);

/**
 * Unassigned device pool (docs/modules/platform_screens.md § Unassigned pool, migration 030).
 *
 *  1. The platform admin switches the pool on (Platform → All screens → Unassigned pool) and gets a
 *     PLATFORM registration key (system_settings hotel 0: platform_pool_key / platform_pool_enabled).
 *  2. A TV registered with that key (POST /api/device/register, same body as always; room_number is
 *     kept as the TV's label / future screen ID) gets a normal token and a row in `device_pool` —
 *     not in any customer. Its polls answer a "waiting for setup" screen (mode 'suspended', which
 *     every app version already shows) with its device ID; heartbeats are accepted.
 *  3. The admin assigns it to a customer and screen: a `devices` row is created in that customer
 *     (or the customer's old revoked record of the same TV is reused) with the SAME token hash, the
 *     pool row is deleted, and the next poll serves the customer's content — no re-registration.
 *  4. "Move to unassigned pool" takes a TV out of a customer: the customer keeps a revoked record
 *     (history), the live token moves to the pool.
 *
 * device_pool is NOT a tenant table; only platform admins (platform.pool) use these methods.
 */
final class DevicePool
{
    public const POLL_INTERVAL = 15;

    /** Unique positive ints. */
    private static function ids(array $v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $v), static fn ($x) => $x > 0)));
    }

    public static function available(): bool
    {
        return Migrator::hasTable(DB::pdo(), 'device_pool');
    }

    public static function enabled(): bool
    {
        return (string) Settings::platform('platform_pool_enabled', '0') === '1' && self::key() !== '' && self::available();
    }

    public static function key(): string
    {
        return (string) Settings::platform('platform_pool_key', '');
    }

    /** Turn the pool on / off; a key is created the first time. Returns the key. */
    public static function setEnabled(bool $on): string
    {
        if ($on && self::key() === '') {
            self::regenerateKey();
        }
        Settings::setPlatform('platform_pool_enabled', $on ? '1' : '0');
        Settings::flush();
        return self::key();
    }

    /** New platform registration key (TVs already in the pool keep working). */
    public static function regenerateKey(): string
    {
        do {
            $key = 'P' . strtoupper(random_token(8));
        } while (DB::value('SELECT id FROM hotels WHERE registration_key = :k', ['k' => $key]));
        Settings::setPlatform('platform_pool_key', $key);
        Settings::flush();
        return $key;
    }

    public static function isPoolKey(string $key): bool
    {
        $mine = self::key();
        return $key !== '' && $mine !== '' && hash_equals($mine, $key) && self::enabled();
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '' || !is_scalar($v)) {
            return null;
        }
        return mb_substr(strip_tags((string) $v), 0, $max);
    }

    private static function ip(mixed $v): ?string
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP) ? $v : null;
    }

    /** Registration with the platform key (called by DeviceManager::register). Returns the API answer. */
    public static function register(array $in): array
    {
        $uid = trim((string) ($in['device_id'] ?? ''));
        $label = mb_substr(trim((string) ($in['room_number'] ?? '')), 0, 20);
        $token = random_token(32);
        $web = strtolower(trim((string) ($in['platform'] ?? $in['device_type'] ?? ''))) === 'web';
        $fields = [
            'token_hash' => DeviceManager::hashToken($token),
            'label' => $label !== '' ? $label : null,
            'platform' => $web ? 'web' : 'android',
            'model' => self::str($in['model'] ?? null, 100),
            'app_version' => self::str($in['app_version'] ?? null, 20),
            'app_version_code' => isset($in['app_version_code']) && is_numeric($in['app_version_code']) ? max(0, (int) $in['app_version_code']) : null,
            'android_version' => self::str($in['android_version'] ?? null, 20),
            'ip_address' => self::ip($in['ip_address'] ?? null),
            'public_ip' => client_ip(),
            'user_agent' => $web ? self::str($in['user_agent'] ?? null, 255) : null,
            'last_ping' => now(),
        ];
        $id = (int) DB::value('SELECT id FROM device_pool WHERE device_uid = :u', ['u' => $uid]);
        if ($id) {
            $set = implode(', ', array_map(static fn ($k) => "`$k` = :$k", array_keys($fields)));
            DB::query("UPDATE device_pool SET $set, source = 'platform_key', registered_at = :ra WHERE id = :id", $fields + ['ra' => now(), 'id' => $id]);
        } else {
            $cols = array_keys($fields);
            DB::query(
                'INSERT INTO device_pool (device_uid, ' . implode(', ', $cols) . ', source, registered_at) VALUES (:device_uid, :' . implode(', :', $cols) . ", 'platform_key', :ra)",
                $fields + ['device_uid' => $uid, 'ra' => now()]
            );
            $id = (int) DB::pdo()->lastInsertId();
        }
        Logger::write('device', 'info', 'Device registered in the unassigned pool', ['uid' => $uid, 'label' => $label, 'pool' => $id]);
        return [
            'token' => $token,
            'device_id' => $uid,
            'hotel' => ['id' => 0, 'name' => ''],
            'room' => ['id' => 0, 'number' => $label, 'name' => '', 'floor' => ''],
            'poll_interval' => self::POLL_INTERVAL,
            'heartbeat_interval' => 60,
            'settings_pin_hash' => DeviceManager::pinHash(null),
            'unassigned' => true,
        ];
    }

    public static function findByToken(string $token): ?array
    {
        if ($token === '' || !self::available()) {
            return null;
        }
        return DB::one('SELECT * FROM device_pool WHERE token_hash = :h LIMIT 1', ['h' => DeviceManager::hashToken($token)]);
    }

    /** The "waiting for setup" content object (shape of ContentResolver's, mode 'suspended'). */
    public static function waitingContent(array $row): array
    {
        $lang = (string) Settings::get('default_language', 'en');
        $title = I18n::translate('Waiting for setup', $lang);
        $msg = I18n::translate('This screen is not assigned to a customer yet. Device ID: :id', $lang, ['id' => $row['device_uid']])
            . ($row['label'] ? ' · ' . $row['label'] : '');
        $content = [
            'mode' => 'suspended',
            'screen_on' => true,
            'room' => ['id' => 0, 'number' => (string) ($row['label'] ?? ''), 'name' => '', 'floor' => ''],
            'hotel' => ['id' => 0, 'name' => '', 'logo_url' => null],
            'playlist' => null,
            'items' => [],
            'overlay' => ['clock' => true, 'clock_format' => 'hh:mm a', 'weather' => ['enabled' => false], 'ticker' => null, 'logo' => false],
            'emergency' => null,
            'power_off_mode' => 'standby',
            'suspended' => ['title' => $title, 'message' => $msg],
            'unassigned' => true,
        ];
        try {
            $content['branding'] = Branding::forTv();
        } catch (Throwable $e) {
            $content['branding'] = null;
        }
        $content['hash'] = sha1(json_out($content));
        $content['generated_at'] = date('c');
        return $content;
    }

    /**
     * Device API request whose token is not a customer TV's (DeviceManager::authenticate): when the
     * token belongs to a pool TV, answer it here and stop; otherwise return (→ INVALID_TOKEN).
     */
    public static function handleRequest(string $token, string $uid): void
    {
        $row = self::findByToken($token);
        if (!$row || ($uid !== '' && !hash_equals((string) $row['device_uid'], $uid))) {
            return;
        }
        $retry = RateLimiter::hit('pool:' . $row['id'], 120, 60);
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
        }
        if (!$row['last_ping'] || strtotime((string) $row['last_ping']) < time() - 3) {
            DB::query('UPDATE device_pool SET last_ping = :n, public_ip = :ip WHERE id = :id', ['n' => now(), 'ip' => client_ip(), 'id' => $row['id']]);
        }
        $route = (string) ($GLOBALS['route'] ?? '');
        if ($route === 'device/command' || str_starts_with($route, 'device/command/')) {
            $content = self::waitingContent($row);
            $changed = $content['hash'] !== (string) ($_GET['hash'] ?? '');
            $data = [
                'server_time' => date('c'),
                'server_time_ms' => (int) floor(microtime(true) * 1000),
                'poll_interval' => self::POLL_INTERVAL,
                'content_hash' => $content['hash'],
                'content_changed' => $changed,
                'commands' => [],
            ];
            if ($changed) {
                $data['content'] = $content;
            }
            Api::ok($data);
        }
        if ($route === 'device/heartbeat') {
            $in = request_json();
            DB::query(
                'UPDATE device_pool SET app_version = COALESCE(:v, app_version), app_version_code = COALESCE(:c, app_version_code),
                    model = COALESCE(:m, model), ip_address = COALESCE(:ip, ip_address) WHERE id = :id',
                ['v' => self::str($in['app_version'] ?? null, 20), 'c' => isset($in['app_version_code']) && is_numeric($in['app_version_code']) ? (int) $in['app_version_code'] : null,
                    'm' => self::str($in['model'] ?? null, 100), 'ip' => self::ip($in['ip_address'] ?? null), 'id' => $row['id']]
            );
            Api::ok([
                'server_time' => date('c'),
                'poll_interval' => self::POLL_INTERVAL,
                'heartbeat_interval' => 60,
                'settings_pin_hash' => DeviceManager::pinHash(null),
            ]);
        }
        if ($route === 'device/played' || $route === 'device/ack') {
            Api::ok(['saved' => 0]);
        }
        Api::error('NOT_ASSIGNED', 'This TV is waiting to be assigned to a customer.', 409);
    }

    // ------------------------------------------------------------------ admin

    public static function all(string $q = ''): array
    {
        if (!self::available()) {
            return [];
        }
        $p = [];
        $w = '';
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w = ' WHERE p.device_uid LIKE :q1 OR p.label LIKE :q2 OR p.model LIKE :q3 OR p.notes LIKE :q4 OR p.ip_address LIKE :q5';
            $p = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }
        return DB::all('SELECT p.*, h.name AS from_hotel FROM device_pool p LEFT JOIN hotels h ON h.id = p.from_hotel_id' . $w . ' ORDER BY p.registered_at DESC, p.id DESC LIMIT 500', $p);
    }

    public static function find(int $id): ?array
    {
        return self::available() ? DB::one('SELECT * FROM device_pool WHERE id = :id', ['id' => $id]) : null;
    }

    public static function isOnline(array $row): bool
    {
        return !empty($row['last_ping']) && strtotime((string) $row['last_ping']) >= time() - max(90, 3 * self::POLL_INTERVAL);
    }

    public static function setNotes(int $id, string $notes): void
    {
        DB::query('UPDATE device_pool SET notes = :n WHERE id = :id', ['n' => mb_substr(trim(strip_tags($notes)), 0, 255) ?: null, 'id' => $id]);
    }

    /** Remove pool entries (the TVs return to their setup screen). Returns count. */
    public static function delete(array $ids): int
    {
        $ids = self::ids($ids);
        if (!$ids) {
            return 0;
        }
        [$in, $p] = DB::in($ids, 'pd');
        $uids = DB::column("SELECT device_uid FROM device_pool WHERE id IN $in", $p);
        $n = DB::query("DELETE FROM device_pool WHERE id IN $in", $p)->rowCount();
        if ($n) {
            ActivityLog::add('pool_delete', 'device', null, 'Removed from the unassigned pool: ' . implode(', ', array_slice($uids, 0, 20)), null);
        }
        return $n;
    }

    /**
     * Assign pool TVs to a customer and screen (atomic, screen limit enforced). $opt like
     * PlatformScreens::move (mode existing|new|same; 'same' uses the TV's label as screen ID).
     * The customer's devices row gets the pool row's token hash: the TV keeps its token.
     */
    public static function assign(array $poolIds, int $hotelId, array $opt): int
    {
        $poolIds = self::ids($poolIds);
        if (!$poolIds) {
            throw new InvalidArgumentException(__('Select at least one screen.'));
        }
        if (!DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $hotelId])) {
            throw new InvalidArgumentException(__('Customer not found.'));
        }
        [$in, $p] = DB::in($poolIds, 'pa');
        $rows = DB::all("SELECT * FROM device_pool WHERE id IN $in ORDER BY id", $p);
        if (count($rows) !== count($poolIds)) {
            throw new InvalidArgumentException(__('Screen not found.'));
        }
        $mode = (string) ($opt['mode'] ?? 'existing');
        $n = DB::transaction(static function () use ($rows, $hotelId, $mode, $opt): int {
            DB::one('SELECT id FROM hotels WHERE id = :id FOR UPDATE', ['id' => $hotelId]);
            PlatformScreens::assertCapacity($hotelId, count($rows));
            $many = count($rows) > 1;
            $hasPlatform = Migrator::hasColumn(DB::pdo(), 'devices', 'platform');
            foreach ($rows as $i => $r) {
                $room = PlatformScreens::targetRoom($hotelId, $mode, (int) ($opt['room_id'] ?? 0),
                    $mode === 'new' && $many ? trim((string) ($opt['name'] ?? '')) . ' ' . ($i + 1) : (string) ($opt['name'] ?? ''),
                    (string) ($r['label'] ?? ''), ['name' => $r['label'] ?? '']);
                $fields = [
                    'room_id' => (int) $room['id'], 'token_hash' => $r['token_hash'], 'app_version' => $r['app_version'],
                    'app_version_code' => $r['app_version_code'], 'android_version' => $r['android_version'], 'model' => $r['model'],
                    'ip_address' => $r['ip_address'], 'public_ip' => $r['public_ip'], 'is_revoked' => 0,
                    'status' => self::isOnline($r) ? 'online' : 'offline', 'last_ping' => $r['last_ping'], 'offline_notified' => 0,
                    'current_hash' => null, 'health_alerts' => null,
                ];
                if ($hasPlatform) {
                    $fields += ['platform' => $r['platform'], 'user_agent' => $r['user_agent']];
                }
                $old = DB::one('SELECT id, is_revoked, room_id FROM devices WHERE hotel_id = :h AND device_uid = :u', ['h' => $hotelId, 'u' => $r['device_uid']]);
                if ($old && !(int) $old['is_revoked'] && $old['room_id']) {
                    throw new RuntimeException(__(':c already has an active TV with the device ID :u.', ['c' => (string) DB::value('SELECT name FROM hotels WHERE id = :id', ['id' => $hotelId]), 'u' => $r['device_uid']]));
                }
                $deviceId = (int) Tenant::run($hotelId, static function () use ($old, $fields, $r): int {
                    if ($old) {
                        DB::update('devices', $fields, 'id = :id', ['id' => (int) $old['id']]);
                        return (int) $old['id'];
                    }
                    return DB::insert('devices', $fields + ['device_uid' => $r['device_uid'], 'registered_at' => now()]);
                });
                DB::query('DELETE FROM device_pool WHERE id = :id', ['id' => (int) $r['id']]);
                DB::query('INSERT INTO device_status_logs (hotel_id, device_id, room_id, status, created_at) VALUES (:h, :d, :r, :s, :c)',
                    ['h' => $hotelId, 'd' => $deviceId, 'r' => (int) $room['id'], 's' => $fields['status'], 'c' => now()]);
                ActivityLog::add('device_moved_in', 'device', $deviceId, 'TV ' . $r['device_uid'] . ' assigned from the unassigned pool to screen ' . $room['room_number'] . ' by the platform', $hotelId);
                ActivityLog::add('pool_assign', 'device', $deviceId, 'TV ' . $r['device_uid'] . ' from the unassigned pool → customer #' . $hotelId . ', screen ' . $room['room_number'], null);
            }
            return count($rows);
        });
        Tenant::forget($hotelId);
        Tenant::run($hotelId, static fn () => Settings::bumpContentVersion());
        PlatformScreens::forget();
        return $n;
    }

    /**
     * Take TVs out of their customers into the pool (platform admins). The customer keeps a revoked
     * record of the TV (its history); the TV's live token moves to the pool row, so the TV shows the
     * "waiting for setup" screen on its next poll. Returns count.
     */
    public static function unassign(array $deviceIds): int
    {
        if (!self::available()) {
            throw new RuntimeException('Run the database update (migration 030) first.');
        }
        $devs = array_values(array_filter(PlatformScreens::devices($deviceIds), static fn ($d) => !(int) $d['is_revoked']));
        DB::transaction(static function () use ($devs): void {
            foreach ($devs as $d) {
                $label = $d['room_id'] ? (string) DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => (int) $d['room_id'], 'h' => (int) $d['hotel_id']]) : '';
                DB::query('DELETE FROM device_pool WHERE device_uid = :u', ['u' => $d['device_uid']]);
                DB::query(
                    "INSERT INTO device_pool (device_uid, token_hash, label, platform, model, app_version, app_version_code, android_version, ip_address, public_ip, user_agent, source, from_hotel_id, last_ping, registered_at)
                     VALUES (:u, :t, :l, :pf, :m, :v, :vc, :av, :ip, :pip, :ua, 'removed', :fh, :lp, :ra)",
                    ['u' => $d['device_uid'], 't' => $d['token_hash'], 'l' => $label !== '' ? mb_substr($label, 0, 20) : null,
                        'pf' => ($d['platform'] ?? 'android') === 'web' ? 'web' : 'android', 'm' => $d['model'], 'v' => $d['app_version'],
                        'vc' => $d['app_version_code'], 'av' => $d['android_version'], 'ip' => $d['ip_address'], 'pip' => $d['public_ip'],
                        'ua' => $d['user_agent'] ?? null, 'fh' => (int) $d['hotel_id'], 'lp' => $d['last_ping'], 'ra' => now()]
                );
                DB::query(
                    "UPDATE devices SET is_revoked = 1, status = 'offline', token_hash = :t, health_alerts = NULL WHERE id = :id AND hotel_id = :h",
                    ['t' => hash('sha256', random_token(32)), 'id' => (int) $d['id'], 'h' => (int) $d['hotel_id']]
                );
                DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND status IN ('pending','delivered')", ['d' => (int) $d['id']]);
                if (Migrator::hasTable(DB::pdo(), 'device_live_views')) {
                    DB::query('DELETE FROM device_live_views WHERE device_id = :d', ['d' => (int) $d['id']]);
                    @unlink(LiveView::framePath((int) $d['hotel_id'], (int) $d['id']));
                }
                ActivityLog::add('device_moved_out', 'device', (int) $d['id'], 'TV ' . $d['device_uid'] . ' moved to the unassigned pool by the platform', (int) $d['hotel_id']);
                ActivityLog::add('pool_unassign', 'device', (int) $d['id'], 'TV ' . $d['device_uid'] . ' of customer #' . $d['hotel_id'] . ' → unassigned pool', null);
            }
        });
        PlatformScreens::forget();
        return count($devs);
    }
}
