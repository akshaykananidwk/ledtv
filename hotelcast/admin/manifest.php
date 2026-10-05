<?php
/**
 * Web app manifest of the admin panel (PWA, #14). Name, colour and icons come from the white-label
 * branding of the logged-in user's hotel (the page links it with crossorigin="use-credentials"),
 * or the platform branding before login.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';

$user = !empty($_COOKIE['HCSESSID']) ? Auth::user() : null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$b = Branding::get(Tenant::current() ?? 0);
$product = $b['product'];
$hotel = Tenant::has() ? trim((string) Settings::get('hotel_name', '')) : '';
$name = $hotel !== '' && $hotel !== $product ? $product . ' · ' . $hotel : $product;
$short = mb_strlen($product) <= 12 ? $product : mb_substr($product, 0, 12);
$v = substr(md5($b['color'] . '|' . $b['logo_path']), 0, 8);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex');
echo json_out([
    'id' => './',
    'name' => $name,
    'short_name' => $short,
    'description' => __(':product TV management', ['product' => $product]),
    'lang' => I18n::lang(),
    'dir' => 'ltr',
    'start_url' => './index.php?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#FFFFFF',
    'theme_color' => $b['color'],
    'categories' => ['business', 'productivity'],
    'icons' => [
        ['src' => 'pwa_icon.php?s=192&v=' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'pwa_icon.php?s=512&v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'pwa_icon.php?s=512&m=1&v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => __('Rooms & TVs'), 'url' => './rooms.php', 'icons' => [['src' => 'pwa_icon.php?s=96&v=' . $v, 'sizes' => '96x96', 'type' => 'image/png']]],
        ['name' => __('Notifications'), 'url' => './push.php', 'icons' => [['src' => 'pwa_icon.php?s=96&v=' . $v, 'sizes' => '96x96', 'type' => 'image/png']]],
    ],
]);
