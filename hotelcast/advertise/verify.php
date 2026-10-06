<?php
/** Advertiser portal: confirm the email with the 6-digit code (max 5 tries per code, resend throttled). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
Csrf::check();

$adv = MarketplacePortal::advertiser();
if (!$adv) {
    redirect(MarketplacePortal::url('login.php'));
}
if ($adv['status'] !== 'unverified') {
    redirect(MarketplacePortal::url('index.php'));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = MarketplacePortal::url('verify.php');
    if (($_POST['op'] ?? '') === 'resend') {
        if (RateLimiter::hit('mkt_otp_resend:' . $adv['id'], 3, 3600) > 0) {
            MarketplacePortal::flash('danger', __('Too many attempts. Please wait a few minutes.'));
        } else {
            Marketplace::sendOtp((int) $adv['id']);
            MarketplacePortal::flash('success', __('A new code was sent to :e.', ['e' => $adv['email']]));
        }
        redirect($back);
    }
    MarketplacePortal::throttle('otp', 20, 900, $back);
    $err = Marketplace::verifyOtp((int) $adv['id'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')) ?? '');
    if ($err) {
        MarketplacePortal::flash('danger', $err);
        redirect($back);
    }
    MarketplacePortal::flash('success', __('Email confirmed.'));
    redirect(MarketplacePortal::url('index.php'));
}
MarketplacePortal::header(__('Confirm your email'));
?>
<div class="mkt-narrow">
  <h1 class="h4"><?= e(__('Confirm your email')) ?></h1>
  <p><?= e(__('Enter the 6-digit code we sent to :e.', ['e' => $adv['email']])) ?></p>
  <form method="post" class="card card-body gap-3">
    <?= Csrf::field() ?>
    <input class="form-control form-control-lg text-center mkt-otp" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required aria-label="<?= e(__('Code')) ?>">
    <button class="btn btn-primary btn-lg"><?= e(__('Confirm')) ?></button>
  </form>
  <form method="post" class="text-center mt-3"><?= Csrf::field() ?><input type="hidden" name="op" value="resend">
    <button class="btn btn-link"><?= e(__('Send a new code')) ?></button></form>
</div>
<?php
MarketplacePortal::footer();
