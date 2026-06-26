<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_count_stocks_missing_one_year_of_end_of_day_prices(): void
    {
        Carbon::setTestNow('2026-06-26 12:00:00');

        $coveredHolding = StockHolding::factory()->create([
            'isin' => 'US0378331005',
            'symbol' => 'AAPL',
        ]);
        $recentOnlyHolding = StockHolding::factory()->create([
            'isin' => 'US5949181045',
            'symbol' => 'MSFT',
        ]);
        StockHolding::factory()->create([
            'isin' => 'US67066G1040',
            'symbol' => 'NVDA',
        ]);

        $stockPriceCatalog = app(StockPriceCatalog::class);
        StockPrice::factory()->create([
            'instrument_key' => $stockPriceCatalog->instrumentKeyForHolding($coveredHolding),
            'price' => '123.45000000',
            'as_of' => '2025-06-26 16:00:00',
            'fetched_at' => '2025-06-26 16:00:00',
        ]);
        StockPrice::factory()->create([
            'instrument_key' => $stockPriceCatalog->instrumentKeyForHolding($recentOnlyHolding),
            'price' => '456.78000000',
            'as_of' => '2026-01-15 16:00:00',
            'fetched_at' => '2026-01-15 16:00:00',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $coveredHolding->id,
            'trading_date' => '2025-06-26',
            'interval' => '5m',
            'as_of' => '2025-06-26 08:00:00',
            'timestamp' => 1750924800,
            'datetime' => '2025-06-26 08:00:00',
            'close' => '123.45000000',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $coveredHolding->id,
            'trading_date' => '2026-06-25',
            'interval' => '5m',
            'as_of' => '2026-06-25 08:00:00',
            'timestamp' => 1782374400,
            'datetime' => '2026-06-25 08:00:00',
            'close' => '124.45000000',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday',
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/data/repair')
            ->assertOk()
            ->assertJsonPath('end_of_day.minimum_date', '2025-06-26')
            ->assertJsonPath('end_of_day.actual_date', '2025-06-26')
            ->assertJsonPath('end_of_day.total_stocks_count', 3)
            ->assertJsonPath('end_of_day.covered_stocks_count', 1)
            ->assertJsonPath('end_of_day.missing_stocks_count', 2)
            ->assertJsonPath('historical_data.minimum_date', '2025-06-26')
            ->assertJsonPath('historical_data.actual_minimum_date', '2025-06-26')
            ->assertJsonPath('historical_data.last_trading_day', '2026-06-25')
            ->assertJsonPath('historical_data.actual_last_trading_day', '2026-06-25')
            ->assertJsonPath('historical_data.total_stocks_count', 3)
            ->assertJsonPath('historical_data.covered_stocks_count', 1)
            ->assertJsonPath('historical_data.missing_stocks_count', 2);
    }

    public function test_guest_cannot_view_data_repair_summary(): void
    {
        $this->getJson('/admin/data/repair')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
