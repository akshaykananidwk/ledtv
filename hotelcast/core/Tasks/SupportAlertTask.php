<?php
declare(strict_types=1);

/**
 * Crash spike alerts (#24): every 15 minutes, hotels whose TVs sent at least
 * platform_crash_alert_threshold crash reports (default 5) in the last hour are reported to the
 * platform admins (web push + email / WhatsApp, StaffAlerts::sendPlatform) and to the hotel's
 * managers (web push, alert type "support"). A hotel is reported at most once every 6 hours.
 */
final class SupportAlertTask implements Task
{
    public const REPEAT_AFTER = 6 * 3600;

    public function interval(): int
    {
        return 900;
    }

    public static function threshold(): int
    {
        return max(1, (int) Settings::platform('platform_crash_alert_threshold', '5'));
    }

    public function run(): array
    {
        $now = time();
        $spikes = DB::all(
            "SELECT f.hotel_id, h.name, COUNT(*) AS crashes, COUNT(DISTINCT f.device_id) AS tvs
             FROM device_support_files f JOIN hotels h ON h.id = f.hotel_id
             WHERE f.kind = 'crash' AND f.created_at >= :since
             GROUP BY f.hotel_id, h.name HAVING COUNT(*) >= :n ORDER BY crashes DESC",
            ['since' => date('Y-m-d H:i:s', $now - 3600), 'n' => self::threshold()]
        );
        $state = json_decode((string) Settings::platform('platform_crash_alert_state', ''), true);
        $state = is_array($state) ? array_filter($state, static fn ($ts) => (int) $ts > $now - self::REPEAT_AFTER) : [];
        $new = array_values(array_filter($spikes, static fn ($s) => !isset($state[(string) $s['hotel_id']])));
        if (!$new) {
            Settings::setPlatform('platform_crash_alert_state', json_out((object) $state));
            return ['spikes' => count($spikes), 'alerted' => 0];
        }
        $lines = array_map(static fn ($s) => sprintf('%s: %d crashes on %d TV(s)', $s['name'], (int) $s['crashes'], (int) $s['tvs']), $new);
        StaffAlerts::sendPlatform(__('TV crash spike'), implode("\n", $lines), 'platform_support.php');
        foreach ($new as $s) {
            $state[(string) $s['hotel_id']] = $now;
            Tenant::run((int) $s['hotel_id'], static fn () => StaffAlerts::send(
                'support.view',
                __('TV crash spike'),
                __(':n crash reports from :t TV(s) in the last hour.', ['n' => (int) $s['crashes'], 't' => (int) $s['tvs']]),
                'support.php',
                'support'
            ));
        }
        Settings::setPlatform('platform_crash_alert_state', json_out((object) $state));
        Logger::write('support', 'warning', 'Crash spike alert', ['hotels' => array_map(static fn ($s) => (int) $s['hotel_id'], $new)]);
        return ['spikes' => count($spikes), 'alerted' => count($new)];
    }
}
