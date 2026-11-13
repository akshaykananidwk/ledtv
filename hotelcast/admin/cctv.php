<?php
declare(strict_types=1);
/**
 * 2.10 CCTV cameras (core/Cctv.php, docs/modules/cctv.md): add a Dahua / CP Plus / Hikvision / Prama / Uniview /
 * other NVR or DVR with its address, login and camera numbers; the cameras become live content items and grid
 * layouts that can be put on any screen. Lists the recorders with their items; delete removes a recorder's items.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.manage');
Csrf::check();

$form = ['name' => '', 'brand' => 'dahua', 'host' => '', 'port' => 554, 'username' => 'admin', 'password' => '', 'channels' => '1,2,3,4', 'quality' => 'auto', 'custom_path' => '', 'grid' => true];
$formErrors = [];
if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'add') {
        [$v, $formErrors] = Cctv::validate($_POST);
        if (!$formErrors) {
            $res = Cctv::create($v, Auth::id());
            flash('success', __(':n camera(s) of ":r" added to the Content Library:', ['n' => count($res['cameras']), 'r' => $v['name']]) . ' '
                . ($res['grids'] ? __('put "All cameras" on a screen to see them together.') : __('put a camera on a screen to see it.')));
            redirect(admin_url('cctv.php'));
        }
        $form = array_merge($form, array_intersect_key($_POST, $form), ['grid' => !empty($_POST['grid']), 'password' => '']);
    } elseif ($op === 'delete') {
        $n = Cctv::deleteGroup(req_str('group', $_POST, 20));
        flash($n ? 'success' : 'warning', $n ? __('Recorder removed (:n item(s)).', ['n' => $n]) : __('Nothing to remove.'));
        redirect(admin_url('cctv.php'));
    }
}

$recorders = Cctv::recorders();
$pageTitle = __('CCTV cameras');
$activeNav = 'cctv';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-camera-video"></i> <?= e(__('CCTV cameras')) ?></h1>
    <p class="lead-sm"><?= e(__('Show your CCTV cameras live on any TV: enter the recorder (NVR / DVR) once — every camera and an "All cameras" grid appear in the Content Library.')) ?></p></div>
</div>
<?php if ($formErrors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card" id="cctvForm" autocomplete="off"><div class="card-header"><i class="bi bi-plus-lg"></i> <?= e(__('Add recorder')) ?></div><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="add">
      <div class="col-sm-6"><label class="form-label" for="c_name"><?= e(__('Name')) ?> *</label><input class="form-control" id="c_name" name="name" maxlength="80" required value="<?= e((string) $form['name']) ?>" placeholder="<?= e(__('e.g. Shop NVR')) ?>"></div>
      <div class="col-sm-6"><label class="form-label" for="c_brand"><?= e(__('Brand')) ?></label><select class="form-select" id="c_brand" name="brand">
        <?php foreach (Cctv::BRANDS as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $form['brand'] === $k ? ' selected' : '' ?>><?= e($k === 'custom' ? __($label) : $label) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-8"><label class="form-label" for="c_host"><?= e(__('IP address or DDNS name')) ?> *</label><input class="form-control mono" id="c_host" name="host" maxlength="200" required value="<?= e((string) $form['host']) ?>" placeholder="192.168.1.108"></div>
      <div class="col-sm-4"><label class="form-label" for="c_port"><?= e(__('RTSP port')) ?></label><input class="form-control" type="number" min="1" max="65535" id="c_port" name="port" value="<?= (int) $form['port'] ?>"></div>
      <div class="col-sm-6"><label class="form-label" for="c_user"><?= e(__('Username')) ?></label><input class="form-control" id="c_user" name="username" maxlength="64" value="<?= e((string) $form['username']) ?>" autocomplete="off"></div>
      <div class="col-sm-6"><label class="form-label" for="c_pass"><?= e(__('Password')) ?></label><input class="form-control" type="password" id="c_pass" name="password" maxlength="64" autocomplete="new-password"></div>
      <div class="col-sm-6"><label class="form-label" for="c_ch"><?= e(__('Camera numbers')) ?> *</label><input class="form-control" id="c_ch" name="channels" maxlength="100" value="<?= e((string) $form['channels']) ?>" placeholder="1,2,3,4 · 1-8">
        <div class="form-text"><?= e(__('Channel numbers on the recorder, e.g. 1,2,3,4 or 1-8.')) ?></div></div>
      <div class="col-sm-6"><label class="form-label" for="c_q"><?= e(__('Picture quality')) ?></label><select class="form-select" id="c_q" name="quality">
        <?php foreach (['auto' => __('Automatic (small stream in grids, full stream alone)'), 'sub' => __('Small stream (fast, for slow internet)'), 'main' => __('Full HD stream')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $form['quality'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-12" id="c_customRow"><label class="form-label" for="c_custom"><?= e(__('RTSP path (only for "Other")')) ?></label><input class="form-control mono" id="c_custom" name="custom_path" maxlength="300" value="<?= e((string) $form['custom_path']) ?>" placeholder="/live/ch{ch}">
        <div class="form-text"><?= e(__('{ch} = camera number.')) ?></div></div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_grid" name="grid" value="1"<?= $form['grid'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="c_grid"><?= e(__('Also create an "All cameras" grid (up to 6 cameras per screen)')) ?></label></div></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-camera-video"></i> <?= e(__('Add cameras')) ?></button></div>
    </div></form>
    <div class="card mt-3"><div class="card-body small">
      <h2 class="h6"><i class="bi bi-info-circle"></i> <?= e(__('How the TV reaches the cameras')) ?></h2>
      <ul class="mb-0">
        <li><?= e(__('Same place: the TV and the recorder are on the same router / Wi-Fi — use the recorder\'s local IP address (e.g. 192.168.1.108).')) ?></li>
        <li><?= e(__('Another place: forward the recorder\'s RTSP port (554) on its router and use the public IP or a DDNS name.')) ?></li>
        <li><?= e(__('Login by serial number only (P2P cloud of Dahua / CP Plus / Hik-Connect) is a closed service of the brand and cannot be used; use the IP address or DDNS.')) ?></li>
        <li><?= e(__('Cheap TVs play about 4 cameras at once; the grid uses the small stream for that.')) ?></li>
      </ul>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><i class="bi bi-hdd-network"></i> <?= e(__('Recorders')) ?></div>
    <?php if (!$recorders): ?>
      <div class="card-body text-muted small" id="cctvEmpty"><?= e(__('No recorder yet. Add one with the form.')) ?></div>
    <?php else: ?>
      <ul class="list-group list-group-flush" id="cctvList">
      <?php foreach ($recorders as $r): ?>
        <li class="list-group-item" data-group="<?= e($r['group']) ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div><strong><?= e($r['nvr']) ?></strong> <span class="badge text-bg-light border"><?= e(Cctv::BRANDS[$r['brand']][0] ?? $r['brand']) ?></span>
              <div class="small text-muted mono"><?= e($r['host']) ?></div></div>
            <form method="post" class="m-0" data-confirm="<?= e(__('Remove ":r" and all its camera items from the Content Library?', ['r' => $r['nvr']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="group" value="<?= e($r['group']) ?>">
              <button class="btn btn-sm btn-outline-danger" aria-label="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
          <ul class="small mb-0 mt-1">
            <?php foreach ($r['items'] as $it): ?><li><i class="bi <?= $it['type'] === 'layout' ? 'bi-grid' : 'bi-camera-video' ?>"></i> <a href="<?= e(admin_url('content.php', ['action' => 'edit', 'id' => $it['id']])) ?>"><?= e($it['title']) ?></a></li><?php endforeach; ?>
          </ul>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    </div>
    <p class="small text-muted mt-2"><?= e(__('Put a camera or "All cameras" on a screen from Screens & TVs, a playlist or the schedule, like any other content.')) ?></p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
