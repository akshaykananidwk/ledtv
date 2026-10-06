<?php
/**
 * HotelCast 2.2 — ticker bar (runs after 010_tickers_access.sql): every hotel's non-empty legacy
 * `ticker_text` setting (Settings page ≤ 2.1) becomes a row in `tickers` (target all, same colours
 * and speed, bottom, reserve_space on), then the setting is cleared so the new Ticker page owns it.
 *
 * Idempotent / resumable: each hotel is converted in one transaction (insert + clear), so an
 * interrupted run never converts a hotel twice; a re-run only finds hotels whose setting is still
 * set. The setting row is set to '' (not deleted) so a platform-wide hotel_id 0 value is not
 * inherited afterwards. The keys stay readable (Tickers::legacy) for chain templates.
 * Uses only PDO (no app classes) so it also works when started by an older updater process.
 */
declare(strict_types=1);

return static function (PDO $pdo, callable $log): void {
    $exists = static function (string $table) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    };
    if (!$exists('tickers') || !$exists('system_settings')) {
        return;
    }
    $hasHotelCol = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings' AND COLUMN_NAME = 'hotel_id'")->fetchColumn() > 0;
    if (!$hasHotelCol) {
        return; // pre-2.0 schema: 002_multitenancy_upgrade.php runs first and adds hotel_id
    }
    $color = static function (?string $c, string $def): string {
        $c = trim((string) $c);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : $def;
    };
    $get = $pdo->prepare('SELECT setting_value FROM system_settings WHERE hotel_id = ? AND setting_key = ?');
    $setting = static function (int $hid, string $key) use ($get): ?string {
        $get->execute([$hid, $key]);
        $v = $get->fetchColumn();
        $get->closeCursor();
        return $v === false ? null : (string) $v;
    };

    $hotels = $pdo->query("SELECT DISTINCT hotel_id FROM system_settings WHERE hotel_id > 0 AND setting_key = 'ticker_text' AND TRIM(setting_value) <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($hotels as $hid) {
        $hid = (int) $hid;
        $pdo->beginTransaction();
        try {
            // Re-read inside the transaction (FOR UPDATE): a parallel run may have converted it already.
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE hotel_id = ? AND setting_key = 'ticker_text' FOR UPDATE");
            $st->execute([$hid]);
            $text = trim((string) $st->fetchColumn());
            $st->closeCursor();
            if ($text === '') {
                $pdo->commit();
                continue;
            }
            $text = mb_substr($text, 0, 1000);
            $speed = (int) ($setting($hid, 'ticker_speed') ?? 5);
            $name = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 60);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                "INSERT INTO tickers (hotel_id, name, message, target_type, target_id, text_color, bg_color, speed, font_size, height, position,
                                      reserve_space, override_lower, priority, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, 'all', NULL, ?, ?, ?, 26, 56, 'bottom', 1, 0, 0, 1, ?, ?)"
            )->execute([
                $hid, $name, $text,
                $color($setting($hid, 'ticker_text_color'), '#FFD700'),
                $color($setting($hid, 'ticker_bg_color'), '#000000'),
                max(1, min(10, $speed ?: 5)), $now, $now,
            ]);
            $pdo->prepare("UPDATE system_settings SET setting_value = '' WHERE hotel_id = ? AND setting_key = 'ticker_text'")->execute([$hid]);
            // TVs pick up the new content object (hash) right away.
            $pdo->prepare(
                "INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (?, 'content_version', '2')
                 ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
            )->execute([$hid]);
            $pdo->commit();
            $log("  hotel $hid: ticker_text → tickers");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
};
