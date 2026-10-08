<?php
/**
 * Reseller panel → Invoices (2.6, docs/modules/panels.md): invoices of the reseller's customers and the
 * commission report (moved here from the 2.0 reseller page). Resellers only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('reseller.panel');
Csrf::check();

$rid = (int) $user['reseller_id'];
$reseller = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $rid]);
if (!$reseller) {
    http_response_code(403);
    require __DIR__ . '/partials/forbidden.php';
    exit;
}
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');
$status = req_str('status', $_GET, 10);
$status = in_array($status, ['unpaid', 'paid', 'cancelled'], true) ? $status : '';
$com = Billing::commission($rid, $from, $to);
$invoices = DB::all(
    'SELECT i.*, h.name AS hotel_name FROM invoices i JOIN hotels h ON h.id = i.hotel_id WHERE h.reseller_id = :r' . ($status !== '' ? ' AND i.status = :st' : '') . ' ORDER BY i.id DESC LIMIT 200',
    ['r' => $rid] + ($status !== '' ? ['st' => $status] : [])
);
$unpaid = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(i.total), 0) AS total FROM invoices i JOIN hotels h ON h.id = i.hotel_id WHERE h.reseller_id = :r AND i.status = 'unpaid'", ['r' => $rid]) ?? ['n' => 0, 'total' => 0];
$pageTitle = __('Invoices');
$activeNav = 'reseller_invoices';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-receipt-cutoff"></i> <?= e(__('Invoices of my customers')) ?></h1><p class="lead-sm"><?= e(__('Invoices are issued by the platform; your commission is calculated from paid invoices.')) ?></p></div>
</div>
<div class="kpi-grid mb-3">
  <?= panel_kpi(__('Unpaid invoices'), (int) $unpaid['n'], 'bi-receipt', admin_url('reseller_invoices.php', ['status' => 'unpaid']), (int) $unpaid['n'] ? money($unpaid['total']) : '', (int) $unpaid['n'] ? 'warning' : '') ?>
  <?= panel_kpi(__('Paid (net) in period'), money($com['paid_total']), 'bi-cash') ?>
  <?= panel_kpi(__('Commission in period'), money($com['commission']), 'bi-cash-coin', '', __(':p %', ['p' => (string) $com['percent']]), 'success') ?>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-header"><?= e(__('Commission report')) ?></div><div class="card-body">
      <form class="row g-2 mb-3" method="get">
        <div class="col-5"><input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>" aria-label="<?= e(__('From')) ?>"></div>
        <div class="col-5"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>" aria-label="<?= e(__('To')) ?>"></div>
        <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel"></i></button></div>
      </form>
      <table class="table table-sm mb-2"><thead><tr><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('Paid (net)')) ?></th><th class="text-end"><?= e(__('Commission')) ?></th></tr></thead><tbody>
        <?php foreach ($com['rows'] as $r): ?><tr><td class="small"><?= e($r['name']) ?></td><td class="text-end"><?= e(money($r['amount'])) ?></td><td class="text-end"><?= e(money($r['commission'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$com['rows']): ?><tr><td colspan="3" class="text-muted small"><?= e(__('No paid invoices in this period.')) ?></td></tr><?php endif; ?>
      </tbody><tfoot><tr class="fw-bold"><td><?= e(__('Total')) ?></td><td class="text-end"><?= e(money($com['paid_total'])) ?></td><td class="text-end text-success" data-commission><?= e(money($com['commission'])) ?></td></tr></tfoot></table>
      <div class="small text-muted"><?= e(__('Commission = paid invoices (without tax) × :p %.', ['p' => (string) $com['percent']])) ?></div>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><?= e(__('Invoices')) ?></span>
        <form method="get" class="ms-auto"><select class="form-select form-select-sm" name="status" onchange="this.form.submit()" aria-label="<?= e(__('Status')) ?>">
          <option value=""><?= e(__('All statuses')) ?></option>
          <?php foreach (['unpaid' => __('Unpaid'), 'paid' => __('Paid'), 'cancelled' => __('Cancelled')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></form></div>
      <div class="table-responsive"><table class="table table-sm table-hc mb-0">
        <thead><tr><th><?= e(__('Number')) ?></th><th><?= e(__('Customer')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Period')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Status')) ?></th></tr></thead><tbody>
        <?php if (!$invoices): ?><tr><td colspan="5" class="text-muted text-center py-3"><?= e(__('No invoices yet.')) ?></td></tr><?php endif; ?>
        <?php foreach ($invoices as $inv): ?><tr><td><a href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e($inv['number']) ?></a></td><td class="small"><?= e($inv['hotel_name']) ?></td><td class="small d-none d-md-table-cell"><?= e(date('M Y', (int) strtotime($inv['period_from']))) ?></td><td class="text-end"><?= e(money($inv['total'], $inv['currency'])) ?></td><td><?= Billing::statusBadge($inv) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
