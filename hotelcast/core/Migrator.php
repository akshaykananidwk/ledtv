<?php
declare(strict_types=1);

/**
 * Runs numbered migrations from /migrations exactly once, tracking applied files in
 * `schema_migrations`:
 *   - NNN_name.sql : plain SQL statements ("already exists" errors are ignored → idempotent)
 *   - NNN_name.php : `return static function (PDO $pdo, callable $log): void { … };`
 *                    for conditional / data migrations. Must be idempotent (resumable):
 *                    use Migrator::hasColumn()/hasIndex()/hasForeignKey() before ALTERs.
 * Files run in natural filename order (002_x.sql before 002_x_more.php).
 */
final class Migrator
{
    public static function files(): array
    {
        $files = array_merge(glob(HC_ROOT . '/migrations/*.sql') ?: [], glob(HC_ROOT . '/migrations/*.php') ?: []);
        $files = array_values(array_filter($files, fn ($f) => preg_match('/^\d{3,}_[A-Za-z0-9_\-]+\.(sql|php)$/', basename($f))));
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
            if (str_ends_with($name, '.php')) {
                $fn = require $file;
                if (!is_callable($fn)) {
                    throw new RuntimeException("Migration $name must return a callable");
                }
                try {
                    $fn(DB::pdo(), $log ?? static function (string $m): void {
                    });
                } catch (Throwable $e) {
                    throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
                }
                DB::query('INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (:f, :t)', ['f' => $name, 't' => now()]);
                $done[] = $name;
                continue;
            }
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

    // ------------------------------------------------------------------ schema helpers (MySQL 8 / MariaDB 10.4+)

    public static function hasTable(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $index]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function hasForeignKey(PDO $pdo, string $table, string $name): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $st->execute([$table, $name]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Columns of an index in order (empty when missing). */
    public static function indexColumns(PDO $pdo, string $table, string $index): array
    {
        $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $st->execute([$table, $index]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function columnType(PDO $pdo, string $table, string $column): ?string
    {
        $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
