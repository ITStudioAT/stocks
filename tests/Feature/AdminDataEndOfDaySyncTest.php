<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataEndOfDaySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_missing_eodhd_end_of_day_prices(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-26 19:20:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'isin' => 'FR0010655746',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        $this->createHistoricalStockPricesExcept(
            $holding,
            Carbon::parse('2025-06-26', 'Europe/Vienna'),
            Carbon::parse('2026-06-26', 'Europe/Vienna'),
            '2026-06-26',
        );

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([
                [
                    'date' => '2026-06-26',
                    'open' => 100.25,
                    'high' => 102.00,
                    'low' => 99.75,
                    'close' => 101.50,
                    'adjusted_close' => 101.40,
                    'volume' => 12345,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/data/end-of-day/sync')
            ->assertOk()
            ->assertJsonPath('message', 'EODHD end-of-day sync: 1 record(s) created.')
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('stored_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('date_to', '2026-06-26')
            ->assertJsonPath('end_of_day_data_update_settings.last_dispatched_at', '2026-06-26T19:20:00+02:00')
            ->assertJsonPath('end_of_day_data_update_settings.next_refresh_at', '2026-06-29T18:30:00+02:00');

        $this->assertDatabaseHas('stock_prices', [
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'AMES',
            'price' => '101.50000000',
            'close' => '101.50000000',
            'price_type' => 'historical_eod',
            'as_of' => '2026-06-26 21:59:59',
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AMES.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26'
            && $request['period'] === 'd'
            && $request['fmt'] === 'json');
    }

    public function test_end_of_day_sync_only_requests_stocks_missing_the_expected_last_date(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-26 19:20:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $missingHoldings = collect(['AAA', 'BBB', 'CCC'])
            ->map(fn (string $symbol): StockHolding => StockHolding::factory()->create([
                'symbol' => $symbol,
                'name' => "{$symbol} Holding",
                'isin' => "FR0010655{$symbol}",
                'exchange' => 'XETRA',
                'mic_code' => 'XETR',
                'currency' => 'EUR',
            ]));
        $completeHolding = StockHolding::factory()->create([
            'symbol' => 'DONE',
            'name' => 'Complete Holding',
            'isin' => 'FR0010655000',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $missingHoldings->each(fn (StockHolding $holding): StockPrice => $this->createHistoricalStockPrice($holding, '2026-06-25', '100.00000000'));
        $this->createHistoricalStockPrice($completeHolding, '2026-06-24', '100.00000000');
        $this->createHistoricalStockPrice($completeHolding, '2026-06-26', '100.00000000');

        Http::fake(fn (Request $request) => Http::response([
            [
                'date' => $request['from'],
                'open' => 100.25,
                'high' => 102.00,
                'low' => 99.75,
                'close' => 101.50,
                'adjusted_close' => 101.40,
                'volume' => 12345,
            ],
        ]));

        $this->actingAs($admin)
            ->postJson('/admin/data/end-of-day/sync')
            ->assertOk()
            ->assertJsonPath('requested_count', 3)
            ->assertJsonPath('stored_count', 3)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('date_from', '2026-06-26')
            ->assertJsonPath('date_to', '2026-06-26');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AAA.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/BBB.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/CCC.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/eod/DONE.XETRA'));
    }

    public function test_end_of_day_sync_continues_at_the_stored_interval_when_the_expected_last_date_is_still_missing(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-26 19:20:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'isin' => 'FR0010655746',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        AppConfig::query()->create([
            'key' => 'end_of_day_data_update.schedule',
            'value' => [
                'daily_time' => '17:45',
                'interval_minutes' => 20,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => '2026-06-26T17:45:00+02:00',
            ],
        ]);
        $this->createHistoricalStockPricesExcept(
            $holding,
            Carbon::parse('2025-06-26', 'Europe/Vienna'),
            Carbon::parse('2026-06-26', 'Europe/Vienna'),
            '2026-06-26',
        );

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/data/end-of-day/sync')
            ->assertOk()
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('date_to', '2026-06-26')
            ->assertJsonPath('end_of_day_data_update_settings.last_dispatched_at', '2026-06-26T19:20:00+02:00')
            ->assertJsonPath('end_of_day_data_update_settings.next_refresh_at', '2026-06-26T19:40:00+02:00');

        Http::assertSentCount(1);
    }

    public function test_due_end_of_day_update_command_retries_after_interval_when_expected_last_date_is_missing(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-26 18:31:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'isin' => 'FR0010655746',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        AppConfig::query()->create([
            'key' => 'end_of_day_data_update.schedule',
            'value' => [
                'daily_time' => '18:30',
                'interval_minutes' => 15,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => '2026-06-26T18:30:00+02:00',
            ],
        ]);
        $this->createHistoricalStockPricesExcept(
            $holding,
            Carbon::parse('2025-06-26', 'Europe/Vienna'),
            Carbon::parse('2026-06-26', 'Europe/Vienna'),
            '2026-06-26',
        );

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([]),
        ]);

        $this->artisan('end-of-day-data:dispatch-due')
            ->assertExitCode(0);

        Http::assertSentCount(1);

        $config = AppConfig::query()->where('key', 'end_of_day_data_update.schedule')->firstOrFail();

        $this->assertSame('2026-06-26', $config->value['last_dispatched_on']);
        $this->assertSame('2026-06-26T18:46:00+02:00', $config->value['next_refresh_at']);
    }

    public function test_guest_cannot_sync_eodhd_end_of_day_prices(): void
    {
        $this->postJson('/admin/data/end-of-day/sync')->assertUnauthorized();
    }

    private function createHistoricalStockPrice(StockHolding $holding, string $date, string $price): StockPrice
    {
        return StockPrice::factory()->create([
            'instrument_key' => 'isin:'.$holding->isin,
            'quote_hash' => hash('sha256', "{$holding->isin}|{$date}|{$price}"),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'close' => $price,
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
    }

    private function createHistoricalStockPricesExcept(
        StockHolding $holding,
        Carbon $dateFrom,
        Carbon $dateTo,
        string $exceptDate,
    ): void {
        $date = $dateFrom->copy()->startOfDay();

        while ($date->lte($dateTo)) {
            if ($date->isWeekday() && $date->toDateString() !== $exceptDate) {
                $this->createHistoricalStockPrice($holding, $date->toDateString(), '100.00000000');
            }

            $date->addDay();
        }
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
