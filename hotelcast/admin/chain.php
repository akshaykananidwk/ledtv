<?php
declare(strict_types=1);
/**
 * Hotel chain dashboard (#20): every hotel of the chain in one view (TVs online / offline, rooms,
 * occupancy, today's plays, open orders / requests, feedback, plan / expiry / invoices), totals,
 * sortable table + cards, "Enter hotel", and a date-range comparison report with charts + CSV.
 *   chain.php?chain=ID[&tab=report&from=Y-m-d&to=Y-m-d&compare=1&hotels[]=…&csv=1]
 * Access: chain admins, hotel super admins with chain access, platform admins, resellers (own chains).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

[$user, $chain] = Chains::page();
$cid = (int) $chain['id'];
$cq = ['chain' => $cid];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'enter') {
        $hid = Chains::assertHotels($cid, [req_int('hotel_id', $_POST)])[0] ?? 0;
        if (!$hid) {
            redirect(admin_url('chain.php', $cq));
        }
        if ($user['role'] !== 'chain_admin' && !Auth::isPlatformUser() && (int) $user['hotel_id'] === $hid) {
            Auth::leaveHotel(); // a super admin "entering" their own hotel just goes home
            redirect(admin_url('index.php'));
        }
        if (!Auth::enterHotel($hid)) {
            Chains::deny("enter hotel $hid");
        }
        $_SESSION['hc_back'] = 'chain.php';
        redirect(admin_url('index.php'));
    }
    redirect(admin_url('chain.php', $cq));
}

$tab = req_str('tab', $_GET, 10) === 'report' ? 'report' : 'overview';
$myChains = count(Chains::userChainIds()) > 1 ? Chains::all(Chains::userChainIds()) : [];
$stateLabel = static fn (string $s) => Hotels::statusBadge($s);

// ---------------------------------------------------------------- report data (+ CSV)
if ($tab === 'report') {
    [$from, $to] = Analytics::range($_GET['from'] ?? null, $_GET['to'] ?? null);
    $allHotels = Chains::hotels($cid);
    $sel = isset($_GET['hotels']) ? int_ids($_GET['hotels']) : array_map(static fn ($h) => (int) $h['id'], $allHotels);
    $sel = Chains::assertHotels($cid, $sel);
    $compare = !empty($_GET['compare']);
    $rep = Chains::report($cid, $from, $to, $sel);
    $prev = null;
    if ($compare) {
        [$pFrom, $pTo] = Chains::previousRange($from, $to);
        $p = Chains::report($cid, $pFrom, $pTo, $sel);
        $prev = ['from' => $pFrom, 'to' => $pTo, 'totals' => $p['totals'], 'hotels' => array_column($p['hotels'], null, 'id')];
    }
    $cols = [
        'plays' => __('Plays'), 'seconds' => __('Screen time (seconds)'), 'uptime' => __('Uptime %'), 'hours_on' => __('Hours ON'), 'kwh' => 'kWh',
        'orders' => __('Orders'), 'revenue' => __('Order revenue'), 'requests' => __('Requests'), 'feedback_avg' => __('Feedback (avg)'),
        'feedback_count' => __('Feedback count'), 'ad_impressions' => __('Ad impressions'), 'occupancy' => __('Occupancy %'),
    ];
    if (!empty($_GET['csv'])) {
        $head = array_merge([__('Hotel')], array_values($cols));
        $lines = [];
        foreach (array_merge($rep['hotels'], [$rep['totals']]) as $r) {
            $line = [$r['name']];
            foreach (array_keys($cols) as $k) {
                $line[] = $r[$k] ?? '';
            }
            $lines[] = $line;
        }
        if ($prev) {
            $lines[] = [];
            $line = [__('Previous period') . ' ' . $prev['from'] . ' – ' . $prev['to']];
            foreach (array_keys($cols) as $k) {
                $line[] = $prev['totals'][$k] ?? '';
            }
            $lines[] = $line;
        }
        csv_download('chain-' . $cid . '-' . $from . '_' . $to . '.csv', $head, $lines);
    }
} else {
    $ov = Chains::overview($cid);
    $emerg = Chains::activeEmergencies($cid);
}

$pageTitle = __('Chain dashboard');
$activeNav = 'chain';
$extraScripts = ['vendor/chartjs/chart.umd.min.js', 'js/analytics.js', 'js/chain.js'];
require __DIR__ . '/partials/header.php';

$num = static fn ($v, int $dec = 0) => $v === null ? '—' : number_format((float) $v, $dec);
$delta = static function ($cur, $old): string {
    if ($cur === null || $old === null || (float) $old == 0.0) {
        return '';
    }
    $pct = round(100 * ((float) $cur - (float) $old) / abs((float) $old), 1);
    $cls = $pct >= 0 ? 'text-success' : 'text-danger';
    return ' <small class="' . $cls . ' text-nowrap">' . ($pct >= 0 ? '▲' : '▼') . ' ' . e((string) abs($pct)) . '%</small>';
};
?>
<div class="page-head">
  <div class="min-w-0">
    <h1 class="text-truncate"><i class="bi bi-diagram-3"></i> <?= e($chain['name']) ?></h1>
    <p class="lead-sm mb-0"><?= e(trim(__('Hotel chain') . ' · ' . ($chain['owner_name'] ?? '') . ($chain['owner_phone'] ? ' · ' . $chain['owner_phone'] : ''), ' ·')) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($myChains): ?>
      <form method="get" class="m-0"><select class="form-select form-select-sm" name="chain" aria-label="<?= e(__('Hotel chain')) ?>" onchange="this.form.submit()">
        <?php foreach ($myChains as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $cid ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></form>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('chain_content.php', $cq)) ?>"><i class="bi bi-collection-play"></i> <?= e(__('Chain content')) ?></a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('chain_broadcast.php', $cq)) ?>"><i class="bi bi-broadcast"></i> <?= e(__('Broadcast')) ?></a>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'overview' ? ' active' : '' ?>" href="<?= e(admin_url('chain.php', $cq)) ?>"><i class="bi bi-grid"></i> <?= e(__('Overview')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'report' ? ' active' : '' ?>" href="<?= e(admin_url('chain.php', $cq + ['tab' => 'report'])) ?>"><i class="bi bi-bar-chart"></i> <?= e(__('Comparison report')) ?></a></li>
</ul>

<?php if ($tab === 'overview'): $t = $ov['totals']; ?>
<div class="row g-3 mb-3" id="chainTotals">
  <?php foreach ([
      ['bi-buildings', 'bg-soft-primary', __('Hotels'), (string) $t['hotels'] . ($t['not_active'] ? ' (' . $t['not_active'] . ' ' . __('paused') . ')' : '')],
      ['bi-wifi', 'bg-soft-success', __('TVs online'), $t['online'] . ' / ' . $t['tvs']],
      ['bi-door-closed', 'bg-soft-secondary', __('Rooms'), (string) $t['rooms']],
      ['bi-person-check', 'bg-soft-info', __('Occupancy'), $t['occupancy'] === null ? '—' : $t['occupancy'] . '%'],
      ['bi-play-circle', 'bg-soft-primary', __('Plays today'), number_format($t['plays_today'])],
      ['bi-bell', 'bg-soft-warning', __('Open orders / requests'), $t['open_orders'] . ' / ' . $t['open_requests']],
      ['bi-star-half', 'bg-soft-success', __('Feedback (30 days)'), $t['feedback_avg'] === null ? '—' : $t['feedback_avg'] . ' ★'],
      ['bi-receipt', $t['overdue_invoices'] ? 'bg-soft-danger' : 'bg-soft-secondary', __('Unpaid invoices'), $t['unpaid_invoices'] . ($t['overdue_invoices'] ? ' (' . $t['overdue_invoices'] . ' ' . __('overdue') . ')' : '')],
  ] as [$icon, $cls, $label, $val]): ?>
    <div class="col-6 col-md-4 col-xl-3"><div class="card h-100"><div class="stat-card"><div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div><div class="min-w-0"><div class="stat-value text-truncate"><?= e($val) ?></div><div class="stat-label"><?= e($label) ?></div></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if (!$ov['hotels']): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-buildings"></i><p class="mb-1"><strong><?= e(__('No hotels in this chain yet.')) ?></strong></p><p class="small text-muted"><?= e(__('The platform or your reseller adds hotels to the chain.')) ?></p></div></div>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <input type="search" class="form-control form-control-sm" style="max-width:16rem" placeholder="<?= e(__('Search hotel…')) ?>" data-chain-filter aria-label="<?= e(__('Search hotel…')) ?>">
  <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="<?= e(__('View')) ?>">
    <button type="button" class="btn btn-outline-secondary" data-chain-view="table"><i class="bi bi-table"></i> <?= e(__('Table')) ?></button>
    <button type="button" class="btn btn-outline-secondary" data-chain-view="cards"><i class="bi bi-grid-3x2-gap"></i> <?= e(__('Cards')) ?></button>
  </div>
</div>

<?php $enterBtn = static function (array $h, string $cls = 'btn-sm btn-primary') use ($cid): string {
    return '<form method="post" class="d-inline m-0">' . Csrf::field() . '<input type="hidden" name="op" value="enter"><input type="hidden" name="chain" value="' . $cid . '">'
        . '<input type="hidden" name="hotel_id" value="' . (int) $h['id'] . '"><button class="btn ' . e($cls) . '" title="' . e(__('Enter hotel')) . '"><i class="bi bi-box-arrow-in-right"></i> <span class="d-none d-sm-inline">' . e(__('Enter')) . '</span></button></form>';
}; ?>

<div class="card" data-chain-panel="table">
  <div class="table-responsive">
    <table class="table table-hc table-hover align-middle mb-0" data-sortable>
      <thead><tr>
        <th data-sort="text"><?= e(__('Hotel')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('TVs online')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Offline')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Rooms')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Occupancy')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Plays today')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Open orders')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Open requests')) ?></th>
        <th data-sort="num" class="text-end"><?= e(__('Feedback')) ?></th>
        <th data-sort="text"><?= e(__('Plan / expiry')) ?></th>
        <th data-sort="num"><?= e(__('Invoices')) ?></th>
        <th class="text-end"><?= e(__('Actions')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($ov['hotels'] as $h): ?>
        <tr data-hotel-row data-name="<?= e(mb_strtolower($h['name'] . ' ' . $h['city'])) ?>">
          <td data-v="<?= e($h['name']) ?>"><strong><?= e($h['name']) ?></strong> <?= $h['state'] !== 'active' ? $stateLabel($h['state']) : '' ?>
            <?= !empty($emerg[$h['id']]) ? '<span class="badge text-bg-danger">' . e(__('Emergency')) . '</span>' : '' ?>
            <div class="small text-muted"><?= e($h['city']) ?></div></td>
          <td class="text-end" data-v="<?= (int) $h['online'] ?>"><span class="text-success fw-semibold" data-h="<?= (int) $h['id'] ?>" data-k="online"><?= (int) $h['online'] ?></span> / <?= (int) $h['tvs'] ?></td>
          <td class="text-end" data-v="<?= (int) $h['offline'] ?>"><span class="<?= $h['offline'] ? 'text-danger fw-semibold' : 'text-muted' ?>" data-h="<?= (int) $h['id'] ?>" data-k="offline"><?= (int) $h['offline'] ?></span></td>
          <td class="text-end" data-v="<?= (int) $h['rooms'] ?>"><?= (int) $h['rooms'] ?></td>
          <td class="text-end" data-v="<?= e((string) ($h['occupancy'] ?? -1)) ?>"><?= $h['occupancy'] === null ? '—' : e((string) $h['occupancy']) . '%' ?></td>
          <td class="text-end" data-v="<?= (int) $h['plays_today'] ?>"><?= e(number_format($h['plays_today'])) ?></td>
          <td class="text-end" data-v="<?= (int) $h['open_orders'] ?>"><?= (int) $h['open_orders'] ?></td>
          <td class="text-end" data-v="<?= (int) $h['open_requests'] ?>"><?= (int) $h['open_requests'] ?></td>
          <td class="text-end" data-v="<?= e((string) ($h['feedback_avg'] ?? -1)) ?>"><?= $h['feedback_avg'] === null ? '—' : e((string) $h['feedback_avg']) . ' ★' ?><div class="small text-muted"><?= (int) $h['feedback_count'] ?></div></td>
          <td class="small" data-v="<?= e((string) ($h['expires_at'] ?? '9999')) ?>"><?= e($h['plan'] ?: __('No plan')) ?>
            <div class="<?= $h['days_left'] !== null && $h['days_left'] < 15 ? 'text-danger' : 'text-muted' ?>"><?= $h['expires_at'] ? e(__('until :d', ['d' => date('d M Y', (int) strtotime((string) $h['expires_at']))])) : e(__('No expiry')) ?></div></td>
          <td data-v="<?= (int) $h['unpaid_invoices'] * 10 + (int) $h['overdue_invoices'] ?>"><?= match ($h['invoice_status']) {
              'overdue' => '<span class="badge text-bg-danger">' . e(__(':n overdue', ['n' => $h['overdue_invoices']])) . '</span>',
              'unpaid' => '<span class="badge text-bg-warning">' . e(__(':n unpaid', ['n' => $h['unpaid_invoices']])) . '</span>',
              default => '<span class="badge text-bg-success">' . e(__('Paid')) . '</span>',
          } ?></td>
          <td class="text-end text-nowrap"><?= $enterBtn($h) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3" data-chain-panel="cards" hidden>
  <?php foreach ($ov['hotels'] as $h): ?>
    <div class="col-sm-6 col-xl-4" data-hotel-row data-name="<?= e(mb_strtolower($h['name'] . ' ' . $h['city'])) ?>">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="flex-grow-1 min-w-0"><h2 class="h6 mb-0 text-truncate"><?= e($h['name']) ?></h2><div class="small text-muted"><?= e($h['city']) ?> · <?= e($h['plan'] ?: __('No plan')) ?></div></div>
          <?= $stateLabel($h['state']) ?>
        </div>
        <div class="row g-2 small text-center mb-2">
          <div class="col-4"><div class="fw-bold fs-5 text-success" data-h="<?= (int) $h['id'] ?>" data-k="online"><?= (int) $h['online'] ?></div><?= e(__('online')) ?></div>
          <div class="col-4"><div class="fw-bold fs-5 <?= $h['offline'] ? 'text-danger' : '' ?>" data-h="<?= (int) $h['id'] ?>" data-k="offline"><?= (int) $h['offline'] ?></div><?= e(__('offline')) ?></div>
          <div class="col-4"><div class="fw-bold fs-5"><?= (int) $h['rooms'] ?></div><?= e(__('Rooms')) ?></div>
          <div class="col-4"><div class="fw-bold"><?= $h['occupancy'] === null ? '—' : e((string) $h['occupancy']) . '%' ?></div><?= e(__('Occupancy')) ?></div>
          <div class="col-4"><div class="fw-bold"><?= e(number_format($h['plays_today'])) ?></div><?= e(__('Plays today')) ?></div>
          <div class="col-4"><div class="fw-bold"><?= $h['feedback_avg'] === null ? '—' : e((string) $h['feedback_avg']) . ' ★' ?></div><?= e(__('Feedback')) ?></div>
        </div>
        <div class="d-flex flex-wrap gap-1 small mb-2">
          <span class="badge text-bg-light border"><?= e(__('Open orders')) ?>: <?= (int) $h['open_orders'] ?></span>
          <span class="badge text-bg-light border"><?= e(__('Open requests')) ?>: <?= (int) $h['open_requests'] ?></span>
          <?php if ($h['unpaid_invoices']): ?><span class="badge <?= $h['overdue_invoices'] ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= e(__(':n unpaid', ['n' => $h['unpaid_invoices']])) ?></span><?php endif; ?>
          <?php if (!empty($emerg[$h['id']])): ?><span class="badge text-bg-danger"><?= e(__('Emergency')) ?></span><?php endif; ?>
          <?php if ($h['expires_at']): ?><span class="badge text-bg-light border <?= $h['days_left'] !== null && $h['days_left'] < 15 ? 'text-danger' : '' ?>"><?= e(__('until :d', ['d' => date('d M Y', (int) strtotime((string) $h['expires_at']))])) ?></span><?php endif; ?>
        </div>
        <?= $enterBtn($h, 'btn-sm btn-primary w-100') ?>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>
<script type="application/json" id="chainCfg"><?= json_embed(['chain' => $cid, 'refresh' => 60]) ?></script>
<?php endif; ?>

<?php else: /* ---------------------------------------------------------------- report */ ?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="chain" value="<?= $cid ?>"><input type="hidden" name="tab" value="report">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="rf"><?= e(__('From')) ?></label><input class="form-control form-control-sm" type="date" id="rf" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="rt"><?= e(__('To')) ?></label><input class="form-control form-control-sm" type="date" id="rt" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="compare" value="1" id="rc"<?= $compare ? ' checked' : '' ?>><label class="form-check-label small" for="rc"><?= e(__('Compare with previous period')) ?></label></div></div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel"></i> <?= e(__('Show')) ?></button>
      <a class="btn btn-sm btn-light border" href="<?= e(admin_url('chain.php', $_GET + ['csv' => 1])) ?>"><i class="bi bi-download"></i> CSV</a>
    </div>
    <div class="col-12">
      <details<?= isset($_GET['hotels']) ? ' open' : '' ?>><summary class="small"><?= e(__('Hotels')) ?> (<?= count($sel) ?>/<?= count($allHotels) ?>)</summary>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php foreach ($allHotels as $h): ?>
            <label class="form-check-label small border rounded px-2 py-1"><input class="form-check-input me-1" type="checkbox" name="hotels[]" value="<?= (int) $h['id'] ?>"<?= in_array((int) $h['id'], $sel, true) ? ' checked' : '' ?>><?= e($h['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
  </div>
</form>

<?php
$names = array_column($rep['hotels'], 'name');
$charts = [
    'chPlays' => ['type' => 'bar', 'labels' => $names, 'datasets' => array_values(array_filter([
        ['label' => __('Plays') . ' ' . $from . ' – ' . $to, 'data' => array_column($rep['hotels'], 'plays')],
        $prev ? ['label' => __('Previous period'), 'data' => array_map(static fn ($h) => $prev['hotels'][$h['id']]['plays'] ?? 0, $rep['hotels']), 'color' => '#94a3b8'] : null,
    ]))],
    'chUptime' => ['type' => 'bar', 'labels' => $names, 'unit' => '%', 'max' => 100, 'datasets' => [['label' => __('Uptime %'), 'data' => array_map(static fn ($h) => $h['uptime'] ?? 0, $rep['hotels']), 'color' => '#16a34a']]],
    'chDays' => ['type' => 'line', 'labels' => $rep['days'], 'datasets' => array_values(array_map(static fn ($s) => ['label' => $s['name'], 'data' => $s['plays']], $rep['series']))],
    'chEnergy' => ['type' => 'bar', 'labels' => $names, 'datasets' => [['label' => __('Hours ON'), 'data' => array_column($rep['hotels'], 'hours_on')], ['label' => 'kWh', 'data' => array_column($rep['hotels'], 'kwh'), 'type' => 'line', 'axis' => 'y2']]],
];
?>
<div class="row g-3 mb-3">
  <?php foreach (['chPlays' => __('Plays per hotel'), 'chUptime' => __('TV uptime per hotel'), 'chDays' => __('Plays per day'), 'chEnergy' => __('Hours ON & electricity')] as $id => $label): ?>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e($label) ?></div><div class="card-body"><div style="height:260px"><canvas data-chart="<?= e($id) ?>" aria-label="<?= e($label) ?>"></canvas></div></div></div>
      <script type="application/json" id="<?= e($id) ?>"><?= json_embed($charts[$id]) ?></script></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap gap-2"><span><?= e(__('Comparison')) ?> · <?= e($from) ?> – <?= e($to) ?></span>
    <?php if ($prev): ?><span class="small text-muted ms-auto"><?= e(__('Change vs. :a – :b', ['a' => $prev['from'], 'b' => $prev['to']])) ?></span><?php endif; ?></div>
  <div class="table-responsive">
    <table class="table table-sm table-hc table-hover mb-0" data-sortable>
      <thead><tr><th data-sort="text"><?= e(__('Hotel')) ?></th>
        <?php foreach ($cols as $k => $label): if ($k === 'seconds' || $k === 'feedback_count') continue; ?><th data-sort="num" class="text-end"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rep['hotels'] as $r): $o = $prev['hotels'][$r['id']] ?? null; ?>
        <tr><td data-v="<?= e($r['name']) ?>"><?= e($r['name']) ?></td>
          <?php foreach ($cols as $k => $label): if ($k === 'seconds' || $k === 'feedback_count') continue; ?>
            <td class="text-end" data-v="<?= e((string) ($r[$k] ?? -1)) ?>"><?= e($num($r[$k] ?? null, in_array($k, ['uptime', 'hours_on', 'occupancy'], true) ? 1 : (in_array($k, ['kwh', 'revenue', 'feedback_avg'], true) ? 2 : 0))) ?><?= $o ? $delta($r[$k] ?? null, $o[$k] ?? null) : '' ?></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$rep['hotels']): ?><tr><td colspan="<?= count($cols) ?>" class="text-center text-muted py-3"><?= e(__('No data.')) ?></td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr class="fw-bold"><td><?= e(__('Total')) ?></td>
        <?php foreach ($cols as $k => $label): if ($k === 'seconds' || $k === 'feedback_count') continue; ?>
          <td class="text-end"><?= e($num($rep['totals'][$k] ?? null, in_array($k, ['uptime', 'hours_on', 'occupancy'], true) ? 1 : (in_array($k, ['kwh', 'revenue', 'feedback_avg'], true) ? 2 : 0))) ?><?= $prev ? $delta($rep['totals'][$k] ?? null, $prev['totals'][$k] ?? null) : '' ?></td>
        <?php endforeach; ?></tr></tfoot>
    </table>
  </div>
  <div class="card-body small text-muted"><?= e(__('Uptime and hours ON come from the TV status and heartbeat of each hotel; electricity uses each hotel\'s TV wattage. Orders, requests and feedback need the guest services module.')) ?></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
