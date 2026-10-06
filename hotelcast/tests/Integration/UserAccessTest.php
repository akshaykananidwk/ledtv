<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-user TV access (core/Access.php, docs/modules/user_access.md), SaaS role names and the
 * platform switch for hotel chains — over real HTTP, like TenancyTest.
 *
 * Hotel #1: rooms A101 + A102 (group G-MINE, floor 1), A201 + A202 (floor 2, A202 in group H-OTHER),
 * A301 (floor 3). deskG (staff) and mgrG (manager) are limited to group G-MINE + room A201;
 * mgrAll (manager) has no rows = all TVs; bossA is the hotel Admin (super_admin); padmin the
 * platform owner. Hotel #2 owns a foreign group / room for the "foreign id" checks.
 */
final class UserAccessTest extends TestCase
{
    private static string $url;
    /** @var array<string, AdminSession> */
    private static array $s = [];
    /** ids: rooms (A101 …), groups (G, H), devices (dev101, dev202), broadcasts */
    private static array $id = [];
    private static array $users = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Access::forget();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        $mk = static function (string $name, string $role, ?int $hotel) use ($pw): int {
            return DB::insert('users', ['hotel_id' => $hotel, 'username' => $name, 'email' => $name . '@ua.test', 'full_name' => $name,
                'password_hash' => $pw, 'role' => $role, 'is_active' => 1, 'created_at' => now()]);
        };
        foreach (['bossA' => 'super_admin', 'mgrAll' => 'manager', 'mgrG' => 'manager', 'deskG' => 'staff', 'recepAll' => 'reception'] as $u => $role) {
            self::$users[$u] = $mk($u, $role, 1);
        }
        self::$users['padmin'] = $mk('padmin', 'platform_admin', null);

        foreach (['A101' => '1', 'A102' => '1', 'A201' => '2', 'A202' => '2', 'A301' => '3'] as $num => $floor) {
            self::$id[$num] = DB::insert('rooms', ['room_number' => $num, 'name' => 'Room ' . $num, 'floor' => $floor]);
        }
        self::$id['G'] = DB::insert('room_groups', ['name' => 'G-MINE', 'type' => 'custom']);
        self::$id['H'] = DB::insert('room_groups', ['name' => 'H-OTHER', 'type' => 'custom']);
        foreach ([['A101', 'G'], ['A102', 'G'], ['A202', 'H']] as [$r, $g]) {
            DB::insert('room_group_members', ['room_id' => self::$id[$r], 'group_id' => self::$id[$g]]);
        }
        foreach (['101' => 'A101', '202' => 'A202', '301' => 'A301'] as $k => $room) {
            self::$id['dev' . $k] = DB::insert('devices', ['device_uid' => 'tv-ua-' . $k, 'room_id' => self::$id[$room], 'token_hash' => hash('sha256', 'ua' . $k),
                'status' => 'online', 'last_ping' => now(), 'registered_at' => date('Y-m-d H:i:s', time() - 86400)]);
        }
        self::$id['content'] = DB::insert('content_items', ['title' => 'UA-CONTENT', 'type' => 'announcement', 'body' => 'Hello', 'duration' => 10]);

        // Broadcasts made by the Admin: hotel-wide ones and ones for "my" rooms only.
        self::$id['E_all'] = Broadcaster::emergencyStart('UA-ALL-EMERGENCY', 'All', 'all', []);
        $off = date('H:i', time() + 3 * 3600);
        $on = date('H:i', time() + 4 * 3600);
        [self::$id['P_all']] = Broadcaster::schedulePower(['target_type' => 'all', 'off_time' => $off, 'on_time' => $on, 'days' => [1, 2, 3, 4, 5, 6, 7], 'title' => 'UA-POWER-ALL']);
        [self::$id['P_mine']] = Broadcaster::schedulePower(['target_type' => 'rooms', 'room_ids' => [self::$id['A101']], 'off_time' => $off, 'on_time' => $on, 'days' => [1, 2], 'title' => 'UA-POWER-MINE']);
        $sched = static fn (string $title, string $type, array $ids) => Broadcaster::schedule([
            'title' => $title, 'target_type' => $type, 'target_ids' => $ids, 'content_id' => self::$id['content'], 'playlist_id' => null,
            'mode' => 'once', 'start_at' => date('Y-m-d H:i:s', time() + 30 * 86400), 'end_at' => null, 'daily_start' => null, 'daily_end' => null, 'repeat_days' => null,
        ]);
        self::$id['S_all'] = $sched('UA-SCHED-ALL', 'all', []);
        self::$id['S_mine'] = $sched('UA-SCHED-MINE', 'groups', [self::$id['G']]);

        // deskG + mgrG: group G-MINE (A101, A102) + room A201.
        foreach (['deskG', 'mgrG'] as $u) {
            Access::setForUser(self::$users[$u], [['group', self::$id['G']], ['room', self::$id['A201']]]);
        }

        // A second hotel with its own group / room (foreign ids).
        $hb = Hotels::create(['name' => 'UA Hotel B', 'plan_id' => null]);
        Tenant::run($hb, static function (): void {
            self::$id['B_room'] = DB::insert('rooms', ['room_number' => 'B901', 'floor' => '9']);
            self::$id['B_group'] = DB::insert('room_groups', ['name' => 'B-GROUP', 'type' => 'custom']);
        });
        Tenant::set(1);
        Cache::clear();
        Settings::flush();

        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        self::$s = [];
        Access::$userOverride = null;
        Access::forget();
        Tenant::set(1);
    }

    private static function as(string $u): AdminSession
    {
        return self::$s[$u] ??= new AdminSession(self::$url, $u);
    }

    /** Data a restricted user must not be able to change. */
    private static function snapshot(): string
    {
        return json_encode([
            'rooms' => DB::all('SELECT id, room_number, floor, is_enabled, content_id, playlist_id FROM rooms WHERE hotel_id = 1 ORDER BY id'),
            'groups' => DB::all('SELECT id, name, content_id, playlist_id FROM room_groups WHERE hotel_id = 1 ORDER BY id'),
            'members' => DB::all('SELECT room_id, group_id FROM room_group_members ORDER BY room_id, group_id'),
            'broadcasts' => DB::all("SELECT id, title, target_type, target_ids, status = 'cancelled' AS cancelled FROM broadcast_commands WHERE hotel_id = 1 ORDER BY id"),
            'emergencies' => DB::column("SELECT id FROM broadcast_commands WHERE hotel_id = 1 AND is_emergency = 1 AND status = 'active' ORDER BY id"),
            'commands' => (int) DB::value('SELECT COUNT(*) FROM device_commands'),
            'settings' => DB::all("SELECT setting_key, setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key IN ('power_off_mode','tv_volume_enabled','tv_volume_max') ORDER BY setting_key"),
            'access' => DB::all('SELECT user_id, target_type, target_id FROM user_access ORDER BY user_id, target_type, target_id'),
        ]) ?: '';
    }

    // ------------------------------------------------------------------ core rules (CLI)

    public function testAccessRulesInCore(): void
    {
        Access::forget();
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$users['deskG']]);
        try {
            $this->assertTrue(Access::restricted());
            $this->assertSame([self::$id['A101'], self::$id['A102'], self::$id['A201']], Access::roomIds());
            $this->assertTrue(Access::canGroup(self::$id['G']));
            $this->assertFalse(Access::canGroup(self::$id['H']));
            $this->assertTrue(Access::canFloor('1'));
            $this->assertFalse(Access::canFloor('2'), 'floor 2 also has A202');
            $this->assertFalse(Access::canTargetList('all', []));
            $this->assertTrue(Access::canTargetList('rooms', [self::$id['A101'], self::$id['A201']]));
            $this->assertFalse(Access::canTargetList('rooms', [self::$id['A101'], self::$id['A202']]));
            $this->assertTrue(Access::canDevice(self::$id['dev101']));
            $this->assertFalse(Access::canDevice(self::$id['dev202']));
            try {
                Broadcaster::parseTarget(['target_type' => 'all']);
                $this->fail('A limited user must not target all rooms');
            } catch (TenantException) {
                $this->assertTrue(true);
            }
            $this->assertSame(['groups', [self::$id['G']]], Broadcaster::parseTarget(['target_type' => 'groups', 'group_ids' => [self::$id['G']]]));
        } finally {
            Access::$userOverride = null;
            Access::forget();
        }
        // Managers without rows, admins and platform users are never limited.
        foreach (['mgrAll', 'bossA', 'padmin'] as $u) {
            $this->assertNull(Access::scope(DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$users[$u]])), $u);
        }
        // Stale rows of a deleted room keep the user limited (no rooms) instead of unlimited.
        $ghost = DB::insert('users', ['hotel_id' => 1, 'username' => 'ghost', 'email' => 'ghost@ua.test', 'password_hash' => 'x', 'role' => 'staff']);
        DB::query("INSERT INTO user_access (hotel_id, user_id, target_type, target_id) VALUES (1, :u, 'room', 999999)", ['u' => $ghost]);
        $this->assertSame(['rooms' => [], 'groups' => []], Access::scope(DB::one('SELECT * FROM users WHERE id = :id', ['id' => $ghost])));
        DB::query('DELETE FROM user_access WHERE user_id = :u', ['u' => $ghost]);
        DB::query('DELETE FROM users WHERE id = :u', ['u' => $ghost]);
    }

    // ------------------------------------------------------------------ lists

    public function testRestrictedUsersSeeOnlyTheirRooms(): void
    {
        $mine = ['A101', 'A102', 'A201'];
        $other = ['A202', 'A301'];
        foreach (['deskG', 'mgrG'] as $u) {
            [$s, , $html] = self::as($u)->get('rooms.php');
            $this->assertSame(200, $s, "rooms.php as $u");
            foreach ($mine as $n) {
                $this->assertStringContainsString('>' . $n . '<', $html, "$u sees $n");
            }
            foreach ($other as $n) {
                $this->assertStringNotContainsString($n, $html, "$u must not see $n");
            }
            $this->assertStringNotContainsString('rooms.php?action=new', $html, 'no "Add room" for limited users');
            $this->assertStringNotContainsString('TESTKEY', $html, 'registration key hidden');

            [$s, $j] = self::as($u)->ajax('room_status');
            $this->assertSame(200, $s);
            $this->assertSame($mine, array_column($j['data']['rooms'], 'number'), "room_status as $u");
            $this->assertSame(3, $j['data']['stats']['rooms']);
            $this->assertSame(1, $j['data']['stats']['devices'], 'only the TV of A101');

            [$s, $j] = self::as($u)->ajax('dashboard_stats');
            $this->assertSame(200, $s);
            $this->assertSame(3, $j['data']['rooms']);
            $this->assertSame(1, $j['data']['online']);

            [$s, , $html] = self::as($u)->get('index.php');
            $this->assertSame(200, $s);
            $this->assertStringNotContainsString('A202', $html);
            $this->assertStringNotContainsString('id="btnRefreshAll"', $html, 'no "refresh all TVs" for limited users');
            $this->assertStringContainsString('UA-ALL-EMERGENCY', $html, 'a hotel-wide emergency is shown …');
            $this->assertStringNotContainsString('js-emergency-stop" data-id', $html, '… but cannot be stopped by a limited user');
        }
        // Target pickers: no "All rooms", only own groups / floors.
        [, , $html] = self::as('deskG')->get('broadcast.php');
        $this->assertStringNotContainsString('name="target_type" value="all"', $html);
        $this->assertStringContainsString('G-MINE', $html);
        $this->assertStringNotContainsString('H-OTHER', $html);
        $this->assertStringContainsString('name="floors[]" value="1"', $html);
        $this->assertStringNotContainsString('name="floors[]" value="2"', $html, 'floor 2 is not fully theirs');

        // Groups page (manager): only the assigned group, no create / delete.
        [$s, , $html] = self::as('mgrG')->get('groups.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('G-MINE', $html);
        $this->assertStringNotContainsString('H-OTHER', $html);
        $this->assertStringNotContainsString('groups.php?action=new', $html);
        $this->assertStringNotContainsString('value="auto_floors"', $html);
        $this->assertStringNotContainsString('name="op" value="delete"', $html);

        // Schedules / power schedules: only the ones for their TVs.
        [, , $html] = self::as('mgrG')->get('schedule.php');
        $this->assertStringContainsString('UA-SCHED-MINE', $html);
        $this->assertStringNotContainsString('UA-SCHED-ALL', $html);
        [, $j] = self::as('mgrG')->ajax('schedule_events&start=' . date('Y-m-d', time() - 86400) . '&end=' . date('Y-m-d', time() + 60 * 86400));
        $titles = implode('|', array_column($j['data']['events'], 'title'));
        $this->assertStringContainsString('UA-SCHED-MINE', $titles);
        $this->assertStringNotContainsString('UA-SCHED-ALL', $titles);
        [, , $html] = self::as('mgrG')->get('power.php');
        $this->assertStringContainsString('UA-POWER-MINE', $html);
        $this->assertStringNotContainsString('UA-POWER-ALL', $html);
        $this->assertStringNotContainsString('name="power_off_mode"', $html, 'hotel-wide power mode hidden');

        // Other pages listing TVs.
        foreach (['support.php', 'setup_file.php', 'apk.php', 'analytics.php', 'logs.php?tab=status', 'tv_controls.php', 'power.php', 'broadcast.php'] as $p) {
            [$s, , $html] = self::as('mgrG')->get($p);
            $this->assertSame(200, $s, $p);
            $this->assertStringNotContainsString('A301', $html, "$p must not list A301");
            $this->assertStringNotContainsString('tv-ua-202', $html, "$p must not list the TV of A202");
        }
    }

    public function testUnrestrictedUsersStillSeeEverything(): void
    {
        foreach (['mgrAll', 'bossA'] as $u) {
            [$s, , $html] = self::as($u)->get('rooms.php');
            $this->assertSame(200, $s);
            foreach (['A101', 'A202', 'A301'] as $n) {
                $this->assertStringContainsString($n, $html, "$u sees $n");
            }
            [, $j] = self::as($u)->ajax('dashboard_stats');
            $this->assertSame(5, $j['data']['rooms'], $u);
            [, , $html] = self::as($u)->get('schedule.php');
            $this->assertStringContainsString('UA-SCHED-ALL', $html);
            [, , $html] = self::as($u)->get('broadcast.php');
            $this->assertStringContainsString('name="target_type" value="all"', $html);
        }
        [, , $html] = self::as('mgrAll')->get('groups.php');
        $this->assertStringContainsString('H-OTHER', $html);
        $this->assertStringContainsString('groups.php?action=new', $html);
    }

    // ------------------------------------------------------------------ refused actions

    public function testActionsOnOtherTvsAre403AndChangeNothing(): void
    {
        $i = self::$id;
        $before = self::snapshot();
        $staff = [
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'all']],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content']]],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'rooms', 'room_ids' => [$i['A101'], $i['A202']]]],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'groups', 'group_ids' => [$i['H']]]],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'floors', 'floors' => ['2']]],
            ['broadcast.php', ['op' => 'command', 'command' => 'SHOW_CONTENT', 'target_type' => 'all']],
            ['broadcast.php', ['op' => 'command', 'command' => 'SHOW_CONTENT', 'target_type' => 'rooms', 'room_ids' => [$i['A301']]]],
            ['broadcast.php', ['op' => 'emergency', 'title' => 'X', 'message' => 'Y', 'target_type' => 'all']],
            ['broadcast.php', ['op' => 'emergency', 'title' => 'X', 'message' => 'Y', 'target_type' => 'rooms', 'room_ids' => [$i['A202']]]],
            ['broadcast.php', ['op' => 'emergency_stop', 'id' => $i['E_all']]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'refresh', 'room_ids' => [$i['A101'], $i['A301']]]],
        ];
        $manager = [
            ['broadcast.php', ['op' => 'push', 'when' => 'once', 'source' => 'c:' . $i['content'], 'target_type' => 'all', 'start_at' => date('Y-m-d\TH:i', time() + 3600)]],
            ['power.php', ['op' => 'now', 'state' => 'off', 'target_type' => 'all']],
            ['power.php', ['op' => 'now', 'state' => 'off', 'target_type' => 'rooms', 'room_ids' => [$i['A202']]]],
            ['power.php', ['op' => 'add', 'off_time' => '23:00', 'on_time' => '06:00', 'days' => [1], 'target_type' => 'all']],
            ['power.php', ['op' => 'add', 'off_time' => '23:00', 'on_time' => '06:00', 'days' => [1], 'target_type' => 'groups', 'group_ids' => [$i['H']]]],
            ['power.php', ['op' => 'toggle', 'id' => $i['P_all'], 'enable' => '0']],
            ['power.php', ['op' => 'delete', 'id' => $i['P_all']]],
            ['power.php', ['op' => 'mode', 'power_off_mode' => 'black']],
            ['schedule.php', ['op' => 'cancel', 'id' => $i['S_all']]],
            ['schedule.php', ['op' => 'delete', 'id' => $i['S_all']]],
            ['schedule.php', ['op' => 'update', 'id' => $i['S_all'], 'source' => 'c:' . $i['content'], 'target_type' => 'rooms', 'room_ids' => [$i['A101']], 'mode' => 'once', 'start_at' => date('Y-m-d\TH:i', time() + 7200)]],
            ['schedule.php', ['op' => 'update', 'id' => $i['S_mine'], 'source' => 'c:' . $i['content'], 'target_type' => 'all', 'mode' => 'once', 'start_at' => date('Y-m-d\TH:i', time() + 7200)]],
            ['rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'A999', 'is_enabled' => 1]],
            ['rooms.php', ['op' => 'save', 'id' => $i['A202'], 'room_number' => 'A202', 'name' => 'HACKED', 'is_enabled' => 1]],
            ['rooms.php', ['op' => 'save', 'id' => $i['A101'], 'room_number' => 'A101', 'groups' => [$i['G'], $i['H']], 'is_enabled' => 1]],
            ['rooms.php', ['op' => 'delete', 'id' => $i['A101']]],
            ['rooms.php', ['op' => 'bulk_add', 'numbers' => 'A900-A905']],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'delete', 'room_ids' => [$i['A101']]]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'REBOOT', 'room_ids' => [$i['A202']]]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'assign', 'room_ids' => [$i['A301']], 'source' => 'c:' . $i['content']]],
            ['rooms.php', ['op' => 'revoke_device', 'device_id' => $i['dev202']]],
            ['groups.php', ['op' => 'save', 'id' => 0, 'name' => 'NEW-GROUP']],
            ['groups.php', ['op' => 'save', 'id' => $i['H'], 'name' => 'HACKED']],
            ['groups.php', ['op' => 'save', 'id' => $i['G'], 'name' => 'G-MINE', 'members' => [$i['A101'], $i['A102'], $i['A202']]]],
            ['groups.php', ['op' => 'delete', 'id' => $i['G']]],
            ['groups.php', ['op' => 'auto_floors']],
            ['tv_controls.php', ['op' => 'save_volume', 'tv_volume_enabled' => '1', 'tv_volume_max' => '10']],
            ['tv_controls.php', ['op' => 'command', 'command' => 'MUTE', 'target_type' => 'all']],
            ['setup_file.php', ['room_ids' => [$i['A301']]]],
        ];
        foreach ([['deskG', $staff], ['mgrG', array_merge($staff, $manager)]] as [$u, $list]) {
            foreach ($list as [$page, $fields]) {
                [$s] = self::as($u)->post($page, $fields);
                $this->assertSame(403, $s, "$u $page " . json_encode($fields));
            }
        }
        $ajax = [
            ['send_command', ['command' => 'SHOW_CONTENT', 'target_type' => 'all']],
            ['send_command', ['command' => 'SHOW_CONTENT', 'target_type' => 'rooms', 'target_ids' => [$i['A202']]]],
            ['send_command', ['command' => 'SHOW_CONTENT', 'target_type' => 'groups', 'target_ids' => [$i['H']]]],
            ['emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'all']],
            ['emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'floors', 'target_ids' => ['3']]],
            ['emergency_stop', ['id' => $i['E_all']]],
            ['support_request', ['device_id' => $i['dev202'], 'command' => 'SCREENSHOT']],
        ];
        foreach ($ajax as [$action, $json]) {
            [$s, $j] = self::as('mgrG')->ajax($action, $json);
            $this->assertSame(403, $s, "ajax $action " . json_encode($json));
            $this->assertSame('FORBIDDEN', $j['error']['code'] ?? null);
        }
        $gets = ['preview.php?room_id=' . $i['A202'], 'rooms.php?action=device&id=' . $i['dev202'], 'rooms.php?action=edit&id=' . $i['A301'],
            'rooms.php?action=new', 'rooms.php?action=bulk_add', 'groups.php?action=new', 'groups.php?action=edit&id=' . $i['H'],
            'schedule.php?action=edit&id=' . $i['S_all'], 'support.php?device=' . $i['dev202'], 'logs.php?tab=broadcasts&id=' . $i['S_all']];
        foreach ($gets as $p) {
            [$s, , $html] = self::as('mgrG')->get($p);
            $this->assertSame(403, $s, $p);
            $this->assertStringNotContainsString('A301', $html, $p);
        }
        [$s, $j] = self::as('mgrG')->ajax('preview_content&room_id=' . $i['A202']);
        $this->assertSame(403, $s);
        $this->assertSame('FORBIDDEN', $j['error']['code'] ?? null);
        Settings::flush();
        $this->assertSame($before, self::snapshot(), 'Nothing may change after refused actions');
        $this->assertSame('active', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $i['E_all']]));
        $this->assertNotEmpty(Logger::tail('security', 5), 'refusals are logged');
    }

    // ------------------------------------------------------------------ allowed actions

    public function testAllowedActionsOnOwnTvsWork(): void
    {
        $i = self::$id;
        // Push content to own rooms / own group.
        [$s] = self::as('deskG')->post('broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'rooms', 'room_ids' => [$i['A201']]]);
        $this->assertSame(302, $s);
        $this->assertSame($i['content'], (int) DB::value('SELECT content_id FROM rooms WHERE id = :id', ['id' => $i['A201']]));
        [$s] = self::as('deskG')->post('broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'groups', 'group_ids' => [$i['G']]]);
        $this->assertSame(302, $s);
        $this->assertSame($i['content'], (int) DB::value('SELECT content_id FROM rooms WHERE id = :id', ['id' => $i['A102']]));
        $this->assertNull(DB::value('SELECT content_id FROM rooms WHERE id = :id', ['id' => $i['A202']]), 'A202 untouched');
        [$s] = self::as('deskG')->post('broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $i['content'], 'target_type' => 'floors', 'floors' => ['1']]);
        $this->assertSame(302, $s, 'floor 1 = only own rooms');

        // Commands + emergency on own TVs (AJAX).
        [$s, $j] = self::as('deskG')->ajax('send_command', ['command' => 'SHOW_CONTENT', 'target_type' => 'rooms', 'target_ids' => [$i['A101']]]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertSame(1, $j['data']['devices']);
        [$s, $j] = self::as('deskG')->ajax('emergency_start', ['title' => 'UA-MINE-EMERGENCY', 'message' => 'Mine', 'target_type' => 'groups', 'target_ids' => [$i['G']]]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $mineEm = (int) $j['data']['broadcast_id'];
        // "Stop all" stops only the emergencies of their TVs; the hotel-wide one keeps running.
        [$s, $j] = self::as('deskG')->ajax('emergency_stop', []);
        $this->assertSame(200, $s);
        $this->assertSame(1, $j['data']['stopped']);
        $this->assertSame('completed', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $mineEm]));
        $this->assertSame('active', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $i['E_all']]));

        // Manager: power now / power schedule / schedules for own TVs.
        [$s] = self::as('mgrG')->post('power.php', ['op' => 'now', 'state' => 'off', 'target_type' => 'rooms', 'room_ids' => [$i['A201']]]);
        $this->assertSame(302, $s);
        $this->assertSame(0, (int) DB::value('SELECT is_enabled FROM rooms WHERE id = :id', ['id' => $i['A201']]));
        self::as('mgrG')->post('power.php', ['op' => 'now', 'state' => 'on', 'target_type' => 'rooms', 'room_ids' => [$i['A201']]]);
        $this->assertSame(1, (int) DB::value('SELECT is_enabled FROM rooms WHERE id = :id', ['id' => $i['A201']]));
        [$s] = self::as('mgrG')->post('power.php', ['op' => 'add', 'title' => 'UA-POWER-NEW', 'off_time' => '23:30', 'on_time' => '05:30', 'days' => [6, 7], 'target_type' => 'groups', 'group_ids' => [$i['G']]]);
        $this->assertSame(302, $s);
        $newPower = (int) DB::value("SELECT id FROM broadcast_commands WHERE title = 'UA-POWER-NEW'");
        $this->assertGreaterThan(0, $newPower);
        [$s] = self::as('mgrG')->post('power.php', ['op' => 'toggle', 'id' => $i['P_mine'], 'enable' => '0']);
        $this->assertSame(302, $s);
        $this->assertSame('cancelled', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $i['P_mine']]));
        [$s] = self::as('mgrG')->post('power.php', ['op' => 'delete', 'id' => $newPower]);
        $this->assertSame(302, $s);
        $this->assertNull(DB::value('SELECT id FROM broadcast_commands WHERE id = :id', ['id' => $newPower]));
        [$s] = self::as('mgrG')->get('schedule.php?action=edit&id=' . $i['S_mine']);
        $this->assertSame(200, $s);
        [$s] = self::as('mgrG')->post('broadcast.php', ['op' => 'push', 'when' => 'once', 'title' => 'UA-SCHED-NEW', 'source' => 'c:' . $i['content'], 'target_type' => 'rooms', 'room_ids' => [$i['A101']], 'start_at' => date('Y-m-d\TH:i', time() + 86400)]);
        $this->assertSame(302, $s);
        $this->assertSame('rooms', DB::value("SELECT target_type FROM broadcast_commands WHERE title = 'UA-SCHED-NEW'"));

        // Own room edit + own group edit (membership of other groups is kept).
        [$s] = self::as('mgrG')->get('rooms.php?action=edit&id=' . $i['A101']);
        $this->assertSame(200, $s);
        [$s] = self::as('mgrG')->post('rooms.php', ['op' => 'save', 'id' => $i['A201'], 'room_number' => 'A201', 'floor' => '2', 'name' => 'Renamed 201', 'is_enabled' => 1, 'groups' => [$i['G']]]);
        $this->assertSame(302, $s);
        $this->assertSame('Renamed 201', DB::value('SELECT name FROM rooms WHERE id = :id', ['id' => $i['A201']]));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM room_group_members WHERE room_id = :r AND group_id = :g', ['r' => $i['A201'], 'g' => $i['G']]));
        [$s] = self::as('mgrG')->post('groups.php', ['op' => 'save', 'id' => $i['G'], 'name' => 'G-MINE', 'type' => 'custom', 'members' => [$i['A101'], $i['A102']]]);
        $this->assertSame(302, $s);
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM room_group_members WHERE group_id = :g', ['g' => $i['G']]));
        [$s] = self::as('mgrG')->get('support.php?device=' . $i['dev101']);
        $this->assertSame(200, $s);
        [$s] = self::as('mgrG')->get('preview.php?room_id=' . $i['A101']);
        $this->assertSame(200, $s);
        // Content library stays shared: a limited manager may create content.
        [$s] = self::as('mgrG')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'UA-NEW-CONTENT', 'body' => 'x', 'duration' => 10, 'is_active' => 1]);
        $this->assertContains($s, [200, 302]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title = 'UA-NEW-CONTENT'"));
    }

    // ------------------------------------------------------------------ users.php form

    public function testAdminAssignsTvAccessOnUsersPage(): void
    {
        $i = self::$id;
        $boss = self::as('bossA');
        [$s, , $html] = $boss->get('users.php?action=new');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="tv_access"', $html);
        $this->assertStringContainsString('G-MINE', $html);
        $this->assertStringNotContainsString('B-GROUP', $html);
        $base = ['op' => 'save', 'id' => 0, 'username' => 'deskNew', 'email' => 'desknew@ua.test', 'role' => 'staff', 'language' => 'en', 'is_active' => 1,
            'password' => 'Passw0rd!', 'password_confirm' => 'Passw0rd!', 'tv_access' => 'some'];

        // Without CSRF token: refused, nothing created.
        [$s] = TestEnv::http('POST', self::$url . 'admin/users.php', null, [], $boss->jar, $base + ['access_groups[0]' => (string) $i['G']]);
        $this->assertSame(419, $s);
        // Foreign-hotel group / room ids: 404, nothing created.
        [$s] = $boss->post('users.php', $base + ['access_groups' => [$i['B_group']]]);
        $this->assertSame(404, $s);
        [$s] = $boss->post('users.php', $base + ['access_rooms' => [$i['B_room']]]);
        $this->assertSame(404, $s);
        // "Only these TVs" without any choice: validation error.
        [$s] = $boss->post('users.php', $base);
        $this->assertSame(302, $s);
        $this->assertNull(DB::value("SELECT id FROM users WHERE username = 'deskNew'"));

        // Valid: group G-MINE + room A301.
        [$s] = $boss->post('users.php', $base + ['access_groups' => [$i['G']], 'access_rooms' => [$i['A301'], 999999]]);
        $this->assertSame(302, $s);
        $uid = (int) DB::value("SELECT id FROM users WHERE username = 'deskNew'");
        $this->assertGreaterThan(0, $uid);
        $rows = DB::all('SELECT hotel_id, target_type, target_id FROM user_access WHERE user_id = :u ORDER BY target_type', ['u' => $uid]);
        $this->assertSame([['hotel_id' => 1, 'target_type' => 'group', 'target_id' => $i['G']], ['hotel_id' => 1, 'target_type' => 'room', 'target_id' => $i['A301']]],
            array_map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r), $rows));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'user_access' AND entity_id = :u", ['u' => $uid]));
        [, , $html] = $boss->get('users.php');
        $this->assertStringContainsString('1 room, 1 group', $html);
        $this->assertStringContainsString('All TVs', $html);
        $this->assertStringContainsString(role_label('super_admin'), $html);
        $this->assertStringContainsString('Who can do what', $html);

        // The new user sees exactly A101, A102, A301.
        [, $j] = self::as('deskNew')->ajax('room_status');
        $this->assertSame(['A101', 'A102', 'A301'], array_column($j['data']['rooms'], 'number'));

        // Back to "All TVs": rows removed, logged.
        [$s] = $boss->post('users.php', ['op' => 'save', 'id' => $uid, 'username' => 'deskNew', 'email' => 'desknew@ua.test', 'role' => 'staff', 'language' => 'en', 'is_active' => 1, 'tv_access' => 'all']);
        $this->assertSame(302, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => $uid]));
        $this->assertSame(2, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'user_access' AND entity_id = :u", ['u' => $uid]));
        // An Admin role is never limited (rows ignored / cleared).
        $boss->post('users.php', ['op' => 'save', 'id' => $uid, 'username' => 'deskNew', 'email' => 'desknew@ua.test', 'role' => 'super_admin', 'language' => 'en', 'is_active' => 1,
            'tv_access' => 'some', 'access_rooms' => [$i['A101']]]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => $uid]));
        // Limited again, then deleted: rows deleted with the user.
        $boss->post('users.php', ['op' => 'save', 'id' => $uid, 'username' => 'deskNew', 'email' => 'desknew@ua.test', 'role' => 'reception', 'language' => 'en', 'is_active' => 1,
            'tv_access' => 'some', 'access_rooms' => [$i['A101']]]);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => $uid]));
        [$s] = $boss->post('users.php', ['op' => 'delete', 'id' => $uid]);
        $this->assertSame(302, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => $uid]));

        // Only the Admin manages users.
        [$s] = self::as('mgrAll')->post('users.php', $base + ['access_groups' => [$i['G']], 'username' => 'nope', 'email' => 'nope@ua.test']);
        $this->assertSame(403, $s);
    }

    public function testRoleLabelsShowTheSaasHierarchy(): void
    {
        $this->assertSame('Super Admin (Platform)', role_label('platform_admin'));
        $this->assertSame('Admin', role_label('super_admin'));
        $this->assertSame('Manager', role_label('manager'));
        $this->assertSame('Staff', role_label('staff'));
        $this->assertSame('Reception', role_label('reception'));
        $this->assertSame('Reseller', role_label('reseller'));
        $this->assertSame('એડમિન', I18n::translate('Admin', 'gu'));
        $this->assertSame('सुपर एडमिन (प्लेटफ़ॉर्म)', I18n::translate('Super Admin (Platform)', 'hi'));
        // DB role keys are unchanged.
        $this->assertSame(['super_admin', 'manager', 'staff', 'reception'], Auth::HOTEL_ROLES);
    }

    // ------------------------------------------------------------------ hotel chains switch

    public function testChainsFeatureIsHiddenUntilSwitchedOn(): void
    {
        Settings::setPlatform('feature_chains', '0');
        Settings::flush();
        $p = self::as('padmin');
        [$s, , $html] = $p->get('platform_settings.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('platform_chains.php', $html, 'no chain menu');
        [$s] = $p->get('platform_chains.php');
        $this->assertSame(404, $s);
        [$s] = $p->post('platform_chains.php', ['op' => 'save', 'name' => 'Nope chain']);
        $this->assertSame(404, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM hotel_chains'));
        foreach (['chain.php', 'chain_content.php', 'chain_broadcast.php'] as $page) {
            [$s] = self::as('bossA')->get($page);
            $this->assertSame(404, $s, $page);
        }
        [$s] = self::as('bossA')->ajax('chain_overview');
        $this->assertContains($s, [403, 404]);

        // Platform settings → Features: switch on.
        [$s, , $html] = $p->get('platform_settings.php?tab=features');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="feature_chains"', $html);
        [$s] = $p->post('platform_settings.php', ['op' => 'features', 'tab' => 'features', 'feature_chains' => '1']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('1', (string) Settings::platform('feature_chains'));
        [, , $html] = $p->get('platform_settings.php');
        $this->assertStringContainsString('platform_chains.php', $html);
        [$s] = $p->get('platform_chains.php');
        $this->assertSame(200, $s);
        // … and off again (hotel users never see platform pages).
        $p->post('platform_settings.php', ['op' => 'features', 'tab' => 'features']);
        Settings::flush();
        $this->assertSame('0', (string) Settings::platform('feature_chains'));
        [$s] = $p->get('platform_chains.php');
        $this->assertSame(404, $s);
        [$s] = self::as('bossA')->post('platform_settings.php', ['op' => 'features', 'tab' => 'features', 'feature_chains' => '1']);
        $this->assertSame(403, $s);
        Settings::flush();
        $this->assertSame('0', (string) Settings::platform('feature_chains'));
    }

    // ------------------------------------------------------------------ crawl

    public function testCrawlEveryAdminPageAsLimitedUsersWithoutWarnings(): void
    {
        $i = self::$id;
        $pages = [];
        foreach (glob(HC_ROOT . '/admin/*.php') ?: [] as $f) {
            $b = basename($f);
            if (in_array($b, ['logout.php', 'ajax.php', 'ajax_update.php'], true)) {
                continue;
            }
            $pages[] = $b;
        }
        $pages = array_merge($pages, [
            'rooms.php?action=devices', 'rooms.php?action=edit&id=' . $i['A101'], 'rooms.php?action=device&id=' . $i['dev101'],
            'groups.php?action=edit&id=' . $i['G'], 'schedule.php?action=edit&id=' . $i['S_mine'], 'logs.php?tab=broadcasts', 'logs.php?tab=activity',
            'logs.php?tab=played', 'logs.php?tab=status&export=csv', 'guests.php?tab=history', 'support.php?device=' . $i['dev101'],
            'preview.php?room_id=' . $i['A101'], 'users.php?action=new', 'analytics.php?csv=rooms',
        ]);
        foreach (['deskG', 'mgrG'] as $u) {
            foreach ($pages as $p) {
                [$s, , $body] = self::as($u)->get($p);
                $this->assertContains($s, [200, 302, 403, 404], "$p as $u");
                $this->assertFalse(TestEnv::hasPhpError($body), "PHP error on $p as $u");
                if ($s === 200 && !str_contains($p, 'csv')) {
                    $this->assertStringNotContainsString('A301', $body, "$p as $u lists a room outside the user's TVs");
                }
            }
            foreach (['dashboard_stats', 'room_status', 'schedule_events&start=2020-01-01&end=2040-01-01', 'guests_board', 'guests_alerts'] as $a) {
                [$s, , $body] = self::as($u)->ajax($a);
                $this->assertContains($s, [200, 403], "ajax $a as $u");
                $this->assertStringNotContainsString('A301', $body, "ajax $a as $u");
            }
        }
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testGujaratiTranslationsCoverTheNewStrings(): void
    {
        $files = ['admin/users.php', 'core/Access.php', 'core/helpers.php', 'admin/partials/forbidden.php', 'admin/broadcast.php', 'core/Chains.php', 'admin/platform_settings.php'];
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $missing = [];
        foreach ($files as $f) {
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                if (!isset($gu[$key])) {
                    $missing[] = "gu $f: $key";
                }
            }
        }
        foreach ((require HC_ROOT . '/lang/gu_access.php') as $key => $v) {
            if (!isset($hi[$key])) {
                $missing[] = "hi: $key";
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }
}
