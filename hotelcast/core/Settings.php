<?php
declare(strict_types=1);

/** Key/value system settings stored in `system_settings`. */
final class Settings
{
    private static ?array $cache = null;

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
        'github_repo' => '',
        'github_branch' => 'main',
        'github_token' => '',
        'github_subdir' => 'hotelcast',
        'last_update_check' => '',
        'content_version' => '1',
        'last_tick' => '0',
        'backup_keep' => '10',
        'log_retention_days' => '90',
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (DB::all('SELECT setting_key, setting_value FROM system_settings') as $row) {
                    self::$cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                Logger::error('Settings load failed: ' . $e->getMessage());
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
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

    public static function set(string $key, mixed $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        DB::query(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => $key, 'v' => $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
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
        self::$cache = null;
    }

    /** Invalidate every room's content cache (called after any content/assignment change). */
    public static function bumpContentVersion(): void
    {
        DB::query(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES ('content_version', '2')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
        );
        if (self::$cache !== null) {
            self::$cache['content_version'] = (string) DB::value("SELECT setting_value FROM system_settings WHERE setting_key='content_version'");
        }
        Cache::clear('content');
    }
}
