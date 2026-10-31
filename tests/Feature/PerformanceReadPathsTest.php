<?php

namespace Tests\Feature;

use App\Jobs\LoadIndexChartPrices;
use App\Jobs\LoadStockChartIntradayData;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\IndexWatchItemRealtimePrice;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\ChartDataRefresh;
use App\Services\StockHistoricalPriceService;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PerformanceReadPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_query_count_does_not_grow_per_holding_and_prices_remain_live(): void
    {
        $this->travelTo(Carbon::parse('2026-06-05 14:00:00', 'Europe/Vienna'));
        $this->signIn();
        $holding = $this->holdingWithPrices();
        $path = '/admin/watchlist/holdings?all=1';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonPath('holdings.0.latest_price', '102.000000');
        $singleCount = count(DB::getQueryLog());

        foreach (range(1, 5) as $index) {
            $this->holdingWithPrices();
        }
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(6, 'holdings');
        $batchCount = count(DB::getQueryLog());
        $this->assertLessThanOrEqual($singleCount + 2, $batchCount, "Query count grew from {$singleCount} to {$batchCount}.");

        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 105, 'as_of' => '2026-06-05 13:00:00']);
        $prices = collect($this->getJson($path)->assertOk()->json('holdings'))->keyBy('id');
        $this->assertSame('105.000000', $prices[$holding->id]['latest_price']);
        DB::disableQueryLog();
    }

    public function test_index_list_uses_a_fixed_number_of_price_queries(): void
    {
        $this->signIn();
        foreach (range(1, 5) as $index) {
            $item = IndexWatchItem::factory()->create();
            IndexWatchItemPrice::query()->create(['index_watch_item_id' => $item->id, 'trading_date' => '2026-06-04', 'actual_price' => 100]);
            IndexWatchItemRealtimePrice::query()->create(['index_watch_item_id' => $item->id, 'trading_date' => '2026-06-05', 'price' => 101, 'as_of' => '2026-06-05 10:00:00']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/admin/index-watch-items')->assertOk()->assertJsonCount(5, 'indexes');
        $priceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'index_watch_item_prices') || str_contains($query['query'], 'index_watch_item_realtime_prices'));
        $this->assertCount(3, $priceQueries);
        DB::disableQueryLog();
    }

    public function test_shared_instrument_fallbacks_keep_each_holdings_own_date_cutoff(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-06-05 14:00:00', 'Europe/Vienna'));
        $first = StockHolding::factory()->create(['isin' => null, 'wkn' => null, 'symbol' => 'SHARED']);
        $second = StockHolding::factory()->create(['isin' => null, 'wkn' => null, 'symbol' => 'SHARED']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $first->id, 'as_of' => '2026-06-05 12:00:00']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $second->id, 'as_of' => '2026-06-04 12:00:00']);
        foreach ([3, 4] as $day) {
            StockPrice::factory()->create([
                'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($first),
                'source_key' => 'stored_quote', 'price_type' => 'last', 'price' => 100 + $day,
                'as_of' => "2026-06-0{$day} 12:00:00",
            ]);
        }
        $holdings = collect($this->getJson('/admin/watchlist/holdings?all=1')->assertOk()->json('holdings'))->keyBy('id');
        $this->assertSame('104.000000', $holdings[$first->id]['end_price_24']);
        $this->assertSame('103.000000', $holdings[$second->id]['end_price_24']);
    }

    public function test_lightweight_end_of_day_status_matches_detailed_coverage_at_day_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-06-05 14:00:00', 'Europe/Vienna'));
        $service = app(StockHistoricalPriceService::class);
        $this->assertFalse($service->hasOutdatedEndOfDayStocks());
        $holding = StockHolding::factory()->create();
        $this->assertTrue($service->hasOutdatedEndOfDayStocks());
        foreach (['2026-06-04 21:59:59', '2026-06-04 22:00:00', '2026-06-05 00:00:00'] as $timestamp) {
            StockPrice::factory()->create([
                'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
                'as_of' => $timestamp, 'price' => 100,
            ]);
            $this->assertSame($service->coverage()['end_of_day_outdated_stocks'] !== [], $service->hasOutdatedEndOfDayStocks(), $timestamp);
        }
    }

    public function test_intraday_snapshot_queries_do_not_grow_per_holding(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Vienna'));
        $createHolding = function (): void {
            $holding = StockHolding::factory()->create();
            foreach ([10, 11] as $hour) {
                StockHoldingIntradayCandle::query()->create([
                    'stock_holding_id' => $holding->id, 'trading_date' => '2026-06-05',
                    'interval' => '5m', 'source_key' => 'eodhd_intraday', 'close' => 100 + $hour,
                    'as_of' => "2026-06-05 {$hour}:00:00",
                ]);
            }
        };
        $createHolding();
        $path = '/admin/watchlist/holdings?all=1';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonPath('holdings.0.latest_price', '111.000000');
        $singleCount = count(DB::getQueryLog());
        foreach (range(1, 5) as $index) {
            $createHolding();
        }
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(6, 'holdings');
        $this->assertLessThanOrEqual($singleCount + 2, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_index_chart_refresh_is_queued_once_and_reports_worker_failure(): void
    {
        $this->signIn();
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        Http::fake();
        $item = IndexWatchItem::factory()->create();
        $path = "/admin/index-watch-items/{$item->id}/prices/ensure?range=intraday";
        $this->postJson($path)->assertOk()->assertJsonPath('refresh.status', 'queued');
        $this->postJson($path)->assertOk()->assertJsonPath('refresh.status', 'queued');
        Queue::assertPushed(LoadIndexChartPrices::class, 1);
        Http::assertNothingSent();
        $job = Queue::pushed(LoadIndexChartPrices::class)->first();
        $job->failed(new \RuntimeException('Provider unavailable'));
        $this->getJson("/admin/index-watch-items/{$item->id}/prices/refresh?range=intraday")
            ->assertStatus(422)->assertJsonPath('refresh.status', 'failed')->assertJsonPath('refresh.error', 'Provider unavailable');
        Http::assertNothingSent();
    }

    public function test_only_global_range_statistics_are_cached_for_at_most_five_seconds(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-06-05 14:00:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create();
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-06-03', 'interval' => '5m', 'source_key' => 'eodhd_intraday', 'as_of' => '2026-06-03 10:00:00', 'close' => 100]);
        $path = '/admin/data/stocks/intraday-data/date-range';
        $this->getJson($path)->assertOk()->assertJsonPath('range.row_count', 1);
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-06-04', 'interval' => '5m', 'source_key' => 'eodhd_intraday', 'as_of' => '2026-06-04 10:00:00', 'close' => 100]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonPath('range.row_count', 1);
        $this->assertEmpty(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'MIN(trading_date)')));
        $this->getJson("/admin/data/stocks/{$holding->id}/intraday-data/date-range")->assertOk()->assertJsonPath('range.row_count', 2);
        $this->travel(5)->seconds();
        $this->getJson($path)->assertOk()->assertJsonPath('range.row_count', 2)->assertJsonPath('range.date_to', '2026-06-04');
        DB::disableQueryLog();
    }

    public function test_missing_chart_samples_are_queued_once_without_blocking_or_external_calls(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        Http::fake();
        $holding = StockHolding::factory()->create(['trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna']);
        $path = "/admin/watchlist/holdings/charts?include_charts=1&chart_stock_id={$holding->id}";
        $this->getJson('/admin/watchlist/holdings?include_charts=1')->assertOk();
        Queue::assertNothingPushed();
        $this->postJson($path)->assertOk()->assertJsonPath('holdings.0.historical_prices_fetching', true)
            ->assertJsonPath('holdings.0.intraday_refresh.status', 'queued');
        $this->postJson($path)->assertOk();
        Queue::assertPushed(LoadStockChartIntradayData::class, 1);
        Http::assertNothingSent();
        $this->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-refresh/samples")
            ->assertOk()->assertJsonPath('refresh.status', 'queued');
        $job = Queue::pushed(LoadStockChartIntradayData::class)->first();
        $this->assertSame('database', $job->connection);
        app(ChartDataRefresh::class)->finish($job->refreshKey, 'Failed upstream');
        $this->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-refresh/samples")
            ->assertOk()->assertJsonPath('refresh.status', 'failed');
        $this->postJson($path)->assertOk()->assertJsonPath('holdings.0.historical_prices_fetching', false);
        Queue::assertPushed(LoadStockChartIntradayData::class, 1);
    }

    public function test_intraday_date_filter_keeps_boundaries_without_sql_date_functions(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create();
        foreach (['2026-06-03', '2026-06-04'] as $date) {
            StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => $date, 'interval' => '5m', 'source_key' => 'eodhd_intraday', 'close' => 100, 'as_of' => $date.' 10:00:00']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/admin/watchlist/holdings?all=1')->assertOk()->assertJsonPath('holdings.0.start_price_date', '2026-06-04');
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:date|strftime)\([^)]*trading_date/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($user);
    }

    private function holdingWithPrices(): StockHolding
    {
        $holding = StockHolding::factory()->create(['trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 102, 'as_of' => '2026-06-05 11:00:00']);
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-06-04', 'close' => 101]);
        StockPrice::factory()->create(['instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding), 'source_key' => 'eodhd_eod', 'price_type' => 'historical_eod', 'price' => 101, 'as_of' => '2026-06-04 19:00:00']);

        return $holding;
    }
}
