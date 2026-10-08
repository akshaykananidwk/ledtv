<?php
/**
 * Platform → All screens (docs/modules/platform_screens.md): every TV of every customer (resellers:
 * of their own customers) with filters, counters, pagination, CSV export, per-row and bulk commands
 * (per customer context through Broadcaster), app update, revoke, moving TVs to another customer /
 * screen, and (platform admins) the unassigned device pool with the platform registration key.
 * GET ?action=rooms&customer=ID → JSON screens of a customer (move dialog).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform_screens.php';

$user = Auth::require('platform.screens');
Csrf::check();

if (is_post()) {
    ps_handle_post(self_url());
}

if (req_str('action', $_GET, 20) === 'rooms') {
    $cid = req_int('customer', $_GET);
    if (!PlatformScreens::canSeeHotel($cid)) {
        ajax_error(__('Customer not found.'), 404, 'NOT_FOUND');
    }
    ajax_ok(['screens' => array_map(static fn ($r) => [
        'id' => (int) $r['id'],
        'label' => $r['room_number'] . ($r['name'] && $r['name'] !== $r['room_number'] ? ' — ' . $r['name'] : '') . ((int) $r['tvs'] ? ' (' . __(':n TVs', ['n' => (int) $r['tvs']]) . ')' : ''),
    ], PlatformScreens::screensOf($cid))]);
}

$f = PlatformScreens::filters($_GET);
if ($f['customer'] && !PlatformScreens::canSeeHotel($f['customer'])) {
    $f['customer'] = 0;
}

if (req_str('export', $_GET, 10) === 'csv') {
    $res = PlatformScreens::list($f, PlatformScreens::scopeHotelIds(), true);
    $rows = PlatformScreens::decorate($res['rows'], $res['total'] <= PlatformScreens::CSV_FULL_MODE_MAX);
    ActivityLog::add('platform_screens_export', 'device', null, 'CSV export of ' . count($rows) . ' screens', null);
    csv_download('all-screens-' . date('Y-m-d') . '.csv',
        ['customer_id', 'customer', 'screen_id', 'screen_name', 'floor', 'groups', 'device_id', 'model', 'platform', 'app_version', 'app_version_code',
            'needs_update', 'status', 'last_seen', 'mode', 'now_showing', 'health_warnings', 'lan_ip', 'public_ip', 'registered_at'],
        (static function () use ($rows) {
            foreach ($rows as $d) {
                yield [
                    $d['hotel_id'], $d['hotel_name'], $d['room_number'] ?? '', $d['room_name'] ?? '', $d['floor'] ?? '', $d['group_names'] ?? '',
                    $d['device_uid'], $d['model'] ?? '', ($d['platform'] ?? 'android'), $d['app_version'] ?? '', $d['app_version_code'] ?? '',
                    $d['outdated'] ? 'yes' : 'no', (int) $d['is_revoked'] ? 'revoked' : ($d['online'] ? 'online' : 'offline'), $d['last_ping'] ?? '',
                    $d['mode'], $d['showing'], implode('; ', array_map(static fn ($k) => DeviceHealth::warningLabel((string) $k), array_keys($d['warnings']))),
                    $d['ip_address'] ?? '', $d['public_ip'] ?? '', $d['registered_at'] ?? '',
                ];
            }
        })()
    );
}

$scope = PlatformScreens::scopeHotelIds();
$counters = PlatformScreens::counters($scope);
$res = PlatformScreens::list($f, $scope);
if ($f['page'] > $res['pages']) {
    $f['page'] = $res['pages'];
    $res = PlatformScreens::list($f, $scope);
}
$rows = PlatformScreens::decorate($res['rows']);
$customers = PlatformScreens::customers();
$versions = PlatformScreens::versions($scope);
$canPool = Auth::can('platform.pool') && DevicePool::available();
$pool = $canPool ? DevicePool::all() : [];
$filtered = $f['q'] !== '' || $f['customer'] || $f['status'] !== '' || $f['platform'] !== '' || $f['version'] !== '' || $f['update'] || $f['warn'] || $f['unassigned'];

$pageTitle = __('All screens');
$activeNav = 'platform_screens';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-tv"></i> <?= e(__('All screens')) ?></h1>
    <p class="lead-sm"><?= e(Auth::role() === 'reseller' ? __('Every TV of your customers.') : __('Every TV of every customer: status, content, health and commands.')) ?></p></div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="<?= e(self_url(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-filetype-csv"></i> <?= e(__('Export CSV')) ?></a>
  </div>
</div>

<?= ps_counters($counters) ?>

<form method="get" class="card card-body mb-3" id="psFilters">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
      <label class="form-label small" for="fq"><?= e(__('Search')) ?></label>
      <input class="form-control" id="fq" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('Screen, device ID, model, IP, customer…')) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small" for="fc"><?= e(__('Customer')) ?></label>
      <select class="form-select" id="fc" name="customer">
        <option value=""><?= e(__('All')) ?></option>
        <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $f['customer'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small" for="fs"><?= e(__('Status')) ?></label>
      <select class="form-select" id="fs" name="status">
        <option value=""><?= e(__('Active TVs')) ?></option>
        <option value="online"<?= $f['status'] === 'online' ? ' selected' : '' ?>><?= e(__('Online')) ?></option>
        <option value="offline"<?= $f['status'] === 'offline' ? ' selected' : '' ?>><?= e(__('Offline')) ?></option>
        <option value="revoked"<?= $f['status'] === 'revoked' ? ' selected' : '' ?>><?= e(__('Revoked')) ?></option>
        <option value="any"<?= $f['status'] === 'any' ? ' selected' : '' ?>><?= e(__('All incl. revoked')) ?></option>
      </select>
    </div>
    <div class="col-6 col-md-1">
      <label class="form-label small" for="fp"><?= e(__('Platform')) ?></label>
      <select class="form-select" id="fp" name="platform">
        <option value=""><?= e(__('All')) ?></option>
        <option value="android"<?= $f['platform'] === 'android' ? ' selected' : '' ?>>Android</option>
        <option value="web"<?= $f['platform'] === 'web' ? ' selected' : '' ?>><?= e(__('Web player')) ?></option>
      </select>
    </div>
    <div class="col-6 col-md-1">
      <label class="form-label small" for="fv"><?= e(__('Version')) ?></label>
      <select class="form-select" id="fv" name="version">
        <option value=""><?= e(__('All')) ?></option>
        <?php foreach ($versions as $v): ?><option value="<?= e($v) ?>"<?= $v === $f['version'] ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-3 d-flex flex-wrap gap-3 small">
      <label class="form-check"><input class="form-check-input" type="checkbox" name="update" value="1"<?= $f['update'] ? ' checked' : '' ?>> <?= e(__('Needs update')) ?></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="warn" value="1"<?= $f['warn'] ? ' checked' : '' ?>> <?= e(__('Health warning')) ?></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="unassigned" value="1"<?= $f['unassigned'] ? ' checked' : '' ?>> <?= e(__('Without screen')) ?></label>
    </div>
    <div class="col-12 d-flex gap-2 align-items-center">
      <button class="btn btn-primary"><i class="bi bi-funnel"></i> <?= e(__('Filter')) ?></button>
      <?php if ($filtered): ?><a class="btn btn-light border" href="<?= e(admin_url('platform_screens.php')) ?>"><i class="bi bi-x-lg"></i> <?= e(__('Clear')) ?></a><?php endif; ?>
      <span class="ms-auto small text-muted"><?= e(__(':n screens', ['n' => $res['total']])) ?></span>
      <select class="form-select form-select-sm w-auto" name="per_page" aria-label="<?= e(__('Per page')) ?>" onchange="this.form.submit()">
        <?php foreach (PlatformScreens::PER_PAGE_OPTIONS as $pp): ?><option value="<?= $pp ?>"<?= $pp === $f['per_page'] ? ' selected' : '' ?>><?= e(__(':n per page', ['n' => $pp])) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<div class="card"><?= ps_table($rows) ?>
  <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
</div>
<?= ps_bulk_bar($customers) ?>

<?php if ($canPool): $poolOn = DevicePool::enabled(); ?>
<div class="card mt-4" id="pool">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <span><i class="bi bi-inbox"></i> <?= e(__('Unassigned pool')) ?> <span class="badge text-bg-light border"><?= count($pool) ?></span></span>
    <form method="post" class="ms-auto d-flex align-items-center gap-2">
      <?= Csrf::field() ?><input type="hidden" name="op" value="pool_toggle"><input type="hidden" name="enabled" value="<?= $poolOn ? '0' : '1' ?>">
      <button class="btn btn-sm <?= $poolOn ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= e($poolOn ? __('Turn off platform registration') : __('Turn on platform registration')) ?></button>
    </form>
  </div>
  <div class="card-body">
    <p class="small text-muted mb-2"><?= e(__('TVs registered with the platform registration key are not part of any customer. They show a "waiting for setup" screen until you assign them here — set TVs up before selling them. The screen number typed on the TV is kept as its screen ID.')) ?></p>
    <?php if ($poolOn): ?>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="small"><?= e(__('Platform registration key')) ?>:</span> <code id="poolKey"><?= e(DevicePool::key()) ?></code>
        <button type="button" class="btn btn-xs btn-light border" data-copy="<?= e(DevicePool::key()) ?>"><i class="bi bi-clipboard"></i></button>
        <form method="post" class="d-inline" data-confirm="<?= e(__('Create a new platform registration key? New TVs then need the new key.')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="pool_regen">
          <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> <?= e(__('New key')) ?></button></form>
      </div>
    <?php else: ?>
      <p class="small"><?= e(__('Platform registration is off: TVs can only register with a customer\'s key.')) ?></p>
    <?php endif; ?>
    <?php if ($pool): ?>
    <form method="post" id="poolForm">
      <?= Csrf::field() ?>
      <div class="table-responsive"><table class="table table-sm table-hc align-middle">
        <thead><tr><th style="width:2rem"><input type="checkbox" class="form-check-input" data-check-all=".pool-cb" aria-label="<?= e(__('Select all')) ?>"></th>
          <th><?= e(__('Device')) ?></th><th><?= e(__('Screen ID')) ?></th><th><?= e(__('Status')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Source')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('IP')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Registered')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($pool as $p): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input pool-cb" name="pool_ids[]" value="<?= (int) $p['id'] ?>" aria-label="<?= e($p['device_uid']) ?>"></td>
            <td><span class="mono small"><?= e($p['device_uid']) ?></span><div class="small text-muted"><?= e(($p['model'] ?? '-') . ' · ' . ps_platform_label($p) . ' · v' . ($p['app_version'] ?? '?')) ?></div>
              <?php if ($p['notes']): ?><div class="small"><i class="bi bi-sticky"></i> <?= e($p['notes']) ?></div><?php endif; ?></td>
            <td><?= e($p['label'] ?? '-') ?></td>
            <td><?= status_badge(DevicePool::isOnline($p) ? 'online' : 'offline') ?><div class="small text-muted"><?= e(time_ago($p['last_ping'])) ?></div></td>
            <td class="d-none d-md-table-cell small"><?= $p['source'] === 'removed' ? e(__('Removed from :c', ['c' => $p['from_hotel'] ?? '#' . $p['from_hotel_id']])) : e(__('Platform key')) ?></td>
            <td class="d-none d-lg-table-cell small mono"><?= e($p['ip_address'] ?? $p['public_ip'] ?? '-') ?></td>
            <td class="d-none d-lg-table-cell small text-muted"><?= e(substr((string) $p['registered_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?= ps_move_fields($customers, 'psPool') ?>
      <div class="d-flex flex-wrap gap-2 mt-2">
        <button class="btn btn-primary" name="op" value="pool_assign"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Assign to customer')) ?></button>
        <button class="btn btn-outline-danger" name="op" value="pool_delete" data-confirm="<?= e(__('Remove the selected TVs from the pool? They return to their setup screen.')) ?>"><i class="bi bi-trash"></i> <?= e(__('Remove')) ?></button>
      </div>
    </form>
    <?php else: ?>
      <div class="text-muted small"><?= e(__('No unassigned TVs.')) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
