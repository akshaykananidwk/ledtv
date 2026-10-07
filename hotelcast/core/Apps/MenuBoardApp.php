<?php
declare(strict_types=1);

/**
 * Restaurant menu board: dishes and prices from the shared menu (core/MenuBoard.php, managed in
 * admin/menu_board.php). Layouts: classic 2–3 column list, photo cards, or "today's special" hero +
 * list. Veg / non-veg symbols, sold-out items hidden or struck, category filter (one TV breakfast,
 * another drinks), dayparting by category hours, automatic paging. Live refresh every 10 s, so the
 * kitchen's "sold out" switch reaches the TVs within seconds.
 */
final class MenuBoardApp extends DisplayApp
{
    public function key(): string
    {
        return 'menu_board';
    }

    public function label(): string
    {
        return __('Menu board');
    }

    public function description(): string
    {
        return __('Restaurant menu with prices, veg / non-veg symbols, photos and today\'s special. Sold-out dishes update live.');
    }

    public function icon(): string
    {
        return 'bi-egg-fried';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('menu_board.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'layout' => 'list',
            'columns' => 2,
            'categories' => [],
            'sold_out' => 'strike',
            'currency' => '₹',
            'show_desc' => true,
            'show_photos' => true,
            'daypart' => true,
            'page_sec' => 12,
            'show_clock' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $cats = array_values(array_unique(array_filter(array_map('intval', array_filter((array) ($in['categories'] ?? []), 'is_scalar')), static fn ($v) => $v > 0)));
        // Another hotel's category id → 404 (Tenant::deny); unknown ids are dropped.
        $cats = $cats ? array_values(array_intersect($cats, Tenant::assertOwnsAll('guest_menu_categories', $cats))) : [];
        $cur = self::str($in, 'currency', 5, '₹');
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'layout' => self::choice($in, 'layout', ['list', 'grid', 'special'], 'list'),
            'columns' => self::int($in, 'columns', 1, 3, 2),
            'categories' => $cats,
            'sold_out' => self::choice($in, 'sold_out', ['strike', 'hide'], 'strike'),
            'currency' => $cur,
            'show_desc' => self::bool($in, 'show_desc'),
            'show_photos' => self::bool($in, 'show_photos'),
            'daypart' => self::bool($in, 'daypart'),
            'page_sec' => self::int($in, 'page_sec', 4, 120, 12),
            'show_clock' => self::bool($in, 'show_clock'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $cats = [];
        foreach (MenuBoard::categories() as $c) {
            $cats[(int) $c['id']] = (string) $c['name_en'];
        }
        $h = self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Our menu')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::select('layout', __('Layout'), [
                'list' => __('Classic menu board (columns with prices)'),
                'grid' => __('Photo cards'),
                'special' => __('Today\'s special + list'),
            ], $config['layout'], '', 'col-md-6')
            . self::select('columns', __('Columns'), ['1' => '1', '2' => '2', '3' => '3'], (string) $config['columns'], '', 'col-md-3')
            . self::input('currency', __('Currency symbol'), $config['currency'], 'text', ['maxlength' => 5], '', 'col-md-3');
        if ($cats) {
            $h .= self::checkboxes('categories', __('Show these categories (none ticked = all)'), $cats, $config['categories']);
        } else {
            $h .= '<div class="col-12"><div class="alert alert-info mb-0">' . e(__('No dishes yet. Add categories and dishes on the Menu board page.')) . '</div></div>';
        }
        return $h
            . self::select('sold_out', __('Sold-out dishes'), ['strike' => __('Show struck through with "Sold out"'), 'hide' => __('Hide them')], $config['sold_out'], '', 'col-md-6')
            . self::input('page_sec', __('Change page every (seconds)'), $config['page_sec'], 'number', ['min' => 4, 'max' => 120], __('When the menu does not fit on one screen.'), 'col-md-6')
            . self::checkbox('daypart', __('Use category hours (e.g. breakfast 7–11)'), (bool) $config['daypart'], 'col-md-6')
            . self::checkbox('show_desc', __('Show descriptions'), (bool) $config['show_desc'], 'col-md-6')
            . self::checkbox('show_photos', __('Show photos'), (bool) $config['show_photos'], 'col-md-6')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-6');
    }

    /** Sample menu for previews of a hotel without dishes. */
    private static function sample(string $cur): array
    {
        $i = static fn (int $id, string $n, string $d, int $p, string $f, bool $sold = false, bool $sp = false, string $badge = '', string $old = ''): array => [
            'id' => $id, 'name' => $n, 'desc' => $d, 'price' => MenuBoard::price($p, $cur), 'old' => $old, 'food' => $f, 'photo' => null,
            'sold' => $sold, 'special' => $sp, 'badge' => $badge, 'cat' => '',
        ];
        $cats = [
            ['id' => 0, 'name' => __('Breakfast'), 'items' => [$i(1, __('Masala dosa'), __('Crisp dosa, potato bhaji, chutney, sambar'), 120, 'veg', false, true, __("Chef's special")), $i(2, __('Idli sambar'), '', 80, 'veg'), $i(3, __('Masala omelette'), '', 90, 'egg', true)]],
            ['id' => 0, 'name' => __('Main course'), 'items' => [$i(4, __('Gujarati thali'), __('Dal, kadhi, two sabzi, rotli, rice, sweet'), 250, 'veg', false, false, __('Bestseller'), MenuBoard::price(280, $cur)), $i(5, __('Paneer butter masala'), '', 220, 'veg'), $i(6, __('Chicken biryani'), '', 280, 'nonveg')]],
        ];
        foreach ($cats as $ci => $c) {
            foreach ($c['items'] as $k => $it) {
                $cats[$ci]['items'][$k]['cat'] = $c['name'];
            }
        }
        return ['categories' => $cats, 'specials' => [$cats[0]['items'][0]]];
    }

    private function board(array $config, array $ctx): array
    {
        $b = MenuBoard::board([
            'categories' => (array) $config['categories'], 'sold_out' => $config['sold_out'], 'currency' => (string) $config['currency'],
            'lang' => (string) $ctx['lang'], 'daypart' => (bool) $config['daypart'],
        ]);
        if (!$b['categories'] && !empty($ctx['preview'])) {
            $b = self::sample((string) $config['currency']);
        }
        return $b;
    }

    public function data(array $config, array $ctx): ?array
    {
        return $this->board($config, $ctx) + [
            'layout' => $config['layout'],
            'columns' => (int) $config['columns'],
            'page_sec' => (int) $config['page_sec'],
            'show_desc' => (bool) $config['show_desc'],
            'show_photos' => (bool) $config['show_photos'],
            't' => ['sold' => __('Sold out'), 'special' => __('Today\'s special'), 'empty' => __('The menu will be available soon.')],
        ];
    }

    public function refreshSec(array $config): int
    {
        return 10;
    }

    /** Same markup as assets/display/apps/menu_board.js → row(). */
    public static function row(array $it, bool $desc, bool $photo): string
    {
        $h = '<div class="mb-item' . ($it['sold'] ? ' is-sold' : '') . ($it['special'] ? ' is-special' : '') . '">';
        if ($photo && $it['photo']) {
            $h .= '<img class="mb-thumb" alt="" src="' . e($it['photo']) . '">';
        }
        $h .= '<div class="mb-main"><div class="mb-line">' . self::food($it['food']) . '<span class="mb-name">' . e($it['name']) . '</span>';
        if ($it['badge'] !== '') {
            $h .= '<span class="mb-badge">' . e($it['badge']) . '</span>';
        }
        $h .= '<span class="mb-dots"></span>';
        if ($it['old'] !== '') {
            $h .= '<s class="mb-old">' . e($it['old']) . '</s>';
        }
        $h .= '<span class="mb-price">' . e($it['price']) . '</span></div>';
        if ($it['sold']) {
            $h .= '<div class="mb-sold">' . e(__('Sold out')) . '</div>';
        } elseif ($desc && $it['desc'] !== '') {
            $h .= '<div class="mb-desc">' . e($it['desc']) . '</div>';
        }
        return $h . '</div></div>';
    }

    /** Indian veg / non-veg symbol (square with a dot / triangle). */
    public static function food(string $f): string
    {
        return $f !== '' ? '<i class="mb-food mb-' . e($f) . '" title="' . e(__(MenuBoard::FOOD_LABELS[$f] ?? '')) . '"></i>' : '';
    }

    public function render(array $config, array $ctx): string
    {
        $b = $this->board($config, $ctx);
        $heading = $config['heading'] !== '' ? $config['heading'] : __('Our menu');
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="12"></div><div class="hc-date" data-hc-date></div></div>';
        }
        $h .= '</div><div class="hc-body mb-body mb-layout-' . e($config['layout']) . ' mb-cols-' . (int) $config['columns'] . '" id="mbBoard">';
        if (!$b['categories']) {
            return $h . '<div class="hc-empty">' . e(__('The menu will be available soon.')) . '</div></div>';
        }
        // Server-side markup (no paging); the script re-lays it out in pages that fit the screen.
        $desc = (bool) $config['show_desc'];
        $photos = (bool) $config['show_photos'];
        if ($config['layout'] === 'special' && $b['specials']) {
            $s = $b['specials'][0];
            $h .= '<div class="mb-hero hc-card">' . ($s['photo'] ? '<img class="mb-hero-img" alt="" src="' . e($s['photo']) . '">' : '')
                . '<div class="mb-hero-tag">' . e(__('Today\'s special')) . '</div><div class="mb-hero-name">' . self::food($s['food']) . e($s['name']) . '</div>'
                . ($s['desc'] !== '' ? '<div class="mb-hero-desc">' . e($s['desc']) . '</div>' : '') . '<div class="mb-hero-price">' . e($s['price']) . '</div></div>';
        }
        $h .= '<div class="mb-pages">';
        foreach ($b['categories'] as $c) {
            $h .= '<div class="mb-cat">' . e($c['name']) . '</div>';
            foreach ($c['items'] as $it) {
                if ($config['layout'] === 'grid') {
                    $h .= '<div class="mb-card hc-card' . ($it['sold'] ? ' is-sold' : '') . '">' . ($it['photo'] ? '<img alt="" src="' . e($it['photo']) . '">' : '')
                        . '<div class="mb-card-name">' . self::food($it['food']) . e($it['name']) . '</div><div class="mb-card-price">' . e($it['price']) . '</div></div>';
                } else {
                    $h .= self::row($it, $desc, $photos);
                }
            }
        }
        return $h . '</div></div>';
    }
}
