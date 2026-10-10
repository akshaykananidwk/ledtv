<?php
declare(strict_types=1);

/**
 * Outgoing e-mail (2.8, docs/modules/email.md). No Composer: a small RFC 5321 SMTP client and the PHP
 * mail() fallback, proper MIME (multipart/alternative text + HTML, RFC 2047 UTF-8 headers, Message-ID,
 * Date), header-injection safe (CR / LF / NUL stripped from every header value).
 *
 * Super Admin → Platform settings → Email (platform settings, hotel 0):
 *   mail_transport      smtp | mail (PHP mail(), default)
 *   smtp_host / smtp_port / smtp_encryption (tls = STARTTLS, ssl = implicit TLS, none)
 *   smtp_username / smtp_password (encrypted with APP_KEY, Settings::setSecret)
 *   smtp_allow_self_signed  1 = do not verify the server certificate (own servers only)
 *   mail_from_email / mail_from_name (default: platform_from_email, platform name)
 *
 * Every send is logged in mail_log (last LOG_KEEP rows: time, recipient, subject, status, error — never
 * the body, it may contain reset links / codes). Failures are also written to logs/mail.log.
 *
 * Tests never send real mail: Mailer::$testHook / Notifier::$mailer capture messages in-process, and a
 * test sandbox (HC_TESTING, a hotelcast_sandbox_ root, or config 'mail_outbox') appends each message to
 * an outbox file instead. Only SMTP to a loopback host (a fake test server) is allowed there.
 */
final class Mailer
{
    public const TRANSPORTS = ['mail', 'smtp'];
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];
    public const LOG_KEEP = 200;
    public const CONNECT_TIMEOUT = 15;
    public const IO_TIMEOUT = 30;

    /** Test hook: fn(array $message): bool. $message keys: to, subject, text, html, from, from_name, reply_to, raw. */
    public static $testHook = null;

    /** Error of the last failed send ('' when it worked). */
    public static string $lastError = '';

    /** SMTP conversation of the last SMTP send (passwords masked), for the "Send test email" button. */
    public static array $lastTranscript = [];

    // ------------------------------------------------------------------ configuration

    /** Effective configuration (platform settings), $override wins (settings form test with unsaved values). */
    public static function config(array $override = []): array
    {
        $g = static fn (string $k, string $d = ''): string => trim((string) Settings::platform($k, $d));
        $transport = $g('mail_transport', 'mail');
        $enc = $g('smtp_encryption', 'tls');
        $cfg = [
            'transport' => in_array($transport, self::TRANSPORTS, true) ? $transport : 'mail',
            'host' => $g('smtp_host'),
            'port' => (int) ($g('smtp_port', '587') ?: 587),
            'encryption' => in_array($enc, self::ENCRYPTIONS, true) ? $enc : 'tls',
            'username' => $g('smtp_username'),
            'password' => '',
            'allow_self_signed' => $g('smtp_allow_self_signed', '0') === '1',
            'from_email' => $g('mail_from_email'),
            'from_name' => $g('mail_from_name'),
        ];
        try {
            $cfg['password'] = Settings::secret('smtp_password');
        } catch (Throwable) {
            $cfg['password'] = ''; // APP_KEY missing: cannot decrypt
        }
        $cfg = array_replace($cfg, $override);
        if ($cfg['from_email'] === '' || !filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
            $cfg['from_email'] = self::defaultFrom();
        }
        if (trim((string) $cfg['from_name']) === '') {
            $cfg['from_name'] = Branding::get(0)['product'];
        }
        return $cfg;
    }

    /** Sender when none is configured: platform_from_email, else no-reply@<host of base_url>. */
    public static function defaultFrom(): string
    {
        foreach (['platform_from_email', 'notify_from_email'] as $k) {
            $v = trim((string) Settings::platform($k, ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }
        $host = (string) (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
        $host = preg_replace('/^www\./', '', $host) ?: 'localhost';
        return 'no-reply@' . (str_contains($host, '.') ? $host : $host . '.localdomain');
    }

    // ------------------------------------------------------------------ sending

    /**
     * Send one message. $to: address or list (comma separated string / array). $html optional (the
     * plain text is always included). $opts: from, from_name, reply_to, config (override array),
     * log (default true). Returns true when the transport accepted it; Mailer::$lastError otherwise.
     */
    public static function send(string|array $to, string $subject, string $text, ?string $html = null, array $opts = []): bool
    {
        self::$lastError = '';
        self::$lastTranscript = [];
        $list = is_array($to) ? $to : explode(',', $to);
        $rcpts = [];
        foreach ($list as $addr) {
            $addr = self::cleanHeader((string) $addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $rcpts[] = $addr;
            }
        }
        $rcpts = array_values(array_unique($rcpts));
        if (!$rcpts) {
            self::$lastError = 'No valid recipient address';
            return false;
        }
        $cfg = self::config((array) ($opts['config'] ?? []));
        $from = self::cleanHeader((string) ($opts['from'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = $cfg['from_email'];
        }
        // With SMTP the configured sender is used (providers reject other From addresses); a caller's
        // address becomes the Reply-To instead.
        $replyTo = self::cleanHeader((string) ($opts['reply_to'] ?? ''));
        if ($cfg['transport'] === 'smtp' && $from !== $cfg['from_email']) {
            $replyTo = $replyTo !== '' ? $replyTo : $from;
            $from = $cfg['from_email'];
        }
        $fromName = self::cleanHeader((string) ($opts['from_name'] ?? '')) ?: $cfg['from_name'];
        $msg = self::build([
            'to' => $rcpts, 'subject' => $subject, 'text' => $text, 'html' => $html,
            'from' => $from, 'from_name' => $fromName, 'reply_to' => $replyTo,
        ]);
        $log = ($opts['log'] ?? true) !== false;

        $hook = self::$testHook;
        $legacy = class_exists('Notifier', false) ? Notifier::$mailer : null;
        if ($hook || $legacy) {
            $ok = true;
            if ($hook) {
                $ok = (bool) $hook($msg);
            }
            if ($legacy) {
                foreach ($rcpts as $r) {
                    $ok = (bool) $legacy($r, $subject, $text) && $ok;
                }
            }
            if ($log) {
                self::log($rcpts, $subject, 'test', $ok ? 'sent' : 'failed', $ok ? '' : 'test hook returned false');
            }
            return $ok;
        }

        $outbox = self::outboxPath();
        if ($outbox !== null && !($cfg['transport'] === 'smtp' && self::isLoopback((string) $cfg['host']))) {
            @mkdir(dirname($outbox), 0755, true);
            $ok = @file_put_contents($outbox, json_encode($msg + ['time' => date('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
            if ($log) {
                self::log($rcpts, $subject, 'outbox', $ok ? 'sent' : 'failed', $ok ? '' : 'outbox not writable');
            }
            return $ok;
        }

        try {
            if ($cfg['transport'] === 'smtp') {
                if ($cfg['host'] === '') {
                    throw new RuntimeException('SMTP host is not set (Platform settings → Email)');
                }
                self::smtpSend($cfg, $from, $rcpts, $msg['raw']);
            } else {
                self::phpMail($msg, $from);
            }
            if ($log) {
                self::log($rcpts, $subject, $cfg['transport'], 'sent');
            }
            return true;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            if ($log) {
                self::log($rcpts, $subject, $cfg['transport'], 'failed', self::$lastError);
            }
            Logger::write('mail', 'error', 'Email not sent: ' . self::$lastError, ['to' => self::maskAddress($rcpts[0]), 'transport' => $cfg['transport']]);
            return false;
        }
    }

    /** PHP mail() transport. Throws when mail() refuses the message. */
    private static function phpMail(array $msg, string $from): void
    {
        if (!function_exists('mail')) {
            throw new RuntimeException('PHP mail() is disabled on this server — configure SMTP');
        }
        // mail() adds To and Subject itself.
        $headers = [];
        foreach ($msg['headers'] as $name => $value) {
            if ($name !== 'To' && $name !== 'Subject') {
                $headers[] = $name . ': ' . $value;
            }
        }
        $params = preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+$/', $from) ? '-f' . $from : '';
        error_clear_last();
        $ok = @mail(implode(', ', $msg['to']), $msg['headers']['Subject'], $msg['body'], implode("\r\n", $headers), $params);
        if (!$ok) {
            $err = error_get_last();
            throw new RuntimeException('PHP mail() failed' . ($err ? ': ' . $err['message'] : ' (the server has no working sendmail) — configure SMTP'));
        }
    }

    /** Test sandboxes write messages to a file instead of sending. null = send for real. */
    public static function outboxPath(): ?string
    {
        $cfg = (string) Config::get('mail_outbox', '');
        if ($cfg !== '') {
            return $cfg;
        }
        if (defined('HC_TESTING') || str_contains(HC_ROOT, 'hotelcast_sandbox_')) {
            return HC_ROOT . '/storage/mail_outbox.jsonl';
        }
        return null;
    }

    /** Messages in the outbox file (tests). */
    public static function outbox(?string $path = null): array
    {
        $path ??= self::outboxPath();
        if ($path === null || !is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $m = json_decode($line, true);
            if (is_array($m)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);
    }

    // ------------------------------------------------------------------ MIME

    /** Strip CR, LF and NUL (header injection) and trim. */
    public static function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    /** RFC 2047 encoded-words (UTF-8, base64) for non-ASCII text, folded at 75 characters. */
    public static function encodeHeader(string $value): string
    {
        $value = self::cleanHeader($value);
        if ($value === '' || !preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $ch) {
            // 45 raw bytes → 60 base64 chars + 12 for =?UTF-8?B??= = 72 ≤ 75
            if (strlen($chunk . $ch) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    /** "Name <addr>" with an encoded / quoted display name. */
    public static function address(string $email, string $name = ''): string
    {
        $email = self::cleanHeader($email);
        $name = self::cleanHeader($name);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return self::encodeHeader($name) . ' <' . $email . '>';
        }
        return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
    }

    /**
     * Build the message. Returns ['to', 'subject', 'text', 'html', 'from', 'from_name', 'reply_to',
     * 'headers' (name => encoded value), 'body', 'raw' (headers + body, CRLF), 'message_id'].
     */
    public static function build(array $m): array
    {
        $from = self::cleanHeader((string) $m['from']);
        $domain = (string) (substr((string) strrchr($from, '@'), 1) ?: 'localhost');
        $messageId = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . preg_replace('/[^A-Za-z0-9.\-]/', '', $domain) . '>';
        $subject = mb_substr(self::cleanHeader((string) $m['subject']), 0, 250);
        $text = self::crlf((string) $m['text']);
        $html = isset($m['html']) && $m['html'] !== null && $m['html'] !== '' ? self::crlf((string) $m['html']) : null;
        $to = array_map(static fn ($a) => self::cleanHeader((string) $a), (array) $m['to']);

        $headers = [
            'Date' => date('r'),
            'From' => self::address($from, (string) ($m['from_name'] ?? '')),
            'To' => implode(', ', $to),
            'Subject' => self::encodeHeader($subject),
            'Message-ID' => $messageId,
            'MIME-Version' => '1.0',
        ];
        $replyTo = self::cleanHeader((string) ($m['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $replyTo;
        }
        $headers['X-Mailer'] = 'KrishnaCloud-Mailer/' . self::cleanHeader((string) (Version::current()['version'] ?? '2'));
        if ($html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
            $body = self::qp($text);
        } else {
            $boundary = '=_hc_' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "This is a multi-part message in MIME format.\r\n\r\n"
                . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($text) . "\r\n"
                . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($html) . "\r\n"
                . '--' . $boundary . "--\r\n";
        }
        $raw = '';
        foreach ($headers as $k => $v) {
            $raw .= $k . ': ' . $v . "\r\n";
        }
        $raw .= "\r\n" . $body;
        return [
            'to' => $to, 'subject' => $subject, 'text' => (string) $m['text'], 'html' => $m['html'] ?? null,
            'from' => $from, 'from_name' => (string) ($m['from_name'] ?? ''), 'reply_to' => $replyTo,
            'headers' => $headers, 'body' => $body, 'raw' => $raw, 'message_id' => $messageId,
        ];
    }

    private static function crlf(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $s);
    }

    private static function qp(string $s): string
    {
        return quoted_printable_encode($s);
    }

    // ------------------------------------------------------------------ SMTP client

    /**
     * Deliver $data (complete message, CRLF) over SMTP. Throws RuntimeException with the failing step
     * and the server's answer. $cfg: host, port, encryption (tls|ssl|none), username, password,
     * allow_self_signed, timeout (optional).
     */
    public static function smtpSend(array $cfg, string $from, array $rcpts, string $data): void
    {
        self::$lastTranscript = [];
        $host = (string) $cfg['host'];
        $port = (int) ($cfg['port'] ?? 0) ?: (($cfg['encryption'] ?? '') === 'ssl' ? 465 : 587);
        $enc = (string) ($cfg['encryption'] ?? 'tls');
        $timeout = (int) ($cfg['timeout'] ?? self::CONNECT_TIMEOUT);
        $ctx = stream_context_create(['ssl' => self::sslOptions($host, !empty($cfg['allow_self_signed']))]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        self::$lastTranscript[] = '-- connect ' . $remote;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $tls = $enc === 'ssl' ? ' (TLS handshake / certificate problem or wrong port; port 465 needs SSL)' : '';
            throw new RuntimeException('SMTP connect to ' . $host . ':' . $port . ' failed: ' . ($errstr !== '' ? $errstr : 'error ' . $errno) . $tls);
        }
        stream_set_timeout($fp, (int) ($cfg['io_timeout'] ?? self::IO_TIMEOUT));
        try {
            self::expect($fp, 220, 'greeting');
            $helo = self::heloName();
            $ext = self::ehlo($fp, $helo);
            if ($enc === 'tls') {
                if (!isset($ext['STARTTLS'])) {
                    throw new RuntimeException('SMTP server does not offer STARTTLS on port ' . $port . ' (choose SSL for port 465, or "none")');
                }
                self::cmd($fp, 'STARTTLS', 220, 'STARTTLS');
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                error_clear_last();
                if (@stream_socket_enable_crypto($fp, true, $method) !== true) {
                    $err = error_get_last();
                    throw new RuntimeException('SMTP STARTTLS handshake failed' . ($err ? ': ' . $err['message'] : '') . ' (certificate not trusted? see "Allow self-signed")');
                }
                self::$lastTranscript[] = '-- TLS started';
                $ext = self::ehlo($fp, $helo);
            }
            $user = (string) ($cfg['username'] ?? '');
            if ($user !== '') {
                self::auth($fp, $ext, $user, (string) ($cfg['password'] ?? ''));
            }
            self::cmd($fp, 'MAIL FROM:<' . self::cleanHeader($from) . '>', 250, 'MAIL FROM');
            foreach ($rcpts as $r) {
                self::cmd($fp, 'RCPT TO:<' . self::cleanHeader((string) $r) . '>', [250, 251], 'RCPT TO');
            }
            self::cmd($fp, 'DATA', 354, 'DATA');
            self::write($fp, self::dotStuff($data) . "\r\n.\r\n", '[message ' . strlen($data) . ' bytes]');
            self::expect($fp, 250, 'end of DATA');
            try {
                self::cmd($fp, 'QUIT', 221, 'QUIT');
            } catch (RuntimeException) {
                // already accepted: a missing 221 does not matter
            }
        } finally {
            fclose($fp);
        }
    }

    public static function sslOptions(string $host, bool $allowSelfSigned): array
    {
        return [
            'verify_peer' => !$allowSelfSigned,
            'verify_peer_name' => !$allowSelfSigned,
            'allow_self_signed' => $allowSelfSigned,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ];
    }

    /** "." at the start of a line is doubled (RFC 5321 4.5.2); line endings normalised to CRLF. */
    public static function dotStuff(string $data): string
    {
        $data = self::crlf($data);
        $data = (string) preg_replace('/^\./m', '..', $data);
        return rtrim($data, "\r\n");
    }

    private static function heloName(): string
    {
        $h = (string) (parse_url(base_url(), PHP_URL_HOST) ?: '');
        if ($h === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $h)) {
            $h = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname()) ?: 'localhost';
        }
        return filter_var($h, FILTER_VALIDATE_IP) ? '[' . $h . ']' : $h;
    }

    /** EHLO (falls back to HELO). Returns extensions: NAME => parameters. */
    private static function ehlo($fp, string $helo): array
    {
        self::write($fp, 'EHLO ' . $helo . "\r\n");
        [$code, $lines] = self::read($fp);
        if ($code !== 250) {
            self::cmd($fp, 'HELO ' . $helo, 250, 'HELO');
            return [];
        }
        $ext = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = preg_split('/[\s=]+/', trim($l)) ?: [];
            $name = strtoupper((string) array_shift($parts));
            if ($name !== '') {
                $ext[$name] = array_map('strtoupper', $parts);
            }
        }
        return $ext;
    }

    private static function auth($fp, array $ext, string $user, string $pass): void
    {
        $mechs = $ext['AUTH'] ?? [];
        $mech = in_array('PLAIN', $mechs, true) ? 'PLAIN' : (in_array('LOGIN', $mechs, true) || !$mechs ? 'LOGIN' : '');
        if ($mech === '') {
            throw new RuntimeException('SMTP server offers no supported login method (AUTH ' . implode(' ', $mechs) . '; PLAIN or LOGIN needed)');
        }
        if ($mech === 'PLAIN') {
            self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), 235, 'AUTH PLAIN', 'AUTH PLAIN ****');
            return;
        }
        self::cmd($fp, 'AUTH LOGIN', 334, 'AUTH LOGIN');
        self::cmd($fp, base64_encode($user), 334, 'AUTH LOGIN (username)', '[username]');
        self::cmd($fp, base64_encode($pass), 235, 'AUTH LOGIN (password)', '[password ****]');
    }

    /** Send a command and check the reply code. $shown: what the transcript records instead of the command. */
    private static function cmd($fp, string $line, int|array $expect, string $step, ?string $shown = null): array
    {
        self::write($fp, $line . "\r\n", $shown);
        return self::expect($fp, $expect, $step);
    }

    private static function expect($fp, int|array $expect, string $step): array
    {
        [$code, $lines] = self::read($fp);
        if (!in_array($code, (array) $expect, true)) {
            $hint = '';
            if ($code === 535 || $code === 534) {
                $hint = ' — wrong username / password (Gmail: use an App Password, not your normal password)';
            }
            throw new RuntimeException('SMTP error at ' . $step . ': ' . ($code ?: 'no answer') . ' ' . trim(implode(' ', $lines)) . $hint);
        }
        return [$code, $lines];
    }

    private static function write($fp, string $data, ?string $shown = null): void
    {
        self::$lastTranscript[] = 'C: ' . ($shown ?? rtrim($data, "\r\n"));
        $len = strlen($data);
        for ($done = 0; $done < $len;) {
            $n = @fwrite($fp, substr($data, $done, 8192));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP connection lost while sending');
            }
            $done += $n;
        }
    }

    /** One (possibly multi-line) reply: [code, [text lines]]. */
    private static function read($fp): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($fp, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('SMTP server did not answer in time');
                }
                break;
            }
            self::$lastTranscript[] = 'S: ' . rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4), "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [$code, $lines];
    }

    // ------------------------------------------------------------------ log

    /** Record a send in mail_log (keeps the newest LOG_KEEP rows). Never stores the body. */
    public static function log(array $to, string $subject, string $transport, string $status, string $error = ''): void
    {
        try {
            DB::insert('mail_log', [
                'recipient' => mb_substr(implode(', ', $to), 0, 190),
                'subject' => mb_substr(self::redact($subject), 0, 190),
                'transport' => substr($transport, 0, 10),
                'status' => $status,
                'error' => $error === '' ? null : mb_substr($error, 0, 500),
                'created_at' => now(),
            ]);
            $cut = (int) DB::value('SELECT id FROM mail_log ORDER BY id DESC LIMIT 1 OFFSET ' . self::LOG_KEEP);
            if ($cut > 0) {
                DB::query('DELETE FROM mail_log WHERE id <= :c', ['c' => $cut]);
            }
        } catch (Throwable) {
            // before migration 035 / no database: logging must never break sending
        }
    }

    /** Subjects can carry one-time codes ("your verification code 123456"): mask digit runs of 6+. */
    public static function redact(string $s): string
    {
        return (string) preg_replace('/\b\d{6,}\b/', '******', $s);
    }

    public static function recentLog(int $limit = self::LOG_KEEP): array
    {
        try {
            return DB::all('SELECT * FROM mail_log ORDER BY id DESC LIMIT ' . max(1, min(self::LOG_KEEP, $limit)));
        } catch (Throwable) {
            return [];
        }
    }

    public static function maskAddress(string $email): string
    {
        $at = strpos($email, '@');
        return $at === false ? '***' : substr($email, 0, min(2, $at)) . '***' . substr($email, $at);
    }
}
