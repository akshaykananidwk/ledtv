<?php
declare(strict_types=1);

/**
 * Factory / office KPI dashboard (#8): big tiles from admin/kpi.php (core/Kpi.php) — counters and
 * percentages with progress bars against the target, text tiles, "Days without accident: 128"
 * tiles counted from a date — coloured green / amber / red by thresholds. Current shift from
 * configurable shift times, clock, optional scrolling safety messages. Live refresh every 10 s
 * (values can be pushed by machines: POST /api/kpi/push).
 */
final class KpiDashboardApp extends DisplayApp
{
    public const PER_PAGE = 12;

    public function key(): string
    {
        return 'kpi_dashboard';
    }

    public function label(): string
    {
        return __('KPI dashboard');
    }

    public function description(): string
    {
        return __('Factory or office dashboard: production counters with targets, days without accident, current shift and scrolling safety messages.');
    }

    public function icon(): string
    {
        return 'bi-speedometer2';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('kpi.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'show_shift' => true,
            'shifts' => __('Shift A') . " | 06:00 | 14:00\n" . __('Shift B') . " | 14:00 | 22:00\n" . __('Shift C') . ' | 22:00 | 06:00',
            'messages' => '',
            'ticker_speed' => 'normal',
            'page_sec' => 12,
            'h24' => true,
            'show_clock' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $shifts = self::text($in, 'shifts', 1000);
        $lines = array_filter(array_map('trim', preg_split('/\R/u', $shifts) ?: []), static fn (string $l): bool => $l !== '');
        if (count(Kpi::parseShifts($shifts)) !== count($lines)) {
            $errors[] = __('Write each shift as: Name | 06:00 | 14:00');
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'show_shift' => self::bool($in, 'show_shift'),
            'shifts' => $shifts,
            'messages' => self::text($in, 'messages', 2000),
            'ticker_speed' => self::choice($in, 'ticker_speed', ['slow', 'normal', 'fast'], 'normal'),
            'page_sec' => self::int($in, 'page_sec', 3, 120, 12),
            'h24' => self::bool($in, 'h24'),
            'show_clock' => self::bool($in, 'show_clock'),
        ], $errors];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Production dashboard')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::textarea('shifts', __('Shifts (one per line: name | start | end)'), $config['shifts'], 3, __('A shift may run past midnight, e.g. Night | 22:00 | 06:00.'), 'col-md-6')
            . self::textarea('messages', __('Safety messages (one per line, scroll at the bottom)'), $config['messages'], 3, __('e.g. Wear your helmet and safety shoes.'), 'col-md-6')
            . self::select('ticker_speed', __('Scrolling speed'), ['slow' => __('Slow'), 'normal' => __('Normal'), 'fast' => __('Fast')], $config['ticker_speed'], '', 'col-md-4')
            . self::input('page_sec', __('Change page every (seconds)'), $config['page_sec'], 'number', ['min' => 3, 'max' => 120], __('Only when there are more than 12 tiles.'), 'col-md-4')
            . self::checkbox('show_shift', __('Show current shift'), (bool) $config['show_shift'], 'col-md-4')
            . self::checkbox('h24', __('24-hour time'), (bool) $config['h24'], 'col-md-4')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-4');
    }

    /** Sample tiles for the preview of a hotel without tiles. */
    private static function samples(int $now): array
    {
        $b = Kpi::DEFAULTS;
        return [
            ['id' => -1, 'label' => __('Days without accident'), 'type' => 'days_since', 'since_date' => date('Y-m-d', $now - 128 * 86400), 'good_at' => 30, 'bad_at' => 7] + $b,
            ['id' => -2, 'label' => __('Production today'), 'type' => 'counter', 'value_num' => 820, 'target' => 1000, 'unit' => __('pcs'), 'good_at' => 900, 'bad_at' => 600] + $b,
            ['id' => -3, 'label' => __('Quality OK'), 'type' => 'percent', 'value_num' => 97.5, 'target' => 98, 'good_at' => 98, 'bad_at' => 95] + $b,
            ['id' => -4, 'label' => __('Line 2'), 'type' => 'text', 'value_text' => __('Running')] + $b,
        ];
    }

    /** Tile views for the page. @return array{0: array, 1: bool} [views, has tiles] */
    public static function tiles(array $ctx): array
    {
        $now = (int) $ctx['now'];
        $rows = Kpi::all(true);
        if (!$rows && !empty($ctx['preview'])) {
            $rows = self::samples($now);
        }
        return [array_map(static fn (array $t): array => Kpi::view($t, $now), $rows), $rows !== []];
    }

    /** Columns for $n tiles on one page. */
    public static function columns(int $n): int
    {
        return match (true) {
            $n <= 3 => max(1, $n),
            $n === 4 => 2,
            $n <= 6 => 3,
            default => 4,
        };
    }

    public static function tile(array $v): string
    {
        $h = '<div class="kp-tile hc-card kp-t-' . e($v['type']) . ($v['level'] !== '' ? ' kp-' . e($v['level']) : '') . '">'
            . '<div class="kp-label">' . e($v['label']) . '</div>'
            . '<div class="kp-value"><b>' . e($v['value'] !== '' ? $v['value'] : '—') . '</b>' . ($v['unit'] !== '' && $v['type'] !== 'text' ? '<span class="kp-unit">' . e($v['unit']) . '</span>' : '') . '</div>';
        if ($v['progress'] !== null) {
            $h .= '<div class="kp-bar"><i style="width:' . e(BusinessApps::plain((float) $v['progress'])) . '%"></i></div>';
        }
        if ($v['target_text'] !== '') {
            $h .= '<div class="kp-target">' . e(__('Target: :t', ['t' => $v['target_text']])) . ($v['progress'] !== null ? ' · ' . e(BusinessApps::plain((float) $v['progress'])) . '%' : '') . '</div>';
        }
        return $h . '</div>';
    }

    /** Body HTML (tiles, paged) — also sent as live data. */
    public static function inner(array $ctx): string
    {
        [$views] = self::tiles($ctx);
        if (!$views) {
            return '<div class="hc-empty">' . e(__('Add tiles on the KPI dashboard page.')) . '</div>';
        }
        $pages = array_chunk($views, self::PER_PAGE);
        $h = '<div id="kpPages" class="hc-slides kp-pages">';
        foreach ($pages as $page) {
            $cols = self::columns(count($page));
            $h .= '<div class="hc-slide kp-page kp-cols-' . $cols . ' kp-rows-' . (int) ceil(count($page) / $cols) . '">';
            foreach ($page as $v) {
                $h .= self::tile($v);
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    }

    /** "Shift A · 06:00–14:00" or ''. */
    public static function shiftText(array $config, int $now): string
    {
        if (!$config['show_shift']) {
            return '';
        }
        $s = Kpi::currentShift(Kpi::parseShifts((string) $config['shifts']), $now);
        if (!$s) {
            return '';
        }
        $h24 = (bool) $config['h24'];
        return $s['name'] . ' · ' . BusinessApps::timeLabel($s['start'], $h24) . '–' . BusinessApps::timeLabel($s['end'], $h24);
    }

    public function data(array $config, array $ctx): ?array
    {
        return ['html' => self::inner($ctx), 'shift' => self::shiftText($config, (int) $ctx['now']), 'page_sec' => (int) $config['page_sec']];
    }

    public function refreshSec(array $config): int
    {
        return 10;
    }

    public function render(array $config, array $ctx): string
    {
        $heading = $config['heading'] !== '' ? $config['heading'] : __('KPI dashboard');
        $shift = self::shiftText($config, (int) $ctx['now']);
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_shift']) {
            $h .= '<div class="kp-shift" id="kpShift"' . ($shift === '' ? ' style="display:none"' : '') . '>' . e($shift) . '</div>';
        }
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="' . ($config['h24'] ? '24' : '12') . '"></div><div class="hc-date" data-hc-date></div></div>';
        }
        $h .= '</div><div class="hc-body" id="kpBody">' . self::inner($ctx) . '</div>';
        $msgs = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $config['messages']) ?: []), static fn (string $m): bool => $m !== ''));
        if ($msgs) {
            $text = implode('   •   ', $msgs);
            $per = ['slow' => 0.32, 'normal' => 0.22, 'fast' => 0.14][$config['ticker_speed']] ?? 0.22;
            $sec = max(12, (int) round(mb_strlen($text) * $per + 10));
            $h .= '<div class="kp-ticker"><div class="kp-ticker-in" style="-webkit-animation-duration:' . $sec . 's;animation-duration:' . $sec . 's">' . e($text) . '</div></div>';
        }
        return $h;
    }
}
