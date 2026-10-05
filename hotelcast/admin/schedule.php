<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('schedule.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $b = $id ? DB::one("SELECT * FROM broadcast_commands WHERE id = :id AND mode IN ('once','window') AND is_emergency = 0", ['id' => $id]) : null;
    if (!$b) {
        flash('warning', __('Schedule not found.'));
        redirect(admin_url('schedule.php'));
    }
    switch ($op) {
        case 'update':
            if (!in_array($b['status'], ['scheduled', 'active'], true)) {
                flash('warning', __('Only upcoming or running schedules can be edited.'));
                redirect(admin_url('schedule.php'));
            }
            $in = $_POST;
            [$data, $errors] = Broadcaster::validateSchedule($in);
            [$cid, $pid] = parse_source($_POST['source'] ?? '');
            if (!$cid && !$pid && !$errors) {
                $errors[] = __('Select content or a playlist.');
            }
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('schedule.php', ['action' => 'edit', 'id' => $id]));
            }
            DB::update('broadcast_commands', [
                'title' => mb_substr((string) $data['title'], 0, 190),
                'target_type' => $data['target_type'],
                'target_ids' => json_out($data['target_ids']),
                'content_id' => $data['content_id'] ?: null,
                'playlist_id' => $data['playlist_id'] ?: null,
                'mode' => $data['mode'],
                'status' => 'scheduled',
                'start_at' => $data['start_at'] ?: null,
                'end_at' => $data['end_at'] ?: null,
                'daily_start' => $data['daily_start'] ?: null,
                'daily_end' => $data['daily_end'] ?: null,
                'repeat_days' => $data['repeat_days'] ?: null,
            ], 'id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
            if ($b['status'] === 'active') {
                // Let TVs that were showing it re-evaluate.
                Broadcaster::queueForRooms(Broadcaster::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], $id);
            }
            Scheduler::tick(true);
            ActivityLog::add('schedule_update', 'broadcast', $id, $data['title']);
            flash('success', __('Schedule updated.'));
            break;
        case 'cancel':
            $wasActive = $b['status'] === 'active';
            Broadcaster::cancel($id);
            if ($wasActive) {
                Broadcaster::queueForRooms(Broadcaster::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT', [], $id);
            }
            ActivityLog::add('schedule_cancel', 'broadcast', $id, $b['title']);
            flash('success', __('Schedule cancelled.'));
            break;
        case 'delete':
            if (in_array($b['status'], ['scheduled', 'active'], true)) {
                Broadcaster::cancel($id);
                Broadcaster::queueForRooms(Broadcaster::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT');
            }
            DB::delete('broadcast_commands', 'id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
            ActivityLog::add('schedule_delete', 'broadcast', $id, $b['title']);
            flash('success', __('Schedule deleted.'));
            break;
    }
    redirect(admin_url('schedule.php'));
}

Scheduler::tick();
$action = req_str('action', $_GET, 20);
$pageTitle = __('Schedule');
$activeNav = 'schedule';
$dtLocal = fn (?string $d) => $d ? date('Y-m-d\TH:i', (int) strtotime($d)) : '';

if ($action === 'edit') {
    $id = req_int('id', $_GET);
    $b = DB::one("SELECT * FROM broadcast_commands WHERE id = :id AND mode IN ('once','window') AND is_emergency = 0", ['id' => $id]);
    if (!$b) {
        flash('warning', __('Schedule not found.'));
        redirect(admin_url('schedule.php'));
    }
    $editable = in_array($b['status'], ['scheduled', 'active'], true);
    $days = $b['repeat_days'] ? array_map('intval', explode(',', (string) $b['repeat_days'])) : [];
    $ids = json_decode((string) $b['target_ids'], true) ?: [];
    $pageTitle = __('Edit schedule');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><?= e($pageTitle) ?></h1><p class="lead-sm"><?= broadcast_status_badge($b['status']) ?> <?= e(schedule_summary($b)) ?></p></div>
      <a href="<?= e(admin_url('schedule.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if (!$editable): ?><div class="alert alert-info"><?= e(__('This schedule has finished or was cancelled. It is shown read-only.')) ?></div><?php endif; ?>
    <form method="post" class="card card-body" id="schedForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="update"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <fieldset<?= $editable ? '' : ' disabled' ?>>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="stitle"><?= e(__('Name')) ?></label>
          <input class="form-control" id="stitle" name="title" value="<?= e($b['title']) ?>" maxlength="190">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="ssource"><?= e(__('What to show')) ?></label>
          <?= source_select('source', source_value($b['content_id'], $b['playlist_id']), __('— Choose content or playlist —'), ['id' => 'ssource']) ?>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(__('On which TVs')) ?></label>
          <?= target_picker('sch', ['type' => $b['target_type'], 'ids' => $ids]) ?>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(__('Type')) ?></label><br>
          <div class="btn-group" role="group">
            <input type="radio" class="btn-check" name="mode" value="once" id="m_once"<?= $b['mode'] === 'once' ? ' checked' : '' ?>>
            <label class="btn btn-outline-primary" for="m_once"><?= e(__('At a set time')) ?></label>
            <input type="radio" class="btn-check" name="mode" value="window" id="m_window"<?= $b['mode'] === 'window' ? ' checked' : '' ?>>
            <label class="btn btn-outline-primary" for="m_window"><?= e(__('During a time window')) ?></label>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <label class="form-label" for="start_at"><?= e(__('Start at')) ?></label>
          <input type="datetime-local" class="form-control" id="start_at" name="start_at" value="<?= e($dtLocal($b['start_at'])) ?>">
        </div>
        <div class="col-sm-6 col-lg-3" data-mode="window">
          <label class="form-label" for="end_at"><?= e(__('Until (optional)')) ?></label>
          <input type="datetime-local" class="form-control" id="end_at" name="end_at" value="<?= e($dtLocal($b['end_at'])) ?>">
        </div>
        <div class="col-6 col-lg-3" data-mode="window">
          <label class="form-label" for="daily_start"><?= e(__('Daily from')) ?></label>
          <input type="time" class="form-control" id="daily_start" name="daily_start" value="<?= e($b['daily_start'] ? substr((string) $b['daily_start'], 0, 5) : '') ?>">
        </div>
        <div class="col-6 col-lg-3" data-mode="window">
          <label class="form-label" for="daily_end"><?= e(__('Daily until')) ?></label>
          <input type="time" class="form-control" id="daily_end" name="daily_end" value="<?= e($b['daily_end'] ? substr((string) $b['daily_end'], 0, 5) : '') ?>">
        </div>
        <div class="col-12" data-mode="window">
          <label class="form-label d-block"><?= e(__('Repeat on')) ?></label>
          <?php foreach (day_names() as $n => $dn): ?>
            <input type="checkbox" class="btn-check" name="repeat_days[]" value="<?= $n ?>" id="rd<?= $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?> autocomplete="off">
            <label class="btn btn-sm btn-outline-secondary mb-1" for="rd<?= $n ?>"><?= e($dn) ?></label>
          <?php endforeach; ?>
        </div>
        <?php if ($editable): ?>
        <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save changes')) ?></button></div>
        <?php endif; ?>
      </div>
      </fieldset>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const f = document.getElementById('schedForm');
      const sync = () => {
        const m = (f.querySelector('input[name=mode]:checked') || {}).value;
        f.querySelectorAll('[data-mode]').forEach((el) => { el.hidden = el.dataset.mode !== m; });
      };
      f.querySelectorAll('input[name=mode]').forEach((r) => r.addEventListener('change', sync));
      sync();
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$filter = in_array($_GET['status'] ?? '', ['upcoming', 'past', 'all'], true) ? $_GET['status'] : 'upcoming';
$statusSql = match ($filter) {
    'upcoming' => "AND status IN ('scheduled','active')",
    'past' => "AND status IN ('completed','cancelled')",
    default => '',
};
$rows = DB::all("SELECT b.*, u.username FROM broadcast_commands b LEFT JOIN users u ON u.id = b.created_by
                 WHERE mode IN ('once','window') AND is_emergency = 0 $statusSql ORDER BY (status = 'active') DESC, start_at IS NULL, start_at DESC, id DESC LIMIT 200");

$extraScripts = ['vendor/fullcalendar/index.global.min.js'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Schedule')) ?></h1>
    <p class="lead-sm"><?= e(__('Planned content changes. Click an entry to edit it.')) ?></p>
  </div>
  <a class="btn btn-primary" href="<?= e(admin_url('broadcast.php')) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New schedule')) ?></a>
</div>

<div class="card mb-3">
  <div class="card-body"><div id="calendar"></div></div>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <span><?= e(__('List')) ?></span>
    <div class="btn-group btn-group-sm ms-auto">
      <?php foreach (['upcoming' => __('Upcoming & running'), 'past' => __('Finished'), 'all' => __('All')] as $k => $lbl): ?>
        <a class="btn btn-outline-secondary<?= $filter === $k ? ' active' : '' ?>" href="<?= e(admin_url('schedule.php', ['status' => $k])) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hc table-hover">
      <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('When')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Target')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5"><div class="hc-empty py-4"><i class="bi bi-calendar-week"></i><p class="text-muted mb-0"><?= e(__('Nothing scheduled. Use Broadcast → "At a set time" or "During a time window" to plan content.')) ?></p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $b): $live = in_array($b['status'], ['scheduled', 'active'], true); ?>
        <tr>
          <td><strong><?= e($b['title']) ?></strong><div class="small text-muted"><?= e(source_label($b['content_id'], $b['playlist_id'])) ?></div></td>
          <td class="small"><?= e(schedule_summary($b)) ?></td>
          <td class="d-none d-md-table-cell small"><?= e(Broadcaster::describeTarget($b['target_type'], $b['target_ids'])) ?></td>
          <td><?= broadcast_status_badge($b['status']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('schedule.php', ['action' => 'edit', 'id' => $b['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi <?= $live ? 'bi-pencil' : 'bi-eye' ?>"></i></a>
            <?php if ($live): ?>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Cancel this schedule? TVs return to their normal content.')) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm btn-outline-warning" title="<?= e(__('Cancel')) ?>"><i class="bi bi-x-circle"></i></button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete this schedule permanently?')) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('calendar');
  if (!window.FullCalendar || !el) return;
  const mobile = window.matchMedia('(max-width: 767px)').matches;
  const cal = new FullCalendar.Calendar(el, {
    initialView: mobile ? 'listWeek' : 'timeGridWeek',
    headerToolbar: mobile ? { left: 'prev,next', center: 'title', right: 'listWeek,dayGridMonth' } : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
    firstDay: 1,
    nowIndicator: true,
    height: mobile ? 'auto' : 640,
    locale: document.documentElement.lang === 'gu' ? 'gu' : 'en',
    buttonText: { today: <?= json_embed(__('Today')) ?>, month: <?= json_embed(__('Month')) ?>, week: <?= json_embed(__('Week')) ?>, list: <?= json_embed(__('List')) ?> },
    noEventsText: <?= json_embed(__('Nothing scheduled')) ?>,
    events: (info, ok, fail) => {
      HC.api('schedule_events', { params: { start: info.startStr, end: info.endStr } })
        .then((d) => ok(d.events)).catch((e) => { HC.toast(e.message, 'danger'); fail(e); });
    },
  });
  cal.render();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
