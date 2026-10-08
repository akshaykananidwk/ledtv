<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('users.manage');
Csrf::check();

const ROLES = Auth::HOTEL_ROLES;
/** Roles that can be limited to some TVs (core/Access.php). Custom roles keep one of these as users.role. */
const LIMITABLE_ROLES = ['manager', 'staff', 'reception'];

/**
 * Roles the current user may give (custom roles / RBAC, core/Roles.php): spec => label. An Admin may give
 * every role; others never the Admin role and only roles whose permissions they hold themselves.
 */
function role_options(): array
{
    $out = [];
    foreach (ROLES as $r) {
        if (Roles::canAssign($r)) {
            $out[$r] = role_label($r);
        }
    }
    // Custom roles can be given while the plan includes them (users who already have one keep it).
    foreach (Roles::featureEnabled() ? Roles::all() : [] as $c) {
        if (Roles::canAssign($c['key'])) {
            $out[$c['key']] = $c['name'];
        }
    }
    return $out;
}

/** May the current user change / delete / unlock this user? (not a user whose role has more rights) */
function can_manage_user(array $u): bool
{
    return Roles::canAssign(Roles::specOf($u));
}

/**
 * TV access from the form: [targets [['group', id] | ['room', id]], errors]. "all" → [].
 * Ids of another hotel are refused (Tenant::find → 404, logged); unknown ids are dropped.
 */
function access_from_post(string $role): array
{
    // 2.5 plans: without "Per-user screen access" every user controls all TVs.
    if (!in_array($role, LIMITABLE_ROLES, true) || ($_POST['tv_access'] ?? 'all') !== 'some' || !Features::enabled('user_access')) {
        return [[], []];
    }
    $targets = [];
    foreach (int_ids($_POST['access_groups'] ?? []) as $gid) {
        if (Tenant::find('room_groups', $gid)) {
            $targets[] = ['group', $gid];
        }
    }
    foreach (int_ids($_POST['access_rooms'] ?? []) as $rid) {
        if (Tenant::find('rooms', $rid)) {
            $targets[] = ['room', $rid];
        }
    }
    return [$targets, $targets ? [] : [__('Choose at least one group or screen, or select "All TVs".')]];
}

/** "All TVs" / "3 rooms, 1 group" for a user (current hotel). */
function access_summary(array $u): string
{
    if (!in_array($u['role'], LIMITABLE_ROLES, true)) {
        return __('All TVs');
    }
    $rows = Access::forUser((int) $u['id']);
    if (!$rows) {
        return __('All TVs');
    }
    $g = count(array_filter($rows, fn ($r) => $r['type'] === 'group'));
    $r = count($rows) - $g;
    $parts = [];
    if ($r) {
        $parts[] = $r === 1 ? __('1 screen') : __(':n screens', ['n' => $r]);
    }
    if ($g) {
        $parts[] = $g === 1 ? __('1 group') : __(':n groups', ['n' => $g]);
    }
    return implode(', ', $parts);
}

/** Text for the activity log: "all" or "groups 3,4; rooms 12". */
function access_log_text(array $targets): string
{
    if (!$targets) {
        return 'all TVs';
    }
    $by = ['group' => [], 'room' => []];
    foreach ($targets as $t) {
        [$type, $id] = isset($t['type']) ? [$t['type'], $t['id']] : $t;
        $by[$type][] = (int) $id;
    }
    sort($by['group']);
    sort($by['room']);
    return trim(($by['group'] ? 'groups ' . implode(',', $by['group']) : '') . ($by['group'] && $by['room'] ? '; ' : '') . ($by['room'] ? 'rooms ' . implode(',', $by['room']) : ''));
}

function other_active_super_admins(int $exceptId): int
{
    return (int) DB::value("SELECT COUNT(*) FROM users WHERE hotel_id = :hid AND role = 'super_admin' AND is_active = 1 AND id <> :id", ['id' => $exceptId] + hid());
}

/**
 * A user of the current hotel (hotel roles only — platform admins / resellers are managed on the
 * platform). 404 for users of another hotel or platform accounts.
 */
function hotel_user(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    if (!$u) {
        return null;
    }
    if ((int) $u['hotel_id'] !== Tenant::id() || !in_array($u['role'], Auth::HOTEL_ROLES, true)) {
        Tenant::deny('users id ' . $id);
    }
    return $u;
}

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $target = $id ? hotel_user($id) : null;
    $me = (int) $user['id'];

    if ($target && in_array($op, ['unlock', 'revoke_session', 'revoke_all'], true) && !can_manage_user($target)) {
        flash('danger', __('You cannot change a user whose role has more rights than yours.'));
        redirect(admin_url('users.php'));
    }
    switch ($op) {
        case 'save':
            if ($id && !$target) {
                flash('danger', __('User not found.'));
                redirect(admin_url('users.php'));
            }
            $username = req_str('username', $_POST, 60);
            $email = req_str('email', $_POST, 190);
            // Role: built-in key or "role:<id>" (custom role of this customer; another customer's id → 404).
            $spec = is_string($_POST['role'] ?? null) ? $_POST['role'] : 'staff';
            $parsed = Roles::parseSpec($spec);
            if ($parsed === null) {
                $spec = 'staff';
                $parsed = ['staff', null];
            }
            [$role, $roleId] = $parsed;
            $lang = isset(I18n::LANGUAGES[$_POST['language'] ?? '']) ? $_POST['language'] : 'en';
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
            $errors = [];
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                $errors[] = __('Username: 3–50 letters, numbers, dot, dash or underscore.');
            } elseif (DB::value('SELECT id FROM users WHERE username = :u AND id <> :id', ['u' => $username, 'id' => $id])) {
                $errors[] = __('This username is already taken.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Enter a valid email address.');
            } elseif (DB::value('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => $id])) {
                $errors[] = __('This email is already used by another user.');
            }
            if (!$target || $password !== '') {
                if ($err = Auth::passwordError($password)) {
                    $errors[] = $err;
                } elseif ($password !== $confirm) {
                    $errors[] = __('The two passwords do not match.');
                }
            }
            if ($target && (int) $target['id'] === $me && (!$active || $spec !== Roles::specOf($user))) {
                $errors[] = __('You cannot disable your own account or change your own role.');
            } elseif ($target && !can_manage_user($target)) {
                $errors[] = __('You cannot change a user whose role has more rights than yours.');
            } elseif (!Roles::canAssign($spec)) {
                $errors[] = $spec === 'super_admin' ? __('Only an Admin can give the Admin role.') : __('You cannot give a role with permissions you do not have yourself.');
            }
            if ($errors && ($target && !can_manage_user($target) || !Roles::canAssign($spec))) {
                Logger::write('security', 'warning', 'Role escalation refused (users.php)', ['user' => $me, 'target' => $id, 'role' => $spec, 'ip' => client_ip()]);
            }
            if ($target && $target['role'] === 'super_admin' && ($role !== 'super_admin' || !$active) && other_active_super_admins((int) $target['id']) === 0) {
                $errors[] = __('There must always be at least one active Admin.');
            }
            [$access, $accessErrors] = access_from_post($role);
            $errors = array_merge($errors, $accessErrors);
            if (!$target && ($limitErr = Features::userLimitError())) { // 2.5 plans: max users
                $errors[] = $limitErr;
            }
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('users.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            $data = ['username' => $username, 'email' => $email, 'full_name' => req_str('full_name', $_POST, 120), 'role' => $role, 'language' => $lang, 'is_active' => $active];
            if (Roles::available()) {
                $data['role_id'] = $roleId;
            }
            $oldSpec = $target ? Roles::specOf($target) : '';
            if ($password !== '') {
                $data['password_hash'] = Auth::hash($password);
            }
            if ($target) {
                DB::update('users', $data, 'id = :id AND hotel_id = :hid', ['id' => $id] + hid());
                if ($password !== '' || !$active || $spec !== $oldSpec) {
                    Auth::revokeUserSessions($id, $id === $me);
                }
                ActivityLog::add('user_update', 'user', $id, $username . ($password !== '' ? ' (password reset)' : ''));
                if ($spec !== $oldSpec) {
                    ActivityLog::add('user_role', 'user', $id, $username . ': ' . Roles::specLabel($oldSpec) . ' → ' . Roles::specLabel($spec));
                }
            } else {
                $id = DB::insert('users', $data + ['hotel_id' => Tenant::id(), 'created_at' => now()]);
                ActivityLog::add('user_create', 'user', $id, $username . ' (' . ($roleId ? Roles::specLabel($spec) : $role) . ')');
            }
            Auth::forgetPermissions();
            // TV access (manager / staff / reception): all TVs or only some groups / rooms.
            $before = access_log_text(Access::forUser($id));
            Access::setForUser($id, $access);
            $after = access_log_text($access);
            if ($before !== $after) {
                ActivityLog::add('user_access', 'user', $id, $username . ': ' . $before . ' → ' . $after);
            }
            flash('success', __('User :u saved.', ['u' => $username]));
            redirect(admin_url('users.php'));

        case 'delete':
            if (!$target) {
                break;
            }
            if ((int) $target['id'] === $me) {
                flash('danger', __('You cannot delete your own account.'));
            } elseif (!can_manage_user($target)) {
                flash('danger', __('You cannot change a user whose role has more rights than yours.'));
            } elseif ($target['role'] === 'super_admin' && other_active_super_admins((int) $target['id']) === 0) {
                flash('danger', __('There must always be at least one active Admin.'));
            } else {
                DB::delete('users', 'id = :id AND hotel_id = :hid', ['id' => $id] + hid());
                DB::query('DELETE FROM user_access WHERE user_id = :u AND hotel_id = :hid', ['u' => $id] + hid());
                ActivityLog::add('user_delete', 'user', $id, $target['username']);
                flash('success', __('User :u deleted.', ['u' => $target['username']]));
            }
            break;

        case 'unlock':
            if ($target) {
                DB::update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = :id AND hotel_id = :hid', ['id' => $id] + hid());
                ActivityLog::add('user_unlock', 'user', $id, $target['username']);
                flash('success', __('User :u unlocked.', ['u' => $target['username']]));
            }
            break;

        case 'revoke_session':
            if (!$target) {
                break;
            }
            $sid = req_int('session_id', $_POST);
            DB::update('user_sessions', ['revoked' => 1], 'id = :sid AND user_id = :uid', ['sid' => $sid, 'uid' => $id]);
            ActivityLog::add('session_revoke', 'user', $id, 'Session #' . $sid);
            flash('success', __('Session ended.'));
            redirect(admin_url('users.php', ['action' => 'view', 'id' => $id]));

        case 'revoke_all':
            if ($target) {
                Auth::revokeUserSessions($id, $id === $me);
                ActivityLog::add('session_revoke', 'user', $id, 'All sessions');
                flash('success', __('All sessions of :u ended.', ['u' => $target['username']]));
            }
            redirect(admin_url('users.php', ['action' => 'view', 'id' => $id]));

        case 'set_pin':
            $pin = req_str('tv_settings_pin', $_POST, 10);
            if (!preg_match('/^\d{4}$/', $pin)) {
                flash('danger', __('The PIN must be exactly 4 digits.'));
            } else {
                Settings::set('tv_settings_pin', $pin);
                ActivityLog::add('tv_pin_change', 'settings', null, 'TV settings PIN changed (all screens)');
                flash('success', __('TV settings PIN saved. TVs pick it up within a minute.'));
            }
            break;
    }
    redirect(admin_url('users.php'));
}

$action = req_str('action', $_GET, 20);
$pageTitle = __('Users');
$activeNav = 'users';

if ($action === 'new' || $action === 'edit') {
    $u = ['id' => 0, 'username' => '', 'email' => '', 'full_name' => '', 'role' => 'staff', 'language' => 'en', 'is_active' => 1];
    if ($action === 'edit') {
        $u = hotel_user(req_int('id', $_GET));
        if (!$u) {
            flash('warning', __('User not found.'));
            redirect(admin_url('users.php'));
        }
        if ((int) $u['id'] !== (int) $user['id'] && !can_manage_user($u)) {
            flash('danger', __('You cannot change a user whose role has more rights than yours.'));
            redirect(admin_url('users.php'));
        }
    }
    $isSelf = (int) $u['id'] === (int) $user['id'];
    $uSpec = $u['id'] ? Roles::specOf($u) : 'staff';
    $roleOptions = role_options();
    if (!isset($roleOptions[$uSpec])) {
        $roleOptions = [$uSpec => Roles::specLabel($uSpec)] + $roleOptions; // own role (shown, not changeable)
    }
    $assigned = $u['id'] ? Access::forUser((int) $u['id']) : [];
    $selGroups = array_map(fn ($r) => $r['id'], array_filter($assigned, fn ($r) => $r['type'] === 'group'));
    $selRooms = array_map(fn ($r) => $r['id'], array_filter($assigned, fn ($r) => $r['type'] === 'room'));
    $accGroups = DB::all('SELECT g.id, g.name, g.type, (SELECT COUNT(*) FROM room_group_members m JOIN rooms r ON r.id = m.room_id AND r.hotel_id = g.hotel_id WHERE m.group_id = g.id) AS members
                          FROM room_groups g WHERE g.hotel_id = :hid ORDER BY g.type, g.name', hid());
    $accRooms = DB::all('SELECT id, room_number, name, floor FROM rooms WHERE hotel_id = :hid ORDER BY LENGTH(floor), floor, LENGTH(room_number), room_number', hid());
    $pageTitle = $u['id'] ? __('Edit user') : __('Add user');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><?= e($pageTitle) ?></h1>
      <a href="<?= e(admin_url('users.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card" style="max-width:760px" autocomplete="off">
      <div class="card-body row g-3">
        <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <div class="col-md-6">
          <label class="form-label" for="un"><?= e(__('Username')) ?> *</label>
          <input class="form-control" id="un" name="username" value="<?= e($u['username']) ?>" required maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" autocapitalize="none">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="em"><?= e(__('Email')) ?> *</label>
          <input class="form-control" type="email" id="em" name="email" value="<?= e($u['email']) ?>" required maxlength="190">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="fn"><?= e(__('Full name')) ?></label>
          <input class="form-control" id="fn" name="full_name" value="<?= e($u['full_name']) ?>" maxlength="120">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="role"><?= e(__('Role')) ?></label>
          <select class="form-select" id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>>
            <?php $customOpts = array_filter($roleOptions, static fn ($k) => str_starts_with((string) $k, 'role:'), ARRAY_FILTER_USE_KEY); ?>
            <optgroup label="<?= e(__('Built-in roles')) ?>">
            <?php foreach (array_diff_key($roleOptions, $customOpts) as $k => $label): ?><option value="<?= e($k) ?>"<?= $uSpec === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </optgroup>
            <?php if ($customOpts): ?>
            <optgroup label="<?= e(__('Custom roles')) ?>">
            <?php foreach ($customOpts as $k => $label): ?><option value="<?= e($k) ?>"<?= $uSpec === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
          <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($uSpec) ?>"><?php endif; ?>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="lang"><?= e(__('Language')) ?></label>
          <select class="form-select" id="lang" name="language">
            <?php foreach (I18n::LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"<?= $u['language'] === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 small text-muted">
          <strong><?= e(role_label('super_admin')) ?></strong>: <?= e(__('everything of this customer, including users, settings and billing.')) ?>
          <strong><?= e(role_label('manager')) ?></strong>: <?= e(__('screens, content, playlists, schedules, TV commands, APK, logs.')) ?>
          <strong><?= e(role_label('staff')) ?></strong>: <?= e(__('view screens and content, send content and emergency messages.')) ?>
          <strong><?= e(role_label('reception')) ?></strong>: <?= e(__('front desk: guests check-in/out, service orders and requests, view screens.')) ?>
          <?php if (Auth::can('roles.manage')): ?><a href="<?= e(admin_url('roles.php')) ?>"><?= e(__('Custom roles and their permissions')) ?> →</a><?php endif; ?>
        </div>
        <div class="col-12<?= Features::enabled('user_access') ? '' : ' d-none' ?>" id="tvAccess"<?= in_array($u['role'], LIMITABLE_ROLES, true) ? '' : ' hidden' ?>>
          <div class="border rounded p-3">
            <label class="form-label fw-semibold mb-1"><i class="bi bi-tv"></i> <?= e(__('Which TVs can this user control?')) ?></label>
            <div class="form-text mt-0 mb-2"><?= e(__('Limit a Manager, Staff or Reception user to some screens or groups. They then see and control only those TVs (screens, dashboard, broadcasts, power, schedules, emergency, guests). Admins always have all TVs.')) ?></div>
            <div class="d-flex flex-wrap gap-3 mb-2">
              <div class="form-check"><input class="form-check-input" type="radio" name="tv_access" value="all" id="ta_all"<?= $assigned ? '' : ' checked' ?>><label class="form-check-label" for="ta_all"><?= e(__('All TVs')) ?></label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="tv_access" value="some" id="ta_some"<?= $assigned ? ' checked' : '' ?>><label class="form-check-label" for="ta_some"><?= e(__('Only these TVs')) ?></label></div>
            </div>
            <div id="tvAccessPick"<?= $assigned ? '' : ' hidden' ?>>
              <input type="search" class="form-control form-control-sm mb-2" id="taSearch" placeholder="<?= e(__('Search screens or groups…')) ?>" aria-label="<?= e(__('Search screens or groups…')) ?>" autocomplete="off">
              <div class="row g-2">
                <div class="col-md-5">
                  <div class="small fw-semibold text-muted mb-1"><?= e(__('Groups')) ?> <span class="fw-normal">(<?= e(__('all screens in the group, also screens added later')) ?>)</span></div>
                  <div class="border rounded p-2" style="max-height:260px;overflow:auto">
                    <?php if (!$accGroups): ?><div class="text-muted small"><?= e(__('No groups yet. Create groups on the Groups page.')) ?></div><?php endif; ?>
                    <?php foreach ($accGroups as $g): ?>
                      <div class="form-check ta-item" data-search="<?= e(mb_strtolower($g['name'])) ?>">
                        <input class="form-check-input" type="checkbox" name="access_groups[]" value="<?= (int) $g['id'] ?>" id="tag<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $selGroups, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="tag<?= (int) $g['id'] ?>"><?= e($g['name']) ?> <span class="text-muted small">(<?= e((int) $g['members'] === 1 ? __('1 screen') : __(':n screens', ['n' => (int) $g['members']])) ?>)</span></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-md-7">
                  <div class="small fw-semibold text-muted mb-1"><?= e(__('Screens')) ?></div>
                  <div class="border rounded p-2" style="max-height:260px;overflow:auto">
                    <?php if (!$accRooms): ?><div class="text-muted small"><?= e(__('No screens yet.')) ?></div><?php endif; ?>
                    <?php foreach ($accRooms as $r): ?>
                      <div class="form-check form-check-inline ta-item" data-search="<?= e(mb_strtolower($r['room_number'] . ' ' . ($r['name'] ?? '') . ' ' . ($r['floor'] ?? ''))) ?>">
                        <input class="form-check-input" type="checkbox" name="access_rooms[]" value="<?= (int) $r['id'] ?>" id="tar<?= (int) $r['id'] ?>"<?= in_array((int) $r['id'], $selRooms, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="tar<?= (int) $r['id'] ?>"><?= e($r['room_number']) ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <div class="form-text"><?= e(__('A limited user cannot add or delete screens and groups, cannot send to "All screens" and can stop only emergencies shown on their TVs. Content library and playlists stay shared.')) ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="pw"><?= e($u['id'] ? __('New password (leave empty to keep)') : __('Password')) ?><?= $u['id'] ? '' : ' *' ?></label>
          <input class="form-control" type="password" id="pw" name="password" autocomplete="new-password" data-strength="#pwBar"<?= $u['id'] ? '' : ' required' ?>>
          <div class="strength-bar" id="pwBar"><span></span></div>
          <div class="form-text"><span data-strength-label></span> · <?= e(__('At least 8 characters with letters and numbers.')) ?></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="pw2"><?= e(__('Repeat password')) ?></label>
          <input class="form-control" type="password" id="pw2" name="password_confirm" autocomplete="new-password">
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="act" name="is_active" value="1"<?= (int) $u['is_active'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
            <label class="form-check-label" for="act"><?= e(__('Account active (can log in)')) ?></label>
            <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
          </div>
        </div>
        <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save user')) ?></button></div>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const limitable = <?= json_embed(LIMITABLE_ROLES) ?>;
      const role = document.getElementById('role');
      const box = document.getElementById('tvAccess');
      const pick = document.getElementById('tvAccessPick');
      const sync = () => {
        box.hidden = !(limitable.includes(role.value) || role.value.startsWith('role:'));
        pick.hidden = !document.getElementById('ta_some').checked;
      };
      role.addEventListener('change', sync);
      document.querySelectorAll('input[name=tv_access]').forEach((r) => r.addEventListener('change', sync));
      document.getElementById('taSearch').addEventListener('input', (ev) => {
        const q = ev.target.value.trim().toLowerCase();
        document.querySelectorAll('.ta-item').forEach((el) => { el.hidden = q !== '' && !el.dataset.search.includes(q); });
      });
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

if ($action === 'view') {
    $u = hotel_user(req_int('id', $_GET));
    if (!$u) {
        redirect(admin_url('users.php'));
    }
    $sessions = DB::all('SELECT * FROM user_sessions WHERE user_id = :u AND revoked = 0 AND expires_at > :n ORDER BY last_activity DESC', ['u' => $u['id'], 'n' => now()]);
    $acts = DB::all('SELECT * FROM activity_logs WHERE hotel_id = :hid AND user_id = :u ORDER BY id DESC LIMIT 50', ['u' => $u['id']] + hid());
    $currentHash = !empty($_SESSION['hc_token']) ? hash('sha256', (string) $_SESSION['hc_token']) : '';
    $pageTitle = $u['username'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-person"></i> <?= e($u['full_name'] ?: $u['username']) ?></h1><p class="lead-sm"><?= e($u['username']) ?> · <?= e($u['email']) ?> · <?= e(Auth::roleName($u)) ?> · <i class="bi bi-tv"></i> <?= e(access_summary($u)) ?></p></div>
      <div class="d-flex gap-2">
        <a href="<?= e(admin_url('users.php', ['action' => 'edit', 'id' => $u['id']])) ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
        <a href="<?= e(admin_url('users.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header d-flex align-items-center"><span><?= e(__('Active sessions')) ?></span>
            <?php if ($sessions): ?>
            <form method="post" class="ms-auto" data-confirm="<?= e(__('Log this user out everywhere?')) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="revoke_all"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><?= e(__('End all')) ?></button>
            </form>
            <?php endif; ?>
          </div>
          <ul class="list-group list-group-flush">
            <?php if (!$sessions): ?><li class="list-group-item text-muted small"><?= e(__('No active sessions.')) ?></li><?php endif; ?>
            <?php foreach ($sessions as $s): $isCur = hash_equals($s['session_hash'], $currentHash); ?>
              <li class="list-group-item small d-flex gap-2 align-items-center">
                <div class="flex-grow-1 min-w-0">
                  <div><strong><?= e($s['ip_address'] ?? '-') ?></strong> <?php if ($isCur): ?><span class="badge text-bg-success"><?= e(__('this session')) ?></span><?php endif; ?></div>
                  <div class="text-muted text-truncate" title="<?= e($s['user_agent']) ?>"><?= e($s['user_agent'] ?? '') ?></div>
                  <div class="text-muted"><?= e(__('Last active')) ?>: <?= e(time_ago($s['last_activity'])) ?></div>
                </div>
                <?php if (!$isCur): ?>
                <form method="post">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="revoke_session"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('End session')) ?>"><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><?= e(__('Recent activity')) ?></div>
          <div class="table-responsive">
            <table class="table table-sm table-hc">
              <thead><tr><th><?= e(__('When')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('Details')) ?></th><th class="d-none d-md-table-cell">IP</th></tr></thead>
              <tbody>
              <?php if (!$acts): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= e(__('No activity yet.')) ?></td></tr><?php endif; ?>
              <?php foreach ($acts as $a): ?>
                <tr><td class="small text-nowrap"><?= e(date('d M H:i', (int) strtotime($a['created_at']))) ?></td><td><span class="badge text-bg-light border"><?= e($a['action']) ?></span></td><td class="small"><?= e($a['details']) ?></td><td class="d-none d-md-table-cell small"><?= e($a['ip_address']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$users = DB::all("SELECT * FROM users WHERE hotel_id = :hid AND role IN ('super_admin','manager','staff','reception') ORDER BY FIELD(role, 'super_admin', 'manager', 'staff', 'reception'), username", hid());
$customRoles = [];
foreach (Roles::all() as $c) {
    $customRoles[(int) $c['id']] = $c;
}
$roleCounts = Roles::userCounts();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e(__('Users')) ?></h1><p class="lead-sm"><?= e(__('People who can log in to this panel.')) ?></p></div>
  <a class="btn btn-primary" href="<?= e(admin_url('users.php', ['action' => 'new'])) ?>"><i class="bi bi-person-plus"></i> <?= e(__('Add user')) ?></a>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hc table-hover">
          <thead><tr><th><?= e(__('User')) ?></th><th><?= e(__('Role')) ?></th><th><?= e(__('TVs')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Last login')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): $locked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
            <tr>
              <td><a href="<?= e(admin_url('users.php', ['action' => 'view', 'id' => $u['id']])) ?>" class="fw-semibold text-decoration-none"><?= e($u['full_name'] ?: $u['username']) ?></a>
                <div class="small text-muted"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
              <?php $crid = Auth::customRoleId($u); ?>
              <td><?php if ($crid !== null): ?><span class="badge text-bg-success" title="<?= e(__('Custom role')) ?>"><i class="bi bi-person-gear"></i> <?= e($customRoles[$crid]['name'] ?? __('Unknown role')) ?></span>
                <?php else: ?><span class="badge <?= $u['role'] === 'super_admin' ? 'text-bg-dark' : ($u['role'] === 'manager' ? 'text-bg-primary' : ($u['role'] === 'reception' ? 'text-bg-info' : 'text-bg-secondary')) ?>"><?= e(role_label($u['role'])) ?></span><?php endif; ?></td>
              <td class="small"><?php $acc = access_summary($u); ?><span class="<?= $acc === __('All TVs') ? 'text-muted' : 'badge text-bg-warning' ?>"><?= e($acc) ?></span></td>
              <td class="d-none d-md-table-cell small"><?= e(time_ago($u['last_login_at'])) ?><?php if ($u['last_login_ip']): ?><div class="text-muted"><?= e($u['last_login_ip']) ?></div><?php endif; ?></td>
              <td>
                <?php if ($locked): ?><span class="badge text-bg-warning"><i class="bi bi-lock"></i> <?= e(__('Locked')) ?></span>
                <?php elseif (!(int) $u['is_active']): ?><span class="badge text-bg-secondary"><?= e(__('Disabled')) ?></span>
                <?php else: ?><span class="badge text-bg-success"><?= e(__('Active')) ?></span><?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <?php if ($locked || (int) $u['failed_attempts'] > 0): ?>
                  <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="unlock"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-sm btn-outline-warning" title="<?= e(__('Unlock')) ?>"><i class="bi bi-unlock"></i></button></form>
                <?php endif; ?>
                <a class="btn btn-sm btn-light border" href="<?= e(admin_url('users.php', ['action' => 'view', 'id' => $u['id']])) ?>" title="<?= e(__('Activity & sessions')) ?>"><i class="bi bi-clock-history"></i></a>
                <?php if ((int) $u['id'] === (int) $user['id'] || can_manage_user($u)): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(admin_url('users.php', ['action' => 'edit', 'id' => $u['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if ((int) $u['id'] !== (int) $user['id'] && can_manage_user($u)): ?>
                  <form method="post" class="d-inline" data-confirm="<?= e(__('Delete user :u? This cannot be undone.', ['u' => $u['username']])) ?>">
                    <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><span><i class="bi bi-shield-lock"></i> <?= e(__('Roles')) ?></span>
        <?php if (Auth::can('roles.manage')): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(admin_url('roles.php')) ?>"><?= e(__('Manage roles')) ?></a><?php endif; ?></div>
      <ul class="list-group list-group-flush small">
        <?php foreach (ROLES as $r): ?>
          <li class="list-group-item d-flex"><span><?= e(role_label($r)) ?></span><span class="ms-auto text-muted"><?= e(($roleCounts[$r] ?? 0) === 1 ? __('1 user') : __(':n users', ['n' => (int) ($roleCounts[$r] ?? 0)])) ?></span></li>
        <?php endforeach; ?>
        <?php foreach ($customRoles as $c): ?>
          <li class="list-group-item d-flex"><span><i class="bi bi-person-gear text-success"></i> <?= e($c['name']) ?></span><span class="ms-auto text-muted"><?= e((int) $c['user_count'] === 1 ? __('1 user') : __(':n users', ['n' => (int) $c['user_count']])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-diagram-3"></i> <?= e(__('Who can do what')) ?></div>
      <div class="card-body small">
        <ol class="ps-3 mb-2">
          <li class="mb-1"><strong><?= e(role_label('platform_admin')) ?></strong> — <?= e(__('the platform owner: creates customers, plans, invoices and licenses. Not shown in this list.')) ?></li>
          <li class="mb-1"><strong><?= e(role_label('super_admin')) ?></strong> — <?= e(__('one customer (this account): everything here, including users, settings and billing. Admins always control all TVs.')) ?></li>
          <li class="mb-1"><strong><?= e(role_label('manager')) ?></strong> — <?= e(__('screens, content, playlists, schedules, TV commands, APK, logs.')) ?></li>
          <li class="mb-1"><strong><?= e(role_label('staff')) ?></strong> — <?= e(__('view screens and content, send content and emergency messages.')) ?></li>
          <li class="mb-1"><strong><?= e(role_label('reception')) ?></strong> — <?= e(__('front desk: guests check-in/out, service orders and requests, view screens.')) ?></li>
        </ol>
        <p class="mb-0 text-muted"><i class="bi bi-tv"></i> <?= e(__('Managers, Staff and Reception can be limited to some TVs (groups or screens) when you add or edit them. Then they see and control only those TVs.')) ?></p>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-key"></i> <?= e(__('TV settings PIN')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('This 4-digit PIN is needed to open the settings screen on a TV. You can also set a different PIN for a single screen on the Screens page.')) ?></p>
        <form method="post" class="d-flex gap-2">
          <?= Csrf::field() ?><input type="hidden" name="op" value="set_pin">
          <input class="form-control" name="tv_settings_pin" value="<?= e((string) Settings::get('tv_settings_pin', '1234')) ?>" inputmode="numeric" pattern="\d{4}" maxlength="4" required aria-label="<?= e(__('TV settings PIN')) ?>">
          <button class="btn btn-primary"><?= e(__('Save')) ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
