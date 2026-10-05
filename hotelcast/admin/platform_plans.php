<?php
/** Platform → Plans: price per TV / month, TV limit and enabled modules (#19). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $plan = $id ? DB::one('SELECT * FROM plans WHERE id = :id', ['id' => $id]) : null;
    if ($op === 'save') {
        [$data, $errors] = Hotels::validatePlan($_POST);
        if (!$errors && DB::value('SELECT id FROM plans WHERE name = :n AND id <> :id', ['n' => $data['name'], 'id' => $id])) {
            $errors[] = __('A plan with this name already exists.');
        }
        if ($errors) {
            flash_errors($errors);
            redirect(admin_url('platform_plans.php', $plan ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
        }
        if ($plan) {
            DB::update('plans', $data, 'id = :id', ['id' => $id]);
            foreach (DB::column('SELECT id FROM hotels WHERE plan_id = :p', ['p' => $id]) as $hid) {
                Tenant::forget((int) $hid);
            }
        } else {
            $id = DB::insert('plans', $data + ['created_at' => now()]);
        }
        ActivityLog::add($plan ? 'plan_update' : 'plan_create', 'plan', $id, $data['name']);
        flash('success', __('Plan ":n" saved.', ['n' => $data['name']]));
    } elseif ($op === 'delete' && $plan) {
        $used = (int) DB::value('SELECT COUNT(*) FROM hotels WHERE plan_id = :p', ['p' => $id]);
        if ($used) {
            flash('danger', __('This plan is used by :n hotels. Deactivate it instead.', ['n' => $used]));
        } else {
            DB::delete('plans', 'id = :id', ['id' => $id]);
            ActivityLog::add('plan_delete', 'plan', $id, $plan['name']);
            flash('success', __('Plan deleted.'));
        }
    }
    redirect(admin_url('platform_plans.php'));
}

$action = req_str('action', $_GET, 20);
$activeNav = 'platform_plans';

if ($action === 'new' || $action === 'edit') {
    $p = ['id' => 0, 'name' => '', 'description' => '', 'price_per_tv_month' => '0', 'max_tvs' => null, 'features' => null, 'is_active' => 1];
    if ($action === 'edit') {
        $p = DB::one('SELECT * FROM plans WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$p) {
            redirect(admin_url('platform_plans.php'));
        }
    }
    $features = $p['features'] ? (json_decode((string) $p['features'], true) ?: []) : array_keys(Hotels::MODULES);
    $pageTitle = $p['id'] ? __('Edit plan') : __('New plan');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1><a class="btn btn-light border" href="<?= e(admin_url('platform_plans.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" class="card" style="max-width:760px"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div class="col-sm-6"><label class="form-label" for="p_n"><?= e(__('Plan name')) ?> *</label><input class="form-control" id="p_n" name="name" value="<?= e($p['name']) ?>" required maxlength="120"></div>
      <div class="col-sm-3"><label class="form-label" for="p_pr"><?= e(__('Price per TV / month')) ?></label>
        <div class="input-group"><span class="input-group-text"><?= e(Settings::platform('billing_currency', 'INR')) ?></span><input class="form-control" id="p_pr" name="price_per_tv_month" type="number" step="0.01" min="0" value="<?= e($p['price_per_tv_month']) ?>"></div></div>
      <div class="col-sm-3"><label class="form-label" for="p_mx"><?= e(__('Max TVs')) ?></label><input class="form-control" id="p_mx" name="max_tvs" type="number" min="0" value="<?= e((string) ($p['max_tvs'] ?? '')) ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
      <div class="col-12"><label class="form-label" for="p_d"><?= e(__('Description')) ?></label><input class="form-control" id="p_d" name="description" value="<?= e((string) $p['description']) ?>" maxlength="500"></div>
      <div class="col-12"><label class="form-label"><?= e(__('Modules included')) ?></label><div>
        <?php foreach (Hotels::MODULES as $k => $label): ?>
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="features[]" value="<?= e($k) ?>" id="f_<?= e($k) ?>"<?= in_array($k, $features, true) ? ' checked' : '' ?>><label class="form-check-label" for="f_<?= e($k) ?>"><?= e(__($label)) ?></label></div>
        <?php endforeach; ?></div>
        <div class="form-text"><?= e(__('Content, broadcast, schedules and TV control are always included.')) ?></div></div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="p_a" name="is_active" value="1"<?= (int) $p['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="p_a"><?= e(__('Available for new hotels')) ?></label></div></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save plan')) ?></button></div>
    </div></form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$plans = DB::all('SELECT p.*, (SELECT COUNT(*) FROM hotels h WHERE h.plan_id = p.id) AS hotels FROM plans p ORDER BY p.price_per_tv_month, p.name');
$pageTitle = __('Plans');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Plans')) ?></h1><p class="lead-sm"><?= e(__('Monthly invoice = active TVs × price per TV (+ tax).')) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('platform_plans.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New plan')) ?></a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hc table-hover">
    <thead><tr><th><?= e(__('Plan')) ?></th><th><?= e(__('Price per TV / month')) ?></th><th><?= e(__('Max TVs')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Modules')) ?></th><th><?= e(__('Hotels')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
    <tbody>
    <?php if (!$plans): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No plans yet.')) ?></td></tr><?php endif; ?>
    <?php foreach ($plans as $p): $f = $p['features'] ? (json_decode((string) $p['features'], true) ?: []) : null; ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong><?= (int) $p['is_active'] ? '' : ' <span class="badge text-bg-secondary">' . e(__('inactive')) . '</span>' ?><div class="small text-muted"><?= e((string) $p['description']) ?></div></td>
        <td><?= e(money($p['price_per_tv_month'])) ?></td>
        <td><?= $p['max_tvs'] !== null ? (int) $p['max_tvs'] : e(__('unlimited')) ?></td>
        <td class="d-none d-md-table-cell small"><?= $f === null ? e(__('All')) : e(implode(', ', array_map(fn ($k) => __(Hotels::MODULES[$k] ?? $k), $f))) ?></td>
        <td><?= (int) $p['hotels'] ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_plans.php', ['action' => 'edit', 'id' => $p['id']])) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Delete plan ":n"?', ['n' => $p['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"<?= (int) $p['hotels'] ? ' disabled' : '' ?>><i class="bi bi-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
