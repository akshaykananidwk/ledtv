<?php
declare(strict_types=1);
/**
 * Photo albums (#19, display app "photo_album"): mobile-first page to create albums, upload many
 * photos from the phone gallery / camera one by one with progress (assets/js/album_upload.js resizes
 * on the phone, Uploader re-encodes on the server; HEIC is refused with a clear message), captions,
 * delete, reorder, approve guest photos and manage the public guest upload link (signed URL + QR,
 * on / off, moderation, maximum photos). Permission albums.manage (staff+). TVs update within 30 s.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('albums.manage');
if (post_too_large()) {
    $msg = __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]);
    if (Auth::isAjax()) {
        ajax_error($msg, 413, 'TOO_LARGE');
    }
    flash('danger', $msg);
    redirect(admin_url('album_upload.php'));
}
Csrf::check();

$albumUrl = static fn (int $id, string $anchor = ''): string => admin_url('album_upload.php', ['album' => $id]) . $anchor;
$formErrors = [];
$formAlbum = null;

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $ajax = Auth::isAjax();
    $albumId = req_int('album_id', $_POST);
    $album = $albumId ? Albums::find($albumId) : null; // another hotel's id → 404
    if ($albumId && !$album) {
        if ($ajax) {
            ajax_error(__('Album not found.'), 404, 'NOT_FOUND');
        }
        flash('warning', __('Album not found.'));
        redirect(admin_url('album_upload.php'));
    }
    $photoId = req_int('photo_id', $_POST);
    $photo = $photoId ? Albums::photo($photoId) : null;
    if ($photoId && (!$photo || !$album || (int) $photo['album_id'] !== (int) $album['id'])) {
        if ($ajax) {
            ajax_error(__('Photo not found.'), 404, 'NOT_FOUND');
        }
        flash('warning', __('Photo not found.'));
        redirect($album ? $albumUrl((int) $album['id']) : admin_url('album_upload.php'));
    }

    switch ($op) {
        case 'album_save':
            [$data, $formErrors] = Albums::validate($_POST);
            if ($formErrors) {
                http_response_code(422);
                $formAlbum = array_replace($album ?? ['id' => 0, 'guest_key' => ''], $data);
                break;
            }
            if ($album) {
                Albums::update((int) $album['id'], $data);
                $id = (int) $album['id'];
            } else {
                $id = Albums::create($data);
            }
            ActivityLog::add($album ? 'album_update' : 'album_create', 'album', $id, mb_substr($data['name'], 0, 120));
            flash('success', __('Album ":t" saved.', ['t' => $data['name']]));
            redirect($albumUrl($id));

        case 'album_delete':
            if ($album) {
                Albums::delete((int) $album['id']);
                ActivityLog::add('album_delete', 'album', (int) $album['id'], mb_substr((string) $album['name'], 0, 120));
                flash('success', __('Album ":t" deleted.', ['t' => $album['name']]));
            }
            redirect(admin_url('album_upload.php'));

        case 'guest_rotate':
            if ($album) {
                Albums::rotateGuestKey((int) $album['id']);
                ActivityLog::add('album_guest_link', 'album', (int) $album['id'], 'new guest link');
                flash('success', __('New guest link created. The old link and QR code no longer work.'));
                redirect($albumUrl((int) $album['id'], '#guest'));
            }
            redirect(admin_url('album_upload.php'));

        case 'upload':
            if (!$album) {
                $ajax ? ajax_error(__('Choose an album first.'), 422) : redirect(admin_url('album_upload.php'));
            }
            $file = $_FILES['photo'] ?? null;
            try {
                if (!is_array($file)) {
                    throw new RuntimeException(__('No file was selected.'));
                }
                $newId = Albums::addPhoto((int) $album['id'], $file, ['caption' => req_str('caption', $_POST, 190), 'source' => 'staff']);
            } catch (RuntimeException $e) {
                if ($ajax) {
                    ajax_error($e->getMessage(), 422, 'UPLOAD');
                }
                flash('danger', $e->getMessage());
                redirect($albumUrl((int) $album['id']));
            }
            ActivityLog::add('album_photo_upload', 'album', (int) $album['id'], 'photo #' . $newId);
            if ($ajax) {
                ajax_ok(['id' => $newId, 'status' => 'approved', 'message' => __('Uploaded')]);
            }
            flash('success', __('Photo uploaded.'));
            redirect($albumUrl((int) $album['id']));

        case 'photo_caption':
            if ($photo) {
                Albums::updateCaption((int) $photo['id'], req_str('caption', $_POST, 190));
            }
            if ($ajax) {
                ajax_ok();
            }
            redirect($albumUrl((int) $album['id'], '#p' . $photoId));

        case 'photo_approve':
            if ($photo) {
                Albums::approve((int) $photo['id']);
                ActivityLog::add('album_photo_approve', 'album', (int) $album['id'], 'photo #' . $photo['id']);
            }
            if ($ajax) {
                ajax_ok();
            }
            redirect($albumUrl((int) $album['id'], '#pending'));

        case 'approve_all':
            if ($album) {
                foreach (Albums::photos((int) $album['id'], 'pending') as $p) {
                    Albums::approve((int) $p['id']);
                }
                ActivityLog::add('album_photo_approve', 'album', (int) $album['id'], 'all pending');
                flash('success', __('All waiting photos are approved.'));
                redirect($albumUrl((int) $album['id']));
            }
            redirect(admin_url('album_upload.php'));

        case 'photo_delete':
            if ($photo) {
                Albums::deletePhoto((int) $photo['id']);
                ActivityLog::add('album_photo_delete', 'album', (int) $album['id'], 'photo #' . $photo['id']);
            }
            if ($ajax) {
                ajax_ok();
            }
            flash('success', __('Photo deleted.'));
            redirect($albumUrl((int) $album['id'], $photo && $photo['status'] === 'pending' ? '#pending' : '#photos'));

        case 'move':
            if ($photo) {
                $ids = array_map('intval', array_column(Albums::photos((int) $album['id'], 'approved'), 'id'));
                $pos = array_search((int) $photo['id'], $ids, true);
                $to = req_str('dir', $_POST, 5) === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && $to >= 0 && $to < count($ids)) {
                    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                    Albums::reorder((int) $album['id'], $ids);
                }
            }
            redirect($albumUrl((int) $album['id'], '#p' . $photoId));

        case 'reorder':
            if ($album) {
                Albums::reorder((int) $album['id'], int_ids($_POST['ids'] ?? []));
            }
            if ($ajax) {
                ajax_ok();
            }
            redirect($album ? $albumUrl((int) $album['id']) : admin_url('album_upload.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('album_upload.php'));
    }
}

$activeNav = 'albums';
$pageTitle = __('Photo albums');
$extraScripts = ['js/album_upload.js'];
$viewId = $formAlbum !== null ? (int) $formAlbum['id'] : req_int('album', $_GET);
$canApps = Auth::can('content.manage');

// ---------------------------------------------------------------- one album
if ($viewId > 0) {
    $album = $viewId > 0 ? Albums::find($viewId) : null;
    if (!$album) {
        flash('warning', __('Album not found.'));
        redirect(admin_url('album_upload.php'));
    }
    $edit = $formAlbum ?? $album;
    $photos = Albums::photos((int) $album['id'], 'approved');
    $pending = Albums::photos((int) $album['id'], 'pending');
    $guestUrl = Albums::guestUrl($album);
    $remaining = Albums::guestRemaining($album);
    $pageTitle = (string) $album['name'];
    $i18n = [
        'uploading' => __('Uploading…'), 'done' => __('Uploaded'), 'failed' => __('Failed'), 'waiting' => __('Waiting…'),
        'heic' => __('HEIC photos (iPhone format) are not supported. On the iPhone choose Settings → Camera → Formats → Most Compatible, or share the photo as JPG.'),
        'not_image' => __('Only JPG, PNG, GIF or WEBP images are allowed.'), 'summary' => __(':ok of :n photos uploaded.'),
        'network' => __('Network error. Check the connection and try again.'),
    ];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-images"></i> <?= e($album['name']) ?></h1>
        <p class="lead-sm"><?= e(__(':n photos on the TV', ['n' => count($photos)])) ?><?= $pending ? ' · ' . e(__(':n waiting for approval', ['n' => count($pending)])) : '' ?></p></div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(admin_url('album_upload.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('All albums')) ?></a>
        <?php if ($canApps): ?><a class="btn btn-light border" href="<?= e(admin_url('apps.php', ['action' => 'new', 'app' => 'photo_album'])) ?>"><i class="bi bi-tv"></i> <?= e(__('Show on TV')) ?></a><?php endif; ?>
      </div>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body">
      <div data-album-uploader data-url="<?= e(admin_url('album_upload.php')) ?>" data-csrf="<?= e(Csrf::token()) ?>" data-album="<?= (int) $album['id'] ?>" data-reload="1"
           data-i18n="<?= e(json_out($i18n)) ?>">
        <div class="row g-2">
          <div class="col-6"><label class="btn btn-primary btn-lg w-100 py-3"><i class="bi bi-images"></i> <?= e(__('Choose photos')) ?>
            <input type="file" class="d-none" data-album-files accept="image/jpeg,image/png,image/gif,image/webp,image/*" multiple></label></div>
          <div class="col-6"><label class="btn btn-outline-primary btn-lg w-100 py-3"><i class="bi bi-camera"></i> <?= e(__('Take a photo')) ?>
            <input type="file" class="d-none" data-album-files accept="image/*" capture="environment"></label></div>
          <div class="col-12"><input class="form-control" name="caption" data-album-caption maxlength="190" placeholder="<?= e(__('Caption for these photos (optional)')) ?>"></div>
        </div>
        <div class="form-text"><?= e(__('Photos are made smaller on the phone before upload and appear on the TVs within 30 seconds. HEIC (iPhone) photos are not supported.')) ?></div>
        <div class="mt-2" data-album-list></div>
      </div>
      <noscript>
        <form method="post" enctype="multipart/form-data" class="mt-2"><?= Csrf::field() ?><input type="hidden" name="op" value="upload"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
          <input type="file" name="photo" accept="image/*" class="form-control mb-2"><button class="btn btn-primary"><?= e(__('Upload')) ?></button></form>
      </noscript>
    </div></div>

    <?php if ($pending): ?>
    <div class="card mb-3 border-warning" id="pending"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <strong><i class="bi bi-hourglass-split"></i> <?= e(__('Waiting for approval (:n)', ['n' => count($pending)])) ?></strong>
      <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="approve_all"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
        <button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> <?= e(__('Approve all')) ?></button></form>
    </div><div class="card-body"><div class="row g-2">
      <?php foreach ($pending as $p): ?>
        <div class="col-6 col-md-3" data-pending="<?= (int) $p['id'] ?>"><div class="border rounded p-1 h-100">
          <img src="<?= e(Albums::photoUrl($p, true)) ?>" alt="" class="w-100 rounded" style="aspect-ratio:4/3;object-fit:cover">
          <div class="small mt-1 text-truncate"><?= e($p['guest_name'] ?: __('Guest')) ?><?= $p['caption'] ? ' · ' . e($p['caption']) : '' ?></div>
          <div class="d-flex gap-1 mt-1">
            <form method="post" class="flex-fill"><?= Csrf::field() ?><input type="hidden" name="op" value="photo_approve"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-success w-100" title="<?= e(__('Approve')) ?>"><i class="bi bi-check-lg"></i> <?= e(__('Approve')) ?></button></form>
            <form method="post" data-confirm="<?= e(__('Delete this photo?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="photo_delete"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Reject')) ?>"><i class="bi bi-x-lg"></i></button></form>
          </div>
        </div></div>
      <?php endforeach; ?>
    </div></div></div>
    <?php endif; ?>

    <div class="card mb-3" id="photos"><div class="card-header"><strong><?= e(__('Photos (:n)', ['n' => count($photos)])) ?></strong></div><div class="card-body">
      <?php if (!$photos): ?>
        <p class="text-muted mb-0"><?= e(__('No photos yet. Add the first ones above.')) ?></p>
      <?php else: ?>
      <div class="row g-2">
        <?php foreach ($photos as $i => $p): ?>
          <div class="col-6 col-md-4 col-lg-3" id="p<?= (int) $p['id'] ?>" data-photo="<?= (int) $p['id'] ?>"><div class="border rounded p-1 h-100">
            <img src="<?= e(Albums::photoUrl($p, true)) ?>" alt="" class="w-100 rounded" style="aspect-ratio:4/3;object-fit:cover" loading="lazy">
            <form method="post" class="mt-1 d-flex gap-1"><?= Csrf::field() ?><input type="hidden" name="op" value="photo_caption"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
              <input class="form-control form-control-sm" name="caption" value="<?= e((string) $p['caption']) ?>" maxlength="190" placeholder="<?= e(__('Caption')) ?>">
              <button class="btn btn-sm btn-light border" title="<?= e(__('Save')) ?>"><i class="bi bi-check-lg"></i></button></form>
            <div class="d-flex gap-1 mt-1">
              <?php foreach (['up' => 'bi-arrow-left', 'down' => 'bi-arrow-right'] as $dir => $icon): ?>
                <form method="post" class="flex-fill"><?= Csrf::field() ?><input type="hidden" name="op" value="move"><input type="hidden" name="dir" value="<?= e($dir) ?>"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-sm btn-light border w-100" title="<?= e($dir === 'up' ? __('Move earlier') : __('Move later')) ?>"<?= ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($photos) - 1) ? ' disabled' : '' ?>><i class="bi <?= e($icon) ?>"></i></button></form>
              <?php endforeach; ?>
              <form method="post" data-confirm="<?= e(__('Delete this photo?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="photo_delete"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
            </div>
            <?php if ($p['source'] === 'guest'): ?><div class="small text-muted mt-1"><i class="bi bi-person"></i> <?= e($p['guest_name'] ?: __('Guest')) ?></div><?php endif; ?>
          </div></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>

    <div class="row g-3">
      <div class="col-lg-6"><div class="card h-100"><div class="card-header"><strong><?= e(__('Album settings')) ?></strong></div><div class="card-body">
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="album_save"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
          <div class="mb-3"><label class="form-label" for="a_name"><?= e(__('Album name')) ?> *</label>
            <input class="form-control" id="a_name" name="name" value="<?= e($edit['name']) ?>" maxlength="120" required></div>
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="a_guest" name="guest_upload" value="1"<?= (int) $edit['guest_upload'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="a_guest"><?= e(__('Guests may upload photos with a link / QR code')) ?></label></div>
          <input type="hidden" name="guest_moderation" value="0"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="a_mod" name="guest_moderation" value="1"<?= (int) $edit['guest_moderation'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="a_mod"><?= e(__('Guest photos wait for approval before they appear on the TV')) ?></label></div>
          <div class="mb-3"><label class="form-label" for="a_max"><?= e(__('Maximum guest photos')) ?></label>
            <input class="form-control" type="number" id="a_max" name="guest_max" min="1" max="<?= Albums::GUEST_MAX_LIMIT ?>" value="<?= (int) $edit['guest_max'] ?>"></div>
          <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
        </form>
        <form method="post" class="mt-3" data-confirm="<?= e(__('Delete album ":t" and all its photos?', ['t' => $album['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="album_delete"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> <?= e(__('Delete album')) ?></button></form>
      </div></div></div>
      <div class="col-lg-6" id="guest"><div class="card h-100"><div class="card-header"><strong><i class="bi bi-qr-code"></i> <?= e(__('Guest upload link')) ?></strong></div><div class="card-body">
        <?php if (!(int) $album['guest_upload']): ?>
          <p class="text-muted"><?= e(__('Switched off. Turn on "Guests may upload photos" to share a link and QR code, e.g. for a wedding.')) ?></p>
        <?php else: ?>
          <div class="d-flex gap-3 flex-wrap align-items-start">
            <div style="width:160px;background:#fff" class="border rounded p-1"><?= QrCode::svg($guestUrl) ?></div>
            <div class="min-w-0 flex-fill">
              <input class="form-control form-control-sm mb-2" readonly value="<?= e($guestUrl) ?>" onclick="this.select()" data-guest-url>
              <div class="small text-muted mb-2"><?= e((int) $album['guest_moderation'] ? __('Guest photos wait for your approval.') : __('Guest photos appear on the TV immediately.')) ?>
                <?= e(__(':n more guest photos allowed.', ['n' => $remaining])) ?></div>
              <form method="post" data-confirm="<?= e(__('Create a new link? The old link and QR code stop working.')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="guest_rotate"><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
                <button class="btn btn-sm btn-light border"><i class="bi bi-arrow-repeat"></i> <?= e(__('New link')) ?></button></form>
            </div>
          </div>
        <?php endif; ?>
      </div></div></div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- album list
$albums = Albums::all();
$new = $formAlbum ?? ['name' => '', 'guest_upload' => 0, 'guest_moderation' => 1, 'guest_max' => 100];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-images"></i> <?= e(__('Photo albums')) ?></h1>
    <p class="lead-sm"><?= e(__('Upload photos from your phone and show them as a slideshow on the TVs (Photo album app).')) ?></p>
  </div>
  <?php if ($canApps): ?><a class="btn btn-light border" href="<?= e(admin_url('apps.php', ['action' => 'new', 'app' => 'photo_album'])) ?>"><i class="bi bi-tv"></i> <?= e(__('Show on TV')) ?></a><?php endif; ?>
</div>
<?php if ($formErrors): ?>
  <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="op" value="album_save"><input type="hidden" name="album_id" value="0">
    <input type="hidden" name="guest_max" value="100">
    <div class="col-12 col-md-8"><label class="form-label" for="new_name"><?= e(__('New album')) ?></label>
      <input class="form-control form-control-lg" id="new_name" name="name" value="<?= e($new['name']) ?>" maxlength="120" required placeholder="<?= e(__('e.g. Wedding of Riya & Aarav')) ?>"></div>
    <div class="col-12 col-md-4"><button class="btn btn-primary btn-lg w-100"><i class="bi bi-plus-lg"></i> <?= e(__('Create album')) ?></button></div>
  </form>
</div></div>
<?php if (!$albums): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-images"></i><p class="text-muted"><?= e(__('No albums yet. Create the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($albums as $a): $cover = Albums::photos((int) $a['id'], 'approved', 1)[0] ?? null; ?>
      <div class="col-12 col-sm-6 col-lg-4" data-album="<?= (int) $a['id'] ?>"><a class="card h-100 text-decoration-none text-reset" href="<?= e($albumUrl((int) $a['id'])) ?>">
        <?php if ($cover): ?><img src="<?= e(Albums::photoUrl($cover, true)) ?>" alt="" class="card-img-top" style="aspect-ratio:16/9;object-fit:cover"><?php else: ?>
          <div class="card-img-top bg-light d-flex align-items-center justify-content-center text-muted" style="aspect-ratio:16/9"><i class="bi bi-images fs-1"></i></div><?php endif; ?>
        <div class="card-body"><strong><?= e($a['name']) ?></strong>
          <div class="small text-muted"><?= e(__(':n photos', ['n' => (int) $a['photos']])) ?>
            <?php if ((int) $a['pending']): ?> · <span class="badge text-bg-warning"><?= e(__(':n waiting', ['n' => (int) $a['pending']])) ?></span><?php endif; ?>
            <?php if ((int) $a['guest_upload']): ?> · <i class="bi bi-qr-code"></i> <?= e(__('Guest link on')) ?><?php endif; ?></div>
        </div></a></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
