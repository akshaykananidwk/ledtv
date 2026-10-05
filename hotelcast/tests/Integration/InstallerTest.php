<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Installer test on a fresh copy of the application (no config, no database tables):
 * walks every wizard step over HTTP and verifies the complete setup.
 */
final class InstallerTest extends TestCase
{
    private static string $dir;
    private static string $url;
    private static string $jar;
    private static string $dbName;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hc_install_' . getmypid();
        TestEnv::makeSandbox(self::$dir, false);
        self::$jar = tempnam(sys_get_temp_dir(), 'jar');
        self::$dbName = 'hotelcast_inst_' . getmypid();
        self::$url = TestEnv::startServer(self::$dir, __DIR__ . '/../router.php', 2);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            DB::pdo()->exec('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        } catch (Throwable) {
        }
        TestEnv::rmTree(self::$dir);
        @unlink(self::$jar);
    }

    private function page(): string
    {
        [, , $body] = TestEnv::http('GET', self::$url . 'install/', null, [], self::$jar);
        return $body;
    }

    private function csrf(string $html): string
    {
        $this->assertMatchesRegularExpression('/name="_csrf" value="([a-f0-9]+)"/', $html);
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);
        return $m[1];
    }

    private function post(array $form): array
    {
        return TestEnv::http('POST', self::$url . 'install/', null, [], self::$jar, $form);
    }

    public function testUninstalledAppRedirectsToInstaller(): void
    {
        [$s, , , $head] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('install/', $head);
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/health');
        $this->assertSame(503, $s);
        $this->assertSame('NOT_INSTALLED', $j['error']['code']);
    }

    public function testFullInstallation(): void
    {
        $db = TestEnv::$db;

        // Step 1: requirements
        $html = $this->page();
        $this->assertStringContainsString('Requirements', $html);
        $this->assertStringContainsString('PHP 8.1', $html);
        $this->post(['_csrf' => $this->csrf($html)]);

        // Step 2: database — wrong password first
        $html = $this->page();
        $this->assertStringContainsString('Database connection', $html);
        $csrf = $this->csrf($html);
        [, , $body] = $this->post(['_csrf' => $csrf, 'db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => self::$dbName, 'db_user' => $db['user'], 'db_pass' => 'wrong-password']);
        $this->assertStringContainsString('Access denied', $body);
        // Test-connection AJAX
        [, $j] = TestEnv::http('POST', self::$url . 'install/?action=testdb', null, [], self::$jar, ['_csrf' => $csrf, 'db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass']]);
        $this->assertTrue($j['ok'], json_encode($j));
        // Correct credentials; database does not exist yet → created automatically
        $this->post(['_csrf' => $csrf, 'db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => self::$dbName, 'db_user' => $db['user'], 'db_pass' => $db['pass']]);
        $this->assertFileExists(self::$dir . '/.env');
        $this->assertFileExists(self::$dir . '/config.php');
        $this->assertStringContainsString('APP_KEY=', file_get_contents(self::$dir . '/.env'));

        // Step 3: tables + demo data
        $html = $this->page();
        $this->assertStringContainsString('Create database tables', $html);
        $this->post(['_csrf' => $csrf, 'demo' => '1']);

        // Step 4: admin account (weak password rejected first)
        $html = $this->page();
        $this->assertStringContainsString('Created tables', $html);
        [, , $body] = $this->post(['_csrf' => $csrf, 'username' => 'owner', 'email' => 'owner@hotel.test', 'full_name' => 'Owner', 'password' => 'weak', 'password2' => 'weak']);
        $this->assertStringContainsString('at least 8', $body);
        $this->post(['_csrf' => $csrf, 'username' => 'owner', 'email' => 'owner@hotel.test', 'full_name' => 'Owner', 'password' => 'Str0ngPass!', 'password2' => 'Str0ngPass!']);

        // Step 5: hotel
        $html = $this->page();
        $this->assertStringContainsString('Hotel details', $html);
        $this->post(['_csrf' => $csrf, 'hotel_name' => 'હોટેલ દ્વારકા Palace', 'timezone' => 'Asia/Kolkata', 'language' => 'gu', 'weather_city' => 'Dwarka', 'base_url' => self::$url]);

        // Step 6: GitHub
        $html = $this->page();
        $this->assertStringContainsString('GitHub auto-update', $html);
        $this->post(['_csrf' => $csrf, 'github_repo' => 'https://github.com/hotel/hotelcast', 'github_branch' => 'main', 'github_subdir' => 'hotelcast', 'github_token' => 'github_pat_x']);

        // Step 7: done (rendered from memory, installer folder deleted)
        [, , $body] = TestEnv::http('GET', self::$url . 'install/', null, [], self::$jar);
        $this->assertFileExists(self::$dir . '/installed.lock');
        $this->assertDirectoryDoesNotExist(self::$dir . '/install', 'Installer folder auto-deleted');

        // Verify database contents directly
        $pdo = DB::connect(['host' => $db['host'], 'port' => $db['port'], 'name' => self::$dbName, 'user' => $db['user'], 'pass' => $db['pass']]);
        // The installing user is platform admin and works inside hotel #1.
        $this->assertSame('platform_admin', $pdo->query("SELECT role FROM users WHERE username='owner'")->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT hotel_id FROM users WHERE username='owner'")->fetchColumn());
        $this->assertSame('હોટેલ દ્વારકા Palace', $pdo->query('SELECT name FROM hotels WHERE id = 1')->fetchColumn());
        $this->assertSame($pdo->query("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key='registration_key'")->fetchColumn(), $pdo->query('SELECT registration_key FROM hotels WHERE id = 1')->fetchColumn());
        $this->assertSame('', (string) $pdo->query("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key='github_repo'")->fetchColumn(), 'GitHub settings are platform-wide (hotel 0)');
        $this->assertSame(20, (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn(), 'Demo rooms');
        $this->assertSame('હોટેલ દ્વારકા Palace', $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='hotel_name'")->fetchColumn());
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='registration_key'")->fetchColumn());
        $this->assertStringStartsWith('enc:', (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='github_token'")->fetchColumn(), 'Token encrypted');

        // Installed app works
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/health');
        $this->assertSame(200, $s);
        $this->assertTrue($j['data']['db']);
        [$s] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertSame(200, $s);
    }

    #[PHPUnit\Framework\Attributes\Depends('testFullInstallation')]
    public function testReinstallBlocked(): void
    {
        // Even if someone re-uploads the installer, installed.lock blocks it.
        TestEnv::makeSandbox(self::$dir . '_tmp', false);
        rename(self::$dir . '_tmp/install', self::$dir . '/install');
        TestEnv::rmTree(self::$dir . '_tmp');
        [$s, , $body] = TestEnv::http('GET', self::$url . 'install/');
        $this->assertSame(403, $s);
        $this->assertStringContainsString('already installed', $body);
    }
}
