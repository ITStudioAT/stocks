<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\StockPriceCatalog;
use App\Services\WatchlistPdfReport;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketDataPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(Carbon::parse('2026-11-04 10:00', 'UTC'));
        Http::preventStrayRequests();
    }

    public function test_api_keeps_free_current_price_and_eodhd_history_separate(): void
    {
        $holding = $this->mixedHolding();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $response = $this->actingAs($admin)->getJson('/admin/watchlist/holdings?include_charts=1&all_chart_holdings=1&all=1');
        $response->assertOk()->assertJsonPath('holdings.0.latest_price', '56.000000')
            ->assertJsonPath('holdings.0.latest_price_source_markers', ['free'])
            ->assertJsonPath('holdings.0.end_price_24_source_markers', ['free'])
            ->assertJsonPath('holdings.0.daily_prices.0.source_markers', ['EODHD'])
            ->assertJsonPath('holdings.0.data_source_markers', ['free', 'EODHD']);
        $this->assertSame('yahoo_xetra_intraday', $holding->fresh()->latestRealtimePrice->source_key);
        $this->getJson("/admin/watchlist/holdings/{$holding->id}/realtime-prices/latest")->assertOk()
            ->assertJsonPath('entries.0.source_markers', ['free']);
        $this->getJson("/admin/watchlist/holdings/{$holding->id}/end-of-day-prices/latest-days")->assertOk()
            ->assertJsonPath('entries.0.source_markers', ['EODHD']);
        $this->getJson("/admin/data/stocks/{$holding->id}/live-data/date-range")->assertOk()
            ->assertJsonPath('range.source_markers', ['free']);
        $this->getJson("/admin/data/stocks/{$holding->id}/eod-data/date-range")->assertOk()
            ->assertJsonPath('range.source_markers', ['EODHD']);
        Http::assertNothingSent();
    }

    public function test_pdf_uses_the_displayed_quote_origin_and_historical_comparison_origin(): void
    {
        $this->mixedHolding();
        $pdf = app(WatchlistPdfReport::class)->pdf(now());
        $document = $pdf->getDomPDF()->getDom();
        $this->assertStringContainsString('not official closes', $document->textContent);
        $xpath = new DOMXPath($document);
        $current = $xpath->query('//*[contains(@class,"price-value")]')->item(0)->textContent;
        $this->assertStringContainsString('56', $current);
        $this->assertStringContainsString('free', $current);
        $this->assertStringNotContainsString('999', $current);
        $end24 = $xpath->query('//*[contains(@class,"fact-cell")][span[text()="End 24"]]')->item(0)->textContent;
        $this->assertStringContainsString('53.5', $end24);
        $this->assertStringContainsString('free', $end24);
        $this->assertStringNotContainsString('EODHD', $end24);
        $this->assertStringStartsWith('%PDF', $pdf->output());
        Http::assertNothingSent();
    }

    public function test_depot_positions_totals_and_chart_points_use_their_actual_inputs(): void
    {
        $holding = $this->mixedHolding();
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 1000]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id, 'pieces' => 2,
            'total_amount' => 100, 'cash_delta' => -100, 'booked_at' => '2025-12-01 12:00:00']);
        $holding->update(['flatex_price' => 57]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $response = $this->actingAs($admin)->getJson('/admin/depot-transactions');
        $response->assertOk()->assertJsonPath('depot_holdings.0.latest_price_source_markers', ['free'])
            ->assertJsonPath('depot_holdings.0.previous_day_price_source_markers', ['free'])
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent_source_markers', ['free'])
            ->assertJsonPath('depot_valuations.latest.stock_balance_source_markers', ['free'])
            ->assertJsonPath('depot_valuations.latest.previous_day_balance_source_markers', ['free'])
            ->assertJsonPath('depot_valuations.latest.previous_day_change_amount_source_markers', ['free'])
            ->assertJsonPath('depot_valuations.flatex.stock_balance_source_markers', [])
            ->assertJsonPath('depot_valuations.flatex.previous_day_change_amount_source_markers', ['free']);
        $this->assertArrayNotHasKey('year_start_price_source_markers', $response->json('depot_holdings.0'));
        $this->assertArrayNotHasKey('cash_balance_source_markers', $response->json('depot_valuations.latest'));
        $points = collect($response->json('depot_performance_series'))->keyBy('date');
        $this->assertSame(['free'], $points['2026-11-03']['source_markers']);
        $this->assertSame(['free'], $points['2026-11-04']['source_markers']);
        $performance = $this->getJson('/admin/dashboard/performance')->assertOk();
        $today = collect($performance->json('days'))->firstWhere('date', '2026-11-04');
        $this->assertSame(['free'], $today['change_amount_source_markers']);
        Http::assertNothingSent();
    }

    public function test_cash_only_totals_and_manual_transactions_have_no_provider_marker(): void
    {
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 1000]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null, 'type' => 'deposit',
            'pieces' => null, 'total_amount' => 1000, 'cash_delta' => 1000, 'booked_at' => now()]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $response = $this->actingAs($admin)->getJson('/admin/depot-transactions')->assertOk();
        $this->assertSame([], $response->json('depot_valuations.latest.stock_balance_source_markers'));
        $this->assertSame([], $response->json('depot_valuations.latest.current_balance_source_markers'));
        $this->assertArrayNotHasKey('source_markers', $response->json('transactions.0'));
        $this->assertArrayNotHasKey('data_source_markers', $response->json('transactions.0'));
        Http::assertNothingSent();
    }

    public function test_paid_only_current_prices_do_not_become_a_zero_or_a_current_depot_value(): void
    {
        $holding = StockHolding::factory()->create(['latest_price' => 999, 'latest_price_source' => 'EODHD real-time']);
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 100]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'pieces' => 2, 'total_amount' => 100, 'cash_delta' => -100, 'booked_at' => now()->subDays(2)]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $response = $this->actingAs($admin)->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.latest_price', null)
            ->assertJsonPath('depot_holdings.0.latest_price_source_markers', [])
            ->assertJsonPath('depot_valuations.latest.stock_balance', null)
            ->assertJsonPath('depot_valuations.latest.current_balance', null)
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', null);

        $this->assertNull(collect($response->json('depot_performance_series'))->last()['account_balance']);
        Http::assertNothingSent();
    }

    public function test_a_missing_period_reference_is_not_replaced_by_the_purchase_cost(): void
    {
        $holding = $this->mixedHolding();
        $depot = Depot::factory()->create(['is_active' => true]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'pieces' => 2, 'total_amount' => 100, 'cash_delta' => -100, 'booked_at' => '2025-12-01 12:00:00']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($admin)->getJson('/admin/depot-stocks/actual-year')->assertOk()
            ->assertJsonPath('stocks.0.average_buy_or_year_start_price', null)
            ->assertJsonPath('stocks.0.average_sell_or_current_price', '56.00000000')
            ->assertJsonPath('stocks.0.change_amount', null)
            ->assertJsonPath('stocks.0.change_percent', null);

        Http::assertNothingSent();
    }

    public function test_depot_periods_distinguish_historical_entry_current_exit_and_manual_trades(): void
    {
        $holding = $this->mixedHolding();
        $depot = Depot::factory()->create(['is_active' => true]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id, 'pieces' => 2,
            'total_amount' => 100, 'cash_delta' => -100, 'booked_at' => '2025-12-01 12:00:00']);
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2025-12-31',
            'close' => 50, 'adjusted_close' => 50, 'source_key' => 'eodhd_eod', 'source_name' => 'EODHD EOD']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin)->getJson('/admin/depot-stocks/actual-year')->assertOk()
            ->assertJsonPath('stocks.0.average_buy_or_year_start_price_source_markers', ['EODHD'])
            ->assertJsonPath('stocks.0.average_sell_or_current_price_source_markers', ['free'])
            ->assertJsonPath('stocks.0.change_amount_source_markers', ['free', 'EODHD']);
        $this->getJson('/admin/depot-stocks/4-ever')->assertOk()
            ->assertJsonPath('stocks.0.average_buy_or_year_start_price_source_markers', [])
            ->assertJsonPath('stocks.0.average_sell_or_current_price_source_markers', ['free']);
        $this->getJson('/admin/depot-stocks/last-year')->assertOk()
            ->assertJsonPath('stocks.0.average_buy_or_year_start_price_source_markers', [])
            ->assertJsonPath('stocks.0.average_sell_or_current_price_source_markers', ['EODHD']);
        Http::assertNothingSent();
    }

    private function mixedHolding(): StockHolding
    {
        $holding = StockHolding::factory()->create(['symbol' => 'RAW', 'isin' => 'AT0000606306', 'exchange' => 'XETRA', 'mic_code' => 'XETR',
            'currency' => 'EUR', 'latest_price' => 999, 'end_price_24' => 999, 'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin']);
        $current = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'symbol' => $holding->symbol,
            'price' => 56, 'as_of' => Carbon::parse('2026-11-04 09:00', 'UTC'), 'source_key' => 'yahoo_xetra_intraday',
            'source_name' => 'Yahoo Finance Xetra', 'source_url' => 'https://finance.yahoo.com/quote/RAW.DE/']);
        $holding->update(['latest_realtime_price_id' => $current->id]);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'symbol' => $holding->symbol,
            'price' => 53.5, 'as_of' => Carbon::parse('2026-11-03 16:00', 'UTC'), 'source_key' => 'yahoo_xetra_intraday',
            'source_name' => 'Yahoo Finance Xetra', 'source_url' => 'https://finance.yahoo.com/quote/RAW.DE/']);
        StockPrice::factory()->create(['instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding), 'isin' => $holding->isin,
            'symbol' => $holding->symbol, 'price' => 54, 'price_type' => 'historical_eod', 'source_key' => 'eodhd_eod', 'source_name' => 'EODHD EOD',
            'as_of' => Carbon::parse('2026-11-03 16:30', 'UTC')]);
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-03', 'close' => 54, 'adjusted_close' => 54,
            'source_key' => 'eodhd_eod', 'source_name' => 'EODHD EOD']);

        return $holding;
    }
}
