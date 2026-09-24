<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\User;
use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewSnapshotArchive;
use App\Services\PreviewSnapshotDatabase;
use App\Services\PreviewSnapshotStream;
use App\Services\PreviewSnapshotTransfer;
use Illuminate\Filesystem\Filesystem;
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

    public function test_populated_mysql_preview_refresh_restores_its_streamed_checkpoint(): void
    {
        User::factory()->create(['id' => 1, 'email' => 'preview-admin@stocks.invalid', 'remember_token' => null]);
        Depot::factory()->create(['name' => 'Preview before refresh', 'account_number' => 'AT123456']);
        $database = new PreviewSnapshotDatabase(DB::getPdo(), new PreviewOriginalSchema);
        $before = $database->summarize($database->records())['sha256'];
        $tables = $database->export();
        $tables['depots'][0]['name'] = 'Fresh original data';
        $directory = sys_get_temp_dir().'/stocks-mysql-refresh-'.bin2hex(random_bytes(8));
        mkdir($directory.'/private', 0700, true);
        mkdir($directory.'/framework/sessions', 0700, true);
        mkdir($directory.'/framework/cache/data', 0700, true);
        $directory = realpath($directory);

        try {
            $transfer = new PreviewSnapshotTransfer($database, new PreviewSnapshotArchive(new PreviewOriginalPolicy),
                $directory.'/private', $directory.'/framework/down', $directory.'/framework/sessions', $directory.'/framework/cache/data');
            $request = $transfer->prepare([
                'source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40),
                'target_commit' => str_repeat('b', 40), 'nonce' => str_repeat('c', 64),
            ], refreshData: true);
            $streamPath = $directory.'/original.snapshot';
            $snapshot = (new PreviewSnapshotStream)->seal($database->recordsFromTables($tables), $request['context'], hex2bin($request['recipient']), $streamPath);

            $transfer->importStream($streamPath, $snapshot['sha256']);

            $this->assertSame('Fresh original data', Depot::firstOrFail()->name);
            $this->assertFileExists($directory.'/private/before.stream');
            $this->assertFileDoesNotExist($directory.'/private/before.bin');
            $transfer->restore();
            $this->assertSame($before, $database->summarize($database->records())['sha256']);
            $this->assertSame('Preview before refresh', Depot::firstOrFail()->name);
            $transfer->finish();
            $this->assertFileDoesNotExist($directory.'/framework/down');
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}
