<?php
declare(strict_types=1);

/**
 * Web push alerts for events detected by polling (every minute): TVs that went offline (after the
 * hotel's notify_offline_minutes) and emergency broadcasts that were started. Each event is pushed
 * once: the task remembers the end of the window it checked (platform_staff_alerts_at).
 * Orders / requests are pushed directly by the guests module through StaffAlerts::send().
 */
final class StaffAlertsTask implements Task
{
    public function interval(): int
    {
        return 60;
    }

    public function run(): array
    {
        $now = time();
        $prev = (int) Settings::platform('platform_staff_alerts_at', '0');
        if ($prev <= 0 || $prev > $now || $now - $prev > 3600) {
            $prev = $now - 120; // first run / long pause: do not replay old events
        }
        $out = ['offline' => 0, 'emergency' => 0, 'sent' => 0];
        // Nobody subscribed → nothing to do (cheap exit for installs without push users).
        $hotels = array_map('intval', DB::column('SELECT DISTINCT u.hotel_id FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE u.hotel_id IS NOT NULL'));
        foreach ($hotels as $hid) {
            $r = Tenant::run($hid, static fn () => self::forHotel($hid, $prev, $now));
            foreach ($r as $k => $v) {
                $out[$k] += $v;
            }
        }
        Settings::setPlatform('platform_staff_alerts_at', (string) $now);
        return $out;
    }

    /** Alerts of one hotel for the window ]$prev, $now]. */
    public static function forHotel(int $hid, int $prev, int $now): array
    {
        $out = ['offline' => 0, 'emergency' => 0, 'sent' => 0];
        if (!Tenant::isActive($hid)) {
            return $out;
        }
        $mins = max(1, Settings::int('notify_offline_minutes', 5));
        $offline = DB::all(
            "SELECT d.id, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
             WHERE d.hotel_id = :h AND d.is_revoked = 0 AND d.room_id IS NOT NULL AND d.status = 'offline'
               AND d.last_ping < :to AND d.last_ping >= :from ORDER BY r.room_number",
            ['h' => $hid, 'to' => date('Y-m-d H:i:s', $now - $mins * 60), 'from' => date('Y-m-d H:i:s', $prev - $mins * 60)]
        );
        if ($offline) {
            $rooms = array_map(static fn ($d) => (string) ($d['room_number'] ?? '#' . $d['id']), $offline);
            $out['offline'] = count($offline);
            $out['sent'] += StaffAlerts::send(
                'rooms.view',
                __('TV offline') . ' · ' . (string) Settings::get('hotel_name', ''),
                __('Room(s): :r', ['r' => implode(', ', array_slice($rooms, 0, 20)) . (count($rooms) > 20 ? ' +' . (count($rooms) - 20) : '')]),
                'rooms.php?status=offline',
                'tv_offline'
            );
        }
        $emergencies = DB::all(
            'SELECT id, title FROM broadcast_commands WHERE hotel_id = :h AND is_emergency = 1 AND created_at > :from AND created_at <= :to',
            ['h' => $hid, 'from' => date('Y-m-d H:i:s', $prev), 'to' => date('Y-m-d H:i:s', $now)]
        );
        foreach ($emergencies as $em) {
            $out['emergency']++;
            $out['sent'] += StaffAlerts::send('dashboard.view', __('Emergency message on TVs'), (string) $em['title'], 'broadcast.php', 'emergency');
        }
        return $out;
    }
}
