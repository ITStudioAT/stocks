<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTestsOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.market_data.free_only' => false, 'services.eodhd.enabled' => true]);
    }

    public function test_admin_can_list_test_options(): void
    {
        $admin = $this->adminUser();
        IndexWatchItem::factory()->create([
            'symbol' => 'SPX',
            'name' => 'S&P 500',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'DAX',
            'name' => null,
        ]);
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple',
            'subtitle' => 'Core technology holding',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'name' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/tests/options')
            ->assertOk()
            ->assertJsonCount(2, 'indices')
            ->assertJsonCount(2, 'stocks')
            ->assertJsonPath('indices.0.name', 'DAX')
            ->assertJsonPath('indices.0.symbol', 'DAX')
            ->assertJsonPath('indices.1.name', 'S&P 500')
            ->assertJsonPath('indices.1.symbol', 'SPX')
            ->assertJsonPath('stocks.0.name', 'Apple')
            ->assertJsonPath('stocks.0.subtitle', 'Core technology holding')
            ->assertJsonPath('stocks.0.symbol', 'AAPL')
            ->assertJsonPath('stocks.1.name', 'MSFT')
            ->assertJsonPath('stocks.1.symbol', 'MSFT');
    }

    public function test_guest_cannot_list_test_options(): void
    {
        $this->getJson('/admin/tests/options')->assertUnauthorized();
    }

    public function test_admin_can_load_selected_stock_today_intraday_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create(['symbol' => 'AMES', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $this->fakeEuropeanTrades([['holding' => $holding, 'price' => 499.9, 'at' => '2026-06-25 07:05:00']]);
        $this->actingAs($admin)->postJson("/admin/tests/stocks/{$holding->id}/intraday")->assertOk()
            ->assertJsonPath('day.interval', 'xtrade')->assertJsonCount(1, 'day.rows')
            ->assertJsonPath('day.rows.0.interval', 'xtrade')->assertJsonPath('day.rows.0.open', null)
            ->assertJsonPath('day.rows.0.close', '499.90000000')->assertJsonPath('day.rows.0.currency', 'EUR')
            ->assertJsonPath('day.rows.0.source_key', 'xetra_posttrade')
            ->assertJsonPath('day.rows.0.raw_payload.coverage_complete', true)
            ->assertJsonPath('refresh.status', 'finished')->assertJsonPath('refresh.stored_count', 1);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_admin_receives_intraday_errors_for_selected_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create(['symbol' => 'AMES', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        Http::fake(['https://mfs.deutsche-boerse.com/api/DETR-posttrade' => Http::response('', 503)]);
        $this->actingAs($this->adminUser())->postJson("/admin/tests/stocks/{$holding->id}/intraday")
            ->assertStatus(422)->assertJsonPath('stock.symbol', 'AMES')
            ->assertJsonPath('message', 'No observations in requested free intraday window; coverage incomplete, no prices fabricated.');
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
    }

    public function test_admin_receives_no_trading_status_for_fully_checked_empty_files(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create(['symbol' => 'AMES', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $this->fakeEuropeanTrades([]);
        $this->actingAs($this->adminUser())->postJson("/admin/tests/stocks/{$holding->id}/intraday")->assertOk()
            ->assertJsonPath('refresh.status', 'finished')->assertJsonPath('refresh.stored_count', 0)
            ->assertJsonPath('refresh.instrument_coverage.0.status', 'no_trades');
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
    }

    public function test_guest_cannot_load_selected_stock_intraday_values(): void
    {
        $holding = StockHolding::factory()->create();

        $this->postJson("/admin/tests/stocks/{$holding->id}/intraday")->assertUnauthorized();
    }

    public function test_admin_can_load_xetra_tickers_from_eodhd(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/exchange-symbol-list/XETRA*' => Http::response([
                [
                    'Code' => 'AMES',
                    'Name' => 'Amundi IBEX 35 UCITS ETF Acc',
                    'Exchange' => 'XETRA',
                    'Type' => 'ETF',
                    'Currency' => 'EUR',
                    'Isin' => 'LU1681043599',
                ],
            ]),
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/tests/tickers')
            ->assertOk()
            ->assertJsonPath('exchange_code', 'XETRA')
            ->assertJsonPath('tickers.0.Code', 'AMES')
            ->assertJsonPath('tickers.0.Name', 'Amundi IBEX 35 UCITS ETF Acc');

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/exchange-symbol-list/XETRA?',
        )
            && $request['api_token'] === 'test-token'
            && $request['fmt'] === 'json');
    }

    public function test_admin_can_load_exchanges_from_eodhd(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/exchanges-list/*' => Http::response([
                [
                    'Code' => 'BA',
                    'Name' => 'Buenos Aires Exchange',
                    'Country' => 'Argentina',
                    'Currency' => 'ARS',
                    'Timezone' => 'America/Argentina/Buenos_Aires',
                    'OperatingMIC' => 'XBUE',
                ],
                [
                    'Code' => 'NASDAQ',
                    'Name' => 'NASDAQ',
                    'Country' => 'USA',
                    'Currency' => 'USD',
                    'Timezone' => 'America/New_York',
                    'OperatingMIC' => 'XNAS',
                ],
            ]),
            'eodhd.com/api/v2/exchange-details/XBUE*' => Http::response([
                'data' => [
                    'Code' => 'XBUE',
                    'Name' => 'Buenos Aires Stock Exchange (BYMA)',
                    'TradingHours' => [
                        'Open' => '11:00:00',
                    ],
                    'ExchangeHolidays' => [
                        '2026-12-24' => [
                            'Holiday' => 'Christmas Eve',
                            'Type' => 'EarlyClose',
                            'EarlyClose' => '13:00:00',
                        ],
                    ],
                ],
            ]),
            'eodhd.com/api/v2/exchange-details/XNAS*' => Http::response([
                'Code' => 'XNAS',
                'Name' => 'NASDAQ details',
                'TradingHours' => '09:30-16:00',
            ]),
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/tests/exchanges')
            ->assertOk()
            ->assertJsonPath('exchanges.0.Code', 'BA')
            ->assertJsonPath('exchanges.0.Name', 'Buenos Aires Exchange')
            ->assertJsonPath('exchanges.0.Country', 'Argentina')
            ->assertJsonPath('exchanges.0.exchange_detail_code', 'XBUE')
            ->assertJsonPath('exchanges.1.Code', 'NASDAQ')
            ->assertJsonPath('exchanges.1.exchange_detail_code', 'XNAS')
            ->assertJsonPath('exchange_details.XBUE.Name', 'Buenos Aires Stock Exchange (BYMA)')
            ->assertJsonPath('exchange_details.XBUE.ExchangeHolidays.2026-12-24.EarlyClose', '13:00:00')
            ->assertJsonPath('exchange_details.XNAS.Name', 'NASDAQ details');

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/exchanges-list/?',
        )
            && $request['api_token'] === 'test-token'
            && $request['fmt'] === 'json');
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/v2/exchange-details/XBUE?',
        )
            && $request['api_token'] === 'test-token');
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/v2/exchange-details/XNAS?',
        )
            && $request['api_token'] === 'test-token');
    }

    public function test_guest_cannot_load_test_tickers(): void
    {
        $this->postJson('/admin/tests/tickers')->assertUnauthorized();
    }

    public function test_guest_cannot_load_test_exchanges(): void
    {
        $this->postJson('/admin/tests/exchanges')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
