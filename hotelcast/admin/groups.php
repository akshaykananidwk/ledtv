<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('groups.manage');
Csrf::check();

const GROUP_TYPES = ['floor', 'zone', 'custom'];
// Users limited to some TVs (core/Access.php): only their assigned groups, no create / delete / auto floors.
$limited = Access::restricted();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    switch ($op) {
        case 'save':
            $id = req_int('id', $_POST);
            $existing = $id ? Tenant::find('room_groups', $id) : null;
            $existing ? Access::requireGroup($id) : Access::requireUnrestricted('group create');
            $name = req_str('name', $_POST, 200);
            $type = in_array($_POST['type'] ?? '', GROUP_TYPES, true) ? $_POST['type'] : 'custom';
            $errors = [];
            if ($name === '' || mb_strlen($name) > 120) {
                $errors[] = __('Group name is required (max 120 characters).');
            } elseif (DB::value('SELECT id FROM room_groups WHERE hotel_id = :hid AND name = :n AND id <> :id', ['n' => $name, 'id' => $id] + hid())) {
                $errors[] = __('A group with this name already exists.');
            }
            if ($id && !$existing) {
                $errors[] = __('Group not found.');
            }
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('groups.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            [$cid, $pid] = parse_source($_POST['source'] ?? '');
            $data = ['name' => $name, 'type' => $type, 'description' => req_str('description', $_POST, 500) ?: null, 'content_id' => $cid, 'playlist_id' => $pid];
            $members = Tenant::assertOwnsAll('rooms', int_ids($_POST['members'] ?? []));
            Access::requireTargetList('rooms', $members); // a limited user cannot pull other rooms into their group
            DB::transaction(function () use (&$id, $existing, $data, $members) {
                if ($existing) {
                    DB::update('room_groups', $data, 'id = :id', ['id' => $id]);
                } else {
                    $id = DB::insert('room_groups', $data + ['created_at' => now()]);
                }
                DB::delete('room_group_members', 'group_id = :g', ['g' => $id]);
                if ($members) {
                    [$in, $p] = DB::in($members, 'r');
                    foreach (DB::column("SELECT id FROM rooms WHERE hotel_id = :hid AND id IN $in", $p + hid()) as $rid) {
                        DB::insert('room_group_members', ['room_id' => (int) $rid, 'group_id' => $id]);
                    }
                }
            });
            Settings::bumpContentVersion();
            Broadcaster::queueForRooms(Broadcaster::targetRooms('groups', [$id]), 'SHOW_CONTENT');
            ActivityLog::add($existing ? 'group_update' : 'group_create', 'group', $id, $name . ' (' . count($members) . ' screens)');
            flash('success', __('Group ":n" saved.', ['n' => $name]));
            redirect(admin_url('groups.php'));

        case 'delete':
            $id = req_int('id', $_POST);
            $g = Tenant::find('room_groups', $id);
            Access::requireUnrestricted('group delete');
            if ($g) {
                $rooms = Broadcaster::targetRooms('groups', [$id]);
                DB::delete('room_groups', 'id = :id', ['id' => $id]);
                Settings::bumpContentVersion();
                Broadcaster::queueForRooms($rooms, 'SHOW_CONTENT');
                ActivityLog::add('group_delete', 'group', $id, $g['name']);
                flash('success', __('Group ":n" deleted.', ['n' => $g['name']]));
            }
            redirect(admin_url('groups.php'));

        case 'auto_floors':
            Access::requireUnrestricted('group auto floors');
            $created = 0;
            $assigned = 0;
            DB::transaction(function () use (&$created, &$assigned) {
                foreach (hc_floors() as $floor) {
                    $name = __('Floor') . ' ' . $floor;
                    $gid = DB::value('SELECT id FROM room_groups WHERE hotel_id = :hid AND name = :n', ['n' => $name] + hid());
                    if (!$gid) {
                        $gid = DB::insert('room_groups', ['name' => $name, 'type' => 'floor', 'description' => null, 'created_at' => now()]);
                        $created++;
                    }
                    $assigned += DB::query(
                        'INSERT IGNORE INTO room_group_members (room_id, group_id) SELECT id, :g FROM rooms WHERE hotel_id = :hid AND floor = :f',
                        ['g' => (int) $gid, 'f' => $floor] + hid()
                    )->rowCount();
                }
            });
            Settings::bumpContentVersion();
            ActivityLog::add('group_auto_floors', 'group', null, "Created $created floor groups, $assigned memberships");
            flash('success', __('Area / floor groups ready: :c created, :a screens added.', ['c' => $created, 'a' => $assigned]));
            redirect(admin_url('groups.php'));
    }
    flash('warning', __('Unknown action.'));
    redirect(admin_url('groups.php'));
}

$action = req_str('action', $_GET, 20);
$pageTitle = __('Groups');
$activeNav = 'groups';

if ($action === 'new') {
    Access::requireUnrestricted('group create');
}
if ($action === 'new' || $action === 'edit') {
    $g = ['id' => 0, 'name' => '', 'type' => 'custom', 'description' => '', 'content_id' => null, 'playlist_id' => null];
    $members = [];
    if ($action === 'edit') {
        $g = Tenant::find('room_groups', req_int('id', $_GET));
        if (!$g) {
            flash('warning', __('Group not found.'));
            redirect(admin_url('groups.php'));
        }
        Access::requireGroup((int) $g['id']);
        $members = array_map('intval', DB::column('SELECT room_id FROM room_group_members WHERE group_id = :g', ['g' => $g['id']]));
    }
    $byFloor = [];
    foreach (hc_rooms() as $r) {
        $byFloor[(string) ($r['floor'] ?? '')][] = $r;
    }
    $pageTitle = $g['id'] ? __('Edit group') : __('New group');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><?= e($pageTitle) ?></h1>
      <a href="<?= e(admin_url('groups.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
      <div class="col-lg-5">
        <div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="gname"><?= e(__('Group name')) ?> *</label>
            <input class="form-control" id="gname" name="name" value="<?= e($g['name']) ?>" required maxlength="120" placeholder="<?= e(__('e.g. Ground floor')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="gtype"><?= e(__('Type')) ?></label>
            <select class="form-select" id="gtype" name="type">
              <?php foreach (GROUP_TYPES as $t): ?><option value="<?= e($t) ?>"<?= $g['type'] === $t ? ' selected' : '' ?>><?= e(__(ucfirst($t))) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="gdesc"><?= e(__('Description')) ?></label>
            <textarea class="form-control" id="gdesc" name="description" rows="2" maxlength="500"><?= e($g['description']) ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label" for="gsource"><?= e(__('Content for this group')) ?></label>
            <?= source_select('source', source_value($g['content_id'], $g['playlist_id']), __('— None (use default content) —'), ['id' => 'gsource']) ?>
            <div class="form-text"><?= e(__('Screens in this group show this unless the screen has its own content.')) ?></div>
          </div>
        </div></div>
      </div>
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header d-flex align-items-center">
            <span><?= e(__('Screens in this group')) ?></span>
            <label class="ms-auto small fw-normal"><input type="checkbox" class="form-check-input me-1" data-check-all=".member-cb"> <?= e(__('Select all')) ?></label>
          </div>
          <div class="card-body" style="max-height:60vh;overflow:auto">
            <?php if (!$byFloor): ?><div class="text-muted"><?= e(__('No screens yet.')) ?></div><?php endif; ?>
            <?php foreach ($byFloor as $floor => $list): $floor = (string) $floor; ?>
              <div class="mb-2">
                <div class="small fw-semibold text-muted mb-1">
                  <?= e($floor !== '' ? __('Floor') . ' ' . $floor : __('No floor')) ?>
                  <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-toggle-floor="<?= e(md5($floor)) ?>"><?= e(__('toggle')) ?></button>
                </div>
                <div class="room-check-grid" style="max-height:none">
                  <?php foreach ($list as $r): ?>
                    <input type="checkbox" class="btn-check member-cb" name="members[]" value="<?= (int) $r['id'] ?>" id="m<?= (int) $r['id'] ?>" data-fl="<?= e(md5($floor)) ?>"<?= in_array((int) $r['id'], $members, true) ? ' checked' : '' ?> autocomplete="off">
                    <label class="btn btn-sm btn-outline-secondary" for="m<?= (int) $r['id'] ?>"><?= e($r['room_number']) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save group')) ?></button></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('[data-toggle-floor]').forEach((b) => b.addEventListener('click', () => {
        const boxes = Array.from(document.querySelectorAll('.member-cb[data-fl="' + b.dataset.toggleFloor + '"]'));
        const on = boxes.every((c) => c.checked);
        boxes.forEach((c) => { c.checked = !on; });
      }));
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$groups = array_values(array_filter(
    DB::all('SELECT g.*, (SELECT COUNT(*) FROM room_group_members m WHERE m.group_id = g.id) AS members FROM room_groups g WHERE g.hotel_id = :hid ORDER BY g.type, g.name', hid()),
    static fn ($g) => Access::canGroup((int) $g['id'])
));
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Groups')) ?></h1>
    <p class="lead-sm"><?= e(__('Group screens by area, floor, zone or any way you like, then send content to a whole group at once.')) ?></p>
  </div>
  <?php if (!$limited): ?>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary" href="<?= e(admin_url('groups.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New group')) ?></a>
    <form method="post" data-confirm="<?= e(__('Create one group per area / floor and add each screen to its group?')) ?>" data-confirm-safe="1">
      <?= Csrf::field() ?><input type="hidden" name="op" value="auto_floors">
      <button class="btn btn-outline-primary"><i class="bi bi-magic"></i> <?= e(__('Auto-create floor groups')) ?></button>
    </form>
  </div>
  <?php endif; ?>
</div>
<div class="card">
<?php if (!$groups): ?>
  <div class="hc-empty">
    <i class="bi bi-collection"></i>
    <p class="mb-1"><strong><?= e(__('No groups yet')) ?></strong></p>
    <p class="text-muted"><?= e(__('Click "Auto-create floor groups" to make one group per floor in one step.')) ?></p>
  </div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hc table-hover">
      <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('Type')) ?></th><th><?= e(__('Screens')) ?></th><th><?= e(__('Content')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g): ?>
        <tr>
          <td><strong><?= e($g['name']) ?></strong><?php if ($g['description']): ?><div class="small text-muted"><?= e($g['description']) ?></div><?php endif; ?></td>
          <td><span class="badge text-bg-light border"><?= e(__(ucfirst($g['type']))) ?></span></td>
          <td><?= (int) $g['members'] ?></td>
          <td class="small"><?= e(source_label($g['content_id'], $g['playlist_id']) ?: __('Default content')) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('groups.php', ['action' => 'edit', 'id' => $g['id']])) ?>"><i class="bi bi-pencil"></i> <span class="d-none d-sm-inline"><?= e(__('Edit')) ?></span></a>
            <?php if (!$limited): ?>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete group ":n"? The screens themselves are not deleted.', ['n' => $g['name']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
