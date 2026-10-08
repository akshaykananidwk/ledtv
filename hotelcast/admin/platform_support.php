<?php
/**
 * Platform → Support (#24): offline TVs, crash counts (24 h / 7 days), outdated app versions against
 * the newest APK release, hotels with errors, recent crashes across all hotels. Crash spikes are
 * reported to platform admins by core/Tasks/SupportAlertTask.php. Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('support.platform');
Csrf::check();

if (is_post() && req_str('op', $_POST, 20) === 'settings') {
    $n = req_int('threshold', $_POST);
    Settings::setPlatform('platform_crash_alert_threshold', (string) max(1, min(1000, $n ?: 5)));
    ActivityLog::add('platform_settings', 'settings', null, 'Crash alert threshold ' . max(1, min(1000, $n ?: 5)), null);
    flash('success', __('Saved.'));
    redirect(admin_url('platform_support.php'));
}

$st = DeviceSupport::platformStats();
$t = $st['totals'];
$showAll = !empty($_GET['all']);
$list = $showAll ? $st['hotels'] : $st['hotels_with_errors'];
$pageTitle = __('Support');
$activeNav = 'platform_support';
require __DIR__ . '/partials/header.php';

$tile = static function (string $label, int|string $value, string $icon, string $cls = '') {
    return '<div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-3">'
        . '<div class="small text-muted"><i class="bi ' . e($icon) . '"></i> ' . e($label) . '</div>'
        . '<div class="fs-3 fw-bold ' . e($cls) . '" data-stat>' . e((string) $value) . '</div></div></div></div>';
};
?>
<div class="page-head">
  <div><h1><i class="bi bi-life-preserver"></i> <?= e(__('Support')) ?></h1>
    <p class="lead-sm"><?= e(__('TV health across all customers.')) ?>
      <?php if ($st['newest']): ?><?= e(__('Newest app: :v (:c)', ['v' => $st['newest']['name'], 'c' => $st['newest']['code']])) ?><?php endif; ?></p></div>
</div>

<div class="row g-3 mb-3">
  <?= $tile(__('TVs'), $t['tvs'], 'bi-tv') ?>
  <?= $tile(__('Offline TVs'), $t['offline'], 'bi-wifi-off', $t['offline'] ? 'text-danger' : '') ?>
  <?= $tile(__('Crashes (24 h)'), $t['crashes_24h'], 'bi-bug', $t['crashes_24h'] ? 'text-danger' : '') ?>
  <?= $tile(__('Crashes (7 days)'), $t['crashes_7d'], 'bi-bug') ?>
  <?= $tile(__('Outdated app'), $t['outdated'], 'bi-android2', $t['outdated'] ? 'text-warning' : '') ?>
  <?= $tile(__('Customers with errors'), $t['hotels_with_errors'], 'bi-buildings', $t['hotels_with_errors'] ? 'text-danger' : '') ?>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= e($showAll ? __('All customers') : __('Customers with errors')) ?></span>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_support.php', $showAll ? [] : ['all' => 1])) ?>"><?= e($showAll ? __('Only customers with errors') : __('Show all customers')) ?></a>
      </div>
      <div class="table-responsive">
        <table class="table table-hc table-sm align-middle mb-0">
          <thead><tr><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th><th class="text-end"><?= e(__('Offline')) ?></th><th class="text-end"><?= e(__('Crashes 24 h')) ?></th><th class="text-end"><?= e(__('Crashes 7 d')) ?></th><th class="text-end d-none d-md-table-cell"><?= e(__('Outdated app')) ?></th><th class="text-end d-none d-md-table-cell"><?= e(__('Failed commands 24 h')) ?></th></tr></thead>
          <tbody>
          <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No problems found. All TVs are online and no crashes were reported.')) ?></td></tr><?php endif; ?>
          <?php foreach ($list as $h): ?>
            <tr>
              <td><a href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $h['id']])) ?>"><?= e($h['name']) ?></a><?php if ($h['status'] !== 'active'): ?> <span class="badge text-bg-secondary"><?= e($h['status']) ?></span><?php endif; ?></td>
              <td class="text-end"><?= (int) $h['tvs'] ?></td>
              <td class="text-end"><?= $h['offline'] ? '<span class="badge text-bg-danger">' . (int) $h['offline'] . '</span>' : '0' ?></td>
              <td class="text-end"><?= $h['crashes_24h'] ? '<span class="badge text-bg-danger">' . (int) $h['crashes_24h'] . '</span>' : '0' ?></td>
              <td class="text-end"><?= (int) $h['crashes_7d'] ?></td>
              <td class="text-end d-none d-md-table-cell"><?= $h['outdated'] ? '<span class="badge text-bg-warning">' . (int) $h['outdated'] . '</span>' : '0' ?></td>
              <td class="text-end d-none d-md-table-cell"><?= (int) $h['failed_24h'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-bug"></i> <?= e(__('Recent crashes')) ?></div>
      <ul class="list-group list-group-flush small">
        <?php if (!$st['recent_crashes']): ?><li class="list-group-item text-muted"><?= e(__('No crash reports.')) ?></li><?php endif; ?>
        <?php foreach ($st['recent_crashes'] as $c): ?>
          <li class="list-group-item">
            <div class="d-flex flex-wrap gap-2"><strong><?= e($c['hotel']) ?></strong><span class="text-muted"><?= e(__('Screen')) ?> <?= e($c['room_number'] ?? '-') ?></span><span class="text-muted">v<?= e($c['app_version'] ?? '?') ?></span><span class="ms-auto text-muted"><?= e($c['happened_at'] ?? $c['created_at']) ?></span></div>
            <div class="mono text-truncate"><?= e($c['summary']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-android2"></i> <?= e(__('App versions on TVs')) ?></div>
      <ul class="list-group list-group-flush">
        <?php if (!$st['versions']): ?><li class="list-group-item text-muted"><?= e(__('No TVs registered yet.')) ?></li><?php endif; ?>
        <?php foreach ($st['versions'] as $v): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>v<?= e($v['version']) ?> <span class="text-muted small">(<?= e($v['code'] ?? '?') ?>)</span> <?php if ($v['outdated']): ?><span class="badge text-bg-warning"><?= e(__('Outdated')) ?></span><?php endif; ?></span>
            <span class="badge text-bg-light border"><?= (int) $v['tvs'] ?> <?= e(__('TVs')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$st['newest']): ?><div class="card-footer small text-muted"><?= e(__('No APK uploaded yet (APK Manager): versions cannot be compared.')) ?></div><?php endif; ?>
    </div>
    <form method="post" class="card mb-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="settings">
      <div class="card-header"><i class="bi bi-bell"></i> <?= e(__('Crash spike alerts')) ?></div>
      <div class="card-body">
        <label class="form-label" for="thr"><?= e(__('Alert when a customer sends this many crash reports within one hour')) ?></label>
        <input type="number" class="form-control mb-2" id="thr" name="threshold" min="1" max="1000" value="<?= (int) SupportAlertTask::threshold() ?>">
        <p class="small text-muted"><?= e(__('Sent by push to platform admins and to the platform notification email / support WhatsApp (Platform settings), at most once every 6 hours per customer.')) ?></p>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
