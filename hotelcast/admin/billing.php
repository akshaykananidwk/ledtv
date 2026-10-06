<?php
/** Hotel → Billing (#20): the hotel's own invoices, read-only (super admin). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('billing.view');
Csrf::check();

// Module hook: admin/partials/billing.d/*.php may handle their own POST (op) and return a callable
// that prints a card on this page (e.g. the free-trial upgrade). billing.php stays POST-able for
// suspended / expired hotels (Auth::SUSPENDED_ALLOWED_SCRIPTS).
$billingCards = [];
foreach (glob(__DIR__ . '/partials/billing.d/*.php') ?: [] as $__bf) {
    $__card = require $__bf;
    if (is_callable($__card)) {
        $billingCards[] = $__card;
    }
}
unset($__bf, $__card);

$hotel = Tenant::hotel();
$invoices = DB::all('SELECT * FROM invoices WHERE hotel_id = :hid AND status <> \'cancelled\' ORDER BY id DESC LIMIT 200', hid());
$unpaid = array_filter($invoices, fn ($i) => $i['status'] === 'unpaid');
$due = array_sum(array_map(fn ($i) => (float) $i['total'], $unpaid));
$overdue = array_filter($unpaid, fn ($i) => Billing::overdueDays($i) > 0);
$brand = Branding::get();
$pageTitle = __('Billing');
$activeNav = 'billing';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e(__('Billing')) ?></h1><p class="lead-sm"><?= e(__('Invoices from :p for this hotel.', ['p' => $brand['product']])) ?></p></div></div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card h-100"><div class="stat-card"><div class="stat-icon bg-soft-primary"><i class="bi bi-box-seam"></i></div><div><div class="stat-value"><?= e($hotel['plan_name'] ?? '—') ?></div><div class="stat-label"><?= e(__('Plan')) ?><?= $hotel['price_per_tv_month'] !== null ? ' · ' . e(money($hotel['price_per_tv_month'])) . '/' . e(__('TV/month')) : '' ?></div></div></div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="stat-card"><div class="stat-icon <?= $overdue ? 'bg-soft-danger' : 'bg-soft-warning' ?>"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= e(money($due)) ?></div><div class="stat-label"><?= e(__('Amount due')) ?><?= $overdue ? ' · ' . e(__(':n overdue', ['n' => count($overdue)])) : '' ?></div></div></div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="stat-card"><div class="stat-icon bg-soft-info"><i class="bi bi-tv"></i></div><div><div class="stat-value"><?= (int) Tenant::tvCount() ?></div><div class="stat-label"><?= e(__('Active TVs (billed per TV)')) ?></div></div></div></div></div>
</div>
<?php foreach ($billingCards as $__card) { $__card(); } ?>
<?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?>
  <div class="alert alert-info small"><i class="bi bi-headset"></i> <?= e(__('Questions about an invoice or payment? Contact :c.', ['c' => trim($brand['support_phone'] . ' ' . $brand['support_email'])])) ?></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hc table-hover">
  <thead><tr><th><?= e(__('Number')) ?></th><th><?= e(__('Period')) ?></th><th class="d-none d-md-table-cell"><?= e(__('TVs')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Due')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php if (!$invoices): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No invoices yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($invoices as $inv): ?>
    <tr>
      <td><a class="fw-semibold" href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e($inv['number']) ?></a></td>
      <td class="small"><?= e(date('M Y', (int) strtotime($inv['period_from']))) ?></td>
      <td class="d-none d-md-table-cell"><?= (int) $inv['tv_count'] ?></td>
      <td class="text-end text-nowrap"><?= e(money($inv['total'], $inv['currency'])) ?></td>
      <td class="small text-nowrap"><?= e(date('d M Y', (int) strtotime($inv['due_date']))) ?></td>
      <td><?= Billing::statusBadge($inv) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('invoice.php', ['id' => $inv['id'], 'print' => 1])) ?>" target="_blank" rel="noopener" title="<?= e(__('Print')) ?>"><i class="bi bi-printer"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
