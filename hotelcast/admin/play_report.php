<?php
declare(strict_types=1);
/**
 * Proof of play report (#40, core/PlayReport.php): plays reported by the TVs, filtered by date range (max
 * 366 days), content, playlist, room, group and ad campaign. Summary (plays, airtime, per TV, per day, per
 * content), a paginated table, CSV export and a print-friendly page (hotel logo, period, signature line)
 * for "Save as PDF" in the browser. Manager+; users limited to some TVs see only their TVs.
 *   play_report.php?from=Y-m-d&to=Y-m-d[&content_id&playlist_id&room_id&group_id&campaign_id][&page=N][&csv=plays|days|tvs|content][&print=1]
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('play_report.view');
$f = PlayReport::filters($_GET);
$query = PlayReport::query($f);
$hotelName = (string) Settings::get('hotel_name', '');

$csv = (string) ($_GET['csv'] ?? '');
if ($csv !== '') {
    $base = 'proof-of-play-' . $f['from'] . '_' . $f['to'];
    ActivityLog::add('play_report_csv', 'report', null, $csv . ' ' . $f['from'] . '..' . $f['to']);
    match ($csv) {
        'days' => csv_download($base . '-days.csv', [__('Date'), __('Plays'), __('Airtime (seconds)'), __('TVs')],
            array_map(fn ($r) => [$r['day'], $r['plays'], $r['seconds'], $r['tvs']], PlayReport::perDay($f))),
        'tvs' => csv_download($base . '-tvs.csv', [__('Room'), __('TV'), __('Plays'), __('Airtime (seconds)'), __('Different items'), __('First play'), __('Last play')],
            array_map(fn ($r) => [$r['room'], $r['tv'], $r['plays'], $r['seconds'], $r['items'], $r['first_at'], $r['last_at']], PlayReport::perTv($f))),
        'content' => csv_download($base . '-content.csv', [__('Content'), __('Type'), __('Plays'), __('Airtime (seconds)'), __('TVs')],
            array_map(fn ($r) => [$r['title'], $r['type'], $r['plays'], $r['seconds'], $r['tvs']], PlayReport::perContent($f, 5000))),
        default => csv_download($base . '.csv', [__('Played at'), __('Room'), __('TV'), __('Content'), __('Type'), __('Seconds on screen'), __('Ad campaign')],
            (static function () use ($f): Generator {
                foreach (PlayReport::iterate($f) as $r) {
                    yield [$r['played_at'], $r['room'], $r['tv'], $r['title'], $r['type'], $r['seconds'], $r['campaign']];
                }
            })()),
    };
}

$sum = PlayReport::summary($f);
$perDay = PlayReport::perDay($f);
$perTv = PlayReport::perTv($f);
$perContent = PlayReport::perContent($f, 50);
$described = PlayReport::describe($f);
$hm = static fn (int $s): string => Analytics::hm($s);

// ---------------------------------------------------------------- printable page ("Save as PDF")
if (!empty($_GET['print'])) {
    $brand = Branding::get();
    $logo = media_url((string) Settings::get('hotel_logo', '')) ?: ($brand['logo_url'] ?? null);
    $maxDay = max(1, ...array_map(fn ($d) => $d['plays'], $perDay ?: [['plays' => 0]]));
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="<?= e(I18n::lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e(__('Proof of play report')) ?> · <?= e($hotelName) ?> · <?= e($f['from']) ?> – <?= e($f['to']) ?></title>
<style>
body{font-family:"Noto Sans Gujarati","Noto Sans Devanagari","Noto Sans",Arial,sans-serif;color:#111;margin:0;background:#f3f4f6}
.sheet{max-width:880px;margin:20px auto;background:#fff;padding:36px 40px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.head{display:flex;gap:18px;align-items:center;border-bottom:3px solid <?= e($brand['color']) ?>;padding-bottom:12px}
.head img{max-height:64px;max-width:180px;object-fit:contain}
h1{font-size:22px;margin:0}h2{font-size:15px;margin:24px 0 8px;border-bottom:2px solid #111;padding-bottom:4px}
.meta{display:flex;justify-content:space-between;gap:20px;font-size:13px;color:#374151;margin-top:10px;flex-wrap:wrap}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px}.kpi{border:1px solid #d1d5db;border-radius:8px;padding:10px 12px}
.kpi b{display:block;font-size:22px}.kpi span{font-size:12px;color:#4b5563}
table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{padding:5px 6px;border-bottom:1px solid #e5e7eb;text-align:left}th{background:#f9fafb}
td.r,th.r{text-align:right}.bar{height:9px;background:<?= e($brand['color']) ?>;border-radius:2px;min-width:1px}
.sign{display:flex;justify-content:space-between;gap:30px;margin-top:40px;font-size:12.5px}.sign div{flex:1;border-top:1px solid #111;padding-top:6px}
.foot{margin-top:22px;font-size:11.5px;color:#6b7280}.tools{max-width:880px;margin:16px auto 0;display:flex;gap:8px}
.tools button,.tools a{font:inherit;font-size:14px;padding:7px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#111;text-decoration:none;cursor:pointer}
tr{page-break-inside:avoid}
@page{size:A4;margin:14mm}
@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;padding:0}.tools{display:none}.bar,.head{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@media (max-width:600px){.kpis{grid-template-columns:repeat(2,1fr)}.sheet{padding:20px}}
</style></head><body>
<div class="tools"><button type="button" onclick="window.print()"><?= e(__('Print / Save as PDF')) ?></button><a href="<?= e(admin_url('play_report.php', $query)) ?>"><?= e(__('Back')) ?></a></div>
<div class="sheet">
  <div class="head">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
    <div><h1><?= e(__('Proof of play report')) ?></h1><div><strong><?= e($hotelName) ?></strong></div></div>
  </div>
  <div class="meta">
    <div><?= e(__('Period')) ?>: <strong><?= e(date('d M Y', (int) strtotime($f['from']))) ?> – <?= e(date('d M Y', (int) strtotime($f['to']))) ?></strong>
      <?php foreach ($described as $k => $v): ?><br><?= e($k) ?>: <strong><?= e($v) ?></strong><?php endforeach; ?></div>
    <div><?= e(__('Generated')) ?>: <?= e(date('d M Y, h:i A')) ?></div>
  </div>
  <div class="kpis">
    <div class="kpi"><b><?= number_format($sum['plays']) ?></b><span><?= e(__('Plays')) ?></span></div>
    <div class="kpi"><b><?= e($hm($sum['seconds'])) ?></b><span><?= e(__('Total airtime')) ?></span></div>
    <div class="kpi"><b><?= (int) $sum['tvs'] ?></b><span><?= e(__('TVs')) ?></span></div>
    <div class="kpi"><b><?= (int) $sum['days'] ?></b><span><?= e(__('Days on air')) ?></span></div>
  </div>
  <h2><?= e(__('Per day')) ?></h2>
  <table><thead><tr><th><?= e(__('Date')) ?></th><th class="r"><?= e(__('Plays')) ?></th><th style="width:34%"></th><th class="r"><?= e(__('Airtime')) ?></th><th class="r"><?= e(__('TVs')) ?></th></tr></thead><tbody>
  <?php foreach ($perDay as $d): ?>
    <tr><td><?= e(date('D d M Y', (int) strtotime($d['day']))) ?></td><td class="r"><?= number_format($d['plays']) ?></td>
      <td><div class="bar" style="width:<?= round(100 * $d['plays'] / $maxDay, 1) ?>%"></div></td>
      <td class="r"><?= e($hm($d['seconds'])) ?></td><td class="r"><?= (int) $d['tvs'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <h2><?= e(__('Per TV')) ?></h2>
  <table><thead><tr><th><?= e(__('Room')) ?></th><th><?= e(__('TV')) ?></th><th class="r"><?= e(__('Plays')) ?></th><th class="r"><?= e(__('Airtime')) ?></th><th><?= e(__('First play')) ?></th><th><?= e(__('Last play')) ?></th></tr></thead><tbody>
  <?php foreach ($perTv as $r): ?>
    <tr><td><?= e($r['room']) ?></td><td><?= e($r['tv']) ?></td><td class="r"><?= number_format($r['plays']) ?></td><td class="r"><?= e($hm($r['seconds'])) ?></td>
      <td><?= e(date('d M H:i', (int) strtotime($r['first_at']))) ?></td><td><?= e(date('d M H:i', (int) strtotime($r['last_at']))) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$perTv): ?><tr><td colspan="6"><?= e(__('No plays in this period.')) ?></td></tr><?php endif; ?>
  </tbody></table>
  <h2><?= e(__('Per content')) ?></h2>
  <table><thead><tr><th><?= e(__('Content')) ?></th><th class="r"><?= e(__('Plays')) ?></th><th class="r"><?= e(__('Airtime')) ?></th><th class="r"><?= e(__('TVs')) ?></th></tr></thead><tbody>
  <?php foreach ($perContent as $r): ?>
    <tr><td><?= e($r['title']) ?></td><td class="r"><?= number_format($r['plays']) ?></td><td class="r"><?= e($hm($r['seconds'])) ?></td><td class="r"><?= (int) $r['tvs'] ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$perContent): ?><tr><td colspan="4"><?= e(__('No plays in this period.')) ?></td></tr><?php endif; ?>
  </tbody></table>
  <div class="sign"><div><?= e(__('Signature')) ?></div><div><?= e(__('Name and stamp')) ?></div></div>
  <div class="foot"><?= e(__('A play is counted when a TV reports that it finished showing the item; airtime is the time it was on screen.')) ?><br>
    <?= e(__('Generated by :p on :d', ['p' => Branding::DEFAULT_PRODUCT, 'd' => date('d M Y, h:i A')])) ?></div>
</div>
</body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------- normal page
$page = max(1, req_int('page', $_GET));
$total = PlayReport::count($f);
$rows = PlayReport::rows($f, $page);
$contentList = hc_content_list();
$playlists = hc_playlist_list();
$rooms = hc_rooms();
$groups = hc_groups();
$campaigns = Tenant::feature('ads') ? DB::all('SELECT id, name FROM ad_campaigns WHERE hotel_id = :h ORDER BY name', ['h' => Tenant::id()]) : [];
$pageTitle = __('Proof of play');
$activeNav = 'play_report';
require __DIR__ . '/partials/header.php';
$sel = static fn (string $name, array $opts, int $current, string $all): string => '<select class="form-select" id="pr_' . e($name) . '" name="' . e($name) . '"><option value="">' . e($all) . '</option>'
    . implode('', array_map(static fn ($id, $label) => '<option value="' . (int) $id . '"' . ((int) $id === $current ? ' selected' : '') . '>' . e($label) . '</option>', array_keys($opts), $opts)) . '</select>';
?>
<div class="page-head">
  <div><h1><i class="bi bi-clipboard-data"></i> <?= e(__('Proof of play')) ?></h1>
    <p class="lead-sm"><?= e(__('What your TVs really played: plays, airtime per TV and per day, with CSV and a printable report.')) ?></p></div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="<?= e(admin_url('play_report.php', $query + ['print' => 1])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i> <?= e(__('Printable report / PDF')) ?></a>
    <a class="btn btn-light border" href="<?= e(admin_url('play_report.php', $query + ['csv' => 'plays'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
  </div>
</div>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small" for="pr_from"><?= e(__('From')) ?></label><input class="form-control" type="date" id="pr_from" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="pr_to"><?= e(__('To')) ?></label><input class="form-control" type="date" id="pr_to" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="pr_content_id"><?= e(__('Content')) ?></label>
      <?= $sel('content_id', array_column($contentList, 'title', 'id'), $f['content_id'], __('All content')) ?></div>
    <div class="col-md-4"><label class="form-label small" for="pr_playlist_id"><?= e(__('Playlist')) ?></label>
      <?= $sel('playlist_id', array_column($playlists, 'name', 'id'), $f['playlist_id'], __('All playlists')) ?></div>
    <div class="col-md-3"><label class="form-label small" for="pr_room_id"><?= e(__('Room')) ?></label>
      <?= $sel('room_id', array_column($rooms, 'room_number', 'id'), $f['room_id'], __('All rooms')) ?></div>
    <div class="col-md-3"><label class="form-label small" for="pr_group_id"><?= e(__('Group')) ?></label>
      <?= $sel('group_id', array_column($groups, 'name', 'id'), $f['group_id'], __('All groups')) ?></div>
    <?php if ($campaigns): ?>
    <div class="col-md-3"><label class="form-label small" for="pr_campaign_id"><?= e(__('Ad campaign')) ?></label>
      <?= $sel('campaign_id', array_column($campaigns, 'name', 'id'), $f['campaign_id'], __('All campaigns')) ?></div>
    <?php endif; ?>
    <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> <?= e(__('Show')) ?></button></div>
  </div>
  <div class="form-text"><?= e(__('At most 366 days at once. Plays are kept for the log retention period (Settings).')) ?></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= e(__('Plays')) ?></div><div class="fs-3 fw-bold" data-pr="plays"><?= number_format($sum['plays']) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= e(__('Total airtime')) ?></div><div class="fs-3 fw-bold" data-pr="airtime"><?= e($hm($sum['seconds'])) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= e(__('TVs')) ?></div><div class="fs-3 fw-bold" data-pr="tvs"><?= (int) $sum['tvs'] ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= e(__('Days on air')) ?></div><div class="fs-3 fw-bold" data-pr="days"><?= (int) $sum['days'] ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-tv"></i> <?= e(__('Per TV')) ?></span><a class="small" href="<?= e(admin_url('play_report.php', $query + ['csv' => 'tvs'])) ?>">CSV</a></div>
      <div class="table-responsive" style="max-height:360px">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th><?= e(__('Room')) ?></th><th><?= e(__('TV')) ?></th><th class="text-end"><?= e(__('Plays')) ?></th><th class="text-end"><?= e(__('Airtime')) ?></th></tr></thead>
          <tbody>
          <?php if (!$perTv): ?><tr><td colspan="4" class="text-center text-muted py-3"><?= e(__('No plays in this period.')) ?></td></tr><?php endif; ?>
          <?php foreach ($perTv as $r): ?><tr><td><?= e($r['room']) ?></td><td class="small"><?= e($r['tv']) ?></td><td class="text-end"><?= number_format($r['plays']) ?></td><td class="text-end"><?= e($hm($r['seconds'])) ?></td></tr><?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-calendar3"></i> <?= e(__('Per day')) ?></span><a class="small" href="<?= e(admin_url('play_report.php', $query + ['csv' => 'days'])) ?>">CSV</a></div>
      <div class="table-responsive" style="max-height:360px">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th><?= e(__('Date')) ?></th><th class="text-end"><?= e(__('Plays')) ?></th><th class="text-end"><?= e(__('Airtime')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($perDay) as $d): ?><tr class="<?= $d['plays'] ? '' : 'text-muted' ?>"><td><?= e(date('D d M Y', (int) strtotime($d['day']))) ?></td><td class="text-end"><?= number_format($d['plays']) ?></td><td class="text-end"><?= e($hm($d['seconds'])) ?></td><td class="text-end"><?= (int) $d['tvs'] ?></td></tr><?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between"><span><i class="bi bi-collection-play"></i> <?= e(__('Per content')) ?></span><a class="small" href="<?= e(admin_url('play_report.php', $query + ['csv' => 'content'])) ?>">CSV</a></div>
  <div class="table-responsive" style="max-height:360px">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th><?= e(__('Content')) ?></th><th><?= e(__('Type')) ?></th><th class="text-end"><?= e(__('Plays')) ?></th><th class="text-end"><?= e(__('Airtime')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th></tr></thead>
      <tbody>
      <?php if (!$perContent): ?><tr><td colspan="5" class="text-center text-muted py-3"><?= e(__('No plays in this period.')) ?></td></tr><?php endif; ?>
      <?php foreach ($perContent as $r): ?><tr><td><?= e($r['title']) ?></td><td class="small"><?= e($r['type']) ?></td><td class="text-end"><?= number_format($r['plays']) ?></td><td class="text-end"><?= e($hm($r['seconds'])) ?></td><td class="text-end"><?= (int) $r['tvs'] ?></td></tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-list-ul"></i> <?= e(__('All plays')) ?> <span class="badge text-bg-light border"><?= number_format($total) ?></span></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="playRows">
      <thead class="table-light"><tr><th><?= e(__('Played at')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('TV')) ?></th><th><?= e(__('Content')) ?></th><th class="text-end"><?= e(__('Seconds')) ?></th><th><?= e(__('Ad campaign')) ?></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= e(__('No plays in this period.')) ?></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr><td class="small text-nowrap"><?= e(date('d M Y H:i:s', (int) strtotime($r['played_at']))) ?></td><td><?= e($r['room']) ?></td><td class="small"><?= e($r['tv']) ?></td>
          <td><?= e($r['title']) ?></td><td class="text-end"><?= $r['seconds'] !== null ? (int) $r['seconds'] : '-' ?></td><td class="small"><?= e($r['campaign']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > PlayReport::PER_PAGE): ?><div class="card-footer"><?= paginate($total, $page, PlayReport::PER_PAGE) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
