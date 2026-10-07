<?php
declare(strict_types=1);

/**
 * Wedding / event welcome (#10): event title ("શુભ વિવાહ"), names, family line, date, venue, schedule
 * (Haldi 10:00, Baraat 18:00 …) with the current / next item highlighted in the hotel time zone,
 * photo slideshow from an album (or one library image), decorative frame per theme, optional welcome
 * message rotating in Gujarati / Hindi / English. Live data every 60 s (schedule highlight, photos).
 */
final class EventWelcomeApp extends DisplayApp
{
    public const FRAMES = ['auto', 'floral', 'mandala', 'lights', 'simple', 'none'];
    /** Frame picked by "auto" per theme preset. */
    private const THEME_FRAMES = [
        'wedding' => 'floral', 'holi' => 'floral', 'diwali' => 'lights', 'christmas' => 'lights',
        'navratri' => 'mandala', 'janmashtami' => 'mandala', 'eid' => 'mandala', 'temple_saffron' => 'mandala',
    ];
    /** An item stays "now" for at most this long when the next one has not started. */
    public const CURRENT_MAX_SEC = 3 * 3600;
    public const MAX_ITEMS = 20;

    public function key(): string
    {
        return 'event_welcome';
    }

    public function label(): string
    {
        return __('Wedding / event welcome');
    }

    public function description(): string
    {
        return __('Welcome screen for a wedding or event: names, date, venue, the programme with the current item highlighted, photos and a festive frame.');
    }

    public function icon(): string
    {
        return 'bi-balloon-heart';
    }

    public function category(): string
    {
        return 'business';
    }

    public function defaults(): array
    {
        return [
            'title' => __('Shubh Vivah'),
            'names' => '',
            'family' => '',
            'event_date' => '',
            'venue' => '',
            'schedule' => '',
            'album_id' => 0,
            'bg_image' => 0,
            'interval' => 8,
            'frame' => 'auto',
            'welcome_on' => true,
            'welcome_en' => 'Welcome',
            'welcome_gu' => 'આપનું હાર્દિક સ્વાગત છે',
            'welcome_hi' => 'आपका हार्दिक स्वागत है',
            'show_clock' => false,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $title = self::str($in, 'title', 120);
        $names = self::str($in, 'names', 160);
        if ($title === '' && $names === '') {
            $errors[] = __('Enter the event title or the names.');
        }
        $date = self::str($in, 'event_date', 10);
        if ($date !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                $errors[] = __('Invalid date: :d', ['d' => $date]);
                $date = '';
            }
        }
        $schedule = self::text($in, 'schedule', 4000);
        $bad = [];
        foreach (ContentApps::lines($schedule, self::MAX_ITEMS + 5, 190) as $line) {
            if (self::parseLine($line, null) === null) {
                $bad[] = $line;
            }
        }
        if ($bad) {
            $errors[] = __('Programme: write each line as "time | name", e.g. "18:00 | Baraat". Check: :l', ['l' => mb_substr(implode(' / ', $bad), 0, 120)]);
        }
        $img = self::int($in, 'bg_image', 0, PHP_INT_MAX, 0);
        if ($img > 0 && self::imageUrl($img) === null) {
            $img = 0;
        }
        return [[
            'title' => $title,
            'names' => $names,
            'family' => self::str($in, 'family', 190),
            'event_date' => $date,
            'venue' => self::str($in, 'venue', 190),
            'schedule' => implode("\n", ContentApps::lines($schedule, self::MAX_ITEMS, 190)),
            'album_id' => ContentApps::albumId($in, 'album_id', $errors),
            'bg_image' => $img,
            'interval' => self::int($in, 'interval', 3, 120, 8),
            'frame' => self::choice($in, 'frame', self::FRAMES, 'auto'),
            'welcome_on' => self::bool($in, 'welcome_on'),
            'welcome_en' => self::str($in, 'welcome_en', 120),
            'welcome_gu' => self::str($in, 'welcome_gu', 120),
            'welcome_hi' => self::str($in, 'welcome_hi', 120),
            'show_clock' => self::bool($in, 'show_clock'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $albums = [0 => __('None')] + Albums::options();
        $frames = ['auto' => __('Automatic (by theme)'), 'floral' => __('Flowers'), 'mandala' => __('Mandala'), 'lights' => __('Lights / diyas'), 'simple' => __('Simple border'), 'none' => __('No frame')];
        return self::input('title', __('Event title'), $config['title'], 'text', ['maxlength' => 120, 'placeholder' => 'શુભ વિવાહ'])
            . self::input('names', __('Names'), $config['names'], 'text', ['maxlength' => 160, 'placeholder' => __('e.g. Riya & Aarav')])
            . self::input('family', __('Family / host line'), $config['family'], 'text', ['maxlength' => 190, 'placeholder' => __('e.g. Shah and Patel families welcome you')])
            . self::input('event_date', __('Date'), $config['event_date'], 'date', [], '', 'col-md-3')
            . self::input('venue', __('Venue'), $config['venue'], 'text', ['maxlength' => 190, 'placeholder' => __('e.g. Lawn, Hotel Krishna')], '', 'col-md-3')
            . self::textarea('schedule', __('Programme (one per line: time | name | place)'), (string) $config['schedule'], 6,
                __('e.g. "10:00 | Haldi | Garden", "18:00 | Baraat". For a different day write the date first: "2026-12-11 19:30 | Reception". Times in the hotel time zone; the current and next item are highlighted.'), 'col-12', 4000)
            . self::select('album_id', __('Photo album (slideshow)'), $albums, (string) $config['album_id'], __('Upload photos from a phone on the Photo albums page; guests can add photos with a QR code.'), 'col-md-4')
            . self::imagePicker('bg_image', __('Or one image from the library'), (int) $config['bg_image'], '', 'col-md-4')
            . self::input('interval', __('Change photo every (seconds)'), $config['interval'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::select('frame', __('Decorative frame'), $frames, $config['frame'], __('Automatic follows the theme: flowers for Wedding / Holi, lights for Diwali / Christmas, mandala for Navratri and other festivals.'), 'col-md-6')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-3')
            . self::checkbox('welcome_on', __('Rotating welcome message'), (bool) $config['welcome_on'], 'col-md-3')
            . self::input('welcome_gu', __('Welcome (Gujarati)'), $config['welcome_gu'], 'text', ['maxlength' => 120], '', 'col-md-4')
            . self::input('welcome_hi', __('Welcome (Hindi)'), $config['welcome_hi'], 'text', ['maxlength' => 120], '', 'col-md-4')
            . self::input('welcome_en', __('Welcome (English)'), $config['welcome_en'], 'text', ['maxlength' => 120], '', 'col-md-4');
    }

    /**
     * One programme line → ['date' => ?Y-m-d, 'time' => 'H:i'|null, 'label', 'place'] or null when unusable.
     * Accepted: "10:00 | Haldi | Garden", "6:30 pm Sangeet", "2026-12-11 19:30 | Reception", "11/12 08:00 - Pheras",
     * "Haldi" (no time: shown, never highlighted). The year of $defaultDate completes "dd/mm" dates.
     */
    public static function parseLine(string $line, ?string $defaultDate): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $date = null;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})\s+(.*)$/u', $line, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            $line = $m[4];
        } elseif (preg_match('#^(\d{1,2})[/.](\d{1,2})(?:[/.](\d{2,4}))?\s+(.*)$#u', $line, $m) && preg_match('/^\d{1,2}[:.]\d{2}/', $m[4])) {
            $year = $m[3] !== '' ? (int) $m[3] : (int) substr($defaultDate ?? date('Y-m-d'), 0, 4);
            if ($year < 100) {
                $year += 2000;
            }
            if (!checkdate((int) $m[2], (int) $m[1], $year)) {
                return null;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
            $line = $m[4];
        }
        $time = null;
        if (preg_match('/^(\d{1,2})[:.](\d{2})\s*([aApP]\.?[mM]\.?)?\s*(?:[|\-–:]\s*)?(.*)$/u', $line, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $ampm = strtolower(str_replace('.', '', $m[3] ?? ''));
            if ($ampm === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'am' && $h === 12) {
                $h = 0;
            }
            if ($h > 23 || $min > 59 || ($ampm !== '' && (int) $m[1] > 12)) {
                return null;
            }
            $time = sprintf('%02d:%02d', $h, $min);
            $line = $m[4];
        } elseif ($date !== null) {
            return null; // a date needs a time
        }
        $parts = array_map('trim', explode('|', $line));
        $label = (string) preg_replace('/^[\s\-–]+|[\s\-–]+$/u', '', $parts[0]);
        if ($label === '') {
            return null;
        }
        return ['date' => $date, 'time' => $time, 'label' => mb_substr($label, 0, 120), 'place' => mb_substr(trim($parts[1] ?? ''), 0, 120)];
    }

    /**
     * Programme with states at $now (hotel time zone): 'past' | 'current' | 'next' | 'later' | 'none'
     * (no time). Items keep the written order.
     * @return array<int, array{label: string, place: string, time: string, day: string, state: string}>
     */
    public static function schedule(string $text, string $eventDate, int $now): array
    {
        $default = $eventDate !== '' ? $eventDate : date('Y-m-d', $now);
        $items = [];
        foreach (ContentApps::lines($text, self::MAX_ITEMS, 190) as $line) {
            $p = self::parseLine($line, $default);
            if ($p === null) {
                continue;
            }
            $day = $p['date'] ?? $default;
            $p['ts'] = $p['time'] !== null ? (int) strtotime($day . ' ' . $p['time'] . ':00') : null;
            $p['day'] = $day;
            $items[] = $p;
        }
        $days = array_unique(array_column(array_filter($items, static fn ($i) => $i['ts'] !== null), 'day'));
        $timed = array_filter($items, static fn ($i) => $i['ts'] !== null);
        uasort($timed, static fn ($a, $b) => $a['ts'] <=> $b['ts']);
        $current = null;
        $next = null;
        foreach ($timed as $k => $i) {
            if ($i['ts'] <= $now) {
                $current = $k;
            } elseif ($next === null) {
                $next = $k;
            }
        }
        if ($current !== null && $now - $items[$current]['ts'] > self::CURRENT_MAX_SEC) {
            $current = null;
        }
        $out = [];
        foreach ($items as $k => $i) {
            $state = $i['ts'] === null ? 'none' : ($k === $current ? 'current' : ($k === $next ? 'next' : ($i['ts'] < $now ? 'past' : 'later')));
            $out[] = [
                'label' => $i['label'],
                'place' => $i['place'],
                'time' => $i['ts'] !== null ? self::timeLabel((int) $i['ts']) : '',
                'day' => $i['ts'] !== null && count($days) > 1 ? self::dayLabel((int) $i['ts']) : '',
                'state' => $state,
            ];
        }
        return $out;
    }

    private static function timeLabel(int $ts): string
    {
        return date('g:i', $ts) . ' ' . (date('H', $ts) < 12 ? __('AM') : __('PM'));
    }

    private static function dayLabel(int $ts): string
    {
        return date('j', $ts) . ' ' . __(date('F', $ts));
    }

    /** Frame style for the config + theme. */
    public static function frame(array $config, string $theme): string
    {
        $f = (string) $config['frame'];
        if ($f === 'auto') {
            return self::THEME_FRAMES[$theme] ?? 'simple';
        }
        return in_array($f, self::FRAMES, true) ? $f : 'simple';
    }

    private function slides(array $config): array
    {
        $slides = ContentApps::albumSlides((int) $config['album_id'], 300);
        if (!$slides && (int) $config['bg_image'] > 0) {
            $slides = ContentApps::librarySlides([(int) $config['bg_image']]);
        }
        return $slides;
    }

    public function data(array $config, array $ctx): ?array
    {
        return [
            'schedule' => self::schedule((string) $config['schedule'], (string) $config['event_date'], (int) $ctx['now']),
            'photos' => $this->slides($config),
            'labels' => ['current' => __('Now'), 'next' => __('Next')],
        ];
    }

    public function refreshSec(array $config): int
    {
        return 60;
    }

    /** Same markup as assets/display/apps/event_welcome.js → item(). */
    public static function item(array $i, array $labels): string
    {
        $badge = isset($labels[$i['state']]) ? '<span class="ew-badge">' . e($labels[$i['state']]) . '</span>' : '';
        return '<li class="ew-item ew-' . e($i['state']) . '"><span class="ew-time">' . e($i['time']) . ($i['day'] !== '' ? '<small>' . e($i['day']) . '</small>' : '') . '</span>'
            . '<span class="ew-label">' . e($i['label']) . ($i['place'] !== '' ? '<small>' . e($i['place']) . '</small>' : '') . '</span>' . $badge . '</li>';
    }

    /** Corner ornament SVG for a frame style (decorative, uses the theme accent colour). */
    private static function corner(string $frame): string
    {
        if ($frame === 'floral') {
            $petals = '';
            for ($a = 0; $a < 360; $a += 45) {
                $petals .= '<ellipse cx="40" cy="22" rx="7" ry="15" transform="rotate(' . $a . ' 40 40)"/>';
            }
            return '<svg viewBox="0 0 120 120" aria-hidden="true"><g fill="currentColor" opacity=".9">' . $petals . '</g><circle cx="40" cy="40" r="8" fill="#fff" opacity=".85"/>'
                . '<path d="M58 40 C80 36 96 52 116 50 M40 58 C36 80 52 96 50 116" stroke="currentColor" stroke-width="3" fill="none"/>'
                . '<ellipse cx="86" cy="42" rx="9" ry="4" fill="currentColor" transform="rotate(-20 86 42)"/><ellipse cx="42" cy="86" rx="4" ry="9" fill="currentColor" transform="rotate(-20 42 86)"/></svg>';
        }
        if ($frame === 'mandala') {
            $rays = '';
            for ($a = 0; $a < 360; $a += 30) {
                $rays .= '<path d="M60 60 L60 14 L66 30 Z" transform="rotate(' . $a . ' 60 60)"/>';
            }
            return '<svg viewBox="0 0 120 120" aria-hidden="true"><g fill="currentColor" opacity=".85">' . $rays . '</g>'
                . '<circle cx="60" cy="60" r="22" fill="none" stroke="currentColor" stroke-width="3"/><circle cx="60" cy="60" r="32" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 5"/>'
                . '<circle cx="60" cy="60" r="9" fill="currentColor"/></svg>';
        }
        return '';
    }

    public function render(array $config, array $ctx): string
    {
        $frame = self::frame($config, (string) ($ctx['theme']['key'] ?? ''));
        $data = $this->data($config, $ctx);
        $hasPhotos = $data['photos'] !== [] || (int) $config['album_id'] > 0;
        $opts = ['sec' => (int) $config['interval'], 'fit' => 'cover', 'kenburns' => true, 'shuffle' => false, 'captions' => false, 'dates' => false];
        $h = ContentApps::slidesAssets() . '<div class="ew-wrap ew-frame-' . e($frame) . ($hasPhotos ? ' ew-has-photos' : '') . '">';
        if ($frame === 'floral' || $frame === 'mandala') {
            $c = self::corner($frame);
            $h .= '<div class="ew-corner ew-tl">' . $c . '</div><div class="ew-corner ew-tr">' . $c . '</div><div class="ew-corner ew-bl">' . $c . '</div><div class="ew-corner ew-br">' . $c . '</div>';
        } elseif ($frame === 'lights') {
            $h .= '<div class="ew-lights"></div>';
        }
        if ($hasPhotos) {
            $h .= '<div class="ew-photo"><div id="ewShow" class="ew-show" data-opts="' . e(json_out($opts)) . '"></div></div>';
        }
        $h .= '<div class="ew-text">';
        if ($config['show_clock']) {
            $h .= '<div class="ew-clock" data-hc-clock="12"></div>';
        }
        if ($config['title'] !== '') {
            $h .= '<div class="ew-title">' . e($config['title']) . '</div>';
        }
        $h .= '<div class="ew-sep"><span></span>&#10086;<span></span></div>';
        if ($config['names'] !== '') {
            $h .= '<h1 class="ew-names">' . e($config['names']) . '</h1>';
        }
        if ($config['family'] !== '') {
            $h .= '<div class="ew-family">' . e($config['family']) . '</div>';
        }
        $meta = [];
        if ($config['event_date'] !== '') {
            $meta[] = ContentApps::dateLabel((string) $config['event_date']);
        }
        if ($config['venue'] !== '') {
            $meta[] = $config['venue'];
        }
        if ($meta) {
            $h .= '<div class="ew-meta">' . implode(' &middot; ', array_map('e', $meta)) . '</div>';
        }
        $items = '';
        foreach ($data['schedule'] as $i) {
            $items .= self::item($i, $data['labels']);
        }
        $h .= '<ul id="ewSchedule" class="ew-schedule"' . ($items === '' ? ' style="display:none"' : '') . '>' . $items . '</ul>';
        if ($config['welcome_on']) {
            $msgs = array_values(array_filter([$config['welcome_gu'], $config['welcome_hi'], $config['welcome_en']], static fn ($s) => $s !== ''));
            if ($msgs) {
                $h .= '<div class="ew-welcome hc-slides" id="ewWelcome">';
                foreach ($msgs as $k => $m) {
                    $h .= '<div class="hc-slide' . ($k === 0 ? ' is-active' : '') . '">' . e($m) . '</div>';
                }
                $h .= '</div>';
            }
        }
        return $h . '</div></div>';
    }
}
