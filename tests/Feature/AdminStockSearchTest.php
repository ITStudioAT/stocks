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

    public function test_paid_stock_search_is_disabled(): void
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
            ->postJson('/admin/stocks/search', ['query' => 'Apple', 'source' => 'eodhd'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'EODHD search is disabled. Only free, saved or verified instruments are available.');

        Http::assertNothingSent();

        Http::assertNothingSent();
    }

    public function test_paid_isin_search_is_disabled(): void
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
            ->postJson('/admin/stocks/search', ['query' => 'DE000A0D8Q23', 'source' => 'eodhd'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'EODHD search is disabled. Only free, saved or verified instruments are available.');

        Http::assertNothingSent();
    }

    public function test_paid_index_search_is_disabled(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::fake([
            'eodhd.com/api/search/ATX.INDX*' => Http::response([]),
            'eodhd.com/api/exchange-symbol-list/INDX*' => Http::response([
                [
                    'Code' => 'ATX',
                    'Exchange' => 'INDX',
                    'Name' => 'Austrian Traded Index in EUR',
                    'Type' => 'INDEX',
                    'Country' => 'Austria',
                    'Currency' => 'EUR',
                    'Isin' => 'AT0000999982',
                ],
            ]),
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/stocks/search', ['query' => 'ATX.INDX', 'source' => 'eodhd'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'EODHD search is disabled. Only free, saved or verified instruments are available.');

        Http::assertNothingSent();
    }

    public function test_search_query_is_required(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/stocks/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_guest_cannot_search_stocks(): void
    {
        $this->postJson('/admin/stocks/search', ['query' => 'Apple'])->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
