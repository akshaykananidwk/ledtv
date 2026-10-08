<?php
/** Admin sidebar — platform section (platform admins) and reseller panel. See 10_hotel.php for the format. */
declare(strict_types=1);

return [
    ['platform_hotels', 'platform_hotels.php', 'platform.manage', 'bi-buildings', __('Customers'), 'platform', ['saas' => true]],
    ['platform_plans', 'platform_plans.php', 'platform.manage', 'bi-box-seam', __('Plans'), 'platform', ['saas' => true]],
    ['platform_resellers', 'platform_resellers.php', 'platform.manage', 'bi-person-badge', __('Resellers'), 'platform', ['saas' => true]],
    ['platform_invoices', 'platform_invoices.php', 'platform.manage', 'bi-receipt-cutoff', __('Invoices'), 'platform', ['saas' => true]],
    ['platform_licenses', 'platform_licenses.php', 'platform.manage', 'bi-key', __('Licenses'), 'platform', ['saas' => true]],
    ['platform_settings', 'platform_settings.php', 'platform.manage', 'bi-sliders', __('Platform settings'), 'platform'],
    ['update', 'update.php', 'update.manage', 'bi-cloud-arrow-down', __('Auto-Update'), 'platform'],
    ['reseller', 'reseller.php', 'reseller.panel', 'bi-briefcase', __('My customers'), 'reseller'],
];
