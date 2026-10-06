<?php
declare(strict_types=1);
/**
 * Chain broadcast & settings (#20):
 *  - push chain content now / schedule it / emergency message / stop emergency, for all rooms of the
 *    selected hotels (Broadcaster runs in each hotel's context; the chain item is published first);
 *  - chain-wide settings templates (ticker, overlay, branding) pushed to selected hotels;
 *  - history of chain actions with per-hotel results.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

[$user, $chain] = Chains::page();
$cid = (int) $chain['id'];
$cq = ['chain' => $cid];
$names = array_column(Chains::hotels($cid), 'name', 'id');

$report = static function (array $res, string $label) use ($names): void {
    $bad = array_filter($res, static fn ($r) => in_array($r['status'], ['error', 'skipped'], true));
    $devices = array_sum(array_map(static fn ($r) => (int) ($r['devices'] ?? 0), $res));
    flash($bad ? 'warning' : 'success', $label . ': ' . Chains::summarize($res) . ($devices ? ' · ' . __(':n TVs', ['n' => $devices]) : ''));
    if ($bad) {
        flash_errors(array_map(static fn ($hid, $r) => ($names[$hid] ?? '#' . $hid) . ': ' . $r['message'], array_keys($bad), $bad));
    }
};

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $hotels = int_ids($_POST['hotels'] ?? []);
    switch ($op) {
        case 'broadcast':
            $kind = req_str('kind', $_POST, 20);
            [$p, $errors] = Chains::validateBroadcast($cid, $kind, $_POST);
            if (!$hotels) {
                $errors[] = __('Select at least one hotel.');
            }
            if ($errors) {
                flash_errors($errors);
                break;
            }
            $res = Chains::broadcast($cid, $hotels, $p, Auth::id());
            $report($res, match ($kind) {
                'push' => __('Pushed now'), 'schedule' => __('Scheduled'), 'emergency' => __('Emergency started'), default => __('Emergency stopped'),
            });
            break;

        case 'save_template':
            $id = req_int('id', $_POST);
            $existing = $id ? Chains::template($cid, $id) : null;
            [$data, $errors] = Chains::validateTemplate($chain, $_POST);
            if ($errors) {
                flash_errors($errors);
                redirect(admin_url('chain_broadcast.php', $cq + ['tpl' => $id ?: 'new']));
            }
            Chains::saveTemplate($cid, $existing, $data, Auth::id());
            flash('success', __('Template saved.'));
            break;

        case 'delete_template':
            Chains::template($cid, req_int('id', $_POST));
            DB::query('DELETE FROM chain_setting_templates WHERE id = :id AND chain_id = :c', ['id' => req_int('id', $_POST), 'c' => $cid]);
            flash('success', __('Deleted.'));
            break;

        case 'apply_template':
            if (!$hotels) {
                flash('warning', __('Select at least one hotel.'));
                break;
            }
            $res = Chains::applyTemplate($cid, req_int('template_id', $_POST), $hotels, Auth::id());
            $report($res, __('Settings pushed'));
            break;
    }
    redirect(admin_url('chain_broadcast.php', $cq));
}

$hotels = Chains::hotels($cid);
$states = [];
foreach ($hotels as $h) {
    $states[(int) $h['id']] = Tenant::state((int) $h['id']);
}
$emerg = Chains::activeEmergencies($cid);
$items = Chains::contentItems($cid);
$playlists = Chains::playlists($cid);
$templates = Chains::templates($cid);
$tplEdit = null;
$tplParam = req_str('tpl', $_GET, 10);
if ($tplParam === 'new') {
    $tplEdit = ['id' => 0, 'name' => '', 'settings' => json_out(['groups' => ['ticker'], 'values' => []])];
} elseif (ctype_digit($tplParam) && $tplParam !== '') {
    $tplEdit = Chains::template($cid, (int) $tplParam);
}
$actions = Chains::actions($cid, 15);

$pageTitle = __('Chain broadcast & settings');
$activeNav = 'chain_broadcast';
$extraScripts = ['js/chain.js'];
require __DIR__ . '/partials/header.php';

$hotelPicker = static function (string $uid) use ($hotels, $states, $emerg): string {
    $h = '<div class="d-flex align-items-center gap-2 mb-1"><span class="form-label mb-0">' . e(__('Hotels')) . '</span>'
        . '<button type="button" class="btn btn-sm btn-light border ms-auto" data-check-all="hotels[]">' . e(__('Select all')) . '</button></div>'
        . '<div class="border rounded p-2 d-flex flex-wrap gap-2">';
    if (!$hotels) {
        $h .= '<span class="text-muted small">' . e(__('No hotels in this chain yet.')) . '</span>';
    }
    foreach ($hotels as $x) {
        $id = (int) $x['id'];
        $off = $states[$id] !== 'active';
        $h .= '<label class="form-check-label small border rounded px-2 py-1' . ($off ? ' text-muted' : '') . '"><input class="form-check-input me-1" type="checkbox" name="hotels[]" value="' . $id . '"'
            . ($off ? ' disabled' : ' checked') . ' id="' . e($uid . $id) . '">' . e($x['name'])
            . (!empty($emerg[$id]) ? ' <span class="badge text-bg-danger">!</span>' : '') . ($off ? ' (' . e(__('paused')) . ')' : '') . '</label>';
    }
    return $h . '</div>';
};
$sourceSelect = static function () use ($items, $playlists): string {
    $h = '<select class="form-select" name="source" id="b_source"><option value="">' . e(__('— choose —')) . '</option>';
    if ($playlists) {
        $h .= '<optgroup label="' . e(__('Playlists')) . '">';
        foreach ($playlists as $p) {
            $h .= '<option value="p:' . (int) $p['id'] . '">▶ ' . e($p['name']) . ' (' . (int) $p['item_count'] . ')</option>';
        }
        $h .= '</optgroup>';
    }
    if ($items) {
        $h .= '<optgroup label="' . e(__('Content')) . '">';
        foreach ($items as $c) {
            $h .= '<option value="c:' . (int) $c['id'] . '">' . e($c['title']) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
};
?>
<div class="page-head">
  <div class="min-w-0"><h1 class="text-truncate"><i class="bi bi-broadcast"></i> <?= e(__('Chain broadcast & settings')) ?></h1>
    <p class="lead-sm mb-0"><?= e($chain['name']) ?> · <?= e(__('Reaches all rooms of the selected hotels.')) ?></p></div>
  <a class="btn btn-light border" href="<?= e(admin_url('chain.php', $cq)) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Chain dashboard')) ?></a>
</div>

<?php if ($emerg): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i>
  <div class="flex-grow-1"><?= e(__('Emergency message is ON in: :h', ['h' => implode(', ', array_map(static fn ($id) => $names[$id] ?? '#' . $id, array_keys($emerg)))])) ?></div>
  <form method="post" class="m-0" data-confirm="<?= e(__('Stop the emergency message on all TVs?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="broadcast"><input type="hidden" name="kind" value="emergency_stop"><input type="hidden" name="chain" value="<?= $cid ?>">
    <?php foreach (array_keys($emerg) as $hid): ?><input type="hidden" name="hotels[]" value="<?= (int) $hid ?>"><?php endforeach; ?>
    <button class="btn btn-sm btn-light fw-bold"><i class="bi bi-stop-circle"></i> <?= e(__('Stop everywhere')) ?></button></form>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" class="card mb-3" id="chainBroadcast">
      <?= Csrf::field() ?><input type="hidden" name="op" value="broadcast"><input type="hidden" name="chain" value="<?= $cid ?>">
      <div class="card-header"><i class="bi bi-broadcast-pin"></i> <?= e(__('Broadcast to hotels')) ?></div>
      <div class="card-body row g-3">
        <div class="col-12"><div class="btn-group flex-wrap" role="group" data-kind-switch>
          <?php foreach (['push' => [__('Push now'), 'bi-lightning'], 'schedule' => [__('Schedule'), 'bi-calendar-event'], 'emergency' => [__('Emergency'), 'bi-exclamation-triangle'], 'emergency_stop' => [__('Stop emergency'), 'bi-stop-circle']] as $k => [$l, $ic]): ?>
            <input type="radio" class="btn-check" name="kind" value="<?= e($k) ?>" id="k_<?= e($k) ?>"<?= $k === 'push' ? ' checked' : '' ?> autocomplete="off">
            <label class="btn btn-sm <?= str_starts_with($k, 'emergency') ? 'btn-outline-danger' : 'btn-outline-primary' ?>" for="k_<?= e($k) ?>"><i class="bi <?= e($ic) ?>"></i> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div></div>
        <div class="col-md-7" data-kinds="push schedule"><label class="form-label" for="b_source"><?= e(__('Chain content or playlist')) ?></label><?= $sourceSelect() ?>
          <?php if (!$items && !$playlists): ?><div class="form-text"><a href="<?= e(admin_url('chain_content.php', $cq)) ?>"><?= e(__('Add chain content first.')) ?></a></div><?php endif; ?></div>
        <div class="col-md-5" data-kinds="push schedule emergency"><label class="form-label" for="b_title"><?= e(__('Title')) ?></label><input class="form-control" id="b_title" name="title" maxlength="190"></div>
        <div class="col-12" data-kinds="schedule" hidden>
          <div class="row g-2">
            <div class="col-12"><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" value="once" id="m_once" checked><label class="form-check-label" for="m_once"><?= e(__('Once (becomes the room content)')) ?></label></div>
              <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" value="window" id="m_win"><label class="form-check-label" for="m_win"><?= e(__('Time window (then back to normal)')) ?></label></div></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="s_start"><?= e(__('Start')) ?></label><input class="form-control form-control-sm" type="datetime-local" id="s_start" name="start_at"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="s_end"><?= e(__('End')) ?></label><input class="form-control form-control-sm" type="datetime-local" id="s_end" name="end_at"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="s_ds"><?= e(__('Daily from')) ?></label><input class="form-control form-control-sm" type="time" id="s_ds" name="daily_start"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="s_de"><?= e(__('Daily until')) ?></label><input class="form-control form-control-sm" type="time" id="s_de" name="daily_end"></div>
            <div class="col-12"><?php foreach (day_names() as $n => $d): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="repeat_days[]" value="<?= $n ?>" id="rd<?= $n ?>"><label class="form-check-label small" for="rd<?= $n ?>"><?= e($d) ?></label></div><?php endforeach; ?></div>
            <div class="col-12 form-text"><?= e(__('Times are the local time of each hotel.')) ?></div>
          </div>
        </div>
        <div class="col-12" data-kinds="emergency" hidden>
          <label class="form-label" for="e_msg"><?= e(__('Message')) ?></label><textarea class="form-control" id="e_msg" name="message" rows="2" maxlength="1000"></textarea>
          <div class="row g-2 mt-1"><div class="col-6 col-md-3"><label class="form-label small" for="e_bg"><?= e(__('Background')) ?></label><input type="color" class="form-control form-control-color w-100" id="e_bg" name="bg_color" value="#B00020"></div>
            <div class="col-6 col-md-3"><label class="form-label small" for="e_fg"><?= e(__('Text colour')) ?></label><input type="color" class="form-control form-control-color w-100" id="e_fg" name="text_color" value="#FFFFFF"></div></div>
        </div>
        <div class="col-12"><?= $hotelPicker('bh') ?></div>
        <div class="col-12"><button class="btn btn-primary" data-confirm="<?= e(__('Send to all rooms of the selected hotels?')) ?>"><i class="bi bi-send"></i> <?= e(__('Send')) ?></button></div>
      </div>
    </form>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-clock-history"></i> <?= e(__('History')) ?></div>
      <ul class="list-group list-group-flush">
        <?php if (!$actions): ?><li class="list-group-item text-muted small"><?= e(__('Nothing sent yet.')) ?></li><?php endif; ?>
        <?php foreach ($actions as $a): $res = json_decode((string) $a['results'], true) ?: []; ?>
          <li class="list-group-item small"><div class="d-flex flex-wrap gap-2"><strong><?= e($a['title'] ?: $a['kind']) ?></strong><span class="badge text-bg-light border"><?= e($a['kind']) ?></span>
            <span class="ms-auto text-muted"><?= e(time_ago((string) $a['created_at'])) ?> · <?= e((string) ($a['username'] ?? '')) ?></span></div>
            <div class="text-muted"><?= e(Chains::summarize($res)) ?> — <?= e(implode(', ', array_map(static fn ($hid) => $names[$hid] ?? '#' . $hid, array_keys($res)))) ?></div></li>
        <?php endforeach; ?>
      </ul></div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header d-flex align-items-center"><span><i class="bi bi-sliders"></i> <?= e(__('Settings templates')) ?></span>
      <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(admin_url('chain_broadcast.php', $cq + ['tpl' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New')) ?></a></div>
      <?php if ($tplEdit): $td = Chains::templateData($tplEdit); $v = $td['values']; $g = $td['groups']; ?>
      <form method="post" class="card-body border-bottom">
        <?= Csrf::field() ?><input type="hidden" name="op" value="save_template"><input type="hidden" name="chain" value="<?= $cid ?>"><input type="hidden" name="id" value="<?= (int) $tplEdit['id'] ?>">
        <div class="mb-2"><label class="form-label" for="t_name"><?= e(__('Template name')) ?></label><input class="form-control" id="t_name" name="name" value="<?= e($tplEdit['name']) ?>" required maxlength="150"></div>
        <fieldset class="border rounded p-2 mb-2"><legend class="float-none w-auto px-1 fs-6 mb-0"><label><input class="form-check-input" type="checkbox" name="groups[]" value="ticker"<?= in_array('ticker', $g, true) ? ' checked' : '' ?>> <?= e(__('Scrolling ticker')) ?></label></legend>
          <textarea class="form-control form-control-sm mb-2" name="ticker_text" rows="2" maxlength="1000" aria-label="<?= e(__('Ticker text (empty = no ticker)')) ?>" placeholder="<?= e(__('Ticker text (empty = no ticker)')) ?>"><?= e((string) ($v['ticker_text'] ?? '')) ?></textarea>
          <div class="row g-2"><div class="col-4"><label class="form-label small" for="t_tbg"><?= e(__('Background')) ?></label><input type="color" class="form-control form-control-color w-100" id="t_tbg" name="ticker_bg_color" value="<?= e(clean_color($v['ticker_bg_color'] ?? null, '#000000')) ?>"></div>
            <div class="col-4"><label class="form-label small" for="t_tfg"><?= e(__('Text')) ?></label><input type="color" class="form-control form-control-color w-100" id="t_tfg" name="ticker_text_color" value="<?= e(clean_color($v['ticker_text_color'] ?? null, '#FFD700')) ?>"></div>
            <div class="col-4"><label class="form-label small" for="t_tsp"><?= e(__('Speed')) ?></label><input type="range" class="form-range" id="t_tsp" name="ticker_speed" min="1" max="10" value="<?= (int) ($v['ticker_speed'] ?? 5) ?>"></div></div>
        </fieldset>
        <fieldset class="border rounded p-2 mb-2"><legend class="float-none w-auto px-1 fs-6 mb-0"><label><input class="form-check-input" type="checkbox" name="groups[]" value="overlay"<?= in_array('overlay', $g, true) ? ' checked' : '' ?>> <?= e(__('Screen overlay')) ?></label></legend>
          <?php foreach (['overlay_clock' => __('Show clock'), 'overlay_logo' => __('Show hotel logo'), 'overlay_weather' => __('Show weather')] as $k => $l): ?>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="<?= e($k) ?>" value="1" id="t_<?= e($k) ?>"<?= ($v[$k] ?? ($k === 'overlay_weather' ? '0' : '1')) === '1' ? ' checked' : '' ?>><label class="form-check-label small" for="t_<?= e($k) ?>"><?= e($l) ?></label></div>
          <?php endforeach; ?>
          <select class="form-select form-select-sm mt-1" name="overlay_clock_format" aria-label="<?= e(__('Clock format')) ?>"><?php foreach (Chains::CLOCK_FORMATS as $f): ?><option value="<?= e($f) ?>"<?= ($v['overlay_clock_format'] ?? 'hh:mm a') === $f ? ' selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select>
        </fieldset>
        <fieldset class="border rounded p-2 mb-2"><legend class="float-none w-auto px-1 fs-6 mb-0"><label><input class="form-check-input" type="checkbox" name="groups[]" value="branding"<?= in_array('branding', $g, true) ? ' checked' : '' ?>> <?= e(__('Branding')) ?></label></legend>
          <div class="row g-2"><div class="col-7"><label class="form-label small" for="t_bn"><?= e(__('Product name')) ?></label><input class="form-control form-control-sm" id="t_bn" name="brand_name" value="<?= e((string) ($v['brand_name'] ?? $chain['brand_name'] ?? '')) ?>" maxlength="120"></div>
            <div class="col-5"><label class="form-label small" for="t_bc"><?= e(__('Colour')) ?></label><input class="form-control form-control-sm" id="t_bc" name="brand_color" value="<?= e((string) ($v['brand_color'] ?? $chain['brand_color'] ?? '')) ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#7B1FA2"></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="use_chain_logo" value="1" id="t_logo"<?= !empty($v['brand_logo']) || ($tplEdit['id'] === 0 && !empty($chain['brand_logo'])) ? ' checked' : '' ?><?= empty($chain['brand_logo']) ? ' disabled' : '' ?>>
              <label class="form-check-label small" for="t_logo"><?= e(__('Use the chain logo')) ?></label></div></div></div>
          <div class="form-text"><?= e(__('Replaces the product name, logo and colour of the hotel (admin panel, TVs). Empty fields remove the hotel override.')) ?></div>
        </fieldset>
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= e(__('Save template')) ?></button>
        <a class="btn btn-sm btn-light border" href="<?= e(admin_url('chain_broadcast.php', $cq)) ?>"><?= e(__('Cancel')) ?></a>
      </form>
      <?php endif; ?>
      <?php if (!$templates && !$tplEdit): ?><div class="card-body text-muted small"><?= e(__('No templates yet. A template stores ticker text, overlay and branding once for the whole chain.')) ?></div><?php endif; ?>
      <?php if ($templates): ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($templates as $t): $td = Chains::templateData($t); ?>
          <li class="list-group-item d-flex align-items-center gap-2"><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($t['name']) ?></div>
            <div class="small text-muted"><?= e(implode(', ', array_map(static fn ($x) => match ($x) { 'ticker' => __('Scrolling ticker'), 'overlay' => __('Screen overlay'), default => __('Branding') }, $td['groups']))) ?></div></div>
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('chain_broadcast.php', $cq + ['tpl' => $t['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="m-0" data-confirm="<?= e(__('Delete this template?')) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete_template"><input type="hidden" name="chain" value="<?= $cid ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="card-body border-top">
        <?= Csrf::field() ?><input type="hidden" name="op" value="apply_template"><input type="hidden" name="chain" value="<?= $cid ?>">
        <label class="form-label" for="t_apply"><?= e(__('Push a template to hotels')) ?></label>
        <select class="form-select mb-2" id="t_apply" name="template_id"><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <?= $hotelPicker('th') ?>
        <button class="btn btn-primary mt-2" data-confirm="<?= e(__('Overwrite these settings in the selected hotels?')) ?>"><i class="bi bi-send"></i> <?= e(__('Push settings')) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
