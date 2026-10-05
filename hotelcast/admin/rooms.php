<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('rooms.view');
Csrf::check();

/** Parse "101-120, 125, 201-205" → list of room numbers (max 500). */
function parse_room_numbers(string $spec): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($spec)) ?: [] as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^([A-Za-z]*)(\d{1,6})-(?:[A-Za-z]*)(\d{1,6})$/', $part, $m)) {
            [$a, $b] = [(int) $m[2], (int) $m[3]];
            if ($b < $a || $b - $a > 500) {
                continue;
            }
            $pad = strlen($m[2]);
            for ($i = $a; $i <= $b; $i++) {
                $out[] = $m[1] . str_pad((string) $i, $pad, '0', STR_PAD_LEFT);
            }
        } elseif (preg_match('/^[A-Za-z0-9-]{1,20}$/', $part)) {
            $out[] = $part;
        }
        if (count($out) > 500) {
            break;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 500);
}

function guess_floor(string $number): ?string
{
    return preg_match('/^(\d+)\d{2}$/', $number, $m) ? $m[1] : null;
}

function save_room_groups(int $roomId, array $groupIds): void
{
    DB::delete('room_group_members', 'room_id = :r', ['r' => $roomId]);
    $valid = $groupIds ? DB::column('SELECT id FROM room_groups WHERE id IN ' . DB::in($groupIds, 'g')[0], DB::in($groupIds, 'g')[1]) : [];
    foreach ($valid as $gid) {
        DB::insert('room_group_members', ['room_id' => $roomId, 'group_id' => (int) $gid]);
    }
}

// ---------------------------------------------------------------- POST actions
if (is_post()) {
    $op = req_str('op', $_POST, 30);
    try {
        switch ($op) {
            case 'save':
                require_can('rooms.manage');
                $id = req_int('id', $_POST);
                $existing = $id ? DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $id]) : null;
                if ($id && !$existing) {
                    throw new InvalidArgumentException(__('Room not found.'));
                }
                $number = req_str('room_number', $_POST, 40);
                $pin = req_str('settings_pin', $_POST, 10);
                $errors = [];
                if ($number === '' || mb_strlen($number) > 20 || !preg_match('/^[\p{L}\p{N} _.-]+$/u', $number)) {
                    $errors[] = __('Room number is required (max 20 letters/numbers).');
                } elseif (DB::value('SELECT id FROM rooms WHERE room_number = :n AND id <> :id', ['n' => $number, 'id' => $id])) {
                    $errors[] = __('Room :n already exists.', ['n' => $number]);
                }
                if ($pin !== '' && !preg_match('/^\d{4}$/', $pin)) {
                    $errors[] = __('The settings PIN must be exactly 4 digits (or empty to use the hotel PIN).');
                }
                [$cid, $pid] = parse_source($_POST['source'] ?? '');
                if ($errors) {
                    flash_errors($errors);
                    redirect(admin_url('rooms.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
                }
                $data = [
                    'room_number' => $number,
                    'name' => req_str('name', $_POST, 120) !== '' ? req_str('name', $_POST, 120) : null,
                    'floor' => req_str('floor', $_POST, 20) !== '' ? req_str('floor', $_POST, 20) : guess_floor($number),
                    'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
                    'settings_pin' => $pin ?: null,
                    'notes' => req_str('notes', $_POST, 500) ?: null,
                    'content_id' => $cid,
                    'playlist_id' => $pid,
                ];
                DB::transaction(function () use (&$id, $data, $existing) {
                    if ($existing) {
                        DB::update('rooms', $data, 'id = :id', ['id' => $id]);
                    } else {
                        $id = DB::insert('rooms', $data + ['created_at' => now()]);
                    }
                    save_room_groups($id, int_ids($_POST['groups'] ?? []));
                });
                Settings::bumpContentVersion();
                $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $id]);
                Broadcaster::queueForRooms([$room], 'SHOW_CONTENT');
                ActivityLog::add($existing ? 'room_update' : 'room_create', 'room', $id, 'Room ' . $number);
                flash('success', $existing ? __('Room :n saved.', ['n' => $number]) : __('Room :n added.', ['n' => $number]));
                redirect(admin_url('rooms.php'));

            case 'delete':
                require_can('rooms.manage');
                $id = req_int('id', $_POST);
                $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $id]);
                if ($room) {
                    DB::delete('rooms', 'id = :id', ['id' => $id]);
                    Settings::bumpContentVersion();
                    ActivityLog::add('room_delete', 'room', $id, 'Room ' . $room['room_number']);
                    flash('success', __('Room :n deleted.', ['n' => $room['room_number']]));
                }
                redirect(admin_url('rooms.php'));

            case 'bulk_add':
                require_can('rooms.manage');
                $numbers = parse_room_numbers(req_str('numbers', $_POST, 2000));
                if (!$numbers) {
                    flash('danger', __('Enter room numbers, e.g. 101-120 or 101, 102, 105.'));
                    redirect(admin_url('rooms.php', ['action' => 'bulk_add']));
                }
                $floor = req_str('floor', $_POST, 20);
                $prefix = req_str('name_prefix', $_POST, 60);
                $groups = int_ids($_POST['groups'] ?? []);
                $added = 0;
                $skipped = [];
                DB::transaction(function () use ($numbers, $floor, $prefix, $groups, &$added, &$skipped) {
                    foreach ($numbers as $n) {
                        if (DB::value('SELECT id FROM rooms WHERE room_number = :n', ['n' => $n])) {
                            $skipped[] = $n;
                            continue;
                        }
                        $rid = DB::insert('rooms', [
                            'room_number' => $n,
                            'name' => $prefix !== '' ? trim($prefix . ' ' . $n) : null,
                            'floor' => $floor !== '' ? $floor : guess_floor($n),
                            'created_at' => now(),
                        ]);
                        if ($groups) {
                            save_room_groups($rid, $groups);
                        }
                        $added++;
                    }
                });
                Settings::bumpContentVersion();
                ActivityLog::add('room_bulk_add', 'room', null, "Added $added rooms: " . implode(', ', array_slice($numbers, 0, 30)));
                flash('success', __(':n rooms added.', ['n' => $added]) . ($skipped ? ' ' . __('Already existed: :list', ['list' => implode(', ', array_slice($skipped, 0, 20))]) : ''));
                redirect(admin_url('rooms.php'));

            case 'bulk':
                $ids = int_ids($_POST['room_ids'] ?? []);
                $do = req_str('bulk_action', $_POST, 30);
                if (!$ids) {
                    flash('warning', __('Select at least one room first.'));
                    redirect(admin_url('rooms.php'));
                }
                $count = count($ids);
                switch ($do) {
                    case 'assign':
                        require_can('rooms.manage');
                        [$cid, $pid] = parse_source($_POST['source'] ?? '');
                        if (!$cid && !$pid) {
                            flash('danger', __('Choose the content or playlist to assign.'));
                            redirect(admin_url('rooms.php'));
                        }
                        $bid = Broadcaster::pushNow('rooms', $ids, $cid, $pid, '', Auth::id());
                        ActivityLog::add('room_bulk_assign', 'broadcast', $bid, source_label($cid, $pid) . " → $count rooms");
                        flash('success', __('Content assigned to :n rooms. TVs will switch within a few seconds.', ['n' => $count]));
                        break;
                    case 'refresh':
                        require_can('broadcast.send');
                        [, $dev] = Broadcaster::sendCommand('SHOW_CONTENT', 'rooms', $ids, [], Auth::id());
                        flash('success', __('Refresh sent to :n TVs.', ['n' => $dev]));
                        break;
                    case 'SCREEN_ON':
                    case 'SCREEN_OFF':
                    case 'REBOOT':
                    case 'CLEAR_CACHE':
                    case 'RELOAD':
                    case 'PING':
                        require_can('broadcast.device_commands');
                        [$bid, $dev] = Broadcaster::sendCommand($do, 'rooms', $ids, [], Auth::id());
                        ActivityLog::add('device_command', 'broadcast', $bid, "$do → $count rooms ($dev TVs)");
                        flash('success', __(':cmd sent to :n TVs.', ['cmd' => command_label($do), 'n' => $dev]));
                        break;
                    case 'enable':
                    case 'disable':
                        require_can('rooms.manage');
                        [$in, $p] = DB::in($ids, 'r');
                        DB::query("UPDATE rooms SET is_enabled = :e WHERE id IN $in", $p + ['e' => $do === 'enable' ? 1 : 0]);
                        Settings::bumpContentVersion();
                        Broadcaster::queueForRooms(Broadcaster::targetRooms('rooms', $ids), 'SHOW_CONTENT');
                        flash('success', __('Saved.'));
                        break;
                    case 'delete':
                        require_can('rooms.manage');
                        [$in, $p] = DB::in($ids, 'r');
                        $nums = DB::column("SELECT room_number FROM rooms WHERE id IN $in", $p);
                        DB::query("DELETE FROM rooms WHERE id IN $in", $p);
                        Settings::bumpContentVersion();
                        ActivityLog::add('room_bulk_delete', 'room', null, 'Deleted rooms: ' . implode(', ', $nums));
                        flash('success', __(':n rooms deleted.', ['n' => count($nums)]));
                        break;
                    default:
                        flash('warning', __('Choose an action.'));
                }
                redirect(admin_url('rooms.php'));

            case 'revoke_device':
                require_can('rooms.manage');
                $did = req_int('device_id', $_POST);
                $dev = DB::one('SELECT * FROM devices WHERE id = :id', ['id' => $did]);
                if ($dev) {
                    DB::update('devices', ['is_revoked' => 1, 'status' => 'offline', 'token_hash' => hash('sha256', random_token(32))], 'id = :id', ['id' => $did]);
                    ActivityLog::add('device_revoke', 'device', $did, 'Revoked device ' . $dev['device_uid']);
                    flash('success', __('TV access revoked. It will return to its setup screen.'));
                }
                redirect(admin_url('rooms.php'));

            case 'delete_device':
                require_can('rooms.manage');
                $did = req_int('device_id', $_POST);
                $dev = DB::one('SELECT * FROM devices WHERE id = :id AND is_revoked = 1', ['id' => $did]);
                if ($dev) {
                    DB::delete('devices', 'id = :id', ['id' => $did]);
                    ActivityLog::add('device_delete', 'device', $did, 'Deleted device ' . $dev['device_uid']);
                    flash('success', __('Device removed.'));
                }
                redirect(admin_url('rooms.php', ['action' => 'devices']));
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect(admin_url('rooms.php'));
    }
    flash('warning', __('Unknown action.'));
    redirect(admin_url('rooms.php'));
}

// ---------------------------------------------------------------- Views
$action = req_str('action', $_GET, 20);
$canManage = Auth::can('rooms.manage');
$groupsAll = hc_groups();

if (in_array($action, ['new', 'edit', 'bulk_add'], true)) {
    require_can('rooms.manage');
}

$pageTitle = __('Rooms & TVs');
$activeNav = 'rooms';

// ---- Device detail
if ($action === 'device') {
    $did = req_int('id', $_GET);
    $dev = DB::one('SELECT d.*, r.room_number, r.name AS room_name FROM devices d LEFT JOIN rooms r ON r.id = d.room_id WHERE d.id = :id', ['id' => $did]);
    if (!$dev) {
        flash('warning', __('Device not found.'));
        redirect(admin_url('rooms.php'));
    }
    $cmds = DB::all('SELECT * FROM device_commands WHERE device_id = :d ORDER BY id DESC LIMIT 30', ['d' => $did]);
    $statusLog = DB::all('SELECT * FROM device_status_logs WHERE device_id = :d ORDER BY id DESC LIMIT 20', ['d' => $did]);
    $online = DeviceManager::isOnline($dev);
    $pageTitle = __('TV details');
    require __DIR__ . '/partials/header.php';
    $info = [
        __('Room') => $dev['room_number'] ? $dev['room_number'] . ($dev['room_name'] ? ' — ' . $dev['room_name'] : '') : '-',
        __('Status') => null,
        __('Last seen') => time_ago($dev['last_ping']) . ($dev['last_ping'] ? ' (' . $dev['last_ping'] . ')' : ''),
        __('Last heartbeat') => time_ago($dev['last_heartbeat']),
        __('Device ID') => $dev['device_uid'],
        __('App version') => trim(($dev['app_version'] ?? '-') . ($dev['app_version_code'] ? ' (' . $dev['app_version_code'] . ')' : '')),
        __('Android version') => $dev['android_version'] ?? '-',
        __('Model') => $dev['model'] ?? '-',
        __('LAN IP') => $dev['ip_address'] ?? '-',
        __('Public IP') => $dev['public_ip'] ?? '-',
        __('Network') => trim(($dev['network_type'] ?? '-') . ($dev['wifi_signal'] !== null ? ' · ' . $dev['wifi_signal'] . ' dBm' : '')),
        __('Free storage') => $dev['free_storage_mb'] !== null ? human_bytes((int) $dev['free_storage_mb'] * 1024 * 1024) : '-',
        __('Screen') => (int) $dev['screen_on'] ? __('On') : __('Off'),
        __('Uptime') => $dev['uptime_sec'] !== null ? floor((int) $dev['uptime_sec'] / 3600) . 'h ' . floor(((int) $dev['uptime_sec'] % 3600) / 60) . 'm' : '-',
        __('Registered') => $dev['registered_at'],
    ];
    ?>
    <div class="page-head">
      <div>
        <h1><i class="bi bi-tv"></i> <?= e(__('TV details')) ?> — <?= e($dev['room_number'] ?? '-') ?></h1>
        <p class="lead-sm mono"><?= e($dev['device_uid']) ?></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
        <?php if ($canManage && !(int) $dev['is_revoked']): ?>
        <form method="post" data-confirm="<?= e(__('Revoke this TV? It will stop showing content and go back to its setup screen until registered again.')) ?>">
          <?= Csrf::field() ?><input type="hidden" name="op" value="revoke_device"><input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
          <button class="btn btn-outline-danger"><i class="bi bi-slash-circle"></i> <?= e(__('Revoke TV')) ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><?= e(__('Heartbeat info')) ?></div>
          <table class="table table-sm table-hc mb-0">
            <?php foreach ($info as $k => $v): ?>
              <tr><th class="text-muted fw-normal ps-3" style="width:40%"><?= e($k) ?></th>
                <td><?php if ($v === null): ?><?= (int) $dev['is_revoked'] ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : status_badge($online ? 'online' : 'offline') ?><?php else: ?><?= e($v) ?><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header"><?= e(__('Recent commands')) ?></div>
          <div class="table-responsive">
            <table class="table table-sm table-hc">
              <thead><tr><th><?= e(__('Command')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Sent')) ?></th><th><?= e(__('Reply')) ?></th></tr></thead>
              <tbody>
              <?php if (!$cmds): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= e(__('No commands sent to this TV yet.')) ?></td></tr><?php endif; ?>
              <?php foreach ($cmds as $c): ?>
                <tr>
                  <td><?= e(command_label($c['command'])) ?></td>
                  <td><?= cmd_status_badge($c['status']) ?></td>
                  <td class="text-nowrap small"><?= e(time_ago($c['created_at'])) ?></td>
                  <td class="small"><?= e($c['message'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><?= e(__('Online / offline history')) ?></div>
          <ul class="list-group list-group-flush small">
            <?php if (!$statusLog): ?><li class="list-group-item text-muted"><?= e(__('No history yet.')) ?></li><?php endif; ?>
            <?php foreach ($statusLog as $s): ?>
              <li class="list-group-item d-flex justify-content-between"><?= status_badge($s['status']) ?><span class="text-muted"><?= e($s['created_at']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---- Add / edit room
if ($action === 'new' || $action === 'edit') {
    $room = ['id' => 0, 'room_number' => '', 'name' => '', 'floor' => '', 'is_enabled' => 1, 'settings_pin' => '', 'notes' => '', 'content_id' => null, 'playlist_id' => null];
    $memberOf = [];
    $devices = [];
    if ($action === 'edit') {
        $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$room) {
            flash('warning', __('Room not found.'));
            redirect(admin_url('rooms.php'));
        }
        $memberOf = array_map('intval', DB::column('SELECT group_id FROM room_group_members WHERE room_id = :r', ['r' => $room['id']]));
        $devices = DB::all('SELECT * FROM devices WHERE room_id = :r ORDER BY is_revoked, last_ping DESC', ['r' => $room['id']]);
    }
    $pageTitle = $room['id'] ? __('Edit room :n', ['n' => $room['room_number']]) : __('Add room');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><?= e($pageTitle) ?></h1>
      <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="row g-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $room['id'] ?>">
      <div class="col-lg-7">
        <div class="card"><div class="card-body row g-3">
          <div class="col-sm-4">
            <label class="form-label" for="room_number"><?= e(__('Room number')) ?> *</label>
            <input class="form-control" id="room_number" name="room_number" value="<?= e($room['room_number']) ?>" required maxlength="20" placeholder="101">
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="floor"><?= e(__('Floor')) ?></label>
            <input class="form-control" id="floor" name="floor" value="<?= e($room['floor']) ?>" maxlength="20" placeholder="1">
            <div class="form-text"><?= e(__('Empty = guessed from the number (101 → 1).')) ?></div>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="settings_pin"><?= e(__('TV settings PIN')) ?></label>
            <input class="form-control" id="settings_pin" name="settings_pin" value="<?= e($room['settings_pin']) ?>" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="<?= e(__('hotel PIN')) ?>">
            <div class="form-text"><?= e(__('4 digits. Empty = use the hotel-wide PIN.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="name"><?= e(__('Room name')) ?></label>
            <input class="form-control" id="name" name="name" value="<?= e($room['name']) ?>" maxlength="120" placeholder="<?= e(__('e.g. Deluxe 101')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="source"><?= e(__('What should this TV show?')) ?></label>
            <?= source_select('source', source_value($room['content_id'], $room['playlist_id']), __('— Follow group / hotel default —'), ['id' => 'source']) ?>
            <div class="form-text"><?= e(__('Leave on "Follow group / hotel default" so the TV shows its group content or the hotel default.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="notes"><?= e(__('Notes')) ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="500"><?= e($room['notes']) ?></textarea>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1"<?= (int) $room['is_enabled'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="is_enabled"><?= e(__('TV is ON (switch off to show a black screen)')) ?></label>
            </div>
          </div>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-header"><?= e(__('Groups')) ?></div>
          <div class="card-body">
            <?php if (!$groupsAll): ?><div class="text-muted small"><?= e(__('No groups yet. Create groups on the Groups page.')) ?></div><?php endif; ?>
            <?php foreach ($groupsAll as $g): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>" id="g<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $memberOf, true) ? ' checked' : '' ?>>
                <label class="form-check-label" for="g<?= (int) $g['id'] ?>"><?= e($g['name']) ?> <span class="text-muted small">(<?= e(__(ucfirst($g['type']))) ?>)</span></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($room['id']): ?>
        <div class="card">
          <div class="card-header"><?= e(__('TVs in this room')) ?></div>
          <ul class="list-group list-group-flush">
            <?php if (!$devices): ?><li class="list-group-item text-muted small"><?= e(__('No TV registered yet.')) ?></li><?php endif; ?>
            <?php foreach ($devices as $d): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center small">
                <span><?= (int) $d['is_revoked'] ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : status_badge(DeviceManager::isOnline($d) ? 'online' : 'offline') ?> <?= e($d['model'] ?? $d['device_uid']) ?></span>
                <a href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $d['id']])) ?>"><?= e(__('Details')) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save room')) ?></button>
        <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border btn-lg"><?= e(__('Cancel')) ?></a>
      </div>
    </form>
    <?php if ($room['id']): ?>
      <form method="post" class="mt-4" data-confirm="<?= e(__('Delete room :n? Its TV will stop receiving content until the room is added again.', ['n' => $room['room_number']])) ?>">
        <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $room['id'] ?>">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> <?= e(__('Delete this room')) ?></button>
      </form>
    <?php endif; ?>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---- Bulk add
if ($action === 'bulk_add') {
    $pageTitle = __('Add many rooms');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><?= e(__('Add many rooms')) ?></h1>
      <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <div class="card" style="max-width:720px"><div class="card-body">
      <form method="post" class="row g-3">
        <?= Csrf::field() ?><input type="hidden" name="op" value="bulk_add">
        <div class="col-12">
          <label class="form-label" for="numbers"><?= e(__('Room numbers')) ?> *</label>
          <input class="form-control form-control-lg" id="numbers" name="numbers" required placeholder="101-120" maxlength="2000">
          <div class="form-text"><?= e(__('Use a range like 101-120, or a list like 101, 102, 105. Combine them: 101-110, 201-210. Existing rooms are skipped.')) ?></div>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="bfloor"><?= e(__('Floor')) ?></label>
          <input class="form-control" id="bfloor" name="floor" maxlength="20" placeholder="<?= e(__('auto')) ?>">
          <div class="form-text"><?= e(__('Empty = guessed from each number (101 → 1, 205 → 2).')) ?></div>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="name_prefix"><?= e(__('Name prefix')) ?></label>
          <input class="form-control" id="name_prefix" name="name_prefix" maxlength="60" placeholder="<?= e(__('e.g. Deluxe')) ?>">
        </div>
        <?php if ($groupsAll): ?>
        <div class="col-12">
          <label class="form-label"><?= e(__('Add to groups')) ?></label><br>
          <?php foreach ($groupsAll as $g): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>" id="bg<?= (int) $g['id'] ?>">
              <label class="form-check-label" for="bg<?= (int) $g['id'] ?>"><?= e($g['name']) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-plus-lg"></i> <?= e(__('Add rooms')) ?></button></div>
      </form>
    </div></div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---- Revoked devices list
if ($action === 'devices') {
    $revoked = DB::all('SELECT d.*, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id WHERE d.is_revoked = 1 OR d.room_id IS NULL ORDER BY d.updated_at DESC');
    $pageTitle = __('Revoked & unassigned TVs');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><?= e($pageTitle) ?></h1>
      <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <div class="card"><div class="table-responsive">
      <table class="table table-hc">
        <thead><tr><th><?= e(__('Device ID')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('Model')) ?></th><th><?= e(__('Last seen')) ?></th><th></th></tr></thead>
        <tbody>
        <?php if (!$revoked): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('Nothing here.')) ?></td></tr><?php endif; ?>
        <?php foreach ($revoked as $d): ?>
          <tr>
            <td class="mono small"><a href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $d['id']])) ?>"><?= e($d['device_uid']) ?></a></td>
            <td><?= e($d['room_number'] ?? '-') ?></td>
            <td><?= e($d['model'] ?? '-') ?></td>
            <td><?= e(time_ago($d['last_ping'])) ?></td>
            <td class="text-end">
              <?php if ($canManage && (int) $d['is_revoked']): ?>
              <form method="post" class="d-inline" data-confirm="<?= e(__('Remove this TV record permanently?')) ?>">
                <?= Csrf::field() ?><input type="hidden" name="op" value="delete_device"><input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---- List
$q = req_str('q', $_GET, 60);
$fFloor = req_str('floor', $_GET, 20);
$fGroup = req_int('group', $_GET);
$fStatus = req_str('status', $_GET, 10);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(r.room_number LIKE :q1 OR r.name LIKE :q2 OR r.notes LIKE :q3)';
    $params += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
}
if ($fFloor !== '') {
    $where[] = 'r.floor = :floor';
    $params['floor'] = $fFloor;
}
if ($fGroup) {
    $where[] = 'EXISTS (SELECT 1 FROM room_group_members m WHERE m.room_id = r.id AND m.group_id = :grp)';
    $params['grp'] = $fGroup;
}
$rooms = DB::all('SELECT r.* FROM rooms r' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY LENGTH(r.floor), r.floor, LENGTH(r.room_number), r.room_number', $params);
$devMap = hc_devices_by_room();
$groupNames = [];
foreach (DB::all('SELECT m.room_id, g.name FROM room_group_members m JOIN room_groups g ON g.id = m.group_id ORDER BY g.name') as $row) {
    $groupNames[(int) $row['room_id']][] = $row['name'];
}
if (in_array($fStatus, ['online', 'offline', 'none'], true)) {
    $rooms = array_values(array_filter($rooms, fn ($r) => hc_room_status($devMap[(int) $r['id']] ?? []) === $fStatus));
}
$totalRooms = (int) DB::value('SELECT COUNT(*) FROM rooms');
$revokedCount = (int) DB::value('SELECT COUNT(*) FROM devices WHERE is_revoked = 1 OR room_id IS NULL');
$canCmd = Auth::can('broadcast.device_commands');

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Rooms & TVs')) ?></h1>
    <p class="lead-sm"><?= e(__(':n rooms', ['n' => $totalRooms])) ?></p>
  </div>
  <?php if ($canManage): ?>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= e(admin_url('rooms.php', ['action' => 'new'])) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= e(__('Add room')) ?></a>
    <a href="<?= e(admin_url('rooms.php', ['action' => 'bulk_add'])) ?>" class="btn btn-outline-primary"><i class="bi bi-plus-square-dotted"></i> <?= e(__('Add many rooms')) ?></a>
    <?php if ($revokedCount): ?><a href="<?= e(admin_url('rooms.php', ['action' => 'devices'])) ?>" class="btn btn-light border"><i class="bi bi-slash-circle"></i> <?= e(__('Revoked TVs')) ?> (<?= $revokedCount ?>)</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="hint-box mb-3">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <i class="bi bi-lightbulb fs-3 text-primary"></i>
    <div class="flex-grow-1">
      <strong><?= e(__('How to connect a new TV')) ?></strong>
      <ol class="mb-1 small ps-3">
        <li><?= e(__('Install the HotelCast app on the TV and open it.')) ?></li>
        <li><?= e(__('Enter the server address:')) ?> <code><?= e(base_url()) ?></code> <button type="button" class="btn btn-xs btn-light border" data-copy="<?= e(base_url()) ?>"><i class="bi bi-clipboard"></i></button></li>
        <?php if ($canManage): ?>
          <li><?= e(__('Enter the registration key:')) ?> <code><?= e((string) Settings::get('registration_key', '') ?: __('(not set — open Settings → Devices)')) ?></code>
            <?php if (Settings::get('registration_key', '')): ?><button type="button" class="btn btn-xs btn-light border" data-copy="<?= e((string) Settings::get('registration_key')) ?>"><i class="bi bi-clipboard"></i></button><?php endif; ?></li>
        <?php else: ?>
          <li><?= e(__('Ask your manager for the registration key.')) ?></li>
        <?php endif; ?>
        <li><?= e(__('Type the room number. The TV appears here within a few seconds.')) ?></li>
      </ol>
    </div>
  </div>
</div>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small" for="fq"><?= e(__('Search')) ?></label>
      <input class="form-control" id="fq" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Room number or name')) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small" for="ff"><?= e(__('Floor')) ?></label>
      <select class="form-select" id="ff" name="floor">
        <option value=""><?= e(__('All')) ?></option>
        <?php foreach (hc_floors() as $f): ?><option value="<?= e($f) ?>"<?= $f === $fFloor ? ' selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small" for="fg"><?= e(__('Group')) ?></label>
      <select class="form-select" id="fg" name="group">
        <option value=""><?= e(__('All')) ?></option>
        <?php foreach ($groupsAll as $g): ?><option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === $fGroup ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small" for="fs"><?= e(__('Status')) ?></label>
      <select class="form-select" id="fs" name="status">
        <option value=""><?= e(__('All')) ?></option>
        <option value="online"<?= $fStatus === 'online' ? ' selected' : '' ?>><?= e(__('Online')) ?></option>
        <option value="offline"<?= $fStatus === 'offline' ? ' selected' : '' ?>><?= e(__('Offline')) ?></option>
        <option value="none"<?= $fStatus === 'none' ? ' selected' : '' ?>><?= e(__('No TV')) ?></option>
      </select>
    </div>
    <div class="col-6 col-md-2 d-flex gap-1">
      <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> <?= e(__('Filter')) ?></button>
      <?php if ($q !== '' || $fFloor !== '' || $fGroup || $fStatus !== ''): ?><a class="btn btn-light border" href="<?= e(admin_url('rooms.php')) ?>" title="<?= e(__('Clear')) ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$rooms): ?>
  <div class="card"><div class="hc-empty">
    <i class="bi bi-door-open"></i>
    <?php if ($totalRooms === 0): ?>
      <p class="mb-1"><strong><?= e(__('No rooms yet')) ?></strong></p>
      <p class="text-muted"><?= e(__('Add rooms, or let TVs auto-register: open the app on a TV and type its room number.')) ?></p>
      <?php if ($canManage): ?>
        <a class="btn btn-primary" href="<?= e(admin_url('rooms.php', ['action' => 'bulk_add'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add rooms')) ?></a>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted"><?= e(__('No rooms match your filter.')) ?></p>
    <?php endif; ?>
  </div></div>
<?php else: ?>
<form method="post" id="bulkForm">
  <?= Csrf::field() ?><input type="hidden" name="op" value="bulk">
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hc table-hover align-middle">
        <thead>
          <tr>
            <th style="width:2rem"><input type="checkbox" class="form-check-input" data-check-all=".room-cb" aria-label="<?= e(__('Select all')) ?>"></th>
            <th><?= e(__('Room')) ?></th>
            <th class="d-none d-md-table-cell"><?= e(__('Floor')) ?></th>
            <th class="d-none d-lg-table-cell"><?= e(__('Groups')) ?></th>
            <th><?= e(__('Status')) ?></th>
            <th><?= e(__('Now showing')) ?></th>
            <th class="d-none d-xl-table-cell"><?= e(__('TV info')) ?></th>
            <th class="text-end"><?= e(__('Actions')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $r):
            $devs = $devMap[(int) $r['id']] ?? [];
            $st = hc_room_status($devs);
            $d = $devs[0] ?? null;
            try {
                $content = ContentResolver::forRoom($r);
                $showing = ContentResolver::describe($content);
                $mode = $content['mode'];
            } catch (Throwable $ex) {
                $showing = '-';
                $mode = '';
            }
        ?>
          <tr>
            <td><input type="checkbox" class="form-check-input room-cb" name="room_ids[]" value="<?= (int) $r['id'] ?>" aria-label="<?= e($r['room_number']) ?>"></td>
            <td>
              <strong><?= e($r['room_number']) ?></strong>
              <?php if (!(int) $r['is_enabled']): ?><span class="badge text-bg-dark ms-1"><?= e(__('Off')) ?></span><?php endif; ?>
              <?php if ($r['name']): ?><div class="small text-muted"><?= e($r['name']) ?></div><?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell"><?= e($r['floor'] ?? '-') ?></td>
            <td class="d-none d-lg-table-cell small"><?= e(implode(', ', $groupNames[(int) $r['id']] ?? []) ?: '-') ?></td>
            <td>
              <?= status_badge($st) ?>
              <?php if ($d): ?><div class="small text-muted"><?= e(time_ago($d['last_ping'])) ?></div><?php endif; ?>
              <?php if (count($devs) > 1): ?><div class="small text-muted"><?= e(__(':n TVs', ['n' => count($devs)])) ?></div><?php endif; ?>
            </td>
            <td class="small">
              <?= e($showing) ?>
              <div class="text-muted"><?= e(mode_label($mode)) ?></div>
            </td>
            <td class="d-none d-xl-table-cell small text-muted">
              <?php if ($d): ?>
                <?= e(($d['model'] ?? '-') . ' · Android ' . ($d['android_version'] ?? '?')) ?><br>
                <?= e('v' . ($d['app_version'] ?? '?') . ' · ' . ($d['ip_address'] ?? '-')) ?>
                <?php if ($d['wifi_signal'] !== null): ?> · <i class="bi bi-wifi"></i> <?= e($d['wifi_signal']) ?> dBm<?php endif; ?>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light border" href="<?= e(admin_url('preview.php', ['room_id' => $r['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview what this TV shows')) ?>"><i class="bi bi-eye"></i></a>
              <?php if ($d): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $d['id']])) ?>" title="<?= e(__('TV details')) ?>"><i class="bi bi-info-circle"></i></a><?php endif; ?>
              <?php if ($canManage): ?><a class="btn btn-sm btn-primary" href="<?= e(admin_url('rooms.php', ['action' => 'edit', 'id' => $r['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bulk-bar card card-body mt-3 shadow">
    <div class="row g-2 align-items-center">
      <div class="col-12 col-md-auto small text-muted"><i class="bi bi-check2-square"></i> <span id="selCount">0</span> <?= e(__('selected')) ?></div>
      <div class="col-12 col-md-3">
        <select class="form-select" name="bulk_action" id="bulkAction" aria-label="<?= e(__('Action')) ?>">
          <option value=""><?= e(__('— Choose action —')) ?></option>
          <?php if ($canManage): ?><option value="assign"><?= e(__('Assign content')) ?></option><?php endif; ?>
          <?php if (Auth::can('broadcast.send')): ?><option value="refresh"><?= e(__('Refresh content')) ?></option><?php endif; ?>
          <?php if ($canCmd): ?>
            <option value="SCREEN_ON"><?= e(__('Turn TV screen on')) ?></option>
            <option value="SCREEN_OFF"><?= e(__('Turn TV screen off')) ?></option>
            <option value="REBOOT"><?= e(__('Reboot TV')) ?></option>
            <option value="CLEAR_CACHE"><?= e(__('Clear cache')) ?></option>
            <option value="RELOAD"><?= e(__('Restart app')) ?></option>
            <option value="PING"><?= e(__('Ping (test)')) ?></option>
          <?php endif; ?>
          <?php if ($canManage): ?><option value="delete"><?= e(__('Delete rooms')) ?></option><?php endif; ?>
        </select>
      </div>
      <div class="col-12 col-md-4" id="bulkSource" hidden>
        <?= source_select('source', '', __('— Choose content —'), ['aria-label' => __('Content')]) ?>
      </div>
      <div class="col-12 col-md-auto">
        <button class="btn btn-primary w-100" data-confirm="<?= e(__('Apply this action to the selected rooms?')) ?>" data-confirm-safe="1"><i class="bi bi-lightning-charge"></i> <?= e(__('Apply')) ?></button>
      </div>
    </div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('bulkAction');
  const src = document.getElementById('bulkSource');
  const count = () => { document.getElementById('selCount').textContent = document.querySelectorAll('.room-cb:checked').length; };
  sel.addEventListener('change', () => { src.hidden = sel.value !== 'assign'; });
  document.querySelectorAll('.room-cb').forEach((c) => c.addEventListener('change', count));
  document.addEventListener('hc:selection', count);
  document.getElementById('bulkForm').addEventListener('submit', (ev) => {
    if (!document.querySelectorAll('.room-cb:checked').length || !sel.value) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      HC.toast(<?= json_embed(__('Select rooms and an action first.')) ?>, 'warning');
    }
  }, true);
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
