<?php
declare(strict_types=1);

/**
 * Periodic maintenance. Runs from cron.php if a cron job exists, otherwise lazily
 * from TV polls and admin page loads (at most once every 20 seconds).
 */
final class Scheduler
{
    private const MIN_INTERVAL = 20;

    public static function tick(bool $force = false): array
    {
        if (!$force) {
            $last = Settings::int('last_tick', 0);
            if (time() - $last < self::MIN_INTERVAL) {
                return [];
            }
            // Atomic claim: only one request runs the tick.
            $claimed = DB::query(
                "UPDATE system_settings SET setting_value = :now WHERE setting_key = 'last_tick' AND CAST(setting_value AS UNSIGNED) = :last",
                ['now' => (string) time(), 'last' => $last]
            )->rowCount();
            if ($claimed !== 1) {
                if (DB::value("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'last_tick'") == 0) {
                    Settings::set('last_tick', (string) time());
                } else {
                    return [];
                }
            }
        } else {
            Settings::set('last_tick', (string) time());
        }

        $result = [];
        try {
            $result['schedules'] = Broadcaster::processSchedules();
            $result['offline'] = count(DeviceManager::detectOffline());
            $result['notified'] = Notifier::checkOffline();

            DB::query(
                "UPDATE device_commands SET status = 'expired' WHERE status IN ('pending','delivered') AND created_at < :t",
                ['t' => date('Y-m-d H:i:s', time() - 86400)]
            );

            // Hourly housekeeping.
            if ($force || (int) date('i') === 0 || mt_rand(1, 180) === 1) {
                self::housekeeping();
            }
        } catch (Throwable $e) {
            Logger::error('Scheduler tick failed: ' . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        return $result;
    }

    public static function housekeeping(): void
    {
        $days = max(7, Settings::int('log_retention_days', 90));
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        DB::query('DELETE FROM broadcast_logs WHERE created_at < :c', ['c' => $cutoff]);
        DB::query('DELETE FROM device_status_logs WHERE created_at < :c', ['c' => $cutoff]);
        DB::query('DELETE FROM activity_logs WHERE created_at < :c', ['c' => $cutoff]);
        DB::query('DELETE FROM login_attempts WHERE created_at < :c', ['c' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        DB::query("DELETE FROM device_commands WHERE status IN ('acked','expired','failed') AND created_at < :c", ['c' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        DB::query('DELETE FROM user_sessions WHERE expires_at < :c OR revoked = 1', ['c' => now()]);
        RateLimiter::cleanup();
    }
}
