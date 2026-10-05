<?php
declare(strict_types=1);

/**
 * Analytics (V2_SPEC §3, #17) for the current hotel and a date range (Y-m-d, inclusive).
 *
 * Sources:
 *  - Plays / screen time: broadcast_logs (event "played", reported by the TVs) — kept for the
 *    log retention period (setting log_retention_days).
 *  - TV uptime %: device_status_logs (online/offline transitions) + devices.last_ping.
 *  - Hours ON: tv_usage_daily, sampled every 5 minutes by AnalyticsTask from the TV heartbeat
 *    (devices.screen_on) while the TV is online. TVs without samples fall back to their online hours.
 *  - Electricity: hours ON × setting tv_watts (default 100 W) / 1000 = kWh.
 *  - Occupancy / guest services: tables of the guests module (only when they exist).
 */
final class Analytics
{
    public const DEFAULT_WATTS = 100;
    public const MAX_DAYS = 366;

    /** Normalise a date range from user input. Default: the last 7 days including today. */
    public static function range(mixed $from, mixed $to): array
    {
        $to = Ads::date($to) ?? date('Y-m-d');
        $from = Ads::date($from) ?? date('Y-m-d', strtotime($to . ' -6 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
            $from = date('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' days'));
        }
        return [$from, $to];
    }

    public static function days(string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[] = $d;
        }
        return $out;
    }

    private static function bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    }

    public static function watts(): int
    {
        return max(1, min(2000, Settings::int('tv_watts', self::DEFAULT_WATTS)));
    }

    // ------------------------------------------------------------------ plays

    /** Plays & screen time per content item (ads included, flagged), most played first. */
    public static function byContent(string $from, string $to, int $limit = 500): array
    {
        [$f, $t] = self::bounds($from, $to);
        return array_map(static fn ($r) => [
            'content_id' => (int) $r['content_id'],
            'title' => $r['title'] ?? ('#' . $r['content_id'] . ' (' . __('deleted') . ')'),
            'type' => (string) ($r['type'] ?? ''),
            'plays' => (int) $r['plays'],
            'seconds' => (int) $r['seconds'],
            'rooms' => (int) $r['rooms'],
            'ad_plays' => (int) $r['ad_plays'],
        ], DB::all(
            "SELECT l.content_id, c.title, c.type, COUNT(*) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds,
                    COUNT(DISTINCT l.room_id) AS rooms, SUM(l.ad_campaign_id IS NOT NULL) AS ad_plays
             FROM broadcast_logs l LEFT JOIN content_items c ON c.id = l.content_id AND c.hotel_id = l.hotel_id
             WHERE l.hotel_id = :h AND l.event = 'played' AND l.content_id IS NOT NULL AND l.created_at >= :f AND l.created_at < :t
             GROUP BY l.content_id, c.title, c.type ORDER BY plays DESC, seconds DESC LIMIT " . max(1, min(5000, $limit)),
            ['h' => Tenant::id(), 'f' => $f, 't' => $t]
        ));
    }

    /** Plays & screen time per room (every room of the hotel, also those without plays). */
    public static function byRoom(string $from, string $to): array
    {
        [$f, $t] = self::bounds($from, $to);
        $rows = DB::all(
            "SELECT r.id, r.room_number, r.floor, COUNT(l.id) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds,
                    COUNT(DISTINCT l.content_id) AS items
             FROM rooms r LEFT JOIN broadcast_logs l ON l.room_id = r.id AND l.hotel_id = r.hotel_id AND l.event = 'played'
                  AND l.created_at >= :f AND l.created_at < :t
             WHERE r.hotel_id = :h GROUP BY r.id, r.room_number, r.floor",
            ['h' => Tenant::id(), 'f' => $f, 't' => $t]
        );
        usort($rows, fn ($a, $b) => strnatcmp((string) $a['room_number'], (string) $b['room_number']));
        return array_map(static fn ($r) => [
            'room_id' => (int) $r['id'], 'room' => (string) $r['room_number'], 'floor' => (string) ($r['floor'] ?? ''),
            'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'], 'items' => (int) $r['items'],
        ], $rows);
    }

    /** Plays, screen time and ad impressions per day (every day of the range). */
    public static function byDay(string $from, string $to): array
    {
        [$f, $t] = self::bounds($from, $to);
        $out = [];
        foreach (self::days($from, $to) as $d) {
            $out[$d] = ['day' => $d, 'plays' => 0, 'seconds' => 0, 'ad_impressions' => 0, 'rooms' => 0];
        }
        foreach (DB::all(
            "SELECT DATE(created_at) AS d, COUNT(*) AS plays, COALESCE(SUM(duration_sec), 0) AS seconds,
                    SUM(ad_campaign_id IS NOT NULL) AS ads, COUNT(DISTINCT room_id) AS rooms
             FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND created_at >= :f AND created_at < :t
             GROUP BY DATE(created_at)",
            ['h' => Tenant::id(), 'f' => $f, 't' => $t]
        ) as $r) {
            $d = (string) $r['d'];
            if (isset($out[$d])) {
                $out[$d] = ['day' => $d, 'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'], 'ad_impressions' => (int) $r['ads'], 'rooms' => (int) $r['rooms']];
            }
        }
        return array_values($out);
    }

    // ------------------------------------------------------------------ TVs

    /**
     * Seconds a device was online in [$start, $end) (unix timestamps), from its status transitions.
     * State before the first transition in the window = last transition before the window; without
     * one, the opposite of the first transition inside the window (offline when there is none).
     * An "online" device whose last ping is older than offline_after counts as online until that ping.
     */
    public static function onlineSeconds(array $device, int $start, int $end, ?array $logs = null, ?string $before = null): int
    {
        if ($end <= $start) {
            return 0;
        }
        $hid = Tenant::id();
        $logs ??= DB::all(
            'SELECT status, created_at FROM device_status_logs WHERE hotel_id = :h AND device_id = :d AND created_at >= :f AND created_at < :t ORDER BY created_at, id',
            ['h' => $hid, 'd' => $device['id'], 'f' => date('Y-m-d H:i:s', $start), 't' => date('Y-m-d H:i:s', $end)]
        );
        $before ??= (string) (DB::value(
            'SELECT status FROM device_status_logs WHERE hotel_id = :h AND device_id = :d AND created_at < :f ORDER BY created_at DESC, id DESC LIMIT 1',
            ['h' => $hid, 'd' => $device['id'], 'f' => date('Y-m-d H:i:s', $start)]
        ) ?? '');
        $online = $before !== '' ? $before === 'online' : ($logs && $logs[0]['status'] === 'offline');
        $cursor = $start;
        $total = 0;
        foreach ($logs as $l) {
            $ts = max($start, min($end, (int) strtotime((string) $l['created_at'])));
            if ($online) {
                $total += $ts - $cursor;
            }
            $cursor = $ts;
            $online = $l['status'] === 'online';
        }
        if ($online) {
            $stop = $end;
            // Still marked online but silent (offline detection not run yet): count until the last ping.
            if ($end >= time() - 1 && !empty($device['last_ping'])) {
                $limit = max(30, Settings::int('offline_after', 90));
                $ping = (int) strtotime((string) $device['last_ping']);
                if ($ping < time() - $limit) {
                    $stop = max($cursor, min($end, $ping));
                }
            }
            $total += max(0, $stop - $cursor);
        }
        return max(0, $total);
    }

    /**
     * Per TV: uptime %, online hours, hours ON (screen on), kWh and the method used for hours ON.
     * The period of a TV starts at its registration (if inside the range) and ends now (if today).
     */
    public static function byTv(string $from, string $to): array
    {
        $hid = Tenant::id();
        [$f, $t] = self::bounds($from, $to);
        $rangeStart = (int) strtotime($f);
        $rangeEnd = min((int) strtotime($t), time());
        $devices = DB::all(
            'SELECT d.*, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
             WHERE d.hotel_id = :h AND d.is_revoked = 0 ORDER BY r.room_number, d.id',
            ['h' => $hid]
        );
        $usage = [];
        foreach (DB::all(
            'SELECT device_id, SUM(online_min) AS online_min, SUM(screen_on_min) AS screen_min, SUM(samples) AS samples
             FROM tv_usage_daily WHERE hotel_id = :h AND day BETWEEN :f AND :t GROUP BY device_id',
            ['h' => $hid, 'f' => $from, 't' => $to]
        ) as $u) {
            $usage[(int) $u['device_id']] = $u;
        }
        $watts = self::watts();
        $out = [];
        foreach ($devices as $d) {
            $start = max($rangeStart, (int) strtotime((string) $d['registered_at']));
            $period = max(0, $rangeEnd - $start);
            $online = $period > 0 ? self::onlineSeconds($d, $start, $rangeEnd) : 0;
            $u = $usage[(int) $d['id']] ?? null;
            $sampled = $u && (int) $u['samples'] > 0;
            $hoursOn = $sampled ? (int) $u['screen_min'] / 60 : $online / 3600;
            $out[] = [
                'device_id' => (int) $d['id'],
                'room' => (string) ($d['room_number'] ?? '-'),
                'model' => (string) ($d['model'] ?? ''),
                'status' => DeviceManager::isOnline($d) ? 'online' : 'offline',
                'period_hours' => round($period / 3600, 1),
                'online_hours' => round($online / 3600, 1),
                'uptime' => $period > 0 ? round(100 * $online / $period, 1) : null,
                'hours_on' => round($hoursOn, 1),
                'kwh' => round($hoursOn * $watts / 1000, 2),
                'method' => $sampled ? 'samples' : 'status',
                '_online' => $online,
                '_period' => $period,
            ];
        }
        return $out;
    }

    /** Hotel-wide summary of byTv(). */
    public static function tvSummary(array $tvs): array
    {
        $online = array_sum(array_column($tvs, '_online'));
        $period = array_sum(array_column($tvs, '_period'));
        return [
            'tvs' => count($tvs),
            'uptime' => $period > 0 ? round(100 * $online / $period, 1) : null,
            'hours_on' => round(array_sum(array_column($tvs, 'hours_on')), 1),
            'kwh' => round(array_sum(array_column($tvs, 'kwh')), 2),
        ];
    }

    /** Remove internal keys before output / CSV. */
    public static function publicTvRows(array $tvs): array
    {
        return array_map(fn ($r) => array_diff_key($r, ['_online' => 1, '_period' => 1]), $tvs);
    }

    /**
     * Sample every TV of the current hotel (AnalyticsTask): add the minutes since the previous sample
     * (max 15) to today's online / screen-on counters. Returns the number of TVs sampled.
     */
    public static function sampleUsage(?int $now = null): int
    {
        $now ??= time();
        $hid = Tenant::id();
        $day = date('Y-m-d', $now);
        $hb = max(15, Settings::int('heartbeat_interval', 60));
        $n = 0;
        foreach (DB::all('SELECT * FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL', ['h' => $hid]) as $d) {
            $last = DB::value('SELECT MAX(last_sample_at) FROM tv_usage_daily WHERE hotel_id = :h AND device_id = :d', ['h' => $hid, 'd' => $d['id']]);
            $mins = $last ? (int) round(($now - (int) strtotime((string) $last)) / 60) : 5;
            $mins = max(0, min(15, $mins));
            $online = DeviceManager::isOnline($d);
            // Screen state comes from the last heartbeat; a TV that stopped sending heartbeats counts as on
            // while it still polls (older app versions without heartbeats).
            $screen = $online && ((int) $d['screen_on'] === 1 || empty($d['last_heartbeat']) || strtotime((string) $d['last_heartbeat']) < $now - 3 * $hb);
            DB::query(
                'INSERT INTO tv_usage_daily (hotel_id, device_id, day, online_min, screen_on_min, samples, last_sample_at)
                 VALUES (:h, :d, :day, :o, :s, 1, :at)
                 ON DUPLICATE KEY UPDATE online_min = online_min + VALUES(online_min), screen_on_min = screen_on_min + VALUES(screen_on_min),
                                         samples = samples + 1, last_sample_at = VALUES(last_sample_at)',
                ['h' => $hid, 'd' => $d['id'], 'day' => $day, 'o' => $online ? $mins : 0, 's' => $screen ? $mins : 0, 'at' => date('Y-m-d H:i:s', $now)]
            );
            $n++;
        }
        DB::query('DELETE FROM tv_usage_daily WHERE hotel_id = :h AND day < :c', ['h' => $hid, 'c' => date('Y-m-d', strtotime('-400 days', $now))]);
        return $n;
    }

    // ------------------------------------------------------------------ guests module (optional)

    public static function tableExists(string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = (bool) DB::value(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table]
            );
        }
        return $cache[$table];
    }

    /** Occupancy per day (% of rooms with a stay that night) — null when the guests module is missing. */
    public static function occupancy(string $from, string $to): ?array
    {
        if (!self::tableExists('guest_stays')) {
            return null;
        }
        try {
            $hid = Tenant::id();
            $rooms = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]);
            [$f, $t] = self::bounds($from, $to);
            $stays = DB::all(
                'SELECT room_id, checkin_at, checked_out_at FROM guest_stays
                 WHERE hotel_id = :h AND room_id IS NOT NULL AND checkin_at < :t AND (checked_out_at IS NULL OR checked_out_at >= :f)',
                ['h' => $hid, 'f' => $f, 't' => $t]
            );
            $days = [];
            $sum = 0.0;
            foreach (self::days($from, $to) as $d) {
                // A room counts as occupied on day D when a stay covers D 18:00 (the "night").
                $night = strtotime($d . ' 18:00:00');
                $occ = [];
                foreach ($stays as $s) {
                    $in = strtotime((string) $s['checkin_at']);
                    $out = $s['checked_out_at'] ? strtotime((string) $s['checked_out_at']) : PHP_INT_MAX;
                    if ($in <= $night && $out > $night) {
                        $occ[(int) $s['room_id']] = true;
                    }
                }
                $pct = $rooms > 0 ? round(100 * count($occ) / $rooms, 1) : 0.0;
                $days[] = ['day' => $d, 'occupied' => count($occ), 'rooms' => $rooms, 'percent' => $pct];
                $sum += $pct;
            }
            return ['days' => $days, 'average' => $days ? round($sum / count($days), 1) : 0.0];
        } catch (Throwable $e) {
            Logger::error('analytics occupancy failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Orders, delivery time, requests by type, feedback — null when the guest services tables are missing. */
    public static function guestServices(string $from, string $to): ?array
    {
        if (!self::tableExists('guest_orders') && !self::tableExists('guest_requests') && !self::tableExists('guest_feedback')) {
            return null;
        }
        $hid = Tenant::id();
        [$f, $t] = self::bounds($from, $to);
        $p = ['h' => $hid, 'f' => $f, 't' => $t];
        $out = ['orders' => null, 'requests' => null, 'feedback' => null];
        try {
            if (self::tableExists('guest_orders')) {
                $o = DB::one(
                    "SELECT COUNT(*) AS n, SUM(status = 'delivered') AS delivered, SUM(status = 'cancelled') AS cancelled,
                            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END), 0) AS revenue,
                            AVG(CASE WHEN status = 'delivered' AND delivered_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, delivered_at) END) AS avg_sec
                     FROM guest_orders WHERE hotel_id = :h AND created_at >= :f AND created_at < :t",
                    $p
                );
                $out['orders'] = [
                    'count' => (int) $o['n'], 'delivered' => (int) $o['delivered'], 'cancelled' => (int) $o['cancelled'],
                    'revenue' => round((float) $o['revenue'], 2),
                    'avg_delivery_min' => $o['avg_sec'] !== null ? round((float) $o['avg_sec'] / 60, 1) : null,
                ];
            }
            if (self::tableExists('guest_requests')) {
                $out['requests'] = array_map(static fn ($r) => [
                    'type' => (string) $r['type_name'], 'count' => (int) $r['n'], 'done' => (int) $r['done'],
                    'avg_done_min' => $r['avg_sec'] !== null ? round((float) $r['avg_sec'] / 60, 1) : null,
                ], DB::all(
                    "SELECT type_name, COUNT(*) AS n, SUM(status = 'done') AS done,
                            AVG(CASE WHEN status = 'done' AND done_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, done_at) END) AS avg_sec
                     FROM guest_requests WHERE hotel_id = :h AND created_at >= :f AND created_at < :t
                     GROUP BY type_name ORDER BY n DESC",
                    $p
                ));
            }
            if (self::tableExists('guest_feedback')) {
                $fb = DB::one(
                    'SELECT COUNT(*) AS n, AVG(rating) AS avg_rating, AVG(rating_cleanliness) AS c, AVG(rating_staff) AS s, AVG(rating_food) AS fo
                     FROM guest_feedback WHERE hotel_id = :h AND created_at >= :f AND created_at < :t',
                    $p
                );
                $r = static fn ($v) => $v !== null ? round((float) $v, 2) : null;
                $out['feedback'] = ['count' => (int) $fb['n'], 'average' => $r($fb['avg_rating']), 'cleanliness' => $r($fb['c']), 'staff' => $r($fb['s']), 'food' => $r($fb['fo'])];
            }
        } catch (Throwable $e) {
            Logger::error('analytics guest services failed: ' . $e->getMessage());
        }
        return $out;
    }

    // ------------------------------------------------------------------ dashboard

    /** Today's plays, TV uptime % since midnight and ad impressions. */
    public static function today(): array
    {
        $d = date('Y-m-d');
        $plays = (int) DB::value(
            "SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND created_at >= :f",
            ['h' => Tenant::id(), 'f' => $d . ' 00:00:00']
        );
        $tvs = self::byTv($d, $d);
        return ['plays' => $plays, 'uptime' => self::tvSummary($tvs)['uptime'], 'ad_impressions' => Ads::impressionsTodayTotal(), 'tvs' => count($tvs)];
    }

    /** "3h 05m" helper for the UI. */
    public static function hm(int $seconds): string
    {
        return Ads::duration($seconds);
    }
}
