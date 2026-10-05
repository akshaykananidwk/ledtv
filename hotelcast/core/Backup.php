<?php
declare(strict_types=1);

/**
 * Full backups (application files + MySQL dump) as dated zip files in /backups,
 * and restore (used by the updater's automatic rollback and manual rollback).
 *
 * The dump is pure PHP/PDO (no mysqldump binary needed — works on shared hosting).
 * Format: one SQL statement per line, so restore can stream it line by line.
 */
final class Backup
{
    /** Paths never touched by restore/update (relative to HC_ROOT). */
    public const PROTECTED = ['.env', 'config.php', 'installed.lock', 'uploads', 'storage', 'backups', 'logs', 'install', '.git'];

    /** Paths excluded from the file part of a backup. */
    private const EXCLUDE = ['uploads', 'backups', 'storage', 'logs', '.git', 'install'];

    /** Tables whose data is transient and not worth dumping. */
    private const SKIP_DATA = ['rate_limits'];

    public static function dir(): string
    {
        $dir = HC_ROOT . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Create a backup. Returns the zip filename (basename).
     */
    public static function create(string $label = 'manual', bool $includeUploads = false, ?callable $log = null): string
    {
        @set_time_limit(0);
        $dir = self::dir();
        $base = 'backup_' . date('Y-m-d_H-i');
        $name = $base . '.zip';
        $i = 1;
        while (is_file($dir . '/' . $name)) {
            $name = $base . '_' . (++$i) . '.zip';
        }
        $zipPath = $dir . '/' . $name;
        $sqlPath = $dir . '/.dump_' . random_token(6) . '.sql';

        $log && $log('Dumping database…');
        $tables = self::dumpDatabase($sqlPath);

        $log && $log('Archiving files…');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlPath);
            throw new RuntimeException('Cannot create backup zip in backups/ (check permissions)');
        }
        $zip->addFile($sqlPath, 'database.sql');
        $count = 0;
        $exclude = $includeUploads ? array_diff(self::EXCLUDE, ['uploads']) : self::EXCLUDE;
        foreach (self::appFiles($exclude) as $rel) {
            $zip->addFile(HC_ROOT . '/' . $rel, 'files/' . $rel);
            $count++;
        }
        $zip->addFromString('backup.json', json_encode([
            'created_at' => date('c'),
            'label' => $label,
            'version' => Version::current(),
            'tables' => $tables,
            'files' => $count,
            'includes_uploads' => $includeUploads,
        ], JSON_PRETTY_PRINT));
        if (!$zip->close()) {
            @unlink($sqlPath);
            throw new RuntimeException('Failed to write backup zip');
        }
        @unlink($sqlPath);
        $log && $log(sprintf('Backup created: %s (%d files, %d tables, %s)', $name, $count, count($tables), human_bytes(filesize($zipPath))));
        Logger::write('update', 'info', 'Backup created ' . $name, ['label' => $label]);
        return $name;
    }

    /** List application files (relative paths), skipping excluded top-level paths. */
    public static function appFiles(array $exclude): array
    {
        $files = [];
        $root = HC_ROOT;
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $f) use ($root, $exclude) {
                    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
                    $top = explode('/', $rel)[0];
                    return !in_array($top, $exclude, true) && !$f->isLink();
                }
            )
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            }
        }
        sort($files);
        return $files;
    }

    /** Dump all tables to $file. Returns table names. */
    public static function dumpDatabase(string $file): array
    {
        $pdo = DB::pdo();
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new RuntimeException('Cannot write database dump');
        }
        fwrite($fh, "-- HotelCast database backup " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        $tables = DB::column('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        foreach ($tables as $table) {
            $create = DB::one('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $ddl = preg_replace('/\s*\n\s*/', ' ', (string) ($create['Create Table'] ?? ''));
            fwrite($fh, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $ddl . ";\n");
            if (in_array($table, self::SKIP_DATA, true)) {
                continue;
            }
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`', PDO::FETCH_ASSOC);
            $batch = [];
            $cols = null;
            while ($row = $stmt->fetch()) {
                $cols ??= '(`' . implode('`,`', array_keys($row)) . '`)';
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, 'INSERT INTO `' . $table . '` ' . $cols . ' VALUES ' . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, 'INSERT INTO `' . $table . '` ' . $cols . ' VALUES ' . implode(',', $batch) . ";\n");
            }
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        fclose($fh);
        return $tables;
    }

    /** Restore a database dump produced by dumpDatabase(). */
    public static function restoreDatabase(string $file): void
    {
        $fh = fopen($file, 'rb');
        if (!$fh) {
            throw new RuntimeException('Cannot read database dump');
        }
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $buf = '';
        while (($line = fgets($fh)) !== false) {
            if ($buf === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $buf .= $line;
            if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                $pdo->exec(rtrim(trim($buf), ';'));
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $pdo->exec(rtrim(trim($buf), ';'));
        }
        fclose($fh);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public static function path(string $name): string
    {
        $name = basename($name);
        if (!preg_match('/^backup_[\w\-]+\.zip$/', $name)) {
            throw new InvalidArgumentException('Invalid backup name');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException('Backup not found: ' . $name);
        }
        return $path;
    }

    /**
     * Restore files and/or database from a backup zip. Protected paths are never touched.
     * Application files that are not in the backup are removed (so a rollback fully undoes an update).
     */
    public static function restore(string $name, bool $files = true, bool $database = true, ?callable $log = null): void
    {
        @set_time_limit(0);
        $zipPath = self::path($name);
        $tmp = HC_ROOT . '/storage/tmp/restore_' . random_token(4);
        mkdir($tmp, 0755, true);
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Cannot open backup zip');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                    throw new RuntimeException('Unsafe path in backup: ' . $entry);
                }
            }
            $zip->extractTo($tmp);
            $zip->close();

            if ($files && is_dir($tmp . '/files')) {
                $log && $log('Restoring files…');
                if (!is_file($tmp . '/files/core/bootstrap.php')) {
                    throw new RuntimeException('Backup does not contain a complete application — aborting file restore');
                }
                $backupFiles = [];
                $src = $tmp . '/files';
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $f) {
                    if (!$f->isFile()) {
                        continue;
                    }
                    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/');
                    if (self::isProtected($rel)) {
                        continue;
                    }
                    $backupFiles[$rel] = true;
                    $dest = HC_ROOT . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        mkdir(dirname($dest), 0755, true);
                    }
                    if (!copy($f->getPathname(), $dest)) {
                        throw new RuntimeException('Cannot write ' . $rel);
                    }
                }
                // Remove files added after the backup (e.g. by a failed update).
                foreach (self::appFiles(self::PROTECTED) as $rel) {
                    if (!isset($backupFiles[$rel]) && !self::isProtected($rel)) {
                        @unlink(HC_ROOT . '/' . $rel);
                    }
                }
            }
            if ($database && is_file($tmp . '/database.sql')) {
                $log && $log('Restoring database…');
                self::restoreDatabase($tmp . '/database.sql');
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Cache::clear();
            Logger::write('update', 'info', 'Backup restored ' . $name, ['files' => $files, 'db' => $database]);
        } finally {
            rrmdir($tmp);
        }
    }

    public static function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $extra = (array) Config::get('update_protected', []);
        foreach (array_merge(self::PROTECTED, $extra) as $p) {
            $p = trim((string) $p, '/');
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** All backups, newest first: [name, size, created, meta]. */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup_*.zip') ?: [] as $f) {
            $meta = null;
            $zip = new ZipArchive();
            if ($zip->open($f) === true) {
                $meta = json_decode((string) $zip->getFromName('backup.json'), true);
                $zip->close();
            }
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'created' => date('Y-m-d H:i:s', filemtime($f)), 'meta' => $meta ?: []];
        }
        usort($out, fn ($a, $b) => strcmp($b['created'], $a['created']) ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function delete(string $name): void
    {
        @unlink(self::path($name));
    }

    /** Keep only the newest N backups. */
    public static function prune(int $keep): int
    {
        $removed = 0;
        foreach (array_slice(self::all(), max(1, $keep)) as $b) {
            @unlink(self::dir() . '/' . $b['name']);
            $removed++;
        }
        return $removed;
    }
}
