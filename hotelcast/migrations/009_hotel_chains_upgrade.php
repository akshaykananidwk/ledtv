<?php
/**
 * HotelCast 2.0 — hotel chains (#20), schema changes on shared tables (runs after 009_hotel_chains.sql):
 *  1. hotels.chain_id (NULL = not in a chain) + index + FK → hotel_chains (ON DELETE SET NULL)
 *  2. users.chain_id (chain_admin users; a hotel super_admin with chain_id = chain access) + FK
 *  3. users.role gains 'chain_admin' — the current ENUM values are kept (other modules may have
 *     added values), only the missing one is appended.
 * Idempotent / resumable: every step checks information_schema first. PDO only.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $one = static function (string $sql, array $p = []) use ($pdo): mixed {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    };
    $hasCol = static fn (string $t, string $c): bool => (int) $one('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) > 0;
    $hasIdx = static fn (string $t, string $i): bool => (int) $one('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $i]) > 0;
    $hasFk = static fn (string $t, string $n): bool => (int) $one("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [$t, $n]) > 0;

    foreach (['hotels' => 'reseller_id', 'users' => 'reseller_id'] as $table => $after) {
        if (!$hasCol($table, 'chain_id')) {
            $log("  $table: adding chain_id");
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN chain_id INT UNSIGNED NULL" . ($hasCol($table, $after) ? " AFTER `$after`" : ''));
        }
        if (!$hasIdx($table, "idx_{$table}_chain")) {
            $pdo->exec("ALTER TABLE `$table` ADD KEY `idx_{$table}_chain` (chain_id)");
        }
        if (!$hasFk($table, "fk_{$table}_chain")) {
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `fk_{$table}_chain` FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE SET NULL");
        }
    }

    $type = (string) $one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    if (str_starts_with(strtolower($type), 'enum(') && !str_contains($type, "'chain_admin'")) {
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $values = array_map(static fn ($v) => str_replace("''", "'", $v), $m[1]);
        $values[] = 'chain_admin';
        $list = implode(',', array_map(static fn ($v) => $pdo->quote($v), $values));
        $default = in_array('staff', $values, true) ? " DEFAULT 'staff'" : '';
        $log('  users: role chain_admin');
        $pdo->exec("ALTER TABLE users MODIFY role ENUM($list) NOT NULL$default");
    }
};
