<?php
/**
 * Dashboard widget: TVs used vs. the plan / license limit, plan name, account status.
 * Widgets in this folder are included by admin/index.php in name order (variables: $user, $stats).
 */
declare(strict_types=1);

if (!Auth::can('settings.manage')) {
    return;
}
$wMax = Tenant::maxTvs();
$wUsed = Tenant::tvCount();
$wHotel = Tenant::hotel();
$wPct = $wMax ? min(100, (int) round($wUsed * 100 / max(1, $wMax))) : 0;
?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100">
    <div class="card-header"><i class="bi bi-speedometer"></i> <?= e(__('Plan & TV limit')) ?></div>
    <div class="card-body">
      <div class="d-flex justify-content-between small mb-1">
        <span><?= e(__('TVs registered')) ?></span>
        <strong><?= (int) $wUsed ?><?= $wMax !== null ? ' / ' . (int) $wMax : '' ?></strong>
      </div>
      <?php if ($wMax !== null): ?>
        <div class="progress" role="progressbar" aria-valuenow="<?= $wPct ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px">
          <div class="progress-bar<?= $wPct >= 90 ? ' bg-danger' : '' ?>" style="width:<?= $wPct ?>%"></div>
        </div>
      <?php else: ?>
        <div class="small text-muted"><?= e(__('No TV limit.')) ?></div>
      <?php endif; ?>
      <div class="small text-muted mt-2">
        <?= e(__('Plan')) ?>: <?= e($wHotel['plan_name'] ?? '—') ?>
        <?php if (!empty($wHotel['expires_at'])): ?> · <?= e(__('Valid until')) ?> <?= e(date('d M Y', (int) strtotime((string) $wHotel['expires_at']))) ?><?php endif; ?>
      </div>
    </div>
  </div>
</div>
