<?php
declare(strict_types=1);

/** Platform: hotels (tenants), plans and resellers management (#18 #19 #22). */
final class Hotels
{
    public const MODULES = [
        'guests' => 'Guests / front desk', 'services' => 'Room service & requests', 'ads' => 'Advertising',
        'analytics' => 'Analytics', 'templates' => 'Template library', 'pwa' => 'Mobile app & push', 'support' => 'Support tools',
    ];

    public static function find(int $id): ?array
    {
        return DB::one(
            'SELECT h.*, p.name AS plan_name, p.price_per_tv_month, p.max_tvs AS plan_max_tvs, r.name AS reseller_name
             FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id LEFT JOIN resellers r ON r.id = h.reseller_id
             WHERE h.id = :id',
            ['id' => $id]
        );
    }

    /** Hotels with TV / room counts. $resellerId limits to one reseller's hotels. */
    public static function all(?int $resellerId = null, string $q = '', string $status = ''): array
    {
        $where = [];
        $p = [];
        if ($resellerId !== null) {
            $where[] = 'h.reseller_id = :rid';
            $p['rid'] = $resellerId;
        }
        if ($q !== '') {
            $where[] = '(h.name LIKE :q1 OR h.slug LIKE :q2 OR h.city LIKE :q3 OR h.contact_email LIKE :q4)';
            $p += ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
        }
        if (in_array($status, Tenant::STATUSES, true)) {
            $where[] = 'h.status = :st';
            $p['st'] = $status;
        }
        return DB::all(
            'SELECT h.*, p.name AS plan_name, p.price_per_tv_month, p.max_tvs AS plan_max_tvs, r.name AS reseller_name,
                (SELECT COUNT(*) FROM devices d WHERE d.hotel_id = h.id AND d.is_revoked = 0 AND d.room_id IS NOT NULL) AS tv_count,
                (SELECT COUNT(*) FROM devices d WHERE d.hotel_id = h.id AND d.is_revoked = 0 AND d.room_id IS NOT NULL AND d.status = \'online\') AS tv_online,
                (SELECT COUNT(*) FROM rooms rm WHERE rm.hotel_id = h.id) AS room_count,
                (SELECT COUNT(*) FROM invoices i WHERE i.hotel_id = h.id AND i.status = \'unpaid\') AS unpaid_invoices
             FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id LEFT JOIN resellers r ON r.id = h.reseller_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY h.name',
            $p
        );
    }

    public static function plans(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY price_per_tv_month, name');
    }

    public static function resellers(): array
    {
        return DB::all('SELECT * FROM resellers ORDER BY name');
    }

    /** Unique slug from a name. */
    public static function uniqueSlug(string $name, int $exceptId = 0): string
    {
        $base = slugify($name);
        $slug = $base;
        for ($i = 2; DB::value('SELECT id FROM hotels WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $exceptId]); $i++) {
            $slug = substr($base, 0, 55) . '-' . $i;
        }
        return $slug;
    }

    /** Unique registration key (16 hex chars, upper case). */
    public static function newRegistrationKey(): string
    {
        do {
            $key = strtoupper(random_token(8));
        } while (DB::value('SELECT id FROM hotels WHERE registration_key = :k', ['k' => $key]));
        return $key;
    }

    /**
     * Validate hotel form input. $byReseller = limited fields (no status, expiry, TV limit, reseller).
     * Returns [data, errors].
     */
    public static function validate(array $in, ?array $existing = null, bool $byReseller = false): array
    {
        $errors = [];
        $s = fn (string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $name = $s('name', 120);
        if ($name === '') {
            $errors[] = __('Business name is required.');
        }
        $email = $s('contact_email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Enter a valid email address.');
        }
        $planId = (int) ($in['plan_id'] ?? 0);
        if ($planId && !DB::value('SELECT id FROM plans WHERE id = :id', ['id' => $planId])) {
            $planId = 0;
        }
        // 2.5 security review: "no plan" means every feature, unlimited and not billed, and inactive plans are
        // drafts / retired. A reseller may choose an active plan or keep the customer's current one.
        $currentPlan = $existing ? (int) ($existing['plan_id'] ?? 0) : 0;
        if ($byReseller && (!$existing || $planId !== $currentPlan)) {
            if (!$planId) {
                $errors[] = __('Choose a plan.');
            } elseif (!DB::value('SELECT id FROM plans WHERE id = :id AND is_active = 1', ['id' => $planId])) {
                $errors[] = __('This plan is not available. Choose another plan.');
                $planId = $currentPlan;
            }
        }
        $color = $s('brand_color', 7);
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = __('Colour must look like #7B1FA2.');
        }
        $data = [
            'name' => $name,
            'plan_id' => $planId ?: null,
            'contact_name' => $s('contact_name', 120) ?: null,
            'contact_email' => $email ?: null,
            'contact_phone' => $s('contact_phone', 40) ?: null,
            'address' => $s('address', 500) ?: null,
            'city' => $s('city', 80) ?: null,
            'gstin' => strtoupper($s('gstin', 20)) ?: null,
            'brand_name' => $s('brand_name', 120) ?: null,
            'brand_color' => $color !== '' ? strtoupper($color) : null,
            'notes' => $s('notes', 1000) ?: null,
        ];
        if (!$byReseller) {
            $max = trim((string) ($in['max_tvs'] ?? ''));
            $data['max_tvs'] = $max === '' ? null : max(0, (int) $max);
            $exp = trim((string) ($in['expires_at'] ?? ''));
            $ts = $exp !== '' ? strtotime($exp . (strlen($exp) === 10 ? ' 23:59:59' : '')) : null;
            if ($exp !== '' && !$ts) {
                $errors[] = __('Invalid expiry date.');
            }
            $data['expires_at'] = $ts ? date('Y-m-d H:i:s', $ts) : null;
            $rid = (int) ($in['reseller_id'] ?? 0);
            $data['reseller_id'] = $rid && DB::value('SELECT id FROM resellers WHERE id = :id', ['id' => $rid]) ? $rid : null;
            if (isset($in['status']) && in_array($in['status'], Tenant::STATUSES, true)) {
                $data['status'] = $in['status'];
            }
        }
        return [$data, $errors];
    }

    /** Validate the "first super admin" fields. Returns [admin|null, errors]. */
    public static function validateAdmin(array $in, bool $required): array
    {
        $username = trim((string) ($in['admin_username'] ?? ''));
        $email = trim((string) ($in['admin_email'] ?? ''));
        $pass = (string) ($in['admin_password'] ?? '');
        if ($username === '' && $email === '' && $pass === '' && !$required) {
            return [null, []];
        }
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $errors[] = __('Username: 3–50 characters (letters, numbers, _ . -).');
        } elseif (DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username])) {
            $errors[] = __('Username :u is already taken.', ['u' => $username]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Enter a valid email address.');
        } elseif (DB::value('SELECT id FROM users WHERE email = :e', ['e' => $email])) {
            $errors[] = __('Email :e is already used by another account.', ['e' => $email]);
        }
        if ($pe = Auth::passwordError($pass)) {
            $errors[] = $pe;
        }
        return [['username' => $username, 'email' => $email, 'password' => $pass, 'full_name' => trim((string) ($in['admin_name'] ?? '')) ?: $username], $errors];
    }

    /** Create a hotel (+ default settings, registration key, optional first super admin). Returns id. */
    public static function create(array $data, ?array $admin = null, ?int $userId = null): int
    {
        return DB::transaction(function () use ($data, $admin, $userId) {
            $key = self::newRegistrationKey();
            $id = DB::insert('hotels', $data + [
                'slug' => self::uniqueSlug($data['name']),
                'status' => 'active',
                'registration_key' => $key,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            $tz = (string) Config::get('timezone', 'Asia/Kolkata');
            foreach (['hotel_name' => $data['name'], 'registration_key' => $key, 'timezone' => $tz, 'content_version' => '1'] as $k => $v) {
                DB::query('INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, :k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['h' => $id, 'k' => $k, 'v' => $v]);
            }
            if ($admin) {
                self::createHotelUser($id, $admin, 'super_admin');
            }
            Settings::flush();
            return $id;
        });
    }

    public static function createHotelUser(int $hotelId, array $admin, string $role = 'super_admin'): int
    {
        return DB::insert('users', [
            'hotel_id' => $hotelId,
            'username' => $admin['username'],
            'email' => $admin['email'],
            'full_name' => $admin['full_name'] ?? $admin['username'],
            'password_hash' => Auth::hash($admin['password']),
            'role' => in_array($role, Auth::HOTEL_ROLES, true) ? $role : 'staff',
            'language' => 'en',
            'is_active' => 1,
            'created_at' => now(),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        if (isset($data['name'])) {
            $data['slug'] = self::uniqueSlug($data['name'], $id);
        }
        DB::update('hotels', $data, 'id = :id', ['id' => $id]);
        if (isset($data['name'])) {
            DB::query("INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, 'hotel_name', :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['h' => $id, 'v' => $data['name']]);
        }
        self::changed($id);
    }

    /** Suspend / activate / expire a hotel. TVs switch to / from the "service paused" screen on their next poll. */
    public static function setStatus(int $id, string $status, ?string $reason = null): void
    {
        if (!in_array($status, Tenant::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        DB::update('hotels', ['status' => $status, 'suspend_reason' => $status === 'active' ? null : ($reason ?: 'manual')], 'id = :id', ['id' => $id]);
        self::changed($id);
        Logger::write('platform', 'info', 'Hotel status changed', ['hotel' => $id, 'status' => $status, 'reason' => $reason]);
    }

    /**
     * 2.6: delete a customer with everything it owns (Super Admin console → Customer 360 → Settings).
     * Every table with a hotel_id column is cleared for that customer (users, screens, TVs, content,
     * logs, invoices …), then the hotels row. Returns rows removed per table. Uploaded files stay on
     * disk (docs/modules/panels.md, open point).
     */
    public static function delete(int $id): array
    {
        if ($id <= 0 || !DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $id])) {
            throw new InvalidArgumentException(__('Customer not found.'));
        }
        if ((int) DB::value('SELECT COUNT(*) FROM hotels') <= 1) {
            throw new RuntimeException(__('The last customer cannot be deleted.'));
        }
        $tables = array_map('strval', array_column(DB::all(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'hotel_id' AND TABLE_NAME <> 'hotels' ORDER BY TABLE_NAME"
        ), 't'));
        $pdo = DB::pdo();
        $removed = [];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            DB::transaction(static function () use ($tables, $id, &$removed): void {
                foreach ($tables as $t) {
                    if (!preg_match('/^[a-z0-9_]+$/i', $t)) {
                        continue;
                    }
                    if ($t === 'licenses') {
                        DB::query('UPDATE licenses SET hotel_id = NULL WHERE hotel_id = :h', ['h' => $id]);
                        continue;
                    }
                    $n = DB::query("DELETE FROM `$t` WHERE hotel_id = :h", ['h' => $id])->rowCount();
                    if ($n) {
                        $removed[$t] = $n;
                    }
                }
                DB::query('DELETE FROM hotels WHERE id = :h', ['h' => $id]);
                $removed['hotels'] = 1;
            });
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        Tenant::forget($id);
        Branding::flush();
        Settings::flush();
        Cache::clear();
        Logger::write('platform', 'warning', 'Customer deleted', ['hotel' => $id, 'rows' => $removed]);
        return $removed;
    }

    /** Hotel row changed: refresh caches and the content of its TVs. */
    public static function changed(int $id): void
    {
        Tenant::forget($id);
        Branding::flush();
        Settings::flush();
        Tenant::run($id, static function (): void {
            Settings::bumpContentVersion();
        });
    }

    /** Can this reseller create another hotel (allowance)? */
    public static function resellerCanCreate(int $resellerId): bool
    {
        $r = DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $resellerId]);
        if (!$r || $r['status'] !== 'active') {
            return false;
        }
        if ($r['max_hotels'] === null) {
            return true;
        }
        return (int) DB::value('SELECT COUNT(*) FROM hotels WHERE reseller_id = :r', ['r' => $resellerId]) < (int) $r['max_hotels'];
    }

    /**
     * Validate a plan form. Returns [data, errors]. Features (2.5, core/Features.php): features[] = feature
     * keys; every optional feature ticked = NULL (everything, also features added later).
     */
    public static function validatePlan(array $in): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') {
            $errors[] = __('Plan name is required.');
        }
        $rawPrice = str_replace(',', '', is_string($in['price_per_tv_month'] ?? null) || is_numeric($in['price_per_tv_month'] ?? null) ? (string) $in['price_per_tv_month'] : '0');
        $price = is_numeric($rawPrice) ? (float) $rawPrice : -1.0;
        if ($price < 0 || $price > 1000000) {
            $errors[] = __('Invalid price.');
        }
        $maxTvs = Features::limitInput($in['max_tvs'] ?? '', __('Max TVs'), $errors);
        $maxUsers = Features::limitInput($in['max_users'] ?? '', __('Max users'), $errors, 100000);
        $storage = Features::limitInput($in['storage_mb'] ?? '', __('Storage (MB)'), $errors);
        $keys = array_values(array_filter((array) ($in['features'] ?? []), 'is_string'));
        $unknown = array_diff($keys, array_keys(Features::all()));
        if ($unknown) {
            $errors[] = __('Unknown feature: :f', ['f' => implode(', ', array_slice($unknown, 0, 5))]);
        }
        $keys = array_values(array_intersect(Features::optionalKeys(), $keys));
        if (!$keys && !$errors) {
            $errors[] = __('Choose at least one feature.');
        }
        return [[
            'name' => $name,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
            'price_per_tv_month' => round(max(0, $price), 2),
            'max_tvs' => $maxTvs,
            'max_users' => $maxUsers,
            'storage_mb' => $storage,
            'features' => Features::encodePlanKeys($keys),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    /** Validate a reseller form. Returns [data, errors]. */
    public static function validateReseller(array $in): array
    {
        $errors = [];
        $s = fn (string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $name = $s('name', 150);
        if ($name === '') {
            $errors[] = __('Reseller name is required.');
        }
        $email = $s('email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Enter a valid email address.');
        }
        $pct = (float) ($in['commission_percent'] ?? 0);
        if ($pct < 0 || $pct > 100) {
            $errors[] = __('Commission must be between 0 and 100 %.');
        }
        $color = $s('brand_color', 7);
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = __('Colour must look like #7B1FA2.');
        }
        $max = trim((string) ($in['max_hotels'] ?? ''));
        return [[
            'name' => $name,
            'contact_name' => $s('contact_name', 120) ?: null,
            'email' => $email ?: null,
            'phone' => $s('phone', 40) ?: null,
            'commission_percent' => round($pct, 2),
            'max_hotels' => $max === '' ? null : max(0, (int) $max),
            'status' => ($in['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
            'brand_name' => $s('brand_name', 120) ?: null,
            'brand_color' => $color !== '' ? strtoupper($color) : null,
            'support_phone' => $s('support_phone', 40) ?: null,
            'support_email' => $s('support_email', 190) ?: null,
            'notes' => $s('notes', 1000) ?: null,
        ], $errors];
    }

    /** Status badge HTML. */
    public static function statusBadge(string $status): string
    {
        $cls = match ($status) {
            'active' => 'text-bg-success',
            'suspended' => 'text-bg-danger',
            'expired' => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
        $label = match ($status) {
            'active' => __('Active'),
            'suspended' => __('Suspended'),
            'expired' => __('Expired'),
            default => $status,
        };
        return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
    }
}
