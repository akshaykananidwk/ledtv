<?php
/**
 * Guest feedback report (#8): average rating, distribution, category averages (cleanliness / staff /
 * food), comments, CSV export. Manager+ (guests.feedback).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('guests.feedback');
if (!GuestServices::enabled()) {
    http_response_code(403);
    $GLOBALS['hc_forbidden'] = true;
    require __DIR__ . '/partials/forbidden.php';
    exit;
}

$validDate = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
$to = $validDate(req_str('to', $_GET, 10)) ?? date('Y-m-d');
$from = $validDate(req_str('from', $_GET, 10)) ?? date('Y-m-d', strtotime($to . ' -29 days'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$maxRating = max(1, min(5, req_int('max', $_GET) ?: 5));

if (($_GET['export'] ?? '') === 'csv') {
    ActivityLog::add('feedback_export', 'feedback', null, $from . ' – ' . $to);
    $rows = GuestServices::feedbackRows($from, $to, 5000, 0, $maxRating);
    csv_download('feedback_' . $from . '_' . $to . '.csv',
        ['Date', 'Room', 'Rating', 'Cleanliness', 'Staff', 'Food', 'Language', 'Comment'],
        array_map(fn ($r) => [$r['created_at'], $r['room_number'] ?? '', $r['rating'], $r['rating_cleanliness'], $r['rating_staff'], $r['rating_food'], $r['language'], $r['comment']], $rows));
}

$stats = GuestServices::feedbackStats($from, $to);
$page = max(1, req_int('page', $_GET));
$total = (int) DB::value('SELECT COUNT(*) FROM guest_feedback WHERE hotel_id = :hid AND created_at BETWEEN :f AND :t AND rating <= :mr', hid() + ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 'mr' => $maxRating]);
$rows = GuestServices::feedbackRows($from, $to, 50, ($page - 1) * 50, $maxRating);
$stars = fn (?float $v) => $v === null ? '—' : number_format($v, 1) . ' ★';
$maxDist = max(1, ...array_values($stats['distribution']));

$pageTitle = __('Guest feedback');
$activeNav = 'guest_feedback';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-star-half"></i> <?= e(__('Guest feedback')) ?></h1>
    <p class="lead-sm"><?= e(__('Ratings and comments guests leave from their phone (scan the QR code on the TV).')) ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(self_url(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-filetype-csv"></i> <?= e(__('Export CSV')) ?></a>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
  <div class="col-auto"><label class="form-label small mb-0" for="fFrom"><?= e(__('From')) ?></label><input class="form-control" type="date" name="from" id="fFrom" value="<?= e($from) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0" for="fTo"><?= e(__('To')) ?></label><input class="form-control" type="date" name="to" id="fTo" value="<?= e($to) ?>"></div>
  <div class="col-auto"><label class="form-label small mb-0" for="fMax"><?= e(__('Ratings')) ?></label>
    <select class="form-select" name="max" id="fMax">
      <option value="5"><?= e(__('All')) ?></option>
      <option value="3"<?= $maxRating === 3 ? ' selected' : '' ?>><?= e(__('3 stars or less')) ?></option>
      <option value="2"<?= $maxRating === 2 ? ' selected' : '' ?>><?= e(__('2 stars or less')) ?></option>
    </select></div>
  <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-funnel"></i> <?= e(__('Show')) ?></button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-muted small"><?= e(__('Average rating')) ?></div>
    <div class="fs-2 fw-bold text-warning"><?= e($stars($stats['average'])) ?></div>
    <div class="small text-muted"><?= e(__(':n reviews', ['n' => $stats['count']])) ?></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body small">
    <div class="d-flex justify-content-between"><span><?= e(__('Cleanliness')) ?></span><strong><?= e($stars($stats['cleanliness'])) ?></strong></div>
    <div class="d-flex justify-content-between"><span><?= e(__('Staff')) ?></span><strong><?= e($stars($stats['staff'])) ?></strong></div>
    <div class="d-flex justify-content-between"><span><?= e(__('Food')) ?></span><strong><?= e($stars($stats['food'])) ?></strong></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <?php for ($n = 5; $n >= 1; $n--): $c = $stats['distribution'][$n]; ?>
      <div class="d-flex align-items-center gap-2 small mb-1">
        <span class="text-nowrap" style="width:2.5rem"><?= $n ?> ★</span>
        <div class="progress flex-grow-1" style="height:10px" role="progressbar" aria-label="<?= $n ?> ★" aria-valuenow="<?= (int) $c ?>" aria-valuemin="0" aria-valuemax="<?= (int) $maxDist ?>">
          <div class="progress-bar <?= $n >= 4 ? 'bg-success' : ($n === 3 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= (int) round($c * 100 / $maxDist) ?>%"></div>
        </div>
        <span class="text-end" style="width:2.5rem"><?= (int) $c ?></span>
      </div>
    <?php endfor; ?>
  </div></div></div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr><th><?= e(__('Date')) ?></th><th><?= e(__('Room')) ?></th><th><?= e(__('Rating')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Cleanliness / Staff / Food')) ?></th><th><?= e(__('Comment')) ?></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No feedback in this period.')) ?></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small text-nowrap"><?= e(date('d M Y, h:i A', (int) strtotime((string) $r['created_at']))) ?></td>
          <td><?= e($r['room_number'] ?? '—') ?></td>
          <td class="text-nowrap <?= (int) $r['rating'] <= 2 ? 'text-danger' : 'text-warning' ?>"><?= str_repeat('★', (int) $r['rating']) ?><span class="text-muted"><?= str_repeat('☆', 5 - (int) $r['rating']) ?></span></td>
          <td class="small d-none d-md-table-cell"><?= e(implode(' / ', array_map(fn ($v) => $v === null ? '—' : (string) $v, [$r['rating_cleanliness'], $r['rating_staff'], $r['rating_food']]))) ?></td>
          <td class="small"><?= nl2br(e((string) $r['comment'])) ?> <span class="badge text-bg-light border"><?= e(strtoupper((string) $r['language'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > 50): ?><div class="card-footer"><?= paginate($total, $page, 50) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
