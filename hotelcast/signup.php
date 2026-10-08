<?php
/**
 * Public sign-up for a free trial (#17), SaaS mode only (Platform → Sign-ups & trials → enable).
 *   signup.php              form (honeypot, signed time-to-submit token, CSRF, optional math captcha)
 *   signup.php?step=verify  6-digit e-mail code (OTP mode)
 *   signup.php?step=pending "we will contact you" (manual approval, and every answer that must not
 *                           reveal whether an address is already registered)
 * On success the owner is logged in and lands on admin/getting_started.php.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/admin/partials/common.php';

Auth::startSession();
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset(I18n::LANGUAGES[$_GET['lang']])) {
    $_SESSION['lang'] = $_GET['lang'];
    I18n::setLang($_GET['lang']);
}
$brand = Branding::get(0);
$lang = I18n::lang();
header('Cache-Control: no-store');

/** Minimal public page shell. */
function signup_page(string $title, callable $body, array $brand, string $lang): never
{
    ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($brand['product']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>:root{--hc-accent:<?= e($brand['color']) ?>;--hc-accent-dark:<?= e(Branding::shade($brand['color'])) ?>}
.hc-login{background:radial-gradient(circle at 20% 20%,<?= e($brand['color']) ?>,#0f172a 65%);min-height:100vh;padding:24px 16px}
.hc-login .card{max-width:640px;width:100%;margin:0 auto}
.hc-hp{position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden}
.terms-box{max-height:180px;overflow:auto;white-space:pre-wrap;font-size:.85rem}</style>
</head>
<body class="hc-admin lang-<?= e($lang) ?>">
<div class="hc-login d-block">
  <div class="card shadow-lg"><div class="card-body p-3 p-sm-5">
    <div class="text-center mb-3">
      <?php if ($brand['logo_url']): ?><img src="<?= e($brand['logo_url']) ?>" alt="" style="max-height:60px;max-width:200px" class="mb-2">
      <?php else: ?><div class="hc-brand-icon text-white mx-auto mb-2" style="width:52px;height:52px;font-size:1.6rem"><i class="bi bi-tv"></i></div><?php endif; ?>
      <h1 class="h4 mb-0"><?= e($title) ?></h1>
      <div class="text-muted small"><?= e($brand['product']) ?></div>
    </div>
    <?php $body(); ?>
    <div class="text-center mt-4 small">
      <?php foreach (I18n::LANGUAGES as $code => $name): ?>
        <a href="?<?= e(http_build_query(array_filter(['lang' => $code, 'step' => is_string($_GET['step'] ?? null) ? $_GET['step'] : null]))) ?>" class="mx-2<?= $code === $lang ? ' fw-bold' : '' ?>"><?= e($name) ?></a>
      <?php endforeach; ?>
      · <a href="<?= e(admin_url('login.php')) ?>"><?= e(__('Log in')) ?></a>
    </div>
  </div></div>
</div>
</body>
</html>
    <?php
    exit;
}

if (!Signup::enabled()) {
    http_response_code(404);
    signup_page(__('Start free trial'), static function (): void {
        echo '<div class="alert alert-info">' . e(__('Online sign-up is not available. Please contact us to get an account.')) . '</div>';
    }, $brand, $lang);
}

$step = is_string($_GET['step'] ?? null) && in_array($_GET['step'], ['verify', 'pending'], true) ? $_GET['step'] : 'form';
$errors = [];
$old = [];
$notice = null;
$ip = client_ip();

if (is_post()) {
    Csrf::check();
    $op = req_str('op', $_POST, 20);
    if (RateLimiter::hit('signup_post:' . $ip, 30, 3600) > 0) {
        http_response_code(429);
        $errors['_'] = __('Too many sign-up attempts. Please try again later or contact us.');
        $step = $op === 'register' ? 'form' : 'verify';
    } elseif ($op === 'register') {
        $old = $_POST;
        $age = Signup::formAge($_POST['ts'] ?? null);
        $hp = trim((string) (is_scalar($_POST['website'] ?? null) ? $_POST['website'] : ''));
        if ($hp !== '') {
            // Honeypot filled: a bot. Answer like a normal manual-review request, store nothing.
            Logger::write('signup', 'warning', 'Sign-up honeypot triggered', ['ip' => $ip]);
            redirect(base_url('signup.php?step=pending'));
        }
        if ($age === null || $age > Signup::MAX_FORM_AGE) {
            $errors['_'] = __('The form has expired. Please check your details and send it again.');
        } elseif ($age < (int) Signup::setting('signup_min_seconds')) {
            $errors['_'] = __('That was very fast. Please check your details and send the form again.');
        }
        if (!$errors && Signup::setting('signup_captcha') === '1' && !Signup::captchaValid($_POST['captcha'] ?? null)) {
            $errors['captcha'] = __('Wrong answer to the math question.');
        }
        if (!$errors) {
            [$data, $vErrors] = Signup::validate($_POST);
            $errors = $vErrors;
            if (!$errors && ($limit = Signup::checkLimits($data['email'], $data['mobile'], $ip)) !== null) {
                http_response_code(429);
                $errors['_'] = $limit;
            }
            if (!$errors) {
                $r = Signup::register($data, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                if ($r['next'] === 'created') {
                    $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $r['user_id']]);
                    Auth::login($u);
                    redirect(admin_url('getting_started.php', ['welcome' => 1]));
                }
                if ($r['next'] === 'verify') {
                    $_SESSION['hc_signup_id'] = $r['id'];
                    $_SESSION['hc_signup_email'] = $data['email'];
                    redirect(base_url('signup.php?step=verify'));
                }
                redirect(base_url('signup.php?step=pending'));
            }
        }
    } elseif ($op === 'verify' || $op === 'resend') {
        $sid = (int) ($_SESSION['hc_signup_id'] ?? 0);
        $step = 'verify';
        if (!$sid) {
            redirect(base_url('signup.php'));
        }
        if ($op === 'resend') {
            $notice = Signup::sendOtp($sid, true) ? __('We sent a new code.') : __('Please wait a minute before asking for a new code (max. 3 codes).');
        } elseif (RateLimiter::hit('signup_otp:' . $ip, 20, 3600) > 0) {
            http_response_code(429);
            $errors['_'] = __('Too many attempts. Please try again later.');
        } else {
            [$ok, $msg, , $uid] = Signup::verifyOtp($sid, req_str('code', $_POST, 20));
            if ($ok) {
                unset($_SESSION['hc_signup_id'], $_SESSION['hc_signup_email']);
                Auth::login(DB::one('SELECT * FROM users WHERE id = :id', ['id' => $uid]));
                redirect(admin_url('getting_started.php', ['welcome' => 1]));
            }
            $st = (string) DB::value('SELECT status FROM signups WHERE id = :id', ['id' => $sid]);
            if ($st === 'pending') {
                unset($_SESSION['hc_signup_id']);
                redirect(base_url('signup.php?step=pending'));
            }
            if ($st !== 'verify') {
                unset($_SESSION['hc_signup_id'], $_SESSION['hc_signup_email']);
            }
            $errors['_'] = (string) $msg;
        }
    }
}

if ($step === 'pending') {
    signup_page(__('Thank you!'), static function (): void {
        echo '<div class="alert alert-success d-flex gap-2"><i class="bi bi-check-circle-fill"></i><div>' . e(__('Thank you! We will review your request and contact you shortly.')) . '</div></div>';
        echo '<p class="small text-muted mb-0">' . e(__('If you already have an account, simply log in.')) . '</p>';
    }, $brand, $lang);
}

if ($step === 'verify') {
    $email = (string) ($_SESSION['hc_signup_email'] ?? '');
    $active = !empty($_SESSION['hc_signup_id']);
    signup_page(__('Check your email'), static function () use ($email, $errors, $notice, $active): void {
        if (!$active) {
            echo '<div class="alert alert-warning">' . e($errors['_'] ?? __('This sign-up has expired. Please start again.')) . '</div>';
            echo '<a class="btn btn-primary w-100" href="' . e(base_url('signup.php')) . '">' . e(__('Start free trial')) . '</a>';
            return;
        }
        $masked = preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $email) ?? '';
        ?>
        <p><?= e(__('We sent a 6-digit code to :email. Enter it below to start your free trial.', ['email' => $masked])) ?></p>
        <?php if ($notice): ?><div class="alert alert-info small"><?= e($notice) ?></div><?php endif; ?>
        <?php if (!empty($errors['_'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
        <form method="post" action="<?= e(base_url('signup.php?step=verify')) ?>" novalidate>
          <?= Csrf::field() ?><input type="hidden" name="op" value="verify">
          <label class="form-label" for="code"><?= e(__('Verification code')) ?></label>
          <input class="form-control form-control-lg text-center mono mb-3" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus style="letter-spacing:.4em">
          <button class="btn btn-primary btn-lg w-100"><i class="bi bi-shield-check"></i> <?= e(__('Verify and start trial')) ?></button>
        </form>
        <form method="post" action="<?= e(base_url('signup.php?step=verify')) ?>" class="text-center mt-3"><?= Csrf::field() ?><input type="hidden" name="op" value="resend">
          <button class="btn btn-link btn-sm"><?= e(__('Send a new code')) ?></button></form>
        <p class="small text-muted mb-0"><?= e(__('No email? Check your spam folder. The code is valid for 15 minutes.')) ?></p>
        <?php
    }, $brand, $lang);
}

// ------------------------------------------------------------------ form
$captcha = Signup::setting('signup_captcha') === '1' ? Signup::captchaQuestion() : null;
$terms = Signup::setting('signup_terms');
$days = Signup::trialDays();
$v = static fn (string $k) => e(is_scalar($old[$k] ?? null) ? (string) $old[$k] : '');
$err = static fn (string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k) => isset($errors[$k]) ? ' is-invalid' : '';
signup_page(__('Start your :n-day free trial', ['n' => $days]), static function () use ($v, $err, $cls, $errors, $captcha, $terms, $old, $lang): void {
    ?>
    <p class="text-center text-muted small"><?= e(__('No payment details needed. Your account is ready in a minute, with sample screens and content.')) ?></p>
    <?php if (!empty($errors['_'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
    <?php if ($errors && empty($errors['_'])): ?><div class="alert alert-danger" role="alert"><?= e(__('Please correct the marked fields.')) ?></div><?php endif; ?>
    <form method="post" action="<?= e(base_url('signup.php')) ?>" class="row g-3" novalidate autocomplete="on">
      <?= Csrf::field() ?><input type="hidden" name="op" value="register"><input type="hidden" name="ts" value="<?= e(Signup::formToken()) ?>">
      <div class="hc-hp" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value=""></div>
      <div class="col-sm-7"><label class="form-label" for="hotel_name"><?= e(__('Business name')) ?> *</label>
        <input class="form-control<?= $cls('hotel_name') ?>" id="hotel_name" name="hotel_name" value="<?= $v('hotel_name') ?>" required maxlength="120" autocomplete="organization"><?= $err('hotel_name') ?></div>
      <div class="col-sm-5"><label class="form-label" for="city"><?= e(__('City')) ?> *</label>
        <input class="form-control<?= $cls('city') ?>" id="city" name="city" value="<?= $v('city') ?>" required maxlength="80" autocomplete="address-level2"><?= $err('city') ?></div>
      <div class="col-sm-6"><label class="form-label" for="owner_name"><?= e(__('Your name')) ?> *</label>
        <input class="form-control<?= $cls('owner_name') ?>" id="owner_name" name="owner_name" value="<?= $v('owner_name') ?>" required maxlength="120" autocomplete="name"><?= $err('owner_name') ?></div>
      <div class="col-sm-6"><label class="form-label" for="mobile"><?= e(__('Mobile / WhatsApp')) ?> *</label>
        <input class="form-control<?= $cls('mobile') ?>" type="tel" id="mobile" name="mobile" value="<?= $v('mobile') ?>" required maxlength="20" inputmode="tel" autocomplete="tel" placeholder="+91 98xxx xxxxx"><?= $err('mobile') ?></div>
      <div class="col-sm-8"><label class="form-label" for="email"><?= e(__('Email')) ?> *</label>
        <input class="form-control<?= $cls('email') ?>" type="email" id="email" name="email" value="<?= $v('email') ?>" required maxlength="190" autocomplete="email" autocapitalize="none" spellcheck="false"><?= $err('email') ?></div>
      <div class="col-sm-4"><label class="form-label" for="tv_estimate"><?= e(__('Screens / TVs')) ?> *</label>
        <input class="form-control<?= $cls('tv_estimate') ?>" type="number" id="tv_estimate" name="tv_estimate" value="<?= $v('tv_estimate') ?>" min="1" max="2000" required inputmode="numeric"><?= $err('tv_estimate') ?></div>
      <div class="col-sm-8"><label class="form-label" for="password"><?= e(__('Choose a password')) ?> *</label>
        <div class="input-group"><input class="form-control<?= $cls('password') ?>" type="password" id="password" name="password" required autocomplete="new-password" minlength="8" aria-describedby="pwHelp">
          <button class="btn btn-outline-secondary" type="button" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password'" aria-label="<?= e(__('Show password')) ?>"><i class="bi bi-eye"></i></button></div>
        <div class="progress mt-1" style="height:4px"><div class="progress-bar" id="pwBar" style="width:0"></div></div>
        <div class="form-text" id="pwHelp"><span id="pwLabel"></span> <?= e(__('At least 8 characters with letters and numbers.')) ?></div><?= $err('password') ?></div>
      <div class="col-sm-4"><label class="form-label" for="language"><?= e(__('Language')) ?></label>
        <select class="form-select" id="language" name="language">
          <?php foreach (I18n::LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"<?= (($old['language'] ?? $lang) === $code) ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($captcha !== null): ?>
      <div class="col-sm-6"><label class="form-label" for="captcha"><?= e(__('Spam check: what is :q?', ['q' => $captcha])) ?> *</label>
        <input class="form-control<?= $cls('captcha') ?>" id="captcha" name="captcha" inputmode="numeric" maxlength="3" required autocomplete="off"><?= $err('captcha') ?></div>
      <?php endif; ?>
      <div class="col-12">
        <details class="mb-2"><summary class="small"><?= e(__('Read the terms')) ?></summary><div class="terms-box border rounded p-2 mt-2 bg-light"><?= e($terms) ?></div></details>
        <div class="form-check"><input class="form-check-input<?= $cls('accept_terms') ?>" type="checkbox" id="accept_terms" name="accept_terms" value="1"<?= !empty($old['accept_terms']) ? ' checked' : '' ?> required>
          <label class="form-check-label" for="accept_terms"><?= e(__('I accept the terms of the free trial.')) ?></label><?= $err('accept_terms') ?></div>
      </div>
      <div class="col-12"><button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-rocket-takeoff"></i> <?= e(__('Start free trial')) ?></button></div>
    </form>
    <?php if (Demo::publicEnabled()): ?>
      <p class="text-center small mt-3 mb-0"><a href="<?= e(base_url('demo.php')) ?>"><i class="bi bi-easel"></i> <?= e(__('Not sure yet? Try the live demo first.')) ?></a></p>
    <?php endif; ?>
    <script>
    (function () {
      var p = document.getElementById('password'), bar = document.getElementById('pwBar'), lbl = document.getElementById('pwLabel');
      var L = <?= json_embed([__('Weak'), __('Fair'), __('Good'), __('Strong')]) ?>, C = ['bg-danger', 'bg-warning', 'bg-info', 'bg-success'];
      p.addEventListener('input', function () {
        var v = p.value, s = 0;
        if (v.length >= 8) s++; if (/[a-z]/i.test(v) && /\d/.test(v)) s++; if (v.length >= 12) s++; if (/[^A-Za-z0-9]/.test(v) || (/[a-z]/.test(v) && /[A-Z]/.test(v))) s++;
        var i = Math.max(0, s - 1);
        bar.style.width = v ? (s * 25) + '%' : '0'; bar.className = 'progress-bar ' + C[i]; lbl.textContent = v ? L[i] + ' ·' : '';
      });
    })();
    </script>
    <?php
}, $brand, $lang);
