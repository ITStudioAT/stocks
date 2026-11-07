<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\MarketDataCoordinator;
use App\Services\MarketPriceStore;
use App\Services\StockPriceCatalog;
use App\Services\YahooFinanceMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_each_weekday_of_the_current_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '900.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '130.000000', 'latest_price_source' => 'Yahoo Finance', 'exchange' => 'XETRA', 'mic_code' => 'XETR',
                'currency' => 'EUR',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '100.00',
                'unit_price' => '100.00000000',
                'cash_delta' => '-100.00',
                'balance_after' => '900.00',
                'booked_at' => '2026-06-30 00:00:00',
            ]);

            $instrumentKey = app(StockPriceCatalog::class)->instrumentKeyForHolding($holding);

            collect([
                ['date' => '2026-06-30', 'price' => '100.00000000'],
                ['date' => '2026-07-24', 'price' => '110.00000000'],
                ['date' => '2026-07-31', 'price' => '120.00000000'],
                ['date' => '2026-08-03', 'price' => '120.00000000'],
                ['date' => '2026-08-04', 'price' => '120.00000000'],
                ['date' => '2026-08-05', 'price' => '125.00000000'],
                ['date' => '2026-08-06', 'price' => '120.00000000'],
            ])->each(fn (array $price): StockPrice => StockPrice::factory()->create([
                'instrument_key' => $instrumentKey,
                'source_key' => 'yahoo_intraday', 'source_name' => 'Yahoo Finance', 'source_url' => 'https://finance.yahoo.com/',
                'price_type' => 'observed_session_end',
                'currency' => 'EUR',
                'price' => $price['price'],
                'as_of' => Carbon::parse("{$price['date']} 23:59:59", 'Europe/Vienna')->utc(),
                'fetched_at' => Carbon::parse("{$price['date']} 23:59:59", 'Europe/Vienna')->utc(),
                'freshness_status' => 'historical',
            ]));
            $livePrice = StockRealtimePrice::factory()->state(['source_key' => 'yahoo_intraday', 'source_name' => 'Yahoo Finance', 'source_url' => 'https://finance.yahoo.com/'])->create([
                'stock_holding_id' => $holding->id,
                'currency' => 'EUR',
                'price' => '130.00000000',
                'as_of' => Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'),
                'fetched_at' => Carbon::parse('2026-08-07 12:01:00', 'Europe/Vienna'),
                'freshness_status' => 'realtime',
            ]);

            $holding->update([
                'latest_realtime_price_id' => $livePrice->id,
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/dashboard/performance')
                ->assertOk()
                ->assertJsonCount(5, 'days')
                ->assertJsonPath('days.0.date', '2026-08-03')
                ->assertJsonPath('days.0.is_live', false)
                ->assertJsonPath('days.0.change_amount', '0.00')
                ->assertJsonPath('days.1.date', '2026-08-04')
                ->assertJsonPath('days.1.is_live', false)
                ->assertJsonPath('days.1.change_amount', '0.00')
                ->assertJsonPath('days.2.date', '2026-08-05')
                ->assertJsonPath('days.2.change_amount', '5.00')
                ->assertJsonPath('days.2.change_percent', '0.49')
                ->assertJsonPath('days.3.date', '2026-08-06')
                ->assertJsonPath('days.3.change_amount', '-5.00')
                ->assertJsonPath('days.3.change_percent', '-0.49')
                ->assertJsonPath('days.4.date', '2026-08-07')
                ->assertJsonPath('days.4.is_live', true)
                ->assertJsonPath('days.4.change_amount', '10.00')
                ->assertJsonPath('days.4.change_percent', '0.98')
                ->assertJsonCount(5, 'sums')
                ->assertJsonPath('sums.0.period', 'week')
                ->assertJsonPath('sums.0.change_amount', '10.00')
                ->assertJsonPath('sums.0.change_percent', '0.98')
                ->assertJsonPath('sums.1.period', 'last_week')
                ->assertJsonPath('sums.1.change_amount', '10.00')
                ->assertJsonPath('sums.1.change_percent', '0.99')
                ->assertJsonPath('sums.2.period', 'month')
                ->assertJsonPath('sums.2.change_amount', '10.00')
                ->assertJsonPath('sums.2.change_percent', '0.98')
                ->assertJsonPath('sums.3.period', 'last_month')
                ->assertJsonPath('sums.3.change_amount', '20.00')
                ->assertJsonPath('sums.3.change_percent', '2.00')
                ->assertJsonPath('sums.4.period', 'year')
                ->assertJsonPath('sums.4.change_amount', '30.00')
                ->assertJsonPath('sums.4.change_percent', '3.00');

            $this->assertDatabaseHas('depot_performance_snapshots', [
                'depot_id' => $depot->id,
                'period' => 'week',
                'end_date' => '2026-08-02',
                'expires_at' => null,
            ]);
            $this->assertDatabaseHas('depot_performance_snapshots', [
                'depot_id' => $depot->id,
                'period' => 'month',
                'end_date' => '2026-07-31',
                'expires_at' => null,
            ]);
            $this->assertDatabaseMissing('depot_performance_snapshots', [
                'depot_id' => $depot->id,
                'end_date' => '2026-08-07',
            ]);

            $livePrice->update(['price' => '134.00000000']);
            DB::enableQueryLog();
            try {
                $this->getJson('/admin/dashboard/performance')
                    ->assertOk()
                    ->assertJsonPath('days.4.change_amount', '14.00')
                    ->assertJsonPath('sums.0.change_amount', '14.00')
                    ->assertJsonPath('sums.1.change_amount', '10.00')
                    ->assertJsonPath('sums.3.change_amount', '20.00')
                    ->assertJsonPath('sums.4.change_amount', '34.00');

                $queries = collect(DB::getQueryLog())->pluck('query');
                $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'depot_performance_snapshots')));
                $this->assertFalse($queries->contains(fn (string $query): bool => str_contains($query, 'historical_observations')));
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_backdated_bookings_and_date_corrections_refresh_stored_periods(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));
        $depot = Depot::factory()->create(['account_balance' => '1000.00', 'is_active' => true]);
        DepotTransaction::factory()->for($depot)->create([
            'type' => 'opening_balance',
            'cash_delta' => '1000.00',
            'balance_after' => '1000.00',
            'booked_at' => '2026-01-01 00:00:00',
        ]);
        $this->actingAs($this->adminUser())
            ->getJson('/admin/dashboard/performance')
            ->assertJsonPath('sums.3.change_amount', '0.00');

        $booking = $this->postJson('/admin/depot-transactions/cash', [
            'type' => 'dividend',
            'total_amount' => '100.00',
            'booked_at' => '2026-07-15',
        ])->assertCreated();

        $this->assertDatabaseMissing('depot_performance_snapshots', [
            'depot_id' => $depot->id, 'period' => 'month', 'end_date' => '2026-07-31',
        ]);
        $this->getJson('/admin/dashboard/performance')
            ->assertJsonPath('sums.3.change_amount', '100.00');

        $this->patchJson('/admin/depot-transactions/'.$booking->json('transaction.id').'/date', [
            'booked_at' => '2026-08-06',
        ])->assertOk();
        $this->getJson('/admin/dashboard/performance')
            ->assertJsonPath('sums.3.change_amount', '0.00')
            ->assertJsonPath('sums.2.change_amount', '100.00');
    }

    public function test_backfill_stores_completed_weeks_months_and_years_once_per_depot(): void
    {
        $this->travelTo(Carbon::parse('2026-01-12 12:00:00', 'Europe/Vienna'));
        $depot = Depot::factory()->create(['account_balance' => '1000.00']);
        DepotTransaction::factory()->for($depot)->create([
            'type' => 'opening_balance',
            'cash_delta' => '1000.00',
            'balance_after' => '1000.00',
            'booked_at' => '2025-12-01 00:00:00',
        ]);

        $this->artisan('dashboard:backfill-performance')->assertSuccessful();

        foreach (['week' => '2026-01-11', 'month' => '2025-12-31', 'year' => '2025-12-31'] as $period => $end) {
            $this->assertDatabaseHas('depot_performance_snapshots', [
                'depot_id' => $depot->id, 'period' => $period, 'end_date' => $end, 'expires_at' => null,
            ]);
        }
        $this->assertDatabaseMissing('depot_performance_snapshots', [
            'depot_id' => $depot->id, 'period' => 'week', 'end_date' => '2026-01-18',
        ]);

        $count = DB::table('depot_performance_snapshots')->count();
        $this->artisan('dashboard:backfill-performance')->assertSuccessful();
        $this->assertDatabaseCount('depot_performance_snapshots', $count);
    }

    public function test_dashboard_performance_is_empty_without_an_active_depot(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/admin/dashboard/performance')
            ->assertOk()
            ->assertExactJson([
                'days' => [],
                'sums' => [],
            ]);
    }

    public function test_dashboard_performance_excludes_a_same_day_deposit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Europe/Vienna'));

        try {
            $depot = Depot::factory()->create([
                'account_balance' => '13000.00',
                'is_active' => true,
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'deposit',
                'pieces' => null,
                'total_amount' => '3000.00',
                'unit_price' => null,
                'cash_delta' => '3000.00',
                'balance_after' => '13000.00',
                'booked_at' => '2026-08-11 00:00:00',
                'is_external_cashflow' => true,
                'affects_performance' => false,
            ]);

            $this->actingAs($this->adminUser())
                ->getJson('/admin/dashboard/performance')
                ->assertOk()
                ->assertJsonPath('sums.0.change_amount', '0.00')
                ->assertJsonPath('sums.0.change_percent', '0.00')
                ->assertJsonPath('sums.2.change_amount', '0.00')
                ->assertJsonPath('sums.2.change_percent', '0.00')
                ->assertJsonPath('sums.4.change_amount', '0.00')
                ->assertJsonPath('sums.4.change_percent', '0.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_last_month_percentage_excludes_later_external_cash_flows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));

        try {
            $depot = Depot::factory()->create([
                'account_balance' => '2100.00',
                'is_active' => true,
            ]);

            collect([
                [
                    'type' => 'opening_balance',
                    'total_amount' => '1000.00',
                    'cash_delta' => '1000.00',
                    'balance_after' => '1000.00',
                    'booked_at' => '2026-01-01 00:00:00',
                ],
                [
                    'type' => 'dividend',
                    'total_amount' => '100.00',
                    'cash_delta' => '100.00',
                    'balance_after' => '1100.00',
                    'booked_at' => '2026-07-15 00:00:00',
                ],
                [
                    'type' => 'deposit',
                    'total_amount' => '1000.00',
                    'cash_delta' => '1000.00',
                    'balance_after' => '2100.00',
                    'booked_at' => '2026-08-04 00:00:00',
                ],
            ])->each(fn (array $transaction): DepotTransaction => DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                ...$transaction,
            ]));

            $this->actingAs($this->adminUser())
                ->getJson('/admin/dashboard/performance')
                ->assertOk()
                ->assertJsonPath('sums.3.period', 'last_month')
                ->assertJsonPath('sums.3.change_amount', '100.00')
                ->assertJsonPath('sums.3.change_percent', '10.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_cannot_view_dashboard_performance(): void
    {
        $this->getJson('/admin/dashboard/performance')->assertUnauthorized();
        $this->postJson('/admin/dashboard/performance/repair', ['period' => 'last_week'])->assertUnauthorized();
    }

    public function test_admin_can_repair_only_the_missing_references_for_last_week(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        $this->fakeDailyRepairPrices([
            '2026-09-30' => 92,
            '2026-10-23' => 96,
            '2026-10-27' => 97,
            '2026-10-30' => 100,
            '2026-11-05' => 999,
        ]);

        $this->actingAs($this->adminUser())->getJson('/admin/dashboard/performance')
            ->assertOk()
            ->assertJsonPath('days.0.change_amount', null)
            ->assertJsonPath('days.0.repairable', true)
            ->assertJsonPath('days.1.change_amount', '0.00')
            ->assertJsonPath('days.1.repairable', false)
            ->assertJsonPath('sums.1.change_amount', null)
            ->assertJsonPath('sums.1.repairable', true);
        Http::assertNothingSent();

        $this->postJson('/admin/dashboard/performance/repair', ['period' => 'last_week'])
            ->assertOk()
            ->assertJsonPath('status', 'repaired')
            ->assertJsonPath('days.0.change_amount', '10.00')
            ->assertJsonPath('days.3.change_amount', '1.00')
            ->assertJsonPath('sums.1.change_amount', '4.00')
            ->assertJsonPath('sums.1.repairable', false)
            ->assertJsonPath('sums.3.change_amount', null);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => (int) $request['period1'] === Carbon::parse('2026-10-23', 'Europe/Berlin')->timestamp
            && (int) $request['period2'] === Carbon::parse('2026-10-31', 'Europe/Berlin')->timestamp
            && $request['interval'] === '1d');
        $this->assertSame(
            ['2026-10-23', '2026-10-30'],
            MarketPrice::query()->where('source_key', 'yahoo_daily')->orderBy('trading_date')->pluck('trading_date')->all(),
        );
        $this->postJson('/admin/dashboard/performance/repair', ['period' => 'last_week'])
            ->assertOk()
            ->assertJsonPath('status', 'not_needed');
        Http::assertSentCount(1);
    }

    public function test_day_repair_keeps_a_gap_when_the_provider_has_no_matching_price(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 19:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        $this->fakeDailyRepairPrices(['2026-10-29' => 99, '2026-11-02' => 999]);

        $this->actingAs($this->adminUser())->getJson('/admin/dashboard/performance')
            ->assertOk()
            ->assertJsonPath('days.0.repairable', true)
            ->assertJsonPath('days.2.change_amount', null)
            ->assertJsonPath('days.2.repairable', false);

        $this->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-04'])
            ->assertOk()
            ->assertJsonPath('status', 'not_needed');
        $this->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02', 'period' => 'last_week'])
            ->assertUnprocessable();
        Http::assertNothingSent();

        $this->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertOk()
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('days.0.change_amount', null)
            ->assertJsonPath('days.1.change_amount', '0.00');

        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public function test_stored_paid_prices_are_visible_in_depot_history_but_do_not_fill_dashboard_gaps(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        app(MarketDataCoordinator::class)->persist(StockHolding::sole(), [
            ['source_key' => 'eodhd_eod', 'interval' => '1d', 'currency' => 'EUR', 'close' => 99, 'trading_date' => '2026-10-30'],
        ]);
        $this->actingAs($this->adminUser())->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '999.00')
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance_source_markers', ['EODHD']);
        $this->getJson('/admin/dashboard/performance')
            ->assertJsonPath('days.0.change_amount', null)
            ->assertJsonPath('days.0.repairable', true);
        Http::assertNothingSent();

        $this->fakeDailyRepairPrices(['2026-10-30' => 100]);
        $this->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'repaired')
            ->assertJsonPath('days.0.change_amount', '10.00')
            ->assertJsonPath('days.0.change_amount_source_markers', ['free']);
        Http::assertSentCount(1);
    }

    public function test_market_collection_stays_scheduled_without_recurring_daily_history_imports(): void
    {
        $events = collect(Schedule::events());

        $this->assertFalse($events->contains(fn ($event): bool => str_contains($event->command ?? '', 'market-data:daily')));
        $this->assertSame('*/5 * * * *', $events->first(fn ($event): bool => str_contains($event->command ?? '', 'market-data:collect'))?->expression);
    }

    public function test_card_repair_reports_a_failed_provider_request_and_leaves_the_gap_open(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response([], 503)]);

        $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', ['period' => 'last_month'])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure_reason', 'download')
            ->assertJsonPath('provider_http_status', 503)
            ->assertJsonPath('sums.3.change_amount', null)
            ->assertJsonPath('sums.3.repairable', true);

        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public function test_queued_dashboard_repair_deduplicates_requests_and_reports_the_updated_cards(): void
    {
        config(['queue.default' => 'database']);
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        $this->fakeDailyRepairPrices(['2026-10-30' => 100]);

        $target = ['date' => '2026-11-02'];
        $runId = $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', $target)
            ->assertSuccessful()
            ->assertJsonPath('status', 'queued')
            ->assertJsonStructure(['run_id'])
            ->json('run_id');

        $this->postJson('/admin/dashboard/performance/repair', $target)
            ->assertSuccessful()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('run_id', $runId);
        $this->postJson('/admin/dashboard/performance/repair', ['period' => 'last_week'])
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonMissingPath('run_id');
        $this->getJson('/admin/dashboard/performance/repair/'.$runId)
            ->assertSuccessful()
            ->assertJsonPath('status', 'queued');

        Http::assertNothingSent();
        $this->assertSame(1, Queue::size());
        Queue::pop()->fire();

        $this->getJson('/admin/dashboard/performance/repair/'.$runId)
            ->assertOk()
            ->assertJsonPath('status', 'repaired')
            ->assertJsonPath('days.0.change_amount', '10.00')
            ->assertJsonPath('days.0.repairable', false)
            ->assertJsonPath('sums.1.change_amount', null);
        Http::assertSentCount(1);
    }

    public function test_queued_repair_gets_the_next_slot_when_intraday_already_reserved_the_symbol(): void
    {
        config(['queue.default' => 'database']);
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        $this->fakeDailyRepairPrices(['2026-10-30' => 100]);
        $coordinator = app(MarketDataCoordinator::class);
        $this->assertTrue($coordinator->reserve('yahoo-symbol:TKE.DE'));

        $runId = $this->actingAs($this->adminUser())
            ->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'queued')->json('run_id');
        Queue::pop()->fire();
        $this->getJson('/admin/dashboard/performance/repair/'.$runId)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('pause_reason', 'symbol')
            ->assertJsonPath('retry_at', now()->addMinutes(5)->toIso8601String());
        Http::assertNothingSent();

        $this->travel(5)->minutes();
        $this->assertFalse($coordinator->reserveYahoo('TKE.DE'));
        $job = Queue::pop();
        $this->assertNotNull($job);
        $job->fire();
        $this->getJson('/admin/dashboard/performance/repair/'.$runId)
            ->assertJsonPath('status', 'repaired')
            ->assertJsonPath('days.0.change_amount', '10.00');
        Http::assertSentCount(1);

        $this->assertFalse($coordinator->reserveYahoo('TKE.DE'));
        $this->travel(5)->minutes();
        $this->assertTrue($coordinator->reserveYahoo('TKE.DE'));
    }

    #[DataProvider('yahooPauseResponses')]
    public function test_repair_reports_provider_rejection_and_keeps_the_shared_pause(int $status, string $retryAfter): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response([], $status, ['Retry-After' => $retryAfter])]);
        $retryAt = now()->addMinutes(30)->toIso8601String();

        $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('pause_reason', 'provider')
            ->assertJsonPath('provider_http_status', $status)
            ->assertJsonPath('retry_at', $retryAt)
            ->assertJsonPath('days.0.change_amount', null);

        $this->travel(29)->minutes();
        $this->assertFalse(app(MarketDataCoordinator::class)->reserveYahoo('TKE.DE'));
        $this->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('pause_reason', 'provider')
            ->assertJsonPath('retry_at', $retryAt);
        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public static function yahooPauseResponses(): array
    {
        return [
            'seconds' => [429, '1800'],
            'http date' => [403, 'Sat, 07 Nov 2026 11:30:00 GMT'],
        ];
    }

    #[DataProvider('longRepairWaits')]
    public function test_queued_repair_resumes_all_pending_symbols_after_retry_after(string $startTime, string $finalStatus): void
    {
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        $this->travelTo(Carbon::parse('2026-11-07 '.$startTime, 'Europe/Vienna'));
        $depot = $this->depotWithStoredNovemberPrices();
        $other = StockHolding::factory()->create([
            'isin' => 'AT0000606306', 'symbol' => 'RAW', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR',
        ]);
        DepotTransaction::factory()->for($depot)->create([
            'stock_holding_id' => $other->id, 'type' => 'buy', 'pieces' => 1,
            'cash_delta' => -190, 'booked_at' => '2026-09-01 00:00:00',
        ]);
        app(MarketDataCoordinator::class)->persist($other, [
            ['source_key' => 'xetra_posttrade', 'currency' => 'EUR', 'price' => 200, 'as_of' => '2026-11-02 16:20:00'],
        ]);
        Http::fake([
            'query1.finance.yahoo.com/*/TKE.DE*' => Http::sequence()
                ->push([], 429, ['Retry-After' => '108000'])
                ->push($this->dailyRepairChart(['2026-10-30' => 100])),
            'query1.finance.yahoo.com/*/RAW.DE*' => Http::response($this->dailyRepairChart(['2026-10-30' => 190], 'RAW.DE')),
        ]);

        $target = ['date' => '2026-11-02'];
        $runId = $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', $target)
            ->assertJsonPath('status', 'queued')->json('run_id');
        Queue::pop()->fire();
        $retryAt = now()->addHours(30)->toIso8601String();
        $statusPath = '/admin/dashboard/performance/repair/'.$runId;
        $this->getJson($statusPath)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('pause_reason', 'provider')
            ->assertJsonPath('provider_http_status', 429)
            ->assertJsonPath('retry_at', $retryAt);
        Http::assertSentCount(1);

        $this->travel(1799)->minutes();
        $this->assertNull(Queue::pop());
        $this->getJson($statusPath)->assertJsonPath('status', 'waiting')->assertJsonPath('retry_at', $retryAt);
        $this->postJson('/admin/dashboard/performance/repair', $target)
            ->assertJsonPath('status', 'waiting')->assertJsonPath('run_id', $runId);
        $this->assertSame(1, Queue::size());
        Http::assertSentCount(1);

        $this->travel(1)->minute();
        $job = Queue::pop();
        $this->assertNotNull($job);
        $job->fire();
        $response = $this->getJson($statusPath)->assertJsonPath('status', $finalStatus);
        if ($finalStatus === 'repaired') {
            $response->assertJsonPath('days.0.change_amount', '20.00');
        }
        Http::assertSentCount(3);
        $this->assertSame(2, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public static function longRepairWaits(): array
    {
        return [
            'same week' => ['12:00:00', 'repaired'],
            'next week' => ['18:00:00', 'period_changed'],
        ];
    }

    public function test_repair_distinguishes_a_validation_failure_from_a_pause(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [['meta' => ['currency' => 'USD']]]]])]);

        $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure_reason', 'validation')
            ->assertJsonPath('provider_http_status', 200)
            ->assertJsonPath('days.0.change_amount', null)
            ->assertJsonMissingPath('retry_at');
        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public function test_repair_does_not_invent_a_retry_time_for_an_existing_pause(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        Cache::put(YahooFinanceMarketData::CooldownKey, true, now()->addMinutes(30));

        $this->actingAs($this->adminUser())->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('pause_reason', 'provider')
            ->assertJsonPath('provider_http_status', null)
            ->assertJsonPath('retry_at', null)
            ->assertJsonPath('message', 'Für Yahoo Finance ist eine Abrufpause aktiv.');
        Http::assertNothingSent();
    }

    public function test_queued_repair_stops_after_repeated_provider_pauses(): void
    {
        config(['queue.default' => 'database']);
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $this->depotWithStoredNovemberPrices();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response([], 429, ['Retry-After' => '300'])]);
        $runId = $this->actingAs($this->adminUser())
            ->postJson('/admin/dashboard/performance/repair', ['date' => '2026-11-02'])->json('run_id');
        $statusPath = '/admin/dashboard/performance/repair/'.$runId;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            Queue::pop()->fire();
            $this->getJson($statusPath)->assertJsonPath('status', 'waiting');
            $this->travel(5)->minutes();
        }
        $lastJob = Queue::pop();
        $lastJob->fire();
        $this->assertFalse($lastJob->isReleased());
        $this->getJson($statusPath)->assertJsonPath('status', 'paused')->assertJsonPath('provider_http_status', 429);
        Http::assertSentCount(3);
    }

    private function depotWithStoredNovemberPrices(): Depot
    {
        config(['services.market_data.central_enabled' => true]);
        $holding = StockHolding::factory()->create([
            'isin' => 'US8740541094',
            'symbol' => 'TKE',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 900]);
        DepotTransaction::factory()->for($depot)->create([
            'stock_holding_id' => $holding->id,
            'booked_at' => '2026-09-01 00:00:00',
            'type' => 'buy',
            'pieces' => 1,
            'cash_delta' => -100,
            'balance_after' => 900,
        ]);

        $quotes = collect(['2026-11-02' => 110, '2026-11-03' => 110, '2026-11-04' => 112, '2026-11-05' => 113, '2026-11-06' => 114])
            ->map(fn (int $price, string $date): array => [
                'source_key' => 'xetra_posttrade',
                'currency' => 'EUR',
                'price' => $price,
                'as_of' => $date.' 16:20:00',
            ])->values()->all();
        app(MarketDataCoordinator::class)->persist($holding, $quotes);
        app(MarketPriceStore::class)->selectLatest($holding->market_listing_id);

        return $depot;
    }

    /** @param array<string, int> $prices */
    private function fakeDailyRepairPrices(array $prices): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->dailyRepairChart($prices))]);
    }

    /** @param array<string, int> $prices @return array<string, mixed> */
    private function dailyRepairChart(array $prices, string $symbol = 'TKE.DE'): array
    {
        return ['chart' => ['result' => [[
            'meta' => [
                'symbol' => $symbol,
                'currency' => 'EUR',
                'exchangeName' => 'GER',
                'fullExchangeName' => 'XETRA',
                'exchangeTimezoneName' => 'Europe/Berlin',
                'instrumentType' => 'EQUITY',
                'dataGranularity' => '1d',
            ],
            'timestamp' => array_map(fn (string $date): int => Carbon::parse($date.' 08:00:00', 'UTC')->timestamp, array_keys($prices)),
            'indicators' => ['quote' => [array_fill_keys(['open', 'high', 'low', 'close'], array_values($prices))]],
        ]]]];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
