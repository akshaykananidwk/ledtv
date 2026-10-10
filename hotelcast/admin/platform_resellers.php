<?php
/** Platform → Resellers (#22): partners who sell the service to hotels and earn a commission. */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $res = $id ? DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $id]) : null;
    if ($id && !$res) {
        redirect(admin_url('platform_resellers.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $errors] = Hotels::validateReseller($_POST);
            if (!$errors && isset($_FILES['brand_logo']) && is_array($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $up = Uploader::handle($_FILES['brand_logo'], 'logo', 'platform');
                    $data['brand_logo'] = $up['path'];
                } catch (RuntimeException $e) {
                    $errors[] = __('Logo') . ': ' . $e->getMessage();
                }
            } elseif ($res && !empty($_POST['remove_brand_logo'])) {
                $data['brand_logo'] = null;
            }
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('platform_resellers.php', $res ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            if ($res) {
                DB::update('resellers', $data, 'id = :id', ['id' => $id]);
                foreach (DB::column('SELECT id FROM hotels WHERE reseller_id = :r', ['r' => $id]) as $hid) {
                    Hotels::changed((int) $hid); // branding of their TVs may change
                }
            } else {
                $id = DB::insert('resellers', $data + ['created_at' => now()]);
            }
            if ($data['status'] !== 'active') {
                // Suspended reseller: end the sessions of its users.
                foreach (DB::column("SELECT id FROM users WHERE reseller_id = :r AND role = 'reseller'", ['r' => $id]) as $uid) {
                    Auth::revokeUserSessions((int) $uid);
                }
            }
            ActivityLog::add($res ? 'reseller_update' : 'reseller_create', 'reseller', $id, $data['name']);
            flash('success', __('Reseller ":n" saved.', ['n' => $data['name']]));
            redirect(admin_url('platform_resellers.php', ['action' => 'view', 'id' => $id]));

        case 'add_user':
            [$acc, $errors] = Hotels::validateAdmin($_POST, true);
            if ($errors) {
                flash_errors($errors);
            } else {
                $uid = DB::insert('users', [
                    'hotel_id' => null, 'reseller_id' => $id, 'role' => 'reseller',
                    'username' => $acc['username'], 'email' => $acc['email'], 'full_name' => $acc['full_name'],
                    'password_hash' => Auth::hash($acc['password']), 'language' => 'en', 'is_active' => 1, 'created_at' => now(),
                ]);
                ActivityLog::add('user_create', 'user', $uid, $acc['username'] . ' (reseller #' . $id . ')');
                if ($acc['invite'] && !PasswordReset::invite($uid)) {
                    flash('warning', __('The invite email could not be sent: :err', ['err' => Mailer::$lastError]));
                }
                flash('success', __('Reseller login :u created.', ['u' => $acc['username']]));
            }
            redirect(admin_url('platform_resellers.php', ['action' => 'view', 'id' => $id]));

        case 'toggle_user':
            $uid = req_int('user_id', $_POST);
            $u = DB::one("SELECT * FROM users WHERE id = :u AND reseller_id = :r AND role = 'reseller'", ['u' => $uid, 'r' => $id]);
            if ($u) {
                DB::update('users', ['is_active' => (int) $u['is_active'] ? 0 : 1], 'id = :id', ['id' => $uid]);
                Auth::revokeUserSessions($uid);
                flash('success', __('Saved.'));
            }
            redirect(admin_url('platform_resellers.php', ['action' => 'view', 'id' => $id]));
    }
    redirect(admin_url('platform_resellers.php'));
}

$action = req_str('action', $_GET, 20);
$activeNav = 'platform_resellers';

if ($action === 'new' || $action === 'edit') {
    $r = ['id' => 0, 'name' => '', 'contact_name' => '', 'email' => '', 'phone' => '', 'commission_percent' => '10', 'max_hotels' => null,
        'status' => 'active', 'brand_name' => '', 'brand_logo' => '', 'brand_color' => '', 'support_phone' => '', 'support_email' => '', 'notes' => ''];
    if ($action === 'edit') {
        $r = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$r) {
            redirect(admin_url('platform_resellers.php'));
        }
    }
    $v = fn (string $k) => e((string) ($r[$k] ?? ''));
    $pageTitle = $r['id'] ? __('Edit reseller') : __('New reseller');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1><a class="btn btn-light border" href="<?= e(admin_url('platform_resellers.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="col-lg-7"><div class="card"><div class="card-header"><?= e(__('Reseller')) ?></div><div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="r_n"><?= e(__('Company name')) ?> *</label><input class="form-control" id="r_n" name="name" value="<?= $v('name') ?>" required maxlength="150"></div>
        <div class="col-sm-6"><label class="form-label" for="r_cn"><?= e(__('Contact person')) ?></label><input class="form-control" id="r_cn" name="contact_name" value="<?= $v('contact_name') ?>"></div>
        <div class="col-sm-6"><label class="form-label" for="r_ph"><?= e(__('Phone / WhatsApp')) ?></label><input class="form-control" id="r_ph" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="col-sm-6"><label class="form-label" for="r_em"><?= e(__('Email')) ?></label><input class="form-control" type="email" id="r_em" name="email" value="<?= $v('email') ?>"></div>
        <div class="col-sm-3"><label class="form-label" for="r_c"><?= e(__('Commission %')) ?></label><input class="form-control" type="number" step="0.01" min="0" max="100" id="r_c" name="commission_percent" value="<?= $v('commission_percent') ?>"></div>
        <div class="col-sm-3"><label class="form-label" for="r_mh"><?= e(__('Customer allowance')) ?></label><input class="form-control" type="number" min="0" id="r_mh" name="max_hotels" value="<?= $v('max_hotels') ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
        <div class="col-sm-6"><label class="form-label" for="r_st"><?= e(__('Status')) ?></label><select class="form-select" id="r_st" name="status">
          <option value="active"<?= $r['status'] === 'active' ? ' selected' : '' ?>><?= e(__('Active')) ?></option><option value="suspended"<?= $r['status'] === 'suspended' ? ' selected' : '' ?>><?= e(__('Suspended')) ?></option></select></div>
        <div class="col-12"><label class="form-label" for="r_no"><?= e(__('Notes')) ?></label><textarea class="form-control" id="r_no" name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      </div></div></div>
      <div class="col-lg-5"><div class="card"><div class="card-header"><?= e(__('Branding for their customers')) ?></div><div class="card-body row g-3">
        <div class="col-sm-7"><label class="form-label" for="r_bn"><?= e(__('Product name')) ?></label><input class="form-control" id="r_bn" name="brand_name" value="<?= $v('brand_name') ?>" placeholder="Krishna Cloud TV Management"></div>
        <div class="col-sm-5"><label class="form-label" for="r_bc"><?= e(__('Colour')) ?></label><input class="form-control" id="r_bc" name="brand_color" value="<?= $v('brand_color') ?>" pattern="#[0-9A-Fa-f]{6}" placeholder="#7B1FA2"></div>
        <div class="col-sm-6"><label class="form-label" for="r_sp"><?= e(__('Support phone')) ?></label><input class="form-control" id="r_sp" name="support_phone" value="<?= $v('support_phone') ?>"></div>
        <div class="col-sm-6"><label class="form-label" for="r_se"><?= e(__('Support email')) ?></label><input class="form-control" type="email" id="r_se" name="support_email" value="<?= $v('support_email') ?>"></div>
        <div class="col-12"><label class="form-label" for="r_bl"><?= e(__('Logo')) ?></label><input class="form-control" type="file" id="r_bl" name="brand_logo" accept="image/png,image/jpeg,image/webp">
          <?php if (!empty($r['brand_logo'])): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $r['brand_logo'])) ?>" alt="" style="max-height:40px"><label class="small"><input type="checkbox" class="form-check-input" name="remove_brand_logo" value="1"> <?= e(__('Remove')) ?></label></div><?php endif; ?></div>
      </div></div></div>
      <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save reseller')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

if ($action === 'view') {
    $r = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => req_int('id', $_GET)]);
    if (!$r) {
        redirect(admin_url('platform_resellers.php'));
    }
    $hotels = Hotels::all((int) $r['id']);
    $users = DB::all("SELECT * FROM users WHERE reseller_id = :r AND role = 'reseller' ORDER BY username", ['r' => $r['id']]);
    $com = Billing::commission((int) $r['id']);
    $pageTitle = $r['name'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-person-badge"></i> <?= e($r['name']) ?> <?= $r['status'] === 'active' ? '' : Hotels::statusBadge('suspended') ?></h1>
        <p class="lead-sm"><?= e(__('Commission')) ?> <?= e((string) (float) $r['commission_percent']) ?>% · <?= e(__('Customers')) ?> <?= count($hotels) ?><?= $r['max_hotels'] !== null ? ' / ' . (int) $r['max_hotels'] : '' ?></p></div>
      <div class="d-flex gap-2"><a class="btn btn-primary" href="<?= e(admin_url('platform_resellers.php', ['action' => 'edit', 'id' => $r['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
        <a class="btn btn-light border" href="<?= e(admin_url('platform_resellers.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header"><?= e(__('Customers')) ?></div><div class="table-responsive"><table class="table table-sm table-hc mb-0">
          <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th></tr></thead><tbody>
          <?php if (!$hotels): ?><tr><td colspan="3" class="text-muted text-center py-3"><?= e(__('No customers yet.')) ?></td></tr><?php endif; ?>
          <?php foreach ($hotels as $h): ?><tr><td><a href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $h['id']])) ?>"><?= e($h['name']) ?></a></td><td><?= (int) $h['tv_count'] ?></td><td><?= Hotels::statusBadge($h['status']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div></div>
        <div class="card"><div class="card-header"><?= e(__('Commission (all time)')) ?></div><div class="card-body">
          <div class="row text-center"><div class="col"><div class="fs-5 fw-bold"><?= e(money($com['paid_total'])) ?></div><div class="small text-muted"><?= e(__('Paid invoices (net)')) ?></div></div>
            <div class="col"><div class="fs-5 fw-bold text-success"><?= e(money($com['commission'])) ?></div><div class="small text-muted"><?= e(__('Commission')) ?> (<?= e((string) $com['percent']) ?>%)</div></div></div>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><?= e(__('Reseller logins')) ?></div><ul class="list-group list-group-flush">
          <?php if (!$users): ?><li class="list-group-item text-muted small"><?= e(__('No login yet — add one below.')) ?></li><?php endif; ?>
          <?php foreach ($users as $u): ?><li class="list-group-item d-flex justify-content-between align-items-center small">
            <span><strong><?= e($u['username']) ?></strong> · <?= e($u['email']) ?><?= (int) $u['is_active'] ? '' : ' · <span class="text-danger">' . e(__('Disabled')) . '</span>' ?></span>
            <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle_user"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm btn-light border"><?= e((int) $u['is_active'] ? __('Disable') : __('Enable')) ?></button></form></li><?php endforeach; ?>
        </ul></div>
        <div class="card"><div class="card-header"><?= e(__('Add reseller login')) ?></div><div class="card-body">
          <form method="post" class="row g-2" autocomplete="off"><?= Csrf::field() ?><input type="hidden" name="op" value="add_user"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <div class="col-sm-6"><input class="form-control" name="admin_username" placeholder="<?= e(__('Username')) ?>" required></div>
            <div class="col-sm-6"><input class="form-control" name="admin_name" placeholder="<?= e(__('Full name')) ?>"></div>
            <div class="col-sm-6"><input class="form-control" type="email" name="admin_email" placeholder="<?= e(__('Email')) ?>" required></div>
            <div class="col-sm-6"><input class="form-control" type="password" id="rs_p" name="admin_password" placeholder="<?= e(__('Password')) ?>" autocomplete="new-password"></div>
            <div class="col-12"><?= invite_checkbox('rs_i', 'rs_p') ?></div>
            <div class="col-12"><button class="btn btn-outline-primary"><i class="bi bi-person-plus"></i> <?= e(__('Create login')) ?></button></div>
          </form></div></div>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$resellers = DB::all('SELECT r.*, (SELECT COUNT(*) FROM hotels h WHERE h.reseller_id = r.id) AS hotels FROM resellers r ORDER BY r.name');
$pageTitle = __('Resellers');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Resellers')) ?></h1><p class="lead-sm"><?= e(__('Partners who sell to customers. Commission = paid invoices of their customers (without tax) × commission %.')) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('platform_resellers.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New reseller')) ?></a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hc table-hover">
  <thead><tr><th><?= e(__('Reseller')) ?></th><th><?= e(__('Customers')) ?></th><th><?= e(__('Commission %')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Commission earned')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php if (!$resellers): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No resellers yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($resellers as $r): $c = Billing::commission((int) $r['id']); ?>
    <tr>
      <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_resellers.php', ['action' => 'view', 'id' => $r['id']])) ?>"><?= e($r['name']) ?></a><div class="small text-muted"><?= e(trim(($r['contact_name'] ?? '') . ' ' . ($r['phone'] ?? ''))) ?></div></td>
      <td><?= (int) $r['hotels'] ?><?= $r['max_hotels'] !== null ? ' / ' . (int) $r['max_hotels'] : '' ?></td>
      <td><?= e((string) (float) $r['commission_percent']) ?>%</td>
      <td class="d-none d-md-table-cell"><?= e(money($c['commission'])) ?></td>
      <td><?= Hotels::statusBadge($r['status'] === 'active' ? 'active' : 'suspended') ?></td>
      <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_resellers.php', ['action' => 'edit', 'id' => $r['id']])) ?>"><i class="bi bi-pencil"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
