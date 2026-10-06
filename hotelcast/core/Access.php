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
 *   Access::requireRoom($id) …  403 via Tenant::deny() when not allowed
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

    /** target_type all | group | room (also floor = rooms on that floor). */
    public static function canTarget(string $type, int|string|null $id): bool
    {
        if (!self::restricted()) {
            return true;
        }
        return match ($type) {
            'room' => self::canRoom((int) $id),
            'group' => self::canGroup((int) $id),
            'floor' => self::canFloor((string) $id),
            default => false, // 'all' and anything else: only unrestricted users
        };
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
            Tenant::deny('room');
        }
    }

    public static function requireDevice(int $deviceId): void
    {
        if (!self::canDevice($deviceId)) {
            Tenant::deny('device');
        }
    }

    public static function requireTarget(string $type, int|string|null $id): void
    {
        if (!self::canTarget($type, $id)) {
            Tenant::deny('target');
        }
    }

    /**
     * SQL fragment limiting a room id column, e.g. Access::roomSql('r.id') → [" AND r.id IN (:ar0,:ar1)", params]
     * ('' and [] when unrestricted; " AND 1=0" when the user has no rooms at all).
     */
    public static function roomSql(string $column): array
    {
        $ids = self::roomIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1=0', []];
        }
        [$in, $p] = DB::in($ids, 'ar');
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
