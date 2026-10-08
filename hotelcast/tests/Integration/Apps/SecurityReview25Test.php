<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.5 security review regressions (plans & features, custom roles, platform screens / move TV / pool):
 *
 *  - a downgraded plan stops time-based rules of the removed modules on the TVs (power schedules, holidays,
 *    scheduled content windows and one-time pushes, device schedules, presence idle-off);
 *  - scheduling content needs "Schedules & calendar", not only schedule.manage (shared with TV power);
 *  - admin/ajax.php send_command only offers the Broadcast page's commands;
 *  - the approval setting does nothing without the "Content approval" feature;
 *  - a moved TV keeps no trace of the old customer (commands with message texts, status log) and only
 *    platform admins move TVs; customers never reach the pool;
 *  - the pool is capped and its key rotates; limits on pool assign and registration;
 *  - resellers cannot give a customer "no plan" (= everything, unlimited) or an inactive plan, and an
 *    inactive current plan survives an edit of the customer form;
 *  - custom roles never hold platform permissions;
 *  - menu board wording without Hospitality.
 */
final class SecurityReview25Test extends TestCase
{
    private static string $url;
    private static array $id = [];
    private static array $s = [];
    private static string $token = 'sr25tvtoken0001abcdefabcdef0123';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Krishna Store');
        $pw = Auth::hash('Passw0rd!');
        $u = static fn (string $name, string $role, ?int $hotel, array $extra = []): int => DB::insert('users', $extra + [
            'hotel_id' => $hotel, 'username' => $name, 'email' => $name . '@sr25.test', 'full_name' => $name, 'password_hash' => $pw, 'role' => $role, 'is_active' => 1, 'created_at' => now(),
        ]);
        self::$id['reseller'] = DB::insert('resellers', ['name' => 'SR Reseller', 'status' => 'active']);
        self::$id['boss'] = $u('srBoss', 'super_admin', 1);
        self::$id['root'] = $u('srRoot', 'platform_admin', null);
        self::$id['res'] = $u('srRes', 'reseller', null, ['reseller_id' => self::$id['reseller']]);
        self::$id['h2'] = Hotels::create(['name' => 'Other Store']);
        $u('srBoss2', 'super_admin', self::$id['h2']);
        self::$id['planActive'] = DB::insert('plans', ['name' => 'SR Active', 'price_per_tv_month' => 5, 'is_active' => 1, 'created_at' => now()]);
        self::$id['planActive2'] = DB::insert('plans', ['name' => 'SR Active Two', 'price_per_tv_month' => 6, 'is_active' => 1, 'created_at' => now()]);
        self::$id['planInactive'] = DB::insert('plans', ['name' => 'SR Retired', 'price_per_tv_month' => 1, 'is_active' => 0, 'created_at' => now()]);
        self::$id['h3'] = Hotels::create(['name' => 'Reseller Store', 'reseller_id' => self::$id['reseller'], 'plan_id' => self::$id['planActive']]);

        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Counter', 'floor' => '1', 'created_at' => now()]);
        self::$id['d101'] = DB::insert('devices', ['device_uid' => 'sr25-tv-101-0001', 'room_id' => self::$id['r101'], 'token_hash' => hash('sha256', self::$token),
            'status' => 'online', 'last_ping' => now(), 'registered_at' => now()]);
        self::$id['c1'] = DB::insert('content_items', ['title' => 'SR-PROMO', 'type' => 'announcement', 'body' => 'Sale', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        Tenant::run(self::$id['h2'], static function (): void {
            self::$id['rB1'] = DB::insert('rooms', ['room_number' => 'B1', 'name' => 'Window', 'floor' => '1', 'created_at' => now()]);
        });
        foreach (['DeviceHealthTask', 'AnalyticsTask', 'DeviceSchedulesTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        DB::query('UPDATE hotels SET plan_id = NULL, feature_overrides = NULL, max_tvs = NULL, max_users = NULL WHERE id IN (1, :h)', ['h' => self::$id['h2']]);
        PlatformScreens::$userOverride = null;
        self::$s = [];
        Tenant::forget();
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        DB::query('DELETE FROM rate_limits');
        self::usePlan(null);
    }

    private static function as(string $u): AdminSession
    {
        return self::$s[$u] ??= new AdminSession(self::$url, $u);
    }

    /** Hotel 1 on a plan with every optional feature except $off (null = no plan: everything). */
    private static function usePlan(?array $off, int $hotel = 1): void
    {
        $planId = null;
        if ($off !== null) {
            $planId = DB::insert('plans', ['name' => 'SR plan ' . bin2hex(random_bytes(4)), 'price_per_tv_month' => 1, 'created_at' => now(),
                'features' => Features::encodePlanKeys(array_values(array_diff(Features::optionalKeys(), $off)))]);
        }
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => $planId, 'h' => $hotel]);
        Tenant::forget();
        Features::forget();
        Settings::bumpContentVersion();
        Cache::clear();
    }

    private static function room(): array
    {
        return (array) DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['r101']]);
    }

    // ------------------------------------------------------------------ A. plan downgrade on the TVs

    public function testDowngradeStopsScheduledModulesOnTheTvs(): void
    {
        $off = ['power_schedules', 'holidays', 'schedule', 'device_schedules', 'presence'];

        // Power-off window (TV power schedules).
        $pw = DB::insert('broadcast_commands', ['title' => 'Night', 'command' => 'SCREEN_OFF', 'target_type' => 'all', 'target_ids' => '[]', 'mode' => 'window', 'status' => 'active', 'created_at' => now()]);
        $this->assertSame('off', ContentResolver::build(self::room())['mode']);
        self::usePlan($off);
        $this->assertNotSame('off', ContentResolver::build(self::room())['mode'], 'power schedule ignored without the feature');
        self::usePlan(null);
        $this->assertSame('off', ContentResolver::build(self::room())['mode'], 'kept: back after an upgrade');
        DB::query('DELETE FROM broadcast_commands WHERE id = :id', ['id' => $pw]);

        // Holiday "TVs off".
        $hol = DB::insert('holidays', ['name' => 'SR-HOLIDAY', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'action' => 'tv_off', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
        $this->assertSame('holiday', ContentResolver::build(self::room())['off_reason'] ?? null);
        self::usePlan($off);
        $this->assertNotSame('off', ContentResolver::build(self::room())['mode'], 'holiday ignored without the feature');
        self::usePlan(null);
        DB::query('DELETE FROM holidays WHERE id = :id', ['id' => $hol]);

        // Scheduled content window.
        $win = DB::insert('broadcast_commands', ['title' => 'Promo', 'command' => 'SHOW_CONTENT', 'content_id' => self::$id['c1'], 'target_type' => 'all', 'target_ids' => '[]', 'mode' => 'window', 'status' => 'active', 'created_at' => now()]);
        $this->assertSame('scheduled', ContentResolver::build(self::room())['mode']);
        self::usePlan($off);
        $c = ContentResolver::build(self::room());
        $this->assertNotSame('scheduled', $c['mode']);
        $this->assertNotContains('SR-PROMO', array_column($c['items'], 'title'));
        self::usePlan(null);
        DB::query('DELETE FROM broadcast_commands WHERE id = :id', ['id' => $win]);

        // One-time push at a set time: waits while the feature is off.
        $once = DB::insert('broadcast_commands', ['title' => 'Later', 'command' => 'SHOW_CONTENT', 'content_id' => self::$id['c1'], 'target_type' => 'all', 'target_ids' => '[]',
            'mode' => 'once', 'status' => 'scheduled', 'start_at' => date('Y-m-d H:i:s', time() - 60), 'created_at' => now()]);
        self::usePlan($off);
        $this->assertSame(0, Broadcaster::processSchedules()['pushed']);
        $this->assertSame('scheduled', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $once]));
        $this->assertNull(self::room()['content_id']);
        self::usePlan(null);
        $this->assertSame(1, Broadcaster::processSchedules()['pushed']);
        $this->assertSame(self::$id['c1'], (int) self::room()['content_id']);
        DB::query('UPDATE rooms SET content_id = NULL WHERE id = :id', ['id' => self::$id['r101']]);
        DB::query("DELETE FROM device_commands WHERE device_id = :d", ['d' => self::$id['d101']]);

        // Device schedule (daily 22:00 mute) and presence idle-off.
        DB::insert('device_schedules', ['title' => 'SR mute', 'action' => 'mute', 'options' => '{}', 'run_time' => '22:00:00', 'repeat_mode' => 'daily',
            'target_type' => 'all', 'target_ids' => '[]', 'is_active' => 1, 'active_from' => '2020-01-01 00:00:00', 'created_at' => now()]);
        $at = (new DateTimeImmutable(date('Y-m-d') . ' 22:00:10', new DateTimeZone(date_default_timezone_get())))->getTimestamp();
        self::usePlan($off);
        $this->assertSame(0, DeviceSchedules::tick($at)['fired'], 'device schedule does not fire without the feature');
        self::usePlan(null);
        $this->assertSame(1, DeviceSchedules::tick($at)['fired']);
        DB::insert('presence_sensors', ['name' => 'SR door', 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r101']]), 'idle_minutes' => 10, 'is_active' => 1,
            'state' => 'on', 'last_seen' => date('Y-m-d H:i:s', time() - 3600), 'created_at' => now()]);
        self::usePlan($off);
        $this->assertSame(0, Presence::idleTick(), 'presence sensors do not switch screens off without the feature');
        $this->assertSame('on', DB::value("SELECT state FROM presence_sensors WHERE name = 'SR door'"));
        self::usePlan(null);
        $this->assertSame(1, Presence::idleTick());
        DB::query('DELETE FROM presence_sensors');
        DB::query('DELETE FROM device_schedules');
        DB::query('DELETE FROM device_schedule_runs');
        DB::query("DELETE FROM device_commands WHERE device_id = :d", ['d' => self::$id['d101']]);
        DB::query('UPDATE rooms SET is_enabled = 1');
    }

    public function testSchedulingContentNeedsTheScheduleFeatureNotOnlyThePermission(): void
    {
        // Plan with TV power schedules (schedule.manage) but without "Schedules & calendar".
        self::usePlan(['schedule']);
        $before = (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE mode = 'once'");
        [$code, , $html] = self::as('srBoss')->post('broadcast.php', ['op' => 'push', 'when' => 'once', 'source' => 'c:' . self::$id['c1'], 'target_type' => 'all',
            'start_at' => date('Y-m-d\TH:i', time() + 3600), 'title' => 'SR sneaky']);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Not included in your plan', $html);
        $this->assertSame($before, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE mode = 'once'"));
        [, , $html] = self::as('srBoss')->get('broadcast.php');
        $this->assertStringNotContainsString('value="once"', $html, 'no scheduling option');
        $this->assertSame(200, self::as('srBoss')->get('power.php')[0], 'TV power schedules still in the plan');
        $this->assertSame(403, self::as('srBoss')->get('schedule.php')[0]);
        self::usePlan(null);
        [, , $html] = self::as('srBoss')->get('broadcast.php');
        $this->assertStringContainsString('value="once"', $html);
    }

    public function testAjaxSendCommandOnlyOffersTheBroadcastPageCommands(): void
    {
        DB::query('DELETE FROM device_commands');
        foreach (['SPEAK', 'PLAY_SOUND', 'SCREENSHOT', 'UPLOAD_LOGS', 'SHOW_MESSAGE', 'SET_VOLUME', 'OPEN_INPUT', 'UPDATE_APP'] as $cmd) {
            [$code, $j] = self::as('srBoss')->ajax('send_command', ['command' => $cmd, 'target_type' => 'all']);
            $this->assertSame(422, $code, $cmd);
            $this->assertSame('VALIDATION_ERROR', $j['error']['code'], $cmd);
        }
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_commands'));
        [$code] = self::as('srBoss')->ajax('send_command', ['command' => 'PING', 'target_type' => 'all']);
        $this->assertSame(200, $code);
        DB::query('DELETE FROM device_commands');
    }

    public function testApprovalSettingDoesNothingWithoutTheFeature(): void
    {
        Settings::set(Approvals::SETTING, '1');
        Settings::flush();
        $this->assertTrue(Approvals::enabled());
        self::usePlan(['approvals']);
        $this->assertFalse(Approvals::enabled(), 'no approvals page in the plan: content is not held back');
        self::usePlan(null);
        Settings::set(Approvals::SETTING, '0');
        Settings::flush();
    }

    // ------------------------------------------------------------------ C. move TV / pool

    public function testMovedTvKeepsNoTraceOfTheOldCustomerAndOnlyPlatformAdminsMove(): void
    {
        $dev = self::$id['d101'];
        DB::insert('device_commands', ['device_id' => $dev, 'command' => 'SHOW_MESSAGE', 'payload' => json_out(['text' => 'SR-SECRET-OLD-CUSTOMER-MESSAGE']), 'status' => 'acked', 'message' => 'SR-SECRET-ACK', 'created_at' => now()]);
        DB::insert('device_status_logs', ['device_id' => $dev, 'room_id' => self::$id['r101'], 'status' => 'offline', 'created_at' => '2020-01-02 03:04:05']);

        // Customers never reach the platform screens / pool.
        $this->assertSame(403, self::as('srBoss')->get('platform_screens.php')[0]);
        [$code] = self::as('srBoss')->post('platform_screens.php', ['op' => 'pool_toggle', 'enabled' => 1]);
        $this->assertSame(403, $code);
        $this->assertFalse(DevicePool::enabled());

        // Resellers and customers cannot move (even inside their scope); the core refuses too.
        PlatformScreens::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['res']]);
        try {
            PlatformScreens::move([$dev], self::$id['h2'], ['mode' => 'existing', 'room_id' => self::$id['rB1']]);
            $this->fail('reseller move must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Only the platform admin', $e->getMessage());
        }
        PlatformScreens::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['boss']]);
        $this->expectExceptionOnMove($dev);
        $this->assertSame(1, (int) DB::value('SELECT hotel_id FROM devices WHERE id = :id', ['id' => $dev]));

        // Platform admin moves the TV.
        PlatformScreens::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['root']]);
        $this->assertSame(1, PlatformScreens::move([$dev], self::$id['h2'], ['mode' => 'existing', 'room_id' => self::$id['rB1']]));
        PlatformScreens::$userOverride = null;
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_commands WHERE device_id = :d', ['d' => $dev]), 'old commands removed');
        [$code, , $html] = self::as('srBoss2')->get('rooms.php?action=device&id=' . $dev);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('sr25-tv-101-0001', $html);
        $this->assertStringNotContainsString('SR-SECRET', $html, 'no command / message of the old customer');
        $this->assertStringNotContainsString('2020-01-02 03:04', $html, 'no status log of the old customer');
        // The TV polls with the same token and gets the new customer's content.
        [$code, $j] = TestEnv::http('GET', self::$url . 'api/device/command/sr25-tv-101-0001?hash=x', null, ['Authorization: Bearer ' . self::$token, 'X-Device-Id: sr25-tv-101-0001']);
        $this->assertSame(200, $code, json_encode($j));
        $this->assertSame(self::$id['h2'], $j['data']['content']['hotel']['id']);
        $this->assertSame([], $j['data']['commands']);
        $this->assertNotContains($dev, array_map('intval', DB::column('SELECT id FROM devices WHERE hotel_id = 1')));

        // Back home for the other tests.
        PlatformScreens::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['root']]);
        PlatformScreens::move([$dev], 1, ['mode' => 'existing', 'room_id' => self::$id['r101']]);
        PlatformScreens::$userOverride = null;
        Tenant::set(1);
    }

    private function expectExceptionOnMove(int $dev): void
    {
        try {
            PlatformScreens::move([$dev], self::$id['h2'], ['mode' => 'existing', 'room_id' => self::$id['rB1']]);
            $this->fail('customer move must be refused');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPoolKeyRotatesThePoolIsCappedAndLimitsHold(): void
    {
        $key1 = DevicePool::setEnabled(true);
        $this->assertMatchesRegularExpression('/^P[0-9A-F]{16}$/', $key1);
        $reg = static fn (string $uid, string $key): array => TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => 'S1', 'registration_key' => $key]);
        [$code, $j] = $reg('sr25-pool-0001', $key1);
        $this->assertSame(200, $code);
        $this->assertTrue($j['data']['unassigned']);
        // The key is never shown to customers.
        foreach (['index.php', 'settings.php', 'rooms.php'] as $page) {
            $this->assertStringNotContainsString($key1, self::as('srBoss')->get($page)[2], $page);
        }
        // Rotation: the old key stops working for new TVs; pool TVs keep their tokens.
        $key2 = DevicePool::regenerateKey();
        $this->assertNotSame($key1, $key2);
        [$code, $j] = $reg('sr25-pool-0002', $key1);
        $this->assertSame(401, $code);
        $this->assertSame('INVALID_REGISTRATION_KEY', $j['error']['code']);
        [$code] = $reg('sr25-pool-0002', $key2);
        $this->assertSame(200, $code);

        // Cap: a full pool refuses NEW TVs; a waiting TV can still re-register.
        $values = [];
        for ($i = count(DevicePool::all()); $i < DevicePool::MAX_WAITING; $i++) {
            $values[] = sprintf("('sr25-fill-%04d', '%s', 'platform_key', NOW())", $i, hash('sha256', 'fill' . $i));
        }
        DB::query('INSERT INTO device_pool (device_uid, token_hash, source, registered_at) VALUES ' . implode(',', $values));
        [$code, $j] = $reg('sr25-pool-0003', $key2);
        $this->assertSame(409, $code);
        $this->assertSame('POOL_FULL', $j['error']['code']);
        $this->assertNull(DB::value("SELECT id FROM device_pool WHERE device_uid = 'sr25-pool-0003'"));
        [$code] = $reg('sr25-pool-0001', $key2);
        $this->assertSame(200, $code, 'known pool TV re-registers');
        DB::query("DELETE FROM device_pool WHERE device_uid LIKE 'sr25-fill-%'");

        // Limits: assigning from the pool and registering with the customer key respect max screens.
        DB::query('UPDATE hotels SET max_tvs = 0 WHERE id = :h', ['h' => self::$id['h2']]);
        Tenant::forget();
        $poolId = (int) DB::value("SELECT id FROM device_pool WHERE device_uid = 'sr25-pool-0001'");
        try {
            DevicePool::assign([$poolId], self::$id['h2'], ['mode' => 'existing', 'room_id' => self::$id['rB1']]);
            $this->fail('over the screen limit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('LICENSE_LIMIT', $e->getMessage());
        }
        $this->assertNotNull(DB::value('SELECT id FROM device_pool WHERE id = :id', ['id' => $poolId]), 'nothing assigned');
        [$code, $j] = $reg('sr25-new-tv-0001', (string) Settings::getFor(self::$id['h2'], 'registration_key'));
        $this->assertSame(403, $code);
        $this->assertSame('LICENSE_LIMIT', $j['error']['code']);
        DB::query('UPDATE hotels SET max_tvs = NULL WHERE id = :h', ['h' => self::$id['h2']]);
        Tenant::forget();

        DB::query('DELETE FROM device_pool');
        DevicePool::setEnabled(false);
        [$code] = $reg('sr25-pool-0004', $key2);
        $this->assertSame(401, $code, 'switched off: the key is refused');
    }

    // ------------------------------------------------------------------ reseller scope / plans

    public function testResellerCannotGiveNoPlanOrAnInactivePlan(): void
    {
        $h3 = self::$id['h3'];
        $save = static fn (array $f): array => self::as('srRes')->post('reseller.php', $f + ['op' => 'save', 'id' => $h3, 'name' => 'Reseller Store']);
        $plan = static fn (): ?int => ($p = DB::value('SELECT plan_id FROM hotels WHERE id = :id', ['id' => $h3])) === null ? null : (int) $p;

        $save(['plan_id' => '']);
        $this->assertSame(self::$id['planActive'], $plan(), '"no plan" = every feature, unlimited: refused for resellers');
        $save(['plan_id' => self::$id['planInactive']]);
        $this->assertSame(self::$id['planActive'], $plan(), 'inactive plan refused');
        $save(['plan_id' => self::$id['planActive2']]);
        $this->assertSame(self::$id['planActive2'], $plan(), 'active plan allowed');
        [, , $html] = self::as('srRes')->get('reseller.php?action=edit&id=' . $h3);
        $this->assertStringNotContainsString('No plan (not billed)', $html);

        // A customer on a plan that was deactivated later: the form keeps it, saving does not drop it.
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => self::$id['planInactive'], 'h' => $h3]);
        [, , $html] = self::as('srRes')->get('reseller.php?action=edit&id=' . $h3);
        $this->assertMatchesRegularExpression('#<option value="' . self::$id['planInactive'] . '" selected>SR Retired \(inactive\)#', $html);
        $save(['plan_id' => self::$id['planInactive'], 'contact_name' => 'Asha']);
        $this->assertSame(self::$id['planInactive'], $plan(), 'unchanged plan may stay');
        [, , $html] = self::as('srRoot')->get('platform_hotels.php?action=edit&id=' . $h3);
        $this->assertMatchesRegularExpression('#<option value="' . self::$id['planInactive'] . '" selected>SR Retired \(inactive\)#', $html);

        // New customer without a plan: refused.
        DB::query('UPDATE resellers SET max_hotels = NULL WHERE id = :r', ['r' => self::$id['reseller']]);
        self::as('srRes')->post('reseller.php', ['op' => 'save', 'id' => 0, 'name' => 'SR No Plan Shop', 'plan_id' => '',
            'admin_username' => 'srnoplan', 'admin_email' => 'srnoplan@sr25.test', 'admin_password' => 'Passw0rd!']);
        $this->assertNull(DB::value("SELECT id FROM hotels WHERE name = 'SR No Plan Shop'"));
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => self::$id['planActive'], 'h' => $h3]);
    }

    // ------------------------------------------------------------------ B. roles

    public function testCustomRolesNeverHoldPlatformPermissions(): void
    {
        $platform = ['platform.manage', 'platform.hotels', 'platform.screens', 'platform.pool', 'platform.move', 'update.manage', 'support.platform', 'signup.manage', 'chains.manage', 'chain.view', 'push.self'];
        $this->assertSame([], array_values(array_intersect($platform, array_keys(Roles::catalog()))));
        self::as('srBoss')->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'SR Platformish', 'perms' => array_merge(['content.view'], $platform)]);
        $perms = json_decode((string) DB::value("SELECT permissions FROM roles WHERE hotel_id = 1 AND name = 'SR Platformish'"), true);
        $this->assertSame(['content.view'], $perms);
        DB::query("DELETE FROM roles WHERE name = 'SR Platformish'");
    }

    // ------------------------------------------------------------------ D. rename review

    public function testMenuBoardActiveLabelWithoutHospitality(): void
    {
        self::usePlan(['room_service', 'feedback', 'guests', 'pms', 'guide']);
        [$code, , $html] = self::as('srBoss')->get('menu_board.php?action=cat');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Active (shown on TV)', $html);
        $this->assertStringNotContainsString('room service', $html);
        self::usePlan(null);
        [, , $html] = self::as('srBoss')->get('menu_board.php?action=cat');
        $this->assertStringContainsString('Active (room service and TV)', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ browser QA findings (2.5)

    public function testBrowserQaFixes(): void
    {
        // Relative times are whole translated phrases (no "5h પહેલાં").
        $ts = date('Y-m-d H:i:s', time() - 5 * 3600 - 60);
        $this->assertSame('5h ago', time_ago($ts));
        $this->assertSame('5 કલાક પહેલાં', I18n::translate(':nh ago', 'gu', ['n' => 5]));
        $this->assertSame('3 मिनट पहले', I18n::translate(':nm ago', 'hi', ['n' => 3]));
        // Hindi dashboard widgets and access-denied text are translated.
        foreach (['Plan & TV limit', 'Today at a glance', 'Invalid username or password.', 'TV preview'] as $k) {
            $this->assertNotSame($k, I18n::translate($k, 'hi'), $k);
        }
        // Password strength meter of the customer form (JS error "reading 'style'" before).
        [, , $html] = self::as('srRoot')->get('platform_hotels.php?action=new');
        $this->assertStringContainsString('<div class="strength-bar" id="apBar"><span></span></div>', $html);
        // Customer detail header: no empty " · · " part when the city is empty.
        [$code, , $html] = self::as('srRoot')->get('platform_customer.php?id=' . self::$id['h2']);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('·  ·', $html);
        $this->assertStringNotContainsString('· ·', $html);
    }
}
