<?php
/**
 * Sidebar items: Ad marketplace (#19) — hotel page (manager+, needs the ads module) and the platform
 * overview (platform admins, SaaS only).
 */
declare(strict_types=1);

$hcMktNav = [
    ['platform_marketplace', 'platform_marketplace.php', 'platform.manage', 'bi-shop', __('Ad marketplace'), 'platform', ['saas' => true]],
];
if (Tenant::has() && Tenant::feature('ads')) {
    $hcMktNav[] = ['marketplace', 'marketplace.php', 'marketplace.manage', 'bi-shop-window', __('Ad marketplace'), 'hotel', ['saas' => true]];
}
return $hcMktNav;
