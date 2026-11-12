<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.4 platform credit line (core/PlatformCredit.php): on by default with Gujarati + English demo text; reaches
 * the TV poll JSON at the end of the customer's ticker and also on screens without a ticker or without the
 * ticker module in the plan; not while a screen is off / in an emergency; only the Super Admin can change it
 * (customers get 403, CSRF); the customer's Ticker page shows it read-only; a customer setting
 * `platform_credit_*` cannot override it; turning it off removes it.
 */
final class PlatformCreditTest extends TestCase
{
    private static string $url;
    private static array $sessions = [];
    private static array $tv = [];
    private static int $alpha = 0;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => null, 'username' => 'credroot', 'email' => 'credroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        Settings::setPlatform('platform_name', 'AK Computer TV');
        Settings::setPlatform('platform_support_phone', '+91 90000 11111');
        DB::query("DELETE FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_credit_enabled'"); // production default: on
        self::$alpha = Hotels::create(['name' => 'Alpha Temple']);
        Hotels::createHotelUser(self::$alpha, ['username' => 'credboss', 'email' => 'boss@credit.test', 'password' => 'Passw0rd!'], 'super_admin');
        Tenant::run(self::$alpha, static function (): void {
            DB::insert('rooms', ['room_number' => 'C1', 'name' => 'Hall', 'floor' => '0']);
        });
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $key = (string) Settings::getFor(self::$alpha, 'registration_key');
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-credit-c1-0001', 'room_number' => 'C1', 'registration_key' => $key, 'app_version' => '2.6.2', 'app_version_code' => 16]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tv = ['uid' => 'tv-credit-c1-0001', 'token' => $j['data']['token']];
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Cache::clear();
        Settings::flush();
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function ticker(): ?array
    {
        Cache::clear();
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['uid'] . '?hash=x', null,
            ['Authorization: Bearer ' . self::$tv['token'], 'X-Device-Id: ' . self::$tv['uid']]);
        self::assertSame(200, $s, (string) json_encode($j));
        return $j['data']['content']['overlay']['ticker'] ?? null;
    }

    public function test1DefaultLineOnScreenWithoutTicker(): void
    {
        $t = self::ticker();
        $this->assertNotNull($t);
        $this->assertStringContainsString('AK Computer TV દ્વારા બનાવવામાં આવી છે', $t['text']);
        $this->assertStringContainsString('Digital TV system by AK Computer TV', $t['text']);
        $this->assertStringContainsString('+91 90000 11111', $t['text']);
        $this->assertSame([PlatformCredit::line()], $t['messages']);
        $this->assertSame('bottom', $t['position']);
    }

    public function test2AppendedAfterCustomerTickerAndKeptWithoutModule(): void
    {
        Tenant::run(self::$alpha, static function (): void {
            DB::insert('tickers', ['message' => 'Jai Dwarkadhish! Aarti at 7 pm', 'target_type' => 'all', 'is_active' => 1, 'priority' => 0,
                'speed' => 5, 'position' => 'bottom', 'created_at' => now()]);
            Settings::bumpContentVersion();
        });
        $t = self::ticker();
        $this->assertStringStartsWith('Jai Dwarkadhish! Aarti at 7 pm', $t['text']);
        $this->assertStringEndsWith(PlatformCredit::line(), $t['text']);
        $this->assertSame(PlatformCredit::line(), end($t['messages']));
        $this->assertCount(2, $t['messages']);

        // Ticker module switched off for this customer: the customer ticker goes, the credit stays.
        DB::query('UPDATE hotels SET feature_overrides = :o WHERE id = :h', ['o' => Features::encodeOverrides([], ['tickers']), 'h' => self::$alpha]);
        Tenant::forget(self::$alpha);
        $t = self::ticker();
        $this->assertNotNull($t);
        $this->assertStringNotContainsString('Aarti at 7 pm', $t['text']);
        $this->assertStringContainsString('AK Computer TV', $t['text']);
        DB::query('UPDATE hotels SET feature_overrides = NULL WHERE id = :h', ['h' => self::$alpha]);
        Tenant::forget(self::$alpha);

        // A customer-level setting with the same key cannot override the platform text.
        DB::query("INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, :k, '0')", ['h' => self::$alpha, 'k' => PlatformCredit::K_ENABLED]);
        Settings::flush();
        $this->assertStringContainsString('AK Computer TV', (string) (self::ticker()['text'] ?? ''));
    }

    public function test3NotOnScreenOffOrEmergency(): void
    {
        $c = ['mode' => 'off', 'overlay' => ['ticker' => null]];
        PlatformCredit::apply($c, ['id' => 1]);
        $this->assertNull($c['overlay']['ticker']);
        $c = ['mode' => 'emergency', 'overlay' => []];
        PlatformCredit::apply($c, ['id' => 1]);
        $this->assertArrayNotHasKey('ticker', $c['overlay']);
        $c = ['mode' => 'assigned', 'screen_on' => false, 'overlay' => []];
        PlatformCredit::apply($c, ['id' => 1]);
        $this->assertArrayNotHasKey('ticker', $c['overlay']);
        $c = ['mode' => 'assigned', 'wall' => ['rows' => 2], 'overlay' => []];
        PlatformCredit::apply($c, ['id' => 1]);
        $this->assertArrayNotHasKey('ticker', $c['overlay']);
    }

    public function test4OnlySuperAdminChangesIt(): void
    {
        [$s] = self::as('credboss')->get('platform_settings.php?tab=credit');
        $this->assertSame(403, $s);
        [$s] = self::as('credboss')->post('platform_settings.php', ['op' => 'credit', 'tab' => 'credit', 'credit_gu' => 'hacked', 'credit_en' => '', 'credit_phone' => '']);
        $this->assertSame(403, $s);
        $this->assertStringContainsString('AK Computer TV', (string) (self::ticker()['text'] ?? ''));
        // The customer's Ticker page shows the line read-only.
        [$s, , $html] = self::as('credboss')->get('tickers.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('id="platformCredit"', $html);
        $this->assertStringContainsString('+91 90000 11111', $html);

        $root = self::as('credroot');
        [$s, , $html] = $root->get('platform_settings.php?tab=credit');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringContainsString('id="creditPreview"', $html);
        // Validation: bad phone, both texts empty → nothing saved.
        $root->post('platform_settings.php', ['op' => 'credit', 'tab' => 'credit', 'credit_enabled' => 1, 'credit_gu' => 'x', 'credit_en' => '', 'credit_phone' => 'call me']);
        $root->post('platform_settings.php', ['op' => 'credit', 'tab' => 'credit', 'credit_enabled' => 1, 'credit_gu' => '', 'credit_en' => '', 'credit_phone' => '']);
        Settings::flush();
        $this->assertSame(PlatformCredit::DEFAULT_GU, PlatformCredit::config()['gu']);

        [$s] = $root->post('platform_settings.php', ['op' => 'credit', 'tab' => 'credit', 'credit_enabled' => 1,
            'credit_gu' => 'આ સિસ્ટમ AK Computer ની છે — કોલ કરો :phone', 'credit_en' => 'System by AK Computer — call :phone', 'credit_phone' => '+91 98765 43210']);
        $this->assertSame(302, $s);
        $t = self::ticker();
        $this->assertStringContainsString('આ સિસ્ટમ AK Computer ની છે — કોલ કરો +91 98765 43210', $t['text']);
        $this->assertStringContainsString('System by AK Computer — call +91 98765 43210', $t['text']);
        $this->assertStringContainsString('Jai Dwarkadhish', $t['text']);

        // Off: gone from every TV.
        $root->post('platform_settings.php', ['op' => 'credit', 'tab' => 'credit', 'credit_gu' => 'x', 'credit_en' => 'y', 'credit_phone' => '']);
        $t = self::ticker();
        $this->assertStringNotContainsString('AK Computer', (string) ($t['text'] ?? ''));
    }

    public function test5EmptyPhoneDropsDanglingIcon(): void
    {
        $line = PlatformCredit::line(['enabled' => true, 'gu' => 'કોલ કરો 📞 :phone', 'en' => 'Call 📞 :phone', 'phone' => '']);
        Settings::setPlatform('platform_support_phone', '');
        Settings::flush();
        $line = PlatformCredit::line(['enabled' => true, 'gu' => 'કોલ કરો 📞 :phone', 'en' => 'Call 📞 :phone', 'phone' => '']);
        $this->assertStringNotContainsString('📞', $line);
        $this->assertStringNotContainsString(':phone', $line);
        Settings::setPlatform('platform_support_phone', '+91 90000 11111');
        Settings::flush();
    }
}
