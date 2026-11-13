<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.7 Google Drive slideshow (core/GoogleDrive.php, core/Apps/GoogleDriveApp.php): folder id from every link
 * form, embedded-folder-view parsing (photos + videos, sub-folders / documents skipped), Drive API v3 listing with
 * a key, media links, error texts (private / not found / empty), last good list kept while Google is down, the
 * TV page + data JSON over HTTP (mocked Google), validation, the app is part of the content-apps module, and
 * the Super Admin API key field.
 */
final class GoogleDriveTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    private const FOLDER = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';
    private const FOLDER_PRIVATE = '1PrivateFolderPrivateFolder00000';

    public static function embeddedHtml(): string
    {
        $entry = static fn (string $id, string $name, string $href) => '<div class="flip-entry" id="entry-' . $id . '" tabindex="0" role="link"><div class="flip-entry-info"><a href="' . $href . '" target="_blank">'
            . '<div class="flip-entry-thumb"></div><div class="flip-entry-title">' . $name . '</div></a></div></div>';
        return '<html><body><div class="flip-entries">'
            . $entry('1photoB0000000000000000000001', 'b-aarti.JPG', 'https://drive.google.com/file/d/1photoB0000000000000000000001/view?usp=drive_web')
            . $entry('1photoA0000000000000000000001', 'a-darshan &amp; shringar.jpg', 'https://drive.google.com/file/d/1photoA0000000000000000000001/view?usp=drive_web')
            . $entry('1video00000000000000000000001', 'c-mangla.mp4', 'https://drive.google.com/file/d/1video00000000000000000000001/view?usp=drive_web')
            . $entry('1subfolder000000000000000001', 'Old photos', 'https://drive.google.com/drive/folders/1subfolder000000000000000001')
            . $entry('1docxfile0000000000000000001', 'timings.docx', 'https://drive.google.com/file/d/1docxfile0000000000000000001/view')
            . '</div></body></html>';
    }

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        DB::insert('users', ['hotel_id' => null, 'username' => 'gdroot', 'email' => 'gdroot@platform.test', 'full_name' => 'Root',
            'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'gdboss', 'email' => 'gdboss@hotel.test', 'full_name' => 'Boss',
            'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'super_admin']);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::flush();
        Cache::clear();
        self::$mockFile = tempnam(sys_get_temp_dir(), 'gdmock');
        file_put_contents(self::$mockFile, json_encode([
            'https://drive.google.com/embeddedfolderview?id=' . self::FOLDER => ['status' => 200, 'body' => self::embeddedHtml()],
            'https://drive.google.com/embeddedfolderview?id=' . self::FOLDER_PRIVATE => ['status' => 200, 'body' => '<a href="https://accounts.google.com/ServiceLogin?continue=x">Sign in</a>'],
        ]));
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url, ['http_mock_file' => self::$mockFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Http::$mock = null;
        @unlink(self::$mockFile);
        Cache::clear('gdrive');
    }

    protected function setUp(): void
    {
        Http::$mock = null;
        Cache::clear('gdrive');
        DB::query("DELETE FROM system_settings WHERE hotel_id = 0 AND setting_key = 'google_api_key'");
        Settings::flush();
    }

    public function testFolderIdFromEveryLinkForm(): void
    {
        $id = self::FOLDER;
        foreach (["https://drive.google.com/drive/folders/$id?usp=sharing", "https://drive.google.com/drive/u/1/folders/$id", "https://drive.google.com/open?id=$id",
            "https://drive.google.com/embeddedfolderview?id=$id", $id] as $link) {
            $this->assertSame($id, GoogleDrive::folderId($link), $link);
        }
        foreach (['https://drive.google.com/file/d/1abcdefghijklmnop/view', 'https://example.com/drive/folders/' . $id, 'hello', ''] as $bad) {
            $this->assertNull(GoogleDrive::folderId($bad), $bad);
        }
        $this->assertSame('0-abcDEF', GoogleDrive::resourceKey("https://drive.google.com/drive/folders/$id?resourcekey=0-abcDEF"));
    }

    public function testDriveFileLinksInVideoAndImageContent(): void
    {
        $fid = '1fileVideo0000000000000000001';
        foreach (["https://drive.google.com/file/d/$fid/view?usp=sharing", "https://drive.google.com/open?id=$fid", "https://drive.google.com/uc?id=$fid&export=download",
            "https://drive.usercontent.google.com/download?id=$fid&export=download"] as $link) {
            $this->assertSame($fid, GoogleDrive::fileId($link), $link);
        }
        $this->assertNull(GoogleDrive::fileId('https://drive.google.com/drive/folders/' . self::FOLDER));
        $this->assertNull(GoogleDrive::fileId('https://example.com/file/d/' . $fid));

        // TV item: the Drive viewer page becomes the media file.
        $v = ContentManager::toTvItem(['id' => 1, 'type' => 'video', 'title' => 'v', 'duration' => 0, 'file_path' => null, 'url' => "https://drive.google.com/file/d/$fid/view?usp=sharing", 'settings' => '{}']);
        $this->assertSame("https://drive.usercontent.google.com/download?id=$fid&export=download&confirm=t", $v['url']);
        $i = ContentManager::toTvItem(['id' => 2, 'type' => 'image', 'title' => 'i', 'duration' => 10, 'file_path' => null, 'url' => "https://drive.google.com/file/d/$fid/view", 'settings' => '{}']);
        $this->assertSame("https://lh3.googleusercontent.com/d/$fid=w1920-h1080", $i['url']);
        $other = ContentManager::toTvItem(['id' => 3, 'type' => 'video', 'title' => 'o', 'duration' => 0, 'file_path' => null, 'url' => 'https://cdn.example.com/a.mp4', 'settings' => '{}']);
        $this->assertSame('https://cdn.example.com/a.mp4', $other['url']);

        // Saving a video with a folder / Docs link: clear message, nothing saved.
        [, $errors] = ContentManager::validate(['title' => 'Folder', 'url' => 'https://drive.google.com/drive/folders/' . self::FOLDER], 'video', false);
        $this->assertStringContainsString('Google Drive slideshow', implode(' ', $errors));
        [, $errors] = ContentManager::validate(['title' => 'Doc', 'url' => 'https://docs.google.com/document/d/1docdocdocdocdoc/edit'], 'video', false);
        $this->assertNotEmpty($errors);
        [, $errors] = ContentManager::validate(['title' => 'File', 'url' => "https://drive.google.com/file/d/$fid/view"], 'video', false);
        $this->assertSame([], $errors);
    }

    public function testParsesThePublicFolderPage(): void
    {
        $files = GoogleDrive::parseEmbedded(self::embeddedHtml());
        $this->assertSame(['b-aarti.JPG', 'a-darshan & shringar.jpg', 'c-mangla.mp4'], array_column($files, 'name'));
        $this->assertSame(['image', 'image', 'video'], array_column($files, 'kind'));

        Http::$mock = fn (string $m, string $url) => str_contains($url, 'embeddedfolderview') ? ['status' => 200, 'body' => self::embeddedHtml(), 'headers' => []] : null;
        $r = GoogleDrive::folder('https://drive.google.com/drive/folders/' . self::FOLDER . '?usp=sharing');
        $this->assertSame('', $r['error']);
        $this->assertSame(['a-darshan & shringar.jpg', 'b-aarti.JPG', 'c-mangla.mp4'], array_column($r['items'], 'name'), 'sorted by name');
        $this->assertSame('https://lh3.googleusercontent.com/d/1photoA0000000000000000000001=w1920-h1080', $r['items'][0]['url']);
        $this->assertSame('https://drive.usercontent.google.com/download?id=1video00000000000000000000001&export=download&confirm=t', $r['items'][2]['url']);
    }

    public function testApiKeyListingAndErrors(): void
    {
        Settings::setPlatform('google_api_key', 'AIzaTestKeyTestKeyTestKey1234567');
        Settings::flush();
        $seen = [];
        Http::$mock = function (string $m, string $url) use (&$seen) {
            $seen[] = $url;
            if (str_contains($url, 'googleapis.com/drive/v3/files?')) {
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['files' => [
                    ['id' => '1apiPhoto000000000000000001', 'name' => 'IMG_0001', 'mimeType' => 'image/jpeg'],
                    ['id' => '1apiVideo000000000000000001', 'name' => 'aarti', 'mimeType' => 'video/mp4'],
                    ['id' => '1apiSheet000000000000000001', 'name' => 'Sheet', 'mimeType' => 'application/vnd.google-apps.spreadsheet'],
                ]])];
            }
            return null;
        };
        $r = GoogleDrive::folder(self::FOLDER);
        $this->assertSame(['aarti', 'IMG_0001'], array_column($r['items'], 'name'));
        $this->assertStringContainsString('in parents', urldecode($seen[0]));
        $this->assertStringStartsWith('https://www.googleapis.com/drive/v3/files/1apiVideo000000000000000001?alt=media', $r['items'][0]['url']);

        Cache::clear('gdrive');
        Http::$mock = fn () => ['status' => 404, 'body' => '{}', 'headers' => []];
        $this->assertSame('not_found', GoogleDrive::folder(self::FOLDER)['error']);
        Cache::clear('gdrive');
        Http::$mock = fn () => ['status' => 403, 'body' => '{}', 'headers' => []];
        $this->assertSame('private', GoogleDrive::folder(self::FOLDER)['error']);
        $this->assertStringContainsString('Anyone with the link', GoogleDrive::errorText('private'));
    }

    public function testLastGoodListIsKeptWhileGoogleIsDown(): void
    {
        Http::$mock = fn () => ['status' => 200, 'body' => self::embeddedHtml(), 'headers' => []];
        $this->assertCount(3, GoogleDrive::folder(self::FOLDER)['items']);
        // Cache expired + network error → the last good list.
        foreach (glob(HC_ROOT . '/storage/cache/gdrive/*') ?: [] as $f) {
            if (!str_contains(basename($f), 'last') && !str_contains((string) file_get_contents($f), 'zzz')) {
                touch($f, time() - 3600);
            }
        }
        Http::$mock = fn () => ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'timeout'];
        $r = GoogleDrive::folder(self::FOLDER);
        $this->assertCount(3, $r['items']);
    }

    public function testTvPageAndDataOverHttp(): void
    {
        $item = DisplayAppsTestKit::createItem('gdrive_slideshow', ['link' => 'https://drive.google.com/drive/folders/' . self::FOLDER, 'names' => true, 'heading' => '<b>Darshan</b>']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringContainsString('gdrive_slideshow.js', $html);
        $this->assertStringContainsString('id="gdVideo"', $html);
        $this->assertStringContainsString('&lt;b&gt;Darshan&lt;/b&gt;', $html);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertSame(60, $json['refresh_sec']);
        $this->assertSame(['image', 'image', 'video'], array_column($json['data']['items'], 'kind'));
        $this->assertSame('a-darshan & shringar', $json['data']['items'][0]['caption']);

        // Videos off; private folder → message, no items.
        $noVid = DisplayAppsTestKit::createItem('gdrive_slideshow', ['link' => self::FOLDER, 'videos' => false]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $noVid);
        $this->assertSame(['image', 'image'], array_column($json['data']['items'], 'kind'));
        $onlyVid = DisplayAppsTestKit::createItem('gdrive_slideshow', ['link' => self::FOLDER, 'show' => 'videos']);
        [, $json] = DisplayAppsTestKit::data(self::$url, $onlyVid);
        $this->assertSame(['video'], array_column($json['data']['items'], 'kind'));
        $onlyPhotos = DisplayAppsTestKit::createItem('gdrive_slideshow', ['link' => self::FOLDER, 'show' => 'photos']);
        [, $json] = DisplayAppsTestKit::data(self::$url, $onlyPhotos);
        $this->assertSame(['image', 'image'], array_column($json['data']['items'], 'kind'));
        $priv = DisplayAppsTestKit::createItem('gdrive_slideshow', ['link' => self::FOLDER_PRIVATE]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $priv);
        $this->assertSame([], $json['data']['items']);
        $this->assertStringContainsString('Anyone with the link', $json['data']['empty']);
    }

    public function testDraftPreviewNeverCallsGoogle(): void
    {
        $calls = 0;
        Http::$mock = function () use (&$calls) { $calls++; return ['status' => 200, 'body' => self::embeddedHtml(), 'headers' => []]; };
        $app = DisplayApps::find('gdrive_slideshow');
        GoogleDrive::$readOnly = true;
        try {
            [, $errors] = $app->validate(['link' => self::FOLDER]);
            $this->assertSame([], $errors);
            $this->assertSame([], $app->data($app->config(['link' => self::FOLDER]), ['preview' => false])['items']);
        } finally {
            GoogleDrive::$readOnly = false;
        }
        // The form and a preview context use the cache only.
        $this->assertStringContainsString('Save to read the folder', $app->form($app->config(['link' => self::FOLDER])));
        $this->assertSame([], $app->data($app->config(['link' => self::FOLDER]), ['preview' => true])['items']);
        $this->assertSame(0, $calls, 'no request to Google while typing / previewing');
        // Saving (validate) reads the folder once; then the form shows the counts from the cache.
        $app->validate(['link' => self::FOLDER]);
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('2 photo(s) and 1 video(s) found', $app->form($app->config(['link' => self::FOLDER])));
        $this->assertCount(3, $app->data($app->config(['link' => self::FOLDER]), ['preview' => true])['items']);
        $this->assertSame(1, $calls);
    }

    public function testAdminPreviewOverHttpIsFast(): void
    {
        // The preview op (sent while typing) must not reach Google: the mock file has no entry for this folder,
        // so a real request would fail slowly; the preview answers at once.
        $boss = new AdminSession(self::$url, 'gdboss');
        $t = microtime(true);
        [$s, , $html] = $boss->post('apps.php', ['op' => 'preview', 'app' => 'gdrive_slideshow', 'title' => 'Drive', 'duration' => 30,
            'cfg[link]' => 'https://drive.google.com/drive/folders/1NotInTheMockFile000000000000', 'cfg[interval]' => 8]);
        $this->assertLessThan(3.0, microtime(true) - $t);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
    }

    public function testValidationModuleAndApiKeySetting(): void
    {
        $app = DisplayApps::find('gdrive_slideshow');
        $this->assertNotNull($app);
        [, $errors] = $app->validate(['link' => 'https://example.com/photos']);
        $this->assertNotEmpty($errors);
        [$cfg, $errors] = $app->validate(['link' => 'https://drive.google.com/drive/folders/' . self::FOLDER, 'interval' => 999, 'video_max' => -5]);
        $this->assertSame([], $errors);
        $this->assertSame(120, $cfg['interval']);
        $this->assertSame(0, $cfg['video_max']);
        $feature = null;
        foreach (Features::all() as $k => $f) {
            if (in_array('gdrive_slideshow', $f['apps'] ?? [], true)) {
                $feature = $k;
            }
        }
        $this->assertNotNull($feature, 'part of a plan module');

        $root = new AdminSession(self::$url, 'gdroot');
        [$s, , $html] = $root->get('platform_settings.php?tab=data_feeds');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('id="googleKeyForm"', $html);
        $root->post('platform_settings.php', ['op' => 'google_key', 'tab' => 'data_feeds', 'google_api_key' => 'not a key!']);
        Settings::flush();
        $this->assertSame('', GoogleDrive::apiKey());
        $root->post('platform_settings.php', ['op' => 'google_key', 'tab' => 'data_feeds', 'google_api_key' => 'AIzaRealLookingKey0123456789abcd']);
        Settings::flush();
        $this->assertSame('AIzaRealLookingKey0123456789abcd', GoogleDrive::apiKey());
    }
}
