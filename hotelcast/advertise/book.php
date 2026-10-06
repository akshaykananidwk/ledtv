<?php
/**
 * Advertiser portal: book an ad — hotels, dates, daily time window, price model, ad (creative), live
 * quote (server-side, ajax.php?action=quote) → save draft or submit the order.
 *   book.php [?id=draft] [&hotel=N] [&creative=N]
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
$adv = MarketplacePortal::require();
Csrf::check();
$aid = (int) $adv['id'];

$id = (int) ($_REQUEST['id'] ?? 0);
$draft = $id ? Marketplace::booking($aid, $id) : null;
if ($id && (!$draft || $draft['status'] !== 'draft')) {
    redirect(MarketplacePortal::url($draft ? 'booking.php' : 'index.php', $draft ? ['id' => $id] : []));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    MarketplacePortal::throttle('book:' . $aid, 60, 3600, MarketplacePortal::url('book.php', $id ? ['id' => $id] : []));
    [$data, $hotelIds, $errors] = Marketplace::validateBooking($aid, $_POST);
    if ($errors) {
        $_SESSION['mkt_book_form'] = $_POST;
        MarketplacePortal::flash('danger', implode("\n", $errors));
        redirect(MarketplacePortal::url('book.php', $id ? ['id' => $id] : []));
    }
    try {
        $bid = Marketplace::saveDraft($aid, $data, $hotelIds, $id ?: null);
        if (($_POST['op'] ?? '') === 'submit') {
            Marketplace::submit($aid, $bid);
            MarketplacePortal::flash('success', __('Order submitted.'));
        } else {
            MarketplacePortal::flash('success', __('Draft saved.'));
        }
        redirect(MarketplacePortal::url('booking.php', ['id' => $bid]));
    } catch (RuntimeException $e) {
        MarketplacePortal::flash('danger', $e->getMessage());
        redirect(MarketplacePortal::url('booking.php', ['id' => $bid ?? $id]));
    }
}

$creatives = Marketplace::creatives($aid, true);
$hotels = Marketplace::listHotels();
$f = $_SESSION['mkt_book_form'] ?? null;
unset($_SESSION['mkt_book_form']);
if (!is_array($f)) {
    $f = $draft ? [
        'title' => $draft['title'], 'category' => $draft['category'], 'pricing_model' => $draft['pricing_model'], 'start_date' => $draft['start_date'],
        'end_date' => $draft['end_date'], 'daily_start' => $draft['daily_start'] ? substr((string) $draft['daily_start'], 0, 5) : '',
        'daily_end' => $draft['daily_end'] ? substr((string) $draft['daily_end'], 0, 5) : '', 'impressions' => $draft['impressions'] ?? 5000,
        'creative_id' => $draft['creative_id'], 'hotel_ids' => array_column(Marketplace::lines($id), 'hotel_id'),
    ] : [
        'title' => '', 'category' => '', 'pricing_model' => 'per_day', 'start_date' => date('Y-m-d', strtotime('+1 day')), 'end_date' => date('Y-m-d', strtotime('+7 days')),
        'daily_start' => '', 'daily_end' => '', 'impressions' => 5000, 'creative_id' => (int) ($_GET['creative'] ?? 0), 'hotel_ids' => array_filter([(int) ($_GET['hotel'] ?? 0)]),
    ];
}
if (!empty($_GET['creative'])) {
    $f['creative_id'] = (int) $_GET['creative'];
}
$sel = array_map('intval', (array) ($f['hotel_ids'] ?? []));
MarketplacePortal::header($draft ? __('Edit draft') : __('Book an ad'), 'book');
?>
<h1 class="h4"><?= e($draft ? __('Edit draft') : __('Book an ad')) ?></h1>
<form method="post" id="mktBook" data-quote-url="<?= e(MarketplacePortal::url('ajax.php', ['action' => 'quote'])) ?>">
  <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">

  <section class="card card-body mb-3 gap-2">
    <h2 class="h6 mb-0">1. <?= e(__('Your ad')) ?></h2>
    <?php if (!$creatives): ?>
      <div class="alert alert-info mb-0"><?= e(__('Upload a picture or write a text announcement first.')) ?>
        <a href="<?= e(MarketplacePortal::url('creatives.php', ['next' => $_SERVER['REQUEST_URI'] ?? ''])) ?>"><?= e(__('Create an ad')) ?></a></div>
    <?php else: ?>
    <div class="mkt-creatives">
      <?php foreach ($creatives as $c): ?>
        <label class="mkt-creative"><input type="radio" name="creative_id" value="<?= (int) $c['id'] ?>"<?= (int) ($f['creative_id'] ?? 0) === (int) $c['id'] ? ' checked' : '' ?> required>
          <?= MarketplacePortal::tvFrame($c) ?><span><?= e($c['title']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <a class="small" href="<?= e(MarketplacePortal::url('creatives.php', ['next' => $_SERVER['REQUEST_URI'] ?? ''])) ?>"><i class="bi bi-plus"></i> <?= e(__('Create another ad')) ?></a>
    <?php endif; ?>
    <div class="row g-2">
      <div class="col-sm-7"><label class="form-label" for="b_title"><?= e(__('Order title')) ?></label>
        <input class="form-control" id="b_title" name="title" maxlength="150" required value="<?= e((string) ($f['title'] ?? '')) ?>" placeholder="<?= e(__('e.g. Diwali offer')) ?>"></div>
      <div class="col-sm-5"><label class="form-label" for="b_cat"><?= e(__('Category')) ?></label>
        <select class="form-select" id="b_cat" name="category" required>
          <option value=""><?= e(__('Choose…')) ?></option>
          <?php foreach (Marketplace::categories() as $c): ?><option value="<?= e($c) ?>"<?= ($f['category'] ?? '') === $c ? ' selected' : '' ?>><?= e(Marketplace::categoryLabel($c)) ?></option><?php endforeach; ?>
        </select></div>
    </div>
  </section>

  <section class="card card-body mb-3 gap-2">
    <h2 class="h6 mb-0">2. <?= e(__('When')) ?></h2>
    <div class="row g-2">
      <div class="col-6"><label class="form-label" for="b_sd"><?= e(__('Start date')) ?></label>
        <input class="form-control" id="b_sd" type="date" name="start_date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e((string) ($f['start_date'] ?? '')) ?>"></div>
      <div class="col-6"><label class="form-label" for="b_ed"><?= e(__('End date')) ?></label>
        <input class="form-control" id="b_ed" type="date" name="end_date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e((string) ($f['end_date'] ?? '')) ?>"></div>
      <div class="col-6"><label class="form-label" for="b_ds"><?= e(__('Daily from')) ?></label>
        <input class="form-control" id="b_ds" type="time" name="daily_start" value="<?= e((string) ($f['daily_start'] ?? '')) ?>"></div>
      <div class="col-6"><label class="form-label" for="b_de"><?= e(__('Daily until')) ?></label>
        <input class="form-control" id="b_de" type="time" name="daily_end" value="<?= e((string) ($f['daily_end'] ?? '')) ?>"></div>
      <div class="col-12 form-text mt-0"><?= e(__('Leave the daily times empty to show the ad all day.')) ?></div>
    </div>
  </section>

  <section class="card card-body mb-3 gap-2">
    <h2 class="h6 mb-0">3. <?= e(__('Price model')) ?></h2>
    <div class="d-flex flex-wrap gap-3">
      <?php foreach (Marketplace::MODELS as $m): ?>
      <div class="form-check"><input class="form-check-input" type="radio" name="pricing_model" id="pm_<?= e($m) ?>" value="<?= e($m) ?>"<?= ($f['pricing_model'] ?? 'per_day') === $m ? ' checked' : '' ?>>
        <label class="form-check-label" for="pm_<?= e($m) ?>"><?= e(Marketplace::modelLabel($m)) ?></label></div>
      <?php endforeach; ?>
    </div>
    <div data-cpm-only><label class="form-label" for="b_imp"><?= e(__('Impressions per hotel')) ?></label>
      <input class="form-control" id="b_imp" type="number" name="impressions" min="1000" step="1000" max="10000000" value="<?= (int) ($f['impressions'] ?? 5000) ?>">
      <div class="form-text"><?= e(__('The ad stops in a hotel when it was shown this many times (or at the end date).')) ?></div></div>
  </section>

  <section class="card card-body mb-3 gap-2">
    <h2 class="h6 mb-0">4. <?= e(__('Hotels')) ?></h2>
    <?php if (!$hotels): ?><p class="text-muted mb-0"><?= e(__('No hotels found.')) ?></p><?php endif; ?>
    <div class="mkt-hotel-pick">
    <?php foreach ($hotels as $h): ?>
      <label class="mkt-card mkt-pick" data-models="<?= e($h['pricing_model']) ?>">
        <input class="form-check-input" type="checkbox" name="hotel_ids[]" value="<?= (int) $h['id'] ?>"<?= in_array((int) $h['id'], $sel, true) ? ' checked' : '' ?>>
        <span><strong><?= e($h['name']) ?></strong> <small class="text-muted"><?= e($h['city']) ?></small><br>
          <small><?= (int) $h['tv_count'] ?> <?= e(__('TVs')) ?>
          <?php if ($h['price_per_tv_day'] !== null): ?> · <?= e(Marketplace::money($h['price_per_tv_day'])) ?>/<?= e(__('TV / day')) ?><?php endif; ?>
          <?php if ($h['price_cpm'] !== null): ?> · <?= e(Marketplace::money($h['price_cpm'])) ?>/<?= e(__('1000 plays')) ?><?php endif; ?></small></span>
      </label>
    <?php endforeach; ?>
    </div>
  </section>

  <section class="card card-body mb-3 mkt-quote" aria-live="polite">
    <h2 class="h6"><?= e(__('Price')) ?></h2>
    <div data-quote><span class="text-muted"><?= e(__('Choose hotels and dates to see the price.')) ?></span></div>
  </section>
  <div class="d-grid gap-2 d-sm-flex mb-4">
    <button class="btn btn-primary btn-lg" name="op" value="submit"><i class="bi bi-send"></i> <?= e(__('Submit order')) ?></button>
    <button class="btn btn-outline-secondary btn-lg" name="op" value="draft" formnovalidate><?= e(__('Save draft')) ?></button>
  </div>
</form>
<?php
MarketplacePortal::footer();
