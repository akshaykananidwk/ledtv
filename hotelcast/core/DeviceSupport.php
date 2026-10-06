<?php
declare(strict_types=1);

/**
 * Support tools (#24): screenshots, log bundles and crash reports uploaded by TVs, plus TV
 * analytics events. Files are stored under storage/support/h{hotel}/d{device}/ (the storage folder
 * is never web reachable) and served only by admin/support.php after a permission + tenant check.
 * The last N files per device and kind are kept (hotel setting support_keep_per_device, default 10).
 */
final class DeviceSupport
{
    public const MAX_SCREENSHOT = 2 * 1024 * 1024;   // 2 MB JPEG
    public const MAX_LOGS = 512 * 1024;              // 512 KB of log text
    public const MAX_STATE = 64 * 1024;              // app state JSON stored with the logs
    public const MAX_CRASH = 64 * 1024;              // 64 KB stack trace
    public const MAX_EVENT_DATA = 4096;              // event data JSON
    public const KINDS = ['screenshot', 'logs', 'crash'];

    /** Storage root (tests may point it elsewhere). */
    public static function root(): string
    {
        return HC_ROOT . '/storage/support';
    }

    public static function keep(): int
    {
        return max(1, min(100, Settings::int('support_keep_per_device', 10)));
    }

    /** Absolute path of a stored file row, or null when it is missing / outside the storage root. */
    public static function path(array $row): ?string
    {
        $rel = (string) $row['file_path'];
        if ($rel === '' || str_contains($rel, '..') || !preg_match('#^h\d+/d\d+/[A-Za-z0-9_.-]+$#', $rel)) {
            return null;
        }
        $f = self::root() . '/' . $rel;
        return is_file($f) ? $f : null;
    }

    private static function store(array $device, string $kind, string $ext, string $data, array $row = []): int
    {
        $hid = Tenant::id();
        if ((int) $device['hotel_id'] !== $hid) {
            throw new TenantException('Device belongs to another hotel');
        }
        $dir = 'h' . $hid . '/d' . (int) $device['id'];
        $abs = self::root() . '/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new RuntimeException('Cannot create ' . $abs);
        }
        if (!is_file(self::root() . '/.htaccess')) {
            @file_put_contents(self::root() . '/.htaccess', "Require all denied\n");
        }
        $name = $kind . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (file_put_contents($abs . '/' . $name, $data, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write support file');
        }
        $id = DB::insert('device_support_files', $row + [
            'device_id' => (int) $device['id'],
            'room_id' => $device['room_id'] ? (int) $device['room_id'] : null,
            'kind' => $kind,
            'file_path' => $dir . '/' . $name,
            'file_size' => strlen($data),
            'app_version' => self::str($device['app_version'] ?? null, 40),
            'created_at' => now(),
        ]);
        self::prune((int) $device['id'], $kind);
        return $id;
    }

    /** Keep only the newest keep() files of a device and kind (current hotel). */
    public static function prune(int $deviceId, string $kind, ?int $keep = null): int
    {
        $keep ??= self::keep();
        $old = DB::all(
            'SELECT id, file_path FROM device_support_files WHERE hotel_id = :h AND device_id = :d AND kind = :k ORDER BY id DESC LIMIT 1000 OFFSET ' . (int) $keep,
            ['h' => Tenant::id(), 'd' => $deviceId, 'k' => $kind]
        );
        foreach ($old as $r) {
            self::deleteRow($r);
        }
        return count($old);
    }

    /** Delete a file row (current hotel) and its file. */
    public static function deleteRow(array $row): void
    {
        $f = self::path($row);
        if ($f) {
            @unlink($f);
        }
        DB::delete('device_support_files', 'id = :id', ['id' => (int) $row['id']]);
    }

    // ------------------------------------------------------------------ device uploads

    /** Validate + store a JPEG screenshot (raw bytes). Returns the file id. Throws InvalidArgumentException. */
    public static function saveScreenshot(array $device, string $jpeg): int
    {
        if ($jpeg === '' || strlen($jpeg) > self::MAX_SCREENSHOT) {
            throw new LengthException('Screenshot must be a JPEG of at most 2 MB');
        }
        $info = @getimagesizefromstring($jpeg);
        if (!str_starts_with($jpeg, "\xFF\xD8\xFF") || !$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 8192 || $info[1] > 8192) {
            throw new InvalidArgumentException('image must be a JPEG file');
        }
        return self::store($device, 'screenshot', 'jpg', $jpeg, ['meta' => json_out(['width' => $info[0], 'height' => $info[1]])]);
    }

    /** Store a log bundle {logs: string ≤ 512 KB, state: object}. */
    public static function saveLogs(array $device, array $in): int
    {
        $logs = $in['logs'] ?? null;
        if (!is_string($logs) || $logs === '') {
            throw new InvalidArgumentException('logs (string) is required');
        }
        if (strlen($logs) > self::MAX_LOGS) {
            throw new LengthException('logs must be at most 512 KB');
        }
        $state = $in['state'] ?? [];
        if (!is_array($state)) {
            throw new InvalidArgumentException('state must be an object');
        }
        $stateJson = json_encode((object) $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        if (strlen($stateJson) > self::MAX_STATE) {
            throw new LengthException('state must be at most 64 KB');
        }
        $logs = mb_convert_encoding($logs, 'UTF-8', 'UTF-8');
        $text = "=== HotelCast TV log bundle ===\n"
            . 'Received: ' . date('c') . "\nDevice: " . $device['device_uid'] . "\n\n=== App state ===\n" . $stateJson . "\n\n=== logcat ===\n" . $logs;
        return self::store($device, 'logs', 'txt', $text, [
            'meta' => json_out(['state' => (object) $state, 'log_bytes' => strlen($logs), 'lines' => substr_count($logs, "\n") + 1]),
        ]);
    }

    /** Store a crash report {stack, app_version, happened_at}. */
    public static function saveCrash(array $device, array $in): int
    {
        $stack = $in['stack'] ?? null;
        if (!is_string($stack) || trim($stack) === '') {
            throw new InvalidArgumentException('stack (string) is required');
        }
        if (strlen($stack) > self::MAX_CRASH) {
            throw new LengthException('stack must be at most 64 KB');
        }
        $ts = isset($in['happened_at']) && is_string($in['happened_at']) ? strtotime($in['happened_at']) : false;
        if (!$ts || $ts > time() + 86400 || $ts < time() - 90 * 86400) {
            $ts = time();
        }
        $version = self::str(is_scalar($in['app_version'] ?? null) ? (string) $in['app_version'] : null, 40) ?? self::str($device['app_version'] ?? null, 40);
        $firstLine = trim(strtok(preg_replace('/^Thread: [^\n]*\n/', '', $stack) ?? $stack, "\n") ?: '');
        return self::store($device, 'crash', 'txt', mb_convert_encoding($stack, 'UTF-8', 'UTF-8'), [
            'app_version' => $version,
            'happened_at' => date('Y-m-d H:i:s', $ts),
            'meta' => json_out(['summary' => mb_substr($firstLine, 0, 300)]),
        ]);
    }

    /** Store an analytics event {type, data}. */
    public static function saveEvent(array $device, array $in): int
    {
        $type = $in['type'] ?? null;
        if (!is_string($type) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $type)) {
            throw new InvalidArgumentException('type must match [a-z0-9_.-]{1,40}');
        }
        $data = $in['data'] ?? null;
        if ($data !== null && !is_array($data)) {
            throw new InvalidArgumentException('data must be an object');
        }
        $json = $data === null ? null : json_out((object) $data);
        if ($json !== null && strlen($json) > self::MAX_EVENT_DATA) {
            throw new LengthException('data must be at most 4 KB');
        }
        return DB::insert('device_events', [
            'device_id' => (int) $device['id'],
            'room_id' => $device['room_id'] ? (int) $device['room_id'] : null,
            'type' => $type,
            'data' => $json,
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ admin side

    /**
     * Ask one TV (current hotel) for a screenshot or its logs. Returns the device_commands id.
     * Unlike Broadcaster::sendCommand (rooms / groups / floors) this targets exactly one device.
     */
    public static function request(int $deviceId, string $command, ?int $userId = null): int
    {
        if (!in_array($command, ['SCREENSHOT', 'UPLOAD_LOGS'], true)) {
            throw new InvalidArgumentException('Unknown command');
        }
        $dev = Tenant::find('devices', $deviceId, 'is_revoked = 0');
        if (!$dev) {
            throw new InvalidArgumentException(__('Device not found.'));
        }
        Access::requireDevice($deviceId); // users limited to some TVs (403)
        // Collapse duplicate pending requests (the admin clicked twice).
        DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND command = :c AND status = 'pending'", ['d' => $deviceId, 'c' => $command]);
        $id = DB::insert('device_commands', [
            'device_id' => $deviceId,
            'command' => $command,
            'payload' => '{}',
            'status' => 'pending',
            'created_at' => now(),
        ]);
        ActivityLog::add('support_request', 'device', $deviceId, $command . ($dev['room_id'] ? ' (room #' . $dev['room_id'] . ')' : ''));
        return $id;
    }

    /** Status of a request + the newest file of the matching kind (for polling). */
    public static function requestStatus(int $deviceId, int $commandId): array
    {
        $dev = Tenant::find('devices', $deviceId);
        if (!$dev) {
            throw new InvalidArgumentException(__('Device not found.'));
        }
        Access::requireDevice($deviceId);
        $cmd = DB::one('SELECT id, command, status, message, created_at FROM device_commands WHERE id = :id AND device_id = :d', ['id' => $commandId, 'd' => $deviceId]);
        if (!$cmd) {
            throw new InvalidArgumentException(__('Not found.'));
        }
        $kind = $cmd['command'] === 'SCREENSHOT' ? 'screenshot' : 'logs';
        $file = DB::one(
            'SELECT id, kind, file_size, created_at FROM device_support_files WHERE hotel_id = :h AND device_id = :d AND kind = :k AND created_at >= :t ORDER BY id DESC LIMIT 1',
            ['h' => Tenant::id(), 'd' => $deviceId, 'k' => $kind, 't' => $cmd['created_at']]
        );
        return [
            'command_id' => (int) $cmd['id'],
            'status' => $cmd['status'],
            'message' => $cmd['message'],
            'file' => $file ? ['id' => (int) $file['id'], 'kind' => $file['kind'], 'size' => (int) $file['file_size'], 'created_at' => $file['created_at']] : null,
            'done' => $file !== null || in_array($cmd['status'], ['failed', 'expired'], true),
        ];
    }

    /** Files of a device (current hotel), newest first. */
    public static function files(int $deviceId, ?string $kind = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM device_support_files WHERE hotel_id = :h AND device_id = :d' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $p = ['h' => Tenant::id(), 'd' => $deviceId] + ($kind ? ['k' => $kind] : []);
        return DB::all($sql, $p);
    }

    /** Delete support files older than $days in the current hotel. */
    public static function purgeOlderThan(int $days): int
    {
        $rows = DB::all('SELECT id, file_path FROM device_support_files WHERE hotel_id = :h AND created_at < :c LIMIT 5000', ['h' => Tenant::id(), 'c' => date('Y-m-d H:i:s', time() - $days * 86400)]);
        foreach ($rows as $r) {
            self::deleteRow($r);
        }
        return count($rows);
    }

    // ------------------------------------------------------------------ platform (all hotels, read only)

    /**
     * Numbers for the platform support dashboard: totals, per-hotel rows (TVs, offline TVs, crashes
     * 24 h / 7 d, outdated app, failed commands 24 h), app version distribution against the newest
     * APK release (highest version_code uploaded by any hotel), recent crashes.
     */
    public static function platformStats(?int $now = null): array
    {
        $now ??= time();
        $d1 = date('Y-m-d H:i:s', $now - 86400);
        $d7 = date('Y-m-d H:i:s', $now - 7 * 86400);
        $newest = DB::one('SELECT version_code, version_name FROM apk_releases ORDER BY version_code DESC, id DESC LIMIT 1');
        $newestCode = $newest ? (int) $newest['version_code'] : null;
        $hotels = [];
        foreach (DB::all('SELECT id, name, status FROM hotels ORDER BY name') as $h) {
            $hotels[(int) $h['id']] = ['id' => (int) $h['id'], 'name' => $h['name'], 'status' => $h['status'],
                'tvs' => 0, 'offline' => 0, 'outdated' => 0, 'crashes_24h' => 0, 'crashes_7d' => 0, 'failed_24h' => 0];
        }
        $tv = DB::all(
            "SELECT hotel_id, COUNT(*) AS tvs, SUM(status = 'offline') AS offline,
                    SUM(app_version_code IS NOT NULL AND app_version_code < :nc) AS outdated
             FROM devices WHERE is_revoked = 0 AND room_id IS NOT NULL GROUP BY hotel_id",
            ['nc' => $newestCode ?? 0]
        );
        foreach ($tv as $r) {
            if (isset($hotels[(int) $r['hotel_id']])) {
                $hotels[(int) $r['hotel_id']]['tvs'] = (int) $r['tvs'];
                $hotels[(int) $r['hotel_id']]['offline'] = (int) $r['offline'];
                $hotels[(int) $r['hotel_id']]['outdated'] = (int) $r['outdated'];
            }
        }
        foreach (DB::all(
            "SELECT hotel_id, SUM(created_at >= :d1) AS c1, COUNT(*) AS c7 FROM device_support_files
             WHERE kind = 'crash' AND created_at >= :d7 GROUP BY hotel_id",
            ['d1' => $d1, 'd7' => $d7]
        ) as $r) {
            if (isset($hotels[(int) $r['hotel_id']])) {
                $hotels[(int) $r['hotel_id']]['crashes_24h'] = (int) $r['c1'];
                $hotels[(int) $r['hotel_id']]['crashes_7d'] = (int) $r['c7'];
            }
        }
        foreach (DB::all(
            "SELECT d.hotel_id, COUNT(*) AS n FROM device_commands c JOIN devices d ON d.id = c.device_id
             WHERE c.status = 'failed' AND c.created_at >= :d1 GROUP BY d.hotel_id",
            ['d1' => $d1]
        ) as $r) {
            if (isset($hotels[(int) $r['hotel_id']])) {
                $hotels[(int) $r['hotel_id']]['failed_24h'] = (int) $r['n'];
            }
        }
        $withErrors = array_values(array_filter($hotels, static fn ($h) => $h['crashes_24h'] > 0 || $h['failed_24h'] > 0 || $h['offline'] > 0));
        usort($withErrors, static fn ($a, $b) => [$b['crashes_24h'], $b['offline'], $b['failed_24h']] <=> [$a['crashes_24h'], $a['offline'], $a['failed_24h']]);
        $versions = array_map(static fn ($r) => [
            'version' => $r['app_version'] !== null ? (string) $r['app_version'] : '?',
            'code' => $r['app_version_code'] !== null ? (int) $r['app_version_code'] : null,
            'tvs' => (int) $r['n'],
            'outdated' => $newestCode !== null && $r['app_version_code'] !== null && (int) $r['app_version_code'] < $newestCode,
        ], DB::all(
            'SELECT app_version_code, MAX(app_version) AS app_version, COUNT(*) AS n FROM devices
             WHERE is_revoked = 0 AND room_id IS NOT NULL GROUP BY app_version_code ORDER BY app_version_code DESC'
        ));
        $recent = DB::all(
            "SELECT f.id, f.hotel_id, h.name AS hotel, r.room_number, f.app_version, f.happened_at, f.created_at, f.meta
             FROM device_support_files f JOIN hotels h ON h.id = f.hotel_id LEFT JOIN rooms r ON r.id = f.room_id AND r.hotel_id = f.hotel_id
             WHERE f.kind = 'crash' ORDER BY f.created_at DESC, f.id DESC LIMIT 20"
        );
        $sum = static fn (string $k) => array_sum(array_column($hotels, $k));
        return [
            'newest' => $newest ? ['code' => $newestCode, 'name' => (string) $newest['version_name']] : null,
            'totals' => [
                'hotels' => count($hotels), 'tvs' => $sum('tvs'), 'offline' => $sum('offline'), 'outdated' => $sum('outdated'),
                'crashes_24h' => $sum('crashes_24h'), 'crashes_7d' => $sum('crashes_7d'), 'failed_24h' => $sum('failed_24h'),
                'hotels_with_errors' => count($withErrors),
            ],
            'hotels' => array_values($hotels),
            'hotels_with_errors' => $withErrors,
            'versions' => $versions,
            'recent_crashes' => array_map(static function ($r) {
                $m = json_decode((string) $r['meta'], true) ?: [];
                unset($r['meta']);
                return $r + ['summary' => (string) ($m['summary'] ?? '')];
            }, $recent),
        ];
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return mb_substr(strip_tags((string) $v), 0, $max);
    }
}
