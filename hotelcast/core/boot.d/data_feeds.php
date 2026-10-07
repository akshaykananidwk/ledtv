<?php
/**
 * 2.3: data feeds (core/DataFeeds.php, docs/modules/data_feeds.md) — gold & silver rates table and the
 * rates permission. The feed state table `data_feeds` is a platform table (not tenant scoped).
 */
declare(strict_types=1);

Tenant::registerTable('metal_rates');

// Gold / silver / market rates quick edit page (admin/rates.php): staff and up.
Auth::registerPermission('rates.manage', 'staff');
