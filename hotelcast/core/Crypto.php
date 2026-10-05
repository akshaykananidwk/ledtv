<?php
declare(strict_types=1);

/** Symmetric encryption for stored secrets (libsodium, key = APP_KEY from .env). */
final class Crypto
{
    private static function key(): string
    {
        $appKey = (string) Env::get('APP_KEY', '');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY missing from .env');
        }
        return sodium_crypto_generichash($appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $cipher): ?string
    {
        if (!str_starts_with($cipher, 'enc:')) {
            return $cipher;
        }
        $raw = base64_decode(substr($cipher, 4), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }
}
