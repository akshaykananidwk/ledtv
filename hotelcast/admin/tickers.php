<?php
declare(strict_types=1);
/**
 * Ticker bar (2.2): scrolling text bars for all TVs, a group or one room, with colours, speed,
 * size, position, "do not cover the video", override, priority, date range, daily window and days.
 * Users with limited TV access (Access) only see / create tickers for their own rooms and groups.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('tickers.manage');
Csrf::check();

$dtLocal = static fn (?string $d): string => $d ? date('Y-m-d\TH:i', (int) strtotime($d)) : '';
$groupNames = [];
foreach (hc_groups() as $g) {
    $groupNames[(int) $g['id']] = (string) $g['name'];
}
$roomNumbers = [];
foreach (hc_rooms() as $r) {
    $roomNumbers[(int) $r['id']] = (string) $r['room_number'];
}
$restricted = Access::restricted();
$allowedRooms = Access::roomIds();
$scope = Access::scope();
$formGroups = array_filter(hc_groups(), static fn ($g) => $scope === null || in_array((int) $g['id'], $scope['groups'], true));
$formRooms = array_filter(hc_rooms(), static fn ($r) => $allowedRooms === null || in_array((int) $r['id'], $allowedRooms, true));

/** Ticker of this hotel the user may edit; another hotel's id → 404 (Tenant::find), not one of the user's TVs → 403. */
$loadTicker = static function (int $id): ?array {
    $t = Tenant::find('tickers', $id);
    if ($t && !Tickers::visible($t)) {
        Access::deny('ticker ' . $id);
    }
    return $t;
};

$formTicker = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    switch ($op) {
        case 'save':
            $id = req_int('id', $_POST);
            $existing = $id ? $loadTicker($id) : null;
            if ($id && !$existing) {
                flash('warning', __('Ticker not found.'));
                redirect(admin_url('tickers.php'));
            }
            [$data, $formErrors] = Tickers::validate($_POST);
            if ($formErrors) {
                // Show the form again with the entered values.
                $formTicker = ['id' => $id] + $data + Tickers::DEFAULTS;
                $formTicker['target_id'] = $data['target_id'] ?? (req_int($data['target_type'] === 'group' ? 'group_id' : 'room_id', $_POST) ?: null);
                http_response_code(422);
                break;
            }
            $newId = Tickers::save($existing ? $id : null, $data);
            ActivityLog::add($existing ? 'ticker_update' : 'ticker_create', 'ticker', $newId, mb_substr($data['name'], 0, 120));
            flash('success', __('Ticker ":n" saved.', ['n' => $data['name']]));
            redirect(admin_url('tickers.php'));

        case 'delete':
            $t = $loadTicker(req_int('id', $_POST));
            if ($t) {
                Tickers::delete((int) $t['id']);
                ActivityLog::add('ticker_delete', 'ticker', (int) $t['id'], mb_substr((string) $t['name'], 0, 120));
                flash('success', __('Ticker ":n" deleted.', ['n' => $t['name']]));
            }
            redirect(admin_url('tickers.php'));

        case 'toggle':
            $t = $loadTicker(req_int('id', $_POST));
            if ($t) {
                $on = !(int) $t['is_active'];
                Tickers::setActive((int) $t['id'], $on);
                ActivityLog::add('ticker_toggle', 'ticker', (int) $t['id'], ($on ? 'on: ' : 'off: ') . mb_substr((string) $t['name'], 0, 100));
                flash('success', $on ? __('Ticker ":n" is switched on.', ['n' => $t['name']]) : __('Ticker ":n" is switched off.', ['n' => $t['name']]));
            }
            redirect(admin_url('tickers.php'));

        default:
            flash('warning', __('Unknown action.'));
            redirect(admin_url('tickers.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'tickers';
$pageTitle = __('Ticker bar');

$stateBadge = static function (string $state): string {
    [$cls, $icon, $label] = match ($state) {
        'live' => ['text-bg-success', 'bi-broadcast', __('Live')],
        'scheduled' => ['text-bg-info', 'bi-clock', __('Scheduled')],
        'expired' => ['text-bg-secondary', 'bi-hourglass-bottom', __('Expired')],
        default => ['text-bg-light border', 'bi-pause-circle', __('Off')],
    };
    return '<span class="badge rounded-pill ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . e($label) . '</span>';
};
$whenText = static function (array $t): string {
    $parts = [];
    if ($t['starts_at'] || $t['ends_at']) {
        $fmt = static fn (?string $d) => $d ? date('d M Y, h:i A', (int) strtotime($d)) : '…';
        $parts[] = $fmt($t['starts_at']) . ' → ' . $fmt($t['ends_at']);
    }
    if ($t['time_from'] && $t['time_to']) {
        $parts[] = __('Daily :a–:b', ['a' => substr((string) $t['time_from'], 0, 5), 'b' => substr((string) $t['time_to'], 0, 5)]);
    }
    $days = Tickers::normalizeDays((string) ($t['days'] ?? ''));
    if ($days) {
        $names = day_names();
        $parts[] = implode(', ', array_map(static fn ($d) => $names[$d] ?? (string) $d, $days));
    }
    return $parts ? implode(' · ', $parts) : __('Always');
};

if ($action === 'new' || $action === 'edit' || $formTicker !== null) {
    if ($formTicker === null) {
        $formTicker = Tickers::DEFAULTS;
        if ($action === 'edit') {
            $formTicker = $loadTicker(req_int('id', $_GET));
            if (!$formTicker) {
                flash('warning', __('Ticker not found.'));
                redirect(admin_url('tickers.php'));
            }
        } elseif ($restricted) {
            $formTicker['target_type'] = $formRooms ? 'room' : 'group';
        }
    }
    $t = $formTicker;
    $isEdit = (int) $t['id'] > 0;
    $pageTitle = $isEdit ? __('Edit ticker') : __('New ticker');
    $days = Tickers::normalizeDays((string) ($t['days'] ?? ''));
    $tid = $t['target_id'] !== null ? (int) $t['target_id'] : 0;
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('A scrolling text bar at the bottom or top of the TV. The video shrinks so the bar never covers it.')) ?></p></div>
      <a href="<?= e(admin_url('tickers.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" id="tickerForm" class="row g-3" novalidate>
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label" for="tk_message"><?= e(__('Message')) ?> *</label>
            <textarea class="form-control" id="tk_message" name="message" rows="3" maxlength="<?= Tickers::MAX_MESSAGE ?>" required lang="gu"
              placeholder="<?= e(__('e.g. Mangla Aarti at 6:00 AM | Breakfast 7–10 AM')) ?>"><?= e($t['message']) ?></textarea>
            <div class="form-text"><?= e(__('Gujarati, Hindi and English work. Several tickers on the same TV are joined with ✦.')) ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="tk_name"><?= e(__('Name (for you)')) ?></label>
            <input class="form-control" id="tk_name" name="name" maxlength="120" value="<?= e($t['name']) ?>" placeholder="<?= e(__('e.g. Aarti timings')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="tk_priority"><?= e(__('Priority')) ?></label>
            <input type="number" class="form-control" id="tk_priority" name="priority" min="-999" max="999" value="<?= (int) $t['priority'] ?>">
            <div class="form-text"><?= e(__('Higher priority comes first on the same level.')) ?></div>
          </div>
          <div class="col-12">
            <label class="form-label d-block"><?= e(__('On which TVs')) ?></label>
            <div class="btn-group flex-wrap" role="group">
              <?php if (!$restricted): ?>
              <input type="radio" class="btn-check" name="target_type" value="all" id="tt_all"<?= $t['target_type'] === 'all' ? ' checked' : '' ?>>
              <label class="btn btn-outline-primary" for="tt_all"><i class="bi bi-tv"></i> <?= e(__('All TVs')) ?></label>
              <?php endif; ?>
              <input type="radio" class="btn-check" name="target_type" value="group" id="tt_group"<?= $t['target_type'] === 'group' ? ' checked' : '' ?>>
              <label class="btn btn-outline-primary" for="tt_group"><i class="bi bi-collection"></i> <?= e(__('Group')) ?></label>
              <input type="radio" class="btn-check" name="target_type" value="room" id="tt_room"<?= $t['target_type'] === 'room' ? ' checked' : '' ?>>
              <label class="btn btn-outline-primary" for="tt_room"><i class="bi bi-door-closed"></i> <?= e(__('Room')) ?></label>
            </div>
          </div>
          <div class="col-md-6" data-target="group">
            <label class="form-label" for="tk_group"><?= e(__('Group')) ?></label>
            <select class="form-select" id="tk_group" name="group_id">
              <option value=""><?= e(__('— Choose a group —')) ?></option>
              <?php foreach ($formGroups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $t['target_type'] === 'group' && $tid === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" data-target="room">
            <label class="form-label" for="tk_room"><?= e(__('Room')) ?></label>
            <select class="form-select" id="tk_room" name="room_id">
              <option value=""><?= e(__('— Choose a room —')) ?></option>
              <?php foreach ($formRooms as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $t['target_type'] === 'room' && $tid === (int) $r['id'] ? ' selected' : '' ?>><?= e(trim($r['room_number'] . ' ' . ($r['name'] ?? ''))) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="tk_override" name="override_lower" value="1"<?= (int) $t['override_lower'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="tk_override"><?= e(__('Hide the less specific tickers on these TVs')) ?></label>
            </div>
            <div class="form-text"><?= e(__('Room tickers are more specific than group tickers, group tickers more than "All TVs". Without this option all of them are shown one after the other.')) ?></div>
          </div>
        </div></div>

        <div class="card mb-3"><div class="card-header"><?= e(__('When')) ?></div><div class="card-body row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="tk_starts"><?= e(__('From (optional)')) ?></label>
            <input type="datetime-local" class="form-control" id="tk_starts" name="starts_at" value="<?= e($dtLocal($t['starts_at'])) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="tk_ends"><?= e(__('Until (optional)')) ?></label>
            <input type="datetime-local" class="form-control" id="tk_ends" name="ends_at" value="<?= e($dtLocal($t['ends_at'])) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_tfrom"><?= e(__('Daily from')) ?></label>
            <input type="time" class="form-control" id="tk_tfrom" name="time_from" value="<?= e($t['time_from'] ? substr((string) $t['time_from'], 0, 5) : '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_tto"><?= e(__('Daily until')) ?></label>
            <input type="time" class="form-control" id="tk_tto" name="time_to" value="<?= e($t['time_to'] ? substr((string) $t['time_to'], 0, 5) : '') ?>">
          </div>
          <div class="col-12 form-text mt-0"><?= e(__('Leave the times empty for all day. A window like 22:00–06:00 runs overnight.')) ?></div>
          <div class="col-12">
            <label class="form-label d-block"><?= e(__('Only on these days (none = every day)')) ?></label>
            <?php foreach (day_names() as $n => $dn): ?>
              <input type="checkbox" class="btn-check" name="days[]" value="<?= $n ?>" id="tkd<?= $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?> autocomplete="off">
              <label class="btn btn-sm btn-outline-secondary mb-1" for="tkd<?= $n ?>"><?= e($dn) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="tk_active" name="is_active" value="1"<?= (int) $t['is_active'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="tk_active"><?= e(__('Switched on')) ?></label>
            </div>
          </div>
        </div></div>
      </div>

      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><?= e(__('Look')) ?></div><div class="card-body row g-3">
          <div class="col-12">
            <label class="form-label d-block"><?= e(__('Colour presets')) ?></label>
            <?php foreach (Tickers::PRESETS as [$pn, $pbg, $pfg]): ?>
              <button type="button" class="btn btn-sm mb-1 border" data-preset-bg="<?= e($pbg) ?>" data-preset-fg="<?= e($pfg) ?>" style="background:<?= e($pbg) ?>;color:<?= e($pfg) ?>"><?= e(__($pn)) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_bg"><?= e(__('Background')) ?></label>
            <input type="color" class="form-control form-control-color w-100" id="tk_bg" name="bg_color" value="<?= e(clean_color((string) $t['bg_color'], '#000000')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_fg"><?= e(__('Text')) ?></label>
            <input type="color" class="form-control form-control-color w-100" id="tk_fg" name="text_color" value="<?= e(clean_color((string) $t['text_color'], '#FFD700')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="tk_speed"><?= e(__('Speed')) ?>: <span id="tk_speed_v"><?= (int) $t['speed'] ?></span></label>
            <input type="range" class="form-range" id="tk_speed" name="speed" min="1" max="10" value="<?= (int) $t['speed'] ?>">
            <div class="d-flex justify-content-between small text-muted"><span><?= e(__('Slow')) ?></span><span><?= e(__('Fast')) ?></span></div>
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_font"><?= e(__('Font size (sp)')) ?></label>
            <input type="number" class="form-control" id="tk_font" name="font_size" min="14" max="72" value="<?= (int) $t['font_size'] ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="tk_height"><?= e(__('Bar height (dp)')) ?></label>
            <input type="number" class="form-control" id="tk_height" name="height" min="32" max="200" value="<?= (int) $t['height'] ?>">
          </div>
          <div class="col-12">
            <label class="form-label d-block"><?= e(__('Position')) ?></label>
            <div class="btn-group" role="group">
              <input type="radio" class="btn-check" name="position" value="bottom" id="tp_bottom"<?= $t['position'] !== 'top' ? ' checked' : '' ?>>
              <label class="btn btn-outline-secondary" for="tp_bottom"><i class="bi bi-layout-text-window-reverse"></i> <?= e(__('Bottom')) ?></label>
              <input type="radio" class="btn-check" name="position" value="top" id="tp_top"<?= $t['position'] === 'top' ? ' checked' : '' ?>>
              <label class="btn btn-outline-secondary" for="tp_top"><i class="bi bi-layout-text-window"></i> <?= e(__('Top')) ?></label>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="tk_reserve" name="reserve_space" value="1"<?= (int) $t['reserve_space'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="tk_reserve"><?= e(__('Do not cover the video (video shrinks)')) ?></label>
            </div>
          </div>
        </div></div>

        <div class="card mb-3 position-sticky" style="top:1rem"><div class="card-header"><?= e(__('Live preview')) ?></div><div class="card-body">
          <div id="tkPreview" class="tk-tv" aria-hidden="true">
            <div class="tk-video"><i class="bi bi-play-circle"></i><span><?= e(__('Video')) ?></span></div>
            <div class="tk-bar"><span class="tk-text"></span></div>
          </div>
          <div class="form-text"><?= e(__('Approximate: the TV scrolls at the same speed and size relative to the screen.')) ?></div>
        </div></div>
      </div>

      <div class="col-12">
        <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save ticker')) ?></button>
        <a href="<?= e(admin_url('tickers.php')) ?>" class="btn btn-light border btn-lg"><?= e(__('Cancel')) ?></a>
      </div>
    </form>
    <style>
    .tk-tv{position:relative;aspect-ratio:16/9;background:#111;border-radius:.5rem;overflow:hidden;display:flex;flex-direction:column}
    .tk-tv .tk-video{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.25rem;color:#cbd5e1;background:linear-gradient(135deg,#1e3a8a,#7b1fa2 60%,#be185d);font-size:.85rem}
    .tk-tv .tk-video i{font-size:2.4rem}
    .tk-tv .tk-bar{flex:none;position:relative;overflow:hidden;display:flex;align-items:center;font-family:"Noto Sans Gujarati","Noto Sans",system-ui,sans-serif;font-weight:600}
    .tk-tv.overlay .tk-bar{position:absolute;left:0;right:0;opacity:.92}
    .tk-tv.top{flex-direction:column-reverse}
    .tk-tv.overlay.top .tk-bar{top:0}.tk-tv.overlay:not(.top) .tk-bar{bottom:0}
    .tk-tv .tk-text{position:absolute;left:0;white-space:nowrap;will-change:transform}
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const f = document.getElementById('tickerForm');
      const tv = document.getElementById('tkPreview');
      const bar = tv.querySelector('.tk-bar');
      const txt = tv.querySelector('.tk-text');
      const $ = (id) => document.getElementById(id);
      let anim = null, last = '';
      const syncTarget = () => {
        const v = (f.querySelector('input[name=target_type]:checked') || {}).value;
        f.querySelectorAll('[data-target]').forEach((el) => { el.hidden = el.dataset.target !== v; });
      };
      const render = () => {
        const scale = tv.clientWidth / 960; // the TV is 960 dp wide
        const speed = Math.max(1, Math.min(10, parseInt($('tk_speed').value, 10) || 5));
        const font = Math.max(14, Math.min(72, parseInt($('tk_font').value, 10) || 26));
        const height = Math.max(32, Math.min(200, parseInt($('tk_height').value, 10) || 56));
        const top = (f.querySelector('input[name=position]:checked') || {}).value === 'top';
        $('tk_speed_v').textContent = speed;
        tv.classList.toggle('top', top);
        tv.classList.toggle('overlay', !$('tk_reserve').checked);
        bar.style.background = $('tk_bg').value;
        bar.style.color = $('tk_fg').value;
        bar.style.height = Math.max(height, font * 1.4) * scale + 'px';
        txt.style.fontSize = font * scale + 'px';
        txt.textContent = $('tk_message').value.replace(/\s+/g, ' ').trim() || '…';
        const key = [txt.textContent, speed, font, height, tv.clientWidth].join('|');
        if (key === last) return;
        last = key;
        if (anim) anim.cancel();
        const from = bar.clientWidth, to = -txt.scrollWidth;
        const pxPerSec = (30 + speed * 25) * scale; // same formula as the TV app
        if (txt.animate) {
          anim = txt.animate([{ transform: 'translateX(' + from + 'px)' }, { transform: 'translateX(' + to + 'px)' }],
            { duration: Math.max(500, (from - to) / pxPerSec * 1000), iterations: Infinity });
        }
      };
      f.querySelectorAll('input[name=target_type]').forEach((r) => r.addEventListener('change', syncTarget));
      f.querySelectorAll('[data-preset-bg]').forEach((b) => b.addEventListener('click', () => {
        $('tk_bg').value = b.dataset.presetBg; $('tk_fg').value = b.dataset.presetFg; render();
      }));
      f.addEventListener('input', render);
      f.addEventListener('change', render);
      window.addEventListener('resize', () => { last = ''; render(); });
      syncTarget();
      render();
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ------------------------------------------------------------------ list + overview
$tickers = Tickers::listVisible();
$now = time();
$overviewRooms = array_values($formRooms);
$overviewLimit = 300;
$overview = Tickers::overview(array_slice($overviewRooms, 0, $overviewLimit), $now);
$legacy = Tickers::legacy();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Ticker bar')) ?></h1>
    <p class="lead-sm"><?= e(__('Scrolling text on the TVs: the same for all TVs, per group or per room. The video shrinks so the bar never covers it.')) ?></p>
  </div>
  <a class="btn btn-primary" href="<?= e(admin_url('tickers.php', ['action' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New ticker')) ?></a>
</div>

<?php if ($legacy && !$restricted): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle"></i>
    <?= e(__('A ticker text from the hotel settings or a chain template is also shown on all TVs (after the tickers below):')) ?>
    <strong><?= e($legacy['message']) ?></strong>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header"><?= e(__('Tickers')) ?> <span class="badge text-bg-light border"><?= count($tickers) ?></span></div>
  <?php if (!$tickers): ?>
    <div class="card-body text-muted"><?= e(__('No tickers yet. Create one to show a scrolling message on the TVs.')) ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hc table-hover align-middle mb-0">
      <thead><tr>
        <th><?= e(__('Message')) ?></th><th><?= e(__('TVs')) ?></th><th><?= e(__('When')) ?></th><th><?= e(__('Look')) ?></th><th><?= e(__('Priority')) ?></th><th><?= e(__('State')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($tickers as $t): $state = Tickers::state($t, $now); ?>
        <tr>
          <td style="max-width:360px">
            <div class="fw-semibold text-truncate"><?= e($t['name']) ?></div>
            <div class="small text-muted text-truncate" lang="gu"><?= e($t['message']) ?></div>
          </td>
          <td><?= e(Tickers::targetName($t, $groupNames, $roomNumbers)) ?><?php if ((int) $t['override_lower']): ?> <span class="badge text-bg-warning" title="<?= e(__('Hides the less specific tickers on these TVs')) ?>"><?= e(__('Override')) ?></span><?php endif; ?></td>
          <td class="small"><?= e($whenText($t)) ?></td>
          <td><span class="badge border" style="background:<?= e(clean_color((string) $t['bg_color'])) ?>;color:<?= e(clean_color((string) $t['text_color'], '#FFD700')) ?>">Aa</span>
            <span class="small text-muted"><?= e(__($t['position'] === 'top' ? 'Top' : 'Bottom')) ?> · <?= (int) $t['speed'] ?>/10</span></td>
          <td><?= (int) $t['priority'] ?></td>
          <td><?= $stateBadge($state) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('tickers.php', ['action' => 'edit', 'id' => (int) $t['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm btn-light border" title="<?= e((int) $t['is_active'] ? __('Switch off') : __('Switch on')) ?>"><i class="bi <?= (int) $t['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
            <form method="post" class="d-inline" data-confirm="<?= e(__('Delete ticker ":n"?', ['n' => $t['name']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm btn-light border text-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><?= e(__('What each TV shows now')) ?></div>
  <?php if (!$overviewRooms): ?>
    <div class="card-body text-muted"><?= e(__('No rooms yet.')) ?></div>
  <?php else: ?>
  <div class="table-responsive" style="max-height:480px">
    <table class="table table-sm table-hc mb-0">
      <thead><tr><th><?= e(__('Room')) ?></th><th><?= e(__('Ticker text')) ?></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($overviewRooms, 0, $overviewLimit) as $r): $tk = $overview[(int) $r['id']] ?? null; ?>
        <tr data-room="<?= (int) $r['id'] ?>">
          <td class="text-nowrap"><?= e($r['room_number']) ?> <span class="text-muted small"><?= e($r['name'] ?? '') ?></span></td>
          <td lang="gu"><?php if ($tk): ?><span class="badge border me-1" style="background:<?= e($tk['bg_color']) ?>;color:<?= e($tk['text_color']) ?>">Aa</span><?= e($tk['text']) ?><?php else: ?><span class="text-muted">— <?= e(__('no ticker')) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($overviewRooms) > $overviewLimit): ?><div class="card-footer small text-muted"><?= e(__('Showing the first :n rooms.', ['n' => $overviewLimit])) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
