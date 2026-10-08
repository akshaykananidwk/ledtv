<?php
declare(strict_types=1);

/**
 * Online sign-up + free trial (#17), SaaS mode only.
 *
 * Flow (signup.php): form (honeypot, time-to-submit token, CSRF, rate limits, disposable e-mail
 * blocklist, optional math captcha) → Signup::register() → depending on the platform setting
 * signup_mode:
 *   - otp    : 6-digit code by e-mail (bcrypt-hashed, 15 min, 5 attempts, 3 sends) → verifyOtp()
 *   - auto   : hotel created immediately
 *   - manual : request waits in Platform → Sign-ups for approve() / reject()
 * provision() creates the hotel (trial plan, expires_at = now + trial_days, is_trial = 1, trial TV
 * limit), its super admin, registration key and demo content (rooms 101..N).
 *
 * Duplicate e-mail / mobile never produce a different answer than a new one: OTP mode sends an
 * "you already have an account" mail instead of a code (verification always fails), auto mode
 * queues the request for manual review.
 *
 * Trial lifecycle (TrialTask → processTrials()): reminders 3 days and 1 day before the end, expiry →
 * hotels.status = 'expired' (reason 'trial'), TVs show "service paused", admin read-only except
 * billing. Upgrade (billing page) → requestUpgrade() creates a manual invoice; when the platform
 * marks it paid, Billing::reactivateIfPaid() → activatePaidUpgrade() converts the hotel.
 *
 * Platform settings (hotel 0, read with Settings::platform()): see DEFAULTS.
 */
final class Signup
{
    public const DEFAULTS = [
        'signup_enabled' => '0',
        'signup_mode' => 'otp',            // otp | auto | manual
        'trial_days' => '14',
        'trial_plan_id' => '',
        'trial_max_tvs' => '5',
        'signup_terms' => "By starting a free trial you agree that:\n1. The trial is free for the trial period; no payment details are needed.\n2. After the trial the TV service pauses until you choose a paid plan.\n3. You are responsible for the content shown on your TVs.\n4. We store your contact details to provide the service and send you account messages.",
        'signup_notify_email' => '',
        'signup_captcha' => '0',
        'signup_min_seconds' => '3',
        'signup_blocked_domains' => '',
    ];
    public const MODES = ['otp', 'auto', 'manual'];
    public const OTP_TTL = 900;
    public const OTP_MAX_ATTEMPTS = 5;
    public const OTP_MAX_SENDS = 3;
    public const MAX_FORM_AGE = 7200;
    public const MAX_DEMO_ROOMS = 20;

    /** Small built-in list of throw-away mail domains (extend with the signup_blocked_domains setting). */
    public const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', 'tempmail.com',
        'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com',
        'fakeinbox.com', 'mintemail.com', 'emailondeck.com', 'mohmal.com', 'tempail.com', 'burnermail.io', 'mailnesia.com',
        'spamgourmet.com', 'tempr.email', 'discard.email', 'mytemp.email', 'moakt.com',
    ];

    public static function setting(string $key): string
    {
        return (string) Settings::platform($key, self::DEFAULTS[$key] ?? '');
    }

    /** Sign-up page available? (SaaS platform + enabled in platform settings). */
    public static function enabled(): bool
    {
        return License::mode() === 'saas' && self::setting('signup_enabled') === '1';
    }

    public static function mode(): string
    {
        $m = self::setting('signup_mode');
        return in_array($m, self::MODES, true) ? $m : 'otp';
    }

    public static function trialDays(): int
    {
        return max(1, min(90, (int) self::setting('trial_days') ?: 14));
    }

    // ------------------------------------------------------------------ anti-abuse

    /** Signed form timestamp "<ts>.<hmac>" (time-to-submit check without server state). */
    public static function formToken(?int $ts = null): string
    {
        $ts ??= time();
        return $ts . '.' . substr(hash_hmac('sha256', 'signup-form|' . $ts, self::key()), 0, 32);
    }

    /** Seconds since the form was rendered; null when the token is missing / forged. */
    public static function formAge(mixed $token): ?int
    {
        if (!is_string($token) || !preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }
        if (!hash_equals(substr(hash_hmac('sha256', 'signup-form|' . $m[1], self::key()), 0, 32), $m[2])) {
            return null;
        }
        return time() - (int) $m[1];
    }

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        return $k !== '' ? $k : hash('sha256', HC_ROOT . '|' . (string) Config::get('base_url', ''));
    }

    public static function isDisposable(string $email): bool
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return true;
        }
        $extra = array_filter(array_map(fn ($d) => strtolower(trim($d)), preg_split('/[\s,;]+/', self::setting('signup_blocked_domains')) ?: []));
        foreach (array_merge(self::DISPOSABLE_DOMAINS, $extra) as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    /** Normalised mobile: "+<digits>" or "<digits>" (10–15 digits), null when invalid. */
    public static function normalizeMobile(string $raw): ?string
    {
        $raw = trim($raw);
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15 || !preg_match('/^[+\d][\d\s().-]*$/', $raw)) {
            return null;
        }
        return ($plus ? '+' : '') . $digits;
    }

    /** Last 10 digits (compare "+91 98250 12345" with "9825012345"). */
    public static function mobileKey(string $mobile): string
    {
        return substr(preg_replace('/\D+/', '', $mobile) ?? '', -10);
    }

    /** New math captcha stored in the session; returns the question. */
    public static function captchaQuestion(): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $_SESSION['hc_signup_captcha'] = $a + $b;
        return $a . ' + ' . $b;
    }

    public static function captchaValid(mixed $answer): bool
    {
        $expected = $_SESSION['hc_signup_captcha'] ?? null;
        unset($_SESSION['hc_signup_captcha']);
        return $expected !== null && is_scalar($answer) && trim((string) $answer) !== '' && (int) $answer === (int) $expected;
    }

    /**
     * Validate the sign-up form. Returns [data, errors]. data: hotel_name, city, owner_name, mobile,
     * email, tv_estimate, language, password.
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $s = static fn (string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '') ?? ''), 0, $max);
        $hotel = $s('hotel_name', 120);
        if (mb_strlen($hotel) < 2) {
            $errors['hotel_name'] = __('Business name is required.');
        }
        $city = $s('city', 80);
        if (mb_strlen($city) < 2) {
            $errors['city'] = __('City is required.');
        }
        $owner = $s('owner_name', 120);
        if (mb_strlen($owner) < 2) {
            $errors['owner_name'] = __('Your name is required.');
        }
        $mobile = self::normalizeMobile($s('mobile', 30));
        if ($mobile === null) {
            $errors['mobile'] = __('Enter a valid mobile number (10–15 digits).');
        }
        $email = strtolower($s('email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = __('Enter a valid email address.');
        } elseif (self::isDisposable($email)) {
            $errors['email'] = __('Please use your business or personal email address (temporary email addresses are not accepted).');
        }
        $tvs = (int) ($in['tv_estimate'] ?? 0);
        if ($tvs < 1 || $tvs > 2000) {
            $errors['tv_estimate'] = __('Enter the number of screens / TVs (1–2000).');
        }
        $lang = is_string($in['language'] ?? null) && isset(I18n::LANGUAGES[$in['language']]) ? $in['language'] : 'en';
        $password = is_string($in['password'] ?? null) ? (string) $in['password'] : '';
        if ($pe = Auth::passwordError($password)) {
            $errors['password'] = $pe;
        } elseif (strlen($password) > 200) {
            $errors['password'] = __('Password is too long.');
        } elseif ($email !== '' && strcasecmp($password, $email) === 0) {
            $errors['password'] = __('Do not use your email address as password.');
        }
        if (empty($in['accept_terms'])) {
            $errors['accept_terms'] = __('Please accept the terms to continue.');
        }
        return [[
            'hotel_name' => $hotel, 'city' => $city, 'owner_name' => $owner, 'mobile' => (string) $mobile,
            'email' => $email, 'tv_estimate' => $tvs, 'language' => $lang, 'password' => $password,
        ], $errors];
    }

    /**
     * Rate limits for a valid submission: 3 per IP per hour, 3 per email and per mobile per day.
     * Returns an error message or null.
     */
    public static function checkLimits(string $email, string $mobile, string $ip): ?string
    {
        $msg = __('Too many sign-up attempts. Please try again later or contact us.');
        if (RateLimiter::hit('signup_ip:' . $ip, 3, 3600) > 0) {
            return $msg;
        }
        if (RateLimiter::hit('signup_email:' . hash('sha256', strtolower($email)), 3, 86400) > 0
            || RateLimiter::hit('signup_mobile:' . self::mobileKey($mobile), 3, 86400) > 0) {
            return $msg;
        }
        return null;
    }

    /** Is the email or mobile already used by an account / an open request? */
    public static function isDuplicate(string $email, string $mobile, int $exceptSignup = 0): bool
    {
        if (DB::value('SELECT id FROM users WHERE email = :e', ['e' => $email])) {
            return true;
        }
        $key = self::mobileKey($mobile);
        if (DB::value("SELECT id FROM signups WHERE id <> :x AND status IN ('pending','approved') AND (email = :e OR RIGHT(mobile, 10) = :m) LIMIT 1", ['x' => $exceptSignup, 'e' => $email, 'm' => $key])) {
            return true;
        }
        return (bool) DB::value(
            "SELECT id FROM hotels WHERE (contact_email = :e OR RIGHT(REPLACE(REPLACE(REPLACE(contact_phone, ' ', ''), '-', ''), '+', ''), 10) = :m) AND demo_kind IS NULL LIMIT 1",
            ['e' => $email, 'm' => $key]
        );
    }

    /**
     * Store a validated sign-up and start the configured flow.
     * Returns ['id' => signup id, 'next' => 'verify' | 'pending' | 'created', 'hotel_id', 'user_id'].
     */
    public static function register(array $data, string $ip, string $ua): array
    {
        $mode = self::mode();
        $dup = self::isDuplicate($data['email'], $data['mobile']);
        if ($mode === 'otp') {
            // A new request replaces older unverified ones of the same address.
            DB::query("UPDATE signups SET status = 'expired', password_hash = NULL, otp_hash = NULL WHERE status = 'verify' AND email = :e", ['e' => $data['email']]);
        }
        // auto mode: 'pending' until provision() below marks it approved (duplicates stay pending for review).
        $status = $mode === 'otp' ? 'verify' : 'pending';
        $id = DB::insert('signups', [
            'status' => $status, 'hotel_name' => $data['hotel_name'], 'city' => $data['city'], 'owner_name' => $data['owner_name'],
            'mobile' => $data['mobile'], 'email' => $data['email'], 'tv_estimate' => $data['tv_estimate'], 'language' => $data['language'],
            'password_hash' => $dup && $mode === 'otp' ? null : Auth::hash($data['password']),
            'duplicate' => $dup ? 1 : 0, 'ip_address' => $ip, 'user_agent' => mb_substr($ua, 0, 255),
            'terms_accepted_at' => now(), 'created_at' => now(),
        ]);
        Logger::write('signup', 'info', 'Sign-up submitted', ['id' => $id, 'mode' => $mode, 'duplicate' => $dup]);
        if ($mode === 'otp') {
            self::sendOtp($id);
            return ['id' => $id, 'next' => 'verify'];
        }
        if ($mode === 'auto' && !$dup) {
            [$hid, $uid] = self::provision($id);
            return ['id' => $id, 'next' => 'created', 'hotel_id' => $hid, 'user_id' => $uid];
        }
        self::notifyPlatform($id, 'pending');
        return ['id' => $id, 'next' => 'pending'];
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM signups WHERE id = :id', ['id' => $id]);
    }

    /**
     * (Re)send the e-mail code. Duplicates get an "account exists" mail instead (same screen for the
     * visitor). Returns false when the send limit / cool-down applies.
     */
    public static function sendOtp(int $id, bool $resend = false): bool
    {
        $s = self::find($id);
        if (!$s || $s['status'] !== 'verify') {
            return false;
        }
        if ((int) $s['otp_sends'] >= self::OTP_MAX_SENDS) {
            return false;
        }
        if ($resend && $s['otp_expires_at'] && strtotime((string) $s['otp_expires_at']) - self::OTP_TTL > time() - 60) {
            return false; // at most one code per minute
        }
        $brand = Branding::get(0)['product'];
        $from = (string) Settings::platform('platform_from_email', '');
        if ((int) $s['duplicate']) {
            DB::query('UPDATE signups SET otp_sends = otp_sends + 1, otp_expires_at = :x WHERE id = :id', ['x' => date('Y-m-d H:i:s', time() + self::OTP_TTL), 'id' => $id]);
            Notifier::email($s['email'], $brand . ': ' . I18n::translate('sign-up request', $s['language']),
                I18n::translate('Someone (hopefully you) tried to start a free trial with this email address, but an account already exists. Please log in or reset your password: :url', $s['language'], ['url' => admin_url('login.php')])
                . "\n\n" . I18n::translate('If this was not you, you can ignore this email.', $s['language']) . "\n\n" . $brand, $from, $brand);
            return true;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::query('UPDATE signups SET otp_hash = :h, otp_expires_at = :x, otp_attempts = 0, otp_sends = otp_sends + 1 WHERE id = :id', [
            'h' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]), 'x' => date('Y-m-d H:i:s', time() + self::OTP_TTL), 'id' => $id,
        ]);
        Notifier::email($s['email'], $brand . ': ' . I18n::translate('your verification code :code', $s['language'], ['code' => $code]),
            I18n::translate('Hello :name,', $s['language'], ['name' => $s['owner_name']]) . "\n\n"
            . I18n::translate('Your code to start the free trial for :hotel is: :code', $s['language'], ['hotel' => $s['hotel_name'], 'code' => $code]) . "\n"
            . I18n::translate('The code is valid for 15 minutes.', $s['language']) . "\n\n"
            . I18n::translate('If this was not you, you can ignore this email.', $s['language']) . "\n\n" . $brand, $from, $brand);
        return true;
    }

    /**
     * Check a code. Returns [ok, error message|null, hotelId|null, userId|null]. 5 wrong codes end
     * the request; expired codes are refused.
     */
    public static function verifyOtp(int $id, string $code): array
    {
        $generic = __('The code is not valid or has expired.');
        $s = self::find($id);
        if (!$s || $s['status'] !== 'verify') {
            return [false, $generic, null, null];
        }
        $attempts = (int) $s['otp_attempts'] + 1;
        DB::query('UPDATE signups SET otp_attempts = otp_attempts + 1 WHERE id = :id', ['id' => $id]);
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $valid = strlen($code) === 6 && $s['otp_hash'] && !(int) $s['duplicate']
            && $s['otp_expires_at'] && strtotime((string) $s['otp_expires_at']) >= time()
            && $attempts <= self::OTP_MAX_ATTEMPTS && password_verify($code, (string) $s['otp_hash']);
        if (!$valid) {
            if ($attempts >= self::OTP_MAX_ATTEMPTS) {
                DB::query("UPDATE signups SET status = 'expired', otp_hash = NULL, password_hash = NULL WHERE id = :id", ['id' => $id]);
                return [false, __('Too many wrong codes. Please start the sign-up again.'), null, null];
            }
            return [false, $generic . ' ' . __(':n attempts left.', ['n' => self::OTP_MAX_ATTEMPTS - $attempts]), null, null];
        }
        DB::query('UPDATE signups SET verified_at = :n, otp_hash = NULL WHERE id = :id', ['n' => now(), 'id' => $id]);
        if (self::isDuplicate($s['email'], $s['mobile'], $id)) {
            // Taken in the meantime (race): let the platform look at it.
            DB::query("UPDATE signups SET status = 'pending', duplicate = 1 WHERE id = :id", ['id' => $id]);
            self::notifyPlatform($id, 'pending');
            return [false, __('Thank you! We will review your request and contact you shortly.'), null, null];
        }
        [$hid, $uid] = self::provision($id);
        return [true, null, $hid, $uid];
    }

    /** Platform admin approves a pending request. Returns [hotelId, userId]. */
    public static function approve(int $id, ?int $by): array
    {
        $s = self::find($id);
        if (!$s || $s['status'] !== 'pending' || !$s['password_hash']) {
            throw new InvalidArgumentException(__('This request cannot be approved.'));
        }
        if (DB::value('SELECT id FROM users WHERE email = :e', ['e' => $s['email']])) {
            throw new InvalidArgumentException(__('Email :e is already used by another account.', ['e' => $s['email']]));
        }
        DB::query('UPDATE signups SET decided_by = :b, decided_at = :n WHERE id = :id', ['b' => $by, 'n' => now(), 'id' => $id]);
        $r = self::provision($id);
        $s = self::find($id);
        $brand = Branding::get(0)['product'];
        Notifier::sendToContact($s['email'], $s['mobile'], $brand . ': ' . I18n::translate('your free trial is ready', $s['language']),
            I18n::translate('Hello :name,', $s['language'], ['name' => $s['owner_name']]) . "\n\n"
            . I18n::translate('Your free trial for :hotel is ready. Log in with your email address and the password you chose: :url', $s['language'], ['hotel' => $s['hotel_name'], 'url' => admin_url('login.php')])
            . "\n\n" . $brand, ['email']);
        return $r;
    }

    public static function reject(int $id, ?int $by, string $reason = ''): void
    {
        $s = self::find($id);
        if (!$s || !in_array($s['status'], ['pending', 'verify'], true)) {
            throw new InvalidArgumentException(__('This request cannot be rejected.'));
        }
        DB::query("UPDATE signups SET status = 'rejected', password_hash = NULL, otp_hash = NULL, decided_by = :b, decided_at = :n, reject_reason = :r WHERE id = :id",
            ['b' => $by, 'n' => now(), 'r' => mb_substr(trim($reason), 0, 255) ?: null, 'id' => $id]);
    }

    /**
     * Create the trial hotel + super admin + demo content for a sign-up. Returns [hotelId, userId].
     */
    public static function provision(int $id): array
    {
        $s = self::find($id);
        if (!$s || !$s['password_hash'] || $s['hotel_id']) {
            throw new RuntimeException('Sign-up cannot be provisioned');
        }
        $planId = (int) self::setting('trial_plan_id');
        if ($planId && !DB::value('SELECT id FROM plans WHERE id = :id', ['id' => $planId])) {
            $planId = 0;
        }
        $maxTvs = trim(self::setting('trial_max_tvs'));
        $ends = date('Y-m-d H:i:s', time() + self::trialDays() * 86400);
        $hid = Hotels::create([
            'name' => $s['hotel_name'], 'city' => $s['city'], 'plan_id' => $planId ?: null,
            'contact_name' => $s['owner_name'], 'contact_email' => $s['email'], 'contact_phone' => $s['mobile'],
            'max_tvs' => $maxTvs === '' ? null : max(1, (int) $maxTvs), 'expires_at' => $ends, 'is_trial' => 1,
            'notes' => 'Free trial (online sign-up #' . $id . ')',
        ]);
        try {
            $uid = DB::insert('users', [
                'hotel_id' => $hid, 'username' => self::uniqueUsername($s['email']), 'email' => $s['email'],
                'full_name' => $s['owner_name'], 'password_hash' => $s['password_hash'], 'role' => 'super_admin',
                'language' => $s['language'], 'is_active' => 1, 'created_at' => now(),
            ]);
        } catch (PDOException $e) {
            // Email taken in the meantime: remove the empty hotel again.
            DB::query('DELETE FROM system_settings WHERE hotel_id = :h', ['h' => $hid]);
            DB::query('DELETE FROM hotels WHERE id = :h', ['h' => $hid]);
            throw new RuntimeException('Could not create the user: ' . $e->getMessage(), 0, $e);
        }
        Tenant::run($hid, static function () use ($s): void {
            Settings::set('default_language', $s['language']);
            $rooms = max(1, min(self::MAX_DEMO_ROOMS, (int) $s['tv_estimate']));
            Demo::sampleContent([1 => $rooms]);
        });
        DB::query("UPDATE signups SET status = 'approved', hotel_id = :h, user_id = :u, trial_ends_at = :t, password_hash = NULL, otp_hash = NULL WHERE id = :id",
            ['h' => $hid, 'u' => $uid, 't' => $ends, 'id' => $id]);
        ActivityLog::add('signup_trial', 'hotel', $hid, 'Free trial started by online sign-up #' . $id, $hid);
        self::notifyPlatform($id, 'created');
        Logger::write('signup', 'info', 'Trial hotel created', ['signup' => $id, 'hotel' => $hid]);
        return [$hid, $uid];
    }

    /** Username from the e-mail's local part, unique. */
    public static function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '', strtolower(strstr($email, '@', true) ?: 'owner')) ?? '';
        $base = substr(trim($base, '.-'), 0, 40);
        if (strlen($base) < 3) {
            $base = 'owner' . $base;
        }
        $u = $base;
        for ($i = 2; DB::value('SELECT id FROM users WHERE username = :u', ['u' => $u]); $i++) {
            $u = $base . $i;
        }
        return $u;
    }

    /** E-mail the platform about a sign-up ('pending' = needs approval, 'created' = trial started). */
    public static function notifyPlatform(int $id, string $what): void
    {
        $to = trim(self::setting('signup_notify_email')) ?: trim((string) Settings::platform('platform_notify_email', ''));
        $s = self::find($id);
        if ($to === '' || !$s) {
            return;
        }
        $brand = Branding::get(0)['product'];
        $subject = $what === 'pending' ? $brand . ': sign-up waiting for approval — ' . $s['hotel_name'] : $brand . ': new free trial — ' . $s['hotel_name'];
        $body = sprintf("Business: %s (%s)\nOwner: %s\nMobile: %s\nEmail: %s\nScreens / TVs: %d\nLanguage: %s%s\n\n%s",
            $s['hotel_name'], $s['city'], $s['owner_name'], $s['mobile'], $s['email'], $s['tv_estimate'], $s['language'],
            (int) $s['duplicate'] ? "\nNOTE: email or mobile already belongs to an existing account." : '',
            admin_url('platform_signups.php'));
        Notifier::email($to, $subject, $body, (string) Settings::platform('platform_from_email', ''), $brand);
    }

    // ------------------------------------------------------------------ trial lifecycle

    /** Days left of a trial hotel (0 = ends today / ended), null when not a trial. */
    public static function daysLeft(?array $hotel, ?int $now = null): ?int
    {
        if (!$hotel || empty($hotel['is_trial']) || empty($hotel['expires_at'])) {
            return null;
        }
        $now ??= time();
        return max(0, (int) ceil((strtotime((string) $hotel['expires_at']) - $now) / 86400));
    }

    /**
     * Hourly (TrialTask): reminders 3 days / 1 day before the end (email + WhatsApp to the hotel
     * contact), expiry → status 'expired' (reason 'trial'), stale unverified sign-ups → expired.
     */
    public static function processTrials(?int $now = null): array
    {
        $now ??= time();
        $out = ['reminded' => 0, 'expired' => 0, 'stale' => 0];
        $brand = Branding::get(0)['product'];
        $channels = ['email'];
        if (trim((string) Settings::platform('billing_whatsapp_url', '')) !== '') {
            $channels[] = 'whatsapp';
        }
        $hotels = DB::all("SELECT id, name, contact_email, contact_phone, expires_at FROM hotels WHERE is_trial = 1 AND status = 'active' AND expires_at IS NOT NULL ORDER BY id");
        foreach ($hotels as $h) {
            $hid = (int) $h['id'];
            $left = strtotime((string) $h['expires_at']) - $now;
            $lang = (string) Settings::getFor($hid, 'default_language', 'en');
            $sent = (int) Settings::getFor($hid, 'trial_reminders', '0');
            $upgrade = admin_url('billing.php');
            if ($left <= 0) {
                Hotels::setStatus($hid, 'expired', 'trial');
                Notifier::sendToContact((string) $h['contact_email'], (string) $h['contact_phone'], $brand . ': ' . I18n::translate('your free trial has ended', $lang),
                    I18n::translate('Dear :hotel, your free trial has ended and the TV service is paused. Choose a plan to continue: :url', $lang, ['hotel' => $h['name'], 'url' => $upgrade]) . "\n\n" . $brand, $channels);
                Settings::setFor($hid, 'trial_reminders', (string) ($sent | 4));
                $out['expired']++;
                continue;
            }
            $bit = $left <= 86400 ? 2 : ($left <= 3 * 86400 ? 1 : 0);
            if ($bit && !($sent & $bit)) {
                $days = $bit === 2 ? 1 : 3;
                Notifier::sendToContact((string) $h['contact_email'], (string) $h['contact_phone'], $brand . ': ' . I18n::translate('your free trial ends in :n day(s)', $lang, ['n' => $days]),
                    I18n::translate('Dear :hotel, your free trial ends on :date. Upgrade to a paid plan to keep your TVs running: :url', $lang, ['hotel' => $h['name'], 'date' => date('d M Y H:i', (int) strtotime((string) $h['expires_at'])), 'url' => $upgrade]) . "\n\n" . $brand, $channels);
                Settings::setFor($hid, 'trial_reminders', (string) ($sent | $bit | ($bit === 2 ? 1 : 0)));
                $out['reminded']++;
            }
        }
        $out['stale'] = DB::query("UPDATE signups SET status = 'expired', password_hash = NULL, otp_hash = NULL WHERE status = 'verify' AND created_at < :t",
            ['t' => date('Y-m-d H:i:s', $now - 86400)])->rowCount();
        return $out;
    }

    /** Pending upgrade of a trial hotel: ['plan_id', 'invoice_id', 'tvs', 'at'] or null. */
    public static function pendingUpgrade(int $hotelId): ?array
    {
        $u = json_decode((string) Settings::getFor($hotelId, 'trial_upgrade', ''), true);
        return is_array($u) && !empty($u['invoice_id']) ? $u : null;
    }

    /**
     * Trial hotel chooses a paid plan: manual invoice (Billing) for one month, platform notified.
     * An earlier unpaid upgrade invoice is cancelled. Returns the invoice id.
     */
    public static function requestUpgrade(int $hotelId, int $planId, int $tvs, ?int $userId): int
    {
        $hotel = Hotels::find($hotelId);
        if (!$hotel || !(int) $hotel['is_trial']) {
            throw new InvalidArgumentException(__('This customer is not on a free trial.'));
        }
        $plan = DB::one('SELECT * FROM plans WHERE id = :id AND is_active = 1', ['id' => $planId]);
        if (!$plan) {
            throw new InvalidArgumentException(__('Choose a plan.'));
        }
        $tvs = max(1, min(5000, $tvs));
        if ($plan['max_tvs'] !== null && $tvs > (int) $plan['max_tvs']) {
            throw new InvalidArgumentException(__('The :p plan allows at most :n TVs.', ['p' => $plan['name'], 'n' => $plan['max_tvs']]));
        }
        $prev = self::pendingUpgrade($hotelId);
        if ($prev && DB::value("SELECT status FROM invoices WHERE id = :id AND hotel_id = :h", ['id' => $prev['invoice_id'], 'h' => $hotelId]) === 'unpaid') {
            DB::query("UPDATE invoices SET status = 'cancelled' WHERE id = :id AND hotel_id = :h", ['id' => $prev['invoice_id'], 'h' => $hotelId]);
        }
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+1 month -1 day'));
        $iid = Billing::createInvoice($hotelId, $from, $to, $userId, $tvs, (float) $plan['price_per_tv_month'], 'Upgrade from free trial to plan ' . $plan['name']);
        DB::query('UPDATE invoices SET plan_name = :p WHERE id = :id', ['p' => $plan['name'], 'id' => $iid]);
        Settings::setFor($hotelId, 'trial_upgrade', json_out(['plan_id' => (int) $plan['id'], 'invoice_id' => $iid, 'tvs' => $tvs, 'at' => now()]));
        DB::query('UPDATE signups SET upgrade_plan_id = :p, upgrade_invoice_id = :i, upgrade_requested_at = :n WHERE hotel_id = :h',
            ['p' => $plan['id'], 'i' => $iid, 'n' => now(), 'h' => $hotelId]);
        $to = trim(self::setting('signup_notify_email')) ?: trim((string) Settings::platform('platform_notify_email', ''));
        if ($to !== '') {
            $brand = Branding::get(0)['product'];
            $inv = Billing::find($iid);
            Notifier::email($to, $brand . ': upgrade request — ' . $hotel['name'],
                sprintf("%s wants to upgrade from the free trial to %s (%d TVs).\nInvoice %s: %s\nMark it paid in Platform → Invoices to activate the plan: %s",
                    $hotel['name'], $plan['name'], $tvs, $inv['number'], money($inv['total'], $inv['currency']), admin_url('platform_invoices.php')),
                (string) Settings::platform('platform_from_email', ''), $brand);
        }
        ActivityLog::add('trial_upgrade_request', 'invoice', $iid, 'Upgrade to ' . $plan['name'] . " ($tvs TVs)", $hotelId);
        return $iid;
    }

    /**
     * Called by Billing::reactivateIfPaid() after a payment: when the hotel's upgrade invoice is paid
     * the trial ends — paid plan, no expiry, plan TV limit, status active. Returns true when converted.
     */
    public static function activatePaidUpgrade(int $hotelId): bool
    {
        $u = self::pendingUpgrade($hotelId);
        if (!$u) {
            return false;
        }
        if (DB::value('SELECT status FROM invoices WHERE id = :id AND hotel_id = :h', ['id' => $u['invoice_id'], 'h' => $hotelId]) !== 'paid') {
            return false;
        }
        $h = DB::one('SELECT id, name, is_trial, contact_email, contact_phone FROM hotels WHERE id = :id', ['id' => $hotelId]);
        if (!$h || !(int) $h['is_trial']) {
            return false;
        }
        $planId = DB::value('SELECT id FROM plans WHERE id = :id', ['id' => $u['plan_id']]) ? (int) $u['plan_id'] : null;
        DB::query('UPDATE hotels SET is_trial = 0, plan_id = :p, expires_at = NULL, max_tvs = NULL WHERE id = :id', ['p' => $planId, 'id' => $hotelId]);
        Hotels::setStatus($hotelId, 'active');
        Settings::setFor($hotelId, 'trial_upgrade', '');
        DB::query('UPDATE signups SET converted_at = :n WHERE hotel_id = :h AND converted_at IS NULL', ['n' => now(), 'h' => $hotelId]);
        $brand = Branding::get(0)['product'];
        $lang = (string) Settings::getFor($hotelId, 'default_language', 'en');
        Notifier::sendToContact((string) $h['contact_email'], (string) $h['contact_phone'], $brand . ': ' . I18n::translate('your plan is active', $lang),
            I18n::translate('Dear :hotel, thank you for your payment. Your paid plan is active and your TVs are running.', $lang, ['hotel' => $h['name']]) . "\n\n" . $brand, ['email']);
        Logger::write('signup', 'info', 'Trial converted to paid plan', ['hotel' => $hotelId, 'plan' => $planId, 'invoice' => $u['invoice_id']]);
        return true;
    }

    /** Platform: give a trial more days (also re-activates a trial that expired). */
    public static function extendTrial(int $hotelId, int $days): void
    {
        $h = DB::one('SELECT * FROM hotels WHERE id = :id AND is_trial = 1', ['id' => $hotelId]);
        if (!$h) {
            throw new InvalidArgumentException(__('This customer is not on a free trial.'));
        }
        $base = max(time(), (int) strtotime((string) ($h['expires_at'] ?? 'now')));
        $ends = date('Y-m-d H:i:s', $base + max(1, min(90, $days)) * 86400);
        DB::query('UPDATE hotels SET expires_at = :e WHERE id = :id', ['e' => $ends, 'id' => $hotelId]);
        DB::query('UPDATE signups SET trial_ends_at = :e WHERE hotel_id = :id', ['e' => $ends, 'id' => $hotelId]);
        Settings::setFor($hotelId, 'trial_reminders', '0');
        if ($h['status'] !== 'active' && $h['suspend_reason'] === 'trial') {
            Hotels::setStatus($hotelId, 'active');
        } else {
            Hotels::changed($hotelId);
        }
    }

    // ------------------------------------------------------------------ getting started

    /** Getting-started checklist of a hotel: [key => [done, label, url, icon]]. */
    public static function checklist(int $hotelId): array
    {
        $h = ['h' => $hotelId];
        $rooms = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', $h);
        $logo = trim((string) Settings::getFor($hotelId, 'hotel_logo', '')) !== '';
        $tvs = (int) DB::value('SELECT COUNT(*) FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL', $h);
        $pushed = (int) DB::value('SELECT COUNT(*) FROM content_items WHERE hotel_id = :h AND created_by IS NOT NULL', $h)
            + (int) DB::value('SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND created_by IS NOT NULL', $h);
        $staff = (int) DB::value('SELECT COUNT(*) FROM users WHERE hotel_id = :h AND is_active = 1', $h);
        $claim = is_file(HC_ROOT . '/admin/claim.php') ? 'claim.php' : 'rooms.php';
        return [
            'rooms' => [$rooms > 0, __('Add your screens'), 'rooms.php', 'bi-door-open', __(':n screens', ['n' => $rooms])],
            'logo' => [$logo, __('Set your business logo'), 'settings.php', 'bi-image', ''],
            'tv' => [$tvs > 0, __('Add your first TV with the QR code'), $claim, 'bi-qr-code-scan', __(':n TVs', ['n' => $tvs])],
            'content' => [$pushed > 0, __('Add and push your own content'), 'content.php', 'bi-broadcast', ''],
            'staff' => [$staff > 1, __('Invite your staff'), 'users.php', 'bi-people', __(':n users', ['n' => $staff])],
        ];
    }

    // ------------------------------------------------------------------ platform stats

    public static function stats(): array
    {
        $by = [];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM signups GROUP BY status') as $r) {
            $by[$r['status']] = (int) $r['n'];
        }
        $approved = $by['approved'] ?? 0;
        $converted = (int) DB::value('SELECT COUNT(*) FROM signups WHERE converted_at IS NOT NULL');
        return [
            'total' => array_sum($by),
            'verify' => $by['verify'] ?? 0,
            'pending' => $by['pending'] ?? 0,
            'approved' => $approved,
            'rejected' => $by['rejected'] ?? 0,
            'expired' => $by['expired'] ?? 0,
            'active_trials' => (int) DB::value("SELECT COUNT(*) FROM hotels WHERE is_trial = 1 AND status = 'active'"),
            'expired_trials' => (int) DB::value("SELECT COUNT(*) FROM hotels WHERE is_trial = 1 AND status <> 'active'"),
            'converted' => $converted,
            'conversion' => $approved > 0 ? round($converted * 100 / $approved, 1) : 0.0,
            'last7' => (int) DB::value('SELECT COUNT(*) FROM signups WHERE created_at >= :t', ['t' => date('Y-m-d H:i:s', time() - 7 * 86400)]),
        ];
    }
}
