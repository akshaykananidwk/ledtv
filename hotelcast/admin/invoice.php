<?php
/**
 * Printable invoice (HTML, print / save as PDF from the browser). Access: platform admin (all),
 * reseller (invoices of their hotels), hotel super admin (own hotel). ?print=1 = bare page.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require();
$inv = Billing::find(req_int('id', $_GET));
$allowed = $inv && (
    Auth::can('platform.manage')
    || (Auth::role() === 'reseller' && $user['reseller_id'] && (int) $inv['reseller_id'] === (int) $user['reseller_id'])
    || (Auth::can('billing.view') && Tenant::current() === (int) $inv['hotel_id'])
);
if (!$allowed) {
    if ($inv) {
        Logger::write('security', 'warning', 'Invoice access denied', ['invoice' => $inv['id'], 'user' => Auth::id()]);
    }
    http_response_code(404);
    $pageTitle = __('Not found');
    $activeNav = '';
    require __DIR__ . '/partials/header.php';
    echo '<div class="hc-empty my-5"><i class="bi bi-receipt"></i><p>' . e(__('Invoice not found.')) . '</p></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$brand = Branding::get(0);
$to = json_decode((string) $inv['bill_to'], true) ?: ['name' => $inv['hotel_name']];
$seller = trim((string) Settings::platform('invoice_seller_details', ''));
$taxLabel = (string) Settings::platform('billing_tax_label', 'Tax');
$print = !empty($_GET['print']);
$d = fn (?string $v) => $v ? date('d M Y', (int) strtotime($v)) : '';

ob_start();
?>
<div class="invoice-sheet shadow-sm" style="--inv:<?= e($brand['color']) ?>">
  <div class="d-flex flex-wrap justify-content-between gap-3 border-bottom pb-3 mb-3" style="border-color:var(--inv)!important">
    <div>
      <?php if ($brand['logo_url']): ?><img src="<?= e($brand['logo_url']) ?>" alt="" style="max-height:56px;max-width:220px" class="mb-2"><br><?php endif; ?>
      <strong class="fs-5" style="color:var(--inv)"><?= e($brand['product']) ?></strong>
      <?php if ($seller !== ''): ?><div class="small text-muted" style="white-space:pre-line"><?= e($seller) ?></div><?php endif; ?>
      <?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?><div class="small text-muted"><?= e(dot_trim($brand['support_phone'] . ' · ' . $brand['support_email'])) ?></div><?php endif; ?>
    </div>
    <div class="text-end">
      <div class="fs-3 fw-bold" style="color:var(--inv)"><?= e(__('INVOICE')) ?></div>
      <div><strong><?= e($inv['number']) ?></strong></div>
      <div class="small"><?= e(__('Date')) ?>: <?= e($d($inv['issued_at'])) ?></div>
      <div class="small"><?= e(__('Due')) ?>: <?= e($d($inv['due_date'])) ?></div>
      <div class="mt-1"><?= Billing::statusBadge($inv) ?></div>
    </div>
  </div>
  <div class="row mb-3">
    <div class="col-sm-6">
      <div class="small text-muted text-uppercase"><?= e(__('Bill to')) ?></div>
      <strong><?= e($to['name'] ?? '') ?></strong>
      <?php foreach (['contact', 'address', 'city', 'email', 'phone'] as $k): if (!empty($to[$k])): ?><div class="small"><?= e($to[$k]) ?></div><?php endif; endforeach; ?>
      <?php if (!empty($to['gstin'])): ?><div class="small">GSTIN: <?= e($to['gstin']) ?></div><?php endif; ?>
    </div>
    <div class="col-sm-6 text-sm-end">
      <div class="small text-muted text-uppercase"><?= e(__('Period')) ?></div>
      <div><?= e($d($inv['period_from'])) ?> – <?= e($d($inv['period_to'])) ?></div>
    </div>
  </div>
  <table class="table">
    <thead><tr><th><?= e(__('Description')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th><th class="text-end"><?= e(__('Rate')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th></tr></thead>
    <tbody>
      <tr><td><?= e(__('TV management service')) ?><?= $inv['plan_name'] ? ' — ' . e($inv['plan_name']) : '' ?><div class="small text-muted"><?= e(date('F Y', (int) strtotime($inv['period_from']))) ?></div></td>
        <td class="text-end"><?= (int) $inv['tv_count'] ?></td><td class="text-end"><?= e(money($inv['unit_price'], $inv['currency'])) ?></td><td class="text-end"><?= e(money($inv['amount'], $inv['currency'])) ?></td></tr>
    </tbody>
    <tfoot>
      <tr><td colspan="3" class="text-end"><?= e(__('Subtotal')) ?></td><td class="text-end"><?= e(money($inv['amount'], $inv['currency'])) ?></td></tr>
      <tr><td colspan="3" class="text-end"><?= e($taxLabel) ?> <?= e((string) (float) $inv['tax_percent']) ?>%</td><td class="text-end"><?= e(money($inv['tax'], $inv['currency'])) ?></td></tr>
      <tr class="fw-bold fs-5"><td colspan="3" class="text-end"><?= e(__('Total')) ?></td><td class="text-end"><?= e(money($inv['total'], $inv['currency'])) ?></td></tr>
    </tfoot>
  </table>
  <?php if ($inv['status'] === 'paid'): ?>
    <div class="alert alert-success py-2 small"><?= e(__('Paid on :d', ['d' => $d($inv['paid_at'])])) ?><?= $inv['payment_ref'] ? ' · ' . e(__('Ref.')) . ' ' . e($inv['payment_ref']) : '' ?><?= $inv['payment_method'] ? ' · ' . e($inv['payment_method']) : '' ?></div>
  <?php endif; ?>
  <?php if ($inv['notes']): ?><div class="small" style="white-space:pre-line"><?= e($inv['notes']) ?></div><?php endif; ?>
  <?php if ($brand['footer'] !== ''): ?><div class="small text-muted text-center mt-4"><?= e($brand['footer']) ?></div><?php endif; ?>
</div>
<?php
$sheet = (string) ob_get_clean();

if ($print) {
    ?><!DOCTYPE html>
<html lang="<?= e(I18n::lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($inv['number']) ?> · <?= e($brand['product']) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head><body class="bg-white p-3"><?= $sheet ?><script>window.addEventListener('load', function () { window.print(); });</script></body></html>
<?php
    exit;
}

$pageTitle = __('Invoice') . ' ' . $inv['number'];
$activeNav = Auth::can('platform.manage') ? 'platform_invoices' : (Auth::role() === 'reseller' ? 'reseller' : 'billing');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head no-print">
  <h1><?= e($pageTitle) ?></h1>
  <div class="d-flex gap-2"><a class="btn btn-primary" href="<?= e(admin_url('invoice.php', ['id' => $inv['id'], 'print' => 1])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i> <?= e(__('Print / PDF')) ?></a>
    <a class="btn btn-light border" href="javascript:history.back()"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
</div>
<?= $sheet ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
