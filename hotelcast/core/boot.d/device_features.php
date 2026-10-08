<?php
/**
 * 2.4 device features (docs/modules/device_features.md): TV health history and live view sessions
 * (migrations/025_device_features.sql), the TV health dashboard permission and its staff alert type.
 * Live view uses support.view (manager+); the "Announce" box on the broadcast page uses announce.send
 * (staff+, the same permission as the device-schedules "Announce now").
 */
declare(strict_types=1);

Tenant::registerTable('device_health_history');
Tenant::registerTable('device_live_views');

// TV health dashboard (admin/tv_health.php): every hotel role may look; limited users see their TVs only.
Auth::registerPermission('tv_health.view', 'reception');
// Same rule as core/boot.d/device_schedules.php (registered here too so the broadcast page works alone).
Auth::registerPermission('announce.send', 'staff');

StaffAlerts::registerType('tv_health', 'TV health warnings', 'tv_health.view');
