<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end TV API test over real HTTP (PHP built-in server running the sandbox app).
 * Covers: register → poll → admin push → TV receives within 10 s → ack → heartbeat → played
 * → per-device access control → revoke → APK download → rate limiting.
 */
final class DeviceApiTest extends TestCase
{
    private static string $url;
    private static string $token = '';
    private static string $uid = 'tv-0f1e2d3c-4b5a-6978';
    private static int $roomId = 0;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        $port = TestEnv::freePort();
        self::$url = 'http://127.0.0.1:' . $port . '/';
        TestEnv::writeConfig(HC_ROOT, self::$url);
        // Start on the chosen port.
        $cmd = sprintf('exec %s -S 127.0.0.1:%d -t %s %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg(HC_ROOT), escapeshellarg(__DIR__ . '/../router.php'));
        $GLOBALS['__dev_srv'] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, null, array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']));
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset($GLOBALS['__dev_srv']) && is_resource($GLOBALS['__dev_srv'])) {
            proc_terminate($GLOBALS['__dev_srv']);
            proc_close($GLOBALS['__dev_srv']);
        }
    }

    private function auth(): array
    {
        return ['Authorization: Bearer ' . self::$token, 'X-Device-Id: ' . self::$uid];
    }

    public function testHealth(): void
    {
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/health');
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
        $this->assertTrue($j['data']['db']);
    }

    public function testRegisterRejectsWrongKey(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$uid, 'room_number' => '305', 'registration_key' => 'WRONG']);
        $this->assertSame(401, $s);
        $this->assertSame('INVALID_REGISTRATION_KEY', $j['error']['code']);
    }

    public function testRegisterValidation(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'x', 'room_number' => '', 'registration_key' => 'TESTKEY123456789']);
        $this->assertSame(400, $s);
        $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
    }

    public function testRegister(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', [
            'device_id' => self::$uid, 'room_number' => '305', 'registration_key' => 'TESTKEY123456789',
            'app_version' => '1.0.0', 'app_version_code' => 1, 'android_version' => '11', 'model' => 'TestTV', 'ip_address' => '192.168.1.50',
        ]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $j['data']['token']);
        $this->assertSame('305', $j['data']['room']['number']);
        $this->assertSame('3', $j['data']['room']['floor'], 'Floor derived from room number');
        $this->assertSame(hash('sha256', '1234'), $j['data']['settings_pin_hash']);
        self::$token = $j['data']['token'];
        self::$roomId = $j['data']['room']['id'];
        $this->assertSame(hash('sha256', self::$token), DB::value('SELECT token_hash FROM devices WHERE device_uid = :u', ['u' => self::$uid]), 'Only token hash stored');
    }

    #[Depends('testRegister')]
    public function testPollReturnsContentOnlyWhenHashDiffers(): void
    {
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid, null, $this->auth());
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertTrue($j['data']['content_changed']);
        $hash = $j['data']['content_hash'];
        $this->assertSame($hash, $j['data']['content']['hash']);
        $this->assertSame('305', $j['data']['content']['room']['number']);

        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid . '?hash=' . $hash, null, $this->auth());
        $this->assertFalse($j['data']['content_changed']);
        $this->assertArrayNotHasKey('content', $j['data']);
        $this->assertSame('online', DB::value('SELECT status FROM devices WHERE device_uid = :u', ['u' => self::$uid]));
    }

    #[Depends('testRegister')]
    public function testAdminPushReachesTvWithin10Seconds(): void
    {
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid, null, $this->auth());
        $hash = $j['data']['content_hash'];
        $cid = DB::insert('content_items', ['title' => 'Breaking: Aarti now', 'type' => 'announcement', 'body' => 'સંધ્યા આરતી', 'duration' => 10]);

        $t0 = microtime(true);
        Broadcaster::pushNow('rooms', [self::$roomId], $cid, null, 'Integration push');
        $received = null;
        $cmds = [];
        while (microtime(true) - $t0 < 10) {
            [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid . '?hash=' . $hash, null, $this->auth());
            if ($j['data']['content_changed']) {
                $received = $j['data']['content'];
                $cmds = $j['data']['commands'];
                break;
            }
            usleep(500000);
        }
        $elapsed = microtime(true) - $t0;
        $this->assertNotNull($received, 'TV did not receive pushed content within 10 s');
        $this->assertLessThan(10, $elapsed);
        fwrite(STDERR, sprintf("\n  [push→TV latency %.2fs]\n", $elapsed));
        $this->assertSame('assigned', $received['mode']);
        $this->assertSame('સંધ્યા આરતી', $received['items'][0]['text']);
        $this->assertSame('SHOW_CONTENT', $cmds[0]['command']);

        // Ack the command
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/ack', ['command_id' => $cmds[0]['id'], 'status' => 'acked', 'message' => 'shown'], $this->auth());
        $this->assertSame(200, $s);
        $this->assertSame('acked', DB::value('SELECT status FROM device_commands WHERE id = :id', ['id' => $cmds[0]['id']]));
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid . '?hash=' . $received['hash'], null, $this->auth());
        $this->assertSame([], $j['data']['commands'], 'Acked commands are not re-sent');
    }

    #[Depends('testRegister')]
    public function testHeartbeatAndPlayed(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', [
            'app_version' => '1.0.1', 'app_version_code' => 2, 'android_version' => '12', 'ip_address' => '192.168.1.77',
            'battery' => -1, 'network_type' => 'ethernet', 'free_storage_mb' => 1234, 'screen_on' => true, 'uptime_sec' => 3600,
        ], $this->auth());
        $this->assertSame(200, $s);
        $this->assertArrayHasKey('poll_interval', $j['data']);
        $d = DB::one('SELECT * FROM devices WHERE device_uid = :u', ['u' => self::$uid]);
        $this->assertSame('1.0.1', $d['app_version']);
        $this->assertSame('192.168.1.77', $d['ip_address']);
        $this->assertSame('ethernet', $d['network_type']);

        $cid = (int) DB::value('SELECT id FROM content_items LIMIT 1');
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/played', ['items' => [['content_id' => $cid, 'started_at' => date('c'), 'duration_sec' => 10]]], $this->auth());
        $this->assertSame(1, $j['data']['saved']);
    }

    #[Depends('testRegister')]
    public function testAccessControl(): void
    {
        [$s] = TestEnv::http('GET', self::$url . 'api/content/' . self::$roomId, null, $this->auth());
        $this->assertSame(200, $s);
        $other = DB::insert('rooms', ['room_number' => '999']);
        [$s] = TestEnv::http('GET', self::$url . 'api/content/' . $other, null, $this->auth());
        $this->assertSame(403, $s, 'Device cannot read another room');
        [$s] = TestEnv::http('GET', self::$url . 'api/device/command/someone-else-uid', null, $this->auth());
        $this->assertSame(403, $s);
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid);
        $this->assertSame(401, $s);
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid, null, ['Authorization: Bearer ' . str_repeat('0', 64)]);
        $this->assertSame('INVALID_TOKEN', $j['error']['code']);
        [$s] = TestEnv::http('POST', self::$url . 'api/device/register', null);
        $this->assertSame(400, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/register');
        $this->assertSame(405, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/nope');
        $this->assertSame(404, $s);
    }

    #[Depends('testRegister')]
    public function testDeviceCommandsAndApkDownload(): void
    {
        @mkdir(HC_ROOT . '/storage/apk', 0755, true);
        $bytes = 'PK' . random_bytes(2000);
        file_put_contents(HC_ROOT . '/storage/apk/test.apk', $bytes);
        $apk = DB::insert('apk_releases', ['version_name' => '1.1.0', 'version_code' => 2, 'file_path' => 'apk/test.apk', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        Broadcaster::pushApk($apk, 'all', []);
        Broadcaster::sendCommand('REBOOT', 'floors', ['3']);
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid . '?hash=x', null, $this->auth());
        $names = array_column($j['data']['commands'], 'command');
        $this->assertContains('UPDATE_APP', $names);
        $this->assertContains('REBOOT', $names);
        $upd = $j['data']['commands'][array_search('UPDATE_APP', $names, true)];
        $this->assertSame(2, $upd['payload']['version_code']);
        $this->assertSame(hash('sha256', $bytes), $upd['payload']['sha256']);

        [$s, , $body] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $apk, null, $this->auth());
        $this->assertSame(200, $s);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $body));
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $apk);
        $this->assertSame(401, $s, 'APK download requires device auth');
    }

    #[Depends('testRegister')]
    public function testRevokedDeviceIsRejected(): void
    {
        DB::update('devices', ['is_revoked' => 1], 'device_uid = :u', ['u' => self::$uid]);
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid, null, $this->auth());
        $this->assertSame(401, $s);
        $this->assertSame('INVALID_TOKEN', $j['error']['code']);
        DB::update('devices', ['is_revoked' => 0], 'device_uid = :u', ['u' => self::$uid]);
    }

    #[Depends('testRegister')]
    public function testRateLimitPerDevice(): void
    {
        $limited = false;
        for ($i = 0; $i < 260; $i++) {
            [$s, , , $head] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$uid . '?hash=x', null, $this->auth());
            if ($s === 429) {
                $limited = true;
                $this->assertStringContainsStringIgnoringCase('Retry-After', $head);
                break;
            }
        }
        $this->assertTrue($limited, 'Device rate limit (120/min) enforced');
        DB::query('DELETE FROM rate_limits');
    }
}
