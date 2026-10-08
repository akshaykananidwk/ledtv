<?php
/**
 * Platform dashboard block (included by admin/platform_hotels.php, the platform admin's home page):
 * the All screens counters, top customers by screens and offline TVs by customer.
 * docs/modules/platform_screens.md. Read-only; scope = the current user's customers.
 */
declare(strict_types=1);

require_once __DIR__ . '/platform_screens.php';

if (!Auth::can('platform.screens')) {
    return;
}
$psDash = PlatformScreens::dashboard(PlatformScreens::scopeHotelIds());
?>
<div class="d-flex align-items-center mb-2">
  <h2 class="h5 mb-0"><i class="bi bi-tv"></i> <?= e(__('Screens of all customers')) ?></h2>
  <a class="ms-auto small" href="<?= e(admin_url('platform_screens.php')) ?>"><?= e(__('All screens')) ?> <i class="bi bi-arrow-right"></i></a>
</div>
<?= ps_counters($psDash['counters']) ?>
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header"><?= e(__('Top customers by screens')) ?></div>
      <ul class="list-group list-group-flush" id="psTopCustomers">
        <?php if (!$psDash['top']): ?><li class="list-group-item text-muted small"><?= e(__('No TVs registered yet.')) ?></li><?php endif; ?>
        <?php foreach ($psDash['top'] as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $c['id'], 'tab' => 'screens'])) ?>"><?= e($c['name']) ?></a>
            <span class="small"><span class="text-success"><?= (int) $c['online'] ?></span> / <?= (int) $c['total'] ?> <?= e(__('online')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header"><?= e(__('Offline TVs by customer')) ?></div>
      <ul class="list-group list-group-flush" id="psOfflineCustomers">
        <?php if (!$psDash['offline']): ?><li class="list-group-item text-muted small"><?= e(__('All TVs are online.')) ?></li><?php endif; ?>
        <?php foreach ($psDash['offline'] as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a class="text-decoration-none" href="<?= e(admin_url('platform_screens.php', ['customer' => $c['id'], 'status' => 'offline'])) ?>"><?= e($c['name']) ?></a>
            <span class="badge text-bg-danger"><?= (int) $c['offline'] ?> / <?= (int) $c['total'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
