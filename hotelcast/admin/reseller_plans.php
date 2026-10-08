<?php
/**
 * Reseller panel → Plans (2.6, docs/modules/panels.md): the plans a reseller may sell, read-only
 * (price, screen limit, features). Resellers only; plans are edited by the platform.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('reseller.panel');
Csrf::check();

$plans = Hotels::plans(true);
$usage = [];
foreach (DB::all('SELECT plan_id, COUNT(*) AS n FROM hotels WHERE reseller_id = :r AND plan_id IS NOT NULL GROUP BY plan_id', ['r' => (int) $user['reseller_id']]) as $r) {
    $usage[(int) $r['plan_id']] = (int) $r['n'];
}
$pageTitle = __('Plans');
$activeNav = 'reseller_plans';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-box-seam"></i> <?= e(__('Plans')) ?></h1><p class="lead-sm"><?= e(__('Plans you can choose for your customers. Prices and features are set by the platform.')) ?></p></div>
</div>
<?php if (!$plans): ?>
  <div class="card"><?= panel_empty('bi-box-seam', __('No plans available'), __('Ask the platform to publish a plan.')) ?></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($plans as $p): $keys = Features::planKeys($p['features']); ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex align-items-start gap-2"><h2 class="h5 mb-0 flex-grow-1"><?= e($p['name']) ?></h2><span class="badge text-bg-light border"><?= e(__(':n of my customers', ['n' => $usage[(int) $p['id']] ?? 0])) ?></span></div>
        <?php if ($p['description']): ?><p class="small text-muted mb-2"><?= e((string) $p['description']) ?></p><?php endif; ?>
        <div class="fs-4 fw-bold"><?= e(money($p['price_per_tv_month'])) ?> <span class="fs-6 fw-normal text-muted">/ <?= e(__('TV/month')) ?></span></div>
        <ul class="list-unstyled small mt-2 mb-0">
          <li><i class="bi bi-tv"></i> <?= e($p['max_tvs'] !== null ? __('max :n TVs', ['n' => $p['max_tvs']]) : __('unlimited TVs')) ?></li>
          <li><i class="bi bi-people"></i> <?= e(isset($p['max_users']) && $p['max_users'] !== null ? __('max :n users', ['n' => $p['max_users']]) : __('unlimited users')) ?></li>
          <li><i class="bi bi-hdd"></i> <?= e(isset($p['storage_mb']) && $p['storage_mb'] !== null ? $p['storage_mb'] . ' MB' : __('unlimited storage')) ?></li>
          <li><i class="bi bi-toggles"></i> <?= e(__(':n features', ['n' => count($keys)])) ?></li>
        </ul>
        <details class="mt-2 small"><summary class="text-muted"><?= e(__('Features')) ?></summary>
          <div class="mt-1"><?= e(implode(', ', array_map([Features::class, 'label'], $keys))) ?></div></details>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
