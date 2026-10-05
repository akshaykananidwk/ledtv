<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require();
Csrf::check();
$me = (int) $user['id'];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'profile') {
        $email = req_str('email', $_POST, 190);
        $lang = isset(I18n::LANGUAGES[$_POST['language'] ?? '']) ? (string) $_POST['language'] : 'en';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', __('Enter a valid email address.'));
        } elseif (DB::value('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => $me])) {
            flash('danger', __('This email is already used by another user.'));
        } else {
            DB::update('users', ['full_name' => req_str('full_name', $_POST, 120), 'email' => $email, 'language' => $lang], 'id = :id', ['id' => $me]);
            $_SESSION['lang'] = $lang;
            ActivityLog::add('profile_update', 'user', $me, 'Profile updated');
            I18n::setLang($lang);
            flash('success', __('Your profile was saved.'));
        }
    } elseif ($op === 'password') {
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['new_password_confirm'] ?? null) ? $_POST['new_password_confirm'] : '';
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $me]);
        if (!password_verify($current, $hash)) {
            flash('danger', __('Your current password is not correct.'));
        } elseif ($err = Auth::passwordError($new)) {
            flash('danger', $err);
        } elseif ($new !== $confirm) {
            flash('danger', __('The two passwords do not match.'));
        } elseif (hash_equals($current, $new)) {
            flash('danger', __('The new password must be different from the current one.'));
        } else {
            DB::update('users', ['password_hash' => Auth::hash($new)], 'id = :id', ['id' => $me]);
            Auth::revokeUserSessions($me, true);
            ActivityLog::add('password_change', 'user', $me, 'Changed own password');
            flash('success', __('Password changed. You were logged out on your other devices.'));
        }
    }
    redirect(admin_url('profile.php'));
}

$u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $me]);
$pageTitle = __('My profile');
$activeNav = '';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e(__('My profile')) ?></h1><p class="lead-sm"><?= e($u['username']) ?> · <?= e(role_label($u['role'])) ?></p></div></div>
<div class="row g-3" style="max-width:1100px">
  <div class="col-lg-6">
    <form method="post" class="card h-100">
      <div class="card-header"><i class="bi bi-person"></i> <?= e(__('Your details')) ?></div>
      <div class="card-body row g-3">
        <?= Csrf::field() ?><input type="hidden" name="op" value="profile">
        <div class="col-12"><label class="form-label" for="fn"><?= e(__('Full name')) ?></label><input class="form-control" id="fn" name="full_name" value="<?= e($u['full_name']) ?>" maxlength="120"></div>
        <div class="col-12"><label class="form-label" for="em"><?= e(__('Email')) ?></label><input class="form-control" type="email" id="em" name="email" value="<?= e($u['email']) ?>" required maxlength="190"></div>
        <div class="col-12"><label class="form-label" for="lg"><?= e(__('Language')) ?></label>
          <select class="form-select" id="lg" name="language"><?php foreach (I18n::LANGUAGES as $c => $n): ?><option value="<?= e($c) ?>"<?= $u['language'] === $c ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <form method="post" class="card h-100" autocomplete="off">
      <div class="card-header"><i class="bi bi-key"></i> <?= e(__('Change password')) ?></div>
      <div class="card-body row g-3">
        <?= Csrf::field() ?><input type="hidden" name="op" value="password">
        <input type="text" name="username" value="<?= e($u['username']) ?>" autocomplete="username" hidden>
        <div class="col-12"><label class="form-label" for="cp"><?= e(__('Current password')) ?></label><input class="form-control" type="password" id="cp" name="current_password" required autocomplete="current-password"></div>
        <div class="col-12"><label class="form-label" for="np"><?= e(__('New password')) ?></label>
          <input class="form-control" type="password" id="np" name="new_password" required autocomplete="new-password" data-strength="#npBar">
          <div class="strength-bar" id="npBar"><span></span></div>
          <div class="form-text"><span data-strength-label></span> · <?= e(__('At least 8 characters with letters and numbers.')) ?></div></div>
        <div class="col-12"><label class="form-label" for="np2"><?= e(__('Repeat new password')) ?></label><input class="form-control" type="password" id="np2" name="new_password_confirm" required autocomplete="new-password"></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-shield-check"></i> <?= e(__('Change password')) ?></button></div>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
