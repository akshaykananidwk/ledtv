<?php
declare(strict_types=1);
/** 403 page "Not included in your plan" (core/Features.php deny()). Expects $GLOBALS['hcDeniedFeature']. */
require_once __DIR__ . '/common.php';
if (!headers_sent()) {
    http_response_code(403);
}
$user = Auth::user();
$nipKey = (string) ($GLOBALS['hcDeniedFeature'] ?? '');
$pageTitle = __('Not included in your plan');
$activeNav = '';
require __DIR__ . '/header.php';
?>
<div class="hc-empty my-5" data-feature-denied="<?= e($nipKey) ?>">
  <i class="bi bi-box-seam display-3 text-warning"></i>
  <h1 class="h3 mt-3"><?= e(__('Not included in your plan')) ?></h1>
  <p class="text-muted"><strong><?= e(__('This feature is not available in your current plan.')) ?></strong> <?= e(__(':f is not part of your current plan.', ['f' => Features::label($nipKey)])) ?> <?= e(__('Contact your provider to upgrade your plan.')) ?></p>
  <div class="d-flex flex-wrap gap-2 justify-content-center">
    <a class="btn btn-primary" href="<?= e(admin_url($user ? Auth::homePage() : 'login.php')) ?>"><i class="bi bi-house"></i> <?= e(__('Back to dashboard')) ?></a>
    <?php if ($user && Auth::can('settings.manage')): ?><a class="btn btn-outline-primary" href="<?= e(admin_url('plan.php')) ?>"><i class="bi bi-box-seam"></i> <?= e(__('Your plan')) ?></a><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
