<?php
declare(strict_types=1);

/**
 * Per-user audit trail, per hotel (activity_logs.hotel_id). Platform-level actions (platform /
 * reseller pages, set ActivityLog::$platformScope = true) are stored with hotel_id NULL so they never
 * show up in a hotel's log.
 *
 * 2.6.1: actions of platform users (Super Admin, reseller) are flagged actor_platform = 1. A customer's
 * own log views add customerFilter() so the Super Admin working inside the workspace leaves no visible
 * trace there; the Super Admin console's Audit logs still shows every row (docs/SPEC_SAAS.md §27, §30).
 * Logins / logouts of platform users are stored at platform level (hotel_id NULL).
 */
final class ActivityLog
{
    public static bool $platformScope = false;

    private const PLATFORM_LEVEL_ACTIONS = ['login', 'logout', 'login_locked'];

    private static ?bool $hasActor = null;

    /** $hotelId: false = automatic (current hotel, or NULL in platform scope), null = platform, int = that hotel. */
    public static function add(string $action, ?string $entityType = null, ?int $entityId = null, string $details = '', int|false|null $hotelId = false): void
    {
        $user = Auth::user();
        $platformActor = $user !== null && in_array((string) ($user['role'] ?? ''), Auth::PLATFORM_ROLES, true);
        if ($hotelId === false) {
            $hotelId = self::$platformScope ? null : Tenant::current();
        }
        if ($platformActor && in_array($action, self::PLATFORM_LEVEL_ACTIONS, true)) {
            $hotelId = null;
        }
        $row = [
            'hotel_id' => $hotelId,
            'user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? (PHP_SAPI === 'cli' ? 'system' : null),
            'action' => substr($action, 0, 60),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => mb_substr($details, 0, 1000),
            'ip_address' => PHP_SAPI === 'cli' ? null : client_ip(),
            'created_at' => now(),
        ];
        if (self::hasActorColumn()) {
            $row['actor_platform'] = $platformActor ? 1 : 0;
        }
        try {
            DB::insert('activity_logs', $row);
        } catch (Throwable $e) {
            Logger::error('Activity log failed: ' . $e->getMessage());
        }
    }

    /**
     * SQL condition for a customer's own log views: hide rows of platform users. $alias: table alias
     * with dot ('a.') or ''. Empty before migration 033.
     */
    public static function customerFilter(string $alias = ''): string
    {
        return self::hasActorColumn() ? ' AND ' . $alias . 'actor_platform = 0' : '';
    }

    /** Does activity_logs have the actor_platform column (migration 033)? Cached per request. */
    public static function hasActorColumn(): bool
    {
        if (self::$hasActor === null) {
            try {
                self::$hasActor = (bool) DB::value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'actor_platform'"
                );
            } catch (Throwable) {
                self::$hasActor = false;
            }
        }
        return self::$hasActor;
    }

    /** Test hook / after migrations: forget the cached column check. */
    public static function forget(): void
    {
        self::$hasActor = null;
    }
}
