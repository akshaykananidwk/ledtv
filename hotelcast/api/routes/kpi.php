<?php
/**
 * KPI dashboard (#8) machine push — set a tile's value from a PLC, counter box or script:
 *
 *   POST /api/kpi/push
 *   Authorization: Bearer kpi_<48 hex>        (or "token" in the JSON / form body)
 *   {"value": 830}                set a counter / percent (text tiles: any text)
 *   {"add": 1}                    add to a counter (negative to subtract)
 *   {"reset": true}               days-since tile: start again from today (counter: back to 0)
 *   {"since": "2026-01-15"}       days-since tile: count from this date
 *   → 200 {"ok":true,"data":{"id":12,"type":"counter","value":"830","num":830,…}}
 *
 * The token belongs to one tile (Admin → KPI dashboard → tile → Machine push). No session and no CSRF:
 * the token is the credential (only its SHA-256 is stored). Rate limits: 30 updates / minute per tile,
 * 600 requests / minute and 20 bad tokens / 10 minutes per IP. Errors: 401 INVALID_TOKEN,
 * 400 VALIDATION_ERROR, 429 RATE_LIMITED, 403 HOTEL_SUSPENDED. See docs/modules/business_apps.md.
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'kpi') {
        return false;
    }
    if (($parts[1] ?? '') !== 'push' || count($parts) > 2) {
        Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
    }
    Api::method('POST');

    $ip = client_ip();
    if (($retry = RateLimiter::hit('kpi:ip:' . $ip, 600, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
    }
    $raw = trim((string) file_get_contents('php://input'));
    $in = request_json();
    if ($raw !== '' && $raw[0] === '{' && json_decode($raw, true) === null) {
        Api::error('VALIDATION_ERROR', 'Body must be JSON', 400);
    }
    $token = Api::bearer() ?? (is_string($in['token'] ?? null) ? $in['token'] : null);
    $tile = $token !== null ? Kpi::byToken(trim($token)) : null;
    if (!$tile) {
        // Slow down token guessing.
        if (($retry = RateLimiter::hit('kpi:bad:' . $ip, 20, 600)) > 0) {
            Api::error('RATE_LIMITED', 'Too many failed attempts', 429, ['Retry-After' => (string) $retry]);
        }
        Api::error('INVALID_TOKEN', 'Missing or invalid KPI push token', 401);
    }
    if (($retry = RateLimiter::hit('kpi:tile:' . (int) $tile['id'], 30, 60)) > 0) {
        Api::error('RATE_LIMITED', 'Too many updates for this tile (max 30 per minute)', 429, ['Retry-After' => (string) $retry]);
    }
    Tenant::set((int) $tile['hotel_id']);
    Tenant::requireActive();

    if (array_key_exists('add', $in)) {
        [$op, $value] = ['add', $in['add']];
    } elseif (array_key_exists('value', $in)) {
        [$op, $value] = ['set', $in['value']];
    } elseif (!empty($in['reset']) && $in['reset'] !== 'false') {
        [$op, $value] = ['reset', null];
    } elseif (array_key_exists('since', $in)) {
        [$op, $value] = ['since', $in['since']];
    } else {
        Api::error('VALIDATION_ERROR', 'Send one of: value, add, reset, since', 400);
    }
    try {
        $row = Kpi::apply($tile, $op, $value);
    } catch (InvalidArgumentException $e) {
        Api::error('VALIDATION_ERROR', $e->getMessage(), 400);
    }
    DB::update('kpi_tiles', ['pushed_at' => now()], 'id = :id', ['id' => (int) $tile['id']]);
    Logger::write('kpi', 'info', 'KPI push', ['hotel' => (int) $tile['hotel_id'], 'tile' => (int) $tile['id'], 'op' => $op]);
    $v = Kpi::view($row, time());
    Api::ok(['id' => $v['id'], 'label' => $v['label'], 'type' => $v['type'], 'value' => $v['value'], 'num' => $v['num'], 'unit' => $v['unit'], 'level' => $v['level']]);
};
