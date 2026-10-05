<?php
/**
 * PHPUnit bootstrap.
 *
 * Creates an isolated sandbox copy of the application (so tests never touch the real
 * installation), writes .env / config.php / installed.lock for the TEST database and
 * boots HotelCast from the sandbox.
 *
 * Test DB settings come from environment variables:
 *   HC_TEST_DB_HOST (127.0.0.1) HC_TEST_DB_PORT (3306) HC_TEST_DB_NAME (hotelcast_test)
 *   HC_TEST_DB_USER (hctest)    HC_TEST_DB_PASS (hctest_pw_123)
 * The test database is WIPED on every run.
 */
declare(strict_types=1);

define('HC_TESTING', true);

$appSrc = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/hotelcast_sandbox_' . getmypid();

require __DIR__ . '/TestEnv.php';
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
register_shutdown_function(static function () use ($sandbox) {
    TestEnv::stopServers();
    if (!getenv('HC_KEEP_SANDBOX')) {
        TestEnv::rmTree($sandbox);
    }
});

require $sandbox . '/core/bootstrap.php';
require $sandbox . '/install/Installer.php';
TestEnv::resetDatabase();
