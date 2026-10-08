<?php
declare(strict_types=1);

/**
 * Google Sheet as a live table (#14): a "Publish to web → CSV" Google Sheets link (only
 * docs.google.com, see core/SheetFeed.php) shown as a big table — price lists, rates, timetables.
 * Header row on / off, chosen columns, font size, automatic paging, refresh every N minutes (server
 * cache, last good copy kept on errors, "last updated" shown), the row matching today highlighted.
 * Cell texts are shown exactly as in the sheet (number formatting untouched) and always escaped.
 */
final class SheetTableApp extends DisplayApp
{
    public const FONTS = ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'xl' => 'Extra large'];

    public function key(): string
    {
        return 'sheet_table';
    }

    public function label(): string
    {
        return __('Google Sheet table');
    }

    public function description(): string
    {
        return __('Show a published Google Sheet as a big table: price lists, rates, timetables. Edit the sheet and the TVs follow.');
    }

    public function icon(): string
    {
        return 'bi-table';
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaults(): array
    {
        return [
            'url' => '',
            'heading' => '',
            'header' => true,
            'columns' => '',
            'font' => 'm',
            'rows_per_page' => 10,
            'page_sec' => 10,
            'refresh_min' => 5,
            'highlight_today' => true,
            'show_updated' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $raw = self::str($in, 'url', 2000);
        $url = '';
        if ($raw === '') {
            $errors[] = __('Paste the Google Sheets link (File → Share → Publish to web → CSV).');
        } else {
            $url = SheetFeed::normalizeUrl($raw) ?? '';
            if ($url === '') {
                $errors[] = __('Only Google Sheets links (https://docs.google.com/spreadsheets/…) are allowed.');
            }
        }
        $cols = self::str($in, 'columns', 300);
        $cols = implode(', ', array_slice(array_values(array_filter(array_map('trim', explode(',', $cols)), static fn ($c) => $c !== '')), 0, SheetFeed::MAX_COLS));
        return [[
            'url' => $url,
            'heading' => self::str($in, 'heading', 120),
            'header' => self::bool($in, 'header'),
            'columns' => mb_substr($cols, 0, 300),
            'font' => self::choice($in, 'font', self::FONTS, 'm'),
            'rows_per_page' => self::int($in, 'rows_per_page', 3, 40, 10),
            'page_sec' => self::int($in, 'page_sec', 3, 120, 10),
            'refresh_min' => self::int($in, 'refresh_min', 1, 1440, 5),
            'highlight_today' => self::bool($in, 'highlight_today'),
            'show_updated' => self::bool($in, 'show_updated'),
        ], $errors];
    }

    public function form(array $config): string
    {
        return self::input('url', __('Google Sheets link'), $config['url'], 'url', ['maxlength' => 2000, 'required' => true, 'placeholder' => 'https://docs.google.com/spreadsheets/d/e/…/pub?output=csv'],
                __('In Google Sheets: File → Share → Publish to web → choose the sheet → "Comma-separated values (.csv)" → Publish, then copy the link. Only docs.google.com links are accepted.'), 'col-12')
            . self::input('heading', __('Heading (optional)'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('columns', __('Columns to show (optional)'), $config['columns'], 'text', ['maxlength' => 300, 'placeholder' => 'A, C, D'],
                __('Letters (A, B …), numbers (1, 2 …) or header names, separated by commas. Empty = all columns.'))
            . self::select('font', __('Text size'), array_map('__', self::FONTS), $config['font'], '', 'col-md-3')
            . self::input('rows_per_page', __('Rows per page'), $config['rows_per_page'], 'number', ['min' => 3, 'max' => 40], '', 'col-md-3')
            . self::input('page_sec', __('Next page after (seconds)'), $config['page_sec'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-3')
            . self::input('refresh_min', __('Reload the sheet every (minutes)'), $config['refresh_min'], 'number', ['min' => 1, 'max' => 1440], '', 'col-md-3')
            . self::checkbox('header', __('First row is the header'), (bool) $config['header'], 'col-md-4')
            . self::checkbox('highlight_today', __('Highlight the row with today\'s date or day'), (bool) $config['highlight_today'], 'col-md-4')
            . self::checkbox('show_updated', __('Show "last updated"'), (bool) $config['show_updated'], 'col-md-4');
    }

    /** Column indexes for the "columns" setting (letters, 1-based numbers or header names); all when empty. */
    public static function columnIndexes(string $spec, ?array $header, int $width): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $spec)), static fn ($c) => $c !== '') as $tok) {
            $idx = null;
            if (ctype_digit($tok)) {
                $idx = (int) $tok - 1;
            } elseif (preg_match('/^[A-Za-z]$/', $tok)) {
                $idx = ord(strtoupper($tok)) - 65;
            }
            if (($idx === null || $idx >= $width) && $header) {
                foreach ($header as $i => $h) {
                    if (mb_strtolower(trim((string) $h)) === mb_strtolower($tok)) {
                        $idx = $i;
                        break;
                    }
                }
            }
            if ($idx !== null && $idx >= 0 && $idx < $width && !in_array($idx, $out, true)) {
                $out[] = $idx;
            }
        }
        return $out ?: range(0, max(0, $width - 1));
    }

    /** Lower-case texts that mean "today" in a cell (dates in common formats, weekday names in en / gu / hi). */
    public static function todayTokens(int $now): array
    {
        $t = [];
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y', 'd/m/y', 'j/n/y', 'd/m', 'j/n', 'j M Y', 'j F Y', 'd M Y', 'd F Y', 'M j, Y', 'F j, Y', 'j M', 'j F', 'd M', 'd F', 'M j', 'F j', 'l', 'D'] as $f) {
            $t[] = mb_strtolower(date($f, $now));
        }
        foreach (['gu', 'hi'] as $l) {
            $t[] = mb_strtolower(I18n::translate(date('l', $now), $l));
        }
        return array_values(array_unique($t));
    }

    /** Table model for the TV (header, rows, highlight, numeric columns, update / error texts). */
    private function model(array $config, array $ctx): array
    {
        $out = ['header' => null, 'rows' => [], 'today' => [], 'numeric' => [], 'updated' => '', 'error' => '', 'message' => ''];
        if ($config['url'] === '') {
            if (!empty($ctx['preview'])) {
                $rows = [[__('Item'), __('Price')], ['Masala tea', '₹ 30'], ['Coffee', '₹ 45'], [date('d/m/Y', (int) $ctx['now']), __('Today')]];
                $entry = ['rows' => $rows, 'ok_at' => (int) $ctx['now'], 'error' => null];
            } else {
                $out['message'] = __('Add a Google Sheets link in the app settings.');
                return $out;
            }
        } else {
            $entry = SheetFeed::get((string) $config['url'], (int) $config['refresh_min']);
        }
        $rows = (array) $entry['rows'];
        $width = $rows ? count($rows[0]) : 0;
        $header = $config['header'] && $rows ? array_shift($rows) : null;
        $cols = self::columnIndexes((string) $config['columns'], $header, $width);
        $pick = static fn (array $r): array => array_map(static fn ($i) => (string) ($r[$i] ?? ''), $cols);
        $out['header'] = $header !== null ? $pick($header) : null;
        $out['rows'] = array_map($pick, $rows);
        if ($config['highlight_today']) {
            $tokens = array_flip(self::todayTokens((int) $ctx['now']));
            foreach ($rows as $k => $r) {
                foreach ($r as $c) {
                    if ($c !== '' && isset($tokens[mb_strtolower(trim($c))])) {
                        $out['today'][] = $k;
                        break;
                    }
                }
            }
        }
        foreach (array_keys($cols) as $j) {
            $num = 0;
            $all = 0;
            foreach ($out['rows'] as $r) {
                if ($r[$j] === '') {
                    continue;
                }
                $all++;
                if (preg_match('/^[\s₹$€£%+\-.,()0-9\/]*\d[\s₹$€£%+\-.,()0-9\/]*(?:\s*(?:rs\.?|inr|\/-))?$/iu', $r[$j])) {
                    $num++;
                }
            }
            $out['numeric'][] = $all > 0 && $num === $all;
        }
        if ($entry['ok_at'] && $config['show_updated']) {
            $ts = (int) $entry['ok_at'];
            $time = date('g:i', $ts) . ' ' . (date('H', $ts) < 12 ? __('AM') : __('PM'));
            $out['updated'] = date('Y-m-d', $ts) === date('Y-m-d', (int) $ctx['now'])
                ? __('Updated :t', ['t' => $time])
                : __('Updated :t', ['t' => date('j', $ts) . ' ' . __(date('F', $ts)) . ', ' . $time]);
        }
        $out['error'] = SheetFeed::errorText($entry['error'] ?? null);
        if (!$entry['rows']) {
            $out['message'] = $out['error'] !== '' ? $out['error'] : __('Loading the sheet…');
        }
        return $out;
    }

    public function data(array $config, array $ctx): ?array
    {
        return $this->model($config, $ctx) + [
            'per_page' => (int) $config['rows_per_page'],
            'page_sec' => (int) $config['page_sec'],
            'page_label' => __('Page'),
        ];
    }

    public function refreshSec(array $config): int
    {
        // The TV asks every minute; the server fetches the sheet only every refresh_min minutes.
        return 60;
    }

    /** First page as HTML (same markup as assets/display/apps/sheet_table.js → table()). */
    public static function table(array $m, int $perPage): string
    {
        $num = $m['numeric'];
        $h = '<table class="st-table">';
        if ($m['header'] !== null) {
            $h .= '<thead><tr>';
            foreach ($m['header'] as $j => $c) {
                $h .= '<th' . (!empty($num[$j]) ? ' class="st-num"' : '') . '>' . e($c) . '</th>';
            }
            $h .= '</tr></thead>';
        }
        $h .= '<tbody>';
        foreach (array_slice($m['rows'], 0, $perPage, true) as $k => $r) {
            $h .= '<tr' . (in_array($k, $m['today'], true) ? ' class="st-today"' : '') . '>';
            foreach ($r as $j => $c) {
                $h .= '<td' . (!empty($num[$j]) ? ' class="st-num"' : '') . '>' . nl2br(e($c), false) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    public function render(array $config, array $ctx): string
    {
        $m = $this->model($config, $ctx);
        $h = '<div class="st-wrap st-font-' . e($config['font']) . '">';
        if ($config['heading'] !== '') {
            $h .= '<div class="hc-header"><div class="hc-title"><h1>' . e($config['heading']) . '</h1></div><div class="hc-clock" data-hc-clock="12"></div></div>';
        }
        $h .= '<div class="hc-body st-body"><div id="stTable">' . ($m['rows'] ? self::table($m, (int) $config['rows_per_page']) : '') . '</div>'
            . '<div id="stMessage" class="hc-empty"' . ($m['message'] === '' ? ' style="display:none"' : '') . '>' . e($m['message']) . '</div></div>'
            . '<div class="hc-footer st-footer"><span id="stUpdated">' . e($m['updated']) . '</span>'
            . '<span id="stError" class="st-error"' . ($m['error'] === '' || !$m['rows'] ? ' style="display:none"' : '') . '>' . e($m['error']) . '</span>'
            . '<span id="stPage"></span></div>';
        return $h . '</div>';
    }
}
