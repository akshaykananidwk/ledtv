<?php
declare(strict_types=1);

/** Per-user audit trail. */
final class ActivityLog
{
    public static function add(string $action, ?string $entityType = null, ?int $entityId = null, string $details = ''): void
    {
        $user = Auth::user();
        try {
            DB::insert('activity_logs', [
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
