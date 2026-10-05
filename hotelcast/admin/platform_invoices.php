<?php
/**
 * Platform → Invoices (#20, manual billing): generate monthly invoices, create one invoice,
 * mark paid (payment reference / date), cancel, send a reminder, print.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $back = admin_url('platform_invoices.php');
    try {
        switch ($op) {
            case 'generate':
                $ym = req_str('month', $_POST, 7);
                $r = Billing::generateMonthly(preg_match('/^\d{4}-\d{2}$/', $ym) ? $ym : null, Auth::id());
                ActivityLog::add('invoices_generate', 'invoice', null, $r['period'][0] . ': ' . count($r['created']) . ' created');
                flash('success', __(':n invoices created for :p.', ['n' => count($r['created']), 'p' => date('M Y', (int) strtotime($r['period'][0]))])
                    . ($r['skipped'] ? ' ' . __(':n hotels skipped (no plan, no TVs or already invoiced).', ['n' => count($r['skipped'])]) : ''));
                break;

            case 'create':
                $hid = req_int('hotel_id', $_POST);
                if (!Hotels::find($hid)) {
                    throw new InvalidArgumentException(__('Hotel not found.'));
                }
                [$from, $to] = Billing::monthRange(req_str('month', $_POST, 7) ?: null);
                $tv = trim(req_str('tv_count', $_POST, 10));
                $price = trim(req_str('unit_price', $_POST, 20));
                $iid = Billing::createInvoice($hid, $from, $to, Auth::id(), $tv === '' ? null : max(0, (int) $tv), $price === '' ? null : max(0.0, (float) $price), req_str('notes', $_POST, 1000));
                ActivityLog::add('invoice_create', 'invoice', $iid, 'Hotel #' . $hid);
                flash('success', __('Invoice created.'));
                $back = admin_url('invoice.php', ['id' => $iid]);
                break;

            case 'paid':
                Billing::markPaid($id, req_str('payment_ref', $_POST, 120), req_str('paid_at', $_POST, 20) ?: null, req_str('payment_method', $_POST, 40));
                ActivityLog::add('invoice_paid', 'invoice', $id, req_str('payment_ref', $_POST, 120));
                flash('success', __('Payment recorded.'));
                break;

            case 'cancel':
                Billing::cancel($id);
                ActivityLog::add('invoice_cancel', 'invoice', $id);
                flash('success', __('Invoice cancelled.'));
                break;

            case 'remind':
                $inv = Billing::find($id);
                if ($inv && $inv['status'] === 'unpaid') {
                    $brand = Branding::get(0)['product'];
                    $res = Notifier::sendToContact((string) $inv['contact_email'], (string) $inv['contact_phone'], $brand . ': ' . __('payment reminder') . ' ' . $inv['number'],
                        sprintf("Dear %s,\n\nInvoice %s (%s) is due on %s. Please pay at your earliest convenience.\n\nThank you,\n%s",
                            $inv['hotel_name'], $inv['number'], money($inv['total'], $inv['currency']), date('d M Y', (int) strtotime($inv['due_date'])), $brand));
                    DB::query('UPDATE invoices SET reminders_sent = reminders_sent + 1, last_reminder_at = :n WHERE id = :id', ['n' => now(), 'id' => $id]);
                    flash($res ? 'success' : 'warning', $res ? __('Reminder sent.') : __('No email / WhatsApp contact for this hotel.'));
                }
                break;

            case 'run_billing':
                $r = Billing::processOverdue();
                flash('success', __('Overdue check done: :r reminders, :s hotels suspended.', ['r' => $r['reminded'], 's' => $r['suspended']]));
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

$fStatus = req_str('status', $_GET, 20);
$fHotel = req_int('hotel', $_GET);
$fMonth = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '';
$where = [];
$p = [];
if (in_array($fStatus, ['unpaid', 'paid', 'cancelled'], true)) {
    $where[] = 'i.status = :st';
    $p['st'] = $fStatus;
} elseif ($fStatus === 'overdue') {
    $where[] = "i.status = 'unpaid' AND i.due_date < :today";
    $p['today'] = date('Y-m-d');
}
if ($fHotel) {
    $where[] = 'i.hotel_id = :h';
    $p['h'] = $fHotel;
}
if ($fMonth !== '') {
    $where[] = 'i.period_from = :pf';
    $p['pf'] = $fMonth . '-01';
}
$invoices = DB::all('SELECT i.*, h.name AS hotel_name FROM invoices i JOIN hotels h ON h.id = i.hotel_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.id DESC LIMIT 500', $p);
$sum = DB::one("SELECT
    COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total END), 0) AS unpaid,
    COALESCE(SUM(CASE WHEN status = 'unpaid' AND due_date < :t THEN total END), 0) AS overdue,
    COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= :m THEN total END), 0) AS paid_month
    FROM invoices", ['t' => date('Y-m-d'), 'm' => date('Y-m-01 00:00:00')]);
$hotels = DB::all('SELECT id, name FROM hotels ORDER BY name');
$prevMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));

$pageTitle = __('Invoices');
$activeNav = 'platform_invoices';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Invoices')) ?></h1><p class="lead-sm"><?= e(__('Manual billing: invoice = active TVs × plan price + tax. Record payments by hand.')) ?></p></div>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#genBox"><i class="bi bi-lightning-charge"></i> <?= e(__('Generate monthly invoices')) ?></button>
    <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#newBox"><i class="bi bi-plus-lg"></i> <?= e(__('Single invoice')) ?></button>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="run_billing"><button class="btn btn-outline-secondary"><i class="bi bi-bell"></i> <?= e(__('Run overdue check')) ?></button></form>
  </div>
</div>
<div class="row g-3 mb-3">
  <?php foreach ([['bi-hourglass-split', 'bg-soft-warning', __('Unpaid'), $sum['unpaid']], ['bi-exclamation-triangle', 'bg-soft-danger', __('Overdue'), $sum['overdue']], ['bi-cash-coin', 'bg-soft-success', __('Paid this month'), $sum['paid_month']]] as [$icon, $cls, $label, $val]): ?>
    <div class="col-md-4"><div class="card h-100"><div class="stat-card"><div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div><div><div class="stat-value"><?= e(money($val)) ?></div><div class="stat-label"><?= e($label) ?></div></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="collapse mb-3" id="genBox"><div class="card"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="op" value="generate">
    <div class="col-sm-4"><label class="form-label" for="g_m"><?= e(__('Month')) ?></label><input class="form-control" type="month" id="g_m" name="month" value="<?= e($prevMonth) ?>"></div>
    <div class="col-sm-8"><button class="btn btn-primary"><i class="bi bi-lightning-charge"></i> <?= e(__('Generate for all active hotels')) ?></button>
      <div class="form-text"><?= e(__('Hotels that already have an invoice for this month are skipped. Runs automatically on the 1st when enabled in Platform settings.')) ?></div></div>
  </form>
</div></div></div>
<div class="collapse mb-3" id="newBox"><div class="card"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="op" value="create">
    <div class="col-md-4"><label class="form-label" for="n_h"><?= e(__('Hotel')) ?></label><select class="form-select" id="n_h" name="hotel_id" required>
      <?php foreach ($hotels as $h): ?><option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="n_m"><?= e(__('Month')) ?></label><input class="form-control" type="month" id="n_m" name="month" value="<?= e(date('Y-m')) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="n_tv"><?= e(__('TVs')) ?></label><input class="form-control" type="number" min="0" id="n_tv" name="tv_count" placeholder="<?= e(__('auto')) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="n_p"><?= e(__('Price per TV')) ?></label><input class="form-control" type="number" step="0.01" min="0" id="n_p" name="unit_price" placeholder="<?= e(__('plan')) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><?= e(__('Create')) ?></button></div>
    <div class="col-12"><input class="form-control" name="notes" maxlength="1000" placeholder="<?= e(__('Notes printed on the invoice (optional)')) ?>"></div>
  </form>
</div></div></div>
<form class="d-flex flex-wrap gap-2 mb-3" method="get">
  <select class="form-select" style="max-width:12rem" name="status">
    <option value=""><?= e(__('All statuses')) ?></option>
    <?php foreach (['unpaid' => __('Unpaid'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'cancelled' => __('Cancelled')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" style="max-width:16rem" name="hotel"><option value=""><?= e(__('All hotels')) ?></option>
    <?php foreach ($hotels as $h): ?><option value="<?= (int) $h['id'] ?>"<?= $fHotel === (int) $h['id'] ? ' selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?></select>
  <input class="form-control" style="max-width:11rem" type="month" name="month" value="<?= e($fMonth) ?>">
  <button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> <?= e(__('Filter')) ?></button>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hc table-hover align-middle">
  <thead><tr><th><?= e(__('Number')) ?></th><th><?= e(__('Hotel')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Period')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('TVs')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Due')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
  <tbody>
  <?php if (!$invoices): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= e(__('No invoices yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($invoices as $inv): ?>
    <tr>
      <td><a class="fw-semibold" href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e($inv['number']) ?></a></td>
      <td class="small"><?= e($inv['hotel_name']) ?></td>
      <td class="d-none d-md-table-cell small"><?= e(date('M Y', (int) strtotime($inv['period_from']))) ?></td>
      <td class="d-none d-lg-table-cell"><?= (int) $inv['tv_count'] ?></td>
      <td class="text-end text-nowrap"><?= e(money($inv['total'], $inv['currency'])) ?></td>
      <td class="small text-nowrap"><?= e(date('d M Y', (int) strtotime($inv['due_date']))) ?><?= Billing::overdueDays($inv) ? '<div class="text-danger">' . e(__(':n days overdue', ['n' => Billing::overdueDays($inv)])) . '</div>' : '' ?></td>
      <td><?= Billing::statusBadge($inv) ?><?php if ($inv['status'] === 'paid'): ?><div class="small text-muted"><?= e(trim(date('d M', (int) strtotime((string) $inv['paid_at'])) . ' ' . ($inv['payment_ref'] ?? ''))) ?></div><?php endif; ?></td>
      <td class="text-end text-nowrap">
        <?php if ($inv['status'] === 'unpaid'): ?>
          <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#paidModal" data-id="<?= (int) $inv['id'] ?>" data-number="<?= e($inv['number']) ?>"><i class="bi bi-check2-circle"></i> <span class="d-none d-xl-inline"><?= e(__('Mark paid')) ?></span></button>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="remind"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= e(__('Send reminder')) ?>"><i class="bi bi-bell"></i></button></form>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Cancel invoice :n?', ['n' => $inv['number']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= e(__('Cancel')) ?>"><i class="bi bi-x-lg"></i></button></form>
        <?php endif; ?>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('invoice.php', ['id' => $inv['id'], 'print' => 1])) ?>" target="_blank" rel="noopener" title="<?= e(__('Print')) ?>"><i class="bi bi-printer"></i></a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>

<div class="modal fade" id="paidModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
  <?= Csrf::field() ?><input type="hidden" name="op" value="paid"><input type="hidden" name="id" value="">
  <div class="modal-header"><h5 class="modal-title"><?= e(__('Record payment')) ?> <span data-number></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button></div>
  <div class="modal-body row g-3">
    <div class="col-sm-6"><label class="form-label" for="pm_d"><?= e(__('Payment date')) ?></label><input class="form-control" type="date" id="pm_d" name="paid_at" value="<?= e(date('Y-m-d')) ?>"></div>
    <div class="col-sm-6"><label class="form-label" for="pm_m"><?= e(__('Method')) ?></label><select class="form-select" id="pm_m" name="payment_method">
      <?php foreach (['UPI', 'Bank transfer', 'Cheque', 'Cash', 'Other'] as $m): ?><option value="<?= e($m) ?>"><?= e(__($m)) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label" for="pm_r"><?= e(__('Payment reference')) ?></label><input class="form-control" id="pm_r" name="payment_ref" maxlength="120" placeholder="<?= e(__('UTR / cheque no.')) ?>"></div>
    <div class="col-12 small text-muted"><?= e(__('A hotel that was suspended for non-payment is reactivated automatically.')) ?></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button><button class="btn btn-success"><i class="bi bi-check2-circle"></i> <?= e(__('Mark paid')) ?></button></div>
</form></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const m = document.getElementById('paidModal');
  m.addEventListener('show.bs.modal', (ev) => {
    const b = ev.relatedTarget;
    m.querySelector('input[name=id]').value = b.getAttribute('data-id');
    m.querySelector('[data-number]').textContent = b.getAttribute('data-number');
  });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
