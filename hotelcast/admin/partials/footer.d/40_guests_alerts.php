<?php
/**
 * Live guest-service alerts on every admin page (V2_SPEC §2 "Reception notifications"): polls
 * admin/ajax.php?action=guests_alerts every 10 s for users with services.manage and shows a toast,
 * plays a short sound and keeps a bell badge (new orders + open requests) in the top bar.
 * Included by footer.php (admin/partials/footer.d hook).
 */
declare(strict_types=1);

if (!Auth::user() || !Tenant::has() || !Auth::can('services.manage') || !GuestServices::enabled()) {
    return;
}
?>
<script type="application/json" id="hcGuestAlertsConfig"><?= json_embed([
    'hotel' => Tenant::id(),
    'orders_url' => admin_url('orders.php'),
    'interval' => 10000,
    'i18n' => [
        'new_order' => __('New order from room :room'),
        'new_request' => __('Room :room: :type'),
        'badge' => __('New orders and open requests'),
        'open' => __('Open'),
    ],
]) ?></script>
<script src="<?= e(asset('js/guests-alerts.js')) ?>" defer></script>
