<?php
declare(strict_types=1);
/**
 * Class schedule (#6): weekly sessions shown by the "Class schedule" display app
 * (core/Apps/ClassScheduleApp.php) — gym / yoga classes, school periods, coaching batches, OPD timings.
 * Name, trainer (+ photo), days, start / end time, studio, level, colour, active switch.
 * Phone friendly: cards, big inputs, quick day buttons.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('class_schedule.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('class_schedule.php'));
}
Csrf::check();

$formRow = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? ClassSchedule::find($id) : null; // another hotel's id → 404
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        flash('warning', __('Class not found.'));
        redirect(admin_url('class_schedule.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = ClassSchedule::validate($_POST);
            $up = null;
            if (!$formErrors) {
                [$up, $formErrors] = BusinessApps::upload('photo');
            }
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'photo_path' => $existing['photo_path'] ?? null, 'thumb_path' => $existing['thumb_path'] ?? null] + $data + ClassSchedule::DEFAULTS;
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
            $newId = ClassSchedule::save($existing ? $id : null, $data);
            if ($old && $old['photo_path']) {
                Uploader::delete($old['photo_path'], $old['thumb_path']);
            }
            ActivityLog::add($existing ? 'class_update' : 'class_create', 'class_session', $newId, mb_substr($data['name'], 0, 120));
            flash('success', __('Class ":t" saved.', ['t' => $data['name']]));
            redirect(admin_url('class_schedule.php'));

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('class_sessions', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('class_toggle', 'class_session', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['name'], 0, 100));
            flash('success', $on ? __('Class ":t" is shown again.', ['t' => $existing['name']]) : __('Class ":t" is hidden.', ['t' => $existing['name']]));
            redirect(admin_url('class_schedule.php'));

        case 'delete':
            ClassSchedule::delete($id);
            ActivityLog::add('class_delete', 'class_session', $id, mb_substr((string) $existing['name'], 0, 120));
            flash('success', __('Class ":t" deleted.', ['t' => $existing['name']]));
            redirect(admin_url('class_schedule.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('class_schedule.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'class_schedule';
$pageTitle = __('Class schedule');
$levels = array_map('__', ClassSchedule::LEVELS);

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = ClassSchedule::DEFAULTS;
        if ($action === 'edit') {
            $formRow = ClassSchedule::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Class not found.'));
                redirect(admin_url('class_schedule.php'));
            }
        }
    }
    $s = $formRow;
    $isEdit = (int) $s['id'] > 0;
    $days = ClassSchedule::days($s);
    $pageTitle = $isEdit ? __('Edit class') : __('New class');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-calendar-week"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Classes repeat every week on the chosen days. TVs update within 30 seconds.')) ?></p></div>
      <a href="<?= e(admin_url('class_schedule.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('class_schedule.php')) ?>" id="classForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="c_name"><?= e(__('Class name')) ?> *</label>
            <input class="form-control form-control-lg" id="c_name" name="name" value="<?= e($s['name']) ?>" required maxlength="120" placeholder="<?= e(__('e.g. Power yoga, Maths, OPD')) ?>">
          </div>
          <div class="col-12">
            <div class="form-label"><?= e(__('Days')) ?> *</div>
            <div class="d-flex flex-wrap gap-2" id="c_days">
              <?php foreach (ClassSchedule::DAY_SHORT as $d => $l): ?>
                <input type="checkbox" class="btn-check" id="c_d<?= $d ?>" name="days[]" value="<?= $d ?>" autocomplete="off"<?= in_array($d, $days, true) ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary" for="c_d<?= $d ?>"><?= e(__($l)) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button type="button" class="btn btn-sm btn-light border" data-days="1,2,3,4,5"><?= e(__('Mon–Fri')) ?></button>
              <button type="button" class="btn btn-sm btn-light border" data-days="1,2,3,4,5,6"><?= e(__('Mon–Sat')) ?></button>
              <button type="button" class="btn btn-sm btn-light border" data-days="1,2,3,4,5,6,7"><?= e(__('Every day')) ?></button>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label" for="c_start"><?= e(__('Start time')) ?> *</label>
            <input class="form-control form-control-lg" type="time" id="c_start" name="start_time" value="<?= e(substr((string) $s['start_time'], 0, 5)) ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label" for="c_end"><?= e(__('End time')) ?> *</label>
            <input class="form-control form-control-lg" type="time" id="c_end" name="end_time" value="<?= e(substr((string) $s['end_time'], 0, 5)) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="c_trainer"><?= e(__('Trainer / teacher / doctor')) ?></label>
            <input class="form-control" id="c_trainer" name="trainer" value="<?= e($s['trainer']) ?>" maxlength="120">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="c_photo"><?= e(__('Photo (optional)')) ?></label>
            <input class="form-control" type="file" id="c_photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
            <?php if (!empty($s['photo_path'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2">
                <img src="<?= e(media_url($s['thumb_path'] ?: $s['photo_path'])) ?>" alt="" class="rounded-circle border" style="width:56px;height:56px;object-fit:cover">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="c_rm" name="remove_photo" value="1"><label class="form-check-label" for="c_rm"><?= e(__('Remove photo')) ?></label></div>
              </div>
            <?php endif; ?>
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="c_room"><?= e(__('Studio / room')) ?></label>
            <input class="form-control" id="c_room" name="room" value="<?= e($s['room']) ?>" maxlength="80">
          </div>
          <div class="col-12">
            <label class="form-label" for="c_level"><?= e(__('Level')) ?></label>
            <select class="form-select" id="c_level" name="level">
              <?php foreach ($levels as $k => $l): ?><option value="<?= e($k) ?>"<?= $s['level'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="c_color"><?= e(__('Colour')) ?></label>
            <input type="color" class="form-control form-control-color w-100" id="c_color" name="color" value="<?= e(clean_color((string) $s['color'], '#1565C0')) ?>" list="c_colors">
            <datalist id="c_colors"><?php foreach (ClassSchedule::COLORS as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="c_active" name="is_active" value="1"<?= (int) $s['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="c_active"><?= e(__('Show on TVs')) ?></label>
          </div></div>
        </div></div>
        <div class="d-grid d-sm-flex gap-2 mt-3">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('class_schedule.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div></div>
      </div>
    </form>
    <script>
    document.querySelectorAll('[data-days]').forEach(function (b) {
      b.addEventListener('click', function () {
        var on = b.getAttribute('data-days').split(',');
        document.querySelectorAll('#c_days input').forEach(function (c) { c.checked = on.indexOf(c.value) >= 0; });
      });
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list (cards, phone friendly)
$sessions = ClassSchedule::all();
$screens = BusinessApps::screens('class_schedule');
$today = ClassSchedule::annotate(array_values(array_filter($sessions, static fn (array $r): bool => (bool) (int) $r['is_active'])), time());
$todayState = [];
foreach ($today as $t) {
    $todayState[(int) $t['id']] = $t['state'];
}
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-calendar-week"></i> <?= e(__('Class schedule')) ?></h1>
    <p class="lead-sm"><?= e(__('Weekly classes, periods or OPD timings for the Class schedule app screens on your TVs.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'class_schedule'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Schedule screens (:n)', ['n' => $screens]) : __('Create a schedule screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('class_schedule.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New class')) ?></a>
  </div>
</div>
<?php if (!$sessions): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-calendar-week"></i><p class="text-muted"><?= e(__('No classes yet. Add the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($sessions as $s): $st = $todayState[(int) $s['id']] ?? ''; ?>
    <div class="col-12 col-md-6 col-xl-4" data-class="<?= (int) $s['id'] ?>">
      <div class="card h-100" style="border-left:6px solid <?= e(clean_color((string) $s['color'], '#1565C0')) ?>"><div class="card-body d-flex gap-3">
        <?php if ($s['photo_path']): ?><img src="<?= e(media_url($s['thumb_path'] ?: $s['photo_path'])) ?>" alt="" class="rounded-circle border flex-shrink-0" style="width:56px;height:56px;object-fit:cover"><?php endif; ?>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between gap-2">
            <strong class="text-break"><?= e($s['name']) ?></strong>
            <?php if (!(int) $s['is_active']): ?><span class="badge rounded-pill text-bg-light border align-self-start"><i class="bi bi-pause-circle"></i> <?= e(__('Off')) ?></span>
            <?php elseif ($st === 'now'): ?><span class="badge rounded-pill text-bg-success align-self-start"><?= e(__('NOW')) ?></span>
            <?php elseif ($st === 'next'): ?><span class="badge rounded-pill text-bg-warning align-self-start"><?= e(__('NEXT')) ?></span><?php endif; ?>
          </div>
          <div class="fw-semibold"><?= e(BusinessApps::timeLabel((string) $s['start_time'], false) . ' – ' . BusinessApps::timeLabel((string) $s['end_time'], false)) ?> · <?= e(ClassSchedule::daysLabel($s)) ?></div>
          <div class="small text-muted text-break"><?= e(implode(' · ', array_filter([(string) $s['trainer'], (string) $s['room'], $s['level'] !== '' ? ($levels[$s['level']] ?? '') : '']))) ?></div>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('class_schedule.php', ['action' => 'edit', 'id' => $s['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-light border"><i class="bi <?= (int) $s['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i> <?= e((int) $s['is_active'] ? __('Hide') : __('Show')) ?></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete class ":t"?', ['t' => $s['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
