<?php
declare(strict_types=1);

/**
 * Panchang + Choghadiya (#27), computed offline (core/Panchang.php — no API): Gujarati-first panchang
 * card (Vikram Samvat, Amanta month, paksha, tithi, nakshatra, yoga, sunrise / sunset, labelled
 * "approximate") and the day / night choghadiya table with the current segment highlighted; the page
 * re-highlights itself and refreshes at segment / day boundaries (assets/display/apps/panchang.js).
 * The admin may type the day's tithi text ("2026-10-08 | Aso sud 7") — it takes precedence.
 */
final class PanchangApp extends WidgetApp
{
    public function key(): string
    {
        return 'panchang';
    }

    public function label(): string
    {
        return __('Panchang & Choghadiya');
    }

    public function description(): string
    {
        return __('Today\'s tithi, nakshatra, yoga, Vikram Samvat month, sunrise / sunset and the day and night choghadiya with the current one highlighted.');
    }

    public function icon(): string
    {
        return 'bi-sun';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Today\'s Panchang'),
            'subtitle' => '',
            'lat' => '',
            'lon' => '',
            'show_panchang' => true,
            'show_choghadiya' => true,
            'h24' => false,
            'overrides' => '',
        ];
    }

    /** "2026-10-08 | text" lines → ['Y-m-d' => text]. */
    public static function parseOverrides(string $text, array &$errors = []): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line, 2));
            $d = DateTime::createFromFormat('!Y-m-d', $p[0]);
            if (!$d || $d->format('Y-m-d') !== $p[0] || ($p[1] ?? '') === '') {
                $errors[] = __('Override line :n: write "YYYY-MM-DD | tithi text", e.g. "2026-10-08 | Aso sud 7".', ['n' => $i + 1]);
                continue;
            }
            $out[$p[0]] = mb_substr($p[1], 0, 160);
        }
        return $out;
    }

    public function validate(array $in): array
    {
        $errors = [];
        [$lat, $lon] = self::latLon($in, $errors);
        $over = self::text($in, 'overrides', 4000);
        self::parseOverrides($over, $errors);
        $c = [
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'lat' => $lat,
            'lon' => $lon,
            'show_panchang' => self::bool($in, 'show_panchang'),
            'show_choghadiya' => self::bool($in, 'show_choghadiya'),
            'h24' => self::bool($in, 'h24'),
            'overrides' => $over,
        ];
        if (!$c['show_panchang'] && !$c['show_choghadiya']) {
            $errors[] = __('Show the panchang, the choghadiya or both.');
            $c['show_panchang'] = $c['show_choghadiya'] = true;
        }
        return [$c, $errors];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::latLonFields($config)
            . self::checkbox('show_panchang', __('Show the panchang card'), (bool) $config['show_panchang'])
            . self::checkbox('show_choghadiya', __('Show the choghadiya table'), (bool) $config['show_choghadiya'])
            . self::checkbox('h24', __('24-hour times'), (bool) $config['h24'])
            . self::textarea('overrides', __('Tithi of the day typed by you (optional)'), (string) $config['overrides'], 3,
                __('One per line: YYYY-MM-DD | text, e.g. "2026-10-08 | Aso sud 7 (Navratri)". On that day your text replaces the computed tithi. The computed panchang is approximate — check your temple panchang for exact times.'), 'col-12', 4000);
    }

    public function refreshSec(array $config): int
    {
        return 900;
    }

    /**
     * Lunar month name in the current language. Translation keys are "Lunar month <name>" because
     * Jyeshtha, Shravana and Magha are also nakshatra names (different words in Gujarati / Hindi).
     */
    public static function monthLabel(string $name): string
    {
        $k = 'Lunar month ' . $name;
        $t = __($k);
        return $t === $k ? $name : $t;
    }

    /** "Ashwin Krishna paksha Trayodashi" / "આસો વદ તેરસ" for a panchang day (translated). */
    public static function tithiText(array $p): string
    {
        $m = $p['month'];
        return ($m['adhik'] ? __('Adhik') . ' ' : '') . self::monthLabel((string) $m['name']) . ' ' . __($p['tithi']['paksha'] === 'shukla' ? 'Shukla paksha' : 'Krishna paksha') . ' ' . __($p['tithi']['name']);
    }

    /** Gujarati first: the Gujarati tithi line, plus the line in the screen language when it is not Gujarati. */
    private static function tithiLines(array $p, ?string $over, string $lang): string
    {
        if ($over !== null) {
            return '<div class="pc-tithi">' . e($over) . '</div>';
        }
        $gu = DisplayApps::inLang('gu', static fn (): string => self::tithiText($p));
        return '<div class="pc-tithi" lang="gu">' . e($gu) . '</div>' . ($lang !== 'gu' ? '<div class="pc-tithi-sub">' . e(self::tithiText($p)) . '</div>' : '');
    }

    protected function body(array $config, array $ctx): string
    {
        $loc = self::location($config);
        if ($loc === null) {
            return self::empty(__('Set the hotel location (Settings → Weather) or the coordinates of this screen.'));
        }
        $tz = date_default_timezone_get();
        $now = (int) $ctx['now'];
        $p = Panchang::day($now, $loc[0], $loc[1], $tz);
        $h24 = (bool) $config['h24'];
        $t = static fn (int $ts): string => self::timeLabel($ts, $h24);
        $html = '<div class="pc-wrap' . ($config['show_panchang'] && $config['show_choghadiya'] ? ' pc-both' : '') . '" data-next="' . ((int) $p['next_sunrise'] * 1000) . '">';
        if ($config['show_panchang']) {
            $over = self::parseOverrides((string) $config['overrides'])[$p['date']] ?? null;
            $rows = [
                [__('Tithi'), $over ?? (__($p['tithi']['name']) . ' · ' . __('until :t', ['t' => $t($p['tithi']['end'])])), $over !== null],
                [__('Nakshatra'), __($p['nakshatra']['name']) . ' · ' . __('until :t', ['t' => $t($p['nakshatra']['end'])]), false],
                [__('Yoga'), __($p['yoga']['name']), false],
                [__('Sunrise'), $t($p['sunrise']), false],
                [__('Sunset'), $t($p['sunset']), false],
            ];
            $html .= '<div class="pc-card hc-card"><div class="pc-date">' . e(self::dateLabel($p['date'], true, true)) . '</div>'
                . '<div class="pc-samvat">' . e(__('Vikram Samvat :y', ['y' => $p['month']['samvat']])) . '</div>'
                . self::tithiLines($p, $over, (string) $ctx['lang']) . '<dl class="pc-list">';
            foreach ($rows as [$k, $v, $mine]) {
                $html .= '<div class="pc-row"><dt>' . e($k) . '</dt><dd>' . e($v) . ($mine ? ' <span class="pc-mine">' . e(__('(set by the hotel)')) . '</span>' : '') . '</dd></div>';
            }
            $html .= '</dl><div class="pc-approx">' . e(__('Approximate — computed for :place; times may differ by a few minutes from your panchang.', ['place' => (string) Settings::get('weather_city', '') ?: sprintf('%.2f, %.2f', $loc[0], $loc[1])])) . '</div></div>';
        }
        if ($config['show_choghadiya']) {
            $cols = ['day' => '', 'night' => ''];
            foreach ($p['choghadiya'] as $i => $s) {
                $cols[$s['part']] .= '<div class="pc-seg pc-' . e($s['quality']) . ($i === $p['current'] ? ' is-now' : '') . '" data-s="' . ($s['start'] * 1000) . '" data-e="' . ($s['end'] * 1000) . '">'
                    . '<span class="pc-seg-name">' . e(__($s['name'])) . '</span><span class="pc-seg-time">' . e($t($s['start']) . ' – ' . $t($s['end'])) . '</span></div>';
            }
            $html .= '<div class="pc-chog"><div class="pc-chog-title">' . e(__('Choghadiya')) . '</div><div class="pc-cols">'
                . '<div class="pc-col"><div class="pc-col-head">☀ ' . e(__('Day')) . '</div>' . $cols['day'] . '</div>'
                . '<div class="pc-col"><div class="pc-col-head">☾ ' . e(__('Night')) . '</div>' . $cols['night'] . '</div></div>'
                . '<div class="pc-legend"><span class="pc-good">' . e(__('Good')) . '</span><span class="pc-neutral">' . e(__('Neutral')) . '</span><span class="pc-bad">' . e(__('Avoid')) . '</span></div></div>';
        }
        return $html . '</div>';
    }
}
