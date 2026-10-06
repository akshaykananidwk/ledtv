<?php
/**
 * Guest web app (#2 #3 #8): /g/{token} (Apache: g/.htaccess rewrite; built-in server: PATH_INFO;
 * fallback /g/?t={token}). No login — the room's secret token (rotated on check-in / check-out) is
 * the credential. Mobile-first, no external CDN, EN / GU / HI. The page embeds the initial data;
 * the app then talks to the JSON API under /api/guest/{token}.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
// The token is in the URL: never leak it in a Referer (e.g. Google review link).
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$token = '';
$pi = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($pi !== '' && $pi !== '/') {
    $token = trim($pi, '/');
} elseif (is_string($_GET['t'] ?? null)) {
    $token = $_GET['t'];
} else {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/g/([A-Za-z0-9]+)/?$#', $path, $m)) {
        $token = $m[1];
    }
}

/** English keys of every text the app shows (translated server-side, lang/*_guests.php). */
$strings = [
    'Room Service',
    'Requests',
    'Feedback',
    'Info',
    'Room',
    'Welcome :name',
    'Your cart',
    'Place order',
    'Notes for the kitchen (optional)',
    'Add',
    'Not available now',
    'Available :h',
    'Total',
    'View cart',
    'Your orders',
    'Order placed! We will keep you updated here.',
    'Received',
    'Accepted',
    'Preparing',
    'Delivered',
    'Cancelled',
    'Sent',
    'Done',
    'What do you need?',
    'Send request',
    'Time',
    'Note (optional)',
    'Request sent. Our team is on it.',
    'Your requests',
    'How was your stay?',
    'Cleanliness',
    'Staff',
    'Food',
    'Comment (optional)',
    'Send feedback',
    'Thank you for your feedback!',
    'Would you share your experience on Google?',
    'Write a Google review',
    'Update feedback',
    'Wi-Fi',
    'Network',
    'Password',
    'Copy',
    'Copied',
    'Checkout',
    'Call reception',
    'Something went wrong. Please try again.',
    'Too many requests. Please wait a moment.',
    'This link is not valid any more',
    'Please scan the QR code on your TV again, or call reception.',
    'Cancel',
    'Close',
    'Veg',
    'Non-veg',
    'Egg',
    'The menu is not available right now.',
    'No orders yet.',
    'No requests yet.',
    'Tap a star to rate',
    'Remove',
    'at :t',
    'Checkout time',
    'Offline — check your Wi-Fi connection.',
    'Order #:n',
    'Clear cart',
    'Your feedback',
    'Please choose a time.',
    'Please choose a rating.',
    'Reception',
    'Room :room',
    'items',
    'Total :t',
    'Please enable JavaScript to use this page.',
];
$tr = static function (string $lang) use ($strings): array {
    $out = [];
    foreach ($strings as $k) {
        $out[$k] = I18n::translate($k, $lang);
    }
    return $out;
};

$ctx = null;
$limited = false;
if (Guests::validTokenFormat($token)) {
    if (RateLimiter::hit('guest:page:' . client_ip(), 120, 60) > 0) {
        $limited = true;
    } else {
        $ctx = Guests::resolveToken($token);
    }
}
if ($ctx === null && !$limited && RateLimiter::hit('guest:bad:' . client_ip(), 30, 600) > 0) {
    $limited = true;
}

if ($ctx === null) {
    http_response_code($limited ? 429 : 404);
    $brand = Branding::get(0);
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= e(I18n::translate('This link is not valid any more', 'en')) ?></title>
<link rel="stylesheet" href="<?= e(asset('guest/guest.css')) ?>"></head>
<body class="g-expired"><main class="g-expired-box">
  <div class="g-expired-icon" aria-hidden="true">🔑</div>
  <?php foreach (array_keys(I18n::GUEST_LANGUAGES) as $l): ?>
    <section lang="<?= e($l) ?>">
      <h1><?= e(I18n::translate($limited ? 'Too many requests. Please wait a moment.' : 'This link is not valid any more', $l)) ?></h1>
      <p><?= e(I18n::translate('Please scan the QR code on your TV again, or call reception.', $l)) ?></p>
    </section>
  <?php endforeach; ?>
  <p class="g-muted"><?= e($brand['product']) ?></p>
</main></body></html>
<?php
    exit;
}

$data = GuestServices::appData($ctx);
$lang = $ctx['lang'];
$color = clean_color($data['hotel']['color'] ?? '', '#7B1FA2');
$config = [
    'api' => base_url('api/index.php') . '?r=' . rawurlencode('guest/' . $token),
    'lang' => $lang,
    'languages' => I18n::GUEST_LANGUAGES,
    'strings' => ['en' => $tr('en'), 'gu' => $tr('gu'), 'hi' => $tr('hi')],
    'data' => $data,
];
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($color) ?>">
<title><?= e($data['hotel']['name']) ?> · <?= e(I18n::translate('Room', $lang)) ?> <?= e($data['room']['number']) ?></title>
<link rel="stylesheet" href="<?= e(asset('guest/guest.css')) ?>">
<link rel="stylesheet" href="<?= e(base_url('g/theme.php') . '?c=' . rawurlencode(ltrim($color, '#'))) ?>">
<script type="application/json" id="gConfig"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?></script>
<script src="<?= e(asset('guest/guest.js')) ?>" defer></script>
</head>
<body>
<header class="g-head">
  <div class="g-head-row">
    <?php if (!empty($data['hotel']['logo_url'])): ?><img class="g-logo" src="<?= e($data['hotel']['logo_url']) ?>" alt=""><?php endif; ?>
    <div class="g-head-text">
      <div class="g-hotel"><?= e($data['hotel']['name']) ?></div>
      <div class="g-room" data-t-room><?= e(I18n::translate('Room', $lang)) ?> <?= e($data['room']['number']) ?></div>
    </div>
    <div class="g-langs" role="group" aria-label="Language">
      <?php foreach (['en' => 'EN', 'gu' => 'ગુ', 'hi' => 'हि'] as $code => $short): ?>
        <button type="button" data-lang="<?= e($code) ?>" lang="<?= e($code) ?>" aria-pressed="<?= $code === $lang ? 'true' : 'false' ?>" title="<?= e(I18n::GUEST_LANGUAGES[$code]) ?>"><?= e($short) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="g-greet" data-greet></div>
</header>
<main id="gMain" class="g-main" aria-live="polite">
  <noscript><p class="g-card"><?= e(I18n::translate('Please enable JavaScript to use this page.', $lang)) ?></p></noscript>
</main>
<nav class="g-tabs" id="gTabs" aria-label="Sections"></nav>
<div class="g-cartbar" id="gCartBar" hidden></div>
<div class="g-sheet" id="gSheet" hidden role="dialog" aria-modal="true"><div class="g-sheet-panel" id="gSheetPanel"></div></div>
<div class="g-toast" id="gToast" role="status" hidden></div>
</body>
</html>
