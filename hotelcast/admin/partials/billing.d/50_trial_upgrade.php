<?php
/**
 * Billing page card: upgrade a free-trial hotel (#17) to a paid plan. POST op=trial_upgrade creates a
 * manual invoice (Signup::requestUpgrade) and notifies the platform; when the platform marks it paid
 * the trial ends and the plan is active. Included by admin/billing.php (permission billing.view).
 */
declare(strict_types=1);

$__hotel = Tenant::hotel();
if (!$__hotel || empty($__hotel['is_trial']) || License::mode() !== 'saas') {
    return null;
}

if (is_post() && req_str('op', $_POST, 30) === 'trial_upgrade') {
    try {
        $iid = Signup::requestUpgrade(Tenant::id(), req_int('plan_id', $_POST), req_int('tvs', $_POST), Auth::id());
        flash('success', __('Thank you! Invoice :n was created. Your plan is activated as soon as the payment is recorded.', ['n' => (string) DB::value('SELECT number FROM invoices WHERE id = :id', ['id' => $iid])]));
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('billing.php') . '#upgrade');
}

return static function () use ($__hotel): void {
    $plans = array_values(array_filter(Hotels::plans(true), fn ($p) => (float) $p['price_per_tv_month'] > 0));
    $pending = Signup::pendingUpgrade((int) $__hotel['id']);
    $inv = $pending ? Billing::find((int) $pending['invoice_id']) : null;
    if ($inv && $inv['status'] !== 'unpaid') {
        $inv = null;
    }
    $left = Signup::daysLeft($__hotel);
    $tvs = max(1, Tenant::tvCount(), (int) DB::value('SELECT tv_estimate FROM signups WHERE hotel_id = :h ORDER BY id DESC LIMIT 1', ['h' => $__hotel['id']]));
    $ended = Tenant::state() !== 'active';
    ?>
<div class="card mb-3 border-primary" id="upgrade"><div class="card-body">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h2 class="h5 mb-0"><i class="bi bi-rocket-takeoff text-primary"></i> <?= e(__('Upgrade to a paid plan')) ?></h2>
    <span class="badge <?= $ended ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= e($ended ? __('Free trial ended') : __('Free trial: :n days left', ['n' => (int) $left])) ?></span>
  </div>
  <?php if ($inv): ?>
    <div class="alert alert-info small mb-3"><i class="bi bi-hourglass-split"></i>
      <?= e(__('Upgrade requested: invoice :n (:t). Your plan is activated as soon as the payment is recorded.', ['n' => $inv['number'], 't' => money($inv['total'], $inv['currency'])])) ?>
      <a href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e(__('View invoice')) ?></a></div>
  <?php endif; ?>
  <?php if (!$plans): ?>
    <p class="text-muted mb-0"><?= e(__('No paid plans are available yet. Please contact your provider.')) ?></p>
  <?php else: ?>
  <form method="post" action="<?= e(admin_url('billing.php')) ?>" class="row g-2 align-items-end">
    <?= Csrf::field() ?><input type="hidden" name="op" value="trial_upgrade">
    <div class="col-12">
      <div class="row g-2">
      <?php foreach ($plans as $i => $p): ?>
        <div class="col-sm-6 col-lg-4"><label class="card h-100 p-2 d-flex flex-row gap-2 align-items-start" style="cursor:pointer">
          <input class="form-check-input mt-1" type="radio" name="plan_id" value="<?= (int) $p['id'] ?>"<?= ($pending['plan_id'] ?? ($plans[0]['id'] ?? 0)) == $p['id'] ? ' checked' : '' ?> required>
          <span><strong><?= e($p['name']) ?></strong> · <?= e(money($p['price_per_tv_month'])) ?>/<?= e(__('TV/month')) ?>
            <?php if ($p['description']): ?><br><small class="text-muted"><?= e($p['description']) ?></small><?php endif; ?>
            <br><small class="text-muted"><?= e($p['max_tvs'] !== null ? __('max :n TVs', ['n' => $p['max_tvs']]) : __('unlimited TVs')) ?></small></span>
        </label></div>
      <?php endforeach; ?>
      </div>
    </div>
    <div class="col-6 col-sm-3"><label class="form-label" for="upTvs"><?= e(__('Number of TVs')) ?></label>
      <input class="form-control" type="number" min="1" max="5000" id="upTvs" name="tvs" value="<?= (int) ($pending['tvs'] ?? $tvs) ?>" required></div>
    <div class="col-12 col-sm-auto"><button class="btn btn-primary"><i class="bi bi-receipt"></i> <?= e($inv ? __('Change plan') : __('Request upgrade')) ?></button></div>
    <div class="col-12 form-text"><?= e(__('We create an invoice for the first month. Pay it by bank transfer / UPI (details on the invoice); the paid plan starts as soon as the payment is recorded.')) ?></div>
  </form>
  <?php endif; ?>
</div></div>
    <?php
};
