<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Platform screens (docs/modules/platform_screens.md): Platform → All screens across customers
 * (reseller limited to their own customers), filters / pagination / CSV, bulk commands in each
 * customer's context, moving a TV between customers (the TV keeps its token and its next poll
 * returns the new customer's content; the old customer no longer lists it; the screen limit is
 * enforced), the unassigned pool (platform registration key, waiting screen, assign / unassign),
 * the customer detail tabs, the customers list / dashboard additions, tenancy (403) and CSRF.
 */
final class PlatformScreensTest extends TestCase
{
    private static string $url;
    /** Customers: 1 = My Hotel (direct), alpha / beta (reseller R1), gamma (reseller R2, max 1 TV). */
    private static array $h = [];
    private static array $room = [];
    /** @var array<string, array{uid:string, token:string, id:int, hotel:int}> */
    private static array $tv = [];
    private static array $sessions = [];
    private static int $r1 = 0;
    private static int $r2 = 0;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        self::$r1 = DB::insert('resellers', ['name' => 'Reseller One', 'status' => 'active']);
        self::$r2 = DB::insert('resellers', ['name' => 'Reseller Two', 'status' => 'active']);
        DB::insert('users', ['hotel_id' => null, 'username' => 'psroot', 'email' => 'psroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => self::$r1, 'username' => 'psres1', 'email' => 'psres1@res.test', 'full_name' => 'Res One', 'password_hash' => $pw, 'role' => 'reseller']);
        self::$h['my'] = 1;
        Settings::set('hotel_name', 'My Hotel');
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Inn', 'reseller_id' => self::$r1]);
        self::$h['beta'] = Hotels::create(['name' => 'Beta Suites', 'reseller_id' => self::$r1]);
        self::$h['gamma'] = Hotels::create(['name' => 'Gamma Lodge', 'reseller_id' => self::$r2, 'max_tvs' => 1]);
        foreach (['my' => ['101', '102'], 'alpha' => ['A1', 'A2'], 'beta' => ['B1'], 'gamma' => ['G1']] as $k => $nums) {
            Tenant::run(self::$h[$k], static function () use ($k, $nums): void {
                foreach ($nums as $n) {
                    self::$room[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Screen ' . $n, 'floor' => '1']);
                }
            });
        }
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphaboss', 'email' => 'boss@alpha.test', 'password' => 'Passw0rd!'], 'super_admin');
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphastaff', 'email' => 'staff@alpha.test', 'password' => 'Passw0rd!'], 'staff');
        Hotels::createHotelUser(self::$h['beta'], ['username' => 'betaboss', 'email' => 'boss@beta.test', 'password' => 'Passw0rd!'], 'super_admin');
        // The test server must not run background tasks that change device status.
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        foreach (['tv101' => ['101', 'my'], 'tv102' => ['102', 'my'], 'tvA1' => ['A1', 'alpha'], 'tvA2' => ['A2', 'alpha'], 'tvB1' => ['B1', 'beta'], 'tvG1' => ['G1', 'gamma']] as $name => [$room, $hk]) {
            self::register($name, $room, $hk);
        }
    }

    public static function tearDownAfterClass(): void
    {
        PlatformScreens::$userOverride = null;
        PlatformScreens::forget();
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        PlatformScreens::$userOverride = null;
        PlatformScreens::forget();
    }

    private static function register(string $name, string $room, string $hotelKey, array $extra = []): array
    {
        $key = (string) Settings::getFor(self::$h[$hotelKey], 'registration_key');
        $uid = 'tv-ps-' . strtolower($name) . '-0001';
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', $extra + ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key, 'app_version' => '2.4.0', 'app_version_code' => 11, 'model' => 'Model ' . $name]);
        self::assertSame(200, $s, (string) json_encode($j));
        $hid = self::$h[$hotelKey];
        return self::$tv[$name] = ['uid' => $uid, 'token' => $j['data']['token'], 'hotel' => $hid,
            'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hid])];
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    /** GET /api/device/command for a TV: [status, data]. */
    private static function poll(string $uid, string $token, string $hash = 'x'): array
    {
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . $uid . '?hash=' . $hash, null, ['Authorization: Bearer ' . $token, 'X-Device-Id: ' . $uid]);
        return [$s, $j['data'] ?? $j];
    }

    private static function deviceRow(string $name): array
    {
        return (array) DB::one('SELECT * FROM devices WHERE id = :id', ['id' => self::$tv[$name]['id']]);
    }

    private static function flashOf(AdminSession $s, string $page): string
    {
        [, , $html] = $s->get($page);
        return $html;
    }

    // ------------------------------------------------------------------ listing / scope / tenancy

    public function testAllScreensListsEveryCustomerAndResellerOnlyOwn(): void
    {
        [$s, , $html] = self::as('psroot')->get('platform_screens.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), 'PHP error on All screens');
        foreach (self::$tv as $t) {
            $this->assertStringContainsString($t['uid'], $html);
        }
        foreach (['Alpha Inn', 'Beta Suites', 'Gamma Lodge', 'My Hotel', 'Unassigned pool'] as $txt) {
            $this->assertStringContainsString($txt, $html);
        }
        $this->assertStringContainsString('psBulkForm', $html);

        // Reseller One: only Alpha + Beta TVs, no pool, no foreign customer even with ?customer=.
        $res = self::as('psres1');
        [$s, , $html] = $res->get('platform_screens.php?customer=' . self::$h['gamma']);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach (['tvA1', 'tvA2', 'tvB1'] as $n) {
            $this->assertStringContainsString(self::$tv[$n]['uid'], $html);
        }
        foreach (['tv101', 'tv102', 'tvG1'] as $n) {
            $this->assertStringNotContainsString(self::$tv[$n]['uid'], $html, "$n must be hidden from reseller");
        }
        $this->assertStringNotContainsString('Gamma Lodge', $html);
        $this->assertStringNotContainsString('Unassigned pool', $html);
        // Reseller menu entry.
        [, , $html] = $res->get('reseller.php');
        $this->assertStringContainsString('platform_screens.php', $html);

        // Core API with the reseller scope.
        $u = DB::one("SELECT * FROM users WHERE username = 'psres1'");
        PlatformScreens::$userOverride = $u;
        $this->assertEqualsCanonicalizing([self::$h['alpha'], self::$h['beta']], PlatformScreens::scopeHotelIds());
        $c = PlatformScreens::counters(PlatformScreens::scopeHotelIds());
        $this->assertSame(3, $c['total']);
        $this->assertSame(0, $c['pool']);
        $this->assertFalse(PlatformScreens::canSeeHotel(self::$h['gamma']));
        try {
            PlatformScreens::bulkCommand([self::$tv['tvG1']['id']], 'PING');
            $this->fail('foreign device accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        PlatformScreens::$userOverride = null;

        // Customer detail of a foreign customer → 404; own → 200.
        [$s] = $res->get('platform_customer.php?id=' . self::$h['gamma']);
        $this->assertSame(404, $s);
        [$s] = $res->get('platform_customer.php?id=1');
        $this->assertSame(404, $s);
        [$s] = $res->get('platform_customer.php?id=' . self::$h['alpha']);
        $this->assertSame(200, $s);
        // Reseller cannot use pool actions (platform admins only).
        [$s] = $res->post('platform_screens.php', ['op' => 'pool_toggle', 'enabled' => 1]);
        $this->assertSame(403, $s);
        $this->assertSame('0', (string) Settings::platform('platform_pool_enabled', '0'));

        // Tenancy: a customer admin / staff gets 403 on every platform screens page.
        foreach (['alphaboss', 'alphastaff'] as $name) {
            foreach (['platform_screens.php', 'platform_customer.php?id=' . self::$h['alpha'], 'platform_screens.php?action=rooms&customer=' . self::$h['alpha']] as $page) {
                [$s, , $body] = self::as($name)->get($page);
                $this->assertSame(403, $s, "$page as $name");
                $this->assertStringNotContainsString(self::$tv['tvB1']['uid'], $body);
            }
            [$s] = self::as($name)->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'cmd:REBOOT', 'ids' => [self::$tv['tvB1']['id']]]);
            $this->assertSame(403, $s);
        }
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'REBOOT'"));

        // Screens of a customer (move dialog JSON): reseller → own only.
        [$s, $j] = $res->get('platform_screens.php?action=rooms&customer=' . self::$h['beta']);
        $this->assertSame(200, $s);
        $this->assertSame('B1', substr($j['data']['screens'][0]['label'], 0, 2));
        [$s] = $res->get('platform_screens.php?action=rooms&customer=' . self::$h['gamma']);
        $this->assertSame(404, $s);
    }

    public function testFiltersPaginationAndCsv(): void
    {
        // Offline TV, outdated app (APK v12 in Alpha), health warning, a TV without screen, a web player.
        DB::query("UPDATE devices SET status = 'offline' WHERE id = :id", ['id' => self::$tv['tv102']['id']]);
        // Alpha's newest APK is v12, My Hotel's own newest is v11 (its TVs are current); Beta / Gamma have
        // no APK → compared with the platform's newest (v12).
        Tenant::run(self::$h['alpha'], static fn () => DB::insert('apk_releases', ['version_name' => '2.5.0', 'version_code' => 12, 'file_path' => 'apk/x.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => now()]));
        Tenant::run(1, static fn () => DB::insert('apk_releases', ['version_name' => '2.4.0', 'version_code' => 11, 'file_path' => 'apk/w.apk', 'file_size' => 1, 'sha256' => str_repeat('c', 64), 'created_at' => now()]));
        DB::query('UPDATE devices SET health = :j, health_at = NOW() WHERE id = :id', ['j' => json_encode(['storage_free_mb' => 120]), 'id' => self::$tv['tvB1']['id']]);
        self::register('tvW1', '101', 'my', ['platform' => 'web', 'app_version' => 'web-2.4', 'app_version_code' => 1]);
        PlatformScreens::forget();

        $ids = static function (array $f): array {
            $res = PlatformScreens::list(PlatformScreens::filters($f), null);
            return array_map(static fn ($r) => (string) $r['device_uid'], $res['rows']);
        };
        $uid = static fn (string $n) => self::$tv[$n]['uid'];
        $this->assertSame([$uid('tv102')], $ids(['status' => 'offline']));
        $this->assertSame([$uid('tvW1')], $ids(['platform' => 'web']));
        $this->assertEqualsCanonicalizing([$uid('tvA1'), $uid('tvA2'), $uid('tvB1'), $uid('tvG1')], $ids(['update' => '1']), 'v11 < customer\'s / platform\'s newest; web players never "need update"');
        $this->assertSame([$uid('tvB1')], $ids(['warn' => '1']));
        $this->assertEqualsCanonicalizing([$uid('tvA1'), $uid('tvA2')], $ids(['customer' => (string) self::$h['alpha']]));
        $this->assertSame([$uid('tvW1')], $ids(['version' => 'web-2.4']));
        $this->assertSame([$uid('tvG1')], $ids(['q' => 'Gamma']));
        $c = PlatformScreens::counters(null);
        $this->assertSame(7, $c['total']);
        $this->assertSame(1, $c['offline']);
        $this->assertSame(4, $c['outdated']);
        $this->assertSame(1, $c['warnings']);

        // Unassigned (registered, no screen).
        DB::query('UPDATE devices SET room_id = NULL WHERE id = :id', ['id' => self::$tv['tvW1']['id']]);
        $this->assertSame([$uid('tvW1')], $ids(['unassigned' => '1']));

        // Pagination: 60 more TVs in My Hotel → 25 per page.
        Tenant::run(1, static function (): void {
            for ($i = 1; $i <= 60; $i++) {
                DB::insert('devices', ['device_uid' => sprintf('tv-ps-bulk-%04d', $i), 'room_id' => self::$room['101'], 'token_hash' => hash('sha256', 'bulk' . $i), 'status' => 'online', 'registered_at' => now()]);
            }
        });
        $p1 = PlatformScreens::list(PlatformScreens::filters(['per_page' => '25', 'customer' => '1']), null);
        $p3 = PlatformScreens::list(PlatformScreens::filters(['per_page' => '25', 'customer' => '1', 'page' => '3']), null);
        $this->assertSame(63, $p1['total']);
        $this->assertSame(3, $p1['pages']);
        $this->assertCount(25, $p1['rows']);
        $this->assertCount(13, $p3['rows']);
        $this->assertSame([], array_intersect(array_column($p1['rows'], 'id'), array_column($p3['rows'], 'id')));
        [$s, , $html] = self::as('psroot')->get('platform_screens.php?customer=1&per_page=25&page=2');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('pagination', $html);
        $this->assertSame(25, substr_count($html, 'class="form-check-input ps-cb"'));

        // CSV: every matching row, header, CSV-injection safe.
        [$s, , $csv, $head] = self::as('psroot')->get('platform_screens.php?status=any&export=csv');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('text/csv', $head);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame(1 + 7 + 60, count($lines));
        $this->assertStringContainsString('customer_id,customer,screen_id', $lines[0]);
        $this->assertStringContainsString('Storage low', $csv);
        [, , $csv] = self::as('psres1')->get('platform_screens.php?export=csv');
        $this->assertStringNotContainsString('tv-ps-bulk-', $csv, 'reseller CSV limited to own customers');
        $this->assertStringNotContainsString($uid('tvG1'), $csv);
        $this->assertStringContainsString($uid('tvB1'), $csv);

        // Clean up: back to the starting state.
        DB::query("DELETE FROM devices WHERE device_uid LIKE 'tv-ps-bulk-%'");
        DB::query('DELETE FROM devices WHERE id = :id', ['id' => self::$tv['tvW1']['id']]);
        unset(self::$tv['tvW1']);
        DB::query("UPDATE devices SET status = 'online' WHERE id = :id", ['id' => self::$tv['tv102']['id']]);
        DB::query('UPDATE devices SET health = NULL, health_at = NULL WHERE id = :id', ['id' => self::$tv['tvB1']['id']]);
        DB::query('DELETE FROM apk_releases');
    }

    // ------------------------------------------------------------------ commands

    public function testBulkCommandRunsPerCustomerContext(): void
    {
        $before = (int) DB::value('SELECT MAX(id) FROM broadcast_commands') ?: 0;
        [$s] = self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'cmd:RELOAD', 'ids' => [self::$tv['tv101']['id'], self::$tv['tvA1']['id'], self::$tv['tvB1']['id']]]);
        $this->assertSame(302, $s);
        $b = DB::all("SELECT hotel_id, target_type, target_ids FROM broadcast_commands WHERE id > :id AND command = 'RELOAD' ORDER BY hotel_id", ['id' => $before]);
        $this->assertSame([1, self::$h['alpha'], self::$h['beta']], array_map(static fn ($r) => (int) $r['hotel_id'], $b), 'one broadcast per customer, in that customer');
        $this->assertSame('rooms', $b[1]['target_type']);
        $this->assertSame([self::$room['A1']], json_decode((string) $b[1]['target_ids'], true));
        foreach (['tv101', 'tvA1', 'tvB1'] as $n) {
            [$s, $d] = self::poll(self::$tv[$n]['uid'], self::$tv[$n]['token']);
            $this->assertSame(200, $s);
            $this->assertContains('RELOAD', array_column($d['commands'], 'command'), $n);
        }
        [, $d] = self::poll(self::$tv['tvA2']['uid'], self::$tv['tvA2']['token']);
        $this->assertNotContains('RELOAD', array_column($d['commands'], 'command'), 'not selected');
        $this->assertGreaterThanOrEqual(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'platform_command'", ['h' => self::$h['alpha']]));
        $this->assertGreaterThanOrEqual(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id IS NULL AND action = 'platform_command'"));

        // Row command (single TV) and the whitelist: SHOW_MESSAGE / LIVE_VIEW are refused.
        [$s] = self::as('psroot')->post('platform_screens.php', ['op' => 'row', 'row' => 'cmd:PING:' . self::$tv['tvA2']['id']]);
        $this->assertSame(302, $s);
        [, $d] = self::poll(self::$tv['tvA2']['uid'], self::$tv['tvA2']['token']);
        $this->assertContains('PING', array_column($d['commands'], 'command'));
        foreach (['cmd:SHOW_MESSAGE', 'cmd:LIVE_VIEW', 'cmd:EMERGENCY'] as $bad) {
            self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => $bad, 'ids' => [self::$tv['tvA2']['id']]]);
        }
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command IN ('SHOW_MESSAGE', 'LIVE_VIEW', 'EMERGENCY')"));
        $this->assertStringContainsString('Unknown command', self::flashOf(self::as('psroot'), 'platform_screens.php'));

        // Reseller: own TVs work, a foreign TV in the same request → nothing is sent.
        $n = (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SCREEN_ON'");
        self::as('psres1')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'cmd:SCREEN_ON', 'ids' => [self::$tv['tvA1']['id'], self::$tv['tvG1']['id']]]);
        $this->assertSame($n, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SCREEN_ON'"));
        self::as('psres1')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'cmd:SCREEN_ON', 'ids' => [self::$tv['tvA1']['id']]]);
        $this->assertSame($n + 1, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SCREEN_ON'"));

        // App update: only customers with an APK; Alpha gets its newest APK.
        $apk = Tenant::run(self::$h['alpha'], static fn () => DB::insert('apk_releases', ['version_name' => '2.5.1', 'version_code' => 13, 'file_path' => 'apk/y.apk', 'file_size' => 1, 'sha256' => str_repeat('b', 64), 'created_at' => now()]));
        self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'update', 'ids' => [self::$tv['tvA1']['id'], self::$tv['tvB1']['id']]]);
        $p = json_decode((string) DB::value("SELECT payload FROM device_commands WHERE device_id = :d AND command = 'UPDATE_APP' ORDER BY id DESC LIMIT 1", ['d' => self::$tv['tvA1']['id']]), true);
        $this->assertSame(13, $p['version_code']);
        $this->assertStringEndsWith('api/device/apk/' . $apk, $p['url']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d AND command = 'UPDATE_APP'", ['d' => self::$tv['tvB1']['id']]));
        $this->assertStringContainsString('Beta Suites', self::flashOf(self::as('psroot'), 'platform_screens.php'), 'warning names the customer without APK');
        DB::query('DELETE FROM apk_releases');
    }

    // ------------------------------------------------------------------ move

    public function testMoveTvToAnotherCustomer(): void
    {
        $tv = self::$tv['tvA2'];
        [$s, $d] = self::poll($tv['uid'], $tv['token']);
        $this->assertSame(self::$h['alpha'], $d['content']['hotel']['id']);
        $oldHash = $d['content_hash'];
        // Pending command + live view session of the old customer.
        Tenant::run(self::$h['alpha'], static function () use ($tv): void {
            DB::insert('device_commands', ['device_id' => $tv['id'], 'command' => 'REBOOT', 'payload' => '{}', 'status' => 'pending', 'created_at' => now()]);
            DB::insert('device_live_views', ['device_id' => $tv['id'], 'token' => str_repeat('c', 32), 'started_at' => now(), 'keepalive_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + 120), 'frames' => 0]);
            DB::insert('device_health_history', ['device_id' => $tv['id'], 'storage_free_mb' => 100, 'created_at' => now()]);
        });
        DB::query('UPDATE devices SET health_alerts = :a WHERE id = :id', ['a' => '{"storage_low":1}', 'id' => $tv['id']]);

        [$s] = self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id']],
            'target_customer' => self::$h['beta'], 'screen_mode' => 'existing', 'target_room' => self::$room['B1']]);
        $this->assertSame(302, $s);
        $row = self::deviceRow('tvA2');
        $this->assertSame(self::$h['beta'], (int) $row['hotel_id']);
        $this->assertSame(self::$room['B1'], (int) $row['room_id']);
        $this->assertSame(hash('sha256', $tv['token']), $row['token_hash'], 'token kept');
        $this->assertNull($row['health_alerts']);
        // 2.5 security review: the old customer's commands are removed (device_commands has no hotel_id, they
        // would show on the new customer's TV page).
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_commands WHERE device_id = :d', ['d' => $tv['id']]));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_live_views WHERE device_id = :d', ['d' => $tv['id']]));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_health_history WHERE device_id = :d', ['d' => $tv['id']]));
        // Next poll with the SAME token: the new customer's content, no commands of the old one.
        [$s, $d] = self::poll($tv['uid'], $tv['token'], $oldHash);
        $this->assertSame(200, $s);
        $this->assertTrue($d['content_changed']);
        $this->assertSame(self::$h['beta'], $d['content']['hotel']['id']);
        $this->assertSame('Beta Suites', $d['content']['hotel']['name']);
        $this->assertSame('B1', $d['content']['room']['number']);
        $this->assertSame([], $d['commands']);
        // Heartbeat works too (device API in the new context).
        [$s] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', ['app_version' => '2.4.0'], ['Authorization: Bearer ' . $tv['token'], 'X-Device-Id: ' . $tv['uid']]);
        $this->assertSame(200, $s);
        self::$tv['tvA2']['hotel'] = self::$h['beta'];
        // Old customer's pages no longer show it, the new customer's do.
        [, , $html] = self::as('alphaboss')->get('rooms.php');
        $this->assertStringNotContainsString($tv['uid'], $html);
        [, , $html] = self::as('alphaboss')->get('rooms.php?action=devices');
        $this->assertStringNotContainsString($tv['uid'], $html);
        [$s] = self::as('alphaboss')->get('rooms.php?action=device&id=' . $tv['id']);
        $this->assertContains($s, [302, 404]);
        [, , $html] = self::as('betaboss')->get('rooms.php?action=device&id=' . $tv['id']);
        $this->assertStringContainsString($tv['uid'], $html);
        // Activity log in both customers and the platform.
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'device_moved_out' AND entity_id = :d", ['h' => self::$h['alpha'], 'd' => $tv['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'device_moved_in' AND entity_id = :d", ['h' => self::$h['beta'], 'd' => $tv['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id IS NULL AND action = 'device_move' AND entity_id = :d", ['d' => $tv['id']]));

        // 2.5 security review: only platform admins move TVs — a reseller is refused even between their own customers.
        [$s] = self::as('psres1')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id']],
            'target_customer' => self::$h['alpha'], 'screen_mode' => 'new', 'new_name' => 'Lobby TV']);
        $this->assertSame(403, $s);
        $this->assertSame(self::$h['beta'], (int) self::deviceRow('tvA2')['hotel_id']);
        [, , $html] = self::as('psres1')->get('platform_screens.php');
        $this->assertStringNotContainsString('value="move"', $html, 'no move action for resellers');
        $this->assertStringNotContainsString('js-ps-move', $html);
        // Row "move" with a NEW screen and "same screen ID".
        [$s] = self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id']],
            'target_customer' => self::$h['alpha'], 'screen_mode' => 'new', 'new_name' => 'Lobby TV']);
        $row = self::deviceRow('tvA2');
        $this->assertSame(self::$h['alpha'], (int) $row['hotel_id']);
        $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $row['room_id']]);
        $this->assertSame('Lobby-TV', $room['room_number']);
        $this->assertSame('Lobby TV', $room['name']);
        $this->assertSame(self::$h['alpha'], (int) $room['hotel_id']);
        [, $d] = self::poll($tv['uid'], $tv['token']);
        $this->assertSame('Lobby-TV', $d['content']['room']['number']);
        self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id']],
            'target_customer' => self::$h['beta'], 'screen_mode' => 'same']);
        $row = self::deviceRow('tvA2');
        $this->assertSame(self::$h['beta'], (int) $row['hotel_id']);
        $this->assertSame('Lobby-TV', DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $row['room_id'], 'h' => self::$h['beta']]), 'same screen ID created in the target');

        // Reseller may not move to a customer of another reseller.
        self::as('psres1')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id']],
            'target_customer' => self::$h['gamma'], 'screen_mode' => 'existing', 'target_room' => self::$room['G1']]);
        $this->assertSame(self::$h['beta'], (int) self::deviceRow('tvA2')['hotel_id']);

        // Screen limit: Gamma allows 1 TV and has 1 → refused, nothing changes (atomic for bulk too).
        [$s] = self::as('psroot')->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids' => [$tv['id'], self::$tv['tv102']['id']],
            'target_customer' => self::$h['gamma'], 'screen_mode' => 'existing', 'target_room' => self::$room['G1']]);
        $this->assertSame(self::$h['beta'], (int) self::deviceRow('tvA2')['hotel_id']);
        $this->assertSame(1, (int) self::deviceRow('tv102')['hotel_id']);
        $this->assertStringContainsString('LICENSE_LIMIT', self::flashOf(self::as('psroot'), 'platform_screens.php'));
        PlatformScreens::$userOverride = DB::one("SELECT * FROM users WHERE username = 'psroot'");
        $this->expectException(RuntimeException::class);
        PlatformScreens::move([$tv['id']], self::$h['gamma'], ['mode' => 'existing', 'room_id' => self::$room['G1']]);
    }

    public function testMoveRefusesRevokedAndClashingTvs(): void
    {
        // A revoked TV cannot be moved (it must register again).
        self::as('psroot')->post('platform_screens.php', ['op' => 'row', 'row' => 'revoke:-:' . self::$tv['tv102']['id']]);
        $row = self::deviceRow('tv102');
        $this->assertSame(1, (int) $row['is_revoked']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = 1 AND action = 'device_revoke' AND entity_id = :d", ['d' => $row['id']]));
        [$s, $d] = self::poll(self::$tv['tv102']['uid'], self::$tv['tv102']['token']);
        $this->assertSame(401, $s);
        PlatformScreens::$userOverride = DB::one("SELECT * FROM users WHERE username = 'psroot'");
        try {
            PlatformScreens::move([$row['id']], self::$h['beta'], ['mode' => 'existing', 'room_id' => self::$room['B1']]);
            $this->fail('revoked TV moved');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('register again', $e->getMessage());
        }
        // The TV registers again in My Hotel (row reused), then: moving it to a customer that has an old
        // revoked record of the same device ID replaces that record.
        self::register('tv102', '102', 'my');
        Tenant::run(self::$h['beta'], static fn () => DB::insert('devices', ['device_uid' => self::$tv['tv102']['uid'], 'token_hash' => hash('sha256', 'old'), 'is_revoked' => 1, 'registered_at' => now()]));
        $n = PlatformScreens::move([self::$tv['tv102']['id']], self::$h['beta'], ['mode' => 'existing', 'room_id' => self::$room['B1']]);
        $this->assertSame(1, $n);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => self::$tv['tv102']['uid'], 'h' => self::$h['beta']]));
        [, $d] = self::poll(self::$tv['tv102']['uid'], self::$tv['tv102']['token']);
        $this->assertSame(self::$h['beta'], $d['content']['hotel']['id']);
        // Back home.
        PlatformScreens::move([self::$tv['tv102']['id']], 1, ['mode' => 'existing', 'room_id' => self::$room['102']]);
        $this->assertSame(1, (int) self::deviceRow('tv102')['hotel_id']);
    }

    // ------------------------------------------------------------------ unassigned pool

    public function testUnassignedPool(): void
    {
        $root = self::as('psroot');
        // Off: the platform key does not exist yet → registration refused.
        [$s] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-ps-pool-0001', 'room_number' => 'P1', 'registration_key' => 'PNOTAKEY']);
        $this->assertSame(401, $s);
        [$s] = $root->post('platform_screens.php', ['op' => 'pool_toggle', 'enabled' => 1]);
        $this->assertSame(302, $s);
        Settings::flush();
        $key = DevicePool::key();
        $this->assertMatchesRegularExpression('/^P[A-F0-9]{16}$/', $key);
        [, , $html] = $root->get('platform_screens.php');
        $this->assertStringContainsString($key, $html);

        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-ps-pool-0001', 'room_number' => 'P1', 'registration_key' => $key, 'model' => 'Pool TV', 'app_version_code' => 11]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertTrue($j['data']['unassigned']);
        $this->assertSame(0, $j['data']['hotel']['id']);
        $token = $j['data']['token'];
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM devices WHERE device_uid = 'tv-ps-pool-0001'"), 'not in any customer');
        // Waiting screen on poll, heartbeat accepted, other device routes → 409.
        [$s, $d] = self::poll('tv-ps-pool-0001', $token);
        $this->assertSame(200, $s);
        $this->assertSame('suspended', $d['content']['mode']);
        $this->assertTrue($d['content']['unassigned']);
        $this->assertStringContainsString('tv-ps-pool-0001', $d['content']['suspended']['message']);
        [$s, $d2] = self::poll('tv-ps-pool-0001', $token, $d['content_hash']);
        $this->assertFalse($d2['content_changed']);
        [$s] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', ['app_version' => '2.4.1'], ['Authorization: Bearer ' . $token, 'X-Device-Id: tv-ps-pool-0001']);
        $this->assertSame(200, $s);
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/content/1', null, ['Authorization: Bearer ' . $token]);
        $this->assertSame(409, $s);
        $this->assertSame('NOT_ASSIGNED', $j['error']['code']);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/command/tv-ps-pool-0001', null, ['Authorization: Bearer wrong-token', 'X-Device-Id: tv-ps-pool-0001']);
        $this->assertSame(401, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/command/tv-ps-other-0001', null, ['Authorization: Bearer ' . $token]);
        $this->assertSame(403, $s, 'token of another device');
        // The pool is listed; the reseller never sees it.
        [, , $html] = $root->get('platform_screens.php');
        $this->assertStringContainsString('tv-ps-pool-0001', $html);
        [, , $html] = self::as('psres1')->get('platform_screens.php');
        $this->assertStringNotContainsString('tv-ps-pool-0001', $html);
        $poolId = (int) DB::value("SELECT id FROM device_pool WHERE device_uid = 'tv-ps-pool-0001'");
        [$s] = self::as('psres1')->post('platform_screens.php', ['op' => 'pool_assign', 'pool_ids' => [$poolId], 'target_customer' => self::$h['alpha'], 'screen_mode' => 'same']);
        $this->assertSame(403, $s);

        // Assign to Alpha with "same screen ID": screen P1 created, next poll = Alpha content, same token.
        [$s] = $root->post('platform_screens.php', ['op' => 'pool_assign', 'pool_ids' => [$poolId], 'target_customer' => self::$h['alpha'], 'screen_mode' => 'same']);
        $this->assertSame(302, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_pool'));
        $dev = DB::one("SELECT * FROM devices WHERE device_uid = 'tv-ps-pool-0001'");
        $this->assertSame(self::$h['alpha'], (int) $dev['hotel_id']);
        $this->assertSame(hash('sha256', $token), $dev['token_hash']);
        $this->assertSame('Pool TV', $dev['model']);
        [$s, $d] = self::poll('tv-ps-pool-0001', $token);
        $this->assertSame(200, $s);
        $this->assertSame(self::$h['alpha'], $d['content']['hotel']['id']);
        $this->assertSame('P1', $d['content']['room']['number']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'device_moved_in' AND entity_id = :d", ['h' => self::$h['alpha'], 'd' => $dev['id']]));

        // Unassign back to the pool: Alpha keeps a revoked record, the TV shows the waiting screen.
        [$s] = $root->post('platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'unassign', 'ids' => [(int) $dev['id']]]);
        $this->assertSame(302, $s);
        $this->assertSame(1, (int) DB::value('SELECT is_revoked FROM devices WHERE id = :id', ['id' => $dev['id']]));
        $pool = DB::one("SELECT * FROM device_pool WHERE device_uid = 'tv-ps-pool-0001'");
        $this->assertSame('removed', $pool['source']);
        $this->assertSame(self::$h['alpha'], (int) $pool['from_hotel_id']);
        $this->assertSame('P1', $pool['label']);
        [, $d] = self::poll('tv-ps-pool-0001', $token);
        $this->assertTrue($d['content']['unassigned'] ?? false);
        // Re-assign to Alpha: the revoked record is reused (one row, active, same token).
        $root->post('platform_screens.php', ['op' => 'pool_assign', 'pool_ids' => [(int) $pool['id']], 'target_customer' => self::$h['alpha'], 'screen_mode' => 'existing', 'target_room' => self::$room['A1']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM devices WHERE device_uid = 'tv-ps-pool-0001'"));
        $dev2 = DB::one("SELECT * FROM devices WHERE device_uid = 'tv-ps-pool-0001'");
        $this->assertSame((int) $dev['id'], (int) $dev2['id']);
        $this->assertSame(0, (int) $dev2['is_revoked']);
        [, $d] = self::poll('tv-ps-pool-0001', $token);
        $this->assertSame('A1', $d['content']['room']['number']);

        // Limit applies to pool assignment (Gamma is full); delete from the pool works.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-ps-pool-0002', 'room_number' => 'P2', 'registration_key' => $key]);
        $this->assertSame(200, $s);
        $p2 = (int) DB::value("SELECT id FROM device_pool WHERE device_uid = 'tv-ps-pool-0002'");
        $root->post('platform_screens.php', ['op' => 'pool_assign', 'pool_ids' => [$p2], 'target_customer' => self::$h['gamma'], 'screen_mode' => 'same']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM device_pool WHERE id = :id', ['id' => $p2]));
        $this->assertStringContainsString('LICENSE_LIMIT', self::flashOf($root, 'platform_screens.php'));
        $root->post('platform_screens.php', ['op' => 'pool_delete', 'pool_ids' => [$p2]]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_pool'));
        [$s] = self::poll('tv-ps-pool-0002', $j['data']['token']);
        $this->assertSame(401, $s);

        // Turned off: the key no longer registers TVs.
        $root->post('platform_screens.php', ['op' => 'pool_toggle', 'enabled' => 0]);
        [$s] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-ps-pool-0003', 'room_number' => 'P3', 'registration_key' => $key]);
        $this->assertSame(401, $s);
        DB::query("DELETE FROM devices WHERE device_uid = 'tv-ps-pool-0001'");
    }

    // ------------------------------------------------------------------ customer detail / dashboard

    public function testCustomerDetailTabsAndDashboard(): void
    {
        foreach (['psroot', 'psres1'] as $who) {
            foreach (['overview', 'screens', 'users', 'activity'] as $tab) {
                [$s, , $html] = self::as($who)->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=' . $tab);
                $this->assertSame(200, $s, "$tab as $who");
                $this->assertFalse(TestEnv::hasPhpError($html), "PHP error on tab $tab as $who");
            }
        }
        // Custom role (users.role_id, core/Roles.php) → its name is shown (Auth::roleName).
        DB::query("INSERT INTO roles (hotel_id, name, base_level, permissions) VALUES (:h, 'Lobby Operator', 'staff', '[]')", ['h' => self::$h['alpha']]);
        $roleId = (int) DB::pdo()->lastInsertId();
        DB::query("UPDATE users SET role_id = :r WHERE username = 'alphastaff'", ['r' => $roleId]);
        [, , $html] = self::as('psroot')->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users');
        $this->assertStringContainsString('alphaboss', $html);
        $this->assertStringContainsString('Lobby Operator', $html);
        $this->assertStringContainsString('alphastaff', $html);
        $this->assertStringNotContainsString('betaboss', $html);
        [, , $html] = self::as('psroot')->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=screens');
        $this->assertStringContainsString(self::$tv['tvA1']['uid'], $html);
        $this->assertStringNotContainsString(self::$tv['tvB1']['uid'], $html);
        [, , $html] = self::as('psroot')->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=overview');
        $this->assertStringContainsString('Limits usage', $html);
        $this->assertStringContainsString('Billing', $html);
        [, , $html] = self::as('psres1')->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=overview');
        $this->assertStringNotContainsString('Unpaid invoices', $html, 'billing summary only for the platform');

        // Users: reset password (logs the user out), deactivate / activate; never another customer's user.
        $staff = (int) DB::value("SELECT id FROM users WHERE username = 'alphastaff'");
        $old = (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $staff]);
        $s0 = self::as('alphastaff');
        [$s] = self::as('psroot')->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_reset', 'user_id' => $staff]);
        $this->assertSame(302, $s);
        $this->assertNotSame($old, (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $staff]));
        $html = self::flashOf(self::as('psroot'), 'platform_customer.php?id=' . self::$h['alpha'] . '&tab=users');
        $this->assertMatchesRegularExpression('/New password for alphastaff: [A-Za-z]{8}\d{2}/', $html);
        [$s] = $s0->get('index.php');
        $this->assertSame(302, $s, 'old session revoked');
        unset(self::$sessions['alphastaff']);
        self::as('psres1')->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_toggle', 'user_id' => $staff]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $staff]));
        self::as('psres1')->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_toggle', 'user_id' => $staff]);
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $staff]));
        $betaBoss = (int) DB::value("SELECT id FROM users WHERE username = 'betaboss'");
        self::as('psroot')->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_toggle', 'user_id' => $betaBoss]);
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $betaBoss]), 'user of another customer untouched');
        $rootId = (int) DB::value("SELECT id FROM users WHERE username = 'psroot'");
        self::as('psroot')->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_toggle', 'user_id' => $rootId]);
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $rootId]));
        $this->assertGreaterThanOrEqual(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'user_password_reset'", ['h' => self::$h['alpha']]));

        // Activity tab shows the customer's log.
        [, , $html] = self::as('psroot')->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=activity');
        $this->assertStringContainsString('user_password_reset', $html);

        // "Open in customer" from a row: enters the customer and jumps to the TV detail.
        $root = new AdminSession(self::$url, 'psroot');
        [$s, , , $head] = $root->post('platform_screens.php', ['op' => 'row', 'row' => 'open:device:' . self::$tv['tvB1']['id']]);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('rooms.php?action=device&id=' . self::$tv['tvB1']['id'], $head);
        [$s, , $html] = $root->get('rooms.php?action=device&id=' . self::$tv['tvB1']['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString(self::$tv['tvB1']['uid'], $html);
        $root->post('platform_hotels.php', ['op' => 'leave']);
        // Login as this customer.
        [$s, , , $head] = $root->post('platform_customer.php?id=' . self::$h['alpha'], ['op' => 'enter']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('index.php', $head);
        $root->post('platform_hotels.php', ['op' => 'leave']);

        // Customers list: Screens (online/total) + Users columns, detail links, dashboard block.
        [$s, , $html] = self::as('psroot')->get('platform_hotels.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach (['Screens (online/total)', 'Top customers by screens', 'Offline TVs by customer', 'platform_customer.php?id=' . self::$h['alpha']] as $txt) {
            $this->assertStringContainsString($txt, $html);
        }
        $dash = PlatformScreens::dashboard(null);
        $this->assertSame(max(array_column(PlatformScreens::perCustomer(null), 'total')), $dash['top'][0]['total']);
        $this->assertSame(PlatformScreens::counters(null)['total'], $dash['counters']['total']);
        DB::query("UPDATE devices SET status = 'offline' WHERE id = :id", ['id' => self::$tv['tvG1']['id']]);
        $dash = PlatformScreens::dashboard(null);
        $this->assertContains('Gamma Lodge', array_column($dash['offline'], 'name'));
        DB::query("UPDATE devices SET status = 'online' WHERE id = :id", ['id' => self::$tv['tvG1']['id']]);
        $users = PlatformScreens::usersPerCustomer(null);
        $this->assertSame(2, $users[self::$h['alpha']]);
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    // ------------------------------------------------------------------ CSRF / translations

    public function testEveryActionNeedsCsrf(): void
    {
        $root = self::as('psroot');
        $before = [
            (string) json_encode(DB::all('SELECT id, hotel_id, room_id, is_revoked, token_hash FROM devices ORDER BY id')),
            (int) DB::value('SELECT COUNT(*) FROM device_commands'),
            (string) Settings::platform('platform_pool_enabled', '0'),
            (string) json_encode(DB::all('SELECT id, is_active, password_hash FROM users ORDER BY id')),
        ];
        $posts = [
            ['platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'cmd:REBOOT', 'ids[0]' => self::$tv['tvA1']['id']]],
            ['platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'revoke', 'ids[0]' => self::$tv['tvA1']['id']]],
            ['platform_screens.php', ['op' => 'bulk', 'bulk_action' => 'move', 'ids[0]' => self::$tv['tvA1']['id'], 'target_customer' => self::$h['beta'], 'screen_mode' => 'same']],
            ['platform_screens.php', ['op' => 'row', 'row' => 'cmd:REBOOT:' . self::$tv['tvA1']['id']]],
            ['platform_screens.php', ['op' => 'row', 'row' => 'open:device:' . self::$tv['tvA1']['id']]],
            ['platform_screens.php', ['op' => 'pool_toggle', 'enabled' => 1]],
            ['platform_screens.php', ['op' => 'pool_regen']],
            ['platform_customer.php?id=' . self::$h['alpha'], ['op' => 'user_reset', 'user_id' => (int) DB::value("SELECT id FROM users WHERE username = 'alphaboss'")]],
            ['platform_customer.php?id=' . self::$h['alpha'], ['op' => 'user_toggle', 'user_id' => (int) DB::value("SELECT id FROM users WHERE username = 'alphaboss'")]],
            ['platform_customer.php?id=' . self::$h['alpha'], ['op' => 'enter']],
            ['platform_customer.php?id=' . self::$h['alpha'] . '&tab=screens', ['op' => 'bulk', 'bulk_action' => 'cmd:PING', 'ids[0]' => self::$tv['tvA1']['id']]],
        ];
        foreach ($posts as [$page, $fields]) {
            foreach ([null, 'wrong-token'] as $token) {
                $form = $token === null ? $fields : ['_csrf' => $token] + $fields;
                [$s] = TestEnv::http('POST', self::$url . 'admin/' . $page, null, [], $root->jar, $form);
                $this->assertSame(419, $s, "$page " . json_encode($fields) . ' without a valid CSRF token');
            }
        }
        Settings::flush();
        $this->assertSame($before, [
            (string) json_encode(DB::all('SELECT id, hotel_id, room_id, is_revoked, token_hash FROM devices ORDER BY id')),
            (int) DB::value('SELECT COUNT(*) FROM device_commands'),
            (string) Settings::platform('platform_pool_enabled', '0'),
            (string) json_encode(DB::all('SELECT id, is_active, password_hash FROM users ORDER BY id')),
        ]);
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $files = ['admin/platform_screens.php', 'admin/platform_customer.php', 'admin/partials/platform_screens.php', 'admin/partials/platform_screens_dashboard.php',
            'admin/partials/nav.d/52_platform_screens.php', 'core/PlatformScreens.php', 'core/DevicePool.php'];
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $missing = [];
        foreach ($files as $f) {
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                foreach (['gu' => $gu, 'hi' => $hi] as $lang => $t) {
                    if (!isset($t[$k])) {
                        $missing[] = "$lang: $k ($f)";
                    }
                }
            }
            preg_match_all('/(?:__|I18n::translate)\(\s*"((?:[^"\\\\]|\\\\.)*)"/', (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $k) {
                $k = stripcslashes($k);
                foreach (['gu' => $gu, 'hi' => $hi] as $lang => $t) {
                    if (!isset($t[$k])) {
                        $missing[] = "$lang: $k ($f)";
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }
}
