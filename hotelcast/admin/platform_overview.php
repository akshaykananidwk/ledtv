<?php
/**
 * Super Admin console → Overview (2.6, docs/modules/panels.md): the platform home. KPI tiles across every
 * customer (customers, TVs, sign-ups, invoices, health), an alerts list that disappears when all is well,
 * recent activity across customers, quick actions and the platform-wide switches (sign-up open,
 * platform registration). Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
Csrf::check();
ActivityLog::$platformScope = true;
$saas = License::mode() === 'saas';

// ------------------------------------------------------------------ numbers
$cust = DB::one("SELECT COUNT(*) AS total, SUM(status = 'active' AND (expires_at IS NULL OR expires_at >= NOW())) AS active,
        SUM(status = 'suspended') AS suspended, SUM(status = 'expired' OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW())) AS expired,
        SUM(is_trial = 1 AND status = 'active') AS trials,
        SUM(status = 'active' AND expires_at IS NOT NULL AND expires_at >= NOW() AND expires_at < DATE_ADD(NOW(), INTERVAL 14 DAY)) AS expiring,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new30 FROM hotels") ?? [];
$c = PlatformScreens::counters(null);
$inv = $saas ? (DB::one("SELECT SUM(status = 'unpaid') AS unpaid, SUM(status = 'unpaid' AND due_date < CURDATE()) AS overdue,
        COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total ELSE 0 END), 0) AS unpaid_total FROM invoices") ?? []) : [];
$signups = $saas && Migrator::hasTable(DB::pdo(), 'signups') ? Signup::stats() : null;
$usersTotal = (int) DB::value("SELECT COUNT(*) FROM users WHERE hotel_id IS NOT NULL AND role NOT IN ('platform_admin','reseller','chain_admin')");
$resellers = $saas ? (int) DB::value("SELECT COUNT(*) FROM resellers WHERE status = 'active'") : 0;
$poolOn = DevicePool::available() && DevicePool::enabled();
$signupOn = $signups !== null && Signup::enabled();

// ------------------------------------------------------------------ alerts
$alerts = [];
$n = static fn (string $k, array $src) => (int) ($src[$k] ?? 0);
if ($n('suspended', $cust)) {
    $alerts[] = ['tone' => 'danger', 'icon' => 'bi-pause-circle-fill', 'text' => __(':n customer(s) suspended', ['n' => $n('suspended', $cust)]), 'href' => admin_url('platform_hotels.php', ['status' => 'suspended']), 'action' => __('Review')];
}
if ($n('expired', $cust)) {
    $alerts[] = ['tone' => 'warning', 'icon' => 'bi-calendar-x', 'text' => __(':n customer(s) expired', ['n' => $n('expired', $cust)]), 'href' => admin_url('platform_hotels.php', ['status' => 'expired']), 'action' => __('Review')];
}
foreach (DB::all("SELECT id, name, expires_at, is_trial FROM hotels WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at >= NOW() AND expires_at < DATE_ADD(NOW(), INTERVAL 14 DAY) ORDER BY expires_at LIMIT 6") as $h) {
    $days = max(0, (int) ceil((strtotime((string) $h['expires_at']) - time()) / 86400));
    $alerts[] = ['tone' => 'warning', 'icon' => $h['is_trial'] ? 'bi-hourglass-split' : 'bi-calendar-event', 'text' => ($h['is_trial'] ? __('Trial of :c ends in :d day(s)', ['c' => $h['name'], 'd' => $days]) : __(':c expires in :d day(s)', ['c' => $h['name'], 'd' => $days])),
        'href' => admin_url('platform_customer.php', ['id' => $h['id'], 'tab' => 'billing']), 'action' => __('Open')];
}
if ($n('overdue', $inv)) {
    $alerts[] = ['tone' => 'danger', 'icon' => 'bi-receipt', 'text' => __(':n overdue invoice(s)', ['n' => $n('overdue', $inv)]), 'href' => admin_url('platform_invoices.php', ['status' => 'unpaid']), 'action' => __('Invoices')];
}
if ($signups && $signups['pending']) {
    $alerts[] = ['tone' => 'info', 'icon' => 'bi-person-plus', 'text' => __(':n sign-up(s) waiting for approval', ['n' => $signups['pending']]), 'href' => admin_url('platform_signups.php'), 'action' => __('Sign-ups')];
}
$offlineBy = PlatformScreens::dashboard(null, 5)['offline'];
foreach ($offlineBy as $o) {
    $alerts[] = ['tone' => 'warning', 'icon' => 'bi-wifi-off', 'text' => __(':c: :n TV(s) offline', ['c' => $o['name'], 'n' => $o['offline']]), 'href' => admin_url('platform_customer.php', ['id' => $o['id'], 'tab' => 'screens', 'status' => 'offline']), 'action' => __('Screens')];
}
if ($c['warnings']) {
    $alerts[] = ['tone' => 'warning', 'icon' => 'bi-heart-pulse', 'text' => __(':n TV(s) with health warnings', ['n' => $c['warnings']]), 'href' => admin_url('platform_screens.php', ['warn' => 1]), 'action' => __('All screens')];
}
if ($c['outdated']) {
    $alerts[] = ['tone' => 'info', 'icon' => 'bi-android2', 'text' => __(':n TV(s) run an outdated app', ['n' => $c['outdated']]), 'href' => admin_url('platform_screens.php', ['update' => 1]), 'action' => __('All screens')];
}
if ($c['pool']) {
    $alerts[] = ['tone' => 'info', 'icon' => 'bi-inboxes', 'text' => __(':n TV(s) waiting in the unassigned pool', ['n' => $c['pool']]), 'href' => admin_url('platform_screens.php', ['unassigned' => 1]), 'action' => __('Assign')];
}

$activity = panel_recent_activity(null, 12);
$topCustomers = PlatformScreens::dashboard(null, 6)['top'];
// §5 charts (inline bars, no JS library): customer growth (6 months), plan distribution, device status.
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
foreach (DB::all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM hotels WHERE created_at >= :t GROUP BY m", ['t' => array_key_first($months) . '-01']) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']] = (int) $r['n'];
    }
}
$byPlan = DB::all('SELECT COALESCE(p.name, :np) AS name, COUNT(*) AS n FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id WHERE h.archived_at IS NULL GROUP BY p.id, p.name ORDER BY n DESC LIMIT 8', ['np' => __('No plan')]);
$revenue = $saas ? (DB::one("SELECT COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= :m THEN total ELSE 0 END), 0) AS month,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) AS total FROM invoices", ['m' => date('Y-m-01 00:00:00')]) ?? ['month' => 0, 'total' => 0]) : null;
// §41 device errors with severity: health warnings (DeviceHealth) and offline TVs, newest first.
$severity = ['temp_high' => 'high', 'storage_low' => 'high', 'ram_low' => 'medium', 'wifi_weak' => 'low', 'uptime_long' => 'low'];
$issues = [];
$warnMap = PlatformScreens::warningMap(null);
if ($warnMap) {
    [$in, $ip] = DB::in(array_slice(array_keys($warnMap), 0, 50), 'wd');
    foreach (DB::all("SELECT d.id, d.device_uid, d.hotel_id, d.last_heartbeat, h.name AS hotel_name, r.room_number FROM devices d JOIN hotels h ON h.id = d.hotel_id LEFT JOIN rooms r ON r.id = d.room_id WHERE d.id IN $in ORDER BY d.last_heartbeat DESC", $ip) as $d) {
        foreach ($warnMap[(int) $d['id']] as $k => $text) {
            $issues[] = ['severity' => $severity[$k] ?? 'medium', 'customer' => $d['hotel_name'], 'hotel_id' => (int) $d['hotel_id'], 'screen' => $d['room_number'] ?? $d['device_uid'], 'uid' => $d['device_uid'],
                'text' => DeviceHealth::warningLabel((string) $k) . ($text !== '' ? ' · ' . $text : ''), 'when' => $d['last_heartbeat']];
        }
    }
}
foreach (DB::all("SELECT d.device_uid, d.hotel_id, d.last_ping, h.name AS hotel_name, r.room_number FROM devices d JOIN hotels h ON h.id = d.hotel_id LEFT JOIN rooms r ON r.id = d.room_id
        WHERE d.is_revoked = 0 AND d.room_id IS NOT NULL AND d.status <> 'online' AND h.status = 'active' ORDER BY d.last_ping DESC LIMIT 8") as $d) {
    $issues[] = ['severity' => 'high', 'customer' => $d['hotel_name'], 'hotel_id' => (int) $d['hotel_id'], 'screen' => $d['room_number'] ?? $d['device_uid'], 'uid' => $d['device_uid'], 'text' => __('Offline'), 'when' => $d['last_ping']];
}
$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($issues, static fn ($a, $b) => [$rank[$a['severity']], $b['when'] ?? ''] <=> [$rank[$b['severity']], $a['when'] ?? '']);
$issues = array_slice($issues, 0, 10);

$pageTitle = __('Overview');
$activeNav = 'platform_overview';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-speedometer2"></i> <?= e(__('Overview')) ?></h1><p class="lead-sm"><?= e(__('Everything on the platform at a glance. Customers are managed from here — open a workspace only for deep content editing.')) ?></p></div>
  <div class="quick-actions d-flex flex-wrap gap-2">
    <?php if ($saas): ?><a class="btn btn-primary" href="<?= e(admin_url('platform_hotels.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New customer')) ?></a><?php endif; ?>
    <a class="btn btn-light border" href="<?= e(admin_url('platform_screens.php')) ?>"><i class="bi bi-tv"></i> <?= e(__('All screens')) ?></a>
    <a class="btn btn-light border" href="<?= e(admin_url('platform_search.php')) ?>"><i class="bi bi-search"></i> <?= e(__('Search')) ?></a>
  </div>
</div>

<div class="kpi-grid mb-3" data-overview-kpis>
  <?= panel_kpi(__('Customers'), $n('total', $cust), 'bi-buildings', admin_url('platform_hotels.php'), __(':n new in 30 days', ['n' => $n('new30', $cust)]), '', 'customers') ?>
  <?= panel_kpi(__('Active'), $n('active', $cust), 'bi-check-circle', admin_url('platform_hotels.php', ['status' => 'active']), $n('trials', $cust) ? __(':n on trial', ['n' => $n('trials', $cust)]) : '', 'success', 'active') ?>
  <?= panel_kpi(__('Suspended / expired'), $n('suspended', $cust) + $n('expired', $cust), 'bi-pause-circle', admin_url('platform_hotels.php', ['status' => 'suspended']), $n('expiring', $cust) ? __(':n expiring soon', ['n' => $n('expiring', $cust)]) : '', $n('suspended', $cust) + $n('expired', $cust) ? 'danger' : '', 'suspended') ?>
  <?= panel_kpi(__('TVs online'), $c['online'], 'bi-wifi', admin_url('platform_screens.php', ['status' => 'online']), __('of :n', ['n' => $c['total']]), 'success', 'online') ?>
  <?= panel_kpi(__('TVs offline'), $c['offline'], 'bi-wifi-off', admin_url('platform_screens.php', ['status' => 'offline']), '', $c['offline'] ? 'danger' : '', 'offline') ?>
  <?php $apkLatest = AppReleases::platformLatest(); /* 2.7: outdated = older than the customer's effective latest (platform APK Manager) */ ?>
  <?= panel_kpi(__('Outdated app'), $c['outdated'], 'bi-android2', admin_url('platform_apk.php'), $apkLatest ? __('Latest: v:v', ['v' => $apkLatest['version_name']]) : '', $c['outdated'] ? 'warning' : '', 'outdated') ?>
  <?= panel_kpi(__('Health warnings'), $c['warnings'], 'bi-heart-pulse', admin_url('platform_screens.php', ['warn' => 1]), '', $c['warnings'] ? 'warning' : '', 'warnings') ?>
  <?= panel_kpi(__('Users'), $usersTotal, 'bi-people', admin_url('platform_search.php'), '', '', 'users') ?>
  <?php if ($signups): ?><?= panel_kpi(__('Open sign-ups'), $signups['pending'] + $signups['verify'], 'bi-person-plus', admin_url('platform_signups.php'), __(':n in 7 days', ['n' => $signups['last7']]), $signups['pending'] ? 'warning' : '', 'signups') ?><?php endif; ?>
  <?php if ($saas): ?><?= panel_kpi(__('Unpaid invoices'), $n('unpaid', $inv), 'bi-receipt', admin_url('platform_invoices.php', ['status' => 'unpaid']), $n('unpaid', $inv) ? money($inv['unpaid_total'] ?? 0) : '', $n('overdue', $inv) ? 'danger' : '', 'invoices') ?><?php endif; ?>
  <?php if ($saas): ?><?= panel_kpi(__('Revenue this month'), money($revenue['month'] ?? 0), 'bi-cash-coin', admin_url('platform_reports.php'), __('total: :a', ['a' => money($revenue['total'] ?? 0)]), 'success', 'revenue') ?><?php endif; ?>
  <?= panel_kpi(__('Expiring soon'), $n('expiring', $cust), 'bi-calendar-event', admin_url('platform_hotels.php'), __('within 14 days'), $n('expiring', $cust) ? 'warning' : '', 'expiring') ?>
  <?php if ($saas): ?><?= panel_kpi(__('Resellers'), $resellers, 'bi-person-badge', admin_url('platform_resellers.php'), '', '', 'resellers') ?><?php endif; ?>
  <?php if ($c['pool'] || DevicePool::available()): ?><?= panel_kpi(__('Unassigned pool'), $c['pool'], 'bi-inboxes', admin_url('platform_screens.php', ['unassigned' => 1]), '', $c['pool'] ? 'info' : '', 'pool') ?><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><i class="bi bi-bell me-2"></i><?= e(__('Needs attention')) ?><span class="badge text-bg-light border ms-2"><?= count($alerts) ?></span></div>
      <?= panel_alerts($alerts, __('All good — nothing needs your attention right now.')) ?>
    </div>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><i class="bi bi-heart-pulse me-2"></i><?= e(__('Device errors & health')) ?>
        <a class="ms-auto small" href="<?= e(admin_url('platform_support.php')) ?>"><?= e(__('Support & logs')) ?></a></div>
      <?php if (!$issues): ?><div class="hc-empty py-4" data-device-issues-empty><i class="bi bi-check2-circle text-success"></i><p class="mb-0"><strong><?= e(__('All TVs are healthy.')) ?></strong></p></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hc mb-0" data-device-issues>
        <thead><tr><th><?= e(__('Severity')) ?></th><th><?= e(__('Customer')) ?></th><th><?= e(__('Screen')) ?></th><th><?= e(__('Problem')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Last seen')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($issues as $i): ?>
          <tr><td><span class="badge <?= ['high' => 'text-bg-danger', 'medium' => 'text-bg-warning', 'low' => 'text-bg-info'][$i['severity']] ?>" data-severity="<?= e($i['severity']) ?>"><?= e(['high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')][$i['severity']]) ?></span></td>
            <td class="small"><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $i['hotel_id'], 'tab' => 'screens', 'q' => $i['uid']])) ?>"><?= e($i['customer']) ?></a></td>
            <td class="small"><strong><?= e((string) $i['screen']) ?></strong></td><td class="small"><?= e($i['text']) ?></td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($i['when'] ? time_ago((string) $i['when']) : __('never')) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header d-flex align-items-center"><i class="bi bi-activity me-2"></i><?= e(__('Recent activity across customers')) ?>
        <a class="ms-auto small" href="<?= e(admin_url('platform_audit.php')) ?>"><?= e(__('Audit logs')) ?></a></div>
      <?php if (!$activity): ?><div class="hc-empty py-4"><i class="bi bi-activity"></i><p class="mb-0"><?= e(__('No activity yet.')) ?></p></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hc mb-0" data-recent-activity>
        <tbody>
        <?php foreach ($activity as $a): ?>
          <tr>
            <td class="small text-nowrap text-muted" style="width:6rem"><?= e(time_ago((string) $a['created_at'])) ?></td>
            <td class="small"><?php if ($a['hotel_id']): ?><a class="text-decoration-none fw-semibold" href="<?= e(admin_url('platform_customer.php', ['id' => $a['hotel_id'], 'tab' => 'activity'])) ?>"><?= e($a['hotel_name'] ?? ('#' . $a['hotel_id'])) ?></a><?php else: ?><span class="badge text-bg-light border"><?= e(__('Platform')) ?></span><?php endif; ?></td>
            <td class="small"><?= e($a['username'] ?? '-') ?></td>
            <td class="small"><span class="mono"><?= e($a['action']) ?></span> <span class="text-muted text-break"><?= e(mb_strimwidth((string) ($a['details'] ?? ''), 0, 90, '…')) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-toggles me-2"></i><?= e(__('Platform switches')) ?></div>
      <ul class="list-group list-group-flush">
        <?php if ($signups !== null && Auth::can('signup.manage')): ?>
        <li class="list-group-item d-flex align-items-center gap-3">
          <div class="flex-grow-1"><div class="fw-semibold"><?= e(__('Online sign-up')) ?></div><div class="small text-muted"><?= e(__('New customers can register themselves and start a free trial.')) ?></div></div>
          <?= panel_switch('signup_open', 0, $signupOn, '', __('Close the online sign-up? The public form shows "registration closed".')) ?>
        </li>
        <?php endif; ?>
        <?php if (DevicePool::available() && Auth::can('platform.pool')): ?>
        <li class="list-group-item d-flex align-items-center gap-3">
          <div class="flex-grow-1"><div class="fw-semibold"><?= e(__('Platform registration')) ?></div><div class="small text-muted"><?= e(__('TVs may register with the platform key into the unassigned pool.')) ?></div></div>
          <?= panel_switch('pool_enabled', 0, $poolOn, '', __('Turn off platform registration? TVs can then only register with a customer\'s key.')) ?>
        </li>
        <?php endif; ?>
        <li class="list-group-item d-flex align-items-center gap-3">
          <div class="flex-grow-1"><div class="fw-semibold"><?= e(__('Chains')) ?></div><div class="small text-muted"><?= e(__('One owner, many customers.')) ?></div></div>
          <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_settings.php')) ?>#features"><?= e(Chains::enabled() ? __('On') : __('Off')) ?> · <?= e(__('Settings')) ?></a>
        </li>
      </ul>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-graph-up me-2"></i><?= e(__('Trends')) ?><a class="ms-auto small" href="<?= e(admin_url('platform_reports.php')) ?>"><?= e(__('Reports')) ?></a></div>
      <div class="card-body">
        <div class="small fw-semibold text-muted text-uppercase mb-1"><?= e(__('New customers per month')) ?></div>
        <?= panel_bars(array_map(static fn ($m, $v) => [date('M y', strtotime($m . '-01')), $v], array_keys($months), $months), null, 'growth') ?>
        <div class="small fw-semibold text-muted text-uppercase mt-3 mb-1"><?= e(__('TVs by status')) ?></div>
        <?= panel_stacked([[__('Online'), $c['online'], 'success'], [__('Offline'), $c['offline'], 'danger'], [__('Outdated app'), $c['outdated'], 'warning']], 'devices') ?>
        <div class="small fw-semibold text-muted text-uppercase mt-3 mb-1"><?= e(__('Customers by plan')) ?></div>
        <?= panel_bars(array_map(static fn ($r) => [(string) $r['name'], (int) $r['n']], $byPlan), null, 'plans') ?>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-bar-chart me-2"></i><?= e(__('Top customers by screens')) ?></div>
      <?php if (!$topCustomers): ?><div class="hc-empty py-4"><i class="bi bi-tv"></i><p class="mb-0"><?= e(__('No TVs registered yet.')) ?></p></div>
      <?php else: $max = max(1, (int) $topCustomers[0]['total']); ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($topCustomers as $t): ?>
          <li class="list-group-item py-2">
            <div class="d-flex justify-content-between small"><a class="text-decoration-none fw-semibold" href="<?= e(admin_url('platform_customer.php', ['id' => $t['id']])) ?>"><?= e($t['name']) ?></a><span><span class="text-success"><?= (int) $t['online'] ?></span> / <?= (int) $t['total'] ?></span></div>
            <div class="progress mt-1" style="height:5px"><div class="progress-bar" style="width:<?= (int) round($t['total'] * 100 / $max) ?>%"></div></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-lightning me-2"></i><?= e(__('Quick actions')) ?></div>
      <div class="card-body quick-actions d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_plans.php')) ?>"><i class="bi bi-box-seam"></i> <?= e(__('Plans & features')) ?></a>
        <?php if ($saas): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_invoices.php')) ?>"><i class="bi bi-receipt-cutoff"></i> <?= e(__('Invoices')) ?></a>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_resellers.php')) ?>"><i class="bi bi-person-badge"></i> <?= e(__('Resellers')) ?></a><?php endif; ?>
        <?php if ($signups !== null): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_signups.php')) ?>"><i class="bi bi-person-plus"></i> <?= e(__('Sign-ups & trials')) ?></a><?php endif; ?>
        <?php if (Auth::can('support.platform') && is_file(__DIR__ . '/platform_support.php')): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_support.php')) ?>"><i class="bi bi-life-preserver"></i> <?= e(__('Support & logs')) ?></a><?php endif; ?>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_settings.php')) ?>"><i class="bi bi-sliders"></i> <?= e(__('Platform settings')) ?></a>
        <?php if (Auth::can('update.manage') && is_file(__DIR__ . '/update.php')): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('update.php')) ?>"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Auto-Update')) ?></a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
