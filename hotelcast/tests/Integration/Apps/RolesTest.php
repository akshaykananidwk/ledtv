<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Custom roles / RBAC (core/Roles.php, admin/roles.php, Auth::can, docs/modules/roles.md) — over real HTTP.
 *
 * Hotel #1: boss (built-in Admin), mgr (built-in Manager), editor (custom "Content editor": content.view,
 * content.manage, playlists.manage), umgr (custom "User desk": dashboard.view, rooms.view, content.view,
 * users.manage), rmgr (custom "Role desk": content.view, content.manage, roles.manage — given by an Admin),
 * padmin (platform owner). Hotel #2 owns a foreign role.
 */
final class RolesTest extends TestCase
{
    private const EDITOR_PERMS = ['content.view', 'content.manage', 'playlists.manage'];

    private static string $url;
    /** @var array<string, AdminSession> */
    private static array $s = [];
    private static array $users = [];
    private static array $roles = [];
    private static int $hotelB = 0;
    private static int $roomId = 0;
    /** Sandbox core/Features.php handling for the plan tests. */
    private static ?string $featuresBackup = null;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Access::forget();
        Auth::forgetPermissions();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        $role = static function (string $name, array $perms, int $hotel = 1): int {
            return (int) Tenant::run($hotel, static fn () => DB::insert('roles', [
                'name' => $name, 'description' => $name . ' role', 'permissions' => json_encode($perms),
                'base_level' => Roles::baseLevel($perms), 'is_system' => 0, 'created_at' => now(),
            ]));
        };
        self::$roles['editor'] = $role('Content editor', self::EDITOR_PERMS);
        self::$roles['udesk'] = $role('User desk', ['dashboard.view', 'rooms.view', 'content.view', 'users.manage']);
        self::$roles['rdesk'] = $role('Role desk', ['content.view', 'content.manage', 'roles.manage']);
        $mk = static function (string $name, string $role, ?int $hotel, ?int $roleId = null) use ($pw): int {
            return DB::insert('users', ['hotel_id' => $hotel, 'username' => $name, 'email' => $name . '@roles.test', 'full_name' => $name,
                'password_hash' => $pw, 'role' => $role, 'role_id' => $roleId, 'is_active' => 1, 'created_at' => now()]);
        };
        self::$users['boss'] = $mk('boss', 'super_admin', 1);
        self::$users['mgr'] = $mk('mgr', 'manager', 1);
        self::$users['editor'] = $mk('editor', Roles::baseLevel(self::EDITOR_PERMS), 1, self::$roles['editor']);
        self::$users['umgr'] = $mk('umgr', 'reception', 1, self::$roles['udesk']);
        self::$users['rmgr'] = $mk('rmgr', 'manager', 1, self::$roles['rdesk']);
        self::$users['padmin'] = $mk('padmin', 'platform_admin', null);

        self::$roomId = DB::insert('rooms', ['room_number' => 'R101', 'name' => 'Room R101', 'floor' => '1']);
        DB::insert('content_items', ['title' => 'RB-CONTENT', 'type' => 'announcement', 'body' => 'Hello', 'duration' => 10]);

        self::$hotelB = Hotels::create(['name' => 'Roles Hotel B', 'plan_id' => null]);
        self::$roles['foreign'] = $role('Foreign role', ['content.view'], self::$hotelB);
        Tenant::set(1);
        Cache::clear();
        Settings::flush();

        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreFeatures();
        self::$s = [];
        Auth::$planOverride = null;
        Auth::forgetPermissions();
        Tenant::set(1);
    }

    private static function as(string $u): AdminSession
    {
        return self::$s[$u] ??= new AdminSession(self::$url, $u);
    }

    private static function row(string $u): array
    {
        return DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$users[$u]]);
    }

    private static function rolePerms(int $id): array
    {
        $p = json_decode((string) DB::value('SELECT permissions FROM roles WHERE id = :id', ['id' => $id]), true);
        sort($p);
        return $p;
    }

    private static function nav(string $html): string
    {
        return preg_match('#<nav class="hc-nav.*?</nav>#s', $html, $m) ? $m[0] : '';
    }

    // ------------------------------------------------------------------ built-in roles unchanged

    public function testBuiltInRolesAreComputedAndUnchanged(): void
    {
        // Every hotel permission: built-in role rows behave exactly like the level comparison of before.
        foreach (Auth::hotelPermissions() as $perm => $min) {
            foreach (Roles::BUILT_IN as $r) {
                $expected = Auth::ROLE_LEVEL[$r] >= Auth::ROLE_LEVEL[$min];
                $this->assertSame($expected, Auth::roleCan($r, $perm), "$r / $perm");
                $this->assertSame($expected, Auth::userCan(['role' => $r, 'role_id' => null, 'hotel_id' => 1], $perm), "userCan $r / $perm");
            }
            $this->assertTrue(Auth::roleCan('platform_admin', $perm), "platform admin acts as Admin: $perm");
        }
        $this->assertSame('super_admin', Auth::rule('roles.manage'));
        $this->assertFalse(Auth::roleCan('manager', 'roles.manage'));
        $this->assertContains('roles.manage', Roles::builtInPermissions('super_admin'));
        $this->assertContains('content.manage', Roles::builtInPermissions('manager'));
        $this->assertNotContains('users.manage', Roles::builtInPermissions('manager'));
        $this->assertNotContains('content.view', Roles::builtInPermissions('reception'));
        $this->assertSame(array_keys(Auth::hotelPermissions()), Roles::builtInPermissions('super_admin'), 'Admin: every hotel permission');
        // A newly registered module permission is picked up by the built-in roles automatically.
        Auth::registerPermission('rbac_probe.manage', 'staff');
        try {
            $this->assertContains('rbac_probe.manage', Roles::builtInPermissions('staff'));
            $this->assertNotContains('rbac_probe.manage', Roles::builtInPermissions('reception'));
            $this->assertArrayHasKey('rbac_probe.manage', Roles::catalog());
        } finally {
            (new ReflectionProperty(Auth::class, 'extraPermissions'))->setValue(null, array_diff_key(
                (new ReflectionProperty(Auth::class, 'extraPermissions'))->getValue(), ['rbac_probe.manage' => 1]
            ));
        }
        // Base level of custom roles: lowest built-in role covering the list, never Admin.
        $this->assertSame('manager', Roles::baseLevel(self::EDITOR_PERMS));
        $this->assertSame('reception', Roles::baseLevel(['dashboard.view', 'guests.manage']));
        $this->assertSame('manager', Roles::baseLevel(['users.manage']));

        // Over HTTP: the built-in Manager is unchanged.
        $m = self::as('mgr');
        $this->assertSame(200, $m->get('content.php')[0]);
        $this->assertSame(200, $m->get('rooms.php')[0]);
        $this->assertSame(403, $m->get('users.php')[0]);
        $this->assertSame(403, $m->get('roles.php')[0]);
        $this->assertSame(200, self::as('boss')->get('roles.php')[0]);
    }

    // ------------------------------------------------------------------ custom role grants / denies

    public function testCustomRoleGrantsExactlyItsPagesAjaxAndNav(): void
    {
        $e = self::as('editor');
        foreach (['content.php', 'playlists.php', 'designer.php', 'apps.php', 'profile.php'] as $p) {
            $this->assertSame(200, $e->get($p)[0], "editor may open $p");
        }
        foreach (['rooms.php', 'groups.php', 'broadcast.php', 'schedule.php', 'users.php', 'roles.php', 'settings.php', 'logs.php', 'apk.php', 'billing.php', 'tickers.php', 'notices.php'] as $p) {
            $this->assertSame(403, $e->get($p)[0], "editor must not open $p");
        }
        // Even though users.role = manager (base level), manager-only permissions are not granted.
        $this->assertSame('manager', self::row('editor')['role']);
        // AJAX
        $this->assertSame(403, $e->ajax('dashboard_stats')[0]);
        $this->assertSame(403, $e->ajax('room_status')[0]);
        $this->assertSame(403, $e->ajax('schedule_events&start=2020-01-01&end=2040-01-01')[0]);
        [$s] = $e->ajax('preview_content&content_id=999999');
        $this->assertSame(404, $s, 'content.view passes, the item is missing');
        [$s] = $e->ajax('send_command', ['command' => 'RELOAD', 'target_type' => 'all']);
        $this->assertSame(403, $s);
        // Nav: only the role's items.
        [, , $html] = $e->get('content.php');
        $nav = self::nav($html);
        $this->assertNotSame('', $nav);
        foreach (['content.php', 'playlists.php', 'designer.php'] as $p) {
            $this->assertStringContainsString('admin/' . $p . '"', $nav, "nav shows $p");
        }
        foreach (['index.php', 'rooms.php', 'broadcast.php', 'users.php', 'roles.php', 'settings.php', 'schedule.php'] as $p) {
            $this->assertStringNotContainsString('admin/' . $p . '"', $nav, "nav hides $p");
        }
        $this->assertStringContainsString('Content editor', $html, 'header shows the custom role name');
        // Landing page after login: the first page the role may open.
        $jar = (string) tempnam(sys_get_temp_dir(), 'rl');
        [, , $login] = TestEnv::http('GET', self::$url . 'admin/login.php', null, [], $jar);
        preg_match('/name="_csrf" value="([^"]+)"/', $login, $m);
        [$s, , , $head] = TestEnv::http('POST', self::$url . 'admin/login.php', null, [], $jar, ['_csrf' => html_entity_decode($m[1] ?? ''), 'username' => 'editor', 'password' => 'Passw0rd!']);
        @unlink($jar);
        $this->assertSame(302, $s);
        // "Home" (index.php = dashboard) sends a role without the dashboard to its first page.
        [$s, , , $head] = $e->get('index.php');
        $this->assertSame(302, $s);
        $this->assertMatchesRegularExpression('#Location: \S*content\.php#i', $head);
    }

    public function testRoleChangesApplyOnTheNextRequestWithoutLogout(): void
    {
        $e = self::as('editor');
        $this->assertSame(200, $e->get('playlists.php')[0]);
        $b = self::as('boss');
        [$s] = $b->post('roles.php', ['op' => 'save', 'id' => self::$roles['editor'], 'name' => 'Content editor', 'description' => 'x', 'perms' => ['content.view', 'content.manage']]);
        $this->assertSame(302, $s);
        $this->assertSame(['content.manage', 'content.view'], self::rolePerms(self::$roles['editor']));
        $this->assertSame(403, $e->get('playlists.php')[0], 'permission removed immediately');
        $this->assertSame(200, $e->get('content.php')[0], 'session kept');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_update' AND entity_id = :id AND details LIKE '%-playlists.manage%'", ['id' => self::$roles['editor']]));
        $b->post('roles.php', ['op' => 'save', 'id' => self::$roles['editor'], 'name' => 'Content editor', 'description' => 'x', 'perms' => self::EDITOR_PERMS]);
        $this->assertSame(200, $e->get('playlists.php')[0]);
    }

    // ------------------------------------------------------------------ plan (Features) gate

    /** Put a Features stub into the SANDBOX (never the repo): permissions listed in storage/test_plan_disabled.json are off. */
    private static function stubFeatures(array $disabled): void
    {
        class_exists('Features'); // CLI keeps the real class (when there is one)
        $file = HC_ROOT . '/core/Features.php';
        if (self::$featuresBackup === null) {
            self::$featuresBackup = is_file($file) ? (string) file_get_contents($file) : '';
            if (self::$featuresBackup !== '') {
                $real = preg_replace('/\bfinal\s+class\s+Features\b/', 'class FeaturesReal', self::$featuresBackup, 1);
                file_put_contents(HC_ROOT . '/core/FeaturesReal.php', (string) $real);
            }
            $parent = self::$featuresBackup !== '' ? ' extends FeaturesReal' : '';
            $fallback = self::$featuresBackup !== '' ? 'return parent::permissionEnabled($perm);' : 'return true;';
            $extra = self::$featuresBackup !== '' ? '' : 'public static function all(): array { return []; } public static function enabled(string $k): bool { return true; }';
            file_put_contents($file, "<?php\ndeclare(strict_types=1);\n/** RolesTest stub (sandbox only). */\nfinal class Features$parent\n{\n"
                . "    public static function permissionEnabled(string \$perm): bool\n    {\n"
                . "        \$f = HC_ROOT . '/storage/test_plan_disabled.json';\n"
                . "        \$off = is_file(\$f) ? (array) json_decode((string) file_get_contents(\$f), true) : [];\n"
                . "        if (in_array(\$perm, \$off, true)) {\n            return false;\n        }\n        $fallback\n    }\n    $extra\n}\n");
        }
        file_put_contents(HC_ROOT . '/storage/test_plan_disabled.json', json_encode(array_values($disabled)));
        self::waitForOpcache();
    }

    /** The built-in web server (cli-server) uses OPcache with revalidate_freq = 2 s: wait until changed files are re-read. */
    private static function waitForOpcache(): void
    {
        usleep(2_600_000);
    }

    private static function restoreFeatures(): void
    {
        if (self::$featuresBackup === null) {
            return;
        }
        $file = HC_ROOT . '/core/Features.php';
        if (self::$featuresBackup === '') {
            @unlink($file);
        } else {
            file_put_contents($file, self::$featuresBackup);
        }
        @unlink(HC_ROOT . '/core/FeaturesReal.php');
        @unlink(HC_ROOT . '/storage/test_plan_disabled.json');
        self::$featuresBackup = null;
        self::waitForOpcache();
    }

    public function testPlanDisabledPermissionsAreHiddenAndDeniedEvenWhenStored(): void
    {
        // CLI: the plan hook.
        Auth::$planOverride = static fn (string $p): ?bool => $p === 'playlists.manage' ? false : null;
        try {
            $this->assertFalse(Auth::planAllows('playlists.manage'));
            $this->assertNotContains('playlists.manage', Roles::visiblePermissions());
            foreach (Roles::matrix() as $g) {
                $this->assertArrayNotHasKey('playlists.manage', $g['perms'], 'hidden in the matrix');
            }
            $editor = self::row('editor');
            $this->assertFalse(Auth::userCan($editor, 'playlists.manage'), 'stored in the role, but not in the plan');
            $this->assertTrue(Auth::userCan($editor, 'content.manage'));
            $this->assertFalse(Auth::userCan(['role' => 'super_admin', 'hotel_id' => 1], 'playlists.manage'), 'not even the Admin');
            // Saving keeps the hidden permission (it comes back with a bigger plan).
            [$final] = Roles::sanitize(['content.view'], self::EDITOR_PERMS);
            $this->assertContains('playlists.manage', $final);
        } finally {
            Auth::$planOverride = null;
        }

        // HTTP: core/Features.php (stubbed in the sandbox) switches the permission off for the plan.
        self::stubFeatures(['playlists.manage']);
        try {
            [$s, , $html] = self::as('boss')->get('roles.php?action=edit&id=' . self::$roles['editor']);
            $this->assertSame(200, $s);
            $this->assertStringNotContainsString('value="playlists.manage"', $html, 'hidden, not just disabled');
            $this->assertStringContainsString('value="content.manage"', $html);
            [, , $html] = self::as('boss')->get('roles.php?action=new');
            $this->assertStringNotContainsString('value="playlists.manage"', $html);
            $this->assertSame(403, self::as('editor')->get('playlists.php')[0], 'stored permission denied by the plan');
            $this->assertSame(403, self::as('boss')->get('playlists.php')[0], 'Admin has only the plan\'s permissions');
            $this->assertStringNotContainsString('admin/playlists.php"', self::nav(self::as('editor')->get('content.php')[2]));
            // Saving the role through the UI keeps the hidden permission stored.
            self::as('boss')->post('roles.php', ['op' => 'save', 'id' => self::$roles['editor'], 'name' => 'Content editor', 'description' => 'x', 'perms' => ['content.view', 'content.manage']]);
            $this->assertContains('playlists.manage', self::rolePerms(self::$roles['editor']));
        } finally {
            self::restoreFeatures();
        }
        $this->assertSame(200, self::as('editor')->get('playlists.php')[0], 'back with the plan');
        $this->assertSame(200, self::as('boss')->get('playlists.php')[0]);
    }

    // ------------------------------------------------------------------ escalation

    public function testUserManagerCannotEscalate(): void
    {
        $u = self::as('umgr');
        $this->assertSame(200, $u->get('users.php')[0]);
        [, , $html] = $u->get('users.php?action=new');
        $this->assertStringNotContainsString('value="super_admin"', $html, 'Admin role not offered');
        $this->assertStringNotContainsString('value="manager"', $html, 'Manager has rights umgr lacks');
        $this->assertStringContainsString('value="role:' . self::$roles['udesk'] . '"', $html, 'own role can be given');
        $base = ['op' => 'save', 'id' => 0, 'full_name' => 'X', 'language' => 'en', 'is_active' => '1', 'password' => 'Secret123', 'password_confirm' => 'Secret123'];
        foreach (['super_admin' => 'esc1', 'manager' => 'esc2', 'role:' . self::$roles['editor'] => 'esc3', 'role:' . self::$roles['rdesk'] => 'esc4'] as $role => $name) {
            $u->post('users.php', $base + ['username' => $name, 'email' => $name . '@roles.test', 'role' => $role]);
            $this->assertFalse((bool) DB::value('SELECT id FROM users WHERE username = :u', ['u' => $name]), "umgr must not create a $role user");
        }
        // A role within umgr's own rights works.
        $u->post('users.php', $base + ['username' => 'okdesk', 'email' => 'okdesk@roles.test', 'role' => 'role:' . self::$roles['udesk']]);
        $ok = DB::one('SELECT * FROM users WHERE username = :u', ['u' => 'okdesk']);
        $this->assertNotNull($ok);
        $this->assertSame(self::$roles['udesk'], (int) $ok['role_id']);
        $this->assertSame('manager', $ok['role'], 'base level of the role (users.manage is a manager-level permission)');

        // The Admin cannot be edited, reset, unlocked or deleted by umgr.
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => self::$users['boss']]);
        [$s, , , $head] = $u->get('users.php?action=edit&id=' . self::$users['boss']);
        $this->assertSame(302, $s);
        $u->post('users.php', ['op' => 'save', 'id' => self::$users['boss'], 'username' => 'boss', 'email' => 'boss@roles.test', 'role' => 'role:' . self::$roles['udesk'], 'is_active' => '1', 'password' => 'Hacked123', 'password_confirm' => 'Hacked123']);
        $this->assertSame($hash, (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => self::$users['boss']]));
        $this->assertSame('super_admin', self::row('boss')['role']);
        $u->post('users.php', ['op' => 'delete', 'id' => self::$users['boss']]);
        $this->assertNotNull(DB::one('SELECT id FROM users WHERE id = :id', ['id' => self::$users['boss']]));
        $u->post('users.php', ['op' => 'delete', 'id' => self::$users['mgr']]);
        $this->assertNotNull(DB::one('SELECT id FROM users WHERE id = :id', ['id' => self::$users['mgr']]), 'manager has more rights than umgr');

        // Own role: cannot be changed.
        $u->post('users.php', ['op' => 'save', 'id' => self::$users['umgr'], 'username' => 'umgr', 'email' => 'umgr@roles.test', 'role' => 'super_admin', 'is_active' => '1']);
        $this->assertSame(self::$roles['udesk'], (int) self::row('umgr')['role_id']);
        $this->assertSame('reception', self::row('umgr')['role']);
        // No roles page for umgr.
        $this->assertSame(403, $u->get('roles.php')[0]);
    }

    public function testRoleManagerCannotEscalateThroughRoles(): void
    {
        $r = self::as('rmgr');
        $this->assertSame(200, $r->get('roles.php')[0]);
        // Own role: no edit page, no save.
        [$s] = $r->get('roles.php?action=edit&id=' . self::$roles['rdesk']);
        $this->assertSame(302, $s);
        $r->post('roles.php', ['op' => 'save', 'id' => self::$roles['rdesk'], 'name' => 'Role desk', 'perms' => ['content.view', 'content.manage', 'roles.manage', 'users.manage', 'settings.manage']]);
        $this->assertSame(['content.manage', 'content.view', 'roles.manage'], self::rolePerms(self::$roles['rdesk']));
        $r->post('roles.php', ['op' => 'delete', 'id' => self::$roles['rdesk']]);
        $this->assertNotNull(DB::one('SELECT id FROM roles WHERE id = :id', ['id' => self::$roles['rdesk']]));
        // New role: only permissions rmgr holds; never roles.manage.
        [$s] = $r->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'Sneaky', 'perms' => ['content.view', 'users.manage', 'roles.manage', 'settings.manage']]);
        $this->assertSame(302, $s);
        $id = (int) DB::value("SELECT id FROM roles WHERE hotel_id = 1 AND name = 'Sneaky'");
        $this->assertGreaterThan(0, $id);
        $this->assertSame(['content.view'], self::rolePerms($id));
        [, , $html] = $r->get('roles.php?action=new');
        $this->assertMatchesRegularExpression('/value="users\.manage"[^>]*disabled/', $html, 'locked for non-Admins');
        $this->assertMatchesRegularExpression('/value="roles\.manage"[^>]*disabled/', $html);
        // Editing another role cannot add rights rmgr lacks; existing ones it lacks are kept.
        $r->post('roles.php', ['op' => 'save', 'id' => self::$roles['udesk'], 'name' => 'User desk', 'perms' => ['content.view', 'content.manage', 'settings.manage']]);
        $this->assertSame(['content.manage', 'content.view', 'dashboard.view', 'rooms.view', 'users.manage'], self::rolePerms(self::$roles['udesk']));
        $r->post('roles.php', ['op' => 'save', 'id' => self::$roles['udesk'], 'name' => 'User desk', 'perms' => ['content.view', 'dashboard.view', 'rooms.view', 'users.manage']]);
        // Delete with reassignment to Admin: refused.
        $victim = DB::insert('users', ['hotel_id' => 1, 'username' => 'sneaky1', 'email' => 'sneaky1@roles.test', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'staff', 'role_id' => $id, 'is_active' => 1, 'created_at' => now()]);
        $r->post('roles.php', ['op' => 'delete', 'id' => $id, 'reassign' => 'super_admin']);
        $this->assertSame($id, (int) DB::value('SELECT role_id FROM users WHERE id = :id', ['id' => $victim]));
        $this->assertNotNull(DB::one('SELECT id FROM roles WHERE id = :id', ['id' => $id]));
        DB::query('DELETE FROM users WHERE id = :id', ['id' => $victim]);
        DB::query('DELETE FROM roles WHERE id = :id', ['id' => $id]);

        // The Admin may give roles.manage.
        self::as('boss')->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'Deputy', 'perms' => ['content.view', 'roles.manage']]);
        $dep = (int) DB::value("SELECT id FROM roles WHERE hotel_id = 1 AND name = 'Deputy'");
        $this->assertSame(['content.view', 'roles.manage'], self::rolePerms($dep));
        DB::query('DELETE FROM roles WHERE id = :id', ['id' => $dep]);
        // Built-in roles cannot be edited or deleted (they are not rows).
        self::as('boss')->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'Manager', 'perms' => ['content.view']]);
        $this->assertFalse((bool) DB::value("SELECT id FROM roles WHERE hotel_id = 1 AND name = 'Manager'"), 'built-in name refused');
    }

    // ------------------------------------------------------------------ last Admin

    public function testAtLeastOneActiveAdminRemains(): void
    {
        $p = self::as('padmin');
        [$s] = $p->post('platform_hotels.php', ['op' => 'enter', 'id' => 1]);
        $this->assertSame(302, $s);
        $this->assertSame(200, $p->get('users.php')[0]);
        // boss is the only active Admin of hotel 1.
        $p->post('users.php', ['op' => 'save', 'id' => self::$users['boss'], 'username' => 'boss', 'email' => 'boss@roles.test', 'role' => 'role:' . self::$roles['editor'], 'is_active' => '1']);
        $this->assertSame('super_admin', self::row('boss')['role']);
        $this->assertNull(self::row('boss')['role_id']);
        $p->post('users.php', ['op' => 'save', 'id' => self::$users['boss'], 'username' => 'boss', 'email' => 'boss@roles.test', 'role' => 'super_admin']);
        $this->assertSame(1, (int) self::row('boss')['is_active'], 'cannot disable the last Admin');
        $p->post('users.php', ['op' => 'delete', 'id' => self::$users['boss']]);
        $this->assertNotNull(DB::one('SELECT id FROM users WHERE id = :id', ['id' => self::$users['boss']]));
        // With a second Admin the first one may get a custom role (and back).
        $p->post('users.php', ['op' => 'save', 'id' => 0, 'username' => 'boss2', 'email' => 'boss2@roles.test', 'role' => 'super_admin', 'is_active' => '1', 'password' => 'Passw0rd!', 'password_confirm' => 'Passw0rd!']);
        $b2 = (int) DB::value("SELECT id FROM users WHERE username = 'boss2'");
        $this->assertGreaterThan(0, $b2);
        $p->post('users.php', ['op' => 'save', 'id' => $b2, 'username' => 'boss2', 'email' => 'boss2@roles.test', 'role' => 'role:' . self::$roles['editor'], 'is_active' => '1']);
        $row = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $b2]);
        $this->assertSame(self::$roles['editor'], (int) $row['role_id']);
        $this->assertSame('manager', $row['role'], 'base level kept in users.role');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'user_role' AND entity_id = :id", ['id' => $b2]));
        DB::query('DELETE FROM users WHERE id = :id', ['id' => $b2]);
    }

    // ------------------------------------------------------------------ tenancy / CSRF / delete

    public function testForeignRoleIdsAre404(): void
    {
        $b = self::as('boss');
        $f = self::$roles['foreign'];
        $this->assertSame(404, $b->get('roles.php?action=edit&id=' . $f)[0]);
        $this->assertSame(404, $b->get('roles.php?action=delete&id=' . $f)[0]);
        $this->assertSame(404, $b->get('roles.php?action=copy&from=role:' . $f)[0]);
        $this->assertSame(404, $b->post('roles.php', ['op' => 'save', 'id' => $f, 'name' => 'Stolen', 'perms' => ['content.view', 'users.manage']])[0]);
        $this->assertSame(404, $b->post('roles.php', ['op' => 'delete', 'id' => $f])[0]);
        $this->assertSame(['content.view'], self::rolePerms($f));
        $this->assertSame('Foreign role', DB::value('SELECT name FROM roles WHERE id = :id', ['id' => $f]));
        [$s] = $b->post('users.php', ['op' => 'save', 'id' => 0, 'username' => 'foreignrole', 'email' => 'foreignrole@roles.test', 'role' => 'role:' . $f,
            'is_active' => '1', 'password' => 'Passw0rd!', 'password_confirm' => 'Passw0rd!']);
        $this->assertSame(404, $s);
        $this->assertFalse((bool) DB::value("SELECT id FROM users WHERE username = 'foreignrole'"));
        [, , $html] = $b->get('roles.php');
        $this->assertStringNotContainsString('Foreign role', $html);
        // A role of hotel B never grants anything in hotel A.
        $this->assertFalse(Auth::userCan(['role' => 'staff', 'role_id' => $f, 'hotel_id' => 1], 'content.view'));
    }

    public function testCsrfIsRequired(): void
    {
        $b = self::as('boss');
        [$s] = TestEnv::http('POST', self::$url . 'admin/roles.php', null, [], $b->jar, ['op' => 'save', 'name' => 'NoCsrf', 'perms[0]' => 'content.view']);
        $this->assertSame(419, $s);
        [$s] = TestEnv::http('POST', self::$url . 'admin/roles.php', null, [], $b->jar, ['_csrf' => 'wrong', 'op' => 'delete', 'id' => (string) self::$roles['editor']]);
        $this->assertSame(419, $s);
        $this->assertFalse((bool) DB::value("SELECT id FROM roles WHERE name = 'NoCsrf'"));
        $this->assertNotNull(DB::one('SELECT id FROM roles WHERE id = :id', ['id' => self::$roles['editor']]));
    }

    public function testCopyCreateAndDeleteWithReassignment(): void
    {
        $b = self::as('boss');
        [$s, , $html] = $b->get('roles.php');
        $this->assertSame(200, $s);
        foreach (['super_admin', 'manager', 'staff', 'reception'] as $r) {
            $this->assertStringContainsString('from=' . $r, $html, "Copy button for $r");
        }
        $this->assertStringContainsString('Content editor', $html);
        [$s, , $html] = $b->get('roles.php?action=view&role=manager');
        $this->assertSame(200, $s);
        $this->assertMatchesRegularExpression('/value="content\.manage"[^>]*checked[^>]*disabled/', $html, 'read-only matrix');
        // Copy the Manager: same permissions, a new custom role.
        [$s, , $html] = $b->get('roles.php?action=copy&from=manager');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Copy of Manager', $html);
        $this->assertStringContainsString('js-group-all', $html, 'select all toggles');
        $this->assertStringContainsString('id="rolePreview"', $html, 'preview');
        $this->assertMatchesRegularExpression('/value="content\.manage"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="users\.manage"[^>]*checked/', $html);
        $b->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'Night manager', 'description' => 'copy', 'perms' => Roles::builtInPermissions('manager')]);
        $nm = (int) DB::value("SELECT id FROM roles WHERE hotel_id = 1 AND name = 'Night manager'");
        $this->assertGreaterThan(0, $nm);
        $mp = Roles::builtInPermissions('manager');
        sort($mp);
        $this->assertSame($mp, self::rolePerms($nm));
        $this->assertSame('manager', DB::value('SELECT base_level FROM roles WHERE id = :id', ['id' => $nm]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_create' AND entity_id = :id", ['id' => $nm]));
        // Duplicate name refused.
        $b->post('roles.php', ['op' => 'save', 'id' => 0, 'name' => 'Night manager', 'perms' => ['content.view']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM roles WHERE hotel_id = 1 AND name = 'Night manager'"));

        // Assign a user, see the role in the users list with counts.
        $uid = DB::insert('users', ['hotel_id' => 1, 'username' => 'night1', 'email' => 'night1@roles.test', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'manager', 'role_id' => $nm, 'is_active' => 1, 'created_at' => now()]);
        [, , $html] = $b->get('users.php');
        $this->assertStringContainsString('Night manager', $html);
        $this->assertStringContainsString('roles.php', $html);
        [, , $html] = $b->get('users.php?action=new');
        $this->assertStringContainsString('value="role:' . $nm . '"', $html);
        $this->assertStringContainsString('value="super_admin"', $html);
        $this->assertStringContainsString('name="tv_access"', $html, 'screen access UI kept');
        [, , $html] = $b->get('users.php?action=edit&id=' . $uid);
        $this->assertMatchesRegularExpression('/value="role:' . $nm . '" selected/', $html);

        // Delete: refused while users have it, unless they are moved.
        $b->post('roles.php', ['op' => 'delete', 'id' => $nm]);
        $this->assertNotNull(DB::one('SELECT id FROM roles WHERE id = :id', ['id' => $nm]));
        [$s, , $html] = $b->get('roles.php?action=delete&id=' . $nm);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('night1', $html);
        $b->post('roles.php', ['op' => 'delete', 'id' => $nm, 'reassign' => 'role:' . self::$roles['editor']]);
        $this->assertNull(DB::one('SELECT id FROM roles WHERE id = :id', ['id' => $nm]));
        $row = DB::one('SELECT role, role_id FROM users WHERE id = :id', ['id' => $uid]);
        $this->assertSame(self::$roles['editor'], (int) $row['role_id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'role_delete' AND entity_id = :id", ['id' => $nm]));
        DB::query('DELETE FROM users WHERE id = :id', ['id' => $uid]);
    }

    public function testScreenAccessStillWorksForCustomRoles(): void
    {
        // A custom-role user can be limited to some screens (users.role keeps a limitable base level).
        $b = self::as('boss');
        $b->post('users.php', ['op' => 'save', 'id' => self::$users['editor'], 'username' => 'editor', 'email' => 'editor@roles.test', 'full_name' => 'editor',
            'role' => 'role:' . self::$roles['editor'], 'language' => 'en', 'is_active' => '1', 'tv_access' => 'some', 'access_rooms' => [self::$roomId]]);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => self::$users['editor']]));
        Access::forget();
        Access::$userOverride = self::row('editor');
        try {
            $this->assertTrue(Access::restricted());
            $this->assertSame([self::$roomId], Access::roomIds());
        } finally {
            Access::$userOverride = null;
            Access::forget();
        }
        $b->post('users.php', ['op' => 'save', 'id' => self::$users['editor'], 'username' => 'editor', 'email' => 'editor@roles.test', 'full_name' => 'editor',
            'role' => 'role:' . self::$roles['editor'], 'language' => 'en', 'is_active' => '1', 'tv_access' => 'all']);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_access WHERE user_id = :u', ['u' => self::$users['editor']]));
        $this->assertSame(self::$roles['editor'], (int) self::row('editor')['role_id']);
    }

    // ------------------------------------------------------------------ crawl

    public function testCrawlEveryAdminPageAsContentEditor(): void
    {
        self::$s = []; // fresh sessions (role changes above revoked some)
        $perms = self::EDITOR_PERMS;
        $e = self::as('editor');
        foreach (glob(HC_ROOT . '/admin/*.php') ?: [] as $f) {
            $page = basename($f);
            if (in_array($page, ['logout.php', 'ajax.php', 'ajax_update.php', 'login.php'], true)) {
                continue;
            }
            $src = (string) file_get_contents($f);
            $perm = preg_match("/Auth::require\\('([a-z0-9_.]+)'\\)/", $src, $m) ? $m[1] : null;
            if ($page === 'content.php') {
                $perm = 'content.view';
            }
            [$s, , $body] = $e->get($page);
            $this->assertFalse(TestEnv::hasPhpError($body), "PHP error on $page");
            if ($perm === null) {
                $this->assertContains($s, [200, 302, 403, 404], $page);
                continue;
            }
            $allowed = in_array($perm, $perms, true) || (is_array(Auth::rule($perm)) && in_array('manager', Auth::rule($perm), true));
            if ($allowed) {
                $this->assertContains($s, [200, 302], "$page ($perm) must be allowed");
            } elseif ($page === 'index.php') {
                $this->assertSame(302, $s, 'home redirects to the first allowed page');
            } else {
                $this->assertContains($s, [403, 404], "$page ($perm) must be denied");
            }
        }
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    // ------------------------------------------------------------------ translations

    public function testTranslationsCoverTheNewStrings(): void
    {
        $files = ['admin/roles.php', 'core/Roles.php', 'admin/users.php', 'admin/partials/nav.d/19_roles.php'];
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        $missing = [];
        foreach ($files as $f) {
            preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                if (!isset($gu[$key])) {
                    $missing[] = "gu $f: $key";
                }
            }
        }
        foreach ((require HC_ROOT . '/lang/gu_roles.php') as $key => $v) {
            if (!isset($hi[$key])) {
                $missing[] = "hi: $key";
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }
}
