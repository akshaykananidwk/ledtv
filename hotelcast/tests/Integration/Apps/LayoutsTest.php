<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Split screen layouts (2.3): validation (bounds, overlap, max zones, nesting, foreign ids), admin
 * create over HTTP with CSRF, the TV contract (content item type 'layout' with resolved zones, audio
 * rule, playlist expansion, inactive / missing sources), the fallback for TV apps older than 2.3.0,
 * the admin TV simulator and XSS in titles.
 */
final class LayoutsTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const ZONE_KEYS = ['id', 'x', 'y', 'w', 'h', 'items', 'loop', 'transition', 'scale', 'mute'];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        self::$id['mgr'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'lyMgr', 'email' => 'lymgr@t.test', 'full_name' => 'Ly Mgr', 'password_hash' => $pw, 'role' => 'manager']);
        foreach (['101', '102', '103'] as $n) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        $c = static fn (array $row): int => DB::insert('content_items', $row + ['duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        self::$id['video'] = $c(['title' => 'Promo video', 'type' => 'video', 'url' => 'https://cdn.example.com/promo.mp4', 'settings' => '{"loop":true,"mute":false}', 'duration' => 0]);
        self::$id['img1'] = $c(['title' => 'Menu 1', 'type' => 'image', 'url' => 'https://cdn.example.com/m1.jpg', 'duration' => 8]);
        self::$id['img2'] = $c(['title' => 'Menu 2', 'type' => 'image', 'url' => 'https://cdn.example.com/m2.jpg', 'duration' => 12]);
        self::$id['off'] = $c(['title' => 'Hidden image', 'type' => 'image', 'url' => 'https://cdn.example.com/off.jpg', 'is_active' => 0]);
        self::$id['ann'] = $c(['title' => self::XSS, 'type' => 'announcement', 'body' => 'Checkout 11 AM ' . self::XSS, 'settings' => '{"style":"marquee"}']);
        self::$id['pl'] = DB::insert('content_playlists', ['name' => 'Menus ' . self::XSS, 'transition' => 'fade', 'created_at' => now()]);
        foreach ([[self::$id['img1'], 7], [self::$id['off'], null], [self::$id['img2'], null]] as $i => [$cid, $dur]) {
            DB::insert('playlist_items', ['playlist_id' => self::$id['pl'], 'content_id' => $cid, 'sort_order' => $i, 'duration' => $dur]);
        }
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'lyBoss2', 'email' => 'ly2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['foreign'] = DB::insert('content_items', ['title' => 'B-SECRET', 'type' => 'image', 'url' => 'https://b.example.com/x.jpg', 'duration' => 5, 'created_at' => now()]);
            self::$id['foreignPl'] = DB::insert('content_playlists', ['name' => 'B-PL', 'transition' => 'fade', 'created_at' => now()]);
        });
        Tenant::set(1);
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Tenant::forget();
        Settings::flush();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
    }

    /** The reference layout: left 70 % video, right 30 % playlist, nothing else. */
    private static function layoutInput(array $override = []): array
    {
        return $override + [
            'bg_color' => '#102030',
            'audio' => 'auto',
            'zones' => [
                ['id' => 'z1', 'x' => 0, 'y' => 0, 'w' => 70, 'h' => 100, 'source' => 'c:' . self::$id['video'], 'scale' => 'zoom', 'transition' => 'none'],
                ['id' => 'z2', 'x' => 70, 'y' => 0, 'w' => 30, 'h' => 85, 'source' => 'p:' . self::$id['pl'], 'scale' => 'fill'],
                ['id' => 'z3', 'x' => 70, 'y' => 85, 'w' => 30, 'h' => 15, 'source' => 'c:' . self::$id['ann']],
            ],
        ];
    }

    private static function validate(array $layout, string $title = 'L'): array
    {
        return ContentManager::validate(['title' => $title, 'duration' => 60, 'layout' => $layout], 'layout', false);
    }

    private static function createLayout(string $title, array $layout, int $duration = 60): int
    {
        [$data, $errors] = self::validate($layout, $title);
        self::assertSame([], $errors);
        $id = DB::insert('content_items', ['title' => $title, 'type' => 'layout', 'duration' => $duration, 'settings' => json_out((object) $data['settings']), 'is_active' => 1, 'created_at' => now()]);
        Settings::bumpContentVersion();
        return $id;
    }

    // ------------------------------------------------------------------ validation

    public function testValidation(): void
    {
        [$data, $errors] = self::validate(self::layoutInput());
        $this->assertSame([], $errors);
        $s = $data['settings'];
        $this->assertSame('#102030', $s['bg_color']);
        $this->assertSame('auto', $s['audio']);
        $this->assertSame(['z1', 'z2', 'z3'], array_column($s['zones'], 'id'));
        $this->assertSame([self::$id['video'], null, self::$id['ann']], array_column($s['zones'], 'content_id'));
        $this->assertSame([null, self::$id['pl'], null], array_column($s['zones'], 'playlist_id'));
        $this->assertSame(['zoom', 'fill', 'fit'], array_column($s['zones'], 'scale'));
        $this->assertSame(['none', 'fade', 'fade'], array_column($s['zones'], 'transition'));

        // Rounded to 2 decimals; touching edges are fine; the form's JSON field works too.
        $in = self::layoutInput(['audio' => 'z2']);
        $in['zones'] = [['x' => 0, 'y' => 0, 'w' => 33.3333, 'h' => 100, 'source' => 'c:' . self::$id['img1']], ['x' => 33.3333, 'y' => 0, 'w' => 66.6667, 'h' => 100, 'content_id' => self::$id['img2']]];
        [$data, $errors] = ContentManager::validate(['title' => 'J', 'layout_json' => json_encode($in)], 'layout', false);
        $this->assertSame([], $errors);
        $this->assertSame([33.33, 66.67], array_column($data['settings']['zones'], 'w'));
        $this->assertSame([0.0, 33.33], array_column($data['settings']['zones'], 'x'));
        $this->assertSame('z2', $data['settings']['audio']);
        $this->assertSame(self::$id['img2'], $data['settings']['zones'][1]['content_id']);

        $err = function (array $zones, string $needle, array $extra = []): void {
            [, $errors] = self::validate(['zones' => $zones] + $extra);
            $this->assertNotSame([], $errors, $needle);
            $this->assertStringContainsString($needle, implode("\n", $errors));
        };
        $src = 'c:' . self::$id['img1'];
        // Bounds
        $err([['x' => -1, 'y' => 0, 'w' => 50, 'h' => 50, 'source' => $src]], 'outside the screen');
        $err([['x' => 60, 'y' => 0, 'w' => 50, 'h' => 50, 'source' => $src]], 'outside the screen');
        $err([['x' => 0, 'y' => 0, 'w' => 0, 'h' => 50, 'source' => $src]], 'outside the screen');
        $err([['x' => 0, 'y' => 'abc', 'w' => 10, 'h' => 50, 'source' => $src]], 'outside the screen');
        $err([['x' => 0, 'y' => 50.5, 'w' => 10, 'h' => 50, 'source' => $src]], 'outside the screen');
        // Overlap
        $err([['x' => 0, 'y' => 0, 'w' => 60, 'h' => 100, 'source' => $src], ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 100, 'source' => $src]], 'Zones 1 and 2 overlap');
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => $src], ['x' => 0, 'y' => 85, 'w' => 100, 'h' => 15, 'source' => $src]], 'Zones 1 and 2 overlap');
        $this->assertSame([], Layouts::overlaps([['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50], ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 50], ['x' => 0, 'y' => 50, 'w' => 50, 'h' => 50]]));
        // Max zones
        $seven = [];
        for ($i = 0; $i < 7; $i++) {
            $seven[] = ['x' => $i * 14, 'y' => 0, 'w' => 14, 'h' => 100, 'source' => $src];
        }
        $err($seven, 'at most 6 zones');
        [, $errors] = self::validate(['zones' => array_slice($seven, 0, 6)]);
        $this->assertSame([], $errors, 'six zones are fine');
        // No zones / no source / unknown ids
        $err([], 'at least one zone');
        [, $errors] = ContentManager::validate(['title' => 'X', 'layout_json' => 'not json'], 'layout', false);
        $this->assertStringContainsString('at least one zone', implode("\n", $errors));
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]], 'choose a content item or playlist');
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'c:999999']], 'no longer exists');
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'p:999999']], 'no longer exists');
        // Nesting: a layout as a source, or a playlist that contains a layout.
        $inner = self::createLayout('Inner', ['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => $src]]]);
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'c:' . $inner]], 'cannot contain another layout');
        $pl = DB::insert('content_playlists', ['name' => 'With layout', 'transition' => 'fade', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $inner, 'sort_order' => 0]);
        $err([['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'p:' . $pl]], 'contains a layout');
        // Junk settings are normalised.
        [$data] = self::validate(['bg_color' => 'red;}', 'audio' => 'z9', 'zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => $src, 'scale' => 'huge', 'transition' => 'spin']]]);
        $this->assertSame(['#000000', 'auto', 'fit', 'fade'], [$data['settings']['bg_color'], $data['settings']['audio'], $data['settings']['zones'][0]['scale'], $data['settings']['zones'][0]['transition']]);
        DB::delete('content_playlists', 'id = :id', ['id' => $pl]);
        DB::delete('content_items', 'id = :id', ['id' => $inner]);
    }

    public function testForeignContentIdIsDenied(): void
    {
        $this->expectException(TenantException::class);
        self::validate(['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'c:' . self::$id['foreign']]]]);
    }

    public function testForeignPlaylistIdIsDenied(): void
    {
        $this->expectException(TenantException::class);
        self::validate(['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'p:' . self::$id['foreignPl']]]]);
    }

    // ------------------------------------------------------------------ admin over HTTP

    public function testCreateEditAndListOverHttp(): void
    {
        $s = new AdminSession(self::$url, 'lyMgr');
        [$code, , $html] = $s->get('content.php?action=new&type=layout');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('id="lyCanvas"', $html);
        $this->assertStringContainsString('name="layout_json"', $html);
        $this->assertStringContainsString('data-preset="lshape"', $html);
        $this->assertStringContainsString('value="60"', $html, 'layouts default to 60 s');
        $this->assertStringNotContainsString('B-SECRET', $html, 'only own hotel sources');

        $json = json_encode(self::layoutInput(['audio' => 'z3']));
        $title = 'Lobby split ' . bin2hex(random_bytes(3));
        // CSRF is required.
        [$code] = TestEnv::http('POST', self::$url . 'admin/content.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'type' => 'layout', 'title' => $title, 'layout_json' => $json]);
        $this->assertSame(419, $code);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM content_items WHERE title = :t', ['t' => $title]));

        $s->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'layout', 'title' => $title, 'duration' => 45, 'is_active' => 1, 'layout_json' => $json]);
        $row = DB::one('SELECT * FROM content_items WHERE title = :t', ['t' => $title]);
        $this->assertNotNull($row);
        $this->assertSame(['layout', 45, 1], [$row['type'], (int) $row['duration'], (int) $row['hotel_id']]);
        $set = json_decode((string) $row['settings'], true);
        $this->assertSame('z3', $set['audio']);
        $this->assertCount(3, $set['zones']);
        $this->assertSame(self::$id['pl'], $set['zones'][1]['playlist_id']);

        // Invalid (overlap) → not saved; foreign id → 404.
        $bad = self::layoutInput();
        $bad['zones'][1]['x'] = 60;
        $s->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'layout', 'title' => 'BAD-OVERLAP', 'layout_json' => json_encode($bad)]);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title = 'BAD-OVERLAP'"));
        $foreign = ['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'source' => 'c:' . self::$id['foreign']]]];
        [$code, , $body] = $s->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'layout', 'title' => 'BAD-FOREIGN', 'layout_json' => json_encode($foreign)]);
        $this->assertSame(404, $code);
        $this->assertStringNotContainsString('B-SECRET', $body);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE title = 'BAD-FOREIGN'"));

        // Edit page shows the saved zones; list shows the layout icon / diagram and "used in layout".
        [$code, , $html] = $s->get('content.php?action=edit&id=' . $row['id']);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('bi-grid-1x2', $html);
        $this->assertStringContainsString('"source":"p:' . self::$id['pl'] . '"', $html);
        foreach (['grid', 'list'] as $view) {
            [$code, , $html] = $s->get('content.php?view=' . $view);
            $this->assertSame(200, $code);
            $this->assertFalse(TestEnv::hasPhpError($html));
            $this->assertStringContainsString('Used in layout', $html);
            $this->assertStringContainsString($title, $html);
        }
        [, , $html] = $s->get('content.php?view=grid&type=layout');
        $this->assertStringContainsString('<svg class="w-100 h-100"', $html);
        [, , $html] = $s->get('playlists.php');
        $this->assertStringContainsString('Used in layout', $html);
        [, , $html] = $s->get('content.php?action=delete&id=' . self::$id['img1']);
        $this->assertStringNotContainsString('Used in layout', $html, 'img1 is only used through the playlist');
        [, , $html] = $s->get('content.php?action=delete&id=' . self::$id['video']);
        $this->assertStringContainsString('Used in layout', $html);
        $this->assertStringContainsString('leaves that zone empty', $html);
        // A playlist can hold a layout; the playlist editor lists it with its icon.
        [, , $html] = $s->get('playlists.php?action=new');
        $this->assertStringContainsString('Split screen layout', $html);
        $this->assertStringContainsString('bi-grid-1x2', $html);

        $this->assertSame('', TestEnv::phpErrors());
        DB::delete('content_items', 'id = :id', ['id' => $row['id']]);
    }

    // ------------------------------------------------------------------ TV contract

    private static function register(string $uid, string $room, ?int $code): string
    {
        $body = ['device_id' => $uid, 'room_number' => $room, 'registration_key' => (string) Settings::get('registration_key')];
        if ($code !== null) {
            $body['app_version'] = $code >= 10 ? '2.3.0' : '2.2.2';
            $body['app_version_code'] = $code;
        }
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', $body);
        self::assertSame(200, $st);
        return (string) $j['data']['token'];
    }

    private static function poll(string $uid, string $token): array
    {
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, ['Authorization: Bearer ' . $token, 'X-Device-Id: ' . $uid]);
        self::assertSame(200, $st);
        return $j['data']['content'];
    }

    public function testTvPollContract(): void
    {
        $lid = self::createLayout('Lobby ' . self::XSS, self::layoutInput(), 60);
        DB::update('rooms', ['content_id' => $lid], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $tok = self::register('tv-layout-new-0001', '101', 10);
        $c = self::poll('tv-layout-new-0001', $tok);
        $this->assertSame('assigned', $c['mode']);
        $this->assertCount(1, $c['items']);
        $it = $c['items'][0];
        $this->assertSame(['id', 'type', 'title', 'duration', 'layout'], array_keys($it));
        $this->assertSame([$lid, 'layout', 'Lobby ' . self::XSS, 0], [$it['id'], $it['type'], $it['title'], $it['duration']], 'single item: duration 0 like any single item');
        $this->assertSame(['bg_color', 'zones'], array_keys($it['layout']));
        $this->assertSame('#102030', $it['layout']['bg_color']);
        $zones = $it['layout']['zones'];
        $this->assertCount(3, $zones);
        foreach ($zones as $z) {
            $this->assertSame(self::ZONE_KEYS, array_keys($z));
            $this->assertIsBool($z['loop']);
            $this->assertIsBool($z['mute']);
        }
        $this->assertSame(['z1', 'z2', 'z3'], array_column($zones, 'id'));
        $this->assertEquals([[0, 0, 70, 100], [70, 0, 30, 85], [70, 85, 30, 15]], array_map(fn ($z) => [$z['x'], $z['y'], $z['w'], $z['h']], $zones));
        $this->assertSame(['zoom', 'fill', 'fit'], array_column($zones, 'scale'));
        $this->assertSame(['none', 'fade', 'fade'], array_column($zones, 'transition'));
        $this->assertSame([true, true, true], array_column($zones, 'loop'));
        // Audio: auto → the first zone with a video; all others muted.
        $this->assertSame([false, true, true], array_column($zones, 'mute'));

        // Zone items are exactly toTvItem() items: single content (duration 0), playlist expanded with
        // per-item durations, inactive item skipped.
        $video = ContentManager::toTvItem(ContentManager::findOwn(self::$id['video']));
        $video['duration'] = 0;
        $this->assertSame([$video], $zones[0]['items']);
        $this->assertSame([self::$id['img1'], self::$id['img2']], array_column($zones[1]['items'], 'id'), 'inactive item skipped');
        $this->assertSame([7, 12], array_column($zones[1]['items'], 'duration'), 'playlist override, then own duration');
        $this->assertSame(ContentManager::toTvItem(ContentManager::findOwn(self::$id['img1']), 7), $zones[1]['items'][0]);
        $this->assertSame('announcement', $zones[2]['items'][0]['type']);
        $this->assertSame('marquee', $zones[2]['items'][0]['style']);

        // Explicit audio zone and "no sound".
        DB::update('content_items', ['settings' => json_out(['audio' => 'z3'] + json_decode((string) DB::value('SELECT settings FROM content_items WHERE id = :id', ['id' => $lid]), true))], 'id = :id', ['id' => $lid]);
        Settings::bumpContentVersion();
        $this->assertSame([true, true, false], array_column(self::poll('tv-layout-new-0001', $tok)['items'][0]['layout']['zones'], 'mute'));
        $set = json_decode((string) DB::value('SELECT settings FROM content_items WHERE id = :id', ['id' => $lid]), true);
        $set['audio'] = 'none';
        DB::update('content_items', ['settings' => json_out($set)], 'id = :id', ['id' => $lid]);
        Settings::bumpContentVersion();
        $this->assertSame([true, true, true], array_column(self::poll('tv-layout-new-0001', $tok)['items'][0]['layout']['zones'], 'mute'));
        // Auto with no video anywhere → every zone muted.
        $this->assertSame([true], array_column(Layouts::toTv(['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'content_id' => self::$id['img1']]]])['zones'], 'mute'));

        // Inside a playlist the layout keeps its own duration; a layout inside a zone playlist is skipped.
        $pl = DB::insert('content_playlists', ['name' => 'Day loop', 'transition' => 'fade', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => self::$id['img1'], 'sort_order' => 0]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $lid, 'sort_order' => 1]);
        DB::insert('playlist_items', ['playlist_id' => self::$id['pl'], 'content_id' => $lid, 'sort_order' => 9]); // added later, bypassing validation
        DB::update('rooms', ['content_id' => null, 'playlist_id' => $pl], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $c = self::poll('tv-layout-new-0001', $tok);
        $this->assertSame(['image', 'layout'], array_column($c['items'], 'type'));
        $this->assertSame(60, $c['items'][1]['duration']);
        $this->assertSame([self::$id['img1'], self::$id['img2']], array_column($c['items'][1]['layout']['zones'][1]['items'], 'id'), 'no layout inside a layout');
        $this->assertStringNotContainsString('B-SECRET', json_encode($c, JSON_UNESCAPED_UNICODE));

        // Deleted / inactive sources do not break the layout: the zone is just empty.
        $tmp = DB::insert('content_items', ['title' => 'Temp', 'type' => 'image', 'url' => 'https://cdn.example.com/t.jpg', 'duration' => 5, 'created_at' => now()]);
        $tmpPl = DB::insert('content_playlists', ['name' => 'Temp PL', 'transition' => 'fade', 'created_at' => now()]);
        $l2 = self::createLayout('Fragile', ['zones' => [
            ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 100, 'source' => 'c:' . $tmp],
            ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 100, 'source' => 'p:' . $tmpPl],
        ]]);
        ContentManager::deleteItem($tmp);
        DB::delete('content_playlists', 'id = :id', ['id' => $tmpPl]);
        DB::update('rooms', ['content_id' => $l2, 'playlist_id' => null], 'id = :id', ['id' => self::$id['r102']]);
        Settings::bumpContentVersion();
        $tok2 = self::register('tv-layout-new-0002', '102', 11);
        $c = self::poll('tv-layout-new-0002', $tok2);
        $this->assertSame('layout', $c['items'][0]['type']);
        $this->assertSame([[], []], array_column($c['items'][0]['layout']['zones'], 'items'));
        DB::update('content_items', ['is_active' => 0], 'id = :id', ['id' => self::$id['video']]);
        $this->assertSame([], Layouts::zoneItems(['content_id' => self::$id['video']]), 'inactive single source');
        DB::update('content_items', ['is_active' => 1], 'id = :id', ['id' => self::$id['video']]);
        $this->assertSame([], Layouts::zoneItems(['content_id' => self::$id['foreign']]), 'never another hotel');
        $this->assertSame([], Layouts::zoneItems(['playlist_id' => self::$id['foreignPl']]));

        // Another hotel's ids planted in the settings are never resolved.
        DB::update('content_items', ['settings' => json_out(['zones' => [['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'content_id' => self::$id['foreign']]]])], 'id = :id', ['id' => $l2]);
        Settings::bumpContentVersion();
        $c = self::poll('tv-layout-new-0002', $tok2);
        $this->assertSame([], $c['items'][0]['layout']['zones'][0]['items']);
        $this->assertStringNotContainsString('B-SECRET', json_encode($c));
        $this->assertSame('', TestEnv::phpErrors());

        DB::delete('playlist_items', 'playlist_id = :p AND content_id = :c', ['p' => self::$id['pl'], 'c' => $lid]);
        DB::update('rooms', ['content_id' => null, 'playlist_id' => null], 'hotel_id = 1');
        DB::delete('content_playlists', 'id = :id', ['id' => $pl]);
        DB::delete('content_items', 'id IN (' . $lid . ',' . $l2 . ')');
        Settings::bumpContentVersion();
    }

    public function testOldAppFallback(): void
    {
        $lid = self::createLayout('Fallback', self::layoutInput(), 40);
        DB::update('rooms', ['content_id' => $lid], 'id = :id', ['id' => self::$id['r103']]);
        Settings::bumpContentVersion();
        $old = self::register('tv-layout-old-0001', '103', 9);
        $unknown = self::register('tv-layout-unk-0001', '103', null);
        $new = self::register('tv-layout-new-0003', '103', 10);

        $video = ContentManager::toTvItem(ContentManager::findOwn(self::$id['video']));
        $video['duration'] = 0;
        $cOld = self::poll('tv-layout-old-0001', $old);
        $this->assertSame([$video], $cOld['items'], 'largest zone (70 × 100 video) replaces the layout');
        $this->assertSame($cOld['items'], self::poll('tv-layout-unk-0001', $unknown)['items'], 'unknown version → fallback');
        $cNew = self::poll('tv-layout-new-0003', $new);
        $this->assertSame('layout', $cNew['items'][0]['type']);
        $this->assertNotSame($cOld['hash'], $cNew['hash']);
        $this->assertSame($cOld['hash'], sha1(json_out(array_diff_key($cOld, ['hash' => 1, 'generated_at' => 1]))), 'hash matches the downgraded object');
        // Hash stays stable between polls (no endless "content changed").
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command?hash=' . $cOld['hash'], null, ['Authorization: Bearer ' . $old, 'X-Device-Id: tv-layout-old-0001']);
        $this->assertFalse($j['data']['content_changed']);

        // The app is updated: its next heartbeat / register reports code 10 → layouts.
        DB::update('devices', ['app_version_code' => 10], "device_uid = 'tv-layout-old-0001'");
        $this->assertSame('layout', self::poll('tv-layout-old-0001', $old)['items'][0]['type']);

        // In a playlist: the layout becomes its largest zone's items; a single item keeps the layout's duration.
        $pl = DB::insert('content_playlists', ['name' => 'Old loop', 'transition' => 'fade', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => self::$id['img2'], 'sort_order' => 0]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $lid, 'sort_order' => 1]);
        DB::update('rooms', ['content_id' => null, 'playlist_id' => $pl], 'id = :id', ['id' => self::$id['r103']]);
        Settings::bumpContentVersion();
        $c = self::poll('tv-layout-unk-0001', $unknown);
        $this->assertSame([self::$id['img2'], self::$id['video']], array_column($c['items'], 'id'));
        $this->assertSame([12, 40], array_column($c['items'], 'duration'));
        $this->assertNotContains('layout', array_column($c['items'], 'type'));

        // Largest zone empty → the next largest with items; all empty → the layout is dropped.
        $item = ['type' => 'layout', 'duration' => 30, 'layout' => ['zones' => [
            ['w' => 80, 'h' => 100, 'items' => []],
            ['w' => 20, 'h' => 50, 'items' => [['id' => 1, 'type' => 'image', 'duration' => 5], ['id' => 2, 'type' => 'image', 'duration' => 6]]],
            ['w' => 20, 'h' => 50, 'items' => [['id' => 3, 'type' => 'image', 'duration' => 0]]],
        ]]];
        $this->assertSame([1, 2], array_column(Layouts::fallbackItems($item), 'id'), 'first wins on a tie; durations kept for several items');
        $this->assertSame([5, 6], array_column(Layouts::fallbackItems($item), 'duration'));
        $item['layout']['zones'][1]['items'] = [];
        $this->assertSame([['id' => 3, 'type' => 'image', 'duration' => 30]], Layouts::fallbackItems($item));
        $item['layout']['zones'][2]['items'] = [];
        $this->assertSame([], Layouts::fallbackItems($item));
        $content = ['items' => [['id' => 9, 'type' => 'image', 'duration' => 5]], 'hash' => 'x'];
        $this->assertSame($content, Layouts::downgradeContent($content), 'no layout → untouched');
        $this->assertTrue(Layouts::legacyDevice(['app_version_code' => 9]));
        $this->assertTrue(Layouts::legacyDevice(['app_version_code' => null]));
        $this->assertFalse(Layouts::legacyDevice(['app_version_code' => 10]));
        $this->assertSame('', TestEnv::phpErrors());

        DB::update('rooms', ['content_id' => null, 'playlist_id' => null], 'hotel_id = 1');
        DB::delete('content_playlists', 'id = :id', ['id' => $pl]);
        DB::delete('content_items', 'id = :id', ['id' => $lid]);
        Settings::bumpContentVersion();
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = ['Split screen layout', 'Used in layout :t', 'Deleting it leaves that zone empty.'];
        foreach (['core/Layouts.php', 'admin/partials/layout_editor.php'] as $f) {
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
    }

    // ------------------------------------------------------------------ simulator & XSS

    public function testSimulatorAndXss(): void
    {
        $lid = self::createLayout(self::XSS, self::layoutInput());
        $pl = DB::insert('content_playlists', ['name' => 'Sim', 'transition' => 'fade', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $lid, 'sort_order' => 0, 'duration' => 20]);
        DB::update('rooms', ['content_id' => $lid], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $s = new AdminSession(self::$url, 'lyMgr');
        foreach (['content_id=' . $lid, 'playlist_id=' . $pl, 'room_id=' . self::$id['r101'], 'content_id=' . $lid . '&embed=1'] as $q) {
            [$code, , $html] = $s->get('preview.php?' . $q);
            $this->assertSame(200, $code, $q);
            $this->assertFalse(TestEnv::hasPhpError($html), $q);
            $this->assertStringContainsString("case 'layout'", $html, 'simulator renders layouts');
            $this->assertStringContainsString('function zonePlayer', $html);
            preg_match('#<script type="application/json" id="contentData">(.*?)</script>#s', $html, $m);
            $obj = json_decode($m[1] ?? '', true);
            $this->assertIsArray($obj, $q);
            $this->assertSame('layout', $obj['items'][0]['type'], $q);
            $this->assertCount(3, $obj['items'][0]['layout']['zones'], $q);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $q);
            $this->assertStringNotContainsString('<img src=x', $html, $q);
        }
        foreach (['content.php', 'content.php?view=list', 'content.php?action=edit&id=' . $lid, 'content.php?action=delete&id=' . self::$id['ann'], 'playlists.php', 'playlists.php?action=edit&id=' . $pl, 'content.php?action=new&type=layout'] as $page) {
            [$code, , $html] = $s->get($page);
            $this->assertSame(200, $code, $page);
            $this->assertFalse(TestEnv::hasPhpError($html), $page);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $page);
            $this->assertStringNotContainsString('<img src=x', $html, $page);
        }
        [, , $html] = $s->get('content.php?view=list');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'escaped, not dropped');
        $this->assertSame('', TestEnv::phpErrors());

        DB::update('rooms', ['content_id' => null], 'hotel_id = 1');
        DB::delete('content_playlists', 'id = :id', ['id' => $pl]);
        DB::delete('content_items', 'id = :id', ['id' => $lid]);
        Settings::bumpContentVersion();
    }
}
