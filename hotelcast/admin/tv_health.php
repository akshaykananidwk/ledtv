<?php
/**
 * TV health (2.4 #44, docs/modules/device_features.md): every TV of the current hotel with storage,
 * memory, temperature, Wi-Fi, uptime and versions from its last heartbeat, colour warnings and 24 h
 * sparklines. Every hotel role may look (tv_health.view); users limited to some TVs see only theirs.
 * POST op=room_features (rooms.manage): USB mode / CEC mode of a room (form on the TV detail page).
 * POST op=notify (settings.manage): email / WhatsApp alerts for health warnings on / off.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('tv_health.view');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $back = admin_url('tv_health.php');
    try {
        if ($op === 'room_features') {
            require_can('rooms.manage');
            $deviceId = req_int('device_id', $_POST);
            if ($deviceId && Tenant::find('devices', $deviceId)) {
                $back = admin_url('rooms.php', ['action' => 'device', 'id' => $deviceId]);
            }
            DeviceFeatures::saveRoomFlags(req_int('room_id', $_POST), $_POST);
            flash('success', __('Saved. The TV picks up the change within a few seconds.'));
        } elseif ($op === 'notify') {
            require_can('settings.manage');
            Settings::set('notify_health', !empty($_POST['notify_health']) ? '1' : '0');
            flash('success', __('Saved.'));
        } else {
            flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

$onlyWarn = !empty($_GET['warn']);
[$acc, $ap] = Access::roomSql('d.room_id');
$rows = DB::all(
    "SELECT d.*, r.room_number, r.name AS room_name, r.usb_mode, r.cec_mode
     FROM devices d LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
     WHERE d.hotel_id = :hid AND d.is_revoked = 0 AND d.room_id IS NOT NULL" . $acc . "
     ORDER BY LENGTH(r.room_number), r.room_number, d.id",
    hid() + $ap
);
$tvs = [];
$counts = ['tvs' => count($rows), 'reporting' => 0, 'warn' => 0];
foreach (DeviceHealth::WARNINGS as $k) {
    $counts[$k] = 0;
}
foreach ($rows as $d) {
    $h = DeviceHealth::of($d);
    $warn = $h ? DeviceHealth::warnings($h, $d) : [];
    if ($h) {
        $counts['reporting']++;
    }
    if ($warn) {
        $counts['warn']++;
    }
    foreach (array_keys($warn) as $k) {
        $counts[$k]++;
    }
    if ($onlyWarn && !$warn) {
        continue;
    }
    $tvs[] = ['d' => $d, 'h' => $h, 'warn' => $warn];
}
$history = DeviceHealth::history(array_map(static fn ($t) => (int) $t['d']['id'], $tvs), 24);
$canLive = Auth::can('support.view');
$notifySetting = Settings::get('notify_health', null);
$notifyOn = $notifySetting === null || $notifySetting === '' ? Settings::bool('notify_offline') : $notifySetting === '1';

$mb = static fn ($v) => $v === null ? '–' : ($v >= 1024 ? number_format($v / 1024, 1) . ' GB' : (int) $v . ' MB');
$uptime = static function (?int $s): string {
    if ($s === null) {
        return '–';
    }
    return $s >= 86400 ? __(':d d :h h', ['d' => intdiv($s, 86400), 'h' => intdiv($s % 86400, 3600)]) : __(':h h :m min', ['h' => intdiv($s, 3600), 'm' => intdiv($s % 3600, 60)]);
};
$cell = static fn (bool $bad) => $bad ? ' class="table-danger fw-semibold"' : '';

$pageTitle = __('TV health');
$activeNav = 'tv_health';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-heart-pulse"></i> <?= e(__('TV health')) ?></h1>
    <p class="lead-sm"><?= e(__('Storage, memory, temperature, Wi-Fi and uptime of every TV, from its last heartbeat. Red cells need attention.')) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <a class="btn <?= $onlyWarn ? 'btn-light border' : 'btn-outline-danger' ?>" href="<?= e(admin_url('tv_health.php', $onlyWarn ? [] : ['warn' => 1])) ?>">
      <i class="bi bi-funnel"></i> <?= e($onlyWarn ? __('Show all TVs') : __('Only TVs with warnings')) ?>
    </a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted"><?= e(__('TVs reporting health')) ?></div><div class="fs-4 fw-semibold"><?= (int) $counts['reporting'] ?> / <?= (int) $counts['tvs'] ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted"><?= e(__('TVs with warnings')) ?></div><div class="fs-4 fw-semibold<?= $counts['warn'] ? ' text-danger' : '' ?>"><?= (int) $counts['warn'] ?></div></div></div></div>
  <div class="col-md-6"><div class="card"><div class="card-body small">
    <?php foreach (DeviceHealth::WARNINGS as $k): ?>
      <span class="badge <?= $counts[$k] ? 'text-bg-danger' : 'text-bg-light border' ?> me-1 mb-1"><?= e(DeviceHealth::warningLabel($k)) ?>: <?= (int) $counts[$k] ?></span>
    <?php endforeach; ?>
    <div class="text-muted mt-1"><?= e(__('Limits: storage < :s MB, memory < :r MB or < :p %, temperature > :t °C, Wi-Fi < :w dBm, uptime > :u days.', [
        's' => DeviceHealth::STORAGE_MIN_MB, 'r' => DeviceHealth::RAM_MIN_MB, 'p' => DeviceHealth::RAM_MIN_PCT,
        't' => DeviceHealth::TEMP_MAX_C, 'w' => DeviceHealth::WIFI_MIN_DBM, 'u' => DeviceHealth::UPTIME_MAX_DAYS,
    ])) ?></div>
  </div></div></div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hc table-hover align-middle mb-0">
      <thead><tr>
        <th><?= e(__('Screen')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Storage free')) ?></th><th><?= e(__('Memory free')) ?></th>
        <th><?= e(__('Temperature')) ?></th><th><?= e(__('Network')) ?></th><th><?= e(__('Uptime')) ?></th>
        <th class="d-none d-lg-table-cell"><?= e(__('Last 24 hours')) ?></th><th class="d-none d-xl-table-cell"><?= e(__('Device')) ?></th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$tvs): ?><tr><td colspan="10" class="text-center text-muted py-4"><?= e($onlyWarn ? __('No TV has a warning.') : __('No TVs registered yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($tvs as $t): $d = $t['d']; $h = $t['h']; $w = $t['warn']; $hist = $history[(int) $d['id']] ?? []; ?>
        <tr>
          <td><strong><?= e($d['room_number'] ?? '-') ?></strong>
            <?php if (!empty($d['usb_mode'])): ?><span class="badge text-bg-info ms-1" title="<?= e(__('USB mode')) ?>"><i class="bi bi-usb-drive"></i></span><?php endif; ?>
            <?php foreach ($w as $k => $txt): ?><div><span class="badge text-bg-danger"><?= e(DeviceHealth::warningLabel($k)) ?></span></div><?php endforeach; ?>
          </td>
          <td><?= status_badge(DeviceManager::isOnline($d) ? 'online' : 'offline') ?><div class="small text-muted"><?= e($d['health_at'] ? time_ago($d['health_at']) : __('no health data')) ?></div></td>
          <?php if (!$h): ?>
            <td colspan="5" class="small text-muted"><?= e(__('No health data yet (app 2.4 or newer sends it with every heartbeat).')) ?></td>
          <?php else: ?>
            <td<?= $cell(isset($w['storage_low'])) ?>><?= e($mb($h['storage_free_mb'] ?? null)) ?><div class="small text-muted"><?= e(__('of :t', ['t' => $mb($h['storage_total_mb'] ?? null)])) ?></div></td>
            <td<?= $cell(isset($w['ram_low'])) ?>><?= e($mb($h['ram_avail_mb'] ?? null)) ?><div class="small text-muted"><?= e(__('of :t', ['t' => $mb($h['ram_total_mb'] ?? null)])) ?><?= isset($h['app_mem_mb']) ? ' · ' . e(__('app :n MB', ['n' => $h['app_mem_mb']])) : '' ?></div></td>
            <td<?= $cell(isset($w['temp_high'])) ?>><?= isset($h['cpu_temp_c']) ? e($h['cpu_temp_c'] . ' °C') : '<span class="text-muted">–</span>' ?></td>
            <td<?= $cell(isset($w['wifi_weak'])) ?>>
              <?= e(($h['network'] ?? $d['network_type'] ?? '-') === 'ethernet' ? __('Ethernet') : (($h['network'] ?? $d['network_type'] ?? '') === 'wifi' ? 'Wi-Fi' : (string) ($h['network'] ?? $d['network_type'] ?? '–'))) ?>
              <?php if (isset($h['wifi_rssi'])): ?><div class="small"><?= (int) $h['wifi_rssi'] ?> dBm<?= isset($h['wifi_link_mbps']) ? ' · ' . (int) $h['wifi_link_mbps'] . ' Mbps' : '' ?></div><?php endif; ?>
            </td>
            <td<?= $cell(isset($w['uptime_long'])) ?>><?= e($uptime(isset($h['uptime_sec']) ? (int) $h['uptime_sec'] : null)) ?></td>
          <?php endif; ?>
          <td class="d-none d-lg-table-cell">
            <div class="d-flex flex-column gap-1">
              <span class="small text-muted"><?= e(__('Temperature')) ?></span>
              <?= DeviceHealth::sparkline(array_column($hist, 'cpu_temp_c'), __('Temperature'), DeviceHealth::TEMP_MAX_C) ?>
              <span class="small text-muted"><?= e(__('Memory free')) ?></span>
              <?= DeviceHealth::sparkline(array_column($hist, 'ram_avail_mb'), __('Memory free'), null, DeviceHealth::RAM_MIN_MB) ?>
            </div>
          </td>
          <td class="d-none d-xl-table-cell small">
            <?= e(dot_trim(($d['model'] ?? '') . ' · Android ' . ($h['android_version'] ?? $d['android_version'] ?? '?'))) ?>
            <div class="text-muted">v<?= e($d['app_version'] ?? '?') ?><?= isset($h['resolution']) ? ' · ' . e($h['resolution']) : '' ?><?= !empty($h['device_owner']) ? ' · ' . e(__('device owner')) : '' ?></div>
            <?php if (!empty($h['last_crash_at'])): ?><div class="text-danger"><?= e(__('Last crash: :t', ['t' => time_ago($h['last_crash_at'])])) ?></div><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($canLive): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('live_view.php', ['device' => $d['id']])) ?>" title="<?= e(__('Live view')) ?>"><i class="bi bi-display"></i></a><?php endif; ?>
            <?php if (Auth::can('rooms.view')): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $d['id']])) ?>" title="<?= e(__('TV details')) ?>"><i class="bi bi-info-circle"></i></a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (Auth::can('settings.manage')): ?>
<form method="post" class="card mt-3">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <?= Csrf::field() ?><input type="hidden" name="op" value="notify">
    <div class="form-check form-switch m-0">
      <input class="form-check-input" type="checkbox" role="switch" id="notify_health" name="notify_health" value="1"<?= $notifyOn ? ' checked' : '' ?>>
      <label class="form-check-label" for="notify_health"><?= e(__('Send health warnings by email / WhatsApp (same contacts as the offline alerts)')) ?></label>
    </div>
    <span class="small text-muted"><?= e(__('Each warning is sent once per TV and again after 24 hours if it stays.')) ?></span>
    <button class="btn btn-sm btn-primary ms-auto"><?= e(__('Save')) ?></button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
