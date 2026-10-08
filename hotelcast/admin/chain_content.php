<?php
declare(strict_types=1);
/**
 * Chain content library (#20): content items and playlists created once for the whole chain and
 * "published" (copied) into selected hotels. Uploaded media files are copied into each hotel's
 * uploads/h{id}/; re-publishing updates the copies (chain_publications keeps chain id → hotel id).
 *   chain_content.php?chain=ID[&tab=playlists][&action=new|edit|publish|playlist|…]
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

[$user, $chain] = Chains::page();
$cid = (int) $chain['id'];
$cq = ['chain' => $cid];
const CHAIN_TYPES = ['image', 'video', 'announcement', 'youtube', 'url', 'stream', 'html', 'clock'];

if (post_too_large()) {
    flash('danger', __('File is larger than the server upload limit (:s).', ['s' => ini_get('upload_max_filesize')]));
    redirect(admin_url('chain_content.php', $cq));
}

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $id = req_int('id', $_POST);
    switch ($op) {
        case 'save_content':
            $existing = $id ? Chains::contentItem($cid, $id) : null;
            if (!$existing && !in_array(req_str('type', $_POST, 20), CHAIN_TYPES, true)) {
                flash('danger', __('Invalid content type.'));
                redirect(admin_url('chain_content.php', $cq));
            }
            [$newId, $errors] = Chains::saveContent($cid, $existing, $_POST, $_FILES['file'] ?? null, Auth::id());
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('chain_content.php', $cq + ($existing ? ['action' => 'edit', 'id' => $id] : ['action' => 'new', 'type' => req_str('type', $_POST, 20)])));
            }
            $pub = Chains::publications($cid, 'content', (int) $newId);
            flash('success', __('Saved.') . ($pub ? ' ' . __('Publish again to update the copies at :n customers.', ['n' => count($pub)]) : ''));
            redirect(admin_url('chain_content.php', $cq + ['action' => 'publish', 'type' => 'content', 'id' => $newId]));

        case 'delete_content':
            Chains::deleteContent($cid, $id);
            flash('success', __('Deleted from the chain library. Copies at the customers stay until a customer deletes them.'));
            redirect(admin_url('chain_content.php', $cq));

        case 'save_playlist':
            $existing = $id ? Chains::playlist($cid, $id) : null;
            // Items ticked in the form, ordered by their position number.
            $order = (array) ($_POST['pos'] ?? []);
            $picked = int_ids($_POST['items'] ?? []);
            usort($picked, static fn ($a, $b) => ((int) ($order[$a] ?? 999)) <=> ((int) ($order[$b] ?? 999)));
            $durations = [];
            foreach ($picked as $i => $c) {
                $durations[$i] = $_POST['dur'][$c] ?? '';
            }
            [$newId, $errors] = Chains::savePlaylist($cid, $existing, ['name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '',
                'transition' => $_POST['transition'] ?? 'fade', 'items' => $picked, 'durations' => $durations], Auth::id());
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('chain_content.php', $cq + ['action' => 'playlist', 'id' => $id]));
            }
            flash('success', __('Saved.'));
            redirect(admin_url('chain_content.php', $cq + ['action' => 'publish', 'type' => 'playlist', 'id' => $newId]));

        case 'delete_playlist':
            Chains::deletePlaylist($cid, $id);
            flash('success', __('Deleted from the chain library. Copies at the customers stay until a customer deletes them.'));
            redirect(admin_url('chain_content.php', $cq + ['tab' => 'playlists']));

        case 'publish':
            $type = req_str('type', $_POST, 10) === 'playlist' ? 'playlist' : 'content';
            $hotels = int_ids($_POST['hotels'] ?? []);
            if (!$hotels) {
                flash('warning', __('Select at least one customer.'));
                redirect(admin_url('chain_content.php', $cq + ['action' => 'publish', 'type' => $type, 'id' => $id]));
            }
            $res = Chains::publish($cid, $type, $id, $hotels, Auth::id());
            $bad = array_filter($res, static fn ($r) => in_array($r['status'], ['error', 'skipped'], true));
            flash($bad ? 'warning' : 'success', __('Published: :s', ['s' => Chains::summarize($res)]));
            if ($bad) {
                $names = array_column(Chains::hotels($cid), 'name', 'id');
                flash_errors(array_map(static fn ($hid, $r) => ($names[$hid] ?? '#' . $hid) . ': ' . $r['message'], array_keys($bad), $bad));
            }
            redirect(admin_url('chain_content.php', $cq + ['action' => 'publish', 'type' => $type, 'id' => $id]));
    }
    redirect(admin_url('chain_content.php', $cq));
}

$action = req_str('action', $_GET, 20);
$tab = req_str('tab', $_GET, 20) === 'playlists' ? 'playlists' : 'content';
$pageTitle = __('Chain content');
$activeNav = 'chain_content';
$extraScripts = ['js/chain.js'];
$hotels = Chains::hotels($cid);

// ---------------------------------------------------------------- content form
if ($action === 'new' || $action === 'edit') {
    if ($action === 'edit') {
        $item = Chains::contentItem($cid, req_int('id', $_GET));
        $type = (string) $item['type'];
    } else {
        $type = req_str('type', $_GET, 20);
        if (!in_array($type, CHAIN_TYPES, true)) {
            $type = 'announcement';
        }
        $item = ['id' => 0, 'title' => '', 'type' => $type, 'url' => '', 'body' => '', 'settings' => null, 'duration' => 10, 'is_active' => 1, 'file_path' => null, 'thumb_path' => null];
    }
    $s = ContentManager::settings($item);
    require __DIR__ . '/partials/header.php';
    $color = static fn (string $name, string $label, string $value) => '<div class="col-6 col-md-3"><label class="form-label" for="c_' . e($name) . '">' . e($label) . '</label>'
        . '<input type="color" class="form-control form-control-color w-100" id="c_' . e($name) . '" name="' . e($name) . '" value="' . e($value) . '"></div>';
    ?>
    <div class="page-head">
      <h1><i class="bi <?= e(ContentManager::TYPE_ICONS[$type] ?? 'bi-file') ?>"></i> <?= e($item['id'] ? __('Edit chain content') : __('New chain content')) ?> · <?= e(__(ContentManager::TYPES[$type])) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('chain_content.php', $cq)) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" enctype="multipart/form-data" class="card card-body">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save_content"><input type="hidden" name="chain" value="<?= $cid ?>">
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="type" value="<?= e($type) ?>">
      <div class="row g-3">
        <div class="col-12"><label class="form-label" for="title"><?= e(__('Title')) ?> *</label>
          <input class="form-control" id="title" name="title" value="<?= e($item['title']) ?>" required maxlength="190"></div>
        <?php if ($type === 'image' || $type === 'video'): ?>
          <div class="col-md-6"><label class="form-label" for="file"><?= e(__('Upload file')) ?></label>
            <input class="form-control" type="file" id="file" name="file" accept="<?= $type === 'image' ? 'image/jpeg,image/png,image/gif,image/webp' : 'video/mp4,video/webm,video/x-matroska,video/quicktime,video/3gpp,.mkv,.mov,.3gp,.m4v' ?>">
            <?php if ($item['file_path']): ?><div class="form-text"><i class="bi bi-paperclip"></i> <?= e(basename((string) $item['file_path'])) ?> — <?= e(__('upload a new file to replace it')) ?></div><?php endif; ?>
            <div class="form-text"><?= e(__('Server upload limit: :s', ['s' => upload_limit() ? round(upload_limit() / 1048576) . ' MB' : '?'])) ?></div></div>
          <div class="col-md-6"><label class="form-label" for="url"><?= e(__('… or a URL')) ?></label>
            <input class="form-control" type="url" id="url" name="url" value="<?= e($item['file_path'] ? '' : (string) $item['url']) ?>" maxlength="1000" placeholder="https://">
            <?php if ($item['file_path']): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="use_url" value="1" id="use_url"><label class="form-check-label small" for="use_url"><?= e(__('Use the URL instead of the uploaded file')) ?></label></div><?php endif; ?></div>
          <?php if ($type === 'video'): ?>
            <div class="col-12"><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="loop" name="loop" value="1"<?= ($s['loop'] ?? true) ? ' checked' : '' ?>><label class="form-check-label" for="loop"><?= e(__('Loop video')) ?></label></div>
              <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="mute" name="mute" value="1"<?= !empty($s['mute']) ? ' checked' : '' ?>><label class="form-check-label" for="mute"><?= e(__('Mute sound')) ?></label></div></div>
          <?php endif; ?>
          <?php if ($item['file_path'] && $type === 'image'): ?><div class="col-12"><img src="<?= e(media_url((string) ($item['thumb_path'] ?: $item['file_path']))) ?>" alt="" style="max-height:120px" class="rounded border"></div><?php endif; ?>
        <?php elseif (in_array($type, ['youtube', 'url', 'stream'], true)): ?>
          <div class="col-12"><label class="form-label" for="url"><?= e($type === 'stream' ? __('Stream URL') : __('URL')) ?> *</label>
            <input class="form-control" id="url" name="url" value="<?= e((string) $item['url']) ?>" required maxlength="1000" placeholder="https://"></div>
          <?php if ($type === 'stream'): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="mute" name="mute" value="1"<?= !empty($s['mute']) ? ' checked' : '' ?>><label class="form-check-label" for="mute"><?= e(__('Mute sound')) ?></label></div></div><?php endif; ?>
        <?php elseif ($type === 'announcement'): ?>
          <div class="col-12"><label class="form-label" for="body"><?= e(__('Text')) ?> *</label><textarea class="form-control" id="body" name="body" rows="3" required maxlength="5000"><?= e((string) $item['body']) ?></textarea></div>
          <div class="col-md-6"><label class="form-label" for="subtitle"><?= e(__('Subtitle')) ?></label><input class="form-control" id="subtitle" name="subtitle" value="<?= e($s['subtitle'] ?? '') ?>" maxlength="500"></div>
          <div class="col-md-3"><label class="form-label" for="style"><?= e(__('Style')) ?></label><select class="form-select" id="style" name="style">
            <option value="fullscreen"><?= e(__('Full screen')) ?></option><option value="marquee"<?= ($s['style'] ?? '') === 'marquee' ? ' selected' : '' ?>><?= e(__('Scrolling text')) ?></option></select></div>
          <div class="col-md-3"><label class="form-label" for="font_size"><?= e(__('Font size')) ?></label><input class="form-control" type="number" id="font_size" name="font_size" min="12" max="200" value="<?= (int) ($s['font_size'] ?? 48) ?>"></div>
          <?= $color('bg_color', __('Background'), clean_color($s['bg_color'] ?? null, '#1A237E')) ?>
          <?= $color('text_color', __('Text colour'), clean_color($s['text_color'] ?? null, '#FFFFFF')) ?>
        <?php elseif ($type === 'html'): ?>
          <div class="col-12"><label class="form-label" for="body"><?= e(__('HTML')) ?> *</label><textarea class="form-control font-monospace" id="body" name="body" rows="10" required spellcheck="false"><?= e((string) $item['body']) ?></textarea></div>
        <?php elseif ($type === 'clock'): ?>
          <div class="col-md-4"><label class="form-label" for="style"><?= e(__('Style')) ?></label><select class="form-select" id="style" name="style">
            <option value="digital"><?= e(__('Digital')) ?></option><option value="analog"<?= ($s['style'] ?? '') === 'analog' ? ' selected' : '' ?>><?= e(__('Analog')) ?></option></select></div>
          <?= $color('bg_color', __('Background'), clean_color($s['bg_color'] ?? null, '#000000')) ?>
          <?= $color('text_color', __('Text colour'), clean_color($s['text_color'] ?? null, '#FFFFFF')) ?>
        <?php endif; ?>
        <div class="col-6 col-md-3"><label class="form-label" for="duration"><?= e(__('Duration (seconds)')) ?></label>
          <input class="form-control" type="number" id="duration" name="duration" min="0" max="86400" value="<?= (int) $item['duration'] ?>"></div>
        <div class="col-6 col-md-3 d-flex align-items-end"><input type="hidden" name="is_active" value="0">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= (int) $item['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="is_active"><?= e(__('Active')) ?></label></div></div>
      </div>
      <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save and choose customers')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- playlist form
if ($action === 'playlist') {
    $pl = req_int('id', $_GET) ? Chains::playlist($cid, req_int('id', $_GET)) : ['id' => 0, 'name' => '', 'description' => '', 'transition' => 'fade'];
    $cur = [];
    foreach ($pl['id'] ? Chains::playlistItems((int) $pl['id']) : [] as $i => $it) {
        $cur[(int) $it['content_id']] ??= ['pos' => $i + 1, 'dur' => $it['duration']];
    }
    $items = Chains::contentItems($cid);
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-collection-play"></i> <?= e($pl['id'] ? __('Edit chain playlist') : __('New chain playlist')) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('chain_content.php', $cq + ['tab' => 'playlists'])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card card-body">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save_playlist"><input type="hidden" name="chain" value="<?= $cid ?>"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="pname"><?= e(__('Name')) ?> *</label><input class="form-control" id="pname" name="name" value="<?= e($pl['name']) ?>" required maxlength="190"></div>
        <div class="col-md-3"><label class="form-label" for="ptr"><?= e(__('Transition')) ?></label><select class="form-select" id="ptr" name="transition">
          <?php foreach (['fade' => __('Fade'), 'slide' => __('Slide'), 'none' => __('None')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $pl['transition'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="pdesc"><?= e(__('Description')) ?></label><input class="form-control" id="pdesc" name="description" value="<?= e((string) $pl['description']) ?>" maxlength="500"></div>
        <div class="col-12"><label class="form-label"><?= e(__('Items (tick, then set the order)')) ?></label>
          <?php if (!$items): ?><div class="text-muted small"><?= e(__('Add chain content first.')) ?></div><?php endif; ?>
          <div class="list-group">
          <?php foreach ($items as $it): $c = $cur[(int) $it['id']] ?? null; ?>
            <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
              <input class="form-check-input" type="checkbox" name="items[]" value="<?= (int) $it['id'] ?>" id="pi<?= (int) $it['id'] ?>"<?= $c ? ' checked' : '' ?>>
              <label class="form-check-label flex-grow-1 min-w-0 text-truncate" for="pi<?= (int) $it['id'] ?>"><i class="bi <?= e(ContentManager::TYPE_ICONS[$it['type']] ?? 'bi-file') ?>"></i> <?= e($it['title']) ?></label>
              <input class="form-control form-control-sm" style="width:4.5rem" type="number" min="1" name="pos[<?= (int) $it['id'] ?>]" value="<?= $c ? (int) $c['pos'] : '' ?>" aria-label="<?= e(__('Order')) ?>" placeholder="#">
              <input class="form-control form-control-sm" style="width:6rem" type="number" min="0" name="dur[<?= (int) $it['id'] ?>]" value="<?= $c && $c['dur'] !== null ? (int) $c['dur'] : '' ?>" aria-label="<?= e(__('Seconds')) ?>" placeholder="<?= e(__('sec')) ?>">
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save and choose customers')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- publish
if ($action === 'publish') {
    $type = req_str('type', $_GET, 10) === 'playlist' ? 'playlist' : 'content';
    $src = $type === 'playlist' ? Chains::playlist($cid, req_int('id', $_GET)) : Chains::contentItem($cid, req_int('id', $_GET));
    $pub = Chains::publications($cid, $type, (int) $src['id']);
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div class="min-w-0"><h1 class="text-truncate"><i class="bi bi-send"></i> <?= e(__('Publish to customers')) ?></h1>
        <p class="lead-sm mb-0"><?= e($type === 'playlist' ? '▶ ' . $src['name'] : $src['title']) ?></p></div>
      <a class="btn btn-light border" href="<?= e(admin_url('chain_content.php', $cq + ($type === 'playlist' ? ['tab' => 'playlists'] : []))) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card">
      <?= Csrf::field() ?><input type="hidden" name="op" value="publish"><input type="hidden" name="chain" value="<?= $cid ?>">
      <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= (int) $src['id'] ?>">
      <div class="card-header d-flex align-items-center gap-2"><span><?= e(__('Customers')) ?></span>
        <button type="button" class="btn btn-sm btn-light border ms-auto" data-check-all="hotels[]"><?= e(__('Select all')) ?></button></div>
      <ul class="list-group list-group-flush">
        <?php if (!$hotels): ?><li class="list-group-item text-muted"><?= e(__('No customers in this chain yet.')) ?></li><?php endif; ?>
        <?php foreach ($hotels as $h): $st = Tenant::state((int) $h['id']); ?>
          <li class="list-group-item d-flex align-items-center gap-2">
            <input class="form-check-input" type="checkbox" name="hotels[]" value="<?= (int) $h['id'] ?>" id="ph<?= (int) $h['id'] ?>"<?= isset($pub[(int) $h['id']]) || !$pub ? ' checked' : '' ?><?= $st !== 'active' ? ' disabled' : '' ?>>
            <label class="form-check-label flex-grow-1" for="ph<?= (int) $h['id'] ?>"><?= e($h['name']) ?> <span class="small text-muted"><?= e((string) $h['city']) ?></span></label>
            <?= $st !== 'active' ? Hotels::statusBadge($st) : '' ?>
            <?= isset($pub[(int) $h['id']]) ? '<span class="badge text-bg-success"><i class="bi bi-check2"></i> ' . e(__('Published')) . ' #' . (int) $pub[(int) $h['id']] . '</span>' : '<span class="badge text-bg-light border">' . e(__('Not yet')) . '</span>' ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('Publishing copies the item (and its media file) to each customer. Publishing again updates the copies; customers can then use it like their own content.')) ?></p>
        <button class="btn btn-primary"<?= $hotels ? '' : ' disabled' ?>><i class="bi bi-send"></i> <?= e(__('Publish to selected customers')) ?></button>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- lists
$items = Chains::contentItems($cid);
$playlists = Chains::playlists($cid);
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div class="min-w-0"><h1 class="text-truncate"><i class="bi bi-collection-play"></i> <?= e(__('Chain content')) ?></h1>
    <p class="lead-sm mb-0"><?= e($chain['name']) ?> · <?= e(__('Create once, publish to every customer of the chain.')) ?></p></div>
  <div class="d-flex flex-wrap gap-2">
    <div class="dropdown">
      <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i> <?= e(__('Add content')) ?></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php foreach (CHAIN_TYPES as $t): ?><li><a class="dropdown-item" href="<?= e(admin_url('chain_content.php', $cq + ['action' => 'new', 'type' => $t])) ?>"><i class="bi <?= e(ContentManager::TYPE_ICONS[$t]) ?> me-2"></i><?= e(__(ContentManager::TYPES[$t])) ?></a></li><?php endforeach; ?>
      </ul>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(admin_url('chain_content.php', $cq + ['action' => 'playlist'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New playlist')) ?></a>
  </div>
</div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'content' ? ' active' : '' ?>" href="<?= e(admin_url('chain_content.php', $cq)) ?>"><?= e(__('Content')) ?> <span class="badge text-bg-light border"><?= count($items) ?></span></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'playlists' ? ' active' : '' ?>" href="<?= e(admin_url('chain_content.php', $cq + ['tab' => 'playlists'])) ?>"><?= e(__('Playlists')) ?> <span class="badge text-bg-light border"><?= count($playlists) ?></span></a></li>
</ul>
<?php $rows = $tab === 'content' ? $items : $playlists; ?>
<div class="card">
  <?php if (!$rows): ?>
    <div class="hc-empty"><i class="bi bi-collection-play"></i><p class="mb-1"><strong><?= e($tab === 'content' ? __('No chain content yet.') : __('No chain playlists yet.')) ?></strong></p></div>
  <?php else: ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($rows as $r): $isPl = $tab === 'playlists'; ?>
      <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1 min-w-0">
          <div class="fw-semibold text-truncate"><i class="bi <?= e($isPl ? 'bi-collection-play' : (ContentManager::TYPE_ICONS[$r['type']] ?? 'bi-file')) ?>"></i> <?= e($isPl ? $r['name'] : $r['title']) ?>
            <?= !$isPl && !(int) $r['is_active'] ? '<span class="badge text-bg-secondary">' . e(__('inactive')) . '</span>' : '' ?></div>
          <div class="small text-muted"><?= e($isPl ? __(':n items', ['n' => $r['item_count']]) : __(ContentManager::TYPES[$r['type']])) ?> ·
            <?= e(__('published at :n of :t customers', ['n' => $r['published'], 't' => count($hotels)])) ?></div>
        </div>
        <div class="d-flex gap-1 text-nowrap">
          <a class="btn btn-sm btn-primary" href="<?= e(admin_url('chain_content.php', $cq + ['action' => 'publish', 'type' => $isPl ? 'playlist' : 'content', 'id' => $r['id']])) ?>"><i class="bi bi-send"></i> <span class="d-none d-sm-inline"><?= e(__('Publish')) ?></span></a>
          <a class="btn btn-sm btn-light border" href="<?= e(admin_url('chain_content.php', $cq + ($isPl ? ['action' => 'playlist', 'id' => $r['id']] : ['action' => 'edit', 'id' => $r['id']]))) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Delete from the chain library? Copies at the customers stay.')) ?>"><?= Csrf::field() ?>
            <input type="hidden" name="op" value="<?= $isPl ? 'delete_playlist' : 'delete_content' ?>"><input type="hidden" name="chain" value="<?= $cid ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
