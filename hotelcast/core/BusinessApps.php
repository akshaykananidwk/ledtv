<?php
declare(strict_types=1);

/**
 * Shared helpers of the business display apps (offers, class schedule, departures, KPI dashboard):
 * form parsing (dates, times, numbers), Indian number formatting, time labels and the optional
 * image upload of the management pages. See docs/modules/business_apps.md.
 */
final class BusinessApps
{
    /** Trimmed single-line string from form input (null when missing / not a string). */
    public static function str(array $in, string $key, int $max = 190): string
    {
        $v = $in[$key] ?? '';
        if (!is_scalar($v)) {
            return '';
        }
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), 0, $max);
    }

    /** Multi-line text (\r\n → \n). */
    public static function text(array $in, string $key, int $max = 3000): string
    {
        $v = $in[$key] ?? '';
        return is_scalar($v) ? mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max) : '';
    }

    /**
     * Optional number: '' → null; invalid / out of range → error and null. Accepts "1,299.50".
     */
    public static function number(array $in, string $key, string $label, float $min, float $max, array &$errors): ?float
    {
        $v = $in[$key] ?? '';
        if (!is_scalar($v)) {
            return null;
        }
        $v = str_replace([',', ' ', '₹'], '', trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            $errors[] = __(':f: enter a number between :a and :b.', ['f' => $label, 'a' => self::plain($min), 'b' => self::plain($max)]);
            return null;
        }
        return round((float) $v, 2);
    }

    /** "Y-m-d" or null (empty); invalid → error. */
    public static function date(array $in, string $key, array &$errors): ?string
    {
        $v = trim(is_string($in[$key] ?? null) ? $in[$key] : '');
        if ($v === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v) {
            $errors[] = __('Invalid date: :d', ['d' => $v]);
            return null;
        }
        return $v;
    }

    /** "Y-m-d H:i:s" from datetime-local ("2026-10-07T18:30") or "Y-m-d H:i[:s]"; null when empty; invalid → error. */
    public static function dateTime(array $in, string $key, array &$errors): ?string
    {
        $v = trim(str_replace('T', ' ', is_string($in[$key] ?? null) ? $in[$key] : ''));
        if ($v === '') {
            return null;
        }
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $v);
            if ($dt && $dt->format(substr($fmt, 1)) === $v) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        $errors[] = __('Invalid date and time: :d', ['d' => $v]);
        return null;
    }

    /** "H:i:00" from "H:i" / "H:i:s"; null when empty or invalid. */
    public static function time(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($v), $m)) {
            return null;
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    /** Minutes since midnight of "H:i[:s]". */
    public static function minutes(string $time): int
    {
        $p = explode(':', $time);
        return (int) ($p[0] ?? 0) * 60 + (int) ($p[1] ?? 0);
    }

    /** "6:05 PM" (translated AM / PM) or "18:05" for "18:05:00". */
    public static function timeLabel(string $time, bool $h24): string
    {
        $m = self::minutes($time);
        $h = intdiv($m, 60) % 24;
        $min = $m % 60;
        if ($h24) {
            return sprintf('%02d:%02d', $h, $min);
        }
        return sprintf('%d:%02d', $h % 12 ?: 12, $min) . ' ' . ($h >= 12 ? __('PM') : __('AM'));
    }

    /** 1234567.5 → "12,34,567.50"; whole numbers without decimals ("1,299"). */
    public static function indian(float $n): string
    {
        $neg = $n < 0;
        $n = abs(round($n, 2));
        $int = (string) (int) floor($n);
        $dec = (int) round(($n - floor($n)) * 100);
        if (strlen($int) > 3) {
            $last = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last;
        }
        return ($neg ? '-' : '') . $int . ($dec > 0 ? '.' . sprintf('%02d', $dec) : '');
    }

    /** Currency + Indian grouping, e.g. "₹1,299". */
    public static function money(?float $n, string $currency = '₹'): string
    {
        return $n === null ? '' : $currency . self::indian($n);
    }

    /** 12.0 → "12", 12.5 → "12.5" */
    public static function plain(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * Optional image upload of a management page form ($_FILES[$field]).
     * @return array{0: ?array, 1: string[]} [['path','thumb'] | null when no file, errors]
     */
    public static function upload(string $field = 'image'): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, []];
        }
        try {
            $up = Uploader::handle($f, 'image');
            return [['path' => $up['path'], 'thumb' => $up['thumb']], []];
        } catch (RuntimeException $e) {
            return [null, [$e->getMessage()]];
        }
    }

    /** Number of app items of a display app in the current hotel (for "screens (n)" buttons). */
    public static function screens(string $app): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :hid AND type = 'app' AND settings LIKE :p", ['hid' => Tenant::id(), 'p' => '%"app":"' . $app . '"%']);
    }
}
