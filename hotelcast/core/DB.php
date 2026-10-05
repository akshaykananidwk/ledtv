<?php
declare(strict_types=1);

/**
 * PDO wrapper. All queries use prepared statements (SQL injection safe).
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            $cfg['name']
        );
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => (bool) ($cfg['persistent'] ?? false),
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    }

    public static function config(): array
    {
        return [
            'host' => (string) Env::get('DB_HOST', 'localhost'),
            'port' => (int) Env::get('DB_PORT', 3306),
            'name' => (string) Env::get('DB_NAME', ''),
            'user' => (string) Env::get('DB_USER', ''),
            'pass' => (string) Env::get('DB_PASS', ''),
            'persistent' => (bool) Config::get('db_persistent', false),
        ];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(self::config());
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** Align MySQL session time zone with PHP so NOW() and date() agree. */
    public static function syncTimezone(): void
    {
        try {
            $offset = (new DateTime())->format('P');
            self::pdo()->exec("SET time_zone = '" . $offset . "'");
        } catch (Throwable) {
            // Not fatal; app always passes PHP-generated timestamps.
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map(fn ($c) => '`' . self::ident($c) . '`', $cols)),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols))
        );
        self::query($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = '`' . self::ident($col) . '` = :set_' . $col;
            $params['set_' . $col] = $val;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', self::ident($table), implode(', ', $sets), $where);
        return self::query($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::query(sprintf('DELETE FROM `%s` WHERE %s', self::ident($table), $where), $params)->rowCount();
    }

    /** Builds "IN (:p0,:p1)" placeholders. Returns [sqlFragment, params]. */
    public static function in(array $values, string $prefix = 'in'): array
    {
        if (!$values) {
            return ['(NULL)', []];
        }
        $ph = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $ph[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $v;
        }
        return ['(' . implode(',', $ph) . ')', $params];
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid identifier: ' . $name);
        }
        return $name;
    }
}
