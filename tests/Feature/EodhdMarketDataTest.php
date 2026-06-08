<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockHoldingIntradayPrice;
use App\Models\StockPrice;
use App\Services\EodhdMarketData;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_stale_eodhd_realtime_quote_is_stored_without_updating_latest_price(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/EXXX.XETRA*' => Http::response([
                'code' => 'EXXX.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 06:45:00', 'UTC')->timestamp,
                'close' => 65.70,
            ]),
            'eodhd.com/api/intraday/EXXX.XETRA*' => Http::response([]),
            'eodhd.com/api/eod/EXXX.XETRA*' => Http::sequence()
                ->push([[
                    'date' => '2026-06-04',
                    'close' => 66.46,
                ]])
                ->push([[
                    'date' => '2026-06-03',
                    'close' => 66.33,
                ]]),
        ]);

        $holding = $this->holdingWithStoredPrice();

        $result = app(EodhdMarketData::class)->resolve($holding);

        $holding->refresh();

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('66.260000', $holding->latest_price);
        $this->assertSame('unavailable_now', $holding->price_status);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'symbol' => 'EXXX',
            'price' => '65.70000000',
            'price_type' => 'last',
            'as_of' => '2026-06-05 06:45:00',
            'freshness_status' => 'stale',
            'validation_status' => 'valid',
        ]);
    }

    public function test_delayed_eodhd_realtime_quote_within_extended_window_updates_latest_price(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-08 16:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/EXXX.XETRA*' => Http::response([
                'code' => 'EXXX.XETRA',
                'timestamp' => Carbon::parse('2026-06-08 12:48:00', 'UTC')->timestamp,
                'open' => 65.22,
                'high' => 65.77,
                'low' => 64.89,
                'close' => 65.70,
                'previousClose' => 66.26,
            ]),
            'eodhd.com/api/intraday/EXXX.XETRA*' => Http::response([]),
            'eodhd.com/api/eod/EXXX.XETRA*' => Http::sequence()
                ->push([[
                    'date' => '2026-06-05',
                    'close' => 66.26,
                ]])
                ->push([[
                    'date' => '2026-06-04',
                    'close' => 66.46,
                ]]),
        ]);

        $holding = $this->holdingWithStoredPrice();

        $result = app(EodhdMarketData::class)->resolve($holding);

        $holding->refresh();

        $this->assertSame('delayed', $result->status);
        $this->assertSame('65.700000', $holding->latest_price);
        $this->assertSame('delayed', $holding->price_status);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'symbol' => 'EXXX',
            'price' => '65.70000000',
            'price_type' => 'last',
            'as_of' => '2026-06-08 12:48:00',
            'freshness_status' => 'delayed',
            'validation_status' => 'valid',
        ]);
    }

    public function test_refresh_prefers_five_minute_intraday_prices_while_exchange_is_open(): void
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
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response(
                collect(range(0, 24))
                    ->map(fn (int $index): array => [
                        'timestamp' => Carbon::parse('2026-06-05 07:01:00', 'UTC')->addMinutes($index * 20)->timestamp,
                        'close' => 470.15 + $index,
                    ])
                    ->all(),
            ),
            'eodhd.com/api/eod/AMES.XETRA*' => Http::sequence()
                ->push([[
                    'date' => '2026-06-04',
                    'close' => 469.20,
                ]])
                ->push([[
                    'date' => '2026-06-03',
                    'close' => 468.10,
                ]]),
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
        $holding->refresh();

        $this->assertSame('fresh', $result->status);
        $this->assertSame('478.150000', $holding->latest_price);
        $this->assertSame('470.15000000', $holding->start_price);
        $this->assertNull($holding->end_price);
        $this->assertSame('469.20000000', $holding->end_price_24);
        $this->assertSame('468.10000000', $holding->end_price_48);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'symbol' => 'AMES',
            'price' => '478.15000000',
            'price_type' => 'intraday',
            'as_of' => '2026-06-05 09:41:00',
        ]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/real-time/AMES.XETRA'));
        $this->assertSame(20, StockHoldingIntradayPrice::query()->where('stock_holding_id', $holding->id)->count());
        $this->assertDatabaseHas('stock_holding_intraday_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'sample_index' => 0,
            'price' => '470.15000000',
            'as_of' => '2026-06-05 07:01:00',
            'source_name' => 'EODHD intraday',
            'price_type' => 'intraday',
        ]);
        $this->assertDatabaseHas('stock_holding_intraday_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'sample_index' => 19,
            'price' => '494.15000000',
            'as_of' => '2026-06-05 15:01:00',
            'source_name' => 'EODHD intraday',
            'price_type' => 'intraday',
        ]);
    }

    public function test_realtime_refresh_clears_latest_price_and_rolls_end_prices_after_exchange_close(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 15:35:00', 'UTC')->timestamp,
                'open' => 470.15,
                'high' => 473.1,
                'low' => 470.15,
                'close' => 472.8,
                'previousClose' => 469.2,
            ]),
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([
                [
                    'timestamp' => Carbon::parse('2026-06-05 07:01:00', 'UTC')->timestamp,
                    'close' => 470.15,
                ],
            ]),
            'eodhd.com/api/eod/AMES.XETRA*' => Http::sequence()
                ->push([[
                    'date' => '2026-06-05',
                    'close' => 472.80,
                ]])
                ->push([[
                    'date' => '2026-06-04',
                    'close' => 469.20,
                ]])
                ->push([[
                    'date' => '2026-06-03',
                    'close' => 468.10,
                ]]),
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
            'latest_price' => '469.200000',
            'latest_price_fetched_at' => '2026-06-04 17:40:00',
            'latest_price_as_of' => '2026-06-04 15:35:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
        ]);

        $result = app(EodhdMarketData::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('closed_market', $result->status);
        $this->assertNull($holding->latest_price);
        $this->assertSame('470.15000000', $holding->start_price);
        $this->assertSame('472.80000000', $holding->end_price);
        $this->assertSame('469.20000000', $holding->end_price_24);
        $this->assertSame('468.10000000', $holding->end_price_48);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD close',
            'symbol' => 'AMES',
            'price' => '472.80000000',
            'price_type' => 'historical_session_end',
            'as_of' => '2026-06-05 15:30:00',
        ]);
    }

    public function test_realtime_refresh_fills_session_fields_from_stored_eodhd_quotes_when_session_endpoints_are_sparse(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 13:36:00', 'UTC')->timestamp,
                'open' => 470.15,
                'high' => 473.1,
                'low' => 470.15,
                'close' => 471.0,
                'previousClose' => 469.2,
            ]),
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([]),
            'eodhd.com/api/eod/AMES.XETRA*' => Http::sequence()
                ->push([])
                ->push([[
                    'date' => '2026-06-04',
                    'close' => 469.20,
                ]])
                ->push([[
                    'date' => '2026-06-03',
                    'close' => 466.80,
                ]]),
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
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $result = app(EodhdMarketData::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('closed_market', $result->status);
        $this->assertNull($holding->latest_price);
        $this->assertSame('470.15000000', $holding->start_price);
        $this->assertSame('471.00000000', $holding->end_price);
        $this->assertSame('469.20000000', $holding->end_price_24);
        $this->assertSame('466.80000000', $holding->end_price_48);
    }

    public function test_intraday_refresh_stores_only_priced_five_minute_candles_without_duplicates(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/intraday/AMES.XETRA')) {
                return Http::response([
                    [
                        'timestamp' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->timestamp,
                        'datetime' => '2026-06-05 07:00:00',
                    ],
                    [
                        'timestamp' => Carbon::parse('2026-06-05 07:05:00', 'UTC')->timestamp,
                        'datetime' => '2026-06-05 07:05:00',
                        'open' => 470.15,
                        'high' => 470.15,
                        'low' => 470.15,
                        'close' => 470.15,
                    ],
                    [
                        'timestamp' => Carbon::parse('2026-06-05 15:30:00', 'UTC')->timestamp,
                        'datetime' => '2026-06-05 15:30:00',
                        'open' => 472.8,
                        'high' => 472.8,
                        'low' => 472.8,
                        'close' => 472.8,
                    ],
                ]);
            }

            if (str_contains($request->url(), '/eod/AMES.XETRA')) {
                return Http::response([[
                    'date' => $request->data()['from'] ?? '2026-06-05',
                    'close' => 472.8,
                ]]);
            }

            return Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 15:35:00', 'UTC')->timestamp,
                'close' => 471.2,
            ]);
        });

        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'France',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        app(EodhdMarketData::class)->resolve($holding);
        app(EodhdMarketData::class)->resolve($holding->refresh());

        $holding->refresh();

        $this->assertSame('closed_market', $holding->price_status);
        $this->assertSame(2, StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->count());
        $this->assertDatabaseMissing('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'as_of' => '2026-06-05 07:00:00',
        ]);
        $this->assertSame(1, StockPrice::query()
            ->where('source_key', 'eodhd_intraday')
            ->where('price_type', 'intraday')
            ->where('symbol', 'AMES')
            ->count());
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'symbol' => 'AMES',
            'price' => '472.80000000',
            'price_type' => 'intraday',
        ]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/real-time/AMES.XETRA'));
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
