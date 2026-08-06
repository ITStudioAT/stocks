<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\StockHistoricalPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStockHistoricalPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_missing_stock_history_with_the_unified_eodhd_service(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = $this->xetraHolding();

        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-25',
            'close' => '100.00000000',
            'adjusted_close' => '100.00000000',
            'currency' => 'EUR',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
        ]);

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([
                [
                    'date' => '2026-06-26',
                    'open' => 100.25,
                    'high' => 102.00,
                    'low' => 99.75,
                    'close' => 101.50,
                    'adjusted_close' => 101.50,
                    'volume' => 12345,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
            ->assertOk()
            ->assertJsonPath('message', 'EODHD historical sync: 1 record(s) created.')
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('stored_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('target_date', '2026-06-26')
            ->assertJsonPath('refresh', null);

        $this->assertDatabaseHas('stock_prices', [
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'AMES',
            'price' => '101.50000000',
            'close' => '101.50000000',
            'price_type' => 'historical_eod',
            'as_of' => '2026-06-26 21:59:59',
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
        $this->assertDatabaseHas('stock_holding_daily_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-26',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'close' => '101.50000000',
            'currency' => 'EUR',
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AMES.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26'
            && $request['period'] === 'd'
            && $request['fmt'] === 'json');
    }

    public function test_admin_stock_history_sync_skips_the_api_when_the_target_date_is_already_stored(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = $this->xetraHolding();

        $this->createHistoricalStockPrice($holding, '2026-06-26', '101.50000000');
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-26',
            'close' => '101.50000000',
            'adjusted_close' => '101.50000000',
            'currency' => 'EUR',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
        ]);
        Http::fake(fn () => throw new RuntimeException('Historical API should not be called.'));

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-prices/ensure')
            ->assertOk()
            ->assertJsonPath('requested_count', 0)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('target_date', '2026-06-26')
            ->assertJsonPath('refresh', null);

        Http::assertNothingSent();
    }

    public function test_admin_can_reload_stock_history_coverage_without_eodhd_api_access(): void
    {
        $this->travelTo(Carbon::parse('2026-06-27 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = $this->xetraHolding();
        $holding->update(['subtitle' => 'Accumulating share class']);

        $this->createHistoricalStockPrice($holding, '2026-06-25', '100.00000000');

        Http::fake(fn () => throw new RuntimeException('EODHD should not be called for coverage reload.'));

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings/historical-prices/coverage')
            ->assertOk()
            ->assertJsonPath('coverage.holdings.0.id', $holding->id)
            ->assertJsonPath('coverage.holdings.0.subtitle', 'Accumulating share class')
            ->assertJsonPath('coverage.holdings.0.end_of_day_last_date', '2026-06-25')
            ->assertJsonPath('refresh', null);

        Http::assertNothingSent();
    }

    public function test_guest_cannot_manage_stock_history(): void
    {
        $this->postJson('/admin/watchlist/holdings/historical-prices/ensure')->assertUnauthorized();
    }

    public function test_historical_price_coverage_reports_the_end_of_day_expected_last_date(): void
    {
        $this->travelTo(Carbon::parse('2026-06-26 18:00:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding();

        $this->createHistoricalStockPrice($holding, '2026-06-25', '100.00000000');

        $coverage = app(StockHistoricalPriceService::class)->coverage();

        $this->assertSame('2026-06-26', $coverage['holdings'][0]['end_of_day_expected_last_date']);
        $this->assertSame($holding->id, $coverage['end_of_day_outdated_stocks'][0]['id']);
        $this->assertSame('AMES - Amundi IBEX 35 UCITS ETF Acc', $coverage['end_of_day_outdated_stocks'][0]['label']);
        $this->assertSame('2026-06-25', $coverage['end_of_day_outdated_stocks'][0]['db_last_date']);
    }

    public function test_intraday_coverage_reports_the_oldest_last_date_across_all_holdings(): void
    {
        $this->travelTo(Carbon::parse('2026-06-26 18:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $selectedHolding = StockHolding::factory()->create(['name' => 'Selected Holding']);
        $laggingHolding = StockHolding::factory()->create([
            'symbol' => 'LAG',
            'name' => 'Lagging Holding',
        ]);

        $this->createIntradayCandle($selectedHolding, '2026-06-25');
        $this->createIntradayCandle($selectedHolding, '2026-06-26');
        $this->createIntradayCandle($laggingHolding, '2026-06-25');

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$selectedHolding->id}/intraday-candles/coverage")
            ->assertOk()
            ->assertJsonPath('coverage.last_date', '2026-06-26')
            ->assertJsonPath('coverage.expected_last_date', '2026-06-26')
            ->assertJsonPath('coverage.oldest_last_date', '2026-06-25')
            ->assertJsonPath('coverage.outdated_stocks.0.id', $laggingHolding->id)
            ->assertJsonPath('coverage.outdated_stocks.0.label', 'LAG - Lagging Holding')
            ->assertJsonPath('coverage.outdated_stocks.0.db_last_date', '2026-06-25')
            ->assertJsonPath('coverage.table_row_count', 3);
    }

    private function xetraHolding(): StockHolding
    {
        return StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
    }

    private function createHistoricalStockPrice(StockHolding $holding, string $date, string $price): StockPrice
    {
        return StockPrice::factory()->create([
            'instrument_key' => 'isin:'.$holding->isin,
            'quote_hash' => hash('sha256', "{$holding->isin}|{$date}|{$price}"),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'close' => $price,
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
    }

    private function createIntradayCandle(StockHolding $holding, string $tradingDate): StockHoldingIntradayCandle
    {
        return StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'interval' => '5m',
            'as_of' => Carbon::parse("{$tradingDate} 09:00:00", 'UTC'),
            'close' => '124.56000000',
            'currency' => 'USD',
            'source_key' => 'eodhd_intraday',
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
