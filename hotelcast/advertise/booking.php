<?php
/**
 * Advertiser portal: one ad order — status timeline, hotels (approval per hotel), invoice-like order with
 * bank / UPI payment instructions (UPI QR), submit / cancel, link to the proof-of-play report.
 *   booking.php?id=N   (only the advertiser's own orders; others → 404)
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
$adv = MarketplacePortal::require();
Csrf::check();
$aid = (int) $adv['id'];
$id = (int) ($_REQUEST['id'] ?? 0);
$b = Marketplace::booking($aid, $id);
if (!$b) {
    http_response_code(404);
    MarketplacePortal::header(__('Not found'));
    echo '<div class="alert alert-warning">' . e(__('This order does not exist.')) . '</div>';
    MarketplacePortal::footer();
    exit;
}
$self = MarketplacePortal::url('booking.php', ['id' => $id]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        match ((string) ($_POST['op'] ?? '')) {
            'submit' => Marketplace::submit($aid, $id),
            'cancel' => Marketplace::cancelByAdvertiser($aid, $id, mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300)),
            default => null,
        };
        MarketplacePortal::flash('success', ($_POST['op'] ?? '') === 'cancel' ? __('Order cancelled.') : __('Order submitted.'));
    } catch (RuntimeException $e) {
        MarketplacePortal::flash('danger', $e->getMessage());
    }
    redirect($self);
}

$lines = Marketplace::lines($id);
$events = Marketplace::events($id);
$creative = $b['creative_id'] ? Marketplace::creative($aid, (int) $b['creative_id']) : null;
$upi = in_array($b['status'], ['awaiting_payment'], true) ? Marketplace::upiUri($b) : null;
$bank = trim(Marketplace::setting('platform_mkt_bank_text'));
$brand = Branding::get(0);
MarketplacePortal::header($b['number'] ?: __('Draft'), 'index');
?>
<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
  <div><h1 class="h4 mb-0"><?= e($b['title']) ?></h1>
    <div class="text-muted small"><?= e($b['number'] ?: __('Draft')) ?> · <?= e(Marketplace::categoryLabel((string) $b['category'])) ?></div></div>
  <?= Marketplace::statusBadge((string) $b['status']) ?>
</div>
<?php if ($b['status_reason']): ?><div class="alert alert-<?= in_array($b['status'], ['rejected', 'cancelled'], true) ? 'danger' : 'info' ?>"><?= e($b['status_reason']) ?>
  <?php if ((float) $b['refund_amount'] > 0): ?><br><strong><?= e(__('Refund')) ?>: <?= e(Marketplace::money($b['refund_amount'])) ?></strong><?= $b['refund_note'] ? ' — ' . e($b['refund_note']) : '' ?><?php endif; ?></div><?php endif; ?>
<?php if ((float) $b['refund_amount'] > 0 && !$b['status_reason']): ?><div class="alert alert-info"><?= e(__('Refund')) ?>: <?= e(Marketplace::money($b['refund_amount'])) ?></div><?php endif; ?>

<?php if ($b['status'] === 'draft'): ?>
  <div class="d-grid gap-2 d-sm-flex mb-3">
    <form method="post" class="d-grid"><?= Csrf::field() ?><input type="hidden" name="op" value="submit"><button class="btn btn-primary btn-lg"><i class="bi bi-send"></i> <?= e(__('Submit order')) ?></button></form>
    <a class="btn btn-outline-secondary btn-lg" href="<?= e(MarketplacePortal::url('book.php', ['id' => $id])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
  </div>
<?php endif; ?>

<?php if ($b['status'] === 'awaiting_payment'): ?>
<section class="card card-body mb-3 mkt-pay">
  <h2 class="h5"><i class="bi bi-bank"></i> <?= e(__('How to pay')) ?></h2>
  <p><?= e(__('Please pay :t and mention the order number :n.', ['t' => Marketplace::money($b['total']), 'n' => $b['number']])) ?>
    <?php if ($b['expires_at']): ?><br><small class="text-muted"><?= e(__('Pay before :d, otherwise the order is cancelled.', ['d' => date('d M Y', (int) strtotime((string) $b['expires_at']))])) ?></small><?php endif; ?></p>
  <?php if ($upi): ?>
    <div class="mkt-upi text-center">
      <div class="mkt-qr"><?= QrCode::svg($upi, "#000000", "#FFFFFF", __("UPI payment QR")) ?></div>
      <div class="small"><?= e(__('Scan with any UPI app')) ?> · <code><?= e(Marketplace::setting('platform_mkt_upi_id')) ?></code></div>
      <a class="btn btn-success mt-2 d-sm-none" href="<?= e($upi) ?>"><?= e(__('Pay with a UPI app')) ?></a>
    </div>
  <?php endif; ?>
  <?php if ($bank !== ''): ?><div class="mkt-bank mt-2"><?= nl2br(e($bank)) ?></div><?php endif; ?>
  <?php if (!$upi && $bank === ''): ?><p class="text-muted mb-0"><?= e(__('We will contact you with the payment details.')) ?></p><?php endif; ?>
  <p class="small text-muted mt-2 mb-0"><?= e(__('After payment our team confirms it (usually within one working day).')) ?></p>
</section>
<?php elseif ($b['status'] === 'submitted'): ?>
  <div class="alert alert-info"><?= e(__('We review your ad first, then send the payment details.')) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <section class="card mb-3 mkt-invoice">
      <div class="card-body">
        <div class="d-flex justify-content-between"><strong><?= e($brand['product']) ?></strong><span><?= e(__('Ad order')) ?> <?= e($b['number'] ?: '') ?></span></div>
        <div class="small text-muted mb-2"><?= e($adv['business_name']) ?> · <?= e($adv['contact_name']) ?> · <?= e($adv['mobile']) ?></div>
        <div class="small mb-2"><?= e(date('d M Y', (int) strtotime((string) $b['start_date']))) ?> – <?= e(date('d M Y', (int) strtotime((string) $b['end_date']))) ?>
          (<?= e(__(':n days', ['n' => Marketplace::days((string) $b['start_date'], (string) $b['end_date'])])) ?>)
          <?php if ($b['daily_start']): ?> · <?= e(substr((string) $b['daily_start'], 0, 5)) ?>–<?= e(substr((string) $b['daily_end'], 0, 5)) ?><?php endif; ?>
          · <?= e(Marketplace::modelLabel((string) $b['pricing_model'])) ?></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th><?= e(__('Hotel')) ?></th><th class="text-end"><?= e(__('Details')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr><td><?= e($l['hotel_name']) ?><br><small class="text-muted"><?= e((string) $l['hotel_city']) ?></small> <?= in_array($b['status'], ['draft', 'submitted', 'awaiting_payment'], true) ? '' : Marketplace::statusBadge((string) $l['status']) ?>
              <?php if ($l['reason'] && in_array($l['status'], ['rejected', 'cancelled'], true)): ?><br><small class="text-danger"><?= e($l['reason']) ?></small><?php endif; ?></td>
              <td class="text-end small"><?php if ($b['pricing_model'] === 'cpm'): ?><?= e(number_format((int) $l['impressions'])) ?> × <?= e(Marketplace::money($l['unit_price'])) ?>/1000
                <?php else: ?><?= (int) $l['days'] ?> × <?= (int) $l['tv_count'] ?> <?= e(__('TVs')) ?> × <?= e(Marketplace::money($l['unit_price'])) ?><?php endif; ?></td>
              <td class="text-end"><?= e(Marketplace::money($l['amount'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="2" class="text-end"><?= e(__('Subtotal')) ?></td><td class="text-end"><?= e(Marketplace::money($b['subtotal'])) ?></td></tr>
            <tr><td colspan="2" class="text-end"><?= e((string) Settings::platform('billing_tax_label', 'GST')) ?> <?= e(rtrim(rtrim((string) $b['tax_percent'], '0'), '.')) ?>%</td><td class="text-end"><?= e(Marketplace::money($b['tax'])) ?></td></tr>
            <tr class="fw-bold"><td colspan="2" class="text-end"><?= e(__('Total')) ?></td><td class="text-end"><?= e(Marketplace::money($b['total'])) ?></td></tr>
            <?php if ($b['paid_at']): ?><tr><td colspan="3" class="text-end small text-success"><?= e(__('Paid on :d', ['d' => date('d M Y', (int) strtotime((string) $b['paid_at']))])) ?> · <?= e((string) $b['payment_ref']) ?></td></tr><?php endif; ?>
          </tfoot>
        </table></div>
      </div>
    </section>
    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
      <button type="button" class="btn btn-outline-secondary" data-print><i class="bi bi-printer"></i> <?= e(__('Print')) ?></button>
      <?php if (in_array($b['status'], ['scheduled', 'running', 'completed'], true) || ($b['paid_at'] && array_filter($lines, fn ($l) => $l['campaign_id']))): ?>
        <a class="btn btn-primary" href="<?= e(MarketplacePortal::url('report.php', ['id' => $id])) ?>"><i class="bi bi-bar-chart"></i> <?= e(__('Proof of play report')) ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($creative): ?><section class="mb-3"><?= MarketplacePortal::tvFrame($creative, (string) ($lines[0]['hotel_name'] ?? '')) ?></section><?php endif; ?>
    <section class="card card-body mb-3">
      <h2 class="h6"><?= e(__('History')) ?></h2>
      <ol class="mkt-timeline">
        <?php foreach ($events as $ev): ?>
          <li><strong><?= e(Marketplace::statusLabel((string) $ev['status'])) ?></strong> <small class="text-muted"><?= e(date('d M H:i', (int) strtotime((string) $ev['created_at']))) ?></small>
            <?php if ($ev['note']): ?><br><small><?= e($ev['note']) ?></small><?php endif; ?></li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php if (in_array($b['status'], ['draft', 'submitted', 'awaiting_payment'], true)): ?>
    <form method="post" class="card card-body gap-2 d-print-none" data-confirm="<?= e(__('Cancel this order?')) ?>">
      <?= Csrf::field() ?><input type="hidden" name="op" value="cancel">
      <label class="form-label mb-0" for="c_reason"><?= e(__('Reason (optional)')) ?></label>
      <input class="form-control" id="c_reason" name="reason" maxlength="300">
      <button class="btn btn-outline-danger"><?= e(__('Cancel order')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
MarketplacePortal::footer();
