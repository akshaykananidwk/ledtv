<?php
/**
 * TV support (#24) for the current hotel: per TV the last screenshot, uploaded log bundles, crash
 * reports and analytics events; "Take screenshot" / "Request logs" ask the TV and wait for the
 * upload. Uploaded files are only reachable through this page (support.php?action=file&id=…).
 * Manager+ (support.view).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('support.view');
Csrf::check();
$action = req_str('action', $_GET, 20);

// ------------------------------------------------------------------ file download / view
if ($action === 'file') {
    $row = Tenant::find('device_support_files', req_int('id', $_GET));
    $path = $row ? DeviceSupport::path($row) : null;
    if (!$row || !$path) {
        http_response_code(404);
        exit('Not found');
    }
    Access::requireDevice((int) $row['device_id']); // users limited to some TVs
    session_write_close();
    $isImg = $row['kind'] === 'screenshot';
    $name = $row['kind'] . '-' . $row['device_id'] . '-' . date('Ymd-His', (int) strtotime($row['created_at'])) . ($isImg ? '.jpg' : '.txt');
    header('Content-Type: ' . ($isImg ? 'image/jpeg' : 'text/plain; charset=utf-8'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Cache-Control: private, max-age=300');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($path);
    exit;
}

$deviceId = req_int('device', $_GET);
if ($deviceId) {
    $dev = Tenant::find('devices', $deviceId);
    if (!$dev) {
        flash('warning', __('Device not found.'));
        redirect(admin_url('support.php'));
    }
    Access::requireDevice($deviceId);
    if (is_post() && req_str('op', $_POST, 20) === 'delete_file') {
        $row = Tenant::find('device_support_files', req_int('file_id', $_POST), 'device_id = :d', ['d' => $deviceId]);
        if ($row) {
            DeviceSupport::deleteRow($row);
            flash('success', __('Deleted.'));
        }
        redirect(admin_url('support.php', ['device' => $deviceId]));
    }
    $room = $dev['room_id'] ? DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :hid', ['id' => $dev['room_id']] + hid()) : null;
    $shots = DeviceSupport::files($deviceId, 'screenshot', 20);
    $logs = DeviceSupport::files($deviceId, 'logs', 20);
    $crashes = DeviceSupport::files($deviceId, 'crash', 20);
    $events = DB::all('SELECT type, data, created_at FROM device_events WHERE hotel_id = :hid AND device_id = :d ORDER BY id DESC LIMIT 30', ['d' => $deviceId] + hid());
    $pending = DB::all("SELECT id, command, status, created_at FROM device_commands WHERE device_id = :d AND command IN ('SCREENSHOT','UPLOAD_LOGS') AND status IN ('pending','delivered') AND created_at > :t ORDER BY id DESC", ['d' => $deviceId, 't' => date('Y-m-d H:i:s', time() - 600)]);
    $online = DeviceManager::isOnline($dev);
    $pageTitle = __('TV support') . ' · ' . ($room['room_number'] ?? '#' . $deviceId);
    $activeNav = 'support';
    require __DIR__ . '/partials/header.php';
    $fileUrl = static fn (array $f, bool $dl = false) => admin_url('support.php', ['action' => 'file', 'id' => $f['id']] + ($dl ? ['download' => 1] : []));
    ?>
    <div class="page-head">
      <div>
        <h1><i class="bi bi-life-preserver"></i> <?= e(__('TV support')) ?> — <?= e($room['room_number'] ?? '-') ?></h1>
        <p class="lead-sm mono"><?= e($dev['device_uid']) ?> · <?= e(dot_trim(($dev['model'] ?? '') . ' · v' . ($dev['app_version'] ?? '?'))) ?></p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <?= (int) $dev['is_revoked'] ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : status_badge($online ? 'online' : 'offline') ?>
        <a href="<?= e(admin_url('support.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
        <?php if (!(int) $dev['is_revoked']): ?>
          <button type="button" class="btn btn-primary" data-support="SCREENSHOT"><i class="bi bi-camera"></i> <?= e(__('Take screenshot')) ?></button>
          <button type="button" class="btn btn-outline-primary" data-support="UPLOAD_LOGS"><i class="bi bi-file-earmark-text"></i> <?= e(__('Request logs')) ?></button>
          <a class="btn btn-outline-primary" href="<?= e(admin_url('live_view.php', ['device' => $deviceId])) ?>"><i class="bi bi-display"></i> <?= e(__('Live view')) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="alert alert-info d-flex align-items-center gap-2" id="supportWait" role="status" hidden>
      <span class="spinner-border spinner-border-sm"></span><span data-text></span>
    </div>
    <?php if (!$online && !(int) $dev['is_revoked']): ?>
      <div class="alert alert-warning small"><?= e(__('This TV is offline. The request is delivered when it comes back online (within 24 hours).')) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-xl-7">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-image"></i> <?= e(__('Last screenshot')) ?></div>
          <div class="card-body">
            <?php if (!$shots): ?>
              <p class="text-muted mb-0"><?= e(__('No screenshot yet. Click "Take screenshot".')) ?></p>
            <?php else: $last = $shots[0]; ?>
              <a href="<?= e($fileUrl($last)) ?>" target="_blank" rel="noopener"><img class="hc-shot" src="<?= e($fileUrl($last)) ?>" alt="<?= e(__('Screenshot')) ?>" loading="lazy"></a>
              <div class="small text-muted mt-1"><?= e($last['created_at']) ?> · <?= e(human_bytes((int) $last['file_size'])) ?> · <a href="<?= e($fileUrl($last, true)) ?>"><?= e(__('Download')) ?></a></div>
              <?php if (count($shots) > 1): ?>
                <div class="d-flex flex-wrap gap-2 mt-3">
                  <?php foreach (array_slice($shots, 1) as $sh): ?>
                    <a class="btn btn-sm btn-light border" href="<?= e($fileUrl($sh)) ?>" target="_blank" rel="noopener"><i class="bi bi-image"></i> <?= e(date('d M H:i', (int) strtotime($sh['created_at']))) ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <p class="small text-muted mt-2 mb-0"><?= e(__('Videos may appear black on some TVs: the video layer is not part of the screenshot.')) ?></p>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-bug"></i> <?= e(__('Crash reports')) ?></div>
          <ul class="list-group list-group-flush">
            <?php if (!$crashes): ?><li class="list-group-item text-muted"><?= e(__('No crash reports.')) ?></li><?php endif; ?>
            <?php foreach ($crashes as $c): $meta = json_decode((string) $c['meta'], true) ?: []; $p = DeviceSupport::path($c); ?>
              <li class="list-group-item">
                <details>
                  <summary class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge text-bg-danger"><?= e($c['happened_at'] ?? $c['created_at']) ?></span>
                    <span class="small text-muted">v<?= e($c['app_version'] ?? '?') ?></span>
                    <span class="small text-truncate" style="max-width:100%"><?= e($meta['summary'] ?? '') ?></span>
                  </summary>
                  <pre class="hc-log-view mt-2"><?= e($p ? (string) file_get_contents($p, false, null, 0, DeviceSupport::MAX_CRASH) : __('File missing.')) ?></pre>
                  <a class="small" href="<?= e($fileUrl($c, true)) ?>"><?= e(__('Download')) ?></a>
                </details>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <div class="col-xl-5">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-file-earmark-text"></i> <?= e(__('Uploaded logs')) ?></div>
          <ul class="list-group list-group-flush">
            <?php if (!$logs): ?><li class="list-group-item text-muted"><?= e(__('No logs yet. Click "Request logs".')) ?></li><?php endif; ?>
            <?php foreach ($logs as $l): $meta = json_decode((string) $l['meta'], true) ?: []; ?>
              <li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
                <span class="me-auto small"><?= e($l['created_at']) ?><br><span class="text-muted"><?= e(human_bytes((int) $l['file_size'])) ?> · <?= e(__(':n lines', ['n' => (int) ($meta['lines'] ?? 0)])) ?></span></span>
                <a class="btn btn-sm btn-light border" href="<?= e($fileUrl($l)) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> <?= e(__('View')) ?></a>
                <a class="btn btn-sm btn-light border" href="<?= e($fileUrl($l, true)) ?>"><i class="bi bi-download"></i></a>
                <form method="post" class="m-0" data-confirm="<?= e(__('Delete this file?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete_file"><input type="hidden" name="file_id" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-activity"></i> <?= e(__('Recent TV events')) ?></div>
          <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm table-hc mb-0">
              <tbody>
              <?php if (!$events): ?><tr><td class="text-muted text-center py-3"><?= e(__('No events yet.')) ?></td></tr><?php endif; ?>
              <?php foreach ($events as $ev): ?>
                <tr><td class="small text-nowrap text-muted"><?= e(date('d M H:i', (int) strtotime($ev['created_at']))) ?></td><td class="small"><strong><?= e($ev['type']) ?></strong> <span class="text-muted mono"><?= e(mb_substr((string) $ev['data'], 0, 160)) ?></span></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const T = <?= json_embed([
          'wait_shot' => __('Waiting for the TV to send a screenshot…'),
          'wait_logs' => __('Waiting for the TV to upload its logs…'),
          'failed' => __('The TV could not do it: :m'),
          'expired' => __('The request expired.'),
          'timeout' => __('No answer from the TV yet. The result appears here when the TV sends it.'),
      ]) ?>;
      const deviceId = <?= (int) $deviceId ?>;
      const box = document.getElementById('supportWait');
      const ajaxUrl = document.querySelector('meta[name="hc-ajax"]').content;
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const call = (action, body, query) => fetch(ajaxUrl + '?' + new URLSearchParams(Object.assign({ action }, query || {})), {
        method: body ? 'POST' : 'GET', credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: body ? JSON.stringify(body) : undefined,
      }).then((r) => r.json()).then((j) => { if (!j.ok) { throw new Error(j.error && j.error.message || 'Error'); } return j.data; });
      function wait(commandId, command) {
        box.hidden = false;
        box.className = 'alert alert-info d-flex align-items-center gap-2';
        box.querySelector('[data-text]').textContent = command === 'SCREENSHOT' ? T.wait_shot : T.wait_logs;
        const started = Date.now();
        const tick = () => call('support_status', null, { device_id: deviceId, command_id: commandId }).then((st) => {
          if (st.file) { location.reload(); return; }
          if (st.status === 'failed' || st.status === 'expired') {
            box.className = 'alert alert-danger d-flex align-items-center gap-2';
            box.querySelector('.spinner-border') && box.querySelector('.spinner-border').remove();
            box.querySelector('[data-text]').textContent = st.status === 'failed' ? T.failed.replace(':m', st.message || '') : T.expired;
            document.querySelectorAll('[data-support]').forEach((b) => { b.disabled = false; });
            return;
          }
          if (Date.now() - started > 120000) { box.querySelector('[data-text]').textContent = T.timeout; }
          setTimeout(tick, Date.now() - started > 120000 ? 10000 : 2500);
        }).catch(() => setTimeout(tick, 5000));
        tick();
      }
      document.querySelectorAll('[data-support]').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('[data-support]').forEach((x) => { x.disabled = true; });
        call('support_request', { device_id: deviceId, command: b.dataset.support })
          .then((d) => wait(d.command_id, b.dataset.support))
          .catch((e) => { document.querySelectorAll('[data-support]').forEach((x) => { x.disabled = false; }); (window.HC && HC.toast) ? HC.toast(e.message, 'danger') : alert(e.message); });
      }));
      <?php if ($pending): ?>wait(<?= (int) $pending[0]['id'] ?>, <?= json_embed($pending[0]['command']) ?>);<?php endif; ?>
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ------------------------------------------------------------------ TV list
$rows = DB::all(
    "SELECT d.*, r.room_number,
        (SELECT MAX(created_at) FROM device_support_files f WHERE f.hotel_id = d.hotel_id AND f.device_id = d.id AND f.kind = 'screenshot') AS last_shot,
        (SELECT COUNT(*) FROM device_support_files f WHERE f.hotel_id = d.hotel_id AND f.device_id = d.id AND f.kind = 'logs') AS logs,
        (SELECT COUNT(*) FROM device_support_files f WHERE f.hotel_id = d.hotel_id AND f.device_id = d.id AND f.kind = 'crash' AND f.created_at >= :week) AS crashes
     FROM devices d LEFT JOIN rooms r ON r.id = d.room_id AND r.hotel_id = d.hotel_id
     WHERE d.hotel_id = :hid AND d.is_revoked = 0 AND d.room_id IS NOT NULL" . Access::roomSql('d.room_id')[0] . "
     ORDER BY crashes DESC, LENGTH(r.room_number), r.room_number",
    ['week' => date('Y-m-d H:i:s', time() - 7 * 86400)] + hid() + Access::roomSql('d.room_id')[1]
);
$newest = DB::one('SELECT version_code, version_name FROM apk_releases WHERE hotel_id = :hid ORDER BY version_code DESC LIMIT 1', hid());
$pageTitle = __('TV support');
$activeNav = 'support';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-life-preserver"></i> <?= e(__('TV support')) ?></h1>
    <p class="lead-sm"><?= e(__('Screenshots, logs and crash reports of your TVs. Open a TV to request a screenshot or its logs.')) ?></p></div>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('Room')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-md-table-cell"><?= e(__('App version')) ?></th><th><?= e(__('Crashes (7 days)')) ?></th><th class="d-none d-sm-table-cell"><?= e(__('Logs')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Last screenshot')) ?></th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No TVs registered yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($rows as $d): $outdated = $newest && !DeviceManager::isWeb($d) && $d['app_version_code'] !== null && (int) $d['app_version_code'] < (int) $newest['version_code']; ?>
        <tr>
          <td><strong><?= e($d['room_number'] ?? '-') ?></strong><div class="small text-muted mono"><?= e(substr((string) $d['device_uid'], 0, 13)) ?></div></td>
          <td><?= status_badge(DeviceManager::isOnline($d) ? 'online' : 'offline') ?></td>
          <td class="d-none d-md-table-cell small">v<?= e($d['app_version'] ?? '?') ?><?php if ($outdated): ?> <span class="badge text-bg-warning" title="<?= e(__('Newest: :v', ['v' => $newest['version_name']])) ?>"><?= e(__('Update available')) ?></span><?php endif; ?></td>
          <td><?= (int) $d['crashes'] ? '<span class="badge text-bg-danger">' . (int) $d['crashes'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td class="d-none d-sm-table-cell"><?= (int) $d['logs'] ?></td>
          <td class="d-none d-lg-table-cell small text-muted"><?= e($d['last_shot'] ? time_ago($d['last_shot']) : '-') ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('live_view.php', ['device' => $d['id']])) ?>" title="<?= e(__('Live view')) ?>"><i class="bi bi-display"></i></a> <a class="btn btn-sm btn-primary" href="<?= e(admin_url('support.php', ['device' => $d['id']])) ?>"><i class="bi bi-tools"></i> <?= e(__('Open')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
