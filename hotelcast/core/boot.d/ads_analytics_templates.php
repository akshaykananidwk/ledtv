<?php
/**
 * Ads & sponsors (#11), analytics (#17), template library / local guide (#12, #7):
 * tenant tables (auto-scoped by DB::insert/update/delete) and permissions.
 */
declare(strict_types=1);

Tenant::registerTable('sponsors');
Tenant::registerTable('ad_campaigns');
Tenant::registerTable('ad_stats_daily');
Tenant::registerTable('tv_usage_daily');

Auth::registerPermission('ads.manage', 'manager');
Auth::registerPermission('analytics.view', 'manager');
Auth::registerPermission('templates.manage', 'manager');
