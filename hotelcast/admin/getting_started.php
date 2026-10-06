<?php
/**
 * Getting started (#17): checklist for a new (trial) hotel — rooms, logo, first TV via QR, own
 * content, staff — with progress. Read-only page; every step links to the page that does it.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('dashboard.view');
Csrf::check();

$hotel = Tenant::hotel();
$steps = Signup::checklist(Tenant::id());
$done = count(array_filter($steps, fn ($s) => $s[0]));
$pct = (int) round($done * 100 / max(1, count($steps)));
$left = Signup::daysLeft($hotel);
$welcome = !empty($_GET['welcome']);
$pageTitle = __('Getting started');
$activeNav = 'getting_started';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div>
  <h1><?= e($welcome ? __('Welcome, :name!', ['name' => $user['full_name'] ?: $user['username']]) : __('Getting started')) ?></h1>
  <p class="lead-sm"><?= e(__('Five steps to get :hotel on your TVs.', ['hotel' => (string) $hotel['name']])) ?></p>
</div></div>

<?php if ($welcome): ?>
<div class="alert alert-success d-flex gap-2"><i class="bi bi-check-circle-fill"></i><div>
  <?= e(__('Your free trial has started.')) ?>
  <?php if ($left !== null): ?><?= e(__('It runs until :d.', ['d' => date('d M Y', (int) strtotime((string) $hotel['expires_at']))])) ?><?php endif; ?>
  <?= e(__('Your login: :u (or your email address).', ['u' => $user['username']])) ?>
  <?= e(__('We added sample rooms, a playlist and a timetable so you can see how it works.')) ?>
</div></div>
<?php endif; ?>

<div class="card mb-3" style="max-width:860px"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <strong><?= e(__(':d of :n steps done', ['d' => $done, 'n' => count($steps)])) ?></strong>
    <span class="text-muted small"><?= $pct ?>%</span>
  </div>
  <div class="progress mb-3" style="height:10px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= e(__('Progress')) ?>">
    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
  </div>
  <ol class="list-group list-group-numbered">
    <?php foreach ($steps as $key => [$ok, $label, $href, $icon, $detail]): ?>
      <li class="list-group-item d-flex align-items-center gap-3 py-3" data-step="<?= e($key) ?>" data-done="<?= $ok ? '1' : '0' ?>">
        <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?> fs-4" aria-hidden="true"></i>
        <div class="flex-grow-1 min-w-0">
          <div class="fw-semibold<?= $ok ? ' text-decoration-line-through text-muted' : '' ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></div>
          <?php if ($detail !== ''): ?><div class="small text-muted"><?= e($detail) ?></div><?php endif; ?>
          <span class="visually-hidden"><?= e($ok ? __('Done') : __('To do')) ?></span>
        </div>
        <a class="btn btn-sm <?= $ok ? 'btn-light border' : 'btn-primary' ?> text-nowrap" href="<?= e(admin_url($href)) ?>"><?= e($ok ? __('Open') : __('Start')) ?> <i class="bi bi-arrow-right"></i></a>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($done === count($steps)): ?>
    <div class="alert alert-success mt-3 mb-0"><i class="bi bi-stars"></i> <?= e(__('All done — your hotel TVs are ready!')) ?></div>
  <?php endif; ?>
</div></div>

<?php if ($left !== null && Auth::can('billing.view')): ?>
<div class="card" style="max-width:860px"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
  <i class="bi bi-hourglass-split fs-4 text-warning"></i>
  <div class="flex-grow-1"><?= e(Tenant::isActive() ? __('Free trial: :n days left.', ['n' => $left]) : __('Your free trial has ended. Upgrade to a paid plan to switch your TVs back on.')) ?></div>
  <a class="btn btn-outline-primary" href="<?= e(admin_url('billing.php') . '#upgrade') ?>"><i class="bi bi-rocket-takeoff"></i> <?= e(__('Upgrade')) ?></a>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
