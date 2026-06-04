<?php

namespace Tests\Feature;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Mail\WatchlistPriceRefreshReportMail;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\StockPriceCatalog;
use App\Services\WatchlistPdfReport;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\WebMarketDataOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDepotHoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_watchlist_holdings_with_pagination(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->count(12)->create();

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?page=2')
            ->assertOk()
            ->assertJsonPath('depot', null)
            ->assertJsonCount(2, 'holdings')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonStructure([
                'holdings' => [
                    [
                        'id',
                        'symbol',
                        'name',
                        'isin',
                        'wkn',
                        'exchange',
                        'mic_code',
                        'instrument_type',
                        'country',
                        'currency',
                        'latest_price',
                        'start_price',
                        'end_price',
                        'latest_price_trend',
                        'latest_price_change_pct',
                        'latest_price_tick_trend',
                        'latest_price_status',
                        'price_status',
                        'latest_price_fetched_at',
                        'latest_price_source',
                        'latest_price_source_url',
                        'latest_price_as_of',
                        'trading_times',
                        'venue',
                        'price_type',
                        'price_spread_pct',
                        'recent_prices',
                        'validation_errors',
                        'created_at',
                    ],
                ],
            ]);
    }

    public function test_admin_listing_hides_stale_holding_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 08:30:00'));
        StockHolding::factory()->create([
            'symbol' => 'LEER',
            'latest_price' => '43.370000',
            'latest_price_as_of' => '2026-06-01 11:10:33',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Rome',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LEER')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null)
            ->assertJsonPath('holdings.0.venue', null)
            ->assertJsonPath('holdings.0.price_type', null);
    }

    public function test_admin_listing_hides_date_only_stale_holding_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 12:00:00'));
        StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'latest_price' => '26.990000',
            'latest_price_as_of' => '03.06.2026',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'EXXX')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null)
            ->assertJsonPath('holdings.0.venue', null)
            ->assertJsonPath('holdings.0.price_type', null);
    }

    public function test_admin_listing_hides_source_time_for_unavailable_holding_prices(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'LYMH',
            'latest_price' => null,
            'latest_price_fetched_at' => '2026-06-03 05:40:55',
            'latest_price_as_of' => '03.06.2026 05:32:08 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYMH')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'unavailable')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null);
    }

    public function test_admin_listing_includes_trading_session_start_and_end_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '191.500000',
            'latest_price_fetched_at' => '2026-06-04 08:20:00',
            'latest_price_as_of' => '2026-06-04 08:15:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'closed_market',
            'latest_price_type' => 'last',
        ]);
        // Berlin (CEST, +02:00): previous day 17:45 (end), today 08:30 (pre-open), today 09:10 (start).
        $this->createMedianQuote($holding, '2026-06-03 15:45:00', '193.00');
        $this->createMedianQuote($holding, '2026-06-04 06:30:00', '190.00');
        $this->createMedianQuote($holding, '2026-06-04 07:10:00', '191.00');
        $this->createMedianQuote($holding, '2026-06-04 08:15:00', '191.50');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYXIB')
            ->assertJsonPath('holdings.0.start_price', '191.00000000')
            ->assertJsonPath('holdings.0.end_price', '193.00000000')
            ->assertJsonPath('holdings.0.latest_price_trend', 'up')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '0.26');
    }

    public function test_admin_listing_serializes_stored_stock_price_source_time_as_utc(): void
    {
        $admin = $this->adminUser();
        $stockPrice = StockPrice::query()->create([
            'instrument_key' => 'isin:FR0010251744',
            'quote_hash' => hash('sha256', 'lyxib-source-time'),
            'source_key' => 'calculated_median',
            'source_name' => '4 quotes',
            'source_url' => 'https://example.com/lyxib',
            'source_quality' => 'calculated',
            'venue' => 'Madrid SIBE',
            'isin' => 'FR0010251744',
            'wkn' => 'LYX0A6',
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'price' => '191.00000000',
            'price_type' => 'calculated_median',
            'as_of' => Carbon::parse('2026-06-03 15:35:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-03 17:10:00', 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
        ]);

        StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'isin' => 'FR0010251744',
            'wkn' => 'LYX0A6',
            'exchange' => 'Madrid',
            'currency' => 'EUR',
            'latest_stock_price_id' => $stockPrice->id,
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-03T15:35:00+00:00');
    }

    public function test_admin_listing_compares_latest_price_to_previous_stored_price(): void
    {
        $admin = $this->adminUser();

        $upHolding = StockHolding::factory()->create([
            'name' => 'A Up',
            'symbol' => 'UP',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createMedianQuote($upHolding, '2026-06-03 10:00:00', '100.00');
        $upLatestPrice = $this->createMedianQuote($upHolding, '2026-06-03 10:20:00', '101.00');
        $upHolding->update(['latest_stock_price_id' => $upLatestPrice->id]);

        $downHolding = StockHolding::factory()->create([
            'name' => 'B Down',
            'symbol' => 'DOWN',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createMedianQuote($downHolding, '2026-06-03 10:00:00', '100.00');
        $downLatestPrice = $this->createMedianQuote($downHolding, '2026-06-03 10:20:00', '99.00');
        $downHolding->update(['latest_stock_price_id' => $downLatestPrice->id]);

        $flatHolding = StockHolding::factory()->create([
            'name' => 'C Flat',
            'symbol' => 'FLAT',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createMedianQuote($flatHolding, '2026-06-03 10:00:00', '100.00');
        $flatLatestPrice = $this->createMedianQuote($flatHolding, '2026-06-03 10:20:00', '100.00');
        $flatHolding->update(['latest_stock_price_id' => $flatLatestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price_tick_trend', 'up')
            ->assertJsonPath('holdings.1.latest_price_tick_trend', 'down')
            ->assertJsonPath('holdings.2.latest_price_tick_trend', 'flat');
    }

    public function test_admin_listing_compares_latest_price_to_previous_stored_price_with_same_source_time(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'symbol' => 'LYXIB',
            'isin' => 'FR0010251744',
            'currency' => 'EUR',
            'price_status' => 'closed_market',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
        ]);

        $this->createMedianQuote($holding, '2026-06-03 15:35:00', '191.00');
        $latestPrice = $this->createMedianQuote($holding, '2026-06-03 15:35:00', '191.04');
        $holding->update(['latest_stock_price_id' => $latestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', '191.040000')
            ->assertJsonPath('holdings.0.latest_price_tick_trend', 'up');
    }

    public function test_admin_listing_includes_recent_stored_prices_from_last_24_hours(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'RECENT',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->createMedianQuote($holding, '2026-06-02 17:50:00', '98.00');
        $this->createMedianQuote($holding, '2026-06-03 12:00:00', '100.00');
        $this->createMedianQuote($holding, '2026-06-03 12:00:00', '100.25');
        StockPrice::query()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|raw-source|2026-06-03 13:00:00"),
            'source_key' => 'tradegate',
            'source_name' => 'Tradegate',
            'source_url' => 'https://example.com/raw',
            'source_quality' => 'official_venue',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => '100.50000000',
            'price_type' => 'indicative_mid',
            'as_of' => Carbon::parse('2026-06-03 13:00:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-03 13:00:00', 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
        ]);
        $latestPrice = $this->createMedianQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_stock_price_id' => $latestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.recent_prices.0.price', '100.00000000')
            ->assertJsonPath('holdings.0.recent_prices.1.price', '100.25000000')
            ->assertJsonPath('holdings.0.recent_prices.2.price', '101.00000000')
            ->assertJsonMissingPath('holdings.0.recent_prices.3');
    }

    public function test_admin_listing_falls_back_to_latest_price_when_session_has_no_close_quote(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 13:00:00'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '195.250000',
            'latest_price_fetched_at' => '2026-06-03 12:30:00',
            'latest_price_as_of' => '2026-06-03 12:00:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);
        // Mid-session only (Berlin 09:10 and 14:00); the market has not closed yet.
        $this->createMedianQuote($holding, '2026-06-03 07:10:00', '191.00');
        $this->createMedianQuote($holding, '2026-06-03 12:00:00', '195.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.start_price', '191.00000000')
            ->assertJsonPath('holdings.0.end_price', '195.250000')
            ->assertJsonPath('holdings.0.latest_price_trend', 'up')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '2.23');
    }

    public function test_admin_listing_falls_back_to_latest_price_when_session_has_no_quotes(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 13:00:00'));
        StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '50.000000',
            'latest_price_fetched_at' => '2026-06-03 12:30:00',
            'latest_price_as_of' => '2026-06-03 12:00:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.start_price', '50.000000')
            ->assertJsonPath('holdings.0.end_price', '50.000000')
            ->assertJsonPath('holdings.0.latest_price_trend', 'flat')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '0.00');
    }

    public function test_admin_can_add_a_selected_stock_holding(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-02 12:00:00'));
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturnUsing(function (StockHolding $holding): QuoteSelectionResult {
                    $holding->update([
                        'currency' => 'EUR',
                        'latest_price' => '123.456789',
                        'latest_price_fetched_at' => Carbon::parse('2026-06-02 12:00:00'),
                        'latest_price_source' => 'Tradegate Exchange',
                        'latest_price_source_url' => 'https://www.tradegatebsx.com/orderbuch.php?isin=US9229083632',
                        'latest_price_as_of' => '2026-06-02 11:59:00',
                        'trading_times' => 'Monday-Friday 08:00-22:00 Europe/Berlin',
                        'price_status' => 'fresh',
                        'latest_price_type' => 'indicative_mid',
                    ]);

                    return new QuoteSelectionResult(null, [], [], status: 'fresh');
                });
        });

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [
                'symbol' => 'aapl',
                'name' => 'Vanguard S&P 500 ETF',
                'isin' => 'us9229083632',
                'wkn' => 'A1JX53',
                'exchange' => 'NASDAQ',
                'mic_code' => 'xnas',
                'instrument_type' => 'ETF',
                'country' => 'United States',
                'currency' => 'usd',
            ])
            ->assertCreated()
            ->assertJsonPath('holding.symbol', 'AAPL')
            ->assertJsonPath('holding.name', 'Vanguard S&P 500 ETF')
            ->assertJsonPath('holding.isin', 'US9229083632')
            ->assertJsonPath('holding.wkn', 'A1JX53')
            ->assertJsonPath('holding.mic_code', 'XNAS')
            ->assertJsonPath('holding.currency', 'EUR')
            ->assertJsonPath('holding.latest_price', '123.456789')
            ->assertJsonPath('holding.latest_price_status', 'fresh')
            ->assertJsonPath('holding.latest_price_fetched_at', '2026-06-02T12:00:00+02:00')
            ->assertJsonPath('holding.latest_price_source', 'Tradegate Exchange')
            ->assertJsonPath('holding.latest_price_source_url', 'https://www.tradegatebsx.com/orderbuch.php?isin=US9229083632')
            ->assertJsonPath('holding.latest_price_as_of', '2026-06-02T11:59:00+02:00')
            ->assertJsonPath('holding.trading_times', 'Monday-Friday 08:00-22:00 Europe/Berlin')
            ->assertJsonPath('holding.price_type', 'indicative_mid');

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'AAPL',
            'name' => 'Vanguard S&P 500 ETF',
            'isin' => 'US9229083632',
            'wkn' => 'A1JX53',
            'exchange' => 'NASDAQ',
            'currency' => 'EUR',
            'latest_price' => '123.456789',
            'latest_price_fetched_at' => '2026-06-02 12:00:00',
            'latest_price_source' => 'Tradegate Exchange',
            'latest_price_source_url' => 'https://www.tradegatebsx.com/orderbuch.php?isin=US9229083632',
            'latest_price_as_of' => '2026-06-02 11:59:00',
            'trading_times' => 'Monday-Friday 08:00-22:00 Europe/Berlin',
            'latest_price_type' => 'indicative_mid',
        ]);
    }

    public function test_admin_can_add_a_selected_stock_holding_without_latest_price_access(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-02 13:00:00'));
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturn(new QuoteSelectionResult(null, [], [], status: 'unavailable'));
        });

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [
                'symbol' => 'exxx',
                'name' => 'iShares ATX UCITS ETF (DE)',
                'isin' => 'DE000A0D8Q23',
                'wkn' => 'A0D8Q2',
                'exchange' => 'XETRA',
                'mic_code' => 'xetr',
                'instrument_type' => 'ETF',
                'country' => 'Germany',
                'currency' => 'eur',
            ])
            ->assertCreated()
            ->assertJsonPath('holding.symbol', 'EXXX')
            ->assertJsonPath('holding.exchange', 'XETRA')
            ->assertJsonPath('holding.mic_code', 'XETR')
            ->assertJsonPath('holding.latest_price', null)
            ->assertJsonPath('holding.latest_price_status', 'missing')
            ->assertJsonPath('holding.latest_price_fetched_at', null)
            ->assertJsonPath('holding.latest_price_source', null)
            ->assertJsonPath('holding.latest_price_source_url', null)
            ->assertJsonPath('holding.trading_times', null);

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'EXXX',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'latest_price' => null,
            'latest_price_fetched_at' => null,
            'latest_price_source' => null,
            'latest_price_source_url' => null,
            'latest_price_as_of' => null,
            'trading_times' => null,
        ]);
    }

    public function test_admin_can_queue_all_watchlist_price_refreshes(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
        ]);
        $thirdHolding = StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'latest_price' => '300.000000',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '3 stock prices queued for refresh.')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.processed', 0)
            ->assertJsonPath('refresh.total', 3)
            ->assertJsonPath('refresh.step', '0/3');

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
        Queue::assertPushed(RefreshDepotHoldingPrices::class, fn (RefreshDepotHoldingPrices $job): bool => $job->refreshId === $refreshId
            && $job->recipientUserId === $admin->id);

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/refresh-prices/{$refreshId}")
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', $refreshId)
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.step', '0/3');

        $this->assertDatabaseHas('stock_holdings', [
            'id' => $thirdHolding->id,
            'latest_price' => '300.000000',
        ]);
    }

    public function test_admin_can_queue_watchlist_price_refreshes_without_an_active_depot(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'EXXX',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '1 stock price queued for refresh.')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 1);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
    }

    public function test_queued_job_refreshes_watchlist_prices_and_tracks_progress(): void
    {
        Mail::fake();
        $apple = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'wkn' => '865985',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'instrument_type' => 'Common Stock',
            'country' => 'United States',
            'currency' => 'USD',
            'latest_price' => '100.000000',
        ]);
        $microsoft = StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corporation',
            'isin' => 'US5949181045',
            'wkn' => '870747',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'instrument_type' => 'Common Stock',
            'country' => 'United States',
            'currency' => 'USD',
            'latest_price' => '200.000000',
            'latest_price_fetched_at' => '2026-06-02 14:30:00',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/msft',
            'latest_price_as_of' => '2026-06-02 14:29 UTC',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturnUsing(function (StockHolding $holding): QuoteSelectionResult {
                    $holding->update([
                        'currency' => 'EUR',
                        'latest_price' => '306.320010',
                        'latest_price_fetched_at' => Carbon::parse('2026-06-02 15:00:00'),
                        'latest_price_source' => 'Tradegate Exchange',
                        'latest_price_source_url' => 'https://www.tradegatebsx.com/orderbuch.php?isin=US0378331005',
                        'latest_price_as_of' => '2026-06-02 14:59:00',
                        'trading_times' => 'Monday-Friday 08:00-22:00 Europe/Berlin',
                        'price_status' => 'fresh',
                        'latest_price_type' => 'indicative_mid',
                    ]);

                    return new QuoteSelectionResult(null, [], [], status: 'fresh');
                });
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturn(new QuoteSelectionResult(null, [], [], status: 'unavailable'));
        });
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-test', 2);

        (new RefreshDepotHoldingPrices('refresh-test'))->handle(
            app(WebMarketDataOrchestrator::class),
            $progress,
            app(WatchlistPdfReport::class),
        );

        $this->assertDatabaseHas('stock_holdings', [
            'id' => $apple->id,
            'currency' => 'EUR',
            'latest_price' => '306.320010',
            'latest_price_fetched_at' => '2026-06-02 15:00:00',
            'latest_price_source' => 'Tradegate Exchange',
            'latest_price_source_url' => 'https://www.tradegatebsx.com/orderbuch.php?isin=US0378331005',
            'latest_price_as_of' => '2026-06-02 14:59:00',
            'trading_times' => 'Monday-Friday 08:00-22:00 Europe/Berlin',
            'latest_price_type' => 'indicative_mid',
        ]);
        $this->assertDatabaseHas('stock_holdings', [
            'id' => $microsoft->id,
            'currency' => 'USD',
            'latest_price' => '200.000000',
            'latest_price_fetched_at' => '2026-06-02 14:30:00',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/msft',
            'latest_price_as_of' => '2026-06-02 14:29 UTC',
        ]);
        $this->assertSame('finished', $progress->get('refresh-test')['status']);
        $this->assertSame('2/2', $progress->get('refresh-test')['step']);
    }

    public function test_completed_manual_refresh_sends_watchlist_pdf_to_logged_in_admin(): void
    {
        Mail::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'MAILPDF',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturn(new QuoteSelectionResult(null, [], [], status: 'fresh'));
        });
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-mail-test', 1);

        (new RefreshDepotHoldingPrices('refresh-mail-test', $admin->id))->handle(
            app(WebMarketDataOrchestrator::class),
            $progress,
            app(WatchlistPdfReport::class),
        );

        Mail::assertSent(WatchlistPriceRefreshReportMail::class, function (WatchlistPriceRefreshReportMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email)
                && str_starts_with($mail->pdfContent, '%PDF')
                && str_starts_with($mail->filename, 'watch-list-')
            && str_ends_with($mail->filename, '.pdf');
        });
    }

    public function test_refresh_still_finishes_when_report_email_cannot_be_sent(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'MAILFAIL',
            'currency' => 'EUR',
            'price_status' => 'fresh',
        ]);
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturn(new QuoteSelectionResult(null, [], [], status: 'fresh'));
        });
        Mail::shouldReceive('to')
            ->once()
            ->with($admin->email)
            ->andThrow(new RuntimeException('SMTP is down.'));
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-mail-fail-test', 1);

        (new RefreshDepotHoldingPrices('refresh-mail-fail-test', $admin->id))->handle(
            app(WebMarketDataOrchestrator::class),
            $progress,
            app(WatchlistPdfReport::class),
        );

        $this->assertSame('finished', $progress->get('refresh-mail-fail-test')['status']);
        $this->assertSame('1/1', $progress->get('refresh-mail-fail-test')['step']);
    }

    public function test_admin_must_provide_a_selected_symbol(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('symbol');
    }

    public function test_admin_can_delete_a_watchlist_holding(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/watchlist/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Stock deleted.');

        $this->assertModelMissing($holding);
    }

    public function test_admin_can_delete_a_watchlist_holding_without_an_active_depot(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/watchlist/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Stock deleted.');

        $this->assertModelMissing($holding);
    }

    public function test_admin_can_list_watchlist_holdings_without_an_active_depot(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('depot', null)
            ->assertJsonPath('holdings.0.symbol', 'AAPL');
    }

    public function test_admin_can_export_watchlist_pdf(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'PDFCO',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $latestPrice = $this->createMedianQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_stock_price_id' => $latestPrice->id]);

        $response = $this->actingAs($admin)->get('/admin/watchlist/holdings/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('watch-list-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_admin_can_export_watchlist_pdf_without_holdings(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get('/admin/watchlist/holdings/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_guest_cannot_manage_holdings(): void
    {
        $this->getJson('/admin/watchlist/holdings')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/pdf')->assertUnauthorized();
        $this->postJson('/admin/watchlist/holdings', ['symbol' => 'AAPL'])->assertUnauthorized();
        $this->postJson('/admin/watchlist/holdings/refresh-prices')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/refresh-prices/example')->assertUnauthorized();
        $this->deleteJson('/admin/watchlist/holdings/1')->assertUnauthorized();
    }

    private function createMedianQuote(StockHolding $holding, string $asOf, string $price): StockPrice
    {
        return StockPrice::query()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|{$asOf}|{$price}"),
            'source_key' => 'calculated_median',
            'source_name' => '2 quotes',
            'source_url' => 'https://example.com',
            'source_quality' => 'calculated',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'price_type' => 'indicative_mid',
            'as_of' => Carbon::parse($asOf, 'UTC'),
            'fetched_at' => Carbon::parse($asOf, 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
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
