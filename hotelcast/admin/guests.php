<?php
/**
 * Front desk (#1 #9 #10): room board (vacant / occupied), check-in, check-out, edit, room move,
 * guest link, stay history. Role reception+ (guests.manage).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('guests.manage');
Csrf::check();
if (!Guests::enabled()) {
    http_response_code(403);
    $GLOBALS['hc_forbidden'] = true;
    require __DIR__ . '/partials/forbidden.php';
    exit;
}

/** Users limited to some TVs (core/Access.php): 403 for a stay in another room. */
$stayRoom = static function (int $stayId): void {
    $stay = Guests::findStay($stayId);
    if ($stay) {
        Access::requireRoom((int) $stay['room_id']);
    }
};

if (is_post()) {
    $op = req_str('op', $_POST, 30);
    $back = admin_url('guests.php', array_filter(['tab' => req_str('tab', $_POST, 10)]));
    try {
        switch ($op) {
            case 'checkin':
                $roomId = req_int('room_id', $_POST);
                if (Tenant::find('rooms', $roomId)) {
                    Access::requireRoom($roomId);
                }
                $in = [
                    'salutation' => req_str('salutation', $_POST, 20),
                    'guest_name' => req_str('guest_name', $_POST, 200),
                    'language' => req_str('language', $_POST, 5),
                    'phone' => req_str('phone', $_POST, 40),
                    'checkout_at' => req_str('checkout_at', $_POST, 30),
                    'notes' => req_str('notes', $_POST, 1000),
                ];
                if (Auth::can('guests.setup')) {
                    $in['wifi_password'] = req_str('wifi_password', $_POST, 100);
                }
                Guests::checkIn($roomId, $in, 'manual', Auth::id());
                flash('success', __('Guest checked in. The TV shows the welcome screen within a few seconds.'));
                break;

            case 'checkout':
                $stayId = req_int('stay_id', $_POST);
                $stayRoom($stayId);
                if (!Guests::findStay($stayId)) {
                    throw new InvalidArgumentException(__('Stay not found.'));
                }
                $ok = Guests::checkOut($stayId);
                flash($ok ? 'success' : 'info', $ok ? __('Guest checked out.') : __('This guest was already checked out.'));
                break;

            case 'update':
                $stayId = req_int('stay_id', $_POST);
                $stayRoom($stayId);
                $in = [
                    'salutation' => req_str('salutation', $_POST, 20),
                    'guest_name' => req_str('guest_name', $_POST, 200),
                    'language' => req_str('language', $_POST, 5),
                    'checkout_at' => req_str('checkout_at', $_POST, 30),
                    'notes' => req_str('notes', $_POST, 1000),
                    'balance_text' => req_str('balance_text', $_POST, 255),
                ];
                $phone = req_str('phone', $_POST, 40);
                if ($phone !== '' || !empty($_POST['remove_phone'])) {
                    $in['phone'] = !empty($_POST['remove_phone']) ? '' : $phone;
                }
                if (Auth::can('guests.setup')) {
                    $in['wifi_password'] = req_str('wifi_password', $_POST, 100);
                }
                Guests::update($stayId, $in);
                flash('success', __('Saved.'));
                break;

            case 'move':
                $stayRoom(req_int('stay_id', $_POST));
                if (Tenant::find('rooms', req_int('to_room_id', $_POST))) {
                    Access::requireRoom(req_int('to_room_id', $_POST));
                }
                Guests::move(req_int('stay_id', $_POST), req_int('to_room_id', $_POST));
                flash('success', __('Guest moved. Both TVs are updated.'));
                break;

            case 'welcome':
                $room = Tenant::find('rooms', req_int('room_id', $_POST));
                if ($room) {
                    Access::requireRoom((int) $room['id']);
                    $n = Broadcaster::queueForRooms([$room], 'SHOW_WELCOME');
                    flash('success', __('Welcome screen sent to :n TV(s).', ['n' => $n]));
                }
                break;

            case 'new_link':
                $room = Tenant::find('rooms', req_int('room_id', $_POST));
                if ($room) {
                    Access::requireRoom((int) $room['id']);
                    $stay = Guests::activeStay((int) $room['id']);
                    Guests::rotateToken((int) $room['id'], $stay ? (int) $stay['id'] : null);
                    Guests::refreshRoom($room);
                    ActivityLog::add('guest_link_rotate', 'room', (int) $room['id'], 'Room ' . $room['room_number']);
                    flash('success', __('A new guest link was created. The old QR code no longer works.'));
                }
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'ROOM_OCCUPIED') {
            throw $e;
        }
        flash('danger', __('This room is already occupied. Check the current guest out first.'));
    }
    redirect($back);
}

$tab = ($_GET['tab'] ?? '') === 'history' ? 'history' : 'board';
$rooms = hc_rooms(); // users limited to some TVs: only their rooms
$stays = Guests::activeStays();
if (Access::restricted()) {
    $stays = array_intersect_key($stays, array_flip(array_map(static fn ($r) => (int) $r['id'], $rooms)));
}
$langs = I18n::GUEST_LANGUAGES;
$today = date('Y-m-d');
$canSetup = Auth::can('guests.setup');
$servicesOn = GuestServices::enabled();

$openOrders = [];
if ($servicesOn) {
    foreach (DB::all("SELECT room_id, COUNT(*) AS n FROM guest_orders WHERE hotel_id = :hid AND status IN ('new','accepted','preparing') GROUP BY room_id", hid()) as $r) {
        $openOrders[(int) $r['room_id']] = (int) $r['n'];
    }
}
$checkoutsToday = count(array_filter($stays, fn ($s) => $s['expected_checkout_at'] && substr((string) $s['expected_checkout_at'], 0, 10) <= $today));
$checkinsToday = (int) DB::value('SELECT COUNT(*) FROM guest_stays WHERE hotel_id = :hid AND checkin_at >= :d', hid() + ['d' => $today . ' 00:00:00']);
$defaultCheckout = date('Y-m-d', time() + 86400) . 'T' . substr((string) (Broadcaster::parseTime(Guests::setting('guest_checkout_time')) ?? '10:00:00'), 0, 5);

// History
$history = [];
$histTotal = 0;
$page = max(1, req_int('page', $_GET));
$q = req_str('q', $_GET, 100);
if ($tab === 'history') {
    $where = 's.hotel_id = :hid';
    $params = hid();
    [$acc, $ap] = Access::roomSql('s.room_id');
    $where .= $acc;
    $params += $ap;
    if ($q !== '') {
        $where .= ' AND (s.guest_name LIKE :q OR r.room_number = :qr OR s.external_ref = :qe)';
        $params += ['q' => '%' . addcslashes($q, '%_\\') . '%', 'qr' => $q, 'qe' => $q];
    }
    $histTotal = (int) DB::value("SELECT COUNT(*) FROM guest_stays s LEFT JOIN rooms r ON r.id = s.room_id AND r.hotel_id = s.hotel_id WHERE $where", $params);
    $history = DB::all(
        "SELECT s.*, r.room_number,
                (SELECT COALESCE(SUM(o.total), 0) FROM guest_orders o WHERE o.hotel_id = s.hotel_id AND o.stay_id = s.id AND o.status <> 'cancelled') AS charges
         FROM guest_stays s LEFT JOIN rooms r ON r.id = s.room_id AND r.hotel_id = s.hotel_id
         WHERE $where ORDER BY s.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50),
        $params
    );
}

$fmt = fn (?string $d) => $d ? date('d M, h:i A', (int) strtotime($d)) : '—';
$pageTitle = __('Front desk');
$activeNav = 'guests';
$extraScripts = ['js/guests-frontdesk.js'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-person-vcard"></i> <?= e(__('Front desk')) ?></h1>
    <p class="lead-sm"><?= e(__('Check guests in and out. The room TV greets the guest by name and shows the room-service QR code.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($servicesOn && Auth::can('services.manage')): ?><a class="btn btn-outline-primary" href="<?= e(admin_url('orders.php')) ?>"><i class="bi bi-bell"></i> <?= e(__('Orders & requests')) ?></a><?php endif; ?>
    <?php if ($canSetup): ?><a class="btn btn-outline-secondary" href="<?= e(admin_url('services_setup.php')) ?>"><i class="bi bi-gear"></i> <?= e(__('Setup')) ?></a><?php endif; ?>
  </div>
</div>

<?php if (Guests::checkinMode()): ?>
<div class="alert alert-info small py-2"><i class="bi bi-info-circle"></i>
  <?= e(Guests::vacantMode() === 'off' ? __('Check-in mode is on: TVs of vacant rooms are switched off.') : (Guests::vacantMode() === 'welcome' ? __('Check-in mode is on: TVs of vacant rooms show the welcome screen.') : __('Check-in mode is on.'))) ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2">
    <div class="text-muted small"><?= e(__('Occupied')) ?></div><div class="fs-3 fw-bold"><?= count($stays) ?> <small class="fs-6 text-muted">/ <?= count($rooms) ?></small></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2">
    <div class="text-muted small"><?= e(__('Vacant')) ?></div><div class="fs-3 fw-bold"><?= max(0, count($rooms) - count($stays)) ?></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2">
    <div class="text-muted small"><?= e(__('Check-ins today')) ?></div><div class="fs-3 fw-bold"><?= $checkinsToday ?></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2">
    <div class="text-muted small"><?= e(__('Checkouts due today')) ?></div><div class="fs-3 fw-bold<?= $checkoutsToday ? ' text-danger' : '' ?>"><?= $checkoutsToday ?></div>
  </div></div></div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'board' ? ' active' : '' ?>" href="<?= e(admin_url('guests.php')) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e(__('Room board')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'history' ? ' active' : '' ?>" href="<?= e(admin_url('guests.php', ['tab' => 'history'])) ?>"><i class="bi bi-clock-history"></i> <?= e(__('History')) ?></a></li>
</ul>

<?php if ($tab === 'board'): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <input type="search" class="form-control" style="max-width:18rem" id="gdSearch" placeholder="<?= e(__('Search room or guest…')) ?>" aria-label="<?= e(__('Search room or guest…')) ?>">
  <div class="btn-group" role="group" aria-label="<?= e(__('Filter')) ?>">
    <input type="radio" class="btn-check" name="gdFilter" id="gdfAll" value="all" checked><label class="btn btn-outline-secondary btn-sm" for="gdfAll"><?= e(__('All')) ?></label>
    <input type="radio" class="btn-check" name="gdFilter" id="gdfOcc" value="occupied"><label class="btn btn-outline-secondary btn-sm" for="gdfOcc"><?= e(__('Occupied')) ?></label>
    <input type="radio" class="btn-check" name="gdFilter" id="gdfVac" value="vacant"><label class="btn btn-outline-secondary btn-sm" for="gdfVac"><?= e(__('Vacant')) ?></label>
    <input type="radio" class="btn-check" name="gdFilter" id="gdfDue" value="due"><label class="btn btn-outline-secondary btn-sm" for="gdfDue"><?= e(__('Checkout today')) ?></label>
  </div>
</div>

<?php if (!$rooms): ?>
  <div class="card"><div class="card-body text-center text-muted py-5"><?= e(__('No screens yet. Add them on the Screens & TVs page.')) ?></div></div>
<?php endif; ?>
<div class="row g-2" id="gdBoard">
<?php foreach ($rooms as $r):
    $s = $stays[(int) $r['id']] ?? null;
    $due = $s && $s['expected_checkout_at'] && substr((string) $s['expected_checkout_at'], 0, 10) <= $today;
    $search = mb_strtolower($r['room_number'] . ' ' . ($r['name'] ?? '') . ' ' . ($s['guest_name'] ?? ''));
    $stayJson = $s ? [
        'id' => (int) $s['id'], 'room' => (string) $r['room_number'], 'salutation' => $s['salutation'], 'guest_name' => $s['guest_name'],
        'language' => $s['language'], 'phone_masked' => Guests::maskPhone($s['phone']),
        'checkout_at' => $s['expected_checkout_at'] ? date('Y-m-d\TH:i', (int) strtotime((string) $s['expected_checkout_at'])) : '',
        'notes' => (string) ($s['notes'] ?? ''), 'balance_text' => (string) ($s['balance_text'] ?? ''),
        'wifi_password' => $canSetup ? (string) ($s['wifi_password'] ?? '') : '',
    ] : null;
?>
  <div class="col-6 col-md-4 col-xl-3 col-xxl-2" data-gd-room data-state="<?= $s ? 'occupied' : 'vacant' ?>" data-due="<?= $due ? '1' : '0' ?>" data-search="<?= e($search) ?>">
    <div class="card h-100 <?= $s ? ($due ? 'border-danger' : 'border-primary') : '' ?>">
      <div class="card-body p-2 d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-1">
          <span class="fs-5 fw-bold lh-sm"><?= e($r['room_number']) ?></span>
          <?php if ($s): ?><span class="badge text-bg-primary"><?= e(__('Occupied')) ?></span><?php else: ?><span class="badge text-bg-light border text-muted"><?= e(__('Vacant')) ?></span><?php endif; ?>
        </div>
        <?php if ($r['floor'] !== null && $r['floor'] !== ''): ?><div class="small text-muted lh-sm"><?= e(__('Floor')) ?> <?= e($r['floor']) ?></div><?php endif; ?>
        <?php if ($s): ?>
          <div class="fw-semibold text-truncate mt-1" title="<?= e(trim($s['salutation'] . ' ' . $s['guest_name'])) ?>"><?= e(trim($s['salutation'] . ' ' . $s['guest_name'])) ?></div>
          <div class="small text-muted">
            <span class="badge text-bg-light border"><?= e(strtoupper((string) $s['language'])) ?></span>
            <?php if ($s['phone']): ?><span title="<?= e(__('Phone')) ?>"><i class="bi bi-telephone"></i> <?= e(Guests::maskPhone($s['phone'])) ?></span><?php endif; ?>
            <?php if (!empty($openOrders[(int) $r['id']])): ?><span class="badge text-bg-warning"><i class="bi bi-bag"></i> <?= (int) $openOrders[(int) $r['id']] ?></span><?php endif; ?>
          </div>
          <div class="small <?= $due ? 'text-danger fw-semibold' : 'text-muted' ?>"><i class="bi bi-box-arrow-right"></i> <?= e($fmt($s['expected_checkout_at'])) ?></div>
          <div class="mt-auto pt-2 d-flex flex-wrap gap-1">
            <form method="post" class="d-inline">
              <?= Csrf::field() ?><input type="hidden" name="op" value="checkout"><input type="hidden" name="stay_id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-danger" data-confirm="<?= e(__('Check out :g from room :r?', ['g' => trim($s['salutation'] . ' ' . $s['guest_name']), 'r' => $r['room_number']])) ?>" data-confirm-safe="1"><i class="bi bi-box-arrow-right"></i> <?= e(__('Check out')) ?></button>
            </form>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-gd-edit="<?= e(json_embed($stayJson)) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-gd-move="<?= e(json_embed($stayJson)) ?>" title="<?= e(__('Move to another room')) ?>"><i class="bi bi-arrow-left-right"></i></button>
            <div class="dropdown d-inline">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e(__('More')) ?>"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu">
                <li><form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="welcome"><input type="hidden" name="room_id" value="<?= (int) $r['id'] ?>"><button class="dropdown-item"><i class="bi bi-tv me-2"></i><?= e(__('Show welcome on TV again')) ?></button></form></li>
                <?php if ($servicesOn): ?>
                <li><button type="button" class="dropdown-item" data-copy="<?= e(Guests::guestUrl(Guests::roomToken((int) $r['id']))) ?>"><i class="bi bi-link-45deg me-2"></i><?= e(__('Copy guest link')) ?></button></li>
                <li><form method="post" class="m-0"><?= Csrf::field() ?><input type="hidden" name="op" value="new_link"><input type="hidden" name="room_id" value="<?= (int) $r['id'] ?>"><button class="dropdown-item" data-confirm="<?= e(__('Create a new guest link? The current QR code stops working.')) ?>"><i class="bi bi-arrow-repeat me-2"></i><?= e(__('New guest link')) ?></button></form></li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        <?php else: ?>
          <div class="small text-muted text-truncate"><?= e($r['name'] ?? '') ?></div>
          <div class="mt-auto pt-2">
            <button type="button" class="btn btn-sm btn-success w-100" data-gd-checkin="<?= e(json_embed(['id' => (int) $r['id'], 'room' => (string) $r['room_number']])) ?>"><i class="bi bi-box-arrow-in-right"></i> <?= e(__('Check in')) ?></button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Check-in / edit modal -->
<div class="modal fade" id="gdStayModal" tabindex="-1" aria-labelledby="gdStayTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post" autocomplete="off">
      <?= Csrf::field() ?>
      <input type="hidden" name="op" value="checkin" data-f="op">
      <input type="hidden" name="room_id" value="" data-f="room_id">
      <input type="hidden" name="stay_id" value="" data-f="stay_id">
      <div class="modal-header">
        <h5 class="modal-title" id="gdStayTitle"><span data-title-checkin><?= e(__('Check in')) ?></span><span data-title-edit hidden><?= e(__('Edit guest')) ?></span> · <?= e(__('Room')) ?> <span data-f-text="room"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label" for="gdSal"><?= e(__('Title')) ?></label>
            <select class="form-select" name="salutation" id="gdSal" data-f="salutation">
              <?php foreach (Guests::SALUTATIONS as $sal): ?><option value="<?= e($sal) ?>"><?= e($sal === '' ? '—' : __($sal)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-8">
            <label class="form-label" for="gdName"><?= e(__('Guest name')) ?> *</label>
            <input class="form-control" name="guest_name" id="gdName" maxlength="120" required data-f="guest_name" placeholder="<?= e(__('e.g. Rajesh Shah')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="gdLang"><?= e(__('TV language')) ?></label>
            <select class="form-select" name="language" id="gdLang" data-f="language">
              <?php foreach ($langs as $code => $label): ?><option value="<?= e($code) ?>"<?= $code === Guests::hotelLang() ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" for="gdPhone"><?= e(__('Phone (optional)')) ?></label>
            <input class="form-control" name="phone" id="gdPhone" type="tel" maxlength="20" inputmode="tel" data-f="phone">
            <div class="form-text" data-phone-hint hidden><?= e(__('Saved:')) ?> <span data-f-text="phone_masked"></span> — <?= e(__('leave empty to keep')) ?>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_phone" value="1" id="gdRmPhone"><label class="form-check-label" for="gdRmPhone"><?= e(__('Remove phone')) ?></label></div>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label" for="gdOut"><?= e(__('Expected checkout')) ?></label>
            <input class="form-control" type="datetime-local" name="checkout_at" id="gdOut" data-f="checkout_at" data-default="<?= e($defaultCheckout) ?>">
          </div>
          <div class="col-12" data-edit-only hidden>
            <label class="form-label" for="gdBal"><?= e(__('Bill summary on checkout day (optional)')) ?></label>
            <input class="form-control" name="balance_text" id="gdBal" maxlength="255" data-f="balance_text" placeholder="<?= e(__('e.g. ₹ 4,500 due')) ?>">
          </div>
          <?php if ($canSetup): ?>
          <div class="col-12">
            <label class="form-label" for="gdWifi"><?= e(__('Personal Wi-Fi password (optional)')) ?></label>
            <input class="form-control" name="wifi_password" id="gdWifi" maxlength="100" data-f="wifi_password">
          </div>
          <?php endif; ?>
          <div class="col-12">
            <label class="form-label" for="gdNotes"><?= e(__('Notes (internal)')) ?></label>
            <textarea class="form-control" name="notes" id="gdNotes" rows="2" maxlength="1000" data-f="notes"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button class="btn btn-primary"><i class="bi bi-check2"></i> <?= e(__('Save')) ?></button>
      </div>
    </form>
  </div></div>
</div>

<!-- Room move modal -->
<div class="modal fade" id="gdMoveModal" tabindex="-1" aria-labelledby="gdMoveTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post">
      <?= Csrf::field() ?><input type="hidden" name="op" value="move"><input type="hidden" name="stay_id" value="" data-f="stay_id">
      <div class="modal-header">
        <h5 class="modal-title" id="gdMoveTitle"><?= e(__('Move guest')) ?> · <span data-f-text="guest_name"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="gdTo"><?= e(__('New room')) ?></label>
        <select class="form-select" name="to_room_id" id="gdTo" required>
          <?php foreach ($rooms as $r): if (isset($stays[(int) $r['id']])) { continue; } ?>
            <option value="<?= (int) $r['id'] ?>"><?= e($r['room_number']) ?><?= $r['name'] ? ' · ' . e($r['name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><?= e(__('Only vacant rooms are listed. Open orders and requests move with the guest.')) ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button class="btn btn-primary"><i class="bi bi-arrow-left-right"></i> <?= e(__('Move')) ?></button>
      </div>
    </form>
  </div></div>
</div>

<?php else: ?>
<form class="d-flex gap-2 mb-3" method="get">
  <input type="hidden" name="tab" value="history">
  <input type="search" class="form-control" style="max-width:20rem" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Guest name, room or booking ref')) ?>" aria-label="<?= e(__('Search')) ?>">
  <button class="btn btn-outline-primary"><i class="bi bi-search"></i> <?= e(__('Search')) ?></button>
</form>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th><?= e(__('Room')) ?></th><th><?= e(__('Guest')) ?></th><th><?= e(__('Phone')) ?></th><th><?= e(__('Check-in')) ?></th><th><?= e(__('Checkout')) ?></th>
        <th><?= e(__('Source')) ?></th><th class="text-end"><?= e(__('Room service')) ?></th>
      </tr></thead>
      <tbody>
      <?php if (!$history): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= e(__('No stays found.')) ?></td></tr><?php endif; ?>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="fw-semibold"><?= e($h['room_number'] ?? '—') ?></td>
          <td><?= $h['pii_deleted_at'] ? '<span class="text-muted fst-italic">' . e(__('(deleted for privacy)')) . '</span>' : e(trim($h['salutation'] . ' ' . $h['guest_name'])) ?>
            <span class="badge text-bg-light border"><?= e(strtoupper((string) $h['language'])) ?></span>
            <?php if ($h['external_ref']): ?><div class="small text-muted"><?= e(__('Ref')) ?>: <?= e($h['external_ref']) ?></div><?php endif; ?></td>
          <td class="small"><?= e(Guests::maskPhone($h['phone'])) ?></td>
          <td class="small text-nowrap"><?= e($fmt($h['checkin_at'])) ?></td>
          <td class="small text-nowrap"><?= $h['checked_out_at'] ? e($fmt($h['checked_out_at'])) : '<span class="badge text-bg-primary">' . e(__('In house')) . '</span>' ?></td>
          <td class="small"><?= e(match ($h['source']) { 'pms' => __('PMS'), 'api' => __('API'), default => __('Front desk') }) ?></td>
          <td class="text-end text-nowrap"><?= (float) $h['charges'] > 0 ? e(money($h['charges'], 'INR')) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($histTotal > 50): ?><div class="card-footer"><?= paginate($histTotal, $page, 50) ?></div><?php endif; ?>
</div>
<p class="small text-muted mt-2"><i class="bi bi-shield-lock"></i> <?= e(__('Guest names and phone numbers are deleted automatically :d days after checkout.', ['d' => (int) Guests::setting('guest_retention_days')])) ?></p>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
