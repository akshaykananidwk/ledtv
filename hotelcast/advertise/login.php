<?php
/** Advertiser portal: log in (email + password; IP throttle + per-account lockout). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
Csrf::check();

if (MarketplacePortal::advertiser()) {
    redirect(MarketplacePortal::url('index.php'));
}
$next = is_string($_REQUEST['next'] ?? null) ? (string) $_REQUEST['next'] : '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    [$adv, $err] = Marketplace::attempt($email, (string) ($_POST['password'] ?? ''), client_ip());
    if ($adv) {
        MarketplacePortal::login($adv);
        redirect($adv['status'] === 'unverified' ? MarketplacePortal::url('verify.php') : MarketplacePortal::safeNext($next));
    }
    MarketplacePortal::flash('danger', (string) $err);
}
MarketplacePortal::header(__('Log in'));
?>
<div class="mkt-narrow">
  <h1 class="h4 mb-3"><?= e(__('Advertiser login')) ?></h1>
  <form method="post" class="card card-body gap-3" novalidate>
    <?= Csrf::field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <div><label class="form-label" for="l_email"><?= e(__('Email')) ?></label>
      <input class="form-control form-control-lg" id="l_email" name="email" type="email" autocomplete="email" required value="<?= e($email) ?>"></div>
    <div><label class="form-label" for="l_pw"><?= e(__('Password')) ?></label>
      <input class="form-control form-control-lg" id="l_pw" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary btn-lg"><?= e(__('Log in')) ?></button>
  </form>
  <p class="mt-3 text-center"><?= e(__('New here?')) ?> <a href="<?= e(MarketplacePortal::url('signup.php')) ?>"><?= e(__('Create a free account')) ?></a></p>
  <p class="small text-muted text-center"><?= e(__('Screen owner? Use the admin panel instead.')) ?></p>
</div>
<?php
MarketplacePortal::footer();
