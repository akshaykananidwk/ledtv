<?php
/**
 * 2.4 device features card on the TV detail page (admin/rooms.php?action=device): health from the
 * last heartbeat with warnings, live view button (support.view) and the room's USB mode / CEC mode
 * (rooms.manage, saved by admin/tv_health.php op=room_features). Expects $dev (device row + room).
 */
declare(strict_types=1);

if (!isset($dev) || !is_array($dev)) {
    return;
}
$__h = DeviceHealth::of($dev);
$__w = $__h ? DeviceHealth::warnings($__h, $dev) : [];
$__room = $dev['room_id'] ? Tenant::find('rooms', (int) $dev['room_id']) : null;
$__flags = $__room ? DeviceFeatures::roomFlags($__room) : ['usb_mode' => false, 'cec_mode' => 'auto'];
$__mb = static fn ($v) => $v === null ? '–' : ($v >= 1024 ? number_format($v / 1024, 1) . ' GB' : (int) $v . ' MB');
$__rows = $__h ? array_filter([
    __('Storage free') => isset($__h['storage_free_mb']) ? $__mb($__h['storage_free_mb']) . ' / ' . $__mb($__h['storage_total_mb'] ?? null) : null,
    __('Memory free') => isset($__h['ram_avail_mb']) ? $__mb($__h['ram_avail_mb']) . ' / ' . $__mb($__h['ram_total_mb'] ?? null) : null,
    __('App memory') => isset($__h['app_mem_mb']) ? $__mb($__h['app_mem_mb']) : null,
    __('Temperature') => isset($__h['cpu_temp_c']) ? $__h['cpu_temp_c'] . ' °C' : null,
    __('Wi-Fi') => isset($__h['wifi_rssi']) ? $__h['wifi_rssi'] . ' dBm' . (isset($__h['wifi_link_mbps']) ? ' · ' . $__h['wifi_link_mbps'] . ' Mbps' : '') : null,
    __('Resolution') => isset($__h['resolution']) ? $__h['resolution'] . (isset($__h['refresh_hz']) ? ' @ ' . $__h['refresh_hz'] . ' Hz' : '') : null,
    __('Device owner') => isset($__h['device_owner']) ? ($__h['device_owner'] ? __('Yes') : __('No')) : null,
    __('Last crash') => !empty($__h['last_crash_at']) ? $__h['last_crash_at'] : null,
    __('Playing from') => isset($__h['usb']['source']) ? ($__h['usb']['source'] === 'usb' ? __('USB drive') : __('Server')) : null,
    __('CEC') => isset($__h['cec']) ? ($__h['cec']['box_mode'] ? __('Box (TV follows over HDMI-CEC)') : __('TV panel')) : null,
], static fn ($v) => $v !== null) : [];
?>
<div class="card mt-3" id="deviceFeatures">
  <div class="card-header d-flex align-items-center gap-2">
    <span><i class="bi bi-heart-pulse"></i> <?= e(__('TV health')) ?></span>
    <span class="small text-muted"><?= e($dev['health_at'] ?? null ? time_ago($dev['health_at']) : __('no health data')) ?></span>
    <?php if (Auth::can('support.view') && !(int) $dev['is_revoked']): ?>
      <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(admin_url('live_view.php', ['device' => $dev['id']])) ?>"><i class="bi bi-display"></i> <?= e(__('Live view')) ?></a>
    <?php endif; ?>
  </div>
  <?php if ($__w): ?>
    <div class="card-body pb-0">
      <?php foreach ($__w as $__k => $__txt): ?><span class="badge text-bg-danger me-1"><?= e(DeviceHealth::warningLabel($__k)) ?>: <?= e($__txt) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($__rows): ?>
    <table class="table table-sm table-hc mb-0">
      <?php foreach ($__rows as $__k => $__v): ?>
        <tr><th class="text-muted fw-normal ps-3" style="width:40%"><?= e($__k) ?></th><td><?= e($__v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <div class="card-body small text-muted"><?= e(__('No health data yet (app 2.4 or newer sends it with every heartbeat).')) ?></div>
  <?php endif; ?>
  <?php if ($__room && Auth::can('rooms.manage') && Access::canRoom((int) $__room['id'])): ?>
    <form method="post" action="<?= e(admin_url('tv_health.php')) ?>" class="card-body border-top">
      <?= Csrf::field() ?><input type="hidden" name="op" value="room_features">
      <input type="hidden" name="room_id" value="<?= (int) $__room['id'] ?>"><input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" id="usb_mode" name="usb_mode" value="1"<?= $__flags['usb_mode'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="usb_mode"><?= e(__('USB mode: play the "KrishnaCloud" folder of a USB drive / SD card on this room\'s TVs')) ?></label>
      </div>
      <div class="row g-2 align-items-end">
        <div class="col-sm-7">
          <label class="form-label small" for="cec_mode"><?= e(__('CEC mode (TV power)')) ?></label>
          <select class="form-select form-select-sm" id="cec_mode" name="cec_mode">
            <option value="auto"<?= $__flags['cec_mode'] === 'auto' ? ' selected' : '' ?>><?= e(__('Automatic (detect)')) ?></option>
            <option value="box"<?= $__flags['cec_mode'] === 'box' ? ' selected' : '' ?>><?= e(__('Android box: switch the TV with HDMI-CEC')) ?></option>
            <option value="tv"<?= $__flags['cec_mode'] === 'tv' ? ' selected' : '' ?>><?= e(__('Android TV (built-in screen)')) ?></option>
          </select>
        </div>
        <div class="col-sm-5"><button class="btn btn-sm btn-primary w-100"><?= e(__('Save')) ?></button></div>
      </div>
      <div class="form-text"><?= e(__('Box mode: "off" puts the box to sleep so HDMI-CEC switches the TV off, and "on" wakes it (One Touch Play). Needs CEC enabled on the box and the TV.')) ?></div>
    </form>
  <?php endif; ?>
</div>
