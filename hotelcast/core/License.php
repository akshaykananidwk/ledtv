<?php
declare(strict_types=1);

/**
 * Self-hosted license client (#19).
 *
 * config.php:
 *   'mode'           => 'saas' | 'standalone'   (default 'saas' — the platform itself / existing installs)
 *   'license_key'    => 'HC-XXXX-…'            (standalone only)
 *   'license_server' => 'https://platform.example.com/hotelcast/'   (base URL of the platform install)
 *
 * In standalone mode the key is checked once a day (Scheduler task) with
 * POST {license_server}api/license/check {key, domain, version, tv_count}. The answer is cached in
 * storage/license.json. When the server cannot be reached the last good answer stays valid for
 * GRACE_DAYS (offline grace). Invalid / expired → the hotel behaves as suspended.
 * No key configured → "unlicensed" demo: banner + max UNLICENSED_MAX_TVS TVs.
 * SaaS mode needs no license at all.
 */
final class License
{
    public const GRACE_DAYS = 14;
    public const UNLICENSED_MAX_TVS = 2;
    public const CHECK_INTERVAL = 86400;

    /** Test hook: callable(string $url, array $payload): array{status:int, body:string, error:?string} */
    public static $transport = null;
    /** Test hook: override the cache file. */
    public static ?string $file = null;

    private static ?array $state = null;

    public static function mode(): string
    {
        return Config::get('mode', 'saas') === 'standalone' ? 'standalone' : 'saas';
    }

    public static function key(): string
    {
        return trim((string) Config::get('license_key', ''));
    }

    public static function server(): string
    {
        return rtrim((string) Config::get('license_server', ''), '/') . '/';
    }

    private static function file(): string
    {
        return self::$file ?? HC_ROOT . '/storage/license.json';
    }

    public static function reset(): void
    {
        self::$state = null;
    }

    /**
     * Current license state:
     *   ['status' => saas|unlicensed|valid|grace|invalid, 'message', 'max_tvs', 'expires_at', 'hotel',
     *    'features', 'checked_at', 'last_ok_at']
     */
    public static function state(): array
    {
        if (self::mode() === 'saas') {
            return ['status' => 'saas', 'message' => '', 'max_tvs' => null, 'expires_at' => null, 'hotel' => null, 'features' => [], 'checked_at' => null, 'last_ok_at' => null];
        }
        if (self::key() === '') {
            return ['status' => 'unlicensed', 'message' => 'No license key configured — demo mode, max ' . self::UNLICENSED_MAX_TVS . ' TVs.',
                'max_tvs' => self::UNLICENSED_MAX_TVS, 'expires_at' => null, 'hotel' => null, 'features' => [], 'checked_at' => null, 'last_ok_at' => null];
        }
        if (self::$state === null) {
            $raw = is_file(self::file()) ? json_decode((string) @file_get_contents(self::file()), true) : null;
            $ok = is_array($raw) && ($raw['key_hash'] ?? '') === hash('sha256', self::key());
            self::$state = $ok ? $raw : [
                'status' => 'pending', 'message' => 'License not checked yet', 'max_tvs' => null, 'expires_at' => null,
                'hotel' => null, 'features' => [], 'checked_at' => null, 'last_ok_at' => null,
            ];
        }
        $s = self::$state;
        // Offline grace / expiry are re-evaluated on every read.
        if (in_array($s['status'], ['valid', 'grace'], true) && !empty($s['expires_at']) && strtotime((string) $s['expires_at']) < time()) {
            $s['status'] = 'invalid';
            $s['message'] = 'License expired on ' . date('d M Y', (int) strtotime((string) $s['expires_at']));
        }
        if ($s['status'] === 'grace' && (!$s['last_ok_at'] || $s['last_ok_at'] < time() - self::GRACE_DAYS * 86400)) {
            $s['status'] = 'invalid';
            $s['message'] = 'The license server could not be reached for more than ' . self::GRACE_DAYS . ' days.';
        }
        return $s;
    }

    /** Operation allowed (TVs show content)? */
    public static function allowsOperation(): bool
    {
        // 'pending' = a key was configured but never checked yet: allow until the first check runs.
        return in_array(self::state()['status'], ['saas', 'unlicensed', 'valid', 'grace', 'pending'], true);
    }

    /** TV limit imposed by the license (null = no license limit). */
    public static function maxTvs(): ?int
    {
        $s = self::state();
        return match ($s['status']) {
            'saas' => null,
            'unlicensed' => self::UNLICENSED_MAX_TVS,
            'invalid' => 0,
            default => $s['max_tvs'] !== null ? (int) $s['max_tvs'] : null,
        };
    }

    /** Is a check due (or forced)? Performs it and returns the new state. */
    public static function check(bool $force = false): array
    {
        if (self::mode() !== 'standalone' || self::key() === '') {
            return self::state();
        }
        $s = self::state();
        if (!$force && $s['checked_at'] && $s['checked_at'] > time() - self::CHECK_INTERVAL) {
            return $s;
        }
        $payload = [
            'key' => self::key(),
            'domain' => (string) (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'),
            'version' => (string) Version::current()['version'],
            'tv_count' => self::tvCount(),
        ];
        $url = self::server() . 'api/license/check';
        try {
            $res = self::$transport
                ? (self::$transport)($url, $payload)
                : Http::request('POST', $url, ['Content-Type: application/json', 'Accept: application/json'], json_out($payload), 15);
        } catch (Throwable $e) {
            $res = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
        $json = json_decode((string) ($res['body'] ?? ''), true);
        $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : null;
        $now = time();
        if (($res['status'] ?? 0) === 200 && $data !== null && array_key_exists('valid', $data)) {
            $valid = (bool) $data['valid'];
            $new = [
                'status' => $valid ? 'valid' : 'invalid',
                'message' => (string) ($data['message'] ?? ($valid ? 'License valid' : 'License invalid')),
                'max_tvs' => isset($data['max_tvs']) && $data['max_tvs'] !== null ? (int) $data['max_tvs'] : null,
                'expires_at' => $data['expires_at'] ?? null,
                'hotel' => $data['hotel'] ?? null,
                'features' => (array) ($data['features'] ?? []),
                'checked_at' => $now,
                'last_ok_at' => $valid ? $now : ($s['last_ok_at'] ?? null),
            ];
        } else {
            // Network / server error → offline grace based on the last successful check.
            $lastOk = $s['last_ok_at'] ?? null;
            $inGrace = $lastOk && $lastOk >= $now - self::GRACE_DAYS * 86400 && in_array($s['status'], ['valid', 'grace'], true);
            $new = array_merge($s, [
                'status' => $inGrace ? 'grace' : 'invalid',
                'message' => $inGrace
                    ? 'License server unreachable — offline grace until ' . date('d M Y', $lastOk + self::GRACE_DAYS * 86400)
                    : 'License could not be verified (server unreachable: ' . ($res['error'] ?? ('HTTP ' . ($res['status'] ?? 0))) . ')',
                'checked_at' => $now,
            ]);
        }
        $new['key_hash'] = hash('sha256', self::key());
        self::$state = $new;
        @file_put_contents(self::file(), json_out($new), LOCK_EX);
        Logger::write('license', $new['status'] === 'invalid' ? 'warning' : 'info', 'License check: ' . $new['status'], ['message' => $new['message']]);
        Settings::flush();
        return self::state();
    }

    private static function tvCount(): int
    {
        try {
            return (int) DB::value('SELECT COUNT(*) FROM devices WHERE is_revoked = 0 AND room_id IS NOT NULL');
        } catch (Throwable) {
            return 0;
        }
    }

    /** Admin banner for standalone installs: null when nothing to show. ['type' => warning|danger, 'text' => …] */
    public static function banner(): ?array
    {
        $s = self::state();
        return match ($s['status']) {
            'unlicensed' => ['type' => 'warning', 'text' => __('Unlicensed installation — demo mode, maximum :n TVs. Add your license key in config.php.', ['n' => self::UNLICENSED_MAX_TVS])],
            'grace' => ['type' => 'warning', 'text' => __('License server unreachable — running in offline grace period.') . ' ' . $s['message']],
            'invalid' => ['type' => 'danger', 'text' => __('License invalid or expired — TVs show the "service paused" screen.') . ' ' . $s['message']],
            default => null,
        };
    }
}
