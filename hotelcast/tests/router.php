<?php
/**
 * Router for PHP's built-in web server (used by tests / local development), emulating
 * the Apache .htaccess rules: /api/* → api/index.php and blocking internal folders.
 *   php -S 127.0.0.1:8080 -t hotelcast hotelcast/tests/router.php
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('#^/(core|backups|logs|storage|migrations|lang|tests)(/|$)#', $path) || preg_match('#/\.#', $path)
    || preg_match('#^/(config\.php|installed\.lock|cron\.php)$#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (preg_match('#^/api(/|$)#', $path) && !is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/api/index.php';
    return true;
}
if (is_dir($_SERVER['DOCUMENT_ROOT'] . $path) && is_file(rtrim($_SERVER['DOCUMENT_ROOT'] . $path, '/') . '/index.php')) {
    if (!str_ends_with($path, '/')) {
        header('Location: ' . $path . '/');
        return true;
    }
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    chdir($_SERVER['DOCUMENT_ROOT'] . rtrim($path, '/'));
    require $_SERVER['DOCUMENT_ROOT'] . rtrim($path, '/') . '/index.php';
    return true;
}
return false;
