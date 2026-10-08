<?php
declare(strict_types=1);
/**
 * Display app page (2.3) for TV WebViews, the admin preview iframe and the TV simulator.
 *   display/?c=<content id>&s=<signature>[&v=<rev>][&preview=1]
 * No login: the HMAC signature (DisplayApps::signature) is the key. Read-only. A wrong signature or a
 * deleted / non-app item → 404; a suspended hotel → neutral page. See docs/modules/display_apps.md.
 */
require __DIR__ . '/../core/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$item = DisplayApps::itemFromRequest($_GET);
if (!$item) {
    http_response_code(404);
    echo DisplayApps::neutralPage();
    exit;
}
if (!Tenant::isActive()) {
    http_response_code(403);
    echo DisplayApps::neutralPage(Tenant::suspendedMessage()['title']);
    exit;
}
if (!Features::itemAllowed($item)) { // 2.5 plans: app family outside the customer's plan
    http_response_code(403);
    echo DisplayApps::neutralPage(__('This app is not available.'));
    exit;
}
echo DisplayApps::renderPage($item, !empty($_GET['preview']));
