<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

Auth::startSession();

// Language choice before login (stored in the session only).
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset(I18n::LANGUAGES[$_GET['lang']])) {
    $_SESSION['lang'] = $_GET['lang'];
    I18n::setLang($_GET['lang']);
}

/** Only allow redirects to relative paths inside the admin folder. */
function safe_next(mixed $next): string
{
    $default = admin_url('index.php');
    if (!is_string($next) || $next === '' || strlen($next) > 500) {
        return $default;
    }
    $adminPath = (string) parse_url(admin_url(), PHP_URL_PATH); // e.g. /hotelcast/admin/
    if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')
        || preg_match('/[\x00-\x1f]/', $next) || str_contains($next, '..')
        || !str_starts_with($next, $adminPath) || str_contains($next, 'login.php') || str_contains($next, 'logout.php')
        || str_contains($next, 'ajax')) {
        return $default;
    }
    $parts = parse_url($next);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }
    return $next;
}

$next = $_POST['next'] ?? $_GET['next'] ?? '';
if (Auth::user()) {
    redirect(safe_next($next));
}

$error = null;
$username = '';
if (is_post()) {
    Csrf::check();
    $username = req_str('username', $_POST, 190);
    $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    if ($username === '' || $password === '') {
        $error = __('Enter your username and password.');
    } else {
        [$ok, $msg] = Auth::attempt($username, $password);
        if ($ok) {
            redirect(safe_next($next));
        }
        $error = $msg;
    }
}

// White-label: platform branding, or a hotel's / reseller's branding with ?b=<hotel-slug>.
$brandHotel = is_string($_GET['b'] ?? null) && preg_match('/^[a-z0-9-]{1,80}$/', $_GET['b'])
    ? DB::one('SELECT id, name, brand_logo FROM hotels WHERE slug = :s', ['s' => $_GET['b']]) : null;
$brand = Branding::get($brandHotel ? (int) $brandHotel['id'] : 0);
$hotelName = $brandHotel ? (string) $brandHotel['name'] : $brand['product'];
$logo = $brand['logo_url'];
$lang = I18n::lang();
$pageTitle = __('Log in');
$user = null;
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(__('Log in')) ?> · <?= e($hotelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>:root{--hc-accent:<?= e($brand['color']) ?>;--hc-accent-dark:<?= e(Branding::shade($brand['color'])) ?>}.hc-login{background:radial-gradient(circle at 20% 20%,<?= e($brand['color']) ?>,#0f172a 65%)}</style>
</head>
<body class="hc-admin lang-<?= e($lang) ?>">
<div class="hc-login">
  <div class="card shadow-lg">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" alt="" style="max-height:72px;max-width:200px" class="mb-2">
        <?php else: ?>
          <div class="hc-brand-icon text-white mx-auto mb-2" style="width:56px;height:56px;font-size:1.7rem"><i class="bi bi-tv"></i></div>
        <?php endif; ?>
        <h1 class="h4 mb-0"><?= e($hotelName) ?></h1>
        <div class="text-muted small"><?= e($brand['product']) ?></div>
      </div>
      <?= flash_show() ?>
      <?php if ($error): ?>
        <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><div><?= e($error) ?></div></div>
      <?php endif; ?>
      <form method="post" action="<?= e(admin_url('login.php')) ?>" autocomplete="on" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="next" value="<?= e(is_string($next) ? $next : '') ?>">
        <div class="mb-3">
          <label class="form-label" for="username"><?= e(__('Username or email')) ?></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control form-control-lg" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190">
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password"><?= e(__('Password')) ?></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" class="form-control form-control-lg" id="password" name="password" required autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password'" aria-label="<?= e(__('Show password')) ?>"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Log in')) ?></button>
      </form>
      <?php if (!$brandHotel && (Signup::enabled() || Demo::publicEnabled())): // Free trial (#17) / public demo (#21) ?>
        <div class="text-center mt-3 d-flex flex-wrap justify-content-center gap-2">
          <?php if (Signup::enabled()): ?><a class="btn btn-outline-success btn-sm" href="<?= e(base_url('signup.php')) ?>"><i class="bi bi-rocket-takeoff"></i> <?= e(__('Start free trial')) ?></a><?php endif; ?>
          <?php if (Demo::publicEnabled()): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('demo.php')) ?>"><i class="bi bi-easel"></i> <?= e(__('Try the demo')) ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?>
        <div class="text-center text-muted small mt-3"><i class="bi bi-headset"></i> <?= e(__('Support')) ?>: <?= e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) ?></div>
      <?php endif; ?>
      <div class="text-center mt-4 small">
        <?php foreach (I18n::LANGUAGES as $code => $name): ?>
          <a href="<?= e(admin_url('login.php', array_filter(['lang' => $code, 'next' => is_string($next) ? $next : '']))) ?>" class="mx-2<?= $code === $lang ? ' fw-bold' : '' ?>"><?= e($name) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
