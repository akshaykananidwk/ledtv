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
$pageTitle = __('Your plan');
$activeNav = 'plan';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-box-seam"></i> <?= e(__('Your plan')) ?>: <?= e($hotel['plan_name'] ?? __('No plan')) ?></h1>
    <p class="lead-sm"><?= e(__('What your plan includes. Features that are not included are hidden in the menu.')) ?></p></div>
  <a class="btn btn-light border" href="<?= e(admin_url('settings.php')) ?>"><i class="bi bi-gear"></i> <?= e(__('Settings')) ?></a>
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
