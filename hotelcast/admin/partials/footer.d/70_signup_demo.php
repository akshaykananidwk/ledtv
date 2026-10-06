<?php
/**
 * Banners for free-trial hotels (#17: days left + "Upgrade") and demo hotels (#21: "Demo mode —
 * changes are disabled"). Printed at the end of the page and moved below the top bar by a tiny
 * script (no shared layout file needs to change). $user may be null.
 */
declare(strict_types=1);

if (empty($user) || !Tenant::has() || License::mode() !== 'saas') {
    return;
}
$__h = Tenant::hotel();
if (!$__h) {
    return;
}
$__banner = null;
$__kind = Demo::kind((int) $__h['id']);
if ($__kind !== null) {
    $__ro = Demo::readOnlyHotel((int) $__h['id']);
    $__text = $__kind === 'public'
        ? __('Demo mode — changes are disabled. This demo hotel is reset every night.')
        : __('Client demo — valid until :d, then deleted automatically.', ['d' => !empty($__h['expires_at']) ? date('d M Y', (int) strtotime((string) $__h['expires_at'])) : '—'])
            . ($__ro ? ' ' . __('Demo mode — changes are disabled.') : '');
    $__link = $__kind === 'public' && Signup::enabled() ? [base_url('signup.php'), __('Start free trial'), 'bi-rocket-takeoff'] : null;
    $__banner = ['info', 'bi-easel', $__text, $__link, 'hcDemoBanner'];
} elseif (!empty($__h['is_trial'])) {
    $__left = Signup::daysLeft($__h);
    $__ended = Tenant::state() !== 'active';
    $__text = $__ended ? __('Your free trial has ended. Upgrade to a paid plan to switch your TVs back on.')
        : ($__left <= 1 ? __('Your free trial ends today.') : __('Free trial: :n days left.', ['n' => $__left]));
    $__link = Auth::can('billing.view') ? [admin_url('billing.php') . '#upgrade', __('Upgrade'), 'bi-rocket-takeoff'] : null;
    $__banner = [$__ended || $__left <= 3 ? 'warning' : 'primary', 'bi-hourglass-split', $__text, $__link, 'hcTrialBanner'];
}
if (!$__banner) {
    return;
}
[$__type, $__icon, $__text, $__link, $__id] = $__banner;
?>
<div class="alert alert-<?= e($__type) ?> rounded-0 mb-0 d-flex flex-wrap gap-2 align-items-center small hc-module-banner" role="status" id="<?= e($__id) ?>">
  <i class="bi <?= e($__icon) ?> fs-5"></i>
  <div class="flex-grow-1"><?= e($__text) ?></div>
  <?php if ($__link): ?><a class="btn btn-sm btn-<?= $__type === 'warning' ? 'dark' : 'light' ?>" href="<?= e($__link[0]) ?>"><i class="bi <?= e($__link[2]) ?>"></i> <?= e($__link[1]) ?></a><?php endif; ?>
</div>
<script>(function(){var b=document.getElementById(<?= json_embed($__id) ?>),t=document.querySelector('.hc-main > .hc-topbar');if(b&&t){t.insertAdjacentElement('afterend',b);}})();</script>
<?php unset($__h, $__banner, $__kind, $__ro, $__text, $__link, $__left, $__ended, $__type, $__icon, $__id); ?>
