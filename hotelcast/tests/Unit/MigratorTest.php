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
}
