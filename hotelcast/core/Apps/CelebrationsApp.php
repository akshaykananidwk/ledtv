<?php
declare(strict_types=1);

/**
 * Birthday / anniversary wall (#29): today's celebrations as a hero (photo or initials, greeting,
 * confetti), rotating when several, then the upcoming days of this week. Only people with consent are
 * ever shown; the year / age is hidden unless the screen enables it. Data from admin/celebrations.php
 * (core/Celebrations.php).
 */
final class CelebrationsApp extends WidgetApp
{
    public function key(): string
    {
        return 'celebrations';
    }

    public function label(): string
    {
        return __('Birthday & anniversary wall');
    }

    public function description(): string
    {
        return __('Today\'s birthdays and anniversaries of staff, members or students with photo and confetti, then this week\'s upcoming ones.');
    }

    public function icon(): string
    {
        return 'bi-balloon-heart';
    }

    public function adminPage(): ?string
    {
        return admin_url('celebrations.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Celebrations'),
            'subtitle' => '',
            'types' => Celebrations::TYPES,
            'group' => '',
            'days' => 7,
            'show_years' => false,
            'confetti' => true,
            'rotate_sec' => 8,
        ];
    }

    private static function typeOptions(): array
    {
        $o = [];
        foreach (Celebrations::TYPES as $t) {
            $o[$t] = Celebrations::typeLabel($t);
        }
        return $o;
    }

    public function validate(array $in): array
    {
        $errors = [];
        $types = self::multi($in, 'types', Celebrations::TYPES);
        if (!$types) {
            $errors[] = __('Choose at least one kind of celebration.');
            $types = Celebrations::TYPES;
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'types' => $types,
            'group' => self::str($in, 'group', 80),
            'days' => self::int($in, 'days', 1, 31, 7),
            'show_years' => self::bool($in, 'show_years'),
            'confetti' => self::bool($in, 'confetti'),
            'rotate_sec' => self::int($in, 'rotate_sec', 3, 60, 8),
        ], $errors];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::checkboxes('types', __('Show'), self::typeOptions(), (array) $config['types'])
            . self::input('group', __('Only this group (optional)'), $config['group'], 'text', ['maxlength' => 80], __('e.g. a department or class; empty = everyone'))
            . self::input('days', __('Upcoming days shown'), $config['days'], 'number', ['min' => 1, 'max' => 31], '', 'col-6 col-md-3')
            . self::input('rotate_sec', __('Change every (seconds)'), $config['rotate_sec'], 'number', ['min' => 3, 'max' => 60], '', 'col-6 col-md-3')
            . self::checkbox('show_years', __('Show age / number of years (when the year is known)'), (bool) $config['show_years'])
            . self::checkbox('confetti', __('Confetti'), (bool) $config['confetti']);
    }

    public function refreshSec(array $config): int
    {
        return 600;
    }

    private static function greeting(string $type): string
    {
        return match ($type) {
            'anniversary' => __('Happy Anniversary!'),
            'work_anniversary' => __('Happy Work Anniversary!'),
            default => __('Happy Birthday!'),
        };
    }

    private static function yearsText(array $c, int $n): string
    {
        return match ((string) $c['type']) {
            'birthday' => __('Turns :n today', ['n' => $n]),
            default => __(':n years', ['n' => $n]),
        };
    }

    private static function photo(array $c, string $cls, bool $full = false): string
    {
        $url = Celebrations::photoUrl($c, $full);
        return $url ? '<div class="' . e($cls) . '" style="background-image:' . self::cssUrl($url) . '"></div>'
            : '<div class="' . e($cls) . ' ce-initials"><span>' . e(self::initials((string) $c['name'])) . '</span></div>';
    }

    protected function body(array $config, array $ctx): string
    {
        $today = self::today($ctx);
        $b = Celebrations::board($today, (int) $config['days'], (array) $config['types'], (string) $config['group']);
        if (!$b['today'] && !$b['upcoming'] && $ctx['preview']) {
            $b['today'][] = Celebrations::DEFAULTS + ['name' => __('Sample: Asha Patel'), 'group_label' => __('Front office'), 'on' => $today, 'in' => 0];
        }
        if (!$b['today'] && !$b['upcoming']) {
            return self::empty(__('No birthdays or anniversaries this week.'));
        }
        $html = '';
        if ($b['today']) {
            $html .= '<div class="ce-hero hc-card" data-rotate="' . (int) $config['rotate_sec'] . '">' . ($config['confetti'] ? '<div class="ce-confetti" aria-hidden="true">' . str_repeat('<i></i>', 18) . '</div>' : '');
            foreach ($b['today'] as $c) {
                $years = $config['show_years'] ? Celebrations::years($c, $today) : null;
                $html .= '<div class="hc-slide ce-slide">' . self::photo($c, 'ce-photo', true)
                    . '<div class="ce-text"><div class="ce-greet">' . e(self::greeting((string) $c['type'])) . '</div>'
                    . '<div class="ce-name">' . e((string) $c['name']) . '</div>'
                    . ((string) $c['group_label'] !== '' ? '<div class="ce-group hc-muted">' . e((string) $c['group_label']) . '</div>' : '')
                    . ($years !== null && $years > 0 ? '<div class="ce-years">' . e(self::yearsText($c, $years)) . '</div>' : '')
                    . '</div></div>';
            }
            $html .= '</div>';
        }
        if ($b['upcoming']) {
            $html .= '<div class="ce-up"><div class="ce-up-title">' . e(__('Coming up')) . '</div><div class="ce-up-list">';
            foreach (array_slice($b['upcoming'], 0, $b['today'] ? 6 : 12) as $c) {
                $icon = match ((string) $c['type']) { 'anniversary' => '💍', 'work_anniversary' => '🏅', default => '🎂' };
                $html .= '<div class="ce-item hc-card">' . self::photo($c, 'ce-thumb')
                    . '<div class="ce-info"><div class="ce-iname">' . e((string) $c['name']) . '</div><div class="hc-muted">' . $icon . ' ' . e(Celebrations::typeLabel((string) $c['type']))
                    . ((string) $c['group_label'] !== '' ? ' · ' . e((string) $c['group_label']) : '') . '</div></div>'
                    . '<div class="ce-when"><b>' . e(self::dateLabel((string) $c['on'], false)) . '</b><span>' . e((int) $c['in'] === 1 ? __('Tomorrow') : __(date('l', (int) strtotime($c['on'] . ' 12:00')))) . '</span></div></div>';
            }
            $html .= '</div></div>';
        }
        return $html;
    }
}
