<?php
declare(strict_types=1);

/**
 * Admin layout header. Expects: $pageTitle (string), $activeNav (string, nav key),
 * optional $extraScripts (asset paths loaded with defer), $extraStyles.
 *
 * 2.6 panels (docs/modules/panels.md): the shell follows Panel::current() — the Super Admin console
 * (platform), the Reseller panel (reseller), the Customer workspace (customer, also while a platform
 * user works inside a customer — then with the impersonation banner) and the chain dashboard.
 */
require_once __DIR__ . '/common.php';

$user = $user ?? Auth::user();
$pageTitle = $pageTitle ?? Branding::get()['product'];
$activeNav = $activeNav ?? '';
$extraScripts = $extraScripts ?? [];
$extraStyles = $extraStyles ?? [];
$hdrBrand = Branding::get();
$inHotel = Tenant::has();
$hdrPanel = $user ? Panel::current() : 'customer';
$hdrTheme = Panel::theme($hdrPanel);
$hdrConsole = $user ? Panel::consoleOf($user) : null;
$hdrImpersonating = $user && Panel::impersonating();
$hotelName = $inHotel ? (string) Settings::get('hotel_name', $hdrBrand['product']) : $hdrBrand['product'];
$hotelLogo = $inHotel ? media_url((string) Settings::get('hotel_logo', '')) : null;
$hotelLogo = $hotelLogo ?: $hdrBrand['logo_url'];
$lang = I18n::lang();
// Name shown in the brand block / title: the customer's business in the workspace, the console name otherwise.
$hdrShellName = $hdrPanel === 'customer' ? $hotelName : $hdrTheme['name'];
if ($hdrPanel === 'reseller' && !empty($user['reseller_id'])) {
    $hdrResellerName = (string) (DB::value('SELECT name FROM resellers WHERE id = :id', ['id' => (int) $user['reseller_id']]) ?: '');
    $hdrShellName = $hdrResellerName !== '' ? $hdrResellerName : $hdrShellName;
}
$hdrAccent = $hdrTheme['accent'] ?? $hdrBrand['color'];
$hdrAccentDark = $hdrTheme['accent_dark'] ?? Branding::shade($hdrBrand['color']);

// Sidebar from the navigation registry (admin/partials/nav.d/*.php), limited to the panel's sections.
$navSections = $user ? Panel::navSections() : [];
$sectionTitles = ['hotel' => $hotelName, 'reseller' => __('Reseller'), 'platform' => __('Platform'), 'chain' => __('Chain')];
$groupTitles = ['Manage' => __('Manage'), 'Commercial' => __('Commercial'), 'System' => __('System'), 'Tools' => __('Tools')];
$hdrSearch = $user && in_array($hdrPanel, ['platform', 'reseller'], true) && Auth::can('platform.screens') && is_file(HC_ROOT . '/admin/platform_search.php');

// Users limited to some TVs (core/Access.php): their emergencies / TVs only.
$hdrEmergencies = $user && $inHotel ? hc_visible_emergencies() : [];
$hdrStats = ['online' => 0, 'devices' => 0];
if ($user && $inHotel && $hdrPanel === 'customer') {
    [$hdrAcc, $hdrAp] = Access::roomSql('room_id');
    foreach (DB::all('SELECT status, last_ping FROM devices WHERE hotel_id = :hid AND is_revoked = 0 AND room_id IS NOT NULL' . $hdrAcc, ['hid' => Tenant::id()] + $hdrAp) as $d) {
        $hdrStats['devices']++;
        if (DeviceManager::isOnline($d)) {
            $hdrStats['online']++;
        }
    }
}
$hdrHotelState = $inHotel ? Tenant::state() : 'active';
$hdrLicense = $user ? License::banner() : null;
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="hc-ajax" content="<?= e(admin_url('ajax.php')) ?>">
<meta name="hc-panel" content="<?= e($hdrPanel) ?>">
<title><?= e($pageTitle) ?> · <?= e($hdrShellName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<?php foreach ($extraStyles as $s): ?>
<link rel="stylesheet" href="<?= e(asset($s)) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>:root{--hc-accent:<?= e($hdrAccent) ?>;--hc-accent-dark:<?= e($hdrAccentDark) ?>}</style>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>" defer></script>
<?php foreach ($extraScripts as $s): ?>
<script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach; ?>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<?php // Module hook: admin/partials/head.d/*.php print extra <head> tags (name order; $user may be null).
foreach (glob(__DIR__ . '/head.d/*.php') ?: [] as $__hd) { include $__hd; } unset($__hd); ?>
<script>window.HC_I18N = <?= json_embed([
    'ok' => __('OK'), 'cancel' => __('Cancel'), 'confirm' => __('Please confirm'), 'error' => __('Something went wrong'),
    'saved' => __('Saved.'), 'uploading' => __('Uploading…'), 'processing' => __('Processing on server…'),
    'weak' => __('Weak'), 'fair' => __('Fair'), 'good' => __('Good'), 'strong' => __('Strong'),
    'stop_emergency' => __('Stop the emergency message on all TVs?'), 'never' => __('never'),
    'online' => __('Online'), 'offline' => __('Offline'), 'no_tv' => __('No TV'),
    'on' => __('On'), 'off' => __('Off'),
]) ?>;</script>
</head>
<body class="hc-admin lang-<?= e($lang) ?> panel-<?= e($hdrPanel) ?>" data-panel="<?= e($hdrPanel) ?>"<?= $hdrImpersonating ? ' data-impersonating="1"' : '' ?>>
<?php if ($user): ?>
<div class="hc-layout">
  <aside class="hc-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="hcSidebar" aria-labelledby="hcSidebarLabel">
    <div class="offcanvas-header hc-brand">
      <a class="d-flex align-items-center gap-2 text-decoration-none text-white" href="<?= e(admin_url(Panel::home($hdrPanel))) ?>" id="hcSidebarLabel">
        <?php if ($hdrPanel === 'customer' && $hotelLogo): ?><img src="<?= e($hotelLogo) ?>" alt="" class="hc-brand-logo">
        <?php else: ?><span class="hc-brand-icon"><i class="bi <?= e($hdrTheme['icon']) ?>"></i></span><?php endif; ?>
        <span class="hc-brand-text">
          <?php if ($hdrPanel === 'customer'): ?><strong><?= e($hotelName) ?></strong><small><?= e($hdrBrand['product']) ?></small>
          <?php else: ?><strong><?= e($hdrTheme['name']) ?></strong><small><?= e($hdrPanel === 'reseller' ? $hdrShellName : $hdrBrand['product']) ?></small><?php endif; ?>
        </span>
      </a>
      <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#hcSidebar" aria-label="<?= e(__('Close')) ?>"></button>
    </div>
    <div class="offcanvas-body p-0">
      <nav class="hc-nav nav flex-column w-100" data-nav-panel="<?= e($hdrPanel) ?>">
        <?php foreach ($navSections as $hdrSection => $hdrNavItems): ?>
          <?php if (count($navSections) > 1): ?><div class="hc-nav-section"><?= e($sectionTitles[$hdrSection] ?? ucfirst($hdrSection)) ?></div><?php endif; ?>
          <?php foreach (Panel::navGroups($hdrNavItems) as $hdrGroup => $hdrGroupItems): ?>
            <?php if ($hdrGroup !== ''): ?><div class="hc-nav-section"><?= e($groupTitles[$hdrGroup] ?? $hdrGroup) ?></div><?php endif; ?>
            <?php foreach ($hdrGroupItems as [$key, $href, $perm, $icon, $label]): ?>
            <a class="nav-link<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e(admin_url($href)) ?>" data-nav="<?= e($key) ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
              <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
            </a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>
      <div class="hc-sidebar-foot small">
        <?php if ($hdrImpersonating && $hdrConsole): ?>
          <a class="hc-sidebar-console" href="<?= e(admin_url(Panel::home($hdrConsole))) ?>"><i class="bi <?= e(Panel::theme($hdrConsole)['icon']) ?>"></i> <?= e(Panel::theme($hdrConsole)['name']) ?></a>
        <?php endif; ?>
        <?= e($hdrBrand['product']) ?> v<?= e(Version::current()['version']) ?>
      </div>
    </div>
  </aside>

  <div class="hc-main">
    <?php if ($hdrImpersonating): ?>
    <div class="hc-context-banner hc-impersonation" role="status" data-impersonation-banner>
      <i class="bi bi-person-badge-fill fs-5"></i>
      <div class="flex-grow-1 min-w-0">
        <span class="hc-impersonation-text"><?= e(__('You are managing customer')) ?> <strong><?= e($hotelName) ?></strong>
          <span class="hc-impersonation-as"><?= e(__('as :r', ['r' => Panel::actorLabel()])) ?></span></span>
        <span class="d-none d-md-inline small opacity-75"> · <?= e(__('Changes here are made in the customer\'s name and logged in its activity log.')) ?></span>
      </div>
      <?php $hdrBack = Auth::backPage(); ?>
      <form method="post" action="<?= e(admin_url($hdrBack)) ?>" class="m-0">
        <?= Csrf::field() ?><input type="hidden" name="op" value="leave">
        <button class="btn btn-sm btn-light fw-semibold hc-impersonation-exit"><i class="bi bi-box-arrow-left"></i> <?= e($hdrBack === 'chain.php' ? __('Back to chain') : __('Exit workspace')) ?></button>
      </form>
    </div>
    <?php endif; ?>
    <header class="hc-topbar">
      <button class="btn btn-link text-body d-lg-none px-1 me-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#hcSidebar" aria-controls="hcSidebar" aria-label="<?= e(__('Menu')) ?>">
        <i class="bi bi-list fs-3"></i>
      </button>
      <?php if ($hdrTheme['badge'] !== ''): ?><span class="hc-panel-badge" data-panel-badge><i class="bi <?= e($hdrTheme['icon']) ?>"></i> <?= e($hdrTheme['badge']) ?></span><?php endif; ?>
      <div class="hc-topbar-title text-truncate">
        <span class="d-none d-sm-inline opacity-75"><?= e($hdrShellName) ?> /</span> <strong><?= e($pageTitle) ?></strong>
      </div>
      <?php if ($hdrSearch): ?>
      <form class="hc-global-search d-none d-md-flex" method="get" action="<?= e(admin_url('platform_search.php')) ?>" role="search">
        <i class="bi bi-search"></i>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'platform_search.php' ? req_str('q', $_GET, 100) : '') ?>" placeholder="<?= e(__('Search customers, screens, TVs, users…')) ?>" aria-label="<?= e(__('Global search')) ?>" autocomplete="off">
      </form>
      <?php endif; ?>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($hdrSearch): ?><a class="btn btn-sm btn-light border d-md-none" href="<?= e(admin_url('platform_search.php')) ?>" title="<?= e(__('Global search')) ?>"><i class="bi bi-search"></i></a><?php endif; ?>
        <?php if ($inHotel && $hdrPanel === 'customer'): ?>
        <a href="<?= e(admin_url('rooms.php')) ?>" class="badge rounded-pill text-decoration-none hc-online-badge <?= $hdrStats['devices'] && $hdrStats['online'] < $hdrStats['devices'] ? 'text-bg-warning' : 'text-bg-success' ?>" id="hcOnlineBadge" title="<?= e(__('TVs online')) ?>">
          <i class="bi bi-tv"></i> <span data-online><?= (int) $hdrStats['online'] ?></span>/<span data-total><?= (int) $hdrStats['devices'] ?></span>
          <span class="d-none d-md-inline"><?= e(__('online')) ?></span>
        </a>
        <?php endif; ?>
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
            <li><h6 class="dropdown-header"><?= e($user['username']) ?> · <?= e(Auth::roleName($user)) ?></h6></li>
            <li><a class="dropdown-item" href="<?= e(admin_url('profile.php')) ?>"><i class="bi bi-person-gear me-2"></i><?= e(__('My profile')) ?></a></li>
            <?php if ($hdrConsole === 'platform' && $hdrPanel !== 'platform'): ?><li><a class="dropdown-item" href="<?= e(admin_url(Panel::home('platform'))) ?>"><i class="bi bi-shield-lock me-2"></i><?= e(__('Super Admin console')) ?></a></li><?php endif; ?>
            <?php if ($hdrConsole === 'reseller' && $hdrPanel !== 'reseller'): ?><li><a class="dropdown-item" href="<?= e(admin_url(Panel::home('reseller'))) ?>"><i class="bi bi-briefcase me-2"></i><?= e(__('Reseller panel')) ?></a></li><?php endif; ?>
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

    <?php if ($inHotel && Features::bypass() && ($hdrOff = Features::disabledKeys())): // 2.5 plans: platform admin sees the customer's view ?>
    <div class="alert alert-secondary rounded-0 mb-0 small" role="status" data-plan-banner>
      <i class="bi bi-box-seam"></i> <?= e(__('Not in this customer\'s plan (hidden from the customer, open for you):')) ?> <?= e(implode(', ', array_map([Features::class, 'label'], $hdrOff))) ?>
    </div>
    <?php endif; ?>
    <?php if ($inHotel && $hdrHotelState !== 'active' && $hdrPanel === 'customer'): ?>
    <div class="alert alert-danger rounded-0 mb-0 d-flex flex-wrap gap-2 align-items-center" role="alert">
      <i class="bi bi-pause-circle-fill fs-5"></i>
      <div class="flex-grow-1"><strong><?= e($hdrHotelState === 'expired' ? __('This account has expired.') : __('This account is suspended.')) ?></strong>
        <?= e(Auth::isPlatformUser() ? __('TVs show the "service paused" screen.') : __('TVs show the "service paused" screen and changes are disabled. Please contact your provider.')) ?></div>
      <?php if (Auth::can('billing.view') && is_file(HC_ROOT . '/admin/billing.php') && License::mode() === 'saas'): ?><a class="btn btn-sm btn-light" href="<?= e(admin_url('billing.php')) ?>"><i class="bi bi-receipt"></i> <?= e(__('Billing')) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($hdrLicense): ?>
    <div class="alert alert-<?= e($hdrLicense['type']) ?> rounded-0 mb-0 small" role="alert"><i class="bi bi-key"></i> <?= e($hdrLicense['text']) ?></div>
    <?php endif; ?>

    <?php if ($hdrEmergencies && $hdrPanel === 'customer'): ?>
    <div class="hc-emergency-banner" role="alert">
      <i class="bi bi-exclamation-triangle-fill fs-4"></i>
      <div class="flex-grow-1">
        <strong><?= e(__('EMERGENCY MESSAGE IS ON')) ?>:</strong>
        <?= e(implode(' · ', array_map(fn ($b) => $b['title'], $hdrEmergencies))) ?>
      </div>
      <?php if (Auth::can('broadcast.emergency') && array_filter($hdrEmergencies, [Access::class, 'canBroadcast'])): ?>
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
