<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The extension points other modules rely on (docs/DEVELOPER.md §2): nav.d menu items, lang module
 * files (gu / hi), content extensions, API route files, scheduler tasks, ajax.d actions, dashboard
 * widgets and boot.d registration of tenant tables / permissions. Test files are written into the
 * sandbox only and removed afterwards.
 */
final class ExtensionPointsTest extends TestCase
{
    private static string $url;
    private static array $files = [];

    private static function put(string $rel, string $code): void
    {
        $path = HC_ROOT . '/' . $rel;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $code);
        self::$files[] = $path;
    }

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'extboss', 'email' => 'ext@x.test', 'password_hash' => Auth::hash('Passw0rd!'), 'role' => 'super_admin']);
        self::put('admin/partials/nav.d/95_testmod.php', "<?php return [['testmod', 'profile.php', 'dashboard.view', 'bi-star', __('Test Module'), 'hotel']];");
        self::put('admin/partials/dashboard.d/95_testmod.php', "<?php echo '<div class=\"col\">TEST-WIDGET-' . (int) Tenant::id() . '</div>';");
        self::put('admin/ajax.d/testmod.php', "<?php if (\$action === 'testmod_hello') { require_can('dashboard.view'); ajax_ok(['hello' => 'world', 'hotel' => Tenant::id(), 'method' => \$method]); }");
        self::put('api/routes/zz_testmod.php', "<?php return static function (string \$route, array \$parts, string \$method): bool {\n if (\$route !== 'testmod/ping') { return false; }\n Api::ok(['pong' => true, 'method' => \$method]);\n};");
        self::put('lang/gu_testmod.php', "<?php return ['Test Module' => 'ટેસ્ટ મોડ્યુલ'];");
        self::put('lang/hi_testmod.php', "<?php return ['Test Module' => 'टेस्ट मॉड्यूल'];");
        self::put('core/Extensions/TestBadgeExtension.php', "<?php\nfinal class TestBadgeExtension implements ContentExtension {\n public function apply(array &\$content, array \$room): void { \$content['test_badge'] = 'hotel-' . Tenant::id() . '-room-' . \$room['room_number']; }\n}");
        self::put('core/Tasks/TestCounterTask.php', "<?php\nfinal class TestCounterTask implements Task {\n public function interval(): int { return 3600; }\n public function run(): array { Settings::setPlatform('testmod_runs', (string) ((int) Settings::platform('testmod_runs', '0') + 1)); return ['ok' => true]; }\n}");
        self::put('core/boot.d/testmod.php', "<?php Tenant::registerTable('testmod_items'); Auth::registerPermission('testmod.manage', 'manager'); Auth::registerPermission('testmod.platform', ['platform_admin']);");
        ContentResolver::resetExtensions();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $f) {
            @unlink($f);
        }
        ContentResolver::resetExtensions();
        I18n::reset();
        DB::pdo()->exec('DROP TABLE IF EXISTS testmod_items');
    }

    public function testNavMenuWidgetAndAjaxModule(): void
    {
        $s = new AdminSession(self::$url, 'extboss');
        [$code, , $html] = $s->get('index.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Test Module', $html, 'nav.d item');
        $this->assertStringContainsString('TEST-WIDGET-1', $html, 'dashboard.d widget');
        [$code, $j] = $s->ajax('testmod_hello');
        $this->assertSame(200, $code);
        $this->assertSame(['hello' => 'world', 'hotel' => 1, 'method' => 'GET'], $j['data']);
        [$code] = $s->ajax('testmod_unknown');
        $this->assertSame(404, $code);
        // Gujarati module file is merged.
        $s->ajax('set_language', ['lang' => 'gu']);
        [, , $html] = $s->get('index.php');
        $this->assertStringContainsString('ટેસ્ટ મોડ્યુલ', $html);
    }

    public function testLanguageModuleFilesAndHindi(): void
    {
        I18n::reset();
        $this->assertSame('ટેસ્ટ મોડ્યુલ', I18n::table('gu')['Test Module'] ?? null);
        $this->assertSame('टेस्ट मॉड्यूल', I18n::translate('Test Module', 'hi'));
        $this->assertSame('Test Module', I18n::translate('Test Module', 'en'));
        $this->assertArrayHasKey('hi', I18n::GUEST_LANGUAGES);
        $this->assertArrayNotHasKey('hi', I18n::LANGUAGES, 'admin UI stays EN / GU');
    }

    public function testApiRouteFile(): void
    {
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/testmod/ping', []);
        $this->assertSame(200, $s);
        $this->assertSame(['pong' => true, 'method' => 'POST'], $j['data']);
        [$s] = TestEnv::http('GET', self::$url . 'api/testmod/other');
        $this->assertSame(404, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/routes/zz_testmod.php');
        $this->assertSame(403, $s, 'route files are not directly reachable');
    }

    public function testContentExtension(): void
    {
        $rid = DB::insert('rooms', ['room_number' => 'E1']);
        $c = ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $rid]));
        $this->assertSame('hotel-1-room-E1', $c['test_badge']);
        $this->assertSame(sha1(json_out(array_diff_key($c, ['hash' => 1, 'generated_at' => 1]))), $c['hash'], 'extension output is part of the hash');
    }

    public function testSchedulerTask(): void
    {
        $this->assertArrayHasKey('TestCounterTask', Scheduler::tasks());
        $this->assertArrayHasKey('BillingTask', Scheduler::tasks());
        $this->assertArrayHasKey('LicenseTask', Scheduler::tasks());
        Settings::setPlatform('task_last_TestCounterTask', '0');
        Settings::setPlatform('testmod_runs', '0');
        $r = Scheduler::runTasks();
        $this->assertSame(['ok' => true], $r['TestCounterTask']);
        $this->assertArrayNotHasKey('TestCounterTask', Scheduler::runTasks(), 'interval respected');
        Settings::flush();
        $this->assertSame('1', Settings::platform('testmod_runs'));
        $this->assertSame(1, Tenant::current(), 'context restored after tasks');
    }

    public function testBootHookRegistersTenantTableAndPermissions(): void
    {
        require HC_ROOT . '/core/boot.d/testmod.php';
        DB::pdo()->exec('CREATE TABLE IF NOT EXISTS testmod_items (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hotel_id INT UNSIGNED NOT NULL DEFAULT 1, name VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        $this->assertTrue(Tenant::isTenantTable('testmod_items'));
        $a = DB::insert('testmod_items', ['name' => 'a']);
        $b = Tenant::run(2 === (int) DB::value('SELECT COUNT(*) FROM hotels WHERE id = 2') ? 2 : Hotels::create(['name' => 'Ext Hotel']), fn () => DB::insert('testmod_items', ['name' => 'b']));
        $this->assertSame(1, (int) DB::value('SELECT hotel_id FROM testmod_items WHERE id = :id', ['id' => $a]));
        $this->assertSame(0, DB::update('testmod_items', ['name' => 'x'], 'id = :id', ['id' => $b]), 'scoped update');
        $this->assertSame('b', DB::value('SELECT name FROM testmod_items WHERE id = :id', ['id' => $b]));
        $this->assertNotNull(Tenant::find('testmod_items', $a));
        $this->expectException(TenantException::class);
        Tenant::find('testmod_items', $b);
    }
}
