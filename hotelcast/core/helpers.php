<?php
declare(strict_types=1);

/** HTML-escape for output (XSS protection). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Base URL of the installation (with trailing slash). Uses config 'base_url' when set,
 * otherwise auto-detects from the request.
 */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $configured = class_exists('Config', false) ? (string) Config::get('base_url', '') : '';
        if ($configured !== '') {
            $base = rtrim($configured, '/') . '/';
        } elseif (PHP_SAPI === 'cli') {
            $base = 'http://localhost/';
        } else {
            $scheme = is_https() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
            $root = realpath(HC_ROOT) ?: HC_ROOT;
            $sub = '';
            if ($docRoot !== '' && str_starts_with($root, $docRoot)) {
                $sub = str_replace('\\', '/', substr($root, strlen($docRoot)));
            }
            $base = $scheme . '://' . $host . rtrim($sub, '/') . '/';
        }
    }
    return $base . ltrim($path, '/');
}

/** URL to a public media file under /uploads (honours CDN base URL setting). */
function media_url(?string $relPath): ?string
{
    if ($relPath === null || $relPath === '') {
        return null;
    }
    $cdn = class_exists('Settings', false) ? trim((string) Settings::get('cdn_base_url', '')) : '';
    $rel = 'uploads/' . ltrim($relPath, '/');
    return $cdn !== '' ? rtrim($cdn, '/') . '/' . $rel : base_url($rel);
}

function asset(string $path): string
{
    $file = HC_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1';
    return base_url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function admin_url(string $page = '', array $query = []): string
{
    $url = base_url('admin/' . ltrim($page, '/'));
    return $query ? $url . '?' . http_build_query($query) : $url;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function iso_time(?string $mysqlDate = null): ?string
{
    if ($mysqlDate === null) {
        return date('c');
    }
    $ts = strtotime($mysqlDate);
    return $ts ? date('c', $ts) : null;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ((bool) Config::get('trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return substr($ip, 0, 45);
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Read JSON request body (cached). */
function request_json(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : $_POST;
    }
    return $data;
}

function json_out(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
}

function human_bytes(int|float|null $bytes): string
{
    $bytes = (float) ($bytes ?? 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return __('never');
    }
    $diff = time() - (int) strtotime($datetime);
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return $diff . 's ' . __('ago');
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ' . __('ago');
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ' . __('ago');
    }
    return floor($diff / 86400) . 'd ' . __('ago');
}

/** Translate a key (English / Gujarati). */
function __(string $key, array $replace = []): string
{
    return I18n::t($key, $replace);
}

/** Recursively delete a directory. */
function rrmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    return @rmdir($dir);
}

/** Recursively copy a directory. */
function rcopy(string $src, string $dst): void
{
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

/** Normalise a hex colour, falling back to $default. */
function clean_color(?string $color, string $default = '#000000'): string
{
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : $default;
}

function role_label(string $role): string
{
    return match ($role) {
        'platform_admin' => __('Platform Admin'),
        'reseller' => __('Reseller'),
        'super_admin' => __('Super Admin'),
        'manager' => __('Manager'),
        'reception' => __('Reception'),
        default => __('Staff'),
    };
}

/** Format an amount with the platform currency, e.g. "₹ 1,234.00". */
function money(float|int|string|null $amount, ?string $currency = null): string
{
    $currency ??= (string) Settings::platform('billing_currency', 'INR');
    $symbol = match (strtoupper($currency)) {
        'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ',
        default => strtoupper($currency) . ' ',
    };
    return $symbol . number_format((float) $amount, 2);
}

/** URL-safe slug (ASCII), e.g. "Hotel Dwarka Palace" → "hotel-dwarka-palace". */
function slugify(string $text, string $fallback = 'hotel'): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return substr($s !== '' ? $s : $fallback, 0, 60);
}
