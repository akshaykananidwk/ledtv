<?php
declare(strict_types=1);

/** System health checks (used by /api/health, the updater and the admin system report). */
final class HealthCheck
{
    public const CRITICAL_FILES = [
        'core/bootstrap.php', 'core/DB.php', 'core/Auth.php', 'core/ContentResolver.php',
        'api/index.php', 'admin/index.php', 'admin/login.php', 'version.json',
    ];

    public static function quick(): array
    {
        $db = false;
        try {
            $db = (int) DB::value('SELECT 1') === 1;
        } catch (Throwable $e) {
            Logger::error('Health: DB failed: ' . $e->getMessage());
        }
        return [
            'status' => $db ? 'ok' : 'error',
            'version' => Version::current()['version'],
            'db' => $db,
            'time' => date('c'),
        ];
    }

    /**
     * Full check. Returns ['ok' => bool, 'checks' => [name => ['ok' => bool, 'message' => string]]].
     * $httpCheck performs a real HTTP request to /api/health (skipped in CLI).
     */
    public static function full(bool $httpCheck = true): array
    {
        $checks = [];

        try {
            DB::setPdo(null); // force a fresh connection
            $tables = DB::column('SHOW TABLES');
            $required = ['users', 'rooms', 'devices', 'content_items', 'broadcast_commands', 'system_settings', 'schema_migrations'];
            $missing = array_diff($required, $tables);
            $checks['database'] = ['ok' => !$missing, 'message' => $missing ? 'Missing tables: ' . implode(', ', $missing) : 'Connected, ' . count($tables) . ' tables'];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }

        $missingFiles = array_values(array_filter(self::CRITICAL_FILES, fn ($f) => !is_file(HC_ROOT . '/' . $f)));
        $checks['files'] = ['ok' => !$missingFiles, 'message' => $missingFiles ? 'Missing: ' . implode(', ', $missingFiles) : 'All critical files present'];

        $syntaxErrors = self::lintCritical();
        $checks['php_syntax'] = ['ok' => !$syntaxErrors, 'message' => $syntaxErrors ? implode('; ', $syntaxErrors) : 'Critical files parse OK'];

        foreach (['uploads', 'backups', 'logs', 'storage', 'storage/cache'] as $dir) {
            $path = HC_ROOT . '/' . $dir;
            $ok = is_dir($path) && is_writable($path);
            $checks['writable_' . str_replace('/', '_', $dir)] = ['ok' => $ok, 'message' => $ok ? 'Writable' : $dir . ' is not writable'];
        }

        foreach (['config.php', '.env'] as $f) {
            $checks['config_' . $f] = ['ok' => is_file(HC_ROOT . '/' . $f), 'message' => is_file(HC_ROOT . '/' . $f) ? 'Present' : $f . ' missing'];
        }

        if ($httpCheck && PHP_SAPI !== 'cli') {
            $res = Http::get(base_url('api/health'), ['Accept: application/json'], 15);
            $json = json_decode($res['body'], true);
            $ok = $res['status'] === 200 && ($json['ok'] ?? false) === true;
            $checks['api'] = ['ok' => $ok, 'message' => $ok ? 'API responded OK' : 'API check failed (HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . ')'];
        }

        $allOk = !in_array(false, array_column($checks, 'ok'), true);
        return ['ok' => $allOk, 'checks' => $checks];
    }

    /** Syntax-check critical PHP files without executing them (token_get_all with TOKEN_PARSE). */
    private static function lintCritical(): array
    {
        $errors = [];
        foreach (self::CRITICAL_FILES as $f) {
            if (!str_ends_with($f, '.php') || !is_file(HC_ROOT . '/' . $f)) {
                continue;
            }
            try {
                token_get_all((string) file_get_contents(HC_ROOT . '/' . $f), TOKEN_PARSE);
            } catch (ParseError $e) {
                $errors[] = $f . ': ' . $e->getMessage() . ' line ' . $e->getLine();
            }
        }
        return $errors;
    }

    /** System report for the admin panel. */
    public static function report(): array
    {
        $disk = @disk_free_space(HC_ROOT);
        return [
            'version' => Version::current(),
            'php' => PHP_VERSION,
            'mysql' => (string) DB::value('SELECT VERSION()'),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI,
            'disk_free' => $disk !== false ? human_bytes($disk) : 'n/a',
            'uploads_size' => human_bytes(self::dirSize(HC_ROOT . '/uploads')),
            'backups_size' => human_bytes(self::dirSize(HC_ROOT . '/backups')),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'ffmpeg' => Uploader::ffmpeg() ? 'available' : 'not available',
            'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'enabled' : 'disabled',
        ];
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $size += $f->isFile() ? $f->getSize() : 0;
        }
        return $size;
    }
}
