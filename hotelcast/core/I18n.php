<?php
declare(strict_types=1);

/**
 * Translations. Keys are English strings; lang/<code>.php maps them. Module files
 * lang/<code>_<module>.php (e.g. lang/gu_platform.php, lang/hi_guests.php) are merged
 * automatically, so modules never need to edit the main language file.
 *
 *  - LANGUAGES: admin panel languages (English, Gujarati).
 *  - GUEST_LANGUAGES: guest-facing languages (TV / guest web app) — adds Hindi.
 */
final class I18n
{
    private static ?string $lang = null;
    private static array $strings = [];
    /** @var array<string, array<string, string>> loaded string tables per language */
    private static array $tables = [];

    public const LANGUAGES = ['en' => 'English', 'gu' => 'ગુજરાતી'];
    public const GUEST_LANGUAGES = ['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिन्दी'];

    public static function lang(): string
    {
        if (self::$lang === null) {
            $lang = $_SESSION['lang'] ?? null;
            if (!$lang && class_exists('Settings', false) && function_exists('hc_installed') && hc_installed()) {
                $lang = Settings::get('default_language', 'en');
            }
            self::setLang((string) ($lang ?: 'en'));
        }
        return self::$lang;
    }

    public static function setLang(string $lang): void
    {
        self::$lang = isset(self::GUEST_LANGUAGES[$lang]) ? $lang : 'en';
        self::$strings = self::table(self::$lang);
    }

    /** String table of a language: lang/<code>.php merged with lang/<code>_*.php. */
    public static function table(string $lang): array
    {
        if (!isset(self::GUEST_LANGUAGES[$lang]) || $lang === 'en') {
            return [];
        }
        if (!isset(self::$tables[$lang])) {
            $strings = [];
            $main = HC_ROOT . '/lang/' . $lang . '.php';
            if (is_file($main)) {
                $strings = (array) require $main;
            }
            $modules = glob(HC_ROOT . '/lang/' . $lang . '_*.php') ?: [];
            sort($modules);
            foreach ($modules as $f) {
                $strings = array_merge($strings, (array) require $f);
            }
            self::$tables[$lang] = $strings;
        }
        return self::$tables[$lang];
    }

    public static function t(string $key, array $replace = []): string
    {
        self::lang();
        return self::replace(self::$strings[$key] ?? $key, $replace);
    }

    /** Translate into a specific language (guest-facing text: en / gu / hi). */
    public static function translate(string $key, string $lang, array $replace = []): string
    {
        $table = self::table($lang);
        return self::replace($table[$key] ?? $key, $replace);
    }

    private static function replace(string $text, array $replace): string
    {
        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, (string) $v, $text);
        }
        return $text;
    }
}
