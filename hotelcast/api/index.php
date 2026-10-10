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

        $content = ContentResolver::forDevice($room, $device);
        $commands = DeviceManager::pendingCommands((int) $device['id']);
        $deadline = time() + $wait;
        while ($wait > 0 && !$commands && $content['hash'] === $clientHash && time() < $deadline && !connection_aborted()) {
            sleep(1);
            Settings::flush();
            $room = DeviceManager::room($device);
            $content = ContentResolver::forDevice($room, $device);
            $commands = DeviceManager::pendingCommands((int) $device['id']);
        }
        $changed = $content['hash'] !== $clientHash;
        $data = [
            'server_time' => date('c'),
            // 2.4 synchronized playback: ms clock for the TV's NTP-like offset estimate (core/SyncPlayback.php).
            'server_time_ms' => SyncPlayback::nowMs(),
            'poll_interval' => DeviceManager::pollInterval(),
            'content_hash' => $content['hash'],
            'content_changed' => $changed,
            'commands' => $commands,
        ];
        // 2.7: a running TV learns about a new (required) app version here and caches it; it keeps playing
        // and updates on its next start (docs/modules/apk_manager.md). Not sent to web players.
        if (!DeviceManager::isWeb($device)) {
            $data['app_update'] = AppReleases::forDevice($device);
        }
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

    // 2.7: latest TV app for this device (docs/modules/apk_manager.md). The app asks on every start and blocks
    // until it is updated when installed < required_version_code. update = null: nothing to install (web players).
    case $route === 'device/app-version':
        Api::method('GET');
        $device = DeviceManager::authenticate();
        Api::ok(['installed_version_code' => $device['app_version_code'] !== null ? (int) $device['app_version_code'] : null,
            'update' => AppReleases::forDevice($device)]);

    case ($parts[0] ?? '') === 'device' && ($parts[1] ?? '') === 'apk' && isset($parts[2]):
        Api::method('GET');
        $device = DeviceManager::authenticate();
        // Own customer's release, or a platform release rolled out to this customer (AppReleases precedence).
        $apk = DeviceManager::isWeb($device) ? null : AppReleases::findForDevice((int) $parts[2], $device);
        $file = $apk ? HC_ROOT . '/storage/' . $apk['file_path'] : null;
        if (!$apk || str_contains($apk['file_path'], '..') || !is_file($file)) {
            Api::error('NOT_FOUND', 'APK not found', 404);
        }
        $size = (int) filesize($file);
        // Resume support: "Range: bytes=N-" (the TV continues an interrupted download).
        $from = 0;
        if (preg_match('/^bytes=(\d+)-$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
            $from = (int) $m[1];
            if ($from >= $size) {
                header('Content-Range: bytes */' . $size);
                Api::error('RANGE_NOT_SATISFIABLE', 'Range not satisfiable', 416);
            }
        }
        if ($from === 0) {
            AppReleases::countDownload((int) $apk['id']);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.android.package-archive');
        header('Accept-Ranges: bytes');
        header('Content-Disposition: attachment; filename="KrishnaCloud-TV-' . preg_replace('/[^A-Za-z0-9._-]/', '', $apk['version_name']) . '.apk"');
        header('X-Content-SHA256: ' . $apk['sha256']);
        if ($from > 0) {
            http_response_code(206);
            header('Content-Range: bytes ' . $from . '-' . ($size - 1) . '/' . $size);
        }
        header('Content-Length: ' . ($size - $from));
        $fh = fopen($file, 'rb');
        if ($fh) {
            fseek($fh, $from);
            fpassthru($fh);
            fclose($fh);
        }
        exit;

    case ($parts[0] ?? '') === 'content' && isset($parts[1]):
        Api::method('GET');
        $device = DeviceManager::authenticate();
        if ((int) $parts[1] !== (int) $device['room_id']) {
            Api::error('FORBIDDEN', 'A device may only read its own room', 403);
        }
        Api::ok(ContentResolver::forDevice(DeviceManager::room($device), $device));
}

// ---------------------------------------------------------------- module routes
// Every api/routes/*.php returns callable(string $route, array $parts, string $method): bool.
// A route handler responds itself (Api::ok / Api::error exit) or returns false when the route is
// not its own. Files run in name order; built-in routes above always win.
// 2.5 plans (core/Features.php): a module route of a feature outside the customer's plan answers
// 403 FEATURE_DISABLED as soon as the route selects its customer. The device API above is never gated.
Features::guardApiRoute($route);
$routeFiles = glob(__DIR__ . '/routes/*.php') ?: [];
sort($routeFiles);
foreach ($routeFiles as $routeFile) {
    $handler = require $routeFile;
    if (is_callable($handler) && $handler($route, $parts, $method) === true) {
        exit;
    }
}

Api::error('NOT_FOUND', 'Unknown endpoint: ' . $route, 404);
