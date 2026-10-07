<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.view');

// A POST bigger than post_max_size arrives empty (and would fail CSRF confusingly).
if (post_too_large()) {
    $msg = __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]);
    if (Auth::isAjax()) {
        ajax_error($msg, 413, 'TOO_LARGE');
    }
    flash('danger', $msg);
    redirect(admin_url('content.php'));
}
Csrf::check();

/** Finish a save: JSON for XHR uploads, flash + redirect otherwise. */
function content_done(bool $ok, string $message, string $redirect): never
{
    if (Auth::isAjax()) {
        if ($ok) {
            flash('success', $message);
            ajax_ok(['redirect' => $redirect]);
        }
        ajax_error($message, 422, 'VALIDATION_ERROR');
    }
    flash($ok ? 'success' : 'danger', $message);
    redirect($redirect);
}

if (is_post()) {
    require_can('content.manage');
    $op = req_str('op', $_POST, 20);

    if ($op === 'save') {
        $id = req_int('id', $_POST);
        $existing = $id ? ContentManager::find($id) : null;
        if ($id && !$existing) {
            content_done(false, __('Content not found.'), admin_url('content.php'));
        }
        $type = $existing ? (string) $existing['type'] : (string) ($_POST['type'] ?? '');
        $formUrl = $existing ? admin_url('content.php', ['action' => 'edit', 'id' => $id]) : admin_url('content.php', ['action' => 'new', 'type' => $type]);
        if (!isset(ContentManager::TYPES[$type])) {
            content_done(false, __('Invalid content type.'), admin_url('content.php'));
        }
        $hasFile = in_array($type, ['image', 'video'], true) && isset($_FILES['file']) && is_array($_FILES['file'])
            && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $in = $_POST;
        $in['_existing_file'] = $existing['file_path'] ?? null;
        [$data, $errors] = ContentManager::validate($in, $type, $hasFile);
        if ($errors) {
            content_done(false, implode("\n", $errors), $formUrl);
        }

        $row = [
            'title' => $data['title'],
            'duration' => $data['duration'],
            'url' => $data['url'],
            'body' => $data['body'],
            'settings' => json_out((object) $data['settings']),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];
        $oldFiles = null;
        if ($hasFile) {
            try {
                $up = Uploader::handle($_FILES['file'], $type);
            } catch (RuntimeException $e) {
                content_done(false, $e->getMessage(), $formUrl);
            }
            $row += ['file_path' => $up['path'], 'thumb_path' => $up['thumb'], 'mime_type' => $up['mime'], 'file_size' => $up['size'], 'url' => null];
            $oldFiles = $existing ? [$existing['file_path'], $existing['thumb_path']] : null;
        } elseif ($existing && in_array($type, ['image', 'video'], true) && $data['url'] && $existing['file_path']) {
            // Switched from an uploaded file to an external URL.
            $row += ['file_path' => null, 'thumb_path' => null, 'mime_type' => null, 'file_size' => null];
            $oldFiles = [$existing['file_path'], $existing['thumb_path']];
        }

        if ($existing) {
            DB::update('content_items', $row, 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('content_items', $row + ['type' => $type, 'created_by' => Auth::id(), 'created_at' => now()]);
        }
        if ($oldFiles) {
            Uploader::delete($oldFiles[0], $oldFiles[1]);
        }
        Settings::bumpContentVersion();
        ActivityLog::add($existing ? 'content_update' : 'content_create', 'content', $id, $type . ': ' . $data['title']);
        content_done(true, __('":t" saved.', ['t' => $data['title']]), admin_url('content.php'));
    }

    if ($op === 'delete') {
        $id = req_int('id', $_POST);
        $item = ContentManager::find($id);
        if ($item) {
            ContentManager::deleteItem($id);
            ActivityLog::add('content_delete', 'content', $id, $item['type'] . ': ' . $item['title']);
            flash('success', __('":t" deleted.', ['t' => $item['title']]));
        }
        redirect(admin_url('content.php'));
    }

    if ($op === 'toggle') {
        $id = req_int('id', $_POST);
        $item = ContentManager::find($id);
        if ($item) {
            DB::update('content_items', ['is_active' => (int) $item['is_active'] ? 0 : 1], 'id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
            ActivityLog::add('content_toggle', 'content', $id, $item['title'] . ((int) $item['is_active'] ? ' disabled' : ' enabled'));
            flash('success', (int) $item['is_active'] ? __('":t" is now hidden from TVs.', ['t' => $item['title']]) : __('":t" is active again.', ['t' => $item['title']]));
        }
        redirect(admin_url('content.php'));
    }

    if ($op === 'duplicate') {
        $id = req_int('id', $_POST);
        $item = ContentManager::find($id);
        if ($item && !$item['file_path']) {
            $nid = DB::insert('content_items', [
                'title' => mb_substr($item['title'] . ' ' . __('(copy)'), 0, 190), 'type' => $item['type'], 'url' => $item['url'],
                'body' => $item['body'], 'settings' => $item['settings'], 'duration' => $item['duration'], 'is_active' => $item['is_active'],
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            ActivityLog::add('content_create', 'content', $nid, 'Duplicated from #' . $id);
            flash('success', __('Copy created.'));
            redirect(admin_url('content.php', ['action' => 'edit', 'id' => $nid]));
        }
        redirect(admin_url('content.php'));
    }
    flash('warning', __('Unknown action.'));
    redirect(admin_url('content.php'));
}

$action = req_str('action', $_GET, 20);
$canManage = Auth::can('content.manage');
$pageTitle = __('Content Library');
$activeNav = 'content';
$limitText = human_bytes(upload_limit());

// ---------------------------------------------------------------- delete confirmation
if ($action === 'delete') {
    require_can('content.manage');
    $item = ContentManager::find(req_int('id', $_GET));
    if (!$item) {
        redirect(admin_url('content.php'));
    }
    $usage = ContentManager::usage((int) $item['id']);
    $layoutUse = Layouts::usageMap()['c'][(int) $item['id']] ?? [];
    $used = array_sum($usage) > 0 || $layoutUse || (string) Settings::get('default_content_id') === (string) $item['id'];
    $pageTitle = __('Delete content');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="card mx-auto" style="max-width:640px">
      <div class="card-body p-4">
        <h1 class="h4"><i class="bi bi-trash text-danger"></i> <?= e(__('Delete ":t"?', ['t' => $item['title']])) ?></h1>
        <?php if ($used): ?>
          <div class="alert alert-warning">
            <strong><?= e(__('This content is in use:')) ?></strong>
            <ul class="mb-0">
              <?php if ($usage['rooms']): ?><li><?= e(__('Assigned to :n rooms', ['n' => $usage['rooms']])) ?></li><?php endif; ?>
              <?php if ($usage['groups']): ?><li><?= e(__('Assigned to :n groups', ['n' => $usage['groups']])) ?></li><?php endif; ?>
              <?php if ($usage['playlists']): ?><li><?= e(__('Part of :n playlists', ['n' => $usage['playlists']])) ?></li><?php endif; ?>
              <?php if ($usage['broadcasts']): ?><li><?= e(__('Used by :n scheduled broadcasts', ['n' => $usage['broadcasts']])) ?></li><?php endif; ?>
              <?php if ((string) Settings::get('default_content_id') === (string) $item['id']): ?><li><?= e(__('It is the hotel default content')) ?></li><?php endif; ?>
              <?php if ($layoutUse): ?><li><?= e(__('Used in layout :t', ['t' => '"' . implode('", "', $layoutUse) . '"'])) ?> — <?= e(__('Deleting it leaves that zone empty.')) ?></li><?php endif; ?>
            </ul>
            <div class="mt-2 small"><?= e(__('Those TVs will fall back to their group or hotel default content.')) ?></div>
          </div>
        <?php else: ?>
          <p class="text-muted"><?= e(__('This content is not used anywhere.')) ?></p>
        <?php endif; ?>
        <p><?= e(__('This cannot be undone.')) ?></p>
        <form method="post" class="d-flex gap-2">
          <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
          <button class="btn btn-danger"><i class="bi bi-trash"></i> <?= e(__('Yes, delete')) ?></button>
          <a class="btn btn-light border" href="<?= e(admin_url('content.php')) ?>"><?= e(__('Cancel')) ?></a>
        </form>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- add / edit form
if ($action === 'new' || $action === 'edit') {
    require_can('content.manage');
    if ($action === 'edit') {
        $item = ContentManager::find(req_int('id', $_GET));
        if (!$item) {
            flash('warning', __('Content not found.'));
            redirect(admin_url('content.php'));
        }
        $type = (string) $item['type'];
        // Template items (templates module) are edited in the template form; ?raw=1 opens the HTML editor.
        if (empty($_GET['raw']) && class_exists('Templates') && Templates::fromContent($item) && Auth::can('templates.manage') && Tenant::feature('templates')) {
            redirect(admin_url('templates.php', ['action' => 'edit', 'id' => $item['id']]));
        }
        // Designer slides (#11) open in the designer; ?raw=1 keeps the plain image form.
        if (empty($_GET['raw']) && $type === 'image' && class_exists('Designer') && Designer::designFor((int) $item['id'])) {
            redirect(admin_url('designer.php', ['id' => $item['id']]));
        }
    } else {
        $type = (string) ($_GET['type'] ?? '');
        if (!isset(ContentManager::TYPES[$type])) {
            redirect(admin_url('content.php'));
        }
        $item = ['id' => 0, 'title' => '', 'type' => $type, 'url' => '', 'body' => '', 'settings' => null, 'duration' => $type === 'layout' ? 60 : 10, 'is_active' => 1, 'file_path' => null, 'thumb_path' => null, 'file_size' => null];
    }
    // Display apps (2.3) have their own form with a live preview: admin/apps.php.
    if ($type === 'app') {
        redirect(admin_url('apps.php', $item['id'] ? ['action' => 'edit', 'id' => $item['id']] : []));
    }
    $s = ContentManager::settings($item);
    $isNew = !$item['id'];
    $pageTitle = ($isNew ? __('Add') : __('Edit')) . ' · ' . __(ContentManager::TYPES[$type]);
    require __DIR__ . '/partials/header.php';
    $color = fn (string $name, string $label, string $value) => '<div class="col-6 col-md-4"><label class="form-label" for="c_' . e($name) . '">' . e($label) . '</label>'
        . '<input type="color" class="form-control form-control-color w-100" id="c_' . e($name) . '" name="' . e($name) . '" value="' . e($value) . '"></div>';
    $isUpload = in_array($type, ['image', 'video'], true);
    ?>
    <div class="page-head">
      <h1><i class="bi <?= e(ContentManager::TYPE_ICONS[$type]) ?>"></i> <?= e($pageTitle) ?></h1>
      <div class="d-flex gap-2">
        <?php if (!$isNew): ?><a class="btn btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $item['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> <?= e(__('Preview')) ?></a><?php endif; ?>
        <a href="<?= e(admin_url('content.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" class="<?= $isUpload ? 'js-upload' : '' ?>" data-progress="#uploadProgress" action="<?= e(admin_url('content.php')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="type" value="<?= e($type) ?>">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card"><div class="card-body row g-3">
            <div class="col-12">
              <label class="form-label" for="title"><?= e(__('Title')) ?> *</label>
              <input class="form-control form-control-lg" id="title" name="title" value="<?= e($item['title']) ?>" required maxlength="190" placeholder="<?= e(__('A name you will recognise, e.g. Breakfast menu')) ?>">
            </div>

            <?php if ($isUpload): ?>
              <div class="col-12">
                <label class="form-label" for="file"><?= e($type === 'image' ? __('Upload image') : __('Upload video')) ?><?= $isNew ? ' *' : '' ?></label>
                <input class="form-control" type="file" id="file" name="file" accept="<?= $type === 'image' ? 'image/jpeg,image/png,image/gif,image/webp' : 'video/mp4,video/webm,video/x-matroska,video/quicktime,video/3gpp,.mkv,.mov,.3gp,.m4v' ?>"<?= $type === 'image' ? ' data-preview="#imgPreview"' : '' ?>>
                <div class="form-text">
                  <?= e($type === 'image' ? __('JPG, PNG, GIF or WEBP. Large images are resized automatically.') : __('MP4 (H.264) works best on all TVs. WEBM, MKV, MOV, 3GP also accepted.')) ?>
                  <?= e(__('Server upload limit: :s.', ['s' => $limitText])) ?>
                  <?php if ($type === 'video'): ?><?= e(__('App limit: :m MB.', ['m' => Settings::int('max_upload_mb', 200)])) ?><?php endif; ?>
                </div>
                <?php if ($item['file_path']): ?>
                  <div class="small mt-1 text-success"><i class="bi bi-check-circle"></i> <?= e(__('Current file')) ?>: <?= e(basename((string) $item['file_path'])) ?> (<?= e(human_bytes($item['file_size'])) ?>) — <?= e(__('choose a new file only to replace it.')) ?></div>
                <?php endif; ?>
                <?php if ($type === 'image'): ?>
                  <img id="imgPreview" src="<?= e(ContentManager::thumbUrl($item) ?? '') ?>" alt="" class="mt-2 rounded border" style="max-height:200px;max-width:100%"<?= ContentManager::thumbUrl($item) ? '' : ' hidden' ?>>
                <?php endif; ?>
                <div id="uploadProgress" class="mt-2" hidden>
                  <div class="small mb-1" data-progress-label><?= e(__('Uploading…')) ?></div>
                  <div class="progress" style="height:22px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div></div>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="url"><?= e(__('…or use a web address (URL) instead')) ?></label>
                <input class="form-control" type="url" id="url" name="url" value="<?= e($item['file_path'] ? '' : (string) $item['url']) ?>" maxlength="1000" placeholder="https://">
              </div>
              <?php if ($type === 'video'): ?>
                <div class="col-12">
                  <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="loop" name="loop" value="1"<?= ($s['loop'] ?? true) ? ' checked' : '' ?>><label class="form-check-label" for="loop"><?= e(__('Loop video')) ?></label></div>
                  <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="mute" name="mute" value="1"<?= !empty($s['mute']) ? ' checked' : '' ?>><label class="form-check-label" for="mute"><?= e(__('Mute sound')) ?></label></div>
                </div>
              <?php endif; ?>

            <?php elseif ($type === 'stream'): ?>
              <div class="col-12">
                <label class="form-label" for="url"><?= e(__('Stream URL')) ?> *</label>
                <input class="form-control" id="url" name="url" value="<?= e($item['url']) ?>" required maxlength="1000" placeholder="https://example.com/live/stream.m3u8 · rtsp://192.168.1.10/live">
                <div class="form-text"><?= e(__('HLS (.m3u8), RTSP (rtsp://) from a temple camera, DASH or a direct video link.')) ?></div>
              </div>
              <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="mute" name="mute" value="1"<?= !empty($s['mute']) ? ' checked' : '' ?>><label class="form-check-label" for="mute"><?= e(__('Mute sound')) ?></label></div></div>

            <?php elseif ($type === 'youtube'): ?>
              <div class="col-12">
                <label class="form-label" for="url"><?= e(__('YouTube link')) ?> *</label>
                <input class="form-control" type="url" id="url" name="url" value="<?= e($item['url']) ?>" required maxlength="1000" placeholder="https://www.youtube.com/watch?v=…">
                <div class="form-text"><?= e(__('Paste a normal YouTube video or live link. It plays automatically on the TV.')) ?></div>
              </div>

            <?php elseif ($type === 'url'): ?>
              <div class="col-12">
                <label class="form-label" for="url"><?= e(__('Web page address')) ?> *</label>
                <input class="form-control" type="url" id="url" name="url" value="<?= e($item['url']) ?>" required maxlength="1000" placeholder="https://">
                <div class="form-text"><?= e(__('The page opens full screen on the TV. Some websites do not allow being shown inside other apps.')) ?></div>
              </div>

            <?php elseif ($type === 'announcement'): ?>
              <div class="col-12">
                <label class="form-label" for="body"><?= e(__('Announcement text')) ?> *</label>
                <textarea class="form-control" id="body" name="body" rows="3" required maxlength="5000" placeholder="<?= e(__('e.g. Welcome to our hotel!')) ?>"><?= e($item['body']) ?></textarea>
              </div>
              <div class="col-12">
                <label class="form-label" for="subtitle"><?= e(__('Subtitle (optional)')) ?></label>
                <input class="form-control" id="subtitle" name="subtitle" value="<?= e($s['subtitle'] ?? '') ?>" maxlength="500">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="style"><?= e(__('Style')) ?></label>
                <select class="form-select" id="style" name="style">
                  <option value="fullscreen"<?= ($s['style'] ?? '') !== 'marquee' ? ' selected' : '' ?>><?= e(__('Full screen')) ?></option>
                  <option value="marquee"<?= ($s['style'] ?? '') === 'marquee' ? ' selected' : '' ?>><?= e(__('Scrolling text (marquee)')) ?></option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="font_size"><?= e(__('Font size')) ?></label>
                <input class="form-range" type="range" id="font_size" name="font_size" min="12" max="200" value="<?= (int) ($s['font_size'] ?? 48) ?>" oninput="this.nextElementSibling.textContent=this.value">
                <span class="small text-muted"><?= (int) ($s['font_size'] ?? 48) ?></span>
              </div>
              <?= $color('bg_color', __('Background colour'), clean_color($s['bg_color'] ?? null, '#1A237E')) ?>
              <?= $color('text_color', __('Text colour'), clean_color($s['text_color'] ?? null, '#FFFFFF')) ?>

            <?php elseif ($type === 'html'): ?>
              <div class="col-12">
                <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e(__('Advanced: this HTML is shown as-is on the TVs. Only paste code from a source you trust.')) ?></div>
                <label class="form-label" for="body"><?= e(__('HTML code')) ?> *</label>
                <textarea class="form-control mono" id="body" name="body" rows="14" required spellcheck="false"><?= e($item['body']) ?></textarea>
              </div>

            <?php elseif ($type === 'timetable'):
                $tt = $isNew ? ['heading' => '', 'subheading' => '', 'columns' => [__('Time'), __('Darshan')], 'rows' => [['06:00', ''], ['', '']], 'footer' => '', 'bg_color' => '#4A0E0E', 'text_color' => '#FFF8E1', 'accent_color' => '#FFB300'] : ContentManager::timetableData($item);
                $cols = array_values((array) $tt['columns']);
            ?>
              <div class="col-md-6">
                <label class="form-label" for="tt_heading"><?= e(__('Heading')) ?></label>
                <input class="form-control" id="tt_heading" name="tt_heading" value="<?= e($tt['heading']) ?>" maxlength="190" placeholder="<?= e(__('e.g. Darshan Timings')) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="tt_subheading"><?= e(__('Sub-heading')) ?></label>
                <input class="form-control" id="tt_subheading" name="tt_subheading" value="<?= e($tt['subheading']) ?>" maxlength="190">
              </div>
              <div class="col-12">
                <label class="form-label"><?= e(__('Rows')) ?></label>
                <div class="row g-2 small text-muted mb-1 d-none d-md-flex">
                  <div class="col-md-3"><input class="form-control form-control-sm" name="tt_col1" value="<?= e($cols[0] ?? 'Time') ?>" aria-label="<?= e(__('Column 1 title')) ?>"></div>
                  <div class="col-md-4"><input class="form-control form-control-sm" name="tt_col2" value="<?= e($cols[1] ?? 'Darshan') ?>" aria-label="<?= e(__('Column 2 title')) ?>"></div>
                  <div class="col-md-4"><input class="form-control form-control-sm" name="tt_col3" value="<?= e($cols[2] ?? __('Notes')) ?>" aria-label="<?= e(__('Column 3 title')) ?>"></div>
                </div>
                <div id="ttRows">
                  <?php foreach ($tt['rows'] as $r): $r = array_values($r); ?>
                  <div class="row g-2 mb-2 tt-row">
                    <div class="col-4 col-md-3"><input class="form-control" name="tt_time[]" value="<?= e($r[0] ?? '') ?>" placeholder="06:00 - 06:30"></div>
                    <div class="col-8 col-md-4"><input class="form-control" name="tt_name[]" value="<?= e($r[1] ?? '') ?>" placeholder="<?= e(__('e.g. Mangla Aarti')) ?>"></div>
                    <div class="col-10 col-md-4"><input class="form-control" name="tt_note[]" value="<?= e($r[2] ?? '') ?>" placeholder="<?= e(__('Note (optional)')) ?>"></div>
                    <div class="col-2 col-md-1"><button type="button" class="btn btn-outline-danger w-100 tt-del" aria-label="<?= e(__('Remove row')) ?>"><i class="bi bi-x-lg"></i></button></div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="ttAdd"><i class="bi bi-plus-lg"></i> <?= e(__('Add row')) ?></button>
                <div class="form-text"><?= e(__('Times like 06:00 or 06:00 - 06:30 (24h) or 6:00 AM. The current row is highlighted live on the TV.')) ?></div>
              </div>
              <div class="col-md-8">
                <label class="form-label" for="tt_footer"><?= e(__('Footer text')) ?></label>
                <input class="form-control" id="tt_footer" name="tt_footer" value="<?= e($tt['footer']) ?>" maxlength="300">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="refresh_sec"><?= e(__('Refresh every (seconds)')) ?></label>
                <input class="form-control" type="number" id="refresh_sec" name="refresh_sec" min="10" max="3600" value="<?= (int) ($s['refresh_sec'] ?? 60) ?>">
              </div>
              <?= $color('bg_color', __('Background colour'), clean_color($tt['bg_color'] ?? null, '#4A0E0E')) ?>
              <?= $color('text_color', __('Text colour'), clean_color($tt['text_color'] ?? null, '#FFF8E1')) ?>
              <?= $color('accent_color', __('Highlight colour'), clean_color($tt['accent_color'] ?? null, '#FFB300')) ?>

            <?php elseif ($type === 'clock'): ?>
              <div class="col-md-4">
                <label class="form-label" for="style"><?= e(__('Clock style')) ?></label>
                <select class="form-select" id="style" name="style">
                  <option value="digital"<?= ($s['style'] ?? '') !== 'analog' ? ' selected' : '' ?>><?= e(__('Digital')) ?></option>
                  <option value="analog"<?= ($s['style'] ?? '') === 'analog' ? ' selected' : '' ?>><?= e(__('Analog')) ?></option>
                </select>
              </div>
              <?= $color('bg_color', __('Background colour'), clean_color($s['bg_color'] ?? null, '#000000')) ?>
              <?= $color('text_color', __('Text colour'), clean_color($s['text_color'] ?? null, '#FFFFFF')) ?>
            <?php elseif ($type === 'layout'): ?>
              <?php require __DIR__ . '/partials/layout_editor.php'; // split screen editor (2.3) ?>
            <?php endif; ?>
          </div></div>
        </div>

        <div class="col-lg-4">
          <div class="card"><div class="card-body">
            <label class="form-label" for="duration"><?= e(__('Show for (seconds)')) ?></label>
            <input class="form-control" type="number" id="duration" name="duration" min="0" max="86400" value="<?= (int) $item['duration'] ?>">
            <div class="form-text mb-3"><?= e(__('Used inside playlists. 0 = until the video ends / forever. A single item assigned to a room stays on screen.')) ?></div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= (int) $item['is_active'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="is_active"><?= e(__('Active (can be shown on TVs)')) ?></label>
            </div>
          </div></div>
        </div>

        <div class="col-12 d-flex gap-2 flex-wrap">
          <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('content.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div>
      </div>
    </form>
    <?php if ($type === 'timetable'): ?>
    <template id="ttTpl">
      <div class="row g-2 mb-2 tt-row">
        <div class="col-4 col-md-3"><input class="form-control" name="tt_time[]" placeholder="06:00 - 06:30"></div>
        <div class="col-8 col-md-4"><input class="form-control" name="tt_name[]" placeholder="<?= e(__('e.g. Mangla Aarti')) ?>"></div>
        <div class="col-10 col-md-4"><input class="form-control" name="tt_note[]" placeholder="<?= e(__('Note (optional)')) ?>"></div>
        <div class="col-2 col-md-1"><button type="button" class="btn btn-outline-danger w-100 tt-del" aria-label="<?= e(__('Remove row')) ?>"><i class="bi bi-x-lg"></i></button></div>
      </div>
    </template>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const rows = document.getElementById('ttRows');
      document.getElementById('ttAdd').addEventListener('click', () => {
        rows.appendChild(document.getElementById('ttTpl').content.cloneNode(true));
        rows.lastElementChild.querySelector('input').focus();
      });
      rows.addEventListener('click', (ev) => {
        const b = ev.target.closest('.tt-del');
        if (!b) return;
        if (rows.querySelectorAll('.tt-row').length > 1) b.closest('.tt-row').remove();
        else b.closest('.tt-row').querySelectorAll('input').forEach((i) => { i.value = ''; });
      });
    });
    </script>
    <?php endif; ?>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$fType = isset(ContentManager::TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$q = req_str('q', $_GET, 100);
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'grid';
$where = ['hotel_id = :hid'];
$params = hid();
if ($fType !== '') {
    $where[] = 'type = :t';
    $params['t'] = $fType;
}
if ($q !== '') {
    $where[] = 'title LIKE :q';
    $params['q'] = '%' . $q . '%';
}
$items = DB::all('SELECT * FROM content_items' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC LIMIT 500', $params);
$counts = [];
foreach (DB::all('SELECT type, COUNT(*) AS n FROM content_items WHERE hotel_id = :hid GROUP BY type', hid()) as $c) {
    $counts[$c['type']] = (int) $c['n'];
}
$total = array_sum($counts);
$layoutUse = Layouts::usageMap(); // "used in layout X" notes (2.3)
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Content Library')) ?></h1>
    <p class="lead-sm"><?= e(__(':n items', ['n' => $total])) ?> · <?= e(__('Server upload limit: :s.', ['s' => $limitText])) ?></p>
  </div>
  <?php if ($canManage): ?>
  <div class="d-flex flex-wrap gap-2">
  <?php if (is_file(__DIR__ . '/designer.php')): // slide designer & PDF import (#11, #12) ?>
    <a class="btn btn-outline-primary btn-lg" href="<?= e(admin_url('designer.php')) ?>"><i class="bi bi-brush"></i> <?= e(__('Design a slide')) ?></a>
    <a class="btn btn-outline-primary btn-lg" href="<?= e(admin_url('pdf_import.php')) ?>"><i class="bi bi-file-earmark-pdf"></i> <?= e(__('Import PDF / slides')) ?></a>
  <?php endif; ?>
  <div class="dropdown">
    <button class="btn btn-primary btn-lg dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i> <?= e(__('Add content')) ?></button>
    <ul class="dropdown-menu dropdown-menu-end">
      <?php foreach (ContentManager::TYPES as $t => $label): ?>
        <li><a class="dropdown-item py-2" href="<?= e($t === 'app' ? admin_url('apps.php') : admin_url('content.php', ['action' => 'new', 'type' => $t])) ?>"><i class="bi <?= e(ContentManager::TYPE_ICONS[$t]) ?> me-2"></i><?= e(__($label)) ?></a></li>
      <?php endforeach; ?>
      <?php if (Auth::can('templates.manage') && Tenant::feature('templates') && is_file(__DIR__ . '/templates.php')): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item py-2" href="<?= e(admin_url('templates.php')) ?>"><i class="bi bi-palette me-2"></i><?= e(__('From a template…')) ?></a></li>
      <?php endif; ?>
    </ul>
  </div>
  </div>
  <?php endif; ?>
</div>

<div class="card card-body mb-3">
  <form method="get" class="row g-2 align-items-center">
    <div class="col-12 col-md-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search by title…')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
    <div class="col-8 col-md-4">
      <select class="form-select" name="type" aria-label="<?= e(__('Type')) ?>" onchange="this.form.submit()">
        <option value=""><?= e(__('All types')) ?> (<?= $total ?>)</option>
        <?php foreach (ContentManager::TYPES as $t => $label): ?><option value="<?= e($t) ?>"<?= $fType === $t ? ' selected' : '' ?>><?= e(__($label)) ?> (<?= $counts[$t] ?? 0 ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="col-4 col-md-3 d-flex gap-1">
      <button class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i></button>
      <a class="btn btn-light border<?= $view === 'grid' ? ' active' : '' ?>" href="<?= e(self_url(['view' => 'grid'])) ?>" title="<?= e(__('Grid')) ?>"><i class="bi bi-grid"></i></a>
      <a class="btn btn-light border<?= $view === 'list' ? ' active' : '' ?>" href="<?= e(self_url(['view' => 'list'])) ?>" title="<?= e(__('List')) ?>"><i class="bi bi-list-ul"></i></a>
    </div>
  </form>
</div>

<?php if (!$items): ?>
  <div class="card"><div class="hc-empty">
    <i class="bi bi-images"></i>
    <?php if ($total === 0): ?>
      <p class="mb-1"><strong><?= e(__('Your content library is empty')) ?></strong></p>
      <p class="text-muted"><?= e(__('Add images, videos, announcements or a temple timetable to show on your TVs.')) ?></p>
      <?php if ($canManage): ?><a class="btn btn-primary" href="<?= e(admin_url('content.php', ['action' => 'new', 'type' => 'image'])) ?>"><i class="bi bi-image"></i> <?= e(__('Add your first image')) ?></a><?php endif; ?>
    <?php else: ?>
      <p class="text-muted"><?= e(__('Nothing matches your search.')) ?></p>
    <?php endif; ?>
  </div></div>
<?php elseif ($view === 'grid'): ?>
  <div class="content-grid">
    <?php foreach ($items as $it): $thumb = ContentManager::thumbUrl($it); ?>
      <div class="card content-card<?= (int) $it['is_active'] ? '' : ' inactive' ?>">
        <a class="content-thumb" href="<?= e(admin_url('preview.php', ['content_id' => $it['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>">
          <?php if ($it['type'] === 'layout'): ?><?= Layouts::miniSvg(ContentManager::settings($it), 'w-100 h-100') ?><?php elseif ($thumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><i class="bi <?= e(ContentManager::TYPE_ICONS[$it['type']]) ?>"></i><?php endif; ?>
          <span class="badge text-bg-dark type-badge"><i class="bi <?= e(ContentManager::TYPE_ICONS[$it['type']]) ?>"></i> <?= e(__(ContentManager::TYPES[$it['type']])) ?></span>
        </a>
        <div class="card-body p-2 d-flex flex-column">
          <div class="fw-semibold text-truncate" title="<?= e($it['title']) ?>"><?= e($it['title']) ?></div>
          <?= Layouts::usageNote($layoutUse, 'c', (int) $it['id']) ?>
          <div class="small text-muted">
            <?= (int) $it['duration'] ? e((int) $it['duration'] . 's') : '∞' ?>
            <?php if ($it['file_size']): ?> · <?= e(human_bytes($it['file_size'])) ?><?php endif; ?>
            <?php if (!(int) $it['is_active']): ?> · <span class="text-danger"><?= e(__('inactive')) ?></span><?php endif; ?>
          </div>
          <div class="d-flex gap-1 mt-2">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $it['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>"><i class="bi bi-eye"></i></a>
            <?php if ($canManage): ?>
              <a class="btn btn-sm btn-primary flex-grow-1" href="<?= e(admin_url('content.php', ['action' => 'edit', 'id' => $it['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <button class="btn btn-sm btn-light border" title="<?= e((int) $it['is_active'] ? __('Deactivate') : __('Activate')) ?>"><i class="bi <?= (int) $it['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
              </form>
              <a class="btn btn-sm btn-outline-danger" href="<?= e(admin_url('content.php', ['action' => 'delete', 'id' => $it['id']])) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hc table-hover">
      <thead><tr><th></th><th><?= e(__('Title')) ?></th><th><?= e(__('Type')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Duration')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Size')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Updated')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): $thumb = ContentManager::thumbUrl($it); ?>
        <tr class="<?= (int) $it['is_active'] ? '' : 'text-muted' ?>">
          <td><?php if ($thumb): ?><img class="thumb-sm" src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-sm"><i class="bi <?= e(ContentManager::TYPE_ICONS[$it['type']]) ?>"></i></span><?php endif; ?></td>
          <td><strong><?= e($it['title']) ?></strong><?php if (!(int) $it['is_active']): ?> <span class="badge text-bg-secondary"><?= e(__('inactive')) ?></span><?php endif; ?><?= Layouts::usageNote($layoutUse, 'c', (int) $it['id']) ?></td>
          <td class="small"><?= e(__(ContentManager::TYPES[$it['type']])) ?></td>
          <td class="d-none d-md-table-cell"><?= (int) $it['duration'] ? e((int) $it['duration'] . 's') : '∞' ?></td>
          <td class="d-none d-md-table-cell small"><?= $it['file_size'] ? e(human_bytes($it['file_size'])) : '-' ?></td>
          <td class="d-none d-lg-table-cell small"><?= e(time_ago($it['updated_at'])) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $it['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>"><i class="bi bi-eye"></i></a>
            <?php if ($canManage): ?>
              <a class="btn btn-sm btn-primary" href="<?= e(admin_url('content.php', ['action' => 'edit', 'id' => $it['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
              <?php if (!$it['file_path']): ?>
              <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="duplicate"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <button class="btn btn-sm btn-light border" title="<?= e(__('Duplicate')) ?>"><i class="bi bi-copy"></i></button></form>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-danger" href="<?= e(admin_url('content.php', ['action' => 'delete', 'id' => $it['id']])) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
