<?php
/**
 * Global search of the Super Admin console / Reseller panel (2.6, docs/modules/panels.md): one box in the
 * header finds customers (name, city, slug, contact e-mail), screens / TVs (screen name, device id, IP,
 * model), users (username, e-mail, name) and — platform admins only — resellers. Results are limited to
 * the customers the user may see (core/PlatformScreens.php scope); customer users get 403.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.screens');
Csrf::check();

$q = req_str('q', $_GET, 100);
$scope = PlatformScreens::scopeHotelIds();   // null = every customer (platform admin)
$isPlatform = Auth::can('platform.manage');
$results = ['customers' => [], 'screens' => [], 'users' => [], 'resellers' => []];
$limit = 25;

if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    [$sc, $sp] = PlatformScreens::scopeSql('h.id', $scope, 'sc');
    // Customers
    $results['customers'] = DB::all(
        'SELECT h.id, h.name, h.city, h.status, h.expires_at, h.is_trial, p.name AS plan_name, r.name AS reseller_name,
                (SELECT COUNT(*) FROM devices d WHERE d.hotel_id = h.id AND d.is_revoked = 0 AND d.room_id IS NOT NULL) AS tv_count
         FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id LEFT JOIN resellers r ON r.id = h.reseller_id
         WHERE (h.name LIKE :q1 OR h.slug LIKE :q2 OR h.city LIKE :q3 OR h.contact_email LIKE :q4 OR h.contact_phone LIKE :q5 OR h.id = :qid)' . $sc . ' ORDER BY h.name LIMIT ' . $limit,
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'qid' => ctype_digit($q) ? (int) $q : 0] + $sp
    );
    // Screens / TVs (same matching as Platform → All screens)
    $f = PlatformScreens::filters(['q' => $q, 'per_page' => 25, 'status' => 'any']);
    $results['screens'] = PlatformScreens::decorate(PlatformScreens::list($f, $scope)['rows'], false);
    // Users of customers in scope
    [$uc, $up] = PlatformScreens::scopeSql('u.hotel_id', $scope, 'us');
    $results['users'] = DB::all(
        "SELECT u.id, u.username, u.email, u.full_name, u.role, u.role_id, u.is_active, u.last_login_at, u.hotel_id, h.name AS hotel_name
         FROM users u JOIN hotels h ON h.id = u.hotel_id
         WHERE u.role NOT IN ('platform_admin','reseller','chain_admin') AND (u.username LIKE :q1 OR u.email LIKE :q2 OR u.full_name LIKE :q3)" . $uc . ' ORDER BY u.username LIMIT ' . $limit,
        ['q1' => $like, 'q2' => $like, 'q3' => $like] + $up
    );
    if ($isPlatform && License::mode() === 'saas') {
        $results['resellers'] = DB::all(
            'SELECT r.*, (SELECT COUNT(*) FROM hotels h WHERE h.reseller_id = r.id) AS customers
             FROM resellers r WHERE r.name LIKE :q1 OR r.email LIKE :q2 OR r.contact_name LIKE :q3 ORDER BY r.name LIMIT ' . $limit,
            ['q1' => $like, 'q2' => $like, 'q3' => $like]
        );
    }
}
$total = array_sum(array_map('count', $results));

$pageTitle = __('Search');
$activeNav = '';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-search"></i> <?= e(__('Global search')) ?></h1><p class="lead-sm"><?= e(__('Customers, screens, TVs (name, device ID, IP), users (e-mail) and resellers.')) ?></p></div>
</div>
<form method="get" class="d-flex gap-2 mb-3" role="search">
  <input class="form-control form-control-lg" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search customers, screens, TVs, users…')) ?>" aria-label="<?= e(__('Global search')) ?>" autofocus autocomplete="off" minlength="2">
  <button class="btn btn-primary btn-lg"><i class="bi bi-search"></i></button>
</form>

<?php if (mb_strlen($q) < 2): ?>
  <div class="card"><?= panel_empty('bi-search', __('Type at least 2 characters'), __('Examples: a customer name, a screen name, a device ID like tv-…, an IP address or a user\'s e-mail.')) ?></div>
<?php elseif ($total === 0): ?>
  <div class="card"><?= panel_empty('bi-emoji-neutral', __('Nothing found for ":q"', ['q' => $q]), __('Check the spelling or try a shorter term.')) ?></div>
<?php else: ?>
  <p class="text-muted small" data-search-total><?= e(__(':n result(s) for ":q"', ['n' => $total, 'q' => $q])) ?></p>

  <?php if ($results['customers']): ?>
  <div class="card search-group" data-search-group="customers">
    <div class="card-header"><i class="bi bi-buildings"></i> <?= e(__('Customers')) ?> <span class="badge text-bg-light border"><?= count($results['customers']) ?></span></div>
    <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('Customer')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Plan')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results['customers'] as $h): ?>
        <tr>
          <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $h['id']])) ?>"><?= e($h['name']) ?></a><div class="small text-muted">#<?= (int) $h['id'] ?> <?= e((string) $h['city']) ?><?= $h['reseller_name'] ? ' · ' . e($h['reseller_name']) : '' ?></div></td>
          <td class="d-none d-md-table-cell small"><?= e($h['plan_name'] ?? '—') ?></td>
          <td><?= (int) $h['tv_count'] ?></td>
          <td><?= Hotels::statusBadge(Tenant::state((int) $h['id'])) ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-sm btn-primary" href="<?= e(admin_url('platform_customer.php', ['id' => $h['id']])) ?>"><i class="bi bi-card-list"></i> <?= e(__('Customer 360')) ?></a> <?= panel_open_workspace((int) $h['id'], 'platform_customer.php', 'sm') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
  <?php endif; ?>

  <?php if ($results['screens']): ?>
  <div class="card search-group" data-search-group="screens">
    <div class="card-header"><i class="bi bi-tv"></i> <?= e(__('Screens & TVs')) ?> <span class="badge text-bg-light border"><?= count($results['screens']) ?></span></div>
    <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('Screen')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Device')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('IP')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results['screens'] as $d): ?>
        <tr>
          <td><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $d['hotel_id']])) ?>"><?= e($d['hotel_name']) ?></a></td>
          <td><strong><?= e($d['room_number'] ?? __('Without screen')) ?></strong><?= !empty($d['room_name']) ? '<div class="small text-muted">' . e($d['room_name']) . '</div>' : '' ?></td>
          <td class="d-none d-md-table-cell small"><span class="mono"><?= e($d['device_uid']) ?></span><div class="text-muted"><?= e((string) ($d['model'] ?? '')) ?> · <?= e((string) ($d['app_version'] ?? '')) ?></div></td>
          <td><?php if ((int) $d['is_revoked']): ?><span class="badge text-bg-secondary"><?= e(__('Revoked')) ?></span><?php else: ?><span class="badge <?= ($d['online'] ?? false) ? 'text-bg-success' : 'text-bg-danger' ?>"><?= e(($d['online'] ?? false) ? __('Online') : __('Offline')) ?></span><?php endif; ?></td>
          <td class="d-none d-lg-table-cell small mono"><?= e((string) ($d['ip_address'] ?? '')) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_customer.php', ['id' => $d['hotel_id'], 'tab' => 'screens', 'q' => $d['device_uid']])) ?>"><i class="bi bi-tv"></i> <?= e(__('Screens')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
  <?php endif; ?>

  <?php if ($results['users']): ?>
  <div class="card search-group" data-search-group="users">
    <div class="card-header"><i class="bi bi-people"></i> <?= e(__('Users')) ?> <span class="badge text-bg-light border"><?= count($results['users']) ?></span></div>
    <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('User')) ?></th><th><?= e(__('Customer')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Role')) ?></th><th><?= e(__('Active')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results['users'] as $u): ?>
        <tr>
          <td><strong><?= e($u['full_name'] ?: $u['username']) ?></strong><div class="small text-muted"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
          <td><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $u['hotel_id'], 'tab' => 'users'])) ?>"><?= e($u['hotel_name']) ?></a></td>
          <td class="d-none d-md-table-cell"><span class="badge text-bg-light border"><?= e(Auth::roleName($u)) ?></span></td>
          <td><?= panel_switch('user_active', (int) $u['id'], (bool) $u['is_active'], '', __('Deactivate this user? They are logged out and cannot log in.')) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_customer.php', ['id' => $u['hotel_id'], 'tab' => 'users'])) ?>"><i class="bi bi-people"></i> <?= e(__('Users')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
  <?php endif; ?>

  <?php if ($results['resellers']): ?>
  <div class="card search-group" data-search-group="resellers">
    <div class="card-header"><i class="bi bi-person-badge"></i> <?= e(__('Resellers')) ?> <span class="badge text-bg-light border"><?= count($results['resellers']) ?></span></div>
    <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0">
      <thead><tr><th><?= e(__('Reseller')) ?></th><th><?= e(__('Customers')) ?></th><th><?= e(__('Active')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results['resellers'] as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong><div class="small text-muted"><?= e((string) $r['contact_name']) ?> <?= e((string) $r['email']) ?></div></td>
          <td><?= (int) $r['customers'] ?></td>
          <td><?= panel_switch('reseller_status', (int) $r['id'], $r['status'] === 'active', '', __('Suspend this reseller? Its users are logged out and cannot log in.')) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_resellers.php', ['action' => 'edit', 'id' => $r['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
