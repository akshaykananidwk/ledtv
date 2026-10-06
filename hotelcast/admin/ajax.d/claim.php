<?php
/**
 * AJAX actions with the prefix "claim_" (QR setup, admin/claim.php):
 *   GET admin/ajax.php?action=claim_status&id=<provisioning id>
 *     → {status: waiting|registered|expired, room_number, online}
 * Only for users who may manage the hotel the code was claimed into (rooms.manage there).
 */
declare(strict_types=1);

if ($action === 'claim_status') {
    $row = Provisioning::find(req_int('id', $in));
    $hid = $row && $row['hotel_id'] ? (int) $row['hotel_id'] : 0;
    if (!$row || !$hid || !Auth::canAccessHotel($hid)) {
        ajax_error(__('Not found'), 404, 'NOT_FOUND');
    }
    if (Auth::isPlatformUser()) {
        Tenant::set($hid);   // this request only (no "enter hotel")
    } elseif (Tenant::current() !== $hid) {
        ajax_error(__('Not found'), 404, 'NOT_FOUND');
    }
    require_can('rooms.manage');
    $room = $row['room_id'] ? DB::one('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $row['room_id'], 'h' => $hid]) : null;
    $device = DB::one(
        'SELECT status, last_ping FROM devices WHERE hotel_id = :h AND device_uid = :u AND is_revoked = 0',
        ['h' => $hid, 'u' => $row['device_uid']]
    );
    $status = match ($row['status']) {
        'used' => 'registered',
        'claimed' => 'waiting',
        default => 'expired',
    };
    ajax_ok([
        'status' => $status,
        'room_number' => $room['room_number'] ?? null,
        'online' => $status === 'registered' && DeviceManager::isOnline($device),
    ]);
}
