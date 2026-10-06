<?php
/**
 * Platform → Ad marketplace (#19): overview, orders (review, mark paid, reject / cancel with refund note,
 * reject creative), advertisers (approve / suspend), hotel payouts (monthly statements, mark paid) and
 * marketplace settings (revenue share, tax, bank / UPI payment details, sign-up rules, content rules).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();
$pageTitle = __('Ad marketplace');
$activeNav = 'platform_marketplace';
$extraStyles = ['advertise/advertise.css'];
$actor = 'platform:' . ($user['username'] ?? '');

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $id = req_int('id', $_POST);
    $back = admin_url('platform_marketplace.php', array_filter(['tab' => req_str('tab', $_POST, 20) ?: null, 'id' => req_int('back_id', $_POST) ?: null]));
    try {
        switch ($op) {
            case 'review_ok':
                Marketplace::approveReview($id, $actor);
                ActivityLog::add('mkt_review', 'mkt_booking', $id);
                flash('success', __('Order approved. The advertiser can pay now.'));
                break;
            case 'paid':
                Marketplace::markPaid($id, req_str('payment_ref', $_POST, 120), req_str('payment_method', $_POST, 40), req_str('paid_at', $_POST, 20) ?: null, Auth::id(), $actor);
                ActivityLog::add('mkt_paid', 'mkt_booking', $id, req_str('payment_ref', $_POST, 120));
                flash('success', __('Payment recorded.'));
                break;
            case 'reject':
            case 'cancel':
                if (req_str('reason', $_POST, 500) === '') {
                    throw new InvalidArgumentException(__('Give a reason.'));
                }
                if ($op === 'reject' && !empty($_POST['reject_creative'])) {
                    $b = Marketplace::findBooking($id);
                    if ($b && $b['creative_id']) {
                        Marketplace::rejectCreative((int) $b['creative_id'], req_str('reason', $_POST, 255), $actor);
                    }
                    $b = Marketplace::findBooking($id);
                    if ($b && !in_array($b['status'], Marketplace::FINAL_STATUSES, true)) {
                        Marketplace::rejectBooking($id, req_str('reason', $_POST, 500), req_str('refund_note', $_POST, 500), $actor);
                    } elseif ($b && req_str('refund_note', $_POST, 500) !== '') {
                        DB::update('mkt_bookings', ['refund_note' => req_str('refund_note', $_POST, 500)], 'id = :id', ['id' => $id]);
                    }
                } elseif ($op === 'reject') {
                    Marketplace::rejectBooking($id, req_str('reason', $_POST, 500), req_str('refund_note', $_POST, 500), $actor);
                } else {
                    Marketplace::cancelBooking($id, req_str('reason', $_POST, 500), req_str('refund_note', $_POST, 500), $actor);
                }
                ActivityLog::add('mkt_' . $op, 'mkt_booking', $id, req_str('reason', $_POST, 200));
                flash('success', $op === 'reject' ? __('Order rejected.') : __('Order cancelled.'));
                break;
            case 'adv_status':
                Marketplace::setAdvertiserStatus($id, req_str('status', $_POST, 20), req_str('note', $_POST, 255));
                ActivityLog::add('mkt_advertiser', 'mkt_advertiser', $id, req_str('status', $_POST, 20));
                flash('success', __('Saved.'));
                break;
            case 'payouts':
                $r = Marketplace::generatePayouts(req_str('period', $_POST, 7));
                ActivityLog::add('mkt_payouts', 'mkt_payout', null, req_str('period', $_POST, 7) . ': ' . count($r));
                flash('success', __(':n statements created / updated.', ['n' => count($r)]));
                break;
            case 'payout_paid':
                Marketplace::markPayoutPaid($id, req_str('payment_ref', $_POST, 120), req_str('paid_at', $_POST, 20) ?: null);
                ActivityLog::add('mkt_payout_paid', 'mkt_payout', $id, req_str('payment_ref', $_POST, 120));
                flash('success', __('Payout marked as paid.'));
                break;
            case 'settings':
                $errors = [];
                $share = (float) str_replace(',', '.', req_str('platform_mkt_hotel_share', $_POST, 10));
                $tax = (float) str_replace(',', '.', req_str('platform_mkt_tax_percent', $_POST, 10));
                $upi = trim(req_str('platform_mkt_upi_id', $_POST, 100));
                if ($share < 0 || $share > 100) {
                    $errors[] = __('Hotel share must be between 0 and 100 %.');
                }
                if ($tax < 0 || $tax > 50) {
                    $errors[] = __('Tax must be between 0 and 50 %.');
                }
                if ($upi !== '' && !Marketplace::validUpi($upi)) {
                    $errors[] = __('Invalid UPI ID (e.g. business@okbank).');
                }
                if ($errors) {
                    throw new InvalidArgumentException(implode("\n", $errors));
                }
                $vals = [
                    'platform_mkt_enabled' => !empty($_POST['platform_mkt_enabled']) ? '1' : '0',
                    'platform_mkt_hotel_share' => number_format($share, 2, '.', ''),
                    'platform_mkt_tax_percent' => number_format($tax, 2, '.', ''),
                    'platform_mkt_bank_text' => req_str('platform_mkt_bank_text', $_POST, 1000),
                    'platform_mkt_upi_id' => $upi,
                    'platform_mkt_upi_name' => preg_replace('/[^\p{L}\p{N} .&-]/u', '', req_str('platform_mkt_upi_name', $_POST, 60)) ?? '',
                    'platform_mkt_expire_days' => (string) max(1, min(60, req_int('platform_mkt_expire_days', $_POST))),
                    'platform_mkt_signup_otp' => !empty($_POST['platform_mkt_signup_otp']) ? '1' : '0',
                    'platform_mkt_signup_approval' => !empty($_POST['platform_mkt_signup_approval']) ? '1' : '0',
                    'platform_mkt_review' => !empty($_POST['platform_mkt_review']) ? '1' : '0',
                    'platform_mkt_rules' => req_str('platform_mkt_rules', $_POST, 3000),
                    'platform_mkt_categories' => preg_replace('/[^a-z0-9_, ]/', '', strtolower(req_str('platform_mkt_categories', $_POST, 600))) ?? '',
                    'platform_mkt_max_image_mb' => (string) max(1, min(25, req_int('platform_mkt_max_image_mb', $_POST))),
                    'platform_mkt_max_video_mb' => (string) max(1, min(500, req_int('platform_mkt_max_video_mb', $_POST))),
                    'platform_mkt_max_days' => (string) max(1, min(366, req_int('platform_mkt_max_days', $_POST))),
                ];
                foreach ($vals as $k => $v) {
                    Settings::setPlatform($k, $v);
                }
                ActivityLog::add('mkt_settings', 'settings', null, 'Marketplace settings');
                flash('success', __('Saved.'));
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

$tab = in_array($_GET['tab'] ?? '', ['overview', 'orders', 'advertisers', 'payouts', 'settings'], true) ? (string) $_GET['tab'] : 'overview';
$detailId = req_int('id', $_GET);
if ($detailId) {
    $tab = 'orders';
}
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><h1><i class="bi bi-shop"></i> <?= e(__('Ad marketplace')) ?></h1>
  <a class="btn btn-light border" href="<?= e(base_url('advertise/')) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= e(__('Advertiser portal')) ?></a></div>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['overview' => __('Overview'), 'orders' => __('Orders'), 'advertisers' => __('Advertisers'), 'payouts' => __('Payouts'), 'settings' => __('Settings')] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('platform_marketplace.php', ['tab' => $k])) ?>"><?= e($l) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): $o = Marketplace::overview(); ?>
  <div class="row g-3 mb-3">
    <?php foreach ([
        [__('Paid ad sales'), Marketplace::money($o['gross']), 'gross'], [__('Platform share'), Marketplace::money($o['platform_share']), 'platform'],
        [__('Hotel share'), Marketplace::money($o['hotel_share']), 'hotels'], [__('Unpaid hotel payouts'), Marketplace::money($o['unpaid_payouts']), 'payouts'],
        [__('Awaiting payment'), (string) ($o['by_status']['awaiting_payment'] + $o['by_status']['submitted']), 'awaiting'], [__('Waiting for hotel approval'), (string) $o['pending_hotel'], 'pending_hotel'],
        [__('Running'), (string) $o['by_status']['running'], 'running'], [__('Hotels selling'), (string) $o['hotels_selling'], 'hotels_selling'],
        [__('Advertisers'), $o['advertisers'] . ($o['advertisers_pending'] ? ' (+' . $o['advertisers_pending'] . ' ' . __('pending') . ')' : ''), 'advertisers'],
    ] as [$label, $val, $key]): ?>
      <div class="col-6 col-md-4 col-xl-3"><div class="card card-body h-100"><div class="small text-muted"><?= e($label) ?></div><div class="fs-4" data-kpi="<?= e($key) ?>"><?= e($val) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <?php $todo = array_merge(Marketplace::platformBookings('submitted'), Marketplace::platformBookings('awaiting_payment')); ?>
  <h2 class="h5"><?= e(__('Pending reviews and payments')) ?></h2>
  <?php if (!$todo): ?><p class="text-muted"><?= e(__('Nothing to do.')) ?></p><?php endif; ?>
  <div class="list-group mb-3"><?php foreach ($todo as $b): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between flex-wrap gap-2" href="<?= e(admin_url('platform_marketplace.php', ['id' => $b['id']])) ?>">
      <span><strong><?= e((string) $b['number']) ?></strong> <?= e($b['business_name']) ?> · <?= e($b['title']) ?></span>
      <span><?= Marketplace::statusBadge((string) $b['status']) ?> <?= e(Marketplace::money($b['total'])) ?></span></a>
  <?php endforeach; ?></div>

<?php elseif ($tab === 'orders' && $detailId): ?>
  <?php $b = Marketplace::findBooking($detailId);
  if (!$b) {
      echo '<div class="alert alert-warning">' . e(__('Not found')) . '</div>';
  } else {
      $lines = Marketplace::lines($detailId);
      $cr = $b['creative_id'] ? DB::one('SELECT * FROM mkt_creatives WHERE id = :id', ['id' => (int) $b['creative_id']]) : null; ?>
  <a class="btn btn-sm btn-light border mb-2" href="<?= e(admin_url('platform_marketplace.php', ['tab' => 'orders'])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2"><h2 class="h5 mb-0"><?= e((string) ($b['number'] ?: '#' . $b['id'])) ?> · <?= e($b['title']) ?></h2><?= Marketplace::statusBadge((string) $b['status']) ?></div>
        <div class="small text-muted mb-2"><?= e($b['business_name']) ?> · <?= e($b['contact_name']) ?> · <?= e($b['mobile']) ?> · <?= e($b['advertiser_email']) ?></div>
        <div class="small mb-2"><?= e($b['start_date']) ?> – <?= e($b['end_date']) ?><?= $b['daily_start'] ? ' · ' . e(substr((string) $b['daily_start'], 0, 5) . '–' . substr((string) $b['daily_end'], 0, 5)) : '' ?> · <?= e(Marketplace::categoryLabel((string) $b['category'])) ?> · <?= e(Marketplace::modelLabel((string) $b['pricing_model'])) ?></div>
        <?php if ($b['status_reason']): ?><div class="alert alert-secondary py-2 small"><?= e($b['status_reason']) ?><?= $b['refund_note'] ? ' — ' . e($b['refund_note']) : '' ?></div><?php endif; ?>
        <table class="table table-sm"><thead><tr><th><?= e(__('Hotel')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th><th class="text-end"><?= e(__('Hotel share')) ?></th></tr></thead>
          <tbody><?php foreach ($lines as $l): ?><tr><td><?= e($l['hotel_name']) ?> <small class="text-muted"><?= e((string) $l['hotel_city']) ?></small><?= $l['campaign_id'] ? ' <small class="text-muted">#' . (int) $l['campaign_id'] . '</small>' : '' ?></td>
            <td><?= Marketplace::statusBadge((string) $l['status']) ?><?= $l['reason'] ? '<br><small class="text-muted">' . e($l['reason']) . '</small>' : '' ?></td>
            <td class="text-end"><?= e(Marketplace::money($l['amount'])) ?></td><td class="text-end"><?= e(Marketplace::money($l['hotel_share'])) ?></td></tr><?php endforeach; ?></tbody>
          <tfoot><tr><td colspan="2" class="text-end"><?= e(__('Subtotal')) ?> / <?= e(__('Tax')) ?></td><td class="text-end" colspan="2"><?= e(Marketplace::money($b['subtotal'])) ?> / <?= e(Marketplace::money($b['tax'])) ?></td></tr>
            <tr class="fw-bold"><td colspan="2" class="text-end"><?= e(__('Total')) ?></td><td class="text-end" colspan="2"><?= e(Marketplace::money($b['total'])) ?></td></tr>
            <?php if ((float) $b['refund_amount'] > 0): ?><tr class="text-danger"><td colspan="2" class="text-end"><?= e(__('Refund')) ?></td><td class="text-end" colspan="2"><?= e(Marketplace::money($b['refund_amount'])) ?></td></tr><?php endif; ?>
            <?php if ($b['paid_at']): ?><tr><td colspan="4" class="text-end small text-success"><?= e(__('Paid on :d', ['d' => date('d M Y', (int) strtotime((string) $b['paid_at']))])) ?> · <?= e((string) $b['payment_ref']) ?> <?= e((string) $b['payment_method']) ?></td></tr><?php endif; ?></tfoot>
        </table>
      </div></div>
      <?php if ($b['status'] === 'submitted'): ?>
        <form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="op" value="review_ok"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="back_id" value="<?= (int) $b['id'] ?>">
          <button class="btn btn-success"><i class="bi bi-check2-circle"></i> <?= e(__('Ad OK — ask for payment')) ?></button></form>
      <?php endif; ?>
      <?php if (in_array($b['status'], ['submitted', 'awaiting_payment'], true)): ?>
        <form method="post" class="card card-body mb-3 row g-2 flex-row"><?= Csrf::field() ?><input type="hidden" name="op" value="paid"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="back_id" value="<?= (int) $b['id'] ?>">
          <h3 class="h6 col-12"><?= e(__('Record payment')) ?> (<?= e(Marketplace::money($b['total'])) ?>)</h3>
          <div class="col-md-5"><input class="form-control" name="payment_ref" required maxlength="120" placeholder="<?= e(__('UTR / transaction id')) ?>" aria-label="<?= e(__('UTR / transaction id')) ?>"></div>
          <div class="col-6 col-md-3"><select class="form-select" name="payment_method" aria-label="<?= e(__('Method')) ?>"><option>UPI</option><option><?= e(__('Bank transfer')) ?></option><option><?= e(__('Cash')) ?></option><option><?= e(__('Cheque')) ?></option></select></div>
          <div class="col-6 col-md-4"><input class="form-control" type="date" name="paid_at" value="<?= e(date('Y-m-d')) ?>" aria-label="<?= e(__('Paid on')) ?>"></div>
          <div class="col-12"><button class="btn btn-primary"><i class="bi bi-cash-coin"></i> <?= e(__('Mark paid')) ?></button></div>
        </form>
      <?php endif; ?>
      <?php if (!in_array($b['status'], Marketplace::FINAL_STATUSES, true)): ?>
        <form method="post" class="card card-body mb-3 gap-2" data-confirm="<?= e(__('Close this order?')) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="back_id" value="<?= (int) $b['id'] ?>">
          <h3 class="h6 mb-0"><?= e(__('Reject or cancel')) ?></h3>
          <input class="form-control" name="reason" required maxlength="500" placeholder="<?= e(__('Reason (shown to the advertiser)')) ?>" aria-label="<?= e(__('Reason')) ?>">
          <input class="form-control" name="refund_note" maxlength="500" placeholder="<?= e(__('Refund note (e.g. refunded via UPI, UTR …)')) ?>" aria-label="<?= e(__('Refund note')) ?>">
          <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="reject_creative" value="1"> <?= e(__('The ad breaks the content rules (reject the ad in all its orders)')) ?></label>
          <div class="d-flex gap-2"><button class="btn btn-outline-danger" name="op" value="reject"><?= e(__('Reject')) ?></button><button class="btn btn-outline-secondary" name="op" value="cancel"><?= e(__('Cancel order')) ?></button></div>
        </form>
      <?php endif; ?>
    </div>
    <div class="col-lg-5">
      <?php if ($cr): ?><div class="mb-3"><?= MarketplacePortal::tvFrame($cr) ?><div class="small mt-1"><?= e($cr['title']) ?> · <?= e($cr['type']) ?><?= $cr['status'] === 'rejected' ? ' · <span class="text-danger">' . e(__('Rejected')) . '</span>' : '' ?></div></div><?php endif; ?>
      <div class="card card-body"><h3 class="h6"><?= e(__('History')) ?></h3><ol class="mkt-timeline">
        <?php foreach (Marketplace::events($detailId) as $ev): ?><li><strong><?= e(Marketplace::statusLabel((string) $ev['status'])) ?></strong> <small class="text-muted"><?= e(date('d M H:i', (int) strtotime((string) $ev['created_at']))) ?> · <?= e($ev['actor']) ?></small><?= $ev['note'] ? '<br><small>' . e($ev['note']) . '</small>' : '' ?></li><?php endforeach; ?>
      </ol></div>
    </div>
  </div>
  <?php } ?>

<?php elseif ($tab === 'orders'): $st = req_str('status', $_GET, 20); $q = req_str('q', $_GET, 60); $rows = Marketplace::platformBookings($st, $q); ?>
  <form class="row g-2 mb-3" method="get"><input type="hidden" name="tab" value="orders">
    <div class="col-md-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Order no., advertiser, title')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
    <div class="col-md-3"><select class="form-select" name="status" aria-label="<?= e(__('Status')) ?>"><option value=""><?= e(__('All')) ?></option>
      <?php foreach (array_diff(Marketplace::STATUSES, ['draft']) as $s): ?><option value="<?= e($s) ?>"<?= $st === $s ? ' selected' : '' ?>><?= e(Marketplace::statusLabel($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><?= e(__('Filter')) ?></button></div>
  </form>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th><?= e(__('Dates')) ?></th><th class="text-end"><?= e(__('Hotels')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Status')) ?></th></tr></thead>
    <tbody><?php foreach ($rows as $b): ?><tr><td><a href="<?= e(admin_url('platform_marketplace.php', ['id' => $b['id']])) ?>"><?= e((string) $b['number']) ?></a><br><small><?= e($b['title']) ?></small></td><td><?= e($b['business_name']) ?></td>
      <td class="small"><?= e($b['start_date']) ?> – <?= e($b['end_date']) ?></td><td class="text-end"><?= (int) $b['hotels'] ?></td><td class="text-end"><?= e(Marketplace::money($b['total'])) ?></td><td><?= Marketplace::statusBadge((string) $b['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-muted"><?= e(__('No orders.')) ?></td></tr><?php endif; ?></tbody>
  </table></div>

<?php elseif ($tab === 'advertisers'): $fs = req_str('status', $_GET, 20); ?>
  <form class="row g-2 mb-3" method="get"><input type="hidden" name="tab" value="advertisers">
    <div class="col-md-3"><select class="form-select" name="status" aria-label="<?= e(__('Status')) ?>"><option value=""><?= e(__('All')) ?></option>
      <?php foreach (['pending' => __('Waiting for approval'), 'active' => __('Active'), 'unverified' => __('Email not confirmed'), 'suspended' => __('Suspended')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $fs === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><?= e(__('Filter')) ?></button></div>
  </form>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(__('Business')) ?></th><th><?= e(__('Contact')) ?></th><th class="text-end"><?= e(__('Orders')) ?></th><th class="text-end"><?= e(__('Paid')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
    <tbody><?php foreach (Marketplace::advertisers($fs) as $a): ?><tr><td><strong><?= e($a['business_name']) ?></strong><br><small class="text-muted"><?= e((string) $a['city']) ?> · <?= e(date('d M Y', (int) strtotime((string) $a['created_at']))) ?></small></td>
      <td class="small"><?= e($a['contact_name']) ?><br><?= e($a['mobile']) ?> · <?= e($a['email']) ?></td><td class="text-end"><?= (int) $a['bookings'] ?></td><td class="text-end"><?= e(Marketplace::money($a['spent'])) ?></td>
      <td><span class="badge text-bg-<?= $a['status'] === 'active' ? 'success' : ($a['status'] === 'suspended' ? 'danger' : 'warning') ?>"><?= e($a['status']) ?></span></td>
      <td class="text-end text-nowrap">
        <?php foreach (array_diff(['active', 'suspended'], [$a['status']]) as $ns): ?>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="adv_status"><input type="hidden" name="tab" value="advertisers"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="<?= e($ns) ?>">
            <button class="btn btn-sm <?= $ns === 'active' ? 'btn-success' : 'btn-outline-danger' ?>"><?= e($ns === 'active' ? __('Activate') : __('Suspend')) ?></button></form>
        <?php endforeach; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>

<?php elseif ($tab === 'payouts'): $period = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? '')) ? (string) $_GET['period'] : ''; $stmt = req_int('payout', $_GET); ?>
  <form method="post" class="row g-2 align-items-end mb-3"><?= Csrf::field() ?><input type="hidden" name="op" value="payouts"><input type="hidden" name="tab" value="payouts">
    <div class="col-7 col-md-3"><label class="form-label" for="p_period"><?= e(__('Month')) ?></label><input class="form-control" type="month" id="p_period" name="period" value="<?= e(date('Y-m', strtotime('first day of last month'))) ?>" required></div>
    <div class="col-5 col-md-3 d-grid"><button class="btn btn-primary"><?= e(__('Create statements')) ?></button></div>
    <div class="col-12 form-text"><?= e(__('Includes every approved hotel line of orders paid until the end of the month that is not on a statement yet.')) ?></div>
  </form>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(__('Month')) ?></th><th><?= e(__('Hotel')) ?></th><th class="text-end"><?= e(__('Bookings')) ?></th><th class="text-end"><?= e(__('Ad sales')) ?></th><th class="text-end"><?= e(__('Payout')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
    <tbody><?php foreach (Marketplace::payouts($period) as $p): ?><tr><td><?= e($p['period']) ?></td><td><?= e($p['hotel_name']) ?> <small class="text-muted"><?= e((string) $p['city']) ?></small></td><td class="text-end"><?= (int) $p['lines_count'] ?></td>
      <td class="text-end"><?= e(Marketplace::money($p['gross'])) ?></td><td class="text-end fw-semibold"><?= e(Marketplace::money($p['amount'])) ?></td><td><?= Marketplace::statusBadge((string) $p['status']) ?><?= $p['payment_ref'] ? ' <small class="text-muted">' . e($p['payment_ref']) . '</small>' : '' ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_marketplace.php', ['tab' => 'payouts', 'payout' => $p['id']])) ?>"><?= e(__('Statement')) ?></a>
        <?php if ($p['status'] === 'unpaid'): ?><form method="post" class="d-inline-flex gap-1"><?= Csrf::field() ?><input type="hidden" name="op" value="payout_paid"><input type="hidden" name="tab" value="payouts"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input class="form-control form-control-sm" name="payment_ref" required maxlength="120" placeholder="<?= e(__('UTR')) ?>" aria-label="<?= e(__('UTR / transaction id')) ?>" style="width:8rem"><button class="btn btn-sm btn-success"><?= e(__('Mark paid')) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php if ($stmt && ($po = DB::one('SELECT p.*, h.name AS hotel_name FROM mkt_payouts p JOIN hotels h ON h.id = p.hotel_id WHERE p.id = :id', ['id' => $stmt]))): ?>
    <div class="card"><div class="card-body"><h3 class="h6"><?= e(__('Statement :p', ['p' => $po['period']])) ?> · <?= e($po['hotel_name']) ?> · <?= Marketplace::statusBadge((string) $po['status']) ?></h3>
      <table class="table table-sm mb-0"><thead><tr><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th><?= e(__('Paid on')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th><th class="text-end"><?= e(__('Share')) ?></th></tr></thead>
        <tbody><?php foreach (Marketplace::payoutLines((int) $po['id']) as $r): ?><tr><td><?= e((string) $r['number']) ?> <?= e($r['title']) ?></td><td><?= e($r['business_name']) ?></td><td><?= e(date('d M Y', (int) strtotime((string) $r['paid_at']))) ?></td><td class="text-end"><?= e(Marketplace::money($r['amount'])) ?></td><td class="text-end"><?= e(Marketplace::money($r['hotel_share'])) ?> (<?= e(rtrim(rtrim((string) $r['hotel_share_pct'], '0'), '.')) ?>%)</td></tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-bold"><td colspan="4" class="text-end"><?= e(__('Payout')) ?></td><td class="text-end"><?= e(Marketplace::money($po['amount'])) ?></td></tr></tfoot></table></div></div>
  <?php endif; ?>

<?php else: $v = static fn (string $k) => e(Marketplace::setting($k)); ?>
  <form method="post" class="card" style="max-width:900px"><div class="card-body row g-3"><?= Csrf::field() ?><input type="hidden" name="op" value="settings"><input type="hidden" name="tab" value="settings">
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="s_en" name="platform_mkt_enabled" value="1"<?= Marketplace::enabled() ? ' checked' : '' ?>><label class="form-check-label" for="s_en"><?= e(__('Marketplace open for advertisers')) ?></label></div></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_share"><?= e(__('Hotel share %')) ?></label><input class="form-control" id="s_share" name="platform_mkt_hotel_share" type="number" step="0.01" min="0" max="100" value="<?= $v('platform_mkt_hotel_share') ?>">
      <div class="form-text"><?= e(__('Platform keeps the rest. Fixed per order when it is paid.')) ?></div></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_tax"><?= e(__('Tax %')) ?> (<?= e((string) Settings::platform('billing_tax_label', 'GST')) ?>)</label><input class="form-control" id="s_tax" name="platform_mkt_tax_percent" type="number" step="0.01" min="0" max="50" value="<?= $v('platform_mkt_tax_percent') ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_exp"><?= e(__('Cancel unpaid orders after (days)')) ?></label><input class="form-control" id="s_exp" name="platform_mkt_expire_days" type="number" min="1" max="60" value="<?= $v('platform_mkt_expire_days') ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_days"><?= e(__('Max days per booking')) ?></label><input class="form-control" id="s_days" name="platform_mkt_max_days" type="number" min="1" max="366" value="<?= $v('platform_mkt_max_days') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="s_upi"><?= e(__('UPI ID for payments')) ?></label><input class="form-control" id="s_upi" name="platform_mkt_upi_id" maxlength="100" value="<?= $v('platform_mkt_upi_id') ?>" placeholder="business@okbank">
      <div class="form-text"><?= e(__('Shows a UPI QR with the exact amount and order number on every unpaid order.')) ?></div></div>
    <div class="col-md-6"><label class="form-label" for="s_upin"><?= e(__('Payee name')) ?></label><input class="form-control" id="s_upin" name="platform_mkt_upi_name" maxlength="60" value="<?= $v('platform_mkt_upi_name') ?>"></div>
    <div class="col-12"><label class="form-label" for="s_bank"><?= e(__('Bank transfer details')) ?></label><textarea class="form-control font-monospace" id="s_bank" name="platform_mkt_bank_text" rows="4" maxlength="1000"><?= $v('platform_mkt_bank_text') ?></textarea></div>
    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="s_otp" name="platform_mkt_signup_otp" value="1"<?= Marketplace::setting('platform_mkt_signup_otp') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="s_otp"><?= e(__('Sign-up: confirm email with a code')) ?></label></div></div>
    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="s_appr" name="platform_mkt_signup_approval" value="1"<?= Marketplace::setting('platform_mkt_signup_approval') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="s_appr"><?= e(__('Sign-up: platform approves new advertisers')) ?></label></div></div>
    <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="s_rev" name="platform_mkt_review" value="1"<?= Marketplace::setting('platform_mkt_review') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="s_rev"><?= e(__('Review every order before payment')) ?></label></div></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_img"><?= e(__('Max image MB')) ?></label><input class="form-control" id="s_img" name="platform_mkt_max_image_mb" type="number" min="1" max="25" value="<?= $v('platform_mkt_max_image_mb') ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="s_vid"><?= e(__('Max video MB')) ?></label><input class="form-control" id="s_vid" name="platform_mkt_max_video_mb" type="number" min="1" max="500" value="<?= $v('platform_mkt_max_video_mb') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="s_cat"><?= e(__('Categories (comma separated keys)')) ?></label><input class="form-control" id="s_cat" name="platform_mkt_categories" maxlength="600" value="<?= $v('platform_mkt_categories') ?>" placeholder="<?= e(implode(',', Marketplace::DEFAULT_CATEGORIES)) ?>"></div>
    <div class="col-12"><label class="form-label" for="s_rules"><?= e(__('Content rules (shown to advertisers and hotels)')) ?></label><textarea class="form-control" id="s_rules" name="platform_mkt_rules" rows="5" maxlength="3000"><?= $v('platform_mkt_rules') ?></textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
  </div></form>
<?php endif; ?>
<?php
require __DIR__ . '/partials/footer.php';
