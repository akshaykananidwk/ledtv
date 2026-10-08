<?php
/**
 * Device schedules (2.4, docs/modules/device_schedules.md):
 *  - tab "schedules": timed TV actions — volume / mute (#38), input / back to the player (#39), nightly
 *    restart spread over 0–10 minutes (#43), bell / chime (#49), spoken announcement; bell timetable quick
 *    setup; "Announce now" (staff and up, only their TVs when limited).
 *  - tab "sounds": built-in chimes + the hotel's own mp3 / wav / ogg (≤ 5 MB).
 *  - tab "presence": motion sensors (POST /api/presence, token shown once) (#47).
 * Schedules, sounds and sensors: manager+ (device_schedules.manage). Announce now: staff+ (announce.send).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('announce.send');
$canManage = Auth::can('device_schedules.manage');
$self = admin_url('device_schedules.php');

if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect($self . '?tab=sounds');
}
Csrf::check();

$tabs = $canManage ? ['schedules' => __('Schedules'), 'sounds' => __('Sounds'), 'presence' => __('Presence sensors')] : ['schedules' => __('Announce')];
$tab = (string) ($_GET['tab'] ?? 'schedules');
$tab = isset($tabs[$tab]) ? $tab : 'schedules';
$formErrors = [];
$old = null;          // schedule form values after a validation error
$sensorErrors = [];
$sensorOld = null;

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    if ($op !== 'announce') {
        require_can('device_schedules.manage');
    }
    $back = $self;
    $render = false; // validation error: show the form again (422) instead of redirecting
    try {
        switch ($op) {
            case 'announce':
                [$bid, $count] = DeviceSchedules::announce($_POST, Auth::id());
                flash($count ? 'success' : 'warning', $count
                    ? __('Announcement sent to :n TV(s).', ['n' => $count])
                    : __('No TV is registered on the selected screens.'));
                $back .= '#announce';
                break;

            case 'save':
                $id = req_int('id', $_POST);
                $existing = $id ? DeviceSchedules::find($id) : null; // another hotel's id → 404
                if ($id && !$existing) {
                    flash('warning', __('Schedule not found.'));
                    break;
                }
                if ($existing) {
                    Access::requireBroadcast($existing);
                }
                [$data, $formErrors] = DeviceSchedules::validate($_POST);
                if ($formErrors) {
                    http_response_code(422);
                    $old = $_POST;
                    $render = true;
                    break;
                }
                $newId = DeviceSchedules::save($existing ? $id : null, $data, Auth::id());
                ActivityLog::add($existing ? 'device_schedule_update' : 'device_schedule_create', 'device_schedule', $newId, mb_substr($data['title'] . ' · ' . $data['action'] . ' · ' . substr((string) $data['run_time'], 0, 5), 0, 190));
                flash('success', __('Schedule ":t" saved.', ['t' => $data['title']]));
                break;

            case 'bulk_bells':
                [$ids, $errors] = DeviceSchedules::bulkBells($_POST, Auth::id());
                if ($errors) {
                    flash_errors($errors);
                    $back .= '#bells';
                } else {
                    ActivityLog::add('device_schedule_bells', 'device_schedule', $ids[0] ?? null, count($ids) . ' bells');
                    flash('success', __(':n bell times saved.', ['n' => count($ids)]));
                }
                break;

            case 'toggle':
            case 'delete':
                $id = req_int('id', $_POST);
                $s = DeviceSchedules::find($id);
                if (!$s) {
                    flash('warning', __('Schedule not found.'));
                    break;
                }
                Access::requireBroadcast($s);
                if ($op === 'delete') {
                    DeviceSchedules::delete($id);
                    ActivityLog::add('device_schedule_delete', 'device_schedule', $id, mb_substr((string) $s['title'], 0, 190));
                    flash('success', __('Schedule deleted.'));
                } else {
                    $on = !(int) $s['is_active'];
                    DeviceSchedules::setActive($id, $on);
                    ActivityLog::add($on ? 'device_schedule_resume' : 'device_schedule_pause', 'device_schedule', $id, mb_substr((string) $s['title'], 0, 190));
                    flash('success', $on ? __('Schedule resumed.') : __('Schedule paused.'));
                }
                break;

            case 'sound_upload':
                $back .= '?tab=sounds';
                try {
                    $sid = Sounds::upload($_FILES['file'] ?? [], req_str('name', $_POST, 120), Auth::id());
                    ActivityLog::add('sound_upload', 'sound', $sid, req_str('name', $_POST, 120));
                    flash('success', __('Sound uploaded.'));
                } catch (RuntimeException $e) {
                    http_response_code(422);
                    flash('danger', $e->getMessage());
                }
                break;

            case 'sound_delete':
                $back .= '?tab=sounds';
                $sid = req_int('id', $_POST);
                $snd = Sounds::find($sid);
                if (!$snd) {
                    flash('warning', __('Sound not found.'));
                } elseif (!Sounds::delete($sid)) {
                    flash('warning', __('This sound is used by a bell schedule. Change or delete the schedule first.'));
                } else {
                    ActivityLog::add('sound_delete', 'sound', $sid, (string) $snd['name']);
                    flash('success', __('Sound deleted.'));
                }
                break;

            case 'sensor_save':
                $back .= '?tab=presence';
                $id = req_int('id', $_POST);
                $existing = $id ? Presence::find($id) : null;
                if ($id && !$existing) {
                    flash('warning', __('Sensor not found.'));
                    break;
                }
                if ($existing) {
                    Access::requireBroadcast($existing);
                }
                [$data, $sensorErrors] = Presence::validate($_POST);
                if ($sensorErrors) {
                    http_response_code(422);
                    $sensorOld = $_POST;
                    $tab = 'presence';
                    $render = true;
                    break;
                }
                $sid = Presence::save($existing ? $id : null, $data, Auth::id());
                ActivityLog::add($existing ? 'presence_sensor_update' : 'presence_sensor_create', 'presence_sensor', $sid, $data['name']);
                if (!$existing) {
                    $_SESSION['presence_new_token'] = ['id' => $sid, 'token' => Presence::newToken($sid)];
                    flash('success', __('Sensor saved. Copy its token now: it is shown only once.'));
                    $back .= '&edit=' . $sid . '#token';
                } else {
                    flash('success', __('Sensor ":n" saved.', ['n' => $data['name']]));
                }
                break;

            case 'sensor_token':
            case 'sensor_revoke':
            case 'sensor_delete':
                $back .= '?tab=presence';
                $id = req_int('id', $_POST);
                $sensor = Presence::find($id);
                if (!$sensor) {
                    flash('warning', __('Sensor not found.'));
                    break;
                }
                Access::requireBroadcast($sensor);
                if ($op === 'sensor_token') {
                    $_SESSION['presence_new_token'] = ['id' => $id, 'token' => Presence::newToken($id)];
                    ActivityLog::add('presence_sensor_token', 'presence_sensor', $id, (string) $sensor['name']);
                    flash('success', __('New token created. Copy it now: it is shown only once.'));
                    $back .= '&edit=' . $id . '#token';
                } elseif ($op === 'sensor_revoke') {
                    Presence::revokeToken($id);
                    ActivityLog::add('presence_sensor_revoke', 'presence_sensor', $id, (string) $sensor['name']);
                    flash('success', __('The token of ":n" no longer works.', ['n' => $sensor['name']]));
                } else {
                    Presence::delete($id);
                    ActivityLog::add('presence_sensor_delete', 'presence_sensor', $id, (string) $sensor['name']);
                    flash('success', __('Sensor deleted.'));
                }
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    if (!$render) {
        redirect($back);
    }
}

// ---------------------------------------------------------------- view data
$limited = Access::restricted();
$days = day_names();
$sounds = Sounds::all();
$actions = array_map('__', DeviceSchedules::ACTIONS);
$inputs = DeviceControlsExtension::inputs();
$edit = null;
if ($canManage && $tab === 'schedules' && $old === null && req_int('edit', $_GET)) {
    $edit = DeviceSchedules::find(req_int('edit', $_GET));
    if ($edit) {
        Access::requireBroadcast($edit);
    }
}
// Form values: after a validation error the posted values, else the edited row, else defaults.
if ($old !== null) {
    $f = $old;
    $fTarget = ['type' => (string) ($old['target_type'] ?? 'all'), 'ids' => (array) ($old['room_ids'] ?? $old['group_ids'] ?? $old['floors'] ?? [])];
} elseif ($edit) {
    $o = DeviceSchedules::opts($edit);
    $f = ['id' => $edit['id'], 'title' => $edit['title'], 'action' => $edit['action'], 'run_time' => substr((string) $edit['run_time'], 0, 5),
        'repeat_mode' => $edit['repeat_mode'], 'run_date' => $edit['run_date'], 'days' => explode(',', (string) $edit['days'])]
        + ['level' => $o['level'] ?? 30, 'input' => $o['input'] ?? 'live_tv', 'min_uptime_h' => $o['min_uptime_h'] ?? 0, 'stagger_min' => $o['stagger_min'] ?? 10,
           'sound' => $o['sound'] ?? '', 'sound_volume' => $edit['action'] === 'bell' ? ($o['volume'] ?? '') : '', 'sound_repeat' => $edit['action'] === 'bell' ? ($o['repeat'] ?? 1) : 1,
           'text' => $o['text'] ?? '', 'lang' => $o['lang'] ?? 'auto', 'rate' => $o['rate'] ?? 1, 'repeat' => $edit['action'] === 'speak' ? ($o['repeat'] ?? 1) : 1,
           'volume' => $edit['action'] === 'speak' ? ($o['volume'] ?? '') : '', 'chime_before' => !empty($o['chime_before'])];
    $fTarget = ['type' => (string) $edit['target_type'], 'ids' => json_decode((string) $edit['target_ids'], true) ?: []];
} else {
    $f = ['id' => 0, 'title' => '', 'action' => 'volume', 'run_time' => '22:00', 'repeat_mode' => 'daily', 'run_date' => date('Y-m-d'), 'days' => [1, 2, 3, 4, 5],
        'level' => 10, 'input' => 'live_tv', 'min_uptime_h' => 0, 'stagger_min' => 10, 'sound' => 'b:school_bell', 'sound_volume' => '', 'sound_repeat' => 1,
        'text' => '', 'lang' => 'auto', 'rate' => 1, 'repeat' => 1, 'volume' => '', 'chime_before' => true];
    $fTarget = ['type' => $limited ? 'rooms' : 'all', 'ids' => []];
}
$fv = static fn (string $k, mixed $d = ''): string => is_scalar($f[$k] ?? null) ? (string) $f[$k] : (string) $d;
$fDays = array_map('intval', (array) ($f['days'] ?? []));

$schedules = $canManage ? DeviceSchedules::list() : [];
$sensors = $canManage ? Presence::list() : [];
$newToken = null;
$editSensor = null;
if ($canManage && $tab === 'presence') {
    if (req_int('edit', $_GET)) {
        $editSensor = Presence::find(req_int('edit', $_GET));
        if ($editSensor) {
            Access::requireBroadcast($editSensor);
        }
    }
    if ($editSensor && ($_SESSION['presence_new_token']['id'] ?? 0) === (int) $editSensor['id']) {
        $newToken = (string) $_SESSION['presence_new_token']['token'];
    }
    unset($_SESSION['presence_new_token']);
}
$so = $sensorOld ?? ($editSensor ? ['id' => $editSensor['id'], 'name' => $editSensor['name'], 'idle_minutes' => $editSensor['idle_minutes'], 'override_schedule' => $editSensor['override_schedule'], 'is_active' => $editSensor['is_active']]
    : ['id' => 0, 'name' => '', 'idle_minutes' => 10, 'override_schedule' => 0, 'is_active' => 1]);
$soTarget = $sensorOld !== null
    ? ['type' => (string) ($sensorOld['target_type'] ?? 'rooms'), 'ids' => (array) ($sensorOld['room_ids'] ?? $sensorOld['group_ids'] ?? [])]
    : ($editSensor ? ['type' => (string) $editSensor['target_type'], 'ids' => json_decode((string) $editSensor['target_ids'], true) ?: []] : ['type' => 'rooms', 'ids' => []]);

$pageTitle = __('Device schedules');
$activeNav = 'device_schedules';
require __DIR__ . '/partials/header.php';

/** <option>s of the sound select. */
function ds_sound_options(array $sounds, string $selected): string
{
    $h = '';
    foreach ($sounds as $ref => $snd) {
        $h .= '<option value="' . e($ref) . '"' . ($ref === $selected ? ' selected' : '') . '>' . e($snd['name']) . ($snd['builtin'] ? ' (' . e(__('built-in')) . ')' : '') . '</option>';
    }
    return $h;
}
?>
<div class="page-head">
  <div><h1><i class="bi bi-alarm"></i> <?= e(__('Device schedules')) ?></h1>
    <p class="lead-sm"><?= e(__('Volume, input, nightly restart, bells and spoken announcements at set times — plus motion sensors that switch TVs on and off.')) ?></p></div>
</div>

<?php if (count($tabs) > 1): ?>
<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e($self . '?tab=' . $k) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($tab === 'schedules'): ?>
<div class="row g-3">
  <div class="col-xl-5">
    <form method="post" class="card mb-3" id="announce">
      <?= Csrf::field() ?><input type="hidden" name="op" value="announce">
      <div class="card-header"><i class="bi bi-megaphone"></i> <?= e(__('Announce now')) ?></div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label" for="an_text"><?= e(__('Text to speak')) ?></label>
          <textarea class="form-control" id="an_text" name="text" rows="2" maxlength="500" required placeholder="<?= e(__('e.g. Aarti will start in 10 minutes')) ?>"></textarea></div>
        <div class="row g-2 mb-2">
          <div class="col-6 col-md-3"><label class="form-label small" for="an_lang"><?= e(__('Language')) ?></label>
            <select class="form-select form-select-sm" id="an_lang" name="lang"><?php foreach (DeviceSchedules::SPEAK_LANGS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(DeviceSchedules::langLabel($k)) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label class="form-label small" for="an_rate"><?= e(__('Speed')) ?></label><input class="form-control form-control-sm" type="number" id="an_rate" name="rate" min="0.5" max="2" step="0.1" value="1"></div>
          <div class="col-6 col-md-3"><label class="form-label small" for="an_rep"><?= e(__('Repeat')) ?></label><input class="form-control form-control-sm" type="number" id="an_rep" name="repeat" min="1" max="3" value="1"></div>
          <div class="col-6 col-md-3"><label class="form-label small" for="an_vol"><?= e(__('Volume (%)')) ?></label><input class="form-control form-control-sm" type="number" id="an_vol" name="volume" min="0" max="100" placeholder="<?= e(__('unchanged')) ?>"></div>
        </div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="chime_before" value="1" id="an_chime" checked><label class="form-check-label" for="an_chime"><?= e(__('Play a chime before speaking')) ?></label></div>
        <div class="mb-3"><label class="form-label"><?= e(__('On which TVs')) ?></label><?= target_picker('an', ['type' => $limited ? 'rooms' : 'all']) ?></div>
        <button class="btn btn-primary" data-confirm="<?= e(__('Speak this announcement on the selected TVs now?')) ?>" data-confirm-safe="1"><i class="bi bi-megaphone"></i> <?= e(__('Announce now')) ?></button>
        <div class="form-text"><?= e(__('The TV reads the text aloud with its own voice (text-to-speech). Gujarati and Hindi need the language installed on the TV.')) ?></div>
      </div>
    </form>

    <?php if ($canManage): ?>
    <form method="post" class="card mb-3" id="scheduleForm" novalidate>
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $fv('id', 0) ?>">
      <div class="card-header"><i class="bi bi-plus-circle"></i> <?= e((int) $fv('id', 0) ? __('Edit schedule') : __('New schedule')) ?></div>
      <div class="card-body">
        <?php if ($formErrors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="ds_action"><?= e(__('Action')) ?></label>
            <select class="form-select" id="ds_action" name="action"><?php foreach ($actions as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('action') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label" for="ds_title"><?= e(__('Name (optional)')) ?></label><input class="form-control" id="ds_title" name="title" maxlength="190" value="<?= e($fv('title')) ?>" placeholder="<?= e(__('e.g. Night volume')) ?>"></div>
        </div>

        <div class="border rounded p-2 mb-3" data-ds-panel="volume">
          <label class="form-label" for="ds_level"><?= e(__('Volume (%)')) ?></label>
          <input type="number" class="form-control" id="ds_level" name="level" min="0" max="100" value="<?= e($fv('level', 10)) ?>">
          <div class="form-text"><?= e(__('Example: 22:00 volume 10, 07:00 volume 40.')) ?></div>
        </div>
        <div class="border rounded p-2 mb-3" data-ds-panel="input">
          <label class="form-label" for="ds_input"><?= e(__('Input')) ?></label>
          <select class="form-select" id="ds_input" name="input">
            <option value="live_tv"<?= $fv('input') === 'live_tv' ? ' selected' : '' ?>><?= e(__('Live TV')) ?></option>
            <?php foreach (DeviceControlsExtension::INPUTS as $hdmi): ?><option value="<?= e($hdmi) ?>"<?= $fv('input') === $hdmi ? ' selected' : '' ?>>HDMI <?= e(substr($hdmi, 4)) ?><?= ($inputs[$hdmi] ?? '') !== '' ? ' — ' . e($inputs[$hdmi]) : '' ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?= e(__('Use the action "Back to the player app" to return to your content later.')) ?></div>
        </div>
        <div class="border rounded p-2 mb-3" data-ds-panel="player"><div class="small text-muted"><?= e(__('The TV app restarts and shows your content again (e.g. after an HDMI or Live TV schedule).')) ?></div></div>
        <div class="border rounded p-2 mb-3" data-ds-panel="reboot">
          <div class="row g-2">
            <div class="col-6"><label class="form-label" for="ds_stagger"><?= e(__('Spread over (minutes)')) ?></label><input type="number" class="form-control" id="ds_stagger" name="stagger_min" min="0" max="10" value="<?= e($fv('stagger_min', 10)) ?>"></div>
            <div class="col-6"><label class="form-label" for="ds_uptime"><?= e(__('Only when up for (hours)')) ?></label><input type="number" class="form-control" id="ds_uptime" name="min_uptime_h" min="0" max="720" value="<?= e($fv('min_uptime_h', 0)) ?>"></div>
          </div>
          <div class="form-text"><?= e(__('Each TV restarts at a random moment within the spread, so not all TVs restart at once. Offline TVs are skipped; 0 hours = always restart.')) ?></div>
        </div>
        <div class="border rounded p-2 mb-3" data-ds-panel="bell">
          <div class="row g-2">
            <div class="col-12"><label class="form-label" for="ds_sound"><?= e(__('Sound')) ?></label><select class="form-select" id="ds_sound" name="sound"><?= ds_sound_options($sounds, $fv('sound')) ?></select></div>
            <div class="col-6"><label class="form-label" for="ds_svol"><?= e(__('Volume (%)')) ?></label><input type="number" class="form-control" id="ds_svol" name="sound_volume" min="0" max="100" value="<?= e($fv('sound_volume')) ?>" placeholder="<?= e(__('unchanged')) ?>"></div>
            <div class="col-6"><label class="form-label" for="ds_srep"><?= e(__('Repeat')) ?></label><input type="number" class="form-control" id="ds_srep" name="sound_repeat" min="1" max="10" value="<?= e($fv('sound_repeat', 1)) ?>"></div>
          </div>
        </div>
        <div class="border rounded p-2 mb-3" data-ds-panel="speak">
          <label class="form-label" for="ds_text"><?= e(__('Text to speak')) ?></label>
          <textarea class="form-control mb-2" id="ds_text" name="text" rows="2" maxlength="500" placeholder="<?= e(__('e.g. Aarti will start in 10 minutes')) ?>"><?= e($fv('text')) ?></textarea>
          <div class="row g-2">
            <div class="col-6 col-md-3"><label class="form-label small" for="ds_lang"><?= e(__('Language')) ?></label><select class="form-select form-select-sm" id="ds_lang" name="lang"><?php foreach (DeviceSchedules::SPEAK_LANGS as $k => $l): ?><option value="<?= e($k) ?>"<?= $fv('lang') === $k ? ' selected' : '' ?>><?= e(DeviceSchedules::langLabel($k)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="ds_rate"><?= e(__('Speed')) ?></label><input class="form-control form-control-sm" type="number" id="ds_rate" name="rate" min="0.5" max="2" step="0.1" value="<?= e($fv('rate', 1)) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="ds_rep"><?= e(__('Repeat')) ?></label><input class="form-control form-control-sm" type="number" id="ds_rep" name="repeat" min="1" max="3" value="<?= e($fv('repeat', 1)) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="ds_vol"><?= e(__('Volume (%)')) ?></label><input class="form-control form-control-sm" type="number" id="ds_vol" name="volume" min="0" max="100" value="<?= e($fv('volume')) ?>" placeholder="<?= e(__('unchanged')) ?>"></div>
          </div>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="chime_before" value="1" id="ds_chime"<?= !empty($f['chime_before']) ? ' checked' : '' ?>><label class="form-check-label" for="ds_chime"><?= e(__('Play a chime before speaking')) ?></label></div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-6 col-md-4"><label class="form-label" for="ds_time"><?= e(__('Time')) ?></label><input type="time" class="form-control" id="ds_time" name="run_time" value="<?= e($fv('run_time', '22:00')) ?>" required></div>
          <div class="col-6 col-md-8"><label class="form-label d-block"><?= e(__('Repeat')) ?></label>
            <div class="btn-group flex-wrap" role="group">
              <?php foreach (DeviceSchedules::REPEATS as $k => $l): ?>
                <input type="radio" class="btn-check" name="repeat_mode" value="<?= e($k) ?>" id="ds_rm_<?= e($k) ?>"<?= $fv('repeat_mode', 'daily') === $k ? ' checked' : '' ?> autocomplete="off"><label class="btn btn-outline-primary btn-sm" for="ds_rm_<?= e($k) ?>"><?= e(__($l)) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="mb-2" data-ds-when="once"><label class="form-label" for="ds_date"><?= e(__('Date')) ?></label><input type="date" class="form-control" id="ds_date" name="run_date" value="<?= e($fv('run_date', date('Y-m-d'))) ?>" min="<?= e(date('Y-m-d')) ?>" style="max-width:220px"></div>
        <div class="mb-2" data-ds-when="weekly">
          <?php foreach ($days as $n => $label): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $n ?>" id="ds_d<?= $n ?>"<?= in_array($n, $fDays, true) ? ' checked' : '' ?>><label class="form-check-label" for="ds_d<?= $n ?>"><?= e($label) ?></label></div>
          <?php endforeach; ?>
        </div>
        <div class="form-text mb-3"><?= e(__('Times are in your time zone (:tz).', ['tz' => date_default_timezone_get()])) ?></div>
        <div class="mb-3"><label class="form-label"><?= e(__('On which TVs')) ?></label><?= target_picker('ds', $fTarget) ?></div>
        <button class="btn btn-primary"><i class="bi bi-save"></i> <?= e(__('Save schedule')) ?></button>
        <?php if ((int) $fv('id', 0)): ?><a class="btn btn-light border" href="<?= e($self) ?>"><?= e(__('Cancel')) ?></a><?php endif; ?>
      </div>
    </form>

    <form method="post" class="card mb-3" id="bells">
      <?= Csrf::field() ?><input type="hidden" name="op" value="bulk_bells">
      <div class="card-header"><i class="bi bi-bell"></i> <?= e(__('Bell timetable (quick setup)')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('For schools, factories and temples: enter all bell times at once. One bell schedule is created per time.')) ?></p>
        <div class="mb-2"><label class="form-label" for="bt_times"><?= e(__('Times')) ?></label><textarea class="form-control" id="bt_times" name="times" rows="2" placeholder="08:00, 08:45, 09:30, 10:15, 11:00"></textarea></div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label" for="bt_title"><?= e(__('Name')) ?></label><input class="form-control" id="bt_title" name="title" maxlength="170" placeholder="<?= e(__('Bell')) ?>"></div>
          <div class="col-md-6"><label class="form-label" for="bt_sound"><?= e(__('Sound')) ?></label><select class="form-select" id="bt_sound" name="sound"><?= ds_sound_options($sounds, 'b:school_bell') ?></select></div>
          <div class="col-6"><label class="form-label" for="bt_vol"><?= e(__('Volume (%)')) ?></label><input type="number" class="form-control" id="bt_vol" name="sound_volume" min="0" max="100" placeholder="<?= e(__('unchanged')) ?>"></div>
          <div class="col-6"><label class="form-label" for="bt_rep"><?= e(__('Repeat')) ?></label><input type="number" class="form-control" id="bt_rep" name="sound_repeat" min="1" max="10" value="1"></div>
        </div>
        <div class="mb-2">
          <?php foreach ($days as $n => $label): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $n ?>" id="bt_d<?= $n ?>"<?= $n <= 5 ? ' checked' : '' ?>><label class="form-check-label" for="bt_d<?= $n ?>"><?= e($label) ?></label></div>
          <?php endforeach; ?>
        </div>
        <div class="mb-3"><label class="form-label"><?= e(__('On which TVs')) ?></label><?= target_picker('bt', ['type' => $limited ? 'rooms' : 'all']) ?></div>
        <button class="btn btn-primary"><i class="bi bi-bell"></i> <?= e(__('Create bell times')) ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($canManage): ?>
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-list-check"></i> <?= e(__('Scheduled actions')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th><?= e(__('When')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Last run')) ?></th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php if (!$schedules): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No schedules yet. Example: volume 10 at 22:00 and volume 40 at 07:00 every day.')) ?></td></tr><?php endif; ?>
          <?php foreach ($schedules as $s):
              $paused = !(int) $s['is_active'];
              $next = DeviceSchedules::nextRun($s);
              $done = $s['repeat_mode'] === 'once' && $s['last_fired_for'];
          ?>
            <tr class="<?= $paused || $done ? 'text-muted' : '' ?>">
              <td class="text-nowrap"><strong><?= e(DeviceSchedules::whenLabel($s)) ?></strong>
                <div class="small"><?php if ($paused): ?><span class="badge text-bg-secondary"><?= e(__('Paused')) ?></span><?php elseif ($done): ?><span class="badge text-bg-light border"><?= e(__('Done')) ?></span><?php elseif ($next): ?><?= e(__('Next')) ?>: <?= e(date('d M H:i', $next)) ?><?php endif; ?></div></td>
              <td><i class="bi <?= e(DeviceSchedules::ICONS[$s['action']] ?? 'bi-gear') ?>"></i> <strong><?= e($s['title']) ?></strong>
                <div class="small text-muted"><?= e($actions[$s['action']] ?? $s['action']) ?> · <?= e(DeviceSchedules::describe($s)) ?></div></td>
              <td class="small"><?= e(Broadcaster::describeTarget((string) $s['target_type'], (string) $s['target_ids'])) ?></td>
              <td class="small"><?php if ($s['last_fired_at']): ?><?= e(date('d M H:i', (int) strtotime((string) $s['last_fired_at']))) ?><div class="text-muted"><?= e((string) $s['last_result']) ?></div><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= e($self . '?edit=' . (int) $s['id']) ?>#scheduleForm" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= e($paused ? __('Resume') : __('Pause')) ?>"><i class="bi <?= $paused ? 'bi-play' : 'bi-pause' ?>"></i></button></form>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this schedule?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const act = document.getElementById('ds_action');
  if (!act) return;
  const panels = () => document.querySelectorAll('[data-ds-panel]').forEach((p) => {
    const a = act.value === 'mute' || act.value === 'unmute' ? '' : act.value;
    p.hidden = p.dataset.dsPanel !== a;
  });
  const when = () => {
    const m = document.querySelector('input[name="repeat_mode"]:checked');
    document.querySelectorAll('[data-ds-when]').forEach((w) => { w.hidden = !m || w.dataset.dsWhen !== m.value; });
  };
  act.addEventListener('change', panels);
  document.querySelectorAll('input[name="repeat_mode"]').forEach((r) => r.addEventListener('change', when));
  panels(); when();
});
</script>

<?php elseif ($tab === 'sounds'): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card">
      <?= Csrf::field() ?><input type="hidden" name="op" value="sound_upload">
      <div class="card-header"><i class="bi bi-upload"></i> <?= e(__('Upload a sound')) ?></div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label" for="snd_name"><?= e(__('Name')) ?></label><input class="form-control" id="snd_name" name="name" maxlength="120" placeholder="<?= e(__('e.g. Lunch bell')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="snd_file"><?= e(__('File (MP3, WAV or OGG, max 5 MB)')) ?></label><input class="form-control" type="file" id="snd_file" name="file" accept=".mp3,.wav,.ogg,audio/mpeg,audio/wav,audio/ogg" required></div>
        <button class="btn btn-primary"><i class="bi bi-upload"></i> <?= e(__('Upload')) ?></button>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-music-note-list"></i> <?= e(__('Sound library')) ?></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($sounds as $ref => $snd): ?>
          <li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
            <div class="flex-grow-1"><strong><?= e($snd['name']) ?></strong>
              <div class="small text-muted"><?= $snd['builtin'] ? e(__('built-in')) : e(human_bytes($snd['size'])) ?></div></div>
            <audio controls preload="none" src="<?= e($snd['url']) ?>" style="max-width:240px;height:34px"></audio>
            <?php if (!$snd['builtin']): ?>
              <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="sound_delete"><input type="hidden" name="id" value="<?= (int) $snd['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this sound?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php else: /* presence */ ?>
<div class="row g-3">
  <div class="col-xl-5">
    <?php if ($newToken !== null): ?>
      <div class="card border-success mb-3" id="token"><div class="card-body">
        <h2 class="h6"><i class="bi bi-key"></i> <?= e(__('Sensor token')) ?></h2>
        <input class="form-control font-monospace mb-2" readonly value="<?= e($newToken) ?>" onclick="this.select()" aria-label="<?= e(__('Sensor token')) ?>">
        <div class="small text-muted mb-2"><?= e(__('Copy it now: it is shown only once. The sensor sends it as "Authorization: Bearer <token>".')) ?></div>
        <pre class="small bg-light p-2 mb-0 text-wrap">curl -X POST <?= e(base_url('api/presence')) ?> -H "Authorization: Bearer <?= e($newToken) ?>" -H "Content-Type: application/json" -d '{"event":"motion"}'</pre>
      </div></div>
    <?php endif; ?>
    <form method="post" class="card mb-3" id="sensorForm" novalidate>
      <?= Csrf::field() ?><input type="hidden" name="op" value="sensor_save"><input type="hidden" name="id" value="<?= (int) $so['id'] ?>">
      <div class="card-header"><i class="bi bi-person-walking"></i> <?= e((int) $so['id'] ? __('Edit sensor') : __('New sensor')) ?></div>
      <div class="card-body">
        <?php if ($sensorErrors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($sensorErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="mb-2"><label class="form-label" for="ps_name"><?= e(__('Name')) ?></label><input class="form-control" id="ps_name" name="name" maxlength="120" value="<?= e((string) ($so['name'] ?? '')) ?>" placeholder="<?= e(__('e.g. Lobby motion sensor')) ?>" required></div>
        <div class="mb-2"><label class="form-label" for="ps_idle"><?= e(__('Switch off after no motion for (minutes)')) ?></label><input type="number" class="form-control" id="ps_idle" name="idle_minutes" min="1" max="1440" value="<?= e((string) ($so['idle_minutes'] ?? 10)) ?>" style="max-width:160px"></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ps_active"<?= !empty($so['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="ps_active"><?= e(__('Presence mode on (motion switches the TVs on, no motion switches them off)')) ?></label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="override_schedule" value="1" id="ps_over"<?= !empty($so['override_schedule']) ? ' checked' : '' ?>><label class="form-check-label" for="ps_over"><?= e(__('Presence overrides schedule (switch on even inside a power-off schedule)')) ?></label></div>
        <div class="mb-3"><label class="form-label"><?= e(__('Screens or groups of this sensor')) ?></label><?= target_picker('ps', $soTarget) ?></div>
        <button class="btn btn-primary"><i class="bi bi-save"></i> <?= e(__('Save sensor')) ?></button>
        <?php if ((int) $so['id']): ?><a class="btn btn-light border" href="<?= e($self . '?tab=presence') ?>"><?= e(__('Cancel')) ?></a><?php endif; ?>
        <div class="form-text"><?= e(__('Emergency messages always win. A screen switched off in TV Power is never switched on by a sensor.')) ?></div>
      </div>
    </form>
  </div>
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-broadcast"></i> <?= e(__('Presence sensors')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th><?= e(__('Name')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Last motion')) ?></th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php if (!$sensors): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No sensors yet. Add one on the left and connect it with its token.')) ?></td></tr><?php endif; ?>
          <?php foreach ($sensors as $ps): ?>
            <tr class="<?= (int) $ps['is_active'] ? '' : 'text-muted' ?>">
              <td><strong><?= e($ps['name']) ?></strong><div class="small text-muted"><?= e(__('Off after :m min', ['m' => (int) $ps['idle_minutes']])) ?><?= (int) $ps['override_schedule'] ? ' · ' . e(__('overrides schedule')) : '' ?>
                · <?= $ps['token_hint'] !== '' ? e(__('token …:h', ['h' => $ps['token_hint']])) : '<span class="text-danger">' . e(__('no token')) . '</span>' ?></div></td>
              <td class="small"><?= e(Broadcaster::describeTarget((string) $ps['target_type'], (string) $ps['target_ids'])) ?></td>
              <td><?php if (!(int) $ps['is_active']): ?><span class="badge text-bg-secondary"><?= e(__('Paused')) ?></span>
                <?php elseif ($ps['state'] === 'on'): ?><span class="badge text-bg-success"><?= e(__('TVs on (people seen)')) ?></span>
                <?php elseif ($ps['state'] === 'off'): ?><span class="badge text-bg-dark"><?= e(__('TVs off (no motion)')) ?></span>
                <?php else: ?><span class="badge text-bg-light border"><?= e(__('Waiting for motion')) ?></span><?php endif; ?></td>
              <td class="small"><?= $ps['last_seen'] ? e(date('d M H:i', (int) strtotime((string) $ps['last_seen']))) : '-' ?><div class="text-muted"><?= e(__(':n events', ['n' => (int) $ps['events']])) ?></div></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= e($self . '?tab=presence&edit=' . (int) $ps['id']) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="sensor_token"><input type="hidden" name="id" value="<?= (int) $ps['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" data-confirm="<?= e(__('Create a new token? The old token stops working.')) ?>" title="<?= e(__('New token')) ?>"><i class="bi bi-key"></i></button></form>
                <?php if ($ps['token_hint'] !== ''): ?>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="sensor_revoke"><input type="hidden" name="id" value="<?= (int) $ps['id'] ?>">
                  <button class="btn btn-sm btn-outline-warning" data-confirm="<?= e(__('Disconnect this sensor? Its token stops working.')) ?>" title="<?= e(__('Revoke token')) ?>"><i class="bi bi-x-octagon"></i></button></form>
                <?php endif; ?>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="sensor_delete"><input type="hidden" name="id" value="<?= (int) $ps['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this sensor?')) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card card-body small">
      <h2 class="h6"><i class="bi bi-plug"></i> <?= e(__('How to connect a sensor')) ?></h2>
      <p class="mb-1"><?= e(__('The sensor (ESP8266 / ESP32 with a PIR sensor, Shelly motion, Home Assistant automation or a phone automation) sends an HTTP POST when it sees people:')) ?></p>
      <pre class="bg-light p-2 text-wrap">POST <?= e(base_url('api/presence')) ?>

Authorization: Bearer prs…
Content-Type: application/json

{"event": "motion"}</pre>
      <p class="mb-0"><?= e(__('Wiring diagrams and sample code are in the module documentation (docs/modules/device_schedules.md).')) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
