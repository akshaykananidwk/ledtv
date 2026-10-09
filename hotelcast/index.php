<?php
/**
 * Public landing page at the site root (2.6.1) — see core/Landing.php for where every piece of data
 * comes from. No login needed; a logged-in visitor gets "Go to dashboard" instead of "Login".
 * Not installed yet → core/bootstrap.php redirects to the installer (unchanged behaviour).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

// Logged in? Only look when a session cookie exists (no session file for every anonymous visitor).
$loggedIn = false;
if (!empty($_COOKIE['HCSESSID'])) {
    $loggedIn = Auth::user() !== null;
    session_write_close();
}

$lang = Landing::language($_GET, $_COOKIE, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
I18n::setLang($lang);

$brand = Branding::get(0);
$product = $brand['product'];
$signup = Signup::enabled();
$trialDays = $signup ? Signup::trialDays() : 0;
$demo = Demo::publicEnabled();
$plans = Landing::plans();
$stats = Landing::stats();
$appCount = Landing::appCount();
$phone = Landing::phoneLinks($brand['support_phone']);
$email = filter_var($brand['support_email'], FILTER_VALIDATE_EMAIL) ? $brand['support_email'] : '';
$hasContact = $phone['tel'] || $email !== '';
$nonce = base64_encode(random_bytes(16));
$canonical = base_url($lang === 'en' ? '' : '?lang=' . $lang);
$dashboardUrl = admin_url('');
$loginUrl = admin_url('login.php');
$signupUrl = base_url('signup.php' . ($lang !== 'en' ? '?lang=' . $lang : ''));
$demoUrl = base_url('demo.php' . ($lang !== 'en' ? '?lang=' . $lang : ''));
$accent = $brand['color'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-cache');
header('Vary: Cookie, Accept-Language');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'nonce-$nonce'; script-src 'self' 'nonce-$nonce'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");

$title = $product . ' — ' . __('Digital signage & TV management for every business');
$description = __('Control every TV screen of your business from one place: live darshan, menus, offers, token queues, timetables, notices and emergency alerts. Works on Android TV, Fire TV and any browser, in English, Gujarati and Hindi.');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => $product,
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Android TV, Fire OS, Web',
    'description' => $description,
    'url' => base_url(),
    'inLanguage' => array_keys(I18n::LANGUAGES),
];
$prices = array_values(array_filter(array_map(static fn ($p) => $p['price'], $plans), static fn ($v) => $v > 0));
if ($prices) {
    $jsonLd['offers'] = ['@type' => 'AggregateOffer', 'lowPrice' => number_format(min($prices), 2, '.', ''),
        'highPrice' => number_format(max($prices), 2, '.', ''), 'priceCurrency' => strtoupper(substr((string) Settings::platform('billing_currency', 'INR'), 0, 3))];
}

/** Primary call to action (trial when sign-up is open, else login / dashboard). */
$cta = static function (string $extra = '') use ($loggedIn, $signup, $trialDays, $signupUrl, $loginUrl, $dashboardUrl, $demo, $demoUrl): void {
    if ($loggedIn) {
        echo '<a class="btn btn-primary' . $extra . '" href="' . e($dashboardUrl) . '"><i class="bi bi-speedometer2" aria-hidden="true"></i> ' . e(__('Go to dashboard')) . '</a>';
    } elseif ($signup) {
        echo '<a class="btn btn-primary' . $extra . '" href="' . e($signupUrl) . '"><i class="bi bi-rocket-takeoff" aria-hidden="true"></i> ' . e(__('Start free trial')) . '</a>';
    }
    if ($demo) {
        echo '<a class="btn btn-ghost' . $extra . '" href="' . e($demoUrl) . '"><i class="bi bi-play-circle" aria-hidden="true"></i> ' . e(__('Watch live demo')) . '</a>';
    }
    if (!$loggedIn) {
        echo '<a class="btn btn-outline' . $extra . '" href="' . e($loginUrl) . '"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> ' . e(__('Login')) . '</a>';
    }
};
$nav = [
    'usecases' => __('Who is it for'),
    'features' => __('Features'),
    'how' => __('How it works'),
    'plans' => __('Plans'),
    'faq' => __('FAQ'),
];
$langUrl = static fn (string $code): string => '?lang=' . $code;
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>" class="lang-<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="<?= e($accent) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php foreach (I18n::LANGUAGES as $code => $_): ?>
<link rel="alternate" hreflang="<?= e($code) ?>" href="<?= e(base_url($code === 'en' ? '' : '?lang=' . $code)) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= e(base_url()) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($product) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($brand['logo_url'] ?: admin_url('pwa_icon.php', ['s' => 512])) ?>">
<meta property="og:locale" content="<?= e(['en' => 'en_IN', 'gu' => 'gu_IN', 'hi' => 'hi_IN'][$lang] ?? 'en_IN') ?>">
<meta name="twitter:card" content="summary">
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(admin_url('pwa_icon.php', ['s' => 192])) ?>">
<link rel="apple-touch-icon" href="<?= e(admin_url('pwa_icon.php', ['s' => 180])) ?>">
<link rel="preload" href="<?= e(base_url('assets/fonts/noto-sans-latin-700-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('landing/landing.css')) ?>">
<style nonce="<?= e($nonce) ?>">:root{--brand:<?= e($accent) ?>;--brand-dark:<?= e(Branding::shade($accent, 0.6)) ?>}</style>
<script type="application/ld+json" nonce="<?= e($nonce) ?>"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset('landing/landing.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main"><?= e(__('Skip to content')) ?></a>

<header class="topbar" id="top">
  <div class="wrap topbar-in">
    <a class="logo" href="<?= e($canonical) ?>">
      <?php if ($brand['logo_url']): ?><img src="<?= e($brand['logo_url']) ?>" alt="" height="36"><?php else: ?><span class="logo-mark" aria-hidden="true"><i class="bi bi-tv"></i></span><?php endif; ?>
      <span class="logo-text"><?= e($product) ?></span>
    </a>
    <button class="menu-btn" type="button" aria-expanded="false" aria-controls="sitenav" aria-label="<?= e(__('Menu')) ?>">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <nav class="sitenav" id="sitenav" aria-label="<?= e(__('Main')) ?>">
      <ul class="navlinks">
        <?php foreach ($nav as $id => $label): ?><li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
      </ul>
      <div class="langs" role="group" aria-label="<?= e(__('Language')) ?>">
        <?php foreach (I18n::LANGUAGES as $code => $name): ?>
          <a href="<?= e($langUrl($code)) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true" class="on"' : '' ?>><?= e($name) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="navcta">
        <?php if ($loggedIn): ?>
          <a class="btn btn-primary btn-sm" href="<?= e($dashboardUrl) ?>"><?= e(__('Go to dashboard')) ?></a>
        <?php else: ?>
          <a class="btn btn-outline btn-sm" href="<?= e($loginUrl) ?>"><?= e(__('Login')) ?></a>
          <?php if ($signup): ?><a class="btn btn-primary btn-sm" href="<?= e($signupUrl) ?>"><?= e(__('Start free trial')) ?></a><?php endif; ?>
        <?php endif; ?>
      </div>
    </nav>
  </div>
</header>

<main id="main">
<!-- ============================================================ hero -->
<section class="hero" aria-labelledby="hero-title">
  <div class="blob b1" aria-hidden="true"></div><div class="blob b2" aria-hidden="true"></div><div class="blob b3" aria-hidden="true"></div>
  <div class="wrap hero-in">
    <div class="hero-copy">
      <p class="eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> <?= e(__('Digital signage & TV management')) ?></p>
      <h1 id="hero-title"><?= e(__('Every TV screen of your business — controlled from one place')) ?></h1>
      <p class="lead"><?= e(__(':product shows live darshan, menus, offers, token numbers, timetables and notices on all your TVs. Change everything from your phone or computer — one screen, one floor, or every screen at once.', ['product' => $product])) ?></p>
      <div class="cta-row"><?php $cta(' btn-lg'); ?></div>
      <?php if ($signup && !$loggedIn): ?>
        <p class="note"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= e(__(':days-day free trial · no payment details needed', ['days' => $trialDays])) ?></p>
      <?php endif; ?>
      <?php if ($hasContact): ?>
        <p class="contact-row">
          <?php if ($phone['whatsapp']): ?><a class="chip chip-wa" href="<?= e($phone['whatsapp']) ?>" rel="noopener" target="_blank"><i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp</a><?php endif; ?>
          <?php if ($phone['tel']): ?><a class="chip" href="<?= e($phone['tel']) ?>"><i class="bi bi-telephone" aria-hidden="true"></i> <?= e($brand['support_phone']) ?></a><?php endif; ?>
          <?php if ($email !== ''): ?><a class="chip" href="mailto:<?= e($email) ?>"><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($email) ?></a><?php endif; ?>
        </p>
      <?php endif; ?>
      <ul class="works-mini" aria-label="<?= e(__('Works on')) ?>">
        <li><i class="bi bi-android2" aria-hidden="true"></i> Android TV</li>
        <li><i class="bi bi-fire" aria-hidden="true"></i> Fire TV</li>
        <li><i class="bi bi-browser-chrome" aria-hidden="true"></i> <?= e(__('Smart TV browser')) ?></li>
        <li><i class="bi bi-pc-display" aria-hidden="true"></i> PC</li>
      </ul>
    </div>
    <div class="hero-art" aria-hidden="true">
      <div class="tv">
        <div class="tv-screen">
          <div class="z-video"><span class="live"><i class="bi bi-record-fill"></i> LIVE</span><i class="bi bi-play-circle-fill play"></i><span class="z-cap"><?= e(__('Live aarti')) ?></span></div>
          <div class="z-side">
            <div class="z-clock">07:00</div>
            <div class="z-title"><?= e(__('Today\'s special')) ?></div>
            <div class="z-item"><span class="veg"></span><?= e(__('Gujarati thali')) ?></div>
            <div class="z-item"><span class="veg"></span><?= e(__('Masala chai')) ?></div>
            <div class="z-token"><small><?= e(__('Token')) ?></small> A-25</div>
          </div>
          <div class="z-ticker"><span><?= e(__('Welcome! · Evening aarti at 7:00 pm · 20% off this week · Free Wi-Fi: scan the QR code')) ?></span></div>
        </div>
        <div class="tv-stand"></div>
      </div>
      <div class="phone">
        <div class="ph-bar"></div>
        <div class="ph-row"><i class="bi bi-tv"></i> <?= e(__('Entrance')) ?> <b class="dot on"></b></div>
        <div class="ph-row"><i class="bi bi-tv"></i> <?= e(__('Counter 1')) ?> <b class="dot on"></b></div>
        <div class="ph-row"><i class="bi bi-tv"></i> <?= e(__('Waiting area')) ?> <b class="dot on"></b></div>
        <div class="ph-btn"><i class="bi bi-send-fill"></i> <?= e(__('Push now')) ?></div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ numbers -->
<section class="numbers" aria-label="<?= e(__('At a glance')) ?>">
  <div class="wrap numbers-in">
    <?php if ($stats): ?>
      <div class="num"><b><?= e(Landing::roundDown($stats['screens'])) ?></b><span><?= e(__('screens managed')) ?></span></div>
      <div class="num"><b><?= e(Landing::roundDown($stats['customers'])) ?></b><span><?= e(__('businesses')) ?></span></div>
    <?php endif; ?>
    <div class="num"><b><?= (int) $appCount ?></b><span><?= e(__('ready-made display apps')) ?></span></div>
    <div class="num"><b>3</b><span><?= e(__('languages: English, ગુજરાતી, हिन्दी')) ?></span></div>
    <div class="num"><b>4×4</b><span><?= e(__('video walls')) ?></span></div>
    <?php if (!$stats): ?><div class="num"><b>12</b><span><?= e(__('festive themes')) ?></span></div><?php endif; ?>
  </div>
</section>

<!-- ============================================================ use cases -->
<section class="sec sec-usecases" id="usecases" aria-labelledby="uc-title">
  <div class="wrap">
    <div class="sec-head">
      <p class="kicker"><?= e(__('Who is it for')) ?></p>
      <h2 id="uc-title"><?= e(__('Made for every place that has a TV')) ?></h2>
      <p><?= e(__('Temples, hotels, hospitals, schools, restaurants, shops, offices and more — each gets ready-made apps for its own needs.')) ?></p>
    </div>
    <div class="grid g4">
      <?php foreach (Landing::USE_CASES as [$icon, $color, $name, $examples]): ?>
        <article class="card uc reveal <?= e($color) ?>">
          <div class="ic"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
          <h3><?= e(__($name)) ?></h3>
          <ul class="ticks">
            <?php foreach ($examples as $x): ?><li><?= e(__($x)) ?></li><?php endforeach; ?>
          </ul>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ features -->
<section class="sec sec-features" id="features" aria-labelledby="ft-title">
  <div class="wrap">
    <div class="sec-head">
      <p class="kicker"><?= e(__('Features')) ?></p>
      <h2 id="ft-title"><?= e(__('Everything you need to run your screens')) ?></h2>
      <p><?= e(__('From one TV to hundreds across many branches — one simple panel in your browser.')) ?></p>
    </div>
    <div class="jump" role="list">
      <?php foreach (Landing::FEATURE_GROUPS as [$gid, $gicon, $gcolor, $gtitle]): ?>
        <a role="listitem" class="pill <?= e($gcolor) ?>" href="#f-<?= e($gid) ?>"><i class="bi <?= e($gicon) ?>" aria-hidden="true"></i> <?= e(__($gtitle)) ?></a>
      <?php endforeach; ?>
      <a role="listitem" class="pill c-saffron" href="#f-apps"><i class="bi bi-grid-1x2" aria-hidden="true"></i> <?= e(__('Apps on screen')) ?></a>
      <a role="listitem" class="pill c-blue" href="#f-devices"><i class="bi bi-hdd-network" aria-hidden="true"></i> <?= e(__('Works on')) ?></a>
    </div>

    <?php foreach (Landing::FEATURE_GROUPS as $i => [$gid, $gicon, $gcolor, $gtitle, $gintro, $items]): ?>
      <div class="fgroup <?= e($gcolor) ?>" id="f-<?= e($gid) ?>">
        <div class="fgroup-head">
          <span class="ic ic-lg"><i class="bi <?= e($gicon) ?>" aria-hidden="true"></i></span>
          <div><h3><?= e(__($gtitle)) ?></h3><p><?= e(__($gintro)) ?></p></div>
        </div>
        <div class="grid g3">
          <?php foreach ($items as [$icon, $t, $txt]): ?>
            <div class="feat reveal">
              <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
              <div><h4><?= e(__($t)) ?></h4><p><?= e(__($txt)) ?></p></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($gid === 'content'): ?>
        <!-- display apps -->
        <div class="fgroup c-saffron" id="f-apps">
          <div class="fgroup-head">
            <span class="ic ic-lg"><i class="bi bi-grid-1x2" aria-hidden="true"></i></span>
            <div><h3><?= e(__('Apps on screen')) ?></h3><p><?= e(__(':n ready-made display apps — fill in a simple form and the TV shows a beautiful, live screen. 12 festive themes, clock and weather included.', ['n' => $appCount])) ?></p></div>
          </div>
          <ul class="apps">
            <?php foreach (Landing::APPS as [$icon, $label]): ?>
              <li class="reveal"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i> <?= e(__($label)) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <div class="grid g2 duo">
      <div class="fgroup c-blue box">
        <div class="fgroup-head">
          <span class="ic ic-lg"><i class="bi bi-translate" aria-hidden="true"></i></span>
          <div><h3><?= e(__('Your language')) ?></h3><p><?= e(__('The admin panel and the TV screens speak English, ગુજરાતી and हिन्दी. Gujarati and Hindi fonts are built in, so text always looks right on the TV.')) ?></p></div>
        </div>
      </div>
      <div class="fgroup c-green box">
        <div class="fgroup-head">
          <span class="ic ic-lg"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
          <div><h3><?= e(__('Safe and secure')) ?></h3><p><?= e(__('Each business\'s data is fully separated from every other. Secure HTTPS connection, safely stored passwords, an audit log of every change, and automatic backups before updates.')) ?></p></div>
        </div>
      </div>
      <div class="fgroup c-violet box">
        <div class="fgroup-head">
          <span class="ic ic-lg"><i class="bi bi-boxes" aria-hidden="true"></i></span>
          <div><h3><?= e(__('Plans and modules')) ?></h3><p><?= e(__('Start small and add modules when you need them — display apps, scheduling, video walls, analytics or the hotel pack. You pay only for what you use.')) ?></p></div>
        </div>
      </div>
      <div class="fgroup c-pink box">
        <div class="fgroup-head">
          <span class="ic ic-lg"><i class="bi bi-door-open" aria-hidden="true"></i></span>
          <div><h3><?= e(__('Hotel pack')) ?></h3><p><?= e(__('Guest welcome screen, check-in / check-out, room service and requests from the guest\'s phone, feedback, local guide and PMS integration.')) ?></p></div>
        </div>
      </div>
    </div>

    <div class="fgroup c-blue" id="f-devices">
      <div class="fgroup-head">
        <span class="ic ic-lg"><i class="bi bi-hdd-network" aria-hidden="true"></i></span>
        <div><h3><?= e(__('Works on')) ?></h3><p><?= e(__('Use the TVs you already have — no expensive special hardware.')) ?></p></div>
      </div>
      <div class="grid g3">
        <?php foreach (Landing::WORKS_ON as [$icon, $t, $txt]): ?>
          <div class="feat reveal"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i><div><h4><?= e(__($t)) ?></h4><p><?= e(__($txt)) ?></p></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ how it works -->
<section class="sec sec-how" id="how" aria-labelledby="how-title">
  <div class="wrap">
    <div class="sec-head light">
      <p class="kicker"><?= e(__('How it works')) ?></p>
      <h2 id="how-title"><?= e(__('Live on your TV in 5 simple steps')) ?></h2>
      <p><?= e(__('No technician, no cables to change. If you can use WhatsApp, you can use this.')) ?></p>
    </div>
    <ol class="steps">
      <?php foreach (Landing::STEPS as $n => [$icon, $t, $txt]): ?>
        <li class="step reveal">
          <span class="step-n"><?= $n + 1 ?></span>
          <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
          <h3><?= e(__($t)) ?></h3>
          <p><?= e(__($txt)) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ============================================================ plans -->
<section class="sec sec-plans" id="plans" aria-labelledby="plans-title">
  <div class="wrap">
    <div class="sec-head">
      <p class="kicker"><?= e(__('Plans')) ?></p>
      <h2 id="plans-title"><?= e(__('Simple plans that grow with you')) ?></h2>
      <p><?= e(__('Priced per screen per month. Every plan includes screens & TVs, groups, users, push-now and the dashboard.')) ?></p>
    </div>
    <div class="grid plans-grid">
      <?php foreach ($plans as $i => $p): ?>
        <article class="plan reveal<?= $i === 1 ? ' plan-pop' : '' ?>" data-plan="<?= (int) $p['id'] ?>">
          <h3><?= e($p['name']) ?></h3>
          <?php if ($p['description'] !== ''): ?><p class="plan-desc"><?= e(__($p['description'])) ?></p><?php endif; ?>
          <div class="price">
            <?php if ($p['price'] > 0): ?>
              <b><?= e(money($p['price'])) ?></b><span><?= e(__('per screen / month')) ?></span>
            <?php else: ?>
              <b class="ask"><?= e(__('Price on request')) ?></b>
            <?php endif; ?>
          </div>
          <ul class="limits">
            <li><i class="bi bi-tv" aria-hidden="true"></i> <?= e($p['max_tvs'] === null ? __('Unlimited screens') : __('Up to :n screens', ['n' => $p['max_tvs']])) ?></li>
            <li><i class="bi bi-people" aria-hidden="true"></i> <?= e($p['max_users'] === null ? __('Unlimited users') : ($p['max_users'] === 1 ? __('1 user') : __('Up to :n users', ['n' => $p['max_users']]))) ?></li>
            <?php if ($p['storage_mb'] !== null): ?><li><i class="bi bi-hdd" aria-hidden="true"></i> <?= e(__(':size storage', ['size' => $p['storage_mb'] >= 1024 ? round($p['storage_mb'] / 1024, 1) . ' GB' : $p['storage_mb'] . ' MB'])) ?></li><?php endif; ?>
          </ul>
          <?php if ($p['all']): ?>
            <p class="all"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> <?= e(__('All modules included')) ?></p>
          <?php elseif (count($p['missing']) <= 8 && count($p['missing']) < count($p['features'])): ?>
            <p class="all"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> <?= e(__('All modules, except:')) ?></p>
            <ul class="ticks small not">
              <?php foreach ($p['missing'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
            </ul>
          <?php else: ?>
            <ul class="ticks small">
              <?php foreach (array_slice($p['features'], 0, 8) as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
              <?php if (count($p['features']) > 8): ?><li class="more"><?= e(__('+ :n more modules', ['n' => count($p['features']) - 8])) ?></li><?php endif; ?>
            </ul>
          <?php endif; ?>
          <div class="plan-cta">
            <?php if ($loggedIn): ?>
              <a class="btn btn-outline" href="<?= e($dashboardUrl) ?>"><?= e(__('Go to dashboard')) ?></a>
            <?php elseif ($signup): ?>
              <a class="btn btn-primary" href="<?= e($signupUrl) ?>"><?= e(__('Start free trial')) ?></a>
            <?php elseif ($hasContact): ?>
              <a class="btn btn-primary" href="#contact"><?= e(__('Contact us')) ?></a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$plans || !$prices): ?>
        <article class="plan plan-contact reveal">
          <h3><?= e(__('Contact us for pricing')) ?></h3>
          <p class="plan-desc"><?= e(__('Tell us how many screens you have and what you want to show — we will suggest the right plan.')) ?></p>
          <div class="plan-cta">
            <?php if ($phone['whatsapp']): ?><a class="btn btn-primary" href="<?= e($phone['whatsapp']) ?>" rel="noopener" target="_blank"><i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp</a><?php endif; ?>
            <?php if ($phone['tel']): ?><a class="btn btn-outline" href="<?= e($phone['tel']) ?>"><i class="bi bi-telephone" aria-hidden="true"></i> <?= e(__('Call us')) ?></a><?php endif; ?>
            <?php if ($email !== ''): ?><a class="btn btn-outline" href="mailto:<?= e($email) ?>"><i class="bi bi-envelope" aria-hidden="true"></i> <?= e(__('E-mail us')) ?></a><?php endif; ?>
          </div>
        </article>
      <?php endif; ?>
    </div>
    <?php if ($plans && $prices): ?><p class="fine"><?= e(__('Prices are per screen per month; taxes as applicable.')) ?></p><?php endif; ?>
  </div>
</section>

<!-- ============================================================ FAQ -->
<section class="sec sec-faq" id="faq" aria-labelledby="faq-title">
  <div class="wrap narrow">
    <div class="sec-head">
      <p class="kicker"><?= e(__('FAQ')) ?></p>
      <h2 id="faq-title"><?= e(__('Questions people ask')) ?></h2>
    </div>
    <?php
    $faq = [
        ['Do the TVs need internet all the time?', 'Internet is needed to receive new content. The TV saves the content and videos in its memory, so it keeps playing even when the internet goes off, and updates itself when the connection is back.'],
        ['Which TVs and devices can I use?', 'Android TVs and Android boxes (Android 5.0 and newer), Fire TV Stick, Smart-TV browsers (Samsung, LG), PCs and Raspberry Pi. Older Android TVs are supported too.'],
        ['My TV is not a smart TV. What can I do?', 'Connect a low-cost Android box or a Fire TV Stick to the HDMI port. It turns any TV with HDMI into a smart screen.'],
        ['How many screens can I manage?', 'From one screen to hundreds, across many branches. The number of screens depends on your plan.'],
        ['Which languages are supported?', 'English, Gujarati and Hindi — in the admin panel and on the TV screens. Each user can choose their own language.'],
        ['Can I manage it from my phone?', 'Yes. The admin panel works in the phone browser and can be installed like an app, with push alerts when a screen goes offline.'],
        ['Do I need technical knowledge?', 'No. You add a TV by scanning a QR code, and ready-made apps and templates make the screens look professional in minutes.'],
        ['Is my data safe?', 'Yes. Each business\'s data is fully separated, connections are encrypted (HTTPS), users get only the rights you give them, and every change is logged.'],
    ];
    if ($signup) {
        $faq[] = ['Can I try it for free?', 'Yes. Start a :days-day free trial online — no payment details needed. Your screens and content are kept when you choose a plan.', ['days' => $trialDays]];
    } elseif ($demo) {
        $faq[] = ['Can I try it for free?', 'Yes. Open the live demo to explore the admin panel and see the TV screens, or contact us for a trial.'];
    } else {
        $faq[] = ['Can I try it for free?', 'Contact us and we will set up a demo for you.'];
    }
    $faq[] = ['How do I get support?', $hasContact
        ? 'Call, WhatsApp or e-mail us. With remote screenshots and TV logs we can often fix problems without visiting.'
        : 'Our team helps you set up. With remote screenshots and TV logs we can often fix problems without visiting.'];
    ?>
    <div class="faq">
      <?php foreach ($faq as $k => $qa): ?>
        <details class="qa"<?= $k === 0 ? ' open' : '' ?>>
          <summary><?= e(__($qa[0])) ?><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
          <p><?= e(__($qa[1], $qa[2] ?? [])) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ final CTA -->
<section class="final" id="contact" aria-labelledby="final-title">
  <div class="blob b2" aria-hidden="true"></div>
  <div class="wrap final-in">
    <h2 id="final-title"><?= e(__('Ready to bring your screens to life?')) ?></h2>
    <p><?= e(__('Set up your first TV in 5 minutes. Change what it shows from anywhere, any time.')) ?></p>
    <div class="cta-row center"><?php $cta(' btn-lg'); ?></div>
    <?php if ($hasContact): ?>
      <p class="contact-row center">
        <?php if ($phone['whatsapp']): ?><a class="chip chip-wa" href="<?= e($phone['whatsapp']) ?>" rel="noopener" target="_blank"><i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp</a><?php endif; ?>
        <?php if ($phone['tel']): ?><a class="chip" href="<?= e($phone['tel']) ?>"><i class="bi bi-telephone" aria-hidden="true"></i> <?= e($brand['support_phone']) ?></a><?php endif; ?>
        <?php if ($email !== ''): ?><a class="chip" href="mailto:<?= e($email) ?>"><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($email) ?></a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <div>
      <div class="logo foot-logo"><span class="logo-mark" aria-hidden="true"><i class="bi bi-tv"></i></span><span class="logo-text"><?= e($product) ?></span></div>
      <p><?= e(__('Digital signage & TV management for every business')) ?></p>
    </div>
    <nav aria-label="<?= e(__('Footer')) ?>">
      <ul>
        <?php if ($loggedIn): ?><li><a href="<?= e($dashboardUrl) ?>"><?= e(__('Go to dashboard')) ?></a></li><?php else: ?><li><a href="<?= e($loginUrl) ?>"><?= e(__('Login')) ?></a></li><?php endif; ?>
        <?php if ($signup && !$loggedIn): ?><li><a href="<?= e($signupUrl) ?>"><?= e(__('Start free trial')) ?></a></li><?php endif; ?>
        <?php if ($demo): ?><li><a href="<?= e($demoUrl) ?>"><?= e(__('Live demo')) ?></a></li><?php endif; ?>
        <li><a href="#features"><?= e(__('Features')) ?></a></li>
        <li><a href="#plans"><?= e(__('Plans')) ?></a></li>
      </ul>
    </nav>
    <div class="langs foot-langs">
      <?php foreach (I18n::LANGUAGES as $code => $name): ?>
        <a href="<?= e($langUrl($code)) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true" class="on"' : '' ?>><?= e($name) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="wrap copy">
    <span>© <?= date('Y') ?> <?= e($product) ?></span>
    <?php if (trim($brand['footer']) !== ''): ?><span><?= e($brand['footer']) ?></span><?php endif; ?>
  </div>
</footer>
<a class="totop" href="#top" aria-label="<?= e(__('Back to top')) ?>"><i class="bi bi-arrow-up" aria-hidden="true"></i></a>
</body>
</html>
