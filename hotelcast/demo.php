<?php
/**
 * Public demo landing page (#21). Only when Platform → Demo → "Public demo" is on (SaaS mode).
 *  - "Try the admin panel": POST → logs the visitor in as the read-only demo user of the public demo
 *    hotel (every change is refused by Demo::guard()).
 *  - "See the TV": demo_tv.php?room=<id> (TV simulator, demo hotel rooms only).
 * Only the public demo hotel is ever shown here.
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

$hid = Demo::publicEnabled() ? Demo::ensurePublic() : null;
$error = null;
if ($hid && is_post()) {
    Csrf::check();
    if (req_str('op', $_POST, 20) === 'login') {
        if (RateLimiter::hit('demo_login:' . client_ip(), 30, 3600) > 0) {
            http_response_code(429);
            $error = __('Too many attempts. Please try again later.');
        } else {
            $demoUser = Demo::demoUser($hid);
            if (!$demoUser) {
                $hid = Demo::resetPublic();
                $demoUser = Demo::demoUser($hid);
            }
            Auth::login($demoUser);
            $_SESSION['lang'] = $lang;
            redirect(admin_url('index.php'));
        }
    }
}
if (!$hid) {
    http_response_code(404);
}
$rooms = $hid ? Demo::publicRooms($hid) : [];
if ($hid) {
    Demo::keepAlive($hid);
}
$hotelName = $hid ? (string) Settings::getFor($hid, 'hotel_name', '') : '';
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(__('Live demo')) ?> · <?= e($brand['product']) ?></title>
<meta name="description" content="<?= e(__('Try :product: TV and digital signage management with a live demo.', ['product' => $brand['product']])) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>:root{--hc-accent:<?= e($brand['color']) ?>;--hc-accent-dark:<?= e(Branding::shade($brand['color'])) ?>}
body{background:#f1f5f9}
.demo-hero{background:radial-gradient(circle at 20% 20%,<?= e($brand['color']) ?>,#0f172a 70%);color:#fff;padding:40px 16px 72px}
.demo-wrap{max-width:980px;margin:-48px auto 32px;padding:0 16px}
.room-btn{display:flex;align-items:center;gap:.4rem;justify-content:center}
</style>
</head>
<body class="hc-admin lang-<?= e($lang) ?>">
<div class="alert alert-warning rounded-0 mb-0 text-center small" role="status" id="hcDemoBanner"><i class="bi bi-easel"></i>
  <?= e(__('Demo mode — changes are disabled. This demo is reset every night.')) ?></div>
<header class="demo-hero text-center">
  <?php if ($brand['logo_url']): ?><img src="<?= e($brand['logo_url']) ?>" alt="" style="max-height:56px;max-width:200px" class="mb-2"><?php endif; ?>
  <h1 class="h2 fw-bold mb-2"><?= e(__(':product live demo', ['product' => $brand['product']])) ?></h1>
  <p class="mb-0 opacity-75"><?= e(__('See how a business manages all its TV screens — welcome screens, menus, offers, timetables and notices.')) ?></p>
  <div class="mt-3 small">
    <?php foreach (I18n::LANGUAGES as $code => $name): ?><a class="link-light mx-2<?= $code === $lang ? ' fw-bold' : '' ?>" href="?lang=<?= e($code) ?>"><?= e($name) ?></a><?php endforeach; ?>
  </div>
</header>
<main class="demo-wrap">
  <?php if (!$hid): ?>
    <div class="card"><div class="card-body text-center py-5"><p class="mb-3"><?= e(__('The demo is not available at the moment.')) ?></p>
      <a class="btn btn-primary" href="<?= e(admin_url('login.php')) ?>"><?= e(__('Log in')) ?></a></div></div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-md-6"><div class="card h-100 shadow-sm"><div class="card-body p-4">
      <h2 class="h5"><i class="bi bi-speedometer2 text-primary"></i> <?= e(__('Try the admin panel')) ?></h2>
      <p class="text-muted small"><?= e(__('Look around the panel of :hotel: screens, playlists, schedules and reports. You can open everything; saving is disabled in the demo.', ['hotel' => $hotelName])) ?></p>
      <form method="post" action="<?= e(base_url('demo.php')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="login">
        <button class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Open the demo admin panel')) ?></button></form>
    </div></div></div>
    <div class="col-md-6"><div class="card h-100 shadow-sm"><div class="card-body p-4">
      <h2 class="h5"><i class="bi bi-tv text-primary"></i> <?= e(__('See the TV')) ?></h2>
      <p class="text-muted small"><?= e(__('Watch what your visitors see on a TV screen (simulated in your browser).')) ?></p>
      <div class="row g-2">
        <?php foreach (array_slice($rooms, 0, 12) as $r): ?>
          <div class="col-4 col-sm-3"><a class="btn btn-light border w-100 room-btn" href="<?= e(base_url('demo_tv.php?room=' . (int) $r['id'])) ?>" title="<?= e((string) $r['name']) ?>">
            <i class="bi <?= (int) $r['tvs'] ? 'bi-tv-fill text-success' : 'bi-tv' ?>"></i> <?= e($r['room_number']) ?></a></div>
        <?php endforeach; ?>
      </div>
    </div></div></div>
  </div>
  <?php if (Signup::enabled()): ?>
    <div class="card mt-3 shadow-sm"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between p-4">
      <div><h2 class="h5 mb-1"><?= e(__('Ready for your own screens?')) ?></h2><div class="text-muted small"><?= e(__('Start a :n-day free trial — no payment details needed.', ['n' => Signup::trialDays()])) ?></div></div>
      <a class="btn btn-success btn-lg" href="<?= e(base_url('signup.php')) ?>"><i class="bi bi-rocket-takeoff"></i> <?= e(__('Start free trial')) ?></a>
    </div></div>
  <?php endif; ?>
  <?php endif; ?>
  <p class="text-center small text-muted mt-4"><?= e($brand['product']) ?><?= $brand['support_phone'] !== '' || $brand['support_email'] !== '' ? ' · ' . e(__('Support')) . ': ' . e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) : '' ?></p>
</main>
</body>
</html>
