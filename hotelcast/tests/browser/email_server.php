<?php
/**
 * 2.8 email browser QA — sandbox server for tests/browser/email_qa.js.
 *
 *   HC_TEST_DB_NAME=hotelcast_test_z php tests/browser/email_server.php
 *
 * Copies the app into a fresh sandbox, WIPES the test database, seeds a Super Admin, a customer owner (EN)
 * and a customer manager (GU), saves example SMTP settings (password encrypted), requests two password
 * resets (the e-mails land in the sandbox outbox — nothing is sent), stores the reset e-mails' HTML as
 * email_preview_<lang>.html in the sandbox root, starts `php -S` and prints one JSON line. Serves until
 * stdin closes.
 */
declare(strict_types=1);

define('HC_TESTING', true);
$appSrc = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/hotelcast_sandbox_email_' . getmypid();

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
$pw = Auth::hash('Passw0rd!');
DB::insert('users', ['hotel_id' => null, 'username' => 'superadmin', 'email' => 'owner@krishnacloud.test', 'full_name' => 'Krishna Admin', 'password_hash' => $pw, 'role' => 'platform_admin', 'language' => 'en']);
$hid = Hotels::create(['name' => 'Shree Mandir Rajkot', 'city' => 'Rajkot', 'contact_email' => 'office@shreemandir.test']);
$en = Hotels::createHotelUser($hid, ['username' => 'mandiradmin', 'email' => 'admin@shreemandir.test', 'password' => 'Passw0rd!', 'full_name' => 'Mandir Admin'], 'super_admin');
$gu = Hotels::createHotelUser($hid, ['username' => 'mandirgu', 'email' => 'manager@shreemandir.test', 'password' => 'Passw0rd!', 'full_name' => 'મંદિર મેનેજર', 'language' => 'gu'], 'manager');
foreach (['platform_support_email' => 'support@krishnacloud.test', 'platform_support_phone' => '+91 98250 00000',
    'mail_transport' => 'smtp', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_encryption' => 'tls',
    'smtp_username' => 'krishnacloud.tv@gmail.com', 'mail_from_email' => 'krishnacloud.tv@gmail.com', 'mail_from_name' => 'Krishna Cloud TV'] as $k => $v) {
    Settings::setPlatform($k, $v);
}
Settings::setSecret('smtp_password', 'abcd efgh ijkl mnop');
foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
    Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
}

$url = TestEnv::startServer($sandbox, $appSrc . '/tests/router.php', 4);
TestEnv::writeConfig($sandbox, $url);
Config::set('base_url', $url);

// Two reset e-mails (EN / GU) via the real flow; the HTML is kept for a screenshot of the e-mail.
$tokens = [];
foreach (['en' => 'admin@shreemandir.test', 'gu' => 'mandirgu'] as $lang => $login) {
    PasswordReset::request($login, '10.0.0.' . ($lang === 'en' ? 1 : 2));
    $box = Mailer::outbox();
    $m = end($box);
    preg_match('/token=([a-f0-9]{64})/', (string) $m['text'], $mm);
    $tokens[$lang] = $mm[1] ?? '';
    file_put_contents($sandbox . '/email_preview_' . $lang . '.html', (string) $m['html']);
}
// A failed send for the log (unreachable SMTP on loopback).
Mailer::send('someone@example.test', 'Krishna Cloud TV: test', 'x', null, ['config' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => TestEnv::freePort(), 'encryption' => 'none', 'timeout' => 2]]);
DB::query('DELETE FROM rate_limits');

echo json_encode(['url' => $url, 'root' => $sandbox, 'tokens' => $tokens], JSON_UNESCAPED_SLASHES) . "\n";
while (!feof(STDIN)) {
    fgets(STDIN);
}
