<?php
declare(strict_types=1);
/**
 * Ads & sponsors (#11): sponsors (contact, contract) and ad campaigns (content item, targeting,
 * date range, daily window, frequency, daily cap per TV, priority, status). Manager+.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('ads.manage');
$pageTitle = __('Ads & Sponsors');
$activeNav = 'ads';
if (!Ads::enabled()) {
    http_response_code(403);
    require __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">' . e(__('Advertising is not included in your plan.')) . '</div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    if ($op === 'sponsor_save') {
        $id = req_int('id', $_POST);
        if ($id && !Ads::findSponsor($id)) {
            redirect(admin_url('ads.php', ['tab' => 'sponsors']));
        }
        [$data, $errors] = Ads::validateSponsor($_POST);
        if ($errors) {
            flash('danger', implode("\n", $errors));
            redirect(admin_url('ads.php', ['action' => 'sponsor', 'id' => $id ?: null]));
        }
        $id = Ads::saveSponsor($id ?: null, $data);
        ActivityLog::add('sponsor_save', 'sponsor', $id, $data['name']);
        flash('success', __('":t" saved.', ['t' => $data['name']]));
        redirect(admin_url('ads.php', ['tab' => 'sponsors']));
    }
    if ($op === 'sponsor_delete') {
        $id = req_int('id', $_POST);
        $s = Ads::findSponsor($id);
        if ($s) {
            Ads::deleteSponsor($id);
            ActivityLog::add('sponsor_delete', 'sponsor', $id, $s['name']);
            flash('success', __('":t" deleted.', ['t' => $s['name']]));
        }
        redirect(admin_url('ads.php', ['tab' => 'sponsors']));
    }
    if ($op === 'campaign_save') {
        $id = req_int('id', $_POST);
        if ($id && !Ads::findCampaign($id)) {
            redirect(admin_url('ads.php'));
        }
        [$data, $errors] = Ads::validateCampaign($_POST);
        if ($errors) {
            flash('danger', implode("\n", $errors));
            redirect(admin_url('ads.php', ['action' => 'campaign', 'id' => $id ?: null, 'sponsor_id' => req_int('sponsor_id', $_POST) ?: null]));
        }
        $id = Ads::saveCampaign($id ?: null, $data, Auth::id());
        ActivityLog::add('ad_campaign_save', 'ad_campaign', $id, $data['name']);
        flash('success', __('":t" saved.', ['t' => $data['name']]));
        redirect(admin_url('ads.php'));
    }
    if ($op === 'campaign_status') {
        $id = req_int('id', $_POST);
        $c = Ads::findCampaign($id);
        if ($c) {
            $new = $c['status'] === 'active' ? 'paused' : 'active';
            Ads::setStatus($id, $new);
            ActivityLog::add('ad_campaign_status', 'ad_campaign', $id, $c['name'] . ' → ' . $new);
            flash('success', $new === 'active' ? __('":t" is running again.', ['t' => $c['name']]) : __('":t" is paused.', ['t' => $c['name']]));
        }
        redirect(admin_url('ads.php'));
    }
    if ($op === 'campaign_delete') {
        $id = req_int('id', $_POST);
        $c = Ads::findCampaign($id);
        if ($c) {
            Ads::deleteCampaign($id);
            ActivityLog::add('ad_campaign_delete', 'ad_campaign', $id, $c['name']);
            flash('success', __('":t" deleted.', ['t' => $c['name']]));
        }
        redirect(admin_url('ads.php'));
    }
    flash('warning', __('Unknown action.'));
    redirect(admin_url('ads.php'));
}

$action = req_str('action', $_GET, 20);
$tab = ($_GET['tab'] ?? '') === 'sponsors' ? 'sponsors' : 'campaigns';

// ---------------------------------------------------------------- sponsor form
if ($action === 'sponsor') {
    $id = req_int('id', $_GET);
    $s = $id ? Ads::findSponsor($id) : null;
    if ($id && !$s) {
        redirect(admin_url('ads.php', ['tab' => 'sponsors']));
    }
    $s ??= ['id' => 0, 'name' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'contract_start' => date('Y-m-d'), 'contract_end' => '', 'notes' => ''];
    $pageTitle = $s['id'] ? __('Edit sponsor') : __('New sponsor');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-person-badge"></i> <?= e($pageTitle) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('ads.php', ['tab' => 'sponsors'])) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post" class="card" style="max-width:820px">
      <div class="card-body row g-3">
        <?= Csrf::field() ?><input type="hidden" name="op" value="sponsor_save"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <div class="col-12"><label class="form-label" for="s_name"><?= e(__('Sponsor / business name')) ?> *</label>
          <input class="form-control form-control-lg" id="s_name" name="name" value="<?= e($s['name']) ?>" required maxlength="150" placeholder="<?= e(__('e.g. Krishna Sweets')) ?>"></div>
        <div class="col-md-4"><label class="form-label" for="s_contact"><?= e(__('Contact person')) ?></label>
          <input class="form-control" id="s_contact" name="contact_name" value="<?= e((string) $s['contact_name']) ?>" maxlength="120"></div>
        <div class="col-md-4"><label class="form-label" for="s_phone"><?= e(__('Phone')) ?></label>
          <input class="form-control" id="s_phone" name="phone" type="tel" value="<?= e((string) $s['phone']) ?>" maxlength="40"></div>
        <div class="col-md-4"><label class="form-label" for="s_email"><?= e(__('Email')) ?></label>
          <input class="form-control" id="s_email" name="email" type="email" value="<?= e((string) $s['email']) ?>" maxlength="190"></div>
        <div class="col-6 col-md-4"><label class="form-label" for="s_cs"><?= e(__('Contract start')) ?></label>
          <input class="form-control" id="s_cs" name="contract_start" type="date" value="<?= e((string) $s['contract_start']) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label" for="s_ce"><?= e(__('Contract end')) ?></label>
          <input class="form-control" id="s_ce" name="contract_end" type="date" value="<?= e((string) $s['contract_end']) ?>"></div>
        <div class="col-12"><label class="form-label" for="s_notes"><?= e(__('Notes (rate, billing details…)')) ?></label>
          <textarea class="form-control" id="s_notes" name="notes" rows="3" maxlength="1000"><?= e((string) $s['notes']) ?></textarea></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('ads.php', ['tab' => 'sponsors'])) ?>"><?= e(__('Cancel')) ?></a></div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- campaign form
if ($action === 'campaign') {
    $id = req_int('id', $_GET);
    $c = $id ? Ads::findCampaign($id) : null;
    if ($id && !$c) {
        redirect(admin_url('ads.php'));
    }
    $sponsors = Ads::sponsors();
    if (!$sponsors) {
        flash('info', __('Add a sponsor first.'));
        redirect(admin_url('ads.php', ['action' => 'sponsor']));
    }
    $c ??= ['id' => 0, 'sponsor_id' => req_int('sponsor_id', $_GET), 'name' => '', 'content_id' => null, 'start_date' => date('Y-m-d'), 'end_date' => '',
        'daily_start' => null, 'daily_end' => null, 'target_type' => 'all', 'target_ids' => '[]', 'freq_items' => 3, 'freq_minutes' => 10,
        'max_per_day' => null, 'priority' => 0, 'status' => 'active'];
    $freqType = $c['freq_items'] ? 'items' : 'minutes';
    $contents = hc_content_list();
    $pageTitle = $c['id'] ? __('Edit campaign') : __('New campaign');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <h1><i class="bi bi-badge-ad"></i> <?= e($pageTitle) ?></h1>
      <a class="btn btn-light border" href="<?= e(admin_url('ads.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <form method="post">
      <?= Csrf::field() ?><input type="hidden" name="op" value="campaign_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card mb-3"><div class="card-body row g-3">
            <div class="col-12"><label class="form-label" for="c_name"><?= e(__('Campaign name')) ?> *</label>
              <input class="form-control form-control-lg" id="c_name" name="name" value="<?= e($c['name']) ?>" required maxlength="150" placeholder="<?= e(__('e.g. Diwali sweets offer')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="c_sponsor"><?= e(__('Sponsor')) ?> *</label>
              <select class="form-select" id="c_sponsor" name="sponsor_id" required>
                <option value=""><?= e(__('Choose…')) ?></option>
                <?php foreach ($sponsors as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $c['sponsor_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-6"><label class="form-label" for="c_content"><?= e(__('Ad content')) ?> *</label>
              <select class="form-select" id="c_content" name="content_id" required>
                <option value=""><?= e(__('Choose…')) ?></option>
                <?php foreach ($contents as $ci): ?><option value="<?= (int) $ci['id'] ?>"<?= (int) $c['content_id'] === (int) $ci['id'] ? ' selected' : '' ?>><?= e($ci['title']) ?> (<?= e(__(ContentManager::TYPES[$ci['type']] ?? $ci['type'])) ?>)<?= (int) $ci['is_active'] ? '' : ' — ' . e(__('inactive')) ?></option><?php endforeach; ?>
              </select>
              <div class="form-text"><?= e(__('An image, video or announcement from the Content Library. Its duration is the ad length (0 = 15 s; videos play to the end).')) ?></div></div>
          </div></div>

          <div class="card mb-3"><div class="card-header"><i class="bi bi-bullseye"></i> <?= e(__('Which TVs')) ?></div><div class="card-body">
            <?= target_picker('ad', ['type' => $c['target_type'], 'ids' => json_decode((string) $c['target_ids'], true) ?: []]) ?>
          </div></div>

          <div class="card mb-3"><div class="card-header"><i class="bi bi-arrow-repeat"></i> <?= e(__('How often')) ?></div><div class="card-body row g-3">
            <div class="col-12">
              <div class="form-check"><input class="form-check-input" type="radio" name="freq_type" id="ft_items" value="items"<?= $freqType === 'items' ? ' checked' : '' ?>>
                <label class="form-check-label" for="ft_items"><?= e(__('In playlists: after every')) ?>
                  <input class="form-control form-control-sm d-inline-block mx-1" style="width:5rem" type="number" name="freq_items" min="1" max="100" value="<?= (int) ($c['freq_items'] ?: 3) ?>" aria-label="<?= e(__('Items')) ?>"> <?= e(__('items')) ?></label></div>
              <div class="form-check mt-1"><input class="form-check-input" type="radio" name="freq_type" id="ft_min" value="minutes"<?= $freqType === 'minutes' ? ' checked' : '' ?>>
                <label class="form-check-label" for="ft_min"><?= e(__('In playlists: by time (see minutes below)')) ?></label></div>
            </div>
            <div class="col-md-6"><label class="form-label" for="c_min"><?= e(__('Every M minutes')) ?></label>
              <input class="form-control" id="c_min" type="number" name="freq_minutes" min="1" max="720" value="<?= (int) $c['freq_minutes'] ?>">
              <div class="form-text"><?= e(__('Rooms showing one item (not a playlist): the item is shown for M minutes, then the ad, then again.')) ?></div></div>
            <div class="col-md-6"><label class="form-label" for="c_cap"><?= e(__('Max impressions per TV per day')) ?></label>
              <input class="form-control" id="c_cap" type="number" name="max_per_day" min="0" max="100000" value="<?= e((string) ($c['max_per_day'] ?? '')) ?>" placeholder="<?= e(__('No limit')) ?>">
              <div class="form-text"><?= e(__('When a TV reached the limit, the ad stops there until tomorrow.')) ?></div></div>
          </div></div>
        </div>

        <div class="col-lg-5">
          <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar-range"></i> <?= e(__('When')) ?></div><div class="card-body row g-3">
            <div class="col-6"><label class="form-label" for="c_sd"><?= e(__('Start date')) ?></label>
              <input class="form-control" id="c_sd" type="date" name="start_date" value="<?= e((string) $c['start_date']) ?>" required></div>
            <div class="col-6"><label class="form-label" for="c_ed"><?= e(__('End date')) ?></label>
              <input class="form-control" id="c_ed" type="date" name="end_date" value="<?= e((string) $c['end_date']) ?>">
              <div class="form-text"><?= e(__('Empty = no end.')) ?></div></div>
            <div class="col-6"><label class="form-label" for="c_ds"><?= e(__('Daily from')) ?></label>
              <input class="form-control" id="c_ds" type="time" name="daily_start" value="<?= e($c['daily_start'] ? substr((string) $c['daily_start'], 0, 5) : '') ?>"></div>
            <div class="col-6"><label class="form-label" for="c_de"><?= e(__('Daily until')) ?></label>
              <input class="form-control" id="c_de" type="time" name="daily_end" value="<?= e($c['daily_end'] ? substr((string) $c['daily_end'], 0, 5) : '') ?>"></div>
            <div class="col-12 form-text mt-0"><?= e(__('Leave the daily times empty to show the ad all day. Overnight windows (e.g. 20:00–02:00) work too.')) ?></div>
          </div></div>
          <div class="card mb-3"><div class="card-body row g-3">
            <div class="col-6"><label class="form-label" for="c_prio"><?= e(__('Priority')) ?></label>
              <input class="form-control" id="c_prio" type="number" name="priority" min="-100" max="100" value="<?= (int) $c['priority'] ?>">
              <div class="form-text"><?= e(__('Higher first when several ads share a break.')) ?></div></div>
            <div class="col-6"><label class="form-label" for="c_status"><?= e(__('Status')) ?></label>
              <select class="form-select" id="c_status" name="status">
                <option value="active"<?= $c['status'] === 'active' ? ' selected' : '' ?>><?= e(__('Active')) ?></option>
                <option value="paused"<?= $c['status'] === 'paused' ? ' selected' : '' ?>><?= e(__('Paused')) ?></option>
              </select></div>
          </div></div>
          <div class="hint-box small mb-3"><i class="bi bi-info-circle"></i>
            <?= e(__('Ads are never shown during an emergency message, when a TV is switched off or on the welcome screen.')) ?></div>
        </div>
        <div class="col-12 d-flex gap-2 flex-wrap">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('ads.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- lists
$sponsors = Ads::sponsors();
$campaigns = Ads::campaigns();
$todayCounts = [];
foreach (DB::all(
    "SELECT ad_campaign_id, COUNT(*) AS n FROM broadcast_logs WHERE hotel_id = :hid AND event = 'played' AND ad_campaign_id IS NOT NULL AND created_at >= :f GROUP BY ad_campaign_id",
    hid() + ['f' => date('Y-m-d 00:00:00')]
) as $r) {
    $todayCounts[(int) $r['ad_campaign_id']] = (int) $r['n'];
}
$liveIds = array_map(fn ($c) => (int) $c['id'], Ads::liveCampaigns());
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Ads & Sponsors')) ?></h1>
    <p class="lead-sm"><?= e(__('Show sponsor ads between your content and give sponsors a report for billing.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="<?= e(admin_url('ads.php', ['action' => 'sponsor'])) ?>"><i class="bi bi-person-plus"></i> <?= e(__('Add sponsor')) ?></a>
    <a class="btn btn-primary" href="<?= e(admin_url('ads.php', ['action' => 'campaign'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New campaign')) ?></a>
  </div>
</div>

<ul class="nav nav-tabs nav-tabs-scroll mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'campaigns' ? ' active' : '' ?>" href="<?= e(admin_url('ads.php')) ?>"><i class="bi bi-badge-ad"></i> <?= e(__('Campaigns')) ?> <span class="badge text-bg-light border"><?= count($campaigns) ?></span></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'sponsors' ? ' active' : '' ?>" href="<?= e(admin_url('ads.php', ['tab' => 'sponsors'])) ?>"><i class="bi bi-person-badge"></i> <?= e(__('Sponsors')) ?> <span class="badge text-bg-light border"><?= count($sponsors) ?></span></a></li>
</ul>

<?php if ($tab === 'sponsors'): ?>
  <?php if (!$sponsors): ?>
    <div class="card"><div class="hc-empty"><i class="bi bi-person-badge"></i>
      <p class="mb-1"><strong><?= e(__('No sponsors yet')) ?></strong></p>
      <p class="text-muted"><?= e(__('Local shops, restaurants or tour operators can pay to show their ad on your TVs.')) ?></p>
      <a class="btn btn-primary" href="<?= e(admin_url('ads.php', ['action' => 'sponsor'])) ?>"><i class="bi bi-person-plus"></i> <?= e(__('Add sponsor')) ?></a>
    </div></div>
  <?php else: ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hc table-hover mb-0">
      <thead><tr><th><?= e(__('Sponsor')) ?></th><th class="d-none d-md-table-cell"><?= e(__('Contact')) ?></th><th><?= e(__('Contract')) ?></th><th><?= e(__('Campaigns')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($sponsors as $s):
          $expired = $s['contract_end'] && $s['contract_end'] < date('Y-m-d'); ?>
        <tr>
          <td><strong><?= e($s['name']) ?></strong><?php if ($s['notes']): ?><div class="small text-muted text-truncate" style="max-width:22rem"><?= e($s['notes']) ?></div><?php endif; ?></td>
          <td class="d-none d-md-table-cell small"><?= e((string) $s['contact_name']) ?><?php if ($s['phone']): ?><br><a href="tel:<?= e((string) $s['phone']) ?>"><?= e((string) $s['phone']) ?></a><?php endif; ?><?php if ($s['email']): ?><br><?= e((string) $s['email']) ?><?php endif; ?></td>
          <td class="small text-nowrap"><?= e($s['contract_start'] ? date('d M Y', (int) strtotime((string) $s['contract_start'])) : '…') ?> → <?= e($s['contract_end'] ? date('d M Y', (int) strtotime((string) $s['contract_end'])) : '…') ?>
            <?php if ($expired): ?><span class="badge text-bg-warning"><?= e(__('expired')) ?></span><?php endif; ?></td>
          <td><?= (int) $s['campaign_count'] ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('sponsor_report.php', ['sponsor_id' => $s['id']])) ?>" title="<?= e(__('Report')) ?>"><i class="bi bi-bar-chart"></i> <span class="d-none d-lg-inline"><?= e(__('Report')) ?></span></a>
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('ads.php', ['action' => 'campaign', 'sponsor_id' => $s['id']])) ?>" title="<?= e(__('New campaign')) ?>"><i class="bi bi-plus-lg"></i></a>
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('ads.php', ['action' => 'sponsor', 'id' => $s['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete sponsor ":t" and all its campaigns?', ['t' => $s['name']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="sponsor_delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>

<?php else: ?>
  <?php if (!$campaigns): ?>
    <div class="card"><div class="hc-empty"><i class="bi bi-badge-ad"></i>
      <p class="mb-1"><strong><?= e(__('No ad campaigns yet')) ?></strong></p>
      <p class="text-muted"><?= e(__('1. Add the sponsor. 2. Upload the ad in the Content Library. 3. Create a campaign.')) ?></p>
      <a class="btn btn-primary" href="<?= e(admin_url('ads.php', ['action' => $sponsors ? 'campaign' : 'sponsor'])) ?>"><i class="bi bi-plus-lg"></i> <?= e($sponsors ? __('New campaign') : __('Add sponsor')) ?></a>
    </div></div>
  <?php else: ?>
  <div class="card"><div class="table-responsive">
    <table class="table table-hc table-hover mb-0">
      <thead><tr><th><?= e(__('Campaign')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Ad content')) ?></th><th class="d-none d-md-table-cell"><?= e(__('When')) ?></th>
        <th class="d-none d-xl-table-cell"><?= e(__('Frequency')) ?></th><th><?= e(__('Today')) ?></th><th><?= e(__('Status')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c):
          $live = in_array((int) $c['id'], $liveIds, true);
          $ended = $c['end_date'] !== null && $c['end_date'] < date('Y-m-d'); ?>
        <tr class="<?= $c['status'] === 'paused' || $ended ? 'text-muted' : '' ?>">
          <td><strong><?= e($c['name']) ?></strong><div class="small text-muted"><?= e($c['sponsor_name']) ?> · <?= e(Broadcaster::describeTarget((string) $c['target_type'], (string) $c['target_ids'])) ?></div></td>
          <td class="d-none d-lg-table-cell small">
            <?php if ($c['content_title'] !== null): ?>
              <a href="<?= e(admin_url('preview.php', ['content_id' => $c['content_id']])) ?>" target="_blank" rel="noopener"><?= e($c['content_title']) ?></a>
              <?php if (!(int) $c['content_active']): ?><span class="badge text-bg-warning"><?= e(__('inactive')) ?></span><?php endif; ?>
            <?php else: ?><span class="text-danger"><?= e(__('Content deleted')) ?></span><?php endif; ?>
          </td>
          <td class="d-none d-md-table-cell small text-nowrap">
            <?= e(date('d M', (int) strtotime((string) $c['start_date']))) ?> → <?= e($c['end_date'] ? date('d M Y', (int) strtotime((string) $c['end_date'])) : '…') ?>
            <?php if ($c['daily_start']): ?><br><?= e(substr((string) $c['daily_start'], 0, 5) . '–' . substr((string) $c['daily_end'], 0, 5)) ?><?php endif; ?>
          </td>
          <td class="d-none d-xl-table-cell small"><?= e(Ads::frequencyLabel($c)) ?><?php if ($c['max_per_day']): ?><br><?= e(__('max :n / TV / day', ['n' => (int) $c['max_per_day']])) ?><?php endif; ?></td>
          <td><strong><?= (int) ($todayCounts[(int) $c['id']] ?? 0) ?></strong></td>
          <td>
            <?php if ($ended): ?><span class="badge text-bg-secondary"><?= e(__('Ended')) ?></span>
            <?php elseif ($c['status'] === 'paused'): ?><span class="badge text-bg-warning"><?= e(__('Paused')) ?></span>
            <?php elseif ($live): ?><span class="badge text-bg-success"><?= e(__('On air')) ?></span>
            <?php else: ?><span class="badge text-bg-primary"><?= e(__('Scheduled')) ?></span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('sponsor_report.php', ['campaign_id' => $c['id']])) ?>" title="<?= e(__('Report')) ?>"><i class="bi bi-bar-chart"></i></a>
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('ads.php', ['action' => 'campaign', 'id' => $c['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?><input type="hidden" name="op" value="campaign_status"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-light border" title="<?= e($c['status'] === 'active' ? __('Pause') : __('Resume')) ?>"><i class="bi <?= $c['status'] === 'active' ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete campaign ":t"? Its report data is deleted too.', ['t' => $c['name']])) ?>">
              <?= Csrf::field() ?><input type="hidden" name="op" value="campaign_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= e(__('"Today" counts ads the TVs reported as played since midnight.')) ?></p>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
