<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Auto-update system test against a mock GitHub API:
 *   check → backup → download → migrate → health check → success
 *   failing migration → automatic rollback (files + DB restored)
 *   syntax error in package → rejected before touching files
 *   manual rollback to an earlier backup
 */
final class UpdaterTest extends TestCase
{
    private static string $mockDir;
    private static string $mockUrl;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        self::$mockDir = sys_get_temp_dir() . '/hc_mockgh_' . getmypid();
        @mkdir(self::$mockDir, 0755, true);
        self::$mockUrl = TestEnv::startServer(self::$mockDir, __DIR__ . '/../fixtures/mock_github.php', 1, ['MOCK_GH_DIR' => self::$mockDir]);
        Config::set('github_api_base', rtrim(self::$mockUrl, '/'));
        Settings::setMany(['github_repo' => 'https://github.com/hotel/hotelcast', 'github_branch' => 'main', 'github_subdir' => 'hotelcast']);
        Settings::setSecret('github_token', 'test-token');
        Version::write(['version' => '1.0.0', 'commit' => 'aaaaaaa000000000000000000000000000000000']);
    }

    public static function tearDownAfterClass(): void
    {
        TestEnv::rmTree(self::$mockDir);
    }

    /** Build a GitHub-style zipball from the current sandbox app with modifications. */
    private static function publish(string $version, string $sha, array $addFiles = [], array $removeFiles = []): void
    {
        $zip = new ZipArchive();
        $zip->open(self::$mockDir . '/package.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $prefix = 'hotel-hotelcast-' . substr($sha, 0, 7) . '/';
        $zip->addEmptyDir($prefix);
        $zip->addFromString($prefix . 'README.md', 'repo root');
        $zip->addFromString($prefix . 'android/build.gradle.kts', '// android');
        foreach (Backup::appFiles(Backup::PROTECTED) as $rel) {
            if ($rel === 'version.json' || in_array($rel, $removeFiles, true) || str_starts_with($rel, 'migrations/0') && $rel !== 'migrations/001_init.sql') {
                continue;
            }
            $zip->addFile(HC_ROOT . '/' . $rel, $prefix . 'hotelcast/' . $rel);
        }
        $zip->addFromString($prefix . 'hotelcast/version.json', json_encode(['version' => $version, 'commit' => '', 'date' => '2026-10-05']));
        foreach ($addFiles as $rel => $content) {
            $zip->addFromString($prefix . 'hotelcast/' . $rel, $content);
        }
        $zip->close();
        file_put_contents(self::$mockDir . '/state.json', json_encode(['sha' => $sha, 'version' => $version, 'message' => "Release $version\n\nDetails"]));
    }

    public function testParseRepo(): void
    {
        $this->assertSame(['owner' => 'a', 'repo' => 'b'], Updater::parseRepo('https://github.com/a/b'));
        $this->assertSame(['owner' => 'a', 'repo' => 'b'], Updater::parseRepo('https://github.com/a/b.git'));
        $this->assertSame(['owner' => 'a-1', 'repo' => 'b.c'], Updater::parseRepo('a-1/b.c'));
        $this->assertNull(Updater::parseRepo('https://evil.com/a/b'));
        $this->assertNull(Updater::parseRepo('not a repo'));
    }

    public function testCheckForUpdate(): void
    {
        self::publish('1.1.0', str_repeat('b', 40));
        $info = Updater::check();
        $this->assertTrue($info['update_available']);
        $this->assertSame('1.1.0', $info['latest']['version']);
        $this->assertSame('bbbbbbb', $info['latest']['short']);
        $this->assertSame(1, $info['changed_files'], 'Only files inside the app folder are counted');
        $this->assertSame('Release 1.1.0', $info['commits'][0]['message']);
    }

    public function testSuccessfulUpdate(): void
    {
        file_put_contents(HC_ROOT . '/uploads/guest-photo.jpg', 'keep me');
        $envBefore = file_get_contents(HC_ROOT . '/.env');
        $configBefore = file_get_contents(HC_ROOT . '/config.php');
        DB::insert('rooms', ['room_number' => '777', 'name' => 'Before update']);

        self::publish('1.1.0', str_repeat('b', 40), [
            'migrations/002_test_column.sql' => "ALTER TABLE rooms ADD COLUMN test_col INT NULL;\nINSERT INTO system_settings (setting_key, setting_value) VALUES ('feature_x', '1');",
            'core/NewFeature.php' => "<?php\nfinal class NewFeature { public const OK = true; }\n",
            '.env' => 'DB_PASS=hacked',
            'config.php' => '<?php return [];',
            'uploads/guest-photo.jpg' => 'overwritten!',
        ]);
        $result = (new Updater())->run(null);
        $this->assertTrue($result['ok'], $result['error'] . "\n" . print_r(array_column($result['log'], 'message'), true));
        $this->assertSame('1.1.0', Version::current()['version']);
        $this->assertSame(str_repeat('b', 40), Version::current()['commit']);
        $this->assertFileExists(HC_ROOT . '/core/NewFeature.php');
        $this->assertContains('002_test_column.sql', Migrator::applied());
        $this->assertContains('test_col', DB::column('SHOW COLUMNS FROM rooms'));
        $this->assertSame($envBefore, file_get_contents(HC_ROOT . '/.env'), '.env never overwritten');
        $this->assertSame($configBefore, file_get_contents(HC_ROOT . '/config.php'), 'config.php never overwritten');
        $this->assertSame('keep me', file_get_contents(HC_ROOT . '/uploads/guest-photo.jpg'), 'uploads never overwritten');
        $this->assertNotNull(DB::one("SELECT * FROM rooms WHERE room_number = '777'"), 'Data preserved');
        $h = DB::one('SELECT * FROM update_history ORDER BY id DESC LIMIT 1');
        $this->assertSame('success', $h['status']);
        $this->assertFileExists(HC_ROOT . '/backups/' . $h['backup_file']);
        $this->assertStringContainsString('health check', $h['log']);
        $this->assertFileDoesNotExist(HC_ROOT . '/storage/update.lock');
    }

    #[Depends('testSuccessfulUpdate')]
    public function testFailedMigrationRollsBackAutomatically(): void
    {
        $before = Version::current();
        self::publish('1.2.0', str_repeat('c', 40), [
            'migrations/002_test_column.sql' => "ALTER TABLE rooms ADD COLUMN test_col INT NULL;\nINSERT INTO system_settings (setting_key, setting_value) VALUES ('feature_x', '1');",
            'core/NewFeature.php' => "<?php\nfinal class NewFeature { public const OK = true; }\n",
            'core/BrokenRelease.php' => "<?php\nfinal class BrokenRelease {}\n",
            'migrations/003_broken.sql' => "ALTER TABLE rooms ADD COLUMN v12_col INT NULL;\nTHIS IS NOT VALID SQL;",
        ]);
        $result = (new Updater())->run(null);
        $this->assertFalse($result['ok']);
        $this->assertSame('rolled_back', $result['status']);
        $this->assertStringContainsString('003_broken.sql', (string) $result['error']);
        $this->assertSame($before['version'], Version::current()['version'], 'Version restored');
        $this->assertFileDoesNotExist(HC_ROOT . '/core/BrokenRelease.php', 'New files removed on rollback');
        $this->assertNotContains('v12_col', DB::column('SHOW COLUMNS FROM rooms'), 'DB schema restored');
        $this->assertNotContains('003_broken.sql', Migrator::applied());
        $h = DB::one('SELECT * FROM update_history ORDER BY id DESC LIMIT 1');
        $this->assertSame('rolled_back', $h['status'], 'History entry survives the DB restore');
        $this->assertStringContainsString('Rollback complete', $h['log']);
    }

    #[Depends('testSuccessfulUpdate')]
    public function testSyntaxErrorPackageRejectedBeforeInstall(): void
    {
        $before = file_get_contents(HC_ROOT . '/core/DB.php');
        self::publish('1.3.0', str_repeat('d', 40), ['core/DB.php' => "<?php\nfinal class DB { public function (\n"]);
        $result = (new Updater())->run(null);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('syntax', strtolower((string) $result['error']));
        $this->assertSame($before, file_get_contents(HC_ROOT . '/core/DB.php'), 'Files untouched');
        $this->assertSame('1.1.0', Version::current()['version']);
    }

    #[Depends('testSuccessfulUpdate')]
    public function testBadTokenFailsCleanly(): void
    {
        Settings::setSecret('github_token', 'wrong');
        self::publish('1.4.0', str_repeat('e', 40));
        $result = (new Updater())->run(null);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Download failed', (string) $result['error']);
        $this->assertSame('1.1.0', Version::current()['version']);
        Settings::setSecret('github_token', 'test-token');
    }

    #[Depends('testFailedMigrationRollsBackAutomatically')]
    public function testManualRollbackToFirstBackup(): void
    {
        $first = DB::value("SELECT backup_file FROM update_history WHERE status = 'success' AND action = 'update' ORDER BY id ASC LIMIT 1");
        $this->assertNotEmpty($first);
        $result = Updater::rollback((string) $first, null, true);
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('1.0.0', Version::current()['version']);
        $this->assertFileDoesNotExist(HC_ROOT . '/core/NewFeature.php');
        $this->assertNotContains('test_col', DB::column('SHOW COLUMNS FROM rooms'));
        $this->assertSame('rollback', DB::value('SELECT action FROM update_history ORDER BY id DESC LIMIT 1'));
    }
}
