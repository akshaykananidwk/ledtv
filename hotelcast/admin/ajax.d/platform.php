<?php
/**
 * AJAX actions with the prefix "platform_" (admin/ajax.php?action=platform_…).
 * Files in admin/ajax.d/<prefix>.php are included by ajax.php for unknown actions; available:
 * $action, $in (JSON body / GET), $method, $user, $needPost(), $parseTarget(). Respond with
 * ajax_ok()/ajax_error(); simply return for actions that are not yours.
 */
declare(strict_types=1);

if ($action === 'platform_stats') {
    require_can('platform.manage');
    ajax_ok([
        'hotels' => (int) DB::value('SELECT COUNT(*) FROM hotels'),
        'active' => (int) DB::value("SELECT COUNT(*) FROM hotels WHERE status = 'active'"),
        'tvs' => (int) DB::value('SELECT COUNT(*) FROM devices WHERE is_revoked = 0 AND room_id IS NOT NULL'),
        'online' => (int) DB::value("SELECT COUNT(*) FROM devices WHERE is_revoked = 0 AND room_id IS NOT NULL AND status = 'online'"),
        'unpaid' => (float) DB::value("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'unpaid'"),
    ]);
}
