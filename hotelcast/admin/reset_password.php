<?php
/**
 * Choose a new password with an e-mailed link (2.8): forgot-password reset (60 min) or the invite of a
 * new user (72 h). The token in the link is moved into the session and the page reloads without it, so
 * it does not stay in the address bar / history; Referrer-Policy no-referrer. core/PasswordReset.php.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';
require_once __DIR__ . '/partials/auth_page.php';

Auth::startSession();
header('Referrer-Policy: no-referrer');
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset(I18n::LANGUAGES[$_GET['lang']])) {
    $_SESSION['lang'] = $_GET['lang'];
    I18n::setLang($_GET['lang']);
}

$brandHotel = auth_brand_hotel();
$bq = $brandHotel ? ['b' => $brandHotel['slug']] : [];

if (isset($_GET['token'])) {
    $raw = is_string($_GET['token']) ? strtolower(trim($_GET['token'])) : '';
    $_SESSION['hc_reset_token'] = preg_match('/^[a-f0-9]{64}$/', $raw) ? $raw : '';
    redirect(admin_url('reset_password.php', $bq));
}

$token = (string) ($_SESSION['hc_reset_token'] ?? '');
$row = $token !== '' ? PasswordReset::check($token) : null;
if ($row && !isset($_GET['lang']) && !isset($_SESSION['lang'])) {
    I18n::setLang((string) $row['user']['language']);
}
$error = null;

if (is_post()) {
    Csrf::check();
    if (RateLimiter::hit('pwreset_post:' . client_ip(), 30, 900) > 0) {
        http_response_code(429);
        $error = __('Too many requests. Please wait 15 minutes and try again.');
    } elseif (!$row) {
        $error = null; // shown as "link not valid" below
    } else {
        $pw = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? (string) $_POST['password_confirm'] : '';
        [$ok, $msg] = PasswordReset::complete($token, $pw, $confirm);
        if ($ok) {
            unset($_SESSION['hc_reset_token']);
            flash('success', $row['type'] === 'invite'
                ? __('Your password is set. You can log in now with your email address or username.')
                : __('Your password has been changed and all devices were logged out. Please log in with your new password.'));
            redirect(admin_url('login.php', $bq));
        }
        $error = $msg;
        $row = PasswordReset::check($token);
    }
}

$invite = $row && $row['type'] === 'invite';
auth_page($invite ? __('Set your password') : __('Choose a new password'), static function () use ($row, $error, $bq, $invite): void {
    if (!$row) {
        ?>
        <div class="alert alert-warning d-flex gap-2" role="alert" data-pwreset-invalid><i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= e(__('This link is not valid any more. It may have expired or been used already. Please request a new one.')) ?></div></div>
        <a class="btn btn-primary w-100" href="<?= e(admin_url('forgot_password.php', $bq)) ?>"><i class="bi bi-envelope"></i> <?= e(__('Request a new link')) ?></a>
        <div class="text-center mt-3 small"><a href="<?= e(admin_url('login.php', $bq)) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back to login')) ?></a></div>
        <?php
        return;
    }
    $u = $row['user'];
    ?>
    <p class="small text-muted"><?= e($invite ? __('Welcome! Choose the password for your account.') : __('Choose a new password for your account.')) ?></p>
    <div class="border rounded p-2 mb-3 small bg-light"><i class="bi bi-person-circle"></i> <strong><?= e((string) $u['username']) ?></strong> · <?= e((string) $u['email']) ?></div>
    <?php if ($error): ?>
      <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(admin_url('reset_password.php', $bq)) ?>" novalidate autocomplete="off">
      <?= Csrf::field() ?>
      <input type="text" name="username" value="<?= e((string) $u['username']) ?>" autocomplete="username" hidden readonly>
      <div class="mb-3">
        <label class="form-label" for="password"><?= e(__('New password')) ?></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-key"></i></span>
          <input type="password" class="form-control form-control-lg" id="password" name="password" required autofocus autocomplete="new-password" minlength="8" maxlength="200" aria-describedby="pwHelp">
          <button class="btn btn-outline-secondary" type="button" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password'" aria-label="<?= e(__('Show password')) ?>"><i class="bi bi-eye"></i></button>
        </div>
        <div class="form-text" id="pwHelp"><?= e(__('At least 8 characters with letters and numbers.')) ?></div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password_confirm"><?= e(__('Repeat password')) ?></label>
        <input type="password" class="form-control form-control-lg" id="password_confirm" name="password_confirm" required autocomplete="new-password" maxlength="200">
      </div>
      <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-check-lg"></i> <?= e($invite ? __('Set password') : __('Change password')) ?></button>
    </form>
    <?php
}, $brandHotel);
