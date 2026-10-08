<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Display apps #26–#30 (docs/modules/widgets_26_30.md): air quality + weather alerts, panchang +
 * choghadiya, festival calendar, birthday / anniversary wall, Google reviews.
 * Pure astronomy with fixed times (choghadiya order, tithi / nakshatra / month within tolerance), Open-Meteo
 * and Google Places providers through Http mocks only (parse, errors, stale, key never leaked), the two
 * management pages (CRUD, starter list, CSV import, consent, CSRF, permissions, tenancy, XSS) and every app
 * over HTTP in en / gu / hi without PHP warnings.
 */
final class WidgetsTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    /** @var array<string, int> */
    private static array $id = [];
    /** @var array<int, string> */
    private static array $calls = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const KEY = 'GOOGLE-SECRET-KEY-123abcXYZ';
    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    // Dwarka (the default weather location)
    private const LAT = 22.2394;
    private const LON = 68.9678;
    private const TZ = 'Asia/Kolkata';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'wMgr', 'staff' => 'wStaff', 'reception' => 'wRecep', 'super_admin' => 'wBoss'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'wBoss2', 'email' => 'w2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            $today = date('Y-m-d');
            self::$id['h2fest'] = DB::insert('festivals', ['name_en' => 'H2-FEST-SECRET', 'starts_on' => $today, 'created_at' => now()]);
            self::$id['h2cel'] = DB::insert('celebrations', ['name' => 'H2-CEL-SECRET', 'month' => (int) date('n'), 'day' => (int) date('j'), 'consent' => 1, 'created_at' => now()]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        DisplayApps::all();
        Cache::clear();
        self::$mockFile = TestEnv::$sandbox . '/storage/widgets_mock.json';
        file_put_contents(self::$mockFile, json_encode([
            'https://' => ['status' => 503, 'body' => '{}'],
            'http://' => ['status' => 503, 'body' => '{}'],
            'https://air-quality-api.open-meteo.com/v1/air-quality' => ['status' => 200, 'body' => json_encode(self::aqJson(45, 80))],
            'https://api.open-meteo.com/v1/forecast' => ['status' => 200, 'body' => json_encode(self::fcJson(2, 33, 20))],
        ]));
        Settings::setPlatform('task_last_DataFeedsTask', (string) (time() + 86400));
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url, ['http_mock_file' => self::$mockFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Http::$mock = null;
        DataFeeds::flush();
        DisplayApps::reset();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        DataFeeds::flush();
        DB::query('DELETE FROM rate_limits');
        self::$calls = [];
        Http::$mock = static function (string $m, string $url): ?array {
            self::$calls[] = $url;
            return null;
        };
    }

    protected function tearDown(): void
    {
        Http::$mock = null;
    }

    // ------------------------------------------------------------------ helpers

    private static function mock(array $map): void
    {
        Http::$mock = static function (string $m, string $url, array $headers) use ($map): ?array {
            self::$calls[] = $url;
            $best = null;
            $len = -1;
            foreach ($map as $prefix => $resp) {
                if (str_starts_with($url, $prefix) && strlen($prefix) > $len) {
                    [$best, $len] = [$resp, strlen($prefix)];
                }
            }
            return $best === null ? null : ['status' => $best[0], 'body' => is_string($best[1]) ? $best[1] : json_encode($best[1])];
        };
    }

    private static function seed(string $p, array $params, array $data, int $owner = 0, ?int $fetched = null, int $errors = 0): int
    {
        $params = DataFeeds::cleanParams($p, $params);
        $fk = DataFeeds::feedKey($p, $params, $owner);
        DB::query('DELETE FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
        $t = $fetched ?? time();
        return DB::insert('data_feeds', ['feed_key' => $fk, 'provider' => $p, 'params' => json_encode($params), 'key_hotel_id' => $owner,
            'data' => json_encode($data), 'fetched_at' => $t, 'attempted_at' => $t, 'error_count' => $errors, 'error' => $errors ? 'Error (HTTP 500)' : null,
            'demand_at' => time(), 'created_at' => now()]);
    }

    /** Open-Meteo air quality answer: 24 hourly values (constant) up to now. */
    private static function aqJson(float $pm25, float $pm10, bool $hourly = true): array
    {
        $now = time() - time() % 3600;
        $times = [];
        for ($i = 23; $i >= 0; $i--) {
            $times[] = $now - $i * 3600;
        }
        $times[] = $now + 3600; // future value: ignored
        $j = ['latitude' => 22.25, 'longitude' => 69.0, 'current' => ['time' => $now, 'interval' => 3600, 'pm10' => $pm10 + 3, 'pm2_5' => $pm25 + 2, 'us_aqi' => 120, 'european_aqi' => 45]];
        if ($hourly) {
            $j['hourly'] = ['time' => $times, 'pm10' => array_fill(0, 25, $pm10), 'pm2_5' => array_merge(array_fill(0, 24, $pm25), [999])];
        }
        return $j;
    }

    private static function fcJson(float $rain, float $tmax, float $wind, int $code = 3): array
    {
        $d = strtotime('today');
        return ['current' => ['time' => time(), 'temperature_2m' => 31.5, 'wind_speed_10m' => $wind - 5, 'wind_gusts_10m' => $wind + 10, 'weather_code' => $code],
            'daily' => ['time' => [$d, $d + 86400], 'temperature_2m_max' => [$tmax, $tmax - 1], 'precipitation_sum' => [$rain, 0], 'wind_speed_10m_max' => [$wind, $wind - 10],
                'wind_gusts_10m_max' => [$wind + 15, $wind], 'weather_code' => [$code, 1]]];
    }

    private static function placesJson(): array
    {
        return ['html_attributions' => [], 'status' => 'OK', 'result' => ['name' => 'Hotel Dwarka', 'rating' => 4.4, 'user_ratings_total' => 1234, 'reviews' => [
            ['author_name' => 'Meera S', 'rating' => 5, 'text' => 'Wonderful stay near the temple', 'time' => 1790000000, 'relative_time_description' => 'a month ago', 'language' => 'en'],
            ['author_name' => 'Bad Guest', 'rating' => 2, 'text' => 'Too noisy at night', 'time' => 1790100000, 'relative_time_description' => '3 weeks ago'],
            ['author_name' => 'XSS ' . self::XSS, 'rating' => 4, 'text' => 'Nice food ' . self::XSS, 'time' => 1790200000],
            ['author_name' => 'No rating', 'text' => 'ignored'],
        ]]];
    }

    private function assertNoXss(string $html, string $where): void
    {
        $this->assertStringNotContainsString('<script>alert(1)', $html, $where);
        $this->assertStringNotContainsString('<img src=x', $html, $where);
    }

    private static function png(): string
    {
        $png = tempnam(sys_get_temp_dir(), 'wimg') . '.png';
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 30));
        imagepng($im, $png);
        return $png;
    }

    /** Signed difference a − b on a cycle of $n (e.g. tithi 30 vs 1 → −1). */
    private static function cyc(int $a, int $b, int $n): int
    {
        $d = (($a - $b) % $n + $n) % $n;
        return $d > $n / 2 ? $d - $n : $d;
    }

    // ------------------------------------------------------------------ panchang (pure, fixed times)

    public function testChoghadiyaSegmentsForKnownDateAndPlace(): void
    {
        // Thursday 8 October 2026, Dwarka.
        [$rise, $set] = Panchang::sunTimes('2026-10-08', self::LAT, self::LON, self::TZ);
        [$next] = Panchang::sunTimes('2026-10-09', self::LAT, self::LON, self::TZ);
        $local = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(self::TZ))->format('H:i');
        // Dwarka (69° E) sunrise ≈ 06:4x, sunset ≈ 18:3x IST in early October.
        $this->assertMatchesRegularExpression('/^06:(3[5-9]|4\d|5[0-5])$/', $local($rise), 'sunrise');
        $this->assertMatchesRegularExpression('/^18:(2\d|3\d|4[0-5])$/', $local($set), 'sunset');

        $segs = Panchang::choghadiya($rise, $set, $next, 4);
        $this->assertCount(16, $segs);
        $this->assertSame(['Shubh', 'Rog', 'Udveg', 'Char', 'Labh', 'Amrit', 'Kaal', 'Shubh'], array_column(array_slice($segs, 0, 8), 'name'), 'Thursday day');
        $this->assertSame(['Amrit', 'Char', 'Rog', 'Kaal', 'Labh', 'Udveg', 'Shubh', 'Amrit'], array_column(array_slice($segs, 8), 'name'), 'Thursday night');
        $this->assertSame($rise, $segs[0]['start']);
        $this->assertSame($set, $segs[7]['end']);
        $this->assertSame($set, $segs[8]['start']);
        $this->assertSame($next, $segs[15]['end']);
        for ($i = 1; $i < 16; $i++) {
            $this->assertSame($segs[$i - 1]['end'], $segs[$i]['start'], 'contiguous');
        }
        $dayLen = ($set - $rise) / 8;
        $this->assertEqualsWithDelta($dayLen, $segs[3]['end'] - $segs[3]['start'], 1);
        $this->assertSame(['good', 'bad', 'bad', 'neutral', 'good', 'good', 'bad', 'good'], array_column(array_slice($segs, 0, 8), 'quality'));

        // Every weekday's first day / night segment (standard table).
        $first = [];
        foreach (range(0, 6) as $w) {
            $s = Panchang::choghadiya(1000, 1800, 2600, $w);
            $first[] = $s[0]['name'] . '/' . $s[8]['name'];
        }
        $this->assertSame(['Udveg/Shubh', 'Amrit/Char', 'Rog/Kaal', 'Labh/Udveg', 'Shubh/Amrit', 'Char/Rog', 'Kaal/Labh'], $first);
        $sunday = Panchang::choghadiya(0, 800, 1600, 0);
        $this->assertSame(['Udveg', 'Char', 'Labh', 'Amrit', 'Kaal', 'Shubh', 'Rog', 'Udveg'], array_column(array_slice($sunday, 0, 8), 'name'));
        $this->assertSame(['Shubh', 'Amrit', 'Char', 'Rog', 'Kaal', 'Labh', 'Udveg', 'Shubh'], array_column(array_slice($sunday, 8), 'name'));

        // day(): at a fixed time the current segment is found; before sunrise it is the previous Vedic day.
        $noon = (new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone(self::TZ)))->getTimestamp();
        $p = Panchang::day($noon, self::LAT, self::LON, self::TZ);
        $this->assertSame('2026-10-08', $p['date']);
        $this->assertSame(4, $p['weekday']);
        $this->assertSame((int) floor(($noon - $rise) / $dayLen), $p['current']);
        $early = (new DateTimeImmutable('2026-10-08 04:00:00', new DateTimeZone(self::TZ)))->getTimestamp();
        $q = Panchang::day($early, self::LAT, self::LON, self::TZ);
        $this->assertSame('2026-10-07', $q['date'], 'before sunrise = previous Vedic day');
        $this->assertSame('night', $q['choghadiya'][$q['current']]['part']);
        $this->assertSame(['Udveg', 'Shubh', 'Amrit', 'Char', 'Rog', 'Kaal', 'Labh', 'Udveg'], array_column(array_slice($q['choghadiya'], 8), 'name'), 'Wednesday night');
    }

    public function testTithiNakshatraAndMonthWithinTolerance(): void
    {
        $at = static fn (string $d): int => Panchang::day((new DateTimeImmutable($d . ' 12:00:00', new DateTimeZone(self::TZ)))->getTimestamp(), 23.0225, 72.5714, self::TZ)['sunrise'];
        // Well-known dates (udaya tithi at Ahmedabad; tolerance ±1 tithi / nakshatra at the boundary).
        $cases = [
            ['2026-09-04', 23, 4, 'Shravana', 2082],   // Janmashtami 2026: Shravan vad 8 (Gujarati amanta), Rohini
            ['2026-11-08', 30, null, 'Ashwin', 2082],  // Diwali 2026: Aso vad amas
            ['2026-11-10', 1, null, 'Kartika', 2083],  // Gujarati New Year: Kartak sud 1, VS 2083
            ['2026-03-03', 15, null, 'Phalguna', 2082], // Holi 2026 (lunar eclipse full moon)
            ['2026-08-28', 15, null, 'Shravana', 2082], // Raksha Bandhan 2026 (partial lunar eclipse)
            ['2026-11-24', 15, 3, 'Kartika', 2083],    // Kartik Purnima / Dev Diwali: Krittika
            ['2025-10-21', 30, null, 'Ashwin', 2081],  // Diwali 2025 (Lakshmi puja 20/21 Oct)
            ['2025-08-16', 23, null, 'Shravana', 2081], // Janmashtami 2025
        ];
        foreach ($cases as [$d, $tithi, $nak, $month, $samvat]) {
            $t = $at($d);
            $ti = Panchang::tithi($t);
            $this->assertLessThanOrEqual(1, abs(self::cyc($ti['n'], $tithi, 30)), "$d tithi {$ti['n']} vs $tithi");
            $this->assertSame($ti['n'] <= 15 ? 'shukla' : 'krishna', $ti['paksha']);
            if ($nak !== null) {
                $n = Panchang::nakshatra($t);
                $this->assertLessThanOrEqual(1, abs(self::cyc($n['n'], $nak, 27)), "$d nakshatra {$n['name']}");
            }
            $m = Panchang::month($t);
            $this->assertSame($month, $m['name'], "$d month");
            $this->assertSame($samvat, $m['samvat'], "$d samvat");
            $this->assertGreaterThan($t, $ti['end'], 'tithi ends later');
            $this->assertLessThan($t + 27 * 3600, $ti['end'], 'a tithi lasts < 27 h');
        }
        // 2026 has an Adhik (leap) Jyeshtha month.
        $this->assertTrue(Panchang::month($at('2026-06-01'))['adhik']);
        $this->assertSame('Jyeshtha', Panchang::month($at('2026-06-01'))['name']);
        $this->assertFalse(Panchang::month($at('2026-09-04'))['adhik']);

        // Astronomical anchors: new moons of the 12 Aug 2026 total solar eclipse (~17:37 UTC) and Diwali
        // (9 Nov 2026 ~07:02 UTC); the 3 Mar 2026 total lunar eclipse (~11:33 UTC) is a full moon.
        $this->assertEqualsWithDelta(gmmktime(17, 37, 0, 8, 12, 2026), Panchang::newMoonBefore(gmmktime(0, 0, 0, 8, 13, 2026)), 15 * 60);
        $this->assertEqualsWithDelta(gmmktime(7, 2, 0, 11, 9, 2026), Panchang::newMoonBefore(gmmktime(0, 0, 0, 11, 10, 2026)), 15 * 60);
        $this->assertEqualsWithDelta(180.0, Panchang::elongation(gmmktime(11, 33, 0, 3, 3, 2026)), 0.6);
        // Lahiri ayanamsa: Makar Sankranti (Sun enters sidereal Capricorn) on 14 Jan 2026 afternoon.
        $this->assertEqualsWithDelta(270.0, Panchang::siderealSun(gmmktime(9, 30, 0, 1, 14, 2026)), 0.6);
        $this->assertEqualsWithDelta(24.2, Panchang::ayanamsa(gmmktime(0, 0, 0, 1, 1, 2026)), 0.1);

        // The starter festival list agrees with the engine (±1 tithi).
        foreach (Festivals::STARTER as [$date, , $en, , , , $tithi]) {
            if ($tithi === null) {
                continue;
            }
            $n = Panchang::tithi($at($date))['n'];
            $this->assertLessThanOrEqual(1, abs(self::cyc($n, $tithi, 30)), "$en $date: tithi $n vs $tithi");
        }
    }

    // ------------------------------------------------------------------ air quality (#26)

    public function testAirQualityProviderParseErrorsAndStale(): void
    {
        // CPCB sub-index boundaries.
        foreach ([[0, 'pm25', 0], [30, 'pm25', 50], [31, 'pm25', 51], [60, 'pm25', 100], [90, 'pm25', 200], [250, 'pm25', 400], [251, 'pm25', 401],
            [50, 'pm10', 50], [100, 'pm10', 100], [250, 'pm10', 200], [430, 'pm10', 400], [2000, 'pm10', 500]] as [$c, $p, $exp]) {
            $this->assertSame($exp, AirQuality::subIndex((float) $c, $p), "$p $c");
        }
        $this->assertSame('poor', AirQuality::category(250));
        $this->assertSame('us_sensitive', AirQuality::usCategory(120));

        $params = ['lat' => self::LAT, 'lon' => self::LON];
        $this->assertSame(['lat' => 22.24, 'lon' => 68.97], DataFeeds::cleanParams('open_meteo_aq', $params));
        foreach ([['lat' => 91, 'lon' => 0], ['lat' => 'x', 'lon' => 1], ['lat' => '1&x=1', 'lon' => 2], []] as $bad) {
            $this->assertNull(DataFeeds::cleanParams('open_meteo_aq', $bad), json_encode($bad));
        }
        self::mock(['https://air-quality-api.open-meteo.com/v1/air-quality' => [200, self::aqJson(45, 80)]]);
        $r = DataFeeds::fetch('open_meteo_aq', $params, '');
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame([47.0, 83.0, 45.0, 80.0, 120.0], [$r['data']['pm25'], $r['data']['pm10'], $r['data']['pm25_24h'], $r['data']['pm10_24h'], $r['data']['us_aqi']]);
        $url = self::$calls[0];
        $this->assertSame('air-quality-api.open-meteo.com', parse_url($url, PHP_URL_HOST));
        $this->assertStringContainsString('latitude=22.24', $url);
        $idx = AirQuality::index($r['data']);
        // PM2.5 45 → 75, PM10 80 → 80: Indian AQI 80 (satisfactory), driven by PM10.
        $this->assertSame(['in', 80, 'satisfactory', 'pm10'], [$idx['scale'], $idx['aqi'], $idx['category'], $idx['main']]);
        // No hourly series → US AQI fallback, clearly labelled.
        self::mock(['https://air-quality-api.open-meteo.com/' => [200, self::aqJson(45, 80, false)]]);
        $r = DataFeeds::fetch('open_meteo_aq', $params, '');
        $idx = AirQuality::index($r['data']);
        $this->assertSame(['us', 120, 'us_sensitive'], [$idx['scale'], $idx['aqi'], $idx['category']]);
        // Provider errors.
        self::mock(['https://air-quality-api.open-meteo.com/' => [400, ['error' => true, 'reason' => 'Latitude must be in range of -90 to 90°.']]]);
        $r = DataFeeds::fetch('open_meteo_aq', $params, '');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Latitude must be', (string) $r['error']);
        self::mock(['https://air-quality-api.open-meteo.com/' => [200, 'not json']]);
        $this->assertFalse(DataFeeds::fetch('open_meteo_aq', $params, '')['ok']);
        self::mock(['https://air-quality-api.open-meteo.com/' => [429, ['error' => true, 'reason' => 'Daily API request limit exceeded']]]);
        $r = DataFeeds::fetch('open_meteo_aq', $params, '');
        $this->assertTrue($r['rate_limited']);

        // Stale: a failed refresh keeps the last good value and marks it.
        $id = self::seed('open_meteo_aq', $params, ['pm25' => 47.0, 'pm10' => 83.0, 'pm25_24h' => 45.0, 'pm10_24h' => 80.0, 'us_aqi' => 120.0], 0, time() - 7200);
        self::mock(['https://air-quality-api.open-meteo.com/' => [500, ['error' => true, 'reason' => 'Internal error']]]);
        $row = DB::one('SELECT * FROM data_feeds WHERE id = :id', ['id' => $id]);
        $new = DataFeeds::refreshRow($row);
        $this->assertSame('failed', $new['_result']);
        DataFeeds::flush();
        $st = DataFeeds::get('open_meteo_aq', $params);
        $this->assertTrue($st['stale']);
        $this->assertSame(47.0, (float) $st['data']['pm25']);
        $this->assertGreaterThan(time(), (int) DB::value('SELECT retry_at FROM data_feeds WHERE id = :id', ['id' => $id]), 'back-off');
        self::seed('open_meteo_wx', $params, AirQuality::parseForecast([200, self::fcJson(0, 30, 10)]));
        $item = DisplayAppsTestKit::createItem('air_quality', ['warnings' => true]);
        $html = DisplayAppsTestKit::renderInProcess($item);
        $this->assertStringContainsString('Last known value', $html);
        $this->assertStringContainsString('Indian AQI', $html);
        $this->assertStringContainsString('aq-dial', $html);
        $this->assertStringContainsString('>80<', $html);
        $this->assertStringContainsString('Satisfactory', $html);
        $this->assertStringNotContainsString('aq-warn ', $html, 'no warning below the thresholds');
        $this->assertStringContainsString('<div class="aq-main-row">', $html, 'full-size dial without warnings');

        // Forecast parse + warnings with thresholds; ferry line only for coastal towns.
        $fc = AirQuality::parseForecast([200, self::fcJson(80, 42, 45, 95)]);
        $this->assertSame(80.0, $fc['days'][0]['rain_mm']);
        $types = static fn (array $w): array => array_column($w, 'type');
        $cfg = (new AirQualityApp())->defaults();
        $this->assertSame(['rain', 'heat', 'wind', 'storm'], $types(AirQuality::warnings($fc, $cfg)));
        $this->assertSame(['rain', 'heat', 'wind', 'storm', 'ferry'], $types(AirQuality::warnings($fc, ['coastal' => true] + $cfg)));
        $this->assertSame(['storm'], $types(AirQuality::warnings($fc, ['rain_mm' => 100, 'heat_c' => 45, 'wind_kmh' => 60] + $cfg)), 'thresholds respected');
        $ferry = AirQuality::warnings($fc, ['coastal' => true, 'ferry_kmh' => 30, 'ferry_text' => 'Bet Dwarka boats closed'] + $cfg);
        $this->assertSame('Bet Dwarka boats closed', end($ferry)['text']);
        $this->assertSame([], AirQuality::warnings(null, $cfg));
        self::mock(['https://api.open-meteo.com/' => [400, ['error' => true, 'reason' => 'Bad forecast request']]]);
        $r = DataFeeds::fetch('open_meteo_wx', $params, '');
        $this->assertFalse($r['ok']);
        $this->assertSame('api.open-meteo.com', parse_url(self::$calls[array_key_last(self::$calls)], PHP_URL_HOST));

        // Banner on the TV in Gujarati with a warning feed.
        self::seed('open_meteo_wx', $params, $fc);
        DataFeeds::flush();
        $item = DisplayAppsTestKit::createItem('air_quality', ['coastal' => true, 'ferry_kmh' => 30], ['lang' => 'gu']);
        $html = DisplayAppsTestKit::renderInProcess($item);
        $this->assertStringContainsString('ભારે વરસાદ', $html);
        $this->assertStringContainsString('દરિયાઈ ચેતવણી', $html);
        $this->assertStringContainsString('સંતોષકારક', $html);
        // Regression (QA 2.4): five warnings pushed the dial and PM cards over each other on a 1080p TV;
        // several warnings now render in two columns and the main row gets the compact (tight) layout.
        $this->assertStringContainsString('aq-warnings aq-warn-many', $html);
        $this->assertStringContainsString('aq-main-row aq-tight aq-tighter', $html);
        ContentManager::deleteItem((int) $item['id']);
    }

    // ------------------------------------------------------------------ Google reviews (#30)

    public function testPlacesProviderParseTtlAndKeyNeverLeaked(): void
    {
        $this->assertNull(DataFeeds::cleanParams('google_places', ['place_id' => 'bad id!']));
        $this->assertNull(DataFeeds::cleanParams('google_places', ['place_id' => self::PLACE, 'lang' => 'fr']));
        $this->assertNull(DataFeeds::cleanParams('google_places', ['place_id' => 'https://evil.com/x']));
        $this->assertSame('no_key', DataFeeds::get('google_places', ['place_id' => self::PLACE])['status']);
        $this->assertSame([], self::$calls, 'no request without a key');

        self::mock(['https://maps.googleapis.com/maps/api/place/details/json' => [200, self::placesJson()]]);
        $r = DataFeeds::fetch('google_places', ['place_id' => self::PLACE, 'lang' => 'en'], self::KEY);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame([4.4, 1234, 3], [$r['data']['rating'], $r['data']['total'], count($r['data']['reviews'])]);
        $this->assertSame(['Meera S', 5, 'Wonderful stay near the temple'], [$r['data']['reviews'][0]['author'], $r['data']['reviews'][0]['rating'], $r['data']['reviews'][0]['text']]);
        $u = self::$calls[0];
        $this->assertSame('maps.googleapis.com', parse_url($u, PHP_URL_HOST));
        $this->assertStringContainsString('fields=name%2Crating%2Cuser_ratings_total%2Creviews', $u);
        $this->assertStringContainsString('place_id=' . self::PLACE, $u);
        $this->assertStringNotContainsString(self::KEY, json_encode($r['data']));
        // Errors: the key is scrubbed from provider messages; OVER_QUERY_LIMIT = rate limit.
        self::mock(['https://maps.googleapis.com/' => [200, ['status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key ' . self::KEY . ' is invalid.']]]);
        $r = DataFeeds::fetch('google_places', ['place_id' => self::PLACE], self::KEY);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('REQUEST_DENIED', (string) $r['error']);
        $this->assertStringNotContainsString(self::KEY, (string) $r['error']);
        self::mock(['https://maps.googleapis.com/' => [200, ['status' => 'OVER_QUERY_LIMIT', 'error_message' => 'You have exceeded your daily request quota']]]);
        $this->assertTrue(DataFeeds::fetch('google_places', ['place_id' => self::PLACE], self::KEY)['rate_limited']);
        self::mock(['https://maps.googleapis.com/' => [200, ['status' => 'NOT_FOUND']]]);
        $this->assertSame('NOT_FOUND', DataFeeds::fetch('google_places', ['place_id' => self::PLACE], self::KEY)['error']);
        // TTL: at least 6 h even if the platform setting says less.
        Settings::setPlatform('platform_feed_ttl_google_places', '60');
        $this->assertGreaterThanOrEqual(21600, DataFeeds::ttl('google_places'));
        $this->assertSame(43200, DataFeeds::PROVIDERS['google_places']['ttl']);

        // Over HTTP: stored reviews, key encrypted at rest, never in the page / data JSON / feed rows.
        DataFeeds::setPlatformKey('google_places', self::KEY);
        $this->assertStringStartsWith('enc:', (string) Settings::platform('platform_feedkey_google_places'));
        self::seed('google_places', ['place_id' => self::PLACE, 'lang' => 'en'], GoogleReviews::parse([200, self::placesJson()]));
        $item = DisplayAppsTestKit::createItem('reviews', ['mode' => 'auto', 'place_id' => self::PLACE, 'min_rating' => 4]);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Wonderful stay near the temple', $html);
        $this->assertStringContainsString('Reviews from Google', $html);
        $this->assertStringContainsString('1,234 reviews', $html);
        $this->assertStringContainsString('4.4', $html);
        $this->assertStringNotContainsString('Too noisy at night', $html, 'only 4–5 stars');
        $this->assertNoXss($html, 'reviews page');
        $this->assertStringContainsString('Wonderful stay', (string) $json['data']['html']);
        foreach ([$html, json_encode($json), json_encode(DB::all('SELECT * FROM data_feeds'))] as $out) {
            $this->assertStringNotContainsString(self::KEY, $out);
            $this->assertStringNotContainsString('maps.googleapis', $out);
        }
        // All ratings → the 2-star review appears; Hindi page.
        $item2 = DisplayAppsTestKit::createItem('reviews', ['mode' => 'auto', 'place_id' => self::PLACE, 'min_rating' => 1], ['lang' => 'hi']);
        self::seed('google_places', ['place_id' => self::PLACE, 'lang' => 'hi'], GoogleReviews::parse([200, self::placesJson()]));
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item2);
        $this->assertStringContainsString('Too noisy at night', $html);
        $this->assertStringContainsString('Google की समीक्षाएँ', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
        // Admin pages never show the key.
        $root = new AdminSession(self::$url, 'wBoss');
        [$code, , $page] = $root->get('data_feeds.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($page));
        $this->assertStringContainsString('Google Places (Place Details)', $page);
        $this->assertStringContainsString('Air quality', $page);
        $this->assertStringNotContainsString(self::KEY, $page);
        [$code, , $page] = (new AdminSession(self::$url, 'wMgr'))->get('apps.php?action=edit&id=' . $item['id']);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString(self::KEY, $page);
        ContentManager::deleteItem((int) $item['id']);
        ContentManager::deleteItem((int) $item2['id']);
        DataFeeds::setPlatformKey('google_places', '');
        Settings::setPlatform('platform_feed_ttl_google_places', '43200');
    }

    public function testManualReviewsParsingAndValidation(): void
    {
        [$list, $err] = GoogleReviews::parseManual("Ramesh P. | 5 | 2026-09-12 | Very clean rooms\nAsha | 4 | | Good food | with a pipe\nBad line\nX | 9 | | text\nY | 3 | not-a-date | text");
        $this->assertCount(2, $list);
        $this->assertSame('Good food | with a pipe', $list[1]['text']);
        $this->assertNull($list[1]['time']);
        $this->assertCount(3, $err);
        $this->assertSame(4.5, GoogleReviews::average($list));
        $this->assertCount(1, GoogleReviews::filter($list, 5));
        $app = new ReviewsApp();
        [$c, $e] = $app->validate([]);
        $this->assertNotEmpty($e, 'manual mode needs a review');
        $this->assertSame('manual', $c['mode']);
        [, $e] = $app->validate(['mode' => 'auto', 'place_id' => 'nope']);
        $this->assertNotEmpty($e);
        [$c, $e] = $app->validate(['mode' => 'auto', 'place_id' => self::PLACE, 'min_rating' => '5', 'manual_rating' => '7']);
        $this->assertSame(['The overall rating must be between 1 and 5.'], $e);
        $this->assertSame(5, $c['min_rating']);
        [$c, $e] = $app->validate(['reviews' => 'A | 5 | | Great', 'manual_rating' => '4.66', 'manual_total' => '250', 'from_google' => '1']);
        $this->assertSame([], $e);
        $this->assertSame('4.7', $c['manual_rating']);
        $item = DisplayAppsTestKit::createItem('reviews', ['reviews' => 'Kiran ' . self::XSS . ' | 5 | 2026-09-01 | Lovely ' . self::XSS, 'manual_total' => '250', 'from_google' => false]);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertNoXss($html, 'manual reviews');
        $this->assertStringContainsString('250 reviews', $html);
        $this->assertStringNotContainsString('Reviews from Google', $html, 'attribution only for Google reviews');
        ContentManager::deleteItem((int) $item['id']);
    }

    // ------------------------------------------------------------------ festivals (#28)

    public function testFestivalsCrudStarterImportTenancyXss(): void
    {
        DB::query('DELETE FROM festivals WHERE hotel_id = 1');
        $s = new AdminSession(self::$url, 'wStaff');
        [$code, , $html] = $s->get('festivals.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('Import starter list', $html);
        // CSRF
        [$code] = TestEnv::http('POST', self::$url . 'admin/festivals.php', null, [], $s->jar, ['op' => 'import']);
        $this->assertSame(419, $code);
        // Validation → 422
        [$code, , $html] = $s->post('festivals.php', ['op' => 'save', 'name_en' => '', 'starts_on' => '2026-13-40']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('The festival name (English) is required.', $html);
        [$code, , $html] = $s->post('festivals.php', ['op' => 'save', 'name_en' => 'X', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-10']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('The last day must not be before the first day.', $html);
        // Create (with picture) today + XSS
        $today = date('Y-m-d');
        $png = self::png();
        [$code] = TestEnv::http('POST', self::$url . 'admin/festivals.php', null, [], $s->jar, ['_csrf' => $s->csrf, 'op' => 'save', 'name_en' => 'Fest ' . self::XSS,
            'name_gu' => 'તહેવાર', 'name_hi' => 'त्योहार', 'starts_on' => $today, 'description' => 'Desc ' . self::XSS, 'theme' => 'diwali', 'is_active' => '1',
            'image' => new CURLFile($png, 'image/png', 'f.png')]);
        @unlink($png);
        $this->assertSame(302, $code);
        $f = DB::one('SELECT * FROM festivals WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($f);
        $this->assertNotNull($f['image_path']);
        $this->assertSame('diwali', $f['theme']);
        $s->post('festivals.php', ['op' => 'save', 'name_en' => 'Later fest', 'starts_on' => date('Y-m-d', strtotime('+10 days')), 'is_active' => '1']);
        [, , $html] = $s->get('festivals.php');
        $this->assertNoXss($html, 'festivals list');
        $this->assertStringContainsString('Later fest', $html);
        // TV: hero today in Gujarati, theme switch, countdown.
        $item = DisplayAppsTestKit::createItem('festivals', ['theme_switch' => true], ['lang' => 'gu']);
        $page = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('તહેવાર', $page);
        $this->assertStringContainsString('fe-hero', $page);
        $this->assertStringContainsString('--hc-bg:#1A0633', $page, 'Diwali colours on the festival day');
        $this->assertStringContainsString('Later fest', $page);
        $this->assertStringContainsString('>10<', $page);
        $this->assertStringNotContainsString('H2-FEST-SECRET', $page);
        $this->assertNoXss($page, 'festival TV page');
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertNoXss((string) $json['data']['html'], 'festival data');
        // Edit / toggle / delete
        [$code] = $s->post('festivals.php', ['op' => 'save', 'id' => $f['id'], 'name_en' => 'Renamed', 'starts_on' => $today, 'is_active' => '1', 'remove_image' => '1']);
        $this->assertSame(302, $code);
        $row = Festivals::find((int) $f['id']);
        $this->assertSame('Renamed', $row['name_en']);
        $this->assertNull($row['image_path']);
        $s->post('festivals.php', ['op' => 'toggle', 'id' => $f['id']]);
        $this->assertSame(0, (int) Festivals::find((int) $f['id'])['is_active']);
        $this->assertSame([], Festivals::today($today), 'hidden festival is not shown');
        // Tenancy: hotel 2's festival → 404 everywhere.
        foreach ([['op' => 'save', 'id' => self::$id['h2fest'], 'name_en' => 'pwn', 'starts_on' => $today], ['op' => 'toggle', 'id' => self::$id['h2fest']], ['op' => 'delete', 'id' => self::$id['h2fest']]] as $p) {
            [$code] = $s->post('festivals.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        [$code] = $s->get('festivals.php?action=edit&id=' . self::$id['h2fest']);
        $this->assertSame(404, $code);
        $this->assertSame('H2-FEST-SECRET', Tenant::run(2, static fn () => Festivals::find(self::$id['h2fest'])['name_en']));
        [$code] = $s->post('festivals.php', ['op' => 'delete', 'id' => $f['id']]);
        $this->assertSame(302, $code);
        $this->assertNull(Festivals::find((int) $f['id']));
        // Starter list: only future festivals, once, marked "please verify".
        $expected = count(array_filter(Festivals::STARTER, static fn ($x) => ($x[1] ?? $x[0]) >= $today));
        [$code] = $s->post('festivals.php', ['op' => 'import']);
        $this->assertSame(302, $code);
        $this->assertSame($expected, (int) DB::value('SELECT COUNT(*) FROM festivals WHERE hotel_id = 1 AND is_starter = 1'));
        $s->post('festivals.php', ['op' => 'import']);
        $this->assertSame($expected, (int) DB::value('SELECT COUNT(*) FROM festivals WHERE hotel_id = 1 AND is_starter = 1'), 'no duplicates');
        $this->assertSame(0, (int) Tenant::run(2, static fn () => DB::value('SELECT COUNT(*) FROM festivals WHERE hotel_id = 2 AND is_starter = 1')));
        [, , $html] = $s->get('festivals.php');
        $this->assertStringContainsString('Please verify the date', $html);
        $this->assertStringContainsString('please verify them with your temple / panchang', $html);
        // Permissions: reception cannot manage festivals.
        [$code] = (new AdminSession(self::$url, 'wRecep'))->get('festivals.php');
        $this->assertSame(403, $code);
        ContentManager::deleteItem((int) $item['id']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ celebrations (#29)

    public function testCelebrationsCrudCsvConsentTenancyXss(): void
    {
        DB::query('DELETE FROM celebrations WHERE hotel_id = 1');
        // Pure helpers.
        $this->assertSame([10, 8, 1990], Celebrations::parseDate('08/10/1990'));
        $this->assertSame([10, 8, 1990], Celebrations::parseDate('1990-10-08'));
        $this->assertSame([2, 29, null], Celebrations::parseDate('29-02'));
        $this->assertSame([12, 31, null], Celebrations::parseDate('--12-31'));
        $this->assertNull(Celebrations::parseDate('31/02/1990'));
        $this->assertNull(Celebrations::parseDate('08/10/2999'));
        $this->assertNull(Celebrations::parseDate('tomorrow'));
        $this->assertSame('work_anniversary', Celebrations::parseType('Work anniversary'));
        $this->assertNull(Celebrations::parseType('graduation'));
        $this->assertSame('2027-02-28', Celebrations::occurrence(['month' => 2, 'day' => 29], 2027));
        $this->assertSame('2028-02-29', Celebrations::occurrence(['month' => 2, 'day' => 29], 2028));

        $mgr = new AdminSession(self::$url, 'wMgr');
        [$code, , $html] = $mgr->get('celebrations.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$code] = (new AdminSession(self::$url, 'wStaff'))->get('celebrations.php');
        $this->assertSame(403, $code, 'personal data: managers only');
        [$code] = TestEnv::http('POST', self::$url . 'admin/celebrations.php', null, [], $mgr->jar, ['op' => 'save', 'name' => 'x', 'day' => 1, 'month' => 1]);
        $this->assertSame(419, $code);
        [$code, , $html] = $mgr->post('celebrations.php', ['op' => 'save', 'name' => 'Bad', 'day' => '31', 'month' => '2']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Choose a valid day and month.', $html);

        $today = date('Y-m-d');
        [$m, $d] = [(int) date('n'), (int) date('j')];
        $in3 = strtotime('+3 days');
        $png = self::png();
        [$code] = TestEnv::http('POST', self::$url . 'admin/celebrations.php', null, [], $mgr->jar, ['_csrf' => $mgr->csrf, 'op' => 'save', 'name' => 'Asha ' . self::XSS,
            'type' => 'birthday', 'day' => (string) $d, 'month' => (string) $m, 'year' => '1990', 'group_label' => 'Grp ' . self::XSS, 'consent' => '1', 'is_active' => '1',
            'photo' => new CURLFile($png, 'image/png', 'p.png')]);
        @unlink($png);
        $this->assertSame(302, $code);
        $mgr->post('celebrations.php', ['op' => 'save', 'name' => 'NoConsent Person', 'type' => 'birthday', 'day' => (string) $d, 'month' => (string) $m, 'consent' => '0', 'is_active' => '1']);
        $mgr->post('celebrations.php', ['op' => 'save', 'name' => 'Upcoming Ravi', 'type' => 'work_anniversary', 'day' => date('j', $in3), 'month' => date('n', $in3), 'year' => '2015', 'consent' => '1', 'is_active' => '1']);
        $asha = DB::one("SELECT * FROM celebrations WHERE hotel_id = 1 AND name LIKE 'Asha%'");
        $this->assertNotNull($asha['photo_path']);
        [, , $html] = $mgr->get('celebrations.php');
        $this->assertNoXss($html, 'celebrations list');
        $this->assertStringContainsString('No consent', $html);

        // TV: consent only, year hidden unless enabled, confetti, upcoming.
        $item = DisplayAppsTestKit::createItem('celebrations', ['show_years' => false]);
        $page = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('Happy Birthday!', $page);
        $this->assertStringContainsString('Asha', $page);
        $this->assertStringNotContainsString('NoConsent Person', $page, 'consent rule');
        $this->assertStringNotContainsString('H2-CEL-SECRET', $page, 'tenancy');
        $this->assertStringContainsString('Upcoming Ravi', $page);
        $this->assertStringContainsString('ce-confetti', $page);
        $this->assertStringNotContainsString('Turns', $page, 'age hidden by default');
        $this->assertStringNotContainsString('1990', $page);
        $this->assertNoXss($page, 'celebrations TV page');
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('NoConsent Person', (string) $json['data']['html']);
        $item2 = DisplayAppsTestKit::createItem('celebrations', ['show_years' => true], ['lang' => 'gu']);
        $page = DisplayAppsTestKit::assertRenders($this, self::$url, $item2);
        $this->assertStringContainsString('આજે ' . ((int) date('Y') - 1990) . ' વર્ષ પૂરાં', $page);
        $item3 = DisplayAppsTestKit::createItem('celebrations', ['types' => ['anniversary']]);
        $page = DisplayAppsTestKit::assertRenders($this, self::$url, $item3);
        $this->assertStringContainsString('No birthdays or anniversaries this week.', $page);

        // Year wrap: 30 Dec → 2 Jan is "in 3 days".
        $b = Celebrations::board('2026-12-30', 7);
        $this->assertSame([], $b['today']);
        $wrap = DB::insert('celebrations', ['name' => 'NewYear Baby', 'month' => 1, 'day' => 2, 'consent' => 1, 'created_at' => now()]);
        $b = Celebrations::board('2026-12-30', 7);
        $this->assertSame(['NewYear Baby', 3, '2027-01-02'], [$b['upcoming'][0]['name'], $b['upcoming'][0]['in'], $b['upcoming'][0]['on']]);
        DB::delete('celebrations', 'id = :id', ['id' => $wrap]);

        // CSV import: text (no consent) and file (with consent).
        $csv = "name,date,type,group\nKiran Shah,08/10/1985,birthday,Kitchen\nMehul & Nisha,1999-02-14,anniversary,\nLeap Day,29/02,,\nBroken line,32/13/2000,birthday,\nOdd,01/01,graduation,\n\"Desai, Priya\",15-08,work,Front office";
        [$code] = $mgr->post('celebrations.php', ['op' => 'import', 'csv_text' => $csv]);
        $this->assertSame(302, $code);
        $rows = DB::all("SELECT * FROM celebrations WHERE hotel_id = 1 AND name IN ('Kiran Shah','Mehul & Nisha','Leap Day','Desai, Priya') ORDER BY name");
        $this->assertCount(4, $rows);
        $this->assertSame([0, 0, 0, 0], array_map(static fn ($r) => (int) $r['consent'], $rows), 'imported without consent');
        $byName = array_column($rows, null, 'name');
        $this->assertSame(['work_anniversary', 8, 15, 'Front office'], [$byName['Desai, Priya']['type'], (int) $byName['Desai, Priya']['month'], (int) $byName['Desai, Priya']['day'], $byName['Desai, Priya']['group_label']]);
        $this->assertSame([10, 8, 1985], [(int) $byName['Kiran Shah']['month'], (int) $byName['Kiran Shah']['day'], (int) $byName['Kiran Shah']['year']]);
        $this->assertNull($byName['Leap Day']['year']);
        $this->assertSame('anniversary', $byName['Mehul & Nisha']['type']);
        $file = tempnam(sys_get_temp_dir(), 'wcsv') . '.csv';
        file_put_contents($file, "\xEF\xBB\xBFname,date,type,group\nCSV Consent " . self::XSS . ',' . date('d/m', strtotime('+1 day')) . ",birthday,Ops\n");
        [$code] = TestEnv::http('POST', self::$url . 'admin/celebrations.php', null, [], $mgr->jar, ['_csrf' => $mgr->csrf, 'op' => 'import', 'consent_all' => '1',
            'csv' => new CURLFile($file, 'text/csv', 'people.csv')]);
        @unlink($file);
        $this->assertSame(302, $code);
        $this->assertSame(1, (int) DB::value("SELECT consent FROM celebrations WHERE hotel_id = 1 AND name LIKE 'CSV Consent%'"));
        $page = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('CSV Consent', $page);
        $this->assertStringContainsString('Tomorrow', $page);
        $this->assertNoXss($page, 'imported names');
        [$code] = (new AdminSession(self::$url, 'wStaff'))->post('celebrations.php', ['op' => 'import', 'csv_text' => "Hacker,01/01,birthday,"]);
        $this->assertSame(403, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM celebrations WHERE name = 'Hacker'"));

        // Toggle / delete / tenancy.
        $mgr->post('celebrations.php', ['op' => 'toggle', 'id' => $asha['id']]);
        $this->assertSame(0, (int) Celebrations::find((int) $asha['id'])['is_active']);
        $this->assertSame([], array_filter(Celebrations::board($today)['today'], static fn ($r) => (int) $r['id'] === (int) $asha['id']));
        foreach ([['op' => 'save', 'id' => self::$id['h2cel'], 'name' => 'pwn', 'day' => '1', 'month' => '1'], ['op' => 'toggle', 'id' => self::$id['h2cel']], ['op' => 'delete', 'id' => self::$id['h2cel']]] as $p) {
            [$code] = $mgr->post('celebrations.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        [$code] = $mgr->get('celebrations.php?action=edit&id=' . self::$id['h2cel']);
        $this->assertSame(404, $code);
        [$code] = $mgr->post('celebrations.php', ['op' => 'delete', 'id' => $asha['id']]);
        $this->assertSame(302, $code);
        $this->assertNull(Celebrations::find((int) $asha['id']));
        foreach ([$item, $item2, $item3] as $it) {
            ContentManager::deleteItem((int) $it['id']);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ panchang app

    public function testPanchangAppOverrideValidationAndRendering(): void
    {
        $app = new PanchangApp();
        [, $e] = $app->validate(['overrides' => "2026-10-08 | Aso sud 7\nbad line\n2026-02-30 | x", 'show_panchang' => '1', 'show_choghadiya' => '1']);
        $this->assertCount(2, $e);
        [$c, $e] = $app->validate(['show_panchang' => '0', 'show_choghadiya' => '0', 'lat' => '95', 'lon' => '10']);
        $this->assertCount(2, $e);
        $this->assertTrue($c['show_panchang'] && $c['show_choghadiya']);
        $this->assertSame(['', ''], [$c['lat'], $c['lon']]);
        [$c, $e] = $app->validate(['show_panchang' => '1', 'lat' => '23.0225', 'lon' => '72.5714']);
        $this->assertSame([], $e);
        $this->assertSame([23.0225, 72.5714], WidgetApp::location($c));
        $this->assertSame([self::LAT, self::LON], WidgetApp::location($app->defaults()), 'hotel weather location');

        $today = Panchang::day(time(), self::LAT, self::LON, date_default_timezone_get())['date'];
        $item = DisplayAppsTestKit::createItem('panchang', ['overrides' => $today . ' | MY-TITHI ' . self::XSS], ['lang' => 'en']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('MY-TITHI', $html);
        $this->assertStringContainsString('(set by the hotel)', $html);
        $this->assertStringContainsString('Approximate', $html);
        $body = explode('window.HC_DISPLAY=', $html)[0];
        $this->assertSame(16, substr_count($body, 'class="pc-seg '));
        $this->assertSame(1, substr_count($body, 'is-now'));
        $this->assertNoXss($html, 'panchang override');
        $item2 = DisplayAppsTestKit::createItem('panchang', [], ['lang' => 'en']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item2);
        $this->assertStringContainsString('lang="gu"', $html, 'Gujarati-first tithi line');
        $this->assertMatchesRegularExpression('/(સુદ|વદ)/u', $html);
        $this->assertStringContainsString('Vikram Samvat 20', $html);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item2);
        $this->assertSame(200, $code);
        $this->assertSame(900, $json['refresh_sec']);
        $this->assertStringContainsString('pc-seg', (string) $json['data']['html']);
        ContentManager::deleteItem((int) $item['id']);
        ContentManager::deleteItem((int) $item2['id']);
    }

    // ------------------------------------------------------------------ every app, every language

    public function testEveryWidgetRendersInEveryLanguage(): void
    {
        $params = ['lat' => self::LAT, 'lon' => self::LON];
        self::seed('open_meteo_aq', $params, AirQuality::parseAq([200, self::aqJson(95, 300)]));
        self::seed('open_meteo_wx', $params, AirQuality::parseForecast([200, self::fcJson(70, 41, 50, 95)]));
        DB::insert('festivals', ['name_en' => 'Today Fest', 'name_gu' => 'આજનો તહેવાર', 'name_hi' => 'आज का त्योहार', 'starts_on' => date('Y-m-d'), 'theme' => 'navratri', 'created_at' => now()]);
        DB::insert('celebrations', ['name' => 'Render Person', 'month' => (int) date('n'), 'day' => (int) date('j'), 'year' => 1980, 'consent' => 1, 'created_at' => now()]);
        $configs = [
            'air_quality' => ['coastal' => true],
            'panchang' => [],
            'festivals' => ['theme_switch' => true],
            'celebrations' => ['show_years' => true],
            'reviews' => ['reviews' => "Meera | 5 | 2026-09-01 | Excellent\nRaj | 4 | | Good location"],
        ];
        foreach ($configs as $app => $cfg) {
            foreach (['en' => 'classic_dark', 'gu' => 'light', 'hi' => 'temple_saffron'] as $lang => $theme) {
                $item = DisplayAppsTestKit::createItem($app, $cfg, ['lang' => $lang, 'theme' => $theme]);
                $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
                $this->assertStringNotContainsString('This app is not available', $html, "$app $lang");
                [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
                $this->assertSame(200, $code, "$app $lang data");
                $this->assertTrue($json['ok']);
                $this->assertIsString($json['data']['html'] ?? null, "$app $lang data.html");
                $prev = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
                $this->assertSame(200, $prev[0]);
                $this->assertFalse(TestEnv::hasPhpError($prev[1]));
                ContentManager::deleteItem((int) $item['id']);
            }
            // Defaults (gallery) and validate([]) never throw.
            $a = DisplayApps::find($app);
            $this->assertNotNull($a);
            $a->validate([]);
            $this->assertNotSame('', $a->form($a->config([])));
            $this->assertSame('widget', $a->category());
        }
        $gu = DisplayAppsTestKit::renderInProcess(DisplayAppsTestKit::createItem('air_quality', [], ['lang' => 'gu']));
        $this->assertStringContainsString('ભારતીય AQI', $gu);
        $hi = DisplayAppsTestKit::renderInProcess(DisplayAppsTestKit::createItem('celebrations', [], ['lang' => 'hi']));
        $this->assertStringContainsString('जन्मदिन की हार्दिक शुभकामनाएँ!', $hi);
        // Admin gallery and edit forms.
        $mgr = new AdminSession(self::$url, 'wMgr');
        [$code, , $html] = $mgr->get('apps.php');
        $this->assertSame(200, $code);
        foreach (['Air quality &amp; weather alerts', 'Panchang &amp; Choghadiya', 'Festival calendar', 'Birthday &amp; anniversary wall', 'Google reviews'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        foreach (array_keys($configs) as $app) {
            [$code, , $html] = $mgr->get('apps.php?action=new&app=' . $app);
            $this->assertSame(200, $code, $app);
            $this->assertFalse(TestEnv::hasPhpError($html), $app);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = array_merge(array_values(Panchang::TITHIS), [Panchang::AMAVASYA, 'Shukla paksha', 'Krishna paksha', 'Adhik'], array_values(Panchang::NAKSHATRAS), array_values(Panchang::YOGAS),
            array_column(Panchang::CHOGHADIYA, 0), array_map(static fn ($m) => 'Lunar month ' . $m, Panchang::MONTHS));
        foreach (['core/AirQuality.php', 'core/GoogleReviews.php', 'core/Festivals.php', 'core/Celebrations.php', 'core/WidgetApp.php', 'core/Apps/AirQualityApp.php',
            'core/Apps/PanchangApp.php', 'core/Apps/FestivalsApp.php', 'core/Apps/CelebrationsApp.php', 'core/Apps/ReviewsApp.php', 'admin/festivals.php', 'admin/celebrations.php',
            'admin/partials/nav.d/18_widgets_26_30.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        $missing = [];
        foreach (array_unique($keys) as $k) {
            foreach (['gu' => $gu, 'hi' => $hi] as $l => $t) {
                if (!isset($t[$k])) {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        $this->assertSame('આસો', I18n::translate('Lunar month Ashwin', 'gu'));
        $this->assertSame('જેઠ', I18n::translate('Lunar month Jyeshtha', 'gu'));
        $this->assertSame('જ્યેષ્ઠા', I18n::translate('Jyeshtha', 'gu'), 'nakshatra ≠ month');
        $this->assertSame('अमृत', I18n::translate('Amrit', 'hi'));
    }
}
