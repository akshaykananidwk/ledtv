<?php
declare(strict_types=1);
/**
 * Birthday / anniversary wall (#29): people shown by the "Birthday & anniversary wall" display app
 * (core/Apps/CelebrationsApp.php). Name, type (birthday / wedding anniversary / work anniversary),
 * day + month (year optional), group (department / class), photo (Uploader), consent, active switch,
 * and a CSV import (name,date,type,group). Only people with consent are ever shown on TVs.
 * Personal data → permission celebrations.manage (manager+).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('celebrations.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('celebrations.php'));
}
Csrf::check();

$formRow = null;
$formErrors = [];
$importErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'import') {
        $csv = is_string($_POST['csv_text'] ?? null) ? (string) $_POST['csv_text'] : '';
        $f = $_FILES['csv'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])) {
            if ((int) $f['size'] > 1024 * 1024) {
                flash('danger', __('The CSV file is too large (max. 1 MB).'));
                redirect(admin_url('celebrations.php'));
            }
            $csv = (string) file_get_contents((string) $f['tmp_name']);
        }
        if (trim($csv) === '') {
            flash('warning', __('Choose a CSV file or paste the lines.'));
            redirect(admin_url('celebrations.php'));
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            flash('danger', __('Save the CSV file as UTF-8 and try again.'));
            redirect(admin_url('celebrations.php'));
        }
        [$n, $importErrors] = Celebrations::importCsv($csv, !empty($_POST['consent_all']));
        ActivityLog::add('celebration_import', 'celebration', null, $n . ' imported');
        flash($n ? 'success' : 'warning', __(':n people imported.', ['n' => $n]) . ($importErrors ? ' ' . implode(' ', array_slice($importErrors, 0, 5)) : ''));
        redirect(admin_url('celebrations.php'));
    }
    $id = req_int('id', $_POST);
    $existing = $id ? Celebrations::find($id) : null; // another hotel's id → 404
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        flash('warning', __('Entry not found.'));
        redirect(admin_url('celebrations.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Celebrations::validate($_POST);
            $up = null;
            if (!$formErrors) {
                [$up, $formErrors] = BusinessApps::upload('photo');
            }
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'photo_path' => $existing['photo_path'] ?? null, 'thumb_path' => $existing['thumb_path'] ?? null] + $data + Celebrations::DEFAULTS;
                break;
            }
            $old = null;
            if ($up) {
                $data['photo_path'] = $up['path'];
                $data['thumb_path'] = $up['thumb'];
                $old = $existing;
            } elseif ($existing && !empty($_POST['remove_photo'])) {
                $data['photo_path'] = null;
                $data['thumb_path'] = null;
                $old = $existing;
            }
            $newId = Celebrations::save($existing ? $id : null, $data);
            if ($old && $old['photo_path']) {
                Uploader::delete($old['photo_path'], $old['thumb_path']);
            }
            ActivityLog::add($existing ? 'celebration_update' : 'celebration_create', 'celebration', $newId, mb_substr($data['name'], 0, 120));
            flash('success', __('":t" saved.', ['t' => $data['name']]));
            redirect(admin_url('celebrations.php'));

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('celebrations', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('celebration_toggle', 'celebration', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['name'], 0, 100));
            flash('success', $on ? __('":t" is shown again.', ['t' => $existing['name']]) : __('":t" is hidden.', ['t' => $existing['name']]));
            redirect(admin_url('celebrations.php'));

        case 'delete':
            Celebrations::delete($id);
            ActivityLog::add('celebration_delete', 'celebration', $id, mb_substr((string) $existing['name'], 0, 120));
            flash('success', __('":t" deleted.', ['t' => $existing['name']]));
            redirect(admin_url('celebrations.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('celebrations.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'celebrations';
$pageTitle = __('Birthdays & anniversaries');
$typeOpts = [];
foreach (Celebrations::TYPES as $t) {
    $typeOpts[$t] = Celebrations::typeLabel($t);
}

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = Celebrations::DEFAULTS;
        if ($action === 'edit') {
            $formRow = Celebrations::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Entry not found.'));
                redirect(admin_url('celebrations.php'));
            }
        }
    }
    $c = $formRow;
    $isEdit = (int) $c['id'] > 0;
    $pageTitle = $isEdit ? __('Edit entry') : __('New entry');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-balloon-heart"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Shown on the birthday & anniversary wall on its day — only with the person\'s consent.')) ?></p></div>
      <a href="<?= e(admin_url('celebrations.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('celebrations.php')) ?>">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="card"><div class="card-body row g-3">
        <div class="col-md-8">
          <label class="form-label" for="c_name"><?= e(__('Name')) ?> *</label>
          <input class="form-control form-control-lg" id="c_name" name="name" value="<?= e($c['name']) ?>" required maxlength="120">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="c_type"><?= e(__('Type')) ?></label>
          <select class="form-select form-select-lg" id="c_type" name="type"><?php foreach ($typeOpts as $k => $l): ?><option value="<?= e($k) ?>"<?= (string) $c['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label" for="c_day"><?= e(__('Day')) ?> *</label>
          <input class="form-control form-control-lg" type="number" min="1" max="31" id="c_day" name="day" value="<?= (int) $c['day'] ?: '' ?>" required>
        </div>
        <div class="col-8 col-md-4">
          <label class="form-label" for="c_month"><?= e(__('Month')) ?> *</label>
          <select class="form-select form-select-lg" id="c_month" name="month" required><option value=""></option>
            <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= (int) $c['month'] === $m ? ' selected' : '' ?>><?= e(__(date('F', mktime(12, 0, 0, $m, 1, 2000)))) ?></option><?php endfor; ?></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="c_year"><?= e(__('Year (optional)')) ?></label>
          <input class="form-control form-control-lg" inputmode="numeric" maxlength="4" id="c_year" name="year" value="<?= e((string) ($c['year'] ?? '')) ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="c_group"><?= e(__('Group (optional)')) ?></label>
          <input class="form-control form-control-lg" id="c_group" name="group_label" value="<?= e($c['group_label']) ?>" maxlength="80" placeholder="<?= e(__('e.g. Kitchen, Class 5-B')) ?>">
        </div>
        <div class="col-12">
          <label class="form-label" for="c_photo"><?= e(__('Photo (optional)')) ?></label>
          <input class="form-control" type="file" id="c_photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
          <?php if (!empty($c['photo_path'])): ?>
            <div class="d-flex align-items-center gap-2 mt-2">
              <img src="<?= e(media_url($c['thumb_path'] ?: $c['photo_path'])) ?>" alt="" class="rounded-circle border" style="width:72px;height:72px;object-fit:cover">
              <div class="form-check"><input class="form-check-input" type="checkbox" id="c_rm" name="remove_photo" value="1"><label class="form-check-label" for="c_rm"><?= e(__('Remove photo')) ?></label></div>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-12"><div class="form-check form-switch">
          <input type="hidden" name="consent" value="0">
          <input class="form-check-input" type="checkbox" role="switch" id="c_consent" name="consent" value="1"<?= (int) $c['consent'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="c_consent"><strong><?= e(__('This person agreed to be shown on the TV screens')) ?></strong></label>
          <div class="form-text"><?= e(__('Without consent the entry is never shown.')) ?></div>
        </div></div>
        <div class="col-12"><div class="form-check form-switch">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" role="switch" id="c_active" name="is_active" value="1"<?= (int) $c['is_active'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="c_active"><?= e(__('Show on TVs')) ?></label>
        </div></div>
      </div></div>
      <div class="d-grid d-sm-flex gap-2 mt-3">
        <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
        <a class="btn btn-light border btn-lg" href="<?= e(admin_url('celebrations.php')) ?>"><?= e(__('Cancel')) ?></a>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$rows = Celebrations::all();
$screens = BusinessApps::screens('celebrations');
$todayMd = date('n-j');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-balloon-heart"></i> <?= e(__('Birthdays & anniversaries')) ?></h1>
    <p class="lead-sm"><?= e(__('People shown on the birthday & anniversary wall on their day. Only entries with consent are shown.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'celebrations'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Wall screens (:n)', ['n' => $screens]) : __('Create a wall screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('celebrations.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New entry')) ?></a>
  </div>
</div>
<details class="card mb-3"><summary class="card-header"><i class="bi bi-filetype-csv"></i> <?= e(__('Import from CSV')) ?></summary><div class="card-body">
  <form method="post" enctype="multipart/form-data" class="row g-3">
    <?= Csrf::field() ?><input type="hidden" name="op" value="import">
    <div class="col-12 small text-muted"><?= e(__('Columns: name,date,type,group — date as DD/MM/YYYY, DD/MM or YYYY-MM-DD; type birthday, anniversary or work_anniversary (empty = birthday). A header line "name,date,type,group" is skipped.')) ?></div>
    <div class="col-md-6"><label class="form-label" for="csv_file"><?= e(__('CSV file')) ?></label><input class="form-control" type="file" id="csv_file" name="csv" accept=".csv,text/csv,text/plain"></div>
    <div class="col-md-6"><label class="form-label" for="csv_text"><?= e(__('…or paste the lines')) ?></label><textarea class="form-control font-monospace" id="csv_text" name="csv_text" rows="3" placeholder="Asha Patel,08/10/1990,birthday,Front office"></textarea></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="consent_all" name="consent_all" value="1"><label class="form-check-label" for="consent_all"><?= e(__('Everyone in this file agreed to be shown on the TV screens')) ?></label></div>
      <div class="form-text"><?= e(__('Leave unticked to import without consent; you can then tick consent per person.')) ?></div></div>
    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-upload"></i> <?= e(__('Import')) ?></button></div>
  </form>
</div></details>
<?php if (!$rows): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-balloon-heart"></i><p class="text-muted"><?= e(__('No entries yet. Add people or import a CSV file.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($rows as $c): $photo = Celebrations::photoUrl($c); ?>
    <div class="col-12 col-md-6 col-xl-4" data-celebration="<?= (int) $c['id'] ?>">
      <div class="card h-100"><div class="card-body d-flex gap-3">
        <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" class="rounded-circle border flex-shrink-0" style="width:60px;height:60px;object-fit:cover"><?php endif; ?>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between gap-2">
            <strong class="text-break"><?= e($c['name']) ?></strong>
            <?php if (!(int) $c['consent']): ?><span class="badge rounded-pill text-bg-warning align-self-start"><i class="bi bi-eye-slash"></i> <?= e(__('No consent')) ?></span>
            <?php elseif (!(int) $c['is_active']): ?><span class="badge rounded-pill text-bg-light border align-self-start"><?= e(__('Off')) ?></span>
            <?php elseif ((int) $c['month'] . '-' . (int) $c['day'] === $todayMd): ?><span class="badge rounded-pill text-bg-success align-self-start"><?= e(__('Today')) ?></span><?php endif; ?>
          </div>
          <div class="small text-muted"><?= e(Celebrations::typeLabel((string) $c['type'])) ?> · <?= e((int) $c['day'] . ' ' . __(date('F', mktime(12, 0, 0, (int) $c['month'], 1, 2000))) . ($c['year'] ? ' ' . $c['year'] : '')) ?><?= $c['group_label'] !== '' ? ' · ' . e($c['group_label']) : '' ?></div>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('celebrations.php', ['action' => 'edit', 'id' => $c['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-light border"><i class="bi <?= (int) $c['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i> <?= e((int) $c['is_active'] ? __('Hide') : __('Show')) ?></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete ":t"?', ['t' => $c['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
