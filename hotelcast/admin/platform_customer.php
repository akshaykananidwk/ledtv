<?php
/**
 * Customer 360 (2.6 docs/modules/panels.md, 2.5 docs/modules/platform_screens.md § Customer detail): one
 * customer (`hotels` row) for the Super Admin console (any customer) or the Reseller panel (own customers),
 * in tabs — everything routine is done here WITHOUT opening the customer's workspace:
 *   summary   status, plan, limits usage, contacts, billing summary, quick actions
 *   plan      plan, limits and every feature switched on / off for this customer (platform only)
 *   screens   the customer's TVs with the same columns / actions as Platform → All screens (+ screen switches)
 *   users     create, role, reset password (+ e-mail), enable / disable
 *   content   what is assigned (default content, per screen, counts) with links into the workspace
 *   billing   invoices, licenses, price, expiry (platform only)
 *   activity  the customer's activity log
 *   settings  status switch, registration key, chain, branding override, danger zone (platform only)
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform_screens.php';
require_once __DIR__ . '/partials/panel_ui.php';
require_once __DIR__ . '/partials/features_ui.php';

$user = Auth::require('platform.screens');
Csrf::check();
ActivityLog::$platformScope = true;

$id = req_int('id', $_GET) ?: req_int('id', $_POST);
$h = $id ? Hotels::find($id) : null;
if (!$h || !PlatformScreens::canSeeHotel($id)) {
    if ($id) {
        Logger::write('security', 'warning', 'Customer detail outside scope', ['user' => Auth::id(), 'hotel' => $id]);
    }
    http_response_code(404);
    exit(e(__('Customer not found.')));
}
$isPlatform = Auth::can('platform.manage');
$saas = License::mode() === 'saas';
$tabs = ['summary' => [__('Summary'), 'bi-card-list']];
if ($isPlatform) {
    $tabs['plan'] = [__('Plan & features'), 'bi-box-seam'];
}
$tabs['screens'] = [__('Screens'), 'bi-tv'];
$tabs['users'] = [__('Users'), 'bi-people'];
$tabs['content'] = [__('Content'), 'bi-collection-play'];
if ($isPlatform && $saas) {
    $tabs['billing'] = [__('Billing'), 'bi-receipt'];
}
$tabs['activity'] = [__('Activity'), 'bi-activity'];
if ($isPlatform) {
    $tabs['settings'] = [__('Settings'), 'bi-gear'];
}
$tab = req_str('tab', $_GET, 20);
$tab = $tab === 'overview' ? 'summary' : $tab; // 2.5 links
$tab = isset($tabs[$tab]) ? $tab : 'summary';
$self = admin_url('platform_customer.php', ['id' => $id, 'tab' => $tab]);
$url = static fn (string $t, array $q = []) => admin_url('platform_customer.php', ['id' => $id, 'tab' => $t] + $q);

/** A user of this customer that the platform may manage (customer roles only), or null. */
$customerUser = static function (int $uid) use ($id): ?array {
    return DB::one("SELECT * FROM users WHERE id = :u AND hotel_id = :h AND role NOT IN ('platform_admin','reseller','chain_admin')", ['u' => $uid, 'h' => $id]);
};
/** Custom roles of this customer (2.5 roles). */
$customRoles = static fn (): array => Roles::available() ? DB::all('SELECT id, name, base_level FROM roles WHERE hotel_id = :h ORDER BY name', ['h' => $id]) : [];
/** ['super_admin', null] / ['staff', 12] from a role spec, limited to this customer. */
$parseRole = static function (string $spec) use ($id): ?array {
    if (in_array($spec, Auth::HOTEL_ROLES, true)) {
        return [$spec, null];
    }
    if (preg_match('/^role:(\d{1,9})$/', $spec, $m) && Roles::available()) {
        $r = DB::one('SELECT id, base_level FROM roles WHERE id = :id AND hotel_id = :h', ['id' => (int) $m[1], 'h' => $id]);
        return $r ? [(string) $r['base_level'], (int) $r['id']] : null;
    }
    return null;
};

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $platformOps = ['limits', 'status', 'regen_key', 'set_chain', 'branding', 'delete', 'extend', 'archive', 'restore'];
    $scopedOps = ['enter', 'user_reset', 'user_toggle', 'user_create', 'user_role'];
    if (in_array($op, $platformOps, true)) {
        require_can('platform.manage');
    }
    if (in_array($op, $platformOps, true) || in_array($op, $scopedOps, true)) {
        try {
            switch ($op) {
                case 'enter':
                    if (!Auth::enterHotel($id)) {
                        throw new InvalidArgumentException(__('You cannot open this customer.'));
                    }
                    $next = req_str('next', $_POST, 60);
                    redirect(admin_url(preg_match('/^[a-z_]+\.php$/', $next) && is_file(__DIR__ . '/' . $next) ? $next : 'index.php'));

                case 'user_create':
                    [$acc, $errors] = Hotels::validateAdmin($_POST, true);
                    $role = $parseRole(req_str('role', $_POST, 20));
                    if ($role === null) {
                        $errors[] = __('Choose a role.');
                    }
                    if ($err = Features::userLimitError(1, $id)) {
                        $errors[] = $err;
                    }
                    if ($errors) {
                        flash_errors($errors);
                        redirect($url('users'));
                    }
                    if (!empty($_POST['language']) && isset(I18n::LANGUAGES[$_POST['language']])) {
                        $acc['language'] = $_POST['language']; // the invite e-mail is written in it
                    }
                    $uid = Hotels::createHotelUser($id, $acc, $role[0]);
                    if ($role[1] !== null) {
                        DB::query('UPDATE users SET role_id = :r WHERE id = :id AND hotel_id = :h', ['r' => $role[1], 'id' => $uid, 'h' => $id]);
                    }
                    if (!empty($_POST['language']) && isset(I18n::LANGUAGES[$_POST['language']])) {
                        DB::query('UPDATE users SET language = :l WHERE id = :id AND hotel_id = :h', ['l' => $_POST['language'], 'id' => $uid, 'h' => $id]);
                    }
                    ActivityLog::add('user_create', 'user', $uid, $acc['username'] . ' (' . req_str('role', $_POST, 20) . ' of customer #' . $id . ')');
                    ActivityLog::add('user_create', 'user', $uid, $acc['username'] . ' created by the platform', $id);
                    $mailed = false;
                    if ($acc['invite']) {
                        $ok = PasswordReset::$lastInviteSent === true;
                        flash($ok ? 'success' : 'warning', __('User :u created.', ['u' => $acc['username']]) . ' '
                            . ($ok ? __('Invite link sent to :e.', ['e' => $acc['email']]) : __('The invite email could not be sent: :err', ['err' => Mailer::$lastError])));
                        redirect($url('users'));
                    }
                    if (!empty($_POST['send_email'])) {
                        $product = Branding::get($id)['product'];
                        $mailed = Notifier::email($acc['email'], __(':p — your login', ['p' => $product]),
                            __("Hello :n,\n\nyour :p account is ready.\n\nLogin: :url\nUsername: :u\nTemporary password: :pw\n\nPlease change the password after logging in (Profile).", [
                                'n' => $acc['full_name'], 'p' => $product, 'url' => admin_url('login.php', ['b' => $h['slug']]), 'u' => $acc['username'], 'pw' => $acc['password'],
                            ]));
                    }
                    flash('success', __('User :u created.', ['u' => $acc['username']]) . ($mailed ? ' ' . __('Sent to :e.', ['e' => $acc['email']]) : ''));
                    redirect($url('users'));

                case 'limits':
                    $errors = [];
                    $data = [];
                    $plans = array_map('intval', array_column(Hotels::plans(false), 'id'));
                    $planId = req_int('plan_id', $_POST);
                    if ($planId && !in_array($planId, $plans, true)) {
                        $errors[] = __('Unknown plan.');
                    }
                    $data['plan_id'] = $planId ?: null;
                    $data['max_tvs'] = Features::limitInput($_POST['max_tvs'] ?? '', __('Max TVs'), $errors, 100000);
                    $data['max_users'] = Features::limitInput($_POST['max_users'] ?? '', __('Max users'), $errors, 100000);
                    $data['storage_mb'] = Features::limitInput($_POST['storage_mb'] ?? '', __('Storage (MB)'), $errors);
                    $exp = req_str('expires_at', $_POST, 10);
                    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                        $errors[] = __('Valid until: enter a date.');
                    }
                    $data['expires_at'] = $exp !== '' ? $exp . ' 23:59:59' : null;
                    if ($errors) {
                        flash_errors($errors);
                        redirect($url('plan'));
                    }
                    Hotels::update($id, $data);
                    Features::forget();
                    ActivityLog::add('hotel_update', 'hotel', $id, $h['name'] . ': plan / limits');
                    ActivityLog::add('hotel_update', 'hotel', $id, 'Plan / limits changed by the platform', $id);
                    flash('success', __('Saved.'));
                    redirect($url('plan'));

                case 'status':
                    $status = req_str('status', $_POST, 20);
                    Hotels::setStatus($id, $status, 'manual');
                    ActivityLog::add('hotel_status', 'hotel', $id, $h['name'] . ' → ' . $status);
                    ActivityLog::add('hotel_status', 'hotel', $id, 'Account ' . $status . ' by the platform', $id);
                    flash('success', __('Customer ":n" is now :s.', ['n' => $h['name'], 's' => __(ucfirst($status))]));
                    redirect($url('settings'));

                case 'extend':
                    $days = max(1, min(365, req_int('days', $_POST) ?: 30));
                    $base = $h['expires_at'] && strtotime((string) $h['expires_at']) > time() ? strtotime((string) $h['expires_at']) : time();
                    Hotels::update($id, ['expires_at' => date('Y-m-d 23:59:59', $base + $days * 86400)]);
                    if ($h['status'] === 'expired') {
                        Hotels::setStatus($id, 'active', null);
                    }
                    ActivityLog::add('hotel_extend', 'hotel', $id, $h['name'] . ' +' . $days . ' days');
                    ActivityLog::add('hotel_extend', 'hotel', $id, 'Validity extended by ' . $days . ' days', $id);
                    flash('success', __('Validity extended by :n days.', ['n' => $days]));
                    redirect($url('billing'));

                case 'archive':
                    Hotels::archive($id);
                    ActivityLog::add('hotel_archive', 'hotel', $id, $h['name'] . ' archived');
                    ActivityLog::add('hotel_archive', 'hotel', $id, 'Account archived by the platform', $id);
                    flash('success', __('Customer ":n" archived. It is suspended and hidden from the list; restore it any time.', ['n' => $h['name']]));
                    redirect(admin_url('platform_hotels.php'));

                case 'restore':
                    Hotels::restore($id);
                    ActivityLog::add('hotel_restore', 'hotel', $id, $h['name'] . ' restored');
                    ActivityLog::add('hotel_restore', 'hotel', $id, 'Account restored by the platform', $id);
                    flash('success', __('Customer ":n" restored and active again.', ['n' => $h['name']]));
                    redirect($url('summary'));

                case 'regen_key':
                    Settings::setFor($id, 'registration_key', Hotels::newRegistrationKey());
                    ActivityLog::add('registration_key_regen', 'hotel', $id, 'Registration key regenerated by platform');
                    flash('success', __('New registration key created. TVs that are already set up keep working; new TVs need the new key.'));
                    redirect($url('settings'));

                case 'set_chain':
                    Chains::requireEnabled();
                    $chainId = req_int('chain_id', $_POST);
                    if ($h['chain_id'] && (int) $h['chain_id'] !== $chainId) {
                        Chains::assignHotel((int) $h['chain_id'], $id, false);
                    }
                    if ($chainId) {
                        Chains::assignHotel($chainId, $id, true);
                    }
                    ActivityLog::add('chain_hotel_set', 'hotel', $id, $h['name'] . ' → chain #' . $chainId);
                    flash('success', __('Saved.'));
                    redirect($url('settings'));

                case 'branding':
                    $data = ['brand_name' => req_str('brand_name', $_POST, 120) ?: null];
                    $color = req_str('brand_color', $_POST, 7);
                    if ($color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                        throw new InvalidArgumentException(__('Colour: use the form #RRGGBB.'));
                    }
                    $data['brand_color'] = $color ?: null;
                    if (isset($_FILES['brand_logo']) && is_array($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $up = Uploader::handle($_FILES['brand_logo'], 'logo', 'platform');
                        $data['brand_logo'] = $up['path'];
                        if ($up['thumb']) {
                            Uploader::delete($up['thumb']);
                        }
                    } elseif (!empty($_POST['remove_brand_logo'])) {
                        $data['brand_logo'] = null;
                    }
                    Hotels::update($id, $data);
                    ActivityLog::add('hotel_update', 'hotel', $id, $h['name'] . ': branding');
                    flash('success', __('Saved.'));
                    redirect($url('settings'));

                case 'delete':
                    if (req_str('confirm_name', $_POST, 120) !== (string) $h['name']) {
                        throw new InvalidArgumentException(__('Type the customer name exactly to confirm the deletion.'));
                    }
                    if (Auth::inEnteredHotel() && (int) ($_SESSION['hc_hotel'] ?? 0) === $id) {
                        Auth::leaveHotel();
                    }
                    $removed = Hotels::delete($id);
                    ActivityLog::add('hotel_delete', 'hotel', $id, $h['name'] . ' deleted: ' . json_out(array_diff_key($removed, ['rows' => 1])));
                    flash('success', Hotels::deleteSummary($removed));
                    redirect(admin_url('platform_hotels.php'));

                case 'user_role':
                    $u = $customerUser(req_int('user_id', $_POST));
                    $role = $parseRole(req_str('role', $_POST, 20));
                    if (!$u || $role === null) {
                        throw new InvalidArgumentException(__('User not found.'));
                    }
                    DB::query('UPDATE users SET role = :r, role_id = :rid WHERE id = :id AND hotel_id = :h', ['r' => $role[0], 'rid' => $role[1], 'id' => $u['id'], 'h' => $id]);
                    Auth::revokeUserSessions((int) $u['id']);
                    Auth::forgetPermissions();
                    ActivityLog::add('user_update', 'user', (int) $u['id'], $u['username'] . ' → ' . req_str('role', $_POST, 20) . ' (customer #' . $id . ')');
                    ActivityLog::add('user_update', 'user', (int) $u['id'], $u['username'] . ': role changed by the platform', $id);
                    flash('success', __('Role of :u changed. The user is logged out.', ['u' => $u['username']]));
                    redirect($url('users'));

                case 'user_toggle':
                case 'user_reset':
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
                    redirect($url('users'));
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
$tvs = Tenant::tvCount($id);
$online = (int) DB::value("SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL AND status = 'online'", ['h' => $id]);
$screens = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $id]);
$userCount = (int) (PlatformScreens::usersPerCustomer([$id])[$id] ?? 0);
$lim = Features::limits($id);
$initial = mb_strtoupper(mb_substr(trim((string) $h['name']), 0, 1)) ?: '#';
$pageTitle = $h['name'];
$activeNav = $isPlatform ? 'platform_hotels' : 'reseller';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div class="c360-head">
    <span class="c360-avatar"><?= e($initial) ?></span>
    <div>
      <h1 class="d-flex align-items-center flex-wrap gap-2"><?= e($h['name']) ?> <span data-status-badge="<?= $id ?>"><?= Hotels::statusBadge($state) ?></span><?php if ($h['is_trial']): ?><span class="badge text-bg-info"><?= e(__('Trial')) ?></span><?php endif; ?><?php if (!empty($h['archived_at'])): ?><span class="badge text-bg-secondary" data-archived><?= e(__('Archived')) ?></span><?php endif; ?></h1>
      <div class="c360-meta">
        <span><i class="bi bi-hash"></i><?= $id ?></span>
        <?php if ($h['city']): ?><span><i class="bi bi-geo-alt"></i> <?= e($h['city']) ?></span><?php endif; ?>
        <span><i class="bi bi-box-seam"></i> <?= e($h['plan_name'] ?? __('No plan')) ?></span>
        <span><i class="bi bi-tv"></i> <span class="text-success"><?= $online ?></span> / <?= $tvs ?> <?= e(__('online')) ?></span>
        <span><i class="bi bi-people"></i> <?= $userCount ?> <?= e(__('users')) ?></span>
        <?php if ($h['reseller_name']): ?><span><i class="bi bi-person-badge"></i> <?= e($h['reseller_name']) ?></span><?php endif; ?>
        <?php if ($h['expires_at']): ?><span><i class="bi bi-calendar-event"></i> <?= e(__('Valid until')) ?> <?= e(date('d M Y', (int) strtotime((string) $h['expires_at']))) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($isPlatform): ?><?= panel_switch('customer_status', $id, $state === 'active', $state === 'active' ? __('Active') : __('Suspended'), __('Suspend this customer? Its TVs show the "service paused" screen and its admin panel becomes read-only.'), '', ['state-label' => 1], $state === 'expired') ?><?php endif; ?>
    <?= panel_open_workspace($id) ?>
    <a class="btn btn-light border" href="<?= e(admin_url($isPlatform ? 'platform_hotels.php' : 'reseller.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
  </div>
</div>

<ul class="nav nav-tabs nav-tabs-c360 mb-3" role="tablist" data-c360-tabs>
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <li class="nav-item"><a class="nav-link<?= $k === $tab ? ' active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?> href="<?= e($url($k)) ?>" data-tab="<?= e($k) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php
// ------------------------------------------------------------------ summary
if ($tab === 'summary'):
    $storage = Features::storageUsed($id);
    $pct = static fn (int $used, ?int $max): int => $max ? (int) min(100, round($used * 100 / max(1, $max))) : 0;
    $usage = [
        [__('Screens (TVs)'), ps_usage($tvs, $lim['max_screens']), $pct($tvs, $lim['max_screens']), __(':n online', ['n' => $online]) . ' · ' . __(':n screens set up', ['n' => $screens]), $url('screens')],
        [__('Users'), ps_usage($userCount, $lim['max_users']), $pct($userCount, $lim['max_users']), '', $url('users')],
        [__('Storage'), human_bytes($storage) . ($lim['storage_mb'] ? ' / ' . human_bytes($lim['storage_mb'] * 1048576) : ''), $pct((int) round($storage / 1048576), $lim['storage_mb']), '', $url('content')],
    ];
    $lastActivity = DB::all('SELECT * FROM activity_logs WHERE hotel_id = :h ORDER BY id DESC LIMIT 6', ['h' => $id]);
    $off = Features::disabledKeys($id);
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header"><?= e(__('Limits usage')) ?></div><div class="card-body">
          <?php foreach ($usage as [$label, $val, $p, $sub, $link]): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between"><a class="text-decoration-none" href="<?= e($link) ?>"><?= e($label) ?></a><strong><?= e($val) ?></strong></div>
              <div class="progress" style="height:6px" role="progressbar" aria-label="<?= e($label) ?>" aria-valuenow="<?= $p ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar<?= $p >= 90 ? ' bg-danger' : '' ?>" style="width:<?= $p ?>%"></div></div>
              <?php if ($sub !== ''): ?><div class="small text-muted"><?= e($sub) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header d-flex"><span><?= e(__('Features')) ?></span><?php if ($isPlatform): ?><a class="ms-auto small" href="<?= e($url('plan')) ?>"><?= e(__('Switch on / off')) ?></a><?php endif; ?></div>
          <div class="card-body small">
            <?php $onKeys = Features::enabledKeys($id); ?>
            <div class="mb-1"><i class="bi bi-check-circle-fill text-success"></i> <?= e(__(':n features on', ['n' => count($onKeys)])) ?><?php if ($off): ?> · <i class="bi bi-x-circle text-muted"></i> <?= e(__(':n off', ['n' => count($off)])) ?><?php endif; ?></div>
            <?php if ($off): ?><div class="text-muted"><?= e(__('Off:')) ?> <?= e(implode(', ', array_map([Features::class, 'label'], $off))) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="card"><div class="card-header d-flex"><span><?= e(__('Recent activity')) ?></span><a class="ms-auto small" href="<?= e($url('activity')) ?>"><?= e(__('View all')) ?></a></div>
          <?php if (!$lastActivity): ?><div class="hc-empty py-3"><i class="bi bi-activity"></i><p class="mb-0"><?= e(__('No activity yet.')) ?></p></div>
          <?php else: ?><table class="table table-sm table-hc mb-0"><tbody>
            <?php foreach ($lastActivity as $l): ?><tr><td class="small text-muted text-nowrap"><?= e(time_ago((string) $l['created_at'])) ?></td><td class="small"><?= e($l['username'] ?? '-') ?></td><td class="small"><span class="mono"><?= e($l['action']) ?></span> <span class="text-muted"><?= e(mb_strimwidth((string) ($l['details'] ?? ''), 0, 80, '…')) ?></span></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div>
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
                __('Phone / WhatsApp') => (string) ($h['contact_phone'] ?? ''),
                __('Billing email') => (string) ($h['contact_email'] ?? ''),
                __('Login link') => admin_url('login.php', ['b' => $h['slug']]),
            ] as $k => $v): if ($v === '') continue; ?>
              <tr><th class="ps-3 text-muted fw-normal" style="width:40%"><?= e($k) ?></th><td class="text-break"><?= $k === __('Status') ? $v : e($v) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php if ($isPlatform && $saas):
            $bill = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM invoices WHERE hotel_id = :h AND status = 'unpaid'", ['h' => $id]) ?? ['n' => 0, 'total' => 0];
            $last = DB::one('SELECT * FROM invoices WHERE hotel_id = :h ORDER BY id DESC LIMIT 1', ['h' => $id]);
            ?>
        <div class="card mb-3"><div class="card-header d-flex"><span><?= e(__('Billing')) ?></span><a class="ms-auto small" href="<?= e($url('billing')) ?>"><?= e(__('View all')) ?></a></div>
          <table class="table table-sm table-hc mb-0">
            <tr><th class="ps-3 text-muted fw-normal" style="width:40%"><?= e(__('Price per screen / month')) ?></th><td><?= e($h['price_per_tv_month'] !== null ? money($h['price_per_tv_month']) : '—') ?></td></tr>
            <tr><th class="ps-3 text-muted fw-normal"><?= e(__('Unpaid invoices')) ?></th><td><?= (int) $bill['n'] ?><?= (int) $bill['n'] ? ' · ' . e(money($bill['total'])) : '' ?></td></tr>
            <tr><th class="ps-3 text-muted fw-normal"><?= e(__('Last invoice')) ?></th><td><?php if ($last): ?><a href="<?= e(admin_url('invoice.php', ['id' => $last['id']])) ?>"><?= e($last['number']) ?></a> · <?= Billing::statusBadge($last) ?><?php else: ?>—<?php endif; ?></td></tr>
          </table>
        </div>
        <?php endif; ?>
        <div class="card"><div class="card-header"><?= e(__('Quick actions')) ?></div>
          <div class="card-body quick-actions d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-light border" href="<?= e($url('users')) ?>"><i class="bi bi-person-plus"></i> <?= e(__('Add user')) ?></a>
            <a class="btn btn-sm btn-light border" href="<?= e($url('screens')) ?>"><i class="bi bi-broadcast-pin"></i> <?= e(__('TV commands')) ?></a>
            <?php if ($isPlatform): ?><a class="btn btn-sm btn-light border" href="<?= e($url('plan')) ?>"><i class="bi bi-toggles"></i> <?= e(__('Features')) ?></a>
            <a class="btn btn-sm btn-light border" href="<?= e($url('settings')) ?>"><i class="bi bi-key"></i> <?= e(__('Registration key')) ?></a>
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_hotels.php', ['action' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit all details')) ?></a><?php endif; ?>
            <?= panel_open_workspace($id, 'platform_customer.php', 'sm', 'content.php') ?>
          </div>
        </div>
      </div>
    </div>
<?php
// ------------------------------------------------------------------ plan & features
elseif ($tab === 'plan'):
    $plans = Hotels::plans(false);
    $o = Features::overrides($id);
    $planKeys = Features::planKeys(Tenant::hotel($id)['plan_features'] ?? null);
    $map = Features::effective($id);
    $v = static fn (string $k) => e((string) ($h[$k] ?? ''));
    ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header"><?= e(__('Plan & limits')) ?></div><div class="card-body">
          <form method="post" class="row g-3">
            <?= Csrf::field() ?><input type="hidden" name="op" value="limits"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12"><label class="form-label" for="pl_plan"><?= e(__('Plan')) ?></label>
              <select class="form-select" id="pl_plan" name="plan_id"><option value=""><?= e(__('— No plan (not billed) —')) ?></option>
                <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($h['plan_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?><?= (int) $p['is_active'] ? '' : ' (' . e(__('inactive')) . ')' ?> · <?= e(money($p['price_per_tv_month'])) ?>/<?= e(__('TV/month')) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-6"><label class="form-label" for="pl_tv"><?= e(__('Max TVs')) ?></label><input class="form-control" type="number" min="0" id="pl_tv" name="max_tvs" value="<?= $v('max_tvs') ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
            <div class="col-6"><label class="form-label" for="pl_mu"><?= e(__('Max users')) ?></label><input class="form-control" type="number" min="0" id="pl_mu" name="max_users" value="<?= $v('max_users') ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
            <div class="col-6"><label class="form-label" for="pl_st"><?= e(__('Storage (MB)')) ?></label><input class="form-control" type="number" min="0" id="pl_st" name="storage_mb" value="<?= $v('storage_mb') ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
            <div class="col-6"><label class="form-label" for="pl_exp"><?= e(__('Valid until')) ?></label><input class="form-control" type="date" id="pl_exp" name="expires_at" value="<?= !empty($h['expires_at']) ? e(date('Y-m-d', (int) strtotime((string) $h['expires_at']))) : '' ?>"></div>
            <div class="col-12 form-text mt-0"><?= e(__('Empty = limit of the plan / no expiry.')) ?></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
          </form>
        </div></div>
        <div class="card"><div class="card-header"><?= e(__('In use')) ?></div><div class="card-body small">
          <div><i class="bi bi-tv"></i> <?= e(__('Screens')) ?>: <strong><?= e($tvs . ' / ' . ($lim['max_screens'] ?? __('unlimited'))) ?></strong></div>
          <div><i class="bi bi-people"></i> <?= e(__('Users')) ?>: <strong><?= e($userCount . ' / ' . ($lim['max_users'] ?? __('unlimited'))) ?></strong></div>
          <div><i class="bi bi-hdd"></i> <?= e(__('Storage')) ?>: <strong><?= e((int) ceil(Features::storageUsed($id) / 1048576) . ' MB / ' . ($lim['storage_mb'] !== null ? $lim['storage_mb'] . ' MB' : __('unlimited'))) ?></strong></div>
        </div></div>
      </div>
      <div class="col-lg-8">
        <div class="card"><div class="card-header d-flex align-items-center"><span><?= e(__('Features of this customer')) ?></span><span class="ms-auto small text-muted"><?= e(__('Switch on / off — takes effect immediately')) ?></span></div>
          <div class="card-body">
            <p class="small text-muted mb-2"><?= e(__('The plan decides the default. A switch that differs from the plan is stored as an override for this customer only.')) ?></p>
            <div class="row g-3" data-feature-switches>
            <?php foreach (Features::grouped() as $group => $defs): ?>
              <div class="col-md-6">
                <div class="fw-semibold small text-uppercase text-muted mb-1"><?= e(__(Features::GROUPS[$group] ?? $group)) ?></div>
                <?php foreach ($defs as $k => $d):
                    $on = !empty($map[$k]);
                    $inPlan = in_array($k, $planKeys, true);
                    $ov = in_array($k, $o['add'], true) ? 'add' : (in_array($k, $o['remove'], true) ? 'remove' : ''); ?>
                  <div class="feature-row" data-feature="<?= e($k) ?>" data-on="<?= $on ? '1' : '0' ?>">
                    <?= panel_switch('feature', $id, $on, '', '', '', ['feature' => $k]) ?>
                    <div class="feature-name"><?= e(__($d['label'])) ?>
                      <?php if ($ov === 'add'): ?><span class="badge text-bg-info"><?= e(__('added')) ?></span><?php elseif ($ov === 'remove'): ?><span class="badge text-bg-secondary"><?= e(__('removed')) ?></span><?php elseif ($inPlan): ?><span class="badge text-bg-light border"><?= e(__('plan')) ?></span><?php endif; ?>
                      <?php if ($d['depends']): ?><small><?= e(__('needs :f', ['f' => implode(', ', array_map([Features::class, 'label'], $d['depends']))])) ?></small><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        </div>
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
    $allScreens = DB::all('SELECT r.id, r.room_number, r.name, r.floor, r.is_enabled, (SELECT COUNT(*) FROM devices d WHERE d.room_id = r.id AND d.hotel_id = r.hotel_id AND d.is_revoked = 0) AS tvs FROM rooms r WHERE r.hotel_id = :h ORDER BY LENGTH(r.room_number), r.room_number', ['h' => $id]);
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
    <div class="card mb-3"><?= ps_table($rows, false) ?>
      <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
    </div>
    <?= ps_bulk_bar($customers, $id) ?>
    <div class="card mt-3"><div class="card-header"><?= e(__('Screens on / off')) ?> <span class="small text-muted fw-normal ms-2"><?= e(__('Off = the TV shows a black screen.')) ?></span></div>
      <?php if (!$allScreens): ?><div class="hc-empty py-3"><i class="bi bi-tv"></i><p class="mb-0"><?= e(__('No screens set up yet.')) ?></p></div>
      <?php else: ?>
      <div class="card-body d-flex flex-wrap gap-2" data-screen-switches>
        <?php foreach ($allScreens as $r): ?>
          <div class="border rounded px-2 py-1 d-flex align-items-center gap-2 small" data-screen="<?= (int) $r['id'] ?>">
            <?= panel_switch('screen_enabled', (int) $r['id'], (bool) $r['is_enabled'], '', '', '', ['customer' => $id]) ?>
            <span><strong><?= e($r['room_number']) ?></strong><?= $r['name'] ? ' · ' . e($r['name']) : '' ?> <span class="text-muted">(<?= (int) $r['tvs'] ?> TV)</span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
<?php
// ------------------------------------------------------------------ users
elseif ($tab === 'users'):
    $users = DB::all(
        "SELECT u.*,
                (SELECT COUNT(*) FROM user_access a WHERE a.user_id = u.id AND a.hotel_id = u.hotel_id AND a.target_type = 'room') AS acc_rooms,
                (SELECT COUNT(*) FROM user_access a WHERE a.user_id = u.id AND a.hotel_id = u.hotel_id AND a.target_type = 'group') AS acc_groups
         FROM users u WHERE u.hotel_id = :h AND u.role NOT IN ('platform_admin','reseller','chain_admin')
         ORDER BY FIELD(u.role, 'super_admin', 'manager', 'staff', 'reception'), u.username",
        ['h' => $id]
    );
    $roles = $customRoles();
    $roleOptions = static function (string $selected) use ($roles): string {
        $o = '';
        foreach (Auth::HOTEL_ROLES as $r) {
            $o .= '<option value="' . e($r) . '"' . ($selected === $r ? ' selected' : '') . '>' . e(role_label($r)) . '</option>';
        }
        foreach ($roles as $r) {
            $o .= '<option value="role:' . (int) $r['id'] . '"' . ($selected === 'role:' . $r['id'] ? ' selected' : '') . '>' . e($r['name']) . ' (' . e(__('custom')) . ')</option>';
        }
        return $o;
    };
    $limitErr = Features::userLimitError(1, $id);
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card">
          <div class="table-responsive"><table class="table table-hc align-middle mb-0" data-users-table>
            <thead><tr><th><?= e(__('User')) ?></th><th><?= e(__('Role')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Last login')) ?></th><th><?= e(__('Active')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Screen access')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
            <tbody>
            <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= e(__('No users yet.')) ?></td></tr><?php endif; ?>
            <?php foreach ($users as $u): ?>
              <tr data-user="<?= (int) $u['id'] ?>">
                <td><strong><?= e($u['full_name'] ?: $u['username']) ?></strong><div class="small text-muted"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
                <td>
                  <form method="post" class="d-flex gap-1 align-items-center"><?= Csrf::field() ?><input type="hidden" name="op" value="user_role"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <select class="form-select form-select-sm w-auto" name="role" aria-label="<?= e(__('Role')) ?>" onchange="this.form.requestSubmit()"><?= $roleOptions(Roles::specOf($u)) ?></select>
                  </form>
                </td>
                <td class="d-none d-md-table-cell small"><?= e($u['last_login_at'] ? time_ago((string) $u['last_login_at']) : __('never')) ?></td>
                <td><?= panel_switch('user_active', (int) $u['id'], (bool) $u['is_active'], '', __('Deactivate :u? The user is logged out and cannot log in.', ['u' => $u['username']])) ?>
                  <?php if ($u['locked_until'] && strtotime((string) $u['locked_until']) > time()): ?><span class="badge text-bg-warning"><?= e(__('Locked')) ?></span><?php endif; ?></td>
                <td class="d-none d-lg-table-cell small"><?= (int) $u['acc_rooms'] || (int) $u['acc_groups']
                    ? e(__(':r screens, :g groups', ['r' => (int) $u['acc_rooms'], 'g' => (int) $u['acc_groups']]))
                    : e(__('All screens')) ?></td>
                <td class="text-end text-nowrap">
                  <form method="post" class="d-inline" data-confirm="<?= e(__('Create a new password for :u? The user is logged out everywhere.', ['u' => $u['username']])) ?>" data-confirm-safe="1">
                    <?= Csrf::field() ?><input type="hidden" name="op" value="user_reset"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <label class="form-check form-check-inline small mb-0" title="<?= e(__('Email the login details to the user')) ?>"><input class="form-check-input" type="checkbox" name="send_email" value="1" checked> <?= e(__('Email')) ?></label>
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i> <?= e(__('Reset password')) ?></button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="card-footer small text-muted"><?= e(__('Screen access per user (which screens a user may see) is set in the workspace → Users.')) ?></div>
        </div>
      </div>
      <div class="col-xl-4">
        <div class="card"><div class="card-header"><i class="bi bi-person-plus me-1"></i> <?= e(__('Add user')) ?></div><div class="card-body">
          <?php if ($limitErr): ?><div class="alert alert-warning small"><?= e($limitErr) ?></div><?php endif; ?>
          <form method="post" class="row g-2" autocomplete="off" data-user-create>
            <?= Csrf::field() ?><input type="hidden" name="op" value="user_create"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12"><label class="form-label" for="nu_u"><?= e(__('Username')) ?></label><input class="form-control" id="nu_u" name="admin_username" required pattern="[A-Za-z0-9_.\-]{3,50}"></div>
            <div class="col-12"><label class="form-label" for="nu_n"><?= e(__('Full name')) ?></label><input class="form-control" id="nu_n" name="admin_name"></div>
            <div class="col-12"><label class="form-label" for="nu_e"><?= e(__('Email')) ?></label><input class="form-control" type="email" id="nu_e" name="admin_email" required></div>
            <div class="col-12"><label class="form-label" for="nu_p"><?= e(__('Password')) ?></label><input class="form-control" type="password" id="nu_p" name="admin_password" autocomplete="new-password" data-strength="#nuBar"><div class="strength-bar" id="nuBar"><span></span></div></div>
            <div class="col-7"><label class="form-label" for="nu_r"><?= e(__('Role')) ?></label><select class="form-select" id="nu_r" name="role"><?= $roleOptions('manager') ?></select></div>
            <div class="col-5"><label class="form-label" for="nu_l"><?= e(__('Language')) ?></label><select class="form-select" id="nu_l" name="language"><?php foreach (I18n::LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><?= invite_checkbox('nu_i', 'nu_p') ?></div>
            <div class="col-12 form-check ms-1"><input class="form-check-input" type="checkbox" id="nu_m" name="send_email" value="1"><label class="form-check-label small" for="nu_m"><?= e(__('Email the login details to the user')) ?> (<?= e(__('when you set a password')) ?>)</label></div>
            <div class="col-12"><button class="btn btn-primary w-100"<?= $limitErr ? ' disabled' : '' ?>><i class="bi bi-person-plus"></i> <?= e(__('Create user')) ?></button></div>
          </form>
        </div></div>
      </div>
    </div>
<?php
// ------------------------------------------------------------------ content
elseif ($tab === 'content'):
    $counts = [
        'items' => (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h', ['h' => $id]),
        'playlists' => (int) DB::value('SELECT COUNT(*) FROM content_playlists WHERE hotel_id = :h', ['h' => $id]),
        'assigned' => (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND (content_id IS NOT NULL OR playlist_id IS NOT NULL)', ['h' => $id]),
        'groups' => (int) DB::value('SELECT COUNT(*) FROM room_groups WHERE hotel_id = :h', ['h' => $id]),
    ];
    $defPl = (int) Settings::getFor($id, 'default_playlist_id', 0);
    $defCt = (int) Settings::getFor($id, 'default_content_id', 0);
    $defName = $defPl ? DB::value('SELECT name FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $defPl, 'h' => $id]) : ($defCt ? DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $defCt, 'h' => $id]) : null);
    $perScreen = DB::all('SELECT r.id, r.room_number, r.name, r.floor, r.is_enabled, c.title AS content_title, p.name AS playlist_name,
            (SELECT COUNT(*) FROM devices d WHERE d.room_id = r.id AND d.hotel_id = r.hotel_id AND d.is_revoked = 0) AS tvs
        FROM rooms r LEFT JOIN content_items c ON c.id = r.content_id AND c.hotel_id = r.hotel_id LEFT JOIN content_playlists p ON p.id = r.playlist_id AND p.hotel_id = r.hotel_id
        WHERE r.hotel_id = :h ORDER BY LENGTH(r.room_number), r.room_number LIMIT 200', ['h' => $id]);
    $recent = DB::all('SELECT id, title, type, is_active, updated_at FROM content_items WHERE hotel_id = :h ORDER BY updated_at DESC LIMIT 8', ['h' => $id]);
    ?>
    <div class="kpi-grid mb-3">
      <?= panel_kpi(__('Content items'), $counts['items'], 'bi-images') ?>
      <?= panel_kpi(__('Playlists'), $counts['playlists'], 'bi-collection-play') ?>
      <?= panel_kpi(__('Screens with own content'), $counts['assigned'], 'bi-pin-angle', '', __('of :n', ['n' => $screens])) ?>
      <?= panel_kpi(__('Groups'), $counts['groups'], 'bi-collection') ?>
      <?= panel_kpi(__('Storage'), human_bytes(Features::storageUsed($id)), 'bi-hdd') ?>
    </div>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card"><div class="card-header d-flex align-items-center"><span><?= e(__('What each screen shows')) ?></span>
            <span class="ms-auto"><?= panel_open_workspace($id, 'platform_customer.php', 'sm', 'rooms.php') ?></span></div>
          <?php if (!$perScreen): ?><div class="hc-empty py-3"><i class="bi bi-tv"></i><p class="mb-0"><?= e(__('No screens set up yet.')) ?></p></div>
          <?php else: ?>
          <div class="table-responsive"><table class="table table-sm table-hc mb-0" data-content-per-screen>
            <thead><tr><th><?= e(__('Screen')) ?></th><th><?= e(__('Assigned')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($perScreen as $r): ?>
              <tr class="<?= (int) $r['is_enabled'] ? '' : 'text-muted' ?>"><td><strong><?= e($r['room_number']) ?></strong><?= $r['name'] ? ' <span class="small text-muted">' . e($r['name']) . '</span>' : '' ?><?= (int) $r['is_enabled'] ? '' : ' <span class="badge text-bg-secondary">' . e(__('Off')) . '</span>' ?></td>
                <td class="small"><?php if ($r['playlist_name']): ?><i class="bi bi-collection-play"></i> <?= e($r['playlist_name']) ?><?php elseif ($r['content_title']): ?><i class="bi bi-image"></i> <?= e($r['content_title']) ?><?php else: ?><span class="text-muted"><?= e(__('Default content')) ?><?= $defName ? ' (' . e((string) $defName) . ')' : '' ?></span><?php endif; ?></td>
                <td class="text-end"><?= (int) $r['tvs'] ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><?= e(__('Default content')) ?></div><div class="card-body small">
          <?php if ($defName): ?><i class="bi <?= $defPl ? 'bi-collection-play' : 'bi-image' ?>"></i> <strong><?= e((string) $defName) ?></strong>
          <?php else: ?><span class="text-muted"><?= e(__('No default content — screens without own content show the welcome screen.')) ?></span><?php endif; ?>
          <div class="mt-2"><?= panel_open_workspace($id, 'platform_customer.php', 'sm', 'settings.php') ?></div>
        </div></div>
        <div class="card"><div class="card-header d-flex"><span><?= e(__('Recently changed content')) ?></span><span class="ms-auto"><?= panel_open_workspace($id, 'platform_customer.php', 'sm', 'content.php') ?></span></div>
          <?php if (!$recent): ?><div class="hc-empty py-3"><i class="bi bi-images"></i><p class="mb-0"><?= e(__('No content uploaded yet.')) ?></p></div>
          <?php else: ?><ul class="list-group list-group-flush">
            <?php foreach ($recent as $c): ?><li class="list-group-item small d-flex justify-content-between gap-2"><span><span class="badge text-bg-light border"><?= e($c['type']) ?></span> <?= e($c['title']) ?><?= (int) $c['is_active'] ? '' : ' <span class="text-muted">(' . e(__('inactive')) . ')</span>' ?></span><span class="text-muted text-nowrap"><?= e(time_ago((string) $c['updated_at'])) ?></span></li><?php endforeach; ?>
          </ul><?php endif; ?>
        </div>
      </div>
    </div>
<?php
// ------------------------------------------------------------------ billing
elseif ($tab === 'billing'):
    $invoices = DB::all('SELECT * FROM invoices WHERE hotel_id = :h ORDER BY id DESC LIMIT 50', ['h' => $id]);
    $licenses = DB::all('SELECT * FROM licenses WHERE hotel_id = :h ORDER BY id DESC', ['h' => $id]);
    $unpaid = array_filter($invoices, static fn ($i) => $i['status'] === 'unpaid');
    ?>
    <div class="kpi-grid mb-3">
      <?= panel_kpi(__('Price per screen / month'), $h['price_per_tv_month'] !== null ? money($h['price_per_tv_month']) : '—', 'bi-tag') ?>
      <?= panel_kpi(__('Monthly estimate'), $h['price_per_tv_month'] !== null ? money((float) $h['price_per_tv_month'] * $tvs) : '—', 'bi-calculator', '', __(':n TVs', ['n' => $tvs])) ?>
      <?= panel_kpi(__('Unpaid invoices'), count($unpaid), 'bi-receipt', '', count($unpaid) ? money(array_sum(array_column($unpaid, 'total'))) : '', count($unpaid) ? 'warning' : '') ?>
      <?= panel_kpi(__('Valid until'), $h['expires_at'] ? date('d M Y', (int) strtotime((string) $h['expires_at'])) : __('No expiry'), 'bi-calendar-event', '', $h['is_trial'] ? __('Trial') : '', $state === 'expired' ? 'danger' : '') ?>
    </div>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card"><div class="card-header d-flex align-items-center"><span><?= e(__('Invoices')) ?></span>
          <a class="ms-auto btn btn-sm btn-light border" href="<?= e(admin_url('platform_invoices.php', ['hotel' => $id])) ?>"><i class="bi bi-receipt-cutoff"></i> <?= e(__('All invoices / create')) ?></a></div>
          <div class="table-responsive"><table class="table table-sm table-hc mb-0">
            <thead><tr><th><?= e(__('Number')) ?></th><th><?= e(__('Period')) ?></th><th class="text-end"><?= e(__('Total')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Due')) ?></th></tr></thead>
            <tbody>
            <?php if (!$invoices): ?><tr><td colspan="5" class="text-muted text-center py-3"><?= e(__('No invoices yet.')) ?></td></tr><?php endif; ?>
            <?php foreach ($invoices as $inv): ?>
              <tr><td><a href="<?= e(admin_url('invoice.php', ['id' => $inv['id']])) ?>"><?= e($inv['number']) ?></a></td>
                <td class="small"><?= e(date('M Y', (int) strtotime($inv['period_from']))) ?></td>
                <td class="text-end"><?= e(money($inv['total'], $inv['currency'])) ?></td><td><?= Billing::statusBadge($inv) ?></td>
                <td class="small d-none d-md-table-cell"><?= e((string) $inv['due_date']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header"><?= e(__('Validity')) ?></div><div class="card-body">
          <form method="post" class="d-flex gap-2"><?= Csrf::field() ?><input type="hidden" name="op" value="extend"><input type="hidden" name="id" value="<?= $id ?>">
            <select class="form-select" name="days" aria-label="<?= e(__('Extend by')) ?>"><?php foreach ([7, 14, 30, 90, 365] as $d): ?><option value="<?= $d ?>"<?= $d === 30 ? ' selected' : '' ?>><?= e(__('+:n days', ['n' => $d])) ?></option><?php endforeach; ?></select>
            <button class="btn btn-outline-primary text-nowrap"><i class="bi bi-calendar-plus"></i> <?= e(__('Extend')) ?></button></form>
          <div class="form-text"><?= e(__('Adds days to the current expiry (or to today when already expired) and re-activates an expired customer.')) ?></div>
        </div></div>
        <div class="card"><div class="card-header"><?= e(__('Licenses')) ?></div>
          <?php if (!$licenses): ?><div class="card-body small text-muted"><?= e(__('No license key for this customer (SaaS customers do not need one).')) ?> <a href="<?= e(admin_url('platform_licenses.php')) ?>"><?= e(__('Licenses')) ?></a></div>
          <?php else: ?><ul class="list-group list-group-flush">
            <?php foreach ($licenses as $lic): ?><li class="list-group-item small"><span class="mono"><?= e(substr((string) $lic['license_key'], 0, 8)) ?>…</span> · <?= e($lic['status']) ?><?= $lic['expires_at'] ? ' · ' . e(date('d M Y', (int) strtotime((string) $lic['expires_at']))) : '' ?><?= $lic['bound_domain'] ? ' · ' . e($lic['bound_domain']) : '' ?></li><?php endforeach; ?>
          </ul><?php endif; ?>
        </div>
      </div>
    </div>
<?php
// ------------------------------------------------------------------ settings
elseif ($tab === 'settings'):
    $regKey = (string) Settings::getFor($id, 'registration_key', '');
    $hcChains = Chains::enabled() ? Chains::all() : [];
    ?>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header"><?= e(__('Account')) ?></div>
          <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex align-items-center gap-3">
              <div class="flex-grow-1"><div class="fw-semibold"><?= e(__('Account active')) ?></div><div class="small text-muted"><?= e(__('Off = suspended: TVs show the "service paused" screen, the customer panel becomes read-only.')) ?></div></div>
              <?= panel_switch('customer_status', $id, $state === 'active', '', __('Suspend this customer? Its TVs show the "service paused" screen and its admin panel becomes read-only.'), '', [], $state === 'expired') ?>
            </li>
            <?php if ($state === 'expired'): ?>
            <li class="list-group-item small d-flex align-items-center gap-2"><i class="bi bi-calendar-x text-warning"></i> <?= e(__('This account has expired.')) ?> <a class="ms-auto btn btn-sm btn-success" href="<?= e($url('billing')) ?>"><?= e(__('Extend validity')) ?></a></li>
            <?php endif; ?>
            <li class="list-group-item">
              <div class="fw-semibold"><?= e(__('Registration key')) ?></div>
              <div class="d-flex flex-wrap align-items-center gap-2 mt-1"><code class="mono"><?= e($regKey) ?></code><button type="button" class="btn btn-sm btn-light border" data-copy="<?= e($regKey) ?>"><i class="bi bi-clipboard"></i></button>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Create a new registration key for this customer? New TVs then need the new key.')) ?>">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="regen_key"><input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> <?= e(__('New registration key')) ?></button></form></div>
            </li>
            <li class="list-group-item small"><div class="fw-semibold"><?= e(__('Login link')) ?></div><code class="text-break"><?= e(admin_url('login.php', ['b' => $h['slug']])) ?></code></li>
          </ul>
        </div>
        <?php if (Chains::enabled() && ($hcChains || $h['chain_id'])): ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-diagram-3"></i> <?= e(__('Chain')) ?></div><div class="card-body">
          <form method="post" class="d-flex gap-2"><?= Csrf::field() ?><input type="hidden" name="op" value="set_chain"><input type="hidden" name="id" value="<?= $id ?>">
            <select class="form-select" name="chain_id" aria-label="<?= e(__('Chain')) ?>"><option value="0"><?= e(__('— Not in a chain —')) ?></option>
              <?php foreach ($hcChains as $hcC): ?><option value="<?= (int) $hcC['id'] ?>"<?= (int) $h['chain_id'] === (int) $hcC['id'] ? ' selected' : '' ?>><?= e($hcC['name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-outline-primary"><?= e(__('Save')) ?></button></form>
        </div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header"><?= e(__('Contact & details')) ?></div><div class="card-body small">
          <div><?= e($h['contact_name'] ?: '—') ?> · <?= e($h['contact_phone'] ?: '—') ?> · <?= e($h['contact_email'] ?: '—') ?></div>
          <div class="text-muted"><?= e(trim(($h['address'] ?? '') . ' ' . ($h['city'] ?? ''))) ?><?= $h['gstin'] ? ' · GSTIN ' . e($h['gstin']) : '' ?></div>
          <a class="btn btn-sm btn-light border mt-2" href="<?= e(admin_url('platform_hotels.php', ['action' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit all details')) ?></a>
        </div></div>
      </div>
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header"><?= e(__('Branding override')) ?></div><div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-2">
            <?= Csrf::field() ?><input type="hidden" name="op" value="branding"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-12 small text-muted"><?= e(__('Optional. Replaces the platform / reseller name, logo and colour for this customer (login page with ?b=slug, admin panel, TVs).')) ?></div>
            <div class="col-sm-7"><label class="form-label" for="b_n"><?= e(__('Product name')) ?></label><input class="form-control" id="b_n" name="brand_name" value="<?= e((string) $h['brand_name']) ?>" maxlength="120"></div>
            <div class="col-sm-5"><label class="form-label" for="b_c"><?= e(__('Colour')) ?></label><input class="form-control" id="b_c" name="brand_color" value="<?= e((string) $h['brand_color']) ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#7B1FA2"></div>
            <div class="col-12"><label class="form-label" for="b_l"><?= e(__('Logo')) ?></label><input class="form-control" type="file" id="b_l" name="brand_logo" accept="image/png,image/jpeg,image/webp">
              <?php if (!empty($h['brand_logo'])): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $h['brand_logo'])) ?>" alt="" style="max-height:40px"><label class="form-check-label small"><input class="form-check-input" type="checkbox" name="remove_brand_logo" value="1"> <?= e(__('Remove')) ?></label></div><?php endif; ?></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
          </form>
        </div></div>
        <div class="card danger-zone"><div class="card-header"><i class="bi bi-exclamation-octagon"></i> <?= e(__('Danger zone')) ?></div><div class="card-body">
          <?php if (empty($h['archived_at'])): ?>
          <p class="small mb-2"><?= e(__('Archive = the recommended way to end a customer: it is suspended, hidden from the customers list and can be restored with all its data.')) ?></p>
          <form method="post" class="mb-3" data-confirm="<?= e(__('Archive this customer? Its TVs show the "service paused" screen and its users are logged out. You can restore it later.')) ?>">
            <?= Csrf::field() ?><input type="hidden" name="op" value="archive"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-secondary"><i class="bi bi-archive"></i> <?= e(__('Archive customer')) ?></button></form>
          <?php else: ?>
          <form method="post" class="mb-3"><?= Csrf::field() ?><input type="hidden" name="op" value="restore"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-success"><i class="bi bi-arrow-counterclockwise"></i> <?= e(__('Restore customer')) ?></button></form>
          <?php endif; ?>
          <p class="small mb-2"><?= e(__('Deleting a customer removes its users, screens, TVs, content, playlists, logs and invoices permanently. Its TVs return to the setup screen. This cannot be undone.')) ?></p>
          <button type="button" class="btn btn-outline-danger" data-delete-customer="<?= $id ?>" data-name="<?= e($h['name']) ?>"><i class="bi bi-trash"></i> <?= e(__('Delete customer')) ?></button>
          <?= panel_delete_customer_modal() ?>
        </div></div>
      </div>
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
