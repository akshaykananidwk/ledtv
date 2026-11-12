<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.3 Live darshan library (core/LiveChannels.php, admin/platform_live.php, Templates → Live darshan):
 * the Dwarkadhish channel exists before anything is saved; Super Admin only (customers get 403); saving an
 * "all customers" channel adds one linked stream item to every customer (no duplicates on re-save) and to new
 * customers; changing the link updates every copy and the TV content (poll JSON) — a renamed copy keeps its
 * name; "on request" channels are added from Templates; delete unlinks; validation; translations.
 */
final class LiveChannelsTest extends TestCase
{
    private static string $url;
    private static array $h = [];
    private static array $sessions = [];
    private static array $tv = [];
    private const LINK1 = 'https://live.example.test/dwarka/index.m3u8';
    private const LINK2 = 'https://cdn2.example.test/dwarka/live.m3u8';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => null, 'username' => 'liveroot', 'email' => 'liveroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        Settings::set('hotel_name', 'Main Temple Trust');
        self::$h['main'] = 1;
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Dharamshala']);
        self::$h['beta'] = Hotels::create(['name' => 'Beta Hotel']);
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphaboss', 'email' => 'boss@alpha.test', 'password' => 'Passw0rd!'], 'super_admin');
        Tenant::run(self::$h['alpha'], static function (): void {
            DB::insert('rooms', ['room_number' => 'L1', 'name' => 'Lobby', 'floor' => '0']);
            // A customer's own live link the Super Admin can copy from.
            DB::insert('content_items', ['title' => 'Live Darshan', 'type' => 'stream', 'url' => self::LINK1, 'settings' => '{"mute":false}', 'duration' => 0, 'created_at' => now()]);
        });
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $key = (string) Settings::getFor(self::$h['alpha'], 'registration_key');
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'tv-live-a1-0001', 'room_number' => 'L1', 'registration_key' => $key, 'app_version' => '2.6.2', 'app_version_code' => 16]);
        self::assertSame(200, $s, (string) json_encode($j));
        self::$tv = ['uid' => 'tv-live-a1-0001', 'token' => $j['data']['token']];
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function copies(): array
    {
        return LiveChannels::linkedItems(LiveChannels::DWARKADHISH);
    }

    public function test1SeedAndAccess(): void
    {
        $all = LiveChannels::all();
        $this->assertArrayHasKey(LiveChannels::DWARKADHISH, $all);
        $this->assertSame('', $all[LiveChannels::DWARKADHISH]['url']);
        $this->assertSame([], LiveChannels::ready());

        [$s, , $html] = self::as('liveroot')->get('platform_live.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringContainsString('શ્રી દ્વારકાધીશ લાઇવ દર્શન', $html);
        $this->assertStringContainsString('Link missing', $html);
        $this->assertStringContainsString('Alpha Dharamshala · Live Darshan', $html); // copy-from picker
        $this->assertStringContainsString('platform_live.php', $html);                // console menu item

        [$s] = self::as('alphaboss')->get('platform_live.php');
        $this->assertSame(403, $s);
        [$s] = self::as('alphaboss')->post('platform_live.php', ['op' => 'save', 'title' => 'Hack', 'url' => self::LINK2, 'auto' => 1]);
        $this->assertSame(403, $s);
        $this->assertSame([], self::copies());
        // Without CSRF nothing is saved.
        [$s] = TestEnv::http('POST', self::$url . 'admin/platform_live.php', null, [], self::as('liveroot')->jar, ['op' => 'save', 'id' => 'dwarkadhish', 'title' => 'X', 'url' => self::LINK1]);
        $this->assertNotSame(302, $s);
        $this->assertSame('', LiveChannels::all()[LiveChannels::DWARKADHISH]['url']);
    }

    public function test2SaveAddsToEveryCustomerOnce(): void
    {
        $from = (int) DB::value("SELECT id FROM content_items WHERE hotel_id = :h AND title = 'Live Darshan'", ['h' => self::$h['alpha']]);
        [$s] = self::as('liveroot')->post('platform_live.php', ['op' => 'save', 'id' => 'dwarkadhish', 'title' => 'શ્રી દ્વારકાધીશ લાઇવ દર્શન',
            'subtitle' => 'Jagat Mandir', 'type' => 'stream', 'url' => '', 'copy_from' => $from, 'auto' => 1]);
        $this->assertSame(302, $s);
        Settings::flush();
        $c = LiveChannels::find('dwarkadhish');
        $this->assertSame(self::LINK1, $c['url']);
        $rows = self::copies();
        $this->assertCount(3, $rows); // main, alpha, beta
        $this->assertEqualsCanonicalizing([self::$h['main'], self::$h['alpha'], self::$h['beta']], array_map(static fn ($r) => (int) $r['hotel_id'], $rows));
        foreach ($rows as $r) {
            $this->assertSame('stream', $r['type']);
            $this->assertSame(self::LINK1, $r['url']);
        }
        // Re-save: no duplicates.
        self::as('liveroot')->post('platform_live.php', ['op' => 'save', 'id' => 'dwarkadhish', 'title' => $c['title'], 'type' => 'stream', 'url' => self::LINK1, 'auto' => 1]);
        $this->assertCount(3, self::copies());
        // A new customer gets it on creation.
        $gamma = Hotels::create(['name' => 'Gamma Guest House']);
        $this->assertArrayHasKey($gamma, LiveChannels::holders('dwarkadhish'));
        // Archived customers are skipped by a later sync.
        $delta = Hotels::create(['name' => 'Delta Archived']);
        DB::query('DELETE FROM content_items WHERE hotel_id = :h', ['h' => $delta]);
        DB::query('UPDATE hotels SET archived_at = :n WHERE id = :h', ['n' => now(), 'h' => $delta]);
        LiveChannels::sync(LiveChannels::find('dwarkadhish'));
        $this->assertArrayNotHasKey($delta, LiveChannels::holders('dwarkadhish'));
        // The customer sees it on Templates as "In your library".
        [, , $html] = self::as('alphaboss')->get('templates.php');
        $this->assertStringContainsString('data-live="dwarkadhish"', $html);
        $this->assertStringContainsString('In your library', $html);
    }

    public function test3LinkChangeReachesEveryTv(): void
    {
        // The customer renamed and assigned its copy.
        $alphaItem = LiveChannels::holders('dwarkadhish')[self::$h['alpha']];
        DB::query("UPDATE content_items SET title = 'Dwarka Live (Lobby)' WHERE id = :id", ['id' => $alphaItem]);
        Tenant::run(self::$h['alpha'], static function () use ($alphaItem): void {
            DB::query('UPDATE rooms SET content_id = :c WHERE hotel_id = :h', ['c' => $alphaItem, 'h' => Tenant::id()]);
            Settings::bumpContentVersion();
        });
        $hdr = ['Authorization: Bearer ' . self::$tv['token'], 'X-Device-Id: ' . self::$tv['uid']];
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['uid'], null, $hdr);
        $this->assertSame(200, $s);
        $this->assertSame(self::LINK1, $j['data']['content']['items'][0]['url']);
        $hash = $j['data']['content_hash'];

        [$s] = self::as('liveroot')->post('platform_live.php', ['op' => 'save', 'id' => 'dwarkadhish', 'title' => 'શ્રી દ્વારકાધીશ લાઇવ દર્શન', 'type' => 'stream', 'url' => self::LINK2, 'auto' => 1]);
        $this->assertSame(302, $s);
        foreach (self::copies() as $r) {
            $this->assertSame(self::LINK2, $r['url']);
        }
        $this->assertSame('Dwarka Live (Lobby)', DB::value('SELECT title FROM content_items WHERE id = :id', ['id' => $alphaItem]));
        Cache::clear();
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['uid'] . '?hash=' . urlencode($hash), null, $hdr);
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['content_changed']);
        $this->assertSame(self::LINK2, $j['data']['content']['items'][0]['url']);
        $this->assertSame('stream', $j['data']['content']['items'][0]['type']);
    }

    public function test4OnRequestChannelValidationAndDelete(): void
    {
        $root = self::as('liveroot');
        // Validation: bad link, missing name.
        $root->post('platform_live.php', ['op' => 'save', 'title' => 'Somnath', 'type' => 'stream', 'url' => 'javascript:alert(1)']);
        $this->assertNull(self::findByTitle('Somnath'));
        $root->post('platform_live.php', ['op' => 'save', 'title' => '', 'type' => 'stream', 'url' => self::LINK2]);
        Settings::flush();
        $this->assertCount(1, LiveChannels::all());
        $root->post('platform_live.php', ['op' => 'save', 'title' => 'Somnath YT', 'type' => 'youtube', 'url' => 'https://example.test/not-youtube']);
        $this->assertNull(self::findByTitle('Somnath YT'));

        // On request: nobody gets it until they add it.
        $root->post('platform_live.php', ['op' => 'save', 'title' => 'Somnath Live', 'type' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk']);
        $c = self::findByTitle('Somnath Live');
        $this->assertNotNull($c);
        $this->assertFalse($c['auto']);
        $this->assertSame([], LiveChannels::holders($c['id']));
        [, , $html] = self::as('alphaboss')->get('templates.php');
        $this->assertStringContainsString('data-live="' . $c['id'] . '"', $html);
        [$s, , , $head] = self::as('alphaboss')->post('templates.php', ['op' => 'live_add', 'channel' => $c['id']]);
        $this->assertSame(302, $s);
        $holders = LiveChannels::holders($c['id']);
        $this->assertSame([self::$h['alpha']], array_keys($holders));
        $this->assertStringContainsString('content.php?action=edit', $head);
        self::as('alphaboss')->post('templates.php', ['op' => 'live_add', 'channel' => $c['id']]);
        $this->assertCount(1, LiveChannels::holders($c['id'])); // no duplicate
        self::as('alphaboss')->post('templates.php', ['op' => 'live_add', 'channel' => 'nope']);
        $this->assertCount(1, LiveChannels::holders($c['id']));

        // Delete: the library entry goes, the customer's copy stays (unlinked).
        $item = $holders[self::$h['alpha']];
        $root->post('platform_live.php', ['op' => 'delete', 'id' => $c['id']]);
        Settings::flush();
        $this->assertNull(LiveChannels::find($c['id']));
        $row = DB::one('SELECT type, url, settings FROM content_items WHERE id = :id', ['id' => $item]);
        $this->assertSame('youtube', $row['type']);
        $this->assertArrayNotHasKey('platform_live', json_decode((string) $row['settings'], true) ?: []);
        $this->assertNotNull(LiveChannels::find('dwarkadhish'));
    }

    public function test5Translations(): void
    {
        [, , $gu] = self::as('liveroot')->get('platform_live.php?lang=gu');
        foreach (['lang/gu_live_channels.php', 'lang/hi_live_channels.php'] as $f) {
            $t = require HC_ROOT . '/' . $f;
            foreach (['Live darshan', 'Add to every customer automatically', 'In your library'] as $k) {
                $this->assertArrayHasKey($k, $t, $f);
            }
        }
        $this->assertFalse(TestEnv::hasPhpError($gu), $gu);
    }

    private static function findByTitle(string $t): ?array
    {
        Settings::flush();
        foreach (LiveChannels::all() as $c) {
            if ($c['title'] === $t) {
                return $c;
            }
        }
        return null;
    }
}
