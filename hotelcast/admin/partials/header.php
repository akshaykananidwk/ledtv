<?php
declare(strict_types=1);

/**
 * Admin layout header. Expects: $pageTitle (string), $activeNav (string, nav key),
 * optional $extraScripts (asset paths loaded with defer), $extraStyles.
 */
require_once __DIR__ . '/common.php';

$user = $user ?? Auth::user();
$pageTitle = $pageTitle ?? 'HotelCast';
$activeNav = $activeNav ?? '';
$extraScripts = $extraScripts ?? [];
$extraStyles = $extraStyles ?? [];
$hotelName = (string) Settings::get('hotel_name', 'HotelCast');
$hotelLogo = media_url((string) Settings::get('hotel_logo', ''));
$lang = I18n::lang();

$navItems = [
    ['index', 'index.php', 'dashboard.view', 'bi-speedometer2', __('Dashboard')],
    ['rooms', 'rooms.php', 'rooms.view', 'bi-tv', __('Rooms & TVs')],
    ['groups', 'groups.php', 'groups.manage', 'bi-collection', __('Groups')],
    ['content', 'content.php', 'content.view', 'bi-images', __('Content Library')],
    ['playlists', 'playlists.php', 'playlists.manage', 'bi-collection-play', __('Playlists')],
    ['broadcast', 'broadcast.php', 'broadcast.send', 'bi-broadcast-pin', __('Broadcast')],
    ['schedule', 'schedule.php', 'schedule.manage', 'bi-calendar-week', __('Schedule')],
    ['power', 'power.php', 'schedule.manage', 'bi-power', __('TV Power')],
    ['apk', 'apk.php', 'apk.manage', 'bi-android2', __('APK Manager')],
    ['logs', 'logs.php', 'logs.view', 'bi-journal-text', __('Logs & History')],
    ['users', 'users.php', 'users.manage', 'bi-people', __('Users')],
    ['settings', 'settings.php', 'settings.manage', 'bi-gear', __('Settings')],
    ['update', 'update.php', 'update.manage', 'bi-cloud-arrow-down', __('Auto-Update')],
];

$hdrEmergencies = $user ? Broadcaster::activeEmergencies() : [];
$hdrStats = ['online' => 0, 'devices' => 0];
if ($user) {
    foreach (DB::all('SELECT status, last_ping FROM devices WHERE is_revoked = 0 AND room_id IS NOT NULL') as $d) {
        $hdrStats['devices']++;
        if (DeviceManager::isOnline($d)) {
            $hdrStats['online']++;
        }
    }
}
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="hc-ajax" content="<?= e(admin_url('ajax.php')) ?>">
<title><?= e($pageTitle) ?> · <?= e($hotelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<?php foreach ($extraStyles as $s): ?>
<link rel="stylesheet" href="<?= e(asset($s)) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>" defer></script>
<?php foreach ($extraScripts as $s): ?>
<script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach; ?>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<script>window.HC_I18N = <?= json_embed([
    'ok' => __('OK'), 'cancel' => __('Cancel'), 'confirm' => __('Please confirm'), 'error' => __('Something went wrong'),
    'saved' => __('Saved.'), 'uploading' => __('Uploading…'), 'processing' => __('Processing on server…'),
    'weak' => __('Weak'), 'fair' => __('Fair'), 'good' => __('Good'), 'strong' => __('Strong'),
    'stop_emergency' => __('Stop the emergency message on all TVs?'), 'never' => __('never'),
    'online' => __('Online'), 'offline' => __('Offline'), 'no_tv' => __('No TV'),
]) ?>;</script>
</head>
<body class="hc-admin lang-<?= e($lang) ?>">
<?php if ($user): ?>
<div class="hc-layout">
  <aside class="hc-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="hcSidebar" aria-labelledby="hcSidebarLabel">
    <div class="offcanvas-header hc-brand">
      <a class="d-flex align-items-center gap-2 text-decoration-none text-white" href="<?= e(admin_url('index.php')) ?>" id="hcSidebarLabel">
        <?php if ($hotelLogo): ?><img src="<?= e($hotelLogo) ?>" alt="" class="hc-brand-logo"><?php else: ?><span class="hc-brand-icon"><i class="bi bi-tv"></i></span><?php endif; ?>
        <span class="hc-brand-text"><strong>HotelCast</strong><small><?= e($hotelName) ?></small></span>
      </a>
      <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#hcSidebar" aria-label="<?= e(__('Close')) ?>"></button>
    </div>
    <div class="offcanvas-body p-0">
      <nav class="hc-nav nav flex-column w-100">
        <?php foreach ($navItems as [$key, $href, $perm, $icon, $label]): ?>
          <?php if (!Auth::can($perm)) continue; ?>
          <a class="nav-link<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e(admin_url($href)) ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
            <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="hc-sidebar-foot small">
        HotelCast v<?= e(Version::current()['version']) ?>
      </div>
    </div>
  </aside>

  <div class="hc-main">
    <header class="hc-topbar">
      <button class="btn btn-link text-body d-lg-none px-1 me-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#hcSidebar" aria-controls="hcSidebar" aria-label="<?= e(__('Menu')) ?>">
        <i class="bi bi-list fs-3"></i>
      </button>
      <div class="hc-topbar-title text-truncate">
        <span class="d-none d-sm-inline text-muted"><?= e($hotelName) ?> /</span> <strong><?= e($pageTitle) ?></strong>
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <a href="<?= e(admin_url('rooms.php')) ?>" class="badge rounded-pill text-decoration-none hc-online-badge <?= $hdrStats['devices'] && $hdrStats['online'] < $hdrStats['devices'] ? 'text-bg-warning' : 'text-bg-success' ?>" id="hcOnlineBadge" title="<?= e(__('TVs online')) ?>">
          <i class="bi bi-tv"></i> <span data-online><?= (int) $hdrStats['online'] ?></span>/<span data-total><?= (int) $hdrStats['devices'] ?></span>
          <span class="d-none d-md-inline"><?= e(__('online')) ?></span>
        </a>
        <div class="dropdown">
          <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e(__('Language')) ?>">
            <i class="bi bi-translate"></i> <span class="d-none d-sm-inline"><?= e(I18n::LANGUAGES[$lang]) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach (I18n::LANGUAGES as $code => $name): ?>
              <li><a class="dropdown-item js-lang<?= $code === $lang ? ' active' : '' ?>" href="#" data-lang="<?= e($code) ?>"><?= e($name) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="dropdown">
          <button class="btn btn-sm btn-primary dropdown-toggle d-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i> <span class="d-none d-md-inline text-truncate" style="max-width:9rem"><?= e($user['full_name'] ?: $user['username']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?= e($user['username']) ?> · <?= e(role_label($user['role'])) ?></h6></li>
            <li><a class="dropdown-item" href="<?= e(admin_url('profile.php')) ?>"><i class="bi bi-person-gear me-2"></i><?= e(__('My profile')) ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(admin_url('logout.php')) ?>" class="m-0">
                <?= Csrf::field() ?>
                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i><?= e(__('Log out')) ?></button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <?php if ($hdrEmergencies): ?>
    <div class="hc-emergency-banner" role="alert">
      <i class="bi bi-exclamation-triangle-fill fs-4"></i>
      <div class="flex-grow-1">
        <strong><?= e(__('EMERGENCY MESSAGE IS ON')) ?>:</strong>
        <?= e(implode(' · ', array_map(fn ($b) => $b['title'], $hdrEmergencies))) ?>
      </div>
      <?php if (Auth::can('broadcast.emergency')): ?>
        <button type="button" class="btn btn-light btn-sm fw-bold js-emergency-stop" data-id="0"><i class="bi bi-stop-circle"></i> <?= e(__('Stop')) ?></button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <main class="hc-content container-fluid">
      <?= flash_show() ?>
<?php else: ?>
<main class="hc-content-guest">
  <?= flash_show() ?>
<?php endif; ?>
