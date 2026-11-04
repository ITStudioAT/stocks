<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\StockPriceCatalog;
use App\Services\StoredHistoricalPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepotPerformanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
        Http::preventStrayRequests();
    }

    public function test_stored_paid_history_restores_valuations_without_changing_free_previous_day_policy(): void
    {
        [$depot, $holding] = $this->portfolio();
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-10-27',
            'currency' => 'EUR', 'close' => 50, 'adjusted_close' => 5, 'source_key' => 'eodhd_eod']);
        StockPrice::factory()->create(['instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'currency' => 'EUR', 'source_key' => 'eodhd_eod', 'price_type' => 'historical_eod', 'price' => 6, 'close' => 60,
            'as_of' => '2026-10-28 22:59:59']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR',
            'source_key' => 'eodhd_realtime', 'price' => 65, 'as_of' => '2026-10-29 16:00:00']);
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR',
            'source_key' => 'eodhd_intraday', 'close' => 70, 'as_of' => '2026-10-30 16:00:00',
            'trading_date' => '2026-10-30', 'interval' => '5m']);

        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        $series = collect($response->json('depot_performance_series'))->keyBy('date');
        foreach (['2026-10-27' => '1000.00', '2026-10-28' => '1020.00', '2026-10-29' => '1030.00', '2026-10-30' => '1040.00', '2026-11-01' => '1040.00'] as $date => $balance) {
            $this->assertSame($balance, $series[$date]['account_balance']);
            $this->assertSame(['EODHD'], $series[$date]['source_markers']);
        }
        $this->assertSame('1050.00', $series['2026-11-04']['account_balance']);
        $this->assertSame(['free'], $series['2026-11-04']['source_markers']);
        $this->assertNull($series['2026-11-02']['account_balance']);
        $response->assertJsonPath('depot_valuations.latest.one_week_start_balance', '1040.00')
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance_source_markers', ['EODHD'])
            ->assertJsonPath('depot_valuations.latest.one_week_change_amount_source_markers', ['free', 'EODHD'])
            ->assertJsonPath('depot_holdings.0.previous_day_price', null);
        Http::assertNothingSent();
    }

    public function test_real_cash_balances_and_period_gains_handle_deposits_withdrawals_and_price_changes(): void
    {
        [$depot, $holding, $quote] = $this->portfolio();
        $this->cash($depot, 'deposit', 500, '2026-11-02');
        $this->cash($depot, 'withdrawal', -200, '2026-11-03');
        $depot->update(['account_balance' => 1200]);
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR',
            'trading_date' => '2026-10-30', 'close' => 50, 'adjusted_close' => 5, 'source_key' => 'eodhd_eod']);
        $quote->update(['price' => 50]);
        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        foreach (['balance', 'month', 'one_week'] as $period) {
            $response->assertJsonPath("depot_valuations.latest.{$period}_change_amount", '0.00')
                ->assertJsonPath("depot_valuations.latest.{$period}_change_percent", '0.00');
        }
        $series = collect($response->json('depot_performance_series'))->keyBy('date');
        $this->assertSame('1000.00', $series['2026-01-01']['cash_balance']);
        $this->assertSame('1000.00', $series['2026-11-01']['account_balance']);
        $this->assertSame('1300.00', $series['2026-11-04']['account_balance']);
        $this->assertSame('300.00', $series['2026-11-04']['net_external_cash_flow']);
        $quote->update(['price' => 75]);
        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        foreach (['balance', 'month', 'one_week'] as $period) {
            $response->assertJsonPath("depot_valuations.latest.{$period}_change_amount", '50.00')
                ->assertJsonPath("depot_valuations.latest.{$period}_change_percent", '3.85');
        }
        $response->assertJsonPath('depot_valuations.latest.year_start_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.month_start_balance', '1000.00');
        Http::assertNothingSent();
    }

    public function test_a_missing_or_wrong_currency_reference_is_not_replaced_by_older_or_future_prices(): void
    {
        [$depot, $holding] = $this->portfolio();
        foreach (['2026-10-29' => 'EUR', '2026-10-30' => 'USD', '2026-11-05' => 'EUR'] as $date => $currency) {
            StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => $currency,
                'trading_date' => $date, 'close' => 999, 'source_key' => 'eodhd_eod']);
        }
        $response = $this->getJson('/admin/depot-transactions')->assertOk();
        $series = collect($response->json('depot_performance_series'))->keyBy('date');
        $this->assertNull($series['2026-11-01']['account_balance']);
        $this->assertSame([], $series['2026-11-01']['source_markers']);
        $response->assertJsonPath('depot_valuations.latest.month_start_balance', null)
            ->assertJsonPath('depot_valuations.latest.one_week_change_amount', null)
            ->assertJsonPath('depot_valuations.latest.one_week_change_amount_source_markers', []);
        Http::assertNothingSent();
    }

    public function test_every_open_position_contributes_its_origin_and_one_missing_price_invalidates_the_total(): void
    {
        [$depot, $holding] = $this->portfolio();
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR',
            'trading_date' => '2026-10-30', 'close' => 50, 'source_key' => 'eodhd_eod']);
        $other = StockHolding::factory()->create(['currency' => 'EUR', 'exchange' => 'XETRA', 'mic_code' => 'XETR']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $other->id,
            'type' => 'buy', 'pieces' => 1, 'total_amount' => 25, 'cash_delta' => -25, 'booked_at' => '2026-10-01']);
        $depot->update(['account_balance' => 875]);
        $quote = StockRealtimePrice::factory()->create(['stock_holding_id' => $other->id, 'currency' => 'EUR',
            'source_key' => 'xetra_posttrade', 'mic' => 'XETR', 'price' => 30, 'as_of' => '2026-10-30 16:00:00']);
        $response = $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '1005.00')
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance_source_markers', ['free', 'EODHD']);
        $point = collect($response->json('depot_performance_series'))->firstWhere('date', '2026-11-01');
        $this->assertSame(['free', 'EODHD'], $point['source_markers']);
        $quote->update(['raw_payload' => ['quarantined' => true]]);
        $response = $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance', null)
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance_source_markers', []);
        $point = collect($response->json('depot_performance_series'))->firstWhere('date', '2026-11-01');
        $this->assertNull($point['account_balance']);
        $this->assertSame([], $point['source_markers']);
        Http::assertNothingSent();
    }

    public function test_an_unpriced_opening_position_does_not_get_a_purchase_cost_or_later_price_baseline(): void
    {
        [$depot, $holding] = $this->portfolio();
        DepotTransaction::query()->where('depot_id', $depot->id)->where('type', 'buy')->update(['booked_at' => '2025-12-01']);
        $response = $this->getJson('/admin/depot-transactions')->assertOk()
            ->assertJsonPath('depot_valuations.latest.year_start_balance', null)
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', null)
            ->assertJsonPath('depot_valuations.latest.balance_change_amount_source_markers', []);
        $this->assertNull($response->json('depot_performance_series.0.account_balance'));
        Http::assertNothingSent();
    }

    public function test_batched_history_preserves_each_venue_day_across_summer_and_winter_utc_boundaries(): void
    {
        $cases = [
            ['EUR', 'XETR', '2026-07-02 21:59:00', '2026-07-02 22:01:00', '2026-07-02', 'Europe/Berlin'],
            ['EUR', 'XETR', '2026-11-03 22:59:00', '2026-11-03 23:01:00', '2026-11-03', 'Europe/Berlin'],
            ['USD', 'XNAS', '2026-07-02 03:59:00', '2026-07-02 04:01:00', '2026-07-01', 'America/New_York'],
            ['USD', 'XNAS', '2026-11-04 04:59:00', '2026-11-04 05:01:00', '2026-11-03', 'America/New_York'],
        ];
        $holdings = collect();
        foreach ($cases as [$currency, $mic, $before, $after]) {
            $holding = StockHolding::factory()->create(['currency' => $currency, 'mic_code' => $mic,
                'exchange' => $mic === 'XETR' ? 'XETRA' : 'NASDAQ']);
            foreach ([$before => 50, $after => 999] as $asOf => $price) {
                StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => $currency,
                    'source_key' => 'eodhd_realtime', 'price' => $price, 'as_of' => $asOf]);
            }
            $holdings->push($holding);
        }
        $resolver = app(StoredHistoricalPriceResolver::class);
        $resolver->prime($holdings, Carbon::parse('2026-06-30'), now());
        foreach ($cases as $index => [, , , , $day, $timezone]) {
            $price = $resolver->at($holdings[$index], Carbon::parse($day, $timezone)->endOfDay());
            $this->assertSame('50.00000000', $price['price']);
            $this->assertSame(['EODHD'], $price['source_markers']);
        }
        Http::assertNothingSent();
    }

    public function test_shared_identifiers_never_mix_historical_prices_in_different_currencies(): void
    {
        $euro = StockHolding::factory()->create(['isin' => 'US0378331005', 'currency' => 'EUR', 'exchange' => 'XETRA', 'mic_code' => 'XETR']);
        $dollar = StockHolding::factory()->make(['id' => $euro->id + 1000, 'isin' => $euro->isin, 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS']);
        foreach (['EUR' => 50, 'USD' => 999] as $currency => $price) {
            StockPrice::factory()->create(['instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($euro),
                'currency' => $currency, 'source_key' => 'eodhd_eod', 'price_type' => 'historical_eod',
                'price' => $price, 'close' => $price, 'as_of' => '2026-11-03 20:00:00']);
        }
        $resolver = app(StoredHistoricalPriceResolver::class);
        $resolver->prime(collect([$euro, $dollar]), now()->subWeek(), now());
        $this->assertSame('50.00000000', $resolver->at($euro, Carbon::parse('2026-11-03')->endOfDay())['price']);
        $this->assertSame('999.00000000', $resolver->at($dollar, Carbon::parse('2026-11-03')->endOfDay())['price']);
        Http::assertNothingSent();
    }

    /** @return array{Depot, StockHolding, StockRealtimePrice} */
    private function portfolio(): array
    {
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 900]);
        $holding = StockHolding::factory()->create(['currency' => 'EUR', 'exchange' => 'XETRA', 'mic_code' => 'XETR',
            'latest_price' => 999, 'latest_price_source' => 'EODHD real-time']);
        $this->cash($depot, 'opening_balance', 1000, '2026-01-01');
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
            'type' => 'buy', 'pieces' => 2, 'total_amount' => 100, 'cash_delta' => -100, 'currency' => 'EUR', 'booked_at' => '2026-10-01']);
        $quote = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR',
            'source_key' => 'yahoo_xetra_intraday', 'source_name' => 'Yahoo Finance Xetra', 'mic' => 'XETR',
            'price' => 75, 'as_of' => '2026-11-04 14:00:00', 'fetched_at' => '2026-11-04 14:01:00']);

        return [$depot, $holding, $quote];
    }

    private function cash(Depot $depot, string $type, float $amount, string $date): void
    {
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => null, 'type' => $type,
            'pieces' => null, 'currency' => 'EUR', 'total_amount' => abs($amount), 'cash_delta' => $amount, 'booked_at' => $date]);
    }
}
