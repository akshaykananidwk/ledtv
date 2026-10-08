<?php
declare(strict_types=1);

/**
 * Base of the display apps #26–#30 (AirQualityApp, PanchangApp, FestivalsApp, CelebrationsApp,
 * ReviewsApp): the DataFeedApp page (header + server-rendered #dfBody, swapped on live refresh by the
 * app's own assets/display/apps/<key>.js) plus helpers for location, dates and times in the TV language.
 * See docs/modules/widgets_26_30.md.
 */
abstract class WidgetApp extends DataFeedApp
{
    /**
     * [lat, lon] of the screen: the app's own fields when both are set, else the hotel's weather location
     * (Settings → weather_lat / weather_lon); null when unknown.
     */
    public static function location(array $config): ?array
    {
        $lat = trim((string) ($config['lat'] ?? ''));
        $lon = trim((string) ($config['lon'] ?? ''));
        if ($lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
            $lat = (string) Settings::get('weather_lat', '');
            $lon = (string) Settings::get('weather_lon', '');
        }
        if (!is_numeric($lat) || !is_numeric($lon) || ((float) $lat === 0.0 && (float) $lon === 0.0) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            return null;
        }
        return [(float) $lat, (float) $lon];
    }

    /** Validate optional lat / lon fields (both empty = hotel location). */
    protected static function latLon(array $in, array &$errors): array
    {
        $lat = self::str($in, 'lat', 20);
        $lon = self::str($in, 'lon', 20);
        if ($lat === '' && $lon === '') {
            return ['', ''];
        }
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            $errors[] = __('Enter a valid latitude (−90 … 90) and longitude (−180 … 180), or leave both empty.');
            return ['', ''];
        }
        return [(string) round((float) $lat, 4), (string) round((float) $lon, 4)];
    }

    protected static function latLonFields(array $config): string
    {
        $city = (string) Settings::get('weather_city', '');
        $help = __('Leave empty to use the hotel location from Settings → Weather:') . ' ' . ($city !== '' ? $city : '—');
        return self::input('lat', __('Latitude'), (string) $config['lat'], 'text', ['inputmode' => 'decimal', 'maxlength' => 20, 'placeholder' => (string) Settings::get('weather_lat', '')], $help)
            . self::input('lon', __('Longitude'), (string) $config['lon'], 'text', ['inputmode' => 'decimal', 'maxlength' => 20, 'placeholder' => (string) Settings::get('weather_lon', '')]);
    }

    /** "6:05 PM" / "18:05" in the current language (hotel time zone). */
    public static function timeLabel(int $ts, bool $h24 = false): string
    {
        return $h24 ? date('H:i', $ts) : date('g:i', $ts) . ' ' . __(date('A', $ts));
    }

    /** "Thursday, 8 October" from 'Y-m-d' in the current language ($year adds the year). */
    public static function dateLabel(string $ymd, bool $weekday = true, bool $year = false): string
    {
        $ts = (int) strtotime($ymd . ' 12:00:00');
        return ($weekday ? __(date('l', $ts)) . ', ' : '') . date('j', $ts) . ' ' . __(date('F', $ts)) . ($year ? ' ' . date('Y', $ts) : '');
    }

    /** Local date of the screen ('Y-m-d', hotel time zone). */
    public static function today(array $ctx): string
    {
        return date('Y-m-d', (int) ($ctx['now'] ?? time()));
    }

    /** Short initials of a name for a photo placeholder ("Asha Patel" → "AP"). */
    public static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if (preg_match('/^\p{L}/u', $w)) { // skips "&", "-", numbers ("Amit & Neha Shah" → AN)
                $out .= mb_strtoupper(mb_substr($w, 0, 1));
            }
            if (mb_strlen($out) >= 2) {
                break;
            }
        }
        return $out !== '' ? $out : '•';
    }

    /** CSS url('…') value for an inline style (quotes / brackets encoded). */
    protected static function cssUrl(string $url): string
    {
        return "url('" . e(str_replace(["'", '\\', "\n", "\r", '(', ')'], ['%27', '%5C', '', '', '%28', '%29'], $url)) . "')";
    }
}
