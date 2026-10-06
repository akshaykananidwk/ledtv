<?php
declare(strict_types=1);

/**
 * Per-user TV access (2.2). A hotel user (manager / staff / reception) with rows in `user_access`
 * may only see and control the TVs of those rooms / groups. No rows = all TVs (unchanged behaviour).
 * Super admins, platform admins, resellers and chain admins always have all TVs.
 *
 * Use it wherever a page / AJAX action / broadcast targets rooms, groups or devices:
 *   Access::restricted()        is the current user limited?
 *   Access::roomIds()           allowed room ids (null = all)
 *   Access::canRoom($id) / canGroup($id) / canDevice($id) / canTarget($type, $id)
 *   Access::requireRoom($id) …  403 via Access::deny() when not allowed (logged in logs/security)
 *   Access::canTargetList($type, $ids) / canBroadcast($row) / requireUnrestricted()
 *   Access::roomSql('r.id')     SQL fragment " AND r.id IN (…)" (or '') + params for list queries
 */
final class Access
{
    /** @var array<int, array{rooms: int[], groups: int[]}|null> cache per user id */
    private static array $cache = [];

    /** Test hook / CLI: force a user context (null = use Auth::user()). */
    public static ?array $userOverride = null;

    public static function forget(): void
    {
        self::$cache = [];
    }

    private static function user(): ?array
    {
        return self::$userOverride ?? Auth::user();
    }

    /** Roles that are never limited. */
    public static function isUnlimitedRole(string $role): bool
    {
        return !in_array($role, ['manager', 'staff', 'reception'], true);
    }

    /** ['rooms' => [...], 'groups' => [...]] for the user, or null when unrestricted. */
    public static function scope(?array $user = null): ?array
    {
        $u = $user ?? self::user();
        if (!$u || self::isUnlimitedRole((string) ($u['role'] ?? ''))) {
            return null;
        }
        $uid = (int) $u['id'];
        if (array_key_exists($uid, self::$cache)) {
            return self::$cache[$uid];
        }
        $hid = (int) ($u['hotel_id'] ?? 0);
        $rows = DB::all('SELECT target_type, target_id FROM user_access WHERE user_id = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hid]);
        if (!$rows) {
            return self::$cache[$uid] = null;
        }
        $groups = [];
        $rooms = [];
        foreach ($rows as $r) {
            if ($r['target_type'] === 'group') {
                $groups[] = (int) $r['target_id'];
            } else {
                $rooms[] = (int) $r['target_id'];
            }
        }
        if ($groups) {
            [$in, $p] = DB::in($groups, 'g');
            foreach (DB::column("SELECT m.room_id FROM room_group_members m JOIN rooms r ON r.id = m.room_id WHERE r.hotel_id = :h AND m.group_id IN $in", $p + ['h' => $hid]) as $rid) {
                $rooms[] = (int) $rid;
            }
        }
        if ($rooms) {
            // Only rooms that still exist in this hotel (stale rows of deleted rooms keep the user restricted).
            [$in, $p] = DB::in(array_values(array_unique($rooms)), 'rm');
            $rooms = array_map('intval', DB::column("SELECT id FROM rooms WHERE hotel_id = :h AND id IN $in", $p + ['h' => $hid]));
        }
        $rooms = array_values(array_unique($rooms));
        sort($rooms);
        return self::$cache[$uid] = ['rooms' => $rooms, 'groups' => array_values(array_unique($groups))];
    }

    public static function restricted(): bool
    {
        return self::scope() !== null;
    }

    /** Allowed room ids, or null for all rooms. */
    public static function roomIds(): ?array
    {
        $s = self::scope();
        return $s === null ? null : $s['rooms'];
    }

    public static function canRoom(int $roomId): bool
    {
        $ids = self::roomIds();
        return $ids === null || in_array($roomId, $ids, true);
    }

    /** A restricted user may target a group only when it was assigned to them. */
    public static function canGroup(int $groupId): bool
    {
        $s = self::scope();
        return $s === null || in_array($groupId, $s['groups'], true);
    }

    public static function canDevice(int $deviceId): bool
    {
        if (!self::restricted()) {
            return true;
        }
        $room = DB::value('SELECT room_id FROM devices WHERE id = :id AND hotel_id = :h', ['id' => $deviceId, 'h' => Tenant::id()]);
        return $room !== null && $room !== false && self::canRoom((int) $room);
    }

    /** target_type all | group | room (also floor = rooms on that floor). Plural forms (Broadcaster) work too. */
    public static function canTarget(string $type, int|string|null $id): bool
    {
        if (!self::restricted()) {
            return true;
        }
        return match ($type) {
            'room', 'rooms' => self::canRoom((int) $id),
            'group', 'groups' => self::canGroup((int) $id),
            'floor', 'floors' => self::canFloor((string) $id),
            default => false, // 'all' and anything else: only unrestricted users
        };
    }

    /**
     * Broadcaster-style target (all | rooms | groups | floors + ids): every id must be allowed;
     * 'all' (or an unknown type) only for unrestricted users. An empty id list targets nothing → allowed.
     */
    public static function canTargetList(string $type, array $ids): bool
    {
        if (!self::restricted()) {
            return true;
        }
        if (!in_array($type, ['room', 'rooms', 'group', 'groups', 'floor', 'floors'], true)) {
            return false;
        }
        foreach ($ids as $id) {
            if (!self::canTarget($type, $id)) {
                return false;
            }
        }
        return true;
    }

    public static function requireTargetList(string $type, array $ids): void
    {
        if (!self::canTargetList($type, $ids)) {
            self::deny('target ' . $type . ' ' . implode(',', array_slice(array_map('strval', $ids), 0, 10)));
        }
    }

    /** May the user change a broadcast_commands row (schedule, power schedule, emergency, ad campaign …)? */
    public static function canBroadcast(array $row): bool
    {
        if (!self::restricted()) {
            return true;
        }
        $ids = $row['target_ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        return self::canTargetList((string) ($row['target_type'] ?? 'all'), (array) $ids);
    }

    public static function requireBroadcast(array $row): void
    {
        if (!self::canBroadcast($row)) {
            self::deny('broadcast ' . ($row['id'] ?? '?'));
        }
    }

    /** Does a broadcast reach at least one of the user's rooms (shown read-only, e.g. a hotel-wide emergency)? */
    public static function touchesBroadcast(array $row): bool
    {
        $allowed = self::roomIds();
        if ($allowed === null || ($row['target_type'] ?? 'all') === 'all') {
            return true;
        }
        $ids = $row['target_ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }
        $rooms = array_map(fn ($r) => (int) $r['id'], Broadcaster::targetRooms((string) $row['target_type'], (array) $ids));
        return (bool) array_intersect($rooms, $allowed);
    }

    /** Keep only rows whose room id column is allowed (rows without a room are dropped for restricted users). */
    public static function filterRooms(array $rows, string $key = 'id'): array
    {
        $ids = self::roomIds();
        if ($ids === null) {
            return $rows;
        }
        return array_values(array_filter($rows, fn ($r) => isset($r[$key]) && in_array((int) $r[$key], $ids, true)));
    }

    /** Hotel-wide actions (create / delete rooms or groups, hotel-wide TV settings): unrestricted users only. */
    public static function requireUnrestricted(string $what = 'hotel-wide action'): void
    {
        if (self::restricted()) {
            self::deny($what);
        }
    }

    /**
     * Refuse with 403 (logged in logs/security): JSON for AJAX, the "Access denied" page otherwise.
     * In CLI (tests, cron) a TenantException is thrown instead.
     */
    public static function deny(string $what = ''): never
    {
        Logger::write('security', 'warning', 'TV access denied (user_access)', [
            'hotel' => Tenant::current(), 'what' => $what,
            'user' => self::user()['id'] ?? null,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        ]);
        if (PHP_SAPI === 'cli') {
            throw new TenantException('Access denied: not one of your TVs (' . $what . ')');
        }
        if (!headers_sent()) {
            http_response_code(403);
        }
        if (Auth::isAjax() || defined('HC_API')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_out(['ok' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => __('You can only control the TVs assigned to you.')]]);
            exit;
        }
        $GLOBALS['hc_forbidden'] = true;
        require HC_ROOT . '/admin/partials/forbidden.php';
        exit;
    }

    public static function canFloor(string $floor): bool
    {
        $ids = self::roomIds();
        if ($ids === null) {
            return true;
        }
        $floorRooms = array_map('intval', DB::column('SELECT id FROM rooms WHERE hotel_id = :h AND floor = :f', ['h' => Tenant::id(), 'f' => $floor]));
        return $floorRooms !== [] && !array_diff($floorRooms, $ids);
    }

    public static function requireRoom(int $roomId): void
    {
        if (!self::canRoom($roomId)) {
            self::deny('room ' . $roomId);
        }
    }

    public static function requireDevice(int $deviceId): void
    {
        if (!self::canDevice($deviceId)) {
            self::deny('device ' . $deviceId);
        }
    }

    public static function requireTarget(string $type, int|string|null $id): void
    {
        if (!self::canTarget($type, $id)) {
            self::deny('target ' . $type . ' ' . $id);
        }
    }

    public static function requireGroup(int $groupId): void
    {
        if (!self::canGroup($groupId)) {
            self::deny('group ' . $groupId);
        }
    }

    /**
     * SQL fragment limiting a room id column, e.g. Access::roomSql('r.id') → [" AND r.id IN (:ar0,:ar1)", params]
     * ('' and [] when unrestricted; " AND 1=0" when the user has no rooms at all).
     */
    public static function roomSql(string $column, string $prefix = 'ar'): array
    {
        $ids = self::roomIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1=0', []];
        }
        [$in, $p] = DB::in($ids, $prefix);
        return [" AND $column IN $in", $p];
    }

    /** Replace a user's assignments (hotel admin action). $targets: [['group', 3], ['room', 12], …] */
    public static function setForUser(int $userId, array $targets): void
    {
        $hid = Tenant::id();
        DB::query('DELETE FROM user_access WHERE user_id = :u AND hotel_id = :h', ['u' => $userId, 'h' => $hid]);
        foreach ($targets as [$type, $id]) {
            if (!in_array($type, ['group', 'room'], true) || (int) $id <= 0) {
                continue;
            }
            $table = $type === 'group' ? 'room_groups' : 'rooms';
            if (!Tenant::find($table, (int) $id)) {
                continue;
            }
            DB::query(
                'INSERT IGNORE INTO user_access (hotel_id, user_id, target_type, target_id, created_at) VALUES (:h, :u, :t, :i, :c)',
                ['h' => $hid, 'u' => $userId, 't' => $type, 'i' => (int) $id, 'c' => now()]
            );
        }
        unset(self::$cache[$userId]);
    }

    /** Assignments of a user: [['type' => 'group', 'id' => 3], …] */
    public static function forUser(int $userId): array
    {
        return array_map(
            fn ($r) => ['type' => $r['target_type'], 'id' => (int) $r['target_id']],
            DB::all('SELECT target_type, target_id FROM user_access WHERE user_id = :u AND hotel_id = :h ORDER BY target_type, target_id', ['u' => $userId, 'h' => Tenant::id()])
        );
    }
}
