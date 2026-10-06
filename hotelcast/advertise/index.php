<?php
/**
 * Advertiser portal (#19): landing page (logged out) / dashboard (logged in).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();

$adv = MarketplacePortal::advertiser();
if ($adv && $adv['status'] === 'unverified') {
    redirect(MarketplacePortal::url('verify.php'));
}
if (!Marketplace::enabled() && !$adv) {
    http_response_code(503);
}

if (!$adv) {
    MarketplacePortal::header(__('Advertise on hotel TVs'));
    $hotels = Marketplace::enabled() ? Marketplace::listHotels() : [];
    ?>
    <section class="mkt-hero">
      <h1><?= e(__('Advertise on hotel TVs')) ?></h1>
      <p><?= e(__('Show your shop, restaurant or service on the TVs in hotel rooms near you. Pick hotels and dates, upload your ad, pay — the hotel does the rest.')) ?></p>
      <?php if (Marketplace::enabled()): ?>
      <div class="d-grid gap-2 d-sm-flex">
        <a class="btn btn-warning btn-lg" href="<?= e(MarketplacePortal::url('signup.php')) ?>"><?= e(__('Create a free account')) ?></a>
        <a class="btn btn-outline-light btn-lg" href="<?= e(MarketplacePortal::url('login.php')) ?>"><?= e(__('Log in')) ?></a>
      </div>
      <?php else: ?>
      <div class="alert alert-warning mb-0"><?= e(__('The ad marketplace is closed at the moment.')) ?></div>
      <?php endif; ?>
    </section>
    <div class="row g-3 mb-3 text-center">
      <div class="col-4"><div class="mkt-stat"><b><?= count($hotels) ?></b><span><?= e(__('Hotels')) ?></span></div></div>
      <div class="col-4"><div class="mkt-stat"><b><?= array_sum(array_column($hotels, 'tv_count')) ?></b><span><?= e(__('TVs')) ?></span></div></div>
      <div class="col-4"><div class="mkt-stat"><b><?= count(array_unique(array_filter(array_column($hotels, 'city')))) ?></b><span><?= e(__('Cities')) ?></span></div></div>
    </div>
    <ol class="mkt-steps">
      <li><?= e(__('Create an account and verify your email.')) ?></li>
      <li><?= e(__('Upload your ad: a picture, a short video or a text announcement.')) ?></li>
      <li><?= e(__('Choose hotels and dates and see the price at once.')) ?></li>
      <li><?= e(__('Pay by bank transfer or UPI. Track every play in your report.')) ?></li>
    </ol>
    <p class="text-center"><a href="<?= e(MarketplacePortal::url('hotels.php')) ?>"><?= e(__('See hotels and prices')) ?> →</a></p>
    <?php
    MarketplacePortal::footer();
    exit;
}

MarketplacePortal::header(__('Dashboard'), 'index');
if ($adv['status'] === 'pending'): ?>
  <div class="alert alert-info"><i class="bi bi-hourglass-split"></i> <?= e(__('Your account is waiting for approval. We will email you when you can book ads.')) ?></div>
<?php
    MarketplacePortal::footer();
    exit;
endif;
$bookings = Marketplace::bookings((int) $adv['id']);
$open = array_filter($bookings, fn ($b) => !in_array($b['status'], Marketplace::FINAL_STATUSES, true));
$spent = 0;
foreach ($bookings as $b) {
    if ($b['paid_at']) {
        $spent += Marketplace::paise($b['total']) - Marketplace::paise($b['refund_amount']);
    }
}
?>
<h1 class="h4 mb-3"><?= e(__('Hello, :n', ['n' => $adv['business_name']])) ?></h1>
<div class="row g-2 mb-3 text-center">
  <div class="col-4"><div class="mkt-stat"><b><?= count($open) ?></b><span><?= e(__('Open orders')) ?></span></div></div>
  <div class="col-4"><div class="mkt-stat"><b><?= count(array_filter($bookings, fn ($b) => $b['status'] === 'running')) ?></b><span><?= e(__('Running')) ?></span></div></div>
  <div class="col-4"><div class="mkt-stat"><b class="small"><?= e(Marketplace::money(Marketplace::fromPaise($spent))) ?></b><span><?= e(__('Spent')) ?></span></div></div>
</div>
<div class="d-grid gap-2 mb-3"><a class="btn btn-primary btn-lg" href="<?= e(MarketplacePortal::url('book.php')) ?>"><i class="bi bi-plus-circle"></i> <?= e(__('Book an ad')) ?></a></div>
<h2 class="h5"><?= e(__('My orders')) ?></h2>
<?php if (!$bookings): ?>
  <p class="text-muted"><?= e(__('No orders yet.')) ?></p>
<?php endif; ?>
<div class="mkt-list">
<?php foreach ($bookings as $b): ?>
  <a class="mkt-card" href="<?= e(MarketplacePortal::url('booking.php', ['id' => $b['id']])) ?>">
    <div class="d-flex justify-content-between gap-2"><strong><?= e($b['title']) ?></strong><?= Marketplace::statusBadge((string) $b['status']) ?></div>
    <div class="small text-muted"><?= e($b['number'] ?: __('Draft')) ?> · <?= e(date('d M', (int) strtotime((string) $b['start_date']))) ?> – <?= e(date('d M Y', (int) strtotime((string) $b['end_date']))) ?> · <?= e(__(':n hotels', ['n' => (int) $b['hotels']])) ?></div>
    <div class="fw-semibold"><?= e(Marketplace::money($b['total'])) ?></div>
  </a>
<?php endforeach; ?>
</div>
<?php
MarketplacePortal::footer();
