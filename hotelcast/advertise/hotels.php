<?php
/** Advertiser portal: public list of hotels that sell TV ad space (public fields only). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();

$adv = MarketplacePortal::advertiser();
$filter = [
    'city' => mb_substr(trim((string) ($_GET['city'] ?? '')), 0, 80),
    'category' => in_array($_GET['category'] ?? '', Marketplace::categories(), true) ? (string) $_GET['category'] : '',
    'model' => in_array($_GET['model'] ?? '', Marketplace::MODELS, true) ? (string) $_GET['model'] : '',
];
$hotels = Marketplace::enabled() ? Marketplace::listHotels($filter) : [];
MarketplacePortal::header(__('Hotels'), 'hotels');
?>
<h1 class="h4"><?= e(__('Hotels selling TV ad space')) ?></h1>
<form class="mkt-filter row g-2 mb-3" method="get">
  <div class="col-12 col-sm-4"><input class="form-control" name="city" value="<?= e($filter['city']) ?>" placeholder="<?= e(__('City')) ?>" aria-label="<?= e(__('City')) ?>"></div>
  <div class="col-6 col-sm-3"><select class="form-select" name="category" aria-label="<?= e(__('Category')) ?>">
    <option value=""><?= e(__('All categories')) ?></option>
    <?php foreach (Marketplace::categories() as $c): ?><option value="<?= e($c) ?>"<?= $filter['category'] === $c ? ' selected' : '' ?>><?= e(Marketplace::categoryLabel($c)) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-6 col-sm-3"><select class="form-select" name="model" aria-label="<?= e(__('Price model')) ?>">
    <option value=""><?= e(__('Any price model')) ?></option>
    <?php foreach (Marketplace::MODELS as $m): ?><option value="<?= e($m) ?>"<?= $filter['model'] === $m ? ' selected' : '' ?>><?= e(Marketplace::modelLabel($m)) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-12 col-sm-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i> <?= e(__('Filter')) ?></button></div>
</form>
<?php if (!$hotels): ?>
  <p class="text-muted"><?= e(__('No hotels found.')) ?></p>
<?php endif; ?>
<div class="mkt-list">
<?php foreach ($hotels as $h): ?>
  <div class="mkt-card" data-hotel="<?= (int) $h['id'] ?>">
    <div class="d-flex justify-content-between gap-2">
      <div><strong><?= e($h['name']) ?></strong><div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($h['city'] ?: '—') ?></div></div>
      <?php if ($h['approval'] === 'auto'): ?><span class="badge text-bg-success align-self-start"><?= e(__('Instant approval')) ?></span><?php endif; ?>
    </div>
    <div class="mkt-facts">
      <span><i class="bi bi-tv"></i> <?= (int) $h['tv_count'] ?> <?= e(__('TVs')) ?></span>
      <span><i class="bi bi-door-closed"></i> <?= (int) $h['rooms'] ?> <?= e(__('rooms')) ?></span>
      <span><i class="bi bi-eye"></i> <?= $h['est_daily_impressions'] !== null ? e(__('~:n plays / day', ['n' => number_format((int) $h['est_daily_impressions'])])) : e(__('new')) ?></span>
    </div>
    <div class="mkt-price">
      <?php if ($h['price_per_tv_day'] !== null): ?><span><b><?= e(Marketplace::money($h['price_per_tv_day'])) ?></b> / <?= e(__('TV / day')) ?></span><?php endif; ?>
      <?php if ($h['price_cpm'] !== null): ?><span><b><?= e(Marketplace::money($h['price_cpm'])) ?></b> / <?= e(__('1000 plays')) ?></span><?php endif; ?>
    </div>
    <?php if ($h['allowed_categories'] || $h['blocked_categories']): ?>
    <div class="small text-muted">
      <?php if ($h['allowed_categories']): ?><?= e(__('Only')) ?>: <?= e(implode(', ', array_map([Marketplace::class, 'categoryLabel'], $h['allowed_categories']))) ?><?php endif; ?>
      <?php if ($h['blocked_categories']): ?><?= e(__('Not accepted')) ?>: <?= e(implode(', ', array_map([Marketplace::class, 'categoryLabel'], $h['blocked_categories']))) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($h['description'] !== ''): ?><p class="small mb-1"><?= e($h['description']) ?></p><?php endif; ?>
    <?php if ($adv && $adv['status'] === 'active'): ?>
      <a class="btn btn-sm btn-primary" href="<?= e(MarketplacePortal::url('book.php', ['hotel' => $h['id']])) ?>"><?= e(__('Book here')) ?></a>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$adv): ?>
  <p class="text-center mt-3"><a class="btn btn-warning" href="<?= e(MarketplacePortal::url('signup.php')) ?>"><?= e(__('Create a free account')) ?></a></p>
<?php endif; ?>
<?php
MarketplacePortal::footer();
