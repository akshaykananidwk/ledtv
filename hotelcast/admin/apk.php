<?php
declare(strict_types=1);
/**
 * 2.9.1: TV app (APK) updates are managed only by the Super Admin (Super Admin → APK Manager,
 * admin/platform_apk.php) and reach every customer's TVs from there. Customers have no APK page any more:
 * the Super Admin is sent to the console page, everyone else gets 403.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

Auth::require('apk.manage');
redirect(admin_url('platform_apk.php'));
