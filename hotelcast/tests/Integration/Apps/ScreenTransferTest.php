<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.5.1 Screens & TVs for the platform (docs/modules/platform_screens.md § 7):
 *  - admin/rooms.php "All customers" view: platform admin sees every customer's TVs (incl. resellers'
 *    customers), a reseller only their own customers, a customer user never gets the switch (forcing
 *    ?view=all / posting the platform form changes nothing);
 *  - "Transfer to another customer" with "Transfer everything" (core/ScreenTransfer.php): screen details and
 *    content copied (rows owned by the target, media files physically copied), the old customer keeps its
 *    originals, the TV's next poll lists the copied items with the target's URLs, logs on both customers;
 *  - storage limit / screen limit / a failure in the middle leave no rows and no files; CSRF; only
 *    platform.move may transfer.
 */
final class ScreenTransferTest extends TestCase
{
    private static string $url;
    private static array $h = [];
    private static array $room = [];
    /** @var array<string, array{uid:string, token:string, id:int, hotel:int}> */
    private static array $tv = [];
    private static array $sessions = [];
    private static array $c = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Owner Account');
        DB::query("UPDATE hotels SET name = 'Owner Account' WHERE id = 1");
        $pw = Auth::hash('Passw0rd!');
        $r1 = DB::insert('resellers', ['name' => 'Reseller One', 'status' => 'active']);
        $r2 = DB::insert('resellers', ['name' => 'Reseller Two', 'status' => 'active']);
        DB::insert('users', ['hotel_id' => null, 'username' => 'stroot', 'email' => 'stroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        // The owner's own login works inside their own account (hotel 1), like on the live site.
        DB::insert('users', ['hotel_id' => 1, 'username' => 'stowner', 'email' => 'stowner@platform.test', 'full_name' => 'Owner', 'password_hash' => $pw, 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => $r1, 'username' => 'stres1', 'email' => 'stres1@res.test', 'full_name' => 'Res One', 'password_hash' => $pw, 'role' => 'reseller']);
        self::$h['own'] = 1;
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Mart', 'reseller_id' => $r1]);
        self::$h['beta'] = Hotels::create(['name' => 'Beta Temple', 'reseller_id' => $r1]);
        self::$h['gamma'] = Hotels::create(['name' => 'Gamma Clinic', 'reseller_id' => $r2]);
        self::$h['delta'] = Hotels::create(['name' => 'Delta Office']);
        foreach (['own' => ['101', '102'], 'alpha' => ['A1', 'A2', 'A3'], 'beta' => ['B1', 'BEMPTY'], 'gamma' => ['G1'], 'delta' => ['D1']] as $k => $nums) {
            Tenant::run(self::$h[$k], static function () use ($nums): void {
                foreach ($nums as $n) {
                    self::$room[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Screen ' . $n, 'floor' => '1']);
                }
            });
        }
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'stalphaboss', 'email' => 'boss@alpha.test', 'password' => 'Passw0rd!'], 'super_admin');
        foreach (['DeviceHealthTask', 'AnalyticsTask', 'DeviceSchedulesTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        foreach (['tvOwn' => ['101', 'own'], 'tvA1' => ['A1', 'alpha'], 'tvA2' => ['A2', 'alpha'], 'tvA3' => ['A3', 'alpha'], 'tvB1' => ['B1', 'beta'], 'tvG1' => ['G1', 'gamma']] as $name => [$room, $hk]) {
            self::register($name, $room, $hk);
        }
    }

    public static function tearDownAfterClass(): void
    {
        PlatformScreens::$userOverride = null;
        PlatformScreens::forget();
        self::$sessions = [];
        Tenant::forget();
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        PlatformScreens::$userOverride = null;
        PlatformScreens::forget();
    }

    private static function register(string $name, string $room, string $hotelKey): void
    {
        $key = (string) Settings::getFor(self::$h[$hotelKey], 'registration_key');
        $uid = 'tv-st-' . strtolower($name) . '-0001';
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key, 'app_version' => '2.5.0', 'app_version_code' => 13, 'model' => 'Model ' . $name]);
        self::assertSame(200, $s, (string) json_encode($j));
        $hid = self::$h[$hotelKey];
        self::$tv[$name] = ['uid' => $uid, 'token' => $j['data']['token'], 'hotel' => $hid,
            'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hid])];
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function poll(string $name): array
    {
        $t = self::$tv[$name];
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . $t['uid'] . '?hash=x', null, ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        self::assertSame(200, $s, (string) json_encode($j));
        return $j['data'];
    }

    private static function device(string $name): array
    {
        return (array) DB::one('SELECT * FROM devices WHERE id = :id', ['id' => self::$tv[$name]['id']]);
    }

    /** Write a real media file of customer $hid: [relative path, absolute path]. */
    private static function mediaFile(int $hid, string $name, string $bytes): array
    {
        $rel = 'h' . $hid . '/media/2026/10/' . $name;
        $abs = HC_ROOT . '/uploads/' . $rel;
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, $bytes);
        return [$rel, $abs];
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(8, 8);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    private static function counts(int $hid): array
    {
        $out = [];
        foreach (['content_items', 'content_playlists', 'tickers', 'broadcast_commands', 'device_schedules', 'rooms'] as $t) {
            $out[$t] = (int) DB::value("SELECT COUNT(*) FROM $t WHERE hotel_id = :h", ['h' => $hid]);
        }
        $out['files'] = is_dir(HC_ROOT . '/uploads/h' . $hid) ? count(array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HC_ROOT . '/uploads/h' . $hid, FilesystemIterator::SKIP_DOTS))), static fn ($f) => $f->isFile())) : 0;
        return $out;
    }

    // ------------------------------------------------------------------ All customers view

    public function testPlatformAdminSeesEveryCustomerOnRoomsPage(): void
    {
        $root = self::as('stroot');
        [$s, , $html] = $root->get('rooms.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), 'PHP error on rooms.php (all customers)');
        $this->assertStringContainsString('Screens — all customers', $html);
        $this->assertStringContainsString('roomsViewSwitch', $html);
        $this->assertStringContainsString('All customers (6 TVs)', $html);
        foreach (self::$tv as $t) {
            $this->assertStringContainsString($t['uid'], $html);
        }
        foreach (['Alpha Mart', 'Beta Temple', 'Gamma Clinic', 'Owner Account'] as $c) {
            $this->assertStringContainsString($c, $html);
        }
        $this->assertStringContainsString('platform_customer.php?id=' . self::$h['gamma'], $html, 'customer column links to the customer details');
        $this->assertStringContainsString('js-ps-transfer', $html, 'Transfer button per TV');
        $this->assertStringContainsString('psTransferModal', $html);
        $this->assertStringContainsString('name="copy_content"', $html);
        // Filters: customer, search, status, screens without TV, pagination.
        [, , $html] = $root->get('rooms.php?customer=' . self::$h['beta']);
        $this->assertStringContainsString(self::$tv['tvB1']['uid'], $html);
        $this->assertStringNotContainsString(self::$tv['tvA1']['uid'], $html);
        [, , $html] = $root->get('rooms.php?q=' . urlencode('tv-st-tvg1'));
        $this->assertStringContainsString(self::$tv['tvG1']['uid'], $html);
        $this->assertStringNotContainsString(self::$tv['tvB1']['uid'], $html);
        [, , $html] = $root->get('rooms.php?status=notv&customer=' . self::$h['beta']);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('BEMPTY', $html);
        $this->assertStringNotContainsString(self::$tv['tvB1']['uid'], $html);
        DB::query("UPDATE devices SET status = 'offline' WHERE id = :id", ['id' => self::$tv['tvG1']['id']]);
        [, , $html] = $root->get('rooms.php?status=offline');
        $this->assertStringContainsString(self::$tv['tvG1']['uid'], $html);
        $this->assertStringNotContainsString(self::$tv['tvA1']['uid'], $html);
        DB::query("UPDATE devices SET status = 'online' WHERE id = :id", ['id' => self::$tv['tvG1']['id']]);
        [, , $html] = $root->get('rooms.php?per_page=25&page=9');
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString(self::$tv['tvA1']['uid'], $html, 'page beyond the end shows the last page');

        // Without an open customer "This customer" is not possible: ?view=customer still shows all.
        [, , $html] = $root->get('rooms.php?view=customer');
        $this->assertStringContainsString('Screens — all customers', $html);
        $this->assertStringContainsString('This customer (none open)', $html);

        // The owner with their own account: default All customers, the choice is remembered in the session.
        $owner = self::as('stowner');
        [, , $html] = $owner->get('rooms.php');
        $this->assertStringContainsString('Screens — all customers', $html);
        $this->assertStringContainsString('This customer (Owner Account)', $html);
        [, , $html] = $owner->get('rooms.php?view=customer');
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('Screens &amp; TVs', $html);
        $this->assertStringContainsString('roomsViewSwitch', $html);
        $this->assertStringNotContainsString(self::$tv['tvB1']['uid'], $html);
        $this->assertStringContainsString('js-ps-transfer', $html, 'Transfer button in the customer view');
        $this->assertStringContainsString('value="transfer"', $html, 'bulk transfer through the checkboxes');
        [, , $html] = $owner->get('rooms.php');
        $this->assertStringContainsString('Screens &amp; TVs', $html, 'view remembered');
        [, , $html] = $owner->get('rooms.php?action=device&id=' . self::$tv['tvOwn']['id']);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('js-ps-transfer', $html, 'Transfer on the TV detail page');
        $this->assertStringContainsString('psTransferModal', $html);
        [, , $html] = $owner->get('rooms.php?view=all');
        $this->assertStringContainsString('Screens — all customers', $html);
    }

    public function testResellerSeesOnlyOwnCustomersAndCustomerUserNeverGetsTheSwitch(): void
    {
        [$s, , $html] = self::as('stres1')->get('rooms.php?customer=' . self::$h['gamma']);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('Screens — your customers', $html);
        foreach (['tvA1', 'tvA2', 'tvB1'] as $n) {
            $this->assertStringContainsString(self::$tv[$n]['uid'], $html);
        }
        foreach (['tvOwn', 'tvG1'] as $n) {
            $this->assertStringNotContainsString(self::$tv[$n]['uid'], $html, "$n hidden from the reseller");
        }
        $this->assertStringNotContainsString('Gamma Clinic', $html);
        $this->assertStringNotContainsString('js-ps-transfer', $html, 'resellers cannot transfer (platform.move)');
        $this->assertStringNotContainsString('psTransferModal', $html);
        [, , $html] = self::as('stres1')->get('rooms.php?status=notv');
        $this->assertStringNotContainsString('>G1<', $html);
        $this->assertStringNotContainsString('>102<', $html);

        // Customer admin: old page, no switch, no other customer's TVs — also when forcing the parameter.
        foreach (['rooms.php', 'rooms.php?view=all', 'rooms.php?view=all&customer=' . self::$h['beta']] as $page) {
            [$s, , $html] = self::as('stalphaboss')->get($page);
            $this->assertSame(200, $s, $page);
            $this->assertFalse(TestEnv::hasPhpError($html));
            $this->assertStringContainsString('Screens &amp; TVs', $html);
            $this->assertStringNotContainsString('roomsViewSwitch', $html);
            $this->assertStringNotContainsString('Screens — all customers', $html);
            $this->assertStringNotContainsString('js-ps-transfer', $html);
            $this->assertStringNotContainsString('B1', $html);
            $this->assertStringNotContainsString('Beta Temple', $html);
        }
        // Posting the platform form as a customer / reseller moves nothing.
        $before = json_encode(DB::all('SELECT id, hotel_id, room_id FROM devices ORDER BY id'));
        [$s] = self::as('stalphaboss')->post('rooms.php', ['ps_form' => 1, 'op' => 'bulk', 'bulk_action' => 'move', 'ids' => [self::$tv['tvA1']['id']],
            'target_customer' => self::$h['beta'], 'screen_mode' => 'same', 'copy_details' => 1, 'copy_content' => 1]);
        $this->assertContains($s, [302, 403]);
        [$s] = self::as('stres1')->post('rooms.php', ['ps_form' => 1, 'op' => 'bulk', 'bulk_action' => 'move', 'ids' => [self::$tv['tvA1']['id']],
            'target_customer' => self::$h['beta'], 'screen_mode' => 'same', 'copy_details' => 1, 'copy_content' => 1]);
        $this->assertSame(403, $s);
        $this->assertSame($before, json_encode(DB::all('SELECT id, hotel_id, room_id FROM devices ORDER BY id')));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h', ['h' => self::$h['beta']]));
        // Core API refuses resellers too.
        PlatformScreens::$userOverride = (array) DB::one("SELECT * FROM users WHERE username = 'stres1'");
        try {
            PlatformScreens::transfer([self::$tv['tvA1']['id']], self::$h['beta'], ['mode' => 'same', 'copy' => ['details' => 1, 'content' => 1]]);
            $this->fail('reseller transferred a TV');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Only the platform admin', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ transfer everything

    private static function seedAlphaA1(): void
    {
        $a = self::$h['alpha'];
        [$imgRel, $imgAbs] = self::mediaFile($a, 'srcimg.png', self::png());
        [$thRel] = self::mediaFile($a, 'srcimg_thumb.jpg', 'thumbbytes');
        [$vidRel, $vidAbs] = self::mediaFile($a, 'srcvid.mp4', str_repeat('v', 4096));
        self::$c['imgAbs'] = $imgAbs;
        self::$c['vidAbs'] = $vidAbs;
        Tenant::run($a, static function () use ($imgRel, $thRel, $vidRel): void {
            $ins = static fn (array $row): int => DB::insert('content_items', $row + ['duration' => 10, 'is_active' => 1, 'created_at' => now()]);
            self::$c['img'] = $ins(['title' => 'ST-IMAGE', 'type' => 'image', 'file_path' => $imgRel, 'thumb_path' => $thRel, 'mime_type' => 'image/png', 'file_size' => 100]);
            self::$c['vid'] = $ins(['title' => 'ST-VIDEO', 'type' => 'video', 'file_path' => $vidRel, 'mime_type' => 'video/mp4', 'file_size' => 4096, 'settings' => json_encode(['loop' => true, 'mute' => true])]);
            self::$c['ann'] = $ins(['title' => 'ST-ANNOUNCE', 'type' => 'announcement', 'body' => 'Welcome', 'settings' => json_encode(['style' => 'fullscreen'])]);
            self::$c['app'] = $ins(['title' => 'ST-APP', 'type' => 'app', 'settings' => json_encode(['app' => 'countdown', 'config' => []])]);
            self::$c['layout'] = $ins(['title' => 'ST-LAYOUT', 'type' => 'layout', 'settings' => json_encode(['bg_color' => '#000000', 'audio' => 'auto', 'zones' => [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 100, 'content_id' => self::$c['img'], 'playlist_id' => null],
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 100, 'content_id' => self::$c['ann'], 'playlist_id' => null],
            ]])]);
            self::$c['secret'] = $ins(['title' => 'ALPHA-SECRET', 'type' => 'announcement', 'body' => 'not for others']);
            self::$c['pl'] = DB::insert('content_playlists', ['name' => 'ST-PLAYLIST', 'transition' => 'fade', 'created_at' => now()]);
            foreach (['img', 'vid', 'ann', 'app', 'layout'] as $i => $k) {
                DB::insert('playlist_items', ['playlist_id' => self::$c['pl'], 'content_id' => self::$c[$k], 'sort_order' => $i, 'duration' => 15]);
            }
            DB::query("UPDATE rooms SET playlist_id = :p, name = 'Lobby A1', floor = '3', settings_pin = '4321', notes = 'near entrance', cec_mode = 'off' WHERE id = :r",
                ['p' => self::$c['pl'], 'r' => self::$room['A1']]);
            DB::insert('tickers', ['name' => 'ST-TICKER', 'message' => 'Sale today', 'target_type' => 'room', 'target_id' => self::$room['A1'], 'video_scale' => 'fit', 'created_at' => now()]);
            DB::insert('tickers', ['name' => 'ST-TICKER-ALL', 'message' => 'For all alpha screens', 'target_type' => 'all', 'created_at' => now()]);
            DB::insert('broadcast_commands', ['title' => 'ST-NIGHT-OFF', 'command' => 'SCREEN_OFF', 'target_type' => 'rooms', 'target_ids' => json_encode([self::$room['A1']]),
                'mode' => 'window', 'status' => 'active', 'daily_start' => '23:00:00', 'daily_end' => '23:30:00', 'payload' => '{}', 'created_at' => now()]);
            DB::insert('device_schedules', ['title' => 'ST-VOLUME', 'action' => 'volume', 'options' => json_encode(['level' => 30]), 'run_time' => '08:00:00', 'repeat_mode' => 'daily',
                'target_type' => 'rooms', 'target_ids' => json_encode([self::$room['A1']]), 'is_active' => 1, 'created_at' => now()]);
            DB::insert('device_schedules', ['title' => 'ST-BELL', 'action' => 'bell', 'options' => json_encode(['sound' => 'u:999', 'repeat' => 1]), 'run_time' => '09:00:00', 'repeat_mode' => 'daily',
                'target_type' => 'rooms', 'target_ids' => json_encode([self::$room['A1']]), 'is_active' => 1, 'created_at' => now()]);
            Settings::bumpContentVersion();
        });
    }

    public function testTransferEverythingCopiesDetailsAndContent(): void
    {
        self::seedAlphaA1();
        $a = self::$h['alpha'];
        $b = self::$h['beta'];
        $alphaBefore = self::counts($a);
        $alphaItems = json_encode(DB::all('SELECT id, title, file_path, thumb_path FROM content_items WHERE hotel_id = :h ORDER BY id', ['h' => $a]));
        $d = self::poll('tvA1');
        $this->assertSame($a, $d['content']['hotel']['id']);
        $this->assertSame('ST-PLAYLIST', $d['content']['playlist']['name']);
        Tenant::run($a, static fn () => DB::insert('device_commands', ['device_id' => self::$tv['tvA1']['id'], 'command' => 'SHOW_MESSAGE', 'payload' => '{"text":"alpha only"}', 'status' => 'delivered', 'created_at' => now()]));

        // From the platform admin's own customer view of rooms.php (the transfer dialog posts ps_form=1).
        $owner = self::as('stowner');
        [$s] = $owner->post('rooms.php', ['ps_form' => 1, 'op' => 'bulk', 'bulk_action' => 'move', 'ids' => [self::$tv['tvA1']['id']],
            'target_customer' => $b, 'screen_mode' => 'same', 'copy_details' => 1, 'copy_content' => 1]);
        $this->assertSame(302, $s);
        [, , $html] = $owner->get('rooms.php');
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('1 TV(s) moved', $html);
        $this->assertStringContainsString('Copied: ', $html);
        $this->assertStringContainsString('1 playlist', $html);
        $this->assertStringContainsString('2 media files', $html);
        $this->assertStringContainsString('1 ticker', $html);
        $this->assertStringContainsString('Not copied: ', $html);
        $this->assertStringContainsString('ST-APP', $html);
        $this->assertStringContainsString('ST-BELL', $html);

        // The TV: same row, same token, new customer, screen with the same screen ID and details.
        $row = self::device('tvA1');
        $this->assertSame($b, (int) $row['hotel_id']);
        $this->assertSame(hash('sha256', self::$tv['tvA1']['token']), $row['token_hash'], 'token kept, no setup on the TV');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_commands WHERE device_id = :d', ['d' => $row['id']]), 'old command history removed');
        $room = (array) DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => (int) $row['room_id']]);
        $this->assertSame($b, (int) $room['hotel_id']);
        $this->assertSame('A1', $room['room_number']);
        $this->assertSame('Lobby A1', $room['name']);
        $this->assertSame('3', $room['floor']);
        $this->assertSame('4321', $room['settings_pin']);
        $this->assertSame('near entrance', $room['notes']);
        $this->assertSame('off', $room['cec_mode']);

        // Content: new rows owned by beta, assigned to the new screen; files copied into uploads/h{beta}.
        $pl = (array) DB::one('SELECT * FROM content_playlists WHERE id = :id', ['id' => (int) $room['playlist_id']]);
        $this->assertSame($b, (int) $pl['hotel_id']);
        $this->assertSame('ST-PLAYLIST', $pl['name']);
        $items = DB::all('SELECT c.* FROM playlist_items pi JOIN content_items c ON c.id = pi.content_id WHERE pi.playlist_id = :p ORDER BY pi.sort_order', ['p' => $pl['id']]);
        $this->assertSame(['ST-IMAGE', 'ST-VIDEO', 'ST-ANNOUNCE', 'ST-LAYOUT'], array_column($items, 'title'), 'display app skipped');
        foreach ($items as $it) {
            $this->assertSame($b, (int) $it['hotel_id']);
            $this->assertNotContains((int) $it['id'], [self::$c['img'], self::$c['vid'], self::$c['ann'], self::$c['layout']]);
        }
        $img = $items[0];
        $this->assertStringStartsWith('h' . $b . '/media/', $img['file_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $img['file_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $img['thumb_path']);
        $this->assertSame(file_get_contents(self::$c['imgAbs']), file_get_contents(HC_ROOT . '/uploads/' . $img['file_path']));
        $this->assertFileExists(HC_ROOT . '/uploads/' . $items[1]['file_path']);
        $zones = json_decode((string) $items[3]['settings'], true)['zones'];
        $this->assertSame((int) $img['id'], (int) $zones[0]['content_id'], 'layout zone points to the copied image (copied once)');
        $this->assertSame((int) $items[2]['id'], (int) $zones[1]['content_id']);
        $this->assertSame(4, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h', ['h' => $b]));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND title IN ('ALPHA-SECRET', 'ST-APP')", ['h' => $b]), 'nothing else of the old customer');

        $t = (array) DB::one("SELECT * FROM tickers WHERE hotel_id = :h AND name = 'ST-TICKER'", ['h' => $b]);
        $this->assertSame('room', $t['target_type']);
        $this->assertSame((int) $room['id'], (int) $t['target_id']);
        $this->assertSame('fit', $t['video_scale']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM tickers WHERE hotel_id = :h AND name = 'ST-TICKER-ALL'", ['h' => $b]));
        $w = (array) DB::one("SELECT * FROM broadcast_commands WHERE hotel_id = :h AND title = 'ST-NIGHT-OFF'", ['h' => $b]);
        $this->assertSame([(int) $room['id']], json_decode((string) $w['target_ids'], true));
        $vol = (array) DB::one("SELECT * FROM device_schedules WHERE hotel_id = :h AND title = 'ST-VOLUME'", ['h' => $b]);
        $this->assertSame([(int) $room['id']], json_decode((string) $vol['target_ids'], true));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_schedules WHERE hotel_id = :h AND title = 'ST-BELL'", ['h' => $b]));

        // The old customer keeps every original row and file.
        $this->assertSame($alphaBefore, self::counts($a));
        $this->assertSame($alphaItems, json_encode(DB::all('SELECT id, title, file_path, thumb_path FROM content_items WHERE hotel_id = :h ORDER BY id', ['h' => $a])));
        $this->assertFileExists(self::$c['imgAbs']);
        $this->assertFileExists(self::$c['vidAbs']);
        $this->assertSame(self::$c['pl'], (int) DB::value('SELECT playlist_id FROM rooms WHERE id = :id', ['id' => self::$room['A1']]));

        // The target can play it: the TV's next poll (ContentResolver in beta) lists the copies with beta's URLs.
        $d = self::poll('tvA1');
        $this->assertSame($b, $d['content']['hotel']['id']);
        $this->assertSame('assigned', $d['content']['mode']);
        $this->assertSame('ST-PLAYLIST', $d['content']['playlist']['name']);
        $titles = array_column($d['content']['items'], 'title');
        $this->assertSame(['ST-IMAGE', 'ST-VIDEO', 'ST-ANNOUNCE', 'ST-LAYOUT'], $titles);
        $this->assertStringContainsString('uploads/h' . $b . '/media/', $d['content']['items'][0]['url']);
        $this->assertStringContainsString('uploads/h' . $b . '/media/', $d['content']['items'][1]['url']);
        $this->assertStringNotContainsString('uploads/h' . $a . '/', (string) json_encode($d['content']));
        [$s] = TestEnv::http('GET', $d['content']['items'][0]['url']);
        $this->assertSame(200, $s, 'copied media file is served');
        $this->assertSame('Sale today', $d['content']['overlay']['ticker']['message'] ?? $d['content']['ticker']['message'] ?? null);

        // Activity logs on both customers and the platform.
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'screen_copied_out'", ['h' => $a]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'screen_copied_in'", ['h' => $b]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id IS NULL AND action = 'screen_transfer'"));
        $this->assertStringNotContainsString('Beta', (string) DB::value("SELECT details FROM activity_logs WHERE hotel_id = :h AND action = 'screen_copied_out'", ['h' => $a]), 'old customer does not learn the new owner');
    }

    public function testTransferWithoutOptionsMovesOnlyTheTv(): void
    {
        // tvA2 (screen A2 follows alpha's default content) → delta, nothing ticked: no content copied.
        $before = self::counts(self::$h['delta']);
        PlatformScreens::$userOverride = (array) DB::one("SELECT * FROM users WHERE username = 'stroot'");
        $r = PlatformScreens::transfer([self::$tv['tvA2']['id']], self::$h['delta'], ['mode' => 'existing', 'room_id' => self::$room['D1']]);
        $this->assertSame(1, $r['moved']);
        $this->assertSame('', $r['summary']);
        $after = self::counts(self::$h['delta']);
        $this->assertSame($before['content_items'], $after['content_items']);
        $this->assertSame($before['files'], $after['files']);
        // Back to alpha with content: A2 had no own assignment → the group / default source is copied and assigned.
        Tenant::run(self::$h['delta'], static function (): void {
            $cid = DB::insert('content_items', ['title' => 'DELTA-DEFAULT', 'type' => 'clock', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
            Settings::set('default_content_id', (string) $cid);
        });
        $r = PlatformScreens::transfer([self::$tv['tvA2']['id']], self::$h['alpha'], ['mode' => 'new', 'name' => 'Back A2', 'copy' => ['details' => false, 'content' => true]]);
        $this->assertSame(1, $r['moved']);
        $this->assertStringContainsString('1 content item', $r['summary']);
        $this->assertStringContainsString('group / default', $r['summary']);
        $room = (array) DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => (int) self::device('tvA2')['room_id']]);
        $this->assertSame('Back A2', $room['name'], 'mode "new" keeps the typed name');
        $this->assertSame('DELTA-DEFAULT', DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => (int) $room['content_id'], 'h' => self::$h['alpha']]));
    }

    public function testStorageAndScreenLimitsAndFailuresLeaveNothingBehind(): void
    {
        $a = self::$h['alpha'];
        $d = self::$h['delta'];
        PlatformScreens::$userOverride = (array) DB::one("SELECT * FROM users WHERE username = 'stroot'");
        // A3 plays a 2 MB video; delta may store 1 MB.
        [$rel] = self::mediaFile($a, 'big.mp4', str_repeat('b', 2 * 1024 * 1024));
        Tenant::run($a, static function () use ($rel): void {
            $cid = DB::insert('content_items', ['title' => 'BIG-VIDEO', 'type' => 'video', 'file_path' => $rel, 'file_size' => 2097152, 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
            DB::query('UPDATE rooms SET content_id = :c WHERE id = :r', ['c' => $cid, 'r' => self::$room['A3']]);
        });
        DB::query('UPDATE hotels SET storage_mb = 1 WHERE id = :id', ['id' => $d]);
        Tenant::forget($d);
        Cache::clear('storage');
        $before = self::counts($d);
        $devBefore = self::device('tvA3');
        try {
            PlatformScreens::transfer([self::$tv['tvA3']['id']], $d, ['mode' => 'new', 'name' => 'Big', 'copy' => ['details' => true, 'content' => true]]);
            $this->fail('storage limit ignored');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('STORAGE_LIMIT', $e->getMessage());
            $this->assertStringContainsString('Delta Office', $e->getMessage());
        }
        $this->assertSame($before, self::counts($d), 'no rows, no screen, no files');
        $this->assertSame($devBefore, self::device('tvA3'), 'the TV stays');
        // Without content the TV fits.
        DB::query('UPDATE hotels SET storage_mb = NULL, max_tvs = :m WHERE id = :id', ['m' => Tenant::tvCount($d), 'id' => $d]);
        Tenant::forget($d);
        // Screen limit full → LICENSE_LIMIT, nothing copied.
        try {
            PlatformScreens::transfer([self::$tv['tvA3']['id']], $d, ['mode' => 'new', 'name' => 'Big', 'copy' => ['details' => true, 'content' => true]]);
            $this->fail('screen limit ignored');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('LICENSE_LIMIT', $e->getMessage());
        }
        $this->assertSame($before, self::counts($d));
        DB::query('UPDATE hotels SET max_tvs = NULL WHERE id = :id', ['id' => $d]);
        Tenant::forget($d);

        // A failure after files were copied (second TV clashes with an active TV of the target): the
        // transaction rolls back and the copied files are removed.
        Tenant::run($d, static fn () => DB::insert('devices', ['device_uid' => self::$tv['tvB1']['uid'], 'room_id' => self::$room['D1'], 'token_hash' => hash('sha256', 'clash'), 'registered_at' => now()]));
        $before = self::counts($d);
        try {
            PlatformScreens::transfer([self::$tv['tvA3']['id'], self::$tv['tvB1']['id']], $d, ['mode' => 'new', 'name' => 'Pair', 'copy' => ['details' => true, 'content' => true]]);
            $this->fail('clash ignored');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has an active TV', $e->getMessage());
        }
        $this->assertSame($before, self::counts($d), 'copied files and rows removed after the failure');
        $this->assertSame($a, (int) self::device('tvA3')['hotel_id']);
        $this->assertSame(self::$h['beta'], (int) self::device('tvB1')['hotel_id']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $rel, 'original untouched');
    }

    public function testTransferNeedsCsrf(): void
    {
        $root = self::as('stroot');
        $before = json_encode(DB::all('SELECT id, hotel_id, room_id FROM devices ORDER BY id'));
        $fields = ['ps_form' => 1, 'op' => 'bulk', 'bulk_action' => 'move', 'ids[0]' => self::$tv['tvG1']['id'], 'target_customer' => self::$h['beta'],
            'screen_mode' => 'same', 'copy_details' => 1, 'copy_content' => 1];
        foreach (['rooms.php', 'rooms.php?action=device&id=' . self::$tv['tvG1']['id'], 'platform_screens.php'] as $page) {
            foreach ([null, 'wrong-token'] as $token) {
                $form = $token === null ? $fields : ['_csrf' => $token] + $fields;
                [$s] = TestEnv::http('POST', self::$url . 'admin/' . $page, null, [], $root->jar, $form);
                $this->assertSame(419, $s, "$page without a valid CSRF token");
            }
        }
        $this->assertSame($before, json_encode(DB::all('SELECT id, hotel_id, room_id FROM devices ORDER BY id')));
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $files = ['admin/rooms.php', 'admin/partials/rooms_platform.php', 'admin/partials/platform_screens.php', 'core/ScreenTransfer.php', 'core/PlatformScreens.php'];
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $missing = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents(HC_ROOT . '/' . $f);
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                foreach (['gu' => $gu, 'hi' => $hi] as $lang => $t) {
                    if (!isset($t[$k])) {
                        $missing[] = "$lang: $k ($f)";
                    }
                }
            }
        }
        // Summary labels are picked at run time (singular / plural).
        preg_match_all("/\\[':n [a-z ]+', ':n [a-z ]+'\\]/", (string) file_get_contents(HC_ROOT . '/core/ScreenTransfer.php'), $m);
        foreach ($m[0] as $pair) {
            preg_match_all("/'([^']+)'/", $pair, $mm);
            foreach ($mm[1] as $k) {
                foreach (['gu' => $gu, 'hi' => $hi] as $lang => $t) {
                    if (!isset($t[$k])) {
                        $missing[] = "$lang: $k (summary)";
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
        // No hotel / room wording in the new UI strings.
        foreach (array_keys(require HC_ROOT . '/lang/gu_screen_transfer.php') as $k) {
            $this->assertDoesNotMatchRegularExpression('/\b(hotel|room)s?\b/i', $k);
        }
    }
}
