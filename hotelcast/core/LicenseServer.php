<?php
declare(strict_types=1);

/**
 * License server side (runs on the SaaS platform): answers POST /api/license/check and
 * manages license keys (Platform → Licenses).
 */
final class LicenseServer
{
    /** Generate a new random key: HC-XXXXX-XXXXX-XXXXX-XXXXX. */
    public static function newKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $parts = [];
        for ($p = 0; $p < 4; $p++) {
            $chunk = '';
            for ($i = 0; $i < 5; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $parts[] = $chunk;
        }
        return 'HC-' . implode('-', $parts);
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }
        $domain = preg_replace('/:\d+$/', '', $domain) ?? '';
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }
        return preg_match('/^[a-z0-9.-]{1,190}$/', $domain) ? $domain : '';
    }

    /**
     * Verify a license. Input {key, domain, version, tv_count}. Always returns
     * {valid, hotel, max_tvs, expires_at, features, message}.
     */
    public static function check(array $in, string $ip = ''): array
    {
        $key = strtoupper(trim((string) ($in['key'] ?? '')));
        $domain = self::normalizeDomain((string) ($in['domain'] ?? ''));
        $answer = static fn (bool $valid, string $message, ?array $lic = null, ?string $hotel = null): array => [
            'valid' => $valid,
            'hotel' => $hotel,
            'max_tvs' => $lic && $lic['max_tvs'] !== null ? (int) $lic['max_tvs'] : null,
            'expires_at' => $lic && $lic['expires_at'] ? date('c', (int) strtotime((string) $lic['expires_at'])) : null,
            'features' => $lic && $lic['features'] ? (json_decode((string) $lic['features'], true) ?: []) : [],
            'message' => $message,
        ];
        if ($key === '' || strlen($key) > 64) {
            return $answer(false, 'License key missing');
        }
        $lic = DB::one('SELECT * FROM licenses WHERE license_key = :k', ['k' => $key]);
        if (!$lic) {
            Logger::write('license', 'warning', 'Unknown license key', ['ip' => $ip, 'domain' => $domain]);
            return $answer(false, 'Unknown license key');
        }
        $hotelName = (string) $lic['customer_name'];
        $hotel = $lic['hotel_id'] ? DB::one('SELECT id, name, status FROM hotels WHERE id = :id', ['id' => $lic['hotel_id']]) : null;
        if ($hotel) {
            $hotelName = (string) $hotel['name'];
        }

        DB::query(
            'UPDATE licenses SET last_check_at = :n, last_ip = :ip, last_version = :v, last_tv_count = :tv, check_count = check_count + 1 WHERE id = :id',
            ['n' => now(), 'ip' => mb_substr($ip, 0, 45), 'v' => mb_substr((string) ($in['version'] ?? ''), 0, 30),
             'tv' => max(0, (int) ($in['tv_count'] ?? 0)), 'id' => $lic['id']]
        );

        if ($lic['status'] !== 'active') {
            return $answer(false, 'License revoked. Contact your provider.', $lic, $hotelName);
        }
        if ($lic['expires_at'] && strtotime((string) $lic['expires_at']) < time()) {
            return $answer(false, 'License expired on ' . date('d M Y', (int) strtotime((string) $lic['expires_at'])) . '. Contact your provider to renew.', $lic, $hotelName);
        }
        if ($hotel && $hotel['status'] !== 'active') {
            return $answer(false, 'Account suspended. Contact your provider.', $lic, $hotelName);
        }
        if ($domain === '') {
            return $answer(false, 'Domain missing', $lic, $hotelName);
        }
        if ($lic['bound_domain'] === null || $lic['bound_domain'] === '') {
            // Bind on first check (atomic: only if still unbound).
            DB::query("UPDATE licenses SET bound_domain = :d WHERE id = :id AND (bound_domain IS NULL OR bound_domain = '')", ['d' => $domain, 'id' => $lic['id']]);
            $lic['bound_domain'] = (string) DB::value('SELECT bound_domain FROM licenses WHERE id = :id', ['id' => $lic['id']]);
            Logger::write('license', 'info', 'License bound to domain', ['license' => $lic['id'], 'domain' => $lic['bound_domain']]);
        }
        if ($lic['bound_domain'] !== $domain) {
            return $answer(false, 'This license is registered for another domain (' . $lic['bound_domain'] . '). Ask your provider to reset it.', $lic, $hotelName);
        }
        return $answer(true, 'License valid', $lic, $hotelName);
    }

    /** Validate license form input. Returns [data, errors]. */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['customer_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = __('Customer name is required.');
        }
        $max = trim((string) ($in['max_tvs'] ?? ''));
        $exp = trim((string) ($in['expires_at'] ?? ''));
        $expTs = $exp !== '' ? strtotime($exp . (strlen($exp) === 10 ? ' 23:59:59' : '')) : null;
        if ($exp !== '' && !$expTs) {
            $errors[] = __('Invalid expiry date.');
        }
        $hotelId = (int) ($in['hotel_id'] ?? 0);
        if ($hotelId && !DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $hotelId])) {
            $hotelId = 0;
        }
        return [[
            'customer_name' => mb_substr($name, 0, 150),
            'max_tvs' => $max === '' ? null : max(0, (int) $max),
            'expires_at' => $expTs ? date('Y-m-d H:i:s', $expTs) : null,
            'hotel_id' => $hotelId ?: null,
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000) ?: null,
            'status' => ($in['status'] ?? 'active') === 'revoked' ? 'revoked' : 'active',
        ], $errors];
    }
}
