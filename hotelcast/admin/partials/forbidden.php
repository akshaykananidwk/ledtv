<?php
declare(strict_types=1);
/** 403 page (included by Auth::require() and require_can()). */
require_once __DIR__ . '/common.php';
if (!headers_sent()) {
    http_response_code(403);
}
$user = Auth::user();
$pageTitle = __('Access denied');
$activeNav = '';
require __DIR__ . '/header.php';
?>
<div class="hc-empty my-5">
  <i class="bi bi-shield-lock display-3 text-danger"></i>
  <h1 class="h3 mt-3"><?= e(__('Access denied')) ?></h1>
  <p class="text-muted"><?= e(__('Your account does not have permission to open this page. Ask the hotel owner (Super Admin) if you need access.')) ?></p>
  <a class="btn btn-primary" href="<?= e(admin_url('index.php')) ?>"><i class="bi bi-house"></i> <?= e(__('Back to dashboard')) ?></a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
