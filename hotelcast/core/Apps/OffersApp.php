<?php
declare(strict_types=1);

/**
 * Shop / mall offers (#4): rotating offer cards with a big discount badge, struck-through old price,
 * ₹ prices and a live "Ends in 2d 04:13:22" countdown. Offers come from admin/offers.php
 * (core/Offers.php); expired offers leave the screen by themselves (live refresh every 15 s, and the
 * page asks for new data the second a countdown reaches zero). Layouts: hero (one big offer at a
 * time), grid (2 × 2 pages), list (pages of 5 rows).
 */
final class OffersApp extends DisplayApp
{
    public const LAYOUTS = ['hero' => 1, 'grid' => 4, 'list' => 5];

    public function key(): string
    {
        return 'offers';
    }

    public function label(): string
    {
        return __('Shop offers');
    }

    public function description(): string
    {
        return __('Discount offers for shops and malls with old price, big % badge and a live countdown. Expired offers disappear automatically.');
    }

    public function icon(): string
    {
        return 'bi-tags';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('offers.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'layout' => 'hero',
            'rotate_sec' => 8,
            'max' => 20,
            'currency' => '₹',
            'show_countdown' => true,
            'show_clock' => true,
            'footer' => '',
        ];
    }

    public function validate(array $in): array
    {
        $cur = self::str($in, 'currency', 5, '₹');
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'layout' => self::choice($in, 'layout', self::LAYOUTS, 'hero'),
            'rotate_sec' => self::int($in, 'rotate_sec', 3, 120, 8),
            'max' => self::int($in, 'max', 1, 60, 20),
            'currency' => $cur !== '' ? $cur : '₹',
            'show_countdown' => self::bool($in, 'show_countdown'),
            'show_clock' => self::bool($in, 'show_clock'),
            'footer' => self::str($in, 'footer', 190),
        ], []];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Today\'s offers')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::select('layout', __('Layout'), ['hero' => __('One big offer at a time'), 'grid' => __('Grid (4 offers per page)'), 'list' => __('List (5 offers per page)')], $config['layout'], '', 'col-md-4')
            . self::input('rotate_sec', __('Change every (seconds)'), $config['rotate_sec'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::input('max', __('Maximum offers'), $config['max'], 'number', ['min' => 1, 'max' => 60], '', 'col-md-4')
            . self::input('currency', __('Currency symbol'), $config['currency'], 'text', ['maxlength' => 5], '', 'col-md-4')
            . self::input('footer', __('Footer text (optional)'), $config['footer'], 'text', ['maxlength' => 190, 'placeholder' => __('e.g. T&C apply. While stocks last.')], '', 'col-md-8')
            . self::checkbox('show_countdown', __('Show "ends in" countdown'), (bool) $config['show_countdown'], 'col-md-4')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-4');
    }

    /** Sample offers for the admin preview of a hotel without offers. */
    private static function samples(int $now): array
    {
        $base = ['id' => 0, 'image_path' => null, 'thumb_path' => null, 'discount_pct' => null, 'valid_from' => null, 'is_active' => 1, 'sort' => 0];
        return [
            ['title' => __('Festive sale: Silk sarees'), 'description' => __('Pure silk, all colours. Free fall and pico.'), 'price' => 1999, 'old_price' => 3499, 'badge' => __('Bestseller'), 'valid_to' => date('Y-m-d H:i:s', $now + 2 * 86400 + 4 * 3600)] + $base,
            ['title' => __('Buy 1 Get 1 free'), 'description' => __('On all shirts and kurtas.'), 'price' => 899, 'old_price' => 1798, 'badge' => __('New'), 'valid_to' => date('Y-m-d H:i:s', $now + 5 * 3600)] + $base,
            ['title' => __('Mobile accessories'), 'description' => '', 'price' => 249, 'old_price' => 399, 'badge' => '', 'valid_to' => null] + $base,
        ];
    }

    /** Offers as JSON-able rows for the TV (same shape as assets/display/apps/offers.js expects). */
    public static function rows(array $config, array $ctx): array
    {
        $now = (int) ($ctx['now'] ?? time());
        $rows = Offers::current((int) $config['max'], $now);
        if (!$rows && !empty($ctx['preview'])) {
            $rows = self::samples($now);
        }
        $out = [];
        foreach ($rows as $o) {
            $price = $o['price'] !== null ? (float) $o['price'] : null;
            $old = $o['old_price'] !== null ? (float) $o['old_price'] : null;
            $out[] = [
                'id' => (int) $o['id'],
                'title' => (string) $o['title'],
                'description' => (string) ($o['description'] ?? ''),
                'image' => Offers::imageUrl($o),
                'price' => BusinessApps::money($price, (string) $config['currency']),
                'old_price' => $old !== null && ($price === null || $old > $price) ? BusinessApps::money($old, (string) $config['currency']) : '',
                'discount' => Offers::discount($o),
                'badge' => (string) $o['badge'],
                'ends' => $config['show_countdown'] && !empty($o['valid_to']) ? (int) strtotime((string) $o['valid_to']) * 1000 : null,
            ];
        }
        return $out;
    }

    /** Texts used by the page script. */
    private static function texts(): array
    {
        return ['ends_in' => __('Ends in'), 'day' => __(':n d'), 'off' => __('OFF'), 'empty' => __('Add offers on the Offers page.')];
    }

    public function data(array $config, array $ctx): ?array
    {
        return [
            'offers' => self::rows($config, $ctx),
            'layout' => $config['layout'],
            'per_page' => self::LAYOUTS[$config['layout']] ?? 1,
            'rotate_sec' => (int) $config['rotate_sec'],
            'text' => self::texts(),
        ];
    }

    public function refreshSec(array $config): int
    {
        return 15;
    }

    /** "2d 04:13:22" / "04:13:22" for $sec seconds left (same as offers.js → left()). */
    public static function countdown(int $sec): string
    {
        $sec = max(0, $sec);
        $d = intdiv($sec, 86400);
        $hms = sprintf('%02d:%02d:%02d', intdiv($sec % 86400, 3600), intdiv($sec % 3600, 60), $sec % 60);
        return ($d > 0 ? __(':n d', ['n' => $d]) . ' ' : '') . $hms;
    }

    /** One offer card; same markup as offers.js → card(). */
    public static function card(array $o, int $nowMs): string
    {
        $h = '<div class="of-card hc-card' . ($o['image'] ? ' has-img' : '') . '">';
        if ($o['image']) {
            $h .= '<div class="of-img"><img alt="" src="' . e($o['image']) . '"></div>';
        }
        $h .= '<div class="of-info">';
        if ($o['badge'] !== '') {
            $h .= '<div class="of-bw"><span class="hc-badge of-badge">' . e($o['badge']) . '</span></div>';
        }
        $h .= '<div class="of-title">' . e($o['title']) . '</div>';
        if ($o['description'] !== '') {
            $h .= '<div class="of-desc">' . nl2br(e($o['description']), false) . '</div>';
        }
        if ($o['price'] !== '' || $o['old_price'] !== '') {
            $h .= '<div class="of-prices">' . ($o['price'] !== '' ? '<span class="of-price">' . e($o['price']) . '</span>' : '')
                . ($o['old_price'] !== '' ? '<s class="of-old">' . e($o['old_price']) . '</s>' : '') . '</div>';
        }
        if ($o['ends']) {
            $h .= '<div class="of-ends" data-ends="' . (int) $o['ends'] . '"><span>' . e(__('Ends in')) . '</span> <b>'
                . e(self::countdown(intdiv((int) $o['ends'] - $nowMs, 1000))) . '</b></div>';
        }
        $h .= '</div>';
        if ($o['discount']) {
            $h .= '<div class="of-disc"><b>' . (int) $o['discount'] . '%</b><span>' . e(__('OFF')) . '</span></div>';
        }
        return $h . '</div>';
    }

    public function render(array $config, array $ctx): string
    {
        $offers = self::rows($config, $ctx);
        $heading = $config['heading'] !== '' ? $config['heading'] : __('Today\'s offers');
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="12"></div><div class="hc-date" data-hc-date></div></div>';
        }
        $per = self::LAYOUTS[$config['layout']] ?? 1;
        $nowMs = (int) ($ctx['now'] ?? time()) * 1000;
        $pages = '';
        foreach (array_chunk($offers, $per) as $chunk) {
            $pages .= '<div class="hc-slide of-page">';
            foreach ($chunk as $o) {
                $pages .= self::card($o, $nowMs);
            }
            $pages .= '</div>';
        }
        $h .= '</div><div class="hc-body"><div id="ofList" class="hc-slides of-' . e($config['layout']) . '">'
            . ($pages !== '' ? $pages : '<div class="hc-empty">' . e(__('Add offers on the Offers page.')) . '</div>') . '</div></div>';
        if ($config['footer'] !== '') {
            $h .= '<div class="hc-footer of-footer">' . e($config['footer']) . '</div>';
        }
        return $h;
    }
}
