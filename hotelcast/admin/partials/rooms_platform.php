<?php
declare(strict_types=1);

/**
 * 2.5.1 Screens & TVs (admin/rooms.php) for users with platform scope (permission platform.screens:
 * platform admins = every customer, resellers = their own customers; docs/modules/platform_screens.md § 7):
 *
 *  - rooms_view_mode(): "This customer" | "All customers" (GET ?view=, remembered in the session; default
 *    All customers for platform admins, This customer for resellers; without an open customer platform
 *    admins always get All customers, resellers only after choosing it — else the old redirect to their panel). Customer users always get 'customer' — the check is server-side
 *    (Auth::can('platform.screens') tests the real role), ?view=all changes nothing for them.
 *  - rooms_view_switch(): the switch shown at the top of both views.
 *  - rooms_all_page(): the All customers view (shared table / filters / bulk bar of Platform → All
 *    screens, core/PlatformScreens.php) and the POST handler of that view and of the "Transfer to another
 *    customer" dialog (ps_form=1 → ps_handle_post; moving needs platform.move).
 */

require_once __DIR__ . '/platform_screens.php';
// platform_screens.php makes logs without an explicit customer platform-level; the customer view of
// rooms.php keeps logging into the customer, so the flag is set only where the platform pages need it.
ActivityLog::$platformScope = false;

/** Does the logged-in user have the platform view of Screens & TVs? */
function rooms_platform_scope(): bool
{
    return Auth::user() !== null && Auth::can('platform.screens');
}

/** 'all' | 'customer' (see file comment). */
function rooms_view_mode(): string
{
    if (!rooms_platform_scope()) {
        return 'customer';
    }
    Auth::startSession();
    $want = req_str('view', $_GET, 10);
    if (in_array($want, ['all', 'customer'], true)) {
        $_SESSION['hc_rooms_view'] = $want;
    }
    $stored = $_SESSION['hc_rooms_view'] ?? null;
    if (!Tenant::has()) {
        // Nothing to show in "This customer": platform admins (and resellers who picked All) get All customers;
        // a reseller without that choice keeps the old redirect to their panel.
        return Auth::role() === 'platform_admin' || $stored === 'all' ? 'all' : 'customer';
    }
    return ($stored ?? (Auth::role() === 'platform_admin' ? 'all' : 'customer')) === 'all' ? 'all' : 'customer';
}

/** View switch "This customer (<name>)" | "All customers (N TVs)". */
function rooms_view_switch(string $active, ?int $tvs = null): string
{
    if (!rooms_platform_scope()) {
        return '';
    }
    $tvs ??= PlatformScreens::tvTotal(PlatformScreens::scopeHotelIds());
    $name = Tenant::has() ? (string) (Tenant::hotel()['name'] ?? ('#' . Tenant::id())) : '';
    $allLabel = Auth::role() === 'platform_admin' ? __('All customers (:n TVs)', ['n' => $tvs]) : __('All my customers (:n TVs)', ['n' => $tvs]);
    ob_start();
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="roomsViewSwitch">
      <span class="small text-muted"><i class="bi bi-eye"></i> <?= e(__('View')) ?>:</span>
      <div class="btn-group" role="group" aria-label="<?= e(__('View')) ?>">
        <?php if ($name !== ''): ?>
          <a class="btn btn-sm <?= $active === 'customer' ? 'btn-primary active' : 'btn-outline-primary' ?>" href="<?= e(admin_url('rooms.php', ['view' => 'customer'])) ?>"<?= $active === 'customer' ? ' aria-current="page"' : '' ?>><i class="bi bi-person-badge"></i> <?= e(__('This customer (:c)', ['c' => $name])) ?></a>
        <?php else: ?>
          <span class="btn btn-sm btn-outline-secondary disabled" title="<?= e(__('Open a customer first (Customers → Login as this customer).')) ?>"><i class="bi bi-person-badge"></i> <?= e(__('This customer (none open)')) ?></span>
        <?php endif; ?>
        <a class="btn btn-sm <?= $active === 'all' ? 'btn-primary active' : 'btn-outline-primary' ?>" href="<?= e(admin_url('rooms.php', ['view' => 'all'])) ?>"<?= $active === 'all' ? ' aria-current="page"' : '' ?>><i class="bi bi-globe2"></i> <?= e($allLabel) ?></a>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** POST of the All customers view / transfer dialog (exits), or the All customers page (exits). */
function rooms_all_page(): never
{
    ActivityLog::$platformScope = true;
    if (is_post()) {
        // After a transfer from a TV's detail page that TV no longer belongs to the open customer.
        ps_handle_post(req_str('action', $_GET, 20) === '' ? self_url() : admin_url('rooms.php'));
    }
    $noTv = req_str('status', $_GET, 10) === 'notv';
    $f = PlatformScreens::filters($_GET);
    if ($f['customer'] && !PlatformScreens::canSeeHotel($f['customer'])) {
        $f['customer'] = 0;
    }
    $scope = PlatformScreens::scopeHotelIds();
    $counters = PlatformScreens::counters($scope);
    $res = $noTv ? PlatformScreens::screensWithoutTv($f, $scope) : PlatformScreens::list($f, $scope);
    if ($f['page'] > $res['pages']) {
        $f['page'] = $res['pages'];
        $res = $noTv ? PlatformScreens::screensWithoutTv($f, $scope) : PlatformScreens::list($f, $scope);
    }
    $rows = $noTv ? $res['rows'] : PlatformScreens::decorate($res['rows']);
    $customers = PlatformScreens::customers();
    $filtered = $f['q'] !== '' || $f['customer'] || $f['status'] !== '' || $noTv || $f['update'] || $f['warn'] || $f['unassigned'];
    $isRoot = Auth::role() === 'platform_admin';

    $pageTitle = $isRoot ? __('Screens — all customers') : __('Screens — your customers');
    $activeNav = 'rooms';
    require __DIR__ . '/header.php';
    ?>
    <?= rooms_view_switch('all', $counters['total']) ?>
    <div class="page-head">
      <div>
        <h1><i class="bi bi-tv"></i> <?= e($pageTitle) ?></h1>
        <p class="lead-sm"><?= e($isRoot ? __('Every TV of every customer, including the customers of your resellers.') : __('Every TV of your customers.')) ?></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if (Auth::can('platform.move')): ?><span class="small text-muted align-self-center"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer a TV with everything: row button or select TVs below.')) ?></span><?php endif; ?>
        <a class="btn btn-light border" href="<?= e(admin_url('platform_screens.php', $f['customer'] ? ['customer' => $f['customer']] : [])) ?>"><i class="bi bi-funnel"></i> <?= e(__('More filters & CSV')) ?></a>
      </div>
    </div>

    <?= ps_counters($counters, 'rooms.php') ?>

    <form method="get" class="card card-body mb-3" id="roomsAllFilters">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label small" for="raq"><?= e(__('Search')) ?></label>
          <input class="form-control" id="raq" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('Screen, device ID, model, IP, customer…')) ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small" for="rac"><?= e(__('Customer')) ?></label>
          <select class="form-select" id="rac" name="customer">
            <option value=""><?= e(__('All')) ?></option>
            <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $f['customer'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small" for="ras"><?= e(__('Status')) ?></label>
          <select class="form-select" id="ras" name="status">
            <option value=""><?= e(__('Active TVs')) ?></option>
            <option value="online"<?= $f['status'] === 'online' ? ' selected' : '' ?>><?= e(__('Online')) ?></option>
            <option value="offline"<?= $f['status'] === 'offline' ? ' selected' : '' ?>><?= e(__('Offline')) ?></option>
            <option value="revoked"<?= $f['status'] === 'revoked' ? ' selected' : '' ?>><?= e(__('Revoked')) ?></option>
            <option value="any"<?= $f['status'] === 'any' ? ' selected' : '' ?>><?= e(__('All incl. revoked')) ?></option>
            <option value="notv"<?= $noTv ? ' selected' : '' ?>><?= e(__('Screens without TV')) ?></option>
          </select>
        </div>
        <div class="col-12 col-md-3 d-flex gap-2 align-items-center">
          <button class="btn btn-primary"><i class="bi bi-funnel"></i> <?= e(__('Filter')) ?></button>
          <?php if ($filtered): ?><a class="btn btn-light border" href="<?= e(admin_url('rooms.php')) ?>"><i class="bi bi-x-lg"></i> <?= e(__('Clear')) ?></a><?php endif; ?>
          <select class="form-select form-select-sm w-auto ms-auto" name="per_page" aria-label="<?= e(__('Per page')) ?>" onchange="this.form.submit()">
            <?php foreach (PlatformScreens::PER_PAGE_OPTIONS as $pp): ?><option value="<?= $pp ?>"<?= $pp === $f['per_page'] ? ' selected' : '' ?>><?= e(__(':n per page', ['n' => $pp])) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="small text-muted mt-2"><?= e($noTv ? __(':n screens without TV', ['n' => $res['total']]) : __(':n screens', ['n' => $res['total']])) ?></div>
    </form>

    <?php if ($noTv): ?>
    <div class="card"><div class="table-responsive">
      <table class="table table-hc table-hover align-middle mb-0" id="roomsNoTv">
        <thead><tr><th><?= e(__('Customer')) ?></th><th><?= e(__('Screen')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Location / group')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= e(__('No screens match your filter.')) ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $r['hotel_id']])) ?>"><?= e($r['hotel_name']) ?></a></td>
            <td><strong><?= e($r['room_number']) ?></strong><?php if ($r['name']): ?><div class="small text-muted"><?= e($r['name']) ?></div><?php endif; ?></td>
            <td class="d-none d-md-table-cell small"><?= e(dot_trim(($r['floor'] !== null && $r['floor'] !== '' ? __('Floor') . ' ' . $r['floor'] : '') . ' · ' . ($r['group_names'] ?? '')) ?: '-') ?></td>
            <td><span class="badge text-bg-light border"><?= e(__('No TV')) ?></span><?php if (!(int) $r['is_enabled']): ?> <span class="badge text-bg-dark"><?= e(__('Off')) ?></span><?php endif; ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_customer.php', ['id' => $r['hotel_id'], 'tab' => 'screens'])) ?>" title="<?= e(__('Customer details')) ?>"><i class="bi bi-person-badge"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
      <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card"><?= ps_table($rows) ?>
      <?php if ($res['pages'] > 1): ?><div class="card-footer"><?= paginate($res['total'], $f['page'], $f['per_page']) ?></div><?php endif; ?>
    </div>
    <?= ps_bulk_bar($customers) ?>
    <?php endif; ?>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * "Transfer to another customer" for the customer view and the TV detail page of rooms.php: the dialog
 * (only with platform.move). The page renders the .js-ps-transfer buttons itself.
 */
function rooms_transfer_dialog(): string
{
    if (!Auth::can('platform.move')) {
        return '';
    }
    return ps_transfer_modal(PlatformScreens::customers());
}
