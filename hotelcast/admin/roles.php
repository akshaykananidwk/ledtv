<?php
/**
 * Roles (RBAC, docs/modules/roles.md): built-in roles (read-only, "Copy") and the customer's custom roles
 * (create / edit / copy / delete) with a permission matrix grouped by feature. Permissions the plan does
 * not include are hidden. Logic: core/Roles.php, checks: Auth::can().
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('roles.manage');
Csrf::check();

if (!Roles::available()) {
    flash('warning', __('Custom roles need the latest database update. Ask the platform admin to run the update.'));
    redirect(admin_url('users.php'));
}

/** "+a, -b" for the activity log. */
function roles_diff(array $before, array $after): string
{
    $add = array_values(array_diff($after, $before));
    $del = array_values(array_diff($before, $after));
    $parts = array_merge(array_map(static fn ($p) => '+' . $p, $add), array_map(static fn ($p) => '-' . $p, $del));
    return $parts ? implode(', ', $parts) : 'no permission change';
}

$myRoleId = Auth::customRoleId();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    switch ($op) {
        case 'save':
            $existing = $id ? Roles::find($id) : null; // another customer's id → 404
            if ($id && !$existing) {
                flash('warning', __('Role not found.'));
                redirect(admin_url('roles.php'));
            }
            if ($existing && $myRoleId === (int) $existing['id']) {
                Logger::write('security', 'warning', 'Role self-edit refused', ['user' => Auth::id(), 'role' => $id, 'ip' => client_ip()]);
                flash('danger', __('You cannot change your own role.'));
                redirect(admin_url('roles.php'));
            }
            [$data, $errors] = Roles::validate($_POST, $id ?: null);
            $requested = array_values(array_filter((array) ($_POST['perms'] ?? []), 'is_string'));
            [$perms, $kept] = Roles::sanitize($requested, $existing['permissions'] ?? []);
            if (!$perms) {
                $errors[] = __('Choose at least one permission.');
            }
            if ($errors) {
                flash_errors($errors);
                $_SESSION['hc_role_form'] = ['name' => $data['name'], 'description' => $data['description'], 'perms' => $requested];
                redirect(admin_url('roles.php', $id ? ['action' => 'edit', 'id' => $id] : ['action' => 'new']));
            }
            $newId = Roles::save($data, $perms, $existing ? $id : null);
            $diff = roles_diff($existing['permissions'] ?? [], $perms);
            ActivityLog::add($existing ? 'role_update' : 'role_create', 'role', $newId, $data['name'] . ': ' . $diff);
            if ($kept) {
                Logger::write('security', 'warning', 'Role permissions outside the editor\'s rights were kept unchanged', ['user' => Auth::id(), 'role' => $newId, 'perms' => $kept]);
                flash('warning', __('Some permissions were not changed: only an Admin can give permissions you do not have yourself, or "Manage roles".'));
            }
            flash('success', __('Role ":n" saved. Its users get the new permissions on their next click.', ['n' => $data['name']]));
            redirect(admin_url('roles.php'));

        case 'delete':
            $role = Roles::find($id);
            if (!$role) {
                flash('warning', __('Role not found.'));
                break;
            }
            if ($myRoleId === (int) $role['id']) {
                flash('danger', __('You cannot delete your own role.'));
                break;
            }
            $users = Roles::usersOf((int) $role['id']);
            $reassign = $users ? req_str('reassign', $_POST, 20) : null;
            if ($users && ($reassign === '' || !Roles::parseSpec((string) $reassign) || !Roles::canAssign((string) $reassign))) {
                flash('danger', __('This role still has users. Choose the role they get instead.'));
                redirect(admin_url('roles.php', ['action' => 'delete', 'id' => $id]));
            }
            if ($users && $reassign === 'super_admin' && !Auth::isAdmin()) {
                flash('danger', __('Only an Admin can give the Admin role.'));
                redirect(admin_url('roles.php', ['action' => 'delete', 'id' => $id]));
            }
            try {
                $moved = Roles::delete($role, $reassign);
            } catch (InvalidArgumentException $e) {
                flash('danger', $e->getMessage());
                redirect(admin_url('roles.php', ['action' => 'delete', 'id' => $id]));
            }
            ActivityLog::add('role_delete', 'role', (int) $role['id'], $role['name'] . ($moved ? ' (' . $moved . ' users → ' . $reassign . ')' : ''));
            foreach ($users as $u) {
                ActivityLog::add('user_update', 'user', (int) $u['id'], $u['username'] . ': role ' . $role['name'] . ' → ' . Roles::specLabel((string) $reassign));
            }
            flash('success', __('Role ":n" deleted.', ['n' => $role['name']]));
            break;

        default:
            flash('warning', __('Unknown action.'));
    }
    redirect(admin_url('roles.php'));
}

$action = req_str('action', $_GET, 20);
$pageTitle = __('Roles');
$activeNav = 'roles';
$isAdmin = Auth::isAdmin();
$myPerms = Roles::editorPermissions();
$counts = Roles::userCounts();

/** Permission matrix (checkboxes, "select all" per group). $locked: perms that cannot be changed here. */
$renderMatrix = static function (array $selected, bool $readOnly) use ($isAdmin, $myPerms): void {
    foreach (Roles::matrix() as $gkey => $g):
        $gid = 'g_' . preg_replace('/[^a-z0-9_]/i', '_', (string) $gkey);
        ?>
        <div class="card mb-3 role-group" data-group="<?= e($g['label']) ?>">
          <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <div class="flex-grow-1 min-w-0">
              <strong><?= e($g['label']) ?></strong>
              <?php if ($g['description'] !== ''): ?><div class="small text-muted"><?= e($g['description']) ?></div><?php endif; ?>
            </div>
            <?php if (!$readOnly): ?>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input js-group-all" type="checkbox" role="switch" id="<?= e($gid) ?>" aria-label="<?= e(__('Select all in :g', ['g' => $g['label']])) ?>">
              <label class="form-check-label small" for="<?= e($gid) ?>"><?= e(__('Select all')) ?></label>
            </div>
            <?php endif; ?>
          </div>
          <ul class="list-group list-group-flush">
            <?php foreach ($g['perms'] as $perm => $info):
                $locked = !$readOnly && !$isAdmin && (!in_array($perm, $myPerms, true) || in_array($perm, Roles::ADMIN_ONLY, true));
                $pid = 'p_' . str_replace('.', '_', $perm);
                ?>
              <li class="list-group-item d-flex gap-2 align-items-start">
                <input class="form-check-input mt-1 js-perm" type="checkbox" name="perms[]" value="<?= e($perm) ?>" id="<?= e($pid) ?>" data-label="<?= e($info['label']) ?>"
                  <?= in_array($perm, $selected, true) ? ' checked' : '' ?><?= $readOnly || $locked ? ' disabled' : '' ?>>
                <label class="flex-grow-1 min-w-0" for="<?= e($pid) ?>">
                  <span class="fw-semibold"><?= e($info['label']) ?></span>
                  <span class="badge <?= $info['kind'] === 'view' ? 'text-bg-light border' : ($info['kind'] === 'manage' ? 'text-bg-primary' : 'text-bg-info') ?> ms-1"><?= e(Roles::kindLabel($info['kind'])) ?></span>
                  <?php if ($locked): ?><span class="badge text-bg-warning ms-1" title="<?= e(__('Only an Admin can change this permission.')) ?>"><i class="bi bi-lock"></i> <?= e(__('Admin only')) ?></span><?php endif; ?>
                  <?php if ($info['description'] !== ''): ?><div class="small text-muted"><?= e($info['description']) ?></div><?php endif; ?>
                  <div class="small text-muted font-monospace d-none d-md-block"><?= e($perm) ?></div>
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php
    endforeach;
};

/** "This role can …" preview. */
$renderSummary = static function (array $perms): void {
    $sum = Roles::summary($perms);
    ?>
    <div id="rolePreview" class="small">
      <?php if (!$sum): ?><p class="text-muted mb-0" data-empty><?= e(__('No permissions yet.')) ?></p><?php endif; ?>
      <?php foreach ($sum as $s): ?>
        <div class="mb-1"><strong><?= e($s['group']) ?>:</strong> <?= e(implode(', ', $s['items'])) ?></div>
      <?php endforeach; ?>
    </div>
    <?php
};

// ------------------------------------------------------------------ view a built-in role
if ($action === 'view') {
    $spec = req_str('role', $_GET, 20);
    $builtIn = Roles::builtIn();
    if (!isset($builtIn[$spec])) {
        redirect(admin_url('roles.php'));
    }
    $r = $builtIn[$spec];
    $pageTitle = $r['name'];
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-shield-lock"></i> <?= e($r['name']) ?> <span class="badge text-bg-secondary align-middle fs-6"><?= e(__('Built-in')) ?></span></h1>
        <p class="lead-sm"><?= e(ucfirst($r['description'])) ?> <?= e(__('Built-in roles cannot be changed. Copy one to start a custom role.')) ?></p></div>
      <div class="d-flex gap-2">
        <a class="btn btn-primary" href="<?= e(admin_url('roles.php', ['action' => 'copy', 'from' => $spec])) ?>"><i class="bi bi-copy"></i> <?= e(__('Copy')) ?></a>
        <a class="btn btn-light border" href="<?= e(admin_url('roles.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-8"><?php $renderMatrix($r['permissions'], true); ?></div>
      <div class="col-lg-4"><div class="card"><div class="card-header"><?= e(__('This role can …')) ?></div><div class="card-body"><?php $renderSummary($r['permissions']); ?></div></div></div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ------------------------------------------------------------------ delete (reassign users first)
if ($action === 'delete') {
    $role = Roles::find(req_int('id', $_GET));
    if (!$role) {
        flash('warning', __('Role not found.'));
        redirect(admin_url('roles.php'));
    }
    $users = Roles::usersOf((int) $role['id']);
    $targets = [];
    foreach (Roles::builtIn() as $k => $b) {
        if (Roles::canAssign($k)) {
            $targets[$k] = $b['name'];
        }
    }
    foreach (Roles::all() as $c) {
        if ((int) $c['id'] !== (int) $role['id'] && Roles::canAssign($c['key'])) {
            $targets[$c['key']] = $c['name'];
        }
    }
    $pageTitle = __('Delete role');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-trash"></i> <?= e(__('Delete role ":n"', ['n' => $role['name']])) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('roles.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card card-body" style="max-width:640px">
      <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
      <?php if ($users): ?>
        <p><?= e(__('These users have this role. Choose the role they get instead:')) ?></p>
        <ul class="small"><?php foreach ($users as $u): ?><li><?= e($u['full_name'] ?: $u['username']) ?> <span class="text-muted">(<?= e($u['username']) ?>)</span></li><?php endforeach; ?></ul>
        <label class="form-label" for="reassign"><?= e(__('New role for these users')) ?></label>
        <select class="form-select mb-3" id="reassign" name="reassign" required>
          <option value=""><?= e(__('Choose…')) ?></option>
          <?php foreach ($targets as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      <?php else: ?>
        <p><?= e(__('No user has this role. It can be deleted.')) ?></p>
      <?php endif; ?>
      <div><button class="btn btn-danger"><i class="bi bi-trash"></i> <?= e(__('Delete role')) ?></button></div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ------------------------------------------------------------------ new / edit / copy
if (in_array($action, ['new', 'edit', 'copy'], true)) {
    $role = ['id' => 0, 'name' => '', 'description' => '', 'permissions' => ['dashboard.view']];
    if ($action === 'edit') {
        $role = Roles::find(req_int('id', $_GET));
        if (!$role) {
            flash('warning', __('Role not found.'));
            redirect(admin_url('roles.php'));
        }
        if ($myRoleId === (int) $role['id']) {
            flash('danger', __('You cannot change your own role.'));
            redirect(admin_url('roles.php'));
        }
    } elseif ($action === 'copy') {
        $from = req_str('from', $_GET, 20);
        $builtIn = Roles::builtIn();
        if (isset($builtIn[$from])) {
            $src = $builtIn[$from];
        } else {
            $src = preg_match('/^role:(\d{1,9})$/', $from, $m) ? Roles::find((int) $m[1]) : null;
        }
        if (!$src) {
            flash('warning', __('Role not found.'));
            redirect(admin_url('roles.php'));
        }
        $role['name'] = mb_substr(__('Copy of :n', ['n' => $src['name']]), 0, Roles::MAX_NAME);
        $role['description'] = (string) $src['description'];
        $role['permissions'] = array_values(array_filter($src['permissions'], static fn ($p) => $isAdmin || !in_array($p, Roles::ADMIN_ONLY, true)));
    }
    if (!empty($_SESSION['hc_role_form']) && is_array($_SESSION['hc_role_form'])) {
        // Values of a form that failed validation.
        $old = $_SESSION['hc_role_form'];
        unset($_SESSION['hc_role_form']);
        $role['name'] = (string) ($old['name'] ?? $role['name']);
        $role['description'] = (string) ($old['description'] ?? $role['description']);
        $role['permissions'] = array_values(array_filter((array) ($old['perms'] ?? []), 'is_string'));
    }
    $pageTitle = $role['id'] ? __('Edit role') : __('New role');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-shield-lock"></i> <?= e($pageTitle) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('roles.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" id="roleForm" autocomplete="off">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card card-body mb-3">
            <div class="row g-3">
              <div class="col-md-5">
                <label class="form-label" for="rname"><?= e(__('Role name')) ?> *</label>
                <input class="form-control" id="rname" name="name" value="<?= e($role['name']) ?>" required maxlength="<?= Roles::MAX_NAME ?>" placeholder="<?= e(__('e.g. Content editor')) ?>">
              </div>
              <div class="col-md-7">
                <label class="form-label" for="rdesc"><?= e(__('Description')) ?></label>
                <input class="form-control" id="rdesc" name="description" value="<?= e($role['description']) ?>" maxlength="<?= Roles::MAX_DESCRIPTION ?>">
              </div>
            </div>
            <div class="form-text"><?= e(__('Only features of your plan are listed. Which screens a user may control is set per user on the Users page.')) ?></div>
          </div>
          <?php $renderMatrix($role['permissions'], false); ?>
        </div>
        <div class="col-lg-4">
          <div class="card position-sticky" style="top:1rem">
            <div class="card-header"><?= e(__('This role can …')) ?></div>
            <div class="card-body"><?php $renderSummary($role['permissions']); ?></div>
            <div class="card-footer"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= e(__('Save role')) ?></button></div>
          </div>
        </div>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('roleForm');
      const preview = document.getElementById('rolePreview');
      const emptyText = <?= json_embed(__('No permissions yet.')) ?>;
      const syncGroup = (card) => {
        const boxes = [...card.querySelectorAll('.js-perm:not(:disabled)')];
        const all = card.querySelector('.js-group-all');
        if (all) {
          all.checked = boxes.length > 0 && boxes.every((b) => b.checked);
          all.indeterminate = !all.checked && boxes.some((b) => b.checked);
          all.disabled = boxes.length === 0;
        }
      };
      const render = () => {
        preview.textContent = '';
        let any = false;
        form.querySelectorAll('.role-group').forEach((card) => {
          const items = [...card.querySelectorAll('.js-perm:checked')].map((b) => b.dataset.label);
          if (!items.length) return;
          any = true;
          const div = document.createElement('div');
          div.className = 'mb-1';
          const s = document.createElement('strong');
          s.textContent = card.dataset.group + ': ';
          div.append(s, document.createTextNode(items.join(', ')));
          preview.append(div);
        });
        if (!any) {
          const p = document.createElement('p');
          p.className = 'text-muted mb-0';
          p.textContent = emptyText;
          preview.append(p);
        }
      };
      form.querySelectorAll('.role-group').forEach((card) => {
        const all = card.querySelector('.js-group-all');
        if (all) {
          all.addEventListener('change', () => {
            card.querySelectorAll('.js-perm:not(:disabled)').forEach((b) => { b.checked = all.checked; });
            syncGroup(card);
            render();
          });
        }
        card.querySelectorAll('.js-perm').forEach((b) => b.addEventListener('change', () => { syncGroup(card); render(); }));
        syncGroup(card);
      });
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ------------------------------------------------------------------ list
$builtIn = Roles::builtIn();
$custom = Roles::all();
$visible = array_flip(Roles::visiblePermissions());
$countVisible = static fn (array $perms): int => count(array_filter($perms, static fn ($p) => isset($visible[$p])));
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-shield-lock"></i> <?= e(__('Roles')) ?></h1><p class="lead-sm"><?= e(__('What each user may do in this account. Built-in roles are fixed; copy one to make your own role.')) ?></p></div>
  <div class="d-flex gap-2">
    <a class="btn btn-light border" href="<?= e(admin_url('users.php')) ?>"><i class="bi bi-people"></i> <?= e(__('Users')) ?></a>
    <a class="btn btn-primary" href="<?= e(admin_url('roles.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New role')) ?></a>
  </div>
</div>
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-lock"></i> <?= e(__('Built-in roles')) ?></div>
  <div class="table-responsive">
    <table class="table table-hc mb-0">
      <thead><tr><th><?= e(__('Role')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Description')) ?></th><th><?= e(__('Permissions')) ?></th><th><?= e(__('Users')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($builtIn as $k => $r): ?>
        <tr>
          <td><span class="fw-semibold"><?= e($r['name']) ?></span> <span class="badge text-bg-secondary"><?= e(__('Built-in')) ?></span></td>
          <td class="d-none d-md-table-cell small text-muted"><?= e(ucfirst($r['description'])) ?></td>
          <td><?= $countVisible($r['permissions']) ?></td>
          <td><?= (int) ($counts[$k] ?? 0) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('roles.php', ['action' => 'view', 'role' => $k])) ?>" title="<?= e(__('View')) ?>"><i class="bi bi-eye"></i></a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('roles.php', ['action' => 'copy', 'from' => $k])) ?>"><i class="bi bi-copy"></i> <?= e(__('Copy')) ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="bi bi-person-gear"></i> <?= e(__('Custom roles')) ?></div>
  <div class="table-responsive">
    <table class="table table-hc mb-0">
      <thead><tr><th><?= e(__('Role')) ?></th><th class="d-none d-md-table-cell"><?= e(__('This role can …')) ?></th><th><?= e(__('Users')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php if (!$custom): ?><tr><td colspan="4" class="text-muted text-center py-4"><?= e(__('No custom roles yet. Click "New role" or copy a built-in role.')) ?></td></tr><?php endif; ?>
      <?php foreach ($custom as $r): $mine = $myRoleId === (int) $r['id']; ?>
        <tr>
          <td><span class="fw-semibold"><?= e($r['name']) ?></span><?php if ($mine): ?> <span class="badge text-bg-info"><?= e(__('your role')) ?></span><?php endif; ?>
            <?php if ($r['description'] !== ''): ?><div class="small text-muted"><?= e($r['description']) ?></div><?php endif; ?></td>
          <td class="d-none d-md-table-cell small">
            <?php foreach (Roles::summary($r['permissions']) as $s): ?><div><strong><?= e($s['group']) ?>:</strong> <?= e(implode(', ', $s['items'])) ?></div><?php endforeach; ?>
          </td>
          <td><?= (int) $r['user_count'] ?></td>
          <td class="text-end text-nowrap">
            <?php if (!$mine): ?><a class="btn btn-sm btn-primary" href="<?= e(admin_url('roles.php', ['action' => 'edit', 'id' => $r['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('roles.php', ['action' => 'copy', 'from' => $r['key']])) ?>" title="<?= e(__('Copy')) ?>"><i class="bi bi-copy"></i></a>
            <?php if (!$mine): ?>
              <?php if ((int) $r['user_count'] > 0): ?>
                <a class="btn btn-sm btn-outline-danger" href="<?= e(admin_url('roles.php', ['action' => 'delete', 'id' => $r['id']])) ?>" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></a>
              <?php else: ?>
                <form method="post" class="d-inline" data-confirm="<?= e(__('Delete role :n?', ['n' => $r['name']])) ?>">
                  <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle"></i> <?= e(__('A user can do something only when their role allows it and your plan includes it. Screen access (all screens or only some) is set per user.')) ?></p>
<?php require __DIR__ . '/partials/footer.php'; ?>
