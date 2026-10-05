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
        $defaults = Settings::DEFAULTS;
        $defaults['registration_key'] = strtoupper(substr(random_token(8), 0, 16));
        foreach ($defaults as $k => $v) {
            DB::query('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)', ['k' => $k, 'v' => $v]);
        }
        Settings::flush();
        $log[] = 'Default settings saved';
        if ($demo) {
            $log = array_merge($log, self::demoData());
        }
        return $log;
    }

    /** Demo rooms, groups, content and playlist (English + Gujarati). */
    public static function demoData(): array
    {
        if ((int) DB::value('SELECT COUNT(*) FROM rooms') > 0) {
            return ['Demo data skipped (rooms already exist)'];
        }
        $log = [];
        $groups = [];
        foreach ([1, 2] as $floor) {
            $groups[$floor] = DB::insert('room_groups', ['name' => 'Floor ' . $floor, 'type' => 'floor', 'description' => 'All rooms on floor ' . $floor, 'created_at' => now()]);
        }
        $vip = DB::insert('room_groups', ['name' => 'Suites (VIP)', 'type' => 'zone', 'description' => 'Premium suites', 'created_at' => now()]);
        $n = 0;
        foreach ([1, 2] as $floor) {
            for ($i = 1; $i <= 10; $i++) {
                $num = $floor . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $rid = DB::insert('rooms', ['room_number' => $num, 'name' => ($i >= 9 ? 'Suite ' : 'Deluxe ') . $num, 'floor' => (string) $floor, 'created_at' => now()]);
                DB::insert('room_group_members', ['room_id' => $rid, 'group_id' => $groups[$floor]]);
                if ($i >= 9) {
                    DB::insert('room_group_members', ['room_id' => $rid, 'group_id' => $vip]);
                }
                $n++;
            }
        }
        $log[] = "Created $n demo rooms in 3 groups";

        $c = [];
        $c['timetable'] = DB::insert('content_items', [
            'title' => 'Dwarkadhish Darshan Timings / દ્વારકાધીશ દર્શન સમય',
            'type' => 'timetable',
            'duration' => 20,
            'body' => json_out([
                'heading' => 'શ્રી દ્વારકાધીશ મંદિર — દર્શન સમય',
                'subheading' => 'Shri Dwarkadhish Temple — Darshan Timings',
                'columns' => ['Time / સમય', 'Darshan / દર્શન'],
                'rows' => [
                    ['06:30 - 07:00', 'Mangla Aarti / મંગળા આરતી'],
                    ['07:00 - 08:00', 'Mangla Darshan / મંગળા દર્શન'],
                    ['08:00 - 09:00', 'Abhishek Puja (Snan) / અભિષેક'],
                    ['09:00 - 09:30', 'Shringar Darshan / શૃંગાર દર્શન'],
                    ['10:30 - 11:00', 'Gwal Bhog / ગ્વાલ ભોગ'],
                    ['11:30 - 12:00', 'Rajbhog / રાજભોગ'],
                    ['12:00 - 13:00', 'Anosar (Temple closed) / અનોસર'],
                    ['17:00 - 17:30', 'Uthappan Darshan / ઉત્થાપન'],
                    ['17:30 - 19:15', 'Darshan / દર્શન'],
                    ['19:30 - 19:45', 'Sandhya Aarti / સંધ્યા આરતી'],
                    ['20:00 - 20:30', 'Shayan Aarti / શયન આરતી'],
                    ['21:30', 'Darshan closes / દર્શન બંધ'],
                ],
                'footer' => 'Timings may change on festivals. / તહેવારોમાં સમય બદલાઈ શકે છે.',
                'bg_color' => '#4A0E0E', 'text_color' => '#FFF8E1', 'accent_color' => '#FFB300',
            ]),
            'settings' => json_out(['refresh_sec' => 60]),
            'created_at' => now(),
        ]);
        $c['welcome'] = DB::insert('content_items', [
            'title' => 'Welcome message / સ્વાગત',
            'type' => 'announcement',
            'duration' => 12,
            'body' => 'જય દ્વારકાધીશ! Welcome to our hotel',
            'settings' => json_out(['subtitle' => 'Breakfast 7:00–10:30 AM · Wi-Fi: Hotel-Guest', 'style' => 'fullscreen', 'bg_color' => '#0D47A1', 'text_color' => '#FFFFFF', 'font_size' => 56]),
            'created_at' => now(),
        ]);
        $c['offer'] = DB::insert('content_items', [
            'title' => 'Restaurant offer',
            'type' => 'announcement',
            'duration' => 10,
            'body' => 'Gujarati Thali — 20% off for in-house guests! ગુજરાતી થાળી પર 20% છૂટ',
            'settings' => json_out(['subtitle' => 'Ground floor restaurant · 12:00–3:00 PM, 7:00–10:30 PM', 'style' => 'fullscreen', 'bg_color' => '#1B5E20', 'text_color' => '#FFFFFF', 'font_size' => 52]),
            'created_at' => now(),
        ]);
        $c['clock'] = DB::insert('content_items', [
            'title' => 'Big clock', 'type' => 'clock', 'duration' => 8,
            'settings' => json_out(['style' => 'digital', 'bg_color' => '#000000', 'text_color' => '#FFD54F']),
            'created_at' => now(),
        ]);
        $c['stream'] = DB::insert('content_items', [
            'title' => 'Live Darshan (demo stream — replace URL)', 'type' => 'stream', 'duration' => 0,
            'url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
            'settings' => json_out(['mute' => false]),
            'created_at' => now(),
        ]);
        $log[] = 'Created ' . count($c) . ' demo content items';

        $pl = DB::insert('content_playlists', ['name' => 'Welcome loop / સ્વાગત', 'description' => 'Default loop for all rooms', 'transition' => 'fade', 'created_at' => now()]);
        foreach (['welcome', 'timetable', 'offer', 'clock'] as $i => $k) {
            DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $c[$k], 'sort_order' => $i]);
        }
        Settings::set('default_playlist_id', (string) $pl);
        Settings::set('ticker_text', 'મંગળા આરતી સવારે 6:30 · Mangla Aarti 6:30 AM · Sandhya Aarti 7:30 PM · Checkout 10:00 AM');
        $log[] = 'Created demo playlist and set it as default content';
        return $log;
    }

    public static function createAdmin(string $username, string $email, string $password, string $name): int
    {
        $existing = DB::one('SELECT id FROM users WHERE username = :u OR email = :e', ['u' => $username, 'e' => $email]);
        $data = [
            'username' => $username,
            'email' => $email,
            'full_name' => $name,
            'password_hash' => Auth::hash($password),
            'role' => 'super_admin',
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
