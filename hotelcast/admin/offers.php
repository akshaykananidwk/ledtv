<?php
declare(strict_types=1);
/**
 * Shop / mall offers (#4): offers shown by the "Shop offers" display app (core/Apps/OffersApp.php).
 * Title, description, image (Uploader), price / old price, discount % (automatic or manual), badge,
 * valid from / to, sort order, active switch. TVs update within 15 seconds; ended offers disappear.
 * Phone friendly: cards instead of a table, big buttons.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('offers.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('offers.php'));
}
Csrf::check();

$formRow = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? Offers::find($id) : null; // another hotel's id → 404
    if (($id && !$existing) || (!$existing && $op !== 'save')) {
        flash('warning', __('Offer not found.'));
        redirect(admin_url('offers.php'));
    }
    switch ($op) {
        case 'save':
            [$data, $formErrors] = Offers::validate($_POST);
            $up = null;
            if (!$formErrors) {
                [$up, $formErrors] = BusinessApps::upload('image');
            }
            if ($formErrors) {
                http_response_code(422);
                $formRow = ['id' => $existing ? (int) $existing['id'] : 0, 'image_path' => $existing['image_path'] ?? null, 'thumb_path' => $existing['thumb_path'] ?? null] + $data + Offers::DEFAULTS;
                break;
            }
            $old = null;
            if ($up) {
                $data['image_path'] = $up['path'];
                $data['thumb_path'] = $up['thumb'];
                $old = $existing;
            } elseif ($existing && !empty($_POST['remove_image'])) {
                $data['image_path'] = null;
                $data['thumb_path'] = null;
                $old = $existing;
            }
            $newId = Offers::save($existing ? $id : null, $data);
            if ($old && $old['image_path']) {
                Uploader::delete($old['image_path'], $old['thumb_path']);
            }
            ActivityLog::add($existing ? 'offer_update' : 'offer_create', 'offer', $newId, mb_substr($data['title'], 0, 120));
            flash('success', __('Offer ":t" saved.', ['t' => $data['title']]));
            redirect(admin_url('offers.php'));

        case 'toggle':
            $on = !(int) $existing['is_active'];
            DB::update('offers', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
            ActivityLog::add('offer_toggle', 'offer', $id, ($on ? 'on: ' : 'off: ') . mb_substr((string) $existing['title'], 0, 100));
            flash('success', $on ? __('Offer ":t" is shown again.', ['t' => $existing['title']]) : __('Offer ":t" is hidden.', ['t' => $existing['title']]));
            redirect(admin_url('offers.php'));

        case 'delete':
            Offers::delete($id);
            ActivityLog::add('offer_delete', 'offer', $id, mb_substr((string) $existing['title'], 0, 120));
            flash('success', __('Offer ":t" deleted.', ['t' => $existing['title']]));
            redirect(admin_url('offers.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('offers.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'offers';
$pageTitle = __('Offers');
$dtl = static fn (?string $v): string => $v ? str_replace(' ', 'T', substr($v, 0, 16)) : '';
$num = static fn ($v): string => $v === null || $v === '' ? '' : BusinessApps::plain((float) $v);

if ($action === 'new' || $action === 'edit' || $formRow !== null) {
    if ($formRow === null) {
        $formRow = Offers::DEFAULTS;
        if ($action === 'edit') {
            $formRow = Offers::find(req_int('id', $_GET));
            if (!$formRow) {
                flash('warning', __('Offer not found.'));
                redirect(admin_url('offers.php'));
            }
        }
    }
    $o = $formRow;
    $isEdit = (int) $o['id'] > 0;
    $pageTitle = $isEdit ? __('Edit offer') : __('New offer');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-tags"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('Offers appear on every TV showing a Shop offers app screen, within 15 seconds.')) ?></p></div>
      <a href="<?= e(admin_url('offers.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('offers.php')) ?>" id="offerForm">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="o_title"><?= e(__('Title')) ?> *</label>
            <input class="form-control form-control-lg" id="o_title" name="title" value="<?= e($o['title']) ?>" required maxlength="190" placeholder="<?= e(__('e.g. Diwali sale: 30% off on sarees')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="o_desc"><?= e(__('Description')) ?></label>
            <textarea class="form-control" id="o_desc" name="description" rows="3" maxlength="<?= Offers::MAX_DESC ?>"><?= e($o['description']) ?></textarea>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label" for="o_price"><?= e(__('Offer price')) ?> (₹)</label>
            <input class="form-control form-control-lg" id="o_price" name="price" inputmode="decimal" value="<?= e($num($o['price'])) ?>" placeholder="999">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label" for="o_old"><?= e(__('Old price (MRP)')) ?> (₹)</label>
            <input class="form-control form-control-lg" id="o_old" name="old_price" inputmode="decimal" value="<?= e($num($o['old_price'])) ?>" placeholder="1499">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label" for="o_pct"><?= e(__('Discount %')) ?></label>
            <input class="form-control form-control-lg" id="o_pct" name="discount_pct" type="number" min="0" max="99" value="<?= e($num($o['discount_pct'])) ?>" placeholder="<?= e(__('Automatic')) ?>">
            <div class="form-text"><?= e(__('Leave empty to calculate it from the old price.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="o_image"><?= e(__('Image (optional)')) ?></label>
            <input class="form-control" type="file" id="o_image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <?php if (!empty($o['image_path'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2">
                <img src="<?= e(media_url($o['thumb_path'] ?: $o['image_path'])) ?>" alt="" class="rounded border" style="max-height:80px">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="o_rm" name="remove_image" value="1"><label class="form-check-label" for="o_rm"><?= e(__('Remove image')) ?></label></div>
              </div>
            <?php endif; ?>
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="o_badge"><?= e(__('Badge (optional)')) ?></label>
            <input class="form-control" id="o_badge" name="badge" maxlength="40" value="<?= e($o['badge']) ?>" placeholder="<?= e(__('e.g. New, Bestseller, Limited')) ?>" list="o_badges">
            <datalist id="o_badges"><?php foreach ([__('New'), __('Bestseller'), __('Limited stock'), __('Today only')] as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-12">
            <label class="form-label" for="o_from"><?= e(__('Valid from')) ?></label>
            <input class="form-control" type="datetime-local" id="o_from" name="valid_from" value="<?= e($dtl($o['valid_from'])) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="o_to"><?= e(__('Valid until')) ?></label>
            <input class="form-control" type="datetime-local" id="o_to" name="valid_to" value="<?= e($dtl($o['valid_to'])) ?>">
            <div class="form-text"><?= e(__('The TV shows a countdown and removes the offer when it ends. Leave empty for no end.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="o_sort"><?= e(__('Sort order')) ?></label>
            <input class="form-control" type="number" id="o_sort" name="sort" min="-1000" max="1000" value="<?= (int) $o['sort'] ?>">
            <div class="form-text"><?= e(__('Smaller numbers are shown first.')) ?></div>
          </div>
          <div class="col-12"><div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="o_active" name="is_active" value="1"<?= (int) $o['is_active'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="o_active"><?= e(__('Show on TVs')) ?></label>
          </div></div>
        </div></div>
        <div class="d-grid d-sm-flex gap-2 mt-3">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('offers.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div></div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list (cards, phone friendly)
$offers = Offers::all();
$screens = BusinessApps::screens('offers');
$states = [
    'live' => ['text-bg-success', 'bi-broadcast', __('Showing')],
    'scheduled' => ['text-bg-info', 'bi-clock', __('Scheduled')],
    'expired' => ['text-bg-secondary', 'bi-hourglass-bottom', __('Expired')],
    'off' => ['text-bg-light border', 'bi-pause-circle', __('Off')],
];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-tags"></i> <?= e(__('Offers')) ?></h1>
    <p class="lead-sm"><?= e(__('Discount offers for the Shop offers app screens on your TVs.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'offers'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Offer screens (:n)', ['n' => $screens]) : __('Create an offers screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('offers.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New offer')) ?></a>
  </div>
</div>
<?php if (!$offers): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-tags"></i><p class="text-muted"><?= e(__('No offers yet. Add the first one.')) ?></p></div></div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($offers as $o): [$cls, $icon, $label] = $states[Offers::state($o)]; $disc = Offers::discount($o); ?>
    <div class="col-12 col-md-6 col-xl-4" data-offer="<?= (int) $o['id'] ?>">
      <div class="card h-100"><div class="card-body d-flex gap-3">
        <?php if ($o['image_path']): ?><img src="<?= e(media_url($o['thumb_path'] ?: $o['image_path'])) ?>" alt="" class="rounded border flex-shrink-0" style="width:72px;height:72px;object-fit:cover"><?php endif; ?>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between gap-2">
            <strong class="text-break"><?= e($o['title']) ?></strong>
            <span class="badge rounded-pill align-self-start <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></span>
          </div>
          <div class="mt-1">
            <?php if ($o['price'] !== null): ?><span class="fw-bold fs-5"><?= e(BusinessApps::money((float) $o['price'])) ?></span><?php endif; ?>
            <?php if ($o['old_price'] !== null): ?><s class="text-muted ms-1"><?= e(BusinessApps::money((float) $o['old_price'])) ?></s><?php endif; ?>
            <?php if ($disc): ?><span class="badge text-bg-danger ms-1"><?= (int) $disc ?>% <?= e(__('OFF')) ?></span><?php endif; ?>
            <?php if ($o['badge'] !== ''): ?><span class="badge text-bg-warning ms-1"><?= e($o['badge']) ?></span><?php endif; ?>
          </div>
          <?php if ($o['valid_from'] || $o['valid_to']): ?>
            <div class="small text-muted mt-1"><i class="bi bi-calendar-range"></i> <?= e(($o['valid_from'] ? substr((string) $o['valid_from'], 0, 16) : '…') . ' → ' . ($o['valid_to'] ? substr((string) $o['valid_to'], 0, 16) : '…')) ?></div>
          <?php endif; ?>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('offers.php', ['action' => 'edit', 'id' => $o['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button class="btn btn-sm btn-light border"><i class="bi <?= (int) $o['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i> <?= e((int) $o['is_active'] ? __('Hide') : __('Show')) ?></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete offer ":t"?', ['t' => $o['title']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
