<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.2 public TV app download: /app (short link for the TV's Downloader app) and the landing page
 * "Download the TV app" section. Only the newest Super Admin release rolled out to all customers is offered;
 * customer uploads and "selected customers" releases never are; resume (Range) works; no release → no
 * section on the landing page and a 404 page at /app.
 */
final class AppDownloadTest extends TestCase
{
    private static string $url;
    /** @var string[] files written to storage/ */
    private static array $files = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        AppReleases::forget();
        Tenant::set(1);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $f) {
            @unlink(HC_ROOT . '/storage/' . $f);
        }
        DB::query('DELETE FROM apk_releases');
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM apk_release_hotels');
        DB::query('DELETE FROM apk_releases');
        Cache::clear('landing');
    }

    private static function release(int $code, string $name, ?int $hotelId = null, string $rollout = 'all', string $body = ''): int
    {
        $rel = 'apk/test-download-' . $code . '-' . bin2hex(random_bytes(3)) . '.apk';
        @mkdir(HC_ROOT . '/storage/apk', 0775, true);
        $body = $body !== '' ? $body : str_repeat('APK' . $code, 1000);
        file_put_contents(HC_ROOT . '/storage/' . $rel, $body);
        self::$files[] = $rel;
        return DB::insert('apk_releases', ['hotel_id' => $hotelId, 'version_name' => $name, 'version_code' => $code, 'file_path' => $rel,
            'file_size' => strlen($body), 'sha256' => hash('sha256', $body), 'notes' => '', 'is_required' => 0, 'rollout' => $rollout,
            'created_at' => now()]);
    }

    /** @return array{0:int,1:array,2:string,3:string} status, headers, body, raw headers */
    private static function get(string $path, array $headers = []): array
    {
        return TestEnv::http('GET', rtrim(self::$url, '/') . $path, null, $headers);
    }

    public function testNoReleaseNoSectionAnd404(): void
    {
        [$s, , $body] = self::get('/app/');
        $this->assertSame(404, $s);
        $this->assertFalse(TestEnv::hasPhpError($body), $body);
        $this->assertStringContainsString('not available for download', $body);
        [$s, , $html] = self::get('/');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('id="app"', $html);
        $this->assertStringNotContainsString('Download APK', $html);
    }

    public function testDownloadsNewestPlatformReleaseForAll(): void
    {
        self::release(15, '2.6.1');
        $id = self::release(16, '2.6.2', null, 'all', 'NEWEST-APK-BYTES');
        self::release(17, '2.6.3', null, 'selected');   // beta for selected customers: never public
        self::release(99, '9.9.9', 1);                  // old customer upload: never public
        [$s, , $body, $head] = self::get('/app/');
        $this->assertSame(200, $s, $body);
        $this->assertSame('NEWEST-APK-BYTES', $body);
        $this->assertMatchesRegularExpression('#content-type: application/vnd\.android\.package-archive#i', $head);
        $this->assertStringContainsString('filename="KrishnaCloud-TV-2.6.2.apk"', $head);
        $this->assertSame(1, (int) DB::value('SELECT downloads FROM apk_releases WHERE id = :id', ['id' => $id]));

        // Resume: the rest of the file, 206, not counted again.
        [$s, , $body, $head] = self::get('/app/', ['Range: bytes=7-']);
        $this->assertSame(206, $s);
        $this->assertSame('APK-BYTES', $body);
        $this->assertStringContainsString('bytes 7-15/16', $head);
        $this->assertSame(1, (int) DB::value('SELECT downloads FROM apk_releases WHERE id = :id', ['id' => $id]));
        [$s] = self::get('/app/', ['Range: bytes=99-']);
        $this->assertSame(416, $s);

        // Short link without the slash redirects to /app/.
        [$s, , , $head] = self::get('/app');
        $this->assertContains($s, [301, 302]);
        $this->assertMatchesRegularExpression('#location: [^\r\n]*/app/#i', $head);
    }

    public function testLandingShowsDownloadSectionInAllLanguages(): void
    {
        self::release(16, '2.6.2');
        [$s, , $html] = self::get('/');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringContainsString('id="app"', $html);
        $this->assertStringContainsString('href="#app"', $html);
        $this->assertStringContainsString('Version 2.6.2', $html);
        $this->assertMatchesRegularExpression('#<a class="btn btn-primary btn-lg" href="[^"]*/app/" download>#', $html);
        // The Downloader address is the site address + /app, without the scheme.
        $short = preg_replace('#^https?://#', '', rtrim(self::$url, '/')) . '/app';
        $this->assertStringContainsString('<code class="app-short">' . e($short) . '</code>', $html);
        [, , $gu] = self::get('/?lang=gu');
        $this->assertStringContainsString('TV એપ ડાઉનલોડ કરો', $gu);
        $this->assertStringContainsString('APK ડાઉનલોડ કરો', $gu);
        [, , $hi] = self::get('/?lang=hi');
        $this->assertStringContainsString('APK डाउनलोड करें', $hi);
    }

    public function testSelectedOnlyReleaseIsNotOffered(): void
    {
        self::release(16, '2.6.2', null, 'selected');
        [$s] = self::get('/app/');
        $this->assertSame(404, $s);
        [, , $html] = self::get('/');
        $this->assertStringNotContainsString('id="app"', $html);
    }

    public function testMissingFileIsNotOffered(): void
    {
        $id = self::release(16, '2.6.2');
        DB::query("UPDATE apk_releases SET file_path = 'apk/does-not-exist.apk' WHERE id = :id", ['id' => $id]);
        [$s] = self::get('/app/');
        $this->assertSame(404, $s);
        DB::query("UPDATE apk_releases SET file_path = '../config.php' WHERE id = :id", ['id' => $id]);
        [$s, , $body] = self::get('/app/');
        $this->assertSame(404, $s);
        $this->assertStringNotContainsString('<?php', $body);
    }
}
