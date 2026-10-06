<?php
/**
 * QR setup of TVs (no typing on the TV). No device auth: the TV is not registered yet.
 *
 *   POST /api/provision/start   {device_id, model, app_version}
 *        → {code, secret, claim_url, expires_in, poll_interval}
 *        rate limits: 10 / 10 min per IP, 5 / 10 min per device_id (429 RATE_LIMITED + Retry-After)
 *   GET  /api/provision/status?code=K7P2QX&secret=<64 hex>
 *        → {status:"pending"} | {status:"claimed", server_url, room_number, registration_key, hotel_name}
 *          | {status:"used"} | {status:"expired"}
 *        unknown code / wrong secret → 404 NOT_FOUND; 60 requests / min per code.
 *
 * See core/Provisioning.php and docs/modules/qr_setup.md.
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'provision') {
        return false;
    }
    $action = $parts[1] ?? '';

    if ($action === 'start' && count($parts) === 2) {
        Api::method('POST');
        $in = request_json();
        $uid = is_string($in['device_id'] ?? null) ? trim($in['device_id']) : '';
        if (!DeviceManager::validUid($uid)) {
            Api::error('VALIDATION_ERROR', 'device_id is required (8-64 characters: letters, digits, -)', 400);
        }
        $ip = client_ip();
        $retry = RateLimiter::hit('prov_start_ip:' . $ip, max(1, (int) Config::get('provision_start_limit_ip', 10)), 600);
        if ($retry === 0) {
            $retry = RateLimiter::hit('prov_start_dev:' . $uid, 5, 600);
        }
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many setup codes requested. Try again later.', 429, ['Retry-After' => (string) $retry]);
        }
        Api::ok(Provisioning::start($in, $ip));
    }

    if ($action === 'status' && count($parts) === 2) {
        Api::method('GET');
        $code = Provisioning::normalizeCode($_GET['code'] ?? null);
        $secret = is_string($_GET['secret'] ?? null) ? strtolower(trim($_GET['secret'])) : '';
        if ($code === null) {
            Api::error('NOT_FOUND', 'Unknown setup code', 404);
        }
        $retry = RateLimiter::hit('prov_status:' . $code, 60, 60);
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
        }
        $row = Provisioning::findBySecret($code, $secret);
        if (!$row) {
            Api::error('NOT_FOUND', 'Unknown setup code', 404);
        }
        Api::ok(Provisioning::statusFor($row));
    }

    Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
};
