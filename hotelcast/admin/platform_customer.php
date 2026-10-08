<?php
/**
 * Customer detail (docs/modules/platform_screens.md § Customer detail): one customer (`hotels` row)
 * for the platform admin (any customer) or a reseller (own customers only), in tabs:
 *   overview  plan, status, limits usage (screens, users, storage), billing summary, created, reseller
 *   screens   the customer's TVs with the same columns / actions as Platform → All screens
 *   users     role, last login, active, screen access; reset password (+ email), (de)activate, enter
 *   activity  the customer's activity log
 * The plan / feature override form stays on platform_hotels.php (edit).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform_screens.php';

$user = Auth::require('platform.screens');
Csrf::check();

$id = req_int('id', $_GET) ?: req_int('id', $_POST);
$h = $id ? Hotels::find($id) : null;
if (!$h || !PlatformScreens::canSeeHotel($id)) {
    if ($id) {
        Logger::write('security', 'warning', 'Customer detail outside scope', ['user' => Auth::id(), 'hotel' => $id]);
    }
    http_response_code(404);
    exit(e(__('Customer not found.')));
}
$tabs = ['overview' => __('Overview'), 'screens' => __('Screens'), 'users' => __('Users'), 'activity' => __('Activity')];
$tab = req_str('tab', $_GET, 20);
$tab = isset($tabs[$tab]) ? $tab : 'overview';
$self = admin_url('platform_customer.php', ['id' => $id, 'tab' => $tab]);

/** A user of this customer that the platform may manage (hotel roles only), or null. */
$customerUser = static function (int $uid) use ($id): ?array {
    return DB::one("SELECT * FROM users WHERE id = :u AND hotel_id = :h AND role NOT IN ('platform_admin','reseller','chain_admin')", ['u' => $uid, 'h' => $id]);
};

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    if (in_array($op, ['enter', 'user_reset', 'user_toggle'], true)) {
        try {
            if ($op === 'enter') {
                if (Auth::enterHotel($id)) {
                    redirect(admin_url('index.php'));
                }
                throw new InvalidArgumentException(__('You cannot open this customer.'));
            }
            $u = $customerUser(req_int('user_id', $_POST));
            if (!$u) {
                throw new InvalidArgumentException(__('User not found.'));
            }
            if ($op === 'user_toggle') {
                $active = (int) $u['is_active'] ? 0 : 1;
                DB::query('UPDATE users SET is_active = :a, failed_attempts = 0, locked_until = NULL WHERE id = :id AND hotel_id = :h', ['a' => $active, 'id' => $u['id'], 'h' => $id]);
                if (!$active) {
                    Auth::revokeUserSessions((int) $u['id']);
                }
                ActivityLog::add($active ? 'user_activate' : 'user_deactivate', 'user', (int) $u['id'], $u['username'] . ' by the platform', $id);
                ActivityLog::add($active ? 'user_activate' : 'user_deactivate', 'user', (int) $u['id'], $u['username'] . ' of customer #' . $id, null);
                flash('success', $active ? __('User :u activated.', ['u' => $u['username']]) : __('User :u deactivated and logged out.', ['u' => $u['username']]));
            } else {
                $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
                $pw = '';
                for ($i = 0; $i < 8; $i++) {
                    $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $pw .= random_int(10, 99);
                DB::query('UPDATE users SET password_hash = :p, failed_attempts = 0, locked_until = NULL WHERE id = :id AND hotel_id = :h', ['p' => Auth::hash($pw), 'id' => $u['id'], 'h' => $id]);
                Auth::revokeUserSessions((int) $u['id']);
                ActivityLog::add('user_password_reset', 'user', (int) $u['id'], $u['username'] . ': password reset by the platform', $id);
                ActivityLog::add('user_password_reset', 'user', (int) $u['id'], $u['username'] . ' of customer #' . $id, null);
                $mailed = false;
                if (!empty($_POST['send_email']) && filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) {
                    $product = Branding::get($id)['product'];
                    $mailed = Notifier::email((string) $u['email'], __(':p — your login', ['p' => $product]),
                        __("Hello :n,\n\nyour :p account is ready.\n\nLogin: :url\nUsername: :u\nTemporary password: :pw\n\nPlease change the password after logging in (Profile).", [
                            'n' => $u['full_name'] ?: $u['username'], 'p' => $product, 'url' => admin_url('login.php', ['b' => $h['slug']]), 'u' => $u['username'], 'pw' => $pw,
                        ]));
                }
                flash('success', __('New password for :u: :pw (shown once).', ['u' => $u['username'], 'pw' => $pw])
                    . ($mailed ? ' ' . __('Sent to :e.', ['e' => $u['email']]) : ''));
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirect($self);
    }
    // Screens tab: the shared handler (commands, move, revoke, pool …).
    ps_handle_post(admin_url('platform_customer.php', ['id' => $id, 'tab' => 'screens'] + array_intersect_key($_GET, array_flip(['q', 'status', 'page', 'per_page']))));
}

$state = Tenant::state($id);
$pageTitle = $h['name'];
$activeNav = Auth::can('platform.manage') ? 'platform_hotels' : 'platform_screens';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-building"></i> <?= e($h['name']) ?> <?= Hotels::statusBadge($state) ?></h1>
    <p class="lead-sm"><?= e(dot_trim(__('Customer') . ' #' . $id . ' · ' . ($h['city'] ?? '') . ' · ' . ($h['plan_name'] ?? __('No plan')) . ($h['reseller_name'] ? ' · ' . __('Reseller') . ': ' . $h['reseller_name'] : ''))) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="enter"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Login as this customer')) ?></button></form>
    <?php if (Auth::can('platform.manage')): ?>
      <a class="btn btn-outline-primary" href="<?= e(admin_url('platform_hotels.php', ['action' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
      <a class="btn btn-light border" href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $id])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    <?php else: ?>
      <a class="btn btn-light border" href="<?= e(admin_url('reseller.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link<?= $k === $tab ? ' active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?> href="<?= e(admin_url('platform_customer.php', ['id' => $id, 'tab' => $k])) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php
// ------------------------------------------------------------------ overview
if ($tab === 'overview'):
    $tvs = Tenant::tvCount($id);
    $maxTvs = Tenant::maxTvs($id);
    $online = (int) DB::value("SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL AND status = 'online'", ['h' => $id]);
    $screens = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $id]);
    $userCount = (int) (PlatformScreens::usersPerCustomer([$id])[$id] ?? 0);
    $plan = $h['plan_id'] ? DB::one('SELECT * FROM plans WHERE id = :id', ['id' => (int) $h['plan_id']]) : null;
    $limit = static fn (string $k) => isset($h[$k]) && $h[$k] !== null && $h[$k] !== '' ? (int) $h[$k] : (isset($plan[$k]) && $plan[$k] !== null && $plan[$k] !== '' ? (int) $plan[$k] : null);
    $maxUsers = $limit('max_users');
    $maxStorage = $limit('max_storage_mb');
    $storage = (int) DB::value('SELECT COALESCE(SUM(file_size), 0) FROM content_items WHERE hotel_id = :h', ['h' => $id]);
    $pct = static fn (int $used, ?int $max): int => $max ? (int) min(100, round($used * 100 / max(1, $max))) : 0;
    $usage = [
        [__('Screens (TVs)'), ps_usage($tvs, $maxTvs), $pct($tvs, $maxTvs), __(':n online', ['n' => $online]) . ' · ' . __(':n screens set up', ['n' => $screens])],
        [__('Users'), ps_usage($userCount, $maxUsers), $pct($userCount, $maxUsers), ''],
        [__('Storage'), human_bytes($storage) . ($maxStorage ? ' / ' . human_bytes($maxStorage * 1048576) : ''), $pct((int) round($storage / 1048576), $maxStorage), ''],
    ];
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header"><?= e(__('Limits usage')) ?></div><div class="card-body">
          <?php foreach ($usage as [$label, $val, $p, $sub]): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between"><span><?= e($label) ?></span><strong><?= e($val) ?></strong></div>
              <div class="progress" style="height:6px" role="progressbar" aria-label="<?= e($label) ?>" aria-valuenow="<?= $p ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar<?= $p >= 90 ? ' bg-danger' : '' ?>" style="width:<?= $p ?>%"></div></div>
              <?php if ($sub !== ''): ?><div class="small text-muted"><?= e($sub) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><?= e(__('Details')) ?></div>
          <table class="table table-sm table-hc mb-0">
            <?php foreach ([
                __('Plan') => $h['plan_name'] ?? __('No plan'),
                __('Status') => Hotels::statusBadge($state),
                __('Valid until') => $h['expires_at'] ? date('d M Y', (int) strtotime((string) $h['expires_at'])) : __('No expiry'),
                __('Reseller') => $h['reseller_name'] ?? __('— Direct customer —'),
                __('Created') => (string) $h['created_at'],
                __('Contact person') => (string) ($h['contact_name'] ?? ''),
                __('Billing email') => (string) ($h['contact_email'] ?? ''),
            ] as $k => $v): if ($v === '') continue; ?>
              <tr><th class="ps-3 text-muted fw-normal" style="width:40%"><?= e($k) ?></th><td><?= $k === __('Status') ? $v : e($v) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php if (Auth::can('platform.manage')):
            $bill = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM invoices WHERE hotel_id = :h AND status = 'unpaid'", ['h' => $id]) ?? ['n' => 0, 'total' => 0];
            $last = DB::one('SELECT * FROM invoices WHERE hotel_id = :h ORDER BY id DESC LIMIT 1', ['h' => $id]);
            ?>
        <div class="card"><div class="card-header d-flex"><span><?= e(__('Billing')) ?></span>
            <a class="ms-auto small" href="<?= e(admin_url('platform_invoices.php', ['hotel' => $id])) ?>"><?= e(__('View all')) ?></a></div>
          <table class="table table-sm table-hc mb-0">
            <tr><th class="ps-3 text-muted fw-normal" style="width:40%"><?= e(__('Price per screen / month')) ?></th><td><?= e($h['price_per_tv_month'] !== null ? money($h['price_per_tv_month']) : '—') ?></td></tr>
            <tr><th class="ps-3 text-muted fw-normal"><?= e(__('Unpaid invoices')) ?></th><td><?= (int) $bill['n'] ?><?= (int) $bill['n'] ? ' · ' . e(money($bill['total'])) : '' ?></td></tr>
            <tr><th class="ps-3 text-muted fw-normal"><?= e(__('Last invoice')) ?></th><td><?php if ($last): ?><a href="<?= e(admin_url('invoice.php', ['id' => $last['id']])) ?>"><?= e($last['number']) ?></a> · <?= Billing::statusBadge($last) ?><?php else: ?>—<?php endif; ?></td></tr>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
<?php
// ------------------------------------------------------------------ screens
elseif ($tab === 'screens'):
    $f = PlatformScreens::filters($_GET);
    $f['customer'] = $id;
    $scope = PlatformScreens::scopeHotelIds();
    $res = PlatformScreens::list($f, $scope);
    $rows = PlatformScreens::decorate($res['rows']);
    $c = PlatformScreens::counters([$id]);
    $customers = PlatformScreens::customers();
    ?>
    <?= ps_counters($c, 'platform_customer.php', ['id' => $id, 'tab' => 'screens']) ?>
    <form method="get" class="d-flex flex-wrap gap-2 mb-3">
      <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="screens">
      <input class="form-control" style="max-width:18rem" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('Screen, device ID, model, IP…')) ?>" aria-label="<?= e(__('Search')) ?>">
      <select class="form-select" style="max-width:12rem" name="status" aria-label="<?= e(__('Status')) ?>">
        <option value=""><?= e(__('Active TVs')) ?></option>
        <?php foreach (['online' => __('Online'), 'offline' => __('Offline'), 'revoked' => __('Revoked'), 'any' => __('All incl. revoked')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-outline-primary"><i class="bi bi-search"></i> <?= e(__('Filter')) ?></button>
      <a class="btn btn-light border ms-auto" href="<?= e(admin_url('platform_screens.php', ['customer' => $id, 'export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> <?= e(__('Export CSV')) ?></a>
    </form>
    <div class="card"><?= ps_table($rows, false) ?>
      <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
    </div>
    <?= ps_bulk_bar($customers, $id) ?>
<?php
// ------------------------------------------------------------------ users
elseif ($tab === 'users'):
    $users = DB::all(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.last_login_at, u.locked_until, u.created_at,
                (SELECT COUNT(*) FROM user_access a WHERE a.user_id = u.id AND a.hotel_id = u.hotel_id AND a.target_type = 'room') AS acc_rooms,
                (SELECT COUNT(*) FROM user_access a WHERE a.user_id = u.id AND a.hotel_id = u.hotel_id AND a.target_type = 'group') AS acc_groups
         FROM users u WHERE u.hotel_id = :h AND u.role NOT IN ('platform_admin','reseller','chain_admin')
         ORDER BY FIELD(u.role, 'super_admin', 'manager', 'staff', 'reception'), u.username",
        ['h' => $id]
    );
    $roleText = static function (array $u): string {
        if (class_exists('Roles') && method_exists('Roles', 'specOf') && method_exists('Roles', 'specLabel')) {
            try {
                return Roles::specLabel(Roles::specOf($u));
            } catch (Throwable $e) {
            }
        }
        return role_label((string) $u['role']);
    };
    ?>
    <div class="card">
      <div class="table-responsive"><table class="table table-hc align-middle mb-0">
        <thead><tr><th><?= e(__('User')) ?></th><th><?= e(__('Role')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Last login')) ?></th><th><?= e(__('Active')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Screen access')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
        <tbody>
        <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No users yet.')) ?></td></tr><?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['full_name'] ?: $u['username']) ?></strong><div class="small text-muted"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
            <td><span class="badge text-bg-light border"><?= e($roleText($u)) ?></span></td>
            <td class="d-none d-md-table-cell small"><?= e($u['last_login_at'] ? time_ago((string) $u['last_login_at']) : __('never')) ?></td>
            <td><?= (int) $u['is_active'] ? '<span class="badge text-bg-success">' . e(__('Active')) . '</span>' : '<span class="badge text-bg-secondary">' . e(__('Disabled')) . '</span>' ?>
              <?php if ($u['locked_until'] && strtotime((string) $u['locked_until']) > time()): ?><span class="badge text-bg-warning"><?= e(__('Locked')) ?></span><?php endif; ?></td>
            <td class="d-none d-lg-table-cell small"><?= (int) $u['acc_rooms'] || (int) $u['acc_groups']
                ? e(__(':r screens, :g groups', ['r' => (int) $u['acc_rooms'], 'g' => (int) $u['acc_groups']]))
                : e(__('All screens')) ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline" data-confirm="<?= e(__('Create a new password for :u? The user is logged out everywhere.', ['u' => $u['username']])) ?>" data-confirm-safe="1">
                <?= Csrf::field() ?><input type="hidden" name="op" value="user_reset"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <label class="form-check form-check-inline small mb-0" title="<?= e(__('Email the login details to the user')) ?>"><input class="form-check-input" type="checkbox" name="send_email" value="1" checked> <?= e(__('Email')) ?></label>
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i> <?= e(__('Reset password')) ?></button></form>
              <form method="post" class="d-inline"<?= (int) $u['is_active'] ? ' data-confirm="' . e(__('Deactivate :u? The user is logged out and cannot log in.', ['u' => $u['username']])) . '"' : '' ?>>
                <?= Csrf::field() ?><input type="hidden" name="op" value="user_toggle"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-sm <?= (int) $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= e((int) $u['is_active'] ? __('Deactivate') : __('Activate')) ?></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="card-footer small text-muted"><?= e(__('To add users or change roles and screen access, use "Login as this customer" → Users.')) ?></div>
    </div>
<?php
// ------------------------------------------------------------------ activity
else:
    $page = max(1, req_int('page', $_GET));
    $per = 50;
    $total = (int) DB::value('SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h', ['h' => $id]);
    $logs = DB::all('SELECT * FROM activity_logs WHERE hotel_id = :h ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), ['h' => $id]);
    ?>
    <div class="card">
      <div class="table-responsive"><table class="table table-sm table-hc mb-0">
        <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('User')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('Details')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('IP')) ?></th></tr></thead>
        <tbody>
        <?php if (!$logs): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No activity yet.')) ?></td></tr><?php endif; ?>
        <?php foreach ($logs as $l): ?>
          <tr><td class="small text-nowrap"><?= e(substr((string) $l['created_at'], 0, 16)) ?></td><td class="small"><?= e($l['username'] ?? '-') ?></td>
            <td class="small mono"><?= e($l['action']) ?></td><td class="small text-break"><?= e($l['details'] ?? '') ?></td><td class="small d-none d-lg-table-cell"><?= e($l['ip_address'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($total > $per): ?><div class="card-footer"><?= paginate($total, $page, $per) ?></div><?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
