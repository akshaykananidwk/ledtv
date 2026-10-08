<?php
/**
 * Live screen view of one TV (2.4 #41, docs/modules/device_features.md). Opening the page starts a
 * live session (ajax live_start → LIVE_VIEW to the TV); the page polls live_poll every 3 s, which keeps
 * the session alive and returns the newest frame's age; leaving the page calls live_stop. After
 * 10 minutes the page pauses itself until "Continue" is pressed. The TV stops on its own 2 minutes
 * after the last keepalive. ?action=frame&device=ID serves the newest frame (manager+, Access).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('support.view');
Csrf::check();

$deviceId = req_int('device', $_GET);
$dev = $deviceId ? Tenant::find('devices', $deviceId) : null;
if (!$dev) {
    if (req_str('action', $_GET, 20) === 'frame') {
        http_response_code(404);
        exit('Not found');
    }
    flash('warning', __('Device not found.'));
    redirect(admin_url('support.php'));
}
Access::requireDevice($deviceId);

if (req_str('action', $_GET, 20) === 'frame') {
    $file = LiveView::framePath(Tenant::id(), $deviceId);
    session_write_close();
    if (!is_file($file)) {
        http_response_code(404);
        exit('No frame yet');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
    readfile($file);
    exit;
}

$room = $dev['room_id'] ? DB::one('SELECT room_number, name FROM rooms WHERE id = :id AND hotel_id = :hid', ['id' => $dev['room_id']] + hid()) : null;
$online = DeviceManager::isOnline($dev);
$pageTitle = __('Live view') . ' · ' . ($room['room_number'] ?? '#' . $deviceId);
$activeNav = 'support';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-display"></i> <?= e(__('Live view')) ?> — <?= e($room['room_number'] ?? '-') ?></h1>
    <p class="lead-sm mono"><?= e($dev['device_uid']) ?> · <?= e(trim(($dev['model'] ?? '') . ' · v' . ($dev['app_version'] ?? '?'), ' ·')) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?= (int) $dev['is_revoked'] ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : status_badge($online ? 'online' : 'offline') ?>
    <a href="<?= e(admin_url('support.php', ['device' => $deviceId])) ?>" class="btn btn-light border" id="liveBack"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    <button type="button" class="btn btn-outline-danger" id="liveStop"><i class="bi bi-stop-circle"></i> <?= e(__('Stop')) ?></button>
    <button type="button" class="btn btn-primary" id="liveContinue" hidden><i class="bi bi-play-circle"></i> <?= e(__('Continue live view')) ?></button>
  </div>
</div>
<?php if (!$online && !(int) $dev['is_revoked']): ?>
  <div class="alert alert-warning small"><?= e(__('This TV is offline. Live view starts when it comes back online.')) ?></div>
<?php endif; ?>
<div class="card">
  <div class="card-body">
    <div class="d-flex flex-wrap gap-3 align-items-center mb-2 small">
      <span id="liveState" class="badge text-bg-secondary"><?= e(__('Starting…')) ?></span>
      <span id="liveAge" class="text-muted"></span>
      <span id="liveMsg" class="text-muted"></span>
    </div>
    <div class="hc-live-frame bg-dark rounded d-flex align-items-center justify-content-center" style="min-height:240px">
      <img id="liveImg" alt="<?= e(__('Live view')) ?>" style="max-width:100%;max-height:75vh;display:none">
      <span id="liveWait" class="text-white-50 p-4"><span class="spinner-border spinner-border-sm"></span> <?= e(__('Waiting for the TV to send its screen… (usually within 10 seconds)')) ?></span>
    </div>
    <p class="small text-muted mt-2 mb-0"><?= e(__('A new picture every 3–5 seconds while this page is open; the TV stops by itself about 2 minutes after you close it. Videos may appear black on some TVs: the video layer is not part of the screenshot.')) ?></p>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const T = <?= json_embed([
      'live' => __('Live'), 'paused' => __('Paused'), 'stopped' => __('Stopped'), 'waiting' => __('Waiting for the TV'),
      'age' => __('Picture from :s s ago'), 'failed' => __('The TV could not start live view: :m'),
      'stale' => __('No new picture for :s s (TV in standby, offline or busy).'),
  ]) ?>;
  const deviceId = <?= (int) $deviceId ?>;
  const frameUrl = <?= json_embed(admin_url('live_view.php', ['device' => $deviceId, 'action' => 'frame'])) ?>;
  const ajaxUrl = document.querySelector('meta[name="hc-ajax"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const $ = (id) => document.getElementById(id);
  const call = (action, body, query, keepalive) => fetch(ajaxUrl + '?' + new URLSearchParams(Object.assign({ action }, query || {})), {
    method: body ? 'POST' : 'GET', credentials: 'same-origin', keepalive: !!keepalive,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: body ? JSON.stringify(body) : undefined,
  }).then((r) => r.json()).then((j) => { if (!j.ok) { throw new Error(j.error && j.error.message || 'Error'); } return j.data; });
  const MAX_MS = 10 * 60 * 1000;
  let started = 0; let timer = null; let lastN = -1; let running = false;
  const state = (txt, cls) => { $('liveState').textContent = txt; $('liveState').className = 'badge ' + cls; };
  function show(st) {
    if (st.frame) {
      if (st.frame.n !== lastN) {
        lastN = st.frame.n;
        const img = $('liveImg');
        img.onload = () => { img.style.display = ''; $('liveWait').style.display = 'none'; };
        img.src = frameUrl + '&n=' + st.frame.n;
      }
      $('liveAge').textContent = T.age.replace(':s', st.frame.age_sec);
      $('liveMsg').textContent = st.frame.age_sec > 20 ? T.stale.replace(':s', st.frame.age_sec) : '';
    }
    if (st.command && st.command.status === 'failed') {
      $('liveMsg').textContent = T.failed.replace(':m', st.command.message || '');
    }
    if (running) state(st.frame && st.frame.age_sec <= 20 ? T.live : T.waiting, st.frame && st.frame.age_sec <= 20 ? 'text-bg-success' : 'text-bg-warning');
  }
  function poll() {
    if (!running) return;
    if (Date.now() - started > MAX_MS) { pause(); return; }
    call('live_poll', null, { device_id: deviceId }).then((st) => {
      show(st);
      if (!st.active) { start(); return; } // expired meanwhile (e.g. laptop asleep): renew
      timer = setTimeout(poll, 3000);
    }).catch(() => { timer = setTimeout(poll, 5000); });
  }
  function start() {
    running = true; started = started || Date.now();
    $('liveContinue').hidden = true; $('liveStop').hidden = false;
    call('live_start', { device_id: deviceId }).then((st) => { show(st); clearTimeout(timer); timer = setTimeout(poll, 2000); })
      .catch((e) => { running = false; state(T.stopped, 'text-bg-danger'); $('liveMsg').textContent = e.message; });
  }
  function stop(beacon) {
    running = false; clearTimeout(timer);
    call('live_stop', { device_id: deviceId }, null, beacon).catch(() => {});
  }
  function pause() {
    stop(false); state(T.paused, 'text-bg-secondary');
    $('liveContinue').hidden = false; $('liveStop').hidden = true;
  }
  $('liveStop').addEventListener('click', () => { stop(false); state(T.stopped, 'text-bg-secondary'); $('liveContinue').hidden = false; $('liveStop').hidden = true; });
  $('liveContinue').addEventListener('click', () => { started = Date.now(); start(); });
  window.addEventListener('pagehide', () => { if (running) stop(true); });
  start();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
