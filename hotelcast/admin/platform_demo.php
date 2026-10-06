<?php
/**
 * Platform → Demo (#21).
 *  - Public demo (platform admins): enable / disable, hotel name, "create / reset now". The demo
 *    hotel is reset every night by DemoResetTask; visitors use demo.php (read-only admin login and
 *    the TV simulator demo_tv.php).
 *  - Demo for client (platform admins + resellers): private demo copy named after a prospect, valid
 *    N days (default 7), then expired and purged automatically. Login details are shown once.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/platform.php';

$user = Auth::require('demo.client');
Csrf::check();

$isPlatform = Auth::can('platform.manage');
$resellerId = Auth::role() === 'reseller' ? (int) ($user['reseller_id'] ?? 0) : null;
if ($resellerId === 0) {
    $resellerId = -1; // reseller account without reseller: sees nothing
}

/** Client demo the current user may manage, or null. */
$ownDemo = static function (int $id) use ($resellerId): ?array {
    $h = DB::one("SELECT * FROM hotels WHERE id = :id AND demo_kind = 'client'", ['id' => $id]);
    if (!$h || ($resellerId !== null && (int) $h['reseller_id'] !== $resellerId)) {
        return null;
    }
    return $h;
};

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    try {
        switch ($op) {
            case 'public_save':
                require_can('platform.manage');
                $enable = !empty($_POST['demo_public_enabled']);
                Settings::setPlatform('demo_public_enabled', $enable ? '1' : '0');
                Settings::setPlatform('demo_hotel_name', req_str('demo_hotel_name', $_POST, 100));
                if ($enable && !Demo::publicHotelId()) {
                    Demo::resetPublic();
                }
                ActivityLog::add('demo_settings', 'settings', null, 'Public demo ' . ($enable ? 'on' : 'off'));
                flash('success', __('Settings saved.'));
                break;
            case 'public_reset':
                require_can('platform.manage');
                $hid = Demo::resetPublic();
                ActivityLog::add('demo_reset', 'hotel', $hid, 'Public demo reset');
                flash('success', __('The demo hotel was reset with fresh sample data.'));
                break;
            case 'client_create':
                $r = Demo::createClientDemo(req_str('prospect', $_POST, 100), $resellerId !== null && $resellerId > 0 ? $resellerId : null, Auth::id(),
                    req_int('days', $_POST) ?: Demo::CLIENT_DAYS, req_str('email', $_POST, 190), !empty($_POST['readonly']));
                ActivityLog::add('demo_client_create', 'hotel', $r['hotel_id'], req_str('prospect', $_POST, 100));
                flash('success', __('Client demo created. Login: :u · Password: :p · valid until :d. Copy these now — the password is not shown again.',
                    ['u' => $r['username'], 'p' => $r['password'], 'd' => date('d M Y', (int) strtotime($r['expires_at']))]));
                break;
            case 'client_delete':
                $h = $ownDemo(req_int('id', $_POST));
                if (!$h) {
                    throw new InvalidArgumentException(__('Not found.'));
                }
                Demo::deleteClientDemo((int) $h['id']);
                ActivityLog::add('demo_client_delete', 'hotel', (int) $h['id'], $h['name']);
                flash('success', __('Client demo deleted.'));
                break;
            case 'client_enter':
                $h = $ownDemo(req_int('id', $_POST));
                if ($h && !$h['demo_purged_at'] && Auth::enterHotel((int) $h['id'])) {
                    redirect(admin_url('index.php'));
                }
                throw new InvalidArgumentException(__('You cannot open this hotel.'));
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('platform_demo.php'));
}

$publicId = $isPlatform ? Demo::publicHotelId() : null;
$public = $publicId ? Hotels::find($publicId) : null;
$publicRooms = $publicId ? (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $publicId]) : 0;
$lastReset = (int) Settings::platform('demo_last_reset', '0');
$clients = DB::all(
    "SELECT h.*, r.name AS reseller_name, (SELECT u.username FROM users u WHERE u.hotel_id = h.id ORDER BY u.id LIMIT 1) AS login
     FROM hotels h LEFT JOIN resellers r ON r.id = h.reseller_id
     WHERE h.demo_kind = 'client'" . ($resellerId !== null ? ' AND h.reseller_id = :r' : '') . ' ORDER BY h.demo_purged_at IS NULL DESC, h.id DESC LIMIT 100',
    $resellerId !== null ? ['r' => $resellerId] : []
);
$pageTitle = $isPlatform ? __('Demo') : __('Client demos');
$activeNav = 'platform_demo';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Show the product to prospects with realistic sample data.')) ?></p></div></div>

<div class="row g-3">
<?php if ($isPlatform): ?>
  <div class="col-xl-5"><div class="card h-100"><div class="card-header"><i class="bi bi-globe"></i> <?= e(__('Public demo')) ?></div><div class="card-body">
    <form method="post" class="row g-2 mb-3"><?= Csrf::field() ?><input type="hidden" name="op" value="public_save">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="d_en" name="demo_public_enabled" value="1"<?= Demo::publicEnabled() ? ' checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="d_en"><?= e(__('Public demo on')) ?></label></div>
        <div class="form-text"><?= e(__('Anyone can open :url, look around a read-only admin panel and watch the TV simulator. Data is reset every night.', ['url' => base_url('demo.php')])) ?></div></div>
      <div class="col-12"><label class="form-label" for="d_name"><?= e(__('Demo hotel name')) ?></label><input class="form-control" id="d_name" name="demo_hotel_name" maxlength="100" value="<?= e((string) Settings::platform('demo_hotel_name', '')) ?>" placeholder="Hotel Dwarka Palace (Demo)"></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
    </form>
    <?php if ($public): ?>
      <table class="table table-sm small mb-3">
        <tr><th class="text-muted fw-normal"><?= e(__('Hotel')) ?></th><td><a href="<?= e(admin_url('platform_hotels.php', ['action' => 'view', 'id' => $public['id']])) ?>"><?= e($public['name']) ?></a> · #<?= (int) $public['id'] ?></td></tr>
        <tr><th class="text-muted fw-normal"><?= e(__('Rooms')) ?></th><td><?= $publicRooms ?> · <?= e(__(':n TVs', ['n' => Tenant::tvCount((int) $public['id'])])) ?></td></tr>
        <tr><th class="text-muted fw-normal"><?= e(__('Last reset')) ?></th><td><?= e($lastReset ? date('d M Y H:i', $lastReset) : __('never')) ?></td></tr>
      </table>
      <div class="d-flex flex-wrap gap-2">
        <form method="post" data-confirm="<?= e(__('Delete all demo data and load fresh sample data now?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="public_reset"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> <?= e(__('Reset demo now')) ?></button></form>
        <?php if (Demo::publicEnabled()): ?><a class="btn btn-light border btn-sm" href="<?= e(base_url('demo.php')) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> <?= e(__('Open demo page')) ?></a><?php endif; ?>
      </div>
    <?php else: ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="public_reset"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic"></i> <?= e(__('Create demo hotel')) ?></button></form>
    <?php endif; ?>
  </div></div></div>
<?php endif; ?>

  <div class="<?= $isPlatform ? 'col-xl-7' : 'col-12' ?>"><div class="card h-100"><div class="card-header"><i class="bi bi-person-video3"></i> <?= e(__('Demo for client')) ?></div><div class="card-body">
    <p class="small text-muted"><?= e(__('Creates a private demo hotel named after your prospect with rich sample data and its own login. It is deleted automatically when it expires.')) ?></p>
    <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="op" value="client_create">
      <div class="col-sm-6"><label class="form-label" for="c_p"><?= e(__('Prospect hotel name')) ?> *</label><input class="form-control" id="c_p" name="prospect" required maxlength="100" placeholder="Hotel Sagar"></div>
      <div class="col-sm-6"><label class="form-label" for="c_e"><?= e(__('Prospect email (optional, used as login)')) ?></label><input class="form-control" type="email" id="c_e" name="email" maxlength="190"></div>
      <div class="col-6 col-sm-3"><label class="form-label" for="c_d"><?= e(__('Valid for')) ?></label><div class="input-group"><input class="form-control" type="number" id="c_d" name="days" min="1" max="30" value="<?= Demo::CLIENT_DAYS ?>"><span class="input-group-text"><?= e(__('days')) ?></span></div></div>
      <div class="col-6 col-sm-5"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="c_ro" name="readonly" value="1"><label class="form-check-label" for="c_ro"><?= e(__('Read-only (changes disabled)')) ?></label></div></div>
      <div class="col-12 col-sm-4"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> <?= e(__('Create client demo')) ?></button></div>
    </form>
    <div class="table-responsive mt-3"><table class="table table-sm table-hc mb-0">
      <thead><tr><th><?= e(__('Hotel')) ?></th><th class="d-none d-sm-table-cell"><?= e(__('Login')) ?></th><th><?= e(__('Valid until')) ?></th><th></th></tr></thead>
      <tbody>
      <?php if (!$clients): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= e(__('No client demos yet.')) ?></td></tr><?php endif; ?>
      <?php foreach ($clients as $c): $gone = (bool) $c['demo_purged_at']; ?>
        <tr class="<?= $gone ? 'text-muted' : '' ?>">
          <td><?= e($c['name']) ?><?= $c['reseller_name'] && $isPlatform ? '<div class="small text-muted">' . e($c['reseller_name']) . '</div>' : '' ?></td>
          <td class="d-none d-sm-table-cell small mono"><?= e($gone ? '—' : (string) $c['login']) ?></td>
          <td class="small text-nowrap"><?= $gone ? '<span class="badge text-bg-secondary">' . e(__('Deleted')) . '</span>' : e($c['expires_at'] ? date('d M Y', (int) strtotime((string) $c['expires_at'])) : '—') ?></td>
          <td class="text-end text-nowrap"><?php if (!$gone): ?>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="client_enter"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= e(__('Open')) ?>"><i class="bi bi-box-arrow-in-right"></i></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete this client demo and all its data now?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="client_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-light border text-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div></div></div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
