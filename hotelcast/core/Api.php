<?php
declare(strict_types=1);

/** JSON API helpers. */
final class Api
{
    public static function ok(array $data = [], int $status = 200): never
    {
        self::send(['ok' => true, 'data' => (object) $data], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $headers = []): never
    {
        foreach ($headers as $h => $v) {
            header($h . ': ' . $v);
        }
        self::send(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function send(array $body, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_out($body);
        exit;
    }

    public static function method(string ...$allowed): void
    {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($m, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            self::error('METHOD_NOT_ALLOWED', 'Method ' . $m . ' not allowed', 405);
        }
    }

    /** Bearer token from Authorization header (works around CGI header stripping). */
    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $h = $v;
                }
            }
        }
        if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/i', trim($h), $m)) {
            return $m[1];
        }
        // Fallback for hosts that strip Authorization entirely.
        $alt = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? null;
        return is_string($alt) && preg_match('/^[A-Za-z0-9]+$/', $alt) ? $alt : null;
    }
}
