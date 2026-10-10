<?php
/**
 * Super Admin console → APK Manager (2.7, docs/modules/apk_manager.md): upload the TV app once for the whole
 * platform, mark it as a required (forced) update, roll it out to all or selected customers, see downloads and
 * how many TVs run each version, and send "Update all TVs now". TVs check the latest required version on every
 * app start (GET api/device/app-version) and cannot play content until they run it. Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
ActivityLog::$platformScope = true;
if (post_too_large()) {
    $msg = __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]);
    if (Auth::isAjax()) {
        ajax_error($msg, 413, 'TOO_LARGE');
    }
    flash('danger', $msg);
    redirect(admin_url('platform_apk.php'));
}
Csrf::check();

function papk_done(bool $ok, string $msg): never
{
    if (Auth::isAjax()) {
        if ($ok) {
            flash('success', $msg);
            ajax_ok(['redirect' => admin_url('platform_apk.php')]);
        }
        ajax_error($msg, 422, 'VALIDATION_ERROR');
    }
    flash($ok ? 'success' : 'danger', $msg);
    redirect(admin_url('platform_apk.php'));
}

/** Options of the upload / edit forms. */
function papk_options(): array
{
    return [
        'required' => !empty($_POST['required']),
        'notes' => req_str('notes', $_POST, 5000),
        'rollout' => req_str('rollout', $_POST, 10),
        'hotels' => is_array($_POST['hotels'] ?? null) ? $_POST['hotels'] : [],
    ];
}

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    try {
        switch ($op) {
            case 'upload':
                if (!isset($_FILES['apk']) || !is_array($_FILES['apk'])) {
                    papk_done(false, __('No file was selected.'));
                }
                $id = AppReleases::upload($_FILES['apk'], papk_options(), Auth::id());
                $r = DB::one('SELECT version_name, version_code, is_required FROM apk_releases WHERE id = :id', ['id' => $id]);
                papk_done(true, (int) $r['is_required']
                    ? __('App v:v uploaded as a required update. Every TV installs it the next time the app starts; use "Update all TVs now" to update running TVs immediately.', ['v' => $r['version_name']])
                    : __('App v:v uploaded. TVs can install it with "Update all TVs now".', ['v' => $r['version_name']]));
                // no break (papk_done exits)
            case 'save':
                AppReleases::saveOptions(req_int('id', $_POST), papk_options());
                papk_done(true, __('Release saved.'));
                // no break
            case 'delete':
                $r = AppReleases::delete(req_int('id', $_POST));
                papk_done(true, __('App v:v deleted.', ['v' => $r['version_name']]));
                // no break
            case 'push_all':
                $res = AppReleases::pushToAll(Auth::id());
                papk_done(true, __('Update sent to :n TV(s) of :c customer(s). :u TV(s) already up to date.', ['n' => $res['tvs'], 'c' => $res['customers'], 'u' => $res['up_to_date']])
                    . ($res['no_release'] ? ' ' . __(':n TV(s) have no app release.', ['n' => $res['no_release']]) : ''));
                // no break
            case 'signer':
                $v = strtolower(trim(req_str('signer', $_POST, 70)));
                if ($v !== '' && $v !== '-' && !preg_match('/^[0-9a-f]{64}$/', $v)) {
                    papk_done(false, __('Enter the 64-character SHA-256 of the signing certificate, or "-" to switch the check off.'));
                }
                Settings::setPlatform('platform_apk_signer', $v === '' ? AppReleases::DEFAULT_SIGNER : $v);
                ActivityLog::add('apk_signer', 'apk', null, 'Expected APK signer: ' . ($v === '' ? 'default' : $v), null);
                papk_done(true, __('Signing certificate saved.'));
        }
    } catch (RuntimeException $e) {
        papk_done(false, $e->getMessage());
    }
    redirect(admin_url('platform_apk.php'));
}

$available = AppReleases::available();
$releases = AppReleases::platformReleases();
$latest = $releases[0] ?? null;
$fleet = AppReleases::fleetStats();
$installed = AppReleases::installedCounts();
krsort($installed);
$customers = DB::all('SELECT id, name FROM hotels ORDER BY name, id');
$signer = AppReleases::expectedSigner();
$onlineAndroid = (int) DB::value("SELECT COUNT(*) FROM devices d WHERE d.is_revoked = 0 AND d.status = 'online' AND d.room_id IS NOT NULL" . DeviceManager::notWebSql('d.platform'));

/** Customer picker (multi-select) for the rollout. */
function papk_rollout(string $id, array $customers, string $rollout = 'all', array $selected = []): string
{
    ob_start(); ?>
  <fieldset class="mb-2">
    <legend class="form-label fs-6 mb-1"><?= e(__('Roll-out')) ?></legend>
    <div class="form-check"><input class="form-check-input" type="radio" name="rollout" value="all" id="<?= e($id) ?>_all"<?= $rollout !== 'selected' ? ' checked' : '' ?>>
      <label class="form-check-label" for="<?= e($id) ?>_all"><?= e(__('All customers')) ?></label></div>
    <div class="form-check"><input class="form-check-input" type="radio" name="rollout" value="selected" id="<?= e($id) ?>_sel"<?= $rollout === 'selected' ? ' checked' : '' ?>>
      <label class="form-check-label" for="<?= e($id) ?>_sel"><?= e(__('Selected customers only')) ?></label></div>
    <select class="form-select form-select-sm mt-1" name="hotels[]" multiple size="<?= min(6, max(2, count($customers))) ?>" aria-label="<?= e(__('Customers')) ?>">
      <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= isset($selected[(int) $c['id']]) ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="form-text"><?= e(__('Used only with "Selected customers only" (Ctrl/Cmd-click to select several).')) ?></div>
  </fieldset>
    <?php return (string) ob_get_clean();
}

$pageTitle = __('APK Manager');
$activeNav = 'platform_apk';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-android2"></i> <?= e(__('APK Manager')) ?></h1>
    <p class="lead-sm"><?= e(__('Upload the TV app once for every customer. A required update is installed by each TV the next time the app starts — the old version cannot be used.')) ?></p></div>
  <?php if ($latest): ?>
  <form method="post" class="d-flex gap-2" data-confirm="<?= e(__('Send the update command to all :n online TVs now? Each TV downloads the app and restarts it after installing.', ['n' => $onlineAndroid])) ?>" data-confirm-safe="1">
    <?= Csrf::field() ?><input type="hidden" name="op" value="push_all">
    <button class="btn btn-success" id="papkPushAll"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Update all TVs now')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="alert alert-warning"><?= e(__('Run the database update first (migration 034).')) ?></div>
<?php endif; ?>

<div class="kpi-grid mb-3" data-apk-kpis>
  <?= panel_kpi(__('Latest app'), $latest ? 'v' . $latest['version_name'] : '—', 'bi-box-seam', '', $latest ? __('code :c', ['c' => (int) $latest['version_code']]) . ((int) $latest['is_required'] ? ' · ' . __('required') : '') : __('No APK uploaded yet.'), '', 'latest') ?>
  <?= panel_kpi(__('TVs on latest version'), $fleet['on_latest'], 'bi-check-circle', '', __('of :n Android TVs', ['n' => $fleet['android']]), 'success', 'on_latest') ?>
  <?= panel_kpi(__('Outdated app'), $fleet['outdated'], 'bi-arrow-up-circle', admin_url('platform_screens.php', ['update' => 1]), '', $fleet['outdated'] ? 'warning' : '', 'outdated') ?>
  <?= panel_kpi(__('Online Android TVs'), $onlineAndroid, 'bi-wifi', admin_url('platform_screens.php', ['status' => 'online', 'platform' => 'android']), '', '', 'online') ?>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-upload"></i> <?= e(__('Upload new app version')) ?></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="js-upload" data-progress="#papkProgress" action="<?= e(admin_url('platform_apk.php')) ?>" id="papkUpload">
          <?= Csrf::field() ?><input type="hidden" name="op" value="upload">
          <div class="mb-2">
            <label class="form-label" for="papkFile"><?= e(__('APK file')) ?> *</label>
            <input class="form-control" type="file" id="papkFile" name="apk" accept=".apk,application/vnd.android.package-archive" required>
            <div class="form-text"><?= e(__('Version name and code are read from the APK (package :p). Limit: :s.', ['p' => AppReleases::PACKAGE, 's' => human_bytes(min(upload_limit(), AppReleases::MAX_MB * 1024 * 1024))])) ?></div>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" name="required" value="1" id="papkReq" checked>
            <label class="form-check-label" for="papkReq"><strong><?= e(__('Required update (force)')) ?></strong></label>
            <div class="form-text"><?= e(__('TVs must install it before the app can be used again. Running TVs keep playing until the app is restarted.')) ?></div>
          </div>
          <?= papk_rollout('papkNew', $customers) ?>
          <div class="mb-3"><label class="form-label" for="papkNotes"><?= e(__('Release notes')) ?></label><textarea class="form-control" id="papkNotes" name="notes" rows="2" maxlength="5000"></textarea></div>
          <div id="papkProgress" class="mb-2" hidden>
            <div class="small mb-1" data-progress-label><?= e(__('Uploading…')) ?></div>
            <div class="progress" style="height:20px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div></div>
          </div>
          <button class="btn btn-primary"<?= $available ? '' : ' disabled' ?>><i class="bi bi-upload"></i> <?= e(__('Upload')) ?></button>
        </form>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-shield-check"></i> <?= e(__('Signing certificate')) ?></div>
      <div class="card-body small">
        <p class="mb-2"><?= e(__('Uploads must be signed with this key (SHA-256 of the certificate). TVs refuse an update signed with another key.')) ?></p>
        <code class="d-block text-break mb-2" id="papkSigner"><?= e($signer !== '' ? $signer : __('Check switched off')) ?></code>
        <details><summary><?= e(__('Change')) ?></summary>
          <form method="post" class="mt-2 d-flex gap-2">
            <?= Csrf::field() ?><input type="hidden" name="op" value="signer">
            <input class="form-control form-control-sm mono" name="signer" maxlength="70" placeholder="<?= e(AppReleases::DEFAULT_SIGNER) ?>" aria-label="<?= e(__('Signing certificate')) ?>">
            <button class="btn btn-sm btn-outline-primary"><?= e(__('Save')) ?></button>
          </form>
          <div class="form-text"><?= e(__('Empty = default release key, "-" = no check.')) ?></div>
        </details>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-collection"></i> <?= e(__('Releases')) ?></div>
      <div class="table-responsive">
        <table class="table table-hc table-sm align-middle mb-0" id="papkReleases">
          <thead><tr><th><?= e(__('Version')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Size')) ?></th><th><?= e(__('Roll-out')) ?></th>
            <th class="text-end"><?= e(__('Downloads')) ?></th><th class="text-end"><?= e(__('Installed')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Uploaded')) ?></th><th></th></tr></thead>
          <tbody>
          <?php if (!$releases): ?><tr><td colspan="7"><?= panel_empty('bi-android2', __('No APK uploaded yet.'), __('Build the app (./gradlew assembleRelease) and upload the signed APK here.')) ?></td></tr><?php endif; ?>
          <?php foreach ($releases as $r): $rid = (int) $r['id']; ?>
            <tr data-release="<?= (int) $r['version_code'] ?>">
              <td><strong>v<?= e($r['version_name']) ?></strong> <span class="text-muted">(<?= (int) $r['version_code'] ?>)</span>
                <?php if ($r['is_latest']): ?><span class="badge text-bg-success"><?= e(__('Latest')) ?></span><?php endif; ?>
                <?php if ((int) $r['is_required']): ?><span class="badge text-bg-warning"><?= e(__('Required')) ?></span><?php else: ?><span class="badge text-bg-light border"><?= e(__('Optional')) ?></span><?php endif; ?>
                <?php if ($r['notes']): ?><div class="small text-muted"><?= e($r['notes']) ?></div><?php endif; ?>
                <div class="small text-muted mono d-none d-lg-block" title="SHA-256">SHA-256 <?= e(substr((string) $r['sha256'], 0, 16)) ?>…</div></td>
              <td class="d-none d-md-table-cell small"><?= e(human_bytes($r['file_size'])) ?></td>
              <td class="small"><?= $r['rollout'] === 'selected' ? e(__(':n selected customer(s)', ['n' => count($r['customers'])])) . '<div class="text-muted">' . e(implode(', ', array_slice($r['customers'], 0, 3)) . (count($r['customers']) > 3 ? ' …' : '')) . '</div>' : e(__('All customers')) ?></td>
              <td class="text-end"><?= (int) $r['downloads'] ?></td>
              <td class="text-end"><?= (int) $r['installed'] ?></td>
              <td class="d-none d-md-table-cell small"><?= e(date('d M Y', (int) strtotime((string) $r['created_at']))) ?><div class="text-muted"><?= e($r['username'] ?? '') ?></div></td>
              <td class="text-end text-nowrap">
                <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#papkEdit<?= $rid ?>" title="<?= e(__('Options')) ?>" aria-label="<?= e(__('Options')) ?>"><i class="bi bi-sliders"></i></button>
                <?php if (!$r['is_latest']): ?>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Delete app v:v?', ['v' => $r['version_name']])) ?>">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= $rid ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>" aria-label="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
                </form>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="<?= e(__('The latest release cannot be deleted. Upload a newer version first.')) ?>"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </td>
            </tr>
            <tr class="collapse" id="papkEdit<?= $rid ?>"><td colspan="7" class="bg-light">
              <form method="post" class="row g-3 p-2">
                <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= $rid ?>">
                <div class="col-md-4">
                  <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="required" value="1" id="papkReq<?= $rid ?>"<?= (int) $r['is_required'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="papkReq<?= $rid ?>"><?= e(__('Required update (force)')) ?></label></div>
                </div>
                <div class="col-md-4"><?= papk_rollout('papk' . $rid, $customers, (string) $r['rollout'], $r['customers']) ?></div>
                <div class="col-md-4"><label class="form-label" for="papkN<?= $rid ?>"><?= e(__('Release notes')) ?></label>
                  <textarea class="form-control form-control-sm" id="papkN<?= $rid ?>" name="notes" rows="3" maxlength="5000"><?= e($r['notes'] ?? '') ?></textarea>
                  <button class="btn btn-sm btn-primary mt-2"><?= e(__('Save')) ?></button></div>
              </form>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted"><?= e(__('A customer\'s own APK (customer APK Manager) is used instead only when its version code is higher. Web players never install an APK.')) ?></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-bar-chart"></i> <?= e(__('App version on TVs')) ?></div>
      <div class="card-body">
        <?php if (!$installed): ?><div class="text-muted small"><?= e(__('No TVs registered yet.')) ?></div><?php endif; ?>
        <?php $maxN = max([1, ...array_values($installed)]); foreach ($installed as $code => $n): $isLatest = $latest && $code === (int) $latest['version_code']; $old = $latest && $code < (int) $latest['version_code']; ?>
          <div class="d-flex align-items-center gap-2 mb-1 small">
            <span style="min-width:5.5rem"><?= e(__('code :c', ['c' => $code])) ?></span>
            <div class="progress flex-grow-1" style="height:14px" role="progressbar" aria-label="<?= e(__('code :c', ['c' => $code])) ?>" aria-valuenow="<?= (int) $n ?>" aria-valuemin="0" aria-valuemax="<?= (int) $maxN ?>">
              <div class="progress-bar <?= $isLatest ? 'bg-success' : ($old ? 'bg-warning' : '') ?>" style="width:<?= round($n / $maxN * 100) ?>%"></div></div>
            <span class="text-end" style="min-width:3rem"><?= (int) $n ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
