<?php
declare(strict_types=1);

/**
 * Departures / arrivals board (#7): classic airport-style split-flap look for bus stands, railway
 * stations, airports, ferries. Entries from admin/departures.php (core/Departures.php): time, number /
 * route, destination (English + Gujarati + Hindi), platform / gate, status with delay. Auto-paging,
 * blinking "Boarding", red "Cancelled", past entries hidden N minutes after their time, clock.
 * Live refresh every 15 s. (Live train / flight APIs are a separate widget.)
 */
final class DeparturesApp extends DisplayApp
{
    public const PLATFORM_LABELS = ['platform' => 'Platform', 'gate' => 'Gate', 'stand' => 'Stand', 'bay' => 'Bay', 'counter' => 'Counter'];

    public function key(): string
    {
        return 'departures';
    }

    public function label(): string
    {
        return __('Departures board');
    }

    public function description(): string
    {
        return __('Bus, train or flight departures and arrivals on an airport-style board with live status: delayed, boarding, cancelled.');
    }

    public function icon(): string
    {
        return 'bi-signpost-split';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('departures.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'mode' => 'departure',
            'platform_label' => 'platform',
            'rows_per_page' => 8,
            'page_sec' => 10,
            'hide_after_min' => 10,
            'lookahead_hours' => 12,
            'bilingual' => true,
            'h24' => true,
            'show_clock' => true,
            'footer' => '',
        ];
    }

    public function validate(array $in): array
    {
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'mode' => self::choice($in, 'mode', ['departure', 'arrival', 'both'], 'departure'),
            'platform_label' => self::choice($in, 'platform_label', self::PLATFORM_LABELS, 'platform'),
            'rows_per_page' => self::int($in, 'rows_per_page', 4, 14, 8),
            'page_sec' => self::int($in, 'page_sec', 3, 120, 10),
            'hide_after_min' => self::int($in, 'hide_after_min', 0, 240, 10),
            'lookahead_hours' => self::int($in, 'lookahead_hours', 1, 24, 12),
            'bilingual' => self::bool($in, 'bilingual'),
            'h24' => self::bool($in, 'h24'),
            'show_clock' => self::bool($in, 'show_clock'),
            'footer' => self::str($in, 'footer', 190),
        ], []];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Departures')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::select('mode', __('Show'), ['departure' => __('Departures'), 'arrival' => __('Arrivals'), 'both' => __('Departures and arrivals')], $config['mode'], '', 'col-md-4')
            . self::select('platform_label', __('Column name'), array_map('__', self::PLATFORM_LABELS), $config['platform_label'], '', 'col-md-4')
            . self::input('rows_per_page', __('Rows per page'), $config['rows_per_page'], 'number', ['min' => 4, 'max' => 14], '', 'col-md-4')
            . self::input('page_sec', __('Change page every (seconds)'), $config['page_sec'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::input('hide_after_min', __('Hide past entries after (minutes)'), $config['hide_after_min'], 'number', ['min' => 0, 'max' => 240], '', 'col-md-4')
            . self::input('lookahead_hours', __('Show the next (hours)'), $config['lookahead_hours'], 'number', ['min' => 1, 'max' => 24], '', 'col-md-4')
            . self::input('footer', __('Footer text (optional)'), $config['footer'], 'text', ['maxlength' => 190, 'placeholder' => __('e.g. Enquiry: 1800 123 456')], '', 'col-12')
            . self::checkbox('bilingual', __('Also show the English name below Gujarati / Hindi'), (bool) $config['bilingual'], 'col-md-6')
            . self::checkbox('h24', __('24-hour time'), (bool) $config['h24'], 'col-md-3')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-3');
    }

    /** Sample rows for the preview of a hotel without entries (around now, daily). */
    private static function samples(int $now): array
    {
        $m = (int) date('G', $now) * 60 + (int) date('i', $now);
        $t = static fn (int $plus): string => sprintf('%02d:%02d:00', intdiv(($m + $plus) % 1440, 60), ($m + $plus) % 60);
        $today = date('Y-m-d', $now);
        $base = ['service_date' => null, 'status_date' => $today, 'is_active' => 1, 'kind' => 'departure', 'remark' => '', 'delay_min' => 0];
        return [
            ['id' => -1, 'sched_time' => $t(-3), 'number' => 'GJ 101', 'destination' => 'Ahmedabad', 'destination_gu' => 'અમદાવાદ', 'destination_hi' => 'अहमदाबाद', 'platform' => '2', 'status' => 'departed'] + $base,
            ['id' => -2, 'sched_time' => $t(5), 'number' => 'GJ 205', 'destination' => 'Surat', 'destination_gu' => 'સુરત', 'destination_hi' => 'सूरत', 'platform' => '4', 'status' => 'boarding'] + $base,
            ['id' => -3, 'sched_time' => $t(20), 'number' => 'GJ 330', 'destination' => 'Rajkot', 'destination_gu' => 'રાજકોટ', 'destination_hi' => 'राजकोट', 'platform' => '1', 'status' => 'delayed', 'delay_min' => 15] + $base,
            ['id' => -4, 'sched_time' => $t(35), 'number' => 'GJ 412', 'destination' => 'Vadodara', 'destination_gu' => 'વડોદરા', 'destination_hi' => 'वडोदरा', 'platform' => '3', 'status' => 'on_time'] + $base,
            ['id' => -5, 'sched_time' => $t(50), 'number' => 'GJ 518', 'destination' => 'Bhavnagar', 'destination_gu' => 'ભાવનગર', 'destination_hi' => 'भावनगर', 'platform' => '2', 'status' => 'cancelled'] + $base,
        ];
    }

    /** Board entries for the page (data + render). */
    public static function entries(array $config, array $ctx): array
    {
        $now = (int) $ctx['now'];
        $rows = Departures::candidates($now);
        $sample = false;
        if (!$rows && !empty($ctx['preview'])) {
            $rows = self::samples($now);
            $sample = true;
        }
        $board = Departures::board($rows, $now, [
            'kind' => $config['mode'], 'hide_after_min' => $config['hide_after_min'], 'lookahead_hours' => $config['lookahead_hours'], 'max' => 120,
        ]);
        return [$board, $rows !== [] || $sample];
    }

    private static function flap(string $text, string $cls = ''): string
    {
        return '<span class="df-flap' . ($cls !== '' ? ' ' . $cls : '') . '">' . e($text !== '' ? $text : '—') . '</span>';
    }

    /** Body HTML of the board below the header (also sent as live data). */
    public static function inner(array $config, array $ctx): string
    {
        [$board, $hasRows] = self::entries($config, $ctx);
        $lang = (string) $ctx['lang'];
        $h24 = (bool) $config['h24'];
        $mode = $config['mode'];
        $destHead = $mode === 'arrival' ? __('Origin') : __('Destination');
        $h = '<div class="df-board"><div class="df-head"><div class="df-c df-c-time">' . e(__('Time')) . '</div><div class="df-c df-c-no">' . e(__('No.')) . '</div>'
            . '<div class="df-c df-c-dest">' . e($destHead) . '</div><div class="df-c df-c-plat">' . e(__(self::PLATFORM_LABELS[$config['platform_label']] ?? 'Platform')) . '</div>'
            . '<div class="df-c df-c-status">' . e(__('Status')) . '</div></div>';
        if (!$board) {
            $msg = $hasRows ? ($mode === 'arrival' ? __('No arrivals right now.') : __('No departures right now.')) : __('Add entries on the Departures board page.');
            return $h . '<div class="hc-empty">' . e($msg) . '</div></div>';
        }
        $per = (int) $config['rows_per_page'];
        $rowStyle = ' style="height:' . rtrim(rtrim(number_format(100 / $per, 3, '.', ''), '0'), '.') . '%"';
        $h .= '<div id="dfPages" class="df-pages hc-slides' . ($per > 9 ? ' df-dense' : '') . '">';
        foreach (array_chunk($board, $per) as $chunk) {
            $h .= '<div class="hc-slide df-page">';
            foreach ($chunk as $r) {
                $st = (string) $r['status_now'];
                $time = BusinessApps::timeLabel((string) $r['sched_time'], $h24);
                $dest = Departures::destination($r, $lang);
                $statusText = Departures::statusLabel($st);
                if ($st === 'delayed' && $r['delay_now'] > 0) {
                    $statusText = __('Delayed :n min', ['n' => $r['delay_now']]);
                }
                $sub = '';
                if ($r['delay_now'] > 0 && !in_array($st, ['cancelled', 'on_time'], true)) {
                    $sub = __('Exp. :t', ['t' => BusinessApps::timeLabel(date('H:i:s', (int) $r['eff_ts']), $h24)]);
                }
                $kindTag = $mode === 'both' ? '<span class="df-kind">' . e($r['kind'] === 'arrival' ? __('ARR') : __('DEP')) . '</span>' : '';
                $h .= '<div class="df-row df-st-' . e($st) . '" data-k="' . e($r['id'] . '|' . $r['date']) . '" data-st="' . e($st . '|' . $r['delay_now']) . '"' . $rowStyle . '>'
                    . '<div class="df-c df-c-time">' . self::flap($time, 'df-mono') . ($r['tomorrow'] ? '<small>' . e(__('Tomorrow')) . '</small>' : '') . '</div>'
                    . '<div class="df-c df-c-no">' . self::flap((string) $r['number'], 'df-mono') . '</div>'
                    . '<div class="df-c df-c-dest">' . $kindTag . self::flap($dest)
                    . ($config['bilingual'] && $dest !== (string) $r['destination'] ? '<small>' . e($r['destination']) . '</small>' : '')
                    . ($r['remark_now'] !== '' ? '<small class="df-remark">' . e($r['remark_now']) . '</small>' : '') . '</div>'
                    . '<div class="df-c df-c-plat">' . self::flap((string) $r['platform'], 'df-mono') . '</div>'
                    . '<div class="df-c df-c-status"><span class="df-status">' . e($statusText) . '</span>' . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</div>'
                    . '</div>';
            }
            $h .= '</div>';
        }
        return $h . '</div></div>';
    }

    public function data(array $config, array $ctx): ?array
    {
        return ['html' => self::inner($config, $ctx), 'page_sec' => (int) $config['page_sec']];
    }

    public function refreshSec(array $config): int
    {
        return 15;
    }

    public function render(array $config, array $ctx): string
    {
        $heading = $config['heading'] !== '' ? $config['heading']
            : ($config['mode'] === 'arrival' ? __('Arrivals') : ($config['mode'] === 'both' ? __('Departures and arrivals') : __('Departures')));
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock df-clock" data-hc-clock="' . ($config['h24'] ? '24' : '12') . '" data-hc-seconds></div><div class="hc-date" data-hc-date></div></div>';
        }
        $h .= '</div><div class="hc-body" id="dfBody">' . self::inner($config, $ctx) . '</div>';
        if ($config['footer'] !== '') {
            $h .= '<div class="hc-footer df-footer">' . e($config['footer']) . '</div>';
        }
        return $h;
    }
}
