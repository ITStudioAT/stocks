<?php

namespace Tests\Feature;

use App\Jobs\LoadStockChartIntradayData;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Services\ChartDataRefresh;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\FreeIntradayMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class FreeIntradayMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 22:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    public function test_automatic_sync_persists_actual_sparse_trades_and_real_us_ohlc_idempotently_without_eodhd(): void
    {
        $europe = $this->holding();
        $us = $this->holding(true);
        $this->providers();
        $service = app(FreeIntradayMarketData::class);
        $result = $service->syncAll();
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['stored_count']);
        $this->assertSame(0, $result['failed_count']);
        $trade = StockHoldingIntradayCandle::query()->where('stock_holding_id', $europe->id)->firstOrFail();
        $this->assertSame('trade', $trade->interval);
        $this->assertNull($trade->open);
        $this->assertNull($trade->high);
        $this->assertSame('56.10000000', $trade->close);
        $this->assertSame('XETB', $trade->raw_payload['mic']);
        $this->assertFalse($trade->raw_payload['coverage_complete']);
        $this->assertSame('2026-10-30T16:35:40.336012321Z', $trade->raw_payload['event_at']);
        $candle = StockHoldingIntradayCandle::query()->where('stock_holding_id', $us->id)->firstOrFail();
        $this->assertSame('5m', $candle->interval);
        $this->assertSame('yahoo_intraday', $candle->source_key);
        $this->assertSame('79.00000000', $candle->open);
        $this->assertSame('USD', $candle->currency);
        $this->assertSame('delayed', $us->fresh()->price_status);
        $service->syncAll();
        $this->assertDatabaseCount('stock_realtime_prices', 2);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 3);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_rate_limit_is_not_marked_successful_and_stops_same_provider_family(): void
    {
        $this->holding();
        StockHolding::factory()->create(['isin' => 'FR0010655746', 'symbol' => 'AMES', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        Http::fake(['https://mfs.deutsche-boerse.com/api/DETR-posttrade' => Http::response('', 429)]);
        $result = app(FreeIntradayMarketData::class)->syncAll();
        $this->assertSame(2, $result['failed_count']);
        $this->assertSame(0, $result['stored_count']);
        $this->assertNotEmpty($result['errors']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('stock_realtime_prices', 0);
    }

    public function test_out_of_retention_history_fails_without_network_or_deleting_existing_data(): void
    {
        $holding = $this->holding();
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-06-01',
            'interval' => '5m', 'as_of' => '2026-06-01 12:00:00', 'close' => 50, 'source_key' => 'eodhd_intraday']);
        try {
            app(FreeIntradayMarketData::class)->records($holding, now()->subYear(), now());
            $this->fail('Unrecoverable history must not be reported as complete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retention', $exception->getMessage());
        }
        $this->assertDatabaseCount('stock_holding_intraday_candles', 1);
        Http::assertNothingSent();
    }

    public function test_empty_window_does_not_fabricate_a_candle(): void
    {
        $holding = $this->holding();
        $this->providers();
        $this->expectException(RuntimeException::class);
        app(FreeIntradayMarketData::class)->records($holding, Carbon::parse('2026-10-30 09:00', 'UTC'), Carbon::parse('2026-10-30 10:00', 'UTC'));
    }

    public function test_us_ohlc_cannot_overwrite_legacy_source_at_the_same_unique_timestamp(): void
    {
        $holding = $this->holding(true);
        $this->providers();
        $records = app(FreeIntradayMarketData::class)->records($holding, now()->startOfDay(), now());
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-10-30',
            'interval' => '5m', 'as_of' => Carbon::createFromTimestampUTC($records[0]['timestamp']), 'close' => 100, 'source_key' => 'eodhd_intraday']);
        app(FreeIntradayMarketData::class)->persist($holding, $records);
        $this->assertSame('100.00000000', StockHoldingIntradayCandle::query()->where('source_key', 'eodhd_intraday')->firstOrFail()->close);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 2);
    }

    public function test_trade_samples_do_not_merge_different_mics_or_replace_newer_nanosecond_events(): void
    {
        $holding = $this->holding();
        $this->providers();
        $service = app(FreeIntradayMarketData::class);
        $records = $service->records($holding, now()->startOfDay(), now());
        $service->persist($holding, $records);
        $otherVenue = [...$records[0], 'mic' => 'XETA', 'close' => '99'];
        $this->assertSame(0, $service->persist($holding, [$otherVenue]));
        $older = [...$records[0], 'event_at' => '2026-10-30T16:35:40.000000001Z', 'close' => '98'];
        $this->assertSame(0, $service->persist($holding, [$older]));
        $this->assertSame('56.10000000', StockHoldingIntradayCandle::query()->firstOrFail()->close);
    }

    public function test_background_refresh_marks_rate_limit_failure_and_chart_jobs_need_no_eodhd_key(): void
    {
        $holding = $this->holding();
        config(['services.eodhd.key' => null]);
        Http::fake(['https://mfs.deutsche-boerse.com/api/DETR-posttrade' => Http::response('', 429, ['Retry-After' => '600'])]);
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('free-rate-limit', 1);
        app()->call([new RefreshDepotHoldingPrices('free-rate-limit'), 'handle']);
        $this->assertSame('failed', $progress->get('free-rate-limit')['status']);
        Queue::fake();
        $state = app(ChartDataRefresh::class)->request($holding, 'samples');
        $this->assertSame('queued', $state['status']);
        Queue::assertPushed(LoadStockChartIntradayData::class);
        Http::assertSentCount(1);
        $this->travel(301)->seconds();
        $result = app(FreeIntradayMarketData::class)->syncAll();
        $this->assertSame(1, $result['failed_count']);
        Http::assertSentCount(1);
    }

    private function holding(bool $us = false): StockHolding
    {
        return StockHolding::factory()->create(['isin' => $us ? 'US37950E2596' : 'AT0000606306',
            'symbol' => $us ? 'ARGT' : 'RAW', 'exchange' => $us ? 'US' : 'XETRA', 'mic_code' => $us ? null : 'XETR',
            'currency' => $us ? 'USD' : 'EUR', 'trading_times' => $us ? 'Monday-Friday 09:30-16:00 America/New_York' : 'Monday-Friday 09:00-17:30 Europe/Berlin']);
    }

    private function providers(): void
    {
        $file = 'DETR-posttrade-2026-10-30T16_35.json.gz';
        Http::fake([
            'https://mfs.deutsche-boerse.com/api/DETR-posttrade' => Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => [$file]]),
            'https://mfs.deutsche-boerse.com/api/download/'.$file => Http::response(gzencode(json_encode([
                'messageId' => 'posttrade', 'instrumentIdentificationCode' => 'AT0000606306', 'priceCurrency' => 'EUR',
                'price' => 56.1, 'priceNotation' => 1, 'venueOfExecution' => 'XETB', 'tradingDateAndTime' => '2026-10-30T16:35:40.336012321Z',
            ]))),
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [[
                'meta' => ['symbol' => 'ARGT', 'currency' => 'USD', 'exchangeName' => 'PCX', 'fullExchangeName' => 'NYSEArca',
                    'exchangeTimezoneName' => 'America/New_York', 'instrumentType' => 'ETF', 'regularMarketPrice' => 79.18, 'regularMarketTime' => now()->subHours(2)->timestamp, 'dataGranularity' => '5m'],
                'timestamp' => [Carbon::parse('2026-10-30 14:30', 'UTC')->timestamp, Carbon::parse('2026-10-30 14:35', 'UTC')->timestamp],
                'indicators' => ['quote' => [['open' => [79, 79.1], 'high' => [80, 80], 'low' => [78, 78], 'close' => [79.1, 79.18], 'volume' => [10, 20]]]],
            ]]]]),
        ]);
    }
}
