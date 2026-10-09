<?php
/**
 * Super Admin console → Reports (2.6, docs/SPEC_SAAS.md §39): platform-wide reports built from existing data —
 * customers by plan / status / reseller, TVs per customer, customer growth and revenue per month, with CSV export.
 * Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
Csrf::check();

$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
$growth = $months;
foreach (DB::all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM hotels WHERE created_at >= :t GROUP BY m", ['t' => array_key_first($months) . '-01']) as $r) {
    if (isset($growth[$r['m']])) {
        $growth[$r['m']] = (int) $r['n'];
    }
}
$tvGrowth = $months;
foreach (DB::all("SELECT DATE_FORMAT(registered_at, '%Y-%m') AS m, COUNT(*) AS n FROM devices WHERE is_revoked = 0 AND registered_at >= :t GROUP BY m", ['t' => array_key_first($months) . '-01']) as $r) {
    if (isset($tvGrowth[$r['m']])) {
        $tvGrowth[$r['m']] = (int) $r['n'];
    }
}
$revenue = $months;
$saas = License::mode() === 'saas';
if ($saas) {
    foreach (DB::all("SELECT DATE_FORMAT(COALESCE(paid_at, issued_at), '%Y-%m') AS m, COALESCE(SUM(total), 0) AS t FROM invoices WHERE status = 'paid' AND COALESCE(paid_at, issued_at) >= :t GROUP BY m", ['t' => array_key_first($months) . '-01']) as $r) {
        if (isset($revenue[$r['m']])) {
            $revenue[$r['m']] = (float) $r['t'];
        }
    }
}
$byPlan = DB::all('SELECT COALESCE(p.name, :np) AS name, COUNT(*) AS n FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id WHERE h.archived_at IS NULL GROUP BY p.id, p.name ORDER BY n DESC', ['np' => __('No plan')]);
$byStatus = DB::one("SELECT SUM(status = 'active' AND (expires_at IS NULL OR expires_at >= NOW())) AS active, SUM(status = 'suspended' AND archived_at IS NULL) AS suspended,
    SUM(status = 'expired' OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW())) AS expired, SUM(archived_at IS NOT NULL) AS archived, SUM(is_trial = 1 AND status = 'active') AS trial FROM hotels") ?? [];
$byReseller = DB::all('SELECT COALESCE(r.name, :direct) AS name, COUNT(*) AS n FROM hotels h LEFT JOIN resellers r ON r.id = h.reseller_id WHERE h.archived_at IS NULL GROUP BY r.id, r.name ORDER BY n DESC', ['direct' => __('— Direct customer —')]);
$perCustomer = PlatformScreens::perCustomer(null);
usort($perCustomer, static fn ($a, $b) => $b['total'] <=> $a['total']);
$c = PlatformScreens::counters(null);
$monthLabel = static fn (string $m): string => date('M y', strtotime($m . '-01'));

if (req_str('export', $_GET, 5) === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="platform-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['customer', 'tvs', 'online', 'offline', 'outdated', 'warnings']);
    foreach ($perCustomer as $r) {
        fputcsv($out, [$r['name'], $r['total'], $r['online'], $r['offline'], $r['outdated'] ?? 0, $r['warnings'] ?? 0]);
    }
    fputcsv($out, []);
    fputcsv($out, ['month', 'new customers', 'new tvs', 'revenue (paid)']);
    foreach ($months as $m => $_) {
        fputcsv($out, [$m, $growth[$m], $tvGrowth[$m], $revenue[$m]]);
    }
    exit;
}
$pageTitle = __('Reports');
$activeNav = 'platform_reports';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-graph-up"></i> <?= e(__('Reports')) ?></h1><p class="lead-sm"><?= e(__('Customers, TVs and revenue over the last 12 months.')) ?></p></div>
  <a class="btn btn-light border" href="<?= e(admin_url('platform_reports.php', ['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> <?= e(__('Export CSV')) ?></a>
</div>
<div class="kpi-grid mb-3">
  <?= panel_kpi(__('Customers'), (int) array_sum(array_column($byPlan, 'n')), 'bi-buildings') ?>
  <?= panel_kpi(__('Active'), (int) ($byStatus['active'] ?? 0), 'bi-check-circle', '', '', 'success') ?>
  <?= panel_kpi(__('TVs'), $c['total'], 'bi-tv', '', __(':n online', ['n' => $c['online']])) ?>
  <?= panel_kpi(__('New customers (12 months)'), array_sum($growth), 'bi-person-plus') ?>
  <?php if ($saas): ?><?= panel_kpi(__('Revenue (12 months)'), money(array_sum($revenue)), 'bi-cash-coin', '', '', 'success') ?><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('Customer growth')) ?></div><div class="card-body"><?= panel_bars(array_map(static fn ($m, $n) => [$monthLabel($m), $n], array_keys($growth), $growth), null, 'growth') ?></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('TV growth')) ?></div><div class="card-body"><?= panel_bars(array_map(static fn ($m, $n) => [$monthLabel($m), $n], array_keys($tvGrowth), $tvGrowth), null, 'tv-growth') ?></div></div></div>
  <?php if ($saas): ?><div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('Monthly revenue (paid invoices)')) ?></div><div class="card-body"><?= panel_bars(array_map(static fn ($m, $n) => [$monthLabel($m), $n], array_keys($revenue), $revenue), static fn ($v) => money($v), 'revenue') ?></div></div></div><?php endif; ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('Customers by plan')) ?></div><div class="card-body"><?= panel_bars(array_map(static fn ($r) => [(string) $r['name'], (int) $r['n']], $byPlan), null, 'plans') ?></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('Customers by status')) ?></div><div class="card-body">
    <?= panel_stacked([[__('Active'), (int) ($byStatus['active'] ?? 0), 'success'], [__('Trial'), (int) ($byStatus['trial'] ?? 0), 'info'], [__('Suspended'), (int) ($byStatus['suspended'] ?? 0), 'danger'], [__('Expired'), (int) ($byStatus['expired'] ?? 0), 'warning'], [__('Archived'), (int) ($byStatus['archived'] ?? 0), 'secondary']], 'status') ?>
    <div class="mt-3"><?= panel_stacked([[__('Online'), $c['online'], 'success'], [__('Offline'), $c['offline'], 'danger'], [__('Outdated app'), $c['outdated'], 'warning']], 'devices') ?></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e(__('Customers by reseller')) ?></div><div class="card-body"><?= panel_bars(array_map(static fn ($r) => [(string) $r['name'], (int) $r['n']], $byReseller), null, 'resellers') ?></div></div></div>
  <div class="col-12"><div class="card"><div class="card-header"><?= e(__('TVs per customer')) ?></div>
    <div class="table-responsive"><table class="table table-sm table-hc mb-0"><thead><tr><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th><th class="text-end"><?= e(__('Online')) ?></th><th class="text-end"><?= e(__('Offline')) ?></th><th class="text-end"><?= e(__('Outdated app')) ?></th><th class="text-end"><?= e(__('Health warnings')) ?></th></tr></thead><tbody>
      <?php foreach ($perCustomer as $r): ?><tr><td><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $r['id'], 'tab' => 'screens'])) ?>"><?= e($r['name']) ?></a></td><td class="text-end"><?= (int) $r['total'] ?></td><td class="text-end text-success"><?= (int) $r['online'] ?></td><td class="text-end<?= (int) $r['offline'] ? ' text-danger' : '' ?>"><?= (int) $r['offline'] ?></td><td class="text-end"><?= (int) ($r['outdated'] ?? 0) ?></td><td class="text-end"><?= (int) ($r['warnings'] ?? 0) ?></td></tr><?php endforeach; ?>
      <?php if (!$perCustomer): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= e(__('No TVs registered yet.')) ?></td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
