<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guest services (V2_SPEC §2): guest web app + public JSON API (token auth, validation, rate limits,
 * invalid / expired tokens, no cross-room or cross-hotel data), room-service orders → staff board →
 * TV message, requests, feedback + report / CSV, menu setup, live alerts, feature flags, isolation.
 */
final class GuestServicesTest extends TestCase
{
    private static string $url;
    private static array $a = [];
    private static array $b = [];
    private static string $tv = '';
    private static string $tvUid = 'tv-services-0201';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'sBoss', 'manager' => 'sMgr', 'reception' => 'sRecep'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@a.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        Settings::setMany(['hotel_name' => 'Sea View', 'guest_wifi_ssid' => 'SeaView-Guest', 'guest_wifi_password' => 'sea12345',
            'guest_google_review_url' => 'https://g.page/r/seaview/review', 'guest_reception_phone' => '+91 2892 234567']);
        foreach (['201', '202', '203'] as $n) {
            self::$a['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'floor' => '2']);
        }
        self::$a['cat'] = DB::insert('guest_menu_categories', ['name_en' => 'Breakfast', 'name_gu' => 'નાસ્તો', 'name_hi' => 'नाश्ता', 'sort_order' => 1]);
        self::$a['tea'] = DB::insert('guest_menu_items', ['category_id' => self::$a['cat'], 'name_en' => 'Masala Tea', 'name_gu' => 'મસાલા ચા', 'price' => 40, 'food_type' => 'veg']);
        self::$a['poha'] = DB::insert('guest_menu_items', ['category_id' => self::$a['cat'], 'name_en' => 'Poha', 'price' => 80.5, 'food_type' => 'veg']);
        // Never available now: a one-minute window 12 hours away.
        $from = date('H:i:00', time() + 12 * 3600);
        $to = date('H:i:00', time() + 12 * 3600 + 60);
        self::$a['night'] = DB::insert('guest_menu_items', ['category_id' => self::$a['cat'], 'name_en' => 'Midnight Maggi', 'price' => 60, 'food_type' => 'veg', 'available_from' => $from, 'available_to' => $to]);
        self::$a['off'] = DB::insert('guest_menu_items', ['category_id' => self::$a['cat'], 'name_en' => 'Hidden Dish', 'price' => 10, 'is_active' => 0]);
        self::$a['stay1'] = Guests::checkIn(self::$a['r201'], ['guest_name' => 'Rajesh Shah', 'salutation' => 'Mr.', 'language' => 'gu']);
        self::$a['stay2'] = Guests::checkIn(self::$a['r202'], ['guest_name' => 'Other Room Guest', 'language' => 'en']);
        self::$a['tok1'] = Guests::roomToken(self::$a['r201']);
        self::$a['tok2'] = Guests::roomToken(self::$a['r202']);
        GuestServices::ensureRequestTypes();
        self::$a['wake'] = (int) DB::value("SELECT id FROM guest_request_types WHERE hotel_id = 1 AND code = 'wakeup'");
        self::$a['water'] = (int) DB::value("SELECT id FROM guest_request_types WHERE hotel_id = 1 AND code = 'water'");

        $hb = Hotels::create(['name' => 'Hotel B'], ['username' => 'sBossB', 'email' => 'sbossb@b.test', 'password' => 'Passw0rd!', 'full_name' => 'Boss B']);
        self::$b['hotel'] = $hb;
        Tenant::run($hb, static function (): void {
            self::$b['room'] = DB::insert('rooms', ['room_number' => '201']);
            self::$b['cat'] = DB::insert('guest_menu_categories', ['name_en' => 'B Menu']);
            self::$b['item'] = DB::insert('guest_menu_items', ['category_id' => self::$b['cat'], 'name_en' => 'B-SECRET-DISH', 'price' => 1]);
            self::$b['stay'] = Guests::checkIn(self::$b['room'], ['guest_name' => 'B Secret Guest']);
            self::$b['tok'] = Guests::roomToken(self::$b['room']);
            GuestServices::ensureRequestTypes();
            self::$b['type'] = (int) DB::value("SELECT id FROM guest_request_types WHERE hotel_id = :h AND code = 'water'", ['h' => Tenant::id()]);
            self::$b['order'] = DB::insert('guest_orders', ['room_id' => self::$b['room'], 'stay_id' => self::$b['stay'], 'status' => 'new', 'total' => 1, 'created_at' => now()]);
            DB::insert('guest_order_items', ['order_id' => self::$b['order'], 'name' => 'B-SECRET-DISH', 'price' => 1, 'qty' => 1, 'line_total' => 1]);
            self::$b['request'] = DB::insert('guest_requests', ['room_id' => self::$b['room'], 'type_name' => 'B-REQ', 'status' => 'open', 'created_at' => now()]);
            self::$b['feedback'] = DB::insert('guest_feedback', ['room_id' => self::$b['room'], 'rating' => 1, 'comment' => 'B-SECRET-COMMENT', 'created_at' => now()]);
        });
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tvUid, 'room_number' => '201', 'registration_key' => (string) Settings::get('registration_key')]);
        self::assertSame(200, $s);
        self::$tv = $j['data']['token'];
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        DB::query('DELETE FROM rate_limits'); // each test starts with fresh limits (testRateLimits checks them)
    }

    private static function api(string $method, string $token, string $action = '', ?array $json = null, array $headers = []): array
    {
        return TestEnv::http($method, self::$url . 'api/guest/' . $token . ($action !== '' ? '/' . $action : ''), $json, $headers);
    }

    // ------------------------------------------------------------------ guest app page + bootstrap

    public function testGuestPageAndExpiredLink(): void
    {
        [$s, , $html, $head] = TestEnv::http('GET', self::$url . 'g/' . self::$a['tok1']);
        $this->assertSame(200, $s, $html . TestEnv::phpErrors());
        $this->assertStringContainsString('Sea View', $html);
        $this->assertStringContainsString('id="gConfig"', $html);
        $this->assertStringContainsString('<html lang="gu">', $html, 'guest language by default');
        $this->assertStringContainsString('મસાલા ચા', $html);
        $this->assertStringNotContainsString('B-SECRET', $html);
        $this->assertStringNotContainsString('Other Room Guest', $html);
        $this->assertStringNotContainsString('Hidden Dish', $html);
        $this->assertMatchesRegularExpression('/Referrer-Policy: no-referrer/i', $head);
        $this->assertMatchesRegularExpression("/Content-Security-Policy: default-src 'self'/i", $head);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('</html>', $html);
        // Fallback URL forms
        [$s, , $html] = TestEnv::http('GET', self::$url . 'g/?t=' . self::$a['tok1']);
        $this->assertSame(200, $s);
        [$s, , $html] = TestEnv::http('GET', self::$url . 'g/index.php/' . self::$a['tok1']);
        $this->assertSame(200, $s);
        // Unknown token → friendly page in all languages
        [$s, , $html] = TestEnv::http('GET', self::$url . 'g/' . str_repeat('Z', 20));
        $this->assertSame(404, $s);
        $this->assertStringContainsString('This link is not valid any more', $html);
        $this->assertStringContainsString('આ લિંક હવે માન્ય નથી', $html);
        $this->assertStringContainsString('यह लिंक अब मान्य नहीं है', $html);
        [$s] = TestEnv::http('GET', self::$url . 'g/');
        $this->assertSame(404, $s);
        // Theme stylesheet only accepts a hex colour
        [$s, , $css] = TestEnv::http('GET', self::$url . 'g/theme.php?c=' . rawurlencode('}</style><script>'));
        $this->assertSame(200, $s);
        $this->assertStringContainsString('--brand:#7B1FA2', $css);
    }

    public function testBootstrapApiOnlyOwnRoomData(): void
    {
        [$s, $j] = self::api('GET', self::$a['tok1']);
        $this->assertSame(200, $s);
        $d = $j['data'];
        $this->assertSame('201', $d['room']['number']);
        $this->assertSame('શ્રી Shah', $d['guest']['name']['gu']);
        $this->assertSame('gu', $d['language']);
        $this->assertSame(['ssid' => 'SeaView-Guest', 'password' => 'sea12345'], $d['wifi']);
        $this->assertSame('+91 2892 234567', $d['reception_phone']);
        $names = array_map(fn ($i) => $i['name']['en'], $d['menu'][0]['items']);
        $this->assertSame(['Masala Tea', 'Poha', 'Midnight Maggi'], $names);
        $this->assertFalse($d['menu'][0]['items'][2]['available']);
        $this->assertSame('મસાલા ચા', $d['menu'][0]['items'][0]['name']['gu']);
        $this->assertSame('Masala Tea', $d['menu'][0]['items'][0]['name']['hi'], 'fallback to English');
        $this->assertCount(8, $d['request_types']);
        $this->assertSame('પીવાનું પાણી', $d['request_types'][0]['name']['gu']);
        $this->assertSame('पीने का पानी', $d['request_types'][0]['name']['hi']);
        $raw = json_encode($j, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('B-SECRET', $raw);
        $this->assertStringNotContainsString('Other Room Guest', $raw);
        $this->assertStringNotContainsString('phone', strtolower(json_encode(array_keys($d['guest']))), 'no PII beyond the display name');
        // Bad formats / unknown
        foreach (['short', str_repeat('a', 41), 'abc$defghijklmnopqrst'] as $bad) {
            [$s, $j] = self::api('GET', rawurlencode($bad));
            $this->assertSame(404, $s, $bad);
        }
        [$s, $j] = self::api('GET', str_repeat('Q', 20));
        $this->assertSame('INVALID_TOKEN', $j['error']['code']);
        [$s] = self::api('DELETE', self::$a['tok1'], 'order');
        $this->assertSame(405, $s);
        [$s] = self::api('GET', self::$a['tok1'], 'nope');
        $this->assertSame(404, $s);
    }

    // ------------------------------------------------------------------ orders

    public function testOrderFlowValidationStaffBoardAndTvMessage(): void
    {
        $tok = self::$a['tok1'];
        // Validation
        $bad = [
            [],
            ['items' => []],
            ['items' => [['id' => self::$a['tea'], 'qty' => 0]]],
            ['items' => [['id' => self::$a['tea'], 'qty' => 21]]],
            ['items' => [['id' => (string) self::$a['tea'], 'qty' => 1]]],
            ['items' => [['id' => self::$a['tea'], 'qty' => 1.5]]],
            ['items' => array_fill(0, 31, ['id' => self::$a['tea'], 'qty' => 1])],
            ['items' => [['id' => self::$a['tea'], 'qty' => 1]], 'notes' => str_repeat('x', 301)],
            ['items' => [['id' => self::$a['tea'], 'qty' => 1]], 'notes' => ['x']],
        ];
        foreach ($bad as $i => $body) {
            [$s, $j] = self::api('POST', $tok, 'order', $body);
            $this->assertSame(400, $s, 'case ' . $i . ' ' . json_encode($j));
            $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
        }
        [$s, $j] = self::api('POST', $tok, 'order', ['items' => [['id' => self::$b['item'], 'qty' => 1]]]);
        $this->assertSame(409, $s, 'other hotel item');
        $this->assertSame('ITEM_UNAVAILABLE', $j['error']['code']);
        [$s] = self::api('POST', $tok, 'order', ['items' => [['id' => self::$a['off'], 'qty' => 1]]]);
        $this->assertSame(409, $s, 'inactive item');
        [$s, $j] = self::api('POST', $tok, 'order', ['items' => [['id' => self::$a['night'], 'qty' => 1]]]);
        $this->assertSame(409, $s, 'outside hours');
        $this->assertStringContainsString('Midnight Maggi', $j['error']['message']);
        // Not JSON
        [$s] = TestEnv::http('POST', self::$url . 'api/guest/' . $tok . '/order', null, [], null, ['items' => 'x']);
        $this->assertSame(415, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM guest_orders WHERE hotel_id = 1'));

        // Valid order — client prices are ignored, duplicates merged
        [$s, $j] = self::api('POST', $tok, 'order', ['items' => [['id' => self::$a['tea'], 'qty' => 2, 'price' => 0], ['id' => self::$a['poha'], 'qty' => 1], ['id' => self::$a['tea'], 'qty' => 1]], 'notes' => 'Less sugar <b>please</b>']);
        $this->assertSame(201, $s, (string) json_encode($j));
        $o = $j['data'];
        $this->assertSame('new', $o['status']);
        $this->assertSame(200.5, (float) $o['total']);
        $this->assertSame(3, $o['items'][0]['qty']);
        $row = DB::one('SELECT * FROM guest_orders WHERE id = :id', ['id' => $o['id']]);
        $this->assertSame(1, (int) $row['hotel_id']);
        $this->assertSame(self::$a['r201'], (int) $row['room_id']);
        $this->assertSame(self::$a['stay1'], (int) $row['stay_id']);
        $this->assertSame(4, (int) $row['item_count']);

        // Own status shows it, another room's token does not
        [, $j] = self::api('GET', $tok, 'status');
        $this->assertSame([$o['id']], array_column($j['data']['orders'], 'id'));
        [, $j] = self::api('GET', self::$a['tok2'], 'status');
        $this->assertSame([], $j['data']['orders']);
        [, $j] = self::api('GET', self::$b['tok'], 'status');
        $this->assertSame([], $j['data']['orders']);

        // Staff board (reception) + alerts
        $r = new AdminSession(self::$url, 'sRecep');
        [$s, $j] = $r->ajax('guests_alerts');
        $this->assertSame(200, $s);
        $this->assertSame(1, $j['data']['new_orders']);
        $this->assertSame($o['id'], $j['data']['orders'][0]['id']);
        [, $j] = $r->ajax('guests_alerts&order=' . $o['id'] . '&request=999999');
        $this->assertSame([], $j['data']['orders'], 'nothing newer');
        [$s, $j] = $r->ajax('guests_board');
        $this->assertSame([$o['id']], array_column($j['data']['orders'], 'id'));
        $this->assertSame('Less sugar <b>please</b>', $j['data']['orders'][0]['notes'], 'raw text, escaped by the board JS');
        $this->assertStringNotContainsString('B-SECRET', json_encode($j));

        [$s, $j] = $r->ajax('guests_order_status', ['id' => $o['id'], 'status' => 'accepted', 'notify_tv' => true]);
        $this->assertSame(200, $s, (string) json_encode($j));
        [$s, $j] = $r->ajax('guests_order_status', ['id' => $o['id'], 'status' => 'new']);
        $this->assertSame(422, $s, 'no way back');
        [$s] = $r->ajax('guests_order_status', ['id' => $o['id'], 'status' => 'bogus']);
        $this->assertSame(422, $s);
        [$s] = $r->ajax('guests_order_status', ['id' => self::$b['order'], 'status' => 'cancelled']);
        $this->assertSame(404, $s, 'hotel B order');
        $this->assertSame('new', DB::value('SELECT status FROM guest_orders WHERE id = :id', ['id' => self::$b['order']]));
        [$s] = $r->ajax('guests_order_status', ['id' => $o['id'], 'status' => 'delivered', 'notify_tv' => true]);
        $this->assertSame(200, $s);
        [, $j] = self::api('GET', $tok, 'status');
        $this->assertSame('delivered', $j['data']['orders'][0]['status']);
        $this->assertNotNull(DB::value('SELECT delivered_at FROM guest_orders WHERE id = :id', ['id' => $o['id']]));

        // The TV receives SHOW_MESSAGE in the guest's language (gu).
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tvUid, null, ['Authorization: Bearer ' . self::$tv, 'X-Device-Id: ' . self::$tvUid]);
        $msgs = array_values(array_filter($j['data']['commands'], fn ($c) => $c['command'] === 'SHOW_MESSAGE'));
        $this->assertCount(2, $msgs);
        $this->assertSame('રૂમ સર્વિસ', $msgs[1]['payload']['title']);
        $this->assertSame('આપનો ઓર્ડર આવી રહ્યો છે 🛎️', $msgs[1]['payload']['message']);
        $this->assertSame(15, $msgs[1]['payload']['duration_sec']);
        // notify_tv false → no message
        [, $j2] = self::api('POST', $tok, 'order', ['items' => [['id' => self::$a['tea'], 'qty' => 1]]]);
        $before = (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SHOW_MESSAGE'");
        $r->ajax('guests_order_status', ['id' => $j2['data']['id'], 'status' => 'cancelled', 'notify_tv' => false]);
        $this->assertSame($before, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SHOW_MESSAGE'"));

        // KOT
        [$s, , $html] = $r->get('orders.php?kot=' . $o['id'] . '&noprint=1');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Masala Tea', $html);
        $this->assertStringContainsString('Less sugar &lt;b&gt;please&lt;/b&gt;', $html);
        $this->assertStringContainsString('₹200.50', $html);
        [$s, , $html] = $r->get('orders.php?kot=' . self::$b['order']);
        $this->assertSame(404, $s);
        $this->assertStringNotContainsString('B-SECRET', $html);
        [$s, , $html] = $r->get('orders.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('guests-alerts.js', $html, 'live alerts on every page');
        // Charges on the stay
        $this->assertSame(200.5, Guests::charges(self::$a['stay1']));
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ requests

    public function testRequestsWakeUpDuplicatesAndStaff(): void
    {
        $tok = self::$a['tok1'];
        [$s, $j] = self::api('POST', $tok, 'request', ['type_id' => self::$a['wake']]);
        $this->assertSame(400, $s, 'wake-up needs a time');
        [$s] = self::api('POST', $tok, 'request', ['type_id' => self::$a['wake'], 'time' => '25:00']);
        $this->assertSame(400, $s);
        [$s] = self::api('POST', $tok, 'request', ['type_id' => self::$b['type']]);
        $this->assertSame(400, $s, 'hotel B request type');
        [$s] = self::api('POST', $tok, 'request', ['type_id' => 'x']);
        $this->assertSame(400, $s);
        [$s, $j] = self::api('POST', $tok, 'request', ['type_id' => self::$a['wake'], 'time' => '06:30', 'notes' => 'two calls please']);
        $this->assertSame(201, $s, (string) json_encode($j));
        $this->assertSame('open', $j['data']['status']);
        $this->assertStringContainsString('T06:30:00', $j['data']['time']);
        $this->assertGreaterThan(time(), strtotime($j['data']['time']), 'next 06:30');
        $id = $j['data']['id'];
        [$s, $j] = self::api('POST', $tok, 'request', ['type_id' => self::$a['wake'], 'time' => '06:30']);
        $this->assertSame($id, $j['data']['id'], 'double tap → same request');
        $this->assertTrue($j['data']['duplicate']);
        [$s, $j] = self::api('POST', $tok, 'request', ['type_id' => self::$a['water']]);
        $this->assertSame(201, $s);
        $water = $j['data']['id'];
        [, $j] = self::api('GET', self::$a['tok2'], 'status');
        $this->assertSame([], $j['data']['requests'], 'other room sees nothing');

        $r = new AdminSession(self::$url, 'sRecep');
        [, $j] = $r->ajax('guests_board');
        $this->assertEqualsCanonicalizing([$id, $water], array_column($j['data']['requests'], 'id'));
        $this->assertStringNotContainsString('B-REQ', json_encode($j));
        [$s] = $r->ajax('guests_request_status', ['id' => $water, 'status' => 'done', 'notify_tv' => true]);
        $this->assertSame(200, $s);
        [$s] = $r->ajax('guests_request_status', ['id' => self::$b['request'], 'status' => 'done']);
        $this->assertSame(404, $s);
        $this->assertSame('open', DB::value('SELECT status FROM guest_requests WHERE id = :id', ['id' => self::$b['request']]));
        [, $j] = self::api('GET', $tok, 'status');
        $byId = array_column($j['data']['requests'], 'status', 'id');
        $this->assertSame('done', $byId[$water]);
        $this->assertSame('open', $byId[$id]);
        $msg = DB::value("SELECT payload FROM device_commands WHERE command = 'SHOW_MESSAGE' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('પીવાનું પાણી', (string) $msg);
    }

    // ------------------------------------------------------------------ feedback

    public function testFeedbackReviewLinkReportAndCsv(): void
    {
        [$s] = self::api('POST', self::$a['tok1'], 'feedback', ['rating' => 6]);
        $this->assertSame(400, $s);
        [$s] = self::api('POST', self::$a['tok1'], 'feedback', ['rating' => 4, 'food' => 9]);
        $this->assertSame(400, $s);
        [$s, $j] = self::api('POST', self::$a['tok1'], 'feedback', ['rating' => 3, 'cleanliness' => 3, 'comment' => 'ok']);
        $this->assertSame(201, $s);
        $this->assertNull($j['data']['google_review_url']);
        [$s, $j] = self::api('POST', self::$a['tok1'], 'feedback', ['rating' => 5, 'cleanliness' => 5, 'staff' => 4, 'food' => 5, 'comment' => '=HYPERLINK("x") Superb poha']);
        $this->assertSame(201, $s);
        $this->assertSame('https://g.page/r/seaview/review', $j['data']['google_review_url']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM guest_feedback WHERE hotel_id = 1 AND stay_id = :s', ['s' => self::$a['stay1']]), 'one per stay, updated');
        [$s, $j] = self::api('POST', self::$a['tok2'], 'feedback', ['rating' => 2, 'comment' => 'AC noisy']);
        $this->assertSame(201, $s);
        [, $j] = self::api('GET', self::$a['tok1'], 'status');
        $this->assertSame(5, $j['data']['feedback']['rating']);

        $stats = GuestServices::feedbackStats(date('Y-m-d'), date('Y-m-d'));
        $this->assertSame(2, $stats['count']);
        $this->assertSame(3.5, $stats['average']);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $stats['distribution']);

        $m = new AdminSession(self::$url, 'sMgr');
        [$s, , $html] = $m->get('feedback.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('3.5', $html);
        $this->assertStringContainsString('AC noisy', $html);
        $this->assertStringNotContainsString('B-SECRET-COMMENT', $html);
        [$s, , $csv, $head] = $m->get('feedback.php?export=csv');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('text/csv', $head);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'CSV injection neutralised');
        $this->assertStringContainsString('AC noisy', $csv);
        $this->assertStringNotContainsString('B-SECRET-COMMENT', $csv);
        [$s] = (new AdminSession(self::$url, 'sRecep'))->get('feedback.php');
        $this->assertSame(403, $s);
    }

    // ------------------------------------------------------------------ menu setup

    public function testMenuSetupCrudAndIsolation(): void
    {
        $m = new AdminSession(self::$url, 'sMgr');
        [$s] = $m->post('services_setup.php', ['op' => 'cat_save', 'tab' => 'menu', 'name_en' => 'Beverages', 'name_gu' => 'પીણાં', 'name_hi' => 'पेय', 'sort_order' => 5, 'is_active' => 1]);
        $this->assertSame(302, $s);
        $cat = (int) DB::value("SELECT id FROM guest_menu_categories WHERE hotel_id = 1 AND name_en = 'Beverages'");
        $this->assertGreaterThan(0, $cat);
        // Item with a photo upload
        $png = tempnam(sys_get_temp_dir(), 'png') . '.png';
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 100, 0));
        imagepng($im, $png);
        $fields = ['_csrf' => $m->csrf, 'op' => 'item_save', 'tab' => 'menu', 'category_id' => (string) $cat, 'name_en' => 'Cold Coffee', 'name_hi' => 'कोल्ड कॉफ़ी',
            'price' => '120', 'food_type' => 'veg', 'available_from' => '', 'available_to' => '', 'is_active' => '1', 'photo' => new CURLFile($png, 'image/png', 'coffee.png')];
        [$s] = TestEnv::http('POST', self::$url . 'admin/services_setup.php', null, [], $m->jar, $fields);
        $this->assertSame(302, $s);
        $item = DB::one("SELECT * FROM guest_menu_items WHERE hotel_id = 1 AND name_en = 'Cold Coffee'");
        $this->assertNotNull($item);
        $this->assertStringStartsWith('h1/media/', (string) $item['photo_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $item['photo_path']);
        @unlink($png);
        [, $j] = self::api('GET', self::$a['tok1']);
        $bev = array_values(array_filter($j['data']['menu'], fn ($c) => $c['id'] === $cat))[0];
        $this->assertSame('कोल्ड कॉफ़ी', $bev['items'][0]['name']['hi']);
        $this->assertStringContainsString('/uploads/h1/media/', $bev['items'][0]['photo_url']);
        // Validation: price, half time window
        [$s] = $m->post('services_setup.php', ['op' => 'item_save', 'tab' => 'menu', 'category_id' => $cat, 'name_en' => 'Bad', 'price' => '-5', 'is_active' => 1]);
        [$s] = $m->post('services_setup.php', ['op' => 'item_save', 'tab' => 'menu', 'category_id' => $cat, 'name_en' => 'Bad2', 'price' => '5', 'available_from' => '10:00', 'is_active' => 1]);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM guest_menu_items WHERE name_en IN ('Bad','Bad2')"));
        // Toggle + pages render
        $m->post('services_setup.php', ['op' => 'item_toggle', 'tab' => 'menu', 'id' => $item['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM guest_menu_items WHERE id = :id', ['id' => $item['id']]));
        [$s, , $html] = $m->get('services_setup.php?tab=menu&edit_item=' . $item['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Cold Coffee', $html);
        $this->assertStringNotContainsString('B-SECRET-DISH', $html);
        // Cross-hotel: edit / delete / category of hotel B → 404 and unchanged
        [$s] = $m->get('services_setup.php?tab=menu&edit_item=' . self::$b['item']);
        $this->assertSame(404, $s);
        [$s] = $m->post('services_setup.php', ['op' => 'item_save', 'tab' => 'menu', 'id' => self::$b['item'], 'category_id' => $cat, 'name_en' => 'Pwned', 'price' => 1, 'is_active' => 1]);
        $this->assertSame(404, $s);
        [$s] = $m->post('services_setup.php', ['op' => 'item_save', 'tab' => 'menu', 'category_id' => self::$b['cat'], 'name_en' => 'Into B', 'price' => 1, 'is_active' => 1]);
        $this->assertSame(404, $s);
        [$s] = $m->post('services_setup.php', ['op' => 'item_delete', 'tab' => 'menu', 'id' => self::$b['item']]);
        $this->assertSame(404, $s);
        [$s] = $m->post('services_setup.php', ['op' => 'cat_delete', 'tab' => 'menu', 'id' => self::$b['cat']]);
        $this->assertSame(404, $s);
        [$s] = $m->post('services_setup.php', ['op' => 'type_delete', 'tab' => 'requests', 'id' => self::$b['type']]);
        $this->assertSame(404, $s);
        $this->assertSame('B-SECRET-DISH', DB::value('SELECT name_en FROM guest_menu_items WHERE id = :id', ['id' => self::$b['item']]));
        $this->assertNotNull(DB::value('SELECT id FROM guest_request_types WHERE id = :id', ['id' => self::$b['type']]));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM guest_menu_items WHERE name_en = 'Into B'"));
        // Request type CRUD
        [$s] = $m->post('services_setup.php', ['op' => 'type_save', 'tab' => 'requests', 'name_en' => 'Iron & board', 'name_gu' => 'ઇસ્ત્રી', 'icon' => '👔', 'sort_order' => 90, 'is_active' => 1]);
        $this->assertSame(302, $s);
        $this->assertNotNull(DB::value("SELECT id FROM guest_request_types WHERE hotel_id = 1 AND name_en = 'Iron & board'"));
        // Delete category with items (photo removed from disk)
        $m->post('services_setup.php', ['op' => 'cat_delete', 'tab' => 'menu', 'id' => $cat]);
        $this->assertNull(DB::value('SELECT id FROM guest_menu_items WHERE id = :id', ['id' => $item['id']]));
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $item['photo_path']);
        // Reception may not edit the menu
        [$s] = (new AdminSession(self::$url, 'sRecep'))->post('services_setup.php', ['op' => 'cat_save', 'tab' => 'menu', 'name_en' => 'Recep cat']);
        $this->assertSame(403, $s);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ tokens, plans, suspension

    public function testCheckoutFeatureFlagAndSuspensionInvalidateLinks(): void
    {
        $tok = Guests::roomToken(self::$a['r203']);
        // Check-in mode off: vacant room link works
        [$s] = self::api('GET', $tok);
        $this->assertSame(200, $s);
        Settings::set('guest_checkin_mode', '1');
        [$s] = self::api('GET', $tok);
        $this->assertSame(404, $s, 'vacant room in check-in mode');
        Settings::set('guest_checkin_mode', '0');

        // Hotel B without the services module → its links are dead, admin pages refused.
        $plan = DB::insert('plans', ['name' => 'Guests only', 'price_per_tv_month' => 0, 'features' => json_encode(['guests'])]);
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => $plan, 'h' => self::$b['hotel']]);
        Tenant::forget();
        [$s] = self::api('GET', self::$b['tok']);
        $this->assertSame(404, $s);
        $bb = new AdminSession(self::$url, 'sBossB');
        [$s] = $bb->get('orders.php');
        $this->assertSame(403, $s);
        [$s] = $bb->ajax('guests_board');
        $this->assertSame(403, $s);
        [$s, , $html] = $bb->get('index.php');
        $this->assertStringNotContainsString('orders.php', $html, 'menu item hidden');
        $this->assertStringContainsString('guests.php', $html);
        $c = Tenant::run(self::$b['hotel'], fn () => ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$b['room']])));
        $this->assertArrayNotHasKey('services', $c);
        $this->assertArrayHasKey('welcome', $c);
        DB::query('UPDATE hotels SET plan_id = NULL WHERE id = :h', ['h' => self::$b['hotel']]);
        Tenant::forget();

        // Suspended hotel → links dead
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = :h", ['h' => self::$b['hotel']]);
        [$s] = self::api('GET', self::$b['tok']);
        $this->assertSame(404, $s);
        DB::query("UPDATE hotels SET status = 'active' WHERE id = :h", ['h' => self::$b['hotel']]);
        [$s] = self::api('GET', self::$b['tok']);
        $this->assertSame(200, $s);

        // Check-out kills the guest's link immediately (page and API).
        $old = self::$a['tok2'];
        Guests::checkOut(self::$a['stay2']);
        [$s, $j] = self::api('GET', $old, 'status');
        $this->assertSame(404, $s);
        $this->assertSame('INVALID_TOKEN', $j['error']['code']);
        [$s] = self::api('POST', $old, 'order', ['items' => [['id' => self::$a['tea'], 'qty' => 1]]]);
        $this->assertSame(404, $s);
        [$s] = TestEnv::http('GET', self::$url . 'g/' . $old);
        $this->assertSame(404, $s);
        // New guest in the same room never sees the previous guest's orders / feedback.
        $sid = Guests::checkIn(self::$a['r202'], ['guest_name' => 'Next Guest']);
        [, $j] = self::api('GET', Guests::roomToken(self::$a['r202']), 'status');
        $this->assertSame([], $j['data']['orders']);
        $this->assertNull($j['data']['feedback']);
        Guests::checkOut($sid);
    }

    public function testRateLimits(): void
    {
        DB::query('DELETE FROM rate_limits');
        $sid = Guests::checkIn(self::$a['r203'], ['guest_name' => 'Busy Guest']);
        $tok = Guests::roomToken(self::$a['r203']);
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            [$codes[]] = self::api('POST', $tok, 'feedback', ['rating' => 5]);
        }
        $this->assertSame(array_fill(0, 30, 201), $codes);
        [$s, $j, , $head] = self::api('POST', $tok, 'feedback', ['rating' => 5]);
        $this->assertSame(429, $s);
        $this->assertSame('RATE_LIMITED', $j['error']['code']);
        $this->assertMatchesRegularExpression('/Retry-After: \d+/i', $head);
        [$s] = self::api('GET', $tok, 'status');
        $this->assertSame(200, $s, 'reads still allowed');
        // Token guessing from one IP
        $codes = [];
        for ($i = 0; $i < 31; $i++) {
            [$codes[]] = self::api('GET', 'Guess' . str_pad((string) $i, 15, 'x'));
        }
        $this->assertSame(404, $codes[0]);
        $this->assertSame(429, $codes[30]);
        DB::query('DELETE FROM rate_limits');
        Guests::checkOut($sid);
        $this->assertSame('', TestEnv::phpErrors());
    }
}
