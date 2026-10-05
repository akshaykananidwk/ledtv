<?php
/**
 * PMS integration (#10), per-hotel API key (Admin → Guest services setup → PMS):
 *   POST /api/pms/checkin    {room_number, guest_name, salutation?, language?, checkout_at?, external_ref?, phone?, balance?}
 *   POST /api/pms/checkout   {room_number | external_ref}
 *   POST /api/pms/room-move  {from_room | external_ref, to_room}
 *   POST /api/pms/update     {external_ref | room_number, checkout_at?, balance?, guest_name?, language?, …}
 *   GET  /api/pms/rooms      occupancy
 *   POST /api/pms/webhook    any JSON payload, mapped by the hotel's field mapping (key also as ?key=)
 * Auth: "Authorization: Bearer <pms key>". Rate limited per key and per IP (failed auth).
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'pms') {
        return false;
    }
    $action = (string) ($parts[1] ?? '');
    $routes = ['checkin' => 'POST', 'checkout' => 'POST', 'room-move' => 'POST', 'update' => 'POST', 'rooms' => 'GET', 'webhook' => 'POST'];
    if (!isset($routes[$action]) || count($parts) > 2) {
        Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
    }
    Api::method($routes[$action]);

    $ip = client_ip();
    if (($retry = RateLimiter::hit('pms:ip:' . $ip, 600, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
    }
    $key = Api::bearer();
    if ($key === null && $action === 'webhook' && is_string($_GET['key'] ?? null)) {
        $key = (string) $_GET['key'];
    }
    $hotelId = $key !== null ? Guests::hotelForPmsKey($key) : null;
    if ($hotelId === null) {
        // Slow down key guessing: 20 failures / 10 min / IP.
        if (($retry = RateLimiter::hit('pms:bad:' . $ip, 20, 600)) > 0) {
            Api::error('RATE_LIMITED', 'Too many failed attempts', 429, ['Retry-After' => (string) $retry]);
        }
        Api::error('INVALID_API_KEY', 'Missing or invalid PMS API key', 401);
    }
    if (($retry = RateLimiter::hit('pms:key:' . substr(hash('sha256', (string) $key), 0, 32), 300, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many requests for this API key', 429, ['Retry-After' => (string) $retry]);
    }
    Tenant::set($hotelId);
    Tenant::requireActive();
    if (!Tenant::feature('guests')) {
        Api::error('FEATURE_DISABLED', 'Guest management is not part of this hotel\'s plan', 403);
    }

    $in = $method === 'GET' ? [] : request_json();
    if ($method === 'POST' && $in === [] && trim((string) file_get_contents('php://input')) !== '' && json_decode((string) file_get_contents('php://input'), true) === null) {
        Api::error('VALIDATION_ERROR', 'Body must be JSON', 400);
    }
    try {
        [$status, $data] = match ($action) {
            'checkin' => GuestPms::checkin($in),
            'checkout' => GuestPms::checkout($in),
            'room-move' => GuestPms::roomMove($in),
            'update' => GuestPms::updateStay($in),
            'rooms' => GuestPms::rooms(),
            'webhook' => GuestPms::webhook($in),
        };
    } catch (PmsError $e) {
        Api::error($e->apiCode, $e->getMessage(), $e->status);
    } catch (InvalidArgumentException $e) {
        Api::error('VALIDATION_ERROR', $e->getMessage(), 400);
    }
    Logger::write('pms', 'info', 'PMS ' . $action, ['hotel' => $hotelId, 'status' => $status, 'stay' => $data['stay_id'] ?? null]);
    Api::ok($data, $status);
};
