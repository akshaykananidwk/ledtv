<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Content display apps (docs/modules/content_apps.md): showcase (#9), event welcome (#10), photo
 * album (#19, admin/album_upload.php + public guest link album/), Google Sheet table (#14, Http mocks)
 * and social wall (#16). Rendering in en / gu / hi without warnings, validation (allowed hosts only,
 * XSS escaped), album upload flow, guest link signature / rate limit / moderation, tenancy, data JSON.
 */
final class ContentAppsTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const APPS = ['showcase', 'event_welcome', 'photo_album', 'sheet_table', 'social_wall'];
    private const PUB = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?output=csv';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'caMgr', 'staff' => 'caStaff', 'reception' => 'caRecep'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        self::$id['img1'] = DB::insert('content_items', ['title' => 'Lobby', 'type' => 'image', 'file_path' => 'h1/media/2026/10/lobby.jpg', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        self::$id['img2'] = DB::insert('content_items', ['title' => 'Pool', 'type' => 'image', 'file_path' => 'h1/media/2026/10/pool.jpg', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'caBoss2', 'email' => 'cb2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2img'] = DB::insert('content_items', ['title' => 'H2 image', 'type' => 'image', 'file_path' => 'h2/media/x.jpg', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
            self::$id['h2album'] = Albums::create(['name' => 'H2-ALBUM-SECRET', 'guest_upload' => 1, 'guest_moderation' => 0, 'guest_max' => 10]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        Cache::clear();
        self::$mockFile = HC_ROOT . '/storage/http_mock_content_apps.json';
        file_put_contents(self::$mockFile, '{}');
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url, ['http_mock_file' => self::$mockFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Http::$mock = null;
        Access::$userOverride = null;
        Access::forget();
        DisplayApps::reset();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
        @unlink(self::$mockFile);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Http::$mock = null;
        Access::$userOverride = null;
        Access::forget();
        Settings::flush();
        I18n::setLang('en');
    }

    private static function jpeg(int $w = 64, int $h = 48): string
    {
        $f = tempnam(sys_get_temp_dir(), 'caimg') . '.jpg';
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        imagejpeg($im, $f, 80);
        return $f;
    }

    private static function ajaxHeaders(AdminSession $s): array
    {
        return ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'X-CSRF-Token: ' . $s->csrf];
    }

    private static function upload(AdminSession $s, int $album, string $file, string $name, string $mime = 'image/jpeg', array $extra = []): array
    {
        return TestEnv::http('POST', self::$url . 'admin/album_upload.php', null, self::ajaxHeaders($s), $s->jar, $extra + [
            '_csrf' => $s->csrf, 'op' => 'upload', 'album_id' => (string) $album, 'photo' => new CURLFile($file, $mime, $name),
        ]);
    }

    private static function guestPost(string $guestUrl, string $file, string $name, array $extra = []): array
    {
        return TestEnv::http('POST', self::$url . DisplayAppsTestKit::rel($guestUrl), null, ['X-Requested-With: XMLHttpRequest'], null, $extra + [
            'op' => 'upload', 'photo' => new CURLFile($file, 'image/jpeg', $name),
        ]);
    }

    private static function assertNoXss(TestCase $t, string $html, string $what = ''): void
    {
        $t->assertStringNotContainsString('<script>alert(1)', $html, $what);
        $t->assertStringNotContainsString('<img src=x', $html, $what);
    }

    // ------------------------------------------------------------------ registry, rendering, translations

    public function testAppsRenderWithDefaultsInEveryLanguage(): void
    {
        foreach (self::APPS as $key) {
            $app = DisplayApps::find($key);
            $this->assertNotNull($app, $key);
            $this->assertSame(array_keys($app->defaults()), array_keys($app->config($app->validate([])[0])), $key);
            $this->assertNotSame('', $app->form($app->defaults()));
            foreach (['en', 'gu', 'hi'] as $lang) {
                $item = DisplayAppsTestKit::createItem($key, [], ['lang' => $lang, 'theme' => $lang === 'gu' ? 'wedding' : 'diwali'], $key . '-' . $lang);
                $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
                $this->assertStringContainsString('<html lang="' . $lang . '"', $html);
                DisplayAppsTestKit::assertRenders($this, self::$url, $item, ['preview' => 1]);
                [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
                $this->assertSame(200, $code, $key . ' data');
                $this->assertTrue($json['ok']);
                $this->assertIsArray($json['data'], $key);
                $this->assertSame($app->refreshSec($app->defaults()), $json['refresh_sec']);
                ContentManager::deleteItem((int) $item['id']);
            }
        }
        $this->assertSame(admin_url('album_upload.php'), DisplayApps::find('photo_album')->adminPage());
        $this->assertSame(30, DisplayApps::find('photo_album')->refreshSec([]));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = array_merge(array_values(SheetFeed::ERRORS), array_values(SheetTableApp::FONTS));
        foreach (['core/Albums.php', 'core/ContentApps.php', 'core/SheetFeed.php', 'core/Apps/PhotoAlbumApp.php', 'core/Apps/ShowcaseApp.php', 'core/Apps/EventWelcomeApp.php',
            'core/Apps/SheetTableApp.php', 'core/Apps/SocialWallApp.php', 'admin/album_upload.php', 'album/index.php', 'admin/partials/nav.d/16_content_apps.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        $missing = [];
        foreach (array_unique($keys) as $k) {
            foreach (['gu' => $gu, 'hi' => $hi] as $l => $t) {
                if (!isset($t[$k])) {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        $this->assertSame('શુભ વિવાહ', I18n::translate('Shubh Vivah', 'gu'));
        $this->assertSame('शुभ विवाह', I18n::translate('Shubh Vivah', 'hi'));
    }

    // ------------------------------------------------------------------ showcase (#9)

    public function testShowcaseValidationRenderingAndTenancy(): void
    {
        $app = DisplayApps::find('showcase');
        [$c, $errors] = $app->validate([]);
        $this->assertContains(__('Enter the project or product name.'), $errors);
        [$c, $errors] = $app->validate(['project' => 'P', 'qr_type' => 'url', 'qr_url' => 'javascript:alert(1)', 'phone' => '<b>', 'layout' => 'diagonal']);
        $this->assertSame('', $c['qr_url']);
        $this->assertSame('', $c['phone']);
        $this->assertSame('right', $c['layout']);
        $this->assertCount(3, $errors, implode(' | ', $errors)); // bad url, bad phone, url missing for the QR
        [, $errors] = $app->validate(['project' => 'P', 'qr_type' => 'whatsapp']);
        $this->assertContains(__('Enter the contact phone for the WhatsApp QR code.'), $errors);
        [$c, $errors] = $app->validate([
            'project' => 'Shree Residency', 'tagline' => 'Near river', 'images' => ['', (string) self::$id['img2'], 'abc', (string) self::$id['img1'], (string) self::$id['img2'], '999999'],
            'specs' => "2 & 3 BHK\n\n Ready possession \nRERA no. PR/GJ/1", 'price' => '₹ 45 lakh*', 'phone' => '98765 43210', 'qr_type' => 'whatsapp',
            'layout' => 'bottom', 'interval' => '500', 'kenburns' => '1',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame([self::$id['img2'], self::$id['img1']], $c['images'], 'own images only, order kept, no duplicates');
        $this->assertSame("2 & 3 BHK\nReady possession\nRERA no. PR/GJ/1", $c['specs']);
        $this->assertSame(60, $c['interval']);
        // Another hotel's image → denied (404 in the admin, exception in CLI).
        try {
            $app->validate(['project' => 'x', 'images' => [(string) self::$id['h2img']]]);
            $this->fail('foreign image accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }

        $item = DisplayAppsTestKit::createItem('showcase', $c, ['lang' => 'gu', 'theme' => 'corporate_blue']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        foreach (['sc-wrap sc-bottom', 'Shree Residency', '2 &amp; 3 BHK', '₹ 45 lakh*', '98765 43210', '<svg', 'lib-slides.js', 'lobby.jpg', 'pool.jpg'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // QR payload = WhatsApp link with the project in the text.
        $this->assertStringContainsString('https://wa.me/919876543210?text=', ContentApps::whatsappLink('98765 43210', 'x'));
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(['c' . self::$id['img2'], 'c' . self::$id['img1']], array_column($json['data']['photos'], 'id'));
        $this->assertSame(0, $json['refresh_sec']);

        // XSS in every text field; tampered settings with a foreign image id are ignored on the TV.
        $x = DisplayAppsTestKit::createItem('showcase', ['project' => self::XSS, 'tagline' => self::XSS, 'specs' => self::XSS, 'price' => self::XSS, 'phone' => self::XSS,
            'qr_type' => 'url', 'qr_url' => 'https://example.com/?q="><script>alert(1)</script>', 'qr_label' => self::XSS, 'images' => [self::$id['h2img']]], [], 'T' . self::XSS);
        [$code, $html] = DisplayAppsTestKit::page(self::$url, $x);
        $this->assertSame(200, $code);
        self::assertNoXss($this, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('h2/media', $html, 'foreign image not shown');
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ event welcome (#10)

    public function testEventWelcomeProgrammeHighlightAndFrames(): void
    {
        $p = static fn (string $l) => EventWelcomeApp::parseLine($l, '2026-12-10');
        $this->assertSame(['date' => null, 'time' => '10:00', 'label' => 'Haldi', 'place' => 'Garden'], $p('10:00 | Haldi | Garden'));
        $this->assertSame(['date' => null, 'time' => '18:30', 'label' => 'Sangeet', 'place' => ''], $p('6:30 pm Sangeet'));
        $this->assertSame(['date' => null, 'time' => '00:15', 'label' => 'Late', 'place' => ''], $p('12:15 AM - Late'));
        $this->assertSame(['date' => '2026-12-11', 'time' => '19:30', 'label' => 'Reception', 'place' => 'Lawn'], $p('2026-12-11 19:30 | Reception | Lawn'));
        $this->assertSame(['date' => '2026-12-11', 'time' => '08:00', 'label' => 'ફેરા', 'place' => ''], $p('11/12 08:00 - ફેરા'));
        $this->assertSame(['date' => null, 'time' => null, 'label' => 'Lunch for all', 'place' => ''], $p('Lunch for all'));
        $this->assertSame('ઓ', $p('10:00 | ઓ')['label'], 'multibyte labels are not cut');
        foreach (['25:00 | x', '10:75 | x', '13:00 pm x', '2026-02-30 10:00 | x', '2026-12-11 Reception', '10:00 |', ''] as $bad) {
            $this->assertNull($p($bad), $bad);
        }
        $text = "10:00 | Haldi\n12:00 | Lunch\n18:00 | Baraat\n2026-12-11 08:00 | Pheras\nPhotos";
        $state = static fn (string $time): array => array_column(EventWelcomeApp::schedule($text, '2026-12-10', (int) strtotime('2026-12-10 ' . $time)), 'state', 'label');
        $this->assertSame(['Haldi' => 'next', 'Lunch' => 'later', 'Baraat' => 'later', 'Pheras' => 'later', 'Photos' => 'none'], $state('08:00'));
        $this->assertSame(['Haldi' => 'current', 'Lunch' => 'next', 'Baraat' => 'later', 'Pheras' => 'later', 'Photos' => 'none'], $state('11:00'));
        $this->assertSame(['Haldi' => 'past', 'Lunch' => 'past', 'Baraat' => 'current', 'Pheras' => 'next', 'Photos' => 'none'], $state('19:00'));
        $this->assertSame(['Haldi' => 'past', 'Lunch' => 'past', 'Baraat' => 'past', 'Pheras' => 'next', 'Photos' => 'none'], $state('23:30'), 'current ends after 3 h');
        $s = EventWelcomeApp::schedule($text, '2026-12-10', (int) strtotime('2026-12-10 11:00'));
        $this->assertSame('10:00 AM', $s[0]['time']);
        $this->assertSame('6:00 PM', $s[2]['time']);
        $this->assertSame('11 December', $s[3]['day'], 'two days → day labels');

        $app = DisplayApps::find('event_welcome');
        [, $errors] = $app->validate(['title' => '', 'names' => '']);
        $this->assertContains(__('Enter the event title or the names.'), $errors);
        [$c, $errors] = $app->validate(['title' => 'શુભ વિવાહ', 'names' => 'Riya & Aarav', 'event_date' => '2026-02-31', 'schedule' => "10:00 | Haldi\n99:99 | Bad"]);
        $this->assertCount(2, $errors, implode(' | ', $errors));
        $this->assertSame('', $c['event_date']);
        try {
            $app->validate(['title' => 'x', 'album_id' => (string) self::$id['h2album']]);
            $this->fail('foreign album accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('floral', EventWelcomeApp::frame(['frame' => 'auto'], 'wedding'));
        $this->assertSame('lights', EventWelcomeApp::frame(['frame' => 'auto'], 'diwali'));
        $this->assertSame('mandala', EventWelcomeApp::frame(['frame' => 'auto'], 'navratri'));
        $this->assertSame('simple', EventWelcomeApp::frame(['frame' => 'auto'], 'classic_dark'));
        $this->assertSame('none', EventWelcomeApp::frame(['frame' => 'none'], 'wedding'));

        $now = time();
        $sched = date('H:i', $now - 600) . " | Haldi\n" . date('H:i', min($now + 3600, strtotime('today 23:59'))) . ' | Baraat';
        $item = DisplayAppsTestKit::createItem('event_welcome', ['title' => 'શુભ વિવાહ', 'names' => 'Riya & Aarav', 'event_date' => date('Y-m-d'), 'venue' => 'Lawn', 'schedule' => $sched], ['lang' => 'gu', 'theme' => 'wedding']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        foreach (['ew-frame-floral', 'ew-corner', 'શુભ વિવાહ', 'Riya &amp; Aarav', 'ew-item ew-current', 'આપનું હાર્દિક સ્વાગત છે', 'आपका हार्दिक स्वागत है', 'Welcome', 'હમણાં'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame('current', $json['data']['schedule'][0]['state']);
        $this->assertSame(60, $json['refresh_sec']);
        $x = DisplayAppsTestKit::createItem('event_welcome', ['title' => self::XSS, 'names' => self::XSS, 'family' => self::XSS, 'venue' => self::XSS, 'schedule' => '10:00 | ' . self::XSS,
            'welcome_en' => self::XSS, 'welcome_gu' => self::XSS, 'welcome_hi' => self::XSS, 'frame' => 'lights'], ['theme' => 'diwali']);
        [$code, $html] = DisplayAppsTestKit::page(self::$url, $x);
        $this->assertSame(200, $code);
        self::assertNoXss($this, $html);
        $this->assertStringContainsString('ew-lights', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ sheet table (#14)

    public function testSheetUrlNormalisationAndValidation(): void
    {
        $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd';
        $good = [
            self::PUB => self::PUB,
            'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pubhtml' => self::PUB,
            'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?gid=123&single=true&output=csv' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?gid=123&single=true&output=csv',
            'http://DOCS.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?output=csv&evil=1' => self::PUB,
            "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=42" => "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=42",
            "https://docs.google.com/spreadsheets/d/$id/edit#gid=7" => "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=7",
            "docs.google.com/spreadsheets/u/0/d/$id/edit" => "https://docs.google.com/spreadsheets/d/$id/export?format=csv",
        ];
        foreach ($good as $in => $out) {
            $this->assertSame($out, SheetFeed::normalizeUrl($in), $in);
        }
        $bad = [
            'https://docs.google.com.evil.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?output=csv',
            'https://evil.com/docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub',
            'https://docs.google.com@evil.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub',
            'https://user:pw@docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub',
            'https://docs.google.com:8443/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub',
            'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/admin/', 'file:///etc/passwd', 'ftp://docs.google.com/spreadsheets/d/x/pub',
            'javascript:alert(1)', 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/export?format=csv',
            'https://docs.google.com/spreadsheets/d/e/short/pub', 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abc"><script>/pub', '',
            'https://drive.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub',
        ];
        foreach ($bad as $in) {
            $this->assertNull(SheetFeed::normalizeUrl($in), $in);
        }
        $app = DisplayApps::find('sheet_table');
        [$c, $errors] = $app->validate(['url' => 'http://127.0.0.1/secret.csv']);
        $this->assertSame('', $c['url']);
        $this->assertSame([__('Only Google Sheets links (https://docs.google.com/spreadsheets/…) are allowed.')], $errors);
        [$c, $errors] = $app->validate(['url' => self::PUB . '&x=1', 'refresh_min' => '0', 'rows_per_page' => '1000', 'font' => 'huge', 'columns' => ' A, ,Price ,3 ', 'header' => '1']);
        $this->assertSame([], $errors);
        $this->assertSame([self::PUB, 1, 40, 'm', 'A, Price, 3'], [$c['url'], $c['refresh_min'], $c['rows_per_page'], $c['font'], $c['columns']]);
        $this->assertSame([0, 2, 1], SheetTableApp::columnIndexes('A, 3, price', ['Item', 'Price', 'Notes'], 3));
        $this->assertSame([0, 1, 2], SheetTableApp::columnIndexes('', null, 3));
        $this->assertSame([0, 1], SheetTableApp::columnIndexes('Z, 9', null, 2), 'out of range → all');
    }

    public function testSheetFetchParseCacheAndErrorsInProcess(): void
    {
        Cache::clear();
        $calls = [];
        $resp = ['status' => 200, 'body' => ''];
        Http::$mock = function (string $m, string $u) use (&$calls, &$resp): array {
            $calls[] = $u;
            return $resp;
        };
        $today = date('d/m/Y');
        $resp['body'] = "\xEF\xBB\xBFItem,Price,Notes,,\r\n\"Tea, masala\",\"₹ 30\",\"Line1\nLine2\",,\r\n\"" . str_replace('"', '""', self::XSS) . "\",\"1.234,00\",\"=cmd|' /C calc'!A0\",,\r\n$today,99,today row,,\r\n,,,,\r\n";
        $e = SheetFeed::get(self::PUB, 5);
        $this->assertNull($e['error']);
        $this->assertSame([['Item', 'Price', 'Notes'], ['Tea, masala', '₹ 30', "Line1\nLine2"], [self::XSS, '1.234,00', "=cmd|' /C calc'!A0"], [$today, '99', 'today row']], $e['rows']);
        $this->assertSame([self::PUB], $calls, 'only the canonical docs.google.com URL is requested');
        // Within refresh_min: served from the cache, no new request.
        SheetFeed::get(self::PUB, 5);
        $this->assertCount(1, $calls);

        // Limits: 500 rows × 20 columns, long cells cut.
        $big = '';
        for ($r = 0; $r < 650; $r++) {
            $big .= implode(',', array_fill(0, 25, $r === 1 ? str_repeat('x', 900) : 'r' . $r)) . "\n";
        }
        [$rows, $err] = SheetFeed::parse($big);
        $this->assertNull($err);
        $this->assertCount(SheetFeed::MAX_ROWS, $rows);
        $this->assertCount(SheetFeed::MAX_COLS, $rows[0]);
        $this->assertSame(SheetFeed::MAX_CELL, mb_strlen($rows[1][0]));
        [, $err] = SheetFeed::parse(str_repeat('a', SheetFeed::MAX_BYTES + 1));
        $this->assertSame('too_large', $err[0]);
        [, $err] = SheetFeed::parse("<!DOCTYPE html><html><body>Sign in</body></html>");
        $this->assertSame('html', $err[0]);
        [, $err] = SheetFeed::parse("a,b\0c");
        $this->assertSame('binary', $err[0]);
        [, $err] = SheetFeed::parse("\n\n , \n");
        $this->assertSame('empty', $err[0]);
        [$rows] = SheetFeed::parse("caf\xE9,ok\n");
        $this->assertSame([['café', 'ok']], $rows, 'Windows-1252 converted');

        // Force the next fetch: malformed answer (HTML) and HTTP errors keep the last good copy.
        $expire = static function (): void {
            $ns = Cache::hotelNs('sheet');
            $v = Cache::get($ns, self::PUB, 999999);
            $v['checked_at'] = 0;
            Cache::set($ns, self::PUB, $v);
        };
        $good = SheetFeed::get(self::PUB, 5);
        $expire();
        $resp = ['status' => 200, 'body' => '<html><body>Please sign in</body></html>'];
        $e = SheetFeed::get(self::PUB, 5);
        $this->assertSame($good['rows'], $e['rows']);
        $this->assertSame($good['ok_at'], $e['ok_at']);
        $this->assertSame('html', $e['error'][0]);
        $expire();
        $resp = ['status' => 500, 'body' => 'oops'];
        $e = SheetFeed::get(self::PUB, 5);
        $this->assertSame($good['rows'], $e['rows']);
        $this->assertSame('http', $e['error'][0]);
        $this->assertStringContainsString('500', SheetFeed::errorText($e['error']));
        $this->assertCount(3, $calls);

        // The TV model: header, chosen columns, today highlighted, numbers untouched, escaped HTML.
        $item = DisplayAppsTestKit::createItem('sheet_table', ['url' => self::PUB, 'columns' => 'Item, B', 'rows_per_page' => 2, 'heading' => 'Rates']);
        $doc = DisplayAppsTestKit::renderInProcess($item);
        $this->assertStringContainsString('<th>Item</th><th class="st-num">Price</th>', $doc);
        self::assertNoXss($this, $doc);
        $this->assertStringNotContainsString('Notes', $doc);
        $this->assertStringContainsString('Google Sheets answered with error 500', $doc, 'error shown beside the old data');
        $data = DisplayApps::payload($item)['data'];
        $this->assertSame(['Item', 'Price'], $data['header']);
        $this->assertSame(['Tea, masala', '₹ 30'], $data['rows'][0]);
        $this->assertSame([self::XSS, '1.234,00'], $data['rows'][1]);
        $this->assertSame([2], $data['today']);
        $this->assertSame([false, true], $data['numeric']);
        $this->assertStringStartsWith('Updated ', $data['updated']);
        $this->assertSame(2, $data['per_page']);
        $this->assertCount(3, $calls, 'rendering used the cache');
        // Tampered stored URLs (another host) are never fetched.
        foreach (['http://127.0.0.1/secret.csv', 'https://evil.com/x.csv', self::PUB . '&x=1'] as $bad) {
            $t = DisplayAppsTestKit::createItem('sheet_table', ['url' => $bad]);
            $data = DisplayApps::payload($t)['data'];
            $this->assertSame([], $data['rows'], $bad);
            $this->assertSame(__('Only Google Sheets links (https://docs.google.com/spreadsheets/…) are allowed.'), $data['message'], $bad);
            $this->assertSame('url', SheetFeed::get($bad, 1)['error'][0]);
        }
        $this->assertCount(3, $calls, 'no request for tampered URLs');
        Http::$mock = null;
        Cache::clear();
    }

    public function testSheetTableOverHttpWithMockFile(): void
    {
        Cache::clear();
        $csv = "Day,Aarti\nMonday,6:30\n" . date('l') . ",7:00\n<b>bold</b>,8:00\n";
        file_put_contents(self::$mockFile, json_encode([self::PUB => ['status' => 200, 'body' => $csv, 'headers' => ['Content-Type' => 'text/csv']]]));
        $item = DisplayAppsTestKit::createItem('sheet_table', ['url' => self::PUB, 'heading' => 'Aarti times'], ['lang' => 'hi']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('<td>Monday</td>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('class="st-today"', $html);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertSame(['Day', 'Aarti'], $json['data']['header']);
        $this->assertSame('<b>bold</b>', $json['data']['rows'][2][0], 'JSON carries raw text; the JS escapes it');
        $this->assertStringStartsWith(I18n::translate('Updated :t', 'hi', ['t' => '']), $json['data']['updated']);
        // Sheet unreachable (unmatched mock → status 0) after expiry: last good copy + error.
        Tenant::run(1, static function (): void {
            $ns = Cache::hotelNs('sheet');
            $v = Cache::get($ns, self::PUB, 999999);
            $v['checked_at'] = 0;
            Cache::set($ns, self::PUB, $v);
        });
        file_put_contents(self::$mockFile, '{}');
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame('Monday', $json['data']['rows'][0][0]);
        $this->assertNotSame('', $json['data']['error']);
        // An item whose stored URL was tampered with is never fetched (validated again on read).
        $bad = DisplayAppsTestKit::createItem('sheet_table', ['url' => '']);
        [, $json] = DisplayAppsTestKit::data(self::$url, $bad);
        $this->assertSame([], $json['data']['rows']);
        $this->assertNotSame('', $json['data']['message']);
        Cache::clear();
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ social wall (#16)

    public function testSocialWallValidationAndEmbeds(): void
    {
        $this->assertSame('https://www.facebook.com/HotelKrishna', SocialWallApp::normalizePage('https://m.facebook.com/HotelKrishna/'));
        $this->assertSame('https://www.facebook.com/profile.php?id=1000123', SocialWallApp::normalizePage('facebook.com/profile.php?id=1000123'));
        foreach (['https://facebook.com.evil.com/x', 'https://evil.com/HotelKrishna', 'javascript:alert(1)', 'https://www.facebook.com/plugins', 'https://www.facebook.com/a/b/c', 'https://www.facebook.com/x"><script>'] as $bad) {
            $this->assertNull(SocialWallApp::normalizePage($bad), $bad);
        }
        $ig = SocialWallApp::normalizePost('https://www.instagram.com/hotelkrishna/p/C1a2B3c4D5e/?igsh=abc');
        $this->assertSame(['network' => 'instagram', 'kind' => 'post', 'url' => 'https://www.instagram.com/p/C1a2B3c4D5e/', 'embed' => 'https://www.instagram.com/p/C1a2B3c4D5e/embed/captioned/'], $ig);
        $this->assertSame('https://www.instagram.com/reel/Cxyz12345/embed/', SocialWallApp::normalizePost('instagram.com/reels/Cxyz12345', false)['embed']);
        $fb = SocialWallApp::normalizePost('https://www.facebook.com/HotelKrishna/posts/pfbid02AbCdEf123?__cft__=x');
        $this->assertSame('https://www.facebook.com/plugins/post.php?href=' . rawurlencode('https://www.facebook.com/HotelKrishna/posts/pfbid02AbCdEf123') . '&show_text=true&width=500', $fb['embed']);
        $this->assertSame('video', SocialWallApp::normalizePost('https://www.facebook.com/HotelKrishna/videos/1234567890/')['kind']);
        $this->assertSame('video', SocialWallApp::normalizePost('https://www.facebook.com/watch/?v=1234567890')['kind']);
        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=', SocialWallApp::normalizePost('https://www.facebook.com/reel/1234567890')['embed']);
        $this->assertNotNull(SocialWallApp::normalizePost('https://www.facebook.com/permalink.php?story_fbid=pfbid0abc12&id=100064'));
        $this->assertNotNull(SocialWallApp::normalizePost('https://www.facebook.com/photo/?fbid=123456789&set=a.1'));
        foreach (['https://instagram.com.evil.com/p/C1a2B3c4D5e/', 'https://evil.com/p/C1a2B3c4D5e/', 'https://www.instagram.com/hotelkrishna/', 'https://x.com/user/status/1', 'https://twitter.com/u/status/1',
            'javascript:alert(1)//instagram.com/p/C1a2B3c4D5e/', 'https://www.instagram.com/p/C1a"><script>/', 'https://www.facebook.com/HotelKrishna', 'https://www.youtube.com/watch?v=abc'] as $bad) {
            $this->assertNull(SocialWallApp::normalizePost($bad), $bad);
        }

        $app = DisplayApps::find('social_wall');
        [, $errors] = $app->validate([]);
        $this->assertSame([__('Enter a Facebook page and / or at least one Instagram or Facebook post link.')], $errors);
        [$c, $errors] = $app->validate(['page_url' => 'https://evil.com/page', 'posts' => "https://x.com/u/status/1\nhttps://www.instagram.com/p/C1a2B3c4D5e/\nhttps://evil.com/p/x"]);
        $this->assertCount(3, $errors, implode(' | ', $errors));
        $this->assertStringContainsString('X / Twitter', $errors[1]);
        $this->assertSame(['', 'https://www.instagram.com/p/C1a2B3c4D5e/'], [$c['page_url'], $c['posts']]);
        [$c, $errors] = $app->validate(['heading' => self::XSS, 'page_url' => 'facebook.com/HotelKrishna', 'posts' => "https://www.instagram.com/p/C1a2B3c4D5e/\nhttps://www.instagram.com/p/C1a2B3c4D5e/?x=1\nhttps://www.facebook.com/HotelKrishna/posts/12345678", 'per_screen' => '9', 'captions' => '1']);
        $this->assertSame([], $errors);
        $this->assertSame(3, $c['per_screen']);
        $this->assertSame(2, count(explode("\n", $c['posts'])), 'duplicates removed');

        $item = DisplayAppsTestKit::createItem('social_wall', $c);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        self::assertNoXss($this, $html);
        $this->assertStringContainsString('src="https://www.facebook.com/plugins/page.php?href=https%3A%2F%2Fwww.facebook.com%2FHotelKrishna&amp;tabs=timeline', $html);
        $this->assertStringContainsString('src="https://www.instagram.com/p/C1a2B3c4D5e/embed/captioned/"', $html);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"', $html);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertCount(2, $json['data']['posts']);
        $this->assertSame(0, $json['refresh_sec']);
        // Tampered stored settings: unknown hosts never reach the page.
        $t = DisplayAppsTestKit::createItem('social_wall', ['page_url' => 'https://evil.com/x', 'posts' => "javascript:alert(1)\nhttps://evil.com/p/abcdef/"]);
        [$code, $html] = DisplayAppsTestKit::page(self::$url, $t);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('evil.com', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ photo album (#19)

    public function testAlbumAdminUploadFlowPermissionsAndTenancy(): void
    {
        $s = new AdminSession(self::$url, 'caStaff');
        [$code, , $html] = $s->get('album_upload.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('H2-ALBUM-SECRET', $html);
        // Validation, CSRF, permissions.
        [$code, , $html] = $s->post('album_upload.php', ['op' => 'album_save', 'album_id' => 0, 'name' => '  ']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString(e(__('The album name is required.')), $html);
        [$code] = TestEnv::http('POST', self::$url . 'admin/album_upload.php', null, [], $s->jar, ['op' => 'album_save', 'album_id' => '0', 'name' => 'NO-CSRF']);
        $this->assertSame(419, $code);
        [$code] = (new AdminSession(self::$url, 'caRecep'))->get('album_upload.php');
        $this->assertSame(403, $code, 'reception may not manage albums');

        [$code] = $s->post('album_upload.php', ['op' => 'album_save', 'album_id' => 0, 'name' => 'Wedding ' . self::XSS]);
        $this->assertSame(302, $code);
        $album = DB::one('SELECT * FROM albums WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $aid = (int) $album['id'];
        $this->assertSame([0, 1, 100], [(int) $album['guest_upload'], (int) $album['guest_moderation'], (int) $album['guest_max']]);
        $this->assertSame(16, strlen((string) $album['guest_key']));
        [$code, , $html] = $s->get('album_upload.php?album=' . $aid);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        self::assertNoXss($this, $html);
        foreach (['data-album-uploader', 'data-album-files', 'capture="environment"', 'album_upload.js'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // Uploads one by one (AJAX JSON), HEIC and non-images refused with a clear message.
        $ids = [];
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $name) {
            $f = self::jpeg(2400, 1600);
            [$code, $json] = self::upload($s, $aid, $f, $name, 'image/jpeg', ['caption' => $i === 0 ? 'First ' . self::XSS : '']);
            @unlink($f);
            $this->assertSame(200, $code, (string) json_encode($json));
            $this->assertTrue($json['ok']);
            $ids[] = (int) $json['data']['id'];
        }
        $p = Albums::photo($ids[0]);
        $this->assertSame(['approved', 'staff', 'First ' . self::XSS, self::$id['caStaff']], [$p['status'], $p['source'], $p['caption'], (int) $p['created_by']]);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $p['image_path']);
        $this->assertStringStartsWith('h1/media/', $p['image_path']);
        $this->assertLessThanOrEqual(1920, getimagesize(HC_ROOT . '/uploads/' . $p['image_path'])[0], 'resized by Uploader');
        $heic = tempnam(sys_get_temp_dir(), 'heic');
        file_put_contents($heic, "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64));
        [$code, $json] = self::upload($s, $aid, $heic, 'IMG_0001.HEIC', 'image/heic');
        $this->assertSame(422, $code);
        $this->assertStringContainsString('HEIC', $json['error']['message']);
        [$code, $json] = self::upload($s, $aid, $heic, 'renamed.jpg', 'image/jpeg');
        $this->assertSame(422, $code, 'HEIC detected by content too');
        @unlink($heic);
        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, '<?php echo 1;');
        [$code, $json] = self::upload($s, $aid, $txt, 'x.php.jpg');
        @unlink($txt);
        $this->assertSame(422, $code);
        $this->assertSame(__('Only JPG, PNG, GIF or WEBP images are allowed.'), $json['error']['message']);
        $this->assertSame(3, Albums::count($aid));

        // TV item: photos in data JSON right away (refresh 30 s), captions escaped.
        $item = DisplayAppsTestKit::createItem('photo_album', ['album_id' => $aid, 'heading' => self::XSS, 'shuffle' => true], ['lang' => 'gu']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        self::assertNoXss($this, $html);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame($ids, array_column($json['data']['photos'], 'id'));
        $this->assertSame('First ' . self::XSS, $json['data']['photos'][0]['caption']);
        $this->assertSame(30, $json['refresh_sec']);

        // Caption, reorder, delete.
        [$code] = $s->post('album_upload.php', ['op' => 'photo_caption', 'album_id' => $aid, 'photo_id' => $ids[1], 'caption' => 'Mandap']);
        $this->assertSame(302, $code);
        $s->post('album_upload.php', ['op' => 'move', 'dir' => 'up', 'album_id' => $aid, 'photo_id' => $ids[2]]);
        $this->assertSame([$ids[0], $ids[2], $ids[1]], array_map('intval', array_column(Albums::photos($aid), 'id')));
        [$code, $json] = TestEnv::http('POST', self::$url . 'admin/album_upload.php', null, self::ajaxHeaders($s), $s->jar, ['op' => 'reorder', 'album_id' => (string) $aid, 'ids[0]' => (string) $ids[1], 'ids[1]' => (string) $ids[0], 'ids[2]' => (string) $ids[2], 'ids[3]' => '999999']);
        $this->assertSame(200, $code);
        $this->assertSame([$ids[1], $ids[0], $ids[2]], array_map('intval', array_column(Albums::photos($aid), 'id')));
        $this->assertSame('Mandap', Albums::photo($ids[1])['caption']);
        $path = Albums::photo($ids[2])['image_path'];
        [$code] = $s->post('album_upload.php', ['op' => 'photo_delete', 'album_id' => $aid, 'photo_id' => $ids[2]]);
        $this->assertSame(302, $code);
        $this->assertNull(DB::one('SELECT id FROM album_photos WHERE id = :id', ['id' => $ids[2]]));
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $path);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([$ids[1], $ids[0]], array_column($json['data']['photos'], 'id'));

        // Tenancy: hotel 2 cannot see / change hotel 1 albums and photos (404) and vice versa.
        $b = new AdminSession(self::$url, 'caBoss2');
        [$code, , $html] = $b->get('album_upload.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('H2-ALBUM-SECRET', $html);
        [$code] = $b->get('album_upload.php?album=' . $aid);
        $this->assertSame(404, $code);
        foreach ([['op' => 'album_save', 'album_id' => $aid, 'name' => 'HACKED'], ['op' => 'album_delete', 'album_id' => $aid], ['op' => 'photo_delete', 'album_id' => $aid, 'photo_id' => $ids[0]], ['op' => 'guest_rotate', 'album_id' => $aid]] as $op) {
            [$code] = $b->post('album_upload.php', $op);
            $this->assertSame(404, $code, $op['op']);
        }
        $f = self::jpeg();
        [$code] = self::upload($b, $aid, $f, 'x.jpg');
        $this->assertSame(404, $code);
        [$code] = self::upload($s, self::$id['h2album'], $f, 'x.jpg');
        $this->assertSame(404, $code);
        @unlink($f);
        // A photo id of another album of the same hotel is refused too.
        $other = Albums::create(['name' => 'Other', 'guest_upload' => 0, 'guest_moderation' => 1, 'guest_max' => 5]);
        [$code] = $s->post('album_upload.php', ['op' => 'photo_delete', 'album_id' => $other, 'photo_id' => $ids[0]]);
        $this->assertSame(302, $code);
        $this->assertNotNull(Albums::photo($ids[0]));
        $this->assertSame('Wedding ' . self::XSS, DB::value('SELECT name FROM albums WHERE id = :id', ['id' => $aid]));
        // The app form lists only own albums; a foreign album id in a saved item shows nothing.
        $this->assertStringNotContainsString('H2-ALBUM-SECRET', DisplayApps::find('photo_album')->form(DisplayApps::find('photo_album')->defaults()));
        $x = DisplayAppsTestKit::createItem('photo_album', ['album_id' => self::$id['h2album']]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $x);
        $this->assertSame([], $json['data']['photos']);

        // A manager limited to one room (Access) still manages hotel-wide albums.
        DB::insert('user_access', ['user_id' => self::$id['caStaff'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        $ls = new AdminSession(self::$url, 'caStaff');
        [$code, , $html] = $ls->get('album_upload.php?album=' . $aid);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$code] = $ls->post('album_upload.php', ['op' => 'photo_caption', 'album_id' => $aid, 'photo_id' => $ids[0], 'caption' => 'Limited']);
        $this->assertSame(302, $code);
        DB::query('DELETE FROM user_access');

        // Delete the album: photos and files go too.
        $p0 = Albums::photo($ids[0])['image_path'];
        [$code] = $s->post('album_upload.php', ['op' => 'album_delete', 'album_id' => $aid]);
        $this->assertSame(302, $code);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM album_photos WHERE album_id = :a', ['a' => $aid]));
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $p0);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'album_delete' AND entity_id = :id", ['id' => $aid]));
        $this->assertTrue(Tenant::run(2, fn () => (bool) Albums::find(self::$id['h2album'])));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testGuestUploadLinkSignatureModerationLimitsAndRate(): void
    {
        $aid = Albums::create(['name' => 'Riya & Aarav ' . self::XSS, 'guest_upload' => 0, 'guest_moderation' => 1, 'guest_max' => 3]);
        $album = Albums::find($aid);
        $link = Albums::guestUrl($album);
        $this->assertMatchesRegularExpression('#/album/\?a=' . $aid . '&s=[0-9a-f]{32}$#', $link);
        $rel = DisplayAppsTestKit::rel($link);
        $get = static fn (string $q): array => TestEnv::http('GET', self::$url . $q);

        // Switched off → closed (403); bad / tampered signatures → 404.
        [$code, , $html] = $get($rel);
        $this->assertSame(403, $code);
        $this->assertStringContainsString(e(__('Photo upload for this album is closed.')), $html);
        DB::update('albums', ['guest_upload' => 1], 'id = :id', ['id' => $aid]);
        foreach ([preg_replace('/s=[0-9a-f]/', 's=0', $rel), 'album/?a=' . $aid . '&s=', 'album/?a=' . self::$id['h2album'] . '&s=' . substr((string) preg_replace('/.*s=/', '', $rel), 0, 32), 'album/?a=abc&s=x', 'album/'] as $q) {
            [$code, , $html] = $get($q);
            $this->assertSame(404, $code, $q);
            $this->assertFalse(TestEnv::hasPhpError($html));
        }
        $h2link = Tenant::run(2, fn () => Albums::guestUrl(Albums::find(self::$id['h2album'])));
        $this->assertNotSame($link, $h2link);

        // Open: the page (gu / hi / en), CSP, escaped album name.
        foreach (['', '&lang=gu', '&lang=hi'] as $l) {
            [$code, , $html, $head] = $get($rel . $l);
            $this->assertSame(200, $code);
            $this->assertFalse(TestEnv::hasPhpError($html));
            self::assertNoXss($this, $html);
            $this->assertStringContainsString('data-album-uploader', $html);
            $this->assertStringContainsString("script-src 'self'", $head);
        }
        $this->assertStringContainsString('તમારા ફોટા શેર કરો', $get($rel . '&lang=gu')[2]);

        // Upload with moderation: pending, not on the TV until approved.
        $item = DisplayAppsTestKit::createItem('photo_album', ['album_id' => $aid, 'guest_qr' => true]);
        $f = self::jpeg();
        [$code, $json] = self::guestPost($link, $f, 'g1.jpg', ['guest_name' => 'Kaka ' . self::XSS, 'caption' => 'Mehndi']);
        $this->assertSame(200, $code, (string) json_encode($json));
        $this->assertSame('pending', $json['data']['status']);
        $pid = (int) $json['data']['id'];
        $p = Albums::photo($pid);
        $this->assertSame(['pending', 'guest', 'Kaka ' . self::XSS, 'Mehndi', null], [$p['status'], $p['source'], $p['guest_name'], $p['caption'], $p['created_by']]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([], $json['data']['photos'], 'pending photos stay off the TV');
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString('pa-qr', $html, 'guest QR shown while the link is on');
        $s = new AdminSession(self::$url, 'caStaff');
        [, , $html] = $s->get('album_upload.php?album=' . $aid);
        $this->assertStringContainsString('data-pending="' . $pid . '"', $html);
        self::assertNoXss($this, $html);
        $this->assertStringContainsString(e(DisplayAppsTestKit::rel($link)), $html, 'guest link + QR on the album page');
        [$code] = $s->post('album_upload.php', ['op' => 'photo_approve', 'album_id' => $aid, 'photo_id' => $pid]);
        $this->assertSame(302, $code);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([$pid], array_column($json['data']['photos'], 'id'));

        // Moderation off: approved immediately. Max N guest photos.
        DB::update('albums', ['guest_moderation' => 0], 'id = :id', ['id' => $aid]);
        [$code, $json] = self::guestPost($link, $f, 'g2.jpg');
        $this->assertSame('approved', $json['data']['status']);
        [$code, $json] = self::guestPost($link, $f, 'g3.jpg');
        $this->assertSame(200, $code);
        [$code, $json] = self::guestPost($link, $f, 'g4.jpg');
        $this->assertSame(403, $code);
        $this->assertSame('LIMIT', $json['error']['code']);
        $this->assertSame(3, Albums::count($aid, 'guest'));
        // Staff uploads do not count against the guest limit.
        $this->assertSame(0, Albums::guestRemaining(Albums::find($aid)));

        // Rate limit per IP + album.
        DB::update('albums', ['guest_max' => 100], 'id = :id', ['id' => $aid]);
        DB::query('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < Albums::RATE_HITS + 1; $i++) {
            [$codes[]] = self::guestPost($link, $f, 'r.jpg');
        }
        $this->assertSame(429, end($codes));
        $this->assertSame(Albums::RATE_HITS, count(array_filter($codes, static fn ($c) => $c === 200)));
        DB::query('DELETE FROM rate_limits');
        // Garbage / HEIC from guests refused.
        $heic = tempnam(sys_get_temp_dir(), 'heic');
        file_put_contents($heic, "\0\0\0\x18ftypheic" . str_repeat("\0", 40));
        [$code, $json] = self::guestPost($link, $heic, 'x.heic');
        $this->assertSame(422, $code);
        $this->assertStringContainsString('HEIC', $json['error']['message']);
        @unlink($heic);

        // New link: the old signature stops working; switching off closes uploads.
        $s->post('album_upload.php', ['op' => 'guest_rotate', 'album_id' => $aid]);
        [$code] = $get($rel);
        $this->assertSame(404, $code);
        $newLink = Albums::guestUrl(Albums::find($aid));
        [$code] = $get(DisplayAppsTestKit::rel($newLink));
        $this->assertSame(200, $code);
        DB::update('albums', ['guest_upload' => 0], 'id = :id', ['id' => $aid]);
        [$code, $json] = self::guestPost($newLink, $f, 'late.jpg');
        $this->assertSame(403, $code);
        $this->assertSame('CLOSED', $json['error']['code']);
        // Suspended hotel: nothing works.
        DB::update('albums', ['guest_upload' => 1], 'id = :id', ['id' => $aid]);
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        [$code] = $get(DisplayAppsTestKit::rel($newLink));
        $this->assertSame(403, $code);
        [$code] = self::guestPost($newLink, $f, 'sus.jpg');
        $this->assertSame(403, $code);
        DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
        Tenant::forget();
        @unlink($f);
        Albums::delete($aid);
        $this->assertSame('', TestEnv::phpErrors());
    }
}
