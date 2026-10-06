<?php
/**
 * Admin menu — hotel chains (#20). Chain users (chain_admin, or a hotel super admin with chain access)
 * get a "Hotel chain" section; platform admins / resellers manage chains from their own menu.
 * See 10_hotel.php for the item format.
 */
declare(strict_types=1);

$__chainNav = [];
if (!Chains::enabled()) {
    // Feature switched off by the platform (Platform settings → Features): no chain menus.
    return $__chainNav;
}
if (Auth::isPlatformUser()) {
    $__chainNav[] = ['platform_chains', 'platform_chains.php', 'chains.manage', 'bi-diagram-3', __('Hotel chains'), Auth::role() === 'reseller' ? 'reseller' : 'platform'];
} elseif (Chains::isChainUser() && Chains::userChainIds()) {
    $__chainNav[] = ['chain', 'chain.php', 'chain.view', 'bi-diagram-3', __('Chain dashboard'), 'chain'];
    $__chainNav[] = ['chain_content', 'chain_content.php', 'chain.view', 'bi-collection-play', __('Chain content'), 'chain'];
    $__chainNav[] = ['chain_broadcast', 'chain_broadcast.php', 'chain.view', 'bi-broadcast', __('Chain broadcast & settings'), 'chain'];
}
return $__chainNav;
