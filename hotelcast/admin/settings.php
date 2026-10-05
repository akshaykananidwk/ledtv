<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('settings.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('settings.php'));
}
Csrf::check();

$tabs = [
    'general' => ['bi-building', __('General')],
    'display' => ['bi-display', __('TV & Display')],
    'devices' => ['bi-tv', __('Devices')],
    'media' => ['bi-images', __('Media')],
    'notify' => ['bi-bell', __('Notifications')],
    'backup' => ['bi-archive', __('Backup & Restore')],
    'maintenance' => ['bi-tools', __('Maintenance')],
];
const CLOCK_FORMATS = ['hh:mm a', 'hh:mm:ss a', 'HH:mm', 'HH:mm:ss', 'EEE hh:mm a', 'dd/MM hh:mm a'];

function int_in_range(string $key, int $min, int $max, int $default): int
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v) || !preg_match('/^-?\d{1,9}$/', trim($v))) {
        return $default;
    }
    return max($min, min($max, (int) $v));
}

function post_flag(string $key): string
{
    return !empty($_POST[$key]) ? '1' : '0';
}

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $tab = isset($tabs[$_POST['tab'] ?? '']) ? (string) $_POST['tab'] : 'general';
    $errors = [];
    $tvAffecting = false;
    try {
        switch ($op) {
            case 'save_general':
                $name = req_str('hotel_name', $_POST, 200);
                if ($name === '' || mb_strlen($name) > 120) {
                    $errors[] = __('Hotel name is required (max 120 characters).');
                }
                $tz = (string) ($_POST['timezone'] ?? '');
                if (!in_array($tz, timezone_identifiers_list(), true)) {
                    $errors[] = __('Choose a valid time zone.');
                }
                $lang = isset(I18n::LANGUAGES[$_POST['default_language'] ?? '']) ? (string) $_POST['default_language'] : 'en';
                if (!$errors) {
                    Settings::setMany(['hotel_name' => $name, 'timezone' => $tz, 'default_language' => $lang]);
                    if (!empty($_POST['remove_logo'])) {
                        $old = (string) Settings::get('hotel_logo', '');
                        Settings::set('hotel_logo', '');
                        if ($old !== '') {
                            Uploader::delete($old);
                        }
                    }
                    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $up = Uploader::handle($_FILES['logo'], 'logo');
                        $old = (string) Settings::get('hotel_logo', '');
                        Settings::set('hotel_logo', $up['path']);
                        if ($old !== '' && $old !== $up['path']) {
                            Uploader::delete($old);
                        }
                        if (!empty($up['thumb'])) {
                            Uploader::delete($up['thumb']);
                        }
                    }
                    $tvAffecting = true;
                }
                break;

            case 'save_display':
                [$cid, $pid] = parse_source($_POST['default_source'] ?? '');
                $fmt = in_array($_POST['overlay_clock_format'] ?? '', CLOCK_FORMATS, true) ? (string) $_POST['overlay_clock_format'] : 'hh:mm a';
                $lat = trim((string) ($_POST['weather_lat'] ?? ''));
                $lon = trim((string) ($_POST['weather_lon'] ?? ''));
                if ($lat !== '' && (!is_numeric($lat) || abs((float) $lat) > 90)) {
                    $errors[] = __('Latitude must be between -90 and 90.');
                }
                if ($lon !== '' && (!is_numeric($lon) || abs((float) $lon) > 180)) {
                    $errors[] = __('Longitude must be between -180 and 180.');
                }
                if (!$errors) {
                    Settings::setMany([
                        'poll_interval' => int_in_range('poll_interval', 3, 60, 8),
                        'heartbeat_interval' => int_in_range('heartbeat_interval', 15, 600, 60),
                        'offline_after' => int_in_range('offline_after', 30, 3600, 90),
                        'long_poll_enabled' => post_flag('long_poll_enabled'),
                        'default_content_id' => $cid ? (string) $cid : '',
                        'default_playlist_id' => $pid ? (string) $pid : '',
                        'overlay_clock' => post_flag('overlay_clock'),
                        'overlay_clock_format' => $fmt,
                        'overlay_logo' => post_flag('overlay_logo'),
                        'overlay_weather' => post_flag('overlay_weather'),
                        'weather_city' => req_str('weather_city', $_POST, 80),
                        'weather_lat' => $lat,
                        'weather_lon' => $lon,
                        'ticker_text' => req_str('ticker_text', $_POST, 1000),
                        'ticker_bg_color' => clean_color($_POST['ticker_bg_color'] ?? null, '#000000'),
                        'ticker_text_color' => clean_color($_POST['ticker_text_color'] ?? null, '#FFD700'),
                        'ticker_speed' => int_in_range('ticker_speed', 1, 10, 5),
                    ]);
                    Cache::clear('weather');
                    $tvAffecting = true;
                }
                break;

            case 'save_devices':
                $pin = req_str('tv_settings_pin', $_POST, 10);
                if (!preg_match('/^\d{4}$/', $pin)) {
                    $errors[] = __('The PIN must be exactly 4 digits.');
                } else {
                    Settings::setMany(['tv_settings_pin' => $pin, 'auto_create_rooms' => post_flag('auto_create_rooms')]);
                }
                break;

            case 'regen_key':
                Settings::set('registration_key', strtoupper(random_token(8)));
                ActivityLog::add('registration_key_regen', 'settings', null, 'Registration key regenerated');
                flash('success', __('New registration key created. TVs that are already set up keep working; new TVs need the new key.'));
                redirect(admin_url('settings.php', ['tab' => 'devices']));

            case 'save_media':
                $cdn = trim((string) ($_POST['cdn_base_url'] ?? ''));
                if ($cdn !== '' && !ContentManager::validUrl($cdn, ['http', 'https'])) {
                    $errors[] = __('CDN base URL must start with http:// or https://');
                }
                if (!$errors) {
                    Settings::setMany([
                        'cdn_base_url' => rtrim($cdn, '/'),
                        'max_upload_mb' => int_in_range('max_upload_mb', 1, 4096, 200),
                        'image_max_width' => int_in_range('image_max_width', 640, 7680, 1920),
                    ]);
                    $tvAffecting = true;
                }
                break;

            case 'save_notify':
            case 'test_notify':
                $emails = array_filter(array_map('trim', explode(',', (string) ($_POST['notify_email'] ?? ''))));
                foreach ($emails as $em) {
                    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = __('Invalid email address: :e', ['e' => $em]);
                    }
                }
                $from = trim((string) ($_POST['notify_from_email'] ?? ''));
                if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = __('Invalid sender email address.');
                }
                $wa = trim((string) ($_POST['notify_whatsapp_url'] ?? ''));
                if ($wa !== '' && !ContentManager::validUrl(str_replace('{message}', 'x', $wa), ['http', 'https'])) {
                    $errors[] = __('The WhatsApp URL must start with https://');
                }
                if (!$errors) {
                    Settings::setMany([
                        'notify_offline' => post_flag('notify_offline'),
                        'notify_offline_minutes' => int_in_range('notify_offline_minutes', 1, 1440, 5),
                        'notify_email' => implode(', ', $emails),
                        'notify_from_email' => $from,
                        'notify_whatsapp_url' => $wa,
                    ]);
                    if ($op === 'test_notify') {
                        if (!$emails && $wa === '') {
                            flash('warning', __('Enter an email address or WhatsApp URL first.'));
                        } else {
                            $res = Notifier::send('HotelCast: ' . __('Test notification'), sprintf('[%s] %s (%s)', Settings::get('hotel_name', 'HotelCast'), __('This is a test message from HotelCast.'), date('d M H:i')));
                            $parts = [];
                            foreach ($res as $ch => $ok) {
                                $parts[] = ($ch === 'email' ? __('Email') : 'WhatsApp') . ': ' . ($ok ? __('sent') : __('failed'));
                            }
                            flash(in_array(false, $res, true) ? 'warning' : 'success', __('Test notification') . ' — ' . implode(', ', $parts));
                        }
                        redirect(admin_url('settings.php', ['tab' => 'notify']));
                    }
                }
                break;

            case 'save_maintenance':
                Settings::set('log_retention_days', (string) int_in_range('log_retention_days', 7, 3650, 90));
                break;

            case 'clear_cache':
                Cache::clear();
                Settings::bumpContentVersion();
                ActivityLog::add('cache_clear', 'settings', null, 'Server cache cleared');
                flash('success', __('Server cache cleared.'));
                redirect(admin_url('settings.php', ['tab' => 'maintenance']));

            case 'run_maintenance':
                $r = Scheduler::tick(true);
                ActivityLog::add('maintenance_run', 'settings', null, json_out($r));
                flash('success', __('Maintenance finished.') . (isset($r['error']) ? ' ' . $r['error'] : ''));
                redirect(admin_url('settings.php', ['tab' => 'maintenance']));

            default:
                flash('warning', __('Unknown action.'));
                redirect(admin_url('settings.php'));
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if ($errors) {
        flash_errors($errors);
    } else {
        if ($tvAffecting) {
            Settings::bumpContentVersion();
        }
        ActivityLog::add('settings_update', 'settings', null, 'Saved ' . $tab . ' settings');
        flash('success', __('Settings saved.'));
    }
    redirect(admin_url('settings.php', ['tab' => $tab]));
}

$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'general';
$S = Settings::all();
$checked = fn (string $k) => (string) ($S[$k] ?? '0') === '1' ? ' checked' : '';
$switch = function (string $key, string $label, string $help = '') use ($checked): string {
    return '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="s_' . e($key) . '" name="' . e($key) . '" value="1"' . $checked($key) . '>'
        . '<label class="form-check-label" for="s_' . e($key) . '">' . e($label) . '</label>' . ($help !== '' ? '<div class="form-text mt-0">' . e($help) . '</div>' : '') . '</div>';
};
$pageTitle = __('Settings');
$activeNav = 'settings';
require __DIR__ . '/partials/header.php';
$formStart = fn (string $op, bool $multipart = false) => '<form method="post"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>' . Csrf::field()
    . '<input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="tab" value="' . e($tab) . '">';
$saveBtn = '<div class="sticky-actions"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> ' . e(__('Save settings')) . '</button></div>';
?>
<div class="page-head"><h1><?= e(__('Settings')) ?></h1></div>
<ul class="nav nav-tabs nav-tabs-scroll mb-3">
  <?php foreach ($tabs as $k => [$icon, $label]): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('settings.php', ['tab' => $k])) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
  <?php if (Auth::can('update.manage')): ?><li class="nav-item"><a class="nav-link" href="<?= e(admin_url('update.php')) ?>"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Auto-Update')) ?></a></li><?php endif; ?>
</ul>

<?php if ($tab === 'general'): $logo = media_url((string) $S['hotel_logo']); ?>
  <?= $formStart('save_general', true) ?>
  <div class="card" style="max-width:760px"><div class="card-body row g-3">
    <div class="col-12">
      <label class="form-label" for="hn"><?= e(__('Hotel name')) ?> *</label>
      <input class="form-control form-control-lg" id="hn" name="hotel_name" value="<?= e($S['hotel_name']) ?>" required maxlength="120">
    </div>
    <div class="col-12">
      <label class="form-label" for="logo"><?= e(__('Hotel logo')) ?></label>
      <div class="d-flex gap-3 align-items-center flex-wrap">
        <img id="logoPrev" src="<?= e($logo ?? '') ?>" alt="" style="max-height:72px;max-width:200px;background:#eee;border-radius:.5rem;padding:4px"<?= $logo ? '' : ' hidden' ?>>
        <div class="flex-grow-1">
          <input class="form-control" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" data-preview="#logoPrev">
          <div class="form-text"><?= e(__('PNG with transparent background works best. Shown on TVs and on this panel.')) ?></div>
          <?php if ($logo): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="rmlogo" name="remove_logo" value="1"><label class="form-check-label" for="rmlogo"><?= e(__('Remove logo')) ?></label></div><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-7">
      <label class="form-label" for="tz"><?= e(__('Time zone')) ?></label>
      <select class="form-select" id="tz" name="timezone">
        <?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= e($tz) ?>"<?= $tz === $S['timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
      </select>
      <div class="form-text"><?= e(__('Used for schedules and the TV clock. India = Asia/Kolkata.')) ?></div>
    </div>
    <div class="col-md-5">
      <label class="form-label" for="dl"><?= e(__('Default panel language')) ?></label>
      <select class="form-select" id="dl" name="default_language">
        <?php foreach (I18n::LANGUAGES as $c => $n): ?><option value="<?= e($c) ?>"<?= $c === $S['default_language'] ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div></div>
  <?= $saveBtn ?></form>

<?php elseif ($tab === 'display'): ?>
  <?= $formStart('save_display') ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-header"><?= e(__('Default content')) ?></div><div class="card-body">
        <label class="form-label" for="dsrc"><?= e(__('What TVs show when nothing else is assigned')) ?></label>
        <?= source_select('default_source', source_value($S['default_content_id'], $S['default_playlist_id']), __('— Welcome screen (hotel logo) —'), ['id' => 'dsrc']) ?>
      </div></div>
      <div class="card mb-3"><div class="card-header"><?= e(__('Connection')) ?></div><div class="card-body row g-3">
        <div class="col-sm-4"><label class="form-label" for="pi"><?= e(__('Check for changes every')) ?></label>
          <div class="input-group"><input type="number" class="form-control" id="pi" name="poll_interval" min="3" max="60" value="<?= (int) $S['poll_interval'] ?>"><span class="input-group-text">s</span></div>
          <div class="form-text"><?= e(__('3–60 seconds. Lower = faster updates, more server load.')) ?></div></div>
        <div class="col-sm-4"><label class="form-label" for="hb"><?= e(__('Heartbeat every')) ?></label>
          <div class="input-group"><input type="number" class="form-control" id="hb" name="heartbeat_interval" min="15" max="600" value="<?= (int) $S['heartbeat_interval'] ?>"><span class="input-group-text">s</span></div></div>
        <div class="col-sm-4"><label class="form-label" for="oa"><?= e(__('Offline after')) ?></label>
          <div class="input-group"><input type="number" class="form-control" id="oa" name="offline_after" min="30" max="3600" value="<?= (int) $S['offline_after'] ?>"><span class="input-group-text">s</span></div>
          <div class="form-text"><?= e(__('No contact for this long = TV shown as offline.')) ?></div></div>
        <div class="col-12"><?= $switch('long_poll_enabled', __('Enable long polling (instant updates)'), __('Only if your hosting allows long-running requests. Leave off on cheap shared hosting.')) ?></div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-header"><?= e(__('Screen overlays')) ?></div><div class="card-body row g-3">
        <div class="col-sm-6"><?= $switch('overlay_clock', __('Show clock')) ?></div>
        <div class="col-sm-6">
          <select class="form-select form-select-sm" name="overlay_clock_format" aria-label="<?= e(__('Clock format')) ?>">
            <?php foreach (CLOCK_FORMATS as $f): ?><option value="<?= e($f) ?>"<?= $f === $S['overlay_clock_format'] ? ' selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><?= $switch('overlay_logo', __('Show hotel logo')) ?></div>
        <div class="col-12"><?= $switch('overlay_weather', __('Show weather'), __('Free weather data from Open-Meteo, updated every 30 minutes.')) ?></div>
        <div class="col-sm-4"><label class="form-label" for="wc"><?= e(__('City name')) ?></label><input class="form-control" id="wc" name="weather_city" value="<?= e($S['weather_city']) ?>" maxlength="80"></div>
        <div class="col-6 col-sm-4"><label class="form-label" for="wlat"><?= e(__('Latitude')) ?></label><input class="form-control" id="wlat" name="weather_lat" value="<?= e($S['weather_lat']) ?>" inputmode="decimal"></div>
        <div class="col-6 col-sm-4"><label class="form-label" for="wlon"><?= e(__('Longitude')) ?></label><input class="form-control" id="wlon" name="weather_lon" value="<?= e($S['weather_lon']) ?>" inputmode="decimal"></div>
      </div></div>
      <div class="card mb-3"><div class="card-header"><?= e(__('Scrolling ticker')) ?></div><div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="tt"><?= e(__('Ticker text (empty = no ticker)')) ?></label>
          <textarea class="form-control" id="tt" name="ticker_text" rows="2" maxlength="1000" placeholder="<?= e(__('e.g. Mangla Aarti at 6:00 AM | Breakfast 7–10 AM')) ?>"><?= e($S['ticker_text']) ?></textarea></div>
        <div class="col-4"><label class="form-label" for="tbg"><?= e(__('Background')) ?></label><input type="color" class="form-control form-control-color w-100" id="tbg" name="ticker_bg_color" value="<?= e(clean_color($S['ticker_bg_color'], '#000000')) ?>"></div>
        <div class="col-4"><label class="form-label" for="tfg"><?= e(__('Text')) ?></label><input type="color" class="form-control form-control-color w-100" id="tfg" name="ticker_text_color" value="<?= e(clean_color($S['ticker_text_color'], '#FFD700')) ?>"></div>
        <div class="col-4"><label class="form-label" for="tsp"><?= e(__('Speed')) ?></label><input type="range" class="form-range" id="tsp" name="ticker_speed" min="1" max="10" value="<?= (int) $S['ticker_speed'] ?>"></div>
      </div></div>
    </div>
  </div>
  <?= $saveBtn ?></form>

<?php elseif ($tab === 'devices'): $key = (string) $S['registration_key']; ?>
  <div class="row g-3" style="max-width:1000px">
    <div class="col-md-6">
      <div class="card h-100"><div class="card-header"><?= e(__('Registration key')) ?></div><div class="card-body">
        <p class="small text-muted"><?= e(__('Each TV needs this key once, when the app is first set up.')) ?></p>
        <div class="hint-box mb-3">
          <div class="small text-muted"><?= e(__('Server address')) ?></div>
          <div class="d-flex gap-2 align-items-center mb-2"><code><?= e(base_url()) ?></code><button type="button" class="btn btn-xs btn-light border" data-copy="<?= e(base_url()) ?>"><i class="bi bi-clipboard"></i></button></div>
          <div class="small text-muted"><?= e(__('Registration key')) ?></div>
          <?php if ($key !== ''): ?>
            <div class="d-flex gap-2 align-items-center"><code class="fs-5"><?= e($key) ?></code><button type="button" class="btn btn-xs btn-light border" data-copy="<?= e($key) ?>"><i class="bi bi-clipboard"></i></button></div>
          <?php else: ?>
            <div class="text-danger"><?= e(__('Not set — TVs cannot register. Create one below.')) ?></div>
          <?php endif; ?>
        </div>
        <form method="post" data-confirm="<?= e(__('Create a new registration key? New TVs will need the new key (TVs already set up keep working).')) ?>" data-confirm-safe="1">
          <?= Csrf::field() ?><input type="hidden" name="op" value="regen_key"><input type="hidden" name="tab" value="devices">
          <button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> <?= e($key !== '' ? __('Regenerate key') : __('Create key')) ?></button>
        </form>
      </div></div>
    </div>
    <div class="col-md-6">
      <?= $formStart('save_devices') ?>
      <div class="card h-100"><div class="card-header"><?= e(__('TV options')) ?></div><div class="card-body">
        <?= $switch('auto_create_rooms', __('Create rooms automatically'), __('When a TV registers with a room number that does not exist yet, the room is created for you.')) ?>
        <label class="form-label mt-2" for="pin"><?= e(__('TV settings PIN (hotel-wide)')) ?></label>
        <input class="form-control" id="pin" name="tv_settings_pin" value="<?= e($S['tv_settings_pin']) ?>" inputmode="numeric" pattern="\d{4}" maxlength="4" required style="max-width:10rem">
        <div class="form-text"><?= e(__('4 digits needed to open the settings screen on a TV. Rooms can override it.')) ?></div>
        <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button>
      </div></div>
      </form>
    </div>
  </div>

<?php elseif ($tab === 'media'): ?>
  <?= $formStart('save_media') ?>
  <div class="card" style="max-width:760px"><div class="card-body row g-3">
    <div class="col-12">
      <label class="form-label" for="cdn"><?= e(__('CDN base URL (optional)')) ?></label>
      <input class="form-control" id="cdn" name="cdn_base_url" value="<?= e($S['cdn_base_url']) ?>" placeholder="https://cdn.example.com" maxlength="500">
      <div class="form-text"><?= e(__('Leave empty to serve images and videos from this server. If set, files are loaded from <CDN>/uploads/…')) ?></div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="mu"><?= e(__('Maximum video size')) ?></label>
      <div class="input-group"><input type="number" class="form-control" id="mu" name="max_upload_mb" min="1" max="4096" value="<?= (int) $S['max_upload_mb'] ?>"><span class="input-group-text">MB</span></div>
      <div class="form-text"><?= e(__('Server upload limit: :s.', ['s' => human_bytes(upload_limit())])) ?> (upload_max_filesize=<?= e((string) ini_get('upload_max_filesize')) ?>, post_max_size=<?= e((string) ini_get('post_max_size')) ?>)</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="iw"><?= e(__('Resize images wider than')) ?></label>
      <div class="input-group"><input type="number" class="form-control" id="iw" name="image_max_width" min="640" max="7680" value="<?= (int) $S['image_max_width'] ?>"><span class="input-group-text">px</span></div>
      <div class="form-text"><?= e(__('1920 is right for Full-HD TVs, 3840 for 4K TVs.')) ?></div>
    </div>
    <div class="col-12 small text-muted">
      <?= e(__('Video compression (ffmpeg)')) ?>: <?= Uploader::ffmpeg() ? '<span class="text-success">' . e(__('available')) . '</span>' : '<span>' . e(__('not available — videos are stored as uploaded')) . '</span>' ?>
    </div>
  </div></div>
  <?= $saveBtn ?></form>

<?php elseif ($tab === 'notify'): ?>
  <?= $formStart('save_notify') ?>
  <div class="card" style="max-width:760px"><div class="card-body row g-3">
    <div class="col-12"><?= $switch('notify_offline', __('Tell me when a TV goes offline')) ?></div>
    <div class="col-sm-6">
      <label class="form-label" for="nom"><?= e(__('Only after it is offline for')) ?></label>
      <div class="input-group"><input type="number" class="form-control" id="nom" name="notify_offline_minutes" min="1" max="1440" value="<?= (int) $S['notify_offline_minutes'] ?>"><span class="input-group-text"><?= e(__('minutes')) ?></span></div>
    </div>
    <div class="col-12">
      <label class="form-label" for="ne"><?= e(__('Send email to')) ?></label>
      <input class="form-control" id="ne" name="notify_email" value="<?= e($S['notify_email']) ?>" placeholder="owner@example.com, manager@example.com" maxlength="1000">
      <div class="form-text"><?= e(__('Separate several addresses with commas. Uses your hosting\'s PHP mail().')) ?></div>
    </div>
    <div class="col-12">
      <label class="form-label" for="nf"><?= e(__('Sender email (optional)')) ?></label>
      <input class="form-control" type="email" id="nf" name="notify_from_email" value="<?= e($S['notify_from_email']) ?>" placeholder="no-reply@yourhotel.com" maxlength="190">
    </div>
    <div class="col-12">
      <label class="form-label" for="nw"><?= e(__('WhatsApp URL (optional)')) ?></label>
      <input class="form-control mono" id="nw" name="notify_whatsapp_url" value="<?= e($S['notify_whatsapp_url']) ?>" maxlength="1000" placeholder="https://api.callmebot.com/whatsapp.php?phone=91XXXXXXXXXX&text={message}&apikey=XXXXXX">
      <div class="form-text"><?= e(__('Free option: CallMeBot. Send "I allow callmebot to send me messages" to +34 644 51 95 23 on WhatsApp, you get an API key back. Then paste:')) ?>
        <code>https://api.callmebot.com/whatsapp.php?phone=91XXXXXXXXXX&amp;text={message}&amp;apikey=XXXXXX</code>. <?= e(__('{message} is replaced with the alert text.')) ?></div>
    </div>
  </div></div>
  <div class="sticky-actions d-flex gap-2 flex-wrap">
    <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button>
    <button class="btn btn-outline-primary btn-lg" name="op" value="test_notify"><i class="bi bi-send"></i> <?= e(__('Save & send test notification')) ?></button>
  </div>
  </form>

<?php elseif ($tab === 'backup'): ?>
  <div class="card" style="max-width:760px"><div class="card-body">
    <h2 class="h5"><i class="bi bi-archive"></i> <?= e(__('Backup & Restore')) ?></h2>
    <p class="text-muted"><?= e(__('Backups of the database and settings are made automatically before every update. You can also create, download and restore backups on the Auto-Update page.')) ?></p>
    <?php if (Auth::can('update.manage')): ?>
      <a class="btn btn-primary" href="<?= e(admin_url('update.php') . '#backups') ?>"><i class="bi bi-archive"></i> <?= e(__('Open backups')) ?></a>
    <?php endif; ?>
  </div></div>

<?php elseif ($tab === 'maintenance'): ?>
  <div class="row g-3" style="max-width:1000px">
    <div class="col-md-6">
      <?= $formStart('save_maintenance') ?>
      <div class="card h-100"><div class="card-header"><?= e(__('Log retention')) ?></div><div class="card-body">
        <label class="form-label" for="lr"><?= e(__('Keep history for')) ?></label>
        <div class="input-group" style="max-width:14rem"><input type="number" class="form-control" id="lr" name="log_retention_days" min="7" max="3650" value="<?= (int) $S['log_retention_days'] ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div>
        <div class="form-text"><?= e(__('Older TV status, play history and activity records are deleted automatically.')) ?></div>
        <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button>
      </div></div>
      </form>
    </div>
    <div class="col-md-6">
      <div class="card h-100"><div class="card-header"><?= e(__('Tools')) ?></div><div class="card-body d-flex flex-column gap-2 align-items-start">
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="clear_cache"><input type="hidden" name="tab" value="maintenance">
          <button class="btn btn-outline-primary"><i class="bi bi-trash3"></i> <?= e(__('Clear server cache')) ?></button></form>
        <div class="form-text mt-0"><?= e(__('Safe. Forces every TV\'s content to be rebuilt.')) ?></div>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="run_maintenance"><input type="hidden" name="tab" value="maintenance">
          <button class="btn btn-outline-primary"><i class="bi bi-gear-wide-connected"></i> <?= e(__('Run maintenance now')) ?></button></form>
        <div class="form-text mt-0"><?= e(__('Processes schedules, marks offline TVs and deletes old history.')) ?></div>
      </div></div>
    </div>
    <div class="col-12">
      <div class="card"><div class="card-header"><?= e(__('System information')) ?></div>
        <table class="table table-sm table-hc mb-0">
          <?php
          $last = Settings::int('last_tick', 0);
          $sys = [
              'HotelCast' => Version::current()['version'] . (Version::current()['commit'] ? ' (' . substr((string) Version::current()['commit'], 0, 7) . ')' : ''),
              'PHP' => PHP_VERSION,
              'MySQL' => (string) DB::value('SELECT VERSION()'),
              __('Server time') => date('Y-m-d H:i:s T'),
              __('Last maintenance run') => $last ? time_ago(date('Y-m-d H:i:s', $last)) : __('never'),
              'upload_max_filesize / post_max_size' => ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'),
              'GD' => function_exists('imagecreatefromstring') ? __('available') : __('missing'),
              'ffmpeg' => Uploader::ffmpeg() ?: __('missing'),
          ];
          foreach ($sys as $k => $v): ?>
            <tr><th class="ps-3 fw-normal text-muted" style="width:40%"><?= e($k) ?></th><td><?= e($v) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
