<?php
declare(strict_types=1);
/**
 * Analytics (#17): plays & screen time per content / room / day, TV uptime and hours ON, estimated
 * electricity, ad impressions, occupancy and guest services (when the guests module is installed).
 * Date range filter, charts (Chart.js) and CSV export of every table. Manager+.
 *   analytics.php?from=Y-m-d&to=Y-m-d[&csv=days|content|rooms|tvs|occupancy|requests]
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('analytics.view');
$pageTitle = __('Analytics');
$activeNav = 'analytics';
if (!Tenant::feature('analytics')) {
    http_response_code(403);
    require __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">' . e(__('Analytics is not included in your plan.')) . '</div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}
Csrf::check();

if (is_post()) {
    if (req_str('op', $_POST, 20) === 'watts') {
        $w = req_int('tv_watts', $_POST);
        if ($w < 1 || $w > 2000) {
            flash('danger', __('Enter the TV power in watts (1–2000).'));
        } else {
            Settings::set('tv_watts', (string) $w);
            ActivityLog::add('settings_update', 'settings', null, 'tv_watts=' . $w);
            flash('success', __('Saved.'));
        }
    }
    redirect(admin_url('analytics.php', array_filter(['from' => req_str('from', $_POST, 10), 'to' => req_str('to', $_POST, 10)])));
}

[$from, $to] = Analytics::range($_GET['from'] ?? null, $_GET['to'] ?? null);
$days = Analytics::byDay($from, $to);
$content = Analytics::byContent($from, $to);
// Users limited to some TVs (core/Access.php): per-room / per-TV tables show only their TVs
// (hotel-wide totals per day / per content stay hotel-wide).
$rooms = Access::filterRooms(Analytics::byRoom($from, $to), 'room_id');
$tvs = Analytics::byTv($from, $to);
if (Access::restricted()) {
    $tvs = array_values(array_filter($tvs, static fn ($t) => Access::canDevice((int) $t['device_id'])));
}
$tvSum = Analytics::tvSummary($tvs);
$tvRows = Analytics::publicTvRows($tvs);
$occ = Analytics::occupancy($from, $to);
$gs = Analytics::guestServices($from, $to);
$watts = Analytics::watts();

$csv = (string) ($_GET['csv'] ?? '');
if ($csv !== '') {
    $base = 'analytics-' . $from . '_' . $to;
    switch ($csv) {
        case 'content':
            csv_download($base . '-content.csv', [__('Content'), __('Type'), __('Plays'), __('Screen time (seconds)'), __('Screens'), __('Of which ads')],
                array_map(fn ($r) => [$r['title'], $r['type'], $r['plays'], $r['seconds'], $r['rooms'], $r['ad_plays']], $content));
        case 'rooms':
            csv_download($base . '-rooms.csv', [__('Screen'), __('Floor'), __('Plays'), __('Screen time (seconds)'), __('Different items')],
                array_map(fn ($r) => [$r['room'], $r['floor'], $r['plays'], $r['seconds'], $r['items']], $rooms));
        case 'tvs':
            csv_download($base . '-tvs.csv', [__('Screen'), __('Model'), __('Status'), __('Hours in period'), __('Hours online'), __('Uptime %'), __('Hours ON'), 'kWh', __('Method')],
                array_map(fn ($r) => [$r['room'], $r['model'], $r['status'], $r['period_hours'], $r['online_hours'], $r['uptime'], $r['hours_on'], $r['kwh'], $r['method']], $tvRows));
        case 'occupancy':
            csv_download($base . '-occupancy.csv', [__('Date'), __('Occupied rooms'), __('Screens'), __('Occupancy %')],
                array_map(fn ($r) => [$r['day'], $r['occupied'], $r['rooms'], $r['percent']], $occ['days'] ?? []));
        case 'requests':
            csv_download($base . '-requests.csv', [__('Request'), __('Count'), __('Done'), __('Avg. minutes to done')],
                array_map(fn ($r) => [$r['type'], $r['count'], $r['done'], $r['avg_done_min']], $gs['requests'] ?? []));
        default:
            csv_download($base . '-days.csv', [__('Date'), __('Plays'), __('Screen time (seconds)'), __('Ad impressions'), __('Screens')],
                array_map(fn ($r) => [$r['day'], $r['plays'], $r['seconds'], $r['ad_impressions'], $r['rooms']], $days));
    }
}

$totPlays = array_sum(array_column($days, 'plays'));
$totSecs = array_sum(array_column($days, 'seconds'));
$totAds = array_sum(array_column($days, 'ad_impressions'));
$q = ['from' => $from, 'to' => $to];
$csvLink = fn (string $t) => '<a class="btn btn-sm btn-light border ms-auto" href="' . e(admin_url('analytics.php', $q + ['csv' => $t])) . '"><i class="bi bi-download"></i> CSV</a>';
$extraScripts = ['vendor/chartjs/chart.umd.min.js', 'js/analytics.js'];
require __DIR__ . '/partials/header.php';

$short = count($days) > 31 ? 'd/m' : 'd M';
$chartDays = ['type' => 'bar', 'labels' => array_map(fn ($d) => date($short, (int) strtotime($d['day'])), $days), 'datasets' => [
    ['label' => __('Plays'), 'data' => array_column($days, 'plays')],
    ['label' => __('Ad impressions'), 'data' => array_column($days, 'ad_impressions'), 'color' => '#f59e0b'],
    ['label' => __('Screen time (hours)'), 'data' => array_map(fn ($d) => round($d['seconds'] / 3600, 2), $days), 'type' => 'line', 'axis' => 'y2', 'color' => '#16a34a'],
]];
$top = array_slice($content, 0, 10);
$chartTop = ['type' => 'bar', 'horizontal' => true, 'labels' => array_map(fn ($r) => mb_strimwidth($r['title'], 0, 34, '…'), $top), 'datasets' => [['label' => __('Plays'), 'data' => array_column($top, 'plays')]]];
$tvChartRows = array_slice($tvRows, 0, 60);
$chartTv = ['type' => 'bar', 'unit' => '%', 'max' => 100, 'labels' => array_map(fn ($r) => (string) $r['room'], $tvChartRows), 'datasets' => [['label' => __('Uptime %'), 'data' => array_map(fn ($r) => $r['uptime'] ?? 0, $tvChartRows), 'color' => '#0ea5e9']]];
$ranges = [
    __('Today') => [date('Y-m-d'), date('Y-m-d')],
    __('7 days') => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    __('30 days') => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    __('This month') => [date('Y-m-01'), date('Y-m-d')],
    __('Last month') => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Analytics')) ?></h1>
    <p class="lead-sm"><?= e(date('d M Y', (int) strtotime($from))) ?> – <?= e(date('d M Y', (int) strtotime($to))) ?> · <?= e(__(':n days', ['n' => count($days)])) ?></p>
  </div>
</div>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3 col-lg-2"><label class="form-label small" for="from"><?= e(__('From')) ?></label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3 col-lg-2"><label class="form-label small" for="to"><?= e(__('To')) ?></label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> <?= e(__('Show')) ?></button></div>
    <div class="col-12 col-lg d-flex flex-wrap gap-1">
      <?php foreach ($ranges as $label => [$a, $b]): ?>
        <a class="btn btn-sm <?= $a === $from && $b === $to ? 'btn-secondary' : 'btn-light border' ?>" href="<?= e(admin_url('analytics.php', ['from' => $a, 'to' => $b])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<style>.an-kpis .stat-value{font-size:1.3rem;white-space:normal;overflow-wrap:anywhere}.an-kpis .stat-label{overflow-wrap:normal}@media (max-width:575.98px){.an-kpis .stat-card{padding:.75rem}.an-kpis .stat-card .stat-icon{display:none}}</style>
<div class="row g-3 mb-3 an-kpis">
  <?php
  $kpis = [
      ['bi-play-circle', 'bg-soft-primary', number_format($totPlays), __('Content plays'), 'plays'],
      ['bi-clock-history', 'bg-soft-info', Analytics::hm($totSecs), __('Screen time'), 'screen'],
      ['bi-wifi', 'bg-soft-success', $tvSum['uptime'] !== null ? $tvSum['uptime'] . ' %' : '–', __('TV uptime'), 'uptime'],
      ['bi-lightning-charge', 'bg-soft-warning', number_format($tvSum['kwh'], 1) . ' kWh', __('Electricity (estimated)'), 'kwh'],
      ['bi-badge-ad', 'bg-soft-danger', number_format($totAds), __('Ad impressions'), 'ads'],
  ];
  if ($occ !== null) {
      $kpis[] = ['bi-door-closed', 'bg-soft-secondary', $occ['average'] . ' %', __('Average occupancy'), 'occupancy'];
  }
  foreach ($kpis as [$icon, $cls, $value, $label, $key]): ?>
  <div class="col-6 col-md-4 col-xxl-2"><div class="card h-100"><div class="stat-card">
    <div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div>
    <div class="min-w-0"><div class="stat-value" data-kpi="<?= e($key) ?>"><?= e($value) ?></div><div class="stat-label"><?= e($label) ?></div></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-calendar3"></i> <?= e(__('Per day')) ?></span><?= $csvLink('days') ?></div>
  <div class="card-body"><div style="height:300px"><canvas data-chart="chDays" aria-label="<?= e(__('Plays per day')) ?>"></canvas></div>
    <script type="application/json" id="chDays"><?= json_embed($chartDays) ?></script></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-trophy"></i> <?= e(__('Top content')) ?></div>
      <div class="card-body">
        <?php if ($top): ?><div style="height:<?= 60 + 28 * count($top) ?>px"><canvas data-chart="chTop" aria-label="<?= e(__('Top content')) ?>"></canvas></div>
          <script type="application/json" id="chTop"><?= json_embed($chartTop) ?></script>
        <?php else: ?><div class="text-muted small"><?= e(__('No plays reported in this period.')) ?></div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-images"></i> <?= e(__('Plays per content')) ?></span><?= $csvLink('content') ?></div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-hc table-sm table-hover mb-0">
        <thead><tr><th><?= e(__('Content')) ?></th><th class="text-end"><?= e(__('Plays')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th><th class="text-end d-none d-sm-table-cell"><?= e(__('Screens')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($content as $r): ?>
          <tr><td><?= e($r['title']) ?><?php if ($r['ad_plays']): ?> <span class="badge text-bg-warning" title="<?= e(__('Of which ads')) ?>"><?= e(__('Ad')) ?> <?= (int) $r['ad_plays'] ?></span><?php endif; ?></td>
            <td class="text-end"><?= number_format($r['plays']) ?></td><td class="text-end text-nowrap"><?= e(Analytics::hm($r['seconds'])) ?></td><td class="text-end d-none d-sm-table-cell"><?= (int) $r['rooms'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$content): ?><tr><td colspan="4" class="text-muted"><?= e(__('No plays reported in this period.')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2 flex-wrap"><span><i class="bi bi-tv"></i> <?= e(__('TVs: uptime, hours ON and electricity')) ?></span><?= $csvLink('tvs') ?></div>
  <div class="card-body">
    <?php if ($tvRows): ?>
      <div style="height:240px"><canvas data-chart="chTv" aria-label="<?= e(__('Uptime %')) ?>"></canvas></div>
      <script type="application/json" id="chTv"><?= json_embed($chartTv) ?></script>
    <?php endif; ?>
    <div class="row g-3 mt-1">
      <div class="col-lg-8">
        <div class="hint-box small"><i class="bi bi-info-circle"></i>
          <strong><?= e(__('How these numbers are calculated')) ?>:</strong>
          <?= e(__('Uptime = time the TV was online (from its online/offline history and last contact) ÷ time in the period since it was registered.')) ?>
          <?= e(__('Hours ON = minutes the TV was online with the screen on, sampled every 5 minutes from the TV heartbeat. TVs without samples use their online hours. Electricity = hours ON × TV power ÷ 1000.')) ?>
          <?= e(__('All values are estimates.')) ?>
        </div>
      </div>
      <div class="col-lg-4">
        <?php if (Auth::can('analytics.view')): ?>
        <form method="post" class="d-flex gap-2 align-items-end">
          <?= Csrf::field() ?><input type="hidden" name="op" value="watts"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
          <div class="flex-grow-1"><label class="form-label small" for="tv_watts"><?= e(__('TV power (watts)')) ?></label>
            <input class="form-control" type="number" id="tv_watts" name="tv_watts" min="1" max="2000" value="<?= (int) $watts ?>"></div>
          <button class="btn btn-light border"><?= e(__('Save')) ?></button>
        </form>
        <div class="form-text"><?= e(__('Typical LED TV: 32" ≈ 50 W, 43" ≈ 80 W, 55" ≈ 120 W.')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="table-responsive" style="max-height:460px"><table class="table table-hc table-sm table-hover mb-0">
    <thead><tr><th><?= e(__('Screen')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Model')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Uptime %')) ?></th>
      <th class="text-end d-none d-sm-table-cell"><?= e(__('Hours online')) ?></th><th class="text-end"><?= e(__('Hours ON')) ?></th><th class="text-end">kWh</th></tr></thead>
    <tbody>
    <?php foreach ($tvRows as $r): ?>
      <tr><td><?= e($r['room']) ?></td><td class="d-none d-md-table-cell small"><?= e($r['model']) ?></td><td><?= status_badge($r['status']) ?></td>
        <td class="text-end"><?= $r['uptime'] !== null ? e((string) $r['uptime']) : '–' ?></td><td class="text-end d-none d-sm-table-cell"><?= e((string) $r['online_hours']) ?></td>
        <td class="text-end"><?= e((string) $r['hours_on']) ?><?php if ($r['method'] === 'status'): ?> <span class="text-muted" title="<?= e(__('No screen samples yet — online hours used.')) ?>">*</span><?php endif; ?></td>
        <td class="text-end"><?= e(number_format($r['kwh'], 2)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$tvRows): ?><tr><td colspan="7" class="text-muted"><?= e(__('No TVs registered yet.')) ?></td></tr><?php endif; ?>
    </tbody>
    <?php if ($tvRows): ?><tfoot><tr class="fw-semibold"><td colspan="3"><?= e(__('Total')) ?> (<?= (int) $tvSum['tvs'] ?>)</td><td class="text-end"><?= $tvSum['uptime'] !== null ? e((string) $tvSum['uptime']) : '–' ?></td>
      <td class="d-none d-sm-table-cell"></td><td class="text-end"><?= e((string) $tvSum['hours_on']) ?></td><td class="text-end"><?= e(number_format($tvSum['kwh'], 2)) ?></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-door-open"></i> <?= e(__('Plays per screen')) ?></span><?= $csvLink('rooms') ?></div>
  <div class="table-responsive" style="max-height:420px"><table class="table table-hc table-sm table-hover mb-0">
    <thead><tr><th><?= e(__('Screen')) ?></th><th class="d-none d-sm-table-cell"><?= e(__('Floor')) ?></th><th class="text-end"><?= e(__('Plays')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th><th class="text-end d-none d-sm-table-cell"><?= e(__('Different items')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rooms as $r): ?>
      <tr><td><?= e($r['room']) ?></td><td class="d-none d-sm-table-cell"><?= e($r['floor']) ?></td><td class="text-end"><?= number_format($r['plays']) ?></td><td class="text-end text-nowrap"><?= e(Analytics::hm($r['seconds'])) ?></td><td class="text-end d-none d-sm-table-cell"><?= (int) $r['items'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rooms): ?><tr><td colspan="5" class="text-muted"><?= e(__('No screens yet.')) ?></td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php if ($occ !== null || $gs !== null): ?>
<div class="row g-3 mb-3">
  <?php if ($occ !== null):
      $chartOcc = ['type' => 'line', 'unit' => '%', 'max' => 100, 'labels' => array_map(fn ($d) => date($short, (int) strtotime($d['day'])), $occ['days']), 'datasets' => [['label' => __('Occupancy %'), 'data' => array_column($occ['days'], 'percent'), 'color' => '#9333ea']]]; ?>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-door-closed"></i> <?= e(__('Occupancy')) ?> · <?= e(__('average')) ?> <?= e((string) $occ['average']) ?> %</span><?= $csvLink('occupancy') ?></div>
      <div class="card-body"><div style="height:240px"><canvas data-chart="chOcc" aria-label="<?= e(__('Occupancy')) ?>"></canvas></div>
        <script type="application/json" id="chOcc"><?= json_embed($chartOcc) ?></script>
        <div class="form-text"><?= e(__('A room counts as occupied on a day when a guest stay covers 6 PM of that day.')) ?></div></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($gs !== null): ?>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-bell"></i> <?= e(__('Guest services')) ?></span><?php if ($gs['requests'] !== null): ?><?= $csvLink('requests') ?><?php endif; ?></div>
      <div class="card-body">
        <div class="row g-2 mb-3 text-center">
          <?php if ($gs['orders'] !== null): ?>
          <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold" data-kpi="orders"><?= (int) $gs['orders']['count'] ?></div><div class="small text-muted"><?= e(__('Room service orders')) ?></div></div></div>
          <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= $gs['orders']['avg_delivery_min'] !== null ? e((string) $gs['orders']['avg_delivery_min']) : '–' ?></div><div class="small text-muted"><?= e(__('Avg. delivery (min)')) ?></div></div></div>
          <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= e(preg_replace('/\.00$/', '', money($gs['orders']['revenue']))) ?></div><div class="small text-muted"><?= e(__('Order value')) ?></div></div></div>
          <?php endif; ?>
          <?php if ($gs['feedback'] !== null): ?>
          <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold" data-kpi="feedback"><?= $gs['feedback']['average'] !== null ? e((string) $gs['feedback']['average']) . ' ★' : '–' ?></div><div class="small text-muted"><?= e(__('Feedback (:n)', ['n' => $gs['feedback']['count']])) ?></div></div></div>
          <?php endif; ?>
        </div>
        <?php if ($gs['requests'] !== null): ?>
        <div class="table-responsive"><table class="table table-hc table-sm mb-0">
          <thead><tr><th><?= e(__('Request')) ?></th><th class="text-end"><?= e(__('Count')) ?></th><th class="text-end"><?= e(__('Done')) ?></th><th class="text-end"><?= e(__('Avg. minutes to done')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($gs['requests'] as $r): ?><tr><td><?= e($r['type']) ?></td><td class="text-end"><?= (int) $r['count'] ?></td><td class="text-end"><?= (int) $r['done'] ?></td><td class="text-end"><?= $r['avg_done_min'] !== null ? e((string) $r['avg_done_min']) : '–' ?></td></tr><?php endforeach; ?>
          <?php if (!$gs['requests']): ?><tr><td colspan="4" class="text-muted"><?= e(__('No requests in this period.')) ?></td></tr><?php endif; ?>
          </tbody></table></div>
        <?php endif; ?>
        <?php if ($gs['feedback'] !== null && $gs['feedback']['count'] > 0): ?>
          <div class="small text-muted mt-2"><?= e(__('Cleanliness')) ?>: <?= e((string) ($gs['feedback']['cleanliness'] ?? '–')) ?> · <?= e(__('Staff')) ?>: <?= e((string) ($gs['feedback']['staff'] ?? '–')) ?> · <?= e(__('Food')) ?>: <?= e((string) ($gs['feedback']['food'] ?? '–')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="small text-muted"><i class="bi bi-info-circle"></i> <?= e(__('Plays and screen time come from the TVs (reported after each item). Detailed logs are kept for :n days (Settings → log retention); ad reports are kept longer.', ['n' => max(7, Settings::int('log_retention_days', 90))])) ?></p>
<?php require __DIR__ . '/partials/footer.php'; ?>
