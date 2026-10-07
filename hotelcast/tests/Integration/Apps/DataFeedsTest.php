<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Data feeds layer (2.3, #21–#25): provider parsers (Http mocks only — no real API is called), errors /
 * rate limits keep the last good value (stale), encrypted keys never exposed, manual mode without keys,
 * admin/rates.php (CRUD, history, tenancy, XSS, CSRF, permissions), ticker placeholders, background
 * task TTL / budget, fixed provider hosts, the five widgets over HTTP, platform + hotel key pages.
 */
final class DataFeedsTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const KEY = 'SECRETKEY-abc123XYZ';
    /** @var array<int, string> */
    private static array $calls = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'dfMgr', 'staff' => 'dfStaff', 'reception' => 'dfRecep', 'super_admin' => 'dfBoss'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        DB::insert('users', ['hotel_id' => 1, 'username' => 'dfRoot', 'email' => 'root@t.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        self::$id['room'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'dfBoss2', 'email' => 'b2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2rate'] = DB::insert('metal_rates', ['gold_24k' => 99999, 'note' => 'H2-RATE-SECRET', 'created_at' => now()]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        DisplayApps::all(); // loads core/Apps classes (CricketApp::pick() …)
        Cache::clear();
        // The sandbox server must never reach the internet: every provider URL is answered by this file.
        self::$mockFile = TestEnv::$sandbox . '/storage/feeds_mock.json';
        file_put_contents(self::$mockFile, json_encode(['https://' => ['status' => 503, 'body' => '{}'], 'http://' => ['status' => 503, 'body' => '{}']]));
        Settings::setPlatform('task_last_DataFeedsTask', (string) (time() + 86400)); // no background run inside the server
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
        self::$calls = [];
        Http::$mock = static function (string $m, string $url): ?array {
            self::$calls[] = $url;
            return null; // unexpected request → network error
        };
    }

    protected function tearDown(): void
    {
        Http::$mock = null;
    }

    // ------------------------------------------------------------------ helpers

    /** Answer requests by URL prefix (in-process). */
    private static function mock(array $map): void
    {
        Http::$mock = static function (string $m, string $url, array $headers) use ($map): ?array {
            self::$calls[] = $url . ' ' . implode(';', $headers);
            $best = null;
            $len = -1;
            foreach ($map as $prefix => $resp) {
                if (str_starts_with($url, $prefix) && strlen($prefix) > $len) {
                    [$best, $len] = [$resp, strlen($prefix)];
                }
            }
            if ($best === null) {
                return null;
            }
            return ['status' => $best[0], 'body' => is_string($best[1]) ? $best[1] : json_encode($best[1])];
        };
    }

    /** Store a feed value as if the task had fetched it. */
    private static function seed(string $p, array $params, array $data, int $owner = 0, ?int $fetched = null): int
    {
        $params = DataFeeds::cleanParams($p, $params);
        $fk = DataFeeds::feedKey($p, $params, $owner);
        DB::query('DELETE FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
        $t = $fetched ?? time();
        return DB::insert('data_feeds', ['feed_key' => $fk, 'provider' => $p, 'params' => json_encode($params), 'key_hotel_id' => $owner,
            'data' => json_encode($data), 'fetched_at' => $t, 'attempted_at' => $t, 'error_count' => 0, 'demand_at' => time(), 'created_at' => now()]);
    }

    private static function feedRow(string $p, array $params = [], int $owner = 0): ?array
    {
        return DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => DataFeeds::feedKey($p, DataFeeds::cleanParams($p, $params) ?? [], $owner)]);
    }

    private static function currencyData(): array
    {
        return ['base' => 'INR', 'rates' => ['USD' => 83.333333, 'EUR' => 90.5, 'GBP' => 105.25, 'AED' => 22.69, 'SAR' => 22.2], 'source_time' => time()];
    }

    private const CRIC = [
        'apikey' => 'x', 'status' => 'success', 'info' => ['hitsToday' => 5, 'hitsUsed' => 5, 'hitsLimit' => 100],
        'data' => [
            ['id' => 'm-ended', 'name' => 'Aus vs Eng, 1st Test', 'matchType' => 'test', 'status' => 'Eng won', 'venue' => 'Perth', 'teams' => ['Australia', 'England'],
                'score' => [], 'matchStarted' => true, 'matchEnded' => true, 'dateTimeGMT' => '2026-10-01T03:00:00'],
            ['id' => 'm-live', 'name' => 'India vs Australia, 2nd ODI', 'matchType' => 'odi', 'status' => 'Australia need 51 runs', 'venue' => 'Wankhede, Mumbai',
                'teams' => ['India', 'Australia'], 'teamInfo' => [['name' => 'India', 'shortname' => 'IND'], ['name' => 'Australia', 'shortname' => 'AUS']],
                'score' => [['r' => 300, 'w' => 7, 'o' => 50, 'inning' => 'India Inning 1'], ['r' => 250, 'w' => 4, 'o' => 40.3, 'inning' => 'Australia Inning 1']],
                'matchStarted' => true, 'matchEnded' => false, 'dateTimeGMT' => '2026-10-07T08:00:00'],
            ['id' => 'm-gt', 'name' => 'Gujarat Titans vs Mumbai', 'matchType' => 't20', 'status' => 'Match starts at 19:30', 'venue' => 'Ahmedabad',
                'teams' => ['Gujarat Titans', 'Mumbai Indians'], 'score' => [], 'matchStarted' => false, 'matchEnded' => false],
        ],
    ];

    // ------------------------------------------------------------------ providers

    public function testProvidersParseGoodJsonWithFixedHosts(): void
    {
        self::mock([
            'https://open.er-api.com/v6/latest/INR' => [200, ['result' => 'success', 'base_code' => 'INR', 'time_last_update_unix' => 1791331352, 'rates' => ['INR' => 1, 'USD' => 0.012, 'EUR' => 0.011049723756906, 'BAD' => 'x']]],
            'https://api.frankfurter.dev/v1/latest?base=INR' => [200, ['amount' => 1, 'base' => 'INR', 'date' => '2026-10-07', 'rates' => ['USD' => 0.0125, 'EUR' => 0.01]]],
            'https://www.goldapi.io/api/XAU/USD' => [200, ['timestamp' => 1791331352, 'metal' => 'XAU', 'currency' => 'USD', 'price' => 2488.0, 'prev_close_price' => 2450.0, 'price_gram_24k' => 80.0]],
            'https://www.goldapi.io/api/XAG/USD' => [200, ['timestamp' => 1791331352, 'metal' => 'XAG', 'currency' => 'USD', 'price' => 31.1034768, 'prev_close_price' => 31.0]],
            'https://api.twelvedata.com/quote' => [200, [
                'NSEI' => ['symbol' => 'NSEI', 'name' => 'NIFTY 50', 'close' => '24350.10', 'change' => '108.2', 'percent_change' => '0.4467', 'previous_close' => '24241.9', 'is_market_open' => true, 'timestamp' => 1791331352],
                'BSESN' => ['symbol' => 'BSESN', 'name' => 'S&P BSE SENSEX', 'close' => '80120.55', 'change' => '-96.1', 'percent_change' => '-0.1198', 'previous_close' => '80216.65'],
                'NSEBANK' => ['code' => 404, 'message' => 'symbol not found', 'status' => 'error'],
            ]],
            'https://api.cricapi.com/v1/currentMatches' => [200, self::CRIC],
            'https://api.aviationstack.com/v1/flights' => [200, ['pagination' => ['count' => 1], 'data' => [[
                'flight_date' => date('Y-m-d'), 'flight_status' => 'scheduled', 'airline' => ['name' => 'Air India'], 'flight' => ['iata' => 'AI101'],
                'departure' => ['airport' => 'Indira Gandhi International', 'iata' => 'DEL', 'terminal' => '3', 'gate' => '12B', 'delay' => 25, 'scheduled' => date('Y-m-d') . 'T14:05:00+00:00', 'estimated' => date('Y-m-d') . 'T14:30:00+00:00'],
                'arrival' => ['airport' => 'John F Kennedy International', 'iata' => 'JFK', 'baggage' => '5', 'scheduled' => date('Y-m-d') . 'T19:00:00+00:00'],
            ]]]],
        ]);

        $r = DataFeeds::fetch('open_er_api', [], '');
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertEqualsWithDelta(83.333333, $r['data']['rates']['USD'], 0.0001);
        $this->assertEqualsWithDelta(90.5, $r['data']['rates']['EUR'], 0.0001);
        $this->assertArrayNotHasKey('INR', $r['data']['rates']);
        $this->assertArrayNotHasKey('BAD', $r['data']['rates']);
        $this->assertSame(1791331352, $r['data']['source_time']);

        $r = DataFeeds::fetch('frankfurter', [], '');
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(80.0, $r['data']['rates']['USD'], 0.0001);

        $r = DataFeeds::fetch('goldapi', [], self::KEY);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(2, $r['requests']);
        $this->assertEqualsWithDelta(80.0, $r['data']['gold_usd_g'], 0.0001);
        $this->assertEqualsWithDelta(2450 / 31.1034768, $r['data']['gold_prev_usd_g'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $r['data']['silver_usd_g'], 0.0001);
        $this->assertStringContainsString('x-access-token: ' . self::KEY, implode("\n", self::$calls), 'goldapi key goes in the header');

        $r = DataFeeds::fetch('twelvedata', ['symbols' => ['NSEI', 'BSESN', 'NSEBANK']], self::KEY);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(['BSESN', 'NSEI'], array_keys($r['data']['quotes']), 'symbol errors are skipped');
        $this->assertSame(24350.10, $r['data']['quotes']['NSEI']['price']);
        $this->assertSame(0.45, $r['data']['quotes']['NSEI']['pct']);
        $this->assertSame(-0.12, $r['data']['quotes']['BSESN']['pct']);
        // single symbol = flat object
        self::mock(['https://api.twelvedata.com/quote' => [200, ['symbol' => 'RELIANCE', 'name' => 'Reliance', 'close' => '2950.5', 'percent_change' => '1.5']]]);
        $r = DataFeeds::fetch('twelvedata', ['symbols' => ['RELIANCE:NSE']], self::KEY);
        $this->assertTrue($r['ok']);
        $this->assertSame(2950.5, $r['data']['quotes']['RELIANCE:NSE']['price']);

        self::mock(['https://api.cricapi.com/v1/currentMatches' => [200, self::CRIC]]);
        $r = DataFeeds::fetch('cricapi', [], self::KEY);
        $this->assertTrue($r['ok']);
        $this->assertCount(3, $r['data']['matches']);
        $live = $r['data']['matches'][1];
        $this->assertSame(['m-live', 'odi', ['IND', 'AUS'], true, false], [$live['id'], $live['type'], $live['short'], $live['started'], $live['ended']]);
        $this->assertSame(['used' => 5, 'limit' => 100], $r['data']['hits']);
        $this->assertSame('m-live', CricketApp::pick($r['data']['matches'], ['mode' => 'live', 'team' => '', 'match_id' => ''])['id']);
        $this->assertSame('m-gt', CricketApp::pick($r['data']['matches'], ['mode' => 'team', 'team' => 'gujarat titans', 'match_id' => ''])['id']);
        $this->assertSame('m-ended', CricketApp::pick($r['data']['matches'], ['mode' => 'match', 'team' => '', 'match_id' => 'm-ended'])['id']);
        $this->assertNull(CricketApp::pick($r['data']['matches'], ['mode' => 'team', 'team' => 'Nepal', 'match_id' => '']));
        $this->assertSame(['need' => 51, 'balls' => 57, 'rrr' => 5.37], CricketApp::chase($live));
        $this->assertSame(243, CricketApp::balls(40.3));

        self::mock(['https://api.aviationstack.com/v1/flights' => [200, ['data' => [[
            'flight_date' => date('Y-m-d'), 'flight_status' => 'active', 'airline' => ['name' => 'Air India'],
            'departure' => ['iata' => 'DEL', 'gate' => '12B', 'terminal' => '3', 'scheduled' => '2026-10-07T14:05:00+00:00'], 'arrival' => ['iata' => 'JFK']]]]]]);
        $r = DataFeeds::fetch('aviationstack', ['flight' => 'ai 101'], self::KEY);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(['AI101', true, 'active', 'DEL', '12B', 'JFK'], [$r['data']['flight'], $r['data']['found'], $r['data']['status'], $r['data']['dep']['iata'], $r['data']['dep']['gate'], $r['data']['arr']['iata']]);
        $this->assertStringStartsWith('https://api.aviationstack.com/v1/flights?access_key=', self::$calls[array_key_last(self::$calls)]);

        // Every request went to the provider's fixed host.
        foreach (self::$calls as $c) {
            $host = parse_url(explode(' ', $c)[0], PHP_URL_HOST);
            $this->assertContains($host, array_column(DataFeeds::PROVIDERS, 'host'), $c);
        }
    }

    public function testDisallowedHostsAndParamsAreImpossible(): void
    {
        foreach (DataFeeds::PROVIDERS as $p => $def) {
            $params = match ($p) {
                'twelvedata' => ['symbols' => ['NSEI']],
                'aviationstack' => ['flight' => 'AI101'],
                default => [],
            };
            foreach (DataFeeds::requests($p, $params, 'k&x=1#@evil.com/') as [$url]) {
                $this->assertSame($def['host'], parse_url($url, PHP_URL_HOST), $p);
                $this->assertStringNotContainsString('@evil.com/', $url, 'the key is URL-encoded');
            }
        }
        foreach ([
            ['twelvedata', ['symbols' => ['NSEI&apikey=x']]],
            ['twelvedata', ['symbols' => ['http://evil.com/']]],
            ['twelvedata', ['symbols' => ['../../etc']]],
            ['twelvedata', ['symbols' => []]],
            ['twelvedata', ['symbols' => array_map(static fn ($i) => 'S' . $i, range(1, 13))]],
            ['aviationstack', ['flight' => 'AI101@evil.com']],
            ['aviationstack', ['flight' => 'https://evil.com']],
            ['nope', []],
        ] as [$p, $params]) {
            $this->assertNull(DataFeeds::cleanParams($p, $params), $p . ' ' . json_encode($params));
            try {
                DataFeeds::requests($p, $params, 'k');
                $this->fail('requests() must refuse ' . $p . ' ' . json_encode($params));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $r = DataFeeds::fetch('nope', [], 'k');
        $this->assertFalse($r['ok']);
        $this->assertSame([], self::$calls, 'no request for unknown providers / bad params');
        // Without an Http mock the test process never reaches the network.
        Http::$mock = null;
        $r = DataFeeds::fetch('open_er_api', [], '');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Network disabled', (string) $r['error']);
    }

    public function testErrorsAndRateLimitsKeepLastGoodValue(): void
    {
        DataFeeds::setPlatformKey('cricapi', self::KEY);
        DB::query('DELETE FROM data_feeds');
        DB::query('DELETE FROM rate_limits');
        self::mock(['https://api.cricapi.com/' => [200, self::CRIC]]);
        $st = DataFeeds::get('cricapi', [], true);
        $this->assertSame('ok', $st['status']);
        $this->assertCount(3, $st['data']['matches']);
        $this->assertFalse($st['stale']);
        $this->assertCount(1, self::$calls);
        // inline fetch only once: a second get() reads the stored value
        DataFeeds::flush();
        DataFeeds::get('cricapi', [], true);
        $this->assertCount(1, self::$calls);

        $ttl = DataFeeds::ttl('cricapi');
        $this->assertGreaterThanOrEqual(864, $ttl, 'TTL stretched to the daily cap (100/day)');
        $age = static fn () => DB::query("UPDATE data_feeds SET fetched_at = :t, retry_at = NULL WHERE provider = 'cricapi'", ['t' => time() - $ttl - 5]);

        // 429 → last good value kept, stale, long back-off
        $age();
        self::mock(['https://api.cricapi.com/' => [429, '{"status":"failure","reason":"Too many requests"}']]);
        $out = DataFeeds::refreshDue();
        $this->assertSame(1, $out['failed']);
        DataFeeds::flush();
        $st = DataFeeds::get('cricapi');
        $this->assertSame('stale', $st['status']);
        $this->assertTrue($st['stale']);
        $this->assertCount(3, $st['data']['matches'], 'last good value kept');
        $this->assertStringContainsString('Rate limit', (string) $st['error']);
        $row = self::feedRow('cricapi');
        $this->assertGreaterThanOrEqual(time() + 1700, (int) $row['retry_at']);
        $this->assertFalse(DataFeeds::due($row), 'no retry before the back-off');

        // provider failure that mentions the key → key scrubbed; quota words → rate limited
        $age();
        self::mock(['https://api.cricapi.com/' => [200, ['status' => 'failure', 'reason' => 'Daily hits limit reached for key ' . self::KEY]]]);
        DataFeeds::refreshDue();
        $row = self::feedRow('cricapi');
        $this->assertStringContainsString('***', (string) $row['error']);
        $this->assertStringNotContainsString(self::KEY, json_encode($row));
        $this->assertSame(2, (int) $row['error_count']);

        // network error / bad JSON → short back-off, value kept
        $age();
        self::mock([]);
        DataFeeds::refreshDue();
        $row = self::feedRow('cricapi');
        $this->assertSame('Network error', $row['error']);
        $this->assertNotNull($row['data']);
        $age();
        self::mock(['https://api.cricapi.com/' => [200, '<html>oops</html>']]);
        DataFeeds::refreshDue();
        $this->assertStringContainsString('Invalid response', (string) self::feedRow('cricapi')['error']);

        // recovery clears the error
        $age();
        self::mock(['https://api.cricapi.com/' => [200, self::CRIC]]);
        DataFeeds::refreshDue();
        DataFeeds::flush();
        $st = DataFeeds::get('cricapi');
        $this->assertSame(['ok', false, null], [$st['status'], $st['stale'], $st['error']]);

        // Other providers' error formats.
        foreach ([
            ['open_er_api', [], 'https://open.er-api.com/', [200, ['result' => 'error', 'error-type' => 'unsupported-code']], 'unsupported-code', false],
            ['goldapi', [], 'https://www.goldapi.io/', [403, ['error' => 'Monthly quota exceeded']], 'quota', true],
            ['twelvedata', ['symbols' => ['NSEI']], 'https://api.twelvedata.com/', [200, ['code' => 429, 'message' => 'You have run out of API credits for the current minute.', 'status' => 'error']], 'credits', true],
            ['aviationstack', ['flight' => 'AI101'], 'https://api.aviationstack.com/', [200, ['error' => ['code' => 'usage_limit_reached', 'message' => 'Your monthly usage limit has been reached. [Technical Support: support@apilayer.com]']]], 'usage limit', true],
            ['aviationstack', ['flight' => 'AI101'], 'https://api.aviationstack.com/', [200, ['error' => ['code' => 'invalid_access_key', 'message' => 'You have not supplied a valid API Access Key.']]], 'valid API Access Key', false],
        ] as [$p, $params, $prefix, $resp, $needle, $limited]) {
            self::mock([$prefix => $resp]);
            $r = DataFeeds::fetch($p, $params, self::KEY);
            $this->assertFalse($r['ok'], $p);
            $this->assertStringContainsString($needle, (string) $r['error'], $p);
            $this->assertSame($limited, $r['rate_limited'], $p . ' rate limited');
            $this->assertStringNotContainsString('apilayer', (string) $r['error']);
        }
        DataFeeds::setPlatformKey('cricapi', '');
    }

    public function testBackgroundTaskRespectsTtlAndBudget(): void
    {
        $this->assertArrayHasKey('DataFeedsTask', Scheduler::tasks());
        $this->assertSame(60, (new DataFeedsTask())->interval());
        DB::query('DELETE FROM data_feeds');
        DB::query('DELETE FROM rate_limits');
        $good = ['result' => 'success', 'rates' => ['USD' => 0.012, 'EUR' => 0.011]];
        self::mock(['https://open.er-api.com/' => [200, $good], 'https://api.frankfurter.dev/' => [200, ['rates' => ['USD' => 0.012], 'date' => '2026-10-07']]]);

        // Fresh value → nothing to do.
        self::seed('open_er_api', [], self::currencyData(), 0, time() - 60);
        $out = (new DataFeedsTask())->run();
        $this->assertSame(0, $out['due']);
        $this->assertSame([], self::$calls, 'TTL respected: no request');

        // TTL over → exactly one request.
        DB::query("UPDATE data_feeds SET fetched_at = :t WHERE provider = 'open_er_api'", ['t' => time() - 3700]);
        $out = (new DataFeedsTask())->run();
        $this->assertSame(1, $out['refreshed']);
        $this->assertCount(1, self::$calls);
        $this->assertEqualsWithDelta(83.3333, DataFeeds::currency()['data']['rates']['USD'], 0.001);
        $out = (new DataFeedsTask())->run();
        $this->assertSame(0, $out['due']);
        $this->assertCount(1, self::$calls);

        // Feeds nobody used for a day are not refreshed; 30 days → forgotten.
        DB::query("UPDATE data_feeds SET fetched_at = :t, demand_at = :d WHERE provider = 'open_er_api'", ['t' => time() - 7200, 'd' => time() - 90000]);
        (new DataFeedsTask())->run();
        $this->assertCount(1, self::$calls);
        DB::query("UPDATE data_feeds SET demand_at = :d WHERE provider = 'open_er_api'", ['d' => time() - 31 * 86400]);
        (new DataFeedsTask())->run();
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM data_feeds'));

        // Per-minute budget: two due feeds, budget 1 → one request, the other waits.
        Settings::setPlatform('platform_feeds_per_min', '1');
        self::seed('open_er_api', [], self::currencyData(), 0, time() - 7200);
        self::seed('frankfurter', [], self::currencyData(), 0, time() - 7200);
        DB::query('DELETE FROM rate_limits');
        self::$calls = [];
        $out = (new DataFeedsTask())->run();
        $this->assertSame(['due' => 2, 'refreshed' => 1, 'failed' => 0, 'skipped' => 1], $out);
        $this->assertCount(1, self::$calls);
        Settings::setPlatform('platform_feeds_per_min', '20');

        // Daily cap per provider (goldapi needs 2 requests per refresh).
        DataFeeds::setPlatformKey('goldapi', self::KEY);
        Settings::setPlatform('platform_feed_cap_goldapi', '3');
        $this->assertSame(57600, DataFeeds::ttl('goldapi'), '86400 × 2 / 3');
        DB::query('DELETE FROM rate_limits');
        $this->assertTrue(DataFeeds::budget('goldapi', 0));
        $this->assertFalse(DataFeeds::budget('goldapi', 0), '2 + 2 > 3');
        $this->assertTrue(DataFeeds::budget('goldapi', 5), 'a hotel\'s own key has its own cap');
        Settings::setPlatform('platform_feed_cap_goldapi', '6');
        DataFeeds::setPlatformKey('goldapi', '');
        DB::query('DELETE FROM rate_limits');
    }

    // ------------------------------------------------------------------ keys

    public function testKeysEncryptedAtRestHotelOverrideAndNeverExposed(): void
    {
        DataFeeds::setPlatformKey('twelvedata', self::KEY);
        $raw = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_feedkey_twelvedata'");
        $this->assertStringStartsWith('enc:', $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertSame(self::KEY, DataFeeds::platformKey('twelvedata'));
        $this->assertSame([self::KEY, 0], DataFeeds::keyFor('twelvedata'));

        DataFeeds::setHotelKey('twelvedata', 'HOTEL-OWN-KEY-777');
        $raw = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'feedkey_twelvedata'");
        $this->assertStringStartsWith('enc:', $raw);
        $this->assertSame(['HOTEL-OWN-KEY-777', 1], DataFeeds::keyFor('twelvedata'));
        $this->assertSame([self::KEY, 0], DataFeeds::keyFor('twelvedata', 2), 'hotel 2 uses the platform key');
        DataFeeds::setHotelKey('twelvedata', '');
        $this->assertSame([self::KEY, 0], DataFeeds::keyFor('twelvedata'));
        DataFeeds::setPlatformKey('open_er_api', 'ignored'); // key-less providers have no key
        $this->assertSame('', (string) Settings::platform('platform_feedkey_open_er_api', ''));

        // Widget page, data JSON and feed rows never contain the key.
        self::seed('twelvedata', ['symbols' => ['NSEI', 'BSESN', 'NSEBANK']], ['quotes' => ['NSEI' => ['name' => 'NIFTY 50', 'price' => 24350.1, 'change' => 10.0, 'pct' => 0.45, 'prev' => null, 'open' => true, 'time' => null]]]);
        $item = DisplayAppsTestKit::createItem('market', ['source' => 'auto']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('24,350.10', $html);
        $this->assertStringContainsString('24,350.10', (string) $json['data']['html']);
        foreach ([$html, json_encode($json), json_encode(DB::all('SELECT * FROM data_feeds'))] as $out) {
            $this->assertStringNotContainsString(self::KEY, $out);
            $this->assertStringNotContainsString('apikey', $out);
        }
        // Admin pages show "Key saved", never the key.
        $root = new AdminSession(self::$url, 'dfRoot');
        [$code, , $page] = $root->get('platform_settings.php?tab=data_feeds');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($page));
        $this->assertStringContainsString('Key saved', $page);
        $this->assertStringNotContainsString(self::KEY, $page);
        $boss = new AdminSession(self::$url, 'dfBoss');
        [$code, , $page] = $boss->get('data_feeds.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($page));
        $this->assertStringNotContainsString(self::KEY, $page);
        ContentManager::deleteItem((int) $item['id']);
        DataFeeds::setPlatformKey('twelvedata', '');
    }

    public function testPlatformTabAndHotelKeyPage(): void
    {
        $root = new AdminSession(self::$url, 'dfRoot');
        [$code] = $root->post('platform_settings.php', ['op' => 'data_feeds', 'tab' => 'data_feeds', 'feed_currency_provider' => 'frankfurter', 'feeds_per_min' => '15',
            'feedkey_cricapi' => 'CRIC-KEY-1234567', 'feed_ttl_cricapi' => '5', 'feed_cap_cricapi' => '200', 'feed_idx_nifty' => 'nsei', 'feed_idx_sensex' => 'BAD SYMBOL!']);
        $this->assertSame(302, $code);
        Settings::flush();
        $this->assertSame('frankfurter', DataFeeds::currencyProvider());
        $this->assertSame(15, DataFeeds::perMinute());
        $this->assertSame('CRIC-KEY-1234567', DataFeeds::platformKey('cricapi'));
        $this->assertStringStartsWith('enc:', (string) Settings::platform('platform_feedkey_cricapi'));
        $this->assertSame('30', (string) Settings::platform('platform_feed_ttl_cricapi'), 'min TTL enforced');
        $this->assertSame(200, DataFeeds::dailyCap('cricapi'));
        $this->assertSame('NSEI', DataFeeds::indexSymbol('nifty'));
        $this->assertSame('BSESN', DataFeeds::indexSymbol('sensex'), 'invalid symbol not saved');
        // empty key field keeps the key; bad key refused; clear removes
        $root->post('platform_settings.php', ['op' => 'data_feeds', 'tab' => 'data_feeds', 'feedkey_cricapi' => '', 'feedkey_goldapi' => 'short']);
        Settings::flush();
        $this->assertSame('CRIC-KEY-1234567', DataFeeds::platformKey('cricapi'));
        $this->assertSame('', DataFeeds::platformKey('goldapi'));
        $root->post('platform_settings.php', ['op' => 'data_feeds', 'tab' => 'data_feeds', 'feedkey_clear_cricapi' => '1', 'feed_currency_provider' => 'open_er_api']);
        Settings::flush();
        $this->assertSame('', DataFeeds::platformKey('cricapi'));
        // hotel users cannot open the platform tab
        $boss = new AdminSession(self::$url, 'dfBoss');
        [$code] = $boss->get('platform_settings.php?tab=data_feeds');
        $this->assertContains($code, [403, 404]);

        // Hotel's own key (super admin only), CSRF.
        [$code] = $boss->post('data_feeds.php', ['op' => 'key', 'provider' => 'goldapi', 'api_key' => 'HOTEL-GOLD-KEY-99']);
        $this->assertSame(302, $code);
        Settings::flush();
        $this->assertSame('HOTEL-GOLD-KEY-99', DataFeeds::hotelKey('goldapi', 1));
        $this->assertStringStartsWith('enc:', (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'feedkey_goldapi'"));
        $this->assertSame('', DataFeeds::hotelKey('goldapi', 2), 'per hotel');
        [, , $page] = $boss->get('data_feeds.php');
        $this->assertStringContainsString('Your own key', $page);
        $this->assertStringNotContainsString('HOTEL-GOLD-KEY-99', $page);
        [$code] = TestEnv::http('POST', self::$url . 'admin/data_feeds.php', null, [], $boss->jar, ['op' => 'key', 'provider' => 'goldapi', 'clear' => '1']);
        $this->assertSame(419, $code);
        $boss->post('data_feeds.php', ['op' => 'key', 'provider' => 'goldapi', 'clear' => '1']);
        Settings::flush();
        $this->assertSame('', DataFeeds::hotelKey('goldapi', 1));
        $mgr = new AdminSession(self::$url, 'dfMgr');
        [$code] = $mgr->get('data_feeds.php');
        $this->assertSame(403, $code);
    }

    // ------------------------------------------------------------------ rates page

    public function testRatesPageCrudHistoryTenancyXss(): void
    {
        DB::query('DELETE FROM metal_rates WHERE hotel_id = 1');
        $s = new AdminSession(self::$url, 'dfStaff');
        [$code, , $html] = $s->get('rates.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('No rates saved yet.', $html);

        [$code] = $s->post('rates.php', ['op' => 'save', 'gold_24k' => '72,000', 'gold_22k' => '66000', 'gold_18k' => '', 'silver_kg' => '88000.50', 'note' => 'Making 8%']);
        $this->assertSame(302, $code);
        [$code] = $s->post('rates.php', ['op' => 'save', 'gold_24k' => '72500', 'gold_22k' => '65800', 'gold_18k' => '54000', 'silver_kg' => '88000.50', 'note' => self::XSS]);
        $this->assertSame(302, $code);
        $rows = DB::all('SELECT * FROM metal_rates WHERE hotel_id = 1 ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame(['72000.00', '66000.00', null], [$rows[0]['gold_24k'], $rows[0]['gold_22k'], $rows[0]['gold_18k']]);
        $this->assertSame((int) self::$id['dfStaff'], (int) $rows[1]['created_by']);

        [, , $html] = $s->get('rates.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        $this->assertStringContainsString('value="72500"', $html, 'form prefilled with the latest rates');
        $this->assertStringContainsString('₹ 72,000', $html, 'history');
        $this->assertStringNotContainsString('H2-RATE-SECRET', $html);

        // Validation → 422, nothing saved.
        foreach ([['gold_24k' => '-5'], ['gold_24k' => 'abc'], ['gold_24k' => '', 'gold_22k' => '', 'gold_18k' => '', 'silver_kg' => ''], ['silver_kg' => '999999999999']] as $bad) {
            [$code, , $html] = $s->post('rates.php', ['op' => 'save'] + $bad);
            $this->assertSame(422, $code, json_encode($bad));
        }
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM metal_rates WHERE hotel_id = 1'));

        // TV board: current rates, ▲ / ▼ against the previous entry, note escaped.
        $item = DisplayAppsTestKit::createItem('gold_rates', ['heading' => self::XSS], ['lang' => 'gu']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('₹ 72,500', $html);
        $this->assertStringContainsString('₹ 88,001', $html);
        $this->assertMatchesRegularExpression('/72,500 <span class="df-up">/', $html);
        $this->assertMatchesRegularExpression('/65,800 <span class="df-down">/', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('H2-RATE-SECRET', $html);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringContainsString('72,500', (string) $json['data']['html']);
        $this->assertStringNotContainsString('<script>alert(1)', (string) $json['data']['html']);

        // CSRF, permissions, tenancy.
        [$code] = TestEnv::http('POST', self::$url . 'admin/rates.php', null, [], $s->jar, ['op' => 'save', 'gold_24k' => '1']);
        $this->assertSame(419, $code);
        $r = new AdminSession(self::$url, 'dfRecep');
        [$code] = $r->get('rates.php');
        $this->assertSame(403, $code);
        [$code] = $s->post('rates.php', ['op' => 'mode', 'rates_mode' => 'auto']);
        $this->assertSame(403, $code, 'mode needs content.manage (manager)');
        $this->assertSame('manual', MetalRates::mode());
        [$code] = $s->post('rates.php', ['op' => 'delete', 'id' => self::$id['h2rate']]);
        $this->assertContains($code, [302, 404]);
        $this->assertNotNull(DB::one('SELECT id FROM metal_rates WHERE id = :id', ['id' => self::$id['h2rate']]), 'other hotel\'s entry untouched');
        $b2 = new AdminSession(self::$url, 'dfBoss2');
        [, , $html] = $b2->get('rates.php');
        $this->assertStringNotContainsString('72,500', $html);
        $this->assertStringContainsString('99,999', $html);

        // Delete the newest entry → the previous one is current again.
        [$code] = $s->post('rates.php', ['op' => 'delete', 'id' => $rows[1]['id']]);
        $this->assertSame(302, $code);
        Settings::flush();
        $this->assertSame(72000.0, MetalRates::current()['values']['gold_24k']);

        // Manual market values.
        [$code, , $html] = $s->post('rates.php', ['op' => 'market', 'tab' => 'market', 'market' => "NIFTY 50 | 24,350.10 | +0.45\nnot a line"]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Line 2', $html);
        [$code] = $s->post('rates.php', ['op' => 'market', 'tab' => 'market', 'market' => "NIFTY 50 | 24,350.10 | +0.45\nSENSEX | 80120.55 | -0.12%\n" . self::XSS . ' | 5 | 1']);
        $this->assertSame(302, $code);
        [, , $html] = $s->get('rates.php?tab=market');
        $this->assertStringContainsString('24,350.10', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);

        // Manager switches the mode; auto without a key falls back to the manual rates.
        $m = new AdminSession(self::$url, 'dfMgr');
        [$code] = $m->post('rates.php', ['op' => 'mode', 'rates_mode' => 'auto', 'rates_duty_pct' => '15', 'rates_markup_pct' => '200']);
        $this->assertSame(302, $code);
        Settings::flush();
        $this->assertSame(['auto', 15.0, 100.0], [MetalRates::mode(), MetalRates::dutyPct(), MetalRates::markupPct()]);
        $c = MetalRates::current();
        $this->assertSame(['manual', 'no_key', 72000.0], [$c['source'], $c['status'], $c['values']['gold_24k']]);
        Settings::set('rates_mode', 'manual');
        ContentManager::deleteItem((int) $item['id']);
    }

    public function testAutoGoldRatesAreIndicative(): void
    {
        DataFeeds::setPlatformKey('goldapi', self::KEY);
        Settings::set('rates_mode', 'auto');
        Settings::set('rates_duty_pct', '10');
        Settings::set('rates_markup_pct', '0');
        self::seed(DataFeeds::currencyProvider(), [], self::currencyData());
        self::seed('goldapi', [], ['gold_usd_g' => 80.0, 'gold_prev_usd_g' => 81.0, 'silver_usd_g' => 1.0, 'silver_prev_usd_g' => 0.9, 'source_time' => time()]);
        DataFeeds::flush();
        $c = MetalRates::current();
        $this->assertSame('auto', $c['source']);
        $this->assertTrue($c['indicative']);
        // 80 $/g × 10 g × 83.333333 × 1.10 = 73,333
        $this->assertEqualsWithDelta(73333, $c['values']['gold_24k'], 1);
        $this->assertEqualsWithDelta(73333 * 22 / 24, $c['values']['gold_22k'], 1);
        $this->assertEqualsWithDelta(1000 * 83.333333 * 1.1, $c['values']['silver_kg'], 1);
        $this->assertSame('down', MetalRates::trend($c['values']['gold_24k'], $c['prev']['gold_24k']));
        $this->assertSame('up', MetalRates::trend($c['values']['silver_kg'], $c['prev']['silver_kg']));
        $item = DisplayAppsTestKit::createItem('gold_rates');
        $html = DisplayAppsTestKit::renderInProcess($item);
        $this->assertStringContainsString('Indicative rates', $html);
        $this->assertStringContainsString('73,333', $html);
        $this->assertSame([], self::$calls);
        ContentManager::deleteItem((int) $item['id']);
        Settings::set('rates_mode', 'manual');
        DataFeeds::setPlatformKey('goldapi', '');
    }

    // ------------------------------------------------------------------ manual mode, widgets

    public function testManualModeWorksWithoutAnyKey(): void
    {
        foreach (array_keys(DataFeeds::PROVIDERS) as $p) {
            DataFeeds::setPlatformKey($p, '');
        }
        DB::query('DELETE FROM data_feeds');
        self::seed(DataFeeds::currencyProvider(), [], self::currencyData()); // free provider, no key
        Settings::set('rates_market_manual', "NIFTY 50 | 24,350.10 | +0.45\nSENSEX | 80,120.55 | -0.12\nGold ETF | 61.2");
        MetalRates::save(['gold_24k' => 71000, 'gold_22k' => null, 'gold_18k' => null, 'silver_kg' => 90000, 'note' => 'GST extra']);

        $this->assertSame('no_key', DataFeeds::get('cricapi')['status']);
        $this->assertSame('no_key', DataFeeds::get('twelvedata', ['symbols' => ['NSEI']])['status']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM data_feeds'), 'no feed row without a key');

        $html = DisplayAppsTestKit::renderInProcess($i1 = DisplayAppsTestKit::createItem('market', ['source' => 'auto', 'layout' => 'ticker']));
        $this->assertStringContainsString('24,350.10', $html);
        $this->assertStringContainsString('mk-track', $html);
        $this->assertStringContainsString('Delayed / indicative', $html);
        $this->assertMatchesRegularExpression('/df-down.*80,120.55/s', $html);

        $html = DisplayAppsTestKit::renderInProcess($i2 = DisplayAppsTestKit::createItem('cricket', ['mode' => 'manual', 'm_team1' => 'India', 'm_score1' => '245/6 (45.2)', 'm_team2' => self::XSS, 'm_status' => 'India batting']));
        $this->assertStringContainsString('245/6 (45.2)', $html);
        $this->assertStringContainsString('India batting', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        // automatic mode without a key → manual values too
        $html = DisplayAppsTestKit::renderInProcess($i3 = DisplayAppsTestKit::createItem('cricket', ['mode' => 'live', 'm_team1' => 'Gujarat', 'm_score1' => '180/4']));
        $this->assertStringContainsString('180/4', $html);

        $html = DisplayAppsTestKit::renderInProcess($i4 = DisplayAppsTestKit::createItem('travel_status', ['flights' => 'AI101', 'manual' => "train | 12902 | Ahmedabad → Mumbai | 21:40 | 21:55 | Delayed | PF 3\nflight | " . self::XSS . ' | DEL → BOM | 10:00']));
        $this->assertStringContainsString('12902', $html);
        $this->assertStringContainsString('tv-warn', $html);
        $this->assertStringContainsString('PF 3', $html);
        $this->assertStringContainsString('Not available', $html, 'tracked flight without a key');
        $this->assertStringNotContainsString('<script>alert(1)', $html);

        $html = DisplayAppsTestKit::renderInProcess($i5 = DisplayAppsTestKit::createItem('currency', ['currencies' => ['USD', 'AED'], 'overrides' => 'USD | 83.10 | 84.20']));
        $this->assertStringContainsString('₹ 83.10', $html);
        $this->assertStringContainsString('₹ 84.20', $html);
        $this->assertStringContainsString('🇺🇸', $html);

        $html = DisplayAppsTestKit::renderInProcess($i6 = DisplayAppsTestKit::createItem('gold_rates', ['show' => ['gold_24k', 'silver_kg']]));
        $this->assertStringContainsString('₹ 71,000', $html);
        $this->assertStringContainsString('GST extra', $html);
        $this->assertStringNotContainsString('Gold 22K', $html);

        $this->assertSame([], self::$calls, 'manual mode makes no request');
        foreach ([$i1, $i2, $i3, $i4, $i5, $i6] as $i) {
            ContentManager::deleteItem((int) $i['id']);
        }
    }

    public function testWidgetValidation(): void
    {
        $m = DisplayApps::find('market');
        [$c, $e] = $m->validate(['source' => 'auto', 'indices' => ['nifty', 'bogus'], 'symbols' => "Reliance | reliance:nse\nTCS | TCS:NSE", 'layout' => 'ticker']);
        $this->assertSame([], $e);
        $this->assertSame(['nifty'], $c['indices']);
        $this->assertSame([['Reliance', 'RELIANCE:NSE'], ['TCS', 'TCS:NSE']], MarketApp::parseSymbols($c['symbols']));
        [, $e] = $m->validate(['symbols' => "Bad | http://evil.com/?x=1\n" . str_repeat("A | AA\n", 10)]);
        $this->assertCount(2, $e);

        $cx = DisplayApps::find('currency');
        [$c, $e] = $cx->validate(['currencies' => ['USD', 'XXX', 'EUR'], 'buy_margin' => '2.5', 'sell_margin' => '99', 'overrides' => "EUR | 90 | 91\n", 'buy_sell' => '1']);
        $this->assertSame([], $e);
        $this->assertSame([['USD', 'EUR'], 2.5, 20.0], [$c['currencies'], $c['buy_margin'], $c['sell_margin']]);
        [, $e] = $cx->validate(['currencies' => [], 'overrides' => "ZZZ | 1\nUSD | -3"]);
        $this->assertCount(3, $e);

        $t = DisplayApps::find('travel_status');
        [$c, $e] = $t->validate(['flights' => "ai101, 6E2134\nUK 955", 'manual' => '']);
        $this->assertNotSame([], $e, '"UK" alone is not a flight');
        $this->assertSame(['AI101', '6E2134'], TravelStatusApp::parseFlights('ai101, 6E2134 AI101'));
        [, $e] = $t->validate(['manual' => 'only | two']);
        $this->assertCount(1, $e);

        $ck = DisplayApps::find('cricket');
        [, $e] = $ck->validate(['mode' => 'team', 'team' => '']);
        $this->assertCount(1, $e);
        [, $e] = $ck->validate(['mode' => 'match', 'match_id' => 'x"><script>']);
        $this->assertCount(2, $e);
        [, $e] = $ck->validate(['mode' => 'manual']);
        $this->assertCount(1, $e);

        $g = DisplayApps::find('gold_rates');
        [$c, $e] = $g->validate(['show' => ['gold_24k', 'nope']]);
        $this->assertSame([['gold_24k'], []], [$c['show'], $e]);
        [, $e] = $g->validate([]);
        $this->assertCount(1, $e);
        $this->assertSame(admin_url('rates.php'), $g->adminPage());

        $this->assertSame('12,34,567.50', DataFeeds::inr(1234567.5));
        $this->assertSame('999', DataFeeds::inr(999, 0));
        $this->assertSame('-1,00,000', DataFeeds::inr(-100000, 0));
        $this->assertSame('+0.45%', DataFeeds::pct(0.45));
        $this->assertSame('−1.20%', DataFeeds::pct(-1.2));
        $this->assertSame('🇪🇺', DataFeeds::flag('EU'));
    }

    public function testFeedWidgetsOverHttpWithStoredValues(): void
    {
        DataFeeds::setPlatformKey('cricapi', self::KEY);
        DataFeeds::setPlatformKey('aviationstack', self::KEY);
        self::seed(DataFeeds::currencyProvider(), [], self::currencyData());
        self::mock(['https://api.cricapi.com/' => [200, self::CRIC]]);
        self::seed('cricapi', [], DataFeeds::fetch('cricapi', [], self::KEY)['data'], 0, time() - 2 * 86400);
        DB::query("UPDATE data_feeds SET error_count = 1, error = 'Rate limit reached (HTTP 429)' WHERE provider = 'cricapi'");
        self::mock(['https://api.aviationstack.com/' => [200, ['data' => [[
            'flight_date' => date('Y-m-d'), 'flight_status' => 'scheduled', 'airline' => ['name' => 'Air India'],
            'departure' => ['iata' => 'DEL', 'gate' => '12B', 'terminal' => '3', 'delay' => 30, 'scheduled' => date('Y-m-d') . 'T14:05:00+05:30', 'estimated' => date('Y-m-d') . 'T14:35:00+05:30'], 'arrival' => ['iata' => 'JFK']]]]]]);
        self::seed('aviationstack', ['flight' => 'AI101'], DataFeeds::fetch('aviationstack', ['flight' => 'AI101'], self::KEY)['data']);
        self::$calls = [];
        Http::$mock = null;

        $items = [
            'cricket' => DisplayAppsTestKit::createItem('cricket', ['mode' => 'team', 'team' => 'India', 'heading' => self::XSS], ['lang' => 'hi']),
            'currency' => DisplayAppsTestKit::createItem('currency', ['currencies' => ['USD', 'EUR', 'AED'], 'buy_margin' => 1, 'sell_margin' => 2, 'subtitle' => self::XSS]),
            'travel_status' => DisplayAppsTestKit::createItem('travel_status', ['flights' => 'AI101, 6E2134'], ['lang' => 'gu']),
        ];
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $items['cricket']);
        $this->assertStringContainsString('300/7', $html);
        $this->assertStringContainsString('250/4', $html);
        $this->assertStringContainsString('IND', $html);
        $this->assertStringContainsString('ck-live', $html);
        $this->assertStringContainsString('df-stale', $html, 'old value after a rate limit is marked');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $items['cricket']);
        $this->assertSame([200, 30], [$code, $json['refresh_sec']]);
        $this->assertStringContainsString('51', (string) $json['data']['html']);

        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $items['currency']);
        $this->assertStringContainsString('₹ 82.50', $html, 'buy = 83.33 − 1 %');
        $this->assertStringContainsString('₹ 85.00', $html, 'sell = 83.33 + 2 %');
        $this->assertStringContainsString('₹ 22.46', $html);
        $this->assertStringContainsString('Indicative rates', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        [, $json] = DisplayAppsTestKit::data(self::$url, $items['currency']);
        $this->assertSame(300, $json['refresh_sec']);

        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $items['travel_status']);
        $this->assertStringContainsString('DEL → JFK', $html);
        $this->assertStringContainsString('12B', $html);
        $this->assertStringContainsString('tv-warn', $html, 'delay ≥ 15 min → delayed');
        $this->assertStringContainsString('6E2134', $html);
        foreach ($items as $it) {
            [, $json] = DisplayAppsTestKit::data(self::$url, $it);
            $this->assertStringNotContainsString(self::KEY, json_encode($json));
            ContentManager::deleteItem((int) $it['id']);
        }
        $this->assertSame([], self::$calls);
        $this->assertSame('', TestEnv::phpErrors());
        DataFeeds::setPlatformKey('cricapi', '');
        DataFeeds::setPlatformKey('aviationstack', '');
    }

    // ------------------------------------------------------------------ ticker placeholders

    public function testTickerPlaceholdersResolveSafely(): void
    {
        foreach (array_keys(DataFeeds::PROVIDERS) as $p) {
            DataFeeds::setPlatformKey($p, '');
        }
        DB::query('DELETE FROM data_feeds');
        DB::query('DELETE FROM metal_rates WHERE hotel_id = 1');
        Settings::set('rates_market_manual', "NIFTY 50 | 24,350.10 | +0.45\nSensex | 80120.55 | -0.12\nBANK NIFTY | {gold_24k}\x07 | 1");
        Settings::set('rates_mode', 'manual');
        [$data, $err] = Tickers::validate(['message' => 'Gold {gold_24k} · 22K {gold_22k} · Silver {silver} · $ {usd_inr} · € {eur_inr} · Nifty {nifty} · Sensex {sensex} · {banknifty} · {unknown} {GOLD_24K} {{usd_inr}}', 'target_type' => 'all', 'is_active' => '1']);
        $this->assertSame([], $err);
        $tid = Tickers::save(null, $data);
        $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['room']]);

        // Feeds missing: known placeholders become —, unknown ones stay, nothing breaks.
        $t = ContentResolver::forRoom($room)['overlay']['ticker'];
        $this->assertStringContainsString('Gold — · 22K —', $t['text']);
        $this->assertStringContainsString('$ —', $t['text']);
        $this->assertStringContainsString('Nifty 24,350.10 (+0.45%)', $t['text'], 'manual market values');
        $this->assertStringContainsString('Sensex 80,120.55 (−0.12%)', $t['text']);
        $this->assertStringContainsString('{unknown} {GOLD_24K}', $t['text']);
        $this->assertStringContainsString('{—}', $t['text'], 'single pass, no recursion');
        $this->assertSame($t['text'], implode(Tickers::SEPARATOR, $t['messages']));
        $this->assertSame([], self::$calls, 'placeholders never fetch inline');

        // Values present → resolved; content hash changes when a value changes.
        self::seed(DataFeeds::currencyProvider(), [], self::currencyData());
        MetalRates::save(['gold_24k' => 72500, 'gold_22k' => 66450.5, 'gold_18k' => null, 'silver_kg' => 88000, 'note' => '']);
        $c1 = ContentResolver::forRoom($room);
        $this->assertStringContainsString('Gold ₹72,500 · 22K ₹66,451 · Silver ₹88,000', $c1['overlay']['ticker']['text']);
        $this->assertStringContainsString('$ ₹83.33 · € ₹90.50', $c1['overlay']['ticker']['text']);
        $this->assertSame($c1['hash'], ContentResolver::forRoom($room)['hash'], 'cached while nothing changes');
        MetalRates::save(['gold_24k' => 73000, 'gold_22k' => null, 'gold_18k' => null, 'silver_kg' => null, 'note' => '']);
        $c2 = ContentResolver::forRoom($room);
        $this->assertStringContainsString('Gold ₹73,000', $c2['overlay']['ticker']['text']);
        $this->assertNotSame($c1['hash'], $c2['hash']);
        // The banknifty label with a control char / braces is cleaned.
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $c2['overlay']['ticker']['text']);

        // Over HTTP: the TV gets the resolved ticker.
        $this->assertSame('₹73,000', DataFeeds::applyPlaceholders('{gold_24k}'));
        $this->assertSame('no braces', DataFeeds::applyPlaceholders('no braces'));
        $this->assertNull(DataFeeds::applyToTicker(null));

        // Ticker form mentions the placeholders.
        $s = new AdminSession(self::$url, 'dfStaff');
        [$code, , $html] = $s->get('tickers.php?action=new');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('{gold_24k}', $html);
        Tickers::delete($tid);
    }

    public function testEveryFeedWidgetRendersWithDefaultsInEveryLanguage(): void
    {
        foreach (['gold_rates', 'market', 'cricket', 'currency', 'travel_status'] as $key) {
            foreach (['en', 'gu', 'hi'] as $lang) {
                $item = DisplayAppsTestKit::createItem($key, [], ['lang' => $lang]);
                $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item, ['preview' => 1]);
                $this->assertStringContainsString('feeds.css', $html);
                $this->assertStringContainsString('id="dfBody"', $html);
                [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
                $this->assertSame(200, $code);
                $this->assertIsString($json['data']['html'] ?? null, $key);
                ContentManager::deleteItem((int) $item['id']);
            }
        }
        // Admin form of every widget renders (apps.php) for a manager.
        $m = new AdminSession(self::$url, 'dfMgr');
        foreach (['gold_rates', 'market', 'cricket', 'currency', 'travel_status'] as $key) {
            [$code, , $html] = $m->get('apps.php?action=new&app=' . $key);
            $this->assertSame(200, $code, $key);
            $this->assertFalse(TestEnv::hasPhpError($html), $key);
            $this->assertStringContainsString('name="cfg[heading]"', $html);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }
}
