<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Security review of 2.3 (docs/SECURITY.md "2.3 additions"): regression tests for the fixed findings.
 *  1. Draft app preview (admin/apps.php op=preview, sent while typing) created data feeds and fetched them
 *     inline: one hotel spent the shared platform API budget (daily caps) of every hotel.
 *  2. Data feeds "Refresh now" ignored TTL / back-off on feeds that use the platform key: a few clicks in
 *     one hotel used up a provider's daily cap for all hotels.
 *  3. Public guest photo upload kept GIF files byte for byte (no re-encode), so anonymous guests could store
 *     polyglot / appended payloads on the hotel's domain.
 */
final class SecurityRegressionTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    /** @var array<string, int> */
    private static array $id = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'srMgr', 'super_admin' => 'srBoss'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'srBoss2', 'email' => 'sb2@t.test', 'password' => 'Passw0rd!']);
        Tenant::set(1);
        DisplayApps::reset();
        DataFeeds::flush();
        Cache::clear();
        foreach (['aviationstack', 'goldapi'] as $p) {
            DataFeeds::setPlatformKey($p, 'PLATFORM-KEY-' . $p);
        }
        // The server never reaches the internet: every request is answered by this file (HTTP 503).
        self::$mockFile = HC_ROOT . '/storage/http_mock_security.json';
        file_put_contents(self::$mockFile, json_encode(['https://' => ['status' => 503, 'body' => '{}'], 'http://' => ['status' => 503, 'body' => '{}']]));
        Settings::setPlatform('task_last_DataFeedsTask', (string) (time() + 86400)); // no background run inside the server
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url, ['http_mock_file' => self::$mockFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Http::$mock = null;
        DataFeeds::$readOnly = false;
        DataFeeds::flush();
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
        Settings::flush();
        DataFeeds::flush();
    }

    private static function budgetUsed(string $p, int $owner = 0): int
    {
        return (int) DB::value('SELECT COALESCE(SUM(hits), 0) FROM rate_limits WHERE rl_key = :k', ['k' => 'feeds:day:' . $p . ':' . $owner]);
    }

    // ------------------------------------------------------------------ 1. draft preview vs shared feed budget

    public function testDraftPreviewNeverCreatesOrFetchesDataFeeds(): void
    {
        DB::query("DELETE FROM data_feeds WHERE provider = 'aviationstack'");
        $s = new AdminSession(self::$url, 'srMgr');
        // What the live preview sends while a flight number is typed: AI1, AI10, AI101.
        foreach (['AI1', 'AI10', 'AI101'] as $typed) {
            [$code, , $html] = $s->post('apps.php', [
                'op' => 'preview', 'id' => 0, 'app' => 'travel_status', 'title' => 'Flights', 'duration' => 20, 'is_active' => 1,
                'theme' => 'classic_dark', 'font' => 'auto', 'lang' => 'en', 'cfg[heading]' => 'Flights', 'cfg[flights]' => $typed,
            ]);
            $this->assertSame(200, $code);
            $this->assertStringContainsString('hc-app-travel_status', $html);
            $this->assertFalse(TestEnv::hasPhpError($html));
        }
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM data_feeds WHERE provider = 'aviationstack'"), 'no feed rows from drafts');
        $this->assertSame(0, self::budgetUsed('aviationstack'), 'no platform API budget spent by drafts');

        // A saved screen still gets its first value inline on the TV (one feed, budget used once).
        $item = DisplayAppsTestKit::createItem('travel_status', ['flights' => 'AI101']);
        DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM data_feeds WHERE provider = 'aviationstack'"));
        $this->assertSame(1, self::budgetUsed('aviationstack'));
        ContentManager::deleteItem((int) $item['id']);
    }

    // ------------------------------------------------------------------ 2. "Refresh now" vs shared feed budget

    public function testRefreshNowRespectsTtlOfSharedPlatformFeeds(): void
    {
        DB::query("DELETE FROM data_feeds WHERE provider = 'goldapi'");
        DB::query("DELETE FROM rate_limits WHERE rl_key LIKE 'feeds:%'");
        DataFeeds::get('goldapi'); // registers the shared feed (platform key, owner 0)
        $fk = DataFeeds::feedKey('goldapi', [], 0);
        $fresh = time() - 60;
        DB::query('UPDATE data_feeds SET data = :d, fetched_at = :f, attempted_at = :f2 WHERE feed_key = :k', ['d' => '{"gold_usd_g":80}', 'f' => $fresh, 'f2' => $fresh, 'k' => $fk]);

        $boss = new AdminSession(self::$url, 'srBoss');
        for ($i = 0; $i < 4; $i++) {
            [$code] = $boss->post('data_feeds.php', ['op' => 'refresh', 'provider' => 'goldapi']);
            $this->assertSame(302, $code);
        }
        $row = DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
        $this->assertSame($fresh, (int) $row['attempted_at'], 'a fresh shared feed is not fetched again');
        $this->assertSame(0, self::budgetUsed('goldapi'), 'the daily cap of all hotels is untouched');

        // Due (TTL over): "Refresh now" fetches once; the back-off after the error then blocks repeats.
        DB::query('UPDATE data_feeds SET fetched_at = :f WHERE feed_key = :k', ['f' => time() - 3 * 86400, 'k' => $fk]);
        $boss->post('data_feeds.php', ['op' => 'refresh', 'provider' => 'goldapi']);
        $boss->post('data_feeds.php', ['op' => 'refresh', 'provider' => 'goldapi']);
        $row = DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
        $this->assertGreaterThan($fresh, (int) $row['attempted_at']);
        $this->assertStringContainsString('503', (string) $row['error']);
        $this->assertSame(2, self::budgetUsed('goldapi'), 'one refresh = 2 requests (gold + silver)');

        // A hotel's own key has its own cap: "Refresh now" works at any time.
        DataFeeds::setHotelKey('goldapi', 'HOTEL-OWN-GOLD-KEY');
        DataFeeds::get('goldapi');
        $own = DataFeeds::feedKey('goldapi', [], 1);
        DB::query('UPDATE data_feeds SET fetched_at = :f WHERE feed_key = :k', ['f' => time() - 60, 'k' => $own]);
        $boss->post('data_feeds.php', ['op' => 'refresh', 'provider' => 'goldapi']);
        $this->assertSame(2, self::budgetUsed('goldapi', 1));
        DataFeeds::setHotelKey('goldapi', '');
    }

    // ------------------------------------------------------------------ 3. guest photo upload re-encodes GIFs

    public function testGuestGifUploadIsReEncoded(): void
    {
        $aid = Albums::create(['name' => 'Wedding', 'guest_upload' => 1, 'guest_moderation' => 0, 'guest_max' => 10]);
        $link = Albums::guestUrl(Albums::find($aid));
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        $f = tempnam(sys_get_temp_dir(), 'srgif') . '.gif';
        imagegif($im, $f);
        $marker = '<html><body><script>alert("HC-POLYGLOT")</script></body></html>';
        file_put_contents($f, $marker, FILE_APPEND);

        [$code, $json] = TestEnv::http('POST', self::$url . DisplayAppsTestKit::rel($link), null, ['X-Requested-With: XMLHttpRequest'], null, [
            'op' => 'upload', 'photo' => new CURLFile($f, 'image/gif', 'party.gif'),
        ]);
        $this->assertSame(200, $code, json_encode($json));
        $photo = Albums::photo((int) $json['data']['id']);
        $path = HC_ROOT . '/uploads/' . $photo['image_path'];
        $this->assertFileExists($path);
        $this->assertStringNotContainsString('HC-POLYGLOT', (string) file_get_contents($path), 'appended payload dropped');
        $this->assertStringEndsNotWith('.gif', (string) $photo['image_path']);
        $this->assertSame('image/png', (new finfo(FILEINFO_MIME_TYPE))->file($path));

        // Not an image at all, only named .gif → refused.
        file_put_contents($f, 'GIF89a' . $marker);
        [$code] = TestEnv::http('POST', self::$url . DisplayAppsTestKit::rel($link), null, ['X-Requested-With: XMLHttpRequest'], null, [
            'op' => 'upload', 'photo' => new CURLFile($f, 'image/gif', 'fake.gif'),
        ]);
        $this->assertSame(422, $code);
        $this->assertSame(1, Albums::count($aid));
        Albums::delete($aid);
        @unlink($f);
    }
}
