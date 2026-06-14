<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockRealtimePricesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stock_realtime_prices', 'stock_holdings', 'stock_prices'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_realtime_price_migration_repairs_stale_legacy_links_before_copying_prices(): void
    {
        $this->createStockPricesTable();
        $this->createStockHoldingsTable();
        $this->createStockRealtimePricesTable();

        $now = '2026-06-09 07:01:06';

        DB::table('stock_prices')->insert([
            $this->stockPriceAttributes(33, 'old-hash-00000000000000000000000000000000000000000000000000000001', 'eodhd_eod', 'AMES', $now),
            $this->stockPriceAttributes(37, 'new-hash-00000000000000000000000000000000000000000000000000000001', 'eodhd_realtime', 'AMES', $now),
            $this->stockPriceAttributes(40, 'new-hash-00000000000000000000000000000000000000000000000000000002', 'eodhd_realtime', 'LEER', $now),
        ]);

        DB::table('stock_holdings')->insert([
            'id' => 1,
            'isin' => 'FR0010655746',
            'wkn' => null,
            'symbol' => 'AMES',
            'mic_code' => 'XETR',
            'latest_stock_price_id' => 37,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stock_realtime_prices')->insert([
            $this->realtimePriceAttributes(1, 33, 'new-hash-00000000000000000000000000000000000000000000000000000001', 'AMES', $now),
            $this->realtimePriceAttributes(2, 37, 'new-hash-00000000000000000000000000000000000000000000000000000002', 'LEER', $now),
        ]);

        $migration = require database_path('migrations/2026_06_13_072045_create_stock_realtime_prices_table.php');
        $migration->up();

        $this->assertDatabaseCount('stock_realtime_prices', 2);
        $this->assertDatabaseHas('stock_realtime_prices', [
            'id' => 1,
            'legacy_stock_price_id' => 37,
            'quote_hash' => 'new-hash-00000000000000000000000000000000000000000000000000000001',
            'symbol' => 'AMES',
            'stock_holding_id' => 1,
        ]);
        $this->assertDatabaseHas('stock_realtime_prices', [
            'id' => 2,
            'legacy_stock_price_id' => 40,
            'quote_hash' => 'new-hash-00000000000000000000000000000000000000000000000000000002',
            'symbol' => 'LEER',
        ]);
        $this->assertDatabaseHas('stock_holdings', [
            'id' => 1,
            'latest_realtime_price_id' => 1,
        ]);
    }

    private function createStockPricesTable(): void
    {
        Schema::create('stock_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('instrument_key');
            $table->string('quote_hash', 64)->unique();
            $table->string('source_key');
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_quality');
            $table->string('venue')->nullable();
            $table->string('mic')->nullable();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 6)->nullable();
            $table->string('symbol')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('bid', 20, 8)->nullable();
            $table->decimal('ask', 20, 8)->nullable();
            $table->decimal('last', 20, 8)->nullable();
            $table->decimal('close', 20, 8)->nullable();
            $table->decimal('nav', 20, 8)->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->string('price_type')->default('unavailable');
            $table->decimal('spread_abs', 20, 8)->nullable();
            $table->decimal('spread_pct', 12, 6)->nullable();
            $table->timestamp('as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->string('freshness_status')->default('unavailable');
            $table->string('validation_status')->default('invalid');
            $table->json('validation_errors')->nullable();
            $table->string('raw_text_hash')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('trading_times')->nullable();
            $table->timestamps();
        });
    }

    private function createStockHoldingsTable(): void
    {
        Schema::create('stock_holdings', function (Blueprint $table): void {
            $table->id();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 6)->nullable();
            $table->string('symbol')->nullable();
            $table->string('mic_code')->nullable();
            $table->foreignId('latest_stock_price_id')->nullable()->constrained('stock_prices')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function createStockRealtimePricesTable(): void
    {
        Schema::create('stock_realtime_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_holding_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legacy_stock_price_id')->nullable()->unique()->constrained('stock_prices')->nullOnDelete();
            $table->string('instrument_key');
            $table->string('quote_hash', 64)->unique();
            $table->string('source_key');
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_quality');
            $table->string('venue')->nullable();
            $table->string('mic')->nullable();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 6)->nullable();
            $table->string('symbol')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('bid', 20, 8)->nullable();
            $table->decimal('ask', 20, 8)->nullable();
            $table->decimal('last', 20, 8)->nullable();
            $table->decimal('close', 20, 8)->nullable();
            $table->decimal('nav', 20, 8)->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->string('price_type')->default('unavailable');
            $table->decimal('spread_abs', 20, 8)->nullable();
            $table->decimal('spread_pct', 12, 6)->nullable();
            $table->timestamp('as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->string('freshness_status')->default('unavailable');
            $table->string('validation_status')->default('invalid');
            $table->json('validation_errors')->nullable();
            $table->string('raw_text_hash')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('trading_times')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function stockPriceAttributes(int $id, string $quoteHash, string $sourceKey, string $symbol, string $now): array
    {
        return $this->priceAttributes($id, $quoteHash, $sourceKey, $symbol, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function realtimePriceAttributes(int $id, int $legacyStockPriceId, string $quoteHash, string $symbol, string $now): array
    {
        return [
            'legacy_stock_price_id' => $legacyStockPriceId,
            'stock_holding_id' => null,
        ] + $this->priceAttributes($id, $quoteHash, 'eodhd_realtime', $symbol, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceAttributes(int $id, string $quoteHash, string $sourceKey, string $symbol, string $now): array
    {
        return [
            'id' => $id,
            'instrument_key' => 'isin:FR0010655746',
            'quote_hash' => $quoteHash,
            'source_key' => $sourceKey,
            'source_name' => 'EODHD real-time open',
            'source_url' => "https://eodhd.com/api/real-time/{$symbol}.XETRA?fmt=json",
            'source_quality' => 'market_data_vendor',
            'venue' => 'XETRA',
            'mic' => 'XETR',
            'isin' => 'FR0010655746',
            'wkn' => null,
            'symbol' => $symbol,
            'currency' => 'EUR',
            'bid' => null,
            'ask' => null,
            'last' => null,
            'close' => 467.45,
            'nav' => null,
            'price' => 467.45,
            'price_type' => 'historical_session_start',
            'spread_abs' => null,
            'spread_pct' => null,
            'as_of' => '2026-06-08 07:00:00',
            'fetched_at' => $now,
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
            'validation_errors' => '[]',
            'raw_text_hash' => null,
            'raw_payload' => '{"close":467.45}',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
