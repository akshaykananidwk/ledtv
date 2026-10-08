<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitSqlHandlesQuotesAndComments(): void
    {
        $sql = "-- comment ; here\nCREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b');\n/* block ; */INSERT INTO a VALUES ('it''s; ok');\n# hash ;\nSELECT 1";
        $stmts = Migrator::splitSql($sql);
        $this->assertCount(3, $stmts);
        $this->assertStringContainsString("'a;b'", $stmts[0]);
        $this->assertStringContainsString("'it''s; ok'", $stmts[1]);
        $this->assertSame('SELECT 1', $stmts[2]);
    }

    public function testAllMigrationsApplied(): void
    {
        $this->assertSame([], Migrator::pending());
        $this->assertContains('001_init.sql', Migrator::applied());
        foreach (['users', 'user_sessions', 'rooms', 'room_groups', 'devices', 'content_items', 'content_playlists',
                     'broadcast_commands', 'broadcast_logs', 'update_history', 'system_settings', 'activity_logs'] as $t) {
            $this->assertContains($t, DB::column('SHOW TABLES'), "Table $t missing");
        }
    }

    public function testMigrateIsIdempotent(): void
    {
        $this->assertSame([], Migrator::migrate());
    }

    /** 031: old default product names become "Krishna Cloud TV Management"; custom white-label names stay. */
    public function testProductNameMigration031(): void
    {
        $pdo = DB::pdo();
        $run = require HC_ROOT . '/migrations/031_product_name_tv_management.php';
        $get = static fn (): string => (string) DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_name'");
        $set = static function (string $v): void {
            DB::query("INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (0, 'platform_name', :v)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['v' => $v]);
        };
        $before = DB::value("SELECT setting_value FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_name'");
        $brand = DB::value('SELECT brand_name FROM hotels WHERE id = 1');
        try {
            foreach (['Krishna Cloud LED TV', 'HotelCast', ''] as $old) {
                $set($old);
                $run($pdo, static fn (string $m) => null);
                $this->assertSame('Krishna Cloud TV Management', $get(), "old default '$old' renamed");
                $run($pdo, static fn (string $m) => null); // idempotent
                $this->assertSame('Krishna Cloud TV Management', $get());
            }
            $set('StayCast TV');
            $run($pdo, static fn (string $m) => null);
            $this->assertSame('StayCast TV', $get(), 'custom white-label name untouched');
            DB::query("UPDATE hotels SET brand_name = 'Krishna Cloud LED TV' WHERE id = 1");
            $run($pdo, static fn (string $m) => null);
            $this->assertNull(DB::value('SELECT brand_name FROM hotels WHERE id = 1'), 'override with an old default name removed');
            DB::query("UPDATE hotels SET brand_name = 'Alpha Signage' WHERE id = 1");
            $run($pdo, static fn (string $m) => null);
            $this->assertSame('Alpha Signage', DB::value('SELECT brand_name FROM hotels WHERE id = 1'), 'custom override untouched');
            $this->assertSame('Krishna Cloud TV Management', Branding::DEFAULT_PRODUCT);
        } finally {
            if ($before === null || $before === false) {
                DB::query("DELETE FROM system_settings WHERE hotel_id = 0 AND setting_key = 'platform_name'");
            } else {
                $set((string) $before);
            }
            DB::query('UPDATE hotels SET brand_name = :b WHERE id = 1', ['b' => $brand]);
            Settings::flush();
        }
    }
}
