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
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'currency' => 'EUR',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'latest_price' => '499.25000000',
        ]);
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([
                [
                    'timestamp' => Carbon::parse('2026-06-25 07:00:00', 'UTC')->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => '2026-06-25 07:00:00',
                    'open' => 498.1,
                    'high' => 499.2,
                    'low' => 497.9,
                    'close' => 499.15,
                    'volume' => 1200,
                ],
                [
                    'timestamp' => Carbon::parse('2026-06-25 07:05:00', 'UTC')->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => '2026-06-25 07:05:00',
                    'open' => 499.15,
                    'high' => 500.1,
                    'low' => 498.8,
                    'close' => 499.9,
                    'volume' => 1400,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/tests/stocks/{$holding->id}/intraday")
            ->assertOk()
            ->assertJsonPath('stock.symbol', 'AMES')
            ->assertJsonPath('day.trading_date', '2026-06-25')
            ->assertJsonPath('day.interval', '5m')
            ->assertJsonCount(2, 'day.rows')
            ->assertJsonPath('day.rows.0.open', '498.10000000')
            ->assertJsonPath('day.rows.0.close', '499.15000000')
            ->assertJsonPath('day.rows.0.trading_date', '2026-06-25')
            ->assertJsonPath('day.rows.0.interval', '5m')
            ->assertJsonPath('day.rows.0.as_of', '2026-06-25 09:00:00')
            ->assertJsonPath('day.rows.0.currency', 'EUR')
            ->assertJsonPath('day.rows.0.source_key', 'eodhd_intraday')
            ->assertJsonPath('day.rows.0.source_name', 'EODHD intraday')
            ->assertJsonPath('day.rows.0.raw_payload.close', 499.15)
            ->assertJsonPath('day.rows.1.close', '499.90000000')
            ->assertJsonPath('refresh.status', 'finished')
            ->assertJsonPath('refresh.stored_count', 2);

        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-25',
            'interval' => '5m',
            'datetime' => '2026-06-25 07:05:00',
            'close' => '499.90000000',
            'source_name' => 'EODHD intraday',
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/AMES.XETRA')
            && $request['api_token'] === 'test-token'
            && $request['fmt'] === 'json'
            && $request['interval'] === '5m'
            && is_numeric($request['from'])
            && is_numeric($request['to']));
    }

    public function test_admin_receives_intraday_errors_for_selected_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
        ]);
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([], 500),
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/tests/stocks/{$holding->id}/intraday")
            ->assertStatus(422)
            ->assertJsonPath('message', 'EODHD intraday request failed with HTTP 500.')
            ->assertJsonPath('stock.symbol', 'AMES')
            ->assertJsonPath('day.trading_date', '2026-06-25');
    }

    public function test_guest_cannot_load_selected_stock_intraday_values(): void
    {
        $holding = StockHolding::factory()->create();

        $this->getJson("/admin/tests/stocks/{$holding->id}/intraday")->assertUnauthorized();
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
            ->getJson('/admin/tests/tickers')
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
            ->getJson('/admin/tests/exchanges')
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
        $this->getJson('/admin/tests/tickers')->assertUnauthorized();
    }

    public function test_guest_cannot_load_test_exchanges(): void
    {
        $this->getJson('/admin/tests/exchanges')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
