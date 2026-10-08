<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QR setup of TVs: POST /api/provision/start → GET /api/provision/status (pending) → admin claims
 * the code in admin/claim.php (HTTP, logged in, CSRF) → status "claimed" with server URL, room and
 * registration key → /api/device/register → status "used". Expiry, wrong secret, rate limits, code
 * collisions, cross-hotel isolation, platform admin / reseller hotel choice, permissions,
 * suspended hotel / TV limit, login redirect with next=claim.php?code=…, cleanup task.
 */
final class ProvisioningTest extends TestCase
{
    private static string $url;
    private static array $u = [];
    private static array $room = [];
    private static string $key1 = '';
    private static string $key2 = '';
    /** @var array<string, AdminSession> */
    private static array $sessions = [];
    private static int $uidSeq = 0;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['qmgr1' => 'manager', 'qstaff1' => 'staff', 'qrecep1' => 'reception'] as $name => $role) {
            self::$u[$name] = DB::insert('users', ['hotel_id' => 1, 'username' => $name, 'email' => $name . '@h1.test', 'full_name' => $name, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$u['qplat'] = DB::insert('users', ['hotel_id' => null, 'username' => 'qplat', 'email' => 'qplat@p.test', 'full_name' => 'Platform', 'password_hash' => $pw, 'role' => 'platform_admin']);
        foreach (['101', '102', '103'] as $n) {
            self::$room[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        Settings::set('hotel_name', 'Hotel One');
        self::$key1 = (string) Settings::get('registration_key');
        self::assertSame(2, Hotels::create(['name' => 'Hotel Two']));
        Tenant::run(2, static function () use ($pw): void {
            self::$room['777'] = DB::insert('rooms', ['room_number' => '777', 'name' => 'Seaview 777', 'floor' => '7']);
            self::$u['qmgr2'] = DB::insert('users', ['hotel_id' => 2, 'username' => 'qmgr2', 'email' => 'qmgr2@h2.test', 'full_name' => 'M2', 'password_hash' => $pw, 'role' => 'manager']);
        });
        self::$key2 = (string) Settings::getFor(2, 'registration_key');
        // Hotel 3 belongs to a reseller.
        $res = DB::insert('resellers', ['name' => 'QR Partner', 'commission_percent' => 5]);
        self::assertSame(3, Hotels::create(['name' => 'Partner Hotel', 'reseller_id' => $res]));
        Tenant::run(3, static function (): void {
            self::$room['301'] = DB::insert('rooms', ['room_number' => '301', 'name' => 'Partner 301', 'floor' => '3']);
        });
        self::$u['qres'] = DB::insert('users', ['hotel_id' => null, 'reseller_id' => $res, 'username' => 'qres', 'email' => 'qres@p.test', 'full_name' => 'Reseller', 'password_hash' => $pw, 'role' => 'reseller']);
        Settings::setPlatform('task_last_ProvisioningCleanupTask', (string) (time() + 86400));
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        self::$sessions = [];
        Provisioning::$codeGenerator = null;
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Tenant::forget();
        Settings::flush();
    }

    // ------------------------------------------------------------------ helpers

    private static function s(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function newUid(): string
    {
        return 'qr-tv-' . getmypid() . '-' . (++self::$uidSeq);
    }

    /** @return array{0:string,1:string,2:string} code, secret, device uid */
    private function start(?string $uid = null, string $model = 'Sony BRAVIA KD-43'): array
    {
        $uid ??= self::newUid();
        [$s, $j, $raw] = TestEnv::http('POST', self::$url . 'api/provision/start', ['device_id' => $uid, 'model' => $model, 'app_version' => '2.1.0']);
        $this->assertSame(200, $s, $raw);
        return [$j['data']['code'], $j['data']['secret'], $uid];
    }

    private static function provStatus(string $code, string $secret): array
    {
        return TestEnv::http('GET', self::$url . 'api/provision/status?code=' . rawurlencode($code) . '&secret=' . rawurlencode($secret));
    }

    private static function provRow(string $code): array
    {
        return DB::one('SELECT * FROM device_provisioning WHERE code = :c ORDER BY id DESC LIMIT 1', ['c' => $code]) ?? [];
    }

    private static function assign(string $user, string $code, array $fields): array
    {
        $row = self::provRow($code);
        return self::s($user)->post('claim.php', $fields + ['op' => 'assign', 'code' => $code, 'prov_id' => $row['id'] ?? 0]);
    }

    private static function location(string $head): string
    {
        return preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------ tests

    public function testFullFlowStartClaimRegisterUsed(): void
    {
        $uid = self::newUid();
        [$s, $j, $raw] = TestEnv::http('POST', self::$url . 'api/provision/start', ['device_id' => $uid, 'model' => 'Mi TV 4A', 'app_version' => '2.1.0']);
        $this->assertSame(200, $s, $raw);
        $this->assertTrue($j['ok']);
        $d = $j['data'];
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $d['code']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $d['secret']);
        $this->assertSame(self::$url . 'admin/claim.php?code=' . $d['code'], $d['claim_url']);
        $this->assertSame(900, $d['expires_in']);
        $this->assertSame(3, $d['poll_interval']);
        $row = self::provRow($d['code']);
        $this->assertSame(hash('sha256', $d['secret']), $row['secret_hash'], 'only the hash is stored');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Mi TV 4A', $row['model']);
        $this->assertNull($row['hotel_id']);

        [$s, $j] = self::provStatus($d['code'], $d['secret']);
        $this->assertSame(200, $s);
        $this->assertSame('pending', $j['data']['status']);
        // Lower-case code from a sloppy client still works.
        [$s, $j] = self::provStatus(strtolower($d['code']), $d['secret']);
        $this->assertSame('pending', $j['data']['status']);

        // Manager scans: page shows the TV + rooms (rooms without TV first), assigns room 102.
        [$s, , $html] = self::s('qmgr1')->get('claim.php?code=' . strtolower($d['code']));
        $this->assertSame(200, $s);
        $this->assertStringContainsString($d['code'], $html);
        $this->assertStringContainsString('Mi TV 4A', $html);
        $this->assertStringContainsString('name="room" value="' . self::$room['102'] . '"', $html);
        $this->assertStringNotContainsString('name="room" value="' . self::$room['777'] . '"', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));

        // Without CSRF → 419, nothing changes.
        [$s] = TestEnv::http('POST', self::$url . 'admin/claim.php', null, [], self::s('qmgr1')->jar, ['op' => 'assign', 'code' => $d['code'], 'prov_id' => (string) $row['id'], 'room' => (string) self::$room['102']]);
        $this->assertSame(419, $s);
        $this->assertSame('pending', self::provRow($d['code'])['status']);

        [$s, , , $head] = self::assign('qmgr1', $d['code'], ['room' => self::$room['102']]);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('claim.php?done=' . $row['id'], self::location($head));
        $row = self::provRow($d['code']);
        $this->assertSame('claimed', $row['status']);
        $this->assertSame(1, (int) $row['hotel_id']);
        $this->assertSame(self::$room['102'], (int) $row['room_id']);
        $this->assertSame(self::$u['qmgr1'], (int) $row['claimed_by']);
        $this->assertNotNull($row['claimed_at']);

        [$s, , $html] = self::s('qmgr1')->get('claim.php?done=' . $row['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('TV assigned to screen 102', $html);
        [$s, $j] = self::s('qmgr1')->ajax('claim_status&id=' . $row['id']);
        $this->assertSame(200, $s);
        $this->assertSame('waiting', $j['data']['status']);

        // TV polls: claimed with everything it needs to register.
        [$s, $j] = self::provStatus($d['code'], $d['secret']);
        $this->assertSame(200, $s);
        $c = $j['data'];
        $this->assertSame('claimed', $c['status']);
        $this->assertSame(self::$url, $c['server_url']);
        $this->assertStringEndsWith('/', $c['server_url']);
        $this->assertSame('102', $c['room_number']);
        $this->assertSame(self::$key1, $c['registration_key']);
        $this->assertSame('Hotel One', $c['hotel_name']);

        [$s, $j, $raw] = TestEnv::http('POST', $c['server_url'] . 'api/device/register', ['device_id' => $uid, 'room_number' => $c['room_number'], 'registration_key' => $c['registration_key'], 'model' => 'Mi TV 4A', 'app_version' => '2.1.0']);
        $this->assertSame(200, $s, $raw);
        $this->assertSame('102', $j['data']['room']['number']);

        [$s, $j] = self::provStatus($d['code'], $d['secret']);
        $this->assertSame('used', $j['data']['status']);
        $this->assertArrayNotHasKey('registration_key', $j['data']);
        $this->assertSame('used', self::provRow($d['code'])['status']);
        $this->assertNotNull(self::provRow($d['code'])['used_at']);
        [, $j] = self::s('qmgr1')->ajax('claim_status&id=' . $row['id']);
        $this->assertSame('registered', $j['data']['status']);
        $this->assertTrue($j['data']['online']);
        $this->assertSame('102', $j['data']['room_number']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'tv_qr_assign' AND hotel_id = 1 AND entity_id = :r", ['r' => self::$room['102']]));
    }

    public function testWrongSecretAndUnknownCodeAre404(): void
    {
        [$code, $secret] = $this->start();
        foreach ([
            [$code, str_repeat('a', 64)],
            [$code, ''],
            [$code, substr($secret, 0, 63)],
            ['ZZZZZZ', $secret],
            ['0O1IL0', $secret],
            ['', $secret],
        ] as [$c, $sec]) {
            [$s, $j] = self::provStatus($c, $sec);
            $this->assertSame(404, $s, "$c / $sec");
            $this->assertSame('NOT_FOUND', $j['error']['code']);
        }
        // Validation / method errors on start.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/provision/start', ['device_id' => 'short']);
        $this->assertSame(400, $s);
        $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
        [$s] = TestEnv::http('GET', self::$url . 'api/provision/start');
        $this->assertSame(405, $s);
        [$s] = TestEnv::http('POST', self::$url . 'api/provision/status?code=' . $code . '&secret=' . $secret);
        $this->assertSame(405, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/provision/other');
        $this->assertSame(404, $s);
    }

    public function testRestartInvalidatesPreviousPendingCode(): void
    {
        $uid = self::newUid();
        [$code1, $secret1] = $this->start($uid);
        [$code2, $secret2] = $this->start($uid);
        $this->assertNotSame($code1, $code2);
        [, $j] = self::provStatus($code1, $secret1);
        $this->assertSame('expired', $j['data']['status']);
        [, $j] = self::provStatus($code2, $secret2);
        $this->assertSame('pending', $j['data']['status']);
        // The old code can no longer be claimed.
        [$s, , $html] = self::s('qmgr1')->get('claim.php?code=' . $code1);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('not found or has expired', $html);
        $this->assertStringNotContainsString('name="prov_id"', $html);
    }

    public function testExpiry(): void
    {
        [$code, $secret] = $this->start();
        DB::query('UPDATE device_provisioning SET expires_at = :t WHERE code = :c', ['t' => gmdate('Y-m-d H:i:s', time() - 5), 'c' => $code]);
        [, , $html] = self::s('qmgr1')->get('claim.php?code=' . $code);
        $this->assertStringNotContainsString('name="prov_id"', $html);
        [, $j] = self::provStatus($code, $secret);
        $this->assertSame('expired', $j['data']['status']);
        $this->assertSame('expired', self::provRow($code)['status']);

        // Claim while pending, then let the claim window (30 min) pass without registration.
        [$code, $secret] = $this->start();
        $this->assertSame(302, self::assign('qmgr1', $code, ['room' => self::$room['103']])[0]);
        $row = self::provRow($code);
        $this->assertSame('claimed', $row['status']);
        // POSTing the same code again → already assigned.
        [$s] = self::assign('qmgr1', $code, ['room' => self::$room['101']]);
        $this->assertSame(302, $s);
        $this->assertSame(self::$room['103'], (int) self::provRow($code)['room_id']);
        DB::query('UPDATE device_provisioning SET claimed_at = :t WHERE id = :id', ['t' => gmdate('Y-m-d H:i:s', time() - 1801), 'id' => $row['id']]);
        [, $j] = self::provStatus($code, $secret);
        $this->assertSame('expired', $j['data']['status']);
        [, $j] = self::s('qmgr1')->ajax('claim_status&id=' . $row['id']);
        $this->assertSame('expired', $j['data']['status']);

        // Assign POST for an expired code changes nothing.
        [$code] = $this->start();
        DB::query('UPDATE device_provisioning SET expires_at = :t WHERE code = :c', ['t' => gmdate('Y-m-d H:i:s', time() - 5), 'c' => $code]);
        self::assign('qmgr1', $code, ['room' => self::$room['101']]);
        $this->assertNull(self::provRow($code)['hotel_id']);
    }

    public function testStartRateLimits(): void
    {
        $uid = self::newUid();
        for ($i = 0; $i < 5; $i++) {
            $this->start($uid);
        }
        [$s, $j, , $head] = TestEnv::http('POST', self::$url . 'api/provision/start', ['device_id' => $uid]);
        $this->assertSame(429, $s);
        $this->assertSame('RATE_LIMITED', $j['error']['code']);
        $this->assertMatchesRegularExpression('/^Retry-After:\s*\d+/mi', $head);

        DB::query('DELETE FROM rate_limits');
        for ($i = 0; $i < 10; $i++) {
            $this->start();
        }
        [$s, , , $head] = TestEnv::http('POST', self::$url . 'api/provision/start', ['device_id' => self::newUid()]);
        $this->assertSame(429, $s, 'per-IP limit');
        $this->assertMatchesRegularExpression('/^Retry-After:\s*\d+/mi', $head);
    }

    public function testStatusRateLimitPerCode(): void
    {
        [$code, $secret] = $this->start();
        for ($i = 0; $i < 60; $i++) {
            [$s] = self::provStatus($code, $secret);
            $this->assertSame(200, $s);
        }
        [$s, $j, , $head] = self::provStatus($code, $secret);
        $this->assertSame(429, $s);
        $this->assertSame('RATE_LIMITED', $j['error']['code']);
        $this->assertMatchesRegularExpression('/^Retry-After:\s*\d+/mi', $head);
    }

    public function testCodeAlphabetAndCollisions(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', Provisioning::randomCode());
        }
        $this->assertSame('K7P2QX', Provisioning::normalizeCode(' k7p2-qx '));
        $this->assertNull(Provisioning::normalizeCode('K7P2Q0'));
        $this->assertNull(Provisioning::normalizeCode('K7P2Q'));

        $seq = ['AAAAAA', 'AAAAAA', 'BAD0OI', 'BBBBBB', 'AAAAAA', 'CCCCCC'];
        Provisioning::$codeGenerator = static function () use (&$seq): string {
            return array_shift($seq) ?? 'DDDDDD';
        };
        try {
            DB::query("DELETE FROM device_provisioning WHERE code IN ('AAAAAA','BBBBBB','CCCCCC')");
            $a = Provisioning::start(['device_id' => self::newUid()], '127.0.0.1');
            $this->assertSame('AAAAAA', $a['code']);
            $b = Provisioning::start(['device_id' => self::newUid()], '127.0.0.1');
            $this->assertSame('BBBBBB', $b['code'], 'active code and invalid code skipped');
            $c = Provisioning::start(['device_id' => self::newUid()], '127.0.0.1');
            $this->assertSame('CCCCCC', $c['code']);
            // Once AAAAAA expired the code may be reused; the old secret does not open the new row.
            DB::query("UPDATE device_provisioning SET status = 'expired' WHERE code = 'AAAAAA'");
            $seq = ['AAAAAA'];
            $a2 = Provisioning::start(['device_id' => self::newUid()], '127.0.0.1');
            $this->assertSame('AAAAAA', $a2['code']);
            $this->assertSame('expired', Provisioning::statusFor(Provisioning::findBySecret('AAAAAA', $a['secret']))['status']);
            $this->assertSame('pending', Provisioning::statusFor(Provisioning::findBySecret('AAAAAA', $a2['secret']))['status']);
            // Every candidate taken → error instead of a duplicate.
            $seq = array_fill(0, 30, 'BBBBBB');
            $this->expectException(RuntimeException::class);
            Provisioning::start(['device_id' => self::newUid()], '127.0.0.1');
        } finally {
            Provisioning::$codeGenerator = null;
            DB::query("DELETE FROM device_provisioning WHERE code IN ('AAAAAA','BBBBBB','CCCCCC')");
        }
    }

    public function testCrossHotelManagerCannotSeeOrAssignOtherHotel(): void
    {
        [$code] = $this->start();
        $prov = self::provRow($code);
        // Manager of hotel 2 sees only hotel 2's rooms.
        [$s, , $html] = self::s('qmgr2')->get('claim.php?code=' . $code . '&hotel=1');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="room" value="' . self::$room['777'] . '"', $html);
        foreach (['101', '102', '103'] as $n) {
            $this->assertStringNotContainsString('name="room" value="' . self::$room[$n] . '"', $html);
        }
        $this->assertStringNotContainsString('Room 101', $html);
        $this->assertStringNotContainsString('name="hotel"', $html, 'hotel users get no hotel choice');

        // Posting a hotel-1 room (even with hotel=1) → 404, row unchanged.
        [$s] = self::assign('qmgr2', $code, ['room' => self::$room['101'], 'hotel' => 1]);
        $this->assertSame(404, $s);
        $this->assertSame('pending', self::provRow($code)['status']);
        $this->assertNull(self::provRow($code)['hotel_id']);
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE id = :id AND hotel_id = 1", ['id' => self::$room['101']]));

        // Manager 1 claims it; manager 2 can neither see the result nor poll its status.
        $this->assertSame(302, self::assign('qmgr1', $code, ['room' => self::$room['101']])[0]);
        [, , $html] = self::s('qmgr2')->get('claim.php?code=' . $code);
        $this->assertStringContainsString('already been assigned', $html);
        $this->assertStringNotContainsString('screen 101', $html);
        [, , $html] = self::s('qmgr2')->get('claim.php?done=' . $prov['id']);
        $this->assertStringNotContainsString('TV assigned to screen', $html);
        [$s] = self::s('qmgr2')->ajax('claim_status&id=' . $prov['id']);
        $this->assertSame(404, $s);
        // Manager 1 re-opening the code sees the success view.
        [, , $html] = self::s('qmgr1')->get('claim.php?code=' . $code);
        $this->assertStringContainsString('TV assigned to screen 101', $html);

        // Hotel users never see the list of waiting TVs.
        [, , $html] = self::s('qmgr1')->get('claim.php');
        $this->assertStringNotContainsString('TVs waiting for setup', $html);
    }

    public function testPlatformAdminChoosesHotel(): void
    {
        [$code, $secret] = $this->start(null, 'Platform Test TV');
        [$s, , $html] = self::s('qplat')->get('claim.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('TVs waiting for setup', $html);
        $this->assertStringContainsString($code, $html);

        [$s, , $html] = self::s('qplat')->get('claim.php?code=' . $code);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="hotel"', $html);
        $this->assertStringContainsString('Hotel Two', $html);
        $this->assertStringContainsString('Partner Hotel', $html);
        $this->assertStringNotContainsString('name="prov_id"', $html, 'no rooms before a hotel is chosen');
        $this->assertStringContainsString('Choose the customer first.', $html);

        [, , $html] = self::s('qplat')->get('claim.php?code=' . $code . '&hotel=2');
        $this->assertStringContainsString('name="room" value="' . self::$room['777'] . '"', $html);
        $this->assertStringNotContainsString('name="room" value="' . self::$room['101'] . '"', $html);

        // Room of hotel 1 while hotel 2 is chosen → 404.
        [$s] = self::assign('qplat', $code, ['hotel' => 2, 'room' => self::$room['101']]);
        $this->assertSame(404, $s);
        $this->assertSame('pending', self::provRow($code)['status']);

        [$s, , , $head] = self::assign('qplat', $code, ['hotel' => 2, 'room' => self::$room['777']]);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('hotel=2', self::location($head));
        $row = self::provRow($code);
        $this->assertSame(2, (int) $row['hotel_id']);
        $this->assertSame(self::$room['777'], (int) $row['room_id']);
        [, $j] = self::provStatus($code, $secret);
        $this->assertSame(self::$key2, $j['data']['registration_key']);
        $this->assertSame('777', $j['data']['room_number']);
        $this->assertSame('Hotel Two', $j['data']['hotel_name']);
        [$s, $j] = self::s('qplat')->ajax('claim_status&id=' . $row['id']);
        $this->assertSame(200, $s);
        $this->assertSame('waiting', $j['data']['status']);
        // The platform admin's session was not switched into hotel 2.
        [$s, , , $head] = self::s('qplat')->get('index.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_hotels.php', self::location($head));
    }

    public function testResellerOnlyOwnHotels(): void
    {
        [$code] = $this->start();
        [$s, , $html] = self::s('qres')->get('claim.php?code=' . $code);
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('TVs waiting for setup', $html);
        $this->assertStringContainsString('Partner Hotel', $html);
        $this->assertStringNotContainsString('Hotel Two', $html);
        // Only one hotel → preselected.
        $this->assertStringContainsString('name="room" value="' . self::$room['301'] . '"', $html);

        [, , $html] = self::s('qres')->get('claim.php?code=' . $code . '&hotel=1');
        $this->assertStringContainsString('You cannot manage this customer.', $html);
        $this->assertStringNotContainsString('name="room" value="' . self::$room['101'] . '"', $html);

        self::assign('qres', $code, ['hotel' => 1, 'room' => self::$room['101']]);
        $this->assertSame('pending', self::provRow($code)['status']);
        $this->assertSame(302, self::assign('qres', $code, ['hotel' => 3, 'room' => self::$room['301']])[0]);
        $this->assertSame(3, (int) self::provRow($code)['hotel_id']);
    }

    public function testStaffAndReceptionAreForbidden(): void
    {
        [$code] = $this->start();
        foreach (['qstaff1', 'qrecep1'] as $user) {
            [$s, , $html] = self::s($user)->get('claim.php?code=' . $code);
            $this->assertSame(403, $s, $user);
            $this->assertStringNotContainsString('name="room" value="' . self::$room['101'] . '"', $html);
            [$s] = self::assign($user, $code, ['room' => self::$room['101']]);
            $this->assertSame(403, $s, $user);
            [, , $html] = self::s($user)->get('rooms.php');
            $this->assertStringNotContainsString('claim.php', $html, 'no QR button / menu without rooms.manage');
        }
        $this->assertSame('pending', self::provRow($code)['status']);
        // Manager sees the button and the menu item.
        [, , $html] = self::s('qmgr1')->get('rooms.php');
        $this->assertStringContainsString('admin/claim.php', $html);
        $this->assertStringContainsString('Add TV (QR)', $html);
    }

    public function testSuspendedHotelAndTvLimitAreShownBeforeAssigning(): void
    {
        [$code, , $uid] = $this->start();
        Hotels::setStatus(2, 'suspended', 'test');
        try {
            [, , $html] = self::s('qplat')->get('claim.php?code=' . $code . '&hotel=2');
            $this->assertStringContainsString('This TV cannot be added right now', $html);
            $this->assertStringContainsString('suspended', $html);
            $this->assertMatchesRegularExpression('/id="qrAssign"\s+disabled/', $html);
            self::assign('qplat', $code, ['hotel' => 2, 'room' => self::$room['777']]);
            $this->assertSame('pending', self::provRow($code)['status']);
        } finally {
            Hotels::setStatus(2, 'active');
        }

        // TV limit of hotel 1 reached.
        $count = (int) DB::value('SELECT COUNT(*) FROM devices WHERE hotel_id = 1 AND is_revoked = 0 AND room_id IS NOT NULL');
        DB::query('UPDATE hotels SET max_tvs = :m WHERE id = 1', ['m' => $count]);
        Tenant::forget();
        try {
            [, , $html] = self::s('qmgr1')->get('claim.php?code=' . $code);
            $this->assertStringContainsString('TV limit reached', $html);
            $this->assertMatchesRegularExpression('/id="qrAssign"\s+disabled/', $html);
            self::assign('qmgr1', $code, ['room' => self::$room['101']]);
            $this->assertSame('pending', self::provRow($code)['status']);
        } finally {
            DB::query('UPDATE hotels SET max_tvs = NULL WHERE id = 1');
            Tenant::forget();
        }
        $this->assertSame(302, self::assign('qmgr1', $code, ['room' => self::$room['101']])[0]);
        $this->assertSame('claimed', self::provRow($code)['status']);
        $this->assertSame($uid, self::provRow($code)['device_uid']);
    }

    public function testCreateNewRoomInline(): void
    {
        [$code, $secret] = $this->start();
        [$s] = self::assign('qmgr1', $code, ['room' => 'new', 'new_room_number' => 'Q512', 'new_floor' => '5']);
        $this->assertSame(302, $s);
        $room = DB::one("SELECT * FROM rooms WHERE hotel_id = 1 AND room_number = 'Q512'");
        $this->assertNotNull($room);
        $this->assertSame('5', $room['floor']);
        $this->assertSame((int) $room['id'], (int) self::provRow($code)['room_id']);
        [, $j] = self::provStatus($code, $secret);
        $this->assertSame('Q512', $j['data']['room_number']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = 2 AND room_number = 'Q512'"));

        // Invalid room number → nothing created, still pending.
        [$code2] = $this->start();
        self::assign('qmgr1', $code2, ['room' => 'new', 'new_room_number' => '<b>x</b>']);
        $this->assertSame('pending', self::provRow($code2)['status']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE room_number LIKE '%<b>%'"));
    }

    public function testWrongCodeAttemptsAreThrottled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            [, , $html] = self::s('qmgr1')->get('claim.php?code=ZZZZZ' . 'ABCDEFGHJK'[$i]);
            $this->assertStringContainsString('not found or has expired', $html);
        }
        [$code] = $this->start();
        [, , $html] = self::s('qmgr1')->get('claim.php?code=' . $code);
        $this->assertStringContainsString('Too many wrong codes', $html);
        $this->assertStringNotContainsString('name="prov_id"', $html);
    }

    public function testLoginRedirectKeepsTheCode(): void
    {
        [$code] = $this->start();
        $jar = (string) tempnam(sys_get_temp_dir(), 'qr');
        try {
            [$s, , , $head] = TestEnv::http('GET', self::$url . 'admin/claim.php?code=' . $code, null, [], $jar);
            $this->assertSame(302, $s);
            $loc = self::location($head);
            $this->assertStringContainsString('login.php?next=', $loc);
            parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
            $this->assertSame('/admin/claim.php?code=' . $code, $q['next']);

            [, , $html] = TestEnv::http('GET', $loc, null, [], $jar);
            $this->assertStringContainsString('name="next" value="/admin/claim.php?code=' . $code . '"', $html);
            preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
            [$s, , , $head] = TestEnv::http('POST', self::$url . 'admin/login.php', null, [], $jar, [
                '_csrf' => html_entity_decode($m[1] ?? ''), 'username' => 'qmgr1', 'password' => 'Passw0rd!', 'next' => $q['next'],
            ]);
            $this->assertSame(302, $s);
            $this->assertSame('/admin/claim.php?code=' . $code, self::location($head));
            [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/claim.php?code=' . $code, null, [], $jar);
            $this->assertSame(200, $s);
            $this->assertStringContainsString('name="prov_id"', $html);
        } finally {
            @unlink($jar);
        }
    }

    public function testCleanupTaskPurgesOldRows(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
        DB::insert('device_provisioning', ['code' => 'OLDAAA', 'secret_hash' => str_repeat('0', 64), 'device_uid' => 'old-device-1', 'status' => 'used', 'created_at' => $old, 'expires_at' => $old]);
        [$code] = $this->start();
        $out = (new ProvisioningCleanupTask())->run();
        $this->assertGreaterThanOrEqual(1, $out['deleted']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_provisioning WHERE code = 'OLDAAA'"));
        $this->assertNotEmpty(self::provRow($code));
        $this->assertSame(86400, (new ProvisioningCleanupTask())->interval());
    }

    public function testGujaratiTranslationsCoverThePage(): void
    {
        $gu = require HC_ROOT . '/lang/gu_provision.php';
        $src = file_get_contents(HC_ROOT . '/admin/claim.php') . file_get_contents(HC_ROOT . '/core/Provisioning.php')
            . file_get_contents(HC_ROOT . '/admin/partials/nav.d/15_qr_setup.php') . file_get_contents(HC_ROOT . '/admin/ajax.d/claim.php');
        preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
        $all = $gu;
        foreach (glob(HC_ROOT . '/lang/gu*.php') ?: [] as $f) {
            $all += require $f;
        }
        foreach (array_unique($m[1]) as $key) {
            $key = stripslashes($key);
            $this->assertArrayHasKey($key, $all, 'missing Gujarati: ' . $key);
        }
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors());
    }
}
