<?php
/**
 * Support tools + analytics events (V2_SPEC §8, § TV contract), device auth:
 *   POST /api/device/screenshot  multipart "image" (JPEG ≤ 2 MB)          → {id}
 *   POST /api/device/logs        {logs: string ≤ 512 KB, state: object}   → {id}
 *   POST /api/device/crash       {stack ≤ 64 KB, app_version, happened_at} → {id}
 *   POST /api/device/event       {type, data}                             → {id}
 * Errors: 400 VALIDATION_ERROR, 413 PAYLOAD_TOO_LARGE, 429 RATE_LIMITED (+ Retry-After), 401 as
 * every device endpoint. Files are stored per hotel and device (DeviceSupport).
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    $routes = [
        // route => [max request bytes, rate limit per device per hour]
        'device/screenshot' => [DeviceSupport::MAX_SCREENSHOT + 64 * 1024, 30],
        'device/logs' => [3 * 1024 * 1024, 30],
        'device/crash' => [512 * 1024, 30],
        'device/event' => [64 * 1024, 1200],
    ];
    if (!isset($routes[$route])) {
        return false;
    }
    [$maxBytes, $perHour] = $routes[$route];
    Api::method('POST');
    $device = DeviceManager::authenticate();

    // Refuse oversized bodies before reading them (Content-Length is set by OkHttp for these requests).
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $maxBytes) {
        Api::error('PAYLOAD_TOO_LARGE', 'Request too large', 413);
    }
    $retry = RateLimiter::hit('sup:' . $route . ':' . $device['id'], $perHour, 3600);
    if ($retry > 0) {
        Api::error('RATE_LIMITED', 'Too many uploads', 429, ['Retry-After' => (string) $retry]);
    }

    try {
        switch ($route) {
            case 'device/screenshot':
                $f = $_FILES['image'] ?? null;
                if (!is_array($f) || is_array($f['error'] ?? null)) {
                    Api::error('VALIDATION_ERROR', 'multipart field "image" (JPEG) is required', 400);
                }
                if (in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $f['size'] > DeviceSupport::MAX_SCREENSHOT) {
                    Api::error('PAYLOAD_TOO_LARGE', 'Screenshot must be at most 2 MB', 413);
                }
                if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                    Api::error('VALIDATION_ERROR', 'Upload failed (error ' . (int) $f['error'] . ')', 400);
                }
                $id = DeviceSupport::saveScreenshot($device, (string) file_get_contents((string) $f['tmp_name']));
                Logger::write('device', 'info', 'Screenshot received', ['device' => $device['id'], 'hotel' => $device['hotel_id']]);
                break;
            case 'device/logs':
                $id = DeviceSupport::saveLogs($device, request_json());
                Logger::write('device', 'info', 'Logs received', ['device' => $device['id'], 'hotel' => $device['hotel_id']]);
                break;
            case 'device/crash':
                $id = DeviceSupport::saveCrash($device, request_json());
                Logger::write('device', 'warning', 'Crash report received', ['device' => $device['id'], 'hotel' => $device['hotel_id']]);
                break;
            default:
                $id = DeviceSupport::saveEvent($device, request_json());
        }
    } catch (LengthException $e) {
        Api::error('PAYLOAD_TOO_LARGE', $e->getMessage(), 413);
    } catch (InvalidArgumentException $e) {
        Api::error('VALIDATION_ERROR', $e->getMessage(), 400);
    }
    Api::ok(['id' => $id]);
};
