<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\MarketIdentityRegistry;
use App\Services\MarketPriceLegacyImporter;
use App\Services\MarketPriceStore;
use App\Services\StockPreviousCloseResolver;
use App\Services\WatchlistPdfReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CentralPreviousTradingDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00', 'Europe/Vienna'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user);
    }

    public function test_previous_day_stays_relative_to_the_displayed_quote_before_close_after_close_and_over_the_weekend(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-04 16:30:00', '180');
        $this->quote($holding, '2026-11-05 16:28:00', '186.90');
        $daily = $this->daily($holding, '2026-11-05', '187.30');
        $this->quote($holding, '2026-11-06 09:00:00', '193.20', ['fetched_at' => '2026-11-07 06:00:00']);
        $this->assertNull($daily->observed_at);
        foreach (['2026-11-06 11:00:00', '2026-11-06 20:00:00', '2026-11-07 10:00:00', '2026-11-09 08:00:00'] as $time) {
            $this->travelTo(Carbon::parse($time, 'Europe/Vienna'));
            $row = $this->row($holding);
            $this->assertSame('193.200000', $row['latest_price']);
            $this->assertSame('187.300000', $row['end_price_24']);
            $this->assertSame('2026-11-05', $row['end_price_24_date']);
            $this->assertNull($row['end_price_24_as_of']);
            $this->assertSame('180.000000', $row['end_price_48']);
            $this->assertSame('2026-11-04', $row['end_price_48_date']);
            $this->assertSame('3.15', $row['latest_price_change_pct']);
        }
        Http::assertNothingSent();
    }

    public function test_missing_exact_previous_day_is_not_replaced_by_current_older_or_paid_prices(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-04 16:30:00', '180');
        $this->quote($holding, '2026-11-05 16:30:00', '999', ['source_key' => 'eodhd_realtime']);
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $row = $this->row($holding);
        $this->assertNull($row['end_price_24']);
        $this->assertSame('2026-11-05', $row['end_price_24_date']);
        $this->assertNull($row['end_price_24_as_of']);
        $this->assertNull($row['latest_price_change_pct']);
        $this->assertSame('180.000000', $row['end_price_48']);
        Http::assertNothingSent();
    }

    public function test_equal_values_on_different_trading_days_are_valid_and_show_zero_change(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-05 16:26:40', '193.20', ['fetched_at' => '2026-11-05 17:10:00']);
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $row = $this->row($holding);
        $this->assertSame($row['latest_price'], $row['end_price_24']);
        $this->assertSame('2026-11-05', $row['end_price_24_date']);
        $this->assertSame('2026-11-05T16:26:40+00:00', $row['end_price_24_as_of']);
        $this->assertSame('0.00', $row['latest_price_change_pct']);
    }

    public function test_a_previous_day_intraday_bar_does_not_supply_a_timestamp_for_its_close(): void
    {
        $holding = $this->holding();
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $bar = app(MarketPriceLegacyImporter::class)->attributes($holding, [
            'source_key' => 'yahoo_xetra_intraday', 'interval' => '5m', 'currency' => 'EUR',
            'as_of' => '2026-11-05 16:20:00', 'close' => '187.50',
        ]);
        app(MarketPriceStore::class)->store($listing->id, $bar);
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');

        $row = $this->row($holding);

        $this->assertSame('187.500000', $row['end_price_24']);
        $this->assertNull($row['end_price_24_as_of']);
    }

    public function test_only_current_compatible_unadjusted_prices_of_the_exact_listing_are_used(): void
    {
        $holding = $this->holding();
        $old = $this->quote($holding, '2026-11-05 16:30:00', '187.30');
        $attributes = $old->toArray();
        foreach (['id', 'current_key', 'observation_key', 'payload_hash', 'revision', 'is_current', 'supersedes_id', 'created_at', 'updated_at'] as $field) {
            unset($attributes[$field]);
        }
        $attributes['price'] = '187.80';
        $attributes['last'] = '187.80';
        $corrected = app(MarketPriceStore::class)->store($holding->market_listing_id, $attributes, confirmedCorrection: true);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertTrue($corrected->is_current);
        $wrongCurrency = $this->quote($holding, '2026-11-05 18:00:00', '777', ['currency' => 'USD']);
        $wrongCurrency->update(['validation_status' => 'valid', 'is_current' => true]);
        $this->quote($holding, '2026-11-05 19:00:00', '888', ['adjustment_basis' => 'split_adjusted']);
        $other = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS']);
        $this->quote($other, '2026-11-05 20:00:00', '999');
        $alternative = (array) DB::table('market_listings')->find($holding->market_listing_id);
        unset($alternative['id']);
        $alternative['identity_key'] = hash('sha256', 'same-instrument-other-listing');
        $alternative['venue_key'] = 'mic:XETA';
        $alternative['mic'] = 'XETA';
        $alternativeId = DB::table('market_listings')->insertGetId($alternative);
        app(MarketPriceStore::class)->store($alternativeId, app(MarketPriceLegacyImporter::class)->attributes($holding,
            ['source_key' => 'xetra_posttrade', 'as_of' => '2026-11-05 20:30:00', 'currency' => 'EUR', 'price' => '555']));
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $row = $this->row($holding);
        $this->assertSame('187.800000', $row['end_price_24']);
        $this->assertSame('2026-11-05', $row['end_price_24_date']);
    }

    public function test_us_session_date_survives_vienna_midnight_and_skips_the_july_holiday_weekend(): void
    {
        $this->travelTo(Carbon::parse('2026-07-07 04:00:00', 'Europe/Vienna'));
        $holding = $this->holding(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS']);
        $this->quote($holding, '2026-07-01 20:00:00', '115');
        $this->quote($holding, '2026-07-02 20:00:00', '120');
        $this->quote($holding, '2026-07-07 00:30:00', '130');
        $row = $this->row($holding);
        $this->assertSame('130.000000', $row['latest_price']);
        $this->assertSame('2026-07-06', $row['start_price_date']);
        $this->assertSame('2026-07-06', $row['end_price_date']);
        $this->assertSame('120.000000', $row['end_price_24']);
        $this->assertSame('2026-07-02', $row['end_price_24_date']);
        $this->assertSame('2026-07-01', $row['end_price_48_date']);
        $this->assertSame('8.33', $row['latest_price_change_pct']);
    }

    public function test_xetra_easter_holidays_use_thursday_before_tuesdays_latest_quote(): void
    {
        $this->travelTo(Carbon::parse('2026-04-11 10:00:00', 'Europe/Vienna'));
        $holding = $this->holding();
        $this->quote($holding, '2026-04-02 15:30:00', '100');
        $this->quote($holding, '2026-04-07 15:30:00', '105');
        $row = $this->row($holding);
        $this->assertSame('100.000000', $row['end_price_24']);
        $this->assertSame('2026-04-02', $row['end_price_24_date']);
    }

    public function test_stale_latest_quote_uses_its_own_previous_day_outside_the_preloaded_history_window(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-09-11 15:30:00', '100');
        $this->quote($holding, '2026-09-14 15:30:00', '112');
        $row = $this->row($holding);
        $this->assertSame('112.000000', $row['latest_price']);
        $this->assertSame('100.000000', $row['end_price_24']);
        $this->assertSame('2026-09-11', $row['end_price_24_date']);
    }

    public function test_daily_bars_keep_their_trading_date_without_an_observed_timestamp(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2026-11-05', '187.30');
        $bar = $this->daily($holding, '2026-11-06', '193.20');
        $bar->update(['effective_at' => '2026-11-07 00:00:00', 'period_end_at' => '2026-11-07 00:00:00']);
        $resolver = app(StockPreviousCloseResolver::class);
        $resolver->prime(collect([$holding]));
        $latest = $resolver->latestObservation($holding);
        $this->assertNull($bar->observed_at);
        $this->assertSame('2026-11-06', $resolver->tradingDateOf($holding, $latest));
        $previous = $resolver->forObservation($holding, $latest);
        $this->assertSame('2026-11-05', $previous['previous_close_date']);
        $this->assertSame('187.30000000', $previous['previous_close']);
        $this->assertSame('187.30000000', $resolver->comparisonReference($holding, $latest, $previous['previous_close'], $previous['previous_close_date']));
    }

    public function test_an_adjusted_latest_quote_is_not_compared_to_an_unadjusted_previous_day(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-05 16:30:00', '100');
        $this->quote($holding, '2026-11-06 16:30:00', '110', ['adjustment_basis' => 'split_adjusted']);
        $row = $this->row($holding);
        $this->assertSame('110.000000', $row['latest_price']);
        $this->assertNull($row['end_price_24']);
        $this->assertNull($row['latest_price_change_pct']);
    }

    public function test_depot_overview_uses_friday_and_thursday_on_the_weekend(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2026-11-05', '187.30');
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '775.40']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null,
            'type' => 'opening_balance', 'pieces' => null, 'total_amount' => 1000, 'cash_delta' => 1000, 'booked_at' => '2026-01-01']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => 2, 'total_amount' => '374.60', 'cash_delta' => '-374.60', 'currency' => 'EUR', 'booked_at' => '2026-11-05 12:00:00']);
        foreach (['2026-11-06' => 100, '2026-11-07' => 50] as $date => $deposit) {
            DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null,
                'type' => 'deposit', 'pieces' => null, 'total_amount' => $deposit, 'cash_delta' => $deposit, 'booked_at' => $date]);
        }
        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        $row = $response->json('depot_holdings.0');
        $this->assertSame('193.20000000', $row['latest_price']);
        $this->assertSame('187.30000000', $row['previous_day_price']);
        $this->assertSame('2026-11-05', $row['previous_day_price_date']);
        $this->assertSame('3.15', $row['previous_day_change_percent']);
        $this->assertSame('previous_day', $row['day_reference_type']);
        $this->assertSame('11.80', $row['day_change_amount_eur']);
        $response->assertJsonPath('depot_valuations.latest.account_overview.valuation_date', '2026-11-06')
            ->assertJsonPath('depot_valuations.latest.account_overview.previous_valuation_date', '2026-11-05')
            ->assertJsonPath('depot_valuations.latest.account_overview.stock_balance', '386.40')
            ->assertJsonPath('depot_valuations.latest.account_overview.cash_balance', '725.40')
            ->assertJsonPath('depot_valuations.latest.account_overview.account_balance', '1111.80')
            ->assertJsonPath('depot_valuations.latest.account_overview.previous_day_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.external_cash_flow', '100.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.change_amount', '11.80');
        $rendered = false;
        View::composer('pdf.watchlist', function ($view) use (&$rendered): void {
            $rendered = true;
            $row = $view->getData()['holdings'][0];
            $this->assertSame('187.300000', $row['end_price_24']);
            $this->assertSame('2026-11-05', $row['end_price_24_date']);
            $this->assertSame('3.15', $row['latest_price_change_pct']);
        });
        app(WatchlistPdfReport::class)->render();
        $this->assertTrue($rendered);
        Http::assertNothingSent();
    }

    public function test_live_depot_overview_skips_a_market_holiday_and_requires_the_exact_previous_session(): void
    {
        $this->travelTo(Carbon::parse('2026-05-04 12:00:00', 'Europe/Vienna'));
        $holding = $this->holding();
        $this->quote($holding, '2026-04-30 15:30:00', '100');
        $this->quote($holding, '2026-05-04 09:30:00', '110');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '925.00']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => '2', 'total_amount' => '200', 'cash_delta' => '-200', 'booked_at' => '2026-04-29']);
        foreach (['2026-05-01' => 100, '2026-05-04 11:00:00' => -25, '2026-05-05' => 50] as $date => $flow) {
            DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null,
                'type' => $flow > 0 ? 'deposit' : 'withdrawal', 'pieces' => null, 'total_amount' => abs($flow), 'cash_delta' => $flow, 'booked_at' => $date]);
        }

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.account_overview.valuation_date', '2026-05-04')
            ->assertJsonPath('depot_valuations.latest.account_overview.previous_valuation_date', '2026-04-30')
            ->assertJsonPath('depot_valuations.latest.account_overview.stock_balance', '220.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.cash_balance', '875.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.account_balance', '1095.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.previous_day_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.external_cash_flow', '75.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.change_amount', '20.00');

        $otherHolding = $this->holding(['isin' => 'DE0007164600', 'symbol' => 'SAP']);
        $this->quote($otherHolding, '2026-04-29 15:30:00', '100');
        $this->quote($otherHolding, '2026-05-04 09:30:00', '110');
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $otherHolding->id,
            'type' => 'buy', 'pieces' => '1', 'total_amount' => '100', 'cash_delta' => '-100', 'booked_at' => '2026-04-29']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.account_overview.stock_balance', '330.00')
            ->assertJsonPath('depot_valuations.latest.account_overview.previous_day_balance', null)
            ->assertJsonPath('depot_valuations.latest.account_overview.change_amount', null);
    }

    public function test_year_start_uses_january_first_cash_and_the_last_trading_day_marks(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2025-12-29', '70');
        $this->daily($holding, '2025-12-30', '80');
        $this->daily($holding, '2026-01-02', '90');
        $this->quote($holding, '2026-11-06 16:30:00', '100');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '1010.00']);
        foreach (['2025-12-29' => 1000, '2026-01-01' => 50, '2026-01-02' => 100] as $date => $amount) {
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => $date === '2025-12-29' ? 'opening_balance' : 'deposit',
                'pieces' => null,
                'total_amount' => $amount,
                'cash_delta' => $amount,
                'booked_at' => $date,
            ]);
        }
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => '2', 'total_amount' => '140', 'cash_delta' => '-140', 'booked_at' => '2025-12-30']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.year_start_balance', '1070.00')
            ->assertJsonPath('depot_valuations.latest.year_net_capital', '1170.00')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1210.00')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '40.00');

        $otherHolding = $this->holding(['isin' => 'DE0007164600', 'symbol' => 'SAP']);
        $this->daily($otherHolding, '2025-12-29', '40');
        $this->quote($otherHolding, '2026-11-06 16:30:00', '50');
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $otherHolding->id,
            'type' => 'buy', 'pieces' => '1', 'total_amount' => '40', 'cash_delta' => '-40', 'booked_at' => '2025-12-29']);
        $depot->update(['account_balance' => '970.00']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.current_balance', '1220.00')
            ->assertJsonPath('depot_valuations.latest.year_start_balance', null)
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', null);
    }

    public function test_completed_depot_periods_keep_calendar_cutoffs_and_trading_day_marks_across_the_year(): void
    {
        $this->travelTo(Carbon::parse('2027-01-04 12:00:00', 'Europe/Vienna'));
        $holding = $this->holding();
        $this->daily($holding, '2026-11-30', '100');
        $this->daily($holding, '2026-12-23', '102');
        $this->daily($holding, '2026-12-30', '110');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '1360.00']);
        foreach ([
            ['opening_balance', 1000, '2026-11-27'],
            ['deposit', 100, '2026-12-31'],
            ['withdrawal', -40, '2027-01-02'],
            ['deposit', 500, '2027-01-04'],
        ] as [$type, $flow, $date]) {
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => $type,
                'pieces' => null,
                'total_amount' => abs($flow),
                'cash_delta' => $flow,
                'booked_at' => $date,
            ]);
        }
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => '2', 'total_amount' => '200', 'cash_delta' => '-200', 'booked_at' => '2026-11-27']);

        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        $response->assertJsonPath('depot_valuations.latest.completed_periods.week.opening_date', '2026-12-27')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.start_date', '2026-12-28')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.closing_date', '2027-01-03')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.opening_balance', '1004.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.closing_balance', '1080.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.change_amount', '16.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.change_percent', '1.50')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.opening_date', '2026-11-30')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.start_date', '2026-12-01')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.closing_date', '2026-12-31')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.opening_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.closing_balance', '1120.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.change_amount', '20.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.change_percent', '1.82');

        $otherHolding = $this->holding(['isin' => 'DE0007164600', 'symbol' => 'SAP']);
        $this->daily($otherHolding, '2026-11-30', '50');
        $this->daily($otherHolding, '2026-12-23', '50');
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $otherHolding->id,
            'type' => 'buy', 'pieces' => '1', 'total_amount' => '50', 'cash_delta' => '-50', 'booked_at' => '2026-11-27']);
        $depot->update(['account_balance' => '1310.00']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.opening_balance', '1004.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.closing_balance', null)
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.change_amount', null)
            ->assertJsonPath('depot_valuations.latest.completed_periods.week.change_percent', null)
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.opening_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.closing_balance', null)
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.change_amount', null)
            ->assertJsonPath('depot_valuations.latest.completed_periods.month.change_percent', null);
    }

    public function test_new_take_two_position_uses_its_weighted_execution_price_for_the_day(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2026-11-05', '187.30');
        $this->daily($holding, '2026-11-06', '193.00');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '1000.00']);
        $trade = ['depot_id' => $depot->id, 'stock_holding_id' => $holding->id, 'type' => 'buy', 'currency' => 'EUR', 'booked_at' => '2026-11-06'];
        DepotTransaction::factory()->create([...$trade, 'pieces' => '30', 'total_amount' => '5845.75', 'cash_delta' => '-5845.75']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.latest_price', '193.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_price', '187.30000000')
            ->assertJsonPath('depot_holdings.0.day_reference_type', 'buy')
            ->assertJsonPath('depot_holdings.0.day_buy_price', '194.85833333')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '-0.95')
            ->assertJsonPath('depot_holdings.0.day_change_amount_eur', '-55.75');

        DepotTransaction::factory()->create([...$trade, 'pieces' => '0.5', 'total_amount' => '95.00', 'cash_delta' => '-95.00']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.day_buy_price', '194.77868852')
            ->assertJsonPath('depot_holdings.0.day_change_amount_eur', '-54.25');
    }

    public function test_day_result_accounts_for_buys_and_sells_without_replacing_the_opening_reference(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2025-12-30', '80');
        $this->daily($holding, '2026-11-05', '100');
        $this->quote($holding, '2026-11-06 16:30:00', '108');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '1000.00']);
        $trade = ['depot_id' => $depot->id, 'stock_holding_id' => $holding->id, 'currency' => 'EUR'];
        DepotTransaction::factory()->create([...$trade, 'type' => 'buy', 'pieces' => '10', 'total_amount' => '750', 'cash_delta' => '-750', 'booked_at' => '2025-11-03']);
        DepotTransaction::factory()->create([...$trade, 'type' => 'buy', 'pieces' => '2', 'total_amount' => '206', 'cash_delta' => '-206', 'booked_at' => '2026-11-06 11:00:00']);
        DepotTransaction::factory()->create([...$trade, 'type' => 'sell', 'pieces' => '3', 'total_amount' => '315', 'cash_delta' => '315', 'booked_at' => '2026-11-06 12:00:00']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.position_pieces', '9.00000000')
            ->assertJsonPath('depot_holdings.0.day_reference_type', 'previous_day')
            ->assertJsonPath('depot_holdings.0.previous_day_price', '100.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '8.00')
            ->assertJsonPath('depot_holdings.0.day_change_amount_eur', '81.00')
            ->assertJsonPath('depot_holdings.0.year_start_price', '85.11111111')
            ->assertJsonPath('depot_holdings.0.year_start_price_source_markers', ['free']);

        DepotTransaction::factory()->create([...$trade, 'type' => 'buy', 'pieces' => '1', 'total_amount' => '107', 'cash_delta' => '-107', 'booked_at' => '2026-11-06 18:00:00']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '8.00')
            ->assertJsonPath('depot_holdings.0.day_change_amount_eur', null);
    }

    public function test_non_eur_quotes_keep_the_day_percent_but_have_no_eur_result_without_fx(): void
    {
        $holding = $this->holding(['symbol' => 'TTWO', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS', 'currency' => 'USD']);
        $this->quote($holding, '2026-11-05 19:00:00', '100');
        $this->quote($holding, '2026-11-06 19:00:00', '110');
        $depot = Depot::factory()->create(['is_active' => true]);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => '2.5', 'total_amount' => '200', 'cash_delta' => '-200', 'currency' => 'USD', 'booked_at' => '2026-11-04']);

        $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_holdings.0.currency', 'USD')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '10.00')
            ->assertJsonPath('depot_holdings.0.day_change_amount_eur', null);
    }

    private function holding(array $attributes = []): StockHolding
    {
        return StockHolding::factory()->create([...['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR'], ...$attributes]);
    }

    private function quote(StockHolding $holding, string $at, string $value, array $extra = []): MarketPrice
    {
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $attributes = app(MarketPriceLegacyImporter::class)->attributes($holding, ['source_key' => $holding->currency === 'USD' ? 'yahoo_intraday' : 'xetra_posttrade',
            'as_of' => $at, 'currency' => $holding->currency, 'price' => $value, 'last' => $value, ...$extra]);
        $attributes = [...$attributes, ...array_intersect_key($extra, array_flip(['adjustment_basis']))];
        $price = app(MarketPriceStore::class)->store($listing->id, $attributes);
        app(MarketPriceStore::class)->selectLatest($listing->id);

        return $price;
    }

    private function daily(StockHolding $holding, string $day, string $value): MarketPrice
    {
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $attributes = app(MarketPriceLegacyImporter::class)->attributes($holding, ['source_key' => 'yahoo_daily', 'interval' => '1d',
            'trading_date' => $day, 'currency' => $holding->currency, 'open' => $value, 'high' => $value, 'low' => $value, 'close' => $value,
            'adjusted_close' => '99', 'fetched_at' => '2026-11-07 06:00:00']);

        return app(MarketPriceStore::class)->store($listing->id, $attributes);
    }

    /** @return array<string, mixed> */
    private function row(StockHolding $holding): array
    {
        $response = $this->getJson('/admin/watchlist/holdings?all=1')->assertOk();

        return collect($response->json('holdings'))->firstWhere('id', $holding->id);
    }
}
