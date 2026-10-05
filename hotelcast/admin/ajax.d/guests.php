<?php
/**
 * Guest module AJAX actions (admin/ajax.php?action=guests_<name>):
 *   guests_alerts          GET   live counters + orders/requests newer than ?order=&request= (toasts, badge)
 *   guests_board           GET   orders & requests board
 *   guests_order_status    POST  {id, status, notify_tv?}
 *   guests_request_status  POST  {id, status, notify_tv?}
 * Session auth, CSRF and the read-only rule are applied by admin/ajax.php.
 */
declare(strict_types=1);

if (!in_array($action, ['guests_alerts', 'guests_board', 'guests_order_status', 'guests_request_status'], true)) {
    return;
}
require_can('services.manage');
if (!GuestServices::enabled()) {
    ajax_error(__('Guest services are not part of this hotel\'s plan.'), 403, 'FEATURE_DISABLED');
}

switch ($action) {
    case 'guests_alerts':
        $since = GuestServices::newSince(req_int('order', $_GET), req_int('request', $_GET));
        ajax_ok(GuestServices::alertCounts() + $since);

    case 'guests_board':
        ajax_ok(GuestServices::board());

    case 'guests_order_status':
        $needPost();
        $id = is_int($in['id'] ?? null) ? $in['id'] : (int) ($in['id'] ?? 0);
        $status = is_string($in['status'] ?? null) ? $in['status'] : '';
        $notify = array_key_exists('notify_tv', $in) ? (bool) $in['notify_tv'] : null;
        $o = GuestServices::setOrderStatus($id, $status, Auth::id(), $notify);
        ajax_ok(['id' => (int) $o['id'], 'status' => $o['status']] + GuestServices::alertCounts());

    case 'guests_request_status':
        $needPost();
        $id = is_int($in['id'] ?? null) ? $in['id'] : (int) ($in['id'] ?? 0);
        $status = is_string($in['status'] ?? null) ? $in['status'] : '';
        $notify = array_key_exists('notify_tv', $in) ? (bool) $in['notify_tv'] : null;
        $r = GuestServices::setRequestStatus($id, $status, Auth::id(), $notify);
        ajax_ok(['id' => (int) $r['id'], 'status' => $r['status']] + GuestServices::alertCounts());
}
