<?php

namespace Tests\Feature;

use App\Jobs\FetchStockHistoricalPrices;
use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\User;
use App\Services\StockHistoricalDailyPriceFetcher;
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
            StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'country' => 'United States',
                'currency' => 'USD',
            ]);

            $response = $this->actingAs($admin)
                ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
                ->assertAccepted()
                ->assertJsonPath('coverage.total_count', 1)
                ->assertJsonPath('coverage.available_count', 0)
                ->assertJsonPath('coverage.missing_count', 1)
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

            (new FetchStockHistoricalPrices($run->id))->handle(app(StockHistoricalDailyPriceFetcher::class));

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
            $this->assertSame('finished', $run->refresh()->status);
            $this->assertSame(1, $run->processed_count);
            $this->assertSame(1, $run->success_count);

            $this->actingAs($admin)
                ->getJson("/admin/watchlist/holdings/historical-prices/{$run->id}")
                ->assertOk()
                ->assertJsonPath('coverage.is_complete', true)
                ->assertJsonPath('refresh.status', 'finished')
                ->assertJsonPath('refresh.processed', 1);

            Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/eod/AAPL.US'));
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
}
