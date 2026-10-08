<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Emergency alarm and message sounds (2.4.1, docs/modules/emergency_alarm.md): migration 027, the built-in
 * alarm / chime WAVs, the emergency form options → `content.emergency.alarm` in the TV poll, "Silence alarm
 * on all TVs", stop, the sound-library restriction (built-in or the hotel's own uploads only, like PLAY_SOUND),
 * CSRF, users limited to some TVs, the SHOW_MESSAGE `sound` payload, the notice board chime, emergency rows
 * from before 2.4.1 and the Gujarati / Hindi strings.
 */
final class EmergencyAlarmTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    /** @var array<string, array{uid: string, token: string, id: int}> */
    private static array $tv = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['alMgr' => 'manager', 'alBoss' => 'super_admin', 'alDesk' => 'staff'] as $u => $role) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@al.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101', '102'] as $n) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => '1']);
        }
        // alDesk (staff) is limited to room 101 (core/Access.php).
        DB::query("INSERT INTO user_access (hotel_id, user_id, target_type, target_id) VALUES (1, :u, 'room', :r)", ['u' => self::$id['alDesk'], 'r' => self::$id['r101']]);
        self::$id['h1sound'] = DB::insert('sounds', ['name' => 'Lobby gong', 'file_path' => 'h1/sounds/2026/10/gong.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 10, 'created_at' => now()]);
        self::$id['h2'] = Hotels::create(['name' => 'Hotel Two']);
        Tenant::run(self::$id['h2'], static function (): void {
            self::$id['h2sound'] = DB::insert('sounds', ['name' => 'H2 siren', 'file_path' => 'h' . self::$id['h2'] . '/sounds/2026/10/h2.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 10, 'created_at' => now()]);
        });
        Tenant::set(1);
        Settings::setPlatform('task_last_DeviceHealthTask', (string) (time() + 86400));
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $key = (string) Settings::get('registration_key');
        foreach (['tv101' => '101', 'tv102' => '102'] as $name => $room) {
            $uid = 'tv-alarm-' . $name . '-0001';
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key, 'app_version' => '2.4.1', 'app_version_code' => 12]);
            self::assertSame(200, $s, (string) json_encode($j));
            self::$tv[$name] = ['uid' => $uid, 'token' => (string) $j['data']['token'],
                'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u', ['u' => $uid])];
        }
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        Tenant::set(1);
        Settings::flush();
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        Access::$userOverride = null;
        Access::forget();
        Cache::clear();
    }

    protected function tearDown(): void
    {
        Tenant::set(1);
        DB::query("UPDATE broadcast_commands SET status = 'completed' WHERE is_emergency = 1 AND status = 'active'");
        Settings::bumpContentVersion();
    }

    private static function dev(string $tv): array
    {
        return ['Authorization: Bearer ' . self::$tv[$tv]['token'], 'X-Device-Id: ' . self::$tv[$tv]['uid']];
    }

    /** The content object a TV gets from the command poll (hash "x" = always changed). */
    private static function content(string $tv): array
    {
        Cache::clear();
        [$s, $j, $raw] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv[$tv]['uid'] . '?hash=x', null, self::dev($tv));
        self::assertSame(200, $s, $raw);
        self::assertFalse(TestEnv::hasPhpError($raw), $raw);
        foreach ($j['data']['commands'] as $c) {
            TestEnv::http('POST', self::$url . 'api/device/ack', ['command_id' => $c['id'], 'status' => 'acked', 'message' => 'ok'], self::dev($tv));
        }
        self::assertArrayHasKey('content', $j['data']);
        return $j['data']['content'];
    }

    private static function activeEmergencies(): array
    {
        return DB::all("SELECT * FROM broadcast_commands WHERE hotel_id = 1 AND is_emergency = 1 AND status = 'active' ORDER BY id");
    }

    private static function emergencyForm(array $extra): array
    {
        return $extra + ['op' => 'emergency', 'title' => 'Fire', 'message' => 'Use the stairs', 'bg_color' => '#B00020', 'text_color' => '#FFFFFF',
            'target_type' => 'all', 'alarm_loop' => '1', 'alarm_repeat' => '3', 'alarm_volume' => '80'];
    }

    // ------------------------------------------------------------------ schema + sounds

    public function testMigrationAddsAlarmColumnsIdempotently(): void
    {
        foreach (['alarm_sound', 'alarm_loop', 'alarm_repeat', 'alarm_volume', 'alarm_muted'] as $col) {
            $this->assertTrue(Migrator::hasColumn(DB::pdo(), 'broadcast_commands', $col), $col);
        }
        $this->assertContains('027_emergency_alarm.sql', Migrator::applied());
        $this->assertNull(DB::one("SHOW COLUMNS FROM broadcast_commands LIKE 'alarm_sound'")['Default']);
        $this->assertSame('80', (string) DB::one("SHOW COLUMNS FROM broadcast_commands LIKE 'alarm_volume'")['Default']);
        DB::delete('schema_migrations', 'filename = :f', ['f' => '027_emergency_alarm.sql']);
        Migrator::migrate();
        $this->assertContains('027_emergency_alarm.sql', Migrator::applied());
    }

    public function testBuiltinAlarmSoundsAreSmallLoopableWavsServedAsAudio(): void
    {
        $refs = array_keys(Sounds::builtins());
        foreach (['emergency_beep', 'emergency_siren', 'fire_alarm', 'notice_chime'] as $key) {
            $this->assertContains('b:' . $key, $refs);
            $file = HC_ROOT . '/assets/sounds/' . $key . '.wav';
            $this->assertFileExists($file);
            $this->assertLessThan(300 * 1024, filesize($file), $key . ' must stay small');
            $wav = (string) file_get_contents($file);
            $fmt = unpack('a4riff/Vsize/a4wave/a4fmt/Vfmtlen/vpcm/vch/Vrate/Vbyterate/vblock/vbits/a4data/Vdatalen', $wav);
            $this->assertSame(['RIFF', 'WAVE', 1, 1, 22050, 16], [$fmt['riff'], $fmt['wave'], $fmt['pcm'], $fmt['ch'], $fmt['rate'], $fmt['bits']], $key);
            $samples = array_values(unpack('s*', substr($wav, 44)));
            $n = count($samples);
            $this->assertGreaterThan(1.5 * 22050, $n, $key . ' length');
            // No click at the loop point: starts at a zero crossing, and the jump from the last sample back to the
            // first is no bigger than an ordinary step of the waveform.
            $this->assertLessThan(200, abs($samples[0]), $key . ' starts at zero');
            $maxStep = 0;
            for ($i = 1; $i < $n; $i++) {
                $maxStep = max($maxStep, abs($samples[$i] - $samples[$i - 1]));
            }
            $this->assertLessThanOrEqual($maxStep, abs($samples[0] - $samples[$n - 1]), $key . ' loop point');
            $peak = max(array_map('abs', $samples));
            $this->assertGreaterThan(0.5 * 32767, $peak, $key . ' normalized');
            $this->assertLessThanOrEqual(0.9 * 32767, $peak, $key . ' headroom');

            [$s, , $body, $head] = TestEnv::http('GET', self::$url . 'assets/sounds/' . $key . '.wav');
            $this->assertSame(200, $s);
            $this->assertMatchesRegularExpression('/^Content-Type:\s*audio\//mi', $head, $key . ' served as audio');
            $this->assertSame(strlen($wav), strlen($body));
        }
        $this->assertStringContainsString('AddType audio/wav .wav', (string) file_get_contents(HC_ROOT . '/.htaccess'));
    }

    // ------------------------------------------------------------------ emergency → TV poll

    public function testEveryAlarmOptionReachesTheTvPoll(): void
    {
        $m = new AdminSession(self::$url, 'alMgr');
        $cases = [
            'beep loop' => [['alarm_sound' => 'b:emergency_beep'], ['#/assets/sounds/emergency_beep\.wav$#', true, 3, 80]],
            'siren 5x' => [['alarm_sound' => 'b:emergency_siren', 'alarm_loop' => '0', 'alarm_repeat' => '5', 'alarm_volume' => '100'], ['#/assets/sounds/emergency_siren\.wav$#', false, 5, 100]],
            'fire alarm' => [['alarm_sound' => 'b:fire_alarm', 'alarm_volume' => '0'], ['#/assets/sounds/fire_alarm\.wav$#', true, 3, 0]],
            'library sound' => [['alarm_sound' => 'u:' . self::$id['h1sound'], 'alarm_loop' => '0', 'alarm_repeat' => '1'], ['#/uploads/h1/sounds/2026/10/gong\.mp3$#', false, 1, 80]],
            'none' => [['alarm_sound' => 'none'], null],
        ];
        foreach ($cases as $label => [$form, $expect]) {
            [$s] = $m->post('broadcast.php', self::emergencyForm($form));
            $this->assertSame(302, $s, $label);
            $this->assertCount(1, self::activeEmergencies(), $label);
            foreach (['tv101', 'tv102'] as $tv) {
                $c = self::content($tv);
                $this->assertSame('emergency', $c['mode'], $label);
                $this->assertSame('Fire', $c['emergency']['title']);
                $this->assertArrayHasKey('alarm', $c['emergency']);
                if ($expect === null) {
                    $this->assertNull($c['emergency']['alarm'], $label);
                    $this->assertFalse($c['emergency']['alarm_muted']);
                    continue;
                }
                $a = $c['emergency']['alarm'];
                $this->assertMatchesRegularExpression('#^https?://#', $a['url'], 'absolute URL');
                $this->assertMatchesRegularExpression($expect[0], $a['url'], $label);
                $this->assertSame([$expect[1], $expect[2], $expect[3]], [$a['loop'], $a['repeat'], $a['volume']], $label);
                $this->assertNotSame('', $a['name']);
            }
            [$s] = $m->post('broadcast.php', ['op' => 'emergency_stop', 'id' => (int) self::activeEmergencies()[0]['id']]);
            $this->assertSame(302, $s);
            $this->assertSame([], self::activeEmergencies(), $label . ' stopped');
        }
        $c = self::content('tv101');
        $this->assertNotSame('emergency', $c['mode']);
        $this->assertNull($c['emergency'], 'stop → no emergency');

        // The admin page shows the alarm options (beep pre-selected) and renders without PHP errors.
        [$s, , $html] = $m->get('broadcast.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertMatchesRegularExpression('#<option value="b:emergency_beep"[^>]*selected>#', $html);
        $this->assertStringContainsString('value="b:emergency_siren"', $html);
        $this->assertStringContainsString('value="u:' . self::$id['h1sound'] . '"', $html);
        $this->assertStringContainsString('name="alarm_loop" value="1" checked', $html);
        $this->assertStringContainsString('name="alarm_volume" min="0" max="100" value="80"', $html);
        $this->assertStringNotContainsString('Silence alarm on all TVs', $html, 'no emergency → no silence button');
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testSilenceKeepsTheMessageDropsTheAlarmAndChangesTheHash(): void
    {
        $m = new AdminSession(self::$url, 'alMgr');
        $m->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'b:emergency_siren']));
        $before = self::content('tv101');
        $this->assertNotNull($before['emergency']['alarm']);
        $same = self::content('tv101');
        $this->assertSame($before['hash'], $same['hash'], 'unchanged emergency → same hash (TV keeps the alarm running)');

        [, , $html] = $m->get('broadcast.php');
        $this->assertStringContainsString('Silence alarm on all TVs', $html);
        $this->assertStringContainsString('data-alarm-state="on"', $html);

        DB::query("DELETE FROM device_commands");
        [$s] = $m->post('broadcast.php', ['op' => 'emergency_silence']);
        $this->assertSame(302, $s);
        $after = self::content('tv101');
        $this->assertSame('emergency', $after['mode'], 'message stays');
        $this->assertSame('Use the stairs', $after['emergency']['message']);
        $this->assertNull($after['emergency']['alarm'], 'silenced → alarm null');
        $this->assertTrue($after['emergency']['alarm_muted']);
        $this->assertNotSame($before['hash'], $after['hash'], 'content hash changes');
        $this->assertSame(2, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'SHOW_CONTENT'"), 'TVs told to refresh');
        $this->assertSame(1, (int) self::activeEmergencies()[0]['alarm_muted']);

        [, , $html] = $m->get('broadcast.php');
        $this->assertStringNotContainsString('Silence alarm on all TVs', $html);
        $this->assertStringContainsString('data-alarm-state="muted"', $html);
        $this->assertSame(0, Broadcaster::emergencySilence(), 'nothing left to silence');

        // AJAX API: start (alarm defaults to the beep) + silence + stop.
        [$s, $j] = $m->ajax('emergency_start', ['title' => 'Gas leak', 'message' => '', 'target_type' => 'rooms', 'target_ids' => [self::$id['r102']]]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $c = self::content('tv102');
        $this->assertSame('Gas leak', $c['emergency']['title']);
        $this->assertStringEndsWith('/assets/sounds/emergency_beep.wav', $c['emergency']['alarm']['url']);
        $this->assertSame([true, 80], [$c['emergency']['alarm']['loop'], $c['emergency']['alarm']['volume']]);
        [$s, $j] = $m->ajax('emergency_silence', ['id' => (int) $j['data']['broadcast_id']]);
        $this->assertSame(200, $s);
        $this->assertSame(1, $j['data']['silenced']);
        $this->assertNull(self::content('tv102')['emergency']['alarm']);
        [$s, $j] = $m->ajax('emergency_stop', []);
        $this->assertSame(200, $s);
        $this->assertSame(2, $j['data']['stopped']);
        $this->assertNull(self::content('tv101')['emergency']);
        $this->assertNull(self::content('tv102')['emergency']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ security

    public function testOnlyTheHotelsOwnSoundLibraryIsAccepted(): void
    {
        $m = new AdminSession(self::$url, 'alMgr');
        // Another hotel's uploaded sound → 404 (Tenant::deny), page and AJAX.
        [$s] = $m->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'u:' . self::$id['h2sound']]));
        $this->assertSame(404, $s);
        [$s] = $m->ajax('emergency_start', ['title' => 'X', 'target_type' => 'all', 'alarm_sound' => 'u:' . self::$id['h2sound']]);
        $this->assertSame(404, $s);
        // Unknown references / URLs → 422 (AJAX) or a flash error (page); nothing starts.
        foreach (['b:nope', 'u:999999', 'https://evil.example/a.mp3', 'http://192.168.1.1/reboot', '../../etc/passwd'] as $bad) {
            [$s, $j] = $m->ajax('emergency_start', ['title' => 'X', 'target_type' => 'all', 'alarm_sound' => $bad]);
            $this->assertSame(422, $s, $bad . ' ' . json_encode($j));
            [$s] = $m->post('broadcast.php', self::emergencyForm(['alarm_sound' => $bad]));
            $this->assertSame(302, $s);
        }
        foreach ([['alarm_volume' => '101'], ['alarm_volume' => '-1'], ['alarm_loop' => '0', 'alarm_repeat' => '11'], ['alarm_loop' => '0', 'alarm_repeat' => '0']] as $bad) {
            [$s] = $m->ajax('emergency_start', ['title' => 'X', 'target_type' => 'all', 'alarm_sound' => 'b:emergency_beep'] + $bad);
            $this->assertSame(422, $s, json_encode($bad));
        }
        $this->assertSame([], self::activeEmergencies(), 'nothing started');

        // In PHP: foreign id → TenantException (404 over HTTP), unknown → InvalidArgumentException.
        try {
            Broadcaster::alarmOptions(['alarm_sound' => 'u:' . self::$id['h2sound']]);
            $this->fail('foreign sound must be denied');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        try {
            Broadcaster::emergencyStart('X', '', 'all', [], null, '#B00020', '#FFFFFF', ['sound' => 'https://evil.example/a.mp3', 'loop' => true]);
            $this->fail('a caller-built alarm array is validated too');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['sound' => 'b:emergency_beep', 'loop' => true, 'repeat' => 3, 'volume' => 80], Broadcaster::alarmOptions([]), 'default for new emergencies');
        $this->assertNull(Broadcaster::alarmOptions(['alarm_sound' => '']));
        $this->assertSame([], self::activeEmergencies());
    }

    public function testCsrfIsRequired(): void
    {
        $m = new AdminSession(self::$url, 'alMgr');
        $m->csrf = 'forged-token';
        [$s] = $m->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'b:emergency_beep']));
        $this->assertSame(419, $s);
        [$s] = $m->ajax('emergency_start', ['title' => 'X', 'target_type' => 'all']);
        $this->assertSame(419, $s);
        $this->assertSame([], self::activeEmergencies());

        $bid = Broadcaster::emergencyStart('Drill', '', 'all', [], null, '#B00020', '#FFFFFF', Broadcaster::alarmOptions([]));
        [$s] = $m->post('broadcast.php', ['op' => 'emergency_silence']);
        $this->assertSame(419, $s);
        [$s] = $m->ajax('emergency_silence', ['id' => $bid]);
        $this->assertSame(419, $s);
        $this->assertSame(0, (int) DB::value('SELECT alarm_muted FROM broadcast_commands WHERE id = :id', ['id' => $bid]), 'not silenced');
    }

    public function testUsersLimitedToSomeTvs(): void
    {
        $desk = new AdminSession(self::$url, 'alDesk');
        // "All rooms" is not allowed for a limited user (403), their own room is.
        [$s] = $desk->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'b:fire_alarm']));
        $this->assertSame(403, $s);
        $this->assertSame([], self::activeEmergencies());
        [$s] = $desk->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'b:fire_alarm', 'target_type' => 'rooms', 'room_ids' => [self::$id['r102']]]));
        $this->assertSame(403, $s, 'another room');
        [$s] = $desk->post('broadcast.php', self::emergencyForm(['alarm_sound' => 'b:fire_alarm', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]));
        $this->assertSame(302, $s);
        $own = (int) self::activeEmergencies()[0]['id'];
        $this->assertStringEndsWith('/fire_alarm.wav', self::content('tv101')['emergency']['alarm']['url']);
        $this->assertNull(self::content('tv102')['emergency'], 'room 102 not targeted');

        // An admin's hotel-wide emergency: the limited user can neither silence it by id (403) …
        $all = Broadcaster::emergencyStart('Evacuate', '', 'all', [], self::$id['alBoss'], '#B00020', '#FFFFFF', Broadcaster::alarmOptions(['alarm_sound' => 'b:emergency_siren']));
        [$s] = $desk->post('broadcast.php', ['op' => 'emergency_silence', 'id' => $all]);
        $this->assertSame(403, $s);
        // … nor with "silence all", which only silences the emergencies of their own TVs.
        [$s] = $desk->post('broadcast.php', ['op' => 'emergency_silence']);
        $this->assertSame(302, $s);
        $rows = array_column(self::activeEmergencies(), 'alarm_muted', 'id');
        $this->assertSame(1, (int) $rows[$own]);
        $this->assertSame(0, (int) $rows[$all]);
        // Room 102 still hears the admin's siren; room 101 shows the newest emergency targeting it (the siren).
        $this->assertStringEndsWith('/emergency_siren.wav', self::content('tv102')['emergency']['alarm']['url']);
        $this->assertSame('Evacuate', self::content('tv101')['emergency']['title']);
        $this->assertNotNull(self::content('tv101')['emergency']['alarm']);

        $boss = new AdminSession(self::$url, 'alBoss');
        [$s] = $boss->post('broadcast.php', ['op' => 'emergency_silence']);
        $this->assertSame(302, $s);
        $this->assertNull(self::content('tv102')['emergency']['alarm']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ SHOW_MESSAGE sound

    public function testShowMessageSoundPayload(): void
    {
        $tv = self::$tv['tv101']['id'];
        DB::query("DELETE FROM device_commands");
        $queued = static fn (): array => array_map(static fn ($r) => json_decode((string) $r['payload'], true),
            DB::all("SELECT payload FROM device_commands WHERE device_id = :d AND command = 'SHOW_MESSAGE' ORDER BY id", ['d' => $tv]));

        TvControls::send('SHOW_MESSAGE', 'rooms', [self::$id['r101']], ['title' => 'Hello', 'message' => 'Tea is ready']);
        TvControls::send('SHOW_MESSAGE', 'rooms', [self::$id['r101']], ['title' => 'Hello', 'sound' => 'b:notice_chime', 'sound_repeat' => '2', 'sound_volume' => '70']);
        TvControls::send('SHOW_MESSAGE', 'rooms', [self::$id['r101']], ['title' => 'Gong', 'sound' => ['ref' => 'u:' . self::$id['h1sound'], 'repeat' => 1, 'volume' => 100]]);
        $q = $queued();
        $this->assertCount(3, $q);
        $this->assertArrayNotHasKey('sound', $q[0], 'default: no sound (old payload unchanged)');
        $this->assertSame(['title' => 'Hello', 'message' => 'Tea is ready', 'duration_sec' => 15], $q[0]);
        $this->assertSame(['repeat' => 2, 'volume' => 70], ['repeat' => $q[1]['sound']['repeat'], 'volume' => $q[1]['sound']['volume']]);
        $this->assertMatchesRegularExpression('#^https?://.+/assets/sounds/notice_chime\.wav$#', $q[1]['sound']['url']);
        $this->assertSame(['url', 'repeat', 'volume'], array_keys($q[1]['sound']));
        $this->assertSame((string) Sounds::resolve('u:' . self::$id['h1sound'])['url'], $q[2]['sound']['url']);

        foreach ([['sound' => 'https://evil.example/x.mp3'], ['sound' => 'b:nope'], ['sound' => 'b:notice_chime', 'sound_repeat' => '6'], ['sound' => 'b:notice_chime', 'sound_volume' => '101']] as $bad) {
            try {
                TvControls::send('SHOW_MESSAGE', 'rooms', [self::$id['r101']], ['title' => 'X'] + $bad);
                $this->fail('must refuse ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            TvControls::send('SHOW_MESSAGE', 'rooms', [self::$id['r101']], ['title' => 'X', 'sound' => 'u:' . self::$id['h2sound']]);
            $this->fail('foreign sound');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertCount(3, $queued(), 'nothing else queued');

        // TV controls page: the form offers the chime; a crafted foreign id → 404.
        $m = new AdminSession(self::$url, 'alMgr');
        [$s, , $html] = $m->get('tv_controls.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('<select class="form-select" id="msound" name="sound">', $html);
        $this->assertStringContainsString('<option value="b:notice_chime">', $html);
        [$s] = $m->post('tv_controls.php', ['op' => 'command', 'command' => 'SHOW_MESSAGE', 'title' => 'Hi', 'sound' => 'b:notice_chime', 'sound_repeat' => '1', 'sound_volume' => '80', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(302, $s);
        [$s] = $m->post('tv_controls.php', ['op' => 'command', 'command' => 'SHOW_MESSAGE', 'title' => 'Hi', 'sound' => 'u:' . self::$id['h2sound'], 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(404, $s);
        $q = $queued();
        $this->assertCount(4, $q);
        $this->assertSame(1, $q[3]['sound']['repeat']);

        // The TV receives it in the command poll.
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['tv101']['uid'] . '?hash=x', null, self::dev('tv101'));
        $msgs = array_values(array_filter($j['data']['commands'], static fn ($c) => $c['command'] === 'SHOW_MESSAGE' && isset($c['payload']['sound'])));
        $this->assertCount(3, $msgs);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ compatibility

    public function testOldEmergencyRowsWithoutAlarmWork(): void
    {
        // A row as written before 2.4.1 (no alarm fields → column defaults, alarm_sound NULL).
        $bid = DB::insert('broadcast_commands', [
            'title' => 'Old', 'command' => 'EMERGENCY', 'target_type' => 'all', 'target_ids' => '[]',
            'payload' => json_out(['title' => 'Old', 'message' => 'Legacy', 'bg_color' => '#B00020', 'text_color' => '#FFFFFF']),
            'is_emergency' => 1, 'mode' => 'now', 'status' => 'active', 'start_at' => now(), 'created_at' => now(),
        ]);
        Settings::bumpContentVersion();
        $c = self::content('tv101');
        $this->assertSame('emergency', $c['mode']);
        $this->assertSame(['id' => $bid, 'title' => 'Old', 'message' => 'Legacy', 'bg_color' => '#B00020', 'text_color' => '#FFFFFF', 'alarm' => null, 'alarm_muted' => false], $c['emergency']);
        $this->assertSame('announcement', $c['items'][0]['type'], 'old apps still get the announcement item');
        // A row array without the alarm keys at all (old code paths / before migration 027).
        $this->assertNull(Broadcaster::alarmFor(['id' => 1, 'title' => 'x']));
        $this->assertFalse(Broadcaster::alarmSounding([['target_type' => 'all', 'target_ids' => '[]']]));
        // Silence on an emergency without alarm changes nothing.
        $this->assertSame(0, Broadcaster::emergencySilence($bid));
        $this->assertSame(1, Broadcaster::emergencyStop($bid));

        // An uploaded alarm sound that is deleted later: the emergency keeps sounding with the built-in beep;
        // while the emergency is active the sound cannot be deleted from the library.
        $tmp = DB::insert('sounds', ['name' => 'Temp', 'file_path' => 'h1/sounds/2026/10/tmp.mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 10, 'created_at' => now()]);
        $bid = Broadcaster::emergencyStart('Up', '', 'all', [], null, '#B00020', '#FFFFFF', Broadcaster::alarmOptions(['alarm_sound' => 'u:' . $tmp]));
        $this->assertSame(1, Sounds::usage($tmp));
        $this->assertFalse(Sounds::delete($tmp), 'in use by an active emergency');
        DB::delete('sounds', 'id = :id', ['id' => $tmp]);
        Settings::bumpContentVersion();
        $this->assertStringEndsWith('/emergency_beep.wav', self::content('tv101')['emergency']['alarm']['url']);
        Broadcaster::emergencyStop($bid);
    }

    public function testNoticeBoardChimeOption(): void
    {
        $app = DisplayApps::find('notice_board');
        $this->assertInstanceOf(NoticeBoardApp::class, $app);
        $this->assertFalse($app->defaults()['chime'], 'off by default');
        [$cfg] = $app->validate([]);
        $this->assertFalse($cfg['chime']);
        $this->assertNull($app->data($cfg, ['preview' => false])['chime_url']);
        [$cfg] = $app->validate(['chime' => '1']);
        $this->assertTrue($cfg['chime']);
        $this->assertMatchesRegularExpression('#^https?://.+/assets/sounds/notice_chime\.wav$#', (string) $app->data($cfg, ['preview' => false])['chime_url']);
        $this->assertNull($app->data($cfg, ['preview' => true])['chime_url'], 'never in the admin preview');
        $this->assertStringContainsString('name="cfg[chime]"', $app->form($cfg));
        $js = (string) file_get_contents(HC_ROOT . '/assets/display/apps/notice_board.js');
        $this->assertStringContainsString('chime_url', $js);
        $this->assertStringContainsString("createElement('audio')", $js);
    }

    public function testWebPlayerAndSimulatorKnowTheAlarm(): void
    {
        $js = (string) file_get_contents(HC_ROOT . '/assets/player/player.js');
        foreach (['function syncAlarm', 'function stopAlarm', 'Tap or press OK to enable the alarm sound', 'p.sound'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertContains('Tap or press OK to enable the alarm sound', WebPlayer::strings());
        $sim = (string) file_get_contents(HC_ROOT . '/admin/partials/tv_simulator.php');
        $this->assertStringContainsString('alarmBadge', $sim);

        // Room preview of an emergency with an alarm renders the indicator data.
        Broadcaster::emergencyStart('Drill', '', 'all', [], null, '#B00020', '#FFFFFF', Broadcaster::alarmOptions([]));
        $m = new AdminSession(self::$url, 'alMgr');
        [$s, , $html] = $m->get('preview.php?room_id=' . self::$id['r101']);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('emergency_beep.wav', $html);
        $this->assertStringContainsString('id="alarmBadge"', $html);
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = array_values(Sounds::BUILTIN);
        foreach (['admin/broadcast.php', 'admin/tv_controls.php', 'core/Broadcaster.php', 'core/TvControls.php', 'core/Sounds.php', 'core/Apps/NoticeBoardApp.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        // Only the strings this release added must be covered in both languages.
        $new = array_keys(require HC_ROOT . '/lang/gu_alarm.php');
        $missing = [];
        foreach (array_unique($keys) as $k) {
            if (!in_array($k, $new, true) && !in_array($k, array_values(Sounds::BUILTIN), true)) {
                continue;
            }
            foreach (['gu' => $gu, 'hi' => $hi] as $l => $t) {
                if (!isset($t[$k]) || $t[$k] === '') {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        $this->assertSame(array_keys(require HC_ROOT . '/lang/gu_alarm.php'), array_values(array_intersect(array_keys(require HC_ROOT . '/lang/gu_alarm.php'), array_keys($hi))), 'every Gujarati string also in Hindi');
        foreach (['Silence alarm on all TVs', 'Alarm sound', 'Repeat until the emergency is stopped', 'Alarm volume', 'Sound when the message appears', 'Play a chime when a new notice appears'] as $k) {
            $this->assertContains($k, $keys, $k . ' is used');
        }
        I18n::setLang('gu');
        $this->assertSame('બધા ટીવી પર એલાર્મ બંધ કરો', __('Silence alarm on all TVs'));
        I18n::setLang('en');
    }
}
