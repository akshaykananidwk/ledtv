<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Device schedules (docs/modules/device_schedules.md): timed volume / input / restart / bell / spoken
 * announcement actions fired exactly once per occurrence (fixed clock, time zones, weekdays, one-off),
 * targets + Access + emergency, staggered restarts (offline / uptime skips), bell timetable bulk entry,
 * sound uploads (audio validation), SPEAK / PLAY_SOUND payloads, the presence webhook (auth, rate limits,
 * screen on, idle off, power-schedule precedence) and the proof of play report (numbers, CSV, print view,
 * tenancy, limited users), plus every page for every role without PHP warnings.
 */
final class DeviceSchedulesTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Krishna Palace');
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'dsMgr', 'staff' => 'dsStaff', 'reception' => 'dsRecep', 'super_admin' => 'dsBoss'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['dsLim' => 'manager', 'dsLimStaff' => 'staff'] as $u => $role) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101' => '1', '102' => '1', '201' => '2'] as $n => $floor) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => $floor]);
            self::$id['d' . $n] = self::device(self::$id['r' . $n], 'ds-tv-' . $n . '-0001');
        }
        self::$id['g1'] = DB::insert('room_groups', ['name' => 'First floor', 'type' => 'custom']);
        foreach (['r101', 'r102'] as $r) {
            DB::query('INSERT INTO room_group_members (group_id, room_id) VALUES (:g, :r)', ['g' => self::$id['g1'], 'r' => self::$id[$r]]);
        }
        foreach (['dsLim', 'dsLimStaff'] as $u) {
            DB::insert('user_access', ['user_id' => self::$id[$u], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        }
        self::$id['h2'] = Hotels::create(['name' => 'Hotel Two'], ['username' => 'dsBoss2', 'email' => 'ds2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(self::$id['h2'], function (): void {
            self::$id['h2room'] = DB::insert('rooms', ['room_number' => '901', 'name' => 'H2 room', 'floor' => '9']);
            self::$id['h2dev'] = self::device(self::$id['h2room'], 'ds-h2-tv-0001');
            self::$id['h2sched'] = DB::insert('device_schedules', ['title' => 'H2-SCHEDULE-SECRET', 'action' => 'mute', 'options' => '{}', 'run_time' => '10:00:00', 'repeat_mode' => 'daily', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
            self::$id['h2sound'] = DB::insert('sounds', ['name' => 'H2-SOUND-SECRET', 'file_path' => 'h2/sounds/x.wav', 'mime' => 'audio/wav', 'size_bytes' => 10, 'created_at' => now()]);
            self::$id['h2sensor'] = DB::insert('presence_sensors', ['name' => 'H2-SENSOR-SECRET', 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['h2room']]), 'created_at' => now()]);
            self::$id['h2content'] = DB::insert('content_items', ['title' => 'H2-CONTENT-SECRET', 'type' => 'image', 'created_at' => now()]);
        });
        Tenant::set(1);
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        require_once HC_ROOT . '/admin/partials/common.php';
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        Access::$userOverride = null;
        Access::forget();
        DB::query('DELETE FROM rate_limits');
        DB::query('DELETE FROM device_commands');
        DB::query("DELETE FROM broadcast_commands WHERE is_emergency = 1 OR command = 'SCREEN_OFF'");
        DB::query('DELETE FROM device_schedules WHERE hotel_id = 1');
        DB::query('UPDATE rooms SET is_enabled = 1');
        DB::query("UPDATE devices SET status = 'online', last_ping = :n, is_revoked = 0", ['n' => now()]);
        Settings::bumpContentVersion();
        Cache::clear();
    }

    private static function device(int $roomId, string $uid): int
    {
        return DB::insert('devices', ['device_uid' => $uid, 'room_id' => $roomId, 'token_hash' => hash('sha256', $uid), 'status' => 'online', 'last_ping' => now(), 'registered_at' => now(), 'model' => 'TV ' . $uid]);
    }

    private static function ts(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get())))->getTimestamp();
    }

    /** Insert a schedule row directly (fixed clock tests: active since 2020). */
    private static function schedule(array $row): int
    {
        return DB::insert('device_schedules', $row + [
            'title' => 'T', 'action' => 'volume', 'options' => '{"level":10}', 'run_time' => '22:00:00', 'repeat_mode' => 'daily',
            'target_type' => 'all', 'target_ids' => '[]', 'is_active' => 1, 'active_from' => '2020-01-01 00:00:00', 'created_at' => now(),
        ]);
    }

    /** device_commands of hotel 1: [[device_id, command, payload array], …] (oldest first). */
    private static function commands(?string $command = null): array
    {
        $rows = DB::all(
            'SELECT dc.device_id, dc.command, dc.payload FROM device_commands dc JOIN devices d ON d.id = dc.device_id WHERE d.hotel_id = 1' . ($command ? ' AND dc.command = :c' : '') . ' ORDER BY dc.id',
            $command ? ['c' => $command] : []
        );
        return array_map(fn ($r) => [(int) $r['device_id'], $r['command'], json_decode((string) $r['payload'], true)], $rows);
    }

    private function assertNoPhpErrors(string $html, string $where): void
    {
        $err = preg_match('#(<b>)?(Warning|Notice|Deprecated|Fatal error|Parse error)(</b>)?:\s.{0,300}#s', $html, $m) ? $m[0] : '';
        $this->assertFalse(TestEnv::hasPhpError($html), $where . ': ' . $err);
    }

    // ------------------------------------------------------------------ payloads + whitelist

    public function testNewCommandsAreWhitelistedWithContractPayloads(): void
    {
        $this->assertContains('SPEAK', Broadcaster::DEVICE_COMMANDS);
        $this->assertContains('PLAY_SOUND', Broadcaster::DEVICE_COMMANDS);
        $this->assertSame('Spoken announcement', command_label('SPEAK'));
        $this->assertSame('Play sound', command_label('PLAY_SOUND'));

        $p = DeviceSchedules::speakPayload(['text' => " Aarti will   start <b>in</b> 10 minutes ", 'lang' => 'gu', 'rate' => '1.5', 'repeat' => '2', 'volume' => '60', 'chime_before' => '1']);
        $this->assertSame(['text' => 'Aarti will start in 10 minutes', 'lang' => 'gu', 'rate' => 1.5, 'repeat' => 2, 'volume' => 60, 'chime_before' => true], $p);
        $p = DeviceSchedules::speakPayload(['text' => 'Hi']);
        $this->assertSame(['text' => 'Hi', 'lang' => 'auto', 'rate' => 1.0, 'repeat' => 1, 'chime_before' => false], $p, 'volume optional (omitted)');
        foreach ([
            [['text' => ''], 'Enter the text to announce.'],
            [['text' => str_repeat('a', 501)], 'at most 500'],
            [['text' => 'x', 'lang' => 'fr'], 'language'],
            [['text' => 'x', 'rate' => '2.5'], 'Speed'],
            [['text' => 'x', 'rate' => '0.4'], 'Speed'],
            [['text' => 'x', 'repeat' => '4'], 'Repeat'],
            [['text' => 'x', 'volume' => '101'], 'Volume'],
        ] as [$in, $err]) {
            try {
                DeviceSchedules::speakPayload($in);
                $this->fail('accepted ' . json_encode($in));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($err, $e->getMessage());
            }
        }
        $this->assertSame(['level' => 0], DeviceSchedules::options('volume', ['level' => '0']));
        $this->assertSame(['input' => 'hdmi2'], DeviceSchedules::options('input', ['input' => 'hdmi2']));
        $this->assertSame(['min_uptime_h' => 0, 'stagger_min' => 10], DeviceSchedules::options('reboot', []));
        $this->assertSame(['sound' => 'b:temple_bell', 'repeat' => 3, 'volume' => 80], DeviceSchedules::options('bell', ['sound' => 'b:temple_bell', 'sound_repeat' => '3', 'sound_volume' => '80']));
        foreach ([['volume', ['level' => '101']], ['volume', ['level' => 'x']], ['input', ['input' => 'hdmi9']], ['reboot', ['stagger_min' => '11']], ['bell', ['sound' => 'b:nope']], ['bell', ['sound' => 'b:school_bell', 'sound_repeat' => '11']], ['nope', []]] as [$a, $in]) {
            try {
                DeviceSchedules::options($a, $in);
                $this->fail("accepted $a " . json_encode($in));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Another hotel's sound can never be referenced (404 like every foreign id).
        $this->expectException(TenantException::class);
        DeviceSchedules::options('bell', ['sound' => 'u:' . self::$id['h2sound']]);
    }

    public function testSpeakAndPlaySoundAreQueuedWithTheExactPayload(): void
    {
        [$bid, $n] = DeviceSchedules::announce(['text' => 'Aarti will start in 10 minutes', 'lang' => 'hi', 'rate' => '0.9', 'repeat' => '1', 'chime_before' => '1', 'target_type' => 'groups', 'group_ids' => [self::$id['g1']]]);
        $this->assertSame(2, $n);
        $cmds = self::commands('SPEAK');
        $this->assertSame([self::$id['d101'], self::$id['d102']], array_column($cmds, 0));
        $this->assertSame(['text' => 'Aarti will start in 10 minutes', 'lang' => 'hi', 'rate' => 0.9, 'repeat' => 1, 'chime_before' => true], $cmds[0][2]);
        $this->assertSame('SPEAK', DB::value('SELECT command FROM broadcast_commands WHERE id = :id', ['id' => $bid]));
        // TV gets it through the normal poll.
        $p = DeviceManager::pendingCommands(self::$id['d101']);
        $this->assertSame('SPEAK', $p[0]['command']);
        $this->assertSame('Aarti will start in 10 minutes', $p[0]['payload']['text']);

        // Bell schedule → PLAY_SOUND with the built-in sound URL.
        $sid = self::schedule(['action' => 'bell', 'options' => json_out(['sound' => 'b:school_bell', 'repeat' => 2, 'volume' => 70]), 'run_time' => '08:00:00', 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r201']])]);
        $r = DeviceSchedules::tick(self::ts('2026-10-07 08:00:20'));
        $this->assertSame(1, $r['fired']);
        $ps = self::commands('PLAY_SOUND');
        $this->assertCount(1, $ps);
        $this->assertSame(self::$id['d201'], $ps[0][0]);
        $this->assertSame(['url', 'volume', 'repeat'], array_keys($ps[0][2]));
        $this->assertMatchesRegularExpression('#^https?://[^/]+/.*assets/sounds/school_bell\.wav$#', $ps[0][2]['url']);
        $this->assertSame([70, 2], [$ps[0][2]['volume'], $ps[0][2]['repeat']]);
        [$code, , , $head] = TestEnv::http('GET', self::$url . 'assets/sounds/school_bell.wav');
        $this->assertSame(200, $code, 'built-in sound is served');
        $this->assertMatchesRegularExpression('#Content-Type: audio/(x-)?wav#i', $head);
        // Scheduled SPEAK keeps the contract too.
        self::schedule(['action' => 'speak', 'options' => json_out(['text' => 'School closes now', 'lang' => 'en', 'rate' => 1.0, 'repeat' => 3, 'chime_before' => false]), 'run_time' => '14:00:00', 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r102']])]);
        DeviceSchedules::tick(self::ts('2026-10-07 14:00:05'));
        $sp = self::commands('SPEAK');
        $this->assertSame(['text' => 'School closes now', 'lang' => 'en', 'rate' => 1, 'repeat' => 3, 'chime_before' => false], end($sp)[2]);
        $this->assertSame(self::$id['d102'], end($sp)[0]);
        DB::delete('device_schedules', 'id = :id', ['id' => $sid]);
    }

    // ------------------------------------------------------------------ firing

    public function testFiresExactlyOncePerOccurrenceWithDaysOneOffAndCatchUp(): void
    {
        $daily = self::schedule(['title' => 'Night volume', 'options' => '{"level":10}', 'run_time' => '22:00:00']);
        $morning = self::schedule(['title' => 'Morning volume', 'options' => '{"level":40}', 'run_time' => '07:00:00']);
        $vol = fn (): array => array_map(fn ($c) => $c[2]['level'], self::commands('SET_VOLUME'));

        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-07 21:59:30'))['fired']);
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-07 22:00:10'))['fired']);
        $this->assertSame([10, 10, 10], $vol(), 'all three TVs');
        foreach (['22:00:40', '22:01:00', '22:05:00', '22:09:59', '23:30:00'] as $t) {
            $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-07 ' . $t))['fired'], $t);
        }
        // A competing tick that read the row before last_fired_for was written is stopped by the unique key.
        DB::update('device_schedules', ['last_fired_for' => null], 'id = :id', ['id' => $daily]);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-07 22:00:30'))['fired']);
        $this->assertCount(3, $vol());
        DeviceSchedules::tick(self::ts('2026-10-08 07:00:00'));
        $this->assertSame([10, 10, 10, 40, 40, 40], $vol());
        DeviceSchedules::tick(self::ts('2026-10-08 22:00:59'));
        $this->assertCount(9, $vol(), 'next day again');
        $s = DeviceSchedules::find($daily);
        $this->assertSame('2026-10-08 22:00:00', $s['last_fired_for']);
        $this->assertStringContainsString('Sent to 3 TV(s).', (string) $s['last_result']);
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM device_schedule_runs WHERE schedule_id IN (:a, :b)', ['a' => $daily, 'b' => $morning]));
        // Late tick (cron missed): within 10 minutes it still fires once, after that the occurrence is skipped.
        DB::query('DELETE FROM device_commands');
        $late = self::schedule(['run_time' => '12:00:00']);
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-09 12:09:30'))['fired']);
        $missed = self::schedule(['run_time' => '13:00:00']);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-09 13:10:01'))['fired']);
        $this->assertNull(DeviceSchedules::find($missed)['last_fired_for']);
        // Across midnight: a 23:58 occurrence is caught up at 00:03.
        $mid = self::schedule(['run_time' => '23:58:00']);
        DeviceSchedules::tick(self::ts('2026-10-10 00:03:00'));
        $this->assertSame('2026-10-09 23:58:00', DeviceSchedules::find($mid)['last_fired_for']);
        DB::query('DELETE FROM device_schedules WHERE id IN (' . implode(',', [$daily, $morning, $late, $missed, $mid]) . ')');

        // Weekdays (1 = Monday): 2026-10-12 is a Monday, 2026-10-13 a Tuesday.
        $mon = self::schedule(['action' => 'mute', 'options' => '{}', 'repeat_mode' => 'weekly', 'days' => '1,3', 'run_time' => '09:00:00']);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-13 09:00:00'))['fired'], 'Tuesday');
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-12 09:00:00'))['fired'], 'Monday');
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-14 09:00:00'))['fired'], 'Wednesday');
        $this->assertCount(6, self::commands('MUTE'));
        // One-off date.
        $once = self::schedule(['action' => 'unmute', 'options' => '{}', 'repeat_mode' => 'once', 'run_date' => '2026-10-20', 'run_time' => '18:30:00']);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-19 18:30:00'))['fired']);
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-20 18:30:00'))['fired']);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-21 18:30:00'))['fired']);
        $this->assertNull(DeviceSchedules::nextRun(DeviceSchedules::find($once), self::ts('2026-10-20 19:00:00')));
        $this->assertSame(self::ts('2026-10-19 09:00:00'), DeviceSchedules::nextRun(DeviceSchedules::find($mon), self::ts('2026-10-14 09:00:01')));
        // active_from: a schedule created / resumed after the time does not fire for that occurrence.
        $fresh = self::schedule(['action' => 'mute', 'options' => '{}', 'run_time' => '10:00:00', 'active_from' => '2026-10-15 10:01:00']);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-15 10:05:00'))['fired']);
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-16 10:00:00'))['fired']);
        // Paused schedules never fire.
        DeviceSchedules::setActive($fresh, false);
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-17 10:00:00'))['fired']);
    }

    public function testHotelTimeZoneTargetsEmergencyAndTask(): void
    {
        $h2 = self::$id['h2'];
        Tenant::run($h2, function (): void {
            Settings::set('timezone', 'America/New_York');
        });
        Tenant::forget();
        Settings::flush();
        Tenant::set(1);
        // 10:00 in New York = 19:30 in India (EDT, UTC-4).
        $ny = (new DateTimeImmutable('2026-10-07 10:00:05', new DateTimeZone('America/New_York')))->getTimestamp();
        $ist = (new DateTimeImmutable('2026-10-07 10:00:05', new DateTimeZone('Asia/Kolkata')))->getTimestamp();
        DB::query('UPDATE device_schedules SET active_from = :a WHERE id = :id', ['a' => '2020-01-01 00:00:00', 'id' => self::$id['h2sched']]);
        $this->assertSame(0, Tenant::run($h2, fn () => DeviceSchedules::tick($ist))['fired'], '10:00 India is not 10:00 New York');
        $this->assertSame(1, Tenant::run($h2, fn () => DeviceSchedules::tick($ny))['fired']);
        $this->assertSame('2026-10-07 10:00:00', DB::value('SELECT last_fired_for FROM device_schedules WHERE id = :id', ['id' => self::$id['h2sched']]));
        $this->assertSame('MUTE', DB::value('SELECT command FROM device_commands WHERE device_id = :d', ['d' => self::$id['h2dev']]));
        $this->assertSame([], self::commands(), "hotel 1's TVs untouched");
        $this->assertSame('Asia/Kolkata', date_default_timezone_get());

        // Targets: group / rooms; emergency rooms are skipped.
        $g = self::schedule(['action' => 'mute', 'options' => '{}', 'run_time' => '11:00:00', 'target_type' => 'groups', 'target_ids' => json_out([self::$id['g1']])]);
        Broadcaster::emergencyStart('Fire', 'Leave now', 'rooms', [self::$id['r102']]);
        DB::query('DELETE FROM device_commands');
        DeviceSchedules::tick(self::ts('2026-10-07 11:00:00'));
        $this->assertSame([self::$id['d101']], array_column(self::commands('MUTE'), 0), 'room 102 has an emergency, 201 is not in the group');
        $this->assertStringContainsString('1 room(s) with an emergency skipped', (string) DeviceSchedules::find($g)['last_result']);
        Broadcaster::emergencyStop();
        // Deleted sound: nothing sent, result explains.
        $bell = self::schedule(['action' => 'bell', 'options' => json_out(['sound' => 'u:999999', 'repeat' => 1]), 'run_time' => '11:30:00']);
        DeviceSchedules::tick(self::ts('2026-10-07 11:30:00'));
        $this->assertSame([], self::commands('PLAY_SOUND'));
        $this->assertSame('Not sent: the sound was deleted.', DeviceSchedules::find($bell)['last_result']);

        // The scheduler task runs every active hotel in its own time zone; a suspended hotel is skipped.
        $now = time();
        DB::query('DELETE FROM device_commands');
        // The fixed-time schedules above (11:00, 11:30 …) would fire again if the real clock happens to be
        // within their 10-minute catch-up window: only the schedule created here may be due.
        DB::query('UPDATE device_schedules SET is_active = 0 WHERE hotel_id = 1');
        $t = self::schedule(['action' => 'unmute', 'options' => '{}', 'run_time' => date('H:i:00', $now)]);
        DB::update('hotels', ['status' => 'suspended'], 'id = :id', ['id' => $h2]);
        Tenant::forget();
        Tenant::run($h2, fn () => DB::update('device_schedules', ['run_time' => date('H:i:00'), 'last_fired_for' => null], 'id = :id', ['id' => self::$id['h2sched']]));
        $out = (new DeviceSchedulesTask())->run();
        $this->assertSame(1, $out['fired']);
        $this->assertCount(3, self::commands('UNMUTE'));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d", ['d' => self::$id['h2dev']]), 'suspended hotel');
        $this->assertSame(0, (new DeviceSchedulesTask())->run()['fired'], 'second run: nothing new');
        $this->assertSame(1, Tenant::current());
        DB::update('hotels', ['status' => 'active'], 'id = :id', ['id' => $h2]);
        Tenant::run($h2, fn () => Settings::set('timezone', 'Asia/Kolkata'));
        Tenant::forget();
        Tenant::set(1);
        DB::delete('device_schedules', 'id IN (' . implode(',', [$g, $bell, $t]) . ')');
    }

    public function testRebootIsStaggeredPerTvAndSkipsOfflineAndShortUptime(): void
    {
        $now = time();
        $occ = self::ts(date('Y-m-d H:i:00', $now));
        DB::update('devices', ['uptime_sec' => 48 * 3600, 'last_heartbeat' => now()], 'id = :id', ['id' => self::$id['d101']]);
        DB::update('devices', ['uptime_sec' => 3600, 'last_heartbeat' => now()], 'id = :id', ['id' => self::$id['d102']]);
        DB::update('devices', ['status' => 'offline', 'last_ping' => date('Y-m-d H:i:s', $now - 3600)], 'id = :id', ['id' => self::$id['d201']]);
        $sid = self::schedule(['title' => 'Nightly restart', 'action' => 'reboot', 'options' => json_out(['min_uptime_h' => 24, 'stagger_min' => 10]), 'run_time' => date('H:i:00', $occ)]);
        $r = DeviceSchedules::tick($occ);
        $this->assertSame(1, $r['fired']);
        $runs = DB::all('SELECT device_id, due_at, status FROM device_schedule_runs WHERE schedule_id = :s AND device_id > 0 ORDER BY device_id', ['s' => $sid]);
        $this->assertSame([self::$id['d101'], self::$id['d102'], self::$id['d201']], array_map('intval', array_column($runs, 'device_id')));
        foreach ($runs as $run) {
            $off = (int) strtotime($run['due_at']) - $occ;
            $this->assertGreaterThanOrEqual(0, $off);
            $this->assertLessThanOrEqual(600, $off, 'random offset 0–10 minutes');
        }
        $this->assertStringContainsString('Restart of 3 TV(s) planned over 10 minutes.', (string) DeviceSchedules::find($sid)['last_result']);
        // Each TV gets its REBOOT at its own time; at the end of the spread all are due.
        $sentBefore = count(self::commands('REBOOT'));
        $this->assertLessThanOrEqual(1, $sentBefore);
        $r = DeviceSchedules::tick($occ + 601);
        $this->assertSame([self::$id['d101']], array_column(self::commands('REBOOT'), 0), 'only the TV up for 48 h restarts');
        $notes = DB::all('SELECT device_id, status, note FROM device_schedule_runs WHERE schedule_id = :s AND device_id > 0 ORDER BY device_id', ['s' => $sid]);
        $this->assertSame([['sent', null], ['skipped', 'uptime'], ['skipped', 'offline']], array_map(fn ($n) => [$n['status'], $n['note']], $notes));
        $this->assertSame(0, DeviceSchedules::tick($occ + 700)['restarts'], 'sent once');
        $this->assertCount(1, self::commands('REBOOT'));
        $bid = (int) DB::value("SELECT broadcast_id FROM device_schedule_runs WHERE schedule_id = :s AND device_id = :d", ['s' => $sid, 'd' => self::$id['d101']]);
        $this->assertSame('REBOOT', DB::value('SELECT command FROM broadcast_commands WHERE id = :id', ['id' => $bid]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_logs WHERE broadcast_id = :b AND event = 'queued'", ['b' => $bid]));

        // Offsets differ per TV: with 12 TVs and a 10 minute spread they are not all the same moment.
        $extra = [];
        for ($i = 0; $i < 9; $i++) {
            $extra[] = self::device(self::$id['r201'], 'ds-stagger-' . $i . '-xx');
        }
        $s2 = self::schedule(['action' => 'reboot', 'options' => json_out(['min_uptime_h' => 0, 'stagger_min' => 10]), 'run_time' => date('H:i:00', $occ)]);
        DeviceSchedules::tick($occ + 5);
        $dues = array_unique(DB::column('SELECT due_at FROM device_schedule_runs WHERE schedule_id = :s AND device_id > 0', ['s' => $s2]));
        $this->assertGreaterThan(1, count($dues));
        // No spread: everything immediately (online TVs only).
        DB::query('DELETE FROM device_commands');
        DB::delete('devices', 'id IN (' . implode(',', $extra) . ')');
        $s3 = self::schedule(['action' => 'reboot', 'options' => json_out(['min_uptime_h' => 0, 'stagger_min' => 0]), 'run_time' => date('H:i:00', $occ), 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r101'], self::$id['r102'], self::$id['r201']])]);
        DeviceSchedules::tick($occ + 10);
        $this->assertSame([self::$id['d101'], self::$id['d102']], array_column(self::commands('REBOOT'), 0));
        DB::delete('device_schedules', 'id IN (' . implode(',', [$sid, $s2, $s3]) . ')');
    }

    // ------------------------------------------------------------------ admin page: schedules, bells, access

    public function testSchedulePageCrudBulkBellsTenancyAndAccess(): void
    {
        $this->assertSame([['08:00:00', '08:45:00', '09:30:00', '13:15:00'], []], DeviceSchedules::parseTimes("08:00, 8:45; 09.30\n13:15 08:00"));
        $this->assertSame([['07:05:00'], ['25:00', 'abc', '7:5']], DeviceSchedules::parseTimes('25:00 abc 07:05 7:5'));

        $s = new AdminSession(self::$url, 'dsMgr');
        [$code, , $html] = $s->get('device_schedules.php');
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'schedules page');
        // Validation → 422 with messages, nothing saved.
        [$code, , $html] = $s->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'action' => 'volume', 'level' => '150', 'run_time' => '25:00', 'repeat_mode' => 'weekly', 'target_type' => 'rooms']);
        $this->assertSame(422, $code);
        foreach (['Volume must be between 0 and 100.', 'Enter the time as HH:MM.', 'Choose at least one day.', 'Select at least one target.'] as $err) {
            $this->assertStringContainsString(e($err), $html);
        }
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM device_schedules WHERE hotel_id = 1'));
        [$code] = TestEnv::http('POST', self::$url . 'admin/device_schedules.php', null, [], $s->jar, ['op' => 'save', 'action' => 'mute', 'run_time' => '10:00']);
        $this->assertSame(419, $code, 'CSRF');
        // Create (XSS in the name), edit, pause, delete.
        [$code] = $s->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'title' => self::XSS, 'action' => 'volume', 'level' => '10', 'run_time' => '22:00', 'repeat_mode' => 'daily', 'target_type' => 'all']);
        $this->assertSame(302, $code);
        $row = DB::one('SELECT * FROM device_schedules WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['volume', '22:00:00', 'daily', '{"level":10}', (int) self::$id['dsMgr']], [$row['action'], $row['run_time'], $row['repeat_mode'], $row['options'], (int) $row['created_by']]);
        [, , $html] = $s->get('device_schedules.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Volume 10 %', $html);
        [, , $html] = $s->get('device_schedules.php?edit=' . $row['id']);
        $this->assertStringContainsString('Edit schedule', $html);
        [$code] = $s->post('device_schedules.php', ['op' => 'save', 'id' => $row['id'], 'title' => 'Speak', 'action' => 'speak', 'text' => 'Aarti will start in 10 minutes', 'lang' => 'gu', 'rate' => '1', 'repeat' => '1',
            'run_time' => '18:50', 'repeat_mode' => 'once', 'run_date' => date('Y-m-d', strtotime('+1 day')), 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(302, $code);
        $row = DeviceSchedules::find((int) $row['id']);
        $this->assertSame(['speak', 'once', date('Y-m-d', strtotime('+1 day')), 'rooms', '[' . self::$id['r101'] . ']'], [$row['action'], $row['repeat_mode'], $row['run_date'], $row['target_type'], $row['target_ids']]);
        $this->assertSame('Aarti will start in 10 minutes', DeviceSchedules::opts($row)['text']);
        $s->post('device_schedules.php', ['op' => 'toggle', 'id' => $row['id']]);
        $this->assertSame(0, (int) DeviceSchedules::find((int) $row['id'])['is_active']);
        // Another hotel's schedule → 404 (edit, toggle, delete, save).
        foreach ([['toggle'], ['delete'], ['save']] as [$op]) {
            [$code] = $s->post('device_schedules.php', ['op' => $op, 'id' => self::$id['h2sched'], 'action' => 'mute', 'run_time' => '10:00', 'target_type' => 'all']);
            $this->assertSame(404, $code, $op);
        }
        [$code] = $s->get('device_schedules.php?edit=' . self::$id['h2sched']);
        $this->assertSame(404, $code);
        [$code] = $s->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'action' => 'mute', 'run_time' => '10:00', 'target_type' => 'rooms', 'room_ids' => [self::$id['h2room']]]);
        $this->assertSame(404, $code, "another hotel's room");
        $this->assertSame('H2-SCHEDULE-SECRET', DB::value('SELECT title FROM device_schedules WHERE id = :id', ['id' => self::$id['h2sched']]));
        [, , $html] = $s->get('device_schedules.php');
        $this->assertStringNotContainsString('H2-SCHEDULE-SECRET', $html);
        $s->post('device_schedules.php', ['op' => 'delete', 'id' => $row['id']]);
        $this->assertNull(DB::one('SELECT id FROM device_schedules WHERE id = :id', ['id' => $row['id']]));

        // Bell timetable: many times at once for weekdays.
        [$code] = $s->post('device_schedules.php', ['op' => 'bulk_bells', 'times' => "08:00, 8:45; 09.30\n13:15 08:00", 'title' => 'Period', 'sound' => 'b:school_bell', 'sound_repeat' => '2', 'sound_volume' => '',
            'days' => [1, 2, 3, 4, 5], 'target_type' => 'all']);
        $this->assertSame(302, $code);
        $bells = DB::all("SELECT * FROM device_schedules WHERE hotel_id = 1 AND action = 'bell' ORDER BY run_time");
        $this->assertSame(['08:00:00', '08:45:00', '09:30:00', '13:15:00'], array_column($bells, 'run_time'));
        $this->assertSame(['Period 08:00', 'weekly', '1,2,3,4,5', '{"sound":"b:school_bell","repeat":2}'], [$bells[0]['title'], $bells[0]['repeat_mode'], $bells[0]['days'], $bells[0]['options']]);
        // Fires on a weekday, not on Sunday 2026-10-11.
        DB::query("UPDATE device_schedules SET active_from = '2020-01-01 00:00:00' WHERE hotel_id = 1");
        $this->assertSame(0, DeviceSchedules::tick(self::ts('2026-10-11 08:45:00'))['fired']);
        $this->assertSame(1, DeviceSchedules::tick(self::ts('2026-10-12 08:45:00'))['fired']);
        // Invalid entries: nothing is created.
        [$code] = $s->post('device_schedules.php', ['op' => 'bulk_bells', 'times' => '08:00, 25:00, abc', 'sound' => 'b:school_bell', 'days' => [1], 'target_type' => 'all']);
        $this->assertSame(302, $code);
        [, , $html] = $s->get('device_schedules.php');
        $this->assertStringContainsString(e('Not a time: 25:00, abc'), $html);
        [$code] = $s->post('device_schedules.php', ['op' => 'bulk_bells', 'times' => '10:00', 'sound' => 'b:none', 'days' => [], 'target_type' => 'all']);
        [, , $html] = $s->get('device_schedules.php');
        $this->assertStringContainsString('Choose a sound.', $html);
        $this->assertSame(4, (int) DB::value("SELECT COUNT(*) FROM device_schedules WHERE hotel_id = 1 AND action = 'bell'"));

        // A manager limited to room 101: no 'all', no foreign rooms, sees only own schedules.
        $lim = new AdminSession(self::$url, 'dsLim');
        [$code, , $html] = $lim->get('device_schedules.php');
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'limited schedules page');
        $this->assertStringNotContainsString('Period 08:00', $html, 'hotel-wide bells are not listed for a limited user');
        [$code] = $lim->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'action' => 'mute', 'run_time' => '10:00', 'repeat_mode' => 'daily', 'target_type' => 'all']);
        $this->assertSame(403, $code);
        [$code] = $lim->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'action' => 'mute', 'run_time' => '10:00', 'repeat_mode' => 'daily', 'target_type' => 'rooms', 'room_ids' => [self::$id['r201']]]);
        $this->assertSame(403, $code);
        [$code] = $lim->post('device_schedules.php', ['op' => 'delete', 'id' => $bells[0]['id']]);
        $this->assertSame(403, $code, 'cannot delete a hotel-wide schedule');
        [$code] = $lim->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'title' => 'LIMITED-OWN', 'action' => 'mute', 'run_time' => '10:00', 'repeat_mode' => 'daily', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(302, $code);
        [, , $html] = $lim->get('device_schedules.php');
        $this->assertStringContainsString('LIMITED-OWN', $html);

        // Staff: announce only (limited staff only to their TVs); reception: no access.
        $st = new AdminSession(self::$url, 'dsStaff');
        [$code, , $html] = $st->get('device_schedules.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Announce now', $html);
        $this->assertStringNotContainsString('scheduleForm', $html);
        [$code] = $st->post('device_schedules.php', ['op' => 'save', 'id' => 0, 'action' => 'mute', 'run_time' => '10:00', 'target_type' => 'all']);
        $this->assertSame(403, $code);
        DB::query('DELETE FROM device_commands');
        [$code] = $st->post('device_schedules.php', ['op' => 'announce', 'text' => 'Lunch is ready', 'lang' => 'en', 'rate' => '1', 'repeat' => '1', 'target_type' => 'all']);
        $this->assertSame(302, $code);
        $this->assertCount(3, self::commands('SPEAK'));
        $ls = new AdminSession(self::$url, 'dsLimStaff');
        [$code] = $ls->post('device_schedules.php', ['op' => 'announce', 'text' => 'x', 'target_type' => 'rooms', 'room_ids' => [self::$id['r102']]]);
        $this->assertSame(403, $code);
        [$code] = $ls->post('device_schedules.php', ['op' => 'announce', 'text' => 'Only mine', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(302, $code);
        $this->assertSame([self::$id['d101']], array_column(array_filter(self::commands('SPEAK'), fn ($c) => $c[2]['text'] === 'Only mine'), 0));
        $rc = new AdminSession(self::$url, 'dsRecep');
        [$code] = $rc->get('device_schedules.php');
        $this->assertSame(403, $code);
        [$code] = $rc->post('device_schedules.php', ['op' => 'announce', 'text' => 'x', 'target_type' => 'all']);
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ sounds

    private static function wav(int $samples = 2205, string $append = ''): string
    {
        $data = str_repeat(pack('v', 1000) . pack('v', 64536), intdiv($samples, 2));
        $f = tempnam(sys_get_temp_dir(), 'dsw') . '.wav';
        file_put_contents($f, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16) . 'data' . pack('V', strlen($data)) . $data . $append);
        return $f;
    }

    private function upload(AdminSession $s, string $path, string $name, string $label = 'My bell'): array
    {
        return TestEnv::http('POST', self::$url . 'admin/device_schedules.php', null, [], $s->jar, ['_csrf' => $s->csrf, 'op' => 'sound_upload', 'name' => $label, 'file' => new CURLFile($path, 'application/octet-stream', $name)]);
    }

    public function testSoundUploadValidationLibraryAndTenancy(): void
    {
        // Unit: real audio check.
        foreach (glob(HC_ROOT . '/assets/sounds/*.wav') as $f) {
            $this->assertTrue(Uploader::isAudio($f, 'wav', (new finfo(FILEINFO_MIME_TYPE))->file($f)), basename($f));
            $this->assertLessThan(Uploader::AUDIO_MAX_MB * 1024 * 1024, filesize($f));
        }
        $this->assertCount(3, glob(HC_ROOT . '/assets/sounds/*.wav'));

        $s = new AdminSession(self::$url, 'dsMgr');
        $before = (int) DB::value('SELECT COUNT(*) FROM sounds WHERE hotel_id = 1');
        [$code] = $this->upload($s, self::wav(), 'bell.wav', 'Lunch bell');
        $this->assertSame(302, $code);
        $row = DB::one('SELECT * FROM sounds WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['Lunch bell', 'audio/wav'], [$row['name'], $row['mime']]);
        $this->assertMatchesRegularExpression('#^h1/sounds/\d{4}/\d{2}/[0-9a-f]{24}\.wav$#', $row['file_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $row['file_path']);
        [$code, , $body] = TestEnv::http('GET', self::$url . 'uploads/' . $row['file_path']);
        $this->assertSame([200, 'RIFF'], [$code, substr($body, 0, 4)]);

        $php = tempnam(sys_get_temp_dir(), 'dsp');
        file_put_contents($php, "<?php echo 'pwned'; ?>");
        $jpg = tempnam(sys_get_temp_dir(), 'dsj');
        $im = imagecreatetruecolor(10, 10);
        imagejpeg($im, $jpg);
        $big = self::wav(3 * 1024 * 1024); // 6 MB of samples
        $id3php = tempnam(sys_get_temp_dir(), 'dsm');
        file_put_contents($id3php, 'ID3' . str_repeat("\0", 20) . '<?php system($_GET["c"]); ?>');
        foreach ([
            [$php, 'evil.php', 'Only MP3, WAV or OGG'],
            [$php, 'evil.mp3', 'Only MP3, WAV or OGG'],
            [$jpg, 'photo.wav', 'Only MP3, WAV or OGG'],
            [self::wav(100, '<?php phpinfo(); ?>'), 'poly.wav', 'Only MP3, WAV or OGG'],
            [$id3php, 'tag.mp3', 'Only MP3, WAV or OGG'],
            [self::wav(), 'sound.wav.php', 'Only MP3, WAV or OGG'],
            [$big, 'big.wav', '/Maximum is 5 MB|server upload limit/'],
        ] as [$path, $name, $err]) {
            [$code, , , $head] = $this->upload($s, $path, $name);
            $this->assertSame(302, $code, $name);
            [, , $html] = $s->get('device_schedules.php?tab=sounds');
            str_starts_with($err, '/') ? $this->assertMatchesRegularExpression($err, $html, $name) : $this->assertStringContainsString($err, $html, $name);
        }
        // The 5 MB limit itself (independent of the server's upload_max_filesize).
        try {
            Sounds::upload(['name' => 'big.wav', 'tmp_name' => $big, 'size' => filesize($big), 'error' => UPLOAD_ERR_OK], 'Big');
            $this->fail('6 MB sound accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Maximum is 5 MB', $e->getMessage());
        }
        $this->assertSame($before + 1, (int) DB::value('SELECT COUNT(*) FROM sounds WHERE hotel_id = 1'), 'only the valid file was stored');
        $this->assertSame([], glob(HC_ROOT . '/uploads/h1/sounds/*/*/*.php') ?: []);

        // Library page lists built-ins + uploads with players; no other hotel's sounds.
        [$code, , $html] = $s->get('device_schedules.php?tab=sounds');
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'sounds tab');
        foreach (['School bell', 'Temple bell', 'Soft chime', 'Lunch bell', 'assets/sounds/temple_bell.wav'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('H2-SOUND-SECRET', $html);
        // A sound in use cannot be deleted; another hotel's id → 404.
        $sid = self::schedule(['action' => 'bell', 'options' => json_out(['sound' => 'u:' . $row['id'], 'repeat' => 1])]);
        $s->post('device_schedules.php', ['op' => 'sound_delete', 'id' => $row['id']]);
        $this->assertNotNull(Sounds::find((int) $row['id']));
        [$code] = $s->post('device_schedules.php', ['op' => 'sound_delete', 'id' => self::$id['h2sound']]);
        $this->assertSame(404, $code);
        DB::delete('device_schedules', 'id = :id', ['id' => $sid]);
        $s->post('device_schedules.php', ['op' => 'sound_delete', 'id' => $row['id']]);
        $this->assertNull(DB::one('SELECT id FROM sounds WHERE id = :id', ['id' => $row['id']]));
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $row['file_path']);
        // Staff may not upload.
        $st = new AdminSession(self::$url, 'dsStaff');
        [$code] = $this->upload($st, self::wav(), 'x.wav');
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ presence webhook

    public function testPresenceWebhookAuthScreenOnIdleOffAndPrecedence(): void
    {
        $s = new AdminSession(self::$url, 'dsMgr');
        [$code, , , $head] = $s->post('device_schedules.php', ['op' => 'sensor_save', 'id' => 0, 'name' => 'Lobby PIR', 'idle_minutes' => '5', 'is_active' => '1', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101'], self::$id['r102']]]);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('edit=', $head);
        $sensor = DB::one('SELECT * FROM presence_sensors WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        [, , $html] = $s->get('device_schedules.php?tab=presence&edit=' . $sensor['id']);
        $this->assertSame(1, preg_match('/value="(prs[0-9a-f]{48})"/', $html, $m), 'token shown once');
        $token = $m[1];
        $this->assertSame([hash('sha256', $token), substr($token, -4)], [$sensor['token_hash'], $sensor['token_hint']]);
        [, , $html] = $s->get('device_schedules.php?tab=presence&edit=' . $sensor['id']);
        $this->assertStringNotContainsString($token, $html, 'only once');
        $this->assertNoPhpErrors($html, 'presence tab');
        $this->assertStringNotContainsString('H2-SENSOR-SECRET', $html);
        [$code] = $s->post('device_schedules.php', ['op' => 'sensor_save', 'id' => 0, 'name' => '', 'idle_minutes' => '0', 'target_type' => 'rooms']);
        $this->assertSame(422, $code);

        $push = static function (?string $tok, ?array $body = ['event' => 'motion'], string $how = 'bearer'): array {
            $h = $tok !== null && $how === 'bearer' ? ['Authorization: Bearer ' . $tok] : [];
            $url = self::$url . 'api/presence' . ($how === 'query' ? '?token=' . $tok : '');
            if ($how === 'body') {
                $body['token'] = $tok;
            }
            return TestEnv::http('POST', $url, $body, $h);
        };
        [$code, $j] = $push(null);
        $this->assertSame([401, 'INVALID_TOKEN'], [$code, $j['error']['code']]);
        [$code] = $push('prs' . str_repeat('0', 48));
        $this->assertSame(401, $code);
        [$code] = TestEnv::http('GET', self::$url . 'api/presence');
        $this->assertSame(405, $code);
        [$code, $j] = $push($token, ['event' => 'dance']);
        $this->assertSame([400, 'VALIDATION_ERROR'], [$code, $j['error']['code']]);

        // Motion → SCREEN_ON to the sensor's TVs (once while people are around).
        [$code, $j] = $push($token);
        $this->assertSame(200, $code, json_encode($j));
        $this->assertSame(['motion', 'on', 2], [$j['data']['event'], $j['data']['state'], $j['data']['screens_on']]);
        $this->assertSame([self::$id['d101'], self::$id['d102']], array_column(self::commands('SCREEN_ON'), 0));
        $this->assertSame(1, (int) DB::value('SELECT is_enabled FROM rooms WHERE id = :id', ['id' => self::$id['r101']]), 'not persisted on the room');
        [, $j] = $push($token, ['event' => 'motion'], 'body');
        $this->assertSame(0, $j['data']['screens_on'], 'already on');
        [, $j] = $push($token, ['state' => 'off'], 'query');
        $this->assertSame('clear', $j['data']['event'], 'Home Assistant style state + token in the query string');
        $this->assertCount(2, self::commands('SCREEN_ON'));
        $row = Presence::find((int) $sensor['id']);
        $this->assertSame(3, (int) $row['events']);
        $this->assertNotNull($row['last_seen']);

        // Idle: no motion for idle_minutes → SCREEN_OFF (emergency rooms excluded), once.
        $seen = (int) strtotime((string) $row['last_seen']);
        $this->assertSame(0, Presence::idleTick($seen + 4 * 60));
        Broadcaster::emergencyStart('Flood', 'Go up', 'rooms', [self::$id['r102']]);
        $this->assertSame(1, Presence::idleTick($seen + 5 * 60 + 1));
        $this->assertSame([self::$id['d101']], array_column(self::commands('SCREEN_OFF'), 0), 'emergency room stays on');
        $this->assertSame('off', Presence::find((int) $sensor['id'])['state']);
        $this->assertSame(0, Presence::idleTick($seen + 20 * 60), 'once');
        Broadcaster::emergencyStop();
        // During an emergency, motion does not need to switch on the emergency room (it is awake anyway).
        DB::query('DELETE FROM device_commands');

        // Power-off schedule precedence: a room inside a TV-off window stays off …
        $win = DB::insert('broadcast_commands', ['title' => 'Night off', 'command' => 'SCREEN_OFF', 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r102']]),
            'mode' => 'window', 'status' => 'active', 'daily_start' => date('H:i:s', time() - 3600), 'daily_end' => date('H:i:s', time() + 3600), 'repeat_days' => '1,2,3,4,5,6,7', 'created_at' => now()]);
        DB::update('rooms', ['is_enabled' => 0], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        Cache::clear();
        DB::query('DELETE FROM rate_limits');
        [, $j] = $push($token);
        $this->assertSame(0, $j['data']['screens_on']);
        $this->assertSame(['101' => 'switched_off', '102' => 'power_schedule'], $j['data']['skipped']);
        $this->assertSame([], self::commands('SCREEN_ON'));
        $this->assertSame('off', Presence::find((int) $sensor['id'])['state']);
        // … unless "presence overrides schedule" is ticked; a room switched off by the admin never.
        [$code] = $s->post('device_schedules.php', ['op' => 'sensor_save', 'id' => $sensor['id'], 'name' => 'Lobby PIR', 'idle_minutes' => '5', 'is_active' => '1', 'override_schedule' => '1', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101'], self::$id['r102']]]);
        $this->assertSame(302, $code);
        [, $j] = $push($token);
        $this->assertSame([1, ['101' => 'switched_off']], [$j['data']['screens_on'], $j['data']['skipped']]);
        $this->assertSame([self::$id['d102']], array_column(self::commands('SCREEN_ON'), 0));
        DB::delete('broadcast_commands', 'id = :id', ['id' => $win]);
        DB::update('rooms', ['is_enabled' => 1], 'id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        // Paused sensor: events are recorded, nothing switched.
        DB::update('presence_sensors', ['is_active' => 0, 'state' => 'off'], 'id = :id', ['id' => $sensor['id']]);
        DB::query('DELETE FROM device_commands');
        [, $j] = $push($token);
        $this->assertSame([false, 0], [$j['data']['active'], $j['data']['screens_on']]);
        $this->assertSame([], self::commands());
        DB::update('presence_sensors', ['is_active' => 1], 'id = :id', ['id' => $sensor['id']]);

        // Rate limits: 60 events / minute per sensor, 20 bad tokens / 10 minutes per IP.
        DB::query('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < 61; $i++) {
            $codes[] = $push($token, ['event' => 'ping'])[0];
        }
        $this->assertSame(array_fill(0, 60, 200), array_slice($codes, 0, 60));
        [$code, $j, , $head] = $push($token, ['event' => 'ping']);
        $this->assertSame([429, 'RATE_LIMITED'], [$code, $j['error']['code']]);
        $this->assertMatchesRegularExpression('/Retry-After: \d+/i', $head);
        DB::query('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < 21; $i++) {
            $codes[] = $push('prs' . str_repeat('a', 48))[0];
        }
        $this->assertSame([401, 429], [$codes[19], $codes[20]]);
        DB::query('DELETE FROM rate_limits');

        // Tenancy: hotel 2's sensor only reaches hotel 2; suspended hotel → 403; revoked token → 401.
        $tok2 = Tenant::run(self::$id['h2'], fn () => Presence::newToken(self::$id['h2sensor']));
        DB::query('DELETE FROM device_commands');
        [$code, $j] = $push($tok2);
        $this->assertSame([200, 1], [$code, $j['data']['screens_on']]);
        $this->assertSame([], self::commands());
        $this->assertSame('SCREEN_ON', DB::value('SELECT command FROM device_commands WHERE device_id = :d', ['d' => self::$id['h2dev']]));
        DB::update('hotels', ['status' => 'suspended'], 'id = :id', ['id' => self::$id['h2']]);
        Tenant::forget();
        [$code, $j] = $push($tok2);
        $this->assertSame([403, 'HOTEL_SUSPENDED'], [$code, $j['error']['code']]);
        DB::update('hotels', ['status' => 'active'], 'id = :id', ['id' => self::$id['h2']]);
        Tenant::forget();
        Tenant::set(1);
        [$code] = $s->post('device_schedules.php', ['op' => 'sensor_delete', 'id' => self::$id['h2sensor']]);
        $this->assertSame(404, $code);
        $s->post('device_schedules.php', ['op' => 'sensor_revoke', 'id' => $sensor['id']]);
        [$code] = $push($token);
        $this->assertSame(401, $code);
        $this->assertNull(Presence::byToken($token));
        // A limited manager only manages sensors of their rooms.
        $lim = new AdminSession(self::$url, 'dsLim');
        [$code] = $lim->post('device_schedules.php', ['op' => 'sensor_delete', 'id' => $sensor['id']]);
        $this->assertSame(403, $code);
        [$code, , $html] = $lim->get('device_schedules.php?tab=presence');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('Lobby PIR', $html);
        DB::query('DELETE FROM rate_limits');
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ proof of play

    public function testPlayReportNumbersCsvPrintTenancyAndLimitedUsers(): void
    {
        $c1 = DB::insert('content_items', ['title' => 'Welcome video', 'type' => 'video', 'created_at' => now()]);
        $c2 = DB::insert('content_items', ['title' => 'Spa offer ' . self::XSS, 'type' => 'image', 'created_at' => now()]);
        $pl = DB::insert('content_playlists', ['name' => 'Lobby loop', 'created_at' => now()]);
        DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $c2, 'sort_order' => 0]);
        $sp = DB::insert('sponsors', ['name' => 'Jeweller', 'created_at' => now()]);
        $ad = DB::insert('ad_campaigns', ['sponsor_id' => $sp, 'name' => 'Diwali sale', 'start_date' => '2026-01-01', 'created_at' => now()]);
        $log = static function (string $dev, int $content, string $at, ?int $sec, ?int $adId = null): void {
            $room = (int) DB::value('SELECT room_id FROM devices WHERE id = :id', ['id' => self::$id[$dev]]);
            DB::insert('broadcast_logs', ['device_id' => self::$id[$dev], 'room_id' => $room, 'content_id' => $content, 'event' => 'played', 'duration_sec' => $sec, 'ad_campaign_id' => $adId, 'created_at' => $at]);
        };
        DB::query("DELETE FROM broadcast_logs WHERE event = 'played'");
        // Range 2026-09-01 .. 2026-09-03.
        $log('d101', $c1, '2026-09-01 10:00:00', 60);
        $log('d101', $c1, '2026-09-01 11:00:00', 60);
        $log('d101', $c2, '2026-09-02 09:00:00', 15, $ad);
        $log('d102', $c2, '2026-09-02 09:30:00', 15, $ad);
        $log('d102', $c1, '2026-09-03 23:59:59', 30);
        $log('d201', $c2, '2026-09-03 08:00:00', null);
        $log('d201', $c1, '2026-08-31 23:59:59', 999);   // before the range
        $log('d201', $c1, '2026-09-04 00:00:00', 999);   // after the range
        DB::insert('broadcast_logs', ['device_id' => self::$id['d101'], 'room_id' => self::$id['r101'], 'content_id' => $c1, 'event' => 'queued', 'created_at' => '2026-09-01 12:00:00']);
        Tenant::run(self::$id['h2'], fn () => DB::insert('broadcast_logs', ['device_id' => self::$id['h2dev'], 'room_id' => self::$id['h2room'], 'content_id' => self::$id['h2content'], 'event' => 'played', 'duration_sec' => 500, 'created_at' => '2026-09-02 10:00:00']));

        $f = PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03']);
        $this->assertSame(['plays' => 6, 'seconds' => 180, 'tvs' => 3, 'rooms' => 3, 'items' => 2, 'days' => 3], PlayReport::summary($f));
        $this->assertSame([[2, 120], [2, 30], [2, 30]], array_map(fn ($d) => [$d['plays'], $d['seconds']], PlayReport::perDay($f)));
        $tv = PlayReport::perTv($f);
        $this->assertSame([['101', 3, 135], ['102', 2, 45], ['201', 1, 0]], array_map(fn ($r) => [$r['room'], $r['plays'], $r['seconds']], $tv));
        $this->assertSame([[$c1, 3, 150], [$c2, 3, 30]], array_map(fn ($r) => [$r['content_id'], $r['plays'], $r['seconds']], PlayReport::perContent($f)));
        $this->assertSame(3, PlayReport::summary(PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03', 'playlist_id' => $pl]))['plays']);
        $this->assertSame(2, PlayReport::summary(PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03', 'campaign_id' => $ad]))['plays']);
        $this->assertSame(5, PlayReport::summary(PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03', 'group_id' => self::$id['g1']]))['plays']);
        $this->assertSame(1, PlayReport::summary(PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03', 'room_id' => self::$id['r201']]))['plays']);
        $this->assertSame(2, PlayReport::summary(PlayReport::filters(['from' => '2026-09-01', 'to' => '2026-09-03', 'content_id' => $c1, 'room_id' => self::$id['r101']]))['plays']);
        // Max 366 days.
        $wide = PlayReport::filters(['from' => '2020-01-01', 'to' => '2026-09-03']);
        $this->assertSame('2025-09-03', $wide['from']);
        // Pagination.
        $this->assertSame(['2026-09-03 23:59:59', '2026-09-03 08:00:00'], array_column(PlayReport::rows($f, 1, 2), 'played_at'), 'newest first');
        $this->assertCount(2, PlayReport::rows($f, 3, 2));
        $this->assertCount(0, PlayReport::rows($f, 4, 2));
        $this->assertCount(6, iterator_to_array(PlayReport::iterate($f), false));
        // Another hotel's ids → 404.
        try {
            PlayReport::filters(['content_id' => self::$id['h2content']]);
            $this->fail('foreign content accepted');
        } catch (TenantException) {
            $this->addToAssertionCount(1);
        }

        $q = 'from=2026-09-01&to=2026-09-03';
        $m = new AdminSession(self::$url, 'dsMgr');
        [$code, , $html] = $m->get('play_report.php?' . $q);
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'play report');
        $this->assertStringContainsString('data-pr="plays">6<', $html);
        $this->assertStringContainsString('data-pr="tvs">3<', $html);
        $this->assertStringContainsString('Diwali sale', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('H2-CONTENT-SECRET', $html);
        [$code, , $csv, $head] = $m->get('play_report.php?' . $q . '&csv=plays');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('text/csv', $head);
        $this->assertStringContainsString('proof-of-play-2026-09-01_2026-09-03.csv', $head);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertCount(7, $lines, 'header + 6 plays');
        $this->assertStringStartsWith('"Played at",Room,TV,Content', $lines[0]);
        $this->assertStringStartsWith('"2026-09-03 23:59:59",102,', $lines[1]);
        [, , $csv] = $m->get('play_report.php?' . $q . '&csv=days');
        $this->assertStringContainsString("2026-09-01,2,120,1", $csv);
        [, , $csv] = $m->get('play_report.php?' . $q . '&csv=tvs');
        $this->assertStringContainsString('101,', $csv);
        [$code, , $html] = $m->get('play_report.php?' . $q . '&print=1');
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'print view');
        $this->assertStringContainsString('Generated by Krishna Cloud LED TV on ', $html);
        $this->assertStringContainsString('Krishna Palace', $html);
        $this->assertStringContainsString('01 Sep 2026', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('Signature', $html);
        [$code] = $m->get('play_report.php?content_id=' . self::$id['h2content']);
        $this->assertSame(404, $code);

        // Limited manager (room 101 only): only their TVs; another room → 403.
        $lim = new AdminSession(self::$url, 'dsLim');
        [$code, , $html] = $lim->get('play_report.php?' . $q);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('data-pr="plays">3<', $html);
        $this->assertStringContainsString('data-pr="tvs">1<', $html);
        [, , $csv] = $lim->get('play_report.php?' . $q . '&csv=plays');
        $this->assertCount(4, array_values(array_filter(explode("\n", trim(substr($csv, 3))))));
        $this->assertStringNotContainsString(',102,', $csv);
        [$code] = $lim->get('play_report.php?' . $q . '&room_id=' . self::$id['r201']);
        $this->assertSame(403, $code);
        [$code, , $html] = $lim->get('play_report.php?' . $q . '&print=1');
        $this->assertStringContainsString('Only your TVs', $html);
        // Staff / reception: no report.
        foreach (['dsStaff', 'dsRecep'] as $u) {
            [$code] = (new AdminSession(self::$url, $u))->get('play_report.php');
            $this->assertSame(403, $code, $u);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ pages, roles, translations

    public function testAllPagesRenderWithoutWarningsForEveryRole(): void
    {
        DB::insert('presence_sensors', ['name' => 'Corridor ' . self::XSS, 'target_type' => 'rooms', 'target_ids' => json_out([self::$id['r101']]), 'state' => 'on', 'last_seen' => now(), 'created_at' => now()]);
        self::schedule(['title' => 'Bell ' . self::XSS, 'action' => 'bell', 'options' => json_out(['sound' => 'b:soft_chime', 'repeat' => 2, 'volume' => 50]), 'repeat_mode' => 'weekly', 'days' => '1,2']);
        self::schedule(['title' => 'Restart', 'action' => 'reboot', 'options' => json_out(['min_uptime_h' => 12, 'stagger_min' => 5])]);
        self::schedule(['title' => 'HDMI', 'action' => 'input', 'options' => '{"input":"hdmi1"}', 'repeat_mode' => 'once', 'run_date' => '2026-12-31']);
        self::schedule(['title' => 'Speak', 'action' => 'speak', 'options' => json_out(['text' => 'Hello ' . self::XSS, 'lang' => 'gu', 'rate' => 1, 'repeat' => 1, 'chime_before' => true])]);
        self::schedule(['title' => 'Back', 'action' => 'player', 'options' => '{}']);
        $pages = ['device_schedules.php', 'device_schedules.php?tab=sounds', 'device_schedules.php?tab=presence', 'play_report.php', 'play_report.php?print=1', 'index.php'];
        foreach (['dsBoss' => 200, 'dsMgr' => 200, 'dsLim' => 200, 'dsStaff' => 'staff', 'dsLimStaff' => 'staff', 'dsRecep' => 403] as $u => $expect) {
            foreach (['en', 'gu'] as $lang) { // admin panel languages (Hindi strings are covered below)
                DB::update('users', ['language' => $lang], 'id = :id', ['id' => self::$id[$u]]);
                $s = new AdminSession(self::$url, $u); // the UI language is taken at login
                foreach ($pages as $p) {
                    [$code, , $html] = $s->get($p);
                    $want = $p === 'index.php' ? 200 : ($expect === 'staff' ? (str_starts_with($p, 'play_report') ? 403 : 200) : $expect);
                    $this->assertSame($want, $code, "$u $lang $p");
                    $this->assertNoPhpErrors($html, "$u $lang $p");
                    $this->assertStringNotContainsString('<script>alert(1)', $html, "$u $p");
                    if ($p === 'index.php') {
                        $this->assertSame($want !== 403 && $expect !== 403, str_contains($html, 'device_schedules.php'), "$u sidebar");
                        $this->assertSame($expect === 200, str_contains($html, 'play_report.php'), "$u sidebar report");
                    }
                }
            }
            DB::update('users', ['language' => 'en'], 'id = :id', ['id' => self::$id[$u]]);
        }
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $keys = array_merge(array_values(DeviceSchedules::ACTIONS), array_values(DeviceSchedules::REPEATS), array_values(Sounds::BUILTIN), ['Only MP3, WAV or OGG sound files are allowed.', 'Spoken announcement', 'Play sound']);
        foreach (['core/DeviceSchedules.php', 'core/Presence.php', 'core/Sounds.php', 'core/PlayReport.php', 'admin/device_schedules.php', 'admin/play_report.php', 'admin/partials/nav.d/23_device_schedules.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        $missing = [];
        foreach (array_unique($keys) as $k) {
            foreach (['gu' => $gu, 'hi' => $hi] as $l => $t) {
                if (!isset($t[$k]) || $t[$k] === '') {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        I18n::setLang('gu');
        $this->assertSame('ડિવાઇસ સમયપત્રક', __('Device schedules'));
        I18n::setLang('en');
    }
}
