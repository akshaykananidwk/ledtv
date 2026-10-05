<?php
/**
 * Guests / front desk / PMS + guest services module (V2_SPEC §1, §2).
 * Registers the module's tenant tables (auto-scoped by DB::insert/update/delete) and permissions.
 * Reserved core permissions used as well: guests.manage, services.manage (reception+).
 */
declare(strict_types=1);

foreach (['guest_stays', 'guest_tokens', 'guest_menu_categories', 'guest_menu_items', 'guest_orders', 'guest_order_items',
    'guest_request_types', 'guest_requests', 'guest_feedback'] as $__t) {
    Tenant::registerTable($__t);
}
unset($__t);

// Menu / request types / front desk & TV settings / PMS key (manager+), feedback report (manager+).
Auth::registerPermission('guests.setup', 'manager');
Auth::registerPermission('guests.feedback', 'manager');
