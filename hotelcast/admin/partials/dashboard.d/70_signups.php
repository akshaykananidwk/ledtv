<?php
/**
 * Dashboard widgets (#17): sign-up / trial counters for platform admins, and the getting-started
 * progress for free-trial hotels. Included by admin/index.php in name order.
 */
declare(strict_types=1);

if (License::mode() !== 'saas') {
    return;
}
$wTrial = Tenant::hotel();
if (!empty($wTrial['is_trial'])) {
    $wSteps = Signup::checklist(Tenant::id());
    $wDone = count(array_filter($wSteps, fn ($s) => $s[0]));
    $wPct = (int) round($wDone * 100 / max(1, count($wSteps)));
    ?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100">
    <div class="card-header"><i class="bi bi-flag"></i> <?= e(__('Getting started')) ?></div>
    <div class="card-body">
      <div class="d-flex justify-content-between small mb-1"><span><?= e(__(':d of :n steps done', ['d' => $wDone, 'n' => count($wSteps)])) ?></span><strong><?= $wPct ?>%</strong></div>
      <div class="progress mb-3" role="progressbar" aria-valuenow="<?= $wPct ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $wPct ?>%"></div></div>
      <a class="btn btn-sm btn-primary" href="<?= e(admin_url('getting_started.php')) ?>"><?= e(__('Continue setup')) ?> <i class="bi bi-arrow-right"></i></a>
    </div>
  </div>
</div>
    <?php
}
if (Auth::can('signup.manage')) {
    try {
        $wS = Signup::stats();
    } catch (Throwable) {
        return;
    }
    ?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100">
    <div class="card-header d-flex justify-content-between"><span><i class="bi bi-person-plus"></i> <?= e(__('Sign-ups & trials')) ?></span><a class="small" href="<?= e(admin_url('platform_signups.php')) ?>"><?= e(__('Open')) ?></a></div>
    <div class="card-body"><div class="row text-center g-2">
      <div class="col-3"><div class="fs-4 fw-bold"><?= (int) $wS['pending'] ?></div><div class="small text-muted"><?= e(__('Waiting')) ?></div></div>
      <div class="col-3"><div class="fs-4 fw-bold"><?= (int) $wS['active_trials'] ?></div><div class="small text-muted"><?= e(__('Trials')) ?></div></div>
      <div class="col-3"><div class="fs-4 fw-bold"><?= (int) $wS['converted'] ?></div><div class="small text-muted"><?= e(__('Paid')) ?></div></div>
      <div class="col-3"><div class="fs-4 fw-bold"><?= e((string) $wS['conversion']) ?>%</div><div class="small text-muted"><?= e(__('Conversion')) ?></div></div>
    </div></div>
  </div>
</div>
    <?php
}
