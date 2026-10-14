<?php
/**
 * 2.9 Menu settings (docs/modules/menu_settings.md, core/NavMenu.php): reorder the sidebar (drag & drop or
 * Up / Down buttons) and hide items.
 *
 *   ?scope=business  Settings → Menu: the customer's default menu for all its users (settings.manage).
 *   ?scope=me        Profile → My menu: the user's own menu in the current panel (every user; also the
 *                    Super Admin console, the reseller panel and the chain dashboard).
 *
 * Only the sidebar changes: hidden pages keep working by URL for users with permission, and only items the
 * editor can already see are listed (permission, plan, mode — hc_nav_sections()).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require();
Csrf::check();
$me = (int) $user['id'];
$panel = Panel::current();
$canBusiness = $panel === 'customer' && Tenant::has() && Auth::can('settings.manage');
$scopeIn = req_str('scope', $_POST + $_GET, 20);
$scope = $scopeIn === 'me' || ($scopeIn === '' && !$canBusiness) ? 'me' : 'business';
if ($scope === 'business') {
    if ($panel !== 'customer' || !Tenant::has()) {
        redirect(admin_url('menu_settings.php', ['scope' => 'me']));
    }
    require_can('settings.manage');
}
$personalAllowed = NavMenu::personalAllowed($panel);

// Items the editor can see, in the built-in order (no menu settings applied).
$sections = Panel::defaultSections();
$allowed = [];
foreach ($sections as $items) {
    $allowed = array_merge($allowed, array_keys($items));
}
$business = $panel === 'customer' && Tenant::has() ? NavMenu::business() : null;
$personal = NavMenu::personal($user, $panel);
$cfg = $scope === 'business' ? $business : ($personal ?? $business);
$selfUrl = admin_url('menu_settings.php', ['scope' => $scope]);

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($scope === 'me' && !$personalAllowed) {
        flash('warning', __('Your personal menu applies to your own console. Inside a customer you see the customer\'s menu.'));
    } elseif ($op === 'save') {
        $new = NavMenu::fromPost($_POST, $allowed, $panel, $cfg);
        if ($scope === 'business') {
            NavMenu::setBusiness(Tenant::id(), $new);
            ActivityLog::add('menu_settings', 'settings', null, 'Menu: ' . count($new['hidden']) . ' hidden item(s), custom order');
            flash('success', __('The menu was saved for all users of your business.'));
        } else {
            NavMenu::setPersonal($me, $panel, $new);
            flash('success', __('Your menu was saved.'));
        }
    } elseif ($op === 'reset') {
        if ($scope === 'business') {
            NavMenu::setBusiness(Tenant::id(), null);
            ActivityLog::add('menu_settings', 'settings', null, 'Menu reset to default');
            flash('success', __('The menu was reset to the default order.'));
        } else {
            NavMenu::setPersonal($me, $panel, null);
            flash('success', $panel === 'customer' ? __('You now use your business\'s menu.') : __('The menu was reset to the default order.'));
        }
    }
    redirect($selfUrl);
}

$hiddenKeys = array_flip(NavMenu::hiddenKeys($cfg, $panel));
$required = array_flip(NavMenu::required($panel));
$more = $cfg['more'] ?? true;
$sectionTitles = ['hotel' => __('Menu'), 'reseller' => __('Reseller'), 'platform' => __('Platform'), 'chain' => __('Chain')];
$groupTitles = ['Manage' => __('Manage'), 'Commercial' => __('Commercial'), 'Plans & subscriptions' => __('Plans & subscriptions'), 'Devices & content' => __('Devices & content'), 'Insight' => __('Insight'), 'System' => __('System'), 'Tools' => __('Tools')];
$grouped = !empty(Panel::GROUPS[$panel]);

$pageTitle = $scope === 'business' ? __('Menu settings') : __('My menu');
$activeNav = $scope === 'business' ? 'settings' : '';
$extraScripts = ['js/menu_settings.js'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-list-ol"></i> <?= e($pageTitle) ?></h1>
    <p class="lead-sm"><?= e($scope === 'business'
        ? __('Choose the order of the menu and hide items your business does not need. This applies to all users; each user can still change their own menu.')
        : __('Choose the order of your menu and hide items you do not need. Only your own menu changes.')) ?></p></div>
  <?php if ($scope === 'business'): ?>
  <a class="btn btn-light border" href="<?= e(admin_url('settings.php')) ?>"><i class="bi bi-gear"></i> <?= e(__('Settings')) ?></a>
  <?php else: ?>
  <a class="btn btn-light border" href="<?= e(admin_url('profile.php')) ?>"><i class="bi bi-person-gear"></i> <?= e(__('My profile')) ?></a>
  <?php endif; ?>
</div>
<?php if ($canBusiness && $personalAllowed): ?>
<ul class="nav nav-pills mb-3" data-menu-scopes>
  <li class="nav-item"><a class="nav-link<?= $scope === 'business' ? ' active' : '' ?>" href="<?= e(admin_url('menu_settings.php', ['scope' => 'business'])) ?>"><i class="bi bi-building"></i> <?= e(__('Business menu (all users)')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $scope === 'me' ? ' active' : '' ?>" href="<?= e(admin_url('menu_settings.php', ['scope' => 'me'])) ?>"><i class="bi bi-person"></i> <?= e(__('My menu')) ?></a></li>
</ul>
<?php endif; ?>

<?php if ($scope === 'me' && !$personalAllowed): ?>
<div class="alert alert-info" data-menu-personal-off><i class="bi bi-info-circle"></i>
  <?= e(__('Your personal menu applies to your own console. Inside a customer you see the customer\'s menu.')) ?>
  <?php if ($canBusiness): ?><a class="alert-link" href="<?= e(admin_url('menu_settings.php', ['scope' => 'business'])) ?>"><?= e(__('Change the customer\'s menu')) ?></a><?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3" style="max-width:1100px">
  <div class="col-lg-7">
    <form method="post" class="card" id="menuForm" data-menu-form data-scope="<?= e($scope) ?>">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-arrow-down-up"></i> <?= e(__('Menu items')) ?></span>
        <?php if ($scope === 'me'): ?>
          <span class="badge <?= $personal !== null ? 'text-bg-primary' : 'text-bg-light border' ?> ms-auto" data-menu-source="<?= $personal !== null ? 'user' : ($business !== null ? 'business' : 'default') ?>">
            <?= e($personal !== null ? __('Your own menu') : ($business !== null ? __('Using your business\'s menu') : __('Default menu'))) ?></span>
        <?php else: ?>
          <span class="badge <?= $business !== null ? 'text-bg-primary' : 'text-bg-light border' ?> ms-auto" data-menu-source="<?= $business !== null ? 'business' : 'default' ?>">
            <?= e($business !== null ? __('Custom menu') : __('Default menu')) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="scope" value="<?= e($scope) ?>">
        <p class="small text-muted"><i class="bi bi-hand-index"></i> <?= e(__('Drag an item by its handle, or use the arrow buttons. Switch an item off to hide it from the menu.')) ?></p>
        <?php if (!$allowed): ?>
          <p class="text-muted"><?= e(__('No menu items.')) ?></p>
        <?php endif; ?>
        <?php foreach ($sections as $sec => $items): ?>
          <?php if (count($sections) > 1): ?><div class="hc-menu-group fw-semibold"><?= e($sectionTitles[$sec] ?? ucfirst($sec)) ?></div><?php endif; ?>
          <?php foreach (Panel::navGroups(NavMenu::order($items, $cfg['order'] ?? [])) as $grp => $grpItems): ?>
            <?php if ($grp !== ''): ?><div class="hc-menu-group"><?= e($groupTitles[$grp] ?? $grp) ?></div><?php endif; ?>
            <ol class="hc-menu-list" data-menu-list>
              <?php foreach ($grpItems as [$key, $href, $perm, $icon, $label]): $isHidden = isset($hiddenKeys[$key]); $isReq = isset($required[$key]); ?>
              <li class="hc-menu-item<?= $isHidden ? ' is-hidden' : '' ?>" draggable="true" data-key="<?= e($key) ?>"<?= $isReq ? ' data-required="1"' : '' ?>>
                <span class="hc-menu-handle" aria-hidden="true" title="<?= e(__('Drag to move')) ?>"><i class="bi bi-grip-vertical"></i></span>
                <input type="hidden" name="order[]" value="<?= e($key) ?>">
                <i class="bi <?= e($icon) ?> hc-menu-icon" aria-hidden="true"></i>
                <span class="hc-menu-label" data-menu-label><?= e($label) ?></span>
                <span class="hc-menu-actions">
                  <button type="button" class="btn btn-sm btn-light border" data-move="up" aria-label="<?= e(__('Move :x up', ['x' => $label])) ?>" title="<?= e(__('Move up')) ?>"><i class="bi bi-arrow-up"></i></button>
                  <button type="button" class="btn btn-sm btn-light border" data-move="down" aria-label="<?= e(__('Move :x down', ['x' => $label])) ?>" title="<?= e(__('Move down')) ?>"><i class="bi bi-arrow-down"></i></button>
                  <?php if ($isReq): ?>
                    <input type="hidden" name="show[]" value="<?= e($key) ?>">
                    <span class="badge text-bg-light border ms-1" title="<?= e(__('Always shown')) ?>" data-menu-locked><i class="bi bi-lock"></i> <span class="d-none d-sm-inline"><?= e(__('Always shown')) ?></span></span>
                  <?php else: ?>
                    <span class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" name="show[]" value="<?= e($key) ?>" id="show_<?= e($key) ?>"<?= $isHidden ? '' : ' checked' ?> data-menu-show>
                      <label class="form-check-label small" for="show_<?= e($key) ?>"><span class="visually-hidden"><?= e($label) ?>: </span><?= e(__('Show')) ?></label>
                    </span>
                  <?php endif; ?>
                </span>
              </li>
              <?php endforeach; ?>
            </ol>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="more" value="1" id="menuMore"<?= $more ? ' checked' : '' ?>>
          <label class="form-check-label" for="menuMore"><?= e(__('Show hidden items in a "Hidden items" group at the bottom of the menu')) ?></label>
        </div>
        <div class="visually-hidden" aria-live="polite" data-menu-live></div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-primary" data-menu-save><i class="bi bi-check-lg"></i> <?= e(__('Save menu')) ?></button>
        <button class="btn btn-light border" form="menuReset" data-confirm="<?= e(__('Reset the menu to the default order and show all items?')) ?>" data-confirm-safe="1" data-menu-reset>
          <i class="bi bi-arrow-counterclockwise"></i> <?= e($scope === 'me' && $panel === 'customer' ? __('Use my business\'s default') : __('Reset to default')) ?></button>
      </div>
    </form>
    <form method="post" id="menuReset" class="d-none"><?= Csrf::field() ?><input type="hidden" name="op" value="reset"><input type="hidden" name="scope" value="<?= e($scope) ?>"></form>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle"></i> <?= e(__('Good to know')) ?></div>
      <div class="card-body small">
        <ul class="mb-0 ps-3">
          <li class="mb-2"><?= e(__('Hiding only removes an item from the menu. Permissions do not change: the page still opens by its link, and you can show it again here at any time.')) ?></li>
          <li class="mb-2"><?= e(__('Items with a lock (such as Dashboard and Settings) are always shown, so you can always come back here.')) ?></li>
          <?php if ($grouped): ?><li class="mb-2"><?= e(__('Items move within their heading; the headings keep their order.')) ?></li><?php endif; ?>
          <li class="mb-2"><?= e(__('New modules added by an update appear at their usual place in the menu.')) ?></li>
          <?php if ($scope === 'business'): ?>
          <li><?= e(__('Each user can also arrange their own menu: My profile → My menu.')) ?></li>
          <?php elseif ($panel === 'customer'): ?>
          <li><?= e(__('Your own menu replaces your business\'s menu for you only. Use "Use my business\'s default" to go back.')) ?></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<script type="application/json" id="menuI18n"><?= json_embed(['moved' => __(':x moved to position :n.'), 'shown' => __(':x is shown.'), 'hidden' => __(':x is hidden.')]) ?></script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
