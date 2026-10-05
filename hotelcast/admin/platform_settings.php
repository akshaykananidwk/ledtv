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
$tabs['notify'] = [__('Notifications'), 'bi-bell'];
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
                foreach (['platform_name' => $P('platform_name', 120) ?: 'HotelCast', 'platform_color' => strtoupper($color),
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
<div class="page-head"><div><h1><?= e(__('Platform settings')) ?></h1><p class="lead-sm"><?= e($saas ? __('Settings for the whole platform (all hotels).') : __('Self-hosted installation.')) ?></p></div></div>
<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" href="<?= e(admin_url('platform_settings.php', ['tab' => $k])) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'branding'): ?>
<form method="post" enctype="multipart/form-data" class="card" style="max-width:820px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="branding"><input type="hidden" name="tab" value="branding">
  <div class="col-12 small text-muted"><?= e(__('Shown on the login page, the admin panel, the installer and on every TV (Content "branding"). Resellers and hotels can override it.')) ?></div>
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
    <div class="form-text"><?= e(__('{phone} is replaced with the hotel\'s phone number (digits only) and {message} with the text. Without {message} the text is POSTed as JSON.')) ?></div></div>
  <div class="col-12"><label class="form-label" for="c_sd"><?= e(__('Your company details on invoices')) ?></label><textarea class="form-control" id="c_sd" name="invoice_seller_details" rows="4" placeholder="<?= e(__("Company name\nAddress\nGSTIN …\nBank / UPI details")) ?>"><?= $val('invoice_seller_details') ?></textarea></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

<?php elseif ($tab === 'notify'): ?>
<form method="post" class="card" style="max-width:760px"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="notify"><input type="hidden" name="tab" value="notify">
  <div class="col-12"><label class="form-label" for="n_to"><?= e(__('Platform admin email(s)')) ?></label><input class="form-control" id="n_to" name="platform_notify_email" value="<?= $val('platform_notify_email') ?>" placeholder="owner@example.com, accounts@example.com">
    <div class="form-text"><?= e(__('Receives platform alerts, e.g. hotels auto-suspended for non-payment.')) ?></div></div>
  <div class="col-12"><label class="form-label" for="n_from"><?= e(__('Sender address for invoices and reminders')) ?></label><input class="form-control" type="email" id="n_from" name="platform_from_email" value="<?= $val('platform_from_email') ?>" placeholder="billing@example.com"></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>

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
