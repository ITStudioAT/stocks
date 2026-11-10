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
use App\Services\YahooFinanceMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string, string, string}> */
    public static function newlyVerifiedXetraSymbols(): array
    {
        return [
            'Eastern Europe ETF' => ['LU1900066462', 'LEER', 'ETF'],
            'Semiconductors ETF' => ['IE000I8KRLL9', 'SEC0', 'ETF'],
            'Biotechnology ETF' => ['IE00BYXG2H39', '2B70', 'ETF'],
            'Copper miners ETF' => ['IE00063FT9K6', 'CEBS', 'ETF'],
            'Defense ETF' => ['IE000YYE6WK5', 'DFEN', 'ETF'],
            'Insurance ETF' => ['DE000A0H08K7', 'EXH5', 'ETF'],
            'Tesla Xetra' => ['US88160R1014', 'TL0', 'EQUITY'],
            'Artificial intelligence ETF' => ['IE00BGV5VN51', 'XAIX', 'ETF'],
        ];
    }

    #[DataProvider('newlyVerifiedXetraSymbols')]
    public function test_verified_xetra_instruments_can_import_free_daily_history(string $isin, string $symbol, string $type): void
    {
        $holding = StockHolding::factory()->create([
            'isin' => $isin, 'symbol' => $symbol, 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR',
        ]);
        $chart = $this->chart();
        $chart['chart']['result'][0]['meta']['symbol'] = "{$symbol}.DE";
        $chart['chart']['result'][0]['meta']['instrumentType'] = $type;
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($chart)]);

        $this->assertTrue(YahooFinanceMarketData::supportsXetra($holding->toArray()));
        $this->assertDatabaseHas('market_source_instruments', [
            'market_listing_id' => $holding->market_listing_id, 'source_key' => 'yahoo', 'symbol' => "{$symbol}.DE",
        ]);
        $result = app(FreeDailyMarketData::class)->import($holding, 2);

        $this->assertSame(1, $result['stored_count']);
        $this->assertDatabaseHas('market_prices', [
            'market_listing_id' => $holding->market_listing_id, 'source_key' => 'yahoo_daily',
            'trading_date' => '2026-11-05', 'currency' => 'EUR', 'interval_code' => '1d',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), "/{$symbol}.DE"));
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

    public function test_missing_settled_daily_bars_are_imported_even_when_intraday_prices_exist(): void
    {
        $holding = $this->holding();
        app(MarketDataCoordinator::class)->persist($holding, [[
            'source_key' => 'xetra_posttrade', 'currency' => 'EUR', 'price' => 195,
            'as_of' => '2026-11-06 16:20:00',
        ]]);
        $chart = $this->chart();
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $chart['chart']['result'][0]['indicators']['quote'][0][$field][1] = 195;
        }
        $chart['chart']['result'][0]['indicators']['adjclose'][0]['adjclose'][1] = 195;
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($chart)]);
        Cache::put('market-data.daily.checked.'.$holding->market_listing_id, true, now()->addHours(24));

        $this->artisan('market-data:daily --days=3 --limit=3 --missing-settled')->assertSuccessful();
        $this->assertDatabaseHas('market_prices', [
            'market_listing_id' => $holding->refresh()->market_listing_id,
            'source_key' => 'yahoo_daily', 'interval_code' => '1d', 'trading_date' => '2026-11-06',
        ]);
        Http::assertSentCount(1);

        $this->travel(7)->hours();
        $this->artisan('market-data:daily --days=3 --limit=3 --missing-settled')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_missing_daily_bar_waits_until_the_trading_session_has_closed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 18:00:00', 'UTC'));
        $this->holding();
        $chart = $this->chart();
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $chart['chart']['result'][0]['indicators']['quote'][0][$field][1] = 195;
        }
        $chart['chart']['result'][0]['indicators']['adjclose'][0]['adjclose'][1] = 195;
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($chart)]);

        $this->artisan('market-data:daily --days=3 --missing-settled')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travelTo(Carbon::parse('2026-11-06 14:00:00', 'UTC'));
        $this->artisan('market-data:daily --days=3 --missing-settled')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('market_prices', ['source_key' => 'yahoo_daily', 'trading_date' => '2026-11-06']);

        $this->travelTo(Carbon::parse('2026-11-06 17:01:00', 'UTC'));
        $this->artisan('market-data:daily --days=3 --missing-settled')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('market_prices', ['source_key' => 'yahoo_daily', 'trading_date' => '2026-11-06']);
    }

    public function test_missing_daily_bar_retries_provider_failures_with_a_backoff(): void
    {
        $this->holding();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response([], 503)]);

        $this->artisan('market-data:daily --days=3 --missing-settled')->assertFailed();
        $this->travel(15)->minutes();
        $this->artisan('market-data:daily --days=3 --missing-settled')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(15)->minutes();
        $this->artisan('market-data:daily --days=3 --missing-settled')->assertFailed();
        Http::assertSentCount(2);
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
