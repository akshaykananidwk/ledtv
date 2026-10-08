<?php
declare(strict_types=1);
/**
 * Festival calendar (#28): festivals shown by the "Festival calendar" display app
 * (core/Apps/FestivalsApp.php). Name in English / Gujarati / Hindi, date (+ optional last day),
 * picture (Uploader), description, festival colours (theme), active switch, and a one-click
 * "Import starter list" of 2026–2027 festivals (dates to be verified with the local temple / panchang).
 * Permission festivals.manage (staff+). Phone friendly: cards, big buttons.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('festivals.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('festivals.php'));
}
Csrf::check();

$formRow = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'import') {
        $n = Festivals::importStarter();
        ActivityLog::add('festival_import', 'festival', null, $n . ' starter festivals');
        flash($n ? 'success' : 'info', $n ? __(':n festivals added. Please verify the dates with your temple / panchang.', ['n' => $n]) : __('The starter list is already imported.'));
        redirect(admin_url('festivals.php'));
    }
    $id = req_int('id', $_POST);
    $existing = $id ? Festivals::find($id) : null; // another hotel's id → 404
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        flash('warning', __('Festival not found.'));
        redirect(admin_url('festivals.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Festivals::validate($_POST);
            $up = null;
            if (!$formErrors) {
                [$up, $formErrors] = BusinessApps::upload('image');
            }
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'image_path' => $existing['image_path'] ?? null, 'thumb_path' => $existing['thumb_path'] ?? null] + $data + Festivals::DEFAULTS;
                break;
            }
            $old = null;
            if ($up) {
                $data['image_path'] = $up['path'];
                $data['thumb_path'] = $up['thumb'];
                $old = $existing;
            } elseif ($existing && !empty($_POST['remove_image'])) {
                $data['image_path'] = null;
                $data['thumb_path'] = null;
                $old = $existing;
            }
            $newId = Festivals::save($existing ? $id : null, $data);
            if ($old && $old['image_path']) {
                Uploader::delete($old['image_path'], $old['thumb_path']);
            }
            ActivityLog::add($existing ? 'festival_update' : 'festival_create', 'festival', $newId, mb_substr($data['name_en'], 0, 120));
            flash('success', __('Festival ":t" saved.', ['t' => $data['name_en']]));
            redirect(admin_url('festivals.php'));

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('festivals', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('festival_toggle', 'festival', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['name_en'], 0, 100));
            flash('success', $on ? __('Festival ":t" is shown again.', ['t' => $existing['name_en']]) : __('Festival ":t" is hidden.', ['t' => $existing['name_en']]));
            redirect(admin_url('festivals.php'));

        case 'delete':
            Festivals::delete($id);
            ActivityLog::add('festival_delete', 'festival', $id, mb_substr((string) $existing['name_en'], 0, 120));
            flash('success', __('Festival ":t" deleted.', ['t' => $existing['name_en']]));
            redirect(admin_url('festivals.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('festivals.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'festivals';
$pageTitle = __('Festivals');
$themes = ['' => __('Screen colours (no change)')];
foreach (DisplayApps::THEMES as $k => $t) {
    $themes[$k] = __($t['label']);
}

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = Festivals::DEFAULTS;
        if ($action === 'edit') {
            $formRow = Festivals::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Festival not found.'));
                redirect(admin_url('festivals.php'));
            }
        }
    }
    $f = $formRow;
    $isEdit = (int) $f['id'] > 0;
    $pageTitle = $isEdit ? __('Edit festival') : __('New festival');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-stars"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Festivals appear on every TV showing a Festival calendar screen.')) ?></p></div>
      <a href="<?= e(admin_url('festivals.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ((int) $f['is_starter']): ?>
      <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= e(__('From the starter list: please verify the date with your temple / panchang.')) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('festivals.php')) ?>">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="f_en"><?= e(__('Name (English)')) ?> *</label>
            <input class="form-control form-control-lg" id="f_en" name="name_en" value="<?= e($f['name_en']) ?>" required maxlength="120" placeholder="<?= e(__('e.g. Diwali')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="f_gu"><?= e(__('Name (Gujarati)')) ?></label>
            <input class="form-control" id="f_gu" name="name_gu" value="<?= e($f['name_gu']) ?>" maxlength="120" lang="gu" placeholder="દિવાળી">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="f_hi"><?= e(__('Name (Hindi)')) ?></label>
            <input class="form-control" id="f_hi" name="name_hi" value="<?= e($f['name_hi']) ?>" maxlength="120" lang="hi" placeholder="दीपावली">
          </div>
          <div class="col-12">
            <label class="form-label" for="f_desc"><?= e(__('Description')) ?></label>
            <textarea class="form-control" id="f_desc" name="description" rows="3" maxlength="<?= Festivals::MAX_DESC ?>"><?= e((string) $f['description']) ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label" for="f_image"><?= e(__('Picture (optional)')) ?></label>
            <input class="form-control" type="file" id="f_image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <?php if (!empty($f['image_path'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2">
                <img src="<?= e(media_url($f['thumb_path'] ?: $f['image_path'])) ?>" alt="" class="rounded border" style="max-height:80px">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="f_rm" name="remove_image" value="1"><label class="form-check-label" for="f_rm"><?= e(__('Remove image')) ?></label></div>
              </div>
            <?php endif; ?>
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="f_start"><?= e(__('Date')) ?> *</label>
            <input class="form-control form-control-lg" type="date" id="f_start" name="starts_on" value="<?= e((string) $f['starts_on']) ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label" for="f_end"><?= e(__('Last day (optional)')) ?></label>
            <input class="form-control" type="date" id="f_end" name="ends_on" value="<?= e((string) ($f['ends_on'] ?? '')) ?>">
            <div class="form-text"><?= e(__('For festivals of several days, e.g. Navratri.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="f_theme"><?= e(__('Festival colours')) ?></label>
            <select class="form-select" id="f_theme" name="theme"><?php foreach ($themes as $k => $l): ?><option value="<?= e($k) ?>"<?= (string) $f['theme'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <div class="form-text"><?= e(__('Used on the festival day when the screen has "Use the festival\'s colours" switched on.')) ?></div>
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="f_active" name="is_active" value="1"<?= (int) $f['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="f_active"><?= e(__('Show on TVs')) ?></label>
          </div></div>
        </div></div>
        <div class="d-grid d-sm-flex gap-2 mt-3">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('festivals.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div></div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list (cards, phone friendly)
$today = date('Y-m-d');
$all = Festivals::all();
$showPast = req_str('past', $_GET, 1) === '1';
$list = array_values(array_filter($all, static fn ($f) => $showPast || Festivals::lastDay($f) >= $today));
$pastCount = count($all) - count(array_filter($all, static fn ($f) => Festivals::lastDay($f) >= $today));
$screens = BusinessApps::screens('festivals');
$states = [
    'today' => ['text-bg-success', 'bi-broadcast', __('Today')],
    'upcoming' => ['text-bg-info', 'bi-calendar-event', __('Upcoming')],
    'past' => ['text-bg-secondary', 'bi-hourglass-bottom', __('Past')],
    'off' => ['text-bg-light border', 'bi-pause-circle', __('Off')],
];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-stars"></i> <?= e(__('Festivals')) ?></h1>
    <p class="lead-sm"><?= e(__('Festival calendar with countdown for the Festival calendar screens on your TVs.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'festivals'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Festival screens (:n)', ['n' => $screens]) : __('Create a festival screen')) ?></a>
    <?php endif; ?>
    <form method="post" class="d-inline" data-confirm="<?= e(__('Add the starter list of 2026–2027 festivals? Existing festivals are kept.')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="import">
      <button class="btn btn-light border"><i class="bi bi-download"></i> <?= e(__('Import starter list')) ?></button></form>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('festivals.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New festival')) ?></a>
  </div>
</div>
<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> <?= e(__('Festival dates depend on the lunar calendar and local custom. The starter list dates are a guide only — please verify them with your temple / panchang and edit them if needed.')) ?></div>
<?php if (!$list): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-stars"></i><p class="text-muted"><?= e(__('No festivals yet. Import the starter list or add the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($list as $f): [$cls, $icon, $label] = $states[Festivals::state($f, $today)]; $days = Festivals::daysUntil((string) $f['starts_on'], $today); ?>
    <div class="col-12 col-md-6 col-xl-4" data-festival="<?= (int) $f['id'] ?>">
      <div class="card h-100"><div class="card-body d-flex gap-3">
        <?php if ($f['image_path']): ?><img src="<?= e(media_url($f['thumb_path'] ?: $f['image_path'])) ?>" alt="" class="rounded border flex-shrink-0" style="width:72px;height:72px;object-fit:cover"><?php endif; ?>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between gap-2">
            <strong class="text-break"><?= e($f['name_en']) ?></strong>
            <span class="badge rounded-pill align-self-start <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></span>
          </div>
          <?php if ($f['name_gu'] !== '' || $f['name_hi'] !== ''): ?><div class="small text-muted"><?= e(trim($f['name_gu'] . ' · ' . $f['name_hi'], ' ·')) ?></div><?php endif; ?>
          <div class="small mt-1"><i class="bi bi-calendar3"></i> <?= e($f['starts_on'] . ($f['ends_on'] ? ' → ' . $f['ends_on'] : '')) ?>
            <?php if ($days > 0): ?><span class="text-muted">· <?= e(__('in :n days', ['n' => $days])) ?></span><?php endif; ?></div>
          <?php if ((int) $f['is_starter']): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-circle"></i> <?= e(__('Please verify the date')) ?></div><?php endif; ?>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('festivals.php', ['action' => 'edit', 'id' => $f['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-sm btn-light border"><i class="bi <?= (int) $f['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i> <?= e((int) $f['is_active'] ? __('Hide') : __('Show')) ?></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete festival ":t"?', ['t' => $f['name_en']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($pastCount > 0): ?>
  <div class="mt-3"><a class="small" href="<?= e(admin_url('festivals.php', $showPast ? [] : ['past' => 1])) ?>"><?= e($showPast ? __('Hide past festivals') : __('Show past festivals (:n)', ['n' => $pastCount])) ?></a></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
