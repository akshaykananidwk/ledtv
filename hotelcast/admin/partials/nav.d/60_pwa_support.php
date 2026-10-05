<?php
/** Admin menu: TV controls, TV support, notifications (PWA / push / support module). See 10_hotel.php for the format. */
declare(strict_types=1);

$__pwaNav = [
    ['tv_controls', 'tv_controls.php', 'devices.controls', 'bi-sliders2', __('TV controls'), 'hotel'],
    ['support', 'support.php', 'support.view', 'bi-life-preserver', __('TV support'), 'hotel'],
];
// Notifications on this device: every user; shown in the hotel menu, or in the platform / reseller
// menu for platform users without a hotel context.
$__pwaNav[] = ['push', 'push.php', 'push.self', 'bi-bell', __('Notifications'),
    Tenant::has() ? 'hotel' : (Auth::role() === 'reseller' ? 'reseller' : 'platform')];
return $__pwaNav;
