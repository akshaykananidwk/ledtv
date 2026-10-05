<?php
/**
 * Orders & requests board (#2 #3): live room-service orders (new → accepted → preparing →
 * delivered | cancelled) and guest requests (open → done), printable KOT (?kot=<order id>).
 * Role reception+ (services.manage). Status changes go through admin/ajax.d/guests.php.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('services.manage');
if (!GuestServices::enabled()) {
    http_response_code(403);
    $GLOBALS['hc_forbidden'] = true;
    require __DIR__ . '/partials/forbidden.php';
    exit;
}

// ---------------------------------------------------------------- printable KOT
if (($kotId = req_int('kot', $_GET)) > 0) {
    $order = GuestServices::order($kotId);
    if (!$order) {
        http_response_code(404);
        exit(e(__('Order not found.')));
    }
    $room = $order['room_id'] ? DB::one('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :hid', ['id' => $order['room_id']] + hid()) : null;
    $stay = $order['stay_id'] ? DB::one('SELECT salutation, guest_name FROM guest_stays WHERE id = :id AND hotel_id = :hid', ['id' => $order['stay_id']] + hid()) : null;
    $foodMark = ['veg' => '🟢', 'nonveg' => '🔴', 'egg' => '🟡', 'none' => ''];
    ?><!DOCTYPE html>
<html lang="<?= e(I18n::lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>KOT #<?= (int) $order['id'] ?></title>
<style>
  body{font-family:system-ui,"Noto Sans Gujarati",sans-serif;max-width:80mm;margin:0 auto;padding:4mm;color:#000;font-size:13px}
  h1{font-size:18px;margin:0 0 4px;text-align:center} .c{text-align:center} hr{border:0;border-top:1px dashed #000;margin:6px 0}
  table{width:100%;border-collapse:collapse} td{padding:2px 0;vertical-align:top} td.q{width:2.5em;font-weight:700;font-size:15px} td.r{text-align:right;white-space:nowrap}
  .big{font-size:22px;font-weight:800} .notes{border:1px solid #000;padding:4px;margin-top:6px;font-weight:600}
  @media print{.np{display:none} body{padding:0}}
</style></head>
<body>
  <h1><?= e((string) Settings::get('hotel_name', '')) ?></h1>
  <div class="c"><?= e(__('Kitchen order ticket')) ?> · KOT #<?= (int) $order['id'] ?></div>
  <hr>
  <div class="c"><?= e(__('Room')) ?> <span class="big"><?= e($room['room_number'] ?? '—') ?></span></div>
  <?php if ($stay && $stay['guest_name'] !== ''): ?><div class="c"><?= e(trim($stay['salutation'] . ' ' . $stay['guest_name'])) ?></div><?php endif; ?>
  <div class="c"><?= e(date('d M Y, h:i A', (int) strtotime((string) $order['created_at']))) ?></div>
  <hr>
  <table>
    <?php foreach ($order['items'] as $i): ?>
      <tr><td class="q"><?= (int) $i['qty'] ?>×</td><td><?= e($foodMark[$i['food_type']] ?? '') ?> <?= e($i['name']) ?></td><td class="r"><?= e(money($i['line_total'], 'INR')) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table><tr><td><strong><?= e(__('Total')) ?></strong></td><td class="r"><strong><?= e(money($order['total'], 'INR')) ?></strong></td></tr></table>
  <?php if ($order['notes']): ?><div class="notes"><?= e(__('Notes')) ?>: <?= e($order['notes']) ?></div><?php endif; ?>
  <p class="c np"><button onclick="window.print()"><?= e(__('Print')) ?></button></p>
  <script>window.addEventListener('load', () => { if (!/noprint/.test(location.search)) window.print(); });</script>
</body></html>
<?php
    exit;
}

$counts = GuestServices::alertCounts();
$pageTitle = __('Orders & requests');
$activeNav = 'guest_orders';
$extraScripts = ['js/guests-orders.js'];
$extraStyles = ['css/guests-admin.css'];
require __DIR__ . '/partials/header.php';
$statusLabels = [
    'new' => __('New'), 'accepted' => __('Accepted'), 'preparing' => __('Preparing'), 'delivered' => __('Delivered'), 'cancelled' => __('Cancelled'),
    'open' => __('Open'), 'done' => __('Done'),
];
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-bell"></i> <?= e(__('Orders & requests')) ?></h1>
    <p class="lead-sm"><?= e(__('Room-service orders and guest requests arrive here live. New ones play a sound.')) ?></p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <div class="form-check form-switch m-0" title="<?= e(__('Show a short message on the room TV when you change the status')) ?>">
      <input class="form-check-input" type="checkbox" id="goNotifyTv" <?= Guests::setting('guest_tv_notify') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label small" for="goNotifyTv"><?= e(__('Tell the guest on TV')) ?></label>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="goSoundTest"><i class="bi bi-volume-up"></i> <?= e(__('Test sound')) ?></button>
  </div>
</div>

<div class="row g-3" id="goBoard"
     data-labels="<?= e(json_embed($statusLabels + [
         'accept' => __('Accept'), 'prepare' => __('Preparing'), 'deliver' => __('Delivered'), 'cancel' => __('Cancel'), 'done_btn' => __('Done'),
         'reopen' => __('Reopen'), 'kot' => __('Print KOT'), 'none_orders' => __('No open orders.'), 'none_requests' => __('No open requests.'),
         'confirm_cancel' => __('Cancel this order?'), 'notes' => __('Notes'), 'room' => __('Room'), 'at' => __('for'), 'updated' => __('Updated.'),
         'veg' => __('Veg'), 'nonveg' => __('Non-veg'), 'egg' => __('Egg'),
     ])) ?>"
     data-kot="<?= e(admin_url('orders.php')) ?>">
  <div class="col-xl-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-bag"></i> <?= e(__('Room-service orders')) ?>
        <span class="badge text-bg-danger ms-auto" data-count="new_orders"><?= (int) $counts['new_orders'] ?></span>
      </div>
      <div class="card-body p-2">
        <div class="row g-2" data-orders><div class="col-12 text-muted small p-3"><?= e(__('Loading…')) ?></div></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-hand-index"></i> <?= e(__('Guest requests')) ?>
        <span class="badge text-bg-danger ms-auto" data-count="open_requests"><?= (int) $counts['open_requests'] ?></span>
      </div>
      <div class="list-group list-group-flush" data-requests><div class="list-group-item text-muted small"><?= e(__('Loading…')) ?></div></div>
    </div>
  </div>
</div>
<p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= e(__('The board refreshes every 10 seconds. Delivered and cancelled orders of today stay visible until midnight.')) ?></p>
<?php require __DIR__ . '/partials/footer.php'; ?>
