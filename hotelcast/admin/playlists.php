<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('playlists.manage');
Csrf::check();

const TRANSITIONS = ['none', 'fade', 'slide'];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'save') {
        $id = req_int('id', $_POST);
        $existing = $id ? ContentManager::findPlaylist($id) : null;
        $name = req_str('name', $_POST, 250);
        $transition = in_array($_POST['transition'] ?? '', TRANSITIONS, true) ? $_POST['transition'] : 'fade';
        if ($name === '' || mb_strlen($name) > 190) {
            flash('danger', __('Playlist name is required (max 190 characters).'));
            redirect(admin_url('playlists.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
        }
        if ($id && !$existing) {
            flash('danger', __('Playlist not found.'));
            redirect(admin_url('playlists.php'));
        }
        // items[n][content_id], items[n][duration] in display order
        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = req_int('content_id', $row);
            if (!$cid) {
                continue;
            }
            $dur = is_string($row['duration'] ?? null) && $row['duration'] !== '' && ctype_digit($row['duration']) ? min(86400, (int) $row['duration']) : null;
            $items[] = [$cid, $dur];
            if (count($items) >= 300) {
                break;
            }
        }
        $validIds = [];
        if ($items) {
            [$in, $p] = DB::in(array_values(array_unique(array_column($items, 0))), 'c');
            $validIds = array_map('intval', DB::column("SELECT id FROM content_items WHERE id IN $in", $p));
        }
        DB::transaction(function () use (&$id, $existing, $name, $transition, $items, $validIds) {
            $data = ['name' => $name, 'description' => req_str('description', $_POST, 500) ?: null, 'transition' => $transition];
            if ($existing) {
                DB::update('content_playlists', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('content_playlists', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
            }
            DB::delete('playlist_items', 'playlist_id = :p', ['p' => $id]);
            $order = 0;
            foreach ($items as [$cid, $dur]) {
                if (in_array($cid, $validIds, true)) {
                    DB::insert('playlist_items', ['playlist_id' => $id, 'content_id' => $cid, 'sort_order' => $order++, 'duration' => $dur]);
                }
            }
        });
        // touch updated_at even when only items changed
        DB::query('UPDATE content_playlists SET updated_at = :n WHERE id = :id', ['n' => now(), 'id' => $id]);
        Settings::bumpContentVersion();
        ActivityLog::add($existing ? 'playlist_update' : 'playlist_create', 'playlist', $id, $name . ' (' . count($items) . ' items)');
        flash('success', __('Playlist ":n" saved.', ['n' => $name]));
        redirect(admin_url('playlists.php', ['action' => 'edit', 'id' => $id]));
    }
    if ($op === 'delete') {
        $id = req_int('id', $_POST);
        $pl = ContentManager::findPlaylist($id);
        if ($pl) {
            DB::delete('content_playlists', 'id = :id', ['id' => $id]);
            if ((string) Settings::get('default_playlist_id') === (string) $id) {
                Settings::set('default_playlist_id', '');
            }
            Settings::bumpContentVersion();
            ActivityLog::add('playlist_delete', 'playlist', $id, $pl['name']);
            flash('success', __('Playlist ":n" deleted.', ['n' => $pl['name']]));
        }
        redirect(admin_url('playlists.php'));
    }
    redirect(admin_url('playlists.php'));
}

$action = req_str('action', $_GET, 20);
$pageTitle = __('Playlists');
$activeNav = 'playlists';

if ($action === 'new' || $action === 'edit') {
    $pl = ['id' => 0, 'name' => '', 'description' => '', 'transition' => 'fade'];
    $plItems = [];
    if ($action === 'edit') {
        $pl = ContentManager::findPlaylist(req_int('id', $_GET));
        if (!$pl) {
            flash('warning', __('Playlist not found.'));
            redirect(admin_url('playlists.php'));
        }
        $plItems = ContentManager::playlistItems((int) $pl['id'], false);
    }
    $library = DB::all('SELECT id, title, type, duration, is_active, file_path, thumb_path, url FROM content_items ORDER BY title');
    $libJs = [];
    foreach ($library as $c) {
        $libJs[$c['id']] = ['id' => (int) $c['id'], 'title' => $c['title'], 'type' => __(ContentManager::TYPES[$c['type']]), 'icon' => ContentManager::TYPE_ICONS[$c['type']],
            'duration' => (int) $c['duration'], 'thumb' => ContentManager::thumbUrl($c), 'active' => (bool) (int) $c['is_active']];
    }
    $usage = $pl['id'] ? [
        'rooms' => (int) DB::value('SELECT COUNT(*) FROM rooms WHERE playlist_id = :p', ['p' => $pl['id']]),
        'groups' => (int) DB::value('SELECT COUNT(*) FROM room_groups WHERE playlist_id = :p', ['p' => $pl['id']]),
    ] : ['rooms' => 0, 'groups' => 0];
    $pageTitle = $pl['id'] ? __('Edit playlist') : __('New playlist');
    $extraScripts = ['vendor/sortablejs/Sortable.min.js'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-collection-play"></i> <?= e($pageTitle) ?></h1>
      <div class="d-flex gap-2">
        <?php if ($pl['id']): ?><a class="btn btn-light border" href="<?= e(admin_url('preview.php', ['playlist_id' => $pl['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> <?= e(__('Preview')) ?></a><?php endif; ?>
        <a href="<?= e(admin_url('playlists.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <form method="post" id="plForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card"><div class="card-body row g-3">
            <div class="col-12">
              <label class="form-label" for="plname"><?= e(__('Playlist name')) ?> *</label>
              <input class="form-control" id="plname" name="name" value="<?= e($pl['name']) ?>" required maxlength="190" placeholder="<?= e(__('e.g. Morning loop')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="pldesc"><?= e(__('Description')) ?></label>
              <textarea class="form-control" id="pldesc" name="description" rows="2" maxlength="500"><?= e($pl['description']) ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label" for="pltr"><?= e(__('Transition between items')) ?></label>
              <select class="form-select" id="pltr" name="transition">
                <option value="none"<?= $pl['transition'] === 'none' ? ' selected' : '' ?>><?= e(__('None')) ?></option>
                <option value="fade"<?= $pl['transition'] === 'fade' ? ' selected' : '' ?>><?= e(__('Fade')) ?></option>
                <option value="slide"<?= $pl['transition'] === 'slide' ? ' selected' : '' ?>><?= e(__('Slide')) ?></option>
              </select>
            </div>
            <?php if ($pl['id'] && ($usage['rooms'] || $usage['groups'])): ?>
              <div class="col-12 small text-muted"><i class="bi bi-info-circle"></i> <?= e(__('Used by :r rooms and :g groups. Changes appear on their TVs automatically.', ['r' => $usage['rooms'], 'g' => $usage['groups']])) ?></div>
            <?php endif; ?>
          </div></div>
        </div>
        <div class="col-lg-8">
          <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
              <span><?= e(__('Items')) ?> (<span id="plCount">0</span>) · <span id="plTotal"></span></span>
            </div>
            <div class="card-body">
              <?php if (!$library): ?>
                <div class="hc-empty"><i class="bi bi-images"></i><p><?= e(__('Your content library is empty.')) ?></p>
                  <a class="btn btn-primary" href="<?= e(admin_url('content.php')) ?>"><?= e(__('Add content first')) ?></a></div>
              <?php else: ?>
                <div class="input-group mb-3">
                  <select class="form-select" id="plAddSel" aria-label="<?= e(__('Content to add')) ?>">
                    <?php foreach ($library as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"><?= e($c['title']) ?> — <?= e(__(ContentManager::TYPES[$c['type']])) ?><?= (int) $c['is_active'] ? '' : ' (' . e(__('inactive')) . ')' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-primary" id="plAddBtn"><i class="bi bi-plus-lg"></i> <?= e(__('Add')) ?></button>
                </div>
                <div id="plList"></div>
                <div id="plEmpty" class="text-muted text-center py-4" hidden><?= e(__('No items yet — choose content above and click Add.')) ?></div>
                <div class="form-text"><i class="bi bi-arrows-move"></i> <?= e(__('Drag items to change the order. Leave "seconds" empty to use the item\'s own duration.')) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save playlist')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('playlists.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div>
      </div>
    </form>
    <script type="application/json" id="plData"><?= json_embed(['library' => (object) $libJs, 'items' => array_map(fn ($r) => ['content_id' => (int) $r['id'], 'duration' => $r['pli_duration']], $plItems)]) ?></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const data = JSON.parse(document.getElementById('plData').textContent);
      const list = document.getElementById('plList');
      if (!list) return;
      const T = { secs: <?= json_embed(__('seconds')) ?>, remove: <?= json_embed(__('Remove')) ?>, inactive: <?= json_embed(__('inactive')) ?>, total: <?= json_embed(__('total :t')) ?> };
      const update = () => {
        const rows = Array.from(list.querySelectorAll('.pl-item'));
        let total = 0;
        rows.forEach((row, i) => {
          row.querySelectorAll('[data-name]').forEach((inp) => { inp.name = 'items[' + i + '][' + inp.dataset.name + ']'; });
          const d = row.querySelector('[data-name=duration]');
          total += parseInt(d.value || d.placeholder || '0', 10) || 0;
        });
        document.getElementById('plCount').textContent = rows.length;
        const m = Math.floor(total / 60), s = total % 60;
        document.getElementById('plTotal').textContent = T.total.replace(':t', (m ? m + 'm ' : '') + s + 's');
        document.getElementById('plEmpty').hidden = rows.length > 0;
      };
      const add = (cid, dur) => {
        const c = data.library[cid];
        if (!c) return;
        const row = document.createElement('div');
        row.className = 'pl-item';
        row.innerHTML = '<span class="drag-handle" title="drag"><i class="bi bi-grip-vertical"></i></span>'
          + (c.thumb ? '<img class="thumb-sm" alt="" src="' + HC.esc(c.thumb) + '">' : '<span class="thumb-sm"><i class="bi ' + HC.esc(c.icon) + '"></i></span>')
          + '<div class="pl-title"><div class="fw-semibold text-truncate">' + HC.esc(c.title) + '</div><div class="small text-muted">' + HC.esc(c.type) + (c.active ? '' : ' · <span class="text-danger">' + HC.esc(T.inactive) + '</span>') + '</div></div>'
          + '<input type="hidden" data-name="content_id" value="' + c.id + '">'
          + '<div class="input-group input-group-sm" style="width:auto"><input type="number" class="form-control" min="0" max="86400" data-name="duration" placeholder="' + c.duration + '" value="' + (dur === null || dur === undefined ? '' : dur) + '" aria-label="' + HC.esc(T.secs) + '"><span class="input-group-text d-none d-sm-inline-flex">' + HC.esc(T.secs) + '</span></div>'
          + '<button type="button" class="btn btn-sm btn-outline-danger" data-remove title="' + HC.esc(T.remove) + '"><i class="bi bi-x-lg"></i></button>';
        list.appendChild(row);
      };
      data.items.forEach((it) => add(it.content_id, it.duration));
      update();
      document.getElementById('plAddBtn').addEventListener('click', () => { add(document.getElementById('plAddSel').value, null); update(); });
      list.addEventListener('click', (ev) => { const b = ev.target.closest('[data-remove]'); if (b) { b.closest('.pl-item').remove(); update(); } });
      list.addEventListener('input', update);
      if (window.Sortable) Sortable.create(list, { handle: '.drag-handle', animation: 150, onEnd: update });
      document.getElementById('plForm').addEventListener('submit', update);
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$playlists = DB::all(
    'SELECT p.*, COUNT(i.id) AS items, COALESCE(SUM(COALESCE(i.duration, c.duration)), 0) AS total_sec,
            (SELECT COUNT(*) FROM rooms r WHERE r.playlist_id = p.id) AS rooms
     FROM content_playlists p
     LEFT JOIN playlist_items i ON i.playlist_id = p.id
     LEFT JOIN content_items c ON c.id = i.content_id
     GROUP BY p.id ORDER BY p.name'
);
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Playlists')) ?></h1>
    <p class="lead-sm"><?= e(__('A playlist shows several items one after another in a loop.')) ?></p>
  </div>
  <a class="btn btn-primary btn-lg" href="<?= e(admin_url('playlists.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New playlist')) ?></a>
</div>
<div class="card">
<?php if (!$playlists): ?>
  <div class="hc-empty"><i class="bi bi-collection-play"></i>
    <p class="mb-1"><strong><?= e(__('No playlists yet')) ?></strong></p>
    <p class="text-muted"><?= e(__('Create a playlist to rotate images, videos and announcements on your TVs.')) ?></p>
  </div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hc table-hover">
      <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('Items')) ?></th><th class="d-none d-sm-table-cell"><?= e(__('Length')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Transition')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Rooms')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($playlists as $p): $t = (int) $p['total_sec']; ?>
        <tr>
          <td><strong><?= e($p['name']) ?></strong><?php if ($p['description']): ?><div class="small text-muted"><?= e($p['description']) ?></div><?php endif; ?></td>
          <td><?= (int) $p['items'] ?></td>
          <td class="d-none d-sm-table-cell"><?= e(($t >= 60 ? floor($t / 60) . 'm ' : '') . ($t % 60) . 's') ?></td>
          <td class="d-none d-md-table-cell"><?= e(__(ucfirst($p['transition']))) ?></td>
          <td class="d-none d-md-table-cell"><?= (int) $p['rooms'] ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('preview.php', ['playlist_id' => $p['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>"><i class="bi bi-eye"></i></a>
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('playlists.php', ['action' => 'edit', 'id' => $p['id']])) ?>"><i class="bi bi-pencil"></i> <span class="d-none d-sm-inline"><?= e(__('Edit')) ?></span></a>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete playlist ":n"? Rooms using it will fall back to their group or hotel default content.', ['n' => $p['name']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
