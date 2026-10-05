<?php
declare(strict_types=1);

/** Current weather via Open-Meteo (free, no API key). Cached 30 minutes. */
final class Weather
{
    private const CODES = [
        0 => ['Clear', '☀'], 1 => ['Mainly clear', '🌤'], 2 => ['Partly cloudy', '⛅'], 3 => ['Cloudy', '☁'],
        45 => ['Fog', '🌫'], 48 => ['Fog', '🌫'], 51 => ['Drizzle', '🌦'], 53 => ['Drizzle', '🌦'], 55 => ['Drizzle', '🌦'],
        61 => ['Rain', '🌧'], 63 => ['Rain', '🌧'], 65 => ['Heavy rain', '🌧'], 66 => ['Freezing rain', '🌧'], 67 => ['Freezing rain', '🌧'],
        71 => ['Snow', '🌨'], 73 => ['Snow', '🌨'], 75 => ['Snow', '🌨'], 80 => ['Showers', '🌦'], 81 => ['Showers', '🌦'],
        82 => ['Heavy showers', '⛈'], 95 => ['Thunderstorm', '⛈'], 96 => ['Thunderstorm', '⛈'], 99 => ['Thunderstorm', '⛈'],
    ];

    public static function current(): ?array
    {
        $lat = (float) Settings::get('weather_lat', '0');
        $lon = (float) Settings::get('weather_lon', '0');
        if (!$lat && !$lon) {
            return null;
        }
        $key = round($lat, 3) . ',' . round($lon, 3);
        $cached = Cache::get('weather', $key, 1800);
        if (is_array($cached)) {
            return $cached;
        }
        if (Cache::get('weather', 'fail:' . $key, 300) !== null) {
            $stale = Cache::get('weather', $key, 86400 * 7);
            return is_array($stale) ? $stale : null;
        }
        $url = sprintf('https://api.open-meteo.com/v1/forecast?latitude=%F&longitude=%F&current_weather=true', $lat, $lon);
        $res = Http::get($url, [], 5);
        $data = $res['status'] === 200 ? json_decode($res['body'], true) : null;
        $cw = $data['current_weather'] ?? null;
        if (!$cw) {
            // Cache the failure briefly to avoid hammering on every poll; fall back to last good value.
            Cache::set('weather', 'fail:' . $key, 1);
            $stale = Cache::get('weather', $key, 86400 * 7);
            return is_array($stale) ? $stale : null;
        }
        [$cond, $icon] = self::CODES[(int) ($cw['weathercode'] ?? 0)] ?? ['', ''];
        $w = ['temp_c' => (int) round((float) $cw['temperature']), 'condition' => $cond, 'icon' => $icon];
        Cache::set('weather', $key, $w);
        return $w;
    }
}
