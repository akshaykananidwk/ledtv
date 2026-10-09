<?php
/**
 * Settings → Your plan (2.5, core/Features.php): the customer's plan, limits with usage and the
 * features that are included / not included, with the provider's contact for upgrades. Read-only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';
require_once __DIR__ . '/partials/features_ui.php';

$user = Auth::require('settings.manage');

$hid = Tenant::id();
$hotel = Tenant::hotel($hid);
$brand = Branding::get();
$disabled = Features::disabledKeys($hid);
$sub = Panel::subscriptionState($hid); // 2.6 docs/SPEC_SAAS.md §29
$lim = Features::limits($hid);
$pageTitle = __('Subscription');
$activeNav = 'plan';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-box-seam"></i> <?= e(__('Your plan')) ?>: <?= e($hotel['plan_name'] ?? __('No plan')) ?></h1>
    <p class="lead-sm"><?= e(__('What your plan includes. Features that are not included are hidden in the menu.')) ?></p></div>
  <a class="btn btn-light border" href="<?= e(admin_url('settings.php')) ?>"><i class="bi bi-gear"></i> <?= e(__('Settings')) ?></a>
</div>
<div class="kpi-grid mb-3" data-subscription>
  <div class="kpi kpi-<?= e($sub['tone']) ?>" data-subscription-state="<?= e($sub['key']) ?>"><span class="kpi-label"><i class="bi bi-patch-check"></i> <?= e(__('Status')) ?></span><span class="kpi-value"><?= e($sub['label']) ?></span>
    <?php if ($sub['days'] !== null): ?><span class="kpi-sub"><?= e($sub['days'] >= 0 ? __(':n day(s) left', ['n' => $sub['days']]) : __('expired :n day(s) ago', ['n' => -$sub['days']])) ?></span><?php endif; ?></div>
  <div class="kpi"><span class="kpi-label"><i class="bi bi-box-seam"></i> <?= e(__('Plan')) ?></span><span class="kpi-value fs-5"><?= e($hotel['plan_name'] ?? __('No plan')) ?></span></div>
  <div class="kpi"><span class="kpi-label"><i class="bi bi-calendar-event"></i> <?= e(__('Valid until')) ?></span><span class="kpi-value fs-5"><?= e(!empty($hotel['expires_at']) ? date('d M Y', (int) strtotime((string) $hotel['expires_at'])) : __('No expiry')) ?></span></div>
  <div class="kpi"><span class="kpi-label"><i class="bi bi-tv"></i> <?= e(__('Screens')) ?></span><span class="kpi-value fs-5"><?= e(Features::screenCount($hid) . ' / ' . ($lim['max_screens'] ?? __('unlimited'))) ?></span></div>
  <div class="kpi"><span class="kpi-label"><i class="bi bi-people"></i> <?= e(__('Users')) ?></span><span class="kpi-value fs-5"><?= e(Features::userCount($hid) . ' / ' . ($lim['max_users'] ?? __('unlimited'))) ?></span></div>
  <div class="kpi"><span class="kpi-label"><i class="bi bi-hdd"></i> <?= e(__('Storage')) ?></span><span class="kpi-value fs-5"><?= e((int) ceil(Features::storageUsed($hid) / 1048576) . ' MB / ' . ($lim['storage_mb'] !== null ? $lim['storage_mb'] . ' MB' : __('unlimited'))) ?></span></div>
</div>
<?php if ($disabled): ?>
  <div class="alert alert-info d-flex flex-wrap gap-2 align-items-center">
    <i class="bi bi-arrow-up-circle fs-5"></i>
    <div class="flex-grow-1"><?= e(__('Need more? :n features are not included in your plan. Contact your provider to upgrade your plan.', ['n' => count($disabled)])) ?>
      <?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?><strong><?= e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) ?></strong><?php endif; ?></div>
  </div>
<?php endif; ?>
<div class="card"><div class="card-body"><?= features_summary_html($hid) ?></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
