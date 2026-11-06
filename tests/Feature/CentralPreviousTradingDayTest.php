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
        $daily = $this->daily($holding, '2026-11-05', '187.30');
        $this->quote($holding, '2026-11-06 09:00:00', '193.20', ['fetched_at' => '2026-11-07 06:00:00']);
        $this->assertNull($daily->observed_at);
        foreach (['2026-11-06 11:00:00', '2026-11-06 20:00:00', '2026-11-07 10:00:00', '2026-11-09 08:00:00'] as $time) {
            $this->travelTo(Carbon::parse($time, 'Europe/Vienna'));
            $row = $this->row($holding);
            $this->assertSame('193.200000', $row['latest_price']);
            $this->assertSame('187.300000', $row['end_price_24']);
            $this->assertSame('2026-11-05', $row['end_price_24_date']);
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
        $this->assertNull($row['latest_price_change_pct']);
        $this->assertSame('180.000000', $row['end_price_48']);
        Http::assertNothingSent();
    }

    public function test_equal_values_on_different_trading_days_are_valid_and_show_zero_change(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-05 16:30:00', '193.20');
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $row = $this->row($holding);
        $this->assertSame($row['latest_price'], $row['end_price_24']);
        $this->assertSame('2026-11-05', $row['end_price_24_date']);
        $this->assertSame('0.00', $row['latest_price_change_pct']);
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

    public function test_depot_position_and_pdf_share_the_quote_relative_reference_while_balance_keeps_its_cashflow_cutoff(): void
    {
        $holding = $this->holding();
        $this->daily($holding, '2026-11-05', '187.30');
        $this->quote($holding, '2026-11-06 16:30:00', '193.20');
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => '625.40']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null,
            'type' => 'opening_balance', 'pieces' => null, 'total_amount' => 1000, 'cash_delta' => 1000, 'booked_at' => '2026-01-01']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => 2, 'total_amount' => '374.60', 'cash_delta' => '-374.60', 'currency' => 'EUR', 'booked_at' => '2026-11-06 12:00:00']);
        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        $row = $response->json('depot_holdings.0');
        $this->assertSame('193.20000000', $row['latest_price']);
        $this->assertSame('187.30000000', $row['previous_day_price']);
        $this->assertSame('2026-11-05', $row['previous_day_price_date']);
        $this->assertSame('3.15', $row['previous_day_change_percent']);
        $this->assertSame('1011.80', $response->json('depot_valuations.latest.previous_day_balance'));
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
