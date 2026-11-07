<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Services\CloudwaysFreeMarketDataSync;
use App\Services\MarketIdentityRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CloudwaysFreeMarketDataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_prices_are_mapped_by_identity_not_remote_ids_and_import_is_repeatable(): void
    {
        config(['database.connections.sync_source' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $schema = Schema::connection('sync_source');
        $schema->create('market_instruments', function (Blueprint $table) {
            $table->id();
            $table->string('identity_key');
        });
        $schema->create('market_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_instrument_id');
            $table->string('venue_key');
            $table->string('currency');
            $table->string('quote_unit');
            $table->string('status');
        });
        $schema->create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_listing_id');
            $table->string('source_key');
            $table->string('currency');
            $table->string('validation_status');
            $table->boolean('is_current');
            $table->string('price');
            $table->string('trading_date');
        });
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS', 'currency' => 'USD']);
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $source = DB::connection('sync_source');
        $source->table('market_instruments')->insert(['id' => 91, 'identity_key' => 'isin:US0378331005']);
        $source->table('market_listings')->insert(['id' => 92, 'market_instrument_id' => 91, 'venue_key' => $listing->venue_key, 'currency' => 'USD', 'quote_unit' => 'unit', 'status' => 'verified']);
        $source->table('market_prices')->insert([
            ['id' => 1, 'market_listing_id' => 92, 'source_key' => 'yahoo_daily', 'currency' => 'USD', 'validation_status' => 'valid', 'is_current' => true, 'price' => '120.00', 'trading_date' => '2026-11-05'],
            ['id' => 2, 'market_listing_id' => 92, 'source_key' => 'eodhd_eod', 'currency' => 'USD', 'validation_status' => 'valid', 'is_current' => true, 'price' => '130.00', 'trading_date' => '2026-11-06'],
        ]);

        $sync = app(CloudwaysFreeMarketDataSync::class);
        $first = $sync->sync('sync_source');
        $second = $sync->sync('sync_source');
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $first['unmatched']);
        $this->assertSame(0, $second['imported']);
        $this->assertDatabaseHas('market_prices', ['market_listing_id' => $listing->id, 'source_key' => 'yahoo_daily']);
        $this->assertDatabaseMissing('market_prices', ['source_key' => 'eodhd_eod']);
        $this->assertSame(1, DB::table('market_prices')->count());
    }
}
