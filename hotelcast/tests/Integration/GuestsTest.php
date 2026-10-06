<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guests / front desk / check-in mode / PMS (V2_SPEC §1): check-in / check-out flows, guest token
 * rotation, TV content extension (guest, welcome, checkout reminder, services, guest_menu, vacant
 * modes, languages), room move, PII retention, PMS API (auth, idempotency, room move, mapping,
 * isolation), front desk pages per role and cross-hotel attempts.
 */
final class GuestsTest extends TestCase
{
    private static string $url;
    private static array $a = [];
    private static array $b = [];
    private static string $tvToken = '';
    private static string $tvUid = 'tv-guests-0101';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'gBoss', 'manager' => 'gMgr', 'staff' => 'gStaff', 'reception' => 'gRecep'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@a.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        Settings::set('hotel_name', 'Dwarka Palace');
        foreach (['101', '102', '103', '104', '105'] as $n) {
            self::$a['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        self::$a['content'] = DB::insert('content_items', ['title' => 'Promo', 'type' => 'announcement', 'body' => 'Hello', 'duration' => 10]);
        Settings::set('default_content_id', (string) self::$a['content']);

        $hb = Hotels::create(['name' => 'Hotel B'], ['username' => 'gBossB', 'email' => 'gbossb@b.test', 'password' => 'Passw0rd!', 'full_name' => 'Boss B']);
        self::$b['hotel'] = $hb;
        Tenant::run($hb, static function (): void {
            self::$b['r1'] = DB::insert('rooms', ['room_number' => 'B101', 'name' => 'B Room']);
            self::$b['r2'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'B same number']);
            self::$b['stay'] = Guests::checkIn(self::$b['r1'], ['guest_name' => 'Secret Guest B', 'salutation' => 'Mr.', 'language' => 'hi', 'phone' => '9876500000']);
        });
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);

        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tvUid, 'room_number' => '101', 'registration_key' => (string) Settings::get('registration_key')]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tvToken = $j['data']['token'];
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        Cache::clear();
    }

    private static function room(int $id): array
    {
        return DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $id]);
    }

    private static function content(int $roomId): array
    {
        return ContentResolver::build(self::room($roomId));
    }

    private static function pms(string $method, string $path, ?array $json = null, ?string $key = null, array $headers = []): array
    {
        if ($key !== null) {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        return TestEnv::http($method, self::$url . 'api/pms/' . $path, $json, $headers);
    }

    // ------------------------------------------------------------------ stays + tokens

    public function testCheckInCheckOutRotatesTokens(): void
    {
        $rid = self::$a['r102'];
        $t0 = Guests::roomToken($rid);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $t0);
        $this->assertSame($t0, Guests::roomToken($rid), 'stable while nothing happens');

        $sid = Guests::checkIn($rid, ['guest_name' => 'Rajesh Shah', 'salutation' => 'mr', 'language' => 'gu', 'phone' => '+91 98765 43210', 'checkout_at' => date('Y-m-d', time() + 86400)]);
        $stay = Guests::findStay($sid);
        $this->assertSame('Mr.', $stay['salutation']);
        $this->assertSame('gu', $stay['language']);
        $this->assertStringEndsWith('10:00:00', (string) $stay['expected_checkout_at'], 'date only → standard checkout time');
        $t1 = Guests::roomToken($rid);
        $this->assertNotSame($t0, $t1, 'rotated on check-in');
        $this->assertNull(Guests::resolveToken($t0), 'old token dead');
        $ctx = Guests::resolveToken($t1);
        $this->assertSame($sid, (int) $ctx['stay']['id']);
        $this->assertSame('gu', $ctx['lang']);

        // A second check-in into the occupied room is refused.
        try {
            Guests::checkIn($rid, ['guest_name' => 'Other']);
            $this->fail('occupied room accepted a second guest');
        } catch (RuntimeException $e) {
            $this->assertSame('ROOM_OCCUPIED', $e->getMessage());
        }
        // Validation
        try {
            Guests::checkIn(self::$a['r105'], ['guest_name' => '  ']);
            $this->fail('empty name accepted');
        } catch (InvalidArgumentException) {
            $this->assertNull(Guests::activeStay(self::$a['r105']));
        }

        $this->assertTrue(Guests::checkOut($sid));
        $this->assertFalse(Guests::checkOut($sid), 'idempotent');
        $t2 = Guests::roomToken($rid);
        $this->assertNotSame($t1, $t2, 'rotated on check-out');
        $this->assertNull(Guests::resolveToken($t1));
        // Check-in mode off → the vacant room's current token stays usable (room service without front desk).
        Settings::set('guest_checkin_mode', '0');
        $this->assertNotNull(Guests::resolveToken($t2));
        Settings::set('guest_checkin_mode', '1');
        $this->assertNull(Guests::resolveToken($t2), 'check-in mode: vacant room has no valid guest link');
        Settings::set('guest_checkin_mode', '0');
    }

    public function testMaskPhone(): void
    {
        $this->assertSame('98•••••210', Guests::maskPhone('9876543210'));
        $this->assertSame('', Guests::maskPhone(null));
        $this->assertSame('•••', Guests::maskPhone('123'));
    }

    public function testContentExtensionWelcomeLanguagesAndServices(): void
    {
        Settings::setMany(['guest_wifi_ssid' => 'Palace-Guest', 'guest_wifi_password' => 'welcome123', 'guest_welcome_duration' => '25']);
        $rid = self::$a['r103'];
        $sid = Guests::checkIn($rid, ['guest_name' => 'Rajesh Shah', 'salutation' => 'Mr.', 'language' => 'gu', 'wifi_password' => 'room103pw']);
        $c = self::content($rid);
        $this->assertSame('default', $c['mode']);
        $this->assertSame('શ્રી Shah', $c['guest']['name']);
        $this->assertSame('Rajesh', $c['guest']['first_name']);
        $this->assertSame('gu', $c['guest']['language']);
        $this->assertNotEmpty($c['guest']['checkin_at']);
        $this->assertTrue($c['welcome']['show']);
        $this->assertSame('stay-' . $sid, $c['welcome']['id']);
        $this->assertSame('સ્વાગત છે શ્રી Rajesh Shah 🙏', $c['welcome']['title']);
        $this->assertStringContainsString('Dwarka Palace', $c['welcome']['message']);
        $this->assertStringContainsString('103', $c['welcome']['message']);
        $this->assertSame(['ssid' => 'Palace-Guest', 'password' => 'room103pw'], $c['welcome']['wifi'], 'per-stay Wi-Fi override');
        $this->assertSame(25, $c['welcome']['duration_sec']);
        $token = Guests::roomToken($rid);
        $this->assertSame(['enabled' => true, 'url' => base_url('g/' . $token), 'label' => 'રૂમ સર્વિસ માટે સ્કેન કરો'], $c['services']);
        $ids = array_column($c['guest_menu'], 'id');
        $this->assertSame(['services', 'requests', 'feedback'], array_slice($ids, -3));
        foreach (array_slice($c['guest_menu'], -3) as $m) {
            $this->assertSame('qr', $m['type']);
            $this->assertStringStartsWith(base_url('g/' . $token), $m['url']);
        }
        $this->assertSame('રૂમ સર્વિસ', $c['guest_menu'][count($c['guest_menu']) - 3]['title']);
        $this->assertStringEndsWith('#feedback', $c['guest_menu'][count($c['guest_menu']) - 1]['url']);

        // Hindi + custom template
        Guests::update($sid, ['language' => 'hi']);
        Settings::set('guest_welcome_title_hi', 'नमस्ते {salutation} {first_name} — {hotel}');
        $c = self::content($rid);
        $this->assertSame('नमस्ते श्री Rajesh — Dwarka Palace', $c['welcome']['title']);
        $this->assertSame('रूम सर्विस के लिए स्कैन करें', $c['services']['label']);
        $this->assertSame('अनुरोध', $c['guest_menu'][count($c['guest_menu']) - 2]['title']);
        // English
        Guests::update($sid, ['language' => 'en']);
        $c = self::content($rid);
        $this->assertSame('Welcome Mr. Rajesh Shah 🙏', $c['welcome']['title']);
        $this->assertSame('Mr. Shah', $c['guest']['name']);

        // guest_menu is APPENDED to existing entries of other modules.
        $content = ['mode' => 'default', 'guest_menu' => [['id' => 'live_tv', 'type' => 'live_tv', 'title' => 'Live TV']]];
        (new GuestExtension())->apply($content, self::room($rid));
        $this->assertSame(['live_tv', 'services', 'requests', 'feedback'], array_column($content['guest_menu'], 'id'));

        // Features switched off in settings
        Settings::setMany(['guest_requests_enabled' => '0', 'guest_feedback_enabled' => '0']);
        $c = self::content($rid);
        $this->assertSame(['services'], array_column($c['guest_menu'], 'id'));
        Settings::setMany(['guest_requests_enabled' => '1', 'guest_feedback_enabled' => '1']);

        Guests::checkOut($sid);
        $c = self::content($rid);
        $this->assertArrayNotHasKey('guest', $c);
        $this->assertArrayNotHasKey('welcome', $c);
        $this->assertNotSame($token, Guests::roomToken($rid));
        $this->assertStringContainsString(Guests::roomToken($rid), $c['services']['url'], 'check-in mode off: vacant room keeps room service');
    }

    public function testVacantModes(): void
    {
        $rid = self::$a['r104'];
        Settings::setMany(['guest_checkin_mode' => '1', 'guest_vacant_mode' => 'off']);
        $c = self::content($rid);
        $this->assertSame('off', $c['mode']);
        $this->assertSame('vacant', $c['off_reason']);
        $this->assertFalse($c['screen_on']);
        $this->assertSame([], $c['items']);
        $this->assertArrayNotHasKey('services', $c, 'no guest link for a vacant room in check-in mode');

        Settings::set('guest_vacant_mode', 'welcome');
        $c = self::content($rid);
        $this->assertSame('empty', $c['mode']);
        $this->assertTrue($c['screen_on']);
        $this->assertSame([], $c['items']);

        Settings::set('guest_vacant_mode', 'normal');
        $this->assertSame('default', self::content($rid)['mode']);

        // Check-in wakes the TV
        Settings::set('guest_vacant_mode', 'off');
        $sid = Guests::checkIn($rid, ['guest_name' => 'Asha Patel', 'salutation' => 'Smt.', 'language' => 'en']);
        $c = self::content($rid);
        $this->assertSame('default', $c['mode']);
        $this->assertTrue($c['screen_on']);
        $this->assertArrayNotHasKey('off_reason', $c);
        $this->assertSame('Welcome Smt. Asha Patel 🙏', $c['welcome']['title']);
        Guests::checkOut($sid);

        // An emergency is never overridden by the vacant rule.
        $eid = Broadcaster::emergencyStart('Fire drill', 'Leave by the stairs', 'all', []);
        $c = self::content($rid);
        $this->assertSame('emergency', $c['mode']);
        $this->assertArrayNotHasKey('off_reason', $c);
        Broadcaster::emergencyStop($eid);

        // A room switched off by the admin keeps reason "admin".
        DB::update('rooms', ['is_enabled' => 0], 'id = :id', ['id' => $rid]);
        $c = self::content($rid);
        $this->assertSame('off', $c['mode']);
        $this->assertSame('admin', $c['off_reason']);
        DB::update('rooms', ['is_enabled' => 1], 'id = :id', ['id' => $rid]);

        // Suspended hotel: polite paused screen, never "vacant".
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        $c = self::content($rid);
        $this->assertSame('suspended', $c['mode']);
        $this->assertArrayNotHasKey('off_reason', $c);
        DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
        Tenant::forget();
        Settings::setMany(['guest_checkin_mode' => '0', 'guest_vacant_mode' => 'normal']);
    }

    public function testCheckoutReminderTiming(): void
    {
        $rid = self::$a['r105'];
        $day = date('Y-m-d', time() + 2 * 86400);
        $sid = Guests::checkIn($rid, ['guest_name' => 'Kiran Mehta', 'salutation' => 'Ms.', 'language' => 'en', 'checkout_at' => $day . 'T11:30']);
        Guests::update($sid, ['balance_text' => '₹ 4,500 due']);
        $stay = Guests::findStay($sid);
        $room = self::room($rid);
        Settings::set('guest_reminder_time', '07:00');
        $this->assertNull(Guests::reminderObject($stay, $room, 'en', strtotime($day . ' 06:59:00')), 'before reminder time');
        $this->assertNull(Guests::reminderObject($stay, $room, 'en', strtotime($day . ' 07:00:00') - 86400), 'day before');
        $r = Guests::reminderObject($stay, $room, 'en', strtotime($day . ' 07:00:00'));
        $this->assertSame(['show' => true, 'id' => 'co-' . $sid . '-' . $day, 'text' => 'Checkout today at 11:30 AM. Need a late checkout? Call reception or scan the QR code. Bill summary: ₹ 4,500 due'], $r);
        $r = Guests::reminderObject($stay, $room, 'gu', strtotime($day . ' 09:00:00'));
        $this->assertStringStartsWith('આજે ચેક-આઉટ 11:30 AM વાગ્યે છે', $r['text']);
        $this->assertStringContainsString('બિલ: ₹ 4,500 due', $r['text']);
        Settings::set('guest_reminder_balance', '0');
        $this->assertStringNotContainsString('4,500', Guests::reminderObject($stay, $room, 'en', strtotime($day . ' 09:00:00'))['text']);
        Settings::set('guest_reminder_enabled', '0');
        $this->assertNull(Guests::reminderObject($stay, $room, 'en', strtotime($day . ' 09:00:00')));
        Settings::setMany(['guest_reminder_enabled' => '1', 'guest_reminder_balance' => '1']);
        // In the content object: today → shown (time permitting); otherwise null.
        $this->assertNull(self::content($rid)['checkout_reminder']);
        Guests::update($sid, ['checkout_at' => date('Y-m-d') . ' 23:59']);
        Settings::set('guest_reminder_time', '00:00');
        $c = self::content($rid);
        $this->assertSame('co-' . $sid . '-' . date('Y-m-d'), $c['checkout_reminder']['id']);
        Guests::checkOut($sid);
        Settings::set('guest_reminder_time', '07:00');
    }

    public function testRoomMoveMovesStayTokenAndOpenOrders(): void
    {
        $from = self::$a['r102'];
        $to = self::$a['r105'];
        $sid = Guests::checkIn($from, ['guest_name' => 'Mover One']);
        $oldToken = Guests::roomToken($from);
        $oid = DB::insert('guest_orders', ['room_id' => $from, 'stay_id' => $sid, 'status' => 'new', 'total' => 100, 'created_at' => now()]);
        $done = DB::insert('guest_orders', ['room_id' => $from, 'stay_id' => $sid, 'status' => 'delivered', 'total' => 50, 'created_at' => now()]);
        Guests::move($sid, $to);
        $this->assertSame($to, (int) Guests::findStay($sid)['room_id']);
        $this->assertNull(Guests::activeStay($from));
        $this->assertNull(Guests::resolveToken($oldToken), 'old room token rotated');
        $this->assertSame($sid, (int) Guests::resolveToken(Guests::roomToken($to))['stay']['id']);
        $this->assertSame($to, (int) DB::value('SELECT room_id FROM guest_orders WHERE id = :id', ['id' => $oid]));
        $this->assertSame($from, (int) DB::value('SELECT room_id FROM guest_orders WHERE id = :id', ['id' => $done]), 'history stays');
        $this->assertSame(150.0, Guests::charges($sid));
        // Target occupied
        $other = Guests::checkIn($from, ['guest_name' => 'Second']);
        try {
            Guests::move($other, $to);
            $this->fail('moved into an occupied room');
        } catch (RuntimeException $e) {
            $this->assertSame('ROOM_OCCUPIED', $e->getMessage());
        }
        Guests::checkOut($sid);
        Guests::checkOut($other);
    }

    public function testRetentionTaskAnonymisesPii(): void
    {
        $sid = Guests::checkIn(self::$a['r104'], ['guest_name' => 'Old Guest', 'phone' => '9999999999', 'notes' => 'VIP', 'external_ref' => 'BK-OLD']);
        Guests::checkOut($sid);
        $recent = Guests::checkIn(self::$a['r104'], ['guest_name' => 'Recent Guest', 'phone' => '8888888888']);
        Guests::checkOut($recent);
        DB::query('UPDATE guest_stays SET checked_out_at = :d WHERE id = :id', ['d' => date('Y-m-d H:i:s', time() - 40 * 86400), 'id' => $sid]);
        DB::insert('guest_orders', ['room_id' => self::$a['r104'], 'stay_id' => $sid, 'status' => 'delivered', 'notes' => 'call Mr Old on 9999999999', 'total' => 10, 'created_at' => now()]);
        // Hotel B: an old stay that must not be touched by hotel A's setting.
        Tenant::run(self::$b['hotel'], function () {
            Settings::set('guest_retention_days', '365');
        });
        Tenant::run(self::$b['hotel'], fn () => DB::query('UPDATE guest_stays SET checked_out_at = :d WHERE id = :id', ['d' => date('Y-m-d H:i:s', time() - 40 * 86400), 'id' => self::$b['stay']]));
        Settings::set('guest_retention_days', '30');
        Settings::setPlatform('task_last_GuestRetentionTask', '0');
        $r = Scheduler::runTasks();
        $this->assertSame(['anonymised' => 1], $r['GuestRetentionTask']);
        $old = DB::one('SELECT * FROM guest_stays WHERE id = :id', ['id' => $sid]);
        $this->assertSame('', $old['guest_name']);
        $this->assertNull($old['phone']);
        $this->assertNull($old['notes']);
        $this->assertNull($old['external_ref']);
        $this->assertNotNull($old['pii_deleted_at']);
        $this->assertNull(DB::value('SELECT notes FROM guest_orders WHERE stay_id = :s', ['s' => $sid]));
        $this->assertSame('Recent Guest', DB::value('SELECT guest_name FROM guest_stays WHERE id = :id', ['id' => $recent]));
        $this->assertSame('Secret Guest B', DB::value('SELECT guest_name FROM guest_stays WHERE id = :id', ['id' => self::$b['stay']]));
        Tenant::run(self::$b['hotel'], fn () => DB::query('UPDATE guest_stays SET checked_out_at = NULL WHERE id = :id', ['id' => self::$b['stay']]));
        $this->assertSame(1, Tenant::current());
    }

    // ------------------------------------------------------------------ PMS API

    public function testPmsAuthValidationAndIdempotency(): void
    {
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '101', 'guest_name' => 'X']);
        $this->assertSame(401, $s);
        $this->assertSame('INVALID_API_KEY', $j['error']['code']);
        [$s] = self::pms('POST', 'checkin', ['room_number' => '101', 'guest_name' => 'X'], 'hcpms' . str_repeat('0', 40));
        $this->assertSame(401, $s);

        $key = Guests::rotatePmsKey();
        $this->assertSame(hash('sha256', $key), Settings::get('guest_pms_key_hash'), 'stored hashed only');
        $this->assertStringNotContainsString($key, (string) json_encode(DB::all("SELECT setting_value FROM system_settings WHERE setting_key LIKE 'guest_pms%'")));

        [$s, $j] = self::pms('GET', 'checkin', null, $key);
        $this->assertSame(405, $s);
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '101'], $key);
        $this->assertSame(400, $s);
        $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '999', 'guest_name' => 'Nobody'], $key);
        $this->assertSame(404, $s);
        $this->assertSame('ROOM_NOT_FOUND', $j['error']['code']);
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '101', 'guest_name' => ['x']], $key);
        $this->assertSame(400, $s);

        $payload = ['room_number' => '101', 'guest_name' => 'Amit Desai', 'salutation' => 'MR', 'language' => 'Gujarati', 'checkout_at' => date('Y-m-d', time() + 86400) . 'T11:00:00+05:30', 'external_ref' => 'BK-1001', 'balance' => '₹ 2,000'];
        [$s, $j] = self::pms('POST', 'checkin', $payload, $key);
        $this->assertSame(201, $s, (string) json_encode($j));
        $this->assertFalse($j['data']['idempotent']);
        $sid = $j['data']['stay_id'];
        $stay = Guests::findStay($sid);
        $this->assertSame('pms', $stay['source']);
        $this->assertSame('Mr.', $stay['salutation']);
        $this->assertSame('gu', $stay['language']);
        $this->assertSame('₹ 2,000', $stay['balance_text']);
        $this->assertSame(date('Y-m-d', time() + 86400) . ' 11:00:00', $stay['expected_checkout_at']);

        // Retry → same stay, no duplicate
        [$s, $j] = self::pms('POST', 'checkin', $payload, $key);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['idempotent']);
        $this->assertSame($sid, $j['data']['stay_id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM guest_stays WHERE hotel_id = 1 AND external_ref = 'BK-1001'"));

        // The TV gets a refresh command and, on the next poll, the welcome.
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tvUid, null, ['Authorization: Bearer ' . self::$tvToken, 'X-Device-Id: ' . self::$tvUid]);
        $this->assertSame(200, $s);
        $this->assertContains('SHOW_CONTENT', array_column($j['data']['commands'], 'command'));
        $this->assertSame('stay-' . $sid, $j['data']['content']['welcome']['id']);
        $this->assertSame('શ્રી Desai', $j['data']['content']['guest']['name']);

        // Update balance
        [$s, $j] = self::pms('POST', 'update', ['external_ref' => 'BK-1001', 'balance' => '₹ 2,500'], $key);
        $this->assertSame(200, $s);
        $this->assertSame('₹ 2,500', Guests::findStay($sid)['balance_text']);

        // A different booking for an occupied room closes the missed check-out.
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '101', 'guest_name' => 'Next Guest', 'external_ref' => 'BK-1002'], $key);
        $this->assertSame(201, $s);
        $this->assertSame($sid, $j['data']['replaced_stay_id']);
        $this->assertNotNull(Guests::findStay($sid)['checked_out_at']);
        $next = $j['data']['stay_id'];

        // Checkout by ref, twice (idempotent), unknown ref
        [$s, $j] = self::pms('POST', 'checkout', ['external_ref' => 'BK-1002'], $key);
        $this->assertSame(200, $s);
        $this->assertFalse($j['data']['idempotent']);
        $this->assertNotNull(Guests::findStay($next)['checked_out_at']);
        [$s, $j] = self::pms('POST', 'checkout', ['external_ref' => 'BK-1002'], $key);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['idempotent']);
        [$s, $j] = self::pms('POST', 'checkout', ['external_ref' => 'NOPE'], $key);
        $this->assertSame(404, $s);
        $this->assertSame('STAY_NOT_FOUND', $j['error']['code']);
        [$s, $j] = self::pms('POST', 'checkout', ['room_number' => '101'], $key);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['idempotent']);

        // Rotated key → the old one is refused
        $key2 = Guests::rotatePmsKey();
        [$s] = self::pms('GET', 'rooms', null, $key);
        $this->assertSame(401, $s);
        [$s] = self::pms('GET', 'rooms', null, $key2);
        $this->assertSame(200, $s);
        Guests::revokePmsKey();
        [$s] = self::pms('GET', 'rooms', null, $key2);
        $this->assertSame(401, $s);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPmsRoomMoveRoomsWebhookAndIsolation(): void
    {
        $key = Guests::rotatePmsKey();
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '102', 'guest_name' => 'Neha Joshi', 'external_ref' => 'BK-2001'], $key);
        $this->assertSame(201, $s);
        $sid = $j['data']['stay_id'];
        [$s, $j] = self::pms('POST', 'room-move', ['from_room' => '102', 'to_room' => '103'], $key);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertFalse($j['data']['idempotent']);
        $this->assertSame(self::$a['r103'], (int) Guests::findStay($sid)['room_id']);
        [$s, $j] = self::pms('POST', 'room-move', ['from_room' => '102', 'to_room' => '103'], $key);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['idempotent'], 'retry of a move');
        Guests::checkIn(self::$a['r104'], ['guest_name' => 'Blocker']);
        [$s, $j] = self::pms('POST', 'room-move', ['external_ref' => 'BK-2001', 'to_room' => '104'], $key);
        $this->assertSame(409, $s);
        $this->assertSame('ROOM_OCCUPIED', $j['error']['code']);

        [$s, $j] = self::pms('GET', 'rooms', null, $key);
        $this->assertSame(200, $s);
        $byNo = array_column($j['data']['rooms'], null, 'room_number');
        $this->assertTrue($byNo['103']['occupied']);
        $this->assertSame('BK-2001', $byNo['103']['stay']['external_ref']);
        $this->assertFalse($byNo['102']['occupied']);
        $this->assertArrayNotHasKey('B101', $byNo, 'only own hotel');
        $this->assertStringNotContainsString('Secret Guest B', json_encode($j));

        // Webhook with the eZee-style preset mapping; key in the query string.
        Settings::set('guest_pms_mapping', json_out(GuestPms::PRESETS['ezee']));
        $ez = ['EventType' => 'CheckIn', 'RoomNo' => '105', 'ReservationNo' => 'EZ-77', 'DepartureDate' => date('Y-m-d', time() + 86400),
            'Guest' => ['FirstName' => 'Meera', 'LastName' => 'Iyer', 'Salutation' => 'Mrs', 'Language' => 'hi', 'Mobile' => '9123456780']];
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/pms/webhook?key=' . $key, $ez);
        $this->assertSame(201, $s, (string) json_encode($j));
        $stay = Guests::findStay($j['data']['stay_id']);
        $this->assertSame('Meera Iyer', $stay['guest_name']);
        $this->assertSame('Mrs.', $stay['salutation']);
        $this->assertSame('hi', $stay['language']);
        $this->assertSame('9123456780', $stay['phone']);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/pms/webhook?key=' . $key, ['EventType' => 'RoomMove', 'ReservationNo' => 'EZ-77', 'NewRoomNo' => '102']);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertSame(self::$a['r102'], (int) Guests::findStay($stay['id'])['room_id']);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/pms/webhook?key=' . $key, ['EventType' => 'CheckOut', 'ReservationNo' => 'EZ-77']);
        $this->assertSame(200, $s);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/pms/webhook?key=' . $key, ['EventType' => 'Dance']);
        $this->assertSame(422, $s);
        $this->assertSame('UNKNOWN_EVENT', $j['error']['code']);
        [$s] = TestEnv::http('POST', self::$url . 'api/pms/checkin?key=' . $key, ['room_number' => '105', 'guest_name' => 'x']);
        $this->assertSame(401, $s, 'query-string key only for the webhook');
        // Mapping validation
        $this->assertNotNull(GuestPms::validateMapping('{"nope":"x"}')[1]);
        $this->assertNotNull(GuestPms::validateMapping('[1,2]')[1]);
        $this->assertNull(GuestPms::validateMapping('{"room_number":"a.b","guest_name":["x","y"]}')[1]);
        Settings::set('guest_pms_mapping', '');

        // Hotel B's key works only on hotel B: room "101" of B, never hotel A's.
        $keyB = Tenant::run(self::$b['hotel'], fn () => Guests::rotatePmsKey());
        [$s, $j] = self::pms('POST', 'checkout', ['external_ref' => 'BK-2001'], $keyB);
        $this->assertSame(404, $s, 'A booking invisible to B');
        $this->assertNull(Guests::findStay($sid)['checked_out_at']);
        [$s, $j] = self::pms('POST', 'checkin', ['room_number' => '101', 'guest_name' => 'B Guest', 'external_ref' => 'BK-B1'], $keyB);
        $this->assertSame(201, $s);
        $this->assertSame(self::$b['hotel'], (int) DB::value('SELECT hotel_id FROM guest_stays WHERE id = :id', ['id' => $j['data']['stay_id']]));
        $this->assertSame(self::$b['r2'], (int) DB::value('SELECT room_id FROM guest_stays WHERE id = :id', ['id' => $j['data']['stay_id']]));
        $this->assertNull(Guests::activeStay(self::$a['r101']), 'hotel A room 101 untouched');
        [$s, $j] = self::pms('POST', 'room-move', ['from_room' => '103', 'to_room' => '101'], $keyB);
        $this->assertSame(404, $s, 'B cannot move A guests');
        $this->assertSame(self::$a['r103'], (int) Guests::findStay($sid)['room_id']);

        // Feature flag: plan without "guests" → 403
        $plan = DB::insert('plans', ['name' => 'Guests test plan', 'price_per_tv_month' => 0, 'features' => json_encode(['services'])]);
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => $plan, 'h' => self::$b['hotel']]);
        Tenant::forget();
        [$s, $j] = self::pms('GET', 'rooms', null, $keyB);
        $this->assertSame(403, $s);
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);
        DB::query('UPDATE hotels SET plan_id = NULL WHERE id = :h', ['h' => self::$b['hotel']]);
        Tenant::forget();
        Guests::checkOut($sid);
        foreach (Guests::activeStays() as $st) {
            Guests::checkOut((int) $st['id']);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ admin pages

    public function testAdminPagesPermissionsPerRole(): void
    {
        $expect = [
            'gRecep' => ['guests.php' => 200, 'orders.php' => 200, 'services_setup.php' => 403, 'feedback.php' => 403],
            'gStaff' => ['guests.php' => 200, 'orders.php' => 200, 'services_setup.php' => 403, 'feedback.php' => 403],
            'gMgr' => ['guests.php' => 200, 'orders.php' => 200, 'services_setup.php' => 200, 'feedback.php' => 200],
            'gBoss' => ['guests.php' => 200, 'orders.php' => 200, 'services_setup.php' => 200, 'feedback.php' => 200],
        ];
        foreach ($expect as $user => $pages) {
            $s = new AdminSession(self::$url, $user);
            foreach ($pages as $page => $code) {
                [$c, , $html] = $s->get($page);
                $this->assertSame($code, $c, "$user → $page");
                $this->assertFalse(TestEnv::hasPhpError($html), "$user $page: PHP error");
                $this->assertStringNotContainsString('<h1>Something went wrong</h1>', $html, "$user $page: exception");
            }
        }
        // Reception lands on the front desk and sees only its menu items.
        $r = new AdminSession(self::$url, 'gRecep');
        [, , $html] = $r->get('index.php');
        $this->assertStringContainsString('guests.php', $html);
        $this->assertStringNotContainsString('services_setup.php', $html);
        [$c, , $html] = $r->get('services_setup.php?tab=pms');
        $this->assertSame(403, $c);
        foreach (['settings', 'pms', 'menu', 'requests'] as $tab) {
            [$c, , $html] = (new AdminSession(self::$url, 'gMgr'))->get('services_setup.php?tab=' . $tab);
            $this->assertSame(200, $c, $tab);
            $this->assertFalse(TestEnv::hasPhpError($html));
            $this->assertStringNotContainsString('<h1>Something went wrong</h1>', $html, $tab);
            $this->assertStringContainsString('</html>', $html, $tab . ' rendered completely');
        }
        [$c, , $html] = $r->get('guests.php?tab=history');
        $this->assertSame(200, $c);
        // Gujarati admin UI
        $r->ajax('set_language', ['lang' => 'gu']);
        [, , $html] = $r->get('guests.php');
        $this->assertStringContainsString('ફ્રન્ટ ડેસ્ક', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testFrontDeskFormsAndCrossHotelAttempts(): void
    {
        $s = new AdminSession(self::$url, 'gRecep');
        [$c] = $s->post('guests.php', ['op' => 'checkin', 'room_id' => self::$a['r101'], 'salutation' => 'Dr.', 'guest_name' => 'Form Guest', 'language' => 'hi', 'phone' => '9000000001', 'checkout_at' => date('Y-m-d', time() + 86400) . 'T10:00']);
        $this->assertSame(302, $c);
        $stay = Guests::activeStay(self::$a['r101']);
        $this->assertSame('Form Guest', $stay['guest_name']);
        $this->assertSame('hi', $stay['language']);
        $this->assertNull($stay['wifi_password'], 'reception cannot set Wi-Fi override');
        [, , $html] = $s->get('guests.php');
        $this->assertStringContainsString('Form Guest', $html);
        $this->assertStringNotContainsString('9000000001', $html, 'phone masked');
        $this->assertStringContainsString('90•••••001', $html);
        $this->assertStringNotContainsString('Secret Guest B', $html);

        // Without CSRF → refused
        [$c] = TestEnv::http('POST', self::$url . 'admin/guests.php', null, [], $s->jar, ['op' => 'checkout', 'stay_id' => (string) $stay['id']]);
        $this->assertSame(419, $c);
        $this->assertNull(Guests::findStay((int) $stay['id'])['checked_out_at']);

        // Hotel B's stay / rooms → 404, untouched
        [$c] = $s->post('guests.php', ['op' => 'checkout', 'stay_id' => self::$b['stay']]);
        $this->assertSame(404, $c);
        [$c] = $s->post('guests.php', ['op' => 'update', 'stay_id' => self::$b['stay'], 'guest_name' => 'Hacked']);
        $this->assertSame(404, $c);
        [$c] = $s->post('guests.php', ['op' => 'checkin', 'room_id' => self::$b['r2'], 'guest_name' => 'Intruder']);
        $this->assertSame(404, $c);
        [$c] = $s->post('guests.php', ['op' => 'move', 'stay_id' => $stay['id'], 'to_room_id' => self::$b['r2']]);
        $this->assertSame(404, $c);
        [$c] = $s->post('guests.php', ['op' => 'move', 'stay_id' => self::$b['stay'], 'to_room_id' => self::$a['r105']]);
        $this->assertSame(404, $c);
        $b = DB::one('SELECT * FROM guest_stays WHERE id = :id', ['id' => self::$b['stay']]);
        $this->assertSame('Secret Guest B', $b['guest_name']);
        $this->assertNull($b['checked_out_at']);
        $this->assertSame(self::$b['r1'], (int) $b['room_id']);
        $this->assertNull(DB::value('SELECT id FROM guest_stays WHERE room_id = :r AND hotel_id = 1', ['r' => self::$b['r2']]));

        // Edit, move, show welcome, checkout
        [$c] = $s->post('guests.php', ['op' => 'update', 'stay_id' => $stay['id'], 'salutation' => 'Dr.', 'guest_name' => 'Form Guest Two', 'language' => 'en', 'checkout_at' => '', 'notes' => 'late arrival', 'balance_text' => '']);
        $this->assertSame(302, $c);
        $this->assertSame('Form Guest Two', Guests::findStay((int) $stay['id'])['guest_name']);
        $this->assertSame('9000000001', Guests::findStay((int) $stay['id'])['phone'], 'empty phone field keeps the number');
        [$c] = $s->post('guests.php', ['op' => 'move', 'stay_id' => $stay['id'], 'to_room_id' => self::$a['r105']]);
        $this->assertSame(302, $c);
        $this->assertSame(self::$a['r105'], (int) Guests::findStay((int) $stay['id'])['room_id']);
        [$c] = $s->post('guests.php', ['op' => 'welcome', 'room_id' => self::$a['r101']]);
        $this->assertSame(302, $c);
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SHOW_WELCOME'"));
        [$c] = $s->post('guests.php', ['op' => 'checkout', 'stay_id' => $stay['id']]);
        $this->assertSame(302, $c);
        $this->assertNotNull(Guests::findStay((int) $stay['id'])['checked_out_at']);

        // Settings page: manager saves, reception cannot
        [$c] = $s->post('services_setup.php', ['op' => 'settings', 'guest_checkin_mode' => '1']);
        $this->assertSame(403, $c);
        $this->assertSame('0', Guests::setting('guest_checkin_mode'));
        $m = new AdminSession(self::$url, 'gMgr');
        [$c] = $m->post('services_setup.php', ['op' => 'settings', 'guest_checkin_mode' => '1', 'guest_vacant_mode' => 'welcome', 'guest_welcome_duration' => '30',
            'guest_checkout_time' => '11:00', 'guest_reminder_time' => '08:00', 'guest_retention_days' => '15', 'guest_wifi_ssid' => 'HC-Guest', 'guest_wifi_password' => 'pw',
            'guest_services_enabled' => '1', 'guest_requests_enabled' => '1', 'guest_feedback_enabled' => '1', 'guest_google_review_url' => 'https://g.page/r/x/review',
            'guest_welcome_title_gu' => 'પધારો {name}']);
        $this->assertSame(302, $c);
        Settings::flush();
        $this->assertSame('1', Guests::setting('guest_checkin_mode'));
        $this->assertSame('welcome', Guests::setting('guest_vacant_mode'));
        $this->assertSame('11:00', Guests::setting('guest_checkout_time'));
        $this->assertSame('15', Guests::setting('guest_retention_days'));
        $this->assertSame('પધારો {name}', Settings::get('guest_welcome_title_gu'));
        [$c] = $m->post('services_setup.php', ['op' => 'settings', 'guest_google_review_url' => 'javascript:alert(1)', 'guest_checkout_time' => '11:00', 'guest_reminder_time' => '08:00']);
        Settings::flush();
        $this->assertSame('https://g.page/r/x/review', Guests::setting('guest_google_review_url'), 'invalid URL rejected');
        // PMS key via the page: shown once
        [$c] = $m->post('services_setup.php', ['op' => 'pms_key', 'tab' => 'pms']);
        [, , $html] = $m->get('services_setup.php?tab=pms');
        $this->assertMatchesRegularExpression('/hcpms[a-f0-9]{40}/', $html);
        [, , $html] = $m->get('services_setup.php?tab=pms');
        $this->assertDoesNotMatchRegularExpression('/hcpms[a-f0-9]{40}/', $html, 'only once');
        Settings::flush();
        Settings::setMany(['guest_checkin_mode' => '0', 'guest_vacant_mode' => 'normal', 'guest_checkout_time' => '10:00']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testGuestsTablesAreTenantScoped(): void
    {
        foreach (['guest_stays', 'guest_tokens', 'guest_menu_categories', 'guest_menu_items', 'guest_orders', 'guest_order_items', 'guest_request_types', 'guest_requests', 'guest_feedback'] as $t) {
            $this->assertTrue(Tenant::isTenantTable($t), $t);
        }
        // Safety net: a scoped update / delete of hotel B's stay from hotel A changes nothing.
        $this->assertSame(0, DB::update('guest_stays', ['guest_name' => 'X'], 'id = :id', ['id' => self::$b['stay']]));
        $this->assertSame(0, DB::delete('guest_stays', 'id = :id', ['id' => self::$b['stay']]));
        $this->expectException(TenantException::class);
        Guests::findStay(self::$b['stay']);
    }
}
