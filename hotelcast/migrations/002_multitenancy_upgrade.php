<?php
/**
 * HotelCast 2.0 — converts an existing single-hotel database (1.x) into hotel #1 of the
 * multi-hotel schema, in place and without data loss:
 *
 *  1. hotel_id (default 1, indexed, FK → hotels) on every tenant table; users/activity_logs nullable
 *  2. unique keys per hotel: rooms (hotel_id, room_number), room_groups (hotel_id, name),
 *     devices (hotel_id, device_uid)
 *  3. users.role gains platform_admin / reseller / reception, users.reseller_id
 *  4. system_settings PK → (hotel_id, setting_key); platform keys move to hotel 0
 *  5. hotel 1 gets the old registration key; the first super admin becomes platform_admin
 *
 * Every step checks information_schema first, so an interrupted run can simply be re-run.
 * Uses only PDO (no app classes) so it also works when started by an older updater process.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $q = static function (string $sql, array $p = []) use ($pdo): PDOStatement|int {
        if (!$p && !str_starts_with(ltrim($sql), 'SELECT')) {
            return (int) $pdo->exec($sql); // DDL: plain exec
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st;
    };
    $has = static fn (string $sql, array $p): bool => (int) $q($sql, $p)->fetchColumn() > 0;
    $hasCol = static fn (string $t, string $c): bool => $has('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $hasIdx = static fn (string $t, string $i): bool => $has('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $i]);
    $hasFk = static fn (string $t, string $n): bool => $has("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [$t, $n]);
    $idxCols = static fn (string $t, string $i): array => $q('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$t, $i])->fetchAll(PDO::FETCH_COLUMN);
    $colType = static fn (string $t, string $c): string => (string) $q('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c])->fetchColumn();

    // Hotel 1 must exist before FKs are added (002_multitenancy.sql normally inserted it).
    $q("INSERT IGNORE INTO hotels (id, name, slug, status) VALUES (1, 'My Hotel', 'hotel-1', 'active')");

    // ------------------------------------------------------------------ 1. hotel_id columns
    $tables = [
        'rooms' => false, 'room_groups' => false, 'content_items' => false, 'content_playlists' => false,
        'broadcast_commands' => false, 'devices' => false, 'apk_releases' => false, 'broadcast_logs' => false,
        'device_status_logs' => false, 'activity_logs' => true, 'users' => true,
    ];
    foreach ($tables as $t => $nullable) {
        if (!$hasCol($t, 'hotel_id')) {
            $log("  $t: adding hotel_id");
            $q("ALTER TABLE `$t` ADD COLUMN hotel_id INT UNSIGNED " . ($nullable ? 'NULL' : 'NOT NULL') . ' DEFAULT 1 AFTER id');
        }
        if (!$hasIdx($t, "idx_{$t}_hotel")) {
            $q("ALTER TABLE `$t` ADD KEY `idx_{$t}_hotel` (hotel_id)");
        }
        if (!$hasFk($t, "fk_{$t}_hotel")) {
            $q("ALTER TABLE `$t` ADD CONSTRAINT `fk_{$t}_hotel` FOREIGN KEY (hotel_id) REFERENCES hotels(id)");
        }
    }

    // ------------------------------------------------------------------ 2. per-hotel unique keys
    $uniques = [
        'rooms' => ['uq_room_number', 'uq_rooms_hotel_number', 'room_number'],
        'room_groups' => ['uq_group_name', 'uq_room_groups_hotel_name', 'name'],
        'devices' => ['uq_device_uid', 'uq_devices_hotel_uid', 'device_uid'],
    ];
    foreach ($uniques as $t => [$old, $new, $col]) {
        $parts = [];
        if ($hasIdx($t, $old)) {
            $parts[] = "DROP INDEX `$old`";
        }
        if (!$hasIdx($t, $new)) {
            $parts[] = "ADD UNIQUE KEY `$new` (hotel_id, `$col`)";
        }
        if ($parts) {
            $log("  $t: unique key per hotel");
            $q("ALTER TABLE `$t` " . implode(', ', $parts));
        }
    }
    if (!$hasIdx('devices', 'idx_devices_token')) {
        $q('ALTER TABLE devices ADD KEY idx_devices_token (token_hash)');
    }

    // ------------------------------------------------------------------ 3. users: roles + reseller
    if (!str_contains($colType('users', 'role'), 'platform_admin')) {
        $log('  users: new roles');
        $q("ALTER TABLE users MODIFY role ENUM('platform_admin','reseller','super_admin','manager','staff','reception') NOT NULL DEFAULT 'staff'");
    }
    if (!$hasCol('users', 'reseller_id')) {
        $q('ALTER TABLE users ADD COLUMN reseller_id INT UNSIGNED NULL AFTER hotel_id');
    }
    if (!$hasFk('users', 'fk_users_reseller')) {
        $q('ALTER TABLE users ADD CONSTRAINT fk_users_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL');
    }

    // ------------------------------------------------------------------ 4. per-hotel settings
    $platformKeys = ['github_repo', 'github_branch', 'github_token', 'github_subdir', 'last_update_check', 'backup_keep', 'last_tick'];
    if (!$hasCol('system_settings', 'hotel_id')) {
        $log('  system_settings: per-hotel');
        $q('ALTER TABLE system_settings ADD COLUMN hotel_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST');
    }
    $in = implode(',', array_fill(0, count($platformKeys), '?'));
    $q("UPDATE IGNORE system_settings SET hotel_id = 0 WHERE hotel_id = 1 AND setting_key IN ($in)", $platformKeys);
    $q("DELETE FROM system_settings WHERE hotel_id = 1 AND setting_key IN ($in)", $platformKeys);
    if ($idxCols('system_settings', 'PRIMARY') !== ['hotel_id', 'setting_key']) {
        $q('ALTER TABLE system_settings DROP PRIMARY KEY, ADD PRIMARY KEY (hotel_id, setting_key)');
    }

    // ------------------------------------------------------------------ 5. hotel 1 data
    $key = $q("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'registration_key'")->fetchColumn();
    if (is_string($key) && $key !== '') {
        $q('UPDATE hotels SET registration_key = ? WHERE id = 1 AND registration_key IS NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM hotels WHERE registration_key = ?) x)', [$key, $key]);
    }
    $name = $q("SELECT setting_value FROM system_settings WHERE hotel_id = 1 AND setting_key = 'hotel_name'")->fetchColumn();
    if (is_string($name) && trim($name) !== '') {
        $q("UPDATE hotels SET name = ? WHERE id = 1 AND name = 'My Hotel'", [mb_substr(trim($name), 0, 120)]);
    }

    // The first (oldest) active super admin becomes platform admin, still inside hotel 1.
    $hasPlatform = (int) $q("SELECT COUNT(*) FROM users WHERE role = 'platform_admin'")->fetchColumn() > 0;
    if (!$hasPlatform) {
        $first = $q("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($first) {
            $q("UPDATE users SET role = 'platform_admin', hotel_id = 1 WHERE id = ?", [(int) $first]);
            $log('  user #' . (int) $first . ' is now platform admin');
        }
    }
};
