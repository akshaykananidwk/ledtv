<?php
/** Advertiser portal: sign-up (business, contact, mobile, email, password) → email code and/or platform approval. */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
Csrf::check();

if (MarketplacePortal::advertiser()) {
    redirect(MarketplacePortal::url('index.php'));
}
$in = ['business_name' => '', 'contact_name' => '', 'mobile' => '', 'email' => '', 'city' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Marketplace::enabled()) {
    MarketplacePortal::throttle('signup', 5, 3600, MarketplacePortal::url('signup.php'));
    $in = array_intersect_key($_POST, $in) + $in;
    if (empty($_POST['accept'])) {
        MarketplacePortal::flash('danger', __('Please accept the ad rules.'));
    } else {
        [$data, $errors] = Marketplace::validateSignup($_POST);
        if ($errors) {
            MarketplacePortal::flash('danger', implode("\n", $errors));
        } else {
            [$id] = Marketplace::register($data, (string) $_POST['password'], I18n::lang());
            MarketplacePortal::login(Marketplace::advertiser($id));
            $a = Marketplace::advertiser($id);
            MarketplacePortal::flash('success', $a['status'] === 'unverified' ? __('Account created. We sent a 6-digit code to your email.') : __('Account created.'));
            redirect(MarketplacePortal::url($a['status'] === 'unverified' ? 'verify.php' : 'index.php'));
        }
    }
}
MarketplacePortal::header(__('Create account'));
?>
<div class="mkt-narrow">
  <h1 class="h4 mb-1"><?= e(__('Create an advertiser account')) ?></h1>
  <p class="text-muted small"><?= e(__('For shops, restaurants, taxis, temples and other local businesses.')) ?></p>
  <?php if (!Marketplace::enabled()): ?>
    <div class="alert alert-warning"><?= e(__('The ad marketplace is closed at the moment.')) ?></div>
  <?php else: ?>
  <form method="post" class="card card-body gap-3">
    <?= Csrf::field() ?>
    <div><label class="form-label" for="s_bn"><?= e(__('Business name')) ?> *</label>
      <input class="form-control" id="s_bn" name="business_name" required maxlength="150" value="<?= e($in['business_name']) ?>" autocomplete="organization"></div>
    <div><label class="form-label" for="s_cn"><?= e(__('Contact person')) ?> *</label>
      <input class="form-control" id="s_cn" name="contact_name" required maxlength="120" value="<?= e($in['contact_name']) ?>" autocomplete="name"></div>
    <div class="row g-2">
      <div class="col-sm-6"><label class="form-label" for="s_mob"><?= e(__('Mobile')) ?> *</label>
        <input class="form-control" id="s_mob" name="mobile" type="tel" required maxlength="20" value="<?= e($in['mobile']) ?>" autocomplete="tel" placeholder="+91…"></div>
      <div class="col-sm-6"><label class="form-label" for="s_city"><?= e(__('City')) ?></label>
        <input class="form-control" id="s_city" name="city" maxlength="80" value="<?= e($in['city']) ?>" autocomplete="address-level2"></div>
    </div>
    <div><label class="form-label" for="s_em"><?= e(__('Email')) ?> *</label>
      <input class="form-control" id="s_em" name="email" type="email" required maxlength="190" value="<?= e($in['email']) ?>" autocomplete="email"></div>
    <div class="row g-2">
      <div class="col-sm-6"><label class="form-label" for="s_pw"><?= e(__('Password')) ?> *</label>
        <input class="form-control" id="s_pw" name="password" type="password" required minlength="8" autocomplete="new-password"></div>
      <div class="col-sm-6"><label class="form-label" for="s_pw2"><?= e(__('Repeat password')) ?> *</label>
        <input class="form-control" id="s_pw2" name="password2" type="password" required minlength="8" autocomplete="new-password"></div>
    </div>
    <details class="small"><summary><?= e(__('Ad rules')) ?></summary><div class="mkt-rules mt-2"><?= nl2br(e(Marketplace::setting('platform_mkt_rules'))) ?></div></details>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="accept" value="1" id="s_acc" required>
      <label class="form-check-label" for="s_acc"><?= e(__('I accept the ad rules.')) ?></label></div>
    <button class="btn btn-primary btn-lg"><?= e(__('Create account')) ?></button>
  </form>
  <?php endif; ?>
  <p class="mt-3 text-center"><?= e(__('Already registered?')) ?> <a href="<?= e(MarketplacePortal::url('login.php')) ?>"><?= e(__('Log in')) ?></a></p>
</div>
<?php
MarketplacePortal::footer();
