<?php
declare(strict_types=1);

/**
 * White-label branding (#21): platform defaults (hotel 0 settings) ← reseller override ← hotel
 * override. Used on the login page, admin header, installer and in the TV Content object.
 */
final class Branding
{
    /** Product name shown everywhere unless a white-label name is set (Platform settings / reseller / hotel). */
    public const DEFAULT_PRODUCT = 'Krishna Cloud TV Management';

    private static array $cache = [];

    /**
     * ['product', 'logo_url', 'logo_path', 'color', 'support_phone', 'support_email', 'footer'].
     * $hotelId null = current hotel (Tenant), 0 = platform only.
     */
    public static function get(?int $hotelId = null): array
    {
        $hotelId ??= Tenant::current() ?? 0;
        if (isset(self::$cache[$hotelId])) {
            return self::$cache[$hotelId];
        }
        $b = [
            'product' => trim((string) Settings::platform('platform_name', self::DEFAULT_PRODUCT)) ?: self::DEFAULT_PRODUCT,
            'logo_path' => (string) Settings::platform('platform_logo', ''),
            'color' => clean_color((string) Settings::platform('platform_color', '#7B1FA2'), '#7B1FA2'),
            'support_phone' => (string) Settings::platform('platform_support_phone', ''),
            'support_email' => (string) Settings::platform('platform_support_email', ''),
            'footer' => (string) Settings::platform('platform_footer', ''),
        ];
        if ($hotelId > 0) {
            try {
                $h = DB::one(
                    'SELECT h.brand_name, h.brand_logo, h.brand_color, r.brand_name AS r_name, r.brand_logo AS r_logo,
                            r.brand_color AS r_color, r.support_phone AS r_phone, r.support_email AS r_email
                     FROM hotels h LEFT JOIN resellers r ON r.id = h.reseller_id WHERE h.id = :id',
                    ['id' => $hotelId]
                );
            } catch (Throwable) {
                $h = null;
            }
            if ($h) {
                $b = self::merge($b, $h['r_name'], $h['r_logo'], $h['r_color'], $h['r_phone'], $h['r_email']);
                $b = self::merge($b, $h['brand_name'], $h['brand_logo'], $h['brand_color'], null, null);
            }
        }
        $b['logo_url'] = $b['logo_path'] !== '' ? media_url($b['logo_path']) : null;
        return self::$cache[$hotelId] = $b;
    }

    private static function merge(array $b, ?string $name, ?string $logo, ?string $color, ?string $phone, ?string $email): array
    {
        if ($name !== null && trim($name) !== '') {
            $b['product'] = trim($name);
        }
        if ($logo !== null && $logo !== '') {
            $b['logo_path'] = $logo;
        }
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $b['color'] = strtoupper($color);
        }
        if ($phone !== null && trim($phone) !== '') {
            $b['support_phone'] = trim($phone);
        }
        if ($email !== null && trim($email) !== '') {
            $b['support_email'] = trim($email);
        }
        return $b;
    }

    /** The `branding` field of the TV Content object. */
    public static function forTv(?int $hotelId = null): array
    {
        $b = self::get($hotelId);
        return [
            'product' => $b['product'],
            'logo_url' => $b['logo_url'],
            'color' => $b['color'],
            'support' => $b['support_phone'] !== '' ? $b['support_phone'] : ($b['support_email'] !== '' ? $b['support_email'] : null),
        ];
    }

    /** Product name for the installer (before the database exists): config 'brand_name' or DEFAULT_PRODUCT. */
    public static function installerName(): string
    {
        $n = trim((string) Config::get('brand_name', ''));
        return $n !== '' ? $n : self::DEFAULT_PRODUCT;
    }

    /** Darker shade of a #RRGGBB colour (for hover / gradients). */
    public static function shade(string $hex, float $factor = 0.8): string
    {
        $hex = ltrim(clean_color($hex, '#7B1FA2'), '#');
        $rgb = array_map(fn ($c) => max(0, min(255, (int) round(hexdec($c) * $factor))), str_split($hex, 2));
        return sprintf('#%02X%02X%02X', ...$rgb);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
