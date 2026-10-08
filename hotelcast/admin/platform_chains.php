<?php
/**
 * Platform → Hotel chains (#20): create / edit chains, assign hotels, chain admin logins, chain access
 * for hotel super admins. Platform admins see every chain; resellers only their own chains and hotels.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('chains.manage');
Chains::requireEnabled(); // platform setting feature_chains (Platform settings → Features)
Csrf::check();
$isReseller = $user['role'] === 'reseller';
$rid = $isReseller ? (int) $user['reseller_id'] : null;

/** A chain the current user may administer, or 404. */
$ownChain = static function (int $id): array {
    $c = Chains::find($id);
    if (!$c || !Chains::canAdminister($id)) {
        Chains::deny('chain ' . $id);
    }
    return $c;
};

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    try {
        switch ($op) {
            case 'save':
                $existing = $id ? $ownChain($id) : null;
                [$data, $errors] = Chains::validate($_POST, $isReseller);
                if (isset($_FILES['brand_logo']) && is_array($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && !$errors) {
                    try {
                        $up = Uploader::handle($_FILES['brand_logo'], 'logo', 'platform');
                        $data['brand_logo'] = $up['path'];
                        if ($up['thumb']) {
                            Uploader::delete($up['thumb']);
                        }
                    } catch (RuntimeException $e) {
                        $errors[] = __('Logo') . ': ' . $e->getMessage();
                    }
                } elseif ($existing && !empty($_POST['remove_brand_logo'])) {
                    $data['brand_logo'] = null;
                }
                if ($errors) {
                    flash_errors($errors);
                    redirect(admin_url('platform_chains.php', $existing ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
                }
                if ($isReseller) {
                    $data['reseller_id'] = $rid;
                }
                if ($existing) {
                    Chains::update($id, $data);
                    ActivityLog::add('chain_update', 'chain', $id, $data['name']);
                } else {
                    $id = Chains::create($data, Auth::id());
                    ActivityLog::add('chain_create', 'chain', $id, $data['name']);
                }
                flash('success', __('Chain ":n" saved.', ['n' => $data['name']]));
                redirect(admin_url('platform_chains.php', ['action' => 'view', 'id' => $id]));

            case 'delete':
                $c = $ownChain($id);
                Chains::delete($id);
                ActivityLog::add('chain_delete', 'chain', $id, $c['name']);
                flash('success', __('Chain deleted. Its hotels keep working on their own; chain admin logins were disabled.'));
                redirect(admin_url('platform_chains.php'));

            case 'add_hotel':
            case 'remove_hotel':
                $c = $ownChain($id);
                $hid = req_int('hotel_id', $_POST);
                Chains::assignHotel($id, $hid, $op === 'add_hotel');
                ActivityLog::add($op === 'add_hotel' ? 'chain_hotel_add' : 'chain_hotel_remove', 'chain', $id, $c['name'] . ' ↔ hotel #' . $hid);
                flash('success', $op === 'add_hotel' ? __('Hotel added to the chain.') : __('Hotel removed from the chain.'));
                break;

            case 'add_admin':
                $ownChain($id);
                [$admin, $errors] = Hotels::validateAdmin($_POST, true);
                if ($errors) {
                    flash_errors($errors);
                } else {
                    $uid = Chains::createAdmin($id, $admin);
                    ActivityLog::add('user_create', 'user', $uid, $admin['username'] . ' (chain_admin of chain #' . $id . ')');
                    flash('success', __('Chain admin :u created.', ['u' => $admin['username']]));
                }
                break;

            case 'admin_status':
                $ownChain($id);
                $uid = req_int('user_id', $_POST);
                if (!DB::value("SELECT id FROM users WHERE id = :u AND chain_id = :c AND role = 'chain_admin'", ['u' => $uid, 'c' => $id])) {
                    Chains::deny("chain $id admin $uid");
                }
                $active = req_int('active', $_POST) === 1;
                DB::query('UPDATE users SET is_active = :a WHERE id = :u', ['a' => $active ? 1 : 0, 'u' => $uid]);
                if (!$active) {
                    Auth::revokeUserSessions($uid);
                }
                flash('success', $active ? __('Login enabled.') : __('Login disabled.'));
                break;

            case 'grant':
            case 'revoke':
                $ownChain($id);
                Chains::setSuperAdminAccess($id, req_int('user_id', $_POST), $op === 'grant');
                ActivityLog::add('chain_access_' . $op, 'user', req_int('user_id', $_POST), 'chain #' . $id);
                flash('success', $op === 'grant' ? __('Chain access granted.') : __('Chain access removed.'));
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('platform_chains.php', $id ? ['action' => 'view', 'id' => $id] : []));
}

$action = req_str('action', $_GET, 20);
$activeNav = 'platform_chains';

// ---------------------------------------------------------------- create / edit
if ($action === 'new' || $action === 'edit') {
    $c = $action === 'edit' ? $ownChain(req_int('id', $_GET)) : ['id' => 0];
    $v = static fn (string $k) => e((string) ($c[$k] ?? ''));
    $pageTitle = $c['id'] ? __('Edit chain') : __('New chain');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('platform_chains.php', $c['id'] ? ['action' => 'view', 'id' => $c['id']] : [])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" enctype="multipart/form-data">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-7"><div class="card"><div class="card-header"><?= e(__('Hotel chain')) ?></div><div class="card-body row g-3">
          <div class="col-12"><label class="form-label" for="c_name"><?= e(__('Chain name')) ?> *</label><input class="form-control" id="c_name" name="name" value="<?= $v('name') ?>" required maxlength="150"></div>
          <div class="col-sm-6"><label class="form-label" for="c_on"><?= e(__('Owner')) ?></label><input class="form-control" id="c_on" name="owner_name" value="<?= $v('owner_name') ?>" maxlength="120"></div>
          <div class="col-sm-6"><label class="form-label" for="c_op"><?= e(__('Phone / WhatsApp')) ?></label><input class="form-control" id="c_op" name="owner_phone" value="<?= $v('owner_phone') ?>" maxlength="40"></div>
          <div class="col-sm-6"><label class="form-label" for="c_oe"><?= e(__('Email')) ?></label><input class="form-control" type="email" id="c_oe" name="owner_email" value="<?= $v('owner_email') ?>" maxlength="190"></div>
          <?php if (!$isReseller): ?>
          <div class="col-sm-6"><label class="form-label" for="c_res"><?= e(__('Reseller')) ?></label><select class="form-select" id="c_res" name="reseller_id">
            <option value=""><?= e(__('— Direct customer —')) ?></option>
            <?php foreach (Hotels::resellers() as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) ($c['reseller_id'] ?? 0) === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
            <div class="form-text"><?= e(__('The reseller can then manage this chain for its own hotels.')) ?></div></div>
          <?php endif; ?>
          <div class="col-12"><label class="form-label" for="c_notes"><?= e(__('Notes')) ?></label><textarea class="form-control" id="c_notes" name="notes" rows="2" maxlength="1000"><?= $v('notes') ?></textarea></div>
        </div></div></div>
        <div class="col-lg-5"><div class="card"><div class="card-header"><?= e(__('Chain branding (optional)')) ?></div><div class="card-body row g-3">
          <div class="col-12 small text-muted"><?= e(__('Used by chain settings templates to brand the chain\'s hotels.')) ?></div>
          <div class="col-sm-7"><label class="form-label" for="c_bn"><?= e(__('Product name')) ?></label><input class="form-control" id="c_bn" name="brand_name" value="<?= $v('brand_name') ?>" maxlength="120"></div>
          <div class="col-sm-5"><label class="form-label" for="c_bc"><?= e(__('Colour')) ?></label><input class="form-control" id="c_bc" name="brand_color" value="<?= $v('brand_color') ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#7B1FA2"></div>
          <div class="col-12"><label class="form-label" for="c_bl"><?= e(__('Logo')) ?></label><input class="form-control" type="file" id="c_bl" name="brand_logo" accept="image/png,image/jpeg,image/webp">
            <?php if (!empty($c['brand_logo'])): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $c['brand_logo'])) ?>" alt="" style="max-height:40px">
              <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="remove_brand_logo" value="1"> <?= e(__('Remove')) ?></label></div><?php endif; ?></div>
        </div></div></div>
      </div>
      <div class="mt-3"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save chain')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- details
if ($action === 'view') {
    $c = $ownChain(req_int('id', $_GET));
    $cid = (int) $c['id'];
    $inChain = Chains::hotels($cid);
    $assignable = array_filter(Chains::assignableHotels($c), static fn ($h) => $h['chain_id'] === null);
    $admins = Chains::admins($cid);
    $supers = Chains::hotelSuperAdmins($cid);
    $pageTitle = $c['name'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div class="min-w-0"><h1 class="text-truncate"><i class="bi bi-diagram-3"></i> <?= e($c['name']) ?></h1>
        <p class="lead-sm mb-0"><?= e(dot_trim(($c['owner_name'] ?? '') . ' · ' . ($c['owner_phone'] ?? '') . ($c['reseller_name'] ? ' · ' . __('Reseller') . ': ' . $c['reseller_name'] : ''))) ?></p></div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= e(admin_url('chain.php', ['chain' => $cid])) ?>"><i class="bi bi-speedometer2"></i> <?= e(__('Chain dashboard')) ?></a>
        <a class="btn btn-outline-primary" href="<?= e(admin_url('platform_chains.php', ['action' => 'edit', 'id' => $cid])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
        <form method="post" class="m-0" data-confirm="<?= e(__('Delete this chain? Hotels keep working on their own, chain admin logins are disabled, the chain library is removed.')) ?>">
          <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= $cid ?>">
          <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> <?= e(__('Delete')) ?></button></form>
        <a class="btn btn-light border" href="<?= e(admin_url('platform_chains.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header"><?= e(__('Hotels in this chain')) ?> (<?= count($inChain) ?>)</div>
          <ul class="list-group list-group-flush">
            <?php if (!$inChain): ?><li class="list-group-item text-muted"><?= e(__('No hotels in this chain yet.')) ?></li><?php endif; ?>
            <?php foreach ($inChain as $h): ?>
              <li class="list-group-item d-flex align-items-center gap-2"><div class="flex-grow-1 min-w-0"><strong><?= e($h['name']) ?></strong> <span class="small text-muted"><?= e((string) $h['city']) ?> #<?= (int) $h['id'] ?></span></div>
                <?= Hotels::statusBadge(Tenant::state((int) $h['id'])) ?>
                <form method="post" class="m-0" data-confirm="<?= e(__('Remove this hotel from the chain?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="remove_hotel"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="hotel_id" value="<?= (int) $h['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Remove')) ?>"><i class="bi bi-x-lg"></i></button></form></li>
            <?php endforeach; ?>
          </ul>
          <div class="card-body border-top">
            <?php if ($assignable): ?>
            <form method="post" class="d-flex gap-2"><?= Csrf::field() ?><input type="hidden" name="op" value="add_hotel"><input type="hidden" name="id" value="<?= $cid ?>">
              <select class="form-select" name="hotel_id" aria-label="<?= e(__('Add a hotel')) ?>"><?php foreach ($assignable as $h): ?><option value="<?= (int) $h['id'] ?>"><?= e($h['name'] . ($h['city'] ? ' · ' . $h['city'] : '')) ?></option><?php endforeach; ?></select>
              <button class="btn btn-outline-primary text-nowrap"><i class="bi bi-plus-lg"></i> <?= e(__('Add')) ?></button></form>
            <?php else: ?><div class="small text-muted"><?= e(__('Every hotel you manage is already in a chain.')) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header"><?= e(__('Chain admins')) ?></div>
          <ul class="list-group list-group-flush">
            <?php if (!$admins): ?><li class="list-group-item text-muted small"><?= e(__('No chain admin yet.')) ?></li><?php endif; ?>
            <?php foreach ($admins as $a): ?>
              <li class="list-group-item d-flex align-items-center gap-2 small"><div class="flex-grow-1 min-w-0 text-truncate"><strong><?= e($a['full_name'] ?: $a['username']) ?></strong> · <?= e($a['username']) ?> · <?= e($a['email']) ?></div>
                <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="admin_status"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="active" value="<?= (int) $a['is_active'] ? 0 : 1 ?>">
                  <button class="btn btn-sm <?= (int) $a['is_active'] ? 'btn-outline-secondary' : 'btn-success' ?>"><?= e((int) $a['is_active'] ? __('Disable') : __('Enable')) ?></button></form></li>
            <?php endforeach; ?>
          </ul>
          <div class="card-body border-top">
            <form method="post" class="row g-2" autocomplete="off"><?= Csrf::field() ?><input type="hidden" name="op" value="add_admin"><input type="hidden" name="id" value="<?= $cid ?>">
              <div class="col-sm-6"><input class="form-control" name="admin_username" placeholder="<?= e(__('Username')) ?>" aria-label="<?= e(__('Username')) ?>" required pattern="[A-Za-z0-9_.\-]{3,50}"></div>
              <div class="col-sm-6"><input class="form-control" name="admin_name" placeholder="<?= e(__('Full name')) ?>" aria-label="<?= e(__('Full name')) ?>"></div>
              <div class="col-sm-6"><input class="form-control" type="email" name="admin_email" placeholder="<?= e(__('Email')) ?>" aria-label="<?= e(__('Email')) ?>" required></div>
              <div class="col-sm-6"><input class="form-control" type="password" name="admin_password" placeholder="<?= e(__('Password')) ?>" aria-label="<?= e(__('Password')) ?>" required autocomplete="new-password"></div>
              <div class="col-12"><button class="btn btn-outline-primary"><i class="bi bi-person-plus"></i> <?= e(__('Create chain admin')) ?></button></div>
            </form>
          </div>
        </div>
        <div class="card"><div class="card-header"><?= e(__('Chain access for hotel super admins')) ?></div>
          <div class="card-body small text-muted pb-0"><?= e(__('A super admin of a chain hotel with chain access also sees the chain dashboard and can enter the other hotels of the chain.')) ?></div>
          <ul class="list-group list-group-flush">
            <?php if (!$supers): ?><li class="list-group-item text-muted small"><?= e(__('No super admins in the chain hotels.')) ?></li><?php endif; ?>
            <?php foreach ($supers as $s): $has = (int) $s['chain_id'] === $cid; ?>
              <li class="list-group-item d-flex align-items-center gap-2 small"><div class="flex-grow-1 min-w-0 text-truncate"><strong><?= e($s['full_name'] ?: $s['username']) ?></strong> · <?= e($s['hotel_name']) ?></div>
                <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="<?= $has ? 'revoke' : 'grant' ?>"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm <?= $has ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= e($has ? __('Remove access') : __('Give access')) ?></button></form></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$chains = Chains::all($isReseller ? Chains::userChainIds() : null);
$pageTitle = __('Hotel chains');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Hotel chains')) ?></h1><p class="lead-sm"><?= e(__('One owner, many hotels: a chain admin sees all hotels of the chain in one dashboard.')) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('platform_chains.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New chain')) ?></a>
</div>
<div class="card">
<?php if (!$chains): ?>
  <div class="hc-empty"><i class="bi bi-diagram-3"></i><p class="mb-1"><strong><?= e(__('No chains yet')) ?></strong></p></div>
<?php else: ?>
  <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0">
    <thead><tr><th><?= e(__('Chain')) ?></th><th><?= e(__('Hotels')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Chain admins')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Reseller')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($chains as $c): ?>
      <tr><td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_chains.php', ['action' => 'view', 'id' => $c['id']])) ?>"><?= e($c['name']) ?></a><div class="small text-muted"><?= e((string) $c['owner_name']) ?></div></td>
        <td><?= (int) $c['hotel_count'] ?></td><td class="d-none d-md-table-cell"><?= (int) $c['admin_count'] ?></td>
        <td class="d-none d-lg-table-cell small"><?= e($c['reseller_name'] ?? '—') ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-primary" href="<?= e(admin_url('chain.php', ['chain' => $c['id']])) ?>"><i class="bi bi-speedometer2"></i> <span class="d-none d-sm-inline"><?= e(__('Dashboard')) ?></span></a>
          <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_chains.php', ['action' => 'edit', 'id' => $c['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
