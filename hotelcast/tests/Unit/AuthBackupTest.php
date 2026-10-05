<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthBackupTest extends TestCase
{
    protected function setUp(): void
    {
        TestEnv::resetDatabase();
    }

    public function testPasswordsAreBcrypt(): void
    {
        $h = Auth::hash('Secret123');
        $this->assertStringStartsWith('$2y$12$', $h);
        $this->assertTrue(password_verify('Secret123', $h));
        $this->assertNotNull(Auth::passwordError('short1'));
        $this->assertNotNull(Auth::passwordError('lettersonly'));
        $this->assertNull(Auth::passwordError('Letters123'));
    }

    public function testRolePermissions(): void
    {
        $this->assertSame(4, Auth::ROLE_LEVEL['super_admin']);
        $this->assertSame(1, Auth::ROLE_LEVEL['reception']);
        $this->assertSame(['platform_admin'], Auth::PLATFORM_PERMISSIONS['update.manage']);
        $this->assertSame(['platform_admin'], Auth::PLATFORM_PERMISSIONS['platform.manage']);
        $this->assertSame('reception', Auth::PERMISSIONS['guests.manage']);
        $this->assertSame('reception', Auth::PERMISSIONS['services.manage']);
        $this->assertSame('reception', Auth::PERMISSIONS['rooms.view']);
        $this->assertSame('super_admin', Auth::PERMISSIONS['billing.view']);
        $this->assertSame('super_admin', Auth::PERMISSIONS['users.manage']);
        $this->assertSame('manager', Auth::PERMISSIONS['content.manage']);
        $this->assertSame('staff', Auth::PERMISSIONS['broadcast.send']);
        $this->assertSame('manager', Auth::PERMISSIONS['broadcast.device_commands']);
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        DB::insert('rooms', ['room_number' => '501', 'name' => 'સ્યુટ 501', 'notes' => "line1\nline2 'quoted' \\ back"]);
        $name = Backup::create('test');
        $this->assertMatchesRegularExpression('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}(_\d+)?\.zip$/', $name);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Backup::dir() . '/' . $name));
        $this->assertNotFalse($zip->locateName('database.sql'));
        $this->assertNotFalse($zip->locateName('files/core/bootstrap.php'));
        $this->assertNotFalse($zip->locateName('files/.env'), 'Full backup keeps .env for disaster recovery (restore never overwrites it)');
        $this->assertFalse($zip->locateName('files/uploads/.htaccess'), 'Uploads excluded unless requested');
        $zip->close();

        DB::query('DELETE FROM rooms');
        DB::insert('rooms', ['room_number' => '999']);
        file_put_contents(HC_ROOT . '/core/ZZExtra.php', '<?php // added after backup');
        $marker = HC_ROOT . '/core/Version.php';
        $orig = file_get_contents($marker);
        file_put_contents($marker, $orig . "\n// modified");

        Backup::restore($name, true, true);
        $row = DB::one("SELECT * FROM rooms WHERE room_number = '501'");
        $this->assertNotNull($row);
        $this->assertSame('સ્યુટ 501', $row['name']);
        $this->assertSame("line1\nline2 'quoted' \\ back", $row['notes']);
        $this->assertNull(DB::one("SELECT * FROM rooms WHERE room_number = '999'"));
        $this->assertFileDoesNotExist(HC_ROOT . '/core/ZZExtra.php', 'Files added after backup are removed');
        $this->assertSame($orig, file_get_contents($marker));
        $this->assertFileExists(HC_ROOT . '/.env', 'Protected files untouched');
        $this->assertStringContainsString('APP_KEY', (string) file_get_contents(HC_ROOT . '/.env'));
        Backup::delete($name);
    }

    public function testProtectedPaths(): void
    {
        foreach (['.env', 'config.php', 'uploads/media/a.jpg', 'storage/apk/x.apk', 'backups/b.zip', 'logs/app.log', 'install/index.php'] as $p) {
            $this->assertTrue(Backup::isProtected($p), $p);
        }
        foreach (['core/DB.php', 'admin/index.php', 'version.json', 'configuration.php'] as $p) {
            $this->assertFalse(Backup::isProtected($p), $p);
        }
    }

    public function testRestoreRejectsBadNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Backup::path('../../etc/passwd');
    }
}
