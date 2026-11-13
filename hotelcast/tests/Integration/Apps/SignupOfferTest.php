<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.6 open registration + registration offer (core/Signup.php, migrations/037_signup_open.sql): defaults open
 * sign-up with a 7-day trial; a sign-up made on or before signup_promo_until gets signup_promo_days free with
 * every module (also when provisioned later); after the date the normal trial; the landing page shows
 * "Register" and the offer; the Super Admin form saves and validates the offer; the migration sets the
 * November 2026 offer.
 */
final class SignupOfferTest extends TestCase
{
    private static string $url;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        DB::insert('users', ['hotel_id' => null, 'username' => 'offroot', 'email' => 'offroot@platform.test', 'full_name' => 'Root',
            'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'platform_admin']);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    protected function setUp(): void
    {
        foreach (['signup_enabled', 'trial_days', 'signup_promo_until', 'signup_promo_days', 'signup_promo_all_features', 'signup_promo_text', 'signup_mode'] as $k) {
            DB::query('DELETE FROM system_settings WHERE hotel_id = 0 AND setting_key = :k', ['k' => $k]);
        }
        DB::query('DELETE FROM rate_limits');
        Settings::flush();
        Cache::clear('landing');
    }

    private static function signup(string $email, string $createdAt): int
    {
        return DB::insert('signups', ['status' => 'pending', 'hotel_name' => 'Offer ' . $email, 'city' => 'Dwarka', 'owner_name' => 'Owner',
            'mobile' => '+919800000' . random_int(100, 999), 'email' => $email, 'tv_estimate' => 1, 'language' => 'gu',
            'password_hash' => Auth::hash('Passw0rd!'), 'duplicate' => 0, 'terms_accepted_at' => $createdAt, 'created_at' => $createdAt]);
    }

    public function testDefaultsOpenRegistrationWithSevenDayTrial(): void
    {
        $this->assertTrue(Signup::enabled());
        $this->assertSame(7, Signup::trialDays());
        $this->assertFalse(Signup::promoActive());
        $this->assertSame(7, Signup::freeDays());
        [$s, , $html] = TestEnv::http('GET', rtrim(self::$url, '/') . '/');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('id="navRegister"', $html);
        $this->assertStringContainsString('signup.php', $html);
        $this->assertStringContainsString('Register now', $html);
        $this->assertStringNotContainsString('id="promoBanner"', $html);
        [$s, , $html] = TestEnv::http('GET', self::$url . 'signup.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('7-day free trial', $html);
    }

    public function testOfferGivesAYearWithEveryModule(): void
    {
        Settings::setPlatform('signup_promo_until', date('Y-m-d', time() + 86400 * 5));
        Settings::setPlatform('signup_promo_days', '365');
        Settings::flush();
        $this->assertTrue(Signup::promoActive());
        $this->assertSame(365, Signup::freeDays());
        $this->assertStringContainsString('1 year(s)', Signup::promoHeadline());

        [$hid] = Signup::provision(self::signup('offer1@example.com', now()));
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $hid]);
        $days = (strtotime((string) $h['expires_at']) - time()) / 86400;
        $this->assertGreaterThan(364, $days);
        $this->assertLessThan(366, $days);
        $this->assertSame(1, (int) $h['is_trial']);
        $over = Features::parseOverrides($h['feature_overrides']);
        $this->assertEqualsCanonicalizing(Features::optionalKeys(), $over['add']);
        Tenant::forget($hid);
        foreach (['tickers', 'schedule', 'video_walls', 'analytics'] as $f) {
            if (in_array($f, Features::optionalKeys(), true)) {
                $this->assertTrue(Features::enabled($f, $hid), $f);
            }
        }

        // Landing + sign-up page show the offer.
        [, , $html] = TestEnv::http('GET', rtrim(self::$url, '/') . '/');
        $this->assertStringContainsString('id="promoBanner"', $html);
        [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?lang=gu');
        $this->assertStringContainsString('id="promoBanner"', $html);
        $this->assertStringContainsString('વર્ષ સુધી બધું ફ્રી', $html);

        // Signed up during the offer but approved after it ended: still the offer.
        Settings::setPlatform('signup_promo_until', date('Y-m-d', time() - 86400));
        Settings::flush();
        [$hid2] = Signup::provision(self::signup('offer2@example.com', date('Y-m-d H:i:s', time() - 86400 * 3)));
        $this->assertGreaterThan(364, (strtotime((string) DB::value('SELECT expires_at FROM hotels WHERE id = :id', ['id' => $hid2])) - time()) / 86400);
    }

    public function testAfterTheOfferTheNormalTrial(): void
    {
        Settings::setPlatform('signup_promo_until', date('Y-m-d', time() - 86400));
        Settings::flush();
        $this->assertFalse(Signup::promoActive());
        [$hid] = Signup::provision(self::signup('late@example.com', now()));
        $h = DB::one('SELECT expires_at, feature_overrides FROM hotels WHERE id = :id', ['id' => $hid]);
        $this->assertEqualsWithDelta(7, (strtotime((string) $h['expires_at']) - time()) / 86400, 0.01);
        $this->assertNull($h['feature_overrides']);
        // The end date itself still counts; a bad date is ignored.
        Settings::setPlatform('signup_promo_until', '2026-11-30');
        Settings::flush();
        $this->assertTrue(Signup::promoActive((int) strtotime('2026-11-30 23:59:00')));
        $this->assertFalse(Signup::promoActive((int) strtotime('2026-12-01 00:00:01')));
        Settings::setPlatform('signup_promo_until', 'soon');
        Settings::flush();
        $this->assertFalse(Signup::promoActive());
    }

    public function testSuperAdminSavesTheOffer(): void
    {
        $root = new AdminSession(self::$url, 'offroot');
        [$s] = $root->post('platform_signups.php', ['op' => 'settings', 'tab' => 'settings', 'signup_enabled' => 1, 'signup_mode' => 'auto', 'trial_days' => 7,
            'signup_promo_until' => '2026-11-30', 'signup_promo_days' => 365, 'signup_promo_all_features' => 1, 'signup_promo_text' => 'November: 1 year free!']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('2026-11-30', Signup::setting('signup_promo_until'));
        $this->assertSame('365', Signup::setting('signup_promo_days'));
        $this->assertSame('November: 1 year free!', Signup::setting('signup_promo_text'));
        [$s, , $html] = $root->get('platform_signups.php?tab=settings');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringContainsString('name="signup_promo_until" value="2026-11-30"', $html);
        // Validation.
        $root->post('platform_signups.php', ['op' => 'settings', 'tab' => 'settings', 'signup_enabled' => 1, 'signup_mode' => 'auto', 'trial_days' => 7, 'signup_promo_until' => '30/11/2026']);
        Settings::flush();
        $this->assertSame('2026-11-30', Signup::setting('signup_promo_until'));
    }

    public function testMigrationSetsTheNovemberOffer(): void
    {
        $sql = (string) file_get_contents(HC_ROOT . '/migrations/037_signup_open.sql');
        foreach (["'signup_enabled', '1'", "'trial_days', '7'", "'signup_promo_until', '2026-11-30'", "'signup_promo_days', '365'"] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        DB::pdo()->exec(preg_replace('/^--.*$/m', '', $sql));
        Settings::flush();
        $this->assertTrue(Signup::enabled());
        $this->assertSame('2026-11-30', Signup::setting('signup_promo_until'));
    }
}
