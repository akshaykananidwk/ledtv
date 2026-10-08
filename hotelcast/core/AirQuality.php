<?php
declare(strict_types=1);

/**
 * Air quality + weather alerts (#26). Data from Open-Meteo through DataFeeds (providers open_meteo_aq =
 * air-quality-api.open-meteo.com and open_meteo_wx = api.open-meteo.com, both key-less, fixed hosts).
 *
 *  - Indian AQI (CPCB National AQI) is ESTIMATED from the 24-hour average PM2.5 and PM10 of the model
 *    (the official index needs measured values of at least three pollutants). When fewer than 16 hourly
 *    values are available, the US AQI from Open-Meteo is shown instead, clearly labelled.
 *  - Weather warnings (heavy rain / heat / high wind / thunderstorm) from the daily forecast with
 *    hotel-configurable thresholds, plus an optional "sea / ferry warning" line for coastal towns
 *    (e.g. Bet Dwarka boats) when the wind exceeds a threshold.
 * Open-Meteo is free for non-commercial use; commercial use needs an Open-Meteo subscription
 * (THIRD_PARTY_NOTICES.md, docs/modules/widgets_26_30.md).
 */
final class AirQuality
{
    /**
     * CPCB National AQI categories: key => [AQI low, AQI high, PM2.5 low, high (µg/m³, 24 h), PM10 low, high, colour, text colour].
     */
    public const BANDS = [
        'good' => [0, 50, 0, 30, 0, 50, '#00B050', '#FFFFFF'],
        'satisfactory' => [51, 100, 31, 60, 51, 100, '#92D050', '#111111'],
        'moderate' => [101, 200, 61, 90, 101, 250, '#FFFF00', '#111111'],
        'poor' => [201, 300, 91, 120, 251, 350, '#FF9900', '#111111'],
        'very_poor' => [301, 400, 121, 250, 351, 430, '#FF0000', '#FFFFFF'],
        'severe' => [401, 500, 251, 500, 431, 1000, '#C00000', '#FFFFFF'],
    ];

    /** US EPA AQI categories (fallback index): key => [low, high, colour, text colour]. */
    public const US_BANDS = [
        'us_good' => [0, 50, '#00E400', '#111111'],
        'us_moderate' => [51, 100, '#FFFF00', '#111111'],
        'us_sensitive' => [101, 150, '#FF7E00', '#111111'],
        'us_unhealthy' => [151, 200, '#FF0000', '#FFFFFF'],
        'us_very_unhealthy' => [201, 300, '#8F3F97', '#FFFFFF'],
        'us_hazardous' => [301, 500, '#7E0023', '#FFFFFF'],
    ];

    /** Category labels and advice (English keys, translated with __() when rendered). */
    public static function label(string $cat): string
    {
        return match ($cat) {
            'good' => __('Good'),
            'satisfactory' => __('Satisfactory'),
            'moderate' => __('Moderate'),
            'poor' => __('Poor'),
            'very_poor' => __('Very poor'),
            'severe' => __('Severe'),
            'us_good' => __('Good'),
            'us_moderate' => __('Moderate'),
            'us_sensitive' => __('Unhealthy for sensitive groups'),
            'us_unhealthy' => __('Unhealthy'),
            'us_very_unhealthy' => __('Very unhealthy'),
            'us_hazardous' => __('Hazardous'),
            default => '—',
        };
    }

    public static function advice(string $cat): string
    {
        return match ($cat) {
            'good', 'us_good' => __('Air quality is good. Enjoy your time outdoors.'),
            'satisfactory', 'us_moderate' => __('Air quality is acceptable. Very sensitive people should limit long outdoor exertion.'),
            'moderate', 'us_sensitive' => __('People with asthma, heart or lung disease, children and elders should reduce long outdoor exertion.'),
            'poor', 'us_unhealthy' => __('Avoid long outdoor activity. Keep windows closed; sensitive people should stay indoors.'),
            'very_poor', 'us_very_unhealthy' => __('Stay indoors as much as possible. Wear an N95 mask outside.'),
            'severe', 'us_hazardous' => __('Health alert: avoid going outdoors. Wear an N95 mask if you must go out.'),
            default => '',
        };
    }

    /** Linear sub-index of a pollutant concentration on the CPCB scale (null when unknown). */
    public static function subIndex(?float $conc, string $pollutant): ?int
    {
        if ($conc === null || $conc < 0) {
            return null;
        }
        $c = round($conc);
        [$li, $hi] = $pollutant === 'pm25' ? [2, 3] : [4, 5];
        foreach (self::BANDS as $b) {
            if ($c <= $b[$hi]) {
                $lo = $b[$li];
                $aqi = $b[0] + ($c - $lo) * ($b[1] - $b[0]) / max(1, $b[$hi] - $lo);
                return (int) round(max($b[0], min($b[1], $aqi)));
            }
        }
        return 500;
    }

    /** CPCB category of an AQI value. */
    public static function category(int $aqi): string
    {
        foreach (self::BANDS as $k => $b) {
            if ($aqi <= $b[1]) {
                return $k;
            }
        }
        return 'severe';
    }

    public static function usCategory(int $aqi): string
    {
        foreach (self::US_BANDS as $k => $b) {
            if ($aqi <= $b[1]) {
                return $k;
            }
        }
        return 'us_hazardous';
    }

    /**
     * Index shown on the TV from stored feed data:
     * ['scale' => 'in'|'us'|null, 'aqi', 'category', 'color', 'fg', 'pm25', 'pm10' (current µg/m³), 'main' => pm25|pm10|null].
     */
    public static function index(?array $d): array
    {
        $out = ['scale' => null, 'aqi' => null, 'category' => '', 'color' => '#607D8B', 'fg' => '#FFFFFF', 'pm25' => null, 'pm10' => null, 'main' => null];
        if (!$d) {
            return $out;
        }
        $out['pm25'] = is_numeric($d['pm25'] ?? null) ? (float) $d['pm25'] : null;
        $out['pm10'] = is_numeric($d['pm10'] ?? null) ? (float) $d['pm10'] : null;
        $s25 = is_numeric($d['pm25_24h'] ?? null) ? self::subIndex((float) $d['pm25_24h'], 'pm25') : null;
        $s10 = is_numeric($d['pm10_24h'] ?? null) ? self::subIndex((float) $d['pm10_24h'], 'pm10') : null;
        if ($s25 !== null || $s10 !== null) {
            $aqi = max((int) $s25, (int) $s10);
            $cat = self::category($aqi);
            return ['scale' => 'in', 'aqi' => $aqi, 'category' => $cat, 'color' => self::BANDS[$cat][6], 'fg' => self::BANDS[$cat][7],
                'main' => (int) $s25 >= (int) $s10 ? 'pm25' : 'pm10'] + $out;
        }
        if (is_numeric($d['us_aqi'] ?? null)) {
            $aqi = (int) round((float) $d['us_aqi']);
            $cat = self::usCategory($aqi);
            return ['scale' => 'us', 'aqi' => $aqi, 'category' => $cat, 'color' => self::US_BANDS[$cat][2], 'fg' => self::US_BANDS[$cat][3]] + $out;
        }
        return $out;
    }

    // ------------------------------------------------------------------ provider parsers (called by DataFeeds::parse)

    private static function err(int $status, array $d): never
    {
        $m = is_scalar($d['reason'] ?? null) && trim((string) $d['reason']) !== '' ? mb_substr(trim(strip_tags((string) $d['reason'])), 0, 200) : 'Error (HTTP ' . $status . ')';
        throw new DataFeedError($m, $status === 429 || (bool) preg_match('/limit|quota|too many|exceed/i', $m));
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? round((float) $v, 2) : null;
    }

    /**
     * Open-Meteo air quality: {"current":{"time","pm10","pm2_5","us_aqi","european_aqi"},
     * "hourly":{"time":[…],"pm10":[…],"pm2_5":[…]}} (unix times) / {"error":true,"reason"}.
     */
    public static function parseAq(array $r): array
    {
        [$status, $d] = $r;
        if ($status !== 200 || !empty($d['error']) || !is_array($d['current'] ?? null)) {
            self::err($status, $d);
        }
        $c = $d['current'];
        $now = is_numeric($c['time'] ?? null) ? (int) $c['time'] : time();
        $avg = static function (string $k) use ($d, $now): ?float {
            $times = (array) ($d['hourly']['time'] ?? []);
            $vals = (array) ($d['hourly'][$k] ?? []);
            $sum = 0.0;
            $n = 0;
            foreach ($times as $i => $t) {
                if (is_numeric($t) && (int) $t <= $now && (int) $t > $now - 86400 && is_numeric($vals[$i] ?? null)) {
                    $sum += (float) $vals[$i];
                    $n++;
                }
            }
            return $n >= 16 ? round($sum / $n, 1) : null;
        };
        $out = [
            'pm25' => self::num($c['pm2_5'] ?? null), 'pm10' => self::num($c['pm10'] ?? null),
            'us_aqi' => self::num($c['us_aqi'] ?? null), 'european_aqi' => self::num($c['european_aqi'] ?? null),
            'pm25_24h' => $avg('pm2_5'), 'pm10_24h' => $avg('pm10'), 'source_time' => $now,
        ];
        if ($out['pm25'] === null && $out['pm10'] === null && $out['us_aqi'] === null) {
            throw new DataFeedError('No air quality values in the response');
        }
        return $out;
    }

    /**
     * Open-Meteo forecast: {"current":{"time","temperature_2m","wind_speed_10m","wind_gusts_10m","weather_code"},
     * "daily":{"time":[…],"temperature_2m_max":[…],"precipitation_sum":[…],"wind_speed_10m_max":[…],
     * "wind_gusts_10m_max":[…],"weather_code":[…]}} → current + up to 2 days.
     */
    public static function parseForecast(array $r): array
    {
        [$status, $d] = $r;
        if ($status !== 200 || !empty($d['error']) || !is_array($d['daily'] ?? null)) {
            self::err($status, $d);
        }
        $c = is_array($d['current'] ?? null) ? $d['current'] : [];
        $days = [];
        foreach (array_slice((array) ($d['daily']['time'] ?? []), 0, 2) as $i => $t) {
            $v = static fn (string $k) => self::num($d['daily'][$k][$i] ?? null);
            $days[] = ['time' => is_numeric($t) ? (int) $t : null, 'temp_max' => $v('temperature_2m_max'), 'rain_mm' => $v('precipitation_sum'),
                'wind_max' => $v('wind_speed_10m_max'), 'gust_max' => $v('wind_gusts_10m_max'), 'code' => is_numeric($d['daily']['weather_code'][$i] ?? null) ? (int) $d['daily']['weather_code'][$i] : null];
        }
        if (!$days) {
            throw new DataFeedError('No forecast in the response');
        }
        return [
            'temp' => self::num($c['temperature_2m'] ?? null), 'wind' => self::num($c['wind_speed_10m'] ?? null),
            'gust' => self::num($c['wind_gusts_10m'] ?? null), 'code' => is_numeric($c['weather_code'] ?? null) ? (int) $c['weather_code'] : null,
            'days' => $days, 'source_time' => is_numeric($c['time'] ?? null) ? (int) $c['time'] : time(),
        ];
    }

    // ------------------------------------------------------------------ warnings

    /**
     * Warning lines from forecast data and thresholds ($cfg: rain_mm, heat_c, wind_kmh, coastal, ferry_kmh,
     * ferry_text). Returns [['type' => rain|heat|wind|storm|ferry, 'text' => translated line]].
     */
    public static function warnings(?array $fc, array $cfg): array
    {
        if (!$fc || !is_array($fc['days'] ?? null)) {
            return [];
        }
        $out = [];
        $when = static fn (int $i): string => $i === 0 ? __('today') : __('tomorrow');
        $maxWind = (float) ($fc['wind'] ?? 0);
        foreach ($fc['days'] as $i => $day) {
            if (!is_array($day)) {
                continue;
            }
            if ($day['rain_mm'] !== null && $day['rain_mm'] >= (float) $cfg['rain_mm'] && !isset($out['rain'])) {
                $out['rain'] = ['type' => 'rain', 'text' => __('Heavy rain expected :when (:n mm). Carry an umbrella and avoid low-lying roads.', ['when' => $when($i), 'n' => (string) round((float) $day['rain_mm'])])];
            }
            if ($day['temp_max'] !== null && $day['temp_max'] >= (float) $cfg['heat_c'] && !isset($out['heat'])) {
                $out['heat'] = ['type' => 'heat', 'text' => __('Heat warning :when: up to :n °C. Drink plenty of water and avoid the midday sun.', ['when' => $when($i), 'n' => (string) round((float) $day['temp_max'])])];
            }
            $w = max((float) ($day['wind_max'] ?? 0), 0.0);
            if ($i === 0) {
                $maxWind = max($maxWind, $w);
            }
            if ($w >= (float) $cfg['wind_kmh'] && !isset($out['wind'])) {
                $out['wind'] = ['type' => 'wind', 'text' => __('High wind :when: up to :n km/h.', ['when' => $when($i), 'n' => (string) round($w)])];
            }
            if (in_array((int) ($day['code'] ?? 0), [95, 96, 99], true) && !isset($out['storm'])) {
                $out['storm'] = ['type' => 'storm', 'text' => __('Thunderstorm expected :when. Stay indoors during lightning.', ['when' => $when($i)])];
            }
        }
        if (!empty($cfg['coastal']) && $maxWind >= (float) $cfg['ferry_kmh']) {
            $txt = trim((string) ($cfg['ferry_text'] ?? ''));
            $out['ferry'] = ['type' => 'ferry', 'text' => ($txt !== '' ? $txt : __('Sea warning: strong wind (:n km/h). Boat / ferry services may be suspended — check at the jetty before you go.', ['n' => (string) round($maxWind)]))];
        }
        return array_values($out);
    }
}
