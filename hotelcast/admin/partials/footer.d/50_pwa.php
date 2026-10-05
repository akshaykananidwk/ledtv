<?php
/**
 * PWA client (#14): service worker registration, "Install app" bar and the web push client
 * (assets/js/pwa.js). Only for logged-in users. Included by footer.php (admin/partials/footer.d hook).
 */
declare(strict_types=1);

if (!Auth::user()) {
    return;
}
$__pwaVapid = null;
if (WebPush::supported() && (!Tenant::has() || Tenant::feature('pwa'))) {
    try {
        $__pwaVapid = WebPush::publicKey();
    } catch (Throwable $e) {
        Logger::error('VAPID key unavailable: ' . $e->getMessage());
    }
}
$__pwaBrand = Branding::get();
?>
<div class="hc-install-bar" id="hcInstallBar" role="dialog" aria-live="polite" aria-label="<?= e(__('Install app')) ?>" hidden>
  <img src="<?= e(admin_url('pwa_icon.php', ['s' => 96])) ?>" alt="" width="40" height="40">
  <div class="hc-install-text"><strong><?= e(__('Install the :product app', ['product' => $__pwaBrand['product']])) ?></strong>
    <span class="text-muted"><?= e(__('Open the admin panel from your home screen and get notifications.')) ?></span></div>
  <button type="button" class="btn btn-primary btn-sm" data-pwa-install><i class="bi bi-download"></i> <?= e(__('Install')) ?></button>
  <button type="button" class="btn-close" data-pwa-dismiss aria-label="<?= e(__('Not now')) ?>"></button>
</div>
<script type="application/json" id="hcPwaConfig"><?= json_embed([
    'sw' => admin_url('sw.js'),
    'scope' => admin_url(),
    'ajax' => admin_url('ajax.php'),
    'vapid' => $__pwaVapid,
    'i18n' => [
        'unsupported' => __('This browser or server does not support push notifications.'),
        'denied' => __('Notifications are blocked. Allow them in the browser settings for this site.'),
        'not_subscribed' => __('Notifications are not enabled on this device.'),
        'no_sw' => __('The app service worker is not available (HTTPS is required).'),
    ],
]) ?></script>
<script src="<?= e(asset('js/pwa.js')) ?>" defer></script>
<?php unset($__pwaVapid, $__pwaBrand);
