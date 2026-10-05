<?php
declare(strict_types=1);

/** Shared helpers for the HotelCast test-suite. */
final class TestEnv
{
    public static string $appSrc = '';
    public static string $sandbox = '';
    public static array $db = [];
    /** @var array<int, resource> */
    private static array $servers = [];

    /** Copy the app to $dir. $installed=true also writes .env, config.php and installed.lock. */
    public static function makeSandbox(string $dir, bool $installed, string $baseUrl = 'http://127.0.0.1/'): void
    {
        self::rmTree($dir);
        $skipTop = ['.env', 'config.php', 'installed.lock', 'tests', 'uploads', 'backups', 'logs', 'storage'];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$appSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::$appSrc) + 1);
            $top = explode('/', $rel)[0];
            if (in_array($top, $skipTop, true) || str_starts_with($top, 'install_disabled')) {
                continue;
            }
            $dest = $dir . '/' . $rel;
            if ($f->isDir()) {
                @mkdir($dest, 0755, true);
            } else {
                @mkdir(dirname($dest), 0755, true);
                copy($f->getPathname(), $dest);
            }
        }
        foreach (['uploads', 'backups', 'logs', 'storage', 'storage/cache'] as $d) {
            @mkdir($dir . '/' . $d, 0755, true);
            foreach (['.htaccess'] as $keep) {
                if (is_file(self::$appSrc . '/' . $d . '/' . $keep)) {
                    copy(self::$appSrc . '/' . $d . '/' . $keep, $dir . '/' . $d . '/' . $keep);
                }
            }
        }
        if ($installed) {
            self::writeConfig($dir, $baseUrl);
            file_put_contents($dir . '/installed.lock', 'test');
        }
    }

    public static function writeConfig(string $dir, string $baseUrl, array $extra = []): void
    {
        $db = self::$db;
        file_put_contents($dir . '/.env', implode("\n", [
            'DB_HOST=' . $db['host'], 'DB_PORT=' . $db['port'], 'DB_NAME=' . $db['name'],
            'DB_USER=' . $db['user'], 'DB_PASS="' . $db['pass'] . '"',
            'APP_KEY=' . str_repeat('ab12', 16),
        ]) . "\n");
        file_put_contents($dir . '/config.php', '<?php return ' . var_export($extra + [
            'base_url' => $baseUrl, 'timezone' => 'Asia/Kolkata', 'debug' => true,
        ], true) . ';');
    }

    /** Drop every table and re-create schema + default settings. */
    public static function resetDatabase(): void
    {
        DB::setPdo(null);
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . $t . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        Settings::flush();
        Installer::setupDatabase(false);
        Settings::set('registration_key', 'TESTKEY123456789');
        Settings::flush();
        Cache::clear();
    }

    public static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    /** Start `php -S` for a docroot with a router script. Returns base URL. */
    public static function startServer(string $docroot, string $router, int $workers = 1, array $env = []): string
    {
        $port = self::freePort();
        $cmd = sprintf('exec %s -S 127.0.0.1:%d -t %s %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($docroot), escapeshellarg($router));
        $envAll = array_merge(getenv(), $env, $workers > 1 ? ['PHP_CLI_SERVER_WORKERS' => (string) $workers] : []);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $envAll);
        self::$servers[] = $proc;
        $url = 'http://127.0.0.1:' . $port . '/';
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
            if ($c) {
                fclose($c);
                return $url;
            }
            usleep(50000);
        }
        throw new RuntimeException('Server did not start on port ' . $port);
    }

    public static function stopServers(): void
    {
        foreach (self::$servers as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::$servers = [];
    }

    /** Simple HTTP client returning [status, decoded json|null, raw body, headers]. */
    public static function http(string $method, string $url, ?array $json = null, array $headers = [], ?string $cookieJar = null, ?array $form = null): array
    {
        $ch = curl_init($url);
        $h = $headers;
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true];
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        } elseif ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = $form;
        }
        if ($cookieJar) {
            $opts[CURLOPT_COOKIEJAR] = $cookieJar;
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hsize);
        $body = substr($raw, $hsize);
        return [$status, json_decode($body, true), $body, $head];
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
