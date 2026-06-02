<?php

namespace Tests\Feature;

use App\Ai\Agents\StockIdentifierResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStockSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_stocks(): void
    {
        Cache::flush();
        $admin = $this->adminUser();
        StockIdentifierResolver::fake([
            [
                'candidates' => collect(range(1, 12))
                    ->map(fn (int $index): array => [
                        'symbol' => "AAPL{$index}",
                        'name' => "Apple {$index}",
                        'isin' => "US037833100{$index}",
                        'wkn' => null,
                        'exchange' => 'NASDAQ',
                        'mic_code' => 'XNAS',
                        'instrument_type' => 'Common Stock',
                        'country' => 'United States',
                        'currency' => 'USD',
                    ])
                    ->all(),
            ],
        ])->preventStrayPrompts();

        $this->actingAs($admin)
            ->getJson('/admin/stocks/search?query=Apple')
            ->assertOk()
            ->assertJsonCount(10, 'results')
            ->assertJsonPath('results.0.symbol', 'AAPL1')
            ->assertJsonPath('results.0.name', 'Apple 1')
            ->assertJsonPath('results.0.exchange', 'NASDAQ')
            ->assertJsonPath('results.0.currency', 'USD');

        StockIdentifierResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('Apple'));
    }

    public function test_admin_can_search_by_wkn_with_ai_resolved_identifiers(): void
    {
        Cache::flush();
        $admin = $this->adminUser();
        StockIdentifierResolver::fake([
            [
                'candidates' => [
                    [
                        'name' => 'iShares ATX UCITS ETF (DE)',
                        'isin' => 'DE000A0D8Q23',
                        'wkn' => 'A0D8Q2',
                        'symbol' => 'EXXX',
                        'exchange' => 'XETR',
                        'mic_code' => 'XETR',
                        'instrument_type' => 'ETF',
                        'country' => 'Germany',
                        'currency' => 'EUR',
                    ],
                    [
                        'name' => 'iShares ATX UCITS ETF (DE)',
                        'isin' => 'DE000A0D8Q23',
                        'wkn' => 'A0D8Q2',
                        'symbol' => 'EX01',
                        'exchange' => 'VSE',
                        'mic_code' => 'XWBO',
                        'instrument_type' => 'ETF',
                        'country' => 'Austria',
                        'currency' => 'EUR',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->actingAs($admin)
            ->getJson('/admin/stocks/search?query=A0D8Q2')
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.symbol', 'EXXX')
            ->assertJsonPath('results.0.name', 'iShares ATX UCITS ETF (DE)')
            ->assertJsonPath('results.0.isin', 'DE000A0D8Q23')
            ->assertJsonPath('results.0.wkn', 'A0D8Q2')
            ->assertJsonPath('results.0.exchange', 'XETR')
            ->assertJsonPath('results.0.mic_code', 'XETR')
            ->assertJsonPath('results.0.currency', 'EUR')
            ->assertJsonPath('results.1.symbol', 'EX01')
            ->assertJsonPath('results.1.isin', 'DE000A0D8Q23')
            ->assertJsonPath('results.1.wkn', 'A0D8Q2')
            ->assertJsonPath('results.1.exchange', 'VSE');

        StockIdentifierResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('A0D8Q2'));
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
