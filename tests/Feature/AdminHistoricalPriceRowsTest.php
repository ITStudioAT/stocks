<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\User;
use App\Services\EodhdApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminHistoricalPriceRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_check_analysis_row_coverage_without_downloading_prices(): void
    {
        $admin = $this->adminUser();
        $firstHolding = $this->xetraHolding();
        $secondHolding = StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'name' => 'Microsoft',
        ]);
        $this->createDailyPrices($firstHolding, 3);
        $this->createDailyPrices($secondHolding, 2);
        Http::fake(fn () => throw new RuntimeException('Coverage must not call EODHD.'));

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings/historical-price-rows?row_count=3')
            ->assertOk()
            ->assertJsonPath('coverage.requested_row_count', 3)
            ->assertJsonPath('coverage.required_price_count', 4)
            ->assertJsonPath('coverage.holding_count', 2)
            ->assertJsonPath('coverage.missing_holding_count', 2)
            ->assertJsonPath('coverage.minimum_available_row_count', 1)
            ->assertJsonPath('coverage.is_complete', false)
            ->assertJsonPath('coverage.holdings.0.stored_price_count', 3)
            ->assertJsonPath('coverage.holdings.0.available_row_count', 2)
            ->assertJsonPath('coverage.holdings.0.missing_price_count', 1);

        Http::assertNothingSent();
    }

    public function test_analysis_row_requests_are_capped_at_one_thousand(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings/historical-price-rows?row_count=1001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('row_count');

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 1001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('row_count');
    }

    public function test_admin_can_confirm_a_bounded_older_history_download(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = $this->xetraHolding();
        $this->createDailyPrices($holding, 2, '2026-06-25');
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([
                $this->eodhdRecord('2026-06-19', 96),
                $this->eodhdRecord('2026-06-20', 97),
                $this->eodhdRecord('2026-06-22', 98),
                $this->eodhdRecord('2026-06-23', 99),
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 4])
            ->assertOk()
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('stored_count', 3)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('coverage.requested_row_count', 4)
            ->assertJsonPath('coverage.required_price_count', 5)
            ->assertJsonPath('coverage.is_complete', true)
            ->assertJsonPath('message', 'EODHD historical sync: 3 daily price row(s) stored.');

        $this->assertSame(5, StockHoldingDailyPrice::query()
            ->where('stock_holding_id', $holding->id)
            ->count());
        $this->assertDatabaseMissing('stock_holding_daily_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-19',
        ]);
        $this->assertDatabaseHas('stock_holding_daily_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-23',
            'close' => '99.00000000',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AMES.XETRA')
            && $request['to'] === '2026-06-23'
            && $request['period'] === 'd');
    }

    public function test_confirmed_download_skips_eodhd_when_enough_rows_are_stored(): void
    {
        $admin = $this->adminUser();
        $holding = $this->xetraHolding();
        $this->createDailyPrices($holding, 5);
        Http::fake(fn () => throw new RuntimeException('EODHD must not be called.'));

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 4])
            ->assertOk()
            ->assertJsonPath('requested_count', 0)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('coverage.is_complete', true);

        Http::assertNothingSent();
    }

    public function test_provider_failures_keep_row_coverage_incomplete(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->xetraHolding();
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([], 503),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 10])
            ->assertOk()
            ->assertJsonPath('requested_count', 0)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('coverage.is_complete', false)
            ->assertJsonPath('errors.0', 'EODHD historical request failed with HTTP 503 for AMES.');
    }

    public function test_invalid_api_token_stops_historical_download_after_the_first_stock(): void
    {
        config(['services.eodhd.key' => 'invalid-test-token']);
        $admin = $this->adminUser();
        $this->xetraHolding();
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'exchange' => 'US',
            'country' => 'United States',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/eod/*' => Http::response([], 401),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 10])
            ->assertOk()
            ->assertJsonPath('requested_count', 0)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('coverage.missing_holding_count', 2)
            ->assertJsonPath('errors.0', EodhdApiClient::AuthenticationErrorMessage);

        Http::assertSentCount(1);
    }

    public function test_guest_cannot_check_or_download_historical_price_rows(): void
    {
        $this->getJson('/admin/watchlist/holdings/historical-price-rows?row_count=10')
            ->assertUnauthorized();
        $this->postJson('/admin/watchlist/holdings/historical-price-rows', ['row_count' => 10])
            ->assertUnauthorized();
    }

    private function xetraHolding(): StockHolding
    {
        return StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
    }

    private function createDailyPrices(
        StockHolding $holding,
        int $count,
        string $latestDate = '2026-06-26',
    ): void {
        foreach (range(0, $count - 1) as $index) {
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => Carbon::parse($latestDate)->subDays($index)->toDateString(),
                'close' => number_format(100 - $index, 8, '.', ''),
                'adjusted_close' => number_format(100 - $index, 8, '.', ''),
                'currency' => $holding->currency,
            ]);
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function eodhdRecord(string $date, int $close): array
    {
        return [
            'date' => $date,
            'open' => $close - 1,
            'high' => $close + 1,
            'low' => $close - 2,
            'close' => $close,
            'adjusted_close' => $close,
            'volume' => 1000,
        ];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
