<?php

namespace Tests\Feature;

use App\Jobs\FetchStockHistoricalPrices;
use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\StockHistoricalDailyPriceFetcher;
use App\Services\StockHistoricalPriceService;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStockHistoricalPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_missing_one_year_stock_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));
        Queue::fake([FetchStockHistoricalPrices::class]);

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
            ]);
            StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '190.00000000',
                'as_of' => Carbon::parse('2026-06-04 07:30:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-04 07:30:00', 'UTC'),
            ]);
            StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '191.00000000',
                'as_of' => Carbon::parse('2026-06-04 15:30:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-04 15:30:00', 'UTC'),
            ]);
            StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '192.00000000',
                'as_of' => Carbon::parse('2026-06-05 08:00:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-05 08:00:00', 'UTC'),
            ]);
            StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '193.00000000',
                'as_of' => Carbon::parse('2026-06-05 12:00:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-05 12:00:00', 'UTC'),
            ]);
            $instrumentKey = app(StockPriceCatalog::class)->instrumentKeyForHolding($holding);
            StockPrice::factory()->create([
                'instrument_key' => $instrumentKey,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '188.00000000',
                'as_of' => Carbon::parse('2026-06-03 20:00:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-03 20:00:00', 'UTC'),
            ]);
            StockPrice::factory()->create([
                'instrument_key' => $instrumentKey,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '188.50000000',
                'as_of' => Carbon::parse('2026-06-04 12:00:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-04 12:00:00', 'UTC'),
            ]);
            StockPrice::factory()->create([
                'instrument_key' => $instrumentKey,
                'symbol' => 'AAPL',
                'currency' => 'USD',
                'price' => '189.00000000',
                'as_of' => Carbon::parse('2026-06-04 20:00:00', 'UTC'),
                'fetched_at' => Carbon::parse('2026-06-04 20:00:00', 'UTC'),
            ]);

            $response = $this->actingAs($admin)
                ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
                ->assertAccepted()
                ->assertJsonPath('coverage.total_count', 1)
                ->assertJsonPath('coverage.available_count', 0)
                ->assertJsonPath('coverage.missing_count', 1)
                ->assertJsonPath('coverage.holdings.0.latest_realtime_day_record_count', 2)
                ->assertJsonPath('coverage.holdings.0.previous_realtime_day_record_count', 2)
                ->assertJsonPath('coverage.holdings.0.previous_realtime_date', '2026-06-04T17:30:00+02:00')
                ->assertJsonPath('coverage.holdings.0.previous_realtime_day_first_record_at', '2026-06-04T09:30:00+02:00')
                ->assertJsonPath('coverage.holdings.0.previous_realtime_day_last_record_at', '2026-06-04T17:30:00+02:00')
                ->assertJsonPath('coverage.holdings.0.latest_realtime_table_row_count', 4)
                ->assertJsonPath('coverage.holdings.0.end_of_day_first_date', '2026-06-03')
                ->assertJsonPath('coverage.holdings.0.end_of_day_last_date', '2026-06-04')
                ->assertJsonPath('coverage.holdings.0.end_of_day_row_count', 2)
                ->assertJsonPath('coverage.holdings.0.end_of_day_table_row_count', 3)
                ->assertJsonPath('refresh.status', 'queued')
                ->assertJsonPath('refresh.total', 1);

            $refreshId = $response->json('refresh.refresh_id');

            $this->assertDatabaseHas('stock_historical_price_fetch_runs', [
                'id' => $refreshId,
                'status' => 'queued',
                'total_count' => 1,
            ]);
            $item = StockHistoricalPriceFetchItem::query()->where('fetch_run_id', $refreshId)->firstOrFail();

            $this->assertSame('queued', $item->status);
            $this->assertSame('2025-06-05', $item->date_from->toDateString());
            $this->assertSame('2026-06-05', $item->date_to->toDateString());
            Queue::assertPushed(FetchStockHistoricalPrices::class, fn (FetchStockHistoricalPrices $job): bool => $job->refreshId === $refreshId);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_does_not_queue_when_one_year_stock_history_is_complete(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));
        Queue::fake([FetchStockHistoricalPrices::class]);

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
            ]);

            $this->createWeekdayDailyPrices($holding, '2025-06-05', '2026-06-04');

            $this->actingAs($admin)
                ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
                ->assertOk()
                ->assertJsonPath('coverage.total_count', 1)
                ->assertJsonPath('coverage.available_count', 1)
                ->assertJsonPath('coverage.missing_count', 0)
                ->assertJsonPath('coverage.is_complete', true)
                ->assertJsonPath('refresh', null);

            Queue::assertNotPushed(FetchStockHistoricalPrices::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_queues_when_a_required_stock_history_day_is_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));
        Queue::fake([FetchStockHistoricalPrices::class]);

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
            ]);

            $this->createWeekdayDailyPrices($holding, '2025-06-05', '2026-06-04', '2026-01-15');

            $this->actingAs($admin)
                ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
                ->assertAccepted()
                ->assertJsonPath('coverage.total_count', 1)
                ->assertJsonPath('coverage.available_count', 0)
                ->assertJsonPath('coverage.missing_count', 1)
                ->assertJsonPath('refresh.status', 'queued');

            Queue::assertPushed(FetchStockHistoricalPrices::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queued_job_fetches_and_stores_daily_stock_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));

        try {
            config(['services.eodhd.key' => 'test-token']);
            Http::fake([
                'eodhd.com/api/eod/AAPL.US*' => Http::response([
                    [
                        'date' => '2025-06-05',
                        'open' => 190.1,
                        'high' => 193.4,
                        'low' => 189.9,
                        'close' => 192.7,
                        'adjusted_close' => 192.7,
                        'volume' => 120000,
                    ],
                    [
                        'date' => '2026-06-04',
                        'open' => 210.5,
                        'high' => 214.2,
                        'low' => 209.8,
                        'close' => 213.1,
                        'adjusted_close' => 213.1,
                        'volume' => 180000,
                    ],
                ]),
            ]);
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
                'exchange' => 'NASDAQ',
                'mic_code' => 'XNAS',
            ]);
            $run = StockHistoricalPriceFetchRun::query()->create([
                'id' => 'history-test',
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
                'total_count' => 1,
            ]);
            StockHistoricalPriceFetchItem::query()->create([
                'fetch_run_id' => $run->id,
                'stock_holding_id' => $holding->id,
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
            ]);

            (new FetchStockHistoricalPrices($run->id))->handle(
                app(StockHistoricalDailyPriceFetcher::class),
                app(StockHistoricalPriceService::class),
            );

            $this->assertDatabaseHas('stock_holding_daily_prices', [
                'stock_holding_id' => $holding->id,
                'trading_date' => '2025-06-05',
                'close' => '192.70000000',
                'currency' => 'USD',
            ]);
            $this->assertDatabaseHas('stock_holding_daily_prices', [
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-04',
                'close' => '213.10000000',
            ]);
            $this->assertSame(2, StockHoldingDailyPrice::query()->where('stock_holding_id', $holding->id)->count());
            $this->assertSame('partial', $run->refresh()->status);
            $this->assertSame(1, $run->processed_count);
            $this->assertSame(0, $run->success_count);
            $this->assertSame(1, $run->unavailable_count);

            $this->actingAs($admin)
                ->getJson("/admin/watchlist/holdings/historical-prices/{$run->id}")
                ->assertOk()
                ->assertJsonPath('coverage.is_complete', false)
                ->assertJsonPath('coverage.missing_count', 1)
                ->assertJsonPath('refresh.status', 'partial')
                ->assertJsonPath('refresh.processed', 1)
                ->assertJsonPath('refresh.stored_count', 2);

            Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/eod/AAPL.US'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queued_job_does_not_call_api_when_database_days_are_complete(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));

        try {
            config(['services.eodhd.key' => 'test-token']);
            Http::fake(fn () => throw new \RuntimeException('Historical API should not be called when daily prices are complete.'));
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
                'exchange' => 'NASDAQ',
                'mic_code' => 'XNAS',
            ]);
            $this->createWeekdayDailyPrices($holding, '2025-06-05', '2026-06-04');
            $run = StockHistoricalPriceFetchRun::query()->create([
                'id' => 'history-complete-test',
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
                'total_count' => 1,
            ]);
            StockHistoricalPriceFetchItem::query()->create([
                'fetch_run_id' => $run->id,
                'stock_holding_id' => $holding->id,
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
            ]);

            (new FetchStockHistoricalPrices($run->id))->handle(
                app(StockHistoricalDailyPriceFetcher::class),
                app(StockHistoricalPriceService::class),
            );

            Http::assertSentCount(0);
            $this->assertSame('finished', $run->refresh()->status);
            $this->assertSame(1, $run->processed_count);
            $this->assertSame(1, $run->success_count);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queued_job_fetches_only_missing_database_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));

        try {
            config(['services.eodhd.key' => 'test-token']);
            Http::fake([
                'eodhd.com/api/eod/AAPL.US*' => Http::response([
                    [
                        'date' => '2026-01-15',
                        'open' => 200.1,
                        'high' => 203.4,
                        'low' => 199.9,
                        'close' => 202.7,
                        'adjusted_close' => 202.7,
                        'volume' => 120000,
                    ],
                ]),
            ]);
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
                'exchange' => 'NASDAQ',
                'mic_code' => 'XNAS',
            ]);
            $this->createWeekdayDailyPrices($holding, '2025-06-05', '2026-06-04', '2026-01-15');
            $run = StockHistoricalPriceFetchRun::query()->create([
                'id' => 'history-missing-day-test',
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
                'total_count' => 1,
            ]);
            StockHistoricalPriceFetchItem::query()->create([
                'fetch_run_id' => $run->id,
                'stock_holding_id' => $holding->id,
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
            ]);

            (new FetchStockHistoricalPrices($run->id))->handle(
                app(StockHistoricalDailyPriceFetcher::class),
                app(StockHistoricalPriceService::class),
            );

            Http::assertSent(fn ($request): bool => str_contains($request->url(), 'from=2026-01-15')
                && str_contains($request->url(), 'to=2026-01-15'));
            Http::assertSentCount(1);
            $this->assertDatabaseHas('stock_holding_daily_prices', [
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-01-15',
                'close' => '202.70000000',
            ]);
            $this->assertSame('finished', $run->refresh()->status);
            $this->assertSame(1, $run->success_count);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queued_job_fetches_fragmented_missing_database_days_as_one_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));

        try {
            config(['services.eodhd.key' => 'test-token']);
            Http::fake([
                'eodhd.com/api/eod/AAPL.US*' => Http::response([
                    [
                        'date' => '2026-01-15',
                        'open' => 200.1,
                        'high' => 203.4,
                        'low' => 199.9,
                        'close' => 202.7,
                        'adjusted_close' => 202.7,
                        'volume' => 120000,
                    ],
                    [
                        'date' => '2026-02-16',
                        'open' => 204.1,
                        'high' => 207.4,
                        'low' => 203.9,
                        'close' => 206.7,
                        'adjusted_close' => 206.7,
                        'volume' => 130000,
                    ],
                ]),
            ]);
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
                'exchange' => 'NASDAQ',
                'mic_code' => 'XNAS',
            ]);
            $this->createWeekdayDailyPrices($holding, '2025-06-05', '2026-06-04');
            StockHoldingDailyPrice::query()
                ->where('stock_holding_id', $holding->id)
                ->where(function ($query): void {
                    $query
                        ->whereDate('trading_date', '2026-01-15')
                        ->orWhereDate('trading_date', '2026-02-16');
                })
                ->delete();
            $run = StockHistoricalPriceFetchRun::query()->create([
                'id' => 'history-fragmented-days-test',
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
                'total_count' => 1,
            ]);
            StockHistoricalPriceFetchItem::query()->create([
                'fetch_run_id' => $run->id,
                'stock_holding_id' => $holding->id,
                'status' => 'queued',
                'date_from' => '2025-06-05',
                'date_to' => '2026-06-05',
            ]);

            (new FetchStockHistoricalPrices($run->id))->handle(
                app(StockHistoricalDailyPriceFetcher::class),
                app(StockHistoricalPriceService::class),
            );

            Http::assertSent(fn ($request): bool => str_contains($request->url(), 'from=2026-01-15')
                && str_contains($request->url(), 'to=2026-02-16'));
            Http::assertSentCount(1);
            $this->assertDatabaseHas('stock_holding_daily_prices', [
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-01-15',
                'close' => '202.70000000',
            ]);
            $this->assertDatabaseHas('stock_holding_daily_prices', [
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-02-16',
                'close' => '206.70000000',
            ]);
            $this->assertSame('finished', $run->refresh()->status);
            $this->assertSame(1, $run->success_count);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_cannot_manage_stock_history(): void
    {
        $this->postJson('/admin/watchlist/holdings/historical-prices/ensure')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/historical-prices/example')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function createWeekdayDailyPrices(StockHolding $holding, string $from, string $to, ?string $skipDate = null): void
    {
        $date = Carbon::parse($from);
        $endDate = Carbon::parse($to);

        while ($date->lte($endDate)) {
            if ($date->isWeekday() && $date->toDateString() !== $skipDate) {
                StockHoldingDailyPrice::factory()->create([
                    'stock_holding_id' => $holding->id,
                    'trading_date' => $date->toDateString(),
                    'close' => '192.70000000',
                    'adjusted_close' => '192.70000000',
                    'currency' => $holding->currency,
                ]);
            }

            $date->addDay();
        }
    }
}
