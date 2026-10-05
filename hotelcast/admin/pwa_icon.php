<?php
/**
 * PWA / notification icon (PNG) from the white-label branding: pwa_icon.php?s=192[&m=1 maskable].
 * Public (needed before login by the manifest / notifications); contains no hotel data except the
 * branding colour and logo, which are public anyway.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';

$size = PwaIcon::normaliseSize(max(1, (int) ($_GET['s'] ?? 192)));
$maskable = !empty($_GET['m']);
// Branding of the logged-in user's hotel when a session exists; platform branding otherwise.
$hotelId = null;
if (!empty($_COOKIE['HCSESSID'])) {
    Auth::user();
    session_write_close();
    $hotelId = Tenant::current();
}
$b = Branding::get($hotelId ?? 0);
$logo = $b['logo_path'] !== '' && !str_contains($b['logo_path'], '..') ? HC_ROOT . '/uploads/' . ltrim($b['logo_path'], '/') : null;
$png = PwaIcon::png($size, $maskable, $b['color'], $logo);
$etag = '"' . md5($png) . '"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($png));
echo $png;
