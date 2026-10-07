<?php
declare(strict_types=1);

/**
 * Restaurant menu board (display app "menu_board", core/Apps/MenuBoardApp.php).
 *
 * Shares the room-service menu (guest_menu_categories / guest_menu_items, migration 003) so the
 * guest app and the TV board show one menu. Migration 016 adds the board columns:
 *   items:      is_sold_out (one-click "sold out" switch, also blocks room-service orders),
 *               show_on_board, is_special ("Today's special"), badge (BADGES key), price_old (struck)
 *   categories: show_on_board, board_from / board_to (dayparting, hotel time; overnight allowed)
 * Managed in admin/menu_board.php (permission menu_board.manage). Works without the guest-services
 * plan feature (restaurants that only use the TV board).
 */
final class MenuBoard
{
    /** Badge keys → English label (translated with __()). */
    public const BADGES = ['new' => 'New', 'chef' => "Chef's special", 'bestseller' => 'Bestseller', 'spicy' => 'Spicy', 'healthy' => 'Healthy'];
    /** Indian food symbols: veg = green, non-veg = red, egg = amber. */
    public const FOOD_COLORS = ['veg' => '#1B8E3E', 'nonveg' => '#C62828', 'egg' => '#E6A100'];
    public const FOOD_LABELS = ['veg' => 'Veg', 'nonveg' => 'Non-veg', 'egg' => 'Egg', 'none' => 'Not marked'];

    public static function categories(): array
    {
        return DB::all('SELECT * FROM guest_menu_categories WHERE hotel_id = :h ORDER BY sort_order, id', ['h' => Tenant::id()]);
    }

    public static function items(): array
    {
        return DB::all(
            'SELECT i.* FROM guest_menu_items i JOIN guest_menu_categories c ON c.id = i.category_id AND c.hotel_id = i.hotel_id
             WHERE i.hotel_id = :h ORDER BY c.sort_order, c.id, i.sort_order, i.id',
            ['h' => Tenant::id()]
        );
    }

    public static function findItem(int $id): ?array
    {
        return Tenant::find('guest_menu_items', $id);
    }

    public static function findCategory(int $id): ?array
    {
        return Tenant::find('guest_menu_categories', $id);
    }

    /** "08:00" → "08:00:00", '' → null, invalid → false. */
    private static function time(mixed $v): string|null|false
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) {
            return false;
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    /** Is "now" (H:i:s) inside from…to? Empty or equal bounds = always; overnight windows allowed. */
    public static function inWindow(?string $from, ?string $to, ?string $now = null): bool
    {
        if (!$from || !$to || $from === $to) {
            return true;
        }
        $now ??= date('H:i:s');
        return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    // ------------------------------------------------------------------ save

    /**
     * Save a category (base fields via GuestServices::saveCategory + board fields).
     * @return array{0: ?int, 1: string[]}
     */
    public static function saveCategory(array $in, ?int $id = null): array
    {
        $from = self::time($in['board_from'] ?? '');
        $to = self::time($in['board_to'] ?? '');
        if ($from === false || $to === false || (($from === null) !== ($to === null))) {
            return [null, [__('Enter both "shown from" and "shown until" times, or leave both empty.')]];
        }
        if ($id && !self::findCategory($id)) {
            return [null, [__('Not found.')]];
        }
        [$newId, $errors] = GuestServices::saveCategory($in, $id);
        if ($errors || !$newId) {
            return [null, $errors];
        }
        DB::update('guest_menu_categories', [
            'show_on_board' => !empty($in['show_on_board']) ? 1 : 0,
            'board_from' => $from,
            'board_to' => $to,
        ], 'id = :id', ['id' => $newId]);
        return [$newId, []];
    }

    /**
     * Save an item: base fields via GuestServices::saveItem (names, price, type, photo, hours),
     * then the board fields. @return array{0: ?int, 1: string[]}
     */
    public static function saveItem(array $in, ?int $id = null, ?array $photo = null): array
    {
        $errors = [];
        $old = trim(str_replace(',', '', is_string($in['price_old'] ?? null) ? $in['price_old'] : ''));
        if ($old !== '' && (!is_numeric($old) || (float) $old < 0 || (float) $old > 1000000)) {
            $errors[] = __('The old price is not valid.');
        }
        $badge = is_string($in['badge'] ?? null) && isset(self::BADGES[$in['badge']]) ? $in['badge'] : null;
        if ($id && !self::findItem($id)) {
            return [null, [__('Not found.')]];
        }
        if ($errors) {
            return [null, $errors];
        }
        [$newId, $errors] = GuestServices::saveItem($in, $id, $photo);
        if ($errors || !$newId) {
            return [null, $errors];
        }
        DB::update('guest_menu_items', [
            'price_old' => $old === '' || (float) $old <= 0 ? null : round((float) $old, 2),
            'badge' => $badge,
            'is_special' => !empty($in['is_special']) ? 1 : 0,
            'show_on_board' => !empty($in['show_on_board']) ? 1 : 0,
            'is_sold_out' => !empty($in['is_sold_out']) ? 1 : 0,
        ], 'id = :id', ['id' => $newId]);
        return [$newId, []];
    }

    /** One-click switches: field sold_out | special | board. Returns the new value. */
    public static function toggle(array $item, string $field): int
    {
        $col = ['sold_out' => 'is_sold_out', 'special' => 'is_special', 'board' => 'show_on_board'][$field] ?? null;
        if ($col === null) {
            throw new InvalidArgumentException('Unknown switch');
        }
        $v = (int) $item[$col] ? 0 : 1;
        DB::update('guest_menu_items', [$col => $v], 'id = :id', ['id' => (int) $item['id']]);
        return $v;
    }

    /** Move an item (within its category) or a category one place up / down (renumbers sort_order). */
    public static function move(string $table, array $row, string $dir): void
    {
        if ($table === 'guest_menu_items') {
            $ids = DB::column('SELECT id FROM guest_menu_items WHERE hotel_id = :h AND category_id = :c ORDER BY sort_order, id', ['h' => Tenant::id(), 'c' => (int) $row['category_id']]);
        } elseif ($table === 'guest_menu_categories') {
            $ids = DB::column('SELECT id FROM guest_menu_categories WHERE hotel_id = :h ORDER BY sort_order, id', ['h' => Tenant::id()]);
        } else {
            throw new InvalidArgumentException('Unknown table');
        }
        $ids = array_map('intval', $ids);
        $pos = array_search((int) $row['id'], $ids, true);
        if ($pos === false) {
            return;
        }
        $to = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        DB::transaction(static function () use ($table, $ids): void {
            foreach ($ids as $i => $id) {
                DB::update($table, ['sort_order' => ($i + 1) * 10], 'id = :id', ['id' => $id]);
            }
        });
    }

    // ------------------------------------------------------------------ TV data

    /** "₹120" / "₹99.50" */
    public static function price(float $p, string $currency): string
    {
        $s = abs($p - round($p)) < 0.005 ? number_format($p, 0, '.', ',') : number_format($p, 2, '.', ',');
        return $currency . $s;
    }

    /**
     * Board data for the TV: categories (in order) with their visible items.
     * $o: categories (ids, [] = all), sold_out ('hide' | 'strike'), currency, lang, daypart (bool),
     *     now (H:i:s, tests). Hidden: inactive or off-board categories / items, categories outside
     *     their board hours (daypart), items outside their room-service hours, sold-out items when
     *     sold_out = 'hide'.
     * @return array{categories: array, specials: array}
     */
    public static function board(array $o): array
    {
        $lang = (string) ($o['lang'] ?? 'en');
        $cur = (string) ($o['currency'] ?? '₹');
        $hide = ($o['sold_out'] ?? 'strike') === 'hide';
        $now = (string) ($o['now'] ?? date('H:i:s'));
        $only = array_map('intval', (array) ($o['categories'] ?? []));
        $cats = [];
        foreach (DB::all('SELECT id, name_en, name_gu, name_hi, board_from, board_to FROM guest_menu_categories WHERE hotel_id = :h AND is_active = 1 AND show_on_board = 1 ORDER BY sort_order, id', ['h' => Tenant::id()]) as $c) {
            if ($only && !in_array((int) $c['id'], $only, true)) {
                continue;
            }
            if (!empty($o['daypart']) && !self::inWindow($c['board_from'], $c['board_to'], $now)) {
                continue;
            }
            $cats[(int) $c['id']] = ['id' => (int) $c['id'], 'name' => GuestServices::loc($c, 'name', $lang), 'items' => []];
        }
        $specials = [];
        if ($cats) {
            [$in, $p] = DB::in(array_keys($cats), 'c');
            $rows = DB::all(
                "SELECT id, category_id, name_en, name_gu, name_hi, description_en, description_gu, description_hi, price, price_old, food_type,
                        photo_path, available_from, available_to, is_sold_out, is_special, badge
                 FROM guest_menu_items WHERE hotel_id = :h AND is_active = 1 AND show_on_board = 1 AND category_id IN $in
                 ORDER BY sort_order, id",
                $p + ['h' => Tenant::id()]
            );
            foreach ($rows as $r) {
                $sold = (int) $r['is_sold_out'] === 1;
                if (($sold && $hide) || !self::inWindow($r['available_from'], $r['available_to'], $now)) {
                    continue;
                }
                $price = (float) $r['price'];
                $old = $r['price_old'] !== null && (float) $r['price_old'] > $price ? self::price((float) $r['price_old'], $cur) : '';
                $badge = isset(self::BADGES[(string) $r['badge']]) ? (string) $r['badge'] : '';
                $it = [
                    'id' => (int) $r['id'],
                    'name' => GuestServices::loc($r, 'name', $lang),
                    'desc' => GuestServices::loc($r, 'description', $lang),
                    'price' => self::price($price, $cur),
                    'old' => $old,
                    'food' => isset(self::FOOD_COLORS[$r['food_type']]) ? (string) $r['food_type'] : '',
                    'photo' => $r['photo_path'] ? media_url((string) $r['photo_path']) : null,
                    'sold' => $sold,
                    'special' => (int) $r['is_special'] === 1,
                    'badge' => $badge !== '' ? __(self::BADGES[$badge]) : '',
                    'cat' => $cats[(int) $r['category_id']]['name'],
                ];
                $cats[(int) $r['category_id']]['items'][] = $it;
                if ($it['special'] && !$sold) {
                    $specials[] = $it;
                }
            }
        }
        return ['categories' => array_values(array_filter($cats, static fn ($c) => $c['items'])), 'specials' => $specials];
    }
}
