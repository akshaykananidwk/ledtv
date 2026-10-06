<?php
declare(strict_types=1);

/**
 * Ad marketplace (#19): local businesses ("advertisers") book TV ad space in hotels that opted in;
 * hotels earn a revenue share.
 *
 *  - Advertisers are NOT hotel users: own table (mkt_advertisers), own session cookie (HCADVSESSID,
 *    path /advertise/, see MarketplacePortal), never touch Auth / users. Every advertiser query is scoped
 *    by advertiser_id; advertisers only see the public hotel listing fields (publicHotel()).
 *  - Hotels opt in on admin/marketplace.php (mkt_hotel_settings: price per TV per day and/or per 1000
 *    impressions, max marketplace ads per loop, allowed / blocked categories, auto or manual approval).
 *  - Booking flow: draft → submitted (platform review, optional) → awaiting_payment (manual bank / UPI) →
 *    paid (platform marks paid) → hotel approval per hotel line (auto or manual) → scheduled → running →
 *    completed; rejected / cancelled with reason and refund note.
 *  - Approved + paid line → sponsor + content item (creative copied into the hotel's media folder) +
 *    ad_campaign inside that hotel (existing Ads module, AdsExtension and impression logging unchanged).
 *    mkt_booking_hotels.campaign_id links the booking line to ad_campaigns.id.
 *  - Money: DECIMAL(12,2) columns; every amount is computed server-side in integer paise.
 */
final class Marketplace
{
    public const STATUSES = ['draft', 'submitted', 'awaiting_payment', 'paid', 'scheduled', 'running', 'completed', 'rejected', 'cancelled'];
    /** Bookings that hold a slot in a hotel (capacity = max ads per loop). */
    public const OPEN_STATUSES = ['submitted', 'awaiting_payment', 'paid', 'scheduled', 'running'];
    public const FINAL_STATUSES = ['completed', 'rejected', 'cancelled'];
    public const LINE_ACTIVE = ['approved', 'delivered'];
    public const MODELS = ['per_day', 'cpm'];
    public const DEFAULT_CATEGORIES = ['food', 'travel', 'shopping', 'religious', 'health', 'education', 'services', 'events', 'real_estate', 'other'];
    public const MAX_HOTELS_PER_BOOKING = 50;
    public const MAX_ADS_PER_LOOP = 5;
    public const OTP_MINUTES = 15;
    public const MAX_OTP_ATTEMPTS = 5;
    public const MAX_LOGIN_FAILS = 5;
    public const LOCK_MINUTES = 15;
    public const IDLE_TIMEOUT = 14400;
    public const ABSOLUTE_TIMEOUT = 43200;
    /** Video types accepted for ads (what Android TVs play reliably). */
    public const VIDEO_EXT = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];

    /** Platform settings (hotel 0; every key starts with platform_ so Settings treats it as platform-wide). */
    public const PLATFORM_DEFAULTS = [
        'platform_mkt_enabled' => '1',
        'platform_mkt_hotel_share' => '70',
        'platform_mkt_tax_percent' => '18',
        'platform_mkt_bank_text' => '',
        'platform_mkt_upi_id' => '',
        'platform_mkt_upi_name' => '',
        'platform_mkt_expire_days' => '7',
        'platform_mkt_signup_otp' => '1',
        'platform_mkt_signup_approval' => '0',
        'platform_mkt_review' => '0',
        'platform_mkt_rules' => "No adult, political, tobacco, alcohol, gambling or misleading content.\nNo web links, QR codes to external sites or phone-scam offers.\nThe business name must be visible in the ad.\nHotels and the platform may reject any ad.",
        'platform_mkt_categories' => '',
        'platform_mkt_max_image_mb' => '10',
        'platform_mkt_max_video_mb' => '50',
        'platform_mkt_max_days' => '90',
    ];

    // ================================================================== settings & helpers

    public static function setting(string $key): string
    {
        return (string) Settings::platform($key, self::PLATFORM_DEFAULTS[$key] ?? '');
    }

    public static function enabled(): bool
    {
        return self::setting('platform_mkt_enabled') === '1';
    }

    public static function hotelSharePct(): float
    {
        return max(0.0, min(100.0, (float) self::setting('platform_mkt_hotel_share')));
    }

    public static function taxPct(): float
    {
        return max(0.0, min(50.0, (float) self::setting('platform_mkt_tax_percent')));
    }

    public static function currency(): string
    {
        return strtoupper((string) Settings::platform('billing_currency', 'INR')) ?: 'INR';
    }

    /** @return string[] category keys */
    public static function categories(): array
    {
        $raw = trim(self::setting('platform_mkt_categories'));
        if ($raw === '') {
            return self::DEFAULT_CATEGORIES;
        }
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $c) {
            $c = preg_replace('/[^a-z0-9_]/', '', $c) ?? '';
            if ($c !== '' && strlen($c) <= 40 && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out ?: self::DEFAULT_CATEGORIES;
    }

    public static function categoryLabel(string $c): string
    {
        return match ($c) {
            'food' => __('Food & restaurants'),
            'travel' => __('Travel & taxi'),
            'shopping' => __('Shopping'),
            'religious' => __('Religious'),
            'health' => __('Health'),
            'education' => __('Education'),
            'services' => __('Local services'),
            'events' => __('Events'),
            'real_estate' => __('Real estate'),
            'other' => __('Other'),
            default => ucfirst(str_replace('_', ' ', $c)),
        };
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'draft' => __('Draft'),
            'submitted' => __('Submitted (in review)'),
            'awaiting_payment' => __('Awaiting payment'),
            'paid' => __('Paid — waiting for hotels'),
            'scheduled' => __('Scheduled'),
            'running' => __('Running'),
            'completed' => __('Completed'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
            'pending' => __('Waiting for hotel approval'),
            'approved' => __('Approved'),
            'delivered' => __('Delivered'),
            'unpaid' => __('Unpaid'),
            default => $s,
        };
    }

    public static function statusBadge(string $s): string
    {
        $cls = match ($s) {
            'draft' => 'secondary', 'submitted', 'pending', 'unpaid' => 'warning', 'awaiting_payment' => 'warning',
            'paid', 'scheduled', 'approved' => 'info', 'running', 'delivered' => 'success', 'completed' => 'dark',
            'rejected', 'cancelled' => 'danger', default => 'secondary',
        };
        return '<span class="badge text-bg-' . $cls . '">' . e(self::statusLabel($s)) . '</span>';
    }

    public static function modelLabel(string $m): string
    {
        return match ($m) {
            'per_day' => __('Per TV per day'),
            'cpm' => __('Per 1000 impressions'),
            'both' => __('Both'),
            default => $m,
        };
    }

    /** "12.50" → 1250 paise (never floats for totals). */
    public static function paise(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromPaise(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }

    public static function money(mixed $amount): string
    {
        return money((float) $amount, self::currency());
    }

    /** Number of days in [start, end] inclusive. */
    public static function days(string $start, string $end): int
    {
        return max(0, (int) round((strtotime($end . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400) + 1);
    }

    /** True when text contains a web address / link (creatives must not carry external URLs). */
    public static function hasUrl(string $text): bool
    {
        return (bool) preg_match('~(https?:|ftp:|//|www\.|\b[a-z0-9-]+\.(com|in|net|org|co|io|info|biz|xyz|app|shop|online|site|link|ly|me|tv)\b)~i', $text);
    }

    private static function json(mixed $v): array
    {
        $d = is_string($v) && $v !== '' ? json_decode($v, true) : $v;
        return is_array($d) ? $d : [];
    }

    private static function event(int $bookingId, string $status, string $note = '', string $actor = 'system', ?int $hotelId = null): void
    {
        DB::insert('mkt_booking_events', [
            'booking_id' => $bookingId, 'hotel_id' => $hotelId, 'status' => $status,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'actor' => mb_substr($actor, 0, 60), 'created_at' => now(),
        ]);
    }

    // ================================================================== advertisers

    /** @return array{0: array, 1: string[]} */
    public static function validateSignup(array $in): array
    {
        $s = static fn (string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $data = [
            'business_name' => $s('business_name', 150),
            'contact_name' => $s('contact_name', 120),
            'mobile' => preg_replace('/[^0-9+]/', '', $s('mobile', 30)) ?? '',
            'email' => strtolower($s('email', 190)),
            'city' => $s('city', 80) ?: null,
        ];
        $errors = [];
        if (mb_strlen($data['business_name']) < 2) {
            $errors[] = __('Business name is required.');
        }
        if ($data['contact_name'] === '') {
            $errors[] = __('Contact person is required.');
        }
        if (!preg_match('/^\+?[0-9]{10,15}$/', $data['mobile'])) {
            $errors[] = __('Enter a valid mobile number.');
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Invalid email address.');
        } elseif (DB::value('SELECT id FROM mkt_advertisers WHERE email = :e', ['e' => $data['email']])) {
            $errors[] = __('An advertiser account with this email already exists.');
        }
        $pw = (string) ($in['password'] ?? '');
        if ($err = Auth::passwordError($pw)) {
            $errors[] = $err;
        } elseif ($pw !== (string) ($in['password2'] ?? $pw)) {
            $errors[] = __('Passwords do not match.');
        }
        return [$data, $errors];
    }

    /** Create the account. Returns [id, otp code or null]. */
    public static function register(array $data, string $password, string $lang = 'en'): array
    {
        $otp = self::setting('platform_mkt_signup_otp') === '1';
        $approval = self::setting('platform_mkt_signup_approval') === '1';
        $id = DB::insert('mkt_advertisers', $data + [
            'password_hash' => Auth::hash($password),
            'status' => $otp ? 'unverified' : ($approval ? 'pending' : 'active'),
            'language' => isset(I18n::LANGUAGES[$lang]) ? $lang : 'en',
            'created_at' => now(),
        ]);
        $code = $otp ? self::sendOtp($id) : null;
        if (!$otp && $approval) {
            self::notifyPlatform(__('New advertiser waiting for approval'), $data['business_name'] . ' (' . $data['email'] . ')');
        }
        return [$id, $code];
    }

    public static function advertiser(int $id): ?array
    {
        return $id > 0 ? DB::one('SELECT * FROM mkt_advertisers WHERE id = :id', ['id' => $id]) : null;
    }

    /** Email a fresh 6-digit code. Returns the code (callers never show it; tests use it). */
    public static function sendOtp(int $advertiserId): string
    {
        $code = (string) random_int(100000, 999999);
        DB::update('mkt_advertisers', [
            'otp_hash' => hash('sha256', $advertiserId . ':' . $code),
            'otp_expires_at' => date('Y-m-d H:i:s', time() + self::OTP_MINUTES * 60),
            'otp_attempts' => 0,
        ], 'id = :id', ['id' => $advertiserId]);
        $a = self::advertiser($advertiserId);
        if ($a) {
            $brand = Branding::get(0)['product'];
            Notifier::email((string) $a['email'], $brand . ': ' . __('your verification code'),
                sprintf("%s\n\n%s: %s\n\n%s", $a['contact_name'], __('Your verification code'), $code, __('The code is valid for :m minutes.', ['m' => self::OTP_MINUTES])),
                (string) Settings::platform('platform_from_email', ''), $brand);
        }
        return $code;
    }

    /** Check the emailed code. Returns null on success, else an error message. */
    public static function verifyOtp(int $advertiserId, string $code): ?string
    {
        $a = self::advertiser($advertiserId);
        if (!$a || $a['status'] !== 'unverified') {
            return null;
        }
        if ((int) $a['otp_attempts'] >= self::MAX_OTP_ATTEMPTS || !$a['otp_hash'] || strtotime((string) $a['otp_expires_at']) < time()) {
            return __('The code expired. Send a new code.');
        }
        if (!hash_equals((string) $a['otp_hash'], hash('sha256', $advertiserId . ':' . trim($code)))) {
            DB::query('UPDATE mkt_advertisers SET otp_attempts = otp_attempts + 1 WHERE id = :id', ['id' => $advertiserId]);
            return __('Wrong code. Please check the email and try again.');
        }
        $approval = self::setting('platform_mkt_signup_approval') === '1';
        DB::update('mkt_advertisers', ['status' => $approval ? 'pending' : 'active', 'email_verified_at' => now(), 'otp_hash' => null, 'otp_expires_at' => null], 'id = :id', ['id' => $advertiserId]);
        if ($approval) {
            self::notifyPlatform(__('New advertiser waiting for approval'), $a['business_name'] . ' (' . $a['email'] . ')');
        }
        return null;
    }

    /**
     * Check email + password. Returns [advertiser row|null, error|null]. Per-account lockout after
     * MAX_LOGIN_FAILS failures and an IP throttle (RateLimiter) against password spraying.
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        if (RateLimiter::hit('mkt_login:' . $ip, 20, 900) > 0) {
            return [null, __('Too many attempts. Please wait a few minutes.')];
        }
        $a = DB::one('SELECT * FROM mkt_advertisers WHERE email = :e', ['e' => strtolower(trim($email))]);
        if ($a && $a['locked_until'] && strtotime((string) $a['locked_until']) > time()) {
            return [null, __('Account locked after too many failed attempts. Try again in :m minutes.', ['m' => (int) ceil((strtotime((string) $a['locked_until']) - time()) / 60)])];
        }
        if (!$a || !password_verify($password, (string) $a['password_hash'])) {
            if (!$a) {
                password_verify($password, '$2y$12$0000000000000000000000.000000000000000000000000000000');
                return [null, __('Invalid email or password.')];
            }
            $fails = (int) $a['failed_attempts'] + 1;
            $lock = $fails >= self::MAX_LOGIN_FAILS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            DB::update('mkt_advertisers', ['failed_attempts' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = :id', ['id' => $a['id']]);
            return [null, __('Invalid email or password.')];
        }
        if ($a['status'] === 'suspended') {
            return [null, __('This advertiser account is suspended.')];
        }
        DB::update('mkt_advertisers', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip], 'id = :id', ['id' => $a['id']]);
        return [$a, null];
    }

    public static function setAdvertiserStatus(int $id, string $status, string $note = ''): void
    {
        if (!in_array($status, ['pending', 'active', 'suspended'], true) || !self::advertiser($id)) {
            return;
        }
        DB::update('mkt_advertisers', ['status' => $status, 'status_note' => $note !== '' ? mb_substr($note, 0, 255) : null], 'id = :id', ['id' => $id]);
        if ($status === 'suspended') {
            DB::query('UPDATE mkt_advertiser_sessions SET revoked = 1 WHERE advertiser_id = :a', ['a' => $id]);
        }
    }

    public static function advertisers(string $status = ''): array
    {
        $p = [];
        $w = '1=1';
        if (in_array($status, ['unverified', 'pending', 'active', 'suspended'], true)) {
            $w = 'a.status = :s';
            $p['s'] = $status;
        }
        return DB::all(
            "SELECT a.id, a.business_name, a.contact_name, a.mobile, a.email, a.city, a.status, a.created_at, a.last_login_at,
                    (SELECT COUNT(*) FROM mkt_bookings b WHERE b.advertiser_id = a.id AND b.status <> 'draft') AS bookings,
                    (SELECT COALESCE(SUM(b.total - b.refund_amount), 0) FROM mkt_bookings b WHERE b.advertiser_id = a.id AND b.paid_at IS NOT NULL) AS spent
             FROM mkt_advertisers a WHERE $w ORDER BY a.status = 'pending' DESC, a.id DESC LIMIT 500",
            $p
        );
    }

    // ================================================================== hotel opt-in

    public static function hotelDefaults(): array
    {
        return ['enabled' => 0, 'pricing_model' => 'per_day', 'price_per_tv_day' => '0.00', 'price_cpm' => '0.00', 'max_ads_per_loop' => 2,
            'allowed_categories' => '[]', 'blocked_categories' => '[]', 'approval' => 'manual', 'description' => ''];
    }

    /** Marketplace settings of a hotel (current hotel by default). */
    public static function hotelSettings(?int $hotelId = null): array
    {
        $hotelId ??= Tenant::id();
        $row = DB::one('SELECT * FROM mkt_hotel_settings WHERE hotel_id = :h', ['h' => $hotelId]);
        return ($row ?: []) + self::hotelDefaults() + ['hotel_id' => $hotelId];
    }

    /** @return array{0: array, 1: string[]} */
    public static function validateHotelSettings(array $in): array
    {
        $cats = self::categories();
        $model = in_array($in['pricing_model'] ?? '', ['per_day', 'cpm', 'both'], true) ? (string) $in['pricing_model'] : 'per_day';
        $allowed = array_values(array_intersect($cats, (array) ($in['allowed_categories'] ?? [])));
        $blocked = array_values(array_intersect($cats, (array) ($in['blocked_categories'] ?? [])));
        $data = [
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'pricing_model' => $model,
            'price_per_tv_day' => self::fromPaise(max(0, min(self::paise(1000000), self::paise($in['price_per_tv_day'] ?? 0)))),
            'price_cpm' => self::fromPaise(max(0, min(self::paise(1000000), self::paise($in['price_cpm'] ?? 0)))),
            'max_ads_per_loop' => max(1, min(self::MAX_ADS_PER_LOOP, (int) ($in['max_ads_per_loop'] ?? 2))),
            'allowed_categories' => json_out(array_values(array_diff($allowed, $blocked))),
            'blocked_categories' => json_out($blocked),
            'approval' => ($in['approval'] ?? 'manual') === 'auto' ? 'auto' : 'manual',
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
        ];
        $errors = [];
        if ($data['enabled']) {
            if ($model !== 'cpm' && self::paise($data['price_per_tv_day']) <= 0) {
                $errors[] = __('Enter the price per TV per day.');
            }
            if ($model !== 'per_day' && self::paise($data['price_cpm']) <= 0) {
                $errors[] = __('Enter the price per 1000 impressions.');
            }
            if ($data['description'] !== null && self::hasUrl($data['description'])) {
                $errors[] = __('Links / web addresses are not allowed.');
            }
        }
        return [$data, $errors];
    }

    public static function saveHotelSettings(array $data, ?int $userId = null): void
    {
        $hid = Tenant::id();
        $cols = $data + ['updated_by' => $userId];
        $sets = implode(', ', array_map(fn ($k) => "`$k` = VALUES(`$k`)", array_keys($cols)));
        $names = implode(', ', array_map(fn ($k) => "`$k`", array_keys($cols)));
        $ph = implode(', ', array_map(fn ($k) => ':' . $k, array_keys($cols)));
        DB::query("INSERT INTO mkt_hotel_settings (hotel_id, $names) VALUES (:hid, $ph) ON DUPLICATE KEY UPDATE $sets", $cols + ['hid' => $hid]);
    }

    /** Does a hotel's setting accept a category? */
    public static function acceptsCategory(array $s, string $category): bool
    {
        $allowed = self::json($s['allowed_categories'] ?? null);
        $blocked = self::json($s['blocked_categories'] ?? null);
        return !in_array($category, $blocked, true) && (!$allowed || in_array($category, $allowed, true));
    }

    public static function supportsModel(array $s, string $model): bool
    {
        return $s['pricing_model'] === 'both' || $s['pricing_model'] === $model;
    }

    // ================================================================== public listing

    /**
     * Hotels that sell ad space (opted in, active, ads module in the plan, at least one TV).
     * Only public fields: never contacts, registration keys, rooms numbers or other hotels' data.
     */
    public static function listHotels(array $filter = []): array
    {
        $rows = DB::all(
            "SELECT h.id, h.name, h.city, h.status, h.expires_at, s.*
             FROM mkt_hotel_settings s JOIN hotels h ON h.id = s.hotel_id
             WHERE s.enabled = 1 AND h.status = 'active' AND (h.expires_at IS NULL OR h.expires_at > :now)
             ORDER BY h.city, h.name",
            ['now' => now()]
        );
        $city = mb_strtolower(trim((string) ($filter['city'] ?? '')));
        $cat = (string) ($filter['category'] ?? '');
        $model = (string) ($filter['model'] ?? '');
        $out = [];
        foreach ($rows as $r) {
            $hid = (int) $r['hotel_id'];
            if ($city !== '' && !str_contains(mb_strtolower((string) $r['city']), $city)) {
                continue;
            }
            if ($cat !== '' && !self::acceptsCategory($r, $cat)) {
                continue;
            }
            if (in_array($model, self::MODELS, true) && !self::supportsModel($r, $model)) {
                continue;
            }
            $pub = self::publicRow($r);
            if ($pub) {
                $out[] = $pub;
            }
        }
        return $out;
    }

    /** Public listing row of one hotel, or null when it does not sell ad space. */
    public static function publicHotel(int $hotelId): ?array
    {
        $r = DB::one(
            "SELECT h.id, h.name, h.city, h.status, h.expires_at, s.* FROM mkt_hotel_settings s JOIN hotels h ON h.id = s.hotel_id
             WHERE s.hotel_id = :h AND s.enabled = 1 AND h.status = 'active' AND (h.expires_at IS NULL OR h.expires_at > :now)",
            ['h' => $hotelId, 'now' => now()]
        );
        return $r ? self::publicRow($r) : null;
    }

    private static function publicRow(array $r): ?array
    {
        $hid = (int) $r['hotel_id'];
        if (!Tenant::feature('ads', $hid) || Tenant::state($hid) !== 'active') {
            return null;
        }
        $tvs = Tenant::tvCount($hid);
        if ($tvs <= 0) {
            return null;
        }
        return [
            'id' => $hid,
            'name' => (string) $r['name'],
            'city' => (string) ($r['city'] ?? ''),
            'tv_count' => $tvs,
            'rooms' => (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]),
            'pricing_model' => (string) $r['pricing_model'],
            'price_per_tv_day' => $r['pricing_model'] !== 'cpm' ? (string) $r['price_per_tv_day'] : null,
            'price_cpm' => $r['pricing_model'] !== 'per_day' ? (string) $r['price_cpm'] : null,
            'max_ads_per_loop' => (int) $r['max_ads_per_loop'],
            'allowed_categories' => self::json($r['allowed_categories']),
            'blocked_categories' => self::json($r['blocked_categories']),
            'approval' => (string) $r['approval'],
            'description' => (string) ($r['description'] ?? ''),
            'est_daily_impressions' => self::estimatedDailyImpressions($hid),
        ];
    }

    /**
     * Estimated impressions per day for one marketplace ad in a hotel, from analytics: average items
     * played per day over the last 7 complete days ÷ 4 (campaigns run "after every 3 items").
     * Null when the hotel has no play data yet.
     */
    public static function estimatedDailyImpressions(int $hotelId): ?int
    {
        static $cache = [];
        if (!array_key_exists($hotelId, $cache)) {
            $plays = (int) DB::value(
                "SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND ad_campaign_id IS NULL AND created_at >= :f AND created_at < :t",
                ['h' => $hotelId, 'f' => date('Y-m-d 00:00:00', strtotime('-7 days')), 't' => date('Y-m-d 00:00:00')]
            );
            $cache[$hotelId] = $plays > 0 ? max(1, (int) round($plays / 7 / 4)) : null;
        }
        return $cache[$hotelId];
    }

    /** Marketplace slots of a hotel already taken in [start, end] (other bookings that hold a slot). */
    public static function slotsTaken(int $hotelId, string $start, string $end, int $excludeBookingId = 0): int
    {
        [$in, $p] = DB::in(self::OPEN_STATUSES, 'os');
        return (int) DB::value(
            "SELECT COUNT(*) FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id
             WHERE bh.hotel_id = :h AND bh.status IN ('pending','approved') AND b.status IN $in AND b.id <> :x
               AND b.start_date <= :e AND b.end_date >= :s",
            $p + ['h' => $hotelId, 'x' => $excludeBookingId, 's' => $start, 'e' => $end]
        );
    }

    // ================================================================== quote

    /**
     * Price quote, computed server-side only.
     *  - per_day: days × TVs × price per TV per day (per hotel line)
     *  - cpm:     impressions ÷ 1000 × price per 1000 impressions (per hotel line)
     * Tax (platform setting) on the subtotal. Unavailable hotels are reported in 'errors' and left out.
     * @return array{lines: array, subtotal: string, tax_percent: string, tax: string, total: string, errors: string[], days: int}
     */
    public static function quote(array $hotelIds, string $model, string $start, string $end, ?int $impressions, string $category, int $excludeBookingId = 0): array
    {
        $days = self::days($start, $end);
        $lines = [];
        $errors = [];
        $sub = 0;
        foreach (array_values(array_unique(array_map('intval', $hotelIds))) as $hid) {
            $pub = self::publicHotel($hid);
            if (!$pub) {
                $errors[] = __('Hotel #:id does not sell ad space.', ['id' => $hid]);
                continue;
            }
            $s = self::hotelSettings($hid);
            if (!self::supportsModel($s, $model)) {
                $errors[] = __(':h does not offer this price model.', ['h' => $pub['name']]);
                continue;
            }
            if (!self::acceptsCategory($s, $category)) {
                $errors[] = __(':h does not accept ads of this category.', ['h' => $pub['name']]);
                continue;
            }
            if (self::slotsTaken($hid, $start, $end, $excludeBookingId) >= (int) $s['max_ads_per_loop']) {
                $errors[] = __(':h is fully booked for these dates.', ['h' => $pub['name']]);
                continue;
            }
            if ($model === 'cpm') {
                $unit = self::paise($s['price_cpm']);
                $amount = (int) round(max(0, (int) $impressions) * $unit / 1000);
            } else {
                $unit = self::paise($s['price_per_tv_day']);
                $amount = $days * $pub['tv_count'] * $unit;
            }
            $sub += $amount;
            $lines[] = [
                'hotel_id' => $hid, 'name' => $pub['name'], 'city' => $pub['city'], 'tv_count' => $pub['tv_count'], 'days' => $days,
                'unit_price' => self::fromPaise($unit), 'impressions' => $model === 'cpm' ? (int) $impressions : null,
                'amount' => self::fromPaise($amount), 'approval' => $pub['approval'],
            ];
        }
        $taxPct = self::taxPct();
        $tax = (int) round($sub * $taxPct / 100);
        return ['lines' => $lines, 'subtotal' => self::fromPaise($sub), 'tax_percent' => number_format($taxPct, 2, '.', ''), 'tax' => self::fromPaise($tax),
            'total' => self::fromPaise($sub + $tax), 'errors' => $errors, 'days' => $days];
    }

    // ================================================================== creatives

    public static function creatives(int $advertiserId, bool $activeOnly = false): array
    {
        return DB::all(
            'SELECT * FROM mkt_creatives WHERE advertiser_id = :a AND status ' . ($activeOnly ? "= 'active'" : "<> 'deleted'") . ' ORDER BY id DESC',
            ['a' => $advertiserId]
        );
    }

    /** Creative of this advertiser (null for anyone else's id). */
    public static function creative(int $advertiserId, int $id): ?array
    {
        return DB::one("SELECT * FROM mkt_creatives WHERE id = :id AND advertiser_id = :a AND status <> 'deleted'", ['id' => $id, 'a' => $advertiserId]);
    }

    /** Validate + store an uploaded image / video under uploads/adv/a{id}/YYYY/MM/. Returns the creative id. */
    public static function uploadCreative(int $advertiserId, array $file, string $title, int $duration = 15): int
    {
        $title = mb_substr(trim($title), 0, 150);
        if ($title === '') {
            throw new RuntimeException(__('Give the ad a title.'));
        }
        if (self::hasUrl($title)) {
            throw new RuntimeException(__('Links / web addresses are not allowed.'));
        }
        if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(($file['error'] ?? null) === UPLOAD_ERR_NO_FILE ? __('No file was selected.') : __('Upload failed. Please try again.'));
        }
        if (!is_uploaded_file((string) $file['tmp_name']) && !defined('HC_TESTING')) {
            throw new RuntimeException(__('Invalid upload.'));
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
        $base = 'adv/a' . $advertiserId;
        if (isset(Uploader::IMAGE_TYPES[$ext])) {
            $max = max(1, (int) self::setting('platform_mkt_max_image_mb'));
            if ((int) $file['size'] > $max * 1048576) {
                throw new RuntimeException(__('File too large. Maximum is :m MB.', ['m' => $max]));
            }
            $info = @getimagesize((string) $file['tmp_name']);
            if (!in_array($mime, Uploader::IMAGE_TYPES, true) || $info === false) {
                throw new RuntimeException(__('Only JPG, PNG, GIF or WEBP images are allowed.'));
            }
            if ($info[0] < 320 || $info[1] < 180) {
                throw new RuntimeException(__('The image is too small (minimum 320 × 180 pixels).'));
            }
            $up = Uploader::storeImage((string) $file['tmp_name'], $mime, $base, 1920);
            $type = 'image';
            $duration = max(5, min(60, $duration));
        } elseif (isset(self::VIDEO_EXT[$ext])) {
            $max = max(1, (int) self::setting('platform_mkt_max_video_mb'));
            if ((int) $file['size'] > $max * 1048576) {
                throw new RuntimeException(__('File too large. Maximum is :m MB.', ['m' => $max]));
            }
            if (!str_starts_with($mime, 'video/')) {
                throw new RuntimeException(__('Only MP4 or WEBM videos are allowed.'));
            }
            $rel = $base . '/' . date('Y/m');
            $abs = HC_ROOT . '/uploads/' . $rel;
            if (!is_dir($abs)) {
                mkdir($abs, 0755, true);
            }
            $name = random_token(12) . '.' . $ext;
            $ok = defined('HC_TESTING') ? copy((string) $file['tmp_name'], $abs . '/' . $name) : move_uploaded_file((string) $file['tmp_name'], $abs . '/' . $name);
            if (!$ok) {
                throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
            }
            @chmod($abs . '/' . $name, 0644);
            $up = ['path' => $rel . '/' . $name, 'size' => (int) filesize($abs . '/' . $name), 'mime' => self::VIDEO_EXT[$ext], 'thumb' => null];
            $type = 'video';
            $duration = 0; // videos play to the end
        } else {
            throw new RuntimeException(__('Upload an image (JPG, PNG, GIF, WEBP) or a video (MP4, WEBM).'));
        }
        return DB::insert('mkt_creatives', [
            'advertiser_id' => $advertiserId, 'type' => $type, 'title' => $title, 'file_path' => $up['path'], 'thumb_path' => $up['thumb'],
            'mime_type' => $up['mime'], 'file_size' => $up['size'], 'duration' => $duration, 'created_at' => now(),
        ]);
    }

    /** Text announcement creative (no links allowed). Returns the id. */
    public static function createTextCreative(int $advertiserId, array $in): int
    {
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 150);
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 200);
        $sub = mb_substr(trim((string) ($in['subtitle'] ?? '')), 0, 120);
        if ($title === '' || $text === '') {
            throw new RuntimeException(__('Title and text are required.'));
        }
        if (self::hasUrl($title . ' ' . $text . ' ' . $sub)) {
            throw new RuntimeException(__('Links / web addresses are not allowed.'));
        }
        if (preg_match('/[<>]/', $title . $text . $sub)) {
            throw new RuntimeException(__('HTML is not allowed.'));
        }
        return DB::insert('mkt_creatives', [
            'advertiser_id' => $advertiserId, 'type' => 'text', 'title' => $title, 'body' => $text,
            'settings' => json_out(['subtitle' => $sub, 'bg_color' => clean_color($in['bg_color'] ?? null, '#1A237E'), 'text_color' => clean_color($in['text_color'] ?? null, '#FFFFFF')]),
            'duration' => max(5, min(60, (int) ($in['duration'] ?? 15))), 'created_at' => now(),
        ]);
    }

    /** Delete a creative that no submitted booking uses. */
    public static function deleteCreative(int $advertiserId, int $id): bool
    {
        $c = self::creative($advertiserId, $id);
        if (!$c || DB::value("SELECT id FROM mkt_bookings WHERE creative_id = :c AND status <> 'draft' LIMIT 1", ['c' => $id])) {
            return false;
        }
        DB::query("UPDATE mkt_bookings SET creative_id = NULL WHERE creative_id = :c AND status = 'draft' AND advertiser_id = :a", ['c' => $id, 'a' => $advertiserId]);
        DB::update('mkt_creatives', ['status' => 'deleted'], 'id = :id AND advertiser_id = :a', ['id' => $id, 'a' => $advertiserId]);
        Uploader::delete($c['file_path'], $c['thumb_path']);
        return true;
    }

    /** Platform / hotel moderation: reject a creative and every open booking that uses it. */
    public static function rejectCreative(int $creativeId, string $reason, string $actor): void
    {
        $c = DB::one('SELECT * FROM mkt_creatives WHERE id = :id', ['id' => $creativeId]);
        if (!$c) {
            return;
        }
        DB::update('mkt_creatives', ['status' => 'rejected', 'reject_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $creativeId]);
        foreach (DB::column("SELECT id FROM mkt_bookings WHERE creative_id = :c AND status NOT IN ('draft','completed','rejected','cancelled')", ['c' => $creativeId]) as $bid) {
            self::rejectBooking((int) $bid, __('Ad rejected') . ': ' . $reason, '', $actor);
        }
    }

    /** TV-simulator preview data (escaped by the caller). */
    public static function previewData(array $c): array
    {
        $s = self::json($c['settings'] ?? null);
        return [
            'type' => $c['type'], 'title' => (string) $c['title'], 'url' => $c['file_path'] ? media_url((string) $c['file_path']) : null,
            'text' => (string) ($c['body'] ?? ''), 'subtitle' => (string) ($s['subtitle'] ?? ''),
            'bg' => clean_color($s['bg_color'] ?? null, '#1A237E'), 'fg' => clean_color($s['text_color'] ?? null, '#FFFFFF'),
        ];
    }

    // ================================================================== bookings (advertiser)

    /**
     * Validate booking form input of an advertiser.
     * @return array{0: array, 1: int[], 2: string[]} [booking data, hotel ids, errors]
     */
    public static function validateBooking(int $advertiserId, array $in): array
    {
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 150);
        $category = (string) ($in['category'] ?? '');
        $model = in_array($in['pricing_model'] ?? '', self::MODELS, true) ? (string) $in['pricing_model'] : 'per_day';
        $start = Ads::date($in['start_date'] ?? null);
        $end = Ads::date($in['end_date'] ?? null);
        $ds = Ads::time($in['daily_start'] ?? null);
        $de = Ads::time($in['daily_end'] ?? null);
        $creativeId = (int) ($in['creative_id'] ?? 0);
        $impressions = $model === 'cpm' ? (int) ($in['impressions'] ?? 0) : null;
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['hotel_ids'] ?? [])), fn ($v) => $v > 0)));
        if ($title === '') {
            $errors[] = __('Give the booking a title.');
        } elseif (self::hasUrl($title)) {
            $errors[] = __('Links / web addresses are not allowed.');
        }
        if (!in_array($category, self::categories(), true)) {
            $errors[] = __('Choose a category.');
            $category = 'other';
        }
        if (!$start || !$end) {
            $errors[] = __('Choose the start and end date.');
            $start ??= date('Y-m-d', strtotime('+1 day'));
            $end ??= $start;
        } else {
            if ($start < date('Y-m-d')) {
                $errors[] = __('The start date cannot be in the past.');
            }
            if ($end < $start) {
                $errors[] = __('End date must be on or after the start date.');
                $end = $start;
            }
            $maxDays = max(1, (int) self::setting('platform_mkt_max_days'));
            if (self::days($start, $end) > $maxDays) {
                $errors[] = __('A booking can run at most :n days.', ['n' => $maxDays]);
            }
        }
        if (($ds === null) !== ($de === null) || ($ds !== null && $ds === $de)) {
            $errors[] = __('Give both daily times (from and to) or leave both empty.');
            $ds = $de = null;
        }
        if ($model === 'cpm' && ($impressions < 1000 || $impressions > 10000000)) {
            $errors[] = __('Impressions per hotel: between 1,000 and 10,000,000.');
        }
        $creative = $creativeId ? self::creative($advertiserId, $creativeId) : null;
        if (!$creative || $creative['status'] !== 'active') {
            $errors[] = __('Choose an ad (creative).');
            $creativeId = 0;
        }
        if (!$hotelIds) {
            $errors[] = __('Choose at least one hotel.');
        } elseif (count($hotelIds) > self::MAX_HOTELS_PER_BOOKING) {
            $errors[] = __('At most :n hotels per booking.', ['n' => self::MAX_HOTELS_PER_BOOKING]);
        }
        return [[
            'title' => $title, 'category' => $category, 'pricing_model' => $model, 'start_date' => $start, 'end_date' => $end,
            'daily_start' => $ds, 'daily_end' => $de, 'impressions' => $impressions, 'creative_id' => $creativeId ?: null,
        ], $hotelIds, $errors];
    }

    /** Booking of this advertiser (null for anyone else's id). */
    public static function booking(int $advertiserId, int $id): ?array
    {
        return DB::one('SELECT * FROM mkt_bookings WHERE id = :id AND advertiser_id = :a', ['id' => $id, 'a' => $advertiserId]);
    }

    /** Any booking (platform pages). */
    public static function findBooking(int $id): ?array
    {
        return DB::one(
            'SELECT b.*, a.business_name, a.contact_name, a.mobile, a.email AS advertiser_email
             FROM mkt_bookings b JOIN mkt_advertisers a ON a.id = b.advertiser_id WHERE b.id = :id',
            ['id' => $id]
        );
    }

    public static function bookings(int $advertiserId): array
    {
        return DB::all(
            'SELECT b.*, (SELECT COUNT(*) FROM mkt_booking_hotels bh WHERE bh.booking_id = b.id) AS hotels
             FROM mkt_bookings b WHERE b.advertiser_id = :a ORDER BY b.id DESC LIMIT 500',
            ['a' => $advertiserId]
        );
    }

    /** Lines of a booking with the hotel's public name / city. */
    public static function lines(int $bookingId): array
    {
        return DB::all(
            'SELECT bh.*, h.name AS hotel_name, h.city AS hotel_city FROM mkt_booking_hotels bh JOIN hotels h ON h.id = bh.hotel_id
             WHERE bh.booking_id = :b ORDER BY h.name',
            ['b' => $bookingId]
        );
    }

    public static function events(int $bookingId): array
    {
        return DB::all('SELECT * FROM mkt_booking_events WHERE booking_id = :b ORDER BY id', ['b' => $bookingId]);
    }

    /** Save (create / update) a draft with its hotel lines and current quote. Returns the booking id. */
    public static function saveDraft(int $advertiserId, array $data, array $hotelIds, ?int $bookingId = null): int
    {
        if ($bookingId) {
            $b = self::booking($advertiserId, $bookingId);
            if (!$b || $b['status'] !== 'draft') {
                throw new RuntimeException(__('Only drafts can be changed.'));
            }
        }
        $q = self::quote($hotelIds, $data['pricing_model'], $data['start_date'], $data['end_date'], $data['impressions'], $data['category'], (int) $bookingId);
        return (int) DB::transaction(function () use ($advertiserId, $data, $q, $bookingId) {
            $row = $data + ['subtotal' => $q['subtotal'], 'tax_percent' => $q['tax_percent'], 'tax' => $q['tax'], 'total' => $q['total'], 'currency' => self::currency()];
            if ($bookingId) {
                DB::update('mkt_bookings', $row, 'id = :id AND advertiser_id = :a', ['id' => $bookingId, 'a' => $advertiserId]);
                DB::query('DELETE FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bookingId]);
            } else {
                $bookingId = DB::insert('mkt_bookings', $row + ['advertiser_id' => $advertiserId, 'status' => 'draft', 'created_at' => now()]);
                self::event($bookingId, 'draft', '', 'advertiser');
            }
            foreach ($q['lines'] as $l) {
                DB::insert('mkt_booking_hotels', [
                    'booking_id' => $bookingId, 'hotel_id' => $l['hotel_id'], 'tv_count' => $l['tv_count'], 'days' => $l['days'],
                    'unit_price' => $l['unit_price'], 'impressions' => $l['impressions'], 'amount' => $l['amount'],
                    'hotel_share_pct' => number_format(self::hotelSharePct(), 2, '.', ''), 'status' => 'pending', 'created_at' => now(),
                ]);
            }
            return $bookingId;
        });
    }

    /**
     * Submit a draft: re-quote server-side (availability, capacity, prices), assign the order number and
     * move to submitted (platform review on) or awaiting_payment. Throws RuntimeException with the reasons.
     */
    public static function submit(int $advertiserId, int $bookingId): void
    {
        $b = self::booking($advertiserId, $bookingId);
        if (!$b || $b['status'] !== 'draft') {
            throw new RuntimeException(__('Only drafts can be submitted.'));
        }
        $adv = self::advertiser($advertiserId);
        if (!$adv || $adv['status'] !== 'active') {
            throw new RuntimeException(__('Your account is not active yet.'));
        }
        $c = $b['creative_id'] ? self::creative($advertiserId, (int) $b['creative_id']) : null;
        if (!$c || $c['status'] !== 'active') {
            throw new RuntimeException(__('Choose an ad (creative).'));
        }
        if ($b['start_date'] < date('Y-m-d')) {
            throw new RuntimeException(__('The start date cannot be in the past.'));
        }
        $hotelIds = array_map('intval', DB::column('SELECT hotel_id FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bookingId]));
        $impr = $b['impressions'] !== null ? (int) $b['impressions'] : null;
        $q = self::quote($hotelIds, (string) $b['pricing_model'], (string) $b['start_date'], (string) $b['end_date'], $impr, (string) $b['category'], $bookingId);
        if ($q['errors'] || !$q['lines']) {
            throw new RuntimeException(implode("\n", $q['errors'] ?: [__('Choose at least one hotel.')]));
        }
        $review = self::setting('platform_mkt_review') === '1';
        $status = $review ? 'submitted' : 'awaiting_payment';
        DB::transaction(function () use ($b, $q, $status, $bookingId) {
            // Lock the hotel lines' capacity: re-count inside the transaction.
            foreach ($q['lines'] as $l) {
                $s = self::hotelSettings($l['hotel_id']);
                if (self::slotsTaken($l['hotel_id'], (string) $b['start_date'], (string) $b['end_date'], $bookingId) >= (int) $s['max_ads_per_loop']) {
                    throw new RuntimeException(__(':h is fully booked for these dates.', ['h' => $l['name']]));
                }
            }
            DB::query('DELETE FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bookingId]);
            foreach ($q['lines'] as $l) {
                DB::insert('mkt_booking_hotels', [
                    'booking_id' => $bookingId, 'hotel_id' => $l['hotel_id'], 'tv_count' => $l['tv_count'], 'days' => $l['days'],
                    'unit_price' => $l['unit_price'], 'impressions' => $l['impressions'], 'amount' => $l['amount'],
                    'hotel_share_pct' => number_format(self::hotelSharePct(), 2, '.', ''), 'status' => 'pending', 'created_at' => now(),
                ]);
            }
            DB::update('mkt_bookings', [
                'number' => 'AD-' . date('Y') . '-' . str_pad((string) $bookingId, 5, '0', STR_PAD_LEFT),
                'subtotal' => $q['subtotal'], 'tax_percent' => $q['tax_percent'], 'tax' => $q['tax'], 'total' => $q['total'], 'currency' => self::currency(),
                'status' => $status, 'submitted_at' => now(),
                'expires_at' => date('Y-m-d H:i:s', time() + max(1, (int) self::setting('platform_mkt_expire_days')) * 86400),
            ], 'id = :id', ['id' => $bookingId]);
            self::event($bookingId, 'submitted', '', 'advertiser');
            if ($status === 'awaiting_payment') {
                self::event($bookingId, 'awaiting_payment', __('Order issued. Pay by bank transfer / UPI.'), 'system');
            }
        });
        $b = self::findBooking($bookingId);
        self::notifyPlatform(__('New ad order :n', ['n' => $b['number']]), $b['business_name'] . ': ' . $b['title'] . ' — ' . self::money($b['total']));
        self::notifyAdvertiser($bookingId, __('Order :n received', ['n' => $b['number']]),
            __('Total') . ': ' . self::money($b['total']) . "\n" . ($status === 'submitted' ? __('We review your ad first, then send the payment details.') : __('Payment details are on the order page.')));
    }

    /** Advertiser cancels an unpaid booking (draft / submitted / awaiting payment). */
    public static function cancelByAdvertiser(int $advertiserId, int $bookingId, string $reason = ''): void
    {
        $b = self::booking($advertiserId, $bookingId);
        if (!$b || !in_array($b['status'], ['draft', 'submitted', 'awaiting_payment'], true)) {
            throw new RuntimeException(__('This booking can no longer be cancelled here. Please contact us.'));
        }
        DB::query("UPDATE mkt_booking_hotels SET status = 'cancelled' WHERE booking_id = :b", ['b' => $bookingId]);
        DB::update('mkt_bookings', ['status' => 'cancelled', 'status_reason' => mb_substr($reason !== '' ? $reason : __('Cancelled by the advertiser'), 0, 500)], 'id = :id', ['id' => $bookingId]);
        self::event($bookingId, 'cancelled', $reason, 'advertiser');
    }

    // ================================================================== platform workflow

    /** Platform review passed (platform_mkt_review = 1): submitted → awaiting_payment. */
    public static function approveReview(int $bookingId, string $actor = 'platform'): void
    {
        $b = self::findBooking($bookingId);
        if (!$b || $b['status'] !== 'submitted') {
            throw new RuntimeException(__('Only submitted orders can be approved.'));
        }
        DB::update('mkt_bookings', ['status' => 'awaiting_payment', 'expires_at' => date('Y-m-d H:i:s', time() + max(1, (int) self::setting('platform_mkt_expire_days')) * 86400)], 'id = :id', ['id' => $bookingId]);
        self::event($bookingId, 'awaiting_payment', __('Ad approved. Please pay to confirm.'), $actor);
        self::notifyAdvertiser($bookingId, __('Order :n approved — please pay', ['n' => $b['number']]), __('Total') . ': ' . self::money($b['total']));
    }

    /**
     * Platform admin records the payment → paid; revenue shares are fixed on every line; hotels with
     * auto-approve get their campaign immediately, the others are notified to approve.
     */
    public static function markPaid(int $bookingId, string $ref, string $method = '', ?string $paidAt = null, ?int $userId = null, string $actor = 'platform'): void
    {
        $b = self::findBooking($bookingId);
        if (!$b || !in_array($b['status'], ['submitted', 'awaiting_payment'], true)) {
            throw new RuntimeException(__('Only unpaid orders can be marked as paid.'));
        }
        $ref = mb_substr(trim($ref), 0, 120);
        if ($ref === '') {
            throw new RuntimeException(__('Enter the payment reference (UTR / transaction id).'));
        }
        $paidAt = $paidAt && strtotime($paidAt) ? date('Y-m-d H:i:s', (int) strtotime($paidAt)) : now();
        $pct = self::hotelSharePct();
        DB::transaction(function () use ($bookingId, $ref, $method, $paidAt, $userId, $pct, $actor) {
            foreach (DB::all('SELECT id, amount FROM mkt_booking_hotels WHERE booking_id = :b', ['b' => $bookingId]) as $l) {
                $amount = self::paise($l['amount']);
                $hotel = (int) round($amount * $pct / 100);
                DB::query('UPDATE mkt_booking_hotels SET hotel_share_pct = :p, hotel_share = :hs, platform_share = :ps WHERE id = :id', [
                    'p' => number_format($pct, 2, '.', ''), 'hs' => self::fromPaise($hotel), 'ps' => self::fromPaise($amount - $hotel), 'id' => (int) $l['id'],
                ]);
            }
            DB::update('mkt_bookings', ['status' => 'paid', 'payment_ref' => $ref, 'payment_method' => mb_substr($method, 0, 40) ?: null,
                'paid_at' => $paidAt, 'paid_by' => $userId, 'expires_at' => null], 'id = :id', ['id' => $bookingId]);
            self::event($bookingId, 'paid', $ref, $actor);
        });
        self::notifyAdvertiser($bookingId, __('Payment received for :n', ['n' => $b['number']]), __('Thank you. The hotels now confirm your ad.'));
        foreach (self::lines($bookingId) as $l) {
            $s = self::hotelSettings((int) $l['hotel_id']);
            if ($s['approval'] === 'auto') {
                try {
                    self::approveLine((int) $l['id'], (int) $l['hotel_id'], null, 'auto');
                } catch (RuntimeException $e) {
                    self::event($bookingId, 'pending', $e->getMessage(), 'system', (int) $l['hotel_id']);
                }
            } else {
                self::notifyHotel((int) $l['hotel_id'], __('New marketplace ad to approve'), $b['business_name'] . ': ' . $b['title'] . ' (' . $b['start_date'] . ' – ' . $b['end_date'] . ')');
            }
        }
        self::refreshStatus($bookingId);
    }

    /** Platform rejects an order / creative (moderation): every line rejected, campaigns paused, refund noted. */
    public static function rejectBooking(int $bookingId, string $reason, string $refundNote = '', string $actor = 'platform'): void
    {
        self::closeBooking($bookingId, 'rejected', $reason, $refundNote, $actor);
    }

    /** Platform cancels an order (any non-final status). */
    public static function cancelBooking(int $bookingId, string $reason, string $refundNote = '', string $actor = 'platform'): void
    {
        self::closeBooking($bookingId, 'cancelled', $reason, $refundNote, $actor);
    }

    private static function closeBooking(int $bookingId, string $status, string $reason, string $refundNote, string $actor): void
    {
        $b = self::findBooking($bookingId);
        if (!$b || in_array($b['status'], self::FINAL_STATUSES, true)) {
            throw new RuntimeException(__('This order is already closed.'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('Give a reason.'));
        }
        foreach (self::lines($bookingId) as $l) {
            if (in_array($l['status'], ['pending', 'approved'], true)) {
                self::stopLine($l, $status === 'rejected' ? 'rejected' : 'cancelled', $reason);
            }
        }
        $refund = $b['paid_at'] ? (string) $b['total'] : '0.00';
        DB::update('mkt_bookings', ['status' => $status, 'status_reason' => mb_substr($reason, 0, 500),
            'refund_note' => trim($refundNote) !== '' ? mb_substr(trim($refundNote), 0, 500) : null, 'refund_amount' => $refund], 'id = :id', ['id' => $bookingId]);
        self::event($bookingId, $status, $reason . (trim($refundNote) !== '' ? ' — ' . trim($refundNote) : ''), $actor);
        self::notifyAdvertiser($bookingId, __('Order :n :s', ['n' => $b['number'] ?: '#' . $bookingId, 's' => self::statusLabel($status)]),
            $reason . ($b['paid_at'] ? "\n" . __('Refund') . ': ' . self::money($refund) . (trim($refundNote) !== '' ? ' — ' . trim($refundNote) : '') : ''));
    }

    /** Line → rejected / cancelled: pause its campaign, take it out of an unpaid payout statement. */
    private static function stopLine(array $l, string $status, string $reason, ?int $userId = null): void
    {
        DB::query('UPDATE mkt_booking_hotels SET status = :s, reason = :r, decided_at = :n, decided_by = COALESCE(:u, decided_by) WHERE id = :id', [
            's' => $status, 'r' => mb_substr($reason, 0, 500), 'n' => now(), 'u' => $userId, 'id' => (int) $l['id'],
        ]);
        if ($l['campaign_id']) {
            Tenant::run((int) $l['hotel_id'], static function () use ($l): void {
                Ads::setStatus((int) $l['campaign_id'], 'paused');
            });
        }
        if ($l['payout_id']) {
            self::recomputePayout((int) $l['payout_id']);
        }
    }

    // ================================================================== hotel workflow

    /** Lines of the current hotel (approval queue / bookings list). */
    public static function hotelLines(array $statuses = [], array $bookingStatuses = []): array
    {
        $p = ['h' => Tenant::id()];
        $w = 'bh.hotel_id = :h';
        if ($statuses) {
            [$in, $pp] = DB::in($statuses, 'ls');
            $w .= " AND bh.status IN $in";
            $p += $pp;
        }
        if ($bookingStatuses) {
            [$in, $pp] = DB::in($bookingStatuses, 'bs');
            $w .= " AND b.status IN $in";
            $p += $pp;
        }
        return DB::all(
            "SELECT bh.*, b.number, b.title, b.category, b.pricing_model, b.start_date, b.end_date, b.daily_start, b.daily_end, b.status AS booking_status,
                    b.paid_at, a.business_name, c.id AS creative_id, c.type AS creative_type, c.title AS creative_title, c.file_path AS creative_path,
                    c.body AS creative_body, c.settings AS creative_settings, c.status AS creative_status
             FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id JOIN mkt_advertisers a ON a.id = b.advertiser_id
             LEFT JOIN mkt_creatives c ON c.id = b.creative_id
             WHERE $w ORDER BY b.start_date DESC, bh.id DESC LIMIT 500",
            $p
        );
    }

    /** Line of the current hotel (404 + security log when it belongs to another hotel). */
    public static function hotelLine(int $lineId): ?array
    {
        return Tenant::find('mkt_booking_hotels', $lineId);
    }

    /**
     * Approve a hotel line (hotel manager or auto-approve). The booking must be paid. Creates the sponsor,
     * content item (creative copied into the hotel's media) and the campaign in that hotel.
     */
    public static function approveLine(int $lineId, int $hotelId, ?int $userId, string $actor): void
    {
        $l = DB::one('SELECT * FROM mkt_booking_hotels WHERE id = :id AND hotel_id = :h', ['id' => $lineId, 'h' => $hotelId]);
        if (!$l || $l['status'] !== 'pending') {
            throw new RuntimeException(__('This request was already decided.'));
        }
        $b = self::findBooking((int) $l['booking_id']);
        if (!$b || !in_array($b['status'], ['paid', 'scheduled', 'running'], true)) {
            throw new RuntimeException(__('The order is not paid yet.'));
        }
        if ($b['end_date'] < date('Y-m-d')) {
            throw new RuntimeException(__('The booking period is over.'));
        }
        $c = $b['creative_id'] ? DB::one('SELECT * FROM mkt_creatives WHERE id = :id', ['id' => (int) $b['creative_id']]) : null;
        if (!$c || $c['status'] !== 'active') {
            throw new RuntimeException(__('The ad was rejected or removed.'));
        }
        $s = self::hotelSettings($hotelId);
        $approved = (int) DB::value(
            "SELECT COUNT(*) FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id
             WHERE bh.hotel_id = :h AND bh.status = 'approved' AND bh.id <> :id AND b.start_date <= :e AND b.end_date >= :s",
            ['h' => $hotelId, 'id' => $lineId, 's' => $b['start_date'], 'e' => $b['end_date']]
        );
        if ($approved >= (int) $s['max_ads_per_loop']) {
            throw new RuntimeException(__('No free ad slot for these dates (max :n marketplace ads per loop).', ['n' => (int) $s['max_ads_per_loop']]));
        }
        $ids = Tenant::run($hotelId, fn () => self::createCampaign($b, $c, $hotelId));
        DB::query("UPDATE mkt_booking_hotels SET status = 'approved', decided_at = :n, decided_by = :u, sponsor_id = :sp, content_id = :ci, campaign_id = :ca, reason = NULL WHERE id = :id", [
            'n' => now(), 'u' => $userId, 'sp' => $ids['sponsor_id'], 'ci' => $ids['content_id'], 'ca' => $ids['campaign_id'], 'id' => $lineId,
        ]);
        self::event((int) $b['id'], 'approved', (string) DB::value('SELECT name FROM hotels WHERE id = :h', ['h' => $hotelId]), $actor, $hotelId);
        self::refreshStatus((int) $b['id']);
    }

    /** Hotel rejects a line (with reason). Approved lines are stopped too (campaign paused). Refund noted. */
    public static function rejectLine(int $lineId, int $hotelId, string $reason, ?int $userId, string $actor, bool $rejectCreative = false): void
    {
        $l = DB::one('SELECT * FROM mkt_booking_hotels WHERE id = :id AND hotel_id = :h', ['id' => $lineId, 'h' => $hotelId]);
        if (!$l || !in_array($l['status'], ['pending', 'approved'], true)) {
            throw new RuntimeException(__('This request was already decided.'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('Give a reason.'));
        }
        $b = self::findBooking((int) $l['booking_id']);
        self::stopLine($l, 'rejected', $reason, $userId);
        if ($b && $b['paid_at']) {
            $lineTotal = (int) round(self::paise($l['amount']) * (1 + (float) $b['tax_percent'] / 100));
            DB::query('UPDATE mkt_bookings SET refund_amount = LEAST(total, refund_amount + :r) WHERE id = :id', ['r' => self::fromPaise($lineTotal), 'id' => (int) $l['booking_id']]);
        }
        $hotelName = (string) DB::value('SELECT name FROM hotels WHERE id = :h', ['h' => $hotelId]);
        self::event((int) $l['booking_id'], 'rejected', $hotelName . ': ' . $reason . ($rejectCreative ? ' (' . __('ad rejected') . ')' : ''), $actor, $hotelId);
        if ($b) {
            self::notifyAdvertiser((int) $b['id'], __(':h declined your ad :n', ['h' => $hotelName, 'n' => $b['number']]), $reason . ($b['paid_at'] ? "\n" . __('The amount for this hotel will be refunded.') : ''));
        }
        self::refreshStatus((int) $l['booking_id']);
    }

    /**
     * Create sponsor (reused per advertiser and hotel) + content item + campaign in the CURRENT hotel.
     * The creative file is copied into uploads/h{hotel}/media/… so hotel content stays per hotel.
     * @return array{sponsor_id: int, content_id: int, campaign_id: int}
     */
    private static function createCampaign(array $b, array $c, int $hotelId): array
    {
        $sponsorId = (int) DB::value(
            'SELECT bh.sponsor_id FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id
             JOIN sponsors s ON s.id = bh.sponsor_id AND s.hotel_id = bh.hotel_id
             WHERE bh.hotel_id = :h AND b.advertiser_id = :a ORDER BY bh.id DESC LIMIT 1',
            ['h' => $hotelId, 'a' => (int) $b['advertiser_id']]
        );
        if (!$sponsorId) {
            $sponsorId = Ads::saveSponsor(null, [
                'name' => mb_substr((string) $b['business_name'], 0, 150), 'contact_name' => mb_substr((string) $b['contact_name'], 0, 120),
                'phone' => mb_substr((string) $b['mobile'], 0, 40), 'email' => (string) $b['advertiser_email'],
                'contract_start' => $b['start_date'], 'contract_end' => null,
                'notes' => mb_substr(__('Ad marketplace advertiser #:id', ['id' => (int) $b['advertiser_id']]), 0, 1000),
            ]);
        }
        $row = ['title' => mb_substr(__('Marketplace') . ' ' . $b['number'] . ': ' . $c['title'], 0, 190), 'duration' => (int) $c['duration'], 'is_active' => 1, 'created_at' => now()];
        if ($c['type'] === 'text') {
            $s = self::json($c['settings']);
            $row += ['type' => 'announcement', 'body' => (string) $c['body'], 'settings' => json_out([
                'subtitle' => (string) ($s['subtitle'] ?? ''), 'style' => 'fullscreen', 'bg_color' => clean_color($s['bg_color'] ?? null, '#1A237E'),
                'text_color' => clean_color($s['text_color'] ?? null, '#FFFFFF'), 'font_size' => 64, 'marketplace_booking_id' => (int) $b['id'],
            ])];
        } else {
            [$path, $thumb] = self::copyIntoHotel((string) $c['file_path'], $c['thumb_path'] ? (string) $c['thumb_path'] : null, $hotelId);
            $row += ['type' => $c['type'], 'file_path' => $path, 'thumb_path' => $thumb, 'mime_type' => $c['mime_type'], 'file_size' => $c['file_size'],
                'settings' => json_out($c['type'] === 'video' ? ['loop' => false, 'mute' => false, 'marketplace_booking_id' => (int) $b['id']] : ['marketplace_booking_id' => (int) $b['id']])];
        }
        $contentId = DB::insert('content_items', $row);
        $campaignId = Ads::saveCampaign(null, [
            'sponsor_id' => $sponsorId, 'name' => mb_substr(__('Marketplace') . ' ' . $b['number'] . ': ' . $b['title'], 0, 150), 'content_id' => $contentId,
            'start_date' => $b['start_date'], 'end_date' => $b['end_date'], 'daily_start' => $b['daily_start'], 'daily_end' => $b['daily_end'],
            'target_type' => 'all', 'target_ids' => '[]', 'freq_items' => 3, 'freq_minutes' => 10, 'max_per_day' => null, 'priority' => 0, 'status' => 'active',
        ]);
        ActivityLog::add('mkt_campaign_create', 'ad_campaign', $campaignId, 'Marketplace ' . $b['number'], $hotelId);
        return ['sponsor_id' => $sponsorId, 'content_id' => $contentId, 'campaign_id' => $campaignId];
    }

    /** Copy an advertiser file (uploads/adv/…) into the hotel's media folder. Returns [path, thumb]. */
    private static function copyIntoHotel(string $src, ?string $thumb, int $hotelId): array
    {
        if (str_contains($src, '..') || !str_starts_with($src, 'adv/') || !is_file(HC_ROOT . '/uploads/' . $src)) {
            throw new RuntimeException(__('The ad file is missing.'));
        }
        $rel = 'h' . $hotelId . '/media/' . date('Y/m');
        $abs = HC_ROOT . '/uploads/' . $rel;
        if (!is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
        $name = random_token(12);
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        copy(HC_ROOT . '/uploads/' . $src, $abs . '/' . $name . '.' . $ext);
        $t = null;
        if ($thumb && !str_contains($thumb, '..') && is_file(HC_ROOT . '/uploads/' . $thumb)) {
            copy(HC_ROOT . '/uploads/' . $thumb, $abs . '/' . $name . '_thumb.jpg');
            $t = $rel . '/' . $name . '_thumb.jpg';
        }
        return [$rel . '/' . $name . '.' . $ext, $t];
    }

    /**
     * Derive the booking status from its lines and dates (paid / scheduled / running / completed / rejected).
     * Called after every line decision and by MarketplaceTask.
     */
    public static function refreshStatus(int $bookingId): string
    {
        $b = DB::one('SELECT * FROM mkt_bookings WHERE id = :id', ['id' => $bookingId]);
        if (!$b || !in_array($b['status'], ['paid', 'scheduled', 'running'], true)) {
            return (string) ($b['status'] ?? '');
        }
        $counts = ['pending' => 0, 'approved' => 0, 'delivered' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM mkt_booking_hotels WHERE booking_id = :b GROUP BY status', ['b' => $bookingId]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $today = date('Y-m-d');
        $live = $counts['approved'] + $counts['delivered'];
        if ($live === 0 && $counts['pending'] === 0) {
            $new = 'rejected';
        } elseif ($live === 0) {
            $new = 'paid';
        } elseif ($b['end_date'] < $today || ($counts['approved'] === 0 && $counts['pending'] === 0)) {
            $new = 'completed';
        } elseif ($b['start_date'] > $today) {
            $new = 'scheduled';
        } else {
            $new = 'running';
        }
        if ($new !== $b['status']) {
            $upd = ['status' => $new];
            if ($new === 'rejected') {
                $upd['status_reason'] = __('No hotel accepted the ad.');
                $upd['refund_amount'] = $b['total'];
            }
            DB::update('mkt_bookings', $upd, 'id = :id', ['id' => $bookingId]);
            self::event($bookingId, $new, $new === 'rejected' ? __('No hotel accepted the ad.') : '', 'system');
            if (in_array($new, ['scheduled', 'running', 'completed', 'rejected'], true)) {
                self::notifyAdvertiser($bookingId, __('Order :n: :s', ['n' => $b['number'], 's' => self::statusLabel($new)]), $b['title']);
            }
        }
        return $new;
    }

    // ================================================================== reports

    /**
     * Proof of play for a booking: impressions / screen time per hotel per day from the hotels' ad stats
     * (Ads::report → ad_stats_daily roll-up + today's raw logs). Only hotels with an approved line.
     */
    public static function report(array $booking, ?string $from = null, ?string $to = null): array
    {
        $from ??= (string) $booking['start_date'];
        $to ??= min((string) $booking['end_date'], date('Y-m-d'));
        if ($to < $from) {
            $to = $from;
        }
        $out = ['from' => $from, 'to' => $to, 'hotels' => [], 'per_day' => [], 'totals' => ['impressions' => 0, 'seconds' => 0, 'rooms' => 0]];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out['per_day'][$d] = ['day' => $d, 'impressions' => 0, 'seconds' => 0];
        }
        foreach (self::lines((int) $booking['id']) as $l) {
            if (!$l['campaign_id']) {
                continue;
            }
            $r = Tenant::run((int) $l['hotel_id'], fn () => Ads::report([(int) $l['campaign_id']], $from, $to));
            $days = [];
            foreach ($r['per_day'] as $d) {
                $days[$d['day']] = ['impressions' => (int) $d['impressions'], 'seconds' => (int) $d['seconds'], 'rooms' => (int) $d['rooms']];
                if (isset($out['per_day'][$d['day']])) {
                    $out['per_day'][$d['day']]['impressions'] += (int) $d['impressions'];
                    $out['per_day'][$d['day']]['seconds'] += (int) $d['seconds'];
                }
            }
            $out['hotels'][] = ['hotel_id' => (int) $l['hotel_id'], 'name' => (string) $l['hotel_name'], 'city' => (string) $l['hotel_city'], 'status' => (string) $l['status'],
                'impressions_ordered' => $l['impressions'] !== null ? (int) $l['impressions'] : null,
                'totals' => $r['totals'], 'days' => $days];
            $out['totals']['impressions'] += (int) $r['totals']['impressions'];
            $out['totals']['seconds'] += (int) $r['totals']['seconds'];
            $out['totals']['rooms'] += (int) $r['totals']['rooms'];
        }
        $out['per_day'] = array_values($out['per_day']);
        return $out;
    }

    /** Hotel earnings (current hotel) for lines of bookings paid in [from, to]. */
    public static function hotelEarnings(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT bh.id, bh.amount, bh.hotel_share_pct, bh.hotel_share, bh.status, bh.payout_id, b.number, b.title, b.start_date, b.end_date, b.paid_at,
                    a.business_name, p.period AS payout_period, p.status AS payout_status
             FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id JOIN mkt_advertisers a ON a.id = b.advertiser_id
             LEFT JOIN mkt_payouts p ON p.id = bh.payout_id AND p.hotel_id = bh.hotel_id
             WHERE bh.hotel_id = :h AND bh.status IN ('approved','delivered') AND b.paid_at >= :f AND b.paid_at < :t
             ORDER BY b.paid_at DESC",
            ['h' => Tenant::id(), 'f' => $from . ' 00:00:00', 't' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );
        $gross = 0;
        $share = 0;
        foreach ($rows as $r) {
            $gross += self::paise($r['amount']);
            $share += self::paise($r['hotel_share']);
        }
        return ['rows' => $rows, 'gross' => self::fromPaise($gross), 'share' => self::fromPaise($share)];
    }

    /** Payout statements of the current hotel. */
    public static function hotelPayouts(): array
    {
        return DB::all('SELECT * FROM mkt_payouts WHERE hotel_id = :h ORDER BY period DESC', ['h' => Tenant::id()]);
    }

    // ================================================================== platform reports & payouts

    public static function overview(): array
    {
        $by = array_fill_keys(self::STATUSES, 0);
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM mkt_bookings GROUP BY status') as $r) {
            $by[$r['status']] = (int) $r['n'];
        }
        $money = DB::one(
            "SELECT COALESCE(SUM(bh.amount), 0) AS gross, COALESCE(SUM(bh.hotel_share), 0) AS hotels, COALESCE(SUM(bh.platform_share), 0) AS platform
             FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id WHERE b.paid_at IS NOT NULL AND bh.status IN ('approved','delivered')"
        ) ?? [];
        return [
            'by_status' => $by,
            'gross' => (string) ($money['gross'] ?? '0'), 'hotel_share' => (string) ($money['hotels'] ?? '0'), 'platform_share' => (string) ($money['platform'] ?? '0'),
            'pending_hotel' => (int) DB::value("SELECT COUNT(*) FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id WHERE bh.status = 'pending' AND b.status IN ('paid','scheduled','running')"),
            'unpaid_payouts' => (string) DB::value("SELECT COALESCE(SUM(amount), 0) FROM mkt_payouts WHERE status = 'unpaid'"),
            'advertisers' => (int) DB::value("SELECT COUNT(*) FROM mkt_advertisers WHERE status = 'active'"),
            'advertisers_pending' => (int) DB::value("SELECT COUNT(*) FROM mkt_advertisers WHERE status = 'pending'"),
            'hotels_selling' => (int) DB::value('SELECT COUNT(*) FROM mkt_hotel_settings WHERE enabled = 1'),
        ];
    }

    public static function platformBookings(string $status = '', string $q = ''): array
    {
        $w = ["b.status <> 'draft'"];
        $p = [];
        if (in_array($status, self::STATUSES, true)) {
            $w[] = 'b.status = :s';
            $p['s'] = $status;
        }
        if ($q !== '') {
            $w[] = '(b.number LIKE :q OR a.business_name LIKE :q2 OR b.title LIKE :q3)';
            $p += ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%'];
        }
        return DB::all(
            'SELECT b.*, a.business_name, (SELECT COUNT(*) FROM mkt_booking_hotels bh WHERE bh.booking_id = b.id) AS hotels
             FROM mkt_bookings b JOIN mkt_advertisers a ON a.id = b.advertiser_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.id DESC LIMIT 500',
            $p
        );
    }

    /**
     * Create / update the payout statements of a month: every hotel's approved lines of bookings paid
     * before the end of that month that are not on a statement yet (late payments roll into the next
     * open statement). Paid statements are never changed. Returns [hotel id => payout id].
     */
    public static function generatePayouts(string $period): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException(__('Choose a month.'));
        }
        $end = date('Y-m-d', strtotime($period . '-01 +1 month')) . ' 00:00:00';
        $out = [];
        $hotels = DB::column(
            "SELECT DISTINCT bh.hotel_id FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id
             WHERE bh.status IN ('approved','delivered') AND bh.payout_id IS NULL AND b.paid_at IS NOT NULL AND b.paid_at < :e",
            ['e' => $end]
        );
        foreach ($hotels as $hid) {
            $hid = (int) $hid;
            $po = DB::one('SELECT * FROM mkt_payouts WHERE hotel_id = :h AND period = :p', ['h' => $hid, 'p' => $period]);
            if ($po && $po['status'] === 'paid') {
                continue;
            }
            $pid = $po ? (int) $po['id'] : DB::insert('mkt_payouts', ['hotel_id' => $hid, 'period' => $period, 'created_at' => now()]);
            DB::query(
                "UPDATE mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id SET bh.payout_id = :pid
                 WHERE bh.hotel_id = :h AND bh.status IN ('approved','delivered') AND bh.payout_id IS NULL AND b.paid_at IS NOT NULL AND b.paid_at < :e",
                ['pid' => $pid, 'h' => $hid, 'e' => $end]
            );
            self::recomputePayout($pid);
            $out[$hid] = $pid;
        }
        return $out;
    }

    /** Recalculate an unpaid statement from its lines (lines no longer approved are released). */
    public static function recomputePayout(int $payoutId): void
    {
        $po = DB::one('SELECT * FROM mkt_payouts WHERE id = :id', ['id' => $payoutId]);
        if (!$po || $po['status'] === 'paid') {
            return;
        }
        DB::query("UPDATE mkt_booking_hotels SET payout_id = NULL WHERE payout_id = :p AND status NOT IN ('approved','delivered')", ['p' => $payoutId]);
        $s = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS gross, COALESCE(SUM(hotel_share), 0) AS share FROM mkt_booking_hotels WHERE payout_id = :p AND hotel_id = :h',
            ['p' => $payoutId, 'h' => (int) $po['hotel_id']]);
        DB::query('UPDATE mkt_payouts SET lines_count = :n, gross = :g, amount = :a WHERE id = :id', [
            'n' => (int) $s['n'], 'g' => (string) $s['gross'], 'a' => (string) $s['share'], 'id' => $payoutId,
        ]);
    }

    public static function markPayoutPaid(int $payoutId, string $ref, ?string $paidAt = null): void
    {
        $po = DB::one('SELECT * FROM mkt_payouts WHERE id = :id', ['id' => $payoutId]);
        if (!$po || $po['status'] === 'paid') {
            throw new RuntimeException(__('This statement is already paid.'));
        }
        if (trim($ref) === '') {
            throw new RuntimeException(__('Enter the payment reference (UTR / transaction id).'));
        }
        self::recomputePayout($payoutId);
        DB::query("UPDATE mkt_payouts SET status = 'paid', payment_ref = :r, paid_at = :t WHERE id = :id", [
            'r' => mb_substr(trim($ref), 0, 120), 't' => $paidAt && strtotime($paidAt) ? date('Y-m-d H:i:s', (int) strtotime($paidAt)) : now(), 'id' => $payoutId,
        ]);
        self::notifyHotel((int) $po['hotel_id'], __('Marketplace payout :p paid', ['p' => $po['period']]), self::money($po['amount']) . ' — ' . trim($ref));
    }

    public static function payouts(string $period = ''): array
    {
        $p = [];
        $w = '1=1';
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $w = 'p.period = :p';
            $p['p'] = $period;
        }
        return DB::all("SELECT p.*, h.name AS hotel_name, h.city FROM mkt_payouts p JOIN hotels h ON h.id = p.hotel_id WHERE $w ORDER BY p.period DESC, h.name LIMIT 1000", $p);
    }

    /** Lines of one statement (hotel id given = must belong to that hotel). */
    public static function payoutLines(int $payoutId, ?int $hotelId = null): array
    {
        $p = ['p' => $payoutId];
        $w = 'bh.payout_id = :p';
        if ($hotelId !== null) {
            $w .= ' AND bh.hotel_id = :h';
            $p['h'] = $hotelId;
        }
        return DB::all(
            "SELECT bh.*, b.number, b.title, b.start_date, b.end_date, b.paid_at, a.business_name
             FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id JOIN mkt_advertisers a ON a.id = b.advertiser_id
             WHERE $w ORDER BY b.paid_at",
            $p
        );
    }

    // ================================================================== periodic work (MarketplaceTask)

    /**
     * - unpaid orders (submitted / awaiting payment) past expires_at → cancelled ("payment not received")
     * - paid orders: hotel lines still pending after the end date → rejected (refund)
     * - CPM lines that delivered the ordered impressions → campaign paused, line delivered
     * - status by date: scheduled → running → completed
     */
    public static function housekeeping(?int $now = null): array
    {
        $now ??= time();
        $res = ['expired' => 0, 'delivered' => 0, 'updated' => 0];
        foreach (DB::all("SELECT id FROM mkt_bookings WHERE status IN ('submitted','awaiting_payment') AND expires_at IS NOT NULL AND expires_at < :n", ['n' => date('Y-m-d H:i:s', $now)]) as $b) {
            DB::query("UPDATE mkt_booking_hotels SET status = 'cancelled' WHERE booking_id = :b AND status = 'pending'", ['b' => (int) $b['id']]);
            DB::update('mkt_bookings', ['status' => 'cancelled', 'status_reason' => __('Payment not received in time.')], 'id = :id', ['id' => (int) $b['id']]);
            self::event((int) $b['id'], 'cancelled', __('Payment not received in time.'), 'system');
            self::notifyAdvertiser((int) $b['id'], __('Order cancelled'), __('Payment not received in time.'));
            $res['expired']++;
        }
        $today = date('Y-m-d', $now);
        foreach (DB::all("SELECT bh.* FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id WHERE bh.status = 'pending' AND b.status IN ('paid','scheduled','running') AND b.end_date < :t", ['t' => $today]) as $l) {
            self::rejectLine((int) $l['id'], (int) $l['hotel_id'], __('Not approved in time.'), null, 'system');
        }
        foreach (DB::all("SELECT bh.*, b.start_date, b.end_date FROM mkt_booking_hotels bh JOIN mkt_bookings b ON b.id = bh.booking_id
                          WHERE bh.status = 'approved' AND bh.impressions IS NOT NULL AND bh.campaign_id IS NOT NULL AND b.status IN ('running','scheduled')") as $l) {
            $got = Tenant::run((int) $l['hotel_id'], fn () => Ads::report([(int) $l['campaign_id']], (string) $l['start_date'], min((string) $l['end_date'], $today))['totals']['impressions']);
            if ($got >= (int) $l['impressions']) {
                DB::query("UPDATE mkt_booking_hotels SET status = 'delivered' WHERE id = :id", ['id' => (int) $l['id']]);
                Tenant::run((int) $l['hotel_id'], fn () => Ads::setStatus((int) $l['campaign_id'], 'paused'));
                self::event((int) $l['booking_id'], 'delivered', __(':n impressions delivered', ['n' => $got]), 'system', (int) $l['hotel_id']);
                $res['delivered']++;
            }
        }
        foreach (DB::column("SELECT id FROM mkt_bookings WHERE status IN ('paid','scheduled','running')") as $bid) {
            $before = (string) DB::value('SELECT status FROM mkt_bookings WHERE id = :id', ['id' => (int) $bid]);
            if (self::refreshStatus((int) $bid) !== $before) {
                $res['updated']++;
            }
        }
        return $res;
    }

    // ================================================================== notifications (never fatal)

    private static function notifyAdvertiser(int $bookingId, string $subject, string $message): void
    {
        try {
            $b = self::findBooking($bookingId);
            if ($b) {
                $brand = Branding::get(0)['product'];
                Notifier::email((string) $b['advertiser_email'], $brand . ': ' . $subject,
                    $b['contact_name'] . ",\n\n" . $message . "\n\n" . base_url('advertise/booking.php?id=' . $bookingId), (string) Settings::platform('platform_from_email', ''), $brand);
            }
        } catch (Throwable $e) {
            Logger::error('Marketplace notify advertiser: ' . $e->getMessage());
        }
    }

    private static function notifyHotel(int $hotelId, string $subject, string $message): void
    {
        try {
            Tenant::run($hotelId, static fn () => Notifier::send($subject, $message . "\n" . admin_url('marketplace.php')));
        } catch (Throwable $e) {
            Logger::error('Marketplace notify hotel: ' . $e->getMessage());
        }
    }

    private static function notifyPlatform(string $subject, string $message): void
    {
        try {
            $to = trim((string) Settings::platform('platform_notify_email', ''));
            if ($to !== '') {
                Notifier::email($to, $subject, $message . "\n" . admin_url('platform_marketplace.php'), (string) Settings::platform('platform_from_email', ''), Branding::get(0)['product']);
            }
        } catch (Throwable $e) {
            Logger::error('Marketplace notify platform: ' . $e->getMessage());
        }
    }

    // ================================================================== payment instructions

    /** upi://pay URI for a booking (null when no UPI ID is configured or nothing to pay). */
    public static function upiUri(array $b): ?string
    {
        $upi = trim(self::setting('platform_mkt_upi_id'));
        if ($upi === '' || self::paise($b['total']) <= 0) {
            return null;
        }
        $name = trim(self::setting('platform_mkt_upi_name')) ?: Branding::get(0)['product'];
        return 'upi://pay?' . http_build_query([
            'pa' => $upi, 'pn' => $name, 'am' => self::fromPaise(self::paise($b['total'])), 'tn' => (string) ($b['number'] ?: 'AD-' . $b['id']), 'cu' => 'INR',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function validUpi(string $upi): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._\-]{2,64}@[A-Za-z][A-Za-z0-9.\-]{1,63}$/', $upi);
    }
}
