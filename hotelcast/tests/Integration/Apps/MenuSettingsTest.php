<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9 Menu settings (docs/modules/menu_settings.md, core/NavMenu.php): the customer's default sidebar (Settings →
 * Menu, settings.manage) and the personal menu of every user (Profile → My menu) — also in the Super Admin console.
 * Order / hidden items are saved with CSRF, applied to the sidebar (user override → business default → built-in),
 * required items can't be hidden, hidden pages still open by URL, the config never shows items outside the plan
 * or the user's permissions, unknown keys are ignored and items missing from a saved order keep their place.
 */
final class MenuSettingsTest extends TestCase
{
    private static string $url;
    private static int $hid = 0;
    private static array $sessions = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        $res = DB::insert('resellers', ['name' => 'Menu Reseller', 'status' => 'active']);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => $res, 'username' => 'msres', 'email' => 'msres@res.test', 'full_name' => 'Res', 'password_hash' => $pw, 'role' => 'reseller']);
        DB::insert('users', ['hotel_id' => null, 'username' => 'msroot', 'email' => 'msroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        // Pro without the ads module: "Ads & Sponsors" must never appear, whatever is saved.
        $keys = array_values(array_diff(Features::presetKeys('Pro'), ['ads', 'marketplace']));
        $plan = DB::insert('plans', ['name' => 'MS plan', 'price_per_tv_month' => 10, 'features' => Features::encodePlanKeys($keys), 'created_at' => now()]);
        self::$hid = Hotels::create(['name' => 'Menu Mart', 'city' => 'Rajkot', 'plan_id' => $plan]);
        Hotels::createHotelUser(self::$hid, ['username' => 'msboss', 'email' => 'boss@menu.test', 'password' => 'Passw0rd!'], 'super_admin');
        Hotels::createHotelUser(self::$hid, ['username' => 'msstaff', 'email' => 'staff@menu.test', 'password' => 'Passw0rd!'], 'staff');
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        NavMenu::setBusiness(self::$hid, null);
        DB::query('UPDATE users SET menu_prefs = NULL');
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    /** Visible sidebar keys (data-nav) of a page. */
    private static function navKeys(string $html): array
    {
        preg_match_all('/data-nav="([a-z0-9_]+)"/', $html, $m);
        return $m[1];
    }

    /** Keys in the "Hidden items" group. */
    private static function hiddenKeys(string $html): array
    {
        preg_match_all('/data-nav-hidden="([a-z0-9_]+)"/', $html, $m);
        return $m[1];
    }

    /** Keys listed in the menu editor, in order. */
    private static function editorKeys(string $html): array
    {
        preg_match_all('/<li class="hc-menu-item[^"]*" draggable="true" data-key="([a-z0-9_]+)"/', $html, $m);
        return $m[1];
    }

    private static function sidebar(string $user, string $page = 'profile.php'): string
    {
        [$s, , $html] = self::as($user)->get($page);
        self::assertSame(200, $s, "$page as $user");
        self::assertFalse(TestEnv::hasPhpError($html), $page);
        return $html;
    }

    private static function business(): ?array
    {
        Settings::flush();
        return NavMenu::business(self::$hid);
    }

    /** Post a menu: $order (keys), $hide (keys switched off), $more. */
    private static function save(string $user, string $scope, array $order, array $hide = [], bool $more = true): array
    {
        $fields = ['op' => 'save', 'scope' => $scope, 'order' => $order, 'show' => array_values(array_diff($order, $hide))];
        if ($more) {
            $fields['more'] = 1;
        }
        return self::as($user)->post('menu_settings.php?scope=' . $scope, $fields);
    }

    // ------------------------------------------------------------------ business default

    public function testBusinessDefaultReordersAndHidesForAllUsers(): void
    {
        $boss = self::as('msboss');
        [$s, , $html] = $boss->get('settings.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('data-menu-settings-link', $html, 'Settings links to the menu page');
        [$s, , $html] = $boss->get('menu_settings.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('data-menu-form data-scope="business"', $html);
        $editor = self::editorKeys($html);
        $default = self::navKeys(self::sidebar('msboss'));
        $this->assertSame($default, $editor, 'the editor lists exactly the sidebar items, in the built-in order');
        $this->assertSame('index', $default[0]);
        $this->assertNotContains('ads', $editor, 'modules outside the plan are not listed');
        $this->assertMatchesRegularExpression('/data-key="index" data-required="1"/', $html);

        // CSRF: a post without the token changes nothing.
        $order = array_merge(['broadcast'], array_values(array_diff($default, ['broadcast'])));
        [$s] = TestEnv::http('POST', self::$url . 'admin/menu_settings.php?scope=business', null, [], $boss->jar,
            ['op' => 'save', 'scope' => 'business', 'order[0]' => 'broadcast', 'show[0]' => 'broadcast']);
        $this->assertContains($s, [400, 403, 419]);
        $this->assertNull(self::business());

        // Broadcast to the top, three items hidden.
        [$s] = self::save('msboss', 'business', $order, ['logs', 'apk', 'power']);
        $this->assertSame(302, $s);
        $cfg = self::business();
        $this->assertSame($order, $cfg['order']);
        $this->assertEqualsCanonicalizing(['logs', 'apk', 'power'], $cfg['hidden']);
        $this->assertTrue($cfg['more']);

        $html = self::sidebar('msboss');
        $keys = self::navKeys($html);
        $this->assertSame('broadcast', $keys[0], 'Broadcast is first');
        foreach (['logs', 'apk', 'power'] as $k) {
            $this->assertNotContains($k, $keys);
        }
        $this->assertSame(['power', 'apk', 'logs'], self::hiddenKeys($html), 'hidden items in the More group, in menu order');
        $this->assertStringContainsString('Hidden items (3)', $html);
        $this->assertStringContainsString('data-nav-more-group', $html);

        // Staff (fewer permissions) get the same order for what they may see; nothing beyond their rights.
        $staff = self::navKeys(self::sidebar('msstaff'));
        $this->assertSame('broadcast', $staff[0]);
        $this->assertNotContains('users', $staff);
        $this->assertNotContains('users', self::hiddenKeys(self::sidebar('msstaff')));
        $this->assertNotContains('logs', $staff);

        // Activity logged; Gujarati page renders translated.
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'menu_settings' AND hotel_id = :h", ['h' => self::$hid]));
        $boss->ajax('set_language', ['lang' => 'gu']);
        [, , $html] = $boss->get('menu_settings.php');
        $this->assertStringContainsString('મેનુ સેટિંગ્સ', $html);
        $this->assertStringContainsString('છુપાવેલી આઇટમ (3)', $html);
        $boss->ajax('set_language', ['lang' => 'en']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testMoreGroupCanBeSwitchedOffAndHiddenPagesStillOpenByUrl(): void
    {
        $default = self::navKeys(self::sidebar('msboss'));
        self::save('msboss', 'business', $default, ['logs', 'users'], false);
        $this->assertFalse(self::business()['more']);
        $html = self::sidebar('msboss');
        $this->assertStringNotContainsString('data-nav-more-group', $html);
        $this->assertSame([], self::hiddenKeys($html));
        $this->assertNotContains('logs', self::navKeys($html));
        // Permissions unchanged: the hidden page opens by URL (and is shown in the More group when it is on).
        [$s, , $html] = self::as('msboss')->get('logs.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        [$s] = self::as('msboss')->get('users.php');
        $this->assertSame(200, $s);
        // Staff still can't open what they never could.
        [$s] = self::as('msstaff')->get('users.php');
        $this->assertSame(403, $s);

        self::save('msboss', 'business', $default, ['logs']);
        [, , $html] = self::as('msboss')->get('logs.php');
        $this->assertMatchesRegularExpression('/<details class="hc-nav-more" data-nav-more-group open>/', $html, 'More group opens on the hidden active page');
        $this->assertMatchesRegularExpression('/class="nav-link active" href="[^"]*logs\.php" data-nav-hidden="logs"/', $html);
    }

    public function testRequiredItemsCannotBeHiddenAndResetRestoresDefault(): void
    {
        $default = self::navKeys(self::sidebar('msboss'));
        self::save('msboss', 'business', array_reverse($default), ['index', 'settings', 'rooms']);
        $cfg = self::business();
        $this->assertSame(['rooms'], $cfg['hidden'], 'Dashboard / Settings are never stored as hidden');
        $keys = self::navKeys(self::sidebar('msboss'));
        $this->assertContains('index', $keys);
        $this->assertContains('settings', $keys);
        $this->assertNotContains('rooms', $keys);
        $this->assertSame(array_values(array_diff(array_reverse($default), ['rooms'])), $keys, 'reverse order applied');
        // A stored config that hides required items (edited by hand / older version) is still ignored.
        NavMenu::setBusiness(self::$hid, ['order' => [], 'hidden' => ['index', 'settings'], 'more' => false]);
        $keys = self::navKeys(self::sidebar('msboss'));
        $this->assertContains('index', $keys);
        $this->assertContains('settings', $keys);

        [$s] = self::as('msboss')->post('menu_settings.php?scope=business', ['op' => 'reset', 'scope' => 'business']);
        $this->assertSame(302, $s);
        $this->assertNull(self::business());
        $this->assertSame($default, self::navKeys(self::sidebar('msboss')));
    }

    // ------------------------------------------------------------------ personal menu

    public function testStaffCannotChangeBusinessDefaultButCanSetPersonalMenu(): void
    {
        $staff = self::as('msstaff');
        [$s] = $staff->get('menu_settings.php?scope=business');
        $this->assertSame(403, $s);
        $default = self::navKeys(self::sidebar('msstaff'));
        [$s] = $staff->post('menu_settings.php?scope=business', ['op' => 'save', 'scope' => 'business', 'order' => array_reverse($default), 'show' => $default]);
        $this->assertSame(403, $s);
        [$s] = $staff->post('menu_settings.php?scope=business', ['op' => 'reset', 'scope' => 'business']);
        $this->assertSame(403, $s);
        $this->assertNull(self::business());

        // Without a scope, staff land on their own menu; Profile links to it.
        [$s, , $html] = $staff->get('menu_settings.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('data-scope="me"', $html);
        $this->assertStringNotContainsString('data-menu-scopes', $html, 'no business tab without settings.manage');
        $this->assertStringContainsString('data-profile-my-menu', self::sidebar('msstaff'));
        $this->assertSame($default, self::editorKeys($html));

        $mine = array_reverse($default);
        [$s] = self::save('msstaff', 'me', $mine, [$default[1]]);
        $this->assertSame(302, $s);
        $this->assertSame(array_values(array_diff($mine, [$default[1]])), self::navKeys(self::sidebar('msstaff')));
        // Only for this user: the boss and the business default are untouched.
        $this->assertNull(self::business());
        $this->assertSame('index', self::navKeys(self::sidebar('msboss'))[0]);
    }

    public function testUserOverrideWinsOverBusinessDefaultAndCanGoBack(): void
    {
        $default = self::navKeys(self::sidebar('msboss'));
        $biz = array_merge(['broadcast'], array_values(array_diff($default, ['broadcast'])));
        self::save('msboss', 'business', $biz, ['logs']);
        // My menu starts from the business default.
        [, , $html] = self::as('msboss')->get('menu_settings.php?scope=me');
        $this->assertStringContainsString('data-menu-source="business"', $html);
        $this->assertSame($biz, self::editorKeys($html));

        $mine = array_merge(['content'], array_values(array_diff($default, ['content'])));
        self::save('msboss', 'me', $mine);
        $keys = self::navKeys(self::sidebar('msboss'));
        $this->assertSame('content', $keys[0], 'the personal menu wins');
        $this->assertContains('logs', $keys, 'the personal menu shows logs although the business hides it');
        [, , $html] = self::as('msboss')->get('menu_settings.php?scope=me');
        $this->assertStringContainsString('data-menu-source="user"', $html);
        $this->assertStringContainsString('Use my business&#039;s default', $html);
        // Staff still see the business default.
        $this->assertSame('broadcast', self::navKeys(self::sidebar('msstaff'))[0]);

        // "Use my business's default"
        [$s] = self::as('msboss')->post('menu_settings.php?scope=me', ['op' => 'reset', 'scope' => 'me']);
        $this->assertSame(302, $s);
        $this->assertNull(DB::value("SELECT menu_prefs FROM users WHERE username = 'msboss'"));
        $keys = self::navKeys(self::sidebar('msboss'));
        $this->assertSame('broadcast', $keys[0]);
        $this->assertNotContains('logs', $keys);
    }

    // ------------------------------------------------------------------ never widen access

    public function testItemsOutsidePlanOrPermissionNeverAppearAndUnknownKeysAreIgnored(): void
    {
        $default = self::navKeys(self::sidebar('msboss'));
        // Posted junk / foreign keys are dropped when saving.
        [$s] = self::save('msboss', 'business', array_merge(['ads', 'platform_hotels', 'evil_key', '<script>', 'broadcast'], $default), ['ads']);
        $this->assertSame(302, $s);
        $cfg = self::business();
        $this->assertSame('broadcast', $cfg['order'][0]);
        foreach (['ads', 'platform_hotels', 'evil_key', '<script>'] as $k) {
            $this->assertNotContains($k, $cfg['order']);
            $this->assertNotContains($k, $cfg['hidden']);
        }
        // A stored config naming items outside the plan / the platform / the user's rights shows none of them.
        NavMenu::setBusiness(self::$hid, ['order' => ['ads', 'platform_hotels', 'platform_settings', 'users', 'roles', 'nope', 'broadcast'], 'hidden' => ['ads', 'users', 'roles'], 'more' => true]);
        $boss = self::sidebar('msboss');
        $all = array_merge(self::navKeys($boss), self::hiddenKeys($boss));
        $this->assertNotContains('ads', $all, 'outside the plan');
        $this->assertNotContains('platform_hotels', $all);
        $this->assertNotContains('platform_settings', $all);
        $this->assertSame('index', self::navKeys($boss)[0], 'Dashboard (not in the saved order) keeps its built-in first place');
        $this->assertContains('broadcast', self::navKeys($boss));
        $this->assertContains('users', self::hiddenKeys($boss));
        $staff = self::sidebar('msstaff');
        $all = array_merge(self::navKeys($staff), self::hiddenKeys($staff));
        foreach (['ads', 'users', 'roles', 'platform_hotels'] as $k) {
            $this->assertNotContains($k, $all, "staff never see $k");
        }
        [$s] = self::as('msboss')->get('ads.php');
        $this->assertSame(403, $s, 'outside the plan stays closed');
        // Broken JSON = built-in menu.
        Settings::setFor(self::$hid, NavMenu::SETTING, '{broken');
        $this->assertSame($default, self::navKeys(self::sidebar('msboss')));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testItemsMissingFromASavedOrderKeepTheirBuiltInPosition(): void
    {
        // Unit: b and e are new (not in the saved order) — each lands after its built-in predecessor.
        $items = array_fill_keys(['a', 'b', 'c', 'd', 'e'], []);
        $this->assertSame(['d', 'e', 'a', 'b', 'c'], array_keys(NavMenu::order($items, ['d', 'a', 'c', 'zz'])), 'e follows its built-in predecessor d');
        $this->assertSame(['c', 'd', 'e', 'b', 'a'], array_keys(NavMenu::order($items, ['c', 'b', 'a'])), 'd / e follow their built-in predecessor c');
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_keys(NavMenu::order($items, [])));
        $items = array_fill_keys(['new', 'a', 'b'], []);
        $this->assertSame(['new', 'b', 'a'], array_keys(NavMenu::order($items, ['b', 'a'])), 'a new first item stays first');
        $this->assertNull(NavMenu::normalize('null'));
        $this->assertSame(['order' => ['ok_1'], 'hidden' => [], 'more' => true], NavMenu::normalize(['order' => ['ok_1', 'BAD', ['x'], 'ok_1'], 'hidden' => 'x']));

        // HTTP: a menu saved before "Ticker bar" existed shows it at its usual place (right after Broadcast).
        $default = self::navKeys(self::sidebar('msboss'));
        $this->assertContains('tickers', $default);
        $old = array_values(array_diff($default, ['tickers', 'broadcast']));
        array_unshift($old, 'broadcast');
        NavMenu::setBusiness(self::$hid, ['order' => $old, 'hidden' => [], 'more' => true]);
        $keys = self::navKeys(self::sidebar('msboss'));
        $this->assertSame(['broadcast', 'tickers'], array_slice($keys, 0, 2));
        $this->assertCount(count($default), $keys);
    }

    // ------------------------------------------------------------------ consoles

    public function testSuperAdminPersonalConsoleMenu(): void
    {
        $root = self::as('msroot');
        $html = self::sidebar('msroot', 'platform_overview.php');
        $default = self::navKeys($html);
        $this->assertSame('platform_overview', $default[0]);
        $this->assertStringContainsString('data-my-menu', $html, 'My menu in the user dropdown');
        [$s, , , $head] = $root->get('menu_settings.php?scope=business');
        $this->assertSame(302, $s, 'no business menu in the console');
        $this->assertStringContainsString('scope=me', $head);
        [$s, , $html] = $root->get('menu_settings.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertSame('platform', preg_match('/<body[^>]*data-panel="([a-z]+)"/', $html, $m) ? $m[1] : '');
        $this->assertSame($default, self::editorKeys($html));
        $this->assertStringContainsString('Items move within their heading', $html);

        // Within "Manage": Resellers first; Licenses hidden; platform_overview can't be hidden.
        $order = array_merge(['platform_resellers'], array_values(array_diff($default, ['platform_resellers'])));
        self::save('msroot', 'me', $order, ['platform_licenses', 'platform_overview']);
        $html = self::sidebar('msroot', 'platform_overview.php');
        $keys = self::navKeys($html);
        $this->assertContains('platform_overview', $keys);
        $this->assertNotContains('platform_licenses', $keys);
        $this->assertSame(['platform_licenses'], self::hiddenKeys($html));
        $this->assertSame(['platform_resellers', 'platform_overview', 'platform_hotels'], array_slice($keys, 0, 3), 'reordered inside its heading');
        $this->assertLessThan(strpos($html, 'data-nav="platform_plans"'), strpos($html, 'data-nav="platform_resellers"'), 'headings keep their order');
        // Moving an item to the top of the whole list does not take it out of its heading.
        self::save('msroot', 'me', array_merge(['platform_settings'], array_values(array_diff($default, ['platform_settings']))));
        $keys = self::navKeys(self::sidebar('msroot', 'platform_overview.php'));
        $this->assertSame('platform_overview', $keys[0]);
        $this->assertGreaterThan(array_search('platform_audit', $keys, true), array_search('platform_settings', $keys, true));
        [$s] = $root->get('platform_licenses.php');
        $this->assertSame(200, $s, 'hidden console page still opens');

        // Inside a customer the Super Admin sees the customer's menu, not the personal one.
        self::save('msboss', 'business', array_merge(['broadcast'], array_values(array_diff(self::navKeys(self::sidebar('msboss')), ['broadcast']))));
        [$s] = $root->post('platform_customer.php', ['op' => 'enter', 'id' => self::$hid, 'next' => 'index.php']);
        $this->assertSame(302, $s);
        $this->assertSame('broadcast', self::navKeys(self::sidebar('msroot', 'profile.php'))[0]);
        [, , $html] = $root->get('menu_settings.php?scope=me');
        $this->assertStringContainsString('data-menu-personal-off', $html);
        [$s, , $html] = $root->get('menu_settings.php?scope=business');
        $this->assertSame(200, $s, 'the Super Admin can edit the customer menu inside the workspace');
        $this->assertStringContainsString('data-scope="business"', $html);
        $root->post('platform_customer.php', ['op' => 'leave']);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testResellerPersonalPanelMenu(): void
    {
        $default = self::navKeys(self::sidebar('msres', 'reseller_overview.php'));
        $this->assertSame('reseller_overview', $default[0]);
        [$s, , $html] = self::as('msres')->get('menu_settings.php');
        $this->assertSame(200, $s);
        $this->assertSame($default, self::editorKeys($html));
        self::save('msres', 'me', array_reverse($default), ['reseller_overview', 'reseller_support']);
        $html = self::sidebar('msres', 'reseller_overview.php');
        $this->assertContains('reseller_overview', self::navKeys($html), 'required in the reseller panel');
        $this->assertSame(['reseller_support'], self::hiddenKeys($html));
        // The reseller's menu is personal: the Super Admin console is untouched.
        $this->assertSame('platform_overview', self::navKeys(self::sidebar('msroot', 'platform_overview.php'))[0]);
        $this->assertSame('', TestEnv::phpErrors());
    }
}
