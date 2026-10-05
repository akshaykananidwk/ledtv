<?php
/**
 * Dashboard widget: occupancy, open orders / requests and the 30-day feedback average
 * (guests & guest services module).
 */
declare(strict_types=1);

if (!Tenant::has() || !(Auth::can('guests.manage') || Auth::can('services.manage'))) {
    return;
}
$__gOn = Guests::enabled();
$__sOn = GuestServices::enabled();
if (!$__gOn && !$__sOn) {
    return;
}
$__occ = $__gOn ? count(Guests::activeStays()) : null;
$__rooms = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :hid', ['hid' => Tenant::id()]);
$__c = $__sOn ? GuestServices::alertCounts() : null;
$__fb = $__sOn && Auth::can('guests.feedback') ? GuestServices::feedbackStats(date('Y-m-d', strtotime('-29 days')), date('Y-m-d')) : null;
?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100">
    <div class="card-header"><i class="bi bi-person-vcard"></i> <?= e(__('Guests today')) ?></div>
    <div class="card-body">
      <div class="row text-center g-2">
        <?php if ($__occ !== null): ?>
        <div class="col"><a class="text-decoration-none text-body" href="<?= e(admin_url('guests.php')) ?>"><div class="fs-3 fw-bold"><?= (int) $__occ ?><small class="fs-6 text-muted">/<?= $__rooms ?></small></div><div class="small text-muted"><?= e(__('Occupied')) ?></div></a></div>
        <?php endif; ?>
        <?php if ($__c !== null): ?>
        <div class="col"><a class="text-decoration-none text-body" href="<?= e(admin_url('orders.php')) ?>"><div class="fs-3 fw-bold<?= $__c['open_orders'] ? ' text-danger' : '' ?>"><?= (int) $__c['open_orders'] ?></div><div class="small text-muted"><?= e(__('Open orders')) ?></div></a></div>
        <div class="col"><a class="text-decoration-none text-body" href="<?= e(admin_url('orders.php')) ?>"><div class="fs-3 fw-bold<?= $__c['open_requests'] ? ' text-danger' : '' ?>"><?= (int) $__c['open_requests'] ?></div><div class="small text-muted"><?= e(__('Open requests')) ?></div></a></div>
        <?php endif; ?>
        <?php if ($__fb !== null): ?>
        <div class="col"><a class="text-decoration-none text-body" href="<?= e(admin_url('feedback.php')) ?>"><div class="fs-3 fw-bold text-warning"><?= $__fb['average'] !== null ? e(number_format($__fb['average'], 1)) : '—' ?></div><div class="small text-muted"><?= e(__('Rating (30 days)')) ?></div></a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php unset($__gOn, $__sOn, $__occ, $__rooms, $__c, $__fb);
