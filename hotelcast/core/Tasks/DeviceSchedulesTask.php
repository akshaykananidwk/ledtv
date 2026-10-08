<?php
declare(strict_types=1);

/**
 * Device schedules (2.4): every minute, for every active hotel (in its time zone), fire due timed actions
 * (volume / input / restart / bell / announcement, DeviceSchedules::tick — each occurrence exactly once),
 * send staggered restarts whose time has come and switch off TVs of presence sensors without motion
 * (Presence::idleTick).
 */
final class DeviceSchedulesTask implements Task
{
    public function interval(): int
    {
        return 60;
    }

    public function run(): array
    {
        $out = ['fired' => 0, 'tvs' => 0, 'restarts' => 0, 'skipped' => 0, 'idle_off' => 0];
        // Cheap exit for installs without any schedule / sensor.
        $hotels = array_map('intval', DB::column(
            'SELECT hotel_id FROM device_schedules WHERE is_active = 1
             UNION SELECT hotel_id FROM device_schedule_runs WHERE status = \'planned\'
             UNION SELECT hotel_id FROM presence_sensors WHERE is_active = 1'
        ));
        foreach ($hotels as $hid) {
            if (!Tenant::isActive($hid)) {
                continue;
            }
            try {
                $r = Tenant::run($hid, static fn (): array => DeviceSchedules::tick() + ['idle_off' => Presence::idleTick()]);
            } catch (Throwable $e) {
                Logger::error('Device schedules for hotel ' . $hid . ' failed: ' . $e->getMessage());
                continue;
            }
            foreach ($out as $k => $v) {
                $out[$k] = $v + (int) ($r[$k] ?? 0);
            }
        }
        return $out;
    }
}
