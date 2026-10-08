<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scheduling (2.4, docs/modules/scheduling.md): calendar AJAX (#31), content approval workflow (#32),
 * content start / expiry dates (#33), playlist dayparting (#34), holiday calendar (#35), migration of
 * existing content, translations and "no PHP warnings" on the new pages for every role.
 */
final class SchedulingTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    /** @var array<string, AdminSession> */
    private static array $sessions = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['admin' => 'super_admin', 'mgr' => 'manager', 'staff' => 'staff', 'desk' => 'reception', 'mgrR' => 'manager'] as $u => $role) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => 'sc' . $u, 'email' => 'sc' . $u . '@t.test', 'full_name' => 'Sc ' . $u, 'password_hash' => $pw, 'role' => $role]);
        }
        foreach ([['101', '1'], ['102', '1'], ['201', '2']] as [$n, $f]) {
            self::$id['r' . $n] = DB::insert('rooms', ['room_number' => $n, 'name' => 'Room ' . $n, 'floor' => $f]);
        }
        self::$id['grp'] = DB::insert('room_groups', ['name' => 'Lobby', 'created_at' => now()]);
        DB::insert('room_group_members', ['group_id' => self::$id['grp'], 'room_id' => self::$id['r201']]);
        // Restricted manager: only room 101 (core/Access.php).
        DB::insert('user_access', ['hotel_id' => 1, 'user_id' => self::$id['mgrR'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);

        $c = static fn (array $row): int => DB::insert('content_items', $row + ['type' => 'announcement', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        self::$id['welcome'] = $c(['title' => 'Welcome', 'body' => 'Welcome text']);
        self::$id['breakfast'] = $c(['title' => 'Breakfast', 'body' => 'Breakfast 7-10']);
        self::$id['bar'] = $c(['title' => 'Bar', 'body' => 'Bar open']);
        self::$id['office'] = $c(['title' => 'Office hours', 'body' => 'Mon-Fri']);
        self::$id['diwali'] = $c(['title' => 'Diwali offer ' . self::XSS, 'body' => 'Happy Diwali']);

        Hotels::create(['name' => 'Other Hotel'], ['username' => 'scBoss2', 'email' => 'scb2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2content'] = DB::insert('content_items', ['title' => 'B-SECRET', 'type' => 'announcement', 'body' => 'x', 'duration' => 5, 'approval_status' => 'pending', 'created_at' => now()]);
            self::$id['h2sched'] = DB::insert('broadcast_commands', ['title' => 'B-SCHED', 'command' => 'SHOW_CONTENT', 'target_type' => 'all', 'target_ids' => '[]',
                'content_id' => self::$id['h2content'], 'mode' => 'once', 'status' => 'scheduled', 'start_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => now()]);
            self::$id['h2hol'] = DB::insert('holidays', ['name' => 'B-HOLIDAY', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'action' => 'tv_off', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
        });
        Tenant::set(1);
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        ContentRules::$now = null;
        self::$sessions = [];
        Tenant::forget();
        Settings::flush();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        ContentRules::$now = null;
    }

    protected function tearDown(): void
    {
        ContentRules::$now = null;
    }

    private static function as(string $u): AdminSession
    {
        return self::$sessions[$u] ??= new AdminSession(self::$url, 'sc' . $u);
    }

    private static function room(string $n): array
    {
        return DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['r' . $n]]);
    }

    private static function texts(array $content): array
    {
        return array_map(static fn ($i) => $i['title'], $content['items']);
    }

    private static function resetRooms(): void
    {
        DB::query('UPDATE rooms SET content_id = NULL, playlist_id = NULL, is_enabled = 1 WHERE hotel_id = 1');
        DB::query('UPDATE room_groups SET content_id = NULL, playlist_id = NULL WHERE hotel_id = 1');
        DB::query("UPDATE broadcast_commands SET status = 'cancelled' WHERE hotel_id = 1 AND status IN ('scheduled','active')");
        DB::query('DELETE FROM holidays WHERE hotel_id = 1');
        Settings::set('default_content_id', '');
        Settings::set('default_playlist_id', '');
        Settings::bumpContentVersion();
    }

    private static function playlist(string $name, array $items): int
    {
        $pid = DB::insert('content_playlists', ['name' => $name, 'transition' => 'fade', 'created_at' => now()]);
        foreach (array_values($items) as $i => $it) {
            [$cid, $from, $to, $days] = $it + [1 => null, 2 => null, 3 => null];
            DB::insert('playlist_items', ['playlist_id' => $pid, 'content_id' => $cid, 'sort_order' => $i, 'daypart_from' => $from, 'daypart_to' => $to, 'daypart_days' => $days]);
        }
        return $pid;
    }

    // ------------------------------------------------------------------ migration

    public function testMigrationMarksExistingContentApproved(): void
    {
        $pdo = DB::pdo();
        // Simulate a 2.3 database: no 022 columns, rows written by 2.3 code.
        $pdo->exec('ALTER TABLE content_items DROP INDEX idx_content_approval');
        foreach (['approval_status', 'approval_note', 'submitted_by', 'reviewed_by', 'reviewed_at', 'valid_from', 'valid_to', 'expiry_warned_for'] as $col) {
            $pdo->exec("ALTER TABLE content_items DROP COLUMN $col");
        }
        foreach (['daypart_from', 'daypart_to', 'daypart_days'] as $col) {
            $pdo->exec("ALTER TABLE playlist_items DROP COLUMN $col");
        }
        $pdo->exec('DROP TABLE content_revisions');
        $pdo->exec('DROP TABLE holidays');
        $old = DB::insert('content_items', ['title' => 'Old 2.3 item', 'type' => 'announcement', 'body' => 'old', 'duration' => 5, 'created_at' => now()]);
        DB::query("DELETE FROM schema_migrations WHERE filename = '022_scheduling.sql'");
        try {
            $done = Migrator::migrate();
        } finally {
            // Never leave the schema half-migrated for later tests.
            Migrator::migrate();
        }
        $this->assertContains('022_scheduling.sql', $done);
        $this->assertSame('approved', DB::value('SELECT approval_status FROM content_items WHERE id = :id', ['id' => $old]));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM content_items WHERE approval_status <> 'approved'"), 'every existing item is approved');
        foreach (['valid_from', 'valid_to', 'expiry_warned_for', 'approval_note'] as $col) {
            $this->assertTrue(Migrator::hasColumn($pdo, 'content_items', $col), $col);
        }
        $this->assertTrue(Migrator::hasColumn($pdo, 'playlist_items', 'daypart_days'));
        $this->assertTrue(Migrator::hasTable($pdo, 'holidays'));
        $this->assertTrue(Migrator::hasTable($pdo, 'content_revisions'));
        $this->assertSame(['hotel_id', 'approval_status'], Migrator::indexColumns($pdo, 'content_items', 'idx_content_approval'));
        // Idempotent: running the file again changes nothing and does not fail.
        DB::query("DELETE FROM schema_migrations WHERE filename = '022_scheduling.sql'");
        $this->assertContains('022_scheduling.sql', Migrator::migrate());
        $this->assertTrue(Tenant::isTenantTable('holidays') && Tenant::isTenantTable('content_revisions'));
        DB::delete('content_items', 'id = :id', ['id' => $old]);
        // The other hotel's fixtures were dropped with the columns / tables above: re-create them.
        Tenant::run(2, function (): void {
            DB::update('content_items', ['approval_status' => 'pending'], 'id = :id', ['id' => self::$id['h2content']]);
            self::$id['h2hol'] = DB::insert('holidays', ['name' => 'B-HOLIDAY', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'action' => 'tv_off', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
        });
    }

    // ------------------------------------------------------------------ #33 expiry / start dates

    public function testValidityWindowAndTimezoneEdges(): void
    {
        self::resetRooms();
        $id = DB::insert('content_items', ['title' => 'Navratri garba', 'type' => 'announcement', 'body' => 'Garba tonight', 'duration' => 10, 'is_active' => 1,
            'valid_from' => '2026-10-01 18:00:00', 'valid_to' => '2026-10-08 00:00:00', 'created_at' => now()]);
        $item = ContentManager::findOwn($id);
        // Hotel time Asia/Kolkata.
        $this->assertSame('Asia/Kolkata', date_default_timezone_get());
        $this->assertSame('scheduled', ContentRules::validity($item, strtotime('2026-10-01 17:59:59')));
        $this->assertSame('live', ContentRules::validity($item, strtotime('2026-10-01 18:00:00')));
        $this->assertSame('live', ContentRules::validity($item, strtotime('2026-10-07 23:59:59')));
        $this->assertSame('expired', ContentRules::validity($item, strtotime('2026-10-08 00:00:00')), 'valid_to is exclusive');

        // One instant: 2026-10-07 18:30 UTC = 00:00 in India (expired) = 14:30 in New York (still live).
        $instant = (int) gmmktime(18, 30, 0, 10, 7, 2026);
        $this->assertSame('expired', ContentRules::validity($item, $instant));
        Settings::set('timezone', 'America/New_York');
        Tenant::clear();
        Settings::flush();
        Tenant::set(1);
        try {
            $this->assertSame('America/New_York', date_default_timezone_get());
            $this->assertSame('live', ContentRules::validity($item, $instant), 'hotel time zone decides');
            // The resolver uses the same rule: assigned item still on air in New York.
            DB::update('rooms', ['content_id' => $id], 'id = :id', ['id' => self::$id['r101']]);
            ContentRules::$now = $instant;
            $this->assertSame('assigned', ContentResolver::build(self::room('101'))['mode']);
        } finally {
            Settings::set('timezone', 'Asia/Kolkata');
            Tenant::clear();
            Settings::flush();
            Tenant::set(1);
        }
        $this->assertSame('Asia/Kolkata', date_default_timezone_get());
        $c = ContentResolver::build(self::room('101'));
        $this->assertSame('empty', $c['mode'], 'expired item is skipped in India');

        // Playlists and layouts skip it too; the rest of the playlist keeps playing.
        $pid = self::playlist('Garba loop', [[$id], [self::$id['welcome']]]);
        DB::update('rooms', ['content_id' => null, 'playlist_id' => $pid], 'id = :id', ['id' => self::$id['r101']]);
        ContentRules::$now = strtotime('2026-10-05 20:00:00');
        $this->assertSame(['Navratri garba', 'Welcome'], self::texts(ContentResolver::build(self::room('101'))));
        ContentRules::$now = strtotime('2026-10-09 20:00:00');
        $this->assertSame(['Welcome'], self::texts(ContentResolver::build(self::room('101'))));
        $this->assertSame([], Layouts::zoneItems(['content_id' => $id]));
        ContentRules::$now = strtotime('2026-10-05 20:00:00');
        $this->assertCount(1, Layouts::zoneItems(['content_id' => $id]));

        // Content list: badges and filter.
        $past = DB::insert('content_items', ['title' => 'Old monsoon offer', 'type' => 'announcement', 'body' => 'x', 'duration' => 5, 'valid_to' => date('Y-m-d H:i:s', time() - 3600), 'created_at' => now()]);
        $future = DB::insert('content_items', ['title' => 'Winter menu', 'type' => 'announcement', 'body' => 'x', 'duration' => 5, 'valid_from' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => now()]);
        [$s, , $html] = self::as('mgr')->get('content.php?view=list');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Expired', $html);
        $this->assertStringContainsString('Scheduled', $html);
        [, , $html] = self::as('mgr')->get('content.php?state=expired');
        $this->assertStringContainsString('Old monsoon offer', $html);
        $this->assertStringNotContainsString('Winter menu', $html);
        [, , $html] = self::as('mgr')->get('content.php?state=scheduled');
        $this->assertStringContainsString('Winter menu', $html);
        $this->assertStringNotContainsString('Old monsoon offer', $html);
        [, , $html] = self::as('mgr')->get('content.php?state=live');
        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringNotContainsString('Winter menu', $html);

        // Saving dates through the form (validation: until after from).
        [$s] = self::as('mgr')->post('content.php', ['op' => 'save', 'id' => $future, 'title' => 'Winter menu', 'body' => 'x', 'duration' => 5, 'is_active' => 1,
            'valid_from' => '2026-12-01T00:00', 'valid_to' => '2026-11-01T00:00']);
        $this->assertSame(302, $s);
        $this->assertNotSame('2026-11-01 00:00:00', DB::value('SELECT valid_to FROM content_items WHERE id = :id', ['id' => $future]));
        self::as('mgr')->post('content.php', ['op' => 'save', 'id' => $future, 'title' => 'Winter menu', 'body' => 'x', 'duration' => 5, 'is_active' => 1,
            'valid_from' => '2026-12-01T00:00', 'valid_to' => '2027-02-28T23:59']);
        $row = DB::one('SELECT valid_from, valid_to FROM content_items WHERE id = :id', ['id' => $future]);
        $this->assertSame(['valid_from' => '2026-12-01 00:00:00', 'valid_to' => '2027-02-28 23:59:00'], $row);

        // "Expires soon" warning: once per item and date.
        ContentRules::$now = null;
        DB::query('UPDATE content_items SET expiry_warned_for = valid_to WHERE hotel_id = 1 AND valid_to IS NOT NULL');
        DB::update('content_items', ['valid_to' => date('Y-m-d H:i:s', time() + 86400)], 'id = :id', ['id' => self::$id['bar']]);
        $this->assertSame(1, ContentExpiryTask::forHotel());
        $this->assertSame(0, ContentExpiryTask::forHotel(), 'warned only once');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'content_expiry_warning' AND entity_id = :id", ['id' => self::$id['bar']]));
        DB::update('content_items', ['valid_to' => null], 'id = :id', ['id' => self::$id['bar']]);
    }

    // ------------------------------------------------------------------ #34 dayparting

    public function testPlaylistDaypartingOvernightAndHash(): void
    {
        self::resetRooms();
        // A: always · B: 22:00–02:00 on Wednesdays (overnight) · C: 09:00–17:00 Mon–Fri
        $pid = self::playlist('Daypart', [[self::$id['welcome']], [self::$id['bar'], '22:00:00', '02:00:00', '3'], [self::$id['office'], '09:00:00', '17:00:00', '1,2,3,4,5']]);
        DB::update('rooms', ['playlist_id' => $pid], 'id = :id', ['id' => self::$id['r101']]);
        $at = function (string $when) {
            ContentRules::$now = strtotime($when);
            return ContentResolver::build(self::room('101'));
        };
        // 2026-10-07 is a Wednesday.
        $this->assertSame(['Welcome', 'Office hours'], self::texts($at('2026-10-07 10:00')));
        $wedNight = $at('2026-10-07 23:00');
        $this->assertSame(['Welcome', 'Bar'], self::texts($wedNight));
        $this->assertSame(['Welcome', 'Bar'], self::texts($at('2026-10-08 01:30')), 'after midnight belongs to Wednesday');
        $this->assertSame(['Welcome'], self::texts($at('2026-10-08 02:00')), 'end is exclusive');
        $this->assertSame(['Welcome'], self::texts($at('2026-10-07 01:30')), 'Tuesday night is not Wednesday');
        $this->assertSame(['Welcome'], self::texts($at('2026-10-10 10:00')), 'Saturday: office item off');
        // The content hash changes when the active set changes (TVs refresh; cache keys are per minute).
        $this->assertNotSame($at('2026-10-07 10:00')['hash'], $wedNight['hash']);
        $this->assertSame($at('2026-10-07 10:05')['hash'], $at('2026-10-07 10:00')['hash']);

        // Nothing active → as if the playlist were empty: falls back to the group / default.
        $only = self::playlist('Office only', [[self::$id['office'], '09:00:00', '17:00:00', '1,2,3,4,5']]);
        DB::update('rooms', ['playlist_id' => $only], 'id = :id', ['id' => self::$id['r101']]);
        Settings::set('default_content_id', (string) self::$id['welcome']);
        $c = $at('2026-10-10 10:00');
        $this->assertSame('default', $c['mode']);
        $this->assertSame(['Welcome'], self::texts($c));
        $this->assertSame('assigned', $at('2026-10-09 10:00')['mode']);
        Settings::set('default_content_id', '');

        // Unit rules.
        $this->assertTrue(ContentRules::daypartActive(['daypart_days' => '6,7'], strtotime('2026-10-11 12:00')));
        $this->assertFalse(ContentRules::daypartActive(['daypart_days' => '6,7'], strtotime('2026-10-12 12:00')));
        $this->assertSame(['22:00:00', '02:00:00', '1,3', null], ContentRules::parseDaypart(['daypart_from' => '22:00', 'daypart_to' => '02:00', 'daypart_days' => ['3', '1', '9']]));
        $this->assertSame([null, null, null, null], ContentRules::parseDaypart(['daypart_days' => '1,2,3,4,5,6,7']), 'every day = no limit');
        $this->assertNotNull(ContentRules::parseDaypart(['daypart_from' => '10:00'])[3], 'one time only → error');

        // Playlist editor saves and shows the dayparts.
        [$s] = self::as('mgr')->post('playlists.php', ['op' => 'save', 'id' => $pid, 'name' => 'Daypart', 'transition' => 'fade',
            'items[0][content_id]' => self::$id['welcome'], 'items[1][content_id]' => self::$id['bar'], 'items[1][daypart_from]' => '22:00', 'items[1][daypart_to]' => '02:00',
            'items[1][daypart_days]' => '3,5', 'items[2][content_id]' => self::$id['office'], 'items[2][daypart_from]' => '09:00']);
        $this->assertSame(302, $s);
        $rows = DB::all('SELECT content_id, daypart_from, daypart_to, daypart_days FROM playlist_items WHERE playlist_id = :p ORDER BY sort_order', ['p' => $pid]);
        $this->assertSame([null, '22:00:00', null], array_column($rows, 'daypart_from'), 'half a window is dropped (with a warning)');
        $this->assertSame([null, '3,5', null], array_column($rows, 'daypart_days'));
        [$s, , $html] = self::as('mgr')->get('playlists.php?action=edit&id=' . $pid);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('"dp_from":"22:00"', $html);
        $this->assertFalse(TestEnv::hasPhpError($html));
    }

    // ------------------------------------------------------------------ #32 approvals

    public function testApprovalWorkflow(): void
    {
        self::resetRooms();
        Settings::set(Approvals::SETTING, '0');
        // Setting off = 2.3 behaviour: staff cannot add content, manager saves are approved.
        [$s] = self::as('staff')->get('content.php?action=new&type=announcement');
        $this->assertSame(403, $s);
        [$s] = self::as('desk')->get('content.php');
        $this->assertSame(403, $s);
        [$s] = self::as('staff')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Sneaky', 'body' => 'x', 'is_active' => 1]);
        $this->assertSame(403, $s);
        self::as('mgr')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Manager note', 'body' => 'x', 'is_active' => 1]);
        $this->assertSame('approved', DB::value("SELECT approval_status FROM content_items WHERE title = 'Manager note'"));

        // Admin switches approval on (settings.manage); a manager cannot.
        self::as('mgr')->post('approvals.php', ['op' => 'setting', 'require_approval' => '1']);
        $this->assertFalse(Settings::bool(Approvals::SETTING));
        [$s] = self::as('admin')->post('approvals.php', ['op' => 'setting', 'require_approval' => '1']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertTrue(Approvals::enabled());

        // Staff content → pending, never on a TV, cannot be pushed / scheduled / chosen.
        [$s, , $html] = self::as('staff')->get('content.php?action=new&type=announcement');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Send for approval', $html);
        [$s] = self::as('staff')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Staff promo ' . self::XSS, 'body' => 'Spa 20% off', 'is_active' => 1, 'duration' => 8]);
        $this->assertSame(302, $s);
        $staffItem = (int) DB::value("SELECT id FROM content_items WHERE body = 'Spa 20% off'");
        $row = ContentManager::findOwn($staffItem);
        $this->assertSame('pending', $row['approval_status']);
        $this->assertSame(self::$id['staff'], (int) $row['submitted_by']);
        // Reception may submit too while approval is on.
        [$s] = self::as('desk')->get('content.php?action=new&type=announcement');
        $this->assertSame(200, $s);
        self::as('desk')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Desk draft', 'body' => 'desk', 'is_active' => 1, 'draft' => '1']);
        $this->assertSame('draft', DB::value("SELECT approval_status FROM content_items WHERE title = 'Desk draft'"));

        DB::update('rooms', ['content_id' => $staffItem], 'id = :id', ['id' => self::$id['r101']]);
        $this->assertSame('empty', ContentResolver::build(self::room('101'))['mode'], 'assigned pending item is skipped');
        $pid = self::playlist('Mixed', [[$staffItem], [self::$id['welcome']]]);
        DB::update('rooms', ['content_id' => null, 'playlist_id' => $pid], 'id = :id', ['id' => self::$id['r101']]);
        $this->assertSame(['Welcome'], self::texts(ContentResolver::build(self::room('101'))));
        $this->assertSame([], Layouts::zoneItems(['content_id' => $staffItem]), 'layout zone skips it');
        Settings::set('default_content_id', (string) $staffItem);
        DB::update('rooms', ['playlist_id' => null], 'id = :id', ['id' => self::$id['r101']]);
        $this->assertSame('empty', ContentResolver::build(self::room('101'))['mode'], 'hotel default skips it');
        Settings::set('default_content_id', '');
        try {
            Broadcaster::pushNow('all', [], $staffItem, null);
            $this->fail('pending content was pushed');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('waiting for approval', $e->getMessage());
        }
        [, $errors] = Broadcaster::validateSchedule(['source' => 'c:' . $staffItem, 'target_type' => 'all', 'mode' => 'once', 'start_at' => date('Y-m-d H:i', time() + 3600)]);
        $this->assertContains('This content is waiting for approval and cannot be used yet.', $errors);
        [$s, $j] = self::as('mgr')->ajax('calendar_add', ['source' => 'c:' . $staffItem, 'target_type' => 'all', 'start' => date('Y-m-d\TH:i', time() + 7200), 'mode' => 'once']);
        $this->assertSame(422, $s);
        // Room / group / default forms refuse it (parse_source) and the pickers show it disabled.
        require_once HC_ROOT . '/admin/partials/common.php';
        $this->assertSame([null, null], parse_source('c:' . $staffItem));
        $this->assertSame([self::$id['welcome'], null], parse_source('c:' . self::$id['welcome']));
        [, , $html] = self::as('mgr')->get('broadcast.php');
        $this->assertMatchesRegularExpression('/value="c:' . $staffItem . '"[^>]* disabled/', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        // Queue: manager sees it (escaped), staff does not get the approve buttons.
        [$s, , $html] = self::as('mgr')->get('approvals.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Spa 20% off', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('name="op" value="approve"', $html);
        [, , $html] = self::as('staff')->get('approvals.php');
        $this->assertStringNotContainsString('name="op" value="approve"', $html);
        $this->assertStringContainsString('My submissions', $html);
        [$s] = self::as('staff')->post('approvals.php', ['op' => 'approve', 'id' => $staffItem]);
        $this->assertSame(403, $s, 'staff cannot approve');
        [$s, , $html] = self::as('mgr')->get('approvals.php?action=preview&id=' . $staffItem);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Spa 20% off', $html);
        // Tenancy: another hotel's item → 404.
        [$s] = self::as('mgr')->post('approvals.php', ['op' => 'approve', 'id' => self::$id['h2content']]);
        $this->assertSame(404, $s);
        $this->assertSame('pending', Tenant::run(2, fn () => DB::value('SELECT approval_status FROM content_items WHERE id = :id', ['id' => self::$id['h2content']])));
        // Reject needs a reason.
        self::as('mgr')->post('approvals.php', ['op' => 'reject', 'id' => $staffItem, 'reason' => '']);
        $this->assertSame('pending', DB::value('SELECT approval_status FROM content_items WHERE id = :id', ['id' => $staffItem]));

        // Approve → plays.
        self::as('mgr')->post('approvals.php', ['op' => 'approve', 'id' => $staffItem]);
        $row = ContentManager::findOwn($staffItem);
        $this->assertSame('approved', $row['approval_status']);
        $this->assertSame(self::$id['mgr'], (int) $row['reviewed_by']);
        DB::update('rooms', ['content_id' => $staffItem], 'id = :id', ['id' => self::$id['r101']]);
        $this->assertSame(['Spa 20% off'], array_column(ContentResolver::build(self::room('101'))['items'], 'text'));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'content_approved' AND entity_id = :id", ['id' => $staffItem]));

        // Staff edits approved content → pending revision; the approved version keeps playing.
        self::as('staff')->post('content.php', ['op' => 'save', 'id' => $staffItem, 'title' => 'Staff promo', 'body' => 'Spa 30% off', 'is_active' => 1, 'duration' => 8]);
        $this->assertSame('approved', DB::value('SELECT approval_status FROM content_items WHERE id = :id', ['id' => $staffItem]));
        $this->assertSame('Spa 20% off', DB::value('SELECT body FROM content_items WHERE id = :id', ['id' => $staffItem]));
        $rev = Approvals::revision($staffItem);
        $this->assertSame('pending', $rev['status']);
        $this->assertSame('Spa 30% off', $rev['data']['body']);
        $this->assertSame(['Spa 20% off'], array_column(ContentResolver::build(self::room('101'))['items'], 'text'));
        [, , $html] = self::as('staff')->get('content.php?action=edit&id=' . $staffItem);
        $this->assertStringContainsString('Spa 30% off', $html, 'staff keep editing their waiting change');
        [, , $html] = self::as('mgr')->get('approvals.php');
        $this->assertStringContainsString('Spa 30% off', $html);
        $this->assertStringContainsString('Change waiting for approval', $html);
        [, , $html] = self::as('mgr')->get('approvals.php?action=preview&id=' . $staffItem);
        $this->assertStringContainsString('Spa 30% off', $html, 'preview shows the waiting version');

        // Reject with a reason → the item stays as approved, the author sees the reason + badge.
        self::as('mgr')->post('approvals.php', ['op' => 'reject', 'id' => $staffItem, 'reason' => 'Price not confirmed']);
        $this->assertSame('rejected', Approvals::revision($staffItem)['status']);
        $this->assertSame('Spa 20% off', DB::value('SELECT body FROM content_items WHERE id = :id', ['id' => $staffItem]));
        [, , $html] = self::as('staff')->get('approvals.php');
        $this->assertStringContainsString('Price not confirmed', $html);
        $this->assertStringContainsString('Approvals (1)', $html, 'menu badge for the author');

        // Re-submit, approve → new version on air.
        self::as('staff')->post('approvals.php', ['op' => 'submit', 'id' => $staffItem]);
        $this->assertSame('pending', Approvals::revision($staffItem)['status']);
        [, , $html] = self::as('mgr')->get('index.php');
        $this->assertMatchesRegularExpression('/Approvals \(\d+\)/', $html, 'menu badge for managers');
        self::as('mgr')->post('approvals.php', ['op' => 'approve', 'id' => $staffItem]);
        $this->assertNull(Approvals::revision($staffItem));
        $this->assertSame('Spa 30% off', DB::value('SELECT body FROM content_items WHERE id = :id', ['id' => $staffItem]));
        $this->assertSame(['Spa 30% off'], array_column(ContentResolver::build(self::room('101'))['items'], 'text'));

        // Manager's own content is approved directly; a manager save replaces a waiting staff change.
        self::as('mgr')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Mgr while on', 'body' => 'm', 'is_active' => 1]);
        $this->assertSame('approved', DB::value("SELECT approval_status FROM content_items WHERE title = 'Mgr while on'"));
        self::as('staff')->post('content.php', ['op' => 'save', 'id' => $staffItem, 'title' => 'Staff promo', 'body' => 'Spa 50% off', 'is_active' => 1]);
        $this->assertNotNull(Approvals::revision($staffItem));
        self::as('mgr')->post('content.php', ['op' => 'save', 'id' => $staffItem, 'title' => 'Staff promo', 'body' => 'Spa 25% off', 'is_active' => 1]);
        $this->assertNull(Approvals::revision($staffItem));
        $this->assertSame('Spa 25% off', DB::value('SELECT body FROM content_items WHERE id = :id', ['id' => $staffItem]));

        // Staff editing a still-pending item keeps it pending; staff cannot delete / toggle.
        self::as('staff')->post('content.php', ['op' => 'save', 'id' => 0, 'type' => 'announcement', 'title' => 'Second', 'body' => 'v1', 'is_active' => 1]);
        $second = (int) DB::value("SELECT id FROM content_items WHERE title = 'Second'");
        self::as('staff')->post('content.php', ['op' => 'save', 'id' => $second, 'title' => 'Second', 'body' => 'v2', 'is_active' => 1]);
        $this->assertSame(['pending', 'v2'], array_values(DB::one('SELECT approval_status, body FROM content_items WHERE id = :id', ['id' => $second])));
        [$s] = self::as('staff')->post('content.php', ['op' => 'delete', 'id' => $second]);
        $this->assertSame(403, $s);
        $this->assertNull(Approvals::revision($second));

        // Switching off: new saves are approved again; items still waiting stay in the queue.
        self::as('admin')->post('approvals.php', ['op' => 'setting']);
        Settings::flush();
        $this->assertFalse(Approvals::enabled());
        $this->assertSame('pending', DB::value('SELECT approval_status FROM content_items WHERE id = :id', ['id' => $second]));
        [, , $html] = self::as('mgr')->get('approvals.php');
        $this->assertStringContainsString('v2', $html);
        self::resetRooms();
    }

    // ------------------------------------------------------------------ #35 holidays

    public function testHolidayActionsAndPrecedence(): void
    {
        self::resetRooms();
        ContentRules::$now = strtotime('2026-11-08 12:00:00'); // Diwali
        DB::update('rooms', ['content_id' => self::$id['welcome']], 'hotel_id = 1', []);
        $hol = static fn (array $row): int => DB::insert('holidays', $row + ['name' => 'Diwali', 'start_date' => '2026-11-08', 'end_date' => '2026-11-09', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);

        $this->assertSame('assigned', ContentResolver::build(self::room('101'))['mode']);
        $marker = $hol(['action' => 'none']);
        $this->assertSame('assigned', ContentResolver::build(self::room('101'))['mode'], "'none' is only a marker");

        $off = $hol(['action' => 'tv_off']);
        $c = ContentResolver::build(self::room('101'));
        $this->assertSame(['off', false, 'holiday', $off], [$c['mode'], $c['screen_on'], $c['off_reason'], $c['holiday_id']]);
        $this->assertSame([], $c['items']);
        ContentRules::$now = strtotime('2026-11-10 00:00:00');
        $this->assertSame('assigned', ContentResolver::build(self::room('101'))['mode'], 'the day after');
        ContentRules::$now = strtotime('2026-11-09 23:59:00');
        $this->assertSame('off', ContentResolver::build(self::room('101'))['mode'], 'end date is inclusive');

        // A more specific holiday (this room) wins over the hotel-wide one.
        $show = $hol(['action' => 'show_content', 'content_id' => self::$id['diwali'], 'target_type' => 'rooms', 'target_ids' => json_encode([self::$id['r102']])]);
        $c = ContentResolver::build(self::room('102'));
        $this->assertSame(['scheduled', $show], [$c['mode'], $c['holiday_id']]);
        $this->assertSame(['Happy Diwali'], array_column($c['items'], 'text'));
        $this->assertSame('off', ContentResolver::build(self::room('101'))['mode']);

        // Groups target.
        DB::update('holidays', ['is_active' => 0], 'id = :id', ['id' => $off]);
        $grp = $hol(['action' => 'tv_off', 'target_type' => 'groups', 'target_ids' => json_encode([self::$id['grp']])]);
        $this->assertSame('off', ContentResolver::build(self::room('201'))['mode']);
        $this->assertSame('assigned', ContentResolver::build(self::room('101'))['mode'], 'paused holiday does nothing');
        DB::update('holidays', ['is_active' => 1], 'id = :id', ['id' => $off]);

        // A time-window broadcast of that day wins over holiday content; a power-off window over both.
        $win = DB::insert('broadcast_commands', ['title' => 'Lakshmi puja', 'command' => 'SHOW_CONTENT', 'target_type' => 'rooms', 'target_ids' => json_encode([self::$id['r102']]),
            'content_id' => self::$id['breakfast'], 'mode' => 'window', 'status' => 'active', 'created_at' => now()]);
        $this->assertSame(['scheduled', $win], [ContentResolver::build(self::room('102'))['mode'], ContentResolver::build(self::room('102'))['broadcast_id'] ?? null]);
        DB::update('broadcast_commands', ['status' => 'cancelled'], 'id = :id', ['id' => $win]);
        $pw = DB::insert('broadcast_commands', ['title' => 'Night off', 'command' => 'SCREEN_OFF', 'target_type' => 'all', 'target_ids' => '[]', 'mode' => 'window', 'status' => 'active', 'created_at' => now()]);
        $c = ContentResolver::build(self::room('102'));
        $this->assertSame(['off', $pw], [$c['mode'], $c['power_schedule_id'] ?? null]);
        DB::update('broadcast_commands', ['status' => 'cancelled'], 'id = :id', ['id' => $pw]);

        // Emergency always wins.
        $em = Broadcaster::emergencyStart('Fire drill', 'Go to the lobby', 'all', []);
        $this->assertSame('emergency', ContentResolver::build(self::room('101'))['mode']);
        $this->assertSame('emergency', ContentResolver::build(self::room('102'))['mode']);
        Broadcaster::emergencyStop($em);
        $this->assertSame('off', ContentResolver::build(self::room('101'))['mode']);
        DB::query('DELETE FROM holidays WHERE id IN (' . implode(',', [$marker, $off, $show, $grp]) . ')');

        // Management page: save / validate / import / tenancy / restricted users.
        [$s] = self::as('mgr')->post('holidays.php', ['op' => 'save', 'id' => 0, 'name' => 'Navratri ' . self::XSS, 'start_date' => '2026-10-11', 'end_date' => '2026-10-19',
            'action' => 'show_content', 'source' => 'c:' . self::$id['diwali'], 'target_type' => 'all', 'is_active' => '1']);
        $this->assertSame(302, $s);
        $nav = DB::one("SELECT * FROM holidays WHERE name LIKE 'Navratri%'");
        $this->assertSame(['2026-10-11', '2026-10-19', 'show_content', self::$id['diwali']], [$nav['start_date'], $nav['end_date'], $nav['action'], (int) $nav['content_id']]);
        self::as('mgr')->post('holidays.php', ['op' => 'save', 'id' => 0, 'name' => 'Bad', 'start_date' => '2026-10-11', 'end_date' => '2026-10-01', 'action' => 'tv_off', 'target_type' => 'all']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM holidays WHERE name = 'Bad'"));
        [$s, , $html] = self::as('mgr')->get('holidays.php?year=2026');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Navratri', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('B-HOLIDAY', $html);
        [$s] = self::as('mgr')->get('holidays.php?action=edit&id=' . self::$id['h2hol']);
        $this->assertSame(404, $s);
        [$s] = self::as('mgr')->post('holidays.php', ['op' => 'delete', 'id' => self::$id['h2hol']]);
        $this->assertSame(404, $s);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM holidays WHERE id = :id', ['id' => self::$id['h2hol']]));

        self::as('mgr')->post('holidays.php', ['op' => 'import', 'action' => 'none']);
        $n = (int) DB::value("SELECT COUNT(*) FROM holidays WHERE hotel_id = 1 AND name = 'Republic Day'");
        $this->assertSame(2, $n, 'Republic Day 2026 and 2027');
        $total = (int) DB::value('SELECT COUNT(*) FROM holidays WHERE hotel_id = 1');
        self::as('mgr')->post('holidays.php', ['op' => 'import', 'action' => 'none']);
        $this->assertSame($total, (int) DB::value('SELECT COUNT(*) FROM holidays WHERE hotel_id = 1'), 'second import adds nothing');
        $this->assertSame(count(Holidays::indianHolidays()), $total - 1);

        // Restricted manager: cannot add hotel-wide holidays or import; may add for their room.
        [$s] = self::as('mgrR')->post('holidays.php', ['op' => 'save', 'id' => 0, 'name' => 'All off', 'start_date' => '2026-12-31', 'action' => 'tv_off', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        [$s] = self::as('mgrR')->post('holidays.php', ['op' => 'import']);
        $this->assertSame(403, $s);
        [$s] = self::as('mgrR')->post('holidays.php', ['op' => 'save', 'id' => 0, 'name' => 'Mine off', 'start_date' => '2026-12-31', 'action' => 'tv_off', 'target_type' => 'rooms', 'room_ids' => [self::$id['r102']]]);
        $this->assertSame(403, $s, 'not their room');
        self::as('mgrR')->post('holidays.php', ['op' => 'save', 'id' => 0, 'name' => 'Mine off', 'start_date' => '2026-12-31', 'action' => 'tv_off', 'target_type' => 'rooms', 'room_ids' => [self::$id['r101']]]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM holidays WHERE name = 'Mine off'"));
        $other = DB::insert('holidays', ['name' => 'Room 102 only', 'start_date' => '2026-12-30', 'end_date' => '2026-12-30', 'action' => 'tv_off', 'target_type' => 'rooms', 'target_ids' => json_encode([self::$id['r102']]), 'created_at' => now()]);
        [, , $html] = self::as('mgrR')->get('holidays.php?year=2026');
        $this->assertStringContainsString('Mine off', $html);
        $this->assertStringNotContainsString('Room 102 only', $html);
        [$s] = self::as('mgrR')->get('holidays.php?action=edit&id=' . $other);
        $this->assertSame(403, $s);
        [$s] = self::as('staff')->get('holidays.php');
        $this->assertSame(403, $s);
        DB::query('DELETE FROM holidays WHERE hotel_id = 1');
        self::resetRooms();
    }

    // ------------------------------------------------------------------ #31 calendar

    public function testCalendarEventsMoveAddTenancyAndRestrictedUsers(): void
    {
        self::resetRooms();
        $day = static fn (int $plus, string $time = '00:00'): string => date('Y-m-d', strtotime("+$plus day")) . ' ' . $time . ':00';
        $mk = static fn (array $row): int => DB::insert('broadcast_commands', $row + ['command' => 'SHOW_CONTENT', 'target_type' => 'all', 'target_ids' => '[]', 'status' => 'scheduled', 'created_at' => now()]);
        $once = $mk(['title' => 'Once ' . self::XSS, 'content_id' => self::$id['welcome'], 'mode' => 'once', 'start_at' => $day(2, '09:00')]);
        $plain = $mk(['title' => 'Plain window', 'content_id' => self::$id['breakfast'], 'mode' => 'window', 'start_at' => $day(3, '08:00'), 'end_at' => $day(3, '10:00'),
            'target_type' => 'rooms', 'target_ids' => json_encode([self::$id['r101']])]);
        $series = $mk(['title' => 'Happy hour', 'content_id' => self::$id['bar'], 'mode' => 'window', 'daily_start' => '18:00:00', 'daily_end' => '19:00:00', 'repeat_days' => '1,3',
            'target_type' => 'rooms', 'target_ids' => json_encode([self::$id['r102']])]);
        $power = DB::insert('broadcast_commands', ['title' => 'Night off', 'command' => 'SCREEN_OFF', 'target_type' => 'all', 'target_ids' => '[]', 'mode' => 'window', 'status' => 'scheduled',
            'daily_start' => '23:00:00', 'daily_end' => '06:00:00', 'repeat_days' => '1,2,3,4,5,6,7', 'created_at' => now()]);
        DB::update('content_items', ['valid_to' => $day(4, '12:00')], 'id = :id', ['id' => self::$id['office']]);
        $hol = DB::insert('holidays', ['name' => 'Founders day', 'start_date' => date('Y-m-d', strtotime('+5 day')), 'end_date' => date('Y-m-d', strtotime('+5 day')), 'action' => 'tv_off',
            'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
        $dev = null;
        if (Migrator::hasTable(DB::pdo(), 'device_schedules')) {
            $dev = DB::insert('device_schedules', ['title' => 'Morning bell', 'action' => 'bell', 'run_time' => '07:00:00', 'repeat_mode' => 'daily', 'target_type' => 'all', 'target_ids' => '[]', 'is_active' => 1, 'created_at' => now()]);
        }
        $range = 'start=' . date('Y-m-d', strtotime('-1 day')) . 'T00:00:00Z&end=' . date('Y-m-d', strtotime('+8 day')) . 'T00:00:00Z';

        [$s, $j, $raw] = self::as('mgr')->ajax('calendar_events&' . $range);
        $this->assertSame(200, $s, $raw);
        $events = $j['data']['events'];
        $byKind = [];
        foreach ($events as $e) {
            $byKind[$e['extendedProps']['kind']][] = $e;
        }
        foreach (['schedule', 'power', 'content', 'holiday'] as $k) {
            $this->assertArrayHasKey($k, $byKind, $k);
        }
        if ($dev) {
            $this->assertArrayHasKey('device', $byKind);
            $this->assertCount(9, $byKind['device'], 'daily bell → one per day in range');
            $this->assertFalse($byKind['device'][0]['editable']);
        }
        $find = static fn (string $id) => array_values(array_filter($events, static fn ($e) => $e['id'] === $id))[0] ?? null;
        $e = $find('b' . $once);
        $this->assertSame(substr($day(2, '09:00'), 0, 10) . 'T09:00:00', $e['start'], 'hotel wall-clock time, no offset');
        $this->assertTrue($e['startEditable']);
        $this->assertFalse($e['durationEditable']);
        $this->assertSame(Calendar::COLORS['schedule'], $e['color']);
        $this->assertTrue($find('b' . $plain)['durationEditable']);
        $occ = array_values(array_filter($byKind['schedule'], static fn ($e) => $e['extendedProps']['ref'] === $series));
        $this->assertNotEmpty($occ);
        foreach ($occ as $o) {
            $this->assertContains((int) date('N', strtotime($o['start'])), [1, 3]);
            $this->assertSame('b' . $series, $o['groupId']);
            $this->assertTrue($o['extendedProps']['repeating']);
        }
        $this->assertFalse($byKind['power'][0]['startEditable'], 'power schedules are read-only here');
        $this->assertSame(Calendar::COLORS['holiday_off'], $byKind['holiday'][0]['color']);
        $this->assertStringNotContainsString('B-SCHED', $raw, 'other hotel');
        $this->assertStringNotContainsString('B-HOLIDAY', $raw);

        // Move a one-off schedule (CSRF required, validation, tenancy).
        $to = date('Y-m-d', strtotime('+2 day')) . 'T11:30:00Z';
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=calendar_move', ['kind' => 'schedule', 'id' => $once, 'start' => $to], ['X-Requested-With: XMLHttpRequest'], self::as('mgr')->jar);
        $this->assertSame(419, $s, 'no CSRF token');
        [$s, $j] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $once, 'start' => $to]);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(date('Y-m-d', strtotime('+2 day')) . ' 11:30:00', DB::value('SELECT start_at FROM broadcast_commands WHERE id = :id', ['id' => $once]));
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $once, 'start' => date('Y-m-d', strtotime('-2 day')) . 'T10:00:00']);
        $this->assertSame(422, $s, 'past');
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $once, 'start' => 'tomorrow-ish']);
        $this->assertSame(422, $s);
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => self::$id['h2sched'], 'start' => $to]);
        $this->assertSame(404, $s, 'another hotel');
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => 999999, 'start' => $to]);
        $this->assertSame(404, $s);
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'power', 'id' => $power, 'start' => $to]);
        $this->assertSame(422, $s);

        // Resize a plain window.
        $d3 = date('Y-m-d', strtotime('+3 day'));
        self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $plain, 'start' => $d3 . 'T08:00:00Z', 'end' => $d3 . 'T12:00:00Z', 'orig_start' => $d3 . 'T08:00:00Z']);
        $this->assertSame([$d3 . ' 08:00:00', $d3 . ' 12:00:00'], array_values(DB::one('SELECT start_at, end_at FROM broadcast_commands WHERE id = :id', ['id' => $plain])));
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $plain, 'start' => $d3 . 'T12:00:00Z', 'end' => $d3 . 'T08:00:00Z']);
        $this->assertSame(422, $s, 'end before start');

        // Move the repeating series: Monday 18:00 → Tuesday 20:00–21:30 shifts the days and times.
        $mon = date('Y-m-d', strtotime('next monday'));
        $tue = date('Y-m-d', strtotime($mon . ' +1 day'));
        [$s, $j] = self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $series, 'start' => $tue . 'T20:00:00Z', 'end' => $tue . 'T21:30:00Z', 'orig_start' => $mon . 'T18:00:00Z']);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['20:00:00', '21:30:00', '2,4'], array_values(DB::one('SELECT daily_start, daily_end, repeat_days FROM broadcast_commands WHERE id = :id', ['id' => $series])));
        // Sunday → Monday wraps around.
        $sun = date('Y-m-d', strtotime($mon . ' -1 day'));
        DB::update('broadcast_commands', ['repeat_days' => '7'], 'id = :id', ['id' => $series]);
        self::as('mgr')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $series, 'start' => $mon . 'T20:00:00Z', 'end' => $mon . 'T21:30:00Z', 'orig_start' => $sun . 'T20:00:00Z']);
        $this->assertSame('1', DB::value('SELECT repeat_days FROM broadcast_commands WHERE id = :id', ['id' => $series]));

        // Move a holiday (all-day, exclusive end).
        $h1 = date('Y-m-d', strtotime('+6 day'));
        $h3 = date('Y-m-d', strtotime('+9 day'));
        self::as('mgr')->ajax('calendar_move', ['kind' => 'holiday', 'id' => $hol, 'start' => $h1, 'end' => $h3]);
        $this->assertSame([$h1, date('Y-m-d', strtotime('+8 day'))], array_values(DB::one('SELECT start_date, end_date FROM holidays WHERE id = :id', ['id' => $hol])));
        [$s] = self::as('mgr')->ajax('calendar_move', ['kind' => 'holiday', 'id' => self::$id['h2hol'], 'start' => $h1]);
        $this->assertSame(404, $s);

        // Add from a selected range (Broadcaster::validateSchedule + schedule).
        $d6 = date('Y-m-d', strtotime('+6 day'));
        [$s, $j] = self::as('mgr')->ajax('calendar_add', ['title' => 'Wedding', 'source' => 'c:' . self::$id['diwali'], 'target_type' => 'rooms', 'target_ids' => [self::$id['r101']],
            'start' => $d6 . 'T10:00:00Z', 'end' => $d6 . 'T14:00:00Z', 'mode' => 'window']);
        $this->assertSame(200, $s, json_encode($j));
        $new = DB::one('SELECT * FROM broadcast_commands WHERE id = :id', ['id' => $j['data']['id']]);
        $this->assertSame(['window', $d6 . ' 10:00:00', $d6 . ' 14:00:00', 'rooms', self::$id['diwali']], [$new['mode'], $new['start_at'], $new['end_at'], $new['target_type'], (int) $new['content_id']]);
        [$s, $j] = self::as('mgr')->ajax('calendar_add', ['source' => 'p:999999', 'target_type' => 'all', 'start' => $d6 . 'T10:00:00Z', 'mode' => 'once']);
        $this->assertSame(422, $s);
        [$s] = self::as('mgr')->ajax('calendar_add', ['source' => 'c:' . self::$id['h2content'], 'target_type' => 'all', 'start' => $d6 . 'T10:00:00Z', 'mode' => 'once']);
        $this->assertSame(404, $s, 'another hotel\'s content → 404');
        [$s] = self::as('mgr')->ajax('calendar_add', ['source' => 'c:' . self::$id['welcome'], 'target_type' => 'rooms', 'target_ids' => [9999999], 'start' => $d6 . 'T10:00:00Z', 'mode' => 'once']);
        $this->assertContains($s, [404, 422]);

        // Restricted manager (room 101 only): sees hotel-wide + own entries, moves only own ones.
        [$s, $j, $raw] = self::as('mgrR')->ajax('calendar_events&' . $range);
        $this->assertSame(200, $s);
        $refs = array_map(static fn ($e) => $e['extendedProps']['kind'] . $e['extendedProps']['ref'], $j['data']['events']);
        $this->assertContains('schedule' . $once, $refs, 'hotel-wide schedule is visible');
        $this->assertContains('schedule' . $plain, $refs, 'own room');
        $this->assertNotContains('schedule' . $series, $refs, 'room 102 only');
        $this->assertStringNotContainsString('Happy hour', $raw);
        foreach ($j['data']['events'] as $e) {
            if ($e['extendedProps']['ref'] === $once && $e['extendedProps']['kind'] === 'schedule') {
                $this->assertFalse($e['startEditable'], 'hotel-wide entries are read-only for restricted users');
            }
        }
        [$s] = self::as('mgrR')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $series, 'start' => $tue . 'T20:00:00Z', 'end' => $tue . 'T21:00:00Z', 'orig_start' => $tue . 'T20:00:00Z']);
        $this->assertSame(403, $s);
        [$s] = self::as('mgrR')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $once, 'start' => $to]);
        $this->assertSame(403, $s, 'hotel-wide schedule');
        [$s] = self::as('mgrR')->ajax('calendar_move', ['kind' => 'schedule', 'id' => $plain, 'start' => $d3 . 'T09:00:00Z', 'end' => $d3 . 'T10:00:00Z', 'orig_start' => $d3 . 'T08:00:00Z']);
        $this->assertSame(200, $s);
        [$s] = self::as('mgrR')->ajax('calendar_add', ['source' => 'c:' . self::$id['welcome'], 'target_type' => 'all', 'start' => $d6 . 'T10:00:00Z', 'end' => $d6 . 'T11:00:00Z']);
        $this->assertSame(403, $s);
        [$s] = self::as('mgrR')->ajax('calendar_add', ['source' => 'c:' . self::$id['welcome'], 'target_type' => 'rooms', 'target_ids' => [self::$id['r101']], 'start' => $d6 . 'T10:00:00Z', 'end' => $d6 . 'T11:00:00Z']);
        $this->assertSame(200, $s);

        // Staff / reception: no calendar.
        [$s] = self::as('staff')->ajax('calendar_events&' . $range);
        $this->assertSame(403, $s);
        [$s] = self::as('staff')->get('calendar.php');
        $this->assertSame(403, $s);
        [$s, , $html] = self::as('mgr')->get('calendar.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('hcCalendar', $html);

        if ($dev) {
            DB::delete('device_schedules', 'id = :id', ['id' => $dev]);
        }
        DB::update('content_items', ['valid_to' => null], 'id = :id', ['id' => self::$id['office']]);
        self::resetRooms();
    }

    public function testCalendarOccurrenceExpansion(): void
    {
        $rs = strtotime('2026-10-05 00:00:00'); // Monday
        $re = strtotime('2026-10-12 00:00:00');
        $occ = Calendar::occurrences(['mode' => 'window', 'start_at' => null, 'end_at' => null, 'daily_start' => '22:00:00', 'daily_end' => '02:00:00', 'repeat_days' => '7'], $rs, $re);
        // Sunday 4th 22:00 → Monday 5th 02:00 reaches into the range; Sunday 11th 22:00 too.
        $this->assertSame(['2026-10-04', '2026-10-11'], array_column($occ, 3));
        $this->assertSame('2026-10-12 02:00', date('Y-m-d H:i', $occ[1][1]));
        $days = Calendar::occurrences(['mode' => 'window', 'start_at' => '2026-10-07 00:00:00', 'end_at' => null, 'daily_start' => null, 'daily_end' => null, 'repeat_days' => '3,4'], $rs, $re);
        $this->assertSame(['2026-10-07', '2026-10-08'], array_column($days, 3));
        $this->assertTrue($days[0][2], 'weekday-only windows are all-day');
        $this->assertSame(strtotime('2026-10-08 10:30:00'), Calendar::parseLocal('2026-10-08T10:30:00Z'));
        $this->assertSame(strtotime('2026-10-08 10:30:00'), Calendar::parseLocal('2026-10-08T10:30:00+05:30'));
        $this->assertSame(strtotime('2026-10-08 00:00:00'), Calendar::parseLocal('2026-10-08'));
        $this->assertNull(Calendar::parseLocal('2026-10-08; DROP TABLE'));
    }

    // ------------------------------------------------------------------ pages, roles, translations

    public function testNewPagesHaveNoPhpWarningsForAllRoles(): void
    {
        Settings::set(Approvals::SETTING, '1');
        $item = DB::insert('content_items', ['title' => 'Pending page item', 'type' => 'announcement', 'body' => 'x', 'duration' => 5, 'approval_status' => 'pending', 'submitted_by' => self::$id['staff'], 'created_at' => now()]);
        $hol = DB::insert('holidays', ['name' => 'Page holiday', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'), 'action' => 'none', 'target_type' => 'all', 'target_ids' => '[]', 'created_at' => now()]);
        $pid = self::playlist('Page playlist', [[self::$id['welcome'], '06:00:00', '11:00:00', '1,2']]);
        $pages = ['calendar.php', 'approvals.php', 'approvals.php?action=preview&id=' . $item, 'holidays.php', 'holidays.php?action=new', 'holidays.php?action=edit&id=' . $hol,
            'holidays.php?year=2027', 'content.php', 'content.php?view=list&state=waiting', 'content.php?state=live', 'content.php?action=new&type=image',
            'content.php?action=edit&id=' . $item, 'content.php?action=edit&id=' . self::$id['welcome'], 'playlists.php?action=edit&id=' . $pid, 'schedule.php', 'index.php'];
        $ajax = ['calendar_events&start=2026-01-01&end=2026-03-01', 'calendar_events&start=garbage&end=x'];
        foreach (['admin', 'mgr', 'mgrR', 'staff', 'desk'] as $u) {
            foreach (['en', 'gu'] as $lang) {
                self::as($u)->ajax('set_language', ['lang' => $lang]);
                foreach ($pages as $p) {
                    [$s, , $body] = self::as($u)->get($p);
                    $this->assertContains($s, [200, 302, 403, 404], "$p as $u ($lang)");
                    $this->assertFalse(TestEnv::hasPhpError($body), "PHP error on $p as $u ($lang)");
                }
                foreach ($ajax as $a) {
                    [$s, , $body] = self::as($u)->ajax($a);
                    $this->assertContains($s, [200, 403], "ajax $a as $u");
                    $this->assertFalse(TestEnv::hasPhpError($body));
                }
            }
            self::as($u)->ajax('set_language', ['lang' => 'en']);
        }
        [$s] = self::as('desk')->get('approvals.php');
        $this->assertSame(200, $s);
        [$s, , $html] = self::as('desk')->get('index.php');
        $this->assertStringContainsString('content.php', $html, 'reception gets the content library while approval is on');
        [$s] = self::as('desk')->get('calendar.php');
        $this->assertSame(403, $s);
        Settings::set(Approvals::SETTING, '0');
        DB::delete('content_items', 'id = :id', ['id' => $item]);
        DB::delete('holidays', 'id = :id', ['id' => $hol]);
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testTranslationsCoverTheNewStrings(): void
    {
        $files = ['core/Approvals.php', 'core/ContentRules.php', 'core/Holidays.php', 'core/Calendar.php', 'core/Tasks/ContentExpiryTask.php', 'admin/calendar.php',
            'admin/approvals.php', 'admin/holidays.php', 'admin/ajax.d/calendar.php', 'admin/partials/nav.d/22_scheduling.php'];
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $missing = [];
        foreach ($files as $f) {
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                isset($gu[$key]) || $missing[] = "gu $f: $key";
                isset($hi[$key]) || $missing[] = "hi $f: $key";
            }
        }
        foreach (Holidays::indianHolidays() as [, $name]) {
            isset($gu[$name]) || $missing[] = "gu holiday: $name";
            isset($hi[$name]) || $missing[] = "hi holiday: $name";
        }
        foreach ((require HC_ROOT . '/lang/gu_scheduling.php') as $key => $v) {
            isset($hi[$key]) || $missing[] = "hi: $key";
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }
}
