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

    /** Roles inside one hotel, by level (higher includes lower). */
    public const ROLE_LEVEL = ['reception' => 1, 'staff' => 2, 'manager' => 3, 'super_admin' => 4];
    /** Roles that are not bound to one hotel. */
    public const PLATFORM_ROLES = ['platform_admin', 'reseller'];
    public const HOTEL_ROLES = ['super_admin', 'manager', 'staff', 'reception'];
    /** Hotel chains (#20): owner-side user of one chain (users.chain_id, hotel_id NULL), see core/Chains.php. */
    public const CHAIN_ROLES = ['chain_admin'];
    public const ALL_ROLES = ['platform_admin', 'reseller', 'super_admin', 'manager', 'staff', 'reception', 'chain_admin'];

    /**
     * Platform permissions: allowed roles (checked against the real role, no hotel needed).
     * Modules can add entries with Auth::registerPermission().
     */
    public const PLATFORM_PERMISSIONS = [
        'platform.manage' => ['platform_admin'],          // hotels, plans, invoices, licenses, platform settings
        'platform.hotels' => ['platform_admin', 'reseller'], // hotel list / create / enter
        'reseller.panel' => ['reseller'],
        'update.manage' => ['platform_admin'],
    ];

    /** Minimum hotel role per permission (platform admins / resellers inside a hotel act as super_admin). */
    public const PERMISSIONS = [
        'dashboard.view' => 'reception',
        'rooms.view' => 'reception',
        'guests.manage' => 'reception',
        'services.manage' => 'reception',
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
        'billing.view' => 'super_admin',
    ];

    private static array $extraPermissions = [];

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
        if ($user['role'] === 'reseller' && (!$user['reseller_id']
            || DB::value("SELECT status FROM resellers WHERE id = :id", ['id' => $user['reseller_id']]) !== 'active')) {
            self::recordAttempt($username, $ip, false);
            return [false, __('This reseller account is suspended.')];
        }
        if (in_array($user['role'], self::HOTEL_ROLES, true) && (!$user['hotel_id'] || !DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $user['hotel_id']]))) {
            self::recordAttempt($username, $ip, false);
            return [false, __('This account is not linked to a hotel.')];
        }
        if ($user['role'] === 'chain_admin' && (empty($user['chain_id']) || !DB::value('SELECT id FROM hotel_chains WHERE id = :id', ['id' => $user['chain_id']]))) {
            self::recordAttempt($username, $ip, false);
            return [false, __('This account is not linked to a hotel chain.')];
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
        unset($_SESSION['hc_hotel']);
        self::$user = $user;
        self::$resolved = true;
        self::resolveTenant();
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
        self::resolveTenant();
        return self::$user;
    }

    /**
     * Select the hotel context for the logged-in user:
     *  - hotel roles: always their own hotel (users.hotel_id), never from the session;
     *  - platform_admin: the hotel "entered" (session) or their own hotel_id (may be none);
     *  - reseller: an entered hotel only if it belongs to the reseller;
     *  - chain_admin: an entered hotel only if it belongs to their chain (Chains::userCanEnter);
     *    a hotel super_admin with chain access may also enter the other hotels of the chain.
     */
    private static function resolveTenant(): void
    {
        $u = self::$user;
        if (!$u) {
            return;
        }
        $hid = null;
        $entered = isset($_SESSION['hc_hotel']) ? (int) $_SESSION['hc_hotel'] : 0;
        if (in_array($u['role'], self::HOTEL_ROLES, true)) {
            $hid = $u['hotel_id'] ? (int) $u['hotel_id'] : null;
            if ($entered && $entered !== $hid && !empty($u['chain_id']) && Chains::userCanEnter($u, $entered)) {
                $hid = $entered;
            }
        } elseif ($u['role'] === 'chain_admin') {
            if ($entered && Chains::userCanEnter($u, $entered)) {
                $hid = $entered;
            }
        } elseif ($u['role'] === 'platform_admin') {
            if ($entered && DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $entered])) {
                $hid = $entered;
            } else {
                $hid = $u['hotel_id'] ? (int) $u['hotel_id'] : null;
            }
        } elseif ($u['role'] === 'reseller') {
            if ($entered && $u['reseller_id'] && (int) DB::value('SELECT reseller_id FROM hotels WHERE id = :id', ['id' => $entered]) === (int) $u['reseller_id']) {
                $hid = $entered;
            }
        }
        if ($entered && $hid !== $entered) {
            unset($_SESSION['hc_hotel']);
        }
        Tenant::set($hid);
    }

    /** Platform admin / reseller switches into a hotel (session). Returns false if not allowed. */
    public static function enterHotel(int $hotelId): bool
    {
        $u = self::user();
        if (!$u || !self::canAccessHotel($hotelId)) {
            return false;
        }
        $_SESSION['hc_hotel'] = $hotelId;
        Tenant::set($hotelId);
        // Logged in the entered hotel: its staff can see when the platform / reseller worked in it.
        ActivityLog::add('hotel_enter', 'hotel', $hotelId, 'Entered hotel #' . $hotelId, $hotelId);
        return true;
    }

    public static function leaveHotel(): void
    {
        self::startSession();
        unset($_SESSION['hc_hotel'], $_SESSION['hc_back']);
        $u = self::user();
        $own = $u && $u['hotel_id'] && ($u['role'] === 'platform_admin' || in_array($u['role'], self::HOTEL_ROLES, true));
        Tenant::set($own ? (int) $u['hotel_id'] : null);
    }

    /** True when a platform admin / reseller / chain user is currently inside a hotel they "entered". */
    public static function inEnteredHotel(): bool
    {
        if (empty($_SESSION['hc_hotel'])) {
            return false;
        }
        return self::isPlatformUser() || (self::user() && Chains::isChainUser()
            && (int) $_SESSION['hc_hotel'] !== (int) (self::user()['hotel_id'] ?? 0));
    }

    /** Page with the "leave hotel" action for the entered-hotel banner (reseller / chain / platform). */
    public static function backPage(): string
    {
        $role = self::role();
        if ($role === 'reseller') {
            return ($_SESSION['hc_back'] ?? '') === 'chain.php' ? 'chain.php' : 'reseller.php';
        }
        if ($role === 'platform_admin') {
            return ($_SESSION['hc_back'] ?? '') === 'chain.php' ? 'chain.php' : 'platform_hotels.php';
        }
        return 'chain.php';
    }

    public static function isPlatformUser(): bool
    {
        return in_array(self::role(), self::PLATFORM_ROLES, true);
    }

    /** May the current user manage / enter this hotel? */
    public static function canAccessHotel(int $hotelId): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'platform_admin') {
            return (bool) DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $hotelId]);
        }
        if ($u['role'] === 'reseller') {
            return $u['reseller_id'] && (int) DB::value('SELECT reseller_id FROM hotels WHERE id = :id', ['id' => $hotelId]) === (int) $u['reseller_id'];
        }
        if ($u['role'] === 'chain_admin') {
            return Chains::userCanEnter($u, $hotelId);
        }
        return (int) $u['hotel_id'] === $hotelId || (!empty($u['chain_id']) && Chains::userCanEnter($u, $hotelId));
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function role(): string
    {
        return self::user()['role'] ?? '';
    }

    /**
     * Role used for hotel permissions: platform admins / resellers act as super_admin inside a hotel
     * they may access; without a hotel context they have no hotel role.
     */
    public static function hotelRole(): string
    {
        $role = self::role();
        if (in_array($role, self::PLATFORM_ROLES, true) || in_array($role, self::CHAIN_ROLES, true)) {
            // Chain admins act as super_admin inside a hotel of their chain (only such a hotel can be entered).
            return Tenant::has() ? 'super_admin' : '';
        }
        return Tenant::has() ? $role : '';
    }

    public static function hasRole(string $minRole): bool
    {
        $role = self::hotelRole();
        return $role !== '' && (self::ROLE_LEVEL[$role] ?? 0) >= (self::ROLE_LEVEL[$minRole] ?? 99);
    }

    /** Module hook: add a permission ('x.manage' => min hotel role, or list of platform roles). */
    public static function registerPermission(string $permission, string|array $rule): void
    {
        self::$extraPermissions[$permission] = $rule;
    }

    public static function isPlatformPermission(string $permission): bool
    {
        return isset(self::PLATFORM_PERMISSIONS[$permission]) || is_array(self::$extraPermissions[$permission] ?? null);
    }

    /**
     * Would a user with $role have $permission inside a hotel (no session needed)? Used to pick the
     * recipients of staff alerts. Platform roles act as super_admin inside a hotel.
     */
    public static function roleCan(string $role, string $permission): bool
    {
        $rule = self::PLATFORM_PERMISSIONS[$permission] ?? self::$extraPermissions[$permission] ?? self::PERMISSIONS[$permission] ?? 'super_admin';
        if (is_array($rule)) {
            return in_array($role, $rule, true);
        }
        $level = in_array($role, self::PLATFORM_ROLES, true) || in_array($role, self::CHAIN_ROLES, true) ? self::ROLE_LEVEL['super_admin'] : (self::ROLE_LEVEL[$role] ?? 0);
        return $level > 0 && $level >= (self::ROLE_LEVEL[$rule] ?? 99);
    }

    public static function can(string $permission): bool
    {
        $rule = self::PLATFORM_PERMISSIONS[$permission] ?? self::$extraPermissions[$permission] ?? self::PERMISSIONS[$permission] ?? 'super_admin';
        if (is_array($rule)) {
            return in_array(self::role(), $rule, true);
        }
        return self::hasRole($rule);
    }

    /** Admin pages / actions still allowed while the hotel is suspended (read-only mode). */
    public const SUSPENDED_ALLOWED_SCRIPTS = ['billing.php', 'invoice.php', 'logout.php', 'profile.php', 'login.php'];

    /** Read-only mode: hotel users of a suspended / expired hotel may not change anything. */
    public static function readOnly(): bool
    {
        return Tenant::has() && !self::isPlatformUser() && !Tenant::isActive();
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
        // Hotel pages need a hotel context; platform users without one go to their home page.
        if ($permission !== null && !self::isPlatformPermission($permission) && !Tenant::has() && (self::isPlatformUser() || self::role() === 'chain_admin')) {
            if (self::isAjax()) {
                http_response_code(409);
                header('Content-Type: application/json; charset=utf-8');
                echo json_out(['ok' => false, 'error' => ['code' => 'NO_HOTEL', 'message' => 'Select a hotel first']]);
                exit;
            }
            redirect(admin_url(self::homePage()));
        }
        // Suspended / expired hotel: read-only except billing.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && self::readOnly()) {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $action = (string) ($_GET['action'] ?? '');
            if (!in_array($script, self::SUSPENDED_ALLOWED_SCRIPTS, true) && !($script === 'ajax.php' && $action === 'set_language')) {
                http_response_code(403);
                if (self::isAjax()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_out(['ok' => false, 'error' => ['code' => 'HOTEL_SUSPENDED', 'message' => __('This hotel account is suspended. Changes are disabled until it is reactivated.')]]);
                    exit;
                }
                if (function_exists('flash')) {
                    flash('danger', __('This hotel account is suspended. Changes are disabled until it is reactivated.'));
                }
                redirect(admin_url(in_array($script, ['', 'index.php'], true) ? 'billing.php' : $script));
            }
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

    /** Landing page for the current user. */
    public static function homePage(): string
    {
        $role = self::role();
        if (!Tenant::has()) {
            return match ($role) {
                'platform_admin' => 'platform_hotels.php',
                'reseller' => 'reseller.php',
                'chain_admin' => 'chain.php',
                default => 'profile.php',
            };
        }
        return $role === 'reception' && is_file(HC_ROOT . '/admin/guests.php') ? 'guests.php' : 'index.php';
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
