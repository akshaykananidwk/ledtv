<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CoreHelpersTest extends TestCase
{
    public function testEscapeHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        $this->assertSame('દ્વારકા', e('દ્વારકા'));
    }

    public function testEnvBuildRoundTrip(): void
    {
        $file = HC_ROOT . '/storage/test.env';
        file_put_contents($file, Env::build(['A' => 'simple', 'B' => 'with space', 'C' => 'p@ss"w#rd=1', 'D' => '']));
        Env::load($file);
        $this->assertSame('simple', Env::get('A'));
        $this->assertSame('with space', Env::get('B'));
        $this->assertSame('p@ss"w#rd=1', Env::get('C'));
        $this->assertSame('', Env::get('D'));
        unlink($file);
    }

    public function testCryptoRoundTrip(): void
    {
        $c = Crypto::encrypt('github_pat_secret');
        $this->assertStringStartsWith('enc:', $c);
        $this->assertStringNotContainsString('secret', $c);
        $this->assertSame('github_pat_secret', Crypto::decrypt($c));
        $this->assertNull(Crypto::decrypt('enc:' . base64_encode(str_repeat('x', 40))));
    }

    public function testCleanColor(): void
    {
        $this->assertSame('#AABBCC', clean_color('#aabbcc'));
        $this->assertSame('#000000', clean_color('red'));
        $this->assertSame('#FFFFFF', clean_color('javascript:x', '#FFFFFF'));
    }

    public function testSettingsSecretStoredEncrypted(): void
    {
        Settings::setSecret('github_token', 'tok_123');
        $raw = DB::value("SELECT setting_value FROM system_settings WHERE setting_key='github_token'");
        $this->assertStringStartsWith('enc:', (string) $raw);
        Settings::flush();
        $this->assertSame('tok_123', Settings::secret('github_token'));
        Settings::setSecret('github_token', '');
    }

    public function testCacheSetGetClear(): void
    {
        Cache::set('t', 'k', ['a' => 1]);
        $this->assertSame(['a' => 1], Cache::get('t', 'k', 60));
        Cache::clear('t');
        $this->assertNull(Cache::get('t', 'k', 60));
    }

    public function testRateLimiter(): void
    {
        $key = 'test:' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(0, RateLimiter::hit($key, 5, 60));
        }
        $this->assertGreaterThan(0, RateLimiter::hit($key, 5, 60));
    }

    public function testGujaratiTranslationFileIsValid(): void
    {
        $gu = require HC_ROOT . '/lang/gu.php';
        $this->assertIsArray($gu);
        $this->assertNotEmpty($gu);
        foreach ($gu as $k => $v) {
            $this->assertIsString($k);
            $this->assertIsString($v);
            $this->assertTrue(mb_check_encoding($v, 'UTF-8'));
        }
        I18n::setLang('gu');
        $this->assertSame($gu['Dashboard'] ?? 'Dashboard', __('Dashboard'));
        I18n::setLang('en');
        $this->assertSame('Dashboard', __('Dashboard'));
    }
}
