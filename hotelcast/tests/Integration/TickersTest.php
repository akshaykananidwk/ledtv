<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ticker bar (2.2): resolution rules (all / group / room, override, priority, daily windows incl.
 * overnight, days, date range), the legacy `ticker_text` fallback and its upgrade migration, the TV
 * contract (content.overlay.ticker) and hash changes, the admin page (CRUD, validation, XSS, CSRF),
 * cross-hotel isolation and per-user TV access (Access).
 */
final class TickersTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const KEYS = ['text', 'messages', 'speed', 'bg_color', 'text_color', 'font_size', 'height', 'position', 'reserve_space'];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'tkMgr', 'staff' => 'tkStaff', 'reception' => 'tkRecep'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101' => '1', '102' => '1', '201' => '2'] as $n => $floor) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => $floor]);
        }
        self::$id['g1'] = DB::insert('room_groups', ['name' => 'Floor 1', 'type' => 'floor', 'created_at' => now()]);
        self::$id['g2'] = DB::insert('room_groups', ['name' => 'Floor 2', 'type' => 'floor', 'created_at' => now()]);
        foreach ([['r101', 'g1'], ['r102', 'g1'], ['r201', 'g2']] as [$r, $g]) {
            DB::insert('room_group_members', ['room_id' => self::$id[$r], 'group_id' => self::$id[$g]]);
        }
        $c = DB::insert('content_items', ['title' => 'Main', 'type' => 'announcement', 'body' => 'Hello', 'duration' => 10]);
        DB::update('rooms', ['content_id' => $c], 'id = :id', ['id' => self::$id['r101']]);

        Hotels::create(['name' => 'Hotel Two'], ['username' => 'tkBoss2', 'email' => 'b2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['b101'] = DB::insert('rooms', ['room_number' => 'B101', 'name' => 'B room']);
            self::$id['bg'] = DB::insert('room_groups', ['name' => 'B group', 'type' => 'custom', 'created_at' => now()]);
            self::$id['bt'] = DB::insert('tickers', ['name' => 'B-TICKER', 'message' => 'B-TICKER-SECRET', 'target_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        });
        Tenant::set(1);
        ContentResolver::resetExtensions();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        Tenant::forget();
        Settings::flush();
        ContentResolver::resetExtensions();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Access::$userOverride = null;
        Access::forget();
        Settings::flush();
    }

    private static function clearTickers(): void
    {
        DB::query('DELETE FROM tickers WHERE hotel_id = 1');
        Settings::set('ticker_text', '');
        Settings::bumpContentVersion();
    }

    private static function add(string $msg, string $type = 'all', ?string $target = null, array $extra = []): int
    {
        return DB::insert('tickers', $extra + [
            'name' => $msg, 'message' => $msg, 'target_type' => $type, 'target_id' => $target ? self::$id[$target] : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private static function room(string $n): array
    {
        return DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['r' . $n]]);
    }

    private static function msgs(string $room, ?int $ts = null): ?array
    {
        $t = Tickers::forRoom(self::room($room), $ts);
        return $t === null ? null : $t['messages'];
    }

    // ------------------------------------------------------------------ resolution

    public function testResolutionAllGroupRoomPriorityAndOverride(): void
    {
        self::clearTickers();
        $this->assertNull(Tickers::forRoom(self::room('101')));

        self::add('ALL-A', 'all', null, ['bg_color' => '#111111', 'speed' => 3]);
        $t = Tickers::forRoom(self::room('101'));
        $this->assertSame(['ALL-A'], $t['messages']);
        $this->assertSame('ALL-A', $t['text']);
        $this->assertSame('#111111', $t['bg_color']);
        $this->assertSame(3, $t['speed']);

        self::add('GRP-B', 'group', 'g1', ['bg_color' => '#222222', 'font_size' => 40, 'height' => 90, 'position' => 'top', 'reserve_space' => 0]);
        $t = Tickers::forRoom(self::room('101'));
        $this->assertSame(['GRP-B', 'ALL-A'], $t['messages']);
        $this->assertSame('GRP-B' . Tickers::SEPARATOR . 'ALL-A', $t['text']);
        $this->assertSame('GRP-B   ✦   ALL-A', $t['text']);
        $this->assertSame(['#222222', 40, 90, 'top', false], [$t['bg_color'], $t['font_size'], $t['height'], $t['position'], $t['reserve_space']], 'style of the most specific');
        $this->assertSame(['ALL-A'], self::msgs('201'));

        self::add('ROOM-C', 'room', 'r101', ['bg_color' => '#333333']);
        $this->assertSame(['ROOM-C', 'GRP-B', 'ALL-A'], self::msgs('101'));
        $this->assertSame('#333333', Tickers::forRoom(self::room('101'))['bg_color']);
        $this->assertSame(['GRP-B', 'ALL-A'], self::msgs('102'));

        // Priority within a level, then id asc.
        self::add('ALL-P5a', 'all', null, ['priority' => 5]);
        self::add('ALL-P5b', 'all', null, ['priority' => 5]);
        self::add('ALL-NEG', 'all', null, ['priority' => -3]);
        $this->assertSame(['ALL-P5a', 'ALL-P5b', 'ALL-A', 'ALL-NEG'], self::msgs('201'));
        $this->assertSame(['ROOM-C', 'GRP-B', 'ALL-P5a', 'ALL-P5b', 'ALL-A', 'ALL-NEG'], self::msgs('101'));

        // Inactive tickers are ignored.
        DB::update('tickers', ['is_active' => 0], "message = 'ALL-NEG'");
        $this->assertSame(['ALL-P5a', 'ALL-P5b', 'ALL-A'], self::msgs('201'));

        // Group override hides the 'all' tickers for that group's rooms only; the room ticker stays.
        $gb = (int) DB::value("SELECT id FROM tickers WHERE message = 'GRP-B'");
        DB::update('tickers', ['override_lower' => 1], 'id = :id', ['id' => $gb]);
        $this->assertSame(['ROOM-C', 'GRP-B'], self::msgs('101'));
        $this->assertSame(['GRP-B'], self::msgs('102'));
        $this->assertSame(['ALL-P5a', 'ALL-P5b', 'ALL-A'], self::msgs('201'));
        // Room override hides everything less specific on that room.
        DB::update('tickers', ['override_lower' => 1], "message = 'ROOM-C'");
        $this->assertSame(['ROOM-C'], self::msgs('101'));
        // An 'all' override does not hide more specific ones.
        DB::update('tickers', ['override_lower' => 0], 'hotel_id = 1');
        DB::update('tickers', ['override_lower' => 1], "message = 'ALL-A'");
        $this->assertSame(['ROOM-C', 'GRP-B', 'ALL-P5a', 'ALL-P5b', 'ALL-A'], self::msgs('101'));

        // Previews per target.
        $this->assertSame(['ALL-P5a', 'ALL-P5b', 'ALL-A'], Tickers::forTarget('all')['messages']);
        $this->assertSame(['GRP-B', 'ALL-P5a', 'ALL-P5b', 'ALL-A'], Tickers::forTarget('group', self::$id['g1'])['messages']);
        $this->assertSame(self::msgs('101'), Tickers::forTarget('room', self::$id['r101'])['messages']);
        $ov = Tickers::overview([self::room('101'), self::room('201')]);
        $this->assertSame(self::msgs('201'), $ov[self::$id['r201']]['messages']);

        // Multi-line messages become one line on the TV.
        self::clearTickers();
        self::add("Line one\n  line two", 'all');
        $this->assertSame('Line one line two', Tickers::forRoom(self::room('201'))['text']);

        // Another hotel's tickers never apply; a foreign room is refused.
        self::clearTickers();
        $this->assertNull(Tickers::forRoom(self::room('101')));
        $this->expectException(TenantException::class);
        Tickers::forRoom(Tenant::run(2, fn () => DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['b101']])));
    }

    public function testTimeWindowsDaysAndDateRange(): void
    {
        self::clearTickers();
        $mon = static fn (string $time, int $plusDays = 0): int => (int) strtotime('2026-10-05 ' . $time) + $plusDays * 86400; // 2026-10-05 is a Monday
        $this->assertSame('1', date('N', $mon('12:00')));

        $night = self::add('NIGHT', 'all', null, ['time_from' => '22:00:00', 'time_to' => '06:00:00']);
        $this->assertSame(['NIGHT'], self::msgs('201', $mon('23:00')));
        $this->assertSame(['NIGHT'], self::msgs('201', $mon('03:00')));
        $this->assertNull(self::msgs('201', $mon('12:00')));
        $this->assertNull(self::msgs('201', $mon('06:00')), 'end is exclusive');

        $day = self::add('DAY', 'all', null, ['time_from' => '09:00:00', 'time_to' => '17:30:00']);
        $this->assertSame(['DAY'], self::msgs('201', $mon('09:00')));
        $this->assertNull(self::msgs('201', $mon('17:30')));

        // Only Mondays: Monday night 23:00 and Tuesday 03:00 (still Monday's window), not Tuesday 23:00.
        DB::update('tickers', ['days' => '1'], 'id = :id', ['id' => $night]);
        $this->assertSame(['NIGHT'], self::msgs('201', $mon('23:00')));
        $this->assertSame(['NIGHT'], self::msgs('201', $mon('03:00', 1)));
        $this->assertNull(self::msgs('201', $mon('23:00', 1)));
        // Same rule as broadcast windows (ContentResolver::windowActive): on a listed day the early part
        // of the window (00:00–06:00) is shown too.
        $this->assertSame(['NIGHT'], self::msgs('201', $mon('03:00')));
        $this->assertNull(self::msgs('201', $mon('03:00', 2)), 'Wednesday 03:00: neither Wednesday nor Tuesday night');
        // Sunday may be stored as 0 or 7.
        DB::update('tickers', ['days' => '0', 'time_from' => null, 'time_to' => null], 'id = :id', ['id' => $day]);
        $this->assertSame(['DAY'], self::msgs('201', $mon('12:00', 6)));
        $this->assertNull(self::msgs('201', $mon('12:00', 5)));
        $this->assertSame([7], Tickers::normalizeDays(['0']));
        $this->assertSame([1, 3, 7], Tickers::normalizeDays('7,3,1,9,x,3'));

        // Date range and state badges.
        self::clearTickers();
        $now = time();
        $future = self::add('FUTURE', 'all', null, ['starts_at' => date('Y-m-d H:i:s', $now + 3600)]);
        $past = self::add('PAST', 'all', null, ['ends_at' => date('Y-m-d H:i:s', $now - 60)]);
        $live = self::add('LIVE', 'all', null, ['starts_at' => date('Y-m-d H:i:s', $now - 3600), 'ends_at' => date('Y-m-d H:i:s', $now + 7200)]);
        $off = self::add('OFF', 'all', null, ['is_active' => 0]);
        $this->assertSame(['LIVE'], self::msgs('201', $now));
        $this->assertSame(['FUTURE', 'LIVE'], self::msgs('201', $now + 3601));
        $state = static fn (int $id) => Tickers::state(DB::one('SELECT * FROM tickers WHERE id = :id', ['id' => $id]), $now);
        $this->assertSame(['scheduled', 'expired', 'live', 'paused'], [$state($future), $state($past), $state($live), $state($off)]);
        self::clearTickers();
    }

    public function testLegacySettingFallback(): void
    {
        self::clearTickers();
        Settings::setMany(['ticker_text' => 'LEGACY-TEXT', 'ticker_bg_color' => '#123456', 'ticker_text_color' => '#ABCDEF', 'ticker_speed' => '8']);
        $t = Tickers::forRoom(self::room('201'));
        $this->assertSame([
            'text' => 'LEGACY-TEXT', 'messages' => ['LEGACY-TEXT'], 'speed' => 8, 'bg_color' => '#123456', 'text_color' => '#ABCDEF',
            'font_size' => 26, 'height' => 56, 'position' => 'bottom', 'reserve_space' => true,
        ], $t);
        // Real tickers come first (legacy has priority -1000), even an 'all' ticker with the lowest priority.
        self::add('NEW-ALL', 'all', null, ['priority' => -999, 'bg_color' => '#000001']);
        $t = Tickers::forRoom(self::room('201'));
        $this->assertSame(['NEW-ALL', 'LEGACY-TEXT'], $t['messages']);
        $this->assertSame('#000001', $t['bg_color']);
        // A group override hides it.
        self::add('G2-ONLY', 'group', 'g2', ['override_lower' => 1]);
        $this->assertSame(['G2-ONLY'], self::msgs('201'));
        // Through the full content object, once (overlay() itself no longer emits a ticker).
        $this->assertNull(ContentResolver::overlay()['ticker']);
        $c = ContentResolver::build(self::room('101'));
        $this->assertSame(['NEW-ALL', 'LEGACY-TEXT'], $c['overlay']['ticker']['messages']);
        $this->assertSame(2, substr_count(json_encode($c, JSON_UNESCAPED_UNICODE), 'LEGACY-TEXT'), 'once in text, once in messages');
        self::clearTickers();
    }

    public function testUpgradeMigrationConvertsLegacySetting(): void
    {
        self::clearTickers();
        Settings::setFor(1, 'ticker_text', '  મંગળા આરતી 6:30 · Mangla Aarti  ');
        Settings::setFor(1, 'ticker_bg_color', '#7b1fa2');
        Settings::setFor(1, 'ticker_text_color', '#FFFFFF');
        Settings::setFor(1, 'ticker_speed', '7');
        Settings::setFor(2, 'ticker_text', 'HOTEL-TWO-LEGACY');
        Settings::setFor(2, 'ticker_speed', '99');
        $before = (int) Settings::getFor(1, 'content_version');
        $this->assertContains('010_tickers_access_upgrade.php', Migrator::applied(), 'ran on install');

        $fn = require HC_ROOT . '/migrations/010_tickers_access_upgrade.php';
        $fn(DB::pdo(), static function (string $m): void {
        });
        Settings::flush();
        $row = DB::one('SELECT * FROM tickers WHERE hotel_id = 1');
        $this->assertNotNull($row);
        $this->assertSame(['મંગળા આરતી 6:30 · Mangla Aarti', 'all', null, '#7B1FA2', '#FFFFFF', 7, 26, 56, 'bottom', 1, 1],
            [$row['message'], $row['target_type'], $row['target_id'], $row['bg_color'], $row['text_color'], (int) $row['speed'], (int) $row['font_size'], (int) $row['height'], $row['position'], (int) $row['reserve_space'], (int) $row['is_active']]);
        $this->assertSame('', Settings::getFor(1, 'ticker_text'));
        $this->assertGreaterThan($before, (int) Settings::getFor(1, 'content_version'));
        $two = DB::one("SELECT * FROM tickers WHERE hotel_id = 2 AND message = 'HOTEL-TWO-LEGACY'");
        $this->assertSame(10, (int) $two['speed'], 'speed clamped');
        $this->assertSame('', Settings::getFor(2, 'ticker_text'));
        // Same text on the TV as before the upgrade.
        $this->assertSame(['મંગળા આરતી 6:30 · Mangla Aarti'], self::msgs('201'));

        // Idempotent: a second run converts nothing.
        $count = (int) DB::value('SELECT COUNT(*) FROM tickers');
        $fn(DB::pdo(), static function (string $m): void {
        });
        $this->assertSame($count, (int) DB::value('SELECT COUNT(*) FROM tickers'));
        DB::query("DELETE FROM tickers WHERE hotel_id = 2 AND message = 'HOTEL-TWO-LEGACY'");
        self::clearTickers();
    }

    // ------------------------------------------------------------------ TV contract

    public function testTvContentContractAndHashOverHttp(): void
    {
        self::clearTickers();
        $key = (string) Settings::get('registration_key');
        $poll = function (string $uid, string $room) use ($key): array {
            static $tokens = [];
            if (!isset($tokens[$uid])) {
                [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key]);
                $this->assertSame(200, $st);
                $tokens[$uid] = $j['data']['token'];
            }
            [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, ['Authorization: Bearer ' . $tokens[$uid], 'X-Device-Id: ' . $uid]);
            $this->assertSame(200, $st);
            return $j['data']['content'];
        };
        $c = $poll('tv-ticker-101-0001', '101');
        $this->assertArrayHasKey('ticker', $c['overlay']);
        $this->assertNull($c['overlay']['ticker'], 'no active ticker → null');
        $h0 = $c['hash'];

        $s = new AdminSession(self::$url, 'tkMgr');
        $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'સ્વાગત છે · Welcome', 'target_type' => 'all', 'bg_color' => '#102030', 'text_color' => '#fafafa',
            'speed' => 6, 'font_size' => 30, 'height' => 64, 'position' => 'top', 'reserve_space' => 1, 'priority' => 0, 'is_active' => 1]);
        $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'Room 101 only', 'target_type' => 'room', 'room_id' => self::$id['r101'], 'bg_color' => '#000000',
            'text_color' => '#FFD700', 'speed' => 4, 'font_size' => 26, 'height' => 56, 'position' => 'bottom', 'reserve_space' => 1, 'is_active' => 1]);
        $c = $poll('tv-ticker-101-0001', '101');
        $tk = $c['overlay']['ticker'];
        $this->assertSame(self::KEYS, array_keys($tk), 'exact contract keys');
        $this->assertSame('Room 101 only   ✦   સ્વાગત છે · Welcome', $tk['text']);
        $this->assertSame(['Room 101 only', 'સ્વાગત છે · Welcome'], $tk['messages']);
        $this->assertSame([4, '#000000', '#FFD700', 26, 56, 'bottom', true], [$tk['speed'], $tk['bg_color'], $tk['text_color'], $tk['font_size'], $tk['height'], $tk['position'], $tk['reserve_space']]);
        $this->assertNotSame($h0, $c['hash'], 'hash changes with the ticker');
        $this->assertSame('assigned', $c['mode']);

        // Welcome screen (room without content) keeps the hotel-wide ticker with its own style.
        $w = $poll('tv-ticker-201-0001', '201');
        $this->assertSame('empty', $w['mode']);
        $this->assertSame(['text' => 'સ્વાગત છે · Welcome', 'messages' => ['સ્વાગત છે · Welcome'], 'speed' => 6, 'bg_color' => '#102030', 'text_color' => '#FAFAFA',
            'font_size' => 30, 'height' => 64, 'position' => 'top', 'reserve_space' => true], $w['overlay']['ticker']);
        $this->assertIsBool($w['overlay']['ticker']['reserve_space']);
        $this->assertIsInt($w['overlay']['ticker']['font_size']);

        // Editing changes the hash; deactivating removes the ticker.
        $id = (int) DB::value("SELECT id FROM tickers WHERE message = 'Room 101 only'");
        $h1 = $c['hash'];
        $s->post('tickers.php', ['op' => 'save', 'id' => $id, 'message' => 'Room 101 updated', 'target_type' => 'room', 'room_id' => self::$id['r101'], 'speed' => 4, 'reserve_space' => 1, 'is_active' => 1]);
        $c = $poll('tv-ticker-101-0001', '101');
        $this->assertNotSame($h1, $c['hash']);
        $this->assertSame(['Room 101 updated', 'સ્વાગત છે · Welcome'], $c['overlay']['ticker']['messages']);
        $s->post('tickers.php', ['op' => 'toggle', 'id' => $id]);
        $this->assertSame(['સ્વાગત છે · Welcome'], $poll('tv-ticker-101-0001', '101')['overlay']['ticker']['messages']);

        // Off / emergency / suspended → no ticker.
        $room = self::room('101');
        DB::update('rooms', ['is_enabled' => 0], 'id = :id', ['id' => $room['id']]);
        $this->assertNull(ContentResolver::build(self::room('101'))['overlay']['ticker']);
        DB::update('rooms', ['is_enabled' => 1], 'id = :id', ['id' => $room['id']]);
        $b = DB::insert('broadcast_commands', ['title' => 'FIRE', 'command' => 'EMERGENCY', 'target_type' => 'all', 'target_ids' => '[]', 'mode' => 'now', 'status' => 'active', 'is_emergency' => 1, 'payload' => '{}']);
        $this->assertSame('emergency', ContentResolver::build(self::room('101'))['mode']);
        $this->assertNull(ContentResolver::build(self::room('101'))['overlay']['ticker']);
        DB::delete('broadcast_commands', 'id = :id', ['id' => $b]);
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        try {
            $c = ContentResolver::build(self::room('101'));
            $this->assertSame('suspended', $c['mode']);
            $this->assertNull($c['overlay']['ticker']);
        } finally {
            DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
            Tenant::forget();
        }
        $this->assertNotNull(ContentResolver::build(self::room('101'))['overlay']['ticker']);
        // Overlay is created when an earlier step left it null.
        $content = ['mode' => 'default', 'overlay' => null];
        (new TickerExtension())->apply($content, self::room('201'));
        $this->assertSame(['સ્વાગત છે · Welcome'], $content['overlay']['ticker']['messages']);
        // The cached content object follows time windows: cache key changes every minute, TTL 15 s.
        $this->assertStringNotContainsString('B-TICKER-SECRET', json_encode($poll('tv-ticker-201-0001', '201'), JSON_UNESCAPED_UNICODE));
        $this->assertSame('', TestEnv::phpErrors());
        self::clearTickers();
    }

    // ------------------------------------------------------------------ admin page

    public function testAdminPageCrudValidationXssAndCsrf(): void
    {
        self::clearTickers();
        $s = new AdminSession(self::$url, 'tkMgr');
        [$code, , $html] = $s->get('tickers.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('What each TV shows now', $html);
        [$code, , $html] = $s->get('tickers.php?action=new');
        $this->assertSame(200, $code);
        foreach (['name="message"', 'name="target_type"', 'name="group_id"', 'name="room_id"', 'name="bg_color"', 'name="text_color"', 'name="speed"', 'name="font_size"',
            'name="height"', 'name="position"', 'name="override_lower"', 'name="priority"', 'name="starts_at"', 'name="ends_at"', 'name="time_from"', 'name="time_to"', 'name="days[]"', 'id="tkPreview"', 'data-preset-bg'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertMatchesRegularExpression('/id="tk_reserve" name="reserve_space" value="1" checked/', $html, '"Do not cover the video" is on by default');

        // Create (Gujarati + XSS in the name and message).
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'name' => 'X' . self::XSS, 'message' => 'જય દ્વારકાધીશ ' . self::XSS, 'target_type' => 'group', 'group_id' => self::$id['g1'],
            'bg_color' => '#7B1FA2', 'text_color' => 'red;}</style>', 'speed' => 99, 'font_size' => 5, 'height' => 999, 'position' => 'sideways', 'override_lower' => 1, 'priority' => 7,
            'starts_at' => '2026-01-01T08:00', 'ends_at' => '2099-12-31T22:00', 'time_from' => '06:00', 'time_to' => '23:30', 'days' => ['1', '0', '9'], 'is_active' => 1]);
        $this->assertSame(302, $code);
        $row = DB::one('SELECT * FROM tickers WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame('જય દ્વારકાધીશ ' . self::XSS, $row['message']);
        $this->assertSame(['group', self::$id['g1'], '#7B1FA2', '#FFD700', 10, 14, 200, 'bottom', 0, 1, 7, '2026-01-01 08:00:00', '2099-12-31 22:00:00', '06:00:00', '23:30:00', '1,7', self::$id['tkMgr']],
            [$row['target_type'], (int) $row['target_id'], $row['bg_color'], $row['text_color'], (int) $row['speed'], (int) $row['font_size'], (int) $row['height'], $row['position'],
                (int) $row['reserve_space'], (int) $row['override_lower'], (int) $row['priority'], $row['starts_at'], $row['ends_at'], $row['time_from'], $row['time_to'], $row['days'], (int) $row['created_by']]);
        [, , $html] = $s->get('tickers.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('જય દ્વારકાધીશ', $html);
        $this->assertStringContainsString('Group: Floor 1', $html);
        [$code, , $html] = $s->get('tickers.php?action=edit&id=' . $row['id']);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('value="2026-01-01T08:00"', $html);
        $this->assertMatchesRegularExpression('/id="tk_reserve" name="reserve_space" value="1">/', $html, 'unchecked when saved off');

        // Validation errors: nothing saved, form shown again with the message.
        $count = (int) DB::value('SELECT COUNT(*) FROM tickers');
        foreach ([
            [['message' => '   ', 'target_type' => 'all'], 'The ticker message is required.'],
            [['message' => str_repeat('a', 1001), 'target_type' => 'all'], 'at most 1000 characters'],
            [['message' => 'x', 'target_type' => 'room', 'room_id' => ''], 'Choose a room.'],
            [['message' => 'x', 'target_type' => 'group', 'group_id' => '999999'], 'Choose a group.'],
            [['message' => 'x', 'target_type' => 'all', 'starts_at' => '2026-05-01T10:00', 'ends_at' => '2026-04-01T10:00'], 'The end date must be after the start date.'],
            [['message' => 'x', 'target_type' => 'all', 'starts_at' => 'tomorrow'], 'Invalid date'],
            [['message' => 'x', 'target_type' => 'all', 'time_from' => '10:00'], 'Set both times'],
            [['message' => 'x', 'target_type' => 'all', 'time_from' => '10:00', 'time_to' => '10:00'], 'different start and end'],
            [['message' => 'x', 'target_type' => 'all', 'time_from' => '25:00', 'time_to' => '10:00'], 'Invalid time'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('tickers.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
            $this->assertStringContainsString('id="tickerForm"', $html);
        }
        $this->assertSame($count, (int) DB::value('SELECT COUNT(*) FROM tickers'));

        // Edit, toggle, delete.
        $s->post('tickers.php', ['op' => 'save', 'id' => $row['id'], 'name' => 'Edited', 'message' => 'Edited msg', 'target_type' => 'all', 'speed' => 2, 'reserve_space' => 1, 'is_active' => 1]);
        $e = DB::one('SELECT * FROM tickers WHERE id = :id', ['id' => $row['id']]);
        $this->assertSame(['Edited', 'Edited msg', 'all', null, 2, 1, 0, null, null], [$e['name'], $e['message'], $e['target_type'], $e['target_id'], (int) $e['speed'], (int) $e['reserve_space'], (int) $e['override_lower'], $e['days'], $e['starts_at']]);
        $this->assertSame($count, (int) DB::value('SELECT COUNT(*) FROM tickers'), 'updated in place');
        $s->post('tickers.php', ['op' => 'toggle', 'id' => $row['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM tickers WHERE id = :id', ['id' => $row['id']]));
        [, , $html] = $s->get('tickers.php');
        $this->assertStringContainsString('bi-pause-circle', $html, 'Off badge');
        $s->post('tickers.php', ['op' => 'toggle', 'id' => $row['id']]);
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM tickers WHERE id = :id', ['id' => $row['id']]));
        [, , $html] = $s->get('tickers.php');
        $this->assertStringContainsString('bi-broadcast', $html, 'Live badge');
        $this->assertMatchesRegularExpression('/data-room="' . self::$id['r201'] . '">.*Edited msg/s', $html, 'overview shows the resolved text');

        // CSRF is required.
        [$code] = TestEnv::http('POST', self::$url . 'admin/tickers.php', null, [], $s->jar, ['op' => 'delete', 'id' => (string) $row['id']]);
        $this->assertSame(419, $code);
        [$code] = TestEnv::http('POST', self::$url . 'admin/tickers.php', null, [], $s->jar, ['_csrf' => 'wrong', 'op' => 'save', 'id' => '0', 'message' => 'NO-CSRF', 'target_type' => 'all']);
        $this->assertSame(419, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM tickers WHERE message = 'NO-CSRF'"));
        $this->assertNotNull(DB::one('SELECT id FROM tickers WHERE id = :id', ['id' => $row['id']]));

        $s->post('tickers.php', ['op' => 'delete', 'id' => $row['id']]);
        $this->assertNull(DB::one('SELECT id FROM tickers WHERE id = :id', ['id' => $row['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'ticker_delete' AND entity_id = :id", ['id' => $row['id']]));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPermissionsNavAndSettingsPage(): void
    {
        $s = new AdminSession(self::$url, 'tkRecep');
        [$code] = $s->get('tickers.php');
        $this->assertSame(403, $code);
        [, , $html] = $s->get('index.php');
        $this->assertStringNotContainsString('tickers.php', $html);

        $s = new AdminSession(self::$url, 'tkMgr');
        [, , $html] = $s->get('index.php');
        $this->assertStringContainsString('tickers.php', $html);
        $this->assertLessThan(strpos($html, 'schedule.php'), strpos($html, 'tickers.php'), 'menu entry right after Broadcast');
        $this->assertGreaterThan(strpos($html, 'broadcast.php'), strpos($html, 'tickers.php'));

        // Settings: no ticker fields any more, a link instead; saving the display tab keeps the legacy keys.
        Settings::set('ticker_text', 'CHAIN-PUSHED');
        $boss = DB::insert('users', ['hotel_id' => 1, 'username' => 'tkBoss', 'email' => 'tkboss@t.test', 'full_name' => 'Boss', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'super_admin']);
        $this->assertGreaterThan(0, $boss);
        $b = new AdminSession(self::$url, 'tkBoss');
        [$code, , $html] = $b->get('settings.php?tab=display');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('name="ticker_text"', $html);
        $this->assertStringContainsString('tickers.php', $html);
        $b->post('settings.php', ['op' => 'save_display', 'tab' => 'display', 'poll_interval' => 8, 'heartbeat_interval' => 60, 'offline_after' => 90, 'overlay_clock_format' => 'hh:mm a']);
        Settings::flush();
        $this->assertSame('CHAIN-PUSHED', Settings::get('ticker_text'));
        self::clearTickers();

        // TV simulator renders the new fields (position, height, font size, reserve_space).
        [$code, , $html] = $s->get('preview.php?room_id=' . self::$id['r101']);
        $this->assertSame(200, $code);
        foreach (['--tk-bottom', 'reserve_space', 'font_size', 'pos-top'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    // ------------------------------------------------------------------ tenancy & access

    public function testCrossHotelIdsAreRefused(): void
    {
        self::clearTickers();
        $mine = self::add('H1-TICKER', 'all');
        $s = new AdminSession(self::$url, 'tkBoss2');
        [$code, , $html] = $s->get('tickers.php');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('H1-TICKER', $html);
        $this->assertStringContainsString('B-TICKER-SECRET', $html);
        $this->assertStringNotContainsString('Room 101', $html, 'overview: own rooms only');
        [$code] = $s->get('tickers.php?action=edit&id=' . $mine);
        $this->assertSame(404, $code);
        foreach ([['op' => 'save', 'id' => $mine, 'message' => 'HACKED', 'target_type' => 'all'], ['op' => 'delete', 'id' => $mine], ['op' => 'toggle', 'id' => $mine]] as $p) {
            [$code] = $s->post('tickers.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        $this->assertSame(['H1-TICKER', 1], array_values(DB::one('SELECT message, is_active FROM tickers WHERE id = :id', ['id' => $mine])));
        // Hotel 1 room / group ids in a new hotel 2 ticker.
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'X-ROOM', 'target_type' => 'room', 'room_id' => self::$id['r101']]);
        $this->assertSame(404, $code);
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'X-GROUP', 'target_type' => 'group', 'group_id' => self::$id['g1']]);
        $this->assertSame(404, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM tickers WHERE message LIKE 'X-%'"));
        // Own ids work and land in hotel 2.
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'B-ROOM', 'target_type' => 'room', 'room_id' => self::$id['b101'], 'reserve_space' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $this->assertSame(2, (int) DB::value("SELECT hotel_id FROM tickers WHERE message = 'B-ROOM'"));
        // Hotel 1's TVs never see hotel 2's tickers.
        $this->assertSame(['H1-TICKER'], self::msgs('101'));
        DB::query("DELETE FROM tickers WHERE message = 'B-ROOM'");
        self::clearTickers();
    }

    public function testRestrictedUserOnlyHandlesOwnTvs(): void
    {
        self::clearTickers();
        $uid = self::$id['tkStaff'];
        DB::query('DELETE FROM user_access WHERE user_id = :u', ['u' => $uid]);
        DB::insert('user_access', ['user_id' => $uid, 'target_type' => 'room', 'target_id' => self::$id['r102'], 'created_at' => now()]);
        DB::insert('user_access', ['user_id' => $uid, 'target_type' => 'group', 'target_id' => self::$id['g2'], 'created_at' => now()]);
        $allId = self::add('MGR-ALL', 'all');
        $r101 = self::add('MGR-R101', 'room', 'r101');
        $g1 = self::add('MGR-G1', 'group', 'g1');

        $s = new AdminSession(self::$url, 'tkStaff');
        [$code, , $html] = $s->get('tickers.php');
        $this->assertSame(200, $code);
        foreach (['MGR-ALL', 'MGR-R101', 'MGR-G1'] as $hidden) {
            $this->assertStringNotContainsString($hidden, preg_replace('/<table class="table table-sm.*$/s', '', $html), $hidden . ' not in the list');
        }
        // Overview: only rooms 102 and 201 (group 2).
        $this->assertStringContainsString('data-room="' . self::$id['r102'] . '"', $html);
        $this->assertStringContainsString('data-room="' . self::$id['r201'] . '"', $html);
        $this->assertStringNotContainsString('data-room="' . self::$id['r101'] . '"', $html);
        // The form offers no "All TVs" and only own rooms / groups.
        [, , $html] = $s->get('tickers.php?action=new');
        $this->assertStringNotContainsString('id="tt_all"', $html);
        $this->assertStringNotContainsString('<option value="' . self::$id['r101'] . '"', $html);
        $this->assertStringContainsString('<option value="' . self::$id['r102'] . '"', $html);
        $this->assertStringNotContainsString('<option value="' . self::$id['g1'] . '"', $html);

        // Cannot create 'all', a foreign room or group of the same hotel.
        [$code, , $html] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'S-ALL', 'target_type' => 'all']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString(e('not all TVs'), $html);
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'S-R101', 'target_type' => 'room', 'room_id' => self::$id['r101']]);
        $this->assertSame(403, $code);
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'S-G1', 'target_type' => 'group', 'group_id' => self::$id['g1']]);
        $this->assertSame(403, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM tickers WHERE message IN ('S-ALL','S-R101','S-G1')"));
        // Own room / group work.
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'S-R102', 'target_type' => 'room', 'room_id' => self::$id['r102'], 'reserve_space' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'message' => 'S-G2', 'target_type' => 'group', 'group_id' => self::$id['g2'], 'reserve_space' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $own = (int) DB::value("SELECT id FROM tickers WHERE message = 'S-R102'");
        [, , $html] = $s->get('tickers.php');
        $this->assertStringContainsString('S-R102', $html);
        $this->assertStringContainsString('S-G2', $html);
        // Cannot open, edit, move, toggle or delete other tickers; cannot move an own ticker to 'all'.
        foreach ([$allId, $r101, $g1] as $other) {
            [$code] = $s->get('tickers.php?action=edit&id=' . $other);
            $this->assertSame(403, $code);
            [$code] = $s->post('tickers.php', ['op' => 'delete', 'id' => $other]);
            $this->assertSame(403, $code);
            [$code] = $s->post('tickers.php', ['op' => 'toggle', 'id' => $other]);
            $this->assertSame(403, $code);
        }
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM tickers WHERE message LIKE 'MGR-%' AND is_active = 1"));
        [$code] = $s->post('tickers.php', ['op' => 'save', 'id' => $own, 'message' => 'S-R102', 'target_type' => 'all']);
        $this->assertSame(422, $code);
        $this->assertSame('room', DB::value('SELECT target_type FROM tickers WHERE id = :id', ['id' => $own]));
        [$code] = $s->post('tickers.php', ['op' => 'delete', 'id' => $own]);
        $this->assertSame(302, $code);
        $this->assertNull(DB::one('SELECT id FROM tickers WHERE id = :id', ['id' => $own]));

        // Same rules in PHP (Access::$userOverride).
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $uid]);
        Access::forget();
        [, $errors] = Tickers::validate(['message' => 'x', 'target_type' => 'all']);
        $this->assertNotEmpty($errors);
        $this->assertSame(['S-G2'], array_column(Tickers::listVisible(), 'message'));

        // The manager (no user_access rows) still sees everything.
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['tkMgr']]);
        Access::forget();
        $this->assertCount(4, Tickers::listVisible());
        Access::$userOverride = null;
        DB::query('DELETE FROM user_access WHERE user_id = :u', ['u' => $uid]);
        self::clearTickers();
    }
}
