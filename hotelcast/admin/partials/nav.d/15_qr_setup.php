<?php
/**
 * Admin menu: "Add TV (QR)" — claim a TV that shows a setup QR code (admin/claim.php).
 * Hotel users with rooms.manage see it in the hotel menu; platform admins / resellers without a
 * hotel context see it in their own menu (they pick the hotel on the page). See 10_hotel.php.
 */
declare(strict_types=1);

if (Tenant::has()) {
    return [['qr_setup', 'claim.php', 'rooms.manage', 'bi-qr-code-scan', __('Add TV (QR)'), 'hotel']];
}
return [['qr_setup', 'claim.php', 'platform.hotels', 'bi-qr-code-scan', __('Add TV (QR)'), Auth::role() === 'reseller' ? 'reseller' : 'platform']];
