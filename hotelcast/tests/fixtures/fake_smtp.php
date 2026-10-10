<?php
/**
 * Tiny fake SMTP server for tests (EmailFlowsTest). Never relays anything: it records each
 * conversation and the received message as one JSON line in the log file.
 *
 *   php tests/fixtures/fake_smtp.php <port> <logfile> [auth-mechs|-] [user] [pass]
 *
 * auth-mechs: "LOGIN", "PLAIN", "LOGIN PLAIN" or "-" (no AUTH, no login needed).
 * RCPT TO an address containing "reject" gets 550. No STARTTLS (the client's command sequence for
 * STARTTLS is checked against a server that does not offer it).
 */
declare(strict_types=1);

[$self, $port, $log] = array_pad($argv, 3, '');
$mechs = ($argv[3] ?? '-') === '-' ? '' : strtoupper((string) $argv[3]);
$user = (string) ($argv[4] ?? '');
$pass = (string) ($argv[5] ?? '');

$srv = stream_socket_server('tcp://127.0.0.1:' . (int) $port, $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}

while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 10);
    $conv = [];
    $msg = null;
    $authed = $mechs === '';
    $say = static function (string $line) use ($c, &$conv): void {
        $conv[] = 'S: ' . rtrim($line);
        fwrite($c, $line . "\r\n");
    };
    $readLine = static function () use ($c, &$conv): ?string {
        $l = fgets($c, 4096);
        if ($l === false) {
            return null;
        }
        $conv[] = 'C: ' . rtrim($l, "\r\n");
        return rtrim($l, "\r\n");
    };
    $say('220 fake.smtp.test ESMTP ready');
    $from = '';
    $rcpts = [];
    while (($line = $readLine()) !== null) {
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO') {
            fwrite($c, "250-fake.smtp.test hello\r\n");
            $conv[] = 'S: 250-fake.smtp.test hello';
            if ($mechs !== '') {
                fwrite($c, "250-AUTH $mechs\r\n");
                $conv[] = "S: 250-AUTH $mechs";
            }
            $say('250 8BITMIME');
        } elseif ($cmd === 'HELO') {
            $say('250 fake.smtp.test');
        } elseif (str_starts_with(strtoupper($line), 'AUTH LOGIN')) {
            $say('334 VXNlcm5hbWU6');
            $u = base64_decode((string) $readLine());
            $say('334 UGFzc3dvcmQ6');
            $p = base64_decode((string) $readLine());
            $authed = $u === $user && $p === $pass;
            $say($authed ? '235 2.7.0 Authentication successful' : '535 5.7.8 Username and Password not accepted');
        } elseif (str_starts_with(strtoupper($line), 'AUTH PLAIN')) {
            $parts = explode("\0", (string) base64_decode(trim(substr($line, 10))));
            $authed = ($parts[1] ?? '') === $user && ($parts[2] ?? '') === $pass;
            $say($authed ? '235 2.7.0 Authentication successful' : '535 5.7.8 Username and Password not accepted');
        } elseif (str_starts_with(strtoupper($line), 'STARTTLS')) {
            $say('454 4.7.0 TLS not available');
        } elseif (str_starts_with(strtoupper($line), 'MAIL FROM:')) {
            if (!$authed) {
                $say('530 5.7.0 Authentication required');
                continue;
            }
            $from = trim(substr($line, 10), '<> ');
            $say('250 2.1.0 OK');
        } elseif (str_starts_with(strtoupper($line), 'RCPT TO:')) {
            $to = trim(substr($line, 8), '<> ');
            if (str_contains($to, 'reject')) {
                $say('550 5.1.1 No such user');
                continue;
            }
            $rcpts[] = $to;
            $say('250 2.1.5 OK');
        } elseif ($cmd === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = [];
            while (($l = fgets($c, 8192)) !== false) {
                $l = rtrim($l, "\r\n");
                if ($l === '.') {
                    break;
                }
                $data[] = str_starts_with($l, '.') ? substr($l, 1) : $l; // undo dot-stuffing
            }
            $conv[] = 'C: [data ' . count($data) . ' lines]';
            $msg = ['from' => $from, 'to' => $rcpts, 'data' => implode("\r\n", $data)];
            $say('250 2.0.0 OK queued');
        } elseif ($cmd === 'RSET' || $cmd === 'NOOP') {
            $say('250 OK');
        } elseif ($cmd === 'QUIT') {
            $say('221 2.0.0 Bye');
            break;
        } else {
            $say('502 5.5.2 Command not recognized');
        }
    }
    fclose($c);
    file_put_contents($log, json_encode(['conversation' => $conv, 'message' => $msg]) . "\n", FILE_APPEND | LOCK_EX);
}
