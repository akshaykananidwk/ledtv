<?php
/**
 * Approvals (2.4, #32): managers approve / reject content that staff added or changed while the hotel
 * requires approval; staff see their own submissions (draft / waiting / rejected with the reason).
 * Hotel admins switch the rule on or off here. Logic: core/Approvals.php.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.submit');
Csrf::check();
$canApprove = Auth::can('content.approve');

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    try {
        switch ($op) {
            case 'approve':
                require_can('content.approve');
                $ok = Approvals::approve($id);
                flash($ok ? 'success' : 'warning', $ok ? __('Approved. It can now be shown on TVs.') : __('Nothing to approve.'));
                break;
            case 'reject':
                require_can('content.approve');
                $ok = Approvals::reject($id, req_str('reason', $_POST, 500));
                flash($ok ? 'success' : 'warning', $ok ? __('Rejected. The author sees your reason.') : __('Nothing to reject.'));
                break;
            case 'submit':
                if (!Approvals::canEdit()) {
                    require_can('content.manage');
                }
                $sent = Approvals::submit($id);
                flash($sent ? 'success' : 'warning', $sent ? __('Sent for approval.') : __('Nothing to send for approval.'));
                break;
            case 'setting':
                require_can('settings.manage');
                Access::requireUnrestricted('approval setting');
                $on = !empty($_POST['require_approval']);
                Settings::set(Approvals::SETTING, $on ? '1' : '0');
                ActivityLog::add('content_approval_setting', 'settings', null, $on ? 'on' : 'off');
                flash('success', $on ? __('Staff content now needs a manager\'s approval.') : __('Approval is switched off. New content is approved directly.'));
                break;
            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('approvals.php'));
}

$action = req_str('action', $_GET, 20);

// TV preview of the version waiting for approval (a staff edit is merged over the approved item).
if ($action === 'preview') {
    $item = ContentManager::find(req_int('id', $_GET));
    if (!$item) {
        http_response_code(404);
        flash('warning', __('Content not found.'));
        redirect(admin_url('approvals.php'));
    }
    $revision = Approvals::revision((int) $item['id']);
    $author = (int) ($revision['submitted_by'] ?? ($item['submitted_by'] ?: $item['created_by']) ?? 0);
    if (!$canApprove && !Auth::can('content.view') && $author !== (int) Auth::id()) {
        require_can('content.approve'); // 403
    }
    $merged = Approvals::merged($item, $revision);
    $tv = ContentManager::toTvItem($merged);
    $tv['duration'] = 0;
    $obj = [
        'mode' => 'preview', 'screen_on' => true,
        'room' => ['id' => 0, 'number' => '', 'name' => __('Preview'), 'floor' => ''],
        'hotel' => ['id' => Tenant::id(), 'name' => (string) Settings::get('hotel_name', ''), 'logo_url' => media_url((string) Settings::get('hotel_logo', ''))],
        'playlist' => null, 'items' => [$tv], 'overlay' => ContentResolver::overlay(), 'emergency' => null, 'branding' => Branding::forTv(),
    ];
    $obj['hash'] = sha1(json_out($obj));
    $obj['generated_at'] = date('c');
    $label = (string) $merged['title'];
    $embed = !empty($_GET['embed']);
    $simRefreshUrl = null;
    $simCloseUrl = admin_url('approvals.php');
    $simBadge = $revision ? __('Change waiting for approval') : __('Waiting for approval');
    header('Cache-Control: no-store');
    require __DIR__ . '/partials/tv_simulator.php';
    exit;
}

$queue = $canApprove ? Approvals::entries() : [];
$mine = Approvals::entries((int) Auth::id());
$pageTitle = __('Approvals');
$activeNav = 'approvals';
require __DIR__ . '/partials/header.php';

/** One queue / submission card. */
$card = static function (array $en, bool $review) use ($canApprove): void {
    $it = $en['item'];
    $rev = $en['revision'];
    $view = Approvals::merged($it, $rev);
    $thumb = ContentManager::thumbUrl($view);
    $type = (string) $it['type'];
    ?>
    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-3">
        <a href="<?= e(admin_url('approvals.php', ['action' => 'preview', 'id' => $it['id']])) ?>" target="_blank" rel="noopener" class="content-thumb rounded border d-flex align-items-center justify-content-center bg-light" style="width:160px;height:90px;overflow:hidden" title="<?= e(__('Preview')) ?>">
          <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" style="max-width:100%;max-height:100%"><?php else: ?><i class="bi <?= e(ContentManager::TYPE_ICONS[$type] ?? 'bi-file') ?> fs-1 text-muted"></i><?php endif; ?>
        </a>
        <div class="flex-grow-1" style="min-width:220px">
          <div class="fw-semibold"><?= e((string) $view['title']) ?></div>
          <div class="small text-muted"><?= e(__(ContentManager::TYPES[$type] ?? $type)) ?> · <?= e($en['author'] !== '' ? $en['author'] : '-') ?> · <?= e(time_ago((string) $en['at'])) ?></div>
          <div class="mt-1 d-flex flex-wrap gap-1"><?= Approvals::badge((string) $en['status'], $rev) ?><?= ContentRules::validityBadge($view) ?></div>
          <?php if ($rev && $rev['status'] === 'pending'): ?><div class="small mt-1"><i class="bi bi-info-circle"></i> <?= e(__('Edit of approved content: TVs keep the approved version until you decide.')) ?></div><?php endif; ?>
          <?php if (in_array($type, ['announcement'], true)): ?><div class="small mt-1 border-start ps-2"><?= e(mb_substr((string) $view['body'], 0, 300)) ?></div><?php endif; ?>
          <?php if (in_array($type, ['url', 'youtube', 'stream'], true) || ($view['url'] ?? '')): ?><div class="small mt-1 text-break"><?= e((string) $view['url']) ?></div><?php endif; ?>
          <?php if ($en['note']): ?><div class="alert alert-danger py-1 px-2 small mt-2 mb-0"><strong><?= e(__('Reason')) ?>:</strong> <?= e((string) $en['note']) ?></div><?php endif; ?>
        </div>
        <div class="d-flex flex-column gap-2" style="min-width:220px">
          <a class="btn btn-sm btn-light border" href="<?= e(admin_url('approvals.php', ['action' => 'preview', 'id' => $it['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> <?= e(__('Preview')) ?></a>
          <?php if ($review && $canApprove): ?>
            <form method="post" class="d-grid">
              <?= Csrf::field() ?><input type="hidden" name="op" value="approve"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> <?= e(__('Approve')) ?></button>
            </form>
            <form method="post" class="d-flex gap-1">
              <?= Csrf::field() ?><input type="hidden" name="op" value="reject"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <input class="form-control form-control-sm" name="reason" required maxlength="500" placeholder="<?= e(__('Reason for rejection')) ?>" aria-label="<?= e(__('Reason for rejection')) ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> <?= e(__('Reject')) ?></button>
            </form>
          <?php elseif (!$review): ?>
            <?php if (Approvals::canEdit()): ?>
              <a class="btn btn-sm btn-primary" href="<?= e(admin_url('content.php', ['action' => 'edit', 'id' => $it['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
            <?php endif; ?>
            <?php if (in_array($en['status'], ['draft', 'rejected'], true) && Approvals::canEdit()): ?>
              <form method="post" class="d-grid">
                <?= Csrf::field() ?><input type="hidden" name="op" value="submit"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-send"></i> <?= e(__('Send for approval')) ?></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
};
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Approvals')) ?></h1>
    <p class="lead-sm"><?= e(Approvals::enabled()
        ? __('Content added or changed by staff and reception waits here until a manager approves it.')
        : __('Approval is switched off: all content is approved directly.')) ?></p>
  </div>
  <?php if (Approvals::canEdit()): ?><a class="btn btn-primary" href="<?= e(admin_url('content.php')) ?>"><i class="bi bi-images"></i> <?= e(__('Content Library')) ?></a><?php endif; ?>
</div>

<?php if (Auth::can('settings.manage') && !Access::restricted()): ?>
<form method="post" class="card card-body mb-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="setting">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <div class="form-check form-switch m-0">
      <input class="form-check-input" type="checkbox" role="switch" id="reqAppr" name="require_approval" value="1"<?= Approvals::enabled() ? ' checked' : '' ?>>
      <label class="form-check-label fw-semibold" for="reqAppr"><?= e(__('Require approval')) ?></label>
    </div>
    <span class="small text-muted flex-grow-1"><?= e(__('When on, staff and reception can add and edit content, but it reaches the TVs only after a manager approves it. Managers\' own content is approved directly.')) ?></span>
    <button class="btn btn-sm btn-primary"><?= e(__('Save')) ?></button>
  </div>
</form>
<?php endif; ?>

<?php if ($canApprove): ?>
  <h2 class="h5 mt-2"><i class="bi bi-hourglass"></i> <?= e(__('Waiting for approval')) ?> <span class="badge text-bg-warning"><?= count($queue) ?></span></h2>
  <?php if (!$queue): ?>
    <div class="card mb-3"><div class="hc-empty py-4"><i class="bi bi-patch-check"></i><p class="text-muted mb-0"><?= e(__('Nothing is waiting for approval.')) ?></p></div></div>
  <?php endif; ?>
  <?php foreach ($queue as $en) { $card($en, true); } ?>
<?php endif; ?>

<h2 class="h5 mt-4"><i class="bi bi-person"></i> <?= e(__('My submissions')) ?></h2>
<?php if (!$mine): ?>
  <div class="card"><div class="hc-empty py-4"><i class="bi bi-inbox"></i><p class="text-muted mb-0"><?= e(__('You have no content waiting, in draft or rejected.')) ?></p></div></div>
<?php endif; ?>
<?php foreach ($mine as $en) { $card($en, false); } ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
