<?php
declare(strict_types=1);

/** English / Gujarati translations. Keys are English strings; lang/gu.php maps them. */
final class I18n
{
    private static ?string $lang = null;
    private static array $strings = [];

    public const LANGUAGES = ['en' => 'English', 'gu' => 'ગુજરાતી'];

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
        self::$lang = isset(self::LANGUAGES[$lang]) ? $lang : 'en';
        $file = HC_ROOT . '/lang/' . self::$lang . '.php';
        self::$strings = (self::$lang !== 'en' && is_file($file)) ? (array) require $file : [];
    }

    public static function t(string $key, array $replace = []): string
    {
        self::lang();
        $text = self::$strings[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, (string) $v, $text);
        }
        return $text;
    }
}
