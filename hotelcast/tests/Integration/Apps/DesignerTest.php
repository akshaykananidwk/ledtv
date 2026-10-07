<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Slide designer (#11) and PDF → slides import (#12), core/Designer.php + admin/ajax.d/designer.php:
 * AJAX save of the exported PNG creates / updates an image item, the design JSON round-trips into the
 * editor, validation (not a PNG, too large, foreign id → 404, missing CSRF → 419), PDF page upload is
 * idempotent and builds a playlist, hotel templates, tenancy isolation, page rendering per role and XSS.
 */
final class DesignerTest extends TestCase
{
    private static string $url;
    private static string $tmp;
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'dzBoss', 'manager' => 'dzMgr', 'staff' => 'dzStaff'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'dzBoss2', 'email' => 'dz2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, fn () => Hotels::createHotelUser(2, ['username' => 'dzMgr2', 'email' => 'dzm2@t.test', 'password' => 'Passw0rd!'], 'manager'));
        Tenant::set(1);
        Designer::reset();
        Cache::clear();
        self::$tmp = sys_get_temp_dir() . '/hc_designer_' . getmypid();
        @mkdir(self::$tmp, 0755, true);
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        TestEnv::rmTree(self::$tmp);
        Tenant::forget();
        Designer::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
    }

    // ------------------------------------------------------------------ helpers

    private static function png(int $w = 1920, int $h = 1080, int $pad = 0): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 40, 90));
        imagestring($img, 5, 20, 20, 'slide ' . random_int(1, 99999), imagecolorallocate($img, 255, 255, 255));
        $f = self::$tmp . '/' . bin2hex(random_bytes(6)) . '.png';
        imagepng($img, $f);
        imagedestroy($img);
        if ($pad > 0) {
            file_put_contents($f, str_repeat("\0", $pad), FILE_APPEND);
        }
        return $f;
    }

    private static function jpeg(int $w = 1920, int $h = 1080): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 250, 250, 250));
        $f = self::$tmp . '/' . bin2hex(random_bytes(6)) . '.jpg';
        imagejpeg($img, $f, 80);
        imagedestroy($img);
        return $f;
    }

    private static function design(string $text = 'Hello'): array
    {
        return [
            'version' => '6.9.1',
            'background' => ['type' => 'linear', 'gradientUnits' => 'pixels', 'coords' => ['x1' => 0, 'y1' => 0, 'x2' => 1920, 'y2' => 0],
                'colorStops' => [['offset' => 0, 'color' => '#112233'], ['offset' => 1, 'color' => '#445566']]],
            'objects' => [
                ['type' => 'Textbox', 'left' => 100, 'top' => 120, 'width' => 900, 'text' => $text, 'fontSize' => 90, 'fill' => '#ffffff',
                    'fontFamily' => '"Noto Sans Gujarati", sans-serif', 'shadow' => ['color' => '#000000', 'blur' => 10, 'offsetX' => 4, 'offsetY' => 4]],
                ['type' => 'Rect', 'left' => 10, 'top' => 20, 'width' => 300, 'height' => 200, 'fill' => '#FFC107', 'rx' => 20, 'ry' => 20],
            ],
            'hc' => ['bgMode' => 'h', 'bg1' => '#112233', 'bg2' => '#445566'],
            'width' => 99999,
        ];
    }

    /** Multipart POST to ajax.php?action=… ($files: field => [path, mime, name]). */
    private static function post(AdminSession $s, string $action, array $fields, array $files = [], bool $csrf = true): array
    {
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
        if ($csrf) {
            $h[] = 'X-CSRF-Token: ' . $s->csrf;
        }
        $form = array_map('strval', $fields);
        foreach ($files as $k => [$path, $mime, $name]) {
            $form[$k] = new CURLFile($path, $mime, $name);
        }
        return TestEnv::http('POST', $s->base . 'admin/ajax.php?action=' . $action, null, $h, $s->jar, $form);
    }

    private static function save(AdminSession $s, int $id, string $title, ?array $design = null, ?string $png = null, array $extra = []): array
    {
        return self::post($s, 'designer_save', ['id' => $id, 'title' => $title, 'duration' => 15, 'design' => json_encode($design ?? self::design())] + $extra,
            ['png' => [$png ?? self::png(), 'image/png', 'slide.png']]);
    }

    private static function editorConfig(string $html): array
    {
        preg_match('#<script type="application/json" id="dzConfig">(.*?)</script>#s', $html, $m);
        return (array) json_decode($m[1] ?? 'null', true);
    }

    // ------------------------------------------------------------------ tests

    public function testStarterTemplates(): void
    {
        $all = Designer::starters();
        $this->assertGreaterThanOrEqual(12, count($all));
        foreach (['sale_offer', 'menu_special', 'welcome_guest', 'diwali', 'navratri', 'janmashtami', 'notice', 'event', 'birthday',
            'real_estate', 'doctor_timing', 'gym_class', 'temple_aarti', 'coming_soon'] as $id) {
            $this->assertArrayHasKey($id, $all, $id);
        }
        foreach ($all as $id => $t) {
            $this->assertNotEmpty($t['design']['objects'], $id);
            $this->assertSame($t['design'], json_decode(Designer::cleanJson(json_encode($t['design'])), true), $id . ' passes validation');
            foreach ($t['design']['objects'] as $o) {
                $this->assertContains($o['type'], ['Textbox', 'Rect', 'Circle', 'Triangle', 'Line', 'Polygon'], $id);
            }
            foreach (['gu', 'hi'] as $l) {
                $this->assertNotSame($t['name'], I18n::translate($t['name'], $l), "$id name translated ($l)");
            }
        }
        $this->assertStringContainsString('શુભ દીપાવલી', json_encode($all['diwali']['design'], JSON_UNESCAPED_UNICODE));
    }

    public function testSaveCreatesImageItemAndResaveUpdatesIt(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        [$code, $j] = self::save($s, 0, 'Diwali offer');
        $this->assertSame(200, $code, json_encode($j));
        $this->assertTrue($j['data']['created']);
        $id = (int) $j['data']['id'];
        $this->assertStringContainsString('designer.php?id=' . $id, $j['data']['edit_url']);

        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $id]);
        $this->assertSame('image', $row['type']);
        $this->assertSame(1, (int) $row['hotel_id']);
        $this->assertSame('Diwali offer', $row['title']);
        $this->assertSame(15, (int) $row['duration']);
        $this->assertSame('image/png', $row['mime_type']);
        $this->assertStringStartsWith('h1/media/', $row['file_path']);
        $file = HC_ROOT . '/uploads/' . $row['file_path'];
        $this->assertFileExists($file);
        $this->assertSame([1920, 1080], array_slice(getimagesize($file), 0, 2));
        $this->assertFileExists(HC_ROOT . '/uploads/' . $row['thumb_path']);
        $this->assertSame('image', ContentManager::toTvItem($row)['type']);
        $this->assertStringContainsString($row['file_path'], ContentManager::toTvItem($row)['url']);

        // Re-save: same item, new file, old file removed, no duplicate.
        [$code, $j] = self::save($s, $id, 'Diwali offer v2', self::design('Updated'));
        $this->assertSame(200, $code, json_encode($j));
        $this->assertFalse($j['data']['created']);
        $this->assertSame($id, (int) $j['data']['id']);
        $row2 = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $id]);
        $this->assertSame('Diwali offer v2', $row2['title']);
        $this->assertNotSame($row['file_path'], $row2['file_path']);
        $this->assertFileDoesNotExist($file);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $row2['file_path']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title LIKE 'Diwali offer%'"));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM designs WHERE content_id = :id', ['id' => $id]));

        // Content library sends designer slides to the designer; ?raw=1 keeps the image form.
        [$code, , , $head] = $s->get('content.php?action=edit&id=' . $id);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('designer.php?id=' . $id, $head);
        [$code, , $html] = $s->get('content.php?action=edit&raw=1&id=' . $id);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('id="file"', $html);

        // Deleting the item removes its design (FK cascade).
        Tenant::set(1);
        ContentManager::deleteItem($id);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM designs WHERE content_id = :id', ['id' => $id]));
    }

    public function testDesignJsonRoundTrips(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        $design = self::design('નમસ્તે हिन्दी "quoted" </script><b>x</b>');
        [$code, $j] = self::save($s, 0, 'Round trip', $design);
        $this->assertSame(200, $code);
        $id = (int) $j['data']['id'];

        $stored = json_decode((string) Designer::designFor($id)['data'], true);
        unset($design['width']); // unknown canvas keys are dropped
        $this->assertSame($design, $stored);

        [$code, , $html] = $s->get('designer.php?id=' . $id);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('</script><b>x</b>', $html, 'JSON is safely embedded');
        $cfg = self::editorConfig($html);
        $this->assertSame($id, $cfg['id']);
        $this->assertSame('Round trip', $cfg['title']);
        $this->assertSame(15, $cfg['duration']);
        $this->assertSame($design, $cfg['design']);
        $this->assertGreaterThanOrEqual(12, count($cfg['starters']));
        $this->assertNotEmpty($cfg['fonts']);
        $this->assertStringContainsString('@font-face{font-family:"Noto Sans Gujarati"', $html, 'Gujarati web font from assets/fonts');
        $this->assertStringContainsString('vendor/fabric/fabric.min.js', $html);
        $this->assertFileExists(HC_ROOT . '/assets/vendor/fabric/LICENSE');
        $this->assertFileExists(HC_ROOT . '/assets/vendor/pdfjs/LICENSE');

        // Plain image items (not made in the designer) are not opened in the designer.
        $plain = DB::insert('content_items', ['title' => 'Plain', 'type' => 'image', 'url' => 'https://x.test/a.jpg', 'duration' => 5]);
        [$code, , , $head] = $s->get('designer.php?id=' . $plain);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('content.php?action=edit&id=' . $plain, $head);
        [$code, $j] = self::save($s, $plain, 'Plain re-save');
        $this->assertSame(200, $code, 'an own image item can be replaced by a design');

        // Starter template link.
        [$code, , $html] = $s->get('designer.php?tpl=diwali');
        $this->assertSame(200, $code);
        $this->assertSame('starter:diwali', self::editorConfig($html)['start']);
    }

    public function testValidation(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        $before = (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = 1');

        // Not a PNG: a JPEG, a text file renamed .png, a PNG with a lying name is fine.
        [$code, $j] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => json_encode(self::design())], ['png' => [self::jpeg(), 'image/png', 'slide.png']]);
        $this->assertSame(422, $code);
        $this->assertSame('VALIDATION_ERROR', $j['error']['code']);
        $txt = self::$tmp . '/evil.png';
        file_put_contents($txt, '<?php echo 1; ?>');
        [$code] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => json_encode(self::design())], ['png' => [$txt, 'image/png', 'slide.png']]);
        $this->assertSame(422, $code);
        [$code] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => json_encode(self::design())]);
        $this->assertSame(422, $code, 'missing image');

        // Too big: over the server upload limit and over the 8 MB rule (in-process).
        [$code, $j] = self::save($s, 0, 'Huge', null, self::png(64, 64, 3 * 1024 * 1024));
        $this->assertSame(413, $code, json_encode($j));
        try {
            Designer::checkImage(['name' => 'a.png', 'tmp_name' => self::png(10, 10), 'size' => 9 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]);
            $this->fail('8 MB limit');
        } catch (RuntimeException $e) {
            $this->assertSame(413, $e->getCode());
        }
        try {
            Designer::checkImage(['name' => 'a.png', 'tmp_name' => self::png(5000, 10), 'size' => 100, 'error' => UPLOAD_ERR_OK]);
            $this->fail('dimension limit');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // Design JSON: invalid, list, > 1 MB.
        foreach (['not json', '[1,2]', '{"objects":"x"}', ''] as $bad) {
            [$code] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => $bad], ['png' => [self::png(), 'image/png', 'slide.png']]);
            $this->assertSame(422, $code, $bad);
        }
        $big = json_encode(['objects' => [['type' => 'Textbox', 'text' => str_repeat('a', 1024 * 1024)]]]);
        [$code] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => $big], ['png' => [self::png(), 'image/png', 'slide.png']]);
        $this->assertSame(413, $code);
        [$code] = self::save($s, 0, '   ');
        $this->assertSame(422, $code, 'title required');

        // Foreign item id → 404, the other hotel's item is untouched.
        $foreign = Tenant::run(2, fn () => DB::insert('content_items', ['title' => 'B-SECRET', 'type' => 'image', 'url' => 'https://b.test/x.jpg', 'duration' => 5]));
        [$code, $j] = self::save($s, $foreign, 'HACKED');
        $this->assertSame(404, $code);
        $this->assertSame('B-SECRET', DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $foreign]));
        [$code] = self::save($s, 999999, 'Missing');
        $this->assertSame(404, $code);
        // Non-image own item → 422.
        $ann = DB::insert('content_items', ['title' => 'Ann', 'type' => 'announcement', 'body' => 'x', 'duration' => 5]);
        [$code] = self::save($s, $ann, 'Ann');
        $this->assertSame(422, $code);

        // Missing CSRF → 419.
        [$code, $j] = self::post($s, 'designer_save', ['id' => 0, 'title' => 'X', 'design' => json_encode(self::design())], ['png' => [self::png(), 'image/png', 'slide.png']], false);
        $this->assertSame(419, $code);
        [$code] = self::post($s, 'designer_pdfpage', ['batch' => str_repeat('a', 32), 'page' => 1], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']], false);
        $this->assertSame(419, $code);
        // GET is refused.
        [$code] = $s->ajax('designer_save');
        $this->assertSame(405, $code);

        $this->assertSame($before + 1, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = 1'), 'only the announcement fixture was added');
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPdfPagesCreateItemsAndPlaylistIdempotently(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        $batch = bin2hex(random_bytes(16));
        $ids = [];
        foreach ([1, 2, 3] as $p) {
            [$code, $j] = self::post($s, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'Hotel deck.pdf', 'page' => $p, 'duration' => 12],
                ['image' => $p === 2 ? [self::png(1920, 2716), 'image/png', 'p2.png'] : [self::jpeg(), 'image/jpeg', 'p.jpg']]);
            $this->assertSame(200, $code, json_encode($j));
            $this->assertTrue($j['data']['created']);
            $ids[$p] = (int) $j['data']['id'];
        }
        // Retry of page 2 (e.g. the response was lost): same item, nothing new.
        [$code, $j] = self::post($s, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'Hotel deck.pdf', 'page' => 2, 'duration' => 12], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(200, $code);
        $this->assertFalse($j['data']['created']);
        $this->assertSame($ids[2], (int) $j['data']['id']);
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title LIKE 'Hotel deck – page %'"));
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $ids[1]]);
        $this->assertSame('Hotel deck – page 1', $row['title']);
        $this->assertSame('image', $row['type']);
        $this->assertSame(12, (int) $row['duration']);
        $this->assertSame('image/jpeg', $row['mime_type']);
        $p2 = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $ids[2]]);
        $this->assertSame([1920, 2716], array_slice(getimagesize(HC_ROOT . '/uploads/' . $p2['file_path']), 0, 2), 'original page shape kept');

        // Playlist in page order; a retry returns the same playlist.
        [$code, $j] = self::post($s, 'designer_pdfplaylist', ['batch' => $batch, 'name' => '', 'duration' => 12, 'transition' => 'slide']);
        $this->assertSame(200, $code, json_encode($j));
        $this->assertTrue($j['data']['created']);
        $this->assertSame(3, $j['data']['items']);
        $pid = (int) $j['data']['id'];
        $pl = DB::one('SELECT * FROM content_playlists WHERE id = :id', ['id' => $pid]);
        $this->assertSame('Hotel deck', $pl['name']);
        $this->assertSame('slide', $pl['transition']);
        $this->assertSame(1, (int) $pl['hotel_id']);
        $this->assertSame(array_values($ids), array_map('intval', DB::column('SELECT content_id FROM playlist_items WHERE playlist_id = :p ORDER BY sort_order', ['p' => $pid])));
        $this->assertSame([12, 12, 12], array_map('intval', DB::column('SELECT duration FROM playlist_items WHERE playlist_id = :p ORDER BY sort_order', ['p' => $pid])));
        [$code, $j] = self::post($s, 'designer_pdfplaylist', ['batch' => $batch, 'name' => 'Again', 'duration' => 12]);
        $this->assertSame(200, $code);
        $this->assertFalse($j['data']['created']);
        $this->assertSame($pid, (int) $j['data']['id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM content_playlists WHERE name IN ('Hotel deck', 'Again')"));
        Tenant::set(1);
        $this->assertCount(3, ContentManager::playlistItems($pid));

        // Limits and bad input.
        [$code] = self::post($s, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'x.pdf', 'page' => 101], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(422, $code, 'max 100 pages');
        [$code] = self::post($s, 'designer_pdfpage', ['batch' => 'nope', 'file_name' => 'x.pdf', 'page' => 1], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(422, $code, 'bad batch id');
        [$code] = self::post($s, 'designer_pdfpage', ['batch' => bin2hex(random_bytes(16)), 'file_name' => 'x.pdf', 'page' => 1], ['image' => [self::$tmp . '/evil.png', 'image/png', 'p.png']]);
        $this->assertSame(422, $code, 'not an image');
        [$code] = self::post($s, 'designer_pdfplaylist', ['batch' => bin2hex(random_bytes(16)), 'name' => 'x']);
        $this->assertSame(404, $code, 'unknown batch');

        // Staff cannot import.
        $staff = new AdminSession(self::$url, 'dzStaff');
        [$code] = self::post($staff, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'x.pdf', 'page' => 4], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(403, $code);
    }

    public function testHotelTemplatesAndImageUpload(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        [$code, $j] = self::post($s, 'designer_template', ['name' => 'Our offer ' . self::XSS, 'design' => json_encode(self::design('Template text'))]);
        $this->assertSame(200, $code, json_encode($j));
        $tid = (int) $j['data']['id'];
        // Same name replaces.
        [$code, $j] = self::post($s, 'designer_template', ['name' => 'Our offer ' . self::XSS, 'design' => json_encode(self::design('Template v2'))]);
        $this->assertSame($tid, (int) $j['data']['id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM designs WHERE kind = 'template' AND hotel_id = 1"));

        [$code, , $html] = $s->get('designer.php?template=' . $tid);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString(self::XSS, $html);
        $cfg = self::editorConfig($html);
        $this->assertSame('template:' . $tid, $cfg['start']);
        $this->assertSame('Our offer ' . self::XSS, $cfg['templates'][0]['name']);
        $this->assertSame('Template v2', $cfg['templates'][0]['design']['objects'][0]['text']);

        // Upload a picture from the designer: lands in the content library.
        [$code, $j] = self::post($s, 'designer_image', [], ['file' => [self::jpeg(800, 600), 'image/jpeg', 'Lobby photo.jpg']]);
        $this->assertSame(200, $code, json_encode($j));
        $this->assertStringStartsWith('../uploads/h1/media/', $j['data']['url']);
        $this->assertSame('Lobby photo', DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $j['data']['id']]));
        [$code] = self::post($s, 'designer_image', [], ['file' => [self::$tmp . '/evil.png', 'image/png', 'x.png']]);
        $this->assertSame(422, $code);
        [, , $html] = $s->get('designer.php');
        $this->assertContains('Lobby photo', array_column(self::editorConfig($html)['library'], 'title'));

        // Another hotel cannot delete it; the owner can.
        $s2 = new AdminSession(self::$url, 'dzMgr2');
        [$code] = self::post($s2, 'designer_tpldelete', ['id' => $tid]);
        $this->assertSame(404, $code);
        [$code] = self::post($s, 'designer_tpldelete', ['id' => $tid]);
        $this->assertSame(200, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM designs WHERE kind = 'template'"));
    }

    public function testTenancyIsolation(): void
    {
        $s1 = new AdminSession(self::$url, 'dzMgr');
        [$code, $j] = self::save($s1, 0, 'H1 slide');
        $id1 = (int) $j['data']['id'];
        $batch = bin2hex(random_bytes(16));
        [$code] = self::post($s1, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'h1.pdf', 'page' => 1], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(200, $code);

        $s2 = new AdminSession(self::$url, 'dzMgr2');
        [$code] = $s2->get('designer.php?id=' . $id1);
        $this->assertSame(404, $code);
        [$code] = self::save($s2, $id1, 'HACKED');
        $this->assertSame(404, $code);
        $this->assertSame('H1 slide', DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $id1]));
        // Hotel 2 cannot build a playlist from hotel 1's batch …
        [$code] = self::post($s2, 'designer_pdfplaylist', ['batch' => $batch, 'name' => 'Steal']);
        $this->assertSame(404, $code);
        // … and the same batch id in hotel 2 is a separate import.
        [$code, $j] = self::post($s2, 'designer_pdfpage', ['batch' => $batch, 'file_name' => 'h2.pdf', 'page' => 1], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(200, $code);
        $this->assertTrue($j['data']['created']);
        $this->assertSame(2, (int) DB::value('SELECT hotel_id FROM content_items WHERE id = :id', ['id' => $j['data']['id']]));
        [, $j] = self::save($s2, 0, 'H2 slide');
        $id2 = (int) $j['data']['id'];
        $row = DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $id2]);
        $this->assertSame(2, (int) $row['hotel_id']);
        $this->assertStringStartsWith('h2/media/', $row['file_path']);
        $this->assertSame(2, (int) DB::value('SELECT hotel_id FROM designs WHERE content_id = :id', ['id' => $id2]));

        // Hotel 2's editor lists only its own images.
        [, , $html] = $s2->get('designer.php');
        $titles = array_column(self::editorConfig($html)['library'], 'title');
        $this->assertContains('H2 slide', $titles);
        $this->assertNotContains('H1 slide', $titles);
    }

    public function testPagesRenderForRoles(): void
    {
        foreach (['dzBoss', 'dzMgr'] as $u) {
            $s = new AdminSession(self::$url, $u);
            foreach (['designer.php', 'pdf_import.php', 'content.php'] as $page) {
                [$code, , $html] = $s->get($page);
                $this->assertSame(200, $code, "$u $page");
                $this->assertFalse(TestEnv::hasPhpError($html), "$u $page");
            }
            [, , $html] = $s->get('content.php');
            $this->assertStringContainsString('designer.php', $html);
            $this->assertStringContainsString('pdf_import.php', $html);
            [, , $html] = $s->get('pdf_import.php');
            $this->assertStringContainsString('vendor/pdfjs/pdf.worker.min.js', $html);
            $this->assertStringContainsString('Save as PDF', $html);
        }
        $staff = new AdminSession(self::$url, 'dzStaff');
        foreach (['designer.php', 'pdf_import.php'] as $page) {
            [$code, , $html] = $staff->get($page);
            $this->assertSame(403, $code, $page);
            $this->assertFalse(TestEnv::hasPhpError($html));
        }
        [$code, , $html] = $staff->get('content.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('designer.php', $html);
        [$code] = self::save($staff, 0, 'Staff slide');
        $this->assertSame(403, $code);

        // Gujarati UI.
        $s = new AdminSession(self::$url, 'dzMgr');
        $s->ajax('set_language', ['lang' => 'gu']);
        [, , $html] = $s->get('designer.php');
        $this->assertStringContainsString(I18n::translate('Design a slide', 'gu'), $html);
        $this->assertNotSame('Design a slide', I18n::translate('Design a slide', 'gu'));
        [, , $html] = $s->get('pdf_import.php');
        $this->assertFalse(TestEnv::hasPhpError($html));
        $s->ajax('set_language', ['lang' => 'en']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testXssInTitles(): void
    {
        $s = new AdminSession(self::$url, 'dzMgr');
        [$code, $j] = self::save($s, 0, 'Slide ' . self::XSS);
        $this->assertSame(200, $code);
        $id = (int) $j['data']['id'];
        $this->assertSame('Slide ' . self::XSS, DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $id]));
        $batch = bin2hex(random_bytes(16));
        [$code] = self::post($s, 'designer_pdfpage', ['batch' => $batch, 'file_name' => self::XSS . '.pdf', 'page' => 1], ['image' => [self::jpeg(), 'image/jpeg', 'p.jpg']]);
        $this->assertSame(200, $code);
        [$code] = self::post($s, 'designer_pdfplaylist', ['batch' => $batch, 'name' => '']);
        $this->assertSame(200, $code);

        foreach (['content.php', 'content.php?view=list', 'designer.php?id=' . $id, 'designer.php', 'playlists.php'] as $page) {
            [$code, , $html] = $s->get($page);
            $this->assertSame(200, $code, $page);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $page);
            $this->assertStringNotContainsString('<img src=x', $html, $page);
        }
        [, , $html] = $s->get('designer.php?id=' . $id);
        $this->assertStringContainsString('value="Slide &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame('Slide ' . self::XSS, self::editorConfig($html)['title']);
    }
}
