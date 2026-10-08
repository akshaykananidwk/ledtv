<?php
/**
 * 2.5: product renamed to "Krishna Cloud TV Management" (a general digital-signage SaaS, no longer hotel-only).
 * Installations that still show a previous default name ("Krishna Cloud LED TV" / "HotelCast") get the new one;
 * a custom white-label name is left untouched. Reseller / customer brand overrides that only repeat an old
 * default name are cleared, so they inherit the new name. Customer (business) names are never touched. Idempotent.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $new = 'Krishna Cloud TV Management';
    $old = ['Krishna Cloud LED TV', 'HotelCast'];
    $st = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE hotel_id = 0 AND setting_key = 'platform_name' AND setting_value IN ('', ?, ?)");
    $st->execute([$new, ...$old]);
    $log('platform_name updated: ' . $st->rowCount());
    foreach (['hotels', 'resellers'] as $table) {
        if (Migrator::hasColumn($pdo, $table, 'brand_name')) {
            $st = $pdo->prepare("UPDATE {$table} SET brand_name = NULL WHERE brand_name IN (?, ?)");
            $st->execute($old);
        }
    }
};
