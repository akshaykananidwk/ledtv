<?php
/**
 * 2.6 panels browser QA — sandbox server for tests/browser/panels_qa.js.
 *
 *   HC_TEST_DB_NAME=hotelcast_test_v php tests/browser/panels_server.php
 *
 * Copies the app into a fresh sandbox, WIPES the test database, seeds a platform admin, a reseller with two
 * customers, a direct customer with demo content and "online" TVs, a pending sign-up and an unpaid invoice,
 * starts `php -S` and prints one JSON line {url, root, users, hotels}. Serves until stdin closes.
 */
declare(strict_types=1);

define('HC_TESTING', true);
$appSrc = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/hotelcast_sandbox_panels_' . getmypid();

require $appSrc . '/tests/TestEnv.php';
TestEnv::$appSrc = $appSrc;
TestEnv::$sandbox = $sandbox;
TestEnv::$db = [
    'host' => getenv('HC_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('HC_TEST_DB_PORT') ?: 3306),
    'name' => getenv('HC_TEST_DB_NAME') ?: 'hotelcast_test',
    'user' => getenv('HC_TEST_DB_USER') ?: 'hctest',
    'pass' => getenv('HC_TEST_DB_PASS') !== false ? (string) getenv('HC_TEST_DB_PASS') : 'hctest_pw_123',
];
TestEnv::makeSandbox($sandbox, true);
register_shutdown_function(static function () use ($sandbox): void {
    TestEnv::stopServers();
    if (!getenv('HC_KEEP_SANDBOX')) {
        TestEnv::rmTree($sandbox);
    }
});
require $sandbox . '/core/bootstrap.php';
require $sandbox . '/install/Installer.php';
TestEnv::resetDatabase();

Tenant::set(1);
Settings::set('hotel_name', 'Krishna Showroom');
$pw = Auth::hash('Passw0rd!');
$r1 = DB::insert('resellers', ['name' => 'Saurashtra Digital', 'status' => 'active', 'commission_percent' => 15, 'max_hotels' => 10, 'contact_name' => 'Jay', 'email' => 'jay@saurashtra.test']);
DB::insert('users', ['hotel_id' => null, 'username' => 'superadmin', 'email' => 'super@platform.test', 'full_name' => 'Krishna Admin', 'password_hash' => $pw, 'role' => 'platform_admin', 'language' => 'en']);
DB::insert('users', ['hotel_id' => null, 'reseller_id' => $r1, 'username' => 'reseller', 'email' => 'res@saurashtra.test', 'full_name' => 'Jay Patel', 'password_hash' => $pw, 'role' => 'reseller', 'language' => 'en']);
$pro = (int) DB::value("SELECT id FROM plans WHERE name = 'Pro'") ?: null;
$basic = (int) DB::value("SELECT id FROM plans ORDER BY price_per_tv_month LIMIT 1") ?: null;
$h = [];
$h['shree'] = Hotels::create(['name' => 'Shree Mandir Rajkot', 'city' => 'Rajkot', 'plan_id' => $pro, 'contact_name' => 'Trustee', 'contact_email' => 'office@shreemandir.test', 'contact_phone' => '+91 98250 00000'],
    ['username' => 'mandiradmin', 'email' => 'admin@shreemandir.test', 'password' => 'Passw0rd!', 'full_name' => 'Mandir Admin']);
$h['hospital'] = Hotels::create(['name' => 'Sanjivani Hospital', 'city' => 'Ahmedabad', 'plan_id' => $pro, 'reseller_id' => $r1, 'expires_at' => date('Y-m-d 23:59:59', time() + 9 * 86400)],
    ['username' => 'hospadmin', 'email' => 'it@sanjivani.test', 'password' => 'Passw0rd!']);
$h['cafe'] = Hotels::create(['name' => 'Cafe Kathiyawad', 'city' => 'Jamnagar', 'plan_id' => $basic, 'reseller_id' => $r1],
    ['username' => 'cafeadmin', 'email' => 'owner@cafe.test', 'password' => 'Passw0rd!']);
$h['school'] = Hotels::create(['name' => 'Gyan Vidyalaya', 'city' => 'Bhavnagar', 'plan_id' => $basic, 'is_trial' => 1, 'expires_at' => date('Y-m-d 23:59:59', time() + 5 * 86400)],
    ['username' => 'schooladmin', 'email' => 'principal@gyan.test', 'password' => 'Passw0rd!']);
Hotels::setStatus($h['school'], 'suspended', 'manual');

// Demo content + fake TVs (online / offline) per customer.
$n = 0;
foreach (['shree' => [1 => 6, 2 => 2], 'hospital' => [1 => 4], 'cafe' => [1 => 2], 'school' => [1 => 3]] as $k => $floors) {
    Tenant::run($h[$k], static function () use ($floors, &$n, $k): void {
        Demo::sampleContent($floors);
        $i = 0;
        foreach (DB::all('SELECT id, room_number FROM rooms WHERE hotel_id = :h ORDER BY id', ['h' => Tenant::id()]) as $r) {
            $n++;
            $i++;
            $online = $i % 4 !== 0;
            DB::insert('devices', ['device_uid' => 'tv-' . $k . '-' . sprintf('%04d', $i), 'room_id' => $r['id'], 'token_hash' => hash('sha256', 'tok' . $n),
                'status' => $online ? 'online' : 'offline', 'last_ping' => $online ? now() : date('Y-m-d H:i:s', time() - 7200), 'last_heartbeat' => now(),
                'app_version' => $i % 3 === 0 ? '2.4.0' : '2.5.0', 'app_version_code' => $i % 3 === 0 ? 11 : 13, 'model' => 'Mi TV 4A', 'ip_address' => '192.168.1.' . (10 + $n), 'is_revoked' => 0, 'registered_at' => now()]);
        }
        Hotels::createHotelUser(Tenant::id(), ['username' => $k . 'staff', 'email' => $k . 'staff@demo.test', 'password' => 'Passw0rd!', 'full_name' => ucfirst($k) . ' Staff'], 'staff');
    });
}
DB::insert('invoices', ['hotel_id' => $h['shree'], 'number' => 'INV-2026-0001', 'period_from' => date('Y-m-01'), 'period_to' => date('Y-m-t'), 'plan_name' => 'Pro', 'tv_count' => 8,
    'unit_price' => 199, 'amount' => 1592, 'tax_percent' => 18, 'tax' => 286.56, 'total' => 1878.56, 'status' => 'unpaid', 'issued_at' => date('Y-m-d', time() - 40 * 86400), 'due_date' => date('Y-m-d', time() - 10 * 86400), 'created_at' => now()]);
DB::insert('invoices', ['hotel_id' => $h['hospital'], 'number' => 'INV-2026-0002', 'period_from' => date('Y-m-01'), 'period_to' => date('Y-m-t'), 'plan_name' => 'Pro', 'tv_count' => 4,
    'unit_price' => 199, 'amount' => 796, 'tax_percent' => 18, 'tax' => 143.28, 'total' => 939.28, 'status' => 'paid', 'paid_at' => now(), 'issued_at' => date('Y-m-d'), 'due_date' => date('Y-m-d', time() + 10 * 86400), 'created_at' => now()]);
if (Migrator::hasTable(DB::pdo(), 'signups')) {
    DB::insert('signups', ['status' => 'pending', 'hotel_name' => 'Patel Sweets', 'owner_name' => 'Meena Patel', 'email' => 'meena@patelsweets.test', 'mobile' => '+919800000001', 'city' => 'Rajkot', 'tv_estimate' => 3, 'language' => 'gu', 'password_hash' => $pw, 'ip_address' => '127.0.0.1', 'created_at' => now()]);
    Settings::setPlatform('signup_enabled', '1');
}
foreach (['DeviceHealthTask', 'AnalyticsTask', 'SupportAlertTask'] as $t) {
    Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
}
Settings::bumpContentVersion();
Cache::clear();

$url = TestEnv::startServer($sandbox, $appSrc . '/tests/router.php', 4);
TestEnv::writeConfig($sandbox, $url);
echo json_encode(['url' => $url, 'root' => $sandbox, 'hotels' => $h, 'users' => ['superadmin', 'reseller', 'mandiradmin']], JSON_UNESCAPED_SLASHES) . "\n";
while (!feof(STDIN)) {
    fgets(STDIN);
}
