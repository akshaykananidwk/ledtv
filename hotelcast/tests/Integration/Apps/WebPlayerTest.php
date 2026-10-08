<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Web player (2.4, #45): the player page (headers, CSP, config, no warnings), its QR image, the
 * `platform` / `user_agent` columns (migration 026), a "web" device registering, polling, sending
 * heartbeats and acking the new SPEAK / PLAY_SOUND commands through the normal device API, the Rooms &
 * TVs page (Web player badge, TV details, "Web player link" button, also on Add TV with QR) and the
 * Gujarati / Hindi strings. The browser end-to-end run is tests/browser/webplayer_e2e.js.
 */
final class WebPlayerTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private static string $uid = 'web-5b0c7e1a-2f4d-4c8e-9a10-3d2b1c0f9e88';
    private static string $token = '';
    private const UA = 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) 76.0.3809.146/6.0 TV Safari/537.36';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => 1, 'username' => 'wpMgr', 'email' => 'wpmgr@t.test', 'full_name' => 'Wp Mgr', 'password_hash' => $pw, 'role' => 'manager']);
        foreach (['401', '402'] as $n) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '4']);
        }
        $c = static fn (array $row): int => DB::insert('content_items', $row + ['duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        self::$id['img'] = $c(['title' => 'Lobby photo', 'type' => 'image', 'url' => 'https://cdn.example.com/lobby.jpg', 'duration' => 8]);
        self::$id['ann'] = $c(['title' => 'Checkout', 'type' => 'announcement', 'body' => 'ચેક-આઉટ 11 વાગ્યે', 'settings' => '{"style":"fullscreen"}']);
        [$data] = ContentManager::validate(['title' => 'Split', 'duration' => 30, 'layout' => ['zones' => [
            ['x' => 0, 'y' => 0, 'w' => 70, 'h' => 100, 'source' => 'c:' . self::$id['img']],
            ['x' => 70, 'y' => 0, 'w' => 30, 'h' => 100, 'source' => 'c:' . self::$id['ann']],
        ]]], 'layout', false);
        self::$id['lay'] = $c(['title' => 'Split', 'type' => 'layout', 'duration' => 30, 'settings' => json_out((object) $data['settings'])]);
        self::$id['pl'] = DB::insert('content_playlists', ['name' => 'Web loop', 'transition' => 'fade', 'created_at' => now()]);
        foreach ([self::$id['img'], self::$id['ann'], self::$id['lay']] as $i => $cid) {
            DB::insert('playlist_items', ['playlist_id' => self::$id['pl'], 'content_id' => $cid, 'sort_order' => $i, 'duration' => null]);
        }
        DB::update('rooms', ['playlist_id' => self::$id['pl']], 'id = :id', ['id' => self::$id['r401']]);
        Settings::bumpContentVersion();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Tenant::forget();
        Settings::flush();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
    }

    private static function auth(): array
    {
        return ['Authorization: Bearer ' . self::$token, 'X-Device-Id: ' . self::$uid];
    }

    private static function header(string $head, string $name): ?string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $head, $m) ? trim($m[1]) : null;
    }

    // ------------------------------------------------------------------ schema

    public function testMigrationAddsPlatformColumns(): void
    {
        $this->assertTrue(Migrator::hasColumn(DB::pdo(), 'devices', 'platform'));
        $this->assertTrue(Migrator::hasColumn(DB::pdo(), 'devices', 'user_agent'));
        $this->assertContains('026_web_player.sql', Migrator::applied());
        $col = DB::one("SHOW COLUMNS FROM devices LIKE 'platform'");
        $this->assertSame("enum('android','web')", strtolower((string) $col['Type']));
        $this->assertSame('android', $col['Default']);
        // Re-running the migration is harmless (duplicate column ignored).
        DB::delete('schema_migrations', 'filename = :f', ['f' => '026_web_player.sql']);
        Migrator::migrate();
        $this->assertContains('026_web_player.sql', Migrator::applied());
    }

    public function testPlatformFields(): void
    {
        $this->assertSame(['platform' => 'web', 'user_agent' => 'UA x'], DeviceManager::platformFields(['device_type' => 'web', 'user_agent' => '<b>UA x</b>']));
        $this->assertSame(['platform' => 'web', 'user_agent' => null], DeviceManager::platformFields(['platform' => 'WEB']));
        $this->assertSame(['platform' => 'android', 'user_agent' => null], DeviceManager::platformFields(['user_agent' => 'ignored for android']));
        $this->assertSame(['platform' => 'android', 'user_agent' => null], DeviceManager::platformFields(['platform' => 'ios']));
        $this->assertSame(255, mb_strlen((string) DeviceManager::platformFields(['platform' => 'web', 'user_agent' => str_repeat('a', 400)])['user_agent']));
    }

    // ------------------------------------------------------------------ player page

    public function testPlayerPageRendersWithKioskFriendlyHeaders(): void
    {
        [$s, , $html, $head] = TestEnv::http('GET', self::$url . 'player/', null, ['Accept-Language: gu-IN,gu;q=0.9,en;q=0.5']);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('text/html', (string) self::header($head, 'Content-Type'));
        // No framing restriction (kiosk shells / signage CMS may embed the player), but a sane CSP.
        $this->assertNull(self::header($head, 'X-Frame-Options'));
        $csp = (string) self::header($head, 'Content-Security-Policy');
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'none'", 'frame-ancestors *', 'media-src *', 'frame-src *', "form-action 'self'"] as $part) {
            $this->assertStringContainsString($part, $csp);
        }
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp);
        $this->assertSame($csp, WebPlayer::csp());
        $this->assertStringContainsString('no-cache', (string) self::header($head, 'Cache-Control'));
        $this->assertStringContainsString('autoplay=*', (string) self::header($head, 'Permissions-Policy'));
        $this->assertSame('nosniff', self::header($head, 'X-Content-Type-Options'));

        $this->assertStringContainsString('<html lang="gu">', $html);
        $this->assertMatchesRegularExpression('#<script src="\.\./assets/player/player\.js\?v=[0-9a-f]{8}"></script>#', $html);
        $this->assertMatchesRegularExpression('#href="\.\./assets/player/player\.css\?v=[0-9a-f]{8}"#', $html);
        $this->assertSame(0, preg_match('#<script>(?!</script>)#', $html), 'no inline script');
        $this->assertSame(1, preg_match('#<script type="application/json" id="hc-config">(.+?)</script>#s', $html, $m));
        $cfg = json_decode($m[1], true);
        $this->assertSame(WebPlayer::VERSION, $cfg['version']);
        $this->assertGreaterThanOrEqual(10, $cfg['versionCode'], 'layouts need app_version_code >= 10');
        $this->assertGreaterThanOrEqual(Layouts::MIN_APP_CODE, $cfg['versionCode']);
        $this->assertSame('../api/index.php?r=', $cfg['api']);
        $this->assertSame('gu', $cfg['lang']);
        $this->assertStringStartsWith('../assets/vendor/hlsjs/hls.min.js?v=', (string) $cfg['hls']);
        $this->assertSame(['en', 'gu', 'hi'], array_keys($cfg['strings']));
        $this->assertSame('આ સ્ક્રીન સેટ કરો', $cfg['strings']['gu']['Set up this screen']);
        $this->assertSame('इस स्क्रीन को सेट करें', $cfg['strings']['hi']['Set up this screen']);
        $this->assertSame('Set up this screen', $cfg['strings']['en']['Set up this screen']);

        // ?lang= wins over Accept-Language; unknown → en. The redirect /player → /player/ works.
        [, , $html] = TestEnv::http('GET', self::$url . 'player/?lang=hi', null, ['Accept-Language: gu']);
        $this->assertStringContainsString('<html lang="hi">', $html);
        [, , $html] = TestEnv::http('GET', self::$url . 'player/index.php?lang=xx');
        $this->assertStringContainsString('<html lang="en">', $html);
        [$s, , , $head] = TestEnv::http('GET', self::$url . 'player');
        $this->assertSame(302, $s);
        $this->assertStringEndsWith('/player/', (string) self::header($head, 'Location'));

        foreach (['assets/player/player.js' => 'javascript', 'assets/player/player.css' => 'css'] as $path => $type) {
            [$s, , $body, $h] = TestEnv::http('GET', self::$url . $path);
            $this->assertSame(200, $s, $path);
            $this->assertStringContainsString($type, (string) self::header($h, 'Content-Type'), $path);
            $this->assertGreaterThan(1000, strlen($body));
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testLanguageDetection(): void
    {
        $this->assertSame('gu', WebPlayer::language(['lang' => 'gu']));
        $this->assertSame('hi', WebPlayer::language([], 'hi-IN,hi;q=0.9'));
        $this->assertSame('en', WebPlayer::language([], 'en-US,gu;q=0.5'));
        $this->assertSame('gu', WebPlayer::language([], 'fr-FR,gu;q=0.5'));
        $this->assertSame('en', WebPlayer::language(['lang' => ['x']], ''));
    }

    /** The player must stay ES5 for old Tizen / webOS browsers (full check: npx acorn --ecma5). */
    public function testPlayerScriptLooksLikeEs5(): void
    {
        $js = (string) file_get_contents(HC_ROOT . '/assets/player/player.js');
        $code = preg_replace(["#/\\*.*?\\*/#s", '#^\s*//.*$#m', "#'(?:[^'\\\\]|\\\\.)*'#", '#"(?:[^"\\\\]|\\\\.)*"#', '#/(?![*/])(?:[^/\\\\\n]|\\\\.)+/[gimy]*#'], ' ', $js);
        foreach (['=>' => 'arrow function', '`' => 'template literal', 'const ' => 'const', 'let ' => 'let', 'class ' => 'class', '...' => 'spread', 'async ' => 'async', 'await ' => 'await', 'fetch(' => 'fetch (old TV browsers)'] as $needle => $what) {
            $this->assertStringNotContainsString($needle, (string) $code, $what);
        }
        $this->assertStringContainsString("'use strict'", $js);
    }

    public function testQrImage(): void
    {
        [$s, , $svg, $head] = TestEnv::http('GET', self::$url . 'player/qr.php?code=K7P2QX');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('image/svg+xml', (string) self::header($head, 'Content-Type'));
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertSame(QrCode::svg(self::$url . 'admin/claim.php?code=K7P2QX'), $svg);
        // Only setup codes: not a general QR generator.
        foreach (['', '?code=', '?code=https://evil.example', '?code=K7P2Q0', '?code[]=x'] as $q) {
            [$s] = TestEnv::http('GET', self::$url . 'player/qr.php' . $q);
            $this->assertSame(404, $s, $q);
        }
    }

    // ------------------------------------------------------------------ device API as a web player

    public function testWebDeviceRegistersPollsAndAcks(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/index.php?r=device/register', [
            'device_id' => self::$uid, 'room_number' => '401', 'registration_key' => 'TESTKEY123456789',
            'app_version' => 'web-' . WebPlayer::VERSION, 'app_version_code' => WebPlayer::VERSION_CODE, 'android_version' => 'Tizen 6.0',
            'model' => 'Web player · Samsung Internet 76 · Tizen 6.0', 'device_type' => 'web', 'platform' => 'web', 'user_agent' => self::UA,
        ]);
        $this->assertSame(200, $s, json_encode($j));
        self::$token = $j['data']['token'];
        $this->assertSame('401', $j['data']['room']['number']);
        $this->assertSame(hash('sha256', '1234'), $j['data']['settings_pin_hash']);
        $dev = DB::one('SELECT * FROM devices WHERE device_uid = :u', ['u' => self::$uid]);
        $this->assertSame('web', $dev['platform']);
        $this->assertSame(self::UA, $dev['user_agent']);
        $this->assertSame('web-2.4.0', $dev['app_version']);
        $this->assertSame(11, (int) $dev['app_version_code']);

        // Poll (the player uses index.php?r= so it works without mod_rewrite, and asks for a long poll).
        $poll = self::$url . 'api/index.php?r=device/command/' . self::$uid;
        [$s, $j] = TestEnv::http('GET', $poll . '&hash=&wait=20', null, self::auth());
        $this->assertSame(200, $s);
        $c = $j['data']['content'];
        $this->assertSame('assigned', $c['mode']);
        $this->assertSame(['image', 'announcement', 'layout'], array_column($c['items'], 'type'), 'version code 11 gets layouts');
        $this->assertSame(['image', 'announcement'], array_map(static fn ($z) => $z['items'][0]['type'], $c['items'][2]['layout']['zones']));
        $hash = $j['data']['content_hash'];
        [$s, $j] = TestEnv::http('GET', $poll . '&hash=' . $hash, null, self::auth());
        $this->assertFalse($j['data']['content_changed']);
        $this->assertArrayNotHasKey('content', $j['data']);

        // Heartbeat like the TV.
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/index.php?r=device/heartbeat', [
            'app_version' => 'web-2.4.0', 'app_version_code' => 11, 'android_version' => 'Tizen 6.0', 'network_type' => 'wifi',
            'current_content_hash' => $hash, 'current_item_id' => self::$id['img'], 'screen_on' => false, 'uptime_sec' => 42,
        ], self::auth());
        $this->assertSame(200, $s);
        $this->assertSame(hash('sha256', '1234'), $j['data']['settings_pin_hash']);
        $dev = DB::one('SELECT * FROM devices WHERE device_uid = :u', ['u' => self::$uid]);
        $this->assertSame(0, (int) $dev['screen_on']);
        $this->assertSame('web', $dev['platform'], 'heartbeat keeps the platform');

        // Commands, incl. the 2.4 SPEAK / PLAY_SOUND, reach the web player and are acked.
        $ids = [];
        foreach ([['SPEAK', ['text' => 'ચેક-આઉટ સમય 11 વાગ્યે', 'lang' => 'gu']], ['PLAY_SOUND', ['sound' => 'chime']], ['SCREENSHOT', []]] as [$cmd, $payload]) {
            $ids[$cmd] = DB::insert('device_commands', ['device_id' => $dev['id'], 'command' => $cmd, 'payload' => json_encode($payload), 'status' => 'pending', 'created_at' => now()]);
        }
        [, $j] = TestEnv::http('GET', $poll . '&hash=' . $hash, null, self::auth());
        $this->assertSame(['SPEAK', 'PLAY_SOUND', 'SCREENSHOT'], array_column($j['data']['commands'], 'command'));
        $this->assertSame('gu', $j['data']['commands'][0]['payload']['lang']);
        foreach ([['SPEAK', 'acked', 'Speaking (gu-IN, voice Google ગુજરાતી)'], ['PLAY_SOUND', 'acked', 'Playing chime'], ['SCREENSHOT', 'failed', 'unsupported: the browser does not allow drawing the screen']] as [$cmd, $status, $msg]) {
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/index.php?r=device/ack', ['command_id' => $ids[$cmd], 'status' => $status, 'message' => $msg], self::auth());
            $this->assertSame(200, $s);
            $row = DB::one('SELECT status, message FROM device_commands WHERE id = :id', ['id' => $ids[$cmd]]);
            $this->assertSame([$status, $msg], [$row['status'], $row['message']]);
        }
        [, $j] = TestEnv::http('GET', $poll . '&hash=' . $hash, null, self::auth());
        $this->assertSame([], $j['data']['commands']);

        // The QR setup start works for a web device id (same flow as the TV app).
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/index.php?r=provision/start', ['device_id' => 'web-0e1f2a3b-4c5d-4e6f-8a9b-0c1d2e3f4a5b', 'model' => 'Web player · Chrome 140 · Linux', 'app_version' => 'web-2.4.0']);
        $this->assertSame(200, $s);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $j['data']['code']);
        [$s] = TestEnv::http('GET', self::$url . 'player/qr.php?code=' . $j['data']['code']);
        $this->assertSame(200, $s);

        // An Android TV registering without device_type stays "android".
        [$s] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'android-tv-0001', 'room_number' => '402', 'registration_key' => 'TESTKEY123456789', 'app_version' => '2.4.0', 'app_version_code' => 11, 'android_version' => '11', 'model' => 'MiTV']);
        $this->assertSame(200, $s);
        $this->assertSame('android', DB::value('SELECT platform FROM devices WHERE device_uid = :u', ['u' => 'android-tv-0001']));
        $this->assertNull(DB::value('SELECT user_agent FROM devices WHERE device_uid = :u', ['u' => 'android-tv-0001']));
    }

    // ------------------------------------------------------------------ admin pages

    /** Runs after testWebDeviceRegistersPollsAndAcks (the devices it registered). */
    public function testRoomsPageShowsWebPlayers(): void
    {
        $s = new AdminSession(self::$url, 'wpMgr');
        [$code, , $html] = $s->get('rooms.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('data-web-player-link', $html);
        $this->assertStringContainsString('id="hcWebPlayerModal"', $html);
        $this->assertStringContainsString('value="' . e(self::$url . 'player/') . '"', $html);
        $this->assertStringContainsString('<svg', $html, 'QR code of the player address');
        $this->assertStringContainsString('bi-browser-chrome', $html);
        $this->assertMatchesRegularExpression('#<span class="badge text-bg-info"[^>]*><i class="bi bi-browser-chrome"></i> Web player</span> Samsung Internet 76 · Tizen 6.0#', $html);
        $this->assertStringContainsString('MiTV · Android 11', $html, 'Android TVs unchanged');

        $did = (int) DB::value('SELECT id FROM devices WHERE device_uid = :u', ['u' => self::$uid]);
        [$code, , $html] = $s->get('rooms.php?action=device&id=' . $did);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('Web player', $html);
        $this->assertStringContainsString(e(self::UA), $html);
        $this->assertStringContainsString('Operating system', $html);
        $aid = (int) DB::value('SELECT id FROM devices WHERE device_uid = :u', ['u' => 'android-tv-0001']);
        [, , $html] = $s->get('rooms.php?action=device&id=' . $aid);
        $this->assertStringContainsString('Android TV app', $html);
        $this->assertStringContainsString('Android version', $html);

        [$code, , $html] = $s->get('rooms.php?action=edit&id=' . self::$id['r401']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('bi-browser-chrome', $html);

        [$code, , $html] = $s->get('claim.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('data-web-player-link', $html);

        $this->assertSame('વેબ પ્લેયર લિંક', I18n::translate('Web player link', 'gu'));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $keys = WebPlayer::strings();
        $src = (string) file_get_contents(HC_ROOT . '/admin/partials/web_player_link.php');
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
        array_push($keys, ...array_map('stripslashes', $m[1]));
        array_push($keys, 'Web player', 'Platform', 'Android TV app', 'Operating system', 'User agent', 'Android version');
        $missing = [];
        foreach (['gu', 'hi'] as $l) {
            $table = I18n::table($l);
            foreach (array_unique($keys) as $k) {
                if (!isset($table[$k]) || $table[$k] === '') {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        // Placeholders survive translation.
        foreach (['gu', 'hi'] as $l) {
            $this->assertStringContainsString(':room', I18n::translate('Room :room', $l));
            $this->assertStringContainsString(':name', I18n::translate('Welcome to :name', $l));
        }
        $this->assertSame(count(WebPlayer::strings()), count(array_unique(WebPlayer::strings())), 'no duplicate keys');
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors());
    }
}
