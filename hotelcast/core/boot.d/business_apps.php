<?php
/**
 * 2.3: business display apps — offers (#4), class schedule (#6), departures board (#7),
 * KPI dashboard (#8). Tables from migrations/017_business_apps.sql, management pages
 * admin/offers.php, admin/class_schedule.php, admin/departures.php, admin/kpi.php.
 * See docs/modules/business_apps.md.
 */
declare(strict_types=1);

foreach (['offers', 'class_sessions', 'departures', 'kpi_tiles'] as $__t) {
    Tenant::registerTable($__t);
}
unset($__t);

Auth::registerPermission('offers.manage', 'staff');
Auth::registerPermission('class_schedule.manage', 'staff');
// Counter / front-desk staff update departure statuses.
Auth::registerPermission('departures.manage', 'reception');
Auth::registerPermission('kpi.manage', 'staff');
