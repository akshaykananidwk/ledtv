<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Public landing page at the site root (index.php + core/Landing.php, 2.6.1): renders without login and
 * without PHP warnings, has every section, English / Gujarati / Hindi (?lang=, cookie, Accept-Language),
 * plans come from the plans table (inactive plans hidden, no plan → "contact us"), the trial / demo /
 * contact buttons follow the platform settings, a logged-in user gets "Go to dashboard", a not installed
 * app still redirects to the installer, CSP without inline script, and no customer name is ever shown.
 */
final class LandingPageTest extends TestCase
{
    private static string $url;
    private const SECRET = 'Zorblax Secret Traders';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Main Business');
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => 1, 'username' => 'landboss', 'email' => 'boss@landing.test', 'full_name' => 'Boss',
            'password_hash' => $pw, 'role' => 'super_admin', 'is_active' => 1, 'created_at' => now()]);
        // Ten customers with one TV each (live numbers need >= Landing::STATS_MIN of both); one has a "secret" name.
        for ($i = 1; $i <= 10; $i++) {
            $hid = Hotels::create(['name' => $i === 1 ? self::SECRET : 'Landing Customer ' . $i]);
            Tenant::run($hid, static function () use ($i): void {
                $room = DB::insert('rooms', ['room_number' => '10' . $i, 'name' => 'Screen ' . $i, 'floor' => '1']);
                DB::insert('devices', ['device_uid' => 'tv-landing-' . $i, 'room_id' => $room, 'token_hash' => hash('sha256', 'landing' . $i),
                    'status' => 'online', 'last_ping' => now(), 'registered_at' => now()]);
            });
        }
        Tenant::set(1);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    protected function setUp(): void
    {
        Settings::setPlatform('signup_enabled', '0');
        Settings::setPlatform('demo_public_enabled', '0');
        Settings::setPlatform('platform_support_phone', '');
        Settings::setPlatform('platform_support_email', '');
        Settings::setPlatform('platform_name', '');
        Settings::flush();
        Cache::clear('landing');
    }

    /** @return array{0:int,1:string,2:string} status, body, headers */
    private static function get(string $query = '', array $headers = [], ?string $jar = null): array
    {
        [$s, , $body, $head] = TestEnv::http('GET', self::$url . $query, null, $headers, $jar);
        return [$s, $body, $head];
    }

    public function testRendersWithoutLoginWithAllSections(): void
    {
        [$s, $html, $head] = self::get();
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        foreach (['id="usecases"', 'id="features"', 'id="f-apps"', 'id="f-devices"', 'id="how"', 'id="plans"', 'id="faq"', 'id="contact"'] as $id) {
            $this->assertStringContainsString($id, $html);
        }
        $this->assertStringContainsString('<html lang="en"', $html);
        $this->assertStringContainsString('Krishna Cloud TV Management', $html);
        $this->assertStringContainsString('Temples &amp; religious places', $html);
        $this->assertStringContainsString('Token queue', $html);
        $this->assertStringContainsString('admin/login.php', $html);
        $this->assertStringNotContainsString('Go to dashboard', $html);
        // SEO
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]+">#', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('hreflang="gu"', $html);
        $this->assertMatchesRegularExpression('#<script type="application/ld\+json" nonce="[^"]+">(.+?)</script>#s', $html);
        preg_match('#<script type="application/ld\+json"[^>]*>(.+?)</script>#s', $html, $m);
        $ld = json_decode($m[1], true);
        $this->assertSame('SoftwareApplication', $ld['@type'] ?? null);
        // CSP: no inline script without the nonce, local assets only.
        $this->assertMatchesRegularExpression("#Content-Security-Policy: default-src 'self';.*script-src 'self' 'nonce-#i", $head);
        preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)[^>]*>#', $html, $inline);
        $this->assertSame([], $inline[0], 'inline script without nonce');
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!127\.0\.0\.1)[^"]+\.(css|js)#', $html, 'no CDN assets');
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }

    public function testLanguagesFromQueryCookieAndBrowser(): void
    {
        [$s, $gu, $head] = self::get('?lang=gu');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($gu));
        $this->assertStringContainsString('<html lang="gu"', $gu);
        $this->assertStringContainsString('મંદિર અને ધાર્મિક સ્થળો', $gu);
        $this->assertStringContainsString('લોકો વારંવાર પૂછે છે', $gu);
        $this->assertMatchesRegularExpression('/Set-Cookie: hc_lang=gu;/i', $head);

        [, $hi] = self::get('?lang=hi');
        $this->assertStringContainsString('<html lang="hi"', $hi);
        $this->assertStringContainsString('मंदिर और धार्मिक स्थल', $hi);
        $this->assertFalse(TestEnv::hasPhpError($hi));

        // Remembered in the cookie.
        [, $c] = self::get('', ['Cookie: hc_lang=gu']);
        $this->assertStringContainsString('<html lang="gu"', $c);
        // Browser language (q-values respected); an unsupported one falls back to English.
        [, $b] = self::get('', ['Accept-Language: fr-FR,hi;q=0.9,en;q=0.5']);
        $this->assertStringContainsString('<html lang="hi"', $b);
        [, $f] = self::get('', ['Accept-Language: fr-FR,de;q=0.8']);
        $this->assertStringContainsString('<html lang="en"', $f);
        // A bad ?lang= is ignored.
        [, $x] = self::get('?lang=xx', ['Accept-Language: gu']);
        $this->assertStringContainsString('<html lang="gu"', $x);

        $this->assertSame('gu', Landing::fromAcceptLanguage('gu-IN,gu;q=0.9,en-US;q=0.8'));
        $this->assertSame('en', Landing::fromAcceptLanguage('en-GB,hi;q=0.3'));
        $this->assertNull(Landing::fromAcceptLanguage(''));
    }

    public function testPlansComeFromTheDatabase(): void
    {
        $active = DB::insert('plans', ['name' => 'Landing Gold Plan', 'description' => 'Test plan for the landing page', 'price_per_tv_month' => 321,
            'max_tvs' => 40, 'max_users' => 7, 'storage_mb' => 2048, 'features' => Features::encodePlanKeys(['content', 'playlists', 'tickers']), 'is_active' => 1]);
        $hidden = DB::insert('plans', ['name' => 'Landing Hidden Plan', 'description' => 'inactive', 'price_per_tv_month' => 555, 'is_active' => 0]);
        try {
            [, $html] = self::get();
            $this->assertStringContainsString('Landing Gold Plan', $html);
            $this->assertStringContainsString('data-plan="' . $active . '"', $html);
            $this->assertStringContainsString('₹321.00', $html);
            $this->assertStringContainsString('Up to 40 screens', $html);
            $this->assertStringContainsString('Up to 7 users', $html);
            $this->assertStringContainsString('2 GB storage', $html);
            $this->assertStringContainsString('Ticker bar', $html);
            $this->assertStringNotContainsString('Landing Hidden Plan', $html);
            $this->assertStringNotContainsString('₹555.00', $html);
            $this->assertStringNotContainsString('Contact us for pricing', $html);
            // "Everything except …" plans list what is left out.
            $pro = (int) DB::value("SELECT id FROM plans WHERE name = 'Pro'");
            $this->assertGreaterThan(0, $pro);
            $this->assertMatchesRegularExpression('#data-plan="' . $pro . '".*?All modules, except:.*?Ad marketplace.*?</article>#s', $html);

            // No active plan at all → a "contact us for pricing" card, no prices.
            $was = DB::all('SELECT id FROM plans WHERE is_active = 1');
            DB::query('UPDATE plans SET is_active = 0');
            try {
                [, $none] = self::get();
                $this->assertStringContainsString('Contact us for pricing', $none);
                $this->assertStringNotContainsString('per screen / month', $none);
                $this->assertFalse(TestEnv::hasPhpError($none));
            } finally {
                foreach ($was as $r) {
                    DB::query('UPDATE plans SET is_active = 1 WHERE id = :id', ['id' => $r['id']]);
                }
            }
        } finally {
            DB::query('DELETE FROM plans WHERE id IN (' . $active . ',' . $hidden . ')');
        }
    }

    public function testTrialDemoAndContactFollowPlatformSettings(): void
    {
        [, $off] = self::get();
        $this->assertStringNotContainsString('signup.php', $off, 'sign-up disabled → no trial button');
        $this->assertStringNotContainsString('demo.php', $off);
        $this->assertStringNotContainsString('wa.me', $off);
        $this->assertStringNotContainsString('tel:', $off);

        Settings::setPlatform('signup_enabled', '1');
        Settings::setPlatform('trial_days', '21');
        Settings::setPlatform('demo_public_enabled', '1');
        Settings::setPlatform('platform_support_phone', '+91 98765 43210');
        Settings::setPlatform('platform_support_email', 'hello@landing.test');
        Settings::setPlatform('platform_name', 'Shree Screens');
        [, $on] = self::get('?lang=gu');
        $this->assertStringContainsString('signup.php?lang=gu', $on);
        $this->assertStringContainsString('21 દિવસની મફત ટ્રાયલ', $on);
        $this->assertStringContainsString('demo.php?lang=gu', $on);
        $this->assertStringContainsString('https://wa.me/919876543210', $on);
        $this->assertStringContainsString('tel:+919876543210', $on);
        $this->assertStringContainsString('mailto:hello@landing.test', $on);
        $this->assertStringContainsString('Shree Screens', $on, 'white-label name');
        $this->assertStringNotContainsString('Krishna Cloud TV Management', $on);
        $this->assertFalse(TestEnv::hasPhpError($on));

        // A local number gets a call link but no WhatsApp guess.
        $this->assertSame(['tel' => 'tel:09876543210', 'whatsapp' => null], Landing::phoneLinks('098765 43210'));
        Settings::setPlatform('trial_days', '14');
    }

    public function testLoggedInUserSeesGoToDashboard(): void
    {
        $s = new AdminSession(self::$url, 'landboss');
        [$st, $html] = self::get('', [], $s->jar);
        $this->assertSame(200, $st, 'no auto-redirect for logged-in users');
        $this->assertStringContainsString('Go to dashboard', $html);
        $this->assertStringContainsString('href="' . self::$url . 'admin/"', $html);
        $this->assertStringNotContainsString('admin/login.php', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
    }

    public function testNoCustomerNamesLeakAndNumbersAreAggregate(): void
    {
        [, $html] = self::get();
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString('Landing Customer', $html);
        $this->assertStringNotContainsString('Main Business', $html);
        $this->assertStringContainsString('screens managed', $html);
        $this->assertMatchesRegularExpression('#<b>10\+</b><span>screens managed#', $html);

        // Below the threshold the live numbers are not shown.
        Cache::set('landing', 'stats', ['screens' => 3, 'customers' => 2]);
        [, $small] = self::get();
        $this->assertStringNotContainsString('screens managed', $small);
        $this->assertSame('100+', Landing::roundDown(137));
        $this->assertSame('150+', Landing::roundDown(175));
        $this->assertSame('40+', Landing::roundDown(47));
        $this->assertSame('1,200+', Landing::roundDown(1234));
    }

    public function testNotInstalledRedirectsToInstaller(): void
    {
        $lock = HC_ROOT . '/installed.lock';
        rename($lock, $lock . '.bak');
        try {
            [$s, , $head] = self::get();
            $this->assertSame(302, $s);
            $this->assertMatchesRegularExpression('#Location: \S*install/#i', $head);
        } finally {
            rename($lock . '.bak', $lock);
        }
        [$s] = self::get();
        $this->assertSame(200, $s);
    }

    public function testAssetsExistAndAreLocal(): void
    {
        foreach (['assets/landing/landing.css', 'assets/landing/landing.js'] as $f) {
            $this->assertFileExists(HC_ROOT . '/' . $f);
            [$s] = self::get($f);
            $this->assertSame(200, $s, $f);
        }
        $css = (string) file_get_contents(HC_ROOT . '/assets/landing/landing.css');
        $this->assertStringNotContainsString('http', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        // Every Gujarati string also has a Hindi translation.
        $gu = require HC_ROOT . '/lang/gu_landing.php';
        $hi = require HC_ROOT . '/lang/hi_landing.php';
        $this->assertSame([], array_values(array_diff(array_keys($gu), array_keys($hi))));
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors());
    }
}
