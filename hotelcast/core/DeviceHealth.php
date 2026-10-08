<?php
declare(strict_types=1);

/**
 * TV health (2.4 #44, docs/modules/device_features.md). Every heartbeat may carry a `health`
 * object (storage, RAM, CPU temperature, Wi-Fi, uptime, app memory, resolution, Android version,
 * device owner, last crash, USB / CEC state). It is sanitised and stored in devices.health (JSON),
 * plus one row per TV per 10 minutes in device_health_history (kept 7 days) for the sparklines of
 * admin/tv_health.php. DeviceHealthTask reports new warnings through Notifier (email / WhatsApp,
 * like the offline alerts) and staff push, debounced per TV and warning.
 */
final class DeviceHealth
{
    public const HISTORY_EVERY = 600;          // one history row per TV per 10 minutes
    public const HISTORY_DAYS = 7;
    public const FRESH_SEC = 1800;             // alerts only from health younger than 30 minutes
    public const REPEAT_ALERT_SEC = 86400;     // a warning that stays is reported again after 24 h
    public const CLEARED_KEEP_SEC = 6 * 3600;  // a cleared warning that comes back within 6 h is not re-sent

    // Warning thresholds (also shown on the dashboard legend).
    public const STORAGE_MIN_MB = 500;
    public const RAM_MIN_MB = 150;
    public const RAM_MIN_PCT = 10;
    public const TEMP_MAX_C = 75;
    public const WIFI_MIN_DBM = -75;
    public const UPTIME_MAX_DAYS = 30;

    public const WARNINGS = ['storage_low', 'ram_low', 'temp_high', 'wifi_weak', 'uptime_long'];

    /** Integer fields: name => [min, max]. */
    private const INTS = [
        'storage_free_mb' => [0, 100000000], 'storage_total_mb' => [0, 100000000],
        'cache_free_mb' => [0, 100000000], 'cache_total_mb' => [0, 100000000],
        'ram_avail_mb' => [0, 10000000], 'ram_total_mb' => [0, 10000000], 'app_mem_mb' => [0, 10000000],
        'wifi_rssi' => [-127, 0], 'wifi_link_mbps' => [0, 100000], 'uptime_sec' => [0, 315360000],
        'sdk' => [1, 99], 'refresh_hz' => [0, 1000],
    ];
    private const BOOLS = ['ram_low', 'device_owner', 'live_view'];

    // ------------------------------------------------------------------ heartbeat

    /** Validate the heartbeat's `health` object. Unknown keys are dropped; never throws. */
    public static function sanitize(mixed $in): array
    {
        if (!is_array($in)) {
            return [];
        }
        $out = [];
        foreach (self::INTS as $k => [$min, $max]) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                $out[$k] = max($min, min($max, (int) round((float) $in[$k])));
            }
        }
        if (isset($in['cpu_temp_c']) && is_numeric($in['cpu_temp_c']) && (float) $in['cpu_temp_c'] > -40 && (float) $in['cpu_temp_c'] <= 150) {
            $out['cpu_temp_c'] = round((float) $in['cpu_temp_c'], 1);
        }
        foreach (self::BOOLS as $k) {
            if (array_key_exists($k, $in) && is_scalar($in[$k])) {
                $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($in['network']) && is_string($in['network']) && preg_match('/^[a-z_]{1,20}$/', $in['network'])) {
            $out['network'] = $in['network'];
        }
        foreach (['resolution', 'ui_resolution'] as $k) {
            if (isset($in[$k]) && is_string($in[$k]) && preg_match('/^\d{2,5}x\d{2,5}$/', $in[$k])) {
                $out[$k] = $in[$k];
            }
        }
        if (isset($in['android_version']) && is_scalar($in['android_version'])) {
            $v = mb_substr(trim(strip_tags((string) $in['android_version'])), 0, 20);
            if ($v !== '') {
                $out['android_version'] = $v;
            }
        }
        if (isset($in['last_crash_at']) && is_string($in['last_crash_at']) && ($ts = strtotime($in['last_crash_at'])) && $ts > 946684800 && $ts < time() + 86400) {
            $out['last_crash_at'] = date('Y-m-d H:i:s', $ts);
        }
        if (isset($in['usb']) && is_array($in['usb'])) {
            $u = $in['usb'];
            $out['usb'] = [
                'source' => in_array($u['source'] ?? null, ['server', 'usb'], true) ? $u['source'] : null,
                'folder' => !empty($u['folder']),
                'files' => is_numeric($u['files'] ?? null) ? max(0, min(100000, (int) $u['files'])) : 0,
                'permission' => !empty($u['permission']),
            ];
        }
        if (isset($in['cec']) && is_array($in['cec'])) {
            $c = $in['cec'];
            $out['cec'] = [
                'mode' => in_array($c['mode'] ?? null, DeviceFeatures::CEC_MODES, true) ? $c['mode'] : 'auto',
                'detected_box' => !empty($c['detected_box']),
                'box_mode' => !empty($c['box_mode']),
            ];
        }
        return $out;
    }

    /** Store the health of a heartbeat (device's hotel) + a history row at most every 10 minutes. */
    public static function record(array $device, mixed $in, ?int $now = null): void
    {
        $h = self::sanitize($in);
        if (!$h) {
            return;
        }
        $now ??= time();
        $hid = (int) $device['hotel_id'];
        DB::query(
            'UPDATE devices SET health = :j, health_at = :t WHERE id = :id AND hotel_id = :h',
            ['j' => json_out($h), 't' => date('Y-m-d H:i:s', $now), 'id' => (int) $device['id'], 'h' => $hid]
        );
        $last = DB::value('SELECT MAX(created_at) FROM device_health_history WHERE hotel_id = :h AND device_id = :d', ['h' => $hid, 'd' => (int) $device['id']]);
        if ($last && strtotime((string) $last) > $now - self::HISTORY_EVERY) {
            return;
        }
        DB::query(
            'INSERT INTO device_health_history (hotel_id, device_id, storage_free_mb, ram_avail_mb, ram_total_mb, cpu_temp_c, wifi_rssi, app_mem_mb, uptime_sec, created_at)
             VALUES (:h, :d, :s, :ra, :rt, :t, :w, :a, :u, :c)',
            [
                'h' => $hid, 'd' => (int) $device['id'], 's' => $h['storage_free_mb'] ?? null, 'ra' => $h['ram_avail_mb'] ?? null,
                'rt' => $h['ram_total_mb'] ?? null, 't' => $h['cpu_temp_c'] ?? null, 'w' => $h['wifi_rssi'] ?? null,
                'a' => $h['app_mem_mb'] ?? null, 'u' => isset($h['uptime_sec']) ? min(4294967295, $h['uptime_sec']) : null,
                'c' => date('Y-m-d H:i:s', $now),
            ]
        );
    }

    /** Decoded devices.health of a device row ([] when none). */
    public static function of(array $device): array
    {
        $h = json_decode((string) ($device['health'] ?? ''), true);
        return is_array($h) ? $h : [];
    }

    // ------------------------------------------------------------------ warnings

    /**
     * Warning keys of a health object. Pure. Wi-Fi falls back to the heartbeat's wifi_signal column.
     * @return array<string, string> key => short text ("320 MB free")
     */
    public static function warnings(array $h, ?array $device = null): array
    {
        $w = [];
        if (isset($h['storage_free_mb']) && $h['storage_free_mb'] < self::STORAGE_MIN_MB) {
            $w['storage_low'] = __(':n MB free', ['n' => $h['storage_free_mb']]);
        }
        $avail = $h['ram_avail_mb'] ?? null;
        $total = $h['ram_total_mb'] ?? null;
        if (!empty($h['ram_low']) || ($avail !== null && $avail < self::RAM_MIN_MB) || ($avail !== null && $total && $avail * 100 < $total * self::RAM_MIN_PCT)) {
            $w['ram_low'] = $avail !== null ? __(':n MB free', ['n' => $avail]) : __('Low memory');
        }
        if (isset($h['cpu_temp_c']) && $h['cpu_temp_c'] > self::TEMP_MAX_C) {
            $w['temp_high'] = $h['cpu_temp_c'] . ' °C';
        }
        $net = $h['network'] ?? ($device['network_type'] ?? null);
        $rssi = $h['wifi_rssi'] ?? (isset($device['wifi_signal']) && $device['wifi_signal'] !== null ? (int) $device['wifi_signal'] : null);
        if ($net === 'wifi' && $rssi !== null && $rssi < self::WIFI_MIN_DBM) {
            $w['wifi_weak'] = $rssi . ' dBm';
        }
        $up = $h['uptime_sec'] ?? (isset($device['uptime_sec']) && $device['uptime_sec'] !== null ? (int) $device['uptime_sec'] : null);
        if ($up !== null && $up > self::UPTIME_MAX_DAYS * 86400) {
            $w['uptime_long'] = __(':n days', ['n' => intdiv($up, 86400)]);
        }
        return $w;
    }

    public static function warningLabel(string $key): string
    {
        return match ($key) {
            'storage_low' => __('Storage low'),
            'ram_low' => __('Memory low'),
            'temp_high' => __('Too hot'),
            'wifi_weak' => __('Weak Wi-Fi'),
            'uptime_long' => __('Not restarted for a long time'),
            default => $key,
        };
    }

    /**
     * Debounce (pure): which of the current warnings must be reported now, and the new state.
     * State: key => unix time of the last report. Reported again after REPEAT_ALERT_SEC while it
     * stays; a cleared warning is remembered for CLEARED_KEEP_SEC so a flapping value is not re-sent.
     * @return array{0: string[], 1: array<string,int>}
     */
    public static function debounce(array $state, array $warningKeys, int $now): array
    {
        $new = [];
        $next = [];
        foreach ($warningKeys as $k) {
            $at = isset($state[$k]) ? (int) $state[$k] : 0;
            if ($at === 0 || ($now - $at >= self::REPEAT_ALERT_SEC) || ($now - $at >= self::CLEARED_KEEP_SEC && !isset($state[$k . ':on']))) {
                $new[] = $k;
                $at = $now;
            }
            $next[$k] = $at;
            $next[$k . ':on'] = 1;
        }
        foreach ($state as $k => $at) {
            if (str_ends_with((string) $k, ':on') || isset($next[$k])) {
                continue;
            }
            if ($now - (int) $at < self::CLEARED_KEEP_SEC) {
                $next[$k] = (int) $at; // cleared, remembered for a while (no ':on' marker)
            }
        }
        return [$new, $next];
    }

    /** Check the current hotel's online TVs and send debounced alerts. Returns number of TVs reported. */
    public static function checkAlerts(?int $now = null): int
    {
        $now ??= time();
        $enabled = Settings::get('notify_health', null);
        $enabled = $enabled === null || $enabled === '' ? Settings::bool('notify_offline') : in_array((string) $enabled, ['1', 'true', 'yes', 'on'], true);
        $rows = DB::all(
            "SELECT d.*, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
             WHERE d.hotel_id = :h AND d.is_revoked = 0 AND d.room_id IS NOT NULL AND d.health_at IS NOT NULL",
            ['h' => Tenant::id()]
        );
        $lines = [];
        foreach ($rows as $d) {
            $fresh = strtotime((string) $d['health_at']) >= $now - self::FRESH_SEC && $d['status'] === 'online';
            $warn = $fresh ? self::warnings(self::of($d), $d) : [];
            $state = json_decode((string) ($d['health_alerts'] ?? ''), true);
            $state = is_array($state) ? $state : [];
            if (!$fresh && !$state) {
                continue;
            }
            [$new, $next] = self::debounce($state, $fresh ? array_keys($warn) : [], $now);
            if ($next !== $state) {
                DB::query('UPDATE devices SET health_alerts = :s WHERE id = :id AND hotel_id = :h', ['s' => $next ? json_out($next) : null, 'id' => (int) $d['id'], 'h' => Tenant::id()]);
            }
            if ($new) {
                $lines[] = __('Screen :r', ['r' => $d['room_number'] ?? ('#' . $d['id'])]) . ': '
                    . implode(', ', array_map(static fn ($k) => self::warningLabel($k) . ' (' . $warn[$k] . ')', $new));
            }
        }
        if (!$lines) {
            return 0;
        }
        $title = __('TV health warning');
        $hotel = (string) Settings::get('hotel_name', Branding::DEFAULT_PRODUCT);
        if ($enabled) {
            Notifier::send(Branding::get()['product'] . ': ' . $title, '[' . $hotel . "]\n" . implode("\n", $lines));
        }
        StaffAlerts::send('tv_health.view', $title, implode("\n", array_slice($lines, 0, 5)) . (count($lines) > 5 ? "\n…" : ''), 'tv_health.php', 'tv_health');
        Logger::write('device', 'warning', 'TV health warning', ['hotel' => Tenant::id(), 'tvs' => count($lines)]);
        return count($lines);
    }

    // ------------------------------------------------------------------ history / dashboard

    /** History rows (oldest first) of the given devices (current hotel) for the last $hours. device_id => rows. */
    public static function history(array $deviceIds, int $hours = 24): array
    {
        $deviceIds = array_values(array_filter(array_map('intval', $deviceIds)));
        if (!$deviceIds) {
            return [];
        }
        [$in, $p] = DB::in($deviceIds, 'd');
        $out = [];
        foreach (DB::all(
            "SELECT device_id, storage_free_mb, ram_avail_mb, ram_total_mb, cpu_temp_c, wifi_rssi, app_mem_mb, uptime_sec, created_at
             FROM device_health_history WHERE hotel_id = :h AND device_id IN $in AND created_at >= :t ORDER BY created_at, id",
            $p + ['h' => Tenant::id(), 't' => date('Y-m-d H:i:s', time() - max(1, min(24 * self::HISTORY_DAYS, $hours)) * 3600)]
        ) as $r) {
            $out[(int) $r['device_id']][] = $r;
        }
        return $out;
    }

    /**
     * Small inline SVG sparkline (pure, escaped). Nulls are skipped; $warnAbove / $warnBelow draw the
     * last point red when outside the limit.
     */
    public static function sparkline(array $values, string $label, ?float $warnAbove = null, ?float $warnBelow = null, int $w = 110, int $h = 26): string
    {
        $vals = array_values(array_filter($values, static fn ($v) => $v !== null && is_numeric($v)));
        $vals = array_map('floatval', $vals);
        if (count($vals) < 2) {
            return '<span class="text-muted small">–</span>';
        }
        $min = min($vals);
        $max = max($vals);
        $span = $max - $min ?: 1.0;
        $n = count($vals);
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = round($i * ($w - 4) / ($n - 1) + 2, 1) . ',' . round($h - 3 - ($v - $min) * ($h - 6) / $span, 1);
        }
        $last = end($vals);
        $bad = ($warnAbove !== null && $last > $warnAbove) || ($warnBelow !== null && $last < $warnBelow);
        [$lx, $ly] = explode(',', (string) end($pts));
        $title = e($label . ': ' . self::fmt($min) . ' – ' . self::fmt($max) . ' (' . __('now :v', ['v' => self::fmt($last)]) . ')');
        return '<svg class="hc-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $title . '"><title>' . $title . '</title>'
            . '<polyline fill="none" stroke="' . ($bad ? '#dc3545' : '#0d6efd') . '" stroke-width="1.5" points="' . implode(' ', $pts) . '"/>'
            . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2" fill="' . ($bad ? '#dc3545' : '#0d6efd') . '"/></svg>';
    }

    private static function fmt(float $v): string
    {
        return fmod($v, 1.0) === 0.0 ? (string) (int) $v : number_format($v, 1);
    }

    /** Delete history older than 7 days (all hotels). Returns rows deleted. */
    public static function pruneHistory(?int $now = null): int
    {
        $now ??= time();
        return DB::query('DELETE FROM device_health_history WHERE created_at < :t', ['t' => date('Y-m-d H:i:s', $now - self::HISTORY_DAYS * 86400)])->rowCount();
    }
}
