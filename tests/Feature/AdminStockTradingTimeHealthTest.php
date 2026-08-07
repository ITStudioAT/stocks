<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStockTradingTimeHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.eodhd.key', 'test-token');
        config()->set('services.eodhd.base_url', 'https://eodhd.com/api');
    }

    public function test_admin_can_view_the_last_stock_trading_time_health_check(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/admin/data/health/stock-trading-times')
            ->assertOk()
            ->assertJsonPath('health_check.status', 'never')
            ->assertJsonPath('health_check.last_executed_at', null)
            ->assertJsonPath('health_check.summary.issue_stocks_count', 0);
    }

    public function test_admin_can_check_missing_and_broken_stock_trading_times_without_calling_eodhd(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 12:34:00', 'Europe/Vienna'));
        Http::preventStrayRequests();

        StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'trading_times' => 'Monday-Friday 09:30-16:00 America/New_York',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'ARGT',
            'trading_times' => null,
        ]);
        StockHolding::factory()->create([
            'symbol' => 'BROKEN',
            'trading_times' => 'Monday-Friday 25:00-17:30 Europe/Vienna',
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/data/health/stock-trading-times')
            ->assertOk()
            ->assertJsonPath('health_check.status', 'issues')
            ->assertJsonPath('health_check.last_executed_at', '2026-08-07T12:34:00+02:00')
            ->assertJsonPath('health_check.summary.total_stocks_count', 3)
            ->assertJsonPath('health_check.summary.healthy_stocks_count', 1)
            ->assertJsonPath('health_check.summary.missing_stocks_count', 1)
            ->assertJsonPath('health_check.summary.broken_stocks_count', 1)
            ->assertJsonPath('health_check.summary.issue_stocks_count', 2)
            ->assertJsonPath('health_check.issues.0.status', 'missing')
            ->assertJsonPath('health_check.issues.1.status', 'broken');

        $storedResult = AppConfig::query()
            ->where('key', 'data_health.stock_trading_times')
            ->firstOrFail()
            ->value;

        $this->assertSame(2, $storedResult['summary']['issue_stocks_count']);
        Http::assertNothingSent();
    }

    public function test_admin_can_repair_stock_trading_times_with_fresh_eodhd_exchange_requests(): void
    {
        StockHolding::factory()->create([
            'symbol' => 'EXSA',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'trading_times' => null,
        ]);
        StockHolding::factory()->create([
            'symbol' => 'ARGT',
            'exchange' => 'US',
            'mic_code' => 'ARCX',
            'country' => 'United States',
            'trading_times' => 'not a trading time',
        ]);
        Http::fake([
            'eodhd.com/api/exchange-details/XETRA*' => Http::response([
                'Code' => 'XETRA',
                'Timezone' => 'Europe/Berlin',
                'TradingHours' => [
                    'Open' => '09:00:00',
                    'Close' => '17:30:00',
                ],
            ]),
            'eodhd.com/api/exchange-details/US*' => Http::response([
                'Code' => 'US',
                'Timezone' => 'America/New_York',
                'TradingHours' => [
                    'Open' => '09:30:00',
                    'Close' => '16:00:00',
                ],
            ]),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/data/health/stock-trading-times/repair')
            ->assertOk()
            ->assertJsonPath('health_check.status', 'healthy')
            ->assertJsonPath('health_check.summary.healthy_stocks_count', 2)
            ->assertJsonPath('health_check.summary.issue_stocks_count', 0)
            ->assertJsonPath('health_check.repair.attempted_stocks_count', 2)
            ->assertJsonPath('health_check.repair.repaired_stocks_count', 2)
            ->assertJsonPath('health_check.repair.failed_stocks_count', 0);

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'EXSA',
            'trading_times' => 'Monday-Friday 09:00:00-17:30:00 Europe/Berlin',
        ]);
        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'ARGT',
            'trading_times' => 'Monday-Friday 09:30:00-16:00:00 America/New_York',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/exchange-details/XETRA'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/exchange-details/US'));
    }

    public function test_failed_eodhd_repair_keeps_the_issue_and_reports_the_failure(): void
    {
        StockHolding::factory()->create([
            'symbol' => 'ARGT',
            'exchange' => 'US',
            'mic_code' => 'ARCX',
            'country' => 'United States',
            'trading_times' => null,
        ]);
        Http::fake([
            'eodhd.com/api/exchange-details/US*' => Http::response([
                'status' => 'error',
                'message' => 'Exchange details unavailable.',
            ], 200),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/data/health/stock-trading-times/repair')
            ->assertOk()
            ->assertJsonPath('health_check.status', 'issues')
            ->assertJsonPath('health_check.summary.issue_stocks_count', 1)
            ->assertJsonPath('health_check.repair.repaired_stocks_count', 0)
            ->assertJsonPath('health_check.repair.failed_stocks_count', 1)
            ->assertJsonPath('health_check.repair.failures.0.message', 'Exchange details unavailable.');
    }

    public function test_guest_cannot_check_or_repair_stock_trading_times(): void
    {
        $this->getJson('/admin/data/health/stock-trading-times')->assertUnauthorized();
        $this->postJson('/admin/data/health/stock-trading-times')->assertUnauthorized();
        $this->postJson('/admin/data/health/stock-trading-times/repair')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
