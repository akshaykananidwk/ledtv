<?php
declare(strict_types=1);

/**
 * Shop / mall offers (#4, display app "offers"). Tenant table `offers` (migrations/017_business_apps.sql),
 * managed in admin/offers.php, shown by core/Apps/OffersApp.php.
 * An offer is "live" when active and now is inside valid_from … valid_to (both optional; valid_to is
 * exclusive, so an offer disappears from the TV the moment it ends). Order: sort asc, newest first.
 * Discount: discount_pct when set (manual), else computed from old_price → price.
 */
final class Offers
{
    public const MAX_DESC = 1000;

    public const DEFAULTS = [
        'id' => 0, 'title' => '', 'description' => '', 'price' => null, 'old_price' => null, 'discount_pct' => null,
        'badge' => '', 'valid_from' => null, 'valid_to' => null, 'sort' => 0, 'image_path' => null, 'thumb_path' => null, 'is_active' => 1,
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('offers', $id);
    }

    public static function all(): array
    {
        return DB::all('SELECT * FROM offers WHERE hotel_id = :h ORDER BY is_active DESC, sort, id DESC', ['h' => Tenant::id()]);
    }

    /** Offers on screen at $now (unix time, hotel time zone). */
    public static function current(int $limit = 50, ?int $now = null): array
    {
        $t = date('Y-m-d H:i:s', $now ?? time());
        return DB::all(
            'SELECT * FROM offers WHERE hotel_id = :h AND is_active = 1 AND (valid_from IS NULL OR valid_from <= :t1) AND (valid_to IS NULL OR valid_to > :t2)
             ORDER BY sort, id DESC LIMIT ' . max(1, min(200, $limit)),
            ['h' => Tenant::id(), 't1' => $t, 't2' => $t]
        );
    }

    /** 'live' | 'scheduled' | 'expired' | 'off' */
    public static function state(array $o, ?int $now = null): string
    {
        $t = date('Y-m-d H:i:s', $now ?? time());
        if (!(int) $o['is_active']) {
            return 'off';
        }
        if (!empty($o['valid_from']) && $o['valid_from'] > $t) {
            return 'scheduled';
        }
        if (!empty($o['valid_to']) && $o['valid_to'] <= $t) {
            return 'expired';
        }
        return 'live';
    }

    /** Discount in percent (manual or computed from the old price), null when there is none. */
    public static function discount(array $o): ?int
    {
        if ($o['discount_pct'] !== null && $o['discount_pct'] !== '' && (int) $o['discount_pct'] > 0) {
            return (int) $o['discount_pct'];
        }
        $p = $o['price'] !== null && $o['price'] !== '' ? (float) $o['price'] : null;
        $old = $o['old_price'] !== null && $o['old_price'] !== '' ? (float) $o['old_price'] : null;
        if ($p === null || $old === null || $old <= 0 || $p >= $old) {
            return null;
        }
        $d = (int) round(($old - $p) / $old * 100);
        return $d > 0 ? min(99, $d) : null;
    }

    /** @return array{0: array, 1: string[]} [row data, errors] */
    public static function validate(array $in): array
    {
        $errors = [];
        $title = BusinessApps::str($in, 'title', 400);
        if ($title === '') {
            $errors[] = __('The offer title is required.');
        } elseif (mb_strlen($title) > 190) {
            $errors[] = __('The title can have at most 190 characters.');
        }
        $desc = BusinessApps::text($in, 'description', self::MAX_DESC + 1);
        if (mb_strlen($desc) > self::MAX_DESC) {
            $errors[] = __('The description can have at most :n characters.', ['n' => self::MAX_DESC]);
        }
        $price = BusinessApps::number($in, 'price', __('Offer price'), 0, 9999999999, $errors);
        $old = BusinessApps::number($in, 'old_price', __('Old price (MRP)'), 0, 9999999999, $errors);
        $pct = BusinessApps::number($in, 'discount_pct', __('Discount %'), 0, 99, $errors);
        if ($price !== null && $old !== null && $old > 0 && $price > $old) {
            $errors[] = __('The offer price must not be higher than the old price.');
        }
        $from = BusinessApps::dateTime($in, 'valid_from', $errors);
        $to = BusinessApps::dateTime($in, 'valid_to', $errors);
        if ($from && $to && $to <= $from) {
            $errors[] = __('The end must be after the start.');
        }
        return [[
            'title' => mb_substr($title, 0, 190),
            'description' => mb_substr($desc, 0, self::MAX_DESC),
            'price' => $price,
            'old_price' => $old,
            'discount_pct' => $pct !== null && $pct > 0 ? (int) round($pct) : null,
            'badge' => BusinessApps::str($in, 'badge', 40),
            'valid_from' => $from,
            'valid_to' => $to,
            'sort' => is_numeric($in['sort'] ?? null) ? max(-1000, min(1000, (int) $in['sort'])) : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('offers', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('offers', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $o = self::find($id);
        if (!$o) {
            return;
        }
        DB::delete('offers', 'id = :id', ['id' => $id]);
        Uploader::delete($o['image_path'], $o['thumb_path']);
    }

    public static function imageUrl(array $o): ?string
    {
        return !empty($o['image_path']) ? media_url((string) $o['image_path']) : null;
    }
}
