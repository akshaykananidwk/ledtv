<?php
declare(strict_types=1);

/**
 * Display app (2.3): a server-rendered TV page (menu board, notice board, countdown, QR …).
 *
 * One class per app in core/Apps/<Name>App.php (class name = file name, extends DisplayApp); the
 * registry (DisplayApps::all()) discovers them like core/Tasks. An app content item is a normal
 * content_items row (type 'app', settings {"app","config","theme","font","accent","lang"}); the TV
 * gets {"type":"url","url":<signed /display/ URL>} and shows the page in its WebView.
 *
 *   key()          unique id, [a-z0-9_]{2,40}; also the name of the optional assets
 *                  assets/display/apps/<key>.css and assets/display/apps/<key>.js (auto-included)
 *   label() / description() / icon() / category()   gallery card (translated with __())
 *   defaults()     full default config; stored config is merged over it (new keys get defaults)
 *   validate($in)  raw form input (the cfg[...] fields) → [normalised config, list of error strings]
 *   form($config)  HTML of the app's own admin fields; name them cfg[...] (helpers below)
 *   render($config, $ctx)  HTML body of the TV page (escape everything with e())
 *   data($config, $ctx)    JSON-able array for live refresh (assets/display/app.js → HCApp.update),
 *                  null = static page
 *   refreshSec($config)    seconds between data refreshes (0 = no live refresh)
 *   adminPage()    URL of the app's own management page (e.g. admin/notices.php) or null
 *
 * $ctx = ['hotel' => [id, name, logo_url], 'branding' => Branding::get(), 'lang' => en|gu|hi,
 *         'preview' => bool, 'item' => [id, title], 'theme' => resolved theme, 'now' => unix time].
 * While render() / data() / form() run, I18n is switched to the item's language for render/data,
 * so __() returns TV texts in that language. See docs/modules/display_apps.md.
 */
abstract class DisplayApp
{
    public const CATEGORIES = ['business' => 'Business', 'content' => 'Content', 'widget' => 'Widgets'];

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** Bootstrap icon class, e.g. 'bi-qr-code'. */
    public function icon(): string
    {
        return 'bi-app';
    }

    /** 'business' | 'content' | 'widget' */
    public function category(): string
    {
        return 'content';
    }

    abstract public function defaults(): array;

    /** @return array{0: array, 1: string[]} */
    abstract public function validate(array $in): array;

    abstract public function form(array $config): string;

    abstract public function render(array $config, array $ctx): string;

    public function data(array $config, array $ctx): ?array
    {
        return null;
    }

    public function refreshSec(array $config): int
    {
        return 0;
    }

    public function adminPage(): ?string
    {
        return null;
    }

    /** Stored config merged over the defaults (keys unknown to defaults() are dropped). */
    public function config(array $stored): array
    {
        $d = $this->defaults();
        return array_replace($d, array_intersect_key($stored, $d));
    }

    // ------------------------------------------------------------------ validation helpers

    /** Trimmed single-line string, cut to $max characters. */
    protected static function str(array $in, string $key, int $max = 190, string $default = ''): string
    {
        $v = $in[$key] ?? $default;
        if (!is_scalar($v)) {
            return $default;
        }
        $v = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v) ?? '');
        return mb_substr($v, 0, $max);
    }

    /** Trimmed multi-line text (\r\n → \n), cut to $max characters. */
    protected static function text(array $in, string $key, int $max = 5000): string
    {
        $v = $in[$key] ?? '';
        return is_scalar($v) ? mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max) : '';
    }

    protected static function int(array $in, string $key, int $min, int $max, int $default): int
    {
        $v = $in[$key] ?? null;
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    protected static function bool(array $in, string $key): bool
    {
        return !empty($in[$key]) && $in[$key] !== '0';
    }

    /** One of $allowed (list or keys of a map), else $default. */
    protected static function choice(array $in, string $key, array $allowed, string $default): string
    {
        $list = array_is_list($allowed) ? $allowed : array_keys($allowed);
        $v = $in[$key] ?? '';
        return is_string($v) && in_array($v, $list, true) ? $v : $default;
    }

    /** Subset of $allowed (keeps the order of $allowed). */
    protected static function multi(array $in, string $key, array $allowed): array
    {
        $list = array_is_list($allowed) ? $allowed : array_keys($allowed);
        $v = array_map('strval', array_filter((array) ($in[$key] ?? []), 'is_scalar'));
        return array_values(array_filter($list, static fn ($a) => in_array((string) $a, $v, true)));
    }

    protected static function color(array $in, string $key, string $default): string
    {
        return clean_color(is_string($in[$key] ?? null) ? $in[$key] : null, $default);
    }

    /** http(s) URL or '' (adds an error when set but invalid). */
    protected static function url(array $in, string $key, array &$errors, string $label): string
    {
        $v = self::str($in, $key, 1000);
        if ($v !== '' && !ContentManager::validUrl($v, ['http', 'https'])) {
            $errors[] = __(':f: enter a valid http(s) address.', ['f' => $label]);
            return '';
        }
        return $v;
    }

    /** 'Y-m-d H:i:s' from a datetime-local / date input, null when empty or invalid. */
    protected static function datetime(array $in, string $key): ?string
    {
        $v = self::str($in, $key, 40);
        if ($v === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $v));
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ admin form helpers (Bootstrap 5)

    /** name="cfg[key]" (or cfg[key][] for $multi). */
    protected static function name(string $key, bool $multi = false): string
    {
        return 'cfg[' . $key . ']' . ($multi ? '[]' : '');
    }

    protected static function fid(string $key): string
    {
        return 'cfg_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
    }

    /** Text-like input. $type: text | number | url | datetime-local | date | time | tel. */
    protected static function input(string $key, string $label, mixed $value, string $type = 'text', array $attrs = [], string $help = '', string $col = 'col-md-6'): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
        }
        return '<div class="' . e($col) . '"><label class="form-label" for="' . e(self::fid($key)) . '">' . e($label) . '</label>'
            . '<input class="form-control" type="' . e($type) . '" id="' . e(self::fid($key)) . '" name="' . e(self::name($key)) . '" value="' . e((string) $value) . '"' . $a . '>'
            . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
    }

    protected static function textarea(string $key, string $label, string $value, int $rows = 3, string $help = '', string $col = 'col-12', int $max = 5000): string
    {
        return '<div class="' . e($col) . '"><label class="form-label" for="' . e(self::fid($key)) . '">' . e($label) . '</label>'
            . '<textarea class="form-control" id="' . e(self::fid($key)) . '" name="' . e(self::name($key)) . '" rows="' . $rows . '" maxlength="' . $max . '">' . e($value) . '</textarea>'
            . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
    }

    /** <select>; $options = value => label (labels already translated). */
    protected static function select(string $key, string $label, array $options, string $value, string $help = '', string $col = 'col-md-6', array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
        }
        $h = '<div class="' . e($col) . '"><label class="form-label" for="' . e(self::fid($key)) . '">' . e($label) . '</label>'
            . '<select class="form-select" id="' . e(self::fid($key)) . '" name="' . e(self::name($key)) . '"' . $a . '>';
        foreach ($options as $v => $l) {
            $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        return $h . '</select>' . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
    }

    protected static function checkbox(string $key, string $label, bool $checked, string $col = 'col-md-6'): string
    {
        return '<div class="' . e($col) . '"><div class="form-check form-switch mt-md-4">'
            . '<input type="hidden" name="' . e(self::name($key)) . '" value="0">'
            . '<input class="form-check-input" type="checkbox" role="switch" id="' . e(self::fid($key)) . '" name="' . e(self::name($key)) . '" value="1"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . e(self::fid($key)) . '">' . e($label) . '</label></div></div>';
    }

    /** Checkbox group posting cfg[key][]; $options = value => label. */
    protected static function checkboxes(string $key, string $label, array $options, array $checked, string $col = 'col-12'): string
    {
        $h = '<div class="' . e($col) . '"><div class="form-label">' . e($label) . '</div><input type="hidden" name="' . e(self::name($key, true)) . '" value="">';
        foreach ($options as $v => $l) {
            $id = self::fid($key . '_' . $v);
            $h .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="' . e($id) . '" name="' . e(self::name($key, true)) . '" value="' . e((string) $v) . '"'
                . (in_array((string) $v, array_map('strval', $checked), true) ? ' checked' : '') . '><label class="form-check-label" for="' . e($id) . '">' . e($l) . '</label></div>';
        }
        return $h . '</div>';
    }

    protected static function colorInput(string $key, string $label, string $value, string $col = 'col-6 col-md-3'): string
    {
        return '<div class="' . e($col) . '"><label class="form-label" for="' . e(self::fid($key)) . '">' . e($label) . '</label>'
            . '<input type="color" class="form-control form-control-color w-100" id="' . e(self::fid($key)) . '" name="' . e(self::name($key)) . '" value="' . e($value) . '"></div>';
    }

    /** Select of the hotel's active image content items (value = content id, 0 = none). */
    protected static function imagePicker(string $key, string $label, int $value, string $help = '', string $col = 'col-md-6'): string
    {
        $opts = [0 => __('None')];
        foreach (DB::all("SELECT id, title FROM content_items WHERE hotel_id = :h AND type = 'image' ORDER BY title", ['h' => Tenant::id()]) as $r) {
            $opts[(int) $r['id']] = (string) $r['title'];
        }
        return self::select($key, $label, $opts, (string) $value, $help, $col);
    }

    /** URL of an image content item of the current hotel (null when missing / not an image). */
    protected static function imageUrl(int $contentId): ?string
    {
        if ($contentId <= 0) {
            return null;
        }
        $row = ContentManager::findOwn($contentId);
        if (!$row || $row['type'] !== 'image' || !ContentRules::approved($row)) { // 2.4: never content waiting for approval
            return null;
        }
        return $row['file_path'] ? media_url((string) $row['file_path']) : ((string) $row['url'] ?: null);
    }
}
