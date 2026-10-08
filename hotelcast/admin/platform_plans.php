<?php
/**
 * Platform → Plans: price per TV / month, limits (screens, users, storage) and the features a plan
 * includes (2.5, core/Features.php, docs/modules/plans_features.md). Copy a plan, start from a
 * ready-made preset. Adding a plan is data — code only ever asks Features for capabilities.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

/** Forget cached entitlements of every customer on a plan (TVs refresh their content). */
$plansChanged = static function (int $planId): void {
    foreach (DB::column('SELECT id FROM hotels WHERE plan_id = :p', ['p' => $planId]) as $hid) {
        Hotels::changed((int) $hid);
    }
    Features::forget();
};

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $plan = $id ? DB::one('SELECT * FROM plans WHERE id = :id', ['id' => $id]) : null;
    if ($id && !$plan) {
        flash('warning', __('Plan not found.'));
        redirect(admin_url('platform_plans.php'));
    }
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
            $plansChanged($id);
        } else {
            $id = DB::insert('plans', $data + ['created_at' => now()]);
        }
        ActivityLog::add($plan ? 'plan_update' : 'plan_create', 'plan', $id, $data['name']);
        flash('success', __('Plan ":n" saved.', ['n' => $data['name']]));
    } elseif ($op === 'copy' && $plan) {
        $base = mb_substr(__('Copy of :n', ['n' => $plan['name']]), 0, 110);
        $name = $base;
        for ($i = 2; DB::value('SELECT id FROM plans WHERE name = :n', ['n' => $name]) && $i < 100; $i++) {
            $name = $base . ' (' . $i . ')';
        }
        $copy = array_intersect_key($plan, array_flip(['description', 'price_per_tv_month', 'max_tvs', 'max_users', 'storage_mb', 'features']));
        $newId = DB::insert('plans', ['name' => $name, 'is_active' => 0, 'created_at' => now()] + $copy);
        ActivityLog::add('plan_copy', 'plan', $newId, $plan['name'] . ' → ' . $name);
        flash('success', __('Plan copied as ":n" (not yet available for new customers).', ['n' => $name]));
        redirect(admin_url('platform_plans.php', ['action' => 'edit', 'id' => $newId]));
    } elseif ($op === 'delete' && $plan) {
        $used = (int) DB::value('SELECT COUNT(*) FROM hotels WHERE plan_id = :p', ['p' => $id]);
        if ($used) {
            flash('danger', __('This plan is used by :n customers. Deactivate it instead.', ['n' => $used]));
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
    $p = ['id' => 0, 'name' => '', 'description' => '', 'price_per_tv_month' => '0', 'max_tvs' => null, 'max_users' => null, 'storage_mb' => null, 'features' => null, 'is_active' => 1];
    if ($action === 'edit') {
        $p = DB::one('SELECT * FROM plans WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$p) {
            redirect(admin_url('platform_plans.php'));
        }
    }
    $features = Features::planKeys($p['features']);
    $presets = [];
    foreach (array_keys(Features::PRESETS) as $pn) {
        $presets[$pn] = Features::presetKeys($pn);
    }
    $pageTitle = $p['id'] ? __('Edit plan') : __('New plan');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head"><h1><?= e($pageTitle) ?></h1><a class="btn btn-light border" href="<?= e(admin_url('platform_plans.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a></div>
    <form method="post" class="card" id="planForm"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div class="col-md-6"><label class="form-label" for="p_n"><?= e(__('Plan name')) ?> *</label><input class="form-control" id="p_n" name="name" value="<?= e($p['name']) ?>" required maxlength="120"></div>
      <div class="col-md-6"><label class="form-label" for="p_pr"><?= e(__('Price per TV / month')) ?></label>
        <div class="input-group"><span class="input-group-text"><?= e(Settings::platform('billing_currency', 'INR')) ?></span><input class="form-control" id="p_pr" name="price_per_tv_month" type="number" step="0.01" min="0" value="<?= e((string) $p['price_per_tv_month']) ?>"></div></div>
      <div class="col-12"><label class="form-label" for="p_d"><?= e(__('Description')) ?></label><input class="form-control" id="p_d" name="description" value="<?= e((string) $p['description']) ?>" maxlength="500"></div>
      <div class="col-sm-4"><label class="form-label" for="p_mx"><?= e(__('Max screens')) ?></label><input class="form-control" id="p_mx" name="max_tvs" type="number" min="0" value="<?= e((string) ($p['max_tvs'] ?? '')) ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
      <div class="col-sm-4"><label class="form-label" for="p_mu"><?= e(__('Max users')) ?></label><input class="form-control" id="p_mu" name="max_users" type="number" min="0" value="<?= e((string) ($p['max_users'] ?? '')) ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
      <div class="col-sm-4"><label class="form-label" for="p_st"><?= e(__('Storage (MB)')) ?></label><input class="form-control" id="p_st" name="storage_mb" type="number" min="0" value="<?= e((string) ($p['storage_mb'] ?? '')) ?>" placeholder="<?= e(__('unlimited')) ?>"></div>
      <div class="col-12">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <label class="form-label mb-0 me-2"><?= e(__('Features included')) ?></label>
          <span class="small text-muted"><?= e(__('Start from:')) ?></span>
          <?php foreach ($presets as $pn => $keys): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= e(json_embed($keys)) ?>"><?= e(__($pn)) ?></button>
          <?php endforeach; ?>
        </div>
        <?= features_checkbox_groups('features', $features, 'pf') ?>
        <div class="form-text"><?= e(__('Always included: dashboard, screens, groups, push content now, users, settings, logs. All features ticked = everything, also features added in future updates.')) ?></div>
      </div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="p_a" name="is_active" value="1"<?= (int) $p['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="p_a"><?= e(__('Available for new customers')) ?></label></div></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save plan')) ?></button></div>
    </div></form>
    <script>
    document.querySelectorAll('[data-preset]').forEach(function (b) {
      b.addEventListener('click', function () {
        var keys = JSON.parse(b.getAttribute('data-preset'));
        document.querySelectorAll('#planForm input[name="features[]"]').forEach(function (c) { c.checked = keys.indexOf(c.value) !== -1; });
      });
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$plans = DB::all('SELECT p.*, (SELECT COUNT(*) FROM hotels h WHERE h.plan_id = p.id) AS hotels FROM plans p ORDER BY p.price_per_tv_month, p.name');
$optional = count(Features::optionalKeys());
$pageTitle = __('Plans');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Plans')) ?></h1><p class="lead-sm"><?= e(__('Monthly invoice = active TVs × price per TV (+ tax).')) ?> <?= e(__('The plan decides which features and limits a customer gets.')) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('platform_plans.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New plan')) ?></a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hc table-hover">
    <thead><tr><th><?= e(__('Plan')) ?></th><th><?= e(__('Price per TV / month')) ?></th><th><?= e(__('Limits')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Features')) ?></th><th><?= e(__('Customers')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
    <tbody>
    <?php if (!$plans): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No plans yet.')) ?></td></tr><?php endif; ?>
    <?php foreach ($plans as $p): $keys = Features::planKeys($p['features']); $unl = __('unlimited'); ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong><?= (int) $p['is_active'] ? '' : ' <span class="badge text-bg-secondary">' . e(__('inactive')) . '</span>' ?><div class="small text-muted"><?= e((string) $p['description']) ?></div></td>
        <td><?= e(money($p['price_per_tv_month'])) ?></td>
        <td class="small text-nowrap">
          <div><i class="bi bi-tv"></i> <?= $p['max_tvs'] !== null ? (int) $p['max_tvs'] : e($unl) ?></div>
          <div><i class="bi bi-people"></i> <?= ($p['max_users'] ?? null) !== null ? (int) $p['max_users'] : e($unl) ?></div>
          <div><i class="bi bi-hdd"></i> <?= ($p['storage_mb'] ?? null) !== null ? (int) $p['storage_mb'] . ' MB' : e($unl) ?></div>
        </td>
        <td class="d-none d-md-table-cell small"><?= count($keys) >= $optional ? e(__('All')) : e(__(':n of :t features', ['n' => count($keys), 't' => $optional])) . '<div class="text-muted">' . e(implode(', ', array_map([Features::class, 'label'], array_slice($keys, 0, 8)))) . (count($keys) > 8 ? ' …' : '') . '</div>' ?></td>
        <td><?= (int) $p['hotels'] ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_plans.php', ['action' => 'edit', 'id' => $p['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="copy"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" title="<?= e(__('Copy plan')) ?>"><i class="bi bi-copy"></i> <span class="d-none d-lg-inline"><?= e(__('Copy plan')) ?></span></button></form>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Delete plan ":n"?', ['n' => $p['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"<?= (int) $p['hotels'] ? ' disabled' : '' ?>><i class="bi bi-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
