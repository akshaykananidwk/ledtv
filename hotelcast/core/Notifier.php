<?php
declare(strict_types=1);

/** Email / WhatsApp notifications: TVs offline (per hotel settings), billing reminders (platform). */
final class Notifier
{
    /**
     * Notify about devices that went offline. Called by Scheduler::tick() for devices whose
     * offline state has lasted longer than notify_offline_minutes (debounced, once per outage).
     */
    public static function devicesOffline(array $devices): void
    {
        if (!$devices || !Settings::bool('notify_offline')) {
            return;
        }
        $rooms = array_map(fn ($d) => $d['room_number'] ?? ('#' . $d['id']), $devices);
        $hotel = (string) Settings::get('hotel_name', Branding::DEFAULT_PRODUCT);
        $msg = sprintf('[%s] TV offline in room(s): %s (since %s)', $hotel, implode(', ', $rooms), date('d M H:i'));
        self::send(Branding::get()['product'] . ': ' . count($devices) . ' TV(s) offline', $msg);
    }

    /** Called when a device that we reported offline starts polling again. */
    public static function deviceBackOnline(array $device): void
    {
        if (empty($device['offline_notified'])) {
            return;
        }
        DB::query('UPDATE devices SET offline_notified = 0 WHERE id = :id AND hotel_id = :h', ['id' => $device['id'], 'h' => (int) $device['hotel_id']]);
        if (!Settings::bool('notify_offline')) {
            return;
        }
        $room = $device['room_id'] ? (string) DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $device['room_id'], 'h' => (int) $device['hotel_id']]) : '#' . $device['id'];
        $hotel = (string) Settings::get('hotel_name', Branding::DEFAULT_PRODUCT);
        self::send(Branding::get()['product'] . ': TV back online', sprintf('[%s] TV in room %s is back online (%s)', $hotel, $room, date('d M H:i')));
    }

    /** Devices offline for longer than the configured delay that were not yet reported. */
    public static function checkOffline(): int
    {
        if (!Settings::bool('notify_offline')) {
            return 0;
        }
        $mins = max(1, Settings::int('notify_offline_minutes', 5));
        $devices = DB::all(
            "SELECT d.*, r.room_number FROM devices d LEFT JOIN rooms r ON r.id = d.room_id
             WHERE d.hotel_id = :h AND d.status = 'offline' AND d.is_revoked = 0 AND d.offline_notified = 0 AND d.last_ping < :t
               AND d.last_ping > :recent",
            ['t' => date('Y-m-d H:i:s', time() - $mins * 60), 'recent' => date('Y-m-d H:i:s', time() - 86400), 'h' => Tenant::id()]
        );
        if (!$devices) {
            return 0;
        }
        [$in, $p] = DB::in(array_map(fn ($d) => (int) $d['id'], $devices), 'd');
        DB::query("UPDATE devices SET offline_notified = 1 WHERE hotel_id = :h AND id IN $in", $p + ['h' => Tenant::id()]);
        self::devicesOffline($devices);
        return count($devices);
    }

    /** Send through every configured channel. Returns array of channel => result. */
    public static function send(string $subject, string $message): array
    {
        $results = [];
        $email = trim((string) Settings::get('notify_email', ''));
        if ($email !== '') {
            $results['email'] = self::email($email, $subject, $message);
        }
        $wa = trim((string) Settings::get('notify_whatsapp_url', ''));
        if ($wa !== '') {
            $results['whatsapp'] = self::webhook($wa, $message);
        }
        Logger::write('notify', 'info', $subject, ['results' => $results]);
        return $results;
    }

    public static function email(string $toList, string $subject, string $message, string $from = '', string $fromName = ''): bool
    {
        $from = $from !== '' ? $from : (trim((string) Settings::get('notify_from_email', '')) ?: 'no-reply@' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'));
        $fromName = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $fromName !== '' ? $fromName : Branding::get()['product']) ?: 'Krishna Cloud';
        $ok = true;
        foreach (array_filter(array_map('trim', explode(',', $toList))) as $to) {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (self::$mailer) {
                $ok = (bool) (self::$mailer)($to, $subject, $message) && $ok;
                continue;
            }
            $headers = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0\r\n";
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers) && $ok;
        }
        return $ok;
    }

    /**
     * WhatsApp via any HTTP gateway. The URL may contain {message} (URL-encoded in place),
     * e.g. CallMeBot: https://api.callmebot.com/whatsapp.php?phone=91XXXXXXXXXX&text={message}&apikey=XXXX
     * Without {message}, the text is POSTed as JSON {"message": "..."}.
     */
    public static function webhook(string $url, string $message): bool
    {
        if (!ContentManager::validUrl(str_replace('{message}', 'x', $url), ['http', 'https'])) {
            return false;
        }
        if (str_contains($url, '{message}')) {
            $res = Http::get(str_replace('{message}', rawurlencode($message), $url), [], 15);
        } else {
            $res = Http::request('POST', $url, ['Content-Type: application/json'], json_out(['message' => $message]), 15);
        }
        return $res['status'] >= 200 && $res['status'] < 300;
    }

    /**
     * Platform message to a hotel contact (billing reminders, suspension notices):
     * email via the platform sender and/or WhatsApp via billing_whatsapp_url, a gateway URL that may
     * contain {phone} and {message} (e.g. https://api.example.com/send?to={phone}&text={message}).
     * $channels: ['email', 'whatsapp']. Returns channel => bool.
     */
    public static function sendToContact(string $email, string $phone, string $subject, string $message, array $channels = ['email', 'whatsapp']): array
    {
        $results = [];
        if (in_array('email', $channels, true) && $email !== '') {
            $results['email'] = self::email($email, $subject, $message, (string) Settings::platform('platform_from_email', ''), Branding::get(0)['product']);
        }
        $url = trim((string) Settings::platform('billing_whatsapp_url', ''));
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (in_array('whatsapp', $channels, true) && $url !== '' && $digits !== '') {
            $results['whatsapp'] = self::webhook(str_replace('{phone}', $digits, $url), $message);
        }
        Logger::write('notify', 'info', $subject, ['to' => $email, 'phone' => $digits !== '' ? substr($digits, 0, 4) . '…' : '', 'results' => $results]);
        return $results;
    }

    /** Test hook: when set, email() calls this instead of mail(): fn(string $to, string $subject, string $message): bool */
    public static $mailer = null;
}
