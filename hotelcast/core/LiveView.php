<?php
declare(strict_types=1);

/**
 * Live screen view (2.4 #41, docs/modules/device_features.md). The admin opens admin/live_view.php
 * for one TV: start() creates / renews the TV's session (one per TV, random token) and queues
 * `LIVE_VIEW {session, interval, max_sec, max_width, quality}`. The TV then captures its screen every
 * few seconds (same PixelCopy path as SCREENSHOT) and uploads small JPEGs to the existing
 * POST /api/device/screenshot with the extra field `live=<token>` (api/routes/device_features.php).
 * Every admin poll is a keepalive (+2 minutes); closing the page calls stop(). The answer to every
 * frame tells the TV whether to continue, so it stops at the latest 2 minutes after the last
 * keepalive. Only the newest frame is kept: storage/support/h{hotel}/d{device}/live.jpg.
 */
final class LiveView
{
    public const KEEPALIVE_SEC = 120;
    public const INTERVAL_SEC = 4;
    public const MAX_WIDTH = 960;
    public const QUALITY = 60;
    public const MAX_FRAME_BYTES = 400 * 1024;
    public const MIN_FRAME_GAP_SEC = 2;
    public const MAX_FRAMES_PER_HOUR = 1200;
    /** Frames of sessions that ended longer ago than this are deleted by DeviceHealthTask. */
    public const FRAME_KEEP_SEC = 3600;

    public static function framePath(int $hotelId, int $deviceId): string
    {
        return DeviceSupport::root() . '/h' . $hotelId . '/d' . $deviceId . '/live.jpg';
    }

    /** The current hotel's session of a device, or null. */
    public static function find(int $deviceId): ?array
    {
        return DB::one('SELECT * FROM device_live_views WHERE hotel_id = :h AND device_id = :d', ['h' => Tenant::id(), 'd' => $deviceId]);
    }

    private static function device(int $deviceId): array
    {
        $dev = Tenant::find('devices', $deviceId);
        if (!$dev) {
            throw new InvalidArgumentException(__('Device not found.'));
        }
        Access::requireDevice($deviceId); // users limited to some TVs → 403
        return $dev;
    }

    /** Start (or restart) live view of a TV of the current hotel. Returns status(). */
    public static function start(int $deviceId, ?int $userId = null, ?int $now = null): array
    {
        $now ??= time();
        $dev = self::device($deviceId);
        if ((int) $dev['is_revoked']) {
            throw new InvalidArgumentException(__('This TV is revoked.'));
        }
        $token = bin2hex(random_bytes(16));
        $nowS = date('Y-m-d H:i:s', $now);
        // Only one LIVE_VIEW waiting per TV (the admin re-opened the page).
        DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND command = 'LIVE_VIEW' AND status IN ('pending','delivered')", ['d' => $deviceId]);
        $cid = DB::insert('device_commands', [
            'device_id' => $deviceId,
            'command' => 'LIVE_VIEW',
            'payload' => json_out([
                'session' => $token, 'interval' => self::INTERVAL_SEC, 'max_sec' => self::KEEPALIVE_SEC,
                'max_width' => self::MAX_WIDTH, 'quality' => self::QUALITY,
            ]),
            'status' => 'pending',
            'created_at' => $nowS,
        ]);
        $row = [
            'token' => $token, 'command_id' => $cid, 'started_by' => $userId, 'started_at' => $nowS, 'keepalive_at' => $nowS,
            'expires_at' => date('Y-m-d H:i:s', $now + self::KEEPALIVE_SEC), 'stopped_at' => null,
        ];
        if (self::find($deviceId)) {
            DB::update('device_live_views', $row, 'device_id = :d', ['d' => $deviceId]);
        } else {
            DB::insert('device_live_views', $row + ['device_id' => $deviceId, 'frames' => 0]);
        }
        ActivityLog::add('live_view', 'device', $deviceId, 'Live view started' . ($dev['room_id'] ? ' (room #' . $dev['room_id'] . ')' : ''));
        return self::status($deviceId, false, $now);
    }

    /**
     * Status for the admin page; $keepalive extends a running session by KEEPALIVE_SEC.
     * {active, token, frame: {at, age_sec, size, width, height, n} | null, command: {status, message}, expires_in}
     */
    public static function status(int $deviceId, bool $keepalive = true, ?int $now = null): array
    {
        $now ??= time();
        self::device($deviceId);
        $s = self::find($deviceId);
        $active = $s && $s['stopped_at'] === null && strtotime((string) $s['expires_at']) >= $now;
        if ($active && $keepalive) {
            DB::update('device_live_views', ['keepalive_at' => date('Y-m-d H:i:s', $now), 'expires_at' => date('Y-m-d H:i:s', $now + self::KEEPALIVE_SEC)], 'device_id = :d', ['d' => $deviceId]);
            $s['expires_at'] = date('Y-m-d H:i:s', $now + self::KEEPALIVE_SEC);
        }
        $cmd = $s && $s['command_id'] ? DB::one('SELECT status, message FROM device_commands WHERE id = :id AND device_id = :d', ['id' => (int) $s['command_id'], 'd' => $deviceId]) : null;
        $hasFrame = $s && $s['frame_at'] && is_file(self::framePath(Tenant::id(), $deviceId));
        return [
            'active' => (bool) $active,
            'frame' => $hasFrame ? [
                'at' => $s['frame_at'],
                'age_sec' => max(0, $now - (int) strtotime((string) $s['frame_at'])),
                'size' => (int) $s['frame_size'],
                'width' => (int) $s['frame_width'],
                'height' => (int) $s['frame_height'],
                'n' => (int) $s['frames'],
            ] : null,
            'command' => $cmd ? ['status' => $cmd['status'], 'message' => (string) ($cmd['message'] ?? '')] : null,
            'expires_in' => $active ? max(0, (int) strtotime((string) $s['expires_at']) - $now) : 0,
        ];
    }

    /** Admin closed the page. Returns true when a running session was stopped. */
    public static function stop(int $deviceId, ?int $now = null): bool
    {
        $now ??= time();
        self::device($deviceId);
        $n = DB::update('device_live_views', ['stopped_at' => date('Y-m-d H:i:s', $now)], 'device_id = :d AND stopped_at IS NULL', ['d' => $deviceId]);
        // A LIVE_VIEW not yet fetched by the TV is not needed any more.
        DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND command = 'LIVE_VIEW' AND status = 'pending'", ['d' => $deviceId]);
        return $n > 0;
    }

    /**
     * A frame from the TV (device auth, Tenant = the device's hotel). Returns the answer for the TV:
     * ['stored' => bool, 'live' => ['continue' => bool, 'interval' => int, 'stop_in' => int]].
     * Throws LengthException (too large), InvalidArgumentException (not a JPEG / too wide),
     * RangeException (too frequent; message = retry seconds).
     */
    public static function acceptFrame(array $device, string $token, string $jpeg, ?int $now = null): array
    {
        $now ??= time();
        $hid = (int) $device['hotel_id'];
        if ($hid !== Tenant::id()) {
            throw new TenantException('Device belongs to another hotel');
        }
        $stop = ['stored' => false, 'live' => ['continue' => false, 'interval' => self::INTERVAL_SEC, 'stop_in' => 0]];
        $s = self::find((int) $device['id']);
        if (!$s || !preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) $s['token'], $token)
            || $s['stopped_at'] !== null || strtotime((string) $s['expires_at']) < $now) {
            return $stop; // closed, expired or replaced by a newer session: the TV stops
        }
        if ($jpeg === '' || strlen($jpeg) > self::MAX_FRAME_BYTES) {
            throw new LengthException('Live frames must be JPEGs of at most ' . intdiv(self::MAX_FRAME_BYTES, 1024) . ' KB');
        }
        $info = @getimagesizefromstring($jpeg);
        if (!str_starts_with($jpeg, "\xFF\xD8\xFF") || !$info || $info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('image must be a JPEG file');
        }
        if ($info[0] > self::MAX_WIDTH || $info[1] > self::MAX_WIDTH * 2) {
            throw new InvalidArgumentException('Live frames must be at most ' . self::MAX_WIDTH . ' px wide');
        }
        if ($s['frame_at'] && $now - (int) strtotime((string) $s['frame_at']) < self::MIN_FRAME_GAP_SEC) {
            throw new RangeException((string) self::MIN_FRAME_GAP_SEC);
        }
        $retry = RateLimiter::hit('live:' . (int) $device['id'], self::MAX_FRAMES_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new RangeException((string) $retry);
        }
        // Security (2.4 review): re-encode with GD like every other upload, so only pixels are kept (no
        // appended / polyglot payload, no metadata) from a TV whose token may be in a guest's hands.
        $im = @imagecreatefromstring($jpeg);
        if ($im === false) {
            throw new InvalidArgumentException('image must be a JPEG file');
        }
        ob_start();
        imagejpeg($im, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        $file = self::framePath($hid, (int) $device['id']);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create ' . $dir);
        }
        if (!is_file(DeviceSupport::root() . '/.htaccess')) {
            @file_put_contents(DeviceSupport::root() . '/.htaccess', "Require all denied\n");
        }
        // Keep the newest frame only: write next to it, then replace atomically.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $jpeg, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write live frame');
        }
        DB::query(
            'UPDATE device_live_views SET frame_at = :t, frame_size = :s, frame_width = :w, frame_height = :hh, frames = frames + 1
             WHERE hotel_id = :h AND device_id = :d',
            ['t' => date('Y-m-d H:i:s', $now), 's' => strlen($jpeg), 'w' => $info[0], 'hh' => $info[1], 'h' => $hid, 'd' => (int) $device['id']]
        );
        return ['stored' => true, 'live' => [
            'continue' => true,
            'interval' => self::INTERVAL_SEC,
            'stop_in' => max(0, min(self::KEEPALIVE_SEC, (int) strtotime((string) $s['expires_at']) - $now)),
        ]];
    }

    /** Delete frames (and rows) of sessions that ended more than FRAME_KEEP_SEC ago (all hotels). */
    public static function cleanup(?int $now = null): int
    {
        $now ??= time();
        $old = DB::all(
            'SELECT id, hotel_id, device_id FROM device_live_views WHERE COALESCE(stopped_at, expires_at) < :t LIMIT 1000',
            ['t' => date('Y-m-d H:i:s', $now - self::FRAME_KEEP_SEC)]
        );
        foreach ($old as $r) {
            @unlink(self::framePath((int) $r['hotel_id'], (int) $r['device_id']));
            DB::query('DELETE FROM device_live_views WHERE id = :id', ['id' => (int) $r['id']]);
        }
        return count($old);
    }
}
