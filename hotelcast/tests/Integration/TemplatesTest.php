<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Template library (#12) & local guide (#7): every template renders a valid, self-contained HTML
 * document in EN / GU / HI, user input is escaped (XSS), QR codes match the reference encoder, the
 * save → re-edit flow through the admin panel, permissions per role, cross-hotel isolation and the
 * "Local guide" item in the TV guest menu.
 */
final class TemplatesTest extends TestCase
{
    private static string $url;
    private static int $room = 0;
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'tplMgr', 'staff' => 'tplStaff', 'reception' => 'tplRecep'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$room = DB::insert('rooms', ['room_number' => '301', 'name' => 'Room 301', 'floor' => '3']);
        $c = DB::insert('content_items', ['title' => 'Main', 'type' => 'announcement', 'body' => 'Hello', 'duration' => 10]);
        DB::update('rooms', ['content_id' => $c], 'id = :id', ['id' => self::$room]);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'tplBoss2', 'email' => 'b2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, fn () => Hotels::createHotelUser(2, ['username' => 'tplMgr2', 'email' => 'm2@t.test', 'password' => 'Passw0rd!'], 'manager'));
        Templates::reset();
        ContentResolver::resetExtensions();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        // Static per-process caches must not leak hotel rows (plans / status) into later test classes.
        Tenant::forget();
        ContentResolver::resetExtensions();
        Templates::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
    }

    public function testLibraryHasAllRequiredTemplates(): void
    {
        $all = Templates::all();
        $this->assertGreaterThanOrEqual(25, count($all));
        foreach (['diwali', 'janmashtami', 'navratri', 'holi', 'uttarayan', 'rath_yatra', 'ganesh_chaturthi', 'new_year', 'independence_day', 'republic_day',
            'raksha_bandhan', 'dussehra', 'wifi', 'breakfast', 'checkout', 'no_smoking', 'pool', 'menu_board', 'offer', 'welcome',
            'aarti', 'bhog', 'darshan_closed', 'attractions', 'ferry', 'taxi', 'emergency', 'map_qr'] as $id) {
            $this->assertArrayHasKey($id, $all, $id);
        }
        $this->assertSame(['festival', 'notice', 'temple', 'guide'], array_keys(Templates::grouped()));
        foreach ($all as $id => $t) {
            $this->assertArrayHasKey($t['category'], Templates::CATEGORIES, $id);
            foreach (['en', 'gu', 'hi'] as $l) {
                $this->assertNotSame('', (string) ($t['name'][$l] ?? ''), "$id name $l");
                $this->assertNotEmpty($t['defaults'][$l]['title'] ?? '', "$id default title $l");
            }
            $keys = [];
            foreach ($t['fields'] as $f) {
                $this->assertContains($f['type'], Templates::FIELD_TYPES, $id . '.' . $f['key']);
                $this->assertNotSame('', __((string) $f['label']));
                $keys[] = $f['key'];
            }
            $this->assertSame(count($keys), count(array_unique($keys)), "$id duplicate field keys");
        }
    }

    public function testEveryTemplateRendersValidSelfContainedHtml(): void
    {
        foreach (Templates::all() as $id => $t) {
            foreach (['en', 'gu', 'hi'] as $lang) {
                $vals = Templates::defaults($t, $lang);
                $html = Templates::render($t, $vals, ['hotel' => 'Hotel <Test>', 'lang' => $lang]);
                $this->assertStringStartsWith('<!DOCTYPE html><html', $html, "$id/$lang");
                $this->assertStringEndsWith('</body></html>', $html);
                $this->assertStringContainsString('<meta charset="utf-8">', $html);
                $this->assertStringContainsString('Noto Sans Gujarati', $html, 'Gujarati font fallback');
                $this->assertMatchesRegularExpression('/font-size:\d+(\.\d+)?vh/', $html, 'vh based sizes');
                $this->assertStringContainsString(e($vals['title']), $html, "$id/$lang title");
                $this->assertStringNotContainsString('Hotel <Test>', $html, 'hotel name escaped');
                // No external resources at all (works offline on the TV, no copyright issue).
                $this->assertDoesNotMatchRegularExpression('/<(script|link|img|iframe)\b/i', $html, "$id/$lang external tag");
                $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?https?:/i', $html);
                $this->assertDoesNotMatchRegularExpression('/\bsrc\s*=/i', $html);
                // Well-formed enough for an HTML parser.
                $doc = new DOMDocument();
                libxml_use_internal_errors(true);
                $this->assertTrue($doc->loadHTML('<?xml encoding="utf-8"?>' . $html));
                $fatal = array_filter(libxml_get_errors(), fn ($e) => $e->level === LIBXML_ERR_FATAL);
                libxml_clear_errors();
                $this->assertSame([], array_map(fn ($e) => trim($e->message), $fatal), "$id/$lang");
                $this->assertSame(1, $doc->getElementsByTagName('body')->length);
            }
        }
    }

    public function testUserFieldsAreEscaped(): void
    {
        foreach (Templates::all() as $id => $t) {
            $in = [];
            foreach ($t['fields'] as $f) {
                $in[$f['key']] = match ($f['type']) {
                    'color' => 'red;}</style><script>alert(3)</script>',
                    'url' => 'javascript:alert(4)',
                    'list' => [self::XSS . ' | ' . self::XSS . ' | ' . self::XSS, '</td></tr></table><script>alert(5)</script>'],
                    default => self::XSS,
                };
            }
            $vals = Templates::normalize($t, $in);
            foreach ($t['fields'] as $f) {
                if ($f['type'] === 'color') {
                    $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $vals[$f['key']]);
                }
                if ($f['type'] === 'url') {
                    $this->assertSame('', $vals[$f['key']], 'javascript: URLs are dropped');
                }
            }
            $html = Templates::render($t, $in);
            $this->assertStringNotContainsString('<script', $html, $id);
            $this->assertStringNotContainsString('<img', $html, $id);
            $this->assertStringNotContainsString('javascript:', $html, $id);
            $this->assertSame(1, substr_count($html, '</style>'), $id . ' colour cannot close the style block');
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $id . ' text shown escaped');
        }
    }

    public function testQrEncoderMatchesReferenceImplementation(): void
    {
        // Expected matrices produced by qrcode-generator 1.4.4 (Kazuhiko Arase, MIT) for the same input.
        foreach ([
            ['https://hotelcast.example/g/AbC123', 'M', 29, 'e69489f216235a360bc72db6245b43ffdebd93bf'],
            ['WIFI:T:WPA;S:Hotel-Guest;P:welcome123;;', 'M', 29, 'bb6b898cb1ef0117fc0e26d105d3a61b315830cc'],
            ['https://www.google.com/maps/search/?api=1&query=Dwarkadhish+Temple+Dwarka+Gujarat+India+361335+Jagat+Mandir+Gomti+Ghat+Rukmini+Devi+Temple+Nageshwar+Jyotirlinga', 'L', 49, '61b0ccfc57d2d70e74364e8e69153da42fd36f2f'],
        ] as [$text, $ecl, $size, $sha]) {
            $m = QrCode::matrix($text, $ecl);
            $this->assertCount($size, $m);
            $this->assertSame($sha, sha1(implode("\n", array_map(fn ($r) => implode('', array_map(fn ($v) => $v ? '1' : '0', $r)), $m))), $text);
        }
        $svg = QrCode::svg('https://x.test/"><script>', '#000000', '#FFFFFF', '"><script>');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->expectException(InvalidArgumentException::class);
        QrCode::svg(str_repeat('x', 2000));
    }

    public function testWifiPayloadEscapingAndQrInTemplate(): void
    {
        $this->assertSame('WIFI:T:WPA;S:My\;Hotel;P:pa\:ss\\\\w;;', Templates::wifiPayload('My;Hotel', 'pa:ss\\w'));
        $this->assertSame('WIFI:T:nopass;S:Open;;', Templates::wifiPayload('Open', ''));
        $t = Templates::find('wifi');
        $html = Templates::render($t, ['ssid' => 'Guest-Net', 'password' => 'abc12345'] + Templates::defaults($t));
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Guest-Net', $html);
        $map = Templates::find('map_qr');
        $html = Templates::render($map, ['url' => ''] + Templates::defaults($map));
        $this->assertStringNotContainsString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3', $html, 'no QR without a link');
    }

    public function testSaveReEditAndLivePreviewOverHttp(): void
    {
        $s = new AdminSession(self::$url, 'tplMgr');
        [$code, , $html] = $s->get('templates.php');
        $this->assertSame(200, $code);
        $this->assertGreaterThanOrEqual(25, substr_count($html, '<iframe loading="lazy" sandbox=""'));
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$code, , $html] = $s->get('templates.php?action=new&tpl=diwali&lang=gu');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('દિવાળીની હાર્દિક શુભકામનાઓ', $html);

        // Live preview (AJAX) escapes values.
        [$code, $j] = $s->ajax('tpl_render', ['tpl' => 'offer', 'fields' => ['title' => '<b>x</b>', 'big' => '50% OFF']]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $j['data']['html']);
        [$code] = $s->ajax('tpl_render', ['tpl' => 'nope', 'fields' => []]);
        $this->assertSame(404, $code);

        // Save as a content item.
        $s->post('templates.php', ['op' => 'save', 'tpl' => 'offer', 'id' => 0, 'lang' => 'en', 'title' => 'Monsoon offer', 'duration' => 20, 'is_active' => 1,
            'fields[title]' => 'Monsoon Special', 'fields[big]' => '30% OFF', 'fields[message]' => 'On all rooms <b>now</b>', 'fields[code]' => 'RAIN30',
            'fields[bg_color]' => '#123456', 'fields[accent_color]' => '#FFEE00']);
        $item = DB::one("SELECT * FROM content_items WHERE hotel_id = 1 AND title = 'Monsoon offer'");
        $this->assertNotNull($item);
        $this->assertSame('html', $item['type']);
        $this->assertSame(20, (int) $item['duration']);
        $settings = json_decode((string) $item['settings'], true);
        $this->assertSame('offer', $settings['template_id']);
        $this->assertSame('30% OFF', $settings['fields']['big']);
        $this->assertStringContainsString('Monsoon Special', (string) $item['body']);
        $this->assertStringContainsString('On all rooms &lt;b&gt;now&lt;/b&gt;', (string) $item['body']);
        $this->assertStringContainsString('#123456', (string) $item['body']);

        // The content library sends template items to the template form; ?raw=1 keeps the HTML editor.
        [$code, , , $head] = $s->get('content.php?action=edit&id=' . $item['id']);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('templates.php?action=edit&id=' . $item['id'], $head);
        [$code, , $html] = $s->get('content.php?action=edit&raw=1&id=' . $item['id']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('HTML code', $html);
        [$code, , $html] = $s->get('templates.php?action=edit&id=' . $item['id']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('value="RAIN30"', $html);
        $this->assertStringContainsString('Monsoon Special', $html);

        // Re-edit and save again: same item updated.
        $s->post('templates.php', ['op' => 'save', 'tpl' => 'offer', 'id' => $item['id'], 'lang' => 'en', 'title' => 'Monsoon offer', 'duration' => 25, 'is_active' => 1,
            'fields[title]' => 'Monsoon Mega Sale', 'fields[big]' => '40% OFF', 'fields[code]' => 'RAIN40']);
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $item['id']]);
        $this->assertStringContainsString('Monsoon Mega Sale', (string) $row['body']);
        $this->assertSame('RAIN40', json_decode((string) $row['settings'], true)['fields']['code']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title = 'Monsoon offer'"));
        // TV gets it as a normal html item.
        $tv = ContentManager::toTvItem($row);
        $this->assertSame('html', $tv['type']);
        $this->assertStringContainsString('40% OFF', $tv['html']);

        // Required field missing → error, nothing saved.
        $before = (int) DB::value('SELECT COUNT(*) FROM content_items');
        $s->post('templates.php', ['op' => 'save', 'tpl' => 'map_qr', 'id' => 0, 'lang' => 'en', 'title' => 'Map', 'duration' => 10, 'fields[title]' => 'Map', 'fields[url]' => 'javascript:alert(1)']);
        $this->assertSame($before, (int) DB::value('SELECT COUNT(*) FROM content_items'));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPermissionsPerRole(): void
    {
        foreach (['tplStaff', 'tplRecep'] as $u) {
            $s = new AdminSession(self::$url, $u);
            [$code] = $s->get('templates.php');
            $this->assertSame(403, $code, $u);
            [$code] = $s->ajax('tpl_render', ['tpl' => 'diwali', 'fields' => []]);
            $this->assertSame(403, $code, $u);
            [, , $html] = $s->get('index.php');
            $this->assertStringNotContainsString('templates.php', $html, $u . ' sees no menu item');
        }
        $s = new AdminSession(self::$url, 'tplMgr');
        [, , $html] = $s->get('index.php');
        $this->assertStringContainsString('templates.php', $html);
    }

    public function testCrossHotelIsolation(): void
    {
        $t = Templates::find('welcome');
        $id = Templates::save(null, $t, Templates::defaults($t), 'H1 welcome', 10, true);
        $s = new AdminSession(self::$url, 'tplMgr2');
        [$code] = $s->get('templates.php?action=edit&id=' . $id);
        $this->assertSame(404, $code);
        [$code] = $s->post('templates.php', ['op' => 'save', 'tpl' => 'welcome', 'id' => $id, 'lang' => 'en', 'title' => 'HACKED', 'duration' => 1, 'fields[title]' => 'HACKED']);
        $this->assertSame(404, $code);
        $this->assertSame('H1 welcome', DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $id]));
        [$code] = $s->post('templates.php', ['op' => 'guide', 'local_guide_content_id' => $id]);
        $this->assertSame(404, $code);
        $this->assertSame('', (string) Settings::getFor(2, 'local_guide_content_id', ''));
        // Hotel 2 own template item works and lands in hotel 2.
        $s->post('templates.php', ['op' => 'save', 'tpl' => 'welcome', 'id' => 0, 'lang' => 'hi', 'title' => 'H2 welcome', 'duration' => 10, 'is_active' => 1, 'fields[title]' => 'स्वागत']);
        $this->assertSame(2, (int) DB::value("SELECT hotel_id FROM content_items WHERE title = 'H2 welcome'"));
    }

    public function testLocalGuideInTvGuestMenu(): void
    {
        $t = Templates::find('attractions');
        $gid = Templates::save(null, $t, Templates::defaults($t, 'gu'), 'Guide GU', 0, true, 'gu');
        $s = new AdminSession(self::$url, 'tplMgr');
        $s->post('templates.php', ['op' => 'guide', 'local_guide_content_id' => $gid, 'local_guide_title' => '']);
        Settings::flush();
        $this->assertSame($gid, Settings::int('local_guide_content_id'));
        $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$room]);
        $c = ContentResolver::build($room);
        $guide = array_values(array_filter($c['guest_menu'] ?? [], fn ($m) => $m['id'] === 'guide'));
        $this->assertCount(1, $guide);
        $this->assertSame('content', $guide[0]['type']);
        $this->assertSame('map', $guide[0]['icon']);
        $this->assertSame('html', $guide[0]['content']['type']);
        $this->assertSame(0, $guide[0]['content']['duration']);
        $this->assertStringContainsString('દ્વારકા દર્શન', $guide[0]['content']['html']);

        // Custom title; inactive item → no entry; suspended hotel → no entry.
        Settings::set('local_guide_title', 'Explore Dwarka');
        $this->assertSame('Explore Dwarka', array_values(array_filter(ContentResolver::build($room)['guest_menu'], fn ($m) => $m['id'] === 'guide'))[0]['title']);
        DB::update('content_items', ['is_active' => 0], 'id = :id', ['id' => $gid]);
        $this->assertSame([], array_filter(ContentResolver::build($room)['guest_menu'] ?? [], fn ($m) => $m['id'] === 'guide'));
        DB::update('content_items', ['is_active' => 1], 'id = :id', ['id' => $gid]);
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        try {
            $c = ContentResolver::build($room);
            $this->assertSame('suspended', $c['mode']);
            $this->assertSame([], array_filter($c['guest_menu'] ?? [], fn ($m) => $m['id'] === 'guide'));
        } finally {
            DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
            Tenant::forget();
        }
        // Over the device API.
        $key = (string) Settings::get('registration_key');
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-tpl-guide-01', 'room_number' => '301', 'registration_key' => $key]);
        $this->assertSame(200, $st);
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, ['Authorization: Bearer ' . $j['data']['token'], 'X-Device-Id: tv-tpl-guide-01']);
        $this->assertSame(200, $st);
        $ids = array_column($j['data']['content']['guest_menu'] ?? [], 'id');
        $this->assertContains('guide', $ids);
    }

    public function testTemplateFilesAreNotExecutableFromTheWeb(): void
    {
        $this->assertFileExists(HC_ROOT . '/templates/.htaccess');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents(HC_ROOT . '/templates/.htaccess'));
        [$st, , $body] = TestEnv::http('GET', self::$url . 'templates/10_festivals.php');
        $this->assertSame('', trim($body), 'definition files exit without HC_ROOT');
    }
}
