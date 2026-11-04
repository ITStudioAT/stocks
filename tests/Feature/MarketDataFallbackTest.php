<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Services\EodhdIntradayFallback;
use App\Services\FreeIntradayMarketData;
use App\Services\MarketDataSourceBusy;
use App\Services\XetraIntradayCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MarketDataFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(Carbon::parse('2026-11-04 10:00', 'UTC'));
        config(['services.eodhd.enabled' => true, 'services.eodhd.key' => 'fake-token', 'services.eodhd.base_url' => 'https://eodhd.com/api']);
        Http::preventStrayRequests();
    }

    public function test_missing_free_quote_uses_eodhd_for_only_the_requested_instrument(): void
    {
        $holding = $this->holding();
        $other = StockHolding::factory()->create();
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andThrow(new RuntimeException('Free feed unavailable.'));
        Http::fake(['eodhd.com/api/real-time/RAW.XETRA*' => Http::response($this->quote())]);
        $result = app(FreeIntradayMarketData::class)->syncHolding($holding);
        $this->assertSame(0, $result['failed_count']);
        $this->assertSame(['EODHD'], $result['coverage'][0]['source_markers']);
        $this->assertSame('eodhd_realtime', $holding->fresh()->latestRealtimePrice->source_key);
        $this->assertNull($other->fresh()->latest_realtime_price_id);
        Http::assertSentCount(1);
    }

    public function test_failed_fallback_preserves_price_and_origin(): void
    {
        $holding = $this->holding();
        $previous = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin,
            'symbol' => $holding->symbol, 'source_key' => 'yahoo_xetra_intraday', 'source_name' => 'Yahoo Finance Xetra', 'source_url' => 'https://finance.yahoo.com/quote/RAW.DE/',
            'price' => '56', 'as_of' => now()->subMinutes(5)]);
        $holding->update(['latest_realtime_price_id' => $previous->id]);
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andThrow(new RuntimeException('Free feed unavailable.'));
        Http::fake(['eodhd.com/*' => Http::response([], 503)]);
        $result = app(FreeIntradayMarketData::class)->syncHolding($holding);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame($previous->id, $holding->fresh()->latest_realtime_price_id);
        $this->assertSame('yahoo_xetra_intraday', $previous->fresh()->source_key);
        $this->assertSame('56.00000000', $previous->fresh()->price);
    }

    public function test_collector_lock_does_not_trigger_a_paid_fallback(): void
    {
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andThrow(new MarketDataSourceBusy('Collector busy.'));
        Http::fake();
        $result = app(FreeIntradayMarketData::class)->syncHolding($this->holding());
        $this->assertSame(1, $result['failed_count']);
        Http::assertNothingSent();
    }

    public function test_bulk_outage_uses_twenty_instrument_batches(): void
    {
        StockHolding::factory()->count(21)->sequence(fn ($sequence): array => ['symbol' => 'F'.$sequence->index])
            ->create(['exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR', 'latest_price' => null]);
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andThrow(new RuntimeException('Free feed unavailable.'));
        Http::fake(['eodhd.com/api/real-time/*' => function ($request) {
            $primary = basename(parse_url($request->url(), PHP_URL_PATH));
            $symbols = array_filter([$primary, ...explode(',', $request['s'] ?? '')]);

            return Http::response(array_map(fn (string $symbol): array => [...$this->quote(), 'code' => $symbol], $symbols));
        }]);
        $result = app(FreeIntradayMarketData::class)->syncAll();
        $this->assertSame(21, $result['stored_count']);
        $this->assertSame(0, $result['failed_count']);
        Http::assertSentCount(2);
    }

    public function test_a_free_quote_saved_while_eodhd_is_loading_cannot_be_overwritten(): void
    {
        $holding = $this->holding();
        $newerId = null;
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andThrow(new RuntimeException('Free feed unavailable.'));
        Http::fake(['eodhd.com/api/real-time/*' => function () use ($holding, &$newerId) {
            $newer = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin,
                'symbol' => $holding->symbol, 'source_key' => 'yahoo_xetra_intraday', 'source_name' => 'Yahoo Finance Xetra',
                'source_url' => 'https://finance.yahoo.com/quote/RAW.DE/', 'as_of' => now()]);
            $newerId = $newer->id;
            StockHolding::query()->whereKey($holding->id)->update(['latest_realtime_price_id' => $newerId]);

            return Http::response($this->quote());
        }]);
        app(FreeIntradayMarketData::class)->syncHolding($holding);
        $this->assertSame($newerId, $holding->fresh()->latest_realtime_price_id);
        $this->assertSame('yahoo_xetra_intraday', $holding->fresh()->latestRealtimePrice->source_key);
    }

    public function test_old_history_uses_eodhd_without_relabelling_or_overwriting_existing_records(): void
    {
        $holding = $this->holding();
        $from = Carbon::parse('2026-06-01 08:00', 'UTC');
        $until = $from->copy()->addHour();
        Http::fake(['eodhd.com/api/intraday/RAW.XETRA*' => Http::response([
            ['timestamp' => $from->timestamp, 'open' => 55, 'high' => 57, 'low' => 54, 'close' => 56, 'volume' => 10],
            ['timestamp' => $from->timestamp, 'open' => 1, 'high' => 2, 'low' => 1, 'close' => 2, 'currency' => 'USD'],
        ])]);
        $provider = app(FreeIntradayMarketData::class);
        $records = $provider->records($holding, $from, $until);
        $this->assertCount(1, $records);
        $this->assertSame('eodhd_intraday', $records[0]['source_key']);
        $this->assertSame(['EODHD'], $records[0]['source_markers']);
        $provider->persist($holding, $records);
        $provider->records($holding, $from, $until);
        $this->assertDatabaseHas('stock_holding_intraday_candles', ['source_key' => 'eodhd_intraday', 'close' => 56]);
        Http::assertSentCount(1);
    }

    public function test_running_time_windows_share_a_cache_and_failures_have_a_cooldown(): void
    {
        $holding = $this->holding();
        $from = now()->subHour();
        Http::fake(['eodhd.com/api/intraday/*' => Http::response([
            ['timestamp' => $from->timestamp, 'open' => 55, 'high' => 57, 'low' => 54, 'close' => 56],
        ])]);
        $provider = app(EodhdIntradayFallback::class);
        $provider->records($holding, $from, now());
        $this->travelTo(now()->addMinute());
        $provider->records($holding, $from, now());
        Http::assertSentCount(1);
    }

    public function test_failed_history_requests_are_not_repeated_during_the_cooldown(): void
    {
        $holding = $this->holding();
        $from = now()->subMonth();
        Http::fake(['eodhd.com/api/intraday/*' => Http::response([], 429)]);
        foreach ([0, 1] as $minute) {
            try {
                app(EodhdIntradayFallback::class)->records($holding, $from, now()->addMinutes($minute));
                $this->fail('Missing history must not be reported as complete.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('preserved', $exception->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_partial_free_history_only_requests_the_older_missing_period(): void
    {
        $holding = $this->holding();
        $free = ['timestamp' => Carbon::parse('2026-11-04 09:00', 'UTC')->timestamp, 'source_key' => 'xetra_posttrade', 'close' => '56'];
        $this->mock(XetraIntradayCollector::class)->shouldReceive('collect')->andReturn([
            'instruments' => [['isin' => $holding->isin, 'status' => 'available', 'coverage_complete' => true]],
            'series' => [$holding->isin => [$free]], 'days' => ['2026-11-03' => [], '2026-11-04' => []],
        ]);
        Http::fake(['eodhd.com/api/intraday/*' => Http::response([
            ['timestamp' => Carbon::parse('2026-11-02 09:00', 'UTC')->timestamp, 'open' => 54, 'high' => 56, 'low' => 53, 'close' => 55],
        ])]);
        $records = app(FreeIntradayMarketData::class)->records($holding, Carbon::parse('2026-11-01', 'UTC'), now());
        $this->assertSame(['eodhd_intraday', 'xetra_posttrade'], array_column($records, 'source_key'));
        Http::assertSent(fn ($request): bool => $request['to'] < Carbon::parse('2026-11-03', 'Europe/Berlin')->timestamp);
        Http::assertSentCount(1);
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['symbol' => 'RAW', 'isin' => 'AT0000606306', 'exchange' => 'XETRA', 'mic_code' => 'XETR',
            'currency' => 'EUR', 'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin']);
    }

    /** @return array<string, mixed> */
    private function quote(): array
    {
        return ['code' => 'RAW.XETRA', 'close' => 56, 'currency' => 'EUR', 'timestamp' => now()->subMinutes(5)->timestamp];
    }
}
