<?php
declare(strict_types=1);

/**
 * Current hotel (tenant) context.
 *
 *  - Admin request: set by Auth::user() from users.hotel_id (platform admins / resellers may
 *    "enter" a hotel, stored in the session).
 *  - Device API request: set by DeviceManager::authenticate() from devices.hotel_id.
 *  - Registration: set from the hotel that owns the registration key.
 *  - Cron / Scheduler: Tenant::each() iterates hotels with the context switched.
 *
 * EVERY query on a tenant table must be scoped with `hotel_id = Tenant::id()`.
 * DB::insert/update/delete add the scope automatically for TABLES (safety net), raw SQL must
 * include it explicitly. Tenant::id() throws when no hotel is selected, so a forgotten context
 * fails loudly instead of leaking another hotel's data.
 */
final class Tenant
{
    /** Tables that carry hotel_id and are auto-scoped by DB::insert/update/delete. */
    public const TABLES = [
        'rooms', 'room_groups', 'content_items', 'content_playlists', 'broadcast_commands', 'devices',
        'apk_releases', 'broadcast_logs', 'device_status_logs',
    ];

    /** Hotel states that allow normal operation. */
    public const STATUSES = ['active', 'suspended', 'expired'];

    private static ?int $id = null;
    private static array $hotels = [];

    /** Current hotel id. Throws when no hotel context is set. */
    public static function id(): int
    {
        if (self::$id === null) {
            throw new TenantException('No hotel context selected');
        }
        return self::$id;
    }

    /** Current hotel id or null (no context). */
    public static function current(): ?int
    {
        return self::$id;
    }

    public static function has(): bool
    {
        return self::$id !== null;
    }

    public static function set(?int $hotelId): void
    {
        if ($hotelId !== null && $hotelId <= 0) {
            throw new InvalidArgumentException('Invalid hotel id');
        }
        if (self::$id === $hotelId) {
            return;
        }
        self::$id = $hotelId;
        // Per-hotel time zone (falls back to the platform / config time zone).
        if (class_exists('Settings', false) && function_exists('hc_installed') && hc_installed()) {
            $tz = (string) Settings::get('timezone', (string) Config::get('timezone', 'Asia/Kolkata'));
            if ($tz !== '' && $tz !== date_default_timezone_get() && in_array($tz, timezone_identifiers_list(), true)) {
                date_default_timezone_set($tz);
                DB::syncTimezone();
            }
        }
    }

    public static function clear(): void
    {
        self::$id = null;
    }

    /** Run $fn inside the context of $hotelId, restoring the previous context afterwards. */
    public static function run(int $hotelId, callable $fn): mixed
    {
        $prev = self::$id;
        self::set($hotelId);
        try {
            return $fn();
        } finally {
            self::set($prev);
        }
    }

    /** Run $fn for every hotel (optionally only active ones). Returns [hotelId => result]. */
    public static function each(callable $fn, bool $activeOnly = false): array
    {
        $out = [];
        $sql = 'SELECT id FROM hotels' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY id';
        foreach (DB::column($sql) as $hid) {
            $hid = (int) $hid;
            try {
                $out[$hid] = self::run($hid, fn () => $fn($hid));
            } catch (Throwable $e) {
                Logger::error('Tenant task for hotel ' . $hid . ' failed: ' . $e->getMessage());
                $out[$hid] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** The current hotel row (cached per request) or null. */
    public static function hotel(?int $hotelId = null): ?array
    {
        $hotelId ??= self::$id;
        if ($hotelId === null) {
            return null;
        }
        if (!array_key_exists($hotelId, self::$hotels)) {
            self::$hotels[$hotelId] = DB::one(
                'SELECT h.*, p.name AS plan_name, p.price_per_tv_month, p.max_tvs AS plan_max_tvs, p.features AS plan_features
                 FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id WHERE h.id = :id',
                ['id' => $hotelId]
            );
        }
        return self::$hotels[$hotelId];
    }

    public static function forget(?int $hotelId = null): void
    {
        if ($hotelId === null) {
            self::$hotels = [];
        } else {
            unset(self::$hotels[$hotelId]);
        }
    }

    /** Set the context from a device row (device API requests). */
    public static function forDevice(array $device): void
    {
        self::set((int) $device['hotel_id']);
    }

    /**
     * Effective state of a hotel: 'active' | 'suspended' | 'expired'.
     * Combines hotels.status, hotels.expires_at and (standalone installs) the license state.
     */
    public static function state(?int $hotelId = null): string
    {
        $h = self::hotel($hotelId);
        if (!$h) {
            return 'suspended';
        }
        if ($h['status'] !== 'active') {
            return $h['status'] === 'expired' ? 'expired' : 'suspended';
        }
        if (!empty($h['expires_at']) && strtotime((string) $h['expires_at']) < time()) {
            return 'expired';
        }
        if (License::mode() === 'standalone' && !License::allowsOperation()) {
            return 'expired';
        }
        return 'active';
    }

    public static function isActive(?int $hotelId = null): bool
    {
        return self::state($hotelId) === 'active';
    }

    /**
     * Abort (API: 403 HOTEL_SUSPENDED) when the current hotel is suspended or expired.
     * Admin pages use Auth::require() which applies the read-only rule instead.
     */
    public static function requireActive(): void
    {
        if (!self::isActive()) {
            if (defined('HC_API')) {
                Api::error('HOTEL_SUSPENDED', self::suspendedMessage()['message'], 403);
            }
            throw new TenantException('Hotel is suspended');
        }
    }

    /** Polite message for TVs / admins of a suspended hotel. */
    public static function suspendedMessage(?string $lang = null): array
    {
        $lang ??= (string) Settings::get('default_language', 'en');
        $custom = trim((string) Settings::get('suspended_message', ''));
        return [
            'title' => I18n::translate('Service paused', $lang),
            'message' => $custom !== '' ? $custom : I18n::translate('This TV service is temporarily paused. Please contact reception.', $lang),
        ];
    }

    /** Maximum TVs for the current hotel (null = unlimited). */
    public static function maxTvs(?int $hotelId = null): ?int
    {
        $h = self::hotel($hotelId);
        $limits = [];
        if ($h && $h['max_tvs'] !== null) {
            $limits[] = (int) $h['max_tvs'];
        } elseif ($h && $h['plan_max_tvs'] !== null) {
            $limits[] = (int) $h['plan_max_tvs'];
        }
        $lic = License::maxTvs();
        if ($lic !== null) {
            $limits[] = $lic;
        }
        return $limits ? min($limits) : null;
    }

    /** Active (registered, not revoked, assigned to a room) TVs of a hotel. */
    public static function tvCount(?int $hotelId = null): int
    {
        $hotelId ??= self::id();
        return (int) DB::value('SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL', ['h' => $hotelId]);
    }

    /** Is a plan feature/module enabled for the current hotel? (No plan / no feature list = everything.) */
    public static function feature(string $module, ?int $hotelId = null): bool
    {
        $h = self::hotel($hotelId);
        if (!$h || empty($h['plan_features'])) {
            return true;
        }
        $f = json_decode((string) $h['plan_features'], true);
        if (!is_array($f) || !$f) {
            return true;
        }
        return in_array($module, $f, true) || (isset($f[$module]) && $f[$module]);
    }

    // ------------------------------------------------------------------ access helpers

    /**
     * Fetch a row of a tenant table by id, scoped to the current hotel.
     * Returns null when it does not exist; DENIES (404, logged) when it belongs to another hotel.
     */
    public static function find(string $table, int $id, string $extraWhere = '', array $params = []): ?array
    {
        self::assertTable($table);
        if ($id <= 0) {
            return null;
        }
        $sql = "SELECT * FROM `$table` WHERE id = :__id AND hotel_id = :__hid" . ($extraWhere !== '' ? " AND ($extraWhere)" : '') . ' LIMIT 1';
        $row = DB::one($sql, $params + ['__id' => $id, '__hid' => self::id()]);
        if ($row === null) {
            self::checkForeign($table, [$id]);
        }
        return $row;
    }

    /** True if every id belongs to the current hotel; denies when any id belongs to another hotel. */
    public static function assertOwnsAll(string $table, array $ids): array
    {
        self::assertTable($table);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
        if (!$ids) {
            return [];
        }
        self::checkForeign($table, $ids);
        [$in, $p] = DB::in($ids, 'own');
        return array_map('intval', DB::column("SELECT id FROM `$table` WHERE hotel_id = :__hid AND id IN $in", $p + ['__hid' => self::id()]));
    }

    /** Deny if any of $ids exists in another hotel. */
    private static function checkForeign(string $table, array $ids): void
    {
        [$in, $p] = DB::in($ids, 'fx');
        $foreign = (int) DB::value("SELECT COUNT(*) FROM `$table` WHERE hotel_id <> :__hid AND id IN $in", $p + ['__hid' => self::id()]);
        if ($foreign > 0) {
            self::deny("$table ids " . implode(',', array_slice($ids, 0, 10)));
        }
    }

    /**
     * Cross-hotel access attempt: log it and stop with 404 (JSON for API/AJAX, HTML otherwise).
     * In CLI (tests, cron) a TenantException is thrown instead.
     */
    public static function deny(string $what = ''): never
    {
        Logger::write('security', 'warning', 'Cross-hotel access denied', [
            'hotel' => self::$id, 'what' => $what,
            'user' => class_exists('Auth', false) ? Auth::id() : null,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        ]);
        if (PHP_SAPI === 'cli') {
            throw new TenantException('Access denied: record belongs to another hotel');
        }
        if (!headers_sent()) {
            http_response_code(404);
        }
        $json = defined('HC_API') || (class_exists('Auth', false) && Auth::isAjax());
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_out(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]);
        } else {
            echo '<!DOCTYPE html><meta charset="utf-8"><title>404</title><body style="font-family:sans-serif;padding:40px">'
                . '<h1>' . e(__('Not found')) . '</h1><p>' . e(__('This record does not exist or belongs to another hotel.')) . '</p>'
                . '<p><a href="' . e(admin_url('index.php')) . '">' . e(__('Back to dashboard')) . '</a></p></body>';
        }
        exit;
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true) && !in_array($table, Tenant::extraTables(), true)) {
            throw new InvalidArgumentException('Not a tenant table: ' . $table);
        }
    }

    /** Extra tenant tables registered by modules (Tenant::registerTable). */
    private static array $extra = [];

    public static function registerTable(string $table): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $table) && !in_array($table, self::$extra, true)) {
            self::$extra[] = $table;
        }
    }

    public static function extraTables(): array
    {
        return self::$extra;
    }

    public static function isTenantTable(string $table): bool
    {
        return in_array($table, self::TABLES, true) || in_array($table, self::$extra, true);
    }
}

