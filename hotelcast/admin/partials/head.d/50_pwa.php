<?php
/**
 * PWA head tags (#14): manifest (per-hotel branding, fetched with the session cookie), theme colour,
 * icons. Included by header.php (admin/partials/head.d hook).
 */
declare(strict_types=1);

$__pwaBrand = Branding::get();
?>
<link rel="manifest" href="<?= e(admin_url('manifest.php')) ?>" crossorigin="use-credentials">
<meta name="theme-color" content="<?= e($__pwaBrand['color']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e($__pwaBrand['product']) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(admin_url('pwa_icon.php', ['s' => 192])) ?>">
<link rel="apple-touch-icon" href="<?= e(admin_url('pwa_icon.php', ['s' => 180])) ?>">
<link rel="stylesheet" href="<?= e(asset('css/pwa.css')) ?>">
<?php unset($__pwaBrand);
