<?php
declare(strict_types=1);

/**
 * Shared helpers of the content display apps (docs/modules/content_apps.md): showcase (#9),
 * event welcome (#10), photo album (#19), sheet table (#14), social wall (#16).
 * Photo slideshow assets, library / album images, QR codes, WhatsApp links, date labels.
 */
final class ContentApps
{
    /** <link> + <script> of the shared slideshow (assets/display/apps/lib-slides.*), put in render(). */
    public static function slidesAssets(): string
    {
        return '<link rel="stylesheet" href="' . e(asset('display/apps/lib-slides.css')) . '">'
            . '<script src="' . e(asset('display/apps/lib-slides.js')) . '"></script>';
    }

    /** id => title of the hotel's image content items (for checkbox pickers). */
    public static function imageOptions(): array
    {
        $out = [];
        foreach (DB::all("SELECT id, title FROM content_items WHERE hotel_id = :h AND type = 'image' ORDER BY title, id LIMIT 300", ['h' => Tenant::id()]) as $r) {
            $out[(int) $r['id']] = (string) $r['title'];
        }
        return $out;
    }

    /**
     * Ids of image content items from form input (own hotel only: another hotel's id → 404 via
     * ContentManager::find; non-images and missing ids are dropped), at most $max, order kept.
     */
    public static function imageIds(mixed $raw, int $max = 30): array
    {
        $out = [];
        foreach ((array) $raw as $v) {
            if (!is_scalar($v) || !ctype_digit((string) $v) || (int) $v <= 0 || strlen((string) $v) > 9) {
                continue;
            }
            $row = ContentManager::find((int) $v);
            if ($row && $row['type'] === 'image' && !in_array((int) $v, $out, true)) {
                $out[] = (int) $v;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** Slides of image content items: [{id, url, caption, date}] (own hotel, images only). */
    public static function librarySlides(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $row = ContentManager::findOwn((int) $id);
            if (!$row || $row['type'] !== 'image' || !ContentRules::approved($row)) { // 2.4: never content waiting for approval
                continue;
            }
            $url = $row['file_path'] ? media_url((string) $row['file_path']) : ((string) $row['url'] ?: null);
            if ($url) {
                $out[] = ['id' => 'c' . (int) $row['id'], 'url' => $url, 'caption' => '', 'date' => ''];
            }
        }
        return $out;
    }

    /** Approved photos of an album of the current hotel as slides (none when the album is missing). */
    public static function albumSlides(int $albumId, int $max = Albums::MAX_PHOTOS): array
    {
        if ($albumId <= 0 || !DB::value('SELECT id FROM albums WHERE id = :id AND hotel_id = :h', ['id' => $albumId, 'h' => Tenant::id()])) {
            return [];
        }
        $out = [];
        foreach (Albums::photos($albumId, 'approved', $max) as $p) {
            $out[] = [
                'id' => (int) $p['id'],
                'url' => Albums::photoUrl($p),
                'caption' => (string) ($p['caption'] ?? ''),
                'date' => self::dateLabel(substr((string) $p['created_at'], 0, 10)),
            ];
        }
        return $out;
    }

    /** Album id from form input: 0 or an album of this hotel (another hotel's id → 404). */
    public static function albumId(array $in, string $key, array &$errors): int
    {
        $v = $in[$key] ?? 0;
        $id = is_scalar($v) && ctype_digit((string) $v) && strlen((string) $v) < 10 ? (int) $v : 0;
        if ($id > 0 && !Albums::find($id)) {
            $errors[] = __('Choose an album from the list.');
            return 0;
        }
        return $id;
    }

    /** "7 October 2026" (month translated) for a Y-m-d date, '' when invalid. */
    public static function dateLabel(string $ymd): string
    {
        $dt = DateTime::createFromFormat('!Y-m-d', $ymd);
        if (!$dt) {
            return '';
        }
        return $dt->format('j') . ' ' . __($dt->format('F')) . ' ' . $dt->format('Y');
    }

    /** Inline QR SVG, '' when empty / too long. */
    public static function qr(string $payload, string $dark = '#000000', string $light = '#FFFFFF', string $label = ''): string
    {
        if ($payload === '') {
            return '';
        }
        try {
            return QrCode::svg($payload, $dark, $light, $label);
        } catch (Throwable) {
            return '';
        }
    }

    /** Digits of a phone number (country code added for 10-digit Indian numbers). */
    public static function phoneDigits(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }
        return $d;
    }

    public static function whatsappLink(string $phone, string $text = ''): string
    {
        $d = self::phoneDigits($phone);
        if ($d === '') {
            return '';
        }
        return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    /** Non-empty trimmed lines of a text, at most $max lines of $len characters. */
    public static function lines(string $text, int $max = 30, int $len = 190): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = mb_substr($line, 0, $len);
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }
}
