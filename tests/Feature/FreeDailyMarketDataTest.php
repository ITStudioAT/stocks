<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\DashboardPerformanceSnapshots;
use App\Services\FreeDailyMarketData;
use App\Services\MarketDataCoordinator;
use App\Services\MarketPriceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreeDailyMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-06 18:00:00', 'UTC'));
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
    }

    private function chart(string $currency = 'EUR'): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['symbol' => 'TKE.DE', 'currency' => $currency, 'exchangeName' => 'GER', 'fullExchangeName' => 'XETRA',
                'exchangeTimezoneName' => 'Europe/Berlin', 'instrumentType' => 'EQUITY', 'dataGranularity' => '1d'],
            'timestamp' => [Carbon::parse('2026-11-05 08:00:00', 'UTC')->timestamp, Carbon::parse('2026-11-06 08:00:00', 'UTC')->timestamp],
            'indicators' => ['quote' => [['open' => [190.2, null], 'high' => [194.0, null], 'low' => [189.5, null],
                'close' => [193.99000549316406, null], 'volume' => [1614, null]]], 'adjclose' => [['adjclose' => [192.25, null]]]],
        ]]]];
    }

    public function test_daily_import_preserves_real_semantics_skips_gaps_and_is_repeatable(): void
    {
        $holding = $this->holding();
        $depot = Depot::factory()->create();
        $snapshots = app(DashboardPerformanceSnapshots::class);
        $snapshots->closingBalance($depot, Carbon::parse('2026-11-04'));
        $snapshots->closingBalance($depot, Carbon::parse('2026-11-05'));

        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart())]);
        $daily = app(FreeDailyMarketData::class);
        $first = $daily->import($holding);
        $this->assertSame(1, $first['stored_count']);
        $this->assertSame(1, $first['skipped_count']);
        $this->assertDatabaseHas('depot_performance_snapshots', ['depot_id' => $depot->id, 'end_date' => '2026-11-04']);
        $this->assertDatabaseMissing('depot_performance_snapshots', ['depot_id' => $depot->id, 'end_date' => '2026-11-05']);
        $row = MarketPrice::sole();
        $this->assertSame('bar', $row->record_kind);
        $this->assertSame('1d', $row->interval_code);
        $this->assertNull($row->observed_at);
        $this->assertSame('session_close_derived', $row->time_basis);
        $this->assertNotSame($row->close, $row->adjusted_close);
        $this->assertSame('2026-11-05', $row->trading_date);
        $this->assertSame('cooldown', $daily->import($holding)['status']);
        Http::assertSentCount(1);
        $this->travel(5)->minutes();
        $this->assertSame(0, $daily->import($holding)['stored_count']);
        $this->assertSame(1, MarketPrice::count());
    }

    public function test_rate_limit_blocks_later_calls_and_wrong_currency_never_enters_storage(): void
    {
        $holding = $this->holding();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response('', 429, ['Retry-After' => '1800'])]);
        $daily = app(FreeDailyMarketData::class);
        $this->assertSame('failed', $daily->import($holding)['status']);
        $this->travel(6)->minutes();
        $this->assertSame('cooldown', $daily->import($holding)['status']);
        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::count());
        $this->travel(25)->minutes();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart('USD'))]);
        $this->assertSame('failed', $daily->import($holding)['status']);
        $this->assertSame(0, MarketPrice::count());
    }

    public function test_scheduled_gap_fill_restores_dashboard_boundaries_without_replacing_priced_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Europe/Vienna'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);

        $holding = $this->holding();
        $depot = Depot::factory()->create(['is_active' => true, 'account_balance' => 900]);
        DepotTransaction::factory()->for($depot)->create([
            'stock_holding_id' => $holding->id,
            'booked_at' => '2026-09-01 00:00:00',
            'type' => 'buy',
            'pieces' => 1,
            'cash_delta' => -100,
            'balance_after' => 900,
        ]);

        $quotes = collect(['2026-11-02' => 110, '2026-11-03' => 111, '2026-11-04' => 112, '2026-11-05' => 113, '2026-11-06' => 114])
            ->map(fn (int $price, string $date): array => [
                'source_key' => 'xetra_posttrade', 'currency' => 'EUR', 'price' => $price, 'as_of' => $date.' 16:20:00',
            ])->values()->all();
        app(MarketDataCoordinator::class)->persist($holding, $quotes);
        app(MarketPriceStore::class)->selectLatest($holding->market_listing_id);

        $this->getJson('/admin/dashboard/performance')->assertOk()
            ->assertJsonPath('days.0.change_amount', null)
            ->assertJsonPath('days.1.change_amount', '1.00')
            ->assertJsonPath('sums.0.change_amount', null)
            ->assertJsonPath('sums.1.change_amount', null)
            ->assertJsonPath('sums.2.change_amount', null)
            ->assertJsonPath('sums.3.change_amount', null)
            ->assertJsonPath('sums.4.change_amount', '14.00');

        $chart = $this->chart();
        $prices = ['2026-09-30' => 92, '2026-10-23' => 96, '2026-10-30' => 100, '2026-11-05' => 999];
        $chart['chart']['result'][0]['timestamp'] = array_map(
            fn (string $date): int => Carbon::parse($date.' 08:00:00', 'UTC')->timestamp,
            array_keys($prices),
        );
        $chart['chart']['result'][0]['indicators'] = [
            'quote' => [array_fill_keys(['open', 'high', 'low', 'close'], array_values($prices))],
        ];
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($chart)]);

        $this->artisan('market-data:daily --days=70 --limit=3 --fill-gaps')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(
            ['2026-09-30', '2026-10-23', '2026-10-30'],
            MarketPrice::query()->where('source_key', 'yahoo_daily')->orderBy('trading_date')->pluck('trading_date')->all(),
        );
        $this->assertDatabaseHas('depot_performance_snapshots', [
            'depot_id' => $depot->id, 'period' => 'day', 'end_date' => '2026-11-05', 'expires_at' => null,
        ]);
        $this->getJson('/admin/dashboard/performance')->assertOk()
            ->assertJsonPath('days.0.change_amount', '10.00')
            ->assertJsonPath('days.1.change_amount', '1.00')
            ->assertJsonPath('days.2.change_amount', '1.00')
            ->assertJsonPath('days.3.change_amount', '1.00')
            ->assertJsonPath('days.4.change_amount', '1.00')
            ->assertJsonPath('sums.0.change_amount', '14.00')
            ->assertJsonPath('sums.1.change_amount', '4.00')
            ->assertJsonPath('sums.2.change_amount', '14.00')
            ->assertJsonPath('sums.3.change_amount', '8.00')
            ->assertJsonPath('sums.4.change_amount', '14.00');

        $this->artisan('market-data:daily --days=70 --limit=3 --fill-gaps')->assertSuccessful();
        $this->assertSame('up_to_date', app(FreeDailyMarketData::class)->import($holding, 2, fillGaps: true)['status']);
        Http::assertSentCount(1);
        $this->assertSame(3, MarketPrice::query()->where('source_key', 'yahoo_daily')->count());
    }

    public function test_daily_endpoint_requires_admin_and_new_holdings_have_a_listing_without_fetching(): void
    {
        $holding = $this->holding();
        $this->assertNotNull($holding->market_listing_id);
        $this->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertForbidden();
        $user->assignRole(Role::findOrCreate('admin'));
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart())]);
        $this->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertOk()->assertJsonPath('stored_count', 1);
        $this->assertSame(1, MarketPrice::count());
    }

    public function test_data_consistency_checks_a_year_of_daily_history_and_only_fetches_on_repair(): void
    {
        config(['queue.default' => 'database']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $chart = $this->chart();
        $chart['chart']['result'][0]['timestamp'][1] = Carbon::parse('2025-11-06 08:00:00', 'UTC')->timestamp;
        $chart['chart']['result'][0]['indicators']['quote'][0] = array_map(fn ($values) => [$values[0], $values[0]], $chart['chart']['result'][0]['indicators']['quote'][0]);
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($chart)]);

        $holdingId = $this->actingAs($admin)->postJson('/admin/watchlist/holdings', [
            'symbol' => 'TKE', 'isin' => 'US8740541094', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR',
        ])->assertCreated()->json('holding.id');

        $this->assertSame(0, Queue::size());
        $this->artisan('market-data:daily --days=7 --initialized-only')->assertSuccessful();
        Http::assertNothingSent();

        StockHolding::factory()->create(['symbol' => 'UNMAPPED', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $this->postJson('/admin/data/performance-consistency', ['repair' => false])->assertOk()->assertJsonPath('run.status', 'queued');
        $this->assertSame(1, Queue::size());
        Queue::pop()->fire();
        $checked = $this->getJson('/admin/data/performance-consistency')->assertOk()
            ->assertJsonPath('run.daily_history.0.holding_id', $holdingId)
            ->assertJsonPath('run.daily_history.0.from', '2025-11-06')
            ->assertJsonPath('run.daily_history.0.status', 'missing')
            ->assertJsonPath('run.daily_history.1.status', 'unsupported')->json('run.daily_history.0');
        $this->assertSame($checked['expected_count'], $checked['missing_count']);
        Http::assertNothingSent();

        $this->postJson('/admin/data/performance-consistency', ['repair' => true])->assertOk()->assertJsonPath('run.status', 'queued');
        Http::assertNothingSent();
        $this->assertSame(1, Queue::size());
        Queue::pop()->fire();

        Http::assertSent(fn ($request) => (int) $request['period1'] === Carbon::parse('2025-11-06', 'Europe/Berlin')->timestamp
            && $request['interval'] === '1d');
        $this->assertSame(['2025-11-06', '2026-11-05'], MarketPrice::query()->where('source_key', 'yahoo_daily')->orderBy('trading_date')->pluck('trading_date')->all());
        $this->assertDatabaseHas('market_data_runs', ['source_key' => 'yahoo_daily', 'status' => 'partial']);
        $this->getJson('/admin/data/performance-consistency')->assertOk()
            ->assertJsonPath('run.daily_history.0.filled_count', 2)
            ->assertJsonPath('run.daily_history.0.missing_count', $checked['missing_count'] - 2)
            ->assertJsonPath('run.daily_history.1.status', 'unsupported');
        Http::assertSentCount(1);
    }
}
