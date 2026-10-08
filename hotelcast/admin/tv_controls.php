<?php
/**
 * TV controls (#4 #5 #15 #16): volume policy + volume commands, guest menu (Live TV, HDMI inputs,
 * Cast, Wi-Fi), messages on the TV, welcome card, input switching. Manager+ (devices.controls).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('devices.controls');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $anchor = '';
    try {
        switch ($op) {
            case 'save_volume':
                Access::requireUnrestricted('tv volume policy');
                $anchor = '#volume';
                $errors = TvControls::saveVolume($_POST);
                if ($errors) {
                    flash_errors($errors);
                } else {
                    ActivityLog::add('settings_update', 'settings', null, 'TV volume policy');
                    flash('success', __('Saved. TVs apply the new volume rules within a few seconds.'));
                }
                break;
            case 'save_menu':
                Access::requireUnrestricted('tv guest menu');
                $anchor = '#menu';
                $errors = TvControls::saveMenu($_POST);
                if ($errors) {
                    flash_errors($errors);
                } else {
                    ActivityLog::add('settings_update', 'settings', null, 'TV guest menu');
                    flash('success', __('Saved. The guest menu on the TVs is updated within a few seconds.'));
                }
                break;
            case 'command':
                $anchor = '#commands';
                $command = strtoupper(req_str('command', $_POST, 30));
                [$type, $ids] = Broadcaster::parseTarget($_POST);
                [, $count] = TvControls::send($command, $type, $ids, $_POST, Auth::id());
                flash($count ? 'success' : 'warning', $count
                    ? __('Sent to :n TV(s). See the result on the TV details page.', ['n' => $count])
                    : __('No TV is registered on the selected screens.'));
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('tv_controls.php') . $anchor);
}

$s = static fn (string $k, string $d = '') => (string) Settings::get($k, $d);
$limited = Access::restricted();
$inputs = DeviceControlsExtension::inputs();
$wifiFromGuests = is_file(HC_ROOT . '/admin/guests.php');
$pageTitle = __('TV controls');
$activeNav = 'tv_controls';
require __DIR__ . '/partials/header.php';

/** Target picker + submit for a command form. */
function tvc_target(string $uid, string $label, string $confirm): string
{
    return '<div class="mb-3"><label class="form-label">' . e(__('On which TVs')) . '</label>' . target_picker($uid) . '</div>'
        . '<button class="btn btn-primary" data-confirm="' . e($confirm) . '" data-confirm-safe="1"><i class="bi bi-send"></i> ' . e($label) . '</button>';
}
?>
<div class="page-head">
  <div><h1><i class="bi bi-sliders2"></i> <?= e(__('TV controls')) ?></h1>
    <p class="lead-sm"><?= e(__('Volume limits, the guest menu on the TV (OK button) and quick commands.')) ?></p></div>
</div>

<div class="row g-3">
  <?php if (!$limited): // volume rules + guest menu are hotel-wide: not for users limited to some TVs ?>
  <div class="col-xl-6">
    <form method="post" class="card mb-3" id="volume">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save_volume">
      <div class="card-header"><i class="bi bi-volume-up"></i> <?= e(__('Volume rules')) ?></div>
      <div class="card-body row g-3">
        <div class="col-12 form-check form-switch ms-2">
          <input class="form-check-input" type="checkbox" role="switch" id="tv_volume_enabled" name="tv_volume_enabled" value="1"<?= $s('tv_volume_enabled') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="tv_volume_enabled"><?= e(__('Control the TV volume')) ?></label>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="volume_default"><?= e(__('Volume at check-in (%)')) ?></label>
          <input type="number" class="form-control" id="volume_default" name="volume_default" min="0" max="100" value="<?= e($s('volume_default')) ?>" placeholder="<?= e(__('unchanged')) ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="volume_max"><?= e(__('Maximum volume (%)')) ?></label>
          <input type="number" class="form-control" id="volume_max" name="volume_max" min="0" max="100" required value="<?= e($s('volume_max', '100')) ?>">
        </div>
        <div class="col-12 form-check form-switch ms-2">
          <input class="form-check-input" type="checkbox" role="switch" id="volume_night_enabled" name="volume_night_enabled" value="1"<?= $s('volume_night_enabled') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="volume_night_enabled"><?= e(__('Lower limit at night')) ?></label>
        </div>
        <div class="col-12 col-sm-4">
          <label class="form-label" for="volume_night_max"><?= e(__('Night maximum (%)')) ?></label>
          <input type="number" class="form-control" id="volume_night_max" name="volume_night_max" min="0" max="100" required value="<?= e($s('volume_night_max', '25')) ?>">
        </div>
        <div class="col-6 col-sm-4">
          <label class="form-label" for="volume_night_from"><?= e(__('Night from')) ?></label>
          <input type="time" class="form-control" id="volume_night_from" name="volume_night_from" required value="<?= e($s('volume_night_from', '22:00')) ?>">
        </div>
        <div class="col-6 col-sm-4">
          <label class="form-label" for="volume_night_to"><?= e(__('Night until')) ?></label>
          <input type="time" class="form-control" id="volume_night_to" name="volume_night_to" required value="<?= e($s('volume_night_to', '06:00')) ?>">
        </div>
        <div class="col-12 form-text mt-0"><?= e(__('The TV lowers the volume to the limit when a guest turns it up. Some TVs control the speaker volume in their own firmware; there the rules cannot be applied.')) ?></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
      </div>
    </form>

    <form method="post" class="card mb-3" id="menu">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save_menu">
      <div class="card-header"><i class="bi bi-list-ul"></i> <?= e(__('Guest menu on the TV')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('Guests open this menu with a short press of OK on the TV remote.')) ?></p>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="guest_menu_live_tv" name="guest_menu_live_tv" value="1"<?= $s('guest_menu_live_tv') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="guest_menu_live_tv"><?= e(__('Live TV (set-top box / tuner)')) ?></label>
        </div>
        <label class="form-label"><?= e(__('HDMI inputs shown to guests')) ?></label>
        <?php foreach (DeviceControlsExtension::INPUTS as $hdmi): $n = substr($hdmi, 4); ?>
          <div class="input-group input-group-sm mb-2">
            <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="input_<?= e($hdmi) ?>" value="1" id="in_<?= e($hdmi) ?>"<?= array_key_exists($hdmi, $inputs) ? ' checked' : '' ?>>
              <label class="ms-2" for="in_<?= e($hdmi) ?>">HDMI <?= e($n) ?></label></div>
            <input type="text" class="form-control" name="label_<?= e($hdmi) ?>" maxlength="40" value="<?= e($inputs[$hdmi] ?? '') ?>" placeholder="<?= e(__('Label, e.g. Set-top box')) ?>" aria-label="<?= e(__('Label')) ?> HDMI <?= e($n) ?>">
          </div>
        <?php endforeach; ?>
        <div class="form-check form-switch mt-3 mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="guest_menu_cast" name="guest_menu_cast" value="1"<?= $s('guest_menu_cast') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="guest_menu_cast"><?= e(__('Cast from phone (instructions + Wi-Fi QR code)')) ?></label>
        </div>
        <?php foreach (I18n::GUEST_LANGUAGES as $l => $ln): ?>
          <label class="form-label small mb-1" for="cast_<?= e($l) ?>"><?= e(__('Cast instructions')) ?> (<?= e($ln) ?>)</label>
          <textarea class="form-control form-control-sm mb-2" id="cast_<?= e($l) ?>" name="guest_cast_text_<?= e($l) ?>" rows="2" maxlength="500" placeholder="<?= e(DeviceControlsExtension::castText($l)) ?>"><?= e($s('guest_cast_text_' . $l)) ?></textarea>
        <?php endforeach; ?>
        <div class="form-text mb-3"><?= e(__('Leave empty for the standard text. You can use {ssid} and {password}.')) ?></div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6"><label class="form-label" for="wifi_ssid"><?= e(__('Guest Wi-Fi name')) ?></label><input class="form-control" id="wifi_ssid" name="wifi_ssid" maxlength="64" value="<?= e($s('wifi_ssid')) ?>" autocomplete="off"></div>
          <div class="col-sm-6"><label class="form-label" for="wifi_password"><?= e(__('Guest Wi-Fi password')) ?></label><input class="form-control" id="wifi_password" name="wifi_password" maxlength="64" value="<?= e($s('wifi_password')) ?>" autocomplete="off"></div>
          <?php if ($wifiFromGuests): ?><div class="col-12 form-text"><?= e(__('The same Wi-Fi details are used on the guest welcome screen.')) ?></div><?php endif; ?>
        </div>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="col-xl-6" id="commands">
    <form method="post" class="card mb-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="command">
      <div class="card-header"><i class="bi bi-volume-down"></i> <?= e(__('Volume now')) ?></div>
      <div class="card-body">
        <div class="mb-3">
          <div class="btn-group flex-wrap" role="group">
            <input type="radio" class="btn-check" name="command" value="SET_VOLUME" id="vc_set" checked autocomplete="off"><label class="btn btn-outline-primary btn-sm" for="vc_set"><?= e(__('Set volume')) ?></label>
            <input type="radio" class="btn-check" name="command" value="MUTE" id="vc_mute" autocomplete="off"><label class="btn btn-outline-primary btn-sm" for="vc_mute"><i class="bi bi-volume-mute"></i> <?= e(__('Mute')) ?></label>
            <input type="radio" class="btn-check" name="command" value="UNMUTE" id="vc_unmute" autocomplete="off"><label class="btn btn-outline-primary btn-sm" for="vc_unmute"><i class="bi bi-volume-up"></i> <?= e(__('Unmute')) ?></label>
          </div>
        </div>
        <div class="mb-3" data-vol-level>
          <label class="form-label" for="vlevel"><?= e(__('Volume')) ?>: <strong id="vlevelOut">30</strong>%</label>
          <input type="range" class="form-range" id="vlevel" name="level" min="0" max="100" value="30">
        </div>
        <?= tvc_target('vol', __('Send'), __('Change the volume on the selected TVs?')) ?>
      </div>
    </form>

    <form method="post" class="card mb-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="command"><input type="hidden" name="command" value="SHOW_MESSAGE">
      <div class="card-header"><i class="bi bi-chat-square-text"></i> <?= e(__('Show a message on the TV')) ?></div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label" for="mtitle"><?= e(__('Title')) ?></label><input class="form-control" id="mtitle" name="title" maxlength="120" placeholder="<?= e(__('e.g. Your food is on the way')) ?>"></div>
        <div class="mb-2"><label class="form-label" for="mmsg"><?= e(__('Message')) ?></label><textarea class="form-control" id="mmsg" name="message" rows="2" maxlength="1000"></textarea></div>
        <div class="mb-3" style="max-width:220px"><label class="form-label" for="mdur"><?= e(__('Show for (seconds)')) ?></label><input type="number" class="form-control" id="mdur" name="duration_sec" min="3" max="3600" value="15"></div>
        <div class="row g-2 mb-3" data-msg-sound>
          <div class="col-sm-6"><label class="form-label" for="msound"><i class="bi bi-music-note-beamed"></i> <?= e(__('Sound when the message appears')) ?></label>
            <select class="form-select" id="msound" name="sound">
              <option value="none"><?= e(__('No sound')) ?></option>
              <?php foreach (Sounds::choices([Sounds::NOTICE_CHIME]) as $ref => $name): ?><option value="<?= e($ref) ?>"><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6 col-sm-3"><label class="form-label" for="msrep"><?= e(__('Play times')) ?></label><input type="number" class="form-control" id="msrep" name="sound_repeat" min="1" max="5" value="1"></div>
          <div class="col-6 col-sm-3"><label class="form-label" for="msvol"><?= e(__('Volume')) ?> %</label><input type="number" class="form-control" id="msvol" name="sound_volume" min="0" max="100" value="80"></div>
        </div>
        <?= tvc_target('msg', __('Show message'), __('Show this message on the selected TVs?')) ?>
      </div>
    </form>

    <form method="post" class="card mb-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="command">
      <div class="card-header"><i class="bi bi-hdmi"></i> <?= e(__('Welcome screen and inputs')) ?></div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="tvcmd"><?= e(__('Action')) ?></label>
          <select class="form-select" id="tvcmd" name="command">
            <option value="SHOW_WELCOME"><?= e(__('Show the welcome screen again')) ?></option>
            <option value="OPEN_INPUT"><?= e(__('Switch input')) ?></option>
          </select>
        </div>
        <div class="mb-3" data-open-input hidden>
          <label class="form-label" for="tvinput"><?= e(__('Input')) ?></label>
          <select class="form-select" id="tvinput" name="input">
            <option value="live_tv"><?= e(__('Live TV')) ?></option>
            <?php foreach (DeviceControlsExtension::INPUTS as $hdmi): ?><option value="<?= e($hdmi) ?>">HDMI <?= e(substr($hdmi, 4)) ?><?= ($inputs[$hdmi] ?? '') !== '' ? ' — ' . e($inputs[$hdmi]) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <?= tvc_target('wel', __('Send'), __('Send this command to the selected TVs?')) ?>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const lvl = document.getElementById('vlevel');
  const out = document.getElementById('vlevelOut');
  lvl.addEventListener('input', () => { out.textContent = lvl.value; });
  document.querySelectorAll('input[name="command"][id^="vc_"]').forEach((r) => r.addEventListener('change', () => {
    document.querySelector('[data-vol-level]').hidden = r.value !== 'SET_VOLUME' || !r.checked;
  }));
  const cmd = document.getElementById('tvcmd');
  cmd.addEventListener('change', () => { document.querySelector('[data-open-input]').hidden = cmd.value !== 'OPEN_INPUT'; });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
