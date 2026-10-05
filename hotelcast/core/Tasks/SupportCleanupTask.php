<?php
declare(strict_types=1);

/**
 * Daily housekeeping of the support / push module: TV events older than the hotel's
 * log_retention_days, support files older than support_retention_days (default 30), and push
 * subscriptions that kept failing (≥ 20 failures) or were unused for 180 days.
 */
final class SupportCleanupTask implements Task
{
    public function interval(): int
    {
        return 86400;
    }

    public function run(): array
    {
        $out = ['events' => 0, 'files' => 0, 'subscriptions' => 0];
        Tenant::each(static function (int $hid) use (&$out): void {
            $days = max(7, Settings::int('log_retention_days', 90));
            $out['events'] += DB::query('DELETE FROM device_events WHERE hotel_id = :h AND created_at < :c', ['h' => $hid, 'c' => date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
            $out['files'] += DeviceSupport::purgeOlderThan(max(1, Settings::int('support_retention_days', 30)));
        });
        $out['subscriptions'] = DB::query(
            'DELETE FROM push_subscriptions WHERE failures >= 20 OR COALESCE(last_used, created_at) < :c',
            ['c' => date('Y-m-d H:i:s', time() - 180 * 86400)]
        )->rowCount();
        return $out;
    }
}
