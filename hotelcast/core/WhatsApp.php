<?php
declare(strict_types=1);

/**
 * 2.10.4 WhatsApp messages through the platform owner's WhatsApp API (bulk.akdwk.in style:
 * POST JSON {api_key, number, message, session_id, media_url?} to the API URL). Settings: Super Admin →
 * Platform settings → WhatsApp (platform keys whatsapp_api_url, whatsapp_api_key, whatsapp_session_id).
 * Used by Super Admin → WhatsApp message (admin/platform_whatsapp.php) to message every customer's contact number.
 * See docs/modules/whatsapp.md.
 */
final class WhatsApp
{
    public const DEFAULT_URL = 'https://bulk.akdwk.in/api.php';
    public const MAX_MESSAGE = 4000;
    public const MAX_RECIPIENTS = 1000;
    public const LOG_KEY = 'platform_whatsapp_log';

    public static function config(): array
    {
        return [
            'url' => trim((string) Settings::platform('whatsapp_api_url', self::DEFAULT_URL)) ?: self::DEFAULT_URL,
            'key' => trim((string) Settings::platform('whatsapp_api_key', '')),
            'session' => trim((string) Settings::platform('whatsapp_session_id', '')),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['key'] !== '' && $c['session'] !== '';
    }

    /** Save the Super Admin form. Empty key = keep the saved one. @return string[] errors */
    public static function saveConfig(array $in): array
    {
        $url = trim((string) ($in['url'] ?? ''));
        $key = trim((string) ($in['key'] ?? ''));
        $session = trim((string) ($in['session'] ?? ''));
        $e = [];
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://'))) {
            $e[] = __('The WhatsApp API URL must start with https://.');
        }
        if ($key !== '' && !preg_match('/^[A-Za-z0-9._:-]{4,200}$/', $key)) {
            $e[] = __('The WhatsApp API key has invalid characters.');
        }
        if ($session !== '' && !preg_match('/^[A-Za-z0-9._:-]{2,100}$/', $session)) {
            $e[] = __('The WhatsApp session ID has invalid characters.');
        }
        if ($e) {
            return $e;
        }
        Settings::setPlatform('whatsapp_api_url', $url !== '' ? $url : self::DEFAULT_URL);
        if ($key !== '') {
            Settings::setPlatform('whatsapp_api_key', $key);
        }
        Settings::setPlatform('whatsapp_session_id', $session);
        Settings::flush();
        ActivityLog::add('platform_whatsapp_settings', 'settings', null, 'WhatsApp API settings saved', null);
        return [];
    }

    /** Mobile number as the API wants it (country code, digits only); 10-digit Indian numbers get 91. Null = not usable. */
    public static function number(string $raw): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10 && preg_match('/^[6-9]/', $d)) {
            $d = '91' . $d;
        }
        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
    }

    /**
     * Send one message. @return array{ok: bool, error: string}
     */
    public static function send(string $number, string $message, string $mediaUrl = ''): array
    {
        $c = self::config();
        if ($c['key'] === '' || $c['session'] === '') {
            return ['ok' => false, 'error' => 'not configured'];
        }
        $body = ['api_key' => $c['key'], 'number' => $number, 'message' => $message, 'session_id' => $c['session']];
        if ($mediaUrl !== '') {
            $body['media_url'] = $mediaUrl;
        }
        $r = Http::request('POST', $c['url'], ['Content-Type: application/json', 'Accept: application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 15);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $r['status'] . ($r['error'] ?? '' ? ' ' . $r['error'] : '')];
        }
        $j = json_decode((string) $r['body'], true);
        $status = strtolower((string) (is_array($j) ? ($j['status'] ?? ($j['success'] ?? 'ok')) : 'ok'));
        if (in_array($status, ['error', 'failed', 'false', '0'], true) || (is_array($j) && ($j['success'] ?? null) === false)) {
            return ['ok' => false, 'error' => mb_substr((string) ($j['message'] ?? $j['error'] ?? 'refused'), 0, 200)];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Customers with a usable contact number. $filter: all | active | trial | selected ($ids).
     * @return array<int, array{id: int, name: string, contact: string, number: string}>
     */
    public static function recipients(string $filter = 'active', array $ids = []): array
    {
        $where = 'h.archived_at IS NULL';
        $p = [];
        if ($filter === 'active') {
            $where .= " AND h.status = 'active'";
        } elseif ($filter === 'trial') {
            $where .= " AND h.is_trial = 1 AND h.status = 'active'";
        } elseif ($filter === 'selected') {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
            if (!$ids) {
                return [];
            }
            [$in, $p] = DB::in($ids, 'wr');
            $where .= " AND h.id IN $in";
        }
        $out = [];
        $seen = [];
        foreach (DB::all("SELECT h.id, h.name, h.contact_name, h.contact_phone FROM hotels h WHERE $where ORDER BY h.name, h.id", $p) as $h) {
            $n = self::number((string) $h['contact_phone']);
            if ($n === null || isset($seen[$n])) {
                continue; // one message per number
            }
            $seen[$n] = true;
            $out[] = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'contact' => (string) ($h['contact_name'] ?: $h['name']), 'number' => $n];
        }
        return array_slice($out, 0, self::MAX_RECIPIENTS);
    }

    /** Personalise: :name (contact), :business (customer name). */
    public static function render(string $message, array $r): string
    {
        return strtr($message, [':name' => $r['contact'], ':business' => $r['name']]);
    }

    /**
     * Send a message to many customers (sequentially; the API queues them). @return array{sent: int, failed: int, errors: array}
     */
    public static function broadcast(array $recipients, string $message, string $mediaUrl, ?int $userId): array
    {
        @set_time_limit(0);
        $sent = $failed = 0;
        $errors = [];
        foreach ($recipients as $r) {
            $res = self::send($r['number'], self::render($message, $r), $mediaUrl);
            if ($res['ok']) {
                $sent++;
            } else {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = $r['name'] . ' (' . substr($r['number'], 0, -4) . '••••): ' . $res['error'];
                }
                if ($res['error'] === 'not configured') {
                    break;
                }
            }
        }
        $log = json_decode((string) Settings::platform(self::LOG_KEY, '[]'), true) ?: [];
        array_unshift($log, ['at' => now(), 'by' => $userId, 'sent' => $sent, 'failed' => $failed, 'text' => mb_substr($message, 0, 120)]);
        Settings::setPlatform(self::LOG_KEY, json_encode(array_slice($log, 0, 30), JSON_UNESCAPED_UNICODE));
        Settings::flush();
        ActivityLog::add('platform_whatsapp_send', 'whatsapp', null, 'WhatsApp message to ' . $sent . ' customer(s), ' . $failed . ' failed', null);
        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    /** Last broadcasts (newest first). */
    public static function history(): array
    {
        return json_decode((string) Settings::platform(self::LOG_KEY, '[]'), true) ?: [];
    }
}
