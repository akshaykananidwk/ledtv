<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WebPush (core/WebPush.php): RFC 8291 Appendix A test vector (exact output and every intermediate
 * key), round trips with random keys, VAPID ES256 JWT (DER ↔ raw signature conversion, verifiable
 * with the public key), endpoint validation and the request built for the push service.
 */
final class WebPushTest extends TestCase
{
    // RFC 8291 Appendix A
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';
    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const EXPECTED = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private static function d(string $s): string
    {
        return WebPush::b64uDecode($s);
    }

    protected function setUp(): void
    {
        if (!WebPush::supported()) {
            $this->markTestSkipped('openssl without EC support');
        }
        WebPush::$transport = null;
    }

    protected function tearDown(): void
    {
        WebPush::$transport = null;
    }

    public function testRfc8291AppendixAVectorMatchesExactly(): void
    {
        $asPem = WebPush::privateKeyFromRaw(self::d(self::AS_PRIVATE), self::d(self::AS_PUBLIC));
        $this->assertSame(self::AS_PUBLIC, WebPush::b64uEncode(WebPush::publicKeyRaw($asPem)));
        $body = WebPush::encrypt(self::PLAINTEXT, self::d(self::UA_PUBLIC), self::d(self::AUTH), $asPem, self::d(self::SALT));
        $this->assertSame(self::EXPECTED, WebPush::b64uEncode($body));
    }

    public function testRfc8291IntermediateValues(): void
    {
        $asPem = WebPush::privateKeyFromRaw(self::d(self::AS_PRIVATE), self::d(self::AS_PUBLIC));
        $k = WebPush::deriveKeys($asPem, self::d(self::AS_PUBLIC), self::d(self::UA_PUBLIC), self::d(self::AUTH), self::d(self::SALT));
        $this->assertSame('kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', WebPush::b64uEncode($k['ecdh_secret']));
        $this->assertSame('Snr3JMxaHVDXHWJn5wdC52WjpCtd2EIEGBykDcZW32k', WebPush::b64uEncode($k['prk_key']));
        $this->assertSame('S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg', WebPush::b64uEncode($k['ikm']));
        $this->assertSame('09_eUZGrsvxChDCGRCdkLiDXrReGOEVeSCdCcPBSJSc', WebPush::b64uEncode($k['prk']));
        $this->assertSame('oIhVW04MRdy2XN9CiKLxTg', WebPush::b64uEncode($k['cek']));
        $this->assertSame('4h_95klXJ5E_qnoN', WebPush::b64uEncode($k['nonce']));
        // ECDH is symmetric: the user agent derives the same secret.
        $uaPem = WebPush::privateKeyFromRaw(self::d(self::UA_PRIVATE), self::d(self::UA_PUBLIC));
        $this->assertSame($k['ecdh_secret'], WebPush::ecdh($uaPem, self::d(self::AS_PUBLIC)));
    }

    public function testRfcMessageDecryptsWithUserAgentKey(): void
    {
        $uaPem = WebPush::privateKeyFromRaw(self::d(self::UA_PRIVATE), self::d(self::UA_PUBLIC));
        $this->assertSame(self::PLAINTEXT, WebPush::decrypt(self::d(self::EXPECTED), $uaPem, self::d(self::AUTH)));
    }

    public function testRoundTripWithRandomKeysAndPadding(): void
    {
        $ua = WebPush::generateKeyPair();
        $auth = random_bytes(16);
        $payload = json_encode(['title' => 'નવો ઓર્ડર', 'body' => str_repeat('x', 1000)], JSON_UNESCAPED_UNICODE);
        $a = WebPush::encrypt($payload, $ua['public'], $auth);
        $b = WebPush::encrypt($payload, $ua['public'], $auth);
        $this->assertNotSame($a, $b, 'fresh salt + ephemeral key per message');
        $this->assertSame(16 + 4 + 1 + 65 + strlen($payload) + 1 + 16, strlen($a));
        $this->assertSame(4096, unpack('N', substr($a, 16, 4))[1]);
        $this->assertSame(65, ord($a[20]));
        $this->assertSame($payload, WebPush::decrypt($a, $ua['private_pem'], $auth));
        $padded = WebPush::encrypt('hi', $ua['public'], $auth, null, null, 30);
        $this->assertSame('hi', WebPush::decrypt($padded, $ua['private_pem'], $auth));
        $this->expectException(RuntimeException::class);
        WebPush::decrypt($a, $ua['private_pem'], random_bytes(16)); // wrong auth secret → GCM tag fails
    }

    public function testInvalidInputsAreRejected(): void
    {
        $ua = WebPush::generateKeyPair();
        foreach ([
            fn () => WebPush::encrypt('x', substr($ua['public'], 1), random_bytes(16)),
            fn () => WebPush::encrypt('x', $ua['public'], random_bytes(8)),
            fn () => WebPush::encrypt(str_repeat('x', WebPush::MAX_PAYLOAD + 1), $ua['public'], random_bytes(16)),
            fn () => WebPush::b64uDecode('***'),
        ] as $i => $fn) {
            try {
                $fn();
                $this->fail('case ' . $i . ' should throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(WebPush::MAX_PAYLOAD + 86 + 17, strlen(WebPush::encrypt(str_repeat('x', WebPush::MAX_PAYLOAD), $ua['public'], random_bytes(16))), 'max payload fits a 4096-byte message');
    }

    public function testDerRawSignatureConversion(): void
    {
        // r with the high bit set (needs a 0x00 prefix in DER), s with leading zero bytes.
        $r = "\x80" . str_repeat("\x11", 31);
        $s = "\x00\x00" . str_repeat("\x22", 30);
        $der = WebPush::rawToDer($r . $s);
        $this->assertSame("\x30", $der[0]);
        $this->assertSame("\x02\x21\x00\x80", substr($der, 2, 4));
        $this->assertSame($r . $s, WebPush::derToRaw($der));
        $this->expectException(InvalidArgumentException::class);
        WebPush::derToRaw("\x31\x00");
    }

    public function testVapidJwtIsValidEs256(): void
    {
        $kp = WebPush::generateKeyPair();
        $jwt = WebPush::vapidJwt('https://fcm.googleapis.com/fcm/send/abc:def', $kp['private_pem'], 'mailto:ops@example.com', 1900000000);
        [$h, $c, $sig] = explode('.', $jwt);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64uDecode($h), true));
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1900000000, 'sub' => 'mailto:ops@example.com'], json_decode(WebPush::b64uDecode($c), true));
        $raw = WebPush::b64uDecode($sig);
        $this->assertSame(64, strlen($raw), 'JWS ES256 signature is raw r||s');
        $pub = openssl_pkey_get_public(WebPush::publicKeyPem($kp['public']));
        $this->assertSame(1, openssl_verify($h . '.' . $c, WebPush::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($h . '.' . $c . 'x', WebPush::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256));
        // Many signatures: r / s with leading zeros must still convert (statistically ~1/128 per run).
        for ($i = 0; $i < 40; $i++) {
            [$h2, $c2, $s2] = explode('.', WebPush::vapidJwt('https://updates.push.services.mozilla.com/wpush/v2/x', $kp['private_pem'], 'mailto:a@b.co', 1900000000 + $i));
            $this->assertSame(1, openssl_verify($h2 . '.' . $c2, WebPush::rawToDer(WebPush::b64uDecode($s2)), $pub, OPENSSL_ALGO_SHA256));
        }
    }

    public function testEndpointValidation(): void
    {
        $this->assertTrue(WebPush::validEndpoint('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertTrue(WebPush::validEndpoint('https://web.push.apple.com/QGz'));
        $this->assertFalse(WebPush::validEndpoint('http://fcm.googleapis.com/fcm/send/abc'), 'https only');
        $this->assertFalse(WebPush::validEndpoint('https://127.0.0.1/x'));
        $this->assertFalse(WebPush::validEndpoint('https://192.168.1.10/x'));
        $this->assertFalse(WebPush::validEndpoint('https://localhost/x'));
        $this->assertFalse(WebPush::validEndpoint('https://user:pw@push.example.com/x'));
        $this->assertFalse(WebPush::validEndpoint('javascript:alert(1)'));
    }

    public function testSendBuildsVapidRequestAndReportsGone(): void
    {
        Tenant::clear();
        $ua = WebPush::generateKeyPair();
        $auth = random_bytes(16);
        $calls = [];
        WebPush::$transport = function (string $url, array $headers, string $body) use (&$calls) {
            $calls[] = [$url, $headers, $body];
            return ['status' => count($calls) === 1 ? 201 : 410, 'body' => ''];
        };
        $sub = ['endpoint' => 'https://push.example.com/send/123', 'p256dh' => WebPush::b64uEncode($ua['public']), 'auth' => WebPush::b64uEncode($auth)];
        $r = WebPush::send($sub, '{"title":"Hi"}', ['ttl' => 600, 'urgency' => 'high', 'topic' => 'orders']);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['gone']);
        [$url, $headers, $body] = $calls[0];
        $this->assertSame('https://push.example.com/send/123', $url);
        $this->assertContains('Content-Encoding: aes128gcm', $headers);
        $this->assertContains('TTL: 600', $headers);
        $this->assertContains('Urgency: high', $headers);
        $this->assertContains('Topic: orders', $headers);
        $authz = (string) current(array_filter($headers, fn ($h) => str_starts_with($h, 'Authorization: ')));
        $this->assertMatchesRegularExpression('/^Authorization: vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]{87}$/', $authz);
        $this->assertSame(WebPush::publicKey(), substr($authz, -87));
        $this->assertSame('{"title":"Hi"}', WebPush::decrypt($body, $ua['private_pem'], $auth));
        // VAPID private key is stored encrypted on the platform (hotel 0).
        $this->assertStringStartsWith('enc:', (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_vapid_private'"));

        $r = WebPush::send($sub, 'x');
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['gone'], '410 → subscription must be removed');
        $this->assertSame(410, $r['status']);
        $r = WebPush::send(['endpoint' => 'http://insecure.example.com/x'] + $sub, 'x');
        $this->assertFalse($r['ok']);
        $this->assertCount(2, $calls, 'invalid endpoint is never contacted');
    }
}
