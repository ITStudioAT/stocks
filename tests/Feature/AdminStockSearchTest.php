<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStockSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_admin_can_search_stocks_with_eodhd(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::fake([
            'eodhd.com/api/search/Apple*' => Http::response(
                collect(range(1, 12))
                    ->map(fn (int $index): array => [
                        'Code' => "AAPL{$index}",
                        'Exchange' => $index === 1 ? 'NASDAQ' : 'US',
                        'Name' => "Apple {$index}",
                        'Type' => 'Common Stock',
                        'Country' => 'USA',
                        'Currency' => 'USD',
                        'ISIN' => "US037833100{$index}",
                    ])
                    ->all(),
            ),
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/stocks/search?query=Apple')
            ->assertOk()
            ->assertJsonCount(10, 'results')
            ->assertJsonPath('results.0.symbol', 'AAPL1')
            ->assertJsonPath('results.0.name', 'Apple 1')
            ->assertJsonPath('results.0.isin', 'US0378331001')
            ->assertJsonPath('results.0.wkn', null)
            ->assertJsonPath('results.0.exchange', 'NASDAQ')
            ->assertJsonPath('results.0.mic_code', 'XNAS')
            ->assertJsonPath('results.0.instrument_type', 'Common Stock')
            ->assertJsonPath('results.0.country', 'USA')
            ->assertJsonPath('results.0.currency', 'USD')
            ->assertJsonPath('eodhd_api_usage.hour.used', 1)
            ->assertJsonPath('eodhd_api_usage.hour.remaining', 999)
            ->assertJsonPath('eodhd_api_usage.day.used', 1)
            ->assertJsonPath('eodhd_api_usage.day.remaining', 99999);

        Http::assertSentCount(1);
    }

    public function test_admin_can_search_by_isin_with_eodhd(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::fake([
            'eodhd.com/api/search/DE000A0D8Q23*' => Http::response([
                [
                    'Code' => 'EXXX',
                    'Exchange' => 'XETRA',
                    'Name' => 'iShares ATX UCITS ETF (DE)',
                    'Type' => 'ETF',
                    'Country' => 'Germany',
                    'Currency' => 'EUR',
                    'ISIN' => 'DE000A0D8Q23',
                ],
            ]),
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/stocks/search?query=DE000A0D8Q23')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.symbol', 'EXXX')
            ->assertJsonPath('results.0.name', 'iShares ATX UCITS ETF (DE)')
            ->assertJsonPath('results.0.isin', 'DE000A0D8Q23')
            ->assertJsonPath('results.0.wkn', null)
            ->assertJsonPath('results.0.exchange', 'XETRA')
            ->assertJsonPath('results.0.mic_code', 'XETR')
            ->assertJsonPath('results.0.currency', 'EUR');
    }

    public function test_search_query_is_required(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/stocks/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_guest_cannot_search_stocks(): void
    {
        $this->getJson('/admin/stocks/search?query=Apple')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
