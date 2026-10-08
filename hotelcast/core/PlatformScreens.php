<?php
declare(strict_types=1);

/**
 * Platform → All screens (docs/modules/platform_screens.md): every TV ("device") of every customer
 * (`hotels`) in one list, with filters, counters, CSV, per-customer commands, revoke, and moving a TV
 * to another customer / screen (`rooms`). The unassigned pool lives in core/DevicePool.php.
 *
 * Scope: platform admins see every customer; resellers only their own customers (hotels.reseller_id).
 * Every method that takes device ids re-checks the scope (ids outside it → InvalidArgumentException,
 * logged), so a reseller can never act on another reseller's TVs.
 *
 * Online = devices.status 'online' (kept up to date per customer by the offline detector), the same
 * rule as the customers list and Platform → Support; last_ping is stored in each customer's time zone.
 */
final class PlatformScreens
{
    /** Commands offered on the page (all are in Broadcaster::DEVICE_COMMANDS); UPDATE_APP goes through pushUpdate(). */
    public const COMMANDS = ['RELOAD', 'REBOOT', 'SCREEN_ON', 'SCREEN_OFF', 'PING', 'CLEAR_CACHE'];
    public const PER_PAGE = 50;
    public const PER_PAGE_OPTIONS = [25, 50, 100, 200];
    /** CSV exports with more rows than this use the cheap mode (no ContentResolver per screen). */
    public const CSV_FULL_MODE_MAX = 1000;
    public const STATUSES = ['online', 'offline', 'revoked', 'any'];

    /** Test hook / CLI: force the acting user (null = Auth::user()). */
    public static ?array $userOverride = null;

    private static ?array $warnCache = null;
    private static ?array $newest = null;

    public static function forget(): void
    {
        self::$warnCache = null;
        self::$newest = null;
    }

    private static function user(): ?array
    {
        return self::$userOverride ?? Auth::user();
    }

    private static function userId(): ?int
    {
        $u = self::user();
        return isset($u['id']) ? (int) $u['id'] : null;
    }

    // ------------------------------------------------------------------ scope

    /** Customer ids the user may see: null = every customer (platform admin), [] = none. */
    public static function scopeHotelIds(?array $user = null): ?array
    {
        $u = $user ?? self::user();
        if (!$u) {
            return [];
        }
        if (($u['role'] ?? '') === 'platform_admin') {
            return null;
        }
        if (($u['role'] ?? '') === 'reseller' && !empty($u['reseller_id'])) {
            return array_map('intval', DB::column('SELECT id FROM hotels WHERE reseller_id = :r ORDER BY id', ['r' => (int) $u['reseller_id']]));
        }
        return [];
    }

    public static function canSeeHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $hotelId])) {
            return false;
        }
        $scope = self::scopeHotelIds();
        return $scope === null || in_array($hotelId, $scope, true);
    }

    /** SQL " AND col IN (…)" for the scope ('' = all, " AND 0 = 1" = nothing). */
    public static function scopeSql(string $col, ?array $scope, string $prefix = 'sc'): array
    {
        if ($scope === null) {
            return ['', []];
        }
        if (!$scope) {
            return [' AND 0 = 1', []];
        }
        [$in, $p] = DB::in($scope, $prefix);
        return [" AND $col IN $in", $p];
    }

    /** Customers in scope (id, name, status, reseller), for filters and the move dialog. */
    public static function customers(): array
    {
        [$sc, $p] = self::scopeSql('h.id', self::scopeHotelIds());
        return DB::all('SELECT h.id, h.name, h.status, h.reseller_id, h.max_tvs FROM hotels h WHERE 1 = 1' . $sc . ' ORDER BY h.name, h.id', $p);
    }

    /** Screens (rooms) of a customer in scope, for the move dialog. */
    public static function screensOf(int $hotelId): array
    {
        if (!self::canSeeHotel($hotelId)) {
            return [];
        }
        return DB::all(
            'SELECT r.id, r.room_number, r.name, r.floor,
                (SELECT COUNT(*) FROM devices d WHERE d.room_id = r.id AND d.hotel_id = r.hotel_id AND d.is_revoked = 0) AS tvs
             FROM rooms r WHERE r.hotel_id = :h ORDER BY LENGTH(r.room_number), r.room_number',
            ['h' => $hotelId]
        );
    }

    // ------------------------------------------------------------------ filters / listing

    /** Normalised filters from GET input. */
    public static function filters(array $in): array
    {
        $s = static fn (string $k, int $max = 60): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $status = $s('status', 10);
        $platform = $s('platform', 10);
        $per = (int) ($in['per_page'] ?? self::PER_PAGE);
        return [
            'q' => $s('q', 80),
            'customer' => max(0, (int) ($in['customer'] ?? 0)),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'platform' => in_array($platform, ['android', 'web'], true) ? $platform : '',
            'version' => $s('version', 20),
            'update' => !empty($in['update']),
            'warn' => !empty($in['warn']),
            'unassigned' => !empty($in['unassigned']),
            'page' => max(1, (int) ($in['page'] ?? 1)),
            'per_page' => in_array($per, self::PER_PAGE_OPTIONS, true) ? $per : self::PER_PAGE,
        ];
    }

    /** Newest APK version code per customer (+ platform-wide newest under key 0). */
    public static function newestCodes(): array
    {
        if (self::$newest !== null) {
            return self::$newest;
        }
        $out = [0 => (int) (DB::value('SELECT MAX(version_code) FROM apk_releases') ?? 0)];
        foreach (DB::all('SELECT hotel_id, MAX(version_code) AS mx FROM apk_releases GROUP BY hotel_id') as $r) {
            $out[(int) $r['hotel_id']] = (int) $r['mx'];
        }
        return self::$newest = $out;
    }

    /** SQL expression "this TV runs an older app than the newest APK" (customer's newest, else platform's). */
    private static function outdatedSql(): string
    {
        $notWeb = DeviceManager::notWebSql('d.platform');
        $global = (int) self::newestCodes()[0]; // integer from the database, inlined (no reused placeholders)
        return "(d.app_version_code IS NOT NULL AND d.app_version_code < COALESCE(apk.mx, $global)$notWeb)";
    }

    private static function fromSql(): string
    {
        return ' FROM devices d JOIN hotels h ON h.id = d.hotel_id
                 LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
                 LEFT JOIN (SELECT hotel_id, MAX(version_code) AS mx FROM apk_releases GROUP BY hotel_id) apk ON apk.hotel_id = d.hotel_id';
    }

    /** [whereSql, params] for the filters within the scope. */
    public static function where(array $f, ?array $scope): array
    {
        [$sc, $p] = self::scopeSql('d.hotel_id', $scope);
        $w = 'WHERE 1 = 1' . $sc;
        if ($f['customer']) {
            $w .= ' AND d.hotel_id = :fc';
            $p['fc'] = $f['customer'];
        }
        $w .= match ($f['status']) {
            'online' => " AND d.is_revoked = 0 AND d.status = 'online'",
            'offline' => " AND d.is_revoked = 0 AND d.status <> 'online'",
            'revoked' => ' AND d.is_revoked = 1',
            'any' => '',
            default => ' AND d.is_revoked = 0',
        };
        if ($f['platform'] !== '' && DeviceManager::notWebSql() !== '') {
            $w .= ' AND d.platform = :fp';
            $p['fp'] = $f['platform'];
        }
        if ($f['version'] !== '') {
            $w .= ' AND d.app_version = :fv';
            $p['fv'] = $f['version'];
        }
        if ($f['update']) {
            $w .= ' AND d.is_revoked = 0 AND ' . self::outdatedSql();
        }
        if ($f['unassigned']) {
            $w .= ' AND d.room_id IS NULL';
        }
        if ($f['warn']) {
            $ids = array_keys(self::warningMap($scope));
            if ($ids) {
                [$in, $wp] = DB::in($ids, 'fw');
                $w .= " AND d.id IN $in";
                $p += $wp;
            } else {
                $w .= ' AND 0 = 1';
            }
        }
        if ($f['q'] !== '') {
            $w .= ' AND (d.device_uid LIKE :q1 OR d.model LIKE :q2 OR d.ip_address LIKE :q3 OR d.public_ip LIKE :q4
                     OR r.room_number LIKE :q5 OR r.name LIKE :q6 OR h.name LIKE :q7 OR d.app_version LIKE :q8)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            foreach (range(1, 8) as $i) {
                $p['q' . $i] = $like;
            }
        }
        return [$w, $p];
    }

    /** Rows of one page: ['rows' => [...], 'total' => int, 'pages' => int]. */
    public static function list(array $f, ?array $scope = null, bool $all = false): array
    {
        $scope = func_num_args() >= 2 ? $scope : self::scopeHotelIds();
        [$w, $p] = self::where($f, $scope);
        $total = (int) DB::value('SELECT COUNT(*)' . self::fromSql() . ' ' . $w, $p);
        $sql = 'SELECT d.*, h.name AS hotel_name, h.status AS hotel_status, h.reseller_id, r.room_number, r.name AS room_name,
                       r.floor, r.is_enabled AS room_enabled, ' . self::outdatedSql() . ' AS outdated,
                       (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR \', \') FROM room_group_members m
                          JOIN room_groups g ON g.id = m.group_id AND g.hotel_id = d.hotel_id WHERE m.room_id = d.room_id) AS group_names'
            . self::fromSql() . ' ' . $w . ' ORDER BY h.name, h.id, d.room_id IS NULL, LENGTH(r.room_number), r.room_number, d.id';
        if (!$all) {
            $sql .= ' LIMIT ' . (int) $f['per_page'] . ' OFFSET ' . (int) (($f['page'] - 1) * $f['per_page']);
        }
        $rows = DB::all($sql, $p);
        return ['rows' => $rows, 'total' => $total, 'pages' => (int) max(1, ceil($total / max(1, $f['per_page'])))];
    }

    /**
     * Health warnings of every active TV in scope: device id => [key => text]. Same rule as
     * admin/tv_health.php (DeviceHealth::warnings on the last heartbeat). Cached per request.
     */
    public static function warningMap(?array $scope): array
    {
        $key = json_encode($scope);
        if (isset(self::$warnCache[$key])) {
            return self::$warnCache[$key];
        }
        [$sc, $p] = self::scopeSql('hotel_id', $scope);
        $out = [];
        $rows = DB::query(
            'SELECT id, health, network_type, wifi_signal, uptime_sec FROM devices
             WHERE is_revoked = 0 AND room_id IS NOT NULL AND (health IS NOT NULL OR wifi_signal IS NOT NULL OR uptime_sec IS NOT NULL)' . $sc,
            $p
        );
        while ($d = $rows->fetch(PDO::FETCH_ASSOC)) {
            $w = DeviceHealth::warnings(DeviceHealth::of($d), $d);
            if ($w) {
                $out[(int) $d['id']] = $w;
            }
        }
        self::$warnCache[$key] = $out;
        return $out;
    }

    /** Header counters for the scope: total, online, offline, outdated, warnings, unassigned, revoked. */
    public static function counters(?array $scope = null): array
    {
        $scope = func_num_args() >= 1 ? $scope : self::scopeHotelIds();
        [$sc, $p] = self::scopeSql('d.hotel_id', $scope);
        $r = DB::one(
            "SELECT SUM(d.is_revoked = 0) AS total,
                    SUM(d.is_revoked = 0 AND d.status = 'online') AS online,
                    SUM(d.is_revoked = 0 AND d.status <> 'online') AS offline,
                    SUM(d.is_revoked = 0 AND " . self::outdatedSql() . ') AS outdated,
                    SUM(d.is_revoked = 0 AND d.room_id IS NULL) AS no_screen,
                    SUM(d.is_revoked = 1) AS revoked'
            . self::fromSql() . ' WHERE 1 = 1' . $sc,
            $p
        ) ?? [];
        $pool = $scope === null && Migrator::hasTable(DB::pdo(), 'device_pool') ? (int) DB::value('SELECT COUNT(*) FROM device_pool') : 0;
        return [
            'total' => (int) ($r['total'] ?? 0),
            'online' => (int) ($r['online'] ?? 0),
            'offline' => (int) ($r['offline'] ?? 0),
            'outdated' => (int) ($r['outdated'] ?? 0),
            'warnings' => count(self::warningMap($scope)),
            'unassigned' => (int) ($r['no_screen'] ?? 0) + $pool,
            'pool' => $pool,
            'revoked' => (int) ($r['revoked'] ?? 0),
        ];
    }

    /** Per customer: screens / online / offline (dashboard + customers list). hotel id => counts. */
    public static function perCustomer(?array $scope = null): array
    {
        $scope = func_num_args() >= 1 ? $scope : self::scopeHotelIds();
        [$sc, $p] = self::scopeSql('h.id', $scope);
        $out = [];
        foreach (DB::all(
            "SELECT h.id, h.name, h.status,
                    COUNT(d.id) AS total, COALESCE(SUM(d.status = 'online'), 0) AS online
             FROM hotels h LEFT JOIN devices d ON d.hotel_id = h.id AND d.is_revoked = 0 AND d.room_id IS NOT NULL
             WHERE 1 = 1$sc GROUP BY h.id, h.name, h.status",
            $p
        ) as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => (string) $r['status'],
                'total' => (int) $r['total'], 'online' => (int) $r['online'], 'offline' => (int) $r['total'] - (int) $r['online']];
        }
        return $out;
    }

    /** Users per customer (hotel roles only): hotel id => count. */
    public static function usersPerCustomer(?array $scope = null): array
    {
        $scope = func_num_args() >= 1 ? $scope : self::scopeHotelIds();
        [$sc, $p] = self::scopeSql('hotel_id', $scope);
        $out = [];
        foreach (DB::all("SELECT hotel_id, COUNT(*) AS n FROM users WHERE hotel_id IS NOT NULL AND role NOT IN ('platform_admin','reseller','chain_admin')$sc GROUP BY hotel_id", $p) as $r) {
            $out[(int) $r['hotel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Add display fields to listed rows: warnings, online, last_seen, mode / showing (per customer
     * context). $full = false skips ContentResolver (large CSV exports): mode from hotel status / room on-off.
     */
    public static function decorate(array $rows, bool $full = true): array
    {
        if (!$rows) {
            return [];
        }
        $warn = self::warningMap(self::scopeHotelIds());
        $roomIds = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['room_id'] ?? 0), $rows))));
        $rooms = [];
        if ($roomIds && $full) {
            [$in, $p] = DB::in($roomIds, 'dr');
            foreach (DB::all("SELECT * FROM rooms WHERE id IN $in", $p) as $room) {
                $rooms[(int) $room['id']] = $room;
            }
        }
        $byHotel = [];
        foreach ($rows as $i => $r) {
            $byHotel[(int) $r['hotel_id']][] = $i;
        }
        foreach ($byHotel as $hid => $idx) {
            $fill = static function () use (&$rows, $idx, $rooms, $warn, $full): void {
                $active = Tenant::isActive();
                foreach ($idx as $i) {
                    $r = &$rows[$i];
                    $r['warnings'] = (int) $r['is_revoked'] ? [] : ($warn[(int) $r['id']] ?? []);
                    $r['online'] = !(int) $r['is_revoked'] && $r['status'] === 'online';
                    $r['last_seen'] = $r['last_ping'] ? time_ago((string) $r['last_ping']) : __('never');
                    $r['outdated'] = (bool) (int) ($r['outdated'] ?? 0);
                    $r['mode'] = '';
                    $r['showing'] = '-';
                    if ((int) $r['is_revoked']) {
                        $r['mode'] = 'revoked';
                        $r['showing'] = __('Revoked');
                    } elseif (!$r['room_id']) {
                        $r['mode'] = 'unassigned';
                        $r['showing'] = __('No screen');
                    } elseif (!$active) {
                        $r['mode'] = 'suspended';
                        $r['showing'] = __('Service paused');
                    } elseif ($full && isset($rooms[(int) $r['room_id']])) {
                        try {
                            $c = ContentResolver::forRoom($rooms[(int) $r['room_id']]);
                            $r['mode'] = (string) $c['mode'];
                            $r['showing'] = ContentResolver::describe($c);
                        } catch (Throwable $e) {
                            Logger::error('All screens: content of room ' . $r['room_id'] . ' failed: ' . $e->getMessage());
                        }
                    } elseif (!(int) ($r['room_enabled'] ?? 1)) {
                        $r['mode'] = 'off';
                        $r['showing'] = __('Screen off');
                    }
                    unset($r);
                }
            };
            Tenant::run($hid, $fill);
        }
        return $rows;
    }

    /** Distinct app versions in scope (filter list). */
    public static function versions(?array $scope = null): array
    {
        $scope = func_num_args() >= 1 ? $scope : self::scopeHotelIds();
        [$sc, $p] = self::scopeSql('hotel_id', $scope);
        return DB::column('SELECT DISTINCT app_version FROM devices WHERE app_version IS NOT NULL AND is_revoked = 0' . $sc . ' ORDER BY app_version DESC LIMIT 50', $p);
    }

    /** Top customers by number of screens (TVs) and customers with offline TVs. */
    public static function dashboard(?array $scope = null, int $limit = 8): array
    {
        $scope = func_num_args() >= 1 ? $scope : self::scopeHotelIds();
        $per = array_values(self::perCustomer($scope));
        $top = array_values(array_filter($per, static fn ($c) => $c['total'] > 0));
        usort($top, static fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        $off = array_values(array_filter($per, static fn ($c) => $c['offline'] > 0));
        usort($off, static fn ($a, $b) => [$b['offline'], $a['name']] <=> [$a['offline'], $b['name']]);
        return ['counters' => self::counters($scope), 'top' => array_slice($top, 0, $limit), 'offline' => array_slice($off, 0, $limit)];
    }

    // ------------------------------------------------------------------ actions

    /**
     * Device rows for ids, all inside the scope. Any id outside the scope (or unknown) → logged and
     * InvalidArgumentException (nothing is changed).
     */
    public static function devices(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
        if (!$ids) {
            throw new InvalidArgumentException(__('Select at least one screen.'));
        }
        if (count($ids) > 1000) {
            throw new InvalidArgumentException(__('Too many screens selected (max. 1000).'));
        }
        [$in, $p] = DB::in($ids, 'dv');
        [$sc, $sp] = self::scopeSql('hotel_id', self::scopeHotelIds());
        $rows = DB::all("SELECT * FROM devices WHERE id IN $in" . $sc . ' ORDER BY hotel_id, id', $p + $sp);
        if (count($rows) !== count($ids)) {
            Logger::write('security', 'warning', 'All screens: device outside scope', ['user' => self::userId(), 'ids' => array_slice($ids, 0, 20)]);
            throw new InvalidArgumentException(__('Screen not found.'));
        }
        return $rows;
    }

    private static function groupByHotel(array $devices): array
    {
        $out = [];
        foreach ($devices as $d) {
            $out[(int) $d['hotel_id']][] = $d;
        }
        return $out;
    }

    private static function hotelName(int $hid): string
    {
        return (string) (DB::value('SELECT name FROM hotels WHERE id = :id', ['id' => $hid]) ?? ('#' . $hid));
    }

    /**
     * Send a whitelisted command to the selected TVs, per customer: Broadcaster::sendCommand inside that
     * customer's context, targeting the screens of the selected TVs. Returns ['customers' => n, 'tvs' => n].
     */
    public static function bulkCommand(array $deviceIds, string $command): array
    {
        if (!in_array($command, self::COMMANDS, true) || !in_array($command, Broadcaster::DEVICE_COMMANDS, true)) {
            throw new InvalidArgumentException(__('Unknown command.'));
        }
        $out = ['customers' => 0, 'tvs' => 0];
        foreach (self::groupByHotel(self::devices($deviceIds)) as $hid => $devs) {
            $roomIds = array_values(array_unique(array_map(static fn ($d) => (int) $d['room_id'], array_filter($devs, static fn ($d) => $d['room_id'] && !(int) $d['is_revoked']))));
            if (!$roomIds) {
                continue;
            }
            [, $n] = Tenant::run($hid, static function () use ($command, $roomIds): array {
                $res = Broadcaster::sendCommand($command, 'rooms', $roomIds, [], self::userId());
                ActivityLog::add('platform_command', 'device', null, $command . ' → ' . count($roomIds) . ' screen(s) (Platform → All screens)', Tenant::id());
                return $res;
            });
            $out['customers']++;
            $out['tvs'] += $n;
        }
        ActivityLog::add('platform_command', 'device', null, $command . ' → ' . $out['tvs'] . ' TV(s) of ' . $out['customers'] . ' customer(s)', null);
        return $out;
    }

    /**
     * Push the newest APK of each customer (APK Manager of that customer) to the selected Android TVs.
     * Customers without an APK are skipped. Returns ['customers' => n, 'tvs' => n, 'skipped' => [names]].
     */
    public static function pushUpdate(array $deviceIds): array
    {
        $out = ['customers' => 0, 'tvs' => 0, 'skipped' => []];
        foreach (self::groupByHotel(self::devices($deviceIds)) as $hid => $devs) {
            $roomIds = array_values(array_unique(array_map(static fn ($d) => (int) $d['room_id'], array_filter($devs, static fn ($d) => $d['room_id'] && !(int) $d['is_revoked'] && !DeviceManager::isWeb($d)))));
            if (!$roomIds) {
                continue;
            }
            $apkId = (int) DB::value('SELECT id FROM apk_releases WHERE hotel_id = :h ORDER BY version_code DESC, id DESC LIMIT 1', ['h' => $hid]);
            if (!$apkId) {
                $out['skipped'][] = self::hotelName($hid);
                continue;
            }
            [, $n] = Tenant::run($hid, static function () use ($apkId, $roomIds): array {
                $res = Broadcaster::pushApk($apkId, 'rooms', $roomIds, self::userId());
                ActivityLog::add('platform_command', 'apk', $apkId, 'UPDATE_APP → ' . count($roomIds) . ' screen(s) (Platform → All screens)', Tenant::id());
                return $res;
            });
            $out['customers']++;
            $out['tvs'] += $n;
        }
        ActivityLog::add('platform_command', 'apk', null, 'UPDATE_APP → ' . $out['tvs'] . ' TV(s) of ' . $out['customers'] . ' customer(s)', null);
        return $out;
    }

    /** Stop pending commands / live view / cached health of a TV (it leaves its customer or is revoked). */
    private static function clearDeviceState(array $d): void
    {
        $id = (int) $d['id'];
        DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND status IN ('pending','delivered')", ['d' => $id]);
        if (Migrator::hasTable(DB::pdo(), 'device_live_views')) {
            DB::query('DELETE FROM device_live_views WHERE device_id = :d', ['d' => $id]);
            @unlink(LiveView::framePath((int) $d['hotel_id'], $id));
        }
        if (Migrator::hasTable(DB::pdo(), 'device_health_history')) {
            DB::query('DELETE FROM device_health_history WHERE device_id = :d AND hotel_id = :h', ['d' => $id, 'h' => (int) $d['hotel_id']]);
        }
    }

    /** Revoke TVs (like Rooms & TVs → Revoke): new random token, the TV returns to its setup screen. Returns count. */
    public static function revoke(array $deviceIds): int
    {
        $devs = array_values(array_filter(self::devices($deviceIds), static fn ($d) => !(int) $d['is_revoked']));
        DB::transaction(static function () use ($devs): void {
            foreach ($devs as $d) {
                DB::query(
                    "UPDATE devices SET is_revoked = 1, status = 'offline', token_hash = :t, health_alerts = NULL WHERE id = :id AND hotel_id = :h",
                    ['t' => hash('sha256', random_token(32)), 'id' => (int) $d['id'], 'h' => (int) $d['hotel_id']]
                );
                self::clearDeviceState($d);
                ActivityLog::add('device_revoke', 'device', (int) $d['id'], 'Revoked device ' . $d['device_uid'] . ' (Platform → All screens)', (int) $d['hotel_id']);
            }
        });
        if ($devs) {
            ActivityLog::add('device_revoke', 'device', null, 'Revoked ' . count($devs) . ' TV(s): ' . implode(', ', array_slice(array_column($devs, 'device_uid'), 0, 20)), null);
        }
        return count($devs);
    }

    /**
     * Throw RuntimeException when $incoming more TVs would exceed the customer's screen limit
     * (hotels.max_tvs / plan / license, Tenant::maxTvs). Call inside the transaction after locking the hotel row.
     */
    public static function assertCapacity(int $hotelId, int $incoming): void
    {
        if ($incoming <= 0) {
            return;
        }
        Tenant::forget($hotelId);
        $max = Tenant::maxTvs($hotelId);
        $used = Tenant::tvCount($hotelId);
        if ($max !== null && $used + $incoming > $max) {
            throw new RuntimeException(__('LICENSE_LIMIT: :c has :u of :m screens in use; :n more do not fit. Raise the limit or remove old TVs first.', [
                'c' => self::hotelName($hotelId), 'u' => $used, 'm' => $max, 'n' => $incoming,
            ]));
        }
    }

    /**
     * Resolve / create the target screen (room) in $hotelId. Runs inside the customer's context.
     *  mode 'existing': $roomId (must belong to the customer)
     *  mode 'new':      new screen named $name (screen ID derived from the name, made unique)
     *  mode 'same':     screen with the ID $sameNumber (created when missing, copying $copy name / floor)
     */
    public static function targetRoom(int $hotelId, string $mode, int $roomId, string $name, string $sameNumber = '', array $copy = []): array
    {
        if ($mode === 'existing') {
            $room = DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $roomId, 'h' => $hotelId]);
            if (!$room) {
                throw new InvalidArgumentException(__('Choose a screen of the target customer.'));
            }
            return $room;
        }
        if ($mode === 'same') {
            $number = mb_substr(trim($sameNumber), 0, 20);
            if ($number === '') {
                throw new InvalidArgumentException(__('This TV has no screen ID. Choose a screen or create a new one.'));
            }
            $room = DB::one('SELECT * FROM rooms WHERE hotel_id = :h AND room_number = :n', ['h' => $hotelId, 'n' => $number]);
            if ($room) {
                return $room;
            }
            $name = trim((string) ($copy['name'] ?? '')) ?: $number;
        } elseif ($mode === 'new') {
            $name = trim($name);
            if ($name === '') {
                throw new InvalidArgumentException(__('Enter a name for the new screen.'));
            }
            $base = trim(preg_replace('/\s+/u', '-', mb_substr($name, 0, 20)) ?? '', '-') ?: 'Screen';
            $number = mb_substr($base, 0, 20);
            for ($i = 2; DB::value('SELECT id FROM rooms WHERE hotel_id = :h AND room_number = :n', ['h' => $hotelId, 'n' => $number]); $i++) {
                $sfx = '-' . $i;
                $number = mb_substr($base, 0, 20 - strlen($sfx)) . $sfx;
            }
        } else {
            throw new InvalidArgumentException(__('Choose a target screen.'));
        }
        $rid = (int) Tenant::run($hotelId, static function () use ($number, $name, $copy): int {
            $rid = DB::insert('rooms', ['room_number' => $number, 'name' => mb_substr($name, 0, 100), 'floor' => $copy['floor'] ?? null, 'created_at' => now()]);
            ActivityLog::add('room_create', 'room', $rid, 'Screen ' . $number . ' created by Platform → All screens', Tenant::id());
            return $rid;
        });
        return (array) DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $rid, 'h' => $hotelId]);
    }

    /**
     * Move TVs to another customer (or another screen of the same customer), atomically.
     * $opt: mode existing|new|same, room_id, name. With several TVs and mode 'new', every TV gets its
     * own new screen ("Name", "Name-2", …).
     *
     * Token decision (docs/modules/platform_screens.md § Token): the device token is KEPT. The TV polls
     * with it; DeviceManager::authenticate() looks the token up across customers and Tenant::forDevice()
     * takes hotel_id from the (now updated) row, so the very next poll is served in the new customer's
     * context with the new customer's content — no visit to the TV, no re-registration.
     *
     * Cleared on the move: pending / delivered commands (expired), the live view session + frame,
     * the health history and debounced health alerts of the old customer, the content hash.
     * Kept: history rows of the old customer (status log, play log, support files) — they stay in the
     * old customer's logs. Returns the number of TVs moved.
     */
    public static function move(array $deviceIds, int $targetHotel, array $opt): int
    {
        $devs = self::devices($deviceIds);
        if (!self::canSeeHotel($targetHotel)) {
            Logger::write('security', 'warning', 'All screens: move to customer outside scope', ['user' => self::userId(), 'hotel' => $targetHotel]);
            throw new InvalidArgumentException(__('Customer not found.'));
        }
        foreach ($devs as $d) {
            if ((int) $d['is_revoked']) {
                throw new InvalidArgumentException(__('Revoked TVs cannot be moved: :u must register again.', ['u' => $d['device_uid']]));
            }
        }
        $mode = (string) ($opt['mode'] ?? 'existing');
        $roomId = (int) ($opt['room_id'] ?? 0);
        $name = (string) ($opt['name'] ?? '');
        $roomNumbers = [];
        foreach ($devs as $d) {
            $roomNumbers[(int) $d['id']] = $d['room_id'] ? (array) DB::one('SELECT room_number, name, floor FROM rooms WHERE id = :id', ['id' => (int) $d['room_id']]) : [];
        }
        $moved = DB::transaction(static function () use ($devs, $targetHotel, $mode, $roomId, $name, $roomNumbers): array {
            DB::one('SELECT id FROM hotels WHERE id = :id FOR UPDATE', ['id' => $targetHotel]);
            // Only TVs that do not already count for the target need a free slot.
            $incoming = count(array_filter($devs, static fn ($d) => (int) $d['hotel_id'] !== $targetHotel || !$d['room_id']));
            self::assertCapacity($targetHotel, $incoming);
            $done = [];
            $many = count($devs) > 1;
            foreach (array_values($devs) as $i => $d) {
                $copy = $roomNumbers[(int) $d['id']] ?? [];
                $room = self::targetRoom($targetHotel, $mode, $roomId, $mode === 'new' && $many ? trim($name) . ' ' . ($i + 1) : $name, (string) ($copy['room_number'] ?? ''), $copy);
                $from = (int) $d['hotel_id'];
                if ($from === $targetHotel && (int) $d['room_id'] === (int) $room['id']) {
                    continue;
                }
                if ($from !== $targetHotel) {
                    // (hotel_id, device_uid) is unique: an old revoked / screen-less record of this TV in the
                    // target customer is replaced; an active one blocks the move.
                    $clash = DB::one('SELECT id, is_revoked, room_id FROM devices WHERE hotel_id = :h AND device_uid = :u AND id <> :id', ['h' => $targetHotel, 'u' => $d['device_uid'], 'id' => (int) $d['id']]);
                    if ($clash && !(int) $clash['is_revoked'] && $clash['room_id']) {
                        throw new RuntimeException(__(':c already has an active TV with the device ID :u.', ['c' => self::hotelName($targetHotel), 'u' => $d['device_uid']]));
                    }
                    if ($clash) {
                        DB::query('DELETE FROM devices WHERE id = :id AND hotel_id = :h', ['id' => (int) $clash['id'], 'h' => $targetHotel]);
                    }
                    self::clearDeviceState($d);
                }
                DB::query(
                    'UPDATE devices SET hotel_id = :t, room_id = :r, current_hash = NULL, current_item_id = NULL, health_alerts = NULL, offline_notified = 0
                     WHERE id = :id AND hotel_id = :f',
                    ['t' => $targetHotel, 'r' => (int) $room['id'], 'id' => (int) $d['id'], 'f' => $from]
                );
                DB::query('INSERT INTO device_status_logs (hotel_id, device_id, room_id, status, created_at) VALUES (:h, :d, :r, :s, :c)', [
                    'h' => $targetHotel, 'd' => (int) $d['id'], 'r' => (int) $room['id'], 's' => $d['status'] === 'online' ? 'online' : 'offline', 'c' => now(),
                ]);
                $what = $d['device_uid'] . ' (' . ($d['model'] ?: 'TV') . ')';
                if ($from !== $targetHotel) {
                    ActivityLog::add('device_moved_out', 'device', (int) $d['id'], 'TV ' . $what . ' moved to another customer by the platform', $from);
                    ActivityLog::add('device_moved_in', 'device', (int) $d['id'], 'TV ' . $what . ' assigned to screen ' . $room['room_number'] . ' by the platform', $targetHotel);
                } else {
                    ActivityLog::add('device_moved', 'device', (int) $d['id'], 'TV ' . $what . ' moved to screen ' . $room['room_number'] . ' by the platform', $targetHotel);
                }
                ActivityLog::add('device_move', 'device', (int) $d['id'], 'TV ' . $what . ': ' . self::hotelName($from) . ' #' . $from . ' → ' . self::hotelName($targetHotel) . ' #' . $targetHotel . ', screen ' . $room['room_number'], null);
                $done[] = $from;
            }
            return $done;
        });
        foreach (array_unique(array_merge($moved, [$targetHotel])) as $hid) {
            Tenant::forget((int) $hid);
            Tenant::run((int) $hid, static fn () => Settings::bumpContentVersion());
        }
        self::forget();
        return count($moved);
    }
}
