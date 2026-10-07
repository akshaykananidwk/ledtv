<?php
declare(strict_types=1);
/**
 * KPI dashboard (#8): tiles of the "KPI dashboard" display app (core/Apps/KpiDashboardApp.php).
 * Phone friendly: each tile is a card with big −1 / +1 / Set buttons (AJAX when JavaScript is on,
 * normal form posts otherwise); days-since tiles get "Reset to today". Edit: name, type, value,
 * target, unit, colour thresholds, machine push token (POST /api/kpi/push, shown once).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('kpi.manage');
Csrf::check();

$formRow = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? Kpi::find($id) : null; // another hotel's id → 404
    $ajax = Auth::isAjax();
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        if ($ajax) {
            ajax_error(__('Tile not found.'), 404, 'NOT_FOUND');
        }
        flash('warning', __('Tile not found.'));
        redirect(admin_url('kpi.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Kpi::validate($_POST);
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'push_token_hash' => $existing['push_token_hash'] ?? null, 'push_token_hint' => $existing['push_token_hint'] ?? '', 'pushed_at' => $existing['pushed_at'] ?? null] + $data + Kpi::DEFAULTS;
                break;
            }
            $newId = Kpi::save($existing ? $id : null, $data);
            ActivityLog::add($existing ? 'kpi_update' : 'kpi_create', 'kpi_tile', $newId, mb_substr($data['label'], 0, 120));
            flash('success', __('Tile ":t" saved.', ['t' => $data['label']]));
            redirect(admin_url('kpi.php'));

        case 'quick':
            $do = req_str('do', $_POST, 10);
            try {
                $row = Kpi::apply($existing, $do, $_POST['value'] ?? null);
            } catch (InvalidArgumentException $e) {
                if ($ajax) {
                    ajax_error($e->getMessage(), 422, 'VALIDATION_ERROR');
                }
                http_response_code(422);
                flash('danger', $e->getMessage());
                redirect(admin_url('kpi.php') . '#t' . $id);
            }
            ActivityLog::add('kpi_value', 'kpi_tile', $id, mb_substr($existing['label'] . ': ' . $do . ' ' . (is_scalar($_POST['value'] ?? null) ? (string) $_POST['value'] : ''), 0, 120));
            $v = Kpi::view($row, time());
            if ($ajax) {
                ajax_ok(['id' => $id, 'value' => $v['value'], 'unit' => $v['unit'], 'level' => $v['level'], 'progress' => $v['progress']]);
            }
            flash('success', __(':t is now :v', ['t' => $existing['label'], 'v' => trim($v['value'] . ' ' . ($v['type'] !== 'text' ? $v['unit'] : ''))]));
            redirect(admin_url('kpi.php') . '#t' . $id);

        case 'token':
            $token = Kpi::newToken($id);
            ActivityLog::add('kpi_token', 'kpi_tile', $id, mb_substr((string) $existing['label'], 0, 120));
            $_SESSION['kpi_new_token'] = ['id' => $id, 'token' => $token];
            flash('success', __('New machine token created. Copy it now: it is shown only once.'));
            redirect(admin_url('kpi.php', ['action' => 'edit', 'id' => $id]) . '#push');

        case 'revoke':
            Kpi::revokeToken($id);
            ActivityLog::add('kpi_token_revoke', 'kpi_tile', $id, mb_substr((string) $existing['label'], 0, 120));
            flash('success', __('Machine push is off for ":t".', ['t' => $existing['label']]));
            redirect(admin_url('kpi.php', ['action' => 'edit', 'id' => $id]) . '#push');

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('kpi_tiles', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('kpi_toggle', 'kpi_tile', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['label'], 0, 100));
            flash('success', $on ? __('Tile ":t" is shown again.', ['t' => $existing['label']]) : __('Tile ":t" is hidden.', ['t' => $existing['label']]));
            redirect(admin_url('kpi.php'));

        case 'delete':
            Kpi::delete($id);
            ActivityLog::add('kpi_delete', 'kpi_tile', $id, mb_substr((string) $existing['label'], 0, 120));
            flash('success', __('Tile ":t" deleted.', ['t' => $existing['label']]));
            redirect(admin_url('kpi.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('kpi.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'kpi';
$pageTitle = __('KPI dashboard');
$types = array_map('__', Kpi::TYPES);
$num = static fn ($v): string => $v === null || $v === '' ? '' : BusinessApps::plain((float) $v);

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = Kpi::DEFAULTS;
        if ($action === 'edit') {
            $formRow = Kpi::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Tile not found.'));
                redirect(admin_url('kpi.php'));
            }
        }
    }
    $t = $formRow;
    $isEdit = (int) $t['id'] > 0;
    $newToken = null;
    if ($isEdit && ($_SESSION['kpi_new_token']['id'] ?? 0) === (int) $t['id']) {
        $newToken = (string) $_SESSION['kpi_new_token']['token'];
    }
    unset($_SESSION['kpi_new_token']);
    $pageTitle = $isEdit ? __('Edit tile') : __('New tile');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-speedometer2"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('One box on the KPI dashboard. TVs update within 10 seconds.')) ?></p></div>
      <a href="<?= e(admin_url('kpi.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="<?= e(admin_url('kpi.php')) ?>" id="kpiForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="k_label"><?= e(__('Name')) ?> *</label>
            <input class="form-control form-control-lg" id="k_label" name="label" value="<?= e($t['label']) ?>" required maxlength="120" placeholder="<?= e(__('e.g. Days without accident, Production today')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="k_type"><?= e(__('Type')) ?></label>
            <select class="form-select form-select-lg" id="k_type" name="type">
              <?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= $t['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" data-for="counter percent">
            <label class="form-label" for="k_value"><?= e(__('Value')) ?></label>
            <input class="form-control form-control-lg" id="k_value" name="value_num" inputmode="decimal" value="<?= e($num($t['value_num'])) ?>">
          </div>
          <div class="col-md-6" data-for="text">
            <label class="form-label" for="k_text"><?= e(__('Text')) ?></label>
            <input class="form-control form-control-lg" id="k_text" name="value_text" maxlength="190" value="<?= e($t['value_text']) ?>" placeholder="<?= e(__('e.g. Running')) ?>">
          </div>
          <div class="col-md-6" data-for="days_since">
            <label class="form-label" for="k_since"><?= e(__('Count days from')) ?></label>
            <input class="form-control form-control-lg" type="date" id="k_since" name="since_date" value="<?= e((string) $t['since_date']) ?>" max="<?= e(date('Y-m-d')) ?>">
            <div class="form-text"><?= e(__('e.g. the date of the last accident.')) ?></div>
          </div>
          <div class="col-6 col-md-4" data-for="counter percent days_since">
            <label class="form-label" for="k_target"><?= e(__('Target')) ?></label>
            <input class="form-control" id="k_target" name="target" inputmode="decimal" value="<?= e($num($t['target'])) ?>">
          </div>
          <div class="col-6 col-md-4" data-for="counter percent days_since">
            <label class="form-label" for="k_unit"><?= e(__('Unit')) ?></label>
            <input class="form-control" id="k_unit" name="unit" maxlength="20" value="<?= e($t['unit']) ?>" placeholder="<?= e(__('pcs, kg, %')) ?>">
          </div>
          <div class="col-6 col-md-2" data-for="counter percent days_since">
            <label class="form-label" for="k_good"><?= e(__('Green from')) ?></label>
            <input class="form-control" id="k_good" name="good_at" inputmode="decimal" value="<?= e($num($t['good_at'])) ?>">
          </div>
          <div class="col-6 col-md-2" data-for="counter percent days_since">
            <label class="form-label" for="k_bad"><?= e(__('Red at')) ?></label>
            <input class="form-control" id="k_bad" name="bad_at" inputmode="decimal" value="<?= e($num($t['bad_at'])) ?>">
          </div>
          <div class="col-12 form-text mt-0" data-for="counter percent days_since"><?= e(__('Colours: green when the value reaches "Green from", red at "Red at" or worse, amber between. If "Green from" is smaller than "Red at", lower values are better (e.g. rejects).')) ?></div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="k_sort"><?= e(__('Sort order')) ?></label>
            <input class="form-control" type="number" id="k_sort" name="sort" min="-1000" max="1000" value="<?= (int) $t['sort'] ?>">
            <div class="form-text"><?= e(__('Smaller numbers are shown first.')) ?></div>
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="k_active" name="is_active" value="1"<?= (int) $t['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="k_active"><?= e(__('Show on TVs')) ?></label>
          </div></div>
        </div></div>
        <div class="d-grid d-sm-flex gap-2 mt-3">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('kpi.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div></div>
      </div>
    </form>
    <?php if ($isEdit): ?>
      <div class="card mt-4" id="push"><div class="card-body">
        <h2 class="h5"><i class="bi bi-cpu"></i> <?= e(__('Machine push (optional)')) ?></h2>
        <p class="text-muted small mb-2"><?= e(__('A machine, PLC or script can set this tile with a secret token. Keep the token private; create a new one if it leaks.')) ?></p>
        <?php if ($newToken !== null): ?>
          <div class="alert alert-warning"><div class="small mb-1"><?= e(__('Your token (shown only once):')) ?></div>
            <input class="form-control font-monospace" readonly value="<?= e($newToken) ?>" id="k_token" onclick="this.select()"></div>
        <?php endif; ?>
        <?php if (!empty($t['push_token_hash'])): ?>
          <p class="mb-2"><span class="badge text-bg-success"><?= e(__('On')) ?></span> <?= e(__('Token ending in :h', ['h' => $t['push_token_hint']])) ?>
            <?php if (!empty($t['pushed_at'])): ?><span class="text-muted small"> · <?= e(__('Last push: :t', ['t' => (string) $t['pushed_at']])) ?></span><?php endif; ?></p>
          <pre class="small bg-light border rounded p-2 text-wrap">curl -X POST <?= e(base_url('api/kpi/push')) ?> \
  -H "Authorization: Bearer <?= e($newToken ?? 'kpi_…') ?>" \
  -H "Content-Type: application/json" -d '{"<?= $t['type'] === 'days_since' ? 'reset": true' : ($t['type'] === 'text' ? 'value": "Running"' : 'add": 1') ?>}'</pre>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap">
          <form method="post" action="<?= e(admin_url('kpi.php')) ?>"<?= !empty($t['push_token_hash']) ? ' data-confirm="' . e(__('Create a new token? The old one stops working.')) . '"' : '' ?>><?= Csrf::field() ?><input type="hidden" name="op" value="token"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-outline-primary"><i class="bi bi-key"></i> <?= e(!empty($t['push_token_hash']) ? __('New token') : __('Turn on machine push')) ?></button></form>
          <?php if (!empty($t['push_token_hash'])): ?>
            <form method="post" action="<?= e(admin_url('kpi.php')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="revoke"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> <?= e(__('Turn off')) ?></button></form>
          <?php endif; ?>
        </div>
      </div></div>
    <?php endif; ?>
    <script>
    (function () {
      var sel = document.getElementById('k_type');
      function sync() {
        document.querySelectorAll('#kpiForm [data-for]').forEach(function (el) {
          el.style.display = el.getAttribute('data-for').split(' ').indexOf(sel.value) >= 0 ? '' : 'none';
        });
      }
      sel.addEventListener('change', sync);
      sync();
    })();
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- tiles with quick buttons (phone friendly)
$tiles = Kpi::all();
$screens = BusinessApps::screens('kpi_dashboard');
$now = time();
$levelCls = ['good' => 'text-success', 'warn' => 'text-warning', 'bad' => 'text-danger', '' => ''];
$qf = static function (int $id, string $do, string $inner, string $cls, ?string $value = null, string $extra = ''): string {
    return '<form method="post" class="kp-quick d-inline"' . $extra . '>' . Csrf::field() . '<input type="hidden" name="op" value="quick"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="do" value="' . e($do) . '">' . ($value !== null ? '<input type="hidden" name="value" value="' . e($value) . '">' : '')
        . '<button class="btn ' . e($cls) . '">' . $inner . '</button></form>';
};
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-speedometer2"></i> <?= e(__('KPI dashboard')) ?></h1>
    <p class="lead-sm"><?= e(__('Update the numbers from your phone; every dashboard TV follows within 10 seconds.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'kpi_dashboard'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Dashboard screens (:n)', ['n' => $screens]) : __('Create a dashboard screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('kpi.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New tile')) ?></a>
  </div>
</div>
<?php if (!$tiles): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-speedometer2"></i><p class="text-muted"><?= e(__('No tiles yet. Add the first one, e.g. "Days without accident".')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($tiles as $t): $v = Kpi::view($t, $now); $id = (int) $t['id']; ?>
    <div class="col-12 col-md-6 col-xl-4" id="t<?= $id ?>" data-tile="<?= $id ?>">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between gap-2">
          <strong class="text-break"><?= e($t['label']) ?></strong>
          <span class="text-nowrap">
            <?php if (!(int) $t['is_active']): ?><span class="badge rounded-pill text-bg-light border"><i class="bi bi-pause-circle"></i> <?= e(__('Off')) ?></span><?php endif; ?>
            <?php if (!empty($t['push_token_hash'])): ?><span class="badge rounded-pill text-bg-light border" title="<?= e(__('Machine push')) ?>"><i class="bi bi-cpu"></i></span><?php endif; ?>
            <span class="badge text-bg-light border"><?= e($types[$v['type']]) ?></span>
          </span>
        </div>
        <div class="display-5 fw-bold my-1 <?= e($levelCls[$v['level']] ?? '') ?>" data-value><?= e($v['value'] !== '' ? $v['value'] : '—') ?><?php if ($v['type'] !== 'text' && $v['unit'] !== ''): ?> <small class="fs-5 text-muted"><?= e($v['unit']) ?></small><?php endif; ?></div>
        <?php if ($v['target_text'] !== ''): ?><div class="small text-muted"><?= e(__('Target: :t', ['t' => $v['target_text']])) ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php if ($v['type'] === 'counter' || $v['type'] === 'percent'): ?>
            <?= $qf($id, 'add', '−1', 'btn-outline-secondary btn-lg px-4', '-1') ?>
            <?= $qf($id, 'add', '+1', 'btn-success btn-lg px-4', '1') ?>
            <form method="post" class="kp-quick d-flex gap-1 flex-grow-1"><?= Csrf::field() ?><input type="hidden" name="op" value="quick"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="set">
              <input class="form-control form-control-lg" name="value" inputmode="decimal" placeholder="<?= e(__('Value')) ?>" aria-label="<?= e(__('Value')) ?>" style="min-width:5rem">
              <button class="btn btn-primary btn-lg"><?= e(__('Set')) ?></button></form>
          <?php elseif ($v['type'] === 'text'): ?>
            <form method="post" class="kp-quick d-flex gap-1 flex-grow-1"><?= Csrf::field() ?><input type="hidden" name="op" value="quick"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="set">
              <input class="form-control form-control-lg" name="value" maxlength="190" value="<?= e($t['value_text']) ?>" aria-label="<?= e(__('Text')) ?>">
              <button class="btn btn-primary btn-lg"><?= e(__('Set')) ?></button></form>
          <?php else: ?>
            <div class="small text-muted w-100"><?= e(__('Counting from :d', ['d' => (string) $t['since_date']])) ?></div>
            <?= $qf($id, 'reset', '<i class="bi bi-arrow-counterclockwise"></i> ' . e(__('Reset to today')), 'btn-outline-danger btn-lg', null, ' data-confirm="' . e(__('Start counting again from today?')) . '" data-noajax="1"') ?>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2 mt-2">
          <a class="btn btn-sm btn-light border" href="<?= e(admin_url('kpi.php', ['action' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-light border"><i class="bi <?= (int) $t['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i> <?= e((int) $t['is_active'] ? __('Hide') : __('Show')) ?></button></form>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Delete tile ":t"?', ['t' => $t['label']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
  <script>
  // Quick buttons without reloading the page (falls back to a normal post without JavaScript).
  document.querySelectorAll('form.kp-quick:not([data-noajax])').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var card = f.closest('[data-tile]'), btn = f.querySelector('button');
      btn.disabled = true;
      fetch(f.action || location.href, { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { throw new Error((j.error && j.error.message) || 'Error'); }
          var el = card.querySelector('[data-value]'), d = j.data;
          el.className = el.className.replace(/\btext-(success|warning|danger)\b/g, '') + ({ good: ' text-success', warn: ' text-warning', bad: ' text-danger' }[d.level] || '');
          el.firstChild.nodeValue = d.value === '' ? '—' : d.value;
          var inp = f.querySelector('input[name=value]:not([type=hidden])');
          if (inp && inp.inputMode === 'decimal') { inp.value = ''; }
        })
        .catch(function (e) { alert(e.message); })
        .then(function () { btn.disabled = false; });
    });
  });
  </script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
