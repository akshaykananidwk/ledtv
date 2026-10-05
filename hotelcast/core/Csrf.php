<?php
declare(strict_types=1);

/** CSRF protection (synchroniser token stored in session). */
final class Csrf
{
    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(32);
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(?string $token): bool
    {
        Auth::startSession();
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    /** Verify the token for any state-changing request; aborts with 419 on failure. */
    public static function check(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            return;
        }
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::valid($token)) {
            http_response_code(419);
            if (Auth::isAjax()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_out(['ok' => false, 'error' => ['code' => 'CSRF', 'message' => 'Security token expired. Reload the page and try again.']]);
            } else {
                echo '<h1>Security token expired</h1><p>Please go back, reload the page and try again.</p>';
            }
            exit;
        }
    }
}
