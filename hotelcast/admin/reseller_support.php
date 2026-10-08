<?php
/**
 * Reseller panel → Support (2.6, docs/modules/panels.md): TVs of the reseller's customers that need
 * attention — offline, outdated app, health warnings — with the same commands as Screens (reboot,
 * reload, update). Resellers only; limited to their own customers (core/PlatformScreens.php).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform_screens.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('reseller.panel');
Csrf::check();
if (is_post()) {
    ps_handle_post(admin_url('reseller_support.php', array_intersect_key($_GET, array_flip(['view', 'page']))));
}
$scope = PlatformScreens::scopeHotelIds() ?? [];
$view = req_str('view', $_GET, 10);
$view = in_array($view, ['offline', 'outdated', 'warn'], true) ? $view : 'offline';
$f = PlatformScreens::filters(['page' => $_GET['page'] ?? 1, 'status' => $view === 'offline' ? 'offline' : '', 'update' => $view === 'outdated', 'warn' => $view === 'warn']);
$res = PlatformScreens::list($f, $scope);
$rows = PlatformScreens::decorate($res['rows']);
$c = PlatformScreens::counters($scope);
$customers = PlatformScreens::customers();
$brand = Branding::get();
$pageTitle = __('Support');
$activeNav = 'reseller_support';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-life-preserver"></i> <?= e(__('Support')) ?></h1><p class="lead-sm"><?= e(__('TVs of your customers that need attention.')) ?></p></div>
  <?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?><div class="small text-muted"><i class="bi bi-headset"></i> <?= e(__('Platform support')) ?>: <?= e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) ?></div><?php endif; ?>
</div>
<div class="kpi-grid mb-3">
  <?= panel_kpi(__('TVs offline'), $c['offline'], 'bi-wifi-off', admin_url('reseller_support.php', ['view' => 'offline']), '', $c['offline'] ? 'danger' : '') ?>
  <?= panel_kpi(__('Outdated app'), $c['outdated'], 'bi-android2', admin_url('reseller_support.php', ['view' => 'outdated']), '', $c['outdated'] ? 'warning' : '') ?>
  <?= panel_kpi(__('Health warnings'), $c['warnings'], 'bi-heart-pulse', admin_url('reseller_support.php', ['view' => 'warn']), '', $c['warnings'] ? 'warning' : '') ?>
  <?= panel_kpi(__('TVs online'), $c['online'], 'bi-wifi', admin_url('platform_screens.php', ['status' => 'online']), __('of :n', ['n' => $c['total']]), 'success') ?>
</div>
<ul class="nav nav-pills mb-3">
  <?php foreach (['offline' => __('Offline'), 'outdated' => __('Needs update'), 'warn' => __('Health warnings')] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link<?= $view === $k ? ' active' : '' ?>" href="<?= e(admin_url('reseller_support.php', ['view' => $k])) ?>"><?= e($l) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if (!$rows): ?>
  <div class="card"><?= panel_empty('bi-check2-circle', __('All good — no TV needs attention here.')) ?></div>
<?php else: ?>
  <div class="card"><?= ps_table($rows, true) ?>
    <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
  </div>
  <?= ps_bulk_bar($customers) ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
