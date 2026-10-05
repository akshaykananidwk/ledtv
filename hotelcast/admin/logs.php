<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('logs.view');
Csrf::check();

const PER_PAGE = 50;
$tabs = [
    'status' => ['bi-wifi', __('TV online/offline')],
    'played' => ['bi-play-circle', __('Content played')],
    'broadcasts' => ['bi-broadcast', __('Broadcasts')],
    'activity' => ['bi-person-lines-fill', __('User activity')],
    'errors' => ['bi-bug', __('System errors')],
    'update' => ['bi-cloud-arrow-down', __('Update log')],
];
// Server-wide logs (errors of all hotels, updater) are for the platform admin only.
if (!Auth::can('update.manage')) {
    unset($tabs['errors'], $tabs['update']);
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'status';
$page = max(1, req_int('page', $_GET));
$export = ($_GET['export'] ?? '') === 'csv';
$fRoom = req_int('room', $_GET);
$fUser = req_int('user', $_GET);
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$fTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';

/** Common hotel + date + room filters → [whereSql, params]. The hotel column comes from the date column's alias. */
function log_filters(string $dateCol, ?string $roomCol, string $from, string $to, int $room): array
{
    $alias = str_contains($dateCol, '.') ? strtok($dateCol, '.') . '.' : '';
    $w = [$alias . 'hotel_id = :hid'];
    $p = hid();
    if ($from !== '') {
        $w[] = "$dateCol >= :dfrom";
        $p['dfrom'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $w[] = "$dateCol <= :dto";
        $p['dto'] = $to . ' 23:59:59';
    }
    if ($roomCol && $room) {
        $w[] = "$roomCol = :room";
        $p['room'] = $room;
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

$rows = [];
$total = 0;
$offset = ($page - 1) * PER_PAGE;
$limitSql = $export ? ' LIMIT 50000' : ' LIMIT ' . PER_PAGE . ' OFFSET ' . $offset;

switch ($tab) {
    case 'status':
        [$where, $p] = log_filters('l.created_at', 'l.room_id', $fFrom, $fTo, $fRoom);
        $total = (int) DB::value("SELECT COUNT(*) FROM device_status_logs l $where", $p);
        $rows = DB::all("SELECT l.*, r.room_number, d.device_uid, d.model FROM device_status_logs l
                         LEFT JOIN rooms r ON r.id = l.room_id LEFT JOIN devices d ON d.id = l.device_id
                         $where ORDER BY l.id DESC" . $limitSql, $p);
        if ($export) {
            csv_download('tv_status_' . date('Ymd') . '.csv', ['Time', 'Room', 'Status', 'Device', 'Model'],
                array_map(fn ($r) => [$r['created_at'], $r['room_number'], $r['status'], $r['device_uid'], $r['model']], $rows));
        }
        break;
    case 'played':
        [$where, $p] = log_filters('l.created_at', 'l.room_id', $fFrom, $fTo, $fRoom);
        $where = $where ? $where . " AND l.event = 'played'" : "WHERE l.event = 'played'";
        $total = (int) DB::value("SELECT COUNT(*) FROM broadcast_logs l $where", $p);
        $rows = DB::all("SELECT l.*, r.room_number, c.title, c.type FROM broadcast_logs l
                         LEFT JOIN rooms r ON r.id = l.room_id LEFT JOIN content_items c ON c.id = l.content_id
                         $where ORDER BY l.id DESC" . $limitSql, $p);
        if ($export) {
            csv_download('content_played_' . date('Ymd') . '.csv', ['Time', 'Room', 'Content', 'Type', 'Seconds'],
                array_map(fn ($r) => [$r['created_at'], $r['room_number'], $r['title'] ?? ('#' . $r['content_id']), $r['type'], $r['duration_sec']], $rows));
        }
        break;
    case 'broadcasts':
        $detail = req_int('id', $_GET);
        if ($detail) {
            Tenant::find('broadcast_commands', $detail); // 404 for another hotel's broadcast
            $bc = DB::one('SELECT b.*, u.username FROM broadcast_commands b LEFT JOIN users u ON u.id = b.created_by WHERE b.id = :id AND b.hotel_id = :hid', ['id' => $detail] + hid());
            $total = (int) DB::value('SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :hid AND broadcast_id = :b', ['b' => $detail] + hid());
            $rows = DB::all('SELECT l.*, r.room_number FROM broadcast_logs l LEFT JOIN rooms r ON r.id = l.room_id WHERE l.hotel_id = :hid AND l.broadcast_id = :b ORDER BY l.id DESC' . $limitSql, ['b' => $detail] + hid());
            if ($export) {
                csv_download('broadcast_' . $detail . '.csv', ['Time', 'Room', 'Event', 'Message'], array_map(fn ($r) => [$r['created_at'], $r['room_number'], $r['event'], $r['message']], $rows));
            }
            break;
        }
        [$where, $p] = log_filters('b.created_at', null, $fFrom, $fTo, 0);
        $total = (int) DB::value("SELECT COUNT(*) FROM broadcast_commands b $where", $p);
        $rows = DB::all("SELECT b.*, u.username FROM broadcast_commands b LEFT JOIN users u ON u.id = b.created_by $where ORDER BY b.id DESC" . $limitSql, $p);
        $stats = [];
        if ($rows) {
            [$in, $ip] = DB::in(array_map(fn ($b) => (int) $b['id'], $rows), 'b');
            foreach (DB::all("SELECT broadcast_id, status, COUNT(*) AS n FROM device_commands WHERE broadcast_id IN $in GROUP BY broadcast_id, status", $ip) as $s) {
                $stats[(int) $s['broadcast_id']][$s['status']] = (int) $s['n'];
            }
        }
        if ($export) {
            csv_download('broadcasts_' . date('Ymd') . '.csv', ['Time', 'Title', 'Command', 'Mode', 'Status', 'Target', 'By', 'Acked', 'Delivered', 'Pending', 'Failed', 'Expired'],
                array_map(fn ($b) => [$b['created_at'], $b['title'], $b['command'], $b['mode'], $b['status'], Broadcaster::describeTarget($b['target_type'], $b['target_ids']), $b['username'],
                    $stats[(int) $b['id']]['acked'] ?? 0, $stats[(int) $b['id']]['delivered'] ?? 0, $stats[(int) $b['id']]['pending'] ?? 0, $stats[(int) $b['id']]['failed'] ?? 0, $stats[(int) $b['id']]['expired'] ?? 0], $rows));
        }
        break;
    case 'activity':
        [$where, $p] = log_filters('a.created_at', null, $fFrom, $fTo, 0);
        if ($fUser) {
            $where = ($where ? $where . ' AND' : 'WHERE') . ' a.user_id = :uid';
            $p['uid'] = $fUser;
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM activity_logs a $where", $p);
        $rows = DB::all("SELECT a.* FROM activity_logs a $where ORDER BY a.id DESC" . $limitSql, $p);
        if ($export) {
            csv_download('activity_' . date('Ymd') . '.csv', ['Time', 'User', 'Action', 'Entity', 'Entity ID', 'Details', 'IP'],
                array_map(fn ($a) => [$a['created_at'], $a['username'], $a['action'], $a['entity_type'], $a['entity_id'], $a['details'], $a['ip_address']], $rows));
        }
        break;
}

$pageTitle = __('Logs & History');
$activeNav = 'logs';
require __DIR__ . '/partials/header.php';
$exportUrl = self_url(['export' => 'csv', 'page' => null]);
$filterForm = function (bool $room, bool $userSel) use ($tab, $fRoom, $fUser, $fFrom, $fTo, $exportUrl): void {
    ?>
    <form method="get" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <?php if (!empty($_GET['id'])): ?><input type="hidden" name="id" value="<?= (int) $_GET['id'] ?>"><?php endif; ?>
      <?php if ($room): ?>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="lroom"><?= e(__('Room')) ?></label>
        <select class="form-select form-select-sm" id="lroom" name="room">
          <option value=""><?= e(__('All rooms')) ?></option>
          <?php foreach (hc_rooms() as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === $fRoom ? ' selected' : '' ?>><?= e($r['room_number']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($userSel): ?>
      <div class="col-6 col-md-3">
        <label class="form-label small" for="luser"><?= e(__('User')) ?></label>
        <select class="form-select form-select-sm" id="luser" name="user">
          <option value=""><?= e(__('All users')) ?></option>
          <?php foreach (DB::all('SELECT id, username FROM users WHERE hotel_id = :hid ORDER BY username', hid()) as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $fUser ? ' selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-2"><label class="form-label small" for="lfrom"><?= e(__('From')) ?></label><input type="date" class="form-control form-control-sm" id="lfrom" name="from" value="<?= e($fFrom) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="lto"><?= e(__('To')) ?></label><input type="date" class="form-control form-control-sm" id="lto" name="to" value="<?= e($fTo) ?>"></div>
      <div class="col-12 col-md-auto d-flex gap-1">
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> <?= e(__('Filter')) ?></button>
        <a class="btn btn-sm btn-light border" href="<?= e($exportUrl) ?>"><i class="bi bi-download"></i> CSV</a>
      </div>
    </form>
    <?php
};
?>
<div class="page-head"><h1><?= e(__('Logs & History')) ?></h1></div>
<ul class="nav nav-tabs nav-tabs-scroll mb-3">
  <?php foreach ($tabs as $k => [$icon, $label]): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('logs.php', ['tab' => $k])) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'errors'): ?>
  <div class="row g-3">
    <div class="col-xl-6"><div class="card"><div class="card-header"><?= e(__('Application errors')) ?> (logs/error.log)</div><div class="card-body">
      <?php $lines = Logger::tail('error', 300); ?>
      <?php if (!$lines): ?><div class="text-success"><i class="bi bi-check-circle"></i> <?= e(__('No errors logged.')) ?></div><?php else: ?><pre class="log-pre mb-0"><?= e(implode("\n", $lines)) ?></pre><?php endif; ?>
    </div></div></div>
    <div class="col-xl-6"><div class="card"><div class="card-header"><?= e(__('PHP errors')) ?> (logs/php_error.log)</div><div class="card-body">
      <?php $lines = Logger::tail('php_error', 300); ?>
      <?php if (!$lines): ?><div class="text-success"><i class="bi bi-check-circle"></i> <?= e(__('No errors logged.')) ?></div><?php else: ?><pre class="log-pre mb-0"><?= e(implode("\n", $lines)) ?></pre><?php endif; ?>
    </div></div></div>
  </div>
<?php elseif ($tab === 'update'): ?>
  <div class="card"><div class="card-header"><?= e(__('Update log')) ?> (logs/update.log)</div><div class="card-body">
    <?php $lines = Logger::tail('update', 500); ?>
    <?php if (!$lines): ?><div class="text-muted"><?= e(__('No updates have run yet.')) ?></div><?php else: ?><pre class="log-pre mb-0"><?= e(implode("\n", $lines)) ?></pre><?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="card"><div class="card-body pb-0">
    <?php if ($tab === 'broadcasts' && !empty($bc)): ?>
      <div class="mb-3">
        <a href="<?= e(admin_url('logs.php', ['tab' => 'broadcasts'])) ?>" class="small"><i class="bi bi-arrow-left"></i> <?= e(__('All broadcasts')) ?></a>
        <h2 class="h5 mt-2 mb-0"><?= e($bc['title']) ?> <?= broadcast_status_badge($bc['status']) ?></h2>
        <div class="small text-muted"><?= e(command_label($bc['command'])) ?> · <?= e(Broadcaster::describeTarget($bc['target_type'], $bc['target_ids'])) ?> · <?= e($bc['created_at']) ?> · <?= e($bc['username'] ?? '-') ?></div>
      </div>
    <?php endif; ?>
    <?php $filterForm(in_array($tab, ['status', 'played'], true), $tab === 'activity'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hc table-sm table-hover">
      <?php if ($tab === 'status'): ?>
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-md-table-cell"><?= e(__('TV')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="small text-nowrap"><?= e($r['created_at']) ?></td><td><?= e($r['room_number'] ?? '-') ?></td><td><?= status_badge($r['status']) ?></td>
            <td class="d-none d-md-table-cell small"><a href="<?= e(admin_url('rooms.php', ['action' => 'device', 'id' => $r['device_id']])) ?>"><?= e($r['model'] ?: $r['device_uid'] ?: '#' . $r['device_id']) ?></a></td></tr>
        <?php endforeach; ?>
      <?php elseif ($tab === 'played'): ?>
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('Content')) ?></th><th><?= e(__('Seconds')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="small text-nowrap"><?= e($r['created_at']) ?></td><td><?= e($r['room_number'] ?? '-') ?></td>
            <td><?= e($r['title'] ?? ('#' . $r['content_id'])) ?> <?php if ($r['type']): ?><span class="small text-muted"><?= e(__(ContentManager::TYPES[$r['type']] ?? $r['type'])) ?></span><?php endif; ?></td>
            <td><?= e($r['duration_sec'] ?? '-') ?></td></tr>
        <?php endforeach; ?>
      <?php elseif ($tab === 'broadcasts' && !empty($bc)): ?>
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('Event')) ?></th><th><?= e(__('Message')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="small text-nowrap"><?= e($r['created_at']) ?></td><td><?= e($r['room_number'] ?? '-') ?></td><td><?= cmd_status_badge(in_array($r['event'], ['acked', 'delivered', 'failed'], true) ? $r['event'] : null) ?: '' ?> <span class="small"><?= e($r['event']) ?></span></td><td class="small"><?= e($r['message']) ?></td></tr>
        <?php endforeach; ?>
      <?php elseif ($tab === 'broadcasts'): ?>
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('What')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Target')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Delivery')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('By')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $b): $s = $stats[(int) $b['id']] ?? []; ?>
          <tr>
            <td class="small text-nowrap"><a href="<?= e(admin_url('logs.php', ['tab' => 'broadcasts', 'id' => $b['id']])) ?>"><?= e($b['created_at']) ?></a></td>
            <td><?php if ((int) $b['is_emergency']): ?><span class="badge text-bg-danger"><?= e(__('Emergency')) ?></span> <?php endif; ?><?= e(in_array($b['command'], ['SHOW_CONTENT', 'EMERGENCY'], true) && $b['title'] !== $b['command'] ? $b['title'] : command_label($b['command'])) ?>
              <?php if ($b['mode'] !== 'now'): ?><div class="small text-muted"><?= e(schedule_summary($b)) ?></div><?php endif; ?></td>
            <td class="d-none d-md-table-cell small"><?= e(Broadcaster::describeTarget($b['target_type'], $b['target_ids'])) ?></td>
            <td><?= broadcast_status_badge($b['status']) ?></td>
            <td class="small text-nowrap"><?php if ($s): ?>✔ <?= (int) ($s['acked'] ?? 0) ?> · ↓ <?= (int) ($s['delivered'] ?? 0) ?> · ⏳ <?= (int) ($s['pending'] ?? 0) ?><?= !empty($s['failed']) ? ' · ✖ ' . (int) $s['failed'] : '' ?><?= !empty($s['expired']) ? ' · ⌛ ' . (int) $s['expired'] : '' ?><?php else: ?>-<?php endif; ?></td>
            <td class="d-none d-lg-table-cell small"><?= e($b['username'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php elseif ($tab === 'activity'): ?>
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('User')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('Details')) ?></th><th class="d-none d-md-table-cell">IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
          <tr><td class="small text-nowrap"><?= e($a['created_at']) ?></td><td><?= e($a['username'] ?? __('system')) ?></td><td><span class="badge text-bg-light border"><?= e($a['action']) ?></span></td><td class="small"><?= e($a['details']) ?></td><td class="d-none d-md-table-cell small"><?= e($a['ip_address']) ?></td></tr>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No records for this filter.')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
  </div>
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="small text-muted"><?= e(__(':n records', ['n' => $total])) ?></span>
    <?= paginate($total, $page, PER_PAGE) ?>
  </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
