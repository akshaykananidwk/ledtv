<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PWA + push (#14), support tools (#24), TV device controls (#4 #5 #15 #16) and the setup file (#23):
 * device support endpoints (auth, size limits, rate limits, cross-hotel), command queuing with the
 * exact payloads the Android app parses, the DeviceControlsExtension output, tvs.csv in the format
 * of tools/windows/HotelCast-Setup.ps1, manifest / service worker / icons, push subscription CRUD,
 * StaffAlerts permission filtering, alert tasks and the platform support dashboard numbers.
 */
final class PwaSupportDevicesTest extends TestCase
{
    private static string $url;
    private static array $u = [];
    private static array $room = [];
    private static string $tv1 = '';
    private static string $tv1Uid = 'tv-pwa-h1-0001';
    private static int $dev1 = 0;
    private static string $tv2 = '';
    private static string $tv2Uid = 'tv-pwa-h2-0001';
    private static int $dev2 = 0;
    private static string $key1 = '';
    private static string $key2 = '';
    private static array $extraFiles = [];
    /** @var array<int, array{0:string,1:array,2:string}> */
    private static array $pushCalls = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['pboss1' => 'super_admin', 'pmgr1' => 'manager', 'pstaff1' => 'staff', 'precep1' => 'reception'] as $name => $role) {
            self::$u[$name] = DB::insert('users', ['hotel_id' => 1, 'username' => $name, 'email' => $name . '@h1.test', 'full_name' => $name, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$u['pplat'] = DB::insert('users', ['hotel_id' => null, 'username' => 'pplat', 'email' => 'pplat@p.test', 'full_name' => 'Platform', 'password_hash' => $pw, 'role' => 'platform_admin']);
        foreach (['101', '102', '103'] as $n) {
            self::$room[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        Settings::set('hotel_name', 'Hotel One');
        self::$key1 = (string) Settings::get('registration_key');
        $h2 = Hotels::create(['name' => 'Hotel Two']);
        self::assertSame(2, $h2);
        Tenant::run(2, static function () use ($pw): void {
            self::$room['201'] = DB::insert('rooms', ['room_number' => '201', 'name' => 'Room 201', 'floor' => '2']);
            self::$u['pmgr2'] = DB::insert('users', ['hotel_id' => 2, 'username' => 'pmgr2', 'email' => 'pmgr2@h2.test', 'full_name' => 'M2', 'password_hash' => $pw, 'role' => 'manager']);
        });
        self::$key2 = (string) Settings::getFor(2, 'registration_key');
        // Keep the test server from running this module's tasks by itself (tests call them directly).
        foreach (['StaffAlertsTask', 'SupportAlertTask', 'SupportCleanupTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        ContentResolver::resetExtensions();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);

        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tv1Uid, 'room_number' => '101', 'registration_key' => self::$key1, 'app_version' => '2.0.0', 'app_version_code' => 20, 'ip_address' => '192.168.1.45']);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tv1 = $j['data']['token'];
        self::$dev1 = (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = 1', ['u' => self::$tv1Uid]);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tv2Uid, 'room_number' => '201', 'registration_key' => self::$key2, 'ip_address' => '10.0.0.9']);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tv2 = $j['data']['token'];
        self::$dev2 = (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = 2', ['u' => self::$tv2Uid]);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$extraFiles as $f) {
            @unlink($f);
        }
        ContentResolver::resetExtensions();
        WebPush::$transport = null;
        Notifier::$mailer = null;
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        self::$pushCalls = [];
        WebPush::$transport = static function (string $url, array $headers, string $body): array {
            self::$pushCalls[] = [$url, $headers, $body];
            return ['status' => str_contains($url, '/gone') ? 410 : (str_contains($url, '/fail') ? 500 : 201), 'body' => ''];
        };
    }

    // ------------------------------------------------------------------ helpers

    private static function devHeaders(int $hotel = 1): array
    {
        return $hotel === 1
            ? ['Authorization: Bearer ' . self::$tv1, 'X-Device-Id: ' . self::$tv1Uid]
            : ['Authorization: Bearer ' . self::$tv2, 'X-Device-Id: ' . self::$tv2Uid];
    }

    private static function api(string $route, ?array $json, int $hotel = 1, ?array $form = null): array
    {
        return TestEnv::http('POST', self::$url . 'api/' . $route, $json, self::devHeaders($hotel), null, $form);
    }

    private static function jpegFile(int $w = 320, int $h = 180): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        $f = tempnam(sys_get_temp_dir(), 'shot') . '.jpg';
        imagejpeg($im, $f, 80);
        imagedestroy($im);
        self::$extraFiles[] = $f;
        return $f;
    }

    /** Poll as TV 1, return the raw command objects (raw JSON kept for exact payload checks) and ack them. */
    private static function pollCommands(int $hotel = 1): array
    {
        $uid = $hotel === 1 ? self::$tv1Uid : self::$tv2Uid;
        [$s, $j, $raw] = TestEnv::http('GET', self::$url . 'api/device/command/' . $uid . '?hash=x', null, self::devHeaders($hotel));
        self::assertSame(200, $s, $raw);
        preg_match('/"commands":(\[.*?\])(,"|\})/s', $raw, $m);
        foreach ($j['data']['commands'] as $c) {
            self::api('device/ack', ['command_id' => $c['id'], 'status' => 'acked', 'message' => 'ok'], $hotel);
        }
        return ['list' => $j['data']['commands'], 'raw' => $m[1] ?? '[]'];
    }

    private static function uaKeys(): array
    {
        $kp = WebPush::generateKeyPair();
        $kp['auth'] = random_bytes(16);
        return $kp;
    }

    private static function addSub(int $userId, ?int $hotelId, string $endpoint, ?string $types = null, ?array $keys = null): array
    {
        $keys ??= self::uaKeys();
        DB::insert('push_subscriptions', [
            'hotel_id' => $hotelId, 'user_id' => $userId, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => WebPush::b64uEncode($keys['public']), 'auth' => WebPush::b64uEncode($keys['auth']), 'alert_types' => $types,
            'created_at' => now(),
        ]);
        return $keys;
    }

    private static function pushedTo(): array
    {
        $eps = array_map(static fn ($c) => $c[0], self::$pushCalls);
        sort($eps);
        return $eps;
    }

    // ------------------------------------------------------------------ device support endpoints

    public function testSupportEndpointsNeedDeviceAuth(): void
    {
        foreach (['device/screenshot', 'device/logs', 'device/crash', 'device/event'] as $r) {
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/' . $r, ['type' => 'x']);
            $this->assertSame(401, $s, $r);
            $this->assertSame('UNAUTHENTICATED', $j['error']['code']);
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/' . $r, ['type' => 'x'], ['Authorization: Bearer ' . str_repeat('a', 64)]);
            $this->assertSame(401, $s, $r);
            $this->assertSame('INVALID_TOKEN', $j['error']['code']);
            [$s] = TestEnv::http('GET', self::$url . 'api/' . $r, null, self::devHeaders());
            $this->assertSame(405, $s, $r . ' is POST only');
        }
    }

    public function testScreenshotUploadValidationAndStorage(): void
    {
        [$s, $j] = self::api('device/screenshot', null, 1, ['image' => new CURLFile(self::jpegFile(), 'image/jpeg', 'screenshot-1.jpg')]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $row = DB::one('SELECT * FROM device_support_files WHERE id = :id', ['id' => $j['data']['id']]);
        $this->assertSame(1, (int) $row['hotel_id']);
        $this->assertSame(self::$dev1, (int) $row['device_id']);
        $this->assertSame(self::$room['101'], (int) $row['room_id']);
        $this->assertSame('screenshot', $row['kind']);
        $this->assertMatchesRegularExpression('#^h1/d' . self::$dev1 . '/screenshot-[\w.-]+\.jpg$#', $row['file_path']);
        $this->assertFileExists(HC_ROOT . '/storage/support/' . $row['file_path']);
        $this->assertSame(['width' => 320, 'height' => 180], json_decode($row['meta'], true));

        // Not a JPEG (PNG bytes), missing field, too large.
        $png = tempnam(sys_get_temp_dir(), 'png');
        self::$extraFiles[] = $png;
        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $png);
        [$s, $j] = self::api('device/screenshot', null, 1, ['image' => new CURLFile($png, 'image/jpeg', 'x.jpg')]);
        $this->assertSame(400, $s);
        $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
        [$s, $j] = self::api('device/screenshot', null, 1, ['other' => 'x']);
        $this->assertSame(400, $s);
        $big = tempnam(sys_get_temp_dir(), 'big');
        self::$extraFiles[] = $big;
        file_put_contents($big, "\xFF\xD8\xFF\xE0" . random_bytes(2 * 1024 * 1024 + 70000));
        [$s, $j] = self::api('device/screenshot', null, 1, ['image' => new CURLFile($big, 'image/jpeg', 'big.jpg')]);
        $this->assertSame(413, $s);
        $this->assertSame('PAYLOAD_TOO_LARGE', $j['error']['code']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM device_support_files WHERE device_id = :d AND kind = 'screenshot'", ['d' => self::$dev1]));
        // Storage is never web reachable.
        [$s] = TestEnv::http('GET', self::$url . 'storage/support/' . $row['file_path']);
        $this->assertSame(403, $s);
    }

    public function testLogsAndCrashLimitsAndRetention(): void
    {
        Settings::set('support_keep_per_device', '2');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            [$s, $j] = self::api('device/logs', ['logs' => "line A $i\nline B ગુજરાતી", 'state' => ['app' => ['version_name' => '2.0.0'], 'n' => $i]]);
            $this->assertSame(200, $s, (string) json_encode($j));
            $ids[] = $j['data']['id'];
        }
        $rows = DB::all("SELECT * FROM device_support_files WHERE device_id = :d AND kind = 'logs' ORDER BY id", ['d' => self::$dev1]);
        $this->assertSame([$ids[1], $ids[2]], array_map(fn ($r) => (int) $r['id'], $rows), 'only the newest 2 kept');
        $this->assertFileDoesNotExist(HC_ROOT . '/storage/support/h1/d' . self::$dev1 . '/gone', 'sanity');
        $text = (string) file_get_contents(HC_ROOT . '/storage/support/' . $rows[1]['file_path']);
        $this->assertStringContainsString('line B ગુજરાતી', $text);
        $this->assertStringContainsString('"n": 2', $text);
        $this->assertSame(2, json_decode($rows[1]['meta'], true)['lines']);
        Settings::set('support_keep_per_device', '10');

        [$s, $j] = self::api('device/logs', ['logs' => str_repeat('x', 512 * 1024 + 1), 'state' => []]);
        $this->assertSame(413, $s);
        $this->assertSame('PAYLOAD_TOO_LARGE', $j['error']['code']);
        [$s] = self::api('device/logs', ['logs' => str_repeat('x', 512 * 1024), 'state' => []]);
        $this->assertSame(200, $s, 'exactly 512 KB is accepted');
        [$s] = self::api('device/logs', ['state' => []]);
        $this->assertSame(400, $s);
        [$s] = self::api('device/logs', ['logs' => 'x', 'state' => 'nope']);
        $this->assertSame(400, $s);

        [$s, $j] = self::api('device/crash', ['stack' => "Thread: main\njava.lang.IllegalStateException: boom\n\tat com.hotelcast.tv.X.y(X.kt:1)", 'app_version' => '2.0.0 (20)', 'happened_at' => date('c', time() - 120)]);
        $this->assertSame(200, $s);
        $crash = DB::one('SELECT * FROM device_support_files WHERE id = :id', ['id' => $j['data']['id']]);
        $this->assertSame('crash', $crash['kind']);
        $this->assertSame('2.0.0 (20)', $crash['app_version']);
        $this->assertSame(date('Y-m-d H:i', time() - 120), substr((string) $crash['happened_at'], 0, 16));
        $this->assertSame('java.lang.IllegalStateException: boom', json_decode($crash['meta'], true)['summary']);
        [$s] = self::api('device/crash', ['stack' => str_repeat('y', 64 * 1024 + 1)]);
        $this->assertSame(413, $s);
        [$s] = self::api('device/crash', ['stack' => '']);
        $this->assertSame(400, $s);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testEventsAreStoredForAnalyticsAndRateLimited(): void
    {
        [$s, $j] = self::api('device/event', ['type' => 'input_switch', 'data' => ['input' => 'hdmi1', 'ok' => true]]);
        $this->assertSame(200, $s);
        $ev = DB::one('SELECT * FROM device_events WHERE id = :id', ['id' => $j['data']['id']]);
        $this->assertSame([1, self::$dev1, self::$room['101'], 'input_switch'], [(int) $ev['hotel_id'], (int) $ev['device_id'], (int) $ev['room_id'], $ev['type']]);
        $this->assertSame(['input' => 'hdmi1', 'ok' => true], json_decode($ev['data'], true));
        [$s] = self::api('device/event', ['type' => 'guest_menu_open']);
        $this->assertSame(200, $s, 'data is optional');
        [$s] = self::api('device/event', ['type' => 'Bad Type!']);
        $this->assertSame(400, $s);
        [$s] = self::api('device/event', ['type' => 'x', 'data' => ['blob' => str_repeat('z', 5000)]]);
        $this->assertSame(413, $s);

        // Per-device hourly upload limit → 429 with Retry-After.
        $now = time();
        DB::query('INSERT INTO rate_limits (rl_key, hits, window_start) VALUES (:k, 30, :w)', ['k' => 'sup:device/crash:' . self::$dev1, 'w' => $now - ($now % 3600)]);
        [$s, $j, , $head] = self::api('device/crash', ['stack' => 'x']);
        $this->assertSame(429, $s);
        $this->assertSame('RATE_LIMITED', $j['error']['code']);
        $this->assertMatchesRegularExpression('/Retry-After: \d+/i', $head);
    }

    public function testCrossHotelIsolationOfSupportData(): void
    {
        [$s, $j] = self::api('device/logs', ['logs' => 'HOTEL-TWO-SECRET', 'state' => []], 2);
        $this->assertSame(200, $s);
        $f2 = DB::one('SELECT * FROM device_support_files WHERE id = :id', ['id' => $j['data']['id']]);
        $this->assertSame(2, (int) $f2['hotel_id']);
        $this->assertStringStartsWith('h2/d' . self::$dev2 . '/', $f2['file_path']);

        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s, , $body] = $m1->get('support.php?action=file&id=' . $f2['id']);
        $this->assertSame(404, $s);
        $this->assertStringNotContainsString('HOTEL-TWO-SECRET', $body);
        [$s] = $m1->get('support.php?device=' . self::$dev2);
        $this->assertSame(404, $s);
        [$s, $j] = $m1->ajax('support_request', ['device_id' => self::$dev2, 'command' => 'SCREENSHOT']);
        $this->assertSame(404, $s);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d AND command = 'SCREENSHOT'", ['d' => self::$dev2]));
        [$s, , $body] = $m1->get('support.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString(self::$tv2Uid, $body);

        $m2 = new AdminSession(self::$url, 'pmgr2');
        [$s, , $body] = $m2->get('support.php?action=file&id=' . $f2['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('HOTEL-TWO-SECRET', $body);
        // The device itself cannot pretend to be in another hotel: hotel 2's token stores into hotel 2 only.
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_support_files WHERE hotel_id = 1 AND device_id = :d", ['d' => self::$dev2]));
    }

    public function testRequestScreenshotFlowFromAdmin(): void
    {
        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s, $j] = $m1->ajax('support_request', ['device_id' => self::$dev1, 'command' => 'SCREENSHOT']);
        $this->assertSame(200, $s, (string) json_encode($j));
        $cid = $j['data']['command_id'];
        [$s, $j] = $m1->ajax('support_status&device_id=' . self::$dev1 . '&command_id=' . $cid);
        $this->assertSame(200, $s);
        $this->assertFalse($j['data']['done']);
        $this->assertNull($j['data']['file']);

        $cmds = self::pollCommands();
        $this->assertStringContainsString('"command":"SCREENSHOT","payload":{}', $cmds['raw']);
        sleep(1); // file created_at must not precede the request second
        [$s] = self::api('device/screenshot', null, 1, ['image' => new CURLFile(self::jpegFile(640, 360), 'image/jpeg', 's.jpg')]);
        $this->assertSame(200, $s);
        [$s, $j] = $m1->ajax('support_status&device_id=' . self::$dev1 . '&command_id=' . $cid);
        $this->assertTrue($j['data']['done']);
        $this->assertSame('screenshot', $j['data']['file']['kind']);
        $this->assertSame('acked', $j['data']['status']);
        [$s, , $img, $head] = $m1->get('support.php?action=file&id=' . $j['data']['file']['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Content-Type: image/jpeg', $head);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($img)[2]);
        [$s, , $html] = $m1->get('support.php?device=' . self::$dev1);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Take screenshot', $html);
        $this->assertStringContainsString('action=file&amp;id=' . $j['data']['file']['id'], $html);
        $this->assertFalse(TestEnv::hasPhpError($html));

        [$s, $j] = $m1->ajax('support_request', ['device_id' => self::$dev1, 'command' => 'UPLOAD_LOGS']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('"command":"UPLOAD_LOGS","payload":{}', self::pollCommands()['raw']);
        [$s] = $m1->ajax('support_request', ['device_id' => self::$dev1, 'command' => 'REBOOT']);
        $this->assertSame(422, $s);

        // Staff / reception: no support tools.
        foreach (['pstaff1', 'precep1'] as $name) {
            $s1 = new AdminSession(self::$url, $name);
            [$s] = $s1->get('support.php');
            $this->assertSame(403, $s, $name);
            [$s] = $s1->ajax('support_request', ['device_id' => self::$dev1, 'command' => 'SCREENSHOT']);
            $this->assertSame(403, $s, $name);
        }
    }

    // ------------------------------------------------------------------ TV commands

    public function testTvControlCommandsHaveTheExactAndroidPayloads(): void
    {
        $m1 = new AdminSession(self::$url, 'pmgr1');
        $target = ['target_type' => 'rooms', 'room_ids' => [self::$room['101']]];
        $cases = [
            [['command' => 'SET_VOLUME', 'level' => '40'], '"command":"SET_VOLUME","payload":{"level":40}'],
            [['command' => 'MUTE', 'level' => '40'], '"command":"MUTE","payload":{}'],
            [['command' => 'UNMUTE'], '"command":"UNMUTE","payload":{}'],
            [['command' => 'OPEN_INPUT', 'input' => 'hdmi2'], '"command":"OPEN_INPUT","payload":{"input":"hdmi2"}'],
            [['command' => 'OPEN_INPUT', 'input' => 'live_tv'], '"command":"OPEN_INPUT","payload":{"input":"live_tv"}'],
            [['command' => 'SHOW_WELCOME'], '"command":"SHOW_WELCOME","payload":{}'],
            [['command' => 'SHOW_MESSAGE', 'title' => 'Your food', 'message' => 'is on the way 🍛', 'duration_sec' => '20'], '"command":"SHOW_MESSAGE","payload":{"title":"Your food","message":"is on the way 🍛","duration_sec":20}'],
        ];
        foreach ($cases as [$fields, $expect]) {
            [$s] = $m1->post('tv_controls.php', ['op' => 'command'] + $fields + $target);
            $this->assertSame(302, $s, $fields['command']);
            $this->assertStringContainsString($expect, self::pollCommands()['raw'], $fields['command']);
        }
        $this->assertSame('SET_VOLUME', DB::value("SELECT command FROM broadcast_commands WHERE command = 'SET_VOLUME' AND hotel_id = 1 LIMIT 1"), 'broadcast_commands.command accepts new commands');
        // Invalid values queue nothing.
        foreach ([['command' => 'SET_VOLUME', 'level' => '150'], ['command' => 'OPEN_INPUT', 'input' => 'hdmi9'], ['command' => 'SHOW_MESSAGE'], ['command' => 'REBOOT']] as $bad) {
            $m1->post('tv_controls.php', ['op' => 'command'] + $bad + $target);
            $this->assertSame([], self::pollCommands()['list'], json_encode($bad));
        }
        // Only the targeted rooms of the current hotel; another hotel's room id is refused.
        [$s] = $m1->post('tv_controls.php', ['op' => 'command', 'command' => 'MUTE', 'target_type' => 'rooms', 'room_ids' => [self::$room['201']]]);
        $this->assertSame(404, $s);
        $this->assertSame([], self::pollCommands(2)['list']);
        // TvControls also works directly (used by other modules), e.g. "Your food is on the way".
        [, $count] = TvControls::send('SHOW_MESSAGE', 'all', [], ['title' => 'Hi', 'message' => '', 'duration_sec' => 1]);
        $this->assertSame(1, $count);
        $this->assertStringContainsString('"payload":{"title":"Hi","message":"","duration_sec":3}', self::pollCommands()['raw'], 'duration clamped to 3..3600');
        $staff = new AdminSession(self::$url, 'pstaff1');
        [$s] = $staff->post('tv_controls.php', ['op' => 'command', 'command' => 'MUTE', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        $this->assertSame([], self::pollCommands()['list']);
        $this->assertContains('SET_VOLUME', Broadcaster::DEVICE_COMMANDS);
    }

    // ------------------------------------------------------------------ content extension

    public function testContentExtensionVolumeAndGuestMenu(): void
    {
        $room = Tenant::find('rooms', self::$room['101']);
        $c = ContentResolver::build($room);
        $this->assertArrayNotHasKey('volume', $c, 'no volume policy until enabled');
        $this->assertSame([], array_values(array_filter((array) ($c['guest_menu'] ?? []), fn ($i) => in_array($i['type'], ['live_tv', 'input', 'cast'], true))));

        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s] = $m1->post('tv_controls.php', ['op' => 'save_volume', 'tv_volume_enabled' => '1', 'volume_default' => '30', 'volume_max' => '80',
            'volume_night_enabled' => '1', 'volume_night_max' => '20', 'volume_night_from' => '22:00', 'volume_night_to' => '06:30']);
        $this->assertSame(302, $s);
        [$s] = $m1->post('tv_controls.php', ['op' => 'save_menu', 'guest_menu_live_tv' => '1', 'input_hdmi1' => '1', 'label_hdmi1' => 'Set-top box',
            'input_hdmi3' => '1', 'label_hdmi3' => '', 'guest_menu_cast' => '1', 'guest_cast_text_en' => 'Join {ssid} / {password} and cast.', 'guest_cast_text_gu' => '',
            'guest_cast_text_hi' => '', 'wifi_ssid' => 'Hotel-Guest', 'wifi_password' => 'welcome123']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('Hotel-Guest', Settings::get('wifi_ssid'), 'shared wifi_ssid key');

        // Another module's item (QR) that runs first must be kept; ours are appended.
        $f = HC_ROOT . '/core/Extensions/AaaTestQrExtension.php';
        file_put_contents($f, "<?php\nfinal class AaaTestQrExtension implements ContentExtension {\n public function apply(array &\$content, array \$room): void { \$content['guest_menu'][] = ['id' => 'services', 'type' => 'qr', 'title' => 'Room Service', 'icon' => 'food', 'url' => 'https://x/g/T']; }\n}");
        self::$extraFiles[] = $f;
        ContentResolver::resetExtensions();
        $c = ContentResolver::build($room);
        $this->assertSame(['default' => 30, 'max' => 80, 'night_max' => 20, 'night_from' => '22:00', 'night_to' => '06:30'], $c['volume']);
        $this->assertSame([
            ['id' => 'services', 'type' => 'qr', 'title' => 'Room Service', 'icon' => 'food', 'url' => 'https://x/g/T'],
            ['id' => 'live_tv', 'type' => 'live_tv', 'title' => 'Live TV', 'icon' => 'tv'],
            ['id' => 'hdmi1', 'type' => 'input', 'title' => 'Set-top box', 'icon' => 'hdmi', 'input' => 'hdmi1'],
            ['id' => 'hdmi3', 'type' => 'input', 'title' => 'HDMI 3', 'icon' => 'hdmi', 'input' => 'hdmi3'],
            ['id' => 'cast', 'type' => 'cast', 'title' => 'Cast from phone', 'icon' => 'cast', 'text' => 'Join Hotel-Guest / welcome123 and cast.'],
        ], array_values(array_filter($c['guest_menu'], fn ($i) => in_array($i['id'], ['live_tv', 'hdmi1', 'hdmi3', 'cast'], true) || ($i['url'] ?? '') === 'https://x/g/T')));
        $this->assertSame($c['hash'], ContentResolver::build($room)['hash'], 'deterministic (no re-download on every poll)');

        // Guest-facing language: hotel default (or the checked-in guest's language).
        Settings::set('default_language', 'gu');
        $gu = ContentResolver::build($room);
        $live = current(array_filter($gu['guest_menu'], fn ($i) => $i['id'] === 'live_tv'));
        $this->assertSame(I18n::translate('Live TV', 'gu'), $live['title']);
        $this->assertNotSame('Live TV', $live['title']);
        $cast = current(array_filter($gu['guest_menu'], fn ($i) => $i['id'] === 'cast'));
        $this->assertStringContainsString('Hotel-Guest', $cast['text'], 'default text in Gujarati names the Wi-Fi');
        $this->assertStringContainsString('welcome123', $cast['text']);
        Settings::set('default_language', 'en');

        // Suspended hotels: no guest menu items, the volume policy still applies.
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget(1);
        $sus = ContentResolver::build($room);
        $this->assertSame('suspended', $sus['mode']);
        $this->assertSame([], array_values(array_filter((array) ($sus['guest_menu'] ?? []), fn ($i) => in_array($i['type'], ['live_tv', 'input', 'cast'], true))));
        $this->assertArrayHasKey('volume', $sus);
        DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
        Tenant::forget(1);

        // Over the device API (the TV parses these fields) and isolated per hotel.
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv1Uid . '?hash=x', null, self::devHeaders());
        $this->assertSame(80, $j['data']['content']['volume']['max']);
        $this->assertContains('hdmi1', array_column($j['data']['content']['guest_menu'], 'id'));
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv2Uid . '?hash=x', null, self::devHeaders(2));
        $this->assertArrayNotHasKey('volume', $j['data']['content'], 'hotel 2 has its own (empty) settings');
        $this->assertNotContains('hdmi1', array_column((array) ($j['data']['content']['guest_menu'] ?? []), 'id'));

        // Validation of the forms.
        [$s] = $m1->post('tv_controls.php', ['op' => 'save_volume', 'tv_volume_enabled' => '1', 'volume_max' => '180', 'volume_night_max' => '20', 'volume_night_from' => '22:00', 'volume_night_to' => '06:00']);
        Settings::flush();
        $this->assertSame('80', Settings::get('volume_max'), 'invalid value not saved');
        @unlink($f);
        ContentResolver::resetExtensions();
    }

    // ------------------------------------------------------------------ setup file

    /** Parse like tools/windows/HotelCast-Setup.ps1 does. */
    private static function parseLikeSetupTool(string $csv): array
    {
        $server = $key = '';
        $tvs = [];
        foreach (preg_split('/\r?\n/', $csv) as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if ($parts[0] === 'server') {
                $server = $parts[1];
                continue;
            }
            if ($parts[0] === 'key') {
                $key = $parts[1];
                continue;
            }
            if ($parts[0] === 'tv_address') {
                continue;
            }
            if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                $tvs[] = [$parts[0], $parts[1]];
            }
        }
        return [$server, $key, $tvs];
    }

    public function testSetupFileMatchesTheWindowsToolFormat(): void
    {
        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s, , $csv, $head] = $m1->get('setup_file.php?download=all');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Content-Type: text/csv', $head);
        $this->assertStringContainsString('filename="tvs.csv"', $head);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $csv, 'no BOM');
        $lines = array_values(array_filter(explode("\r\n", $csv), fn ($l) => $l !== '' && $l[0] !== '#'));
        $this->assertSame(['server,' . rtrim(self::$url, '/'), 'key,' . self::$key1, 'tv_address,room', '192.168.1.45,101', ',102', ',103'], $lines);
        [$server, $key, $tvs] = self::parseLikeSetupTool($csv);
        $this->assertSame(rtrim(self::$url, '/'), $server);
        $this->assertSame(self::$key1, $key);
        $this->assertSame([['192.168.1.45', '101']], $tvs, 'rows without an address are skipped by the tool until filled in');
        $this->assertStringNotContainsString(self::$key2, $csv);

        [$s, , $csv] = $m1->post('setup_file.php', ['room_ids' => [self::$room['102']]]);
        $this->assertSame(200, $s);
        $this->assertStringEndsWith("tv_address,room\r\n,102\r\n", $csv);
        [$s] = $m1->post('setup_file.php', ['room_ids' => [self::$room['201']]]);
        $this->assertSame(404, $s, 'room of another hotel');
        [$s, , $html] = $m1->get('setup_file.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s, , $html] = $m1->get('rooms.php');
        $this->assertStringContainsString('setup_file.php', $html, 'button on the Rooms page');
        $staff = new AdminSession(self::$url, 'pstaff1');
        [$s] = $staff->get('setup_file.php?download=all');
        $this->assertSame(403, $s);
        [, , $html] = $staff->get('rooms.php');
        $this->assertStringNotContainsString('setup_file.php', $html);
    }

    // ------------------------------------------------------------------ PWA

    public function testManifestServiceWorkerAndIcons(): void
    {
        [$s, $j, $raw, $head] = TestEnv::http('GET', self::$url . 'admin/manifest.php');
        $this->assertSame(200, $s);
        $this->assertMatchesRegularExpression('#Content-Type: application/manifest\+json#i', $head);
        $this->assertSame('Krishna Cloud LED TV', $j['name'], 'platform branding before login');
        foreach (['short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons', 'id'] as $k) {
            $this->assertArrayHasKey($k, $j, $k);
        }
        $this->assertSame('standalone', $j['display']);
        $this->assertSame('./', $j['scope']);
        $this->assertLessThanOrEqual(12, mb_strlen($j['short_name']));
        $purposes = array_column($j['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);
        foreach ($j['icons'] as $icon) {
            [$s, , $png, $h] = TestEnv::http('GET', self::$url . 'admin/' . $icon['src']);
            $this->assertSame(200, $s);
            $this->assertStringContainsString('Content-Type: image/png', $h);
            $size = getimagesizefromstring($png);
            $this->assertSame($icon['sizes'], $size[0] . 'x' . $size[1]);
        }
        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s, $j] = TestEnv::http('GET', self::$url . 'admin/manifest.php', null, [], $m1->jar);
        $this->assertSame('Krishna Cloud LED TV · Hotel One', $j['name'], 'hotel name with the session');

        [$s, , $js, $head] = TestEnv::http('GET', self::$url . 'admin/sw.js');
        $this->assertSame(200, $s);
        $this->assertMatchesRegularExpression('#Content-Type: (text|application)/javascript#i', $head);
        foreach (["addEventListener('push'", "addEventListener('notificationclick'", "addEventListener('fetch'", 'offline.html'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/offline.html');
        $this->assertSame(200, $s);

        [$s, , $html] = $m1->get('index.php');
        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('crossorigin="use-credentials"', $html);
        $this->assertStringContainsString('id="hcPwaConfig"', $html);
        $this->assertStringContainsString('js/pwa.js', $html);
        $this->assertStringContainsString('id="hcInstallBar"', $html);
        preg_match('#<script type="application/json" id="hcPwaConfig">(.*?)</script>#s', $html, $m);
        $cfg = json_decode($m[1], true);
        $this->assertSame(WebPush::publicKey(), $cfg['vapid']);
        $this->assertStringEndsWith('admin/sw.js', $cfg['sw']);
        // Login page: no app UI for anonymous users.
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringNotContainsString('hcPwaConfig', $html);
    }

    // ------------------------------------------------------------------ push subscriptions

    public function testPushSubscriptionCrud(): void
    {
        DB::query('DELETE FROM push_subscriptions');
        $m1 = new AdminSession(self::$url, 'pmgr1');
        [$s, $j] = $m1->ajax('push_status');
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['supported']);
        $this->assertSame(WebPush::publicKey(), $j['data']['public_key']);
        $this->assertFalse($j['data']['subscribed']);
        $avail = array_column($j['data']['available'], 'key');
        $this->assertContains('support', $avail, 'manager may get support alerts');
        $this->assertNotContains('platform', $avail);

        $keys = self::uaKeys();
        $ep = 'https://push.example.com/send/mgr1-device';
        $sub = ['endpoint' => $ep, 'keys' => ['p256dh' => WebPush::b64uEncode($keys['public']), 'auth' => WebPush::b64uEncode($keys['auth'])]];
        [$s, $j] = $m1->ajax('push_subscribe', $sub);
        $this->assertSame(200, $s, (string) json_encode($j));
        $id = $j['data']['id'];
        $row = DB::one('SELECT * FROM push_subscriptions WHERE id = :id', ['id' => $id]);
        $this->assertSame([1, self::$u['pmgr1'], $ep, null], [(int) $row['hotel_id'], (int) $row['user_id'], $row['endpoint'], $row['alert_types']]);
        [$s, $j] = $m1->ajax('push_subscribe', $sub + ['types' => ['tv_offline']]);
        $this->assertSame($id, $j['data']['id'], 'same endpoint → same row');
        $this->assertSame('tv_offline', DB::value('SELECT alert_types FROM push_subscriptions WHERE id = :id', ['id' => $id]));
        [$s, $j] = $m1->ajax('push_prefs', ['endpoint' => $ep, 'types' => ['support', 'orders', 'platform']]);
        $this->assertSame(200, $s);
        $this->assertSame('orders,support', DB::value('SELECT alert_types FROM push_subscriptions WHERE id = :id', ['id' => $id]), 'unknown / not allowed types dropped');
        [$s, $j] = $m1->ajax('push_prefs', ['endpoint' => $ep, 'types' => []]);
        $this->assertSame('none', DB::value('SELECT alert_types FROM push_subscriptions WHERE id = :id', ['id' => $id]));
        [$s, $j] = $m1->ajax('push_prefs', ['endpoint' => $ep, 'types' => $avail]);
        $this->assertNull(DB::value('SELECT alert_types FROM push_subscriptions WHERE id = :id', ['id' => $id]), 'all = NULL (future types included)');
        [$s, $j] = TestEnv::http('GET', self::$url . 'admin/ajax.php?action=push_status&endpoint=' . rawurlencode($ep), null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $m1->jar);
        $this->assertTrue($j['data']['subscribed']);

        // Validation
        foreach ([
            ['endpoint' => 'http://push.example.com/x'] + $sub,
            ['endpoint' => 'https://10.0.0.1/x'] + $sub,
            ['endpoint' => $ep . '2', 'keys' => ['p256dh' => 'abc', 'auth' => WebPush::b64uEncode($keys['auth'])]],
            ['endpoint' => $ep . '3', 'keys' => ['p256dh' => WebPush::b64uEncode($keys['public']), 'auth' => 'AAAA']],
            ['endpoint' => $ep . '4', 'keys' => 'x'],
        ] as $bad) {
            [$s] = $m1->ajax('push_subscribe', $bad);
            $this->assertSame(422, $s, json_encode($bad));
        }
        // CSRF is required.
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=push_subscribe', $sub, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $m1->jar);
        $this->assertSame(419, $s);

        // Other users cannot remove or change it; a user's own row only.
        $staff = new AdminSession(self::$url, 'pstaff1');
        [$s, $j] = $staff->ajax('push_unsubscribe', ['endpoint' => $ep]);
        $this->assertSame(0, $j['data']['removed']);
        [$s] = $staff->ajax('push_prefs', ['endpoint' => $ep, 'types' => []]);
        $this->assertSame(404, $s);
        [$s] = $staff->post('push.php', ['op' => 'remove', 'id' => $id]);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM push_subscriptions WHERE id = :id', ['id' => $id]));
        $m2 = new AdminSession(self::$url, 'pmgr2');
        [$s, $j] = $m2->ajax('push_unsubscribe', ['endpoint' => $ep]);
        $this->assertSame(0, $j['data']['removed'], 'other hotel');

        [$s, , $html] = $m1->get('push.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s, $j] = $m1->ajax('push_unsubscribe', ['endpoint' => $ep]);
        $this->assertSame(1, $j['data']['removed']);
        [$s] = $m1->ajax('push_test', []);
        $this->assertSame(404, $s, 'no device subscribed');

        // Every role (also reception and platform admins without a hotel) manages its own devices.
        foreach (['precep1', 'pplat'] as $name) {
            $s1 = new AdminSession(self::$url, $name);
            [$s, , $html] = $s1->get('push.php');
            $this->assertSame(200, $s, $name);
            $this->assertStringContainsString('push.php', $html, 'menu entry');
            $k = self::uaKeys();
            [$s] = $s1->ajax('push_subscribe', ['endpoint' => 'https://push.example.com/send/' . $name, 'keys' => ['p256dh' => WebPush::b64uEncode($k['public']), 'auth' => WebPush::b64uEncode($k['auth'])]]);
            $this->assertSame(200, $s, $name);
        }
        $this->assertNull(DB::value("SELECT hotel_id FROM push_subscriptions WHERE endpoint = 'https://push.example.com/send/pplat'"));
        // Super admin: email / WhatsApp per alert type for the hotel.
        $boss = new AdminSession(self::$url, 'pboss1');
        [$s] = $boss->post('push.php', ['op' => 'channels', 'email' => ['orders', 'tv_offline', 'bogus'], 'whatsapp' => ['emergency']]);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame(['orders', 'tv_offline'], StaffAlerts::channelTypes('email'));
        $this->assertSame(['emergency'], StaffAlerts::channelTypes('whatsapp'));
        $m1->post('push.php', ['op' => 'channels', 'email' => ['support']]);
        Settings::flush();
        $this->assertSame(['orders', 'tv_offline'], StaffAlerts::channelTypes('email'), 'managers cannot change hotel channels');
        DB::query('DELETE FROM push_subscriptions');
        Settings::set('alert_email_types', '');
        Settings::set('alert_whatsapp_types', '');
    }

    // ------------------------------------------------------------------ staff alerts

    public function testStaffAlertsGoOnlyToPermittedUsersOfTheCurrentHotel(): void
    {
        DB::query('DELETE FROM push_subscriptions');
        $base = 'https://push.example.com/send/';
        $keys = [];
        foreach (['precep1', 'pstaff1', 'pmgr1', 'pboss1'] as $n) {
            $keys[$n] = self::addSub(self::$u[$n], 1, $base . $n);
        }
        self::addSub(self::$u['pmgr2'], 2, $base . 'pmgr2');
        self::addSub(self::$u['pplat'], null, $base . 'pplat');

        $this->assertSame(2, StaffAlerts::send('support.view', 'TV crashed', 'Room 101', 'support.php', 'support'));
        $this->assertSame([$base . 'pboss1', $base . 'pmgr1'], self::pushedTo());
        // Payload decrypts on the device and links to the admin page.
        $call = current(array_filter(self::$pushCalls, fn ($c) => $c[0] === $base . 'pmgr1'));
        $payload = json_decode(WebPush::decrypt($call[2], $keys['pmgr1']['private_pem'], $keys['pmgr1']['auth']), true);
        $this->assertSame('TV crashed', $payload['title']);
        $this->assertSame('Room 101', $payload['body']);
        $this->assertStringEndsWith('admin/support.php', $payload['url']);
        $this->assertSame(1, $payload['hotel']);

        self::$pushCalls = [];
        $this->assertSame(4, StaffAlerts::send('rooms.view', 'TV offline', 'Room 102', '', 'tv_offline'));
        $this->assertSame([$base . 'pboss1', $base . 'pmgr1', $base . 'precep1', $base . 'pstaff1'], self::pushedTo());
        self::$pushCalls = [];
        $this->assertSame(1, StaffAlerts::send('settings.manage', 'x', 'y'));
        $this->assertSame([$base . 'pboss1'], self::pushedTo());

        // Hotel 2 alerts reach hotel 2 only.
        self::$pushCalls = [];
        $this->assertSame(1, Tenant::run(2, fn () => StaffAlerts::send('rooms.view', 'H2', 'x', '', 'tv_offline')));
        $this->assertSame([$base . 'pmgr2'], self::pushedTo());

        // Alert type preferences per device: pmgr1 only wants orders.
        DB::query("UPDATE push_subscriptions SET alert_types = 'orders' WHERE endpoint = :e", ['e' => $base . 'pmgr1']);
        self::$pushCalls = [];
        StaffAlerts::send('services.manage', 'Request', 'Towels', '', 'requests');
        $this->assertNotContains($base . 'pmgr1', self::pushedTo());
        self::$pushCalls = [];
        StaffAlerts::send('services.manage', 'Order', '2 × tea', '', 'orders');
        $this->assertContains($base . 'pmgr1', self::pushedTo());
        self::$pushCalls = [];
        StaffAlerts::send('services.manage', 'Custom', 'module type', '', 'some_module_type');
        $this->assertContains($base . 'pmgr1', self::pushedTo(), 'unregistered types cannot be switched off');

        // 404/410 → subscription removed; other errors count failures.
        self::addSub(self::$u['pmgr1'], 1, $base . 'gone/1');
        self::addSub(self::$u['pmgr1'], 1, $base . 'fail/1');
        StaffAlerts::send('support.view', 'x', 'y', '', 'support');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = :e', ['e' => $base . 'gone/1']));
        $this->assertSame(1, (int) DB::value('SELECT failures FROM push_subscriptions WHERE endpoint = :e', ['e' => $base . 'fail/1']));
        $this->assertNotNull(DB::value('SELECT last_used FROM push_subscriptions WHERE endpoint = :e', ['e' => $base . 'pboss1']));

        // Email channel per alert type.
        $mails = [];
        Notifier::$mailer = function (string $to, string $subject, string $msg) use (&$mails) {
            $mails[] = [$to, $subject];
            return true;
        };
        Settings::set('notify_email', 'desk@h1.test');
        Settings::set('alert_email_types', 'orders');
        StaffAlerts::send('services.manage', 'New order', 'Room 101', '', 'orders');
        StaffAlerts::send('services.manage', 'New request', 'Room 101', '', 'requests');
        $this->assertSame([['desk@h1.test', 'New order']], $mails);
        Settings::set('alert_email_types', '');

        // Platform alerts: platform admins only.
        self::$pushCalls = [];
        Settings::setPlatform('platform_notify_email', 'ops@platform.test');
        $mails = [];
        $this->assertSame(1, StaffAlerts::sendPlatform('Crash spike', 'Hotel One: 9 crashes'));
        $this->assertSame([$base . 'pplat'], self::pushedTo());
        $this->assertSame('ops@platform.test', $mails[0][0] ?? null);

        // Test notification to all of one user's devices.
        self::$pushCalls = [];
        [$sent, $failed] = StaffAlerts::test(self::$u['pmgr1']);
        $this->assertSame([1, 1], [$sent, $failed], 'ok device + failing device');
        Notifier::$mailer = null;
        DB::query('DELETE FROM push_subscriptions');
    }

    public function testStaffAlertsTaskPushesOfflineTvsAndEmergenciesOnce(): void
    {
        DB::query('DELETE FROM push_subscriptions');
        self::addSub(self::$u['precep1'], 1, 'https://push.example.com/send/r1');
        $now = time();
        DB::query("UPDATE devices SET status = 'offline', last_ping = :p WHERE id = :id", ['p' => date('Y-m-d H:i:s', $now - 330), 'id' => self::$dev1]);
        $r = StaffAlertsTask::forHotel(1, $now - 60, $now);
        $this->assertSame(1, $r['offline']);
        $this->assertSame(1, $r['sent']);
        $payload = self::$pushCalls[0][2];
        $this->assertNotSame('', $payload);
        $this->assertSame(0, StaffAlertsTask::forHotel(1, $now, $now + 60)['offline'], 'reported once');

        $bid = Broadcaster::emergencyStart('Fire drill', 'Leave by the stairs', 'all', []);
        self::$pushCalls = [];
        $r = StaffAlertsTask::forHotel(1, $now - 5, time());
        $this->assertSame(1, $r['emergency']);
        $this->assertCount(1, self::$pushCalls, 'reception gets emergency alerts (dashboard.view)');
        Broadcaster::emergencyStop($bid);
        DB::query("UPDATE devices SET status = 'online', last_ping = :p WHERE id = :id", ['p' => now(), 'id' => self::$dev1]);
        // run() itself: only hotels with subscribers, window bookkeeping.
        Settings::setPlatform('platform_staff_alerts_at', (string) time());
        $out = (new StaffAlertsTask())->run();
        $this->assertSame(['offline' => 0, 'emergency' => 0, 'sent' => 0], $out);
        DB::query('DELETE FROM push_subscriptions');
    }

    // ------------------------------------------------------------------ platform support

    public function testPlatformSupportDashboardNumbersAndCrashSpikeAlert(): void
    {
        $h3 = Hotels::create(['name' => 'Hotel Three']);
        $now = time();
        $devs = Tenant::run($h3, function () use ($now) {
            $r = DB::insert('rooms', ['room_number' => '301']);
            $mk = fn (string $uid, string $status, int $code) => DB::insert('devices', ['device_uid' => $uid, 'room_id' => $r, 'token_hash' => hash('sha256', $uid),
                'status' => $status, 'app_version' => '9.' . $code, 'app_version_code' => $code, 'last_ping' => date('Y-m-d H:i:s', $now - 30)]);
            $a = $mk('h3-tv-0001', 'online', 9000);
            $b = $mk('h3-tv-0002', 'offline', 8000);
            DB::insert('apk_releases', ['version_name' => '9.9000', 'version_code' => 9000, 'file_path' => 'apk/h3/x.apk', 'file_size' => 1, 'sha256' => str_repeat('b', 64)]);
            foreach ([600, 1200, 3 * 86400, 10 * 86400] as $i => $ago) {
                DB::insert('device_support_files', ['device_id' => $i % 2 ? $a : $b, 'room_id' => $r, 'kind' => 'crash', 'file_path' => 'h3/d1/crash-' . $i . '.txt',
                    'app_version' => '9.8000', 'meta' => json_out(['summary' => 'java.lang.RuntimeException: test ' . $i]), 'created_at' => date('Y-m-d H:i:s', $now - $ago)]);
            }
            DB::insert('device_commands', ['device_id' => $a, 'command' => 'REBOOT', 'status' => 'failed', 'created_at' => date('Y-m-d H:i:s', $now - 100)]);
            return [$a, $b];
        });
        $st = DeviceSupport::platformStats($now);
        $this->assertSame(['code' => 9000, 'name' => '9.9000'], $st['newest']);
        $row = current(array_filter($st['hotels'], fn ($h) => $h['id'] === $h3));
        $this->assertSame(['id' => $h3, 'name' => 'Hotel Three', 'status' => 'active', 'tvs' => 2, 'offline' => 1, 'outdated' => 1,
            'crashes_24h' => 2, 'crashes_7d' => 3, 'failed_24h' => 1], $row);
        $this->assertContains($h3, array_column($st['hotels_with_errors'], 'id'));
        $v = current(array_filter($st['versions'], fn ($x) => $x['code'] === 8000));
        $this->assertSame(['version' => '9.8000', 'code' => 8000, 'tvs' => 1, 'outdated' => true], $v);
        $this->assertSame('java.lang.RuntimeException: test 0', current(array_filter($st['recent_crashes'], fn ($c) => (int) $c['hotel_id'] === $h3))['summary'] ?? null);
        $this->assertSame(array_sum(array_column($st['hotels'], 'crashes_24h')), $st['totals']['crashes_24h']);
        $this->assertGreaterThanOrEqual(1, $st['totals']['outdated']);

        $plat = new AdminSession(self::$url, 'pplat');
        [$s, , $html] = $plat->get('platform_support.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Hotel Three', $html);
        $this->assertStringContainsString('9.8000', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s] = $plat->post('platform_support.php', ['op' => 'settings', 'threshold' => '2']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame(2, SupportAlertTask::threshold());
        foreach (['pmgr1', 'pboss1'] as $n) {
            [$s] = (new AdminSession(self::$url, $n))->get('platform_support.php');
            $this->assertSame(403, $s, $n);
        }

        // Crash spike (≥ 2 crashes in the last hour in Hotel Three) → platform admins + hotel managers, once.
        DB::query('DELETE FROM push_subscriptions');
        self::addSub(self::$u['pplat'], null, 'https://push.example.com/send/plat');
        Settings::setPlatform('platform_crash_alert_state', '');
        $mails = [];
        Notifier::$mailer = function (string $to, string $subject, string $msg) use (&$mails) {
            $mails[] = [$to, $subject, $msg];
            return true;
        };
        Settings::setPlatform('platform_notify_email', 'ops@platform.test');
        $r = (new SupportAlertTask())->run();
        $this->assertGreaterThanOrEqual(1, $r['alerted']);
        $this->assertSame(['https://push.example.com/send/plat'], self::pushedTo());
        $this->assertStringContainsString('Hotel Three: 2 crashes', $mails[0][2] ?? '');
        self::$pushCalls = [];
        $r = (new SupportAlertTask())->run();
        $this->assertSame(0, $r['alerted'], 'not repeated within 6 hours');
        $this->assertSame([], self::$pushCalls);
        Notifier::$mailer = null;
        DB::query('DELETE FROM push_subscriptions');
    }

    public function testCleanupTaskAndNoPhpErrors(): void
    {
        Tenant::run(1, function () {
            DB::insert('device_events', ['device_id' => self::$dev1, 'type' => 'old_event', 'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
        });
        $out = (new SupportCleanupTask())->run();
        $this->assertGreaterThanOrEqual(1, $out['events']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_events WHERE type = 'old_event'"));
        $this->assertSame(1, Tenant::current(), 'context restored');
        $m1 = new AdminSession(self::$url, 'pmgr1');
        foreach (['tv_controls.php', 'support.php', 'push.php', 'setup_file.php'] as $p) {
            [$s, , $html] = $m1->get($p);
            $this->assertSame(200, $s, $p);
            $this->assertFalse(TestEnv::hasPhpError($html), $p);
        }
        $m1->ajax('set_language', ['lang' => 'gu']);
        [, , $html] = $m1->get('tv_controls.php');
        $this->assertStringContainsString(I18n::translate('TV controls', 'gu'), $html);
        $this->assertNotSame('TV controls', I18n::translate('TV controls', 'gu'));
        $this->assertSame('', TestEnv::phpErrors());
    }
}
