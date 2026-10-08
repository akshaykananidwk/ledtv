<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Video walls (#36) and synchronized playback (#37), 2.4: wall CRUD and tile validation (grid bounds,
 * each room in at most one wall), tenancy, per-user TV access, the TV poll contract per tile (`wall`,
 * `sync`, `server_time_ms`), a stable sync epoch across polls, the content hash changing with the wall,
 * synced playlists (duration rules, admin switch), "Identify" and old apps. docs/modules/video_wall_sync.md
 */
final class VideoWallTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    /** @var array<string, string> room number => device token */
    private static array $tok = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        self::$id['mgr'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'vwMgr', 'email' => 'vwmgr@t.test', 'full_name' => 'Vw Mgr', 'password_hash' => $pw, 'role' => 'manager']);
        self::$id['limited'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'vwDesk', 'email' => 'vwdesk@t.test', 'full_name' => 'Vw Desk', 'password_hash' => $pw, 'role' => 'manager']);
        self::$id['staff'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'vwStaff', 'email' => 'vwstaff@t.test', 'full_name' => 'Vw Staff', 'password_hash' => $pw, 'role' => 'staff']);
        foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'R1', 'R2'] as $n) {
            self::$id[$n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Lobby ' . $n, 'floor' => '0']);
        }
        $c = static fn (array $row): int => DB::insert('content_items', $row + ['duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        self::$id['video'] = $c(['title' => 'Wall video', 'type' => 'video', 'url' => 'https://cdn.example.com/wall.mp4', 'settings' => '{"loop":true,"mute":false}', 'duration' => 30]);
        self::$id['video0'] = $c(['title' => 'Video no length', 'type' => 'video', 'url' => 'https://cdn.example.com/v0.mp4', 'settings' => '{"loop":false,"mute":false}', 'duration' => 0]);
        self::$id['videoMedia'] = $c(['title' => 'Video known length', 'type' => 'video', 'url' => 'https://cdn.example.com/v1.mp4', 'settings' => '{"loop":false,"mute":false,"media_duration_ms":12500}', 'duration' => 0]);
        self::$id['img1'] = $c(['title' => 'Slide 1', 'type' => 'image', 'url' => 'https://cdn.example.com/s1.jpg', 'duration' => 8]);
        self::$id['img2'] = $c(['title' => 'Slide 2', 'type' => 'image', 'url' => 'https://cdn.example.com/s2.jpg', 'duration' => 0]);
        self::$id['ann'] = $c(['title' => 'Notice', 'type' => 'announcement', 'body' => 'Welcome', 'duration' => 5]);
        self::$id['layout'] = $c(['title' => 'A layout', 'type' => 'layout', 'settings' => '{"zones":[]}', 'duration' => 60]);
        self::$id['pl'] = DB::insert('content_playlists', ['name' => 'Wall loop', 'transition' => 'slide', 'created_at' => now()]);
        foreach ([[self::$id['video'], null], [self::$id['img1'], 6], [self::$id['img2'], null], [self::$id['layout'], null]] as $i => [$cid, $dur]) {
            DB::insert('playlist_items', ['playlist_id' => self::$id['pl'], 'content_id' => $cid, 'sort_order' => $i, 'duration' => $dur]);
        }
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'vwBoss2', 'email' => 'vw2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['foreignRoom'] = DB::insert('rooms', ['room_number' => 'B1', 'name' => 'B-SECRET-ROOM', 'floor' => '1']);
            self::$id['foreignContent'] = DB::insert('content_items', ['title' => 'B-SECRET', 'type' => 'image', 'url' => 'https://b.example.com/x.jpg', 'duration' => 5, 'created_at' => now()]);
        });
        Tenant::set(1);
        Access::setForUser(self::$id['limited'], [['room', self::$id['L5']], ['room', self::$id['L6']]]);
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        Tenant::forget();
        Settings::flush();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        Access::$userOverride = null;
        Access::forget();
    }

    private static function wallInput(array $override = []): array
    {
        return $override + [
            'name' => 'Lobby wall', 'rows' => 2, 'cols' => 2, 'source' => 'c:' . self::$id['video'],
            'tiles' => ['0_0' => self::$id['L1'], '0_1' => self::$id['L2'], '1_0' => self::$id['L3'], '1_1' => self::$id['L4']],
            'audio' => '0_1', 'is_active' => 1, 'bezel_mm' => '', 'screen_w_mm' => '', 'screen_h_mm' => '',
        ];
    }

    private static function errorsOf(array $in, ?int $wallId = null): string
    {
        [, , $errors] = VideoWalls::validate($in, $wallId);
        return implode("\n", $errors);
    }

    private static function register(string $room, ?int $code = 11): string
    {
        $uid = 'tv-wall-' . strtolower($room) . '-' . ($code ?? 0) . '-000';
        $body = ['device_id' => $uid, 'room_number' => $room, 'registration_key' => (string) Settings::get('registration_key')];
        if ($code !== null) {
            $body['app_version'] = $code >= 11 ? '2.4.0' : '2.3.0';
            $body['app_version_code'] = $code;
        }
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', $body);
        self::assertSame(200, $st, (string) json_encode($j));
        return $uid . '|' . $j['data']['token'];
    }

    /** @return array full poll `data` */
    private static function poll(string $cred, string $hash = ''): array
    {
        [$uid, $token] = explode('|', $cred);
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command?hash=' . $hash, null, ['Authorization: Bearer ' . $token, 'X-Device-Id: ' . $uid]);
        self::assertSame(200, $st);
        return $j['data'];
    }

    // ------------------------------------------------------------------ validation / CRUD

    public function testValidationAndCrud(): void
    {
        [$data, $tiles, $errors] = VideoWalls::validate(self::wallInput(['bezel_mm' => '10', 'screen_w_mm' => '1000', 'screen_h_mm' => '560']));
        $this->assertSame([], $errors);
        $this->assertSame([2, 2, self::$id['video'], null, 0, 1], [$data['rows_count'], $data['cols_count'], $data['content_id'], $data['playlist_id'], $data['audio_row'], $data['audio_col']]);
        $this->assertSame([[0, 0, self::$id['L1']], [0, 1, self::$id['L2']], [1, 0, self::$id['L3']], [1, 1, self::$id['L4']]], $tiles);

        // Grid bounds: rows / cols 1..4, at least 2 TVs; tiles inside the grid.
        $this->assertStringContainsString('1 to 4 rows', self::errorsOf(self::wallInput(['rows' => 5])));
        $this->assertStringContainsString('1 to 4 rows', self::errorsOf(self::wallInput(['rows' => 0])));
        $this->assertStringContainsString('1 to 4 rows', self::errorsOf(self::wallInput(['rows' => 1, 'cols' => 1, 'tiles' => ['0_0' => self::$id['L1']]])));
        $this->assertStringContainsString('outside the grid', self::errorsOf(self::wallInput(['tiles' => ['2_0' => self::$id['L1']]])));
        $this->assertStringContainsString('outside the grid', self::errorsOf(self::wallInput(['rows' => 1, 'cols' => 4, 'tiles' => ['0_4' => self::$id['L1']]])));
        $this->assertStringContainsString('outside the grid', self::errorsOf(self::wallInput(['tiles' => ['x' => self::$id['L1']]])));
        [, $t, $e] = VideoWalls::validate(self::wallInput(['rows' => 1, 'cols' => 4, 'tiles' => ['0_0' => self::$id['L1'], '0_3' => self::$id['L2']]]));
        $this->assertSame([], $e, '1×4 with gaps is fine');
        $this->assertCount(2, $t);
        // A room once per wall.
        $this->assertStringContainsString('more than one tile', self::errorsOf(self::wallInput(['tiles' => ['0_0' => self::$id['L1'], '0_1' => self::$id['L1']]])));
        // Content rules: required, no layout, videos need a length.
        $this->assertStringContainsString('Choose what the wall plays', self::errorsOf(self::wallInput(['source' => ''])));
        $this->assertStringContainsString('Choose what the wall plays', self::errorsOf(self::wallInput(['source' => 'c:999999'])));
        $this->assertStringContainsString('cannot be shown on a video wall', self::errorsOf(self::wallInput(['source' => 'c:' . self::$id['layout']])));
        $this->assertStringContainsString('needs a length in seconds', self::errorsOf(self::wallInput(['source' => 'c:' . self::$id['video0']])));
        $this->assertSame('', self::errorsOf(self::wallInput(['source' => 'c:' . self::$id['videoMedia']])), 'stored media length is enough');
        $this->assertSame('', self::errorsOf(self::wallInput(['source' => 'p:' . self::$id['pl']])), 'playlist video has its own 30 s');
        $this->assertStringContainsString('bezel compensation', self::errorsOf(self::wallInput(['bezel_mm' => '8'])));
        $this->assertStringContainsString('Wall name', self::errorsOf(self::wallInput(['name' => ' '])));
        // Bad audio tile → first tile; "none" → no sound anywhere.
        [$d] = VideoWalls::validate(self::wallInput(['audio' => '3_3']));
        $this->assertSame([0, 0], [$d['audio_row'], $d['audio_col']]);
        [$d] = VideoWalls::validate(self::wallInput(['audio' => 'none']));
        $this->assertSame(VideoWalls::AUDIO_NONE, $d['audio_row']);

        // Save, then a second wall cannot reuse the rooms; editing the first one can.
        $id = VideoWalls::save(null, $data, $tiles, self::$id['mgr']);
        $this->assertCount(4, VideoWalls::tiles($id));
        $this->assertStringContainsString('already part of the wall "Lobby wall"', self::errorsOf(self::wallInput(['name' => 'Second', 'tiles' => ['0_0' => self::$id['L1'], '0_1' => self::$id['L5']]])));
        $this->assertSame('', self::errorsOf(self::wallInput(), $id));
        [$d2, $t2] = VideoWalls::validate(self::wallInput(['tiles' => ['0_0' => self::$id['L2'], '0_1' => self::$id['L1']]]), $id);
        VideoWalls::save($id, $d2, $t2);
        $this->assertSame([self::$id['L2'], self::$id['L1']], array_map('intval', array_column(VideoWalls::tiles($id), 'room_id')));
        // The database refuses a room in two walls too.
        $other = VideoWalls::save(null, ['name' => 'Other'] + $d2, []);
        $this->expectException(PDOException::class);
        try {
            DB::insert('video_wall_tiles', ['wall_id' => $other, 'room_id' => self::$id['L1'], 'row_index' => 0, 'col_index' => 0]);
        } finally {
            VideoWalls::delete($other);
            VideoWalls::delete($id);
            $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM video_wall_tiles WHERE wall_id = :w', ['w' => $id]), 'tiles go with the wall');
        }
    }

    public function testTenancy(): void
    {
        // Another hotel's room or content → cross-hotel denial.
        try {
            VideoWalls::validate(self::wallInput(['tiles' => ['0_0' => self::$id['foreignRoom']]]));
            $this->fail('foreign room accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        try {
            VideoWalls::validate(self::wallInput(['source' => 'c:' . self::$id['foreignContent']]));
            $this->fail('foreign content accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        [$d, $t] = VideoWalls::validate(self::wallInput(['name' => 'H1 wall']));
        $id = VideoWalls::save(null, $d, $t);
        Tenant::run(2, function () use ($id): void {
            $this->assertSame([], VideoWalls::all());
            $this->assertNull(VideoWalls::forRoom(self::$id['L1']));
            try {
                VideoWalls::find($id);
                $this->fail('hotel 2 can read hotel 1 wall');
            } catch (TenantException) {
                $this->addToAssertionCount(1);
            }
        });
        $this->assertSame(1, (int) DB::value('SELECT hotel_id FROM video_wall_tiles WHERE wall_id = :w LIMIT 1', ['w' => $id]));
        VideoWalls::delete($id);
    }

    public function testAccessForRestrictedUsers(): void
    {
        [$d, $t] = VideoWalls::validate(self::wallInput(['name' => 'Big lobby']));
        $big = VideoWalls::save(null, $d, $t);
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['limited']]);
        Access::forget();
        try {
            $this->assertTrue(Access::restricted());
            $this->assertSame([], array_column(VideoWalls::all(), 'id'), 'a wall with other TVs is hidden');
            try {
                VideoWalls::validate(self::wallInput(['name' => 'Mine', 'tiles' => ['0_0' => self::$id['L5'], '0_1' => self::$id['R1']]]));
                $this->fail('restricted user placed another TV');
            } catch (TenantException $e) {
                $this->assertStringContainsString('not one of your TVs', $e->getMessage());
            }
            [$d, $t, $e] = VideoWalls::validate(self::wallInput(['name' => 'Mine', 'tiles' => ['0_0' => self::$id['L5'], '0_1' => self::$id['L6']]]));
            $this->assertSame([], $e);
            $mine = VideoWalls::save(null, $d, $t);
            $this->assertSame([$mine], array_map('intval', array_column(VideoWalls::all(), 'id')));
            foreach (['save' => fn () => VideoWalls::save($big, $d, []), 'delete' => fn () => VideoWalls::delete($big), 'identify' => fn () => VideoWalls::identify(VideoWalls::find($big))] as $what => $fn) {
                try {
                    $fn();
                    $this->fail("restricted user could $what a wall with other TVs");
                } catch (TenantException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            Access::$userOverride = null;
            Access::forget();
        }

        // Over HTTP: the list hides the big wall, its edit page and a foreign room are 403.
        $s = new AdminSession(self::$url, 'vwDesk');
        [$code, , $html] = $s->get('video_walls.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('Mine', $html);
        $this->assertStringNotContainsString('Big lobby', $html);
        [$code] = $s->get('video_walls.php?action=edit&id=' . $big);
        $this->assertSame(403, $code);
        [$code, , $html] = $s->get('video_walls.php?action=new');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('Lobby R1', $html, 'only own TVs offered');
        $this->assertStringContainsString('Lobby L5', $html);
        $in = self::wallInput(['name' => 'Sneaky', 'tiles' => null]);
        unset($in['tiles']);
        [$code] = $s->post('video_walls.php', ['op' => 'save', 'id' => 0] + $in + ['tiles[0_0]' => self::$id['R1'], 'tiles[0_1]' => self::$id['L6']]);
        $this->assertSame(403, $code);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM video_walls WHERE name = 'Sneaky'"));
        // Staff has no permission at all.
        $st = new AdminSession(self::$url, 'vwStaff');
        [$code] = $st->get('video_walls.php');
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
        VideoWalls::delete($big);
        VideoWalls::delete($mine);
    }

    public function testAdminPagesOverHttp(): void
    {
        $s = new AdminSession(self::$url, 'vwMgr');
        [$code, , $html] = $s->get('video_walls.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('No video walls yet', $html);
        $this->assertStringContainsString('video_walls.php', $html, 'sidebar entry');
        [$code, , $html] = $s->get('video_walls.php?action=new');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('id="vwGrid"', $html);
        $this->assertStringContainsString('name="tiles[3_3]"', $html);
        $this->assertStringNotContainsString('B-SECRET', $html);

        $in = self::wallInput(['name' => 'HTTP wall <b>x</b>']);
        $fields = ['op' => 'save', 'id' => 0] + array_diff_key($in, ['tiles' => 1]);
        foreach ($in['tiles'] as $k => $rid) {
            $fields["tiles[$k]"] = $rid;
        }
        // CSRF required.
        [$code] = TestEnv::http('POST', self::$url . 'admin/video_walls.php', null, [], $s->jar, $fields);
        $this->assertSame(419, $code);
        // Invalid input: the form comes back with the error, nothing saved.
        [$code, , $html] = $s->post('video_walls.php', ['source' => 'c:' . self::$id['video0']] + $fields);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('needs a length in seconds', $html);
        $this->assertStringContainsString('HTTP wall &lt;b&gt;x&lt;/b&gt;', $html, 'entered values kept, escaped');
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM video_walls WHERE name LIKE 'HTTP wall%'"));
        $s->post('video_walls.php', $fields);
        $wall = DB::one("SELECT * FROM video_walls WHERE name LIKE 'HTTP wall%'");
        $this->assertNotNull($wall);
        $this->assertCount(4, VideoWalls::tiles((int) $wall['id']));
        [$code, , $html] = $s->get('video_walls.php');
        $this->assertStringContainsString('HTTP wall &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('HTTP wall <b>', $html);
        $this->assertStringContainsString('2 × 2', $html);
        [$code, , $html] = $s->get('video_walls.php?action=edit&id=' . $wall['id']);
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('/name="tiles\[1_1\]".*?value="' . self::$id['L4'] . '" selected/s', $html);

        // Identify: one SHOW_MESSAGE per registered TV with its tile number.
        $credL3 = self::register('L3');
        $before = (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM device_commands');
        $s->post('video_walls.php', ['op' => 'identify', 'id' => $wall['id']]);
        $cmds = DB::all("SELECT command, payload FROM device_commands WHERE id > :b AND command = 'SHOW_MESSAGE'", ['b' => $before]);
        $this->assertCount(1, $cmds);
        $p = json_decode((string) $cmds[0]['payload'], true);
        $this->assertSame('3', $p['title'], 'row 2, column 1 of a 2×2 wall is tile 3');
        $this->assertStringContainsString('Row 2, column 1', $p['message']);
        $this->assertSame(15, $p['duration_sec']);
        $this->assertNotEmpty(self::poll($credL3)['commands']);

        // Delete.
        $s->post('video_walls.php', ['op' => 'delete', 'id' => $wall['id']]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM video_walls WHERE id = :id', ['id' => $wall['id']]));
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ TV contract

    public function testTvPollContractPerTile(): void
    {
        DB::update('rooms', ['content_id' => self::$id['ann']], 'id = :id', ['id' => self::$id['L1']]);
        Settings::set('overlay_clock', '1');
        [$d, $t] = VideoWalls::validate(self::wallInput(['name' => 'Contract wall', 'source' => 'p:' . self::$id['pl'], 'bezel_mm' => '10', 'screen_w_mm' => '1000', 'screen_h_mm' => '560']));
        $wallId = VideoWalls::save(null, $d, $t);
        $epoch = (int) DB::value('SELECT sync_epoch_ms FROM video_walls WHERE id = :id', ['id' => $wallId]);
        $this->assertEqualsWithDelta(SyncPlayback::nowMs(), $epoch, 60000);

        $creds = [];
        foreach (['L1', 'L2', 'L3', 'L4'] as $r) {
            $creds[$r] = self::register($r);
        }
        $expected = ['L1' => [0, 0, false], 'L2' => [0, 1, true], 'L3' => [1, 0, false], 'L4' => [1, 1, false]];
        $hashes = [];
        $first = null;
        foreach ($creds as $r => $cred) {
            $t0 = SyncPlayback::nowMs();
            $data = self::poll($cred);
            $this->assertIsInt($data['server_time_ms']);
            $this->assertGreaterThanOrEqual($t0 - 5, $data['server_time_ms']);
            $this->assertLessThanOrEqual(SyncPlayback::nowMs() + 5, $data['server_time_ms']);
            $c = $data['content'];
            $this->assertSame('wall', $c['mode'], $r . ' room content is replaced');
            [$row, $col, $audio] = $expected[$r];
            $this->assertSame(['id' => $wallId, 'rows' => 2, 'cols' => 2, 'row' => $row, 'col' => $col, 'bezel_x_pct' => 1, 'bezel_y_pct' => 1.786, 'audio' => $audio], $c['wall']);
            $this->assertSame([self::$id['video'], self::$id['img1'], self::$id['img2']], array_column($c['items'], 'id'), 'layout skipped');
            $this->assertSame([30, 6, 10], array_column($c['items'], 'duration'), 'img2 gets the 10 s default');
            $this->assertSame(['epoch_ms' => $epoch, 'cycle_ms' => 46000, 'item_offsets_ms' => [0, 30000, 36000]], $c['sync']);
            $this->assertSame('fade', $c['playlist']['transition'], 'slide is not used on a wall');
            $this->assertFalse($c['overlay']['clock']);
            $this->assertNull($c['overlay']['ticker']);
            $this->assertArrayNotHasKey('ads', $c);
            $hashes[$r] = $c['hash'];
            $first ??= $c;
        }
        $this->assertCount(4, array_unique($hashes), 'each tile has its own content');

        // The epoch is stable across polls: same hash, nothing re-sent.
        Cache::clear();
        sleep(1);
        $again = self::poll($creds['L1']);
        $this->assertSame($hashes['L1'], $again['content_hash']);
        $again = self::poll($creds['L1'], $hashes['L1']);
        $this->assertFalse($again['content_changed']);
        $this->assertArrayNotHasKey('content', $again);
        Tenant::set(1);
        $built = ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['L1']]));
        $this->assertSame($epoch, $built['sync']['epoch_ms']);

        // The hash changes when the wall changes (bezel), and the schedule restarts (new epoch).
        usleep(5000);
        [$d, $t] = VideoWalls::validate(self::wallInput(['name' => 'Contract wall', 'source' => 'p:' . self::$id['pl'], 'bezel_mm' => '12', 'screen_w_mm' => '1000', 'screen_h_mm' => '560']), $wallId);
        VideoWalls::save($wallId, $d, $t);
        $changed = self::poll($creds['L1'], $hashes['L1']);
        $this->assertTrue($changed['content_changed']);
        $this->assertSame(1.2, $changed['content']['wall']['bezel_x_pct']);
        $this->assertGreaterThan($epoch, $changed['content']['sync']['epoch_ms']);

        // Wall switched off → the room's own content again (no wall / sync keys).
        DB::update('video_walls', ['is_active' => 0], 'id = :id', ['id' => $wallId]);
        Settings::bumpContentVersion();
        $own = self::poll($creds['L1'])['content'];
        $this->assertSame('assigned', $own['mode']);
        $this->assertSame([self::$id['ann']], array_column($own['items'], 'id'));
        $this->assertArrayNotHasKey('wall', $own);
        $this->assertArrayNotHasKey('sync', $own);
        $this->assertTrue($own['overlay']['clock']);
        DB::update('video_walls', ['is_active' => 1], 'id = :id', ['id' => $wallId]);
        Settings::bumpContentVersion();

        // Emergency wins over the wall.
        $bid = DB::insert('broadcast_commands', ['title' => 'Fire', 'command' => 'SHOW_MESSAGE', 'target_type' => 'all', 'target_ids' => '[]', 'payload' => '{"title":"Fire","message":"Leave"}', 'is_emergency' => 1, 'status' => 'active', 'mode' => 'once', 'created_at' => now()]);
        Settings::bumpContentVersion();
        $em = self::poll($creds['L2'])['content'];
        $this->assertSame('emergency', $em['mode']);
        $this->assertArrayNotHasKey('wall', $em);
        DB::delete('broadcast_commands', 'id = :id', ['id' => $bid]);
        Settings::bumpContentVersion();

        // Single video on a wall: one item that loops, cycle = its length.
        [$d, $t] = VideoWalls::validate(self::wallInput(['name' => 'Contract wall', 'source' => 'c:' . self::$id['videoMedia']]), $wallId);
        VideoWalls::save($wallId, $d, $t);
        $c = self::poll($creds['L4'])['content'];
        $this->assertCount(1, $c['items']);
        $this->assertTrue($c['items'][0]['loop']);
        $this->assertSame(12500, $c['sync']['cycle_ms']);
        $this->assertSame([0], $c['sync']['item_offsets_ms']);
        $this->assertNull($c['playlist']);

        // Old app (2.3, code 10): gets the same items and simply ignores wall / sync — the whole picture.
        $old = self::register('L3', 10);
        $c = self::poll($old)['content'];
        $this->assertSame([self::$id['videoMedia']], array_column($c['items'], 'id'));
        $this->assertSame('https://cdn.example.com/v1.mp4', $c['items'][0]['url']);
        $this->assertSame('', TestEnv::phpErrors());
        VideoWalls::delete($wallId);
        DB::update('rooms', ['content_id' => null], 'id = :id', ['id' => self::$id['L1']]);
        Settings::set('overlay_clock', '0');
    }

    // ------------------------------------------------------------------ synced playlists (#37)

    public function testSyncPlan(): void
    {
        $items = [
            ['id' => self::$id['img1'], 'type' => 'image', 'duration' => 8],
            ['id' => self::$id['videoMedia'], 'type' => 'video', 'duration' => 0],
            ['id' => 0, 'type' => 'layout', 'duration' => 0],
            ['id' => self::$id['ann'], 'type' => 'announcement', 'duration' => 0],
        ];
        $plan = SyncPlayback::plan($items, 1_700_000_000_000);
        $this->assertSame(['epoch_ms' => 1_700_000_000_000, 'cycle_ms' => 8000 + 12500 + 60000 + 10000, 'item_offsets_ms' => [0, 8000, 20500, 80500]], $plan);
        $this->assertSame([8, 13, 60, 10], array_column($items, 'duration'), 'TV durations follow the schedule');
        $bad = [['id' => self::$id['video0'], 'type' => 'video', 'duration' => 0], ['id' => 1, 'type' => 'image', 'duration' => 5]];
        $this->assertNull(SyncPlayback::plan($bad, 0), 'a video without any length cannot be synced');
        $empty = [];
        $this->assertNull(SyncPlayback::plan($empty, 0));
        $this->assertSame(['Video no length'], SyncPlayback::missingDurations([[self::$id['video0'], null], [self::$id['video0'], 0], [self::$id['img2'], null], [self::$id['videoMedia'], null]]));
        $this->assertSame([], SyncPlayback::missingDurations([[self::$id['video0'], 20]]), 'playlist seconds are enough');
    }

    public function testSyncedPlaylistOnGroupAndDefault(): void
    {
        $pl = DB::insert('content_playlists', ['name' => 'Restaurant sync', 'transition' => 'fade', 'created_at' => now()]);
        foreach ([[self::$id['video'], null], [self::$id['img1'], null]] as $i => [$cid, $dur]) {
            DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $cid, 'sort_order' => $i, 'duration' => $dur]);
        }
        $g = DB::insert('room_groups', ['name' => 'Restaurant', 'type' => 'custom', 'playlist_id' => $pl, 'created_at' => now()]);
        foreach (['R1', 'R2'] as $r) {
            DB::insert('room_group_members', ['room_id' => self::$id[$r], 'group_id' => $g]);
        }
        Settings::bumpContentVersion();
        $r1 = self::register('R1');
        $r2 = self::register('R2');
        $c = self::poll($r1)['content'];
        $this->assertSame('group', $c['mode']);
        $this->assertArrayNotHasKey('sync', $c, 'not synced until switched on');

        // Switch on in the playlist editor (admin): epoch set, both TVs get the same schedule.
        $s = new AdminSession(self::$url, 'vwMgr');
        [$code, , $html] = $s->get('playlists.php?action=edit&id=' . $pl);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('name="sync_playback"', $html);
        $form = ['op' => 'save', 'id' => $pl, 'name' => 'Restaurant sync', 'transition' => 'fade', 'sync_playback' => 1,
            'items[0][content_id]' => self::$id['video'], 'items[0][duration]' => '', 'items[1][content_id]' => self::$id['img1'], 'items[1][duration]' => '7'];
        $s->post('playlists.php', $form);
        $row = DB::one('SELECT * FROM content_playlists WHERE id = :id', ['id' => $pl]);
        $this->assertSame(1, (int) $row['sync_playback']);
        $this->assertGreaterThan(0, (int) $row['sync_epoch_ms']);
        $c1 = self::poll($r1)['content'];
        $c2 = self::poll($r2)['content'];
        $want = ['epoch_ms' => (int) $row['sync_epoch_ms'], 'cycle_ms' => 37000, 'item_offsets_ms' => [0, 30000]];
        $this->assertSame($want, $c1['sync']);
        $this->assertSame($want, $c2['sync']);
        $this->assertArrayNotHasKey('wall', $c1);
        [, , $html] = $s->get('playlists.php');
        $this->assertStringContainsString('Synced', $html);

        // Stable across polls (no new hash) …
        Cache::clear();
        sleep(1);
        $this->assertSame($c1['hash'], self::poll($r1)['content_hash']);

        // … a video without a length → the switch stays off with a warning.
        $form['items[2][content_id]'] = self::$id['video0'];
        $form['items[2][duration]'] = '';
        [, , $html] = $s->post('playlists.php', $form);
        $this->assertSame(0, (int) DB::value('SELECT sync_playback FROM content_playlists WHERE id = :id', ['id' => $pl]));
        [, , $html] = $s->get('playlists.php?action=edit&id=' . $pl);
        $this->assertStringContainsString('Sync playback was not switched on', $html);
        $this->assertStringContainsString('Video no length', $html);
        // With seconds for it, it works; as hotel default ("all") every TV is in step.
        $form['items[2][duration]'] = '15';
        $s->post('playlists.php', $form);
        $this->assertSame(1, (int) DB::value('SELECT sync_playback FROM content_playlists WHERE id = :id', ['id' => $pl]));
        DB::delete('room_groups', 'id = :id', ['id' => $g]);
        Settings::set('default_playlist_id', (string) $pl);
        Settings::bumpContentVersion();
        $c = self::poll($r2)['content'];
        $this->assertSame('default', $c['mode']);
        $this->assertSame(52000, $c['sync']['cycle_ms']);
        $this->assertSame([0, 30000, 37000], $c['sync']['item_offsets_ms']);

        // A content item edited later to have no length: sync is dropped, the playlist still plays.
        DB::update('playlist_items', ['duration' => null], 'playlist_id = :p AND content_id = :c', ['p' => $pl, 'c' => self::$id['video0']]);
        Settings::bumpContentVersion();
        $c = self::poll($r2)['content'];
        $this->assertArrayNotHasKey('sync', $c);
        $this->assertCount(3, $c['items']);
        Settings::set('default_playlist_id', '');
        Settings::bumpContentVersion();
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = ['Video wall', 'Sync playback', 'Synced', 'Sync playback was not switched on. Enter the seconds for these videos: :t'];
        foreach (['core/VideoWalls.php', 'core/SyncPlayback.php', 'admin/video_walls.php', 'admin/partials/nav.d/18_video_walls.php'] as $f) {
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
}
