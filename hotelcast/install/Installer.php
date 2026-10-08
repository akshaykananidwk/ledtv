<?php
declare(strict_types=1);

/** Installer logic (kept separate from the wizard UI). */
final class Installer
{
    public const MIN_PHP = '8.1.0';
    public const EXTENSIONS = [
        'pdo_mysql' => 'PDO MySQL', 'mysqli' => 'MySQLi', 'curl' => 'cURL', 'zip' => 'Zip',
        'gd' => 'GD (images)', 'mbstring' => 'mbstring', 'json' => 'JSON', 'openssl' => 'OpenSSL',
        'sodium' => 'Sodium (encryption)', 'fileinfo' => 'Fileinfo',
    ];
    public const WRITABLE = ['', 'uploads', 'backups', 'logs', 'storage', 'storage/cache'];

    public static function requirements(): array
    {
        $checks = [];
        $checks[] = ['label' => 'PHP ' . self::MIN_PHP . '+ (you have ' . PHP_VERSION . ')', 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'required' => true];
        foreach (self::EXTENSIONS as $ext => $label) {
            $checks[] = ['label' => 'PHP extension: ' . $label, 'ok' => extension_loaded($ext), 'required' => $ext !== 'mysqli'];
        }
        foreach (self::WRITABLE as $dir) {
            $path = HC_ROOT . ($dir === '' ? '' : '/' . $dir);
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (is_dir($path) && !is_writable($path)) {
                @chmod($path, 0775);
            }
            $checks[] = ['label' => 'Writable folder: /' . ($dir === '' ? ' (application root, for config.php & .env)' : $dir), 'ok' => is_dir($path) && is_writable($path), 'required' => true];
        }
        $checks[] = self::htaccessCheck();
        $checks[] = ['label' => 'HTTPS connection (recommended for security)', 'ok' => is_https(), 'required' => false];
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $checks[] = ['label' => 'Upload limit ≥ 64 MB for videos (now ' . ini_get('upload_max_filesize') . ')', 'ok' => $upload >= 64 * 1024 * 1024, 'required' => false];
        return $checks;
    }

    private static function htaccessCheck(): array
    {
        $label = '.htaccess support (blocks access to /core, /backups, /logs, .env)';
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (function_exists('apache_get_modules')) {
            $ok = in_array('mod_rewrite', apache_get_modules(), true);
            return ['label' => $label . ($ok ? '' : ' — mod_rewrite missing'), 'ok' => $ok, 'required' => false];
        }
        // Loop-back test: core/.htaccess must deny access.
        if (function_exists('curl_init') && PHP_SAPI !== 'cli-server') {
            $res = Http::get(self::detectBaseUrl() . 'core/Env.php', [], 5);
            if ($res['status'] > 0) {
                $ok = $res['status'] === 403 || $res['status'] === 404;
                return ['label' => $label . ($ok ? '' : ' — /core is publicly reachable (HTTP ' . $res['status'] . ')'), 'ok' => $ok, 'required' => false];
            }
        }
        return ['label' => $label . ' — could not verify on ' . ($server ?: 'this server'), 'ok' => false, 'required' => false];
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /** Base URL of the app derived from the installer's own URL (/x/install/index.php → /x/). */
    public static function detectBaseUrl(): string
    {
        $scheme = is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
        $dir = rtrim(dirname($script, 2), '/');
        return $scheme . '://' . $host . $dir . '/';
    }

    /** Try to connect; optionally create the database. Returns [PDO|null, error|null, info]. */
    public static function connect(array $db, bool $create = true): array
    {
        try {
            $pdo = DB::connect($db);
            return [$pdo, null, 'Connected to database "' . $db['name'] . '"'];
        } catch (PDOException $e) {
            // 1049 = unknown database → try to create it.
            if ($create && (int) ($e->errorInfo[1] ?? 0) === 1049 || $create && str_contains($e->getMessage(), '1049')) {
                try {
                    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $server->exec('CREATE DATABASE `' . str_replace('`', '', $db['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    return [DB::connect($db), null, 'Database "' . $db['name'] . '" created'];
                } catch (PDOException $e2) {
                    return [null, 'Database "' . $db['name'] . '" does not exist and could not be created: ' . self::cleanError($e2->getMessage()), ''];
                }
            }
            return [null, self::cleanError($e->getMessage()), ''];
        }
    }

    private static function cleanError(string $m): string
    {
        if (str_contains($m, '1045')) {
            return 'Access denied — check the database username and password.';
        }
        if (str_contains($m, '2002') || str_contains($m, 'getaddrinfo')) {
            return 'Cannot reach the database server — check the host name (usually "localhost").';
        }
        return preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\])?\s*/', '', $m) ?: $m;
    }

    public static function validDbName(string $n): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-$]{1,64}$/', $n);
    }

    public static function mysqlVersionOk(PDO $pdo): array
    {
        $v = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($v, 'mariadb') !== false;
        $num = preg_replace('/[^0-9.].*$/', '', $v);
        $ok = $isMaria ? version_compare($num, '10.4', '>=') : version_compare($num, '5.7.8', '>=');
        return [$ok, $v];
    }

    /** Write .env and config.php. */
    public static function writeConfig(array $db, string $appKey, array $config): void
    {
        $env = Env::build([
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'],
            'DB_PASS' => $db['pass'],
            'APP_KEY' => $appKey,
        ]);
        if (file_put_contents(HC_ROOT . '/.env', $env) === false) {
            throw new RuntimeException('Cannot write .env — make the application folder writable.');
        }
        @chmod(HC_ROOT . '/.env', 0640);

        $php = "<?php\n/**\n * HotelCast configuration — generated by the installer on " . date('Y-m-d H:i') . ".\n"
            . " * Secrets are in .env. This file is never overwritten by the auto-updater.\n */\nreturn "
            . var_export($config, true) . ";\n";
        if (file_put_contents(HC_ROOT . '/config.php', $php) === false) {
            throw new RuntimeException('Cannot write config.php — make the application folder writable.');
        }
        @chmod(HC_ROOT . '/config.php', 0640);
    }

    /** Create schema + default settings. */
    public static function setupDatabase(bool $demo): array
    {
        $log = [];
        $ran = Migrator::migrate(function ($m) use (&$log) {
            $log[] = $m;
        });
        $log[] = $ran ? 'Created tables (' . implode(', ', $ran) . ')' : 'Tables already exist';
        // A single install is hotel #1 (created by migration 002).
        Tenant::set(1);
        $defaults = Settings::DEFAULTS;
        $existingKey = (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'registration_key'");
        $defaults['registration_key'] = $existingKey !== '' ? $existingKey : Hotels::newRegistrationKey();
        foreach ($defaults as $k => $v) {
            DB::query('INSERT IGNORE INTO system_settings (hotel_id, setting_key, setting_value) VALUES (1, :k, :v)', ['k' => $k, 'v' => $v]);
        }
        foreach (Settings::PLATFORM_DEFAULTS as $k => $v) {
            DB::query('INSERT IGNORE INTO system_settings (hotel_id, setting_key, setting_value) VALUES (0, :k, :v)', ['k' => $k, 'v' => $v]);
        }
        DB::query('UPDATE hotels SET registration_key = :k WHERE id = 1', ['k' => $defaults['registration_key']]);
        Settings::flush();
        $log[] = 'Default settings saved';
        if ($demo) {
            $log = array_merge($log, self::demoData());
        }
        return $log;
    }

    /**
     * Demo rooms, groups, content and playlist (English + Gujarati). The data itself lives in
     * Demo::sampleContent() (core/Demo.php) so the public sign-up and the demo hotels reuse it after
     * the installer folder has been deleted.
     */
    public static function demoData(): array
    {
        Tenant::set(Tenant::current() ?? 1);
        if ((int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h', ['h' => Tenant::id()]) > 0) {
            return ['Demo data skipped (screens already exist)'];
        }
        return Demo::sampleContent([1 => 10, 2 => 10]);
    }

    /**
     * The installing user becomes platform admin (manages the platform / auto-update) and works
     * inside hotel #1 as its super admin.
     */
    public static function createAdmin(string $username, string $email, string $password, string $name): int
    {
        $existing = DB::one('SELECT id FROM users WHERE username = :u OR email = :e', ['u' => $username, 'e' => $email]);
        $data = [
            'username' => $username,
            'email' => $email,
            'full_name' => $name,
            'password_hash' => Auth::hash($password),
            'role' => 'platform_admin',
            'hotel_id' => 1,
            'is_active' => 1,
        ];
        if ($existing) {
            DB::update('users', $data, 'id = :id', ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
        return DB::insert('users', $data + ['created_at' => now()]);
    }

    /** Finalise: lock file + remove installer directory. Returns message about the installer folder. */
    public static function finish(): string
    {
        file_put_contents(HC_ROOT . '/installed.lock', json_encode(['installed_at' => date('c'), 'version' => Version::current()['version']]) . "\n");
        $dir = HC_ROOT . '/install';
        if (rrmdir($dir) || !is_dir($dir)) {
            return 'The /install folder has been deleted.';
        }
        $renamed = HC_ROOT . '/install_disabled_' . random_token(6);
        if (@rename($dir, $renamed)) {
            @file_put_contents($renamed . '/.htaccess', "Require all denied\n");
            return 'The installer folder was renamed to ' . basename($renamed) . ' and blocked. You can delete it.';
        }
        return 'Could not delete the /install folder — it is locked by installed.lock, but please delete it manually.';
    }
}
