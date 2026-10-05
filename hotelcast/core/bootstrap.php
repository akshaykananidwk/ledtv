<?php
/**
 * HotelCast bootstrap — included by every entry point (admin, api, cron).
 */
declare(strict_types=1);

if (defined('HC_ROOT')) {
    return;
}

define('HC_ROOT', dirname(__DIR__));
define('HC_CORE', __DIR__);
define('HC_START', microtime(true));

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

spl_autoload_register(static function (string $class): void {
    $file = HC_CORE . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require HC_CORE . '/helpers.php';

Env::load(HC_ROOT . '/.env');
Config::load(HC_ROOT . '/config.php');

date_default_timezone_set((string) Config::get('timezone', 'Asia/Kolkata'));

$debug = (bool) Config::get('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', HC_ROOT . '/logs/php_error.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    Logger::error('Uncaught ' . get_class($e) . ': ' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (defined('HC_API')) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => $debug ? $e->getMessage() : 'Internal server error',
        ]]);
    } elseif (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . PHP_EOL);
    } else {
        echo '<h1>Something went wrong</h1><p>The error was logged. Please check logs/app.log.</p>';
        if ($debug) {
            echo '<pre>' . e((string) $e) . '</pre>';
        }
    }
    exit(1);
});

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** True when the installer has completed. */
function hc_installed(): bool
{
    return is_file(HC_ROOT . '/installed.lock') && is_file(HC_ROOT . '/config.php');
}

if (!defined('HC_INSTALLER') && !hc_installed()) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "HotelCast is not installed. Open /install in a browser.\n");
        exit(1);
    }
    if (defined('HC_API')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => ['code' => 'NOT_INSTALLED', 'message' => 'HotelCast is not installed']]);
        exit;
    }
    header('Location: ' . base_url('install/'));
    exit;
}

if (hc_installed()) {
    $tz = Settings::get('timezone');
    if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
    DB::syncTimezone();
}
