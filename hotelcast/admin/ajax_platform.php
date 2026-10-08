<?php
declare(strict_types=1);
/**
 * 2.6 panels (docs/modules/panels.md): one-click switches of the Super Admin console / Reseller panel.
 * Included by admin/ajax.php for action=platform_toggle (POST JSON {kind, target, on, ...}).
 * Every switch checks the permission and the user's customer scope, writes an activity log row
 * (platform scope + the customer's own log where it concerns a customer) and returns {on: bool}.
 *
 *   customer_status  target = customer id, on = active / off = suspended        platform.manage
 *   feature          target = customer id, feature = key                        platform.manage
 *   user_active      target = user id (customer user in scope)                  platform.screens
 *   screen_enabled   target = screen (room) id, customer = id (in scope)        platform.screens
 *   plan_active      target = plan id                                           platform.manage
 *   reseller_status  target = reseller id                                       platform.manage
 *   signup_open      target = 0 (platform setting signup_enabled)               signup.manage
 *   pool_enabled     target = 0 (platform registration / unassigned pool)       platform.pool
 */

if (!isset($in) || !is_array($in) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ajax_error('POST required', 405, 'METHOD_NOT_ALLOWED');
}
ActivityLog::$platformScope = true;

$kind = is_string($in['kind'] ?? null) ? $in['kind'] : '';
$target = isset($in['target']) && is_numeric($in['target']) ? (int) $in['target'] : 0;
$on = !empty($in['on']);

/** A customer the current user may manage, or 404-ish error. */
$customer = static function (int $id): array {
    $h = $id ? Hotels::find($id) : null;
    if (!$h || !PlatformScreens::canSeeHotel($id)) {
        Logger::write('security', 'warning', 'Platform switch outside scope', ['user' => Auth::id(), 'hotel' => $id]);
        ajax_error(__('Customer not found.'), 404, 'NOT_FOUND');
    }
    return $h;
};

switch ($kind) {
    case 'customer_status':
        require_can('platform.manage');
        $h = $customer($target);
        $status = $on ? 'active' : 'suspended';
        Hotels::setStatus($target, $status, 'manual');
        ActivityLog::add('hotel_status', 'hotel', $target, $h['name'] . ' → ' . $status);
        ActivityLog::add('hotel_status', 'hotel', $target, 'Account ' . $status . ' by the platform', $target);
        ajax_ok(['on' => $on, 'status' => $status, 'badge' => Hotels::statusBadge(Tenant::state($target))]);

    case 'feature':
        require_can('platform.manage');
        $h = $customer($target);
        $key = is_string($in['feature'] ?? null) ? $in['feature'] : '';
        if (!Features::exists($key) || Features::isCore($key)) {
            ajax_error(__('Unknown feature.'), 422, 'VALIDATION_ERROR');
        }
        $o = Features::overrides($target);
        $planKeys = Features::planKeys(Tenant::hotel($target)['plan_features'] ?? null);
        $inPlan = in_array($key, $planKeys, true);
        $add = array_values(array_diff($o['add'], [$key]));
        $remove = array_values(array_diff($o['remove'], [$key]));
        if ($on && !$inPlan) {
            $add[] = $key;
        } elseif (!$on && $inPlan) {
            $remove[] = $key;
        }
        Hotels::update($target, ['feature_overrides' => Features::encodeOverrides($add, $remove)]);
        Features::forget();
        Tenant::forget($target);
        $effective = Features::enabled($key, $target);
        ActivityLog::add('hotel_feature', 'hotel', $target, $h['name'] . ': ' . $key . ' ' . ($on ? 'on' : 'off'));
        ActivityLog::add('hotel_feature', 'hotel', $target, 'Feature ' . $key . ' switched ' . ($on ? 'on' : 'off') . ' by the platform', $target);
        ajax_ok(['on' => $effective, 'in_plan' => $inPlan, 'override' => in_array($key, $add, true) ? 'add' : (in_array($key, $remove, true) ? 'remove' : ''),
            'warning' => $on && !$effective ? __('Switched on, but a feature it depends on is off.') : '']);

    case 'user_active':
        require_can('platform.screens');
        $u = $target ? DB::one("SELECT * FROM users WHERE id = :u AND hotel_id IS NOT NULL AND role NOT IN ('platform_admin','reseller','chain_admin')", ['u' => $target]) : null;
        if (!$u) {
            ajax_error(__('User not found.'), 404, 'NOT_FOUND');
        }
        $hid = (int) $u['hotel_id'];
        $customer($hid);
        DB::query('UPDATE users SET is_active = :a, failed_attempts = 0, locked_until = NULL WHERE id = :id AND hotel_id = :h', ['a' => $on ? 1 : 0, 'id' => $target, 'h' => $hid]);
        if (!$on) {
            Auth::revokeUserSessions($target);
        }
        ActivityLog::add($on ? 'user_activate' : 'user_deactivate', 'user', $target, $u['username'] . ' of customer #' . $hid);
        ActivityLog::add($on ? 'user_activate' : 'user_deactivate', 'user', $target, $u['username'] . ' by the platform', $hid);
        ajax_ok(['on' => $on]);

    case 'screen_enabled':
        require_can('platform.screens');
        $hid = isset($in['customer']) && is_numeric($in['customer']) ? (int) $in['customer'] : 0;
        $customer($hid);
        $room = $target ? DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $target, 'h' => $hid]) : null;
        if (!$room) {
            ajax_error(__('Screen not found.'), 404, 'NOT_FOUND');
        }
        Tenant::run($hid, static function () use ($target, $on): void {
            DB::update('rooms', ['is_enabled' => $on ? 1 : 0], 'id = :id', ['id' => $target]);
            Settings::bumpContentVersion();
        });
        ActivityLog::add($on ? 'room_enable' : 'room_disable', 'room', $target, 'Screen ' . $room['room_number'] . ' of customer #' . $hid);
        ActivityLog::add($on ? 'room_enable' : 'room_disable', 'room', $target, 'Screen ' . $room['room_number'] . ' switched ' . ($on ? 'on' : 'off') . ' by the platform', $hid);
        ajax_ok(['on' => $on]);

    case 'plan_active':
        require_can('platform.manage');
        $p = $target ? DB::one('SELECT * FROM plans WHERE id = :id', ['id' => $target]) : null;
        if (!$p) {
            ajax_error(__('Plan not found.'), 404, 'NOT_FOUND');
        }
        DB::update('plans', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $target]);
        ActivityLog::add('plan_update', 'plan', $target, $p['name'] . ($on ? ' activated' : ' deactivated'));
        ajax_ok(['on' => $on]);

    case 'reseller_status':
        require_can('platform.manage');
        $r = $target ? DB::one('SELECT * FROM resellers WHERE id = :id', ['id' => $target]) : null;
        if (!$r) {
            ajax_error(__('Reseller not found.'), 404, 'NOT_FOUND');
        }
        DB::update('resellers', ['status' => $on ? 'active' : 'suspended'], 'id = :id', ['id' => $target]);
        if (!$on) {
            foreach (DB::all("SELECT id FROM users WHERE reseller_id = :r AND role = 'reseller'", ['r' => $target]) as $ru) {
                Auth::revokeUserSessions((int) $ru['id']);
            }
        }
        Branding::flush();
        ActivityLog::add('reseller_update', 'reseller', $target, $r['name'] . ($on ? ' activated' : ' suspended'));
        ajax_ok(['on' => $on]);

    case 'signup_open':
        require_can('signup.manage');
        Settings::setPlatform('signup_enabled', $on ? '1' : '0');
        ActivityLog::add('signup_settings', 'settings', null, 'Online sign-up ' . ($on ? 'opened' : 'closed'));
        ajax_ok(['on' => $on]);

    case 'pool_enabled':
        require_can('platform.pool');
        DevicePool::setEnabled($on);
        ActivityLog::add('pool_settings', 'settings', null, 'Unassigned pool ' . ($on ? 'enabled' : 'disabled'));
        ajax_ok(['on' => $on]);
}
ajax_error(__('Unknown action.'), 422, 'VALIDATION_ERROR');
