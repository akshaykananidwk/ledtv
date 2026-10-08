<?php
/**
 * Platform → Sign-ups & trials (#17): online sign-up requests (verify / pending / approved /
 * rejected / expired), approve / reject (manual approval mode), trial end + conversion, extend a
 * trial, and the sign-up settings (enable, approval mode, trial days / plan / TV limit, terms,
 * notification email, captcha).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('signup.manage');
Csrf::check();

$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'settings' ? 'settings' : 'list';

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    try {
        switch ($op) {
            case 'approve':
                [$hid] = Signup::approve($id, Auth::id());
                ActivityLog::add('signup_approve', 'signup', $id, 'Customer #' . $hid);
                flash('success', __('Sign-up approved: the trial customer was created and the owner was informed.'));
                break;
            case 'reject':
                Signup::reject($id, Auth::id(), req_str('reason', $_POST, 255));
                ActivityLog::add('signup_reject', 'signup', $id);
                flash('success', __('Sign-up rejected.'));
                break;
            case 'extend':
                $hid = req_int('hotel_id', $_POST);
                Signup::extendTrial($hid, max(1, req_int('days', $_POST) ?: 7));
                ActivityLog::add('trial_extend', 'hotel', $hid, '+' . (req_int('days', $_POST) ?: 7) . ' days');
                flash('success', __('Trial extended.'));
                break;
            case 'settings':
                $errors = [];
                $mode = req_str('signup_mode', $_POST, 10);
                $email = req_str('signup_notify_email', $_POST, 500);
                foreach (array_filter(array_map('trim', explode(',', $email))) as $addr) {
                    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = __('Invalid email: :e', ['e' => $addr]);
                    }
                }
                $plan = req_int('trial_plan_id', $_POST);
                if ($plan && !DB::value('SELECT id FROM plans WHERE id = :id', ['id' => $plan])) {
                    $plan = 0;
                }
                $max = trim(req_str('trial_max_tvs', $_POST, 6));
                $domains = implode("\n", array_slice(array_values(array_unique(array_filter(array_map(
                    fn ($d) => strtolower(trim($d)),
                    preg_split('/[\s,;]+/', (string) ($_POST['signup_blocked_domains'] ?? '')) ?: []
                ), fn ($d) => strlen($d) <= 190 && (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)))), 0, 500));
                if ($errors) {
                    flash_errors($errors);
                    redirect(admin_url('platform_signups.php', ['tab' => 'settings']));
                }
                foreach ([
                    'signup_enabled' => !empty($_POST['signup_enabled']) ? '1' : '0',
                    'signup_mode' => in_array($mode, Signup::MODES, true) ? $mode : 'otp',
                    'trial_days' => (string) max(1, min(90, req_int('trial_days', $_POST) ?: 14)),
                    'trial_plan_id' => $plan ? (string) $plan : '',
                    'trial_max_tvs' => $max === '' ? '' : (string) max(1, min(10000, (int) $max)),
                    'signup_terms' => mb_substr(trim((string) ($_POST['signup_terms'] ?? '')), 0, 10000),
                    'signup_notify_email' => $email,
                    'signup_captcha' => !empty($_POST['signup_captcha']) ? '1' : '0',
                    'signup_min_seconds' => (string) max(0, min(60, (int) ($_POST['signup_min_seconds'] ?? 3))),
                    'signup_blocked_domains' => $domains,
                ] as $k => $v) {
                    Settings::setPlatform($k, $v);
                }
                ActivityLog::add('platform_settings', 'settings', null, 'Saved sign-up settings');
                flash('success', __('Settings saved.'));
                redirect(admin_url('platform_signups.php', ['tab' => 'settings']));
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('platform_signups.php', array_filter(['status' => req_str('back_status', $_POST, 20)])));
}

$stats = Signup::stats();
$fStatus = in_array($_GET['status'] ?? '', ['verify', 'pending', 'approved', 'rejected', 'expired', 'trial', 'converted'], true) ? (string) $_GET['status'] : '';
$q = req_str('q', $_GET, 100);
$where = [];
$p = [];
if (in_array($fStatus, ['verify', 'pending', 'approved', 'rejected', 'expired'], true)) {
    $where[] = 's.status = :st';
    $p['st'] = $fStatus;
} elseif ($fStatus === 'trial') {
    $where[] = 'h.is_trial = 1';
} elseif ($fStatus === 'converted') {
    $where[] = 's.converted_at IS NOT NULL';
}
if ($q !== '') {
    $where[] = '(s.hotel_name LIKE :q1 OR s.email LIKE :q2 OR s.mobile LIKE :q3 OR s.owner_name LIKE :q4 OR s.city LIKE :q5)';
    $p += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%", 'q5' => "%$q%"];
}
$page = max(1, req_int('page', $_GET));
$per = 50;
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) DB::value('SELECT COUNT(*) FROM signups s LEFT JOIN hotels h ON h.id = s.hotel_id' . $sqlWhere, $p);
$rows = DB::all(
    'SELECT s.*, h.status AS hotel_status, h.is_trial, h.expires_at AS hotel_expires, p.name AS plan_name
     FROM signups s LEFT JOIN hotels h ON h.id = s.hotel_id LEFT JOIN plans p ON p.id = h.plan_id'
    . $sqlWhere . ' ORDER BY s.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
    $p
);
$S = Settings::all(0);
$sv = fn (string $k) => (string) ($S[$k] ?? Signup::DEFAULTS[$k] ?? '');
$statusLabels = [
    'verify' => [__('Email not verified'), 'text-bg-light border'], 'pending' => [__('Waiting for approval'), 'text-bg-warning'],
    'approved' => [__('Approved'), 'text-bg-success'], 'rejected' => [__('Rejected'), 'text-bg-secondary'], 'expired' => [__('Expired'), 'text-bg-light border'],
];
$pageTitle = __('Sign-ups & trials');
$activeNav = 'platform_signups';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e(__('Sign-ups & trials')) ?></h1>
  <p class="lead-sm"><?= e(__('Businesses that signed up online for a free trial.')) ?>
    <?php if (Signup::enabled()): ?><a href="<?= e(base_url('signup.php')) ?>" target="_blank" rel="noopener"><?= e(base_url('signup.php')) ?></a>
    <?php else: ?><span class="badge text-bg-secondary"><?= e(__('Sign-up page is switched off')) ?></span><?php endif; ?></p></div></div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'list' ? ' active' : '' ?>" href="<?= e(admin_url('platform_signups.php')) ?>"><i class="bi bi-list-ul"></i> <?= e(__('Sign-ups')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>" href="<?= e(admin_url('platform_signups.php', ['tab' => 'settings'])) ?>"><i class="bi bi-sliders"></i> <?= e(__('Settings')) ?></a></li>
</ul>

<?php if ($tab === 'list'): ?>
<div class="row g-3 mb-3">
  <?php foreach ([
      [__('Waiting for approval'), $stats['pending'], 'bi-hourglass-split', 'bg-soft-warning', 'pending'],
      [__('Active trials'), $stats['active_trials'], 'bi-rocket-takeoff', 'bg-soft-primary', 'trial'],
      [__('Converted to paid'), $stats['converted'] . ' · ' . $stats['conversion'] . '%', 'bi-graph-up-arrow', 'bg-soft-success', 'converted'],
      [__('Sign-ups (last 7 days)'), $stats['last7'], 'bi-person-plus', 'bg-soft-info', ''],
  ] as [$label, $val, $icon, $cls, $filter]): ?>
    <div class="col-6 col-lg-3"><a class="card h-100 text-decoration-none text-body" href="<?= e(admin_url('platform_signups.php', array_filter(['status' => $filter]))) ?>"><div class="stat-card"><div class="stat-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div><div class="min-w-0"><div class="stat-value"><?= e((string) $val) ?></div><div class="stat-label"><?= e($label) ?></div></div></div></a></div>
  <?php endforeach; ?>
</div>
<form class="d-flex flex-wrap gap-2 mb-3" method="get">
  <select class="form-select form-select-sm" style="max-width:220px" name="status" aria-label="<?= e(__('Status')) ?>" onchange="this.form.submit()">
    <option value=""><?= e(__('All')) ?></option>
    <?php foreach ($statusLabels + ['trial' => [__('Active trials')], 'converted' => [__('Converted to paid')]] as $k => $l): ?>
      <option value="<?= e($k) ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= e($l[0]) ?></option>
    <?php endforeach; ?>
  </select>
  <input class="form-control form-control-sm" style="max-width:260px" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search business, email, mobile…')) ?>">
  <button class="btn btn-sm btn-light border"><i class="bi bi-search"></i></button>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hc table-hover mb-0">
  <thead><tr><th><?= e(__('Date')) ?></th><th><?= e(__('Customer')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Owner')) ?></th><th class="text-end"><?= e(__('TVs')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Trial ends')) ?></th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No sign-ups yet.')) ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): [$sl, $sc] = $statusLabels[$r['status']] ?? [$r['status'], 'text-bg-light']; ?>
    <tr>
      <td class="small text-nowrap"><?= e(date('d M Y H:i', (int) strtotime((string) $r['created_at']))) ?></td>
      <td><strong><?= e($r['hotel_name']) ?></strong><div class="small text-muted"><?= e((string) $r['city']) ?> · <?= e(strtoupper((string) $r['language'])) ?></div>
        <?php if ((int) $r['duplicate']): ?><span class="badge text-bg-danger" title="<?= e(__('Email or mobile already belongs to an existing account.')) ?>"><?= e(__('Existing customer?')) ?></span><?php endif; ?></td>
      <td class="d-none d-md-table-cell small"><?= e($r['owner_name']) ?><br><?= e($r['email']) ?><br><?= e($r['mobile']) ?></td>
      <td class="text-end"><?= (int) $r['tv_estimate'] ?></td>
      <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
        <?php if ($r['hotel_id']): ?><br><?= Hotels::statusBadge((string) $r['hotel_status']) ?>
          <?php if ($r['converted_at']): ?><span class="badge text-bg-success"><?= e(__('Paid')) ?>: <?= e((string) $r['plan_name']) ?></span><?php elseif ((int) $r['is_trial']): ?><span class="badge text-bg-info"><?= e(__('Trial')) ?></span><?php endif; ?>
          <?php if ($r['upgrade_invoice_id'] && !$r['converted_at']): ?><span class="badge text-bg-warning"><?= e(__('Upgrade requested')) ?></span><?php endif; ?>
        <?php endif; ?>
        <?php if ($r['reject_reason']): ?><div class="small text-muted"><?= e($r['reject_reason']) ?></div><?php endif; ?></td>
      <td class="d-none d-lg-table-cell small text-nowrap"><?= $r['hotel_id'] && (int) $r['is_trial'] && $r['hotel_expires'] ? e(date('d M Y', (int) strtotime((string) $r['hotel_expires']))) : '—' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($r['status'] === 'pending'): ?>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back_status" value="<?= e($fStatus) ?>">
            <button class="btn btn-sm btn-success"<?= $r['password_hash'] ? '' : ' disabled' ?>><i class="bi bi-check-lg"></i> <?= e(__('Approve')) ?></button></form>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['pending', 'verify'], true)): ?>
          <form method="post" class="d-inline" data-confirm="<?= e(__('Reject this sign-up?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back_status" value="<?= e($fStatus) ?>">
            <button class="btn btn-sm btn-light border"><i class="bi bi-x-lg"></i> <?= e(__('Reject')) ?></button></form>
        <?php endif; ?>
        <?php if ($r['hotel_id'] && (int) $r['is_trial']): ?>
          <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="extend"><input type="hidden" name="hotel_id" value="<?= (int) $r['hotel_id'] ?>"><input type="hidden" name="days" value="7"><input type="hidden" name="back_status" value="<?= e($fStatus) ?>">
            <button class="btn btn-sm btn-light border" title="<?= e(__('Extend the trial by 7 days')) ?>"><i class="bi bi-calendar-plus"></i> +7</button></form>
        <?php endif; ?>
        <?php if ($r['hotel_id']): ?><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $r['hotel_id']])) ?>" title="<?= e(__('Customer')) ?>"><i class="bi bi-building"></i></a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?= paginate($total, $page, $per) ?>

<?php else: $plans = Hotels::plans(true); ?>
<form method="post" class="card" style="max-width:900px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="settings"><input type="hidden" name="tab" value="settings">
  <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="s_en" name="signup_enabled" value="1"<?= $sv('signup_enabled') === '1' ? ' checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="s_en"><?= e(__('Allow businesses to sign up online for a free trial')) ?></label></div>
    <div class="form-text"><?= e(__('Shows "Start free trial" on the login page and enables :url.', ['url' => base_url('signup.php')])) ?></div></div>
  <div class="col-md-6"><label class="form-label" for="s_mode"><?= e(__('New sign-ups')) ?></label>
    <select class="form-select" id="s_mode" name="signup_mode">
      <?php foreach (['otp' => __('Verify the email address with a 6-digit code (needs working email)'), 'auto' => __('Approve automatically (no email needed)'), 'manual' => __('Manual approval by a platform admin')] as $k => $l): ?>
        <option value="<?= e($k) ?>"<?= $sv('signup_mode') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text"><?= e(__('If your server cannot send email, choose automatic or manual approval.')) ?></div></div>
  <div class="col-md-6"><label class="form-label" for="s_ne"><?= e(__('Notify about new sign-ups')) ?></label><input class="form-control" id="s_ne" name="signup_notify_email" value="<?= e($sv('signup_notify_email')) ?>" placeholder="sales@example.com">
    <div class="form-text"><?= e(__('Empty = platform admin email(s) from the platform settings.')) ?></div></div>
  <div class="col-sm-4"><label class="form-label" for="s_td"><?= e(__('Trial length')) ?></label><div class="input-group"><input class="form-control" type="number" min="1" max="90" id="s_td" name="trial_days" value="<?= e($sv('trial_days')) ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div></div>
  <div class="col-sm-4"><label class="form-label" for="s_tp"><?= e(__('Trial plan')) ?></label><select class="form-select" id="s_tp" name="trial_plan_id">
    <option value=""><?= e(__('— No plan (all modules) —')) ?></option>
    <?php foreach ($plans as $pl): ?><option value="<?= (int) $pl['id'] ?>"<?= $sv('trial_plan_id') === (string) $pl['id'] ? ' selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select>
    <div class="form-text"><?= e(__('Modules available during the trial.')) ?></div></div>
  <div class="col-sm-4"><label class="form-label" for="s_mt"><?= e(__('Max TVs during the trial')) ?></label><input class="form-control" type="number" min="1" id="s_mt" name="trial_max_tvs" value="<?= e($sv('trial_max_tvs')) ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
  <div class="col-12"><label class="form-label" for="s_terms"><?= e(__('Terms shown on the sign-up page')) ?></label><textarea class="form-control" id="s_terms" name="signup_terms" rows="6"><?= e($sv('signup_terms')) ?></textarea></div>
  <div class="col-12"><h2 class="h6 mt-2"><i class="bi bi-shield-check"></i> <?= e(__('Spam protection')) ?></h2>
    <p class="small text-muted mb-2"><?= e(__('Always on: hidden honeypot field, max 3 sign-ups per IP address per hour and per email / mobile per day, blocked throw-away email domains.')) ?></p></div>
  <div class="col-sm-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="s_cap" name="signup_captcha" value="1"<?= $sv('signup_captcha') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="s_cap"><?= e(__('Simple math question (captcha)')) ?></label></div></div>
  <div class="col-sm-6"><label class="form-label" for="s_ms"><?= e(__('Minimum time to fill the form')) ?></label><div class="input-group"><input class="form-control" type="number" min="0" max="60" id="s_ms" name="signup_min_seconds" value="<?= e($sv('signup_min_seconds')) ?>"><span class="input-group-text"><?= e(__('seconds')) ?></span></div></div>
  <div class="col-12"><label class="form-label" for="s_bd"><?= e(__('More blocked email domains')) ?></label><textarea class="form-control mono" id="s_bd" name="signup_blocked_domains" rows="3" placeholder="example-temp-mail.com"><?= e($sv('signup_blocked_domains')) ?></textarea>
    <div class="form-text"><?= e(__('One per line. Built in: :list …', ['list' => implode(', ', array_slice(Signup::DISPOSABLE_DOMAINS, 0, 6))])) ?></div></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
