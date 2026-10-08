<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.4 device features (docs/modules/device_features.md): SPEAK / PLAY_SOUND accepted and queued with
 * the payloads the Android app parses, the live view session lifecycle (start, keepalive, frame
 * upload limits, keep-last-only, stop, expiry, tenancy, Access, roles), TV health from heartbeats
 * (sanitising, 10-minute history, 7-day pruning, warnings, debounced alerts), the TV health page
 * for every hotel role without PHP warnings, the usb_mode / cec_mode content flags and the
 * broadcast page's "Announce" form (CSRF, validation, targets).
 */
final class DeviceFeaturesTest extends TestCase
{
    private static string $url;
    private static array $u = [];
    private static array $room = [];
    /** @var array<string, array{uid:string, token:string, id:int, hotel:int}> */
    private static array $tv = [];
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['fboss' => 'super_admin', 'fmgr' => 'manager', 'fstaff' => 'staff', 'frecep' => 'reception', 'flim' => 'manager'] as $name => $role) {
            self::$u[$name] = DB::insert('users', ['hotel_id' => 1, 'username' => $name, 'email' => $name . '@h1.test', 'full_name' => $name, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101', '102'] as $n) {
            self::$room[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        Settings::set('hotel_name', 'Hotel One');
        // flim may only use room 102.
        Access::setForUser(self::$u['flim'], [['room', self::$room['102']]]);
        Hotels::create(['name' => 'Hotel Two']);
        Tenant::run(2, static function () use ($pw): void {
            self::$room['201'] = DB::insert('rooms', ['room_number' => '201', 'name' => 'Room 201', 'floor' => '2']);
            self::$u['fmgr2'] = DB::insert('users', ['hotel_id' => 2, 'username' => 'fmgr2', 'email' => 'fmgr2@h2.test', 'full_name' => 'M2', 'password_hash' => $pw, 'role' => 'manager']);
        });
        // The test server must not run the health task by itself (the tests call it directly).
        Settings::setPlatform('task_last_DeviceHealthTask', (string) (time() + 86400));
        ContentResolver::resetExtensions();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $keys = [1 => (string) Settings::get('registration_key'), 2 => (string) Settings::getFor(2, 'registration_key')];
        foreach (['tv101' => ['101', 1], 'tv102' => ['102', 1], 'tv201' => ['201', 2]] as $name => [$room, $hotel]) {
            $uid = 'tv-df-' . $name . '-0001';
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $keys[$hotel], 'app_version' => '2.4.0', 'app_version_code' => 11]);
            self::assertSame(200, $s, (string) json_encode($j));
            self::$tv[$name] = ['uid' => $uid, 'token' => $j['data']['token'], 'hotel' => $hotel,
                'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hotel])];
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tmp as $f) {
            @unlink($f);
        }
        foreach (self::$tv as $t) {
            @unlink(LiveView::framePath($t['hotel'], $t['id']));
        }
        Notifier::$mailer = null;
        WebPush::$transport = null;
        Access::$userOverride = null;
        Access::forget();
        ContentResolver::resetExtensions();
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        WebPush::$transport = static fn (string $url, array $headers, string $body): array => ['status' => 201, 'body' => ''];
    }

    // ------------------------------------------------------------------ helpers

    private static function dev(string $tv): array
    {
        return ['Authorization: Bearer ' . self::$tv[$tv]['token'], 'X-Device-Id: ' . self::$tv[$tv]['uid']];
    }

    private static function api(string $tv, string $route, ?array $json, ?array $form = null): array
    {
        return TestEnv::http('POST', self::$url . 'api/' . $route, $json, self::dev($tv), null, $form);
    }

    /** Poll as a TV; returns [list of commands, raw "commands" JSON, full data] and acks them. */
    private static function poll(string $tv): array
    {
        [$s, $j, $raw] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv[$tv]['uid'] . '?hash=x', null, self::dev($tv));
        self::assertSame(200, $s, $raw);
        preg_match('/"commands":(\[.*?\])(,"|\})/s', $raw, $m);
        foreach ($j['data']['commands'] as $c) {
            self::api($tv, 'device/ack', ['command_id' => $c['id'], 'status' => 'acked', 'message' => 'ok']);
        }
        return [$j['data']['commands'], $m[1] ?? '[]', $j['data']];
    }

    private static function jpeg(int $w, int $h, bool $noise = false, int $q = 60): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 90, 160));
        if ($noise) {
            for ($i = 0; $i < $w * $h / 2; $i++) {
                imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
            }
        }
        $f = tempnam(sys_get_temp_dir(), 'live') . '.jpg';
        imagejpeg($im, $f, $q);
        imagedestroy($im);
        self::$tmp[] = $f;
        return $f;
    }

    private static function frame(string $tv, string $token, string $file): array
    {
        return self::api($tv, 'device/screenshot', null, ['image' => new CURLFile($file, 'image/jpeg', 'live.jpg'), 'live' => $token]);
    }

    private static function sessionToken(string $tv): string
    {
        return (string) DB::value('SELECT token FROM device_live_views WHERE device_id = :d', ['d' => self::$tv[$tv]['id']]);
    }

    // ------------------------------------------------------------------ commands

    public function testSpeakAndPlaySoundAreAcceptedAndQueuedWithPayloads(): void
    {
        $this->assertContains('SPEAK', Broadcaster::DEVICE_COMMANDS);
        $this->assertContains('PLAY_SOUND', Broadcaster::DEVICE_COMMANDS);
        $this->assertContains('SPEAK', TvControls::COMMANDS);
        $this->assertContains('PLAY_SOUND', TvControls::COMMANDS);
        self::poll('tv101');

        [, $n] = TvControls::send('SPEAK', 'rooms', [self::$room['101']], ['text' => '  નાસ્તો તૈયાર છે  ', 'lang' => 'gu', 'rate' => '1.25', 'repeat' => '2', 'volume' => '80', 'chime_before' => '1'], self::$u['fmgr']);
        $this->assertSame(1, $n);
        [, $n] = TvControls::send('PLAY_SOUND', 'rooms', [self::$room['101']], ['url' => 'https://cdn.example.com/sounds/bell.mp3', 'volume' => '60', 'repeat' => '3']);
        $this->assertSame(1, $n);
        [$bid, $n] = Broadcaster::sendCommand('SPEAK', 'all', [], ['text' => 'Pool closes at 8', 'lang' => 'en', 'rate' => 1, 'repeat' => 1, 'chime_before' => false]);
        $this->assertSame(3 - 1, $n, 'hotel 1 has two TVs');
        [$list, $raw] = self::poll('tv101');
        $this->assertSame(['SPEAK', 'PLAY_SOUND', 'SPEAK'], array_column($list, 'command'));
        $this->assertStringContainsString('"command":"SPEAK","payload":{"text":"નાસ્તો તૈયાર છે","lang":"gu","rate":1.25,"repeat":2,"volume":80,"chime_before":true}', $raw);
        $this->assertStringContainsString('"command":"PLAY_SOUND","payload":{"url":"https://cdn.example.com/sounds/bell.mp3","volume":60,"repeat":3}', $raw);
        // The TV's ack message (voice used / fallback) lands in the broadcast log.
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_logs WHERE broadcast_id = :b AND event = 'acked'", ['b' => $bid]));

        foreach ([
            ['SPEAK', ['text' => '']], ['SPEAK', ['text' => 'x', 'lang' => 'fr']], ['SPEAK', ['text' => 'x', 'repeat' => 9]],
            ['SPEAK', ['text' => 'x', 'rate' => 5]], ['PLAY_SOUND', ['url' => 'javascript:alert(1)']], ['PLAY_SOUND', ['url' => 'ftp://x/a.mp3']],
            ['PLAY_SOUND', ['url' => 'https://x/a.mp3', 'volume' => 150]], ['PLAY_SOUND', ['url' => 'https://x/a.mp3', 'repeat' => 0]],
        ] as [$cmd, $in]) {
            try {
                TvControls::send($cmd, 'rooms', [self::$room['101']], $in);
                $this->fail("$cmd " . json_encode($in) . ' must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], self::poll('tv101')[0], 'nothing queued for rejected payloads');
    }

    // ------------------------------------------------------------------ live view

    public function testLiveViewSessionLifecycle(): void
    {
        self::poll('tv101');
        $m = new AdminSession(self::$url, 'fmgr');
        [$s, $j] = $m->ajax('live_start', ['device_id' => self::$tv['tv101']['id']]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertTrue($j['data']['active']);
        $this->assertNull($j['data']['frame']);
        $this->assertSame(120, $j['data']['expires_in']);
        // The TV gets LIVE_VIEW with a session token and the size / quality limits.
        [$list, $raw] = self::poll('tv101');
        $this->assertSame(['LIVE_VIEW'], array_column($list, 'command'));
        $token = self::sessionToken('tv101');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertStringContainsString('"payload":{"session":"' . $token . '","interval":4,"max_sec":120,"max_width":960,"quality":60}', $raw);

        $shotsBefore = (int) DB::value("SELECT COUNT(*) FROM device_support_files WHERE device_id = :d AND kind = 'screenshot'", ['d' => self::$tv['tv101']['id']]);
        [$s, $j] = self::frame('tv101', $token, self::jpeg(960, 540));
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertTrue($j['data']['stored']);
        $this->assertSame(['continue' => true, 'interval' => 4], array_slice($j['data']['live'], 0, 2, true));
        $this->assertGreaterThan(100, $j['data']['live']['stop_in']);
        $this->assertLessThanOrEqual(120, $j['data']['live']['stop_in']);
        // Rate limit: a second frame within 2 s → 429 + Retry-After.
        [$s, , , $head] = self::frame('tv101', $token, self::jpeg(960, 540));
        $this->assertSame(429, $s);
        $this->assertMatchesRegularExpression('/Retry-After: \d+/i', $head);
        DB::query('UPDATE device_live_views SET frame_at = :t WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() - 5), 'd' => self::$tv['tv101']['id']]);
        // Size limits: too wide → 400, too big → 413, not a JPEG → 400.
        [$s, $j] = self::frame('tv101', $token, self::jpeg(1280, 720));
        $this->assertSame(400, $s);
        $this->assertStringContainsString('960', $j['error']['message']);
        [$s] = self::frame('tv101', $token, self::jpeg(960, 1800, true, 100));
        $this->assertSame(413, $s);
        $png = tempnam(sys_get_temp_dir(), 'png');
        self::$tmp[] = $png;
        $im = imagecreatetruecolor(100, 50);
        imagepng($im, $png);
        imagedestroy($im);
        [$s] = self::frame('tv101', $token, $png);
        $this->assertSame(400, $s);
        // Second good frame replaces the first: only live.jpg, no support-file rows (screenshots stay untouched).
        [$s, $j] = self::frame('tv101', $token, self::jpeg(640, 360));
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['stored']);
        $dir = dirname(LiveView::framePath(1, self::$tv['tv101']['id']));
        $this->assertSame(['live.jpg'], array_values(array_filter(scandir($dir) ?: [], static fn ($f) => str_starts_with($f, 'live'))));
        $this->assertSame([640, 360], array_slice(getimagesize(LiveView::framePath(1, self::$tv['tv101']['id'])) ?: [], 0, 2));
        $this->assertSame($shotsBefore, (int) DB::value("SELECT COUNT(*) FROM device_support_files WHERE device_id = :d AND kind = 'screenshot'", ['d' => self::$tv['tv101']['id']]));
        // A normal screenshot (no "live" field) still goes to the support files.
        [$s] = self::api('tv101', 'device/screenshot', null, ['image' => new CURLFile(self::jpeg(320, 180), 'image/jpeg', 's.jpg')]);
        $this->assertSame(200, $s);
        $this->assertSame($shotsBefore + 1, (int) DB::value("SELECT COUNT(*) FROM device_support_files WHERE device_id = :d AND kind = 'screenshot'", ['d' => self::$tv['tv101']['id']]));

        // Admin poll: newest frame + keepalive (expires_at moves forward).
        DB::query('UPDATE device_live_views SET expires_at = :t WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() + 30), 'd' => self::$tv['tv101']['id']]);
        [$s, $j] = $m->ajax('live_poll&device_id=' . self::$tv['tv101']['id']);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['active']);
        $this->assertSame(2, $j['data']['frame']['n']);
        $this->assertSame([640, 360], [$j['data']['frame']['width'], $j['data']['frame']['height']]);
        $this->assertGreaterThanOrEqual(118, $j['data']['expires_in']);
        $this->assertSame('acked', $j['data']['command']['status']);
        [$s, , $img, $head] = $m->get('live_view.php?device=' . self::$tv['tv101']['id'] . '&action=frame');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Content-Type: image/jpeg', $head);
        $this->assertStringContainsString('no-store', $head);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($img)[2]);
        [$s, , $html] = $m->get('live_view.php?device=' . self::$tv['tv101']['id']);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('live_start', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));

        // Wrong / old token → the TV is told to stop, nothing stored.
        DB::query('UPDATE device_live_views SET frame_at = :t WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() - 5), 'd' => self::$tv['tv101']['id']]);
        [$s, $j] = self::frame('tv101', str_repeat('a', 32), self::jpeg(320, 180));
        $this->assertSame(200, $s);
        $this->assertSame([false, false], [$j['data']['stored'], $j['data']['live']['continue']]);

        // Stop (admin closed the page) → next frame: continue false.
        [$s, $j] = $m->ajax('live_stop', ['device_id' => self::$tv['tv101']['id']]);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['stopped']);
        [$s, $j] = self::frame('tv101', $token, self::jpeg(320, 180));
        $this->assertSame([false, false, 0], [$j['data']['stored'], $j['data']['live']['continue'], $j['data']['live']['stop_in']]);
        [, $j] = $m->ajax('live_poll&device_id=' . self::$tv['tv101']['id']);
        $this->assertFalse($j['data']['active']);

        // Restart, then no keepalive for longer than 2 minutes → expired → the TV stops.
        [$s] = $m->ajax('live_start', ['device_id' => self::$tv['tv101']['id']]);
        $this->assertSame(200, $s);
        $token2 = self::sessionToken('tv101');
        $this->assertNotSame($token, $token2);
        DB::query('UPDATE device_live_views SET expires_at = :t, frame_at = NULL WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() - 1), 'd' => self::$tv['tv101']['id']]);
        [, $j] = self::frame('tv101', $token2, self::jpeg(320, 180));
        $this->assertFalse($j['data']['live']['continue']);
        // Re-opening expires the not yet delivered LIVE_VIEW of the previous start.
        $m->ajax('live_start', ['device_id' => self::$tv['tv101']['id']]);
        $m->ajax('live_start', ['device_id' => self::$tv['tv101']['id']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d AND command = 'LIVE_VIEW' AND status = 'pending'", ['d' => self::$tv['tv101']['id']]));
        $m->ajax('live_stop', ['device_id' => self::$tv['tv101']['id']]);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d AND command = 'LIVE_VIEW' AND status = 'pending'", ['d' => self::$tv['tv101']['id']]));

        // Cleanup task deletes frames of sessions that ended more than an hour ago.
        DB::query('UPDATE device_live_views SET stopped_at = :t WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() - 7200), 'd' => self::$tv['tv101']['id']]);
        $this->assertGreaterThanOrEqual(1, LiveView::cleanup());
        $this->assertFileDoesNotExist(LiveView::framePath(1, self::$tv['tv101']['id']));
        $this->assertNull(Tenant::run(1, fn () => LiveView::find(self::$tv['tv101']['id'])));
    }

    public function testLiveViewTenancyAccessAndRoles(): void
    {
        $tv101 = self::$tv['tv101']['id'];
        // Another hotel's manager: 404 (tenancy); restricted manager on another room's TV: 403.
        $m2 = new AdminSession(self::$url, 'fmgr2');
        [$s] = $m2->ajax('live_start', ['device_id' => $tv101]);
        $this->assertSame(404, $s);
        [$s] = $m2->get('live_view.php?device=' . $tv101 . '&action=frame');
        $this->assertSame(404, $s);
        $lim = new AdminSession(self::$url, 'flim');
        [$s] = $lim->ajax('live_start', ['device_id' => $tv101]);
        $this->assertSame(403, $s);
        [$s] = $lim->ajax('live_poll&device_id=' . $tv101);
        $this->assertSame(403, $s);
        [$s, $j] = $lim->ajax('live_start', ['device_id' => self::$tv['tv102']['id']]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $lim->ajax('live_stop', ['device_id' => self::$tv['tv102']['id']]);
        // Staff / reception: no live view (support.view = manager+).
        foreach (['fstaff', 'frecep'] as $name) {
            $x = new AdminSession(self::$url, $name);
            [$s] = $x->ajax('live_start', ['device_id' => $tv101]);
            $this->assertSame(403, $s, $name);
            [$s] = $x->get('live_view.php?device=' . $tv101);
            $this->assertSame(403, $s, $name);
        }
        // CSRF: POST without the token is refused.
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=live_start', ['device_id' => $tv101], ['X-Requested-With: XMLHttpRequest'], (new AdminSession(self::$url, 'fmgr'))->jar);
        $this->assertSame(419, $s);

        // A TV of hotel 2 cannot feed hotel 1's session with its token.
        $m = new AdminSession(self::$url, 'fmgr');
        $m->ajax('live_start', ['device_id' => $tv101]);
        $token = self::sessionToken('tv101');
        [$s, $j] = self::frame('tv201', $token, self::jpeg(320, 180));
        $this->assertSame(200, $s);
        $this->assertFalse($j['data']['stored']);
        $this->assertFalse(Tenant::run(1, fn () => LiveView::status($tv101, false))['frame'] !== null);
        // In-process: Access limits apply to the core API too.
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$u['flim']]);
        Access::forget();
        try {
            LiveView::start($tv101);
            $this->fail('restricted user must not start live view of another TV');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        } finally {
            Access::$userOverride = null;
            Access::forget();
        }
        $m->ajax('live_stop', ['device_id' => $tv101]);
        // Unauthenticated device upload.
        [$s] = TestEnv::http('POST', self::$url . 'api/device/screenshot', null, [], null, ['image' => new CURLFile(self::jpeg(320, 180), 'image/jpeg', 'x.jpg'), 'live' => $token]);
        $this->assertSame(401, $s);
    }

    // ------------------------------------------------------------------ health

    private static function health(array $over = []): array
    {
        return $over + [
            'storage_free_mb' => 2048, 'storage_total_mb' => 8192, 'cache_free_mb' => 2048, 'cache_total_mb' => 8192,
            'ram_avail_mb' => 900, 'ram_total_mb' => 2048, 'ram_low' => false, 'cpu_temp_c' => 52.4,
            'wifi_rssi' => -58, 'wifi_link_mbps' => 72, 'network' => 'wifi', 'uptime_sec' => 3600, 'app_mem_mb' => 180,
            'resolution' => '3840x2160', 'ui_resolution' => '1920x1080', 'refresh_hz' => 60, 'android_version' => '11', 'sdk' => 30,
            'device_owner' => true, 'last_crash_at' => '2026-10-01T10:00:00+05:30',
            'usb' => ['source' => 'server', 'folder' => false, 'files' => 0, 'permission' => true, 'error' => null],
            'cec' => ['mode' => 'auto', 'detected_box' => false, 'box_mode' => false], 'live_view' => false,
        ];
    }

    private static function heartbeat(string $tv, array $health): array
    {
        return self::api($tv, 'device/heartbeat', [
            'app_version' => '2.4.0', 'app_version_code' => 11, 'android_version' => '11', 'model' => 'Test TV', 'battery' => -1,
            'network_type' => 'wifi', 'wifi_signal' => $health['wifi_rssi'] ?? -60, 'free_storage_mb' => 2048, 'screen_on' => true,
            'uptime_sec' => $health['uptime_sec'] ?? 100, 'health' => $health,
        ]);
    }

    public function testHeartbeatHealthIsStoredWithHistory(): void
    {
        $id = self::$tv['tv102']['id'];
        DB::query('DELETE FROM device_health_history WHERE device_id = :d', ['d' => $id]);
        [$s, $j] = self::heartbeat('tv102', self::health(['cpu_temp_c' => '81.26', 'evil' => '<script>', 'resolution' => '1920x1080"><x', 'android_version' => '<b>11</b>', 'wifi_rssi' => 20, 'ram_avail_mb' => -5]));
        $this->assertSame(200, $s, (string) json_encode($j));
        $dev = DB::one('SELECT * FROM devices WHERE id = :id', ['id' => $id]);
        $h = DeviceHealth::of($dev);
        $this->assertSame(81.3, $h['cpu_temp_c']);
        $this->assertArrayNotHasKey('evil', $h);
        $this->assertArrayNotHasKey('resolution', $h, 'invalid resolution dropped');
        $this->assertSame('11', $h['android_version']);
        $this->assertSame(0, $h['wifi_rssi'], 'clamped to -127..0');
        $this->assertSame(0, $h['ram_avail_mb']);
        $this->assertSame(date('Y-m-d H:i:s', (int) strtotime('2026-10-01T10:00:00+05:30')), $h['last_crash_at']);
        $this->assertTrue($h['device_owner']);
        $this->assertSame(['source' => 'server', 'folder' => false, 'files' => 0, 'permission' => true], $h['usb']);
        $this->assertNotNull($dev['health_at']);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM device_health_history WHERE device_id = :d', ['d' => $id]));
        $this->assertSame('81.3', (string) DB::value('SELECT cpu_temp_c FROM device_health_history WHERE device_id = :d', ['d' => $id]));

        // One history row per 10 minutes.
        self::heartbeat('tv102', self::health());
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM device_health_history WHERE device_id = :d', ['d' => $id]));
        DB::query('UPDATE device_health_history SET created_at = :t WHERE device_id = :d', ['t' => date('Y-m-d H:i:s', time() - 601), 'd' => $id]);
        self::heartbeat('tv102', self::health());
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM device_health_history WHERE device_id = :d', ['d' => $id]));
        $this->assertSame(52.4, DeviceHealth::of(DB::one('SELECT * FROM devices WHERE id = :id', ['id' => $id]))['cpu_temp_c']);
        // A 2.3 app (no health) changes nothing; garbage health is ignored.
        foreach ([null, 'x', [1, 2]] as $bad) {
            [$s] = self::api('tv102', 'device/heartbeat', ['app_version' => '2.3.0', 'health' => $bad]);
            $this->assertSame(200, $s);
        }
        $this->assertSame(52.4, DeviceHealth::of(DB::one('SELECT * FROM devices WHERE id = :id', ['id' => $id]))['cpu_temp_c']);

        // 7-day retention.
        DB::query('UPDATE device_health_history SET created_at = :t WHERE device_id = :d ORDER BY id LIMIT 1', ['t' => date('Y-m-d H:i:s', time() - 8 * 86400), 'd' => $id]);
        $this->assertGreaterThanOrEqual(1, DeviceHealth::pruneHistory());
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM device_health_history WHERE device_id = :d', ['d' => $id]));
        $this->assertSame([$id], array_keys(Tenant::run(1, fn () => DeviceHealth::history([$id, self::$tv['tv201']['id']], 24))), 'hotel 2 history never mixed in');
    }

    public function testWarningsThresholdsAndDebounce(): void
    {
        $this->assertSame([], DeviceHealth::warnings(self::health()));
        $w = DeviceHealth::warnings(self::health(['storage_free_mb' => 320, 'ram_avail_mb' => 100, 'cpu_temp_c' => 76.0, 'wifi_rssi' => -80, 'uptime_sec' => 31 * 86400]));
        $this->assertSame(DeviceHealth::WARNINGS, array_keys($w));
        $this->assertSame('76 °C', $w['temp_high']);
        $this->assertArrayHasKey('ram_low', DeviceHealth::warnings(self::health(['ram_avail_mb' => 300, 'ram_total_mb' => 4096])), '< 10 %');
        $this->assertArrayHasKey('ram_low', DeviceHealth::warnings(self::health(['ram_low' => true])));
        $this->assertArrayNotHasKey('wifi_weak', DeviceHealth::warnings(self::health(['wifi_rssi' => -90, 'network' => 'ethernet'])));
        $this->assertArrayNotHasKey('temp_high', DeviceHealth::warnings(self::health(['cpu_temp_c' => 75.0])));

        $t = 1_800_000_000;
        [$new, $st] = DeviceHealth::debounce([], ['temp_high', 'wifi_weak'], $t);
        $this->assertSame(['temp_high', 'wifi_weak'], $new);
        [$new, $st] = DeviceHealth::debounce($st, ['temp_high', 'wifi_weak'], $t + 600);
        $this->assertSame([], $new, 'still the same warnings: not re-sent');
        [$new, $st] = DeviceHealth::debounce($st, ['temp_high'], $t + 1200);
        $this->assertSame([], $new);
        [$new, $st] = DeviceHealth::debounce($st, ['temp_high', 'wifi_weak'], $t + 1800);
        $this->assertSame([], $new, 'wifi flapping back within 6 h is not re-sent');
        [$new, $st] = DeviceHealth::debounce($st, ['temp_high', 'wifi_weak'], $t + 86400 + 10);
        $this->assertSame(['temp_high', 'wifi_weak'], $new, 'reminder after 24 h');
        [$new, $st] = DeviceHealth::debounce($st, [], $t + 86400 + 7 * 3600);
        $this->assertSame([[], []], [$new, $st], 'cleared and forgotten after 6 h');
    }

    public function testHealthAlertsGoThroughNotifierDebounced(): void
    {
        $mails = [];
        Notifier::$mailer = function (string $to, string $subject, string $msg) use (&$mails) {
            $mails[] = [$to, $subject, $msg];
            return true;
        };
        Settings::setMany(['notify_email' => 'ops@h1.test', 'notify_health' => '1']);
        DB::query("UPDATE devices SET health_alerts = NULL, health = NULL, health_at = NULL WHERE hotel_id = 1");
        self::heartbeat('tv101', self::health(['storage_free_mb' => 300, 'cpu_temp_c' => 82.0]));
        self::heartbeat('tv102', self::health());
        $now = time();
        $this->assertSame(1, Tenant::run(1, fn () => DeviceHealth::checkAlerts($now)));
        $this->assertCount(1, $mails);
        $this->assertSame('ops@h1.test', $mails[0][0]);
        $this->assertStringContainsString('TV health warning', $mails[0][1]);
        $this->assertStringContainsString('101', $mails[0][2]);
        $this->assertStringContainsString('Storage low', $mails[0][2]);
        $this->assertStringContainsString('Too hot (82 °C)', $mails[0][2]);
        $this->assertStringNotContainsString('102', $mails[0][2]);
        // Debounced: the next run (and the task) send nothing new.
        $this->assertSame(0, Tenant::run(1, fn () => DeviceHealth::checkAlerts($now + 600)));
        $this->assertCount(1, $mails);
        $summary = (new DeviceHealthTask())->run();
        $this->assertSame(0, $summary['alerted']);
        $this->assertCount(1, $mails);
        // A new warning on the same TV is reported on its own.
        self::heartbeat('tv101', self::health(['storage_free_mb' => 300, 'cpu_temp_c' => 82.0, 'wifi_rssi' => -82]));
        $this->assertSame(1, Tenant::run(1, fn () => DeviceHealth::checkAlerts($now + 1200)));
        $this->assertStringContainsString('Weak Wi-Fi', $mails[1][2]);
        $this->assertStringNotContainsString('Storage low', $mails[1][2]);
        // Alerts off: state still advances, nothing mailed. Stale health (offline TV) is not alerted.
        Settings::set('notify_health', '0');
        $this->assertSame(1, Tenant::run(1, fn () => DeviceHealth::checkAlerts($now + 90000)));
        $this->assertCount(2, $mails);
        DB::query('UPDATE devices SET health_alerts = NULL, health_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', $now - 7200), 'id' => self::$tv['tv101']['id']]);
        Settings::set('notify_health', '1');
        $this->assertSame(0, Tenant::run(1, fn () => DeviceHealth::checkAlerts($now)));
        Notifier::$mailer = null;
    }

    public function testHealthPageRendersForAllRolesWithAccessLimits(): void
    {
        self::heartbeat('tv101', self::health(['cpu_temp_c' => 80.0, 'storage_free_mb' => 100]));
        self::heartbeat('tv102', self::health());
        DB::query('UPDATE device_health_history SET created_at = :t WHERE hotel_id = 1', ['t' => date('Y-m-d H:i:s', time() - 700)]);
        self::heartbeat('tv101', self::health(['cpu_temp_c' => 78.0]));
        foreach (['fboss', 'fmgr', 'fstaff', 'frecep', 'flim'] as $name) {
            $x = new AdminSession(self::$url, $name);
            [$s, , $html] = $x->get('tv_health.php');
            $this->assertSame(200, $s, $name);
            $this->assertFalse(TestEnv::hasPhpError($html), $name);
            $this->assertStringContainsString('TV health', $html, $name);
            $this->assertStringContainsString('<strong>102</strong>', $html, $name);
            if ($name === 'flim') {
                $this->assertStringNotContainsString('<strong>101</strong>', $html, 'limited user sees only room 102');
            } else {
                $this->assertStringContainsString('<strong>101</strong>', $html, $name);
                $this->assertStringContainsString('Too hot', $html, $name);
                $this->assertStringContainsString('<svg class="hc-spark"', $html, $name);
            }
            $this->assertSame(in_array($name, ['fboss', 'fmgr', 'flim'], true), str_contains($html, 'live_view.php?device='), $name . ' live view link');
            [$s, , $html] = $x->get('tv_health.php?warn=1');
            $this->assertSame(200, $s);
            $this->assertFalse(TestEnv::hasPhpError($html));
            // TV detail page with the health card.
            [$s, , $html] = $x->get('rooms.php?action=device&id=' . self::$tv['tv102']['id']);
            $this->assertSame(200, $s, $name);
            $this->assertFalse(TestEnv::hasPhpError($html), $name);
            $this->assertStringContainsString('deviceFeatures', $html);
            $this->assertSame(in_array($name, ['fboss', 'fmgr', 'flim'], true), str_contains($html, 'name="cec_mode"'), $name . ' room flag form');
        }
        $this->assertSame('', TestEnv::phpErrors());
        // Another hotel's manager does not see hotel 1's TVs.
        [$s, , $html] = (new AdminSession(self::$url, 'fmgr2'))->get('tv_health.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('<strong>101</strong>', $html);
        // Settings toggle: super admin only.
        [$s] = (new AdminSession(self::$url, 'fmgr'))->post('tv_health.php', ['op' => 'notify', 'notify_health' => '1']);
        $this->assertSame(403, $s);
        [$s] = (new AdminSession(self::$url, 'fboss'))->post('tv_health.php', ['op' => 'notify']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('0', Settings::get('notify_health'));
    }

    // ------------------------------------------------------------------ USB / CEC mode

    public function testUsbModeAndCecModeFlagsInContent(): void
    {
        [, , $data] = self::poll('tv101');
        $this->assertArrayNotHasKey('usb_mode', $data['content']);
        $this->assertArrayNotHasKey('cec_mode', $data['content']);
        $m = new AdminSession(self::$url, 'fmgr');
        [$s, , , $head] = $m->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'device_id' => self::$tv['tv101']['id'], 'usb_mode' => '1', 'cec_mode' => 'box']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('rooms.php?action=device', $head);
        [, $raw, $data] = self::poll('tv101');
        $this->assertTrue($data['content']['usb_mode']);
        $this->assertSame('box', $data['content']['cec_mode']);
        [, , $data] = self::poll('tv102');
        $this->assertArrayNotHasKey('usb_mode', $data['content'], 'other rooms unchanged');
        // Validation, CSRF, permissions, Access, tenancy.
        $m->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'xbox']);
        $this->assertSame('box', DB::value('SELECT cec_mode FROM rooms WHERE id = :id', ['id' => self::$room['101']]));
        [$s] = TestEnv::http('POST', self::$url . 'admin/tv_health.php', null, [], $m->jar, ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'tv']);
        $this->assertSame(419, $s);
        foreach (['fstaff', 'frecep'] as $name) {
            [$s] = (new AdminSession(self::$url, $name))->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'tv']);
            $this->assertSame(403, $s, $name);
        }
        [$s] = (new AdminSession(self::$url, 'flim'))->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'tv']);
        $this->assertSame(403, $s);
        [$s] = (new AdminSession(self::$url, 'fmgr2'))->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'tv']);
        $this->assertSame(404, $s);
        $this->assertSame('box', DB::value('SELECT cec_mode FROM rooms WHERE id = :id', ['id' => self::$room['101']]));
        // Switch back off.
        $m->post('tv_health.php', ['op' => 'room_features', 'room_id' => self::$room['101'], 'cec_mode' => 'auto']);
        [, , $data] = self::poll('tv101');
        $this->assertArrayNotHasKey('usb_mode', $data['content']);
        $this->assertArrayNotHasKey('cec_mode', $data['content']);
    }

    // ------------------------------------------------------------------ broadcast announce

    public function testBroadcastAnnounceForm(): void
    {
        self::poll('tv101');
        self::poll('tv102');
        $m = new AdminSession(self::$url, 'fstaff');
        [$s, , $html] = $m->get('broadcast.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="op" value="announce"', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $before = (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SPEAK'");
        // CSRF.
        [$s] = TestEnv::http('POST', self::$url . 'admin/broadcast.php', null, [], $m->jar, ['op' => 'announce', 'text' => 'Hi', 'target_type' => 'all']);
        $this->assertSame(419, $s);
        // Validation: empty text, bad language, no target.
        foreach ([['text' => '', 'target_type' => 'all'], ['text' => 'Hi', 'lang' => 'fr', 'target_type' => 'all'], ['text' => 'Hi', 'target_type' => 'rooms']] as $bad) {
            [$s] = $m->post('broadcast.php', ['op' => 'announce'] + $bad);
            $this->assertSame(302, $s);
        }
        $this->assertSame($before, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SPEAK'"));
        // Valid: room 102 only.
        [$s, , , $head] = $m->post('broadcast.php', ['op' => 'announce', 'text' => 'नाश्ता तैयार है', 'lang' => 'hi', 'repeat' => '2', 'chime_before' => '1', 'target_type' => 'rooms', 'room_ids' => [self::$room['102']]]);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('#announce', $head);
        $this->assertSame([], self::poll('tv101')[0]);
        [$list, $raw] = self::poll('tv102');
        $this->assertSame(['SPEAK'], array_column($list, 'command'));
        $this->assertStringContainsString('"payload":{"text":"नाश्ता तैयार है","lang":"hi","rate":1', $raw);
        $this->assertStringContainsString('"repeat":2', $raw);
        $this->assertStringContainsString('"chime_before":true', $raw);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE command = 'SPEAK' AND hotel_id = 1 AND title LIKE '%नाश्ता%'"));
        // Restricted user: only their TVs; another hotel's room ids → 404.
        $lim = new AdminSession(self::$url, 'flim');
        [$s] = $lim->post('broadcast.php', ['op' => 'announce', 'text' => 'Hello', 'target_type' => 'rooms', 'room_ids' => [self::$room['101']]]);
        $this->assertSame(403, $s);
        [$s] = $lim->post('broadcast.php', ['op' => 'announce', 'text' => 'Hello', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        [$s] = $m->post('broadcast.php', ['op' => 'announce', 'text' => 'Hello', 'target_type' => 'rooms', 'room_ids' => [self::$room['201']]]);
        $this->assertSame(404, $s);
        $this->assertSame([], self::poll('tv201')[0]);
        // Reception has no broadcast page at all.
        [$s] = (new AdminSession(self::$url, 'frecep'))->post('broadcast.php', ['op' => 'announce', 'text' => 'Hello', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        $this->assertSame('', TestEnv::phpErrors());
    }
}
