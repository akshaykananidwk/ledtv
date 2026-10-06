<?php
/**
 * QR setup: assign a new TV (showing a QR + 6-character code) to a room from the phone.
 *   claim.php?code=K7P2QX      → TV details, (platform users: hotel choice), room list, Assign
 *   claim.php                  → type the code (phones that cannot scan); platform admin: waiting TVs
 *   claim.php?done=<id>        → "TV will start in a few seconds" + live status (ajax claim_status)
 * Permission: rooms.manage in the hotel. Platform admins / resellers choose one of their hotels.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require();   // login (keeps ?code=… through login.php?next=)
Csrf::check();

$isPlatform = Auth::isPlatformUser();
$hotels = [];
$hid = 0;
if ($isPlatform) {
    $hotels = Auth::role() === 'platform_admin' ? Hotels::all() : Hotels::all((int) ($user['reseller_id'] ?? 0));
    $wanted = req_int('hotel');
    if ($wanted && !Auth::canAccessHotel($wanted)) {
        Logger::write('security', 'warning', 'QR setup: hotel not allowed', ['user' => Auth::id(), 'hotel' => $wanted, 'ip' => client_ip()]);
        flash('danger', __('You cannot manage this hotel.'));
        $wanted = 0;
    }
    $hid = $wanted ?: (int) (Tenant::current() ?? 0);
    if (!$wanted && count($hotels) === 1) {
        $hid = (int) $hotels[0]['id'];
    }
    // This request only: platform users act inside the chosen hotel (no session "enter").
    Tenant::set($hid ?: null);
} else {
    $hid = (int) (Tenant::current() ?? 0);
}
if (($hid && !Auth::can('rooms.manage')) || (!$isPlatform && !$hid)) {
    $GLOBALS['hc_forbidden'] = true;
    require __DIR__ . '/partials/forbidden.php';
    exit;
}

$hotelQ = $isPlatform && $hid ? ['hotel' => $hid] : [];
$missKeys = ['prov_miss_u:' . (int) Auth::id() => 10, 'prov_miss_ip:' . client_ip() => 20];

/** Rows other users must not see: only the claiming hotel's users get details. */
$canSeeClaim = static fn (array $row): bool => $row['hotel_id'] !== null && Auth::canAccessHotel((int) $row['hotel_id']);

// ---------------------------------------------------------------- POST: assign
if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $code = Provisioning::normalizeCode($_POST['code'] ?? '') ?? '';
    $back = admin_url('claim.php', array_filter(['code' => $code] + $hotelQ));
    if ($op !== 'assign' || !$hid) {
        flash('warning', $hid ? __('Unknown action.') : __('Choose the hotel first.'));
        redirect($back);
    }
    $prov = Provisioning::find(req_int('prov_id', $_POST));
    if (!$prov || $prov['code'] !== $code) {
        flash('danger', __('This setup code was not found or has expired. Start the setup again on the TV.'));
        redirect(admin_url('claim.php', $hotelQ));
    }
    if ($prov['status'] !== 'pending') {
        flash('danger', $prov['status'] === 'expired'
            ? __('This setup code has expired. The TV shows a new code after a restart of the setup.')
            : __('This TV has already been assigned to a room.'));
        redirect($back);
    }
    $problems = Provisioning::hotelProblems($hid, (string) $prov['device_uid']);
    if ($problems) {
        flash_errors($problems);
        redirect($back);
    }
    $roomSel = req_str('room', $_POST, 20);
    $room = null;
    $created = false;
    if ($roomSel === 'new') {
        // Users limited to some TVs (core/Access.php) cannot add rooms.
        Access::requireUnrestricted('room create (QR setup)');
        $number = req_str('new_room_number', $_POST, 40);
        $floor = req_str('new_floor', $_POST, 20);
        if ($number === '' || mb_strlen($number) > 20 || !preg_match('/^[\p{L}\p{N} _.-]+$/u', $number)) {
            flash('danger', __('Room number is required (max 20 letters/numbers).'));
            redirect($back);
        }
        $existing = DB::one('SELECT * FROM rooms WHERE hotel_id = :hid AND room_number = :n', ['n' => $number] + hid());
        if ($existing) {
            $room = $existing;   // typed an existing number: just use that room
        } else {
            $rid = DB::insert('rooms', [
                'room_number' => $number,
                'name' => null,
                'floor' => $floor !== '' ? $floor : (preg_match('/^(\d+)\d{2}$/', $number, $m) ? $m[1] : null),
                'created_at' => now(),
            ]);
            $room = Tenant::find('rooms', $rid);
            $created = true;
            Settings::bumpContentVersion();
            ActivityLog::add('room_create', 'room', $rid, 'Room ' . $number . ' (QR setup)');
        }
    } else {
        $room = ctype_digit($roomSel) ? Tenant::find('rooms', (int) $roomSel) : null;   // another hotel's id → 404
        if ($room) {
            Access::requireRoom((int) $room['id']);   // not one of the user's rooms → 403
        }
    }
    if (!$room) {
        flash('danger', __('Choose a room for this TV.'));
        redirect($back);
    }
    if (!Provisioning::claim((int) $prov['id'], $hid, (int) $room['id'], (int) Auth::id())) {
        flash('danger', __('This setup code has expired. The TV shows a new code after a restart of the setup.'));
        redirect($back);
    }
    ActivityLog::add('tv_qr_assign', 'room', (int) $room['id'], 'QR setup ' . $prov['code'] . ' → room ' . $room['room_number']
        . ' (' . ($prov['model'] ?: $prov['device_uid']) . ')' . ($created ? ' [new room]' : ''));
    redirect(admin_url('claim.php', ['done' => (int) $prov['id']] + $hotelQ));
}

// ---------------------------------------------------------------- GET
$codeRaw = req_str('code', $_GET, 20);
$code = Provisioning::normalizeCode($codeRaw);
$prov = null;
$codeError = null;
$done = null;

if (req_int('done', $_GET)) {
    $done = Provisioning::find(req_int('done', $_GET));
    if (!$done || !$canSeeClaim($done) || ($hid && (int) $done['hotel_id'] !== $hid)) {
        $done = null;
        $codeError = __('This setup code was not found or has expired. Start the setup again on the TV.');
    }
} elseif ($codeRaw !== '') {
    $blocked = false;
    foreach ($missKeys as $k => $max) {
        $blocked = $blocked || Provisioning::throttled($k, $max, 900);
    }
    if ($blocked) {
        $codeError = __('Too many wrong codes. Please wait 15 minutes and try again.');
    } else {
        $prov = $code !== null ? Provisioning::findByCode($code) : null;
        if (!$prov) {
            foreach ($missKeys as $k => $max) {
                RateLimiter::hit($k, $max, 900);
            }
            $codeError = __('This setup code was not found or has expired. Check the code on the TV screen.');
        } elseif ($prov['status'] === 'expired') {
            $codeError = __('This setup code has expired. The TV shows a new code after a restart of the setup.');
            $prov = null;
        } elseif ($prov['status'] !== 'pending') {
            if ($canSeeClaim($prov)) {
                $done = $prov;
                if ($isPlatform && (int) $prov['hotel_id'] !== $hid) {
                    $hid = (int) $prov['hotel_id'];
                    Tenant::set($hid);
                }
            } else {
                $codeError = __('This TV has already been assigned to a room.');
            }
            $prov = null;
        }
    }
}

$problems = [];
$rooms = [];
if ($prov && $hid) {
    $problems = Provisioning::hotelProblems($hid, (string) $prov['device_uid']);
    $rooms = Access::filterRooms(Provisioning::rooms($hid));
}
$limited = Access::restricted();
$waiting = !$prov && !$done && Auth::role() === 'platform_admin' ? Provisioning::pendingRecent(15) : [];
$doneRoom = $done && $done['room_id'] ? DB::one('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $done['room_id'], 'h' => (int) $done['hotel_id']]) : null;
$hotelName = $hid ? (string) (Settings::getFor($hid, 'hotel_name', '') ?: (DB::value('SELECT name FROM hotels WHERE id = :id', ['id' => $hid]) ?: '')) : '';

$pageTitle = __('Add TV with QR');
$activeNav = 'qr_setup';
require __DIR__ . '/partials/header.php';
?>
<style>
.qr-wrap{max-width:640px;margin:0 auto}
.qr-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:2rem;font-weight:700;letter-spacing:.3em}
.qr-code-input{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:1.8rem;letter-spacing:.35em;text-transform:uppercase;text-align:center}
.qr-room{display:flex;align-items:center;gap:.75rem;min-height:56px;padding:.65rem .9rem;cursor:pointer}
.qr-room input{width:1.4rem;height:1.4rem;flex:0 0 auto;margin:0}
.qr-room .qr-num{font-size:1.15rem;font-weight:700;min-width:4.5rem}
.qr-room:has(input:checked){background:#eef2ff;outline:2px solid var(--hc-accent,#4f46e5);outline-offset:-2px}
.qr-rooms{max-height:52vh;overflow-y:auto}
.qr-sticky{position:sticky;bottom:0;z-index:5;background:var(--bs-body-bg,#fff);padding:.75rem 0;border-top:1px solid #e5e7eb}
.qr-sticky .btn{min-height:56px;font-size:1.15rem}
.qr-status{font-size:1.2rem}
</style>
<div class="qr-wrap">
<div class="page-head">
  <div>
    <h1><i class="bi bi-qr-code-scan"></i> <?= e(__('Add TV with QR')) ?></h1>
    <p class="lead-sm"><?= e(__('Scan the QR code on the new TV with your phone camera, pick the room and press Assign. Nothing needs to be typed on the TV.')) ?></p>
  </div>
</div>
<?= flash_show() ?>
<?php if ($codeError): ?>
  <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><div><?= e($codeError) ?></div></div>
<?php endif; ?>

<?php if ($done): ?>
  <?php $doneOk = $done['status'] === 'used'; ?>
  <div class="card border-success mb-3" id="qrDone" data-id="<?= (int) $done['id'] ?>" data-status="<?= e($done['status']) ?>">
    <div class="card-body text-center py-4">
      <i class="bi bi-check-circle-fill text-success display-4"></i>
      <h2 class="h4 mt-2"><?= e(__('TV assigned to room :n', ['n' => $doneRoom['room_number'] ?? '-'])) ?></h2>
      <p class="text-muted mb-3"><?= e(__('The TV will start in a few seconds. Keep it switched on and connected to the internet.')) ?></p>
      <div class="qr-status" id="qrStatus" aria-live="polite">
        <?php if ($doneOk): ?>
          <span class="text-success fw-bold"><i class="bi bi-check2-circle"></i> <?= e(__('Registered — the TV is working.')) ?></span>
        <?php elseif ($done['status'] === 'expired'): ?>
          <span class="text-danger"><i class="bi bi-x-circle"></i> <?= e(__('The TV did not register in time. Restart the setup on the TV and scan the new code.')) ?></span>
        <?php else: ?>
          <span class="text-primary"><span class="spinner-border spinner-border-sm"></span> <?= e(__('Waiting for the TV…')) ?></span>
        <?php endif; ?>
      </div>
      <div class="small text-muted mt-2"><?= e($done['model'] ?: '') ?> <span class="mono"><?= e($done['device_uid']) ?></span></div>
    </div>
  </div>
  <div class="d-grid gap-2 mb-4">
    <a class="btn btn-primary btn-lg" href="<?= e(admin_url('claim.php', $hotelQ)) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Add another TV')) ?></a>
    <a class="btn btn-light border btn-lg" href="<?= e(admin_url('rooms.php')) ?>"><i class="bi bi-tv"></i> <?= e(__('Rooms & TVs')) ?></a>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('qrDone');
    if (!box || box.dataset.status !== 'claimed') return;
    const out = document.getElementById('qrStatus');
    const L = <?= json_embed([
        'registered' => __('Registered — the TV is working.'),
        'online' => __('Registered — the TV is online in room :n.'),
        'expired' => __('The TV did not register in time. Restart the setup on the TV and scan the new code.'),
    ]) ?>;
    const started = Date.now();
    const stop = HC.every(3000, async () => {
      const d = await HC.api('claim_status', { params: { id: box.dataset.id } });
      if (d.status === 'registered') {
        out.innerHTML = '<span class="text-success fw-bold"><i class="bi bi-check2-circle"></i> ' + HC.esc(d.online ? L.online.replace(':n', d.room_number || '') : L.registered) + '</span>';
        stop();
      } else if (d.status === 'expired' || Date.now() - started > 1800000) {
        out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + HC.esc(L.expired) + '</span>';
        stop();
      }
    }, false);
  });
  </script>

<?php elseif ($prov): ?>
  <?php $left = max(0, Provisioning::ts($prov['expires_at']) - time()); ?>
  <div class="card mb-3">
    <div class="card-body d-flex align-items-center gap-3">
      <i class="bi bi-tv fs-1 text-primary"></i>
      <div class="flex-grow-1 min-w-0">
        <div class="qr-code"><?= e($prov['code']) ?></div>
        <div class="small text-muted text-truncate"><?= e($prov['model'] ?: __('Unknown TV model')) ?><?= $prov['app_version'] ? ' · ' . e(__('App')) . ' ' . e($prov['app_version']) : '' ?></div>
        <div class="small"><i class="bi bi-hourglass-split"></i> <?= e(__('Time left')) ?>: <strong id="qrLeft" data-left="<?= (int) $left ?>"><?= e(sprintf('%d:%02d', intdiv($left, 60), $left % 60)) ?></strong></div>
      </div>
    </div>
  </div>

  <?php if ($isPlatform): ?>
  <form method="get" class="card mb-3"><div class="card-body">
    <input type="hidden" name="code" value="<?= e($prov['code']) ?>">
    <label class="form-label fw-bold" for="qrHotel"><?= e(__('Hotel')) ?></label>
    <div class="d-flex gap-2">
      <select class="form-select form-select-lg" name="hotel" id="qrHotel" onchange="this.form.submit()">
        <option value=""><?= e(__('Choose the hotel…')) ?></option>
        <?php foreach ($hotels as $h): ?>
          <option value="<?= (int) $h['id'] ?>" <?= (int) $h['id'] === $hid ? 'selected' : '' ?>><?= e($h['name']) ?><?= $h['status'] !== 'active' ? ' (' . e(__($h['status'])) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-primary"><?= e(__('OK')) ?></button></noscript>
    </div>
  </div></form>
  <?php endif; ?>

  <?php if (!$hid): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> <?= e(__('Choose the hotel first.')) ?></div>
  <?php else: ?>
    <?php if ($problems): ?>
      <div class="alert alert-danger" role="alert">
        <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> <?= e(__('This TV cannot be added right now')) ?></div>
        <ul class="mb-0 ps-3"><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <form method="post" id="qrForm">
      <?= Csrf::field() ?>
      <input type="hidden" name="op" value="assign">
      <input type="hidden" name="code" value="<?= e($prov['code']) ?>">
      <input type="hidden" name="prov_id" value="<?= (int) $prov['id'] ?>">
      <?php if ($isPlatform): ?><input type="hidden" name="hotel" value="<?= (int) $hid ?>"><?php endif; ?>
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-door-closed"></i> <?= e(__('Room for this TV')) ?><?= $isPlatform ? ' · ' . e($hotelName) : '' ?></span>
          <span class="badge text-bg-light border"><?= count($rooms) ?></span>
        </div>
        <div class="p-2 border-bottom">
          <input type="search" class="form-control form-control-lg" id="qrSearch" placeholder="<?= e(__('Search room…')) ?>" autocomplete="off" aria-label="<?= e(__('Search room…')) ?>">
        </div>
        <div class="list-group list-group-flush qr-rooms" id="qrRooms">
          <?php $shownSep = false; foreach ($rooms as $r): $hasTv = (int) $r['tv_count'] > 0; ?>
            <?php if ($hasTv && !$shownSep): $shownSep = true; ?>
              <div class="list-group-item small text-muted bg-light qr-sep"><?= e(__('Rooms that already have a TV')) ?></div>
            <?php endif; ?>
            <label class="list-group-item qr-room" data-search="<?= e(mb_strtolower($r['room_number'] . ' ' . $r['name'] . ' ' . $r['floor'])) ?>">
              <input type="radio" class="form-check-input" name="room" value="<?= (int) $r['id'] ?>" data-has-tv="<?= $hasTv ? '1' : '0' ?>">
              <span class="qr-num"><?= e($r['room_number']) ?></span>
              <span class="flex-grow-1 small text-muted text-truncate"><?= e($r['name'] ?: '') ?><?= $r['floor'] !== null && $r['floor'] !== '' ? ' · ' . e(__('Floor')) . ' ' . e($r['floor']) : '' ?></span>
              <?php if ($hasTv): ?>
                <span class="badge text-bg-warning"><i class="bi bi-tv"></i> <?= e(__('Has TV')) ?></span>
              <?php else: ?>
                <span class="badge text-bg-success"><?= e(__('No TV yet')) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
          <?php if (!$rooms): ?>
            <div class="list-group-item text-muted"><?= e(__('No rooms yet. Create the room below.')) ?></div>
          <?php endif; ?>
          <?php if (!$limited): ?>
          <label class="list-group-item qr-room">
            <input type="radio" class="form-check-input" name="room" value="new" id="qrNew" <?= !$rooms ? 'checked' : '' ?>>
            <span class="fw-bold"><i class="bi bi-plus-circle"></i> <?= e(__('Create new room')) ?></span>
          </label>
          <?php endif; ?>
        </div>
        <div class="card-body border-top <?= $rooms || $limited ? 'd-none' : '' ?>" id="qrNewFields">
          <div class="row g-2">
            <div class="col-7">
              <label class="form-label" for="qrNewNumber"><?= e(__('Room number')) ?> *</label>
              <input class="form-control form-control-lg" id="qrNewNumber" name="new_room_number" maxlength="20" placeholder="101">
            </div>
            <div class="col-5">
              <label class="form-label" for="qrNewFloor"><?= e(__('Floor')) ?></label>
              <input class="form-control form-control-lg" id="qrNewFloor" name="new_floor" maxlength="20" placeholder="<?= e(__('auto')) ?>">
            </div>
          </div>
        </div>
      </div>
      <div class="alert alert-warning d-none" id="qrReplace"><i class="bi bi-exclamation-triangle"></i>
        <?= e(__('This room already has a TV. The new TV is added to the room; if it replaces the old TV, revoke the old one in Rooms & TVs afterwards.')) ?></div>
      <div class="qr-sticky">
        <button class="btn btn-primary w-100" id="qrAssign" <?= $problems ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i> <?= e(__('Assign')) ?></button>
      </div>
    </form>
  <?php endif; ?>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const leftEl = document.getElementById('qrLeft');
    if (leftEl) {
      let left = parseInt(leftEl.dataset.left, 10) || 0;
      const end = Date.now() + left * 1000;
      const tick = () => {
        left = Math.max(0, Math.round((end - Date.now()) / 1000));
        leftEl.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
        if (left <= 0) {
          leftEl.textContent = <?= json_embed(__('expired')) ?>;
          const b = document.getElementById('qrAssign');
          if (b) b.disabled = true;
          return;
        }
        setTimeout(tick, 1000);
      };
      tick();
    }
    const form = document.getElementById('qrForm');
    if (!form) return;
    const search = document.getElementById('qrSearch');
    const items = Array.from(form.querySelectorAll('#qrRooms label[data-search]'));
    search && search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      items.forEach((el) => { el.classList.toggle('d-none', q !== '' && !el.dataset.search.includes(q)); });
    });
    const newFields = document.getElementById('qrNewFields');
    const replace = document.getElementById('qrReplace');
    form.addEventListener('change', (ev) => {
      if (ev.target.name !== 'room') return;
      const isNew = ev.target.value === 'new';
      newFields.classList.toggle('d-none', !isNew);
      replace.classList.toggle('d-none', ev.target.dataset.hasTv !== '1');
      if (isNew) document.getElementById('qrNewNumber').focus();
    });
    form.addEventListener('submit', (ev) => {
      const sel = form.querySelector('input[name="room"]:checked');
      if (!sel || (sel.value === 'new' && document.getElementById('qrNewNumber').value.trim() === '')) {
        ev.preventDefault();
        HC.toast(<?= json_embed(__('Choose a room for this TV.')) ?>, 'danger');
      }
    });
  });
  </script>

<?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <ol class="mb-3 ps-3">
        <li><?= e(__('Install the Krishna Cloud LED TV app on the TV and open it. The TV shows a QR code and a 6-character code.')) ?></li>
        <li><?= e(__('Scan the QR code with your phone camera — this page opens with the TV already selected.')) ?></li>
        <li><?= e(__('Or type the code from the TV screen here:')) ?></li>
      </ol>
      <form method="get" class="d-grid gap-2">
        <?php if ($isPlatform && $hid): ?><input type="hidden" name="hotel" value="<?= (int) $hid ?>"><?php endif; ?>
        <label class="visually-hidden" for="qrCodeInput"><?= e(__('Setup code')) ?></label>
        <input class="form-control form-control-lg qr-code-input" id="qrCodeInput" name="code" value="<?= e($codeRaw) ?>" maxlength="7" minlength="6" required
               autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="······" pattern="[A-Za-z0-9 -]{6,7}">
        <button class="btn btn-primary btn-lg"><i class="bi bi-search"></i> <?= e(__('Find TV')) ?></button>
      </form>
    </div>
  </div>

  <?php if (Auth::role() === 'platform_admin'): ?>
  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-hourglass-split"></i> <?= e(__('TVs waiting for setup')) ?> <span class="small text-muted">(<?= e(__('last 15 minutes, all hotels')) ?>)</span></div>
    <div class="list-group list-group-flush">
      <?php if (!$waiting): ?><div class="list-group-item text-muted"><?= e(__('No TV is waiting right now.')) ?></div><?php endif; ?>
      <?php foreach ($waiting as $w): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="<?= e(admin_url('claim.php', ['code' => $w['code']] + $hotelQ)) ?>">
          <span class="qr-code fs-5"><?= e($w['code']) ?></span>
          <span class="flex-grow-1 small text-truncate"><?= e($w['model'] ?: __('Unknown TV model')) ?><br><span class="text-muted"><?= e($w['ip'] ?? '') ?> · <?= e(__(':m min ago', ['m' => max(0, intdiv(time() - Provisioning::ts($w['created_at']), 60))])) ?></span></span>
          <i class="bi bi-chevron-right"></i>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
