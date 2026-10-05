<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Platform features (V2_SPEC §0): hotel CRUD + enter, suspension (TV "service paused" screen,
 * registration refused, read-only admin), TV limits, invoices (numbers, amounts, tax), payments
 * and auto-suspend / reactivation, resellers (own hotels only, allowance, commission), the license
 * server endpoint and the self-hosted license client (grace period, unlicensed demo), branding.
 */
final class PlatformTest extends TestCase
{
    private static string $url;
    private static ?AdminSession $root = null;
    private static int $hotel = 0;          // "Sea View" created through the UI
    private static string $key = '';
    private static array $tvTokens = [];
    private static int $standardPlan = 0;
    private static int $reseller = 0;
    private static int $resHotel = 0;
    private static string $licenseKey = '';
    private static array $mails = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'root', 'email' => 'root@platform.test', 'full_name' => 'Root', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'platform_admin']);
        foreach (['invoice_prefix' => 'TST', 'billing_tax_percent' => '18', 'invoice_due_days' => '15', 'auto_suspend_days' => '10', 'reminder_email' => '1', 'invoice_auto_generate' => '0'] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
        self::$standardPlan = (int) DB::value("SELECT id FROM plans WHERE name = 'Standard'");
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
        License::$file = null;
        License::$transport = null;
        License::reset();
        Tenant::set(1);
    }

    private static function register(string $uid, string $room, ?string $key = null): array
    {
        return TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key ?? self::$key]);
    }

    private static function poll(string $uid): array
    {
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . $uid . '?hash=x', null, ['Authorization: Bearer ' . self::$tvTokens[$uid], 'X-Device-Id: ' . $uid]);
        return $j['data']['content'] ?? [];
    }

    public function testPlatformAdminCreatesEditsAndEntersHotel(): void
    {
        [$s, , $html] = self::$root->get('platform_hotels.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('My Hotel', $html, 'Hotel #1 listed');
        [$s] = self::$root->post('platform_hotels.php', [
            'op' => 'save', 'id' => 0, 'name' => 'Sea View', 'plan_id' => self::$standardPlan, 'contact_email' => 'owner@seaview.test',
            'contact_phone' => '+91 98765 43210', 'city' => 'Dwarka', 'status' => 'active',
            'admin_username' => 'seaboss', 'admin_email' => 'seaboss@seaview.test', 'admin_password' => 'Passw0rd!', 'admin_name' => 'Sea Boss',
        ]);
        $this->assertSame(302, $s);
        self::$hotel = (int) DB::value("SELECT id FROM hotels WHERE name = 'Sea View'");
        $this->assertGreaterThan(1, self::$hotel);
        Settings::flush();
        self::$key = (string) Settings::getFor(self::$hotel, 'registration_key');
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', self::$key);
        $this->assertSame(self::$key, DB::value('SELECT registration_key FROM hotels WHERE id = :id', ['id' => self::$hotel]));
        $this->assertSame('Sea View', Settings::getFor(self::$hotel, 'hotel_name'));
        $u = DB::one("SELECT * FROM users WHERE username = 'seaboss'");
        $this->assertSame('super_admin', $u['role']);
        $this->assertSame(self::$hotel, (int) $u['hotel_id']);

        // Edit: rename + TV limit 2.
        self::$root->post('platform_hotels.php', ['op' => 'save', 'id' => self::$hotel, 'name' => 'Sea View Resort', 'plan_id' => self::$standardPlan, 'max_tvs' => 2, 'status' => 'active', 'contact_email' => 'owner@seaview.test', 'contact_phone' => '+91 98765 43210']);
        Settings::flush();
        $this->assertSame('Sea View Resort', DB::value('SELECT name FROM hotels WHERE id = :id', ['id' => self::$hotel]));
        $this->assertSame('Sea View Resort', Settings::getFor(self::$hotel, 'hotel_name'));
        $this->assertSame(2, (int) DB::value('SELECT max_tvs FROM hotels WHERE id = :id', ['id' => self::$hotel]));

        // Enter the hotel: hotel pages now work on Sea View, with the context banner.
        [$s, , , $head] = self::$root->post('platform_hotels.php', ['op' => 'enter', 'id' => self::$hotel]);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('index.php', $head);
        [$s, , $html] = self::$root->get('rooms.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Sea View Resort', $html);
        $this->assertStringContainsString('You are managing hotel', $html);
        self::$root->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'S1', 'is_enabled' => 1]);
        $this->assertSame(self::$hotel, (int) DB::value("SELECT hotel_id FROM rooms WHERE room_number = 'S1'"));
        // Back to the platform → own hotel #1 again.
        self::$root->post('platform_hotels.php', ['op' => 'leave']);
        [, , $html] = self::$root->get('rooms.php');
        $this->assertStringNotContainsString('You are managing hotel', $html);
        $this->assertStringNotContainsString('>S1<', $html);
        // The new hotel's admin can log in and sees only his hotel.
        $sea = new AdminSession(self::$url, 'seaboss');
        [$s, , $html] = $sea->get('rooms.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('S1', $html);
        [$s] = $sea->get('platform_hotels.php');
        $this->assertSame(403, $s);
    }

    public function testTvLimitPerHotel(): void
    {
        foreach (['tv-sea-0001' => 'S1', 'tv-sea-0002' => 'S2'] as $uid => $room) {
            [$s, $j] = self::register($uid, $room);
            $this->assertSame(200, $s, (string) json_encode($j));
            self::$tvTokens[$uid] = $j['data']['token'];
            $this->assertSame(self::$hotel, $j['data']['hotel']['id']);
        }
        [$s, $j] = self::register('tv-sea-0003', 'S3');
        $this->assertSame(403, $s);
        $this->assertSame('LICENSE_LIMIT', $j['error']['code']);
        // An already registered TV may re-register (token rotation) at the limit.
        [$s, $j] = self::register('tv-sea-0001', 'S1');
        $this->assertSame(200, $s);
        self::$tvTokens['tv-sea-0001'] = $j['data']['token'];
        // Revoking one TV frees a slot.
        DB::query("UPDATE devices SET is_revoked = 1 WHERE device_uid = 'tv-sea-0002'");
        [$s, $j] = self::register('tv-sea-0003', 'S3');
        $this->assertSame(200, $s);
        self::$tvTokens['tv-sea-0003'] = $j['data']['token'];
        $this->assertSame(2, Tenant::tvCount(self::$hotel));
    }

    public function testSuspendedHotel(): void
    {
        self::$root->post('platform_hotels.php', ['op' => 'status', 'id' => self::$hotel, 'status' => 'suspended']);
        $this->assertSame('suspended', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => self::$hotel]));
        $c = self::poll('tv-sea-0001');
        $this->assertSame('suspended', $c['mode']);
        $this->assertNotEmpty($c['suspended']['title']);
        $this->assertNotEmpty($c['suspended']['message']);
        $this->assertSame([], $c['items']);
        [$s, $j] = self::register('tv-sea-0009', 'S9');
        $this->assertSame(403, $s);
        $this->assertSame('HOTEL_SUSPENDED', $j['error']['code']);

        // Hotel admin: banner, read-only (except billing).
        $sea = new AdminSession(self::$url, 'seaboss');
        [$s, , $html] = $sea->get('index.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('suspended', $html);
        $rooms = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => self::$hotel]);
        $sea->post('rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'S77', 'is_enabled' => 1]);
        $this->assertSame($rooms, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => self::$hotel]), 'read-only while suspended');
        [$s, $j] = $sea->ajax('emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        $this->assertSame('HOTEL_SUSPENDED', $j['error']['code']);
        [$s] = $sea->get('billing.php');
        $this->assertSame(200, $s);

        // Reactivate → normal content again.
        self::$root->post('platform_hotels.php', ['op' => 'status', 'id' => self::$hotel, 'status' => 'active']);
        $this->assertNotSame('suspended', self::poll('tv-sea-0001')['mode']);

        // Expiry date in the past behaves like suspended.
        DB::query('UPDATE hotels SET expires_at = :d WHERE id = :id', ['d' => date('Y-m-d H:i:s', time() - 60), 'id' => self::$hotel]);
        Tenant::forget();
        $this->assertSame('expired', Tenant::state(self::$hotel));
        $room = DB::one("SELECT * FROM rooms WHERE room_number = 'S1'");
        $this->assertSame('suspended', Tenant::run(self::$hotel, fn () => ContentResolver::build($room))['mode']);
        DB::query('UPDATE hotels SET expires_at = NULL WHERE id = :id', ['id' => self::$hotel]);
        Tenant::forget();
    }

    public function testInvoiceGenerationNumbersAmountsAndTax(): void
    {
        $tvs = Tenant::tvCount(self::$hotel);
        $this->assertSame(2, $tvs);
        $r = Billing::generateMonthly('2026-09');
        $this->assertCount(1, $r['created'], 'Only Sea View has a plan and TVs');
        $this->assertSame('no plan', $r['skipped'][1]);
        $inv = Billing::find($r['created'][0]);
        $year = date('Y');
        $this->assertSame("TST-$year-0001", $inv['number']);
        $this->assertSame('2026-09-01', $inv['period_from']);
        $this->assertSame('2026-09-30', $inv['period_to']);
        $this->assertSame(2, (int) $inv['tv_count']);
        $this->assertEqualsWithDelta(298.00, (float) $inv['amount'], 0.001);      // 2 × 149
        $this->assertEqualsWithDelta(53.64, (float) $inv['tax'], 0.001);          // 18 %
        $this->assertEqualsWithDelta(351.64, (float) $inv['total'], 0.001);
        $this->assertSame(date('Y-m-d', strtotime('+15 days')), $inv['due_date']);
        $this->assertSame('Sea View Resort', json_decode((string) $inv['bill_to'], true)['name']);
        // Idempotent.
        $this->assertCount(0, Billing::generateMonthly('2026-09')['created']);
        // Through the UI: next month + a single invoice → sequential numbers.
        self::$root->post('platform_invoices.php', ['op' => 'generate', 'month' => '2026-08']);
        self::$root->post('platform_invoices.php', ['op' => 'create', 'hotel_id' => self::$hotel, 'month' => '2026-07', 'tv_count' => 3, 'unit_price' => '100']);
        $numbers = DB::column('SELECT number FROM invoices ORDER BY id');
        $this->assertSame(["TST-$year-0001", "TST-$year-0002", "TST-$year-0003"], $numbers);
        $last = DB::one('SELECT * FROM invoices ORDER BY id DESC LIMIT 1');
        $this->assertEqualsWithDelta(354.00, (float) $last['total'], 0.001); // 300 + 18 %
        [$s, , $html] = self::$root->get('invoice.php?id=' . $inv['id'] . '&print=1');
        $this->assertSame(200, $s);
        $this->assertStringContainsString("TST-$year-0001", $html);
        $this->assertStringContainsString('351.64', $html);
        [$s, , $html] = self::$root->get('platform_invoices.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString("TST-$year-0002", $html);
    }

    public function testOverdueAutoSuspendAndReactivationOnPayment(): void
    {
        $first = (int) DB::value("SELECT id FROM invoices WHERE period_from = '2026-09-01'");
        DB::query('UPDATE invoices SET due_date = :d WHERE id = :id', ['d' => date('Y-m-d', strtotime('-20 days')), 'id' => $first]);
        self::$mails = [];
        $r = Billing::processOverdue();
        $this->assertSame(1, $r['suspended']);
        $this->assertGreaterThanOrEqual(1, $r['reminded']);
        $h = Hotels::find(self::$hotel);
        $this->assertSame('suspended', $h['status']);
        $this->assertSame('billing', $h['suspend_reason']);
        $this->assertNotEmpty(array_filter(self::$mails, fn ($m) => $m[0] === 'owner@seaview.test' && str_contains($m[2], 'overdue')), 'reminder emailed');
        $this->assertSame('suspended', self::poll('tv-sea-0001')['mode']);
        // A second run on the same day does not remind again.
        $this->assertSame(0, Billing::processOverdue()['reminded']);

        // Hotel super admin sees its invoices (read-only billing page) — but never another hotel's invoice.
        $sea = new AdminSession(self::$url, 'seaboss');
        [$s, , $html] = $sea->get('billing.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('TST-', $html);
        [$s] = $sea->get('invoice.php?id=' . $first);
        $this->assertSame(200, $s);
        $other = Billing::createInvoice(1, '2026-09-01', '2026-09-30', null, 1, 10.0);
        [$s] = $sea->get('invoice.php?id=' . $other);
        $this->assertSame(404, $s);

        // Payment recorded by the platform → hotel reactivated automatically.
        self::$root->post('platform_invoices.php', ['op' => 'paid', 'id' => $first, 'payment_ref' => 'UTR123456', 'paid_at' => date('Y-m-d'), 'payment_method' => 'UPI']);
        $inv = Billing::find($first);
        $this->assertSame('paid', $inv['status']);
        $this->assertSame('UTR123456', $inv['payment_ref']);
        $this->assertSame('active', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => self::$hotel]));
        $this->assertNotSame('suspended', self::poll('tv-sea-0001')['mode']);
        // Manual suspension is not lifted by a payment.
        Hotels::setStatus(self::$hotel, 'suspended', 'manual');
        Billing::reactivateIfPaid(self::$hotel);
        $this->assertSame('suspended', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => self::$hotel]));
        Hotels::setStatus(self::$hotel, 'active');
        // Cancel an unpaid invoice.
        self::$root->post('platform_invoices.php', ['op' => 'cancel', 'id' => $other]);
        $this->assertSame('cancelled', DB::value('SELECT status FROM invoices WHERE id = :id', ['id' => $other]));
    }

    public function testResellerSeesOnlyOwnHotelsAndEarnsCommission(): void
    {
        self::$root->post('platform_resellers.php', ['op' => 'save', 'id' => 0, 'name' => 'Gujarat Partners', 'commission_percent' => '10', 'max_hotels' => 1, 'status' => 'active', 'brand_name' => 'PartnerTV', 'brand_color' => '#00AA00']);
        self::$reseller = (int) DB::value("SELECT id FROM resellers WHERE name = 'Gujarat Partners'");
        $this->assertGreaterThan(0, self::$reseller);
        self::$root->post('platform_resellers.php', ['op' => 'add_user', 'id' => self::$reseller, 'admin_username' => 'res1', 'admin_email' => 'res1@partner.test', 'admin_password' => 'Passw0rd!']);
        $this->assertSame('reseller', DB::value("SELECT role FROM users WHERE username = 'res1'"));
        // Another reseller with a hotel.
        $other = DB::insert('resellers', ['name' => 'Other Partner', 'commission_percent' => 5]);
        $otherHotel = Hotels::create(['name' => 'Other Hotel', 'reseller_id' => $other]);

        $res = new AdminSession(self::$url, 'res1');
        [$s, , $html] = $res->get('reseller.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('Other Hotel', $html);
        $this->assertStringNotContainsString('Sea View', $html);
        // Create a hotel (allowance 1).
        $res->post('reseller.php', ['op' => 'save', 'id' => 0, 'name' => 'Res Hotel One', 'plan_id' => self::$standardPlan,
            'admin_username' => 'resboss', 'admin_email' => 'resboss@resh.test', 'admin_password' => 'Passw0rd!',
            // Fields a reseller may not set are ignored:
            'max_tvs' => 9999, 'status' => 'active', 'reseller_id' => $other]);
        self::$resHotel = (int) DB::value("SELECT id FROM hotels WHERE name = 'Res Hotel One'");
        $h = Hotels::find(self::$resHotel);
        $this->assertSame(self::$reseller, (int) $h['reseller_id']);
        $this->assertNull($h['max_tvs']);
        // Allowance used up.
        $res->post('reseller.php', ['op' => 'save', 'id' => 0, 'name' => 'Res Hotel Two', 'admin_username' => 'resboss2', 'admin_email' => 'rb2@resh.test', 'admin_password' => 'Passw0rd!']);
        $this->assertNull(DB::value("SELECT id FROM hotels WHERE name = 'Res Hotel Two'"));
        // Cannot enter / edit another reseller's or a direct hotel.
        foreach ([$otherHotel, self::$hotel, 1] as $foreign) {
            [$s] = $res->post('reseller.php', ['op' => 'enter', 'id' => $foreign]);
            $this->assertSame(404, $s);
            [$s] = $res->get('reseller.php?action=edit&id=' . $foreign);
            $this->assertSame(404, $s);
        }
        [$s] = $res->post('platform_hotels.php', ['op' => 'enter', 'id' => $otherHotel]);
        $this->assertSame(403, $s);
        [$s] = $res->get('platform_hotels.php');
        $this->assertSame(403, $s);
        // Without a hotel the reseller is sent to its panel.
        [$s, , , $head] = $res->get('rooms.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('reseller.php', $head);
        // Enter own hotel → manage it.
        [$s, , , $head] = $res->post('reseller.php', ['op' => 'enter', 'id' => self::$resHotel]);
        $this->assertSame(302, $s);
        [$s, , $html] = $res->get('rooms.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Res Hotel One', $html);

        // Commission: paid invoices (net) × 10 %; unpaid ones excluded.
        $paid = Billing::createInvoice(self::$resHotel, '2026-09-01', '2026-09-30', null, 10, 100.0);
        Billing::markPaid($paid, 'NEFT1');
        Billing::createInvoice(self::$resHotel, '2026-10-01', '2026-10-31', null, 10, 100.0);
        Billing::createInvoice($otherHotel, '2026-09-01', '2026-09-30', null, 5, 100.0);
        $c = Billing::commission(self::$reseller);
        $this->assertEqualsWithDelta(1000.0, $c['paid_total'], 0.001);
        $this->assertEqualsWithDelta(100.0, $c['commission'], 0.001);
        $this->assertSame(0.0, Billing::commission($other)['commission']);
        $res->post('reseller.php', ['op' => 'leave']);
        [, , $html] = $res->get('reseller.php');
        $this->assertStringContainsString('100.00', $html);
        // Invoices of other hotels are not visible to the reseller.
        $foreignInvoice = (int) DB::value('SELECT id FROM invoices WHERE hotel_id = :h', ['h' => $otherHotel]);
        [$s] = $res->get('invoice.php?id=' . $foreignInvoice);
        $this->assertSame(404, $s);
        [$s] = $res->get('invoice.php?id=' . $paid);
        $this->assertSame(200, $s);
    }

    public function testLicenseServerEndpoint(): void
    {
        self::$root->post('platform_licenses.php', ['op' => 'save', 'id' => 0, 'customer_name' => 'Self Hosted Inn', 'max_tvs' => 5, 'expires_at' => date('Y-m-d', strtotime('+1 year'))]);
        self::$licenseKey = (string) DB::value("SELECT license_key FROM licenses WHERE customer_name = 'Self Hosted Inn'");
        $this->assertMatchesRegularExpression('/^HC(-[A-Z0-9]{5}){4}$/', self::$licenseKey);
        $check = fn (array $in) => TestEnv::http('POST', self::$url . 'api/license/check', $in);

        [$s, $j] = $check(['key' => self::$licenseKey, 'domain' => 'https://inn.example.com/hotelcast/', 'version' => '2.0.0', 'tv_count' => 3]);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['valid'], (string) json_encode($j));
        $this->assertSame(5, $j['data']['max_tvs']);
        $this->assertSame('Self Hosted Inn', $j['data']['hotel']);
        $this->assertNotNull($j['data']['expires_at']);
        $lic = DB::one('SELECT * FROM licenses WHERE license_key = :k', ['k' => self::$licenseKey]);
        $this->assertSame('inn.example.com', $lic['bound_domain'], 'bound on first check');
        $this->assertSame(3, (int) $lic['last_tv_count']);
        // Other domain → refused until the binding is reset.
        [, $j] = $check(['key' => self::$licenseKey, 'domain' => 'thief.example.org']);
        $this->assertFalse($j['data']['valid']);
        $this->assertStringContainsString('another domain', $j['data']['message']);
        self::$root->post('platform_licenses.php', ['op' => 'reset_domain', 'id' => $lic['id']]);
        [, $j] = $check(['key' => self::$licenseKey, 'domain' => 'new-server.example.com']);
        $this->assertTrue($j['data']['valid']);
        // Unknown, expired, revoked.
        [, $j] = $check(['key' => 'HC-NOPE', 'domain' => 'x.example']);
        $this->assertFalse($j['data']['valid']);
        DB::query('UPDATE licenses SET expires_at = :d WHERE id = :id', ['d' => date('Y-m-d H:i:s', time() - 86400), 'id' => $lic['id']]);
        [, $j] = $check(['key' => self::$licenseKey, 'domain' => 'new-server.example.com']);
        $this->assertFalse($j['data']['valid']);
        $this->assertStringContainsString('expired', $j['data']['message']);
        DB::query('UPDATE licenses SET expires_at = NULL WHERE id = :id', ['id' => $lic['id']]);
        self::$root->post('platform_licenses.php', ['op' => 'revoke', 'id' => $lic['id']]);
        [, $j] = $check(['key' => self::$licenseKey, 'domain' => 'new-server.example.com']);
        $this->assertFalse($j['data']['valid']);
        self::$root->post('platform_licenses.php', ['op' => 'revoke', 'id' => $lic['id']]); // reactivate
        [, $j] = $check(['key' => self::$licenseKey, 'domain' => 'new-server.example.com']);
        $this->assertTrue($j['data']['valid']);
        // GET is not allowed; rate limit per IP.
        [$s] = TestEnv::http('GET', self::$url . 'api/license/check');
        $this->assertSame(405, $s);
        Settings::setPlatform('license_rate_per_min', '5');
        DB::query("DELETE FROM rate_limits WHERE rl_key LIKE 'license:%'");
        $codes = [];
        for ($i = 0; $i < 7; $i++) {
            $codes[] = $check(['key' => 'HC-X', 'domain' => 'x.example'])[0];
        }
        $this->assertContains(429, $codes);
        DB::query("DELETE FROM rate_limits WHERE rl_key LIKE 'license:%'");
        Settings::setPlatform('license_rate_per_min', '30');
    }

    public function testLicenseClientGraceAndUnlicensedMode(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lic');
        License::$file = $file;
        Config::set('mode', 'standalone');
        Config::set('license_key', '');
        License::reset();
        Tenant::forget();
        try {
            // No key → unlicensed demo: works, max 2 TVs.
            $this->assertSame('unlicensed', License::state()['status']);
            $this->assertTrue(License::allowsOperation());
            $this->assertSame(2, Tenant::maxTvs(1));
            $this->assertNotNull(License::banner());

            Config::set('license_key', 'HC-TEST-KEY');
            License::reset();
            $answer = ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['valid' => true, 'hotel' => 'Inn', 'max_tvs' => 7, 'expires_at' => null, 'features' => [], 'message' => 'License valid']]), 'error' => null];
            $calls = 0;
            License::$transport = function (string $url, array $payload) use (&$answer, &$calls) {
                $calls++;
                $this->assertSame('HC-TEST-KEY', $payload['key']);
                $this->assertArrayHasKey('domain', $payload);
                $this->assertArrayHasKey('tv_count', $payload);
                return $answer;
            };
            $this->assertSame('valid', License::check()['status']);
            $this->assertSame(7, License::maxTvs());
            $this->assertSame(7, Tenant::maxTvs(1));
            License::check();
            $this->assertSame(1, $calls, 'checked at most once a day');

            // Server unreachable → offline grace (still valid).
            $answer = ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'];
            $this->assertSame('grace', License::check(true)['status']);
            $this->assertTrue(License::allowsOperation());
            $this->assertSame(7, License::maxTvs());
            // Grace is over after 14 days without a successful check.
            $data = json_decode((string) file_get_contents($file), true);
            $data['last_ok_at'] = time() - 15 * 86400;
            file_put_contents($file, json_encode($data));
            License::reset();
            $this->assertSame('invalid', License::state()['status']);
            $this->assertFalse(License::allowsOperation());
            Tenant::forget();
            $this->assertSame('expired', Tenant::state(1));
            $room = DB::insert('rooms', ['room_number' => 'LIC1']);
            $this->assertSame('suspended', ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $room]))['mode']);

            // Explicit "invalid" answer from the server.
            $answer = ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['valid' => false, 'message' => 'License revoked']]), 'error' => null];
            $s = License::check(true);
            $this->assertSame('invalid', $s['status']);
            $this->assertSame('License revoked', $s['message']);

            // Real round trip against this platform's license endpoint.
            License::$transport = null;
            Config::set('license_server', self::$url);
            $key = LicenseServer::newKey();
            DB::insert('licenses', ['license_key' => $key, 'customer_name' => 'Loopback', 'max_tvs' => 3]);
            Config::set('license_key', $key);
            License::reset();
            $s = License::check(true);
            $this->assertSame('valid', $s['status'], $s['message']);
            $this->assertSame(3, License::maxTvs());
            $this->assertNotNull(DB::value('SELECT bound_domain FROM licenses WHERE license_key = :k', ['k' => $key]));
        } finally {
            Config::set('mode', 'saas');
            Config::set('license_key', '');
            License::$transport = null;
            License::$file = null;
            License::reset();
            Tenant::forget();
            @unlink($file);
        }
        $this->assertSame('saas', License::state()['status']);
        $this->assertNull(License::maxTvs());
    }

    public function testBrandingInContentAndLogin(): void
    {
        Settings::setPlatform('platform_name', 'StayCast');
        Settings::setPlatform('platform_color', '#123456');
        Settings::setPlatform('platform_support_phone', '+91 99999 00000');
        Branding::flush();
        Tenant::each(static function (): void {
            Settings::bumpContentVersion();
        });
        $c = self::poll('tv-sea-0001');
        $this->assertSame(['product' => 'StayCast', 'logo_url' => null, 'color' => '#123456', 'support' => '+91 99999 00000'], $c['branding']);

        // Reseller override for its hotels, hotel override above that.
        $room = Tenant::run(self::$resHotel, fn () => DB::insert('rooms', ['room_number' => 'R1']));
        $build = fn () => Tenant::run(self::$resHotel, fn () => ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $room])));
        Branding::flush();
        $this->assertSame('PartnerTV', $build()['branding']['product']);
        $this->assertSame('#00AA00', $build()['branding']['color']);
        DB::query("UPDATE hotels SET brand_name = 'HotelTV' WHERE id = :id", ['id' => self::$resHotel]);
        Branding::flush();
        $this->assertSame('HotelTV', $build()['branding']['product']);
        $this->assertSame('#00AA00', $build()['branding']['color']);

        // Login page (platform branding) and ?b=<slug> (hotel branding).
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringContainsString('StayCast', $html);
        $this->assertStringContainsString('#123456', $html);
        $slug = (string) DB::value('SELECT slug FROM hotels WHERE id = :id', ['id' => self::$resHotel]);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php?b=' . $slug);
        $this->assertStringContainsString('Res Hotel One', $html);
        $this->assertStringContainsString('HotelTV', $html);
        // Admin header uses the brand too.
        [, , $html] = self::$root->get('platform_hotels.php');
        $this->assertStringContainsString('StayCast', $html);
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }
}
