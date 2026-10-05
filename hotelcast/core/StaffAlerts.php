<?php
declare(strict_types=1);

/**
 * Staff alerts (#14): web push to the phones / PCs of hotel staff, plus email / WhatsApp through
 * Notifier when the hotel enabled that channel for the alert type.
 *
 * Public API for other modules (guests / room service, support, …):
 *
 *   StaffAlerts::send('services.manage', 'New order · Room 101', '2 × Masala tea', admin_url('orders.php'), 'orders');
 *
 * → every opted-in user of the CURRENT hotel whose role has the permission gets a push message on
 *   each subscribed device (unless the user switched that alert type off for the device). Returns the
 *   number of push messages accepted by the push services. Never throws (errors are logged).
 *
 * Alert types are what users choose on Admin → Notifications; modules may add their own with
 * StaffAlerts::registerType() in a boot.d file. A type that is not registered is always delivered.
 */
final class StaffAlerts
{
    /** @var array<string, array{label:string, permission:string}> */
    private static array $types = [];
    private static bool $defaultsLoaded = false;

    /** Built-in alert types: key => [English label (translated on output), permission]. */
    private const DEFAULT_TYPES = [
        'orders' => ['New room service orders', 'services.manage'],
        'requests' => ['New guest requests', 'services.manage'],
        'tv_offline' => ['TV went offline', 'rooms.view'],
        'emergency' => ['Emergency message started', 'dashboard.view'],
        'support' => ['TV crashes', 'support.view'],
        'platform' => ['Platform: crash spikes', 'support.platform'],
    ];

    public static function registerType(string $type, string $label, string $permission): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,40}$/', $type)) {
            self::$types[$type] = ['label' => $label, 'permission' => $permission];
        }
    }

    /** All alert types: key => ['label' => translated label, 'permission' => …]. */
    public static function types(): array
    {
        if (!self::$defaultsLoaded) {
            self::$defaultsLoaded = true;
            foreach (self::DEFAULT_TYPES as $k => [$label, $perm]) {
                self::$types += [$k => ['label' => $label, 'permission' => $perm]];
            }
        }
        $out = [];
        foreach (self::$types as $k => $t) {
            $out[$k] = ['label' => __($t['label']), 'permission' => $t['permission']];
        }
        return $out;
    }

    /** Alert types the current user may receive (for the opt-in page). */
    public static function typesForCurrentUser(): array
    {
        return array_filter(self::types(), static fn ($t) => Auth::can($t['permission']));
    }

    /**
     * Push (+ email / WhatsApp per alert type) to the current hotel's staff with $permission.
     * $url: page opened when the notification is clicked (absolute, or relative to admin/).
     * $type: alert type key (defaults to the permission name).
     */
    public static function send(string $permission, string $title, string $body, string $url = '', ?string $type = null): int
    {
        $type = $type !== null && $type !== '' ? $type : $permission;
        try {
            $hid = Tenant::id();
            self::sendChannels($type, $title, $body);
            if (!Tenant::feature('pwa', $hid)) {
                return 0;
            }
            $subs = DB::all(
                'SELECT s.*, u.role FROM push_subscriptions s JOIN users u ON u.id = s.user_id
                 WHERE u.hotel_id = :h AND u.is_active = 1',
                ['h' => $hid]
            );
            $subs = array_values(array_filter($subs, static fn ($s) => Auth::roleCan((string) $s['role'], $permission)));
            return self::deliver($subs, $type, $title, $body, $url, $hid);
        } catch (Throwable $e) {
            Logger::error('StaffAlerts::send failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Platform alert (no hotel context needed): push to every platform admin, plus email to the
     * platform notification address and WhatsApp (billing gateway) to the platform support phone.
     */
    public static function sendPlatform(string $title, string $body, string $url = '', string $type = 'platform'): int
    {
        try {
            $email = trim((string) Settings::platform('platform_notify_email', ''));
            $phone = trim((string) Settings::platform('platform_support_phone', ''));
            if ($email !== '' || $phone !== '') {
                Notifier::sendToContact($email, $phone, $title, $title . "\n" . $body . ($url !== '' ? "\n" . self::absUrl($url) : ''));
            }
            $subs = DB::all("SELECT s.*, u.role FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE u.role = 'platform_admin' AND u.is_active = 1");
            return self::deliver($subs, $type, $title, $body, $url, null);
        } catch (Throwable $e) {
            Logger::error('StaffAlerts::sendPlatform failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Send a test notification to every device of one user. Returns [sent, failed, errors[]]. */
    public static function test(int $userId): array
    {
        $subs = DB::all('SELECT s.*, u.role FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.user_id = :u', ['u' => $userId]);
        $errors = [];
        $sent = self::deliver($subs, '', __('Test notification'), __('Notifications work on this device.'), 'index.php', null, $errors, true);
        return [$sent, count($subs) - $sent, $errors];
    }

    /** Is $type enabled for this subscription (NULL = all types)? */
    public static function wants(array $sub, string $type): bool
    {
        if ($type === '' || $sub['alert_types'] === null || $sub['alert_types'] === '') {
            return true;
        }
        if (!isset(self::types()[$type])) {
            return true; // unregistered type: the user cannot switch it off, so deliver
        }
        return in_array($type, array_map('trim', explode(',', (string) $sub['alert_types'])), true);
    }

    /** Hotel setting alert_email_types / alert_whatsapp_types: comma separated alert types. */
    public static function channelTypes(string $channel): array
    {
        $v = (string) Settings::get('alert_' . $channel . '_types', '');
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }

    private static function sendChannels(string $type, string $title, string $body): void
    {
        $message = $title . "\n" . $body;
        $email = trim((string) Settings::get('notify_email', ''));
        if ($email !== '' && in_array($type, self::channelTypes('email'), true)) {
            Notifier::email($email, $title, $message);
        }
        $wa = trim((string) Settings::get('notify_whatsapp_url', ''));
        if ($wa !== '' && in_array($type, self::channelTypes('whatsapp'), true)) {
            Notifier::webhook($wa, $message);
        }
    }

    private static function absUrl(string $url): string
    {
        if ($url === '') {
            return admin_url('index.php');
        }
        return preg_match('#^https?://#i', $url) ? $url : admin_url(ltrim($url, '/'));
    }

    /** Encrypt + send to each subscription; removes gone subscriptions. Returns the number delivered. */
    private static function deliver(array $subs, string $type, string $title, string $body, string $url, ?int $hotelId, array &$errors = [], bool $force = false): int
    {
        if (!$subs || !WebPush::supported()) {
            if ($subs) {
                $errors[] = 'Web push is not supported by this server (openssl EC missing)';
            }
            return 0;
        }
        $payload = [
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'url' => self::absUrl($url),
            'tag' => $type !== '' ? $type : 'hotelcast',
            'type' => $type,
            'hotel' => $hotelId,
            'icon' => admin_url('pwa_icon.php', ['s' => 192]),
            'ts' => time(),
        ];
        $json = json_out($payload);
        while (strlen($json) > WebPush::MAX_PAYLOAD - 64 && mb_strlen($payload['body']) > 20) {
            $payload['body'] = mb_substr($payload['body'], 0, (int) (mb_strlen($payload['body']) * 0.7)) . '…';
            $json = json_out($payload);
        }
        $sent = 0;
        foreach ($subs as $s) {
            if (!$force && !self::wants($s, $type)) {
                continue;
            }
            $r = WebPush::send($s, $json, ['ttl' => 3600 * 12, 'urgency' => in_array($type, ['emergency', 'orders', 'requests'], true) ? 'high' : 'normal']);
            if ($r['ok']) {
                $sent++;
                DB::query('UPDATE push_subscriptions SET last_used = :n, failures = 0, last_error = NULL WHERE id = :id', ['n' => now(), 'id' => $s['id']]);
            } elseif ($r['gone']) {
                DB::query('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $s['id']]);
                Logger::write('push', 'info', 'Subscription removed (' . ($r['error'] ?? $r['status']) . ')', ['user' => $s['user_id']]);
            } else {
                $errors[] = (string) $r['error'];
                DB::query('UPDATE push_subscriptions SET failures = failures + 1, last_error = :e WHERE id = :id', ['e' => mb_substr((string) $r['error'], 0, 255), 'id' => $s['id']]);
            }
        }
        if ($subs) {
            Logger::write('push', 'info', 'Alert "' . mb_substr($title, 0, 80) . '"', ['type' => $type, 'hotel' => $hotelId, 'sent' => $sent, 'subs' => count($subs)]);
        }
        return $sent;
    }
}
