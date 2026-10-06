<?php
/**
 * Public TV simulator for the demo hotel (#21): demo_tv.php?room=<room id> renders the same simulator
 * as admin/preview.php without a login — ONLY for rooms of the public demo hotel (any other id is a
 * plain 404, nothing about other hotels is revealed). &json=1 returns the live Content object
 * ({ok, data}) polled by the simulator. Rate limited per IP.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/admin/partials/common.php';

Auth::startSession();
I18n::lang();
session_write_close();
header('Cache-Control: no-store');
$json = !empty($_GET['json']);

$notFound = static function () use ($json): never {
    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_out(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]);
    } else {
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>404</title>'
            . '<body style="font-family:sans-serif;padding:24px 16px"><h1>' . e(__('Not found')) . '</h1><p><a href="' . e(base_url('demo.php')) . '">' . e(__('Back to the demo')) . '</a></p></body>';
    }
    exit;
};

$hid = Demo::publicEnabled() ? Demo::publicHotelId() : null;
$roomId = req_int('room', $_GET);
if (!$hid || !$roomId) {
    $notFound();
}
if (RateLimiter::hit('demo_tv:' . client_ip(), 900, 3600) > 0) {
    http_response_code(429);
    header('Retry-After: 600');
    exit($json ? json_out(['ok' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests']]) : 'Too many requests');
}
// Scoped to the demo hotel: a room of any other hotel is simply "not found".
$room = DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h AND is_enabled = 1', ['id' => $roomId, 'h' => $hid]);
if (!$room) {
    $notFound();
}
Tenant::set($hid);
Demo::keepAlive($hid);
$obj = ContentResolver::build($room);

if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_out(['ok' => true, 'data' => $obj]);
    exit;
}

$label = __('Room') . ' ' . $obj['room']['number'] . ' · ' . (string) Settings::get('hotel_name', '');
$embed = !empty($_GET['embed']);
$simRefreshUrl = base_url('demo_tv.php?' . http_build_query(['room' => $roomId, 'json' => 1]));
$simCloseUrl = base_url('demo.php');
$simBadge = __('Demo');
require __DIR__ . '/admin/partials/tv_simulator.php';
