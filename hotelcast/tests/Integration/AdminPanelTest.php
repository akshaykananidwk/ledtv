<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Admin panel over HTTP: login + lockout, every page renders for the right roles,
 * server-side permission checks, CSRF enforcement, XSS escaping, AJAX endpoints.
 */
final class AdminPanelTest extends TestCase
{
    private static string $url;
    private static array $jars = [];

    public const PAGES = [
        'index.php' => 'reception', 'rooms.php' => 'reception', 'content.php' => 'staff', 'broadcast.php' => 'staff',
        'groups.php' => 'manager', 'playlists.php' => 'manager', 'schedule.php' => 'manager', 'apk.php' => 'manager',
        'logs.php' => 'manager', 'power.php' => 'manager', 'users.php' => 'super_admin', 'settings.php' => 'super_admin', 'update.php' => 'platform_admin',
        'profile.php' => 'reception', 'billing.php' => 'super_admin',
        'platform_hotels.php' => 'platform_admin', 'platform_plans.php' => 'platform_admin', 'platform_resellers.php' => 'platform_admin',
        'platform_invoices.php' => 'platform_admin', 'platform_licenses.php' => 'platform_admin', 'platform_settings.php' => 'platform_admin',
        'platform_support.php' => 'platform_admin',
    ];

    /** Role level per test user (platform admin acts as super admin inside hotel 1). */
    private const USERS = ['root' => 5, 'boss' => 4, 'mgr' => 3, 'desk' => 2, 'recep' => 1];
    private const LEVEL = ['reception' => 1, 'staff' => 2, 'manager' => 3, 'super_admin' => 4, 'platform_admin' => 5];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Installer::demoData();
        foreach (['platform_admin' => 'root', 'super_admin' => 'boss', 'manager' => 'mgr', 'staff' => 'desk', 'reception' => 'recep'] as $role => $u) {
            DB::insert('users', ['username' => $u, 'email' => $u . '@hotel.test', 'full_name' => ucfirst($u), 'password_hash' => Auth::hash('Passw0rd!'), 'role' => $role]);
        }
        DB::insert('rooms', ['room_number' => 'X1', 'name' => '<script>alert("xss")</script>', 'floor' => '9']);
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$jars as $j) {
            @unlink($j);
        }
    }

    private static function jar(string $who): string
    {
        return self::$jars[$who] ??= (string) tempnam(sys_get_temp_dir(), 'adm');
    }

    private static function csrfFrom(string $html): string
    {
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) || preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
            return html_entity_decode($m[1]);
        }
        return '';
    }

    private static function login(string $user, string $pass = 'Passw0rd!'): array
    {
        $jar = self::jar($user);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php', null, [], $jar);
        $form = ['_csrf' => self::csrfFrom($html), 'username' => $user, 'password' => $pass];
        return TestEnv::http('POST', self::$url . 'admin/login.php', null, [], $jar, $form);
    }

    public function testLoginPageAndRedirect(): void
    {
        [$s, , , $head] = TestEnv::http('GET', self::$url . 'admin/index.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('login.php', $head);
        [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function testLockoutAfterFiveFailures(): void
    {
        DB::insert('users', ['username' => 'victim', 'email' => 'v@hotel.test', 'password_hash' => Auth::hash('Correct123'), 'role' => 'staff']);
        for ($i = 0; $i < 5; $i++) {
            self::login('victim', 'wrong-' . $i);
        }
        $this->assertNotNull(DB::value("SELECT locked_until FROM users WHERE username='victim'"));
        [, , $html] = self::login('victim', 'Correct123');
        $this->assertStringContainsStringIgnoringCase('locked', $html, 'Correct password refused while locked');
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE u.username='victim'"));
    }

    public function testLoginSuccess(): void
    {
        foreach (array_keys(self::USERS) as $u) {
            [$s, , , $head] = self::login($u);
            $this->assertSame(302, $s, "login $u");
            $this->assertStringNotContainsString('login.php', $head);
        }
    }

    public static function pageMatrix(): array
    {
        $rows = [];
        foreach (self::PAGES as $page => $min) {
            foreach (self::USERS as $u => $lvl) {
                $rows["$page as $u"] = [$page, $u, $lvl >= self::LEVEL[$min]];
            }
        }
        return $rows;
    }

    #[DataProvider('pageMatrix')]
    public function testPagePermissions(string $page, string $user, bool $allowed): void
    {
        if (!isset(self::$jars[$user])) {
            self::login($user);
        }
        [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/' . $page, null, [], self::jar($user));
        $this->assertSame($allowed ? 200 : 403, $s, "$page as $user");
        if ($allowed) {
            $this->assertFalse(TestEnv::hasPhpError($html), "$page as $user shows a PHP error");
            $this->assertStringNotContainsString('Fatal error', $html);
            $this->assertStringNotContainsString('Warning:', $html);
            $this->assertStringNotContainsString('Notice:', $html);
            $this->assertStringNotContainsString('Deprecated:', $html);
        }
    }

    public function testXssEscapedInRoomList(): void
    {
        self::login('boss');
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/rooms.php', null, [], self::jar('boss'));
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCsrfRequiredForAjaxPost(): void
    {
        self::login('boss');
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=emergency_stop', [], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], self::jar('boss'));
        $this->assertSame(419, $s);
    }

    public function testAjaxDashboardAndRoomStatus(): void
    {
        self::login('desk');
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
        [$s, $j] = TestEnv::http('GET', self::$url . 'admin/ajax.php?action=dashboard_stats', null, $h, self::jar('desk'));
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
        [$s, $j] = TestEnv::http('GET', self::$url . 'admin/ajax.php?action=room_status', null, $h, self::jar('desk'));
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
    }

    public function testEmergencyViaAjaxAndStaffCannotReboot(): void
    {
        [, , $html] = self::login('desk');
        [, , $page] = TestEnv::http('GET', self::$url . 'admin/index.php', null, [], self::jar('desk'));
        $csrf = self::csrfFrom($page);
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'X-CSRF-Token: ' . $csrf];
        [$s, $j] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=emergency_start', ['title' => 'Test alarm', 'message' => 'Please stay calm', 'target_type' => 'all'], $h, self::jar('desk'));
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE is_emergency=1 AND status='active'"));
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=emergency_stop', [], $h, self::jar('desk'));
        $this->assertSame(200, $s);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE is_emergency=1 AND status='active'"));
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=send_command', ['command' => 'REBOOT', 'target_type' => 'all'], $h, self::jar('desk'));
        $this->assertSame(403, $s, 'Staff cannot send device commands');
    }

    public function testPreviewPages(): void
    {
        self::login('boss');
        $cid = (int) DB::value("SELECT id FROM content_items WHERE type='timetable' LIMIT 1");
        $rid = (int) DB::value('SELECT id FROM rooms LIMIT 1');
        foreach (['content_id=' . $cid, 'room_id=' . $rid, 'playlist_id=' . (int) DB::value('SELECT id FROM content_playlists LIMIT 1')] as $q) {
            [$s, , $html] = TestEnv::http('GET', self::$url . 'admin/preview.php?' . $q, null, [], self::jar('boss'));
            $this->assertSame(200, $s, $q);
            $this->assertStringNotContainsString('Fatal error', $html);
        }
    }

    public function testUpdatePageAjaxStatus(): void
    {
        self::login('root');
        [$s, $j] = TestEnv::http('GET', self::$url . 'admin/ajax_update.php?action=update_status', null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], self::jar('root'));
        $this->assertSame(200, $s);
        $this->assertTrue($j['ok']);
        foreach (['boss', 'mgr'] as $u) {
            self::login($u);
            [$s] = TestEnv::http('GET', self::$url . 'admin/ajax_update.php?action=update_status', null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], self::jar($u));
            $this->assertSame(403, $s, 'Only the platform admin can use the updater (' . $u . ')');
        }
    }

    public function testLogout(): void
    {
        self::login('mgr');
        [, , $page] = TestEnv::http('GET', self::$url . 'admin/index.php', null, [], self::jar('mgr'));
        TestEnv::http('POST', self::$url . 'admin/logout.php', null, [], self::jar('mgr'), ['_csrf' => self::csrfFrom($page)]);
        [$s] = TestEnv::http('GET', self::$url . 'admin/index.php', null, [], self::jar('mgr'));
        $this->assertSame(302, $s);
        unset(self::$jars['mgr']);
    }

    public function testNoPhpWarningsLogged(): void
    {
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }
}
