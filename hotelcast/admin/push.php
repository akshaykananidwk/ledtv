<?php
/**
 * Notifications (#14): enable web push on this phone / PC, choose alert types, send a test, manage
 * the user's devices. Hotel super admins also choose which alert types go out by email / WhatsApp.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('push.self');
Csrf::check();
$me = (int) $user['id'];
$canChannels = Tenant::has() && Auth::can('settings.manage');

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'remove') {
        $n = DB::query('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :u', ['id' => req_int('id'), 'u' => $me])->rowCount();
        flash($n ? 'success' : 'warning', $n ? __('Device removed.') : __('Not found.'));
    } elseif ($op === 'remove_all') {
        DB::query('DELETE FROM push_subscriptions WHERE user_id = :u', ['u' => $me]);
        flash('success', __('Notifications were switched off on all your devices.'));
    } elseif ($op === 'channels' && $canChannels) {
        $types = array_keys(StaffAlerts::types());
        foreach (['email', 'whatsapp'] as $ch) {
            $chosen = array_values(array_intersect($types, array_map('strval', (array) ($_POST[$ch] ?? []))));
            Settings::set('alert_' . $ch . '_types', implode(',', $chosen));
        }
        ActivityLog::add('settings_update', 'settings', null, 'Alert channels (email / WhatsApp)');
        flash('success', __('Saved.'));
    }
    redirect(admin_url('push.php'));
}

$devices = DB::all('SELECT id, user_agent, created_at, last_used, failures, last_error FROM push_subscriptions WHERE user_id = :u ORDER BY id DESC', ['u' => $me]);
$types = StaffAlerts::typesForCurrentUser();
$serverOk = WebPush::supported();
$featureOk = !Tenant::has() || Tenant::feature('pwa');
$allTypes = StaffAlerts::types();
$emailTypes = $canChannels ? StaffAlerts::channelTypes('email') : [];
$waTypes = $canChannels ? StaffAlerts::channelTypes('whatsapp') : [];

/** Short device name from a user agent. */
function push_device_name(?string $ua): string
{
    $ua = (string) $ua;
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => __('Unknown device'),
    };
    $br = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Firefox') => 'Firefox',
        str_contains($ua, 'Chrome') => 'Chrome',
        str_contains($ua, 'Safari') => 'Safari',
        default => '',
    };
    return dot_trim($os . ' · ' . $br);
}

$pageTitle = __('Notifications');
$activeNav = 'push';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-bell"></i> <?= e(__('Notifications')) ?></h1>
    <p class="lead-sm"><?= e(__('Get alerts on this phone or PC, even when the admin panel is closed.')) ?></p></div>
  <div><button type="button" class="btn btn-outline-primary" data-pwa-install hidden><i class="bi bi-download"></i> <?= e(__('Install app')) ?></button></div>
</div>

<?php if (!$serverOk): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= e(__('Push notifications are not available on this server: the PHP openssl extension needs elliptic-curve (P-256) support. Email / WhatsApp alerts still work.')) ?></div>
<?php elseif (!$featureOk): ?>
  <div class="alert alert-info"><?= e(__('Mobile app & push notifications are not part of your plan.')) ?></div>
<?php endif; ?>

<div class="row g-3" style="max-width:1100px">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-phone"></i> <?= e(__('This device')) ?></div>
      <div class="card-body">
        <p id="pushState" class="mb-3"><span class="spinner-border spinner-border-sm"></span> <?= e(__('Checking…')) ?></p>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="btn btn-primary" id="pushOn" hidden><i class="bi bi-bell"></i> <?= e(__('Enable notifications')) ?></button>
          <button type="button" class="btn btn-outline-secondary" id="pushTest" hidden><i class="bi bi-send"></i> <?= e(__('Send test notification')) ?></button>
          <button type="button" class="btn btn-outline-danger" id="pushOff" hidden><i class="bi bi-bell-slash"></i> <?= e(__('Turn off on this device')) ?></button>
        </div>
        <p class="small text-muted mb-1"><i class="bi bi-info-circle"></i> <?= e(__('Android / PC: use Chrome, Edge or Firefox. iPhone / iPad: first "Add to Home Screen" from Safari\'s share menu, then open the app from the home screen and enable notifications there.')) ?></p>
        <p class="small text-muted mb-0"><?= e(__('Notifications need HTTPS.')) ?></p>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-list-check"></i> <?= e(__('Alerts on this device')) ?></div>
      <div class="card-body">
        <?php if (!$types): ?>
          <p class="text-muted"><?= e(__('There are no alert types for your role.')) ?></p>
        <?php else: ?>
          <div id="pushTypes">
            <?php foreach ($types as $k => $t): ?>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="pt_<?= e($k) ?>" value="<?= e($k) ?>" checked>
                <label class="form-check-label" for="pt_<?= e($k) ?>"><?= e($t['label']) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="pushSaveTypes" disabled><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-devices"></i> <?= e(__('My devices with notifications')) ?></span>
        <?php if ($devices): ?>
          <form method="post" class="m-0" data-confirm="<?= e(__('Switch off notifications on all your devices?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="remove_all"><button class="btn btn-sm btn-outline-danger"><?= e(__('Remove all')) ?></button></form>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hc table-sm align-middle mb-0">
          <thead><tr><th><?= e(__('Device')) ?></th><th><?= e(__('Added')) ?></th><th><?= e(__('Last notification')) ?></th><th><?= e(__('Status')) ?></th><th></th></tr></thead>
          <tbody>
          <?php if (!$devices): ?><tr><td colspan="5" class="text-muted text-center py-3"><?= e(__('Notifications are not enabled on any of your devices.')) ?></td></tr><?php endif; ?>
          <?php foreach ($devices as $d): ?>
            <tr>
              <td class="hc-push-device"><?= e(push_device_name($d['user_agent'])) ?></td>
              <td class="small text-nowrap"><?= e(time_ago($d['created_at'])) ?></td>
              <td class="small text-nowrap"><?= e(time_ago($d['last_used'])) ?></td>
              <td class="small"><?php if ((int) $d['failures'] > 0): ?><span class="badge text-bg-warning" title="<?= e((string) $d['last_error']) ?>"><?= e(__(':n failures', ['n' => (int) $d['failures']])) ?></span><?php else: ?><span class="badge text-bg-success"><?= e(__('OK')) ?></span><?php endif; ?></td>
              <td class="text-end"><form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="remove"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= e(__('Remove')) ?>"><i class="bi bi-trash"></i></button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($canChannels): ?>
  <div class="col-12">
    <form method="post" class="card">
      <?= Csrf::field() ?><input type="hidden" name="op" value="channels">
      <div class="card-header"><i class="bi bi-envelope"></i> <?= e(__('Email and WhatsApp alerts (whole hotel)')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('Sent to the email address and WhatsApp gateway set in Settings → Notifications, in addition to push notifications.')) ?></p>
        <div class="table-responsive">
          <table class="table table-sm align-middle" style="max-width:640px">
            <thead><tr><th><?= e(__('Alert')) ?></th><th class="text-center"><?= e(__('Email')) ?></th><th class="text-center">WhatsApp</th></tr></thead>
            <tbody>
            <?php foreach ($allTypes as $k => $t): if ($k === 'platform') { continue; } ?>
              <tr><td><?= e($t['label']) ?></td>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="email[]" value="<?= e($k) ?>" aria-label="<?= e(__('Email')) ?>: <?= e($t['label']) ?>"<?= in_array($k, $emailTypes, true) ? ' checked' : '' ?>></td>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="whatsapp[]" value="<?= e($k) ?>" aria-label="WhatsApp: <?= e($t['label']) ?>"<?= in_array($k, $waTypes, true) ? ' checked' : '' ?>></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const T = <?= json_embed([
      'on' => __('Notifications are ON for this device.'),
      'off' => __('Notifications are off on this device.'),
      'blocked' => __('Notifications are blocked. Allow them in the browser settings for this site.'),
      'unsupported' => __('This browser or server does not support push notifications.'),
      'enabled' => __('Notifications enabled.'),
      'disabled' => __('Notifications switched off on this device.'),
      'sent' => __('Test notification sent to :n device(s).'),
      'saved' => __('Saved.'),
  ]) ?>;
  const $ = (id) => document.getElementById(id);
  const state = $('pushState');
  const types = () => Array.from(document.querySelectorAll('#pushTypes input:checked')).map((c) => c.value);
  const toast = (m, t) => (window.HC && HC.toast ? HC.toast(m, t || 'success') : alert(m));
  async function refresh() {
    const P = window.HCPush;
    if (!P || !P.supported()) {
      state.textContent = T.unsupported;
      return;
    }
    let st;
    try { st = await P.status(); } catch (e) { state.textContent = e.message; return; }
    const blocked = P.permission() === 'denied';
    state.innerHTML = '';
    const badge = document.createElement('span');
    badge.className = 'badge me-2 ' + (st.subscribed ? 'text-bg-success' : 'text-bg-secondary');
    badge.textContent = st.subscribed ? 'ON' : 'OFF';
    state.append(badge, document.createTextNode(blocked ? T.blocked : (st.subscribed ? T.on : T.off)));
    $('pushOn').hidden = st.subscribed || blocked;
    $('pushOff').hidden = !st.subscribed;
    $('pushTest').hidden = !st.devices;
    const save = $('pushSaveTypes');
    if (save) {
      save.disabled = !st.subscribed;
      document.querySelectorAll('#pushTypes input').forEach((c) => { c.checked = st.types.includes(c.value); });
    }
  }
  const run = (btn, fn) => btn && btn.addEventListener('click', async () => {
    btn.disabled = true;
    try { await fn(); } catch (e) { toast(e.message, 'danger'); }
    btn.disabled = false;
    refresh();
  });
  run($('pushOn'), async () => { await HCPush.subscribe(types()); toast(T.enabled); });
  run($('pushOff'), async () => { await HCPush.unsubscribe(); toast(T.disabled); });
  run($('pushTest'), async () => { const r = await HCPush.test(); toast(T.sent.replace(':n', r.sent), r.sent ? 'success' : 'warning'); if (r.errors && r.errors.length) { toast(r.errors[0], 'warning'); } });
  run($('pushSaveTypes'), async () => { await HCPush.savePrefs(types()); toast(T.saved); });
  if (window.HCPush) { refresh(); } else { document.addEventListener('hc:pwa-ready', refresh); }
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
