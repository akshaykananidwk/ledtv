<?php
/**
 * Sidebar items: Templates (#12 / #7), Ads & sponsors (#11), Analytics (#17) — manager+.
 * Hidden when the hotel's plan does not include the module (Tenant::feature).
 */
declare(strict_types=1);

$hcAatNav = [];
if (Tenant::feature('templates')) {
    $hcAatNav[] = ['templates', 'templates.php', 'templates.manage', 'bi-palette', __('Templates'), 'hotel'];
}
if (Tenant::feature('ads')) {
    $hcAatNav[] = ['ads', 'ads.php', 'ads.manage', 'bi-badge-ad', __('Ads & Sponsors'), 'hotel'];
}
if (Tenant::feature('analytics')) {
    $hcAatNav[] = ['analytics', 'analytics.php', 'analytics.view', 'bi-graph-up-arrow', __('Analytics'), 'hotel'];
}
return $hcAatNav;
