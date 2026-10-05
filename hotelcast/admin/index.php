<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('dashboard.view');
Csrf::check();
Scheduler::tick();

$stats = hc_dashboard_stats();
$activity = DB::all('SELECT * FROM activity_logs WHERE hotel_id = :hid ORDER BY id DESC LIMIT 10', hid());
$roomStatus = hc_room_status_list();
$emergencies = Broadcaster::activeEmergencies();

$pageTitle = __('Dashboard');
$activeNav = 'index';
require __DIR__ . '/partials/header.php';

$cards = [
    ['rooms', 'bi-door-closed', 'bg-soft-primary', __('Total rooms'), (string) $stats['rooms'], admin_url('rooms.php')],
    ['online', 'bi-wifi', 'bg-soft-success', __('TVs online'), (string) $stats['online'], admin_url('rooms.php', ['status' => 'online'])],
    ['offline', 'bi-wifi-off', 'bg-soft-danger', __('TVs offline'), (string) $stats['offline'], admin_url('rooms.php', ['status' => 'offline'])],
    ['never_registered', 'bi-plug', 'bg-soft-secondary', __('Rooms without TV'), (string) $stats['never_registered'], admin_url('rooms.php', ['status' => 'none'])],
    ['emergencies', 'bi-exclamation-triangle', $stats['emergencies'] ? 'bg-soft-danger' : 'bg-soft-warning', __('Active emergency'), $stats['emergencies'] ? __('YES') : __('None'), admin_url('broadcast.php') . '#emergency'],
    ['last_update_ago', 'bi-clock-history', 'bg-soft-info', __('Last content update'), $stats['last_update_ago'], admin_url('content.php')],
];
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Dashboard')) ?></h1>
    <p class="lead-sm"><?= e(__('Welcome, :name! Here is what your TVs are doing right now.', ['name' => $user['full_name'] ?: $user['username']])) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (Auth::can('broadcast.send')): ?>
      <a href="<?= e(admin_url('broadcast.php')) ?>" class="btn btn-primary"><i class="bi bi-broadcast-pin"></i> <?= e(__('Broadcast to all')) ?></a>
      <button type="button" class="btn btn-outline-primary" id="btnRefreshAll"><i class="bi bi-arrow-clockwise"></i> <?= e(__('Refresh all TVs')) ?></button>
    <?php endif; ?>
    <?php if (Auth::can('broadcast.emergency')): ?>
      <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#emergencyModal"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(__('Emergency message')) ?></button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php foreach ($cards as [$key, $icon, $cls, $label, $value, $href]): ?>
  <div class="col-6 col-md-4 col-xxl-2">
    <a href="<?= e($href) ?>" class="card text-decoration-none text-body h-100">
      <div class="stat-card">
        <div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div>
        <div class="min-w-0">
          <div class="stat-value" data-stat="<?= e($key) ?>"><?= e($value) ?></div>
          <div class="stat-label"><?= e($label) ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($emergencies): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2">
  <i class="bi bi-exclamation-triangle-fill fs-4"></i>
  <div class="flex-grow-1">
    <?php foreach ($emergencies as $em): ?>
      <div><strong><?= e($em['title']) ?></strong> — <?= e(Broadcaster::describeTarget($em['target_type'], $em['target_ids'])) ?> · <?= e(time_ago($em['start_at'])) ?></div>
    <?php endforeach; ?>
  </div>
  <?php if (Auth::can('broadcast.emergency')): ?>
    <button class="btn btn-danger js-emergency-stop" data-id="0"><i class="bi bi-stop-circle"></i> <?= e(__('Stop emergency')) ?></button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card h-100">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-grid-3x3-gap"></i> <?= e(__('Live room status')) ?></span>
        <span class="small text-muted ms-auto">
          <span class="legend-dot" style="background:#16a34a"></span><?= e(__('Online')) ?>
          <span class="legend-dot ms-2" style="background:#dc2626"></span><?= e(__('Offline')) ?>
          <span class="legend-dot ms-2" style="background:#9ca3af"></span><?= e(__('No TV')) ?>
          <span class="legend-dot ms-2" style="background:#1e293b"></span><?= e(__('Screen off')) ?>
        </span>
      </div>
      <div class="card-body">
        <?php if (!$roomStatus): ?>
          <div class="hc-empty">
            <i class="bi bi-tv"></i>
            <p class="mb-2"><strong><?= e(__('No rooms yet')) ?></strong></p>
            <p class="text-muted"><?= e(__('Add rooms, or simply set up the TV app — TVs register their room automatically.')) ?></p>
            <?php if (Auth::can('rooms.manage')): ?><a class="btn btn-primary" href="<?= e(admin_url('rooms.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add rooms')) ?></a><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="room-grid" id="roomGrid"></div>
          <div class="small text-muted mt-2"><i class="bi bi-arrow-repeat"></i> <?= e(__('Updates automatically every 10 seconds.')) ?> <span id="gridUpdated"></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-activity"></i> <?= e(__('Recent activity')) ?></span>
        <?php if (Auth::can('logs.view')): ?><a class="ms-auto small" href="<?= e(admin_url('logs.php', ['tab' => 'activity'])) ?>"><?= e(__('View all')) ?></a><?php endif; ?>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$activity): ?>
          <li class="list-group-item text-muted"><?= e(__('No activity yet.')) ?></li>
        <?php endif; ?>
        <?php foreach ($activity as $a): ?>
          <li class="list-group-item small">
            <div class="d-flex justify-content-between gap-2">
              <strong><?= e($a['username'] ?: __('system')) ?></strong>
              <span class="text-muted text-nowrap"><?= e(time_ago($a['created_at'])) ?></span>
            </div>
            <div><span class="badge text-bg-light border"><?= e($a['action']) ?></span> <?= e($a['details']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php
// Dashboard widgets from modules: admin/partials/dashboard.d/*.php (each prints one Bootstrap column
// inside this row; check your own permission first).
$widgets = glob(__DIR__ . '/partials/dashboard.d/*.php') ?: [];
sort($widgets);
if ($widgets): ?>
<div class="row g-3 mt-0">
  <?php foreach ($widgets as $widget) { require $widget; } ?>
</div>
<?php endif; ?>

<?php if (Auth::can('broadcast.emergency')): ?>
<div class="modal fade" id="emergencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="emergencyForm">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(__('Emergency message')) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small"><?= e(__('The message appears full screen on the selected TVs immediately, above all other content, until you stop it.')) ?></p>
        <div class="mb-3">
          <label class="form-label" for="emTitle"><?= e(__('Title')) ?></label>
          <input type="text" class="form-control" id="emTitle" name="title" maxlength="190" value="<?= e(__('Attention')) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="emMessage"><?= e(__('Message')) ?></label>
          <textarea class="form-control" id="emMessage" name="message" rows="3" maxlength="1000" placeholder="<?= e(__('e.g. Please evacuate the building using the nearest staircase.')) ?>"></textarea>
        </div>
        <label class="form-label"><?= e(__('Show on')) ?></label>
        <?= target_picker('dem') ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-megaphone-fill"></i> <?= e(__('Show emergency message now')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const grid = document.getElementById('roomGrid');
  const previewBase = <?= json_embed(admin_url('preview.php')) ?>;
  const roomsBase = <?= json_embed(admin_url('rooms.php')) ?>;
  const fmt = (r) => {
    const cls = 'room-tile st-' + r.status + (r.mode === 'off' || r.standby ? ' mode-off' : '') + (r.mode === 'emergency' ? ' mode-emergency' : '');
    const icon = r.status === 'online' ? 'bi-wifi' : (r.status === 'offline' ? 'bi-wifi-off' : 'bi-dash-circle');
    return '<a class="' + cls + '" href="' + previewBase + '?room_id=' + r.id + '" target="_blank" rel="noopener" title="' + HC.esc(r.status_label + ' · ' + r.mode_label + ' · ' + r.last_seen) + '">'
      + '<i class="bi ' + icon + ' rt-badge"></i>'
      + '<span class="rt-num">' + HC.esc(r.number) + '</span>'
      + '<span class="rt-show">' + HC.esc(r.showing) + '</span>'
      + '<span class="rt-meta">' + HC.esc(r.status === 'none' ? r.status_label : r.last_seen) + '</span></a>';
  };
  if (grid) {
    HC.every(10000, async () => {
      const d = await HC.api('room_status');
      grid.innerHTML = d.rooms.map(fmt).join('');
      const s = d.stats;
      const set = (k, v) => { const el = document.querySelector('[data-stat="' + k + '"]'); if (el) el.textContent = v; };
      set('rooms', s.rooms); set('online', s.online); set('offline', s.offline); set('never_registered', s.never_registered);
      set('last_update_ago', s.last_update_ago);
      document.getElementById('gridUpdated').textContent = '· ' + new Date().toLocaleTimeString();
    });
  }

  const refreshBtn = document.getElementById('btnRefreshAll');
  if (refreshBtn) refreshBtn.addEventListener('click', async () => {
    if (!(await HC.confirm(<?= json_embed(__('Tell every TV to reload its content now?')) ?>, { danger: false }))) return;
    try {
      const r = await HC.api('send_command', { data: { command: 'SHOW_CONTENT', target_type: 'all', target_ids: [] } });
      HC.toast(<?= json_embed(__('Refresh sent to :n TVs.')) ?>.replace(':n', r.devices));
    } catch (e) { HC.toast(e.message, 'danger'); }
  });

  const emForm = document.getElementById('emergencyForm');
  if (emForm) emForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const t = HC.targetFrom(emForm);
    const btn = emForm.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      await HC.api('emergency_start', { data: Object.assign({ title: emForm.querySelector('[name=title]').value, message: emForm.querySelector('[name=message]').value }, t) });
      window.location.reload();
    } catch (e) { HC.toast(e.message, 'danger'); btn.disabled = false; }
  });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
