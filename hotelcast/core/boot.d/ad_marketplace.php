<?php
/**
 * Ad marketplace (#19): tenant tables (auto-scoped by DB::insert/update/delete) and permissions.
 * Advertisers are not hotel users: their tables (mkt_advertisers, mkt_creatives, mkt_bookings …) are
 * platform tables, every advertiser query is scoped by advertiser_id in core/Marketplace.php.
 */
declare(strict_types=1);

Tenant::registerTable('mkt_hotel_settings');
Tenant::registerTable('mkt_booking_hotels');
Tenant::registerTable('mkt_payouts');

// Hotel side: approve / reject marketplace ads (manager+), opt-in, prices and earnings (super admin).
Auth::registerPermission('marketplace.manage', 'manager');
Auth::registerPermission('marketplace.settings', 'super_admin');
