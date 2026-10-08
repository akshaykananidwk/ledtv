<?php
/**
 * Video walls (2.4, #36): N×M TVs act as one big screen. Create a wall, place a TV (room) on each tile
 * of the grid, choose what it plays, bezel compensation, which TV plays sound, and "Identify" (each TV
 * shows its tile number). Core logic: core/VideoWalls.php; docs/modules/video_wall_sync.md.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('video_walls.manage');
Csrf::check();

/** Form values of a wall row (+ tiles) for the editor. */
function vw_form_values(?array $wall): array
{
    $v = [
        'id' => 0, 'name' => '', 'rows' => 2, 'cols' => 2, 'bezel_mm' => '', 'screen_w_mm' => '', 'screen_h_mm' => '',
        'source' => '', 'audio' => '0_0', 'is_active' => 1, 'tiles' => [],
    ];
    if ($wall) {
        $num = static fn ($x) => (float) $x > 0 ? rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.') : '';
        $v = [
            'id' => (int) $wall['id'], 'name' => (string) $wall['name'], 'rows' => (int) $wall['rows_count'], 'cols' => (int) $wall['cols_count'],
            'bezel_mm' => $num($wall['bezel_mm']), 'screen_w_mm' => $num($wall['screen_w_mm']), 'screen_h_mm' => $num($wall['screen_h_mm']),
            'source' => source_value($wall['content_id'], $wall['playlist_id']),
            'audio' => (int) $wall['audio_row'] === VideoWalls::AUDIO_NONE ? 'none' : (int) $wall['audio_row'] . '_' . (int) $wall['audio_col'],
            'is_active' => (int) $wall['is_active'], 'tiles' => [],
        ];
        foreach (VideoWalls::tiles((int) $wall['id']) as $t) {
            $v['tiles'][(int) $t['row_index'] . '_' . (int) $t['col_index']] = (int) $t['room_id'];
        }
    }
    return $v;
}

$formErrors = [];
$formValues = null;

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    if ($op === 'save') {
        $existing = $id ? VideoWalls::find($id) : null;
        if ($id && !$existing) {
            flash('warning', __('Video wall not found.'));
            redirect(admin_url('video_walls.php'));
        }
        if ($existing) {
            VideoWalls::requireUse($existing);
        }
        [$data, $tiles, $formErrors] = VideoWalls::validate($_POST, $id ?: null);
        if (!$formErrors) {
            $id = VideoWalls::save($id ?: null, $data, $tiles, Auth::id());
            Broadcaster::queueForRooms(array_map(fn ($t) => ['id' => $t[2]], $tiles), 'SHOW_CONTENT');
            ActivityLog::add($existing ? 'video_wall_update' : 'video_wall_create', 'video_wall', $id, $data['name'] . ' (' . $data['rows_count'] . '×' . $data['cols_count'] . ', ' . count($tiles) . ' TVs)');
            flash('success', __('Video wall ":n" saved.', ['n' => $data['name']]));
            redirect(admin_url('video_walls.php', ['action' => 'edit', 'id' => $id]));
        }
        // Show the form again with what was entered.
        $formValues = [
            'id' => $id, 'name' => (string) ($_POST['name'] ?? ''), 'rows' => $data['rows_count'], 'cols' => $data['cols_count'],
            'bezel_mm' => (string) ($_POST['bezel_mm'] ?? ''), 'screen_w_mm' => (string) ($_POST['screen_w_mm'] ?? ''), 'screen_h_mm' => (string) ($_POST['screen_h_mm'] ?? ''),
            'source' => (string) ($_POST['source'] ?? ''), 'audio' => (string) ($_POST['audio'] ?? '0_0'), 'is_active' => $data['is_active'],
            'tiles' => array_map('intval', array_filter(is_array($_POST['tiles'] ?? null) ? $_POST['tiles'] : [], fn ($v) => is_scalar($v))),
        ];
    } elseif ($op === 'delete') {
        $wallRooms = array_map(fn ($t) => ['id' => (int) $t['room_id']], VideoWalls::tiles($id));
        $wall = VideoWalls::delete($id);
        if ($wall) {
            Broadcaster::queueForRooms($wallRooms, 'SHOW_CONTENT');
            ActivityLog::add('video_wall_delete', 'video_wall', $id, (string) $wall['name']);
            flash('success', __('Video wall ":n" deleted. Its TVs show their own content again.', ['n' => $wall['name']]));
        }
        redirect(admin_url('video_walls.php'));
    } elseif ($op === 'identify') {
        $wall = VideoWalls::find($id);
        if ($wall) {
            $n = VideoWalls::identify($wall);
            flash($n ? 'success' : 'warning', $n ? __('Each TV of ":n" now shows its tile number for 15 seconds (:c TVs).', ['n' => $wall['name'], 'c' => $n]) : __('No registered TV on this wall yet.'));
            redirect(admin_url('video_walls.php', req_str('back', $_POST, 10) === 'edit' ? ['action' => 'edit', 'id' => $id] : []));
        }
        redirect(admin_url('video_walls.php'));
    } else {
        redirect(admin_url('video_walls.php'));
    }
}

$action = $formValues !== null ? 'edit' : req_str('action', $_GET, 20);
$pageTitle = __('Video walls');
$activeNav = 'video_walls';

if ($action === 'new' || $action === 'edit') {
    if ($formValues === null) {
        $wall = null;
        if ($action === 'edit') {
            $wall = VideoWalls::find(req_int('id', $_GET));
            if (!$wall) {
                flash('warning', __('Video wall not found.'));
                redirect(admin_url('video_walls.php'));
            }
            VideoWalls::requireUse($wall);
        }
        $formValues = vw_form_values($wall);
    }
    $v = $formValues;
    $rooms = hc_rooms();
    // Rooms that already belong to another wall (shown as taken).
    $taken = [];
    foreach (DB::all('SELECT t.room_id, w.name FROM video_wall_tiles t JOIN video_walls w ON w.id = t.wall_id WHERE t.hotel_id = :hid AND t.wall_id <> :w', ['w' => (int) $v['id']] + hid()) as $t) {
        $taken[(int) $t['room_id']] = (string) $t['name'];
    }
    $status = $v['id'] ? VideoWalls::deviceStatus((int) $v['id']) : [];
    $pageTitle = $v['id'] ? __('Edit video wall') : __('New video wall');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-grid-3x3"></i> <?= e($pageTitle) ?></h1>
      <div class="d-flex gap-2">
        <?php if ($v['id']): ?>
          <form method="post">
            <?= Csrf::field() ?><input type="hidden" name="op" value="identify"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="back" value="edit">
            <button class="btn btn-outline-primary" title="<?= e(__('Each TV shows its tile number for 15 seconds.')) ?>"><i class="bi bi-123"></i> <?= e(__('Identify')) ?></button>
          </form>
        <?php endif; ?>
        <a href="<?= e(admin_url('video_walls.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><?php foreach ($formErrors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" id="vwForm" class="row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
      <div class="col-lg-4">
        <div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="vwname"><?= e(__('Wall name')) ?> *</label>
            <input class="form-control" id="vwname" name="name" value="<?= e($v['name']) ?>" required maxlength="120" placeholder="<?= e(__('e.g. Lobby wall')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="vwrows"><?= e(__('Rows')) ?></label>
            <select class="form-select" id="vwrows" name="rows"><?php for ($i = 1; $i <= VideoWalls::MAX_ROWS; $i++): ?><option value="<?= $i ?>"<?= (int) $v['rows'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
          </div>
          <div class="col-6">
            <label class="form-label" for="vwcols"><?= e(__('Columns')) ?></label>
            <select class="form-select" id="vwcols" name="cols"><?php for ($i = 1; $i <= VideoWalls::MAX_COLS; $i++): ?><option value="<?= $i ?>"<?= (int) $v['cols'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
          </div>
          <div class="col-12">
            <label class="form-label" for="vwsource"><?= e(__('The wall plays')) ?> *</label>
            <?= source_select('source', (string) $v['source'], __('— Choose content or a playlist —'), ['id' => 'vwsource']) ?>
            <div class="form-text"><?= e(__('Every TV shows its part of the same picture, always in step. Videos need a length in seconds. Split screen layouts cannot be used.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="vwaudio"><?= e(__('Sound from')) ?></label>
            <select class="form-select" id="vwaudio" name="audio">
              <option value="none"<?= $v['audio'] === 'none' ? ' selected' : '' ?>><?= e(__('No sound')) ?></option>
              <?php for ($r = 0; $r < VideoWalls::MAX_ROWS; $r++): for ($c = 0; $c < VideoWalls::MAX_COLS; $c++): $k = $r . '_' . $c; ?>
                <option value="<?= e($k) ?>" data-r="<?= $r ?>" data-c="<?= $c ?>"<?= $v['audio'] === $k ? ' selected' : '' ?>><?= e(__('Tile :n (row :r, column :c)', ['n' => $r * (int) $v['cols'] + $c + 1, 'r' => $r + 1, 'c' => $c + 1])) ?></option>
              <?php endfor; endfor; ?>
            </select>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="vwactive" name="is_active" value="1"<?= $v['is_active'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="vwactive"><?= e(__('Wall is on')) ?></label>
            </div>
            <div class="form-text"><?= e(__('When off, the TVs show their own room / group content.')) ?></div>
          </div>
          <div class="col-12"><hr class="my-1"><div class="fw-semibold"><?= e(__('Bezel compensation (optional)')) ?></div>
            <div class="form-text"><?= e(__('Gap between the pictures of two neighbouring TVs (both frames together), and the picture size of one TV. The image then continues behind the frames instead of being cut at them.')) ?></div>
          </div>
          <div class="col-4">
            <label class="form-label small" for="vwbezel"><?= e(__('Gap (mm)')) ?></label>
            <input class="form-control" id="vwbezel" name="bezel_mm" type="number" min="0" max="500" step="0.1" value="<?= e((string) $v['bezel_mm']) ?>">
          </div>
          <div class="col-4">
            <label class="form-label small" for="vwsw"><?= e(__('Picture width (mm)')) ?></label>
            <input class="form-control" id="vwsw" name="screen_w_mm" type="number" min="0" max="5000" step="0.1" value="<?= e((string) $v['screen_w_mm']) ?>">
          </div>
          <div class="col-4">
            <label class="form-label small" for="vwsh"><?= e(__('Picture height (mm)')) ?></label>
            <input class="form-control" id="vwsh" name="screen_h_mm" type="number" min="0" max="5000" step="0.1" value="<?= e((string) $v['screen_h_mm']) ?>">
          </div>
        </div></div>
      </div>
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header"><?= e(__('Which TV is where? (as seen from the front)')) ?></div>
          <div class="card-body">
            <div id="vwGrid" class="vw-grid" style="display:grid;gap:8px;grid-template-columns:repeat(<?= (int) $v['cols'] ?>,minmax(0,1fr))">
              <?php for ($r = 0; $r < VideoWalls::MAX_ROWS; $r++): for ($c = 0; $c < VideoWalls::MAX_COLS; $c++):
                  $k = $r . '_' . $c;
                  $sel = (int) ($v['tiles'][$k] ?? 0);
                  $on = $r < (int) $v['rows'] && $c < (int) $v['cols'];
                  $st = $sel ? ($status[$sel] ?? null) : null; ?>
                <div class="vw-tile border rounded p-2 text-center bg-light" data-r="<?= $r ?>" data-c="<?= $c ?>" style="aspect-ratio:16/9;min-width:0<?= $on ? '' : ';display:none' ?>">
                  <div class="fs-3 fw-bold text-primary lh-1 vw-num"><?= $r * (int) $v['cols'] + $c + 1 ?></div>
                  <label class="visually-hidden" for="vwt<?= e($k) ?>"><?= e(__('TV on tile :n', ['n' => $r * (int) $v['cols'] + $c + 1])) ?></label>
                  <select class="form-select form-select-sm mt-1" id="vwt<?= e($k) ?>" name="tiles[<?= e($k) ?>]"<?= $on ? '' : ' disabled' ?>>
                    <option value="0"><?= e(__('— no TV —')) ?></option>
                    <?php foreach ($rooms as $room): $rid = (int) $room['id']; ?>
                      <option value="<?= $rid ?>"<?= $sel === $rid ? ' selected' : '' ?><?= isset($taken[$rid]) ? ' disabled' : '' ?>><?= e($room['room_number']) ?><?= $room['name'] ? ' · ' . e($room['name']) : '' ?><?= isset($taken[$rid]) ? ' (' . e($taken[$rid]) . ')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($st): ?>
                    <div class="small mt-1"><?= $st['online'] ? '<span class="text-success"><i class="bi bi-circle-fill"></i> ' . e(__('Online')) . '</span>' : '<span class="text-muted"><i class="bi bi-circle"></i> ' . e(__('Offline')) . '</span>' ?>
                      <?php if ($st['old_app']): ?><span class="badge text-bg-warning" title="<?= e(__('TV app older than 2.4: shows the whole picture until it is updated.')) ?>"><?= e(__('Update app')) ?></span><?php endif; ?></div>
                  <?php endif; ?>
                </div>
              <?php endfor; endfor; ?>
            </div>
            <div class="form-text mt-2"><i class="bi bi-info-circle"></i> <?= e(__('Each room can be part of one wall only. Use "Identify" after saving to check that every TV is in the right place.')) ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save wall')) ?></button>
        <a class="btn btn-light border btn-lg" href="<?= e(admin_url('video_walls.php')) ?>"><?= e(__('Cancel')) ?></a>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const rows = document.getElementById('vwrows'), cols = document.getElementById('vwcols'), grid = document.getElementById('vwGrid'), audio = document.getElementById('vwaudio');
      const T = { tile: <?= json_embed(__('Tile :n (row :r, column :c)')) ?> };
      const update = () => {
        const R = +rows.value, C = +cols.value;
        grid.style.gridTemplateColumns = 'repeat(' + C + ',minmax(0,1fr))';
        grid.querySelectorAll('.vw-tile').forEach((t) => {
          const r = +t.dataset.r, c = +t.dataset.c, on = r < R && c < C;
          t.style.display = on ? '' : 'none';
          t.querySelector('select').disabled = !on;
          t.querySelector('.vw-num').textContent = r * C + c + 1;
        });
        audio.querySelectorAll('option[data-r]').forEach((o) => {
          const r = +o.dataset.r, c = +o.dataset.c, on = r < R && c < C;
          o.hidden = !on; o.disabled = !on;
          o.textContent = T.tile.replace(':n', r * C + c + 1).replace(':r', r + 1).replace(':c', c + 1);
        });
        if (audio.selectedOptions[0] && audio.selectedOptions[0].disabled) audio.value = '0_0';
      };
      // A room can sit on one tile only: picking it again elsewhere clears the other tile.
      grid.addEventListener('change', (ev) => {
        const s = ev.target.closest('select');
        if (!s || s.value === '0') return;
        grid.querySelectorAll('select').forEach((o) => { if (o !== s && o.value === s.value) o.value = '0'; });
      });
      rows.addEventListener('change', update);
      cols.addEventListener('change', update);
      update();
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$walls = VideoWalls::all();
$tileCounts = [];
foreach (DB::all('SELECT wall_id, COUNT(*) AS n FROM video_wall_tiles WHERE hotel_id = :hid GROUP BY wall_id', hid()) as $t) {
    $tileCounts[(int) $t['wall_id']] = (int) $t['n'];
}
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Video walls')) ?></h1>
    <p class="lead-sm"><?= e(__('Several TVs side by side act as one big screen (up to 4 × 4). Each TV shows its part of the picture, all in step.')) ?></p>
  </div>
  <a class="btn btn-primary btn-lg" href="<?= e(admin_url('video_walls.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New video wall')) ?></a>
</div>
<div class="card">
<?php if (!$walls): ?>
  <div class="hc-empty"><i class="bi bi-grid-3x3"></i>
    <p class="mb-1"><strong><?= e(__('No video walls yet')) ?></strong></p>
    <p class="text-muted"><?= e(__('Mount the TVs in a grid, register each one as a room, then create a wall and place each TV on its tile.')) ?></p>
  </div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hc table-hover">
      <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('Size')) ?></th><th><?= e(__('TVs')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Content')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($walls as $w): $wid = (int) $w['id']; $total = (int) $w['rows_count'] * (int) $w['cols_count']; ?>
        <tr>
          <td><strong><?= e($w['name']) ?></strong> <?= (int) $w['is_active'] ? '' : '<span class="badge text-bg-secondary">' . e(__('Off')) . '</span>' ?></td>
          <td><?= (int) $w['rows_count'] ?> × <?= (int) $w['cols_count'] ?></td>
          <td><?= (int) ($tileCounts[$wid] ?? 0) ?> / <?= $total ?></td>
          <td class="d-none d-md-table-cell small"><?= e(source_label($w['content_id'], $w['playlist_id']) ?: '-') ?></td>
          <td class="text-end text-nowrap">
            <form method="post" class="d-inline">
              <?= Csrf::field() ?><input type="hidden" name="op" value="identify"><input type="hidden" name="id" value="<?= $wid ?>">
              <button class="btn btn-sm btn-outline-primary" title="<?= e(__('Each TV shows its tile number for 15 seconds.')) ?>"><i class="bi bi-123"></i> <span class="d-none d-lg-inline"><?= e(__('Identify')) ?></span></button>
            </form>
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('video_walls.php', ['action' => 'edit', 'id' => $wid])) ?>"><i class="bi bi-pencil"></i> <span class="d-none d-sm-inline"><?= e(__('Edit')) ?></span></a>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete video wall ":n"? Its TVs show their own content again.', ['n' => $w['name']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= $wid ?>">
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
