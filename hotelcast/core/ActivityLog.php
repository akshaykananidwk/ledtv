<?php
declare(strict_types=1);

/**
 * Per-user audit trail, per hotel (activity_logs.hotel_id). Platform-level actions (platform /
 * reseller pages, set ActivityLog::$platformScope = true) are stored with hotel_id NULL so they never
 * show up in a hotel's log.
 */
final class ActivityLog
{
    public static bool $platformScope = false;

    /** $hotelId: false = automatic (current hotel, or NULL in platform scope), null = platform, int = that hotel. */
    public static function add(string $action, ?string $entityType = null, ?int $entityId = null, string $details = '', int|false|null $hotelId = false): void
    {
        $user = Auth::user();
        if ($hotelId === false) {
            $hotelId = self::$platformScope ? null : Tenant::current();
        }
        try {
            DB::insert('activity_logs', [
                'hotel_id' => $hotelId,
                'user_id' => $user['id'] ?? null,
                'username' => $user['username'] ?? (PHP_SAPI === 'cli' ? 'system' : null),
                'action' => substr($action, 0, 60),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => mb_substr($details, 0, 1000),
                'ip_address' => PHP_SAPI === 'cli' ? null : client_ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Logger::error('Activity log failed: ' . $e->getMessage());
        }
    }
}
