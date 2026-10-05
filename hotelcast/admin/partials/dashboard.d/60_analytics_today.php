<?php
/**
 * Dashboard widget (analytics #17 / ads #11): today's content plays, TV uptime since midnight and
 * ad impressions. Manager+ (analytics.view) and only when the plan includes analytics.
 */
declare(strict_types=1);

if (!Auth::can('analytics.view') || !Tenant::feature('analytics')) {
    return;
}
try {
    $wAn = Analytics::today();
} catch (Throwable $e) {
    Logger::error('analytics widget failed: ' . $e->getMessage());
    return;
}
?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100" id="hcAnalyticsWidget">
    <div class="card-header d-flex align-items-center"><span><i class="bi bi-graph-up-arrow"></i> <?= e(__('Today at a glance')) ?></span>
      <a class="ms-auto small" href="<?= e(admin_url('analytics.php')) ?>"><?= e(__('Analytics')) ?></a></div>
    <div class="card-body">
      <div class="row text-center g-2">
        <div class="col-4"><div class="fs-3 fw-bold" data-an="plays"><?= number_format((int) $wAn['plays']) ?></div><div class="small text-muted"><?= e(__('Plays today')) ?></div></div>
        <div class="col-4"><div class="fs-3 fw-bold" data-an="uptime"><?= $wAn['uptime'] !== null ? e((string) $wAn['uptime']) . '%' : '–' ?></div><div class="small text-muted"><?= e(__('TV uptime')) ?></div></div>
        <div class="col-4"><div class="fs-3 fw-bold" data-an="ads"><?= number_format((int) $wAn['ad_impressions']) ?></div><div class="small text-muted"><?= e(__('Ad impressions')) ?></div></div>
      </div>
    </div>
  </div>
</div>
