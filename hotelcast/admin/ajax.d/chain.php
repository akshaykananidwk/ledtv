<?php
/**
 * AJAX actions with the prefix "chain_" (admin/ajax.php?action=chain_…), hotel chains (#20).
 *   chain_overview?chain=ID  (GET) — per-hotel numbers of the chain dashboard (auto-refresh)
 * Membership is checked by Chains::current() (404 for a chain of someone else).
 */
declare(strict_types=1);

if ($action === 'chain_overview') {
    require_can('chain.view');
    Chains::requireEnabled();
    $chain = Chains::current(req_int('chain', $_GET));
    $ov = Chains::overview((int) $chain['id']);
    ajax_ok([
        'chain' => (int) $chain['id'],
        'totals' => $ov['totals'],
        'hotels' => array_map(static fn ($h) => [
            'id' => $h['id'], 'name' => $h['name'], 'state' => $h['state'], 'tvs' => $h['tvs'], 'online' => $h['online'], 'offline' => $h['offline'],
            'rooms' => $h['rooms'], 'occupancy' => $h['occupancy'], 'plays_today' => $h['plays_today'], 'open_orders' => $h['open_orders'],
            'open_requests' => $h['open_requests'], 'feedback_avg' => $h['feedback_avg'], 'invoice_status' => $h['invoice_status'],
        ], $ov['hotels']),
    ]);
}
