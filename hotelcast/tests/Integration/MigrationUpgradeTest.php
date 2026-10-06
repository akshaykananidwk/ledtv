<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade of a live 1.2.0 single-hotel installation: a database with ONLY 001_init.sql and
 * realistic data is migrated in place. Everything must be preserved and belong to hotel #1, the
 * first super admin becomes platform admin, settings become per hotel / platform, old media paths
 * keep working, an interrupted migration can be resumed, and the upgraded app works over HTTP
 * (admin login + an already registered TV keeps polling with its old token).
 */
final class MigrationUpgradeTest extends TestCase
{
    private static string $dbName;
    private static ?PDO $pdo = null;
    private static array $ids = [];
    private static string $tvToken;

    public static function setUpBeforeClass(): void
    {
        self::$dbName = 'hotelcast_inst_mig_' . getmypid(); // hotelcast_inst* = test grant pattern
        $db = TestEnv::$db;
        $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        $server->exec('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$pdo = DB::connect(['host' => $db['host'], 'port' => $db['port'], 'name' => self::$dbName, 'user' => $db['user'], 'pass' => $db['pass']]);
        self::createLegacyInstall(self::$pdo);
    }

    public static function tearDownAfterClass(): void
    {
        DB::setPdo(null);
        Settings::flush();
        Tenant::forget();
        Tenant::set(1);
        try {
            self::$pdo?->exec('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        } catch (Throwable) {
        }
        self::$pdo = null;
    }

    /** Schema 001 only + data as a 1.2.0 installation has it. */
    private static function createLegacyInstall(PDO $pdo): void
    {
        foreach (Migrator::splitSql((string) file_get_contents(HC_ROOT . '/migrations/001_init.sql')) as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->exec("INSERT INTO schema_migrations (filename) VALUES ('001_init.sql')");
        $ins = function (string $table, array $row) use ($pdo): int {
            $cols = array_keys($row);
            $st = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $st->execute(array_values($row));
            return (int) $pdo->lastInsertId();
        };
        $pw = password_hash('Owner123!', PASSWORD_BCRYPT, ['cost' => 4]);
        self::$ids['owner'] = $ins('users', ['username' => 'owner', 'email' => 'owner@legacy.test', 'full_name' => 'Owner', 'password_hash' => $pw, 'role' => 'super_admin']);
        self::$ids['owner2'] = $ins('users', ['username' => 'partner', 'email' => 'partner@legacy.test', 'full_name' => 'Partner', 'password_hash' => $pw, 'role' => 'super_admin']);
        self::$ids['mgr'] = $ins('users', ['username' => 'manager', 'email' => 'mgr@legacy.test', 'full_name' => 'Mgr', 'password_hash' => $pw, 'role' => 'manager']);
        foreach ([
            'hotel_name' => 'Legacy Palace દ્વારકા', 'registration_key' => 'LEGACYKEY0000001', 'github_repo' => 'https://github.com/x/hotelcast',
            'github_branch' => 'main', 'last_tick' => '12345', 'backup_keep' => '7', 'ticker_text' => 'Legacy ticker', 'tv_settings_pin' => '9876',
            'content_version' => '41', 'timezone' => 'Asia/Kolkata', 'poll_interval' => '8',
        ] as $k => $v) {
            $ins('system_settings', ['setting_key' => $k, 'setting_value' => $v]);
        }
        self::$ids['room'] = $ins('rooms', ['room_number' => '101', 'name' => 'Deluxe 101', 'floor' => '1']);
        self::$ids['room2'] = $ins('rooms', ['room_number' => '102', 'name' => 'Deluxe 102', 'floor' => '1']);
        self::$ids['group'] = $ins('room_groups', ['name' => 'Floor 1', 'type' => 'floor']);
        $ins('room_group_members', ['room_id' => self::$ids['room'], 'group_id' => self::$ids['group']]);
        self::$ids['image'] = $ins('content_items', ['title' => 'Legacy image', 'type' => 'image', 'file_path' => 'media/2026/09/legacy.jpg', 'thumb_path' => 'media/2026/09/legacy_thumb.jpg', 'duration' => 10]);
        self::$ids['ann'] = $ins('content_items', ['title' => 'Legacy welcome', 'type' => 'announcement', 'body' => 'Welcome!', 'duration' => 10]);
        self::$ids['playlist'] = $ins('content_playlists', ['name' => 'Legacy loop']);
        $ins('playlist_items', ['playlist_id' => self::$ids['playlist'], 'content_id' => self::$ids['image'], 'sort_order' => 0]);
        $ins('playlist_items', ['playlist_id' => self::$ids['playlist'], 'content_id' => self::$ids['ann'], 'sort_order' => 1]);
        $pdo->exec('UPDATE rooms SET playlist_id = ' . self::$ids['playlist'] . ' WHERE id = ' . self::$ids['room']);
        self::$ids['bc'] = $ins('broadcast_commands', ['title' => 'Old push', 'command' => 'SHOW_CONTENT', 'target_type' => 'all', 'target_ids' => '[]', 'content_id' => self::$ids['ann'], 'mode' => 'now', 'status' => 'completed']);
        self::$tvToken = bin2hex(random_bytes(32));
        self::$ids['device'] = $ins('devices', ['device_uid' => 'legacy-tv-000001', 'room_id' => self::$ids['room'], 'token_hash' => hash('sha256', self::$tvToken), 'status' => 'online', 'last_ping' => date('Y-m-d H:i:s')]);
        $ins('device_commands', ['device_id' => self::$ids['device'], 'command' => 'PING', 'status' => 'acked']);
        $ins('broadcast_logs', ['broadcast_id' => self::$ids['bc'], 'device_id' => self::$ids['device'], 'room_id' => self::$ids['room'], 'event' => 'acked']);
        $ins('device_status_logs', ['device_id' => self::$ids['device'], 'room_id' => self::$ids['room'], 'status' => 'online']);
        $ins('activity_logs', ['user_id' => self::$ids['owner'], 'username' => 'owner', 'action' => 'login']);
        self::$ids['apk'] = $ins('apk_releases', ['version_name' => '1.2.0', 'version_code' => 12, 'file_path' => 'apk/hotelcast_old.apk', 'file_size' => 10, 'sha256' => str_repeat('b', 64)]);
    }

    private static function useMigDb(): void
    {
        DB::setPdo(self::$pdo);
        Settings::flush();
        Tenant::forget();
        Branding::flush();
    }

    public function testUpgradePreservesDataInHotelOne(): void
    {
        self::useMigDb();
        Tenant::clear();
        $counts = [];
        foreach (['users', 'rooms', 'room_groups', 'room_group_members', 'content_items', 'content_playlists', 'playlist_items', 'broadcast_commands', 'devices', 'device_commands', 'broadcast_logs', 'device_status_logs', 'activity_logs', 'apk_releases', 'system_settings'] as $t) {
            $counts[$t] = (int) DB::value("SELECT COUNT(*) FROM `$t`");
        }
        $ran = Migrator::migrate();
        $this->assertSame(['002_multitenancy.sql', '002_multitenancy_upgrade.php'], array_values(array_filter($ran, fn ($f) => str_starts_with($f, '002'))));
        foreach ($counts as $t => $n) {
            $this->assertSame($n, (int) DB::value("SELECT COUNT(*) FROM `$t`"), "$t rows preserved");
        }
        // Hotel 1 created from the old settings.
        $h = DB::one('SELECT * FROM hotels WHERE id = 1');
        $this->assertSame('Legacy Palace દ્વારકા', $h['name']);
        $this->assertSame('LEGACYKEY0000001', $h['registration_key']);
        $this->assertSame('active', $h['status']);
        // Every tenant row belongs to hotel 1.
        foreach (['rooms', 'room_groups', 'content_items', 'content_playlists', 'broadcast_commands', 'devices', 'apk_releases', 'activity_logs', 'broadcast_logs', 'device_status_logs', 'users'] as $t) {
            $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM `$t` WHERE hotel_id IS NULL OR hotel_id <> 1"), "$t → hotel 1");
        }
        // Settings: per hotel, platform keys on hotel 0.
        $this->assertSame('https://github.com/x/hotelcast', DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'github_repo'"));
        $this->assertSame('12345', DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'last_tick'"));
        $this->assertSame('7', DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'backup_keep'"));
        $this->assertSame('Legacy ticker', DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'ticker_text'"));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM system_settings WHERE hotel_id = 1 AND setting_key IN ('github_repo','last_tick','backup_keep')"));
        // Roles: the first super admin is platform admin (still in hotel 1); the second stays super admin.
        $this->assertSame(['platform_admin', 1], [DB::value('SELECT role FROM users WHERE id = :id', ['id' => self::$ids['owner']]), (int) DB::value('SELECT hotel_id FROM users WHERE id = :id', ['id' => self::$ids['owner']])]);
        $this->assertSame('super_admin', DB::value('SELECT role FROM users WHERE id = :id', ['id' => self::$ids['owner2']]));
        $this->assertSame('manager', DB::value('SELECT role FROM users WHERE id = :id', ['id' => self::$ids['mgr']]));
        // Unique keys are per hotel now.
        $this->assertSame(['hotel_id', 'room_number'], Migrator::indexColumns(self::$pdo, 'rooms', 'uq_rooms_hotel_number'));
        $this->assertSame(['hotel_id', 'setting_key'], Migrator::indexColumns(self::$pdo, 'system_settings', 'PRIMARY'));
        $this->assertTrue(Migrator::hasForeignKey(self::$pdo, 'rooms', 'fk_rooms_hotel'));
        $h2 = Hotels::create(['name' => 'Second Hotel']);
        Tenant::run($h2, fn () => DB::insert('rooms', ['room_number' => '101']));
        $this->assertSame(2, (int) DB::value("SELECT COUNT(*) FROM rooms WHERE room_number = '101'"));
        $this->expectException(PDOException::class);
        Tenant::run(1, fn () => DB::insert('rooms', ['room_number' => '101']));
    }

    #[Depends('testUpgradePreservesDataInHotelOne')]
    public function testAppLogicWorksOnUpgradedData(): void
    {
        self::useMigDb();
        Tenant::set(1);
        $this->assertSame('Legacy ticker', Settings::get('ticker_text'));
        $this->assertSame('9876', Settings::get('tv_settings_pin'));
        $this->assertSame('https://github.com/x/hotelcast', Settings::get('github_repo'), 'platform key read from hotel 0');
        $room = DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$ids['room']]);
        $c = ContentResolver::build($room);
        $this->assertSame('assigned', $c['mode']);
        $this->assertSame('Legacy loop', $c['playlist']['name']);
        $this->assertStringEndsWith('uploads/media/2026/09/legacy.jpg', $c['items'][0]['url'], 'old media paths still valid');
        $this->assertSame('Legacy Palace દ્વારકા', $c['hotel']['name']);
        $this->assertSame('HotelCast', $c['branding']['product']);
        $this->assertSame([], Migrator::pending());
        $this->assertSame([], Migrator::migrate(), 'idempotent');
    }

    #[Depends('testAppLogicWorksOnUpgradedData')]
    public function testUpgradedInstallationWorksOverHttp(): void
    {
        $dir = sys_get_temp_dir() . '/hotelcast_mig_app_' . getmypid();
        $orig = TestEnv::$db;
        TestEnv::$db['name'] = self::$dbName;
        try {
            TestEnv::makeSandbox($dir, true);
        } finally {
            TestEnv::$db = $orig;
        }
        try {
            $url = TestEnv::startServer($dir, __DIR__ . '/../router.php', 2);
            $orig2 = TestEnv::$db;
            TestEnv::$db['name'] = self::$dbName;
            TestEnv::writeConfig($dir, $url);
            TestEnv::$db = $orig2;
            // The old TV keeps working with its old token.
            [$s, $j] = TestEnv::http('GET', $url . 'api/device/command/legacy-tv-000001?hash=x', null, ['Authorization: Bearer ' . self::$tvToken, 'X-Device-Id: legacy-tv-000001']);
            $this->assertSame(200, $s, (string) json_encode($j));
            $this->assertSame('Legacy loop', $j['data']['content']['playlist']['name']);
            // Registration with the old key still lands in hotel 1.
            [$s, $j] = TestEnv::http('POST', $url . 'api/device/register', ['device_id' => 'legacy-tv-000002', 'room_number' => '102', 'registration_key' => 'LEGACYKEY0000001']);
            $this->assertSame(200, $s);
            $this->assertSame(1, $j['data']['hotel']['id']);
            $this->assertSame(self::$ids['room2'], $j['data']['room']['id']);
            // The old owner logs in with full access: hotel pages + platform + updater.
            $owner = new AdminSession($url, 'owner', 'Owner123!');
            foreach (['index.php', 'rooms.php', 'content.php', 'users.php', 'settings.php', 'update.php', 'platform_hotels.php'] as $p) {
                [$s, , $html] = $owner->get($p);
                $this->assertSame(200, $s, $p);
                $this->assertStringNotContainsString('Warning:', $html);
            }
            [, , $html] = $owner->get('rooms.php');
            $this->assertStringContainsString('Deluxe 101', $html);
            // The second super admin manages the hotel but not the platform.
            $partner = new AdminSession($url, 'partner', 'Owner123!');
            $this->assertSame(200, $partner->get('users.php')[0]);
            $this->assertSame(403, $partner->get('update.php')[0]);
            $log = $dir . '/logs/php_error.log';
            $this->assertFalse(is_file($log) && trim((string) file_get_contents($log)) !== '', 'no PHP warnings: ' . (is_file($log) ? file_get_contents($log) : ''));
        } finally {
            TestEnv::rmTree($dir);
        }
    }

    public function testInterruptedMigrationCanBeResumed(): void
    {
        $db = TestEnv::$db;
        $name = self::$dbName . '_r';
        $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("DROP DATABASE IF EXISTS `$name`");
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $pdo = DB::connect(['host' => $db['host'], 'port' => $db['port'], 'name' => $name, 'user' => $db['user'], 'pass' => $db['pass']]);
            self::createLegacyInstall($pdo);
            DB::setPdo($pdo);
            Settings::flush();
            Tenant::forget();
            // Simulate a crash half-way: 002 SQL applied, some columns / keys already added.
            foreach (Migrator::splitSql((string) file_get_contents(HC_ROOT . '/migrations/002_multitenancy.sql')) as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->exec("INSERT INTO schema_migrations (filename) VALUES ('002_multitenancy.sql')");
            $pdo->exec('ALTER TABLE rooms ADD COLUMN hotel_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id');
            $pdo->exec('ALTER TABLE rooms DROP INDEX uq_room_number, ADD UNIQUE KEY uq_rooms_hotel_number (hotel_id, room_number)');
            $pdo->exec('ALTER TABLE system_settings ADD COLUMN hotel_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST');
            // 002's upgrade step resumes first; later module migrations (003+) follow.
            $this->assertSame('002_multitenancy_upgrade.php', Migrator::migrate()[0] ?? null);
            $this->assertSame(['hotel_id', 'setting_key'], Migrator::indexColumns($pdo, 'system_settings', 'PRIMARY'));
            $this->assertTrue(Migrator::hasForeignKey($pdo, 'devices', 'fk_devices_hotel'));
            // Running the upgrade step once more is harmless.
            $fn = require HC_ROOT . '/migrations/002_multitenancy_upgrade.php';
            $fn($pdo, static function (): void {
            });
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'platform_admin'")->fetchColumn());
            $this->assertSame('github_repo', $pdo->query("SELECT setting_key FROM system_settings WHERE hotel_id = 0 AND setting_key = 'github_repo'")->fetchColumn());
        } finally {
            DB::setPdo(null);
            Settings::flush();
            Tenant::forget();
            $server->exec("DROP DATABASE IF EXISTS `$name`");
        }
    }
}
