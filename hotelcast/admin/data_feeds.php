<?php
declare(strict_types=1);
/**
 * Data feeds of a hotel (2.3): which external feeds are available (platform key / the hotel's own key),
 * the hotel's optional own API keys (encrypted, never shown again), and the state of the shared feeds
 * (as of, last error) with a "Refresh now" button (within the request budget). Super admin
 * (settings.manage). See docs/modules/data_feeds.md.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('settings.manage');
Csrf::check();

$P = DataFeeds::PROVIDERS;
if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $p = req_str('provider', $_POST, 30);
    if (!isset($P[$p])) {
        flash('warning', __('Unknown provider.'));
        redirect(admin_url('data_feeds.php'));
    }
    switch ($op) {
        case 'key':
            if (!$P[$p]['key']) {
                break;
            }
            if (!empty($_POST['clear'])) {
                DataFeeds::setHotelKey($p, '');
                ActivityLog::add('feed_key', 'settings', null, $p . ' removed');
                flash('success', __('Your own key was removed; the platform key is used.'));
                break;
            }
            $key = is_string($_POST['api_key'] ?? null) ? trim($_POST['api_key']) : '';
            if (!DataFeeds::validKey($key)) {
                flash('danger', __(':p: the API key looks wrong (8–200 characters, no spaces).', ['p' => $P[$p]['name']]));
                break;
            }
            DataFeeds::setHotelKey($p, $key);
            ActivityLog::add('feed_key', 'settings', null, $p . ' set');
            flash('success', __('Key saved (encrypted).'));
            break;

        case 'refresh':
            $params = in_array($p, ['aviationstack', 'twelvedata', 'open_meteo_aq', 'open_meteo_wx', 'google_places'], true) ? null : [];
            if ($params === null || ($P[$p]['feed'] === 'currency' && $p !== DataFeeds::currencyProvider())) {
                break;
            }
            $st = DataFeeds::get($p, $params);
            if ($st['status'] === 'no_key') {
                flash('warning', __('No API key for :p.', ['p' => $P[$p]['name']]));
                break;
            }
            [, $owner] = DataFeeds::keyFor($p);
            $row = DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => DataFeeds::feedKey($p, DataFeeds::cleanParams($p, $params) ?? [], $owner)]);
            if ($row && $P[$p]['key'] && $owner === 0 && !DataFeeds::due($row)) {
                // Feed on the shared platform key (used by every hotel without its own key): only when its
                // TTL / back-off is over, so one hotel cannot spend the provider's daily cap of all hotels.
                flash('info', __('Up to date'));
                break;
            }
            $new = $row ? DataFeeds::refreshRow($row) : null;
            if (!$new || ($new['_result'] ?? '') === 'budget') {
                flash('warning', __('Request limit reached — try again in a minute.'));
            } elseif ($new['error'] !== null) {
                flash('danger', __('The provider answered: :e', ['e' => (string) $new['error']]));
            } else {
                flash('success', __('Updated.'));
            }
            break;
    }
    redirect(admin_url('data_feeds.php'));
}

$feedName = [
    'currency' => __('Currency rates'),
    'metals' => __('Gold & silver price'),
    'market' => __('Stock market'),
    'cricket' => __('Cricket scores'),
    'flights' => __('Flight status'),
    'air_quality' => __('Air quality'),
    'weather_alerts' => __('Weather warnings'),
    'reviews' => __('Google reviews'),
];
$activeNav = 'data_feeds';
$pageTitle = __('Data feeds');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-broadcast"></i> <?= e(__('Data feeds')) ?></h1><p class="lead-sm"><?= e(__('Live data for the gold, market, cricket, currency and flight widgets. Every widget also works with values you type yourself.')) ?></p></div>
  <a href="<?= e(admin_url('rates.php')) ?>" class="btn btn-light border"><i class="bi bi-gem"></i> <?= e(__('Rates')) ?></a>
</div>
<div class="row g-3">
<?php foreach ($P as $p => $def):
    if ($def['feed'] === 'currency' && $p !== DataFeeds::currencyProvider()) {
        continue;
    }
    $platformKey = $def['key'] && DataFeeds::platformKey($p) !== '';
    $ownKey = $def['key'] && DataFeeds::hotelKey($p, Tenant::id()) !== '';
    $st = in_array($p, ['open_er_api', 'frankfurter', 'goldapi', 'cricapi'], true) && DataFeeds::hasKey($p) ? DataFeeds::get($p) : null;
?>
  <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <div><div class="fw-semibold"><?= e($feedName[$def['feed']] ?? $def['feed']) ?></div><div class="small text-muted"><?= e($def['name']) ?></div></div>
      <?php if (!$def['key']): ?><span class="badge text-bg-success"><?= e(__('Free, no key')) ?></span>
      <?php elseif ($ownKey): ?><span class="badge text-bg-primary"><?= e(__('Your own key')) ?></span>
      <?php elseif ($platformKey): ?><span class="badge text-bg-success"><?= e(__('Platform key')) ?></span>
      <?php else: ?><span class="badge text-bg-secondary"><?= e(__('No key — manual values only')) ?></span><?php endif; ?>
    </div>
    <?php if ($st !== null): ?>
      <div class="small mb-2"><?= e(__('Status')) ?>: <strong><?= e(match ($st['status']) { 'ok' => __('Up to date'), 'stale' => __('Last known value'), 'error' => __('Error'), default => __('Waiting for data…') }) ?></strong>
        <?php if ($st['as_of']): ?> · <?= e(__('Updated :t', ['t' => DataFeeds::asOf($st['as_of'])])) ?><?php endif; ?>
        <?php if ($st['error']): ?><div class="text-danger text-break"><?= e($st['error']) ?></div><?php endif; ?></div>
      <form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="op" value="refresh"><input type="hidden" name="provider" value="<?= e($p) ?>">
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> <?= e(__('Refresh now')) ?></button></form>
    <?php endif; ?>
    <?php if ($def['key']): ?>
      <form method="post" class="row g-2" autocomplete="off"><?= Csrf::field() ?><input type="hidden" name="op" value="key"><input type="hidden" name="provider" value="<?= e($p) ?>">
        <div class="col-12"><label class="form-label small mb-0" for="k_<?= e($p) ?>"><?= e(__('Your own API key (optional)')) ?></label>
          <input class="form-control form-control-sm" type="password" id="k_<?= e($p) ?>" name="api_key" autocomplete="new-password" placeholder="<?= e($ownKey ? __('Saved — enter a new key to replace it') : __('Paste the key')) ?>"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary"><?= e(__('Save key')) ?></button>
          <?php if ($ownKey): ?><button class="btn btn-sm btn-outline-danger" name="clear" value="1"><?= e(__('Remove')) ?></button><?php endif; ?>
          <a class="btn btn-sm btn-link" href="<?= e($def['signup']) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('Get a key')) ?></a></div>
      </form>
    <?php endif; ?>
  </div></div></div>
<?php endforeach; ?>
</div>
<div class="card mt-3"><div class="card-body small">
  <strong><?= e(__('Ticker placeholders')) ?>:</strong> <code><?= e('{' . implode('} {', DataFeeds::PLACEHOLDERS) . '}') ?></code>
  <div class="text-muted"><?= e(__('Write them in a ticker message; TVs show the current value. Trains have no official live API — use manual rows in the Train & flight status app.')) ?></div>
</div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
