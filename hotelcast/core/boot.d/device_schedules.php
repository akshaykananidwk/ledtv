<?php
/**
 * 2.4: device schedules (docs/modules/device_schedules.md) — timed TV actions (volume / input / restart /
 * bell / spoken announcement), sound library, presence sensors (POST /api/presence) and the proof of play
 * report. Tables from migrations/023_device_schedules.sql; firing by core/Tasks/DeviceSchedulesTask.php.
 */
declare(strict_types=1);

foreach (['device_schedules', 'device_schedule_runs', 'sounds', 'presence_sensors'] as $__t) {
    Tenant::registerTable($__t);
}
unset($__t);

// Schedules, sound library and presence sensors: manager and up (admin/device_schedules.php).
Auth::registerPermission('device_schedules.manage', 'manager');
// "Announce now" (spoken announcement to chosen TVs): staff and up, limited to their TVs (Access).
Auth::registerPermission('announce.send', 'staff');
// Proof of play report (admin/play_report.php): manager and up; limited users see only their TVs.
Auth::registerPermission('play_report.view', 'manager');
