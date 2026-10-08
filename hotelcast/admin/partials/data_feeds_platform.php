<?php
/**
 * Platform settings → "Data feeds" tab (included by admin/platform_settings.php): API keys of the keyed
 * providers (stored encrypted; the page never shows them, only "saved"), TTL / daily caps, the request
 * budget, the currency provider and the index symbols. Saved by DataFeeds::savePlatformSettings().
 */
declare(strict_types=1);

$feedP = DataFeeds::PROVIDERS;
$curP = DataFeeds::currencyProvider();
?>
<form method="post" class="card" style="max-width:980px" autocomplete="off"><div class="card-body row g-3">
  <?= Csrf::field() ?><input type="hidden" name="op" value="data_feeds"><input type="hidden" name="tab" value="data_feeds">
  <div class="col-12"><p class="text-muted mb-0"><?= e(__('External data for the gold, market, cricket, currency and flight widgets and for ticker placeholders. Keys are stored encrypted and used only by the server; TVs never see them. Customers may also enter their own key (Data feeds page). Every widget also works with values typed by the customer.')) ?></p></div>
  <div class="col-md-6">
    <label class="form-label" for="df_cur"><?= e(__('Currency rates provider (free, no key)')) ?></label>
    <select class="form-select" id="df_cur" name="feed_currency_provider">
      <?php foreach (['open_er_api', 'frankfurter'] as $p): ?><option value="<?= e($p) ?>"<?= $curP === $p ? ' selected' : '' ?>><?= e($feedP[$p]['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label" for="df_pm"><?= e(__('Requests per minute (all providers)')) ?></label>
    <input class="form-control" type="number" min="1" max="600" id="df_pm" name="feeds_per_min" value="<?= DataFeeds::perMinute() ?>">
    <div class="form-text"><?= e(__('The background task never sends more requests than this.')) ?></div>
  </div>
  <?php foreach ($feedP as $p => $def): if (!$def['key']) { continue; } $has = DataFeeds::platformKey($p) !== ''; ?>
  <div class="col-12"><div class="border rounded p-3 row g-2">
    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center">
      <strong><?= e($def['name']) ?></strong>
      <span class="small"><?= $has ? '<span class="badge text-bg-success">' . e(__('Key saved')) . '</span>' : '<span class="badge text-bg-secondary">' . e(__('No key')) . '</span>' ?>
        · <a href="<?= e($def['signup']) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('Get a key')) ?></a></span>
    </div>
    <div class="col-md-6">
      <label class="form-label small" for="df_k_<?= e($p) ?>"><?= e(__('API key')) ?></label>
      <input class="form-control" type="password" id="df_k_<?= e($p) ?>" name="feedkey_<?= e($p) ?>" value="" autocomplete="new-password" placeholder="<?= e($has ? __('Saved — leave empty to keep') : __('Paste the key')) ?>">
      <?php if ($has): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="df_c_<?= e($p) ?>" name="feedkey_clear_<?= e($p) ?>" value="1"><label class="form-check-label small" for="df_c_<?= e($p) ?>"><?= e(__('Remove key')) ?></label></div><?php endif; ?>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small" for="df_t_<?= e($p) ?>"><?= e(__('Refresh every (seconds)')) ?></label>
      <input class="form-control" type="number" min="<?= (int) $def['min_ttl'] ?>" id="df_t_<?= e($p) ?>" name="feed_ttl_<?= e($p) ?>" value="<?= e((string) Settings::platform('platform_feed_ttl_' . $p, (string) $def['ttl'])) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small" for="df_cap_<?= e($p) ?>"><?= e(__('Requests per day (0 = no limit)')) ?></label>
      <input class="form-control" type="number" min="0" id="df_cap_<?= e($p) ?>" name="feed_cap_<?= e($p) ?>" value="<?= DataFeeds::dailyCap($p) ?>">
    </div>
    <div class="col-12 form-text mt-0"><?= e(__('Effective refresh: every :n s (stretched to stay within the daily limit). Check the provider\'s current terms and free-tier limits.', ['n' => DataFeeds::ttl($p)])) ?></div>
    <?php if ($p === 'twelvedata'): ?>
      <?php foreach (DataFeeds::INDICES as $k => [$label]): ?>
      <div class="col-4"><label class="form-label small" for="df_i_<?= e($k) ?>"><?= e(__(':i symbol', ['i' => $label])) ?></label>
        <input class="form-control" id="df_i_<?= e($k) ?>" name="feed_idx_<?= e($k) ?>" maxlength="25" value="<?= e(DataFeeds::indexSymbol($k)) ?>"></div>
      <?php endforeach; ?>
    <?php elseif ($p === 'aviationstack'): ?>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="df_avh" name="feed_aviationstack_http" value="1"<?= Settings::platform('platform_feed_aviationstack_http', '0') === '1' ? ' checked' : '' ?>>
        <label class="form-check-label small" for="df_avh"><?= e(__('Use plain HTTP (some free plans have no HTTPS; the key is then sent unencrypted)')) ?></label></div></div>
    <?php endif; ?>
  </div></div>
  <?php endforeach; ?>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save settings')) ?></button></div>
</div></form>
