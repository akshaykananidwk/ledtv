<?php
declare(strict_types=1);

/**
 * Web Push in pure PHP (no Composer): message encryption per RFC 8291 (ECDH P-256 + HKDF +
 * "aes128gcm" content coding, RFC 8188) and VAPID authentication per RFC 8292 (ES256 JWT).
 * Needs the openssl extension with EC support (openssl_pkey_derive, prime256v1, aes-128-gcm);
 * WebPush::supported() is false otherwise and callers fall back to email / WhatsApp / in-page toasts.
 *
 * The VAPID key pair is generated on first use and stored in the platform settings (hotel 0):
 * platform_vapid_public (base64url, uncompressed point) and platform_vapid_private (PEM,
 * encrypted with Crypto / APP_KEY).
 */
final class WebPush
{
    /** Record size written in the aes128gcm header (single record). */
    public const RECORD_SIZE = 4096;
    /** Largest payload that fits one record of a 4096-byte push message (4096 - 86 header - 16 tag - 1 delimiter). */
    public const MAX_PAYLOAD = 3993;
    public const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /**
     * Test / integration hook: fn(string $url, array $headers, string $body): array{status:int, body?:string, error?:?string}.
     * When null the request is sent with Http::request (cURL).
     */
    public static $transport = null;

    private const SPKI_P256_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    // ------------------------------------------------------------------ capability

    /** True when openssl can do ECDH on P-256 and AES-128-GCM. */
    public static function supported(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = extension_loaded('openssl')
                && function_exists('openssl_pkey_derive')
                && in_array('prime256v1', openssl_get_curve_names() ?: [], true)
                && in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
        }
        return $ok;
    }

    // ------------------------------------------------------------------ base64url

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr(trim($s), '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        if ($out === false) {
            throw new InvalidArgumentException('Invalid base64url value');
        }
        return $out;
    }

    // ------------------------------------------------------------------ keys

    /** Generate a P-256 key pair: ['private_pem' => PEM, 'public' => 65-byte uncompressed point]. */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not create an EC key (openssl: ' . (openssl_error_string() ?: 'unknown error') . ')');
        }
        return ['private_pem' => $pem, 'public' => self::publicKeyRaw($key)];
    }

    /** Uncompressed public point (0x04 || X || Y) of an EC key. */
    public static function publicKeyRaw(OpenSSLAsymmetricKey|string $key): string
    {
        $k = is_string($key) ? openssl_pkey_get_private($key) : $key;
        if ($k === false) {
            throw new InvalidArgumentException('Invalid EC private key');
        }
        $d = openssl_pkey_get_details($k);
        if (!isset($d['ec']['x'], $d['ec']['y'])) {
            throw new InvalidArgumentException('Not an EC key');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** PEM (SEC1) private key from the raw 32-byte scalar and its uncompressed public point. */
    public static function privateKeyFromRaw(string $d, string $publicRaw): string
    {
        if (strlen($d) !== 32 || strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new InvalidArgumentException('Invalid raw P-256 key');
        }
        // ECPrivateKey ::= SEQUENCE { 1, OCTET STRING d, [0] prime256v1, [1] BIT STRING publicKey }
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $publicRaw;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** PEM public key (SubjectPublicKeyInfo) from an uncompressed P-256 point. */
    public static function publicKeyPem(string $publicRaw): string
    {
        if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new InvalidArgumentException('Invalid P-256 public key (expected 65 bytes, uncompressed)');
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(self::SPKI_P256_PREFIX . $publicRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** ECDH shared secret (32 bytes, X coordinate) between our private key and a peer public point. */
    public static function ecdh(string $privatePem, string $peerPublicRaw): string
    {
        $peer = openssl_pkey_get_public(self::publicKeyPem($peerPublicRaw));
        $priv = openssl_pkey_get_private($privatePem);
        if ($peer === false || $priv === false) {
            throw new InvalidArgumentException('Invalid key for ECDH (is the subscription key a valid P-256 point?)');
        }
        $secret = openssl_pkey_derive($peer, $priv, 32);
        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException('ECDH failed: ' . (openssl_error_string() ?: 'unknown error'));
        }
        return $secret;
    }

    /** VAPID key pair of the platform (created and stored on first use). */
    public static function vapidKeys(): array
    {
        $pub = (string) Settings::platform('platform_vapid_public', '');
        $privEnc = (string) Settings::platform('platform_vapid_private', '');
        if ($pub === '' || $privEnc === '') {
            if (!self::supported()) {
                throw new RuntimeException('Web push needs the PHP openssl extension with EC (P-256) support.');
            }
            $kp = self::generateKeyPair();
            // INSERT IGNORE: two requests creating keys at the same time agree on the first one.
            DB::query("INSERT IGNORE INTO system_settings (hotel_id, setting_key, setting_value) VALUES (0, 'platform_vapid_public', :v)", ['v' => self::b64uEncode($kp['public'])]);
            DB::query("INSERT IGNORE INTO system_settings (hotel_id, setting_key, setting_value) VALUES (0, 'platform_vapid_private', :v)", ['v' => Crypto::encrypt($kp['private_pem'])]);
            Settings::flush();
            $pub = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_vapid_public'");
            $privEnc = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_vapid_private'");
            Logger::write('push', 'info', 'VAPID key pair created');
        }
        $pem = Crypto::decrypt($privEnc);
        if ($pem === null || $pem === '') {
            throw new RuntimeException('VAPID private key cannot be decrypted (APP_KEY changed?). Reset it in the platform settings.');
        }
        return ['public' => $pub, 'private_pem' => $pem];
    }

    /** Public VAPID key (base64url) for PushManager.subscribe({applicationServerKey}). */
    public static function publicKey(): string
    {
        return self::vapidKeys()['public'];
    }

    /** Forget the VAPID keys (all existing subscriptions become invalid). */
    public static function resetKeys(): void
    {
        DB::query("DELETE FROM system_settings WHERE hotel_id = 0 AND setting_key IN ('platform_vapid_public', 'platform_vapid_private')");
        DB::query('DELETE FROM push_subscriptions');
        Settings::flush();
    }

    // ------------------------------------------------------------------ RFC 8291 encryption

    /**
     * Key derivation of RFC 8291 §3.4 / RFC 8188 §2.2 (exposed for the test vectors).
     * Returns ecdh_secret, prk_key, ikm, prk, cek, nonce (binary).
     */
    public static function deriveKeys(string $asPrivatePem, string $asPublic, string $uaPublic, string $authSecret, string $salt): array
    {
        $ecdh = self::ecdh($asPrivatePem, $uaPublic);
        $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
        $keyInfo = "WebPush: info\0" . $uaPublic . $asPublic;
        $ikm = substr(hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true), 0, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        return ['ecdh_secret' => $ecdh, 'prk_key' => $prkKey, 'ikm' => $ikm, 'prk' => $prk, 'cek' => $cek, 'nonce' => $nonce];
    }

    /**
     * Encrypt a push message for a subscription (RFC 8291, aes128gcm, one record).
     * $uaPublic = subscription keys.p256dh (65 bytes), $authSecret = keys.auth (16 bytes).
     * $asPrivatePem / $salt are only given by tests; normally a fresh ephemeral key and salt are used.
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivatePem = null, ?string $salt = null, int $padding = 0): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new InvalidArgumentException('Subscription key p256dh must be an uncompressed P-256 point');
        }
        if (strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('Subscription auth secret must be 16 bytes');
        }
        if (strlen($payload) + $padding > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('Push payload too large (max ' . self::MAX_PAYLOAD . ' bytes)');
        }
        if ($asPrivatePem === null) {
            $kp = self::generateKeyPair();
            $asPrivatePem = $kp['private_pem'];
            $asPublic = $kp['public'];
        } else {
            $asPublic = self::publicKeyRaw($asPrivatePem);
        }
        $salt ??= random_bytes(16);
        $k = self::deriveKeys($asPrivatePem, $asPublic, $uaPublic, $authSecret, $salt);
        // Single (= last) record: payload || 0x02 || zero padding.
        $plain = $payload . "\x02" . str_repeat("\0", max(0, $padding));
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-128-gcm', $k['cek'], OPENSSL_RAW_DATA, $k['nonce'], $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-128-GCM encryption failed');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /**
     * Decrypt an aes128gcm push message with the user agent's keys (used by tests to round-trip).
     * Returns the payload without padding.
     */
    public static function decrypt(string $body, string $uaPrivatePem, string $authSecret): string
    {
        if (strlen($body) < 21 + 65 + 17) {
            throw new InvalidArgumentException('Message too short');
        }
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $rest = substr($body, 21 + $idLen);
        $uaPublic = self::publicKeyRaw($uaPrivatePem);
        // Same derivation from the other side: ECDH(ua_private, as_public) == ECDH(as_private, ua_public).
        $ecdh = self::ecdh($uaPrivatePem, $asPublic);
        $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
        $ikm = substr(hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true), 0, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }
        $plain = rtrim($plain, "\0");
        if (!str_ends_with($plain, "\x02")) {
            throw new RuntimeException('Invalid padding delimiter');
        }
        return substr($plain, 0, -1);
    }

    // ------------------------------------------------------------------ RFC 8292 VAPID

    /** ECDSA DER signature (SEQUENCE { INTEGER r, INTEGER s }) → raw r || s (64 bytes) for JWS ES256. */
    public static function derToRaw(string $der, int $partLen = 32): string
    {
        $pos = 0;
        $readLen = static function () use ($der, &$pos): int {
            $len = ord($der[$pos++] ?? "\0");
            if ($len & 0x80) {
                $n = $len & 0x7f;
                $len = 0;
                for ($i = 0; $i < $n; $i++) {
                    $len = ($len << 8) | ord($der[$pos++] ?? "\0");
                }
            }
            return $len;
        };
        if (($der[$pos++] ?? '') !== "\x30") {
            throw new InvalidArgumentException('Invalid DER signature');
        }
        $readLen();
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (($der[$pos++] ?? '') !== "\x02") {
                throw new InvalidArgumentException('Invalid DER signature');
            }
            $len = $readLen();
            $int = ltrim(substr($der, $pos, $len), "\0");
            $pos += $len;
            if (strlen($int) > $partLen) {
                throw new InvalidArgumentException('Invalid DER signature');
            }
            $out .= str_pad($int, $partLen, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Raw r || s → DER (for verifying with openssl_verify). */
    public static function rawToDer(string $raw): string
    {
        $parts = '';
        foreach (str_split($raw, intdiv(strlen($raw), 2)) as $p) {
            $p = ltrim($p, "\0");
            if ($p === '' || ord($p[0]) & 0x80) {
                $p = "\0" . $p;
            }
            $parts .= "\x02" . chr(strlen($p)) . $p;
        }
        return "\x30" . chr(strlen($parts)) . $parts;
    }

    /** Signed VAPID JWT (ES256) for the push service origin of $endpoint. */
    public static function vapidJwt(string $endpoint, string $privatePem, string $subject, ?int $exp = null): string
    {
        $p = parse_url($endpoint);
        if (!isset($p['scheme'], $p['host'])) {
            throw new InvalidArgumentException('Invalid push endpoint');
        }
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $header = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64uEncode(json_encode(['aud' => $aud, 'exp' => $exp ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed');
        }
        return $input . '.' . self::b64uEncode(self::derToRaw($der));
    }

    /** "mailto:" contact sent in the VAPID claims (platform support / notification email). */
    public static function subject(): string
    {
        foreach (['platform_support_email', 'platform_notify_email', 'platform_from_email'] as $k) {
            $m = trim((string) Settings::platform($k, ''));
            if (filter_var($m, FILTER_VALIDATE_EMAIL)) {
                return 'mailto:' . $m;
            }
        }
        $host = (string) (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
        return 'mailto:admin@' . (str_contains($host, '.') ? $host : 'example.com');
    }

    // ------------------------------------------------------------------ sending

    /** Push endpoints must be https URLs of a public host (no IP literals of private networks). */
    public static function validEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 1000 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'])) {
            return false;
        }
        $host = trim((string) $p['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return strtolower($host) !== 'localhost';
    }

    /**
     * Send one push message.
     * $subscription: ['endpoint', 'p256dh' (base64url), 'auth' (base64url)].
     * $opts: ttl (seconds, default 86400), urgency (very-low|low|normal|high), topic (≤ 32 url-safe chars).
     * Returns ['ok' => bool, 'status' => int, 'gone' => bool (404/410: remove the subscription), 'error' => ?string].
     */
    public static function send(array $subscription, string $payload, array $opts = []): array
    {
        if (!self::supported()) {
            return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'Web push is not supported by this server (openssl EC missing)'];
        }
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        if (!self::validEndpoint($endpoint)) {
            return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'Invalid endpoint'];
        }
        try {
            $keys = self::vapidKeys();
            $body = self::encrypt($payload, self::b64uDecode((string) ($subscription['p256dh'] ?? '')), self::b64uDecode((string) ($subscription['auth'] ?? '')));
            $jwt = self::vapidJwt($endpoint, $keys['private_pem'], self::subject());
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'gone' => $e instanceof InvalidArgumentException, 'error' => $e->getMessage()];
        }
        $ttl = max(0, min(2419200, (int) ($opts['ttl'] ?? 86400)));
        $urgency = in_array($opts['urgency'] ?? 'normal', self::URGENCIES, true) ? $opts['urgency'] ?? 'normal' : 'normal';
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: ' . $urgency,
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
        ];
        if (!empty($opts['topic']) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) $opts['topic'])) {
            $headers[] = 'Topic: ' . $opts['topic'];
        }
        $res = self::$transport
            ? (self::$transport)($endpoint, $headers, $body)
            : Http::request('POST', $endpoint, $headers, $body, 10);
        $status = (int) ($res['status'] ?? 0);
        $ok = $status >= 200 && $status < 300;
        return [
            'ok' => $ok,
            'status' => $status,
            'gone' => $status === 404 || $status === 410,
            'error' => $ok ? null : (($res['error'] ?? null) ?: ('HTTP ' . $status . ' ' . mb_substr(trim(strip_tags((string) ($res['body'] ?? ''))), 0, 150))),
        ];
    }
}
