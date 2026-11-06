<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralMarketDataMariaDbMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql' || ! DB::connection()->isMaria()) {
            $this->markTestSkipped('This regression reproduces the interrupted MariaDB deployment on an isolated local test database.');
        }
        $paths = collect(File::glob(database_path('migrations/*.php')))
            ->filter(fn (string $path): bool => basename($path) < '2026_11_06_215326_create_central_market_data_tables.php')
            ->map(fn (string $path): string => 'database/migrations/'.basename($path))->values()->all();
        Artisan::call('migrate:fresh', ['--path' => $paths, '--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && DB::connection()->getDriverName() === 'mysql' && DB::connection()->isMaria()) {
            Schema::dropAllTables();
        }
        parent::tearDown();
    }

    public function test_failed_char_expression_leaves_three_tables_and_the_corrected_pending_migration_resumes_safely(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'US8740541094', 'currency' => 'EUR']);
        $legacy = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'currency' => 'EUR', 'price' => '193.12345678']);
        $path = database_path('migrations/2026_11_06_215326_create_central_market_data_tables.php');
        $original = str_replace('RTRIM(observation_key)', 'observation_key', File::get($path));
        $failedMigration = eval('?>'.$original);
        try {
            $failedMigration->up();
            $this->fail('The original 2.0.0 expression must reproduce MariaDB error 1901.');
        } catch (QueryException $exception) {
            $this->assertSame(1901, $exception->errorInfo[1]);
        }
        foreach (['market_instruments', 'market_listings', 'market_source_instruments'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertFalse(Schema::hasTable('market_prices'));
        $this->assertFalse(Schema::hasColumn('stock_holdings', 'market_listing_id'));
        $this->assertSame('193.12345678', (string) DB::table('stock_realtime_prices')->where('id', $legacy->id)->value('price'));
        $correctedMigration = require $path;
        $correctedMigration->up();
        $failedMigration->up();
        $this->artisan('migrate --force --no-interaction')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('market_prices'));
        $this->assertTrue(Schema::hasIndex('market_prices', 'market_prices_current_key_unique'));
        $this->assertTrue(Schema::hasColumn('stock_holdings', 'market_listing_id'));
        $this->assertSame(1, DB::table('stock_realtime_prices')->count());
        $this->assertSame('193.12345678', (string) DB::table('stock_realtime_prices')->where('id', $legacy->id)->value('price'));
        $this->artisan('migrate --force --no-interaction')->assertSuccessful();
        $this->assertSame(1, DB::table('stock_realtime_prices')->count());
    }
}
