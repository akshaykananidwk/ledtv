<?php
/**
 * 2.6.1 landing page browser QA — sandbox server for tests/browser/landing_qa.js.
 *
 *   HC_TEST_DB_NAME=hotelcast_test_x php tests/browser/landing_server.php
 *
 * Copies the app into a fresh sandbox, WIPES the test database, turns on online sign-up and the public demo,
 * sets a support phone / e-mail, starts `php -S` and prints one JSON line {url, root}. Serves until stdin closes.
 */
declare(strict_types=1);

define('HC_TESTING', true);
$appSrc = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/hotelcast_sandbox_landing_' . getmypid();

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
// Plans: the ready-made ones (Basic, Business, Pro, Hospitality); the 2.0 examples are switched off.
DB::query("UPDATE plans SET is_active = 0 WHERE name IN ('Standard', 'Premium')");
DB::query("UPDATE plans SET max_tvs = 10 WHERE name = 'Basic'");
DB::query("UPDATE plans SET max_tvs = 50, storage_mb = 10240 WHERE name = 'Business'");
Settings::setPlatform('signup_enabled', '1');
Settings::setPlatform('demo_public_enabled', '1');
Settings::setPlatform('platform_support_phone', '+91 98250 00000');
Settings::setPlatform('platform_support_email', 'hello@example.com');
foreach (['DeviceHealthTask', 'AnalyticsTask', 'SupportAlertTask', 'DemoResetTask'] as $t) {
    Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
}
Cache::clear();

$url = TestEnv::startServer($sandbox, $appSrc . '/tests/router.php', 4);
TestEnv::writeConfig($sandbox, $url);
echo json_encode(['url' => $url, 'root' => $sandbox], JSON_UNESCAPED_SLASHES) . "\n";
while (!feof(STDIN)) {
    fgets(STDIN);
}
