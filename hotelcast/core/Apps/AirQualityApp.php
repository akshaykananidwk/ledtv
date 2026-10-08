<?php
declare(strict_types=1);

/**
 * Air quality + weather alert (#26): AQI (Indian AQI estimated from 24 h PM2.5 / PM10, else US AQI,
 * clearly labelled), PM2.5, PM10, colour band and advice in en / gu / hi; optional banner with heavy rain /
 * heat / high wind / thunderstorm warnings and a "sea / ferry warning" line for coastal towns.
 * Data: Open-Meteo (DataFeeds providers open_meteo_aq + open_meteo_wx, no key) for the hotel's weather
 * location or the app's own coordinates. See core/AirQuality.php, docs/modules/widgets_26_30.md.
 */
final class AirQualityApp extends WidgetApp
{
    public function key(): string
    {
        return 'air_quality';
    }

    public function label(): string
    {
        return __('Air quality & weather alerts');
    }

    public function description(): string
    {
        return __('Air quality index with PM2.5 / PM10, colour band and health advice, plus heavy rain, heat, wind and sea / ferry warnings.');
    }

    public function icon(): string
    {
        return 'bi-wind';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Air quality'),
            'subtitle' => '',
            'lat' => '',
            'lon' => '',
            'show_advice' => true,
            'warnings' => true,
            'rain_mm' => 64,
            'heat_c' => 40,
            'wind_kmh' => 40,
            'coastal' => false,
            'ferry_kmh' => 35,
            'ferry_text' => '',
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        [$lat, $lon] = self::latLon($in, $errors);
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'lat' => $lat,
            'lon' => $lon,
            'show_advice' => self::bool($in, 'show_advice'),
            'warnings' => self::bool($in, 'warnings'),
            'rain_mm' => self::int($in, 'rain_mm', 5, 500, 64),
            'heat_c' => self::int($in, 'heat_c', 25, 55, 40),
            'wind_kmh' => self::int($in, 'wind_kmh', 10, 200, 40),
            'coastal' => self::bool($in, 'coastal'),
            'ferry_kmh' => self::int($in, 'ferry_kmh', 10, 200, 35),
            'ferry_text' => self::str($in, 'ferry_text', 240),
        ], $errors];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190], __('e.g. the town name'))
            . self::latLonFields($config)
            . self::checkbox('show_advice', __('Show health advice'), (bool) $config['show_advice'])
            . self::checkbox('warnings', __('Show weather warnings (rain, heat, wind)'), (bool) $config['warnings'])
            . self::input('rain_mm', __('Heavy rain from (mm / day)'), $config['rain_mm'], 'number', ['min' => 5, 'max' => 500], '', 'col-6 col-md-4')
            . self::input('heat_c', __('Heat warning from (°C)'), $config['heat_c'], 'number', ['min' => 25, 'max' => 55], '', 'col-6 col-md-4')
            . self::input('wind_kmh', __('High wind from (km/h)'), $config['wind_kmh'], 'number', ['min' => 10, 'max' => 200], '', 'col-6 col-md-4')
            . self::checkbox('coastal', __('Coastal town: show a sea / ferry warning'), (bool) $config['coastal'])
            . self::input('ferry_kmh', __('Sea / ferry warning from wind (km/h)'), $config['ferry_kmh'], 'number', ['min' => 10, 'max' => 200], __('Useful for Bet Dwarka boats or other ferries.'), 'col-6 col-md-6')
            . self::input('ferry_text', __('Sea / ferry warning text (optional)'), $config['ferry_text'], 'text', ['maxlength' => 240], __('Empty = standard text with the wind speed.'), 'col-12');
    }

    public function refreshSec(array $config): int
    {
        return 300;
    }

    protected function body(array $config, array $ctx): string
    {
        $loc = self::location($config);
        if ($loc === null) {
            return self::empty(__('Set the hotel location (Settings → Weather) or the coordinates of this screen.'));
        }
        $params = ['lat' => $loc[0], 'lon' => $loc[1]];
        $aq = DataFeeds::get('open_meteo_aq', $params, true);
        $fc = $config['warnings'] || $config['coastal'] ? DataFeeds::get('open_meteo_wx', $params, true) : null;
        $warn = $fc ? AirQuality::warnings($fc['data'], $config) : [];
        $banner = '';
        if ($warn) {
            // Several warnings: two columns, smaller text, and a smaller dial below (everything fits a 16:9 screen).
            $banner = '<div class="aq-warnings' . (count($warn) > 1 ? ' aq-warn-many' : '') . '">';
            foreach ($warn as $w) {
                $banner .= '<div class="aq-warn aq-warn-' . e($w['type']) . '"><span class="aq-warn-ico">' . match ($w['type']) { 'rain' => '🌧', 'heat' => '🌡', 'storm' => '⛈', 'ferry' => '⛴', default => '💨' } . '</span>' . e($w['text']) . '</div>';
            }
            $banner .= '</div>';
        }
        $idx = AirQuality::index($aq['data']);
        if ($idx['aqi'] === null) {
            return $banner . self::empty($aq['status'] === 'error' ? __('Air quality data is not available right now.') : __('Waiting for air quality data…'));
        }
        $scale = $idx['scale'] === 'in' ? __('Indian AQI (estimated from PM2.5 / PM10)') : __('US AQI (EPA scale)');
        $pm = static function (?float $v, string $label, string $key) use ($idx): string {
            return '<div class="aq-pm hc-card' . ($idx['main'] === $key ? ' aq-main' : '') . '"><div class="aq-pm-label">' . e($label) . '</div>'
                . '<div class="aq-pm-val">' . e($v === null ? '—' : (string) round($v)) . '</div><div class="aq-pm-unit">µg/m³</div></div>';
        };
        $bands = '';
        foreach ($idx['scale'] === 'in' ? AirQuality::BANDS : AirQuality::US_BANDS as $k => $b) {
            $bands .= '<div class="aq-band' . ($k === $idx['category'] ? ' is-on' : '') . '" style="background:' . e($idx['scale'] === 'in' ? $b[6] : $b[2]) . '"><span>' . e(AirQuality::label($k)) . '</span></div>';
        }
        $src = 'Open-Meteo' . ($idx['scale'] === 'in' ? ' · ' . __('CPCB categories') : '');
        return $banner
            . '<div class="aq-main-row' . ($warn ? ' aq-tight' : '') . (count($warn) > 2 ? ' aq-tighter' : '') . '">'
            . '<div class="aq-dial" style="background:' . e($idx['color']) . ';color:' . e($idx['fg']) . '"><div class="aq-num">' . (int) $idx['aqi'] . '</div><div class="aq-cat">' . e(AirQuality::label($idx['category'])) . '</div></div>'
            . '<div class="aq-side"><div class="aq-scale hc-muted">' . e($scale) . '</div>'
            . '<div class="aq-pms">' . $pm($idx['pm25'], 'PM2.5', 'pm25') . $pm($idx['pm10'], 'PM10', 'pm10') . '</div>'
            . ($config['show_advice'] ? '<div class="aq-advice">' . e(AirQuality::advice($idx['category'])) . '</div>' : '')
            . '</div></div>'
            . '<div class="aq-bands">' . $bands . '</div>'
            . self::foot($aq['as_of'], (bool) $aq['stale'], __('Model estimate'), $src);
    }
}
