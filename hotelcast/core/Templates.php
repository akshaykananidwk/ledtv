<?php
declare(strict_types=1);

/**
 * Template library (V2_SPEC §4, #12 + local guide #7).
 *
 * Template definitions live in hotelcast/templates/*.php (each file returns a list of definitions):
 *   id, category, layout ('festival' | 'card'), variant, name [en, gu, hi], icon, fields (schema),
 *   defaults [en, gu, hi] (text values), colors (language independent), motif / emoji (decoration).
 * Field types: text, textarea, color, list (one row per line, columns separated by "|"), time, url.
 *
 * render() builds a complete HTML document for a 1920×1080 TV (vh-based sizes, Gujarati font fallback,
 * no external resources: CSS / inline SVG / emoji only, QR codes from QrCode). Every user value is
 * escaped. Saved as a normal content item (type "html") whose settings keep
 * {template_id, fields, lang} so it can be edited again (templates.php?action=edit&id=…).
 */
final class Templates
{
    public const CATEGORIES = ['festival' => 'Festivals', 'notice' => 'Notices', 'temple' => 'Temple', 'guide' => 'Local guide'];
    public const FIELD_TYPES = ['text', 'textarea', 'color', 'list', 'time', 'url'];
    public const LANGS = ['en', 'gu', 'hi'];
    private const MAX = ['text' => 200, 'textarea' => 2000, 'time' => 40, 'url' => 1000];
    public const MAX_ROWS = 40;

    private static ?array $all = null;

    /** Folder with the definition files (overridable in tests). */
    public static string $dir = '';

    /** @return array<string, array> id => definition, in file / definition order */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $dir = self::$dir !== '' ? self::$dir : HC_ROOT . '/templates';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $defs = (static fn (string $__f) => require $__f)($f);
            foreach (is_array($defs) ? $defs : [] as $d) {
                if (is_array($d) && isset($d['id'], $d['category'], $d['fields']) && preg_match('/^[a-z0-9_]{2,40}$/', (string) $d['id'])) {
                    $d += ['layout' => 'card', 'variant' => 'notice', 'icon' => '', 'colors' => [], 'defaults' => [], 'name' => [], 'emoji' => [], 'motif' => ''];
                    $out[$d['id']] = $d;
                }
            }
        }
        return self::$all = $out;
    }

    public static function reset(): void
    {
        self::$all = null;
    }

    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** Definitions grouped by category (category order of CATEGORIES). */
    public static function grouped(): array
    {
        $out = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach (self::all() as $id => $t) {
            $out[$t['category']][$id] = $t;
        }
        return array_filter($out);
    }

    public static function name(array $tpl, ?string $lang = null): string
    {
        $lang ??= class_exists('I18n', false) ? I18n::lang() : 'en';
        return (string) ($tpl['name'][$lang] ?? $tpl['name']['en'] ?? $tpl['id']);
    }

    /** Default field values in a language (missing keys fall back to English, colours from 'colors'). */
    public static function defaults(array $tpl, string $lang = 'en'): array
    {
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        $vals = (array) ($tpl['defaults'][$lang] ?? []) + (array) ($tpl['defaults']['en'] ?? []) + (array) $tpl['colors'];
        $out = [];
        foreach ($tpl['fields'] as $f) {
            $v = $vals[$f['key']] ?? ($f['type'] === 'list' ? [] : '');
            if ($f['type'] === 'list' && is_string($v)) {
                $v = self::lines($v);
            }
            $out[$f['key']] = $v;
        }
        return self::normalize($tpl, $out);
    }

    /** Sanitise submitted values against the schema (unknown keys dropped). */
    public static function normalize(array $tpl, array $in): array
    {
        $out = [];
        foreach ($tpl['fields'] as $f) {
            $key = (string) $f['key'];
            $v = $in[$key] ?? null;
            switch ($f['type']) {
                case 'color':
                    $out[$key] = clean_color(is_string($v) ? $v : null, (string) ($tpl['colors'][$key] ?? '#000000'));
                    break;
                case 'list':
                    $rows = is_array($v) ? $v : self::lines(is_string($v) ? $v : '');
                    $clean = [];
                    foreach ($rows as $r) {
                        $r = is_array($r) ? implode(' | ', array_map('strval', $r)) : (is_scalar($r) ? (string) $r : '');
                        $r = trim(self::oneLine($r));
                        if ($r !== '') {
                            $clean[] = mb_substr($r, 0, 300);
                        }
                    }
                    $out[$key] = array_slice($clean, 0, self::MAX_ROWS);
                    break;
                case 'url':
                    $v = is_string($v) ? trim($v) : '';
                    $out[$key] = $v !== '' && ContentManager::validUrl($v, ['http', 'https']) ? mb_substr($v, 0, self::MAX['url']) : '';
                    break;
                case 'textarea':
                    $v = is_string($v) ? str_replace("\r", '', $v) : '';
                    $out[$key] = mb_substr(trim($v), 0, self::MAX['textarea']);
                    break;
                default: // text, time
                    $out[$key] = mb_substr(trim(self::oneLine(is_scalar($v) ? (string) $v : '')), 0, self::MAX[$f['type']] ?? 200);
            }
        }
        return $out;
    }

    /** Validation errors for required fields. */
    public static function errors(array $tpl, array $fields): array
    {
        $errors = [];
        foreach ($tpl['fields'] as $f) {
            $v = $fields[$f['key']] ?? null;
            if (!empty($f['required']) && ($v === null || $v === '' || $v === [])) {
                $errors[] = __(':f is required.', ['f' => __((string) $f['label'])]);
            }
        }
        return array_values(array_unique($errors));
    }

    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text))), fn ($l) => $l !== ''));
    }

    private static function oneLine(string $s): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    }

    /** Split a list row "a | b | c" into trimmed columns. */
    public static function cols(string $row, int $max = 4): array
    {
        return array_map('trim', array_slice(explode('|', $row, $max), 0, $max));
    }

    // ------------------------------------------------------------------ content items

    /** Template data stored in a content item, or null if it is not a template item. */
    public static function fromContent(array $item): ?array
    {
        if (($item['type'] ?? '') !== 'html') {
            return null;
        }
        $s = ContentManager::settings($item);
        if (empty($s['template_id']) || !is_string($s['template_id']) || !self::find($s['template_id'])) {
            return null;
        }
        return ['template_id' => $s['template_id'], 'fields' => is_array($s['fields'] ?? null) ? $s['fields'] : [], 'lang' => (string) ($s['lang'] ?? 'en')];
    }

    /**
     * Render + store as a content item (type html) of the current hotel. Returns the content id.
     * $id = existing content item (must be a template item of this hotel).
     */
    public static function save(?int $id, array $tpl, array $fields, string $title, int $duration, bool $active, string $lang = 'en', ?int $userId = null): int
    {
        $fields = self::normalize($tpl, $fields);
        $row = [
            'title' => mb_substr(trim($title) !== '' ? trim($title) : self::name($tpl, $lang), 0, 190),
            'body' => self::render($tpl, $fields),
            'settings' => json_out(['template_id' => $tpl['id'], 'fields' => $fields, 'lang' => in_array($lang, self::LANGS, true) ? $lang : 'en']),
            'duration' => max(0, min(86400, $duration)),
            'is_active' => $active ? 1 : 0,
        ];
        if ($id) {
            DB::update('content_items', $row, 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('content_items', $row + ['type' => 'html', 'url' => null, 'created_by' => $userId, 'created_at' => now()]);
        }
        Settings::bumpContentVersion();
        return $id;
    }

    // ------------------------------------------------------------------ rendering

    /** Full HTML document for the TV. */
    public static function render(array $tpl, array $fields, array $ctx = []): string
    {
        $f = self::normalize($tpl, $fields);
        $ctx += ['hotel' => Tenant::has() ? (string) Settings::get('hotel_name', '') : ''];
        $body = $tpl['layout'] === 'festival' ? self::festival($tpl, $f, $ctx) : self::card($tpl, $f, $ctx);
        $title = e(self::name($tpl, 'en'));
        return '<!DOCTYPE html><html lang="' . e((string) ($ctx['lang'] ?? 'en')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="generator" content="HotelCast template ' . e($tpl['id']) . '">'
            . '<title>' . $title . '</title><style>' . self::baseCss() . $body['css'] . '</style></head><body class="tpl tpl-' . e($tpl['id']) . '">'
            . $body['html'] . '</body></html>';
    }

    private static function baseCss(): string
    {
        return '*{box-sizing:border-box;margin:0;padding:0}html,body{width:100%;height:100%;overflow:hidden}'
            . 'body{font-family:"Noto Sans Gujarati","Noto Sans Devanagari","Noto Sans","Shruti","Lohit Gujarati",Roboto,Arial,sans-serif;'
            . 'line-height:1.25;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}'
            . '.emo{font-family:"Noto Color Emoji","Apple Color Emoji","Segoe UI Emoji",sans-serif;line-height:1}'
            . '@keyframes spin{to{transform:rotate(360deg)}}@keyframes floaty{50%{transform:translateY(-1.2vh)}}'
            . '@keyframes glow{50%{opacity:.55}}';
    }

    /** Mix two #RRGGBB colours (0 = a, 1 = b). */
    public static function mix(string $a, string $b, float $t): string
    {
        $a = clean_color($a, '#000000');
        $b = clean_color($b, '#000000');
        $c = '#';
        for ($i = 1; $i < 7; $i += 2) {
            $x = hexdec(substr($a, $i, 2));
            $y = hexdec(substr($b, $i, 2));
            $c .= sprintf('%02X', (int) round($x + ($y - $x) * $t));
        }
        return $c;
    }

    /** Black or white text for a background. */
    public static function contrast(string $bg): string
    {
        $bg = clean_color($bg, '#000000');
        $l = 0.299 * hexdec(substr($bg, 1, 2)) + 0.587 * hexdec(substr($bg, 3, 2)) + 0.114 * hexdec(substr($bg, 5, 2));
        return $l > 150 ? '#111111' : '#FFFFFF';
    }

    private static function text(array $f, string $key): string
    {
        return e((string) ($f[$key] ?? ''));
    }

    private static function para(array $f, string $key): string
    {
        return nl2br(e((string) ($f[$key] ?? '')), false);
    }

    /** Font size (vh) for n rows of a list in a given height budget. */
    private static function rowSize(int $n, float $budget = 52, float $min = 2.4, float $max = 5.2): float
    {
        return round(max($min, min($max, $budget / max(1, $n) * 0.62)), 2);
    }

    // ------------------------------------------------------------------ festival layout

    private static function festival(array $tpl, array $f, array $ctx): array
    {
        $bg = $f['bg_color'] ?? '#2A0A3D';
        $ac = $f['accent_color'] ?? '#FFC107';
        $fg = $f['text_color'] ?? self::contrast($bg);
        $light = self::mix($bg, '#FFFFFF', 0.18);
        $dark = self::mix($bg, '#000000', 0.45);
        $motif = self::motif((string) $tpl['motif'], $ac, $bg);
        $emoji = '';
        foreach (array_slice((array) $tpl['emoji'], 0, 5) as $i => $em) {
            $emoji .= '<span class="emo" style="animation-delay:' . ($i * 0.4) . 's">' . e((string) $em) . '</span>';
        }
        $sig = trim((string) ($f['signature'] ?? '')) !== '' ? self::text($f, 'signature') : e($ctx['hotel']);
        $css = "body{background:radial-gradient(ellipse at 50% 42%,{$light} 0%,{$bg} 55%,{$dark} 100%);color:{$fg}}"
            . ".frame{position:absolute;inset:2.2vh;border:.5vh double {$ac};border-radius:2vh;pointer-events:none;opacity:.8}"
            . '.m{position:absolute;opacity:.33}.m svg{width:100%;height:100%}'
            . '.m1{width:46vh;height:46vh;left:-9vh;bottom:-9vh}.m2{width:46vh;height:46vh;right:-9vh;top:-9vh}'
            . '.m.rot svg{animation:spin 90s linear infinite}'
            . '.wrap{position:relative;z-index:2;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:6vh 12vw}'
            . '.emojis{font-size:9vh;display:flex;gap:3vh;margin-bottom:2vh}.emojis span{display:inline-block;animation:floaty 4s ease-in-out infinite}'
            . "h1{font-size:12vh;font-weight:800;color:{$ac};letter-spacing:.02em;text-shadow:0 .4vh 2vh rgba(0,0,0,.45),0 0 4vh " . self::mix($ac, $bg, 0.3) . '}'
            . 'h2{font-size:5.4vh;font-weight:600;margin-top:1.5vh}'
            . '.msg{font-size:3.8vh;max-width:70vw;margin-top:3vh;opacity:.95}'
            . ".sig{margin-top:4vh;font-size:3.2vh;font-weight:700;padding-top:1.6vh;border-top:.35vh solid {$ac};letter-spacing:.06em}";
        $rot = in_array($tpl['motif'], ['mandala', 'rangoli', 'dandiya', 'rakhi', 'fireworks', 'chakra'], true) ? ' rot' : '';
        $html = '<div class="m m1' . $rot . '">' . $motif . '</div><div class="m m2' . $rot . '">' . $motif . '</div><div class="frame"></div>'
            . '<div class="wrap">' . ($emoji !== '' ? '<div class="emojis">' . $emoji . '</div>' : '')
            . '<h1>' . self::text($f, 'title') . '</h1>'
            . (($f['subtitle'] ?? '') !== '' ? '<h2>' . self::text($f, 'subtitle') . '</h2>' : '')
            . (($f['message'] ?? '') !== '' ? '<p class="msg">' . self::para($f, 'message') . '</p>' : '')
            . ($sig !== '' ? '<div class="sig">' . $sig . '</div>' : '')
            . '</div>';
        return ['css' => $css, 'html' => $html];
    }

    /** Decorative SVG (no external images). */
    public static function motif(string $name, string $ac, string $bg): string
    {
        $ac = clean_color($ac, '#FFC107');
        $c2 = self::mix($ac, '#FFFFFF', 0.45);
        $svg = static fn (string $inner, string $vb = '-100 -100 200 200') => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $vb . '" aria-hidden="true">' . $inner . '</svg>';
        switch ($name) {
            case 'diya':
                return $svg('<defs><radialGradient id="fl" cx="50%" cy="60%" r="60%"><stop offset="0" stop-color="#FFF8E1"/><stop offset=".5" stop-color="#FFC107"/><stop offset="1" stop-color="#FF5722" stop-opacity="0"/></radialGradient></defs>'
                    . '<circle r="95" fill="none" stroke="' . $ac . '" stroke-width="2" stroke-dasharray="4 6"/>'
                    . '<ellipse cx="0" cy="-22" rx="20" ry="42" fill="url(#fl)" style="animation:glow 2.5s ease-in-out infinite"/>'
                    . '<path d="M-70 10 Q0 18 70 10 Q60 62 0 66 Q-60 62 -70 10Z" fill="' . $ac . '"/>'
                    . '<path d="M-70 10 Q0 18 70 10" fill="none" stroke="' . $c2 . '" stroke-width="5"/>'
                    . '<g fill="' . $c2 . '"><circle cx="-40" cy="38" r="5"/><circle cx="0" cy="44" r="5"/><circle cx="40" cy="38" r="5"/></g>');
            case 'tricolor':
                $spokes = '';
                for ($i = 0; $i < 24; $i++) {
                    $a = deg2rad($i * 15);
                    $spokes .= '<line x1="0" y1="0" x2="' . round(26 * cos($a), 2) . '" y2="' . round(26 * sin($a), 2) . '"/>';
                }
                return $svg('<path d="M-100 -60 Q-50 -80 0 -60 T100 -60 V-20 Q50 -40 0 -20 T-100 -20Z" fill="#FF9933"/>'
                    . '<path d="M-100 -20 Q-50 -40 0 -20 T100 -20 V20 Q50 0 0 20 T-100 20Z" fill="#FFFFFF"/>'
                    . '<path d="M-100 20 Q-50 0 0 20 T100 20 V60 Q50 40 0 60 T-100 60Z" fill="#138808"/>'
                    . '<g transform="translate(0 0)" stroke="#000080" stroke-width="1.6" fill="none"><circle r="28" stroke-width="3"/>' . $spokes . '<circle r="5" fill="#000080"/></g>');
            case 'kites':
                $k = static fn (float $x, float $y, float $rot, string $c, string $c2) => '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $rot . ')">'
                    . '<path d="M0 -40 L28 0 L0 46 L-28 0Z" fill="' . $c . '"/><path d="M0 -40 L0 46 M-28 0 L28 0" stroke="' . $c2 . '" stroke-width="2"/>'
                    . '<path d="M0 46 q10 20 -6 34 q-14 14 4 30" stroke="' . $c2 . '" stroke-width="2" fill="none"/><path d="M-6 50 L6 50 L0 60Z" fill="' . $c2 . '"/></g>';
                return $svg($k(-45, -30, -18, '#E91E63', '#FFFFFF') . $k(40, -50, 14, '#FFC107', '#FFFFFF') . $k(30, 35, 30, '#00BCD4', '#FFFFFF')
                    . '<path d="M-45 16 Q-10 80 90 100" stroke="#FFFFFF" stroke-width="1.2" fill="none" opacity=".7"/>');
            case 'splash':
                $cols = ['#E91E63', '#FFEB3B', '#00E676', '#2979FF', '#FF6D00', '#AA00FF'];
                $out = '<defs><filter id="bl"><feGaussianBlur stdDeviation="6"/></filter></defs><g filter="url(#bl)">';
                foreach ([[-50, -40, 38], [30, -55, 30], [55, 20, 36], [-20, 40, 34], [0, -5, 28], [-70, 30, 22]] as $i => [$x, $y, $r]) {
                    $out .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="' . $cols[$i] . '"/>';
                }
                return $svg($out . '</g>');
            case 'fireworks':
                $out = '';
                foreach ([[-40, -35, 45, $ac], [45, 10, 38, '#FF4081'], [-20, 50, 30, '#40C4FF']] as [$x, $y, $r, $c]) {
                    $out .= '<g transform="translate(' . $x . ' ' . $y . ')" stroke="' . $c . '" stroke-width="3" stroke-linecap="round">';
                    for ($i = 0; $i < 16; $i++) {
                        $a = deg2rad($i * 22.5);
                        $out .= '<line x1="' . round($r * 0.35 * cos($a), 1) . '" y1="' . round($r * 0.35 * sin($a), 1) . '" x2="' . round($r * cos($a), 1) . '" y2="' . round($r * sin($a), 1) . '"/>';
                    }
                    $out .= '<circle r="4" fill="' . $c . '"/></g>';
                }
                return $svg($out);
            case 'rakhi':
                $petals = '';
                for ($i = 0; $i < 12; $i++) {
                    $petals .= '<ellipse cx="0" cy="-30" rx="11" ry="24" fill="' . ($i % 2 ? $ac : '#E91E63') . '" transform="rotate(' . ($i * 30) . ')"/>';
                }
                return $svg('<path d="M-100 0 Q-60 -10 -40 0 M40 0 Q60 10 100 0" stroke="' . $c2 . '" stroke-width="6" fill="none"/>'
                    . $petals . '<circle r="20" fill="#FFEB3B"/><circle r="10" fill="#E91E63"/>');
            case 'peacock':
                return $svg('<path d="M0 95 Q-6 20 0 -60" stroke="#2E7D32" stroke-width="4" fill="none"/>'
                    . '<ellipse cx="0" cy="-30" rx="46" ry="62" fill="#1B5E20"/><ellipse cx="0" cy="-34" rx="34" ry="46" fill="#00897B"/>'
                    . '<ellipse cx="0" cy="-38" rx="22" ry="28" fill="#FFD600"/><ellipse cx="0" cy="-40" rx="15" ry="18" fill="#0D47A1"/>'
                    . '<ellipse cx="0" cy="-42" rx="7" ry="8" fill="#1A237E"/>');
            case 'dandiya':
                return $svg(self::mandalaInner($ac, $c2, 10) . '<g stroke-linecap="round"><line x1="-70" y1="-70" x2="60" y2="60" stroke="#E91E63" stroke-width="9"/>'
                    . '<line x1="70" y1="-70" x2="-60" y2="60" stroke="#FFC107" stroke-width="9"/></g>');
            case 'bow':
                return $svg('<path d="M-30 -85 Q60 0 -30 85" stroke="' . $ac . '" stroke-width="8" fill="none"/>'
                    . '<line x1="-30" y1="-85" x2="-30" y2="85" stroke="' . $c2 . '" stroke-width="2"/>'
                    . '<line x1="-85" y1="0" x2="80" y2="0" stroke="' . $c2 . '" stroke-width="4"/><path d="M80 0 L62 -10 L62 10Z" fill="' . $ac . '"/>'
                    . '<path d="M-85 0 l-10 -9 M-85 0 l-10 9" stroke="' . $c2 . '" stroke-width="3"/>');
            case 'chariot':
                return $svg('<path d="M-60 60 L-60 0 L-40 -10 L-30 -60 L0 -95 L30 -60 L40 -10 L60 0 L60 60Z" fill="' . $ac . '"/>'
                    . '<path d="M0 -95 L0 -120 L22 -112 L0 -104" fill="#E53935" stroke="#E53935"/>'
                    . '<rect x="-20" y="10" width="40" height="50" rx="20" fill="' . self::mix($bg, '#000000', 0.3) . '"/>'
                    . '<g fill="none" stroke="' . $c2 . '" stroke-width="5"><circle cx="-45" cy="75" r="22"/><circle cx="45" cy="75" r="22"/></g>', '-100 -125 200 225');
            case 'chakra':
            case 'mandala':
            case 'rangoli':
            default:
                return $svg(self::mandalaInner($ac, $c2, $name === 'rangoli' ? 12 : 16));
        }
    }

    private static function mandalaInner(string $ac, string $c2, int $n): string
    {
        $g = '<circle r="96" fill="none" stroke="' . $ac . '" stroke-width="2"/><circle r="86" fill="none" stroke="' . $c2 . '" stroke-width="1" stroke-dasharray="3 5"/>';
        for ($i = 0; $i < $n; $i++) {
            $r = $i * 360 / $n;
            $g .= '<ellipse cx="0" cy="-58" rx="12" ry="28" fill="none" stroke="' . $ac . '" stroke-width="2.5" transform="rotate(' . $r . ')"/>'
                . '<circle cx="0" cy="-90" r="3.5" fill="' . $c2 . '" transform="rotate(' . ($r + 180 / $n) . ')"/>'
                . '<path d="M0 -24 L7 -36 L0 -48 L-7 -36Z" fill="' . $c2 . '" transform="rotate(' . $r . ')"/>';
        }
        return $g . '<circle r="18" fill="' . $ac . '"/><circle r="9" fill="' . $c2 . '"/>';
    }

    // ------------------------------------------------------------------ card layout (notices, temple, guide)

    private static function card(array $tpl, array $f, array $ctx): array
    {
        $bg = $f['bg_color'] ?? '#0D47A1';
        $ac = $f['accent_color'] ?? '#FFC107';
        $fg = $f['text_color'] ?? self::contrast($bg);
        $panel = self::mix($bg, '#FFFFFF', 0.08);
        $line = self::mix($bg, $fg, 0.22);
        $variant = (string) $tpl['variant'];
        $icon = trim((string) ($f['icon'] ?? '')) !== '' ? (string) $f['icon'] : (string) $tpl['icon'];
        $foot = trim((string) ($f['signature'] ?? '')) !== '' ? (string) $f['signature'] : (string) $ctx['hotel'];
        $css = "body{background:linear-gradient(160deg," . self::mix($bg, '#FFFFFF', 0.06) . " 0%,{$bg} 45%," . self::mix($bg, '#000000', 0.35) . " 100%);color:{$fg}}"
            . '.page{position:relative;height:100%;display:flex;flex-direction:column;padding:5vh 6vw 4vh}'
            . ".hd{display:flex;align-items:center;gap:3vh;padding-bottom:2.4vh;border-bottom:.5vh solid {$ac}}"
            . ".ic{flex:none;width:13vh;height:13vh;border-radius:50%;background:{$ac};display:flex;align-items:center;justify-content:center;font-size:7.5vh;box-shadow:0 .6vh 2vh rgba(0,0,0,.35)}"
            . 'h1{font-size:8vh;font-weight:800;line-height:1.08}h1+.sub{font-size:3.6vh;opacity:.85;margin-top:.6vh}'
            . '.main{flex:1;display:flex;flex-direction:column;justify-content:center;min-height:0;padding:3vh 0}'
            . ".ft{display:flex;justify-content:space-between;align-items:flex-end;gap:4vw;font-size:2.9vh;opacity:.92;border-top:.25vh solid {$line};padding-top:1.8vh}"
            . ".ft b{color:{$ac}}.note{font-size:3.3vh;opacity:.92;margin-top:2.5vh}"
            . ".big{font-size:17vh;font-weight:800;color:{$ac};line-height:1;letter-spacing:.02em;text-shadow:0 .6vh 2.4vh rgba(0,0,0,.35)}"
            . '.biglbl{font-size:4vh;opacity:.85;margin-bottom:1.6vh;text-transform:uppercase;letter-spacing:.12em}'
            . '.msg{font-size:4vh;margin-top:2.4vh;max-width:80vw}'
            . ".tbl{width:100%;border-collapse:collapse}.tbl td{padding:1.1vh 1.4vw;border-bottom:.2vh solid {$line};vertical-align:middle}"
            . ".tbl tr:last-child td{border-bottom:0}.tbl td.k{font-weight:800;color:{$ac};white-space:nowrap}.tbl td.n{opacity:.85;text-align:right;white-space:nowrap}"
            . ".tbl th{text-align:left;font-size:.62em;text-transform:uppercase;letter-spacing:.1em;opacity:.75;padding:0 1.4vw 1vh;border-bottom:.35vh solid {$ac}}"
            . ".panel{background:{$panel};border-radius:2.4vh;padding:2.4vh 2.4vw;box-shadow:inset 0 0 0 .25vh {$line}}"
            . '.split{display:flex;gap:4vw;align-items:center}.split>.grow{flex:1;min-width:0}'
            . '.qr{flex:none;background:#fff;padding:1.6vh;border-radius:2vh;box-shadow:0 1vh 3vh rgba(0,0,0,.35)}.qr svg{display:block;width:100%;height:100%}'
            . '.qrcap{font-size:2.6vh;text-align:center;margin-top:1.2vh;overflow-wrap:anywhere;max-width:46vh}';
        $html = '<div class="page"><div class="hd">' . ($icon !== '' ? '<div class="ic emo">' . e($icon) . '</div>' : '')
            . '<div><h1>' . self::text($f, 'title') . '</h1>' . (($f['subtitle'] ?? '') !== '' ? '<div class="sub">' . self::text($f, 'subtitle') . '</div>' : '') . '</div></div>'
            . '<div class="main">';
        [$mCss, $mHtml] = match ($variant) {
            'big' => self::vBig($f, $ac),
            'board' => self::vBoard($f, $ac, $line),
            'tiles' => self::vTiles($f, $ac),
            'timetable' => self::vTimetable($f, $ac),
            'sign' => self::vSign($f, $ac),
            'offer' => self::vOffer($f, $ac, $bg),
            'wifi' => self::vWifi($f, $ac),
            'qr' => self::vQr($f, $ac),
            'list' => self::vList($tpl, $f),
            default => self::vNotice($f),
        };
        $css .= $mCss;
        $html .= $mHtml . (($f['note'] ?? '') !== '' ? '<div class="note">' . self::para($f, 'note') . '</div>' : '') . '</div>'
            . '<div class="ft"><span><b>' . e($foot) . '</b></span><span>' . self::text($f, 'footer') . '</span></div></div>';
        return ['css' => $css, 'html' => $html];
    }

    private static function rowsOf(array $f, string $key = 'rows'): array
    {
        return array_values(array_filter((array) ($f[$key] ?? []), 'is_string'));
    }

    private static function vNotice(array $f): array
    {
        $rows = self::rowsOf($f);
        $size = self::rowSize(count($rows), 48, 3, 5.6);
        $t = '';
        foreach ($rows as $r) {
            $c = self::cols($r, 3);
            $t .= '<tr><td class="k">' . e($c[0]) . '</td><td>' . e($c[1] ?? '') . '</td>' . (isset($c[2]) ? '<td class="n">' . e($c[2]) . '</td>' : '') . '</tr>';
        }
        return ['', ($t !== '' ? '<div class="panel" style="font-size:' . $size . 'vh"><table class="tbl">' . $t . '</table></div>' : '')
            . (($f['message'] ?? '') !== '' ? '<p class="msg">' . self::para($f, 'message') . '</p>' : '')];
    }

    private static function vBig(array $f, string $ac): array
    {
        return ['.main{align-items:center;text-align:center}', (($f['big_label'] ?? '') !== '' ? '<div class="biglbl">' . self::text($f, 'big_label') . '</div>' : '')
            . '<div class="big">' . self::text($f, 'big') . '</div>'
            . (($f['message'] ?? '') !== '' ? '<p class="msg">' . self::para($f, 'message') . '</p>' : '')];
    }

    private static function vTimetable(array $f, string $ac): array
    {
        $rows = self::rowsOf($f);
        $size = self::rowSize(count($rows), 56, 2.6, 5.4);
        $t = '';
        foreach ($rows as $r) {
            $c = self::cols($r, 3);
            $t .= '<tr><td class="k">' . e($c[0]) . '</td><td>' . e($c[1] ?? '') . '</td><td class="n">' . e($c[2] ?? '') . '</td></tr>';
        }
        return ['', '<div class="panel" style="font-size:' . $size . 'vh"><table class="tbl">' . $t . '</table></div>'
            . (($f['message'] ?? '') !== '' ? '<p class="msg" style="font-size:3.2vh">' . self::para($f, 'message') . '</p>' : '')];
    }

    private static function vBoard(array $f, string $ac, string $line): array
    {
        $rows = self::rowsOf($f);
        $cols = count($rows) > 8 ? 2 : 1;
        $size = self::rowSize((int) ceil(count($rows) / $cols), 54, 2.6, 5);
        $items = '';
        foreach ($rows as $r) {
            $c = self::cols($r, 3);
            $items .= '<div class="it"><div class="nm">' . e($c[0]) . (isset($c[2]) && $c[2] !== '' ? '<small>' . e($c[2]) . '</small>' : '') . '</div><div class="dots"></div><div class="pr">' . e($c[1] ?? '') . '</div></div>';
        }
        $css = '.board{display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:1.4vh 5vw;font-size:' . $size . 'vh}'
            . '.it{display:flex;align-items:baseline;gap:1vw}.nm{font-weight:700}.nm small{display:block;font-size:.6em;font-weight:400;opacity:.8}'
            . ".dots{flex:1;border-bottom:.35vh dotted {$line};transform:translateY(-.4em)}.pr{font-weight:800;color:{$ac};white-space:nowrap}";
        return [$css, '<div class="board">' . $items . '</div>' . (($f['message'] ?? '') !== '' ? '<p class="msg" style="font-size:3.2vh">' . self::para($f, 'message') . '</p>' : '')];
    }

    private static function vTiles(array $f, string $ac): array
    {
        $rows = self::rowsOf($f);
        $palette = ['#C62828', '#1565C0', '#EF6C00', '#2E7D32', '#6A1B9A', '#00838F', '#AD1457', '#4E342E'];
        $n = count($rows);
        $cols = $n <= 4 ? max(1, $n) : ($n <= 6 ? 3 : 4);
        $tiles = '';
        foreach ($rows as $i => $r) {
            $c = self::cols($r, 3);
            $tiles .= '<div class="tile" style="background:' . $palette[$i % count($palette)] . '"><div class="tl">' . e($c[0]) . '</div><div class="tn">' . e($c[1] ?? '') . '</div>'
                . (($c[2] ?? '') !== '' ? '<div class="tx">' . e($c[2]) . '</div>' : '') . '</div>';
        }
        $numSize = $n > 8 ? 7 : ($n > 4 ? 9 : 12);
        $css = '.tiles{display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:2.2vh}'
            . '.tile{border-radius:2.4vh;padding:2.4vh 1.6vw;color:#fff;text-align:center;box-shadow:0 1vh 2.6vh rgba(0,0,0,.35)}'
            . '.tl{font-size:3.6vh;font-weight:700}.tn{font-size:' . $numSize . 'vh;font-weight:900;line-height:1.05;letter-spacing:.03em}.tx{font-size:2.6vh;opacity:.9}';
        return [$css, '<div class="tiles">' . $tiles . '</div>' . (($f['message'] ?? '') !== '' ? '<p class="msg" style="font-size:3.2vh">' . self::para($f, 'message') . '</p>' : '')];
    }

    private static function vSign(array $f, string $ac): array
    {
        $sign = '<svg viewBox="0 0 200 200" aria-hidden="true"><circle cx="100" cy="100" r="88" fill="#fff" stroke="#D32F2F" stroke-width="18"/>'
            . '<rect x="38" y="104" width="104" height="22" rx="3" fill="#455A64"/><rect x="142" y="104" width="20" height="22" rx="3" fill="#FF7043"/>'
            . '<path d="M150 96 q-10 -18 4 -34 q10 -12 0 -26" stroke="#90A4AE" stroke-width="6" fill="none" stroke-linecap="round"/>'
            . '<line x1="38" y1="38" x2="162" y2="162" stroke="#D32F2F" stroke-width="18" stroke-linecap="round"/></svg>';
        return ['.sign{display:flex;align-items:center;gap:5vw}.sign svg{flex:none;width:44vh;height:44vh;filter:drop-shadow(0 1vh 2vh rgba(0,0,0,.4))}'
            . '.sign .msg{font-size:5vh;font-weight:700}.sign .sm{font-size:3.4vh;opacity:.9;margin-top:2vh}',
            '<div class="sign">' . $sign . '<div><p class="msg">' . self::para($f, 'message') . '</p>'
            . (($f['big'] ?? '') !== '' ? '<p class="sm">' . self::text($f, 'big') . '</p>' : '') . '</div></div>'];
    }

    private static function vOffer(array $f, string $ac, string $bg): array
    {
        $fgBurst = self::contrast($ac);
        $pts = [];
        for ($i = 0; $i < 32; $i++) {
            $r = $i % 2 ? 80 : 98;
            $a = deg2rad($i * 360 / 32);
            $pts[] = round(100 + $r * cos($a), 1) . ',' . round(100 + $r * sin($a), 1);
        }
        $burst = '<svg viewBox="0 0 200 200" aria-hidden="true"><polygon points="' . implode(' ', $pts) . '" fill="' . $ac . '"/></svg>';
        $css = '.offer{display:flex;align-items:center;gap:5vw}.burst{position:relative;flex:none;width:52vh;height:52vh;animation:floaty 5s ease-in-out infinite}'
            . '.burst svg{position:absolute;inset:0;width:100%;height:100%;animation:spin 60s linear infinite}'
            . ".burst div{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;color:{$fgBurst};font-size:11vh;font-weight:900;line-height:.95;padding:7vh}"
            . ".code{display:inline-block;margin-top:3vh;font-size:4.6vh;font-weight:800;letter-spacing:.15em;border:.45vh dashed {$ac};border-radius:1.4vh;padding:1vh 2.4vw}"
            . '.valid{font-size:3vh;opacity:.85;margin-top:2vh}';
        return [$css, '<div class="offer"><div class="burst">' . $burst . '<div>' . self::text($f, 'big') . '</div></div><div class="grow">'
            . '<p class="msg" style="font-size:4.6vh;margin-top:0">' . self::para($f, 'message') . '</p>'
            . (($f['code'] ?? '') !== '' ? '<div class="code">' . self::text($f, 'code') . '</div>' : '')
            . (($f['valid'] ?? '') !== '' ? '<div class="valid">' . self::text($f, 'valid') . '</div>' : '') . '</div></div>'];
    }

    /** Wi-Fi QR payload (WIFI:T:WPA;S:…;P:…;;) with the special characters escaped. */
    public static function wifiPayload(string $ssid, string $password): string
    {
        $esc = static fn (string $s) => preg_replace('/([\\\\;,:"])/', '\\\\$1', $s);
        return 'WIFI:T:' . ($password === '' ? 'nopass' : 'WPA') . ';S:' . $esc($ssid) . ';' . ($password !== '' ? 'P:' . $esc($password) . ';' : '') . ';';
    }

    private static function qrBox(string $payload, string $caption, float $vh = 40): string
    {
        if ($payload === '') {
            return '';
        }
        try {
            $svg = QrCode::svg($payload, '#000000', '#FFFFFF', $caption);
        } catch (Throwable) {
            return '';
        }
        return '<div><div class="qr" style="width:' . $vh . 'vh;height:' . $vh . 'vh">' . $svg . '</div>'
            . ($caption !== '' ? '<div class="qrcap">' . e($caption) . '</div>' : '') . '</div>';
    }

    private static function vWifi(array $f, string $ac): array
    {
        $ssid = (string) ($f['ssid'] ?? '');
        $pw = (string) ($f['password'] ?? '');
        $rows = '<tr><td class="k">' . self::text($f, 'ssid_label') . '</td><td>' . e($ssid) . '</td></tr>'
            . '<tr><td class="k">' . self::text($f, 'password_label') . '</td><td style="letter-spacing:.06em">' . e($pw) . '</td></tr>';
        return ['.tbl td{font-size:6vh}.tbl td.k{font-size:3.6vh;text-transform:uppercase;letter-spacing:.1em}',
            '<div class="split"><div class="grow"><div class="panel"><table class="tbl">' . $rows . '</table></div>'
            . (($f['message'] ?? '') !== '' ? '<p class="msg">' . self::para($f, 'message') . '</p>' : '') . '</div>'
            . ($ssid !== '' ? self::qrBox(self::wifiPayload($ssid, $pw), (string) ($f['qr_caption'] ?? ''), 42) : '') . '</div>'];
    }

    private static function vQr(array $f, string $ac): array
    {
        $url = (string) ($f['url'] ?? '');
        return ['.place{font-size:6vh;font-weight:800;color:' . $ac . '}.addr{font-size:3.8vh;margin-top:2vh;opacity:.92}',
            '<div class="split"><div class="grow"><div class="place">' . self::text($f, 'place') . '</div><div class="addr">' . self::para($f, 'address') . '</div>'
            . (($f['message'] ?? '') !== '' ? '<p class="msg" style="font-size:3.4vh">' . self::para($f, 'message') . '</p>' : '') . '</div>'
            . self::qrBox($url, (string) ($f['qr_caption'] ?? '') !== '' ? (string) $f['qr_caption'] : $url, 46) . '</div>'];
    }

    private static function vList(array $tpl, array $f): array
    {
        $rows = self::rowsOf($f);
        $size = self::rowSize(count($rows) + 1, 56, 2.4, 4.8);
        $heads = array_values(array_filter(array_map('trim', explode('|', (string) ($f['columns'] ?? ''))), fn ($h) => $h !== ''));
        $ncol = max(2, min(4, max(count($heads), ...array_map(fn ($r) => count(self::cols($r)), $rows ?: ['a|b']))));
        $t = '';
        if ($heads) {
            $t .= '<tr>';
            for ($i = 0; $i < $ncol; $i++) {
                $t .= '<th' . ($i === $ncol - 1 && $ncol > 2 ? ' style="text-align:right"' : '') . '>' . e($heads[$i] ?? '') . '</th>';
            }
            $t .= '</tr>';
        }
        foreach ($rows as $r) {
            $c = self::cols($r);
            $t .= '<tr>';
            for ($i = 0; $i < $ncol; $i++) {
                $cls = $i === 0 ? 'k' : ($i === $ncol - 1 && $ncol > 2 ? 'n' : '');
                $t .= '<td' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . e($c[$i] ?? '') . '</td>';
            }
            $t .= '</tr>';
        }
        $qr = ($f['url'] ?? '') !== '' ? self::qrBox((string) $f['url'], (string) ($f['qr_caption'] ?? ''), 30) : '';
        $table = '<div class="panel" style="font-size:' . $size . 'vh"><table class="tbl">' . $t . '</table></div>';
        return ['', ($qr !== '' ? '<div class="split"><div class="grow">' . $table . '</div>' . $qr . '</div>' : $table)
            . (($f['message'] ?? '') !== '' ? '<p class="msg" style="font-size:3.2vh">' . self::para($f, 'message') . '</p>' : '')];
    }
}
