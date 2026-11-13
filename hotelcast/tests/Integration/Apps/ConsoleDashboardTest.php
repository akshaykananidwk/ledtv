<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.9.5 console dashboard numbers: a Super Admin whose user row still carries a home customer (the first
 * install's business) is sent from the customer dashboard (index.php) to the console dashboard with the numbers
 * of all customers; inside an opened customer workspace index.php stays that customer's dashboard; a TV that
 * stopped polling counts as offline on the console without waiting for a Scheduler tick.
 */
final class ConsoleDashboardTest extends TestCase
{
    private static string $url;
    private static int $beta = 0;

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        // Super Admin with a home customer (hotel 1), as on installs where the installer's admin was promoted.
        DB::insert('users', ['hotel_id' => 1, 'username' => 'dashroot', 'email' => 'dashroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        Settings::set('hotel_name', 'Office');
        DB::insert('rooms', ['room_number' => 'O1', 'name' => 'Office', 'floor' => '0']);
        self::$beta = Hotels::create(['name' => 'Beta Temple']);
        Tenant::run(self::$beta, static function (): void {
            foreach (['B1', 'B2', 'B3'] as $i => $n) {
                $room = DB::insert('rooms', ['room_number' => $n, 'name' => 'Screen ' . $n, 'floor' => '1']);
                // B1 polling now, B2 stopped 10 minutes ago but still marked online, B3 offline.
                DB::insert('devices', ['device_uid' => 'tv-dash-' . $n, 'room_id' => $room, 'token_hash' => hash('sha256', 'dash' . $n),
                    'status' => $i < 2 ? 'online' : 'offline', 'last_ping' => date('Y-m-d H:i:s', time() - [5, 600, 900][$i]), 'registered_at' => now()]);
            }
        });
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::setPlatform('last_tick', (string) (time() + 86400)); // no Scheduler tick during the test
        Cache::clear();
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public function testSuperAdminGetsTheConsoleDashboard(): void
    {
        $root = new AdminSession(self::$url, 'dashroot');
        [$s, , , $head] = $root->get('index.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('platform_overview.php', $head);

        [$s, , $html] = $root->get('platform_overview.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $c = PlatformScreens::counters(null);
        $this->assertSame(3, $c['total']);
        $this->assertSame(1, $c['online'], 'B2 stopped polling: offline at once');
        $this->assertSame(2, $c['offline']);
        $this->assertSame('offline', DB::value("SELECT status FROM devices WHERE device_uid = 'tv-dash-B2'"));

        // Inside a customer workspace the customer dashboard is shown (that customer's numbers).
        [$s] = $root->post('platform_hotels.php', ['op' => 'enter', 'id' => self::$beta]);
        $this->assertSame(302, $s);
        [$s, , $html] = $root->get('index.php');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('Beta Temple', $html);
        $this->assertStringNotContainsString('>Office<', $html);
    }
}
