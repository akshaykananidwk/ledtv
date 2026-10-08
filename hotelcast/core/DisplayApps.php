<?php
declare(strict_types=1);

/**
 * Display apps (2.3) registry and runtime — see core/DisplayApp.php and docs/modules/display_apps.md.
 *
 *  - Registry: every core/Apps/*App.php class extending DisplayApp (file name order), DisplayApps::all().
 *  - Content item: content_items.type = 'app', settings
 *      {"app": key, "config": {...}, "theme": preset, "font": auto|gujarati|hindi|latin|serif,
 *       "accent": "#RRGGBB"|null, "lang": auto|en|gu|hi}
 *  - TV item: {"type":"url","url": signed display URL,"app": key,"refresh_sec":0} (ContentManager::toTvItem).
 *  - Signed URL: <base>/display/?c=<content id>&s=<sig>&v=<rev>, sig = first 32 hex chars of
 *    HMAC-SHA256("display:<hotel_id>:<content_id>", APP_KEY). Read-only, no login, not guessable.
 *    `v` only changes the URL (and the TV content hash) when the item is edited; it is not checked.
 *  - Themes (#20): THEMES presets → CSS variables (--hc-bg, --hc-fg, --hc-accent, --hc-card, --hc-font …).
 */
final class DisplayApps
{
    /** Theme presets: CSS variable values (English label, translated with __()). */
    public const THEMES = [
        'classic_dark' => ['label' => 'Classic dark', 'bg' => '#0B0F1A', 'bg2' => '#1B2235', 'fg' => '#F5F7FA', 'muted' => '#9AA4B2', 'accent' => '#FFB300', 'card' => 'rgba(255,255,255,.07)', 'card_fg' => '#F5F7FA', 'border' => 'rgba(255,255,255,.14)', 'heading' => 'inherit'],
        'light' => ['label' => 'Light', 'bg' => '#EEF2F7', 'bg2' => '#FFFFFF', 'fg' => '#1A1F2B', 'muted' => '#5B6474', 'accent' => '#1565C0', 'card' => '#FFFFFF', 'card_fg' => '#1A1F2B', 'border' => 'rgba(0,0,0,.10)', 'heading' => 'inherit'],
        'temple_saffron' => ['label' => 'Temple saffron', 'bg' => '#5A1300', 'bg2' => '#A63A00', 'fg' => '#FFF8E1', 'muted' => '#FFD8A8', 'accent' => '#FFC107', 'card' => 'rgba(255,236,179,.12)', 'card_fg' => '#FFF8E1', 'border' => 'rgba(255,213,79,.35)', 'heading' => 'inherit'],
        'diwali' => ['label' => 'Diwali', 'bg' => '#1A0633', 'bg2' => '#4A0D3B', 'fg' => '#FFF3E0', 'muted' => '#E1BEE7', 'accent' => '#FFB300', 'card' => 'rgba(255,183,77,.12)', 'card_fg' => '#FFF3E0', 'border' => 'rgba(255,179,0,.35)', 'heading' => 'inherit'],
        'navratri' => ['label' => 'Navratri', 'bg' => '#7A0030', 'bg2' => '#D84315', 'fg' => '#FFFFFF', 'muted' => '#FFE0B2', 'accent' => '#FFEB3B', 'card' => 'rgba(255,255,255,.14)', 'card_fg' => '#FFFFFF', 'border' => 'rgba(255,235,59,.40)', 'heading' => 'inherit'],
        'janmashtami' => ['label' => 'Janmashtami', 'bg' => '#0D1B4C', 'bg2' => '#1E5AA8', 'fg' => '#F1F8FF', 'muted' => '#B3D4FF', 'accent' => '#FFD54F', 'card' => 'rgba(255,255,255,.10)', 'card_fg' => '#F1F8FF', 'border' => 'rgba(0,191,165,.45)', 'heading' => 'inherit'],
        'holi' => ['label' => 'Holi', 'bg' => '#4A148C', 'bg2' => '#00897B', 'fg' => '#FFFFFF', 'muted' => '#F8BBD0', 'accent' => '#FF4081', 'card' => 'rgba(255,255,255,.14)', 'card_fg' => '#FFFFFF', 'border' => 'rgba(255,235,59,.45)', 'heading' => 'inherit'],
        'christmas' => ['label' => 'Christmas', 'bg' => '#0B3D1F', 'bg2' => '#7F0000', 'fg' => '#FFFFFF', 'muted' => '#C8E6C9', 'accent' => '#FFD54F', 'card' => 'rgba(255,255,255,.10)', 'card_fg' => '#FFFFFF', 'border' => 'rgba(255,213,79,.40)', 'heading' => 'inherit'],
        'eid' => ['label' => 'Eid', 'bg' => '#003D33', 'bg2' => '#00695C', 'fg' => '#F1FFF8', 'muted' => '#B2DFDB', 'accent' => '#E6C200', 'card' => 'rgba(255,255,255,.10)', 'card_fg' => '#F1FFF8', 'border' => 'rgba(230,194,0,.40)', 'heading' => 'inherit'],
        'wedding' => ['label' => 'Wedding', 'bg' => '#3E0A1E', 'bg2' => '#7A1E3A', 'fg' => '#FFF5F7', 'muted' => '#F8C8D4', 'accent' => '#F3C66B', 'card' => 'rgba(255,245,247,.10)', 'card_fg' => '#FFF5F7', 'border' => 'rgba(243,198,107,.45)', 'heading' => 'Georgia,"Times New Roman",serif'],
        'corporate_blue' => ['label' => 'Corporate blue', 'bg' => '#0A2540', 'bg2' => '#12467A', 'fg' => '#FFFFFF', 'muted' => '#A9C4E2', 'accent' => '#00B4D8', 'card' => 'rgba(255,255,255,.08)', 'card_fg' => '#FFFFFF', 'border' => 'rgba(255,255,255,.18)', 'heading' => 'inherit'],
        'restaurant_warm' => ['label' => 'Restaurant warm', 'bg' => '#2B140A', 'bg2' => '#5A2A12', 'fg' => '#FFF4E6', 'muted' => '#E0B993', 'accent' => '#FF8F00', 'card' => 'rgba(255,244,230,.09)', 'card_fg' => '#FFF4E6', 'border' => 'rgba(255,143,0,.35)', 'heading' => 'inherit'],
    ];
    public const DEFAULT_THEME = 'classic_dark';

    /** Font choices → CSS font stack (the bundled Noto subsets are split by script via unicode-range). */
    public const FONTS = [
        'auto' => 'Automatic (by language)',
        'gujarati' => 'Gujarati (Noto Sans Gujarati)',
        'hindi' => 'Hindi (Noto Sans Devanagari)',
        'latin' => 'English (Noto Sans)',
        'serif' => 'Classic serif',
    ];
    private const FONT_STACKS = [
        'gujarati' => '"Noto Sans Gujarati","Noto Sans","Noto Sans Devanagari",Arial,sans-serif',
        'hindi' => '"Noto Sans Devanagari","Noto Sans","Noto Sans Gujarati",Arial,sans-serif',
        'latin' => '"Noto Sans","Noto Sans Gujarati","Noto Sans Devanagari",Arial,sans-serif',
        'serif' => 'Georgia,"Times New Roman","Noto Sans Gujarati","Noto Sans Devanagari",serif',
    ];
    public const LANGS = ['auto' => 'Default content', 'en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिन्दी'];

    /** @var array<string, DisplayApp>|null */
    private static ?array $apps = null;
    /** @var array<string, DisplayApp> apps added with register() (tests / modules outside core/Apps) */
    private static array $extra = [];

    // ------------------------------------------------------------------ registry

    /** @return array<string, DisplayApp> key => app, core/Apps file-name order, then register()ed ones. */
    public static function all(): array
    {
        if (self::$apps !== null) {
            return self::$apps;
        }
        $apps = [];
        $files = glob(HC_CORE . '/Apps/*App.php') ?: [];
        sort($files);
        foreach ($files as $f) {
            $class = basename($f, '.php');
            if (!class_exists($class, false)) {
                require_once $f;
            }
            if (!class_exists($class, false) || !is_subclass_of($class, DisplayApp::class) || !(new ReflectionClass($class))->isInstantiable()) {
                continue;
            }
            $app = new $class();
            self::add($apps, $app, $f);
        }
        foreach (self::$extra as $app) {
            self::add($apps, $app, get_class($app));
        }
        return self::$apps = $apps;
    }

    private static function add(array &$apps, DisplayApp $app, string $where): void
    {
        $key = $app->key();
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $key) || isset($apps[$key]) || !isset(DisplayApp::CATEGORIES[$app->category()])) {
            Logger::error('Display app skipped (invalid or duplicate key / category): ' . $where);
            return;
        }
        $apps[$key] = $app;
    }

    /** Add an app that does not live in core/Apps (tests, modules). */
    public static function register(DisplayApp $app): void
    {
        self::$extra[$app->key()] = $app;
        self::$apps = null;
    }

    /** Forget discovered / registered apps (tests). */
    public static function reset(bool $keepRegistered = false): void
    {
        self::$apps = null;
        if (!$keepRegistered) {
            self::$extra = [];
        }
    }

    public static function find(string $key): ?DisplayApp
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array<string, DisplayApp>> category => [key => app] (every category present, maybe empty). */
    public static function byCategory(): array
    {
        $out = array_fill_keys(array_keys(DisplayApp::CATEGORIES), []);
        foreach (self::all() as $k => $app) {
            $out[$app->category()][$k] = $app;
        }
        return $out;
    }

    // ------------------------------------------------------------------ item settings

    /** Settings JSON of an app item with every key present and valid. */
    public static function normalize(array $s): array
    {
        $theme = (string) ($s['theme'] ?? '');
        $font = (string) ($s['font'] ?? '');
        $lang = (string) ($s['lang'] ?? '');
        $accent = $s['accent'] ?? null;
        return [
            'app' => is_string($s['app'] ?? null) ? $s['app'] : '',
            'config' => is_array($s['config'] ?? null) ? $s['config'] : [],
            'theme' => isset(self::THEMES[$theme]) ? $theme : self::DEFAULT_THEME,
            'font' => isset(self::FONTS[$font]) ? $font : 'auto',
            'accent' => is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtoupper($accent) : null,
            'lang' => isset(self::LANGS[$lang]) ? $lang : 'auto',
        ];
    }

    /**
     * Validate the generic + app form input (app, theme, font, accent_on, accent, lang, cfg[...]).
     * @return array{0: array, 1: string[]} [settings, errors]
     */
    public static function validateItem(array $in): array
    {
        $errors = [];
        $key = is_string($in['app'] ?? null) ? $in['app'] : '';
        $app = self::find($key);
        if (!$app) {
            return [self::normalize([]), [__('Unknown display app.')]];
        }
        $cfgIn = is_array($in['cfg'] ?? null) ? $in['cfg'] : [];
        [$config, $appErrors] = $app->validate($cfgIn);
        $settings = self::normalize([
            'app' => $key,
            'config' => $config,
            'theme' => $in['theme'] ?? null,
            'font' => $in['font'] ?? null,
            'accent' => !empty($in['accent_on']) ? clean_color(is_string($in['accent'] ?? null) ? $in['accent'] : null, '#FFB300') : null,
            'lang' => $in['lang'] ?? null,
        ]);
        return [$settings, array_merge($errors, array_values(array_map('strval', $appErrors)))];
    }

    /** Language a page is rendered in (en / gu / hi). */
    public static function lang(array $settings): string
    {
        $l = $settings['lang'] ?? 'auto';
        if ($l === 'auto' || !isset(I18n::GUEST_LANGUAGES[$l])) {
            $l = (string) Settings::get('default_language', 'en');
        }
        return isset(I18n::GUEST_LANGUAGES[$l]) ? $l : 'en';
    }

    // ------------------------------------------------------------------ signed URLs

    public static function signature(int $hotelId, int $contentId): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY missing from .env');
        }
        return substr(hash_hmac('sha256', 'display:' . $hotelId . ':' . $contentId, $key), 0, 32);
    }

    public static function verify(int $hotelId, int $contentId, string $sig): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $sig) === 1 && hash_equals(self::signature($hotelId, $contentId), $sig);
    }

    /** Short revision of an item's look (title + settings + duration); changes the URL after edits. */
    public static function rev(array $item): string
    {
        $s = $item['settings'] ?? '';
        return substr(sha1((string) ($item['title'] ?? '') . '|' . (is_string($s) ? $s : json_out($s)) . '|' . (string) ($item['duration'] ?? '')), 0, 8);
    }

    private static function hotelOf(array $item): int
    {
        return isset($item['hotel_id']) ? (int) $item['hotel_id'] : Tenant::id();
    }

    /** Signed TV page URL of an app item. */
    public static function displayUrl(array $item, array $extra = []): string
    {
        $id = (int) $item['id'];
        return base_url('display/') . '?' . http_build_query(['c' => $id, 's' => self::signature(self::hotelOf($item), $id), 'v' => self::rev($item)] + $extra);
    }

    /** Signed live-data URL of an app item. */
    public static function dataUrl(array $item): string
    {
        $id = (int) $item['id'];
        return base_url('display/data.php') . '?' . http_build_query(['c' => $id, 's' => self::signature(self::hotelOf($item), $id)]);
    }

    /**
     * Content item for a signed request (?c=&s=), with the hotel context switched to its hotel.
     * Null for a bad signature, a missing / deleted item or an item that is not an app.
     */
    public static function itemFromRequest(array $q): ?array
    {
        $c = $q['c'] ?? '';
        $s = $q['s'] ?? '';
        if (!is_string($c) || !ctype_digit($c) || strlen($c) > 9 || (int) $c <= 0 || !is_string($s)) {
            return null;
        }
        // Lookup by id before the hotel is known; the signature then binds the row to its hotel.
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => (int) $c]);
        if (!$row || $row['type'] !== 'app' || !self::verify((int) $row['hotel_id'], (int) $c, strtolower($s))) {
            return null;
        }
        Tenant::set((int) $row['hotel_id']);
        return $row;
    }

    // ------------------------------------------------------------------ themes

    /** Resolved theme for settings: preset values with the accent override applied. */
    public static function theme(array $settings): array
    {
        $s = self::normalize($settings);
        $t = self::THEMES[$s['theme']] + ['key' => $s['theme']];
        if ($s['accent']) {
            $t['accent'] = $s['accent'];
        }
        $t['accent_fg'] = self::contrast($t['accent']);
        $font = $s['font'] === 'auto' ? match (self::lang($s)) { 'gu' => 'gujarati', 'hi' => 'hindi', default => 'latin' } : $s['font'];
        $t['font'] = self::FONT_STACKS[$font] ?? self::FONT_STACKS['latin'];
        $t['font_key'] = $font;
        return $t;
    }

    /** #000 or #FFF, whichever reads better on $hex. */
    public static function contrast(string $hex): string
    {
        $hex = clean_color($hex, '#000000');
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111111' : '#FFFFFF';
    }

    /** `:root{--hc-…}` for a resolved theme (values are constants or clean_color() output). */
    public static function themeCss(array $t): string
    {
        $vars = [
            'bg' => $t['bg'], 'bg2' => $t['bg2'], 'fg' => $t['fg'], 'muted' => $t['muted'], 'accent' => $t['accent'],
            'accent-fg' => $t['accent_fg'], 'card' => $t['card'], 'card-fg' => $t['card_fg'], 'border' => $t['border'],
            'font' => $t['font'], 'heading-font' => $t['heading'] === 'inherit' ? $t['font'] : $t['heading'],
        ];
        $css = ':root{';
        foreach ($vars as $k => $v) {
            $css .= '--hc-' . $k . ':' . str_replace(['<', '>', '{', '}', ';'], '', (string) $v) . ';';
        }
        return $css . '}';
    }

    // ------------------------------------------------------------------ rendering

    /** Context passed to render() / data(). */
    public static function context(array $item, array $settings, string $lang, bool $preview): array
    {
        $hid = Tenant::id();
        return [
            'hotel' => ['id' => $hid, 'name' => (string) Settings::get('hotel_name', ''), 'logo_url' => media_url((string) Settings::get('hotel_logo', ''))],
            'branding' => Branding::get($hid),
            'lang' => $lang,
            'preview' => $preview,
            'item' => ['id' => (int) ($item['id'] ?? 0), 'title' => (string) ($item['title'] ?? '')],
            'theme' => self::theme($settings),
            'now' => time(),
        ];
    }

    /** Run $fn with I18n switched to $lang (restored afterwards). */
    public static function inLang(string $lang, callable $fn): mixed
    {
        $prev = I18n::lang();
        I18n::setLang($lang);
        try {
            return $fn();
        } finally {
            I18n::setLang($prev);
        }
    }

    /**
     * Live data payload for data.php: ['ok', 'rev', 'server_now' (ms), 'refresh_sec', 'data'].
     */
    public static function payload(array $item): array
    {
        $s = self::normalize(ContentManager::settings($item));
        $app = self::find($s['app']);
        $lang = self::lang($s);
        return self::inLang($lang, static function () use ($app, $item, $s, $lang): array {
            $data = null;
            $refresh = 0;
            if ($app) {
                $config = $app->config($s['config']);
                $ctx = self::context($item, $s, $lang, false);
                $data = $app->data($config, $ctx);
                $refresh = max(0, $app->refreshSec($config));
            }
            return ['ok' => true, 'rev' => self::rev($item), 'server_now' => (int) round(microtime(true) * 1000), 'refresh_sec' => $refresh, 'data' => $data];
        });
    }

    /**
     * Complete HTML document of an app item for TV WebViews / previews. $item may be unsaved
     * (id 0: admin draft preview, no live refresh).
     */
    public static function renderPage(array $item, bool $preview = false): string
    {
        $s = self::normalize(ContentManager::settings($item));
        $lang = self::lang($s);
        return self::inLang($lang, static function () use ($item, $s, $lang, $preview): string {
            $app = self::find($s['app']);
            $theme = self::theme($s);
            $body = '';
            $data = null;
            $refresh = 0;
            if (!$app) {
                $body = self::message(__('This app is not available.'));
            } else {
                $config = $app->config($s['config']);
                $ctx = self::context($item, $s, $lang, $preview);
                try {
                    $body = $app->render($config, $ctx);
                    $data = $app->data($config, $ctx);
                    $refresh = max(0, $app->refreshSec($config));
                } catch (Throwable $e) {
                    Logger::error('Display app ' . $s['app'] . ' failed: ' . $e->getMessage(), ['item' => $item['id'] ?? 0]);
                    $body = self::message(__('This app is not available.'));
                }
            }
            $key = $app ? $app->key() : 'none';
            $saved = (int) ($item['id'] ?? 0) > 0;
            $boot = [
                'app' => $key,
                'item' => (int) ($item['id'] ?? 0),
                'rev' => self::rev($item),
                'lang' => $lang,
                'preview' => $preview,
                'refresh_sec' => $refresh,
                'data_url' => $saved ? self::dataUrl($item) : null,
                'server_now' => (int) round(microtime(true) * 1000),
                'tz_offset_min' => intdiv((int) date('Z'), 60),
                'data' => $data,
                'i18n' => [
                    'reconnecting' => __('Reconnecting…'),
                    'months' => array_map('__', ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']),
                    'days' => array_map('__', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']),
                    'am' => __('AM'), 'pm' => __('PM'),
                ],
            ];
            return self::document($key, (string) ($item['title'] ?? ''), $lang, $theme, $body, $boot, $preview);
        });
    }

    /** Neutral full page (bad link, suspended hotel …). */
    public static function neutralPage(string $text = ''): string
    {
        $theme = self::theme([]);
        return self::document('none', '', 'en', $theme, $text !== '' ? self::message($text) : '', null, false);
    }

    private static function message(string $text): string
    {
        return '<div class="hc-center"><div class="hc-message">' . e($text) . '</div></div>';
    }

    private static function document(string $key, string $title, string $lang, array $theme, string $body, ?array $boot, bool $preview): string
    {
        $css = '<link rel="stylesheet" href="' . e(asset('display/app.css')) . '">';
        $js = '';
        if ($boot !== null) {
            $js = '<script>window.HC_DISPLAY=' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';</script>'
                . '<script src="' . e(asset('display/app.js')) . '"></script>';
        }
        if ($key !== 'none') {
            if (is_file(HC_ROOT . '/assets/display/apps/' . $key . '.css')) {
                $css .= '<link rel="stylesheet" href="' . e(asset('display/apps/' . $key . '.css')) . '">';
            }
            if ($boot !== null && is_file(HC_ROOT . '/assets/display/apps/' . $key . '.js')) {
                $js .= '<script src="' . e(asset('display/apps/' . $key . '.js')) . '"></script>';
            }
        }
        if ($boot !== null) {
            $js .= '<script>if(window.HC&&HC.start){HC.start();}</script>';
        }
        $status = $boot !== null ? '<div id="hc-status" class="hc-status" style="display:none"><span class="hc-status-dot"></span> ' . e(__('Reconnecting…')) . '</div>' : '';
        return '<!DOCTYPE html><html lang="' . e($lang) . '" class="hc-display hc-lang-' . e($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . e($title !== '' ? $title : 'Display') . '</title>'
            . $css . '<style>' . self::themeCss($theme) . '</style></head>'
            . '<body class="hc-app hc-app-' . e($key) . ' hc-theme-' . e((string) $theme['key']) . ' hc-font-' . e((string) $theme['font_key']) . ($preview ? ' hc-preview' : '') . '">'
            . '<div id="hc-stage" class="hc-stage">' . $body . '</div>' . $status . $js . '</body></html>';
    }
}
