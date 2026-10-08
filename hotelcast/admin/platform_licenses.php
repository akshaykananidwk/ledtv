<?php
/**
 * Platform → Licenses (#19): keys for self-hosted installations. A key is bound to the domain of
 * its first check (POST /api/license/check); "Reset domain" frees it for a move to a new server.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $lic = $id ? DB::one('SELECT * FROM licenses WHERE id = :id', ['id' => $id]) : null;
    if ($id && !$lic) {
        redirect(admin_url('platform_licenses.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $errors] = LicenseServer::validate($_POST);
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('platform_licenses.php', $lic ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            $data['features'] = null;
            if ($lic) {
                DB::update('licenses', $data, 'id = :id', ['id' => $id]);
                flash('success', __('License saved.'));
            } else {
                $key = LicenseServer::newKey();
                $id = DB::insert('licenses', $data + ['license_key' => $key, 'created_at' => now()]);
                flash('success', __('License created: :k', ['k' => $key]));
            }
            ActivityLog::add($lic ? 'license_update' : 'license_create', 'license', $id, $data['customer_name']);
            break;
        case 'reset_domain':
            DB::update('licenses', ['bound_domain' => null], 'id = :id', ['id' => $id]);
            ActivityLog::add('license_reset', 'license', $id, 'Domain binding reset (was ' . ($lic['bound_domain'] ?? '-') . ')');
            flash('success', __('Domain binding reset. The next check binds the key to the new domain.'));
            break;
        case 'revoke':
            DB::update('licenses', ['status' => $lic['status'] === 'active' ? 'revoked' : 'active'], 'id = :id', ['id' => $id]);
            ActivityLog::add('license_status', 'license', $id, $lic['status'] === 'active' ? 'revoked' : 'reactivated');
            flash('success', __('Saved.'));
            break;
    }
    redirect(admin_url('platform_licenses.php'));
}

$action = req_str('action', $_GET, 20);
$activeNav = 'platform_licenses';
$hotels = DB::all('SELECT id, name FROM hotels ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    $l = ['id' => 0, 'customer_name' => '', 'max_tvs' => null, 'expires_at' => null, 'hotel_id' => null, 'notes' => '', 'status' => 'active', 'license_key' => ''];
    if ($action === 'edit') {
        $l = DB::one('SELECT * FROM licenses WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$l) {
            redirect(admin_url('platform_licenses.php'));
        }
    }
    $pageTitle = $l['id'] ? __('Edit license') : __('New license');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1><a class="btn btn-light border" href="<?= e(admin_url('platform_licenses.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" class="card" style="max-width:760px"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
      <?php if ($l['id']): ?><div class="col-12"><label class="form-label"><?= e(__('License key')) ?></label><div class="input-group"><input class="form-control mono" value="<?= e($l['license_key']) ?>" readonly><button type="button" class="btn btn-outline-secondary" data-copy="<?= e($l['license_key']) ?>"><i class="bi bi-clipboard"></i></button></div></div><?php endif; ?>
      <div class="col-sm-8"><label class="form-label" for="l_c"><?= e(__('Customer name')) ?> *</label><input class="form-control" id="l_c" name="customer_name" value="<?= e($l['customer_name']) ?>" required maxlength="150"></div>
      <div class="col-sm-4"><label class="form-label" for="l_m"><?= e(__('Max TVs')) ?></label><input class="form-control" type="number" min="0" id="l_m" name="max_tvs" value="<?= e((string) ($l['max_tvs'] ?? '')) ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
      <div class="col-sm-6"><label class="form-label" for="l_e"><?= e(__('Valid until')) ?></label><input class="form-control" type="date" id="l_e" name="expires_at" value="<?= $l['expires_at'] ? e(date('Y-m-d', (int) strtotime((string) $l['expires_at']))) : '' ?>"></div>
      <div class="col-sm-6"><label class="form-label" for="l_h"><?= e(__('Billing customer (optional)')) ?></label><select class="form-select" id="l_h" name="hotel_id"><option value=""><?= e(__('— none —')) ?></option>
        <?php foreach ($hotels as $h): ?><option value="<?= (int) $h['id'] ?>"<?= (int) ($l['hotel_id'] ?? 0) === (int) $h['id'] ? ' selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?></select>
        <div class="form-text"><?= e(__('Link to a customer record to bill the self-hosted customer; suspending that customer also invalidates the license.')) ?></div></div>
      <div class="col-12"><label class="form-label" for="l_n"><?= e(__('Notes')) ?></label><textarea class="form-control" id="l_n" name="notes" rows="2"><?= e((string) $l['notes']) ?></textarea></div>
      <input type="hidden" name="status" value="<?= e($l['status']) ?>">
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e($l['id'] ? __('Save license') : __('Create license key')) ?></button></div>
    </div></form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$licenses = DB::all('SELECT l.*, h.name AS hotel_name FROM licenses l LEFT JOIN hotels h ON h.id = l.hotel_id ORDER BY l.id DESC');
$pageTitle = __('Licenses');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Licenses')) ?></h1><p class="lead-sm"><?= e(__('Keys for self-hosted installations. They check :url once a day.', ['url' => base_url('api/license/check')])) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('platform_licenses.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New license')) ?></a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hc table-hover align-middle">
  <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('License key')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Domain')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Last check')) ?></th><th><?= e(__('Valid until')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
  <tbody>
  <?php if (!$licenses): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No licenses yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($licenses as $l): $expired = $l['expires_at'] && strtotime((string) $l['expires_at']) < time(); ?>
    <tr>
      <td><strong><?= e($l['customer_name']) ?></strong><div class="small text-muted"><?= e($l['hotel_name'] ?? '') ?><?= $l['max_tvs'] !== null ? ' · ' . e(__('max :n TVs', ['n' => $l['max_tvs']])) : '' ?></div></td>
      <td class="mono small text-nowrap"><?= e($l['license_key']) ?> <button type="button" class="btn btn-xs btn-light border" data-copy="<?= e($l['license_key']) ?>"><i class="bi bi-clipboard"></i></button></td>
      <td class="d-none d-md-table-cell small"><?= e($l['bound_domain'] ?: __('not bound yet')) ?></td>
      <td class="d-none d-lg-table-cell small"><?= e($l['last_check_at'] ? time_ago($l['last_check_at']) . ' · v' . $l['last_version'] . ' · ' . (int) $l['last_tv_count'] . ' ' . __('TVs') : __('never')) ?></td>
      <td class="small"><?= e($l['expires_at'] ? date('d M Y', (int) strtotime((string) $l['expires_at'])) : __('No expiry')) ?></td>
      <td><?= $l['status'] !== 'active' ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : ($expired ? Hotels::statusBadge('expired') : Hotels::statusBadge('active')) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_licenses.php', ['action' => 'edit', 'id' => $l['id']])) ?>"><i class="bi bi-pencil"></i></a>
        <?php if ($l['bound_domain']): ?><form method="post" class="d-inline" data-confirm="<?= e(__('Reset the domain binding of this key?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="reset_domain"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= e(__('Reset domain')) ?>"><i class="bi bi-arrow-counterclockwise"></i></button></form><?php endif; ?>
        <form method="post" class="d-inline" data-confirm="<?= e($l['status'] === 'active' ? __('Revoke this license? The installation shows the "service paused" screen after its next check.') : __('Reactivate this license?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="revoke"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <button class="btn btn-sm <?= $l['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= e($l['status'] === 'active' ? __('Revoke') : __('Activate')) ?>"><i class="bi <?= $l['status'] === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
