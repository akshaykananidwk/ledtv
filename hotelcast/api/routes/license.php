<?php
/**
 * License server endpoint (SaaS platform only):
 *   POST /api/license/check  {key, domain, version, tv_count}
 *   → {ok:true, data:{valid, hotel, max_tvs, expires_at, features, message}}
 * Rate limited per IP. An unknown / invalid key is a normal answer (valid:false), not an HTTP error,
 * so self-hosted installs can tell "invalid" from "server unreachable" (offline grace).
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if ($route !== 'license/check') {
        return false;
    }
    if (License::mode() !== 'saas') {
        Api::error('NOT_FOUND', 'This installation is not a license server', 404);
    }
    Api::method('POST');
    $limit = max(5, (int) Settings::platform('license_rate_per_min', '30'));
    $retry = RateLimiter::hit('license:' . client_ip(), $limit, 60);
    if ($retry > 0) {
        Api::error('RATE_LIMITED', 'Too many license checks', 429, ['Retry-After' => (string) $retry]);
    }
    Api::ok(LicenseServer::check(request_json(), client_ip()));
};
