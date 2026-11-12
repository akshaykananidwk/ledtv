<?php
/**
 * Super Admin console → Live darshan (2.9.3, docs/modules/live_channels.md): live streams set up once for the
 * whole platform (first: Shri Dwarkadhish Live Darshan). "All customers" channels are added to every customer's
 * content library (and to new customers); changing a link updates every customer's copy and their TVs.
 * Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
ActivityLog::$platformScope = true;
Csrf::check();

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_str('id', $_POST, 40);
    try {
        if ($op === 'save') {
            $in = $_POST;
            // "Copy link from a customer's content" picker.
            $from = req_int('copy_from', $_POST);
            if ($from > 0 && trim((string) ($in['url'] ?? '')) === '') {
                $src = DB::one('SELECT type, url FROM content_items WHERE id = :id', ['id' => $from]);
                if ($src) {
                    $in['url'] = (string) $src['url'];
                    $in['type'] = $src['type'] === 'youtube' || preg_match('#^https?://([a-z0-9-]+\.)?(youtube\.com|youtu\.be)/#i', (string) $src['url']) ? 'youtube' : 'stream';
                }
            }
            $res = LiveChannels::save($id !== '' ? $id : null, $in);
            $c = $res['channel'];
            if (trim($c['url']) === '') {
                flash('warning', __('":t" saved. Add its live link so customers receive it.', ['t' => $c['title']]));
            } else {
                flash('success', __('":t" saved: added to :a customer(s), link updated for :u.', ['t' => $c['title'], 'a' => $res['added'], 'u' => $res['updated']]));
            }
        } elseif ($op === 'delete') {
            $c = LiveChannels::delete($id);
            flash($c ? 'success' : 'danger', $c ? __('":t" removed from the library. Customers keep their copy.', ['t' => $c['title']]) : __('Live channel not found.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    }
    redirect(admin_url('platform_live.php'));
}

$channels = LiveChannels::all();
$customers = (int) DB::value('SELECT COUNT(*) FROM hotels WHERE archived_at IS NULL');
$candidates = LiveChannels::candidates();
$edit = req_str('edit', $_GET, 40);
$editing = $edit !== '' ? ($channels[$edit] ?? null) : null;

/** Channel form (new or edit). */
function plive_form(?array $c, array $candidates): string
{
    $c ??= ['id' => '', 'title' => '', 'subtitle' => '', 'type' => 'stream', 'url' => '', 'auto' => true, 'mute' => false];
    $fid = 'plive_' . ($c['id'] !== '' ? $c['id'] : 'new');
    ob_start(); ?>
  <form method="post" class="plive-form" id="<?= e($fid) ?>">
    <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
    <div class="mb-2"><label class="form-label" for="<?= e($fid) ?>_t"><?= e(__('Name shown to customers')) ?> *</label>
      <input class="form-control" id="<?= e($fid) ?>_t" name="title" maxlength="150" required value="<?= e($c['title']) ?>"></div>
    <div class="mb-2"><label class="form-label" for="<?= e($fid) ?>_s"><?= e(__('Description')) ?></label>
      <input class="form-control" id="<?= e($fid) ?>_s" name="subtitle" maxlength="200" value="<?= e($c['subtitle']) ?>"></div>
    <div class="mb-2"><span class="form-label d-block"><?= e(__('Type')) ?></span>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="type" value="stream" id="<?= e($fid) ?>_ts"<?= $c['type'] !== 'youtube' ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= e($fid) ?>_ts"><?= e(__('Live stream (.m3u8 / RTSP / RTMP)')) ?></label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="type" value="youtube" id="<?= e($fid) ?>_ty"<?= $c['type'] === 'youtube' ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= e($fid) ?>_ty">YouTube</label></div></div>
    <div class="mb-2"><label class="form-label" for="<?= e($fid) ?>_u"><?= e(__('Live link')) ?></label>
      <input class="form-control mono" id="<?= e($fid) ?>_u" name="url" maxlength="1000" value="<?= e($c['url']) ?>" placeholder="https://…/live/index.m3u8 · https://www.youtube.com/live/…"></div>
    <?php if ($candidates): ?>
    <div class="mb-2"><label class="form-label" for="<?= e($fid) ?>_c"><?= e(__('…or copy the link from a customer\'s content')) ?></label>
      <select class="form-select form-select-sm" id="<?= e($fid) ?>_c" name="copy_from">
        <option value="0">—</option>
        <?php foreach ($candidates as $k): ?>
          <option value="<?= (int) $k['id'] ?>"><?= e($k['customer'] . ' · ' . $k['title'] . ' (' . $k['type'] . ') · ' . mb_strimwidth((string) $k['url'], 0, 60, '…')) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text"><?= e(__('Used when the link field above is empty.')) ?></div></div>
    <?php endif; ?>
    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="auto" value="1" id="<?= e($fid) ?>_a"<?= !empty($c['auto']) ? ' checked' : '' ?>>
      <label class="form-check-label" for="<?= e($fid) ?>_a"><strong><?= e(__('Add to every customer automatically')) ?></strong></label>
      <div class="form-text"><?= e(__('Existing and new customers get it in their content library, ready to put on any screen. Off: customers add it themselves from Templates → Live darshan.')) ?></div></div>
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="mute" value="1" id="<?= e($fid) ?>_m"<?= !empty($c['mute']) ? ' checked' : '' ?>>
      <label class="form-check-label" for="<?= e($fid) ?>_m"><?= e(__('Muted')) ?></label></div>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save and update all customers')) ?></button>
  </form>
    <?php return (string) ob_get_clean();
}

$pageTitle = __('Live darshan');
$activeNav = 'platform_live';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-broadcast"></i> <?= e(__('Live darshan')) ?></h1>
    <p class="lead-sm"><?= e(__('Set up a live stream once for every customer. Change the link here and every customer\'s TVs switch to it automatically.')) ?></p></div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-collection-play"></i> <?= e(__('Live channels')) ?></div>
      <?php if (!$channels): ?>
        <div class="card-body"><?= panel_empty('bi-broadcast', __('No live channels yet.'), __('Add the first one with the form.')) ?></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-hc table-sm align-middle mb-0" id="pliveList">
        <thead><tr><th><?= e(__('Channel')) ?></th><th><?= e(__('Customers')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($channels as $c): $n = count(LiveChannels::holders($c['id'])); ?>
          <tr data-channel="<?= e($c['id']) ?>">
            <td><strong><?= e($c['title']) ?></strong>
              <?php if ($c['subtitle'] !== ''): ?><div class="small text-muted"><?= e($c['subtitle']) ?></div><?php endif; ?>
              <div class="small"><span class="badge text-bg-light"><?= e($c['type'] === 'youtube' ? 'YouTube' : __('Live stream')) ?></span>
                <?php if (!empty($c['auto'])): ?><span class="badge text-bg-success"><?= e(__('All customers')) ?></span><?php else: ?><span class="badge text-bg-secondary"><?= e(__('On request')) ?></span><?php endif; ?>
                <?php if (trim($c['url']) === ''): ?><span class="badge text-bg-warning"><?= e(__('Link missing')) ?></span>
                <?php else: ?><code class="small text-break"><?= e(mb_strimwidth($c['url'], 0, 70, '…')) ?></code><?php endif; ?></div></td>
            <td><span class="num"><?= $n ?></span> / <?= $customers ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(admin_url('platform_live.php', ['edit' => $c['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
              <form method="post" class="d-inline" data-confirm="<?= e(__('Remove ":t" from the library? Customers keep their copy, but it is no longer updated.', ['t' => $c['title']])) ?>">
                <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
                <button class="btn btn-sm btn-outline-danger" aria-label="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i> <?= e(__('Customers see the channel as a normal item in Content and choose the screens themselves. A name a customer changed is kept when you update the link.')) ?></p>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil' : 'bi-plus-lg' ?>"></i> <?= e($editing ? __('Edit ":t"', ['t' => $editing['title']]) : __('Add live channel')) ?></div>
      <div class="card-body">
        <?= plive_form($editing ?? (isset($channels[LiveChannels::DWARKADHISH]) && trim($channels[LiveChannels::DWARKADHISH]['url']) === '' && !$edit ? $channels[LiveChannels::DWARKADHISH] : null), $candidates) ?>
        <?php if ($editing): ?><a class="btn btn-link px-0 mt-2" href="<?= e(admin_url('platform_live.php')) ?>"><?= e(__('Add a new channel instead')) ?></a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
