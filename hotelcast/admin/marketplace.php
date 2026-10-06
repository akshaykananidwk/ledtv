<?php
declare(strict_types=1);
/**
 * Ad marketplace (#19) — hotel side:
 *  - Requests: paid marketplace bookings waiting for this hotel's approval (approve → campaign is created
 *    in the Ads module; reject with reason, optionally "ad not acceptable").
 *  - Bookings: approved / running / finished marketplace ads in this hotel.
 *  - Earnings: revenue share of paid bookings per period + monthly payout statements (super admin).
 *  - Settings: "Sell ad space" opt-in, price model and prices, max ads per loop, categories, approval (super admin).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('marketplace.manage');
$pageTitle = __('Ad marketplace');
$activeNav = 'marketplace';
$extraStyles = ['advertise/advertise.css'];
if (!Ads::enabled()) {
    http_response_code(403);
    require __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">' . e(__('Advertising is not included in your plan.')) . '</div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}
Csrf::check();
$canSettings = Auth::can('marketplace.settings');
$hid = Tenant::id();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $tab = 'requests';
    try {
        if ($op === 'settings_save') {
            require_can('marketplace.settings');
            $tab = 'settings';
            [$data, $errors] = Marketplace::validateHotelSettings($_POST);
            if ($errors) {
                flash_errors($errors);
            } else {
                Marketplace::saveHotelSettings($data, Auth::id());
                ActivityLog::add('mkt_settings', 'hotel', $hid, $data['enabled'] ? 'Selling ad space' : 'Not selling ad space');
                flash('success', __('Saved.'));
            }
        } elseif ($op === 'approve') {
            $line = Marketplace::hotelLine(req_int('id', $_POST));
            if ($line) {
                Marketplace::approveLine((int) $line['id'], $hid, Auth::id(), (string) ($user['username'] ?? 'hotel'));
                ActivityLog::add('mkt_approve', 'mkt_booking', (int) $line['booking_id']);
                flash('success', __('Approved. The ad now runs as a campaign in Ads & Sponsors.'));
            }
        } elseif ($op === 'reject') {
            $line = Marketplace::hotelLine(req_int('id', $_POST));
            if ($line) {
                Marketplace::rejectLine((int) $line['id'], $hid, req_str('reason', $_POST, 500), Auth::id(), (string) ($user['username'] ?? 'hotel'), !empty($_POST['bad_creative']));
                ActivityLog::add('mkt_reject', 'mkt_booking', (int) $line['booking_id'], req_str('reason', $_POST, 200));
                flash('success', __('Rejected. The advertiser was informed.'));
            }
            $tab = req_str('tab', $_POST, 20) ?: 'requests';
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('marketplace.php', ['tab' => $tab]));
}

$tab = in_array($_GET['tab'] ?? '', ['requests', 'bookings', 'earnings', 'settings'], true) ? (string) $_GET['tab'] : 'requests';
if (in_array($tab, ['earnings', 'settings'], true) && !$canSettings) {
    $tab = 'requests';
}
$mkt = Marketplace::hotelSettings();
$pending = Marketplace::hotelLines(['pending'], ['paid', 'scheduled', 'running']);
$upcoming = Marketplace::hotelLines(['pending'], ['submitted', 'awaiting_payment']);

// Statement of this hotel (another hotel's id → 404 before any output).
$payout = $tab === 'earnings' && req_int('payout', $_GET) ? Tenant::find('mkt_payouts', req_int('payout', $_GET)) : null;

/** Creative preview (TV frame) for a line row. */
$preview = static function (array $l): string {
    if (!$l['creative_id']) {
        return '<div class="text-muted small">' . e(__('The ad was removed.')) . '</div>';
    }
    return MarketplacePortal::tvFrame(['type' => $l['creative_type'], 'title' => $l['creative_title'], 'file_path' => $l['creative_path'],
        'body' => $l['creative_body'], 'settings' => $l['creative_settings']]);
};

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-shop-window"></i> <?= e(__('Ad marketplace')) ?></h1>
    <p class="text-muted mb-0"><?= e(__('Local businesses book ads on your TVs; you earn :p% of every paid booking.', ['p' => rtrim(rtrim(number_format(Marketplace::hotelSharePct(), 2, '.', ''), '0'), '.')])) ?></p></div>
  <?= $mkt['enabled'] ? '<span class="badge text-bg-success fs-6">' . e(__('Selling ad space')) . '</span>' : '<span class="badge text-bg-secondary fs-6">' . e(__('Not selling ad space')) . '</span>' ?>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'requests' ? ' active' : '' ?>" href="<?= e(admin_url('marketplace.php', ['tab' => 'requests'])) ?>"><?= e(__('Requests')) ?><?php if ($pending): ?> <span class="badge text-bg-danger"><?= count($pending) ?></span><?php endif; ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'bookings' ? ' active' : '' ?>" href="<?= e(admin_url('marketplace.php', ['tab' => 'bookings'])) ?>"><?= e(__('Bookings')) ?></a></li>
  <?php if ($canSettings): ?>
  <li class="nav-item"><a class="nav-link<?= $tab === 'earnings' ? ' active' : '' ?>" href="<?= e(admin_url('marketplace.php', ['tab' => 'earnings'])) ?>"><?= e(__('Earnings')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>" href="<?= e(admin_url('marketplace.php', ['tab' => 'settings'])) ?>"><?= e(__('Settings')) ?></a></li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'requests'): ?>
  <?php if (!$mkt['enabled'] && !$pending): ?>
    <div class="hint-box mb-3"><i class="bi bi-info-circle"></i> <?= e(__('Turn on "Sell ad space" in Settings to appear in the advertiser marketplace.')) ?></div>
  <?php endif; ?>
  <?php if (!$pending): ?><p class="text-muted"><?= e(__('No requests waiting for approval.')) ?></p><?php endif; ?>
  <div class="row g-3">
  <?php foreach ($pending as $l): ?>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
      <div class="d-flex justify-content-between gap-2"><div><strong><?= e($l['title']) ?></strong><div class="small text-muted"><?= e($l['business_name']) ?> · <?= e((string) $l['number']) ?> · <?= e(Marketplace::categoryLabel((string) $l['category'])) ?></div></div>
        <span class="fw-semibold"><?= e(Marketplace::money($l['hotel_share'])) ?><br><small class="text-muted fw-normal"><?= e(__('your share')) ?></small></span></div>
      <div class="small my-2"><i class="bi bi-calendar-range"></i> <?= e(date('d M', (int) strtotime((string) $l['start_date']))) ?> – <?= e(date('d M Y', (int) strtotime((string) $l['end_date']))) ?>
        <?php if ($l['daily_start']): ?> · <?= e(substr((string) $l['daily_start'], 0, 5)) ?>–<?= e(substr((string) $l['daily_end'], 0, 5)) ?><?php endif; ?>
        · <?= e(Marketplace::modelLabel((string) $l['pricing_model'])) ?><?= $l['impressions'] ? ' (' . e(number_format((int) $l['impressions'])) . ')' : '' ?></div>
      <div style="max-width:420px"><?= $preview($l) ?></div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <button class="btn btn-success"><i class="bi bi-check-lg"></i> <?= e(__('Approve')) ?></button></form>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= Csrf::field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <input class="form-control form-control-sm" name="reason" required maxlength="500" placeholder="<?= e(__('Reason for rejection')) ?>" aria-label="<?= e(__('Reason for rejection')) ?>" style="max-width:220px">
          <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="bad_creative" value="1"> <?= e(__('ad not acceptable')) ?></label>
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> <?= e(__('Reject')) ?></button></form>
      </div>
    </div></div></div>
  <?php endforeach; ?>
  </div>
  <?php if ($upcoming): ?>
    <h2 class="h6 mt-4"><?= e(__('Ordered, not paid yet')) ?></h2>
    <div class="table-responsive"><table class="table table-sm">
      <thead><tr><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th><?= e(__('Dates')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th></tr></thead>
      <tbody><?php foreach ($upcoming as $l): ?><tr><td><?= e($l['title']) ?> <small class="text-muted"><?= e((string) $l['number']) ?></small></td><td><?= e($l['business_name']) ?></td>
        <td><?= e($l['start_date']) ?> – <?= e($l['end_date']) ?></td><td class="text-end"><?= e(Marketplace::money($l['amount'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'bookings'): ?>
  <?php $rows = Marketplace::hotelLines(['approved', 'delivered', 'rejected', 'cancelled'], ['paid', 'scheduled', 'running', 'completed', 'rejected', 'cancelled']); ?>
  <?php if (!$rows): ?><p class="text-muted"><?= e(__('No marketplace bookings yet.')) ?></p><?php endif; ?>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th><?= e(__('Dates')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Your share')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): ?>
      <tr><td><?= e($l['title']) ?><br><small class="text-muted"><?= e((string) $l['number']) ?></small></td><td><?= e($l['business_name']) ?></td>
        <td class="small"><?= e($l['start_date']) ?> – <?= e($l['end_date']) ?></td>
        <td><?= Marketplace::statusBadge(in_array($l['status'], ['approved'], true) ? (string) $l['booking_status'] : (string) $l['status']) ?><?php if ($l['reason'] && in_array($l['status'], ['rejected', 'cancelled'], true)): ?><br><small class="text-muted"><?= e($l['reason']) ?></small><?php endif; ?></td>
        <td class="text-end"><?= e(Marketplace::money($l['hotel_share'])) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($l['campaign_id'] && Auth::can('ads.manage')): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('sponsor_report.php', ['campaign_id' => $l['campaign_id']])) ?>"><i class="bi bi-bar-chart"></i> <?= e(__('Report')) ?></a><?php endif; ?>
          <?php if ($l['status'] === 'approved' && $l['booking_status'] !== 'completed'): ?>
            <form method="post" class="d-inline-flex gap-1" data-confirm="<?= e(__('Stop this ad? The advertiser gets a refund for your hotel.')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="tab" value="bookings"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <input class="form-control form-control-sm" name="reason" required maxlength="500" placeholder="<?= e(__('Reason')) ?>" aria-label="<?= e(__('Reason')) ?>" style="width:9rem">
              <button class="btn btn-sm btn-outline-danger"><?= e(__('Stop')) ?></button></form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'earnings'): ?>
  <?php
  [$from, $to] = Analytics::range($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? date('Y-m-d'));
  $earn = Marketplace::hotelEarnings($from, $to);
  $payouts = Marketplace::hotelPayouts();
  ?>
  <form class="row g-2 align-items-end mb-3" method="get"><input type="hidden" name="tab" value="earnings">
    <div class="col-6 col-md-3"><label class="form-label" for="e_from"><?= e(__('From')) ?></label><input class="form-control" type="date" id="e_from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="e_to"><?= e(__('To')) ?></label><input class="form-control" type="date" id="e_to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><?= e(__('Show')) ?></button></div>
  </form>
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card card-body"><div class="small text-muted"><?= e(__('Ad sales')) ?></div><div class="fs-4" data-gross><?= e(Marketplace::money($earn['gross'])) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card card-body"><div class="small text-muted"><?= e(__('Your earnings')) ?></div><div class="fs-4 text-success" data-share><?= e(Marketplace::money($earn['share'])) ?></div></div></div>
  </div>
  <div class="table-responsive mb-4"><table class="table table-sm">
    <thead><tr><th><?= e(__('Paid on')) ?></th><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th><th class="text-end"><?= e(__('Share')) ?></th><th><?= e(__('Payout')) ?></th></tr></thead>
    <tbody><?php foreach ($earn['rows'] as $r): ?><tr><td><?= e(date('d M Y', (int) strtotime((string) $r['paid_at']))) ?></td><td><?= e($r['title']) ?> <small class="text-muted"><?= e((string) $r['number']) ?></small></td>
      <td><?= e($r['business_name']) ?></td><td class="text-end"><?= e(Marketplace::money($r['amount'])) ?></td><td class="text-end"><?= e(Marketplace::money($r['hotel_share'])) ?> <small class="text-muted"><?= e(rtrim(rtrim((string) $r['hotel_share_pct'], '0'), '.')) ?>%</small></td>
      <td><?= $r['payout_period'] ? e($r['payout_period']) . ' ' . Marketplace::statusBadge((string) $r['payout_status']) : '<span class="text-muted small">' . e(__('next statement')) . '</span>' ?></td></tr><?php endforeach; ?>
    <?php if (!$earn['rows']): ?><tr><td colspan="6" class="text-muted"><?= e(__('No earnings in this period.')) ?></td></tr><?php endif; ?></tbody>
  </table></div>
  <h2 class="h5"><?= e(__('Payout statements')) ?></h2>
  <div class="table-responsive"><table class="table table-sm">
    <thead><tr><th><?= e(__('Month')) ?></th><th class="text-end"><?= e(__('Bookings')) ?></th><th class="text-end"><?= e(__('Ad sales')) ?></th><th class="text-end"><?= e(__('Payout')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($payouts as $p): ?><tr><td><?= e($p['period']) ?></td><td class="text-end"><?= (int) $p['lines_count'] ?></td><td class="text-end"><?= e(Marketplace::money($p['gross'])) ?></td><td class="text-end fw-semibold"><?= e(Marketplace::money($p['amount'])) ?></td>
      <td><?= Marketplace::statusBadge((string) $p['status']) ?><?= $p['payment_ref'] ? ' <small class="text-muted">' . e($p['payment_ref']) . '</small>' : '' ?></td>
      <td><a class="btn btn-sm btn-light border" href="<?= e(admin_url('marketplace.php', ['tab' => 'earnings', 'payout' => $p['id']])) ?>"><?= e(__('Statement')) ?></a></td></tr><?php endforeach; ?>
    <?php if (!$payouts): ?><tr><td colspan="6" class="text-muted"><?= e(__('No statements yet.')) ?></td></tr><?php endif; ?></tbody>
  </table></div>
  <?php if ($payout): ?>
    <div class="card mt-3" id="statement"><div class="card-body">
      <h3 class="h6"><?= e(__('Statement :p', ['p' => $payout['period']])) ?> · <?= Marketplace::statusBadge((string) $payout['status']) ?></h3>
      <table class="table table-sm mb-0"><thead><tr><th><?= e(__('Order')) ?></th><th><?= e(__('Advertiser')) ?></th><th class="text-end"><?= e(__('Amount')) ?></th><th class="text-end"><?= e(__('Share')) ?></th></tr></thead>
        <tbody><?php foreach (Marketplace::payoutLines((int) $payout['id'], $hid) as $r): ?><tr><td><?= e((string) $r['number']) ?> <?= e($r['title']) ?></td><td><?= e($r['business_name']) ?></td><td class="text-end"><?= e(Marketplace::money($r['amount'])) ?></td><td class="text-end"><?= e(Marketplace::money($r['hotel_share'])) ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-bold"><td colspan="3" class="text-end"><?= e(__('Payout')) ?></td><td class="text-end"><?= e(Marketplace::money($payout['amount'])) ?></td></tr></tfoot></table>
    </div></div>
  <?php endif; ?>

<?php else: ?>
  <?php $allowed = json_decode((string) $mkt['allowed_categories'], true) ?: []; $blocked = json_decode((string) $mkt['blocked_categories'], true) ?: []; ?>
  <form method="post" class="card" style="max-width:860px"><div class="card-body row g-3">
    <?= Csrf::field() ?><input type="hidden" name="op" value="settings_save">
    <div class="col-12"><div class="form-check form-switch fs-5"><input class="form-check-input" type="checkbox" role="switch" id="m_en" name="enabled" value="1"<?= $mkt['enabled'] ? ' checked' : '' ?>>
      <label class="form-check-label" for="m_en"><?= e(__('Sell ad space')) ?></label></div>
      <div class="form-text"><?= e(__('Your hotel appears in the advertiser marketplace with its name, city, number of TVs and rooms and your prices. Contacts and room numbers are never shown.')) ?></div></div>
    <div class="col-md-4"><label class="form-label" for="m_model"><?= e(__('Price model')) ?></label>
      <select class="form-select" id="m_model" name="pricing_model">
        <?php foreach (['per_day', 'cpm', 'both'] as $m): ?><option value="<?= e($m) ?>"<?= $mkt['pricing_model'] === $m ? ' selected' : '' ?>><?= e(Marketplace::modelLabel($m)) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-4"><label class="form-label" for="m_pd"><?= e(__('Price per TV per day')) ?></label>
      <input class="form-control" id="m_pd" type="number" step="0.01" min="0" name="price_per_tv_day" value="<?= e((string) $mkt['price_per_tv_day']) ?>"></div>
    <div class="col-6 col-md-4"><label class="form-label" for="m_cpm"><?= e(__('Price per 1000 impressions')) ?></label>
      <input class="form-control" id="m_cpm" type="number" step="0.01" min="0" name="price_cpm" value="<?= e((string) $mkt['price_cpm']) ?>"></div>
    <div class="col-6 col-md-4"><label class="form-label" for="m_max"><?= e(__('Max marketplace ads per loop')) ?></label>
      <input class="form-control" id="m_max" type="number" min="1" max="<?= Marketplace::MAX_ADS_PER_LOOP ?>" name="max_ads_per_loop" value="<?= (int) $mkt['max_ads_per_loop'] ?>">
      <div class="form-text"><?= e(__('How many marketplace ads may run at the same time.')) ?></div></div>
    <div class="col-6 col-md-4"><label class="form-label" for="m_appr"><?= e(__('Approval')) ?></label>
      <select class="form-select" id="m_appr" name="approval">
        <option value="manual"<?= $mkt['approval'] === 'manual' ? ' selected' : '' ?>><?= e(__('I approve every ad')) ?></option>
        <option value="auto"<?= $mkt['approval'] === 'auto' ? ' selected' : '' ?>><?= e(__('Approve automatically after payment')) ?></option>
      </select></div>
    <div class="col-md-4"><label class="form-label"><?= e(__('Revenue share')) ?></label>
      <div class="form-control-plaintext"><?= e(__('You :h% · platform :p%', ['h' => rtrim(rtrim(number_format(Marketplace::hotelSharePct(), 2, '.', ''), '0'), '.'), 'p' => rtrim(rtrim(number_format(100 - Marketplace::hotelSharePct(), 2, '.', ''), '0'), '.')])) ?></div></div>
    <div class="col-md-6"><fieldset><legend class="form-label fs-6"><?= e(__('Allowed categories')) ?> <small class="text-muted">(<?= e(__('none = all')) ?>)</small></legend>
      <?php foreach (Marketplace::categories() as $c): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed_categories[]" id="ma_<?= e($c) ?>" value="<?= e($c) ?>"<?= in_array($c, $allowed, true) ? ' checked' : '' ?>><label class="form-check-label" for="ma_<?= e($c) ?>"><?= e(Marketplace::categoryLabel($c)) ?></label></div><?php endforeach; ?>
    </fieldset></div>
    <div class="col-md-6"><fieldset><legend class="form-label fs-6"><?= e(__('Blocked categories')) ?></legend>
      <?php foreach (Marketplace::categories() as $c): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="blocked_categories[]" id="mb_<?= e($c) ?>" value="<?= e($c) ?>"<?= in_array($c, $blocked, true) ? ' checked' : '' ?>><label class="form-check-label" for="mb_<?= e($c) ?>"><?= e(Marketplace::categoryLabel($c)) ?></label></div><?php endforeach; ?>
    </fieldset></div>
    <div class="col-12"><label class="form-label" for="m_desc"><?= e(__('Short description for advertisers')) ?></label>
      <textarea class="form-control" id="m_desc" name="description" rows="2" maxlength="500" placeholder="<?= e(__('e.g. 40 rooms near the temple, pilgrims and families')) ?>"><?= e((string) $mkt['description']) ?></textarea></div>
    <div class="col-12"><div class="hint-box small"><i class="bi bi-info-circle"></i> <?= e(__('Approved ads become normal campaigns in Ads & Sponsors (all rooms, after every 3 items). Ad rules for advertisers:')) ?>
      <div class="mt-1"><?= nl2br(e(Marketplace::setting('platform_mkt_rules'))) ?></div></div></div>
    <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
  </div></form>
<?php endif; ?>
<?php
require __DIR__ . '/partials/footer.php';
