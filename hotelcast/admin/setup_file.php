<?php
/**
 * Bulk TV setup file (#23): tvs.csv for tools/windows/HotelCast-Setup.ps1.
 *
 *   # comment lines
 *   server,<installation URL>
 *   key,<registration key>
 *   tv_address,room
 *   <last known LAN IP of the room's TV, or blank>,<room number>
 *
 * GET  setup_file.php                 → page to choose rooms
 * GET  setup_file.php?download=all    → file with every room
 * POST setup_file.php (room_ids[])    → file with the selected rooms
 * Manager+ (devices.setup). The file contains the registration key: treat it like a password.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('devices.setup');
Csrf::check();

/** Value safe for the setup tool's naive "split on comma" parser and for its quoted adb shell use. */
function setup_cell(string $v): string
{
    return trim(str_replace([',', "\r", "\n", "'", '"', '`', '\\', '#'], ' ', $v));
}

/** The CSV text for a list of room rows (current hotel). */
function setup_file_text(array $rooms): string
{
    $ips = [];
    if ($rooms) {
        [$in, $p] = DB::in(array_map(static fn ($r) => (int) $r['id'], $rooms), 'r');
        foreach (DB::all("SELECT room_id, ip_address FROM devices WHERE hotel_id = :hid AND is_revoked = 0 AND room_id IN $in AND ip_address IS NOT NULL ORDER BY last_ping IS NULL, last_ping DESC, id DESC", $p + hid()) as $d) {
            $ips[(int) $d['room_id']] ??= (string) $d['ip_address'];
        }
    }
    $hotel = setup_cell((string) Settings::get('hotel_name', ''));
    $lines = [
        '# Krishna Cloud TV Management bulk setup file - ' . $hotel . ' - ' . date('Y-m-d H:i'),
        '# Contains the registration key of this customer: keep it private and delete it after the setup.',
        '# One TV per line: tv_address,room (room = screen name / ID). tv_address = TV IP (port 5555 is used) or IP:PORT shown in',
        '# Wireless debugging (Android 11+). Fill in the address where it is blank - rows without one are skipped.',
        'server,' . setup_cell(rtrim(base_url(), '/')),
        'key,' . setup_cell((string) Settings::get('registration_key', '')),
        'tv_address,room',
    ];
    foreach ($rooms as $r) {
        $room = setup_cell((string) $r['room_number']);
        if ($room === '') {
            continue;
        }
        $ip = $ips[(int) $r['id']] ?? '';
        $lines[] = (filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '') . ',' . $room;
    }
    return implode("\r\n", $lines) . "\r\n";
}

$key = (string) Settings::get('registration_key', '');
$download = null;
if (($_GET['download'] ?? '') === 'all') {
    $download = hc_rooms();
} elseif (is_post()) {
    $ids = Tenant::assertOwnsAll('rooms', int_ids($_POST['room_ids'] ?? []));
    Access::requireTargetList('rooms', $ids); // users limited to some TVs: only their rooms (hc_rooms() is limited too)
    $download = array_values(array_filter(hc_rooms(), static fn ($r) => in_array((int) $r['id'], $ids, true)));
    if (!$download) {
        flash('warning', __('Select at least one screen.'));
        redirect(admin_url('setup_file.php'));
    }
}
if ($download !== null) {
    if ($key === '') {
        flash('danger', __('Set a registration key first (Settings → Devices).'));
        redirect(admin_url('setup_file.php'));
    }
    ActivityLog::add('setup_file', 'room', null, 'Downloaded TV setup file (' . count($download) . ' screens)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tvs.csv"');
    header('Cache-Control: no-store');
    echo setup_file_text($download);
    exit;
}

$rooms = hc_rooms();
$devMap = hc_devices_by_room();
$pageTitle = __('TV setup file');
$activeNav = 'rooms';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-filetype-csv"></i> <?= e(__('TV setup file')) ?></h1>
    <p class="lead-sm"><?= e(__('tvs.csv for the Windows bulk setup tool (tools/windows/KrishnaCloud-Setup.bat).')) ?></p></div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(admin_url('rooms.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    <?php if ($key !== '' && $rooms): ?><a href="<?= e(admin_url('setup_file.php', ['download' => 'all'])) ?>" class="btn btn-primary"><i class="bi bi-download"></i> <?= e(__('Download for all screens')) ?></a><?php endif; ?>
  </div>
</div>
<?php if ($key === ''): ?>
  <div class="alert alert-warning"><?= e(__('Set a registration key first (Settings → Devices).')) ?></div>
<?php endif; ?>
<div class="hint-box mb-3 small">
  <ol class="mb-0 ps-3">
    <li><?= e(__('Download the file and put it next to KrishnaCloud-Setup.bat and the TV app APK on a Windows PC in the same network as the TVs.')) ?></li>
    <li><?= e(__('Fill in the IP address of each TV where it is blank (TV: Settings → Network). Android 11+ TVs: use IP:PORT from Developer options → Wireless debugging.')) ?></li>
    <li><?= e(__('Run KrishnaCloud-Setup.bat. It installs the app and registers every TV with this server and its screen name / ID.')) ?></li>
  </ol>
  <div class="mt-2 text-danger"><i class="bi bi-shield-lock"></i> <?= e(__('The file contains the registration key. Keep it private and delete it after the setup.')) ?></div>
</div>
<?php if (!$rooms): ?>
  <p class="text-muted"><?= e(__('No screens yet.')) ?></p>
<?php else: ?>
<form method="post" class="card">
  <?= Csrf::field() ?>
  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
    <span class="me-auto"><?= e(__('Selected screens')) ?></span>
    <button type="button" class="btn btn-sm btn-light border" data-sel="1"><?= e(__('Select all')) ?></button>
    <button type="button" class="btn btn-sm btn-light border" data-sel="0"><?= e(__('Clear')) ?></button>
  </div>
  <div class="card-body">
    <div class="room-check-grid">
      <?php foreach ($rooms as $r): $d = ($devMap[(int) $r['id']] ?? [])[0] ?? null; ?>
        <input type="checkbox" class="btn-check" name="room_ids[]" value="<?= (int) $r['id'] ?>" id="sf_<?= (int) $r['id'] ?>" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="sf_<?= (int) $r['id'] ?>" title="<?= e($d['ip_address'] ?? __('No TV')) ?>"><?= e($r['room_number']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-footer"><button class="btn btn-primary"<?= $key === '' ? ' disabled' : '' ?>><i class="bi bi-download"></i> <?= e(__('Download for selected screens')) ?></button></div>
</form>
<script>
document.querySelectorAll('[data-sel]').forEach((b) => b.addEventListener('click', () => {
  document.querySelectorAll('input[name="room_ids[]"]').forEach((c) => { c.checked = b.dataset.sel === '1'; });
}));
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
