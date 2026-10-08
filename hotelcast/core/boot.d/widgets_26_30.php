<?php
/**
 * 2.4: display apps #26–#30 — air quality (air_quality), panchang (panchang), festival calendar
 * (festivals), birthday / anniversary wall (celebrations), Google reviews (reviews).
 * Tables from migrations/021_widgets.sql, management pages admin/festivals.php and
 * admin/celebrations.php. See docs/modules/widgets_26_30.md.
 */
declare(strict_types=1);

Tenant::registerTable('festivals');
Tenant::registerTable('celebrations');

Auth::registerPermission('festivals.manage', 'staff');
// Personal data (names, photos, dates of birth): managers only.
Auth::registerPermission('celebrations.manage', 'manager');
