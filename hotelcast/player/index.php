<?php
declare(strict_types=1);
/**
 * Web player (2.4, #45): open this page in any browser — Samsung Tizen / LG webOS browsers, Fire TV Silk,
 * a PC or mini-PC in kiosk mode, Raspberry Pi Chromium — and it behaves like a TV running the app:
 * QR / manual setup, then it registers as a device of type "web", long-polls the device API, renders the
 * content object, runs commands and sends heartbeats. All logic is in assets/player/player.js (ES5, no
 * build step). No login: the page is public, like the TV app; the device token lives in localStorage.
 * See docs/modules/web_player.md.
 */
require __DIR__ . '/../core/bootstrap.php';

WebPlayer::sendHeaders();
$cfg = WebPlayer::config($_GET, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$lang = $cfg['lang'];
$product = $cfg['product'];
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#000000">
<title><?= e($product) ?> · <?= e(I18n::translate('Web player', $lang)) ?></title>
<link rel="stylesheet" href="<?= e(WebPlayer::asset('player/player.css')) ?>">
</head>
<body class="hc-player">
<div id="hc-root"><div class="hc-boot"><div class="hc-spinner"></div><div><?= e($product) ?></div></div></div>
<noscript><div class="hc-noscript">JavaScript is required for the web player.</div></noscript>
<script type="application/json" id="hc-config"><?= WebPlayer::embed($cfg) ?></script>
<script src="<?= e(WebPlayer::asset('player/player.js')) ?>"></script>
</body>
</html>
