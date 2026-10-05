<?php
/**
 * Platform → Support (placeholder). The support module (#24: offline TVs, crash counts, outdated
 * app versions across hotels) replaces this file; the menu entry lives in
 * partials/nav.d/80_platform_support.php.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('platform.manage');
$offline = DB::all(
    "SELECT h.id, h.name, COUNT(d.id) AS offline FROM hotels h JOIN devices d ON d.hotel_id = h.id
     WHERE d.is_revoked = 0 AND d.room_id IS NOT NULL AND d.status = 'offline' GROUP BY h.id, h.name ORDER BY offline DESC LIMIT 20"
);
$pageTitle = __('Support');
$activeNav = 'platform_support';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e(__('Support')) ?></h1><p class="lead-sm"><?= e(__('The full support dashboard (crashes, logs, screenshots) is coming soon.')) ?></p></div></div>
<div class="card" style="max-width:760px"><div class="card-header"><?= e(__('Hotels with offline TVs')) ?></div>
  <ul class="list-group list-group-flush">
    <?php if (!$offline): ?><li class="list-group-item text-muted"><?= e(__('All TVs are online.')) ?></li><?php endif; ?>
    <?php foreach ($offline as $o): ?>
      <li class="list-group-item d-flex justify-content-between"><a href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $o['id']])) ?>"><?= e($o['name']) ?></a><span class="badge text-bg-danger"><?= (int) $o['offline'] ?> <?= e(__('offline')) ?></span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
