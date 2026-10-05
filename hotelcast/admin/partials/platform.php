<?php
declare(strict_types=1);

/**
 * Shared helpers for the platform / reseller pages (hotel form + save handler).
 * Permission checks stay in the pages.
 */

require_once __DIR__ . '/common.php';

/**
 * Save a hotel from $_POST (create or update). $resellerId forces the reseller (reseller panel,
 * limited fields). Redirects back to $backUrl with errors. Returns the hotel id.
 */
function platform_save_hotel(?array $existing, ?int $resellerId, string $backUrl): int
{
    $byReseller = $resellerId !== null;
    [$data, $errors] = Hotels::validate($_POST, $existing, $byReseller);
    $admin = null;
    if (!$existing) {
        [$admin, $adminErrors] = Hotels::validateAdmin($_POST, $byReseller);
        $errors = array_merge($errors, $adminErrors);
        if ($byReseller && !Hotels::resellerCanCreate($resellerId)) {
            $errors[] = __('Your hotel allowance is used up. Ask the platform to raise it.');
        }
    }
    // Branding logo override (stored with the platform files).
    if (!$errors && isset($_FILES['brand_logo']) && is_array($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $up = Uploader::handle($_FILES['brand_logo'], 'logo', 'platform');
            $data['brand_logo'] = $up['path'];
            if ($up['thumb']) {
                Uploader::delete($up['thumb']);
            }
        } catch (RuntimeException $e) {
            $errors[] = __('Logo') . ': ' . $e->getMessage();
        }
    } elseif ($existing && !empty($_POST['remove_brand_logo'])) {
        $data['brand_logo'] = null;
    }
    if ($errors) {
        flash_errors($errors);
        redirect($backUrl);
    }
    if ($byReseller) {
        $data['reseller_id'] = $resellerId;
    }
    if ($existing) {
        $statusChange = isset($data['status']) && $data['status'] !== $existing['status'] ? $data['status'] : null;
        unset($data['status']);
        Hotels::update((int) $existing['id'], $data);
        if ($statusChange) {
            Hotels::setStatus((int) $existing['id'], $statusChange, 'manual');
        }
        ActivityLog::add('hotel_update', 'hotel', (int) $existing['id'], $data['name']);
        flash('success', __('Hotel ":n" saved.', ['n' => $data['name']]));
        return (int) $existing['id'];
    }
    $status = $data['status'] ?? 'active';
    unset($data['status']);
    $id = Hotels::create($data, $admin, Auth::id());
    if ($status !== 'active') {
        Hotels::setStatus($id, $status, 'manual');
    }
    ActivityLog::add('hotel_create', 'hotel', $id, $data['name'] . ($admin ? ' (admin ' . $admin['username'] . ')' : ''));
    flash('success', __('Hotel ":n" created.', ['n' => $data['name']]) . ($admin ? ' ' . __('Super admin :u can log in now.', ['u' => $admin['username']]) : ''));
    return $id;
}

/** Hotel form fields (inside a <form enctype="multipart/form-data">). */
function hotel_form_fields(array $h, bool $byReseller, bool $isNew): string
{
    $plans = Hotels::plans(true);
    $resellers = $byReseller ? [] : Hotels::resellers();
    $v = fn (string $k) => e((string) ($h[$k] ?? ''));
    ob_start();
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card"><div class="card-header"><?= e(__('Hotel')) ?></div><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="h_name"><?= e(__('Hotel name')) ?> *</label>
            <input class="form-control" id="h_name" name="name" value="<?= $v('name') ?>" required maxlength="120">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="h_plan"><?= e(__('Plan')) ?></label>
            <select class="form-select" id="h_plan" name="plan_id">
              <option value=""><?= e(__('— No plan (not billed) —')) ?></option>
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= (int) ($h['plan_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?> · <?= e(money($p['price_per_tv_month'])) ?>/<?= e(__('TV/month')) ?><?= $p['max_tvs'] !== null ? ' · ' . e(__('max :n TVs', ['n' => $p['max_tvs']])) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (!$byReseller): ?>
          <div class="col-sm-6">
            <label class="form-label" for="h_res"><?= e(__('Reseller')) ?></label>
            <select class="form-select" id="h_res" name="reseller_id">
              <option value=""><?= e(__('— Direct customer —')) ?></option>
              <?php foreach ($resellers as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) ($h['reseller_id'] ?? 0) === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="h_max"><?= e(__('Max TVs')) ?></label>
            <input class="form-control" type="number" min="0" id="h_max" name="max_tvs" value="<?= $v('max_tvs') ?>" placeholder="<?= e(__('plan limit')) ?>">
            <div class="form-text"><?= e(__('Empty = limit of the plan.')) ?></div>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="h_exp"><?= e(__('Valid until')) ?></label>
            <input class="form-control" type="date" id="h_exp" name="expires_at" value="<?= !empty($h['expires_at']) ? e(date('Y-m-d', (int) strtotime((string) $h['expires_at']))) : '' ?>">
            <div class="form-text"><?= e(__('Empty = no expiry.')) ?></div>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="h_status"><?= e(__('Status')) ?></label>
            <select class="form-select" id="h_status" name="status">
              <?php foreach (['active' => __('Active'), 'suspended' => __('Suspended'), 'expired' => __('Expired')] as $s => $l): ?>
                <option value="<?= e($s) ?>"<?= ($h['status'] ?? 'active') === $s ? ' selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-sm-6"><label class="form-label" for="h_cn"><?= e(__('Contact person')) ?></label><input class="form-control" id="h_cn" name="contact_name" value="<?= $v('contact_name') ?>" maxlength="120"></div>
          <div class="col-sm-6"><label class="form-label" for="h_cp"><?= e(__('Phone / WhatsApp')) ?></label><input class="form-control" id="h_cp" name="contact_phone" value="<?= $v('contact_phone') ?>" maxlength="40" placeholder="+91…"></div>
          <div class="col-sm-6"><label class="form-label" for="h_ce"><?= e(__('Billing email')) ?></label><input class="form-control" type="email" id="h_ce" name="contact_email" value="<?= $v('contact_email') ?>" maxlength="190"></div>
          <div class="col-sm-6"><label class="form-label" for="h_gst"><?= e(__('GSTIN')) ?></label><input class="form-control" id="h_gst" name="gstin" value="<?= $v('gstin') ?>" maxlength="20"></div>
          <div class="col-sm-8"><label class="form-label" for="h_addr"><?= e(__('Address')) ?></label><input class="form-control" id="h_addr" name="address" value="<?= $v('address') ?>" maxlength="500"></div>
          <div class="col-sm-4"><label class="form-label" for="h_city"><?= e(__('City')) ?></label><input class="form-control" id="h_city" name="city" value="<?= $v('city') ?>" maxlength="80"></div>
          <div class="col-12"><label class="form-label" for="h_notes"><?= e(__('Notes')) ?></label><textarea class="form-control" id="h_notes" name="notes" rows="2" maxlength="1000"><?= $v('notes') ?></textarea></div>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><?= e(__('Branding override')) ?></div><div class="card-body row g-3">
          <div class="col-12 small text-muted"><?= e(__('Optional. Replaces the platform / reseller name, logo and colour for this hotel (login page with ?b=slug, admin panel, TVs).')) ?></div>
          <div class="col-sm-7"><label class="form-label" for="h_bn"><?= e(__('Product name')) ?></label><input class="form-control" id="h_bn" name="brand_name" value="<?= $v('brand_name') ?>" maxlength="120" placeholder="HotelCast"></div>
          <div class="col-sm-5"><label class="form-label" for="h_bc"><?= e(__('Colour')) ?></label><input class="form-control" id="h_bc" name="brand_color" value="<?= $v('brand_color') ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#7B1FA2"></div>
          <div class="col-12">
            <label class="form-label" for="h_bl"><?= e(__('Logo')) ?></label>
            <input class="form-control" type="file" id="h_bl" name="brand_logo" accept="image/png,image/jpeg,image/webp">
            <?php if (!empty($h['brand_logo'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $h['brand_logo'])) ?>" alt="" style="max-height:40px">
                <label class="form-check-label small"><input class="form-check-input" type="checkbox" name="remove_brand_logo" value="1"> <?= e(__('Remove')) ?></label></div>
            <?php endif; ?>
          </div>
        </div></div>
        <?php if ($isNew): ?>
        <div class="card"><div class="card-header"><?= e(__('First super admin of the hotel')) ?></div><div class="card-body row g-3">
          <div class="col-12 small text-muted"><?= e($byReseller ? __('Required. The hotel owner logs in with this account.') : __('Optional — you can also enter the hotel and add users later.')) ?></div>
          <div class="col-sm-6"><label class="form-label" for="a_u"><?= e(__('Username')) ?></label><input class="form-control" id="a_u" name="admin_username" maxlength="50" autocomplete="off" pattern="[A-Za-z0-9_.\-]{3,50}"></div>
          <div class="col-sm-6"><label class="form-label" for="a_n"><?= e(__('Full name')) ?></label><input class="form-control" id="a_n" name="admin_name" maxlength="120"></div>
          <div class="col-12"><label class="form-label" for="a_e"><?= e(__('Email')) ?></label><input class="form-control" type="email" id="a_e" name="admin_email" maxlength="190" autocomplete="off"></div>
          <div class="col-12"><label class="form-label" for="a_p"><?= e(__('Password')) ?></label><input class="form-control" type="password" id="a_p" name="admin_password" autocomplete="new-password" data-strength="#apBar"><div class="progress mt-1" style="height:4px"><div class="progress-bar" id="apBar"></div></div></div>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Small "x / y TVs" text. */
function tv_usage_label(int $used, ?int $max): string
{
    return $max === null ? (string) $used : $used . ' / ' . $max;
}
