<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\EodhdMarketData;
use App\Services\StockPriceCatalog;
use App\Services\TradingSessionPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EodhdMarketDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_refresh_preserves_the_last_price_without_marking_it_stale(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/real-time/EXXX.XETRA*' => Http::response([
                'status' => 'error',
                'message' => 'Real-time quote unavailable.',
            ]),
        ]);

        $holding = $this->holdingWithStoredPrice();
        $storedPriceId = $holding->latest_stock_price_id;

        $result = app(EodhdMarketData::class)->resolve($holding);

        $holding->refresh();

        $this->assertSame('unavailable', $result->status);
        $this->assertSame($storedPriceId, $holding->latest_stock_price_id);
        $this->assertSame('66.260000', $holding->latest_price);
        $this->assertSame('unavailable_now', $holding->price_status);
    }

    public function test_stale_realtime_quote_preserves_the_last_price_without_marking_it_stale(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/EXXX.XETRA*' => Http::response([
                'code' => 'EXXX.XETRA',
                'timestamp' => Carbon::parse('2026-06-04 15:36:00', 'UTC')->timestamp,
                'close' => 66.26,
            ]),
        ]);

        $holding = $this->holdingWithStoredPrice();

        $result = app(EodhdMarketData::class)->resolve($holding);

        $holding->refresh();

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('66.260000', $holding->latest_price);
        $this->assertSame('unavailable_now', $holding->price_status);
    }

    public function test_realtime_refresh_stores_latest_start_and_previous_end_prices_from_eodhd(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 09:36:00', 'UTC')->timestamp,
                'open' => 470.15,
                'high' => 472.4,
                'low' => 470.15,
                'close' => 472.4,
                'previousClose' => 469.2,
            ]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'France',
            'currency' => 'EUR',
            'latest_price' => null,
            'latest_price_fetched_at' => null,
            'latest_price_as_of' => null,
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => null,
        ]);

        $result = app(EodhdMarketData::class)->resolve($holding);
        $sessionPrices = app(TradingSessionPriceResolver::class)->resolve($holding->refresh(), '472.400000');

        $this->assertSame('fresh', $result->status);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'symbol' => 'AMES',
            'price' => '472.40000000',
            'price_type' => 'last',
            'as_of' => '2026-06-05 09:36:00',
        ]);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time open',
            'symbol' => 'AMES',
            'price' => '470.15000000',
            'price_type' => 'historical_session_start',
            'as_of' => '2026-06-05 07:00:00',
        ]);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD previous close',
            'symbol' => 'AMES',
            'price' => '469.20000000',
            'price_type' => 'historical_session_end',
            'as_of' => '2026-06-04 15:30:00',
        ]);
        $this->assertSame('470.15000000', $sessionPrices['start_price']);
        $this->assertSame('469.20000000', $sessionPrices['end_price']);
    }

    private function holdingWithStoredPrice(): StockHolding
    {
        $holding = StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'latest_price' => '66.260000',
            'latest_price_fetched_at' => '2026-06-04 20:47:04',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/previous',
            'latest_price_as_of' => '2026-06-04 15:36:00',
            'price_status' => 'fresh',
        ]);

        $stockPrice = StockPrice::factory()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://eodhd.com/api/real-time/EXXX.XETRA?fmt=json',
            'source_quality' => 'market_data_vendor',
            'venue' => 'Xetra',
            'mic' => 'XETR',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'symbol' => 'EXXX',
            'currency' => 'EUR',
            'price' => '66.26000000',
            'price_type' => 'last',
            'as_of' => '2026-06-04 15:36:00',
            'fetched_at' => '2026-06-04 20:47:04',
            'freshness_status' => 'closed_market',
            'validation_status' => 'valid',
        ]);

        $holding->update([
            'latest_stock_price_id' => $stockPrice->id,
        ]);

        return $holding->refresh();
    }
}
