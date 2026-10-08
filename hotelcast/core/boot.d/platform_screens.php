<?php
/**
 * Platform screens (docs/modules/platform_screens.md): Platform → All screens, moving TVs between
 * customers, the unassigned device pool and the customer detail page.
 *   platform.screens  platform admins (every customer) and resellers (only their own customers)
 *   platform.pool     platform admins: unassigned pool, platform registration key
 *   platform.move     platform admins: move TVs between customers / screens (2.5 security review)
 * device_pool is NOT a tenant table (see migrations/030_platform_screens.sql).
 */
declare(strict_types=1);

Auth::registerPermission('platform.screens', ['platform_admin', 'reseller']);
Auth::registerPermission('platform.pool', ['platform_admin']);
Auth::registerPermission('platform.move', ['platform_admin']);
