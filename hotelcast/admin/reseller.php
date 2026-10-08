<?php
/**
 * Reseller panel (#22): the reseller's hotels (TV status), add a hotel within the allowance,
 * enter a hotel to manage it, invoices of their hotels and the commission report.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';
require_once __DIR__ . '/partials/panel_ui.php';

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
$pageTitle = __('My customers');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-briefcase"></i> <?= e(__('My customers')) ?></h1><p class="lead-sm"><?= e($reseller['name']) ?> · <?= e(__('Customers')) ?>: <?= count($hotels) ?><?= $reseller['max_hotels'] !== null ? ' / ' . (int) $reseller['max_hotels'] : '' ?> · <?= e(__('Commission')) ?> <?= e((string) (float) $reseller['commission_percent']) ?>%</p></div>
  <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(admin_url('reseller.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add customer')) ?></a>
  <?php else: ?><span class="text-muted small"><?= e(__('Customer allowance used up.')) ?></span><?php endif; ?>
</div>
<div class="card mb-3">
<?php if (!$hotels): ?>
  <div class="hc-empty"><i class="bi bi-buildings"></i><p class="mb-1"><strong><?= e(__('No customers yet.')) ?></strong></p><?php if ($canCreate): ?><a class="btn btn-sm btn-primary" href="<?= e(admin_url('reseller.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add customer')) ?></a><?php endif; ?></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-hc table-hover align-middle">
  <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('Plan')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($hotels as $h): ?>
    <tr>
      <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $h['id']])) ?>"><?= e($h['name']) ?></a><div class="small text-muted"><?= e((string) $h['city']) ?><?= (int) $h['unpaid_invoices'] ? ' · <span class="text-danger">' . e(__(':n unpaid', ['n' => $h['unpaid_invoices']])) . '</span>' : '' ?></div></td>
      <td class="small"><?= e($h['plan_name'] ?? '—') ?></td>
      <td><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $h['id'], 'tab' => 'screens'])) ?>"><?= (int) $h['tv_count'] ?> <span class="small text-success">(<?= (int) $h['tv_online'] ?> <?= e(__('online')) ?>)</span></a></td>
      <td><?= Hotels::statusBadge(Tenant::state((int) $h['id'])) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_customer.php', ['id' => $h['id']])) ?>" title="<?= e(__('Customer 360')) ?>"><i class="bi bi-card-list"></i> <span class="d-none d-sm-inline"><?= e(__('Manage')) ?></span></a>
        <?= panel_open_workspace((int) $h['id'], 'reseller.php', 'sm') ?>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('reseller.php', ['action' => 'edit', 'id' => $h['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<p class="small text-muted"><i class="bi bi-info-circle"></i> <?= e(__('Invoices and your commission report are under Invoices; TV problems under Support.')) ?></p>
<?php require __DIR__ . '/partials/footer.php'; ?>
