<?php
/**
 * Admin menu: Platform → Sign-ups & trials (#17) and Demo (#21); "Getting started" is in 05_getting_started.php.
 * Resellers see "Client demos" in their own menu. See 10_hotel.php for the format.
 */
declare(strict_types=1);

$__items = [
    ['platform_signups', 'platform_signups.php', 'signup.manage', 'bi-person-plus', __('Sign-ups & trials'), 'platform', ['saas' => true]],
    ['platform_demo', 'platform_demo.php', 'demo.client', 'bi-easel', Auth::role() === 'reseller' ? __('Client demos') : __('Demo'), Auth::role() === 'reseller' ? 'reseller' : 'platform', ['saas' => true]],
];
return $__items;
