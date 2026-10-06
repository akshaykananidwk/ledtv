<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Advertiser portal session over HTTP (own cookie jar, CSRF from the page meta tag). */
final class AdvSession
{
    public string $jar;
    public string $csrf = '';

    public function __construct(public string $base)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'adv');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path): array
    {
        $r = TestEnv::http('GET', $this->base . $path, null, [], $this->jar);
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $r[2], $m)) {
            $this->csrf = html_entity_decode($m[1]);
        }
        return $r;
    }

    /** POST a form (arrays become name[i]; CURLFile values are uploaded). */
    public function post(string $path, array $fields): array
    {
        if ($this->csrf === '') {
            $this->get('advertise/login.php');
        }
        $flat = ['_csrf' => $this->csrf];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $x) {
                    $flat[$k . '[' . $i . ']'] = (string) $x;
                }
            } else {
                $flat[$k] = $v instanceof CURLFile ? $v : (string) $v;
            }
        }
        return TestEnv::http('POST', $this->base . $path, null, [], $this->jar, $flat);
    }

    public function json(string $path, array $body): array
    {
        return TestEnv::http('POST', $this->base . $path, $body, ['X-CSRF-Token: ' . $this->csrf, 'X-Requested-With: XMLHttpRequest'], $this->jar);
    }

    public function cookies(): string
    {
        return (string) @file_get_contents($this->jar);
    }
}

/**
 * Ad marketplace (#19): advertiser sign-up / OTP / login / lockout, separate session (never reaches admin/),
 * hotel opt-in & pricing, public listing fields, quote math (days × TVs × price, CPM, tax), capacity
 * (max ads per loop), booking lifecycle (draft → awaiting payment → paid → hotel approval → running),
 * campaign created in each approved hotel → ad in that hotel's TV content only → impressions reported back
 * to the advertiser (report, CSV, print), rejections + refunds, revenue share and monthly payout
 * statements, MarketplaceTask (expiry, completion, CPM delivery), isolation between advertisers and hotels.
 */
final class MarketplaceTest extends TestCase
{
    private static string $url;
    private static array $id = [];
    private static array $tv = [];
    private static string $png = '';
    private static array $mails = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Dwarka Palace');
        DB::query("UPDATE hotels SET city = 'Dwarka' WHERE id = 1");
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'mkBoss1', 'manager' => 'mkMgr1', 'staff' => 'mkStaff1'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@m.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        DB::insert('users', ['hotel_id' => null, 'username' => 'mkPlat', 'email' => 'plat@m.test', 'full_name' => 'Plat', 'password_hash' => $pw, 'role' => 'platform_admin']);
        $pl = DB::insert('content_playlists', ['name' => 'Loop', 'transition' => 'fade']);
        for ($i = 1; $i <= 4; $i++) {
            $ci = DB::insert('content_items', ['title' => 'H1 item ' . $i, 'type' => 'image', 'url' => 'https://x.test/' . $i . '.jpg', 'duration' => 10]);
            DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $ci, 'sort_order' => $i]);
        }
        Settings::set('default_playlist_id', (string) $pl);
        self::$id['h1key'] = (string) Settings::get('registration_key');

        Hotels::create(['name' => 'Gomti View', 'city' => 'Dwarka'], ['username' => 'mkBoss2', 'email' => 'b2@m.test', 'password' => 'Passw0rd!']);
        Hotels::create(['name' => 'Not Selling Inn', 'city' => 'Okha'], ['username' => 'mkBoss3', 'email' => 'b3@m.test', 'password' => 'Passw0rd!']);
        foreach ([2, 3] as $h) {
            Tenant::run($h, static function () use ($h): void {
                $ci = DB::insert('content_items', ['title' => 'H' . $h . ' main', 'type' => 'image', 'url' => 'https://x.test/h' . $h . '.jpg', 'duration' => 0]);
                Settings::set('default_content_id', (string) $ci);
            });
            self::$id['h' . $h . 'key'] = (string) Settings::getFor($h, 'registration_key');
        }
        DB::insert('users', ['hotel_id' => 2, 'username' => 'mkMgr2', 'email' => 'm2@m.test', 'full_name' => 'M2', 'password_hash' => $pw, 'role' => 'manager']);
        Settings::setPlatform('platform_mkt_upi_id', 'hotelcast@okbank');
        Settings::setPlatform('platform_mkt_upi_name', 'HotelCast Ads');
        Settings::setPlatform('platform_mkt_bank_text', "Bank: Test Bank\nA/c 123456");
        Settings::setPlatform('platform_notify_email', 'ops@platform.test');
        Notifier::$mailer = static function (string $to, string $subject, string $message): bool {
            self::$mails[] = [$to, $subject, $message];
            return true;
        };
        Cache::clear();
        ContentResolver::resetExtensions();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);

        // TVs: hotel 1 → 2 TVs (rooms 101, 102), hotel 2 → 1 TV, hotel 3 → 1 TV.
        foreach ([['h1', '101'], ['h1', '102'], ['h2', '201'], ['h3', '301']] as [$h, $room]) {
            $uid = 'mkt-tv-' . $h . '-' . $room;
            [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => self::$id[$h . 'key']]);
            if ($st !== 200) {
                throw new RuntimeException('register failed: ' . json_encode($j));
            }
            self::$tv[$h . $room] = ['Authorization: Bearer ' . $j['data']['token'], 'X-Device-Id: ' . $uid];
        }
        $img = imagecreatetruecolor(640, 360);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        self::$png = sys_get_temp_dir() . '/mkt_ad_' . getmypid() . '.png';
        imagepng($img, self::$png);
        imagedestroy($img);
    }

    public static function tearDownAfterClass(): void
    {
        Notifier::$mailer = null;
        @unlink(self::$png);
        Tenant::forget();
        ContentResolver::resetExtensions();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
    }

    // ------------------------------------------------------------------ helpers

    private static function optIn(int $hotelId, array $over = []): void
    {
        Tenant::run($hotelId, static function () use ($over): void {
            [$data, $err] = Marketplace::validateHotelSettings($over + ['enabled' => 1, 'pricing_model' => 'per_day', 'price_per_tv_day' => '50', 'price_cpm' => '0',
                'max_ads_per_loop' => 2, 'approval' => 'manual']);
            if ($err) {
                throw new RuntimeException(implode(', ', $err));
            }
            Marketplace::saveHotelSettings($data);
        });
    }

    /** Advertiser created in-process (active), returns id. */
    private static function advertiser(string $name, string $email): int
    {
        return DB::insert('mkt_advertisers', ['business_name' => $name, 'contact_name' => 'Owner ' . $name, 'mobile' => '+919876543210', 'email' => $email,
            'password_hash' => Auth::hash('Passw0rd!'), 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()]);
    }

    private static function textCreative(int $advId, string $title = 'Sweets'): int
    {
        return Marketplace::createTextCreative($advId, ['title' => $title, 'text' => $title . ' — fresh daily', 'subtitle' => 'Near the temple', 'duration' => 12]);
    }

    private static function booking(int $advId, array $over = [], array $hotels = [1, 2]): int
    {
        [$data, $ids, $err] = Marketplace::validateBooking($advId, $over + ['title' => 'Offer', 'category' => 'food', 'pricing_model' => 'per_day',
            'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+2 days')), 'creative_id' => $over['creative_id'] ?? self::textCreative($advId), 'hotel_ids' => $hotels]);
        if ($err) {
            throw new RuntimeException(implode(', ', $err));
        }
        return Marketplace::saveDraft($advId, $data, $ids);
    }

    private static function loginAdv(string $email, string $password = 'Passw0rd!'): AdvSession
    {
        $s = new AdvSession(self::$url);
        $s->get('advertise/login.php');
        $s->post('advertise/login.php', ['email' => $email, 'password' => $password]);
        $s->get('advertise/index.php');
        return $s;
    }

    private static function location(string $head): string
    {
        return preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------ hotel opt-in

    public function testHotelOptInPricingAndPermissions(): void
    {
        $boss = new AdminSession(self::$url, 'mkBoss1');
        [$st, , $html] = $boss->get('marketplace.php?tab=settings');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Sell ad space', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));

        // Enabled without a price → validation error, nothing stored.
        $boss->post('marketplace.php', ['op' => 'settings_save', 'enabled' => 1, 'pricing_model' => 'per_day', 'price_per_tv_day' => '0', 'max_ads_per_loop' => 2, 'approval' => 'manual']);
        $this->assertNull(DB::value('SELECT enabled FROM mkt_hotel_settings WHERE hotel_id = 1'));

        $boss->post('marketplace.php', ['op' => 'settings_save', 'enabled' => 1, 'pricing_model' => 'per_day', 'price_per_tv_day' => '50.00', 'price_cpm' => '',
            'max_ads_per_loop' => 9, 'approval' => 'manual', 'blocked_categories' => ['religious'], 'description' => 'Near the temple, 40 rooms']);
        $s = Marketplace::hotelSettings(1);
        $this->assertSame(1, (int) $s['enabled']);
        $this->assertSame('50.00', (string) $s['price_per_tv_day']);
        $this->assertSame(Marketplace::MAX_ADS_PER_LOOP, (int) $s['max_ads_per_loop'], 'clamped');
        $this->assertSame(['religious'], json_decode((string) $s['blocked_categories'], true));

        // Links are not allowed in the hotel description.
        $boss->post('marketplace.php', ['op' => 'settings_save', 'enabled' => 1, 'pricing_model' => 'per_day', 'price_per_tv_day' => '50', 'max_ads_per_loop' => 2,
            'approval' => 'manual', 'description' => 'see www.spam.com']);
        $this->assertSame('Near the temple, 40 rooms', (string) Marketplace::hotelSettings(1)['description']);
        DB::query('UPDATE mkt_hotel_settings SET max_ads_per_loop = 2 WHERE hotel_id = 1');

        // Manager: requests yes, settings no. Staff: nothing.
        $mgr = new AdminSession(self::$url, 'mkMgr1');
        $this->assertSame(200, $mgr->get('marketplace.php')[0]);
        [$st] = $mgr->post('marketplace.php', ['op' => 'settings_save', 'enabled' => 0]);
        $this->assertSame(403, $st);
        $this->assertSame(1, (int) Marketplace::hotelSettings(1)['enabled']);
        $staff = new AdminSession(self::$url, 'mkStaff1');
        $this->assertSame(403, $staff->get('marketplace.php')[0]);

        // Hotel 2 sells per day AND per 1000 impressions, auto approval; hotel 3 does not sell.
        self::optIn(2, ['pricing_model' => 'both', 'price_per_tv_day' => '20.50', 'price_cpm' => '120', 'approval' => 'auto']);
        $list = Marketplace::listHotels();
        $this->assertSame([1, 2], array_column($list, 'id'));
        $h1 = $list[0];
        $this->assertSame(['id', 'name', 'city', 'tv_count', 'rooms', 'pricing_model', 'price_per_tv_day', 'price_cpm', 'max_ads_per_loop', 'allowed_categories',
            'blocked_categories', 'approval', 'description', 'est_daily_impressions'], array_keys($h1), 'public fields only');
        $this->assertSame(2, $h1['tv_count']);
        $this->assertSame('Dwarka Palace', $h1['name']);
        $this->assertNull($h1['price_cpm']);
        $this->assertSame([2], array_column(Marketplace::listHotels(['model' => 'cpm']), 'id'));
        $this->assertSame([2], array_column(Marketplace::listHotels(['category' => 'religious']), 'id'), 'hotel 1 blocks religious ads');

        // Public listing page (no login) shows hotels 1 + 2 but no contact data.
        [$st, , $html] = TestEnv::http('GET', self::$url . 'advertise/hotels.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Dwarka Palace', $html);
        $this->assertStringContainsString('Gomti View', $html);
        $this->assertStringNotContainsString('Not Selling Inn', $html);
        $this->assertStringNotContainsString('b2@m.test', $html);
        $this->assertStringNotContainsString(self::$id['h1key'], $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ advertiser accounts

    public function testAdvertiserSignupOtpLoginAndSeparateSession(): void
    {
        $s = new AdvSession(self::$url);
        [$st, , $html] = $s->get('advertise/signup.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('name="business_name"', $html);
        // Validation: weak password, bad mobile.
        $s->post('advertise/signup.php', ['business_name' => 'Krishna Sweets', 'contact_name' => 'Ramesh', 'mobile' => '12', 'email' => 'ks@adv.test', 'password' => 'short', 'password2' => 'short', 'accept' => 1]);
        $this->assertNull(DB::value("SELECT id FROM mkt_advertisers WHERE email = 'ks@adv.test'"));
        [, , $head] = [null, null, $s->post('advertise/signup.php', ['business_name' => 'Krishna Sweets', 'contact_name' => 'Ramesh', 'mobile' => '+91 98765 43210',
            'email' => 'KS@adv.test', 'city' => 'Dwarka', 'password' => 'Sweets123', 'password2' => 'Sweets123', 'accept' => 1])[3]];
        $this->assertStringContainsString('verify.php', self::location($head));
        $a = DB::one("SELECT * FROM mkt_advertisers WHERE email = 'ks@adv.test'");
        $this->assertSame('unverified', $a['status']);
        $this->assertSame('+919876543210', $a['mobile']);
        $this->assertTrue(password_verify('Sweets123', $a['password_hash']));
        // Duplicate email refused.
        $s2 = new AdvSession(self::$url);
        $s2->get('advertise/signup.php');
        $s2->post('advertise/signup.php', ['business_name' => 'Copy', 'contact_name' => 'X', 'mobile' => '9876543210', 'email' => 'ks@adv.test', 'password' => 'Sweets123', 'password2' => 'Sweets123', 'accept' => 1]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM mkt_advertisers WHERE email = 'ks@adv.test'"));

        // Unverified: every page leads to the code form; the booking page is not reachable.
        [, , , $head] = $s->get('advertise/book.php');
        $this->assertStringContainsString('verify.php', self::location($head));
        DB::query('UPDATE mkt_advertisers SET otp_hash = :h, otp_expires_at = :e WHERE id = :id', ['h' => hash('sha256', $a['id'] . ':123456'), 'e' => date('Y-m-d H:i:s', time() + 600), 'id' => $a['id']]);
        $s->get('advertise/verify.php');
        $s->post('advertise/verify.php', ['code' => '654321']);
        $this->assertSame(1, (int) DB::value('SELECT otp_attempts FROM mkt_advertisers WHERE id = :id', ['id' => $a['id']]));
        $s->post('advertise/verify.php', ['code' => '123 456']);
        $this->assertSame('active', DB::value('SELECT status FROM mkt_advertisers WHERE id = :id', ['id' => $a['id']]));
        [$st, , $html] = $s->get('advertise/index.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Krishna Sweets', $html);
        self::$id['advA'] = (int) $a['id'];

        // Separate session: cookie HCADVSESSID scoped to /advertise/, never an admin session.
        $this->assertMatchesRegularExpression('#\t/advertise/\t.*\tHCADVSESSID\t#', $s->cookies());
        $this->assertStringNotContainsString('HCSESSID', $s->cookies());
        [$st, , , $head] = TestEnv::http('GET', self::$url . 'admin/index.php', null, [], $s->jar);
        $this->assertSame(302, $st);
        $this->assertStringContainsString('admin/login.php', self::location($head));
        [$st] = TestEnv::http('GET', self::$url . 'admin/marketplace.php', null, [], $s->jar);
        $this->assertSame(302, $st);
        [$st, $j] = TestEnv::http('GET', self::$url . 'admin/ajax.php?action=platform_stats', null, ['X-Requested-With: XMLHttpRequest'], $s->jar);
        $this->assertSame(401, $st);
        // Even the advertiser's session id sent as the admin cookie is not an admin login.
        preg_match('/HCADVSESSID\t(\S+)/', $s->cookies(), $m);
        [$st] = TestEnv::http('GET', self::$url . 'admin/platform_marketplace.php', null, ['Cookie: HCSESSID=' . $m[1]]);
        $this->assertSame(302, $st);
        // ... and a hotel admin session does not open the advertiser portal.
        $boss = new AdminSession(self::$url, 'mkBoss1');
        [, , , $head] = TestEnv::http('GET', self::$url . 'advertise/book.php', null, [], $boss->jar);
        $this->assertStringContainsString('advertise/login.php', self::location($head));

        // Logout revokes; wrong password counts towards the lockout.
        $s->get('advertise/logout.php');
        [, , , $head] = $s->get('advertise/book.php');
        $this->assertStringContainsString('login.php', self::location($head));
        $bad = new AdvSession(self::$url);
        $bad->get('advertise/login.php');
        for ($i = 0; $i < Marketplace::MAX_LOGIN_FAILS; $i++) {
            [, , $html] = $bad->post('advertise/login.php', ['email' => 'ks@adv.test', 'password' => 'wrong']);
        }
        $this->assertNotNull(DB::value('SELECT locked_until FROM mkt_advertisers WHERE id = :id', ['id' => $a['id']]));
        [, , $html] = $bad->post('advertise/login.php', ['email' => 'ks@adv.test', 'password' => 'Sweets123']);
        $this->assertStringContainsString('locked', $html);
        DB::query('UPDATE mkt_advertisers SET locked_until = NULL, failed_attempts = 0 WHERE id = :id', ['id' => $a['id']]);
        $ok = self::loginAdv('ks@adv.test', 'Sweets123');
        $this->assertStringContainsString('Krishna Sweets', $ok->get('advertise/index.php')[2]);

        // Approval mode: verified accounts wait for the platform.
        Settings::setPlatform('platform_mkt_signup_approval', '1');
        try {
            [$pid, $code] = Marketplace::register(['business_name' => 'Pending Taxi', 'contact_name' => 'P', 'mobile' => '+919999999999', 'email' => 'p@adv.test', 'city' => null], 'Passw0rd1');
            $this->assertNull(Marketplace::verifyOtp($pid, (string) $code));
            $this->assertSame('pending', Marketplace::advertiser($pid)['status']);
            $p = self::loginAdv('p@adv.test', 'Passw0rd1');
            $this->assertStringContainsString('waiting for approval', $p->get('advertise/index.php')[2]);
            [, , , $head] = $p->get('advertise/book.php');
            $this->assertStringContainsString('advertise/index.php', self::location($head));
            $this->assertNotEmpty(array_filter(self::$mails, fn ($m) => $m[0] === 'p@adv.test' && str_contains($m[2], (string) $code)), 'OTP emailed');
        } finally {
            Settings::setPlatform('platform_mkt_signup_approval', '0');
        }
        // Suspended accounts can not log in.
        Marketplace::setAdvertiserStatus($pid, 'suspended');
        [$adv, $err] = Marketplace::attempt('p@adv.test', 'Passw0rd1', '10.0.0.9');
        $this->assertNull($adv);
        $this->assertStringContainsString('suspended', (string) $err);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ quote

    public function testQuoteMath(): void
    {
        $from = date('Y-m-d', strtotime('+10 days'));
        $to = date('Y-m-d', strtotime('+12 days'));
        // per day: hotel 1: 3 days × 2 TVs × 50.00 = 300.00; hotel 2: 3 × 1 × 20.50 = 61.50
        $q = Marketplace::quote([1, 2], 'per_day', $from, $to, null, 'food');
        $this->assertSame([], $q['errors']);
        $this->assertSame(3, $q['days']);
        $this->assertSame(['300.00', '61.50'], array_column($q['lines'], 'amount'));
        $this->assertSame([2, 1], array_column($q['lines'], 'tv_count'));
        $this->assertSame('361.50', $q['subtotal']);
        $this->assertSame('65.07', $q['tax'], '18 % GST');
        $this->assertSame('426.57', $q['total']);
        // CPM: only hotel 2 offers it: 2,500 impressions × 120.00 / 1000 = 300.00
        $q = Marketplace::quote([1, 2], 'cpm', $from, $to, 2500, 'food');
        $this->assertCount(1, $q['errors']);
        $this->assertSame(['300.00'], array_column($q['lines'], 'amount'));
        $this->assertSame(2500, $q['lines'][0]['impressions']);
        $this->assertSame('354.00', $q['total']);
        // Category blocked in hotel 1, hotel 3 does not sell, unknown hotel.
        $q = Marketplace::quote([1, 3, 999], 'per_day', $from, $to, null, 'religious');
        $this->assertSame([], $q['lines']);
        $this->assertCount(3, $q['errors']);
        // Tax setting change and rounding in paise.
        Settings::setPlatform('platform_mkt_tax_percent', '0');
        $this->assertSame('61.50', Marketplace::quote([2], 'per_day', $from, $to, null, 'food')['total']);
        Settings::setPlatform('platform_mkt_tax_percent', '18');
        $this->assertSame(1, Marketplace::days($from, $from));
        $this->assertSame(31, Marketplace::days('2026-01-01', '2026-01-31'));

        // Same numbers through the portal's JSON quote (server side), and the price is never taken from the client.
        $s = self::loginAdv('ks@adv.test', 'Sweets123');
        [$st, $j] = $s->json('advertise/ajax.php?action=quote', ['hotel_ids' => [1, 2], 'pricing_model' => 'per_day', 'start_date' => $from, 'end_date' => $to, 'category' => 'food', 'total' => '1.00']);
        $this->assertSame(200, $st, (string) json_encode($j));
        $this->assertSame('426.57', $j['data']['total']);
        [$st] = TestEnv::http('POST', self::$url . 'advertise/ajax.php?action=quote', ['hotel_ids' => [1]], ['X-CSRF-Token: wrong'], $s->jar);
        $this->assertSame(419, $st);
        [$st] = TestEnv::http('POST', self::$url . 'advertise/ajax.php?action=quote', ['hotel_ids' => [1]]);
        $this->assertSame(401, $st);
    }

    // ------------------------------------------------------------------ full lifecycle

    public function testBookingLifecycleCampaignTvAndProofOfPlay(): void
    {
        $s = self::loginAdv('ks@adv.test', 'Sweets123');
        $aid = self::$id['advA'];
        // Creatives: links refused; image upload validated; fake image refused.
        $s->get('advertise/creatives.php');
        $s->post('advertise/creatives.php', ['op' => 'text', 'title' => 'Bad', 'text' => 'Visit https://evil.test now']);
        $s->post('advertise/creatives.php', ['op' => 'text', 'title' => 'Bad2', 'text' => 'order at sweets.com']);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM mkt_creatives WHERE advertiser_id = :a', ['a' => $aid]));
        $fake = sys_get_temp_dir() . '/mkt_fake_' . getmypid() . '.jpg';
        file_put_contents($fake, '<?php echo "x"; ?>');
        $s->post('advertise/creatives.php', ['op' => 'upload', 'title' => 'Fake', 'file' => new CURLFile($fake, 'image/jpeg', 'ad.jpg')]);
        @unlink($fake);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM mkt_creatives WHERE advertiser_id = :a', ['a' => $aid]));
        $s->post('advertise/creatives.php', ['op' => 'upload', 'title' => 'Kaju katli', 'duration' => 20, 'file' => new CURLFile(self::$png, 'image/png', 'ad.png')]);
        $cr = DB::one('SELECT * FROM mkt_creatives WHERE advertiser_id = :a', ['a' => $aid]);
        $this->assertNotNull($cr);
        $this->assertSame('image', $cr['type']);
        $this->assertSame(20, (int) $cr['duration']);
        $this->assertStringStartsWith('adv/a' . $aid . '/', (string) $cr['file_path'], 'stored per advertiser');
        $this->assertFileExists(HC_ROOT . '/uploads/' . $cr['file_path']);
        [, , $html] = $s->get('advertise/creatives.php');
        $this->assertStringContainsString('tv-sim', $html, 'TV simulator preview');

        // Book hotels 1 + 2 from today for 3 days, submit.
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+2 days'));
        [, , $html] = $s->get('advertise/book.php');
        $this->assertStringContainsString('Gomti View', $html);
        [, , , $head] = $s->post('advertise/book.php', ['op' => 'submit', 'title' => 'Diwali sweets', 'category' => 'food', 'pricing_model' => 'per_day',
            'start_date' => $start, 'end_date' => $end, 'daily_start' => '', 'daily_end' => '', 'creative_id' => $cr['id'], 'hotel_ids' => [1, 2]]);
        $bid = (int) DB::value('SELECT id FROM mkt_bookings WHERE advertiser_id = :a ORDER BY id DESC', ['a' => $aid]);
        $this->assertStringContainsString('booking.php?id=' . $bid, self::location($head));
        $b = Marketplace::findBooking($bid);
        $this->assertSame('awaiting_payment', $b['status']);
        $this->assertSame('AD-' . date('Y') . '-' . str_pad((string) $bid, 5, '0', STR_PAD_LEFT), $b['number']);
        $this->assertSame('426.57', (string) $b['total']);
        $this->assertNotNull($b['expires_at']);
        self::$id['booking'] = $bid;

        // Order page: bank text + UPI QR (exact amount, order number).
        [$st, , $html] = $s->get('advertise/booking.php?id=' . $bid);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('A/c 123456', $html);
        $this->assertSame('upi://pay?pa=hotelcast%40okbank&pn=HotelCast%20Ads&am=426.57&tn=' . $b['number'] . '&cu=INR', Marketplace::upiUri($b));
        $this->assertStringContainsString(e(Marketplace::upiUri($b)), $html, 'UPI deep link');

        // Hotel 1 sees it as ordered, not yet approvable.
        $mgr = new AdminSession(self::$url, 'mkMgr1');
        [, , $html] = $mgr->get('marketplace.php');
        $this->assertStringContainsString('Ordered, not paid yet', $html);
        $this->assertStringNotContainsString('name="op" value="approve"', $html);

        // Platform marks paid → hotel 2 (auto) gets the campaign at once, hotel 1 must approve.
        $plat = new AdminSession(self::$url, 'mkPlat');
        [$st, , $html] = $plat->get('platform_marketplace.php?id=' . $bid);
        $this->assertSame(200, $st);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $plat->post('platform_marketplace.php', ['op' => 'paid', 'id' => $bid, 'back_id' => $bid, 'payment_ref' => '', 'payment_method' => 'UPI']);
        $this->assertSame('awaiting_payment', Marketplace::findBooking($bid)['status'], 'reference required');
        $plat->post('platform_marketplace.php', ['op' => 'paid', 'id' => $bid, 'back_id' => $bid, 'payment_ref' => 'UTR123456', 'payment_method' => 'UPI', 'paid_at' => date('Y-m-d')]);
        $b = Marketplace::findBooking($bid);
        $this->assertSame('running', $b['status'], 'hotel 2 auto-approved, starts today');
        $lines = [];
        foreach (Marketplace::lines($bid) as $l) {
            $lines[(int) $l['hotel_id']] = $l;
        }
        $this->assertSame('pending', $lines[1]['status']);
        $this->assertSame('approved', $lines[2]['status']);
        $this->assertSame('70.00', (string) $lines[1]['hotel_share_pct']);
        $this->assertSame('210.00', (string) $lines[1]['hotel_share']);
        $this->assertSame('90.00', (string) $lines[1]['platform_share']);
        $this->assertSame('43.05', (string) $lines[2]['hotel_share'], '70 % of 61.50');
        $this->assertSame('18.45', (string) $lines[2]['platform_share']);
        $c2 = (int) $lines[2]['campaign_id'];
        $this->assertGreaterThan(0, $c2);
        $camp = DB::one('SELECT * FROM ad_campaigns WHERE id = :id', ['id' => $c2]);
        $this->assertSame(2, (int) $camp['hotel_id']);
        $this->assertSame('all', $camp['target_type']);
        $this->assertSame($start, $camp['start_date']);
        $this->assertSame($end, $camp['end_date']);
        $this->assertSame('Krishna Sweets', DB::value('SELECT name FROM sponsors WHERE id = :id AND hotel_id = 2', ['id' => $camp['sponsor_id']]));
        $ci = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $camp['content_id']]);
        $this->assertSame(2, (int) $ci['hotel_id']);
        $this->assertStringStartsWith('h2/media/', (string) $ci['file_path'], 'creative copied into the hotel media');
        $this->assertFileExists(HC_ROOT . '/uploads/' . $ci['file_path']);

        // Hotel 1 manager approves through the admin page.
        [, , $html] = $mgr->get('marketplace.php');
        $this->assertStringContainsString('Diwali sweets', $html);
        $this->assertStringContainsString('tv-sim', $html);
        $mgr->post('marketplace.php', ['op' => 'approve', 'id' => $lines[1]['id']]);
        $l1 = DB::one('SELECT * FROM mkt_booking_hotels WHERE id = :id', ['id' => $lines[1]['id']]);
        $this->assertSame('approved', $l1['status']);
        $c1 = (int) $l1['campaign_id'];
        $this->assertSame(1, (int) DB::value('SELECT hotel_id FROM ad_campaigns WHERE id = :id', ['id' => $c1]));
        self::$id['c1'] = $c1;
        self::$id['c2'] = $c2;
        self::$id['line1'] = (int) $lines[1]['id'];
        self::$id['line2'] = (int) $lines[2]['id'];

        // The ad is in the TV content of hotels 1 and 2 only (hotel 3 is untouched).
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, self::$tv['h1101']);
        $this->assertSame(200, $st);
        $ads = array_values(array_filter($j['data']['content']['items'], fn ($i) => isset($i['ad_campaign_id'])));
        $this->assertNotEmpty($ads);
        $this->assertSame([$c1], array_values(array_unique(array_column($ads, 'ad_campaign_id'))), 'only hotel 1 campaign');
        $this->assertSame(20, $ads[0]['duration']);
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, self::$tv['h2201']);
        $this->assertSame([$c2], array_values(array_unique(array_column(array_filter($j['data']['content']['items'], fn ($i) => isset($i['ad_campaign_id'])), 'ad_campaign_id'))));
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, self::$tv['h3301']);
        $this->assertSame([], array_filter($j['data']['content']['items'], fn ($i) => isset($i['ad_campaign_id'])), 'hotel 3 shows no marketplace ad');

        // TVs report plays → advertiser's proof of play.
        $adContent1 = (int) DB::value('SELECT content_id FROM ad_campaigns WHERE id = :id', ['id' => $c1]);
        [$st] = TestEnv::http('POST', self::$url . 'api/device/played', ['items' => [
            ['content_id' => $adContent1, 'started_at' => date('c', time() - 120), 'duration_sec' => 20, 'ad_campaign_id' => $c1],
            ['content_id' => $adContent1, 'started_at' => date('c', time() - 60), 'duration_sec' => 20, 'ad_campaign_id' => $c1],
            ['content_id' => $adContent1, 'duration_sec' => 20, 'ad_campaign_id' => $c2], // other hotel's campaign → not counted
        ]], self::$tv['h1101']);
        $this->assertSame(200, $st);
        TestEnv::http('POST', self::$url . 'api/device/played', ['items' => [
            ['content_id' => (int) $camp['content_id'], 'duration_sec' => 20, 'ad_campaign_id' => $c2],
        ]], self::$tv['h2201']);
        $r = Marketplace::report(Marketplace::findBooking($bid));
        $per = array_column($r['hotels'], 'totals', 'hotel_id');
        $this->assertSame(2, $per[1]['impressions']);
        $this->assertSame(40, $per[1]['seconds']);
        $this->assertSame(1, $per[2]['impressions']);
        $this->assertSame(3, $r['totals']['impressions']);
        $this->assertSame(3, $r['per_day'][0]['impressions']);
        [$st, , $html] = $s->get('advertise/report.php?id=' . $bid);
        $this->assertSame(200, $st);
        $this->assertMatchesRegularExpression('/data-total-impressions>3</', $html);
        $this->assertStringContainsString('Dwarka Palace', $html);
        [$st, , $csv] = $s->get('advertise/report.php?id=' . $bid . '&csv=1');
        $this->assertSame(200, $st);
        $this->assertStringContainsString($start . ',"Dwarka Palace",Dwarka,2,40,1', $csv);
        [$st, , $html] = $s->get('advertise/report.php?id=' . $bid . '&print=1');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Print / Save as PDF', $html);
        // Hotel sponsor report keeps working for the marketplace campaign.
        $this->assertSame(200, $mgr->get('sponsor_report.php?campaign_id=' . $c1)[0]);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ capacity

    public function testCapacityMaxAdsPerLoop(): void
    {
        // Hotel 1 allows 2 marketplace ads per loop; booking #1 holds one slot today..+2.
        $b = self::advertiser('Taxi Co', 'taxi@adv.test');
        self::$id['advB'] = $b;
        $first = self::booking($b, ['title' => 'Taxi 1'], [1]);
        Marketplace::submit($b, $first);
        $second = self::booking($b, ['title' => 'Taxi 2'], [1]);
        try {
            Marketplace::submit($b, $second);
            $this->fail('hotel 1 is full');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fully booked', $e->getMessage());
        }
        $this->assertSame('draft', Marketplace::booking($b, $second)['status']);
        // Later dates are free.
        $q = Marketplace::quote([1], 'per_day', date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+6 days')), null, 'food');
        $this->assertSame([], $q['errors']);
        Marketplace::cancelByAdvertiser($b, $first, 'changed plans');
        $this->assertSame('cancelled', Marketplace::booking($b, $first)['status']);
        Marketplace::submit($b, $second);
        $this->assertSame('awaiting_payment', Marketplace::booking($b, $second)['status'], 'slot freed by the cancellation');
        self::$id['bookingB'] = $second;
    }

    // ------------------------------------------------------------------ rejection & refunds

    public function testHotelRejectionAndPlatformModeration(): void
    {
        $b = self::$id['advB'];
        $bid = self::$id['bookingB'];
        Marketplace::markPaid($bid, 'UTR-B', 'Bank transfer', null, null);
        $this->assertSame('paid', Marketplace::booking($b, $bid)['status'], 'waiting for hotel 1');
        $line = (int) DB::value('SELECT id FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bid]);

        // Hotel 2 manager can not decide hotel 1's line (404, unchanged).
        $mgr2 = new AdminSession(self::$url, 'mkMgr2');
        [$st] = $mgr2->post('marketplace.php', ['op' => 'approve', 'id' => $line]);
        $this->assertSame(404, $st);
        [$st] = $mgr2->post('marketplace.php', ['op' => 'reject', 'id' => $line, 'reason' => 'x']);
        $this->assertSame(404, $st);
        $this->assertSame('pending', DB::value('SELECT status FROM mkt_booking_hotels WHERE id = :id', ['id' => $line]));

        // Hotel 1 rejects with reason → booking rejected (no hotel left), full refund noted.
        $mgr = new AdminSession(self::$url, 'mkMgr1');
        $mgr->post('marketplace.php', ['op' => 'reject', 'id' => $line, 'reason' => '']);
        $this->assertSame('pending', DB::value('SELECT status FROM mkt_booking_hotels WHERE id = :id', ['id' => $line]), 'reason required');
        $mgr->post('marketplace.php', ['op' => 'reject', 'id' => $line, 'reason' => 'We do not take taxi ads', 'bad_creative' => 1]);
        $bk = Marketplace::booking($b, $bid);
        $this->assertSame('rejected', $bk['status']);
        $this->assertSame((string) $bk['total'], (string) $bk['refund_amount']);
        $this->assertSame('rejected', DB::value('SELECT status FROM mkt_booking_hotels WHERE id = :id', ['id' => $line]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM mkt_booking_events WHERE booking_id = :b AND status = 'rejected' AND hotel_id = 1 AND note LIKE '%We do not take taxi ads%'", ['b' => $bid]));
        $this->assertNotEmpty(array_filter(self::$mails, fn ($m) => $m[0] === 'taxi@adv.test' && str_contains($m[1], 'Payment received')), 'advertiser emailed');

        // Platform moderation: a running booking whose ad breaks the rules → creative + booking rejected, campaigns paused.
        $cr = self::textCreative($b, 'Taxi fast');
        $bid2 = self::booking($b, ['title' => 'Taxi 3', 'creative_id' => $cr, 'start_date' => date('Y-m-d', strtotime('+20 days')), 'end_date' => date('Y-m-d', strtotime('+21 days'))], [2]);
        Marketplace::submit($b, $bid2);
        Marketplace::markPaid($bid2, 'UTR-C');
        $l = DB::one('SELECT * FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bid2]);
        $this->assertSame('approved', $l['status']);
        $this->assertSame('scheduled', Marketplace::booking($b, $bid2)['status'], 'starts in 20 days');
        $plat = new AdminSession(self::$url, 'mkPlat');
        $plat->post('platform_marketplace.php', ['op' => 'reject', 'id' => $bid2, 'back_id' => $bid2, 'reason' => 'Misleading offer', 'refund_note' => 'Refunded via UPI UTR999', 'reject_creative' => 1]);
        $bk = Marketplace::booking($b, $bid2);
        $this->assertSame('rejected', $bk['status']);
        $this->assertSame('Refunded via UPI UTR999', $bk['refund_note']);
        $this->assertSame((string) $bk['total'], (string) $bk['refund_amount']);
        $this->assertSame('rejected', DB::value('SELECT status FROM mkt_creatives WHERE id = :id', ['id' => $cr]));
        $this->assertSame('paused', DB::value('SELECT status FROM ad_campaigns WHERE id = :id', ['id' => (int) $l['campaign_id']]));
        // A rejected creative can not be booked again.
        [, , $err] = Marketplace::validateBooking($b, ['title' => 'x', 'category' => 'food', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'creative_id' => $cr, 'hotel_ids' => [2]]);
        $this->assertContains('Choose an ad (creative).', $err);
        // Advertiser sees reason + refund.
        $s = new AdvSession(self::$url);
        $s->get('advertise/login.php');
        $s->post('advertise/login.php', ['email' => 'taxi@adv.test', 'password' => 'Passw0rd!']);
        [, , $html] = $s->get('advertise/booking.php?id=' . $bid2);
        $this->assertStringContainsString('Misleading offer', $html);
        $this->assertStringContainsString('Refunded via UPI UTR999', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ earnings & payouts

    public function testRevenueShareAndPayoutStatements(): void
    {
        $period = date('Y-m');
        $res = Marketplace::generatePayouts($period);
        $this->assertSame([1, 2], array_keys($res));
        $p1 = DB::one('SELECT * FROM mkt_payouts WHERE id = :id', ['id' => $res[1]]);
        $p2 = DB::one('SELECT * FROM mkt_payouts WHERE id = :id', ['id' => $res[2]]);
        $this->assertSame('210.00', (string) $p1['amount'], 'only approved lines (the rejected taxi line is not paid out)');
        $this->assertSame('300.00', (string) $p1['gross']);
        $this->assertSame(1, (int) $p1['lines_count']);
        $this->assertSame('43.05', (string) $p2['amount']);
        // Idempotent (nothing new to add).
        $this->assertSame([], Marketplace::generatePayouts($period));
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM mkt_payouts'));

        // Hotel 1 sees its own earnings and statement only.
        $boss = new AdminSession(self::$url, 'mkBoss1');
        [$st, , $html] = $boss->get('marketplace.php?tab=earnings&from=' . date('Y-m-01') . '&to=' . date('Y-m-d'));
        $this->assertSame(200, $st);
        $this->assertMatchesRegularExpression('/data-share>₹210\.00</', $html);
        $this->assertStringNotContainsString('43.05', $html);
        [$st] = $boss->get('marketplace.php?tab=earnings&payout=' . $res[2]);
        $this->assertSame(404, $st, 'other hotel statement');
        Tenant::run(2, function () {
            $e = Marketplace::hotelEarnings(date('Y-m-01'), date('Y-m-d'));
            $this->assertSame('43.05', $e['share']);
            $this->assertCount(1, $e['rows']);
        });
        // Manager has no earnings tab.
        $mgr = new AdminSession(self::$url, 'mkMgr1');
        $this->assertStringNotContainsString('data-share', $mgr->get('marketplace.php?tab=earnings')[2]);

        // Platform marks hotel 1's statement paid; paid statements never change.
        $plat = new AdminSession(self::$url, 'mkPlat');
        [, , $html] = $plat->get('platform_marketplace.php?tab=payouts&payout=' . $res[1]);
        $this->assertStringContainsString('Dwarka Palace', $html);
        $plat->post('platform_marketplace.php', ['op' => 'payout_paid', 'tab' => 'payouts', 'id' => $res[1], 'payment_ref' => 'NEFT-77']);
        $this->assertSame('paid', DB::value('SELECT status FROM mkt_payouts WHERE id = :id', ['id' => $res[1]]));
        try {
            Marketplace::markPayoutPaid($res[1], 'again');
            $this->fail('already paid');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        [, , $html] = $plat->get('platform_marketplace.php');
        $this->assertMatchesRegularExpression('/data-kpi="gross">₹361\.50</', $html);
        $this->assertMatchesRegularExpression('/data-kpi="platform">₹108\.45</', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ isolation

    public function testAdvertiserIsolation(): void
    {
        $a = self::$id['advA'];
        $bid = self::$id['booking'];
        $b = self::$id['advB'];
        $this->assertNull(Marketplace::booking($b, $bid));
        $crA = (int) DB::value('SELECT id FROM mkt_creatives WHERE advertiser_id = :a', ['a' => $a]);
        $this->assertNull(Marketplace::creative($b, $crA));
        [, , $err] = Marketplace::validateBooking($b, ['title' => 'x', 'category' => 'food', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'creative_id' => $crA, 'hotel_ids' => [2]]);
        $this->assertContains('Choose an ad (creative).', $err, "B can not book A's creative");
        $this->assertFalse(Marketplace::deleteCreative($b, $crA));

        $s = new AdvSession(self::$url);
        $s->get('advertise/login.php');
        $s->post('advertise/login.php', ['email' => 'taxi@adv.test', 'password' => 'Passw0rd!']);
        foreach (['advertise/booking.php?id=' . $bid, 'advertise/report.php?id=' . $bid, 'advertise/report.php?id=' . $bid . '&csv=1'] as $p) {
            [$st, , $html] = $s->get($p);
            $this->assertSame(404, $st, $p);
            $this->assertStringNotContainsString('Diwali sweets', $html);
        }
        [, , $html] = $s->get('advertise/index.php');
        $this->assertStringNotContainsString('Diwali sweets', $html);
        $s->post('advertise/booking.php', ['id' => $bid, 'op' => 'cancel', 'reason' => 'hack']);
        $this->assertSame('running', Marketplace::findBooking($bid)['status'], "B can not cancel A's order");
        $s->post('advertise/creatives.php', ['op' => 'delete', 'id' => $crA]);
        $this->assertSame('active', DB::value('SELECT status FROM mkt_creatives WHERE id = :id', ['id' => $crA]));
        // A can not cancel a paid order itself.
        try {
            Marketplace::cancelByAdvertiser($a, $bid);
            $this->fail('paid orders are cancelled by the platform');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        // Hotel 2 lists only its own lines; hotel 1 line ids are foreign.
        Tenant::run(2, function () {
            $this->assertSame([2], array_values(array_unique(array_map('intval', array_column(Marketplace::hotelLines(), 'hotel_id')))));
            $this->expectException(TenantException::class);
            Marketplace::hotelLine(self::$id['line1']);
        });
    }

    // ------------------------------------------------------------------ periodic task

    public function testTaskExpiresCompletesAndStopsCpm(): void
    {
        $b = self::$id['advB'];
        // Unpaid order past its deadline → cancelled.
        $q = self::booking($b, ['title' => 'Late payer', 'start_date' => date('Y-m-d', strtotime('+30 days')), 'end_date' => date('Y-m-d', strtotime('+30 days'))], [2]);
        Marketplace::submit($b, $q);
        DB::query('UPDATE mkt_bookings SET expires_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', time() - 60), 'id' => $q]);
        // CPM order in hotel 2: 1,000 impressions ordered.
        $cpm = self::booking($b, ['title' => 'CPM', 'pricing_model' => 'cpm', 'impressions' => 1000, 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+9 days'))], [2]);
        Marketplace::submit($b, $cpm);
        $this->assertSame('141.60', (string) Marketplace::booking($b, $cpm)['total'], '1000 × 120 / 1000 + 18 %');
        Marketplace::markPaid($cpm, 'UTR-CPM');
        $line = DB::one('SELECT * FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $cpm]);
        $this->assertSame('approved', $line['status']);
        Tenant::run(2, function () use ($line) {
            for ($i = 0; $i < 1000; $i += 250) {
                $rows = [];
                for ($k = 0; $k < 250; $k++) {
                    $rows[] = '(2, ' . (int) $line['campaign_id'] . ", 'played', 15, NOW())";
                }
                DB::query('INSERT INTO broadcast_logs (hotel_id, ad_campaign_id, event, duration_sec, created_at) VALUES ' . implode(',', $rows));
            }
        });
        // A running booking whose end date passed → completed.
        DB::query('UPDATE mkt_bookings SET start_date = :s, end_date = :e WHERE id = :id', ['s' => date('Y-m-d', strtotime('-5 days')), 'e' => date('Y-m-d', strtotime('-1 day')), 'id' => self::$id['booking']]);

        Settings::setPlatform('task_last_MarketplaceTask', '0');
        $res = (new MarketplaceTask())->run();
        $this->assertSame(1, $res['expired']);
        $this->assertSame(1, $res['delivered']);
        $this->assertSame('cancelled', Marketplace::booking($b, $q)['status']);
        $this->assertSame('Payment not received in time.', Marketplace::booking($b, $q)['status_reason']);
        $this->assertSame('delivered', DB::value('SELECT status FROM mkt_booking_hotels WHERE id = :id', ['id' => $line['id']]));
        $this->assertSame('paused', DB::value('SELECT status FROM ad_campaigns WHERE id = :id', ['id' => $line['campaign_id']]));
        $this->assertSame('completed', Marketplace::booking($b, $cpm)['status'], 'all hotels delivered');
        $this->assertSame('completed', Marketplace::findBooking(self::$id['booking'])['status']);
        // Idempotent second run.
        $res = Marketplace::housekeeping();
        $this->assertSame(['expired' => 0, 'delivered' => 0, 'updated' => 0], $res);
    }

    // ------------------------------------------------------------------ pages

    public function testPagesRenderWithoutErrors(): void
    {
        $s = self::loginAdv('ks@adv.test', 'Sweets123');
        foreach (['advertise/', 'advertise/index.php', 'advertise/hotels.php?city=dwarka&category=food', 'advertise/book.php', 'advertise/book.php?hotel=2',
            'advertise/creatives.php', 'advertise/booking.php?id=' . self::$id['booking'], 'advertise/index.php?lang=gu', 'advertise/hotels.php'] as $p) {
            [$st, , $html] = $s->get($p);
            $this->assertSame(200, $st, $p);
            $this->assertFalse(TestEnv::hasPhpError($html), $p);
        }
        $this->assertStringContainsString('હોટેલ', $s->get('advertise/hotels.php')[2], 'Gujarati UI');
        $s->get('advertise/index.php?lang=en');
        foreach (['advertise/', 'advertise/login.php', 'advertise/signup.php', 'advertise/hotels.php'] as $p) {
            [$st, , $html] = TestEnv::http('GET', self::$url . $p);
            $this->assertSame(200, $st, $p);
            $this->assertFalse(TestEnv::hasPhpError($html), $p);
        }
        $plat = new AdminSession(self::$url, 'mkPlat');
        foreach (['overview', 'orders', 'advertisers', 'payouts', 'settings'] as $t) {
            [$st, , $html] = $plat->get('platform_marketplace.php?tab=' . $t);
            $this->assertSame(200, $st, $t);
            $this->assertFalse(TestEnv::hasPhpError($html), $t);
        }
        $this->assertStringContainsString('platform_marketplace.php', $plat->get('platform_marketplace.php')[2], 'menu item');
        // Platform settings validation.
        $plat->post('platform_marketplace.php', ['op' => 'settings', 'tab' => 'settings', 'platform_mkt_enabled' => 1, 'platform_mkt_hotel_share' => '75', 'platform_mkt_tax_percent' => '18',
            'platform_mkt_upi_id' => 'not a upi', 'platform_mkt_expire_days' => 5, 'platform_mkt_max_days' => 90, 'platform_mkt_max_image_mb' => 10, 'platform_mkt_max_video_mb' => 50]);
        Settings::flush();
        $this->assertSame('70', Marketplace::setting('platform_mkt_hotel_share'), 'invalid UPI → nothing saved');
        $plat->post('platform_marketplace.php', ['op' => 'settings', 'tab' => 'settings', 'platform_mkt_enabled' => 1, 'platform_mkt_hotel_share' => '75', 'platform_mkt_tax_percent' => '18',
            'platform_mkt_upi_id' => 'ads@okicici', 'platform_mkt_signup_otp' => 1, 'platform_mkt_expire_days' => 5, 'platform_mkt_max_days' => 90, 'platform_mkt_max_image_mb' => 10,
            'platform_mkt_max_video_mb' => 50, 'platform_mkt_rules' => 'Be nice']);
        Settings::flush();
        $this->assertSame('75.00', Marketplace::setting('platform_mkt_hotel_share'));
        $this->assertSame('ads@okicici', Marketplace::setting('platform_mkt_upi_id'));
        // Hotel users never reach the platform page; advertiser pages need no admin login.
        $boss = new AdminSession(self::$url, 'mkBoss1');
        $this->assertSame(403, $boss->get('platform_marketplace.php')[0]);
        foreach (['requests', 'bookings', 'earnings', 'settings'] as $t) {
            [$st, , $html] = $boss->get('marketplace.php?tab=' . $t);
            $this->assertSame(200, $st, $t);
            $this->assertFalse(TestEnv::hasPhpError($html), $t);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }
}
