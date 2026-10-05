<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResolverBroadcastTest extends TestCase
{
    private int $room101;
    private int $room201;
    private int $floor1;
    private int $cWelcome;
    private int $cOffer;
    private int $cDefault;
    private int $playlist;

    protected function setUp(): void
    {
        TestEnv::resetDatabase();
        $this->room101 = DB::insert('rooms', ['room_number' => '101', 'floor' => '1', 'name' => 'R101']);
        $this->room201 = DB::insert('rooms', ['room_number' => '201', 'floor' => '2', 'name' => 'R201']);
        $this->floor1 = DB::insert('room_groups', ['name' => 'Floor 1', 'type' => 'floor']);
        DB::insert('room_group_members', ['room_id' => $this->room101, 'group_id' => $this->floor1]);
        $mk = fn (string $t) => DB::insert('content_items', ['title' => $t, 'type' => 'announcement', 'body' => $t, 'duration' => 10]);
        $this->cWelcome = $mk('Welcome');
        $this->cOffer = $mk('Offer');
        $this->cDefault = $mk('Default');
        $this->playlist = DB::insert('content_playlists', ['name' => 'Loop', 'transition' => 'slide']);
        DB::insert('playlist_items', ['playlist_id' => $this->playlist, 'content_id' => $this->cWelcome, 'sort_order' => 1, 'duration' => 7]);
        DB::insert('playlist_items', ['playlist_id' => $this->playlist, 'content_id' => $this->cOffer, 'sort_order' => 2]);
        Settings::set('default_content_id', (string) $this->cDefault);
        Settings::bumpContentVersion();
    }

    private function room(int $id): array
    {
        return DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $id]);
    }

    public function testDefaultThenGroupThenRoomPriority(): void
    {
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('default', $c['mode']);
        $this->assertSame('Default', $c['items'][0]['title']);

        DB::update('room_groups', ['playlist_id' => $this->playlist], 'id = :id', ['id' => $this->floor1]);
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('group', $c['mode']);
        $this->assertSame('slide', $c['playlist']['transition']);
        $this->assertSame(7, $c['items'][0]['duration'], 'Playlist per-item duration override');
        $this->assertSame(10, $c['items'][1]['duration']);
        $this->assertSame('default', ContentResolver::build($this->room($this->room201))['mode'], 'Room 201 not in group');

        DB::update('rooms', ['content_id' => $this->cOffer], 'id = :id', ['id' => $this->room101]);
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('assigned', $c['mode']);
        $this->assertSame('Offer', $c['items'][0]['title']);
    }

    public function testHashChangesOnlyWhenContentChanges(): void
    {
        $a = ContentResolver::build($this->room($this->room101));
        sleep(1);
        $b = ContentResolver::build($this->room($this->room101));
        $this->assertSame($a['hash'], $b['hash'], 'Hash is stable (generated_at excluded)');
        DB::update('content_items', ['body' => 'Changed'], 'id = :id', ['id' => $this->cDefault]);
        $this->assertNotSame($a['hash'], ContentResolver::build($this->room($this->room101))['hash']);
    }

    public function testRoomOff(): void
    {
        DB::update('rooms', ['is_enabled' => 0], 'id = :id', ['id' => $this->room101]);
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('off', $c['mode']);
        $this->assertFalse($c['screen_on']);
    }

    public function testPushNowAssignsRoomsAndQueuesCommands(): void
    {
        $dev = DB::insert('devices', ['device_uid' => 'dev-aaaa-1111', 'room_id' => $this->room101, 'token_hash' => str_repeat('a', 64)]);
        $bid = Broadcaster::pushNow('floors', ['1'], null, $this->playlist, 'Test push', null);
        $this->assertGreaterThan(0, $bid);
        $this->assertSame($this->playlist, (int) $this->room($this->room101)['playlist_id']);
        $this->assertNull($this->room($this->room201)['playlist_id']);
        $cmds = DeviceManager::pendingCommands($dev);
        $this->assertCount(1, $cmds);
        $this->assertSame('SHOW_CONTENT', $cmds[0]['command']);
        // Second push collapses the older pending refresh.
        Broadcaster::pushNow('rooms', [$this->room101], $this->cOffer, null);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE device_id = :d AND status IN ('pending','delivered')", ['d' => $dev]));
    }

    public function testEmergencyOverridesEverythingAndStops(): void
    {
        DB::update('rooms', ['content_id' => $this->cOffer], 'id > 0');
        $id = Broadcaster::emergencyStart('Fire drill', 'Please use the stairs', 'groups', [$this->floor1]);
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('emergency', $c['mode']);
        $this->assertSame('Fire drill', $c['emergency']['title']);
        $this->assertSame('assigned', ContentResolver::build($this->room($this->room201))['mode'], 'Room outside target unaffected');
        $this->assertSame(1, Broadcaster::emergencyStop($id));
        $this->assertSame('assigned', ContentResolver::build($this->room($this->room101))['mode']);
    }

    public function testScheduledOncePushesWhenDue(): void
    {
        [$data, $errors] = Broadcaster::validateSchedule([
            'source' => 'c:' . $this->cOffer, 'target_type' => 'all', 'mode' => 'once',
            'start_at' => date('Y-m-d\TH:i', time() - 60),
        ]);
        $this->assertSame([], $errors);
        $id = Broadcaster::schedule($data);
        $res = Broadcaster::processSchedules();
        $this->assertSame(1, $res['pushed']);
        $this->assertSame('completed', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $id]));
        $this->assertSame($this->cOffer, (int) $this->room($this->room201)['content_id']);
        $this->assertSame(0, Broadcaster::processSchedules()['pushed'], 'Not processed twice');
    }

    public function testTimeWindowOverridesOnlyInsideWindow(): void
    {
        $now = time();
        [$data, $errors] = Broadcaster::validateSchedule([
            'source' => 'p:' . $this->playlist, 'target_type' => 'rooms', 'room_ids' => [$this->room201], 'mode' => 'window',
            'daily_start' => date('H:i', $now - 3600), 'daily_end' => date('H:i', $now + 3600),
            'repeat_days' => [(int) date('N')],
        ]);
        if (date('H', $now - 3600) > date('H', $now + 3600)) {
            $this->markTestSkipped('Window crosses midnight at this time of day');
        }
        $this->assertSame([], $errors);
        Broadcaster::schedule($data);
        Settings::bumpContentVersion();
        $c = ContentResolver::build($this->room($this->room201));
        $this->assertSame('scheduled', $c['mode']);
        $this->assertSame('default', ContentResolver::build($this->room($this->room101))['mode']);
        $this->assertSame(1, Broadcaster::processSchedules()['activated']);
    }

    public function testWindowActiveLogic(): void
    {
        $ts = strtotime('2026-10-05 21:00:00'); // Monday
        $b = ['start_at' => null, 'end_at' => null, 'daily_start' => '20:00:00', 'daily_end' => '22:00:00', 'repeat_days' => '1'];
        $this->assertTrue(ContentResolver::windowActive($b, $ts));
        $this->assertFalse(ContentResolver::windowActive($b, strtotime('2026-10-05 22:00:00')));
        $this->assertFalse(ContentResolver::windowActive($b, strtotime('2026-10-06 21:00:00')), 'Tuesday not in repeat days');
        $overnight = ['start_at' => null, 'end_at' => null, 'daily_start' => '22:00:00', 'daily_end' => '02:00:00', 'repeat_days' => '1'];
        $this->assertTrue(ContentResolver::windowActive($overnight, strtotime('2026-10-05 23:30:00')));
        $this->assertTrue(ContentResolver::windowActive($overnight, strtotime('2026-10-06 01:30:00')), 'Overnight continues after midnight');
        $this->assertFalse(ContentResolver::windowActive($overnight, strtotime('2026-10-06 03:00:00')));
        $range = ['start_at' => '2026-10-05 00:00:00', 'end_at' => '2026-10-06 00:00:00', 'daily_start' => null, 'daily_end' => null, 'repeat_days' => null];
        $this->assertTrue(ContentResolver::windowActive($range, $ts));
        $this->assertFalse(ContentResolver::windowActive($range, strtotime('2026-10-07 10:00:00')));
    }

    public function testScreenOffCommandPersists(): void
    {
        [$bid, $count] = Broadcaster::sendCommand('SCREEN_OFF', 'rooms', [$this->room101]);
        $this->assertSame(0, (int) $this->room($this->room101)['is_enabled']);
        Broadcaster::sendCommand('SCREEN_ON', 'all', []);
        $this->assertSame(1, (int) $this->room($this->room101)['is_enabled']);
    }

    public function testOfflineDetection(): void
    {
        $dev = DB::insert('devices', ['device_uid' => 'dev-off-1', 'room_id' => $this->room101, 'token_hash' => str_repeat('b', 64),
            'status' => 'online', 'last_ping' => date('Y-m-d H:i:s', time() - 600)]);
        $changed = DeviceManager::detectOffline();
        $this->assertCount(1, $changed);
        $this->assertSame('offline', DB::value('SELECT status FROM devices WHERE id = :id', ['id' => $dev]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM device_status_logs WHERE device_id = :d AND status='offline'", ['d' => $dev]));
    }

    public function testPowerScheduleTurnsTvsOffInsideWindowOnly(): void
    {
        $now = time();
        if (date('H', $now - 3600) > date('H', $now + 3600)) {
            $this->markTestSkipped('Window crosses midnight at this time of day');
        }
        [$id, $errors] = Broadcaster::schedulePower([
            'target_type' => 'floors', 'floors' => ['1'],
            'off_time' => date('H:i', $now - 3600), 'on_time' => date('H:i', $now + 3600),
            'days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        $this->assertSame([], $errors);
        $c = ContentResolver::build($this->room($this->room101));
        $this->assertSame('off', $c['mode']);
        $this->assertFalse($c['screen_on']);
        $this->assertSame('default', ContentResolver::build($this->room($this->room201))['mode'], 'Other floor stays on');
        $this->assertSame('active', DB::value('SELECT status FROM broadcast_commands WHERE id = :id', ['id' => $id]));

        // Emergency wakes TVs that are scheduled off.
        $em = Broadcaster::emergencyStart('Fire', 'Leave now', 'all', []);
        $this->assertSame('emergency', ContentResolver::build($this->room($this->room101))['mode']);
        Broadcaster::emergencyStop($em);

        // Paused schedule → TV back on.
        Broadcaster::setPowerScheduleEnabled($id, false);
        $this->assertSame('default', ContentResolver::build($this->room($this->room101))['mode']);
        Broadcaster::setPowerScheduleEnabled($id, true);
        $this->assertSame('off', ContentResolver::build($this->room($this->room101))['mode']);
    }

    public function testPowerScheduleOutsideWindowAndOvernight(): void
    {
        $now = time();
        [$id, $errors] = Broadcaster::schedulePower([
            'target_type' => 'all',
            'off_time' => date('H:i', $now + 3600), 'on_time' => date('H:i', $now + 7200),
            'days' => [(int) date('N')],
        ]);
        $this->assertSame([], $errors);
        if (date('H', $now + 3600) < date('H', $now)) {
            $this->markTestSkipped('Window crosses midnight at this time of day');
        }
        $this->assertNotSame('off', ContentResolver::build($this->room($this->room101))['mode']);

        $b = DB::one('SELECT * FROM broadcast_commands WHERE id = :id', ['id' => $id]);
        $b['daily_start'] = '23:00:00';
        $b['daily_end'] = '06:00:00';
        $b['repeat_days'] = '1,2,3,4,5,6,7';
        $this->assertTrue(ContentResolver::windowActive($b, strtotime('2026-10-05 23:30:00')));
        $this->assertTrue(ContentResolver::windowActive($b, strtotime('2026-10-06 05:59:00')));
        $this->assertFalse(ContentResolver::windowActive($b, strtotime('2026-10-06 06:00:00')));
        $this->assertFalse(ContentResolver::windowActive($b, strtotime('2026-10-06 12:00:00')));
    }

    public function testPowerScheduleValidation(): void
    {
        [$id, $errors] = Broadcaster::schedulePower(['target_type' => 'rooms', 'off_time' => '23:00', 'on_time' => '23:00', 'days' => []]);
        $this->assertNull($id);
        $this->assertCount(3, $errors);
    }
}
