<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sponsor ads (#11) and analytics (#17): ad insertion rules (frequency by items / minutes, single-item
 * rotation, date range, daily window, targeting, daily cap per TV, priority, never in emergency / off /
 * suspended / welcome screen), impression logging through the device API, sponsor report numbers and
 * roll-up, analytics math on seeded data (plays, uptime, hours ON, kWh, occupancy, guest services),
 * admin pages + CSV, permissions per role, plan feature flags and cross-hotel isolation.
 */
final class AdsAnalyticsTest extends TestCase
{
    private static string $url;
    private static array $id = [];
    private static string $key = '';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'adMgr', 'staff' => 'adStaff', 'reception' => 'adRecep', 'super_admin' => 'adBoss'] as $role => $u) {
            DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@a.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach (['101' => '1', '102' => '1', '201' => '2', '202' => '2'] as $num => $floor) {
            self::$id['room' . $num] = DB::insert('rooms', ['room_number' => (string) $num, 'name' => 'Room ' . $num, 'floor' => $floor]);
        }
        self::$id['group'] = DB::insert('room_groups', ['name' => 'Suites', 'type' => 'custom']);
        DB::insert('room_group_members', ['room_id' => self::$id['room202'], 'group_id' => self::$id['group']]);
        $pl = DB::insert('content_playlists', ['name' => 'Lobby loop', 'transition' => 'fade']);
        for ($i = 1; $i <= 5; $i++) {
            self::$id['item' . $i] = DB::insert('content_items', ['title' => 'Item ' . $i, 'type' => 'image', 'url' => 'https://x.test/' . $i . '.jpg', 'duration' => 10]);
            DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => self::$id['item' . $i], 'sort_order' => $i]);
        }
        self::$id['playlist'] = $pl;
        self::$id['single'] = DB::insert('content_items', ['title' => 'Welcome board', 'type' => 'announcement', 'body' => 'Welcome', 'duration' => 0]);
        self::$id['ad'] = DB::insert('content_items', ['title' => 'Sweets ad', 'type' => 'image', 'url' => 'https://x.test/ad.jpg', 'duration' => 0]);
        self::$id['ad2'] = DB::insert('content_items', ['title' => 'Taxi ad', 'type' => 'image', 'url' => 'https://x.test/ad2.jpg', 'duration' => 8]);
        self::$id['advideo'] = DB::insert('content_items', ['title' => 'Video ad', 'type' => 'video', 'url' => 'https://x.test/ad.mp4', 'duration' => 0]);
        DB::update('rooms', ['playlist_id' => $pl], 'id IN (:a, :b, :c)', ['a' => self::$id['room101'], 'b' => self::$id['room102'], 'c' => self::$id['room201']]);
        DB::update('rooms', ['content_id' => self::$id['single']], 'id = :id', ['id' => self::$id['room202']]);
        self::$id['sponsor'] = Ads::saveSponsor(null, ['name' => 'Krishna Sweets', 'contact_name' => 'Ramesh', 'phone' => '+91 98765 43210']);
        self::$key = (string) Settings::get('registration_key');

        // Hotel 2 with its own manager, sponsor and campaign.
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'adBoss2', 'email' => 'b2@a.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, static function () use ($pw): void {
            DB::insert('users', ['hotel_id' => 2, 'username' => 'adMgr2', 'email' => 'm2@a.test', 'full_name' => 'M2', 'password_hash' => $pw, 'role' => 'manager']);
            self::$id['h2room'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'H2 101', 'floor' => '1']);
            self::$id['h2content'] = DB::insert('content_items', ['title' => 'H2 ad', 'type' => 'image', 'url' => 'https://x.test/h2.jpg', 'duration' => 5]);
            self::$id['h2main'] = DB::insert('content_items', ['title' => 'H2 main', 'type' => 'image', 'url' => 'https://x.test/h2m.jpg', 'duration' => 0]);
            DB::update('rooms', ['content_id' => self::$id['h2main']], 'id = :id', ['id' => self::$id['h2room']]);
            self::$id['h2sponsor'] = Ads::saveSponsor(null, ['name' => 'H2 Sponsor']);
            self::$id['h2campaign'] = Ads::saveCampaign(null, self::campaignData(['sponsor_id' => self::$id['h2sponsor'], 'content_id' => self::$id['h2content'], 'name' => 'H2 campaign']));
        });
        Cache::clear();
        ContentResolver::resetExtensions();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        // Static per-process caches must not leak hotel rows (plans / status) into later test classes.
        Tenant::forget();
        ContentResolver::resetExtensions();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        DB::query('DELETE FROM ad_campaigns WHERE hotel_id = 1');
        DB::query("DELETE FROM broadcast_logs WHERE hotel_id = 1 AND event = 'played'");
        DB::query('DELETE FROM ad_stats_daily WHERE hotel_id = 1');
        Cache::clear();
    }

    private static function campaignData(array $over = []): array
    {
        return $over + [
            'sponsor_id' => self::$id['sponsor'] ?? 0, 'name' => 'Campaign', 'content_id' => self::$id['ad'] ?? null,
            'start_date' => date('Y-m-d', strtotime('-1 day')), 'end_date' => null, 'daily_start' => null, 'daily_end' => null,
            'target_type' => 'all', 'target_ids' => '[]', 'freq_items' => 2, 'freq_minutes' => 10, 'max_per_day' => null, 'priority' => 0, 'status' => 'active',
        ];
    }

    private static function campaign(array $over = []): int
    {
        return Ads::saveCampaign(null, self::campaignData($over));
    }

    private static function room(string $num): array
    {
        return DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['room' . $num]]);
    }

    /** "item1 item2 A7 item3 …" for readable assertions. */
    private static function seq(array $items): string
    {
        $names = [];
        foreach (self::$id as $k => $v) {
            if (str_starts_with($k, 'item')) {
                $names[$v] = $k;
            }
        }
        return implode(' ', array_map(fn ($i) => isset($i['ad_campaign_id']) ? 'A' . $i['ad_campaign_id'] : ($names[$i['id']] ?? '#' . $i['id']), $items));
    }

    // ------------------------------------------------------------------ insertion rules

    public function testAfterEveryNItemsInPlaylist(): void
    {
        $c = self::campaign(['freq_items' => 2]);
        $content = ContentResolver::build(self::room('101'));
        $this->assertSame('assigned', $content['mode']);
        $this->assertSame("item1 item2 A$c item3 item4 A$c item5", self::seq($content['items']));
        $ad = $content['items'][2];
        $this->assertSame(self::$id['ad'], $ad['id']);
        $this->assertSame($c, $ad['ad_campaign_id']);
        $this->assertSame(Ads::DEFAULT_AD_SECONDS, $ad['duration'], 'duration 0 → default ad length');
        $this->assertSame([$c], $content['ads']);
        $this->assertSame(sha1(json_out(array_diff_key($content, ['hash' => 1, 'generated_at' => 1]))), $content['hash'], 'ads are part of the hash');

        // N larger than the playlist: the ad still runs once per loop (at the end).
        DB::update('ad_campaigns', ['freq_items' => 9], 'id = :id', ['id' => $c]);
        $content = ContentResolver::build(self::room('101'));
        $this->assertSame("item1 item2 item3 item4 item5 A$c", self::seq($content['items']));
    }

    public function testEveryMMinutesInPlaylistAndVideoAdKeepsZero(): void
    {
        $c = self::campaign(['freq_items' => null, 'freq_minutes' => 1, 'content_id' => self::$id['advideo']]);
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['id' => 1000 + $i, 'type' => 'image', 'duration' => $i === 3 ? 0 : 10];
        }
        // 10 s items (item 4 has duration 0 → counted as DEFAULT_ITEM_SECONDS = 10) → ad after 60 s = 6 items.
        $out = Ads::insertIntoPlaylist($items, Ads::liveCampaigns());
        $this->assertCount(11, $out);
        $this->assertSame($c, $out[6]['ad_campaign_id']);
        $this->assertSame(0, $out[6]['duration'], 'video ads play to the end');
        $this->assertSame('video', $out[6]['type']);
    }

    public function testSingleItemBecomesRotation(): void
    {
        $c = self::campaign(['freq_minutes' => 7]);
        $content = ContentResolver::build(self::room('202'));
        $this->assertSame('assigned', $content['mode']);
        $this->assertNull($content['playlist']);
        $this->assertCount(2, $content['items']);
        $this->assertSame(self::$id['single'], $content['items'][0]['id']);
        $this->assertSame(7 * 60, $content['items'][0]['duration'], 'main item for M minutes instead of forever');
        $this->assertSame($c, $content['items'][1]['ad_campaign_id']);
        // Two campaigns: the shortest interval wins, both ads in the break (priority order).
        $c2 = self::campaign(['freq_minutes' => 3, 'content_id' => self::$id['ad2'], 'priority' => 5]);
        $content = ContentResolver::build(self::room('202'));
        $this->assertSame(180, $content['items'][0]['duration']);
        $this->assertSame([$c2, $c], [$content['items'][1]['ad_campaign_id'], $content['items'][2]['ad_campaign_id']]);
        $this->assertSame(8, $content['items'][1]['duration']);
    }

    public function testDateRangeDailyWindowAndStatus(): void
    {
        $room = self::room('101');
        $noon = strtotime(date('Y-m-d 12:00:00'));
        $c = self::campaign(['daily_start' => '09:00:00', 'daily_end' => '11:00:00']);
        $this->assertSame([], Ads::eligibleFor($room, $noon), 'outside the daily window');
        $this->assertCount(1, Ads::eligibleFor($room, strtotime(date('Y-m-d 10:30:00'))));
        DB::update('ad_campaigns', ['daily_start' => '20:00:00', 'daily_end' => '02:00:00'], 'id = :id', ['id' => $c]);
        $this->assertCount(1, Ads::eligibleFor($room, strtotime(date('Y-m-d 01:30:00'))), 'overnight window after midnight');
        $this->assertCount(1, Ads::eligibleFor($room, strtotime(date('Y-m-d 21:00:00'))));
        $this->assertSame([], Ads::eligibleFor($room, $noon));
        DB::update('ad_campaigns', ['daily_start' => null, 'daily_end' => null, 'end_date' => date('Y-m-d', strtotime('-1 day'))], 'id = :id', ['id' => $c]);
        $this->assertSame([], Ads::eligibleFor($room, $noon), 'ended');
        DB::update('ad_campaigns', ['end_date' => null, 'start_date' => date('Y-m-d', strtotime('+1 day'))], 'id = :id', ['id' => $c]);
        $this->assertSame([], Ads::eligibleFor($room, $noon), 'not started');
        DB::update('ad_campaigns', ['start_date' => date('Y-m-d'), 'status' => 'paused'], 'id = :id', ['id' => $c]);
        $this->assertSame([], Ads::eligibleFor($room, $noon), 'paused');
        DB::update('ad_campaigns', ['status' => 'active'], 'id = :id', ['id' => $c]);
        DB::update('content_items', ['is_active' => 0], 'id = :id', ['id' => self::$id['ad']]);
        try {
            $this->assertSame([], Ads::eligibleFor($room, $noon), 'inactive ad content');
        } finally {
            DB::update('content_items', ['is_active' => 1], 'id = :id', ['id' => self::$id['ad']]);
        }
        $this->assertCount(1, Ads::eligibleFor($room, $noon));
    }

    public function testTargeting(): void
    {
        $byRoom = self::campaign(['target_type' => 'rooms', 'target_ids' => json_out([self::$id['room101']])]);
        $byFloor = self::campaign(['target_type' => 'floors', 'target_ids' => json_out(['2']), 'content_id' => self::$id['ad2']]);
        $byGroup = self::campaign(['target_type' => 'groups', 'target_ids' => json_out([self::$id['group']])]);
        $ids = fn (string $r) => array_map(fn ($c) => (int) $c['id'], Ads::eligibleFor(self::room($r)));
        $this->assertSame([$byRoom], $ids('101'));
        $this->assertSame([], $ids('102'));
        $this->assertSame([$byFloor], $ids('201'));
        $this->assertEqualsCanonicalizing([$byFloor, $byGroup], $ids('202'));
    }

    public function testNeverDuringEmergencyOffSuspendedOrWelcomeScreen(): void
    {
        self::campaign();
        $room = self::room('101');
        $hasAds = fn (array $c) => (bool) array_filter($c['items'], fn ($i) => isset($i['ad_campaign_id']));
        $this->assertTrue($hasAds(ContentResolver::build($room)));

        $em = Broadcaster::emergencyStart('Fire drill', 'Please stay calm', 'all', []);
        try {
            $c = ContentResolver::build($room);
            $this->assertSame('emergency', $c['mode']);
            $this->assertFalse($hasAds($c));
        } finally {
            Broadcaster::emergencyStop($em);
        }
        DB::update('rooms', ['is_enabled' => 0], 'id = :id', ['id' => $room['id']]);
        $c = ContentResolver::build(self::room('101'));
        DB::update('rooms', ['is_enabled' => 1], 'id = :id', ['id' => $room['id']]);
        $this->assertSame('off', $c['mode']);
        $this->assertFalse($hasAds($c));

        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        try {
            $c = ContentResolver::build($room);
            $this->assertSame('suspended', $c['mode']);
            $this->assertSame([], $c['items']);
        } finally {
            DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
            Tenant::forget();
        }
        // Empty room (welcome screen) gets no ads.
        $empty = DB::insert('rooms', ['room_number' => '999', 'floor' => '9']);
        $c = ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $empty]));
        DB::delete('rooms', 'id = :id', ['id' => $empty]);
        $this->assertSame('empty', $c['mode']);
        $this->assertSame([], $c['items']);
        // Explicit modes that never get ads.
        foreach (['emergency', 'off', 'suspended', 'empty', 'preview'] as $mode) {
            $content = ['mode' => $mode, 'screen_on' => true, 'playlist' => null, 'items' => [['id' => 1, 'type' => 'image', 'duration' => 0]]];
            Ads::apply($content, $room);
            $this->assertCount(1, $content['items'], $mode);
        }
        $content = ['mode' => 'assigned', 'screen_on' => true, 'playlist' => null, 'items' => [['id' => 1, 'type' => 'image', 'duration' => 0]]];
        Ads::apply($content, $room);
        $this->assertCount(2, $content['items']);
        Ads::strip($content);
        $this->assertCount(1, $content['items']);
        $this->assertArrayNotHasKey('ads', $content);
    }

    public function testDailyCapPerTvAndPriorityAndMaxAdsPerBreak(): void
    {
        $room = self::room('101');
        $capped = self::campaign(['max_per_day' => 2, 'priority' => 1]);
        $devA = DB::insert('devices', ['device_uid' => 'cap-tv-a-0001', 'room_id' => $room['id'], 'token_hash' => str_repeat('a', 64)]);
        $devB = DB::insert('devices', ['device_uid' => 'cap-tv-b-0001', 'room_id' => $room['id'], 'token_hash' => str_repeat('b', 64)]);
        $log = fn (int $dev, ?string $at = null) => DB::insert('broadcast_logs', ['device_id' => $dev, 'room_id' => $room['id'], 'content_id' => self::$id['ad'], 'ad_campaign_id' => $capped,
            'event' => 'played', 'duration_sec' => 15, 'created_at' => $at ?? now()]);
        $log($devA);
        $log($devB);
        $log($devA, date('Y-m-d 23:00:00', strtotime('-1 day'))); // yesterday does not count
        $this->assertCount(1, Ads::eligibleFor($room), 'each TV played it once');
        $this->assertSame([$capped => 1], Ads::impressionsToday([$capped], (int) $room['id']));
        $log($devA);
        $this->assertSame([], Ads::eligibleFor($room), 'busiest TV reached the cap');
        $this->assertCount(1, Ads::eligibleFor(self::room('102')), 'other rooms unaffected');

        // Priority & max ads per break: 4 campaigns due at the same time → 3 now (by priority), the 4th next break.
        DB::query('DELETE FROM ad_campaigns WHERE hotel_id = 1');
        $p = [];
        foreach ([3, 9, 1, 5] as $prio) {
            $p[$prio] = self::campaign(['priority' => $prio, 'freq_items' => 1]);
        }
        $out = Ads::insertIntoPlaylist([['id' => 1, 'type' => 'image', 'duration' => 10], ['id' => 2, 'type' => 'image', 'duration' => 10]], Ads::liveCampaigns());
        $seq = array_map(fn ($i) => $i['ad_campaign_id'] ?? 'i', $out);
        $this->assertSame(['i', $p[9], $p[5], $p[3], 'i', $p[9], $p[5], $p[3], $p[1]], $seq);
        DB::query('DELETE FROM devices WHERE id IN (' . $devA . ',' . $devB . ')');
    }

    public function testFeatureFlagDisablesAds(): void
    {
        self::campaign();
        $plan = DB::insert('plans', ['name' => 'Basic no ads', 'price_per_tv_month' => 100, 'features' => json_out(['guests'])]);
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = 1', ['p' => $plan]);
        Tenant::forget();
        try {
            $c = ContentResolver::build(self::room('101'));
            $this->assertSame([], array_filter($c['items'], fn ($i) => isset($i['ad_campaign_id'])));
            $s = new AdminSession(self::$url, 'adMgr');
            [$code] = $s->get('ads.php');
            $this->assertSame(403, $code);
            [$code] = $s->get('analytics.php');
            $this->assertSame(403, $code);
            [, , $html] = $s->get('index.php');
            $this->assertStringNotContainsString('ads.php', $html);
            $this->assertStringNotContainsString('hcAnalyticsWidget', $html);
        } finally {
            DB::query('UPDATE hotels SET plan_id = NULL WHERE id = 1');
            DB::query('DELETE FROM plans WHERE id = :p', ['p' => $plan]);
            Tenant::forget();
        }
    }

    // ------------------------------------------------------------------ device API

    public function testAdsReachTheTvAndImpressionsAreLogged(): void
    {
        $c = self::campaign(['freq_items' => 2]);
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'ads-tv-101-0001', 'room_number' => '101', 'registration_key' => self::$key]);
        $this->assertSame(200, $st, (string) json_encode($j));
        $h = ['Authorization: Bearer ' . $j['data']['token'], 'X-Device-Id: ads-tv-101-0001'];
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, $h);
        $this->assertSame(200, $st);
        $ads = array_values(array_filter($j['data']['content']['items'], fn ($i) => isset($i['ad_campaign_id'])));
        $this->assertCount(2, $ads);
        $this->assertSame($c, $ads[0]['ad_campaign_id']);

        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/played', ['items' => [
            ['content_id' => self::$id['item1'], 'started_at' => date('c', time() - 60), 'duration_sec' => 10],
            ['content_id' => self::$id['ad'], 'started_at' => date('c', time() - 50), 'duration_sec' => 15, 'ad_campaign_id' => $c],
            ['content_id' => self::$id['ad'], 'started_at' => date('c', time() - 30), 'duration_sec' => 14, 'ad_campaign_id' => (string) $c],
            ['content_id' => self::$id['ad'], 'duration_sec' => 15, 'ad_campaign_id' => self::$id['h2campaign']], // other hotel's campaign → not counted as ad
            ['content_id' => self::$id['ad'], 'duration_sec' => 15, 'ad_campaign_id' => 'x; DROP TABLE x'],
        ]], $h);
        $this->assertSame(200, $st);
        $this->assertSame(5, $j['data']['saved']);
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = 1 AND ad_campaign_id = :c', ['c' => $c]));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM broadcast_logs WHERE ad_campaign_id = :c', ['c' => self::$id['h2campaign']]));
        $this->assertSame(3, (int) DB::value('SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = 1 AND event = \'played\' AND ad_campaign_id IS NULL'), 'foreign / invalid ids are stored as normal plays');
        $this->assertSame(2, Ads::impressionsTodayTotal());
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ sponsor report

    public function testSponsorReportNumbersAndRollupSurvivesRetention(): void
    {
        $c1 = self::campaign(['name' => 'Diwali offer']);
        $c2 = self::campaign(['name' => 'Taxi', 'content_id' => self::$id['ad2']]);
        $d2 = date('Y-m-d', strtotime('-2 days'));
        $d1 = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $old = date('Y-m-d', strtotime('-20 days'));
        $seed = function (int $c, string $day, string $room, int $n, int $sec) {
            for ($i = 0; $i < $n; $i++) {
                DB::insert('broadcast_logs', ['room_id' => self::$id['room' . $room], 'content_id' => self::$id['ad'], 'ad_campaign_id' => $c, 'event' => 'played',
                    'duration_sec' => $sec, 'created_at' => $day . ' 1' . ($i % 10) . ':00:00']);
            }
        };
        $seed($c1, $d2, '101', 3, 15);
        $seed($c1, $d2, '102', 2, 15);
        $seed($c1, $d1, '101', 4, 10);
        $seed($c2, $d1, '201', 1, 8);
        $seed($c1, $today, '202', 2, 15);
        $seed($c1, $old, '101', 6, 15);
        // Noise: normal plays and another hotel are ignored.
        DB::insert('broadcast_logs', ['room_id' => self::$id['room101'], 'content_id' => self::$id['item1'], 'event' => 'played', 'duration_sec' => 10, 'created_at' => $d1 . ' 09:00:00']);

        $r = Ads::report([$c1, $c2], $d2, $today);
        $this->assertSame(['impressions' => 12, 'seconds' => 3 * 15 + 2 * 15 + 4 * 10 + 8 + 2 * 15, 'rooms' => 4, 'days_active' => 3], $r['totals']);
        $this->assertSame([
            ['day' => $d2, 'impressions' => 5, 'seconds' => 75, 'rooms' => 2],
            ['day' => $d1, 'impressions' => 5, 'seconds' => 48, 'rooms' => 2],
            ['day' => $today, 'impressions' => 2, 'seconds' => 30, 'rooms' => 1],
        ], $r['per_day']);
        $this->assertSame(['101', '102', '202', '201'], array_column($r['per_room'], 'room'));
        $this->assertSame(7, $r['per_room'][0]['impressions']);
        $byName = array_column($r['per_campaign'], null, 'name');
        $this->assertSame(11, $byName['Diwali offer']['impressions']);
        $this->assertSame(1, $byName['Taxi']['rooms']);
        $this->assertSame(1, Ads::report([$c2], $d2, $today)['totals']['impressions']);
        $this->assertSame(0, Ads::report([], $d2, $today)['totals']['impressions']);

        // Old days: rolled up while raw logs exist, then raw logs are purged by retention → same numbers.
        $before = Ads::report([$c1], $old, $old)['totals'];
        $this->assertSame(6, $before['impressions']);
        Settings::set('log_retention_days', '7');
        DB::query('DELETE FROM broadcast_logs WHERE hotel_id = 1 AND created_at < :c', ['c' => date('Y-m-d', strtotime('-7 days'))]);
        try {
            $this->assertSame($before, Ads::report([$c1], $old, $old)['totals']);
            $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = 1 AND created_at < :c', ['c' => $d2]));
        } finally {
            Settings::set('log_retention_days', '90');
        }
        // Roll-up task is idempotent.
        Settings::setPlatform('task_last_AdsRollupTask', '0');
        $res = Scheduler::runTasks();
        $this->assertArrayHasKey('AdsRollupTask', $res);
        $this->assertSame(12, Ads::report([$c1, $c2], $d2, $today)['totals']['impressions']);

        // Admin pages: report, CSV, printable view.
        $s = new AdminSession(self::$url, 'adMgr');
        [$code, , $html] = $s->get('sponsor_report.php?sponsor_id=' . self::$id['sponsor'] . '&from=' . $d2 . '&to=' . $today);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('chart.umd.min.js', $html);
        $this->assertMatchesRegularExpression('/data-kpi>12</', $html);
        [$code, , $csv, $head] = $s->get('sponsor_report.php?campaign_id=' . $c1 . '&from=' . $d2 . '&to=' . $today . '&csv=day');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('text/csv', $head);
        $this->assertStringContainsString($d1 . ',4,40,1', $csv);
        [$code, , $csv] = $s->get('sponsor_report.php?sponsor_id=' . self::$id['sponsor'] . '&from=' . $d2 . '&to=' . $today . '&csv=room');
        $this->assertStringContainsString('101,7,85', $csv);
        [$code, , $html] = $s->get('sponsor_report.php?sponsor_id=' . self::$id['sponsor'] . '&from=' . $d2 . '&to=' . $today . '&print=1');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Advertising report', $html);
        $this->assertStringContainsString('Krishna Sweets', $html);
        $this->assertStringNotContainsString('hc-sidebar', $html, 'printable page without admin chrome');
    }

    // ------------------------------------------------------------------ admin CRUD, permissions, isolation

    public function testAdminCrudValidationAndPermissions(): void
    {
        $s = new AdminSession(self::$url, 'adMgr');
        [$code, , $html] = $s->get('ads.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $s->post('ads.php', ['op' => 'sponsor_save', 'id' => 0, 'name' => 'Dwarka Taxi Co', 'phone' => '02892 123456', 'email' => 'taxi@x.test', 'contract_start' => date('Y-m-d'), 'contract_end' => '']);
        $sp = (int) DB::value("SELECT id FROM sponsors WHERE hotel_id = 1 AND name = 'Dwarka Taxi Co'");
        $this->assertGreaterThan(0, $sp);
        $s->post('ads.php', ['op' => 'sponsor_save', 'id' => 0, 'name' => '', 'email' => 'bad']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM sponsors WHERE email = 'bad'"));

        [$code, , $html] = $s->get('ads.php?action=campaign');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('data-target-picker', $html);
        $s->post('ads.php', ['op' => 'campaign_save', 'id' => 0, 'name' => 'Taxi promo', 'sponsor_id' => $sp, 'content_id' => self::$id['ad2'],
            'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+30 days')), 'daily_start' => '08:00', 'daily_end' => '22:00',
            'target_type' => 'floors', 'floors' => ['1'], 'freq_type' => 'minutes', 'freq_items' => 4, 'freq_minutes' => 15, 'max_per_day' => 20, 'priority' => 2, 'status' => 'active']);
        $c = DB::one("SELECT * FROM ad_campaigns WHERE hotel_id = 1 AND name = 'Taxi promo'");
        $this->assertNotNull($c);
        $this->assertNull($c['freq_items']);
        $this->assertSame(15, (int) $c['freq_minutes']);
        $this->assertSame('08:00:00', $c['daily_start']);
        $this->assertSame('floors', $c['target_type']);
        $this->assertSame(['1'], json_decode((string) $c['target_ids'], true));
        $this->assertSame(20, (int) $c['max_per_day']);
        [, , $html] = $s->get('ads.php');
        $this->assertStringContainsString('Taxi promo', $html);
        $s->post('ads.php', ['op' => 'campaign_status', 'id' => $c['id']]);
        $this->assertSame('paused', DB::value('SELECT status FROM ad_campaigns WHERE id = :id', ['id' => $c['id']]));
        // Invalid: end before start, one daily time only.
        $s->post('ads.php', ['op' => 'campaign_save', 'id' => 0, 'name' => 'Bad', 'sponsor_id' => $sp, 'content_id' => self::$id['ad2'], 'start_date' => '2026-05-10', 'end_date' => '2026-05-01', 'daily_start' => '08:00']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM ad_campaigns WHERE name = 'Bad'"));
        $s->post('ads.php', ['op' => 'campaign_delete', 'id' => $c['id']]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM ad_campaigns WHERE id = :id', ['id' => $c['id']]));
        $s->post('ads.php', ['op' => 'sponsor_delete', 'id' => $sp]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM sponsors WHERE id = :id', ['id' => $sp]));
        $this->assertSame('', TestEnv::phpErrors());

        foreach (['adStaff', 'adRecep'] as $u) {
            $low = new AdminSession(self::$url, $u);
            foreach (['ads.php', 'analytics.php', 'sponsor_report.php?sponsor_id=' . self::$id['sponsor'], 'templates.php'] as $page) {
                [$code] = $low->get($page);
                $this->assertSame(403, $code, "$u $page");
            }
            [$code] = $low->post('ads.php', ['op' => 'sponsor_save', 'name' => 'Sneaky']);
            $this->assertSame(403, $code);
            [, , $html] = $low->get('index.php');
            $this->assertStringNotContainsString('hcAnalyticsWidget', $html, $u . ' sees no analytics widget');
        }
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM sponsors WHERE name = 'Sneaky'"));
    }

    public function testCrossHotelIsolation(): void
    {
        $c1 = self::campaign(['name' => 'H1 secret campaign']);
        $s2 = new AdminSession(self::$url, 'adMgr2');
        [$code, , $html] = $s2->get('ads.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('H2 campaign', $html);
        $this->assertStringNotContainsString('H1 secret campaign', $html);
        $this->assertStringNotContainsString('Krishna Sweets', $html);
        foreach (['ads.php?action=campaign&id=' . $c1, 'ads.php?action=sponsor&id=' . self::$id['sponsor'], 'sponsor_report.php?campaign_id=' . $c1, 'sponsor_report.php?sponsor_id=' . self::$id['sponsor']] as $page) {
            [$code] = $s2->get($page);
            $this->assertSame(404, $code, $page);
        }
        foreach ([['op' => 'campaign_delete', 'id' => $c1], ['op' => 'campaign_status', 'id' => $c1], ['op' => 'sponsor_delete', 'id' => self::$id['sponsor']],
            ['op' => 'campaign_save', 'id' => $c1, 'name' => 'HACK', 'sponsor_id' => self::$id['h2sponsor'], 'content_id' => self::$id['h2content']],
            ['op' => 'campaign_save', 'id' => 0, 'name' => 'Steal content', 'sponsor_id' => self::$id['h2sponsor'], 'content_id' => self::$id['ad']],
            ['op' => 'campaign_save', 'id' => 0, 'name' => 'Steal sponsor', 'sponsor_id' => self::$id['sponsor'], 'content_id' => self::$id['h2content']],
            ['op' => 'campaign_save', 'id' => 0, 'name' => 'Steal rooms', 'sponsor_id' => self::$id['h2sponsor'], 'content_id' => self::$id['h2content'], 'target_type' => 'rooms', 'room_ids' => [self::$id['room101']]]] as $post) {
            [$code] = $s2->post('ads.php', $post);
            $this->assertSame(404, $code, (string) json_encode($post));
        }
        $row = DB::one('SELECT * FROM ad_campaigns WHERE id = :id', ['id' => $c1]);
        $this->assertSame('H1 secret campaign', $row['name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM ad_campaigns WHERE name LIKE 'Steal%'"));
        $this->assertNotNull(DB::one('SELECT id FROM sponsors WHERE id = :id', ['id' => self::$id['sponsor']]));

        // TVs of hotel 2 only get hotel 2 ads; hotel 1 rooms never get hotel 2 ads.
        $h2 = Tenant::run(2, fn () => ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['h2room']])));
        $this->assertSame([self::$id['h2campaign']], $h2['ads']);
        $h1 = ContentResolver::build(self::room('101'));
        $this->assertSame([$c1], $h1['ads']);
        $this->assertSame([], Tenant::run(2, fn () => array_filter(Ads::campaigns(), fn ($c) => (int) $c['hotel_id'] !== 2)));
        // Analytics of hotel 2 never count hotel 1 plays.
        DB::insert('broadcast_logs', ['room_id' => self::$id['room101'], 'content_id' => self::$id['item1'], 'event' => 'played', 'duration_sec' => 10, 'created_at' => now()]);
        $this->assertSame(0, Tenant::run(2, fn () => Analytics::today()['plays']));
        $this->assertSame(1, Analytics::today()['plays']);
        [$code, , $csv] = $s2->get('analytics.php?csv=content');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('Item 1', $csv);
    }

    // ------------------------------------------------------------------ analytics

    public function testAnalyticsMathOnSeededData(): void
    {
        $d = date('Y-m-d', strtotime('-3 days'));
        $prev = date('Y-m-d', strtotime($d . ' -1 day'));
        Settings::set('tv_watts', '80');
        $dev = fn (string $uid, string $room, string $registered) => DB::insert('devices', ['device_uid' => $uid, 'room_id' => self::$id['room' . $room], 'token_hash' => hash('sha256', $uid),
            'status' => 'offline', 'last_ping' => $d . ' 18:00:00', 'registered_at' => $registered, 'model' => 'TV-' . $room]);
        $st = fn (int $device, string $status, string $at) => DB::insert('device_status_logs', ['device_id' => $device, 'room_id' => null, 'status' => $status, 'created_at' => $at]);
        $a = $dev('an-tv-a-000001', '101', $d . ' 00:00:00');
        $st($a, 'offline', $prev . ' 22:00:00');
        $st($a, 'online', $d . ' 06:00:00');
        $st($a, 'offline', $d . ' 18:00:00');
        $b = $dev('an-tv-b-000001', '102', $d . ' 12:00:00');
        $st($b, 'online', $d . ' 12:00:00');
        $st($b, 'offline', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:30:00');
        $c = $dev('an-tv-c-000001', '201', $d . ' 00:00:00');
        $st($c, 'offline', $d . ' 08:00:00'); // no earlier history → it was online before
        DB::insert('tv_usage_daily', ['device_id' => $a, 'day' => $d, 'online_min' => 700, 'screen_on_min' => 600, 'samples' => 140, 'last_sample_at' => $d . ' 18:00:00']);
        try {
            $tvs = array_column(Analytics::publicTvRows(Analytics::byTv($d, $d)), null, 'room');
            $this->assertSame(50.0, $tvs['101']['uptime']);
            $this->assertSame(12.0, $tvs['101']['online_hours']);
            $this->assertSame(10.0, $tvs['101']['hours_on'], 'hours ON from screen samples');
            $this->assertSame(0.8, $tvs['101']['kwh']);
            $this->assertSame('samples', $tvs['101']['method']);
            $this->assertSame(100.0, $tvs['102']['uptime'], 'period starts at registration');
            $this->assertSame(12.0, $tvs['102']['period_hours']);
            $this->assertSame(12.0, $tvs['102']['hours_on'], 'no samples → online hours');
            $this->assertSame(0.96, $tvs['102']['kwh']);
            $this->assertSame('status', $tvs['102']['method']);
            $this->assertSame(33.3, $tvs['201']['uptime']);
            $sum = Analytics::tvSummary(Analytics::byTv($d, $d));
            $this->assertSame(round(100 * (12 + 12 + 8) / (24 + 12 + 24), 1), $sum['uptime']);
            $this->assertSame(round(0.8 + 0.96 + 8 * 0.08, 2), $sum['kwh']);

            // Plays.
            foreach ([['101', 'item1', 10, null], ['101', 'item1', 10, null], ['102', 'item1', 10, null], ['101', 'item2', 20, null], ['102', 'ad', 15, 1], ['201', 'ad', 15, 1]] as [$room, $item, $sec, $ad]) {
                DB::insert('broadcast_logs', ['room_id' => self::$id['room' . $room], 'device_id' => $a, 'content_id' => self::$id[$item], 'event' => 'played', 'duration_sec' => $sec,
                    'ad_campaign_id' => $ad ? self::campaign() : null, 'created_at' => $d . ' 10:00:00']);
            }
            DB::insert('broadcast_logs', ['room_id' => self::$id['room101'], 'content_id' => self::$id['item1'], 'event' => 'queued', 'created_at' => $d . ' 10:00:00']);
            $days = Analytics::byDay($d, $d);
            $this->assertSame([['day' => $d, 'plays' => 6, 'seconds' => 80, 'ad_impressions' => 2, 'rooms' => 3]], $days);
            $content = array_column(Analytics::byContent($d, $d), null, 'title');
            $this->assertSame(3, $content['Item 1']['plays']);
            $this->assertSame(30, $content['Item 1']['seconds']);
            $this->assertSame(2, $content['Item 1']['rooms']);
            $this->assertSame(2, $content['Sweets ad']['ad_plays']);
            $this->assertSame('Item 1', Analytics::byContent($d, $d)[0]['title'], 'most played first');
            $rooms = array_column(Analytics::byRoom($d, $d), null, 'room');
            $this->assertSame(['room_id' => self::$id['room101'], 'room' => '101', 'floor' => '1', 'plays' => 3, 'seconds' => 40, 'items' => 2], $rooms['101']);
            $this->assertSame(0, $rooms['202']['plays']);

            // Occupancy & guest services (guests module tables, when installed).
            if (Analytics::tableExists('guest_stays')) {
                DB::query('INSERT INTO guest_stays (hotel_id, room_id, guest_name, checkin_at, checked_out_at) VALUES (1, :r, :n, :i, :o)',
                    ['r' => self::$id['room101'], 'n' => 'Mr. Shah', 'i' => $prev . ' 14:00:00', 'o' => date('Y-m-d', strtotime($d . ' +1 day')) . ' 10:00:00']);
                DB::query('INSERT INTO guest_stays (hotel_id, room_id, guest_name, checkin_at) VALUES (1, :r, :n, :i)', ['r' => self::$id['room102'], 'n' => 'Late', 'i' => $d . ' 20:00:00']);
                $occ = Analytics::occupancy($d, $d);
                $this->assertSame(1, $occ['days'][0]['occupied']);
                $this->assertSame(25.0, $occ['days'][0]['percent']);
                $occ2 = Analytics::occupancy($d, date('Y-m-d', strtotime($d . ' +1 day')));
                $this->assertSame(1, $occ2['days'][1]['occupied'], 'late check-in counts the next night only');
            }
            if (Analytics::tableExists('guest_orders')) {
                foreach ([[30, 'delivered', 500], [50, 'delivered', 300], [null, 'cancelled', 200]] as [$min, $status, $total]) {
                    DB::query('INSERT INTO guest_orders (hotel_id, room_id, status, total, created_at, delivered_at) VALUES (1, :r, :s, :t, :c, :dl)',
                        ['r' => self::$id['room101'], 's' => $status, 't' => $total, 'c' => $d . ' 10:00:00', 'dl' => $min ? date('Y-m-d H:i:s', strtotime($d . ' 10:00:00') + $min * 60) : null]);
                }
                DB::query("INSERT INTO guest_orders (hotel_id, status, total, created_at) VALUES (2, 'new', 999, :c)", ['c' => $d . ' 10:00:00']);
                foreach (['Water', 'Water', 'Towels'] as $type) {
                    DB::query("INSERT INTO guest_requests (hotel_id, type_name, status, created_at) VALUES (1, :t, 'open', :c)", ['t' => $type, 'c' => $d . ' 11:00:00']);
                }
                DB::query('INSERT INTO guest_feedback (hotel_id, rating, token_hash, created_at) VALUES (1, 4, :t1, :c), (1, 5, :t2, :c2)', ['t1' => 'a', 't2' => 'b', 'c' => $d . ' 12:00:00', 'c2' => $d . ' 12:00:00']);
                $gs = Analytics::guestServices($d, $d);
                $this->assertSame(3, $gs['orders']['count']);
                $this->assertSame(40.0, $gs['orders']['avg_delivery_min']);
                $this->assertSame(800.0, $gs['orders']['revenue']);
                $this->assertSame([['type' => 'Water', 'count' => 2, 'done' => 0, 'avg_done_min' => null], ['type' => 'Towels', 'count' => 1, 'done' => 0, 'avg_done_min' => null]], $gs['requests']);
                $this->assertSame(4.5, $gs['feedback']['average']);
            }

            // Page + CSV exports.
            $s = new AdminSession(self::$url, 'adMgr');
            [$code, , $html] = $s->get('analytics.php?from=' . $d . '&to=' . $d);
            $this->assertSame(200, $code);
            $this->assertFalse(TestEnv::hasPhpError($html));
            $this->assertStringContainsString('chart.umd.min.js', $html);
            $this->assertMatchesRegularExpression('/data-kpi="plays">6</', $html);
            $this->assertStringContainsString('How these numbers are calculated', $html);
            if (Analytics::tableExists('guest_stays')) {
                $this->assertStringContainsString('data-kpi="occupancy">25 %', $html);
            }
            [$code, , $csv, $head] = $s->get('analytics.php?from=' . $d . '&to=' . $d . '&csv=tvs');
            $this->assertStringContainsString('text/csv', $head);
            $this->assertStringContainsString('101,TV-101,offline,24,12,50,10,0.8,samples', $csv);
            foreach (['days', 'content', 'rooms', 'occupancy', 'requests'] as $t) {
                [$code, , , $head] = $s->get('analytics.php?from=' . $d . '&to=' . $d . '&csv=' . $t);
                $this->assertSame(200, $code, $t);
                $this->assertStringContainsString('text/csv', $head, $t);
            }
            $s->post('analytics.php', ['op' => 'watts', 'tv_watts' => 120]);
            Settings::flush();
            $this->assertSame(120, Analytics::watts());
            $s->post('analytics.php', ['op' => 'watts', 'tv_watts' => 99999]);
            Settings::flush();
            $this->assertSame(120, Analytics::watts());
            [, , $html] = $s->get('index.php');
            $this->assertStringContainsString('hcAnalyticsWidget', $html);
            $this->assertSame('', TestEnv::phpErrors());
        } finally {
            DB::query('DELETE FROM device_status_logs WHERE device_id IN (' . $a . ',' . $b . ',' . $c . ')');
            DB::query('DELETE FROM devices WHERE id IN (' . $a . ',' . $b . ',' . $c . ')');
            DB::query('DELETE FROM tv_usage_daily');
            Settings::set('tv_watts', '100');
        }
    }

    public function testRangeHelperAndUsageSampling(): void
    {
        $this->assertSame([date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], Analytics::range(null, null));
        $this->assertSame(['2026-01-01', '2026-01-31'], Analytics::range('2026-01-31', '2026-01-01'));
        $this->assertSame(['2025-01-02', '2026-01-02'], Analytics::range('2020-01-01', '2026-01-02'), 'max one year');
        $this->assertSame([date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], Analytics::range('bad', "x' OR 1=1"));

        $now = time();
        $on = DB::insert('devices', ['device_uid' => 'smp-tv-on-0001', 'room_id' => self::$id['room101'], 'token_hash' => str_repeat('c', 64), 'status' => 'online',
            'last_ping' => date('Y-m-d H:i:s', $now), 'last_heartbeat' => date('Y-m-d H:i:s', $now), 'screen_on' => 1]);
        $standby = DB::insert('devices', ['device_uid' => 'smp-tv-sb-0001', 'room_id' => self::$id['room102'], 'token_hash' => str_repeat('d', 64), 'status' => 'online',
            'last_ping' => date('Y-m-d H:i:s', $now), 'last_heartbeat' => date('Y-m-d H:i:s', $now), 'screen_on' => 0]);
        $off = DB::insert('devices', ['device_uid' => 'smp-tv-of-0001', 'room_id' => self::$id['room201'], 'token_hash' => str_repeat('e', 64), 'status' => 'offline',
            'last_ping' => date('Y-m-d H:i:s', $now - 3600)]);
        try {
            Analytics::sampleUsage($now);
            DB::update('devices', ['last_heartbeat' => date('Y-m-d H:i:s', $now + 290)], 'id = :id', ['id' => $standby]);
            Analytics::sampleUsage($now + 300);
            $row = fn (int $d) => DB::one('SELECT online_min, screen_on_min, samples FROM tv_usage_daily WHERE device_id = :d AND day = :day', ['d' => $d, 'day' => date('Y-m-d', $now)]);
            $this->assertSame(['online_min' => 10, 'screen_on_min' => 10, 'samples' => 2], array_map('intval', $row($on)));
            $this->assertSame(['online_min' => 10, 'screen_on_min' => 0, 'samples' => 2], array_map('intval', $row($standby)));
            $this->assertSame(['online_min' => 0, 'screen_on_min' => 0, 'samples' => 2], array_map('intval', $row($off)));
            // A long gap is capped at 15 minutes.
            Analytics::sampleUsage($now + 300 + 7200);
            if (date('Y-m-d', $now + 300 + 7200) === date('Y-m-d', $now)) {
                // (Near midnight the 2-hour-later sample lands on the next day's row, so only check it on the same day.)
                $this->assertSame(25, (int) $row($on)['online_min']);
            }
            $this->assertSame(1, (int) DB::value('SELECT hotel_id FROM tv_usage_daily WHERE device_id = :d LIMIT 1', ['d' => $on]));
            Settings::setPlatform('task_last_AnalyticsTask', '0');
            $this->assertArrayHasKey('AnalyticsTask', Scheduler::runTasks());
        } finally {
            DB::query('DELETE FROM tv_usage_daily');
            DB::query('DELETE FROM devices WHERE id IN (' . $on . ',' . $standby . ',' . $off . ')');
        }
    }
}
