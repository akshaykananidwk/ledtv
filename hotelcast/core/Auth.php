<?php
declare(strict_types=1);

/**
 * Admin authentication: bcrypt passwords, DB-tracked sessions, lockout after
 * 5 failed attempts (15 minutes), role-based permissions.
 */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;
    public const IDLE_TIMEOUT = 7200;      // 2 hours
    public const ABSOLUTE_TIMEOUT = 43200; // 12 hours

    public const ROLE_LEVEL = ['staff' => 1, 'manager' => 2, 'super_admin' => 3];

    /** Minimum role per permission. */
    public const PERMISSIONS = [
        'dashboard.view' => 'staff',
        'rooms.view' => 'staff',
        'rooms.manage' => 'manager',
        'groups.manage' => 'manager',
        'content.view' => 'staff',
        'content.manage' => 'manager',
        'playlists.manage' => 'manager',
        'broadcast.send' => 'staff',
        'broadcast.emergency' => 'staff',
        'broadcast.device_commands' => 'manager',
        'schedule.manage' => 'manager',
        'apk.manage' => 'manager',
        'logs.view' => 'manager',
        'users.manage' => 'super_admin',
        'settings.manage' => 'super_admin',
        'update.manage' => 'super_admin',
    ];

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SESSION_DISABLED === session_status()) {
            return;
        }
        $path = parse_url(base_url(), PHP_URL_PATH) ?: '/';
        session_name('HCSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    /**
     * Attempt login. Returns [bool success, string|null errorMessage].
     */
    public static function attempt(string $username, string $password): array
    {
        $ip = client_ip();
        $username = trim($username);

        // IP-level throttle: max 20 failures per 15 min from one IP (any username).
        $ipFails = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND success = 0 AND created_at > :since',
            ['ip' => $ip, 'since' => date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60)]
        );
        if ($ipFails >= 20) {
            return [false, __('Too many failed attempts from your network. Try again in 15 minutes.')];
        }

        $user = DB::one('SELECT * FROM users WHERE username = :u OR email = :e LIMIT 1', ['u' => $username, 'e' => $username]);

        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            self::recordAttempt($username, $ip, false);
            return [false, __('Account locked after too many failed attempts. Try again in :m minutes.', ['m' => $mins])];
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Constant-ish time for unknown users
            if (!$user) {
                password_verify($password, '$2y$12$0000000000000000000000.000000000000000000000000000000');
            }
            self::recordAttempt($username, $ip, false);
            if ($user) {
                $fails = (int) $user['failed_attempts'] + 1;
                $lock = $fails >= self::MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60) : null;
                DB::update('users', [
                    'failed_attempts' => $lock ? 0 : $fails,
                    'locked_until' => $lock,
                ], 'id = :id', ['id' => $user['id']]);
                if ($lock) {
                    ActivityLog::add('login_locked', 'user', (int) $user['id'], 'Locked after ' . self::MAX_ATTEMPTS . ' failed attempts from ' . $ip);
                    return [false, __('Account locked after too many failed attempts. Try again in :m minutes.', ['m' => self::LOCKOUT_MINUTES])];
                }
                $left = self::MAX_ATTEMPTS - $fails;
                return [false, __('Invalid username or password.') . ' ' . __(':n attempts left.', ['n' => $left])];
            }
            return [false, __('Invalid username or password.')];
        }

        if (!(int) $user['is_active']) {
            self::recordAttempt($username, $ip, false);
            return [false, __('This account is disabled.')];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            DB::update('users', ['password_hash' => self::hash($password)], 'id = :id', ['id' => $user['id']]);
        }

        self::recordAttempt($username, $ip, true);
        self::login($user);
        return [true, null];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        DB::insert('login_attempts', [
            'username' => mb_substr($username, 0, 190),
            'ip_address' => $ip,
            'success' => $success ? 1 : 0,
            'created_at' => now(),
        ]);
    }

    public static function login(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $token = random_token(32);
        DB::insert('user_sessions', [
            'user_id' => $user['id'],
            'session_hash' => hash('sha256', $token),
            'ip_address' => client_ip(),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => now(),
            'last_activity' => now(),
            'expires_at' => date('Y-m-d H:i:s', time() + self::ABSOLUTE_TIMEOUT),
        ]);
        DB::update('users', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => client_ip(),
        ], 'id = :id', ['id' => $user['id']]);
        $_SESSION['hc_token'] = $token;
        $_SESSION['hc_uid'] = (int) $user['id'];
        $_SESSION['lang'] = $user['language'] ?: 'en';
        self::$user = $user;
        self::$resolved = true;
        ActivityLog::add('login', 'user', (int) $user['id'], 'Logged in');
    }

    public static function logout(): void
    {
        self::startSession();
        if (!empty($_SESSION['hc_token'])) {
            DB::update('user_sessions', ['revoked' => 1], 'session_hash = :h', ['h' => hash('sha256', $_SESSION['hc_token'])]);
        }
        if (self::user()) {
            ActivityLog::add('logout', 'user', (int) self::user()['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$resolved = true;
    }

    /** Current logged-in user or null. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        if (PHP_SAPI === 'cli') {
            return null;
        }
        self::startSession();
        $token = $_SESSION['hc_token'] ?? null;
        if (!$token) {
            return null;
        }
        $row = DB::one(
            'SELECT s.id AS sid, s.last_activity, s.expires_at, s.revoked, u.*
             FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.session_hash = :h LIMIT 1',
            ['h' => hash('sha256', $token)]
        );
        if (!$row || (int) $row['revoked'] || !(int) $row['is_active']
            || strtotime($row['expires_at']) < time()
            || strtotime($row['last_activity']) < time() - self::IDLE_TIMEOUT) {
            unset($_SESSION['hc_token'], $_SESSION['hc_uid']);
            return null;
        }
        if (strtotime($row['last_activity']) < time() - 60) {
            DB::update('user_sessions', ['last_activity' => now()], 'id = :id', ['id' => $row['sid']]);
        }
        unset($row['password_hash']);
        self::$user = $row;
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function role(): string
    {
        return self::user()['role'] ?? '';
    }

    public static function hasRole(string $minRole): bool
    {
        $role = self::role();
        return $role !== '' && (self::ROLE_LEVEL[$role] ?? 0) >= (self::ROLE_LEVEL[$minRole] ?? 99);
    }

    public static function can(string $permission): bool
    {
        $min = self::PERMISSIONS[$permission] ?? 'super_admin';
        return self::hasRole($min);
    }

    /** Guard for admin pages. */
    public static function require(?string $permission = null): array
    {
        $user = self::user();
        if (!$user) {
            if (self::isAjax()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_out(['ok' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Please log in again']]);
                exit;
            }
            $back = $_SERVER['REQUEST_URI'] ?? '';
            redirect(admin_url('login.php', $back ? ['next' => $back] : []));
        }
        if ($permission !== null && !self::can($permission)) {
            http_response_code(403);
            if (self::isAjax()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_out(['ok' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have permission for this action']]);
                exit;
            }
            $GLOBALS['hc_forbidden'] = true;
            require HC_ROOT . '/admin/partials/forbidden.php';
            exit;
        }
        return $user;
    }

    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** Password policy: min 8 chars, letters + digits. Returns error or null. */
    public static function passwordError(string $password): ?string
    {
        if (strlen($password) < 8) {
            return __('Password must be at least 8 characters.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return __('Password must contain letters and numbers.');
        }
        return null;
    }

    /** Revoke all sessions of a user (e.g. after password change / disable). */
    public static function revokeUserSessions(int $userId, bool $exceptCurrent = false): void
    {
        $params = ['u' => $userId];
        $sql = 'UPDATE user_sessions SET revoked = 1 WHERE user_id = :u';
        if ($exceptCurrent && !empty($_SESSION['hc_token'])) {
            $sql .= ' AND session_hash <> :h';
            $params['h'] = hash('sha256', $_SESSION['hc_token']);
        }
        DB::query($sql, $params);
    }
}
