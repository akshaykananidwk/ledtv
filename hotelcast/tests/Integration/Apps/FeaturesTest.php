<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Plans & feature entitlements (2.5, core/Features.php, docs/modules/plans_features.md):
 * registry completeness (every admin page, ajax action, API route, permission, display app, content
 * extension and dashboard widget belongs to a feature or is core), enabled / depends / overrides / legacy
 * plan lists, end-to-end enforcement of a disabled feature (page 403, hidden nav, AJAX 403, API 403,
 * TV content, display page), plans without a feature list (everything on), limits (users, screens,
 * storage), the plan editor (CRUD, CSRF, validation, platform only), customer overrides and every
 * admin page without PHP warnings.
 */
final class FeaturesTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', 'Krishna Palace');
        DB::query("UPDATE hotels SET registration_key = 'FTKEY0000000000001' WHERE id = 1");
        $pw = Auth::hash('Passw0rd!');
        foreach (['super_admin' => 'ftBoss', 'staff' => 'ftStaff'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$id['root'] = DB::insert('users', ['hotel_id' => null, 'username' => 'ftRoot', 'email' => 'root@t.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Lobby', 'floor' => '1']);
        self::$id['d101'] = DB::insert('devices', ['device_uid' => 'ft-tv-101-0001', 'room_id' => self::$id['r101'], 'token_hash' => hash('sha256', 'ft-tv-101-0001'), 'status' => 'online', 'last_ping' => now(), 'registered_at' => now()]);
        self::$id['h2'] = Hotels::create(['name' => 'Hotel Two']);
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        require_once HC_ROOT . '/admin/partials/common.php';
    }

    public static function tearDownAfterClass(): void
    {
        DB::query('UPDATE hotels SET plan_id = NULL, feature_overrides = NULL, max_users = NULL, storage_mb = NULL, max_tvs = NULL');
        Features::$storageOverride = null;
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        DB::query('UPDATE hotels SET plan_id = NULL, feature_overrides = NULL, max_users = NULL, storage_mb = NULL, max_tvs = NULL');
        DB::query('DELETE FROM rate_limits');
        Features::$storageOverride = null;
        Tenant::forget();
        Settings::flush();
        Settings::bumpContentVersion();
        Cache::clear();
    }

    /** New plan with these feature keys (null = everything); returns id. */
    private static function plan(?array $keys, array $extra = []): int
    {
        static $n = 0;
        $n++;
        return DB::insert('plans', $extra + [
            'name' => 'FT plan ' . $n, 'price_per_tv_month' => 10,
            'features' => $keys === null ? null : Features::encodePlanKeys($keys), 'created_at' => now(),
        ]);
    }

    private static function usePlan(int $planId, int $hotel = 1): void
    {
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = :h', ['p' => $planId, 'h' => $hotel]);
        Tenant::forget();
        Settings::bumpContentVersion();
        Cache::clear();
    }

    private function assertNoPhpErrors(string $html, string $where): void
    {
        $err = preg_match('#(<b>)?(Warning|Notice|Deprecated|Fatal error|Parse error)(</b>)?:\s.{0,300}#s', $html, $m) ? $m[0] : '';
        $this->assertFalse(TestEnv::hasPhpError($html), $where . ': ' . $err);
    }

    /** Is there a sidebar link to $page? */
    private static function navHas(string $html, string $page): bool
    {
        return (bool) preg_match('#class="nav-link[^"]*" href="[^"]*/admin/' . preg_quote($page, '#') . '"#', $html);
    }

    // ------------------------------------------------------------------ registry completeness

    public function testEveryAdminPageAndAjaxActionBelongsToAFeature(): void
    {
        $missing = [];
        foreach (glob(HC_ROOT . '/admin/*.php') ?: [] as $f) {
            if (!Features::pageKnown(basename($f))) {
                $missing[] = basename($f);
            }
        }
        $this->assertSame([], $missing, 'admin pages without a feature: register them in core/Features.php (pages) or make them core');
        // A page nobody registered fails (the crawler works).
        $this->assertFalse(Features::pageKnown('brand_new_module.php'));
        $this->assertTrue(Features::pageKnown('platform_brand_new.php'), 'platform pages are never gated');

        // Built-in ajax.php actions + every action string of every ajax.d file.
        $actions = [];
        preg_match_all("/case '([a-z0-9_]+)':/", (string) file_get_contents(HC_ROOT . '/admin/ajax.php'), $m);
        $actions = array_merge($actions, $m[1], ['update_check', 'update_run', 'rollback']);
        foreach (glob(HC_ROOT . '/admin/ajax.d/*.php') ?: [] as $f) {
            $prefix = basename($f, '.php');
            preg_match_all("/'(" . preg_quote($prefix, '/') . "_[a-z0-9_]+)'/", (string) file_get_contents($f), $m);
            $this->assertNotEmpty($m[1], 'no actions found in ajax.d/' . $prefix . '.php');
            $actions = array_merge($actions, $m[1]);
        }
        $unknown = array_values(array_unique(array_filter($actions, static fn ($a) => !Features::ajaxKnown($a))));
        $this->assertSame([], $unknown, 'ajax actions without a feature');
        $this->assertFalse(Features::ajaxKnown('brandnew_action'));

        // Examples
        $this->assertSame('designer', Features::forPage('designer.php'));
        $this->assertSame('pdf_import', Features::forAjax('designer_pdfpage'));
        $this->assertSame('designer', Features::forAjax('designer_save'));
        $this->assertNull(Features::forPage('rooms.php'), 'core page');
        $this->assertNull(Features::forPage('platform_plans.php'), 'platform page');
        $this->assertNull(Features::forAjax('room_status'), 'core action');
        $this->assertSame('live_view', Features::forAjax('live_poll'));
    }

    public function testEveryApiRoutePermissionAppExtensionAndWidgetBelongsToAFeature(): void
    {
        // API: built-in routes + the routes each api/routes file answers.
        $routes = ['', 'health', 'device/register', 'device/command/x', 'device/ack', 'device/heartbeat', 'device/played', 'device/apk/1', 'content/1'];
        foreach (glob(HC_ROOT . '/api/routes/*.php') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            preg_match_all("/\\(\\\$parts\\[0\\] \\?\\? ''\\) !== '([a-z_-]+)'/", $src, $m1);
            preg_match_all("/\\\$route !== '([a-z_\\/-]+)'/", $src, $m2);
            preg_match_all("/'(device\\/[a-z_-]+)' =>/", $src, $m3);
            $found = array_merge($m1[1], $m2[1], $m3[1]);
            $this->assertNotEmpty($found, 'no route found in ' . basename($f));
            $routes = array_merge($routes, $found);
        }
        $unknown = array_values(array_filter($routes, static fn ($r) => !Features::apiKnown($r)));
        $this->assertSame([], $unknown, 'API routes without a feature');
        $this->assertSame('pms', Features::forApi('pms/checkin'));
        $this->assertSame('presence', Features::forApi('presence'));
        $this->assertSame('api_access', Features::forApi('kpi/push'));
        $this->assertSame('room_service', Features::forApi('guest/abc/order'));
        $this->assertNull(Features::forApi('device/command'), 'the device API is never gated');
        $this->assertFalse(Features::apiKnown('brandnew/x'));

        // Permissions: every hotel permission (core + core/boot.d) belongs to a feature; role-list
        // permissions are platform level.
        $ref = new ReflectionClass(Auth::class);
        $extra = $ref->getProperty('extraPermissions');
        $extra->setAccessible(true);
        $perms = Auth::PERMISSIONS + $extra->getValue() + Auth::PLATFORM_PERMISSIONS;
        $orphans = [];
        foreach ($perms as $perm => $rule) {
            if (is_string($rule) && !Features::permissionOwners($perm)) {
                $orphans[] = $perm;
            }
        }
        $this->assertSame([], $orphans, 'hotel permissions without a feature');

        // Display apps: every core/Apps key is listed explicitly.
        $listed = array_merge(...array_values(array_map(static fn ($d) => $d['apps'], Features::all())));
        $this->assertSame([], array_values(array_diff(array_keys(DisplayApps::all()), $listed)), 'display apps without a family');
        $this->assertSame('app_menu_board', Features::forApp('menu_board'));
        $this->assertSame('data_feeds', Features::forApp('gold_rates'));

        // Content extensions: listed, or field-level (filterContent).
        $ext = array_merge(...array_values(array_map(static fn ($d) => $d['extensions'], Features::all())));
        foreach (glob(HC_CORE . '/Extensions/*.php') ?: [] as $f) {
            $c = basename($f, '.php');
            $this->assertTrue(in_array($c, $ext, true) || in_array($c, Features::FIELD_EXTENSIONS, true), 'content extension without a feature: ' . $c);
        }
        // Dashboard widgets.
        $w = array_merge(...array_values(array_map(static fn ($d) => $d['widgets'], Features::all())));
        foreach (glob(HC_ROOT . '/admin/partials/dashboard.d/*.php') ?: [] as $f) {
            $this->assertContains(basename($f), $w, 'dashboard widget without a feature');
        }

        // Registry sanity: groups, depends, presets, granularity, translations.
        $all = Features::all();
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        foreach ($all as $k => $d) {
            $this->assertArrayHasKey($d['group'], Features::GROUPS, $k);
            foreach ($d['depends'] as $dep) {
                $this->assertArrayHasKey($dep, $all, "$k depends on unknown $dep");
                $this->assertFalse(Features::isCore($dep), "$k depends on a core feature");
            }
            foreach ([$d['label'], $d['description']] as $s) {
                $this->assertArrayHasKey($s, $gu, 'Gujarati: ' . $s);
                $this->assertArrayHasKey($s, $hi, 'Hindi: ' . $s);
            }
        }
        foreach (Features::GROUPS as $g) {
            $this->assertArrayHasKey($g, $gu);
        }
        $n = count(Features::optionalKeys());
        $this->assertGreaterThanOrEqual(30, $n);
        $this->assertLessThanOrEqual(50, $n);
        foreach (['dashboard', 'screens', 'groups', 'users', 'profile', 'settings', 'logs', 'update'] as $core) {
            $this->assertTrue(Features::isCore($core), $core);
        }
        foreach (array_keys(Features::PRESETS) as $p) {
            $this->assertNotEmpty(Features::presetKeys($p), $p);
        }
        $this->assertNotContains('guests', Features::presetKeys('Pro'));
        $this->assertNotContains('marketplace', Features::presetKeys('Pro'));
        $this->assertContains('designer', Features::presetKeys('Pro'));
        $this->assertCount($n, Features::presetKeys('Hospitality'));
    }

    public function testTranslationsOfTheNewPages(): void
    {
        $files = ['core/Features.php', 'admin/plan.php', 'admin/platform_plans.php', 'admin/partials/features_ui.php', 'admin/partials/not_in_plan.php', 'admin/partials/nav.d/11_plan.php'];
        foreach (['gu', 'hi'] as $lang) {
            $t = I18n::table($lang);
            $missing = [];
            foreach ($files as $f) {
                preg_match_all("/(?:__|I18n::translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
                foreach ($m[1] as $key) {
                    if (!isset($t[stripslashes($key)])) {
                        $missing[] = "$f: $key";
                    }
                }
            }
            $this->assertSame([], array_values(array_unique($missing)), $lang);
        }
    }

    // ------------------------------------------------------------------ logic

    public function testEnabledDependsOverridesAndLegacyLists(): void
    {
        // No plan / plan without a list / empty list = everything (back-compat).
        foreach (Features::optionalKeys() as $k) {
            $this->assertTrue(Features::enabled($k), 'no plan: ' . $k);
        }
        foreach ([null, '[]', ''] as $i => $raw) {
            $p = DB::insert('plans', ['name' => 'Raw ' . $i, 'price_per_tv_month' => 0, 'features' => $raw === '' ? null : $raw, 'created_at' => now()]);
            self::usePlan($p);
            $this->assertSame(Features::optionalKeys(), Features::enabledKeys(), 'plan features ' . var_export($raw, true));
        }
        // The plans that existed before 2.5 keep NULL = everything.
        foreach (['Basic', 'Standard', 'Premium'] as $name) {
            $this->assertNull(DB::value('SELECT features FROM plans WHERE name = :n', ['n' => $name]), $name);
        }
        // Ready-made plans seeded by migration 028.
        foreach (['Business', 'Pro', 'Hospitality'] as $name) {
            $row = DB::one('SELECT * FROM plans WHERE name = :n', ['n' => $name]);
            $this->assertNotNull($row, $name);
            $this->assertEqualsCanonicalizing(Features::presetKeys($name), Features::planKeys($row['features']), $name);
        }
        $this->assertSame(5, (int) DB::value("SELECT max_users FROM plans WHERE name = 'Business'"));

        // A list of keys + depends.
        self::usePlan(self::plan(['content', 'designer', 'pdf_import', 'apps', 'app_menu_board', 'feedback']));
        $this->assertTrue(Features::enabled('designer'));
        $this->assertTrue(Features::enabled('app_menu_board'));
        $this->assertFalse(Features::enabled('tickers'));
        $this->assertFalse(Features::enabled('feedback'), 'feedback depends on room_service');
        $this->assertTrue(Features::enabled('dashboard'), 'core is always on');
        $this->assertTrue(Features::enabled('screens'));
        $this->assertFalse(Features::enabled('no_such_feature'));
        self::usePlan(self::plan(['designer', 'apps', 'app_menu_board']));
        $this->assertFalse(Features::enabled('designer'), 'designer needs content');
        $this->assertFalse(Features::enabled('app_menu_board'), 'app family needs apps which needs content');

        // Customer overrides: add / remove win over the plan.
        DB::query('UPDATE hotels SET feature_overrides = :o WHERE id = 1', ['o' => Features::encodeOverrides(['content', 'tickers'], ['designer'])]);
        Tenant::forget();
        $this->assertTrue(Features::enabled('content'));
        $this->assertTrue(Features::enabled('tickers'));
        $this->assertFalse(Features::enabled('designer'), 'removed for this customer');
        $this->assertTrue(Features::enabled('app_menu_board'), 'dependency added → family works');
        $this->assertSame(['add' => ['content', 'tickers'], 'remove' => ['designer']], Features::overrides(1));
        $this->assertNull(Features::encodeOverrides([], []));
        $this->assertSame(['add' => [], 'remove' => []], Features::parseOverrides('{"add":["dashboard","nope"],"remove":5}'), 'core / unknown keys ignored');
        // Other hotel untouched; explicit hotel id.
        $this->assertTrue(Features::enabled('designer', self::$id['h2']));
        $this->assertFalse(Features::enabled('designer', 1));

        // Legacy 2.0 module lists keep their meaning.
        DB::query('UPDATE hotels SET feature_overrides = NULL WHERE id = 1');
        $legacy = DB::insert('plans', ['name' => 'Legacy guests', 'price_per_tv_month' => 0, 'features' => json_encode(['guests']), 'created_at' => now()]);
        self::usePlan($legacy);
        $this->assertTrue(Features::enabled('guests'));
        $this->assertTrue(Features::enabled('designer'), 'not a 2.0 module → on');
        $this->assertFalse(Features::enabled('ads'));
        $this->assertFalse(Features::enabled('marketplace'));
        $this->assertFalse(Features::enabled('room_service'));
        $this->assertFalse(Features::enabled('templates'));
        $this->assertFalse(Features::enabled('guide'));
        $this->assertTrue(Features::isLegacyList(['guests', 'signage']));
        $this->assertFalse(Features::isLegacyList(['guests', 'designer']));
        $this->assertSame(array_values(array_diff(Features::optionalKeys(), ['guests', 'pms'])), Features::planKeys(['ads', 'analytics', 'services', 'templates', 'pwa', 'support']), 'legacy list without guests');
        // Old format {"ads": true}
        $this->assertNotContains('ads', Features::planKeys('{"ads": false, "guests": true}'));

        // Tenant::feature() wrapper maps the old names.
        $this->assertTrue(Tenant::feature('guests'));
        $this->assertFalse(Tenant::feature('services'));
        $this->assertFalse(Tenant::feature('ads'));
        $this->assertFalse(Tenant::feature('pwa'));
        $this->assertTrue(Tenant::feature('unknown_module'), 'unknown names are not restricted');
        $this->assertTrue(Tenant::feature('designer'), 'feature keys work too');

        // Encoding: core keys mark the new format; everything = NULL.
        $this->assertNull(Features::encodePlanKeys(Features::optionalKeys()));
        $enc = json_decode((string) Features::encodePlanKeys(['ads']), true);
        $this->assertContains('dashboard', $enc);
        $this->assertSame(['ads'], Features::planKeys($enc), 'a list of only "ads" is not misread as a legacy list');
    }

    public function testPermissionEnabledForTheRbacCheck(): void
    {
        self::usePlan(self::plan(Features::presetKeys('Basic')));
        $this->assertTrue(Features::permissionEnabled('content.manage'));
        $this->assertTrue(Features::permissionEnabled('schedule.manage'), 'schedule + power schedules');
        $this->assertTrue(Features::permissionEnabled('tickers.manage'));
        $this->assertFalse(Features::permissionEnabled('ads.manage'));
        $this->assertFalse(Features::permissionEnabled('menu_board.manage'));
        $this->assertFalse(Features::permissionEnabled('support.view'), 'support + live view both off');
        $this->assertFalse(Features::permissionEnabled('roles.manage'), 'custom roles not in Basic');
        $this->assertTrue(Features::permissionEnabled('dashboard.view'), 'core');
        $this->assertTrue(Features::permissionEnabled('users.manage'), 'core');
        $this->assertTrue(Features::permissionEnabled('no.such.permission'), 'not tied to a feature');
        Tenant::clear();
        $this->assertTrue(Features::permissionEnabled('ads.manage'), 'no customer context');
        Tenant::set(1);
        $this->assertSame(['support', 'live_view'], array_values(array_intersect(['support', 'live_view'], Features::permissionOwners('support.view'))));
    }

    // ------------------------------------------------------------------ end to end: a disabled feature

    public function testDisabledFeatureIsRefusedEverywhereAndHiddenOnTvs(): void
    {
        // Plan: everything except designer, tickers, the menu board family, layouts, live view, presence API and the web player.
        $keys = array_values(array_diff(Features::optionalKeys(), ['designer', 'tickers', 'app_menu_board', 'layouts', 'live_view', 'api_access', 'web_player', 'emergency_alarm', 'usb_mode']));
        $plan = self::plan($keys);

        // TV content fixtures (full plan first).
        $img = DB::insert('content_items', ['title' => 'Pic', 'type' => 'image', 'url' => 'https://example.com/a.jpg', 'duration' => 10, 'is_active' => 1, 'created_at' => now()]);
        $menu = DisplayAppsTestKit::createItem('menu_board', []);
        $layout = DB::insert('content_items', ['title' => 'Split', 'type' => 'layout', 'duration' => 30, 'is_active' => 1, 'created_at' => now(),
            'settings' => json_out(['bg_color' => '#000000', 'audio' => 'auto', 'zones' => [['id' => 'z1', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'content_id' => $img, 'playlist_id' => null]]])]);
        $pl = DB::insert('content_playlists', ['name' => 'Mixed', 'transition' => 'fade', 'created_at' => now()]);
        foreach ([$img, (int) $menu['id'], $layout] as $i => $cid) {
            DB::query('INSERT INTO playlist_items (playlist_id, content_id, sort_order) VALUES (:p, :c, :s)', ['p' => $pl, 'c' => $cid, 's' => $i]);
        }
        DB::query('UPDATE rooms SET playlist_id = :p, usb_mode = 1 WHERE id = :r', ['p' => $pl, 'r' => self::$id['r101']]);
        DB::insert('tickers', ['name' => 'T', 'message' => 'FT-TICKER-TEXT', 'target_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        $room = static fn () => DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['r101']]);
        Settings::bumpContentVersion();
        $c = ContentResolver::build($room());
        $this->assertCount(3, $c['items'], 'full plan: image + app + layout');
        $this->assertNotNull($c['overlay']['ticker']);
        $this->assertStringContainsString('FT-TICKER-TEXT', json_out($c['overlay']['ticker']));
        $this->assertTrue($c['usb_mode'] ?? false);
        [$code] = DisplayAppsTestKit::page(self::$url, $menu);
        $this->assertSame(200, $code);

        self::usePlan($plan);
        // TV content: app of a disabled family and the layout are skipped like inactive items; no ticker; no USB mode.
        $c = ContentResolver::build($room());
        $this->assertSame([$img], array_column($c['items'], 'id'));
        $this->assertNull($c['overlay']['ticker']);
        $this->assertArrayNotHasKey('usb_mode', $c);
        // Emergency alarm sound stripped, the message stays (safety first).
        $bid = Broadcaster::emergencyStart('Fire', 'Leave now', 'all', [], null, '#B00020', '#FFFFFF', Broadcaster::alarmOptions(['alarm_sound' => 'b:emergency_beep']));
        Settings::bumpContentVersion();
        $c = ContentResolver::build($room());
        $this->assertSame('emergency', $c['mode']);
        $this->assertNull($c['emergency']['alarm']);
        Broadcaster::emergencyStop($bid);
        // Display page of the disabled app → neutral "not available" page; live data refused.
        [$code, $html] = DisplayAppsTestKit::page(self::$url, $menu);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('This app is not available.', $html);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $menu);
        $this->assertSame(403, $code);
        $this->assertSame('FEATURE_DISABLED', $json['error']['code']);
        // Saving such content is refused too.
        [, $errors] = ContentManager::validate(['title' => 'X', 'app' => 'menu_board', 'cfg' => []], 'app', false);
        $this->assertContains('This app is not included in your plan.', $errors);

        // Admin pages: 403 "Not included in your plan" + hidden navigation.
        $s = new AdminSession(self::$url, 'ftBoss');
        foreach (['designer.php', 'tickers.php', 'menu_board.php', 'live_view.php'] as $page) {
            [$code, , $html] = $s->get($page);
            $this->assertSame(403, $code, $page);
            $this->assertStringContainsString('Not included in your plan', $html, $page);
            $this->assertNoPhpErrors($html, $page);
        }
        [$code, , $html] = $s->post('tickers.php', ['op' => 'save', 'id' => 0, 'name' => 'X', 'message' => 'Y']);
        $this->assertSame(403, $code, 'POST refused before the page runs');
        [$code, , $html] = $s->get('index.php');
        $this->assertSame(200, $code);
        foreach (['designer.php', 'tickers.php', 'menu_board.php'] as $page) {
            $this->assertFalse(self::navHas($html, $page), 'nav hides ' . $page);
        }
        $this->assertTrue(self::navHas($html, 'content.php'));
        $this->assertTrue(self::navHas($html, 'plan.php'), '"Your plan" in the menu');
        [, , $html] = $s->get('content.php');
        $this->assertStringNotContainsString('admin/designer.php"', $html, 'designer button hidden');
        $this->assertStringContainsString('admin/pdf_import.php"', $html, 'PDF import still in the plan');
        $this->assertStringNotContainsString('type=layout', $html, 'layout type hidden');
        [, , $html] = $s->get('apps.php');
        $this->assertStringNotContainsString('data-app="menu_board"', $html, 'gallery hides the family');
        $this->assertStringContainsString('data-app="notice_board"', $html);
        // "Your plan" lists included / not included.
        [$code, , $html] = $s->get('plan.php');
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('#data-feature="designer" data-on="0"#', $html);
        $this->assertMatchesRegularExpression('#data-feature="content" data-on="1"#', $html);
        $this->assertStringContainsString('Contact your provider to upgrade your plan.', $html);

        // AJAX: 403 JSON.
        [$code, $j] = $s->ajax('designer_save', ['title' => 'x']);
        $this->assertSame(403, $code);
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);
        $this->assertSame('designer', $j['error']['feature']);
        [$code, $j] = $s->ajax('live_poll&device_id=' . self::$id['d101']);
        $this->assertSame(403, $code);
        [$code, $j] = $s->ajax('room_status');
        $this->assertSame(200, $code, 'core actions keep working');

        // API: module route 403, device API untouched.
        $kpiToken = 'kpi' . str_repeat('a1', 24);
        DB::insert('kpi_tiles', ['label' => 'Safe days', 'push_token_hash' => hash('sha256', $kpiToken), 'created_at' => now()]);
        [$code, $j] = TestEnv::http('POST', self::$url . 'api/kpi/push', ['value' => 5], ['Authorization: Bearer ' . $kpiToken]);
        $this->assertSame(403, $code);
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);
        $prsToken = 'prs' . str_repeat('b2', 24);
        DB::insert('presence_sensors', ['name' => 'Door', 'target_type' => 'rooms', 'target_ids' => '[]', 'token_hash' => hash('sha256', $prsToken), 'created_at' => now()]);
        [$code, $j] = TestEnv::http('POST', self::$url . 'api/presence', ['event' => 'motion'], ['Authorization: Bearer ' . $prsToken]);
        $this->assertSame(403, $code, 'presence needs api_access');
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);
        [$code, $j] = TestEnv::http('GET', self::$url . 'api/device/command', null, ['Authorization: Bearer ft-tv-101-0001']);
        $this->assertNotSame(403, $code, 'device API never gated');
        // Web player registration refused, Android TVs register.
        [$code, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'ft-web-player-01', 'room_number' => '101', 'registration_key' => 'FTKEY0000000000001', 'platform' => 'web']);
        $this->assertSame(403, $code);
        $this->assertSame('FEATURE_DISABLED', $j['error']['code']);

        // Platform admin inside the customer: pages open (with a banner), navigation = customer's view.
        $root = new AdminSession(self::$url, 'ftRoot');
        $root->post('platform_hotels.php', ['op' => 'enter', 'id' => 1]);
        [$code, , $html] = $root->get('designer.php');
        $this->assertSame(200, $code, 'platform admin has everything');
        $this->assertStringContainsString('data-plan-banner', $html);
        $this->assertFalse(self::navHas($html, 'designer.php'), 'customer view');
        $this->assertNoPhpErrors($html, 'designer as platform admin');

        // Enabling the feature again (override) opens everything.
        DB::query('UPDATE hotels SET feature_overrides = :o WHERE id = 1', ['o' => Features::encodeOverrides(['designer', 'tickers', 'app_menu_board', 'layouts', 'api_access'], [])]);
        Tenant::forget();
        Settings::bumpContentVersion();
        [$code] = $s->get('designer.php');
        $this->assertSame(200, $code);
        [$code] = TestEnv::http('POST', self::$url . 'api/kpi/push', ['value' => 6], ['Authorization: Bearer ' . $kpiToken]);
        $this->assertSame(200, $code);
        $this->assertCount(3, ContentResolver::build($room())['items']);
        [$code] = DisplayAppsTestKit::page(self::$url, $menu);
        $this->assertSame(200, $code);

        DB::query('UPDATE rooms SET playlist_id = NULL, usb_mode = 0');
        DB::query('DELETE FROM tickers');
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPlanWithoutFeatureListKeepsEverythingOn(): void
    {
        self::usePlan((int) DB::value("SELECT id FROM plans WHERE name = 'Standard'"));
        $s = new AdminSession(self::$url, 'ftBoss');
        foreach (['designer.php', 'tickers.php', 'menu_board.php', 'ads.php', 'guests.php', 'orders.php', 'live_view.php'] as $page) {
            [$code, , $html] = $s->get($page);
            $this->assertContains($code, [200, 302], $page); // live_view.php without a TV goes back to the TV list
            $this->assertNoPhpErrors($html, $page);
        }
        [, , $html] = $s->get('index.php');
        $this->assertTrue(self::navHas($html, 'designer.php'));
        $this->assertTrue(self::navHas($html, 'tickers.php'));
        $this->assertStringNotContainsString('data-plan-banner', $html);
    }

    // ------------------------------------------------------------------ limits

    public function testLimitsUsersScreensAndStorage(): void
    {
        $this->assertSame(['max_screens' => null, 'max_users' => null, 'storage_mb' => null], Features::limits());
        // Plan limits, customer overrides win.
        $plan = self::plan(null, ['max_tvs' => 9, 'max_users' => 4, 'storage_mb' => 100]);
        self::usePlan($plan);
        $this->assertSame(['max_screens' => 9, 'max_users' => 4, 'storage_mb' => 100], Features::limits());
        DB::query('UPDATE hotels SET max_users = 7, storage_mb = 1 WHERE id = 1');
        Tenant::forget();
        $this->assertSame(['max_screens' => 9, 'max_users' => 7, 'storage_mb' => 1], Features::limits());

        // Users: the limit is reached → creating a user is refused with a clear message.
        $count = Features::userCount();
        DB::query('UPDATE hotels SET max_users = :n WHERE id = 1', ['n' => $count]);
        Tenant::forget();
        $s = new AdminSession(self::$url, 'ftBoss');
        $new = ['op' => 'save', 'id' => 0, 'username' => 'ftNew', 'email' => 'ftnew@t.test', 'full_name' => 'New', 'role' => 'staff', 'language' => 'en', 'is_active' => 1, 'password' => 'Passw0rd!', 'password_confirm' => 'Passw0rd!', 'tv_access' => 'all'];
        $s->post('users.php', $new);
        $this->assertFalse((bool) DB::value("SELECT id FROM users WHERE username = 'ftNew'"));
        [, , $html] = $s->get('users.php?action=new');
        $this->assertStringContainsString('Your plan allows at most ' . $count . ' users.', $html);
        // Editing existing users still works at the limit.
        $s->post('users.php', ['op' => 'save', 'id' => self::$id['ftStaff'], 'username' => 'ftStaff', 'email' => 'ftStaff@t.test', 'full_name' => 'Renamed', 'role' => 'staff', 'language' => 'en', 'is_active' => 1, 'tv_access' => 'all']);
        $this->assertSame('Renamed', DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => self::$id['ftStaff']]));
        DB::query('UPDATE hotels SET max_users = :n WHERE id = 1', ['n' => $count + 1]);
        Tenant::forget();
        $s->post('users.php', $new);
        $this->assertTrue((bool) DB::value("SELECT id FROM users WHERE username = 'ftNew'"));
        $this->assertNotNull(Features::userLimitError());

        // Screens: max_screens = connected TVs (same limit as the TV registration).
        DB::query('UPDATE hotels SET max_tvs = :n WHERE id = 1', ['n' => Features::screenCount()]);
        Tenant::forget();
        $this->assertSame(1, Features::screenCount());
        $this->assertStringContainsString('at most 1 screens', (string) Features::screenLimitError());
        [$code, $j] = TestEnv::http('POST', self::$url . 'api/device/register', ['device_id' => 'ft-tv-new-00002', 'room_number' => '101', 'registration_key' => 'FTKEY0000000000001']);
        $this->assertSame(403, $code);
        $this->assertSame('LICENSE_LIMIT', $j['error']['code']);
        DB::query('UPDATE hotels SET max_tvs = NULL WHERE id = 1');
        Tenant::forget();
        $this->assertNull(Features::screenLimitError());

        // Storage (storage_mb = 1): measured folder size, cached; uploads beyond the limit refused.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ftimg');
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $tmp);
        $file = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
        Features::$storageOverride = [1 => 2 * 1024 * 1024];
        try {
            Uploader::handle($file, 'image');
            $this->fail('storage limit not applied');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Storage full: your plan includes 1 MB and 2 MB are used.', $e->getMessage());
        }
        Uploader::handle($file, 'logo', 'platform'); // platform files are not counted
        Features::$storageOverride = null;
        // Real folder size (other tests may have uploaded files already): leave ~100 KB of headroom.
        @mkdir(HC_ROOT . '/uploads/h1', 0755, true);
        Cache::clear('storage');
        $before = Features::storageUsed(1);
        $limitMb = intdiv($before + 600 * 1024, 1048576) + 1;
        DB::query('UPDATE hotels SET storage_mb = :m WHERE id = 1', ['m' => $limitMb]);
        Tenant::forget();
        $big = $limitMb * 1048576 - $before - 100 * 1024;
        file_put_contents(HC_ROOT . '/uploads/h1/ft_big.bin', str_repeat('x', $big));
        Cache::clear('storage');
        $this->assertSame($before + $big, Features::storageUsed(1));
        $up = Uploader::handle($file, 'image');
        $this->assertNotEmpty($up['path'], 'fits');
        try {
            Features::checkStorage(200 * 1024);
            $this->fail('cached usage must grow after an upload');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Storage full', $e->getMessage());
        }
        @unlink(HC_ROOT . '/uploads/h1/ft_big.bin');
        @unlink($tmp);
        DB::query('UPDATE hotels SET storage_mb = NULL, plan_id = NULL WHERE id = 1');
        Tenant::forget();
        Cache::clear('storage');
        Features::checkStorage(500 * 1024 * 1024); // unlimited: no exception
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ plan editor + customer overrides

    public function testPlanEditorCrudValidationCsrfAndPlatformOnly(): void
    {
        // Platform only.
        $boss = new AdminSession(self::$url, 'ftBoss');
        [$code] = $boss->get('platform_plans.php');
        $this->assertSame(403, $code);
        [$code] = $boss->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Hack', 'features' => ['content']]);
        $this->assertSame(403, $code);
        $this->assertFalse((bool) DB::value("SELECT id FROM plans WHERE name = 'Hack'"));

        $root = new AdminSession(self::$url, 'ftRoot');
        [$code, , $html] = $root->get('platform_plans.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Copy plan', $html);
        [$code, , $html] = $root->get('platform_plans.php?action=new');
        $this->assertSame(200, $code);
        $this->assertNoPhpErrors($html, 'plan editor');
        foreach (array_keys(Features::GROUPS) as $g) {
            if ($g !== 'core') {
                $this->assertStringContainsString('data-feature-group="' . $g . '"', $html);
            }
        }
        $this->assertStringContainsString('data-group-select="all"', $html);
        $this->assertStringContainsString('data-preset=', $html);
        $this->assertStringContainsString('name="max_users"', $html);
        $this->assertStringContainsString('name="storage_mb"', $html);

        // CSRF
        [$code] = TestEnv::http('POST', self::$url . 'admin/platform_plans.php', null, [], $root->jar, ['op' => 'save', 'id' => '0', 'name' => 'NoCsrf', 'features[0]' => 'content']);
        $this->assertSame(419, $code);
        $this->assertFalse((bool) DB::value("SELECT id FROM plans WHERE name = 'NoCsrf'"));

        // Validation
        $root->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Bad', 'price_per_tv_month' => '-5', 'max_users' => 'abc', 'features' => ['content']]);
        $this->assertFalse((bool) DB::value("SELECT id FROM plans WHERE name = 'Bad'"));
        [, , $html] = $root->get('platform_plans.php?action=new');
        $this->assertStringContainsString('Invalid price.', $html);
        $this->assertStringContainsString('Max users must be a whole number', $html);
        $root->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Empty', 'price_per_tv_month' => '5']);
        $this->assertFalse((bool) DB::value("SELECT id FROM plans WHERE name = 'Empty'"), 'at least one feature');
        $root->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Basic', 'price_per_tv_month' => '5', 'features' => ['content']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM plans WHERE name = 'Basic'"), 'duplicate name');

        // Create
        [$code] = $root->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Signage Lite', 'description' => 'Small shops', 'price_per_tv_month' => '79.50',
            'max_tvs' => '10', 'max_users' => '2', 'storage_mb' => '500', 'features' => ['content', 'playlists', 'tickers', 'no_such_key'], 'is_active' => 1]);
        $this->assertSame(302, $code);
        $this->assertFalse((bool) DB::value("SELECT id FROM plans WHERE name = 'Signage Lite'"), 'unknown keys are refused');
        $root->post('platform_plans.php', ['op' => 'save', 'id' => 0, 'name' => 'Signage Lite', 'description' => 'Small shops', 'price_per_tv_month' => '79.50',
            'max_tvs' => '10', 'max_users' => '2', 'storage_mb' => '500', 'features' => ['content', 'playlists', 'tickers'], 'is_active' => 1]);
        $p = DB::one("SELECT * FROM plans WHERE name = 'Signage Lite'");
        $this->assertNotNull($p);
        $this->assertSame('79.50', (string) $p['price_per_tv_month']);
        $this->assertSame([10, 2, 500], [(int) $p['max_tvs'], (int) $p['max_users'], (int) $p['storage_mb']]);
        $this->assertEqualsCanonicalizing(['content', 'playlists', 'tickers'], Features::planKeys($p['features']));
        $this->assertContains('dashboard', json_decode((string) $p['features'], true), 'format marker');
        // Edit: tick everything → NULL (all, also future features); limits emptied → unlimited.
        self::usePlan((int) $p['id']);
        $root->post('platform_plans.php', ['op' => 'save', 'id' => $p['id'], 'name' => 'Signage Lite', 'price_per_tv_month' => '80', 'max_tvs' => '', 'max_users' => '', 'storage_mb' => '', 'features' => Features::optionalKeys(), 'is_active' => 1]);
        $p = DB::one('SELECT * FROM plans WHERE id = :id', ['id' => $p['id']]);
        $this->assertNull($p['features']);
        $this->assertNull($p['max_users']);
        Tenant::forget();
        $this->assertTrue(Features::enabled('ads'));
        // Copy
        $root->post('platform_plans.php', ['op' => 'copy', 'id' => $p['id']]);
        $copy = DB::one("SELECT * FROM plans WHERE name = 'Copy of Signage Lite'");
        $this->assertNotNull($copy);
        $this->assertSame(0, (int) $copy['is_active'], 'copies start inactive');
        $this->assertSame('80.00', (string) $copy['price_per_tv_month']);
        [$code, , $html] = $root->get('platform_plans.php?action=edit&id=' . $copy['id']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Copy of Signage Lite', $html);
        // Delete: refused while in use, allowed otherwise.
        $root->post('platform_plans.php', ['op' => 'delete', 'id' => $p['id']]);
        $this->assertNotNull(DB::one('SELECT id FROM plans WHERE id = :id', ['id' => $p['id']]));
        $root->post('platform_plans.php', ['op' => 'delete', 'id' => $copy['id']]);
        $this->assertNull(DB::one('SELECT id FROM plans WHERE id = :id', ['id' => $copy['id']]));
        [, , $html] = $root->get('platform_plans.php');
        $this->assertNoPhpErrors($html, 'plan list');
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testCustomerOverridesOnTheCustomerForm(): void
    {
        $h2 = self::$id['h2'];
        $basic = self::plan(Features::presetKeys('Basic'), ['max_users' => 1]);
        $root = new AdminSession(self::$url, 'ftRoot');
        [$code, , $html] = $root->get('platform_hotels.php?action=edit&id=' . $h2);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('name="feature_override[designer]"', $html);
        $this->assertNoPhpErrors($html, 'customer form');
        $root->post('platform_hotels.php', ['op' => 'save', 'id' => $h2, 'name' => 'Hotel Two', 'plan_id' => $basic, 'status' => 'active',
            'feature_overrides_form' => 1, 'feature_override[designer]' => 'add', 'feature_override[tickers]' => 'remove', 'feature_override[dashboard]' => 'remove',
            'max_users' => '3', 'storage_mb' => '250']);
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $h2]);
        $this->assertSame((int) $basic, (int) $h['plan_id']);
        $this->assertSame(['add' => ['designer'], 'remove' => ['tickers']], Features::parseOverrides($h['feature_overrides']));
        $this->assertSame([3, 250], [(int) $h['max_users'], (int) $h['storage_mb']]);
        Tenant::forget();
        $this->assertTrue(Features::enabled('designer', $h2));
        $this->assertFalse(Features::enabled('tickers', $h2));
        $this->assertTrue(Features::enabled('content', $h2));
        $this->assertFalse(Features::enabled('ads', $h2));
        $this->assertSame(3, Features::limits($h2)['max_users']);
        // Summary of the effective features on the form.
        [, , $html] = $root->get('platform_hotels.php?action=edit&id=' . $h2);
        $this->assertMatchesRegularExpression('#data-feature="designer" data-on="1"#', $html);
        $this->assertMatchesRegularExpression('#data-feature="tickers" data-on="0"#', $html);
        $this->assertMatchesRegularExpression('#<option value="add" selected>#', $html);
        // Invalid limit → error, nothing changed.
        $root->post('platform_hotels.php', ['op' => 'save', 'id' => $h2, 'name' => 'Hotel Two', 'plan_id' => $basic, 'status' => 'active', 'feature_overrides_form' => 1, 'max_users' => '-1']);
        $this->assertSame(3, (int) DB::value('SELECT max_users FROM hotels WHERE id = :id', ['id' => $h2]));
        // Clearing the overrides.
        $root->post('platform_hotels.php', ['op' => 'save', 'id' => $h2, 'name' => 'Hotel Two', 'plan_id' => '', 'status' => 'active', 'feature_overrides_form' => 1, 'max_users' => '', 'storage_mb' => '']);
        $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $h2]);
        $this->assertNull($h['feature_overrides']);
        $this->assertNull($h['max_users']);
        // Resellers cannot set overrides (their form has no such fields; posted ones are ignored).
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ every page without warnings

    public function testEveryCustomerPageUnderARestrictedPlanWithoutPhpWarnings(): void
    {
        self::usePlan(self::plan(Features::presetKeys('Basic')));
        $s = new AdminSession(self::$url, 'ftBoss');
        $skip = ['ajax.php', 'ajax_update.php', 'login.php', 'logout.php', 'manifest.php', 'pwa_icon.php', 'invoice.php', 'setup_file.php'];
        foreach (glob(HC_ROOT . '/admin/*.php') ?: [] as $f) {
            $page = basename($f);
            if (in_array($page, $skip, true) || preg_match(Features::PLATFORM_PAGE_PATTERN, $page)) {
                continue;
            }
            [$code, , $html] = $s->get($page);
            $this->assertNoPhpErrors($html, $page);
            $owners = Features::pageOwners($page);
            if ($owners && !Features::anyEnabled($owners)) {
                $this->assertSame(403, $code, $page . ' (not in Basic)');
                $this->assertStringContainsString('data-feature-denied="' . $owners[0] . '"', $html, $page);
            } else {
                $this->assertNotSame(500, $code, $page);
                $this->assertStringNotContainsString('data-feature-denied', $html, $page . ' is in the plan');
            }
        }
        // Dashboard widgets of disabled features are not rendered.
        [, , $html] = $s->get('index.php');
        $this->assertStringNotContainsString('hcAnalyticsWidget', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }
}
