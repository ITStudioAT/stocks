<?php

namespace Tests\Feature;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\WebMarketDataOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDepotHoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_active_depot_holdings_with_pagination(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'name' => 'Main depot',
            'is_active' => true,
        ]);
        StockHolding::factory()->count(12)->create([
            'depot_id' => $depot->id,
        ]);
        StockHolding::factory()->create();

        $this->actingAs($admin)
            ->getJson('/admin/active-depot/holdings?page=2')
            ->assertOk()
            ->assertJsonPath('depot.name', 'Main depot')
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
                        'validation_errors',
                        'created_at',
                    ],
                ],
            ]);
    }

    public function test_admin_listing_hides_stale_holding_prices(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-06-03 08:30:00'));
        StockHolding::factory()->create([
            'depot_id' => $depot->id,
            'symbol' => 'LEER',
            'latest_price' => '43.370000',
            'latest_price_as_of' => '2026-06-01 11:10:33',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Rome',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/active-depot/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LEER')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-01 11:10:33');
    }

    public function test_admin_listing_hides_date_only_holding_prices(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-06-03 12:00:00'));
        StockHolding::factory()->create([
            'depot_id' => $depot->id,
            'symbol' => 'EXXX',
            'latest_price' => '26.990000',
            'latest_price_as_of' => '03.06.2026',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/active-depot/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'EXXX')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_as_of', '03.06.2026');
    }

    public function test_admin_listing_hides_source_time_for_unavailable_holding_prices(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        StockHolding::factory()->create([
            'depot_id' => $depot->id,
            'symbol' => 'LYMH',
            'latest_price' => null,
            'latest_price_fetched_at' => '2026-06-03 05:40:55',
            'latest_price_as_of' => '03.06.2026 05:32:08 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/active-depot/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYMH')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'unavailable')
            ->assertJsonPath('holdings.0.latest_price_as_of', null);
    }

    public function test_admin_can_add_a_selected_stock_holding(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
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
            ->postJson('/admin/active-depot/holdings', [
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
            ->assertJsonPath('holding.latest_price_fetched_at', '2026-06-02T12:00:00+00:00')
            ->assertJsonPath('holding.latest_price_source', 'Tradegate Exchange')
            ->assertJsonPath('holding.latest_price_source_url', 'https://www.tradegatebsx.com/orderbuch.php?isin=US9229083632')
            ->assertJsonPath('holding.latest_price_as_of', '2026-06-02 11:59:00')
            ->assertJsonPath('holding.trading_times', 'Monday-Friday 08:00-22:00 Europe/Berlin')
            ->assertJsonPath('holding.price_type', 'indicative_mid');

        $this->assertDatabaseHas('stock_holdings', [
            'depot_id' => $depot->id,
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
        Depot::factory()->create([
            'is_active' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-06-02 13:00:00'));
        $this->mock(WebMarketDataOrchestrator::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('resolve')
                ->once()
                ->andReturn(new QuoteSelectionResult(null, [], [], status: 'unavailable'));
        });

        $this->actingAs($admin)
            ->postJson('/admin/active-depot/holdings', [
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

    public function test_admin_can_queue_active_depot_holding_price_refresh(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        StockHolding::factory()->create([
            'depot_id' => $depot->id,
            'symbol' => 'AAPL',
        ]);
        StockHolding::factory()->create([
            'depot_id' => $depot->id,
            'symbol' => 'MSFT',
        ]);
        $inactiveHolding = StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'latest_price' => '300.000000',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/active-depot/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '2 stock prices queued for refresh.')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.processed', 0)
            ->assertJsonPath('refresh.total', 2)
            ->assertJsonPath('refresh.step', '0/2');

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushed(RefreshDepotHoldingPrices::class, fn (RefreshDepotHoldingPrices $job): bool => $job->depotId === $depot->id
            && $job->refreshId === $refreshId);

        $this->actingAs($admin)
            ->getJson("/admin/active-depot/holdings/refresh-prices/{$refreshId}")
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', $refreshId)
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.step', '0/2');

        $this->assertDatabaseHas('stock_holdings', [
            'id' => $inactiveHolding->id,
            'latest_price' => '300.000000',
        ]);
    }

    public function test_queued_job_refreshes_active_depot_holding_prices_and_tracks_progress(): void
    {
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        $apple = StockHolding::factory()->create([
            'depot_id' => $depot->id,
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
            'depot_id' => $depot->id,
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
        $progress->start('refresh-test', $depot->id, 2);

        (new RefreshDepotHoldingPrices($depot->id, 'refresh-test'))->handle(
            app(WebMarketDataOrchestrator::class),
            $progress,
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

    public function test_admin_must_provide_a_selected_symbol(): void
    {
        $admin = $this->adminUser();
        Depot::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/active-depot/holdings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('symbol');
    }

    public function test_admin_can_delete_an_active_depot_holding(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'depot_id' => $depot->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/active-depot/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Stock deleted.');

        $this->assertModelMissing($holding);
    }

    public function test_admin_cannot_delete_a_holding_from_an_inactive_depot(): void
    {
        $admin = $this->adminUser();
        Depot::factory()->create([
            'is_active' => true,
        ]);
        $inactiveDepot = Depot::factory()->create([
            'is_active' => false,
        ]);
        $holding = StockHolding::factory()->create([
            'depot_id' => $inactiveDepot->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/active-depot/holdings/{$holding->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('holding');

        $this->assertModelExists($holding);
    }

    public function test_admin_cannot_list_holdings_without_an_active_depot(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/active-depot/holdings')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('depot');
    }

    public function test_guest_cannot_manage_holdings(): void
    {
        $this->getJson('/admin/active-depot/holdings')->assertUnauthorized();
        $this->postJson('/admin/active-depot/holdings', ['symbol' => 'AAPL'])->assertUnauthorized();
        $this->postJson('/admin/active-depot/holdings/refresh-prices')->assertUnauthorized();
        $this->getJson('/admin/active-depot/holdings/refresh-prices/example')->assertUnauthorized();
        $this->deleteJson('/admin/active-depot/holdings/1')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
