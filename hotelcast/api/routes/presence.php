<?php
/**
 * Presence / motion sensor webhook (#47, core/Presence.php):
 *
 *   POST /api/presence
 *   Authorization: Bearer prs<48 hex>        (or "token" in the JSON / form body, or ?token=… for
 *                                             devices that cannot set headers, e.g. some Shelly actions)
 *   {"event": "motion"}       people seen → TVs of the sensor's rooms switch on (default when no body)
 *   {"event": "clear"}        sensor reports "no motion" (only recorded; the idle timer switches off)
 *   {"event": "ping"}         keep-alive / test, nothing is switched
 *   Home Assistant style {"state": "on" | "off"} works too (on = motion, off = clear).
 *   → 200 {"ok":true,"data":{"id":3,"event":"motion","state":"on","screens_on":2,"skipped":{"104":"power_schedule"}}}
 *
 * No session and no CSRF: the token is the credential (only its SHA-256 is stored). Rate limits:
 * 60 events / minute per sensor, 600 requests / minute and 20 bad tokens / 10 minutes per IP.
 * Errors: 401 INVALID_TOKEN, 400 VALIDATION_ERROR, 429 RATE_LIMITED, 403 HOTEL_SUSPENDED.
 * See docs/modules/device_schedules.md (ESP8266 / ESP32 sketch, Home Assistant, Shelly, phone).
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'presence') {
        return false;
    }
    if (count($parts) > 1) {
        Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
    }
    Api::method('POST');

    $ip = client_ip();
    if (($retry = RateLimiter::hit('presence:ip:' . $ip, 600, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
    }
    $raw = trim((string) file_get_contents('php://input'));
    if ($raw !== '' && $raw[0] === '{' && json_decode($raw, true) === null) {
        Api::error('VALIDATION_ERROR', 'Body must be JSON', 400);
    }
    $in = request_json();
    if (!$in && $_POST) {
        $in = $_POST;
    }
    $token = Api::bearer();
    foreach ([$in['token'] ?? null, $_GET['token'] ?? null] as $t) {
        if ($token === null && is_string($t) && $t !== '') {
            $token = trim($t);
        }
    }
    $sensor = $token !== null ? Presence::byToken($token) : null;
    if (!$sensor) {
        // Slow down token guessing.
        if (($retry = RateLimiter::hit('presence:bad:' . $ip, 20, 600)) > 0) {
            Api::error('RATE_LIMITED', 'Too many failed attempts', 429, ['Retry-After' => (string) $retry]);
        }
        Api::error('INVALID_TOKEN', 'Missing or invalid presence sensor token', 401);
    }
    if (($retry = RateLimiter::hit('presence:sensor:' . (int) $sensor['id'], 60, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many events for this sensor (max 60 per minute)', 429, ['Retry-After' => (string) $retry]);
    }
    Tenant::set((int) $sensor['hotel_id']);
    Tenant::requireActive();

    $event = $in['event'] ?? null;
    if ($event === null && isset($in['state'])) {
        $state = strtolower(trim((string) (is_scalar($in['state']) ? $in['state'] : '')));
        $event = in_array($state, ['on', 'true', '1', 'detected', 'motion'], true) ? 'motion' : 'clear';
    }
    $event = strtolower(trim(is_scalar($event) ? (string) $event : 'motion')) ?: 'motion';
    if (!in_array($event, Presence::EVENTS, true)) {
        Api::error('VALIDATION_ERROR', 'event must be one of: ' . implode(', ', Presence::EVENTS), 400);
    }
    $result = Presence::handle($sensor, $event);
    Logger::write('presence', 'info', 'Presence event', ['hotel' => (int) $sensor['hotel_id'], 'sensor' => (int) $sensor['id'], 'event' => $event, 'on' => $result['screens_on']]);
    Api::ok($result);
};
