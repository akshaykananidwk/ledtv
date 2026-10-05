<?php
/**
 * PWA + web push (#14), support tools (#24), TV device controls (#4 #5 #15 #16), setup file (#23).
 * Registers the module's tenant tables (auto-scoped by DB::insert/update/delete) and permissions.
 * push_subscriptions is NOT a tenant table: rows belong to a user (hotel_id may be NULL for
 * platform users) and are always accessed by user_id.
 */
declare(strict_types=1);

Tenant::registerTable('device_support_files');
Tenant::registerTable('device_events');

// Volume / guest menu settings and TV commands (SET_VOLUME, SHOW_MESSAGE, OPEN_INPUT, …).
Auth::registerPermission('devices.controls', 'manager');
// TV logs / crashes / screenshots of the hotel.
Auth::registerPermission('support.view', 'manager');
// Bulk setup file (tvs.csv) for tools/windows/HotelCast-Setup.ps1.
Auth::registerPermission('devices.setup', 'manager');
// Cross-hotel support dashboard.
Auth::registerPermission('support.platform', ['platform_admin']);
// Every user manages the push subscriptions of their own devices (also without a hotel context).
Auth::registerPermission('push.self', Auth::ALL_ROLES);
