<?php
declare(strict_types=1);

/**
 * Runs numbered SQL files from /migrations (001_init.sql, 002_xxx.sql …) exactly once,
 * tracking applied files in `schema_migrations`.
 */
final class Migrator
{
    public static function files(): array
    {
        $files = glob(HC_ROOT . '/migrations/*.sql') ?: [];
        $files = array_values(array_filter($files, fn ($f) => preg_match('/^\d{3,}_[A-Za-z0-9_\-]+\.sql$/', basename($f))));
        sort($files, SORT_NATURAL);
        return $files;
    }

    public static function applied(): array
    {
        try {
            return DB::column('SELECT filename FROM schema_migrations');
        } catch (Throwable) {
            return [];
        }
    }

    public static function pending(): array
    {
        $applied = array_flip(self::applied());
        return array_values(array_filter(self::files(), fn ($f) => !isset($applied[basename($f)])));
    }

    /** Apply all pending migrations. Returns list of applied filenames. Throws on failure. */
    public static function migrate(?callable $log = null): array
    {
        $done = [];
        foreach (self::pending() as $file) {
            $name = basename($file);
            $log && $log('Running migration ' . $name);
            foreach (self::splitSql((string) file_get_contents($file)) as $stmt) {
                try {
                    DB::pdo()->exec($stmt);
                } catch (PDOException $e) {
                    // Idempotency: ignore "already exists" / "duplicate column/key" errors.
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (!in_array($code, [1050, 1060, 1061, 1068, 1091, 1826], true)) {
                        throw new RuntimeException("Migration $name failed: " . $e->getMessage() . ' — SQL: ' . mb_substr($stmt, 0, 200));
                    }
                }
            }
            DB::query('INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (:f, :t)', ['f' => $name, 't' => now()]);
            $done[] = $name;
        }
        return $done;
    }

    /** Split SQL into statements (handles quotes and -- / # / block comments). */
    public static function splitSql(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\') {
                    $buf .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '-' && $next === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n") || $c === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $stmts[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }
}
