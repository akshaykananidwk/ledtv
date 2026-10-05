<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('broadcast.send');
Csrf::check();

const UI_COMMANDS = ['SHOW_CONTENT', 'RELOAD', 'CLEAR_CACHE', 'SCREEN_ON', 'SCREEN_OFF', 'REBOOT', 'PING'];

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    try {
        switch ($op) {
            case 'push':
                $when = in_array($_POST['when'] ?? 'now', ['now', 'once', 'window'], true) ? $_POST['when'] : 'now';
                if ($when === 'now') {
                    [$cid, $pid] = parse_source($_POST['source'] ?? '');
                    [$type, $ids] = Broadcaster::parseTarget($_POST);
                    if ($type !== 'all' && !$ids) {
                        throw new InvalidArgumentException(__('Select at least one target.'));
                    }
                    $bid = Broadcaster::pushNow($type, $ids, $cid, $pid, req_str('title', $_POST, 190), Auth::id());
                    $n = (int) DB::value('SELECT COUNT(*) FROM device_commands WHERE broadcast_id = :b', ['b' => $bid]);
                    ActivityLog::add('broadcast_push', 'broadcast', $bid, source_label($cid, $pid) . ' → ' . Broadcaster::describeTarget($type, $ids));
                    flash('success', __('Sent! :n TVs will switch within a few seconds.', ['n' => $n]));
                } else {
                    require_can('schedule.manage');
                    $in = $_POST;
                    $in['mode'] = $when;
                    [$data, $errors] = Broadcaster::validateSchedule($in);
                    if (!$errors) {
                        [$cid, $pid] = parse_source($_POST['source'] ?? '');
                        if (!$cid && !$pid) {
                            $errors[] = __('Select content or a playlist.');
                        }
                    }
                    if ($errors) {
                        flash_errors($errors);
                        redirect(admin_url('broadcast.php'));
                    }
                    $bid = Broadcaster::schedule($data, Auth::id());
                    ActivityLog::add('broadcast_schedule', 'broadcast', $bid, $data['title'] . ' (' . $when . ')');
                    flash('success', __('Scheduled. You can see it on the Schedule page.'));
                    Scheduler::tick(true);
                }
                break;

            case 'emergency':
                require_can('broadcast.emergency');
                [$type, $ids] = Broadcaster::parseTarget($_POST);
                if ($type !== 'all' && !$ids) {
                    throw new InvalidArgumentException(__('Select at least one target.'));
                }
                $title = req_str('title', $_POST, 190);
                $message = req_str('message', $_POST, 1000);
                if ($title === '' && $message === '') {
                    throw new InvalidArgumentException(__('Enter a title or message.'));
                }
                $bid = Broadcaster::emergencyStart($title, $message, $type, $ids, Auth::id(),
                    clean_color($_POST['bg_color'] ?? null, '#B00020'), clean_color($_POST['text_color'] ?? null, '#FFFFFF'));
                ActivityLog::add('emergency_start', 'broadcast', $bid, $title . ' → ' . Broadcaster::describeTarget($type, $ids));
                flash('warning', __('Emergency message is now showing on the TVs. Remember to stop it when done.'));
                break;

            case 'emergency_stop':
                require_can('broadcast.emergency');
                $id = req_int('id', $_POST);
                $n = Broadcaster::emergencyStop($id ?: null);
                ActivityLog::add('emergency_stop', 'broadcast', $id ?: null, "Stopped $n");
                flash('success', __('Emergency message stopped. TVs return to normal content.'));
                break;

            case 'command':
                $command = strtoupper(req_str('command', $_POST, 20));
                if (!in_array($command, UI_COMMANDS, true)) {
                    throw new InvalidArgumentException(__('Unknown command.'));
                }
                require_can($command === 'SHOW_CONTENT' ? 'broadcast.send' : 'broadcast.device_commands');
                [$type, $ids] = Broadcaster::parseTarget($_POST);
                if ($type !== 'all' && !$ids) {
                    throw new InvalidArgumentException(__('Select at least one target.'));
                }
                [$bid, $count] = Broadcaster::sendCommand($command, $type, $ids, [], Auth::id());
                ActivityLog::add('device_command', 'broadcast', $bid, $command . ' → ' . Broadcaster::describeTarget($type, $ids) . " ($count TVs)");
                flash('success', __(':cmd sent to :n TVs.', ['cmd' => command_label($command), 'n' => $count]));
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('broadcast.php') . ($op === 'emergency' || $op === 'emergency_stop' ? '#emergency' : ''));
}

$canSchedule = Auth::can('schedule.manage');
$canCmd = Auth::can('broadcast.device_commands');
$emergencies = Broadcaster::activeEmergencies();
$history = DB::all(
    'SELECT b.*, u.username FROM broadcast_commands b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 30'
);
$stats = [];
if ($history) {
    [$in, $p] = DB::in(array_map(fn ($b) => (int) $b['id'], $history), 'b');
    foreach (DB::all("SELECT broadcast_id, status, COUNT(*) AS n FROM device_commands WHERE broadcast_id IN $in GROUP BY broadcast_id, status", $p) as $r) {
        $stats[(int) $r['broadcast_id']][$r['status']] = (int) $r['n'];
    }
}
$hasContent = (bool) DB::value('SELECT 1 FROM content_items LIMIT 1') || (bool) DB::value('SELECT 1 FROM content_playlists LIMIT 1');

$pageTitle = __('Broadcast');
$activeNav = 'broadcast';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Broadcast')) ?></h1>
    <p class="lead-sm"><?= e(__('Send content to TVs now or later, show emergency messages, and control TVs remotely.')) ?></p>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-send"></i> <?= e(__('Send content to TVs')) ?></div>
      <div class="card-body">
        <?php if (!$hasContent): ?>
          <div class="hc-empty py-3"><i class="bi bi-images"></i><p><?= e(__('Add some content first, then come back here to send it to your TVs.')) ?></p>
            <?php if (Auth::can('content.manage')): ?><a class="btn btn-primary" href="<?= e(admin_url('content.php')) ?>"><?= e(__('Go to Content Library')) ?></a><?php endif; ?></div>
        <?php else: ?>
        <form method="post" id="pushForm">
          <?= Csrf::field() ?><input type="hidden" name="op" value="push">
          <div class="mb-3">
            <label class="form-label" for="psource">1. <?= e(__('What to show')) ?></label>
            <?= source_select('source', '', __('— Choose content or playlist —'), ['id' => 'psource', 'required' => 'required']) ?>
          </div>
          <div class="mb-3">
            <label class="form-label">2. <?= e(__('On which TVs')) ?></label>
            <?= target_picker('push') ?>
          </div>
          <div class="mb-3">
            <label class="form-label">3. <?= e(__('When')) ?></label>
            <div class="btn-group flex-wrap w-100" role="group">
              <input type="radio" class="btn-check" name="when" value="now" id="w_now" checked autocomplete="off">
              <label class="btn btn-outline-success" for="w_now"><i class="bi bi-lightning-charge"></i> <?= e(__('Right now')) ?></label>
              <?php if ($canSchedule): ?>
              <input type="radio" class="btn-check" name="when" value="once" id="w_once" autocomplete="off">
              <label class="btn btn-outline-primary" for="w_once"><i class="bi bi-alarm"></i> <?= e(__('At a set time')) ?></label>
              <input type="radio" class="btn-check" name="when" value="window" id="w_window" autocomplete="off">
              <label class="btn btn-outline-primary" for="w_window"><i class="bi bi-calendar-range"></i> <?= e(__('During a time window')) ?></label>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($canSchedule): ?>
          <div class="border rounded p-3 mb-3 bg-light" data-when="once window" hidden>
            <div class="row g-2">
              <div class="col-sm-6">
                <label class="form-label" for="start_at"><span data-when-label="once"><?= e(__('Start at')) ?></span><span data-when-label="window"><?= e(__('From (optional)')) ?></span></label>
                <input type="datetime-local" class="form-control" id="start_at" name="start_at">
              </div>
              <div class="col-sm-6" data-when="window">
                <label class="form-label" for="end_at"><?= e(__('Until (optional)')) ?></label>
                <input type="datetime-local" class="form-control" id="end_at" name="end_at">
              </div>
              <div class="col-6 col-sm-3" data-when="window">
                <label class="form-label" for="daily_start"><?= e(__('Daily from')) ?></label>
                <input type="time" class="form-control" id="daily_start" name="daily_start">
              </div>
              <div class="col-6 col-sm-3" data-when="window">
                <label class="form-label" for="daily_end"><?= e(__('Daily until')) ?></label>
                <input type="time" class="form-control" id="daily_end" name="daily_end">
              </div>
              <div class="col-12" data-when="window">
                <label class="form-label d-block"><?= e(__('Repeat on')) ?></label>
                <?php foreach (day_names() as $n => $dn): ?>
                  <input type="checkbox" class="btn-check" name="repeat_days[]" value="<?= $n ?>" id="rd<?= $n ?>" autocomplete="off">
                  <label class="btn btn-sm btn-outline-secondary mb-1" for="rd<?= $n ?>"><?= e($dn) ?></label>
                <?php endforeach; ?>
                <div class="form-text"><?= e(__('Example: Daily from 06:00 until 07:00 on Mon–Sun shows the Aarti stream every morning; afterwards TVs return to their normal content.')) ?></div>
              </div>
              <div class="col-12">
                <label class="form-label" for="ptitle"><?= e(__('Name (optional)')) ?></label>
                <input class="form-control" id="ptitle" name="title" maxlength="190" placeholder="<?= e(__('e.g. Morning Aarti')) ?>">
              </div>
            </div>
          </div>
          <?php endif; ?>
          <button class="btn btn-primary btn-lg" data-confirm="<?= e(__('Send this content to the selected TVs?')) ?>" data-confirm-safe="1"><i class="bi bi-send-fill"></i> <?= e(__('Send')) ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::can('broadcast.send')): ?>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-tv"></i> <?= e(__('TV remote control')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="command">
          <div class="mb-3">
            <label class="form-label" for="ccommand"><?= e(__('Command')) ?></label>
            <select class="form-select" name="command" id="ccommand">
              <option value="SHOW_CONTENT"><?= e(__('Refresh content (safe)')) ?></option>
              <?php if ($canCmd): ?>
                <option value="SCREEN_ON"><?= e(__('Turn TV screen on')) ?></option>
                <option value="SCREEN_OFF"><?= e(__('Turn TV screen off')) ?></option>
                <option value="RELOAD"><?= e(__('Restart app')) ?></option>
                <option value="CLEAR_CACHE"><?= e(__('Clear cache')) ?></option>
                <option value="REBOOT"><?= e(__('Reboot TV')) ?></option>
                <option value="PING"><?= e(__('Ping (test)')) ?></option>
              <?php endif; ?>
            </select>
            <?php if (!$canCmd): ?><div class="form-text"><?= e(__('Other commands (reboot, screen off…) need a Manager account.')) ?></div><?php endif; ?>
          </div>
          <div class="mb-3"><?= target_picker('cmd') ?></div>
          <button class="btn btn-outline-primary" data-confirm="<?= e(__('Send this command to the selected TVs?')) ?>"><i class="bi bi-lightning-charge"></i> <?= e(__('Send command')) ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-5">
    <?php if (Auth::can('broadcast.emergency')): ?>
    <div class="card mb-3 border border-danger" id="emergency">
      <div class="card-header bg-danger text-white"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(__('Emergency message')) ?></div>
      <div class="card-body">
        <?php if ($emergencies): ?>
          <div class="mb-3">
            <?php foreach ($emergencies as $em): ?>
              <div class="alert alert-danger d-flex align-items-center gap-2 mb-2">
                <div class="flex-grow-1">
                  <strong><?= e($em['title']) ?></strong>
                  <div class="small"><?= e(Broadcaster::describeTarget($em['target_type'], $em['target_ids'])) ?> · <?= e(time_ago($em['start_at'])) ?></div>
                </div>
                <form method="post">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="emergency_stop"><input type="hidden" name="id" value="<?= (int) $em['id'] ?>">
                  <button class="btn btn-danger btn-sm"><i class="bi bi-stop-circle"></i> <?= e(__('Stop')) ?></button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="emergency">
          <div class="mb-2">
            <label class="form-label" for="etitle"><?= e(__('Title')) ?></label>
            <input class="form-control" id="etitle" name="title" maxlength="190" value="<?= e(__('Attention')) ?>">
          </div>
          <div class="mb-2">
            <label class="form-label" for="emsg"><?= e(__('Message')) ?></label>
            <textarea class="form-control" id="emsg" name="message" rows="3" maxlength="1000" placeholder="<?= e(__('e.g. Please evacuate the building using the nearest staircase.')) ?>"></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label" for="ebg"><?= e(__('Background colour')) ?></label><input type="color" class="form-control form-control-color w-100" id="ebg" name="bg_color" value="#B00020"></div>
            <div class="col-6"><label class="form-label" for="efg"><?= e(__('Text colour')) ?></label><input type="color" class="form-control form-control-color w-100" id="efg" name="text_color" value="#FFFFFF"></div>
          </div>
          <label class="form-label"><?= e(__('Show on')) ?></label>
          <div class="mb-3"><?= target_picker('em') ?></div>
          <button class="btn btn-danger w-100" data-confirm="<?= e(__('Show this emergency message full screen on the selected TVs now?')) ?>"><i class="bi bi-megaphone-fill"></i> <?= e(__('Show emergency message now')) ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-1">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-clock-history"></i> <?= e(__('Recent broadcasts')) ?></span>
    <?php if (Auth::can('logs.view')): ?><a class="ms-auto small" href="<?= e(admin_url('logs.php', ['tab' => 'broadcasts'])) ?>"><?= e(__('Full history')) ?></a><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hc table-sm">
      <thead><tr><th><?= e(__('When')) ?></th><th><?= e(__('What')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Target')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Delivery')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('By')) ?></th></tr></thead>
      <tbody>
      <?php if (!$history): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('Nothing sent yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($history as $b): $s = $stats[(int) $b['id']] ?? []; $tot = array_sum($s); ?>
        <tr>
          <td class="small text-nowrap"><?= e(date('d M H:i', (int) strtotime($b['created_at']))) ?></td>
          <td>
            <?php if ((int) $b['is_emergency']): ?><span class="badge text-bg-danger"><?= e(__('Emergency')) ?></span><?php endif; ?>
            <?= e(in_array($b['command'], ['SHOW_CONTENT', 'EMERGENCY'], true) && $b['title'] !== $b['command'] ? $b['title'] : command_label($b['command'])) ?>
            <?php if ($b['mode'] !== 'now'): ?><div class="small text-muted"><?= e(schedule_summary($b)) ?></div><?php endif; ?>
          </td>
          <td class="d-none d-md-table-cell small"><?= e(Broadcaster::describeTarget($b['target_type'], $b['target_ids'])) ?></td>
          <td><?= broadcast_status_badge($b['status']) ?></td>
          <td class="small text-nowrap">
            <?php if ($tot): ?>
              <span class="text-success" title="<?= e(__('Done')) ?>"><i class="bi bi-check2-all"></i> <?= (int) ($s['acked'] ?? 0) ?></span>
              <span class="text-info ms-1" title="<?= e(__('Delivered')) ?>"><i class="bi bi-check2"></i> <?= (int) ($s['delivered'] ?? 0) ?></span>
              <span class="text-warning ms-1" title="<?= e(__('Waiting')) ?>"><i class="bi bi-hourglass-split"></i> <?= (int) ($s['pending'] ?? 0) ?></span>
              <?php if (!empty($s['failed'])): ?><span class="text-danger ms-1" title="<?= e(__('Failed')) ?>"><i class="bi bi-x-circle"></i> <?= (int) $s['failed'] ?></span><?php endif; ?>
              <?php if (!empty($s['expired'])): ?><span class="text-muted ms-1" title="<?= e(__('Expired')) ?>"><i class="bi bi-clock"></i> <?= (int) $s['expired'] ?></span><?php endif; ?>
            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
          </td>
          <td class="d-none d-lg-table-cell small"><?= e($b['username'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('pushForm');
  if (!form) return;
  const sync = () => {
    const w = (form.querySelector('input[name=when]:checked') || {}).value || 'now';
    form.querySelectorAll('[data-when]').forEach((el) => { el.hidden = !el.dataset.when.split(' ').includes(w); });
    form.querySelectorAll('[data-when-label]').forEach((el) => { el.hidden = el.dataset.whenLabel !== w; });
    const st = form.querySelector('#start_at');
    if (st) st.required = w === 'once';
  };
  form.querySelectorAll('input[name=when]').forEach((r) => r.addEventListener('change', sync));
  sync();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
