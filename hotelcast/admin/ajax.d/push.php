<?php
/**
 * Web push subscriptions of the logged-in user (admin/ajax.php?action=push_…). Every user manages
 * only their own subscriptions (rows are matched by user_id + endpoint hash).
 *   push_status      GET  [endpoint]                          → {supported, public_key, subscribed, types, available, devices}
 *   push_subscribe   POST {endpoint, keys:{p256dh, auth}, types?} → {id}
 *   push_unsubscribe POST {endpoint}                            → {removed}
 *   push_prefs       POST {endpoint, types[]}                   → {types}
 *   push_test        POST                                       → {sent, failed, errors}
 */
declare(strict_types=1);

if (!str_starts_with($action, 'push_')) {
    return;
}
require_can('push.self');
$me = (int) Auth::id();

/** Normalise chosen alert types: NULL = all (also future types), 'none' = nothing. */
$pushTypes = static function (mixed $types): ?string {
    if ($types === null) {
        return null;
    }
    $avail = array_keys(StaffAlerts::typesForCurrentUser());
    $chosen = array_values(array_intersect($avail, array_map('strval', (array) $types)));
    if (count($chosen) === count($avail)) {
        return null;
    }
    return $chosen ? implode(',', $chosen) : 'none';
};
$pushRow = static function (string $endpoint) use ($me): ?array {
    return $endpoint === '' ? null : DB::one('SELECT * FROM push_subscriptions WHERE endpoint_hash = :h AND user_id = :u', ['h' => hash('sha256', $endpoint), 'u' => $me]);
};

switch ($action) {
    case 'push_status':
        $endpoint = is_string($in['endpoint'] ?? null) ? $in['endpoint'] : '';
        $row = $pushRow($endpoint);
        $avail = StaffAlerts::typesForCurrentUser();
        $key = null;
        if (WebPush::supported()) {
            try {
                $key = WebPush::publicKey();
            } catch (Throwable $e) {
                Logger::error('VAPID: ' . $e->getMessage());
            }
        }
        ajax_ok([
            'supported' => $key !== null,
            'public_key' => $key,
            'subscribed' => $row !== null,
            'types' => $row === null || $row['alert_types'] === null ? array_keys($avail) : array_values(array_intersect(array_keys($avail), explode(',', (string) $row['alert_types']))),
            'available' => array_map(static fn ($k, $t) => ['key' => $k, 'label' => $t['label']], array_keys($avail), $avail),
            'devices' => (int) DB::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u', ['u' => $me]),
        ]);

    case 'push_subscribe':
        $needPost();
        $endpoint = is_string($in['endpoint'] ?? null) ? trim($in['endpoint']) : '';
        $p256 = is_string($in['keys']['p256dh'] ?? null) ? trim($in['keys']['p256dh']) : '';
        $auth = is_string($in['keys']['auth'] ?? null) ? trim($in['keys']['auth']) : '';
        if (!WebPush::validEndpoint($endpoint)) {
            ajax_error(__('Invalid push subscription.'), 422, 'VALIDATION_ERROR');
        }
        try {
            $pRaw = WebPush::b64uDecode($p256);
            $aRaw = WebPush::b64uDecode($auth);
        } catch (InvalidArgumentException) {
            $pRaw = $aRaw = '';
        }
        if (strlen($pRaw) !== 65 || $pRaw[0] !== "\x04" || strlen($aRaw) !== 16) {
            ajax_error(__('Invalid push subscription.'), 422, 'VALIDATION_ERROR');
        }
        if ((int) DB::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u', ['u' => $me]) >= 20 && !$pushRow($endpoint)) {
            ajax_error(__('Too many devices. Remove an old device first.'), 422, 'LIMIT');
        }
        $hash = hash('sha256', $endpoint);
        $fields = [
            'hotel_id' => $user['hotel_id'] ? (int) $user['hotel_id'] : null,
            'user_id' => $me,
            'endpoint' => $endpoint,
            'p256dh' => WebPush::b64uEncode($pRaw),
            'auth' => WebPush::b64uEncode($aRaw),
            'alert_types' => array_key_exists('types', $in) ? $pushTypes($in['types']) : null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'failures' => 0,
            'last_error' => null,
        ];
        // The same browser endpoint belongs to the user who subscribed last (shared PC, re-login).
        DB::query('DELETE FROM push_subscriptions WHERE endpoint_hash = :h AND user_id <> :u', ['h' => $hash, 'u' => $me]);
        $existing = $pushRow($endpoint);
        if ($existing) {
            DB::update('push_subscriptions', $fields, 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('push_subscriptions', $fields + ['endpoint_hash' => $hash, 'created_at' => now()]);
            ActivityLog::add('push_subscribe', 'user', $me, 'Enabled notifications on a device');
        }
        ajax_ok(['id' => $id]);

    case 'push_unsubscribe':
        $needPost();
        $endpoint = is_string($in['endpoint'] ?? null) ? $in['endpoint'] : '';
        $n = DB::query('DELETE FROM push_subscriptions WHERE endpoint_hash = :h AND user_id = :u', ['h' => hash('sha256', $endpoint), 'u' => $me])->rowCount();
        ajax_ok(['removed' => $n]);

    case 'push_prefs':
        $needPost();
        $row = $pushRow(is_string($in['endpoint'] ?? null) ? $in['endpoint'] : '');
        if (!$row) {
            ajax_error(__('Notifications are not enabled on this device.'), 404, 'NOT_FOUND');
        }
        $types = $pushTypes((array) ($in['types'] ?? []));
        DB::update('push_subscriptions', ['alert_types' => $types], 'id = :id AND user_id = :u', ['id' => $row['id'], 'u' => $me]);
        ajax_ok(['types' => $types]);

    case 'push_test':
        $needPost();
        [$sent, $failed, $errors] = StaffAlerts::test($me);
        if (!$sent && !$failed) {
            ajax_error(__('Notifications are not enabled on any of your devices.'), 404, 'NOT_FOUND');
        }
        ajax_ok(['sent' => $sent, 'failed' => $failed, 'errors' => array_values(array_unique($errors))]);
}
