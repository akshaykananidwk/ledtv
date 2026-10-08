<?php
declare(strict_types=1);

/**
 * Festival calendar + countdown (#28): today's festival as a hero (image, greeting), then the next N
 * festivals with "in N days". Optional theme switch: on a festival day with a theme (Diwali, Navratri …)
 * the page takes that festival's colours. Festivals come from admin/festivals.php (core/Festivals.php).
 */
final class FestivalsApp extends WidgetApp
{
    public function key(): string
    {
        return 'festivals';
    }

    public function label(): string
    {
        return __('Festival calendar');
    }

    public function description(): string
    {
        return __('Upcoming festivals with a countdown in days, and today\'s festival with its picture and greeting.');
    }

    public function icon(): string
    {
        return 'bi-stars';
    }

    public function adminPage(): ?string
    {
        return admin_url('festivals.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Festivals'),
            'subtitle' => '',
            'count' => 5,
            'days' => 365,
            'hero' => true,
            'images' => true,
            'descriptions' => true,
            'theme_switch' => false,
        ];
    }

    public function validate(array $in): array
    {
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'count' => self::int($in, 'count', 1, 12, 5),
            'days' => self::int($in, 'days', 7, 730, 365),
            'hero' => self::bool($in, 'hero'),
            'images' => self::bool($in, 'images'),
            'descriptions' => self::bool($in, 'descriptions'),
            'theme_switch' => self::bool($in, 'theme_switch'),
        ], []];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::input('count', __('Upcoming festivals shown'), $config['count'], 'number', ['min' => 1, 'max' => 12], '', 'col-6 col-md-3')
            . self::input('days', __('Look ahead (days)'), $config['days'], 'number', ['min' => 7, 'max' => 730], '', 'col-6 col-md-3')
            . self::checkbox('hero', __('Big greeting on the festival day'), (bool) $config['hero'])
            . self::checkbox('images', __('Show festival pictures'), (bool) $config['images'])
            . self::checkbox('descriptions', __('Show descriptions'), (bool) $config['descriptions'])
            . self::checkbox('theme_switch', __('Use the festival\'s colours on the festival day'), (bool) $config['theme_switch']);
    }

    public function refreshSec(array $config): int
    {
        return 600;
    }

    /** Sample festivals for an empty admin preview. */
    private static function samples(string $today): array
    {
        $out = [];
        foreach ([[12, 'Diwali', 'દિવાળી', 'दीपावली'], [40, 'Dev Diwali', 'દેવ દિવાળી', 'देव दीपावली']] as [$in, $en, $gu, $hi]) {
            $out[] = array_merge(Festivals::DEFAULTS, ['name_en' => $en, 'name_gu' => $gu, 'name_hi' => $hi, 'starts_on' => date('Y-m-d', (int) strtotime($today . ' +' . $in . ' days'))]);
        }
        return $out;
    }

    protected function body(array $config, array $ctx): string
    {
        $today = self::today($ctx);
        $lang = (string) $ctx['lang'];
        $now = $config['hero'] ? Festivals::today($today) : [];
        $next = Festivals::upcoming($today, (int) $config['count'], (int) $config['days']);
        if (!$now && !$next && $ctx['preview']) {
            $next = self::samples($today);
        }
        if (!$now && !$next) {
            return self::empty(__('No upcoming festivals. Add them on the Festivals page.'));
        }
        $html = '';
        if ($now) {
            $f = $now[0];
            $theme = (string) $f['theme'];
            if ($config['theme_switch'] && isset(DisplayApps::THEMES[$theme])) {
                $t = DisplayApps::THEMES[$theme] + ['key' => $theme];
                $t['accent_fg'] = DisplayApps::contrast($t['accent']);
                $t['font'] = (string) ($ctx['theme']['font'] ?? 'inherit');
                $html .= '<style>' . DisplayApps::themeCss($t) . '</style>';
            }
            $img = $config['images'] ? Festivals::imageUrl($f) : null;
            $name = Festivals::name($f, $lang);
            $html .= '<div class="fe-hero hc-card' . ($img ? ' fe-has-img' : '') . '">'
                . ($img ? '<div class="fe-hero-img" style="background-image:' . self::cssUrl($img) . '"></div>' : '<div class="fe-hero-deco">🪔</div>')
                . '<div class="fe-hero-text"><div class="fe-today">' . e(__('Today')) . '</div><h2 class="fe-hero-name">' . e($name) . '</h2>'
                . '<div class="fe-greet">' . e(__('Warm wishes on :name!', ['name' => $name])) . '</div>'
                . ($config['descriptions'] && trim((string) $f['description']) !== '' ? '<div class="fe-desc">' . nl2br(e((string) $f['description'])) . '</div>' : '')
                . (count($now) > 1 ? '<div class="fe-also hc-muted">' . e(__('Also today: :list', ['list' => implode(', ', array_map(static fn ($x) => Festivals::name($x, $lang), array_slice($now, 1)))])) . '</div>' : '')
                . '</div></div>';
        }
        if ($next) {
            $html .= '<div class="fe-list' . ($now ? ' fe-compact' : '') . '">';
            foreach ($next as $f) {
                $days = Festivals::daysUntil((string) $f['starts_on'], $today);
                $img = $config['images'] && !$now ? Festivals::imageUrl($f) : null;
                $when = self::dateLabel((string) $f['starts_on']) . (!empty($f['ends_on']) ? ' – ' . self::dateLabel((string) $f['ends_on'], false) : '');
                $html .= '<div class="fe-item hc-card">'
                    . ($img ? '<div class="fe-thumb" style="background-image:' . self::cssUrl($img) . '"></div>' : '')
                    . '<div class="fe-info"><div class="fe-name">' . e(Festivals::name($f, $lang)) . '</div><div class="fe-when hc-muted">' . e($when) . '</div>'
                    . ($config['descriptions'] && !$now && trim((string) $f['description']) !== '' ? '<div class="fe-small">' . e(mb_strimwidth((string) $f['description'], 0, 140, '…')) . '</div>' : '')
                    . '</div><div class="fe-count">' . ($days > 1 ? '<b>' . (int) $days . '</b><span>' . e(__('days')) . '</span>' : '<span class="fe-soon">' . e($days === 1 ? __('Tomorrow') : __('Today')) . '</span>') . '</div></div>';
            }
            $html .= '</div>';
        }
        return $html;
    }
}
