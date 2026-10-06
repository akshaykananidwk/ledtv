<?php
/**
 * Advertiser portal: my ads (creatives) — upload an image / short video (validated, stored per advertiser)
 * or write a text announcement; preview on a TV-simulator frame; delete unused ones.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
$adv = MarketplacePortal::require();
Csrf::check();
$aid = (int) $adv['id'];
$back = MarketplacePortal::url('creatives.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string) ($_POST['op'] ?? '');
    $next = MarketplacePortal::safeNext((string) ($_POST['next'] ?? ''));
    try {
        if ($op === 'upload') {
            MarketplacePortal::throttle('upload:' . $aid, 30, 3600, $back);
            $id = Marketplace::uploadCreative($aid, $_FILES['file'] ?? [], (string) ($_POST['title'] ?? ''), (int) ($_POST['duration'] ?? 15));
            MarketplacePortal::flash('success', __('Ad saved.'));
            redirect(str_contains($next, 'book.php') ? $next . (str_contains($next, '?') ? '&' : '?') . 'creative=' . $id : $back);
        }
        if ($op === 'text') {
            $id = Marketplace::createTextCreative($aid, $_POST);
            MarketplacePortal::flash('success', __('Ad saved.'));
            redirect(str_contains($next, 'book.php') ? $next . (str_contains($next, '?') ? '&' : '?') . 'creative=' . $id : $back);
        }
        if ($op === 'delete') {
            $ok = Marketplace::deleteCreative($aid, (int) ($_POST['id'] ?? 0));
            MarketplacePortal::flash($ok ? 'success' : 'warning', $ok ? __('Ad deleted.') : __('This ad is used in an order and cannot be deleted.'));
        }
    } catch (RuntimeException $e) {
        MarketplacePortal::flash('danger', $e->getMessage());
    }
    redirect($back);
}

$list = Marketplace::creatives($aid);
$maxImg = (int) Marketplace::setting('platform_mkt_max_image_mb');
$maxVid = (int) Marketplace::setting('platform_mkt_max_video_mb');
$next = (string) ($_GET['next'] ?? '');
MarketplacePortal::header(__('My ads'), 'creatives');
?>
<h1 class="h4"><?= e(__('My ads')) ?></h1>
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <form method="post" enctype="multipart/form-data" class="card card-body gap-2">
      <h2 class="h6 mb-0"><i class="bi bi-image"></i> <?= e(__('Picture or short video')) ?></h2>
      <?= Csrf::field() ?><input type="hidden" name="op" value="upload"><input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="form-label mb-0" for="u_title"><?= e(__('Title')) ?></label>
      <input class="form-control" id="u_title" name="title" maxlength="150" required>
      <label class="form-label mb-0" for="u_file"><?= e(__('File')) ?></label>
      <input class="form-control" id="u_file" type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" required data-preview="#u_prev">
      <div class="form-text"><?= e(__('Landscape 16:9 works best (1920 × 1080). Images up to :i MB, short videos (MP4 / WEBM, 10–30 seconds) up to :v MB.', ['i' => $maxImg, 'v' => $maxVid])) ?></div>
      <label class="form-label mb-0" for="u_dur"><?= e(__('Seconds on screen (pictures)')) ?></label>
      <input class="form-control" id="u_dur" type="number" name="duration" min="5" max="60" value="15">
      <div id="u_prev" class="tv-sim d-none"><div class="tv-screen"></div><div class="tv-stand"></div></div>
      <button class="btn btn-primary"><i class="bi bi-upload"></i> <?= e(__('Upload')) ?></button>
    </form>
  </div>
  <div class="col-md-6">
    <form method="post" class="card card-body gap-2" data-text-preview>
      <h2 class="h6 mb-0"><i class="bi bi-megaphone"></i> <?= e(__('Text announcement')) ?></h2>
      <?= Csrf::field() ?><input type="hidden" name="op" value="text"><input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="form-label mb-0" for="t_title"><?= e(__('Title')) ?></label>
      <input class="form-control" id="t_title" name="title" maxlength="150" required>
      <label class="form-label mb-0" for="t_text"><?= e(__('Text on the TV')) ?></label>
      <textarea class="form-control" id="t_text" name="text" maxlength="200" rows="2" required placeholder="<?= e(__('e.g. Krishna Sweets — fresh kaju katli, 10% off for hotel guests')) ?>"></textarea>
      <label class="form-label mb-0" for="t_sub"><?= e(__('Second line (address, phone)')) ?></label>
      <input class="form-control" id="t_sub" name="subtitle" maxlength="120">
      <div class="row g-2">
        <div class="col-4"><label class="form-label mb-0" for="t_bg"><?= e(__('Background')) ?></label><input class="form-control form-control-color w-100" id="t_bg" type="color" name="bg_color" value="#1A237E"></div>
        <div class="col-4"><label class="form-label mb-0" for="t_fg"><?= e(__('Text colour')) ?></label><input class="form-control form-control-color w-100" id="t_fg" type="color" name="text_color" value="#FFFFFF"></div>
        <div class="col-4"><label class="form-label mb-0" for="t_dur"><?= e(__('Seconds')) ?></label><input class="form-control" id="t_dur" type="number" name="duration" min="5" max="60" value="15"></div>
      </div>
      <div class="tv-sim"><div class="tv-screen"><div class="tv-text" data-tv-text><strong></strong><span></span></div><div class="tv-badge"><?= e(__('Ad')) ?></div></div><div class="tv-stand"></div></div>
      <div class="form-text"><?= e(__('No web links or website addresses — the TV cannot open them.')) ?></div>
      <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
    </form>
  </div>
</div>
<h2 class="h5"><?= e(__('Saved ads')) ?></h2>
<?php if (!$list): ?><p class="text-muted"><?= e(__('No ads yet.')) ?></p><?php endif; ?>
<div class="row g-3">
<?php foreach ($list as $c): ?>
  <div class="col-sm-6 col-lg-4"><div class="mkt-card h-100">
    <?= MarketplacePortal::tvFrame($c) ?>
    <div class="d-flex justify-content-between gap-2 mt-2"><strong><?= e($c['title']) ?></strong>
      <?= $c['status'] === 'rejected' ? '<span class="badge text-bg-danger">' . e(__('Rejected')) . '</span>' : '' ?></div>
    <?php if ($c['status'] === 'rejected' && $c['reject_reason']): ?><div class="small text-danger"><?= e($c['reject_reason']) ?></div><?php endif; ?>
    <div class="small text-muted"><?= e(match ($c['type']) { 'image' => __('Picture'), 'video' => __('Video'), default => __('Text') }) ?><?= $c['file_size'] ? ' · ' . e(human_bytes((int) $c['file_size'])) : '' ?></div>
    <form method="post" class="mt-2" data-confirm="<?= e(__('Delete this ad?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> <?= e(__('Delete')) ?></button></form>
  </div></div>
<?php endforeach; ?>
</div>
<details class="mt-4 small"><summary><?= e(__('Ad rules')) ?></summary><div class="mkt-rules mt-2"><?= nl2br(e(Marketplace::setting('platform_mkt_rules'))) ?></div></details>
<?php
MarketplacePortal::footer();
