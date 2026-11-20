<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.10.4 WhatsApp messages (core/WhatsApp.php, admin/platform_whatsapp.php): Indian mobile numbers normalised to
 * 91XXXXXXXXXX; the API gets {api_key, number, message, session_id, media_url}; provider errors are reported;
 * recipients are customers' contact mobiles (active / trial / all / selected, one message per number, archived
 * never); :name / :business personalised; the Super Admin page saves the settings without ever printing the key,
 * sends to everyone and logs history; customers get 403; CSRF required.
 */
final class WhatsAppTest extends TestCase
{
    private static string $url;
    private static string $mockFile;
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => null, 'username' => 'waroot', 'email' => 'waroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => 1, 'username' => 'waboss', 'email' => 'waboss@hotel.test', 'full_name' => 'Boss', 'password_hash' => $pw, 'role' => 'super_admin']);
        DB::query("UPDATE hotels SET contact_phone = NULL WHERE id = 1");
        foreach ([
            'temple' => ['Shiv Temple', 'Ramesh', '+91 98765 43210', 'active', 0],
            'hotel' => ['Sea Hotel', 'Mina', '09876500011', 'active', 1],
            'dup' => ['Sea Hotel Annex', 'Mina', '9876500011', 'active', 0],
            'paused' => ['Old Shop', 'Kiran', '9123456780', 'suspended', 0],
            'nophone' => ['No Phone Co', 'X', '12345', 'active', 0],
            'archived' => ['Gone Ltd', 'Y', '9988776655', 'active', 0],
        ] as $k => [$name, $contact, $phone, $status, $trial]) {
            $id = Hotels::create(['name' => $name]);
            DB::query('UPDATE hotels SET contact_name = :c, contact_phone = :p, status = :s, is_trial = :t WHERE id = :id',
                ['c' => $contact, 'p' => $phone, 's' => $status, 't' => $trial, 'id' => $id]);
            self::$ids[$k] = $id;
        }
        DB::query('UPDATE hotels SET archived_at = NOW() WHERE id = :id', ['id' => self::$ids['archived']]);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::flush();
        self::$mockFile = tempnam(sys_get_temp_dir(), 'wamock');
        file_put_contents(self::$mockFile, json_encode(['https://bulk.akdwk.in/api.php' => ['status' => 200, 'body' => '{"status":"success","message":"queued"}']]));
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url, ['http_mock_file' => self::$mockFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Http::$mock = null;
        @unlink(self::$mockFile);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Settings::flush();
    }

    public function test1Numbers(): void
    {
        $this->assertSame('919876543210', WhatsApp::number('+91 98765 43210'));
        $this->assertSame('919876543210', WhatsApp::number('98765-43210'));
        $this->assertSame('919876543210', WhatsApp::number('098765 43210'));
        $this->assertSame('919876543210', WhatsApp::number('0091 9876543210'));
        $this->assertSame('14155550123', WhatsApp::number('+1 415 555 0123'));
        $this->assertNull(WhatsApp::number('12345'));
        $this->assertNull(WhatsApp::number(''));
        $this->assertNull(WhatsApp::number('1234567890')); // 10 digits not starting 6-9: not Indian mobile, no country code
    }

    public function test2SendBodyAndErrors(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'not configured'], WhatsApp::send('919876543210', 'hi'));
        $this->assertSame([], WhatsApp::saveConfig(['url' => '', 'key' => 'testkey123', 'session' => '9000000001']));
        $calls = [];
        Http::$mock = static function (string $m, string $url, array $h, ?string $body) use (&$calls): array {
            $calls[] = [$m, $url, $h, json_decode((string) $body, true)];
            return ['status' => 200, 'body' => '{"status":"success"}'];
        };
        $this->assertTrue(WhatsApp::send('919876543210', 'જય દ્વારકાધીશ', 'https://x.test/a.jpg')['ok']);
        [$m, $url, $h, $b] = $calls[0];
        $this->assertSame('POST', $m);
        $this->assertSame('https://bulk.akdwk.in/api.php', $url);
        $this->assertContains('Content-Type: application/json', $h);
        $this->assertSame(['api_key' => 'testkey123', 'number' => '919876543210', 'message' => 'જય દ્વારકાધીશ', 'session_id' => '9000000001', 'media_url' => 'https://x.test/a.jpg'], $b);
        WhatsApp::send('919876543210', 'plain');
        $this->assertArrayNotHasKey('media_url', $calls[1][3]);

        Http::$mock = static fn () => ['status' => 200, 'body' => '{"status":"error","message":"Session not connected"}'];
        $this->assertSame(['ok' => false, 'error' => 'Session not connected'], WhatsApp::send('919876543210', 'x'));
        Http::$mock = static fn () => ['status' => 200, 'body' => '{"success":false,"error":"Invalid API key"}'];
        $this->assertSame(['ok' => false, 'error' => 'Invalid API key'], WhatsApp::send('919876543210', 'x'));
        Http::$mock = static fn () => ['status' => 500, 'body' => 'oops'];
        $this->assertFalse(WhatsApp::send('919876543210', 'x')['ok']);
        Http::$mock = null;

        // Validation; empty key keeps the saved one.
        $this->assertNotEmpty(WhatsApp::saveConfig(['url' => 'http://insecure.test/api', 'key' => '', 'session' => 'x1']));
        $this->assertNotEmpty(WhatsApp::saveConfig(['url' => '', 'key' => 'bad key!', 'session' => 'x1']));
        $this->assertSame([], WhatsApp::saveConfig(['url' => '', 'key' => '', 'session' => '9000000002']));
        $this->assertSame('testkey123', WhatsApp::config()['key']);
        $this->assertSame('9000000002', WhatsApp::config()['session']);
    }

    public function test3RecipientsAndBroadcast(): void
    {
        $names = static fn (array $r) => array_column($r, 'name');
        $active = WhatsApp::recipients('active');
        $this->assertSame(['Sea Hotel', 'Shiv Temple'], $names($active)); // annex = same number, paused / no phone / archived out
        $this->assertSame(['Sea Hotel'], $names(WhatsApp::recipients('trial')));
        $this->assertSame(['Old Shop', 'Sea Hotel', 'Shiv Temple'], $names(WhatsApp::recipients('all')));
        $this->assertSame(['Old Shop'], $names(WhatsApp::recipients('selected', [self::$ids['paused'], self::$ids['archived'], self::$ids['nophone']])));
        $this->assertSame([], WhatsApp::recipients('selected', []));

        $sent = [];
        Http::$mock = static function (string $m, string $url, array $h, ?string $body) use (&$sent): array {
            $b = json_decode((string) $body, true);
            $sent[$b['number']] = $b['message'];
            return $b['number'] === '919876500011' ? ['status' => 200, 'body' => '{"status":"error","message":"not on WhatsApp"}'] : ['status' => 200, 'body' => '{"status":"success"}'];
        };
        $r = WhatsApp::broadcast($active, 'Namaste :name (:business), please update the TV app.', '', null);
        Http::$mock = null;
        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['failed']);
        $this->assertStringContainsString('Sea Hotel (91987650••••): not on WhatsApp', $r['errors'][0]);
        $this->assertSame('Namaste Ramesh (Shiv Temple), please update the TV app.', $sent['919876543210']);
        $h = WhatsApp::history()[0];
        $this->assertSame([1, 1], [$h['sent'], $h['failed']]);
    }

    public function test4SuperAdminPage(): void
    {
        $boss = new AdminSession(self::$url, 'waboss');
        [$s] = $boss->get('platform_whatsapp.php');
        $this->assertSame(403, $s);
        [$s] = $boss->post('platform_whatsapp.php', ['op' => 'send', 'message' => 'x', 'to' => 'all']);
        $this->assertSame(403, $s);

        $root = new AdminSession(self::$url, 'waroot');
        [$s] = $root->post('platform_whatsapp.php', ['op' => 'settings', 'wa_url' => '', 'wa_key' => 'secretKEY9876', 'wa_session' => '9000000003']);
        $this->assertSame(302, $s);
        Settings::flush();
        $this->assertSame('secretKEY9876', WhatsApp::config()['key']);
        [$s, , $html] = $root->get('platform_whatsapp.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), $html);
        $this->assertStringNotContainsString('secretKEY9876', $html);
        $this->assertStringContainsString('9000000003', $html);
        $this->assertStringContainsString('id="waSend"', $html);
        $this->assertStringContainsString('(2)', $html); // active customers with a number

        // Without a CSRF token nothing is sent.
        $before = count(WhatsApp::history());
        $r = (new ReflectionProperty(AdminSession::class, 'csrf'));
        $good = $r->getValue($root);
        $r->setValue($root, 'wrong');
        $root->post('platform_whatsapp.php', ['op' => 'send', 'message' => 'x', 'to' => 'all']);
        $r->setValue($root, $good);
        Settings::flush();
        $this->assertCount($before, WhatsApp::history());

        [$s] = $root->post('platform_whatsapp.php', ['op' => 'send', 'message' => 'New TV app 2.6.6 — please update.', 'media_url' => '', 'to' => 'active']);
        $this->assertSame(302, $s);
        Settings::flush();
        $h = WhatsApp::history();
        $this->assertCount($before + 1, $h);
        $this->assertSame(2, $h[0]['sent']);
        $this->assertSame('New TV app 2.6.6 — please update.', $h[0]['text']);
        [, , $html] = $root->get('platform_whatsapp.php');
        $this->assertStringContainsString('New TV app 2.6.6', $html);

        // Empty message / bad media link: nothing sent.
        $root->post('platform_whatsapp.php', ['op' => 'send', 'message' => '', 'to' => 'active']);
        $root->post('platform_whatsapp.php', ['op' => 'send', 'message' => 'x', 'media_url' => 'javascript:alert(1)', 'to' => 'active']);
        Settings::flush();
        $this->assertCount($before + 1, WhatsApp::history());
    }
}
