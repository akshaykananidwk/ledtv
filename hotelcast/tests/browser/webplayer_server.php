<?php
/**
 * Web player browser test (2.4, #45) — sandbox server for tests/browser/webplayer_e2e.js.
 *
 *   HC_TEST_DB_NAME=hotelcast_test_v php tests/browser/webplayer_server.php
 *
 * Copies the app into a fresh sandbox (/tmp/hotelcast_sandbox_webplayer_<pid>), WIPES the test database,
 * seeds hotel 1 (room 101, a playlist with image + display app + announcement + split screen layout, a
 * ticker bar), starts `php -S` and prints one JSON line {url, root, key, room, ids}. It then keeps
 * serving until stdin closes (the e2e script ends it) and removes the sandbox.
 * tests/browser/webplayer_ctl.php changes data (emergency, commands) while the browser runs.
 */
declare(strict_types=1);

define('HC_TESTING', true);
$appSrc = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/hotelcast_sandbox_webplayer_' . getmypid();

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
Settings::set('hotel_name', 'Hotel Dwarka Palace');
Settings::set('overlay_clock', '1');
Settings::set('poll_interval', '3');
Settings::set('long_poll_enabled', '1');

// Media: a generated 1280x720 JPEG in the sandbox's uploads.
@mkdir($sandbox . '/uploads/h1/media/2026/10', 0755, true);
$im = imagecreatetruecolor(1280, 720);
for ($y = 0; $y < 720; $y++) {
    imageline($im, 0, $y, 1279, $y, imagecolorallocate($im, 20 + (int) ($y / 6), 60 + (int) ($y / 9), 140));
}
imagefilledellipse($im, 980, 220, 260, 260, imagecolorallocate($im, 255, 179, 0));
imagestring($im, 5, 60, 640, 'Hotel Dwarka Palace - lobby', imagecolorallocate($im, 255, 255, 255));
imagejpeg($im, $sandbox . '/uploads/h1/media/2026/10/lobby.jpg', 85);

$room = DB::insert('rooms', ['room_number' => '101', 'name' => 'Deluxe 101', 'floor' => '1', 'created_at' => now()]);
$c = static fn (array $row): int => DB::insert('content_items', $row + ['is_active' => 1, 'created_at' => now()]);
$ids = [];
$ids['image'] = $c(['title' => 'Lobby', 'type' => 'image', 'file_path' => 'h1/media/2026/10/lobby.jpg', 'duration' => 5]);
$ids['app'] = $c(['title' => 'Diwali countdown', 'type' => 'app', 'duration' => 5,
    'settings' => json_out(['app' => 'countdown', 'config' => ['title' => 'દિવાળી', 'subtitle' => 'Diwali 2026', 'target' => date('Y-m-d H:i:00', strtotime('+20 days'))], 'theme' => 'diwali', 'font' => 'auto', 'accent' => null, 'lang' => 'gu'])]);
$ids['ann'] = $c(['title' => 'Checkout', 'type' => 'announcement', 'duration' => 5, 'body' => "ચેક-આઉટ સવારે 11 વાગ્યે\nCheck-out 11:00 AM",
    'settings' => json_out(['style' => 'fullscreen', 'subtitle' => 'Thank you for staying with us', 'bg_color' => '#7B1FA2', 'text_color' => '#FFFFFF', 'font_size' => 52])]);
$ids['clock'] = $c(['title' => 'Clock', 'type' => 'clock', 'duration' => 0, 'settings' => '{"style":"analog","bg_color":"#0B1020","text_color":"#FFFFFF"}']);
[$data, $errors] = ContentManager::validate(['title' => 'Lobby split', 'duration' => 8, 'layout' => ['bg_color' => '#000000', 'audio' => 'auto', 'zones' => [
    ['x' => 0, 'y' => 0, 'w' => 70, 'h' => 100, 'source' => 'c:' . $ids['image'], 'scale' => 'zoom'],
    ['x' => 70, 'y' => 0, 'w' => 30, 'h' => 50, 'source' => 'c:' . $ids['clock']],
    ['x' => 70, 'y' => 50, 'w' => 30, 'h' => 50, 'source' => 'c:' . $ids['ann']],
]]], 'layout', false);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
$ids['layout'] = $c(['title' => 'Lobby split', 'type' => 'layout', 'duration' => 8, 'settings' => json_out((object) $data['settings'])]);
$pl = DB::insert('content_playlists', ['name' => 'Lobby loop', 'transition' => 'fade', 'created_at' => now()]);
foreach (['image', 'app', 'ann', 'layout'] as $i => $k) {
    DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $ids[$k], 'sort_order' => $i, 'duration' => null]);
}
DB::update('rooms', ['playlist_id' => $pl], 'id = :id', ['id' => $room]);
DB::insert('tickers', ['name' => 'Welcome', 'message' => 'મંગળા આરતી સવારે 6:30   ✦   Mangla Aarti 6:30 AM   ✦   Free Wi-Fi: DwarkaPalace', 'target_type' => 'all',
    'text_color' => '#FFD700', 'bg_color' => '#1A237E', 'speed' => 5, 'font_size' => 28, 'height' => 56, 'position' => 'bottom', 'reserve_space' => 1, 'is_active' => 1]);
Settings::bumpContentVersion();
Cache::clear();

$url = TestEnv::startServer($sandbox, $appSrc . '/tests/router.php', 4);
TestEnv::writeConfig($sandbox, $url);
echo json_encode(['url' => $url, 'root' => $sandbox, 'key' => 'TESTKEY123456789', 'room' => '101', 'ids' => $ids], JSON_UNESCAPED_SLASHES) . "\n";
// Serve until the caller closes stdin.
while (!feof(STDIN)) {
    fgets(STDIN);
}
