<?php

namespace Tests\Feature;

use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Services\MarketDataUpgrade;
use App\Services\MarketIdentityRegistry;
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

    public function test_generated_current_key_stays_unique_across_revisions_and_character_padding_modes(): void
    {
        $price = MarketPrice::factory()->create();
        $attributes = $price->getAttributes();
        unset($attributes['id'], $attributes['current_key']);
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode')->sql_mode;
        $modes = [$mode];
        if (DB::connection()->isMaria()) {
            $modes[] = $mode.',PAD_CHAR_TO_FULL_LENGTH';
        }
        try {
            foreach ($modes as $currentMode) {
                DB::statement('SET SESSION sql_mode = ?', [$currentMode]);
                $this->assertSame($price->observation_key, $price->fresh()->current_key);
                $attributes['revision']++;
                $attributes['is_current'] = false;
                $historical = MarketPrice::create($attributes);
                $this->assertNull($historical->current_key);
                try {
                    DB::table('market_prices')->where('id', $historical->id)->update(['is_current' => true]);
                    $this->fail('The database must reject a second current revision even outside the price store.');
                } catch (QueryException $exception) {
                    $this->assertSame('23000', $exception->errorInfo[0]);
                }
                DB::transaction(function () use ($price, $historical): void {
                    DB::table('market_prices')->where('id', $price->id)->update(['is_current' => false]);
                    DB::table('market_prices')->where('id', $historical->id)->update(['is_current' => true]);
                });
                $price = $historical->fresh();
                $this->assertSame(1, MarketPrice::where('is_current', true)->count());
                $this->assertSame($price->observation_key, $price->current_key);
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    public function test_concurrent_collectors_keep_one_current_observation_in_the_native_database(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $config = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'CACHE_STORE' => 'array'];
        $code = 'app(App\\Services\\LocalMarketDataDatabase::class)->assertLocal(); if (! str_starts_with(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName(), "stocks_v2_test_")) { throw new RuntimeException("Unsafe test target"); } '
            .'for ($index=0; $index<10; $index++) { app(App\\Services\\MarketPriceStore::class)->store('.$listing->id.', ["source_key"=>"yahoo_intraday", "currency"=>"EUR", "price"=>"193.1234567890", "observed_at"=>"2026-11-06 12:00:00", "effective_at"=>"2026-11-06 12:00:00"]); }';
        $workers = [];
        foreach (range(1, 2) as $worker) {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), $environment, timeout: 60);
            $process->start();
            $workers[] = $process;
        }
        foreach ($workers as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        }
        $this->assertSame(1, MarketPrice::count());
        $this->assertSame(1, MarketPrice::where('is_current', true)->count());
        $this->assertSame('193.1234567890', (string) MarketPrice::first()->price);
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
