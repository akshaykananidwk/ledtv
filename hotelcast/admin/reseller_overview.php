<?php
/**
 * Reseller panel → Overview (2.6, docs/modules/panels.md): KPIs of the reseller's own customers, alerts,
 * recent activity in their customers, allowance and commission. Resellers only; every number is limited
 * to customers with hotels.reseller_id = the reseller (core/PlatformScreens.php scope).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('reseller.panel');
Csrf::check();
ActivityLog::$platformScope = true;

$rid = (int) $user['reseller_id'];
$reseller = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $rid]);
if (!$reseller) {
    http_response_code(403);
    require __DIR__ . '/partials/forbidden.php';
    exit;
}
$scope = PlatformScreens::scopeHotelIds() ?? [];
$hotels = Hotels::all($rid);
$cust = ['total' => count($hotels), 'active' => 0, 'suspended' => 0, 'expiring' => 0];
$alerts = [];
foreach ($hotels as $h) {
    $st = Tenant::state((int) $h['id']);
    if ($st === 'active') {
        $cust['active']++;
    } else {
        $cust['suspended']++;
        $alerts[] = ['tone' => 'danger', 'icon' => 'bi-pause-circle-fill', 'text' => __(':c is :s', ['c' => $h['name'], 's' => $st === 'expired' ? __('expired') : __('suspended')]), 'href' => admin_url('platform_customer.php', ['id' => $h['id']]), 'action' => __('Open')];
    }
    if ($st === 'active' && $h['expires_at'] && strtotime((string) $h['expires_at']) < time() + 14 * 86400) {
        $cust['expiring']++;
        $days = max(0, (int) ceil((strtotime((string) $h['expires_at']) - time()) / 86400));
        $alerts[] = ['tone' => 'warning', 'icon' => 'bi-calendar-event', 'text' => __(':c expires in :d day(s)', ['c' => $h['name'], 'd' => $days]), 'href' => admin_url('platform_customer.php', ['id' => $h['id']]), 'action' => __('Open')];
    }
    if ((int) $h['unpaid_invoices']) {
        $alerts[] = ['tone' => 'warning', 'icon' => 'bi-receipt', 'text' => __(':c: :n unpaid invoice(s)', ['c' => $h['name'], 'n' => $h['unpaid_invoices']]), 'href' => admin_url('reseller_invoices.php'), 'action' => __('Invoices')];
    }
}
$c = PlatformScreens::counters($scope);
foreach (PlatformScreens::dashboard($scope, 5)['offline'] as $o) {
    $alerts[] = ['tone' => 'warning', 'icon' => 'bi-wifi-off', 'text' => __(':c: :n TV(s) offline', ['c' => $o['name'], 'n' => $o['offline']]), 'href' => admin_url('platform_customer.php', ['id' => $o['id'], 'tab' => 'screens', 'status' => 'offline']), 'action' => __('Screens')];
}
if ($c['warnings']) {
    $alerts[] = ['tone' => 'warning', 'icon' => 'bi-heart-pulse', 'text' => __(':n TV(s) with health warnings', ['n' => $c['warnings']]), 'href' => admin_url('platform_screens.php', ['warn' => 1]), 'action' => __('Screens')];
}
if ($c['outdated']) {
    $alerts[] = ['tone' => 'info', 'icon' => 'bi-android2', 'text' => __(':n TV(s) run an outdated app', ['n' => $c['outdated']]), 'href' => admin_url('platform_screens.php', ['update' => 1]), 'action' => __('Screens')];
}
$canCreate = Hotels::resellerCanCreate($rid);
$com = License::mode() === 'saas' ? Billing::commission($rid, date('Y-01-01'), date('Y-m-d')) : null;
$activity = panel_recent_activity($scope, 12);
$top = PlatformScreens::dashboard($scope, 6)['top'];

$pageTitle = __('Overview');
$activeNav = 'reseller_overview';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-speedometer2"></i> <?= e($reseller['name']) ?></h1><p class="lead-sm"><?= e(__('Your customers at a glance.')) ?> <?= e(__('Customers')) ?>: <?= count($hotels) ?><?= $reseller['max_hotels'] !== null ? ' / ' . (int) $reseller['max_hotels'] : '' ?> · <?= e(__('Commission')) ?> <?= e((string) (float) $reseller['commission_percent']) ?>%</p></div>
  <div class="quick-actions d-flex flex-wrap gap-2">
    <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(admin_url('reseller.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add customer')) ?></a><?php endif; ?>
    <a class="btn btn-light border" href="<?= e(admin_url('platform_screens.php')) ?>"><i class="bi bi-tv"></i> <?= e(__('Screens')) ?></a>
  </div>
</div>
<div class="kpi-grid mb-3" data-overview-kpis>
  <?= panel_kpi(__('My customers'), $cust['total'], 'bi-buildings', admin_url('reseller.php'), $reseller['max_hotels'] !== null ? __('allowance :n', ['n' => (int) $reseller['max_hotels']]) : '', '', 'customers') ?>
  <?= panel_kpi(__('Active'), $cust['active'], 'bi-check-circle', admin_url('reseller.php'), $cust['expiring'] ? __(':n expiring soon', ['n' => $cust['expiring']]) : '', 'success', 'active') ?>
  <?= panel_kpi(__('Suspended / expired'), $cust['suspended'], 'bi-pause-circle', admin_url('reseller.php'), '', $cust['suspended'] ? 'danger' : '', 'suspended') ?>
  <?= panel_kpi(__('TVs online'), $c['online'], 'bi-wifi', admin_url('platform_screens.php', ['status' => 'online']), __('of :n', ['n' => $c['total']]), 'success', 'online') ?>
  <?= panel_kpi(__('TVs offline'), $c['offline'], 'bi-wifi-off', admin_url('platform_screens.php', ['status' => 'offline']), '', $c['offline'] ? 'danger' : '', 'offline') ?>
  <?= panel_kpi(__('Outdated app'), $c['outdated'], 'bi-android2', admin_url('platform_screens.php', ['update' => 1]), '', $c['outdated'] ? 'warning' : '', 'outdated') ?>
  <?= panel_kpi(__('Health warnings'), $c['warnings'], 'bi-heart-pulse', admin_url('platform_screens.php', ['warn' => 1]), '', $c['warnings'] ? 'warning' : '', 'warnings') ?>
  <?php if ($com): ?><?= panel_kpi(__('Commission this year'), money($com['commission']), 'bi-cash-coin', admin_url('reseller_invoices.php'), __('paid: :a', ['a' => money($com['paid_total'])]), 'success', 'commission') ?><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><i class="bi bi-bell me-2"></i><?= e(__('Needs attention')) ?><span class="badge text-bg-light border ms-2"><?= count($alerts) ?></span></div>
      <?= panel_alerts($alerts, __('All good — nothing needs your attention right now.')) ?>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-activity me-2"></i><?= e(__('Recent activity in my customers')) ?></div>
      <?php if (!$activity): ?><div class="hc-empty py-4"><i class="bi bi-activity"></i><p class="mb-0"><?= e(__('No activity yet.')) ?></p></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hc mb-0" data-recent-activity><tbody>
        <?php foreach ($activity as $a): ?>
          <tr><td class="small text-nowrap text-muted"><?= e(time_ago((string) $a['created_at'])) ?></td>
            <td class="small"><a class="text-decoration-none fw-semibold" href="<?= e(admin_url('platform_customer.php', ['id' => $a['hotel_id'], 'tab' => 'activity'])) ?>"><?= e($a['hotel_name'] ?? '') ?></a></td>
            <td class="small"><?= e($a['username'] ?? '-') ?></td>
            <td class="small"><span class="mono"><?= e($a['action']) ?></span> <span class="text-muted"><?= e(mb_strimwidth((string) ($a['details'] ?? ''), 0, 80, '…')) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-bar-chart me-2"></i><?= e(__('Customers by screens')) ?></div>
      <?php if (!$top): ?><?= panel_empty('bi-tv', __('No TVs registered yet.'), $canCreate ? __('Add your first customer and register its TVs.') : '', $canCreate ? admin_url('reseller.php', ['action' => 'new']) : '', __('Add customer')) ?>
      <?php else: $max = max(1, (int) $top[0]['total']); ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($top as $t): ?>
          <li class="list-group-item py-2"><div class="d-flex justify-content-between small"><a class="text-decoration-none fw-semibold" href="<?= e(admin_url('platform_customer.php', ['id' => $t['id']])) ?>"><?= e($t['name']) ?></a><span><span class="text-success"><?= (int) $t['online'] ?></span> / <?= (int) $t['total'] ?></span></div>
            <div class="progress mt-1" style="height:5px"><div class="progress-bar" style="width:<?= (int) round($t['total'] * 100 / $max) ?>%"></div></div></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-lightning me-2"></i><?= e(__('Quick actions')) ?></div>
      <div class="card-body quick-actions d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller.php')) ?>"><i class="bi bi-briefcase"></i> <?= e(__('My customers')) ?></a>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller_plans.php')) ?>"><i class="bi bi-box-seam"></i> <?= e(__('Plans')) ?></a>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller_invoices.php')) ?>"><i class="bi bi-receipt-cutoff"></i> <?= e(__('Invoices')) ?></a>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller_support.php')) ?>"><i class="bi bi-life-preserver"></i> <?= e(__('Support')) ?></a>
        <?php if (Auth::can('demo.client') && is_file(__DIR__ . '/platform_demo.php')): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_demo.php')) ?>"><i class="bi bi-easel"></i> <?= e(__('Client demos')) ?></a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
