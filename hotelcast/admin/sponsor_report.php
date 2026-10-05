<?php
declare(strict_types=1);
/**
 * Sponsor report (#11): impressions, screen time, rooms reached and a per-day table for one sponsor
 * (all its campaigns) or one campaign. CSV export and a printable page for billing the sponsor.
 *   sponsor_report.php?sponsor_id=N | ?campaign_id=N  [&from=Y-m-d&to=Y-m-d] [&csv=day|room|campaign] [&print=1]
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('ads.manage');
$activeNav = 'ads';
if (!Ads::enabled()) {
    http_response_code(403);
    $pageTitle = __('Sponsor report');
    require __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">' . e(__('Advertising is not included in your plan.')) . '</div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$campaign = req_int('campaign_id', $_GET) ? Ads::findCampaign(req_int('campaign_id', $_GET)) : null;
$sponsor = $campaign ? Ads::findSponsor((int) $campaign['sponsor_id']) : (req_int('sponsor_id', $_GET) ? Ads::findSponsor(req_int('sponsor_id', $_GET)) : null);
if (!$sponsor) {
    flash('warning', __('Choose a sponsor or campaign.'));
    redirect(admin_url('ads.php', ['tab' => 'sponsors']));
}
$campaigns = $campaign ? [$campaign] : Ads::campaigns((int) $sponsor['id']);
$ids = array_map(fn ($c) => (int) $c['id'], $campaigns);

// Default period: from the campaign / contract start (max 1 year) until today or the end.
$defFrom = $campaign ? (string) $campaign['start_date'] : (string) ($sponsor['contract_start'] ?: date('Y-m-01'));
$defEnd = $campaign ? ($campaign['end_date'] ?: date('Y-m-d')) : ($sponsor['contract_end'] ?: date('Y-m-d'));
$defTo = min((string) $defEnd, date('Y-m-d'));
[$from, $to] = Analytics::range($_GET['from'] ?? $defFrom, $_GET['to'] ?? max($defFrom, $defTo));
$report = Ads::report($ids, $from, $to);
$subject = $campaign ? $campaign['name'] : $sponsor['name'];
$hotelName = (string) Settings::get('hotel_name', '');

$csv = (string) ($_GET['csv'] ?? '');
if ($csv !== '') {
    $base = 'sponsor-' . slugify((string) $subject, 'report') . '-' . $from . '_' . $to;
    match ($csv) {
        'room' => csv_download($base . '-rooms.csv', [__('Room'), __('Impressions'), __('Screen time (seconds)')],
            array_map(fn ($r) => [$r['room'], $r['impressions'], $r['seconds']], $report['per_room'])),
        'campaign' => csv_download($base . '-campaigns.csv', [__('Campaign'), __('Impressions'), __('Screen time (seconds)'), __('Rooms')],
            array_map(fn ($r) => [$r['name'], $r['impressions'], $r['seconds'], $r['rooms']], $report['per_campaign'])),
        default => csv_download($base . '-days.csv', [__('Date'), __('Impressions'), __('Screen time (seconds)'), __('Rooms')],
            array_map(fn ($r) => [$r['day'], $r['impressions'], $r['seconds'], $r['rooms']], $report['per_day'])),
    };
}

$t = $report['totals'];
$query = array_filter(['sponsor_id' => $campaign ? null : $sponsor['id'], 'campaign_id' => $campaign['id'] ?? null, 'from' => $from, 'to' => $to]);
$maxDay = max(1, ...array_map(fn ($d) => $d['impressions'], $report['per_day'] ?: [['impressions' => 0]]));

// ---------------------------------------------------------------- printable page
if (!empty($_GET['print'])) {
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="<?= e(I18n::lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e(__('Sponsor report')) ?> · <?= e($subject) ?></title>
<style>
body{font-family:"Noto Sans Gujarati","Noto Sans",Arial,sans-serif;color:#111;margin:0;background:#f3f4f6}
.sheet{max-width:860px;margin:20px auto;background:#fff;padding:36px 40px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:22px;margin:0}h2{font-size:15px;margin:26px 0 8px;border-bottom:2px solid #111;padding-bottom:4px}
.meta{display:flex;justify-content:space-between;gap:20px;font-size:13px;color:#374151;margin-top:6px;flex-wrap:wrap}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:20px}.kpi{border:1px solid #d1d5db;border-radius:8px;padding:10px 12px}
.kpi b{display:block;font-size:22px}.kpi span{font-size:12px;color:#4b5563}
table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{padding:5px 6px;border-bottom:1px solid #e5e7eb;text-align:left}th{background:#f9fafb}
td.r,th.r{text-align:right}.bar{height:9px;background:#4f46e5;border-radius:2px;min-width:1px}
.foot{margin-top:26px;font-size:11.5px;color:#6b7280}.tools{max-width:860px;margin:16px auto 0;display:flex;gap:8px}
.tools button,.tools a{font:inherit;font-size:14px;padding:7px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#111;text-decoration:none;cursor:pointer}
@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;padding:0}.tools{display:none}.bar{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@media (max-width:600px){.kpis{grid-template-columns:repeat(2,1fr)}.sheet{padding:20px}}
</style></head><body>
<div class="tools"><button onclick="window.print()"><?= e(__('Print / Save as PDF')) ?></button><a href="<?= e(admin_url('sponsor_report.php', $query)) ?>"><?= e(__('Back')) ?></a></div>
<div class="sheet">
  <h1><?= e(__('Advertising report')) ?> — <?= e($subject) ?></h1>
  <div class="meta">
    <div><strong><?= e($hotelName) ?></strong><br><?= e(__('Sponsor')) ?>: <?= e($sponsor['name']) ?><?= $sponsor['contact_name'] ? ' · ' . e($sponsor['contact_name']) : '' ?><?= $sponsor['phone'] ? ' · ' . e($sponsor['phone']) : '' ?></div>
    <div><?= e(__('Period')) ?>: <strong><?= e(date('d M Y', (int) strtotime($from))) ?> – <?= e(date('d M Y', (int) strtotime($to))) ?></strong><br><?= e(__('Generated')) ?>: <?= e(date('d M Y, h:i A')) ?></div>
  </div>
  <div class="kpis">
    <div class="kpi"><b><?= number_format($t['impressions']) ?></b><span><?= e(__('Impressions')) ?></span></div>
    <div class="kpi"><b><?= e(Ads::duration($t['seconds'])) ?></b><span><?= e(__('Total screen time')) ?></span></div>
    <div class="kpi"><b><?= (int) $t['rooms'] ?></b><span><?= e(__('Rooms reached')) ?></span></div>
    <div class="kpi"><b><?= (int) $t['days_active'] ?></b><span><?= e(__('Days on air')) ?></span></div>
  </div>
  <?php if (count($report['per_campaign']) > 1 || !$campaign): ?>
  <h2><?= e(__('Campaigns')) ?></h2>
  <table><thead><tr><th><?= e(__('Campaign')) ?></th><th class="r"><?= e(__('Impressions')) ?></th><th class="r"><?= e(__('Screen time')) ?></th><th class="r"><?= e(__('Rooms')) ?></th></tr></thead><tbody>
  <?php foreach ($report['per_campaign'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="r"><?= number_format($r['impressions']) ?></td><td class="r"><?= e(Ads::duration($r['seconds'])) ?></td><td class="r"><?= (int) $r['rooms'] ?></td></tr><?php endforeach; ?>
  <?php if (!$report['per_campaign']): ?><tr><td colspan="4"><?= e(__('No impressions in this period.')) ?></td></tr><?php endif; ?>
  </tbody></table>
  <?php endif; ?>
  <h2><?= e(__('Per day')) ?></h2>
  <table><thead><tr><th><?= e(__('Date')) ?></th><th class="r"><?= e(__('Impressions')) ?></th><th style="width:34%"></th><th class="r"><?= e(__('Screen time')) ?></th><th class="r"><?= e(__('Rooms')) ?></th></tr></thead><tbody>
  <?php foreach ($report['per_day'] as $d): ?>
    <tr><td><?= e(date('D d M Y', (int) strtotime($d['day']))) ?></td><td class="r"><?= number_format($d['impressions']) ?></td>
      <td><div class="bar" style="width:<?= round(100 * $d['impressions'] / $maxDay, 1) ?>%"></div></td>
      <td class="r"><?= e(Ads::duration($d['seconds'])) ?></td><td class="r"><?= (int) $d['rooms'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="foot"><?= e(__('Impressions are counted when a TV reports that it finished showing the ad. Screen time is the time the ad was on screen.')) ?></div>
</div>
</body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------- normal page
$pageTitle = __('Sponsor report');
$extraScripts = ['vendor/chartjs/chart.umd.min.js', 'js/analytics.js'];
require __DIR__ . '/partials/header.php';
$chart = [
    'type' => 'bar',
    'labels' => array_map(fn ($d) => date('d M', (int) strtotime($d['day'])), $report['per_day']),
    'datasets' => [
        ['label' => __('Impressions'), 'data' => array_column($report['per_day'], 'impressions')],
        ['label' => __('Screen time (minutes)'), 'data' => array_map(fn ($d) => round($d['seconds'] / 60, 1), $report['per_day']), 'type' => 'line', 'axis' => 'y2'],
    ],
];
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-bar-chart"></i> <?= e($subject) ?></h1>
    <p class="lead-sm"><?= e(__('Sponsor')) ?>: <?= e($sponsor['name']) ?><?= $campaign ? '' : ' · ' . e(__(':n campaigns', ['n' => count($campaigns)])) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="<?= e(admin_url('sponsor_report.php', $query + ['print' => 1])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i> <?= e(__('Printable report')) ?></a>
    <a class="btn btn-light border" href="<?= e(admin_url('ads.php', ['tab' => $campaign ? null : 'sponsors'])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
  </div>
</div>

<form method="get" class="card card-body mb-3">
  <?php if ($campaign): ?><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><?php else: ?><input type="hidden" name="sponsor_id" value="<?= (int) $sponsor['id'] ?>"><?php endif; ?>
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="from"><?= e(__('From')) ?></label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="to"><?= e(__('To')) ?></label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> <?= e(__('Show')) ?></button></div>
  </div>
</form>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['bi-eye', 'bg-soft-primary', number_format($t['impressions']), __('Impressions')],
      ['bi-clock-history', 'bg-soft-info', Ads::duration($t['seconds']), __('Total screen time')],
      ['bi-door-open', 'bg-soft-success', (string) $t['rooms'], __('Rooms reached')],
      ['bi-calendar-check', 'bg-soft-warning', (string) $t['days_active'], __('Days on air')],
  ] as [$icon, $cls, $value, $label]): ?>
  <div class="col-6 col-xl-3"><div class="card"><div class="stat-card">
    <div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div>
    <div class="min-w-0"><div class="stat-value" data-kpi><?= e($value) ?></div><div class="stat-label"><?= e($label) ?></div></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-graph-up"></i> <?= e(__('Impressions per day')) ?></span>
    <a class="btn btn-sm btn-light border ms-auto" href="<?= e(admin_url('sponsor_report.php', $query + ['csv' => 'day'])) ?>"><i class="bi bi-download"></i> CSV</a></div>
  <div class="card-body"><div style="height:280px"><canvas data-chart="chartDays" aria-label="<?= e(__('Impressions per day')) ?>"></canvas></div>
    <script type="application/json" id="chartDays"><?= json_embed($chart) ?></script></div>
  <div class="table-responsive" style="max-height:420px">
    <table class="table table-hc table-sm mb-0">
      <thead><tr><th><?= e(__('Date')) ?></th><th class="text-end"><?= e(__('Impressions')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th><th class="text-end"><?= e(__('Rooms')) ?></th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($report['per_day']) as $d): ?>
        <tr><td><?= e(date('D d M Y', (int) strtotime($d['day']))) ?></td><td class="text-end"><?= number_format($d['impressions']) ?></td><td class="text-end"><?= e(Ads::duration($d['seconds'])) ?></td><td class="text-end"><?= (int) $d['rooms'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-badge-ad"></i> <?= e(__('Campaigns')) ?></span>
        <a class="btn btn-sm btn-light border ms-auto" href="<?= e(admin_url('sponsor_report.php', $query + ['csv' => 'campaign'])) ?>"><i class="bi bi-download"></i> CSV</a></div>
      <div class="table-responsive"><table class="table table-hc table-sm mb-0">
        <thead><tr><th><?= e(__('Campaign')) ?></th><th class="text-end"><?= e(__('Impressions')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th><th class="text-end"><?= e(__('Rooms')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($report['per_campaign'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-end"><?= number_format($r['impressions']) ?></td><td class="text-end"><?= e(Ads::duration($r['seconds'])) ?></td><td class="text-end"><?= (int) $r['rooms'] ?></td></tr><?php endforeach; ?>
        <?php if (!$report['per_campaign']): ?><tr><td colspan="4" class="text-muted"><?= e(__('No impressions in this period.')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-door-open"></i> <?= e(__('Rooms')) ?></span>
        <a class="btn btn-sm btn-light border ms-auto" href="<?= e(admin_url('sponsor_report.php', $query + ['csv' => 'room'])) ?>"><i class="bi bi-download"></i> CSV</a></div>
      <div class="table-responsive" style="max-height:360px"><table class="table table-hc table-sm mb-0">
        <thead><tr><th><?= e(__('Room')) ?></th><th class="text-end"><?= e(__('Impressions')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($report['per_room'] as $r): ?><tr><td><?= e($r['room']) ?></td><td class="text-end"><?= number_format($r['impressions']) ?></td><td class="text-end"><?= e(Ads::duration($r['seconds'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$report['per_room']): ?><tr><td colspan="3" class="text-muted"><?= e(__('No impressions in this period.')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle"></i> <?= e(__('Impressions are counted when a TV reports that it finished showing the ad. Screen time is the time the ad was on screen.')) ?></p>
<?php require __DIR__ . '/partials/footer.php'; ?>
