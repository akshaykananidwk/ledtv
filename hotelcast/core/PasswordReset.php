<?php
declare(strict_types=1);

/**
 * Forgot password + invite links (2.8, docs/modules/email.md).
 *
 *  - request(): "forgot password" by email OR username. The page always shows the same neutral
 *    message (no user enumeration). Rate limits: 5 requests / 15 min per IP, 3 e-mails / hour per
 *    account. Inactive users, archived / demo customers, suspended resellers get nothing.
 *  - Tokens: 32 random bytes (hex in the link), only the SHA-256 is stored (password_resets), single
 *    use, 60 min for a reset, 72 h for an invite (new user created by an admin). A new token
 *    invalidates the older open tokens of that user.
 *  - complete(): password policy (Auth::passwordError), new hash, failed attempts / lock cleared, ALL
 *    sessions revoked, token used, activity logged, "your password was changed" e-mail.
 * Tokens are never logged.
 */
final class PasswordReset
{
    public const TTL_RESET = 3600;
    public const TTL_INVITE = 259200;
    public const IP_LIMIT = 5;
    public const IP_WINDOW = 900;
    public const USER_LIMIT = 3;
    public const USER_WINDOW = 3600;
    public const TYPES = ['reset', 'invite'];

    /**
     * Handle a forgot-password request. Returns 'ok' (always shown as the neutral message) or
     * 'rate_limited' (too many requests from this IP — says nothing about any account).
     */
    public static function request(string $login, string $ip): string
    {
        if (RateLimiter::hit('pwreset_ip:' . $ip, self::IP_LIMIT, self::IP_WINDOW) > 0) {
            Logger::write('auth', 'warning', 'Password reset rate limit (IP)', ['ip' => $ip]);
            return 'rate_limited';
        }
        $user = self::findUser($login);
        if (!$user || !self::canReset($user)) {
            Logger::write('auth', 'info', 'Password reset requested for an unknown / blocked account', ['ip' => $ip]);
            return 'ok';
        }
        if (RateLimiter::hit('pwreset_user:' . (int) $user['id'], self::USER_LIMIT, self::USER_WINDOW) > 0) {
            Logger::write('auth', 'warning', 'Password reset rate limit (account)', ['user' => (int) $user['id'], 'ip' => $ip]);
            return 'ok';
        }
        $token = self::issue((int) $user['id'], 'reset', $ip);
        $sent = self::sendResetEmail($user, $token);
        ActivityLog::add('password_reset_request', 'user', (int) $user['id'], $user['username'] . ': reset link ' . ($sent ? 'emailed' : 'NOT sent (' . Mailer::$lastError . ')') . ' (IP ' . $ip . ')', self::logHotel($user));
        Logger::write('auth', $sent ? 'info' : 'error', 'Password reset link ' . ($sent ? 'sent' : 'not sent: ' . Mailer::$lastError), ['user' => (int) $user['id'], 'ip' => $ip]);
        return 'ok';
    }

    /** User by email (contains "@") or username; trimmed, case-insensitive (column collation). */
    public static function findUser(string $login): ?array
    {
        $login = trim($login);
        if ($login === '' || mb_strlen($login) > 190) {
            return null;
        }
        return str_contains($login, '@')
            ? DB::one('SELECT * FROM users WHERE email = :e LIMIT 1', ['e' => $login])
            : DB::one('SELECT * FROM users WHERE username = :u LIMIT 1', ['u' => $login]);
    }

    /** May this account receive a reset / invite e-mail? */
    public static function canReset(array $user): bool
    {
        if (!(int) $user['is_active'] || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $role = (string) $user['role'];
        if ($role === 'reseller') {
            return !empty($user['reseller_id']) && DB::value('SELECT status FROM resellers WHERE id = :id', ['id' => $user['reseller_id']]) === 'active';
        }
        if ($role === 'chain_admin') {
            return !empty($user['chain_id']) && (bool) DB::value('SELECT id FROM hotel_chains WHERE id = :id', ['id' => $user['chain_id']]);
        }
        if (in_array($role, Auth::HOTEL_ROLES, true)) {
            if (empty($user['hotel_id'])) {
                return false;
            }
            $h = DB::one('SELECT * FROM hotels WHERE id = :id', ['id' => $user['hotel_id']]);
            return $h !== null && empty($h['archived_at']) && empty($h['demo_kind']);
        }
        return $role === 'platform_admin';
    }

    /** New token for a user (older open tokens are invalidated). Returns the raw token (hex). */
    public static function issue(int $userId, string $type, string $ip = ''): string
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'reset';
        $token = bin2hex(random_bytes(32));
        DB::query('UPDATE password_resets SET expires_at = :n WHERE user_id = :u AND used_at IS NULL AND expires_at > :n2', ['n' => now(), 'n2' => now(), 'u' => $userId]);
        DB::query('DELETE FROM password_resets WHERE created_at < :t', ['t' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        DB::insert('password_resets', [
            'user_id' => $userId, 'type' => $type, 'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + ($type === 'invite' ? self::TTL_INVITE : self::TTL_RESET)),
            'ip' => $ip !== '' ? substr($ip, 0, 45) : null,
        ]);
        return $token;
    }

    /** Open token row (+ the user as 'user') or null when unknown / used / expired / account blocked. */
    public static function check(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::one('SELECT * FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > :n', ['h' => hash('sha256', $token), 'n' => now()]);
        if (!$row) {
            return null;
        }
        $user = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $row['user_id']]);
        if (!$user || !self::canReset($user)) {
            return null;
        }
        $row['user'] = $user;
        return $row;
    }

    /**
     * Set the new password with a token. Returns [ok, error|null, user|null].
     */
    public static function complete(string $token, string $password, string $confirm): array
    {
        $row = self::check($token);
        if (!$row) {
            return [false, __('This link is not valid any more. It may have expired or been used already. Please request a new one.'), null];
        }
        $user = $row['user'];
        if ($err = Auth::passwordError($password)) {
            return [false, $err, null];
        }
        if (strlen($password) > 200) {
            return [false, __('Password is too long.'), null];
        }
        if ($password !== $confirm) {
            return [false, __('The two passwords do not match.'), null];
        }
        if (strcasecmp($password, (string) $user['email']) === 0 || strcasecmp($password, (string) $user['username']) === 0) {
            return [false, __('Do not use your email address or username as password.'), null];
        }
        // Single use, also under concurrent submits.
        $used = DB::query('UPDATE password_resets SET used_at = :n WHERE id = :id AND used_at IS NULL', ['n' => now(), 'id' => $row['id']])->rowCount();
        if ($used !== 1) {
            return [false, __('This link is not valid any more. It may have expired or been used already. Please request a new one.'), null];
        }
        DB::update('users', ['password_hash' => Auth::hash($password), 'failed_attempts' => 0, 'locked_until' => null], 'id = :id', ['id' => $user['id']]);
        DB::query('UPDATE password_resets SET expires_at = :n WHERE user_id = :u AND used_at IS NULL', ['n' => now(), 'u' => $user['id']]);
        Auth::revokeUserSessions((int) $user['id']);
        $invite = $row['type'] === 'invite';
        ActivityLog::add($invite ? 'password_set_invite' : 'password_reset', 'user', (int) $user['id'],
            $user['username'] . ($invite ? ': password set with the invite link' : ': password reset with the email link') . ' (IP ' . client_ip() . ')', self::logHotel($user));
        Logger::write('auth', 'info', $invite ? 'Password set from invite' : 'Password reset completed', ['user' => (int) $user['id'], 'ip' => client_ip()]);
        if (!$invite) {
            self::sendChangedEmail($user);
        }
        return [true, null, $user];
    }

    /** Activity log scope: the customer of a customer user, platform level (NULL) for everybody else. */
    private static function logHotel(array $user): ?int
    {
        return in_array((string) $user['role'], Auth::HOTEL_ROLES, true) && !empty($user['hotel_id']) ? (int) $user['hotel_id'] : null;
    }

    // ------------------------------------------------------------------ invites

    /** Result of the last invite() (null = none yet). */
    public static ?bool $lastInviteSent = null;

    /** New user created by an admin: e-mail a 72 h "set your password" link. Returns true when sent. */
    public static function invite(int $userId): bool
    {
        self::$lastInviteSent = false;
        $user = DB::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user || !self::canReset($user)) {
            Mailer::$lastError = 'account inactive or without a valid email';
            return false;
        }
        $token = self::issue($userId, 'invite', PHP_SAPI === 'cli' ? '' : client_ip());
        [$brand, $lang, $login] = self::context($user);
        $t = static fn (string $k, array $r = []): string => I18n::translate($k, $lang, $r);
        [$text, $html] = MailTemplate::render([
            'brand' => $brand, 'lang' => $lang, 'title' => $t('Your account is ready'),
            'paragraphs' => [
                $t('Hello :name,', ['name' => $user['full_name'] ?: $user['username']]),
                $t('An account has been created for you on :product.', ['product' => $brand['product']]),
                $t('Username') . ': ' . $user['username'] . "\n" . $t('Email') . ': ' . $user['email'],
                $t('Click the button to choose your password. After that you can log in with your email address or username.'),
            ],
            'button' => ['label' => $t('Set your password'), 'url' => self::url($token, $user)],
            'after' => [$t('The link is valid for 72 hours. Log in later at: :url', ['url' => $login])],
            'small' => [$t('If you did not expect this email, you can ignore it.')],
        ]);
        $ok = Mailer::send((string) $user['email'], $brand['product'] . ': ' . $t('Your account is ready'), $text, $html, ['from_name' => $brand['product']]);
        Logger::write('auth', $ok ? 'info' : 'error', 'Invite link ' . ($ok ? 'sent' : 'not sent: ' . Mailer::$lastError), ['user' => $userId]);
        self::$lastInviteSent = $ok;
        return $ok;
    }

    /** Random password nobody knows (the invited user sets their own). */
    public static function randomPassword(): string
    {
        return bin2hex(random_bytes(18)) . 'Aa1';
    }

    // ------------------------------------------------------------------ e-mails

    /** [brand, language, login URL] for a user (customer users get the customer's white-label brand). */
    public static function context(array $user): array
    {
        $hid = in_array((string) $user['role'], Auth::HOTEL_ROLES, true) && !empty($user['hotel_id']) ? (int) $user['hotel_id'] : 0;
        $brand = Branding::get($hid);
        $lang = isset(I18n::LANGUAGES[(string) $user['language']]) ? (string) $user['language'] : 'en';
        $slug = $hid ? (string) DB::value('SELECT slug FROM hotels WHERE id = :id', ['id' => $hid]) : '';
        $login = admin_url('login.php', $slug !== '' ? ['b' => $slug] : []);
        return [$brand, $lang, $login];
    }

    /** Absolute link to the reset page. */
    public static function url(string $token, ?array $user = null): string
    {
        $q = ['token' => $token];
        if ($user && in_array((string) $user['role'], Auth::HOTEL_ROLES, true) && !empty($user['hotel_id'])) {
            $slug = (string) DB::value('SELECT slug FROM hotels WHERE id = :id', ['id' => $user['hotel_id']]);
            if ($slug !== '') {
                $q['b'] = $slug;
            }
        }
        return admin_url('reset_password.php', $q);
    }

    public static function sendResetEmail(array $user, string $token): bool
    {
        [$brand, $lang] = self::context($user);
        $t = static fn (string $k, array $r = []): string => I18n::translate($k, $lang, $r);
        [$text, $html] = MailTemplate::render([
            'brand' => $brand, 'lang' => $lang, 'title' => $t('Reset your password'),
            'paragraphs' => [
                $t('Hello :name,', ['name' => $user['full_name'] ?: $user['username']]),
                $t('We received a request to reset the password of your :product account (username: :u).', ['product' => $brand['product'], 'u' => $user['username']]),
            ],
            'button' => ['label' => $t('Choose a new password'), 'url' => self::url($token, $user)],
            'after' => [$t('This link is valid for 60 minutes and can be used only once.')],
            'small' => [$t('If you did not request this, you can ignore this email. Your password stays the same.')],
        ]);
        return Mailer::send((string) $user['email'], $brand['product'] . ': ' . $t('Reset your password'), $text, $html, ['from_name' => $brand['product']]);
    }

    public static function sendChangedEmail(array $user): bool
    {
        [$brand, $lang, $login] = self::context($user);
        $t = static fn (string $k, array $r = []): string => I18n::translate($k, $lang, $r);
        [$text, $html] = MailTemplate::render([
            'brand' => $brand, 'lang' => $lang, 'title' => $t('Your password was changed'),
            'paragraphs' => [
                $t('Hello :name,', ['name' => $user['full_name'] ?: $user['username']]),
                $t('The password of your :product account (:u) was changed on :date (IP :ip). Other devices were logged out.', [
                    'product' => $brand['product'], 'u' => $user['username'], 'date' => date('d M Y H:i'), 'ip' => PHP_SAPI === 'cli' ? '-' : client_ip(),
                ]),
            ],
            'button' => ['label' => $t('Log in'), 'url' => $login],
            'small' => [$t('If you did not do this, reset your password immediately and contact support.')],
        ]);
        return Mailer::send((string) $user['email'], $brand['product'] . ': ' . $t('Your password was changed'), $text, $html, ['from_name' => $brand['product']]);
    }
}
