<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.10 CCTV cameras (core/Cctv.php, admin/cctv.php): RTSP addresses per brand (Dahua / CP Plus / Hikvision /
 * Prama / Uniview / custom, login encoded, port), channel lists, validation, the page creates one stream item per
 * camera + "All cameras" grid layouts (sub stream, decoder hint) that reach the TV poll JSON, lists recorders
 * without passwords, deletes a recorder's items, permissions / CSRF / tenant isolation, translations.
 */
final class CctvTest extends TestCase
{
    private static string $url;
    private static int $other = 0;
    private static array $tv = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => 1, 'username' => 'camboss', 'email' => 'camboss@hotel.test', 'full_name' => 'Boss', 'password_hash' => $pw, 'role' => 'super_admin']);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'camdesk', 'email' => 'camdesk@hotel.test', 'full_name' => 'Desk', 'password_hash' => $pw, 'role' => 'reception']);
        self::$other = Hotels::create(['name' => 'Other Shop']);
        $room = DB::insert('rooms', ['room_number' => 'S1', 'name' => 'Security desk', 'floor' => '0']);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::flush();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-cctv-s1-0001', 'room_number' => 'S1', 'registration_key' => 'TESTKEY123456789', 'app_version' => '2.6.3', 'app_version_code' => 17]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tv = ['uid' => 'tv-cctv-s1-0001', 'token' => $j['data']['token'], 'room' => $room];
    }

    public function testRtspAddressesPerBrand(): void
    {
        $this->assertSame('rtsp://admin:pa%40ss%3Aw0rd@192.168.1.108/cam/realmonitor?channel=3&subtype=1', Cctv::url('dahua', '192.168.1.108', 554, 'admin', 'pa@ss:w0rd', 3, true));
        $this->assertSame('rtsp://admin:x@shop.dahuaddns.com:8554/cam/realmonitor?channel=1&subtype=0', Cctv::url('cpplus', 'shop.dahuaddns.com', 8554, 'admin', 'x', 1, false));
        $this->assertSame('rtsp://admin:x@10.0.0.5/Streaming/Channels/101', Cctv::url('hikvision', '10.0.0.5', 554, 'admin', 'x', 1, false));
        $this->assertSame('rtsp://admin:x@10.0.0.5/Streaming/Channels/1202', Cctv::url('prama', '10.0.0.5', 554, 'admin', 'x', 12, true));
        $this->assertSame('rtsp://admin:x@10.0.0.6/unicast/c2/s1/live', Cctv::url('uniview', '10.0.0.6', 554, 'admin', 'x', 2, true));
        $this->assertSame('rtsp://10.0.0.7/live/ch4', Cctv::url('custom', '10.0.0.7', 554, '', '', 4, false, 'live/ch{ch}'));
        $this->assertSame([1, 2, 3, 4, 7], Cctv::channels('4,1-3 7,,x'));
        $this->assertSame('rtsp://admin:•••@1.2.3.4/x', Cctv::masked('rtsp://admin:secret@1.2.3.4/x'));
    }

    public function testValidation(): void
    {
        [, $e] = Cctv::validate(['name' => '', 'host' => 'bad host!', 'port' => 70000, 'channels' => 'none']);
        $this->assertCount(4, $e);
        [$v, $e] = Cctv::validate(['name' => 'NVR', 'brand' => 'dahua', 'host' => 'rtsp://192.168.1.108/', 'channels' => '1-4']);
        $this->assertSame([], $e);
        $this->assertSame('192.168.1.108', $v['host']);
        $this->assertSame(554, $v['port']);
        [, $e] = Cctv::validate(['name' => 'X', 'brand' => 'custom', 'host' => '1.2.3.4', 'channels' => '1,2', 'custom_path' => '/live']);
        $this->assertNotEmpty($e, 'custom path needs {ch} for several cameras');
    }

    public function testPageCreatesCamerasAndGridThatReachTheTv(): void
    {
        $boss = new AdminSession(self::$url, 'camboss');
        [$s, , $html] = $boss->get('cctv.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringContainsString('id="cctvForm"', $html);
        $this->assertStringContainsString('serial number', $html);

        [$s] = $boss->post('cctv.php', ['op' => 'add', 'name' => 'Shop NVR', 'brand' => 'cpplus', 'host' => '192.168.1.108', 'port' => 554,
            'username' => 'admin', 'password' => 'S3cret@1', 'channels' => '1-4', 'quality' => 'auto', 'grid' => 1]);
        $this->assertSame(302, $s);
        $rows = DB::all("SELECT * FROM content_items WHERE hotel_id = 1 AND JSON_EXTRACT(settings, '$.cctv.group') IS NOT NULL ORDER BY id");
        $this->assertSame(['stream', 'stream', 'stream', 'stream', 'layout'], array_column($rows, 'type'));
        $this->assertSame('rtsp://admin:S3cret%401@192.168.1.108/cam/realmonitor?channel=1&subtype=1', $rows[0]['url'], 'grid → sub stream');
        $grid = $rows[4];
        $gs = json_decode((string) $grid['settings'], true);
        $this->assertCount(4, $gs['zones']);
        $this->assertSame(4, $gs['max_decoders']);

        // Recorder list: no password in the page.
        [, , $html] = $boss->get('cctv.php');
        $this->assertStringContainsString('Shop NVR', $html);
        $this->assertStringContainsString('192.168.1.108', $html);
        $this->assertStringNotContainsString('S3cret', $html);

        // Put the grid on the screen → the TV gets a layout with 4 RTSP zones and the decoder hint.
        DB::query('UPDATE rooms SET content_id = :c WHERE id = :r', ['c' => (int) $grid['id'], 'r' => self::$tv['room']]);
        Settings::bumpContentVersion();
        Cache::clear();
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['uid'] . '?hash=x', null, ['Authorization: Bearer ' . self::$tv['token'], 'X-Device-Id: ' . self::$tv['uid']]);
        $this->assertSame(200, $s);
        $layout = $j['data']['content']['items'][0]['layout'];
        $this->assertSame(4, $layout['max_decoders']);
        $this->assertCount(4, $layout['zones']);
        $this->assertSame('stream', $layout['zones'][0]['items'][0]['type']);
        $this->assertStringStartsWith('rtsp://admin:', $layout['zones'][3]['items'][0]['url']);

        // Single camera without grid: main stream.
        $boss->post('cctv.php', ['op' => 'add', 'name' => 'Gate', 'brand' => 'hikvision', 'host' => 'gate.example.com', 'port' => 554,
            'username' => 'admin', 'password' => 'p', 'channels' => '2', 'quality' => 'auto']);
        $this->assertSame('rtsp://admin:p@gate.example.com/Streaming/Channels/201', DB::value("SELECT url FROM content_items WHERE hotel_id = 1 AND title LIKE 'Gate%'"));

        // Delete a recorder: its items go, others stay.
        $group = $gs['cctv']['group'];
        $before = (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = 1');
        [$s] = $boss->post('cctv.php', ['op' => 'delete', 'group' => $group]);
        $this->assertSame(302, $s);
        $this->assertSame($before - 5, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = 1'));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = 1 AND title LIKE 'Gate%'"));
    }

    public function testPermissionsCsrfAndTenants(): void
    {
        [$s] = (new AdminSession(self::$url, 'camdesk'))->get('cctv.php');
        $this->assertSame(403, $s);
        $boss = new AdminSession(self::$url, 'camboss');
        [$s] = TestEnv::http('POST', self::$url . 'admin/cctv.php', null, [], $boss->jar, ['op' => 'add', 'name' => 'X', 'host' => '1.2.3.4', 'channels' => '1']);
        $this->assertNotSame(302, $s);
        // Another customer's recorder cannot be deleted from here.
        $g = Tenant::run(self::$other, static fn () => Cctv::create(['name' => 'Theirs', 'brand' => 'dahua', 'host' => '1.2.3.4', 'port' => 554, 'username' => 'a', 'password' => 'b',
            'channels' => [1], 'quality' => 'auto', 'custom_path' => '', 'grid' => false], null));
        $theirGroup = json_decode((string) DB::value('SELECT settings FROM content_items WHERE id = :id', ['id' => $g['cameras'][0]]), true)['cctv']['group'];
        $boss->post('cctv.php', ['op' => 'delete', 'group' => $theirGroup]);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE id = :id', ['id' => $g['cameras'][0]]));
        foreach (['lang/gu_cctv.php', 'lang/hi_cctv.php'] as $f) {
            $t = require HC_ROOT . '/' . $f;
            $this->assertArrayHasKey('CCTV cameras', $t);
        }
    }
}
