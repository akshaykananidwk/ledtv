<?php
/**
 * Auto-Update: GitHub settings, check/update with live progress, update history with
 * rollback, backups (create / download / upload / restore / delete) and system health.
 */
declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/flash.php';

$user = Auth::require('update.manage');
Csrf::check();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? '') === 'github') {
    $repo = trim((string) ($_POST['github_repo'] ?? ''));
    $branch = trim((string) ($_POST['github_branch'] ?? 'main'));
    $subdir = trim((string) ($_POST['github_subdir'] ?? ''), "/ \t");
    $errors = [];
    if ($repo !== '' && !Updater::parseRepo($repo)) {
        $errors[] = __('Repository must look like https://github.com/owner/repo or owner/repo.');
    }
    if (!preg_match('~^[A-Za-z0-9._/-]{1,100}$~', $branch)) {
        $errors[] = __('Invalid branch name.');
    }
    if ($subdir !== '' && !preg_match('~^[A-Za-z0-9._/-]{1,100}$~', $subdir) || str_contains($subdir, '..')) {
        $errors[] = __('Invalid folder name.');
    }
    $keep = max(2, min(50, (int) ($_POST['backup_keep'] ?? 10)));
    if ($errors) {
        flash_errors($errors);
    } else {
        Settings::setMany(['github_repo' => $repo, 'github_branch' => $branch, 'github_subdir' => $subdir, 'backup_keep' => (string) $keep]);
        $token = trim((string) ($_POST['github_token'] ?? ''));
        if (!empty($_POST['clear_token'])) {
            Settings::setSecret('github_token', '');
        } elseif ($token !== '') {
            Settings::setSecret('github_token', $token);
        }
        ActivityLog::add('update_settings', 'settings', null, 'GitHub: ' . $repo . ' @ ' . $branch);
        flash('success', __('Auto-update settings saved.'));
    }
    redirect(admin_url('update.php'));
}

$version = Version::current();
$history = DB::all(
    'SELECT h.*, u.username FROM update_history h LEFT JOIN users u ON u.id = h.started_by ORDER BY h.id DESC LIMIT 50'
);
$lastSuccess = DB::value("SELECT finished_at FROM update_history WHERE status = 'success' AND action = 'update' ORDER BY id DESC LIMIT 1");
$backups = Backup::all();
$hasToken = Settings::secret('github_token') !== '';
$lastCheck = json_decode((string) Settings::get('last_update_check', ''), true);
$health = HealthCheck::full(false);
$report = HealthCheck::report();
$pendingMigrations = array_map('basename', Migrator::pending());

$statusBadge = static function (string $s): string {
    $map = ['success' => 'success', 'failed' => 'danger', 'rolled_back' => 'warning', 'running' => 'info'];
    $labels = ['success' => __('Success'), 'failed' => __('Failed'), 'rolled_back' => __('Rolled back'), 'running' => __('Running')];
    return '<span class="badge text-bg-' . ($map[$s] ?? 'secondary') . '">' . e($labels[$s] ?? $s) . '</span>';
};

$pageTitle = __('Auto-Update');
$activeNav = 'update';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down"></i> <?= e(__('Auto-Update')) ?></h1>
  <a href="#backups" class="btn btn-outline-secondary btn-sm"><i class="bi bi-archive"></i> <?= e(__('Backups')) ?></a>
</div>
<?= flash_show() ?>

<div class="row g-3">
  <!-- Version -->
  <div class="col-lg-5">
    <div class="card h-100 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> <?= e(__('Installed version')) ?></div>
      <div class="card-body">
        <div class="display-6 fw-bold">v<?= e($version['version']) ?></div>
        <dl class="row small mb-0 mt-2">
          <dt class="col-5"><?= e(__('Commit')) ?></dt><dd class="col-7"><code><?= e($version['commit'] ? substr($version['commit'], 0, 10) : '—') ?></code></dd>
          <dt class="col-5"><?= e(__('Release date')) ?></dt><dd class="col-7"><?= e($version['date'] ?: '—') ?></dd>
          <dt class="col-5"><?= e(__('Last update')) ?></dt><dd class="col-7"><?= e($lastSuccess ? date('d M Y H:i', strtotime((string) $lastSuccess)) : '—') ?></dd>
          <dt class="col-5"><?= e(__('Repository')) ?></dt><dd class="col-7 text-break"><?= e(Settings::get('github_repo') ?: __('Not configured')) ?></dd>
          <dt class="col-5"><?= e(__('Branch')) ?></dt><dd class="col-7"><?= e(Settings::get('github_branch', 'main')) ?></dd>
        </dl>
        <?php if ($pendingMigrations): ?>
          <div class="alert alert-warning small mt-3 mb-0"><?= e(__('Pending database migrations')) ?>: <?= e(implode(', ', $pendingMigrations)) ?></div>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-primary" id="btnCheck" <?= Updater::configured() ? '' : 'disabled' ?>><i class="bi bi-search"></i> <?= e(__('Check for Update')) ?></button>
        <button class="btn btn-success" id="btnUpdate" <?= Updater::configured() ? '' : 'disabled' ?>><i class="bi bi-cloud-download"></i> <?= e(__('Update Now')) ?></button>
      </div>
    </div>
  </div>

  <!-- Check result / progress -->
  <div class="col-lg-7">
    <div class="card h-100 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-github"></i> <?= e(__('Latest version on GitHub')) ?></div>
      <div class="card-body" id="checkResult">
        <?php if (!Updater::configured()): ?>
          <p class="text-muted mb-0"><?= e(__('Configure your GitHub repository below to enable one-click updates.')) ?></p>
        <?php elseif ($lastCheck): ?>
          <p class="text-muted small mb-0"><?= e(__('Last checked')) ?>: <?= e(date('d M Y H:i', strtotime((string) ($lastCheck['checked_at'] ?? 'now')))) ?> — <?= e(__('click "Check for Update" to refresh.')) ?></p>
        <?php else: ?>
          <p class="text-muted mb-0"><?= e(__('Click "Check for Update" to compare with GitHub.')) ?></p>
        <?php endif; ?>
      </div>
      <div class="card-body border-top d-none" id="progressBox">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="spinner-border spinner-border-sm text-success" id="progSpin"></div>
          <strong id="progTitle"><?= e(__('Updating… do not close this page')) ?></strong>
        </div>
        <div class="progress mb-2" style="height:8px"><div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="progBar" style="width:5%"></div></div>
        <pre class="small bg-dark text-light p-2 rounded mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap" id="progLog"></pre>
      </div>
    </div>
  </div>

  <!-- Settings -->
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-gear"></i> <?= e(__('GitHub settings')) ?></div>
      <form method="post" class="card-body">
        <?= Csrf::field() ?><input type="hidden" name="form" value="github">
        <div class="mb-2">
          <label class="form-label small fw-semibold"><?= e(__('Repository URL')) ?></label>
          <input class="form-control" name="github_repo" value="<?= e(Settings::get('github_repo')) ?>" placeholder="https://github.com/owner/hotelcast">
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small fw-semibold"><?= e(__('Branch')) ?></label>
            <input class="form-control" name="github_branch" value="<?= e(Settings::get('github_branch', 'main')) ?>"></div>
          <div class="col-6"><label class="form-label small fw-semibold"><?= e(__('App folder in repo')) ?></label>
            <input class="form-control" name="github_subdir" value="<?= e(Settings::get('github_subdir', 'hotelcast')) ?>"></div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold"><?= e(__('Personal Access Token')) ?></label>
          <input class="form-control" type="password" name="github_token" autocomplete="off" placeholder="<?= e($hasToken ? __('Saved (leave empty to keep)') : 'github_pat_…') ?>">
          <div class="form-text"><?= e(__('Fine-grained token with Contents: Read-only. Stored encrypted.')) ?></div>
          <?php if ($hasToken): ?>
            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_token" value="1" id="clrTok"><label class="form-check-label small" for="clrTok"><?= e(__('Remove saved token')) ?></label></div>
          <?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold"><?= e(__('Backups to keep')) ?></label>
          <input class="form-control" type="number" min="2" max="50" name="backup_keep" value="<?= e(Settings::get('backup_keep', '10')) ?>">
        </div>
        <div class="small text-muted mb-3"><i class="bi bi-shield-lock"></i> <?= e(__('Never overwritten by updates')) ?>: <code>.env</code>, <code>config.php</code>, <code>uploads/</code>, <code>storage/</code>, <code>backups/</code>, <code>logs/</code></div>
        <button class="btn btn-primary"><i class="bi bi-save"></i> <?= e(__('Save')) ?></button>
      </form>
    </div>
  </div>

  <!-- Health -->
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-heart-pulse"></i> <?= e(__('System health')) ?></span>
        <button class="btn btn-sm btn-outline-primary" id="btnHealth"><i class="bi bi-arrow-repeat"></i> <?= e(__('Run health check')) ?></button>
      </div>
      <div class="card-body">
        <ul class="list-unstyled small mb-3" id="healthList">
          <?php foreach ($health['checks'] as $name => $c): ?>
            <li><i class="bi <?= $c['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i> <strong><?= e($name) ?></strong>: <?= e($c['message']) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="row small g-1">
          <?php foreach ($report as $k => $v): if (is_array($v)) { continue; } ?>
            <div class="col-6 col-md-4"><span class="text-muted"><?= e(str_replace('_', ' ', $k)) ?>:</span> <?= e($v) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- History -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> <?= e(__('Update history')) ?></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr>
            <th>#</th><th><?= e(__('Date')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('Version')) ?></th><th><?= e(__('Commit')) ?></th>
            <th><?= e(__('Status')) ?></th><th><?= e(__('Backup')) ?></th><th><?= e(__('By')) ?></th><th class="text-end"></th>
          </tr></thead>
          <tbody>
          <?php if (!$history): ?>
            <tr><td colspan="9" class="text-center text-muted py-4"><?= e(__('No updates yet.')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($history as $h): $hasBackup = $h['backup_file'] && is_file(Backup::dir() . '/' . basename((string) $h['backup_file'])); ?>
            <tr>
              <td><?= (int) $h['id'] ?></td>
              <td class="text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $h['started_at']))) ?></td>
              <td><?= e($h['action'] === 'rollback' ? __('Rollback') : __('Update')) ?></td>
              <td class="text-nowrap"><?= e(($h['from_version'] ?? '?') . ' → ' . ($h['to_version'] ?? '?')) ?></td>
              <td><code><?= e($h['commit_hash'] ? substr((string) $h['commit_hash'], 0, 7) : '—') ?></code></td>
              <td><?= $statusBadge((string) $h['status']) ?></td>
              <td class="small text-break"><?= e($h['backup_file'] ?: '—') ?></td>
              <td><?= e($h['username'] ?? '—') ?></td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-secondary" data-log="<?= (int) $h['id'] ?>"><i class="bi bi-file-text"></i> <?= e(__('Log')) ?></button>
                <?php if ($hasBackup): ?>
                  <button class="btn btn-sm btn-outline-warning" data-rollback="<?= (int) $h['id'] ?>" data-label="<?= e($h['backup_file']) ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= e(__('Rollback')) ?></button>
                <?php endif; ?>
                <template id="log-<?= (int) $h['id'] ?>"><?= e((string) $h['log']) ?></template>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted"><?= e(__('Rollback restores the files and database saved just before that update or rollback.')) ?></div>
    </div>
  </div>

  <!-- Backups -->
  <div class="col-12" id="backups">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span><i class="bi bi-archive"></i> <?= e(__('Backups')) ?> <span class="badge text-bg-secondary"><?= count($backups) ?></span></span>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" id="incUploads"><label class="form-check-label small" for="incUploads"><?= e(__('Include media uploads')) ?></label></div>
          <button class="btn btn-sm btn-primary" id="btnBackup"><i class="bi bi-plus-circle"></i> <?= e(__('Create backup now')) ?></button>
          <label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-upload"></i> <?= e(__('Upload backup')) ?><input type="file" accept=".zip" id="uploadBackup" hidden></label>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr><th><?= e(__('File')) ?></th><th><?= e(__('Created')) ?></th><th><?= e(__('Version')) ?></th><th><?= e(__('Note')) ?></th><th><?= e(__('Size')) ?></th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php if (!$backups): ?>
            <tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No backups yet.')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($backups as $b): ?>
            <tr>
              <td class="small text-break"><?= e($b['name']) ?></td>
              <td class="text-nowrap small"><?= e(date('d M Y H:i', strtotime($b['created']))) ?></td>
              <td><?= e($b['meta']['version']['version'] ?? '?') ?></td>
              <td class="small"><?= e($b['meta']['label'] ?? '') ?><?= !empty($b['meta']['includes_uploads']) ? ' · ' . e(__('with media')) : '' ?></td>
              <td class="text-nowrap small"><?= e(human_bytes($b['size'])) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(admin_url('ajax_update.php', ['action' => 'update_backup_download', 'name' => $b['name'], 'token' => Csrf::token()])) ?>"><i class="bi bi-download"></i></a>
                <button class="btn btn-sm btn-outline-warning" data-restore="<?= e($b['name']) ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= e(__('Restore')) ?></button>
                <button class="btn btn-sm btn-outline-danger" data-delete="<?= e($b['name']) ?>"><i class="bi bi-trash"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted"><?= e(__('Backups contain all program files and the full database. Media uploads are included only when selected. Store downloaded backups somewhere safe.')) ?></div>
    </div>
  </div>
</div>

<!-- Log modal -->
<div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><?= e(__('Update log')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><pre class="small bg-dark text-light p-2 rounded mb-0" style="white-space:pre-wrap" id="logBody"></pre></div>
</div></div></div>

<!-- Restore modal -->
<div class="modal fade" id="restoreModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><?= e(__('Restore backup')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <p><?= e(__('Restore')) ?> <strong id="restoreName"></strong>?</p>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="restoreDb" checked><label class="form-check-label" for="restoreDb"><?= e(__('Also restore the database (rooms, content, settings, users)')) ?></label></div>
    <div class="alert alert-warning small mt-3 mb-0"><?= e(__('A safety backup of the current state is created first, so this can be undone.')) ?></div>
  </div>
  <div class="modal-footer"><button class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button><button class="btn btn-warning" id="restoreGo"><?= e(__('Restore now')) ?></button></div>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var CSRF = <?= json_embed(Csrf::token()) ?>;
  var URL_ = <?= json_embed(admin_url('ajax_update.php')) ?>;
  var T = <?= json_embed([
      'confirmUpdate' => __('Start the update now? A full backup is made first and everything is rolled back automatically if something fails.'),
      'upToDate' => __('You are up to date.'),
      'available' => __('Update available'),
      'changedFiles' => __('changed files'),
      'done' => __('Update complete!'),
      'failed' => __('Update failed'),
      'rolledBack' => __('Previous version restored automatically.'),
      'checking' => __('Checking GitHub…'),
      'creating' => __('Creating backup… this can take a minute.'),
      'deleteQ' => __('Delete this backup permanently?'),
      'rollbackQ' => __('Roll back to the state saved in this backup? Files and database will be restored.'),
      'error' => __('Error'),
      'unknown' => __('unknown'),
      'commits' => __('Changes'),
  ]) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function post(action, body, isForm) {
    var opts = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } };
    if (isForm) { opts.body = body; } else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body || {}); }
    return fetch(URL_ + '?action=' + action, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: { message: 'HTTP ' + r.status } }; });
    });
  }
  function toast(msg, type) { if (window.HC && HC.toast) { HC.toast(msg, type); } else { alert(msg); } }
  function confirmBox(msg) { return (window.HC && HC.confirm) ? HC.confirm(msg) : Promise.resolve(window.confirm(msg)); }

  var checkBox = document.getElementById('checkResult');
  function renderCheck(d) {
    var h = '';
    if (d.update_available) {
      h += '<div class="alert alert-success py-2 mb-2"><i class="bi bi-stars"></i> <strong>' + esc(T.available) + ': v' + esc(d.latest.version) + '</strong></div>';
    } else {
      h += '<div class="alert alert-info py-2 mb-2"><i class="bi bi-check2-circle"></i> ' + esc(T.upToDate) + '</div>';
    }
    h += '<dl class="row small mb-2">'
      + '<dt class="col-4">Commit</dt><dd class="col-8"><code>' + esc(d.latest.short) + '</code> ' + (d.latest.url ? '<a href="' + esc(d.latest.url) + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>' : '') + '</dd>'
      + '<dt class="col-4">Message</dt><dd class="col-8">' + esc(d.latest.message).replace(/\n/g, '<br>') + '</dd>'
      + '<dt class="col-4">Date</dt><dd class="col-8">' + esc(d.latest.date ? new Date(d.latest.date).toLocaleString() : '') + ' · ' + esc(d.latest.author) + '</dd>'
      + '<dt class="col-4">' + esc(T.changedFiles) + '</dt><dd class="col-8">' + (d.changed_files === null ? esc(T.unknown) : esc(d.changed_files)) + '</dd></dl>';
    if (d.commits && d.commits.length) {
      h += '<div class="small fw-semibold">' + esc(T.commits) + '</div><ul class="small mb-2">';
      d.commits.forEach(function (c) { h += '<li><code>' + esc(c.sha) + '</code> ' + esc(c.message) + ' <span class="text-muted">— ' + esc(c.author) + '</span></li>'; });
      h += '</ul>';
    }
    if (d.files && d.files.length) {
      h += '<details class="small"><summary>' + esc(d.files.length) + ' ' + esc(T.changedFiles) + '</summary><ul class="mb-0">';
      d.files.forEach(function (f) { h += '<li><span class="badge text-bg-light border">' + esc(f.status) + '</span> ' + esc(f.file) + '</li>'; });
      h += '</ul></details>';
    }
    checkBox.innerHTML = h;
  }

  var btnCheck = document.getElementById('btnCheck');
  btnCheck && btnCheck.addEventListener('click', function () {
    btnCheck.disabled = true;
    checkBox.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm"></span> ' + esc(T.checking) + '</div>';
    post('update_check').then(function (j) {
      if (j.ok) { renderCheck(j.data); } else { checkBox.innerHTML = '<div class="alert alert-danger mb-0"></div>'; checkBox.firstChild.textContent = (j.error && j.error.message) || T.error; }
    }).finally(function () { btnCheck.disabled = false; });
  });

  // ---- Update with live progress
  var box = document.getElementById('progressBox'), logEl = document.getElementById('progLog'), bar = document.getElementById('progBar');
  var pollTimer = null;
  function setProgress(text) {
    logEl.textContent = text || '';
    logEl.scrollTop = logEl.scrollHeight;
    var m = (text || '').match(/Step (\d)\/7/g);
    if (m) { var n = parseInt(m[m.length - 1].replace(/\D+(\d)\/7/, '$1'), 10); bar.style.width = Math.min(95, 8 + n * 12.5) + '%'; }
  }
  function poll() {
    fetch(URL_ + '?action=update_status', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (j) { if (j.ok && j.data.row && j.data.row.status === 'running') { setProgress(j.data.row.log); } }).catch(function () {});
  }
  var btnUpdate = document.getElementById('btnUpdate');
  btnUpdate && btnUpdate.addEventListener('click', function () {
    confirmBox(T.confirmUpdate).then(function (yes) {
      if (!yes) { return; }
      btnUpdate.disabled = true; btnCheck.disabled = true;
      box.classList.remove('d-none'); setProgress(''); bar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success'; bar.style.width = '5%';
      document.getElementById('progSpin').classList.remove('d-none');
      pollTimer = setInterval(poll, 1500);
      window.onbeforeunload = function () { return 'Update in progress'; };
      post('update_run').then(function (j) {
        clearInterval(pollTimer); window.onbeforeunload = null;
        var d = j.data || {};
        setProgress((d.log || []).map(function (l) { return '[' + l.time + '] ' + l.level.toUpperCase() + ' ' + l.message; }).join('\n') || (j.error && j.error.message) || '');
        document.getElementById('progSpin').classList.add('d-none');
        bar.classList.remove('progress-bar-animated', 'progress-bar-striped'); bar.style.width = '100%';
        if (j.ok) {
          bar.classList.replace('bg-success', 'bg-success');
          document.getElementById('progTitle').textContent = '✅ ' + T.done + ' v' + (d.version || '');
          toast(T.done, 'success'); setTimeout(function () { location.reload(); }, 4000);
        } else {
          bar.classList.replace('bg-success', 'bg-danger');
          document.getElementById('progTitle').textContent = '❌ ' + T.failed + ': ' + (d.error || (j.error && j.error.message) || '') + (d.status === 'rolled_back' ? ' — ' + T.rolledBack : '');
          btnUpdate.disabled = false; btnCheck.disabled = false;
        }
      }).catch(function (e) {
        clearInterval(pollTimer); window.onbeforeunload = null;
        document.getElementById('progTitle').textContent = T.error + ': ' + e;
        btnUpdate.disabled = false; btnCheck.disabled = false;
      });
    });
  });

  // ---- Health
  var btnHealth = document.getElementById('btnHealth');
  btnHealth.addEventListener('click', function () {
    btnHealth.disabled = true;
    post('update_health').then(function (j) {
      if (!j.ok) { toast((j.error && j.error.message) || T.error, 'danger'); return; }
      var ul = document.getElementById('healthList'); ul.innerHTML = '';
      Object.keys(j.data.checks).forEach(function (k) {
        var c = j.data.checks[k], li = document.createElement('li');
        li.innerHTML = '<i class="bi ' + (c.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger') + '"></i> <strong>' + esc(k) + '</strong>: ' + esc(c.message);
        ul.appendChild(li);
      });
    }).finally(function () { btnHealth.disabled = false; });
  });

  // ---- Logs, rollback, backups
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-log],[data-rollback],[data-delete],[data-restore]');
    if (!t) { return; }
    if (t.dataset.log) {
      document.getElementById('logBody').textContent = document.getElementById('log-' + t.dataset.log).content.textContent || '—';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('logModal')).show();
    } else if (t.dataset.rollback) {
      confirmBox(T.rollbackQ + '\n\n' + t.dataset.label).then(function (yes) {
        if (!yes) { return; }
        t.disabled = true;
        post('rollback', { history_id: parseInt(t.dataset.rollback, 10) }).then(function (j) {
          toast(j.ok ? '✔ Rollback complete' : ((j.data && j.data.error) || (j.error && j.error.message) || T.error), j.ok ? 'success' : 'danger');
          setTimeout(function () { location.reload(); }, 1500);
        });
      });
    } else if (t.dataset.delete) {
      confirmBox(T.deleteQ).then(function (yes) {
        if (!yes) { return; }
        post('update_backup_delete', { name: t.dataset.delete }).then(function (j) { j.ok ? t.closest('tr').remove() : toast(j.error.message, 'danger'); });
      });
    } else if (t.dataset.restore) {
      document.getElementById('restoreName').textContent = t.dataset.restore;
      document.getElementById('restoreGo').dataset.name = t.dataset.restore;
      bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreModal')).show();
    }
  });
  document.getElementById('restoreGo').addEventListener('click', function () {
    var b = this; b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    post('rollback', { backup: b.dataset.name, restore_db: document.getElementById('restoreDb').checked }).then(function (j) {
      toast(j.ok ? '✔ Restored' : ((j.data && j.data.error) || (j.error && j.error.message) || T.error), j.ok ? 'success' : 'danger');
      setTimeout(function () { location.reload(); }, 1500);
    });
  });
  document.getElementById('btnBackup').addEventListener('click', function () {
    var b = this; b.disabled = true; toast(T.creating, 'info');
    post('update_backup_create', { include_uploads: document.getElementById('incUploads').checked }).then(function (j) {
      j.ok ? location.reload() : (toast((j.error && j.error.message) || T.error, 'danger'), b.disabled = false);
    });
  });
  document.getElementById('uploadBackup').addEventListener('change', function () {
    if (!this.files.length) { return; }
    var fd = new FormData(); fd.append('backup', this.files[0]);
    post('update_backup_upload', fd, true).then(function (j) { j.ok ? location.reload() : toast((j.error && j.error.message) || T.error, 'danger'); });
  });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
