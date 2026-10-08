<?php
/**
 * 2.4 live screen view (#41), device auth: the TV uploads live frames to the existing screenshot
 * endpoint with one extra multipart field:
 *   POST /api/device/screenshot  multipart "image" (JPEG ≤ 400 KB, ≤ 960 px wide) + "live" (session token)
 *     → {stored, live: {continue, interval, stop_in}}
 * Requests without "live" are normal screenshots (api/routes/device_support.php, which runs after
 * this file). Errors: 400 VALIDATION_ERROR, 413 PAYLOAD_TOO_LARGE, 429 RATE_LIMITED (+ Retry-After).
 * Only the newest frame is kept (core/LiveView.php).
 */
declare(strict_types=1);

return static function (string $route, array $parts, string $method): bool {
    if ($route !== 'device/screenshot' || !isset($_POST['live'])) {
        return false;
    }
    Api::method('POST');
    $device = DeviceManager::authenticate();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > LiveView::MAX_FRAME_BYTES + 64 * 1024) {
        Api::error('PAYLOAD_TOO_LARGE', 'Live frame too large', 413);
    }
    $f = $_FILES['image'] ?? null;
    if (!is_array($f) || is_array($f['error'] ?? null)) {
        Api::error('VALIDATION_ERROR', 'multipart field "image" (JPEG) is required', 400);
    }
    if (in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $f['size'] > LiveView::MAX_FRAME_BYTES) {
        Api::error('PAYLOAD_TOO_LARGE', 'Live frames must be at most ' . intdiv(LiveView::MAX_FRAME_BYTES, 1024) . ' KB', 413);
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        Api::error('VALIDATION_ERROR', 'Upload failed (error ' . (int) $f['error'] . ')', 400);
    }
    try {
        $answer = LiveView::acceptFrame($device, is_string($_POST['live']) ? trim($_POST['live']) : '', (string) file_get_contents((string) $f['tmp_name']));
    } catch (LengthException $e) {
        Api::error('PAYLOAD_TOO_LARGE', $e->getMessage(), 413);
    } catch (RangeException $e) {
        Api::error('RATE_LIMITED', 'Too many live frames', 429, ['Retry-After' => $e->getMessage()]);
    } catch (InvalidArgumentException $e) {
        Api::error('VALIDATION_ERROR', $e->getMessage(), 400);
    }
    Api::ok($answer);
};
