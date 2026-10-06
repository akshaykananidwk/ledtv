<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hotel chains (#20): schema, dashboard aggregates, chain content publishing (media copies, re-publish,
 * per-hotel ids), chain broadcast, settings templates, enter hotel, role permissions and isolation.
 *
 * Data: chain X = hotels #1 "Alpha Palace" + #2 "Alpha Beach"; chain Y = #3 "Beta Inn";
 * #4 "Solo Hotel" is not in a chain. Users: cadminX / cadminY (chain_admin), bossX1 (super admin of #1
 * with chain access), bossX2 (super admin of #2 without), mgrX1 (manager of #1), padmin (platform).
 */
final class ChainsTest extends TestCase
{
    private static string $url;
    private static int $x = 0;
    private static int $y = 0;
    private static array $h = [];
    private static array $u = [];
    private static array $dev = [];
    /** @var array<string, AdminSession> */
    private static array $s = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        // The chains feature is off by default (platform setting, see UserAccessTest for the switched-off case).
        Settings::setPlatform('feature_chains', '1');
        Settings::flush();
        Chains::actAs(null);
        $pw = Auth::hash('Passw0rd!');
        Tenant::set(1);
        DB::query("UPDATE hotels SET name = 'Alpha Palace' WHERE id = 1");
        Settings::set('hotel_name', 'Alpha Palace');
        self::$h[1] = 1;
        self::$h[2] = Hotels::create(['name' => 'Alpha Beach', 'plan_id' => null]);
        self::$h[3] = Hotels::create(['name' => 'Beta Inn', 'plan_id' => null]);
        self::$h[4] = Hotels::create(['name' => 'Solo Hotel', 'plan_id' => null]);
        self::$x = Chains::create(['name' => 'Chain X', 'owner_name' => 'Owner X']);
        self::$y = Chains::create(['name' => 'Chain Y', 'owner_name' => 'Owner Y']);
        DB::query('UPDATE hotels SET chain_id = :c WHERE id IN (1, :h2)', ['c' => self::$x, 'h2' => self::$h[2]]);
        DB::query('UPDATE hotels SET chain_id = :c WHERE id = :h3', ['c' => self::$y, 'h3' => self::$h[3]]);

        $mk = static function (string $name, string $role, ?int $hotel, ?int $chain = null) use ($pw): int {
            return DB::insert('users', ['hotel_id' => $hotel, 'chain_id' => $chain, 'username' => $name, 'email' => $name . '@t.test', 'full_name' => $name,
                'password_hash' => $pw, 'role' => $role, 'is_active' => 1, 'created_at' => now()]);
        };
        self::$u['padmin'] = $mk('padmin', 'platform_admin', null);
        self::$u['cadminX'] = $mk('cadminX', 'chain_admin', null, self::$x);
        self::$u['cadminY'] = $mk('cadminY', 'chain_admin', null, self::$y);
        self::$u['bossX1'] = $mk('bossX1', 'super_admin', 1, self::$x);
        self::$u['bossX2'] = $mk('bossX2', 'super_admin', self::$h[2]);
        self::$u['mgrX1'] = $mk('mgrX1', 'manager', 1);
        self::$u['bossY'] = $mk('bossY', 'super_admin', self::$h[3]);

        // Rooms + TVs: #1 two rooms (1 online, 1 offline), #2 one room (online), #3 one (online), #4 one (online).
        $rooms = ['1' => ['A101', 'A102'], '2' => ['B201'], '3' => ['C301'], '4' => ['D401']];
        foreach ($rooms as $k => $list) {
            $hid = self::$h[(int) $k];
            Tenant::run($hid, static function () use ($hid, $list): void {
                foreach ($list as $i => $num) {
                    $rid = DB::insert('rooms', ['room_number' => $num, 'name' => 'Room ' . $num, 'floor' => '1']);
                    $online = !($hid === 1 && $i === 1);
                    self::$dev[$hid][] = DB::insert('devices', ['device_uid' => 'tv-' . $num, 'room_id' => $rid, 'token_hash' => hash('sha256', $num),
                        'status' => $online ? 'online' : 'offline', 'last_ping' => $online ? now() : date('Y-m-d H:i:s', time() - 7200), 'registered_at' => date('Y-m-d H:i:s', time() - 86400)]);
                }
            });
        }
        // Plays today: #1 three, #2 one, #3 five (must never count for chain X).
        foreach ([1 => 3, 2 => 1, 3 => 5] as $k => $n) {
            $hid = self::$h[$k];
            $room = (int) DB::value('SELECT id FROM rooms WHERE hotel_id = :h ORDER BY id LIMIT 1', ['h' => $hid]);
            for ($i = 0; $i < $n; $i++) {
                DB::query("INSERT INTO broadcast_logs (hotel_id, device_id, room_id, event, duration_sec, created_at) VALUES (:h, :d, :r, 'played', 10, :t)",
                    ['h' => $hid, 'd' => self::$dev[$hid][0], 'r' => $room, 't' => date('Y-m-d H:i:s', max(strtotime('today'), time() - 60))]);
            }
        }
        // Guest services: open order in #1, open request in #2, feedback 5 + 3 in #1, 1 in #3.
        DB::query("INSERT INTO guest_orders (hotel_id, status, total, created_at) VALUES (1, 'new', 100, :t), (1, 'delivered', 50, :t2)", ['t' => now(), 't2' => now()]);
        DB::query("INSERT INTO guest_requests (hotel_id, type_name, status, created_at) VALUES (:h, 'Towels', 'open', :t)", ['h' => self::$h[2], 't' => now()]);
        DB::query("INSERT INTO guest_feedback (hotel_id, rating, token_hash, created_at) VALUES (1, 5, 'a', :t), (1, 3, 'b', :t2), (:h3, 1, 'c', :t3)",
            ['t' => now(), 't2' => now(), 't3' => now(), 'h3' => self::$h[3]]);
        // Occupancy: one in-house stay in #1.
        DB::query("INSERT INTO guest_stays (hotel_id, room_id, guest_name, checkin_at) VALUES (1, (SELECT id FROM rooms WHERE hotel_id = 1 ORDER BY id LIMIT 1), 'Guest', :t)", ['t' => date('Y-m-d H:i:s', time() - 3600)]);
        // Overdue invoice for #2 (2 days: below the auto-suspend threshold of BillingTask).
        DB::query("INSERT INTO invoices (hotel_id, number, period_from, period_to, total, status, issued_at, due_date) VALUES (:h, 'T-2026-0001', :f, :t, 500, 'unpaid', :i, :d)",
            ['h' => self::$h[2], 'f' => date('Y-m-01', strtotime('-1 month')), 't' => date('Y-m-t', strtotime('-1 month')), 'i' => date('Y-m-d', strtotime('-9 days')), 'd' => date('Y-m-d', strtotime('-2 days'))]);
        Settings::setFor(self::$h[3], 'ticker_text', 'BETA-TICKER');
        Cache::clear();
        Tenant::set(1);

        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Chains::actAs(null);
        self::$s = [];
        Tenant::set(1);
    }

    private static function sess(string $user): AdminSession
    {
        return self::$s[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function user(string $name): array
    {
        return DB::one('SELECT * FROM users WHERE username = :u', ['u' => $name]);
    }

    /** Write a real PNG into the chain's upload folder; returns its relative path. */
    private static function chainImage(int $chain, string $name, int $color): string
    {
        $rel = 'chains/c' . $chain . '/media/' . date('Y/m') . '/' . $name;
        @mkdir(dirname(HC_ROOT . '/uploads/' . $rel), 0755, true);
        $im = imagecreatetruecolor(8, 8);
        imagefill($im, 0, 0, $color);
        imagepng($im, HC_ROOT . '/uploads/' . $rel);
        return $rel;
    }

    private function assertDenied(int $status, string $what): void
    {
        $this->assertContains($status, [403, 404], $what . ' must be refused (got ' . $status . ')');
    }

    // ------------------------------------------------------------------ schema / roles

    public function testMigrationAddsChainSchemaAndRole(): void
    {
        $pdo = DB::pdo();
        $this->assertTrue(Migrator::hasColumn($pdo, 'hotels', 'chain_id'));
        $this->assertTrue(Migrator::hasColumn($pdo, 'users', 'chain_id'));
        $this->assertTrue(Migrator::hasForeignKey($pdo, 'hotels', 'fk_hotels_chain'));
        $type = (string) Migrator::columnType($pdo, 'users', 'role');
        foreach (['platform_admin', 'reseller', 'super_admin', 'manager', 'staff', 'reception', 'chain_admin'] as $r) {
            $this->assertStringContainsString("'$r'", $type);
        }
        foreach (['hotel_chains', 'chain_content_items', 'chain_playlists', 'chain_playlist_items', 'chain_publications', 'chain_setting_templates', 'chain_actions'] as $t) {
            $this->assertTrue(Migrator::hasTable($pdo, $t), $t);
        }
        $this->assertSame([], Migrator::pending());
        // The PHP migration is idempotent.
        $fn = require HC_ROOT . '/migrations/009_hotel_chains_upgrade.php';
        $fn($pdo, static function (): void {
        });
        $this->assertSame($type, (string) Migrator::columnType($pdo, 'users', 'role'));
    }

    public function testRolePermissions(): void
    {
        $this->assertTrue(Auth::roleCan('chain_admin', 'rooms.manage'), 'chain admin acts as super admin inside a chain hotel');
        $this->assertTrue(Auth::roleCan('chain_admin', 'settings.manage'));
        $this->assertTrue(Auth::roleCan('chain_admin', 'chain.view'));
        $this->assertFalse(Auth::roleCan('chain_admin', 'chains.manage'));
        $this->assertFalse(Auth::roleCan('chain_admin', 'platform.manage'));
        $this->assertFalse(Auth::roleCan('manager', 'chain.view'));
        $this->assertTrue(Auth::roleCan('reseller', 'chains.manage'));
        $this->assertContains('chain_admin', Auth::ALL_ROLES);

        $this->assertSame([self::$x], Chains::userChainIds(self::user('cadminX')));
        $this->assertSame([self::$y], Chains::userChainIds(self::user('cadminY')));
        $this->assertSame([self::$x], Chains::userChainIds(self::user('bossX1')), 'granted super admin');
        $this->assertSame([], Chains::userChainIds(self::user('bossX2')), 'super admin without grant');
        $this->assertSame([], Chains::userChainIds(self::user('mgrX1')));
        $this->assertCount(2, Chains::userChainIds(self::user('padmin')));
        $this->assertTrue(Chains::userCanEnter(self::user('cadminX'), self::$h[2]));
        $this->assertFalse(Chains::userCanEnter(self::user('cadminX'), self::$h[3]));
        $this->assertFalse(Chains::userCanEnter(self::user('cadminX'), self::$h[4]));
        $this->assertTrue(Chains::userCanEnter(self::user('bossX1'), self::$h[2]));
        $this->assertFalse(Chains::userCanEnter(self::user('bossX2'), 1));
        $this->assertFalse(Chains::userCanEnter(self::user('mgrX1'), self::$h[2]));
    }

    // ------------------------------------------------------------------ aggregates

    public function testOverviewAggregatesOnlyChainHotels(): void
    {
        Chains::actAs(self::user('cadminX'));
        $ov = Chains::overview(self::$x);
        $by = array_column($ov['hotels'], null, 'id');
        $ids = array_map('intval', array_keys($by));
        sort($ids);
        $this->assertSame([1, self::$h[2]], $ids);
        $this->assertSame(['tvs' => 2, 'online' => 1, 'offline' => 1, 'rooms' => 2, 'plays' => 3, 'orders' => 1, 'requests' => 0],
            ['tvs' => $by[1]['tvs'], 'online' => $by[1]['online'], 'offline' => $by[1]['offline'], 'rooms' => $by[1]['rooms'], 'plays' => $by[1]['plays_today'], 'orders' => $by[1]['open_orders'], 'requests' => $by[1]['open_requests']]);
        $this->assertSame(4.0, (float) $by[1]['feedback_avg']);
        $this->assertSame(1, $by[1]['occupied']);
        $this->assertSame(50.0, (float) $by[1]['occupancy']);
        $this->assertSame('overdue', $by[self::$h[2]]['invoice_status']);
        $t = $ov['totals'];
        $this->assertSame([2, 3, 2, 1, 3, 4, 1, 1, 1, 1], [$t['hotels'], $t['tvs'], $t['online'], $t['offline'], $t['rooms'], $t['plays_today'], $t['open_orders'], $t['open_requests'], $t['unpaid_invoices'], $t['overdue_invoices']]);
        $this->assertSame(4.0, (float) $t['feedback_avg'], 'chain Y feedback (1★) never counted');
        $this->assertSame(1, Tenant::id(), 'context restored');
    }

    public function testComparisonReportUsesAnalyticsPerHotel(): void
    {
        Chains::actAs(self::user('cadminX'));
        $d = date('Y-m-d');
        $rep = Chains::report(self::$x, $d, $d);
        $this->assertCount(2, $rep['hotels']);
        $this->assertSame(4, $rep['totals']['plays']);
        $this->assertSame(40, $rep['totals']['seconds']);
        $this->assertSame(2, $rep['totals']['orders']);
        $this->assertSame(1, $rep['totals']['requests']);
        $this->assertSame(4.0, (float) $rep['totals']['feedback_avg']);
        $this->assertSame([$d], $rep['days']);
        $this->assertSame([3], $rep['series'][1]['plays']);
        $this->assertNotNull($rep['totals']['uptime']);
        // A hotel of another chain in the filter is refused.
        try {
            Chains::report(self::$x, $d, $d, [1, self::$h[3]]);
            $this->fail('foreign hotel accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        [$pf, $pt] = Chains::previousRange('2026-10-08', '2026-10-14');
        $this->assertSame(['2026-10-01', '2026-10-07'], [$pf, $pt]);
    }

    // ------------------------------------------------------------------ publish

    public function testPublishCopiesMediaAndRepublishUpdatesCopies(): void
    {
        Chains::actAs(self::user('cadminX'));
        $file = self::chainImage(self::$x, 'logo-a.png', 0xFF0000);
        $cid = DB::insert('chain_content_items', ['chain_id' => self::$x, 'title' => 'Chain Breakfast', 'type' => 'image', 'file_path' => $file,
            'mime_type' => 'image/png', 'file_size' => filesize(HC_ROOT . '/uploads/' . $file), 'settings' => '{}', 'duration' => 12, 'is_active' => 1]);
        $res = Chains::publish(self::$x, 'content', $cid, [1, self::$h[2]]);
        $this->assertSame(['created', 'created'], array_column($res, 'status'));
        $pub = Chains::publications(self::$x, 'content', $cid);
        $this->assertSame([1, self::$h[2]], array_keys($pub));
        $paths = [];
        foreach ($pub as $hid => $localId) {
            $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $localId]);
            $this->assertSame($hid, (int) $row['hotel_id']);
            $this->assertSame('Chain Breakfast', $row['title']);
            $this->assertStringStartsWith('h' . $hid . '/media/', $row['file_path']);
            $this->assertFileExists(HC_ROOT . '/uploads/' . $row['file_path']);
            $this->assertFileEquals(HC_ROOT . '/uploads/' . $file, HC_ROOT . '/uploads/' . $row['file_path']);
            $this->assertSame($cid, json_decode($row['settings'], true)['chain_content_id']);
            $paths[$hid] = $row['file_path'];
        }
        $this->assertNotSame($paths[1], $paths[self::$h[2]], 'each hotel has its own copy');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id IN (:a, :b)', ['a' => self::$h[3], 'b' => self::$h[4]]));

        // Re-publish after a change: same ids, updated copies, unchanged file kept.
        DB::query("UPDATE chain_content_items SET title = 'Chain Breakfast v2', duration = 20 WHERE id = :id", ['id' => $cid]);
        $res = Chains::publish(self::$x, 'content', $cid, [1, self::$h[2]]);
        $this->assertSame(['updated', 'updated'], array_column($res, 'status'));
        $this->assertSame($pub, Chains::publications(self::$x, 'content', $cid));
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $pub[1]]);
        $this->assertSame(['Chain Breakfast v2', 20, $paths[1]], [$row['title'], (int) $row['duration'], $row['file_path']]);

        // New media file → copies replaced, old copies deleted.
        $file2 = self::chainImage(self::$x, 'logo-b.png', 0x00FF00);
        DB::query('UPDATE chain_content_items SET file_path = :f WHERE id = :id', ['f' => $file2, 'id' => $cid]);
        Chains::publish(self::$x, 'content', $cid, [1]);
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $pub[1]]);
        $this->assertNotSame($paths[1], $row['file_path']);
        $this->assertFileEquals(HC_ROOT . '/uploads/' . $file2, HC_ROOT . '/uploads/' . $row['file_path']);
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $paths[1]);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $paths[self::$h[2]], 'hotel 2 not re-published yet');

        // Playlist: items mapped to each hotel's copies; re-publish keeps the playlist id, updates order.
        $c2 = DB::insert('chain_content_items', ['chain_id' => self::$x, 'title' => 'Chain Welcome', 'type' => 'announcement', 'body' => 'Welcome!', 'settings' => '{}', 'duration' => 8, 'is_active' => 1]);
        $pl = DB::insert('chain_playlists', ['chain_id' => self::$x, 'name' => 'Chain Loop', 'transition' => 'fade']);
        DB::insert('chain_playlist_items', ['playlist_id' => $pl, 'content_id' => $cid, 'sort_order' => 0]);
        DB::insert('chain_playlist_items', ['playlist_id' => $pl, 'content_id' => $c2, 'sort_order' => 1, 'duration' => 5]);
        Chains::publish(self::$x, 'playlist', $pl, [1, self::$h[2]]);
        $plPub = Chains::publications(self::$x, 'playlist', $pl);
        foreach ($plPub as $hid => $localPl) {
            $items = Tenant::run($hid, static fn () => ContentManager::playlistItems($localPl));
            $this->assertSame(['Chain Breakfast v2', 'Chain Welcome'], array_column($items, 'title'));
            $this->assertSame([Chains::publications(self::$x, 'content', $cid)[$hid], Chains::publications(self::$x, 'content', $c2)[$hid]], array_map('intval', array_column($items, 'id')));
        }
        DB::query('UPDATE chain_playlist_items SET sort_order = 5 WHERE playlist_id = :p AND content_id = :c', ['p' => $pl, 'c' => $cid]);
        $res = Chains::publish(self::$x, 'playlist', $pl, [self::$h[2]]);
        $this->assertSame('updated', $res[self::$h[2]]['status']);
        $this->assertSame($plPub[self::$h[2]], $res[self::$h[2]]['local_id']);
        $items = Tenant::run(self::$h[2], static fn () => ContentManager::playlistItems($plPub[self::$h[2]]));
        $this->assertSame(['Chain Welcome', 'Chain Breakfast v2'], array_column($items, 'title'));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM content_playlists WHERE hotel_id = :h', ['h' => self::$h[2]]));

        // Isolation: publishing into a hotel of chain Y / an unchained hotel is refused before anything happens.
        foreach ([self::$h[3], self::$h[4]] as $foreign) {
            try {
                Chains::publish(self::$x, 'content', $cid, [1, $foreign]);
                $this->fail('publish into foreign hotel accepted');
            } catch (TenantException) {
                $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h', ['h' => $foreign]));
            }
        }
        // A chain Y admin cannot publish chain X items, nor into chain X hotels.
        Chains::actAs(self::user('cadminY'));
        $this->expectException(TenantException::class);
        Chains::publish(self::$x, 'content', $cid, [1]);
    }

    // ------------------------------------------------------------------ broadcast

    public function testChainBroadcastQueuesCommandsOnlyInChainHotels(): void
    {
        Chains::actAs(self::user('cadminX'));
        $cid = DB::insert('chain_content_items', ['chain_id' => self::$x, 'title' => 'Diwali offer', 'type' => 'announcement', 'body' => 'Offer', 'settings' => '{}', 'duration' => 10, 'is_active' => 1]);
        $before = (int) DB::value('SELECT COUNT(*) FROM device_commands');
        $foreignDevices = array_merge(self::$dev[self::$h[3]], self::$dev[self::$h[4]]);
        [$in, $p] = DB::in($foreignDevices, 'fd');

        [$params, $errors] = Chains::validateBroadcast(self::$x, 'push', ['source' => 'c:' . $cid]);
        $this->assertSame([], $errors);
        $res = Chains::broadcast(self::$x, [1, self::$h[2]], $params);
        $this->assertSame(['done', 'done'], array_column($res, 'status'));
        $this->assertSame([2, 1], array_column($res, 'devices'));
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM device_commands') - $before);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id IN $in", $p));
        $local1 = Chains::publications(self::$x, 'content', $cid)[1];
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = 1 AND content_id = :c', ['c' => $local1]));

        // Emergency + stop.
        [$params] = Chains::validateBroadcast(self::$x, 'emergency', ['title' => 'Fire drill', 'message' => 'Please stay calm']);
        Chains::broadcast(self::$x, [1, self::$h[2]], $params);
        $this->assertSame([1 => 1, self::$h[2] => 1], Chains::activeEmergencies(self::$x));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id IN (:a, :b) AND is_emergency = 1", ['a' => self::$h[3], 'b' => self::$h[4]]));
        [$params] = Chains::validateBroadcast(self::$x, 'emergency_stop', []);
        Chains::broadcast(self::$x, [1, self::$h[2]], $params);
        $this->assertSame([], Chains::activeEmergencies(self::$x));

        // Schedule (window, in each hotel's own context).
        [$params, $errors] = Chains::validateBroadcast(self::$x, 'schedule', ['source' => 'c:' . $cid, 'mode' => 'window', 'daily_start' => '18:00', 'daily_end' => '20:00', 'repeat_days' => [1, 2, 3]]);
        $this->assertSame([], $errors);
        Chains::broadcast(self::$x, [self::$h[2]], $params);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND mode = 'window' AND status IN ('scheduled','active')", ['h' => self::$h[2]]));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = 1 AND mode = 'window'"));
        [, $errors] = Chains::validateBroadcast(self::$x, 'schedule', ['source' => 'c:' . $cid, 'mode' => 'once']);
        $this->assertNotEmpty($errors, 'once needs a start time');

        // Isolation: a foreign hotel in the list → refused, nothing queued anywhere.
        $n = (int) DB::value('SELECT COUNT(*) FROM device_commands');
        [$params] = Chains::validateBroadcast(self::$x, 'emergency', ['title' => 'X']);
        foreach ([self::$h[3], self::$h[4]] as $foreign) {
            try {
                Chains::broadcast(self::$x, [1, $foreign], $params);
                $this->fail('broadcast into foreign hotel accepted');
            } catch (TenantException) {
                $this->assertSame($n, (int) DB::value('SELECT COUNT(*) FROM device_commands'));
            }
        }
        $this->assertSame([], Chains::activeEmergencies(self::$x));

        // Suspended hotel of the chain is skipped (read-only), the others still get it.
        Hotels::setStatus(self::$h[2], 'suspended', 'manual');
        [$params] = Chains::validateBroadcast(self::$x, 'push', ['source' => 'c:' . $cid]);
        $res = Chains::broadcast(self::$x, [1, self::$h[2]], $params);
        $this->assertSame(['done', 'skipped'], array_column($res, 'status'));
        Hotels::setStatus(self::$h[2], 'active');
        $this->assertSame(1, Tenant::id());
    }

    // ------------------------------------------------------------------ settings templates

    public function testSettingsTemplatePushedToSelectedHotels(): void
    {
        Chains::actAs(self::user('cadminX'));
        $chain = Chains::find(self::$x);
        [$data, $errors] = Chains::validateTemplate($chain, ['name' => 'Festive', 'groups' => ['ticker', 'overlay', 'branding'],
            'ticker_text' => 'CHAIN-TICKER', 'ticker_speed' => '7', 'ticker_bg_color' => '#112233', 'overlay_clock' => '1', 'overlay_clock_format' => 'HH:mm',
            'brand_name' => 'Alpha Hotels', 'brand_color' => '#aa0000']);
        $this->assertSame([], $errors);
        $tid = Chains::saveTemplate(self::$x, null, $data);
        $res = Chains::applyTemplate(self::$x, $tid, [1, self::$h[2]]);
        $this->assertSame(['done', 'done'], array_column($res, 'status'));
        Settings::flush();
        foreach ([1, self::$h[2]] as $hid) {
            $this->assertSame('CHAIN-TICKER', Settings::getFor($hid, 'ticker_text'));
            $this->assertSame('7', Settings::getFor($hid, 'ticker_speed'));
            $this->assertSame('HH:mm', Settings::getFor($hid, 'overlay_clock_format'));
            $this->assertSame(['Alpha Hotels', '#AA0000'], array_values(DB::one('SELECT brand_name, brand_color FROM hotels WHERE id = :h', ['h' => $hid])));
        }
        $this->assertSame('BETA-TICKER', Settings::getFor(self::$h[3], 'ticker_text'));
        $this->assertNull(DB::value('SELECT brand_name FROM hotels WHERE id = :h', ['h' => self::$h[3]]));
        $c = Tenant::run(self::$h[2], static fn () => ContentResolver::forRoom(DB::one('SELECT * FROM rooms WHERE hotel_id = :h LIMIT 1', ['h' => self::$h[2]])));
        $this->assertSame('CHAIN-TICKER', $c['overlay']['ticker']['text']);
        $this->assertSame('Alpha Hotels', $c['branding']['product']);
        // Chain Y template id / chain Y hotel are refused.
        Chains::actAs(self::user('cadminY'));
        try {
            Chains::applyTemplate(self::$x, $tid, [1]);
            $this->fail('foreign chain accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('CHAIN-TICKER', Settings::getFor(1, 'ticker_text'));
        Tenant::run(1, static fn () => Settings::set('ticker_text', ''));
    }

    // ------------------------------------------------------------------ HTTP: dashboard, enter, isolation

    public function testChainAdminDashboardShowsOnlyOwnHotels(): void
    {
        $s = self::sess('cadminX');
        [$st, , $html] = $s->get('index.php');
        $this->assertContains($st, [200, 302]);
        [$st, , $html] = $s->get('chain.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Alpha Palace', $html);
        $this->assertStringContainsString('Alpha Beach', $html);
        $this->assertStringNotContainsString('Beta Inn', $html);
        $this->assertStringNotContainsString('Solo Hotel', $html);
        $this->assertStringContainsString('chain_content.php', $html, 'chain menu');
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$st, , $html] = $s->get('chain.php?tab=report');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('data-chart="chPlays"', $html);
        $this->assertStringNotContainsString('Beta Inn', $html);
        [$st, , $csv, $head] = $s->get('chain.php?tab=report&csv=1');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('text/csv', $head);
        $this->assertStringContainsString('Alpha Beach', $csv);
        $this->assertStringNotContainsString('Beta Inn', $csv);
        [$st, $j] = $s->ajax('chain_overview&chain=' . self::$x);
        $this->assertSame(200, $st);
        $this->assertSame(2, $j['data']['totals']['hotels']);
        foreach (['chain_content.php', 'chain_broadcast.php', 'chain_content.php?action=new&type=announcement', 'chain_broadcast.php?tpl=new'] as $page) {
            [$st, , $html] = $s->get($page);
            $this->assertSame(200, $st, $page);
            $this->assertFalse(TestEnv::hasPhpError($html), $page);
        }
        // A chain admin without a hotel is sent to the chain dashboard from hotel pages.
        [$st, , , $head] = $s->get('rooms.php');
        $this->assertSame(302, $st);
        $this->assertStringContainsString('chain.php', $head);
    }

    public function testChainAdminIsolationOverHttp(): void
    {
        $s = self::sess('cadminX');
        // Other chain / no chain: dashboard, ajax, report filter.
        $this->assertDenied($s->get('chain.php?chain=' . self::$y)[0], 'chain Y dashboard');
        $this->assertDenied($s->ajax('chain_overview&chain=' . self::$y)[0], 'chain Y ajax');
        $this->assertDenied($s->get('chain.php?tab=report&hotels[]=' . self::$h[3])[0], 'report with chain Y hotel');
        $this->assertDenied($s->get('chain_content.php?chain=' . self::$y)[0], 'chain Y library');
        // Enter a foreign hotel.
        foreach ([self::$h[3], self::$h[4]] as $foreign) {
            $this->assertDenied($s->post('chain.php', ['op' => 'enter', 'hotel_id' => $foreign])[0], 'enter hotel ' . $foreign);
        }
        [$st, , , $head] = $s->get('rooms.php');
        $this->assertSame(302, $st, 'still no hotel context');
        // Chain Y library items / templates.
        $yItem = DB::insert('chain_content_items', ['chain_id' => self::$y, 'title' => 'BETA-SECRET', 'type' => 'announcement', 'body' => 'x', 'settings' => '{}', 'duration' => 5, 'is_active' => 1]);
        $yTpl = DB::insert('chain_setting_templates', ['chain_id' => self::$y, 'name' => 'BETA-TPL', 'settings' => json_encode(['groups' => ['ticker'], 'values' => ['ticker_text' => 'HACKED']])]);
        $this->assertDenied($s->get('chain_content.php?action=edit&id=' . $yItem)[0], 'edit chain Y item');
        $this->assertDenied($s->get('chain_content.php?action=publish&type=content&id=' . $yItem)[0], 'publish page of chain Y item');
        $this->assertDenied($s->post('chain_content.php', ['op' => 'publish', 'type' => 'content', 'id' => $yItem, 'hotels' => [1]])[0], 'publish chain Y item');
        $this->assertDenied($s->post('chain_content.php', ['op' => 'delete_content', 'id' => $yItem])[0], 'delete chain Y item');
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM chain_content_items WHERE id = :id', ['id' => $yItem]));
        $this->assertDenied($s->post('chain_broadcast.php', ['op' => 'apply_template', 'template_id' => $yTpl, 'hotels' => [1]])[0], 'apply chain Y template');
        $this->assertNotSame('HACKED', Settings::getFor(1, 'ticker_text'));

        // Publish / broadcast / template into a hotel of chain Y or an unchained hotel.
        $xItem = DB::insert('chain_content_items', ['chain_id' => self::$x, 'title' => 'X-ITEM', 'type' => 'announcement', 'body' => 'x', 'settings' => '{}', 'duration' => 5, 'is_active' => 1]);
        $cmds = (int) DB::value('SELECT COUNT(*) FROM device_commands');
        foreach ([self::$h[3], self::$h[4]] as $foreign) {
            $this->assertDenied($s->post('chain_content.php', ['op' => 'publish', 'type' => 'content', 'id' => $xItem, 'hotels' => [1, $foreign]])[0], 'publish into ' . $foreign);
            $this->assertDenied($s->post('chain_broadcast.php', ['op' => 'broadcast', 'kind' => 'emergency', 'title' => 'HACK', 'hotels' => [$foreign]])[0], 'emergency into ' . $foreign);
            $this->assertDenied($s->post('chain_broadcast.php', ['op' => 'broadcast', 'kind' => 'push', 'source' => 'c:' . $xItem, 'hotels' => [1, $foreign]])[0], 'push into ' . $foreign);
            $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND title = 'X-ITEM'", ['h' => $foreign]));
            $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND title = 'HACK'", ['h' => $foreign]));
        }
        $this->assertSame($cmds, (int) DB::value('SELECT COUNT(*) FROM device_commands'), 'nothing queued');
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title = 'X-ITEM'"), 'the valid hotel in a refused list got nothing either');
        // Platform pages are not for chain admins.
        $this->assertDenied($s->get('platform_chains.php')[0], 'platform chains');
        $this->assertDenied($s->get('platform_hotels.php')[0], 'platform hotels');
    }

    public function testEnterHotelActsAsSuperAdminWithBannerAndBackLink(): void
    {
        $s = self::sess('cadminX');
        [$st, , , $head] = $s->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[2]]);
        $this->assertSame(302, $st);
        $this->assertStringContainsString('index.php', $head);
        [$st, , $html] = $s->get('rooms.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('B201', $html);
        $this->assertStringNotContainsString('A101', $html);
        $this->assertStringNotContainsString('C301', $html);
        $this->assertStringContainsString('hc-context-banner', $html);
        $this->assertStringContainsString('Back to chain', $html);
        [$st] = $s->get('settings.php');
        $this->assertSame(200, $st, 'acts as super admin');
        [$st] = $s->get('users.php');
        $this->assertSame(200, $st);
        // Session cannot be pointed at a foreign hotel, the context stays.
        $this->assertDenied($s->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[3]])[0], 'switch to chain Y hotel');
        [, , $html] = $s->get('rooms.php');
        $this->assertStringContainsString('B201', $html);
        $this->assertStringNotContainsString('C301', $html);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'hotel_enter'", ['h' => self::$h[2]]));
        // Leave → back on the chain dashboard, no hotel context.
        [$st, , , $head] = $s->post('chain.php', ['op' => 'leave']);
        $this->assertSame(302, $st);
        $this->assertStringContainsString('chain.php', $head);
        [$st] = $s->get('rooms.php');
        $this->assertSame(302, $st);
    }

    public function testHotelUsersNeverSeeChainPages(): void
    {
        foreach (['mgrX1', 'bossX2', 'bossY'] as $u) {
            $s = self::sess($u);
            foreach (['chain.php', 'chain_content.php', 'chain_broadcast.php', 'chain.php?chain=' . self::$x] as $page) {
                [$st, , $html] = $s->get($page);
                $this->assertDenied($st, "$u $page");
                $this->assertStringNotContainsString('Alpha Beach', $html);
            }
            $this->assertDenied($s->ajax('chain_overview&chain=' . self::$x)[0], "$u ajax");
            $this->assertDenied($s->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[2], 'chain' => self::$x])[0], "$u enter");
            [, , $html] = $s->get('index.php');
            $this->assertStringNotContainsString('chain.php', $html, "$u menu");
            $this->assertDenied($s->get('platform_chains.php')[0], "$u platform chains");
        }
        [, , $html] = self::sess('mgrX1')->get('rooms.php');
        $this->assertStringNotContainsString('B201', $html);
    }

    public function testSuperAdminWithChainAccess(): void
    {
        $s = self::sess('bossX1');
        [$st, , $html] = $s->get('chain.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Alpha Beach', $html);
        $this->assertStringNotContainsString('Beta Inn', $html);
        [$st] = $s->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[2]]);
        $this->assertSame(302, $st);
        [, , $html] = $s->get('rooms.php');
        $this->assertStringContainsString('B201', $html);
        $this->assertStringContainsString('Back to chain', $html);
        $this->assertDenied($s->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[4]])[0], 'enter unchained hotel');
        $s->post('chain.php', ['op' => 'leave']);
        [, , $html] = $s->get('rooms.php');
        $this->assertStringContainsString('A101', $html, 'back in own hotel');
        $this->assertStringNotContainsString('B201', $html);
    }

    public function testUploadAndPublishOverHttp(): void
    {
        $s = self::sess('cadminX');
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $im = imagecreatetruecolor(20, 20);
        imagefill($im, 0, 0, 0x3366CC);
        imagepng($im, $tmp);
        [$st, , , $head] = TestEnv::http('POST', self::$url . 'admin/chain_content.php', null, [], $s->jar, [
            '_csrf' => $s->csrf, 'op' => 'save_content', 'type' => 'image', 'title' => 'HTTP Poster', 'duration' => '9', 'is_active' => '1',
            'file' => new CURLFile($tmp, 'image/png', 'poster.png'),
        ]);
        @unlink($tmp);
        $this->assertSame(302, $st);
        $item = DB::one("SELECT * FROM chain_content_items WHERE title = 'HTTP Poster'");
        $this->assertNotNull($item);
        $this->assertSame(self::$x, (int) $item['chain_id']);
        $this->assertStringStartsWith('chains/c' . self::$x . '/media/', $item['file_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $item['file_path']);
        $this->assertStringContainsString('action=publish', $head);

        [$st] = $s->post('chain_content.php', ['op' => 'publish', 'type' => 'content', 'id' => $item['id'], 'hotels' => [1, self::$h[2]]]);
        $this->assertSame(302, $st);
        $pub = Chains::publications(self::$x, 'content', (int) $item['id']);
        $this->assertCount(2, $pub, (string) DB::value('SELECT results FROM chain_actions ORDER BY id DESC LIMIT 1'));
        foreach ($pub as $hid => $lid) {
            $row = DB::one('SELECT * FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $lid, 'h' => $hid]);
            $this->assertStringStartsWith('h' . $hid . '/media/', $row['file_path']);
            $this->assertFileExists(HC_ROOT . '/uploads/' . $row['file_path']);
        }
        [, , $html] = $s->get('chain_content.php?action=publish&type=content&id=' . $item['id']);
        $this->assertStringContainsString('#' . $pub[1], $html);

        // Chain broadcast over HTTP: push now to both hotels.
        [$st] = $s->post('chain_broadcast.php', ['op' => 'broadcast', 'kind' => 'push', 'source' => 'c:' . $item['id'], 'hotels' => [1, self::$h[2]]]);
        $this->assertSame(302, $st);
        $this->assertSame(2, (int) DB::value("SELECT COUNT(DISTINCT hotel_id) FROM broadcast_commands WHERE title = 'HTTP Poster'"));
        [, , $html] = $s->get('chain_broadcast.php');
        $this->assertStringContainsString('HTTP Poster', $html, 'history');
    }

    public function testPlatformAdminManagesChains(): void
    {
        $s = self::sess('padmin');
        [$st, , , $head] = $s->post('platform_chains.php', ['op' => 'save', 'name' => 'Gamma Group', 'owner_name' => 'G Owner', 'owner_email' => 'g@g.test']);
        $this->assertSame(302, $st);
        $gid = (int) DB::value("SELECT id FROM hotel_chains WHERE name = 'Gamma Group'");
        $this->assertGreaterThan(0, $gid);
        $s->post('platform_chains.php', ['op' => 'add_hotel', 'id' => $gid, 'hotel_id' => self::$h[4]]);
        $this->assertSame($gid, (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => self::$h[4]]));
        // A hotel of another chain cannot be moved silently.
        $s->post('platform_chains.php', ['op' => 'add_hotel', 'id' => $gid, 'hotel_id' => self::$h[3]]);
        $this->assertSame(self::$y, (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => self::$h[3]]));
        $s->post('platform_chains.php', ['op' => 'add_admin', 'id' => $gid, 'admin_username' => 'gadmin', 'admin_email' => 'gadmin@g.test', 'admin_password' => 'Passw0rd!', 'admin_name' => 'G Admin']);
        $g = self::user('gadmin');
        $this->assertSame(['chain_admin', $gid, null], [$g['role'], (int) $g['chain_id'], $g['hotel_id']]);
        [$st, , $html] = $s->get('platform_chains.php?action=view&id=' . $gid);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Solo Hotel', $html);
        [$st, , $html] = $s->get('platform_chains.php');
        $this->assertStringContainsString('Chain X', $html);
        [$st, , $html] = $s->get('chain.php?chain=' . $gid);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Solo Hotel', $html);
        [$st, , $html] = $s->get('platform_hotels.php?action=view&id=' . self::$h[4]);
        $this->assertStringContainsString('Gamma Group', $html);

        // The new chain admin can log in and sees exactly that hotel.
        $ga = self::sess('gadmin');
        [$st, , $html] = $ga->get('chain.php');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Solo Hotel', $html);
        $this->assertStringNotContainsString('Alpha Beach', $html);

        // Grant / revoke chain access for a super admin of a chain hotel; a foreign user is refused.
        $s->post('platform_chains.php', ['op' => 'grant', 'id' => self::$x, 'user_id' => self::$u['bossX2']]);
        $this->assertSame(self::$x, (int) self::user('bossX2')['chain_id']);
        $s->post('platform_chains.php', ['op' => 'revoke', 'id' => self::$x, 'user_id' => self::$u['bossX2']]);
        $this->assertNull(self::user('bossX2')['chain_id']);
        $this->assertDenied($s->post('platform_chains.php', ['op' => 'grant', 'id' => self::$x, 'user_id' => self::$u['bossY']])[0], 'grant to chain Y super admin');
        $this->assertNull(self::user('bossY')['chain_id']);

        // Removing the hotel from the chain ends the chain admin's access.
        $s->post('platform_hotels.php', ['op' => 'set_chain', 'id' => self::$h[4], 'chain_id' => 0]);
        $this->assertNull(DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => self::$h[4]]));
        $this->assertDenied($ga->post('chain.php', ['op' => 'enter', 'hotel_id' => self::$h[4]])[0], 'enter after removal');
        $this->assertSame('', TestEnv::phpErrors(), 'no PHP warnings');
    }

    public function testResellerOnlyOwnChainsAndHotels(): void
    {
        $rid = DB::insert('resellers', ['name' => 'Res One', 'status' => 'active', 'created_at' => now()]);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => $rid, 'username' => 'resone', 'email' => 'resone@t.test', 'full_name' => 'Res One',
            'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'reseller', 'is_active' => 1, 'created_at' => now()]);
        DB::query('UPDATE hotels SET reseller_id = :r, chain_id = NULL WHERE id = :h', ['r' => $rid, 'h' => self::$h[4]]);
        $s = self::sess('resone');
        $this->assertDenied($s->get('chain.php?chain=' . self::$x)[0], 'reseller opens a platform chain');
        $s->post('platform_chains.php', ['op' => 'save', 'name' => 'Res Chain', 'reseller_id' => '']);
        $rc = DB::one("SELECT * FROM hotel_chains WHERE name = 'Res Chain'");
        $this->assertSame($rid, (int) $rc['reseller_id'], 'reseller chain is forced to the reseller');
        $this->assertDenied($s->post('platform_chains.php', ['op' => 'add_hotel', 'id' => $rc['id'], 'hotel_id' => 1])[0], 'add a hotel of someone else');
        $this->assertSame(self::$x, (int) DB::value('SELECT chain_id FROM hotels WHERE id = 1'));
        $this->assertDenied($s->post('platform_chains.php', ['op' => 'add_hotel', 'id' => self::$x, 'hotel_id' => self::$h[4]])[0], 'add to a platform chain');
        $s->post('platform_chains.php', ['op' => 'add_hotel', 'id' => $rc['id'], 'hotel_id' => self::$h[4]]);
        $this->assertSame((int) $rc['id'], (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => self::$h[4]]));
        [$st, , $html] = $s->get('chain.php?chain=' . $rc['id']);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Solo Hotel', $html);
        [$st] = $s->post('chain.php', ['op' => 'enter', 'chain' => $rc['id'], 'hotel_id' => self::$h[4]]);
        $this->assertSame(302, $st);
        [, , $html] = $s->get('rooms.php');
        $this->assertStringContainsString('D401', $html);
        $this->assertStringContainsString('Back to chain', $html);
        $s->post('chain.php', ['op' => 'leave', 'chain' => $rc['id']]);
        // The platform cannot put another reseller's / direct hotel into the reseller's chain.
        self::sess('padmin')->post('platform_chains.php', ['op' => 'add_hotel', 'id' => $rc['id'], 'hotel_id' => self::$h[2]]);
        $this->assertSame(self::$x, (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => self::$h[2]]));
        $this->assertSame('', TestEnv::phpErrors(), 'no PHP warnings');
    }
}
