<?php
declare(strict_types=1);

/**
 * 2.5 plans & feature entitlements — shared admin UI pieces (core/Features.php):
 *   features_checkbox_groups()  grouped feature checkboxes with "all / none" per group (plan editor)
 *   features_override_fields()  per-customer add / remove overrides + limit overrides (customer form)
 *   features_summary_html()     effective features of a customer (customer form / detail page, "Your plan")
 * Only renders escaped HTML; permission checks stay in the pages.
 */

require_once __DIR__ . '/common.php';

if (defined('HC_FEATURES_UI')) {
    return;
}
define('HC_FEATURES_UI', true);

/** Grouped checkboxes name="$name[]" for every optional feature. */
function features_checkbox_groups(string $name, array $checked, string $idPrefix = 'ft'): string
{
    $all = Features::all();
    ob_start();
    ?>
    <div class="row g-3" data-feature-groups>
    <?php foreach (Features::grouped() as $group => $defs): ?>
      <div class="col-md-6 col-xl-4">
        <fieldset class="border rounded p-2 h-100" data-feature-group="<?= e($group) ?>">
          <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1"><?= e(__(Features::GROUPS[$group] ?? $group)) ?></legend>
          <div class="mb-1 small">
            <button type="button" class="btn btn-link btn-sm p-0 me-2" data-group-select="all"><?= e(__('Select all')) ?></button>
            <button type="button" class="btn btn-link btn-sm p-0" data-group-select="none"><?= e(__('Select none')) ?></button>
          </div>
          <?php foreach ($defs as $k => $d): $id = $idPrefix . '_' . $k; ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="<?= e($name) ?>[]" value="<?= e($k) ?>" id="<?= e($id) ?>"<?= in_array($k, $checked, true) ? ' checked' : '' ?>>
              <label class="form-check-label" for="<?= e($id) ?>"><?= e(__($d['label'])) ?>
                <?php if ($d['depends']): ?><span class="text-muted small">(<?= e(__('needs :f', ['f' => implode(', ', array_map(static fn ($x) => __($all[$x]['label'] ?? $x), $d['depends']))])) ?>)</span><?php endif; ?>
                <span class="d-block small text-muted"><?= e(__($d['description'])) ?></span></label>
            </div>
          <?php endforeach; ?>
        </fieldset>
      </div>
    <?php endforeach; ?>
    </div>
    <script>
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-group-select]');
      if (!b) { return; }
      var on = b.getAttribute('data-group-select') === 'all';
      b.closest('[data-feature-group]').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = on; });
    });
    </script>
    <?php
    return (string) ob_get_clean();
}

/** Override selects (plan / add / remove) per feature + limit overrides, for the customer form. */
function features_override_fields(array $h): string
{
    $o = Features::parseOverrides($h['feature_overrides'] ?? null);
    $planKeys = Features::planKeys($h['plan_features'] ?? (isset($h['plan_id']) && $h['plan_id'] ? DB::value('SELECT features FROM plans WHERE id = :id', ['id' => (int) $h['plan_id']]) : null));
    $v = static fn (string $k) => e((string) ($h[$k] ?? ''));
    ob_start();
    ?>
    <div class="card mt-3" id="featureOverrides"><div class="card-header"><?= e(__('Features & limits of this customer')) ?></div><div class="card-body">
      <p class="small text-muted"><?= e(__('The plan decides what the customer gets. Here you can add or remove single features for this customer only, and change its limits.')) ?></p>
      <div class="row g-3 mb-3">
        <div class="col-sm-6"><label class="form-label" for="h_mu"><?= e(__('Max users')) ?></label><input class="form-control" type="number" min="0" id="h_mu" name="max_users" value="<?= $v('max_users') ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
        <div class="col-sm-6"><label class="form-label" for="h_st"><?= e(__('Storage (MB)')) ?></label><input class="form-control" type="number" min="0" id="h_st" name="storage_mb" value="<?= $v('storage_mb') ?>" placeholder="<?= e(__('plan limit')) ?>"></div>
        <div class="col-12 form-text mt-0"><?= e(__('Empty = limit of the plan. Max screens = "Max TVs" above.')) ?></div>
      </div>
      <input type="hidden" name="feature_overrides_form" value="1">
      <div class="row g-2">
      <?php foreach (Features::grouped() as $group => $defs): ?>
        <div class="col-12"><div class="fw-semibold small text-uppercase text-muted mt-2"><?= e(__(Features::GROUPS[$group] ?? $group)) ?></div></div>
        <?php foreach ($defs as $k => $d):
            $cur = in_array($k, $o['add'], true) ? 'add' : (in_array($k, $o['remove'], true) ? 'remove' : '');
            $inPlan = in_array($k, $planKeys, true); ?>
          <div class="col-md-6 col-xl-4 d-flex align-items-center gap-2">
            <select class="form-select form-select-sm w-auto" name="feature_override[<?= e($k) ?>]" id="fo_<?= e($k) ?>" aria-label="<?= e(__($d['label'])) ?>">
              <option value=""<?= $cur === '' ? ' selected' : '' ?>><?= e($inPlan ? __('Plan: included') : __('Plan: not included')) ?></option>
              <option value="add"<?= $cur === 'add' ? ' selected' : '' ?>><?= e(__('Add for this customer')) ?></option>
              <option value="remove"<?= $cur === 'remove' ? ' selected' : '' ?>><?= e(__('Remove for this customer')) ?></option>
            </select>
            <label class="small" for="fo_<?= e($k) ?>"><?= e(__($d['label'])) ?></label>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </div>
      <?php if (!empty($h['id'])): ?><div class="mt-3"><?= features_summary_html((int) $h['id']) ?></div><?php endif; ?>
    </div></div>
    <?php
    return (string) ob_get_clean();
}

/** [overrides JSON|null, max_users|null, storage_mb|null, errors] from the customer form POST. */
function features_override_input(array $in): array
{
    $errors = [];
    $add = $remove = [];
    foreach ((array) ($in['feature_override'] ?? []) as $k => $mode) {
        if (!is_string($k) || !Features::exists($k) || Features::isCore($k)) {
            continue;
        }
        if ($mode === 'add') {
            $add[] = $k;
        } elseif ($mode === 'remove') {
            $remove[] = $k;
        }
    }
    $maxUsers = Features::limitInput($in['max_users'] ?? '', __('Max users'), $errors, 100000);
    $storage = Features::limitInput($in['storage_mb'] ?? '', __('Storage (MB)'), $errors);
    return [Features::encodeOverrides($add, $remove), $maxUsers, $storage, $errors];
}

/** Effective features + limits of a customer: included / not included per group. */
function features_summary_html(int $hotelId, bool $withLimits = true): string
{
    Tenant::forget($hotelId);
    $map = Features::effective($hotelId);
    $o = Features::overrides($hotelId);
    $lim = Features::limits($hotelId);
    $planKeys = Features::planKeys(Tenant::hotel($hotelId)['plan_features'] ?? null);
    $unl = __('unlimited');
    ob_start();
    ?>
    <div class="features-summary" data-features-summary="<?= (int) $hotelId ?>">
      <?php if ($withLimits): ?>
      <div class="d-flex flex-wrap gap-3 small mb-2">
        <span><i class="bi bi-tv"></i> <?= e(__('Screens')) ?>: <strong><?= e(Features::screenCount($hotelId) . ' / ' . ($lim['max_screens'] ?? $unl)) ?></strong></span>
        <span><i class="bi bi-people"></i> <?= e(__('Users')) ?>: <strong><?= e(Features::userCount($hotelId) . ' / ' . ($lim['max_users'] ?? $unl)) ?></strong></span>
        <span><i class="bi bi-hdd"></i> <?= e(__('Storage')) ?>: <strong><?= e((int) ceil(Features::storageUsed($hotelId) / 1048576) . ' MB / ' . ($lim['storage_mb'] !== null ? $lim['storage_mb'] . ' MB' : $unl)) ?></strong></span>
      </div>
      <?php endif; ?>
      <div class="row g-2">
      <?php foreach (Features::grouped() as $group => $defs): ?>
        <div class="col-md-6 col-xl-4"><div class="border rounded p-2 h-100">
          <div class="fw-semibold small mb-1"><?= e(__(Features::GROUPS[$group] ?? $group)) ?></div>
          <ul class="list-unstyled small mb-0">
          <?php foreach ($defs as $k => $d): $on = !empty($map[$k]); ?>
            <li class="<?= $on ? '' : 'text-muted' ?>" data-feature="<?= e($k) ?>" data-on="<?= $on ? '1' : '0' ?>">
              <i class="bi <?= $on ? 'bi-check-circle-fill text-success' : 'bi-x-circle' ?>"></i> <?= e(__($d['label'])) ?>
              <?php if (in_array($k, $o['add'], true)): ?><span class="badge text-bg-info"><?= e(__('added')) ?></span><?php endif; ?>
              <?php if (in_array($k, $o['remove'], true)): ?><span class="badge text-bg-secondary"><?= e(__('removed')) ?></span><?php endif; ?>
              <?php if (!$on && in_array($k, $planKeys, true) && !in_array($k, $o['remove'], true)): ?><span class="badge text-bg-warning"><?= e(__('needs another feature')) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
          </ul>
        </div></div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
