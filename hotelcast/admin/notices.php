<?php
declare(strict_types=1);
/**
 * Notice board (#3): school / college / office notices shown by the "Notice board" display app
 * (core/Apps/NoticeBoardApp.php). Title, text, category (exam / holiday / result / event / general),
 * date range, priority, optional image (Uploader), active switch. TVs update within a minute.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('notices.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('notices.php'));
}
Csrf::check();

$formNotice = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? Notices::find($id) : null; // another hotel's id → 404
    if ($id && !$existing) {
        flash('warning', __('Notice not found.'));
        redirect(admin_url('notices.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Notices::validate($_POST);
            $hasFile = isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $up = null;
            if (!$formErrors && $hasFile) {
                try {
                    $up = Uploader::handle($_FILES['image'], 'image');
                } catch (RuntimeException $e) {
                    $formErrors[] = $e->getMessage();
                }
            }
            if ($formErrors) {
                http_response_code(422);
                $formNotice = ['id' => $existing ? (int) $existing['id'] : 0, 'image_path' => $existing['image_path'] ?? null, 'thumb_path' => $existing['thumb_path'] ?? null] + $data + Notices::DEFAULTS;
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
            $newId = Notices::save($existing ? $id : null, $data);
            if ($old && $old['image_path']) {
                Uploader::delete($old['image_path'], $old['thumb_path']);
            }
            ActivityLog::add($existing ? 'notice_update' : 'notice_create', 'notice', $newId, mb_substr($data['title'], 0, 120));
            flash('success', __('Notice ":t" saved.', ['t' => $data['title']]));
            redirect(admin_url('notices.php'));

        case 'toggle':
            if ($existing) {
                $on = !(int) $existing['is_active'];
                DB::update('notices', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
                ActivityLog::add('notice_toggle', 'notice', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['title'], 0, 100));
                flash('success', $on ? __('Notice ":t" is shown again.', ['t' => $existing['title']]) : __('Notice ":t" is hidden.', ['t' => $existing['title']]));
            }
            redirect(admin_url('notices.php'));

        case 'delete':
            if ($existing) {
                Notices::delete($id);
                ActivityLog::add('notice_delete', 'notice', $id, mb_substr((string) $existing['title'], 0, 120));
                flash('success', __('Notice ":t" deleted.', ['t' => $existing['title']]));
            }
            redirect(admin_url('notices.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('notices.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'notices';
$pageTitle = __('Notice board');
$cats = array_map('__', Notices::CATEGORIES);
$badge = static fn (string $cat): string => '<span class="badge" style="background:' . e(Notices::COLORS[$cat] ?? '#546E7A') . '">' . e($cats[$cat] ?? $cat) . '</span>';

if ($action === 'new' || $action === 'edit' || $formNotice !== null) {
    if ($formNotice === null) {
        $formNotice = Notices::DEFAULTS;
        if ($action === 'edit') {
            $formNotice = Notices::find(req_int('id', $_GET));
            if (!$formNotice) {
                flash('warning', __('Notice not found.'));
                redirect(admin_url('notices.php'));
            }
        }
    }
    $n = $formNotice;
    $isEdit = (int) $n['id'] > 0;
    $pageTitle = $isEdit ? __('Edit notice') : __('New notice');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-pin-angle"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Notices appear on every TV showing a Notice board app screen, within a minute.')) ?></p></div>
      <a href="<?= e(admin_url('notices.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('notices.php')) ?>" id="noticeForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="n_title"><?= e(__('Title')) ?> *</label>
            <input class="form-control form-control-lg" id="n_title" name="title" value="<?= e($n['title']) ?>" required maxlength="190" placeholder="<?= e(__('e.g. Annual exams start on Monday')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="n_body"><?= e(__('Notice text')) ?></label>
            <textarea class="form-control" id="n_body" name="body" rows="5" maxlength="<?= Notices::MAX_BODY ?>"><?= e($n['body']) ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label" for="n_image"><?= e(__('Image (optional)')) ?></label>
            <input class="form-control" type="file" id="n_image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <?php if (!empty($n['image_path'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2">
                <img src="<?= e(media_url($n['thumb_path'] ?: $n['image_path'])) ?>" alt="" class="rounded border" style="max-height:80px">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="n_rm" name="remove_image" value="1"><label class="form-check-label" for="n_rm"><?= e(__('Remove image')) ?></label></div>
              </div>
            <?php endif; ?>
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="n_cat"><?= e(__('Category')) ?></label>
            <select class="form-select" id="n_cat" name="category">
              <?php foreach ($cats as $k => $l): ?><option value="<?= e($k) ?>"<?= $n['category'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" for="n_from"><?= e(__('Show from')) ?></label>
            <input class="form-control" type="date" id="n_from" name="starts_on" value="<?= e((string) $n['starts_on']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="n_to"><?= e(__('Show until')) ?></label>
            <input class="form-control" type="date" id="n_to" name="ends_on" value="<?= e((string) $n['ends_on']) ?>">
          </div>
          <div class="col-12 form-text mt-0"><?= e(__('Leave empty to show it from today and until you switch it off.')) ?></div>
          <div class="col-12">
            <label class="form-label" for="n_prio"><?= e(__('Priority')) ?></label>
            <input class="form-control" type="number" id="n_prio" name="priority" min="-100" max="100" value="<?= (int) $n['priority'] ?>">
            <div class="form-text"><?= e(__('Higher numbers are shown first.')) ?></div>
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="n_active" name="is_active" value="1"<?= (int) $n['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="n_active"><?= e(__('Active')) ?></label>
          </div></div>
        </div></div></div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('notices.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$notices = Notices::all();
$boards = (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :hid AND type = 'app' AND settings LIKE :p", hid() + ['p' => '%"app":"notice_board"%']);
$states = [
    'live' => ['text-bg-success', 'bi-broadcast', __('Showing')],
    'scheduled' => ['text-bg-info', 'bi-clock', __('Scheduled')],
    'expired' => ['text-bg-secondary', 'bi-hourglass-bottom', __('Expired')],
    'off' => ['text-bg-light border', 'bi-pause-circle', __('Off')],
];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-pin-angle"></i> <?= e(__('Notice board')) ?></h1>
    <p class="lead-sm"><?= e(__('Exam, holiday, result and event notices for the Notice board app screens on your TVs.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($boards ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'notice_board'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($boards ? __('Notice board screens (:n)', ['n' => $boards]) : __('Create a notice board screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('notices.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New notice')) ?></a>
  </div>
</div>
<?php if (!$notices): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-pin-angle"></i><p class="text-muted"><?= e(__('No notices yet. Add the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('Title')) ?></th><th><?= e(__('Category')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Dates')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Priority')) ?></th><th><?= e(__('State')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($notices as $n): [$cls, $icon, $label] = $states[Notices::state($n)]; ?>
        <tr data-notice="<?= (int) $n['id'] ?>">
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if ($n['image_path']): ?><img src="<?= e(media_url($n['thumb_path'] ?: $n['image_path'])) ?>" alt="" class="thumb-sm"><?php endif; ?>
              <div class="min-w-0"><strong><?= e($n['title']) ?></strong><?php if ($n['body'] !== null && $n['body'] !== ''): ?><div class="small text-muted text-truncate" style="max-width:28rem"><?= e($n['body']) ?></div><?php endif; ?></div>
            </div>
          </td>
          <td><?= $badge((string) $n['category']) ?></td>
          <td class="d-none d-md-table-cell small"><?= e(($n['starts_on'] ?: '…') . ' → ' . ($n['ends_on'] ?: '…')) ?></td>
          <td class="d-none d-md-table-cell"><?= (int) $n['priority'] ?></td>
          <td><span class="badge rounded-pill <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></span></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('notices.php', ['action' => 'edit', 'id' => $n['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button class="btn btn-sm btn-light border" title="<?= e((int) $n['is_active'] ? __('Hide') : __('Show')) ?>"><i class="bi <?= (int) $n['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete notice ":t"?', ['t' => $n['title']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
