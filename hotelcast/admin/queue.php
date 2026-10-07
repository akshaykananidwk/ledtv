<?php
declare(strict_types=1);
/**
 * Token queue — staff calling page (permission queue.operate, reception+), mobile first:
 * pick my counter → NEXT / RECALL / ARRIVED / DONE / SKIP / NO-SHOW, call a number, transfer to
 * another service; waiting count refreshes every 5 s. Buttons post with AJAX (CSRF header) and fall
 * back to normal form posts. Tab "setup" (queue.manage, manager+): services, counters, daily reset,
 * self-service links. Logic in core/Queue.php; TV: display app "queue_display".
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('queue.operate');
Csrf::check();
$ajax = Auth::isAjax();
$canManage = Auth::can('queue.manage');

/** JSON state of a counter for the calling page. */
$state = static function (array $counter, string $message = '', string $level = 'success'): array {
    $cur = Queue::current($counter);
    $next = DB::all(
        "SELECT * FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND status = 'waiting'" . ($counter['service_id'] ? ' AND service_id = :s' : '') . ' ORDER BY queued_at, id LIMIT 8',
        ['h' => Tenant::id(), 'd' => Queue::today()] + ($counter['service_id'] ? ['s' => (int) $counter['service_id']] : [])
    );
    return [
        'counter' => ['id' => (int) $counter['id'], 'name' => (string) $counter['name']],
        'current' => Queue::tokenJson($cur),
        'waiting' => Queue::waitingFor($counter),
        'next' => array_map(static fn ($t) => Queue::label($t), $next),
        'message' => $message,
        'level' => $level,
    ];
};

$back = static function (array $q = []): never {
    redirect(admin_url('queue.php', $q));
};

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    // ---------------------------------------------------------------- calling (queue.operate)
    if (in_array($op, ['next', 'recall', 'serving', 'done', 'skip', 'no_show', 'call', 'transfer'], true)) {
        $counter = Queue::findCounter(req_int('counter', $_POST)); // another hotel's id → 404
        if (!$counter || !(int) $counter['is_active']) {
            $ajax ? ajax_error(__('Choose your counter.'), 404, 'NOT_FOUND') : $back();
        }
        $msg = '';
        $level = 'success';
        $t = null;
        switch ($op) {
            case 'next':
                $t = Queue::next($counter);
                $msg = $t ? __('Calling :t', ['t' => Queue::label($t)]) : __('Nobody is waiting.');
                $level = $t ? 'success' : 'warning';
                break;
            case 'recall':
                $t = Queue::recall($counter);
                $msg = $t ? __('Calling :t again', ['t' => Queue::label($t)]) : __('No token at this counter.');
                $level = $t ? 'success' : 'warning';
                break;
            case 'serving':
                $t = Queue::serving($counter);
                $msg = $t ? __(':t is being served.', ['t' => Queue::label($t)]) : __('No token at this counter.');
                $level = $t ? 'success' : 'warning';
                break;
            case 'done':
            case 'skip':
            case 'no_show':
                $t = Queue::finish($counter, $op === 'skip' ? 'skipped' : $op);
                $msg = $t ? __(':t: :s', ['t' => Queue::label($t), 's' => Queue::statusLabel((string) $t['status'])]) : __('No token at this counter.');
                $level = $t ? 'success' : 'warning';
                break;
            case 'call':
                $code = req_str('number', $_POST, 20);
                $t = Queue::callNumber($counter, $code);
                $msg = $t ? __('Calling :t', ['t' => Queue::label($t)]) : __('Token :n is not waiting today.', ['n' => $code]);
                $level = $t ? 'success' : 'warning';
                break;
            case 'transfer':
                $svc = Queue::findService(req_int('service_id', $_POST)); // another hotel's id → 404
                $t = $svc ? Queue::transfer($counter, (int) $svc['id']) : null;
                $msg = $t ? __(':t sent to :s.', ['t' => Queue::label($t), 's' => $svc['name']]) : __('No token at this counter.');
                $level = $t ? 'success' : 'warning';
                break;
        }
        if ($ajax) {
            ajax_ok($state($counter, $msg, $level));
        }
        flash($level, $msg);
        $back(['counter' => (int) $counter['id']]);
    }

    // ---------------------------------------------------------------- setup (queue.manage)
    require_can('queue.manage');
    $id = req_int('id', $_POST);
    switch ($op) {
        case 'svc_save':
            [$sid, $errors] = Queue::saveService($_POST, $id ?: null);
            if ($errors) {
                flash_errors($errors);
            } else {
                ActivityLog::add($id ? 'queue_service_update' : 'queue_service_create', 'queue_service', (int) $sid, req_str('name', $_POST, 120));
                flash('success', __('Service ":t" saved.', ['t' => req_str('name', $_POST, 120)]));
            }
            $back(['tab' => 'setup']);
        case 'svc_delete':
            $s = Queue::findService($id);
            if ($s && Queue::deleteService($id)) {
                ActivityLog::add('queue_service_delete', 'queue_service', $id, (string) $s['name']);
                flash('success', __('Service ":t" deleted.', ['t' => $s['name']]));
            }
            $back(['tab' => 'setup']);
        case 'svc_reset':
            $s = Queue::findService($id);
            if ($s && Queue::reset($id)) {
                ActivityLog::add('queue_reset', 'queue_service', $id, (string) $s['name']);
                flash('success', __('Token numbers of ":t" start again from :n.', ['t' => $s['name'], 'n' => (int) $s['start_number']]));
            }
            $back(['tab' => 'setup']);
        case 'ctr_save':
            [$cid, $errors] = Queue::saveCounter($_POST, $id ?: null);
            if ($errors) {
                flash_errors($errors);
            } else {
                ActivityLog::add($id ? 'queue_counter_update' : 'queue_counter_create', 'queue_counter', (int) $cid, req_str('name', $_POST, 60));
                flash('success', __('Counter ":t" saved.', ['t' => req_str('name', $_POST, 60)]));
            }
            $back(['tab' => 'setup']);
        case 'ctr_delete':
            $c = Queue::findCounter($id);
            if ($c && Queue::deleteCounter($id)) {
                ActivityLog::add('queue_counter_delete', 'queue_counter', $id, (string) $c['name']);
                flash('success', __('Counter ":t" deleted.', ['t' => $c['name']]));
            }
            $back(['tab' => 'setup']);
        default:
            $ajax ? ajax_error(__('Unknown action.'), 422) : flash('warning', __('Unknown action.'));
            $back();
    }
}

$tab = req_str('tab', $_GET, 10) === 'setup' ? 'setup' : 'call';
if ($tab === 'setup' && !$canManage) {
    http_response_code(403);
    require __DIR__ . '/partials/forbidden.php';
    exit;
}
$counter = req_int('counter', $_GET) ? Queue::findCounter(req_int('counter', $_GET)) : null; // another hotel's id → 404
if ($counter && $ajax) {
    ajax_ok($state($counter)); // polling: GET queue.php?counter=ID with X-Requested-With
}

$activeNav = 'queue';
$pageTitle = __('Token queue');
$extraScripts = ['js/queue-admin.js'];
$services = Queue::services();
$svcName = [];
foreach ($services as $s) {
    $svcName[(int) $s['id']] = (string) $s['name'];
}
$counters = Queue::counters();

/** Service form (new when $f is null). */
$svcForm = static function (?array $f): string {
    $f ??= ['id' => 0, 'name' => '', 'prefix' => '', 'start_number' => 1, 'self_service' => 0, 'is_active' => 1, 'sort_order' => 0];
    $u = 's' . (int) $f['id'];
    $sw = static fn (string $n, string $l, bool $on): string => '<div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' . $u . $n . '" name="' . $n . '" value="1"' . ($on ? ' checked' : '') . '><label class="form-check-label" for="' . $u . $n . '">' . e($l) . '</label></div></div>';
    return '<form method="post" class="row g-2">' . Csrf::field() . '<input type="hidden" name="op" value="svc_save"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
        . '<div class="col-md-5"><label class="form-label small" for="' . $u . 'n">' . e(__('Name')) . '</label><input class="form-control" id="' . $u . 'n" name="name" value="' . e($f['name']) . '" maxlength="120" required placeholder="' . e(__('e.g. Dr. Patel – OPD')) . '"></div>'
        . '<div class="col-4 col-md-2"><label class="form-label small" for="' . $u . 'p">' . e(__('Prefix')) . '</label><input class="form-control text-uppercase" id="' . $u . 'p" name="prefix" value="' . e($f['prefix']) . '" maxlength="5" placeholder="A"></div>'
        . '<div class="col-4 col-md-3"><label class="form-label small" for="' . $u . 's">' . e(__('First number')) . '</label><input class="form-control" type="number" min="1" max="99999" id="' . $u . 's" name="start_number" value="' . (int) $f['start_number'] . '"></div>'
        . '<div class="col-4 col-md-2"><label class="form-label small" for="' . $u . 'o">' . e(__('Order')) . '</label><input class="form-control" type="number" id="' . $u . 'o" name="sort_order" value="' . (int) $f['sort_order'] . '"></div>'
        . $sw('self_service', __('Visitors may take a token on their phone (QR)'), (bool) (int) $f['self_service'])
        . $sw('is_active', __('Active'), (bool) (int) $f['is_active'])
        . '<div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> ' . e(__('Save')) . '</button></div></form>';
};

/** Counter form (new when $f is null). */
$ctrForm = static function (?array $f) use ($services): string {
    $f ??= ['id' => 0, 'name' => '', 'service_id' => null, 'room_text' => '', 'is_active' => 1, 'sort_order' => 0];
    $u = 'c' . (int) $f['id'];
    $opts = '<option value="0">' . e(__('All services')) . '</option>';
    foreach ($services as $s) {
        $opts .= '<option value="' . (int) $s['id'] . '"' . ((int) $f['service_id'] === (int) $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
    }
    return '<form method="post" class="row g-2">' . Csrf::field() . '<input type="hidden" name="op" value="ctr_save"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
        . '<div class="col-md-6"><label class="form-label small" for="' . $u . 'n">' . e(__('Name')) . '</label><input class="form-control" id="' . $u . 'n" name="name" value="' . e($f['name']) . '" maxlength="60" required placeholder="' . e(__('e.g. Counter 3')) . '"></div>'
        . '<div class="col-md-6"><label class="form-label small" for="' . $u . 's">' . e(__('Calls tokens of')) . '</label><select class="form-select" id="' . $u . 's" name="service_id">' . $opts . '</select></div>'
        . '<div class="col-8"><label class="form-label small" for="' . $u . 'r">' . e(__('Room / place (optional)')) . '</label><input class="form-control" id="' . $u . 'r" name="room_text" value="' . e((string) $f['room_text']) . '" maxlength="120" placeholder="' . e(__('e.g. Room 12, first floor')) . '"></div>'
        . '<div class="col-4"><label class="form-label small" for="' . $u . 'o">' . e(__('Order')) . '</label><input class="form-control" type="number" id="' . $u . 'o" name="sort_order" value="' . (int) $f['sort_order'] . '"></div>'
        . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' . $u . 'a" name="is_active" value="1"' . ((int) $f['is_active'] ? ' checked' : '') . '><label class="form-check-label" for="' . $u . 'a">' . e(__('Active')) . '</label></div></div>'
        . '<div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> ' . e(__('Save')) . '</button></div></form>';
};
require __DIR__ . '/partials/header.php';
?>
<style>
.q-token{font-size:clamp(3.5rem,18vw,7rem);font-weight:800;line-height:1;letter-spacing:.02em;font-variant-numeric:tabular-nums}
.q-btn{min-height:4.2rem;font-size:1.25rem;font-weight:700}
.q-next{min-height:5.5rem;font-size:1.8rem}
.q-counter-pick{min-height:4.5rem;font-size:1.25rem}
</style>
<div class="page-head">
  <div>
    <h1><i class="bi bi-people"></i> <?= e(__('Token queue')) ?></h1>
    <p class="lead-sm"><?= e(__('Call the next token at your counter. The Token display on the TVs shows it at once with a chime.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="<?= e(admin_url('queue_issue.php')) ?>"><i class="bi bi-ticket-perforated"></i> <?= e(__('Issue tokens')) ?></a>
    <?php if ($canManage): ?>
      <a class="btn <?= $tab === 'setup' ? 'btn-primary' : 'btn-light border' ?>" href="<?= e(admin_url('queue.php', ['tab' => 'setup'])) ?>"><i class="bi bi-gear"></i> <?= e(__('Setup')) ?></a>
    <?php endif; ?>
  </div>
</div>
<?php if ($tab === 'call'): ?>
  <?php if (!$counters): ?>
    <div class="card"><div class="hc-empty"><i class="bi bi-people"></i><p class="text-muted"><?= e(__('No counters yet.')) ?> <?= e($canManage ? __('Add services and counters in Setup.') : __('Ask your manager to add services and counters.')) ?></p>
      <?php if ($canManage): ?><a class="btn btn-primary" href="<?= e(admin_url('queue.php', ['tab' => 'setup'])) ?>"><?= e(__('Setup')) ?></a><?php endif; ?></div></div>
  <?php elseif (!$counter): ?>
    <h2 class="h5 mb-3"><?= e(__('Choose your counter')) ?></h2>
    <div class="row g-2" id="qCounterPick">
      <?php foreach ($counters as $c): if (!(int) $c['is_active']) { continue; } ?>
        <div class="col-6 col-md-4 col-lg-3"><a class="btn btn-outline-primary w-100 q-counter-pick" data-counter-link="<?= (int) $c['id'] ?>" href="<?= e(admin_url('queue.php', ['counter' => $c['id']])) ?>">
          <strong><?= e($c['name']) ?></strong><br><small><?= e($c['service_id'] ? ($svcName[(int) $c['service_id']] ?? '') : __('All services')) ?></small></a></div>
      <?php endforeach; ?>
    </div>
  <?php else: $st = $state($counter); ?>
    <div class="row g-3" id="qApp" data-counter="<?= (int) $counter['id'] ?>" data-url="<?= e(admin_url('queue.php')) ?>">
      <div class="col-lg-7">
        <div class="card text-center"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold"><i class="bi bi-display"></i> <?= e($counter['name']) ?> · <span class="text-muted"><?= e($counter['service_id'] ? ($svcName[(int) $counter['service_id']] ?? '') : __('All services')) ?></span></span>
            <a class="small" href="<?= e(admin_url('queue.php')) ?>" data-forget-counter><?= e(__('Change counter')) ?></a>
          </div>
          <div class="text-muted small text-uppercase"><?= e(__('Now at your counter')) ?></div>
          <div class="q-token my-2" id="qCurrent"><?= e($st['current']['label'] ?? '—') ?></div>
          <div class="mb-3"><span class="badge rounded-pill text-bg-secondary" id="qStatus"><?= e($st['current']['status_label'] ?? __('No token')) ?></span>
            <span class="small text-muted ms-2" id="qCustomer"><?= e(trim(($st['current']['name'] ?? '') . ' ' . ($st['current']['phone'] ?? ''))) ?></span></div>
          <div id="qMsg" class="alert py-2 d-none" role="status"></div>
          <form method="post" class="q-op" data-q-op><?= Csrf::field() ?><input type="hidden" name="counter" value="<?= (int) $counter['id'] ?>"><input type="hidden" name="op" value="next">
            <button class="btn btn-primary w-100 q-btn q-next"><i class="bi bi-skip-forward-fill"></i> <?= e(__('NEXT')) ?> <span class="badge text-bg-light ms-1" data-q-waiting><?= (int) $st['waiting'] ?></span></button></form>
          <div class="row g-2 mt-1">
            <?php foreach ([['recall', 'btn-warning', 'bi-megaphone', __('RECALL')], ['serving', 'btn-info', 'bi-person-check', __('ARRIVED')], ['done', 'btn-success', 'bi-check2-circle', __('DONE')],
                ['skip', 'btn-outline-secondary', 'bi-skip-end', __('SKIP')], ['no_show', 'btn-outline-danger', 'bi-person-x', __('NO-SHOW')]] as [$op, $cls, $icon, $label]): ?>
              <div class="<?= $op === 'recall' ? 'col-12 col-sm-4' : 'col-6 col-sm-4' ?>"><form method="post" data-q-op><?= Csrf::field() ?><input type="hidden" name="counter" value="<?= (int) $counter['id'] ?>"><input type="hidden" name="op" value="<?= e($op) ?>">
                <button class="btn <?= e($cls) ?> w-100 q-btn"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></button></form></div>
            <?php endforeach; ?>
          </div>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-body">
          <form method="post" data-q-op class="mb-3"><?= Csrf::field() ?><input type="hidden" name="counter" value="<?= (int) $counter['id'] ?>"><input type="hidden" name="op" value="call">
            <label class="form-label fw-semibold" for="qNumber"><?= e(__('Call a specific number')) ?></label>
            <div class="input-group input-group-lg"><input class="form-control" id="qNumber" name="number" placeholder="<?= e(__('e.g. A-25')) ?>" maxlength="20" autocomplete="off" required>
              <button class="btn btn-primary"><i class="bi bi-megaphone"></i> <?= e(__('Call')) ?></button></div>
          </form>
          <?php if (count($services) > 1): ?>
          <form method="post" data-q-op><?= Csrf::field() ?><input type="hidden" name="counter" value="<?= (int) $counter['id'] ?>"><input type="hidden" name="op" value="transfer">
            <label class="form-label fw-semibold" for="qTransfer"><?= e(__('Transfer the current token to')) ?></label>
            <div class="input-group input-group-lg"><select class="form-select" id="qTransfer" name="service_id"><?php foreach ($services as $s): if (!(int) $s['is_active']) { continue; } ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
              <button class="btn btn-outline-primary"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer')) ?></button></div>
          </form>
          <?php endif; ?>
        </div></div>
        <div class="card"><div class="card-body">
          <div class="d-flex justify-content-between"><strong><?= e(__('Waiting')) ?></strong><span class="badge text-bg-primary fs-6" data-q-waiting><?= (int) $st['waiting'] ?></span></div>
          <div class="mt-2 d-flex flex-wrap gap-1" id="qNext"><?php foreach ($st['next'] as $l): ?><span class="badge text-bg-light border fs-6"><?= e($l) ?></span><?php endforeach; ?></div>
        </div></div>
      </div>
    </div>
  <?php endif; ?>
<?php else: // ------------------------------------------------------------- setup
    $stats = Queue::todayStats(); ?>
  <div class="row g-3">
    <div class="col-12"><div class="card"><div class="card-body d-flex flex-wrap gap-3 small">
      <span><?= e(__('Today')) ?>:</span>
      <?php foreach ($stats as $k => $n): ?><span><?= e(Queue::statusLabel($k)) ?>: <strong><?= (int) $n ?></strong></span><?php endforeach; ?>
    </div></div></div>
    <div class="col-xl-7">
      <div class="card"><div class="card-header fw-semibold"><?= e(__('Services')) ?></div>
        <div class="table-responsive"><table class="table table-hc align-middle mb-0">
          <thead><tr><th><?= e(__('Service')) ?></th><th><?= e(__('Prefix')) ?></th><th><?= e(__('Today')) ?></th><th><?= e(__('Self-service')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): $todayLast = $s['last_date'] === Queue::today() ? (int) $s['last_number'] : 0; ?>
            <tr>
              <td><strong><?= e($s['name']) ?></strong><?php if (!(int) $s['is_active']): ?> <span class="badge text-bg-secondary"><?= e(__('Off')) ?></span><?php endif; ?></td>
              <td><?= e($s['prefix'] !== '' ? $s['prefix'] . '-' : '—') ?></td>
              <td><?= $todayLast ? e(Queue::label(['prefix' => $s['prefix'], 'number' => $todayLast])) : '—' ?></td>
              <td><?php if ((int) $s['self_service']): ?><a href="<?= e(Queue::publicUrl($s)) ?>" target="_blank" rel="noopener" class="small"><i class="bi bi-phone"></i> <?= e(__('Open link')) ?></a><?php else: ?>—<?php endif; ?></td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#svc<?= (int) $s['id'] ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></button>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Start the token numbers of ":t" again? Today\'s tokens of this service are removed.', ['t' => $s['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="svc_reset"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-outline-warning" title="<?= e(__('Reset numbers')) ?>"><i class="bi bi-arrow-counterclockwise"></i></button></form>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Delete service ":t" and its tokens?', ['t' => $s['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="svc_delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
            <tr class="collapse" id="svc<?= (int) $s['id'] ?>"><td colspan="5"><?= $svcForm($s) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="card-body border-top"><h3 class="h6"><?= e(__('New service')) ?></h3><?= $svcForm(null) ?></div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card"><div class="card-header fw-semibold"><?= e(__('Counters')) ?></div>
        <ul class="list-group list-group-flush">
        <?php foreach ($counters as $c): ?>
          <li class="list-group-item">
            <div class="d-flex align-items-center gap-2">
              <div class="me-auto"><strong><?= e($c['name']) ?></strong> <span class="small text-muted"><?= e($c['service_id'] ? ($svcName[(int) $c['service_id']] ?? '') : __('All services')) ?><?= $c['room_text'] ? ' · ' . e($c['room_text']) : '' ?></span>
                <?php if (!(int) $c['is_active']): ?> <span class="badge text-bg-secondary"><?= e(__('Off')) ?></span><?php endif; ?></div>
              <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#ctr<?= (int) $c['id'] ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline" data-confirm="<?= e(__('Delete counter ":t"?', ['t' => $c['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="ctr_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
            </div>
            <div class="collapse mt-2" id="ctr<?= (int) $c['id'] ?>"><?= $ctrForm($c) ?></div>
          </li>
        <?php endforeach; ?>
        </ul>
        <div class="card-body border-top"><h3 class="h6"><?= e(__('New counter')) ?></h3><?= $ctrForm(null) ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
