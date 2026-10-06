<?php
/**
 * Guest services setup (manager+, permission guests.setup):
 *   tab "settings" — check-in mode, vacant TVs, welcome / checkout-reminder texts (EN/GU/HI), Wi-Fi,
 *                    privacy retention, guest app options, Google review link;
 *   tab "pms"      — PMS API key (generate / rotate / revoke) and webhook field mapping;
 *   tab "menu"     — room-service categories and items (photo, veg / non-veg, price, hours);
 *   tab "requests" — request buttons (water, towels, wake-up call…).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('guests.setup');
if (post_too_large()) {
    flash('danger', __('The upload is too large for this server.'));
    redirect(admin_url('services_setup.php', ['tab' => 'menu']));
}
Csrf::check();
if (!Guests::enabled() && !GuestServices::enabled()) {
    http_response_code(403);
    $GLOBALS['hc_forbidden'] = true;
    require __DIR__ . '/partials/forbidden.php';
    exit;
}
$tabs = [];
if (Guests::enabled() || GuestServices::enabled()) {
    $tabs['settings'] = [__('Settings'), 'bi-sliders'];
}
if (Guests::enabled()) {
    $tabs['pms'] = [__('PMS integration'), 'bi-plug'];
}
if (GuestServices::enabled()) {
    $tabs['menu'] = [__('Room-service menu'), 'bi-egg-fried'];
    $tabs['requests'] = [__('Request buttons'), 'bi-hand-index'];
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'settings';
$langs = I18n::GUEST_LANGUAGES;
$tplKinds = [
    'welcome_title' => [__('Welcome title'), Guests::TPL_WELCOME_TITLE],
    'welcome_message' => [__('Welcome message'), Guests::TPL_WELCOME_MESSAGE],
    'reminder_text' => [__('Checkout reminder'), Guests::TPL_REMINDER],
    'balance_text' => [__('Bill summary line'), Guests::TPL_BALANCE],
];

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $back = ['tab' => req_str('tab', $_POST, 20) ?: $tab];
    try {
        switch ($op) {
            case 'settings':
                $errors = [];
                $set = [];
                $bool = fn (string $k) => !empty($_POST[$k]) ? '1' : '0';
                foreach (['guest_checkin_mode', 'guest_reminder_enabled', 'guest_reminder_balance', 'guest_services_enabled', 'guest_requests_enabled',
                    'guest_feedback_enabled', 'guest_tv_notify', 'guest_notify_external'] as $k) {
                    $set[$k] = $bool($k);
                }
                $vm = req_str('guest_vacant_mode', $_POST, 10);
                $set['guest_vacant_mode'] = in_array($vm, Guests::VACANT_MODES, true) ? $vm : 'normal';
                $set['guest_welcome_duration'] = (string) max(5, min(600, req_int('guest_welcome_duration', $_POST) ?: 20));
                $set['guest_retention_days'] = (string) max(1, min(3650, req_int('guest_retention_days', $_POST) ?: 30));
                foreach (['guest_checkout_time' => '10:00', 'guest_reminder_time' => '07:00'] as $k => $def) {
                    $t = Broadcaster::parseTime(req_str($k, $_POST, 8));
                    if (!$t) {
                        $errors[] = __('Enter times as HH:MM.');
                    }
                    $set[$k] = $t ? substr($t, 0, 5) : $def;
                }
                $set['guest_wifi_ssid'] = req_str('guest_wifi_ssid', $_POST, 32);
                $set['guest_wifi_password'] = req_str('guest_wifi_password', $_POST, 63);
                $phone = req_str('guest_reception_phone', $_POST, 30);
                if ($phone !== '' && !preg_match('/^\+?[0-9 ()\-]{3,20}$/', $phone)) {
                    $errors[] = __('Reception phone is not valid.');
                }
                $set['guest_reception_phone'] = $phone;
                $review = req_str('guest_google_review_url', $_POST, 500);
                if ($review !== '' && !preg_match('#^https://[^\s<>"]+$#i', $review)) {
                    $errors[] = __('The review link must start with https://');
                }
                $set['guest_google_review_url'] = $review;
                foreach (array_keys($tplKinds) as $kind) {
                    foreach (array_keys($langs) as $l) {
                        $set['guest_' . $kind . '_' . $l] = req_str('guest_' . $kind . '_' . $l, $_POST, 400);
                    }
                }
                if ($errors) {
                    flash_errors($errors);
                    break;
                }
                Settings::setMany($set);
                Settings::bumpContentVersion();
                ActivityLog::add('guest_settings', 'settings', null, 'Guest services settings saved');
                flash('success', __('Saved.'));
                break;

            case 'pms_key':
                if (!Guests::enabled()) {
                    break;
                }
                $key = Guests::rotatePmsKey();
                Auth::startSession();
                $_SESSION['hc_new_pms_key'] = $key;
                ActivityLog::add('pms_key_rotate', 'settings', null, 'PMS API key generated (…' . substr($key, -4) . ')');
                flash('success', __('New PMS API key created. Copy it now — it is shown only once. The old key no longer works.'));
                break;

            case 'pms_revoke':
                Guests::revokePmsKey();
                ActivityLog::add('pms_key_revoke', 'settings', null, 'PMS API key revoked');
                flash('success', __('PMS API key revoked.'));
                break;

            case 'pms_mapping':
                $preset = req_str('preset', $_POST, 20);
                if ($preset !== '' && isset(GuestPms::PRESETS[$preset])) {
                    $json = json_encode(GuestPms::PRESETS[$preset], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $json = (string) ($_POST['mapping'] ?? '');
                }
                [$norm, $err] = GuestPms::validateMapping(mb_substr($json, 0, 8000));
                if ($err) {
                    flash('danger', $err);
                    break;
                }
                Settings::set('guest_pms_mapping', $norm);
                ActivityLog::add('pms_mapping', 'settings', null, $preset !== '' ? 'preset ' . $preset : 'custom');
                flash('success', __('Saved.'));
                break;

            case 'cat_save':
                [$id, $errors] = GuestServices::saveCategory($_POST, req_int('id', $_POST) ?: null);
                $errors ? flash_errors($errors) : flash('success', __('Saved.'));
                break;

            case 'cat_delete':
                if (GuestServices::deleteCategory(req_int('id', $_POST))) {
                    flash('success', __('Category deleted.'));
                }
                break;

            case 'item_save':
                $photo = null;
                if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $photo = Uploader::handle($_FILES['photo'], 'image');
                    } catch (RuntimeException $e) {
                        flash('danger', $e->getMessage());
                        break;
                    }
                }
                $itemId = req_int('id', $_POST) ?: null;
                [$id, $errors] = GuestServices::saveItem($_POST, $itemId, $photo);
                if ($errors) {
                    if ($photo) {
                        Uploader::delete($photo['path'], $photo['thumb'] ?? null);
                    }
                    flash_errors($errors);
                    if ($itemId) {
                        $back['edit_item'] = $itemId;
                    }
                } else {
                    if (!empty($_POST['remove_photo']) && !$photo && ($old = Tenant::find('guest_menu_items', (int) $id)) && $old['photo_path']) {
                        Uploader::delete((string) $old['photo_path']);
                        DB::update('guest_menu_items', ['photo_path' => null], 'id = :id', ['id' => $id]);
                    }
                    flash('success', __('Saved.'));
                }
                break;

            case 'item_toggle':
                $item = Tenant::find('guest_menu_items', req_int('id', $_POST));
                if ($item) {
                    DB::update('guest_menu_items', ['is_active' => (int) $item['is_active'] ? 0 : 1], 'id = :id', ['id' => $item['id']]);
                    flash('success', (int) $item['is_active'] ? __('Item hidden from the menu.') : __('Item is available again.'));
                }
                break;

            case 'item_delete':
                if (GuestServices::deleteItem(req_int('id', $_POST))) {
                    flash('success', __('Item deleted.'));
                }
                break;

            case 'type_save':
                [$id, $errors] = GuestServices::saveRequestType($_POST, req_int('id', $_POST) ?: null);
                $errors ? flash_errors($errors) : flash('success', __('Saved.'));
                break;

            case 'type_delete':
                if (Tenant::find('guest_request_types', req_int('id', $_POST))) {
                    DB::delete('guest_request_types', 'id = :id', ['id' => req_int('id', $_POST)]);
                    flash('success', __('Request button deleted.'));
                }
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('services_setup.php', $back));
}

$gset = fn (string $k) => Guests::setting($k);
$newKey = null;
if ($tab === 'pms') {
    Auth::startSession();
    $newKey = $_SESSION['hc_new_pms_key'] ?? null;
    unset($_SESSION['hc_new_pms_key']);
}
$categories = $tab === 'menu' ? GuestServices::categories() : [];
$items = $tab === 'menu' ? GuestServices::items() : [];
$editItem = $tab === 'menu' && req_int('edit_item', $_GET) ? Tenant::find('guest_menu_items', req_int('edit_item', $_GET)) : null;
$editCat = $tab === 'menu' && req_int('edit_cat', $_GET) ? Tenant::find('guest_menu_categories', req_int('edit_cat', $_GET)) : null;
$types = $tab === 'requests' ? GuestServices::requestTypes() : [];
$editType = $tab === 'requests' && req_int('edit_type', $_GET) ? Tenant::find('guest_request_types', req_int('edit_type', $_GET)) : null;
$foodTypes = ['veg' => __('Veg'), 'nonveg' => __('Non-veg'), 'egg' => __('Egg'), 'none' => __('Not food')];

$pageTitle = __('Guest services setup');
$activeNav = 'guest_setup';
$extraStyles = ['css/guests-admin.css'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-gear-wide-connected"></i> <?= e(__('Guest services setup')) ?></h1>
    <p class="lead-sm"><?= e(__('Front desk, TV welcome, checkout reminder, PMS, room-service menu and request buttons.')) ?></p>
  </div>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('services_setup.php', ['tab' => $k])) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'settings'): ?>
<form method="post">
  <?= Csrf::field() ?><input type="hidden" name="op" value="settings"><input type="hidden" name="tab" value="settings">
  <div class="row g-3">
    <?php if (Guests::enabled()): ?>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-door-open"></i> <?= e(__('Check-in mode & vacant rooms')) ?></div>
        <div class="card-body">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="guest_checkin_mode" value="1" id="sCheckin" <?= $gset('guest_checkin_mode') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="sCheckin"><strong><?= e(__('Check-in mode')) ?></strong> — <?= e(__('TVs know whether a room is occupied')) ?></label>
          </div>
          <div class="ms-md-4 mb-3">
            <div class="small text-muted mb-1"><?= e(__('TV of a vacant room')) ?>:</div>
            <?php foreach (['normal' => __('Normal content (no change)'), 'welcome' => __('Idle "Welcome to the hotel" screen'), 'off' => __('Switched off (saves electricity)')] as $v => $label): ?>
              <div class="form-check"><input class="form-check-input" type="radio" name="guest_vacant_mode" value="<?= e($v) ?>" id="sVac<?= e($v) ?>" <?= Guests::vacantMode() === $v ? 'checked' : '' ?>>
                <label class="form-check-label" for="sVac<?= e($v) ?>"><?= e($label) ?></label></div>
            <?php endforeach; ?>
            <div class="form-text"><?= e(__('At check-in the TV wakes up and greets the guest. Emergency messages always reach every TV.')) ?></div>
          </div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label" for="sDur"><?= e(__('Welcome screen duration (seconds)')) ?></label>
              <input class="form-control" type="number" min="5" max="600" name="guest_welcome_duration" id="sDur" value="<?= e($gset('guest_welcome_duration')) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="sCo"><?= e(__('Standard checkout time')) ?></label>
              <input class="form-control" type="time" name="guest_checkout_time" id="sCo" value="<?= e($gset('guest_checkout_time')) ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="sSsid"><?= e(__('Guest Wi-Fi name (SSID)')) ?></label>
              <input class="form-control" name="guest_wifi_ssid" id="sSsid" maxlength="32" value="<?= e($gset('guest_wifi_ssid')) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="sWpw"><?= e(__('Wi-Fi password')) ?></label>
              <input class="form-control" name="guest_wifi_password" id="sWpw" maxlength="63" value="<?= e($gset('guest_wifi_password')) ?>" autocomplete="off">
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-alarm"></i> <?= e(__('Checkout reminder & privacy')) ?></div>
        <div class="card-body">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="guest_reminder_enabled" value="1" id="sRem" <?= $gset('guest_reminder_enabled') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="sRem"><?= e(__('Show a checkout reminder on the TV on checkout day')) ?></label>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label" for="sRemT"><?= e(__('Show from')) ?></label>
              <input class="form-control" type="time" name="guest_reminder_time" id="sRemT" value="<?= e($gset('guest_reminder_time')) ?>" required>
            </div>
            <div class="col-sm-6 d-flex align-items-end">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="guest_reminder_balance" value="1" id="sBal" <?= $gset('guest_reminder_balance') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="sBal"><?= e(__('Include the bill summary (from front desk or PMS)')) ?></label></div>
            </div>
          </div>
          <hr>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label" for="sRet"><?= e(__('Delete guest details after (days)')) ?></label>
              <input class="form-control" type="number" min="1" max="3650" name="guest_retention_days" id="sRet" value="<?= e($gset('guest_retention_days')) ?>">
              <div class="form-text"><?= e(__('Name, phone and notes are removed automatically after checkout (DPDP).')) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (GuestServices::enabled()): ?>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-phone"></i> <?= e(__('Guest app (scan QR on TV)')) ?></div>
        <div class="card-body">
          <?php foreach (['guest_services_enabled' => __('Room-service menu & orders'), 'guest_requests_enabled' => __('Service requests (water, towels, wake-up call…)'), 'guest_feedback_enabled' => __('Feedback (stars + comment)'), 'guest_tv_notify' => __('Tell the guest on the TV when an order / request status changes'), 'guest_notify_external' => __('Also send new orders by email / WhatsApp (hotel notification settings)')] as $k => $label): ?>
            <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="<?= e($k) ?>" value="1" id="s<?= e($k) ?>" <?= $gset($k) === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="s<?= e($k) ?>"><?= e($label) ?></label></div>
          <?php endforeach; ?>
          <div class="row g-2 mt-2">
            <div class="col-sm-6">
              <label class="form-label" for="sRp"><?= e(__('Reception phone (shown to guests)')) ?></label>
              <input class="form-control" type="tel" name="guest_reception_phone" id="sRp" maxlength="30" value="<?= e($gset('guest_reception_phone')) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="sGr"><?= e(__('Google review link')) ?></label>
              <input class="form-control" type="url" name="guest_google_review_url" id="sGr" maxlength="500" placeholder="https://g.page/r/…/review" value="<?= e($gset('guest_google_review_url')) ?>">
              <div class="form-text"><?= e(__('Shown after a rating of 4 or 5 stars.')) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (Guests::enabled()): ?>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-translate"></i> <?= e(__('TV texts per language')) ?></div>
        <div class="card-body">
          <p class="small text-muted"><?= e(__('Leave empty to use the built-in text. Placeholders:')) ?> <code>{salutation} {name} {first_name} {hotel} {room} {time} {date} {balance}</code></p>
          <ul class="nav nav-pills nav-sm mb-2" role="tablist">
            <?php $first = true; foreach ($langs as $l => $ln): ?>
              <li class="nav-item" role="presentation"><button class="nav-link py-1<?= $first ? ' active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tpl_<?= e($l) ?>" type="button" role="tab"><?= e($ln) ?></button></li>
            <?php $first = false; endforeach; ?>
          </ul>
          <div class="tab-content">
            <?php $first = true; foreach ($langs as $l => $ln): ?>
              <div class="tab-pane fade<?= $first ? ' show active' : '' ?>" id="tpl_<?= e($l) ?>" role="tabpanel">
                <?php foreach ($tplKinds as $kind => [$label, $def]): ?>
                  <label class="form-label small mb-0 mt-1" for="t_<?= e($kind . '_' . $l) ?>"><?= e($label) ?></label>
                  <input class="form-control form-control-sm" name="guest_<?= e($kind . '_' . $l) ?>" id="t_<?= e($kind . '_' . $l) ?>" maxlength="400"
                         value="<?= e((string) Settings::get('guest_' . $kind . '_' . $l, '')) ?>" placeholder="<?= e(I18n::translate($def, $l)) ?>">
                <?php endforeach; ?>
              </div>
            <?php $first = false; endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save"></i> <?= e(__('Save settings')) ?></button></div>
</form>

<?php elseif ($tab === 'pms'): ?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key"></i> <?= e(__('PMS API key')) ?></div>
      <div class="card-body">
        <?php if ($newKey): ?>
          <div class="alert alert-warning">
            <div class="small mb-1"><?= e(__('Copy this key now — it is shown only once:')) ?></div>
            <div class="d-flex gap-2 align-items-center"><code class="user-select-all text-break" id="pmsKey"><?= e($newKey) ?></code>
              <button type="button" class="btn btn-sm btn-outline-dark" data-copy="<?= e($newKey) ?>"><i class="bi bi-clipboard"></i></button></div>
          </div>
        <?php endif; ?>
        <p class="mb-2"><?= e(__('Status')) ?>:
          <?php if ($gset('guest_pms_key_hash') !== ''): ?><span class="badge text-bg-success"><?= e(__('Active')) ?></span> <span class="text-muted small">…<?= e($gset('guest_pms_key_hint')) ?></span>
          <?php else: ?><span class="badge text-bg-secondary"><?= e(__('No key')) ?></span><?php endif; ?></p>
        <div class="d-flex gap-2 flex-wrap">
          <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="pms_key"><input type="hidden" name="tab" value="pms">
            <button class="btn btn-primary btn-sm"<?= $gset('guest_pms_key_hash') !== '' ? ' data-confirm="' . e(__('Create a new key? The PMS must be updated with the new key.')) . '"' : '' ?>><i class="bi bi-arrow-repeat"></i> <?= e($gset('guest_pms_key_hash') !== '' ? __('Rotate key') : __('Generate key')) ?></button></form>
          <?php if ($gset('guest_pms_key_hash') !== ''): ?>
          <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="pms_revoke"><input type="hidden" name="tab" value="pms">
            <button class="btn btn-outline-danger btn-sm" data-confirm="<?= e(__('Revoke the PMS key? The PMS can no longer check guests in or out.')) ?>"><i class="bi bi-x-circle"></i> <?= e(__('Revoke')) ?></button></form>
          <?php endif; ?>
        </div>
        <hr>
        <dl class="small mb-0">
          <dt><?= e(__('REST endpoints')) ?></dt>
          <dd><code><?= e(base_url('api/pms/checkin')) ?></code>, <code>…/checkout</code>, <code>…/room-move</code>, <code>…/update</code>, <code>GET …/rooms</code></dd>
          <dt><?= e(__('Header')) ?></dt><dd><code>Authorization: Bearer &lt;key&gt;</code></dd>
          <dt><?= e(__('Webhook URL (for PMS that cannot send headers)')) ?></dt><dd><code class="text-break"><?= e(base_url('api/pms/webhook')) ?>?key=&lt;key&gt;</code></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-diagram-3"></i> <?= e(__('Webhook field mapping')) ?></div>
      <div class="card-body">
        <form method="post" class="mb-3 d-flex gap-2 flex-wrap align-items-end">
          <?= Csrf::field() ?><input type="hidden" name="op" value="pms_mapping"><input type="hidden" name="tab" value="pms">
          <div><label class="form-label small mb-0" for="pPreset"><?= e(__('Start from a preset')) ?></label>
            <select class="form-select form-select-sm" name="preset" id="pPreset">
              <option value="generic"><?= e(__('Generic (standard field names)')) ?></option>
              <option value="ezee">eZee style</option><option value="hotelogix">Hotelogix style</option><option value="stayflexi">StayFlexi style</option>
            </select></div>
          <button class="btn btn-sm btn-outline-primary" data-confirm="<?= e(__('Replace the current mapping with this preset?')) ?>" data-confirm-safe="1"><?= e(__('Load preset')) ?></button>
        </form>
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="pms_mapping"><input type="hidden" name="tab" value="pms">
          <label class="form-label small" for="pMap"><?= e(__('Mapping (JSON: field → path in the PMS payload, e.g. "booking.guest.name")')) ?></label>
          <textarea class="form-control font-monospace small" name="mapping" id="pMap" rows="14" spellcheck="false"><?= e(json_encode(GuestPms::mapping(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
          <div class="form-text"><?= e(__('A list of paths is joined with a space (first + last name). "events" lists the event names that mean check-in, checkout, room move and update.')) ?></div>
          <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save"></i> <?= e(__('Save mapping')) ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'menu'): ?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-folder-plus"></i> <?= e($editCat ? __('Edit category') : __('New category')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="cat_save"><input type="hidden" name="tab" value="menu"><input type="hidden" name="id" value="<?= (int) ($editCat['id'] ?? 0) ?>">
          <?php foreach ($langs as $l => $ln): ?>
            <label class="form-label small mb-0" for="c_<?= e($l) ?>"><?= e(__('Name')) ?> (<?= e($ln) ?>)<?= $l === 'en' ? ' *' : '' ?></label>
            <input class="form-control form-control-sm mb-1" name="name_<?= e($l) ?>" id="c_<?= e($l) ?>" maxlength="100" value="<?= e($editCat['name_' . $l] ?? '') ?>"<?= $l === 'en' ? ' required' : '' ?>>
          <?php endforeach; ?>
          <div class="row g-2 align-items-end">
            <div class="col-6"><label class="form-label small mb-0" for="cSort"><?= e(__('Sort')) ?></label><input class="form-control form-control-sm" type="number" name="sort_order" id="cSort" value="<?= (int) ($editCat['sort_order'] ?? (count($categories) + 1) * 10) ?>"></div>
            <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="cAct" <?= !$editCat || (int) $editCat['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="cAct"><?= e(__('Active')) ?></label></div></div>
          </div>
          <div class="mt-2 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> <?= e(__('Save')) ?></button>
            <?php if ($editCat): ?><a class="btn btn-light border btn-sm" href="<?= e(admin_url('services_setup.php', ['tab' => 'menu'])) ?>"><?= e(__('Cancel')) ?></a><?php endif; ?></div>
        </form>
      </div>
    </div>

    <div class="card" id="itemForm">
      <div class="card-header"><i class="bi bi-plus-square"></i> <?= e($editItem ? __('Edit item') : __('New menu item')) ?></div>
      <div class="card-body">
        <?php if (!$categories): ?><p class="text-muted small mb-0"><?= e(__('Create a category first (e.g. Breakfast, Main course, Beverages).')) ?></p><?php else: ?>
        <form method="post" enctype="multipart/form-data">
          <?= Csrf::field() ?><input type="hidden" name="op" value="item_save"><input type="hidden" name="tab" value="menu"><input type="hidden" name="id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
          <label class="form-label small mb-0" for="iCat"><?= e(__('Category')) ?></label>
          <select class="form-select form-select-sm mb-1" name="category_id" id="iCat" required>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($editItem['category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name_en']) ?></option><?php endforeach; ?>
          </select>
          <?php foreach ($langs as $l => $ln): ?>
            <label class="form-label small mb-0" for="i_<?= e($l) ?>"><?= e(__('Name')) ?> (<?= e($ln) ?>)<?= $l === 'en' ? ' *' : '' ?></label>
            <input class="form-control form-control-sm mb-1" name="name_<?= e($l) ?>" id="i_<?= e($l) ?>" maxlength="120" value="<?= e($editItem['name_' . $l] ?? '') ?>"<?= $l === 'en' ? ' required' : '' ?>>
          <?php endforeach; ?>
          <?php foreach ($langs as $l => $ln): ?>
            <label class="form-label small mb-0" for="d_<?= e($l) ?>"><?= e(__('Description')) ?> (<?= e($ln) ?>)</label>
            <input class="form-control form-control-sm mb-1" name="description_<?= e($l) ?>" id="d_<?= e($l) ?>" maxlength="300" value="<?= e($editItem['description_' . $l] ?? '') ?>">
          <?php endforeach; ?>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small mb-0" for="iPrice"><?= e(__('Price (₹)')) ?></label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="price" id="iPrice" value="<?= e($editItem['price'] ?? '') ?>" required></div>
            <div class="col-6"><label class="form-label small mb-0" for="iFood"><?= e(__('Type')) ?></label>
              <select class="form-select form-select-sm" name="food_type" id="iFood"><?php foreach ($foodTypes as $k => $v): ?><option value="<?= e($k) ?>"<?= ($editItem['food_type'] ?? 'veg') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-0" for="iFrom"><?= e(__('Available from')) ?></label><input class="form-control form-control-sm" type="time" name="available_from" id="iFrom" value="<?= e(substr((string) ($editItem['available_from'] ?? ''), 0, 5)) ?>"></div>
            <div class="col-6"><label class="form-label small mb-0" for="iTo"><?= e(__('Available to')) ?></label><input class="form-control form-control-sm" type="time" name="available_to" id="iTo" value="<?= e(substr((string) ($editItem['available_to'] ?? ''), 0, 5)) ?>"></div>
            <div class="col-6"><label class="form-label small mb-0" for="iSort"><?= e(__('Sort')) ?></label><input class="form-control form-control-sm" type="number" name="sort_order" id="iSort" value="<?= (int) ($editItem['sort_order'] ?? 0) ?>"></div>
            <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="iAct" <?= !$editItem || (int) $editItem['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="iAct"><?= e(__('Available')) ?></label></div></div>
          </div>
          <label class="form-label small mb-0 mt-1" for="iPhoto"><?= e(__('Photo (optional)')) ?></label>
          <input class="form-control form-control-sm" type="file" name="photo" id="iPhoto" accept="image/jpeg,image/png,image/webp" data-preview="#iPrev">
          <img id="iPrev" class="gs-photo mt-1" alt="" <?= empty($editItem['photo_path']) ? 'hidden' : 'src="' . e(media_url((string) $editItem['photo_path'])) . '"' ?>>
          <?php if (!empty($editItem['photo_path'])): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="iRm"><label class="form-check-label small" for="iRm"><?= e(__('Remove photo')) ?></label></div><?php endif; ?>
          <div class="form-text"><?= e(__('Leave the times empty if the item is available all day.')) ?></div>
          <div class="mt-2 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> <?= e(__('Save')) ?></button>
            <?php if ($editItem): ?><a class="btn btn-light border btn-sm" href="<?= e(admin_url('services_setup.php', ['tab' => 'menu'])) ?>"><?= e(__('Cancel')) ?></a><?php endif; ?></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <?php if (!$categories): ?><div class="card"><div class="card-body text-center text-muted py-5"><?= e(__('The menu is empty.')) ?></div></div><?php endif; ?>
    <?php foreach ($categories as $c): $catItems = array_filter($items, fn ($i) => (int) $i['category_id'] === (int) $c['id']); ?>
      <div class="card mb-3<?= (int) $c['is_active'] ? '' : ' opacity-75' ?>">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
          <strong><?= e($c['name_en']) ?></strong>
          <span class="small text-muted"><?= e(trim(($c['name_gu'] ?? '') . ' · ' . ($c['name_hi'] ?? ''), ' ·')) ?></span>
          <?php if (!(int) $c['is_active']): ?><span class="badge text-bg-secondary"><?= e(__('Hidden')) ?></span><?php endif; ?>
          <div class="ms-auto d-flex gap-1">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(admin_url('services_setup.php', ['tab' => 'menu', 'edit_cat' => $c['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="cat_delete"><input type="hidden" name="tab" value="menu"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this category and all its items?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <tbody>
            <?php if (!$catItems): ?><tr><td class="text-muted small p-3"><?= e(__('No items in this category.')) ?></td></tr><?php endif; ?>
            <?php foreach ($catItems as $i): ?>
              <tr class="<?= (int) $i['is_active'] ? '' : 'text-muted' ?>">
                <td style="width:56px"><?php if ($i['photo_path']): ?><img class="gs-thumb" src="<?= e(media_url((string) $i['photo_path'])) ?>" alt="" loading="lazy"><?php endif; ?></td>
                <td><span class="gs-food gs-<?= e($i['food_type']) ?>" title="<?= e($foodTypes[$i['food_type']] ?? '') ?>"></span> <strong><?= e($i['name_en']) ?></strong>
                  <div class="small text-muted"><?= e(trim(($i['name_gu'] ?? '') . ' · ' . ($i['name_hi'] ?? ''), ' ·')) ?><?= GuestServices::hoursLabel($i) !== '' ? ' · ⏰ ' . e(GuestServices::hoursLabel($i)) : '' ?></div></td>
                <td class="text-end text-nowrap"><?= e(money($i['price'], 'INR')) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-secondary" href="<?= e(admin_url('services_setup.php', ['tab' => 'menu', 'edit_item' => $i['id']])) ?>#itemForm" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="item_toggle"><input type="hidden" name="tab" value="menu"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" title="<?= e((int) $i['is_active'] ? __('Mark as not available') : __('Mark as available')) ?>"><i class="bi <?= (int) $i['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button></form>
                  <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="item_delete"><input type="hidden" name="tab" value="menu"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this item?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'requests'): ?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-plus-square"></i> <?= e($editType ? __('Edit request button') : __('New request button')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="type_save"><input type="hidden" name="tab" value="requests"><input type="hidden" name="id" value="<?= (int) ($editType['id'] ?? 0) ?>">
          <?php foreach ($langs as $l => $ln): ?>
            <label class="form-label small mb-0" for="r_<?= e($l) ?>"><?= e(__('Name')) ?> (<?= e($ln) ?>)<?= $l === 'en' ? ' *' : '' ?></label>
            <input class="form-control form-control-sm mb-1" name="name_<?= e($l) ?>" id="r_<?= e($l) ?>" maxlength="100" value="<?= e($editType['name_' . $l] ?? '') ?>"<?= $l === 'en' ? ' required' : '' ?>>
          <?php endforeach; ?>
          <div class="row g-2 align-items-end">
            <div class="col-4"><label class="form-label small mb-0" for="rIcon"><?= e(__('Icon')) ?></label><input class="form-control form-control-sm" name="icon" id="rIcon" maxlength="4" value="<?= e($editType['icon'] ?? '🛎️') ?>"></div>
            <div class="col-4"><label class="form-label small mb-0" for="rSort"><?= e(__('Sort')) ?></label><input class="form-control form-control-sm" type="number" name="sort_order" id="rSort" value="<?= (int) ($editType['sort_order'] ?? (count($types) + 1) * 10) ?>"></div>
            <div class="col-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="rAct" <?= !$editType || (int) $editType['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="rAct"><?= e(__('Active')) ?></label></div></div>
          </div>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="needs_time" value="1" id="rTime" <?= !empty($editType['needs_time']) ? 'checked' : '' ?>><label class="form-check-label small" for="rTime"><?= e(__('Guest picks a time (e.g. wake-up call)')) ?></label></div>
          <div class="mt-2 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> <?= e(__('Save')) ?></button>
            <?php if ($editType): ?><a class="btn btn-light border btn-sm" href="<?= e(admin_url('services_setup.php', ['tab' => 'requests'])) ?>"><?= e(__('Cancel')) ?></a><?php endif; ?></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-xl-8">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th></th><th><?= e(__('Button')) ?></th><th><?= e(__('Time')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($types as $t): ?>
            <tr class="<?= (int) $t['is_active'] ? '' : 'text-muted' ?>">
              <td class="fs-4" style="width:3rem"><?= e($t['icon']) ?></td>
              <td><strong><?= e($t['name_en']) ?></strong><div class="small text-muted"><?= e(trim(($t['name_gu'] ?? '') . ' · ' . ($t['name_hi'] ?? ''), ' ·')) ?></div></td>
              <td><?= (int) $t['needs_time'] ? '<i class="bi bi-clock"></i>' : '' ?></td>
              <td><?= (int) $t['is_active'] ? '<span class="badge text-bg-success">' . e(__('Active')) . '</span>' : '<span class="badge text-bg-secondary">' . e(__('Hidden')) . '</span>' ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(admin_url('services_setup.php', ['tab' => 'requests', 'edit_type' => $t['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="type_delete"><input type="hidden" name="tab" value="requests"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this request button?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
