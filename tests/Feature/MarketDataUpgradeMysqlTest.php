<?php

namespace Tests\Feature;

use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Services\MarketDataUpgrade;
use App\Services\MarketPriceLegacyImporter;
use App\Services\MarketPriceStore;
use Illuminate\Database\QueryException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MarketDataUpgradeMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run this deployment integration test against a verified local stocks_v2_test_* database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        Storage::fake('market-upgrade-mysql');
        config(['services.market_data.mode_file' => Storage::disk('market-upgrade-mysql')->path('mode.json'),
            'services.market_data.upgrade_directory' => Storage::disk('market-upgrade-mysql')->path('backups')]);
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && DB::connection()->getDriverName() === 'mysql') {
            Schema::dropAllTables();
        }
        parent::tearDown();
    }

    private function source(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'price' => '193.12345678', 'currency' => 'EUR']);
    }

    private function fakeBackup(bool $successful = true): void
    {
        Process::fake(function ($process) use ($successful) {
            if (is_array($process->command) && in_array('--version', $process->command, true)) {
                return Process::result(output: 'mysqldump Ver 8.4.3 for Win64 on x86_64');
            }
            if (is_array($process->command)) {
                foreach ($process->command as $argument) {
                    if (str_starts_with($argument, '--result-file=')) {
                        file_put_contents(substr($argument, 14), str_repeat('-- controlled native backup fixture'.PHP_EOL, 100));
                    }
                }
            }

            return Process::result(exitCode: $successful ? 0 : 1);
        });
    }

    public function test_native_backup_barriers_activation_and_explicit_rollback_are_ordered(): void
    {
        $this->source();
        $this->fakeBackup();
        $upgrade = app(MarketDataUpgrade::class);
        $upgrade->prepare();
        $state = $upgrade->state();
        $this->assertTrue(is_file($state['backup'].'.json'));
        $this->assertSame(hash_file('sha256', $state['backup']), $state['backup_sha256']);
        try {
            DB::table('stock_realtime_prices')->update(['price' => '999']);
            $this->fail('Legacy writes must be rejected by the database barrier.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('legacy market-data writes are paused', $exception->getMessage());
        }
        $this->assertSame('active', $upgrade->finish()['status']);
        $this->assertTrue(MarketPriceStore::enabled());
        $this->assertSame(1, MarketPrice::count());
        $this->assertSame('already_active', $upgrade->finish()['status']);
        $upgrade->rollback();
        $this->assertFalse(MarketPriceStore::enabled());
        $this->assertSame(1, DB::table('stock_realtime_prices')->update(['price' => '194']));
        $this->assertSame(1, MarketPrice::count());
    }

    public function test_failed_backup_keeps_legacy_data_and_refuses_activation(): void
    {
        $this->source();
        $this->fakeBackup(false);
        $this->artisan('market-data:upgrade --prepare')->assertFailed();
        $this->assertFalse(MarketPriceStore::enabled());
        $this->assertFalse(app(MarketDataUpgrade::class)->state()['write_barrier']);
        $this->assertSame(1, DB::table('stock_realtime_prices')->count());
        $this->assertSame(0, MarketPrice::count());
    }

    public function test_corrupt_backup_and_wrong_restore_target_are_refused(): void
    {
        $this->source();
        $this->fakeBackup();
        $upgrade = app(MarketDataUpgrade::class);
        $upgrade->prepare();
        $state = $upgrade->state();
        file_put_contents($state['backup'], 'corrupted');
        try {
            $upgrade->finish();
            $this->fail('A corrupt backup must prevent activation.');
        } catch (RuntimeException) {
            $this->assertFalse(MarketPriceStore::enabled());
        }
        $this->expectException(RuntimeException::class);
        $upgrade->restore($state['backup'].'.json', 'another_database');
    }

    public function test_restore_uses_native_streaming_process_with_the_confirmed_database(): void
    {
        $this->source();
        $this->fakeBackup();
        $upgrade = app(MarketDataUpgrade::class);
        $upgrade->prepare();
        $state = $upgrade->state();
        $upgrade->restore($state['backup'].'.json', DB::connection()->getDatabaseName());
        $this->assertFalse(MarketPriceStore::enabled());
        Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === config('services.market_data.mysql_binary')
            && $process->command[array_key_last($process->command)] === DB::connection()->getDatabaseName());
    }

    public function test_partial_schema_can_resume_without_losing_prices_and_repairs_constraints(): void
    {
        $this->source();
        app(MarketPriceLegacyImporter::class)->run();
        Schema::table('market_prices', function ($table): void {
            $table->dropUnique('market_prices_current_key_unique');
            $table->dropForeign('market_prices_market_listing_id_foreign');
        });
        $migration = require database_path('migrations/2026_11_06_215326_create_central_market_data_tables.php');
        $migration->up();
        $migration->up();
        $index = require database_path('migrations/2026_11_06_225424_add_source_series_index_to_market_prices_table.php');
        $index->up();
        $this->assertSame(1, MarketPrice::count());
        $this->assertTrue(Schema::hasIndex('market_prices', 'market_prices_current_key_unique'));
        $this->assertTrue(collect(Schema::getForeignKeys('market_prices'))->contains('name', 'market_prices_market_listing_id_foreign'));
    }

    public function test_mariadb_native_clients_are_supported_without_mysql_only_dump_flags(): void
    {
        $this->source();
        config(['services.market_data.mysqldump_binary' => 'mysqldump', 'services.market_data.mysql_binary' => 'mysql']);
        Process::fake(function ($process) {
            $command = $process->command;
            if (in_array($command[0], ['mysqldump', 'mysql'], true)) {
                return Process::result(exitCode: 1);
            }
            if (in_array('--version', $command, true)) {
                return Process::result(output: 'mariadb-dump Ver 10.19 Distrib 10.11.6-MariaDB');
            }
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, 14), str_repeat('-- MariaDB backup fixture'.PHP_EOL, 100));
                }
            }

            return Process::result();
        });
        app(MarketDataUpgrade::class)->prepare();
        $this->assertSame('mariadb', app(MarketDataUpgrade::class)->state()['restore_binary']);
        Process::assertRan(fn ($process) => $process->command[0] === 'mariadb-dump'
            && in_array('--single-transaction', $process->command, true)
            && ! in_array('--set-gtid-purged=OFF', $process->command, true)
            && ! in_array('--column-statistics=0', $process->command, true));
    }

    public function test_native_backup_restore_round_trip_preserves_legacy_data_and_can_upgrade_again(): void
    {
        if (getenv('STOCKS_TEST_NATIVE_MYSQL') !== '1') {
            $this->markTestSkipped('Enable native database clients explicitly on the isolated local test database.');
        }
        Process::swap(new Factory);
        $this->source();
        $upgrade = app(MarketDataUpgrade::class);
        $upgrade->prepare();
        $manifest = $upgrade->state()['backup'].'.json';
        $this->assertSame('active', $upgrade->finish()['status']);
        $upgrade->rollback();
        DB::table('stock_realtime_prices')->update(['price' => '999']);
        $upgrade->restore($manifest, DB::connection()->getDatabaseName());
        $this->assertSame('193.12345678', (string) DB::table('stock_realtime_prices')->value('price'));
        $this->assertSame(0, MarketPrice::count());
        $this->assertFalse(MarketPriceStore::enabled());
        $migration = require database_path('migrations/2026_11_06_215326_create_central_market_data_tables.php');
        $migration->up();
        $upgrade->prepare();
        $this->assertSame('active', $upgrade->finish()['status']);
        $this->assertSame(1, MarketPrice::count());
    }
}
