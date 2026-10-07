<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Display apps framework (2.3): registry, content type 'app' (admin CRUD with CSRF / validation),
 * TV contract (url item with a signed /display/ URL), signature + tenancy checks, rendering of every
 * registered app in en / gu / hi without PHP warnings, live data JSON, the notice board (CRUD,
 * tenancy), XSS escaping and users with limited TV access.
 */
final class DisplayAppsTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'apMgr', 'staff' => 'apStaff', 'reception' => 'apRecep', 'super_admin' => 'apBoss'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101', '102'] as $n) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        self::$id['g1'] = DB::insert('room_groups', ['name' => 'G1', 'type' => 'custom', 'created_at' => now()]);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'apBoss2', 'email' => 'b2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2qr'] = DisplayAppsTestKit::createItem('qr', ['mode' => 'text', 'text' => 'H2-SECRET-QR'], [], 'H2-APP')['id'];
            self::$id['h2notice'] = DB::insert('notices', ['title' => 'H2-NOTICE-SECRET', 'category' => 'general', 'created_at' => now()]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        DisplayApps::reset();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Access::$userOverride = null;
        Access::forget();
        Settings::flush();
    }

    private static function row(int $id): ?array
    {
        return DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $id]);
    }

    /** Base form fields of an app item. */
    private static function form(string $app, array $cfg, array $extra = []): array
    {
        $f = $extra + ['op' => 'save', 'id' => 0, 'app' => $app, 'title' => 'My ' . $app, 'duration' => 20, 'is_active' => 1, 'theme' => 'diwali', 'font' => 'gujarati', 'lang' => 'gu'];
        foreach ($cfg as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $x) {
                    $f['cfg[' . $k . '][' . $i . ']'] = $x;
                }
            } else {
                $f['cfg[' . $k . ']'] = $v;
            }
        }
        return $f;
    }

    // ------------------------------------------------------------------ registry

    public function testRegistryDiscoversAppsAndHelpers(): void
    {
        $all = DisplayApps::all();
        foreach (['notice_board', 'countdown', 'qr'] as $k) {
            $this->assertArrayHasKey($k, $all, $k);
        }
        foreach ($all as $k => $app) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{2,40}$/', $k);
            $this->assertSame($k, $app->key());
            $this->assertArrayHasKey($app->category(), DisplayApp::CATEGORIES);
            $this->assertNotSame('', $app->label());
            $this->assertNotSame('', $app->description());
            $this->assertStringStartsWith('bi-', $app->icon());
            $this->assertIsArray($app->defaults());
            $this->assertNotSame('', $app->form($app->defaults()), $k . ' form');
            $this->assertGreaterThanOrEqual(0, $app->refreshSec($app->defaults()));
            [$cfg, $errors] = $app->validate([]);
            $this->assertIsArray($cfg);
            $this->assertIsArray($errors);
            $this->assertSame(array_keys($app->defaults()), array_keys($app->config($cfg)), $k . ': config() keeps every default key');
        }
        $this->assertSame(admin_url('notices.php'), $all['notice_board']->adminPage());
        $this->assertNull($all['qr']->adminPage());
        $cats = DisplayApps::byCategory();
        $this->assertSame(array_keys(DisplayApp::CATEGORIES), array_keys($cats));
        $this->assertArrayHasKey('qr', $cats['widget']);
        $this->assertArrayHasKey('notice_board', $cats['content']);

        // register() for apps outside core/Apps; invalid keys are skipped.
        $dummy = new class extends DisplayApp {
            public function key(): string { return 'zz_dummy'; }
            public function label(): string { return 'Dummy'; }
            public function description(): string { return 'Test'; }
            public function defaults(): array { return ['a' => 1]; }
            public function validate(array $in): array { return [['a' => self::int($in, 'a', 0, 9, 1)], []]; }
            public function form(array $config): string { return self::input('a', 'A', $config['a'], 'number'); }
            public function render(array $config, array $ctx): string { return '<p class="dummy">' . (int) $config['a'] . '</p>'; }
        };
        DisplayApps::register($dummy);
        $this->assertSame($dummy, DisplayApps::find('zz_dummy'));
        $this->assertStringContainsString('name="cfg[a]"', $dummy->form(['a' => 3]));
        $this->assertSame(['a' => 1, ], $dummy->config(['a' => 1, 'junk' => 5]), 'unknown keys dropped');
        DisplayApps::reset();
        $this->assertNull(DisplayApps::find('zz_dummy'));

        // Settings normalisation and themes.
        $n = DisplayApps::normalize(['app' => 'qr', 'theme' => 'nope', 'font' => 'x', 'accent' => 'red', 'lang' => 'fr']);
        $this->assertSame(['app' => 'qr', 'config' => [], 'theme' => 'classic_dark', 'font' => 'auto', 'accent' => null, 'lang' => 'auto'], $n);
        $this->assertCount(12, DisplayApps::THEMES);
        foreach (DisplayApps::THEMES as $tk => $t) {
            foreach (['label', 'bg', 'bg2', 'fg', 'muted', 'accent', 'card', 'card_fg', 'border', 'heading'] as $v) {
                $this->assertArrayHasKey($v, $t, $tk . '.' . $v);
            }
        }
        $t = DisplayApps::theme(['theme' => 'light', 'accent' => '#ff0000', 'font' => 'hindi']);
        $this->assertSame('#FF0000', $t['accent']);
        $this->assertSame('#FFFFFF', $t['accent_fg']);
        $this->assertStringStartsWith('"Noto Sans Devanagari"', $t['font']);
        $css = DisplayApps::themeCss($t);
        $this->assertStringContainsString('--hc-accent:#FF0000;', $css);
        $this->assertStringContainsString('--hc-bg:#EEF2F7;', $css);
    }

    public function testQrPayloadsCountdownAndValidation(): void
    {
        $qr = DisplayApps::find('qr');
        [$c, $e] = $qr->validate(['mode' => 'upi', 'upi_pa' => 'hotel.krishna@okaxis', 'upi_pn' => 'Hotel Krishna', 'upi_am' => '499.50', 'upi_tn' => 'Room 101']);
        $this->assertSame([], $e);
        $this->assertSame('upi://pay?pa=hotel.krishna%40okaxis&pn=Hotel%20Krishna&am=499.50&cu=INR&tn=Room%20101', QrApp::payload($c));
        [$c, $e] = $qr->validate(['mode' => 'whatsapp', 'wa_phone' => '098765 43210', 'wa_text' => 'Hi & hello']);
        $this->assertSame([], $e);
        $this->assertSame('https://wa.me/919876543210?text=Hi%20%26%20hello', QrApp::payload($c));
        [$c, $e] = $qr->validate(['mode' => 'wifi', 'wifi_ssid' => 'Hotel;Guest', 'wifi_pass' => 'p:a"ss', 'wifi_enc' => 'WPA', 'wifi_hidden' => '1']);
        $this->assertSame([], $e);
        $this->assertSame('WIFI:T:WPA;S:Hotel\\;Guest;P:p\\:a\\"ss;H:true;;', QrApp::payload($c));
        [$c] = $qr->validate(['mode' => 'wifi', 'wifi_ssid' => 'Open', 'wifi_enc' => 'nopass']);
        $this->assertSame('WIFI:T:nopass;S:Open;;', QrApp::payload($c));
        foreach ([
            [['mode' => 'url', 'url' => 'javascript:alert(1)'], 'valid http(s) address'],
            [['mode' => 'url'], 'Enter the web link'],
            [['mode' => 'upi', 'upi_pa' => 'not-a-vpa'], 'valid UPI ID'],
            [['mode' => 'upi', 'upi_pa' => 'a.b@okaxis', 'upi_am' => '-5'], 'amount'],
            [['mode' => 'whatsapp', 'wa_phone' => '123'], 'WhatsApp number'],
            [['mode' => 'text', 'text' => ' '], 'Enter the text'],
            [['mode' => 'text', 'text' => str_repeat('x', 600) . 'ઘ'], ''],
            [['mode' => 'wifi', 'wifi_ssid' => 'X', 'wifi_enc' => 'WPA'], 'Wi-Fi password'],
            [['mode' => 'text', 'text' => 'ok', 'dark' => '#000000', 'light' => '#000000'], 'colours must be different'],
        ] as [$in, $needle]) {
            [, $e] = $qr->validate($in);
            if ($needle === '') {
                $this->assertSame([], $e, 'long text still fits');
                continue;
            }
            $this->assertNotEmpty($e, $needle);
            $this->assertStringContainsString($needle, implode(' ', $e));
        }

        $this->assertSame([1, 2, 3, 4], CountdownApp::parts(1000 + 86400 + 2 * 3600 + 3 * 60 + 4, 1000));
        $this->assertSame([0, 0, 0, 0], CountdownApp::parts(10, 20));
        $cd = DisplayApps::find('countdown');
        [$c, $e] = $cd->validate(['title' => 'Opening', 'target' => '2030-01-02T10:30', 'show_seconds' => '1', 'bg_image' => '99999']);
        $this->assertSame([], $e);
        $this->assertSame(['2030-01-02 10:30:00', true, 0], [$c['target'], $c['show_seconds'], $c['bg_image']]);
        [, $e] = $cd->validate(['title' => '', 'target' => 'nonsense']);
        $this->assertCount(2, $e);

        // After zero the message shows, the boxes are hidden.
        $item = DisplayAppsTestKit::createItem('countdown', ['title' => 'Past', 'target' => '2020-01-01 00:00:00', 'done_message' => 'DONE-MSG']);
        $html = DisplayAppsTestKit::renderInProcess($item);
        $this->assertMatchesRegularExpression('/class="cd-boxes" style="display:none"/', $html);
        $this->assertStringContainsString('<div class="cd-done">DONE-MSG</div>', $html);
        $this->assertStringContainsString('data-target="' . (strtotime('2020-01-01 00:00:00') * 1000) . '"', $html);
        ContentManager::deleteItem((int) $item['id']);
    }

    // ------------------------------------------------------------------ admin CRUD

    public function testCreateEditDeleteAppItemOverHttp(): void
    {
        $s = new AdminSession(self::$url, 'apMgr');
        [$code, , $html] = $s->get('apps.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach (['data-app="notice_board"', 'data-app="countdown"', 'data-app="qr"', 'apps.php?action=new&amp;app=qr', 'notices.php', 'Open management page'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertMatchesRegularExpression('#href="[^"]*admin/apps\.php"#', $html, 'sidebar entry');
        $this->assertLessThan(strpos($html, 'playlists.php'), strpos($html, 'admin/apps.php"'), 'Apps right after the Content Library');

        [$code, , $html] = $s->get('apps.php?action=new&app=qr');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach (['name="title"', 'name="duration"', 'name="cfg[mode]"', 'name="cfg[upi_pa]"', 'name="theme"', 'value="navratri"', 'name="font"', 'name="lang"', 'name="accent_on"', 'id="appPreview"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        [$code] = $s->get('apps.php?action=new&app=nope');
        $this->assertSame(302, $code);

        // CSRF.
        $before = (int) DB::value("SELECT COUNT(*) FROM content_items WHERE type = 'app'");
        [$code] = TestEnv::http('POST', self::$url . 'admin/apps.php', null, [], $s->jar, self::form('qr', ['mode' => 'text', 'text' => 'x']));
        $this->assertSame(419, $code);
        // Validation: nothing saved, the form comes back with the errors.
        [$code, , $html] = $s->post('apps.php', ['title' => ''] + self::form('qr', ['mode' => 'url', 'url' => 'ftp://x']));
        $this->assertSame(422, $code);
        $this->assertStringContainsString(e('Title is required'), $html);
        $this->assertStringContainsString('valid http(s) address', $html);
        $this->assertStringContainsString('id="appForm"', $html);
        [$code] = $s->post('apps.php', self::form('unknown_app', []));
        $this->assertSame(422, $code);
        $this->assertSame($before, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE type = 'app'"));

        // Create.
        [$code, , , $head] = $s->post('apps.php', self::form('qr', ['mode' => 'upi', 'upi_pa' => 'shop@okaxis', 'upi_pn' => 'Shop', 'upi_am' => '100', 'title' => 'Pay here', 'caption' => 'UPI'], ['accent_on' => 1, 'accent' => '#00ff00']));
        $this->assertSame(302, $code);
        $id = (int) DB::value("SELECT MAX(id) FROM content_items WHERE type = 'app'");
        $this->assertStringContainsString('apps.php?action=edit&id=' . $id, $head);
        $row = self::row($id);
        $this->assertSame([1, 'My qr', 20, 1, self::$id['apMgr']], [(int) $row['hotel_id'], $row['title'], (int) $row['duration'], (int) $row['is_active'], (int) $row['created_by']]);
        $st = json_decode($row['settings'], true);
        $this->assertSame(['app' => 'qr', 'theme' => 'diwali', 'font' => 'gujarati', 'accent' => '#00FF00', 'lang' => 'gu'], array_diff_key($st, ['config' => 1]));
        $this->assertSame(['upi', 'shop@okaxis', '100', 'Pay here'], [$st['config']['mode'], $st['config']['upi_pa'], $st['config']['upi_am'], $st['config']['title']]);

        // Edit page with the live preview of the signed URL; the library and its edit link lead here.
        [$code, , $html] = $s->get('apps.php?action=edit&id=' . $id);
        $this->assertSame(200, $code);
        $this->assertStringContainsString(e(DisplayAppsTestKit::rel(DisplayApps::displayUrl($row, ['preview' => 1]))) . '"', $html);
        $this->assertStringContainsString('value="shop@okaxis"', $html);
        [, , $html] = $s->get('content.php');
        $this->assertStringContainsString('My qr', $html);
        $this->assertStringContainsString('apps.php', $html, '"Add content" → Display app goes to the gallery');
        [$code, , , $head] = $s->get('content.php?action=edit&id=' . $id);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('apps.php?action=edit&id=' . $id, $head);
        [$code, , , $head] = $s->get('content.php?action=new&type=app');
        $this->assertSame(302, $code);
        $this->assertStringContainsString('apps.php', $head);
        // Content pickers (broadcast, playlists, rooms) list it.
        [$code, , $html] = $s->get('broadcast.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('My qr', $html);

        // Draft preview (unsaved form values) renders the page.
        [$code, , $html] = $s->post('apps.php', ['op' => 'preview'] + self::form('qr', ['mode' => 'text', 'text' => 'DRAFT', 'title' => 'DRAFT-TITLE']));
        $this->assertSame(200, $code);
        $this->assertStringContainsString('hc-app-qr', $html);
        $this->assertStringContainsString('DRAFT-TITLE', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('"data_url":null', $html, 'drafts do not poll');

        // Edit (the app key cannot be changed).
        $v1 = DisplayApps::displayUrl($row);
        [$code] = $s->post('apps.php', ['id' => $id, 'title' => 'Renamed'] + self::form('countdown', ['mode' => 'text', 'text' => 'Now text'], ['theme' => 'holi', 'lang' => 'hi']));
        $this->assertSame(302, $code);
        $row = self::row($id);
        $st = json_decode($row['settings'], true);
        $this->assertSame(['Renamed', 'qr', 'holi', null, 'hi', 'text'], [$row['title'], $st['app'], $st['theme'], $st['accent'], $st['lang'], $st['config']['mode']]);
        $this->assertNotSame($v1, DisplayApps::displayUrl($row), 'URL revision changes after an edit');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE id = :id AND type = 'app'", ['id' => $id]), 'updated in place');

        // Delete (CSRF required).
        [$code] = TestEnv::http('POST', self::$url . 'admin/apps.php', null, [], $s->jar, ['op' => 'delete', 'id' => (string) $id]);
        $this->assertSame(419, $code);
        $this->assertNotNull(self::row($id));
        [$code] = $s->post('apps.php', ['op' => 'delete', 'id' => $id]);
        $this->assertSame(302, $code);
        $this->assertNull(self::row($id));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'content_delete' AND entity_id = :id", ['id' => $id]));
        [$code] = $s->get('apps.php?action=edit&id=' . $id);
        $this->assertSame(302, $code);

        // Another hotel's item: 404.
        foreach ([['op' => 'save', 'id' => self::$id['h2qr'], 'title' => 'HACK'] + self::form('qr', ['mode' => 'text', 'text' => 'x']), ['op' => 'delete', 'id' => self::$id['h2qr']]] as $p) {
            [$code] = $s->post('apps.php', $p);
            $this->assertSame(404, $code);
        }
        [$code] = $s->get('apps.php?action=edit&id=' . self::$id['h2qr']);
        $this->assertSame(404, $code);
        $this->assertSame('H2-APP', Tenant::run(2, fn () => DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => self::$id['h2qr']])));

        // Staff / reception (no content.manage): 403, no menu entry.
        $r = new AdminSession(self::$url, 'apRecep');
        [$code] = $r->get('apps.php');
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ TV contract & signatures

    public function testTvPollGetsSignedUrlItem(): void
    {
        $item = DisplayAppsTestKit::createItem('qr', ['mode' => 'text', 'text' => 'TV-QR', 'title' => 'TV-TITLE']);
        DB::update('rooms', ['content_id' => $item['id']], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $key = (string) Settings::get('registration_key');
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-apps-101-0001', 'room_number' => '101', 'registration_key' => $key]);
        $this->assertSame(200, $st);
        $token = $j['data']['token'];
        $poll = static function () use ($token): array {
            [, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, ['Authorization: Bearer ' . $token, 'X-Device-Id: tv-apps-101-0001']);
            return $j['data']['content'];
        };
        $c = $poll();
        $this->assertSame('assigned', $c['mode']);
        $tv = $c['items'][0];
        $this->assertSame('url', $tv['type'], 'TV apps since 2.0 show it as a web page');
        $this->assertSame('qr', $tv['app']);
        $this->assertSame(0, $tv['refresh_sec']);
        $this->assertSame(0, $tv['duration']);
        $sig = DisplayApps::signature(1, (int) $item['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $sig);
        $this->assertSame(substr(hash_hmac('sha256', 'display:1:' . $item['id'], str_repeat('ab12', 16)), 0, 32), $sig);
        $this->assertSame(self::$url . 'display/?c=' . $item['id'] . '&s=' . $sig . '&v=' . DisplayApps::rev($item), $tv['url']);
        [$code, , $html] = TestEnv::http('GET', $tv['url']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('TV-TITLE', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('assets/display/app.js', $html);
        $this->assertStringContainsString('assets/display/apps/qr.css', $html);
        $this->assertStringContainsString('--hc-bg:', $html);

        // Editing changes the URL and the content hash; the TV reloads.
        $h1 = $c['hash'];
        $s = json_decode($item['settings'], true);
        $s['theme'] = 'eid';
        DB::update('content_items', ['settings' => json_out($s)], 'id = :id', ['id' => $item['id']]);
        Settings::bumpContentVersion();
        $c = $poll();
        $this->assertNotSame($h1, $c['hash']);
        $this->assertNotSame($tv['url'], $c['items'][0]['url']);
        // Playlist items too, with their duration.
        $pl = DB::insert('content_playlists', ['name' => 'PL', 'transition' => 'fade', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $item['id'], 'sort_order' => 1, 'duration' => 45]);
        DB::update('rooms', ['content_id' => null, 'playlist_id' => $pl], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $c = $poll();
        $this->assertSame(['url', 45, 'qr'], [$c['items'][0]['type'], $c['items'][0]['duration'], $c['items'][0]['app']]);
        // TV simulator renders 'url' items in an iframe.
        $m = new AdminSession(self::$url, 'apMgr');
        [$code, , $html] = $m->get('preview.php?content_id=' . $item['id']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('display/?c=' . $item['id'] . '\u0026s=' . $sig, $html);
        DB::update('rooms', ['playlist_id' => null], 'id = :id', ['id' => self::$id['r101']]);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testSignatureAndTenancyChecks(): void
    {
        $item = DisplayAppsTestKit::createItem('qr', ['mode' => 'text', 'text' => 'SIG-SECRET', 'title' => 'SIG-SECRET']);
        $id = (int) $item['id'];
        $sig = DisplayApps::signature(1, $id);
        $get = static fn (string $q): array => TestEnv::http('GET', self::$url . 'display/' . $q);
        [$code, , $html] = $get('?c=' . $id . '&s=' . $sig);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('SIG-SECRET', $html);
        $this->assertTrue(DisplayApps::verify(1, $id, $sig));
        $this->assertFalse(DisplayApps::verify(2, $id, $sig), 'bound to the hotel');
        $bad = [
            '?c=' . $id . '&s=' . str_repeat('0', 32),
            '?c=' . $id . '&s=' . substr($sig, 0, 31),
            '?c=' . $id,
            '?c=' . ($id + 1000) . '&s=' . $sig,
            '?c=abc&s=' . $sig,
            '?c[]=1&s=' . $sig,
            '',
            // Hotel 2's item with a signature made for hotel 1, or hotel 1's id signature reused.
            '?c=' . self::$id['h2qr'] . '&s=' . DisplayApps::signature(1, self::$id['h2qr']),
            '?c=' . self::$id['h2qr'] . '&s=' . $sig,
        ];
        foreach ($bad as $q) {
            [$code, , $html] = $get($q);
            $this->assertSame(404, $code, $q);
            $this->assertStringNotContainsString('SIG-SECRET', $html);
            $this->assertStringNotContainsString('H2-SECRET-QR', $html);
            $this->assertFalse(TestEnv::hasPhpError($html), $q);
            [$code, $json] = TestEnv::http('GET', self::$url . 'display/data.php' . $q);
            $this->assertSame(404, $code, 'data ' . $q);
            $this->assertSame('NOT_FOUND', $json['error']['code']);
        }
        // Hotel 2's own signature works for hotel 2's item only.
        [$code, , $html] = $get('?c=' . self::$id['h2qr'] . '&s=' . DisplayApps::signature(2, self::$id['h2qr']));
        $this->assertSame(200, $code);
        $this->assertStringContainsString('<title>H2-APP</title>', $html);

        // A non-app item never renders, even with a valid signature.
        $ann = DB::insert('content_items', ['title' => 'ANN-SECRET', 'type' => 'announcement', 'body' => 'x', 'duration' => 10]);
        [$code, , $html] = $get('?c=' . $ann . '&s=' . DisplayApps::signature(1, $ann));
        $this->assertSame(404, $code);
        $this->assertStringNotContainsString('ANN-SECRET', $html);

        // Suspended hotel: neutral page / 403 JSON, no content.
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        try {
            [$code, , $html] = $get('?c=' . $id . '&s=' . $sig);
            $this->assertSame(403, $code);
            $this->assertStringNotContainsString('SIG-SECRET', $html);
            $this->assertStringContainsString('Service paused', $html);
            [$code, $json] = TestEnv::http('GET', self::$url . 'display/data.php?c=' . $id . '&s=' . $sig);
            $this->assertSame([403, 'SUSPENDED'], [$code, $json['error']['code']]);
        } finally {
            DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
            Tenant::forget();
        }
        // Deleted item → 404.
        ContentManager::deleteItem($id);
        [$code] = $get('?c=' . $id . '&s=' . $sig);
        $this->assertSame(404, $code);
        // The display folder is reachable (not blocked like core/ or lang/).
        [$code] = TestEnv::http('GET', self::$url . 'display');
        $this->assertSame(302, $code);
        [$code] = TestEnv::http('GET', self::$url . 'assets/fonts/noto-sans-gujarati-gujarati-400-normal.woff2');
        $this->assertSame(200, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ rendering

    public function testEveryRegisteredAppRendersInEveryLanguage(): void
    {
        $words = ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी'];
        foreach (DisplayApps::all() as $key => $app) {
            foreach (['en', 'gu', 'hi'] as $lang) {
                $item = DisplayAppsTestKit::createItem($key, [], ['lang' => $lang, 'theme' => array_keys(DisplayApps::THEMES)[crc32($key . $lang) % 12]], $key . '-' . $lang);
                $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
                $this->assertStringContainsString('<html lang="' . $lang . '"', $html, $key . ' ' . $lang);
                $this->assertStringContainsString('"lang":"' . $lang . '"', $html);
                DisplayAppsTestKit::assertRenders($this, self::$url, $item, ['preview' => 1]);
                [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
                $this->assertSame(200, $code, $key . ' data');
                $this->assertTrue($json['ok']);
                $this->assertSame(['ok', 'rev', 'server_now', 'refresh_sec', 'data'], array_keys($json));
                $this->assertSame(DisplayApps::rev($item), $json['rev']);
                $this->assertSame($app->refreshSec($app->defaults()), $json['refresh_sec']);
                $this->assertEqualsWithDelta(microtime(true) * 1000, $json['server_now'], 60000);
                // In-process rendering (preview) gives the same document shape and restores the admin language.
                I18n::setLang('en');
                $doc = DisplayAppsTestKit::renderInProcess($item, true);
                $this->assertStringContainsString('hc-app-' . $key, $doc);
                $this->assertSame('en', I18n::lang());
                ContentManager::deleteItem((int) $item['id']);
            }
        }
        // TV texts follow the item language.
        $item = DisplayAppsTestKit::createItem('countdown', [], ['lang' => 'gu']);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString(I18n::translate('Days', 'gu'), $html);
        $this->assertNotSame('Days', I18n::translate('Days', 'gu'));
        $this->assertNotSame('Days', I18n::translate('Days', 'hi'));
        $this->assertStringContainsString('"Noto Sans Gujarati"', $html, 'auto font for Gujarati');
        $this->assertNotEmpty($words);
        ContentManager::deleteItem((int) $item['id']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testNoticeBoardDataJsonAndRendering(): void
    {
        DB::query('DELETE FROM notices WHERE hotel_id = 1');
        $item = DisplayAppsTestKit::createItem('notice_board', ['categories' => ['exam', 'holiday'], 'layout' => 'list']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertSame([], $json['data']['notices']);
        $this->assertSame(60, $json['refresh_sec']);
        $this->assertSame('list', $json['data']['layout']);
        // Preview without notices shows sample notices; the TV shows the empty text.
        [, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
        $this->assertStringContainsString('nb-card', $html);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString('No notices right now.', $html);

        $today = date('Y-m-d');
        $ins = static fn (array $r): int => DB::insert('notices', $r + ['body' => '', 'category' => 'exam', 'created_at' => now()]);
        $ins(['title' => 'EXAM-LOW', 'priority' => 0]);
        $ins(['title' => 'EXAM-HIGH', 'priority' => 5, 'starts_on' => $today, 'ends_on' => $today]);
        $ins(['title' => 'HOLIDAY', 'category' => 'holiday', 'body' => "Line 1\nLine 2"]);
        $ins(['title' => 'RESULT-HIDDEN-BY-CATEGORY', 'category' => 'result']);
        $ins(['title' => 'FUTURE', 'starts_on' => date('Y-m-d', strtotime('+2 days'))]);
        $ins(['title' => 'PAST', 'ends_on' => date('Y-m-d', strtotime('-1 day'))]);
        $ins(['title' => 'OFF', 'is_active' => 0]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(['EXAM-HIGH', 'HOLIDAY', 'EXAM-LOW'], array_column($json['data']['notices'], 'title'));
        $n = $json['data']['notices'][0];
        $this->assertSame(['exam', 'Exam', '#C62828'], [$n['category'], $n['label'], $n['color']]);
        $this->assertSame(date('j') . ' ' . date('F') . ' ' . date('Y'), $n['date']);
        $this->assertStringNotContainsString('H2-NOTICE-SECRET', json_encode($json));
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString('Line 1<br>' . "\n" . 'Line 2', $html);
        $this->assertStringContainsString('class="nb-list"', $html);
        // All categories when none is ticked; Gujarati labels.
        $all = DisplayAppsTestKit::createItem('notice_board', ['categories' => []], ['lang' => 'gu']);
        [, $json] = DisplayAppsTestKit::data(self::$url, $all);
        $this->assertContains('RESULT-HIDDEN-BY-CATEGORY', array_column($json['data']['notices'], 'title'));
        $this->assertSame(I18n::translate('Exam', 'gu'), $json['data']['notices'][0]['label']);
        $this->assertNotSame('Exam', $json['data']['notices'][0]['label']);
        // Optional timetable from the content library, beside the notices.
        $tt = DB::insert('content_items', ['title' => 'Classes', 'type' => 'timetable', 'duration' => 10, 'body' => json_out(['heading' => 'TT-HEAD', 'rows' => [['09:00', 'Maths']]])]);
        $nb = DisplayApps::find('notice_board');
        [$cfg, $e] = $nb->validate(['timetable_id' => (string) $tt, 'categories' => ['exam', 'bogus']]);
        $this->assertSame([], $e);
        $this->assertSame([$tt, ['exam']], [$cfg['timetable_id'], $cfg['categories']]);
        [, $e] = $nb->validate(['timetable_id' => (string) $item['id']]);
        $this->assertNotEmpty($e, 'not a timetable');
        $withTt = DisplayAppsTestKit::createItem('notice_board', ['timetable_id' => $tt]);
        [, $html] = DisplayAppsTestKit::page(self::$url, $withTt);
        $this->assertStringContainsString('class="nb-tt"', $html);
        $this->assertStringContainsString('TT-HEAD', $html);
        DB::query('DELETE FROM notices WHERE hotel_id = 1');
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ notices admin

    public function testNoticesCrudUploadTenancyAndXss(): void
    {
        DB::query('DELETE FROM notices WHERE hotel_id = 1');
        $s = new AdminSession(self::$url, 'apStaff');
        [$code, , $html] = $s->get('notices.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('H2-NOTICE-SECRET', $html);
        [$code, , $html] = $s->get('notices.php?action=new');
        $this->assertSame(200, $code);
        foreach (['name="title"', 'name="body"', 'name="category"', 'name="starts_on"', 'name="ends_on"', 'name="priority"', 'name="image"', 'name="is_active"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Validation.
        foreach ([
            [['title' => ' '], 'The notice title is required.'],
            [['title' => 'x', 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-01'], 'The end date must be on or after the start date.'],
            [['title' => 'x', 'starts_on' => '31-12-2026'], 'Invalid date'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('notices.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
        }
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notices WHERE hotel_id = 1'));
        // CSRF.
        [$code] = TestEnv::http('POST', self::$url . 'admin/notices.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'title' => 'NO-CSRF']);
        $this->assertSame(419, $code);

        // Create with an image upload and XSS in title / text.
        $png = tempnam(sys_get_temp_dir(), 'nimg') . '.png';
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        imagepng($im, $png);
        [$code] = TestEnv::http('POST', self::$url . 'admin/notices.php', null, [], $s->jar, [
            '_csrf' => $s->csrf, 'op' => 'save', 'id' => '0', 'title' => 'પરીક્ષા ' . self::XSS, 'body' => "Body " . self::XSS, 'category' => 'exam',
            'starts_on' => date('Y-m-d'), 'ends_on' => '', 'priority' => '3', 'is_active' => '1', 'image' => new CURLFile($png, 'image/png', 'notice.png'),
        ]);
        @unlink($png);
        $this->assertSame(302, $code);
        $n = DB::one('SELECT * FROM notices WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['પરીક્ષા ' . self::XSS, 'exam', date('Y-m-d'), null, 3, 1, self::$id['apStaff']], [$n['title'], $n['category'], $n['starts_on'], $n['ends_on'], (int) $n['priority'], (int) $n['is_active'], (int) $n['created_by']]);
        $this->assertNotEmpty($n['image_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $n['image_path']);
        [, , $html] = $s->get('notices.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        // On the TV page too (server render and JSON for the JS renderer).
        $board = DisplayAppsTestKit::createItem('notice_board', [], [], 'Board ' . self::XSS);
        [, $html] = DisplayAppsTestKit::page(self::$url, $board);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('પરીક્ષા &lt;script&gt;', $html);
        $this->assertStringContainsString('<title>Board &lt;script&gt;', $html);
        $this->assertStringContainsString('/uploads/' . $n['image_path'], $html);
        $this->assertStringContainsString('"title":"પરીક્ષા \u003Cscript\u003E', $html, 'boot JSON escapes < >');

        // Edit (remove the image), toggle, delete.
        [$code] = $s->post('notices.php', ['op' => 'save', 'id' => $n['id'], 'title' => 'Edited', 'body' => '', 'category' => 'event', 'priority' => 0, 'is_active' => 1, 'remove_image' => 1]);
        $this->assertSame(302, $code);
        $e = DB::one('SELECT * FROM notices WHERE id = :id', ['id' => $n['id']]);
        $this->assertSame(['Edited', 'event', null], [$e['title'], $e['category'], $e['image_path']]);
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $n['image_path']);
        $s->post('notices.php', ['op' => 'toggle', 'id' => $n['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM notices WHERE id = :id', ['id' => $n['id']]));
        [, $json] = DisplayAppsTestKit::data(self::$url, $board);
        $this->assertSame([], $json['data']['notices'], 'switched-off notices leave the TV');

        // Hotel 2 cannot see, edit, toggle or delete hotel 1 notices (404) and vice versa.
        $b = new AdminSession(self::$url, 'apBoss2');
        [, , $html] = $b->get('notices.php');
        $this->assertStringContainsString('H2-NOTICE-SECRET', $html);
        $this->assertStringNotContainsString('Edited', $html);
        [$code] = $b->get('notices.php?action=edit&id=' . $n['id']);
        $this->assertSame(404, $code);
        foreach ([['op' => 'save', 'id' => $n['id'], 'title' => 'HACKED'], ['op' => 'toggle', 'id' => $n['id']], ['op' => 'delete', 'id' => $n['id']]] as $p) {
            [$code] = $b->post('notices.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        $this->assertSame('Edited', DB::value('SELECT title FROM notices WHERE id = :id', ['id' => $n['id']]));
        [$code] = $s->post('notices.php', ['op' => 'delete', 'id' => self::$id['h2notice']]);
        $this->assertSame(404, $code);
        $this->assertTrue(Tenant::run(2, fn () => (bool) Notices::find(self::$id['h2notice'])));
        $this->assertNull(Notices::find(0));

        $s->post('notices.php', ['op' => 'delete', 'id' => $n['id']]);
        $this->assertNull(DB::one('SELECT id FROM notices WHERE id = :id', ['id' => $n['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'notice_delete' AND entity_id = :id", ['id' => $n['id']]));
        // Reception may not manage notices.
        [$code] = (new AdminSession(self::$url, 'apRecep'))->get('notices.php');
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testXssInAppTitlesAndConfig(): void
    {
        $item = DisplayAppsTestKit::createItem('qr', ['mode' => 'text', 'text' => self::XSS, 'title' => self::XSS, 'caption' => self::XSS], ['accent' => '#123456'], 'T' . self::XSS);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $cd = DisplayAppsTestKit::createItem('countdown', ['title' => self::XSS, 'subtitle' => self::XSS, 'done_message' => self::XSS]);
        [, $html] = DisplayAppsTestKit::page(self::$url, $cd);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        // Tampered settings (accent / theme / font injected into CSS) are cleaned.
        DB::update('content_items', ['settings' => json_out(['app' => 'qr', 'config' => ['mode' => 'text', 'text' => 'x'], 'theme' => '</style><script>alert(3)</script>', 'font' => '}</style>', 'accent' => 'red;}</style><script>alert(4)</script>'])], 'id = :id', ['id' => $item['id']]);
        [$code, $html] = DisplayAppsTestKit::page(self::$url, self::row((int) $item['id']));
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('alert(3)', $html);
        $this->assertStringNotContainsString('alert(4)', $html);
        $this->assertStringContainsString('hc-theme-classic_dark', $html);
        $s = new AdminSession(self::$url, 'apMgr');
        [, , $html] = $s->get('apps.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('T&lt;script&gt;', $html);
        [, , $html] = $s->get('apps.php?action=edit&id=' . $item['id']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ users with limited TV access

    public function testRestrictedUsersStillWork(): void
    {
        // A manager limited to room 102 / group 1 (Access) still manages hotel-wide app screens and notices.
        $uid = self::$id['apMgr'];
        DB::insert('user_access', ['user_id' => $uid, 'target_type' => 'room', 'target_id' => self::$id['r102'], 'created_at' => now()]);
        DB::insert('user_access', ['user_id' => self::$id['apStaff'], 'target_type' => 'group', 'target_id' => self::$id['g1'], 'created_at' => now()]);
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $uid]);
        Access::forget();
        $this->assertTrue(Access::restricted());
        Access::$userOverride = null;

        $s = new AdminSession(self::$url, 'apMgr');
        [$code, , $html] = $s->get('apps.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$code] = $s->post('apps.php', self::form('countdown', ['title' => 'Limited', 'target' => '2031-01-01T10:00', 'show_seconds' => 1]));
        $this->assertSame(302, $code);
        $id = (int) DB::value("SELECT MAX(id) FROM content_items WHERE type = 'app'");
        $this->assertSame('Limited', json_decode((string) self::row($id)['settings'], true)['config']['title']);
        [$code, , $html] = $s->get('apps.php?action=edit&id=' . $id);
        $this->assertSame(200, $code);
        $st = new AdminSession(self::$url, 'apStaff');
        [$code] = $st->post('notices.php', ['op' => 'save', 'id' => 0, 'title' => 'Limited staff notice', 'category' => 'general', 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code] = $st->get('apps.php');
        $this->assertSame(403, $code, 'staff: no content.manage');
        DB::query('DELETE FROM user_access');
        $this->assertSame('', TestEnv::phpErrors());
    }
}
