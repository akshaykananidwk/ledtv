<?php
/**
 * Public guest API for the guest web app (/g/{token}), authenticated by the room's guest token:
 *   GET  /api/guest/{token}           everything the app needs (hotel, room, guest, Wi-Fi, menu, request types, status)
 *   GET  /api/guest/{token}/status    the guest's own orders, requests and feedback (live status)
 *   POST /api/guest/{token}/order     {items: [{id, qty}], notes?}
 *   POST /api/guest/{token}/request   {type_id, time?: "HH:MM", notes?}
 *   POST /api/guest/{token}/feedback  {rating 1–5, cleanliness?, staff?, food?, comment?}
 * POST bodies must be JSON (Content-Type: application/json). No cookies / sessions: the token in the
 * URL is the credential, so there is nothing to forge cross-site. Rate limited per IP and per token.
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'guest') {
        return false;
    }
    $token = (string) ($parts[1] ?? '');
    $action = (string) ($parts[2] ?? '');
    $routes = ['' => 'GET', 'status' => 'GET', 'order' => 'POST', 'request' => 'POST', 'feedback' => 'POST'];
    if (!isset($routes[$action]) || count($parts) > 3 || $token === '') {
        Api::error('NOT_FOUND', 'Unknown endpoint', 404);
    }
    Api::method($routes[$action]);
    $ip = client_ip();
    $limit = static function (string $key, int $max, int $window, string $msg = 'Too many requests'): void {
        if (($retry = RateLimiter::hit($key, $max, $window)) > 0) {
            Api::error('RATE_LIMITED', $msg, 429, ['Retry-After' => (string) $retry]);
        }
    };
    $limit('guest:ip:' . $ip, 240, 60);

    $ctx = Guests::resolveToken($token);
    if ($ctx === null) {
        // Token guessing: 30 unknown / expired tokens per 10 minutes per IP.
        $limit('guest:bad:' . $ip, 30, 600, 'Too many invalid links');
        Api::error('INVALID_TOKEN', 'This link is not valid any more. Please scan the QR code on your TV again.', 404);
    }
    $tk = substr($ctx['token_hash'], 0, 32);
    $limit('guest:tok:' . $tk, 120, 60);

    if ($method === 'POST') {
        if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')), 'application/json')) {
            Api::error('UNSUPPORTED_MEDIA_TYPE', 'Send JSON (Content-Type: application/json)', 415);
        }
        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > 20000) {
            Api::error('VALIDATION_ERROR', 'Request too large', 413);
        }
        $in = json_decode($raw, true);
        if (!is_array($in)) {
            Api::error('VALIDATION_ERROR', 'Body must be a JSON object', 400);
        }
        $limit('guest:w:tok:' . $tk, 30, 600, 'Too many requests from this room. Please call reception.');
        $limit('guest:w:ip:' . $ip, 60, 600, 'Too many requests. Please call reception.');
        try {
            $data = match ($action) {
                'order' => GuestServices::placeOrder($ctx, $in),
                'request' => GuestServices::createRequest($ctx, $in),
                'feedback' => GuestServices::saveFeedback($ctx, $in),
            };
        } catch (InvalidArgumentException $e) {
            Api::error('VALIDATION_ERROR', $e->getMessage(), 400);
        } catch (DomainException $e) {
            $m = $e->getMessage();
            if (str_starts_with($m, 'ITEM_UNAVAILABLE:')) {
                Api::error('ITEM_UNAVAILABLE', 'Not available right now: ' . substr($m, 17), 409);
            }
            Api::error('SERVICE_DISABLED', 'This service is not available', 403);
        }
        Api::ok($data, 201);
    }

    if ($action === 'status') {
        Api::ok(GuestServices::statusForGuest($ctx));
    }

    // Full bootstrap for the app.
    Api::ok(GuestServices::appData($ctx));
};
