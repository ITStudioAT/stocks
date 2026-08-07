<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        $this->createIntradayCandle($coveredHolding, '2025-06-26');
        $this->createIntradayCandle($coveredHolding, '2026-06-25');

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

    public function test_admin_repair_summary_uses_current_weekday_after_trading_day_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Europe/Vienna'));

        $coveredHolding = StockHolding::factory()->create([
            'isin' => 'US0378331005',
            'symbol' => 'AAPL',
        ]);
        $staleHolding = StockHolding::factory()->create([
            'isin' => 'US5949181045',
            'symbol' => 'MSFT',
        ]);

        $this->createIntradayCandle($coveredHolding, '2025-06-26');
        $this->createIntradayCandle($coveredHolding, '2026-06-26');
        $this->createIntradayCandle($staleHolding, '2025-06-26');
        $this->createIntradayCandle($staleHolding, '2026-06-25');

        $this->actingAs($this->adminUser())
            ->getJson('/admin/data/repair')
            ->assertOk()
            ->assertJsonPath('historical_data.minimum_date', '2025-06-26')
            ->assertJsonPath('historical_data.actual_minimum_date', '2025-06-26')
            ->assertJsonPath('historical_data.last_trading_day', '2026-06-26')
            ->assertJsonPath('historical_data.actual_last_trading_day', '2026-06-25')
            ->assertJsonPath('historical_data.total_stocks_count', 2)
            ->assertJsonPath('historical_data.covered_stocks_count', 1)
            ->assertJsonPath('historical_data.missing_stocks_count', 1)
            ->assertJsonPath('historical_data.missing_stocks.0.id', $staleHolding->id)
            ->assertJsonPath('historical_data.missing_stocks.0.db_minimum_date', '2025-06-26')
            ->assertJsonPath('historical_data.missing_stocks.0.db_last_trading_day', '2026-06-25')
            ->assertJsonPath('historical_data.missing_stocks.0.missing_ranges.0.from', '2026-06-26')
            ->assertJsonPath('historical_data.missing_stocks.0.missing_ranges.0.to', '2026-06-26');
    }

    public function test_guest_cannot_view_data_repair_summary(): void
    {
        $this->getJson('/admin/data/repair')->assertUnauthorized();
    }

    public function test_admin_can_reload_and_correct_an_already_stored_end_of_day_price(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'CEBS',
            'name' => 'iShares Copper Miners UCITS ETF EUR',
            'isin' => 'IE00063FT9K6',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        $instrumentKey = app(StockPriceCatalog::class)->instrumentKeyForHolding($holding);
        $date = '2026-08-06';
        StockPrice::factory()->create([
            'instrument_key' => $instrumentKey,
            'quote_hash' => hash('sha256', "{$instrumentKey}|eodhd_eod|{$date}|close"),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'CEBS',
            'isin' => 'IE00063FT9K6',
            'currency' => 'EUR',
            'price' => '9.84300000',
            'close' => '9.84300000',
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'freshness_status' => 'historical',
        ]);

        Http::fake([
            'eodhd.com/api/eod/CEBS.XETRA*' => Http::response([[
                'date' => $date,
                'open' => 9.85,
                'high' => 9.989,
                'low' => 9.73,
                'close' => 9.781,
                'adjusted_close' => 9.781,
                'volume' => 110877,
            ]]),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson("/admin/data/repair/end-of-day/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('stock.id', $holding->id)
            ->assertJsonPath('stored_prices_count', 1);

        $this->assertSame(1, StockPrice::query()->count());
        $this->assertDatabaseHas('stock_prices', [
            'quote_hash' => hash('sha256', "{$instrumentKey}|eodhd_eod|{$date}|close"),
            'price' => '9.78100000',
            'close' => '9.78100000',
        ]);
    }

    private function createIntradayCandle(StockHolding $holding, string $tradingDate): StockHoldingIntradayCandle
    {
        $asOf = Carbon::parse("{$tradingDate} 08:00:00", 'UTC');

        return StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'interval' => '5m',
            'as_of' => $asOf,
            'timestamp' => $asOf->timestamp,
            'datetime' => $asOf->toDateTimeString(),
            'close' => '123.45000000',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday',
        ]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
