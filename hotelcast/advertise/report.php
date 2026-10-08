<?php
/**
 * Advertiser portal: proof-of-play report of one order — impressions and screen time per hotel per day
 * (from the hotels' ad statistics). Printable (&print=1) and CSV (&csv=1). Own orders only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
$adv = MarketplacePortal::require();
$id = (int) ($_GET['id'] ?? 0);
$b = Marketplace::booking((int) $adv['id'], $id);
if (!$b || !$b['paid_at']) {
    http_response_code(404);
    MarketplacePortal::header(__('Not found'));
    echo '<div class="alert alert-warning">' . e(__('No report for this order yet.')) . '</div>';
    MarketplacePortal::footer();
    exit;
}
$from = Ads::date($_GET['from'] ?? null) ?? (string) $b['start_date'];
$to = Ads::date($_GET['to'] ?? null) ?? min((string) $b['end_date'], date('Y-m-d'));
$from = max($from, (string) $b['start_date']);
$to = min(max($to, $from), (string) $b['end_date']);
$r = Marketplace::report($b, $from, $to);
$brand = Branding::get(0);

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proof-of-play-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $b['number']) . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, [__('Date'), __('Customer'), __('City'), __('Impressions'), __('Screen time (seconds)'), __('Screens')]);
    foreach ($r['hotels'] as $h) {
        foreach ($h['days'] as $day => $d) {
            // Spreadsheet formula injection: hotel names never start with = + - @ in the output.
            fputcsv($fh, [$day, preg_replace('/^[=+\-@]/', "'$0", $h['name']), preg_replace('/^[=+\-@]/', "'$0", $h['city']), $d['impressions'], $d['seconds'], $d['rooms']]);
        }
    }
    fclose($fh);
    exit;
}

$print = !empty($_GET['print']);
if ($print) {
    ?><!DOCTYPE html><html lang="<?= e(I18n::lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(__('Proof of play')) ?> <?= e((string) $b['number']) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('advertise/advertise.css')) ?>">
<script src="<?= e(asset('advertise/advertise.js')) ?>" defer></script></head><body class="mkt-print p-3">
<?php } else {
    MarketplacePortal::header(__('Proof of play'), 'index');
} ?>
<div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
  <div><h1 class="h4 mb-0"><?= e(__('Proof of play')) ?></h1>
    <div class="text-muted"><?= e($brand['product']) ?> · <?= e(__('Ad order')) ?> <?= e((string) $b['number']) ?> · <?= e($adv['business_name']) ?></div>
    <div class="small"><?= e($b['title']) ?> · <?= e(date('d M Y', (int) strtotime($from))) ?> – <?= e(date('d M Y', (int) strtotime($to))) ?></div></div>
  <div class="d-flex gap-2 d-print-none">
    <?php if ($print): ?><button type="button" class="btn btn-primary" data-print><i class="bi bi-printer"></i> <?= e(__('Print / Save as PDF')) ?></button>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="<?= e(MarketplacePortal::url('report.php', ['id' => $id, 'from' => $from, 'to' => $to, 'print' => 1])) ?>"><i class="bi bi-printer"></i> <?= e(__('Printable')) ?></a>
      <a class="btn btn-outline-secondary" href="<?= e(MarketplacePortal::url('report.php', ['id' => $id, 'from' => $from, 'to' => $to, 'csv' => 1])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    <?php endif; ?>
  </div>
</div>
<?php if (!$print): ?>
<form class="row g-2 my-2 d-print-none" method="get"><input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="col-5"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" min="<?= e((string) $b['start_date']) ?>" max="<?= e((string) $b['end_date']) ?>" aria-label="<?= e(__('From')) ?>"></div>
  <div class="col-5"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" min="<?= e((string) $b['start_date']) ?>" max="<?= e((string) $b['end_date']) ?>" aria-label="<?= e(__('To')) ?>"></div>
  <div class="col-2 d-grid"><button class="btn btn-outline-primary" aria-label="<?= e(__('Show')) ?>"><i class="bi bi-arrow-repeat"></i></button></div>
</form>
<?php endif; ?>
<div class="row g-2 my-2 text-center">
  <div class="col-4"><div class="mkt-stat"><b data-total-impressions><?= number_format((int) $r['totals']['impressions']) ?></b><span><?= e(__('Impressions')) ?></span></div></div>
  <div class="col-4"><div class="mkt-stat"><b><?= e(Ads::duration((int) $r['totals']['seconds'])) ?></b><span><?= e(__('Screen time')) ?></span></div></div>
  <div class="col-4"><div class="mkt-stat"><b><?= count(array_filter($r['hotels'], fn ($h) => $h['totals']['impressions'] > 0)) ?></b><span><?= e(__('Customers')) ?></span></div></div>
</div>
<?php if (!$r['hotels']): ?><p class="text-muted"><?= e(__('No venue has started your ad yet.')) ?></p><?php endif; ?>
<?php foreach ($r['hotels'] as $h): ?>
<section class="card mb-3 mkt-report-hotel"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap gap-2"><h2 class="h6 mb-0"><?= e($h['name']) ?> <small class="text-muted"><?= e($h['city']) ?></small></h2>
    <span class="small"><?= number_format((int) $h['totals']['impressions']) ?> <?= e(__('impressions')) ?><?= $h['impressions_ordered'] ? ' / ' . number_format((int) $h['impressions_ordered']) : '' ?> · <?= e(Ads::duration((int) $h['totals']['seconds'])) ?></span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 mt-2">
    <thead><tr><th><?= e(__('Date')) ?></th><th class="text-end"><?= e(__('Impressions')) ?></th><th class="text-end"><?= e(__('Screen time')) ?></th><th class="text-end"><?= e(__('Screens')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($h['days'] as $day => $d): ?>
      <tr><td class="text-nowrap"><?= e(date('d M y', (int) strtotime($day))) ?></td><td class="text-end"><?= number_format($d['impressions']) ?></td><td class="text-end"><?= e(Ads::duration($d['seconds'])) ?></td><td class="text-end"><?= (int) $d['rooms'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></section>
<?php endforeach; ?>
<p class="small text-muted"><?= e(__('Impressions are reported by the venue TVs each time your ad finished playing. Generated :d.', ['d' => date('d M Y H:i')])) ?></p>
<?php
if ($print) {
    echo '</body></html>';
} else {
    MarketplacePortal::footer();
}
