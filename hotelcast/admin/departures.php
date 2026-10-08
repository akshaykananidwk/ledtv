<?php
declare(strict_types=1);
/**
 * Departures / arrivals board (#7): timetable entries of the "Departures board" display app
 * (core/Apps/DeparturesApp.php) with one-tap status buttons for the counter: On time, Delayed +15,
 * Boarding, Departed / Arrived, Cancelled. Entries run on one date or every day (daily rows start
 * every day "on time"). Phone friendly: big buttons, cards. TVs update within 15 seconds.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('departures.manage');
Csrf::check();

$formRow = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? Departures::find($id) : null; // another hotel's id → 404
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        flash('warning', __('Entry not found.'));
        redirect(admin_url('departures.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Departures::validate($_POST);
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'daily' => !empty($_POST['daily'])] + $data + Departures::DEFAULTS;
                break;
            }
            $newId = Departures::save($existing ? $id : null, $data);
            ActivityLog::add($existing ? 'departure_update' : 'departure_create', 'departure', $newId, mb_substr(trim($data['number'] . ' ' . $data['destination']), 0, 120));
            flash('success', __('Entry ":t" saved.', ['t' => trim($data['number'] . ' ' . $data['destination'])]));
            redirect(admin_url('departures.php'));

        case 'status':
            // One-tap status for the occurrence on `date` (today or tomorrow only).
            $status = req_str('status', $_POST, 20);
            $date = req_str('date', $_POST, 10);
            $allowed = [date('Y-m-d'), date('Y-m-d', Departures::midnight(time(), 1))];
            if (!in_array($date, $allowed, true)) {
                $date = $allowed[0];
            }
            if (!isset(Departures::STATUSES[$status]) || (!empty($existing['service_date']) && $existing['service_date'] !== $date)) {
                flash('warning', __('Unknown action.'));
                redirect(admin_url('departures.php'));
            }
            [$st, $delay] = Departures::quick($existing, $date, $status, max(0, min(240, req_int('add', $_POST))));
            $label = trim($existing['number'] . ' ' . $existing['destination']);
            ActivityLog::add('departure_status', 'departure', $id, mb_substr($label . ': ' . $st . ($delay ? ' +' . $delay : ''), 0, 120));
            flash('success', $label . ': ' . ($st === 'delayed' ? __('Delayed :n min', ['n' => $delay]) : Departures::statusLabel($st)));
            redirect(admin_url('departures.php') . '#d' . $id);

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('departures', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('departure_toggle', 'departure', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['destination'], 0, 100));
            flash('success', $on ? __('Entry ":t" is shown again.', ['t' => $existing['destination']]) : __('Entry ":t" is hidden.', ['t' => $existing['destination']]));
            redirect(admin_url('departures.php'));

        case 'delete':
            Departures::delete($id);
            ActivityLog::add('departure_delete', 'departure', $id, mb_substr((string) $existing['destination'], 0, 120));
            flash('success', __('Entry ":t" deleted.', ['t' => $existing['destination']]));
            redirect(admin_url('departures.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('departures.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'departures';
$pageTitle = __('Departures board');
$statusCls = ['on_time' => 'text-bg-success', 'delayed' => 'text-bg-warning', 'boarding' => 'text-bg-primary', 'departed' => 'text-bg-secondary', 'cancelled' => 'text-bg-danger', 'arrived' => 'text-bg-secondary'];

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = Departures::DEFAULTS + ['daily' => true];
        if ($action === 'edit') {
            $formRow = Departures::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Entry not found.'));
                redirect(admin_url('departures.php'));
            }
            $formRow['daily'] = empty($formRow['service_date']);
        }
    }
    $r = $formRow;
    $isEdit = (int) $r['id'] > 0;
    $pageTitle = $isEdit ? __('Edit entry') : __('New entry');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-signpost-split"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('A bus, train or flight on the board. TVs update within 15 seconds.')) ?></p></div>
      <a href="<?= e(admin_url('departures.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="<?= e(admin_url('departures.php')) ?>" id="depForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <div class="btn-group w-100" role="group">
              <?php foreach (Departures::KINDS as $k => $l): ?>
                <input type="radio" class="btn-check" name="kind" id="d_k_<?= e($k) ?>" value="<?= e($k) ?>"<?= $r['kind'] === $k ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary btn-lg" for="d_k_<?= e($k) ?>"><?= e(__($l)) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label" for="d_time"><?= e(__('Time')) ?> *</label>
            <input class="form-control form-control-lg" type="time" id="d_time" name="sched_time" value="<?= e(substr((string) $r['sched_time'], 0, 5)) ?>" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label" for="d_no"><?= e(__('Number / route')) ?></label>
            <input class="form-control form-control-lg" id="d_no" name="number" value="<?= e($r['number']) ?>" maxlength="40" placeholder="GJ 101">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label" for="d_plat"><?= e(__('Platform / gate')) ?></label>
            <input class="form-control form-control-lg" id="d_plat" name="platform" value="<?= e($r['platform']) ?>" maxlength="20">
          </div>
          <div class="col-12">
            <label class="form-label" for="d_dest"><?= e(__('Destination (English)')) ?> *</label>
            <input class="form-control form-control-lg" id="d_dest" name="destination" value="<?= e($r['destination']) ?>" required maxlength="120" placeholder="Ahmedabad">
            <div class="form-text"><?= e(__('For arrivals: where it comes from.')) ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="d_dest_gu"><?= e(__('Destination in Gujarati')) ?></label>
            <input class="form-control" id="d_dest_gu" name="destination_gu" value="<?= e($r['destination_gu']) ?>" maxlength="120" placeholder="અમદાવાદ" lang="gu">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="d_dest_hi"><?= e(__('Destination in Hindi')) ?></label>
            <input class="form-control" id="d_dest_hi" name="destination_hi" value="<?= e($r['destination_hi']) ?>" maxlength="120" placeholder="अहमदाबाद" lang="hi">
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="daily" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="d_daily" name="daily" value="1"<?= !empty($r['daily']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="d_daily"><?= e(__('Runs every day')) ?></label>
          </div></div>
          <div class="col-12">
            <label class="form-label" for="d_date"><?= e(__('Date (when not daily)')) ?></label>
            <input class="form-control" type="date" id="d_date" name="service_date" value="<?= e((string) $r['service_date']) ?>">
          </div>
          <div class="col-7">
            <label class="form-label" for="d_status"><?= e(__('Status')) ?></label>
            <select class="form-select" id="d_status" name="status">
              <?php foreach (Departures::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e(__($l)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label" for="d_delay"><?= e(__('Delay (min)')) ?></label>
            <input class="form-control" type="number" id="d_delay" name="delay_min" min="0" max="1440" value="<?= (int) $r['delay_min'] ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="d_remark"><?= e(__('Remark')) ?></label>
            <input class="form-control" id="d_remark" name="remark" value="<?= e($r['remark']) ?>" maxlength="190" placeholder="<?= e(__('e.g. Via Nadiad, AC sleeper')) ?>">
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="d_active" name="is_active" value="1"<?= (int) $r['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="d_active"><?= e(__('Show on TVs')) ?></label>
          </div></div>
        </div></div>
        <div class="d-grid d-sm-flex gap-2 mt-3">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('departures.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div></div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- board with quick buttons + all entries
$now = time();
$board = Departures::board(Departures::candidates($now), $now, ['kind' => 'both', 'hide_after_min' => 60, 'lookahead_hours' => 24, 'max' => 60]);
$all = Departures::all();
$screens = BusinessApps::screens('departures');
$quick = static function (array $b, string $status, string $label, string $cls, int $add = 0): string {
    return '<form method="post" class="d-inline">' . Csrf::field() . '<input type="hidden" name="op" value="status"><input type="hidden" name="id" value="' . (int) $b['id'] . '">'
        . '<input type="hidden" name="date" value="' . e($b['date']) . '"><input type="hidden" name="status" value="' . e($status) . '">'
        . ($add ? '<input type="hidden" name="add" value="' . $add . '">' : '')
        . '<button class="btn ' . e($cls) . ' px-3 py-2">' . e($label) . '</button></form>';
};
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-signpost-split"></i> <?= e(__('Departures board')) ?></h1>
    <p class="lead-sm"><?= e(__('Tap a button to change the status on every board at once.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'departures'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Board screens (:n)', ['n' => $screens]) : __('Create a board screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('departures.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New entry')) ?></a>
  </div>
</div>

<h2 class="h5 mt-2"><?= e(__('On the board now')) ?></h2>
<?php if (!$board): ?>
  <div class="card mb-4"><div class="hc-empty"><i class="bi bi-signpost-split"></i><p class="text-muted"><?= e($all ? __('Nothing in the next 24 hours.') : __('No entries yet. Add the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-2 mb-4">
  <?php foreach ($board as $b): $st = (string) $b['status_now']; ?>
    <div class="col-12 col-lg-6" id="d<?= (int) $b['id'] ?>" data-board="<?= (int) $b['id'] ?>">
      <div class="card h-100"><div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="min-w-0">
            <div class="fs-4 fw-bold font-monospace"><?= e(BusinessApps::timeLabel((string) $b['sched_time'], true)) ?>
              <?php if ($b['tomorrow']): ?><span class="badge text-bg-light border fs-6"><?= e(__('Tomorrow')) ?></span><?php endif; ?>
              <span class="badge text-bg-light border fs-6"><?= e(__(Departures::KINDS[$b['kind']] ?? 'Departure')) ?></span></div>
            <div class="text-break"><strong><?= e($b['number']) ?></strong> <?= e($b['destination']) ?><?= $b['platform'] !== '' ? ' · ' . e(__('Platform')) . ' ' . e($b['platform']) : '' ?></div>
          </div>
          <span class="badge <?= e($statusCls[$st] ?? 'text-bg-secondary') ?> fs-6 text-wrap"><?= e($st === 'delayed' ? __('Delayed :n min', ['n' => $b['delay_now']]) : Departures::statusLabel($st)) ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?= $quick($b, 'on_time', __('On time'), 'btn-outline-success') ?>
          <?= $quick($b, 'delayed', __('Delayed +15'), 'btn-warning', 15) ?>
          <?php if ($b['kind'] === 'departure'): ?>
            <?= $quick($b, 'boarding', __('Boarding'), 'btn-primary') ?>
            <?= $quick($b, 'departed', __('Departed'), 'btn-secondary') ?>
          <?php else: ?>
            <?= $quick($b, 'arrived', __('Arrived'), 'btn-secondary') ?>
          <?php endif; ?>
          <?= $quick($b, 'cancelled', __('Cancelled'), 'btn-outline-danger') ?>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($all): ?>
  <h2 class="h5"><?= e(__('All entries')) ?></h2>
  <div class="card"><div class="list-group list-group-flush">
  <?php foreach ($all as $r): ?>
    <div class="list-group-item d-flex flex-wrap gap-2 align-items-center justify-content-between" data-dep="<?= (int) $r['id'] ?>">
      <div class="min-w-0">
        <span class="font-monospace fw-bold"><?= e(substr((string) $r['sched_time'], 0, 5)) ?></span>
        <strong class="ms-1"><?= e($r['number']) ?></strong> <?= e($r['destination']) ?>
        <span class="badge text-bg-light border"><?= e(__(Departures::KINDS[$r['kind']] ?? 'Departure')) ?></span>
        <span class="small text-muted"><?= e($r['service_date'] ? (string) $r['service_date'] : __('Every day')) ?></span>
        <?php if (!(int) $r['is_active']): ?><span class="badge rounded-pill text-bg-light border"><i class="bi bi-pause-circle"></i> <?= e(__('Off')) ?></span><?php endif; ?>
      </div>
      <div class="text-nowrap">
        <a class="btn btn-sm btn-primary" href="<?= e(admin_url('departures.php', ['action' => 'edit', 'id' => $r['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
        <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-sm btn-light border" title="<?= e((int) $r['is_active'] ? __('Hide') : __('Show')) ?>"><i class="bi <?= (int) $r['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
        <form method="post" class="d-inline" data-confirm="<?= e(__('Delete entry ":t"?', ['t' => trim($r['number'] . ' ' . $r['destination'])])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
      </div>
    </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
