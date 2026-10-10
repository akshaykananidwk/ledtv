<?php
/**
 * Forgot password (2.8, docs/modules/email.md): request a reset link by email address or username.
 * Always the same neutral answer (no user enumeration) after a roughly constant time; rate limited
 * per IP and per account (core/PasswordReset.php).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';
require_once __DIR__ . '/partials/auth_page.php';

Auth::startSession();
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset(I18n::LANGUAGES[$_GET['lang']])) {
    $_SESSION['lang'] = $_GET['lang'];
    I18n::setLang($_GET['lang']);
}
if (Auth::user()) {
    redirect(admin_url('index.php'));
}
header('Referrer-Policy: no-referrer');

/** Minimum answer time (ms): an existing account (token + e-mail) must not answer slower than an unknown one. */
const HC_PWRESET_MIN_MS = 1200;

$brandHotel = auth_brand_hotel();
$bq = $brandHotel ? ['b' => $brandHotel['slug']] : [];
$error = null;
$login = '';

if (is_post()) {
    Csrf::check();
    $start = microtime(true);
    $login = req_str('login', $_POST, 190);
    if ($login === '') {
        $error = __('Enter your email address or username.');
    } else {
        $status = PasswordReset::request($login, client_ip());
        $wait = (int) (HC_PWRESET_MIN_MS - (microtime(true) - $start) * 1000);
        if ($wait > 0) {
            usleep($wait * 1000);
        }
        if ($status === 'rate_limited') {
            http_response_code(429);
            $error = __('Too many requests. Please wait 15 minutes and try again.');
        } else {
            $_SESSION['hc_pwreset_sent'] = 1;
            redirect(admin_url('forgot_password.php', $bq + ['sent' => 1]));
        }
    }
}

$sent = !empty($_GET['sent']) && !empty($_SESSION['hc_pwreset_sent']);
auth_page(__('Forgot password?'), static function () use ($sent, $error, $login, $bq): void {
    if ($sent) {
        ?>
        <div class="alert alert-success d-flex gap-2" role="status" data-pwreset-sent><i class="bi bi-envelope-check-fill"></i>
          <div><?= e(__('If an account exists for this email address or username, we have sent a link to reset the password.')) ?></div></div>
        <p class="small text-muted"><?= e(__('Please check your inbox and the spam folder. The link is valid for 60 minutes. No email? Check the address, wait a few minutes or contact support.')) ?></p>
        <a class="btn btn-primary w-100" href="<?= e(admin_url('login.php', $bq)) ?>"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Back to login')) ?></a>
        <?php
        return;
    }
    ?>
    <p class="text-muted small"><?= e(__('Enter the email address or username of your account. We will email you a link to choose a new password.')) ?></p>
    <?php if ($error): ?>
      <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(admin_url('forgot_password.php', $bq)) ?>" novalidate>
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label" for="login"><?= e(__('Email or username')) ?></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="text" class="form-control form-control-lg" id="login" name="login" value="<?= e($login) ?>" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190" inputmode="email">
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-send"></i> <?= e(__('Send reset link')) ?></button>
    </form>
    <div class="text-center mt-3 small"><a href="<?= e(admin_url('login.php', $bq)) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back to login')) ?></a></div>
    <?php
}, $brandHotel);
