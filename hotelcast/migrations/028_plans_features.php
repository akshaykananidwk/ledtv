<?php
/**
 * 2.5 plans & feature entitlements (docs/modules/plans_features.md, core/Features.php):
 *   plans.max_users, plans.storage_mb               limits of a plan (NULL = unlimited)
 *   hotels.feature_overrides  JSON {"add": [...], "remove": [...]}  per-customer feature overrides
 *   hotels.max_users, hotels.storage_mb             per-customer limit overrides (NULL = plan limit)
 * Seeds the ready-made plans (Features::PRESETS) that do not exist yet (by name). Existing plans are
 * left as they are: features NULL keeps meaning "everything", old module lists keep their meaning.
 * Idempotent.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $add = [
        ['plans', 'max_users', 'INT UNSIGNED NULL'],
        ['plans', 'storage_mb', 'INT UNSIGNED NULL'],
        ['hotels', 'feature_overrides', 'TEXT NULL'],
        ['hotels', 'max_users', 'INT UNSIGNED NULL'],
        ['hotels', 'storage_mb', 'INT UNSIGNED NULL'],
    ];
    foreach ($add as [$table, $col, $def]) {
        if (!Migrator::hasColumn($pdo, $table, $col)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            $log("Added $table.$col");
        }
    }

    $exists = $pdo->prepare('SELECT id FROM plans WHERE name = ?');
    $insert = $pdo->prepare('INSERT INTO plans (name, description, price_per_tv_month, max_tvs, max_users, storage_mb, features, is_active, created_at) VALUES (?, ?, ?, NULL, ?, NULL, ?, 1, NOW())');
    foreach (Features::PRESETS as $name => $p) {
        $exists->execute([$name]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insert->execute([$name, $p['description'], $p['price'], $p['max_users'], Features::encodePlanKeys(Features::presetKeys($name))]);
        $log('Plan created: ' . $name);
    }
};
