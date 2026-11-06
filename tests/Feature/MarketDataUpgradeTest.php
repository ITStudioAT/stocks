<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Services\MarketDataCoordinator;
use App\Services\MarketDataMode;
use App\Services\MarketDataUpgrade;
use App\Services\MarketPriceLegacyImporter;
use App\Services\MarketPriceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MarketDataUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.market_data.mode_file' => Storage::disk('local')->path('mode.json'),
            'services.market_data.upgrade_directory' => Storage::disk('local')->path('backups')]);
    }

    private function source(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'currency' => 'EUR', 'price' => '193.12345678']);
    }

    private function preparedBackup(): void
    {
        Storage::disk('local')->makeDirectory('backups');
        $file = Storage::disk('local')->path('backups/fixture.sql');
        file_put_contents($file, str_repeat('-- isolated fixture backup'.PHP_EOL, 100));
        AppConfig::create(['key' => MarketDataUpgrade::StateKey, 'value' => ['status' => 'prepared', 'write_barrier' => true,
            'database_signature' => app(MarketDataMode::class)->signature(), 'backup' => $file, 'backup_sha256' => hash_file('sha256', $file)]]);
        app(MarketDataMode::class)->write('upgrading');
    }

    public function test_unprepared_upgrade_and_wrong_deployment_context_cannot_activate(): void
    {
        $upgrade = app(MarketDataUpgrade::class);
        try {
            $upgrade->assertContext(true);
            $this->fail('Testing is not a production deployment.');
        } catch (RuntimeException) {
            $this->assertFalse(MarketPriceStore::enabled());
        }
        $this->expectException(RuntimeException::class);
        $upgrade->finish();
    }

    public function test_verified_upgrade_activates_durably_and_repeated_finish_does_not_duplicate_data(): void
    {
        $this->source();
        $this->preparedBackup();
        $upgrade = app(MarketDataUpgrade::class);
        $result = $upgrade->finish();
        $this->assertSame('active', $result['status']);
        $this->assertSame(1, $result['verification']['legacy_rows']);
        config(['services.market_data.central_enabled' => false]);
        $this->assertTrue(MarketPriceStore::enabled());
        $this->assertSame('already_active', $upgrade->finish()['status']);
        $this->assertSame(1, DB::table('market_prices')->count());
        $this->assertSame(1, DB::table('stock_realtime_prices')->count());
    }

    public function test_changed_source_or_corrupt_central_values_fail_the_verification_gate(): void
    {
        $this->source();
        app(MarketPriceLegacyImporter::class)->run();
        $this->assertSame(1, app(MarketDataUpgrade::class)->verify()['legacy_rows']);
        DB::table('market_prices')->update(['price' => '1.0000000000']);
        $this->expectException(RuntimeException::class);
        app(MarketDataUpgrade::class)->verify();
    }

    public function test_new_legacy_rows_are_detected_and_failed_finish_restores_legacy_mode(): void
    {
        $this->source();
        $this->preparedBackup();
        $this->mock(MarketPriceLegacyImporter::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn([]);
        });
        $this->artisan('market-data:upgrade --finish')->assertFailed();
        $this->assertFalse(MarketPriceStore::enabled());
        $this->assertFalse(app(MarketDataUpgrade::class)->state()['write_barrier']);
        $this->assertSame(1, DB::table('stock_realtime_prices')->count());
    }

    public function test_equal_prices_with_changed_observation_time_do_not_pass_verification(): void
    {
        $this->source();
        app(MarketPriceLegacyImporter::class)->run();
        DB::table('market_prices')->update(['source_time_raw' => 'wrong-source-time']);
        $this->expectException(RuntimeException::class);
        app(MarketDataUpgrade::class)->verify();
    }

    public function test_orphan_isin_with_no_wkn_cannot_be_associated_with_an_unrelated_saved_instrument(): void
    {
        $this->source();
        StockPrice::factory()->create(['isin' => 'US0000000001', 'wkn' => null, 'currency' => 'EUR']);
        app(MarketPriceLegacyImporter::class)->run();
        $result = app(MarketDataUpgrade::class)->verify();
        $this->assertSame(2, $result['legacy_rows']);
        $this->assertSame(1, $result['unresolved_rows']);
        $link = DB::table('market_price_legacy_links')->where('legacy_table', 'stock_prices')->first();
        $this->assertSame('unresolved', $link->status);
    }

    public function test_mode_file_is_bound_to_the_database_and_upgrade_pauses_provider_work(): void
    {
        app(MarketDataMode::class)->write('upgrading');
        $this->assertTrue(app(MarketDataMode::class)->paused());
        $result = app(MarketDataCoordinator::class)->collect();
        $this->assertSame(0, $result['stored_count']);
        $mode = json_decode(file_get_contents(app(MarketDataMode::class)->path()), true);
        $mode['database_signature'] = 'another-database';
        file_put_contents(app(MarketDataMode::class)->path(), json_encode($mode));
        $this->assertNull(app(MarketDataMode::class)->read());
    }
}
