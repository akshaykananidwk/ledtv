<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Online sign-up + free trial (#17) and demo mode (#21): sign-up form (validation, honeypot,
 * time-to-submit, captcha, rate limits, disposable e-mail), OTP verification (hashed, expiry,
 * attempt limit), duplicates without enumeration, auto / manual approval, trial reminders + expiry
 * (TV "service paused", admin read-only), upgrade → invoice → paid → active, public demo hotel (rich
 * data, nightly reset, read-only demo user: every admin POST / AJAX action blocked), public demo
 * pages expose the demo hotel only, client demos (create, reseller scope, auto-expiry + purge).
 */
final class SignupDemoTest extends TestCase
{
    private static string $url;
    private static ?AdminSession $root = null;
    private static array $mails = [];
    private static int $basicPlan = 0;
    private static int $standardPlan = 0;
    private static int $trialHotel = 0;      // created in auto mode, used by the lifecycle test
    private static string $trialEmail = 'owner.auto@sagar-hotel.test';
    private static int $demoHotel = 0;
    /** @var string[] */
    private static array $jars = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'root', 'email' => 'root@platform.test', 'full_name' => 'Root', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'platform_admin']);
        self::$basicPlan = (int) DB::value("SELECT id FROM plans WHERE name = 'Basic'");
        self::$standardPlan = (int) DB::value("SELECT id FROM plans WHERE name = 'Standard'");
        foreach ([
            'signup_enabled' => '1', 'signup_mode' => 'otp', 'trial_days' => '14', 'trial_plan_id' => (string) self::$basicPlan,
            'trial_max_tvs' => '5', 'signup_min_seconds' => '3', 'signup_captcha' => '0', 'billing_tax_percent' => '18',
            'invoice_prefix' => 'TRL', 'platform_notify_email' => 'sales@platform.test', 'invoice_auto_generate' => '0',
        ] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        self::$root = new AdminSession(self::$url, 'root');
        Notifier::$mailer = static function (string $to, string $subject, string $message): bool {
            self::$mails[] = [$to, $subject, $message];
            return true;
        };
    }

    public static function tearDownAfterClass(): void
    {
        Notifier::$mailer = null;
        Config::set('mode', 'saas');
        TestEnv::writeConfig(HC_ROOT, self::$url);
        foreach (self::$jars as $j) {
            @unlink($j);
        }
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Settings::flush();
        Tenant::forget();
        Demo::forget();
    }

    // ------------------------------------------------------------------ helpers

    private static function jar(): string
    {
        return self::$jars[] = (string) tempnam(sys_get_temp_dir(), 'pub');
    }

    /** GET a public page with a cookie jar: [csrf, html, status]. */
    private static function open(string $path, string $jar): array
    {
        [$s, , $html] = TestEnv::http('GET', self::$url . $path, null, [], $jar);
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
        return [html_entity_decode($m[1] ?? ''), $html, $s];
    }

    private static function form(array $over = []): array
    {
        static $n = 0;
        $n++;
        return $over + [
            'hotel_name' => 'Hotel Test ' . $n, 'city' => 'Dwarka', 'owner_name' => 'Owner ' . $n, 'mobile' => '+91 98250 ' . sprintf('%05d', 10000 + $n),
            'email' => 'owner' . $n . '@hotel' . $n . '.test', 'tv_estimate' => '12', 'language' => 'en', 'password' => 'Trial2026pass',
            'accept_terms' => '1', 'website' => '', 'ts' => Signup::formToken(time() - 10),
        ];
    }

    /** Submit the sign-up form: [status, body, location, jar]. */
    private static function signup(array $fields, ?string $jar = null): array
    {
        $jar ??= self::jar();
        [$csrf, $html] = self::open('signup.php', $jar);
        if (isset($fields['captcha']) && $fields['captcha'] === 'auto' && preg_match('/what is (\d+) \+ (\d+)\?/', $html, $m)) {
            $fields['captcha'] = (string) ((int) $m[1] + (int) $m[2]);
        }
        [$s, , $body, $head] = TestEnv::http('POST', self::$url . 'signup.php', null, [], $jar, ['_csrf' => $csrf, 'op' => 'register'] + $fields);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return [$s, $body, $m[1] ?? '', $jar];
    }

    private static function verify(string $jar, string $code, string $op = 'verify'): array
    {
        [$csrf] = self::open('signup.php?step=verify', $jar);
        [$s, , $body, $head] = TestEnv::http('POST', self::$url . 'signup.php?step=verify', null, [], $jar, ['_csrf' => $csrf, 'op' => $op, 'code' => $code]);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return [$s, $body, $m[1] ?? ''];
    }

    private static function setOtp(int $signupId, string $code): void
    {
        DB::query('UPDATE signups SET otp_hash = :h, otp_expires_at = :x WHERE id = :id', ['h' => password_hash($code, PASSWORD_BCRYPT), 'x' => date('Y-m-d H:i:s', time() + 600), 'id' => $signupId]);
    }

    /** Admin session from an existing cookie jar (after signup / demo login). */
    private static function sessionFromJar(string $jar): AdminSession
    {
        $sess = (new ReflectionClass(AdminSession::class))->newInstanceWithoutConstructor();
        $sess->base = self::$url;
        $sess->jar = $jar;
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/profile.php', null, [], $jar);
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
        $sess->csrf = html_entity_decode($m[1] ?? '');
        return $sess;
    }

    private static function hotelSnapshot(int $hid): array
    {
        $out = [];
        foreach (DB::column("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'hotel_id' AND c.TABLE_NAME NOT IN ('activity_logs', 'device_status_logs', 'devices') ORDER BY c.TABLE_NAME") as $t) {
            $rows = DB::all("SELECT * FROM `$t` WHERE hotel_id = :h", ['h' => $hid]);
            foreach ($rows as &$r) {
                unset($r['updated_at'], $r['last_activity'], $r['last_login_at'], $r['last_login_ip'], $r['language']);
            }
            unset($r);
            $out[$t] = md5(json_encode($rows));
        }
        $out['hotel'] = md5(json_encode(DB::one('SELECT name, status, plan_id, max_tvs, expires_at, registration_key FROM hotels WHERE id = :h', ['h' => $hid])));
        $out['devices'] = md5(json_encode(DB::all('SELECT id, device_uid, room_id, is_revoked FROM devices WHERE hotel_id = :h ORDER BY id', ['h' => $hid])));
        return $out;
    }

    // ------------------------------------------------------------------ sign-up

    public function testSignupPageOnlyInSaasModeWhenEnabled(): void
    {
        Settings::setPlatform('signup_enabled', '0');
        [$s] = TestEnv::http('GET', self::$url . 'signup.php');
        $this->assertSame(404, $s);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringNotContainsString('signup.php', $html);

        Settings::setPlatform('signup_enabled', '1');
        [$s, , $html] = TestEnv::http('GET', self::$url . 'signup.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="website"', $html, 'honeypot field');
        $this->assertStringContainsString('name="ts"', $html, 'time-to-submit token');
        $this->assertStringContainsString('name="accept_terms"', $html);
        $this->assertStringContainsString('The trial is free', $html, 'terms text from platform settings');
        $this->assertStringContainsString('width=device-width', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringContainsString('signup.php', $html, '"Start free trial" on the login page');
        // Gujarati UI.
        [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?lang=gu');
        $this->assertStringContainsString('મફત ટ્રાયલ', $html);

        // Standalone (self-hosted) installs hide it.
        TestEnv::writeConfig(HC_ROOT, self::$url, ['mode' => 'standalone']);
        try {
            [$s] = TestEnv::http('GET', self::$url . 'signup.php');
            $this->assertSame(404, $s);
            [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
            $this->assertStringNotContainsString('signup.php', $html);
        } finally {
            TestEnv::writeConfig(HC_ROOT, self::$url);
        }
    }

    public function testValidation(): void
    {
        $before = (int) DB::value('SELECT COUNT(*) FROM signups');
        [$s, $body] = self::signup(self::form(['email' => 'not-an-email', 'password' => 'short', 'accept_terms' => '', 'mobile' => '12', 'hotel_name' => '', 'tv_estimate' => '0']));
        $this->assertSame(200, $s);
        foreach (['Enter a valid email address.', 'Password must be at least 8 characters.', 'Please accept the terms to continue.', 'Enter a valid mobile number', 'Business name is required.', 'Enter the number of screens / TVs'] as $msg) {
            $this->assertStringContainsString($msg, $body);
        }
        [, $body] = self::signup(self::form(['email' => 'someone@mailinator.com']));
        $this->assertStringContainsString('temporary email addresses are not accepted', $body);
        [, $body] = self::signup(self::form(['password' => 'onlyletterspassword']));
        $this->assertStringContainsString('Password must contain letters and numbers.', $body);
        // Entered values are kept and escaped.
        [, $body] = self::signup(self::form(['hotel_name' => '<script>alert(1)</script>', 'email' => 'bad']));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        // Missing CSRF token.
        [$s] = TestEnv::http('POST', self::$url . 'signup.php', null, [], self::jar(), ['op' => 'register'] + self::form());
        $this->assertSame(419, $s);
        $this->assertSame($before, (int) DB::value('SELECT COUNT(*) FROM signups'), 'nothing stored');
    }

    public function testAntiAbuse(): void
    {
        $before = (int) DB::value('SELECT COUNT(*) FROM signups');
        // Honeypot: looks like success, nothing stored.
        [$s, , $loc] = self::signup(self::form(['website' => 'http://spam.example']));
        $this->assertSame(302, $s);
        $this->assertStringContainsString('step=pending', $loc);
        // Too fast (form rendered "now", minimum 3 s), forged and expired tokens.
        [, $body] = self::signup(self::form(['ts' => Signup::formToken(time())]));
        $this->assertStringContainsString('That was very fast', $body);
        [, $body] = self::signup(self::form(['ts' => (time() - 20) . '.' . str_repeat('a', 32)]));
        $this->assertStringContainsString('The form has expired', $body);
        [, $body] = self::signup(self::form(['ts' => Signup::formToken(time() - 3 * 86400)]));
        $this->assertStringContainsString('The form has expired', $body);
        $this->assertSame($before, (int) DB::value('SELECT COUNT(*) FROM signups'));

        // Math captcha.
        Settings::setPlatform('signup_captcha', '1');
        try {
            [, $body] = self::signup(self::form(['captcha' => '999']));
            $this->assertStringContainsString('Wrong answer to the math question.', $body);
            [$s, , $loc] = self::signup(self::form(['captcha' => 'auto']));
            $this->assertSame(302, $s);
            $this->assertStringContainsString('step=verify', $loc);
        } finally {
            Settings::setPlatform('signup_captcha', '0');
        }

        // Per IP: 3 sign-ups per hour (the captcha one above was #1).
        DB::query('DELETE FROM rate_limits');
        for ($i = 0; $i < 3; $i++) {
            [$s] = self::signup(self::form());
            $this->assertSame(302, $s, 'sign-up ' . ($i + 1));
        }
        [$s, $body] = self::signup(self::form());
        $this->assertSame(429, $s);
        $this->assertStringContainsString('Too many sign-up attempts', $body);

        // Per e-mail / mobile: 3 per day, also from other IPs.
        DB::query('DELETE FROM rate_limits');
        $f = self::form(['email' => 'repeat@repeat-hotel.test']);
        for ($i = 0; $i < 3; $i++) {
            DB::query("DELETE FROM rate_limits WHERE rl_key LIKE 'signup_ip:%'");
            [$s] = self::signup(['ts' => Signup::formToken(time() - 10), 'mobile' => '+91 90000 0000' . $i] + $f);
            $this->assertSame(302, $s);
        }
        DB::query("DELETE FROM rate_limits WHERE rl_key LIKE 'signup_ip:%'");
        [$s] = self::signup(['ts' => Signup::formToken(time() - 10), 'mobile' => '+91 90000 00009'] + $f);
        $this->assertSame(429, $s);
        // Only the newest unverified request of an address stays open.
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM signups WHERE email = 'repeat@repeat-hotel.test' AND status = 'verify'"));
    }

    public function testOtpSignupHappyPath(): void
    {
        $f = self::form(['hotel_name' => 'Hotel Gomti View', 'email' => 'Owner@Gomti-View.test', 'tv_estimate' => '30', 'language' => 'gu']);
        [$s, , $loc, $jar] = self::signup($f);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('signup.php?step=verify', $loc);
        $row = DB::one("SELECT * FROM signups WHERE email = 'owner@gomti-view.test' ORDER BY id DESC LIMIT 1");
        $this->assertSame('verify', $row['status']);
        $this->assertSame(1, (int) $row['otp_sends']);
        $this->assertStringStartsWith('$2y$', (string) $row['otp_hash'], 'OTP stored as bcrypt hash');
        $this->assertStringStartsWith('$2y$', (string) $row['password_hash']);
        $this->assertTrue(password_verify('Trial2026pass', (string) $row['password_hash']));
        $this->assertGreaterThan(time() + 600, strtotime((string) $row['otp_expires_at']));
        [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?step=verify', null, [], $jar);
        $this->assertStringContainsString('o••••@gomti-view.test', $html, 'masked e-mail');

        self::setOtp((int) $row['id'], '424242');
        [$s, $body] = self::verify($jar, '111111');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('The code is not valid or has expired.', $body);
        $this->assertStringContainsString('4 attempts left', $body);
        [$s, , $loc] = self::verify($jar, '424 242');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('admin/getting_started.php?welcome=1', $loc);

        $row = Signup::find((int) $row['id']);
        $this->assertSame('approved', $row['status']);
        $this->assertNull($row['password_hash']);
        $this->assertNull($row['otp_hash']);
        $hid = (int) $row['hotel_id'];
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $hid]);
        $this->assertSame('Hotel Gomti View', $h['name']);
        $this->assertSame('active', $h['status']);
        $this->assertSame(1, (int) $h['is_trial']);
        $this->assertSame(self::$basicPlan, (int) $h['plan_id']);
        $this->assertSame(5, (int) $h['max_tvs']);
        $this->assertEqualsWithDelta(time() + 14 * 86400, strtotime((string) $h['expires_at']), 120);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', (string) $h['registration_key']);
        $this->assertSame((string) $h['registration_key'], Settings::getFor($hid, 'registration_key'));
        $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $row['user_id']]);
        $this->assertSame('super_admin', $u['role']);
        $this->assertSame($hid, (int) $u['hotel_id']);
        $this->assertSame('owner', $u['username']);
        $this->assertSame('gu', $u['language']);
        $this->assertSame('gu', Settings::getFor($hid, 'default_language'));
        // Demo content: rooms 101..120 (estimate 30 → max 20), playlist as default content.
        $rooms = DB::column('SELECT room_number FROM rooms WHERE hotel_id = :h ORDER BY id', ['h' => $hid]);
        $this->assertCount(20, $rooms);
        $this->assertSame('101', $rooms[0]);
        $this->assertSame('120', $rooms[19]);
        $this->assertGreaterThan(0, (int) Settings::getFor($hid, 'default_playlist_id'));
        $this->assertSame(5, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h', ['h' => $hid]));

        // Logged in → getting started (checklist, QR link, trial banner), Gujarati.
        [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/getting_started.php?welcome=1&lang=gu', null, [], $jar);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $sess = self::sessionFromJar($jar);
        $sess->ajax('set_language', ['lang' => 'en']);
        [$s, , $html] = $sess->get('getting_started.php?welcome=1');
        $this->assertStringContainsString('Your free trial has started.', $html);
        $this->assertStringContainsString('data-step="rooms" data-done="1"', $html);
        $this->assertStringContainsString('data-step="tv" data-done="0"', $html);
        $this->assertStringContainsString('claim.php', $html);
        $this->assertStringContainsString('1 of 5 steps done', $html);
        $this->assertStringContainsString('Free trial: 14 days left.', $html);
        $this->assertStringContainsString('getting_started.php', $html, 'menu entry');
        [$s, $j] = $sess->ajax('signup_checklist');
        $this->assertSame(200, $s);
        $this->assertSame(20, $j['data']['percent']);
        [, , $html] = $sess->get('rooms.php');
        $this->assertStringContainsString('120', $html, 'only own rooms, in own hotel');
        // The same address cannot start a second trial: same answer, no code, nothing created.
        DB::query('DELETE FROM rate_limits');
        [$s, , $loc, $jar2] = self::signup(self::form(['email' => 'owner@gomti-view.test']));
        $this->assertSame(302, $s);
        $this->assertStringContainsString('step=verify', $loc, 'same answer as a new address');
        $dup = DB::one("SELECT * FROM signups WHERE email = 'owner@gomti-view.test' ORDER BY id DESC LIMIT 1");
        $this->assertSame(1, (int) $dup['duplicate']);
        $this->assertNull($dup['otp_hash']);
        $this->assertNull($dup['password_hash']);
        [$s, $body] = self::verify($jar2, '000000');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('The code is not valid or has expired.', $body);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM hotels WHERE contact_email = 'owner@gomti-view.test'"));
    }

    public function testOtpExpiryAndAttemptLimit(): void
    {
        [, , , $jar] = self::signup(self::form(['email' => 'limit@limit-hotel.test']));
        $id = (int) DB::value("SELECT id FROM signups WHERE email = 'limit@limit-hotel.test' ORDER BY id DESC LIMIT 1");
        self::setOtp($id, '135790');
        DB::query('UPDATE signups SET otp_expires_at = :x WHERE id = :id', ['x' => date('Y-m-d H:i:s', time() - 5), 'id' => $id]);
        [, $body] = self::verify($jar, '135790');
        $this->assertStringContainsString('The code is not valid or has expired.', $body, 'expired code refused');
        self::setOtp($id, '135790');
        for ($i = 0; $i < 3; $i++) {
            [, $body] = self::verify($jar, '000000');
        }
        $this->assertStringContainsString('1 attempts left', $body);
        [, $body] = self::verify($jar, '999999');
        $this->assertStringContainsString('Too many wrong codes', $body, '5 attempts (incl. the expired one) end the request');
        [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?step=verify', null, [], $jar);
        $this->assertStringContainsString('This sign-up has expired', $html, 'the code can no longer be used');
        $this->assertSame('expired', DB::value('SELECT status FROM signups WHERE id = :id', ['id' => $id]));
        $this->assertNull(DB::value('SELECT hotel_id FROM signups WHERE id = :id', ['id' => $id]));
        // Resend limit: max 3 codes.
        [, , , $jar] = self::signup(self::form(['email' => 'resend@limit-hotel.test']));
        $id = (int) DB::value("SELECT id FROM signups WHERE email = 'resend@limit-hotel.test'");
        [, $body] = self::verify($jar, '', 'resend');
        $this->assertStringContainsString('Please wait a minute', $body, 'cool-down');
        DB::query('UPDATE signups SET otp_expires_at = :x WHERE id = :id', ['x' => date('Y-m-d H:i:s', time() + 600), 'id' => $id]);
        [, $body] = self::verify($jar, '', 'resend');
        $this->assertStringContainsString('We sent a new code.', $body);
        $this->assertSame(2, (int) DB::value('SELECT otp_sends FROM signups WHERE id = :id', ['id' => $id]));
    }

    public function testAutoApprovalMode(): void
    {
        Settings::setPlatform('signup_mode', 'auto');
        try {
            [$s, , $loc, $jar] = self::signup(self::form(['hotel_name' => 'Hotel Sagar Auto', 'email' => self::$trialEmail, 'tv_estimate' => '4', 'mobile' => '+91 99090 11111']));
            $this->assertSame(302, $s);
            $this->assertStringContainsString('getting_started.php', $loc);
            self::$trialHotel = (int) DB::value("SELECT id FROM hotels WHERE name = 'Hotel Sagar Auto'");
            $this->assertGreaterThan(0, self::$trialHotel);
            $this->assertSame(4, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => self::$trialHotel]));
            [$s] = TestEnv::http('GET', self::$url . 'admin/index.php', null, [], $jar);
            $this->assertSame(200, $s);
            // Duplicate in auto mode → manual review, same neutral answer.
            DB::query('DELETE FROM rate_limits');
            [$s, , $loc] = self::signup(self::form(['email' => self::$trialEmail]));
            $this->assertStringContainsString('step=pending', $loc);
            $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM hotels WHERE contact_email = :e", ['e' => self::$trialEmail]));
            $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM signups WHERE email = :e AND status = 'pending' AND duplicate = 1", ['e' => self::$trialEmail]));
        } finally {
            Settings::setPlatform('signup_mode', 'otp');
        }
    }

    public function testManualApprovalMode(): void
    {
        Settings::setPlatform('signup_mode', 'manual');
        self::$mails = [];
        try {
            [$s, , $loc] = self::signup(self::form(['hotel_name' => 'Hotel Manual One', 'email' => 'manual1@manual-hotel.test', 'password' => 'Manual2026ok']));
            $this->assertStringContainsString('step=pending', $loc);
            [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?step=pending');
            $this->assertStringContainsString('We will review your request', $html);
            [, , , $jar2] = self::signup(self::form(['hotel_name' => 'Hotel Manual Two', 'email' => 'manual2@manual-hotel.test']));
            $one = (int) DB::value("SELECT id FROM signups WHERE email = 'manual1@manual-hotel.test'");
            $two = (int) DB::value("SELECT id FROM signups WHERE email = 'manual2@manual-hotel.test'");
            $this->assertSame('pending', DB::value('SELECT status FROM signups WHERE id = :id', ['id' => $one]));
            $this->assertFalse((bool) DB::value("SELECT id FROM hotels WHERE name = 'Hotel Manual One'"));

            [$s, , $html] = self::$root->get('platform_signups.php?status=pending');
            $this->assertSame(200, $s);
            $this->assertStringContainsString('Hotel Manual One', $html);
            $this->assertStringContainsString('Waiting for approval', $html);
            [$s] = self::$root->post('platform_signups.php', ['op' => 'approve', 'id' => $one]);
            $this->assertSame(302, $s);
            $hid = (int) DB::value("SELECT id FROM hotels WHERE name = 'Hotel Manual One'");
            $this->assertGreaterThan(0, $hid);
            $this->assertSame('approved', DB::value('SELECT status FROM signups WHERE id = :id', ['id' => $one]));
            // The owner logs in with the password chosen at sign-up.
            $owner = new AdminSession(self::$url, 'manual1@manual-hotel.test', 'Manual2026ok');
            [$s, , $html] = $owner->get('rooms.php');
            $this->assertSame(200, $s);
            $this->assertStringContainsString('Hotel Manual One', $html);
            self::$root->post('platform_signups.php', ['op' => 'reject', 'id' => $two, 'reason' => 'Fake']);
            $r = Signup::find($two);
            $this->assertSame('rejected', $r['status']);
            $this->assertNull($r['password_hash']);
            // Hotel staff cannot open the platform sign-up list.
            [$s] = $owner->get('platform_signups.php');
            $this->assertSame(403, $s);
        } finally {
            Settings::setPlatform('signup_mode', 'otp');
        }
    }

    // ------------------------------------------------------------------ trial lifecycle

    public function testTrialRemindersExpiryReadOnlyAndUpgrade(): void
    {
        $hid = self::$trialHotel;
        $this->assertGreaterThan(0, $hid, 'created by testAutoApprovalMode');
        $key = (string) DB::value('SELECT registration_key FROM hotels WHERE id = :id', ['id' => $hid]);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-trial-0001', 'room_number' => '101', 'registration_key' => $key]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $token = $j['data']['token'];
        $poll = function () use ($token): array {
            [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/tv-trial-0001?hash=x', null, ['Authorization: Bearer ' . $token, 'X-Device-Id: tv-trial-0001']);
            return $j['data']['content'] ?? [];
        };
        $this->assertNotSame('suspended', $poll()['mode']);

        // Reminders: 3 days, then 1 day before the end — each once.
        self::$mails = [];
        DB::query('UPDATE hotels SET expires_at = :e WHERE id = :id', ['e' => date('Y-m-d H:i:s', time() + 2 * 86400 + 600), 'id' => $hid]);
        $this->assertSame(1, Signup::processTrials()['reminded']);
        $this->assertSame(0, Signup::processTrials()['reminded']);
        DB::query('UPDATE hotels SET expires_at = :e WHERE id = :id', ['e' => date('Y-m-d H:i:s', time() + 20 * 3600), 'id' => $hid]);
        $this->assertSame(1, (new TrialTask())->run()['reminded']);
        $this->assertSame(0, Signup::processTrials()['reminded']);
        $mine = array_values(array_filter(self::$mails, fn ($m) => $m[0] === self::$trialEmail));
        $this->assertCount(2, $mine);
        $this->assertStringContainsString('ends in 3 day', $mine[0][1]);
        $this->assertStringContainsString('ends in 1 day', $mine[1][1]);
        $this->assertStringContainsString('billing.php', $mine[1][2]);

        // Expiry → status expired, TV "service paused", admin read-only except billing.
        DB::query('UPDATE hotels SET expires_at = :e WHERE id = :id', ['e' => date('Y-m-d H:i:s', time() - 60), 'id' => $hid]);
        $r = Signup::processTrials();
        $this->assertSame(1, $r['expired']);
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $hid]);
        $this->assertSame('expired', $h['status']);
        $this->assertSame('trial', $h['suspend_reason']);
        $this->assertSame('suspended', $poll()['mode']);
        $this->assertStringContainsString('has ended', end(self::$mails)[1]);

        $owner = new AdminSession(self::$url, self::$trialEmail, 'Trial2026pass');
        [$s, , $html] = $owner->get('index.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Your free trial has ended.', $html);
        $this->assertStringContainsString('billing.php#upgrade', $html);
        $rooms = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]);
        $owner->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => '999', 'is_enabled' => 1]);
        $this->assertSame($rooms, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]), 'read-only');
        [$s, $j] = $owner->ajax('emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        $this->assertSame('HOTEL_SUSPENDED', $j['error']['code']);

        // Upgrade: plan choice on the billing page → manual invoice → platform marks paid → active.
        [$s, , $html] = $owner->get('billing.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Upgrade to a paid plan', $html);
        $this->assertStringContainsString('Standard', $html);
        [$s] = $owner->post('billing.php', ['op' => 'trial_upgrade', 'plan_id' => self::$standardPlan, 'tvs' => 2]);
        $this->assertSame(302, $s);
        $inv = DB::one('SELECT * FROM invoices WHERE hotel_id = :h ORDER BY id DESC LIMIT 1', ['h' => $hid]);
        $this->assertNotNull($inv);
        $this->assertSame('unpaid', $inv['status']);
        $this->assertSame('Standard', $inv['plan_name']);
        $this->assertSame(2, (int) $inv['tv_count']);
        $this->assertEqualsWithDelta(351.64, (float) $inv['total'], 0.001);   // 2 × 149 + 18 %
        $this->assertSame(self::$standardPlan, (int) DB::value('SELECT upgrade_plan_id FROM signups WHERE hotel_id = :h', ['h' => $hid]));
        [, , $html] = $owner->get('billing.php');
        $this->assertStringContainsString($inv['number'], $html);
        // Choosing again replaces the unpaid invoice.
        $owner->post('billing.php', ['op' => 'trial_upgrade', 'plan_id' => self::$standardPlan, 'tvs' => 3]);
        $this->assertSame('cancelled', DB::value('SELECT status FROM invoices WHERE id = :id', ['id' => $inv['id']]));
        $inv = DB::one("SELECT * FROM invoices WHERE hotel_id = :h AND status = 'unpaid'", ['h' => $hid]);
        $this->assertSame(3, (int) $inv['tv_count']);
        $this->assertSame('expired', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => $hid]), 'still paused until paid');

        [$s] = self::$root->post('platform_invoices.php', ['op' => 'paid', 'id' => $inv['id'], 'payment_ref' => 'UPI-1234']);
        $this->assertSame(302, $s);
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $hid]);
        $this->assertSame('active', $h['status']);
        $this->assertSame(0, (int) $h['is_trial']);
        $this->assertSame(self::$standardPlan, (int) $h['plan_id']);
        $this->assertNull($h['expires_at']);
        $this->assertNull($h['max_tvs']);
        $this->assertNotNull(DB::value('SELECT converted_at FROM signups WHERE hotel_id = :h', ['h' => $hid]));
        Tenant::forget();
        $this->assertSame('active', Tenant::state($hid));
        $this->assertNotSame('suspended', $poll()['mode']);
        [, , $html] = $owner->get('index.php');
        $this->assertStringNotContainsString('hcTrialBanner', $html);
        $owner->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => '999', 'is_enabled' => 1]);
        $this->assertSame($rooms + 1, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]), 'writable again');
        // Platform list shows the conversion.
        [, , $html] = self::$root->get('platform_signups.php?status=converted');
        $this->assertStringContainsString('Hotel Sagar Auto', $html);
        $this->assertSame(1, Signup::stats()['converted']);
    }

    public function testPlatformSignupSettings(): void
    {
        [$s] = self::$root->post('platform_signups.php', ['op' => 'settings', 'tab' => 'settings', 'signup_enabled' => 1, 'signup_mode' => 'manual', 'trial_days' => 21,
            'trial_plan_id' => self::$standardPlan, 'trial_max_tvs' => 8, 'signup_terms' => 'Custom terms <b>x</b>', 'signup_notify_email' => 'leads@platform.test',
            'signup_min_seconds' => 4, 'signup_blocked_domains' => "spam-domain.test\nINVALID DOMAIN!!"]);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('manual', Settings::platform('signup_mode'));
        $this->assertSame('21', Settings::platform('trial_days'));
        $this->assertSame((string) self::$standardPlan, Settings::platform('trial_plan_id'));
        $this->assertSame('8', Settings::platform('trial_max_tvs'));
        $this->assertSame('spam-domain.test', Settings::platform('signup_blocked_domains'));
        $this->assertTrue(Signup::isDisposable('x@spam-domain.test'));
        $this->assertTrue(Signup::isDisposable('x@mx.yopmail.com'));
        $this->assertFalse(Signup::isDisposable('x@gmail.com'));
        [, , $html] = TestEnv::http('GET', self::$url . 'signup.php');
        $this->assertStringContainsString('Custom terms &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('21-day free trial', $html);
        [$s, , $html] = self::$root->get('platform_signups.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s, $j] = self::$root->ajax('signup_stats');
        $this->assertSame(200, $s);
        $this->assertArrayHasKey('conversion', $j['data']);
        [, , $html] = self::$root->get('index.php');
        $this->assertStringContainsString('Sign-ups &amp; trials', $html, 'dashboard widget + menu');
        foreach (['signup_mode' => 'otp', 'trial_days' => '14', 'trial_plan_id' => (string) self::$basicPlan, 'trial_max_tvs' => '5', 'signup_min_seconds' => '3', 'signup_blocked_domains' => ''] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
    }

    // ------------------------------------------------------------------ demo

    public function testPublicDemoHotelWithRichData(): void
    {
        [$s] = TestEnv::http('GET', self::$url . 'demo.php');
        $this->assertSame(404, $s, 'demo off by default');
        [$s] = self::$root->post('platform_demo.php', ['op' => 'public_save', 'demo_public_enabled' => 1, 'demo_hotel_name' => 'Demo Palace']);
        $this->assertSame(302, $s);
        Settings::flush();
        $hid = (int) Settings::platform('demo_hotel_id');
        $this->assertGreaterThan(0, $hid);
        self::$demoHotel = $hid;
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $hid]);
        $this->assertSame('public', $h['demo_kind']);
        $this->assertSame('Demo Palace', $h['name']);
        $p = ['h' => $hid];
        $this->assertSame(20, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', $p));
        $this->assertSame(['1', '2'], DB::column('SELECT DISTINCT floor FROM rooms WHERE hotel_id = :h ORDER BY floor', $p));
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM room_groups WHERE hotel_id = :h', $p));
        $this->assertGreaterThanOrEqual(2, (int) DB::value('SELECT COUNT(*) FROM content_playlists WHERE hotel_id = :h', $p));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND type = 'timetable'", $p));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND mode = 'window'", $p), 'schedule');
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND status = 'online'", $p), '3 fake TVs online');
        $this->assertGreaterThan(50, (int) DB::value("SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND created_at >= :t", $p + ['t' => date('Y-m-d', time() - 7 * 86400)]));
        $this->assertGreaterThan(10, (int) DB::value('SELECT COUNT(*) FROM device_status_logs WHERE hotel_id = :h', $p));
        $this->assertSame(4, (int) DB::value('SELECT COUNT(*) FROM guest_stays WHERE hotel_id = :h AND checked_out_at IS NULL', $p), 'guests checked in');
        $this->assertSame(9, (int) DB::value('SELECT COUNT(*) FROM guest_menu_items WHERE hotel_id = :h', $p), 'room-service menu');
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM sponsors WHERE hotel_id = :h', $p));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM ad_campaigns WHERE hotel_id = :h', $p));
        $this->assertGreaterThan(0, (int) DB::value('SELECT COUNT(*) FROM tv_usage_daily WHERE hotel_id = :h', $p));
        $demoUser = Demo::demoUser($hid);
        $this->assertSame('manager', $demoUser['role']);
        // A TV cannot be added to the public demo (3 fake TVs = limit).
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-intruder-01', 'room_number' => '105', 'registration_key' => $h['registration_key']]);
        $this->assertSame(403, $s);
        $this->assertSame('LICENSE_LIMIT', $j['error']['code']);
        // Landing page.
        [$s, , $html] = TestEnv::http('GET', self::$url . 'demo.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Demo mode — changes are disabled', $html);
        $this->assertStringContainsString('demo_tv.php?room=', $html);
        $this->assertStringContainsString('signup.php', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringContainsString('demo.php', $html);
    }

    public function testDemoUserCannotChangeAnything(): void
    {
        $hid = self::$demoHotel;
        $this->assertGreaterThan(0, $hid);
        $jar = self::jar();
        [$csrf] = self::open('demo.php', $jar);
        [$s, , , $head] = TestEnv::http('POST', self::$url . 'demo.php', null, [], $jar, ['_csrf' => $csrf, 'op' => 'login']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('admin/index.php', $head);
        $demo = self::sessionFromJar($jar);
        $this->assertNotSame('', $demo->csrf);
        foreach (['index.php', 'rooms.php', 'content.php', 'playlists.php', 'schedule.php', 'guests.php', 'analytics.php', 'ads.php'] as $page) {
            [$s, , $html] = $demo->get($page);
            $this->assertSame(200, $s, $page);
            $this->assertStringContainsString('hcDemoBanner', $html, "demo banner on $page");
            $this->assertFalse(TestEnv::hasPhpError($html), $page);
        }
        [, , $html] = $demo->get('index.php');
        $this->assertStringContainsString('Demo Palace', $html);

        $before = self::hotelSnapshot($hid);
        $other = self::hotelSnapshot(1);
        // Every admin page refuses every POST (generic fields of the common forms).
        $fields = ['op' => 'save', 'id' => 0, 'room_number' => 'X1', 'name' => 'Hacked', 'title' => 'Hacked', 'type' => 'announcement', 'body' => 'x',
            'hotel_name' => 'Hacked', 'username' => 'hacker', 'email' => 'h@x.test', 'password' => 'Passw0rd!', 'role' => 'super_admin', 'status' => 'active',
            'room_id' => 1, 'content_id' => 1, 'playlist_id' => 1, 'source' => 'c:1', 'target_type' => 'all', 'is_enabled' => 1];
        $pages = array_map('basename', glob(HC_ROOT . '/admin/*.php') ?: []);
        $this->assertGreaterThan(30, count($pages));
        $ops = ['save', 'delete', 'toggle', 'bulk', 'assign', 'send', 'upload', 'settings', 'regen_key', 'checkin', 'checkout', 'leave', 'enter'];
        foreach ($pages as $page) {
            if (in_array($page, ['login.php', 'logout.php'], true)) {
                continue;
            }
            foreach ($ops as $op) {
                [$s, , $body, $head] = $demo->post($page, ['op' => $op] + $fields);
                $this->assertContains($s, [303, 403], "$page op=$op must be refused (got $s)");
                if ($s === 303) {
                    $this->assertMatchesRegularExpression('/^Location: /mi', $head);
                } else {
                    $this->assertStringContainsString('DEMO_READONLY', $body, $page);
                }
            }
        }
        // Directory index (admin/) and PUT / DELETE too.
        [$s] = TestEnv::http('POST', self::$url . 'admin/', null, [], $jar, ['_csrf' => $demo->csrf, 'op' => 'save']);
        $this->assertContains($s, [303, 403], 'POST admin/');
        foreach (['rooms.php', 'content.php'] as $page) {
            [$s] = TestEnv::http('DELETE', self::$url . 'admin/' . $page, null, ['X-CSRF-Token: ' . $demo->csrf], $jar);
            $this->assertContains($s, [303, 403]);
        }
        // Every AJAX action (built-in + module files) is refused for POST.
        $src = (string) file_get_contents(HC_ROOT . '/admin/ajax.php');
        foreach (glob(HC_ROOT . '/admin/ajax.d/*.php') ?: [] as $f) {
            $src .= file_get_contents($f);
        }
        foreach (glob(HC_ROOT . '/admin/ajax_update.php') ?: [] as $f) {
            $src .= file_get_contents($f);
        }
        preg_match_all("/(?:case\s+'|\\\$action\s*===\s*')([a-z0-9_]+)'/", $src, $m);
        $actions = array_values(array_unique(array_merge($m[1], ['update_run', 'rollback'])));
        $this->assertGreaterThan(15, count($actions));
        foreach ($actions as $action) {
            if ($action === 'set_language') {
                continue;
            }
            [$s, $j] = $demo->ajax($action, ['title' => 'X', 'message' => 'Y', 'target_type' => 'all', 'command' => 'REBOOT', 'id' => 1, 'status' => 'delivered', 'lang' => 'gu']);
            $this->assertSame(403, $s, "ajax $action");
            $this->assertSame('DEMO_READONLY', $j['error']['code'] ?? null, "ajax $action");
            $this->assertSame('Demo mode — changes are disabled.', $j['error']['message']);
        }
        $this->assertSame($before, self::hotelSnapshot($hid), 'demo hotel data unchanged');
        $this->assertSame($other, self::hotelSnapshot(1), 'other hotel unchanged');
        // Friendly flash after a blocked form.
        [, , $html] = $demo->get('rooms.php');
        $this->assertStringContainsString('Demo mode — changes are disabled.', $html);
        // Allowed: language switch and logout.
        [$s, $j] = $demo->ajax('set_language', ['lang' => 'gu']);
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
        [$s, , , $head] = $demo->post('logout.php', []);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('login.php', $head);
        [$s] = $demo->get('rooms.php');
        $this->assertSame(302, $s, 'logged out');
        // The demo user has no known password.
        $this->assertFalse(Auth::attempt(Demo::demoUser($hid)['username'], 'demo')[0]);
        // Platform admins working inside the demo hotel are not blocked.
        self::$root->post('platform_hotels.php', ['op' => 'enter', 'id' => $hid]);
        self::$root->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'P1', 'is_enabled' => 1]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND room_number = 'P1'", ['h' => $hid]));
        self::$root->post('platform_hotels.php', ['op' => 'leave']);
    }

    public function testPublicDemoPagesExposeOnlyTheDemoHotel(): void
    {
        $hid = self::$demoHotel;
        $room = (int) DB::value("SELECT id FROM rooms WHERE hotel_id = :h AND room_number = '101'", ['h' => $hid]);
        [$s, , $html] = TestEnv::http('GET', self::$url . 'demo_tv.php?room=' . $room);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('id="contentData"', $html);
        $this->assertStringContainsString('Demo Palace', $html);
        $this->assertStringContainsString('demo_tv.php?room=' . $room . '\\u0026json=1', $html, 'live refresh URL');
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s, $j] = TestEnv::http('GET', self::$url . 'demo_tv.php?room=' . $room . '&json=1');
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
        $this->assertSame($hid, $j['data']['hotel']['id']);
        $this->assertSame('101', $j['data']['room']['number']);
        $this->assertNotEmpty($j['data']['items']);
        $this->assertStringNotContainsString((string) DB::value('SELECT registration_key FROM hotels WHERE id = :id', ['id' => $hid]), json_encode($j));

        // Rooms of other hotels: plain 404, nothing revealed.
        Settings::setFor(1, 'hotel_name', 'Secret Main Hotel');
        $foreign = (int) DB::insert('rooms', ['hotel_id' => 1, 'room_number' => 'SEC1', 'name' => 'Secret room', 'created_at' => now()]);
        foreach ([$foreign, 0, 999999] as $rid) {
            foreach (['', '&json=1'] as $suffix) {
                [$s, , $body] = TestEnv::http('GET', self::$url . 'demo_tv.php?room=' . $rid . $suffix);
                $this->assertSame(404, $s, "room $rid$suffix");
                $this->assertStringNotContainsString('Secret', $body);
            }
        }
        [, , $body] = TestEnv::http('GET', self::$url . 'demo.php');
        $this->assertStringNotContainsString('Secret', $body);
        $this->assertStringNotContainsString('SEC1', $body);
        // demo.php never logs into another hotel: the session belongs to the demo user.
        $jar = self::jar();
        [$csrf] = self::open('demo.php', $jar);
        TestEnv::http('POST', self::$url . 'demo.php', null, [], $jar, ['_csrf' => $csrf, 'op' => 'login']);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/rooms.php', null, [], $jar);
        $this->assertStringNotContainsString('SEC1', $html);
        $this->assertStringContainsString('Demo Palace', $html);

        // Demo switched off → public pages gone.
        Settings::setPlatform('demo_public_enabled', '0');
        try {
            [$s] = TestEnv::http('GET', self::$url . 'demo_tv.php?room=' . $room);
            $this->assertSame(404, $s);
            [$s] = TestEnv::http('GET', self::$url . 'demo.php');
            $this->assertSame(404, $s);
        } finally {
            Settings::setPlatform('demo_public_enabled', '1');
        }
    }

    public function testDemoNightlyReset(): void
    {
        $hid = self::$demoHotel;
        Tenant::run($hid, function (): void {
            DB::insert('rooms', ['room_number' => 'JUNK', 'created_at' => now()]);
            DB::query("UPDATE devices SET status = 'offline', last_ping = :t WHERE hotel_id = :h", ['t' => date('Y-m-d H:i:s', time() - 3600), 'h' => Tenant::id()]);
        });
        Settings::setPlatform('demo_last_reset', (string) time());
        $this->assertFalse(Demo::resetDue());
        // Not due: the task only keeps the fake TVs online.
        $out = (new DemoResetTask())->run();
        $this->assertArrayNotHasKey('public_reset', $out);
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND status = 'online'", ['h' => $hid]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND room_number = 'JUNK'", ['h' => $hid]));

        Settings::setPlatform('demo_last_reset', (string) (time() - 2 * 86400));
        $this->assertTrue(Demo::resetDue());
        $userBefore = (int) Demo::demoUser($hid)['id'];
        $out = (new DemoResetTask())->run();
        $this->assertSame($hid, $out['public_reset'], 'same hotel reset');
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND room_number IN ('JUNK', 'P1')", ['h' => $hid]));
        $this->assertSame(20, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]));
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND status = 'online'", ['h' => $hid]));
        $this->assertNotSame($userBefore, (int) Demo::demoUser($hid)['id'], 'fresh demo user, old sessions gone');
        $this->assertGreaterThan(time() - 60, (int) Settings::platform('demo_last_reset'));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM hotels WHERE demo_kind = 'public'"));
        // Due rules: once per day after 03:00, always after 26 h.
        Settings::setPlatform('demo_last_reset', (string) strtotime('yesterday 04:00'));
        $this->assertTrue(Demo::resetDue(strtotime('today 03:30')));
        $this->assertFalse(Demo::resetDue(strtotime('today 02:00')));
        // Reset button (platform).
        [$s] = self::$root->post('platform_demo.php', ['op' => 'public_reset']);
        $this->assertSame(302, $s);
        [$s, , $html] = self::$root->get('platform_demo.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Reset demo now', $html);
        // Purge refuses normal hotels.
        $this->expectException(InvalidArgumentException::class);
        Demo::purge(1);
    }

    public function testClientDemoCreatedForProspectAndAutoDeleted(): void
    {
        [$s] = self::$root->post('platform_demo.php', ['op' => 'client_create', 'prospect' => 'Hotel Sagar', 'days' => 7]);
        $this->assertSame(302, $s);
        [, , $html] = self::$root->get('platform_demo.php');
        $this->assertMatchesRegularExpression('/Login: (\S+) · Password: (\S+) ·/u', $html);
        preg_match('/Login: (\S+) · Password: (\S+) ·/u', $html, $m);
        [, $username, $password] = $m;
        $h = DB::one("SELECT * FROM hotels WHERE name = 'Hotel Sagar (Demo)'");
        $this->assertSame('client', $h['demo_kind']);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime((string) $h['expires_at']), 120);
        $this->assertSame(20, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $h['id']]));
        $client = new AdminSession(self::$url, $username, $password);
        [$s, , $html] = $client->get('index.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Hotel Sagar (Demo)', $html);
        $this->assertStringContainsString('Client demo — valid until', $html);
        // Private copy: the prospect may try changes (not read-only by default).
        $client->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'C1', 'is_enabled' => 1]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND room_number = 'C1'", ['h' => $h['id']]));

        // Read-only variant.
        self::$root->post('platform_demo.php', ['op' => 'client_create', 'prospect' => 'Hotel Readonly', 'readonly' => 1]);
        [, , $html] = self::$root->get('platform_demo.php');
        preg_match('/Login: (\S+) · Password: (\S+) ·/u', $html, $m2);
        $ro = new AdminSession(self::$url, $m2[1], $m2[2]);
        [$s, $j] = $ro->ajax('emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        $this->assertSame('DEMO_READONLY', $j['error']['code']);

        // Resellers: own demos only.
        $rid = DB::insert('resellers', ['name' => 'Demo Partner', 'commission_percent' => 10, 'status' => 'active', 'created_at' => now()]);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => $rid, 'username' => 'partner', 'email' => 'partner@demo.test', 'full_name' => 'Partner', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'reseller']);
        $partner = new AdminSession(self::$url, 'partner');
        [$s, , $html] = $partner->get('platform_demo.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('Hotel Sagar (Demo)', $html);
        $this->assertStringNotContainsString('Public demo', $html);
        $partner->post('platform_demo.php', ['op' => 'client_create', 'prospect' => 'Partner Prospect']);
        $this->assertSame($rid, (int) DB::value("SELECT reseller_id FROM hotels WHERE name = 'Partner Prospect (Demo)'"));
        $partner->post('platform_demo.php', ['op' => 'client_delete', 'id' => $h['id']]);
        $this->assertNull(DB::value('SELECT demo_purged_at FROM hotels WHERE id = :id', ['id' => $h['id']]), 'reseller cannot delete platform demo');
        [$s] = $partner->post('platform_demo.php', ['op' => 'public_reset']);
        [$s] = $partner->get('platform_signups.php');
        $this->assertSame(403, $s);

        // Auto-expiry: status expired + data purged + login gone.
        DB::insert('rooms', ['hotel_id' => 1, 'room_number' => 'MAIN1', 'created_at' => now()]);
        DB::query('UPDATE hotels SET expires_at = :e WHERE id = :id', ['e' => date('Y-m-d H:i:s', time() - 60), 'id' => $h['id']]);
        $out = (new DemoResetTask())->run();
        $this->assertSame(1, $out['client_demos_expired']);
        $h2 = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $h['id']]);
        $this->assertSame('expired', $h2['status']);
        $this->assertNotNull($h2['demo_purged_at']);
        foreach (['rooms', 'content_items', 'devices', 'users', 'guest_stays', 'broadcast_logs', 'system_settings'] as $t) {
            $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM `$t` WHERE hotel_id = :h", ['h' => $h['id']]), $t);
        }
        $this->assertFalse(Auth::attempt($username, $password)[0]);
        $this->assertGreaterThan(0, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => self::$demoHotel]), 'public demo untouched');
        $this->assertGreaterThan(0, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = 1'), 'main hotel untouched');
        [, , $html] = self::$root->get('platform_demo.php');
        $this->assertStringContainsString('Deleted', $html);
        // Manual delete by the platform.
        $pid = (int) DB::value("SELECT id FROM hotels WHERE name = 'Partner Prospect (Demo)'");
        self::$root->post('platform_demo.php', ['op' => 'client_delete', 'id' => $pid]);
        $this->assertNotNull(DB::value('SELECT demo_purged_at FROM hotels WHERE id = :id', ['id' => $pid]));
    }

    // ------------------------------------------------------------------ misc

    public function testInstallerDemoDataUsesSharedSampleContent(): void
    {
        $hid = Hotels::create(['name' => 'Installer Check']);
        $log = Tenant::run($hid, fn () => Installer::demoData());
        $this->assertContains('Created 20 demo screens in 3 groups', $log);
        $this->assertSame(['Demo data skipped (screens already exist)'], Tenant::run($hid, fn () => Installer::demoData()));
        $this->assertSame(4, (int) DB::value("SELECT COUNT(*) FROM rooms r JOIN room_group_members m ON m.room_id = r.id JOIN room_groups g ON g.id = m.group_id WHERE r.hotel_id = :h AND g.name = 'Display walls (promo)'", ['h' => $hid]));
        $this->assertSame(4, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND name LIKE 'Display wall %'", ['h' => $hid]));
    }

    public function testGujaratiTranslationsComplete(): void
    {
        $files = ['core/Signup.php', 'core/Demo.php', 'signup.php', 'demo.php', 'demo_tv.php', 'admin/getting_started.php', 'admin/platform_signups.php',
            'admin/platform_demo.php', 'admin/partials/nav.d/05_getting_started.php', 'admin/partials/nav.d/55_signup_demo.php', 'admin/partials/footer.d/70_signup_demo.php',
            'admin/partials/billing.d/50_trial_upgrade.php', 'admin/partials/dashboard.d/70_signups.php', 'admin/login.php'];
        $gu = I18n::table('gu');
        $missing = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents(HC_ROOT . '/' . $f);
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                if (!isset($gu[$key])) {
                    $missing[] = "$f: $key";
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
        $this->assertSame('', TestEnv::phpErrors(), 'no PHP warnings logged by the app');
    }
}
