<?php
declare(strict_types=1);
/**
 * Live data of a display app (2.3), polled by assets/display/app.js every refresh_sec seconds.
 *   display/data.php?c=<content id>&s=<signature>
 * → {"ok":true,"rev":"…","server_now":<ms>,"refresh_sec":N,"data":{…}|null}
 * Errors: 404 NOT_FOUND (bad signature / deleted item), 403 SUSPENDED (hotel paused).
 */
require __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$item = DisplayApps::itemFromRequest($_GET);
if (!$item) {
    http_response_code(404);
    echo json_out(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]);
    exit;
}
if (!Tenant::isActive()) {
    http_response_code(403);
    echo json_out(['ok' => false, 'error' => ['code' => 'SUSPENDED', 'message' => 'Service paused']]);
    exit;
}
if (!Features::itemAllowed($item)) { // 2.5 plans: app family outside the customer's plan
    http_response_code(403);
    echo json_out(['ok' => false, 'error' => ['code' => 'FEATURE_DISABLED', 'message' => 'Not available']]);
    exit;
}
echo json_out(DisplayApps::payload($item));
