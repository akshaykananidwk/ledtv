<?php
/**
 * Admin sidebar — platform section (Super Admin console, 2.6 docs/modules/panels.md) and the reseller
 * panel. See 10_hotel.php for the format; the order / grouping per panel is in core/Panel.php.
 */
declare(strict_types=1);

return [
    ['platform_overview', 'platform_overview.php', 'platform.manage', 'bi-speedometer2', __('Dashboard'), 'platform'],
    ['platform_hotels', 'platform_hotels.php', 'platform.manage', 'bi-buildings', __('Customers'), 'platform', ['saas' => true]],
    ['platform_plans', 'platform_plans.php', 'platform.manage', 'bi-box-seam', __('Plans & modules'), 'platform', ['saas' => true]],
    ['platform_resellers', 'platform_resellers.php', 'platform.manage', 'bi-person-badge', __('Resellers'), 'platform', ['saas' => true]],
    ['platform_invoices', 'platform_invoices.php', 'platform.manage', 'bi-receipt-cutoff', __('Invoices'), 'platform', ['saas' => true]],
    ['platform_licenses', 'platform_licenses.php', 'platform.manage', 'bi-key', __('Licenses'), 'platform', ['saas' => true]],
    ['platform_settings', 'platform_settings.php', 'platform.manage', 'bi-sliders', __('System settings'), 'platform'],
    // 2.7 Super Admin → APK Manager (docs/modules/apk_manager.md): platform-wide TV app releases, forced update.
    ['platform_apk', 'platform_apk.php', 'platform.manage', 'bi-android2', __('APK Manager'), 'platform'],
    ['platform_content', 'platform_content.php', 'platform.manage', 'bi-collection-play', __('Content overview'), 'platform'],
    ['platform_reports', 'platform_reports.php', 'platform.manage', 'bi-graph-up', __('Reports'), 'platform'],
    ['platform_audit', 'platform_audit.php', 'platform.manage', 'bi-journal-check', __('Audit logs'), 'platform'],
    ['update', 'update.php', 'update.manage', 'bi-cloud-arrow-down', __('Auto-Update'), 'platform'],
    // Reseller panel
    ['reseller_overview', 'reseller_overview.php', 'reseller.panel', 'bi-speedometer2', __('Overview'), 'reseller'],
    ['reseller', 'reseller.php', 'reseller.panel', 'bi-briefcase', __('My customers'), 'reseller'],
    ['reseller_plans', 'reseller_plans.php', 'reseller.panel', 'bi-box-seam', __('Plans'), 'reseller', ['saas' => true]],
    ['reseller_invoices', 'reseller_invoices.php', 'reseller.panel', 'bi-receipt-cutoff', __('Invoices'), 'reseller', ['saas' => true]],
    ['reseller_support', 'reseller_support.php', 'reseller.panel', 'bi-life-preserver', __('Support'), 'reseller'],
];
