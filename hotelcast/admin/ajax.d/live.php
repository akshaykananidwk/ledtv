<?php
/**
 * 2.4 live screen view (admin/ajax.php?action=live_…), support.view (manager+), current hotel and the
 * user's TVs only (Access):
 *   live_start POST {device_id}  → status (queues LIVE_VIEW for the TV)
 *   live_poll  GET  device_id    → status + keepalive (+2 minutes)
 *   live_stop  POST {device_id}  → {stopped}
 * status = {active, frame: {at, age_sec, size, width, height, n} | null, command: {status, message} | null, expires_in}
 */
declare(strict_types=1);

if ($action === 'live_start') {
    $needPost();
    require_can('support.view');
    ajax_ok(LiveView::start((int) ($in['device_id'] ?? 0), Auth::id()));
}
if ($action === 'live_poll') {
    require_can('support.view');
    ajax_ok(LiveView::status(req_int('device_id', $in)));
}
if ($action === 'live_stop') {
    $needPost();
    require_can('support.view');
    ajax_ok(['stopped' => LiveView::stop((int) ($in['device_id'] ?? 0))]);
}
