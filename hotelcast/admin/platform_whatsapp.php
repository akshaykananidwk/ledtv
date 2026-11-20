<?php
/**
 * Super Admin console → WhatsApp (2.10.4, core/WhatsApp.php, docs/modules/whatsapp.md): API settings (URL, key, session
 * ID) and a custom message — text and optional photo / video / PDF link — to the contact number of every customer
 * (all active, trials, all, or selected). :name and :business personalise it. Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
ActivityLog::$platformScope = true;
Csrf::check();

$draft = ['message' => '', 'media_url' => '', 'to' => 'active', 'hotels' => []];
if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'settings') {
        $errors = WhatsApp::saveConfig(['url' => req_str('wa_url', $_POST, 300), 'key' => req_str('wa_key', $_POST, 200), 'session' => req_str('wa_session', $_POST, 100)]);
        $errors ? flash_errors($errors) : flash('success', __('WhatsApp settings saved.'));
        redirect(admin_url('platform_whatsapp.php'));
    }
    if ($op === 'test' || $op === 'send') {
        $message = trim(mb_substr((string) ($_POST['message'] ?? ''), 0, WhatsApp::MAX_MESSAGE));
        $media = trim(req_str('media_url', $_POST, 1000));
        $draft = ['message' => $message, 'media_url' => $media, 'to' => req_str('to', $_POST, 10), 'hotels' => is_array($_POST['hotels'] ?? null) ? array_map('intval', $_POST['hotels']) : []];
        $errs = [];
        if (!WhatsApp::configured()) {
            $errs[] = __('Enter the WhatsApp API key and session ID first.');
        }
        if ($message === '') {
            $errs[] = __('Write the message.');
        }
        if ($media !== '' && (!filter_var($media, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $media))) {
            $errs[] = __('The photo / video / PDF link must start with http:// or https://.');
        }
        if (!$errs && $op === 'test') {
            $n = WhatsApp::number(req_str('test_number', $_POST, 20));
            if ($n === null) {
                $errs[] = __('Enter a valid mobile number for the test.');
            } else {
                $r = WhatsApp::send($n, WhatsApp::render($message, ['contact' => 'Test', 'name' => 'Test']), $media);
                $r['ok'] ? flash('success', __('Test message sent to :n.', ['n' => $n])) : flash('danger', __('WhatsApp refused the message: :e', ['e' => $r['error']]));
            }
        }
        if (!$errs && $op === 'send') {
            $to = in_array($draft['to'], ['active', 'trial', 'all', 'selected'], true) ? $draft['to'] : 'active';
            $list = WhatsApp::recipients($to, $draft['hotels']);
            if (!$list) {
                $errs[] = __('No customer with a valid mobile number in this selection.');
            } else {
                $res = WhatsApp::broadcast($list, $message, $media, Auth::id());
                flash($res['failed'] ? 'warning' : 'success', __('WhatsApp message sent to :s customer(s); :f failed.', ['s' => $res['sent'], 'f' => $res['failed']])
                    . ($res['errors'] ? ' ' . implode(' · ', $res['errors']) : ''));
                redirect(admin_url('platform_whatsapp.php'));
            }
        }
        if ($errs) {
            flash_errors($errs);
        }
    }
}

$cfg = WhatsApp::config();
$customers = DB::all('SELECT id, name, contact_phone FROM hotels WHERE archived_at IS NULL ORDER BY name, id');
$counts = ['active' => count(WhatsApp::recipients('active')), 'trial' => count(WhatsApp::recipients('trial')), 'all' => count(WhatsApp::recipients('all'))];
$noNumber = count(array_filter($customers, static fn ($c) => WhatsApp::number((string) $c['contact_phone']) === null));
$pageTitle = __('WhatsApp messages');
$activeNav = 'platform_whatsapp';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-whatsapp text-success"></i> <?= e(__('WhatsApp messages')) ?></h1>
    <p class="lead-sm"><?= e(__('Send your own message to the mobile number of every customer — for example "Update the TV app".')) ?></p></div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card" id="waSend"><div class="card-header"><i class="bi bi-send"></i> <?= e(__('New message')) ?></div><div class="card-body row g-3">
      <?= Csrf::field() ?>
      <div class="col-12"><label class="form-label" for="wa_msg"><?= e(__('Message')) ?> *</label>
        <textarea class="form-control" id="wa_msg" name="message" rows="7" maxlength="<?= WhatsApp::MAX_MESSAGE ?>" placeholder="<?= e(__('Dear :name, a new TV app is ready. Please update it today so your TVs keep running.')) ?>"><?= e($draft['message']) ?></textarea>
        <div class="form-text"><?= e(__(':name = contact person, :business = customer name.')) ?></div></div>
      <div class="col-12"><label class="form-label" for="wa_media"><?= e(__('Photo / video / PDF link (optional)')) ?></label>
        <input class="form-control" id="wa_media" name="media_url" maxlength="1000" value="<?= e($draft['media_url']) ?>" placeholder="https://ledtv.akdwk.in/app/"></div>
      <div class="col-12"><span class="form-label d-block"><?= e(__('Send to')) ?></span>
        <?php foreach (['active' => __('Active customers'), 'trial' => __('Customers on trial'), 'all' => __('All customers'), 'selected' => __('Selected customers')] as $k => $l): ?>
          <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="to" value="<?= e($k) ?>" id="wa_to_<?= e($k) ?>"<?= $draft['to'] === $k ? ' checked' : '' ?>>
            <label class="form-check-label" for="wa_to_<?= e($k) ?>"><?= e($l) ?><?= isset($counts[$k]) ? ' (' . $counts[$k] . ')' : '' ?></label></div>
        <?php endforeach; ?>
        <select class="form-select form-select-sm mt-2" name="hotels[]" multiple size="6" aria-label="<?= e(__('Customers')) ?>">
          <?php foreach ($customers as $c): $n = WhatsApp::number((string) $c['contact_phone']); ?>
            <option value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $draft['hotels'], true) ? ' selected' : '' ?><?= $n === null ? ' disabled' : '' ?>><?= e($c['name'] . ($n ? ' · ' . $n : ' · ' . __('no mobile number'))) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><?= e(__('Used with "Selected customers" (Ctrl/Cmd-click). Numbers come from the customer\'s contact mobile; one message per number.')) ?>
          <?php if ($noNumber): ?><span class="text-warning"><?= e(__(':n customer(s) have no valid mobile number.', ['n' => $noNumber])) ?></span><?php endif; ?></div></div>
      <div class="col-sm-6"><label class="form-label" for="wa_test"><?= e(__('Test number')) ?></label>
        <div class="input-group"><input class="form-control" id="wa_test" name="test_number" maxlength="20" placeholder="9876543210">
          <button class="btn btn-outline-secondary" name="op" value="test"><?= e(__('Send test')) ?></button></div></div>
      <div class="col-sm-6 d-flex align-items-end justify-content-sm-end">
        <button class="btn btn-success" name="op" value="send" data-confirm="<?= e(__('Send this WhatsApp message to the selected customers now?')) ?>"><i class="bi bi-whatsapp"></i> <?= e(__('Send to customers')) ?></button></div>
    </div></form>
    <?php $hist = WhatsApp::history(); if ($hist): ?>
    <div class="card mt-3"><div class="card-header"><i class="bi bi-clock-history"></i> <?= e(__('Sent messages')) ?></div>
      <ul class="list-group list-group-flush small" id="waHistory">
        <?php foreach ($hist as $h): ?><li class="list-group-item d-flex justify-content-between gap-2"><span class="text-truncate"><?= e($h['text']) ?></span>
          <span class="text-nowrap text-muted"><?= e(time_ago((string) $h['at'])) ?> · ✓ <?= (int) $h['sent'] ?><?= (int) $h['failed'] ? ' · ✗ ' . (int) $h['failed'] : '' ?></span></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <form method="post" class="card" id="waSettings" autocomplete="off"><div class="card-header"><i class="bi bi-gear"></i> <?= e(__('WhatsApp API')) ?>
      <?= WhatsApp::configured() ? '<span class="badge text-bg-success ms-1">' . e(__('Connected')) . '</span>' : '<span class="badge text-bg-warning ms-1">' . e(__('Not set up')) . '</span>' ?></div><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="settings">
      <div class="col-12"><label class="form-label" for="wa_url"><?= e(__('API URL')) ?></label><input class="form-control mono" id="wa_url" name="wa_url" maxlength="300" value="<?= e($cfg['url']) ?>"></div>
      <div class="col-12"><label class="form-label" for="wa_key"><?= e(__('API key')) ?></label><input class="form-control mono" type="password" id="wa_key" name="wa_key" maxlength="200" autocomplete="new-password" placeholder="<?= e($cfg['key'] !== '' ? __('saved — leave empty to keep') : '') ?>"></div>
      <div class="col-12"><label class="form-label" for="wa_session"><?= e(__('Session ID (WhatsApp number of the linked device)')) ?></label><input class="form-control mono" id="wa_session" name="wa_session" maxlength="100" value="<?= e($cfg['session']) ?>"></div>
      <div class="col-12 small text-muted"><?= e(__('From your WhatsApp API panel → API & Webhooks. The key is stored on the server and never shown again.')) ?></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
    </div></form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
