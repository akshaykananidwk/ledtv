<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.6 panels (docs/modules/panels.md): panel detection per role, sidebar contents per panel (the Super Admin
 * console has no customer content modules, the customer workspace has no platform items, the reseller panel is
 * limited), global search scoping (reseller finds only own customers; customer users 403), Customer 360 actions
 * (feature switch, suspend, create user, reset password, role) with CSRF + audit rows, the impersonation banner
 * only when a customer was opened, and deleting a fully populated customer (rows, files, TV token, invoices kept).
 */
final class PanelsTest extends TestCase
{
    private static string $url;
    private static array $h = [];
    private static array $sessions = [];
    private static int $r1 = 0;
    private static int $r2 = 0;
    private static array $tv = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'My Business');
        $pw = Auth::hash('Passw0rd!');
        self::$r1 = DB::insert('resellers', ['name' => 'Reseller One', 'status' => 'active']);
        self::$r2 = DB::insert('resellers', ['name' => 'Reseller Two', 'status' => 'active']);
        DB::insert('users', ['hotel_id' => null, 'username' => 'pnroot', 'email' => 'pnroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => self::$r1, 'username' => 'pnres1', 'email' => 'pnres1@res.test', 'full_name' => 'Res One', 'password_hash' => $pw, 'role' => 'reseller']);
        $pro = (int) DB::value("SELECT id FROM plans WHERE name = 'Pro'");
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Stores', 'city' => 'Rajkot', 'reseller_id' => self::$r1, 'plan_id' => $pro ?: null, 'contact_email' => 'owner@alpha.test']);
        self::$h['gamma'] = Hotels::create(['name' => 'Gamma Lodge', 'city' => 'Surat', 'reseller_id' => self::$r2, 'plan_id' => $pro ?: null]);
        self::$h['doomed'] = Hotels::create(['name' => 'Doomed Traders', 'city' => 'Baroda', 'plan_id' => $pro ?: null]);
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphaboss', 'email' => 'boss@alpha.test', 'password' => 'Passw0rd!'], 'super_admin');
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphastaff', 'email' => 'staff@alpha.test', 'password' => 'Passw0rd!'], 'staff');
        Hotels::createHotelUser(self::$h['gamma'], ['username' => 'gammaboss', 'email' => 'boss@gamma.test', 'password' => 'Passw0rd!'], 'super_admin');
        Hotels::createHotelUser(self::$h['doomed'], ['username' => 'doomboss', 'email' => 'boss@doomed.test', 'password' => 'Passw0rd!'], 'super_admin');
        foreach (['alpha' => ['A1'], 'gamma' => ['G1']] as $k => $nums) {
            Tenant::run(self::$h[$k], static function () use ($nums): void {
                foreach ($nums as $n) {
                    DB::insert('rooms', ['room_number' => $n, 'name' => 'Screen ' . $n, 'floor' => '1']);
                }
            });
        }
        // The fully populated customer that will be deleted: demo content, playlist, groups, tickers, a layout, an invoice.
        Tenant::run(self::$h['doomed'], static function (): void {
            Demo::sampleContent([1 => 4, 2 => 3]);
            DB::insert('tickers', ['hotel_id' => Tenant::id(), 'name' => 'Welcome', 'message' => 'Welcome to Doomed Traders']);
            if (Migrator::hasTable(DB::pdo(), 'layouts')) {
                try {
                    DB::query('INSERT INTO layouts (hotel_id, name, definition, created_at) VALUES (:h, :n, :d, :c)', ['h' => Tenant::id(), 'n' => 'L1', 'd' => '{"zones":[]}', 'c' => now()]);
                } catch (Throwable) {
                    // layout columns differ: the demo rows are populated enough
                }
            }
        });
        DB::insert('invoices', ['hotel_id' => self::$h['doomed'], 'number' => 'INV-DOOM-1', 'period_from' => date('Y-m-01'), 'period_to' => date('Y-m-t'), 'plan_name' => 'Pro',
            'tv_count' => 1, 'unit_price' => 100, 'amount' => 100, 'tax' => 0, 'total' => 100, 'status' => 'unpaid', 'issued_at' => date('Y-m-d'), 'due_date' => date('Y-m-d', time() + 86400 * 10), 'created_at' => now()]);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        self::register('tvA1', 'A1', 'alpha');
        self::register('tvD1', '101', 'doomed');
        self::register('tvD2', '102', 'doomed');
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
        PlatformScreens::$userOverride = null;
        PlatformScreens::forget();
    }

    private static function register(string $name, string $room, string $hotelKey): array
    {
        $key = (string) Settings::getFor(self::$h[$hotelKey], 'registration_key');
        $uid = 'tv-pn-' . strtolower($name) . '-0001';
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key, 'app_version' => '2.6.0', 'app_version_code' => 12, 'model' => 'Model ' . $name]);
        self::assertSame(200, $s, (string) json_encode($j));
        return self::$tv[$name] = ['uid' => $uid, 'token' => $j['data']['token'], 'hotel' => self::$h[$hotelKey],
            'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => self::$h[$hotelKey]])];
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function poll(string $name): int
    {
        $t = self::$tv[$name];
        [$s] = TestEnv::http('GET', self::$url . 'api/device/command/' . $t['uid'] . '?hash=x', null, ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        return $s;
    }

    /** data-nav keys of the sidebar in a page. */
    private static function navKeys(string $html): array
    {
        preg_match_all('/data-nav="([a-z0-9_]+)"/', $html, $m);
        return $m[1];
    }

    private static function panelOf(string $html): string
    {
        return preg_match('/<body[^>]*data-panel="([a-z]+)"/', $html, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------ panels & sidebars

    public function testEveryRoleLandsInItsOwnPanelWithItsOwnSidebar(): void
    {
        // Super Admin console: home = overview, dark console, platform items only.
        $root = self::as('pnroot');
        [$s, , , $head] = $root->get('index.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_overview.php', $head);
        [$s, , $html] = $root->get('platform_overview.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertSame('platform', self::panelOf($html));
        $this->assertStringContainsString('data-panel-badge', $html);
        $this->assertStringContainsString('Super Admin console', $html);
        $this->assertStringContainsString('hc-global-search', $html);
        $this->assertStringContainsString('data-overview-kpis', $html);
        $this->assertStringContainsString('data-kpi="customers"', $html);
        $this->assertStringContainsString('Needs attention', $html);
        $this->assertStringContainsString('data-recent-activity', $html);
        $keys = self::navKeys($html);
        foreach (['platform_overview', 'platform_hotels', 'platform_screens', 'platform_plans', 'platform_resellers', 'platform_invoices', 'platform_settings'] as $k) {
            $this->assertContains($k, $keys, "console sidebar has $k");
        }
        foreach (['index', 'rooms', 'content', 'playlists', 'broadcast', 'schedule', 'users', 'settings', 'reseller', 'reseller_overview'] as $k) {
            $this->assertNotContains($k, $keys, "console sidebar must not list the customer module $k");
        }
        $this->assertStringNotContainsString('data-impersonation-banner', $html);

        // Reseller panel: teal, limited items, no platform-only item.
        $res = self::as('pnres1');
        [$s, , , $head] = $res->get('index.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('reseller_overview.php', $head);
        [$s, , $html] = $res->get('reseller_overview.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertSame('reseller', self::panelOf($html));
        $this->assertStringContainsString('Reseller One', $html);
        $keys = self::navKeys($html);
        foreach (['reseller_overview', 'reseller', 'platform_screens', 'reseller_plans', 'reseller_invoices', 'reseller_support'] as $k) {
            $this->assertContains($k, $keys, "reseller sidebar has $k");
        }
        foreach (['platform_overview', 'platform_hotels', 'platform_plans', 'platform_resellers', 'platform_invoices', 'platform_settings', 'platform_support', 'update', 'content', 'rooms'] as $k) {
            $this->assertNotContains($k, $keys, "reseller sidebar must not list $k");
        }
        foreach (['reseller.php', 'reseller_plans.php', 'reseller_invoices.php', 'reseller_support.php'] as $p) {
            [$s, , $html] = $res->get($p);
            $this->assertSame(200, $s, $p);
            $this->assertFalse(TestEnv::hasPhpError($html), $p);
            $this->assertSame('reseller', self::panelOf($html));
        }
        // Platform-only pages stay forbidden for the reseller (server-side), customer pages need a workspace.
        foreach (['platform_overview.php', 'platform_plans.php', 'platform_settings.php', 'platform_resellers.php'] as $p) {
            [$s] = $res->get($p);
            $this->assertSame(403, $s, $p);
        }

        // Customer workspace: the customer's name, no platform / reseller items, no console pages.
        $boss = self::as('alphaboss');
        [$s, , $html] = $boss->get('index.php');
        $this->assertSame(200, $s);
        $this->assertSame('customer', self::panelOf($html));
        $this->assertStringContainsString('Alpha Stores', $html);
        $this->assertStringNotContainsString('data-panel-badge', $html);
        $this->assertStringNotContainsString('hc-global-search', $html);
        $keys = self::navKeys($html);
        $this->assertContains('index', $keys);
        $this->assertContains('rooms', $keys);
        foreach ($keys as $k) {
            $this->assertStringStartsNotWith('platform_', $k, 'customer sidebar leaks a platform item');
            $this->assertStringStartsNotWith('reseller', $k, 'customer sidebar leaks a reseller item');
        }
        foreach (['platform_overview.php', 'platform_search.php', 'platform_customer.php?id=' . self::$h['alpha'], 'reseller_overview.php', 'reseller.php', 'reseller_support.php'] as $p) {
            [$s] = $boss->get($p);
            $this->assertSame(403, $s, "$p as customer admin");
        }
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testOpeningACustomerShowsTheImpersonationBannerAndTheCustomerTheme(): void
    {
        $root = new AdminSession(self::$url, 'pnroot');
        [, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha']);
        $this->assertStringContainsString('Open customer workspace', $html);
        $this->assertStringNotContainsString('data-impersonation-banner', $html);
        [$s, , , $head] = $root->post('platform_customer.php', ['op' => 'enter', 'id' => self::$h['alpha'], 'next' => 'content.php']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('content.php', $head);
        [$s, , $html] = $root->get('rooms.php');
        $this->assertSame(200, $s);
        $this->assertSame('customer', self::panelOf($html));
        $this->assertStringContainsString('data-impersonating="1"', $html);
        $this->assertStringContainsString('data-impersonation-banner', $html);
        $this->assertStringContainsString('You are managing customer', $html);
        $this->assertStringContainsString('Alpha Stores', $html);
        $this->assertStringContainsString('Super Admin', $html);
        $this->assertStringContainsString('Exit workspace', $html);
        $keys = self::navKeys($html);
        $this->assertContains('rooms', $keys);
        $this->assertNotContains('platform_hotels', $keys, 'inside the workspace the sidebar is the customer\'s');
        // The user menu still links back to the console.
        $this->assertStringContainsString('platform_overview.php', $html);
        // Exit → console again, no banner.
        [$s, , , $head] = $root->post('platform_hotels.php', ['op' => 'leave']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_overview.php', $head);
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertSame('platform', self::panelOf($html));
        $this->assertStringNotContainsString('data-impersonation-banner', $html);
        // Reseller: same banner with its own role word, only for own customers.
        $res = new AdminSession(self::$url, 'pnres1');
        [$s] = $res->post('platform_customer.php', ['op' => 'enter', 'id' => self::$h['gamma']]);
        $this->assertSame(404, $s, 'reseller cannot open another reseller\'s customer');
        [$s] = $res->post('reseller.php', ['op' => 'enter', 'id' => self::$h['alpha']]);
        $this->assertSame(302, $s);
        [, , $html] = $res->get('index.php');
        $this->assertSame('customer', self::panelOf($html));
        $this->assertStringContainsString('data-impersonation-banner', $html);
        $this->assertStringContainsString('Reseller', $html);
        $res->post('reseller.php', ['op' => 'leave']);
        [, , $html] = $res->get('reseller_overview.php');
        $this->assertSame('reseller', self::panelOf($html));
    }

    // ------------------------------------------------------------------ global search

    public function testGlobalSearchIsScopedToWhatTheUserMaySee(): void
    {
        $root = self::as('pnroot');
        foreach (['Alpha', 'tv-pn-tva1', 'boss@gamma.test', 'Reseller Two', 'Surat'] as $q) {
            [$s, , $html] = $root->get('platform_search.php?q=' . rawurlencode($q));
            $this->assertSame(200, $s, $q);
            $this->assertFalse(TestEnv::hasPhpError($html), $q);
            $this->assertStringContainsString('data-search-total', $html, "no results for $q");
        }
        [, , $html] = $root->get('platform_search.php?q=tv-pn-tva1');
        $this->assertStringContainsString('data-search-group="screens"', $html);
        $this->assertStringContainsString('Alpha Stores', $html);
        [, , $html] = $root->get('platform_search.php?q=boss@gamma.test');
        $this->assertStringContainsString('data-search-group="users"', $html);
        $this->assertStringContainsString('gammaboss', $html);
        [, , $html] = $root->get('platform_search.php?q=Reseller');
        $this->assertStringContainsString('data-search-group="resellers"', $html);
        [, , $html] = $root->get('platform_search.php?q=zzzz-nothing');
        $this->assertStringContainsString('Nothing found', $html);
        [, , $html] = $root->get('platform_search.php?q=a');
        $this->assertStringContainsString('Type at least 2 characters', $html);

        // Reseller One: own customers / TVs / users only; no other reseller, no resellers group at all.
        $res = self::as('pnres1');
        [$s, , $html] = $res->get('platform_search.php?q=Alpha');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Alpha Stores', $html);
        foreach (['Gamma', 'tv-pn-tvg', 'boss@gamma.test', 'Reseller Two', 'Doomed'] as $q) {
            [$s, , $html] = $res->get('platform_search.php?q=' . rawurlencode($q));
            $this->assertSame(200, $s);
            $this->assertStringNotContainsString('Gamma Lodge', $html, "reseller must not find Gamma via '$q'");
            $this->assertStringNotContainsString('gammaboss', $html);
            $this->assertStringNotContainsString('Doomed Traders', $html);
            $this->assertStringNotContainsString('data-search-group="resellers"', $html);
        }
        [, , $html] = $res->get('platform_search.php?q=' . rawurlencode('Reseller Two'));
        $this->assertStringContainsString('Nothing found', $html);
        // Customer users: 403, also via the AJAX switches.
        $boss = self::as('alphaboss');
        [$s] = $boss->get('platform_search.php?q=Alpha');
        $this->assertSame(403, $s);
        [$s] = $boss->ajax('platform_toggle', ['kind' => 'customer_status', 'target' => self::$h['gamma'], 'on' => false]);
        $this->assertSame(403, $s);
        $this->assertSame('active', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => self::$h['gamma']]));
    }

    // ------------------------------------------------------------------ Customer 360 actions

    public function testCustomer360TabsRenderForPlatformAndReseller(): void
    {
        $root = self::as('pnroot');
        foreach (['summary', 'plan', 'screens', 'users', 'content', 'billing', 'activity', 'settings'] as $tab) {
            [$s, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=' . $tab);
            $this->assertSame(200, $s, $tab);
            $this->assertFalse(TestEnv::hasPhpError($html), "PHP error on tab $tab");
            $this->assertStringContainsString('data-c360-tabs', $html);
            $this->assertSame('platform', self::panelOf($html), 'the 360 renders in the console, not in the customer theme');
        }
        [, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=plan');
        $this->assertStringContainsString('data-feature-switches', $html);
        $this->assertStringContainsString('data-kind="feature"', $html);
        [, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users');
        $this->assertStringContainsString('data-user-create', $html);
        $this->assertStringContainsString('data-kind="user_active"', $html);
        [, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=content');
        $this->assertStringContainsString('data-content-per-screen', $html);
        $this->assertStringContainsString('A1', $html);
        [, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=settings');
        $this->assertStringContainsString('data-delete-customer="' . self::$h['alpha'] . '"', $html);
        // The old 2.5 "overview" tab and the old customers → view link land on the 360.
        [$s, , $html] = $root->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=overview');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Limits usage', $html);
        [$s, , , $head] = $root->get('platform_hotels.php?action=view&id=' . self::$h['alpha']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_customer.php', $head);
        // Reseller: no plan / billing / settings tabs for its own customer; 404 for a foreign one.
        $res = self::as('pnres1');
        [$s, , $html] = $res->get('platform_customer.php?id=' . self::$h['alpha']);
        $this->assertSame(200, $s);
        $this->assertSame('reseller', self::panelOf($html));
        $this->assertStringNotContainsString('data-tab="plan"', $html);
        $this->assertStringNotContainsString('data-tab="settings"', $html);
        $this->assertStringNotContainsString('data-tab="billing"', $html);
        [$s] = $res->get('platform_customer.php?id=' . self::$h['gamma']);
        $this->assertSame(404, $s);
        [$s] = $res->get('platform_customer.php?id=' . self::$h['alpha'] . '&tab=plan');
        $this->assertSame(200, $s, 'unknown tab falls back to summary');
    }

    public function testSwitchesNeedCsrfPermissionAndScopeAndAreAudited(): void
    {
        $root = self::as('pnroot');
        $alpha = self::$h['alpha'];
        $before = (int) DB::value('SELECT COUNT(*) FROM activity_logs');
        // Feature off / on for one customer.
        $key = 'tickers';
        $this->assertTrue(Features::exists($key));
        [$s, $j] = $root->ajax('platform_toggle', ['kind' => 'feature', 'target' => $alpha, 'feature' => $key, 'on' => false]);
        $this->assertSame(200, $s, (string) json_encode($j));
        $this->assertFalse($j['data']['on']);
        Tenant::forget($alpha);
        Features::forget();
        $this->assertFalse(Features::enabled($key, $alpha));
        $this->assertContains($key, Features::overrides($alpha)['remove']);
        [$s, $j] = $root->ajax('platform_toggle', ['kind' => 'feature', 'target' => $alpha, 'feature' => $key, 'on' => true]);
        $this->assertSame(200, $s);
        Tenant::forget($alpha);
        Features::forget();
        $this->assertTrue(Features::enabled($key, $alpha));
        $this->assertSame([], Features::overrides($alpha)['remove'], 'back to the plan default: no override left');
        [$s] = $root->ajax('platform_toggle', ['kind' => 'feature', 'target' => $alpha, 'feature' => 'no_such_feature', 'on' => true]);
        $this->assertSame(422, $s);
        // Suspend / activate.
        [$s, $j] = $root->ajax('platform_toggle', ['kind' => 'customer_status', 'target' => $alpha, 'on' => false]);
        $this->assertSame(200, $s);
        $this->assertSame('suspended', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => $alpha]));
        $this->assertStringContainsString('Suspended', $j['data']['badge']);
        [$s] = $root->ajax('platform_toggle', ['kind' => 'customer_status', 'target' => $alpha, 'on' => true]);
        $this->assertSame(200, $s);
        $this->assertSame('active', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => $alpha]));
        // User active off / on, screen off / on, plan, sign-up, pool.
        $staff = (int) DB::value("SELECT id FROM users WHERE username = 'alphastaff'");
        [$s] = $root->ajax('platform_toggle', ['kind' => 'user_active', 'target' => $staff, 'on' => false]);
        $this->assertSame(200, $s);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $staff]));
        [$s] = $root->ajax('platform_toggle', ['kind' => 'user_active', 'target' => $staff, 'on' => true]);
        $this->assertSame(200, $s);
        $room = (int) DB::value("SELECT id FROM rooms WHERE hotel_id = :h AND room_number = 'A1'", ['h' => $alpha]);
        [$s] = $root->ajax('platform_toggle', ['kind' => 'screen_enabled', 'target' => $room, 'customer' => $alpha, 'on' => false]);
        $this->assertSame(200, $s);
        $this->assertSame(0, (int) DB::value('SELECT is_enabled FROM rooms WHERE id = :id', ['id' => $room]));
        [$s] = $root->ajax('platform_toggle', ['kind' => 'screen_enabled', 'target' => $room, 'customer' => self::$h['gamma'], 'on' => true]);
        $this->assertSame(404, $s, 'screen must belong to the given customer');
        [$s] = $root->ajax('platform_toggle', ['kind' => 'screen_enabled', 'target' => $room, 'customer' => $alpha, 'on' => true]);
        $this->assertSame(200, $s);
        $plan = (int) DB::value('SELECT id FROM plans ORDER BY id LIMIT 1');
        [$s] = $root->ajax('platform_toggle', ['kind' => 'plan_active', 'target' => $plan, 'on' => false]);
        $this->assertSame(200, $s);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM plans WHERE id = :id', ['id' => $plan]));
        $root->ajax('platform_toggle', ['kind' => 'plan_active', 'target' => $plan, 'on' => true]);
        [$s] = $root->ajax('platform_toggle', ['kind' => 'signup_open', 'target' => 0, 'on' => true]);
        $this->assertSame(200, $s);
        Settings::flush();
        $this->assertSame('1', (string) Settings::platform('signup_enabled'));
        $root->ajax('platform_toggle', ['kind' => 'signup_open', 'target' => 0, 'on' => false]);
        Settings::flush();
        $this->assertSame('0', (string) Settings::platform('signup_enabled'));
        [$s] = $root->ajax('platform_toggle', ['kind' => 'pool_enabled', 'target' => 0, 'on' => true]);
        $this->assertSame(200, $s);
        Settings::flush();
        $this->assertTrue(DevicePool::enabled());
        $root->ajax('platform_toggle', ['kind' => 'pool_enabled', 'target' => 0, 'on' => false]);
        // Audit rows were written (platform scope + the customer's own log).
        $this->assertGreaterThanOrEqual($before + 10, (int) DB::value('SELECT COUNT(*) FROM activity_logs'));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'hotel_status' AND details LIKE '%suspended%'", ['h' => $alpha]));
        $this->assertGreaterThanOrEqual(2, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'hotel_feature'", ['h' => $alpha]));

        // Reseller: scope — own customer's user yes, foreign customer no, platform-only switches 403.
        $res = self::as('pnres1');
        [$s] = $res->ajax('platform_toggle', ['kind' => 'user_active', 'target' => $staff, 'on' => true]);
        $this->assertSame(200, $s);
        $gammaBoss = (int) DB::value("SELECT id FROM users WHERE username = 'gammaboss'");
        [$s] = $res->ajax('platform_toggle', ['kind' => 'user_active', 'target' => $gammaBoss, 'on' => false]);
        $this->assertSame(404, $s);
        $this->assertSame(1, (int) DB::value('SELECT is_active FROM users WHERE id = :id', ['id' => $gammaBoss]));
        foreach (['customer_status' => $alpha, 'feature' => $alpha, 'plan_active' => $plan, 'signup_open' => 0, 'pool_enabled' => 0] as $kind => $target) {
            [$s] = $res->ajax('platform_toggle', ['kind' => $kind, 'target' => $target, 'feature' => $key, 'on' => false]);
            $this->assertSame(403, $s, "$kind as reseller");
        }
        $this->assertSame('active', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => $alpha]));

        // CSRF: a POST without the token is refused (419) and changes nothing.
        $h = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
        [$s] = TestEnv::http('POST', self::$url . 'admin/ajax.php?action=platform_toggle', ['kind' => 'customer_status', 'target' => $alpha, 'on' => false], $h, $root->jar);
        $this->assertSame(419, $s);
        $this->assertSame('active', DB::value('SELECT status FROM hotels WHERE id = :id', ['id' => $alpha]));
        [$s] = TestEnv::http('POST', self::$url . 'admin/platform_customer.php', null, [], $root->jar, ['op' => 'user_create', 'id' => (string) $alpha, 'admin_username' => 'nocsrf', 'admin_email' => 'nocsrf@alpha.test', 'admin_password' => 'Passw0rd!', 'role' => 'staff']);
        $this->assertSame(419, $s);
        $this->assertNull(DB::value("SELECT id FROM users WHERE username = 'nocsrf'"));
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testCustomer360CreatesUsersChangesRolesAndResetsPasswords(): void
    {
        $root = new AdminSession(self::$url, 'pnroot');
        $alpha = self::$h['alpha'];
        [$s, , , $head] = $root->post('platform_customer.php?id=' . $alpha . '&tab=users', ['op' => 'user_create', 'id' => $alpha, 'admin_username' => 'alphamgr', 'admin_name' => 'Alpha Manager',
            'admin_email' => 'mgr@alpha.test', 'admin_password' => 'Passw0rd!', 'role' => 'manager', 'language' => 'gu']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('tab=users', $head);
        $u = DB::one("SELECT * FROM users WHERE username = 'alphamgr'");
        $this->assertNotNull($u);
        $this->assertSame($alpha, (int) $u['hotel_id']);
        $this->assertSame('manager', $u['role']);
        $this->assertSame('gu', $u['language']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'user_create' AND entity_id = :u", ['h' => $alpha, 'u' => $u['id']]));
        // The new user can log in to the customer workspace only.
        $mgr = new AdminSession(self::$url, 'alphamgr');
        [$s, , $html] = $mgr->get('index.php');
        $this->assertSame(200, $s);
        $this->assertSame('customer', self::panelOf($html));
        // Platform roles / foreign custom roles are refused; duplicate username refused.
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'user_create', 'id' => $alpha, 'admin_username' => 'evil', 'admin_email' => 'evil@alpha.test', 'admin_password' => 'Passw0rd!', 'role' => 'platform_admin']);
        $this->assertNull(DB::value("SELECT id FROM users WHERE username = 'evil'"));
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'user_create', 'id' => $alpha, 'admin_username' => 'alphamgr', 'admin_email' => 'x@alpha.test', 'admin_password' => 'Passw0rd!', 'role' => 'staff']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM users WHERE username = 'alphamgr'"));
        // Role change logs the user out.
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'user_role', 'id' => $alpha, 'user_id' => $u['id'], 'role' => 'staff']);
        $this->assertSame('staff', DB::value('SELECT role FROM users WHERE id = :id', ['id' => $u['id']]));
        [$s] = $mgr->get('index.php');
        $this->assertSame(302, $s, 'logged out after the role change');
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'user_role', 'id' => $alpha, 'user_id' => $u['id'], 'role' => 'reseller']);
        $this->assertSame('staff', DB::value('SELECT role FROM users WHERE id = :id', ['id' => $u['id']]), 'platform roles cannot be given');
        // Password reset: the flash shows the new password once and the old one no longer works.
        $old = DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $u['id']]);
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'user_reset', 'id' => $alpha, 'user_id' => $u['id']]);
        [, , $html] = $root->get('platform_customer.php?id=' . $alpha . '&tab=users');
        $this->assertStringContainsString('New password for alphamgr', $html);
        $this->assertNotSame($old, DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $u['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'user_password_reset' AND entity_id = :u", ['h' => $alpha, 'u' => $u['id']]));
        // Limits form.
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'limits', 'id' => $alpha, 'plan_id' => (string) DB::value('SELECT plan_id FROM hotels WHERE id = :id', ['id' => $alpha]), 'max_tvs' => '7', 'max_users' => '9', 'storage_mb' => '', 'expires_at' => '2030-01-31']);
        $row = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $alpha]);
        $this->assertSame(7, (int) $row['max_tvs']);
        $this->assertSame(9, (int) $row['max_users']);
        $this->assertStringStartsWith('2030-01-31', (string) $row['expires_at']);
        $root->post('platform_customer.php?id=' . $alpha, ['op' => 'limits', 'id' => $alpha, 'plan_id' => (string) $row['plan_id'], 'max_tvs' => '', 'max_users' => '', 'storage_mb' => '', 'expires_at' => '']);
        $this->assertNull(DB::value('SELECT max_tvs FROM hotels WHERE id = :id', ['id' => $alpha]));
        // A reseller may create users of its own customer but not change plan / limits.
        $res = new AdminSession(self::$url, 'pnres1');
        [$s] = $res->post('platform_customer.php?id=' . $alpha, ['op' => 'limits', 'id' => $alpha, 'max_tvs' => '1']);
        $this->assertSame(403, $s);
        $this->assertNull(DB::value('SELECT max_tvs FROM hotels WHERE id = :id', ['id' => $alpha]));
        $res->post('platform_customer.php?id=' . $alpha, ['op' => 'user_create', 'id' => $alpha, 'admin_username' => 'resmade', 'admin_email' => 'resmade@alpha.test', 'admin_password' => 'Passw0rd!', 'role' => 'reception']);
        $this->assertSame($alpha, (int) DB::value("SELECT hotel_id FROM users WHERE username = 'resmade'"));
        [$s] = $res->post('platform_customer.php?id=' . self::$h['gamma'], ['op' => 'user_create', 'id' => self::$h['gamma'], 'admin_username' => 'resevil', 'admin_email' => 'resevil@g.test', 'admin_password' => 'Passw0rd!', 'role' => 'staff']);
        $this->assertSame(404, $s);
        $this->assertNull(DB::value("SELECT id FROM users WHERE username = 'resevil'"));
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    // ------------------------------------------------------------------ delete a customer

    public function testDeletingAPopulatedCustomerRemovesRowsFilesAndTvAccess(): void
    {
        $id = self::$h['doomed'];
        $alpha = self::$h['alpha'];
        // Files of the customer: media, thumbnail, branding logo, a live-view frame.
        $files = [
            'uploads/h' . $id . '/media/2026/10/photo.jpg', 'uploads/h' . $id . '/media/2026/10/photo_thumb.jpg',
            'uploads/h' . $id . '/branding/logo.png', 'storage/support/h' . $id . '/d' . self::$tv['tvD1']['id'] . '/live.jpg', 'storage/apk/h' . $id . '/app.apk',
        ];
        foreach ($files as $f) {
            @mkdir(dirname(HC_ROOT . '/' . $f), 0755, true);
            file_put_contents(HC_ROOT . '/' . $f, str_repeat('x', 2048));
        }
        $alphaFile = 'uploads/h' . $alpha . '/media/2026/10/keep.jpg';
        @mkdir(dirname(HC_ROOT . '/' . $alphaFile), 0755, true);
        file_put_contents(HC_ROOT . '/' . $alphaFile, 'keep');
        $tables = DB::column("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'hotel_id' AND TABLE_NAME <> 'hotels'");
        $populated = [];
        foreach ($tables as $t) {
            $n = (int) DB::value("SELECT COUNT(*) FROM `$t` WHERE hotel_id = :h", ['h' => $id]);
            if ($n) {
                $populated[$t] = $n;
            }
        }
        foreach (['rooms', 'devices', 'content_items', 'content_playlists', 'room_groups', 'users', 'tickers', 'invoices'] as $t) {
            $this->assertArrayHasKey($t, $populated, "demo customer should have $t rows");
        }
        $doomBoss = new AdminSession(self::$url, 'doomboss');
        [$s] = $doomBoss->get('index.php');
        $this->assertSame(200, $s);
        $this->assertGreaterThan(0, (int) DB::value('SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h', ['h' => $id]), 'login wrote an activity row');
        $alphaRows = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $alpha]) + (int) DB::value('SELECT COUNT(*) FROM users WHERE hotel_id = :h', ['h' => $alpha]);
        $this->assertSame(200, self::poll('tvD1'), 'TV polls fine before the delete');
        $this->assertSame(200, self::poll('tvA1'));

        $root = new AdminSession(self::$url, 'pnroot');
        // Reseller / customer users cannot delete; wrong confirmation name does nothing; CSRF enforced.
        $res = new AdminSession(self::$url, 'pnres1');
        [$s] = $res->post('platform_customer.php', ['op' => 'delete', 'id' => $alpha, 'confirm_name' => 'Alpha Stores']);
        $this->assertSame(403, $s);
        [$s] = $doomBoss->post('platform_customer.php', ['op' => 'delete', 'id' => $id, 'confirm_name' => 'Doomed Traders']);
        $this->assertSame(403, $s);
        [$s] = $root->post('platform_customer.php', ['op' => 'delete', 'id' => $id, 'confirm_name' => 'Doomed']);
        $this->assertSame(302, $s);
        $this->assertNotNull(DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $id]), 'wrong name: nothing deleted');
        [$s] = TestEnv::http('POST', self::$url . 'admin/platform_customer.php', null, [], $root->jar, ['op' => 'delete', 'id' => (string) $id, 'confirm_name' => 'Doomed Traders']);
        $this->assertSame(419, $s);
        $this->assertNotNull(DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $id]));
        // The real thing, from the customers list modal (same handler as Customer 360 → Settings).
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertStringContainsString('data-archive-customer="' . $id . '"', $html, 'archive is the default list action');
        $this->assertStringContainsString('hcDeleteCustomer', $html);
        [, , $html] = $root->get('platform_customer.php?id=' . $id . '&tab=settings');
        $this->assertStringContainsString('data-delete-customer="' . $id . '"', $html);
        [$s, , , $head] = $root->post('platform_customer.php', ['op' => 'delete', 'id' => $id, 'confirm_name' => 'Doomed Traders']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_hotels.php', $head);
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertStringContainsString('Deleted customer &quot;Doomed Traders&quot;', $html);
        $this->assertStringContainsString('7 screens, 2 TVs', $html);
        $this->assertStringContainsString('invoice(s) kept for accounting', $html);
        $this->assertStringNotContainsString('Doomed Traders</a>', $html);

        $this->assertNull(DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $id]));
        foreach ($tables as $t) {
            if (in_array($t, ['invoices', 'licenses', 'signups', 'device_pool'], true)) {
                continue;
            }
            $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM `$t` WHERE hotel_id = :h", ['h' => $id]), "orphan rows left in $t");
        }
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM invoices WHERE hotel_id = :h', ['h' => $id]));
        $inv = DB::one("SELECT * FROM invoices WHERE number = 'INV-DOOM-1'");
        $this->assertNotNull($inv, 'invoices are kept for accounting');
        $this->assertNull($inv['hotel_id']);
        $this->assertSame('Doomed Traders', $inv['customer_name']);
        [$s, , $html] = $root->get('platform_invoices.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('INV-DOOM-1', $html);
        $this->assertStringContainsString('Doomed Traders', $html);
        [$s, , $html] = $root->get('invoice.php?id=' . $inv['id']);
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        // Child rows without hotel_id are gone through the cascades (sessions, playlist items, group members, device commands).
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL'));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM playlist_items p LEFT JOIN content_playlists c ON c.id = p.playlist_id WHERE c.id IS NULL'));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM room_group_members m LEFT JOIN rooms r ON r.id = m.room_id WHERE r.id IS NULL'));
        // Files gone, other customers' files kept.
        foreach ($files as $f) {
            $this->assertFileDoesNotExist(HC_ROOT . '/' . $f);
        }
        $this->assertDirectoryDoesNotExist(HC_ROOT . '/uploads/h' . $id);
        $this->assertDirectoryDoesNotExist(HC_ROOT . '/storage/support/h' . $id);
        $this->assertDirectoryDoesNotExist(HC_ROOT . '/storage/apk/h' . $id);
        $this->assertFileExists(HC_ROOT . '/' . $alphaFile);
        // Its TVs are refused on their next poll and cannot re-register with the old key; the users are logged out.
        $this->assertSame(401, self::poll('tvD1'));
        $this->assertSame(401, self::poll('tvD2'));
        [$s] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => self::$tv['tvD1']['uid'], 'room_number' => '101', 'registration_key' => 'gone', 'app_version' => '2.6.0', 'app_version_code' => 12]);
        $this->assertContains($s, [401, 403, 404]);
        [$s] = $doomBoss->get('index.php');
        $this->assertSame(302, $s, 'deleted customer\'s admin is logged out');
        // Other customers untouched.
        $this->assertSame(200, self::poll('tvA1'));
        $this->assertSame($alphaRows, (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $alpha]) + (int) DB::value('SELECT COUNT(*) FROM users WHERE hotel_id = :h', ['h' => $alpha]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id IS NULL AND action = 'hotel_delete' AND entity_id = :h", ['h' => $id]));
        [$s] = $root->get('platform_customer.php?id=' . $id);
        $this->assertSame(404, $s);
        // The last customer can never be deleted.
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    // ------------------------------------------------------------------ docs/SPEC_SAAS.md alignment (2.6)

    public function testSidebarFollowsTheSpecMenusPerPanel(): void
    {
        $root = self::as('pnroot');
        [, , $html] = $root->get('platform_overview.php');
        $keys = self::navKeys($html);
        // §34: Dashboard, Clients, Plans & modules, Subscriptions, Devices, Content overview, Reports, Notifications, Audit logs, System settings.
        $pos = static fn (string $k) => array_search($k, $keys, true);
        foreach (['platform_overview', 'platform_hotels', 'platform_plans', 'platform_invoices', 'platform_licenses', 'platform_screens', 'platform_content', 'platform_reports', 'push', 'platform_audit', 'platform_settings'] as $k) {
            $this->assertContains($k, $keys, "console sidebar has $k");
        }
        $this->assertLessThan($pos('platform_hotels'), $pos('platform_overview'));
        $this->assertLessThan($pos('platform_plans'), $pos('platform_hotels'));
        $this->assertLessThan($pos('platform_screens'), $pos('platform_plans'));
        $this->assertLessThan($pos('platform_content'), $pos('platform_screens'));
        $this->assertLessThan($pos('platform_audit'), $pos('platform_reports'));
        $this->assertLessThan($pos('platform_settings'), $pos('platform_audit'));
        foreach (['Dashboard', 'Plans &amp; modules', 'Content overview', 'Reports', 'Audit logs', 'System settings', 'Devices &amp; screens'] as $label) {
            $this->assertStringContainsString($label, $html, "label $label");
        }
        foreach (['platform_content.php', 'platform_reports.php', 'platform_audit.php'] as $p) {
            [$s, , $html] = $root->get($p);
            $this->assertSame(200, $s, $p . ' ' . substr(TestEnv::phpErrors(), -600));
            $this->assertFalse(TestEnv::hasPhpError($html), $p);
            $this->assertSame('platform', self::panelOf($html));
        }
        [, , $html] = $root->get('platform_overview.php');
        foreach (['data-kpi="suspended"', 'data-kpi="warnings"', 'data-kpi="expiring"', 'data-kpi="revenue"', 'data-chart="growth"', 'data-chart="devices"', 'data-chart="plans"', 'Device errors'] as $x) {
            $this->assertStringContainsString($x, $html, $x);
        }
        // Customer: Dashboard, Screens, Locations / groups, Content, Playlists, Schedules, Users, Logs, Settings, Subscription.
        [, , $html] = self::as('alphaboss')->get('index.php');
        $keys = self::navKeys($html);
        $pos = static fn (string $k) => array_search($k, $keys, true);
        $this->assertLessThan($pos('rooms'), $pos('index'));
        $this->assertLessThan($pos('groups'), $pos('rooms'));
        $this->assertLessThan($pos('content'), $pos('groups'));
        $this->assertLessThan($pos('playlists'), $pos('content'));
        $this->assertLessThan($pos('users'), $pos('playlists'));
        $this->assertLessThan($pos('settings'), $pos('users'));
        $this->assertLessThan($pos('plan'), $pos('settings'));
        $this->assertStringContainsString('Locations &amp; groups', $html);
        $this->assertStringContainsString('>Subscription<', $html);
    }

    public function testSubscriptionPageShowsOwnStateOnly(): void
    {
        $boss = self::as('alphaboss');
        [$s, , $html] = $boss->get('plan.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('data-subscription-state="ACTIVE"', $html);
        $this->assertStringNotContainsString('Gamma Lodge', $html);
        $this->assertStringNotContainsString('Doomed', $html);
        DB::update('hotels', ['expires_at' => date('Y-m-d 23:59:59', time() + 5 * 86400)], 'id = :id', ['id' => self::$h['alpha']]);
        Tenant::forget(self::$h['alpha']);
        [, , $html] = $boss->get('plan.php');
        $this->assertStringContainsString('data-subscription-state="EXPIRING"', $html);
        $this->assertStringContainsString('day(s) left', $html);
        DB::update('hotels', ['expires_at' => null], 'id = :id', ['id' => self::$h['alpha']]);
        Tenant::forget(self::$h['alpha']);
        $this->assertSame('ACTIVE', Panel::subscriptionState(self::$h['alpha'])['key']);
        // Staff without settings.manage cannot open it; it is never a platform page.
        [$s] = self::as('alphastaff')->get('plan.php');
        $this->assertSame(403, $s);
    }

    public function testDisabledModuleAnswersWithThePlanMessageOnPagesAjaxAndApi(): void
    {
        $root = self::as('pnroot');
        $alpha = self::$h['alpha'];
        [$s] = $root->ajax('platform_toggle', ['kind' => 'feature', 'target' => $alpha, 'feature' => 'templates', 'on' => false]);
        $this->assertSame(200, $s);
        $boss = new AdminSession(self::$url, 'alphaboss');
        [$s, , $html] = $boss->get('templates.php');
        $this->assertSame(403, $s);
        $this->assertStringContainsString('This feature is not available in your current plan.', $html);
        [$s, $j] = $boss->ajax('tpl_apply', ['id' => 1]);
        $this->assertSame(403, $s);
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);
        $this->assertStringContainsString('This feature is not available in your current plan.', $j['error']['message']);
        $this->assertSame('This feature is not available in your current plan.', substr(Features::denial('templates')['message'], 0, 51));
        $root->ajax('platform_toggle', ['kind' => 'feature', 'target' => $alpha, 'feature' => 'templates', 'on' => true]);
        foreach (['gu', 'hi'] as $lang) {
            $this->assertNotSame('This feature is not available in your current plan.', I18n::table($lang)['This feature is not available in your current plan.'] ?? 'This feature is not available in your current plan.', $lang);
        }
    }

    public function testAuditLogsPageIsPlatformOnlyAndFilters(): void
    {
        $root = self::as('pnroot');
        $alpha = self::$h['alpha'];
        [$s, , $html] = $root->get('platform_audit.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('data-audit-table', $html);
        $this->assertStringContainsString('Alpha Stores', $html);
        [$s, , $html] = $root->get('platform_audit.php?customer=' . self::$h['gamma'] . '&action=user_');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('Alpha Stores</a>', $html);
        [$s, , $html] = $root->get('platform_audit.php?customer=' . $alpha . '&action=hotel_feature&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'));
        $this->assertSame(200, $s);
        $this->assertStringContainsString('hotel_feature', $html);
        [$s, , $html] = $root->get('platform_audit.php?customer=platform');
        $this->assertSame(200, $s);
        [$s, , $csv] = $root->get('platform_audit.php?export=csv&customer=' . $alpha);
        $this->assertSame(200, $s);
        $this->assertStringContainsString('time,customer,user,action', $csv);
        foreach (['pnres1', 'alphaboss'] as $u) {
            [$s] = self::as($u)->get('platform_audit.php');
            $this->assertSame(403, $s, $u);
            [$s] = self::as($u)->get('platform_reports.php');
            $this->assertSame(403, $s, $u);
            [$s] = self::as($u)->get('platform_content.php');
            $this->assertSame(403, $s, $u);
        }
        // The customer's own log stays limited to its data.
        [$s, , $html] = self::as('alphaboss')->get('logs.php');
        $this->assertSame(200, $s);
        $this->assertStringNotContainsString('Gamma Lodge', $html);
    }

    public function testArchiveRestoreAndPermanentDeletePolicy(): void
    {
        $root = new AdminSession(self::$url, 'pnroot');
        $gamma = self::$h['gamma'];
        $gboss = new AdminSession(self::$url, 'gammaboss');
        [$s] = $gboss->get('index.php');
        $this->assertSame(200, $s);
        // Resellers and customers cannot archive.
        [$s] = self::as('pnres1')->post('platform_customer.php', ['op' => 'archive', 'id' => self::$h['alpha']]);
        $this->assertSame(403, $s);
        [$s] = $gboss->post('platform_customer.php', ['op' => 'archive', 'id' => $gamma]);
        $this->assertSame(403, $s);
        // Archive from the list (same handler): suspended + archived, hidden from the default list, users logged out, TV paused.
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertStringContainsString('data-archive-customer="' . $gamma . '"', $html);
        $this->assertStringNotContainsString('data-delete-customer="' . $gamma . '"', $html, 'permanent delete only after archiving (list)');
        [$s, , , $head] = $root->post('platform_customer.php', ['op' => 'archive', 'id' => $gamma]);
        $this->assertSame(302, $s);
        $row = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $gamma]);
        $this->assertNotNull($row['archived_at']);
        $this->assertSame('suspended', $row['status']);
        $this->assertSame('ARCHIVED', Panel::subscriptionState($gamma)['key']);
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertStringNotContainsString('data-status-badge="' . $gamma . '"', $html, 'archived customers are hidden by default');
        [, , $html] = $root->get('platform_hotels.php?status=archived');
        $this->assertStringContainsString('Gamma Lodge', $html);
        $this->assertStringContainsString('data-restore-customer="' . $gamma . '"', $html);
        $this->assertStringContainsString('data-delete-customer="' . $gamma . '"', $html);
        [$s] = $gboss->get('index.php');
        $this->assertSame(302, $s, 'archived customer\'s users are logged out');
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE hotel_id = :h AND action = 'hotel_archive'", ['h' => $gamma]));
        [, , $html] = $root->get('platform_customer.php?id=' . $gamma);
        $this->assertStringContainsString('data-archived', $html);
        // Restore: active again, data intact.
        [$s] = $root->post('platform_customer.php', ['op' => 'restore', 'id' => $gamma]);
        $this->assertSame(302, $s);
        $row = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $gamma]);
        $this->assertNull($row['archived_at']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND room_number = 'G1'", ['h' => $gamma]));
        [, , $html] = $root->get('platform_hotels.php');
        $this->assertStringContainsString('data-status-badge="' . $gamma . '"', $html);
        $gboss2 = new AdminSession(self::$url, 'gammaboss');
        [$s] = $gboss2->get('index.php');
        $this->assertSame(200, $s, 'restored customer can log in again');
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testTranslationsExistForThePanelStrings(): void
    {
        foreach (['gu', 'hi'] as $lang) {
            $t = I18n::table($lang);
            foreach (['Super Admin console', 'Reseller panel', 'Open customer workspace', 'Exit workspace', 'Global search', 'Customer 360', 'Needs attention', 'Delete customer', 'Plan & features'] as $k) {
                $this->assertArrayHasKey($k, $t, "$lang: $k");
                $this->assertNotSame($k, $t[$k]);
            }
        }
        foreach (['gu', 'hi'] as $lang) {
            DB::update('users', ['language' => $lang], 'username = :u', ['u' => 'pnroot']);
            $s = new AdminSession(self::$url, 'pnroot');
            foreach (['platform_overview.php', 'platform_search.php?q=Alpha', 'platform_customer.php?id=' . self::$h['alpha'] . '&tab=plan', 'platform_hotels.php'] as $p) {
                [$code, , $html] = $s->get($p);
                $this->assertSame(200, $code, "$lang $p");
                $this->assertFalse(TestEnv::hasPhpError($html), "$lang $p");
            }
            [, , $html] = $s->get('platform_overview.php');
            $this->assertStringContainsString($lang === 'gu' ? 'સુપર એડમિન કન્સોલ' : 'सुपर एडमिन कंसोल', $html);
        }
        DB::update('users', ['language' => 'en'], 'username = :u', ['u' => 'pnroot']);
    }
}
