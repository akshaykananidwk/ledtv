<?php
/**
 * HotelCast REST API front controller.
 * Routes: /api/<route> (via .htaccess) or /api/index.php?r=<route>
 */
declare(strict_types=1);

define('HC_API', true);
require __DIR__ . '/../core/bootstrap.php';

header('Cache-Control: no-store');

$route = (string) ($_GET['r'] ?? '');
if ($route === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $apiBase = rtrim((string) parse_url(base_url('api'), PHP_URL_PATH), '/');
    if ($apiBase !== '' && str_starts_with($uri, $apiBase)) {
        $route = substr($uri, strlen($apiBase));
    } elseif (($pos = strpos($uri, '/api/')) !== false) {
        $route = substr($uri, $pos + 5);
    }
}
$route = trim(preg_replace('#^/?index\.php#', '', $route), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$parts = $route === '' ? [] : explode('/', $route);

switch (true) {
    // ---------------------------------------------------------------- public
    case $route === '' || $route === 'health':
        // Public endpoint: per-IP limit. (Device endpoints are limited per device instead,
        // because all hotel TVs usually share one public IP.)
        $retry = RateLimiter::hit('health:' . client_ip(), 60, 60);
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many requests', 429, ['Retry-After' => (string) $retry]);
        }
        $health = HealthCheck::quick();
        Api::send(['ok' => $health['status'] === 'ok', 'data' => $health], $health['status'] === 'ok' ? 200 : 503);

    // ---------------------------------------------------------------- device
    case $route === 'device/register':
        Api::method('POST');
        $retry = RateLimiter::hit('register:' . client_ip(), (int) Config::get('register_limit_per_5min', 100), 300);
        if ($retry > 0) {
            Api::error('RATE_LIMITED', 'Too many registration attempts', 429, ['Retry-After' => (string) $retry]);
        }
        Api::ok(DeviceManager::register(request_json()));

    case ($parts[0] ?? '') === 'device' && ($parts[1] ?? '') === 'command':
        Api::method('GET');
        $device = DeviceManager::authenticate();
        if (isset($parts[2]) && $parts[2] !== '' && !hash_equals($device['device_uid'], $parts[2])) {
            Api::error('FORBIDDEN', 'Token does not belong to this device', 403);
        }
        Scheduler::tick();
        $room = DeviceManager::room($device);
        $clientHash = (string) ($_GET['hash'] ?? '');
        $wait = Settings::bool('long_poll_enabled') ? max(0, min(25, (int) ($_GET['wait'] ?? 0))) : 0;

        $content = ContentResolver::forRoom($room);
        $commands = DeviceManager::pendingCommands((int) $device['id']);
        $deadline = time() + $wait;
        while ($wait > 0 && !$commands && $content['hash'] === $clientHash && time() < $deadline && !connection_aborted()) {
            sleep(1);
            Settings::flush();
            $room = DeviceManager::room($device);
            $content = ContentResolver::forRoom($room);
            $commands = DeviceManager::pendingCommands((int) $device['id']);
        }
        $changed = $content['hash'] !== $clientHash;
        $data = [
            'server_time' => date('c'),
            'poll_interval' => DeviceManager::pollInterval(),
            'content_hash' => $content['hash'],
            'content_changed' => $changed,
            'commands' => $commands,
        ];
        if ($changed) {
            $data['content'] = $content;
            DB::update('devices', ['current_hash' => $content['hash']], 'id = :id', ['id' => $device['id']]);
        }
        Api::ok($data);

    case $route === 'device/ack':
        Api::method('POST');
        $device = DeviceManager::authenticate();
        Api::ok(DeviceManager::ack($device, request_json()));

    case $route === 'device/heartbeat':
        Api::method('POST');
        $device = DeviceManager::authenticate();
        Scheduler::tick();
        Api::ok(DeviceManager::heartbeat($device, request_json()));

    case $route === 'device/played':
        Api::method('POST');
        $device = DeviceManager::authenticate();
        Api::ok(DeviceManager::played($device, request_json()));

    case ($parts[0] ?? '') === 'device' && ($parts[1] ?? '') === 'apk' && isset($parts[2]):
        Api::method('GET');
        $device = DeviceManager::authenticate();
        $apk = DB::one('SELECT * FROM apk_releases WHERE id = :id AND hotel_id = :h', ['id' => (int) $parts[2], 'h' => (int) $device['hotel_id']]);
        $file = $apk ? HC_ROOT . '/storage/' . $apk['file_path'] : null;
        if (!$apk || !is_file($file) || str_contains($apk['file_path'], '..')) {
            Api::error('NOT_FOUND', 'APK not found', 404);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="HotelCast-' . preg_replace('/[^A-Za-z0-9._-]/', '', $apk['version_name']) . '.apk"');
        header('X-Content-SHA256: ' . $apk['sha256']);
        readfile($file);
        exit;

    case ($parts[0] ?? '') === 'content' && isset($parts[1]):
        Api::method('GET');
        $device = DeviceManager::authenticate();
        if ((int) $parts[1] !== (int) $device['room_id']) {
            Api::error('FORBIDDEN', 'A device may only read its own room', 403);
        }
        Api::ok(ContentResolver::forRoom(DeviceManager::room($device)));
}

// ---------------------------------------------------------------- module routes
// Every api/routes/*.php returns callable(string $route, array $parts, string $method): bool.
// A route handler responds itself (Api::ok / Api::error exit) or returns false when the route is
// not its own. Files run in name order; built-in routes above always win.
$routeFiles = glob(__DIR__ . '/routes/*.php') ?: [];
sort($routeFiles);
foreach ($routeFiles as $routeFile) {
    $handler = require $routeFile;
    if (is_callable($handler) && $handler($route, $parts, $method) === true) {
        exit;
    }
}

Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
