<?php
declare(strict_types=1);
/**
 * Rates (#21 / #22): quick, phone-friendly page to type today's gold & silver rates (MetalRates, every
 * save is a new history row; the TV board shows ▲ / ▼ against the previous one), the manual market
 * values ("Label | value | change %") and — for managers — the automatic (indicative) gold mode with
 * duty / markup %. Used by the Gold & silver and Stock market apps and the ticker placeholders.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('rates.manage');
Csrf::check();

$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'market' ? 'market' : 'gold';
$canMode = Auth::can('content.manage');
$formErrors = [];
$form = null;
$marketText = null;

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    switch ($op) {
        case 'save':
            [$data, $formErrors] = MetalRates::validate($_POST);
            if ($formErrors) {
                http_response_code(422);
                $form = $data;
                break;
            }
            $id = MetalRates::save($data);
            ActivityLog::add('rates_update', 'metal_rates', $id, implode(' / ', array_map(static fn ($f) => $f . '=' . ($data[$f] ?? '-'), MetalRates::FIELDS)));
            flash('success', __('Rates saved. TVs show them within a minute.'));
            redirect(admin_url('rates.php'));

        case 'delete':
            $row = MetalRates::find(req_int('id', $_POST)); // another hotel's id → 404
            if ($row) {
                MetalRates::delete((int) $row['id']);
                ActivityLog::add('rates_delete', 'metal_rates', (int) $row['id'], (string) $row['created_at']);
                flash('success', __('History entry deleted.'));
            } else {
                flash('warning', __('Entry not found.'));
            }
            redirect(admin_url('rates.php'));

        case 'market':
            $text = is_string($_POST['market'] ?? null) ? mb_substr(trim(str_replace("\r\n", "\n", $_POST['market'])), 0, 3000) : '';
            foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
                if (trim($line) !== '' && !DataFeeds::manualMarket($line)) {
                    $formErrors[] = __('Line :n: enter "Label | value | change %", e.g. "NIFTY 50 | 24,350.10 | +0.45".', ['n' => $i + 1]);
                }
            }
            if ($formErrors) {
                http_response_code(422);
                $marketText = $text;
                $tab = 'market';
                break;
            }
            Settings::set('rates_market_manual', $text);
            Settings::set('rates_market_updated', (string) time());
            Settings::bumpContentVersion();
            DataFeeds::flush();
            ActivityLog::add('rates_market', 'settings', null, mb_substr($text, 0, 200));
            flash('success', __('Market values saved.'));
            redirect(admin_url('rates.php', ['tab' => 'market']));

        case 'mode':
            require_can('content.manage');
            $mode = ($_POST['rates_mode'] ?? '') === 'auto' ? 'auto' : 'manual';
            $pct = static fn (string $k, string $def): string => (string) round(max(0, min(100, (float) (DataFeeds::number(req_str($k, $_POST, 10)) ?? $def))), 2);
            Settings::set('rates_mode', $mode);
            Settings::set('rates_duty_pct', $pct('rates_duty_pct', '15'));
            Settings::set('rates_markup_pct', $pct('rates_markup_pct', '3'));
            Settings::set('rates_auto_note', mb_substr(req_str('rates_auto_note', $_POST, 255), 0, 255));
            Settings::bumpContentVersion();
            DataFeeds::flush();
            ActivityLog::add('rates_mode', 'settings', null, $mode);
            flash('success', __('Settings saved.'));
            redirect(admin_url('rates.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('rates.php'));
    }
}

$cur = MetalRates::current();
$history = MetalRates::history();
$labels = MetalRates::labels();
$latest = $history[0] ?? null;
if ($form === null) {
    $form = ['note' => (string) ($latest['note'] ?? '')];
    foreach (MetalRates::FIELDS as $f) {
        $form[$f] = $latest && $latest[$f] !== null ? rtrim(rtrim((string) $latest[$f], '0'), '.') : '';
    }
}
$fmt = static fn ($v): string => $v === null || $v === '' ? '—' : '₹ ' . DataFeeds::inr((float) $v, 0);
$activeNav = 'rates';
$pageTitle = __('Rates');
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-gem"></i> <?= e(__('Rates')) ?></h1><p class="lead-sm"><?= e(__('Today\'s gold, silver and market values for the TV widgets and ticker placeholders such as {gold_24k}.')) ?></p></div>
  <a href="<?= e(admin_url('apps.php')) ?>" class="btn btn-light border"><i class="bi bi-grid-3x3-gap"></i> <?= e(__('Apps')) ?></a>
</div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'gold' ? ' active' : '' ?>" href="<?= e(admin_url('rates.php')) ?>"><?= e(__('Gold & silver')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'market' ? ' active' : '' ?>" href="<?= e(admin_url('rates.php', ['tab' => 'market'])) ?>"><?= e(__('Market (manual)')) ?></a></li>
</ul>
<?php if ($formErrors): ?>
  <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($tab === 'gold'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <?php if ($cur['mode'] === 'auto'): ?>
      <div class="alert alert-info small"><?= e(__('Automatic mode is on: TVs show indicative rates from the gold price feed. The rates below are used while no automatic value is available.')) ?>
        <?php if ($cur['source'] === 'auto'): ?><br><strong><?= e(__('Now on TV')) ?>:</strong> <?php foreach (MetalRates::FIELDS as $f): ?><?= e($labels[$f]) ?> <?= e($fmt($cur['values'][$f])) ?> &nbsp; <?php endforeach; ?><?php endif; ?>
      </div>
    <?php endif; ?>
    <form method="post" class="card" id="ratesForm" action="<?= e(admin_url('rates.php')) ?>"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save">
      <?php foreach (MetalRates::FIELDS as $f): ?>
      <div class="col-6">
        <label class="form-label" for="r_<?= e($f) ?>"><?= e($labels[$f]) ?></label>
        <div class="input-group input-group-lg"><span class="input-group-text">₹</span>
          <input class="form-control" id="r_<?= e($f) ?>" name="<?= e($f) ?>" inputmode="decimal" autocomplete="off" maxlength="15" value="<?= e((string) $form[$f]) ?>"></div>
      </div>
      <?php endforeach; ?>
      <div class="col-12">
        <label class="form-label" for="r_note"><?= e(__('Note (optional)')) ?></label>
        <input class="form-control" id="r_note" name="note" maxlength="255" value="<?= e((string) $form['note']) ?>" placeholder="<?= e(__('e.g. Making charges from 8% · GST extra')) ?>">
      </div>
      <div class="col-12 d-grid"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save rates')) ?></button></div>
      <?php if ($latest): ?><div class="col-12 small text-muted"><?= e(__('Last saved :t', ['t' => date('d M Y, H:i', (int) strtotime((string) $latest['created_at']))])) ?></div><?php endif; ?>
    </div></form>

    <?php if ($canMode): ?>
    <form method="post" class="card mt-3" action="<?= e(admin_url('rates.php')) ?>"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="mode">
      <div class="col-12"><strong><?= e(__('Automatic rates (optional)')) ?></strong>
        <div class="form-text"><?= e(__('Indicative rates from the international gold price (GoldAPI.io) converted to rupees, plus duty and your markup. Shown on TV as "Indicative". Most jewellers type their own rates instead.')) ?></div></div>
      <div class="col-md-4">
        <label class="form-label" for="r_mode"><?= e(__('Mode')) ?></label>
        <select class="form-select" id="r_mode" name="rates_mode">
          <option value="manual"<?= $cur['mode'] === 'manual' ? ' selected' : '' ?>><?= e(__('Manual (typed here)')) ?></option>
          <option value="auto"<?= $cur['mode'] === 'auto' ? ' selected' : '' ?>><?= e(__('Automatic (indicative)')) ?></option>
        </select>
        <?php if (!DataFeeds::hasKey('goldapi')): ?><div class="form-text text-warning"><?= e(__('No gold price key yet (Platform settings → Data feeds, or your own key on the Data feeds page).')) ?></div><?php endif; ?>
      </div>
      <div class="col-6 col-md-4"><label class="form-label" for="r_duty"><?= e(__('Import duty + taxes %')) ?></label><input class="form-control" id="r_duty" name="rates_duty_pct" inputmode="decimal" value="<?= e((string) MetalRates::dutyPct()) ?>"></div>
      <div class="col-6 col-md-4"><label class="form-label" for="r_markup"><?= e(__('Markup %')) ?></label><input class="form-control" id="r_markup" name="rates_markup_pct" inputmode="decimal" value="<?= e((string) MetalRates::markupPct()) ?>"></div>
      <div class="col-12"><label class="form-label" for="r_anote"><?= e(__('Note in automatic mode')) ?></label><input class="form-control" id="r_anote" name="rates_auto_note" maxlength="255" value="<?= e((string) Settings::get('rates_auto_note', '')) ?>"></div>
      <div class="col-12"><button class="btn btn-outline-primary"><?= e(__('Save settings')) ?></button></div>
    </div></form>
    <?php endif; ?>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= e(__('History')) ?></div>
      <?php if (!$history): ?><div class="card-body text-muted"><?= e(__('No rates saved yet.')) ?></div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th><?= e(__('Date')) ?></th><th>24K</th><th>22K</th><th>18K</th><th><?= e(__('Silver')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($history as $i => $h): ?>
          <tr<?= $i === 0 ? ' class="table-success"' : '' ?>>
            <td class="small"><?= e(date('d M, H:i', (int) strtotime((string) $h['created_at']))) ?><?php if ($h['username']): ?><div class="text-muted"><?= e((string) $h['username']) ?></div><?php endif; ?>
              <?php if ($h['note']): ?><div class="text-muted text-truncate" style="max-width:12rem"><?= e((string) $h['note']) ?></div><?php endif; ?></td>
            <?php foreach (MetalRates::FIELDS as $f): ?><td class="small text-nowrap"><?= e($fmt($h[$f])) ?></td><?php endforeach; ?>
            <td><form method="post" class="m-0" onsubmit="return confirm(<?= e(json_encode(__('Delete this entry?'))) ?>)"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
              <button class="btn btn-sm btn-link text-danger p-0" title="<?= e(__('Delete')) ?>" aria-label="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: $mt = $marketText ?? (string) Settings::get('rates_market_manual', ''); $updated = (int) Settings::get('rates_market_updated', '0'); ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card" action="<?= e(admin_url('rates.php', ['tab' => 'market'])) ?>"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="market"><input type="hidden" name="tab" value="market">
      <div class="col-12">
        <label class="form-label" for="r_market"><?= e(__('Market values')) ?></label>
        <textarea class="form-control font-monospace" id="r_market" name="market" rows="8" maxlength="3000" placeholder="NIFTY 50 | 24,350.10 | +0.45&#10;SENSEX | 80,120.55 | -0.12&#10;BANK NIFTY | 52,400 | 0.30"><?= e($mt) ?></textarea>
        <div class="form-text"><?= e(__('One per line: Label | value | change %. Used by the Stock market app in manual mode (or without a market data key) and by the ticker placeholders {nifty}, {sensex}, {banknifty}.')) ?></div>
      </div>
      <div class="col-12 d-grid d-md-block"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save market values')) ?></button></div>
      <?php if ($updated): ?><div class="col-12 small text-muted"><?= e(__('Last saved :t', ['t' => date('d M Y, H:i', $updated)])) ?></div><?php endif; ?>
    </div></form>
  </div>
  <div class="col-lg-5"><div class="card"><div class="card-header"><?= e(__('Preview')) ?></div><ul class="list-group list-group-flush">
    <?php foreach (DataFeeds::manualMarket($mt) as $m): ?>
      <li class="list-group-item d-flex justify-content-between"><span><?= e($m['label']) ?></span><span><?= e(DataFeeds::inr($m['price'], 2)) ?>
        <?php if ($m['pct'] !== null): ?><span class="<?= $m['pct'] >= 0 ? 'text-success' : 'text-danger' ?>"> <?= e(DataFeeds::pct($m['pct'])) ?></span><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ul></div></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
