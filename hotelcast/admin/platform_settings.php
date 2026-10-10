<?php
/**
 * Platform settings (hotel_id 0): white-label branding (#21), billing (#20), notifications and —
 * on self-hosted installs — the license status (#19).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('platform.manage');
Csrf::check();

$saas = License::mode() === 'saas';
$tabs = ['branding' => [__('Branding'), 'bi-palette']];
if ($saas) {
    $tabs['billing'] = [__('Billing'), 'bi-receipt'];
}
$tabs['email'] = [__('Email (SMTP)'), 'bi-envelope-at'];
$tabs['notify'] = [__('Notifications'), 'bi-bell'];
$tabs['features'] = [__('Features'), 'bi-toggles'];
$tabs['data_feeds'] = [__('Data feeds'), 'bi-broadcast'];
$tabs['admins'] = [__('Platform admins'), 'bi-shield-lock'];
$tabs['license'] = [__('License'), 'bi-key'];
$tab = isset($tabs[$_GET['tab'] ?? $_POST['tab'] ?? '']) ? (string) ($_GET['tab'] ?? $_POST['tab']) : 'branding';

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $errors = [];
    $P = fn (string $k, int $max = 500) => req_str($k, $_POST, $max);
    switch ($op) {
        case 'branding':
            $color = $P('platform_color', 7);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors[] = __('Colour must look like #7B1FA2.');
            }
            $email = $P('platform_support_email', 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Enter a valid email address.');
            }
            if (!$errors && isset($_FILES['platform_logo']) && is_array($_FILES['platform_logo']) && ($_FILES['platform_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $up = Uploader::handle($_FILES['platform_logo'], 'logo', 'platform');
                    Settings::setPlatform('platform_logo', $up['path']);
                } catch (RuntimeException $e) {
                    $errors[] = __('Logo') . ': ' . $e->getMessage();
                }
            } elseif (!empty($_POST['remove_logo'])) {
                Settings::setPlatform('platform_logo', '');
            }
            if (!$errors) {
                foreach (['platform_name' => $P('platform_name', 120) ?: Branding::DEFAULT_PRODUCT, 'platform_color' => strtoupper($color),
                    'platform_support_phone' => $P('platform_support_phone', 40), 'platform_support_email' => $email,
                    'platform_footer' => $P('platform_footer', 200)] as $k => $v) {
                    Settings::setPlatform($k, $v);
                }
                Branding::flush();
                // TVs show the branding: refresh every hotel's content.
                Tenant::each(static function (): void {
                    Settings::bumpContentVersion();
                });
            }
            break;

        case 'billing':
            $tax = (float) $P('billing_tax_percent', 10);
            if ($tax < 0 || $tax > 100) {
                $errors[] = __('Tax must be between 0 and 100 %.');
            }
            $prefix = strtoupper($P('invoice_prefix', 12));
            if (!preg_match('/^[A-Z0-9-]{1,12}$/', $prefix)) {
                $errors[] = __('Invoice prefix: letters, numbers and dashes only.');
            }
            $wa = $P('billing_whatsapp_url', 1000);
            if ($wa !== '' && !ContentManager::validUrl(str_replace(['{phone}', '{message}'], 'x', $wa), ['https', 'http'])) {
                $errors[] = __('Invalid WhatsApp gateway URL.');
            }
            if (!$errors) {
                foreach ([
                    'billing_currency' => in_array($P('billing_currency', 3), ['INR', 'USD', 'EUR', 'GBP', 'AED'], true) ? $P('billing_currency', 3) : 'INR',
                    'billing_tax_percent' => (string) round($tax, 2),
                    'billing_tax_label' => $P('billing_tax_label', 20) ?: 'Tax',
                    'invoice_prefix' => $prefix,
                    'invoice_due_days' => (string) max(0, min(120, (int) $P('invoice_due_days', 5))),
                    'invoice_auto_generate' => !empty($_POST['invoice_auto_generate']) ? '1' : '0',
                    'invoice_seller_details' => mb_substr(trim((string) ($_POST['invoice_seller_details'] ?? '')), 0, 1000),
                    'auto_suspend_days' => (string) max(0, min(365, (int) $P('auto_suspend_days', 5))),
                    'reminder_email' => !empty($_POST['reminder_email']) ? '1' : '0',
                    'reminder_whatsapp' => !empty($_POST['reminder_whatsapp']) ? '1' : '0',
                    'reminder_every_days' => (string) max(1, min(30, (int) $P('reminder_every_days', 5))),
                    'billing_whatsapp_url' => $wa,
                ] as $k => $v) {
                    Settings::setPlatform($k, $v);
                }
            }
            break;

        case 'email':
        case 'email_test':
            // 2.8 Email transport (core/Mailer.php). The password is stored encrypted and never shown;
            // empty = keep. "Send test email" uses the values in the form without saving them.
            $mailCfg = [
                'transport' => in_array($P('mail_transport', 10), Mailer::TRANSPORTS, true) ? $P('mail_transport', 10) : 'mail',
                'host' => $P('smtp_host', 190),
                'port' => req_int('smtp_port', $_POST),
                'encryption' => in_array($P('smtp_encryption', 10), Mailer::ENCRYPTIONS, true) ? $P('smtp_encryption', 10) : 'tls',
                'username' => $P('smtp_username', 190),
                'allow_self_signed' => !empty($_POST['smtp_allow_self_signed']),
                'from_email' => $P('mail_from_email', 190),
                'from_name' => mb_substr(Mailer::cleanHeader($P('mail_from_name', 100)), 0, 100),
            ];
            $newPass = is_string($_POST['smtp_password'] ?? null) ? trim((string) $_POST['smtp_password']) : '';
            if ($mailCfg['transport'] === 'smtp') {
                if ($mailCfg['host'] === '' || !preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $mailCfg['host'])) {
                    $errors[] = __('Enter the SMTP server (host name), e.g. smtp.gmail.com.');
                }
                if ($mailCfg['port'] < 1 || $mailCfg['port'] > 65535) {
                    $errors[] = __('Enter the SMTP port (587 for STARTTLS, 465 for SSL).');
                }
            }
            if ($mailCfg['from_email'] !== '' && !filter_var($mailCfg['from_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Enter a valid email address.') . ' (' . __('From email') . ')';
            }
            if ($op === 'email_test') {
                $to = $P('test_to', 190);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = __('Enter the address that should receive the test email.');
                }
                if ($errors) {
                    break;
                }
                $override = $mailCfg + ['password' => !empty($_POST['clear_smtp_password']) ? '' : ($newPass !== '' ? $newPass : Settings::secret('smtp_password'))];
                if ($override['from_email'] === '') {
                    unset($override['from_email']);
                }
                if ($override['from_name'] === '') {
                    unset($override['from_name']);
                }
                $brand = Branding::get(0);
                [$text, $html] = MailTemplate::render(['brand' => $brand, 'lang' => I18n::lang(), 'title' => __('Test email'),
                    'paragraphs' => [__('This is a test message from :product.', ['product' => $brand['product']]),
                        __('Your email settings work: messages such as password resets, sign-up codes and alerts will be delivered.'),
                        __('Transport') . ': ' . ($override['transport'] === 'smtp' ? 'SMTP ' . $override['host'] . ':' . $override['port'] . ' (' . $override['encryption'] . ')' : 'PHP mail()')]]);
                $ok = Mailer::send($to, $brand['product'] . ': ' . __('Test email'), $text, $html, ['config' => $override]);
                $_SESSION['hc_mail_test'] = ['ok' => $ok, 'to' => $to, 'error' => Mailer::$lastError, 'transcript' => array_slice(Mailer::$lastTranscript, -14)];
                ActivityLog::add('mail_test', 'settings', null, 'Test email to ' . Mailer::maskAddress($to) . ': ' . ($ok ? 'sent' : 'failed'));
                redirect(admin_url('platform_settings.php', ['tab' => 'email']));
            }
            if (!$errors) {
                foreach (['mail_transport' => $mailCfg['transport'], 'smtp_host' => $mailCfg['host'], 'smtp_port' => (string) ($mailCfg['port'] ?: 587),
                    'smtp_encryption' => $mailCfg['encryption'], 'smtp_username' => $mailCfg['username'], 'smtp_allow_self_signed' => $mailCfg['allow_self_signed'] ? '1' : '0',
                    'mail_from_email' => $mailCfg['from_email'], 'mail_from_name' => $mailCfg['from_name']] as $k => $v) {
                    Settings::setPlatform($k, $v);
                }
                if (!empty($_POST['clear_smtp_password'])) {
                    Settings::setSecret('smtp_password', '');
                } elseif ($newPass !== '') {
                    Settings::setSecret('smtp_password', $newPass);
                }
            }
            break;

        case 'notify':
            $to = $P('platform_notify_email', 500);
            $from = $P('platform_from_email', 190);
            foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = __('Invalid email: :e', ['e' => $addr]);
                }
            }
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Enter a valid email address.');
            }
            if (!$errors) {
                Settings::setPlatform('platform_notify_email', $to);
                Settings::setPlatform('platform_from_email', $from);
            }
            break;

        case 'add_admin':
            [$acc, $errs] = Hotels::validateAdmin($_POST, true);
            $errors = array_merge($errors, $errs);
            if (!$errors) {
                $uid = DB::insert('users', [
                    'hotel_id' => null, 'role' => 'platform_admin', 'username' => $acc['username'], 'email' => $acc['email'],
                    'full_name' => $acc['full_name'], 'password_hash' => Auth::hash($acc['password']), 'language' => 'en', 'is_active' => 1, 'created_at' => now(),
                ]);
                ActivityLog::add('user_create', 'user', $uid, $acc['username'] . ' (platform_admin)');
                if ($acc['invite'] && !PasswordReset::invite($uid)) {
                    $errors[] = __('The invite email could not be sent: :err', ['err' => Mailer::$lastError]);
                }
            }
            break;

        case 'features':
            // Optional platform features. Hotel chains are off by default; switching off hides the
            // chain pages / menus (404) but keeps all chain data.
            $on = !empty($_POST['feature_chains']) ? '1' : '0';
            if ((string) Settings::platform('feature_chains', '0') !== $on) {
                Settings::setPlatform('feature_chains', $on);
                ActivityLog::add('platform_feature', 'settings', null, 'feature_chains = ' . $on);
            }
            break;

        case 'data_feeds':
            // API keys (encrypted), budgets and symbols of the data feeds (core/DataFeeds.php).
            $errors = DataFeeds::savePlatformSettings($_POST);
            break;

        case 'toggle_admin':
            $uid = req_int('user_id', $_POST);
            $u = DB::one("SELECT * FROM users WHERE id = :id AND role = 'platform_admin'", ['id' => $uid]);
            $active = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'platform_admin' AND is_active = 1");
            if (!$u || $uid === Auth::id() || ((int) $u['is_active'] && $active <= 1)) {
                $errors[] = __('You cannot disable your own account or the last platform admin.');
            } else {
                DB::update('users', ['is_active' => (int) $u['is_active'] ? 0 : 1], 'id = :id', ['id' => $uid]);
                Auth::revokeUserSessions($uid);
                ActivityLog::add('user_update', 'user', $uid, $u['username'] . ((int) $u['is_active'] ? ' disabled' : ' enabled'));
            }
            break;

        case 'license_check':
            $s = License::check(true);
            flash($s['status'] === 'invalid' ? 'danger' : 'success', __('License status: :s', ['s' => $s['status']]) . ' — ' . $s['message']);
            redirect(admin_url('platform_settings.php', ['tab' => 'license']));
    }
    if ($errors) {
        flash_errors($errors);
    } else {
        ActivityLog::add('platform_settings', 'settings', null, 'Saved ' . $op);
        flash('success', __('Settings saved.'));
    }
    redirect(admin_url('platform_settings.php', ['tab' => $tab]));
}

$S = Settings::all(0);
$val = fn (string $k) => e((string) ($S[$k] ?? ''));
$chk = fn (string $k) => ((string) ($S[$k] ?? '0')) === '1' ? ' checked' : '';
$pageTitle = __('Platform settings');
$activeNav = 'platform_settings';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e(__('Platform settings')) ?></h1><p class="lead-sm"><?= e($saas ? __('Settings for the whole platform (all customers).') : __('Self-hosted installation.')) ?></p></div></div>
<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('platform_settings.php', ['tab' => $k])) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'branding'): ?>
<form method="post" enctype="multipart/form-data" class="card" style="max-width:820px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="branding"><input type="hidden" name="tab" value="branding">
  <div class="col-12 small text-muted"><?= e(__('Shown on the login page, the admin panel, the installer and on every TV (Content "branding"). Resellers and customers can override it.')) ?></div>
  <div class="col-sm-8"><label class="form-label" for="b_n"><?= e(__('Product name')) ?></label><input class="form-control" id="b_n" name="platform_name" value="<?= $val('platform_name') ?>" maxlength="120"></div>
  <div class="col-sm-4"><label class="form-label" for="b_c"><?= e(__('Primary colour')) ?></label><input class="form-control form-control-color w-100" type="color" id="b_c" name="platform_color" value="<?= $val('platform_color') ?>"></div>
  <div class="col-12"><label class="form-label" for="b_l"><?= e(__('Logo')) ?></label><input class="form-control" type="file" id="b_l" name="platform_logo" accept="image/png,image/jpeg,image/webp">
    <?php if (!empty($S['platform_logo'])): ?><div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $S['platform_logo'])) ?>" alt="" style="max-height:48px"><label class="small"><input type="checkbox" class="form-check-input" name="remove_logo" value="1"> <?= e(__('Remove')) ?></label></div><?php endif; ?></div>
  <div class="col-sm-6"><label class="form-label" for="b_sp"><?= e(__('Support phone')) ?></label><input class="form-control" id="b_sp" name="platform_support_phone" value="<?= $val('platform_support_phone') ?>" maxlength="40"></div>
  <div class="col-sm-6"><label class="form-label" for="b_se"><?= e(__('Support email')) ?></label><input class="form-control" type="email" id="b_se" name="platform_support_email" value="<?= $val('platform_support_email') ?>"></div>
  <div class="col-12"><label class="form-label" for="b_f"><?= e(__('Footer text')) ?></label><input class="form-control" id="b_f" name="platform_footer" value="<?= $val('platform_footer') ?>" maxlength="200"></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

<?php elseif ($tab === 'billing'): ?>
<form method="post" class="card" style="max-width:900px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="billing"><input type="hidden" name="tab" value="billing">
  <div class="col-sm-3"><label class="form-label" for="c_cur"><?= e(__('Currency')) ?></label><select class="form-select" id="c_cur" name="billing_currency">
    <?php foreach (['INR', 'USD', 'EUR', 'GBP', 'AED'] as $c): ?><option<?= ($S['billing_currency'] ?? 'INR') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3"><label class="form-label" for="c_tax"><?= e(__('Tax %')) ?></label><input class="form-control" type="number" step="0.01" min="0" max="100" id="c_tax" name="billing_tax_percent" value="<?= $val('billing_tax_percent') ?>"></div>
  <div class="col-sm-3"><label class="form-label" for="c_tl"><?= e(__('Tax label')) ?></label><input class="form-control" id="c_tl" name="billing_tax_label" value="<?= $val('billing_tax_label') ?>" maxlength="20" placeholder="GST"></div>
  <div class="col-sm-3"><label class="form-label" for="c_pre"><?= e(__('Invoice prefix')) ?></label><input class="form-control" id="c_pre" name="invoice_prefix" value="<?= $val('invoice_prefix') ?>" maxlength="12"><div class="form-text"><?= e(__('e.g. :x', ['x' => ($S['invoice_prefix'] ?? 'HC') . '-' . date('Y') . '-0001'])) ?></div></div>
  <div class="col-sm-4"><label class="form-label" for="c_due"><?= e(__('Payment due after')) ?></label><div class="input-group"><input class="form-control" type="number" min="0" max="120" id="c_due" name="invoice_due_days" value="<?= $val('invoice_due_days') ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div></div>
  <div class="col-sm-4"><label class="form-label" for="c_sus"><?= e(__('Auto-suspend when overdue by')) ?></label><div class="input-group"><input class="form-control" type="number" min="0" max="365" id="c_sus" name="auto_suspend_days" value="<?= $val('auto_suspend_days') ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div><div class="form-text"><?= e(__('0 = never suspend automatically.')) ?></div></div>
  <div class="col-sm-4"><label class="form-label" for="c_rem"><?= e(__('Repeat reminders every')) ?></label><div class="input-group"><input class="form-control" type="number" min="1" max="30" id="c_rem" name="reminder_every_days" value="<?= $val('reminder_every_days') ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div></div>
  <div class="col-12 d-flex flex-wrap gap-4">
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_ag" name="invoice_auto_generate" value="1"<?= $chk('invoice_auto_generate') ?>><label class="form-check-label" for="c_ag"><?= e(__('Generate last month\'s invoices automatically on the 1st')) ?></label></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_re" name="reminder_email" value="1"<?= $chk('reminder_email') ?>><label class="form-check-label" for="c_re"><?= e(__('Overdue reminders by email')) ?></label></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_rw" name="reminder_whatsapp" value="1"<?= $chk('reminder_whatsapp') ?>><label class="form-check-label" for="c_rw"><?= e(__('Overdue reminders by WhatsApp')) ?></label></div>
  </div>
  <div class="col-12"><label class="form-label" for="c_wa"><?= e(__('WhatsApp gateway URL')) ?></label><input class="form-control" id="c_wa" name="billing_whatsapp_url" value="<?= $val('billing_whatsapp_url') ?>" placeholder="https://api.example.com/send?to={phone}&amp;text={message}">
    <div class="form-text"><?= e(__('{phone} is replaced with the customer\'s phone number (digits only) and {message} with the text. Without {message} the text is POSTed as JSON.')) ?></div></div>
  <div class="col-12"><label class="form-label" for="c_sd"><?= e(__('Your company details on invoices')) ?></label><textarea class="form-control" id="c_sd" name="invoice_seller_details" rows="4" placeholder="<?= e(__("Company name\nAddress\nGSTIN …\nBank / UPI details")) ?>"><?= $val('invoice_seller_details') ?></textarea></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

<?php elseif ($tab === 'email'):
    $mt = $_SESSION['hc_mail_test'] ?? null;
    unset($_SESSION['hc_mail_test']);
    $hasPass = Settings::secret('smtp_password') !== '';
    $mlog = Mailer::recentLog();
    $sel = fn (string $k, string $v) => ((string) ($S[$k] ?? '')) === $v ? ' selected' : '';
    $outbox = Mailer::outboxPath();
?>
<?php if ($mt): ?>
  <?php if ($mt['ok']): ?>
    <div class="alert alert-success d-flex gap-2" role="status" data-mail-test="ok"><i class="bi bi-check-circle-fill"></i><div><?= e(__('Test email sent to :e. Check the inbox (and the spam folder).', ['e' => $mt['to']])) ?></div></div>
  <?php else: ?>
    <div class="alert alert-danger" role="alert" data-mail-test="failed"><div class="d-flex gap-2"><i class="bi bi-exclamation-octagon-fill"></i><div><strong><?= e(__('The test email could not be sent.')) ?></strong><br><span class="mono small" data-mail-error><?= e((string) $mt['error']) ?></span></div></div>
      <?php if (!empty($mt['transcript'])): ?><details class="mt-2"><summary class="small"><?= e(__('SMTP conversation')) ?></summary><pre class="small mb-0 mt-2" style="white-space:pre-wrap"><?= e(implode("\n", $mt['transcript'])) ?></pre></details><?php endif; ?></div>
  <?php endif; ?>
<?php endif; ?>
<?php if ($outbox !== null): ?><div class="alert alert-info small"><?= e(__('Test installation: emails are written to an outbox file instead of being sent.')) ?></div><?php endif; ?>
<div class="row g-3">
<div class="col-xl-7">
<form method="post" class="card" data-smtp-card autocomplete="off"><div class="card-header"><i class="bi bi-envelope-at"></i> <?= e(__('Email (SMTP)')) ?></div><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="tab" value="email">
  <div class="col-12 small text-muted"><?= e(__('Used for password resets, sign-up codes, welcome emails, invites, alerts and invoices. SMTP through a real mailbox is much more reliable than PHP mail(), which often fails or lands in spam on shared hosting.')) ?></div>
  <div class="col-sm-6"><label class="form-label" for="m_t"><?= e(__('Send emails with')) ?></label>
    <select class="form-select" id="m_t" name="mail_transport"><option value="smtp"<?= $sel('mail_transport', 'smtp') ?>><?= e(__('SMTP server (recommended)')) ?></option><option value="mail"<?= $sel('mail_transport', 'mail') ?>><?= e(__('PHP mail() of the hosting')) ?></option></select></div>
  <div class="col-sm-6"><label class="form-label" for="m_enc"><?= e(__('Encryption')) ?></label>
    <select class="form-select" id="m_enc" name="smtp_encryption"><option value="tls"<?= $sel('smtp_encryption', 'tls') ?>>STARTTLS (587)</option><option value="ssl"<?= $sel('smtp_encryption', 'ssl') ?>>SSL / TLS (465)</option><option value="none"<?= $sel('smtp_encryption', 'none') ?>><?= e(__('None (not recommended)')) ?></option></select></div>
  <div class="col-sm-8"><label class="form-label" for="m_h"><?= e(__('SMTP server')) ?></label><input class="form-control" id="m_h" name="smtp_host" value="<?= $val('smtp_host') ?>" placeholder="smtp.gmail.com" maxlength="190" autocapitalize="none" spellcheck="false"></div>
  <div class="col-sm-4"><label class="form-label" for="m_p"><?= e(__('Port')) ?></label><input class="form-control" type="number" id="m_p" name="smtp_port" value="<?= $val('smtp_port') ?>" min="1" max="65535" placeholder="587"></div>
  <div class="col-sm-6"><label class="form-label" for="m_u"><?= e(__('Username')) ?></label><input class="form-control" id="m_u" name="smtp_username" value="<?= $val('smtp_username') ?>" placeholder="you@gmail.com" maxlength="190" autocomplete="off" autocapitalize="none" spellcheck="false"></div>
  <div class="col-sm-6"><label class="form-label" for="m_pw"><?= e(__('Password')) ?></label><input class="form-control" type="password" id="m_pw" name="smtp_password" autocomplete="new-password" placeholder="<?= e($hasPass ? __('Saved (leave empty to keep)') : __('App password')) ?>">
    <div class="form-text"><?= e(__('Stored encrypted. Never shown again.')) ?></div>
    <?php if ($hasPass): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_smtp_password" value="1" id="m_clr"><label class="form-check-label small" for="m_clr"><?= e(__('Remove saved password')) ?></label></div><?php endif; ?></div>
  <div class="col-sm-6"><label class="form-label" for="m_fe"><?= e(__('From email')) ?></label><input class="form-control" type="email" id="m_fe" name="mail_from_email" value="<?= $val('mail_from_email') ?>" placeholder="<?= e(Mailer::defaultFrom()) ?>" maxlength="190">
    <div class="form-text"><?= e(__('Gmail / Zoho: use the same address as the username.')) ?></div></div>
  <div class="col-sm-6"><label class="form-label" for="m_fn"><?= e(__('From name')) ?></label><input class="form-control" id="m_fn" name="mail_from_name" value="<?= $val('mail_from_name') ?>" placeholder="<?= e(Branding::get(0)['product']) ?>" maxlength="100"></div>
  <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="m_ss" name="smtp_allow_self_signed" value="1"<?= $chk('smtp_allow_self_signed') ?>><label class="form-check-label" for="m_ss"><?= e(__('Allow self-signed certificate (only for your own mail server)')) ?></label></div></div>
  <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" name="op" value="email"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
  <div class="col-12"><hr class="my-1"></div>
  <div class="col-sm-8"><label class="form-label" for="m_to"><?= e(__('Send a test email to')) ?></label><input class="form-control" type="email" id="m_to" name="test_to" value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="190"></div>
  <div class="col-sm-4 d-flex align-items-end"><button class="btn btn-outline-primary w-100" name="op" value="email_test" data-mail-test-btn><i class="bi bi-send"></i> <?= e(__('Send test email')) ?></button></div>
  <div class="col-12 form-text mt-0"><?= e(__('The test uses the values in this form (also before saving) and shows the exact error if it fails.')) ?></div>
</div></form>
</div>
<div class="col-xl-5">
  <div class="card"><div class="card-header"><i class="bi bi-lightbulb"></i> <?= e(__('Settings for common providers')) ?></div><div class="card-body small">
    <p class="mb-2"><strong>Gmail / Google Workspace</strong>: smtp.gmail.com · 587 · STARTTLS. <?= e(__('Username = your Gmail address. Password = an App Password (Google Account → Security → 2-Step Verification → App passwords), not your normal password.')) ?></p>
    <p class="mb-2"><strong>Hostinger</strong>: smtp.hostinger.com · 465 · SSL. <?= e(__('Username = the full mailbox address created in hPanel → Emails, with its password.')) ?></p>
    <p class="mb-2"><strong>Zoho Mail</strong>: smtp.zoho.in (India) / smtp.zoho.com · 465 SSL <?= e(__('or')) ?> 587 STARTTLS. <?= e(__('Username = your Zoho address; with 2FA use an application-specific password.')) ?></p>
    <p class="mb-2"><strong>cPanel</strong>: mail.<?= e(__('your-domain')) ?> · 465 · SSL. <?= e(__('Username = the full email address created in cPanel → Email Accounts.')) ?></p>
    <p class="mb-0 text-muted"><?= e(__('Tip: add the SPF / DKIM records your mail provider shows you to your domain\'s DNS, so emails do not land in spam.')) ?></p>
  </div></div>
</div>
<div class="col-12">
  <div class="card" data-mail-log><div class="card-header d-flex justify-content-between"><span><i class="bi bi-journal-text"></i> <?= e(__('Email log')) ?></span><span class="small text-muted"><?= e(__('Last :n emails (no contents are stored)', ['n' => Mailer::LOG_KEEP])) ?></span></div>
    <div class="table-responsive" style="max-height:480px"><table class="table table-sm mb-0 align-middle small">
      <thead class="table-light"><tr><th><?= e(__('Time')) ?></th><th><?= e(__('To')) ?></th><th><?= e(__('Subject')) ?></th><th><?= e(__('Status')) ?></th></tr></thead>
      <tbody>
      <?php if (!$mlog): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= e(__('No emails sent yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($mlog as $l): ?>
        <tr><td class="text-nowrap"><?= e(date('d M H:i:s', (int) strtotime((string) $l['created_at']))) ?></td><td class="text-break"><?= e((string) $l['recipient']) ?></td><td class="text-break"><?= e((string) $l['subject']) ?></td>
          <td><?php if ($l['status'] === 'sent'): ?><span class="badge text-bg-success"><?= e(__('sent')) ?></span><?php else: ?><span class="badge text-bg-danger"><?= e(__('failed')) ?></span><?php endif; ?> <span class="text-muted"><?= e((string) $l['transport']) ?></span>
            <?php if (!empty($l['error'])): ?><div class="text-danger mono" style="font-size:.75rem"><?= e((string) $l['error']) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
</div>
</div>

<?php elseif ($tab === 'notify'): ?>
<form method="post" class="card" style="max-width:760px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="notify"><input type="hidden" name="tab" value="notify">
  <div class="col-12"><label class="form-label" for="n_to"><?= e(__('Platform admin email(s)')) ?></label><input class="form-control" id="n_to" name="platform_notify_email" value="<?= $val('platform_notify_email') ?>" placeholder="owner@example.com, accounts@example.com">
    <div class="form-text"><?= e(__('Receives platform alerts, e.g. customers auto-suspended for non-payment.')) ?></div></div>
  <div class="col-12"><label class="form-label" for="n_from"><?= e(__('Sender address for invoices and reminders')) ?></label><input class="form-control" type="email" id="n_from" name="platform_from_email" value="<?= $val('platform_from_email') ?>" placeholder="billing@example.com"></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

<?php elseif ($tab === 'features'): ?>
<form method="post" class="card" style="max-width:760px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="features"><input type="hidden" name="tab" value="features">
  <div class="col-12">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" id="f_chains" name="feature_chains" value="1"<?= $chk('feature_chains') ?>>
      <label class="form-check-label fw-semibold" for="f_chains"><?= e(__('Chains')) ?></label>
    </div>
    <div class="form-text"><?= e(__('Groups of customers with one owner (chain dashboard, chain content, chain broadcast, chain admins). Off: the chain menus and pages are hidden and chain admins cannot enter customers. Existing chain data is kept and comes back when you switch it on again.')) ?></div>
  </div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

<?php elseif ($tab === 'data_feeds'): ?>
<?php require __DIR__ . '/partials/data_feeds_platform.php'; ?>

<?php elseif ($tab === 'admins'): $admins = DB::all("SELECT u.*, h.name AS hotel_name FROM users u LEFT JOIN hotels h ON h.id = u.hotel_id WHERE u.role = 'platform_admin' ORDER BY u.username"); ?>
<div class="row g-3" style="max-width:1000px">
  <div class="col-lg-7"><div class="card"><div class="card-header"><?= e(__('Platform admins')) ?></div><ul class="list-group list-group-flush">
    <?php foreach ($admins as $a): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
        <span><strong><?= e($a['full_name'] ?: $a['username']) ?></strong> · <?= e($a['username']) ?> · <?= e($a['email']) ?>
          <?= $a['hotel_name'] ? '<span class="badge text-bg-light border">' . e(__('Customer')) . ': ' . e($a['hotel_name']) . '</span>' : '' ?>
          <?= (int) $a['is_active'] ? '' : '<span class="text-danger">' . e(__('Disabled')) . '</span>' ?></span>
        <?php if ((int) $a['id'] !== Auth::id()): ?>
        <form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle_admin"><input type="hidden" name="tab" value="admins"><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
          <button class="btn btn-sm btn-light border"><?= e((int) $a['is_active'] ? __('Disable') : __('Enable')) ?></button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul></div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header"><?= e(__('Add platform admin')) ?></div><div class="card-body">
    <p class="small text-muted"><?= e(__('Platform admins manage all customers, plans, invoices, licenses and updates.')) ?></p>
    <form method="post" class="row g-2" autocomplete="off"><?= Csrf::field() ?><input type="hidden" name="op" value="add_admin"><input type="hidden" name="tab" value="admins">
      <div class="col-sm-6"><input class="form-control" name="admin_username" placeholder="<?= e(__('Username')) ?>" required></div>
      <div class="col-sm-6"><input class="form-control" name="admin_name" placeholder="<?= e(__('Full name')) ?>"></div>
      <div class="col-12"><input class="form-control" type="email" name="admin_email" placeholder="<?= e(__('Email')) ?>" required></div>
      <div class="col-12"><input class="form-control" type="password" id="pa_p" name="admin_password" placeholder="<?= e(__('Password')) ?>" autocomplete="new-password"></div>
      <div class="col-12"><?= invite_checkbox('pa_i', 'pa_p') ?></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-person-plus"></i> <?= e(__('Create')) ?></button></div>
    </form></div></div></div>
</div>

<?php else: $ls = License::state(); ?>
<div class="card" style="max-width:760px"><div class="card-body">
  <?php if ($saas): ?>
    <p><?= e(__('This installation runs in SaaS (platform) mode: no license is needed. It is the license server for self-hosted customers (Platform → Licenses).')) ?></p>
    <p class="small text-muted mb-0"><?= e(__('License check endpoint')) ?>: <code><?= e(base_url('api/license/check')) ?></code></p>
  <?php else: ?>
    <table class="table table-sm mb-3">
      <tr><th class="text-muted fw-normal" style="width:35%"><?= e(__('Status')) ?></th><td><strong><?= e($ls['status']) ?></strong> — <?= e($ls['message']) ?></td></tr>
      <tr><th class="text-muted fw-normal"><?= e(__('License key')) ?></th><td class="mono"><?= e(License::key() !== '' ? substr(License::key(), 0, 8) . '…' : __('not configured')) ?></td></tr>
      <tr><th class="text-muted fw-normal"><?= e(__('License server')) ?></th><td><?= e(License::server()) ?></td></tr>
      <tr><th class="text-muted fw-normal"><?= e(__('Max TVs')) ?></th><td><?= e(License::maxTvs() === null ? __('unlimited') : (string) License::maxTvs()) ?></td></tr>
      <tr><th class="text-muted fw-normal"><?= e(__('Valid until')) ?></th><td><?= e($ls['expires_at'] ? date('d M Y', (int) strtotime((string) $ls['expires_at'])) : '—') ?></td></tr>
      <tr><th class="text-muted fw-normal"><?= e(__('Last check')) ?></th><td><?= e($ls['checked_at'] ? date('d M Y H:i', (int) $ls['checked_at']) : __('never')) ?></td></tr>
    </table>
    <p class="small text-muted"><?= e(__('The key and server are set in config.php (license_key, license_server). The license is checked once a day; if the server cannot be reached the installation keeps working for :n days.', ['n' => License::GRACE_DAYS])) ?></p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="license_check"><input type="hidden" name="tab" value="license">
      <button class="btn btn-outline-primary"<?= License::key() === '' ? ' disabled' : '' ?>><i class="bi bi-arrow-repeat"></i> <?= e(__('Check license now')) ?></button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
