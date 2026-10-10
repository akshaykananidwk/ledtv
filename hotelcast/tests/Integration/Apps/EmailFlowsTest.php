<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.8 Email (docs/modules/email.md): Mailer MIME build (headers, RFC 2047, header injection), the SMTP
 * client against a fake SMTP server (plain, AUTH LOGIN, AUTH PLAIN, wrong password, STARTTLS not
 * offered, refused connection), the mail log, Super Admin → Platform settings → Email (encrypted
 * password, "Send test email" with the exact error), forgot / reset password for every role (neutral
 * answer, single-use hashed token, expiry, sessions revoked, rate limits, CSRF), login by email,
 * sign-up OTP + welcome mail, admin-created users with an invite link.
 *
 * No real e-mail ever leaves: the sandbox writes messages to storage/mail_outbox.jsonl and SMTP is only
 * allowed to loopback (the fake server below).
 */
final class EmailFlowsTest extends TestCase
{
    private static string $url;
    private static array $h = [];
    private static array $u = [];
    private static array $procs = [];
    private static string $tmp = '';
    private const PW = 'Passw0rd!';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash(self::PW);
        $r1 = DB::insert('resellers', ['name' => 'Mail Reseller', 'status' => 'active']);
        $r2 = DB::insert('resellers', ['name' => 'Gone Reseller', 'status' => 'suspended']);
        self::$u['root'] = DB::insert('users', ['hotel_id' => null, 'username' => 'emroot', 'email' => 'Root@Platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        self::$u['res'] = DB::insert('users', ['hotel_id' => null, 'reseller_id' => $r1, 'username' => 'emres', 'email' => 'res@reseller.test', 'full_name' => 'Res', 'password_hash' => $pw, 'role' => 'reseller', 'language' => 'gu']);
        self::$u['res_gone'] = DB::insert('users', ['hotel_id' => null, 'reseller_id' => $r2, 'username' => 'emresgone', 'email' => 'gone@reseller.test', 'full_name' => 'Gone', 'password_hash' => $pw, 'role' => 'reseller']);
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Mail Stores', 'city' => 'Rajkot', 'reseller_id' => $r1, 'contact_email' => 'owner@alpha.test']);
        DB::query("UPDATE hotels SET brand_name = 'Alpha Signage', brand_color = '#0055AA' WHERE id = :id", ['id' => self::$h['alpha']]);
        self::$h['archived'] = Hotels::create(['name' => 'Archived Mail Co', 'city' => 'Surat']);
        DB::query('UPDATE hotels SET archived_at = :n WHERE id = :id', ['n' => now(), 'id' => self::$h['archived']]);
        self::$u['boss'] = Hotels::createHotelUser(self::$h['alpha'], ['username' => 'emboss', 'email' => 'boss@alpha.test', 'password' => self::PW, 'language' => 'hi'], 'super_admin');
        self::$u['staff'] = Hotels::createHotelUser(self::$h['alpha'], ['username' => 'emstaff', 'email' => 'staff@alpha.test', 'password' => self::PW], 'staff');
        self::$u['inactive'] = Hotels::createHotelUser(self::$h['alpha'], ['username' => 'emoff', 'email' => 'off@alpha.test', 'password' => self::PW], 'staff');
        DB::query('UPDATE users SET is_active = 0 WHERE id = :id', ['id' => self::$u['inactive']]);
        self::$u['archived'] = Hotels::createHotelUser(self::$h['archived'], ['username' => 'emarch', 'email' => 'boss@archived.test', 'password' => self::PW], 'super_admin');
        $chain = DB::insert('hotel_chains', ['name' => 'Mail Chain', 'created_at' => now()]);
        self::$u['chain'] = Chains::createAdmin($chain, ['username' => 'emchain', 'email' => 'chain@chain.test', 'password' => self::PW, 'full_name' => 'Chain Boss']);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::setPlatform('platform_support_email', 'help@platform.test');
        self::$tmp = sys_get_temp_dir() . '/hc_email_' . getmypid();
        @mkdir(self::$tmp, 0700, true);
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::$procs = [];
        Mailer::$testHook = null;
        Notifier::$mailer = null;
        foreach (['mail_transport' => 'mail', 'smtp_host' => '', 'smtp_password' => ''] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
        TestEnv::rmTree(self::$tmp);
        Tenant::set(1);
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Settings::flush();
        Mailer::$testHook = null;
        Notifier::$mailer = null;
        @unlink((string) Mailer::outboxPath());
        Tenant::set(1);
    }

    // ------------------------------------------------------------------ helpers

    /** Start the fake SMTP server; returns [port, logfile]. */
    private static function fakeSmtp(string $mechs = '-', string $user = '', string $pass = ''): array
    {
        $port = TestEnv::freePort();
        $log = self::$tmp . '/smtp_' . $port . '.jsonl';
        $cmd = sprintf('exec %s %s %d %s %s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg(TestEnv::$appSrc . '/tests/fixtures/fake_smtp.php'),
            $port, escapeshellarg($log), escapeshellarg($mechs), escapeshellarg($user), escapeshellarg($pass));
        self::$procs[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
            if ($c) {
                fclose($c);
                // the probe connection is logged as an empty conversation: wait for it, then clear
                for ($j = 0; $j < 50 && !is_file($log); $j++) {
                    usleep(20000);
                }
                @unlink($log);
                return [$port, $log];
            }
            usleep(50000);
        }
        throw new RuntimeException('fake SMTP did not start');
    }

    private static function smtpLog(string $log): array
    {
        for ($i = 0; $i < 50 && !is_file($log); $i++) {
            usleep(20000);
        }
        $out = [];
        foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
            $out[] = json_decode($l, true);
        }
        return $out;
    }

    private static function outbox(): array
    {
        return Mailer::outbox();
    }

    private static function tokenFrom(string $text): string
    {
        return preg_match('/reset_password\.php\?token=([a-f0-9]{64})/', $text, $m) ? $m[1] : '';
    }

    /** Public page session: [jar, csrf, html]. */
    private static function page(string $path, ?string $jar = null): array
    {
        $jar ??= (string) tempnam(self::$tmp, 'jar');
        [$s, , $html, $head] = TestEnv::http('GET', self::$url . $path, null, [], $jar);
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
        return [$jar, html_entity_decode($m[1] ?? ''), $html, $s, $head];
    }

    private static function forgot(string $login, ?string $jar = null): array
    {
        [$jar, $csrf] = self::page('admin/forgot_password.php', $jar);
        [$s, , $body, $head] = TestEnv::http('POST', self::$url . 'admin/forgot_password.php', null, [], $jar, ['_csrf' => $csrf, 'login' => $login]);
        return [$s, $body, $head, $jar];
    }

    /** Open the e-mailed link, then post the new password. Returns [status, body, head]. */
    private static function reset(string $token, string $pw, ?string $confirm = null): array
    {
        $jar = (string) tempnam(self::$tmp, 'jar');
        [$s, , , $head] = TestEnv::http('GET', self::$url . 'admin/reset_password.php?token=' . $token, null, [], $jar);
        self::assertSame(302, $s);
        self::assertStringNotContainsString($token, $head, 'the token is moved out of the URL');
        [$jar, $csrf, $html] = self::page('admin/reset_password.php', $jar);
        if ($csrf === '') {
            return [200, $html, ''];
        }
        [$s, , $body, $head] = TestEnv::http('POST', self::$url . 'admin/reset_password.php', null, [], $jar, ['_csrf' => $csrf, 'password' => $pw, 'password_confirm' => $confirm ?? $pw]);
        return [$s, $body, $head];
    }

    private static function canLogin(string $login, string $pw): bool
    {
        [$jar, $csrf] = self::page('admin/login.php');
        [$s, , , $head] = TestEnv::http('POST', self::$url . 'admin/login.php', null, [], $jar, ['_csrf' => $csrf, 'username' => $login, 'password' => $pw]);
        return $s === 302 && !str_contains($head, 'login.php');
    }

    // ------------------------------------------------------------------ Mailer: MIME

    public function testMimeMessageIsWellFormedAndInjectionSafe(): void
    {
        $subject = 'પાસવર્ડ રીસેટ — Krishna Cloud';
        $m = Mailer::build([
            'to' => ['a@b.test'], 'subject' => $subject . "\r\nBcc: evil@x.test", 'text' => "Hello\n.dot line\nબીજી લાઇન", 'html' => '<p>Hello <b>world</b></p>',
            'from' => "no-reply@platform.test\r\nBcc: evil@x.test", 'from_name' => "Evil\r\nBcc: evil@x.test", 'reply_to' => 'help@platform.test',
        ]);
        $head = substr($m['raw'], 0, (int) strpos($m['raw'], "\r\n\r\n"));
        // No header line was injected: every line is a known header or a folded continuation.
        foreach (explode("\r\n", $head) as $line) {
            $this->assertMatchesRegularExpression('/^(Date|From|To|Subject|Message-ID|MIME-Version|Reply-To|X-Mailer|Content-Type|Content-Transfer-Encoding): |^ =\?UTF-8\?B\?/', $line);
            $this->assertLessThanOrEqual(998, strlen($line));
        }
        $this->assertStringNotContainsString("\nBcc:", $m['raw']);
        $this->assertStringStartsWith('=?UTF-8?B?', $m['headers']['Subject']);
        $this->assertSame($subject . ' Bcc: evil@x.test', mb_decode_mimeheader($m['headers']['Subject']));
        $this->assertMatchesRegularExpression('/^<[a-f0-9]{24}\.\d+@[A-Za-z0-9.\-]+>$/', $m['headers']['Message-ID']);
        $this->assertNotFalse(strtotime($m['headers']['Date']));
        $this->assertSame('help@platform.test', $m['headers']['Reply-To']);
        $this->assertSame('1.0', $m['headers']['MIME-Version']);
        $this->assertStringContainsString('"Evil Bcc: evil@x.test" <', $m['headers']['From']);
        $this->assertMatchesRegularExpression('/^multipart\/alternative; boundary="(=_hc_[a-f0-9]{24})"$/', $m['headers']['Content-Type']);
        preg_match('/boundary="([^"]+)"/', $m['headers']['Content-Type'], $b);
        $parts = explode('--' . $b[1], $m['body']);
        $this->assertCount(4, $parts, 'preamble, text, html, closing');
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $parts[1]);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $parts[2]);
        $text = quoted_printable_decode(substr($parts[1], (int) strpos($parts[1], "\r\n\r\n") + 4));
        $this->assertSame("Hello\r\n.dot line\r\nબીજી લાઇન", rtrim($text, "\r\n"));
        $this->assertStringContainsString('<b>world</b>', quoted_printable_decode($parts[2]));
        // Display names / UTF-8 names
        $this->assertSame('=?UTF-8?B?' . base64_encode('ક્રિષ્ના') . '?= <x@y.test>', Mailer::address('x@y.test', 'ક્રિષ્ના'));
        $this->assertSame('"A \"B\"" <x@y.test>', Mailer::address('x@y.test', 'A "B"'));
        // Long subjects fold into several encoded words of at most 75 characters.
        $long = Mailer::encodeHeader(str_repeat('ગુજરાતી ', 20));
        foreach (explode("\r\n ", $long) as $w) {
            $this->assertLessThanOrEqual(75, strlen($w));
        }
        $this->assertSame(str_repeat('ગુજરાતી ', 19) . 'ગુજરાતી', mb_decode_mimeheader($long));
        // Dot-stuffing and CRLF normalisation for SMTP DATA.
        $this->assertSame("a\r\n..b\r\n...c\r\nd", Mailer::dotStuff("a\n.b\r\n..c\rd\r\n"));
    }

    public function testSendRejectsInjectedRecipientsAndUsesTheTestHook(): void
    {
        $got = [];
        Mailer::$testHook = static function (array $m) use (&$got): bool {
            $got[] = $m;
            return true;
        };
        $this->assertFalse(Mailer::send("a@b.test\r\nBcc: evil@x.test", 'Hi', 'x'));
        $this->assertSame('No valid recipient address', Mailer::$lastError);
        $this->assertTrue(Mailer::send('a@b.test, c@d.test', 'Hi', 'text', '<p>html</p>'));
        $this->assertCount(1, $got);
        $this->assertSame(['a@b.test', 'c@d.test'], $got[0]['to']);
        $this->assertStringContainsString('multipart/alternative', $got[0]['raw']);
        // Old hook (Notifier::$mailer) still gets every message, also from Mailer::send().
        $old = [];
        Mailer::$testHook = null;
        Notifier::$mailer = static function (string $to, string $s, string $m) use (&$old): bool {
            $old[] = [$to, $s, $m];
            return true;
        };
        $this->assertTrue(Notifier::email('x@y.test', 'Subject', "Line 1\n\nhttps://example.test/x"));
        $this->assertSame([['x@y.test', 'Subject', "Line 1\n\nhttps://example.test/x"]], $old);
        // Nothing reached the outbox while a hook was set.
        $this->assertSame([], self::outbox());
    }

    public function testNotifierWrapsMessagesInTheBrandedTemplate(): void
    {
        $got = [];
        Mailer::$testHook = static function (array $m) use (&$got): bool {
            $got[] = $m;
            return true;
        };
        Tenant::run(self::$h['alpha'], static fn () => Notifier::email('owner@alpha.test', 'TV offline', "[Alpha] TV offline on screen(s): 101\n\nhttps://example.test/admin"));
        $this->assertCount(1, $got);
        $html = (string) $got[0]['html'];
        $this->assertStringContainsString('Alpha Signage', $html, 'customer white-label brand');
        $this->assertStringContainsString('#0055AA', $html);
        $this->assertStringContainsString('<a href="https://example.test/admin"', $html);
        $this->assertStringContainsString('help@platform.test', $html, 'support contact in the footer');
        $this->assertStringContainsString('TV offline on screen(s): 101', $got[0]['text'], 'plain text unchanged');
        $this->assertStringContainsString('Alpha Signage', $got[0]['raw']);
        // Template: escaped, plain-text alternative, button link also printed.
        [$text, $html] = MailTemplate::render(['brand' => Branding::get(0), 'title' => '<script>x</script>', 'paragraphs' => ['A & B'], 'button' => ['label' => 'Go', 'url' => 'https://e.test/?a=1&b=2'], 'code' => '123456']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('href="https://e.test/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString("Go:\nhttps://e.test/?a=1&b=2", $text);
        $this->assertStringContainsString('123456', $text);
    }

    // ------------------------------------------------------------------ SMTP client

    public function testSmtpWithoutAuthDeliversWithDotStuffing(): void
    {
        [$port, $log] = self::fakeSmtp();
        $msg = Mailer::build(['to' => ['guest@example.test'], 'subject' => 'Plain', 'text' => "Hi\n.leading dot\n..two", 'from' => 'no-reply@platform.test', 'from_name' => 'P']);
        Mailer::smtpSend(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none'], 'no-reply@platform.test', ['guest@example.test'], $msg['raw']);
        $conv = self::smtpLog($log);
        $this->assertCount(1, $conv);
        $c = array_values(array_filter($conv[0]['conversation'], fn ($l) => str_starts_with($l, 'C: ')));
        $this->assertMatchesRegularExpression('/^C: EHLO \S+$/', $c[0]);
        $this->assertSame(['C: MAIL FROM:<no-reply@platform.test>', 'C: RCPT TO:<guest@example.test>', 'C: DATA'], array_slice($c, 1, 3));
        $this->assertSame('C: QUIT', end($c));
        $this->assertSame(rtrim($msg['raw'], "\r\n"), $conv[0]['message']['data'], 'the server got exactly the message back after un-stuffing');
        $this->assertContains('C: QUIT', Mailer::$lastTranscript);
    }

    public function testSmtpAuthLoginAndPlainAndWrongPassword(): void
    {
        [$port, $log] = self::fakeSmtp('LOGIN', 'mailer@platform.test', 'app-pass-123');
        $raw = Mailer::build(['to' => ['x@example.test'], 'subject' => 'S', 'text' => 'T', 'from' => 'mailer@platform.test'])['raw'];
        $cfg = ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'mailer@platform.test', 'password' => 'app-pass-123'];
        Mailer::smtpSend($cfg, 'mailer@platform.test', ['x@example.test'], $raw);
        $conv = self::smtpLog($log)[0]['conversation'];
        $this->assertContains('C: AUTH LOGIN', $conv);
        $this->assertContains('C: ' . base64_encode('mailer@platform.test'), $conv);
        $this->assertContains('S: 235 2.7.0 Authentication successful', $conv);
        foreach (Mailer::$lastTranscript as $l) {
            $this->assertStringNotContainsString(base64_encode('app-pass-123'), $l, 'password never in the transcript');
            $this->assertStringNotContainsString('app-pass-123', $l);
        }
        // Wrong password: the exact server answer and a hint.
        try {
            Mailer::smtpSend(['password' => 'wrong'] + $cfg, 'mailer@platform.test', ['x@example.test'], $raw);
            $this->fail('expected an SMTP error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMTP error at AUTH LOGIN (password): 535 5.7.8 Username and Password not accepted', $e->getMessage());
            $this->assertStringContainsString('App Password', $e->getMessage());
        }
        // AUTH PLAIN when offered.
        [$port2, $log2] = self::fakeSmtp('PLAIN LOGIN', 'u2', 'p2');
        Mailer::smtpSend(['host' => '127.0.0.1', 'port' => $port2, 'encryption' => 'none', 'username' => 'u2', 'password' => 'p2'], 'a@b.test', ['x@example.test'], $raw);
        $conv = self::smtpLog($log2)[0]['conversation'];
        $this->assertContains('C: AUTH PLAIN ' . base64_encode("\0u2\0p2"), $conv);
        $this->assertContains('AUTH PLAIN ****', array_map(fn ($l) => substr($l, 3), Mailer::$lastTranscript));
        // Rejected recipient
        try {
            Mailer::smtpSend(['host' => '127.0.0.1', 'port' => $port2, 'encryption' => 'none', 'username' => 'u2', 'password' => 'p2'], 'a@b.test', ['reject@example.test'], $raw);
            $this->fail('expected RCPT error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMTP error at RCPT TO: 550 5.1.1 No such user', $e->getMessage());
        }
    }

    public function testStartTlsSequenceAndConnectionErrors(): void
    {
        // STARTTLS chosen but the server does not offer it: refused before any password is sent.
        [$port, $log] = self::fakeSmtp('LOGIN', 'u', 'p');
        try {
            Mailer::smtpSend(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p'], 'a@b.test', ['x@example.test'], "Subject: x\r\n\r\nx");
            $this->fail('expected STARTTLS error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }
        $conv = self::smtpLog($log)[0]['conversation'];
        $this->assertSame([], array_values(array_filter($conv, fn ($l) => str_starts_with($l, 'C: AUTH'))), 'no credentials over plaintext');
        // Closed port
        $closed = TestEnv::freePort();
        try {
            Mailer::smtpSend(['host' => '127.0.0.1', 'port' => $closed, 'encryption' => 'none', 'timeout' => 3], 'a@b.test', ['x@example.test'], 'x');
            $this->fail('expected connect error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMTP connect to 127.0.0.1:' . $closed . ' failed', $e->getMessage());
        }
        // TLS peer verification is on by default, off only with "allow self-signed".
        $this->assertTrue(Mailer::sslOptions('smtp.gmail.com', false)['verify_peer']);
        $this->assertTrue(Mailer::sslOptions('smtp.gmail.com', false)['verify_peer_name']);
        $this->assertFalse(Mailer::sslOptions('smtp.gmail.com', false)['allow_self_signed']);
        $this->assertFalse(Mailer::sslOptions('mail.own.test', true)['verify_peer']);
        $this->assertSame('smtp.gmail.com', Mailer::sslOptions('smtp.gmail.com', false)['peer_name']);
    }

    public function testMailerSendThroughSmtpSettingsAndTheMailLog(): void
    {
        [$port, $log] = self::fakeSmtp('LOGIN', 'sender@platform.test', 'Secret-77');
        DB::query('DELETE FROM mail_log');
        foreach (['mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none', 'smtp_username' => 'sender@platform.test', 'mail_from_email' => '', 'mail_from_name' => ''] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
        Settings::setSecret('smtp_password', 'Secret-77');
        try {
            $this->assertTrue(Mailer::send('dest@example.test', 'Your code 123456', 'body with secret link', null, ['from' => 'billing@other.test']), Mailer::$lastError);
            $m = self::smtpLog($log)[0]['message'];
            $this->assertSame('sender@platform.test', $m['from'], 'SMTP sends as the configured mailbox (username)');
            $this->assertStringContainsString('Reply-To: billing@other.test', $m['data'], 'caller address becomes Reply-To');
            $row = DB::one('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1');
            $this->assertSame(['dest@example.test', 'Your code ******', 'smtp', 'sent'], [$row['recipient'], $row['subject'], $row['transport'], $row['status']]);
            $this->assertStringNotContainsString('secret link', json_encode(DB::all('SELECT * FROM mail_log')), 'no bodies in the log');
            // A failure is logged with the error.
            Settings::setSecret('smtp_password', 'nope');
            $this->assertFalse(Mailer::send('dest@example.test', 'Second', 'x'));
            $row = DB::one('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1');
            $this->assertSame('failed', $row['status']);
            $this->assertStringContainsString('535', (string) $row['error']);
            // Only the newest 200 rows are kept.
            for ($i = 0; $i < 205; $i++) {
                Mailer::log(['n' . $i . '@x.test'], 'bulk', 'mail', 'sent');
            }
            $this->assertSame(Mailer::LOG_KEEP, (int) DB::value('SELECT COUNT(*) FROM mail_log'));
            $this->assertSame('n204@x.test', DB::value('SELECT recipient FROM mail_log ORDER BY id DESC LIMIT 1'));
        } finally {
            Settings::setPlatform('mail_transport', 'mail');
            Settings::setSecret('smtp_password', '');
        }
        // A non-loopback SMTP host in a test sandbox goes to the outbox, never out.
        Settings::setPlatform('mail_transport', 'smtp');
        Settings::setPlatform('smtp_host', 'smtp.gmail.com');
        try {
            $this->assertTrue(Mailer::send('real@gmail.com', 'Never sent', 'x'));
            $this->assertSame('real@gmail.com', self::outbox()[0]['to'][0]);
        } finally {
            Settings::setPlatform('mail_transport', 'mail');
            Settings::setPlatform('smtp_host', '');
        }
    }

    // ------------------------------------------------------------------ settings page

    public function testSmtpSettingsCardSavesEncryptedPasswordAndTestEmailShowsErrors(): void
    {
        $root = new AdminSession(self::$url, 'emroot');
        [$s, , $html] = $root->get('platform_settings.php?tab=email');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('data-smtp-card', $html);
        $this->assertStringContainsString('smtp.gmail.com', $html, 'Gmail preset help');
        $this->assertStringContainsString('data-mail-log', $html);
        [$port, $log] = self::fakeSmtp('LOGIN', 'box@platform.test', 'S3cret-Pass-9');
        $form = ['op' => 'email', 'tab' => 'email', 'mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_encryption' => 'none',
            'smtp_username' => 'box@platform.test', 'smtp_password' => 'S3cret-Pass-9', 'mail_from_email' => 'box@platform.test', 'mail_from_name' => 'Krishna Mail'];
        [$s] = $root->post('platform_settings.php', $form);
        $this->assertSame(302, $s);
        Settings::flush();
        $stored = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'smtp_password'");
        $this->assertStringStartsWith('enc:', $stored);
        $this->assertStringNotContainsString('S3cret', $stored);
        $this->assertSame('S3cret-Pass-9', Settings::secret('smtp_password'));
        [, , $html] = $root->get('platform_settings.php?tab=email');
        $this->assertStringNotContainsString('S3cret-Pass-9', $html, 'never echoed back');
        $this->assertStringContainsString('Saved (leave empty to keep)', $html);
        // Saving again with an empty password keeps it.
        $root->post('platform_settings.php', ['smtp_password' => ''] + $form);
        Settings::flush();
        $this->assertSame('S3cret-Pass-9', Settings::secret('smtp_password'));
        $this->assertSame('smtp', Settings::platform('mail_transport'));

        // Test email: works through the fake server.
        [$s] = $root->post('platform_settings.php', ['op' => 'email_test', 'test_to' => 'me@example.test', 'smtp_password' => ''] + $form);
        $this->assertSame(302, $s);
        [, , $html] = $root->get('platform_settings.php?tab=email');
        $this->assertStringContainsString('data-mail-test="ok"', $html);
        $got = self::smtpLog($log);
        $this->assertSame(['me@example.test'], end($got)['message']['to']);
        $this->assertStringContainsString('Krishna Mail', end($got)['message']['data']);
        // Wrong password typed in the form: exact SMTP error shown, nothing saved.
        $root->post('platform_settings.php', ['op' => 'email_test', 'test_to' => 'me@example.test', 'smtp_password' => 'bad'] + $form);
        [, , $html] = $root->get('platform_settings.php?tab=email');
        $this->assertStringContainsString('data-mail-test="failed"', $html);
        $this->assertStringContainsString('535 5.7.8 Username and Password not accepted', $html);
        $this->assertStringContainsString('SMTP conversation', $html);
        $this->assertStringNotContainsString(base64_encode('bad'), $html);
        Settings::flush();
        $this->assertSame('S3cret-Pass-9', Settings::secret('smtp_password'), 'test does not save');
        // Unreachable server
        $root->post('platform_settings.php', ['op' => 'email_test', 'test_to' => 'me@example.test', 'smtp_port' => TestEnv::freePort()] + $form);
        [, , $html] = $root->get('platform_settings.php?tab=email');
        $this->assertStringContainsString('SMTP connect to 127.0.0.1', $html);
        $this->assertStringContainsString('failed', $html);
        // The log shows the sends, without bodies.
        $this->assertStringContainsString('me@example.test', $html);
        // CSRF + permission
        [$s] = TestEnv::http('POST', self::$url . 'admin/platform_settings.php', null, [], $root->jar, ['op' => 'email', 'tab' => 'email', 'mail_transport' => 'mail']);
        $this->assertSame(419, $s);
        [$s] = (new AdminSession(self::$url, 'emboss'))->get('platform_settings.php?tab=email');
        $this->assertSame(403, $s);
        // Remove the saved password, back to PHP mail() for the next tests.
        $root->post('platform_settings.php', ['op' => 'email', 'tab' => 'email', 'mail_transport' => 'mail', 'clear_smtp_password' => '1', 'smtp_port' => 587, 'smtp_encryption' => 'tls']);
        Settings::flush();
        $this->assertSame('', Settings::secret('smtp_password'));
        $this->assertSame('mail', Settings::platform('mail_transport'));
    }

    // ------------------------------------------------------------------ forgot / reset password

    public function testForgotPasswordIsNeutralAndTheResetLinkWorksOnce(): void
    {
        [, , $html] = self::page('admin/login.php');
        $this->assertStringContainsString('data-forgot-link', $html);
        $this->assertStringContainsString('Email or username', $html);

        // Unknown address: same redirect + message, nothing sent.
        $t0 = microtime(true);
        [$s, , $head, $jar] = self::forgot('nobody@nowhere.test');
        $unknownMs = (microtime(true) - $t0) * 1000;
        $this->assertSame(302, $s);
        $this->assertStringContainsString('forgot_password.php?sent=1', $head);
        [, , $neutral] = self::page('admin/forgot_password.php?sent=1', $jar);
        $this->assertStringContainsString('data-pwreset-sent', $neutral);
        $this->assertSame([], self::outbox());

        // Known account (email in other case, spaces): same answer, one e-mail with the link.
        $old = new AdminSession(self::$url, 'emroot'); // a running session, must be revoked by the reset
        [$s] = $old->get('platform_overview.php');
        $this->assertSame(200, $s);
        $lock = date('Y-m-d H:i:s', time() + 900);
        DB::query('UPDATE users SET failed_attempts = 3, locked_until = :l WHERE id = :id', ['l' => $lock, 'id' => self::$u['root']]);
        $t0 = microtime(true);
        [$s, , $head, $jar2] = self::forgot('  ROOT@platform.TEST ');
        $knownMs = (microtime(true) - $t0) * 1000;
        $this->assertSame(302, $s);
        $this->assertStringContainsString('forgot_password.php?sent=1', $head);
        [, , $neutral2] = self::page('admin/forgot_password.php?sent=1', $jar2);
        $this->assertSame(strip_tags((string) preg_replace('/name="_csrf" value="[^"]+"/', '', $neutral)), strip_tags((string) preg_replace('/name="_csrf" value="[^"]+"/', '', $neutral2)), 'identical page');
        $this->assertGreaterThan(1000, $unknownMs, 'minimum answer time');
        $this->assertLessThan(1500, abs($knownMs - $unknownMs), 'roughly constant time');
        $mails = self::outbox();
        $this->assertCount(1, $mails);
        $this->assertSame(['Root@Platform.test'], $mails[0]['to']);
        $this->assertStringContainsString('Reset your password', $mails[0]['subject']);
        $this->assertStringContainsString('60 minutes', $mails[0]['text']);
        $this->assertStringContainsString('If you did not request this', $mails[0]['text']);
        $this->assertStringContainsString('<a href="' . self::$url . 'admin/reset_password.php?token=', (string) $mails[0]['html']);
        $token = self::tokenFrom($mails[0]['text']);
        $this->assertSame(64, strlen($token));
        $this->assertStringStartsWith(self::$url . 'admin/reset_password.php?token=', (string) preg_replace('/.*?(http\S+reset_password\S+).*/s', '$1', $mails[0]['text']), 'absolute URL');
        $row = DB::one('SELECT * FROM password_resets WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => self::$u['root']]);
        $this->assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash is stored');
        $this->assertSame('reset', $row['type']);
        $this->assertEqualsWithDelta(time() + 3600, strtotime((string) $row['expires_at']), 120);
        // The token is in no log / table.
        foreach (glob(HC_ROOT . '/logs/*.log') ?: [] as $f) {
            $this->assertStringNotContainsString($token, (string) file_get_contents($f), basename($f));
        }
        $this->assertStringNotContainsString($token, json_encode(DB::all('SELECT * FROM activity_logs')) . json_encode(DB::all('SELECT * FROM mail_log')));

        // Reset page: no referrer, validation, then success.
        [, , $html, , $head] = self::page('admin/reset_password.php?token=' . $token);
        $this->assertMatchesRegularExpression('/Referrer-Policy: no-referrer/i', $head);
        [$s, $body] = self::reset($token, 'short1', 'short1');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('at least 8 characters', $body);
        [$s, $body] = self::reset($token, 'NewPassw0rd2', 'Different99');
        $this->assertStringContainsString('do not match', $body);
        [$s, , $head] = self::reset($token, 'NewPassw0rd2');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('login.php', $head);
        $u = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$u['root']]);
        $this->assertTrue(password_verify('NewPassw0rd2', $u['password_hash']));
        $this->assertSame(0, (int) $u['failed_attempts']);
        $this->assertNull($u['locked_until'], 'lock cleared');
        $this->assertNotNull(DB::value('SELECT used_at FROM password_resets WHERE id = :id', ['id' => $row['id']]));
        // Every session of the user was revoked.
        [$s, , , $head] = $old->get('platform_overview.php');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('login.php', $head);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM user_sessions WHERE user_id = :u AND revoked = 0', ['u' => self::$u['root']]));
        // Activity log at platform level, "password changed" e-mail.
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'password_reset' AND entity_id = :u AND hotel_id IS NULL", ['u' => self::$u['root']]));
        $changed = array_values(array_filter(self::outbox(), fn ($m) => str_contains($m['subject'], 'Your password was changed')));
        $this->assertCount(1, $changed);
        // Single use.
        [$s, $body] = self::reset($token, 'Another1pass');
        $this->assertStringContainsString('data-pwreset-invalid', $body);
        $this->assertTrue(password_verify('NewPassw0rd2', (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => self::$u['root']])));
        // Login with the new password, by email in mixed case.
        $this->assertTrue(self::canLogin(' root@PLATFORM.test ', 'NewPassw0rd2'));
        $this->assertFalse(self::canLogin('emroot', self::PW));
        DB::query('UPDATE users SET password_hash = :p WHERE id = :id', ['p' => Auth::hash(self::PW), 'id' => self::$u['root']]);
    }

    public function testExpiredAndSupersededTokensAreRejected(): void
    {
        $t1 = PasswordReset::issue(self::$u['staff'], 'reset', '10.0.0.1');
        $this->assertNotNull(PasswordReset::check($t1));
        $t2 = PasswordReset::issue(self::$u['staff'], 'reset', '10.0.0.1');
        $this->assertNull(PasswordReset::check($t1), 'a new request invalidates older tokens');
        $this->assertNotNull(PasswordReset::check($t2));
        DB::query('UPDATE password_resets SET expires_at = :x WHERE token_hash = :h', ['x' => date('Y-m-d H:i:s', time() - 1), 'h' => hash('sha256', $t2)]);
        $this->assertNull(PasswordReset::check($t2), 'expired');
        [, $body] = self::reset($t2, 'Whatever123');
        $this->assertStringContainsString('data-pwreset-invalid', $body);
        [$ok] = PasswordReset::complete($t2, 'Whatever123', 'Whatever123');
        $this->assertFalse($ok);
        $this->assertNull(PasswordReset::check('not-a-token'));
        $this->assertNull(PasswordReset::check(str_repeat('a', 64)));
        // A token stops working when the account is disabled.
        $t3 = PasswordReset::issue(self::$u['staff'], 'reset');
        DB::query('UPDATE users SET is_active = 0 WHERE id = :id', ['id' => self::$u['staff']]);
        $this->assertNull(PasswordReset::check($t3));
        DB::query('UPDATE users SET is_active = 1 WHERE id = :id', ['id' => self::$u['staff']]);
        // Password = username / email refused.
        $t4 = PasswordReset::issue(self::$u['staff'], 'reset');
        [$ok, $err] = PasswordReset::complete($t4, 'staff@alpha.test', 'staff@alpha.test');
        $this->assertFalse($ok);
        $this->assertNotNull($err);
    }

    public function testRateLimitsPerIpAndPerAccount(): void
    {
        // Per account: 3 e-mails per hour, from any IP; the answer stays neutral.
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame('ok', PasswordReset::request('emstaff', '10.1.0.' . $i));
        }
        $this->assertCount(3, self::outbox());
        // Per IP: 5 requests per 15 minutes (any account), then 429.
        DB::query('DELETE FROM rate_limits');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('ok', PasswordReset::request('unknown' . $i . '@x.test', '10.2.0.1'));
        }
        $this->assertSame('rate_limited', PasswordReset::request('emstaff', '10.2.0.1'));
        DB::query('DELETE FROM rate_limits');
        for ($i = 0; $i < 5; $i++) {
            [$s] = self::forgot('nobody' . $i . '@x.test');
            $this->assertSame(302, $s);
        }
        [$s, $body] = self::forgot('emstaff');
        $this->assertSame(429, $s);
        $this->assertStringContainsString('Too many requests', $body);
    }

    public function testCsrfIsRequiredOnBothForms(): void
    {
        [$jar] = self::page('admin/forgot_password.php');
        [$s] = TestEnv::http('POST', self::$url . 'admin/forgot_password.php', null, [], $jar, ['login' => 'emstaff']);
        $this->assertSame(419, $s);
        [$s] = TestEnv::http('POST', self::$url . 'admin/forgot_password.php', null, [], $jar, ['_csrf' => 'forged', 'login' => 'emstaff']);
        $this->assertSame(419, $s);
        $this->assertSame([], self::outbox());
        $token = PasswordReset::issue(self::$u['staff'], 'reset');
        $jar = (string) tempnam(self::$tmp, 'jar');
        TestEnv::http('GET', self::$url . 'admin/reset_password.php?token=' . $token, null, [], $jar);
        [$s] = TestEnv::http('POST', self::$url . 'admin/reset_password.php', null, [], $jar, ['password' => 'Hacked1234', 'password_confirm' => 'Hacked1234']);
        $this->assertSame(419, $s);
        $this->assertTrue(password_verify(self::PW, (string) DB::value('SELECT password_hash FROM users WHERE id = :id', ['id' => self::$u['staff']])));
    }

    public function testEveryRoleCanResetButBlockedAccountsGetNothing(): void
    {
        $cases = ['res@reseller.test' => 'res', 'emchain' => 'chain', 'BOSS@alpha.test' => 'boss', 'emstaff' => 'staff'];
        foreach ($cases as $login => $key) {
            DB::query('DELETE FROM rate_limits');
            @unlink((string) Mailer::outboxPath());
            $this->assertSame('ok', PasswordReset::request($login, '10.3.0.1'));
            $mails = self::outbox();
            $this->assertCount(1, $mails, $login);
            $email = (string) DB::value('SELECT email FROM users WHERE id = :id', ['id' => self::$u[$key]]);
            $this->assertSame([$email], $mails[0]['to']);
            $token = self::tokenFrom($mails[0]['text']);
            [$s] = self::reset($token, 'Reset' . $key . '2026');
            $this->assertSame(302, $s, $login);
            $this->assertTrue(self::canLogin($email, 'Reset' . $key . '2026'), $login);
            DB::query('UPDATE users SET password_hash = :p WHERE id = :id', ['p' => Auth::hash(self::PW), 'id' => self::$u[$key]]);
        }
        // Language + white-label: the Hindi customer owner gets a Hindi mail with the customer's brand and branded link.
        DB::query('DELETE FROM rate_limits');
        @unlink((string) Mailer::outboxPath());
        PasswordReset::request('emboss', '10.3.0.2');
        $m = self::outbox()[0];
        $this->assertStringContainsString('Alpha Signage', $m['subject']);
        $this->assertStringContainsString('#0055AA', (string) $m['html']);
        $this->assertMatchesRegularExpression('/reset_password\.php\?token=[a-f0-9]{64}&b=alpha-mail-stores/', $m['text']);
        $this->assertStringContainsString(I18n::translate('Reset your password', 'hi'), $m['subject']);
        $this->assertNotSame('Reset your password', I18n::translate('Reset your password', 'hi'));
        // Gujarati reseller
        DB::query('DELETE FROM rate_limits');
        @unlink((string) Mailer::outboxPath());
        PasswordReset::request('emres', '10.3.0.3');
        $this->assertStringContainsString(I18n::translate('Reset your password', 'gu'), self::outbox()[0]['subject']);
        // Inactive user, archived customer, suspended reseller, unknown: nothing, neutral.
        DB::query('DELETE FROM rate_limits');
        @unlink((string) Mailer::outboxPath());
        foreach (['off@alpha.test', 'emarch', 'gone@reseller.test', 'ghost'] as $login) {
            $this->assertSame('ok', PasswordReset::request($login, '10.3.1.' . strlen($login)));
        }
        $this->assertSame([], self::outbox());
        [$s, , $head] = self::forgot('emarch');
        $this->assertSame(302, $s);
        $this->assertStringContainsString('sent=1', $head);
    }

    // ------------------------------------------------------------------ login by email

    public function testLoginByEmailCaseInsensitiveAndUniqueEmails(): void
    {
        $this->assertTrue(self::canLogin('  BoSs@Alpha.TEST ', self::PW));
        $this->assertTrue(self::canLogin('EMBOSS', self::PW), 'username, case-insensitive');
        $this->assertFalse(self::canLogin('boss@alpha.test', 'wrong-pass1'));
        DB::query('UPDATE users SET failed_attempts = 0, locked_until = NULL');
        // Creating a user with an existing email (other case) is refused.
        $boss = new AdminSession(self::$url, 'emboss');
        [$s] = $boss->post('users.php', ['op' => 'save', 'username' => 'dupmail', 'email' => 'STAFF@alpha.test', 'role' => 'staff', 'language' => 'en', 'is_active' => 1, 'password' => 'Passw0rd99', 'password_confirm' => 'Passw0rd99']);
        $this->assertSame(302, $s);
        $this->assertFalse((bool) DB::value("SELECT id FROM users WHERE username = 'dupmail'"));
        [$acc, $errors] = Hotels::validateAdmin(['admin_username' => 'dup2', 'admin_email' => 'Root@PLATFORM.test', 'admin_password' => 'Passw0rd99'], true);
        $this->assertNotSame([], $errors);
        // The recovery CLI still works (and finds the user by email).
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HC_ROOT . '/bin/make_super_admin.php') . ' --email=cli-admin@platform.test --password=CliPassw0rd1 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertTrue(self::canLogin('CLI-Admin@platform.test', 'CliPassw0rd1'));
    }

    // ------------------------------------------------------------------ sign-up

    public function testSignupSendsOtpThenWelcomeAndSurfacesMailErrors(): void
    {
        foreach (['signup_enabled' => '1', 'signup_mode' => 'otp', 'trial_days' => '10', 'signup_notify_email' => 'sales@platform.test'] as $k => $v) {
            Settings::setPlatform($k, $v);
        }
        $got = [];
        Mailer::$testHook = static function (array $m) use (&$got): bool {
            $got[] = $m;
            return true;
        };
        $data = ['hotel_name' => 'Email Cafe', 'city' => 'Dwarka', 'owner_name' => 'Asha', 'mobile' => '+91 98250 77777', 'email' => 'asha@cafe.test',
            'tv_estimate' => 2, 'language' => 'gu', 'password' => 'CafePass2026'];
        $r = Signup::register($data, '10.9.0.1', 'test');
        $this->assertSame('verify', $r['next']);
        $this->assertCount(1, $got);
        $this->assertSame(['asha@cafe.test'], $got[0]['to']);
        preg_match('/\b(\d{6})\b/', $got[0]['text'], $m);
        $code = $m[1];
        $this->assertStringContainsString($code, (string) $got[0]['html'], 'big code in the HTML');
        $this->assertStringContainsString(I18n::translate('The code is valid for 15 minutes.', 'gu'), $got[0]['text']);
        $got = [];
        [$ok, , $hid, $uid] = Signup::verifyOtp($r['id'], $code);
        $this->assertTrue($ok);
        $welcome = array_values(array_filter($got, fn ($x) => $x['to'] === ['asha@cafe.test']));
        $this->assertCount(1, $welcome, 'welcome e-mail');
        $w = $welcome[0];
        $this->assertStringContainsString(I18n::translate('your free trial is ready', 'gu'), $w['subject']);
        $this->assertStringContainsString('admin/login.php', $w['text']);
        $this->assertStringContainsString((string) DB::value('SELECT username FROM users WHERE id = :id', ['id' => $uid]), $w['text']);
        $this->assertStringContainsString('asha@cafe.test', $w['text']);
        $this->assertStringContainsString('10', $w['text']);
        $this->assertStringContainsString((string) Settings::getFor($hid, 'registration_key'), $w['text'], 'TV pairing steps');
        $platform = array_values(array_filter($got, fn ($x) => $x['to'] === ['sales@platform.test']));
        $this->assertCount(1, $platform, 'platform notified');

        // SMTP failure: no silent failure — flag, error in the mail log, the send does not count.
        Mailer::$testHook = static fn (array $m): bool => false;
        $data['email'] = 'ravi@cafe2.test';
        $data['mobile'] = '+91 98250 88888';
        $r2 = Signup::register($data, '10.9.0.2', 'test');
        $this->assertTrue(Signup::$mailFailed);
        $row = Signup::find($r2['id']);
        $this->assertSame(0, (int) $row['otp_sends']);
        $this->assertNull($row['otp_hash']);
        $this->assertSame('failed', DB::value('SELECT status FROM mail_log ORDER BY id DESC LIMIT 1'));
        Mailer::$testHook = null;
        $this->assertTrue(Signup::sendOtp($r2['id'], true), 'retry works at once');
        $this->assertFalse(Signup::$mailFailed);

        // Over HTTP with an unreachable SMTP server the visitor sees "could not send" instead of nothing.
        DB::query('DELETE FROM rate_limits');
        Settings::setPlatform('mail_transport', 'smtp');
        Settings::setPlatform('smtp_host', '127.0.0.1');
        Settings::setPlatform('smtp_port', (string) TestEnv::freePort());
        Settings::setPlatform('smtp_encryption', 'none');
        Settings::setPlatform('signup_min_seconds', '0');
        try {
            $jar = (string) tempnam(self::$tmp, 'jar');
            [, , $html] = TestEnv::http('GET', self::$url . 'signup.php', null, [], $jar);
            preg_match('/name="_csrf" value="([^"]+)"/', $html, $c);
            preg_match('/name="ts" value="([^"]+)"/', $html, $ts);
            [$s, , , $head] = TestEnv::http('POST', self::$url . 'signup.php', null, [], $jar, ['_csrf' => html_entity_decode($c[1]), 'op' => 'register', 'ts' => html_entity_decode($ts[1]), 'website' => '',
                'hotel_name' => 'Fail Mail Co', 'city' => 'Okha', 'owner_name' => 'Mira', 'mobile' => '+91 98250 99999', 'email' => 'mira@failmail.test', 'tv_estimate' => 1,
                'language' => 'en', 'password' => 'MiraPass2026', 'accept_terms' => 1]);
            $this->assertSame(302, $s);
            $this->assertStringContainsString('step=verify', $head);
            [, , $html] = TestEnv::http('GET', self::$url . 'signup.php?step=verify', null, [], $jar);
            $this->assertStringContainsString('data-signup-mail-failed', $html);
            $this->assertStringContainsString('help@platform.test', $html, 'support contact');
            $this->assertStringContainsString('SMTP connect', (string) DB::value("SELECT error FROM mail_log WHERE recipient = 'mira@failmail.test' ORDER BY id DESC LIMIT 1"));
        } finally {
            Settings::setPlatform('mail_transport', 'mail');
            Settings::setPlatform('smtp_host', '');
            Settings::setPlatform('signup_enabled', '0');
        }
    }

    // ------------------------------------------------------------------ invites

    public function testAdminCreatedUsersGetAnInviteLinkToSetThePassword(): void
    {
        $root = new AdminSession(self::$url, 'emroot');
        $role = 'manager';
        [$s] = $root->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_create', 'id' => self::$h['alpha'], 'admin_username' => 'invitee',
            'admin_name' => 'New Manager', 'admin_email' => 'Invitee@Alpha.test', 'admin_password' => '', 'role' => $role, 'language' => 'gu', 'send_invite' => 1]);
        $this->assertSame(302, $s);
        $uid = (int) DB::value("SELECT id FROM users WHERE username = 'invitee'");
        $this->assertGreaterThan(0, $uid);
        $mails = array_values(array_filter(self::outbox(), fn ($m) => $m['to'] === ['Invitee@Alpha.test']));
        $this->assertCount(1, $mails);
        $this->assertStringContainsString(I18n::translate('Your account is ready', 'gu'), $mails[0]['subject']);
        $this->assertStringContainsString('invitee', $mails[0]['text']);
        $token = self::tokenFrom($mails[0]['text']);
        $row = DB::one('SELECT * FROM password_resets WHERE token_hash = :h', ['h' => hash('sha256', $token)]);
        $this->assertSame('invite', $row['type']);
        $this->assertEqualsWithDelta(time() + 72 * 3600, strtotime((string) $row['expires_at']), 120);
        $this->assertFalse(self::canLogin('invitee', ''), 'no usable password before the invite is used');
        $jar = (string) tempnam(self::$tmp, 'jar');
        TestEnv::http('GET', self::$url . 'admin/reset_password.php?token=' . $token . '&b=alpha-mail-stores', null, [], $jar);
        [, $csrf, $html] = self::page('admin/reset_password.php?b=alpha-mail-stores', $jar);
        $this->assertStringContainsString('Alpha Signage', $html, 'customer branding on the page');
        [$s, , , $head] = TestEnv::http('POST', self::$url . 'admin/reset_password.php?b=alpha-mail-stores', null, [], $jar, ['_csrf' => $csrf, 'password' => 'Invited2026', 'password_confirm' => 'Invited2026']);
        $this->assertSame(302, $s);
        $this->assertStringContainsString('login.php?b=alpha-mail-stores', $head);
        $this->assertTrue(self::canLogin('invitee@alpha.test', 'Invited2026'));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'password_set_invite' AND entity_id = :u AND hotel_id = :h", ['u' => $uid, 'h' => self::$h['alpha']]));
        // The direct password option still works (no invite).
        [$s] = $root->post('platform_customer.php?id=' . self::$h['alpha'] . '&tab=users', ['op' => 'user_create', 'id' => self::$h['alpha'], 'admin_username' => 'direct1',
            'admin_email' => 'direct1@alpha.test', 'admin_password' => 'Direct2026x', 'role' => $role]);
        $this->assertTrue(self::canLogin('direct1', 'Direct2026x'));
        $this->assertFalse((bool) DB::value("SELECT id FROM password_resets WHERE user_id = (SELECT id FROM users WHERE username = 'direct1')"));
        // Customer admin: Users → new user with invite.
        $boss = new AdminSession(self::$url, 'emboss');
        @unlink((string) Mailer::outboxPath());
        [$s] = $boss->post('users.php', ['op' => 'save', 'username' => 'cashier1', 'email' => 'cashier1@alpha.test', 'full_name' => 'Cashier', 'role' => 'staff', 'language' => 'en',
            'is_active' => 1, 'password' => '', 'password_confirm' => '', 'send_invite' => 1]);
        $this->assertSame(302, $s);
        $this->assertTrue((bool) DB::value("SELECT id FROM users WHERE username = 'cashier1'"));
        $mails = self::outbox();
        $this->assertCount(1, $mails);
        $this->assertSame(['cashier1@alpha.test'], $mails[0]['to']);
        $this->assertSame(64, strlen(self::tokenFrom($mails[0]['text'])));
        // Platform admins / resellers / chain admins: invite from the Super Admin console.
        @unlink((string) Mailer::outboxPath());
        $root->post('platform_settings.php', ['op' => 'add_admin', 'tab' => 'admins', 'admin_username' => 'newroot', 'admin_email' => 'newroot@platform.test', 'admin_password' => '', 'send_invite' => 1]);
        $this->assertSame(['newroot@platform.test'], self::outbox()[0]['to'] ?? null);
        // Without the checkbox an empty password is still refused.
        $root->post('platform_settings.php', ['op' => 'add_admin', 'tab' => 'admins', 'admin_username' => 'nopass', 'admin_email' => 'nopass@platform.test', 'admin_password' => '']);
        $this->assertFalse((bool) DB::value("SELECT id FROM users WHERE username = 'nopass'"));
    }
}
