<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Services\EodhdBatchRealtimePriceService;
use App\Services\EodhdMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EodhdMarketDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_keys_include_all_eodhd_storage_sources(): void
    {
        $this->assertSame([
            'eodhd_realtime',
            'eodhd_intraday',
            'eodhd_eod',
        ], EodhdMarketData::sourceKeys());
    }

    public function test_batch_realtime_sync_is_the_stock_live_data_retrieval_path(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:05:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding();

        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 10:00:00', 'UTC')->timestamp,
                'close' => 472.4,
            ]),
        ]);

        $result = app(EodhdBatchRealtimePriceService::class)->syncAll();

        $this->assertSame(1, $result['requested_count']);
        $this->assertSame(1, $result['stored_count']);
        $this->assertSame(1, $result['updated_count']);
        $this->assertSame(0, $result['failed_count']);
        $this->assertDatabaseHas('stock_realtime_prices', [
            'stock_holding_id' => $holding->id,
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'symbol' => 'AMES',
            'price' => '472.40000000',
            'price_type' => 'last',
            'as_of' => '2026-06-05 10:00:00',
        ]);
        $this->assertNotNull($holding->refresh()->latest_realtime_price_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/real-time/AMES.XETRA'));
    }

    public function test_batch_realtime_sync_does_not_create_duplicate_records_for_unchanged_quotes(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:05:00', 'Europe/Vienna'));
        $this->xetraHolding();

        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 10:00:00', 'UTC')->timestamp,
                'close' => 472.4,
            ]),
        ]);

        app(EodhdBatchRealtimePriceService::class)->syncAll();
        $result = app(EodhdBatchRealtimePriceService::class)->syncAll();

        $this->assertSame(0, $result['stored_count']);
        $this->assertSame(1, $result['unchanged_count']);
        $this->assertSame(0, $result['updated_count']);
        $this->assertSame(1, StockRealtimePrice::query()->count());
    }

    public function test_batch_realtime_sync_stores_stale_quotes_without_selecting_them_as_latest(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding([
            'price_status' => 'fresh',
        ]);
        $latestRealtimePrice = StockRealtimePrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'symbol' => 'AMES',
            'price' => '470.00000000',
            'as_of' => Carbon::parse('2026-06-05 09:55:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-05 09:55:00', 'UTC'),
        ]);
        $holding->update(['latest_realtime_price_id' => $latestRealtimePrice->id]);

        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                'code' => 'AMES.XETRA',
                'timestamp' => Carbon::parse('2026-06-05 06:45:00', 'UTC')->timestamp,
                'close' => 469.7,
            ]),
        ]);

        $result = app(EodhdBatchRealtimePriceService::class)->syncAll();

        $this->assertSame(1, $result['stored_count']);
        $this->assertSame(0, $result['updated_count']);
        $this->assertSame($latestRealtimePrice->id, $holding->refresh()->latest_realtime_price_id);
        $this->assertSame('fresh', $holding->price_status);
        $this->assertDatabaseHas('stock_realtime_prices', [
            'stock_holding_id' => $holding->id,
            'source_key' => 'eodhd_realtime',
            'symbol' => 'AMES',
            'price' => '469.70000000',
            'freshness_status' => 'stale',
        ]);
    }

    public function test_intraday_samples_are_retrieved_without_using_the_realtime_endpoint(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding();

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
                        'timestamp' => Carbon::parse('2026-06-05 09:55:00', 'UTC')->timestamp,
                        'datetime' => '2026-06-05 09:55:00',
                        'open' => 472.8,
                        'high' => 472.8,
                        'low' => 472.8,
                        'close' => 472.8,
                    ],
                ]);
            }

            return Http::response([
                'status' => 'error',
                'message' => 'Unexpected endpoint.',
            ], 500);
        });

        app(EodhdMarketData::class)->ensureIntradaySamples($holding);
        app(EodhdMarketData::class)->ensureIntradaySamples($holding->refresh());

        $this->assertSame(2, StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->count());
        $this->assertDatabaseMissing('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'as_of' => '2026-06-05 07:00:00',
        ]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/real-time/AMES.XETRA'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function xetraHolding(array $attributes = []): StockHolding
    {
        return StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'latest_price' => null,
            'latest_price_fetched_at' => null,
            'latest_price_as_of' => null,
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            'price_status' => null,
            ...$attributes,
        ]);
    }
}
