<?php

namespace Tests\Feature;

use App\Services\PreviewOriginalSchema;
use App\Services\PreviewSnapshotDatabase;
use Illuminate\Support\Facades\DB;

class PreviewSnapshotMysqlTest extends PreviewSnapshotDatabaseTest
{
    protected function configureSnapshotTestDatabase(): void
    {
        if (getenv('PREVIEW_MYSQL_TEST_DATABASE') !== 'stocks_preview_snapshot_test') {
            $this->markTestSkipped('Requires the dedicated isolated MySQL CI service.');
        }
        config(['database.default' => 'mysql', 'database.connections.mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'stocks_preview_snapshot_test', 'username' => 'root',
            'password' => 'snapshot-test-only', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        DB::purge('mysql');
        $this->assertSame('stocks_preview_snapshot_test', DB::connection()->getPdo()->query('SELECT DATABASE()')->fetchColumn());
    }

    public function test_source_snapshot_really_runs_in_a_read_only_mysql_transaction(): void
    {
        $connection = DB::getPdo();
        $connection->exec('SET SESSION TRANSACTION READ ONLY');
        $database = new PreviewSnapshotDatabase($connection, new PreviewOriginalSchema);
        $this->assertCount(35, $database->export());
        $connection->beginTransaction();
        try {
            $this->expectException(\PDOException::class);
            $connection->exec("INSERT INTO depots (name, account_balance, is_active) VALUES ('must not write', 0, 0)");
        } finally {
            $connection->rollBack();
            $connection->exec('SET SESSION TRANSACTION READ WRITE');
        }
    }

    public function test_stream_import_survives_the_servers_short_idle_timeout(): void
    {
        $connection = DB::getPdo();
        $database = new PreviewSnapshotDatabase($connection, new PreviewOriginalSchema);
        $before = $database->summarize($database->records());
        $records = static function (): iterable {
            sleep(2);
            yield from [];
        };

        $connection->exec('SET SESSION wait_timeout = 1');
        try {
            $this->assertSame($before, $database->importRecords($records, $before['sha256'], rehearsal: true));
            $this->assertGreaterThanOrEqual(600, (int) $connection->query('SELECT @@SESSION.wait_timeout')->fetchColumn());
        } finally {
            $connection->exec('SET SESSION wait_timeout = DEFAULT');
        }
    }
}
