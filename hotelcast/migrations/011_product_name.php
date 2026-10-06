<?php
/**
 * 2.2: product renamed to "Krishna Cloud LED TV". Installations that still show the old default name
 * ("HotelCast" / "HotelCast Hotel") get the new one; a custom white-label name is left untouched.
 * Idempotent.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $new = 'Krishna Cloud LED TV';
    $st = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE hotel_id = 0 AND setting_key = 'platform_name' AND setting_value IN ('', 'HotelCast')");
    $st->execute([$new]);
    $log('platform_name updated: ' . $st->rowCount());
    $st = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'hotel_name' AND setting_value = 'HotelCast Hotel'");
    $st->execute([$new]);
    if (Migrator::hasColumn($pdo, 'hotels', 'brand_name')) {
        $pdo->exec("UPDATE hotels SET brand_name = NULL WHERE brand_name = 'HotelCast'");
    }
    if (Migrator::hasColumn($pdo, 'resellers', 'brand_name')) {
        $pdo->exec("UPDATE resellers SET brand_name = NULL WHERE brand_name = 'HotelCast'");
    }
};
