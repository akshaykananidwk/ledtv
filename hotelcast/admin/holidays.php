<?php
/**
 * Holidays (2.4, #35): dates on which TVs switch off, show special content, or that are only marked in
 * the calendar. Starter list of Indian public holidays 2026–2027. Logic: core/Holidays.php; applied by
 * ContentResolver (TVs off works like a scheduled power-off; emergencies still win).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('holidays.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    try {
        switch ($op) {
            case 'save':
                $existing = $id ? Holidays::find($id) : null; // another hotel's id → 404
                if ($id && !$existing) {
                    flash('warning', __('Holiday not found.'));
                    break;
                }
                if ($existing) {
                    Access::requireBroadcast($existing);
                }
                [$data, $errors] = Holidays::validate($_POST + ['is_active' => '']);
                if ($errors) {
                    flash_errors($errors);
                    redirect(admin_url('holidays.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
                }
                $id = Holidays::save($data, $existing ? $id : null);
                ActivityLog::add($existing ? 'holiday_update' : 'holiday_create', 'holiday', $id, $data['name'] . ' ' . $data['start_date'] . ' · ' . $data['action']);
                flash('success', __('Holiday ":n" saved.', ['n' => $data['name']]));
                break;
            case 'delete':
                $h = Holidays::find($id);
                if ($h) {
                    Access::requireBroadcast($h);
                    Holidays::delete($h);
                    ActivityLog::add('holiday_delete', 'holiday', $id, (string) $h['name']);
                    flash('success', __('Holiday ":n" deleted.', ['n' => $h['name']]));
                }
                break;
            case 'toggle':
                $h = Holidays::find($id);
                if ($h) {
                    Access::requireBroadcast($h);
                    DB::update('holidays', ['is_active' => (int) $h['is_active'] ? 0 : 1], 'id = :id', ['id' => $id]);
                    Settings::bumpContentVersion();
                    Holidays::refreshTvs($h);
                    flash('success', (int) $h['is_active'] ? __('Holiday paused.') : __('Holiday active again.'));
                }
                break;
            case 'import':
                Access::requireUnrestricted('holiday import'); // hotel-wide entries
                $n = Holidays::importIndian((string) ($_POST['action'] ?? 'none'));
                ActivityLog::add('holiday_import', 'holiday', null, $n . ' Indian public holidays');
                flash('success', __(':n holidays added. Please check the dates against the official list for your state.', ['n' => $n]));
                break;
            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('holidays.php'));
}

$action = req_str('action', $_GET, 20);
$pageTitle = __('Holidays');
$activeNav = 'holidays';
$actionHelp = [
    'none' => __('Only shown in the calendar.'),
    'tv_off' => __('TVs are off the whole day, like a scheduled power-off. Emergency messages still appear.'),
    'show_content' => __('TVs show the chosen content instead of their own. Time-window schedules of that day still win.'),
];

if ($action === 'new' || $action === 'edit') {
    $h = ['id' => 0, 'name' => '', 'start_date' => (string) ($_GET['date'] ?? date('Y-m-d')), 'end_date' => '', 'action' => 'tv_off',
        'content_id' => null, 'playlist_id' => null, 'target_type' => Access::restricted() ? 'rooms' : 'all', 'target_ids' => '[]', 'is_active' => 1];
    if ($action === 'edit') {
        $h = Holidays::find(req_int('id', $_GET));
        if (!$h) {
            flash('warning', __('Holiday not found.'));
            redirect(admin_url('holidays.php'));
        }
        Access::requireBroadcast($h);
    }
    $h['start_date'] = Holidays::parseDate($h['start_date']) ?? date('Y-m-d');
    $pageTitle = $h['id'] ? __('Edit holiday') : __('New holiday');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-balloon"></i> <?= e($pageTitle) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('holidays.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card card-body" id="holForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="hname"><?= e(__('Name')) ?> *</label>
          <input class="form-control" id="hname" name="name" value="<?= e($h['name']) ?>" required maxlength="190" placeholder="<?= e(__('e.g. Diwali')) ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="hstart"><?= e(__('Date')) ?> *</label>
          <input type="date" class="form-control" id="hstart" name="start_date" value="<?= e($h['start_date']) ?>" required>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="hend"><?= e(__('Until (optional)')) ?></label>
          <input type="date" class="form-control" id="hend" name="end_date" value="<?= e($h['end_date'] !== $h['start_date'] ? (string) $h['end_date'] : '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label d-block"><?= e(__('On this day')) ?></label>
          <?php foreach (Holidays::ACTIONS as $a): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="action" value="<?= e($a) ?>" id="ha_<?= e($a) ?>"<?= $h['action'] === $a ? ' checked' : '' ?>>
              <label class="form-check-label" for="ha_<?= e($a) ?>"><strong><?= e(Holidays::actionLabel($a)) ?></strong> — <span class="text-muted"><?= e($actionHelp[$a]) ?></span></label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="col-md-6" data-hol-source>
          <label class="form-label" for="hsource"><?= e(__('What to show')) ?></label>
          <?= source_select('source', source_value($h['content_id'], $h['playlist_id']), __('— Choose content or playlist —'), ['id' => 'hsource']) ?>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(__('On which TVs')) ?></label>
          <?= target_picker('hol', ['type' => $h['target_type'], 'ids' => json_decode((string) $h['target_ids'], true) ?: []]) ?>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="hactive" name="is_active" value="1"<?= (int) $h['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="hactive"><?= e(__('Active')) ?></label>
          </div>
        </div>
        <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const f = document.getElementById('holForm');
      const sync = () => { const a = (f.querySelector('input[name=action]:checked') || {}).value; f.querySelector('[data-hol-source]').hidden = a !== 'show_content'; };
      f.querySelectorAll('input[name=action]').forEach((r) => r.addEventListener('change', sync));
      sync();
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$year = req_int('year', $_GET) ?: (int) date('Y');
$rows = DB::all('SELECT * FROM holidays WHERE hotel_id = :hid AND end_date >= :a AND start_date <= :b ORDER BY start_date, id',
    ['a' => $year . '-01-01', 'b' => $year . '-12-31'] + hid());
$rows = array_values(array_filter($rows, [Holidays::class, 'visible']));
$today = date('Y-m-d');
$extraScripts = ['vendor/fullcalendar/index.global.min.js'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Holidays')) ?></h1>
    <p class="lead-sm"><?= e(__('Days on which TVs switch off or show special content. Hotel time (:tz).', ['tz' => date_default_timezone_get()])) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (Auth::can('schedule.manage')): ?><a class="btn btn-light border" href="<?= e(admin_url('calendar.php')) ?>"><i class="bi bi-calendar3"></i> <?= e(__('Calendar')) ?></a><?php endif; ?>
    <a class="btn btn-primary" href="<?= e(admin_url('holidays.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New holiday')) ?></a>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('holidays.php', ['year' => $year - 1])) ?>" aria-label="<?= e(__('Previous year')) ?>"><i class="bi bi-chevron-left"></i></a>
        <strong><?= (int) $year ?></strong>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('holidays.php', ['year' => $year + 1])) ?>" aria-label="<?= e(__('Next year')) ?>"><i class="bi bi-chevron-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-hc table-hover mb-0">
          <thead><tr><th><?= e(__('Date')) ?></th><th><?= e(__('Name')) ?></th><th><?= e(__('On this day')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Target')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5"><div class="hc-empty py-4"><i class="bi bi-balloon"></i><p class="text-muted mb-0"><?= e(__('No holidays in this year yet.')) ?></p></div></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $h): $mine = Access::canBroadcast($h); $now = $h['start_date'] <= $today && $h['end_date'] >= $today; ?>
            <tr class="<?= (int) $h['is_active'] ? '' : 'text-muted' ?>">
              <td class="text-nowrap small"><?= e(date('d M Y', (int) strtotime($h['start_date']))) ?><?php if ($h['end_date'] !== $h['start_date']): ?> – <?= e(date('d M Y', (int) strtotime($h['end_date']))) ?><?php endif; ?>
                <?php if ($now): ?><span class="badge text-bg-success"><?= e(__('Today')) ?></span><?php endif; ?></td>
              <td><strong><?= e($h['name']) ?></strong><?php if (!(int) $h['is_active']): ?> <span class="badge text-bg-secondary"><?= e(__('paused')) ?></span><?php endif; ?>
                <?php if ($h['action'] === 'show_content'): ?><div class="small text-muted"><?= e(source_label($h['content_id'], $h['playlist_id'])) ?></div><?php endif; ?></td>
              <td><span class="badge <?= $h['action'] === 'tv_off' ? 'text-bg-dark' : ($h['action'] === 'show_content' ? 'text-bg-primary' : 'text-bg-light border') ?>"><?= e(Holidays::actionLabel($h['action'])) ?></span></td>
              <td class="d-none d-md-table-cell small"><?= e(Broadcaster::describeTarget($h['target_type'], $h['target_ids'])) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($mine): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(admin_url('holidays.php', ['action' => 'edit', 'id' => $h['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                  <button class="btn btn-sm btn-light border" title="<?= e((int) $h['is_active'] ? __('Pause') : __('Resume')) ?>"><i class="bi <?= (int) $h['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Delete holiday ":n"?', ['n' => $h['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if (!Access::restricted()): ?>
    <form method="post" class="card card-body mt-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="import">
      <div class="fw-semibold mb-1"><i class="bi bi-download"></i> <?= e(__('Import Indian public holidays 2026–2027')) ?></div>
      <p class="small text-muted mb-2"><?= e(__('A starter list of national holidays and major festivals for all TVs. Festival dates follow the lunar calendar and can differ by a day — please verify them yourself against the official list for your state. Entries already present are skipped.')) ?></p>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <label class="small" for="impAction"><?= e(__('On these days')) ?></label>
        <select class="form-select form-select-sm" style="width:auto" id="impAction" name="action">
          <option value="none"><?= e(Holidays::actionLabel('none')) ?></option>
          <option value="tv_off"><?= e(Holidays::actionLabel('tv_off')) ?></option>
        </select>
        <button class="btn btn-sm btn-outline-primary"><?= e(__('Import')) ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-xl-5">
    <div class="card"><div class="card-body"><div id="holCal"></div></div></div>
  </div>
</div>
<script type="application/json" id="holData"><?= json_embed([
    'events' => array_map(static fn ($h) => [
        'title' => $h['name'], 'start' => $h['start_date'], 'end' => date('Y-m-d', (int) strtotime($h['end_date'] . ' +1 day')), 'allDay' => true,
        'color' => $h['action'] === 'tv_off' ? Calendar::COLORS['holiday_off'] : Calendar::COLORS['holiday'],
        'url' => Access::canBroadcast($h) ? admin_url('holidays.php', ['action' => 'edit', 'id' => $h['id']]) : '',
    ], $rows),
    'initial' => $year === (int) date('Y') ? date('Y-m-d') : $year . '-01-01',
    'lang' => I18n::lang(),
    'newUrl' => admin_url('holidays.php', ['action' => 'new']),
    't' => ['today' => __('Today')],
]) ?></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('holCal');
  if (!window.FullCalendar || !el) return;
  const D = JSON.parse(document.getElementById('holData').textContent);
  new FullCalendar.Calendar(el, {
    timeZone: 'UTC',
    initialView: 'dayGridMonth',
    initialDate: D.initial,
    headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
    buttonText: { today: D.t.today },
    firstDay: 1,
    height: 'auto',
    locale: D.lang === 'gu' ? 'gu' : 'en',
    events: D.events,
    dateClick: (info) => { window.location.href = D.newUrl + (D.newUrl.indexOf('?') >= 0 ? '&' : '?') + 'date=' + encodeURIComponent(info.dateStr); },
  }).render();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
