<?php
/**
 * Support tools (admin/ajax.php?action=support_…), manager+ (support.view), current hotel only:
 *   support_request POST {device_id, command: SCREENSHOT|UPLOAD_LOGS} → {command_id}
 *   support_status  GET  device_id, command_id                     → {status, message, file, done}
 */
declare(strict_types=1);

if ($action === 'support_request') {
    $needPost();
    require_can('support.view');
    $id = DeviceSupport::request((int) ($in['device_id'] ?? 0), strtoupper((string) ($in['command'] ?? '')), Auth::id());
    ajax_ok(['command_id' => $id]);
}
if ($action === 'support_status') {
    require_can('support.view');
    ajax_ok(DeviceSupport::requestStatus(req_int('device_id', $in), req_int('command_id', $in)));
}
