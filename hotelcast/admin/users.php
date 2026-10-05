<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('users.manage');
Csrf::check();

const ROLES = ['super_admin', 'manager', 'staff'];

function other_active_super_admins(int $exceptId): int
{
    return (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1 AND id <> :id", ['id' => $exceptId]);
}

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $target = $id ? DB::one('SELECT * FROM users WHERE id = :id', ['id' => $id]) : null;
    $me = (int) $user['id'];

    switch ($op) {
        case 'save':
            if ($id && !$target) {
                flash('danger', __('User not found.'));
                redirect(admin_url('users.php'));
            }
            $username = req_str('username', $_POST, 60);
            $email = req_str('email', $_POST, 190);
            $role = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'staff';
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
            if ($target && (int) $target['id'] === $me && (!$active || $role !== $user['role'])) {
                $errors[] = __('You cannot disable your own account or change your own role.');
            }
            if ($target && $target['role'] === 'super_admin' && ($role !== 'super_admin' || !$active) && other_active_super_admins((int) $target['id']) === 0) {
                $errors[] = __('There must always be at least one active Super Admin.');
            }
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('users.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            $data = ['username' => $username, 'email' => $email, 'full_name' => req_str('full_name', $_POST, 120), 'role' => $role, 'language' => $lang, 'is_active' => $active];
            if ($password !== '') {
                $data['password_hash'] = Auth::hash($password);
            }
            if ($target) {
                DB::update('users', $data, 'id = :id', ['id' => $id]);
                if ($password !== '' || !$active || $role !== $target['role']) {
                    Auth::revokeUserSessions($id, $id === $me);
                }
                ActivityLog::add('user_update', 'user', $id, $username . ($password !== '' ? ' (password reset)' : ''));
            } else {
                $id = DB::insert('users', $data + ['created_at' => now()]);
                ActivityLog::add('user_create', 'user', $id, $username . ' (' . $role . ')');
            }
            flash('success', __('User :u saved.', ['u' => $username]));
            redirect(admin_url('users.php'));

        case 'delete':
            if (!$target) {
                break;
            }
            if ((int) $target['id'] === $me) {
                flash('danger', __('You cannot delete your own account.'));
            } elseif ($target['role'] === 'super_admin' && other_active_super_admins((int) $target['id']) === 0) {
                flash('danger', __('There must always be at least one active Super Admin.'));
            } else {
                DB::delete('users', 'id = :id', ['id' => $id]);
                ActivityLog::add('user_delete', 'user', $id, $target['username']);
                flash('success', __('User :u deleted.', ['u' => $target['username']]));
            }
            break;

        case 'unlock':
            if ($target) {
                DB::update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = :id', ['id' => $id]);
                ActivityLog::add('user_unlock', 'user', $id, $target['username']);
                flash('success', __('User :u unlocked.', ['u' => $target['username']]));
            }
            break;

        case 'revoke_session':
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
                ActivityLog::add('tv_pin_change', 'settings', null, 'Hotel TV settings PIN changed');
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
        $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => req_int('id', $_GET)]);
        if (!$u) {
            flash('warning', __('User not found.'));
            redirect(admin_url('users.php'));
        }
    }
    $isSelf = (int) $u['id'] === (int) $user['id'];
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
            <?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
          </select>
          <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($u['role']) ?>"><?php endif; ?>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="lang"><?= e(__('Language')) ?></label>
          <select class="form-select" id="lang" name="language">
            <?php foreach (I18n::LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"<?= $u['language'] === $code ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 small text-muted">
          <strong><?= e(__('Super Admin')) ?></strong>: <?= e(__('everything, including users, settings and updates.')) ?>
          <strong><?= e(__('Manager')) ?></strong>: <?= e(__('rooms, content, playlists, schedules, TV commands, APK, logs.')) ?>
          <strong><?= e(__('Staff')) ?></strong>: <?= e(__('view rooms and content, send content and emergency messages.')) ?>
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
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

if ($action === 'view') {
    $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => req_int('id', $_GET)]);
    if (!$u) {
        redirect(admin_url('users.php'));
    }
    $sessions = DB::all('SELECT * FROM user_sessions WHERE user_id = :u AND revoked = 0 AND expires_at > :n ORDER BY last_activity DESC', ['u' => $u['id'], 'n' => now()]);
    $acts = DB::all('SELECT * FROM activity_logs WHERE user_id = :u ORDER BY id DESC LIMIT 50', ['u' => $u['id']]);
    $currentHash = !empty($_SESSION['hc_token']) ? hash('sha256', (string) $_SESSION['hc_token']) : '';
    $pageTitle = $u['username'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-person"></i> <?= e($u['full_name'] ?: $u['username']) ?></h1><p class="lead-sm"><?= e($u['username']) ?> · <?= e($u['email']) ?> · <?= e(role_label($u['role'])) ?></p></div>
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

$users = DB::all('SELECT * FROM users ORDER BY FIELD(role, \'super_admin\', \'manager\', \'staff\'), username');
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
          <thead><tr><th><?= e(__('User')) ?></th><th><?= e(__('Role')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Last login')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): $locked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
            <tr>
              <td><a href="<?= e(admin_url('users.php', ['action' => 'view', 'id' => $u['id']])) ?>" class="fw-semibold text-decoration-none"><?= e($u['full_name'] ?: $u['username']) ?></a>
                <div class="small text-muted"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
              <td><span class="badge <?= $u['role'] === 'super_admin' ? 'text-bg-dark' : ($u['role'] === 'manager' ? 'text-bg-primary' : 'text-bg-secondary') ?>"><?= e(role_label($u['role'])) ?></span></td>
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
                <a class="btn btn-sm btn-primary" href="<?= e(admin_url('users.php', ['action' => 'edit', 'id' => $u['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                <?php if ((int) $u['id'] !== (int) $user['id']): ?>
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
    <div class="card">
      <div class="card-header"><i class="bi bi-key"></i> <?= e(__('TV settings PIN')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('Guests need this 4-digit PIN to open the settings screen on a TV. You can also set a different PIN for a single room on the Rooms page.')) ?></p>
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
