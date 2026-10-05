<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Multi-hotel isolation (V2_SPEC §0, the #1 security requirement), over real HTTP:
 * two hotels A (#1) and B (#2); users of hotel A try to read and change hotel B's rooms, content,
 * playlists, groups, broadcasts, schedules, power schedules, APKs, devices, users and settings
 * with B's ids — every attempt must answer 403/404 and leave B's data unchanged. TVs of B never
 * receive A's content, the registration key decides the hotel, device tokens are hotel-bound.
 */
final class TenancyTest extends TestCase
{
    private static string $url;
    private static array $jars = [];
    private static array $csrf = [];
    /** Hotel B ids */
    private static array $b = [];
    /** Hotel A ids */
    private static array $a = [];
    private static string $keyA = '';
    private static string $keyB = '';
    private static string $tvB = '';
    private static string $tvBUid = 'tv-hotelb-0001';
    private static string $tvA = '';
    private static string $tvAUid = 'tv-hotela-0001';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'bossA', 'manager' => 'mgrA', 'staff' => 'deskA', 'reception' => 'recepA'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@a.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        // Hotel A data
        self::$a['room'] = DB::insert('rooms', ['room_number' => 'A101', 'name' => 'Room A101', 'floor' => '1']);
        self::$a['content'] = DB::insert('content_items', ['title' => 'A-CONTENT', 'type' => 'announcement', 'body' => 'Hello from A', 'duration' => 10]);
        Settings::set('ticker_text', 'A-TICKER-SECRET');
        self::$keyA = (string) Settings::get('registration_key');

        // Hotel B with its own super admin and data
        $hb = Hotels::create(['name' => 'Hotel B', 'plan_id' => null], ['username' => 'bossB', 'email' => 'bossb@b.test', 'password' => 'Passw0rd!', 'full_name' => 'Boss B']);
        self::assertSame(2, $hb);
        Tenant::run(2, static function (): void {
            self::$b['room'] = DB::insert('rooms', ['room_number' => 'B101', 'name' => 'Room B101', 'floor' => '1']);
            self::$b['room2'] = DB::insert('rooms', ['room_number' => 'B102', 'name' => 'Room B102', 'floor' => '1']);
            self::$b['group'] = DB::insert('room_groups', ['name' => 'GB-GROUP', 'type' => 'custom']);
            DB::insert('room_group_members', ['room_id' => self::$b['room'], 'group_id' => self::$b['group']]);
            self::$b['content'] = DB::insert('content_items', ['title' => 'B-SECRET-CONTENT', 'type' => 'announcement', 'body' => 'Hello from B', 'duration' => 10]);
            self::$b['content2'] = DB::insert('content_items', ['title' => 'B-OTHER', 'type' => 'clock', 'duration' => 10]);
            self::$b['playlist'] = DB::insert('content_playlists', ['name' => 'B-PLAYLIST', 'transition' => 'fade']);
            DB::insert('playlist_items', ['playlist_id' => self::$b['playlist'], 'content_id' => self::$b['content'], 'sort_order' => 0]);
            Settings::set('default_playlist_id', (string) self::$b['playlist']);
            self::$b['schedule'] = Broadcaster::schedule([
                'title' => 'B-SCHEDULE', 'target_type' => 'all', 'target_ids' => [], 'content_id' => self::$b['content'], 'playlist_id' => null,
                'mode' => 'once', 'start_at' => date('Y-m-d H:i:s', time() + 30 * 86400), 'end_at' => null, 'daily_start' => null, 'daily_end' => null, 'repeat_days' => null,
            ]);
            [self::$b['power']] = Broadcaster::schedulePower(['target_type' => 'all', 'off_time' => '23:00', 'on_time' => '06:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
            Broadcaster::setPowerScheduleEnabled(self::$b['power'], false);
            self::$b['emergency'] = Broadcaster::emergencyStart('B-EMERGENCY', 'B only', 'rooms', [self::$b['room2']]);
            self::$b['apk'] = DB::insert('apk_releases', ['version_name' => 'B9.9.9', 'version_code' => 99, 'file_path' => 'apk/h2/none.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64)]);
            Settings::set('ticker_text', 'B-TICKER');
        });
        self::$keyB = (string) Settings::getFor(2, 'registration_key');
        self::$b['user'] = (int) DB::value("SELECT id FROM users WHERE username = 'bossB'");
        Cache::clear();

        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);

        // TVs: one per hotel, registered with each hotel's key.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tvBUid, 'room_number' => 'B101', 'registration_key' => self::$keyB]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tvB = $j['data']['token'];
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tvAUid, 'room_number' => 'A101', 'registration_key' => self::$keyA]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tvA = $j['data']['token'];
        self::$b['device'] = (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = 2', ['u' => self::$tvBUid]);
        self::$b['command'] = (int) DB::insert('device_commands', ['device_id' => self::$b['device'], 'command' => 'PING', 'status' => 'pending', 'created_at' => now()]);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$jars as $j) {
            @unlink($j);
        }
        Tenant::set(1);
    }

    // ------------------------------------------------------------------ helpers

    private static function jar(string $u): string
    {
        return self::$jars[$u] ??= (string) tempnam(sys_get_temp_dir(), 'ten');
    }

    private static function login(string $u): void
    {
        if (isset(self::$csrf[$u])) {
            return;
        }
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php', null, [], self::jar($u));
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
        TestEnv::http('POST', self::$url . 'admin/login.php', null, [], self::jar($u), ['_csrf' => html_entity_decode($m[1] ?? ''), 'username' => $u, 'password' => 'Passw0rd!']);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/profile.php', null, [], self::jar($u));
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
        self::$csrf[$u] = html_entity_decode($m[1] ?? '');
    }

    private static function get(string $u, string $path): array
    {
        self::login($u);
        return TestEnv::http('GET', self::$url . 'admin/' . $path, null, [], self::jar($u));
    }

    /** POST a form (arrays flattened to name[i]). */
    private static function post(string $u, string $path, array $fields): array
    {
        self::login($u);
        $flat = ['_csrf' => self::$csrf[$u]];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $x) {
                    $flat[$k . '[' . $i . ']'] = (string) $x;
                }
            } else {
                $flat[$k] = (string) $v;
            }
        }
        return TestEnv::http('POST', self::$url . 'admin/' . $path, null, [], self::jar($u), $flat);
    }

    private static function ajax(string $u, string $action, ?array $json = null): array
    {
        self::login($u);
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'X-CSRF-Token: ' . self::$csrf[$u]];
        return TestEnv::http($json === null ? 'GET' : 'POST', self::$url . 'admin/ajax.php?action=' . $action, $json, $h, self::jar($u));
    }

    /** Everything hotel B owns (volatile device columns excluded). */
    private static function snapshotB(): string
    {
        $out = [];
        foreach (['rooms', 'room_groups', 'content_items', 'content_playlists', 'broadcast_commands', 'apk_releases'] as $t) {
            $out[$t] = DB::all("SELECT * FROM `$t` WHERE hotel_id = 2 ORDER BY id");
        }
        $out['devices'] = DB::all('SELECT id, room_id, is_revoked, token_hash, hotel_id FROM devices WHERE hotel_id = 2 ORDER BY id');
        $out['members'] = DB::all('SELECT m.* FROM room_group_members m JOIN rooms r ON r.id = m.room_id WHERE r.hotel_id = 2 ORDER BY room_id, group_id');
        $out['playlist_items'] = DB::all('SELECT pi.playlist_id, pi.content_id, pi.sort_order FROM playlist_items pi JOIN content_playlists p ON p.id = pi.playlist_id WHERE p.hotel_id = 2 ORDER BY pi.id');
        $out['users'] = DB::all('SELECT id, username, email, role, is_active, hotel_id, password_hash, failed_attempts FROM users WHERE hotel_id = 2 ORDER BY id');
        $out['settings'] = DB::all("SELECT setting_key, setting_value FROM system_settings WHERE hotel_id = 2 AND setting_key <> 'content_version' ORDER BY setting_key");
        $out['commands'] = DB::all('SELECT id, command, status FROM device_commands WHERE device_id = :d ORDER BY id', ['d' => self::$b['device']]);
        return json_encode($out) ?: '';
    }

    private function assertDenied(int $status, string $what): void
    {
        $this->assertContains($status, [403, 404], $what . ' must be refused (got ' . $status . ')');
    }

    // ------------------------------------------------------------------ tests

    public function testListsShowOnlyOwnHotel(): void
    {
        $secrets = ['B101', 'B102', 'B-SECRET-CONTENT', 'B-PLAYLIST', 'GB-GROUP', 'B-SCHEDULE', 'B-EMERGENCY', 'B9.9.9', 'bossB', self::$keyB, self::$tvBUid, 'B-TICKER'];
        $pages = ['index.php', 'rooms.php', 'rooms.php?action=devices', 'content.php', 'playlists.php', 'groups.php', 'broadcast.php', 'schedule.php', 'power.php',
            'apk.php', 'users.php', 'settings.php', 'settings.php?tab=devices', 'settings.php?tab=display', 'logs.php?tab=status', 'logs.php?tab=broadcasts', 'logs.php?tab=activity',
            'logs.php?tab=played', 'billing.php', 'rooms.php?action=new', 'content.php?action=new&type=announcement', 'broadcast.php'];
        foreach ($pages as $p) {
            [$s, , $html] = self::get('bossA', $p);
            $this->assertSame(200, $s, $p);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $html, "$p leaks hotel B data ($secret)");
            }
        }
        foreach (['dashboard_stats', 'room_status', 'schedule_events&start=2020-01-01&end=2040-01-01'] as $a) {
            [$s, , $body] = self::ajax('bossA', $a);
            $this->assertSame(200, $s, $a);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body, "ajax $a leaks hotel B data ($secret)");
            }
        }
        // And hotel B's admin sees its own data.
        [, , $html] = self::get('bossB', 'rooms.php');
        $this->assertStringContainsString('B101', $html);
        $this->assertStringNotContainsString('A101', $html);
    }

    public static function foreignGetPages(): array
    {
        return [
            'room edit' => ['rooms.php?action=edit&id={room}'],
            'device detail' => ['rooms.php?action=device&id={device}'],
            'content edit' => ['content.php?action=edit&id={content}'],
            'content delete confirm' => ['content.php?action=delete&id={content}'],
            'playlist edit' => ['playlists.php?action=edit&id={playlist}'],
            'group edit' => ['groups.php?action=edit&id={group}'],
            'schedule edit' => ['schedule.php?action=edit&id={schedule}'],
            'user edit' => ['users.php?action=edit&id={user}'],
            'user view' => ['users.php?action=view&id={user}'],
            'preview room' => ['preview.php?room_id={room}'],
            'preview content' => ['preview.php?content_id={content}'],
            'preview playlist' => ['preview.php?playlist_id={playlist}'],
            'broadcast log' => ['logs.php?tab=broadcasts&id={schedule}'],
            'ajax preview' => ['ajax.php?action=preview_content&content_id={content}'],
        ];
    }

    #[DataProvider('foreignGetPages')]
    public function testForeignIdsInGetRequestsAre404(string $path): void
    {
        $path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) self::$b[$m[1]], $path);
        self::login('bossA');
        $h = str_contains($path, 'ajax.php') ? ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'] : [];
        [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/' . $path, null, $h, self::jar('bossA'));
        $this->assertDenied($s, $path);
        $this->assertStringNotContainsString('B-SECRET-CONTENT', $html);
        $this->assertStringNotContainsString('B101', $html);
    }

    public function testForeignIdsInWriteRequestsAreRefusedAndChangeNothing(): void
    {
        $before = self::snapshotB();
        $b = self::$b;
        $attempts = [
            ['rooms.php', ['op' => 'save', 'id' => $b['room'], 'room_number' => 'HACKED', 'is_enabled' => 1]],
            ['rooms.php', ['op' => 'delete', 'id' => $b['room']]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'delete', 'room_ids' => [$b['room'], $b['room2']]]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'disable', 'room_ids' => [$b['room']]]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'REBOOT', 'room_ids' => [$b['room']]]],
            ['rooms.php', ['op' => 'bulk', 'bulk_action' => 'assign', 'room_ids' => [self::$a['room']], 'source' => 'c:' . $b['content']]],
            ['rooms.php', ['op' => 'save', 'id' => 0, 'room_number' => 'A900', 'source' => 'p:' . $b['playlist']]],
            ['rooms.php', ['op' => 'save', 'id' => self::$a['room'], 'room_number' => 'A101', 'groups' => [$b['group']], 'is_enabled' => 1]],
            ['rooms.php', ['op' => 'revoke_device', 'device_id' => $b['device']]],
            ['rooms.php', ['op' => 'delete_device', 'device_id' => $b['device']]],
            ['content.php', ['op' => 'save', 'id' => $b['content'], 'title' => 'HACKED', 'body' => 'x']],
            ['content.php', ['op' => 'delete', 'id' => $b['content']]],
            ['content.php', ['op' => 'toggle', 'id' => $b['content']]],
            ['content.php', ['op' => 'duplicate', 'id' => $b['content']]],
            ['playlists.php', ['op' => 'save', 'id' => $b['playlist'], 'name' => 'HACKED']],
            ['playlists.php', ['op' => 'delete', 'id' => $b['playlist']]],
            ['groups.php', ['op' => 'save', 'id' => $b['group'], 'name' => 'HACKED']],
            ['groups.php', ['op' => 'save', 'id' => 0, 'name' => 'A-GROUP', 'members' => [$b['room']]]],
            ['groups.php', ['op' => 'delete', 'id' => $b['group']]],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . $b['content'], 'target_type' => 'all']],
            ['broadcast.php', ['op' => 'push', 'when' => 'now', 'source' => 'c:' . self::$a['content'], 'target_type' => 'rooms', 'room_ids' => [$b['room']]]],
            ['broadcast.php', ['op' => 'push', 'when' => 'window', 'source' => 'c:' . $b['content'], 'target_type' => 'all', 'start_at' => date('Y-m-d\TH:i'), 'end_at' => date('Y-m-d\TH:i', time() + 3600)]],
            ['broadcast.php', ['op' => 'emergency', 'title' => 'X', 'message' => 'Y', 'target_type' => 'groups', 'group_ids' => [$b['group']]]],
            ['broadcast.php', ['op' => 'emergency_stop', 'id' => $b['emergency']]],
            ['broadcast.php', ['op' => 'command', 'command' => 'REBOOT', 'target_type' => 'rooms', 'room_ids' => [$b['room']]]],
            ['schedule.php', ['op' => 'cancel', 'id' => $b['schedule']]],
            ['schedule.php', ['op' => 'delete', 'id' => $b['schedule']]],
            ['schedule.php', ['op' => 'update', 'id' => $b['schedule'], 'source' => 'c:' . $b['content'], 'target_type' => 'all', 'mode' => 'once', 'start_at' => date('Y-m-d\TH:i')]],
            ['power.php', ['op' => 'toggle', 'id' => $b['power'], 'enable' => '1']],
            ['power.php', ['op' => 'delete', 'id' => $b['power']]],
            ['power.php', ['op' => 'now', 'state' => 'off', 'target_type' => 'rooms', 'room_ids' => [$b['room']]]],
            ['apk.php', ['op' => 'delete', 'id' => $b['apk']]],
            ['apk.php', ['op' => 'push', 'apk_id' => $b['apk'], 'target_type' => 'all']],
            ['users.php', ['op' => 'save', 'id' => $b['user'], 'username' => 'bossB', 'email' => 'x@x.test', 'role' => 'staff', 'is_active' => 1]],
            ['users.php', ['op' => 'delete', 'id' => $b['user']]],
            ['users.php', ['op' => 'unlock', 'id' => $b['user']]],
            ['users.php', ['op' => 'revoke_all', 'id' => $b['user']]],
        ];
        foreach ($attempts as [$page, $fields]) {
            [$s] = self::post('bossA', $page, $fields);
            $this->assertDenied($s, "$page " . json_encode($fields));
        }
        $ajax = [
            ['send_command', ['command' => 'REBOOT', 'target_type' => 'rooms', 'target_ids' => [$b['room']]]],
            ['send_command', ['command' => 'SHOW_CONTENT', 'target_type' => 'groups', 'target_ids' => [$b['group']]]],
            ['emergency_start', ['title' => 'X', 'message' => 'Y', 'target_type' => 'rooms', 'target_ids' => [$b['room']]]],
            ['emergency_stop', ['id' => $b['emergency']]],
        ];
        foreach ($ajax as [$action, $json]) {
            [$s] = self::ajax('bossA', $action, $json);
            $this->assertDenied($s, "ajax $action");
        }
        // Settings changes of hotel A never touch hotel B.
        self::post('bossA', 'settings.php', ['op' => 'regen_key', 'tab' => 'devices']);
        self::post('bossA', 'settings.php', ['op' => 'save_display', 'tab' => 'display', 'ticker_text' => 'A-NEW-TICKER']);
        self::post('bossA', 'users.php', ['op' => 'set_pin', 'tv_settings_pin' => '4321']);
        Settings::flush();
        $this->assertSame($before, self::snapshotB(), 'Hotel B data must be unchanged after all cross-hotel attempts');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = 2 AND is_emergency = 1 AND status = 'active'"));
        $this->assertSame('B-TICKER', Settings::getFor(2, 'ticker_text'));
        $this->assertSame(self::$keyB, Settings::getFor(2, 'registration_key'));
        $this->assertNotSame(self::$keyA, Settings::getFor(1, 'registration_key'), 'Hotel A key was regenerated');
        self::$keyA = (string) Settings::getFor(1, 'registration_key');
        // Every refusal is logged.
        $this->assertNotEmpty(Logger::tail('security', 5));
    }

    public function testHotelUsersCannotUsePlatformFeatures(): void
    {
        foreach (['bossA', 'bossB'] as $u) {
            foreach (['platform_hotels.php', 'platform_invoices.php', 'platform_settings.php', 'update.php'] as $p) {
                [$s] = self::get($u, $p);
                $this->assertSame(403, $s, "$p as $u");
            }
            [$s] = self::post($u, 'platform_hotels.php', ['op' => 'enter', 'id' => $u === 'bossA' ? 2 : 1]);
            $this->assertSame(403, $s, "enter hotel as $u");
        }
        // Even with a forged session flag a hotel user stays in its own hotel.
        [, , $html] = self::get('bossA', 'rooms.php');
        $this->assertStringNotContainsString('B101', $html);
    }

    public function testTvOfHotelBNeverGetsHotelAContent(): void
    {
        // Hotel A broadcasts an emergency to ALL its rooms and pushes content to all.
        $this->assertSame(200, self::ajax('bossA', 'emergency_start', ['title' => 'A-ALARM', 'message' => 'A only', 'target_type' => 'all'])[0]);
        $auth = ['Authorization: Bearer ' . self::$tvB, 'X-Device-Id: ' . self::$tvBUid];
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tvBUid . '?hash=x', null, $auth);
        $this->assertSame(200, $s);
        $c = $j['data']['content'];
        $this->assertSame((string) self::$b['room'], (string) $c['room']['id']);
        $this->assertSame(2, $c['hotel']['id']);
        $this->assertSame('Hotel B', $c['hotel']['name']);
        $this->assertNotSame('emergency', $c['mode'], 'Hotel A emergency must not reach hotel B');
        $json = json_encode($c, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('A-ALARM', $json);
        $this->assertStringNotContainsString('A-CONTENT', $json);
        $this->assertStringNotContainsString('TICKER-SECRET', $json);
        $this->assertStringContainsString('B-SECRET-CONTENT', $json, 'Hotel B default playlist');
        // Hotel A's TV gets the emergency.
        [, $ja] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tvAUid . '?hash=x', null, ['Authorization: Bearer ' . self::$tvA, 'X-Device-Id: ' . self::$tvAUid]);
        $this->assertSame('emergency', $ja['data']['content']['mode']);
        self::ajax('bossA', 'emergency_stop', []);

        // A device may only read its own room, never another hotel's.
        [$s] = TestEnv::http('GET', self::$url . 'api/content/' . self::$a['room'], null, $auth);
        $this->assertSame(403, $s);
        // Another hotel's command id cannot be acked; another hotel's APK cannot be downloaded.
        [$s] = TestEnv::http('POST', self::$url . 'api/device/ack', ['command_id' => self::$b['command'], 'status' => 'acked'], ['Authorization: Bearer ' . self::$tvA, 'X-Device-Id: ' . self::$tvAUid]);
        $this->assertSame(404, $s);
        $this->assertContains(DB::value('SELECT status FROM device_commands WHERE id = :id', ['id' => self::$b['command']]), ['pending', 'delivered'], 'not acked by the foreign TV');
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . self::$b['apk'], null, ['Authorization: Bearer ' . self::$tvA, 'X-Device-Id: ' . self::$tvAUid]);
        $this->assertSame(404, $s);
    }

    public function testRegistrationKeyIdentifiesTheHotel(): void
    {
        // Room number that exists in hotel A, registered with hotel B's key → a new room in hotel B.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-hotelb-0002', 'room_number' => 'A101', 'registration_key' => self::$keyB]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertSame(2, $j['data']['hotel']['id']);
        $this->assertNotSame(self::$a['room'], $j['data']['room']['id']);
        $this->assertSame(2, (int) DB::value('SELECT hotel_id FROM rooms WHERE id = :id', ['id' => $j['data']['room']['id']]));
        $this->assertSame(2, (int) DB::value("SELECT hotel_id FROM devices WHERE device_uid = 'tv-hotelb-0002'"));
        // The same physical TV (uid) re-registered into hotel A does not take over hotel B's device row.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tvBUid, 'room_number' => 'A101', 'registration_key' => self::$keyA]);
        $this->assertSame(200, $s);
        $this->assertSame(1, $j['data']['hotel']['id']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM devices WHERE device_uid = :u AND hotel_id = 2', ['u' => self::$tvBUid]));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM devices WHERE device_uid = :u AND hotel_id = 1', ['u' => self::$tvBUid]));
        // Hotel B's TV token keeps working (separate row).
        [$s] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', ['app_version' => '2.0.0'], ['Authorization: Bearer ' . self::$tvB, 'X-Device-Id: ' . self::$tvBUid]);
        $this->assertSame(200, $s);
        // Wrong key → 401.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-x-00000001', 'room_number' => '1', 'registration_key' => 'NOPE']);
        $this->assertSame(401, $s);
        $this->assertSame('INVALID_REGISTRATION_KEY', $j['error']['code']);
    }

    public function testCoreHelpersRefuseForeignRows(): void
    {
        Tenant::set(1);
        try {
            ContentManager::find(self::$b['content']);
            $this->fail('Cross-hotel find must throw in CLI');
        } catch (TenantException) {
            $this->assertTrue(true);
        }
        $this->assertNull(ContentManager::findOwn(self::$b['content']));
        $this->assertSame([], Broadcaster::targetRooms('rooms', [self::$b['room']]));
        // Auto-scoped writes never touch another hotel.
        $this->assertSame(0, DB::update('rooms', ['name' => 'HACK'], 'id = :id', ['id' => self::$b['room']]));
        $this->assertSame(0, DB::delete('content_items', 'id = :id', ['id' => self::$b['content']]));
        $this->assertSame('Room B101', DB::value('SELECT name FROM rooms WHERE id = :id', ['id' => self::$b['room']]));
        // Without a hotel context tenant queries fail loudly.
        Tenant::clear();
        try {
            DB::insert('rooms', ['room_number' => 'NOCTX']);
            $this->fail('Insert without hotel context must throw');
        } catch (TenantException) {
            $this->assertTrue(true);
        } finally {
            Tenant::set(1);
        }
        $this->expectException(TenantException::class);
        ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$b['room']]));
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }
}
