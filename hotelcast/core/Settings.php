<?php
declare(strict_types=1);

/**
 * Key/value settings stored in `system_settings` (PK hotel_id + setting_key).
 *
 *  - Hotel settings: Settings::get() reads the current hotel's value (Tenant), falling back to
 *    the platform row (hotel_id 0) and then DEFAULTS.
 *  - Platform settings (PLATFORM_KEYS, e.g. GitHub updater, branding, billing): always hotel 0.
 *    Settings::platform() / setPlatform() address them explicitly.
 */
final class Settings
{
    /** @var array<int, array<string, mixed>> cache per hotel id (0 = platform only) */
    private static array $cache = [];

    public const DEFAULTS = [
        'hotel_name' => 'HotelCast Hotel',
        'hotel_logo' => '',
        'timezone' => 'Asia/Kolkata',
        'default_language' => 'en',
        'poll_interval' => '8',
        'heartbeat_interval' => '60',
        'offline_after' => '90',
        'long_poll_enabled' => '0',
        'default_content_id' => '',
        'default_playlist_id' => '',
        'tv_settings_pin' => '1234',
        'registration_key' => '',
        'auto_create_rooms' => '1',
        'power_off_mode' => 'standby',
        'overlay_clock' => '1',
        'overlay_clock_format' => 'hh:mm a',
        'overlay_logo' => '1',
        'overlay_weather' => '0',
        'weather_city' => 'Dwarka',
        'weather_lat' => '22.2394',
        'weather_lon' => '68.9678',
        'ticker_text' => '',
        'ticker_bg_color' => '#000000',
        'ticker_text_color' => '#FFD700',
        'ticker_speed' => '5',
        'cdn_base_url' => '',
        'max_upload_mb' => '200',
        'image_max_width' => '1920',
        'notify_offline' => '0',
        'notify_email' => '',
        'notify_from_email' => '',
        'notify_whatsapp_url' => '',
        'notify_offline_minutes' => '5',
        'content_version' => '1',
        'log_retention_days' => '90',
        'suspended_message' => '',
    ];

    /** Platform-wide settings (hotel_id 0) with their defaults. */
    public const PLATFORM_DEFAULTS = [
        'github_repo' => '',
        'github_branch' => 'main',
        'github_token' => '',
        'github_subdir' => 'hotelcast',
        'last_update_check' => '',
        'backup_keep' => '10',
        'last_tick' => '0',
        // White-label branding (#21)
        'platform_name' => 'HotelCast',
        'platform_logo' => '',
        'platform_color' => '#7B1FA2',
        'platform_support_phone' => '',
        'platform_support_email' => '',
        'platform_footer' => '',
        // Billing (#20)
        'billing_currency' => 'INR',
        'billing_tax_percent' => '18',
        'billing_tax_label' => 'GST',
        'invoice_prefix' => 'HC',
        'invoice_due_days' => '15',
        'invoice_auto_generate' => '1',
        'invoice_seller_details' => '',
        'auto_suspend_days' => '15',
        'reminder_email' => '1',
        'reminder_whatsapp' => '0',
        'reminder_every_days' => '3',
        'billing_whatsapp_url' => '',
        'platform_notify_email' => '',
        'platform_from_email' => '',
        // License server (#19)
        'license_rate_per_min' => '30',
        // Self-hosted license client state (standalone mode)
        'license_state' => '',
    ];

    public const PLATFORM_KEYS = [
        'github_repo', 'github_branch', 'github_token', 'github_subdir', 'last_update_check', 'backup_keep', 'last_tick',
        'platform_name', 'platform_logo', 'platform_color', 'platform_support_phone', 'platform_support_email', 'platform_footer',
        'billing_currency', 'billing_tax_percent', 'billing_tax_label', 'invoice_prefix', 'invoice_due_days', 'invoice_auto_generate',
        'invoice_seller_details', 'auto_suspend_days', 'reminder_email', 'reminder_whatsapp', 'reminder_every_days',
        'billing_whatsapp_url', 'platform_notify_email', 'platform_from_email', 'license_rate_per_min', 'license_state',
    ];

    public static function isPlatformKey(string $key): bool
    {
        return in_array($key, self::PLATFORM_KEYS, true) || str_starts_with($key, 'task_last_') || str_starts_with($key, 'platform_');
    }

    /** Hotel id whose settings are read (current tenant, 0 when none). */
    private static function hid(): int
    {
        return Tenant::current() ?? 0;
    }

    /** All settings of a hotel (hotel row → platform row → defaults). */
    public static function all(?int $hotelId = null): array
    {
        $hid = $hotelId ?? self::hid();
        if (!isset(self::$cache[$hid])) {
            $values = self::DEFAULTS + self::PLATFORM_DEFAULTS;
            try {
                $rows = DB::all(
                    'SELECT hotel_id, setting_key, setting_value FROM system_settings WHERE hotel_id IN (0, :h) ORDER BY hotel_id',
                    ['h' => $hid]
                );
                foreach ($rows as $row) {
                    // Platform keys never come from a hotel row.
                    if ((int) $row['hotel_id'] !== 0 && self::isPlatformKey($row['setting_key'])) {
                        continue;
                    }
                    $values[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                Logger::error('Settings load failed: ' . $e->getMessage());
            }
            self::$cache[$hid] = $values;
        }
        return self::$cache[$hid];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all(self::isPlatformKey($key) ? 0 : null);
        return $all[$key] ?? $default;
    }

    /** Setting of a specific hotel (without switching the tenant). */
    public static function getFor(int $hotelId, string $key, mixed $default = null): mixed
    {
        $all = self::all(self::isPlatformKey($key) ? 0 : $hotelId);
        return $all[$key] ?? $default;
    }

    /** Platform-wide setting (hotel_id 0). */
    public static function platform(string $key, mixed $default = null): mixed
    {
        $all = self::all(0);
        return $all[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key): bool
    {
        return (string) self::get($key, '0') === '1';
    }

    /**
     * Save a setting for the current hotel (platform keys always go to hotel 0).
     * Throws TenantException for a hotel key when no hotel is selected.
     */
    public static function set(string $key, mixed $value): void
    {
        self::setFor(self::isPlatformKey($key) ? 0 : Tenant::id(), $key, $value);
    }

    public static function setPlatform(string $key, mixed $value): void
    {
        self::setFor(0, $key, $value);
    }

    /** Save a setting for a given hotel (0 = platform). */
    public static function setFor(int $hotelId, string $key, mixed $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if ($hotelId !== 0 && self::isPlatformKey($key)) {
            $hotelId = 0;
        }
        if ($hotelId > 0 && $key === 'registration_key') {
            // The registration key identifies the hotel: unique across hotels (hotels.registration_key).
            if ($value !== '' && DB::value('SELECT id FROM hotels WHERE registration_key = :k AND id <> :h', ['k' => $value, 'h' => $hotelId])) {
                throw new InvalidArgumentException('Registration key already used by another hotel');
            }
            DB::query('UPDATE hotels SET registration_key = :k WHERE id = :h', ['k' => $value === '' ? null : $value, 'h' => $hotelId]);
        }
        if ($hotelId > 0 && $key === 'hotel_name' && trim($value) !== '') {
            DB::query('UPDATE hotels SET name = :n WHERE id = :h', ['n' => mb_substr(trim($value), 0, 120), 'h' => $hotelId]);
            Tenant::forget($hotelId);
        }
        DB::query(
            'INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['h' => $hotelId, 'k' => $key, 'v' => $value]
        );
        if ($hotelId === 0) {
            self::$cache = []; // platform values are inherited by every hotel
        } else {
            unset(self::$cache[$hotelId]);
        }
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set((string) $k, $v);
        }
    }

    /** Secret settings (GitHub token) are stored encrypted with APP_KEY. */
    public static function setSecret(string $key, string $plain): void
    {
        self::set($key, $plain === '' ? '' : Crypto::encrypt($plain));
    }

    public static function secret(string $key): string
    {
        $v = (string) self::get($key, '');
        if ($v === '') {
            return '';
        }
        return Crypto::decrypt($v) ?? '';
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Invalidate the current hotel's content cache (called after any content/assignment change). */
    public static function bumpContentVersion(): void
    {
        $hid = self::hid();
        if ($hid === 0) {
            // No hotel context (e.g. platform maintenance): invalidate everything.
            Cache::clear();
            return;
        }
        DB::query(
            "INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, 'content_version', '2')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1",
            ['h' => $hid]
        );
        if (isset(self::$cache[$hid])) {
            self::$cache[$hid]['content_version'] = (string) DB::value(
                "SELECT setting_value FROM system_settings WHERE hotel_id = :h AND setting_key = 'content_version'",
                ['h' => $hid]
            );
        }
        Cache::clear(Cache::hotelNs('content'));
    }
}
