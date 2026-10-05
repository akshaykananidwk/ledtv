<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('apk.manage');
if (post_too_large()) {
    $msg = __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]);
    if (Auth::isAjax()) {
        ajax_error($msg, 413, 'TOO_LARGE');
    }
    flash('danger', $msg);
    redirect(admin_url('apk.php'));
}
Csrf::check();

function apk_done(bool $ok, string $msg): never
{
    if (Auth::isAjax()) {
        if ($ok) {
            flash('success', $msg);
            ajax_ok(['redirect' => admin_url('apk.php')]);
        }
        ajax_error($msg, 422, 'VALIDATION_ERROR');
    }
    flash($ok ? 'success' : 'danger', $msg);
    redirect(admin_url('apk.php'));
}

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    switch ($op) {
        case 'upload':
            $vName = req_str('version_name', $_POST, 30);
            $vCode = req_int('version_code', $_POST);
            if (!preg_match('/^[0-9A-Za-z._-]{1,30}$/', $vName) || $vCode < 1) {
                apk_done(false, __('Version name (e.g. 1.2.0) and version code (a whole number, e.g. 7) are required.'));
            }
            if (!isset($_FILES['apk']) || !is_array($_FILES['apk'])) {
                apk_done(false, __('No file was selected.'));
            }
            try {
                $up = Uploader::handle($_FILES['apk'], 'apk');
            } catch (RuntimeException $e) {
                apk_done(false, $e->getMessage());
            }
            $abs = HC_ROOT . '/storage/' . $up['path'];
            $id = DB::insert('apk_releases', [
                'version_name' => $vName,
                'version_code' => $vCode,
                'file_path' => $up['path'],
                'file_size' => (int) filesize($abs),
                'sha256' => (string) hash_file('sha256', $abs),
                'notes' => req_str('notes', $_POST, 5000) ?: null,
                'uploaded_by' => Auth::id(),
                'created_at' => now(),
            ]);
            ActivityLog::add('apk_upload', 'apk', $id, "v$vName ($vCode)");
            apk_done(true, __('APK v:v uploaded. Use "Push update" to install it on TVs.', ['v' => $vName]));

        case 'delete':
            $id = req_int('id', $_POST);
            $apk = Tenant::find('apk_releases', $id);
            if ($apk) {
                $path = (string) $apk['file_path'];
                if ($path !== '' && !str_contains($path, '..') && str_starts_with($path, 'apk/')) {
                    @unlink(HC_ROOT . '/storage/' . $path);
                }
                DB::delete('apk_releases', 'id = :id', ['id' => $id]);
                ActivityLog::add('apk_delete', 'apk', $id, 'v' . $apk['version_name']);
                flash('success', __('APK deleted.'));
            }
            redirect(admin_url('apk.php'));

        case 'push':
            $id = req_int('apk_id', $_POST);
            [$type, $ids] = Broadcaster::parseTarget($_POST);
            if ($type !== 'all' && !$ids) {
                flash('danger', __('Select at least one target.'));
                redirect(admin_url('apk.php'));
            }
            try {
                [$bid, $count] = Broadcaster::pushApk($id, $type, $ids, Auth::id());
                ActivityLog::add('apk_push', 'broadcast', $bid, 'APK #' . $id . ' → ' . Broadcaster::describeTarget($type, $ids) . " ($count TVs)");
                flash('success', __('Update sent to :n TVs. They download and install it in the background.', ['n' => $count]));
            } catch (InvalidArgumentException $e) {
                flash('danger', $e->getMessage());
            }
            redirect(admin_url('apk.php'));
    }
    redirect(admin_url('apk.php'));
}

$releases = DB::all('SELECT a.*, u.username FROM apk_releases a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.hotel_id = :hid ORDER BY a.version_code DESC, a.id DESC', hid());
$latestCode = $releases ? (int) $releases[0]['version_code'] : 0;
$devices = DB::all(
    "SELECT d.*, r.room_number,
            (SELECT c.status FROM device_commands c WHERE c.device_id = d.id AND c.command = 'UPDATE_APP' ORDER BY c.id DESC LIMIT 1) AS upd_status,
            (SELECT c.created_at FROM device_commands c WHERE c.device_id = d.id AND c.command = 'UPDATE_APP' ORDER BY c.id DESC LIMIT 1) AS upd_at,
            (SELECT c.message FROM device_commands c WHERE c.device_id = d.id AND c.command = 'UPDATE_APP' ORDER BY c.id DESC LIMIT 1) AS upd_msg
     FROM devices d LEFT JOIN rooms r ON r.id = d.room_id
     WHERE d.hotel_id = :hid AND d.is_revoked = 0 ORDER BY LENGTH(r.room_number), r.room_number",
    hid()
);
$pageTitle = __('APK Manager');
$activeNav = 'apk';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('APK Manager')) ?></h1><p class="lead-sm"><?= e(__('Upload new versions of the TV app and install them on all TVs remotely.')) ?></p></div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-upload"></i> <?= e(__('Upload new APK')) ?></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="js-upload" data-progress="#apkProgress" action="<?= e(admin_url('apk.php')) ?>">
          <?= Csrf::field() ?><input type="hidden" name="op" value="upload">
          <div class="mb-2">
            <label class="form-label" for="apkf"><?= e(__('APK file')) ?> *</label>
            <input class="form-control" type="file" id="apkf" name="apk" accept=".apk,application/vnd.android.package-archive" required>
            <div class="form-text"><?= e(__('Server upload limit: :s.', ['s' => human_bytes(upload_limit())])) ?></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label" for="vn"><?= e(__('Version name')) ?> *</label><input class="form-control" id="vn" name="version_name" required maxlength="30" placeholder="1.2.0"></div>
            <div class="col-6"><label class="form-label" for="vc"><?= e(__('Version code')) ?> *</label><input class="form-control" type="number" id="vc" name="version_code" required min="1" value="<?= $latestCode + 1 ?>"></div>
          </div>
          <div class="form-text mb-2"><?= e(__('Must match versionName / versionCode in the app build. TVs only install a higher version code.')) ?></div>
          <div class="mb-3"><label class="form-label" for="notes"><?= e(__('Release notes')) ?></label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="5000"></textarea></div>
          <div id="apkProgress" class="mb-2" hidden>
            <div class="small mb-1" data-progress-label><?= e(__('Uploading…')) ?></div>
            <div class="progress" style="height:20px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div></div>
          </div>
          <button class="btn btn-primary"><i class="bi bi-upload"></i> <?= e(__('Upload')) ?></button>
        </form>
      </div>
    </div>
    <?php if ($releases): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Push app update')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="push">
          <div class="mb-2">
            <label class="form-label" for="apkid"><?= e(__('Version')) ?></label>
            <select class="form-select" name="apk_id" id="apkid">
              <?php foreach ($releases as $r): ?><option value="<?= (int) $r['id'] ?>">v<?= e($r['version_name']) ?> (<?= (int) $r['version_code'] ?>) — <?= e(date('d M Y', (int) strtotime($r['created_at']))) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><?= target_picker('apk') ?></div>
          <button class="btn btn-success" data-confirm="<?= e(__('Install this app version on the selected TVs? Each TV restarts the app after installing.')) ?>" data-confirm-safe="1"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Push update')) ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><?= e(__('Releases')) ?></div>
      <div class="table-responsive">
        <table class="table table-hc table-sm">
          <thead><tr><th><?= e(__('Version')) ?></th><th><?= e(__('Size')) ?></th><th class="d-none d-md-table-cell">SHA-256</th><th><?= e(__('Uploaded')) ?></th><th></th></tr></thead>
          <tbody>
          <?php if (!$releases): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No APK uploaded yet.')) ?></td></tr><?php endif; ?>
          <?php foreach ($releases as $r): ?>
            <tr>
              <td><strong>v<?= e($r['version_name']) ?></strong> <span class="text-muted">(<?= (int) $r['version_code'] ?>)</span><?php if ($r['notes']): ?><div class="small text-muted"><?= e($r['notes']) ?></div><?php endif; ?></td>
              <td class="small"><?= e(human_bytes($r['file_size'])) ?></td>
              <td class="d-none d-md-table-cell mono small" title="<?= e($r['sha256']) ?>"><?= e(substr((string) $r['sha256'], 0, 12)) ?>…</td>
              <td class="small"><?= e(date('d M Y', (int) strtotime($r['created_at']))) ?><div class="text-muted"><?= e($r['username'] ?? '') ?></div></td>
              <td class="text-end">
                <form method="post" data-confirm="<?= e(__('Delete APK v:v?', ['v' => $r['version_name']])) ?>">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><?= e(__('App version on each TV')) ?></div>
      <div class="table-responsive">
        <table class="table table-hc table-sm">
          <thead><tr><th><?= e(__('Room')) ?></th><th><?= e(__('App version')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Last update command')) ?></th></tr></thead>
          <tbody>
          <?php if (!$devices): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= e(__('No TVs registered yet.')) ?></td></tr><?php endif; ?>
          <?php foreach ($devices as $d): $old = $latestCode && (int) $d['app_version_code'] < $latestCode; ?>
            <tr>
              <td><a href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $d['id']])) ?>"><?= e($d['room_number'] ?? '-') ?></a></td>
              <td class="<?= $old ? 'text-warning fw-semibold' : '' ?>">v<?= e($d['app_version'] ?? '?') ?> <span class="text-muted">(<?= e($d['app_version_code'] ?? '?') ?>)</span><?php if ($old): ?> <i class="bi bi-arrow-up-circle" title="<?= e(__('Update available')) ?>"></i><?php endif; ?></td>
              <td><?= status_badge(DeviceManager::isOnline($d) ? 'online' : 'offline') ?></td>
              <td class="small"><?= cmd_status_badge($d['upd_status']) ?> <?= $d['upd_at'] ? e(time_ago($d['upd_at'])) : '' ?><?php if ($d['upd_msg']): ?><div class="text-muted"><?= e($d['upd_msg']) ?></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
