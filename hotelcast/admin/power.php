<?php
/**
 * TV Power: switch TVs to standby / wake them now, and daily automatic off/on schedules.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('schedule.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    try {
        switch ($op) {
            case 'now':
                $command = ($_POST['state'] ?? '') === 'on' ? 'SCREEN_ON' : 'SCREEN_OFF';
                [$type, $ids] = Broadcaster::parseTarget($_POST);
                if ($type !== 'all' && !$ids) {
                    throw new InvalidArgumentException(__('Select at least one target.'));
                }
                [$bid, $count] = Broadcaster::sendCommand($command, $type, $ids, [], Auth::id());
                ActivityLog::add('power_now', 'broadcast', $bid, $command . ' → ' . Broadcaster::describeTarget($type, $ids) . " ($count TVs)");
                flash('success', $command === 'SCREEN_ON'
                    ? __('Turning on :n TVs (within one poll interval).', ['n' => $count])
                    : __('Turning off :n TVs (within one poll interval).', ['n' => $count]));
                break;

            case 'mode':
                Access::requireUnrestricted('power off mode'); // hotel-wide setting
                $mode = ($_POST['power_off_mode'] ?? '') === 'black' ? 'black' : 'standby';
                Settings::set('power_off_mode', $mode);
                Settings::bumpContentVersion();
                Broadcaster::queueForRooms(Broadcaster::targetRooms('all', []), 'SHOW_CONTENT');
                ActivityLog::add('power_off_mode', 'settings', null, $mode);
                flash('success', __('Saved.'));
                break;

            case 'add':
                [$id, $errors] = Broadcaster::schedulePower($_POST, Auth::id());
                if ($errors) {
                    flash_errors($errors);
                } else {
                    $b = Tenant::find('broadcast_commands', (int) $id);
                    ActivityLog::add('power_schedule_add', 'broadcast', $id, $b['title'] . ' · ' . Broadcaster::describeTarget($b['target_type'], $b['target_ids']));
                    flash('success', __('Power schedule saved.'));
                }
                break;

            case 'toggle':
                $id = req_int('id', $_POST);
                $enable = ($_POST['enable'] ?? '') === '1';
                Broadcaster::setPowerScheduleEnabled($id, $enable);
                ActivityLog::add($enable ? 'power_schedule_resume' : 'power_schedule_pause', 'broadcast', $id);
                flash('success', $enable ? __('Schedule resumed.') : __('Schedule paused.'));
                break;

            case 'delete':
                $id = req_int('id', $_POST);
                $b = Tenant::find('broadcast_commands', $id, "command = 'SCREEN_OFF' AND mode = 'window'");
                if ($b) {
                    Access::requireBroadcast($b);
                    DB::delete('broadcast_commands', 'id = :id', ['id' => $id]);
                    Settings::bumpContentVersion();
                    Broadcaster::queueForRooms(Broadcaster::targetRooms($b['target_type'], json_decode((string) $b['target_ids'], true) ?: []), 'SHOW_CONTENT');
                    ActivityLog::add('power_schedule_delete', 'broadcast', $id, (string) $b['title']);
                    flash('success', __('Schedule deleted.'));
                }
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('power.php'));
}

Scheduler::tick();
$schedules = DB::all(
    "SELECT b.*, u.username FROM broadcast_commands b LEFT JOIN users u ON u.id = b.created_by
     WHERE b.hotel_id = :hid AND b.command = 'SCREEN_OFF' AND b.mode = 'window' ORDER BY b.status = 'cancelled', b.id DESC",
    hid()
);
$limited = Access::restricted();
$schedules = array_values(array_filter($schedules, [Access::class, 'canBroadcast'])); // limited users: only their TVs
[$accR, $apR] = Access::roomSql('id');
$roomsOff = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :hid AND is_enabled = 0' . $accR, hid() + $apR);
$activeNow = array_values(array_filter($schedules, fn ($b) => $b['status'] !== 'cancelled' && ContentResolver::windowActive($b)));
$days = day_names();
$powerLog = DB::all(
    "SELECT dc.command, dc.status, dc.message, dc.created_at, dc.acked_at, r.room_number
     FROM device_commands dc JOIN devices d ON d.id = dc.device_id LEFT JOIN rooms r ON r.id = d.room_id
     WHERE d.hotel_id = :hid AND dc.command IN ('SCREEN_ON','SCREEN_OFF')" . Access::roomSql('d.room_id')[0] . " ORDER BY dc.id DESC LIMIT 15",
    hid() + Access::roomSql('d.room_id')[1]
);

$pageTitle = __('TV Power');
$activeNav = 'power';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-power"></i> <?= e(__('TV Power')) ?></h1>
    <p class="lead-sm"><?= e(__('Switch TVs off (standby) and on from here, or let them switch off and on automatically every day.')) ?></p>
  </div>
</div>
<?= flash_show() ?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-muted small"><?= e(__('Screens switched off by admin')) ?></div>
    <div class="fs-3 fw-bold"><?= $roomsOff ?></div>
  </div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-muted small"><?= e(__('Off schedules active right now')) ?></div>
    <div class="fs-3 fw-bold"><?= count($activeNow) ?></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100 border-info"><div class="card-body small">
    <i class="bi bi-info-circle text-info"></i>
    <?= e(__('Real standby needs the TV app set as device owner (one-time adb command, see the TV setup guide). Without it the TV only shows a black screen. Anyone can always switch the TV on with the remote. Emergency messages wake TVs automatically.')) ?>
  </div></div></div>
</div>

<div class="row g-3">
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-lightning-charge"></i> <?= e(__('Turn TVs off / on now')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="now">
          <div class="mb-3"><?= target_picker('pnow') ?></div>
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-success" name="state" value="on"><i class="bi bi-power"></i> <?= e(__('Turn ON')) ?></button>
            <button class="btn btn-dark" name="state" value="off" data-confirm="<?= e(__('Turn the selected TVs off? They stay off until you turn them on again.')) ?>"><i class="bi bi-power"></i> <?= e(__('Turn OFF')) ?></button>
          </div>
          <div class="form-text"><?= e(__('Turning off here keeps the screen off (even after a TV restart) until you turn it on again.')) ?></div>
        </form>
      </div>
    </div>

    <?php if (!$limited): ?>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-gear"></i> <?= e(__('How should "OFF" work?')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="mode">
          <?php $pm = Settings::get('power_off_mode', 'standby'); ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="power_off_mode" value="standby" id="pm_s" <?= $pm !== 'black' ? 'checked' : '' ?>>
            <label class="form-check-label" for="pm_s"><strong><?= e(__('Real standby (saves electricity)')) ?></strong><br>
              <span class="small text-muted"><?= e(__('TV really switches off. Switching on from here works only if the TV keeps Wi-Fi on in standby (Energy mode: Increased / Always connected). Otherwise use the TV\'s own power-on timer.')) ?></span></label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="power_off_mode" value="black" id="pm_b" <?= $pm === 'black' ? 'checked' : '' ?>>
            <label class="form-check-label" for="pm_b"><strong><?= e(__('Black screen (always controllable)')) ?></strong><br>
              <span class="small text-muted"><?= e(__('TV only shows a black screen and stays connected, so "Turn ON" always works instantly. Uses more electricity.')) ?></span></label>
          </div>
          <button class="btn btn-outline-primary btn-sm"><i class="bi bi-save"></i> <?= e(__('Save')) ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-alarm"></i> <?= e(__('New daily schedule')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?><input type="hidden" name="op" value="add">
          <div class="mb-3">
            <label class="form-label" for="ptitle"><?= e(__('Name (optional)')) ?></label>
            <input class="form-control" name="title" id="ptitle" maxlength="190" placeholder="<?= e(__('e.g. Night off')) ?>">
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="poff"><?= e(__('TV OFF at')) ?></label>
              <input type="time" class="form-control" name="off_time" id="poff" value="23:00" required>
            </div>
            <div class="col-6">
              <label class="form-label" for="pon"><?= e(__('TV ON at')) ?></label>
              <input type="time" class="form-control" name="on_time" id="pon" value="06:00" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label d-block"><?= e(__('Days')) ?></label>
            <?php foreach ($days as $n => $label): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="days[]" value="<?= $n ?>" id="pd<?= $n ?>" checked>
                <label class="form-check-label" for="pd<?= $n ?>"><?= e($label) ?></label>
              </div>
            <?php endforeach; ?>
            <div class="form-text"><?= e(__('Overnight works: OFF 23:00, ON 06:00 switches off at night and on next morning.')) ?></div>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= e(__('On which TVs')) ?></label>
            <?= target_picker('psched') ?>
          </div>
          <button class="btn btn-primary"><i class="bi bi-save"></i> <?= e(__('Save schedule')) ?></button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-list-check"></i> <?= e(__('Daily power schedules')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr>
            <th><?= e(__('Name')) ?></th><th><?= e(__('Off → On')) ?></th><th><?= e(__('Days')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"></th>
          </tr></thead>
          <tbody>
          <?php if (!$schedules): ?>
            <tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No power schedules yet. Create one on the left, e.g. OFF 23:00 – ON 06:00 every day.')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($schedules as $b):
              $paused = $b['status'] === 'cancelled';
              $isOffNow = !$paused && ContentResolver::windowActive($b);
              $dayList = array_map(fn ($d) => $days[(int) $d] ?? $d, explode(',', (string) $b['repeat_days']));
          ?>
            <tr class="<?= $paused ? 'text-muted' : '' ?>">
              <td><strong><?= e($b['title']) ?></strong><div class="small text-muted"><?= e($b['username'] ?? '') ?></div></td>
              <td class="text-nowrap"><?= e(date('h:i A', (int) strtotime((string) $b['daily_start']))) ?> → <?= e(date('h:i A', (int) strtotime((string) $b['daily_end']))) ?></td>
              <td class="small"><?= e(count($dayList) === 7 ? __('every day') : implode(', ', $dayList)) ?></td>
              <td class="small"><?= e(Broadcaster::describeTarget($b['target_type'], $b['target_ids'])) ?></td>
              <td>
                <?php if ($paused): ?><span class="badge text-bg-secondary"><?= e(__('Paused')) ?></span>
                <?php elseif ($isOffNow): ?><span class="badge text-bg-dark"><i class="bi bi-moon"></i> <?= e(__('TVs off now')) ?></span>
                <?php else: ?><span class="badge text-bg-success"><?= e(__('Waiting')) ?></span><?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <input type="hidden" name="enable" value="<?= $paused ? '1' : '0' ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= e($paused ? __('Resume') : __('Pause')) ?>"><i class="bi <?= $paused ? 'bi-play' : 'bi-pause' ?>"></i></button>
                </form>
                <form method="post" class="d-inline">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('Delete this schedule?')) ?>"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="card mt-3">
  <div class="card-header"><i class="bi bi-clipboard-check"></i> <?= e(__('Last ON/OFF results from TVs')) ?></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th><?= e(__('Time')) ?></th><th><?= e(__('Screen')) ?></th><th><?= e(__('Command')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('TV reply')) ?></th></tr></thead>
      <tbody>
      <?php if (!$powerLog): ?><tr><td colspan="5" class="text-center text-muted py-3"><?= e(__('No commands yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($powerLog as $l): ?>
        <tr class="<?= str_contains((string) $l['message'], 'FAILED') ? 'table-danger' : '' ?>">
          <td class="small text-nowrap"><?= e(date('d M H:i:s', (int) strtotime((string) $l['created_at']))) ?></td>
          <td><?= e($l['room_number'] ?? '-') ?></td>
          <td><?= e($l['command'] === 'SCREEN_ON' ? __('Turn ON') : __('Turn OFF')) ?></td>
          <td><?= cmd_status_badge($l['status']) ?></td>
          <td class="small"><?= e($l['message'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
