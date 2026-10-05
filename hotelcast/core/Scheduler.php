<?php
declare(strict_types=1);

/**
 * Periodic maintenance. Runs from cron.php if a cron job exists, otherwise lazily
 * from TV polls and admin page loads (at most once every 20 seconds).
 *
 * Per-hotel work (schedules, offline detection, notifications) runs inside each hotel's context
 * (Tenant::each). Afterwards every task in core/Tasks/*.php (interface Task) runs when due.
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
                "UPDATE system_settings SET setting_value = :now WHERE hotel_id = 0 AND setting_key = 'last_tick' AND CAST(setting_value AS UNSIGNED) = :last",
                ['now' => (string) time(), 'last' => $last]
            )->rowCount();
            if ($claimed !== 1) {
                if (DB::value("SELECT COUNT(*) FROM system_settings WHERE hotel_id = 0 AND setting_key = 'last_tick'") == 0) {
                    Settings::setPlatform('last_tick', (string) time());
                } else {
                    return [];
                }
            }
        } else {
            Settings::setPlatform('last_tick', (string) time());
        }

        $result = ['schedules' => ['pushed' => 0, 'activated' => 0, 'ended' => 0], 'offline' => 0, 'notified' => 0];
        $prevTenant = Tenant::current();
        try {
            foreach (Tenant::each(static fn () => [
                'schedules' => Broadcaster::processSchedules(),
                'offline' => count(DeviceManager::detectOffline()),
                'notified' => Notifier::checkOffline(),
            ]) as $r) {
                foreach ((array) ($r['schedules'] ?? []) as $k => $v) {
                    $result['schedules'][$k] = ($result['schedules'][$k] ?? 0) + (int) $v;
                }
                $result['offline'] += (int) ($r['offline'] ?? 0);
                $result['notified'] += (int) ($r['notified'] ?? 0);
            }

            DB::query(
                "UPDATE device_commands SET status = 'expired' WHERE status IN ('pending','delivered') AND created_at < :t",
                ['t' => date('Y-m-d H:i:s', time() - 86400)]
            );

            // Hourly housekeeping.
            if ($force || (int) date('i') === 0 || mt_rand(1, 180) === 1) {
                self::housekeeping();
            }
            // Tasks keep their own interval even on a forced (cron) tick.
            $result['tasks'] = self::runTasks();
        } catch (Throwable $e) {
            Logger::error('Scheduler tick failed: ' . $e->getMessage());
            $result['error'] = $e->getMessage();
        } finally {
            Tenant::set($prevTenant);
        }
        return $result;
    }

    /** Maintenance of the current hotel only (schedules, offline TVs, notifications). */
    public static function tickHotel(): array
    {
        return [
            'schedules' => Broadcaster::processSchedules(),
            'offline' => count(DeviceManager::detectOffline()),
            'notified' => Notifier::checkOffline(),
        ];
    }

    /** Instances of every core/Tasks/*.php class implementing Task (sorted by file name). */
    public static function tasks(): array
    {
        $out = [];
        $files = glob(HC_CORE . '/Tasks/*.php') ?: [];
        sort($files);
        foreach ($files as $f) {
            $class = basename($f, '.php');
            if (!class_exists($class, false)) {
                require_once $f;
            }
            if (class_exists($class, false) && is_subclass_of($class, 'Task')) {
                $out[$class] = new $class();
            }
        }
        return $out;
    }

    /** Run due tasks (or all of them with $force). Returns [TaskName => summary]. */
    public static function runTasks(bool $force = false): array
    {
        $out = [];
        $prevTenant = Tenant::current();
        foreach (self::tasks() as $name => $task) {
            $key = 'task_last_' . $name;
            $last = (int) Settings::platform($key, '0');
            if (!$force && time() - $last < max(60, $task->interval())) {
                continue;
            }
            // Claim the run first so parallel ticks don't run a task twice.
            Settings::setPlatform($key, (string) time());
            Tenant::clear();
            try {
                $out[$name] = $task->run();
            } catch (Throwable $e) {
                Logger::error('Task ' . $name . ' failed: ' . $e->getMessage());
                $out[$name] = ['error' => $e->getMessage()];
            } finally {
                Tenant::set($prevTenant);
            }
        }
        return $out;
    }

    public static function housekeeping(): void
    {
        // Log retention is a hotel setting.
        Tenant::each(static function (int $hid): void {
            $days = max(7, Settings::int('log_retention_days', 90));
            $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
            DB::query('DELETE FROM broadcast_logs WHERE hotel_id = :h AND created_at < :c', ['c' => $cutoff, 'h' => $hid]);
            DB::query('DELETE FROM device_status_logs WHERE hotel_id = :h AND created_at < :c', ['c' => $cutoff, 'h' => $hid]);
            DB::query('DELETE FROM activity_logs WHERE hotel_id = :h AND created_at < :c', ['c' => $cutoff, 'h' => $hid]);
        });
        DB::query('DELETE FROM activity_logs WHERE hotel_id IS NULL AND created_at < :c', ['c' => date('Y-m-d H:i:s', time() - 365 * 86400)]);
        DB::query('DELETE FROM login_attempts WHERE created_at < :c', ['c' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        DB::query("DELETE FROM device_commands WHERE status IN ('acked','expired','failed') AND created_at < :c", ['c' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        DB::query('DELETE FROM user_sessions WHERE expires_at < :c OR revoked = 1', ['c' => now()]);
        RateLimiter::cleanup();
    }
}
