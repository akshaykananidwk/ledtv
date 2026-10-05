<?php
/**
 * Admin sidebar — guests & guest services module (front desk, orders board, feedback, setup).
 * Items are hidden when the hotel's plan does not include the module (Tenant::feature).
 */
declare(strict_types=1);

if (!Tenant::has()) {
    return [];
}
$__g = Guests::enabled();
$__s = GuestServices::enabled();
$__items = [];
if ($__g) {
    $__items[] = ['guests', 'guests.php', 'guests.manage', 'bi-person-vcard', __('Front desk'), 'hotel'];
}
if ($__s) {
    $__items[] = ['guest_orders', 'orders.php', 'services.manage', 'bi-bell', __('Orders & requests'), 'hotel'];
    $__items[] = ['guest_feedback', 'feedback.php', 'guests.feedback', 'bi-star-half', __('Guest feedback'), 'hotel'];
}
if ($__g || $__s) {
    $__items[] = ['guest_setup', 'services_setup.php', 'guests.setup', 'bi-gear-wide-connected', __('Guest services setup'), 'hotel'];
}
unset($__g, $__s);
return $__items;
