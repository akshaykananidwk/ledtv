<?php
/**
 * Reseller panel (#22): the reseller's hotels (TV status), add a hotel within the allowance,
 * enter a hotel to manage it, invoices of their hotels and the commission report.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require();
Csrf::check();
if (is_post() && req_str('op', $_POST, 20) === 'leave') {
    Auth::leaveHotel();
    redirect(admin_url(Auth::homePage()));
}
require_can('reseller.panel');

$rid = (int) $user['reseller_id'];
$reseller = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $rid]);
if (!$reseller) {
    http_response_code(403);
    require __DIR__ . '/partials/forbidden.php';
    exit;
}

/** A hotel of this reseller, or 404. */
$ownHotel = static function (int $id) use ($rid): array {
    $h = $id ? Hotels::find($id) : null;
    if (!$h || (int) $h['reseller_id'] !== $rid) {
        Logger::write('security', 'warning', 'Reseller cross-access denied', ['reseller' => $rid, 'hotel' => $id]);
        http_response_code(404);
        exit(e(__('Customer not found.')));
    }
    return $h;
};

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    switch ($op) {
        case 'enter':
            $h = $ownHotel(req_int('id', $_POST));
            Auth::enterHotel((int) $h['id']);
            redirect(admin_url('index.php'));
        case 'save':
            $id = req_int('id', $_POST);
            $existing = $id ? $ownHotel($id) : null;
            $id = platform_save_hotel($existing, $rid, admin_url('reseller.php', $existing ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            redirect(admin_url('reseller.php'));
    }
    redirect(admin_url('reseller.php'));
}

$action = req_str('action', $_GET, 20);
$activeNav = 'reseller';
$canCreate = Hotels::resellerCanCreate($rid);

if ($action === 'new' || $action === 'edit') {
    if ($action === 'new' && !$canCreate) {
        flash('warning', __('Your customer allowance is used up. Ask the platform to raise it.'));
        redirect(admin_url('reseller.php'));
    }
    $h = $action === 'edit' ? $ownHotel(req_int('id', $_GET)) : ['id' => 0];
    $pageTitle = $h['id'] ? __('Edit customer') : __('New customer');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1><a class="btn btn-light border" href="<?= e(admin_url('reseller.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" enctype="multipart/form-data">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
      <?= hotel_form_fields($h, true, !$h['id']) ?>
      <div class="mt-3"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save customer')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$hotels = Hotels::all($rid);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');
$com = Billing::commission($rid, $from, $to);
$invoices = DB::all(
    'SELECT i.*, h.name AS hotel_name FROM invoices i JOIN hotels h ON h.id = i.hotel_id WHERE h.reseller_id = :r ORDER BY i.id DESC LIMIT 100',
    ['r' => $rid]
);
$pageTitle = __('My customers');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e($reseller['name']) ?></h1><p class="lead-sm"><?= e(__('Customers')) ?>: <?= count($hotels) ?><?= $reseller['max_hotels'] !== null ? ' / ' . (int) $reseller['max_hotels'] : '' ?> · <?= e(__('Commission')) ?> <?= e((string) (float) $reseller['commission_percent']) ?>%</p></div>
  <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(admin_url('reseller.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add customer')) ?></a>
  <?php else: ?><span class="text-muted small"><?= e(__('Customer allowance used up.')) ?></span><?php endif; ?>
</div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-hc table-hover align-middle">
  <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('Plan')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
  <tbody>
  <?php if (!$hotels): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No customers yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($hotels as $h): ?>
    <tr>
      <td><strong><?= e($h['name']) ?></strong><div class="small text-muted"><?= e((string) $h['city']) ?></div></td>
      <td class="small"><?= e($h['plan_name'] ?? '—') ?></td>
      <td><?= (int) $h['tv_count'] ?> <span class="small text-success">(<?= (int) $h['tv_online'] ?> <?= e(__('online')) ?>)</span></td>
      <td><?= Hotels::statusBadge($h['status']) ?></td>
      <td class="text-end text-nowrap">
        <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="enter"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
          <button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Enter')) ?></button></form>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller.php', ['action' => 'edit', 'id' => $h['id']])) ?>"><i class="bi bi-pencil"></i></a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-header"><?= e(__('Commission report')) ?></div><div class="card-body">
      <form class="row g-2 mb-3" method="get">
        <div class="col-5"><input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>" aria-label="<?= e(__('From')) ?>"></div>
        <div class="col-5"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>" aria-label="<?= e(__('To')) ?>"></div>
        <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel"></i></button></div>
      </form>
      <table class="table table-sm mb-2"><thead><tr><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('Paid (net)')) ?></th><th class="text-end"><?= e(__('Commission')) ?></th></tr></thead><tbody>
        <?php foreach ($com['rows'] as $r): ?><tr><td class="small"><?= e($r['name']) ?></td><td class="text-end"><?= e(money($r['amount'])) ?></td><td class="text-end"><?= e(money($r['commission'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$com['rows']): ?><tr><td colspan="3" class="text-muted small"><?= e(__('No paid invoices in this period.')) ?></td></tr><?php endif; ?>
      </tbody><tfoot><tr class="fw-bold"><td><?= e(__('Total')) ?></td><td class="text-end"><?= e(money($com['paid_total'])) ?></td><td class="text-end text-success" data-commission><?= e(money($com['commission'])) ?></td></tr></tfoot></table>
      <div class="small text-muted"><?= e(__('Commission = paid invoices (without tax) × :p %.', ['p' => (string) $com['percent']])) ?></div>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100"><div class="card-header"><?= e(__('Invoices of my customers')) ?></div><div class="table-responsive"><table class="table table-sm table-hc mb-0">
      <thead><tr><th><?= e(__('Number')) ?></th><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Status')) ?></th></tr></thead><tbody>
      <?php if (!$invoices): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= e(__('No invoices yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($invoices as $inv): ?><tr><td><a href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e($inv['number']) ?></a></td><td class="small"><?= e($inv['hotel_name']) ?></td><td class="text-end"><?= e(money($inv['total'], $inv['currency'])) ?></td><td><?= Billing::statusBadge($inv) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
