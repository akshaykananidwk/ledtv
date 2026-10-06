<?php
declare(strict_types=1);

/**
 * Hotel chains (#20): one owner, many hotels.
 *
 *  - hotel_chains (+ hotels.chain_id): created by the platform admin, or by a reseller for its own hotels.
 *  - Who may use a chain (userChainIds):
 *      platform_admin → every chain; reseller → chains with its reseller_id;
 *      chain_admin (users.chain_id, hotel_id NULL) → its chain;
 *      hotel super_admin with users.chain_id → that chain, only while their own hotel is in it.
 *  - Every cross-hotel action takes explicit hotel ids and re-checks membership (assertHotels):
 *    hotels.chain_id must be the chain (reseller: and hotels.reseller_id theirs). A foreign id is logged
 *    and refused with 404 (Tenant::deny; CLI throws TenantException). Per-hotel work then runs inside
 *    Tenant::run($hotelId), so the normal tenant scoping (DB safety net, Tenant::find) applies.
 *  - Chain content library (chain_content_items / chain_playlists) is copied into hotels on publish;
 *    chain_publications links chain item → per-hotel id; re-publishing updates the copies.
 *  - Write actions skip hotels that are suspended / expired (read-only rule), reported as "skipped".
 */
final class Chains
{
    /** Settings keys a chain template may push, by group (validated like admin/settings.php). */
    public const TEMPLATE_GROUPS = [
        'ticker' => ['ticker_text', 'ticker_speed', 'ticker_bg_color', 'ticker_text_color'],
        'overlay' => ['overlay_clock', 'overlay_clock_format', 'overlay_logo', 'overlay_weather'],
        'branding' => ['brand_name', 'brand_color', 'brand_logo'],
    ];
    public const CLOCK_FORMATS = ['hh:mm a', 'hh:mm:ss a', 'HH:mm', 'HH:mm:ss', 'EEE hh:mm a', 'dd/MM hh:mm a'];
    public const BROADCAST_KINDS = ['push', 'schedule', 'emergency', 'emergency_stop'];

    private static array $chainCache = [];
    /** User the checks run for outside a web request (CLI tasks, tests); null = Auth::user(). */
    private static ?array $actor = null;

    /** Run chain checks as $user (CLI / tests). Pass null to go back to the session user. */
    public static function actAs(?array $user): void
    {
        self::$actor = $user;
        self::$chainCache = [];
    }

    private static function actor(): ?array
    {
        return self::$actor ?? Auth::user();
    }

    // ------------------------------------------------------------------ lookups

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return DB::one(
            'SELECT c.*, r.name AS reseller_name FROM hotel_chains c LEFT JOIN resellers r ON r.id = c.reseller_id WHERE c.id = :id',
            ['id' => $id]
        );
    }

    /** Chains with hotel / admin counts; $ids limits the list (null = all). */
    public static function all(?array $ids = null): array
    {
        if ($ids !== null && !$ids) {
            return [];
        }
        $p = [];
        $where = '';
        if ($ids !== null) {
            [$in, $p] = DB::in(array_map('intval', $ids), 'c');
            $where = " WHERE c.id IN $in";
        }
        return DB::all(
            "SELECT c.*, r.name AS reseller_name,
                (SELECT COUNT(*) FROM hotels h WHERE h.chain_id = c.id) AS hotel_count,
                (SELECT COUNT(*) FROM users u WHERE u.chain_id = c.id AND u.role = 'chain_admin') AS admin_count
             FROM hotel_chains c LEFT JOIN resellers r ON r.id = c.reseller_id$where ORDER BY c.name",
            $p
        );
    }

    /** Hotels of a chain (with plan). A reseller only ever gets its own hotels. */
    public static function hotels(int $chainId): array
    {
        $u = self::actor();
        $p = ['c' => $chainId];
        $extra = '';
        if ($u && $u['role'] === 'reseller') {
            $extra = ' AND h.reseller_id = :r';
            $p['r'] = (int) $u['reseller_id'];
        }
        return DB::all(
            'SELECT h.*, p.name AS plan_name FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id WHERE h.chain_id = :c' . $extra . ' ORDER BY h.name',
            $p
        );
    }

    public static function hotelIds(int $chainId): array
    {
        return array_map('intval', DB::column('SELECT id FROM hotels WHERE chain_id = :c ORDER BY name', ['c' => $chainId]));
    }

    // ------------------------------------------------------------------ access

    /** Chain ids the user may use (dashboard, library, broadcast, enter hotels). */
    public static function userChainIds(?array $u = null): array
    {
        $u ??= self::actor();
        if (!$u) {
            return [];
        }
        $key = (int) $u['id'];
        if (isset(self::$chainCache[$key])) {
            return self::$chainCache[$key];
        }
        $ids = [];
        try {
            switch ($u['role']) {
                case 'platform_admin':
                    $ids = DB::column('SELECT id FROM hotel_chains ORDER BY name');
                    break;
                case 'reseller':
                    if (!empty($u['reseller_id'])) {
                        $ids = DB::column('SELECT id FROM hotel_chains WHERE reseller_id = :r ORDER BY name', ['r' => (int) $u['reseller_id']]);
                    }
                    break;
                case 'chain_admin':
                    if (!empty($u['chain_id']) && DB::value('SELECT id FROM hotel_chains WHERE id = :c', ['c' => (int) $u['chain_id']])) {
                        $ids = [(int) $u['chain_id']];
                    }
                    break;
                case 'super_admin':
                    if (self::superAdminGrant($u)) {
                        $ids = [(int) $u['chain_id']];
                    }
                    break;
            }
        } catch (PDOException) {
            $ids = []; // before migration 009
        }
        return self::$chainCache[$key] = array_values(array_map('intval', $ids));
    }

    public static function flushCache(): void
    {
        self::$chainCache = [];
    }

    /** Hotel super admin with chain access: users.chain_id set and their own hotel is in that chain. */
    private static function superAdminGrant(array $u): bool
    {
        return ($u['role'] ?? '') === 'super_admin' && !empty($u['chain_id']) && !empty($u['hotel_id'])
            && (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => (int) $u['hotel_id']]) === (int) $u['chain_id'];
    }

    /**
     * Platform feature switch (platform setting feature_chains, default off): when off the chain
     * pages / menus / AJAX answer 404, chain admins and chain grants cannot enter hotels and the
     * platform cannot create chains. Existing chain data stays untouched.
     */
    public static function enabled(): bool
    {
        try {
            return (string) Settings::platform('feature_chains', '0') === '1';
        } catch (Throwable) {
            return false;
        }
    }

    /** 404 (not found) when the chains feature is switched off. */
    public static function requireEnabled(): void
    {
        if (self::enabled()) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            throw new TenantException('Hotel chains are switched off');
        }
        if (!headers_sent()) {
            http_response_code(404);
        }
        if (Auth::isAjax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_out(['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]);
            exit;
        }
        echo '<!DOCTYPE html><meta charset="utf-8"><title>404</title><body style="font-family:sans-serif;padding:40px">'
            . '<h1>' . e(__('Not found')) . '</h1><p>' . e(__('This feature is not available.')) . '</p>'
            . '<p><a href="' . e(admin_url(Auth::user() ? Auth::homePage() : 'login.php')) . '">' . e(__('Back to dashboard')) . '</a></p></body>';
        exit;
    }

    /** chain_admin, or hotel super admin with a valid chain grant. */
    public static function isChainUser(?array $u = null): bool
    {
        $u ??= self::actor();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'chain_admin') {
            return true;
        }
        try {
            return self::superAdminGrant($u);
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Used by Auth (no Auth::user() call here — runs while the session user is being resolved):
     * may a chain user enter $hotelId? chain_admin: hotel in their chain; super_admin with a grant:
     * own hotel in the chain and target hotel in the same chain. Other roles: false.
     */
    public static function userCanEnter(array $u, int $hotelId): bool
    {
        $chain = (int) ($u['chain_id'] ?? 0);
        if ($chain <= 0 || $hotelId <= 0 || !self::enabled()) {
            return false;
        }
        try {
            if ($u['role'] === 'chain_admin') {
                return (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => $hotelId]) === $chain;
            }
            if ($u['role'] === 'super_admin' && self::superAdminGrant($u)) {
                return (int) DB::value('SELECT chain_id FROM hotels WHERE id = :h', ['h' => $hotelId]) === $chain;
            }
        } catch (PDOException) {
        }
        return false;
    }

    public static function canAccessChain(int $chainId): bool
    {
        return $chainId > 0 && in_array($chainId, self::userChainIds(), true);
    }

    /** Create chains / assign hotels / add chain admins: platform admin (all) or reseller (own chains). */
    public static function canAdminister(?int $chainId = null): bool
    {
        $u = self::actor();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'platform_admin') {
            return $chainId === null || (bool) self::find($chainId);
        }
        if ($u['role'] === 'reseller' && !empty($u['reseller_id'])) {
            if ($chainId === null) {
                return true;
            }
            $c = self::find($chainId);
            return $c && (int) $c['reseller_id'] === (int) $u['reseller_id'];
        }
        return false;
    }

    /** May the current user act on $hotelId through $chainId? (membership re-checked every time) */
    public static function hotelAllowed(int $chainId, int $hotelId): bool
    {
        if (!self::canAccessChain($chainId) || $hotelId <= 0) {
            return false;
        }
        $h = DB::one('SELECT chain_id, reseller_id FROM hotels WHERE id = :h', ['h' => $hotelId]);
        if (!$h || (int) $h['chain_id'] !== $chainId) {
            return false;
        }
        $u = self::actor();
        if ($u && $u['role'] === 'reseller') {
            return (int) $h['reseller_id'] === (int) $u['reseller_id'];
        }
        return true;
    }

    /** Validated hotel ids of the chain; any foreign id → logged + 404 (CLI: TenantException). */
    public static function assertHotels(int $chainId, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) && strlen($id) < 10 ? (int) $id : 0);
            if ($id <= 0) {
                continue;
            }
            if (!self::hotelAllowed($chainId, $id)) {
                self::deny("chain $chainId hotel $id");
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }

    /** The chain the current user works on (param, else their first chain); 404 when not allowed. */
    public static function current(int $requested = 0): array
    {
        $ids = self::userChainIds();
        $id = $requested ?: ($ids[0] ?? 0);
        if (!$id || !in_array($id, $ids, true) || !($c = self::find($id))) {
            self::deny('chain ' . $id);
        }
        return $c;
    }

    /**
     * Guard for the chain pages (chain.php, chain_content.php, chain_broadcast.php): logged in, CSRF,
     * "leave hotel" from the entered-hotel banner, role gate, chain membership. Returns [user, chain].
     * The read-only rule of Auth::require() is not applied here: chain actions skip suspended hotels.
     */
    public static function page(): array
    {
        $user = Auth::user() ?? Auth::require();
        Csrf::check();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['op'] ?? '') === 'leave') {
            $back = Auth::backPage();
            Auth::leaveHotel();
            redirect(admin_url($back === 'chain.php' && self::userChainIds() ? 'chain.php' : Auth::homePage()));
        }
        self::requireEnabled();
        if (!Auth::can('chain.view')) {
            http_response_code(403);
            require HC_ROOT . '/admin/partials/forbidden.php';
            exit;
        }
        $chainId = isset($_GET['chain']) && is_string($_GET['chain']) && ctype_digit($_GET['chain']) ? (int) $_GET['chain']
            : (isset($_POST['chain']) && is_string($_POST['chain']) && ctype_digit($_POST['chain']) ? (int) $_POST['chain'] : 0);
        return [$user, self::current($chainId)];
    }

    public static function deny(string $what): never
    {
        Logger::write('security', 'warning', 'Chain access denied', ['what' => $what, 'user' => Auth::id()]);
        Tenant::deny($what);
    }

    // ------------------------------------------------------------------ chains CRUD (platform / reseller)

    /** Validate a chain form. $byReseller = no reseller field. Returns [data, errors]. */
    public static function validate(array $in, bool $byReseller = false): array
    {
        $errors = [];
        $s = static fn (string $k, int $max) => mb_substr(trim(is_string($in[$k] ?? null) ? $in[$k] : ''), 0, $max);
        $name = $s('name', 150);
        if ($name === '') {
            $errors[] = __('Chain name is required.');
        }
        $email = $s('owner_email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Enter a valid email address.');
        }
        $color = $s('brand_color', 7);
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = __('Colour must look like #7B1FA2.');
        }
        $data = [
            'name' => $name,
            'owner_name' => $s('owner_name', 120) ?: null,
            'owner_email' => $email ?: null,
            'owner_phone' => $s('owner_phone', 40) ?: null,
            'brand_name' => $s('brand_name', 120) ?: null,
            'brand_color' => $color !== '' ? strtoupper($color) : null,
            'notes' => $s('notes', 1000) ?: null,
        ];
        if (!$byReseller) {
            $rid = (int) ($in['reseller_id'] ?? 0);
            $data['reseller_id'] = $rid && DB::value('SELECT id FROM resellers WHERE id = :id', ['id' => $rid]) ? $rid : null;
        }
        return [$data, $errors];
    }

    public static function create(array $data, ?int $userId = null): int
    {
        $id = DB::insert('hotel_chains', $data + ['created_by' => $userId, 'created_at' => now()]);
        self::flushCache();
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        DB::update('hotel_chains', $data, 'id = :id', ['id' => $id]);
    }

    /** Delete a chain: hotels / users keep working (chain_id → NULL); chain admins are disabled. */
    public static function delete(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $admins = array_map('intval', DB::column("SELECT id FROM users WHERE chain_id = :c AND role = 'chain_admin'", ['c' => $id]));
            DB::query("UPDATE users SET is_active = 0 WHERE chain_id = :c AND role = 'chain_admin'", ['c' => $id]);
            foreach ($admins as $uid) {
                Auth::revokeUserSessions($uid);
            }
            DB::query('DELETE FROM hotel_chains WHERE id = :id', ['id' => $id]);
        });
        self::flushCache();
    }

    /** Hotels that may be added to a chain by the current user (not in another chain). */
    public static function assignableHotels(array $chain): array
    {
        $u = self::actor();
        $p = ['c' => (int) $chain['id']];
        $where = '(h.chain_id IS NULL OR h.chain_id = :c)';
        if ($u && $u['role'] === 'reseller') {
            $where .= ' AND h.reseller_id = :r';
            $p['r'] = (int) $u['reseller_id'];
        }
        return DB::all("SELECT h.id, h.name, h.city, h.chain_id FROM hotels h WHERE $where ORDER BY h.name", $p);
    }

    /** Add / remove a hotel (caller checked canAdminister). Throws InvalidArgumentException. */
    public static function assignHotel(int $chainId, int $hotelId, bool $add = true): void
    {
        $chain = self::find($chainId);
        $h = DB::one('SELECT id, chain_id, reseller_id FROM hotels WHERE id = :h', ['h' => $hotelId]);
        if (!$chain || !$h) {
            throw new InvalidArgumentException(__('Hotel not found.'));
        }
        $u = self::actor();
        if ($u && $u['role'] === 'reseller' && (int) $h['reseller_id'] !== (int) $u['reseller_id']) {
            self::deny("reseller assign hotel $hotelId");
        }
        if ($add) {
            if ($chain['reseller_id'] !== null && (int) $h['reseller_id'] !== (int) $chain['reseller_id']) {
                throw new InvalidArgumentException(__('A reseller\'s chain can only contain hotels of that reseller.'));
            }
            if ($h['chain_id'] !== null && (int) $h['chain_id'] !== $chainId) {
                throw new InvalidArgumentException(__('This hotel already belongs to another chain.'));
            }
            DB::query('UPDATE hotels SET chain_id = :c WHERE id = :h', ['c' => $chainId, 'h' => $hotelId]);
        } elseif ((int) $h['chain_id'] === $chainId) {
            DB::query('UPDATE hotels SET chain_id = NULL WHERE id = :h', ['h' => $hotelId]);
            DB::query('DELETE FROM chain_publications WHERE chain_id = :c AND hotel_id = :h', ['c' => $chainId, 'h' => $hotelId]);
        }
        Tenant::forget($hotelId);
        self::flushCache();
    }

    /** Create a chain admin login (hotel_id NULL). $admin from Hotels::validateAdmin(). */
    public static function createAdmin(int $chainId, array $admin): int
    {
        return DB::insert('users', [
            'hotel_id' => null,
            'chain_id' => $chainId,
            'username' => $admin['username'],
            'email' => $admin['email'],
            'full_name' => $admin['full_name'] ?? $admin['username'],
            'password_hash' => Auth::hash($admin['password']),
            'role' => 'chain_admin',
            'language' => 'en',
            'is_active' => 1,
            'created_at' => now(),
        ]);
    }

    /** Super admins of the chain's hotels (candidates for / holders of chain access). */
    public static function hotelSuperAdmins(int $chainId): array
    {
        return DB::all(
            "SELECT u.id, u.username, u.full_name, u.email, u.chain_id, u.is_active, h.name AS hotel_name
             FROM users u JOIN hotels h ON h.id = u.hotel_id
             WHERE h.chain_id = :c AND u.role = 'super_admin' ORDER BY h.name, u.username",
            ['c' => $chainId]
        );
    }

    /** Grant / revoke chain access for a super admin of a hotel in the chain. */
    public static function setSuperAdminAccess(int $chainId, int $userId, bool $grant): void
    {
        $ok = DB::value(
            "SELECT u.id FROM users u JOIN hotels h ON h.id = u.hotel_id WHERE u.id = :u AND u.role = 'super_admin' AND h.chain_id = :c",
            ['u' => $userId, 'c' => $chainId]
        );
        if (!$ok) {
            self::deny("chain $chainId grant user $userId");
        }
        DB::query('UPDATE users SET chain_id = :c WHERE id = :u', ['c' => $grant ? $chainId : null, 'u' => $userId]);
        self::flushCache();
    }

    public static function admins(int $chainId): array
    {
        return DB::all(
            "SELECT id, username, full_name, email, is_active, last_login_at FROM users WHERE chain_id = :c AND role = 'chain_admin' ORDER BY username",
            ['c' => $chainId]
        );
    }

    // ------------------------------------------------------------------ dashboard

    /** One row per hotel of the chain (current numbers) + totals. */
    public static function overview(int $chainId): array
    {
        $rows = [];
        foreach (self::hotels($chainId) as $h) {
            try {
                $rows[] = Tenant::run((int) $h['id'], static fn () => self::snapshot($h));
            } catch (Throwable $e) {
                Logger::error('chain overview failed for hotel ' . $h['id'] . ': ' . $e->getMessage());
                $rows[] = self::emptySnapshot($h) + ['error' => true];
            }
        }
        $sum = static fn (string $k) => array_sum(array_map(static fn ($r) => (int) ($r[$k] ?? 0), $rows));
        $rooms = $sum('rooms');
        $occRows = array_filter($rows, static fn ($r) => $r['occupied'] !== null);
        $fb = array_filter($rows, static fn ($r) => $r['feedback_avg'] !== null && $r['feedback_count'] > 0);
        $fbCount = array_sum(array_column($fb, 'feedback_count'));
        $totals = [
            'hotels' => count($rows),
            'tvs' => $sum('tvs'), 'online' => $sum('online'), 'offline' => $sum('offline'), 'rooms' => $rooms,
            'occupied' => $occRows ? array_sum(array_column($occRows, 'occupied')) : null,
            'occupancy' => $occRows && $rooms > 0 ? round(100 * array_sum(array_column($occRows, 'occupied')) / max(1, array_sum(array_column($occRows, 'rooms'))), 1) : null,
            'plays_today' => $sum('plays_today'), 'ad_impressions_today' => $sum('ad_impressions_today'),
            'open_orders' => $sum('open_orders'), 'open_requests' => $sum('open_requests'),
            'feedback_avg' => $fbCount > 0 ? round(array_sum(array_map(static fn ($r) => $r['feedback_avg'] * $r['feedback_count'], $fb)) / $fbCount, 2) : null,
            'unpaid_invoices' => $sum('unpaid_invoices'), 'overdue_invoices' => $sum('overdue_invoices'),
            'not_active' => count(array_filter($rows, static fn ($r) => $r['state'] !== 'active')),
        ];
        return ['hotels' => $rows, 'totals' => $totals];
    }

    private static function emptySnapshot(array $h): array
    {
        return [
            'id' => (int) $h['id'], 'name' => (string) $h['name'], 'city' => (string) ($h['city'] ?? ''),
            'state' => (string) $h['status'], 'plan' => (string) ($h['plan_name'] ?? ''), 'expires_at' => $h['expires_at'] ?? null,
            'days_left' => null, 'tvs' => 0, 'online' => 0, 'offline' => 0, 'rooms' => 0, 'occupied' => null, 'occupancy' => null,
            'plays_today' => 0, 'uptime_today' => null, 'ad_impressions_today' => 0, 'open_orders' => 0, 'open_requests' => 0,
            'feedback_avg' => null, 'feedback_count' => 0, 'unpaid_invoices' => 0, 'overdue_invoices' => 0, 'invoice_status' => 'none',
        ];
    }

    /** Current numbers of the hotel in context (Tenant::run). */
    private static function snapshot(array $h): array
    {
        $hid = Tenant::id();
        $row = self::emptySnapshot($h);
        $row['state'] = Tenant::state($hid);
        if (!empty($h['expires_at'])) {
            $row['days_left'] = (int) floor(((int) strtotime((string) $h['expires_at']) - time()) / 86400);
        }
        foreach (DB::all('SELECT status, last_ping FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IS NOT NULL', ['h' => $hid]) as $d) {
            $row['tvs']++;
            DeviceManager::isOnline($d) ? $row['online']++ : $row['offline']++;
        }
        $row['rooms'] = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => $hid]);
        if (Analytics::tableExists('guest_stays')) {
            $row['occupied'] = (int) DB::value(
                'SELECT COUNT(DISTINCT room_id) FROM guest_stays WHERE hotel_id = :h AND room_id IS NOT NULL AND checkin_at <= :n AND checked_out_at IS NULL',
                ['h' => $hid, 'n' => now()]
            );
            $row['occupancy'] = $row['rooms'] > 0 ? round(100 * $row['occupied'] / $row['rooms'], 1) : 0.0;
        }
        $today = Analytics::today();
        $row['plays_today'] = (int) $today['plays'];
        $row['uptime_today'] = $today['uptime'];
        $row['ad_impressions_today'] = (int) $today['ad_impressions'];
        if (Analytics::tableExists('guest_orders')) {
            $row['open_orders'] = (int) DB::value("SELECT COUNT(*) FROM guest_orders WHERE hotel_id = :h AND status IN ('new','accepted','preparing')", ['h' => $hid]);
        }
        if (Analytics::tableExists('guest_requests')) {
            $row['open_requests'] = (int) DB::value("SELECT COUNT(*) FROM guest_requests WHERE hotel_id = :h AND status = 'open'", ['h' => $hid]);
        }
        if (Analytics::tableExists('guest_feedback')) {
            $fb = DB::one('SELECT COUNT(*) AS n, AVG(rating) AS a FROM guest_feedback WHERE hotel_id = :h AND created_at >= :f', ['h' => $hid, 'f' => date('Y-m-d H:i:s', strtotime('-30 days'))]);
            $row['feedback_count'] = (int) $fb['n'];
            $row['feedback_avg'] = $fb['a'] !== null ? round((float) $fb['a'], 2) : null;
        }
        $inv = DB::one(
            "SELECT SUM(status = 'unpaid') AS unpaid, SUM(status = 'unpaid' AND due_date < :d) AS overdue FROM invoices WHERE hotel_id = :h",
            ['h' => $hid, 'd' => date('Y-m-d')]
        );
        $row['unpaid_invoices'] = (int) ($inv['unpaid'] ?? 0);
        $row['overdue_invoices'] = (int) ($inv['overdue'] ?? 0);
        $row['invoice_status'] = $row['overdue_invoices'] > 0 ? 'overdue' : ($row['unpaid_invoices'] > 0 ? 'unpaid' : 'ok');
        return $row;
    }

    /**
     * Date-range comparison of hotels (Analytics functions run in each hotel's context):
     * plays, screen time, uptime %, hours ON, kWh, orders, requests, feedback, ad impressions, occupancy.
     * Returns ['hotels' => rows, 'totals' => row, 'days' => [Y-m-d…], 'series' => [hotelId => plays per day]].
     */
    public static function report(int $chainId, string $from, string $to, ?array $hotelIds = null): array
    {
        $hotels = self::hotels($chainId);
        if ($hotelIds !== null) {
            $hotelIds = self::assertHotels($chainId, $hotelIds);
            $hotels = array_values(array_filter($hotels, static fn ($h) => in_array((int) $h['id'], $hotelIds, true)));
        }
        $rows = [];
        $series = [];
        $tot = ['online' => 0, 'period' => 0];
        foreach ($hotels as $h) {
            $hid = (int) $h['id'];
            try {
                $r = Tenant::run($hid, static function () use ($h, $from, $to): array {
                    $days = Analytics::byDay($from, $to);
                    $tvs = Analytics::byTv($from, $to);
                    $sum = Analytics::tvSummary($tvs);
                    $gs = Analytics::guestServices($from, $to);
                    $occ = Analytics::occupancy($from, $to);
                    return [
                        'id' => (int) $h['id'], 'name' => (string) $h['name'],
                        'plays' => array_sum(array_column($days, 'plays')),
                        'seconds' => array_sum(array_column($days, 'seconds')),
                        'ad_impressions' => array_sum(array_column($days, 'ad_impressions')),
                        'tvs' => $sum['tvs'], 'uptime' => $sum['uptime'], 'hours_on' => $sum['hours_on'], 'kwh' => $sum['kwh'],
                        'orders' => $gs['orders']['count'] ?? null,
                        'revenue' => $gs['orders']['revenue'] ?? null,
                        'requests' => isset($gs['requests']) && is_array($gs['requests']) ? array_sum(array_column($gs['requests'], 'count')) : null,
                        'feedback_avg' => $gs['feedback']['average'] ?? null,
                        'feedback_count' => $gs['feedback']['count'] ?? 0,
                        'occupancy' => $occ['average'] ?? null,
                        '_online' => array_sum(array_column($tvs, '_online')),
                        '_period' => array_sum(array_column($tvs, '_period')),
                        '_days' => array_column($days, 'plays', 'day'),
                    ];
                });
            } catch (Throwable $e) {
                Logger::error('chain report failed for hotel ' . $hid . ': ' . $e->getMessage());
                continue;
            }
            $series[$hid] = ['name' => $r['name'], 'plays' => array_values($r['_days'])];
            $tot['online'] += $r['_online'];
            $tot['period'] += $r['_period'];
            unset($r['_days'], $r['_online'], $r['_period']);
            $rows[] = $r;
        }
        $s = static fn (string $k) => array_sum(array_map(static fn ($r) => $r[$k] ?? 0, $rows));
        $fbRows = array_filter($rows, static fn ($r) => $r['feedback_avg'] !== null && $r['feedback_count'] > 0);
        $fbN = array_sum(array_column($fbRows, 'feedback_count'));
        $occ = array_values(array_filter(array_column($rows, 'occupancy'), static fn ($v) => $v !== null));
        $totals = [
            'id' => 0, 'name' => __('Total'),
            'plays' => $s('plays'), 'seconds' => $s('seconds'), 'ad_impressions' => $s('ad_impressions'), 'tvs' => $s('tvs'),
            'uptime' => $tot['period'] > 0 ? round(100 * $tot['online'] / $tot['period'], 1) : null,
            'hours_on' => round((float) $s('hours_on'), 1), 'kwh' => round((float) $s('kwh'), 2),
            'orders' => $s('orders'), 'revenue' => round((float) $s('revenue'), 2), 'requests' => $s('requests'),
            'feedback_avg' => $fbN > 0 ? round(array_sum(array_map(static fn ($r) => $r['feedback_avg'] * $r['feedback_count'], $fbRows)) / $fbN, 2) : null,
            'feedback_count' => $fbN,
            'occupancy' => $occ ? round(array_sum($occ) / count($occ), 1) : null,
        ];
        return ['hotels' => $rows, 'totals' => $totals, 'days' => Analytics::days($from, $to), 'series' => $series];
    }

    /** The equally long period right before [$from, $to]. */
    public static function previousRange(string $from, string $to): array
    {
        $len = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pTo = date('Y-m-d', strtotime($from . ' -1 day'));
        return [date('Y-m-d', strtotime($pTo . ' -' . ($len - 1) . ' days')), $pTo];
    }

    // ------------------------------------------------------------------ chain content library

    public static function contentItems(int $chainId): array
    {
        return DB::all(
            "SELECT c.*, (SELECT COUNT(*) FROM chain_publications p WHERE p.chain_id = c.chain_id AND p.source_type = 'content' AND p.source_id = c.id) AS published
             FROM chain_content_items c WHERE c.chain_id = :c ORDER BY c.title",
            ['c' => $chainId]
        );
    }

    public static function playlists(int $chainId): array
    {
        return DB::all(
            "SELECT p.*, (SELECT COUNT(*) FROM chain_playlist_items i WHERE i.playlist_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM chain_publications x WHERE x.chain_id = p.chain_id AND x.source_type = 'playlist' AND x.source_id = p.id) AS published
             FROM chain_playlists p WHERE p.chain_id = :c ORDER BY p.name",
            ['c' => $chainId]
        );
    }

    /** Chain content item (of this chain) or 404. */
    public static function contentItem(int $chainId, int $id): array
    {
        $row = $id > 0 ? DB::one('SELECT * FROM chain_content_items WHERE id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]) : null;
        if (!$row) {
            self::deny("chain $chainId content $id");
        }
        return $row;
    }

    public static function playlist(int $chainId, int $id): array
    {
        $row = $id > 0 ? DB::one('SELECT * FROM chain_playlists WHERE id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]) : null;
        if (!$row) {
            self::deny("chain $chainId playlist $id");
        }
        return $row;
    }

    public static function playlistItems(int $playlistId): array
    {
        return DB::all(
            'SELECT i.content_id, i.sort_order, i.duration, c.title, c.type FROM chain_playlist_items i
             JOIN chain_content_items c ON c.id = i.content_id WHERE i.playlist_id = :p ORDER BY i.sort_order, i.id',
            ['p' => $playlistId]
        );
    }

    /** Hotel ids (and local ids) an item is published to: [hotelId => localId]. */
    public static function publications(int $chainId, string $type, int $sourceId): array
    {
        $out = [];
        foreach (DB::all(
            'SELECT hotel_id, local_id FROM chain_publications WHERE chain_id = :c AND source_type = :t AND source_id = :s',
            ['c' => $chainId, 't' => $type, 's' => $sourceId]
        ) as $r) {
            $out[(int) $r['hotel_id']] = (int) $r['local_id'];
        }
        return $out;
    }

    /**
     * Create / update a chain content item from form input (+ optional uploaded file).
     * Returns [id|null, errors].
     */
    public static function saveContent(int $chainId, ?array $existing, array $in, ?array $file, ?int $userId = null): array
    {
        $type = $existing ? (string) $existing['type'] : (string) ($in['type'] ?? '');
        $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($existing && !empty($existing['file_path'])) {
            $in['_existing_file'] = 1;
        }
        [$data, $errors] = ContentManager::validate($in, $type, $hasFile);
        if ($errors) {
            return [null, $errors];
        }
        $row = [
            'title' => $data['title'], 'type' => $data['type'], 'duration' => $data['duration'], 'url' => $data['url'], 'body' => $data['body'],
            'settings' => json_out((object) $data['settings']),
            'is_active' => array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : ($existing ? (int) $existing['is_active'] : 1),
        ];
        $oldFiles = null;
        if ($hasFile && in_array($type, ['image', 'video'], true)) {
            try {
                $up = Uploader::handle($file, $type);
                [$path, $thumb] = self::relocate($chainId, $up['path'], $up['thumb']);
                $row += ['file_path' => $path, 'thumb_path' => $thumb, 'mime_type' => $up['mime'], 'file_size' => $up['size']];
                $row['url'] = null;
                if ($existing) {
                    $oldFiles = [$existing['file_path'], $existing['thumb_path']];
                }
            } catch (RuntimeException $e) {
                return [null, [$e->getMessage()]];
            }
        } elseif ($existing && $data['url'] && !empty($existing['file_path']) && !empty($in['use_url'])) {
            $row += ['file_path' => null, 'thumb_path' => null, 'mime_type' => null, 'file_size' => null];
            $oldFiles = [$existing['file_path'], $existing['thumb_path']];
        }
        if ($existing) {
            DB::update('chain_content_items', $row, 'id = :id AND chain_id = :c', ['id' => (int) $existing['id'], 'c' => $chainId]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('chain_content_items', $row + ['chain_id' => $chainId, 'created_by' => $userId, 'created_at' => now()]);
        }
        if ($oldFiles) {
            Uploader::delete($oldFiles[0], $oldFiles[1]);
        }
        return [$id, []];
    }

    /** Move a freshly uploaded file (+ thumb) into uploads/chains/c{id}/media/YYYY/MM/. */
    private static function relocate(int $chainId, string $path, ?string $thumb): array
    {
        $dirRel = 'chains/c' . $chainId . '/media/' . date('Y/m');
        $dirAbs = HC_ROOT . '/uploads/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
            throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
        }
        $out = [];
        foreach ([$path, $thumb] as $p) {
            if (!$p) {
                $out[] = null;
                continue;
            }
            $dest = $dirRel . '/' . basename($p);
            if (!@rename(HC_ROOT . '/uploads/' . $p, HC_ROOT . '/uploads/' . $dest)) {
                $out[] = $p; // keep where it is (still a valid media path)
                continue;
            }
            $out[] = $dest;
        }
        return $out;
    }

    /** Delete a chain item (hotel copies stay in the hotels, the links are removed). */
    public static function deleteContent(int $chainId, int $id): void
    {
        $item = self::contentItem($chainId, $id);
        DB::query('DELETE FROM chain_content_items WHERE id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]);
        DB::query("DELETE FROM chain_publications WHERE chain_id = :c AND source_type = 'content' AND source_id = :s", ['c' => $chainId, 's' => $id]);
        Uploader::delete($item['file_path'], $item['thumb_path']);
    }

    /** Create / update a chain playlist. $items = [[content_id, duration|null], …] (chain items only). */
    public static function savePlaylist(int $chainId, ?array $existing, array $in, ?int $userId = null): array
    {
        $name = mb_substr(trim(is_string($in['name'] ?? null) ? $in['name'] : ''), 0, 190);
        $errors = [];
        if ($name === '') {
            $errors[] = __('Playlist name is required.');
        }
        $ids = [];
        foreach ((array) ($in['items'] ?? []) as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        if ($ids) {
            [$inSql, $p] = DB::in(array_values(array_unique($ids)), 'ci');
            $own = array_map('intval', DB::column("SELECT id FROM chain_content_items WHERE chain_id = :c AND id IN $inSql", $p + ['c' => $chainId]));
            if (count($own) !== count(array_unique($ids))) {
                self::deny("chain $chainId playlist items");
            }
        }
        if (!$ids) {
            $errors[] = __('Add at least one item.');
        }
        if ($errors) {
            return [null, $errors];
        }
        $durations = (array) ($in['durations'] ?? []);
        $transition = in_array($in['transition'] ?? '', ['none', 'fade', 'slide'], true) ? $in['transition'] : 'fade';
        $row = ['name' => $name, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null, 'transition' => $transition];
        return [DB::transaction(static function () use ($chainId, $existing, $row, $ids, $durations, $userId): int {
            if ($existing) {
                $id = (int) $existing['id'];
                DB::update('chain_playlists', $row, 'id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]);
                DB::query('DELETE FROM chain_playlist_items WHERE playlist_id = :p', ['p' => $id]);
            } else {
                $id = DB::insert('chain_playlists', $row + ['chain_id' => $chainId, 'created_by' => $userId, 'created_at' => now()]);
            }
            foreach ($ids as $i => $cid) {
                $d = $durations[$i] ?? '';
                DB::insert('chain_playlist_items', [
                    'playlist_id' => $id, 'content_id' => $cid, 'sort_order' => $i,
                    'duration' => is_numeric($d) && (int) $d > 0 ? min(86400, (int) $d) : null,
                ]);
            }
            return $id;
        }), []];
    }

    public static function deletePlaylist(int $chainId, int $id): void
    {
        self::playlist($chainId, $id);
        DB::query('DELETE FROM chain_playlists WHERE id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]);
        DB::query("DELETE FROM chain_publications WHERE chain_id = :c AND source_type = 'playlist' AND source_id = :s", ['c' => $chainId, 's' => $id]);
    }

    /**
     * Publish a chain content item ('content') or playlist ('playlist') to hotels of the chain.
     * Returns [hotelId => ['status' => created|updated|skipped|error, 'local_id' => int|null, 'message' => string]].
     */
    public static function publish(int $chainId, string $type, int $sourceId, array $hotelIds, ?int $userId = null): array
    {
        $hotelIds = self::assertHotels($chainId, $hotelIds);
        $src = $type === 'playlist' ? self::playlist($chainId, $sourceId) : self::contentItem($chainId, $sourceId);
        $out = [];
        foreach ($hotelIds as $hid) {
            $out[$hid] = self::inHotel($hid, static function () use ($chainId, $type, $src, $userId): array {
                $before = self::publications($chainId, $type, (int) $src['id'])[Tenant::id()] ?? null;
                $local = $type === 'playlist' ? self::publishPlaylistHere($chainId, $src, $userId) : self::publishContentHere($chainId, $src, $userId);
                ActivityLog::add('chain_publish', $type === 'playlist' ? 'playlist' : 'content', $local, 'Chain ' . $type . ': ' . ($src['title'] ?? $src['name']));
                return ['status' => $before === $local ? 'updated' : 'created', 'local_id' => $local, 'message' => ''];
            });
        }
        self::logAction($chainId, 'publish_' . $type, (string) ($src['title'] ?? $src['name']), $out, $userId);
        return $out;
    }

    /** Run a write action in a hotel: skipped when suspended / expired, errors caught. */
    private static function inHotel(int $hid, callable $fn): array
    {
        try {
            return Tenant::run($hid, static function () use ($fn): array {
                if (!Tenant::isActive()) {
                    return ['status' => 'skipped', 'local_id' => null, 'message' => __('Hotel is suspended or expired.')];
                }
                return $fn();
            });
        } catch (TenantException $e) {
            throw $e;
        } catch (Throwable $e) {
            Logger::error('chain action failed in hotel ' . $hid . ': ' . $e->getMessage());
            return ['status' => 'error', 'local_id' => null, 'message' => $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : __('Something went wrong')];
        }
    }

    /** Copy / update one chain item in the current hotel. Returns the hotel's content id. */
    public static function publishContentHere(int $chainId, array $src, ?int $userId = null): int
    {
        $hid = Tenant::id();
        $link = DB::one(
            "SELECT * FROM chain_publications WHERE hotel_id = :h AND source_type = 'content' AND source_id = :s",
            ['h' => $hid, 's' => (int) $src['id']]
        );
        $local = $link ? ContentManager::findOwn((int) $link['local_id']) : null;
        $settings = ContentManager::settings($src);
        $settings['chain_content_id'] = (int) $src['id'];
        $row = [
            'title' => $src['title'], 'type' => $src['type'], 'url' => $src['url'], 'body' => $src['body'],
            'settings' => json_out((object) $settings), 'duration' => (int) $src['duration'], 'is_active' => (int) $src['is_active'],
            'mime_type' => $src['mime_type'], 'file_size' => $src['file_size'],
        ];
        $deleteOld = null;
        if (!empty($src['file_path'])) {
            $same = $local && $link['source_file'] === $src['file_path'] && !empty($local['file_path']) && is_file(HC_ROOT . '/uploads/' . $local['file_path']);
            if ($same) {
                $row['file_path'] = $local['file_path'];
                $row['thumb_path'] = $local['thumb_path'];
            } else {
                $row['file_path'] = self::copyMedia($chainId, (string) $src['file_path'], $hid);
                $row['thumb_path'] = !empty($src['thumb_path']) ? self::copyMedia($chainId, (string) $src['thumb_path'], $hid) : null;
                if ($local && !empty($local['file_path'])) {
                    $deleteOld = [$local['file_path'], $local['thumb_path']];
                }
            }
        } else {
            $row['file_path'] = null;
            $row['thumb_path'] = null;
            if ($local && !empty($local['file_path']) && $link && $link['source_file']) {
                $deleteOld = [$local['file_path'], $local['thumb_path']];
            }
        }
        if ($local) {
            $id = (int) $local['id'];
            DB::update('content_items', $row, 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('content_items', $row + ['created_by' => $userId, 'created_at' => now()]);
        }
        if ($deleteOld) {
            Uploader::delete($deleteOld[0], $deleteOld[1]);
        }
        self::link($chainId, $hid, 'content', (int) $src['id'], $id, $src['file_path'] ?: null, $userId);
        Settings::bumpContentVersion();
        return $id;
    }

    /** Copy / update a chain playlist (and its items) in the current hotel. Returns the hotel's playlist id. */
    public static function publishPlaylistHere(int $chainId, array $pl, ?int $userId = null): int
    {
        $hid = Tenant::id();
        $map = [];
        $items = self::playlistItems((int) $pl['id']);
        foreach ($items as $it) {
            $cid = (int) $it['content_id'];
            if (!isset($map[$cid])) {
                $map[$cid] = self::publishContentHere($chainId, self::contentItem($chainId, $cid), $userId);
            }
        }
        $link = DB::one(
            "SELECT * FROM chain_publications WHERE hotel_id = :h AND source_type = 'playlist' AND source_id = :s",
            ['h' => $hid, 's' => (int) $pl['id']]
        );
        $local = $link ? ContentManager::findOwnPlaylist((int) $link['local_id']) : null;
        $row = ['name' => $pl['name'], 'description' => $pl['description'], 'transition' => $pl['transition']];
        return DB::transaction(static function () use ($local, $row, $items, $map, $chainId, $hid, $pl, $userId): int {
            if ($local) {
                $id = (int) $local['id'];
                DB::update('content_playlists', $row, 'id = :id', ['id' => $id]);
                // playlist_items has no hotel_id: the parent playlist was verified above (findOwnPlaylist).
                DB::query('DELETE FROM playlist_items WHERE playlist_id = :p', ['p' => $id]);
            } else {
                $id = DB::insert('content_playlists', $row + ['created_by' => $userId, 'created_at' => now()]);
            }
            foreach ($items as $i => $it) {
                DB::insert('playlist_items', [
                    'playlist_id' => $id, 'content_id' => $map[(int) $it['content_id']], 'sort_order' => $i,
                    'duration' => $it['duration'] !== null ? (int) $it['duration'] : null,
                ]);
            }
            self::link($chainId, $hid, 'playlist', (int) $pl['id'], $id, null, $userId);
            Settings::bumpContentVersion();
            return $id;
        });
    }

    private static function link(int $chainId, int $hid, string $type, int $sourceId, int $localId, ?string $sourceFile, ?int $userId): void
    {
        DB::query(
            'INSERT INTO chain_publications (chain_id, hotel_id, source_type, source_id, local_id, source_file, published_by, published_at)
             VALUES (:c, :h, :t, :s, :l, :f, :u, :n)
             ON DUPLICATE KEY UPDATE chain_id = VALUES(chain_id), local_id = VALUES(local_id), source_file = VALUES(source_file),
                                     published_by = VALUES(published_by), updated_at = VALUES(published_at)',
            ['c' => $chainId, 'h' => $hid, 't' => $type, 's' => $sourceId, 'l' => $localId, 'f' => $sourceFile, 'u' => $userId, 'n' => now()]
        );
    }

    /** Copy a chain media file into uploads/h{hotel}/media/YYYY/MM/. Returns the new relative path. */
    private static function copyMedia(int $chainId, string $rel, int $hid): string
    {
        if (str_contains($rel, '..') || !str_starts_with($rel, 'chains/c' . $chainId . '/')) {
            throw new RuntimeException('Invalid chain media path');
        }
        $src = HC_ROOT . '/uploads/' . $rel;
        if (!is_file($src)) {
            throw new RuntimeException(__('The media file of this item is missing.'));
        }
        $dirRel = 'h' . $hid . '/media/' . date('Y/m');
        $dirAbs = HC_ROOT . '/uploads/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
            throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $suffix = str_ends_with(pathinfo($rel, PATHINFO_FILENAME), '_thumb') ? '_thumb' : '';
        $name = 'chain' . $chainId . '_' . random_token(8) . $suffix . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
        if (!copy($src, $dirAbs . '/' . $name)) {
            throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
        }
        @chmod($dirAbs . '/' . $name, 0644);
        return $dirRel . '/' . $name;
    }

    // ------------------------------------------------------------------ chain broadcast

    /**
     * Validate broadcast input once for the chain. Source "c:ID" / "p:ID" = chain content / playlist.
     * Returns [params, errors].
     */
    public static function validateBroadcast(int $chainId, string $kind, array $in): array
    {
        $errors = [];
        $p = ['kind' => $kind];
        if (!in_array($kind, self::BROADCAST_KINDS, true)) {
            return [$p, [__('Unknown action.')]];
        }
        if ($kind === 'push' || $kind === 'schedule') {
            $source = is_string($in['source'] ?? null) ? $in['source'] : '';
            if (preg_match('/^c:(\d{1,9})$/', $source, $m)) {
                $p['source_type'] = 'content';
                $p['source'] = self::contentItem($chainId, (int) $m[1]);
            } elseif (preg_match('/^p:(\d{1,9})$/', $source, $m)) {
                $p['source_type'] = 'playlist';
                $p['source'] = self::playlist($chainId, (int) $m[1]);
            } else {
                $errors[] = __('Select content or a playlist.');
            }
            $p['title'] = mb_substr(trim((string) ($in['title'] ?? '')), 0, 190) ?: (string) ($p['source']['title'] ?? $p['source']['name'] ?? '');
        }
        if ($kind === 'schedule') {
            $p['mode'] = ($in['mode'] ?? 'once') === 'window' ? 'window' : 'once';
            $p['start_at'] = Broadcaster::parseDateTime(is_string($in['start_at'] ?? null) ? $in['start_at'] : '');
            $p['end_at'] = $p['mode'] === 'window' ? Broadcaster::parseDateTime(is_string($in['end_at'] ?? null) ? $in['end_at'] : '') : null;
            $p['daily_start'] = $p['mode'] === 'window' ? Broadcaster::parseTime(is_string($in['daily_start'] ?? null) ? $in['daily_start'] : '') : null;
            $p['daily_end'] = $p['mode'] === 'window' ? Broadcaster::parseTime(is_string($in['daily_end'] ?? null) ? $in['daily_end'] : '') : null;
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['repeat_days'] ?? [])), static fn ($d) => $d >= 1 && $d <= 7)));
            sort($days);
            $p['repeat_days'] = $p['mode'] === 'window' && $days ? implode(',', $days) : null;
            if ($p['mode'] === 'once' && !$p['start_at']) {
                $errors[] = __('Start date/time is required.');
            }
            if ($p['start_at'] && $p['end_at'] && strtotime($p['end_at']) <= strtotime($p['start_at'])) {
                $errors[] = __('End time must be after start time.');
            }
            if (($p['daily_start'] xor $p['daily_end']) || ($p['daily_start'] && $p['daily_start'] === $p['daily_end'])) {
                $errors[] = __('Give both daily start and end time (different values).');
            }
            if ($p['mode'] === 'window' && !$p['start_at'] && !$p['end_at'] && !$p['daily_start'] && !$days) {
                $errors[] = __('A time window needs a date range, daily times or repeat days.');
            }
        }
        if ($kind === 'emergency') {
            $p['title'] = mb_substr(trim((string) ($in['title'] ?? '')), 0, 190);
            $p['message'] = mb_substr(trim((string) ($in['message'] ?? '')), 0, 1000);
            $p['bg_color'] = clean_color(is_string($in['bg_color'] ?? null) ? $in['bg_color'] : null, '#B00020');
            $p['text_color'] = clean_color(is_string($in['text_color'] ?? null) ? $in['text_color'] : null, '#FFFFFF');
            if ($p['title'] === '' && $p['message'] === '') {
                $errors[] = __('Enter a title or message.');
            }
        }
        if ($kind === 'emergency_stop') {
            $p['title'] = __('Stop emergency');
        }
        return [$p, $errors];
    }

    /**
     * Run a validated broadcast in every selected hotel (all rooms), Broadcaster in each hotel's context.
     * push / schedule publish the chain item first (the copy is refreshed). Returns per-hotel results.
     */
    public static function broadcast(int $chainId, array $hotelIds, array $p, ?int $userId = null): array
    {
        $hotelIds = self::assertHotels($chainId, $hotelIds);
        $out = [];
        foreach ($hotelIds as $hid) {
            $out[$hid] = self::inHotel($hid, static function () use ($chainId, $p, $userId): array {
                $hid = Tenant::id();
                $local = null;
                if (in_array($p['kind'], ['push', 'schedule'], true)) {
                    $local = $p['source_type'] === 'playlist'
                        ? self::publishPlaylistHere($chainId, $p['source'], $userId)
                        : self::publishContentHere($chainId, $p['source'], $userId);
                }
                $c = $p['kind'] !== 'emergency_stop' && ($p['source_type'] ?? '') === 'content' ? $local : null;
                $pl = ($p['source_type'] ?? '') === 'playlist' ? $local : null;
                switch ($p['kind']) {
                    case 'push':
                        $bid = Broadcaster::pushNow('all', [], $c, $pl, $p['title'], $userId);
                        break;
                    case 'schedule':
                        $bid = Broadcaster::schedule([
                            'title' => $p['title'], 'target_type' => 'all', 'target_ids' => [], 'content_id' => $c, 'playlist_id' => $pl,
                            'mode' => $p['mode'], 'start_at' => $p['start_at'], 'end_at' => $p['end_at'],
                            'daily_start' => $p['daily_start'], 'daily_end' => $p['daily_end'], 'repeat_days' => $p['repeat_days'],
                        ], $userId);
                        break;
                    case 'emergency':
                        $bid = Broadcaster::emergencyStart($p['title'], $p['message'], 'all', [], $userId, $p['bg_color'], $p['text_color']);
                        break;
                    default:
                        $n = Broadcaster::emergencyStop();
                        ActivityLog::add('emergency_stop', 'broadcast', null, "Chain: stopped $n emergency broadcast(s)");
                        return ['status' => 'done', 'local_id' => null, 'message' => (string) $n, 'devices' => 0];
                }
                $devices = (int) DB::value("SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :h AND broadcast_id = :b AND event = 'queued'", ['h' => $hid, 'b' => $bid]);
                ActivityLog::add('chain_' . $p['kind'], 'broadcast', $bid, 'Chain: ' . $p['title'] . " ($devices TVs)");
                return ['status' => 'done', 'local_id' => $bid, 'message' => '', 'devices' => $devices];
            });
        }
        self::logAction($chainId, 'broadcast_' . $p['kind'], (string) ($p['title'] ?? ''), $out, $userId);
        return $out;
    }

    /** Active emergencies per hotel of the chain: [hotelId => count]. */
    public static function activeEmergencies(int $chainId): array
    {
        $out = [];
        foreach (DB::all(
            "SELECT b.hotel_id, COUNT(*) AS n FROM broadcast_commands b JOIN hotels h ON h.id = b.hotel_id
             WHERE h.chain_id = :c AND b.is_emergency = 1 AND b.status = 'active' GROUP BY b.hotel_id",
            ['c' => $chainId]
        ) as $r) {
            $out[(int) $r['hotel_id']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ settings templates

    public static function templates(int $chainId): array
    {
        return DB::all('SELECT * FROM chain_setting_templates WHERE chain_id = :c ORDER BY name', ['c' => $chainId]);
    }

    public static function template(int $chainId, int $id): array
    {
        $row = $id > 0 ? DB::one('SELECT * FROM chain_setting_templates WHERE id = :id AND chain_id = :c', ['id' => $id, 'c' => $chainId]) : null;
        if (!$row) {
            self::deny("chain $chainId template $id");
        }
        return $row;
    }

    /** Validate a template form: only the ticked groups are stored. Returns [data, errors]. */
    public static function validateTemplate(array $chain, array $in): array
    {
        $errors = [];
        $name = mb_substr(trim(is_string($in['name'] ?? null) ? $in['name'] : ''), 0, 150);
        if ($name === '') {
            $errors[] = __('Template name is required.');
        }
        $groups = array_values(array_intersect(array_keys(self::TEMPLATE_GROUPS), (array) ($in['groups'] ?? [])));
        if (!$groups) {
            $errors[] = __('Choose at least one group of settings.');
        }
        $s = [];
        $str = static fn (string $k, int $max) => mb_substr(trim(is_string($in[$k] ?? null) ? str_replace("\r", '', $in[$k]) : ''), 0, $max);
        if (in_array('ticker', $groups, true)) {
            $s['ticker_text'] = $str('ticker_text', 1000);
            $s['ticker_speed'] = (string) max(1, min(10, (int) ($in['ticker_speed'] ?? 5)));
            $s['ticker_bg_color'] = clean_color(is_string($in['ticker_bg_color'] ?? null) ? $in['ticker_bg_color'] : null, '#000000');
            $s['ticker_text_color'] = clean_color(is_string($in['ticker_text_color'] ?? null) ? $in['ticker_text_color'] : null, '#FFD700');
        }
        if (in_array('overlay', $groups, true)) {
            $s['overlay_clock'] = !empty($in['overlay_clock']) ? '1' : '0';
            $s['overlay_clock_format'] = in_array($in['overlay_clock_format'] ?? '', self::CLOCK_FORMATS, true) ? (string) $in['overlay_clock_format'] : 'hh:mm a';
            $s['overlay_logo'] = !empty($in['overlay_logo']) ? '1' : '0';
            $s['overlay_weather'] = !empty($in['overlay_weather']) ? '1' : '0';
        }
        if (in_array('branding', $groups, true)) {
            $color = $str('brand_color', 7);
            if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors[] = __('Colour must look like #7B1FA2.');
            }
            $s['brand_name'] = $str('brand_name', 120);
            $s['brand_color'] = $color !== '' ? strtoupper($color) : '';
            $s['brand_logo'] = !empty($in['use_chain_logo']) ? (string) ($chain['brand_logo'] ?? '') : '';
        }
        return [['name' => $name, 'settings' => json_out(['groups' => $groups, 'values' => $s])], $errors];
    }

    public static function saveTemplate(int $chainId, ?array $existing, array $data, ?int $userId = null): int
    {
        if ($existing) {
            DB::update('chain_setting_templates', $data, 'id = :id AND chain_id = :c', ['id' => (int) $existing['id'], 'c' => $chainId]);
            return (int) $existing['id'];
        }
        return DB::insert('chain_setting_templates', $data + ['chain_id' => $chainId, 'created_by' => $userId, 'created_at' => now()]);
    }

    public static function templateData(array $tpl): array
    {
        $d = json_decode((string) $tpl['settings'], true);
        return ['groups' => (array) ($d['groups'] ?? []), 'values' => (array) ($d['values'] ?? [])];
    }

    /** Push a template to hotels: hotel settings (ticker / overlay) and hotel branding override. */
    public static function applyTemplate(int $chainId, int $templateId, array $hotelIds, ?int $userId = null): array
    {
        $hotelIds = self::assertHotels($chainId, $hotelIds);
        $tpl = self::template($chainId, $templateId);
        $d = self::templateData($tpl);
        $out = [];
        foreach ($hotelIds as $hid) {
            $out[$hid] = self::inHotel($hid, static function () use ($d, $tpl): array {
                $hid = Tenant::id();
                foreach (['ticker', 'overlay'] as $g) {
                    if (in_array($g, $d['groups'], true)) {
                        foreach (self::TEMPLATE_GROUPS[$g] as $k) {
                            if (array_key_exists($k, $d['values'])) {
                                Settings::set($k, (string) $d['values'][$k]);
                            }
                        }
                    }
                }
                if (in_array('branding', $d['groups'], true)) {
                    $v = $d['values'];
                    DB::query(
                        'UPDATE hotels SET brand_name = :n, brand_color = :c, brand_logo = :l WHERE id = :h',
                        ['n' => ($v['brand_name'] ?? '') ?: null, 'c' => ($v['brand_color'] ?? '') ?: null, 'l' => ($v['brand_logo'] ?? '') ?: null, 'h' => $hid]
                    );
                    Tenant::forget($hid);
                    Branding::flush();
                }
                Settings::bumpContentVersion();
                ActivityLog::add('chain_settings', 'settings', null, 'Chain template: ' . $tpl['name']);
                return ['status' => 'done', 'local_id' => null, 'message' => ''];
            });
        }
        self::logAction($chainId, 'template', (string) $tpl['name'], $out, $userId);
        return $out;
    }

    // ------------------------------------------------------------------ history

    private static function logAction(int $chainId, string $kind, string $title, array $results, ?int $userId): void
    {
        try {
            DB::insert('chain_actions', [
                'chain_id' => $chainId, 'kind' => mb_substr($kind, 0, 30), 'title' => mb_substr($title, 0, 190),
                'hotel_ids' => json_out(array_keys($results)), 'results' => json_out($results),
                'created_by' => $userId, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Logger::error('chain action log failed: ' . $e->getMessage());
        }
    }

    public static function actions(int $chainId, int $limit = 20): array
    {
        return DB::all(
            'SELECT a.*, u.username FROM chain_actions a LEFT JOIN users u ON u.id = a.created_by WHERE a.chain_id = :c ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit)),
            ['c' => $chainId]
        );
    }

    /** Short summary of per-hotel results: "3 done · 1 skipped". */
    public static function summarize(array $results): string
    {
        $c = [];
        foreach ($results as $r) {
            $s = (string) ($r['status'] ?? 'error');
            $c[$s] = ($c[$s] ?? 0) + 1;
        }
        $labels = ['created' => __('created'), 'updated' => __('updated'), 'done' => __('done'), 'skipped' => __('skipped'), 'error' => __('failed')];
        $parts = [];
        foreach ($c as $s => $n) {
            $parts[] = $n . ' ' . ($labels[$s] ?? $s);
        }
        return implode(' · ', $parts);
    }
}
